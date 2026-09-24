<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Mapa s místem firmy. Cizí mapa (Google) se načte až po klepnutí – do té doby web nic neposílá třetí straně
 * a nepotřebuje souhlas s cookies. Vedle je vždy obyčejný odkaz na mapu.
 */
final class Mapa extends Prvek
{
    public const string TYP = 'mapa';
    public const string NAZEV = 'Mapa';
    public const string POPIS = 'Mapa s adresou firmy – načte se až po klepnutí (soukromí, rychlost).';
    public const string IKONA = 'mapa';
    public const array ZNACKY = ['figure', 'div'];

    public static function vlastnosti(): array
    {
        return [
            'adresa' => ['typ' => 'text', 'popisek' => 'Adresa nebo souřadnice (prázdné = adresa firmy z Nastavení)', 'vychozi' => '', 'max' => 200],
            'priblizeni' => ['typ' => 'vyber', 'popisek' => 'Přiblížení', 'vychozi' => '15', 'moznosti' => ['11' => 'město', '13' => 'čtvrť', '15' => 'ulice', '17' => 'dům']],
        ];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['pomer_stran' => '16/9', 'zaobleni' => 'm', 'orez' => 'hidden']];
    }

    public static function zakladniCss(): string
    {
        return '.ka-mapa { position: relative; margin: 0; min-height: 16rem; background: var(--ka-barva-plocha); }
.ka-mapa > button, .ka-mapa > iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
.ka-mapa > button { display: grid; place-content: center; gap: var(--ka-mezera-xs); padding: var(--ka-mezera-m); background: var(--ka-barva-plocha); color: var(--ka-barva-text); font: inherit; text-align: center; cursor: pointer; }
.ka-mapa > button strong { font-size: var(--ka-krok-1); }
.ka-mapa > button small { color: var(--ka-barva-tlumeny); }
.ka-mapa > button:hover strong { color: var(--ka-barva-primarni); }
.ka-mapa figcaption { position: absolute; inset: auto 0 0 auto; padding: 0.3em 0.7em; background: var(--ka-barva-pozadi); font-size: var(--ka-krok--1); border-radius: var(--ka-zaobleni-s) 0 0 0; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $web = $k->app->settings();
        $adresa = $p['obsah']['adresa'] !== '' ? $p['obsah']['adresa']
            : ($web->get('firma_gps') !== '' ? $web->get('firma_gps') : trim(implode(', ', array_filter([$web->get('firma_ulice'), $web->get('firma_psc') . ' ' . $web->get('firma_mesto')])), ', '));
        if (trim($adresa) === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Vyplňte adresu v panelu Obsah nebo v Nastavení → Firma.')) . '</div>' : '';
        }
        $q = rawurlencode($adresa);
        $vlozit = 'https://maps.google.com/maps?q=' . $q . '&z=' . (int) $p['obsah']['priblizeni'] . '&output=embed';
        $odkaz = $web->get('firma_mapa') !== '' && $p['obsah']['adresa'] === '' ? $web->get('firma_mapa') : 'https://www.google.com/maps/search/?api=1&query=' . $q;
        $tlacitko = '<button type="button" data-vlozit="' . e($vlozit) . '" data-titulek="' . e(t('Mapa: %s', $adresa)) . '">'
            . '<strong>' . e(t('Zobrazit mapu')) . '</strong><span>' . e($adresa) . '</span><small>' . e(t('Po klepnutí se načte z Google Map.')) . '</small></button>';
        $popisek = '<figcaption><a href="' . e($odkaz) . '" target="_blank" rel="noopener">' . e(t('Otevřít v mapách')) . '</a></figcaption>';

        return $p['znacka'] === 'figure'
            ? '<figure' . Text::sTridou($a, 'ka-mapa') . '>' . $tlacitko . $popisek . '</figure>'
            : '<div' . Text::sTridou($a, 'ka-mapa') . '>' . $tlacitko . '</div>';
    }
}
