<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Images;
use Kaleta\Core\Response;

/**
 * Media: uploading, also by drag and drop and directly from the editor, folders,
 * labels, deleting and an overview of where an image is used (news, builds, collections, logo…).
 * An image is inserted into the text from the editor.
 */
final class Media extends Module
{
    public const string IDENT = 'media';
    public const string NAME = 'Media';
    public const string GROUP = 'Content';
    public const string ICON = 'media';

    /** Everyone who writes news must be able to upload; but only the administrator changes and deletes other people's images. */
    public const bool FOR_ALL_USERS = true;

    private const int PER_PAGE = 40;

    protected function actionList(): Response
    {
        $pageNumber = max(1, $this->request->getInt('page', 1));
        [$where, $params, $filter] = $this->filter();
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {media} o WHERE {$where}", $params);

        return $this->view('list', 'Media', [
            'images' => $this->load($where, $params, $pageNumber, self::PER_PAGE),
            'pageNumber' => $pageNumber,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'limit' => \Kaleta\Core\Files::limitText(),
            'filter' => $filter,
            'folders' => $this->folders(),
            'newsItem' => $filter['article'] > 0 ? $this->db->value('SELECT titulek FROM {novinky} WHERE idc = ?', [$filter['article']]) : null,
        ]);
    }

    /** JSON list for the image picker dialog in the editor; the same filters as in the list. */
    protected function actionListing(): Response
    {
        [$where, $params] = $this->filter();

        return Response::json([
            'obrazky' => array_map($this->toJson(...), $this->load($where, $params, max(1, $this->request->getInt('page', 1)), 60)),
            'slozky' => array_map(fn (array $s): array => ['id' => (int) $s['ids'], 'nazev' => $s['nazev']], $this->folders()),
        ]);
    }

    /** Creating or renaming a folder. */
    protected function actionFolder(): Response
    {
        $name = mb_substr($this->request->post('nazev'), 0, 100);
        if (!$this->request->isPost() || $name === '') {
            return $this->back();
        }
        $ids = $this->request->postInt('ids');
        if ($ids > 0) {
            $this->db->update('media_slozky', ['nazev' => $name], ['ids' => $ids]);
        } else {
            $ids = $this->db->insert('media_slozky', ['nazev' => $name]);
        }

        return $this->back('Folder saved.', '', ['section' => $ids]);
    }

