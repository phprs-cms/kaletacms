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

    /** @var array<string, array{0: string, 1: string}>|null hodnoty položky kolekce pro {{značky}} (uvnitř Výpisu kolekce a na detailu) */
    public ?array $polozka = null;

    /** Hloubka Výpisu kolekce: prvky uvnitř se opakují, proto mají styl přes třídu, ne přes id. */
    public int $vSmycce = 0;

    /** @var array<int, array<string, mixed>|null> načtené komponenty (jedna komponenta bývá na stránce víckrát) */
    public array $komponenty = [];

    /** @var list<int> komponenty, které se právě vykreslují (ochrana proti komponentě v sobě samé) */
    public array $zanoreni = [];

    /** @var array<string, array{pred: string, za: string}> ovládání kolem prvku (filtry a stránkování výpisu kolekce) podle id */
    public array $okoli = [];

    /** @var array<string, true> prvky, jejichž CSS už na stránce je */
    public array $styly = [];

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
