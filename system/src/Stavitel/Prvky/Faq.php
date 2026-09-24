<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Rozbalovací položky (akordeon) jako <details> – bez JavaScriptu. Volitelně otevřená vždy jen jedna (atribut name)
 * a strukturovaná data FAQPage – ta jen ve stránce, ne v záhlaví a patičce (jinak by FAQ byla každá stránka webu).
 */
final class Faq extends Prvek
{
    public const string TYP = 'faq';
    public const string NAZEV = 'Otázky a odpovědi (akordeon)';
    public const string POPIS = 'Rozbalovací položky – otázky (FAQ pro vyhledávače) nebo jakýkoli obsah, který nemusí být vidět hned.';
    public const string IKONA = 'faq';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return ['polozky' => ['typ' => 'polozky', 'popisek' => 'Otázky', 'max' => 30, 'pole' => [
            'otazka' => ['typ' => 'text', 'popisek' => 'Otázka', 'vychozi' => '', 'max' => 300],
            'odpoved' => ['typ' => 'html', 'popisek' => 'Odpověď', 'vychozi' => ''],
        ], 'vychozi' => [['otazka' => t('Jak dlouho trvá realizace?'), 'odpoved' => '<p>' . t('Obvykle dva až čtyři týdny podle rozsahu.') . '</p>'], ['otazka' => t('Kolik to stojí?'), 'odpoved' => '<p>' . t('Cenu vám připravíme na míru – ozvěte se nám.') . '</p>']]],
            'jedna' => ['typ' => 'prepinac', 'popisek' => 'Otevřená vždy jen jedna položka', 'vychozi' => false],
            'faq' => ['typ' => 'prepinac', 'popisek' => 'Jsou to otázky a odpovědi (FAQ pro vyhledávače)', 'vychozi' => true]];
    }

    public static function zakladniCss(): string
    {
        return '.mc-faq details { border-block-end: 1px solid var(--mc-barva-linka); }
.mc-faq summary { display: flex; justify-content: space-between; gap: 1em; padding-block: var(--mc-mezera-s); font-weight: 600; cursor: pointer; list-style: none; }
.mc-faq summary::-webkit-details-marker { display: none; }
.mc-faq summary::after { content: "+"; font-size: 1.4em; line-height: 1; color: var(--mc-barva-primarni); transition: rotate 0.2s; }
.mc-faq details[open] summary::after { rotate: 45deg; }
.mc-faq details > div { padding-block-end: var(--mc-mezera-s); color: var(--mc-barva-tlumeny); }
.mc-faq details > div > :last-child { margin-block-end: 0; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $html = '';
        $faq = $p['obsah']['faq'] && !str_starts_with($k->zdroj, 'cast:');
        $skupina = $p['obsah']['jedna'] ? ' name="faq-' . e($p['id']) . '"' : '';
        foreach ($p['obsah']['polozky'] as $i => $polozka) {
            if ($polozka['otazka'] === '') {
                continue;
            }
            if ($faq) {
                $k->faq[] = [$polozka['otazka'], trim(strip_tags($polozka['odpoved']))];
            }
            $html .= '<details' . $skupina . ($i === 0 && $k->editor ? ' open' : '') . '><summary>' . e($polozka['otazka']) . '</summary><div>' . $polozka['odpoved'] . '</div></details>';
        }

        return '<div' . Text::sTridou($a, 'mc-faq') . '>' . $html . '</div>';
    }
}
