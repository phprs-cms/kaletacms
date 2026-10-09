<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Notices;
use Kaleta\Core\Response;
use Kaleta\Builder\Collections as KolekceObsahu;
use Kaleta\Builder\ItemImport;
use Kaleta\Builder\CollectionCategories as CategoryTree;
use Kaleta\Builder\Publisher;

/**
 * Collections – custom content types (references, team, products, branches…). The field definition and the item template
 * are changed by the administrator, the items by anyone with access to the module. The Collection list element in the
 * builder puts them on the site.
 */
final class Collections extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'collections';
    public const string NAME = 'Collections';
    public const string GROUP = 'Content';
    public const string ICON = 'kolekce';

    protected function actionList(): Response
    {
        return $this->view('list', 'Collections', [
            'presets' => \Kaleta\Builder\Presets::all(),
            'collection' => $this->db->all('SELECT k.idk, k.nazev, k.seo_link, k.detail, (SELECT COUNT(*) FROM {kolekce_polozky} p WHERE p.idk = k.idk AND p.smazano IS NULL) AS pocet FROM {kolekce} k ORDER BY k.nazev'),
        ]);
    }

    /* ---------- collection definition (administrator) ---------- */

    /** @return array<string, string> collections an item link can point to (2.10): address => name */
    private function otherCollections(int $idk): array
    {
        return $this->db->pairs('SELECT seo_link, nazev FROM {kolekce} WHERE idk <> ? ORDER BY nazev', [$idk]);
    }

    /** A ready-made collection (2.10, 2.11 Builder\Presets): its fields, item pages, structured data and the redirect of hidden items. */
    protected function actionPreset(): Response
    {
        if (($refusal = $this->admin()) !== null || !$this->request->isPost()) {
            return $refusal ?? $this->back();
        }
        $id = self::createPreset($this->app, $this->request->post('preset'));

        return $id === null ? $this->back('Unknown template.', '', [], 'chyba')
            : $this->back('The collection was created with a hidden page that lists it – add the first items, then publish the page.', 'items', ['id' => $id]);
    }

    /** Creates a ready-made collection; returns its id, or null for an unknown preset. Also for MCP (create_collection preset). */
    public static function createPreset(\Kaleta\Core\App $app, string $preset, string $name = ''): ?int
    {
        return \Kaleta\Builder\Presets::create($app, $preset, $name);
    }

    protected function actionNew(): Response
    {
        return $this->admin() ?? $this->view('form', 'New collection', ['k' => ['idk' => 0, 'nazev' => '', 'seo_link' => '', 'detail' => 0, 'pole' => [
            ['klic' => '', 'popisek' => t('Description'), 'typ' => 'radky'], ['klic' => '', 'popisek' => t('Image'), 'typ' => 'obrazek'],
        ]], 'otherCollections' => $this->otherCollections(0)]);
    }

    protected function actionEdit(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));

        return $this->admin() ?? ($k === null ? $this->error('The collection does not exist.', 404) : $this->view('form', $k['nazev'], ['k' => $k, 'otherCollections' => $this->otherCollections((int) $k['idk'])]));
    }

    protected function actionSave(): Response
    {
        if (($refusal = $this->admin()) !== null || !$this->request->isPost()) {
            return $refusal ?? $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('idk');
        $previous = $id > 0 ? KolekceObsahu::byId($this->db, $id) : null;
        $name = mb_substr(trim($r->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('The collection needs a name.', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $name, 110);
        // an unchanged slug stays, also one a later release reserved (3.7)
        if ($seo !== ($previous['seo_link'] ?? null) && (in_array($seo, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$seo]) || \Kaleta\Core\Routes::isNewsSlug($seo, $this->db))
            || $this->db->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $id]) !== null) {
            return $this->back(t('The address “%s” is already used by the system or another collection.', $seo), $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        // the key of an existing field does not change (item values are stored under it); new fields get it from the label
        $field = KolekceObsahu::sanitizeFields(is_array($_POST['pole'] ?? null) ? array_values($_POST['pole']) : []);
        $redirect = KolekceObsahu::cleanRedirect($r->post('hidden_redirect'));
        if ($redirect === null) {
            return $this->back('The redirect of hidden items must be an address on the site (/team) or https://…', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $data = ['nazev' => $name, 'seo_link' => $seo, 'detail' => $r->postBool('detail') ? 1 : 0, 'hidden_redirect' => $redirect, 'pole' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if (is_array($_POST['schema'] ?? null)) {
            $schema = \Kaleta\Builder\CollectionSchema::sanitize($_POST['schema'], $field);
            $data['schema_org'] = $schema === null ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE);
        }
        if ($previous !== null) {
            $this->db->update('kolekce', $data, ['idk' => $id]);
        } else {
            $id = $this->db->insert('kolekce', $data);
        }
        \Kaleta\Front\Cache::clear();

        return $this->back('The collection was saved.', 'items', ['id' => $id]);
    }

    protected function actionDelete(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $k = KolekceObsahu::byId($this->db, $this->request->postInt('idk'));
            // an official notice board keeps its notices for good (2.11, Core\Notices)
            if ($k !== null && ($count = Notices::count($this->db, $k)) > 0) {
                return $this->back(t(Notices::REFUSAL_COLLECTION, $count), 'edit', ['id' => $k['idk']], 'chyba');
            }
            $this->db->delete('kolekce', ['idk' => $this->request->postInt('idk')]);
        }

        return $this->back('The collection and its items were deleted.');
    }

    /* ---------- items ---------- */

    protected function actionItems(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }

        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $trash = $this->request->get('stav') === 'kos';

        return $this->view('items', $k['nazev'], ['k' => $k, 'languages' => Language::additional($this->app->settings()), 'siteLanguages' => $siteLanguages, 'language' => $language,
            'trash' => $trash, 'noticeBoard' => Notices::isNotices($k), 'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NOT NULL', [$k['idk']]),
            // a document library (2.11): how often each document was downloaded, and its stable address
            'downloads' => \Kaleta\Core\Documents::fileField($k) !== null ? \Kaleta\Core\Documents::counts($this->db, (int) $k['idk']) : null,
            'items' => $this->db->all('SELECT idp, nazev, seo_link, poradi, zobrazit, jazyk, datum, smazano, valid_until, review_by FROM {kolekce_polozky} WHERE idk = ? AND smazano IS ' . ($trash ? 'NOT NULL' : 'NULL')
                . ($column !== null ? ' AND jazyk = ?' : '') . ' ORDER BY ' . ($trash ? 'smazano DESC' : 'jazyk, poradi, nazev'), $column !== null ? [$k['idk'], $column] : [$k['idk']])]);
    }

    protected function actionItem(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }
        $idp = $this->request->getInt('polozka');
        // an item in the trash is not edited (saving would publish it again) – it comes back through Restore first
        $p = $idp > 0 ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$idp, $k['idk']]) : null;
        if ($idp > 0 && $p === null) {
            return $this->error('The item does not exist.', 404);
        }
        $assigned = $p !== null ? CategoryTree::ofItem($this->db, (int) $p['idp']) : [];
        if ($p === null) {
            // a new translation from the translation overview (2.14): the original's values, hidden, in the chosen language with the same address
            $language = Language::column($this->app->settings(), $this->request->get('jazyk'));
            $original = $language !== '' ? $this->db->one("SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND jazyk = '' AND smazano IS NULL", [$this->request->getInt('original'), $k['idk']]) : null;
            $p = ['idp' => 0, 'nazev' => $original['nazev'] ?? '', 'seo_link' => $original['seo_link'] ?? '', 'data' => $original['data'] ?? '{}', 'poradi' => $original['poradi'] ?? 100, 'zobrazit' => $original === null ? 1 : 0,
                'jazyk' => $language, 'datum' => date('Y-m-d H:i:s'), 'seo_titulek' => '', 'popis' => '', 'obrazek' => $original['obrazek'] ?? '', 'noindex' => 0, 'zverejnit_od' => null, 'valid_until' => null, 'review_by' => null];
            $assigned = $original !== null ? CategoryTree::ofItem($this->db, (int) $original['idp']) : ($this->request->getInt('kategorie') > 0 ? [$this->request->getInt('kategorie')] : []);
        }
        $p['data'] = json_decode((string) $p['data'], true) ?: [];

        return $this->view('item', $p['nazev'] !== '' ? $p['nazev'] : t('New item'), ['k' => $k, 'p' => $p,
            // 3.7: the categories to tick, with the names of the item's language
            'categories' => CategoryTree::choices($this->db, (int) $k['idk'], (string) $p['jazyk']), 'assigned' => $assigned,
            'versions' => $p['idp'] > 0 ? \Kaleta\Builder\Publisher::listAll($this->db, ['cast' => 'polozka:' . (int) $p['idp']]) : [],
            // the audit trail of a notice (2.11, Core\Notices), newest first
            'noticeLog' => $p['idp'] > 0 && Notices::isNotices($k) ? array_reverse(Notices::entries($this->db, (int) $k['idk'], (int) $p['idp'])) : []]);
    }

    protected function actionSaveItem(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::byId($this->db, $r->postInt('idk')) : null;
        if ($k === null) {
            return $this->back();
        }
        $idp = $r->postInt('idp');
        $name = mb_substr(trim($r->post('nazev')), 0, 200);
        if ($name === '') {
            return $this->back('The item needs a name.', 'item', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }
        $errors = [];
        $data = KolekceObsahu::sanitizeData($k['pole'], is_array($_POST['data'] ?? null) ? $_POST['data'] : [], $errors);
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $name, 150);
        // the slug is unique within a language: a translation of the item can have the same one (/compare/wordpress, /de/compare/wordpress)
        $language = Language::column($this->app->settings(), $r->post('jazyk'));
        // 3.7: never a category's address – a given one is refused, one made from the name gets a number
        $storedSlug = $idp > 0 ? (string) $this->db->value('SELECT seo_link FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) : '';
        if ($r->post('seo_link') !== '' && $seo !== $storedSlug && CategoryTree::slugIsCategory($this->db, (int) $k['idk'], $seo, $language)) {
            return $this->back(CategoryTree::itemSlugRefusal($seo), 'item', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }
        $seo = CategoryTree::freeItemSlug($this->db, (int) $k['idk'], $language, $seo, $idp, $storedSlug);
        $row = ['idk' => $k['idk'], 'nazev' => $name, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'poradi' => max(-9999, min(9999, $r->postInt('poradi'))), 'jazyk' => $language, 'zmeneno' => date('Y-m-d H:i:s'),
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')), 'review_by' => \Kaleta\Core\Validity::date($r->post('review_by'))] // 2.10
            + KolekceObsahu::pageFields($_POST, $r->postBool('zobrazit'));
        // a notice that is (or was) on the board cannot be hidden (2.11, Core\Notices)
        if (Notices::refusesHiding($k, $data, (bool) $row['zobrazit'])) {
            return $this->back(t(Notices::REFUSAL_HIDE), 'item', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }
        $previous = $idp > 0 ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$idp, $k['idk']]) : null;
        if ($previous !== null) {
            KolekceObsahu::saveVersion($this->app, $previous, $row);
            $this->db->update('kolekce_polozky', $row, ['idp' => $idp]);
        } else {
            $idp = $this->db->insert('kolekce_polozky', $row + ['datum' => date('Y-m-d H:i:s')]);
        }
        if ($r->post('kategorie_formular') === '1') {
            CategoryTree::assign($this->db, $idp, (int) $k['idk'], array_map(intval(...), $r->postList('kategorie'))); // 3.7: the ticked categories
        }
        Notices::recordSave($this->app, $k, $previous, $row, $idp);
        \Kaleta\Front\Cache::clear();
        if ($errors !== []) {
            return $this->back(t('The item is saved, but these fields had an invalid value and were left empty: %s', implode(', ', $errors)), 'item', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }

        return $this->back('The item was saved.', 'items', ['id' => $k['idk']]);
    }

    /** Actions the list does with ticked items (2.14): show, hide, language version, trash. A notice board keeps its notices (2.11). */
    protected function actionBulkItems(): Response
    {
        $idk = $this->request->postInt('idk');
        $k = $this->request->isPost() ? KolekceObsahu::byId($this->db, $idk) : null;
        $action = $this->request->post('provest');
        if ($k === null || !in_array($action, ['zobrazit', 'skryt', 'jazyk', 'kos'], true)) {
            return $this->back('Unknown action.', 'items', ['id' => $idk], 'chyba');
        }
        if (Notices::isNotices($k) && $action !== 'zobrazit') {
            return $this->back(t($action === 'kos' ? Notices::REFUSAL_DELETE : Notices::REFUSAL_HIDE), 'items', ['id' => $idk], 'chyba');
        }
        $language = Language::column($this->app->settings(), $this->request->post('jazyk'));
        $done = 0;
        $skipped = 0;
        foreach (array_unique(array_map(intval(...), $this->request->postList('oznacene'))) as $idp) {
            $p = $this->db->one('SELECT idp, nazev, seo_link, jazyk FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$idp, $idk]);
            // the address is unique within a language: an item whose address the target language already has stays where it is
            if ($p === null || ($action === 'jazyk' && $this->db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$idk, $language, $p['seo_link'], $idp]) !== null)) {
                $skipped++;
                continue;
            }
            $now = date('Y-m-d H:i:s');
            match ($action) {
                'zobrazit' => $this->db->update('kolekce_polozky', ['zobrazit' => 1, 'zverejnit_od' => null, 'zmeneno' => $now], ['idp' => $idp]),
                'skryt' => $this->db->update('kolekce_polozky', ['zobrazit' => 0, 'zmeneno' => $now], ['idp' => $idp]),
                'jazyk' => $this->db->update('kolekce_polozky', ['jazyk' => $language, 'zmeneno' => $now], ['idp' => $idp]),
                default => self::trashItem($this->db, $idp, $idk),
            };
            \Kaleta\Admin\ChangeLog::write($this->app, 'collections', 'bulk ' . ['zobrazit' => 'shown', 'skryt' => 'hidden', 'jazyk' => 'language ' . ($language ?: 'default'), 'kos' => 'moved to trash'][$action], mb_substr($k['seo_link'] . ': ' . $p['nazev'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Kaleta\Front\Cache::clear();
        }
        $message = match ($action) {
            'zobrazit' => t('Items published: %d.', $done), 'skryt' => t('Items hidden: %d.', $done),
            'jazyk' => t('Items moved to the language version: %d.', $done), default => t('Items moved to the trash: %d.', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (the address is taken in that language).', $skipped) : ''), 'items', ['id' => $idk], $done > 0 ? 'ok' : 'chyba');
    }

    /* ---------- CSV/JSON import of items (3.7, Builder\ItemImport) ---------- */

    /** The upload form, and the imports of this collection that are not finished. */
    protected function actionImport(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));

        return $k === null ? $this->error('The collection does not exist.', 404)
            : $this->view('import', t('Import items: %s', $k['nazev']), ['k' => $k, 'unfinished' => ItemImport::unfinished((int) $k['idk']), 'finished' => ItemImport::finished((int) $k['idk'])]);
    }

    /** The file is read and kept in storage/import; the preview follows. */
    protected function actionImportUpload(): Response
    {
        $k = $this->request->isPost() ? KolekceObsahu::byId($this->db, $this->request->postInt('idk')) : null;
        if ($k === null) {
            return $this->back();
        }
        $file = $this->request->file('soubor');
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $text = $this->request->post('text');
        $name = 'pasted.csv';
        if ($tmp !== '' && ($file['error'] ?? null) === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
            if (filesize($tmp) > ItemImport::MAX_BYTES) {
                return $this->back(t('The file is larger than %d MB.', ItemImport::MAX_BYTES >> 20), 'import', ['id' => $k['idk']], 'chyba');
            }
            $text = (string) file_get_contents($tmp);
            $name = (string) ($file['name'] ?? 'items.csv');
        }
        if (strlen($text) > ItemImport::MAX_BYTES) {
            return $this->back(t('The file is larger than %d MB.', ItemImport::MAX_BYTES >> 20), 'import', ['id' => $k['idk']], 'chyba');
        }
        $parsed = ItemImport::parse(Redirects::toUtf8($text), $name); // a CSV from Excel in Czech Windows is Windows-1250
        if (is_string($parsed)) {
            return $this->back(t($parsed), 'import', ['id' => $k['idk']], 'chyba');
        }
        $state = ItemImport::create($k, $name, $parsed[0], $parsed[1], $this->app->auth()->id());

        return $this->back('', 'import_preview', ['id' => $k['idk'], 'import' => $state['id']]);
    }

    /** The mapping of the columns and what saving would do with every row. Nothing is saved yet. */
    protected function actionImportPreview(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        $state = $k === null ? null : ItemImport::load($this->request->get('import'), (int) $k['idk']);
        if ($k === null || $state === null) {
            return $this->back('The import does not exist any more.', $k !== null ? 'import' : '', $k !== null ? ['id' => $k['idk']] : [], 'chyba');
        }
        if ($state['faze'] !== 'nahled') {
            return $this->back('', 'import_progress', ['id' => $k['idk'], 'import' => $state['id']]);
        }
        @set_time_limit(120); // thousands of rows of a moved shop
        $plan = ItemImport::plan($this->app, $k, $state);

        return $this->view('import_preview', t('Import items: %s', $k['nazev']), ['k' => $k, 'state' => $state, 'header' => ItemImport::rows((string) $state['id'])['header'],
            'sample' => array_slice(ItemImport::rows((string) $state['id'])['rows'], 0, 3), 'plan' => $plan,
            'counts' => array_count_values(array_column($plan, 'status')) + ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0],
            'languages' => Language::additional($this->app->settings())]);
    }

    /** The mapping and the options from the preview form: back to the preview (Update preview), or the saving starts (Save). */
    protected function actionImportMap(): Response
    {
        $k = $this->request->isPost() ? KolekceObsahu::byId($this->db, $this->request->postInt('idk')) : null;
        $state = $k === null ? null : ItemImport::load($this->request->post('import'), (int) $k['idk']);
        if ($k === null || $state === null || $state['faze'] !== 'nahled') {
            return $this->back('The import does not exist any more.', type: 'chyba');
        }
        $state['mapovani'] = ItemImport::cleanMapping(ItemImport::rows((string) $state['id'])['header'], is_array($_POST['mapovani'] ?? null) ? $_POST['mapovani'] : [], $k['pole']);
        $state['jazyk'] = Language::column($this->app->settings(), $this->request->post('jazyk'));
        $state['zobrazit'] = $this->request->postBool('zobrazit');
        if ($this->request->post('ulozit') === '') {
            ItemImport::save($state);

            return $this->back('', 'import_preview', ['id' => $k['idk'], 'import' => $state['id']]);
        }
        @set_time_limit(120);
        ItemImport::start($this->app, $k, $state);
        ItemImport::save($state);

        return $this->back('', 'import_progress', ['id' => $k['idk'], 'import' => $state['id']]);
    }

    /** GET shows where the import is; POST does one batch (rows, then images). The page submits itself until done. */
    protected function actionImportProgress(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        $state = $k === null ? null : ItemImport::load($this->request->post('import') ?: $this->request->get('import'), (int) $k['idk']);
        if ($k === null || $state === null) {
            return $this->back('The import does not exist any more.', type: 'chyba');
        }
        if ($this->request->isPost() && in_array($state['faze'], ['ulozeni', 'obrazky'], true)) {
            $lock = fopen(\Kaleta\Core\WpFile::folder() . '/polozky-import.zamek', 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    @set_time_limit(60);
                    $state = ItemImport::load((string) $state['id'], (int) $k['idk']) ?? $state;
                    ItemImport::step($this->app, $k, $state);
                } finally {
                    ItemImport::save($state);
                    flock($lock, LOCK_UN);
                }
            }
        }

        return $this->view('import_progress', t('Import items: %s', $k['nazev']), ['k' => $k, 'state' => $state]);
    }

    /** Removes the record of an import (and its rows); the saved items stay. */
    protected function actionImportDelete(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost() && ItemImport::load($this->request->post('import'), $idk) !== null) {
            ItemImport::delete($this->request->post('import'));
        }

        return $this->back('', 'import', ['id' => $idk]);
    }

    /* ---------- categories (3.7, Builder\CollectionCategories) ---------- */

    /** The categories of a collection as a tree, with their language versions. */
    protected function actionCategories(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }
        $languages = Language::additional($this->app->settings());
        $translated = [];
        foreach ($this->db->all('SELECT t.category_id, t.language FROM {collection_category_texts} t WHERE t.idk = ?', [$k['idk']]) as $t) {
            $translated[(int) $t['category_id']][] = (string) $t['language'];
        }
        // the tree in the default language; a category that has texts only in another language is listed under that name
        $tree = CategoryTree::tree($this->db, (int) $k['idk'], '');
        $listed = array_column($tree, 'id');
        foreach ($languages as $language) {
            foreach (CategoryTree::tree($this->db, (int) $k['idk'], $language) as $row) {
                if (!in_array($row['id'], $listed, true)) {
                    $tree[] = $row;
                    $listed[] = $row['id'];
                }
            }
        }
        $counts = $this->db->pairs('SELECT category_id, COUNT(*) FROM {collection_item_categories} ic JOIN {kolekce_polozky} p ON p.idp = ic.idp WHERE p.idk = ? AND p.smazano IS NULL GROUP BY category_id', [$k['idk']]);

        return $this->view('categories', t('Categories: %s', $k['nazev']), ['k' => $k, 'tree' => $tree, 'translated' => $translated, 'languages' => $languages, 'counts' => $counts]);
    }

    /** A category's form: the shared settings and the texts of one language (?jazyk=; the default language without it). */
    protected function actionCategory(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->error('The collection does not exist.', 404);
        }
        $id = $this->request->getInt('kategorie');
        $category = $id > 0 ? CategoryTree::byId($this->db, $id) : null;
        if ($id > 0 && ($category === null || $category['idk'] !== (int) $k['idk'])) {
            return $this->error('The category does not exist in this collection.', 404);
        }
        $language = in_array($this->request->get('jazyk'), Language::additional($this->app->settings()), true) ? $this->request->get('jazyk') : '';
        $category ??= ['id' => 0, 'idk' => (int) $k['idk'], 'parent_id' => $this->request->getInt('nadrazena') ?: null, 'image' => '', 'sort_order' => 100, 'visible' => true, 'texts' => []];
        $parents = array_values(array_filter(CategoryTree::tree($this->db, (int) $k['idk'], ''), fn (array $c): bool => $c['parent_id'] === null && $c['id'] !== $category['id']));
        $texts = $category['texts'][$language] ?? ['name' => '', 'slug' => '', 'description' => '', 'seo_title' => '', 'seo_description' => ''];
        $heading = $texts['name'] !== '' ? $texts['name'] : ($category['texts']['']['name'] ?? t('New category'));

        return $this->view('category', $heading . ($language !== '' ? ' (' . strtoupper($language) . ')' : ''), ['k' => $k, 'category' => $category, 'language' => $language, 'texts' => $texts, 'parents' => $parents,
            'hasChildren' => $category['id'] > 0 && $this->db->value('SELECT 1 FROM {collection_categories} WHERE parent_id = ? LIMIT 1', [$category['id']]) !== null]);
    }

    protected function actionSaveCategory(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::byId($this->db, $r->postInt('idk')) : null;
        if ($k === null) {
            return $this->back();
        }
        $id = $r->postInt('id');
        $language = in_array($r->post('jazyk'), Language::additional($this->app->settings()), true) ? $r->post('jazyk') : '';
        try {
            $id = CategoryTree::save($this->db, (int) $k['idk'], $id > 0 ? $id : null, $language, [
                'name' => $r->post('name'), 'slug' => $r->post('slug'), 'description' => $r->post('description'), 'seo_title' => $r->post('seo_title'),
                'seo_description' => $r->post('seo_description'), 'image' => $r->post('image'), 'sort_order' => $r->postInt('sort_order'),
                'parent_id' => $r->postInt('parent_id'), 'visible' => $r->postBool('visible'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->back($e->getMessage(), 'category', ['id' => $k['idk']] + ($id > 0 ? ['kategorie' => $id] : []) + ($language !== '' ? ['jazyk' => $language] : []), 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'collections', 'category saved', mb_substr($k['seo_link'] . ': ' . $r->post('name'), 0, 80));
        \Kaleta\Front\Cache::clear();

        return $this->back('The category was saved.', 'categories', ['id' => $k['idk']]);
    }

    protected function actionDeleteCategory(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::byId($this->db, $r->postInt('idk')) : null;
        if ($k === null) {
            return $this->back();
        }
        $language = $r->post('jazyk');
        try {
            if ($language !== '' && in_array($language, Language::additional($this->app->settings()), true)) {
                CategoryTree::deleteLanguage($this->db, (int) $k['idk'], $r->postInt('id'), $language); // only that language version
            } else {
                CategoryTree::delete($this->db, (int) $k['idk'], $r->postInt('id'));
            }
        } catch (\InvalidArgumentException $e) {
            return $this->back($e->getMessage(), 'categories', ['id' => $k['idk']], 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'collections', 'category deleted', $k['seo_link'] . ': #' . $r->postInt('id'));
        \Kaleta\Front\Cache::clear();

        return $this->back('The category was deleted; its items stay in the collection.', 'categories', ['id' => $k['idk']]);
    }

    /** E-mail signature of a person (2.10, Builder\EmailSignature): the preview, a copy button, the plain text and where to paste it. */
    protected function actionSignature(): Response
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        $p = $k === null ? null : $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$this->request->getInt('polozka'), $k['idk']]);
        if ($k === null || $p === null) {
            return $this->error('The item does not exist.', 404);
        }
        $p['data'] = json_decode((string) $p['data'], true) ?: [];

        return $this->view('signature', t('E-mail signature: %s', $p['nazev']), ['k' => $k, 'p' => $p, 'signature' => \Kaleta\Builder\EmailSignature::forItem($this->app, $k, $p)]);
    }

    /** Copy of an item (hidden, with a free slug) – a quick start for a similar reference, team member, product. */
    protected function actionDuplicateItem(): Response
    {
        $idk = $this->request->postInt('idk');
        $p = $this->request->isPost() ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$this->request->postInt('idp'), $idk]) : null;
        if ($p === null) {
            return $this->back('', 'items', ['id' => $idk]);
        }
        $seo = CategoryTree::freeItemSlug($this->db, $idk, (string) $p['jazyk'], $p['seo_link'] . '-kopie');
        $copy = ['idk' => $idk, 'nazev' => mb_substr(t('%s (copy)', $p['nazev']), 0, 200), 'seo_link' => $seo, 'data' => $p['data'],
            'seo_titulek' => $p['seo_titulek'], 'popis' => $p['popis'], 'obrazek' => $p['obrazek'], 'noindex' => $p['noindex'],
            'poradi' => $p['poradi'], 'zobrazit' => 0, 'jazyk' => $p['jazyk'], 'datum' => date('Y-m-d H:i:s')];
        $id = $this->db->insert('kolekce_polozky', $copy);
        CategoryTree::assign($this->db, $id, $idk, CategoryTree::ofItem($this->db, (int) $p['idp'])); // the copy is in the same categories (3.7)
        Notices::recordSave($this->app, (array) KolekceObsahu::byId($this->db, $idk), null, $copy, $id);

        return $this->back('The copy of the item is hidden – edit it and publish it.', 'item', ['id' => $idk, 'polozka' => $id]);
    }

    /** An earlier version of the item back (1.9); the current one goes to the history first. */
    protected function actionRestoreItemVersion(): Response
    {
        $idk = $this->request->postInt('idk');
        $idp = $this->request->postInt('idp');
        $item = $this->request->isPost() ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$idp, $idk]) : null;
        $version = $item === null ? null : KolekceObsahu::loadVersion($this->db, $idp, $this->request->postInt('idr'));
        if ($version === null) {
            return $this->back('The version does not exist.', 'items', ['id' => $idk], 'chyba');
        }
        // the address of a restored version may be taken by another item or a category in the meantime (3.7, N37-8): the item keeps its current one
        $kept = '';
        if (isset($version['seo_link']) && !CategoryTree::itemSlugFree($this->db, $idk, (string) $item['jazyk'], (string) $version['seo_link'], $idp, (string) $item['seo_link'])) {
            $kept = ' ' . t('Its address %s is taken by another item or a category now, so the item keeps %s.', (string) $version['seo_link'], (string) $item['seo_link']);
            unset($version['seo_link']);
        }
        KolekceObsahu::saveVersion($this->app, $item, $version);
        $this->db->update('kolekce_polozky', $version + ['zmeneno' => date('Y-m-d H:i:s')], ['idp' => $idp]);
        Notices::recordSave($this->app, (array) KolekceObsahu::byId($this->db, $idk), $item, $version, $idp);
        \Kaleta\Front\Cache::clear();

        return $this->back(t('The earlier version of the item is back; the one before it is in the history.') . $kept, 'item', ['id' => $idk, 'polozka' => $idp]);
    }

    /** To the trash: the item disappears from the site at once and can be restored for 30 days. */
    protected function actionDeleteItem(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            if ($this->isNoticeBoard($idk)) {
                return $this->back(t(Notices::REFUSAL_DELETE), 'items', ['id' => $idk], 'chyba'); // the permanent archive (2.11)
            }
            self::trashItem($this->db, $this->request->postInt('idp'), $idk);
        }

        return $this->back('The item is in the trash – it is no longer on the site; you can restore it for 30 days.', 'items', ['id' => $idk]);
    }

    /** Whether a collection is an official notice board (2.11, Core\Notices) – its notices are never deleted. */
    private function isNoticeBoard(int $idk): bool
    {
        return Notices::isNotices((array) KolekceObsahu::byId($this->db, $idk));
    }

    /** The whole audit trail of a notice board as CSV (administrators, 2.11, Core\Notices). */
    protected function actionNoticeLog(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        if ($k === null || !Notices::isNotices($k)) {
            return $this->error('The collection is not an official notice board.', 404);
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'collections', 'notice log CSV', $k['seo_link']);

        return new Response(Notices::csv($this->db, $k), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $k['seo_link'] . '-log-' . date('Y-m-d') . '.csv"']);
    }

    /** Back from the trash – hidden, so it does not appear on the site before it is checked. */
    protected function actionRestoreItem(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost() && self::restoreItem($this->db, $idp = $this->request->postInt('idp'), $idk)
            && ($slug = CategoryTree::freeRestoredItemSlug($this->db, $idp)) !== null) {
            return $this->back(t('The item was restored as hidden with the address %s, because a category of the collection has its old address.', $slug), 'items', ['id' => $idk]);
        }

        return $this->back('The item was restored as hidden.', 'items', ['id' => $idk]);
    }

    protected function actionDeleteItemPermanently(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            if ($this->isNoticeBoard($idk)) {
                return $this->back(t(Notices::REFUSAL_DELETE), 'items', ['id' => $idk, 'stav' => 'kos'], 'chyba');
            }
            $this->db->run('DELETE FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NOT NULL', [$this->request->postInt('idp'), $idk]);
        }

        return $this->back('The item was deleted permanently.', 'items', ['id' => $idk, 'stav' => 'kos']);
    }

    /** Moves an item to the trash (admin and MCP); returns whether it was there to move. */
    public static function trashItem(\Kaleta\Core\Db $db, int $idp, int $idk): bool
    {
        $moved = $db->run('UPDATE {kolekce_polozky} SET smazano = NOW(), zobrazit = 0 WHERE idp = ? AND idk = ? AND smazano IS NULL', [$idp, $idk])->rowCount() > 0;
        \Kaleta\Front\Cache::clear();

        return $moved;
    }

    public static function restoreItem(\Kaleta\Core\Db $db, int $idp, int $idk): bool
    {
        return $db->run('UPDATE {kolekce_polozky} SET smazano = NULL WHERE idp = ? AND idk = ? AND smazano IS NOT NULL', [$idp, $idk])->rowCount() > 0;
    }

    /** Items longer than 30 days in the trash are deleted permanently (with pages and news, on an admin visit). */
    public static function emptyTrash(\Kaleta\Core\Db $db): void
    {
        $db->run('DELETE FROM {kolekce_polozky} WHERE smazano < NOW() - INTERVAL 30 DAY');
    }

    /* ---------- item template in the builder (administrator) ---------- */

    protected function actionBuilder(): Response
    {
        if (($refusal = $this->admin()) !== null) {
            return $refusal;
        }
        $k = $this->template();
        if ($k !== null && $k['stavba'] === null && $k['stavba_koncept'] === null) {
            KolekceObsahu::writeTemplate($this->db, $k, ['stavba_koncept' => KolekceObsahu::initialTemplateDraft($this->db, $k), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        if (!$this->app->auth()->isAdmin()) {
            return null;
        }
        $k = $this->template();
        if ($k === null) {
            return null;
        }
        $language = $k['sablona_jazyk'];
        $categories = ($k['sablona_druh'] ?? '') === 'kategorie';

        return [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Language::ofContent($this->app->settings(), $language),
            'titulek' => t($categories ? 'Category page: %s' : 'Detail: %s', $k['nazev']) . ($language !== '' ? ' (' . strtoupper($language) . ')' : ''), 'revize' => ['cast' => KolekceObsahu::templateKey($k)],
            'parametry' => ['id' => (int) $k['idk']] + ($language !== '' ? ['jazyk' => $language] : []) + ($categories ? ['sablona' => 'kategorie'] : []),
        ];
    }

    /**
     * Collection with the template of the language from the URL (?jazyk=de; without it, or with a language the site does
     * not have, the default language) – the item template, or with ?sablona=kategorie the category template (3.7).
     */
    private function template(): ?array
    {
        $k = KolekceObsahu::byId($this->db, $this->request->getInt('id'));
        $language = $this->request->get('jazyk');
        $language = in_array($language, Language::additional($this->app->settings()), true) ? $language : '';
        if ($k === null) {
            return null;
        }

        return $this->request->get('sablona') === 'kategorie' ? CategoryTree::template($this->db, $k, $language) : KolekceObsahu::inLanguage($this->db, $k, $language);
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        KolekceObsahu::writeTemplate($this->db, $target['radek'], ['stavba_koncept' => $draft]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::collection($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['radek'];
        $language = $k['sablona_jazyk'];
        if (($k['sablona_druh'] ?? '') === 'kategorie') {
            // 3.7: the category template – previewed on the first category of the language, without one on sample values
            $first = array_values(array_filter(CategoryTree::tree($this->db, (int) $k['idk'], $language), fn (array $c): bool => $c['parent_id'] === null))[0] ?? null;
            $url = $this->app->url(($language !== '' ? $language . '/' : '') . $k['seo_link'] . '/' . ($first !== null ? $first['slug'] : '_kategorie'));

            return [
                'adresa' => $url, 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => true, 'casti' => false,
                'zpet' => ['adresa' => $this->url('categories', ['id' => (int) $k['idk']]), 'text' => t('Categories: %s', $k['nazev'])], 'nastaveni' => $this->url('categories', ['id' => (int) $k['idk']]), 'textNastaveni' => t('Categories'),
                'kolekce' => ['seo_link' => $k['seo_link'], 'nazev' => t('Category page: %s', $k['nazev']), 'detail' => true, 'pole' => [
                    ['klic' => 'popis', 'popisek' => t('Category description'), 'typ' => 'html'], ['klic' => 'obrazek', 'popisek' => t('Image'), 'typ' => 'obrazek'],
                    ['klic' => 'pocet', 'popisek' => t('Number of items'), 'typ' => 'text'], ['klic' => 'nadrazena', 'popisek' => t('Parent category'), 'typ' => 'text'],
                    ['klic' => 'nadrazena_url', 'popisek' => t('Parent category page'), 'typ' => 'odkaz']]],
                'podpis' => KolekceObsahu::templateKey($k),
            ];
        }
        // preview on the first item in the template's language (an additional language has URLs /<language>/…)
        $seo = $this->db->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL ORDER BY poradi, nazev LIMIT 1', [$k['idk'], $language]);
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $k['seo_link'] . '/' . ($seo ?? '_ukazka'));

        return [
            'adresa' => $url, 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $k['detail'], 'casti' => false,
            'zpet' => ['adresa' => $this->url('items', ['id' => (int) $k['idk']]), 'text' => $k['nazev']], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idk']]), 'textNastaveni' => t('Collection fields and settings'),
            'kolekce' => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']],
            'podpis' => KolekceObsahu::templateKey($k),
        ];
    }

    private function admin(): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->error('Only the site administrator can change the collection definition and detail template.', 403);
    }
}
