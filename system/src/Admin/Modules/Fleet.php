<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Fleet\AlertRules;
use Kaleta\Fleet\Console;
use Kaleta\Fleet\Digest;
use Kaleta\Fleet\History;
use Kaleta\Fleet\Kit;
use Kaleta\Fleet\Report;

/**
 * The fleet console (2.9, extension "fleet", Fleet\Console): every paired site on one screen, the ones that need attention
 * first; a site's last report; pairing keys; the update ring of each site and allowing a new version by hand. Since 2.16
 * also the shared design kit (Fleet\Kit) the sites receive as drafts. Since 3.17 the console keeps history (Fleet\History:
 * trends on a site's page), sends alerts by rules and a weekly digest (Fleet\AlertRules, Fleet\Digest; Alerts and digest)
 * and makes a monthly report per customer (Fleet\Report; Reports).
 */
final class Fleet extends Module
{
    public const string IDENT = 'fleet';
    public const string NAME = 'Fleet';
    public const string GROUP = 'Administration';
    public const string ICON = 'web';
    public const string EXTENSION = 'fleet';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $sites = Console::overview($this->db, time());
        $show = $this->request->get('show') === 'all' ? 'all' : 'attention';
        $query = mb_strtolower(trim($this->request->get('q')));
        $needing = count(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings']));
        $listed = array_values(array_filter($sites, fn (array $s): bool => ($show === 'all' || $s['score'] >= Console::REASONS['warnings'] || $query !== '')
            && ($query === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['url']), $query))));
        $key = $this->app->session->get('fleet_pairing_key');
        $this->app->session->set('fleet_pairing_key', null); // shown once

        return $this->view('list', 'Fleet', ['sites' => $listed, 'total' => count($sites), 'needing' => $needing, 'show' => $show, 'query' => $query,
            'latest' => Console::latest($this->app)[0], 'pairingKey' => is_string($key) ? $key : '']);
    }

    protected function actionDetail(): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->back('The site is not in the console.', '', [], 'chyba');
        }
        $events = $this->db->all("SELECT created_at, type, severity, message FROM {events} WHERE type LIKE 'fleet.%' AND JSON_EXTRACT(data, '$.site') = ? ORDER BY id DESC LIMIT 20", [(int) $site['id']]);

        return $this->view('detail', (string) $site['name'], ['site' => $site, 'events' => $events, 'latest' => Console::latest($this->app)[0],
            'trend' => History::trend($this->db, (int) $site['id'], time())]);
    }

    protected function actionPairingKey(): Response
    {
        if ($this->request->isPost()) {
            if ($this->app->settings()->get('site_url') === '') {
                return $this->back('Fill in the site address in Settings → General first.', '', [], 'chyba');
            }
            $this->app->session->set('fleet_pairing_key', Console::newPairingKey($this->app));
            ChangeLog::write($this->app, 'fleet', 'pairing_key');
        }

        return $this->back();
    }

    protected function actionRing(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            $ring = $this->request->post('ring') === 'canary' ? 'canary' : 'normal';
            $this->db->update('fleet_sites', ['ring' => $ring], ['id' => (int) $site['id']]);
            ChangeLog::write($this->app, 'fleet', 'ring', $site['url'] . ': ' . $ring);
        }

        return $this->back('Saved.', $site !== null ? 'detail' : '', $site !== null ? ['id' => (int) $site['id']] : []);
    }

    /** Allows the newest version now – one site (id) or every site that lets the console decide. */
    protected function actionAllow(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        $count = Console::allowNow($this->app, $id > 0 ? $id : null);
        ChangeLog::write($this->app, 'fleet', 'allow_update', ($id > 0 ? '#' . $id : 'all') . ': ' . $count);

        return $this->back($count > 0 ? t('The new version is allowed on %d sites; each installs it with its next report (within an hour).', $count) : 'No site waits for a new version it may install.',
            $id > 0 ? 'detail' : '', $id > 0 ? ['id' => $id] : []);
    }

    protected function actionCheck(): Response
    {
        if ($this->request->isPost()) {
            $id = $this->request->postInt('id');
            Console::checkUptime($this->app, $id > 0 ? $id : null);
        }

        return $this->back('Checked.', $this->request->postInt('id') > 0 ? 'detail' : '', $this->request->postInt('id') > 0 ? ['id' => $this->request->postInt('id')] : []);
    }

    protected function actionRemove(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            Console::remove($this->app, (int) $site['id']);
            ChangeLog::write($this->app, 'fleet', 'remove', (string) $site['url']);
        }

        return $this->back('The site was removed from the console. It stops reporting with its next report; pair it again to bring it back.');
    }

    /** The shared design kit (2.16, Fleet\Kit): what the console offers the sites, and the versions published so far. */
    protected function actionKit(): Response
    {
        $s = $this->app->settings();

        return $this->view('kit', 'Shared kit', [
            'designSystem' => \Kaleta\Builder\DesignSystem::load($s), 'classes' => \Kaleta\Core\Look::classes($this->db, $s, false),
            'components' => \Kaleta\Builder\Components::all($this->db), 'sections' => $this->db->all('SELECT idx, nazev FROM {sekce} ORDER BY ' . $this->db->sortText('nazev')),
            'kits' => Kit::history($this->db), 'sites' => Console::sites($this->db), 'applied' => Kit::appliedVersions($this->db),
        ]);
    }

    /** Publishes a new kit version from the chosen parts; the sites that opted in pick it up with their next report, as drafts. */
    protected function actionKitPublish(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'kit');
        }
        $ids = fn (string $key): array => array_values(array_filter(array_map('intval', is_array($_POST[$key] ?? null) ? $_POST[$key] : []), fn (int $id): bool => $id > 0));
        $classes = $this->request->postBool('classes_all') ? null : array_values(array_filter(is_array($_POST['classes'] ?? null) ? $_POST['classes'] : [], 'is_string'));
        try {
            $kit = Kit::publish($this->app, ['design_system' => $this->request->postBool('design_system'), 'classes' => $classes, 'components' => $ids('components'), 'sections' => $ids('sections')]);
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), 'kit', [], 'chyba');
        }
        ChangeLog::write($this->app, 'fleet', 'kit_publish', 'version ' . $kit['version'] . ': ' . $kit['summary']);

        return $this->back(t('Kit version %d is published (%s). Every site that receives the kit picks it up with its next report, within an hour – as drafts for its people to publish.', $kit['version'], $kit['summary']), 'kit');
    }

    /** Alerts and digest (3.17): the alert rules, the recipients, the quiet hours and the weekly digest. */
    protected function actionAlerts(): Response
    {
        $s = $this->app->settings();

        return $this->view('alerts', 'Alerts and digest', ['rules' => AlertRules::rules($s), 'recipients' => $s->get('fleet_alert_recipients'),
            'fallback' => AlertRules::recipients($s), 'quietHours' => AlertRules::quietHours($s), 'digest' => $s->bool('fleet_digest'), 'digestTitles' => $s->bool('fleet_digest_titles'),
            'alertsEnabled' => $s->bool('alerts_enabled')]);
    }

    protected function actionAlertsSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'alerts');
        }
        $s = $this->app->settings();
        $recipients = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', $this->request->post('fleet_alert_recipients')) ?: [], fn (string $e): bool => $e !== '')));
        $invalid = array_filter($recipients, fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) === false);
        if ($invalid !== [] || count($recipients) > 10) {
            return $this->back($invalid !== [] ? t('These addresses are not valid: %s. Nothing was saved.', implode(', ', $invalid)) : 'At most 10 recipients. Nothing was saved.', 'alerts', [], 'chyba');
        }
        $input = [];
        foreach (array_keys(AlertRules::KINDS) as $kind) {
            $input[$kind] = ['on' => $this->request->postBool('on_' . $kind), 'threshold' => $this->request->post('threshold_' . $kind)];
        }
        $s->set('fleet_alert_rules', (string) json_encode(AlertRules::sanitize($input)));
        $s->set('fleet_alert_recipients', implode(', ', $recipients));
        $s->set('fleet_alert_quiet_hours', (string) max(1, min(AlertRules::QUIET_MAX, $this->request->postInt('fleet_alert_quiet_hours') ?: AlertRules::QUIET_DEFAULT)));
        $s->set('fleet_digest', $this->request->postBool('fleet_digest') ? '1' : '0');
        $s->set('fleet_digest_titles', $this->request->postBool('fleet_digest_titles') ? '1' : '0');
        ChangeLog::write($this->app, 'fleet', 'alerts');

        return $this->back('Saved.', 'alerts');
    }

    /** The weekly digest as it would go out now (the last 7 days), with the saved settings. */
    protected function actionDigestPreview(): Response
    {
        $s = $this->app->settings();
        $end = new \DateTimeImmutable();
        [$subject, $text] = Digest::compose(Digest::build($this->app, $end->modify('-7 days'), $end), $s, AlertRules::consoleUrl($this->app), $s->bool('fleet_digest_titles'));

        return $this->view('digest', 'Weekly digest', ['subject' => $subject, 'text' => $text, 'recipients' => AlertRules::recipients($s), 'on' => $s->bool('fleet_digest')]);
    }

    /** Reports (3.17): the monthly reports per customer the console has made (Fleet\Report). */
    protected function actionReports(): Response
    {
        return $this->view('reports', 'Fleet reports', ['reports' => Report::all($this->db),
            'previous' => \Kaleta\Core\MonthlyReport::previousMonth(new \DateTimeImmutable())->format('Y-m')]);
    }

    /** Makes the previous month's reports now, or rebuilds a stored month from the history still kept (the administrator's choice). */
    protected function actionReportBuild(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'reports');
        }
        $month = $this->request->post('month');
        $rebuild = $month !== '';
        if ($rebuild && preg_match(\Kaleta\Core\ReportArchive::MONTH_PATTERN, $month) !== 1) {
            return $this->back('This month is not in the archive.', 'reports', [], 'chyba');
        }
        $start = $rebuild ? new \DateTimeImmutable($month . '-01') : \Kaleta\Core\MonthlyReport::previousMonth(new \DateTimeImmutable());
        $count = Report::store($this->app, $start, $rebuild);
        ChangeLog::write($this->app, 'fleet', $rebuild ? 'report_rebuild' : 'report_build', $start->format('Y-m') . ': ' . $count);

        return $this->back($count > 0 ? t('The reports for %s are ready (%d).', \Kaleta\Core\ReportPage::monthName($start->format('Y-m')), $count)
            : t('The reports for %s were made already – rebuild one from the list.', \Kaleta\Core\ReportPage::monthName($start->format('Y-m'))), 'reports');
    }

    /** One customer's month as the printable A4 page – GET that only renders, with its Print button. */
    protected function actionReportView(): Response
    {
        $stored = Report::get($this->db, $this->request->get('month'), $this->request->get('customer'));
        if ($stored === null) {
            return $this->error('This month is not in the archive.', 404);
        }
        $params = ['month' => $stored['month'], 'customer' => $stored['customer']];
        $html = Report::render($this->app->view, $stored, $this->app->settings(), $this->consoleAddress(), [
            'script' => $this->app->url('image/tisk.js') . '?v=' . KALETA_VERSION, 'download' => $this->url('report_download', $params), 'back' => $this->url('reports')]);

        // its own inline styles and the Print button script, nothing else may run – like the site's monthly report
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; script-src 'self'; frame-ancestors 'self'; base-uri 'none'"]);
    }

    /** The same page as an HTML file to keep or send: no toolbar, no script. */
    protected function actionReportDownload(): Response
    {
        $stored = Report::get($this->db, $this->request->get('month'), $this->request->get('customer'));
        if ($stored === null) {
            return $this->error('This month is not in the archive.', 404);
        }
        $name = $stored['customer'] !== '' ? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_diacritics($stored['customer']))), '-') : 'no-customer';

        return new Response(Report::render($this->app->view, $stored, $this->app->settings(), $this->consoleAddress()), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="fleet-report-' . ($name !== '' ? $name : 'customer') . '-' . $stored['month'] . '.html"', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; frame-ancestors 'none'; base-uri 'none'"]);
    }

    /** The console's address for the report's logo and links: the setting, not the Host header. */
    private function consoleAddress(): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . rtrim($this->app->url(''), '/');
    }

    /** @return array<string, mixed>|null the site from ?id= or the posted id, with its attention and decoded report */
    private function site(): ?array
    {
        $id = $this->request->isPost() ? $this->request->postInt('id') : $this->request->getInt('id');
        $row = $this->db->one('SELECT * FROM {fleet_sites} WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $beat = json_decode((string) ($row['heartbeat'] ?? ''), true);

        return $row + Console::attention($row, time()) + ['beat' => is_array($beat) ? $beat : []];
    }
}
