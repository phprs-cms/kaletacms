<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;
use MiroCMS\Front\Identita;
use MiroCMS\Front\Layouty;

/**
 * Identita webu: šablona, logo, ikona, hlavní barva a písma. Propisuje se do všech dodávaných šablon.
 */
final class Vzhled extends Modul
{
    public const string IDENT = 'vzhled';
    public const string NAZEV = 'Identita webu';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'identita';
    public const bool JEN_ADMIN = true;

    private const array KLICE = ['layout', 'logo_webu', 'favicon', 'brand_akcent', 'brand_pismo_titulky', 'brand_pismo_text', 'nazev_webu', 'tmavy_rezim'];

    protected function akceVypis(): Response
    {
        $web = $this->app->settings();

        return $this->view('vypis', 'Identita webu', [
            'layouty' => Layouty::seznam(),
            'hodnoty' => array_combine(self::KLICE, array_map($web->get(...), self::KLICE)),
        ]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $web = $this->app->settings();
        if (isset(Layouty::seznam()[$r->post('layout')])) {
            $web->set('layout', $r->post('layout'));
        }
        $web->set('logo_webu', mb_substr($r->post('logo_webu'), 0, 255));
        $web->set('favicon', mb_substr($r->post('favicon'), 0, 255));
        $vlastni = $r->post('akcent_vlastni') === '1' && preg_match('/^#[0-9a-f]{6}$/i', $r->post('brand_akcent'));
        $web->set('brand_akcent', $vlastni ? strtolower($r->post('brand_akcent')) : '');
        $web->set('brand_pismo_titulky', isset(Identita::PISMA_TITULKU[$r->post('brand_pismo_titulky')]) ? $r->post('brand_pismo_titulky') : 'vychozi');
        $web->set('tmavy_rezim', $r->post('tmavy_rezim') === 'auto' ? 'auto' : 'vypnuto');
        $web->set('brand_pismo_text', isset(Identita::PISMA_TEXTU[$r->post('brand_pismo_text')]) ? $r->post('brand_pismo_text') : 'vychozi');

        return $this->zpet('Identita webu byla uložena.');
    }
}
