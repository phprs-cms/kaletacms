<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Komponenty;
use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Použití komponenty: vloží její publikovanou stavbu a dosadí vlastní hodnoty jejích {{vlastností}}.
 * Na webu nemá vlastní značku (vypíše rovnou obsah komponenty); v editoru ji obalí prvek, aby šla vybrat jako celek.
 */
final class Komponenta extends Prvek
{
    public const string TYP = 'komponenta';
    public const string NAZEV = 'Komponenta';
    public const string POPIS = 'Znovupoužitelný blok – úprava komponenty se projeví všude, kde je použitá.';
    public const string IKONA = 'komponenta';
    public const string SKUPINA = 'Pokročilé';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return [
            'komponenta' => ['typ' => 'text', 'popisek' => 'Komponenta', 'vychozi' => '', 'max' => 12],
            'hodnoty' => ['typ' => 'hodnoty', 'popisek' => 'Vlastnosti', 'vychozi' => []],
        ];
    }

    /** Obsah komponenty s hodnotami tohoto použití (volá Stavba při vykreslení). */
    public static function vnitrek(array $p, Kontext $k, callable $vykresli): string
    {
        $id = (int) $p['obsah']['komponenta'];
        if (!array_key_exists($id, $k->komponenty)) {
            $k->komponenty[$id] = $id > 0 ? Komponenty::podleId($k->app->db(), $id) : null;
        }
        $komponenta = $k->komponenty[$id];
        $stavba = $komponenta === null ? null : \MiroCMS\Stavitel\Stavba::zJson($komponenta['stavba'] ?? $komponenta['stavba_koncept']);
        if ($stavba === null) {
            return $k->editor ? '<p>' . e(t('Vyberte komponentu v panelu Obsah.')) . '</p>' : '';
        }
        if (in_array($id, $k->zanoreni, true) || count($k->zanoreni) >= Komponenty::MAX_ZANORENI) {
            return ''; // komponenta sama v sobě by se vykreslovala donekonečna
        }
        // uvnitř se použije jen publikovaná podoba a bez značek editoru (vybírá se komponenta jako celek);
        // komponenta může být na stránce víckrát, proto styl přes třídu jako ve výpisu kolekce
        [$polozka, $editor, $smycka] = [$k->polozka, $k->editor, $k->vSmycce];
        $k->zanoreni[] = $id;
        $k->polozka = Komponenty::hodnoty($komponenta, is_array($p['obsah']['hodnoty'] ?? null) ? $p['obsah']['hodnoty'] : []);
        $k->editor = false;
        $k->vSmycce++;
        try {
            return $vykresli($stavba);
        } finally {
            array_pop($k->zanoreni);
            [$k->polozka, $k->editor, $k->vSmycce] = [$polozka, $editor, $smycka];
        }
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return $k->editor ? '<div' . $a . ' style="display:contents">' . $deti . '</div>' : $deti;
    }
}
