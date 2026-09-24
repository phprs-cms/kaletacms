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
        ];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $html = TextNovinky::prehravac($p['obsah']['url'], $k->app->request->basePath(), $p['obsah']['titulek']);
        if ($html === '') {
            return $k->editor ? '<figure' . $a . ' class="ka-medium"></figure>' : '';
        }

        // atributy prvku (id, třídy) se přidají do první značky přehrávače
        return (string) preg_replace_callback('/^<(figure|div) class="([^"]*)"/', fn (array $m): string => '<' . $m[1] . Text::sTridou($a, $m[2]), $html, 1);
    }
}
