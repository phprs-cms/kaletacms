<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

final class BulletList extends Element
{
    public const string TYPE = 'seznam';
    public const string NAME = 'Seznam';
    public const string DESCRIPTION = 'A list of points – with bullets, numbers or ticks.';
    public const string ICON = 'seznam';
    public const array HTML_TAGS = ['ul', 'ol'];

    public static function properties(): array
    {
        return [
            'polozky' => ['typ' => 'radky', 'popisek' => 'Items (one per line)', 'vychozi' => t('First benefit') . "\n" . t('Second benefit') . "\n" . t('Third benefit'), 'max' => 4000],
            'styl' => ['typ' => 'vyber', 'popisek' => 'Bullets', 'vychozi' => 'odrazky', 'moznosti' => ['odrazky' => 'běžné', 'fajfky' => 'fajfky', 'bez' => 'no bullets']],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-seznam--fajfky, .ka-seznam--bez { list-style: none; padding-inline-start: 0; }
.ka-seznam--fajfky li { position: relative; padding-inline-start: 1.6em; }
.ka-seznam--fajfky li::before { content: ""; position: absolute; inset-inline-start: 0.1em; top: 0.35em; width: 0.9em; height: 0.5em; border: solid var(--ka-barva-primarni); border-width: 0 0 0.16em 0.16em; transform: rotate(-45deg); }
.ka-seznam li + li { margin-block-start: 0.4em; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $items = array_filter(array_map(trim(...), preg_split('/\R/u', $p['obsah']['polozky']) ?: []), fn (string $r): bool => $r !== '');

        return '<' . $p['znacka'] . Text::withClass($a, 'ka-seznam ka-seznam--' . $p['obsah']['styl']) . '>'
            . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $items)) . '</' . $p['znacka'] . '>';
    }
}
