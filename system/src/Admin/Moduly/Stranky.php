<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Stránky webu: úvod, O nás, Služby, Kontakt, Zásady ochrany soukromí… Úvodní stránku určuje Nastavení → Základní.
 * Stránka má adresu /<seo_link>.
 */
final class Stranky extends Modul
{
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
        return $this->formular(['ids' => 0, 'seo_link' => '', 'titulek' => '', 'popis' => '', 'text' => '', 'zobrazit' => 1, 'v_menu' => 1, 'poradi' => 100]);
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
            $this->db->insert('stranky', $data);
        }

        return $this->zpet('Stránka byla uložena.');
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
