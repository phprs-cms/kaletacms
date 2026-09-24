<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Tlačítko „nahoru“ v pravém dolním rohu. Objeví se, až návštěvník odroluje (animace řízená posunem stránky, bez skriptu);
 * kde to prohlížeč neumí, je vidět pořád. Nejlépe do patičky – pak je na všech stránkách.
 */
final class Nahoru extends Prvek
{
    public const string TYP = 'nahoru';
    public const string NAZEV = 'Tlačítko nahoru';
    public const string POPIS = 'Plovoucí šipka zpět na začátek stránky – vložte ji do patičky.';
    public const string IKONA = 'nahoru-prvek';
    public const string SKUPINA = 'Pokročilé';
    public const array ZNACKY = ['a'];

    public static function zakladniCss(): string
    {
        return '.ka-nahoru { position: fixed; inset: auto 1rem 1rem auto; z-index: 50; display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border-radius: 50%; background: var(--ka-barva-text); color: var(--ka-barva-pozadi); box-shadow: var(--ka-stin-m); }
.ka-nahoru svg { width: 1.2rem; height: 1.2rem; }
@supports (animation-timeline: scroll()) {
	.ka-nahoru { animation: ka-nahoru linear both; animation-timeline: scroll(); animation-range: 0 40vh; }
	@keyframes ka-nahoru { from { opacity: 0; visibility: hidden; translate: 0 1rem; } }
}';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        if ($k->editor) {
            return '<span' . $a . ' style="display:inline-block;padding:.4rem .8rem;border:1px dashed currentColor;border-radius:999px;font-size:.85rem">↑ ' . e(t('Tlačítko nahoru (na webu vpravo dole)')) . '</span>';
        }

        return '<a' . Text::sTridou($a, 'ka-nahoru') . ' href="#" aria-label="' . e(t('Zpět nahoru')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></a>';
    }
}
