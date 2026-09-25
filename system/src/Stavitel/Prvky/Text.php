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
:where(.stavba) mark { background: none; color: var(--ka-barva-sekundarni); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return '<div' . self::sTridou($a, 'ka-text') . '>' . $p['obsah']['html'] . '</div>';
    }

    /** Doplní základní třídu typu do hotových atributů (před třídy uživatele). */
    public static function sTridou(string $a, string $trida): string
    {
        return str_contains($a, ' class="') ? str_replace(' class="', ' class="' . $trida . ' ', $a) : $a . ' class="' . $trida . '"';
    }
}
