<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;
use MiroCMS\Stavitel\Stavba;

/**
 * Vyskakovací okno (Popover API): otevře ho odkaz nebo tlačítko s adresou #<kotva okna> (Pokročilé → Kotva, jinak
 * #okno-<id prvku> – editor ji u okna ukáže), případně samo po zadané době
 * (jednou za návštěvu – image/web.js). Zavře ho křížek, Esc i klepnutí vedle. V editoru je vidět jako běžný blok.
 */
final class Okno extends Prvek
{
    public const string TYP = 'okno';
    public const string NAZEV = 'Vyskakovací okno';
    public const string POPIS = 'Okno přes stránku – otevře ho tlačítko s odkazem na kotvu okna, nebo samo po chvíli.';
    public const string IKONA = 'okno';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return [
            'samo' => ['typ' => 'vyber', 'popisek' => 'Otevřít samo', 'vychozi' => '0', 'moznosti' => ['0' => 'ne, jen odkazem', '5' => 'po 5 s', '15' => 'po 15 s', '30' => 'po 30 s']],
        ];
    }

    public static function vychoziDeti(): array
    {
        return [
            ['znacka' => 'h2'] + Stavba::novy('nadpis', ['text' => t('Nezávazná konzultace zdarma')]),
            Stavba::novy('text', ['html' => '<p>' . t('Nechte nám kontakt, ozveme se do druhého dne.') . '</p>']),
            Stavba::novy('tlacitko', ['text' => t('Kontaktujte nás'), 'odkaz' => '/kontakt']),
        ];
    }

    public static function zakladniCss(): string
    {
        // rozložení obsahu dává okno samo: vlastní „display“ ze stylu by zavřené okno ukázalo (popover skrývá display: none)
        return '.mc-okno:popover-open, .mc-okno--editor { display: flex; flex-direction: column; align-items: flex-start; gap: var(--mc-mezera-m); }
.mc-okno { inset: 0; margin: auto; width: min(36rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem); overflow: auto; padding: var(--mc-mezera-xl) var(--mc-mezera-l) var(--mc-mezera-l); border: 0; border-radius: var(--mc-zaobleni); background: var(--mc-barva-pozadi); color: var(--mc-barva-text); box-shadow: var(--mc-stin-l); }
.mc-okno::backdrop { background: rgb(0 0 0 / 0.45); }
.mc-okno-zavrit { position: absolute; top: 0.5rem; right: 0.5rem; width: 2.5rem; height: 2.5rem; border: 0; border-radius: 50%; background: none; color: inherit; font-size: 1.5rem; line-height: 1; cursor: pointer; }
.mc-okno-zavrit:hover { background: var(--mc-barva-plocha); }
.mc-okno--editor { position: relative; margin: var(--mc-mezera-m) auto; outline: 2px dashed var(--mc-barva-linka); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        // id okna: kotva prvku, nebo id stylu; bez obojího okno-<id> (na id stojí i styl prvku, proto se nemění)
        $kotva = $p['kotva'] ?? 'okno-' . $p['id'];
        if (preg_match('/ id="([^"]*)"/', $a, $m)) {
            $kotva = $m[1];
        } else {
            $a = ' id="' . e($kotva) . '"' . $a;
        }
        $zavrit = '<button type="button" class="mc-okno-zavrit" popovertarget="' . e($kotva) . '" popovertargetaction="hide" aria-label="' . e(t('Zavřít')) . '">×</button>';
        if ($k->editor) {
            // v editoru se okno ukáže na místě, aby šlo upravovat, i s adresou, kterou ho tlačítko otevře
            return '<div' . Text::sTridou($a, 'mc-okno mc-okno--editor') . '><small style="position:absolute;top:.6rem;left:1rem;color:var(--mc-barva-tlumeny)">'
                . e(t('Otevře ho odkaz #%s', $kotva)) . '</small>' . $deti . '</div>';
        }

        return '<div' . Text::sTridou($a, 'mc-okno') . ' popover role="dialog"'
            . ((int) $o['samo'] > 0 ? ' data-samo="' . (int) $o['samo'] . '"' : '') . '>' . $zavrit . $deti . '</div>';
    }
}
