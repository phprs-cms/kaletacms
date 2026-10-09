<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Site pages: home, About us, Services, Contact, Privacy policy… The home page is set in Nastavení → Základní (Settings → General).
 * A page has the URL /<seo_link>. The content is either text from the editor, or a build from the builder (column stavba, the draft stavba_koncept).
 */
final class Pages extends Module
{
    use \Kaleta\Admin\BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'pages';
    public const string NAME = 'Pages';
    public const string GROUP = 'Content';
    public const string ICON = 'stranky';

    /** Slugs that belong to the system and a page cannot have. */
    public const array RESERVED_SLUGS = ['novinky', 'hledani', 'news', 'search', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'stav', 'ulohy', 'souhlas', 'formular', 'popup', 'vitals', 'konverze', 'download', 'screen', 'og', '_report',
        // 3.7: the subscription endpoint and the English system paths (Core\Routes::SYSTEM_PATHS); a page that has one of them
        // from before keeps it (the slug check below lets an unchanged slug through)
        'odber', 'tasks', 'subscription', 'form', 'consent', 'conversion'];

    /** Pages in the trash last this many days, then they are deleted permanently (like news). */
    public const int TRASH_DAYS = 30;

    /**
     * May a page not have the slug: a word of the system (RESERVED_SLUGS, with the English system paths of 3.7), a language
     * code, or the custom news URL? The one check of every place that creates a page or changes its slug – the form, MCP,
     * the page import, a restore from the trash, the site import, the WordPress and web importers, a copy (3.7, N37-2).
     * A subpage (parent/slug) never collides. A page that holds a word from before 3.7 keeps it only while its slug stays
     * as stored (the callers let an unchanged slug through, Core\Routes::heldSystemPaths); a restored or imported one does not.
     */
    public static function slugReserved(string $slug, ?\Kaleta\Core\Db $db): bool
    {
        return !str_contains($slug, '/') && (in_array($slug, self::RESERVED_SLUGS, true) || isset(\Kaleta\Core\Language::AVAILABLE[$slug]) || \Kaleta\Core\Routes::isNewsSlug($slug, $db));
    }

    /**
     * A free page slug made from $base (o-nas, o-nas-2…): no other page has it – one in the trash keeps its slug reserved;
     * with slugs per language (3.9, Core\Slug) only a page of the same language version counts – and it is not reserved
     * (slugReserved, in every language), unless it is the page's own stored slug. $id = the page itself (0 = a new one).
     */
    public static function freeSlug(\Kaleta\Core\Db $db, string $base, int $id = 0, string $stored = '', int $max = 120, string $language = ''): string
    {
        return \Kaleta\Core\Slug::makeUnique($base, fn (string $a): bool => ($a !== $stored && self::slugReserved($a, $db))
            || \Kaleta\Core\Slug::taken($db, 'stranky', $a, $language, $id), $max);
    }

    protected function actionList(): Response
    {
        $trash = $this->request->get('stav') === 'kos';
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();
        $where = [$trash ? 'smazano IS NOT NULL' : 'smazano IS NULL'];
        $params = [];
        if ($column !== null) {
            $where[] = 'jazyk = ?';
            $params[] = $column;
        }
        if ($search !== '') {
            $where[] = '(titulek LIKE ? OR seo_link LIKE ?)';
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $pattern, $pattern);
        }

        $pages = $this->db->all('SELECT * FROM {stranky} WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($trash ? 'smazano DESC' : 'jazyk, poradi, titulek'), $params);

        // column "V navigaci" (In navigation): with a custom menu by the menu items (the page or a link to its URL), otherwise the flag v_menu
        foreach ($pages as &$s) {
            $s['v_menu'] = \Kaleta\Core\Menu::hasPage($this->db, (int) $s['ids'], (string) $s['jazyk'], (string) $s['seo_link']) ?? (bool) $s['v_menu'];
        }
        unset($s);

        return $this->view('list', 'Pages', [
            'pages' => $trash || $search !== '' ? $pages : self::sortAsTree($pages),
            'trash' => $trash, 'search' => $search, 'siteLanguages' => $siteLanguages, 'language' => $language,
            'comments' => $trash ? [] : \Kaleta\Core\DraftComments::unresolvedCounts($this->db), // unresolved comments from shared previews (2.15)
            'inTrash' => (int) $this->db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NOT NULL'),
        ]);
    }

