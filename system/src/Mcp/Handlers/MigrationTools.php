<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\ImageDownloader;
use Kaleta\Core\MigrationReport;
use Kaleta\Core\WpFile;
use Kaleta\Core\WpImport;

/**
 * MCP tools for moving a site to Kaleta (2.7): the parity report, the import of old form entries and (3.6) the WordPress
 * export import – the same Core\WpImport steps as the admin, with everything arriving hidden. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait MigrationTools
{
    /**
     * import_wordpress (3.6): the WordPress (WXR) import of the admin over MCP – one batch per call, resumable with the
     * import id. Everything arrives hidden (news as drafts, pages and items hidden), menus go to the draft look.
     */
    private function toolImportWordpress(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('A WordPress export is imported by an administrator.');
        }
        if (!class_exists(\XMLReader::class) || !class_exists(\DOMDocument::class)) {
            throw new \DomainException('The PHP extension xmlreader or dom is missing on the server – a WordPress export cannot be read without them.');
        }
        $db = $this->app->db();
        $settings = $this->app->settings();
        $id = trim((string) ($a['import'] ?? ''));
        if ($id !== '') {
            $state = (WpFile::path($id) !== null ? WpImport::loadState($id) : null) ?? throw new \InvalidArgumentException('The import does not exist; start a new one with file or url.');
        } else {
            $file = basename(str_replace('\\', '/', trim((string) ($a['file'] ?? ''))));
            $url = trim((string) ($a['url'] ?? ''));
            if ($file === '' && $url === '') {
                throw new \InvalidArgumentException('Give file (a WordPress export uploaded with upload_file, in the admin or over FTP into storage/import/) or url (the http(s) address of the export).');
            }
            if ($file === '') {
                if (!ImageDownloader::isAvailable()) {
                    throw new \DomainException('This server cannot download files from other sites – upload the export with upload_file or in the admin instead.');
                }
                try {
                    $file = WpImport::download($url);
                } catch (\RuntimeException $e) {
                    throw new \InvalidArgumentException('The export could not be downloaded: ' . $e->getMessage() . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')
                        . ' An export over ' . (WpImport::MAX_DOWNLOAD >> 20) . ' MB goes over FTP into storage/import/ (then call with file).');
                }
            }
            $path = WpFile::path($file) ?? throw new \InvalidArgumentException('There is no such WordPress export in storage/import/ – upload it with upload_file (a file name ending .xml), in the admin (Import and export) or over FTP.');
            try {
                (new WpFile($path))->verify();
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException($e->getMessage());
            }
            $state = WpImport::begin($file);
        }
        $confirm = ($a['confirm'] ?? false) === true;
        $created = WpImport::created($state); // the records this call creates count against the hourly change limit (3.7, N37-26)
        $start = function (array &$state) use ($a, $db, $settings): void {
            WpImport::run($state, WpImport::options($this->wordpressOptions($a), $db, $settings));
            WpImport::saveState($state);
        };
        if ($confirm && $state['faze'] === 'nahled') {
            $start($state);
        }
        [$state, $error] = WpImport::advance($db, $settings, $this->app->request->basePath(), $this->app->auth()->id(), $state);
        if ($confirm && $error === null && $state['faze'] === 'nahled') {
            $start($state); // a small file is read in the first call: with confirm the import starts with the next one
        }
        $domain = ImageDownloader::domainFromUrl((string) $state['web']['adresa']);
        $canDownload = ImageDownloader::isAvailable() && extension_loaded('gd') && $domain !== '';
        if ($state['faze'] === 'hotovo' && ($state['volby']['obrazky'] ?? false) && $canDownload) {
            (new WpImport($db, $settings, $this->app->request->basePath(), $this->app->auth()->id()))->startImages($state);
            WpImport::saveState($state);
        }
        $this->recordsCreated = WpImport::created($state) - $created;

        return $this->wordpressResult($state, $error, $canDownload);
    }

    /**
     * upload_file with a .xml file (3.6): a WordPress export is not a Media file – it holds drafts, private posts and the
     * authors' e-mails – so it is checked and kept in storage/import/ (not reachable from the web) for import_wordpress.
     *
     * @return array<string, mixed>
     */
    private function uploadWordpressExport(string $content, string $displayName): array
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('A WordPress export is imported by an administrator.');
        }
        // a drafts-only connection never feeds an import (3.6, N36-2) – the export decides what an administrator imports
        if (($this->app->auth()->connection()['access'] ?? 'full') !== 'full') {
            throw new \DomainException('A WordPress export can be uploaded only by a connection with full access – this one is limited to drafts or reading.');
        }
        $name = WpFile::uploadName($displayName);
        if (WpFile::path($name) !== null) {
            throw new \InvalidArgumentException('An export named ' . $name . ' is already there – upload it under another name; an export is never replaced.');
        }
        $temporary = WpFile::folder() . '/nahrani-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, $content);
            (new WpFile($temporary))->verifyContent(); // only a WordPress export is kept
            if (!rename($temporary, WpFile::folder() . '/' . $name)) {
                throw new \RuntimeException('The file could not be saved – check write permissions for storage/import.');
            }
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } finally {
            @unlink($temporary);
        }

        return ['import_file' => $name, 'in_media' => false,
            'next' => 'import_wordpress with {"file":"' . $name . '"} – the export stays private in storage/import/, it is not in Media.'];
    }

    /**
     * The English options of import_wordpress as WpImport options; over MCP everything arrives hidden and images are
     * downloaded unless images: false.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function wordpressOptions(array $a): array
    {
        $input = ['skryte' => true, 'obrazky' => true, 'jazyk' => (string) ($a['language'] ?? ''), 'rubrika' => (int) ($a['default_category'] ?? 0),
            'autori' => is_array($a['authors'] ?? null) ? $a['authors'] : []];
        foreach (['drafts' => 'koncepty', 'pages' => 'stranky', 'builder' => 'stavitel', 'redirects' => 'presmerovani', 'collections' => 'kolekce', 'menus' => 'menu', 'images' => 'obrazky', 'add_languages' => 'jazyky_pridat'] as $en => $cs) {
            if (array_key_exists($en, $a)) {
                $input[$cs] = (bool) $a[$en];
            }
        }
        $locations = [];
        foreach (is_array($a['menu_locations'] ?? null) ? $a['menu_locations'] : [] as $slug => $location) {
            $locations[(string) $slug] = ['main' => 'hlavni', 'footer' => 'paticka', 'skip' => ''][is_string($location) ? $location : ''] ?? null;
        }
        $input['menu_umisteni'] = array_filter($locations, fn (?string $l): bool => $l !== null);

        return $input;
    }

    /**
     * The answer of import_wordpress: where the import is, what it found and did (WpImport::summary) and what comes next.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function wordpressResult(array $state, ?\RuntimeException $error, bool $canDownload): array
    {
        $phase = ['analyza' => 'reading', 'nahled' => 'preview', 'import' => 'importing', 'hotovo' => 'done', 'obrazky' => 'downloading_images', 'obrazky-hotovo' => 'done'][$state['faze']] ?? (string) $state['faze'];
        $summary = WpImport::summary($state);
        foreach ($summary['menus'] as &$menu) {
            if (isset($menu['items_for_save_menu'])) {
                $menu['items_for_save_menu'] = \Kaleta\Mcp\Translator::menuItemsToEnglish($menu['items_for_save_menu']);
            }
        }
        unset($menu);
        $out = ['import' => $state['soubor'], 'old_site' => ['name' => $state['web']['nazev'], 'url' => $state['web']['adresa']], 'phase' => $phase,
            'progress' => $state['faze'] === 'obrazky' ? ['records_done' => (int) $state['obr']['hotovo'], 'records_total' => (int) $state['obr']['celkem']]
                : ['processed' => (int) $state['pozice'], 'total' => (int) $state['celkem']],
            'everything_hidden' => true,
            'batches' => sprintf('Each call does one batch (up to %d items or about %d seconds, images %d at a time); the import resumes where it stopped, and running the same file again skips what is already here.', WpImport::BATCH, WpImport::SECONDS, WpImport::IMAGE_BATCH),
        ];
        if ($error !== null) {
            $out['error'] = $error->getMessage() . ($error->getCode() > 0 ? ' ' . $error->getCode() : '');
        }
        $out['found'] = $summary['found'];
        if (in_array($state['faze'], ['nahled', 'analyza'], true)) {
            $out['will_be_skipped'] = array_values(array_filter($summary['skipped'], fn (array $s): bool => !in_array($s['what'], ['already_imported'], true)));
        } else {
            $out += array_diff_key($summary, ['found' => 1]);
        }
        $out['next'] = match (true) {
            $error !== null => 'The import stopped – tell the user the error. A damaged file: export it from WordPress again and start with the new file.',
            $state['faze'] === 'analyza' => 'Call again with the same import until the phase is "preview".',
            $state['faze'] === 'nahled' => 'Show the user what was found and what will be skipped. On their instruction call again with import and confirm: true, with the options you agreed (drafts, pages, builder, redirects, collections, menus, menu_locations, authors, images, language, default_category). Everything arrives hidden.',
            $state['faze'] === 'import' => 'Call again with the same import until the phase is "done".',
            $state['faze'] === 'obrazky' => 'Images are being downloaded into Media – call again with the same import until the phase is "done".',
            default => 'Done – nothing is public yet: news are drafts, pages and items hidden, menus in the draft look. Next: (1) run migration_report with the old site\'s address and fix what it finds as drafts; '
                . '(2) check the menus with get_menu and preview_link site: true, and publish_look only when the user asks; (3) rebuild the pages whose layout came from a page builder (skipped "layout:…") with build_from_html from the live page; '
                . '(4) publish pages and news (update_page visible, update_news publish) only on the user\'s instruction.'
                . (($state['volby']['obrazky'] ?? false) && !$canDownload ? ' Images were not downloaded: this server cannot download them or the file does not name the old site – move them with upload_file.' : ''),
        };

        return $out;
    }

    /** migration_report */
    private function toolMigrationReport(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The migration report is for administrators.');
        }
        $id = trim((string) ($a['report_id'] ?? ''));
        if ($id === '') {
            $url = trim((string) ($a['url'] ?? ''));
            $url = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
            if (!\Kaleta\Core\WebImport::validUrl($url) || !ImageDownloader::isAvailable()) {
                throw new \InvalidArgumentException('Give the address of the old site, e.g. https://www.example.com.');
            }
            $state = MigrationReport::newState($url);
        } else {
            $state = MigrationReport::load($id) ?? throw new \InvalidArgumentException('The report does not exist; start a new one with the url.');
        }
        $report = new MigrationReport($this->app, new ImageDownloader($state['web'], true));
        if ($state['faze'] !== 'hotovo') {
            $report->step($state, fn (array $s) => MigrationReport::save($s)); // saved between the heavy parts (3.7, N37-23)
            MigrationReport::save($state);
        }
        $r = $report->result($state);
        $s = $r['souhrn'];
        // a large report is read in pages of 100 problem rows (3.7); a whole number in range, never a cast huge one (N37-27)
        $offset = filter_var($a['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => \Kaleta\Core\WebImport::MAX_PAGES]]);
        if ($offset === false) {
            throw new \InvalidArgumentException('offset must be a whole number from 0 to ' . \Kaleta\Core\WebImport::MAX_PAGES . ' (0, 100, 200…).');
        }
        $rows = array_values(array_filter($r['radky'], fn (array $row): bool => $row['problemy'] !== []));

        return ['report_id' => $state['id'], 'old_site' => $state['web'],
            'phase' => ['hledani' => 'finding', 'kontrola' => 'checking', 'hotovo' => 'done'][$state['faze']] ?? $state['faze'],
            'summary' => ['addresses' => $s['adres'], 'checked' => $s['zkontrolovano'], 'ok' => $s['ok'], 'redirected' => $s['presmerovano'],
                'not_published' => $s['skryto'], 'missing' => $s['chybi'], 'errors' => $s['chyb'], 'warnings' => $s['varovani']],
            'problems' => array_map(fn (array $row): array => ['old' => $row['stara'], 'new' => $row['nova'] ?: null, 'status' => $row['stav'],
                'problems' => array_map(fn (string $p): array => ['code' => $p, 'severity' => MigrationReport::PROBLEMS[$p] ?? 'info', 'message' => MigrationReport::describe($p)], $row['problemy']),
                'old_title' => $row['titulek_stary'], 'new_title' => $row['titulek_novy']], array_slice($rows, $offset, 100)),
            'more_problems' => max(0, count($rows) - $offset - 100),
            'notes' => $r['poznamky'], // a robots.txt that was cut, sitemaps on other hosts that were not read (3.7)
            'site_checks' => array_map(fn (array $c): array => ['message' => $c['zprava'], 'fix_in' => $this->app->request->origin() . $this->app->url($c['uprava'])], $r['web']),
            'next' => $state['faze'] !== 'hotovo' ? 'Call again with the same report_id until the phase is done.'
                : (count($rows) > $offset + 100 ? 'More problems: call again with the same report_id and offset ' . ($offset + 100) . ' for the next 100. ' : '')
                . 'Fix what you can as drafts (save_redirect for missing addresses, descriptions, forms), list the rest for the user, and run a new report before the domain is switched.'];
    }

    /** import_enquiries */
    private function toolImportEnquiries(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() || !$auth->hasModule('enquiries')) {
            throw new \DomainException('Old enquiries are imported by an administrator with the Enquiries section.');
        }
        $source = strtolower(trim((string) ($a['source'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,30}$/D', $source)) {
            throw new \InvalidArgumentException('source: a short name of where the entries come from, e.g. breakdance or old-site.cz.');
        }
        $entries = $a['entries'] ?? null;
        if (!is_array($entries) || $entries === [] || count($entries) > 200) {
            throw new \InvalidArgumentException('entries: a list of 1–200 entries; send more in further calls.');
        }
        $status = ['new' => 0, 'read' => 1, 'resolved' => 2][(string) ($a['status'] ?? 'read')] ?? throw new \InvalidArgumentException('status must be new, read or resolved.');
        $db = $this->app->db();
        $months = $this->app->settings()->int('enquiries_months');
        $limit = $months > 0 ? date('Y-m-d H:i:s', strtotime('-' . $months . ' months')) : null;
        $imported = $skipped = $old = 0;
        $errors = [];
        foreach (array_values($entries) as $i => $e) {
            $entry = self::enquiryEntry($e);
            if (is_string($entry)) {
                $errors[] = 'entries[' . $i . ']: ' . $entry;
                continue;
            }
            $key = sha1($entry['datum'] . '|' . $entry['formular'] . '|' . json_encode($entry['data'], JSON_UNESCAPED_UNICODE));
            if ($db->value('SELECT 1 FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND cizi_id = ?', ['form:' . $source, 'poptavka', $key]) !== null) {
                $skipped++;
                continue;
            }
            $id = $db->insert('poptavky', ['datum' => $entry['datum'], 'formular' => $entry['formular'], 'zdroj' => mb_substr('import:' . $source, 0, 40),
                'stranka' => $entry['stranka'], 'email' => $entry['email'], 'data' => (string) json_encode($entry['data'], JSON_UNESCAPED_UNICODE), 'stav' => $status]);
            $db->run('INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?)', ['form:' . $source, 'poptavka', $key, $id]);
            $imported++;
            if ($limit !== null && $entry['datum'] < $limit) {
                $old++;
            }
        }

        return ['imported' => $imported, 'already_imported' => $skipped, 'errors' => $errors,
            'older_than_retention' => $old,
            'note' => $old > 0 ? sprintf('%d entries are older than the %d months enquiries are kept (Settings → Privacy and cookies); the site deletes them with the next clean-up. Tell the user.', $old, $months) : null];
    }

    /**
     * One entry of import_enquiries, checked: {date, form, page, email, fields: [{label, value}] or {label: value}}.
     *
     * @return array{datum: string, formular: string, stranka: string, email: string, data: list<array{0: string, 1: string}>}|string the entry, or what is wrong
     */
    public static function enquiryEntry(mixed $e): array|string
    {
        if (!is_array($e)) {
            return 'an entry is an object';
        }
        $time = strtotime((string) ($e['date'] ?? ''));
        if ($time === false || $time > time() + 86400) {
            return 'date must be a date and time, e.g. 2025-03-14 09:30';
        }
        $fields = [];
        $raw = $e['fields'] ?? [];
        if (is_array($raw) && !array_is_list($raw)) {
            $raw = array_map(fn (string|int $k, mixed $v): array => ['label' => (string) $k, 'value' => $v], array_keys($raw), $raw);
        }
        foreach (is_array($raw) ? $raw : [] as $f) {
            if (!is_array($f) || !is_scalar($f['value'] ?? null)) {
                continue;
            }
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 200);
            $value = mb_substr(trim(strip_tags((string) $f['value'])), 0, 5000);
            if ($label !== '' && $value !== '') {
                $fields[] = [$label, $value];
            }
            if (count($fields) >= 60) {
                break;
            }
        }
        if ($fields === []) {
            return 'fields are empty';
        }
        $email = trim((string) ($e['email'] ?? ''));
        if ($email === '') {
            foreach ($fields as [, $value]) {
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $email = $value;
                    break;
                }
            }
        }

        return ['datum' => date('Y-m-d H:i:s', $time), 'formular' => mb_substr(trim((string) ($e['form'] ?? '')), 0, 120),
            'stranka' => mb_substr(trim((string) ($e['page'] ?? '')), 0, 255),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : '', 'data' => $fields];
    }
}
