<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Db;
use Kaleta\Core\RedirectRules;
use Kaleta\Core\Response;

/**
 * 301 redirects: old URL -> new. Created automatically when the slug of a page, news item or category changes,
 * manually useful after moving from another system. Used only when the site finds nothing for the URL. Every new
 * redirect also heals the site's own links to the old address (Core\LinkHealing).
 *
 * Since 2.14 the screen also shows, for every address visitors could not find, the page the site thinks they meant
 * (Core\RedirectMatcher) with one click to create the redirect, and the setting that lets the daily job create the
 * sure ones by itself; such a redirect is marked automatic with its score and deleting it is the undo.
 */
final class Redirects extends Module
{
    public const string IDENT = 'redirects';
    public const string NAME = 'Redirects';
    public const string GROUP = 'Site care';
    public const string ICON = 'presmerovani';
    public const string EXTENSION = 'presmerovani';
    public const bool ADMIN_ONLY = true;

    /** $heal = false: a bulk import (Core\RedirectRules::save) leaves the site's links alone, a scan per row would take minutes. */
    public static function add(Db $db, string $z, string $commandName, bool $heal = true): void
    {
        $z = trim($z, '/ ');
        if ($z === '' || $z === trim($commandName, '/ ')) {
            return;
        }
        // the new target URL also takes over older redirects, so that no chains form
        $db->run('UPDATE {presmerovani} SET na_adresu = ? WHERE na_adresu = ?', [$commandName, $z]);
        $db->run('DELETE FROM {presmerovani} WHERE z_adresy = ?', [trim($commandName, '/ ')]);
        $db->run(
            'INSERT INTO {presmerovani} (z_adresy, na_adresu, vytvoreno) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE na_adresu = VALUES(na_adresu)',
            [mb_substr($z, 0, 255), mb_substr($commandName, 0, 255)],
        );
        // links on the site that still lead to the old address are rewritten, so visitors never meet the redirect (2.14)
        if ($heal) {
            \Kaleta\Core\LinkHealing::heal($db, $z, $commandName);
        }
    }

    private const int PER_PAGE = 50;

