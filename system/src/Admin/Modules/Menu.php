<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Language;
use Kaleta\Core\Menu as MenuWebu;
use Kaleta\Core\Response;

/**
 * Vzhled → Menu (Appearance → Menu): the main menu and the footer menu for each language version. Items are pages, custom
 * links, news and groups, each with one level of submenu. Until someone saves the main menu, it is assembled from pages
 * "in menu".
 */
final class Menu extends Module
{
    public const string IDENT = 'menu';
    public const string NAME = 'Menu';
    public const string GROUP = 'Appearance';
    public const string ICON = 'menu';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        [$location, $language] = $this->selection();
        [$inDraft, $saved] = \Kaleta\Core\Look::menuForEditing($this->db, $this->app->settings(), $location, $language); // the draft look, when there is one
        $pages = $this->db->all('SELECT ids, titulek, zobrazit, v_menu FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$language]);
        // the automatic main menu is shown in the editor as the visitor sees it – saving turns it into a custom one
        $items = $saved ?? ($location === 'hlavni'
            ? [...array_map(fn (array $s): array => ['typ' => 'stranka', 'ids' => (int) $s['ids'], 'text' => ''], array_values(array_filter($pages, fn (array $s): bool => $s['zobrazit'] && $s['v_menu']))), ...(\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky') ? [['typ' => 'novinky', 'text' => '']] : [])]
            : []);
        $siteSettings = $this->app->settings();
        $languages = array_merge([''], Language::additional($siteSettings));

        return $this->view('list', 'Menu', [
            'location' => $location, 'language' => $language, 'automatic' => $saved === null, 'items' => $items, 'inDraft' => $inDraft,
            'pages' => array_map(fn (array $s): array => ['ids' => (int) $s['ids'], 'titulek' => $s['titulek'], 'skryta' => !$s['zobrazit']], $pages),
            'languages' => array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[Language::ofContent($siteSettings, $j)][0], $languages)),
        ]);
    }

    protected function actionSave(): Response
    {
        [$location, $language] = $this->selection();
        if ($this->request->isPost()) {
            $items = json_decode((string) ($_POST['polozky'] ?? ''), true);
            if (!is_array($items)) {
                return $this->back('The menu could not be saved – please try again.', '', ['location' => $location, 'language' => $language], 'chyba');
            }
            \Kaleta\Core\Look::setMenu($this->app->settings(), $location, $language, $items);
            // 3.6: "Save and publish menu" – only this menu goes live, the rest of the draft look keeps waiting
            if ($this->request->post('publikovat') === '1' && \Kaleta\Core\Look::publishMenu($this->app, $location, $language)) {
                return $this->back('The menu is published – visitors see it now.', '', ['location' => $location, 'language' => $language]);
            }
        }

        return $this->back('The menu is saved to the draft look – preview the whole site, then publish it.', '', ['location' => $location, 'language' => $language]);
    }

    /** The main menu returns to being assembled automatically from pages "in menu"; the footer menu is emptied. */
    protected function actionAutomatic(): Response
    {
        [$location, $language] = $this->selection();
        if ($this->request->isPost()) {
            \Kaleta\Core\Look::setMenu($this->app->settings(), $location, $language, null);
        }

        return $this->back($location === 'hlavni' ? 'In the draft look the menu is again built automatically from pages in the navigation.' : 'In the draft look the footer menu is empty.', '', ['location' => $location, 'language' => $language]);
    }

    /** @return array{0: string, 1: string} location and language (column) from the URL */
    private function selection(): array
    {
        $location = $this->request->get('location');
        $language = $this->request->get('language');

        return [isset(MenuWebu::LOCATIONS[$location]) ? $location : 'hlavni', in_array($language, Language::additional($this->app->settings()), true) ? $language : ''];
    }
}
