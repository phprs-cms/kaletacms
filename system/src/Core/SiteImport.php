<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Pages;
use Kaleta\Builder\Build;
use Kaleta\Builder\CollectionCategories;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Components;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Popups;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\Style;

/**
 * Import of a Kaleta export (1.8): moves a whole site into a new, empty installation – the counterpart of SiteExport.
 *
 * The site is empty, so every row keeps its number from the export and all references between pages, news, collections,
 * components, menus and media stay valid without remapping. What comes in goes through the same validators as when saving
 * in the administration: builds (Build::sanitize), classes (Style), menus, collection data, pop-up rules, design system.
 * Users, passwords, keys and tokens are never in an export, so they are never imported; the imported news belong to the
 * administrator who runs the import.
 *
 * Steps (Admin\Modules\Transfer, one step per request): prepare – obsah.json is split into one file per table (one row per
 * line, so even a large export does not need to fit in memory); preview; data – a database backup, emptying the content
 * and the rows in batches; media – files from the archive in batches; done. The state is a file in storage/import.
 */
final class SiteImport
{
    /**
     * Tables in the order of import (a folder before media, a collection before its items). Pop-ups come before the builds:
     * a 1.x export may carry the old Modal element, which becomes a new pop-up (Builder\ModalConversion) next to them.
     */
    public const array TABLES = ['kategorie', 'stitky', 'popupy', 'stranky', 'novinky', 'presmerovani', 'tridy', 'casti', 'komponenty', 'sekce', 'menu',
        'kolekce', 'kolekce_sablony', 'kolekce_polozky', 'collection_categories', 'collection_category_texts', 'collection_category_templates', 'collection_item_categories',
        'document_versions', 'media_slozky', 'media', 'facts', 'hours_exceptions', 'notice_log', 'blueprints', 'notebook',
        'booking_services', 'booking_staff', 'booking_staff_services', 'booking_hours', 'booking_off'];

    /** Content emptied before the import (including what depends on it: versions, drafts, usage and link checks). */
    private const array EMPTIED = ['novinky_stitky', 'novinky_revize', 'novinky_koncepty', 'stranky_revize', 'stavba_revize', 'media_pouziti', 'odkazy_vadne',
        'collection_item_categories', 'collection_category_texts', 'collection_category_templates', 'collection_categories', 'kolekce_polozky', 'kolekce_sablony', 'kolekce', 'novinky', 'kategorie', 'stitky', 'stranky', 'presmerovani', 'tridy', 'casti', 'komponenty', 'sekce',
        'menu', 'popupy', 'media', 'media_slozky', 'import_mapa', 'facts', 'fact_history', 'hours_exceptions', 'document_versions', 'document_downloads', 'notice_log', 'blueprints', 'notebook', 'draft_comments',
        'booking_staff_services', 'booking_hours', 'booking_off', 'booking_staff', 'booking_services']; // the bookings themselves stay: personal data of this site's customers

    /** Files that may come from the archive into media/ (images and the attachments Media accepts). */
    private const array MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'];

    private const int BATCH = 300;
    private const int MEDIA_BATCH = 100;
    private const int SECONDS = 8;

    /** @var array<string, list<string>> columns of the tables on this site */
    private array $columns = [];

    /** The export brings the notice log itself (2.11) – otherwise every imported notice gets a 'created' row. */
    private bool $exportHasNoticeLog = false;

    /** @var array<int, array<string, mixed>|null> idk => the collection when it is an official notice board (for the 'created' rows of imported notices) */
    private array $noticeBoards = [];

    /** The working folder of the running import (the export's rows per table). */
    private string $work = '';

    /** @var array<string, true>|null the page slugs of the export, so a renamed page never takes the slug of a later one */
    private ?array $exportPageSlugs = null;

    /** @var array<string, string> a page slug the system uses => the free one its page got (its subpages move with it) */
    private array $renamedPages = [];

    /** @var list<list<string>> what the import changed so the site keeps working: [format, ...arguments] for t(), shown at the end */
    private array $notes = [];

