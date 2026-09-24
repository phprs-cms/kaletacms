<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Pás stránky přes celou šířku; obsah drží vnitřní obal v šířce webu (nebo úzký pro text, nebo žádný). */
final class Sekce extends Prvek
{
    public const string TYP = 'sekce';
    public const string NAZEV = 'Sekce';
    public const string POPIS = 'Pás přes celou šířku stránky s obsahem uprostřed.';
    public const string IKONA = 'sekce';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['section', 'header', 'footer', 'aside', 'article', 'div'];

    public static function vlastnosti(): array
    {
        return [
            'sirka' => ['typ' => 'vyber', 'popisek' => 'Šířka obsahu', 'vychozi' => 'obsah', 'moznosti' => ['obsah' => 'šířka webu', 'uzka' => 'úzká (text)', 'plna' => 'celá šířka']],
            'video' => ['typ' => 'odkaz', 'popisek' => 'Video na pozadí (MP4 nebo WebM z Médií, bez zvuku)', 'vychozi' => ''],
        ];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['odsazeni_y' => 'xl']];
    }

    public static function zakladniCss(): string
    {
        return '.ka-obal { width: min(100% - 2 * var(--ka-mezera-m), var(--ka-sirka)); margin-inline: auto; }
.ka-obal--uzka { width: min(100% - 2 * var(--ka-mezera-m), var(--ka-sirka-textu)); }
.ka-obal > * + * { margin-block-start: var(--ka-mezera-m); }
.ka-s-videem { position: relative; isolation: isolate; overflow: hidden; }
.ka-video-pozadi { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; }
@media (prefers-reduced-motion: reduce) { .ka-video-pozadi { display: none; } }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $sirka = $p['obsah']['sirka'] ?? 'obsah';
        $obsah = $sirka === 'plna' ? $deti : '<div class="ka-obal' . ($sirka === 'uzka' ? ' ka-obal--uzka' : '') . '">' . $deti . '</div>';

        // video na pozadí: jen soubor z Médií (cizí přehrávač by bez souhlasu posílal data); ztlumené, ve smyčce, pro čtečky skryté
        $video = (string) ($p['obsah']['video'] ?? '');
        if (preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,300}\.(mp4|webm))$#i', $video, $m) && !str_contains($m[1], '..')) {
            $obsah = '<video class="ka-video-pozadi" src="' . e($k->app->request->basePath() . '/' . $m[1]) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>' . $obsah;
            $a = Text::sTridou($a, 'ka-s-videem');
        }

        return '<' . $p['znacka'] . $a . '>' . $obsah . '</' . $p['znacka'] . '>';
    }
}