    protected function actionList(): Response
    {
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        $whereParts = $search !== '' ? 'WHERE z_adresy LIKE ? OR na_adresu LIKE ?' : '';
        $params = $search !== '' ? array_fill(0, 2, '%' . addcslashes($search, '%_\\') . '%') : [];
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {presmerovani} ' . $whereParts, $params);
        $pageNumber = max(1, min((int) ceil(max(1, $total) / self::PER_PAGE), $this->request->getInt('page', 1)));

        $notFound = \Kaleta\Core\NotFound::pending($this->app, 60, 50);

        return $this->view('list', 'Redirects', [
            'records' => $this->db->all('SELECT * FROM {presmerovani} ' . $whereParts . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'total' => $total, 'pageNumber' => $pageNumber, 'pageCount' => (int) ceil($total / self::PER_PAGE), 'search' => $search,
            'edit' => $this->request->getInt('edit') > 0 ? $this->db->one('SELECT * FROM {presmerovani} WHERE idp = ?', [$this->request->getInt('edit')]) : null,
            'notFound' => $notFound,
            'suggestions' => \Kaleta\Core\RedirectMatcher::suggestions($this->app, $notFound),
            'autoOn' => $this->app->settings()->bool('redirect_auto'),
            'threshold' => \Kaleta\Core\RedirectMatcher::threshold($this->app->settings()),
            'fromUrl' => mb_substr($this->request->get('from'), 0, 255),
        ]);
    }

    /** Redirects for missing addresses by themselves (2.14): on/off and the score a candidate needs. */
    protected function actionSettings(): Response
    {
        if ($this->request->isPost()) {
            $threshold = $this->request->postInt('redirect_auto_threshold');
            $s = $this->app->settings();
            $s->set('redirect_auto', $this->request->post('redirect_auto') === '1' ? '1' : '0');
            $s->set('redirect_auto_threshold', (string) ($threshold >= 50 && $threshold <= 100 ? $threshold : \Kaleta\Core\RedirectMatcher::DEFAULT_THRESHOLD));
            \Kaleta\Admin\ChangeLog::write($this->app, 'redirects', 'settings', ($s->bool('redirect_auto') ? 'on' : 'off') . ', ' . $s->get('redirect_auto_threshold'));
        }

        return $this->back('Settings saved.');
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $z = (string) parse_url($this->request->post('z_adresy'), PHP_URL_PATH);
        $commandName = $this->request->post('na_adresu');
        $code = match ($this->request->postInt('typ')) {
            302 => 302,
            RedirectRules::GONE => RedirectRules::GONE,
            default => 301,
        };
        if ($code === RedirectRules::GONE) {
            $commandName = '/'; // 3.6: a 410 rule has no target – the address answers "gone" with the not-found page
        }
        if (trim($z, '/') === '' || $commandName === '' || (!preg_match('#^https?://#i', $commandName) && !preg_match('#^/?[^\s:]*$#D', $commandName))) {
            return $this->back('Enter the old address (a path on this site) and the target – a path or a full https://… URL', type: 'chyba');
        }
        $target = $code === RedirectRules::GONE ? '' : (preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/'));
        $from = trim($z, '/ ');
        $idp = $this->request->postInt('idp');
        // 3.6: a pattern (/blog/* → /news/*) is checked like a row of the CSV import, and no rule may close a loop
        if (($refusal = RedirectRules::refusal($this->db, $from, $target, $idp, $code)) !== null) {
            return $this->back($refusal, type: 'chyba');
        }
        if ($idp > 0) {
            // editing an existing record
            $this->db->update('presmerovani', ['z_adresy' => mb_substr($from, 0, 255), 'na_adresu' => mb_substr($target, 0, 255)], ['idp' => $idp]);
        } elseif (RedirectRules::isPattern($from) || $code === RedirectRules::GONE) {
            RedirectRules::store($this->db, $from, $target, $code);
        } else {
            self::add($this->db, $z, $target);
        }
        $this->db->run('UPDATE {presmerovani} SET typ = ?, auto_score = NULL WHERE z_adresy = ?', [$code, $from]);
        $this->db->delete('nenalezeno', ['cesta' => trim($z, '/')]);

        return $this->back('Redirect saved.');
    }

    /**
     * CSV import, step 1 (3.6): the file (or pasted text) is read and every row is checked – what would be added, changed
     * or refused and why. Nothing is saved yet; the text goes back with the confirmation and is checked again on saving.
     */
    protected function actionImport(): Response
    {
        $csv = $this->request->isPost() ? $this->csvText() : null;
        if ($csv === null) {
            return $this->back('Choose a CSV file or paste its rows (old address, target, code).', type: 'chyba');
        }
        $plan = RedirectRules::plan($this->db, RedirectRules::parseCsv($csv));
        if ($plan === []) {
            return $this->back('The file has no redirects – one per row: old address, target, code (301 or 302).', type: 'chyba');
        }

        return $this->view('import', 'Import redirects', [
            'plan' => $plan, 'csv' => $csv,
            'counts' => array_count_values(array_column($plan, 'status')) + ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0],
        ]);
    }

    /** CSV import, step 2: the confirmed text is checked again and the accepted rows are saved. */
    protected function actionImportSave(): Response
    {
        $csv = $this->request->isPost() ? $this->csvText() : null;
        if ($csv === null) {
            return $this->back();
        }
        @set_time_limit(120); // thousands of rows of a moved site
        $plan = RedirectRules::save($this->db, RedirectRules::parseCsv($csv));
        $counts = array_count_values(array_column($plan, 'status')) + ['added' => 0, 'changed' => 0, 'refused' => 0];
        \Kaleta\Admin\ChangeLog::write($this->app, 'redirects', 'import', $counts['added'] . ' + ' . $counts['changed']);
        \Kaleta\Front\Cache::clear();
        $this->app->session->flash($counts['refused'] > 0 ? 'chyba' : 'ok', t('Redirects imported: %d added, %d changed, %d refused.', $counts['added'], $counts['changed'], $counts['refused']));

        return Response::redirect($this->url());
    }

    /** The CSV from the uploaded file or the text field (UTF-8; a Windows-1250 file from Excel is converted), null = none. */
    private function csvText(): ?string
    {
        $file = $this->request->file('soubor');
        $text = $this->request->post('csv');
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($tmp !== '' && ($file['error'] ?? null) === UPLOAD_ERR_OK && is_uploaded_file($tmp) && filesize($tmp) <= 2_000_000) {
            $text = (string) file_get_contents($tmp);
        }
        if ($text === '' || strlen($text) > 2_000_000) {
            return null;
        }

        return self::toUtf8($text);
    }

    /**
     * A CSV saved by Excel in Czech Windows is Windows-1250. mbstring does not know that encoding (it threw a ValueError and
     * the import ended with an error 500 – 3.6, N36-3), so iconv converts it; without iconv ISO-8859-2, which has the same
     * Czech and Slovak letters, is the fallback.
     */
    public static function toUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = function_exists('iconv') ? @iconv('Windows-1250', 'UTF-8//IGNORE', $text) : false;

        return is_string($converted) ? $converted : (string) mb_convert_encoding($text, 'UTF-8', 'ISO-8859-2');
    }

    /** An address visitors could not find, left alone for good (a bot probe, something nobody needs). */
    protected function actionIgnore(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Core\NotFound::ignore($this->app, [$this->request->post('cesta')]);
        }

        return Response::redirect($this->url() . '#nenalezeno');
    }

    /** All addresses waiting now – the warning on the start screen goes away until a new address appears. */
    protected function actionIgnoreAll(): Response
    {
        $count = $this->request->isPost() ? \Kaleta\Core\NotFound::ignore($this->app) : 0;
        $this->app->session->flash('ok', t('%d addresses ignored. A new address that visitors cannot find will show up again.', $count));

        return Response::redirect($this->request->post('zpet') === 'prehled' ? $this->app->url('admin.php') : $this->url() . '#nenalezeno');
    }

    /** Empties the overview of not-found URLs. */
    protected function actionClear(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {nenalezeno}');
        }

        return $this->back('The list of addresses not found is empty.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('presmerovani', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back('Redirect deleted.');
    }
}
