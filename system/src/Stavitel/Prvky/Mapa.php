<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

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
        return '.mc-mapa { position: relative; margin: 0; min-height: 16rem; background: var(--mc-barva-plocha); }
.mc-mapa > button, .mc-mapa > iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
.mc-mapa > button { display: grid; place-content: center; gap: var(--mc-mezera-xs); padding: var(--mc-mezera-m); background: var(--mc-barva-plocha); color: var(--mc-barva-text); font: inherit; text-align: center; cursor: pointer; }
.mc-mapa > button strong { font-size: var(--mc-krok-1); }
.mc-mapa > button small { color: var(--mc-barva-tlumeny); }
.mc-mapa > button:hover strong { color: var(--mc-barva-primarni); }
.mc-mapa figcaption { position: absolute; inset: auto 0 0 auto; padding: 0.3em 0.7em; background: var(--mc-barva-pozadi); font-size: var(--mc-krok--1); border-radius: var(--mc-zaobleni-s) 0 0 0; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $web = $k->app->settings();
        $adresa = $p['obsah']['adresa'] !== '' ? $p['obsah']['adresa']
            : ($web->get('firma_gps') !== '' ? $web->get('firma_gps') : trim(implode(', ', array_filter([$web->get('firma_ulice'), $web->get('firma_psc') . ' ' . $web->get('firma_mesto')])), ', '));
        if (trim($adresa) === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--mc-barva-plocha)">' . e(t('Vyplňte adresu v panelu Obsah nebo v Nastavení → Firma.')) . '</div>' : '';
        }
        $q = rawurlencode($adresa);
        $vlozit = 'https://maps.google.com/maps?q=' . $q . '&z=' . (int) $p['obsah']['priblizeni'] . '&output=embed';
        $odkaz = $web->get('firma_mapa') !== '' && $p['obsah']['adresa'] === '' ? $web->get('firma_mapa') : 'https://www.google.com/maps/search/?api=1&query=' . $q;
        $tlacitko = '<button type="button" data-vlozit="' . e($vlozit) . '" data-titulek="' . e(t('Mapa: %s', $adresa)) . '">'
            . '<strong>' . e(t('Zobrazit mapu')) . '</strong><span>' . e($adresa) . '</span><small>' . e(t('Po klepnutí se načte z Google Map.')) . '</small></button>';
        $popisek = '<figcaption><a href="' . e($odkaz) . '" target="_blank" rel="noopener">' . e(t('Otevřít v mapách')) . '</a></figcaption>';

        return $p['znacka'] === 'figure'
            ? '<figure' . Text::sTridou($a, 'mc-mapa') . '>' . $tlacitko . $popisek . '</figure>'
            : '<div' . Text::sTridou($a, 'mc-mapa') . '>' . $tlacitko . '</div>';
    }
}