    /**
     * Pages sorted as a tree: a subpage right after its parent, with the depth (key "uroven") for indentation in the list.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private static function sortAsTree(array $pages): array
    {
        $byParent = [];
        foreach ($pages as $s) {
            $byParent[(int) ($s['nadrazena'] ?? 0)][] = $s;
        }
        $ids = array_column($pages, 'ids');
        $result = [];
        $add = function (int $parent, int $level) use (&$add, &$result, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $s) {
                $result[] = $s + ['uroven' => $level];
                if ($level < 4) {
                    $add((int) $s['ids'], $level + 1);
                }
            }
        };
        $add(0, 0);
        // pages whose parent is in the trash or does not exist are shown at the top level
        foreach ($byParent as $parent => $children) {
            if ($parent !== 0 && !in_array($parent, array_map('intval', $ids), true)) {
                foreach ($children as $s) {
                    $result[] = $s + ['uroven' => 0];
                }
            }
        }

        return $result;
    }

    protected function actionNew(): Response
    {
        // from the translation overview (2.14): the language version and the original are filled in
        $language = \Kaleta\Core\Language::column($this->app->settings(), $this->request->get('jazyk'));
        $original = $language !== '' ? $this->db->one("SELECT ids, titulek, popis FROM {stranky} WHERE ids = ? AND jazyk = '' AND smazano IS NULL", [$this->request->getInt('preklad_z')]) : null;

        return $this->form(['ids' => 0, 'seo_link' => '', 'titulek' => $original['titulek'] ?? '', 'popis' => $original['popis'] ?? '', 'seo_titulek' => '', 'obrazek' => '', 'noindex' => 0, 'text' => '', 'zobrazit' => 1, 'v_menu' => 1, 'poradi' => 100, 'stavba' => null, 'stavba_koncept' => null,
            'nadrazena' => $this->request->getInt('nadrazena') ?: null, 'zverejnit_od' => null, 'valid_until' => null, 'review_by' => null, 'jazyk' => $language, 'preklad_z' => $original['ids'] ?? null]);
    }

    /** Translation overview (2.14, Core\Translations): what is missing or older than the original in each language version. */
    protected function actionTranslations(): Response
    {
        $auth = $this->app->auth();

        return $this->view('translations', 'Translations', \Kaleta\Core\Translations::matrix($this->app) + [
            'assistant' => (new \Kaleta\Core\Assistant($this->app->settings()))->isReady(),
            'news' => $auth->hasModule('news'), 'collections' => $auth->hasModule('collections'),
        ]);
    }

    /** Actions the list does with ticked pages (2.14): show, hide, language version, trash. Each page is checked as if edited alone. */
    protected function actionBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $action = $this->request->post('provest');
        if (!in_array($action, ['zobrazit', 'skryt', 'jazyk', 'kos'], true)) {
            return $this->back('Unknown action.', '', [], 'chyba');
        }
        $auth = $this->app->auth();
        $home = $this->app->settings()->int('home_page');
        $language = \Kaleta\Core\Language::column($this->app->settings(), $this->request->post('jazyk'));
        $done = 0;
        $skipped = 0;
        $clashes = 0; // 3.9: with slugs per language a page cannot move where another page of that version has its slug
        foreach (array_unique(array_map(intval(...), $this->request->postList('oznacene'))) as $id) {
            $page = $this->loadPage($id);
            // authors may only touch hidden pages and never publish; the home page stays visible and out of the trash
            if ($page === null || (!$auth->canPublish() && ($page['zobrazit'] || $action === 'zobrazit')) || ($id === $home && in_array($action, ['skryt', 'kos'], true))) {
                $skipped++;
                continue;
            }
            if ($action === 'jazyk' && $language !== $page['jazyk'] && \Kaleta\Core\Slug::taken($this->db, 'stranky', (string) $page['seo_link'], $language, $id)) {
                $clashes++;
                continue;
            }
            match ($action) {
                'zobrazit' => $this->db->update('stranky', ['zobrazit' => 1, 'show_on_publish' => 0, 'zverejnit_od' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $id]),
                'skryt' => $this->db->update('stranky', ['zobrazit' => 0, 'show_on_publish' => 0, 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $id]),
                'jazyk' => $this->db->update('stranky', ['jazyk' => $language, 'preklad_z' => $language === '' ? null : $page['preklad_z'], 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $id]),
                default => $this->db->run('UPDATE {stranky} SET smazano = NOW(), zobrazit = 0, show_on_publish = 0 WHERE ids = ?', [$id]),
            };
            \Kaleta\Admin\ChangeLog::write($this->app, 'pages', 'bulk ' . ['zobrazit' => 'shown', 'skryt' => 'hidden', 'jazyk' => 'language ' . ($language ?: 'default'), 'kos' => 'moved to trash'][$action], mb_substr($page['titulek'], 0, 80));
            $done++;
        }
        if ($done > 0) {
            \Kaleta\Front\Cache::clear();
        }
        $message = match ($action) {
            'zobrazit' => t('Pages published: %d.', $done), 'skryt' => t('Pages hidden: %d.', $done),
            'jazyk' => t('Pages moved to the language version: %d.', $done), default => t('Pages moved to the trash: %d.', $done),
        };

