<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Údaj z Nastavení (adresa, telefon, IČO, otevírací doba, copyright, sociální sítě…) – vyplní se jednou a změní se všude.
 * Firma v Nastavení → Firma, web v Nastavení → Základní.
 */
final class Udaje extends Prvek
{
    public const string TYP = 'udaje';
    public const string NAZEV = 'Údaje firmy';
    public const string POPIS = 'Adresa, telefon, e-mail, IČO, otevírací doba, mapa, copyright nebo sociální sítě z Nastavení.';
    public const string IKONA = 'udaje';
    public const string SKUPINA = 'Dynamické';
    public const array ZNACKY = ['p', 'div', 'span', 'address'];

    public static function vlastnosti(): array
    {
        return ['udaj' => ['typ' => 'vyber', 'popisek' => 'Údaj', 'vychozi' => 'copyright', 'moznosti' => [
            'adresa' => 'Adresa', 'telefon' => 'Telefon', 'email' => 'E-mail', 'hodiny' => 'Otevírací doba', 'mapa' => 'Odkaz na mapu',
            'firma' => 'Obchodní firma a IČO', 'copyright' => '© rok a název webu', 'nazev' => 'Název webu', 'popis' => 'Popis webu',
            'text_paticky' => 'Text patičky', 'site' => 'Sociální sítě', 'rss' => 'Odkaz na RSS',
        ]]];
    }

    public static function zakladniCss(): string
    {
        return '.ka-hodiny { margin: 0; padding: 0; list-style: none; }
.ka-udaj:is(address) { font-style: normal; }
.ka-site { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs) var(--ka-mezera-s); margin: 0; padding: 0; list-style: none; }
.ka-site a, .ka-udaj a { color: inherit; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $web = $k->app->settings();
        $z = $p['znacka'];
        $obal = fn (string $html): string => $html === '' && !$k->editor ? '' : '<' . $z . Text::sTridou($a, 'ka-udaj') . '>' . ($html !== '' ? $html : e(t('(doplňte v Nastavení → Firma)'))) . '</' . $z . '>';

        return match ($p['obsah']['udaj']) {
            'copyright' => $obal('&copy; ' . date('Y') . ' ' . e($web->get('nazev_webu'))),
            'nazev' => $obal(e($web->get('nazev_webu'))),
            'popis' => $obal(e($web->get('popis_webu'))),
            'text_paticky' => $obal(e($web->get('text_paticky'))),
            'email' => $obal(($mail = $web->get('firma_email')) !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : ''),
            'rss' => \Kaleta\Core\Rozsireni::je($web, 'novinky') ? $obal('<a href="' . e($k->url('rss.xml')) . '">RSS</a>') : '', // bez novinek RSS není
            'adresa' => $obal(implode('<br>', array_map(e(...), \Kaleta\Front\Firma::adresa($web)))),
            'telefon' => $obal($web->get('firma_telefon') !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $web->get('firma_telefon'))) . '">' . e($web->get('firma_telefon')) . '</a>' : ''),
            'mapa' => $obal($web->get('firma_mapa') !== '' ? '<a href="' . e($web->get('firma_mapa')) . '" target="_blank" rel="noopener">' . e(t('Zobrazit na mapě')) . '</a>' : ''),
            'firma' => $obal(implode('<br>', array_map(e(...), array_filter([
                $web->get('firma_nazev'),
                trim(($web->get('firma_ico') !== '' ? t('IČO') . ' ' . $web->get('firma_ico') : '') . ($web->get('firma_dic') !== '' ? ', ' . t('DIČ') . ' ' . $web->get('firma_dic') : ''), ', '),
            ])))),
            'hodiny' => ($radky = \Kaleta\Front\Firma::radkyHodin($web)) !== []
                ? '<ul' . Text::sTridou($a, 'ka-hodiny') . '>' . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $radky)) . '</ul>'
                : $obal(''),
            'site' => self::site($web, $a, $k),
            default => '',
        };
    }

    private static function site(\Kaleta\Core\Settings $web, string $a, Kontext $k): string
    {
        $site = array_filter(['LinkedIn' => $web->get('soc_linkedin'), 'Facebook' => $web->get('soc_facebook'), 'Instagram' => $web->get('soc_instagram'), 'YouTube' => $web->get('soc_youtube'), 'X' => $web->get('soc_x')]);
        if ($site === []) {
            return $k->editor ? '<p' . $a . '>' . e(t('Sociální sítě doplníte v Nastavení.')) . '</p>' : '';
        }

        return '<ul' . Text::sTridou($a, 'ka-site') . '>' . implode('', array_map(fn (string $n, string $u): string => '<li><a href="' . e($u) . '" rel="me noopener" target="_blank">' . e($n) . '</a></li>', array_keys($site), $site)) . '</ul>';
    }
}
