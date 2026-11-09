<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Fleet\AlertRules;
use Kaleta\Fleet\Calendar;
use Kaleta\Fleet\Checks;
use Kaleta\Fleet\Console;
use Kaleta\Fleet\ConsoleTasks;
use Kaleta\Fleet\Customers;
use Kaleta\Fleet\Inbox;
use Kaleta\Fleet\Digest;
use Kaleta\Fleet\Health;
use Kaleta\Fleet\History;
use Kaleta\Fleet\Inventory;
use Kaleta\Fleet\Kit;
use Kaleta\Fleet\Plan;
use Kaleta\Fleet\Report;
use Kaleta\Fleet\Rollout;

/**
 * The fleet console (2.9, extension "fleet", Fleet\Console): every paired site on one screen, the ones that need attention
 * first; a site's last report; pairing keys; the update ring of each site and allowing a new version by hand. Since 2.16
 * also the shared design kit (Fleet\Kit) the sites receive as drafts. Since 3.17 the console opens on the inbox – what waits
 * on every site (Fleet\Inbox) – sites belong to customers (Fleet\Customers), the console keeps history (Fleet\History: trends
 * on a site's page), sends alerts by rules and a weekly digest (Fleet\AlertRules, Fleet\Digest; Alerts and digest) and makes a
 * monthly report per customer (Fleet\Report; Reports). Since 3.29 it opens on Today (what needs attention, Fleet\Checks), with
 * the health matrix and the workload of the sites next to the inbox. Since 3.30 the inventory (Fleet\Inventory: versions,
 * PHP, database, server, features, add-ons and languages of every site, filtered and exported), the expiry calendar
 * (Fleet\Calendar: certificate and domain dates, recovery keys, reminders, a calendar link) and the rollout (Fleet\Rollout:
 * rings, their delays, maintenance windows per customer and site, where the newest release has reached); the console also
 * keeps tasks per site or customer (Fleet\ConsoleTasks over Core\Tasks: a task panel on the site and customer screens,
 * Admin\TaskActions) and shows the address of each site's own Claude connector.
 */
final class Fleet extends Module
{
    use \Kaleta\Admin\TaskActions;

    public const string IDENT = 'fleet';
    public const string NAME = 'Fleet';
    public const string GROUP = 'Administration';
    public const string ICON = 'web';
    public const string EXTENSION = 'fleet';
    public const bool ADMIN_ONLY = true;

    /**
     * Today (3.29, the console opens on it – Fleet\Checks::today through Fleet\Health): what needs attention across the sites,
     * red before amber, then the check's weight and the oldest first; filtered by customer and severity, with a summary of
     * the fleet. An older link with the inbox's own filters (kind, site, urgent) still opens the inbox.
     */
    protected function actionList(): Response
    {
        if ($this->request->get('kind') !== '' || $this->request->getInt('site') > 0 || $this->request->get('urgent') !== '') {
            return $this->actionInbox();
        }
        $now = time();
        $context = Health::context($this->app);
        $sites = Console::sites($this->db);
        $items = Checks::today($sites, $now, $context);
        $customers = Customers::names($this->db);
        $customer = $this->request->getInt('customer');
        $customer = $customer === -1 || isset($customers[$customer]) ? $customer : 0;
        $severity = in_array($this->request->get('severity'), Health::SEVERITIES, true) ? $this->request->get('severity') : '';
        // 3.30: the overdue console tasks ranked among the checks (kind "task"); the summary counts the checks only
        $tasks = ConsoleTasks::todayItems(ConsoleTasks::open($this->db), $sites, $customers);
        $all = ConsoleTasks::rank([...$items, ...$tasks]);

        return $this->view('today', 'Fleet', ['items' => Health::filter($all, $customer, $severity), 'total' => count($all), 'summary' => Health::summary($sites, $items, $now, $context),
            'customer' => $customer, 'severity' => $severity, 'customers' => $customers, 'now' => $now, 'pairingKey' => $this->pairingKey(), 'overdueTasks' => count($tasks),
            'names' => \Kaleta\Core\Assignment::labels($this->db, array_column($tasks, 'assignee_id')), 'me' => $this->app->auth()->id()]);
    }

