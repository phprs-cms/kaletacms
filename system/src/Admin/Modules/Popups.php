<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\Popups as Okna;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;

/**
 * Popups: the content is built in the builder as a site part, the settings define the type, trigger, rules and frequency.
 * The popup appears on the site once it is published and enabled. The counters of views, closes and conversions are without cookies.
 */
final class Popups extends Module
{
    use BuilderActions;

    public const string IDENT = 'popups';
    public const string NAME = 'Pop-ups';
    public const string GROUP = 'Appearance';
    public const string ICON = 'popupy';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        return $this->view('list', 'Pop-ups', ['popups' => Okna::all($this->db)]);
    }

    protected function actionNew(): Response
    {
        return $this->view('new', 'New pop-up', []);
    }

    /** A new popup from a ready-made pattern: build draft in the site language, type and trigger from the pattern, disabled – straight into the builder. */
    protected function actionCreate(): Response
    {
        $r = $this->request;
        $pattern = Okna::LIBRARY[$r->post('vzor')] ?? null;
        if (!$r->isPost() || $pattern === null) {
            return $this->back('', 'new');
        }
        $name = mb_substr(trim($r->post('nazev')), 0, 100) ?: t($pattern[0]);
        $id = $this->db->insert('popupy', [
            'nazev' => $name, 'adresa' => Okna::address($this->db, $name), 'typ' => $pattern[2], 'spoustec' => $pattern[3], 'hodnota' => $pattern[4],
            'pravidla' => (string) json_encode(Okna::defaultRules()), 'cetnost' => 'relace', 'dni' => 7, 'aktivni' => 0,
            'stavba_koncept' => Build::toJson(Okna::libraryBuild((string) $r->post('vzor'), Language::defaults($this->app->settings()))), 'zmeneno' => date('Y-m-d H:i:s'),
        ]);

        return Response::redirect($this->url('builder', ['id' => $id]));
    }

    protected function actionEdit(): Response
    {
        $p = Okna::byId($this->db, $this->request->getInt('id'));

        return $p === null ? $this->error('The pop-up does not exist.', 404) : $this->view('form', $p['nazev'], ['p' => $p] + $this->options());
    }

    protected function actionSave(): Response
    {
        $r = $this->request;
        $p = $r->isPost() ? Okna::byId($this->db, $r->postInt('idpp')) : null;
        if ($p === null) {
            return $this->back();
        }
        $name = mb_substr(trim($r->post('nazev')), 0, 100);
        if ($name === '') {
            return $this->back('The pop-up needs a name.', 'edit', ['id' => $p['idpp']], 'chyba');
        }
        $url = $r->post('adresa') !== '' ? slugify($r->post('adresa'), 60) : $p['adresa'];
        if (!preg_match(Okna::ADDRESS_PATTERN, $url) || $this->db->value('SELECT idpp FROM {popupy} WHERE adresa = ? AND idpp <> ?', [$url, $p['idpp']]) !== null) {
            return $this->back(t('Another window already uses the address “%s”.', $url), 'edit', ['id' => $p['idpp']], 'chyba');
        }
        // "na celém webu" (on the whole site): the choice of places is inactive in the form and is not sent – it stays saved in case you return to it
        $selected = $r->post('kde') === 'vybrane';
        $rules = Okna::sanitizeRules([
            'kde' => $r->post('kde'),
            'stranky' => $selected ? (is_array($_POST['stranky'] ?? null) ? $_POST['stranky'] : []) : $p['pravidla']['stranky'],
            'kolekce' => $selected ? (is_array($_POST['kolekce'] ?? null) ? $_POST['kolekce'] : []) : $p['pravidla']['kolekce'],
            'novinky' => $selected ? $r->postBool('novinky') : $p['pravidla']['novinky'], 'jazyk' => $r->post('jazyk'), 'od' => $r->post('od'), 'do' => $r->post('do'),
            'zarizeni' => $r->post('zarizeni'), 'utm' => $r->post('utm'), 'odkud' => $r->post('odkud'),
        ]);
        $this->db->update('popupy', [
            'nazev' => $name, 'adresa' => $url,
            'typ' => isset(Okna::TYPES[$r->post('typ')]) ? $r->post('typ') : $p['typ'],
            'spoustec' => isset(Okna::TRIGGERS[$r->post('spoustec')]) ? $r->post('spoustec') : $p['spoustec'],
            'hodnota' => max(0, min(3600, $r->postInt('hodnota'))),
            'cetnost' => isset(Okna::FREQUENCIES[$r->post('cetnost')]) ? $r->post('cetnost') : $p['cetnost'],
            'dni' => $r->post('dni') !== '' ? max(1, min(365, $r->postInt('dni', 7))) : (int) $p['dni'], // the field is active only for the frequency "dni"
            'poradi' => max(-9999, min(9999, $r->postInt('poradi', 100))),
            'pravidla' => (string) json_encode($rules, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s'),
            // true until and review by (2.10, Core\Validity): empty or not a date = none
            'valid_until' => \Kaleta\Core\Validity::date($r->post('valid_until')), 'review_by' => \Kaleta\Core\Validity::date($r->post('review_by')),
        ], ['idpp' => $p['idpp']]);
        \Kaleta\Front\Cache::clear();

        return $this->back('The pop-up settings were saved.');
    }

    /** Enable or disable the popup on the site; only a published one can be enabled. */
    protected function actionToggle(): Response
    {
        $p = $this->request->isPost() ? Okna::byId($this->db, $this->request->postInt('idpp')) : null;
        if ($p === null) {
            return $this->back();
        }
        // from the popup settings you stay in the settings, from the list in the list
        [$action, $args] = $this->request->post('z') === 'edit' ? ['edit', ['id' => $p['idpp']]] : ['', []];
        if (!$p['aktivni'] && $p['stavba'] === null) {
            return $this->back('Publish the pop-up in the builder first – then you can turn it on.', $action, $args, 'chyba');
        }
        $this->db->update('popupy', ['aktivni' => $p['aktivni'] ? 0 : 1], ['idpp' => $p['idpp']]);
        \Kaleta\Front\Cache::clear();

        return $this->back($p['aktivni'] ? 'The pop-up is off – it no longer shows on the site.' : 'The pop-up is on and shows on the site according to its rules.', $action, $args);
    }

    protected function actionReset(): Response
    {
        if ($this->request->isPost()) {
            $this->db->update('popupy', ['zobrazeni' => 0, 'zavreni' => 0, 'konverze' => 0], ['idpp' => $this->request->postInt('idpp')]);
        }

        return $this->back('The pop-up counters were reset.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('popupy', ['idpp' => $this->request->postInt('idpp')]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back('The pop-up was deleted.');
    }

    /** Pages and collections for the choice "where the popup appears", site languages. @return array<string, mixed> */
    private function options(): array
    {
        $siteSettings = $this->app->settings();
        $languages = array_merge([Language::defaults($siteSettings)], Language::additional($siteSettings));

        return [
            'pages' => $this->db->all('SELECT ids, titulek, jazyk FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek LIMIT 500'),
            'collection' => $this->db->all('SELECT seo_link, nazev FROM {kolekce} WHERE detail = 1 ORDER BY nazev'),
            'languages' => count($languages) > 1 ? array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[$j][0] ?? $j, $languages)) : [],
        ];
    }

    /* ---------- builder ---------- */

    protected function loadBuildTarget(): ?array
    {
        $p = Okna::byId($this->db, $this->request->getInt('id'));

        return $p === null ? null : [
            'radek' => $p, 'stavba' => $p['stavba'], 'koncept' => $p['stavba_koncept'], 'jazyk' => Language::defaults($this->app->settings()),
            'titulek' => t('Pop-up: %s', $p['nazev']), 'revize' => ['cast' => 'popup:' . $p['idpp']], 'parametry' => ['id' => $p['idpp']],
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('popupy', ['stavba_koncept' => $draft], ['idpp' => $target['radek']['idpp']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::popup($this->app, $target['radek']);
    }

    protected function describeTarget(array $target): array
    {
        $p = $target['radek'];
        $url = $this->app->url('_popup/' . $p['idpp']);

        return [
            'adresa' => $url . '?build=koncept', 'nahled' => $url . '?build=koncept&editor=1', 'zobrazena' => (bool) $p['aktivni'], 'casti' => false,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Pop-ups')], 'nastaveni' => $this->url('edit', ['id' => $p['idpp']]),
            'textNastaveni' => t('Pop-up settings (when and where it shows)'), 'podpis' => 'popup:' . $p['idpp'],
        ];
    }
}
