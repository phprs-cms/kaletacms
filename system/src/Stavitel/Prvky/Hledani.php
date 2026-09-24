<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Vyhledávací pole: formulář na /hledani (stránky, položky kolekcí a novinky; bez ohledu na diakritiku). */
final class Hledani extends Prvek
{
    public const string TYP = 'hledani';
    public const string NAZEV = 'Vyhledávání';
    public const string POPIS = 'Pole pro hledání na webu – do záhlaví, na stránku 404 nebo k výpisu.';
    public const string IKONA = 'hledat';
    public const array ZNACKY = ['form'];

    public static function vlastnosti(): array
    {
        return [
            'napoveda' => ['typ' => 'text', 'popisek' => 'Text v prázdném poli', 'vychozi' => t('Hledat na webu…'), 'max' => 80],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Tlačítko', 'vychozi' => t('Hledat'), 'max' => 40],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-hledani { display: flex; gap: var(--ka-mezera-xs); max-width: 32rem; }
.ka-hledani input { flex: 1; min-width: 0; padding: 0.6em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-hledani button { padding: 0.6em 1.1em; border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; font-weight: 600; cursor: pointer; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $id = 'hl-' . $p['id'];

        return '<form' . Text::sTridou($a, 'ka-hledani') . ' role="search" method="get" action="' . e($k->url('hledani')) . '">'
            . '<label class="ka-jen-ctecka" for="' . e($id) . '">' . e(t('Hledat na webu')) . '</label>'
            . '<input type="search" id="' . e($id) . '" name="q" minlength="3" maxlength="100" placeholder="' . e($o['napoveda']) . '" required>'
            . '<button type="submit">' . e($o['tlacitko']) . '</button></form>';
    }
}
