<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * News and the company blog (in the database table ka_novinky, categories = ka_kategorie).
 *
 * Rules:
 *  - an author sees and edits only their own news items and cannot publish,
 *  - an editor and an administrator see all news items and publish them,
 *  - a published news item can be changed only by someone who can publish.
 */
final class News extends Module
{
    public const string IDENT = 'news';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Novinky';
    public const string GROUP = 'Content';
    public const string ICON = 'novinky';

    private const int PER_PAGE = 20;

    /**
     * A draft of a news author (level 0, does not publish themselves) waits until an editor or an administrator publishes it.
     * Kaleta has no other status "sent for approval" – the author can only save a draft and a message tells them an editor
     * will publish it.
     */
    public const string AWAITING_PUBLICATION = 'c.visible = 0 AND c.autor IN (SELECT idu FROM {uzivatele} WHERE admin = 0)';

    /** How many news items from authors wait to be published (for editors and administrators; 0 for authors). */
    public static function countAwaitingPublication(\Kaleta\Core\App $app): int
    {
        return $app->auth()->canPublish() ? (int) $app->db()->value('SELECT COUNT(*) FROM {novinky} c WHERE c.smazano IS NULL AND ' . self::AWAITING_PUBLICATION) : 0;
    }

    protected function actionList(): Response
    {
        $auth = $this->app->auth();
        $where = ['1 = 1'];
        $params = [];
        if (($authors = $auth->managedAuthors()) !== null) {
            $where[] = 'c.autor IN (' . implode(',', $authors) . ')';
        }
        if (($colorScheme = $this->request->getInt('category')) > 0) {
            $where[] = 'c.tema = ?';
            $params[] = $colorScheme;
        }
        // language version: the site's default language is stored in the column as ''
        $s = $this->app->settings();
        $siteLanguages = ($additional = \Kaleta\Core\Language::additional($s)) === [] ? [] : [\Kaleta\Core\Language::defaults($s), ...$additional];
        $language = in_array($this->request->get('language'), $siteLanguages, true) ? $this->request->get('language') : '';
        if ($language !== '') {
            $where[] = 'c.jazyk = ?';
            $params[] = \Kaleta\Core\Language::column($s, $language);
        }
        if (($search = $this->request->get('search')) !== '') {
            $where[] = 'c.titulek LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $state = $this->request->get('status');
        $inTrash = $state === 'kos';
        $where[] = $inTrash ? 'c.smazano IS NOT NULL' : 'c.smazano IS NULL';
        $statusConditions = [
            'vydane' => 'c.visible = 1 AND c.datum <= NOW()',
            'plan' => 'c.visible = 1 AND c.datum > NOW()',
            'koncepty' => 'c.visible = 0',
            'ke_vydani' => self::AWAITING_PUBLICATION,
        ];
        if (isset($statusConditions[$state])) {
            $where[] = $statusConditions[$state];
        }
        $cond = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM {novinky} c WHERE {$cond}", $params);
        $pageNumber = max(1, $this->request->getInt('page', 1));
        $news = $this->db->all(
            "SELECT c.idc, c.seo_link, c.titulek, c.datum, c.visible, c.visit, c.smazano, c.valid_until, c.review_by,
                    t.nazev AS tema_jm, u.jmeno AS autor_jm, u.user AS autor_login, u.admin AS autor_uroven,
                    (SELECT COUNT(*) FROM {social_drafts} d WHERE d.idc = c.idc AND d.copied_at IS NULL) AS social_open
             FROM {novinky} c
             JOIN {kategorie} t ON t.idt = c.tema
             LEFT JOIN {uzivatele} u ON u.idu = c.autor
             WHERE {$cond}
             ORDER BY " . ($inTrash ? 'c.smazano DESC' : 'c.datum DESC') . ", c.idc DESC
             LIMIT ? OFFSET ?",
            [...$params, self::PER_PAGE, ($pageNumber - 1) * self::PER_PAGE],
        );

        return $this->view('list', 'Novinky', [
            'news' => $news,
            'total' => $total,
            'pageNumber' => $pageNumber,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'category' => Categories::listAll($this->db),
            'filter' => ['category' => $colorScheme, 'language' => $language, 'search' => $search, 'status' => isset($statusConditions[$state]) || $inTrash ? $state : ''],
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} c WHERE c.smazano IS NOT NULL' . $auth->articleScope('c.')),
            'toPublish' => self::countAwaitingPublication($this->app),
            'siteLanguages' => $siteLanguages,
        ]);
    }

    protected function actionNew(): Response
    {
        // without a category the news item could not be saved: the default one is created and the editor opens right away (no dead end)
        if (Categories::createDefault($this->db, $this->app->settings()) !== null) {
            $this->app->session->flash('ok', t('News items need a category, so the category “%s” has been created. You can rename it or add more under News → Categories.', (string) (Categories::listAll($this->db)[0]['nazev'] ?? '')));
        }

        return $this->form($this->defaults());
    }