    /** The health matrix (3.29): a row per site, a column per check (Fleet\Checks::CHECKS), each cell linking to its fix. */
    protected function actionHealth(): Response
    {
        $customers = Customers::names($this->db);
        $customer = $this->request->getInt('customer');
        $customer = $customer === -1 || isset($customers[$customer]) ? $customer : 0;
        $sort = in_array($this->request->get('sort'), Health::MATRIX_SORTS, true) ? $this->request->get('sort') : 'worst';
        $sites = array_values(array_filter(Console::sites($this->db), fn (array $s): bool => $customer === 0 || ($customer < 0 ? Console::customerIdOf($s) === 0 : Console::customerIdOf($s) === $customer)));

        return $this->view('health', 'Health', ['rows' => Health::matrix($sites, time(), Health::context($this->app), $sort, $customers), 'customers' => $customers,
            'customer' => $customer, 'sort' => $sort, 'siteCount' => (int) $this->db->value('SELECT COUNT(*) FROM {fleet_sites}')]);
    }

    /** The workload (3.29, Fleet\Checks::workload): per site and in total the enquiries, replies, tasks and new customers – counts only. */
    protected function actionWorkload(): Response
    {
        $customers = Customers::names($this->db);
        $customer = $this->request->getInt('customer');
        $customer = $customer === -1 || isset($customers[$customer]) ? $customer : 0;
        $sites = array_values(array_filter(Console::sites($this->db), fn (array $s): bool => $customer === 0 || ($customer < 0 ? Console::customerIdOf($s) === 0 : Console::customerIdOf($s) === $customer)));
        $sort = in_array($this->request->get('sort'), Checks::WORKLOAD, true) ? $this->request->get('sort') : 'site';
        $dir = $this->request->get('dir') === 'asc' || ($sort === 'site' && $this->request->get('dir') !== 'desc') ? 'asc' : 'desc';
        $workload = Checks::workload($sites);

        return $this->view('workload', 'Workload', ['rows' => Health::sortWorkload($workload['sites'], $sort, $dir), 'totals' => $workload['totals'], 'reporting' => $workload['reporting'],
            'customers' => $customers, 'customer' => $customer, 'sort' => $sort, 'dir' => $dir, 'siteCount' => (int) $this->db->value('SELECT COUNT(*) FROM {fleet_sites}')]);
    }

