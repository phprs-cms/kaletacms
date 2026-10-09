<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Components as KomponentyStavby;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Components – reusable blocks (service card, contact block, call to action…). They are created in the builder with the
 * button "Uložit jako komponentu" (Save as component) or here; they are edited in the builder and a change shows
 * everywhere they are used.
 */
final class Components extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'components';
    public const string NAME = 'Components';
    public const string GROUP = 'Appearance';
    public const string ICON = 'komponenta';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $components = KomponentyStavby::all($this->db);
        foreach ($components as &$k) {
            $k['mista'] = $this->usages((int) $k['idm']);
            $k['pouziti'] = count($k['mista']);
        }
        unset($k);

        return $this->view('list', 'Components', ['components' => $components]);
    }

    protected function actionNew(): Response
    {
        return $this->view('form', 'New component', ['k' => ['idm' => 0, 'nazev' => '', 'vlastnosti' => []]]);
    }

    protected function actionEdit(): Response
    {
        $k = KomponentyStavby::byId($this->db, $this->request->getInt('id'));

        return $k === null ? $this->error('The component does not exist.', 404) : $this->view('form', $k['nazev'], ['k' => $k]);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('idm');
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('The component needs a name.', $id > 0 ? 'edit' : 'new', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $data = ['nazev' => $name, 'vlastnosti' => (string) json_encode(KomponentyStavby::sanitizeProperties(is_array($_POST['vlastnosti'] ?? null) ? $_POST['vlastnosti'] : []), JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($id > 0 && KomponentyStavby::byId($this->db, $id) !== null) {
            $this->db->update('komponenty', $data, ['idm' => $id]);
        } else {
            $id = $this->db->insert('komponenty', $data + ['stavba_koncept' => Build::toJson(['v' => Build::VERSION, 'deti' => [Build::fresh('sekce')]])]);
        }
        \Kaleta\Front\Cache::clear();

        return $this->back('The component was saved.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('komponenty', ['idm' => $this->request->postInt('idm')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('The component was deleted. Places where it was used will be empty.');
    }

    /** From the builder: the selected element becomes a component (JSON). The editor then replaces it with a use of the component. */
    protected function actionFromElement(): Response
    {
        $element = $this->request->isPost() ? json_decode((string) ($_POST['prvek'] ?? ''), true) : null;
        $name = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if (!is_array($element) || $name === '') {
            return Response::json(['ok' => false, 'chyba' => t('Name or element is missing.')], 400);
        }
        [$build] = Build::sanitize(['v' => Build::VERSION, 'deti' => [$element]], true);
        if ($build['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('A component cannot be created from this element.')], 400);
        }
        $id = $this->db->insert('komponenty', ['nazev' => $name, 'vlastnosti' => '[]', 'stavba' => Build::toJson($build), 'zmeneno' => date('Y-m-d H:i:s')]);

        return Response::json(['ok' => true, 'id' => $id, 'komponenty' => self::listForEditor($this->db)]);
    }

    /** @return list<array{id: int, nazev: string, vlastnosti: list<array<string, string>>}> */
    public static function listForEditor(\Kaleta\Core\Db $db): array
    {
        return array_map(fn (array $k): array => ['id' => (int) $k['idm'], 'nazev' => $k['nazev'], 'vlastnosti' => $k['vlastnosti']], KomponentyStavby::all($db));
    }

    /* ---------- editing in the builder ---------- */

    protected function actionBuilder(): Response
    {
        return $this->openBuilder();
    }

    protected function loadBuildTarget(): ?array
    {
        $k = KomponentyStavby::byId($this->db, $this->request->getInt('id'));

        return $k === null ? null : [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Language::defaults($this->app->settings()),
            'titulek' => t('Component: %s', $k['nazev']), 'revize' => ['cast' => 'komponenta:' . (int) $k['idm']], 'parametry' => ['id' => (int) $k['idm']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('komponenty', ['stavba_koncept' => $draft], ['idm' => $target['radek']['idm']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::component($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $k = $target['radek'];
        $url = $this->app->url('_komponenta/' . (int) $k['idm']);

        return [
            'adresa' => $url . '?build=koncept', 'nahled' => $url . '?build=koncept&editor=1', 'zobrazena' => true, 'casti' => false,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Components')], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idm']]),
            // hint of the {{properties}} in the editor (the same as for a collection, only without built-in values)
            'kolekce' => ['seo_link' => '', 'nazev' => $k['nazev'], 'pole' => $k['vlastnosti'], 'detail' => false, 'vestavene' => false],
        ];
    }

    /**
     * Where the component is used (pages, site parts, collections, other components) – names for the list and the delete confirmation.
     *
     * @return list<string>
     */
    private function usages(int $idm): array
    {
        $pattern = '%"typ":"komponenta"%"komponenta":"' . $idm . '"%';
        $whereParts = ' WHERE (stavba LIKE ? OR stavba_koncept LIKE ?)';
        $usages = [];
        foreach ($this->db->all('SELECT titulek, smazano IS NOT NULL AS kos FROM {stranky}' . $whereParts . ' ORDER BY smazano IS NOT NULL, titulek', [$pattern, $pattern]) as $r) {
            $usages[] = t('page “%s”', $r['titulek']) . ($r['kos'] ? ' (' . t('in trash') . ')' : '');
        }
        foreach ($this->db->all('SELECT typ, jazyk, nazev FROM {casti}' . $whereParts . ' ORDER BY typ, jazyk, varianta', [$pattern, $pattern]) as $r) {
            $usages[] = mb_strtolower(t(\Kaleta\Builder\SiteParts::TYPES[$r['typ']][0] ?? $r['typ'])) . ($r['nazev'] !== '' ? ' „' . $r['nazev'] . '“' : '') . ($r['jazyk'] !== '' ? ' (' . $r['jazyk'] . ')' : '');
        }
        foreach ($this->db->all('SELECT nazev FROM {kolekce}' . $whereParts . ' ORDER BY nazev', [$pattern, $pattern]) as $r) {
            $usages[] = t('collection detail “%s”', $r['nazev']);
        }
        // a component inside itself does not count (and is not even rendered on the site)
        foreach ($this->db->all('SELECT nazev FROM {komponenty}' . $whereParts . ' AND idm <> ? ORDER BY nazev', [$pattern, $pattern, $idm]) as $r) {
            $usages[] = t('component “%s”', $r['nazev']);
        }

        return $usages;
    }
}
