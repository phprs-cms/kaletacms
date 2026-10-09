<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Language;
use Kaleta\Core\Preview;
use Kaleta\Core\Response;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\ElementClipboard;
use Kaleta\Builder\Style;

/**
 * Builder actions shared by pages (Modules\Pages) and site parts (Modules\SiteParts): editor, autosaving the draft,
 * publishing, discarding changes, versions, sections from the library and shared classes. The module supplies what the
 * build "target" is and how to save it.
 *
 * Target: ['radek' => database row, 'stavba' => ?string, 'koncept' => ?string, 'jazyk' => content code, 'titulek' => string,
 *         'revize' => ['ids' => …] | ['cast' => …], 'parametry' => parameters of action URLs (id, or type and language)]
 */
trait BuilderActions
{
    /** @return array<string, mixed>|null the target from the request parameters */
    abstract protected function loadBuildTarget(): ?array;

    /** Saves the work-in-progress draft (null = discard). */
    abstract protected function saveDraft(array $target, ?string $draft): void;

    abstract protected function publishTarget(array $target): void;

    /**
     * Details for the editor: titulek, adresa (public), nahled (canvas), zobrazena, casti (offer site part elements),
     * zpet [adresa, text], nastaveni (URL of the target's settings, or null), podpis (target of the signed preview,
     * e.g. "stranka:12" – Core\Preview).
     *
     * @return array<string, mixed>
     */
    abstract protected function describeTarget(array $target): array;

