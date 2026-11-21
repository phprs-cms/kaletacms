<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\HtmlConverter;
use Kaleta\Mcp\Schema;

/**
 * MCP tools: look (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait LookTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function lookToolsDefinitions(): array
    {
        return [
            'seznam_trid' => ['Sdílené třídy webu (karta, tmava…) s jejich stylem po stavech a vlastním CSS. Třídu dostane prvek v poli "tridy".', Schema::object(['nazev' => Schema::text('jen tahle třída (nepovinné)')])],
            'uloz_tridy' => ['Založí nebo změní sdílené třídy (správce) – změna se hned projeví na celém webu. Zadej CSS jako v bloku <style>: pravidla jedné třídy (.karta { … }), '
                . '.karta:hover { … } a @media (max-width: 1023px) = tablet, (max-width: 767px) = mobil. Tokeny var(--ka-…), i přepis tokenů v třídě (--ka-barva-text: #fff) pro tmavé pásy.',
                Schema::object(['css' => Schema::text('pravidla tříd; slučují se se stávajícími – samotné .karta:hover nebo @media nechá základ třídy beze změny'),
                    'nahradit' => ['type' => 'boolean', 'description' => 'true = třídy z css nahradit celé (základ i všechny stavy)'],
                    'smazat' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'názvy tříd ke smazání']])],
            'uprav_design_system' => ['Změní vzhled celého webu (správce): barvy, písma, velikosti, šířku, zaoblení – nebo použije předvolbu. Nezadané hodnoty zůstanou. Vrátí kontrolu čitelnosti barev.',
                Schema::object(['predvolba' => Schema::text('firemni | remeslo | pratelsky | elegantni | technologie (nepovinné)'), 'ds' => ['type' => 'object', 'description' => 'Změny, např. {"barvy":{"primarni":"#0f766e"},"pismo_titulky":"klasicke","zaobleni":"l"} – klíče viz stavba_schema → design_system']])],
            'publish_look' => ['Publishes the draft look – design system, classes, menus, logo, icon (update_design_system, save_classes, save_menu, update_settings; administrators; only when the user explicitly asks, after they saw the preview). The published look is kept as a version first.', Schema::object([])],
            'discard_look' => ['Throws the draft look away – the published look stays (administrators; only when the user explicitly asks).', Schema::object([])],
            'list_look_versions' => ['Earlier published looks (the last 20), with what the next publishing changed. restore_look_version brings one back into the draft.', Schema::object([])],
            'restore_look_version' => ['Loads an earlier published look into the draft look (administrators) – check it with preview_link site: true, then publish_look.', Schema::object(['id' => Schema::number('version ID from list_look_versions')], ['id'])],
        ];
    }

    /** list_classes (seznam_trid) */
    private function toolListClasses(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        // with the draft look: Claude works on what will be published (draft = changed in the draft look)
        $classes = \Kaleta\Core\Look::classes($db, $siteSettings, true);
        if (isset($a['nazev'])) {
            $classes = array_intersect_key($classes, [(string) $a['nazev'] => true]);
        }

        return array_values(array_map(fn (string $name, array $c): array => ['nazev' => $name, 'styl' => $c['styl'] ?: new \stdClass(), 'css' => $c['css']]
            + ($c['draft'] ? ['draft' => true] : []), array_keys($classes), $classes));
    }

    /** save_classes (uloz_tridy) */
    private function toolSaveClasses(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $conversion = HtmlConverter::convert('<style>' . str_ireplace('</style', '', (string) ($a['css'] ?? '')) . '</style>', true);
        $stored = [];
        $inDraft = [];
        foreach (array_unique(array_merge(array_keys($conversion['tridy']), array_keys($conversion['tridy_styl']))) as $className) {
            // merged: a rule only for :hover or @media keeps the class base and the other states (nahradit: true = the whole class anew)
            $previous = empty($a['nahradit']) ? (\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className] ?? null) : null; // the draft, when there is one
            $style = ($conversion['tridy_styl'][$className] ?? []) + (array) ($previous['styl'] ?? []);
            $css = $conversion['tridy'][$className] ?? (string) ($previous['css'] ?? '');
            // a change of an existing class goes to the draft look, a new class is live at once (it changes nothing published)
            \Kaleta\Core\Look::setClass($siteSettings, $className, ['styl' => $style, 'css' => $css]) ? $inDraft[] = $className : $stored[] = $className;
        }
        $deleted = [];
        foreach (is_array($a['smazat'] ?? null) ? $a['smazat'] : [] as $className) {
            if (is_string($className) && isset(\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className])) {
                \Kaleta\Core\Look::setClass($siteSettings, $className, null);
                $deleted[] = $className;
            }
        }

        return ['ulozeno' => $stored, 'look_draft' => $inDraft, 'smazano' => $deleted, 'hlaseni' => $conversion['hlaseni']]
            + ($inDraft !== [] || $deleted !== [] ? ['pozn' => 'Changes of existing classes and deletions are in the draft look – check them with preview_link site: true, publish with publish_look.'] : []);
    }

    /** update_design_system (uprav_design_system) */
    private function toolUpdateDesignSystem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $ds = isset($a['predvolba']) ? (DesignSystem::preset((string) $a['predvolba']) ?? throw new \InvalidArgumentException('Předvolba neexistuje: ' . implode(', ', array_keys(DesignSystem::PRESETS)) . '.')) : \Kaleta\Core\Look::designSystem($siteSettings);
        $changes = is_array($a['ds'] ?? null) ? $a['ds'] : [];
        foreach (['barvy', 'barvy_tmave'] as $group) {
            if (is_array($changes[$group] ?? null)) {
                $changes[$group] += $ds[$group];
            }
        }
        $ds = DesignSystem::sanitize($changes + $ds);
        \Kaleta\Core\Look::setDesignSystem($siteSettings, $ds); // to the draft look – publish_look publishes it

        // 3.6: the dark mode palette with the derived primary and secondary, and its readability (barvy_tmave.primarni and
        // .sekundarni override them; "" returns them to automatic)
        $dark = in_array($siteSettings->get('dark_mode'), ['auto', 'tmavy'], true) ? ['tmava_paleta' => DesignSystem::darkColors($ds), 'citelnost_tmave' => DesignSystem::contrasts($ds, true)] : [];

        return ['design_system' => $ds, 'citelnost' => DesignSystem::contrasts($ds)] + $dark + ['stav' => 'draft look – visitors see it after publish_look',
            'nahled' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];
    }

    /** publish_look and discard_look and restore_look_version */
    private function toolPublishLook(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'The look of the site can be published only by an administrator.');
        if ($name === 'discard_look') {
            \Kaleta\Core\Look::discard($this->app->settings());

            return ['discarded' => true];
        }
        if ($name === 'restore_look_version') {
            \Kaleta\Core\Look::restoreVersion($this->app, $id);

            return ['draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $this->app->settings()), 'admin-'), 'preview' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];
        }
        // the summary for Claude in English; the stored version, the event and the change log keep the site's language
        $english = \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $this->app->settings()), 'admin-');
        if (\Kaleta\Core\Look::publish($this->app) === []) {
            throw new \DomainException('There is no draft look to publish.');
        }

        return ['published' => $english];
    }

    /** discard_look: the same as publish_look */
    private function toolDiscardLook(string $name, array $a): mixed
    {
        return $this->toolPublishLook($name, $a);
    }

    /** list_look_versions */
    private function toolListLookVersions(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return ['versions' => \Kaleta\Core\Look::versions($db), 'draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $this->app->settings()), 'admin-')];
    }

    /** restore_look_version: the same as publish_look */
    private function toolRestoreLookVersion(string $name, array $a): mixed
    {
        return $this->toolPublishLook($name, $a);
    }
}
