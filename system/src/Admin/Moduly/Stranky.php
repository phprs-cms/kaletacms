<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;
use MiroCMS\Stavitel\Publikace;
use MiroCMS\Stavitel\Stavba;

/**
 * Stránky webu: úvod, O nás, Služby, Kontakt, Zásady ochrany soukromí… Úvodní stránku určuje Nastavení → Základní.
 * Stránka má adresu /<seo_link>. Obsah je buď text z editoru, nebo stavba ze stavitele (sloupec stavba, rozpracovaná stavba_koncept).
 */
final class Stranky extends Modul
{
    use \MiroCMS\Admin\StavitelAkce {
        akceStavitel as protected editorStavby;
    }

    public const string IDENT = 'stranky';
    public const string NAZEV = 'Stránky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'stranky';

    /** Adresy, které patří systému a stránka je mít nemůže. */
    public const array VYHRAZENE = ['novinky', 'hledani', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'stav', 'ulohy', 'souhlas'];

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Stránky', ['stranky' => $this->db->all('SELECT * FROM {stranky} ORDER BY poradi, titulek')]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['ids' => 0, 'seo_link' => '', 'titulek' => '', 'popis' => '', 'text' => '', 'zobrazit' => 1, 'v_menu' => 1, 'poradi' => 100, 'stavba' => null]);
    }

    protected function akceEdit(): Response
    {
        $stranka = $this->db->one('SELECT * FROM {stranky} WHERE ids = ?', [$this->request->getInt('id')]);

        return $stranka === null ? $this->chyba('Stránka neexistuje.', 404) : $this->formular($stranka);
    }

    /** Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen název a text stránky. */
    protected function akceUlozText(): Response
    {
        $r = $this->request;
        $stranka = $r->isPost() ? $this->db->one('SELECT * FROM {stranky} WHERE ids = ?', [$r->postInt('id')]) : null;
        if ($stranka === null) {
            return $this->zpetNaWeb($r->post('zpet'));
        }
        $titulek = mb_substr($r->post('titulek'), 0, 200);
        if ($titulek === '') {
            return $this->zpetNaWeb($r->post('zpet'), '?upravit=text&chyba=1');
        }
        $this->db->update('stranky', ['titulek' => $titulek, 'text' => $r->post('text'), 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $stranka['ids']]);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'stranky', 'úprava přímo na webu', mb_substr($titulek, 0, 80));

        return $this->zpetNaWeb($r->post('zpet'));
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('ids');
        $data = [
            'titulek' => mb_substr($r->post('titulek'), 0, 200),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('titulek'), 110),
            'popis' => mb_substr($r->post('popis'), 0, 300),
            'text' => $r->post('text'),
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'v_menu' => (int) $r->postBool('v_menu'),
            'poradi' => max(0, min(65535, $r->postInt('poradi', 100))),
            'zmeneno' => date('Y-m-d H:i:s'),
            'jazyk' => \MiroCMS\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk')),
        ];
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        $chyby = [];
        if ($data['titulek'] === '') {
            $chyby['titulek'] = 'Vyplňte název stránky.';
        }
        if (in_array($data['seo_link'], self::VYHRAZENE, true) || isset(\MiroCMS\Core\Jazyk::DOSTUPNE[$data['seo_link']])) {
            $chyby['seo_link'] = 'Tuto adresu používá systém, zvolte jinou.';
        } elseif ($this->db->value('SELECT ids FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$data['seo_link'], $id]) !== null) {
            $chyby['seo_link'] = 'Stránka s touto adresou už existuje.';
        }
        if ($chyby !== []) {
            return $this->formular(['ids' => $id] + $data, $chyby);
        }
        if ($id > 0) {
            $puvodni = $this->db->one('SELECT seo_link, zobrazit FROM {stranky} WHERE ids = ?', [$id]);
            $this->db->update('stranky', $data, ['ids' => $id]);
            if ($puvodni !== null && $puvodni['zobrazit'] && $puvodni['seo_link'] !== $data['seo_link']) {
                // zobrazená stránka změnila adresu: stará se přesměruje na novou
                Presmerovani::pridej($this->db, $puvodni['seo_link'], $data['seo_link']);
            }
        } else {
            $id = $this->db->insert('stranky', $data);
        }
        if ($r->post('po_ulozeni') === 'stavitel') {
            return \MiroCMS\Core\Response::redirect($this->url('stavitel', ['id' => $id]));
        }

        return $this->zpet('Stránka byla uložena.');
    }

    /* ---------- stavitel (akce v Admin\StavitelAkce) ---------- */

    /** Editor; textová stránka se při prvním otevření převede na stavbu (úzká sekce s nadpisem a textem, text zůstane). */
    protected function akceStavitel(): Response
    {
        $stranka = $this->nactiStranku($this->request->getInt('id'));
        if ($stranka !== null && $stranka['stavba'] === null && $stranka['stavba_koncept'] === null) {
            $this->db->update('stranky', ['stavba_koncept' => Stavba::naJson(Stavba::zTextu($stranka['titulek'], (string) $stranka['text']))], ['ids' => $stranka['ids']]);
        }

        return $this->editorStavby();
    }

    protected function cilStavby(): ?array
    {
        $stranka = $this->nactiStranku($this->request->getInt('id'));

        return $stranka === null ? null : [
            'radek' => $stranka, 'stavba' => $stranka['stavba'], 'koncept' => $stranka['stavba_koncept'], 'jazyk' => $this->jazykObsahu($stranka['jazyk']),
            'titulek' => $stranka['titulek'], 'revize' => ['ids' => (int) $stranka['ids']], 'parametry' => ['id' => (int) $stranka['ids']],
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('stranky', ['stavba_koncept' => $koncept], ['ids' => $cil['radek']['ids']]);
    }

    protected function publikujCil(array $cil): void
    {
        Publikace::stranka($this->app, $cil['radek']);
    }

    protected function editorCile(array $cil): array
    {
        $stranka = $cil['radek'];
        $uvod = $this->app->settings()->int('titulni_stranka') === (int) $stranka['ids'];
        $adresa = $this->app->url(($stranka['jazyk'] !== '' ? $stranka['jazyk'] . '/' : '') . ($uvod ? '' : $stranka['seo_link']));

        return [
            'adresa' => $adresa, 'nahled' => $adresa . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $stranka['zobrazit'], 'casti' => false,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Stránky')], 'nastaveni' => $this->url('edit', ['id' => (int) $stranka['ids']]),
        ];
    }

    /** Publikuje koncept stránky (i z MCP). */
    public static function publikuj(\MiroCMS\Core\App $app, array $stranka): void
    {
        Publikace::stranka($app, $stranka);
    }

    /** Stránka se vrátí k textu z editoru (stavba zůstane ve verzích). */
    protected function akceStavbaText(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->postInt('ids')) : null;
        if ($stranka !== null && $stranka['stavba'] !== null) {
            $this->db->insert('stavba_revize', ['ids' => $stranka['ids'], 'datum' => date('Y-m-d H:i:s'), 'kdo' => $this->app->auth()->id(), 'stavba' => $stranka['stavba']]);
            $this->db->update('stranky', ['stavba' => null, 'stavba_koncept' => null], ['ids' => $stranka['ids']]);
        }

        return $this->zpet('Stránka zobrazuje text z editoru (obsah stavby bez rozložení). Stavbu najdete ve verzích, když otevřete stavitel.', 'edit', ['id' => (int) ($stranka['ids'] ?? 0)]);
    }

    /** @return array<string, mixed>|null */
    private function nactiStranku(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {stranky} WHERE ids = ?', [$id]);
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('stranky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->zpet('Stránka byla smazána.');
    }

    /**
     * @param array<string, mixed> $stranka
     * @param array<string, string> $chyby
     */
    private function formular(array $stranka, array $chyby = []): Response
    {
        return $this->view('formular', $stranka['ids'] ? 'Úprava stránky' : 'Nová stránka', ['stranka' => $stranka, 'chyby' => $chyby]);
    }
}