    /** Full-screen build editor: canvas with the real site page, tree, properties. */
    protected function actionBuilder(): Response
    {
        $target = $this->loadBuildTarget();
        if ($target === null) {
            return $this->error('Page does not exist.', 404);
        }
        $app = $this->app;
        $e = $this->describeTarget($target);
        $collection = array_map(fn (array $k): array => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']], Collections::all($this->db));
        $extensions = \Kaleta\Core\Extensions::enabled($app->settings());
        $schema = Build::schema($app->auth()->isAdmin(), $target['jazyk'], $e['casti'], $extensions);
        $components = \Kaleta\Admin\Modules\Components::listForEditor($this->db);
        $header = str_starts_with((string) ($target['revize']['cast'] ?? ''), 'hlavicka:');
        foreach ($schema['prvky'] as &$element) {
            if ($element['typ'] === \Kaleta\Builder\Elements\Section::TYPE && !$header) {
                // 3.6 (UXA-12): the header options only where they work – in the header site part (Section::scrollMode);
                // a stored value stays in the build, only the editor does not offer it
                $element['vlastnosti'] = array_diff_key($element['vlastnosti'], array_flip(\Kaleta\Builder\Elements\Section::HEADER_ONLY));
            }
            if ($element['typ'] === 'komponenta') {
                $element['vlastnosti']['komponenta'] = ['typ' => 'vyber', 'popisek' => 'Component', 'vychozi' => '',
                    'moznosti' => ['' => '—'] + array_column(array_map(fn (array $k): array => ['id' => (string) $k['id'], 'nazev' => $k['nazev']], $components), 'nazev', 'id')];
            }
            if ($element['typ'] === 'kolekce') {
                // in the editor, a choice of the site's collections (the validator takes the collection slug as text)
                $element['vlastnosti']['kolekce'] = ['typ' => 'vyber', 'popisek' => 'Collections', 'vychozi' => $collection[0]['seo_link'] ?? '',
                    'moznosti' => ['' => '—'] + array_column($collection, 'nazev', 'seo_link')];
            }
        }
        unset($element);
        $schema = self::translateSchema($schema);
        foreach ($schema['prvky'] as &$element) {
            if ($element['typ'] === \Kaleta\Builder\Elements\Form::TYPE && isset($element['vlastnosti']['max_priloha'])) {
                // 3.7: the editor says what this server accepts – a larger limit than that never applies (Form::attachmentLimit)
                $server = \Kaleta\Core\Files::limit();
                $element['vlastnosti']['max_priloha']['popisek'] = $server > 0 && $server < \Kaleta\Builder\Elements\Form::HARD_MAX_ATTACHMENT_MB * 1048576
                    ? t('Attachment size limit per file in MB (1–25). This server accepts files up to %s, so a higher limit applies only up to that.', \Kaleta\Core\Files::limitText())
                    : t('Attachment size limit per file in MB (1–25). This server accepts files up to %s.', $server > 0 ? \Kaleta\Core\Files::limitText() : '25 MB');
            }
        }
        unset($element);
        Library::createClasses($this->db, ['karta']); // card pattern in the Collection list
        $data = [
            'stranka' => ['titulek' => $target['titulek'], 'adresa' => $e['adresa'], 'zobrazena' => $e['zobrazena'], 'publikovana' => $target['stavba'] !== null, 'smiPublikovat' => $app->auth()->canPublish(),
                'poPublikovani' => (bool) ($e['poPublikovani'] ?? false), // a new page waits hidden for its first publish (3.5)
                'nadpisy' => (bool) ($e['nadpisy'] ?? false)], // check before publishing: the page should have one h1 and not skip levels
            'stavba' => Build::fromJson($target['koncept'] ?? $target['stavba']),
            'zmeny' => $target['koncept'] !== null && $target['koncept'] !== $target['stavba'],
            'verze' => self::computeBuildVersion($target['koncept'] ?? $target['stavba']),
            'schema' => $schema,
            'kolekce' => $collection,
            'komponenty' => $components,
            'ai' => (new \Kaleta\Core\Assistant($app->settings()))->isReady(),
            'kolekceDetailu' => $e['kolekce'] ?? null,
            'knihovna' => Library::listAll($extensions),
            'kategorieKnihovny' => array_map(fn (string $k): string => t($k), Library::CATEGORIES),
            // language versions of the site for the display condition ('' = the default language, as the "jazyk" column has it)
            'jazyky' => [['kod' => '', 'nazev' => t('%s (default language)', Language::AVAILABLE[Language::defaults($app->settings())][0])],
                ...array_map(fn (string $c): array => ['kod' => $c, 'nazev' => Language::AVAILABLE[$c][0]], Language::additional($app->settings()))],
            'tridy' => $this->loadBuilderClasses(),
            'mojeSekce' => self::listMySections($this->db),
            'barvy' => DesignSystem::load($app->settings())['barvy'],
            // options for the link field: site pages (with the language prefix) and news; the editor adds anchors on the page
            'odkazy' => [...array_map(fn (array $s): array => ['/' . ($s['jazyk'] !== '' ? $s['jazyk'] . '/' : '') . ((int) $s['ids'] === $app->settings()->int('home_page') ? '' : $s['seo_link']), $s['titulek'] . ($s['zobrazit'] ? '' : ' (' . t('hidden') . ')')],
                $this->db->all('SELECT ids, titulek, seo_link, jazyk, zobrazit FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek LIMIT 300')), ['/' . \Kaleta\Core\Routes::publicPath('novinky', \Kaleta\Core\Language::defaults($app->settings()), $this->db), t('Novinky')]],
            'nahled' => $e['nahled'],
            'komentare' => $this->commentsForEditor((string) ($e['podpis'] ?? '')), // comments from shared previews (2.15); null = this target has none (components have no signed preview)
            'textNastaveni' => $e['textNastaveni'] ?? null, // label of the link to the target's settings (otherwise "Nastavení stránky" – Page settings)
            'zpet' => $e['zpet'],
            'adresy' => array_map(fn (string $action): string => $this->url($action, $target['parametry']), [
                'uloz' => 'build_save', 'publikuj' => 'build_publish', 'zahod' => 'build_discard', 'sekce' => 'build_section', 'trida' => 'build_class',
                'revize' => 'build_versions', 'obnov' => 'build_restore', 'aiSekce' => 'build_ai_section', 'aiText' => 'build_ai_text', 'ulozSekci' => 'build_save_section',
                'sdilet' => 'build_share', 'balicek' => 'build_package', 'vlozeni' => 'build_paste', 'komentarVyrizen' => 'build_comment_resolve',
            ]) + ['smazSekci' => $app->auth()->isAdmin() ? $this->url('build_delete_section', $target['parametry']) : null] + ['admin' => $app->url('admin.php'), 'nastaveni' => $e['nastaveni'],
                'komponenta' => $app->auth()->isAdmin() ? $app->url('admin.php?module=components&action=from_element') : null,
                'nahledSekce' => $app->url('_sekce/'),
                'navod' => \Kaleta\Admin\Guide::forScreen(static::IDENT, 'builder', '', \Kaleta\Core\Language::code())],
        ];

        return Response::html($app->view->render('admin/pages/builder', ['app' => $app, 'data' => $data, 'title' => $target['titulek']]));
    }

    /** Autosaving the draft from the editor (JSON). Returns the sanitized build and the errors the editor shows. */
    protected function actionBuildSave(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'chyba' => t('Page does not exist.')], 404);
        }
        $input = json_decode((string) ($_POST['stavba'] ?? ''), true);
        if (!is_array($input)) {
            return Response::json(['ok' => false, 'chyba' => t('The build is not valid JSON.')], 400);
        }
        if (($conflict = $this->checkVersionConflict($target)) !== null) {
            return $conflict;
        }
        [$build, $errors] = Build::sanitize($input, $this->app->auth()->isAdmin(), Build::fromJson($target['koncept'] ?? $target['stavba']));
        $json = Build::toJson($build);
        $this->saveDraft($target, $json);

        return Response::json(['ok' => true, 'stavba' => $build, 'chyby' => self::builderNotes($errors), 'zmeny' => $json !== $target['stavba'], 'verze' => self::computeBuildVersion($json)]);
    }

    /** Publishing: the draft becomes the build; the previous published version goes to the history. */
    protected function actionBuildPublish(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null || ($target['koncept'] ?? $target['stavba']) === null) {
            return Response::json(['ok' => false, 'chyba' => t('There is nothing to publish.')], 400);
        }
        if (!$this->app->auth()->canPublish()) {
            return Response::json(['ok' => false, 'chyba' => t('Only an editor or administrator can publish. Your changes stay saved as a draft.')], 403);
        }
        // only what the editor saved last is published – not an older draft, nor someone else's work-in-progress changes
        if (($conflict = $this->checkVersionConflict($target)) !== null) {
            return $conflict;
        }
        $this->publishTarget($target);
        ChangeLog::write($this->app, static::IDENT, 'build published', mb_substr($target['titulek'], 0, 80));
        $published = $this->loadBuildTarget();

        // whether the target is on the site now: a page that waited for its first publish is shown by it (3.5)
        return Response::json(['ok' => true, 'zobrazena' => $published !== null && (bool) $this->describeTarget($published)['zobrazena']]);
    }

    /**
     * Draft preview link for a colleague or a client: anyone can open it without signing in, it is valid only for this
     * target and the given number of days (1–7). It shows the draft at the moment of opening, not the state when the link
     * was created; search engines do not index it.
     */
    protected function actionBuildShare(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'chyba' => t('Page does not exist.')], 404);
        }
        $e = $this->describeTarget($target);
        $days = max(1, min(7, $this->request->postInt('dni', 7)));
        // "Allow comments" (2.15, Core\DraftComments): the flag is signed into the key; comments exist for page drafts
        $comments = $this->request->post('komentare') === '1' && \Kaleta\Core\DraftComments::parseTarget((string) ($e['podpis'] ?? '')) !== null;
        $key = Preview::key($this->db, $this->app->settings(), $e['podpis'], $days * 24 * 60, $comments);
        $url = str_replace('&editor=1', '', $e['nahled']);
        ChangeLog::write($this->app, static::IDENT, 'preview shared', mb_substr($target['titulek'], 0, 80) . ' (' . $days . ' d' . ($comments ? ', comments' : '') . ')');

        return Response::json(['ok' => true, 'odkaz' => $this->request->origin() . $url . '&preview_key=' . $key, 'plati_do' => time() + $days * 86400, 'komentare' => $comments]);
    }

    /**
     * One click resolves a comment from a shared preview (2.15, Core\DraftComments); the editor's panel gets the fresh list.
     * The comment must belong to this target – a comment id alone does not reach another page.
     */
    protected function actionBuildCommentResolve(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'chyba' => t('Page does not exist.')], 404);
        }
        $signature = (string) ($this->describeTarget($target)['podpis'] ?? '');
        $comment = \Kaleta\Core\DraftComments::find($this->db, $this->request->postInt('id'));
        if ($comment === null || $comment['target'] !== $signature) {
            return Response::json(['ok' => false, 'chyba' => t('The comment does not exist.')], 404);
        }
        if (\Kaleta\Core\DraftComments::resolve($this->app, $comment['id'])) {
            ChangeLog::write($this->app, static::IDENT, 'comment resolved', mb_substr($target['titulek'], 0, 80) . ' (#' . $comment['id'] . ')');
        }

        return Response::json(['ok' => true, 'komentare' => $this->commentsForEditor($signature)]);
    }

    /**
     * Comments of a page draft for the builder's panel (unresolved first), or null for targets that have no comments (site
     * parts, collection templates, pop-ups, components).
     *
     * @return list<array<string, mixed>>|null
     */
    private function commentsForEditor(string $signature): ?array
    {
        if (\Kaleta\Core\DraftComments::parseTarget($signature) === null) {
            return null;
        }

        return array_map(fn (array $c): array => ['id' => $c['id'], 'prvek' => $c['element'], 'citace' => $c['quote'], 'jmeno' => $c['name'], 'text' => $c['text'],
            'kdy' => format_date($c['created_at'], true), 'vyrizeno' => $c['resolved_at'] !== null], \Kaleta\Core\DraftComments::list($this->db, $signature, false, 200));
    }

    /**
     * The selected elements packed for the system clipboard (JSON): with the shared classes and the components they use, so
     * that the builder of another Kaleta site can paste them (Builder\ElementClipboard). Changes nothing.
     */
    protected function actionBuildPackage(): Response
    {
        $elements = json_decode((string) ($_POST['prvky'] ?? ''), true);
        if (!$this->request->isPost() || !is_array($elements) || !array_is_list($elements) || $elements === []) {
            return Response::json(['ok' => false, 'chyba' => t('Nothing to copy.')], 400);
        }

        return Response::json(['ok' => true, 'schranka' => ElementClipboard::pack($this->db, array_slice($elements, 0, ElementClipboard::MAX_ELEMENTS), $this->request->origin())]);
    }

    /**
     * Elements pasted from the clipboard of another Kaleta site (JSON): the envelope is checked, missing classes and
     * components are created like on a page import (an administrator only, existing classes stay), the elements go through
     * the validator and come back with new ids – the editor inserts them at the selected position and saves the draft as
     * usual. Media of the other site are relinked or left out; the editor shows how many.
     */
    protected function actionBuildPaste(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'chyba' => t('Page does not exist.')], 404);
        }
        $raw = (string) ($_POST['schranka'] ?? '');
        $package = strlen($raw) <= ElementClipboard::MAX_LENGTH ? ElementClipboard::parse(json_decode($raw, true)) : null;
        if ($package === null) {
            return Response::json(['ok' => false, 'chyba' => t('The clipboard does not contain Kaleta elements.')], 400);
        }
        $admin = $this->app->auth()->isAdmin();
        [$elements, $created, $images] = ElementClipboard::import($this->app->settings(), $package, $this->request->origin(), $admin);
        [$build, $errors] = Build::sanitize(['deti' => $elements], $admin);
        if ($build['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('None of the elements could be inserted.') . ($errors !== [] ? ' ' . implode(' ', array_slice(array_values(self::builderNotes($errors)), 0, 3)) : '')], 400);
        }
        $messages = array_values(self::builderNotes($errors));
        if ($created['tridy'] > 0 || $created['komponenty'] > 0) {
            $messages[] = t('New classes: %d, new components: %d (those the site already had were kept).', $created['tridy'], $created['komponenty']);
        } elseif (!$admin && ($package['tridy'] !== [] || $package['komponenty'] !== [])) {
            $messages[] = t('Its classes and components were not imported – only an administrator can add them.');
        }
        if ($images > 0) {
            $messages[] = str_starts_with($package['site'], 'https://')
                ? t('%d images still load from %s – replace them with files from this site’s Media.', $images, $package['site'])
                : t('%d images were left out – the media of the other site are not available here.', $images);
        }

        return Response::json(['ok' => true, 'prvky' => $build['deti'], 'hlaseni' => $messages, 'tridy' => $this->loadBuilderClasses(),
            'komponenty' => \Kaleta\Admin\Modules\Components::listForEditor($this->db)]);
    }

    /** Discards the work-in-progress changes: the editor returns to the published build. */
    protected function actionBuildDiscard(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null || $target['stavba'] === null) {
            return Response::json(['ok' => false, 'chyba' => t('There is no published version yet – nothing to revert to.')], 400);
        }
        $this->saveDraft($target, null);

        return Response::json(['ok' => true, 'stavba' => Build::fromJson($target['stavba']), 'verze' => self::computeBuildVersion($target['stavba'])]);
    }

    /** A section from the library as new elements (JSON) in the target's language; missing classes it uses are created. */
    protected function actionBuildSection(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $section = $target !== null ? Library::section($this->request->get('key'), $target['jazyk']) : null;
        if ($section === null) {
            return Response::json(['ok' => false, 'chyba' => t('The section is not in the library.')], 404);
        }
        Library::createClasses($this->db, $section['tridy']);

        return Response::json(['ok' => true, 'prvek' => $section['prvek'], 'tridy' => $this->loadBuilderClasses()]);
    }

    /** @return list<array{id:int, nazev:string, prvek:array<string, mixed>}> the site's custom sections (panel "Přidat → Moje sekce", Add → My sections) */
    public static function listMySections(\Kaleta\Core\Db $db): array
    {
        return array_values(array_filter(array_map(fn (array $r): ?array => is_array($p = json_decode((string) $r['prvek'], true)) ? ['id' => (int) $r['idx'], 'nazev' => $r['nazev'], 'prvek' => $p] : null,
            $db->all('SELECT idx, nazev, prvek FROM {sekce} ORDER BY nazev LIMIT 200'))));
    }

    /** Saves the selected element to the custom section library (it goes through the validator like every build). */
    protected function actionBuildSaveSection(): Response
    {
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        $element = json_decode((string) ($_POST['prvek'] ?? ''), true);
        if (!$this->request->isPost() || $name === '' || !is_array($element)) {
            return Response::json(['ok' => false, 'chyba' => t('The section needs a name.')], 400);
        }
        [$build] = Build::sanitize(['deti' => [$element]], $this->app->auth()->isAdmin());
        if (($build['deti'][0] ?? null) === null) {
            return Response::json(['ok' => false, 'chyba' => t('The element could not be saved.')], 400);
        }
        $this->db->insert('sekce', ['nazev' => $name, 'prvek' => (string) json_encode($build['deti'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'zmeneno' => date('Y-m-d H:i:s')]);
        ChangeLog::write($this->app, static::IDENT, 'section saved to library', $name);

        return Response::json(['ok' => true, 'sekce' => self::listMySections($this->db)]);
    }

    protected function actionBuildDeleteSection(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return Response::json(['ok' => false, 'chyba' => t('Only an administrator can remove sections.')], 403);
        }
        $this->db->delete('sekce', ['idx' => $this->request->postInt('idx')]);

        return Response::json(['ok' => true, 'sekce' => self::listMySections($this->db)]);
    }

    /** Saving or deleting a shared class (JSON). */
    protected function actionBuildClass(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $name = $this->request->post('nazev');
        if (!preg_match(Build::CLASS_PATTERN, $name)) {
            return Response::json(['ok' => false, 'chyba' => t('Class name: lowercase letters without accents, digits and hyphens (e.g. card, card--highlighted).')], 400);
        }
        if ($this->request->post('pouziti') === '1') {
            return Response::json(['ok' => true, 'pouziti' => $this->findClassUsages($name)]);
        }
        if (!$this->app->auth()->isAdmin()) {
            // a shared class changes the look of all pages (through the draft look) – so only the administrator edits,
            // renames and deletes it
            return Response::json(['ok' => false, 'chyba' => t('Only an administrator edits a shared class – a change applies to the whole website at once. Set the look of a single element in its style.')], 403);
        }
        if (($new = $this->request->post('novy_nazev')) !== '') {
            // renaming: the class row and all builds that use it (pages, site parts, collection item templates, components, my sections)
            if (!preg_match(Build::CLASS_PATTERN, $new) || $this->db->value('SELECT 1 FROM {tridy} WHERE nazev = ?', [$new]) !== null) {
                return Response::json(['ok' => false, 'chyba' => t('The new name must be unused and written in lowercase without accents (e.g. card-large).')], 400);
            }
            $this->db->update('tridy', ['nazev' => $new, 'zmeneno' => date('Y-m-d H:i:s')], ['nazev' => $name]);
            \Kaleta\Core\Look::renameClass($this->app->settings(), $name, $new);
            foreach (self::BUILD_SOURCES as $table => [$key, $columns]) {
                foreach ($this->db->all('SELECT ' . $key . ', ' . implode(', ', $columns) . ' FROM {' . $table . '} WHERE ' . implode(' OR ', array_map(fn (string $s): string => $s . ' LIKE ?', $columns)), array_fill(0, count($columns), '%"' . $name . '"%')) as $r) {
                    $change = [];
                    foreach ($columns as $s) {
                        if ($r[$s] !== null && str_contains($r[$s], '"' . $name . '"')) {
                            $change[$s] = self::renameClass((string) $r[$s], $name, $new);
                        }
                    }
                    $this->db->update($table, $change, [$key => $r[$key]]);
                }
            }
            \Kaleta\Front\Cache::clear();

            return Response::json(['ok' => true, 'tridy' => $this->loadBuilderClasses(), 'nazev' => $new]);
        }
        // the class goes to the draft look (Core\Look): the builder shows it, visitors see it once the look is published
        if ($this->request->post('smazat') === '1') {
            \Kaleta\Core\Look::setClass($this->app->settings(), $name, null);
        } else {
            $errors = [];
            $discarded = [];
            $style = Style::sanitize(json_decode((string) ($_POST['styl'] ?? ''), true), $name, $errors);
            $css = Style::customCss($this->request->post('css'), $discarded);
            \Kaleta\Core\Look::setClass($this->app->settings(), $name, ['styl' => $style, 'css' => $css]);
            if ($errors !== [] || $discarded !== []) {
                return Response::json(['ok' => true, 'koncept' => true, 'tridy' => $this->loadBuilderClasses(), 'chyby' => self::builderNotes($errors) + array_map(fn (string $d): string => t('Declaration not allowed: %s', $d), $discarded)]);
            }
        }

        return Response::json(['ok' => true, 'koncept' => true, 'tridy' => $this->loadBuilderClasses()]);
    }

    /** Tables with builds: table => [key, columns with the build JSON]. */
    private const array BUILD_SOURCES = [
        'stranky' => ['ids', ['stavba', 'stavba_koncept']], 'casti' => ['typ', ['stavba', 'stavba_koncept']], 'kolekce' => ['idk', ['stavba', 'stavba_koncept']],
        'komponenty' => ['idm', ['stavba', 'stavba_koncept']], 'sekce' => ['idx', ['prvek']],
    ];

    /** Renames the class in the "tridy" array of all build elements (JSON) – other occurrences of the text stay. */
    private static function renameClass(string $json, string $old, string $new): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $walk = function (array $x) use (&$walk, $old, $new): array {
            if (isset($x['tridy']) && is_array($x['tridy'])) {
                $x['tridy'] = array_map(fn (mixed $t): mixed => $t === $old ? $new : $t, $x['tridy']);
            }
            foreach ($x as $k => $v) {
                if (is_array($v)) {
                    $x[$k] = $walk($v);
                }
            }

            return $x;
        };

        return (string) json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> where the class is used (names of pages, site parts, collections, components, my sections) */
    private function findClassUsages(string $name): array
    {
        $pattern = '%"tridy":[%"' . addcslashes($name, '%_\\') . '"%';
        $whereParts = [];
        foreach ($this->db->all('SELECT titulek FROM {stranky} WHERE smazano IS NULL AND (stavba LIKE ? OR stavba_koncept LIKE ?)', [$pattern, $pattern]) as $r) {
            $whereParts[] = t('page') . ' ' . $r['titulek'];
        }
        foreach ($this->db->all('SELECT typ, nazev FROM {casti} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', [$pattern, $pattern]) as $r) {
            $whereParts[] = t('site part') . ' ' . ($r['nazev'] !== '' ? $r['nazev'] : $r['typ']);
        }
        foreach ([['kolekce', 'nazev', 'kolekce'], ['komponenty', 'nazev', 'komponenta']] as [$table, $column, $kind]) {
            foreach ($this->db->all('SELECT ' . $column . ' AS n FROM {' . $table . '} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', [$pattern, $pattern]) as $r) {
                $whereParts[] = t($kind) . ' ' . $r['n'];
            }
        }

        return array_values(array_unique($whereParts));
    }

    /** Published versions (JSON for the Versions dialog). */
    protected function actionBuildVersions(): Response
    {
        $target = $this->loadBuildTarget();

        // date in the admin format (format_date()), not the browser's – in English it otherwise came out as the American 9/25/2026, 9:43:45 AM
        return Response::json(['revize' => $target === null ? [] : array_map(fn (array $r): array => $r + ['kdy' => format_date($r['datum'], true)], Publisher::listAll($this->db, $target['revize']))]);
    }

    /** An older version is loaded into the draft; it is published only with the Publish button. */
    protected function actionBuildRestore(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $build = $target !== null ? Publisher::load($this->db, $target['revize'], $this->request->postInt('idr')) : null;
        if ($build === null) {
            return Response::json(['ok' => false, 'chyba' => t('The version does not exist.')], 404);
        }
        $this->saveDraft($target, $build);

        return Response::json(['ok' => true, 'stavba' => Build::fromJson($build), 'verze' => self::computeBuildVersion($build)]);
    }

    /**
     * Notes of the builder (Build, Style, HtmlConverter) are Czech source texts that Mcp\Translator::NOTICES turns into English
     * for Claude; an administration in another language gets the same English instead of Czech (3.5).
     *
     * @param array<array-key, string> $notes
     * @return array<array-key, string>
     */
    private static function builderNotes(array $notes): array
    {
        return Language::code() === 'cs' ? $notes : array_map(\Kaleta\Mcp\Translator::message(...), $notes);
    }

    /** Hash of the content the editor last saw on the server (the draft, otherwise the published build). */
    private static function computeBuildVersion(?string $json): string
    {
        return substr(md5((string) $json), 0, 16);
    }

    /**
     * Protection against overwriting someone else's changes: the editor sends the hash of the version it is based on. When
     * the draft has changed in the meantime (another editor, Claude via MCP, a second tab), it is rejected and the editor
     * offers to load the newer one, or to overwrite.
     */
    private function checkVersionConflict(array $target): ?Response
    {
        $version = $this->request->post('verze');
        $current = self::computeBuildVersion($target['koncept'] ?? $target['stavba']);
        if ($version === '' || $this->request->post('prepsat') === '1' || hash_equals($current, $version)) {
            return null;
        }

        return Response::json(['ok' => false, 'konflikt' => true, 'verze' => $current, 'stavba' => Build::fromJson($target['koncept'] ?? $target['stavba']),
            'chyba' => t('Someone else has edited this page in the meantime (or you opened it in another window).')], 409);
    }

    /**
     * Schema labels (names of elements, fields, style properties and their options) into the admin language. The default
     * content of elements is not translated – it is in the page language (Build::schema).
     */
    private static function translateSchema(array $schema): array
    {
        $field = function (array $properties) use (&$field): array {
            foreach ($properties as $key => $d) {
                $properties[$key]['popisek'] = t((string) ($d['popisek'] ?? ''));
                if (isset($d['moznosti'])) {
                    $properties[$key]['moznosti'] = array_map(fn (string $m): string => t($m), $d['moznosti']);
                }
                if (isset($d['pole'])) {
                    $properties[$key]['pole'] = $field($d['pole']);
                }
            }

            return $properties;
        };
        foreach ($schema['prvky'] as $i => $p) {
            $schema['prvky'][$i] = ['nazev' => t($p['nazev']), 'popis' => t($p['popis']), 'skupina' => t($p['skupina']), 'vlastnosti' => $field($p['vlastnosti'])] + $p;
        }
        $schema['styl'] = $field($schema['styl']);
        $schema['skupiny_stylu'] = array_map(fn (string $s): string => t($s), $schema['skupiny_stylu']);

        return $schema;
    }

    /** AI assistant: a new section from a description (JSON with elements to insert). */
    protected function actionBuildAiSection(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if ($target === null || !$assistant->isReady()) {
            return Response::json(['ok' => false, 'chyba' => t('The writing assistant is not enabled (Features).')], 400);
        }
        try {
            $html = \Kaleta\Core\Language::runWith($target['jazyk'], fn (): string => $assistant->suggestSection($this->request->post('zadani'), $target['jazyk'], $target['titulek']));
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 502);
        }
        try {
            ['stavba' => $build, 'hlaseni' => $messages] = \Kaleta\Builder\HtmlConverter::saveToSite($this->db, $html, false);
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            return Response::json(['ok' => false, 'chyba' => $e->localized()], 502); // the model's HTML over a limit of Core\HtmlLimits
        }
        [$clean] = Build::sanitize($build, $this->app->auth()->isAdmin());
        if ($clean['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('The assistant did not return a usable section. Try refining the description.')], 502);
        }

        return Response::json(['ok' => true, 'prvky' => $clean['deti'], 'tridy' => $this->loadBuilderClasses(), 'hlaseni' => self::builderNotes($messages)]);
    }

    /** AI assistant: rewrite of an element's text (shorter, longer, more formal…). Saves nothing – the editor inserts the text as a regular change. */
    protected function actionBuildAiText(): Response
    {
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$this->request->isPost() || !$assistant->isReady()) {
            return Response::json(['ok' => false, 'chyba' => t('The writing assistant is not enabled (Features).')], 400);
        }
        try {
            $text = $assistant->rewrite((string) ($_POST['text'] ?? ''), $this->request->post('pokyn'), $this->request->post('html') === '1');
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 502);
        }

        return Response::json(['ok' => true, 'text' => $text]);
    }

    /** @return array<string, array{styl: array<string, mixed>|\stdClass, css: string}> */
    protected function loadBuilderClasses(): array
    {
        // with the draft look: the builder works on what will be published
        return array_map(fn (array $c): array => ['styl' => $c['styl'] ?: new \stdClass(), 'css' => $c['css']], \Kaleta\Core\Look::classes($this->db, $this->app->settings(), true));
    }

    protected function contentLanguage(string $column): string
    {
        return Language::ofContent($this->app->settings(), $column);
    }
}