    /** Values of a new news item; they also fill the fields missing from the form after a failed validation. */
    private function defaults(): array
    {
        // from the translation overview (2.14): the original in the default language is filled in
        $original = $this->request->getInt('translation_of') > 0 ? $this->db->value("SELECT idc FROM {novinky} WHERE idc = ? AND jazyk = '' AND smazano IS NULL", [$this->request->getInt('translation_of')]) : null;

        return [
            'idc' => 0, 'seo_link' => '', 'titulek' => '', 'uvod' => '', 'text' => '', 'obrazek' => '', 'obrazek_popis' => '', 'obrazek_autor' => '',
            'tema' => (int) (Categories::listAll($this->db)[0]['idt'] ?? 0), 'autor' => $this->app->auth()->id(), 'datum' => date('Y-m-d H:i:s'),
            'visible' => 0, 't_slova' => '', 'seo_titulek' => '', 'seo_popis' => '', 'noindex' => 0, 'preklad_z' => $original === null ? null : (int) $original, 'faq' => '', 'jazyk' => '',
            'valid_until' => null, 'review_by' => null,
        ];
    }

    /**
     * Actions the list does with ticked news items (2.14): publish, back to draft, category (the category sets the
     * language version), trash. The same rules as for one news item: an author only their own drafts, publishing only
     * with the permission to publish.
     */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $action = $this->request->post('provest');
        if (!in_array($action, ['vydat', 'koncept', 'kategorie', 'kos'], true)) {
            return $this->back('Unknown action.', '', [], 'chyba');
        }
        $auth = $this->app->auth();
        $category = $action === 'kategorie' ? $this->db->one('SELECT idt, jazyk FROM {kategorie} WHERE idt = ?', [$this->request->postInt('kategorie')]) : null;
        if ($action === 'kategorie' && $category === null) {
            return $this->back('Vyberte kategorii.', '', [], 'chyba');
        }
        $done = 0;
        $skipped = 0;
        $clashes = 0; // 3.9: with slugs per language a news item cannot move where another one of that version has its slug
        // the list's checkboxes are the ones of "Delete selected" (smaz[]); oznacene[] is what the other lists send
        foreach (array_unique(array_map(intval(...), [...$this->request->postList('smaz'), ...$this->request->postList('oznacene')])) as $id) {
            $newsItem = $this->load($id);
            if ($newsItem === null || (!$auth->canPublish() && ($newsItem['visible'] || $action === 'vydat'))) {
                $skipped++;
                continue;
            }
            if ($action === 'kategorie' && $category['jazyk'] !== $newsItem['jazyk'] && \Kaleta\Core\Slug::taken($this->db, 'novinky', (string) $newsItem['seo_link'], (string) $category['jazyk'], $id)) {
                $clashes++;
                continue;
            }
            $now = date('Y-m-d H:i:s');
            match ($action) {
                'vydat' => $this->db->update('novinky', ['visible' => 1, 'zmeneno' => $now], ['idc' => $id]),
                'koncept' => $this->db->update('novinky', ['visible' => 0, 'zmeneno' => $now], ['idc' => $id]),
                // a translation stays linked to its original only in another language version
                'kategorie' => $this->db->update('novinky', ['tema' => (int) $category['idt'], 'jazyk' => $category['jazyk'], 'preklad_z' => $category['jazyk'] === '' ? null : $newsItem['preklad_z'], 'zmeneno' => $now], ['idc' => $id]),
                default => $this->db->update('novinky', ['smazano' => $now, 'visible' => 0], ['idc' => $id]),
            };
            \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'bulk ' . ['vydat' => 'published', 'koncept' => 'back to draft', 'kategorie' => 'category', 'kos' => 'moved to trash'][$action], mb_substr($newsItem['titulek'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Kaleta\Front\Cache::clear();
            if ($action === 'vydat') {
                \Kaleta\Core\Notifications::process($this->app); // newly published news items are announced (webhook, IndexNow)
            }
        }
        $message = match ($action) {
            'vydat' => t('News items published: %d.', $done), 'koncept' => t('News items back as drafts: %d.', $done),
            'kategorie' => t('News items moved to the category: %d.', $done), default => t('News items moved to the trash: %d. They can be restored for 30 days (News → Trash).', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (no permission).', $skipped) : '')
            . ($clashes > 0 ? ' ' . t('Skipped: %d (that language version already has the address).', $clashes) : ''), '', [], $done > 0 ? 'ok' : 'chyba');
    }

    /** Copy of a news item as a draft (tags included) – a quick start for a similar news item. */
    protected function actionDuplicate(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->request->postInt('idc')) : null;
        if ($newsItem === null) {
            return $this->back();
        }
        $copy = array_intersect_key($newsItem, array_flip(['uvod', 'text', 'obrazek', 'obrazek_popis', 'obrazek_autor', 'tema', 't_slova', 'seo_popis', 'noindex', 'faq', 'jazyk']));
        $seo = \Kaleta\Core\Slug::makeUnique($newsItem['seo_link'] . '-kopie', fn (string $a): bool => \Kaleta\Core\Slug::taken($this->db, 'novinky', $a, (string) $newsItem['jazyk']));
        $id = $this->db->insert('novinky', $copy + ['titulek' => mb_substr(t('%s (copy)', $newsItem['titulek']), 0, 255), 'seo_link' => $seo, 'visible' => 0,
            'datum' => date('Y-m-d H:i:s'), 'autor' => $this->app->auth()->id(), 'zmeneno' => date('Y-m-d H:i:s')]);
        $this->db->run('INSERT INTO {novinky_stitky} (idc, ids) SELECT ?, ids FROM {novinky_stitky} WHERE idc = ?', [$id, $newsItem['idc']]);
        \Kaleta\Core\Search::index($this->db, $id);

        return $this->back('The copy of the news item is saved as a draft.', 'edit', ['id' => $id]);
    }

    protected function actionEdit(): Response
    {
        $newsItem = $this->load($this->request->getInt('id'));

        return $newsItem === null ? $this->error('The news item does not exist or you do not have access to it.', 404) : $this->form($newsItem);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $auth = $this->app->auth();
        $r = $this->request;
        $id = $r->postInt('idc');

        $previous = null;
        if ($id > 0) {
            $previous = $this->load($id);
            if ($previous === null) {
                return $this->error('The news item does not exist or you do not have access to it.', 404);
            }
            if ($previous['visible'] && !$auth->canPublish()) {
                return $this->error('Only an editor or administrator can edit a published news item.', 403);
            }
        }

        $errors = [];
        $html = [];
        foreach (['uvod', 'text'] as $field) {
            try {
                $html[$field] = \Kaleta\Core\Html::forUserOrFail($r->post($field), $auth, $field);
            } catch (\Kaleta\Core\HtmlTooLarge $e) {
                $html[$field] = $r->post($field); // only shown again in the form (escaped), never saved
                $errors[$field] = $e->localized();
            }
        }
        $data = [
            'titulek' => $r->post('titulek'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('titulek'), 150),
            'uvod' => $html['uvod'],
            'text' => $html['text'],
            'obrazek' => $r->post('obrazek'),
            'obrazek_popis' => mb_substr(trim($r->post('obrazek_popis')), 0, 300),
            'obrazek_autor' => mb_substr(trim($r->post('obrazek_autor')), 0, 120),
            'tema' => $r->postInt('tema'),
            'autor' => $r->postInt('autor'),
            'datum' => self::parseFormDate($r->post('datum')) ?? date('Y-m-d H:i:s'),
            'visible' => (int) ($r->post('stav') === 'vydany' && $auth->canPublish()),
            't_slova' => $r->post('t_slova'),
            'seo_titulek' => mb_substr($r->post('seo_titulek'), 0, 255),
            'seo_popis' => mb_substr($r->post('seo_popis'), 0, 320),
            'noindex' => (int) $r->postBool('noindex'),
            'faq' => $r->post('faq'),
            'zmeneno' => date('Y-m-d H:i:s'),
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')),
            'review_by' => \Kaleta\Core\Validity::date($r->post('review_by')),
        ];

        if ($data['titulek'] === '') {
            $errors['titulek'] = 'Fill in the title.';
        }
        if ($this->db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$data['tema']]) === null) {
            $errors['tema'] = 'Vyberte kategorii.';
        }
        $allowedAuthors = $auth->managedAuthors();
        if ($allowedAuthors !== null && !in_array($data['autor'], $allowedAuthors, true)) {
            $data['autor'] = $auth->id();
        }
        if ($this->db->value('SELECT idu FROM {uzivatele} WHERE idu = ?', [$data['autor']]) === null) {
            $errors['autor'] = 'Select an author.';
        }
        if ($errors !== []) {
            return $this->form(['idc' => $id] + $data + ($previous ?? $this->defaults()), $errors);
        }
        // the language version is taken from the category; a translation is linked to the news item in the default language (slug or number)
        $data['jazyk'] = (string) $this->db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$data['tema']]);
        $original = trim($r->post('preklad_z'));
        $data['preklad_z'] = $original === '' || $data['jazyk'] === '' ? null
            : ($this->db->value("SELECT idc FROM {novinky} WHERE (idc = ? OR seo_link = ?) AND jazyk = '' AND idc <> ?", [(int) $original, basename((string) parse_url($original, PHP_URL_PATH)), $id]) ?: null);

        if ($r->postBool('oznacit_aktualizaci') && $data['visible']) {
            $data['aktualizovano'] = date('Y-m-d H:i:s');
        }
        $data['seo_link'] = $this->findFreeSlug($data['seo_link'], $id, $data['jazyk']);
        if ($id > 0) {
            if ([$previous['titulek'], $previous['uvod'], $previous['text']] !== [$data['titulek'], $data['uvod'], $data['text']]) {
                self::version($this->db, $previous, $this->app->auth()->id());
            }
            $this->db->update('novinky', $data, ['idc' => $id]);
            if ($previous['seo_link'] !== $data['seo_link'] && $previous['visible']) {
                // a published news item changed its slug: the old one is redirected so that links and search engines do not lose the page
                Redirects::add($this->db, \Kaleta\Core\Slug::redirectPath($this->db, 'novinky/' . $previous['seo_link'], (string) $previous['jazyk']),
                    \Kaleta\Core\Slug::redirectPath($this->db, 'novinky/' . $data['seo_link'], $data['jazyk']));
            }
        } else {
            $id = $this->db->insert('novinky', $data);
        }

        Media::recordUsage($this->db, $id, $data['obrazek'], $data['uvod'], $data['text']);
        \Kaleta\Core\Search::index($this->db, $id);
        // a saved news item clears the unsaved state on the server (for a new one it is kept under the number 0)
        $this->db->run('DELETE FROM {novinky_koncepty} WHERE kdo = ? AND idc IN (0, ?)', [$auth->id(), $id]);
        self::tags($this->db, $id, $r->post('stitky'));
        // a newly published news item is announced (webhook, IndexNow); a scheduled one waits for its time - see Core\Notifications
        \Kaleta\Core\Notifications::process($this->app);
        if ($data['visible'] && !empty($previous['visible']) && !$data['noindex'] && strtotime($data['datum']) <= time()) {
            (new \Kaleta\Front\Seo($this->app))->indexNow($this->app->newsItemUrl($data['seo_link'], $data['jazyk']));
        }

        $message = $auth->canPublish() ? 'News item saved.' : 'News item saved. It will appear on the site once an editor publishes it.';

        return $r->post('po_ulozeni') === 'zustat' ? $this->back($message, 'edit', ['id' => $id]) : $this->back($message);
    }

    /**
     * Saving from editing "directly on the site" (views/front/upravit.php): only the title, intro and text. The same rules
     * apply as for a regular save - permissions via load(), a published news item only with the permission to publish,
     * versions, search, image usage.
     */
    protected function actionSaveText(): Response
    {
        $r = $this->request;
        $newsItem = $r->isPost() ? $this->load($r->postInt('id')) : null;
        if ($newsItem === null || ($newsItem['visible'] && !$this->app->auth()->canPublish())) {
            return $this->redirectToSite($r->post('zpet'));
        }
        // an unpublished news item is visible on the site only in the preview
        $preview = $newsItem['visible'] && strtotime((string) $newsItem['datum']) <= time() ? '' : 'preview=1';
        try {
            $data = ['titulek' => mb_substr($r->post('titulek'), 0, 255), 'uvod' => \Kaleta\Core\Html::forUserOrFail($r->post('uvod'), $this->app->auth(), 'uvod'),
                'text' => \Kaleta\Core\Html::forUserOrFail($r->post('text'), $this->app->auth(), 'text')];
        } catch (\Kaleta\Core\HtmlTooLarge) {
            // over a limit of Core\HtmlLimits: nothing is saved (the result is only a code in the address, never a text)
            return $this->redirectToSite($r->post('zpet'), '?' . ($preview !== '' ? $preview . '&' : '') . 'edit=text&error=limit');
        }
        if ($data['titulek'] === '') {
            return $this->redirectToSite($r->post('zpet'), '?' . ($preview !== '' ? $preview . '&' : '') . 'edit=text&error=1');
        }
        if ([$newsItem['titulek'], $newsItem['uvod'], $newsItem['text']] !== array_values($data)) {
            self::version($this->db, $newsItem, $this->app->auth()->id());
        }
        $this->db->update('novinky', $data + ['zmeneno' => date('Y-m-d H:i:s')], ['idc' => $newsItem['idc']]);
        Media::recordUsage($this->db, (int) $newsItem['idc'], (string) $newsItem['obrazek'], $data['uvod'], $data['text']);
        \Kaleta\Core\Search::index($this->db, (int) $newsItem['idc']);
        \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'edited directly on the site', mb_substr($data['titulek'], 0, 80));
        if ($newsItem['visible'] && !$newsItem['noindex'] && strtotime((string) $newsItem['datum']) <= time()) {
            (new \Kaleta\Front\Seo($this->app))->indexNow($this->app->newsItemUrl($newsItem['seo_link'], $newsItem['jazyk']));
        }

        return $this->redirectToSite($r->post('zpet'), $preview !== '' ? '?' . $preview : '');
    }

    /**
     * Autosaving an unsaved news item to the server (image/editor.js). It does not save the news item - only the form state
     * of the signed-in user, so that they can continue writing elsewhere. A POST without the field "pole" deletes the unsaved state.
     */
    protected function actionDraft(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $idc = $this->request->postInt('idc');
        if ($idc > 0 && $this->load($idc) === null) {
            return Response::json(['ok' => false], 404);
        }
        $me = $this->app->auth()->id();
        $data = (string) ($_POST['pole'] ?? '');
        if ($data === '' || strlen($data) > 3_000_000 || !is_array(json_decode($data, true))) {
            $this->db->delete('novinky_koncepty', ['kdo' => $me, 'idc' => $idc]);

            return Response::json(['ok' => true, 'smazano' => true]);
        }
        $this->db->run('INSERT INTO {novinky_koncepty} (kdo, idc, cas, data) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE cas = NOW(), data = VALUES(data)', [$me, $idc, $data]);
        if (random_int(1, 40) === 1) {
            $this->db->run('DELETE FROM {novinky_koncepty} WHERE cas < NOW() - INTERVAL 30 DAY');
        }

        return Response::json(['ok' => true]);
    }

    /** Searching news by title for the link dialog in the editor and for the command palette (?edit=1). */
    protected function actionSearchJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['clanky' => []]);
        }
        $editMode = $this->request->get('edit') === '1';
        $news = $this->db->all(
            'SELECT idc, titulek, seo_link, jazyk, visible AND datum <= NOW() AS vydany FROM {novinky} WHERE smazano IS NULL AND titulek LIKE ?'
                . ($editMode ? $this->app->auth()->articleScope() : '') . ' ORDER BY datum DESC LIMIT 8',
            ['%' . addcslashes($q, '%_\\') . '%'],
        );

        return Response::json(['clanky' => array_map(fn (array $c): array => [
            'titulek' => $c['titulek'], 'vydany' => (bool) $c['vydany'],
            'url' => $editMode ? $this->url('edit', ['id' => $c['idc']]) : $this->app->url(($c['jazyk'] !== '' ? $c['jazyk'] . '/' : '') . 'novinky/' . $c['seo_link']),
        ], $news)]);
    }

    /**
     * AI assistant: a suggestion for the news item being written (titles, intro, SEO description, tags, proofreading, image description).
     * Works with the text from the form, saves nothing - a human decides whether to use the suggestion.
     */
    protected function actionAssistant(): Response
    {
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$this->request->isPost() || !$assistant->isReady()) {
            return Response::json(['chyba' => t('The writing assistant is not enabled or the key is missing (Features).')], 400);
        }
        // safeguard against unwanted spending: at most 60 requests per hour per user
        if ($this->hasTooManyRequests()) {
            return Response::json(['chyba' => t('You have used the assistant 60 times in the last hour. Please try again later.')], 429);
        }
        $task = $this->request->post('ukol');
        $image = null;
        if ($task === 'alt') {
            // only files from media/: the path is assembled from verified parts of the URL
            $image = preg_match('#media/(\d{4}/\d{2}/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif))$#', (string) parse_url($this->request->post('obrazek'), PHP_URL_PATH), $m) ? KALETA_ROOT . '/media/' . $m[1] : null;
            $smaller = $image === null ? null : preg_replace('/\.(\w+)$/', '-1200.$1', $image);
            $image = $smaller !== null && is_file($smaller) ? $smaller : $image;
        }
        try {
            $result = $assistant->suggest($task, [
                'titulek' => $this->request->post('titulek'),
                'uvod' => $this->request->post('uvod'),
                'text' => $this->request->post('text'),
                'stitky_webu' => $task === 'stitky' ? array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 300'), 'nazev') : [],
            ], $image);
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 502);
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'asistent', $task, mb_substr($this->request->post('titulek'), 0, 80));

        return Response::json($result);
    }

    /** "Social posts" panel (2.13, Core\SocialDrafts): a person edited a draft before copying it. */
    protected function actionSocialSave(): Response
    {
        $draft = $this->socialDraft();
        if ($draft === null) {
            return $this->back();
        }
        $error = \Kaleta\Core\SocialDrafts::update($this->db, $draft['id'], $this->request->post('text'));
        if ($error !== null) {
            return $this->backToSocial($draft['idc'], $error, 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'social draft', $draft['network'] . ' #' . $draft['idc']);

        return $this->backToSocial($draft['idc'], 'The post draft is saved.');
    }

    /** "Mark as posted" (and back) on a social post draft. */
    protected function actionSocialPosted(): Response
    {
        $draft = $this->socialDraft();
        if ($draft === null) {
            return $this->back();
        }
        \Kaleta\Core\SocialDrafts::markPosted($this->db, $draft['id'], $this->request->postBool('posted'));

        return $this->backToSocial($draft['idc'], $this->request->postBool('posted') ? 'Marked as posted.' : 'Marked as not posted yet.');
    }

    /** "Suggest with the assistant": the AI assistant rewrites the drafts of the news item – only on this click. */
    protected function actionSocialSuggest(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->request->postInt('idc')) : null;
        if ($newsItem === null) {
            return $this->back();
        }
        if ($this->hasTooManyRequests()) {
            return $this->backToSocial((int) $newsItem['idc'], 'You have used the assistant 60 times in the last hour. Please try again later.', 'chyba');
        }
        $error = \Kaleta\Core\SocialDrafts::suggest($this->app, (int) $newsItem['idc']);
        if ($error !== null) {
            return $this->backToSocial((int) $newsItem['idc'], $error, 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'asistent', 'prispevky', mb_substr((string) $newsItem['titulek'], 0, 80));

        return $this->backToSocial((int) $newsItem['idc'], 'The assistant rewrote the drafts – read them before posting.');
    }

    /** The draft from the POST, only when the news item is within the signed-in user's scope. */
    private function socialDraft(): ?array
    {
        $draft = $this->request->isPost() ? \Kaleta\Core\SocialDrafts::find($this->db, $this->request->postInt('id')) : null;

        return $draft !== null && $this->load($draft['idc']) !== null ? $draft : null;
    }

    /** Back to the editor, to the "Social posts" panel. */
    private function backToSocial(int $idc, string $message, string $type = 'ok'): Response
    {
        $this->app->session->flash($type, t($message));

        return Response::redirect($this->url('edit', ['id' => $idc]) . '#social-posts');
    }

    /**
     * "Přeložit asistentem" (Translate with the assistant): from the saved version of the news item in the default language
     * it creates a draft in the category of the target language, linked to the original. The translation always waits to be
     * read by a human – it is never published by itself.
     */
    protected function actionTranslate(): Response
    {
        $newsItem = $this->request->isPost() ? $this->load($this->request->postInt('idc')) : null;
        if ($newsItem === null) {
            return $this->back('Save the news item first, then it can be translated.', type: 'chyba');
        }
        $backToNewsItem = fn (string $message): Response => $this->back($message, 'edit', ['id' => $newsItem['idc']], type: 'chyba');
        $language = $this->request->post('prelozit_do');
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$assistant->isReady()) {
            return $backToNewsItem('The writing assistant is not enabled or the key is missing (Features).');
        }
        if ($newsItem['jazyk'] !== '' || !in_array($language, \Kaleta\Core\Language::additional($this->app->settings()), true)) {
            return $backToNewsItem('Only a news item in the default language can be translated, into one of the other language versions of the site.');
        }
        if (($existing = $this->db->value('SELECT idc FROM {novinky} WHERE preklad_z = ? AND jazyk = ?', [$newsItem['idc'], $language])) !== null) {
            return $this->back('A translation into this language already exists – here it is.', 'edit', ['id' => (int) $existing]);
        }
        // target category: the counterpart of the original's category, otherwise the first category of the given language
        $category = $this->db->value('SELECT idt FROM {kategorie} WHERE jazyk = ? ORDER BY (preklad_z <=> ?) DESC, hodnost DESC, idt LIMIT 1', [$language, $newsItem['tema']]);
        if ($category === null) {
            return $backToNewsItem('There is no category in the target language yet. Create one in News → Categories (the Language version field).');
        }
        if ($this->hasTooManyRequests()) {
            return $backToNewsItem('You have used the assistant 60 times in the last hour. Please try again later.');
        }

        set_time_limit(600); // a long text is translated in batches
        $plainFields = ['titulek', 'seo_titulek', 'seo_popis', 'faq', 't_slova'];
        try {
            $translation = $assistant->translate(array_map(strval(...), array_intersect_key($newsItem, array_flip([...$plainFields, 'uvod', 'text']))), $language, $plainFields);
        } catch (\RuntimeException $e) {
            return $backToNewsItem(t($e->getMessage()));
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'asistent', 'preklad-' . $language, mb_substr($newsItem['titulek'], 0, 80));

        // the draft takes everything that is not translated from the original (image, author…); not the counters
        $data = array_intersect_key($newsItem, array_flip(['obrazek', 'autor', 'noindex'])) + [
            'tema' => (int) $category, 'jazyk' => $language, 'preklad_z' => $newsItem['idc'], 'visible' => 0, 'datum' => date('Y-m-d H:i:s'),
        ];
        foreach ($translation as $field => $value) {
            $data[$field] = $field === 'titulek' ? mb_substr($value, 0, 255) : $value;
        }
        $data['seo_link'] = $this->findFreeSlug(slugify($data['titulek'], 100), 0, $language);
        $id = $this->db->insert('novinky', $data);
        Media::recordUsage($this->db, $id, (string) $data['obrazek'], $data['uvod'], $data['text']);
        $this->db->run('INSERT INTO {novinky_stitky} (idc, ids) SELECT ?, ids FROM {novinky_stitky} WHERE idc = ?', [$id, $newsItem['idc']]);
        \Kaleta\Core\Search::index($this->db, $id);

        return $this->back('The translation has been created as a draft. Read it before publishing – the assistant can make mistakes in names, numbers and technical terms.', 'edit', ['id' => $id]);
    }

    private function hasTooManyRequests(): bool
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM {protokol} WHERE kdo = ? AND modul = 'asistent' AND cas > NOW() - INTERVAL 1 HOUR", [$this->app->auth()->id()]) >= 60;
    }

    /** Loads an older version of the news item into the editor; it is saved only when the form is submitted. */
    protected function actionVersions(): Response
    {
        $newsItem = $this->load($this->request->getInt('id'));
        $version = $newsItem === null ? null : $this->db->one('SELECT * FROM {novinky_revize} WHERE idr = ? AND idc = ?', [$this->request->getInt('revision'), $newsItem['idc']]);
        if ($version === null) {
            return $this->error('This version of the news item does not exist.', 404);
        }
        $this->app->session->flash('info', t('The editor now contains the version from %s. It takes effect once you save the news item.', format_date($version['datum'], true)));

        return $this->form(['titulek' => $version['titulek'], 'uvod' => $version['uvod'], 'text' => $version['text']] + $newsItem);
    }

    /** What changed since the saved version: comparison of an older version with the current wording. */
    protected function actionCompare(): Response
    {
        $newsItem = $this->load($this->request->getInt('id'));
        $version = $newsItem === null ? null : $this->db->one(
            "SELECT r.*, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm FROM {novinky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.idr = ? AND r.idc = ?",
            [$this->request->getInt('revision'), $newsItem['idc'] ?? 0],
        );
        if ($version === null) {
            return $this->error('This version of the news item does not exist.', 404);
        }

        return $this->view('compare', 'Compare versions', [
            'newsItem' => $newsItem,
            'versions' => $version,
            'title' => \Kaleta\Core\Diff::html((string) $version['titulek'], (string) $newsItem['titulek']),
            'home' => \Kaleta\Core\Diff::html((string) $version['uvod'], (string) $newsItem['uvod']),
            'text' => \Kaleta\Core\Diff::html((string) $version['text'], (string) $newsItem['text']),
        ]);
    }

    /** Broken links found by the background check (Core\Links) – since 2.14 across news items, page builds and collection items. */
    protected function actionLinks(): Response
    {
        if ($this->request->isPost()) {
            // "check again": the record is put at the front of the queue
            \Kaleta\Core\Links::recheck($this->app, $this->request->post('kind') ?: 'news', $this->request->postInt('id') ?: $this->request->postInt('idc'));

            return $this->back('It will be checked again within a few minutes.', 'links');
        }
        $pages = $this->app->auth()->hasModule('pages');

        return $this->view('links', 'Broken links', [
            'links' => array_values(array_filter(\Kaleta\Core\Links::broken($this->app, 300, $this->app->auth()->articleScope('c.')), fn (array $l): bool => $l['kind'] === 'news' || $pages)),
            'checked' => (int) $this->db->value('SELECT (SELECT COUNT(*) FROM {novinky} WHERE odkazy_cas IS NOT NULL) + (SELECT COUNT(*) FROM {stranky} WHERE links_checked IS NOT NULL) + (SELECT COUNT(*) FROM {kolekce_polozky} WHERE links_checked IS NOT NULL)'),
            'total' => (int) $this->db->value('SELECT (SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL) + (SELECT COUNT(*) FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL) + (SELECT COUNT(*) FROM {kolekce_polozky} WHERE zobrazit = 1 AND smazano IS NULL)'),
            'isEnabled' => $this->app->settings()->bool('link_check'),
        ]);
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $moved = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $newsItem = $this->load((int) $id);
            if ($newsItem === null || ($newsItem['visible'] && !$this->app->auth()->canPublish())) {
                continue;
            }
            // trash: the news item disappears from the site and from lists, but can be restored for 30 days; it returns as a draft, never published by itself
            $moved += $this->db->update('novinky', ['smazano' => date('Y-m-d H:i:s'), 'visible' => 0], ['idc' => $newsItem['idc']]);
            \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'moved to trash', mb_substr($newsItem['titulek'], 0, 80));
        }

        return $this->back(t('News items moved to the trash: %d. They can be restored for 30 days (News → Trash).', $moved), type: $moved > 0 ? 'ok' : 'chyba');
    }

    /** Restore from the trash: the news item returns as a draft (not published). */
    protected function actionRestore(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $restored = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $newsItem = $this->load((int) $id, true);
            if ($newsItem === null) {
                continue;
            }
            $restored += $this->db->update('novinky', ['smazano' => null], ['idc' => $newsItem['idc']]);
            \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'restored from trash', mb_substr($newsItem['titulek'], 0, 80));
        }

        return $this->back(t('News items restored: %d. They are back as drafts.', $restored), '', ['status' => 'kos'], $restored > 0 ? 'ok' : 'chyba');
    }

    /** Permanent deletion from the trash (only someone who can publish). */
    protected function actionDeletePermanently(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->canPublish()) {
            return $this->back();
        }
        $deleted = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $newsItem = $this->load((int) $id, true);
            if ($newsItem !== null) {
                $deleted += $this->db->delete('novinky', ['idc' => $newsItem['idc']]);
                \Kaleta\Admin\ChangeLog::write($this->app, 'news', 'deleted permanently', mb_substr($newsItem['titulek'], 0, 80));
            }
        }

        return $this->back(t('News items permanently deleted: %d.', $deleted), '', ['status' => 'kos'], $deleted > 0 ? 'ok' : 'chyba');
    }

    /** The trash empties itself: news items older than 30 days are deleted permanently (called by Admin\Kernel on entering the admin). */
    public static function emptyTrash(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {novinky} WHERE smazano < NOW() - INTERVAL 30 DAY')->rowCount();
    }

    /**
     * @param array<string, mixed> $newsItem
     * @param array<string, string> $errors
     */
    private function form(array $newsItem, array $errors = []): Response
    {
        $auth = $this->app->auth();
        $allowedIds = $auth->managedAuthors();
        $authors = $allowedIds === null
            ? $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2")
            : $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE idu IN (" . implode(',', $allowedIds) . ') ORDER BY 2');
        // social post drafts (2.13): only a published news item has them; a news item published through Claude gets them here at the latest
        $published = $newsItem['idc'] && $newsItem['visible'] && strtotime((string) $newsItem['datum']) <= time() && empty($newsItem['smazano']);
        if ($published) {
            \Kaleta\Core\SocialDrafts::prepare($this->app, (int) $newsItem['idc']);
        }

        return $this->view('form', $newsItem['idc'] ? 'Edit news item' : 'New news item', [
            'socialDrafts' => $published ? \Kaleta\Core\SocialDrafts::forNews($this->db, (int) $newsItem['idc']) : null,
            'newsItem' => $newsItem,
            'errors' => $errors,
            'category' => Categories::listAll($this->db),
            'authors' => $authors,
            'canPublish' => $auth->canPublish(),
            'draftOnServer' => $this->request->isPost() ? null : $this->db->one('SELECT cas, data FROM {novinky_koncepty} WHERE kdo = ? AND idc = ?', [$auth->id(), (int) $newsItem['idc']]),
            'siteLanguages' => \Kaleta\Core\Language::additional($this->app->settings()) !== [],
            // for a news item in the default language: which languages it can be translated into and which translations already exist (language => number)
            'translationLanguages' => $newsItem['idc'] && ($newsItem['jazyk'] ?? '') === '' ? \Kaleta\Core\Language::additional($this->app->settings()) : [],
            'translations' => $newsItem['idc'] ? array_map(intval(...), $this->db->pairs("SELECT jazyk, idc FROM {novinky} WHERE preklad_z = ? AND jazyk <> ''", [(int) $newsItem['idc']])) : [],
            'original' => empty($newsItem['preklad_z']) ? '' : (string) $this->db->value('SELECT seo_link FROM {novinky} WHERE idc = ?', [$newsItem['preklad_z']]),
            'assistant' => (new \Kaleta\Core\Assistant($this->app->settings()))->isReady(),
            // content check of the saved version (2.14, Core\ContentCheck); a news item not saved yet has nothing to check
            'contentCheck' => $newsItem['idc'] && !$this->request->isPost() ? \Kaleta\Core\ContentCheck::forNews($newsItem) : [],
            'tags' => $this->request->isPost() ? $this->request->post('stitky') : implode(', ', array_column(
                $this->db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [(int) $newsItem['idc']]),
                'nazev',
            )),
            'allTags' => array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 500'), 'nazev'),
            'versions' => $this->db->all(
                "SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm
                 FROM {novinky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.idc = ? ORDER BY r.idr DESC",
                [(int) $newsItem['idc']],
            ),
        ]);
    }

    /** Saves the previous form of the news item; the last 20 versions are kept. */
    /** The previous form of the news item to the history (the last 20 versions) – admin and MCP. */
    public static function version(\Kaleta\Core\Db $db, array $previous, ?int $who): void
    {
        $db->insert('novinky_revize', [
            'idc' => $previous['idc'], 'datum' => $previous['zmeneno'] ?? $previous['datum'], 'kdo' => $who,
            'titulek' => $previous['titulek'], 'uvod' => $previous['uvod'], 'text' => $previous['text'],
        ]);
        $boundary = $db->value('SELECT idr FROM {novinky_revize} WHERE idc = ? ORDER BY idr DESC LIMIT 1 OFFSET 20', [$previous['idc']]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {novinky_revize} WHERE idc = ? AND idr <= ?', [$previous['idc'], $boundary]);
        }
    }

    /** Tags written with commas (at most 20); unknown ones are created – admin and MCP. */
    public static function tags(\Kaleta\Core\Db $db, int $idc, string $input): void
    {
        $db->delete('novinky_stitky', ['idc' => $idc]);
        $names = array_unique(array_filter(array_map(fn (string $n): string => mb_substr(trim($n), 0, 80), explode(',', $input))));
        foreach (array_slice($names, 0, 20) as $name) {
            $seo = slugify($name, 90);
            $ids = $db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
            $ids = $ids !== null ? (int) $ids : $db->insert('stitky', ['nazev' => $name, 'seo_link' => $seo]);
            $db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        }
    }

    /** Loads a news item only if the signed-in user can manage it. A news item in the trash only with $fromTrash (restore, permanent deletion). */
    private function load(int $id, bool $fromTrash = false): ?array
    {
        $newsItem = $this->db->one('SELECT * FROM {novinky} WHERE idc = ? AND smazano IS ' . ($fromTrash ? 'NOT NULL' : 'NULL'), [$id]);
        $authors = $this->app->auth()->managedAuthors();

        return $newsItem === null || ($authors !== null && !in_array((int) $newsItem['autor'], $authors, true)) ? null : $newsItem;
    }

    /** A free news slug: another news item in the trash keeps its own; per language version with slugs_per_language (3.9, Core\Slug). */
    private function findFreeSlug(string $seo, int $idc, string $language): string
    {
        return \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => \Kaleta\Core\Slug::taken($this->db, 'novinky', $a, $language, $idc));
    }

    /** Value from <input type="datetime-local"> -> DATETIME; empty or invalid = null. */
    private static function parseFormDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16));

        return $dt === false ? null : $dt->format('Y-m-d H:i:00');
    }
}
