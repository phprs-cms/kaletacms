<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/** Údaj z nastavení webu (copyright, e-mail, popis, sociální sítě…) – změní se všude, když se změní v Nastavení. */
final class Udaje extends Prvek
{
    public const string TYP = 'udaje';
    public const string NAZEV = 'Údaje webu';
    public const string POPIS = 'Copyright, e-mail, popis webu, text patičky nebo odkazy na sociální sítě z Nastavení.';
    public const string IKONA = 'udaje';
    public const string SKUPINA = 'Části webu';
    public const array ZNACKY = ['p', 'div', 'span'];
    public const bool JEN_CASTI = true;

    public static function vlastnosti(): array
    {
        return ['udaj' => ['typ' => 'vyber', 'popisek' => 'Údaj', 'vychozi' => 'copyright', 'moznosti' => [
            'copyright' => '© rok a název webu', 'nazev' => 'Název webu', 'popis' => 'Popis webu', 'text_paticky' => 'Text patičky',
            'email' => 'E-mail webu', 'site' => 'Sociální sítě', 'rss' => 'Odkaz na RSS',
        ]]];
    }

    public static function zakladniCss(): string
    {
        return '.mc-site { display: flex; flex-wrap: wrap; gap: var(--mc-mezera-xs) var(--mc-mezera-s); margin: 0; padding: 0; list-style: none; }
.mc-site a, .mc-udaj a { color: inherit; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $web = $k->app->settings();
        $z = $p['znacka'];
        $obal = fn (string $html): string => $html === '' && !$k->editor ? '' : '<' . $z . Text::sTridou($a, 'mc-udaj') . '>' . ($html !== '' ? $html : e(t('(údaj není vyplněný v Nastavení)'))) . '</' . $z . '>';

        return match ($p['obsah']['udaj']) {
            'copyright' => $obal('&copy; ' . date('Y') . ' ' . e($web->get('nazev_webu'))),
            'nazev' => $obal(e($web->get('nazev_webu'))),
            'popis' => $obal(e($web->get('popis_webu'))),
            'text_paticky' => $obal(e($web->get('text_paticky'))),
            'email' => $obal($web->get('email_webu') !== '' ? '<a href="mailto:' . e($web->get('email_webu')) . '">' . e($web->get('email_webu')) . '</a>' : ''),
            'rss' => $obal('<a href="' . e($k->url('rss.xml')) . '">RSS</a>'),
            'site' => self::site($web, $a, $k),
            default => '',
        };
    }

    private static function site(\MiroCMS\Core\Settings $web, string $a, Kontext $k): string
    {
        $site = array_filter(['LinkedIn' => $web->get('soc_linkedin'), 'Facebook' => $web->get('soc_facebook'), 'Instagram' => $web->get('soc_instagram'), 'YouTube' => $web->get('soc_youtube'), 'X' => $web->get('soc_x')]);
        if ($site === []) {
            return $k->editor ? '<p' . $a . '>' . e(t('Sociální sítě doplníte v Nastavení.')) . '</p>' : '';
        }

        return '<ul' . Text::sTridou($a, 'mc-site') . '>' . implode('', array_map(fn (string $n, string $u): string => '<li><a href="' . e($u) . '" rel="me noopener" target="_blank">' . e($n) . '</a></li>', array_keys($site), $site)) . '</ul>';
    }
}
