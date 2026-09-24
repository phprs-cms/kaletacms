<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Front\TextNovinky;
use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Video z YouTube či Vimea nebo soubor z Médií. Cizí přehrávač se načte až po kliknutí (soukromí, rychlost). */
final class Video extends Prvek
{
    public const string TYP = 'video';
    public const string NAZEV = 'Video';
    public const string POPIS = 'YouTube, Vimeo nebo video z Médií – načte se až po kliknutí.';
    public const string IKONA = 'video';
    public const array ZNACKY = ['figure'];

    public static function vlastnosti(): array
    {
        return [
            'url' => ['typ' => 'odkaz', 'popisek' => 'Adresa videa', 'vychozi' => ''],
            'titulek' => ['typ' => 'text', 'popisek' => 'Název videa (pro čtečky)', 'vychozi' => '', 'max' => 200],
            'plakat' => ['typ' => 'obrazek', 'popisek' => 'Plakát (obrázek před spuštěním)', 'vychozi' => ''],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-medium-spustit:has(.ka-medium-plakat) { position: relative; isolation: isolate; overflow: hidden; color: #fff; }
.ka-medium-plakat { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; filter: brightness(0.7); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $html = TextNovinky::prehravac($p['obsah']['url'], $k->app->request->basePath(), $p['obsah']['titulek']);
        if ($html === '') {
            return $k->editor ? '<figure' . $a . ' class="ka-medium"></figure>' : '';
        }

        $plakat = self::obrazek((string) ($p['obsah']['plakat'] ?? ''), $k);
        if ($plakat !== '') {
            // soubor z Médií: poster; YouTube a Vimeo: vlastní obrázek za tlačítkem (náhled od služby by se musel stahovat z jejích serverů)
            $html = str_contains($html, '<video ')
                ? str_replace('<video ', '<video poster="' . e($plakat) . '" ', $html)
                : (string) preg_replace('/(<button type="button" class="ka-medium-spustit"[^>]*>)/', '$1<img class="ka-medium-plakat" src="' . e($plakat) . '" alt="" loading="lazy">', $html, 1);
        }

        // atributy prvku (id, třídy) se přidají do první značky přehrávače
        return (string) preg_replace_callback('/^<(figure|div) class="([^"]*)"/', fn (array $m): string => '<' . $m[1] . Text::sTridou($a, $m[2]), $html, 1);
    }

    /** Adresa obrázku z Médií (od kořene instalace) nebo https. */
    private static function obrazek(string $url, Kontext $k): string
    {
        if (preg_match('#^https://#i', $url)) {
            return $url;
        }

        return preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,300})$#', $url, $m) && !str_contains($m[1], '..') ? $k->app->request->basePath() . '/' . $m[1] : '';
    }
}
