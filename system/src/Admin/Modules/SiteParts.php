<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\SiteParts as CastiWebu;
use Kaleta\Builder\Publisher;

/**
 * Site parts in the builder: header, footer and the wrappers of the news item detail, the list and the 404 page. Without a
 * published build the layout draws the part; "Vrátit na šablonu" (Revert to layout) turns the build off (it stays in the versions).
 */
final class SiteParts extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'parts';
    public const string NAME = 'Site parts';
    public const string GROUP = 'Appearance';
    public const string ICON = 'casti';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $siteSettings = $this->app->settings();
        $languages = array_merge([''], Language::additional($siteSettings));
        $rows = [];
        $variants = [];
        foreach ($this->db->all('SELECT typ, jazyk, varianta, nazev, stranky, pravidla, stavba IS NOT NULL AS publikovana, stavba_koncept IS NOT NULL AND (stavba IS NULL OR stavba_koncept <> stavba) AS zmeny, zmeneno FROM {casti} ORDER BY nazev') as $r) {
            if ($r['varianta'] === '') {
                $rows[$r['typ'] . ':' . $r['jazyk']] = $r;
            } else {
                $variants[$r['typ'] . ':' . $r['jazyk']][] = $r;
            }
        }

        return $this->view('list', 'Site parts', [
            'types' => CastiWebu::TYPES, 'languages' => $languages, 'rows' => $rows, 'variants' => $variants,
            'pageNames' => $this->db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY poradi, titulek'),
            'collectionNames' => $this->db->pairs('SELECT seo_link, nazev FROM {kolekce}'),
            'languageNames' => array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[Language::ofContent($siteSettings, $j)][0], $languages)),
        ]);
    }

    /** Editor; a part that does not exist yet is created with a draft based on what the layout has drawn so far. */
    protected function actionBuilder(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type !== null && $variant === '' && CastiWebu::row($this->db, $type, $language) === null) {
            $this->db->insert('casti', ['typ' => $type, 'jazyk' => $language, 'stavba_koncept' => CastiWebu::initialDraft($this->db, $type, $language, $this->contentLanguage($language)), 'zmeneno' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    /** The part reverts to the layout (a variant is deleted): the published build goes to the versions, the site draws the part from the layout. */
    protected function actionTemplate(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        $row = $this->request->isPost() && $type !== null ? CastiWebu::row($this->db, $type, $language, $variant) : null;
        if ($row !== null) {
            Publisher::version($this->app, ['cast' => CastiWebu::versionKey($type, $language, $variant)], $row['stavba'], null, $row['zmeneno']);
            $this->db->delete('casti', ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back($variant !== '' ? 'The variant was deleted – the selected pages have the default version again.' : 'The site part is back to its default design. The previous design is in the history when you open it in the builder again.');
    }

    /** Form of a header or footer variant: name and the pages it applies to. */
    /** Ready-made templates of the part (PartTemplates) to start from. */
    protected function actionTemplates(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type === null) {
            return $this->error('The site part does not exist.', 404);
        }

        return $this->view('templates', t('Templates: %s', t(CastiWebu::TYPES[$type][0])), [
            'type' => $type, 'language' => $language, 'variant' => $variant,
            'templates' => \Kaleta\Builder\PartTemplates::forType($type, \Kaleta\Core\Extensions::enabled($this->app->settings())),
        ]);
    }

    /** A template into the part's draft – the builder opens with it; the site changes only after publishing. */
    protected function actionApplyTemplate(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if (!$this->request->isPost() || $type === null
            || !CastiWebu::applyTemplate($this->db, $type, $language, $variant, $this->request->post('sablona'), $this->contentLanguage($language), \Kaleta\Core\Extensions::enabled($this->app->settings()))) {
            return $this->back('The template could not be used.', '', [], 'chyba');
        }
        $this->app->session->flash('ok', 'The template is in the draft – adjust it and publish; until then visitors see the published version.');

        return Response::redirect($this->url('builder', ['type' => $type, 'language' => $language] + ($variant !== '' ? ['variant' => $variant] : [])));
    }

    protected function actionVariant(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type === null || !in_array($type, CastiWebu::WITH_VARIANTS, true)) {
            return $this->error('Only the header and footer can have variants.', 404);
        }
        $row = $variant !== '' ? CastiWebu::row($this->db, $type, $language, $variant) : null;

        return $this->view('variant', t('Variant: %s', t(CastiWebu::TYPES[$type][0])), [
            'type' => $type, 'language' => $language, 'variant' => $row['varianta'] ?? '', 'name' => $row['nazev'] ?? '',
            'selected' => array_map('intval', json_decode((string) ($row['stranky'] ?? '[]'), true) ?: []),
            'pages' => $this->db->all('SELECT ids, titulek FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$language]),
            // 3.6: kinds of content the variant takes besides the pages
            'rules' => CastiWebu::sanitizeRules(json_decode((string) ($row['pravidla'] ?? ''), true)),
            'collections' => $this->db->all('SELECT seo_link, nazev FROM {kolekce} WHERE detail = 1 ORDER BY nazev'),
            'parents' => $this->db->all('SELECT DISTINCT p.ids, p.titulek FROM {stranky} p JOIN {stranky} c ON c.nadrazena = p.ids AND c.smazano IS NULL WHERE p.jazyk = ? AND p.smazano IS NULL ORDER BY p.titulek', [$language]),
            'news' => \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky'),
        ]);
    }

    /** Saving a variant; a new one starts as a copy of the default form (or the form from the layout) as a draft. */
    protected function actionSaveVariant(): Response
    {
        [$type, $language] = $this->readPartParams();
        if (!$this->request->isPost() || $type === null || !in_array($type, CastiWebu::WITH_VARIANTS, true)) {
            return $this->back();
        }
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('The variant needs a name.', 'variant', ['type' => $type, 'language' => $language], 'chyba');
        }
        $rules = ['novinky' => $this->request->post('novinky') === '1', 'vypis' => $this->request->post('vypis') === '1', 'kolekce' => $this->request->postList('kolekce'),
            'nadrazene' => array_map('intval', $this->request->postList('nadrazene'))];
        $variant = CastiWebu::saveVariant($this->db, $type, $language, $this->request->post('varianta'), $name, array_map('intval', $this->request->postList('stranky')), $this->contentLanguage($language), $rules);
        \Kaleta\Front\Cache::clear();

        return \Kaleta\Core\Response::redirect($this->url('builder', ['type' => $type, 'language' => $language, 'variant' => $variant]));
    }

    protected function loadBuildTarget(): ?array
    {
        [$type, $language, $variant] = $this->readPartParams();
        $row = $type === null ? null : CastiWebu::row($this->db, $type, $language, $variant);

        return $row === null ? null : [
            'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => $this->contentLanguage($language),
            'titulek' => t(CastiWebu::TYPES[$type][0]) . ($variant !== '' ? ' – ' . $row['nazev'] : ''),
            'revize' => ['cast' => CastiWebu::versionKey($type, $language, $variant)], 'parametry' => ['type' => $type, 'language' => $language] + ($variant !== '' ? ['variant' => $variant] : []),
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('casti', ['stavba_koncept' => $draft], ['typ' => $target['radek']['typ'], 'jazyk' => $target['radek']['jazyk'], 'varianta' => $target['radek']['varianta']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::part($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $type = $target['radek']['typ'];
        $language = $target['radek']['jazyk'];
        // preview: a page on which the part appears (the news item wrapper on the newest news item, 404 on a non-existent URL)
        // a variant is shown on the first page it applies to
        $page = $target['radek']['varianta'] !== '' ? (json_decode((string) $target['radek']['stranky'], true) ?: [])[0] ?? null : null;
        $path = $page !== null ? (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [(int) $page]) : match ($type) {
            'novinka' => ($seo = $this->db->value('SELECT seo_link FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum <= NOW() AND jazyk = ? ORDER BY datum DESC LIMIT 1', [$language])) !== null ? 'novinky/' . $seo : 'novinky',
            'vypis' => 'novinky',
            'nenalezeno' => 'tahle-stranka-neexistuje',
            default => '',
        };
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $path);

        return [
            'adresa' => $url, 'nahled' => $url . '?part=' . $type . '&build=koncept&editor=1' . ($target['radek']['varianta'] !== '' ? '&variant=' . rawurlencode($target['radek']['varianta']) : ''),
            'zobrazena' => true, 'casti' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Site parts')], 'nastaveni' => null, 'podpis' => 'cast:' . $type . ':' . $language . ($target['radek']['varianta'] !== '' ? ':' . $target['radek']['varianta'] : ''),
        ];
    }

    /** @return array{0: ?string, 1: string, 2: string} type, language and variant of the part from the request URL */
    private function readPartParams(): array
    {
        $type = $this->request->get('type');
        $language = $this->request->get('language');
        $variant = $this->request->get('variant');

        return [isset(CastiWebu::TYPES[$type]) ? $type : null, in_array($language, Language::additional($this->app->settings()), true) ? $language : '',
            in_array($type, CastiWebu::WITH_VARIANTS, true) && preg_match(CastiWebu::VARIANT_PATTERN, $variant) ? $variant : ''];
    }
}
