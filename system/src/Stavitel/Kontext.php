<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\App;

/** Stav jednoho vykreslení stavby: web, režim editoru a co se na stránce použilo (kvůli CSS jen toho potřebného). */
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

    public function __construct(public readonly App $app, public readonly bool $editor = false)
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