    /**
     * The inbox (3.17, Fleet\Inbox; its own tab since 3.29): everything that waits on the sites, the most urgent and the
     * oldest first – filtered by kind, site, customer and "only urgent"; the filter bar counts each kind under the other filters.
     */
    protected function actionInbox(): Response
    {
        $all = Inbox::load($this->db, $this->app->settings(), time());
        $customer = $this->request->getInt('customer');
        $filters = ['site' => $this->request->getInt('site'), 'customer' => $customer, 'urgent' => $this->request->get('urgent') === '1'];
        $kind = isset(Inbox::KINDS[$this->request->get('kind')]) ? $this->request->get('kind') : '';
        $narrowed = Inbox::filter($all, $filters);
        $sites = Console::sites($this->db);

        return $this->view('inbox', 'Fleet', ['rows' => Inbox::filter($narrowed, ['kind' => $kind]), 'counts' => Inbox::counts($narrowed), 'narrowed' => count($narrowed),
            'total' => count($all), 'urgent' => count(array_filter($all, fn (array $r): bool => $r['urgency'] === 'urgent')), 'kind' => $kind, 'filters' => $filters,
            'sites' => array_map(fn (array $s): string => (string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url'], array_column($sites, null, 'id')),
            'customers' => Customers::names($this->db), 'siteCount' => count($sites), 'pairingKey' => $this->pairingKey()]);
    }

    /** All sites with what needs attention (2.9), since 3.17 with their customer and how much waits on each. */
    protected function actionSites(): Response
    {
        $sites = Console::overview($this->db, time());
        $show = $this->request->get('show') === 'all' ? 'all' : 'attention';
        $query = mb_strtolower(trim($this->request->get('q')));
        $customers = Customers::names($this->db);
        $customer = isset($customers[$this->request->getInt('customer')]) ? $this->request->getInt('customer') : 0;
        $needing = count(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings']));
        $listed = array_values(array_filter($sites, fn (array $s): bool => ($show === 'all' || $s['score'] >= Console::REASONS['warnings'] || $query !== '' || $customer > 0)
            && ($query === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['url'] . ' ' . ($customers[(int) $s['customer_id']] ?? '')), $query))
            && ($customer === 0 || (int) $s['customer_id'] === $customer)));

        return $this->view('list', 'Fleet', ['sites' => $listed, 'total' => count($sites), 'needing' => $needing, 'show' => $show, 'query' => $query,
            'customers' => $customers, 'customer' => $customer, 'waiting' => Inbox::waitingBySite(Inbox::load($this->db, $this->app->settings(), time())),
            'latest' => Console::latest($this->app)[0], 'pairingKey' => $this->pairingKey(), 'taskCounts' => ConsoleTasks::openCounts($this->db)['sites']]);
    }

    /** The customers (3.17): how many sites each has and how much waits, renaming, the new version for one customer's sites. */
    protected function actionCustomers(): Response
    {
        $waiting = Inbox::waitingBySite(Inbox::load($this->db, $this->app->settings(), time()));
        $bySite = array_column(Console::sites($this->db), 'customer_id', 'id');
        $perCustomer = [];
        foreach ($waiting as $siteId => $n) {
            $id = (int) ($bySite[$siteId] ?? 0);
            $perCustomer[$id] = ($perCustomer[$id] ?? 0) + $n;
        }

        return $this->view('customers', 'Customers', ['customers' => Customers::all($this->db), 'waiting' => $perCustomer,
            'without' => count(array_filter($bySite, fn (mixed $c): bool => $c === null)), 'latest' => Console::latest($this->app)[0], 'reports' => Report::latestByCustomer($this->db),
            'taskCounts' => ConsoleTasks::openCounts($this->db)['customers']]);
    }

    /**
     * One customer (3.30): its sites with what needs attention and how much waits, and the console tasks about the customer
     * (Admin\TaskActions – "check prices at Fysiomed before January"). The tasks of each site are on the site's screen.
     */
    protected function actionCustomerDetail(): Response
    {
        $id = $this->request->getInt('id');
        $name = Customers::nameOf($this->db, $id);
        if ($name === '') {
            return $this->back('The customer is not in the console.', 'customers', [], 'chyba');
        }
        $now = time();
        $sites = array_values(array_filter(Console::overview($this->db, $now), fn (array $s): bool => Console::customerIdOf($s) === $id));

        return $this->view('customer_detail', $name, ['customer' => ['id' => $id, 'name' => $name], 'sites' => $sites,
            'waiting' => Inbox::waitingBySite(Inbox::load($this->db, $this->app->settings(), $now)), 'taskCounts' => ConsoleTasks::openCounts($this->db)['sites'],
            'tasks' => $this->taskPanel(ConsoleTasks::customer($id), ['customer' => $id])]);
    }

    /** Sets the customer and the note of one site (the site detail). */
    protected function actionCustomer(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back('', 'sites');
        }
        Customers::assign($this->db, [(int) $site['id']], $this->request->post('customer'));
        Customers::setNote($this->db, (int) $site['id'], $this->request->post('note'));
        ChangeLog::write($this->app, 'fleet', 'customer', $site['url'] . ': ' . Customers::clean($this->request->post('customer')));

        return $this->back('Saved.', 'detail', ['id' => (int) $site['id']]);
    }