        return $this->back($message . ($skipped > 0 ? ' ' . t('Skipped: %d (no permission, or the home page).', $skipped) : '')
            . ($clashes > 0 ? ' ' . t('Skipped: %d (that language version already has the address).', $clashes) : ''), '', [], $done > 0 ? 'ok' : 'chyba');
    }

    protected function actionEdit(): Response
    {
        $page = $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$this->request->getInt('id')]);

        return $page === null ? $this->error('Page does not exist.', 404) : $this->form($page);
    }

    /** Saving from editing "directly on the site" (views/front/upravit.php): only the page's name and text. */
    protected function actionSaveText(): Response
    {
        $r = $this->request;
        $page = $r->isPost() ? $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$r->postInt('id')]) : null;
        if ($page === null || (!$this->app->auth()->canPublish() && $page['zobrazit'])) {
            return $this->redirectToSite($r->post('zpet'));
        }
        $title = mb_substr($r->post('titulek'), 0, 200);
        if ($title === '') {
            return $this->redirectToSite($r->post('zpet'), '?upravit=text&chyba=1');
        }
        try {
            $text = \Kaleta\Core\Html::forUserOrFail($r->post('text'), $this->app->auth(), 'text');
        } catch (\Kaleta\Core\HtmlTooLarge) {
            return $this->redirectToSite($r->post('zpet'), '?upravit=text&chyba=limit'); // over a limit of Core\HtmlLimits: nothing saved
        }
        if ($page['titulek'] !== $title || (string) $page['text'] !== $text) {
            $this->saveVersion((int) $page['ids'], $page['titulek'], (string) $page['text']); // an edit directly on the site goes to the history as in the admin
        }
        $this->db->update('stranky', ['titulek' => $title, 'text' => $text, 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $page['ids']]);
        \Kaleta\Admin\ChangeLog::write($this->app, 'pages', 'edited directly on the site', mb_substr($title, 0, 80));

        return $this->redirectToSite($r->post('zpet'));
    }

    /**
     * Roles at the author level (custom roles without the permission to publish too) can prepare pages, but not publish them,
     * change published ones or delete them. Returns a response with the refusal, or null when allowed.
     */
    private function requirePublishPermission(?array $page = null): ?Response
    {
        if ($this->app->auth()->canPublish() || ($page !== null && !$page['zobrazit'])) {
            return null;
        }

        return $this->back('Only an editor or administrator edits, publishes and deletes published pages. You can prepare a new hidden page.', '', [], 'chyba');
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $id = $r->postInt('ids');
        if ($id > 0 && ($refusal = $this->requirePublishPermission($this->db->one('SELECT zobrazit FROM {stranky} WHERE ids = ?', [$id]) ?? ['zobrazit' => 1])) !== null) {
            return $refusal;
        }
        $language = \Kaleta\Core\Language::column($this->app->settings(), $r->post('jazyk'));
        // parent page: the same language, not the page itself nor its subpage (otherwise a cycle would form)
        $custom = $id > 0 ? (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$id]) : '';
        $parent = $r->postInt('nadrazena') > 0 ? $this->db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ? AND ids <> ? AND jazyk = ? AND smazano IS NULL', [$r->postInt('nadrazena'), $id, $language]) : null;
        if ($parent !== null && $custom !== '' && str_starts_with($parent['seo_link'] . '/', $custom . '/')) {
            $parent = null;
        }
        $prefix = $parent !== null ? $parent['seo_link'] . '/' : '';
        $slug = slugify($r->post('seo_link') !== '' ? basename(str_replace('\\', '/', $r->post('seo_link'))) : $r->post('titulek'), max(20, 118 - strlen($prefix)));
        $textError = null;
        try {
            $text = \Kaleta\Core\Html::forUserOrFail($r->post('text'), $this->app->auth(), 'text');
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            $text = $r->post('text'); // only shown again in the form (escaped), never saved
            $textError = $e->localized();
        }
        $data = [
            'titulek' => mb_substr($r->post('titulek'), 0, 200),
            'seo_link' => $prefix . $slug,
            'nadrazena' => $parent !== null ? (int) $parent['ids'] : null,
            'popis' => mb_substr($r->post('popis'), 0, 300),
            'seo_titulek' => mb_substr(trim($r->post('seo_titulek')), 0, 200),
            'obrazek' => mb_substr(trim($r->post('obrazek')), 0, 255),
            'noindex' => (int) $r->postBool('noindex'),
            'text' => $text,
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'v_menu' => (int) $r->postBool('v_menu'),
            'poradi' => max(0, min(65535, $r->postInt('poradi', 100))),
            'zmeneno' => date('Y-m-d H:i:s'),
            'jazyk' => $language,
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')),
            'review_by' => \Kaleta\Core\Validity::date($r->post('review_by')),
        ];
        if ($this->app->auth()->isAdmin() && !\Kaleta\Core\Demo::active()) {
            // code in <head> of this page only (2.3); never in the public demo – administrators, like the code for the whole site
            $data['kod_hlavicky'] = trim((string) ($_POST['kod_hlavicky'] ?? '')) !== '' ? mb_substr((string) $_POST['kod_hlavicky'], 0, 20000) : null;
        }
        // scheduled publishing: only for a hidden page with a future time; a past time publishes the page right away
        $from = strtotime(str_replace('T', ' ', $r->post('zverejnit_od'))) ?: null;
        $data['zverejnit_od'] = !$data['zobrazit'] && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null;
        if (!$data['zobrazit'] && $from !== null && $from <= time()) {
            $data['zobrazit'] = 1;
        }
        if (!$this->app->auth()->canPublish()) {
            $data['zobrazit'] = 0; // without the permission to publish the page stays hidden, an editor publishes it
            $data['zverejnit_od'] = null;
        }
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        // a page with nothing to show yet stays hidden until its build is published (3.5): a template, the builder, or no text
        $stored = $id > 0 ? $this->db->one('SELECT zobrazit, stavba FROM {stranky} WHERE ids = ?', [$id]) : null;
        $data = self::visibility((bool) $data['zobrazit'], (bool) ($stored['zobrazit'] ?? false), self::hasContent(['stavba' => $stored['stavba'] ?? null, 'text' => $data['text']])) + $data;
        $errors = $textError !== null ? ['text' => $textError] : [];
        if ($data['titulek'] === '') {
            $errors['titulek'] = 'Enter the page title.';
        }
        $storedSlug = $id > 0 ? $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$id]) : null;
        if ($r->post('seo_link') === '') {
            // slug from the name: a taken or reserved one gets a number (o-nas-2), as with news
            $data['seo_link'] = self::freeSlug($this->db, $data['seo_link'], $id, (string) $storedSlug, language: $language);
        }
        [$sameLanguage, $languageParams] = \Kaleta\Core\Slug::scope($this->db, $language);
        if ($parent === null && $data['seo_link'] !== $storedSlug && self::slugReserved($data['seo_link'], $this->db)) {
            $errors['seo_link'] = 'This URL is used by the system, choose another one.';
        } elseif (($other = $this->db->one('SELECT ids, smazano FROM {stranky} WHERE seo_link = ? AND ids <> ?' . $sameLanguage, [$data['seo_link'], $id, ...$languageParams])) !== null) {
            $errors['seo_link'] = $other['smazano'] !== null ? 'A page in the trash uses this address – restore it or delete it permanently.' : 'A page with this URL already exists.';
        }
        // the page password (2.14, Core\PageLock): empty = unchanged, a tick removes it; only its hash is stored
        [$passwordHash, $passwordError] = \Kaleta\Core\PageLock::fromForm($r->post('heslo_stranky'), $r->postBool('heslo_zrusit'));
        if ($passwordError !== '') {
            $errors['heslo_stranky'] = $passwordError;
        } elseif ($passwordHash !== null) {
            $data['heslo_hash'] = $passwordHash === '' ? null : $passwordHash;
        }
        if ($id > 0 && $id === $this->app->settings()->int('home_page') && !$data['zobrazit']) {
            $errors['zobrazit'] = 'The home page cannot be hidden. First choose another home page in Settings → General.';
        }
        if ($errors !== []) {
            $previous = $id > 0 ? $this->db->one('SELECT stavba, stavba_koncept, heslo_hash FROM {stranky} WHERE ids = ?', [$id]) : null;

            return $this->form(['ids' => $id] + $data + ($previous ?? ['stavba' => null, 'stavba_koncept' => null]), $errors);
        }
        if ($id > 0) {
            $previous = $this->db->one('SELECT seo_link, zobrazit, titulek, text, jazyk FROM {stranky} WHERE ids = ?', [$id]);
            if ($previous !== null && ($previous['text'] !== $data['text'] || $previous['titulek'] !== $data['titulek'])) {
                $this->saveVersion($id, $previous['titulek'], (string) $previous['text']);
            }
            $this->db->update('stranky', $data, ['ids' => $id]);
            if ($previous !== null && $previous['seo_link'] !== $data['seo_link']) {
                self::move($this->db, $previous['seo_link'], $data['seo_link'], (bool) $previous['zobrazit'], $language);
            }
        } else {
            $id = $this->db->insert('stranky', $data);
            $template = \Kaleta\Builder\Library::PAGE_TEMPLATES[$r->post('sablona')] ?? null;
            if ($template !== null && $template[1] !== []) {
                // new page from a template: sections from the library as a draft and straight into the builder
                $build = \Kaleta\Builder\Library::page($this->db, $template[1], $data['titulek'], $this->contentLanguage($language));
                $this->db->update('stranky', ['stavba_koncept' => Build::toJson($build)], ['ids' => $id]);
                \Kaleta\Core\Menu::setPage($this->db, $id, $language, (bool) $data['v_menu']);

                return \Kaleta\Core\Response::redirect($this->url('builder', ['id' => $id]));
            }
            if ($template !== null && $data['text'] === '') {
                // in the page's language, from what the site has switched on (1.9); with the text it may be shown as chosen
                $text = \Kaleta\Core\Language::runWith($this->contentLanguage($language), fn (): string => \Kaleta\Builder\Library::privacyPolicyText($this->app->settings()));
                $this->db->update('stranky', ['text' => $text] + ($data['show_on_publish'] ? ['zobrazit' => 1, 'show_on_publish' => 0] : []), ['ids' => $id]);

                return $this->back('The page has been created with a privacy policy outline – fill in the details in square brackets.', 'edit', ['id' => $id]);
            }
        }
        // assembled menu (Vzhled → Menu, Appearance → Menu): the checkbox "v navigaci" (in navigation) adds the page to the menu or removes it
        \Kaleta\Core\Menu::setPage($this->db, $id, $data['jazyk'], (bool) $data['v_menu']);
        if ($r->post('po_ulozeni') === 'stavitel') {
            return \Kaleta\Core\Response::redirect($this->url('builder', ['id' => $id]));
        }

        return $this->back($data['show_on_publish'] ? 'Page saved. It stays hidden until it has content – add text or publish it in the builder, and it goes on the site.' : 'Page saved.');
    }

    /**
     * Whether a page has something a visitor can see: a published build, or text (an image or an embed counts).
     *
     * @param array<string, mixed> $page stavba, text
     */
    public static function hasContent(array $page): bool
    {
        return ($page['stavba'] ?? null) !== null || trim(strip_tags((string) ($page['text'] ?? ''), '<img><iframe><video><audio><object><embed>')) !== '';
    }

    /**
     * The visibility of a page being saved (3.5): a page that is not on the site yet and has nothing to show is not made
     * visible – a new page from a template, or one opened in the builder before it has text, would be live and in the
     * navigation empty. The wish is kept (show_on_publish) and applied by the first published build (Builder\Publisher::page)
     * or the first save with text. A visible page stays visible, and hiding a page always works.
     *
     * @return array{zobrazit: int, show_on_publish: int}
     */
    public static function visibility(bool $show, bool $visibleNow, bool $hasContent): array
    {
        return $show && !$visibleNow && !$hasContent ? ['zobrazit' => 0, 'show_on_publish' => 1] : ['zobrazit' => (int) $show, 'show_on_publish' => 0];
    }

    /* ---------- builder (actions in Admin\BuilderActions) ---------- */

    /** Editor; a text page is converted to a build on first opening (a narrow section with a heading and the text, the text stays). */
    protected function actionBuilder(): Response
    {
        $page = $this->loadPage($this->request->getInt('id'));
        if ($page !== null && $page['stavba'] === null && $page['stavba_koncept'] === null) {
            $this->db->update('stranky', ['stavba_koncept' => Build::toJson(Build::fromText($page['titulek'], (string) $page['text']))], ['ids' => $page['ids']]);
        }

        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $page = $this->loadPage($this->request->getInt('id'));

        return $page === null ? null : [
            'radek' => $page, 'stavba' => $page['stavba'], 'koncept' => $page['stavba_koncept'], 'jazyk' => $this->contentLanguage($page['jazyk']),
            'titulek' => $page['titulek'], 'revize' => ['ids' => (int) $page['ids']], 'parametry' => ['id' => (int) $page['ids']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('stranky', ['stavba_koncept' => $draft], ['ids' => $target['radek']['ids']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::page($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $page = $target['radek'];
        $home = $this->app->settings()->int('home_page') === (int) $page['ids'];
        $url = $this->app->url(($page['jazyk'] !== '' ? $page['jazyk'] . '/' : '') . ($home ? '' : $page['seo_link']));

        return [
            'adresa' => $url, 'nahled' => $url . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $page['zobrazit'], 'casti' => false, 'nadpisy' => true,
            'poPublikovani' => (bool) ($page['show_on_publish'] ?? false), // hidden until the build is published, then shown (3.5)
            'zpet' => ['adresa' => $this->url(), 'text' => t('Pages')], 'nastaveni' => $this->url('edit', ['id' => (int) $page['ids']]),
            'podpis' => 'stranka:' . (int) $page['ids'],
        ];
    }

    /** Publishes the page draft (from MCP too). */
    public static function publish(\Kaleta\Core\App $app, array $page): void
    {
        Publisher::page($app, $page);
    }

    /** The page returns to the text from the editor (the build stays in the versions). */
    protected function actionBuildText(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->request->postInt('ids')) : null;
        if ($page !== null && $page['stavba'] !== null) {
            $this->db->insert('stavba_revize', ['ids' => $page['ids'], 'datum' => date('Y-m-d H:i:s'), 'kdo' => $this->app->auth()->id(), 'stavba' => $page['stavba']]);
            $this->db->update('stranky', ['stavba' => null, 'stavba_koncept' => null], ['ids' => $page['ids']]);
        }

        return $this->back('The page shows the text from the editor (the build\'s content without the layout). You will find the build in versions when you open the builder.', 'edit', ['id' => (int) ($page['ids'] ?? 0)]);
    }

    /** @return array<string, mixed>|null */
    private function loadPage(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
    }

    /** The previous form of the page text to the history (the last 30 versions). */
    private function saveVersion(int $ids, string $title, string $text): void
    {
        self::version($this->db, $ids, $this->app->auth()->id(), $title, $text);
    }

    /** The same for MCP and other inputs outside the module. */
    public static function version(\Kaleta\Core\Db $db, int $ids, int $who, string $title, string $text): void
    {
        $db->insert('stranky_revize', ['ids' => $ids, 'datum' => date('Y-m-d H:i:s'), 'kdo' => $who, 'titulek' => $title, 'text' => $text]);
        $db->run('DELETE FROM {stranky_revize} WHERE ids = ? AND idr NOT IN (SELECT idr FROM (SELECT idr FROM {stranky_revize} WHERE ids = ? ORDER BY idr DESC LIMIT 30) t)', [$ids, $ids]);
    }

    /**
     * The page changed its slug: subpages move with it and the old URLs of visible pages are redirected. With slugs per
     * language (3.9) only the subpages of its own language version move – /en/sluzby/web is not a subpage of /sluzby – and
     * the redirects carry the language prefix (Core\Slug::redirectPath).
     */
    public static function move(\Kaleta\Core\Db $db, string $old, string $newVersion, bool $visible, string $language = ''): void
    {
        $redirect = fn (string $path): string => \Kaleta\Core\Slug::redirectPath($db, $path, $language);
        if ($visible) {
            Redirects::add($db, $redirect($old), $redirect($newVersion));
        }
        [$sameLanguage, $languageParams] = \Kaleta\Core\Slug::scope($db, $language);
        foreach ($db->all('SELECT ids, seo_link, zobrazit FROM {stranky} WHERE seo_link LIKE ?' . $sameLanguage, [addcslashes($old, '%_\\') . '/%', ...$languageParams]) as $p) {
            $target = $newVersion . substr($p['seo_link'], strlen($old));
            $db->update('stranky', ['seo_link' => $target], ['ids' => $p['ids']]);
            if ($p['zobrazit']) {
                Redirects::add($db, $redirect($p['seo_link']), $redirect($target));
            }
        }
    }

    /** Restoring an older version of the page text (the current form goes to the history). */
    protected function actionRestoreVersion(): Response
    {
        $version = $this->request->isPost() ? $this->db->one('SELECT * FROM {stranky_revize} WHERE idr = ?', [$this->request->postInt('idr')]) : null;
        $page = $version !== null ? $this->loadPage((int) $version['ids']) : null;
        if ($page === null) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission($page)) !== null) {
            return $refusal;
        }
        $this->saveVersion((int) $page['ids'], $page['titulek'], (string) $page['text']);
        $this->db->update('stranky', ['titulek' => $version['titulek'], 'text' => $version['text'], 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $page['ids']]);

        return $this->back(t('Version from %s restored.', format_date($version['datum'], true)), 'edit', ['id' => (int) $page['ids']]);
    }

    /**
     * The page as a JSON file – for transfer to another site running Kaleta. Version 2 (1.8) also carries the shared
     * classes and the components the build uses (Builder\PagePackage), so the page looks the same there.
     */
    protected function actionExport(): Response
    {
        $s = $this->loadPage($this->request->getInt('id'));
        if ($s === null) {
            return $this->error('Page does not exist.', 404);
        }
        $build = Build::fromJson($s['stavba_koncept'] ?? $s['stavba']);
        $json = (string) json_encode(['format' => 'kaleta-stranka', 'verze' => 2, 'titulek' => $s['titulek'], 'popis' => $s['popis'], 'text' => $s['text'],
            'stavba' => $build] + \Kaleta\Builder\PagePackage::collect($this->db, $build ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="stranka-' . basename(str_replace('/', '-', $s['seo_link'])) . '.json"']);
    }

    /** Import of a page from a JSON export: a hidden page is created, the build goes through the validator like any other. */
    protected function actionImport(): Response
    {
        $file = $_FILES['soubor']['tmp_name'] ?? '';
        $data = $this->request->isPost() && is_uploaded_file($file) && filesize($file) < 5_000_000 ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || ($data['format'] ?? '') !== 'kaleta-stranka' || trim((string) ($data['titulek'] ?? '')) === '') {
            return $this->back('The file is not a page export.', '', [], 'chyba');
        }
        $title = mb_substr(trim((string) $data['titulek']), 0, 200);
        // never a system address (3.7, N37-2): a page "Subscription" would take over /subscription, so it gets subscription-2
        $wanted = slugify($title, 110);
        try {
            $text = \Kaleta\Core\HtmlLimits::guard(fn (): string => \Kaleta\Core\WpContent::safeHtml((string) ($data['text'] ?? '')));
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            return $this->back(t('The page was not imported: %s', $e->localized()), '', [], 'chyba');
        }
        $record = ['titulek' => $title, 'seo_link' => self::freeSlug($this->db, $wanted), 'popis' => mb_substr((string) ($data['popis'] ?? ''), 0, 300),
            'text' => $text, 'zobrazit' => 0, 'v_menu' => 0, 'zmeneno' => date('Y-m-d H:i:s')];
        $created = ['tridy' => 0, 'komponenty' => 0];
        if (is_array($data['stavba'] ?? null)) {
            // the classes and components that came with it first: the build then points at this site's components
            [$pageBuild, $created] = \Kaleta\Builder\PagePackage::import($this->app->settings(), $data, $data['stavba'], $this->app->auth()->isAdmin());
            [$build, $errors] = Build::sanitize($pageBuild, $this->app->auth()->isAdmin());
            $record['stavba_koncept'] = Build::toJson($build);
        }
        $id = $this->db->insert('stranky', $record);
        $extra = ($data['tridy'] ?? []) !== [] || ($data['komponenty'] ?? []) !== [];
        $renamed = self::slugReserved($wanted, $this->db) ? ' ' . t('The address %s is used by the system, so the page got %s.', '/' . $wanted, '/' . $record['seo_link']) : '';

        return $this->back(match (true) {
            $extra && !$this->app->auth()->isAdmin() => t('The page has been imported as hidden – check it and publish it.') . ' ' . t('Its classes and components were not imported – only an administrator can add them.'),
            $extra => t('The page has been imported as hidden – check it and publish it.') . ' ' . t('New classes: %d, new components: %d (those the site already had were kept).', $created['tridy'], $created['komponenty']),
            default => t('The page has been imported as hidden – check it and publish it.'),
        } . $renamed, 'edit', ['id' => $id]);
    }

    /** Deleting = moving to the trash: the page disappears from the site, the slug stays reserved and the page can be restored. */
    protected function actionDelete(): Response
    {
        $ids = $this->request->postInt('ids');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($ids === $this->app->settings()->int('home_page')) {
            return $this->back('The home page cannot be deleted. First choose another home page in Settings → General.', '', [], 'chyba');
        }
        $this->db->run('UPDATE {stranky} SET smazano = NOW(), zobrazit = 0, show_on_publish = 0 WHERE ids = ? AND smazano IS NULL', [$ids]);

        return $this->back(t('The page is in the trash. You can restore it for %d days.', self::TRASH_DAYS));
    }

    /** Restore from the trash: the page returns hidden, only the user publishes it. */
    protected function actionRestore(): Response
    {
        if ($this->request->isPost()) {
            $ids = $this->request->postInt('ids');
            $old = (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
            $this->db->run('UPDATE {stranky} SET smazano = NULL WHERE ids = ?', [$ids]);
            if (($slug = self::freeRestoredSlug($this->db, $ids)) !== null) {
                return $this->back(\Kaleta\Core\Routes::isNewsSlug($old, $this->db)
                    ? t('The page has been restored with the address %s, because the news now uses its old address – publish it in its settings.', '/' . $slug)
                    : t('The page has been restored with the address %s, because its old address %s is used by the system – publish it in its settings.', '/' . $slug, '/' . $old));
            }
        }

        return $this->back('The page has been restored as hidden – publish it in its settings.');
    }

    /**
     * A page back from the trash must not hide behind the news or a system address: when the custom news URL (Settings →
     * General, news_slug) took its slug meanwhile, or the slug is a word of the system (a page "form" from before 3.7 –
     * while it was in the trash the system took the English address back, 3.7 N37-2), the page and its subpages get a free
     * one (blog-2, form-2). Returns the new slug, null = unchanged.
     */
    public static function freeRestoredSlug(\Kaleta\Core\Db $db, int $ids): ?string
    {
        $page = $db->one('SELECT seo_link, jazyk FROM {stranky} WHERE ids = ?', [$ids]);
        [$slug, $language] = [(string) ($page['seo_link'] ?? ''), (string) ($page['jazyk'] ?? '')];
        if (!self::slugReserved($slug, $db)) {
            return null;
        }
        $free = \Kaleta\Core\Slug::makeUnique($slug . '-2', fn (string $a): bool => self::slugReserved($a, $db) || \Kaleta\Core\Slug::taken($db, 'stranky', $a, $language, $ids)
            || $db->value('SELECT 1 FROM {kolekce} WHERE seo_link = ?', [$a]) !== null, 120);
        $db->update('stranky', ['seo_link' => $free], ['ids' => $ids]);
        self::move($db, $slug, $free, false, $language); // the old address belongs to the news, so it gets no redirect

        return $free;
    }

    protected function actionDeletePermanently(): Response
    {
        if (($refusal = $this->requirePublishPermission()) !== null) {
            return $refusal;
        }
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {stranky} WHERE ids = ? AND smazano IS NOT NULL', [$this->request->postInt('ids')]);
        }

        return $this->back('The page has been permanently deleted.', '', ['stav' => 'kos']);
    }

    /** Pages in the trash longer than TRASH_DAYS are deleted permanently (called by Admin\Kernel). */
    public static function emptyTrash(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {stranky} WHERE smazano < NOW() - INTERVAL ' . self::TRASH_DAYS . ' DAY')->rowCount();
    }

    /** Copy of the page including the build and the work-in-progress draft – hidden, with a free slug. */
    protected function actionDuplicate(): Response
    {
        $page = $this->request->isPost() ? $this->loadPage($this->request->postInt('ids')) : null;
        if ($page === null) {
            return $this->back();
        }
        $copy = array_diff_key($page, ['ids' => 0, 'smazano' => 0]);
        $copy['titulek'] = mb_substr(t('%s (copy)', $page['titulek']), 0, 200);
        $copy['seo_link'] = self::freeSlug($this->db, mb_substr($page['seo_link'] . '-kopie', 0, 110), language: (string) $page['jazyk']);
        $copy['zobrazit'] = 0;
        $copy['show_on_publish'] = 0;
        $copy['v_menu'] = 0; // the copy does not get into the navigation until someone adds it there
        $copy['preklad_z'] = null;
        $copy['zmeneno'] = date('Y-m-d H:i:s');
        $id = $this->db->insert('stranky', $copy);

        return $this->back('The copy of the page is hidden – edit it and publish it.', 'edit', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $errors
     */
    private function form(array $page, array $errors = []): Response
    {
        $language = (string) ($page['jazyk'] ?? '');
        $custom = (string) ($page['seo_link'] ?? '');

        return $this->view('form', $page['ids'] ? 'Edit page' : 'New page', [
            'page' => $page, 'errors' => $errors,
            // possible parent pages: the same language, not the page itself nor its subpages
            'parents' => array_values(array_filter($this->db->all('SELECT ids, titulek, seo_link FROM {stranky} WHERE jazyk = ? AND smazano IS NULL AND ids <> ? ORDER BY seo_link', [$language, (int) $page['ids']]),
                fn (array $s): bool => $custom === '' || !str_starts_with($s['seo_link'] . '/', $custom . '/'))),
            'versions' => $page['ids'] ? $this->db->all('SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = \'\', u.user, u.jmeno) AS kdo FROM {stranky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.ids = ? ORDER BY r.idr DESC LIMIT 30', [(int) $page['ids']]) : [],
            'home' => $page['ids'] > 0 && (int) $page['ids'] === $this->app->settings()->int('home_page'),
            'inMenu' => $page['ids'] > 0 ? \Kaleta\Core\Menu::hasPage($this->db, (int) $page['ids'], (string) ($page['jazyk'] ?? '')) : null,
            'customMenu' => \Kaleta\Core\Menu::load($this->db, 'hlavni', (string) ($page['jazyk'] ?? '')) !== null,
            // content check of the saved version (2.14, Core\ContentCheck); a page not saved yet has nothing to check
            'contentCheck' => $page['ids'] > 0 && !$this->request->isPost() ? \Kaleta\Core\ContentCheck::forPage($page) : [],
        ]);
    }
}