    /** Deleting a folder; the images stay and move to the unsorted ones. */
    protected function actionFolderDelete(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->db->delete('media_slozky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->back('Folder deleted, its images are now uncategorized.');
    }

    /**
     * Recounts which images a news item uses: the main image, images inserted by the editor
     * (data-id, file URL). Called when a news item is saved.
     */
    public static function recordUsage(\Kaleta\Core\Db $db, int $idc, string ...$html): void
    {
        $all = implode(' ', $html);
        preg_match_all('/data-id="(\d+)"/', $all, $m);
        $ids = array_map(intval(...), $m[1]);
        preg_match_all('#media/\d{4}/\d{2}/[A-Za-z0-9._-]+\.[a-z0-9]{2,5}#', $all, $paths); // images and attachments (PDF, documents…)
        foreach (array_unique($paths[0]) as $path) {
            $ido = $db->value('SELECT ido FROM {media} WHERE obr_poloha = ? OR nahl_poloha = ?', [$path, $path]);
            if ($ido !== null) {
                $ids[] = (int) $ido;
            }
        }
        $db->delete('media_pouziti', ['idc' => $idc]);
        foreach (array_unique($ids) as $ido) {
            $db->run('INSERT IGNORE INTO {media_pouziti} (ido, idc) SELECT ido, ? FROM {media} WHERE ido = ?', [$idc, $ido]);
        }
    }

    /**
     * Media used outside the news usage table: builds of pages, site parts, collection item templates and components (drafts
     * too), text pages, collection items, classes (background image) and settings (logo, icon, sharing image).
     * Computed when shown – so the overview is always up to date without tracking on every save.
     *
     * @return array<int, list<string>> ido => descriptions of the places
     */
    public static function findUsagesElsewhere(\Kaleta\Core\Db $db): array
    {
        $sources = [
            [t('page'), 'SELECT titulek AS kde, CONCAT_WS(\' \', text, stavba, stavba_koncept, obrazek) AS obsah FROM {stranky}'],
            [t('tag'), 'SELECT nazev AS kde, CONCAT_WS(\' \', popis, obrazek) AS obsah FROM {stitky}'],
            [t('category'), 'SELECT nazev AS kde, popis AS obsah FROM {kategorie}'],
            [t('my section'), 'SELECT nazev AS kde, prvek AS obsah FROM {sekce}'],
            [t('user'), 'SELECT user AS kde, foto AS obsah FROM {uzivatele}'],
            [t('site part'), 'SELECT CONCAT(typ, IF(nazev = \'\', \'\', CONCAT(\' – \', nazev))) AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {casti}'],
            [t('collection'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {kolekce}'],
            [t('collection item'), "SELECT nazev AS kde, CONCAT(data, ' ', obrazek) AS obsah FROM {kolekce_polozky}"],
            [t('component'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {komponenty}'],
            [t('class'), 'SELECT nazev AS kde, CONCAT_WS(\' \', styl, css) AS obsah FROM {tridy}'],
            [t('settings'), 'SELECT promenna AS kde, hodnota AS obsah FROM {nastaveni} WHERE hodnota LIKE \'%media%\''],
            // 2.14: pop-up builds and newsletters (the rendered e-mail is kept from the start of sending) point at media too
            [t('pop-up'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {popupy}'],
            [t('newsletter'), 'SELECT subject AS kde, CONCAT_WS(\' \', intro, button_url, html) AS obsah FROM {newsletters}'],
        ];
        $usages = [];
        foreach ($sources as [$kind, $sql]) {
            foreach ($db->all($sql) as $r) {
                // paths also in JSON (media\/2026\/…), with and without the site URL; a variant (-1200, .webp) counts as the original
                foreach (\Kaleta\Core\MediaHygiene::paths((string) $r['obsah']) as $path) {
                    $usages[$path][$kind . ' ' . $r['kde']] = true;
                }
            }
        }
        if ($usages === []) {
            return [];
        }
        $used = [];
        foreach ($db->all('SELECT ido, obr_poloha, nahl_poloha FROM {media}') as $o) {
            foreach ([$o['obr_poloha'], $o['nahl_poloha']] as $path) {
                if ($path !== '' && isset($usages[$path])) {
                    $used[(int) $o['ido']] = array_keys(($used[(int) $o['ido']] ?? []) + $usages[$path]);
                }
            }
        }

        return $used;
    }

    /** Upload of one or more files; with the parameter format=json it answers the editor with JSON. */
    protected function actionUpload(): Response
    {
        $json = $this->request->get('format') === 'json';
        $section = $this->db->value('SELECT ids FROM {media_slozky} WHERE ids = ?', [$this->request->postInt('sekce')]);
        $section = $section === null ? null : (int) $section;
        $uploaded = [];
        $errors = [];
        $imageCount = 0;
        foreach (self::uploadedFiles() as $file) {
            try {
                $data = self::store($this->app, $file, $section);
                $imageCount += $data['nahl_poloha'] !== '' ? 1 : 0;
                $uploaded[] = $this->toJson($data + ['popis' => '']);
            } catch (\RuntimeException $e) {
                $errors[] = ($file['name'] ?? t('file')) . ': ' . t($e->getMessage());
            }
        }
        if ($uploaded === [] && $errors === []) {
            $errors[] = t('No file was selected.');
        }
        if ($json) {
            return Response::json(['obrazky' => $uploaded, 'chyby' => $errors], $uploaded === [] ? 400 : 200);
        }
        foreach ($errors as $error) {
            $this->app->session->flash('chyba', $error);
        }

        $message = $uploaded === [] ? '' : t('Files uploaded: %d.', count($uploaded)) . ($imageCount > 0 ? ' ' . t('Add a description for blind visitors (alt text) to the images: what the image shows.') : '');

        return $this->back($message, '', $section !== null ? ['section' => $section] : []);
    }

    /**
     * One uploaded file into Media – the same path for every upload in the administration (the Media screen, the editor,
     * the attachments of a request to Claude, 2.15): an image resized with variants, an SVG cleaned, an attachment (PDF,
     * documents…) as it is. Returns the media row with its new id.
     *
     * @param array<string, mixed> $file item of $_FILES (uploadedFiles)
     * @return array<string, mixed>
     * @throws \RuntimeException with the reason (an admin text to translate with t())
     */
    public static function store(\Kaleta\Core\App $app, array $file, ?int $section = null): array
    {
        $attachment = \Kaleta\Core\Files::isAttachment((string) ($file['name'] ?? ''));
        $data = match (true) {
            strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'svg' => self::saveSvg($file),
            $attachment => \Kaleta\Core\Files::save($file),
            default => Images::save($file),
        };
        if (!$attachment) {
            // the image name is also the description for the blind (alt): a file name ("IMG 2041", "foto dilna") does not describe the image
            // and the checks would take it as filled in – it stays empty and the list and the pre-publish check ask for it
            $data['nazev'] = '';
        }
        $data['ido'] = $app->db()->insert('media', $data + ['vlastnik' => $app->auth()->id(), 'sekce' => $section, 'datum' => date('Y-m-d H:i:s')]);

        return $data;
    }

    /**
     * SVG (logo, icon): sanitized to allowed tags and attributes; without a thumbnail and variants, the browser scales it itself.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private static function saveSvg(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new \RuntimeException('The SVG file could not be read (max. 2 MB, valid SVG).');
        }
        try {
            return self::saveSvgContent((string) file_get_contents($tmp), (string) ($file['name'] ?? 'obrazek'));
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            throw new \RuntimeException(t('The SVG file was refused: %s', $e->localized()), 0, $e); // already in the admin's language
        }
    }

    /**
     * SVG from text (upload and MCP): cleaned of scripts and outbound links (Core\Svg) and saved under a new name.
     *
     * @return array<string, mixed> row for the media table
     * @throws \Kaleta\Core\HtmlTooLarge when the SVG is over a limit of Core\HtmlLimits (the file is refused)
     */
    public static function saveSvgContent(string $content, string $displayName): array
    {
        $svg = strlen($content) < 2_000_000 ? \Kaleta\Core\Svg::sanitize($content) : null;
        if ($svg === null) {
            throw new \RuntimeException('The SVG file could not be read (max. 2 MB, valid SVG).');
        }
        $folder = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $folder)) {
            mkdir(KALETA_ROOT . '/' . $folder, 0775, true);
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $path = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.svg';
        file_put_contents(KALETA_ROOT . '/' . $path, $svg);
        [$w, $h] = \Kaleta\Core\Svg::dimensions($svg);

        return ['obr_poloha' => $path, 'obr_width' => min(65535, $w), 'obr_height' => min(65535, $h), 'obr_vel' => strlen($svg),
            'nahl_poloha' => $path, 'nahl_width' => min(65535, $w), 'nahl_height' => min(65535, $h), 'nazev' => mb_substr(str_replace(['_', '-'], ' ', $name), 0, 150)];
    }

    /** A new file in place of the old one with the same URL: links on the site stay and show the new version. */
    protected function actionReplace(): Response
    {
        $ido = $this->request->postInt('ido');
        $image = $this->request->isPost() && $this->canEdit($ido) ? $this->db->one('SELECT * FROM {media} WHERE ido = ?', [$ido]) : null;
        $file = $_FILES['soubor'] ?? null;
        if ($image === null || !is_array($file)) {
            return $this->back();
        }
        try {
            $new = Images::replace($image['obr_poloha'], $file);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'list', ['edit' => $ido], 'chyba');
        }
        $this->db->update('media', $new + ['barva' => ''], ['ido' => $ido]);
        \Kaleta\Front\Cache::clear();

        return $this->back('The file has been replaced – the new version is shown everywhere it is used.', 'list', ['edit' => $ido]);
    }

    protected function actionSave(): Response
    {
        if ($this->request->isPost() && $this->canEdit($this->request->postInt('ido'))) {
            $x = max(0, min(100, $this->request->postInt('ohnisko_x', 50)));
            $y = max(0, min(100, $this->request->postInt('ohnisko_y', 50)));
            $this->db->update('media', [
                'nazev' => mb_substr($this->request->post('nazev'), 0, 150),
                'popis' => mb_substr($this->request->post('popis'), 0, 500),
                'autor' => mb_substr(trim($this->request->post('autor')), 0, 120),
                'ohnisko' => $x === 50 && $y === 50 ? '' : $x . '% ' . $y . '%',
            ], ['ido' => $this->request->postInt('ido')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('Image description saved.');
    }

    /** Description for the blind (alt = field "nazev", as in the detail and in the editor) directly from the grid – without reloading (image/admin.js). */
    protected function actionSaveCaption(): Response
    {
        $ido = $this->request->postInt('ido');
        if (!$this->request->isPost() || !$this->canEdit($ido)) {
            return Response::json(['ok' => false, 'chyba' => t('You cannot edit this image.')], 403);
        }
        $this->db->update('media', ['nazev' => mb_substr(trim($this->request->post('popis')), 0, 150)], ['ido' => $ido]);
        \Kaleta\Front\Cache::clear();

        return Response::json(['ok' => true]);
    }

    /**
     * Clean-up (2.14, Core\MediaHygiene): unused files, oversized images, duplicates and images without a description – the
     * site proposes, the user deletes, shrinks or describes. Media has no trash, so deleting asks for a confirmation.
     */
    protected function actionCleanup(): Response
    {
        $report = \Kaleta\Core\MediaHygiene::report($this->db);
        $canEdit = fn (array $o): bool => $this->app->auth()->isAdmin() || (int) $o['vlastnik'] === $this->app->auth()->id();

        return $this->view('cleanup', 'Media clean-up', $report + [
            'withoutAlt' => array_slice(array_values(array_filter($report['without_alt'], $canEdit)), 0, self::ALT_BATCH),
            'withoutAltTotal' => count($report['without_alt']),
            'canShrink' => extension_loaded('gd'),
            'canEdit' => $canEdit,
        ]);
    }

    /** How many images without alt the clean-up form shows at once. */
    public const int ALT_BATCH = 100;

    /** Descriptions for blind visitors (alt) of several images saved together (Media → Clean-up). */
    protected function actionSaveAlts(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'cleanup');
        }
        $saved = 0;
        foreach (is_array($_POST['alt'] ?? null) ? $_POST['alt'] : [] as $ido => $alt) {
            $alt = mb_substr(trim((string) $alt), 0, 150);
            if ($alt === '' || !$this->canEdit((int) $ido)) {
                continue;
            }
            $saved += $this->db->update('media', ['nazev' => $alt], ['ido' => (int) $ido, 'nazev' => '']);
        }
        if ($saved > 0) {
            \Kaleta\Front\Cache::clear();
            \Kaleta\Admin\ChangeLog::write($this->app, 'media', 'alt texts in bulk', (string) $saved);
        }

        return $this->back(t('Image descriptions saved: %d.', $saved), 'cleanup');
    }

    /** An oversized image re-encoded to the usual size in place (Core\Images::shrinkFile) – its address and every use stay. */
    protected function actionShrink(): Response
    {
        $ido = $this->request->postInt('ido');
        $image = $this->request->isPost() && $this->canEdit($ido) ? $this->db->one('SELECT * FROM {media} WHERE ido = ?', [$ido]) : null;
        if ($image === null) {
            return $this->back('', 'cleanup');
        }
        try {
            $new = Images::shrinkFile($image['obr_poloha']);
        } catch (\RuntimeException $e) {
            return $this->back(t($e->getMessage()), 'cleanup', [], 'chyba');
        }
        $this->db->update('media', $new + ['barva' => ''], ['ido' => $ido]);
        \Kaleta\Front\Cache::clear();
        \Kaleta\Admin\ChangeLog::write($this->app, 'media', 'made smaller', $image['obr_poloha']);

        return $this->back(t('The image is now %s px wide and takes %s.', (string) $new['obr_width'], \Kaleta\Core\Files::size($new['obr_vel'])), 'cleanup');
    }

    /** Bulk action on the selected images: delete, or move to a folder. From the clean-up (zpet=cleanup) it returns there. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $backTo = $this->request->post('zpet') === 'cleanup' ? 'cleanup' : '';
        $move = $this->request->post('provest') === 'presun';
        $target = $this->request->postInt('do_sekce') ?: null;
        $count = 0;
        $skipped = 0;
        $elsewhere = $move ? [] : self::findUsagesElsewhere($this->db);
        foreach ($this->request->postList('oznacene') as $id) {
            $image = $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $id]);
            if ($image === null || !$this->canEdit((int) $image['ido'])) {
                continue;
            }
            if ($move) {
                $count += $this->db->update('media', ['sekce' => $target], ['ido' => $image['ido']]) >= 0 ? 1 : 0;
            } elseif (isset($elsewhere[(int) $image['ido']]) || $this->db->value('SELECT 1 FROM {media_pouziti} WHERE ido = ? LIMIT 1', [$image['ido']]) !== null) {
                $skipped++; // a used file would disappear from the site – it is deleted once it is not used anywhere
            } else {
                Images::delete($image['obr_poloha'], $image['nahl_poloha']);
                \Kaleta\Core\Files::delete($image['obr_poloha']);
                $count += $this->db->delete('media', ['ido' => $image['ido']]);
            }
        }

        if ($skipped > 0) {
            $this->app->session->flash('chyba', t('%d files in use were not deleted – remove them from the site first (the list shows where they are used).', $skipped));
        }
        if (!$move && $count > 0) {
            \Kaleta\Admin\ChangeLog::write($this->app, 'media', 'deleted', (string) $count);
        }

        return $this->back($move ? t('Images moved: %d.', $count) : t('Images deleted: %d.', $count), $backTo, $move && $target ? ['section' => $target] : []);
    }

    private function canEdit(int $ido): bool
    {
        $owner = $this->db->value('SELECT vlastnik FROM {media} WHERE ido = ?', [$ido]);

        return $this->app->auth()->isAdmin() || (int) $owner === $this->app->auth()->id();
    }

    /**
     * List filter from the URL: section (folder number, 0 = unsorted), article (idc), unused=1, search (name, label or file name).
     *
     * @return array{0: string, 1: list<int|string>, 2: array{section: ?int, article: int, unused: bool, search: string, sort: string}}
     */
    private function filter(): array
    {
        $where = ['1 = 1'];
        $params = [];
        $section = $this->request->get('section') === '' ? null : $this->request->getInt('section');
        if ($section !== null) {
            $where[] = $section > 0 ? 'o.sekce = ?' : 'o.sekce IS NULL';
            if ($section > 0) {
                $params[] = $section;
            }
        }
        $newsItem = $this->request->getInt('article');
        if ($newsItem > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {media_pouziti} p WHERE p.ido = o.ido AND p.idc = ?)';
            $params[] = $newsItem;
        }
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        if ($search !== '') {
            $where[] = '(o.nazev LIKE ? OR o.popis LIKE ? OR o.obr_poloha LIKE ?)';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern, $pattern);
        }
        $unused = $this->request->get('unused') === '1';
        if ($unused) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {media_pouziti} p WHERE p.ido = o.ido)';
            $elsewhere = array_keys(self::findUsagesElsewhere($this->db));
            if ($elsewhere !== []) {
                $where[] = 'o.ido NOT IN (' . implode(',', array_map(intval(...), $elsewhere)) . ')';
            }
        }

        $sort = isset(self::SORT_ORDERS[$this->request->get('sort')]) ? $this->request->get('sort') : 'nove';

        return [implode(' AND ', $where), $params, ['section' => $section, 'article' => $newsItem, 'unused' => $unused, 'search' => $search, 'sort' => $sort]];
    }

    /** @return list<array<string, mixed>> folders with the number of images */
    private function folders(): array
    {
        return $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {media} o WHERE o.sekce = s.ids) AS pocet FROM {media_slozky} s ORDER BY s.nazev');
    }

    /** @return list<array<string, mixed>> */
    /** List sort orders: key from the URL => [label, ORDER BY]. */
    public const array SORT_ORDERS = [
        'nove' => ['nejnovější', 'o.ido DESC'], 'stare' => ['nejstarší', 'o.ido ASC'], 'nazev' => ['by name', 'o.nazev ASC, o.ido DESC'],
        'velikost' => ['largest files', 'o.obr_vel DESC'], 'nepouzite' => ['least used', 'pouzito ASC, o.ido DESC'],
    ];

    private function load(string $where, array $params, int $pageNumber, int $count): array
    {
        $order = self::SORT_ORDERS[$this->request->get('sort')][1] ?? self::SORT_ORDERS['nove'][1];
        $elsewhere = self::findUsagesElsewhere($this->db);

        return array_map(function (array $o) use ($elsewhere): array {
            // where: news by the usage table + places outside news
            $o['kde'] = $elsewhere[(int) $o['ido']] ?? [];
            $o['pouzito'] = (int) $o['pouzito'] + count($o['kde']);

            return $o;
        }, $this->db->all(
            "SELECT o.*, (SELECT COUNT(*) FROM {media_pouziti} p WHERE p.ido = o.ido) AS pouzito
             FROM {media} o WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $count, ($pageNumber - 1) * $count],
        ));
    }

    /** @param array<string, mixed> $o */
    private function toJson(array $o): array
    {
        return [
            'id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'popis' => $o['popis'] ?? '',
            'url' => $this->app->url($o['obr_poloha']), 'nahled' => $o['nahl_poloha'] === '' ? '' : $this->app->url($o['nahl_poloha']),
            'sirka' => (int) $o['obr_width'], 'vyska' => (int) $o['obr_height'],
            // attachment for download (PDF, document, audio…): without a thumbnail, inserted into the text as a link
            'soubor' => $o['nahl_poloha'] === '', 'pripona' => strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION)), 'velikost' => \Kaleta\Core\Files::size((int) ($o['obr_vel'] ?? 0)),
        ];
    }

    /**
     * A file input of $_FILES (multiple too) converted to a list of individual files, at most $max of them.
     *
     * @return list<array<string, mixed>>
     */
    public static function uploadedFiles(string $field = 'soubory', int $max = 30): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f)) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $files = [];
        foreach (array_keys($f['name']) as $i) {
            if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $files[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        }

        return array_slice($files, 0, $max);
    }
}
