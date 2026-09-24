<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Odpočet do data (akce, otevření, uzávěrka): dny, hodiny, minuty a sekundy. Server vypíše stav v okamžiku vykreslení,
 * web.js ho pak odpočítává každou sekundu; po uplynutí se ukáže text „po skončení“.
 */
final class Odpocet extends Prvek
{
    public const string TYP = 'odpocet';
    public const string NAZEV = 'Odpočet';
    public const string POPIS = 'Zbývající čas do data – akce, otevření provozovny, uzávěrka přihlášek.';
    public const string IKONA = 'odpocet';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return [
            'cil' => ['typ' => 'text', 'popisek' => 'Do kdy (RRRR-MM-DD HH:MM)', 'vychozi' => date('Y-m-d', strtotime('+30 days')) . ' 09:00', 'max' => 16],
            'konec' => ['typ' => 'text', 'popisek' => 'Text po skončení', 'vychozi' => t('Akce právě probíhá.'), 'max' => 200],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-odpocet { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-s); margin: 0; }
.ka-odpocet > div { display: grid; min-width: 4.5rem; padding: var(--ka-mezera-s); border-radius: var(--ka-zaobleni); background: var(--ka-barva-plocha); text-align: center; }
.ka-odpocet dd { order: -1; margin: 0; font: 800 var(--ka-krok-4)/1 var(--ka-pismo-titulky); font-variant-numeric: tabular-nums; }
.ka-odpocet dt { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $cil = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', (string) $o['cil']) ? strtotime((string) $o['cil']) : false;
        if ($cil === false) {
            return $k->editor ? '<p' . $a . '>' . e(t('Zadejte datum ve tvaru RRRR-MM-DD HH:MM.')) . '</p>' : '';
        }
        $zbyva = $cil - time();
        if ($zbyva <= 0) {
            return '<p' . Text::sTridou($a, 'ka-odpocet-konec') . '>' . e($o['konec']) . '</p>';
        }
        $casti = ['d' => [intdiv($zbyva, 86400), t('dní')], 'h' => [intdiv($zbyva % 86400, 3600), t('hodin')], 'm' => [intdiv($zbyva % 3600, 60), t('minut')], 's' => [$zbyva % 60, t('sekund')]];
        $html = '';
        foreach ($casti as $klic => [$cislo, $nazev]) {
            $html .= '<div><dt>' . e($nazev) . '</dt><dd data-cast="' . $klic . '">' . ($klic === 'd' ? $cislo : str_pad((string) $cislo, 2, '0', STR_PAD_LEFT)) . '</dd></div>';
        }

        return '<dl' . Text::sTridou($a, 'ka-odpocet') . ' data-odpocet="' . e(date('c', $cil)) . '" data-konec="' . e($o['konec']) . '" role="timer" aria-live="off">' . $html . '</dl>';
    }
}