    /** Gives the ticked sites of the list one customer ('' = none). */
    protected function actionCustomerBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'sites');
        }
        $ids = array_map('intval', is_array($_POST['sites'] ?? null) ? $_POST['sites'] : []);
        if ($ids === []) {
            return $this->back('Tick the sites first.', 'sites', ['show' => 'all'], 'chyba');
        }
        $name = Customers::clean($this->request->post('customer'));
        $count = Customers::assign($this->db, $ids, $name);
        ChangeLog::write($this->app, 'fleet', 'customer_bulk', $name . ': ' . $count);

        return $this->back($name === '' ? t('%d sites have no customer now.', $count) : t('%d sites belong to %s now.', $count, $name), 'sites',
            ($id = Customers::find($this->db, $name)) !== null ? ['customer' => $id] : ['show' => 'all']);
    }

    /** Renames a customer for all its sites (a name another customer has merges the two). */
    protected function actionCustomerRename(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'customers');
        }
        $id = $this->request->postInt('id');
        $old = (string) ($this->db->value('SELECT name FROM {fleet_customers} WHERE id = ?', [$id]) ?? '');
        if (!Customers::rename($this->db, $id, $this->request->post('name'))) {
            return $this->back('Fill in the new name.', 'customers', [], 'chyba');
        }
        ChangeLog::write($this->app, 'fleet', 'customer_rename', $old . ' → ' . Customers::clean($this->request->post('name')));

        return $this->back('Saved.', 'customers');
    }

    protected function actionDetail(): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->back('The site is not in the console.', '', [], 'chyba');
        }
        $events = $this->db->all("SELECT created_at, type, severity, message FROM {events} WHERE type LIKE 'fleet.%' AND JSON_EXTRACT(data, '$.site') = ? ORDER BY id DESC LIMIT 20", [(int) $site['id']]);

        return $this->view('detail', (string) $site['name'], ['site' => $site, 'events' => $events, 'latest' => Console::latest($this->app)[0], 'customers' => Customers::names($this->db),
            'trend' => History::trend($this->db, (int) $site['id'], time()),
            // 3.30: the ring's delay and the customer's maintenance window (Fleet\Rollout); the site's own Claude connector and the console tasks about the site
            'delays' => Rollout::delays($this->app->settings()), 'customerWindow' => Rollout::customerWindows($this->db)[Console::customerIdOf($site)] ?? '',
            'connector' => Plan::connector($site['url']), 'tasks' => $this->taskPanel(ConsoleTasks::site((int) $site['id']), ['site' => (int) $site['id']])]);
    }

    /** The pairing key made just now, shown once (the inbox or the list of sites, wherever the owner lands). */
    private function pairingKey(): string
    {
        $key = $this->app->session->get('fleet_pairing_key');
        $this->app->session->set('fleet_pairing_key', null);

        return is_string($key) ? $key : '';
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
            // 3.30: pilot | normal | careful ("canary", the test site of before, is pilot) and the site's own maintenance window
            $ring = Rollout::ring($this->request->post('ring'));
            $update = ['ring' => $ring];
            $mode = $this->request->post('window_mode');
            if (in_array($mode, ['customer', 'any', 'own'], true)) {
                $own = Rollout::windowFromInput(is_array($_POST['window_days'] ?? null) ? $_POST['window_days'] : [], $this->request->post('window_from'), $this->request->post('window_to'));
                $update['update_window'] = match ($mode) {
                    'customer' => null,
                    'any' => '',
                    default => $own,
                };
                if (!Rollout::longEnough($own) && $mode === 'own') {
                    return $this->back(t('A maintenance window must be at least %d hours long – the sites ask the console once an hour. Nothing was saved.', intdiv(Rollout::MIN_WINDOW_MINUTES, 60)),
                        'detail', ['id' => (int) $site['id']], 'chyba');
                }
            }
            $this->db->update('fleet_sites', $update, ['id' => (int) $site['id']]);
            ChangeLog::write($this->app, 'fleet', 'ring', $site['url'] . ': ' . $ring . (array_key_exists('update_window', $update) ? ', ' . Rollout::describe($update['update_window'] ?? '') : ''));
        }

        return $this->back('Saved.', $site !== null ? 'detail' : '', $site !== null ? ['id' => (int) $site['id']] : []);
    }

    /** Allows the newest version now – one site (id), one customer's sites (customer, 3.17) or every site that lets the console decide. */
    protected function actionAllow(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'sites');
        }
        $id = $this->request->postInt('id');
        $customer = $id > 0 ? 0 : $this->request->postInt('customer');
        $count = $customer > 0 ? Console::allowNowForCustomer($this->app, $customer) : Console::allowNow($this->app, $id > 0 ? $id : null);
        ChangeLog::write($this->app, 'fleet', 'allow_update', ($id > 0 ? '#' . $id : ($customer > 0 ? 'customer #' . $customer : 'all')) . ': ' . $count);

        return $this->back($count > 0 ? t('The new version is allowed on %d sites; each installs it with its next report (within an hour).', $count) : 'No site waits for a new version it may install.',
            $id > 0 ? 'detail' : ($customer > 0 ? 'customers' : 'sites'), $id > 0 ? ['id' => $id] : []);
    }

    protected function actionCheck(): Response
    {
        if ($this->request->isPost()) {
            $id = $this->request->postInt('id');
            Console::checkUptime($this->app, $id > 0 ? $id : null);
        }

        return $this->back('Checked.', $this->request->postInt('id') > 0 ? 'detail' : 'sites', $this->request->postInt('id') > 0 ? ['id' => $this->request->postInt('id')] : []);
    }

    protected function actionRemove(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            Console::remove($this->app, (int) $site['id']);
            ChangeLog::write($this->app, 'fleet', 'remove', (string) $site['url']);
        }

        return $this->back('The site was removed from the console. It stops reporting with its next report; pair it again to bring it back.', 'sites');
    }

    /** The shared design kit (2.16, Fleet\Kit): what the console offers the sites, and the versions published so far. */
    protected function actionKit(): Response
    {
        $s = $this->app->settings();

        return $this->view('kit', 'Shared kit', [
            'designSystem' => \Kaleta\Builder\DesignSystem::load($s), 'classes' => \Kaleta\Core\Look::classes($this->db, $s, false),
            'components' => \Kaleta\Builder\Components::all($this->db), 'sections' => $this->db->all('SELECT idx, nazev FROM {sekce} ORDER BY ' . $this->db->sortText('nazev')),
            'kits' => Kit::history($this->db), 'sites' => Console::sites($this->db), 'applied' => Kit::appliedVersions($this->db), 'customers' => Customers::names($this->db),
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
        $customers = Customers::names($this->db);
        $customer = isset($customers[$this->request->postInt('customer')]) ? $this->request->postInt('customer') : null; // 3.17: one customer's sites only
        try {
            $kit = Kit::publish($this->app, ['design_system' => $this->request->postBool('design_system'), 'classes' => $classes, 'components' => $ids('components'), 'sections' => $ids('sections'),
                'customer' => $customer]);
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), 'kit', [], 'chyba');
        }
        ChangeLog::write($this->app, 'fleet', 'kit_publish', 'version ' . $kit['version'] . ': ' . $kit['summary'] . ($customer !== null ? ' (' . $customers[$customer] . ')' : ''));

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
        return $this->view('reports', 'Fleet reports', ['reports' => Report::all($this->db), 'customers' => Customers::names($this->db),
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
        // only a month that has ended and that the console keeps history of (N317-6) – never a future or an empty one
        if (!Report::buildable($this->db, $start->format('Y-m'), new \DateTimeImmutable())) {
            return $this->back(t('The console can make a report only for a month that has ended and that it keeps the history of (%s).', \Kaleta\Core\ReportPage::monthName($start->format('Y-m'))), 'reports', [], 'chyba');
        }
        $count = Report::store($this->app, $start, $rebuild);
        ChangeLog::write($this->app, 'fleet', $rebuild ? 'report_rebuild' : 'report_build', $start->format('Y-m') . ': ' . $count);

        return $this->back($count > 0 ? t('The reports for %s are ready (%d).', \Kaleta\Core\ReportPage::monthName($start->format('Y-m')), $count)
            : t('The reports for %s were made already – rebuild one from the list.', \Kaleta\Core\ReportPage::monthName($start->format('Y-m'))), 'reports');
    }

    /** One customer's month (?customer=<id>, the inbox's customer filter; 0 or empty = without one) as the printable A4 page – GET that only renders, with its Print button. */
    protected function actionReportView(): Response
    {
        $stored = Report::get($this->db, $this->request->get('month'), $this->request->getInt('customer'));
        if ($stored === null) {
            return $this->error('This month is not in the archive.', 404);
        }
        $params = ['month' => $stored['month'], 'customer' => $stored['customer_id']];
        $html = Report::render($this->app->view, $stored, $this->app->settings(), $this->consoleAddress(), [
            'script' => $this->app->url('image/tisk.js') . '?v=' . KALETA_VERSION, 'download' => $this->url('report_download', $params), 'back' => $this->url('reports')]);

        // its own inline styles and the Print button script, nothing else may run – like the site's monthly report
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; script-src 'self'; frame-ancestors 'self'; base-uri 'none'"]);
    }

    /** The same page as an HTML file to keep or send: no toolbar, no script. */
    protected function actionReportDownload(): Response
    {
        $stored = Report::get($this->db, $this->request->get('month'), $this->request->getInt('customer'));
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

    /**
     * The site ("site") or the customer ("customer") a posted task form is about (3.30, Admin\TaskActions); the change log
     * names it by the site's address or the customer's name.
     */
    protected function taskTarget(): ?array
    {
        $customer = $this->request->postInt('customer');
        if ($customer > 0) {
            $name = Customers::nameOf($this->db, $customer);

            return $name === '' ? null : ['target' => ConsoleTasks::customer($customer), 'back' => ['customer_detail', ['id' => $customer]], 'title' => $name, 'review' => null];
        }
        $id = $this->request->postInt('site');
        $url = $id > 0 ? $this->db->value('SELECT url FROM {fleet_sites} WHERE id = ?', [$id]) : null;

        return $url === null ? null : ['target' => ConsoleTasks::site($id), 'back' => ['detail', ['id' => $id]], 'title' => (string) $url, 'review' => null];
    }

    /** @return array<string, mixed>|null the site from ?id= or the posted id, with its attention and decoded report */
    private function site(): ?array
    {
        $id = $this->request->isPost() ? $this->request->postInt('id') : $this->request->getInt('id');
        $row = $this->db->one('SELECT * FROM {fleet_sites} WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        return $row + Console::attention($row, time()) + ['beat' => Console::storedBeat($row)];
    }

    /**
     * The inventory (3.30, Fleet\Inventory): a row per site with its version, channel, PHP, database, web server, features,
     * add-ons, languages and last report – filtered by each column and a free text.
     */
    protected function actionInventory(): Response
    {
        [$rows, $all, $filters, $query, $customers] = $this->inventory();

        return $this->view('inventory', 'Inventory', ['rows' => $rows, 'total' => count($all), 'options' => Inventory::options($all), 'filters' => $filters, 'query' => $query,
            'customers' => $customers, 'unknown' => count(array_filter($all, fn (array $r): bool => !$r['known']))]);
    }

    /** The inventory as it is filtered, to CSV (an administrator's export; the module is for administrators only). */
    protected function actionInventoryCsv(): Response
    {
        [$rows] = $this->inventory();
        ChangeLog::write($this->app, 'fleet', 'inventory_csv', (string) count($rows));

        return new Response(Inventory::csv($rows), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="fleet-inventory-' . date('Y-m-d') . '.csv"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The rows of the inventory with the filters of the request.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, mixed>, 3: string, 4: array<int, string>}
     */
    private function inventory(): array
    {
        $customers = Customers::names($this->db);
        $all = array_map(fn (array $s): array => Inventory::row($s, $customers), Console::sites($this->db));
        $filters = [];
        foreach (array_keys(Inventory::FILTERS) as $filter) {
            $filters[$filter] = $filter === 'customer' ? $this->request->getInt('customer') : mb_substr(trim($this->request->get($filter)), 0, 60);
        }
        $filters['customer'] = $filters['customer'] === -1 || isset($customers[$filters['customer']]) ? $filters['customer'] : 0;
        $query = mb_substr(trim($this->request->get('q')), 0, 100);

        return [Inventory::filter($all, $filters, $query), $all, $filters, $query, $customers];
    }

    /**
     * The expiry calendar (3.30, Fleet\Calendar): a month of certificate and domain dates, the list of the coming ones,
     * the recovery keys, the reminders and the administrator's calendar link.
     */
    protected function actionCalendar(): Response
    {
        $now = time();
        $sites = Console::sites($this->db);
        $events = Calendar::dates($sites, $now);
        $customers = Customers::names($this->db);
        $customer = $this->request->getInt('customer');
        $customer = $customer === -1 || isset($customers[$customer]) ? $customer : 0;
        $narrowed = array_values(array_filter($events, fn (array $e): bool => $customer === 0 || ($customer < 0 ? $e['customer_id'] === null : $e['customer_id'] === $customer)));
        $link = $this->app->session->get('fleet_calendar_link');
        $this->app->session->set('fleet_calendar_link', null);

        return $this->view('calendar', 'Calendar', ['month' => Calendar::month($narrowed, $this->request->get('month'), $now), 'events' => $narrowed, 'keys' => Calendar::keys($sites, $now),
            'customers' => $customers, 'customer' => $customer, 'reminders' => Calendar::remindersByKind($this->app->settings()), 'now' => $now, 'siteCount' => count($sites),
            'feedLink' => is_string($link) && $link !== '' ? AlertRules::consoleUrl($this->app) . '/fleet/calendar.ics?token=' . rawurlencode($link) : '',
            'hasFeed' => Calendar::hasFeed($this->app->settings(), $this->app->auth()->id()), 'alertsOn' => $this->app->settings()->bool('alerts_enabled'),
            'recipients' => AlertRules::recipients($this->app->settings())]);
    }

    /**
     * Saves the reminder days of the calendar ("30, 7"; empty = no reminders) – 3.31.1: for domain registrations
     * (fleet_expiry_reminders) and certificates (fleet_certificate_reminders) apart; a field the form does not send stays.
     * A wrong list saves nothing.
     */
    protected function actionCalendarSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'calendar');
        }
        $saved = [];
        foreach (Calendar::REMINDER_SETTINGS as $key) {
            if (!$this->request->hasPost($key)) {
                continue;
            }
            $days = Calendar::parseReminders($this->request->post($key));
            if ($days === null) {
                return $this->back(t('Write up to %d whole numbers of days from 0 to %d, separated by commas.', Calendar::MAX_REMINDERS, Calendar::MAX_REMINDER), 'calendar', [], 'chyba');
            }
            $saved[$key] = implode(',', $days);
        }
        foreach ($saved as $key => $value) {
            $this->app->settings()->set($key, $value);
        }
        ChangeLog::write($this->app, 'fleet', 'calendar_reminders', implode('; ', array_map(fn (string $k, string $v): string => $k . ' ' . $v, array_keys($saved), $saved)));

        return $this->back('Saved.', 'calendar');
    }

    /** Makes a new calendar link for the signed-in administrator (shown once) or revokes it. */
    protected function actionCalendarFeed(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'calendar');
        }
        $s = $this->app->settings();
        $user = $this->app->auth()->id();
        if ($this->request->post('feed') === 'revoke') {
            Calendar::revokeFeed($s, $user);
            ChangeLog::write($this->app, 'fleet', 'calendar_feed', 'revoked');

            return $this->back('The calendar link was revoked. A calendar app that still asks gets nothing.', 'calendar');
        }
        $this->app->session->set('fleet_calendar_link', Calendar::newFeed($s, $user));
        ChangeLog::write($this->app, 'fleet', 'calendar_feed', 'new');

        return $this->back('', 'calendar');
    }

    /**
     * The rollout (3.30, Fleet\Rollout): where the newest release has reached in each ring – updated, waiting (until
     * when), held back by the pilots, failed – the delay of each ring and the maintenance windows of the customers.
     */
    protected function actionRollout(): Response
    {
        $s = $this->app->settings();
        [$latest, $security, $firstSeen] = Console::latest($this->app);
        $windows = Rollout::customerWindows($this->db);
        $overview = Rollout::overview(Console::sites($this->db), $windows, $latest, $security, $firstSeen, time(), Rollout::delays($s), new \DateTimeZone(date_default_timezone_get()));

        return $this->view('rollout', 'Rollout', ['overview' => $overview, 'delays' => Rollout::delays($s), 'customers' => Customers::all($this->db), 'windows' => $windows,
            'names' => Customers::names($this->db), 'timeZone' => date_default_timezone_get(), 'now' => time()]);
    }

    /** Saves the delay of each ring (hours, 0 – Rollout::MAX_HOURS). */
    protected function actionRolloutSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'rollout');
        }
        $s = $this->app->settings();
        $saved = [];
        foreach (Rollout::SETTINGS as $ring => $key) {
            $value = trim($this->request->post($key));
            if (preg_match('/^\d{1,5}$/D', $value) !== 1 || (int) $value > Rollout::MAX_HOURS) {
                return $this->back(t('Each delay is a whole number of hours from 0 to %d. Nothing was saved.', Rollout::MAX_HOURS), 'rollout', [], 'chyba');
            }
            $saved[$key] = (string) (int) $value;
        }
        foreach ($saved as $key => $value) {
            $s->set($key, $value);
        }
        ChangeLog::write($this->app, 'fleet', 'rollout', implode(', ', $saved));

        return $this->back('Saved.', 'rollout');
    }

    /** Saves the maintenance window of one customer (no day ticked = any time). */
    protected function actionWindow(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'rollout');
        }
        $id = $this->request->postInt('customer');
        $name = Customers::names($this->db)[$id] ?? null;
        if ($name === null) {
            return $this->back('', 'rollout');
        }
        $window = Rollout::windowFromInput(is_array($_POST['window_days'] ?? null) ? $_POST['window_days'] : [], $this->request->post('window_from'), $this->request->post('window_to'));
        if (!Rollout::longEnough($window)) {
            return $this->back(t('A maintenance window must be at least %d hours long – the sites ask the console once an hour. Nothing was saved.', intdiv(Rollout::MIN_WINDOW_MINUTES, 60)), 'rollout', [], 'chyba');
        }
        $this->db->update('fleet_customers', ['update_window' => $window], ['id' => $id]);
        ChangeLog::write($this->app, 'fleet', 'window', $name . ': ' . Rollout::describe($window));

        return $this->back(t('Updates for %s: %s.', $name, Rollout::describe($window)), 'rollout');
    }
}
