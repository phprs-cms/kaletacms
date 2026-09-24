<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Settings;

/**
 * Identita webu (Vzhled → Identita webu): hlavní barva a písma, které se propisují do šablon.
 *
 * Šablony s identitou počítají přes CSS proměnné --mc-akcent, --mc-pismo-titulky a --mc-pismo-text:
 * ve svém style.css je použijí s vlastní výchozí hodnotou, např. --akcent: var(--mc-akcent, #326891).
 * Písma jsou jen systémová (žádné stahování z cizích serverů - rychlost a GDPR).
 */
final class Identita
{
    /** klíč => [název, popis, CSS font-family] */
    public const array PISMA_TITULKU = [
        'vychozi' => ['Podle šablony', 'písmo, se kterým šablona přichází', ''],
        'elegantni' => ['Elegantní patkové', 'Bodoni, Didot – elegance a móda', '"Bodoni 72", Didot, "Bodoni MT", "Playfair Display", Georgia, serif'],
        'klasicke' => ['Klasické patkové', 'Georgia – seriózní a dobře čitelné', 'Georgia, "Times New Roman", Times, serif'],
        'knizni' => ['Knižní', 'Charter, Cambria – klidné a literární', 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif'],
        'moderni' => ['Moderní bezpatkové', 'systémové písmo zařízení – čisté a neutrální', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'grotesk' => ['Výrazný grotesk', 'Helvetica, Arial – výrazné a sebevědomé', '"Helvetica Neue", Helvetica, "Arial Nova", Arial, sans-serif'],
        'zaoblene' => ['Zaoblené', 'přátelské, pro služby a rodinné firmy', 'ui-rounded, "SF Pro Rounded", "Hiragino Maru Gothic ProN", Quicksand, Nunito, system-ui, sans-serif'],
        'strojove' => ['Psací stroj', 'technologie a vývoj', 'ui-monospace, "SF Mono", Menlo, Consolas, "Courier New", monospace'],
    ];

    public const array PISMA_TEXTU = [
        'vychozi' => ['Podle šablony', '', ''],
        'patkove' => ['Patkové', 'Georgia – pohodlné pro dlouhé čtení', 'Georgia, "Times New Roman", Times, serif'],
        'knizni' => ['Knižní', 'Charter, Cambria', 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif'],
        'moderni' => ['Bezpatkové', 'systémové písmo zařízení', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'grotesk' => ['Grotesk', 'Helvetica, Arial', '"Helvetica Neue", Helvetica, "Arial Nova", Arial, sans-serif'],
    ];

    /** Ikona webu do <head>. Barvy a písma webu vypisuje design systém (Stavitel\DesignSystem). */
    public static function hlava(Settings $web, string $zaklad): string
    {
        $html = '';
        $ikona = $web->get('favicon');
        if ($ikona !== '') {
            $html .= '<link rel="icon" href="' . e((preg_match('#^(https?:)?/#', $ikona) ? '' : $zaklad . '/') . $ikona) . "\">\n";
        }

        return $html;
    }
}
