<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\App;

/**
 * Stav vykreslení jedné stránky webu: co se použilo (kvůli CSS jen toho potřebného) napříč stavbou stránky i částmi webu
 * (záhlaví, patička, obálka), aby stránka dostala jediný blok CSS. Režim editoru se přepíná podle právě vykreslované stavby.
 */
final class Kontext
{
    /** @var array<string, true> typy prvků na stránce */
    public array $typy = [];

    /** @var array<string, true> třídy na stránce */
    public array $tridy = [];

    /** CSS prvků s vlastním stylem (vrstva „prvky“). */
    public string $css = '';

    /** @var list<array{0:string, 1:string}> otázky a odpovědi z prvků FAQ – pro strukturovaná data stránky */
    public array $faq = [];

    /** @var list<array{titulek:string, seo_link:string}> stránky hlavní navigace (dodává web) */
    public array $menu = [];

    /** Cesta zobrazené stránky (kvůli aria-current v navigaci). */
    public string $cesta = '';

    /** Hotový přepínač jazykových verzí webu (prázdný u jednojazyčného webu). */
    public string $jazyky = '';

    /** Odkud právě vykreslovaná stavba je: „stranka:<id>“ nebo „cast:<typ>:<jazyk>“ (formulář podle něj najde svá pole). */
    public string $zdroj = '';

    /** Obsah, který systém vkládá do obálky (prvek „Obsah stránky“): novinka, výpis, stránka 404. */
    public string $obsah = '';

    public function __construct(public readonly App $app, public bool $editor = false)
    {
    }

    public function url(string $cesta): string
    {
        return $this->app->url($cesta);
    }

    /** Adresa obrázku z media/ doplněná o cestu k instalaci; cizí adresa zůstane. */
    public function obrazek(string $src): string
    {
        return preg_match('#^(https?:)?//|^/#', $src) ? $src : $this->app->request->basePath() . '/' . $src;
    }
}
