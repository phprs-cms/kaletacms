<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Core\Obrazky;
use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Obrázek z Médií: srcset z připravených variant, líné načítání (kromě hlavního obrázku stránky), volitelně popisek a odkaz. */
final class Obrazek extends Prvek
{
    public const string TYP = 'obrazek';
    public const string NAZEV = 'Obrázek';
    public const string POPIS = 'Fotka nebo ilustrace z Médií, volitelně s popiskem a odkazem.';
    public const string IKONA = 'obrazek';
    public const array ZNACKY = ['img'];

    public static function vlastnosti(): array
    {
        return [
            'src' => ['typ' => 'obrazek', 'popisek' => 'Obrázek', 'vychozi' => ''],
            'alt' => ['typ' => 'text', 'popisek' => 'Popis pro nevidomé (alt)', 'vychozi' => '', 'max' => 300],
            'popisek' => ['typ' => 'text', 'popisek' => 'Popisek pod obrázkem', 'vychozi' => '', 'max' => 300],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Odkaz', 'vychozi' => ''],
            'priorita' => ['typ' => 'prepinac', 'popisek' => 'Hlavní obrázek stránky (načíst hned)', 'vychozi' => false],
        ];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['sirka' => '100%', 'zaobleni' => 'm']];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        if ($o['src'] === '') {
            return $k->editor ? '<div' . $a . ' style="display:grid;place-items:center;min-height:10rem;background:var(--ka-barva-plocha);color:var(--ka-barva-tlumeny)">' . e(t('Vyberte obrázek')) . '</div>' : '';
        }
        $src = $k->obrazek($o['src']);
        $srcset = Obrazky::srcset(ltrim(preg_replace('#^' . preg_quote($k->app->request->basePath(), '#') . '/#', '', $src) ?? $src, '/'), $k->app->request->basePath());
        $popisek = $o['popisek'] !== '';
        $img = '<img' . ($popisek || $o['odkaz'] !== '' ? '' : $a) . ' src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="(max-width: 900px) 100vw, 900px"' : '')
            . ' alt="' . e($o['alt']) . '"' . ($o['priorita'] ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
        if ($o['odkaz'] !== '') {
            $img = '<a' . ($popisek ? '' : $a) . ' href="' . e($o['odkaz']) . '">' . $img . '</a>';
        }

        return $popisek ? '<figure' . Text::sTridou($a, 'ka-figura') . '>' . $img . '<figcaption>' . e($o['popisek']) . '</figcaption></figure>' : $img;
    }

    public static function zakladniCss(): string
    {
        return '.ka-figura { margin: 0; }
.ka-figura img { display: block; width: 100%; height: auto; border-radius: inherit; }
.ka-figura figcaption { margin-block-start: var(--ka-mezera-xs); font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }';
    }
}