    /** @var list<list<string>> the same from the static row cleaners: content over a limit of HtmlLimits (3.8) */
    private static array $limitNotes = [];

    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly int $admin)
    {
    }

    /* ---------- files in storage/import ---------- */

    /** @return list<array{soubor:string, velikost:int, cas:int}> Kaleta exports in storage/import, newest on top */
    public static function listAll(): array
    {
        $files = [];
        foreach (glob(WpFile::FOLDER . '/*.{zip,json}', GLOB_BRACE) ?: [] as $path) {
            if (self::isValidName(basename($path))) {
                $files[] = ['soubor' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
            }
        }
        usort($files, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $files;
    }

    public static function isValidName(string $file): bool
    {
        return $file !== '' && strlen($file) <= 150 && basename($file) === $file && !str_starts_with($file, '.')
            && !preg_match('#[/\\\\\x00-\x1f]#', $file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['zip', 'json'], true)
            && !preg_match('/^(kaleta-)?stav-[0-9a-f]{16}\.json$/D', $file); // state files of the imports live in the same folder
    }

    public static function path(string $file): ?string
    {
        return self::isValidName($file) && is_file(WpFile::FOLDER . '/' . $file) ? WpFile::FOLDER . '/' . $file : null;
    }

    public static function uploadName(string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) === 'json' ? 'json' : 'zip';

        return slugify(pathinfo($original, PATHINFO_FILENAME), 80) . '.' . $extension;
    }

    /* ---------- state ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file): array
    {
        return ['soubor' => $file, 'faze' => 'priprava', 'hlavicka' => [], 'pocty' => [], 'media_celkem' => 0, 'tabulka' => 0, 'pozice' => 0,
            'vyprazdneno' => false, 'zaloha' => '', 'media_pozice' => 0, 'vysledek' => [], 'media' => ['ulozeno' => 0, 'preskoceno' => 0], 'chyby' => [], 'zmeny' => [], 'prejmenovane_stranky' => []];
    }

    private static function stateFile(string $file): string
    {
        return WpFile::FOLDER . '/kaleta-stav-' . substr(sha1($file), 0, 16) . '.json';
    }

    /** Working folder of the import: obsah.json and one file per table. */
    private static function workFolder(string $file): string
    {
        return WpFile::FOLDER . '/kaleta-' . substr(sha1($file), 0, 16);
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file): ?array
    {
        $data = is_file(self::stateFile($file)) ? json_decode((string) file_get_contents(self::stateFile($file)), true) : null;

        return is_array($data) ? array_replace(self::newState($file), $data) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['soubor']);
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path);
    }

    /**
     * What the import changed so the site keeps working (3.7): a page on an address of the system, a collection category
     * against the tree rules – translated for the result.
     *
     * @param array<string, mixed> $state
     * @return list<string>
     */
    public static function changes(array $state): array
    {
        $out = [];
        foreach ((array) ($state['zmeny'] ?? []) as $note) {
            $parts = array_values(array_filter((array) $note, 'is_string'));
            if ($parts !== []) {
                $out[] = t(array_shift($parts), ...$parts);
            }
        }

        return $out;
    }

    /** Deletes the state and the working folder (the file itself stays until the administrator deletes it). */
    public static function deleteState(string $file): void
    {
        @unlink(self::stateFile($file));
        foreach (glob(self::workFolder($file) . '/*') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir(self::workFolder($file));
    }

    /**
     * What the site already contains; the import is allowed only on an empty site (a fresh installation, possibly with
     * a starter site: at most its five pages and the welcome news item).
     *
     * @return array{prazdny: bool, stranky: int, novinky: int, polozky: int, media: int}
     */
    public static function siteContent(Db $db): array
    {
        $c = ['stranky' => (int) $db->value('SELECT COUNT(*) FROM {stranky}'), 'novinky' => (int) $db->value('SELECT COUNT(*) FROM {novinky}'),
            'polozky' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky}'), 'media' => (int) $db->value('SELECT COUNT(*) FROM {media}')];

        return ['prazdny' => $c['stranky'] <= 5 && $c['novinky'] <= 1 && $c['polozky'] === 0 && $c['media'] === 0] + $c;
    }

    /* ---------- 1. preparation ---------- */

    /**
     * Reads the header and splits obsah.json into files per table (one JSON row per line); counts rows and media files.
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException the file is not a Kaleta export or comes from a newer Kaleta
     */
    public static function prepare(array &$state): void
    {
        $path = self::path((string) $state['soubor']) ?? throw new \RuntimeException('The file does not exist.');
        $work = self::workFolder((string) $state['soubor']);
        if (!is_dir($work) && !@mkdir($work, 0775, true)) {
            throw new \RuntimeException('Cannot create the storage/import folder – check write permissions.');
        }
        $json = $work . '/obsah.json';
        $mediaCount = 0;
        if (str_ends_with(strtolower($path), '.zip')) {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('The PHP zip extension is missing on the server – upload obsah.json from the archive instead.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true || ($in = $zip->getStream('obsah.json')) === false) {
                throw new \RuntimeException('The file is not a Kaleta export (obsah.json is missing in the archive).');
            }
            $out = fopen($json, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (self::mediaTarget($name) !== null) {
                    $mediaCount++;
                }
            }
            $zip->close();
        } else {
            copy($path, $json);
        }
        [$header, $counts] = self::split($json, $work);
        @unlink($json);
        if (($header['format'] ?? '') !== 'kaleta-export') {
            throw new \RuntimeException('The file is not a Kaleta export.');
        }
        if ((int) ($header['verze_formatu'] ?? 0) > SiteExport::FORMAT_VERSION || version_compare((string) ($header['kaleta'] ?? '0'), KALETA_VERSION, '>')) {
            throw new \RuntimeException('The export comes from a newer version of Kaleta – update this site first (Settings → Backups and updates).');
        }
        $state['hlavicka'] = ['kaleta' => (string) ($header['kaleta'] ?? ''), 'vytvoreno' => (string) ($header['vytvoreno'] ?? ''),
            'nazev' => (string) ($header['nastaveni']['site_name'] ?? ''), 'verze_formatu' => (int) ($header['verze_formatu'] ?? 1)];
        $state['pocty'] = $counts;
        $state['media_celkem'] = $mediaCount;
        $state['faze'] = 'nahled';
    }

    /**
     * obsah.json as written by SiteExport has one row per line – read as a stream. Any other layout (e.g. formatted by hand)
     * is read whole, when it is not too large.
     *
     * @return array{0: array<string, mixed>, 1: array<string, int>} header with settings, rows per table
     */
    private static function split(string $json, string $work): array
    {
        $parts = [];
        $counts = [];
        $write = function (string $table, array $row) use (&$parts, &$counts, $work): void {
            if (!in_array($table, self::TABLES, true)) {
                return;
            }
            $parts[$table] ??= fopen($work . '/' . $table . '.ndjson', 'wb');
            fwrite($parts[$table], json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            $counts[$table] = ($counts[$table] ?? 0) + 1;
        };
        $f = fopen($json, 'rb');
        $first = (string) fgets($f);
        $header = json_decode(rtrim(rtrim($first), ',') . '}', true);
        $table = null;
        $streamed = is_array($header);
        while ($streamed && ($line = fgets($f)) !== false) {
            $line = rtrim($line);
            if (preg_match('/^"([a-z_]+)":\[$/D', $line, $m)) {
                $table = $m[1];
            } elseif (str_starts_with($line, '{') && $table !== null) {
                $row = json_decode(rtrim($line, ','), true);
                if (!is_array($row)) {
                    $streamed = false;
                    break;
                }
                $write($table, $row);
            } elseif (in_array($line, [']', '],', ']}', ''], true)) {
                $table = null;
            } else {
                $streamed = false;
            }
        }
        fclose($f);
        if (!$streamed) {
            foreach ($parts as $h) {
                fclose($h);
            }
            [$parts, $counts] = [[], []];
            if (filesize($json) > 64 * 1024 * 1024) {
                throw new \RuntimeException('The file is not a Kaleta export.');
            }
            $data = json_decode((string) file_get_contents($json), true);
            if (!is_array($data)) {
                throw new \RuntimeException('The file is not a Kaleta export.');
            }
            $header = array_diff_key($data, array_flip(self::TABLES));
            foreach (self::TABLES as $t) {
                foreach (is_array($data[$t] ?? null) ? $data[$t] : [] as $row) {
                    if (is_array($row)) {
                        $write($t, $row);
                    }
                }
            }
        }
        foreach ($parts as $h) {
            fclose($h);
        }
        if (is_array($header['nastaveni'] ?? null)) {
            file_put_contents($work . '/nastaveni.json', (string) json_encode($header['nastaveni'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return [is_array($header) ? $header : [], $counts];
    }

    /* ---------- 2. data ---------- */

    /**
     * One batch: the first one backs up the database and empties the content, then rows table by table. After the last
     * table the settings are applied and the media step follows.
     *
     * @param array<string, mixed> $state
     */
    public function importData(array &$state): void
    {
        if (!$state['vyprazdneno']) {
            if (!self::siteContent($this->db)['prazdny']) {
                throw new \RuntimeException('The site already has its own content. A Kaleta export can be imported only into a new, empty site.');
            }
            $state['zaloha'] = Backup::create($this->db, 'predimportem');
            $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
            foreach (self::EMPTIED as $table) {
                $this->db->run('DELETE FROM {' . $table . '}');
            }
            $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
            $state['vyprazdneno'] = true;
            // 3.9: an export where two language versions share a slug (/kontakt, /en/kontakt) needs slugs per language here
            // too, before the first row – otherwise the global unique key refuses the second one (Core\Slug)
            if (!Slug::perLanguage($this->db) && self::sharesSlugs(self::workFolder((string) $state['soubor']))) {
                $refusal = Slug::switchPerLanguage($this->db, $this->settings, true);
                $state['zmeny'] = [...(array) $state['zmeny'], $refusal === null
                    ? ['Two language versions in the export share addresses, so the same address in every language was switched on (Settings → General).']
                    : [$refusal[0], $refusal[1]]];
            }

            return;
        }
        $start = microtime(true);
        $done = 0;
        $this->exportHasNoticeLog = (int) ($state['pocty']['notice_log'] ?? 0) > 0;
        $this->work = self::workFolder((string) $state['soubor']);
        $this->renamedPages = [];
        foreach ((array) $state['prejmenovane_stranky'] as $from => $to) {
            if (is_string($to)) {
                $this->renamedPages[(string) $from] = $to;
            }
        }
        while ($state['tabulka'] < count(self::TABLES) && $done < self::BATCH && microtime(true) - $start < self::SECONDS) {
            $table = self::TABLES[$state['tabulka']];
            $file = self::workFolder((string) $state['soubor']) . '/' . $table . '.ndjson';
            if (!is_file($file) || $state['pozice'] >= (int) ($state['pocty'][$table] ?? 0)) {
                $state['tabulka']++;
                $state['pozice'] = 0;
                continue;
            }
            $f = new \SplFileObject($file, 'rb');
            $f->seek((int) $state['pozice']);
            $this->db->transaction(function () use ($f, $table, &$state, &$done, $start): void {
                while (!$f->eof() && $done < self::BATCH && microtime(true) - $start < self::SECONDS) {
                    $line = trim((string) $f->current());
                    $f->next();
                    $state['pozice']++;
                    $done++;
                    $row = $line === '' ? null : json_decode($line, true);
                    $ok = is_array($row) && $this->insert($table, $row);
                    $state['vysledek'][$table][$ok ? 'ok' : 'preskoceno'] = ($state['vysledek'][$table][$ok ? 'ok' : 'preskoceno'] ?? 0) + 1;
                }
            });
        }
        // what was changed so the imported site keeps working (3.7, N37-2 and N37-11) – listed with the result
        $state['zmeny'] = array_slice([...(array) $state['zmeny'], ...$this->notes, ...self::$limitNotes], 0, 200);
        $state['prejmenovane_stranky'] = $this->renamedPages;
        $this->notes = [];
        self::$limitNotes = [];
        if ($state['tabulka'] >= count(self::TABLES)) {
            $this->applySettings((string) $state['soubor'], (string) ($state['hlavicka']['kaleta'] ?? ''));
            $state['faze'] = $state['media_celkem'] > 0 ? 'media' : 'hotovo';
            if ($state['faze'] === 'hotovo') {
                $this->finish();
            }
        }
    }

    /** One row: cleaned by the table's rules, only columns this site has; false = skipped. */
    private function insert(string $table, array $r): bool
    {
        $r = $this->liftModals($table, $r);
        $clean = match ($table) {
            'kategorie' => $this->category($r),
            'stitky' => $this->tag($r),
            'stranky' => $this->page($r),
            'novinky' => $this->newsItem($r),
            'presmerovani' => $this->redirect($r),
            'tridy' => $this->sharedClass($r),
            'casti' => $this->sitePart($r),
            'komponenty' => $this->component($r),
            'sekce' => $this->section($r),
            'menu' => $this->menu($r),
            'kolekce' => $this->collection($r),
            'kolekce_sablony' => $this->collectionTemplate($r),
            'kolekce_polozky' => $this->collectionItem($r),
            'collection_categories' => $this->collectionCategory($r),
            'collection_category_texts' => $this->collectionCategoryText($r),
            'collection_category_templates' => (int) ($r['idk'] ?? 0) > 0 ? ['idk' => (int) $r['idk'], 'jazyk' => self::language($r['jazyk'] ?? ''), 'stavba' => self::build($r['stavba'] ?? null),
                'stavba_koncept' => self::build($r['stavba_koncept'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')] : null,
            'collection_item_categories' => $this->itemCategory($r),
            'document_versions' => self::documentVersion($r),
            'popupy' => $this->popup($r),
            'media_slozky' => (int) ($r['ids'] ?? 0) > 0 ? ['ids' => (int) $r['ids'], 'nazev' => mb_substr(trim(strip_tags((string) ($r['nazev'] ?? ''))), 0, 100)] : null,
            'media' => $this->mediaRow($r),
            'facts' => self::fact($r),
            'hours_exceptions' => self::hoursException($r),
            'booking_services' => self::bookingService($r),
            'booking_staff' => self::bookingStaff($r),
            'booking_staff_services' => (int) ($r['staff_id'] ?? 0) > 0 && (int) ($r['service_id'] ?? 0) > 0 ? ['staff_id' => (int) $r['staff_id'], 'service_id' => (int) $r['service_id']] : null,
            'booking_hours' => self::bookingHours($r),
            'booking_off' => self::bookingOff($r),
            'blueprints' => self::blueprint($r),
            'notice_log' => self::noticeLogRow($r),
            'notebook' => self::note($r),
        };
        if ($clean === null) {
            return false;
        }
        $tags = $clean['_stitky'] ?? [];
        unset($clean['_stitky']);
        $clean = array_intersect_key($clean, array_flip($this->columns($table)));
        try {
            $this->db->insert($table, $clean);
        } catch (\PDOException) {
            return false; // a duplicate number or address in the export
        }
        foreach ($tags as $ids) {
            $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$clean['idc'], $ids]);
        }
        if ($table === 'kolekce_polozky' && !$this->exportHasNoticeLog) {
            $this->logImportedNotice($clean);
        }

        return true;
    }

    /** An imported notice of an official notice board (2.11, Core\Notices) starts its audit trail with a 'created' row by "import". */
    private function logImportedNotice(array $item): void
    {
        $idk = (int) $item['idk'];
        if (!array_key_exists($idk, $this->noticeBoards)) {
            $collection = Collections::byId($this->db, $idk);
            $this->noticeBoards[$idk] = $collection !== null && Notices::isNotices($collection) ? $collection : null;
        }
        if ($this->noticeBoards[$idk] !== null) {
            Notices::log($this->db, (int) $item['idp'], 'created', Notices::changes($this->noticeBoards[$idk], null, $item), 'import');
        }
    }

    /** A 1.x build with the Modal element: the Modal becomes a site pop-up, the build links to it (as the 2.0 migration does). */
    private function liftModals(string $table, array $r): array
    {
        $text = fn (mixed $v): string => is_array($v) ? (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $v;
        if (!in_array($table, ['stranky', 'casti', 'kolekce', 'kolekce_sablony', 'komponenty', 'popupy'], true)
            || !str_contains($text($r['stavba'] ?? null) . $text($r['stavba_koncept'] ?? null), '"typ":"okno"')) {
            return $r;
        }
        $builds = [];
        foreach (['stavba', 'stavba_koncept'] as $column) {
            $v = $r[$column] ?? null;
            $builds[$column] = is_array($v) ? $v : (is_string($v) && $v !== '' ? (json_decode($v, true) ?: null) : null);
        }
        [$builds] = \Kaleta\Builder\ModalConversion::convertRow($this->db, $table, $r, $builds);

        return array_replace($r, $builds);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= array_column($this->db->all('SHOW COLUMNS FROM {' . $table . '}'), 'Field');
    }

    /* ---------- rows ---------- */

    /**
     * An address from the export when it is valid, otherwise one made from the name. \z, not $: "imp\n" must not pass
     * (a header PHP refuses on the subcategory redirect, a broken sitemap entry – 3.7, N37-10).
     */
    private static function slug(mixed $slug, string $fallback, int $max): string
    {
        $slug = is_string($slug) ? $slug : '';

        return preg_match('/^[a-z0-9][a-z0-9-]*\z/', $slug) && strlen($slug) <= $max ? $slug : slugify($fallback, $max);
    }

    /** Does a slug of a page, news item or category appear twice in the export (two language versions, 3.9)? Read without decoding the rows. */
    private static function sharesSlugs(string $folder): bool
    {
        foreach (array_keys(Slug::TABLES) as $table) {
            $seen = [];
            $file = $folder . '/' . $table . '.ndjson';
            foreach (is_file($file) ? new \SplFileObject($file, 'rb') : [] as $line) {
                if (is_string($line) && preg_match('/"seo_link"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $line, $m) === 1) {
                    if (isset($seen[$m[1]])) {
                        return true;
                    }
                    $seen[$m[1]] = true;
                }
            }
        }

        return false;
    }

    /** The address of a page: one segment, or a subpage's path under its parent (o-nas/tym); otherwise one made from the name. */
    private static function pageSlug(mixed $slug, string $fallback): string
    {
        $slug = is_string($slug) ? $slug : '';

        return preg_match('#^[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*\z#', $slug) && strlen($slug) <= 120 ? $slug : slugify($fallback, 110);
    }

    /**
     * A page slug the system uses (an English system path of 3.7 such as form or subscription, Pages::slugReserved) would
     * take over that address – the page gets a free one (form-2), its subpages move with it, and the result says so (N37-2).
     */
    private function freePageSlug(string $slug, string $language): string
    {
        $root = explode('/', $slug, 2)[0];
        if (isset($this->renamedPages[$root])) {
            return $this->renamedPages[$root] . substr($slug, strlen($root)); // a subpage of a page that got a new address
        }
        if (!Pages::slugReserved($slug, $this->db)) {
            return $slug;
        }
        if ($this->exportPageSlugs === null) {
            $this->exportPageSlugs = [];
            $file = $this->work . '/stranky.ndjson';
            foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && is_string($row['seo_link'] ?? null)) {
                    $this->exportPageSlugs[$row['seo_link']] = true;
                }
            }
        }
        $free = Slug::makeUnique($slug, fn (string $a): bool => Pages::slugReserved($a, $this->db) || isset($this->exportPageSlugs[$a])
            || Slug::taken($this->db, 'stranky', $a, $language), 120);
        $this->renamedPages[$slug] = $free;
        Pages::move($this->db, $slug, $free, false, $language); // subpages imported before it; the old address is the system's, so no redirect
        $this->notes[] = ['The address %s is used by the system, so the page got %s.', '/' . $slug, '/' . $free];

        return $free;
    }

    /** A value from a fixed list (types of pop-ups and the like), otherwise the first one. */
    private static function pick(array $list, mixed $value): string
    {
        return is_string($value) && isset($list[$value]) ? $value : (string) array_key_first($list);
    }

    private static function text(mixed $v, int $max): string
    {
        return mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
    }

    /**
     * The HTML of a page or news item from the export, sanitized the way a save without code rights is (Html::safe): the archive
     * may come from anywhere, so it never brings script into the site. Structure, classes, images and links stay; an embedded
     * YouTube or Vimeo player becomes its URL on its own line, which the site shows as a player again.
     */
    private static function html(mixed $v, int $max): string
    {
        return Html::safe(WpContent::embeddedVideos(self::text($v, $max)));
    }

    /** html(), or null when it is over a limit of HtmlLimits: the record is skipped, with the reason among the notes. */
    private function htmlOrSkip(mixed $v, int $max, string $title): ?string
    {
        try {
            return HtmlLimits::guard(fn (): string => self::html($v, $max));
        } catch (HtmlTooLarge $e) {
            $this->notes[] = ['“%s” was skipped: %s', $title, $e->localized()];

            return null;
        }
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/D', $v) ? $v : null;
    }

    /**
     * "True until" and "review by" (2.10) of pages, news, items and pop-ups.
     *
     * @return array{valid_until: ?string, review_by: ?string}
     */
    private static function validity(array $r): array
    {
        $day = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? $v : null;

        return ['valid_until' => $day($r['valid_until'] ?? null), 'review_by' => $day($r['review_by'] ?? null)];
    }

    /** A business fact (2.10); a value that does not fit its type is left out. @return array<string, mixed>|null */
    private static function fact(array $r): ?array
    {
        $key = (string) ($r['fact_key'] ?? '');
        $type = isset(Facts::TYPES[$r['type'] ?? '']) ? (string) $r['type'] : 'text';
        $value = Facts::clean($type, (string) ($r['value'] ?? ''));
        if (!preg_match(Facts::KEY_PATTERN, $key) || isset(Facts::BUILT_IN[$key]) || $value === null || ($type === 'text' && Facts::startsWithScheme($value))) {
            return null; // a text fact "javascript:…" is refused like in Facts::save (3.3.3, N50)
        }

        return ['fact_key' => $key, 'language' => self::language($r['language'] ?? ''), 'label' => self::text(strip_tags((string) ($r['label'] ?? $key)), 150), 'type' => $type, 'value' => $value,
            'schema_prop' => isset(Facts::SCHEMA_PROPS[$r['schema_prop'] ?? '']) ? (string) $r['schema_prop'] : '', 'source' => self::text(strip_tags((string) ($r['source'] ?? '')), 255),
            'updated_at' => date('Y-m-d H:i:s')];
    }

    /** An exception to the opening hours (2.10). @return array<string, mixed>|null */
    /** An applied industry blueprint (2.11): only a manifest that passes Core\Blueprint::sanitize. */
    private static function blueprint(array $r): ?array
    {
        [$manifest] = \Kaleta\Core\Blueprint::sanitize(is_array($r['manifest'] ?? null) ? $r['manifest'] : json_decode((string) ($r['manifest'] ?? ''), true));

        return $manifest === null ? null : ['bkey' => $manifest['key'], 'nazev' => \Kaleta\Core\Blueprint::text($manifest['name']), 'manifest' => (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'applied_at' => date('Y-m-d H:i:s')];
    }

    /** A note of the agent notebook (2.15, Core\Notebook) – the author and the dates stay as they were. @return array<string, mixed>|null */
    private static function note(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 150);
        $text = self::text(trim(strip_tags((string) ($r['text'] ?? ''))), Notebook::MAX_TEXT);
        if ($title === '' || $text === '') {
            return null;
        }
        $date = fn (mixed $v): string => is_string($v) && strtotime($v) !== false ? date('Y-m-d H:i:s', (int) strtotime($v)) : date('Y-m-d H:i:s');

        return ['id' => (int) ($r['id'] ?? 0) > 0 ? (int) $r['id'] : null, 'topic' => Notebook::topic($r['topic'] ?? '') ?? 'other', 'title' => $title, 'text' => $text, 'pinned' => !empty($r['pinned']) ? 1 : 0,
            'author' => self::text(trim(strip_tags((string) ($r['author'] ?? ''))), 100), 'created_at' => $date($r['created_at'] ?? null), 'updated_at' => $date($r['updated_at'] ?? null)];
    }

    /** @param array<string, mixed> $r */
    private static function bookingService(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 150);
        $duration = (int) ($r['duration_min'] ?? 0);
        if ((int) ($r['id'] ?? 0) <= 0 || trim($name) === '' || $duration < 5 || $duration > Booking::MAX_DURATION) {
            return null;
        }

        return ['id' => (int) $r['id'], 'name' => $name, 'duration_min' => $duration, 'buffer_min' => max(0, min(240, (int) ($r['buffer_min'] ?? 0))), 'price_text' => self::text(strip_tags((string) ($r['price_text'] ?? '')), 60),
            'description' => self::text(strip_tags((string) ($r['description'] ?? '')), 500), 'active' => !empty($r['active']) ? 1 : 0, 'requires_confirmation' => !empty($r['requires_confirmation']) ? 1 : 0, 'sort_order' => (int) ($r['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $r the account link (user_id) does not travel – the users of the new site are different */
    private static function bookingStaff(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 150);
        $email = trim((string) ($r['email'] ?? ''));
        if ((int) ($r['id'] ?? 0) <= 0 || trim($name) === '') {
            return null;
        }

        return ['id' => (int) $r['id'], 'name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 190) : '', 'active' => !empty($r['active']) ? 1 : 0, 'sort_order' => (int) ($r['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $r */
    private static function bookingHours(array $r): ?array
    {
        $ranges = Booking::parseHours([(int) ($r['weekday'] ?? 0) => (string) ($r['time_from'] ?? '') . '-' . (string) ($r['time_to'] ?? '')]);
        if ((int) ($r['staff_id'] ?? 0) <= 0 || $ranges === null || $ranges === []) {
            return null;
        }
        [$from, $to] = $ranges[(int) $r['weekday']][0];

        return ['staff_id' => (int) $r['staff_id'], 'service_id' => (int) ($r['service_id'] ?? 0) > 0 ? (int) $r['service_id'] : null, 'weekday' => (int) $r['weekday'], 'time_from' => $from, 'time_to' => $to];
    }

    /** @param array<string, mixed> $r */
    private static function bookingOff(array $r): ?array
    {
        $range = Booking::offRange(substr((string) ($r['off_from'] ?? ''), 0, 16), substr((string) ($r['off_to'] ?? ''), 0, 16));
        if ($range === null) {
            return null;
        }

        return ['staff_id' => (int) ($r['staff_id'] ?? 0) > 0 ? (int) $r['staff_id'] : null, 'off_from' => $range[0], 'off_to' => $range[1], 'note' => self::text(strip_tags((string) ($r['note'] ?? '')), 150)];
    }

    private static function hoursException(array $r): ?array
    {
        $from = (string) ($r['date_from'] ?? '');
        $to = (string) ($r['date_to'] ?? '');
        $closed = !empty($r['closed']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) || $to < $from
            || (!$closed && (Hours::parseRanges((string) ($r['hours'] ?? '')) ?? []) === [])) {
            return null;
        }

        return ['date_from' => $from, 'date_to' => $to, 'closed' => $closed ? 1 : 0, 'hours' => $closed ? '' : self::text((string) $r['hours'], 100),
            'note' => self::text(strip_tags((string) ($r['note'] ?? '')), 150), 'notice_days' => max(0, min(60, (int) ($r['notice_days'] ?? 7))), 'created_at' => date('Y-m-d H:i:s')];
    }

    /** A row of the notice log (2.11, Core\Notices) as exported – the trail is kept as it was. @return array<string, mixed>|null */
    private static function noticeLogRow(array $r): ?array
    {
        $at = (string) ($r['at'] ?? '');
        if ((int) ($r['id'] ?? 0) <= 0 || (int) ($r['idp'] ?? 0) <= 0 || !in_array($r['action'] ?? '', Notices::ACTIONS, true) || strtotime($at) === false) {
            return null;
        }
        $fields = is_array($r['fields'] ?? null) ? $r['fields'] : json_decode((string) ($r['fields'] ?? ''), true);

        return ['id' => (int) $r['id'], 'idp' => (int) $r['idp'], 'action' => (string) $r['action'], 'at' => date('Y-m-d H:i:s', (int) strtotime($at)),
            'by' => self::text(strip_tags((string) ($r['by'] ?? '')), 100), 'fields' => (string) json_encode(is_array($fields) ? $fields : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    private static function language(mixed $v): string
    {
        return Language::offeredOrDefault($v);
    }

    /** A path of an image or file: from media/, or an https:// address; anything else (javascript:…) is dropped. */
    private static function file(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('#^(/?media/[^\s"\'<>]+|https://[^\s"\'<>]+)$#D', $v) && !str_contains($v, '..') ? $v : '';
    }

    /** A builder build from the export: through the validator like any save; null for an empty or broken one. */
    private static function build(mixed $v): ?string
    {
        $build = is_array($v) ? $v : (is_string($v) && $v !== '' ? json_decode($v, true) : null);
        if (!is_array($build)) {
            return null;
        }
        [$clean, $errors] = Build::sanitize($build, true);
        foreach ($errors as $place => $error) {
            if (Build::isLimitNote($error)) {
                self::$limitNotes[] = ['A builder field (%s) was over a safety limit for HTML and was left empty.', $place];
            }
        }

        return Build::toJson($clean);
    }

    private function category(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['nazev'] ?? '')), 100);

        return (int) ($r['idt'] ?? 0) > 0 && $name !== '' ? ['idt' => (int) $r['idt'], 'nazev' => $name, 'seo_link' => self::slug($r['seo_link'] ?? '', $name, 120),
            'popis' => self::text($r['popis'] ?? '', 5000), 'hodnost' => (int) ($r['hodnost'] ?? 0), 'jazyk' => self::language($r['jazyk'] ?? ''),
            'preklad_z' => (int) ($r['preklad_z'] ?? 0) ?: null] : null;
    }

    private function tag(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['nazev'] ?? '')), 100);

        return (int) ($r['ids'] ?? 0) > 0 && $name !== '' ? ['ids' => (int) $r['ids'], 'nazev' => $name, 'seo_link' => self::slug($r['seo_link'] ?? '', $name, 120),
            'popis' => self::text($r['popis'] ?? '', 5000), 'obrazek' => self::file($r['obrazek'] ?? '')] : null;
    }

    private function page(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['titulek'] ?? ''))), 200);
        if ((int) ($r['ids'] ?? 0) <= 0 || $title === '') {
            return null;
        }
        $text = $this->htmlOrSkip($r['text'] ?? '', 4_000_000, $title);
        if ($text === null) {
            return null;
        }

        return ['ids' => (int) $r['ids'], 'titulek' => $title, 'seo_link' => $this->freePageSlug(self::pageSlug($r['seo_link'] ?? '', $title), self::language($r['jazyk'] ?? '')), 'popis' => self::text($r['popis'] ?? '', 300),
            'seo_titulek' => self::text($r['seo_titulek'] ?? '', 200), 'obrazek' => self::file($r['obrazek'] ?? ''), 'noindex' => (int) !empty($r['noindex']),
            'text' => $text, 'zobrazit' => (int) !empty($r['zobrazit']), 'zverejnit_od' => self::date($r['zverejnit_od'] ?? null),
            'v_menu' => (int) !empty($r['v_menu']), 'poradi' => (int) ($r['poradi'] ?? 0), 'zmeneno' => self::date($r['zmeneno'] ?? null) ?? date('Y-m-d H:i:s'),
            'jazyk' => self::language($r['jazyk'] ?? ''), 'preklad_z' => (int) ($r['preklad_z'] ?? 0) ?: null, 'nadrazena' => (int) ($r['nadrazena'] ?? 0) ?: null,
            'stavba' => self::build($r['stavba'] ?? null), 'stavba_koncept' => self::build($r['stavba_koncept'] ?? null)] + self::validity($r);
    }

    private function newsItem(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['titulek'] ?? ''))), 255);
        if ((int) ($r['idc'] ?? 0) <= 0 || $title === '') {
            return null;
        }
        $intro = $this->htmlOrSkip($r['uvod'] ?? '', 100_000, $title);
        $text = $intro === null ? null : $this->htmlOrSkip($r['text'] ?? '', 4_000_000, $title);
        if ($intro === null || $text === null) {
            return null;
        }
        $row = ['idc' => (int) $r['idc'], 'titulek' => $title, 'seo_link' => self::slug($r['seo_link'] ?? '', $title, 160), 'uvod' => $intro,
            'text' => $text, 'obrazek' => self::file($r['obrazek'] ?? ''), 'obrazek_popis' => self::text($r['obrazek_popis'] ?? '', 300),
            'obrazek_autor' => self::text($r['obrazek_autor'] ?? '', 120), 'tema' => (int) ($r['tema'] ?? 0), 'autor' => $this->admin,
            'datum' => self::date($r['datum'] ?? null) ?? date('Y-m-d H:i:s'), 'visible' => (int) !empty($r['visible']), 't_slova' => self::text($r['t_slova'] ?? '', 500),
            'seo_titulek' => self::text($r['seo_titulek'] ?? '', 255), 'seo_popis' => self::text($r['seo_popis'] ?? '', 320), 'noindex' => (int) !empty($r['noindex']),
            'visit' => (int) ($r['visit'] ?? 0), 'zmeneno' => self::date($r['zmeneno'] ?? null), 'aktualizovano' => self::date($r['aktualizovano'] ?? null),
            // already announced on the old site: the import sends no webhook and no IndexNow for the whole archive
            'oznameno' => date('Y-m-d H:i:s'), 'jazyk' => self::language($r['jazyk'] ?? ''), 'preklad_z' => (int) ($r['preklad_z'] ?? 0) ?: null, 'hledani' => null,
            '_stitky' => array_values(array_filter(array_map('intval', is_array($r['stitky'] ?? null) ? $r['stitky'] : []), fn (int $i): bool => $i > 0))] + self::validity($r);
        if (is_string($r['faq'] ?? null)) {
            $row['faq'] = self::text($r['faq'], 60_000);
        }

        return $row;
    }

    private function redirect(array $r): ?array
    {
        // addresses as the Redirects module stores them: the old one without the slashes around, the target a path or a URL
        $from = is_string($r['z_adresy'] ?? null) ? trim($r['z_adresy'], '/ ') : '';
        $to = is_string($r['na_adresu'] ?? null) ? trim($r['na_adresu']) : '';
        $code = (int) ($r['typ'] ?? 301);
        $code = in_array($code, [302, RedirectRules::GONE], true) ? $code : 301;
        if ($code === RedirectRules::GONE) {
            $to = ''; // 3.6: gone for good, no target
        }

        return (int) ($r['idp'] ?? 0) > 0 && preg_match('#^[^\s/][^\s]{0,254}$#D', $from) && ($code === RedirectRules::GONE || preg_match('#^(?!//)(?!javascript:)(?!data:)[^\s]{1,255}$#iD', $to))
            ? ['idp' => (int) $r['idp'], 'z_adresy' => $from, 'na_adresu' => $to, 'typ' => $code, 'pocet' => 0, 'vytvoreno' => date('Y-m-d H:i:s'),
                'auto_score' => is_numeric($r['auto_score'] ?? null) ? max(0, min(100, (int) $r['auto_score'])) : null]
            : null;
    }

    private function sharedClass(array $r): ?array
    {
        $name = (string) ($r['nazev'] ?? '');
        if (!preg_match(Build::CLASS_PATTERN, $name)) {
            return null;
        }
        $errors = [];
        $discarded = [];
        $style = Style::sanitize(is_array($r['styl'] ?? null) ? $r['styl'] : json_decode((string) ($r['styl'] ?? ''), true), $name, $errors);

        return ['nazev' => $name, 'styl' => (string) json_encode($style ?: new \stdClass(), JSON_UNESCAPED_UNICODE), 'css' => Style::customCss((string) ($r['css'] ?? ''), $discarded), 'zmeneno' => date('Y-m-d H:i:s')];
    }

    private function sitePart(array $r): ?array
    {
        $type = (string) ($r['typ'] ?? '');
        $variant = (string) ($r['varianta'] ?? '');
        if (!isset(SiteParts::TYPES[$type]) || ($variant !== '' && !preg_match(SiteParts::VARIANT_PATTERN, $variant))) {
            return null;
        }
        $pages = is_array($r['stranky'] ?? null) ? $r['stranky'] : json_decode((string) ($r['stranky'] ?? ''), true);
        $rules = SiteParts::sanitizeRules(is_array($r['pravidla'] ?? null) ? $r['pravidla'] : json_decode((string) ($r['pravidla'] ?? ''), true));

        return ['typ' => $type, 'jazyk' => self::language($r['jazyk'] ?? ''), 'varianta' => $variant, 'nazev' => self::text(strip_tags((string) ($r['nazev'] ?? '')), 100),
            'stranky' => is_array($pages) ? (string) json_encode(array_values(array_filter(array_map('intval', $pages), fn (int $i): bool => $i > 0))) : null,
            'pravidla' => SiteParts::hasRules($rules) ? (string) json_encode($rules, JSON_UNESCAPED_UNICODE) : null, // 3.6
            'stavba' => self::build($r['stavba'] ?? null), 'stavba_koncept' => self::build($r['stavba_koncept'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')];
    }

    private function component(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['nazev'] ?? ''))), 100);
        $properties = is_array($r['vlastnosti'] ?? null) ? $r['vlastnosti'] : json_decode((string) ($r['vlastnosti'] ?? ''), true);

        return (int) ($r['idm'] ?? 0) > 0 && $name !== '' ? ['idm' => (int) $r['idm'], 'nazev' => $name,
            'vlastnosti' => (string) json_encode(Components::sanitizeProperties($properties), JSON_UNESCAPED_UNICODE),
            'stavba' => self::build($r['stavba'] ?? null), 'stavba_koncept' => self::build($r['stavba_koncept'] ?? null), 'kit_key' => self::kitKey($r['kit_key'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')] : null;
    }

    /** The key a component or section got from a fleet design kit (2.16, Fleet\Kit) – kept, so the next kit updates it instead of adding a copy. */
    private static function kitKey(mixed $v): ?string
    {
        return is_string($v) && preg_match(\Kaleta\Fleet\Kit::KEY_PATTERN, $v) ? $v : null;
    }

    private function section(array $r): ?array
    {
        $element = is_array($r['prvek'] ?? null) ? $r['prvek'] : json_decode((string) ($r['prvek'] ?? ''), true);
        $build = is_array($element) ? json_decode((string) self::build(['v' => Build::VERSION, 'deti' => [$element]]), true) : null;
        $name = self::text(trim(strip_tags((string) ($r['nazev'] ?? ''))), 100);

        return (int) ($r['idx'] ?? 0) > 0 && $name !== '' && isset($build['deti'][0])
            ? ['idx' => (int) $r['idx'], 'nazev' => $name, 'prvek' => (string) json_encode($build['deti'][0], JSON_UNESCAPED_UNICODE), 'kit_key' => self::kitKey($r['kit_key'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')] : null;
    }

    private function menu(array $r): ?array
    {
        $location = (string) ($r['umisteni'] ?? '');
        $items = is_array($r['polozky'] ?? null) ? $r['polozky'] : json_decode((string) ($r['polozky'] ?? ''), true);

        return isset(Menu::LOCATIONS[$location]) ? ['umisteni' => $location, 'jazyk' => self::language($r['jazyk'] ?? ''),
            'polozky' => (string) json_encode(Menu::sanitize($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'zmeneno' => date('Y-m-d H:i:s')] : null;
    }

    private function collection(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['nazev'] ?? ''))), 100);
        $fields = is_array($r['pole'] ?? null) ? $r['pole'] : json_decode((string) ($r['pole'] ?? ''), true);

        return (int) ($r['idk'] ?? 0) > 0 && $name !== '' ? ['idk' => (int) $r['idk'], 'nazev' => $name, 'seo_link' => self::slug($r['seo_link'] ?? '', $name, 110),
            'pole' => (string) json_encode(Collections::sanitizeFields($fields), JSON_UNESCAPED_UNICODE), 'detail' => (int) !empty($r['detail']),
            'hidden_redirect' => Collections::cleanRedirect((string) ($r['hidden_redirect'] ?? '')) ?? '',
            'preset' => \Kaleta\Builder\Presets::get((string) ($r['preset'] ?? '')) !== null ? (string) $r['preset'] : '',
            'schema_org' => ($schema = \Kaleta\Builder\CollectionSchema::sanitize(is_array($r['schema_org'] ?? null) ? $r['schema_org'] : json_decode((string) ($r['schema_org'] ?? ''), true), Collections::sanitizeFields($fields))) === null
                ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE),
            'stavba' => self::build($r['stavba'] ?? null), 'stavba_koncept' => self::build($r['stavba_koncept'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')] : null;
    }

    private function collectionTemplate(array $r): ?array
    {
        return (int) ($r['idk'] ?? 0) > 0 ? ['idk' => (int) $r['idk'], 'jazyk' => self::language($r['jazyk'] ?? ''), 'stavba' => self::build($r['stavba'] ?? null),
            'stavba_koncept' => self::build($r['stavba_koncept'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')] : null;
    }

    private function collectionItem(array $r): ?array
    {
        $idk = (int) ($r['idk'] ?? 0);
        $fields = $idk > 0 ? json_decode((string) $this->db->value('SELECT pole FROM {kolekce} WHERE idk = ?', [$idk]), true) : null;
        $name = self::text(trim(strip_tags((string) ($r['nazev'] ?? ''))), 200);
        if ((int) ($r['idp'] ?? 0) <= 0 || !is_array($fields) || $name === '') {
            return null; // an item without its collection
        }
        $data = is_array($r['data'] ?? null) ? $r['data'] : json_decode((string) ($r['data'] ?? ''), true);
        $errors = [];
        $tooLarge = null;
        $clean = Collections::sanitizeData($fields, is_array($data) ? $data : [], $errors, $tooLarge);
        if ($tooLarge !== null) {
            $this->notes[] = ['“%s” was skipped: %s', $name, HtmlLimits::message($tooLarge)];

            return null;
        }

        return ['idp' => (int) $r['idp'], 'idk' => $idk, 'nazev' => $name, 'seo_link' => self::slug($r['seo_link'] ?? '', $name, 160),
            'data' => (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'poradi' => (int) ($r['poradi'] ?? 0), 'zobrazit' => (int) !empty($r['zobrazit']), 'jazyk' => self::language($r['jazyk'] ?? ''),
            'datum' => self::date($r['datum'] ?? null) ?? date('Y-m-d H:i:s'), 'zmeneno' => date('Y-m-d H:i:s'),
            'seo_titulek' => self::text($r['seo_titulek'] ?? '', 200), 'popis' => self::text($r['popis'] ?? '', 300), 'obrazek' => self::file($r['obrazek'] ?? ''),
            'noindex' => (int) !empty($r['noindex']), 'zverejnit_od' => self::date($r['zverejnit_od'] ?? null)] + self::validity($r);
    }

    /**
     * A collection category (3.7) by the tree rules of CollectionCategories::save (N37-11): its parent is a top-level
     * category of the same collection imported before it (the export writes the top level first), never the category
     * itself – two levels at most. A row that breaks them keeps its content as a top-level category, and the result says
     * so. One whose collection was not imported fails on the foreign key and is skipped.
     */
    private function collectionCategory(array $r): ?array
    {
        $id = (int) ($r['id'] ?? 0);
        $idk = (int) ($r['idk'] ?? 0);
        $parent = (int) ($r['parent_id'] ?? 0);
        if ($id <= 0 || $idk <= 0) {
            return null;
        }
        if ($parent > 0) {
            $row = $parent === $id ? null : $this->db->one('SELECT idk, parent_id FROM {collection_categories} WHERE id = ?', [$parent]);
            if ($row === null || (int) $row['idk'] !== $idk || $row['parent_id'] !== null) {
                $parent = 0;
                $this->notes[] = ['Collection category %s: its parent is not a top-level category of the same collection, so it became a top-level category.', '#' . $id];
            }
        }

        return ['id' => $id, 'idk' => $idk, 'parent_id' => $parent > 0 ? $parent : null,
            'image' => self::file($r['image'] ?? ''), 'sort_order' => max(-9999, min(9999, (int) ($r['sort_order'] ?? 100))), 'visible' => (int) !empty($r['visible']), 'updated_at' => date('Y-m-d H:i:s')];
    }

    /**
     * The texts of a collection category in one language (3.7), by the rules of CollectionCategories::save (N37-11): a
     * language Kaleta knows, the category of the same collection, the description through the HTML allow-list, and an
     * address that is valid, not reserved and no item's of the collection (the items are imported first) – such an address
     * gets a number, and the result says so.
     */
    private function collectionCategoryText(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 200);
        $id = (int) ($r['category_id'] ?? 0);
        $idk = (int) ($r['idk'] ?? 0);
        $language = is_string($r['language'] ?? null) ? $r['language'] : '';
        if ($id <= 0 || $idk <= 0 || $name === '' || ($language !== '' && !isset(Language::AVAILABLE[$language]))
            || (int) $this->db->value('SELECT idk FROM {collection_categories} WHERE id = ?', [$id]) !== $idk) {
            return null;
        }
        $wanted = self::slug($r['slug'] ?? '', $name, 160);
        $other = fn (string $a): bool => $this->db->value('SELECT 1 FROM {collection_category_texts} WHERE idk = ? AND language = ? AND slug = ?', [$idk, $language, $a]) !== null;
        [$sameLanguage, $languageParams] = Slug::scope($this->db, $language); // 3.9: an item of another language version only without slugs per language
        $unusable = fn (string $a): bool => preg_match(CollectionCategories::SLUG_PATTERN, $a) !== 1 || in_array($a, CollectionCategories::RESERVED_SLUGS, true)
            || $this->db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ?' . $sameLanguage . ' LIMIT 1', [$idk, $a, ...$languageParams]) !== null;
        $slug = $wanted;
        if ($unusable($wanted)) {
            $slug = Slug::makeUnique($wanted, fn (string $a): bool => $unusable($a) || $other($a), 160);
            $this->notes[] = ['Collection category “%s”: the address %s cannot be used (an item of the collection has it, or it is reserved), so the category got %s.', $name, $wanted, $slug];
        }
        try {
            $description = HtmlLimits::guard(fn (): string => WpContent::safeHtml(self::text($r['description'] ?? '', 100000)));
        } catch (HtmlTooLarge $e) {
            $description = ''; // the category itself is kept: its items and addresses depend on it
            $this->notes[] = ['Collection category “%s”: the description was left empty: %s', $name, $e->localized()];
        }

        return ['category_id' => $id, 'language' => $language, 'idk' => $idk, 'name' => $name, 'slug' => $slug, 'description' => $description,
            'seo_title' => self::text(trim(strip_tags((string) ($r['seo_title'] ?? ''))), 200), 'seo_description' => self::text(trim(strip_tags((string) ($r['seo_description'] ?? ''))), 300)];
    }

    /** An item in a category (3.7): both of the same collection, as CollectionCategories::assign keeps it. @return array{idp: int, category_id: int}|null */
    private function itemCategory(array $r): ?array
    {
        $idp = (int) ($r['idp'] ?? 0);
        $category = (int) ($r['category_id'] ?? 0);
        $same = $idp > 0 && $category > 0
            && $this->db->value('SELECT 1 FROM {collection_categories} c JOIN {kolekce_polozky} p ON p.idk = c.idk WHERE c.id = ? AND p.idp = ?', [$category, $idp]) !== null;

        return $same ? ['idp' => $idp, 'category_id' => $category] : null;
    }

    /** A previous file of a document (2.11); a row whose document was not imported fails on the foreign key and is skipped. */
    private static function documentVersion(array $r): ?array
    {
        $file = is_string($r['file'] ?? null) ? trim($r['file']) : '';
        if ((int) ($r['idp'] ?? 0) <= 0 || preg_match(Collections::MEDIA_PATTERN, $file) !== 1 || str_contains($file, '..')) {
            return null;
        }

        return ['idp' => (int) $r['idp'], 'file' => $file, 'version' => self::text(strip_tags((string) ($r['version'] ?? '')), 100),
            'replaced_at' => self::date($r['replaced_at'] ?? null) ?? date('Y-m-d H:i:s'), 'replaced_by' => self::text(strip_tags((string) ($r['replaced_by'] ?? '')), 100)];
    }

    private function popup(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['nazev'] ?? ''))), 100);
        $address = (string) ($r['adresa'] ?? '');
        if ((int) ($r['idpp'] ?? 0) <= 0 || $name === '' || !preg_match(Popups::ADDRESS_PATTERN, $address)) {
            return null;
        }
        $rules = is_array($r['pravidla'] ?? null) ? $r['pravidla'] : json_decode((string) ($r['pravidla'] ?? ''), true);

        return ['idpp' => (int) $r['idpp'], 'nazev' => $name, 'adresa' => $address,
            'typ' => self::pick(Popups::TYPES, $r['typ'] ?? ''), 'spoustec' => self::pick(Popups::TRIGGERS, $r['spoustec'] ?? ''),
            'hodnota' => max(0, min(100_000, (int) ($r['hodnota'] ?? 0))), 'pravidla' => (string) json_encode(Popups::sanitizeRules(is_array($rules) ? $rules : []), JSON_UNESCAPED_UNICODE),
            'cetnost' => self::pick(Popups::FREQUENCIES, $r['cetnost'] ?? ''),
            'dni' => max(0, min(3650, (int) ($r['dni'] ?? 0))), 'aktivni' => (int) !empty($r['aktivni']), 'poradi' => (int) ($r['poradi'] ?? 0),
            'stavba' => self::build($r['stavba'] ?? null), 'stavba_koncept' => self::build($r['stavba_koncept'] ?? null), 'zmeneno' => date('Y-m-d H:i:s')] + self::validity($r);
    }

    private function mediaRow(array $r): ?array
    {
        // format 1 named the columns differently (soubor, sirka, vyska, nahled)
        $file = self::file($r['obr_poloha'] ?? ($r['soubor'] ?? ''));
        if ((int) ($r['ido'] ?? 0) <= 0 || !str_starts_with(ltrim($file, '/'), 'media/')) {
            return null;
        }
        $folder = (int) ($r['sekce'] ?? 0);

        return ['ido' => (int) $r['ido'], 'vlastnik' => $this->admin,
            'sekce' => $folder > 0 && $this->db->value('SELECT 1 FROM {media_slozky} WHERE ids = ?', [$folder]) !== null ? $folder : null,
            'nazev' => self::text($r['nazev'] ?? '', 150), 'popis' => self::text($r['popis'] ?? '', 500), 'autor' => self::text($r['autor'] ?? '', 120),
            'obr_poloha' => ltrim($file, '/'), 'obr_width' => max(0, min(65535, (int) ($r['obr_width'] ?? ($r['sirka'] ?? 0)))),
            'obr_height' => max(0, min(65535, (int) ($r['obr_height'] ?? ($r['vyska'] ?? 0)))), 'obr_vel' => max(0, (int) ($r['obr_vel'] ?? 0)),
            'nahl_poloha' => ltrim(self::file($r['nahl_poloha'] ?? ($r['nahled'] ?? '')), '/'), 'nahl_width' => max(0, min(65535, (int) ($r['nahl_width'] ?? 0))),
            'nahl_height' => max(0, min(65535, (int) ($r['nahl_height'] ?? 0))), 'barva' => is_string($r['barva'] ?? null) && preg_match('/^(#[0-9a-f]{6}|-)?$/iD', $r['barva']) ? $r['barva'] : '',
            'ohnisko' => is_string($r['ohnisko'] ?? null) && preg_match('/^(\d{1,3}% \d{1,3}%)?$/D', $r['ohnisko']) ? $r['ohnisko'] : '', 'datum' => self::date($r['datum'] ?? null) ?? date('Y-m-d H:i:s')];
    }

    /** The public settings of the export (the same allowlist the export uses); the address of this site stays. */
    private function applySettings(string $file, string $fromVersion): void
    {
        $values = json_decode((string) @file_get_contents(self::workFolder($file) . '/nastaveni.json'), true);
        foreach (is_array($values) ? $values : [] as $key => $value) {
            $key = OldSettingsKeys::current((string) $key); // an export of 1.4.0 and older has the old keys
            if (!is_scalar($value) || $key === 'site_url' || (!in_array($key, SiteExport::SETTINGS, true) && Language::settingKey($key, Settings::PER_LANGUAGE) === null)) {
                continue;
            }
            $value = (string) $value;
            $value = match ($key) {
                'design_system' => (string) json_encode(DesignSystem::sanitize(json_decode($value, true) ?: []), JSON_UNESCAPED_SLASHES),
                'logo', 'favicon', 'share_image' => self::file($value),
                'home_page', 'news_per_page' => (string) max(0, (int) $value),
                'news_slug' => Routes::systemSlugError($value) === null ? $value : null,
                'news_slug_previous' => Routes::rememberSlug($value, '', ''), // only valid slugs, at most ten
                'time_zone' => in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null,
                'site_language' => isset(Language::AVAILABLE[$value]) ? $value : null,
                'additional_languages' => implode(',', array_filter(explode(',', $value), fn (string $c): bool => isset(Language::AVAILABLE[$c]))),
                'extensions' => $value === '-' ? '-' : implode(',', array_intersect(explode(',', $value), array_keys(Extensions::CATALOG))),
                // a field of the admin form is validated like the form and MCP do (3.3.3, N55): company_map or social_*
                // "javascript:…" from a crafted archive is dropped and the setting keeps its value
                default => \Kaleta\Admin\Modules\Settings::checkable($key) ? \Kaleta\Admin\Modules\Settings::verifyValue($key, $value) : mb_substr($value, 0, 20_000),
            };
            if ($value !== null) {
                $this->settings->set($key, $value);
            }
        }
        $this->settings->set('look_draft', '');
        // an export from before 3.2 knew Bookings as part of the core: a site that brings its booking set-up keeps the
        // feature switched on (as migration 0073 does for an updated site)
        if (version_compare($fromVersion, '3.2.0', '<') && $this->db->value('SELECT 1 FROM {booking_services} LIMIT 1') !== null) {
            Extensions::save($this->settings, array_values(array_unique([...Extensions::enabled($this->settings), 'bookings'])));
        }
    }

    /* ---------- 3. media ---------- */

    /**
     * Files from the archive into media/, in batches. Only images and the attachment types Media accepts, only inside
     * media/, never PHP or hidden files; an SVG is cleaned like an uploaded one.
     *
     * @param array<string, mixed> $state
     */
    public function importMedia(array &$state): void
    {
        $zip = new \ZipArchive();
        $path = self::path((string) $state['soubor']);
        if ($path === null || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The file does not exist.');
        }
        $start = microtime(true);
        $done = 0;
        $total = $zip->numFiles;
        while ($state['media_pozice'] < $total && $done < self::MEDIA_BATCH && microtime(true) - $start < self::SECONDS) {
            $name = (string) $zip->getNameIndex((int) $state['media_pozice']);
            $state['media_pozice']++;
            $target = self::mediaTarget($name);
            if ($target === null) {
                continue;
            }
            $done++;
            $full = KALETA_ROOT . '/' . $target;
            $ok = is_dir(dirname($full)) || @mkdir(dirname($full), 0775, true);
            $reason = '';
            if ($ok && str_ends_with(strtolower($target), '.svg')) {
                try {
                    $content = Svg::sanitize((string) $zip->getFromName($name)); // an SVG is cleaned like an uploaded one
                } catch (HtmlTooLarge $e) {
                    $content = null; // over a limit of HtmlLimits: skipped with the reason
                    $reason = ' – ' . $e->localized();
                }
                $ok = $content !== null && file_put_contents($full, $content) !== false;
            } elseif ($ok) {
                // streamed: a video or a large PDF does not have to fit in memory
                $in = $zip->getStream($name);
                $out = $in === false ? false : @fopen($full, 'wb');
                $ok = $in !== false && $out !== false && stream_copy_to_stream($in, $out) !== false;
                foreach ([$in, $out] as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            }
            if (!$ok) {
                $state['media']['preskoceno']++;
                if (count($state['chyby']) < 20) {
                    $state['chyby'][] = $target . $reason;
                }
                continue;
            }
            $state['media']['ulozeno']++;
        }
        $zip->close();
        if ($state['media_pozice'] >= $total) {
            $state['faze'] = 'hotovo';
            $this->finish();
        }
    }

    /** Where a file from the archive goes; null = it does not belong in media/ or its type is not allowed. */
    public static function mediaTarget(string $name): ?string
    {
        if (!preg_match('#^media/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_][A-Za-z0-9_.-]*$#D', $name) || str_contains($name, '..')) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, [...self::MEDIA_EXTENSIONS, ...Files::FILE_EXTENSIONS], true) ? $name : null;
    }

    /** After the import: the search index, the cache and the working files. */
    private function finish(): void
    {
        for ($i = 0; $i < 500 && Search::complete($this->db, 200) > 0; $i++) {
            // the search index of the imported news, 200 at a time
        }
        \Kaleta\Front\Cache::clear();
    }

    /** Removes the working folder of a finished import (the export file itself stays). */
    public static function cleanUp(string $file): void
    {
        foreach (glob(self::workFolder($file) . '/*') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir(self::workFolder($file));
    }
}
