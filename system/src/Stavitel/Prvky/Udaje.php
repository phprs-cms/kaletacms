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

    /** Značka nevyplněné otevírací doby na webu: kontejner, ve kterém kromě ní zbude jen nadpis, se vynechá (Kontejner::vykresli). */
    public const string PRAZDNE_HODINY = '<!--ka-prazdne-hodiny-->';

    public static function vlastnosti(): array
    {
        return ['udaj' => ['typ' => 'vyber', 'popisek' => 'Údaj', 'vychozi' => 'copyright', 'moznosti' => [
            'adresa' => 'Adresa', 'telefon' => 'Telefon', 'email' => 'E-mail', 'hodiny' => 'Otevírací doba', 'mapa' => 'Odkaz na mapu',
            'firma' => 'Obchodní firma a IČO', 'tiraz' => 'Tiráž (všechny údaje o provozovateli)', 'copyright' => '© rok a název webu', 'nazev' => 'Název webu', 'popis' => 'Popis webu',
            'text_paticky' => 'Text patičky', 'site' => 'Sociální sítě', 'rss' => 'Odkaz na RSS',
        ]]];
    }

    public static function zakladniCss(): string
    {
        return '.ka-hodiny { margin: 0; padding: 0; list-style: none; }
.ka-udaj:is(address) { font-style: normal; }
.ka-site { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs) var(--ka-mezera-s); margin: 0; padding: 0; list-style: none; }
.ka-site a, .ka-udaj a { color: inherit; }
.ka-tiraz { display: grid; grid-template-columns: max-content 1fr; gap: var(--ka-mezera-2xs) var(--ka-mezera-m); margin: 0; }
.ka-tiraz dt { font-weight: 600; }
.ka-tiraz dd { margin: 0; }
@media (max-width: 600px) { .ka-tiraz { grid-template-columns: 1fr; } .ka-tiraz dd { margin-block-end: var(--ka-mezera-xs); } }';
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
                : ($k->editor ? $obal('') : self::PRAZDNE_HODINY),
            'site' => self::site($web, $a, $k),
            'tiraz' => self::tiraz($web, $a, $k),
            default => '',
        };
    }

    /**
     * Tiráž (Impressum): kdo web provozuje – obchodní firma, sídlo, identifikační čísla, zápis v rejstříku, zastoupení
     * a kontakt. Vypíše jen vyplněné údaje z Nastavení → Firma.
     */
    private static function tiraz(\Kaleta\Core\Settings $web, string $a, Kontext $k): string
    {
        $tel = $web->get('firma_telefon');
        $mail = $web->get('firma_email');
        $radky = array_filter([
            t('Provozovatel') => e($web->get('firma_nazev') !== '' ? $web->get('firma_nazev') : $web->get('nazev_webu')),
            t('Sídlo') => implode('<br>', array_map(e(...), \Kaleta\Front\Firma::adresa($web))),
            t('IČO') => e($web->get('firma_ico')),
            t('DIČ') => e($web->get('firma_dic')),
            t('Zápis v rejstříku') => e($web->get('firma_rejstrik')),
            t('Zastoupení') => e($web->get('firma_zastupce')),
            t('Telefon') => $tel !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $tel)) . '">' . e($tel) . '</a>' : '',
            t('E-mail') => $mail !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '',
        ], fn (string $h): bool => $h !== '');
        if (count($radky) < 2 && $k->editor) {
            return '<p' . $a . '>' . e(t('(doplňte v Nastavení → Firma)')) . '</p>';
        }

        return '<dl' . Text::sTridou($a, 'ka-tiraz') . '>' . implode('', array_map(fn (string $n, string $h): string => '<dt>' . e($n) . '</dt><dd>' . $h . '</dd>', array_keys($radky), $radky)) . '</dl>';
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
