<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Front\Clanky;
use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Výpis posledních novinek (dynamický – mění se sám, jak přibývají novinky). */
final class Novinky extends Prvek
{
    public const string TYP = 'novinky';
    public const string NAZEV = 'Novinky';
    public const string POPIS = 'Poslední novinky jako karty – aktualizují se samy.';
    public const string IKONA = 'clanek';
    public const string SKUPINA = 'Dynamické';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return [
            'pocet' => ['typ' => 'cislo', 'popisek' => 'Počet novinek', 'vychozi' => 3, 'min' => 1, 'max' => 12],
            'kategorie' => ['typ' => 'text', 'popisek' => 'Jen z kategorie (adresa, nepovinné)', 'vychozi' => '', 'max' => 120],
            'obrazky' => ['typ' => 'prepinac', 'popisek' => 'Zobrazit obrázky', 'vychozi' => true],
        ];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:18rem', 'mezera' => 'l']];
    }

    public static function zakladniCss(): string
    {
        return '.ka-novinka { display: flex; flex-direction: column; gap: var(--ka-mezera-xs); }
.ka-novinka img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: var(--ka-zaobleni); margin-block-end: var(--ka-mezera-xs); }
.ka-novinka time { font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }
.ka-novinka h3 { margin: 0; font-size: var(--ka-krok-1); }
.ka-novinka h3 a { color: inherit; text-decoration: none; }
.ka-novinka h3 a:hover { color: var(--ka-barva-primarni); }
.ka-novinka p { margin: 0; color: var(--ka-barva-tlumeny); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $ctecka = new Clanky($k->app->db(), $k->app->settings(), $k->app->request->basePath());
        $idt = $o['kategorie'] === '' ? null : $k->app->db()->value('SELECT idt FROM {kategorie} WHERE seo_link = ?', [$o['kategorie']]);
        [$novinky] = $idt === null ? $ctecka->vypis(1, (int) $o['pocet']) : $ctecka->zKategorie((int) $idt, 1, (int) $o['pocet']);
        $html = '';
        foreach ($novinky as $n) {
            $adresa = $k->url('novinky/' . $n['seo_link']);
            $html .= '<article class="ka-novinka">'
                . ($o['obrazky'] && $n['obrazek'] !== '' ? '<img src="' . e($n['obrazek']) . '" alt="" loading="lazy">' : '')
                . '<time datetime="' . e(date('c', strtotime($n['datum']))) . '">' . e(datum($n['datum'])) . '</time>'
                . '<h3><a href="' . e($adresa) . '">' . e($n['titulek']) . '</a></h3>'
                . '<p>' . e(mb_strimwidth(trim(html_entity_decode(strip_tags($n['uvod']), ENT_QUOTES | ENT_HTML5)), 0, 180, '…')) . '</p></article>';
        }
        if ($html === '' && $k->editor) {
            $html = '<p>' . e(t('Zatím tu nejsou žádné novinky.')) . '</p>';
        }

        return '<div' . $a . '>' . $html . '</div>';
    }
}
