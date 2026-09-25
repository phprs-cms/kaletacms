<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Formátovaný text z editoru: odstavce, seznamy, podnadpisy, odkazy, tabulky. */
final class Text extends Prvek
{
    public const string TYP = 'text';
    public const string NAZEV = 'Text';
    public const string POPIS = 'Odstavce, seznamy, odkazy a tabulky z editoru.';
    public const string IKONA = 'text';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return ['html' => ['typ' => 'html', 'popisek' => 'Text', 'vychozi' => '<p>' . t('Sem napište text. Stačí pár vět, které návštěvníkovi řeknou, co ho tu čeká.') . '</p>']];
    }

    public static function zakladniCss(): string
    {
        return '.ka-text > :first-child { margin-block-start: 0; }
.ka-text > :last-child { margin-block-end: 0; }
.ka-text img { max-width: 100%; height: auto; }
.ka-text pre { max-width: 100%; overflow-x: auto; }
.ka-text :is(h2, h3)[id] { scroll-margin-top: 6rem; }
:where(.stavba) mark { background: none; color: var(--ka-barva-sekundarni); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $html = $k->vSmycce === 0 ? self::kotvy($p['obsah']['html'], $k) : $p['obsah']['html'];

        return '<div' . self::sTridou($a, 'ka-text') . '>' . $html . '</div>';
    }

    /**
     * Mezititulky h2 a h3 dostanou kotvu z textu („#what-you-need“), ať jde odkázat na konkrétní část stránky. Nadpis s vlastním
     * id zůstane, jak je; v kartách výpisu kolekce se kotvy nepřidávají (opakovaly by se).
     */
    private static function kotvy(string $html, Kontext $k): string
    {
        if (!preg_match('/<h[23]\b/i', $html)) {
            return $html;
        }

        return preg_replace_callback('#<(h[23])\b([^>]*)>(.*?)</\1>#is', function (array $m) use ($k): string {
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5));
            if ($text === '' || preg_match('/\sid\s*=/i', $m[2])) {
                return $m[0];
            }
            $id = $zaklad = slugify($text, 60);
            if ($id === '') {
                return $m[0];
            }
            for ($i = 2; isset($k->kotvy[$id]); $i++) {
                $id = $zaklad . '-' . $i;
            }
            $k->kotvy[$id] = true;

            return '<' . $m[1] . $m[2] . ' id="' . e($id) . '">' . $m[3] . '</' . $m[1] . '>';
        }, $html) ?? $html;
    }

    /** Doplní základní třídu typu do hotových atributů (před třídy uživatele). */
    public static function sTridou(string $a, string $trida): string
    {
        return str_contains($a, ' class="') ? str_replace(' class="', ' class="' . $trida . ' ', $a) : $a . ' class="' . $trida . '"';
    }
}
