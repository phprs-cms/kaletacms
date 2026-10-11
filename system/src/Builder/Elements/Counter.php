<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Core\Facts;

/**
 * Animated counter ("1,200 happy customers"): the number is complete in the HTML (search engines, screen readers, site without a script),
 * web.js counts it up from zero once when it appears on screen. Whoever does not want motion (system setting) sees the result right away.
 * The number may be a fact or a computed token (2.10: {{fact.projects}}, {{years_since:fact.founded}}, {{count:reference}}), so it never
 * goes stale – the site audit asks for one where digits are typed in.
 */
final class Counter extends Element
{
    public const string TYPE = 'pocitadlo';
    public const string NAME = 'Counter';
    public const string DESCRIPTION = 'A big number with a label that counts up when shown (years of experience, customers, projects). The number may be a fact or a computed token, so it stays true.';
    public const string ICON = 'pocitadlo';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'cislo' => ['typ' => 'text', 'popisek' => 'Number – or a fact or count token ({{fact.projects}}, {{years_since:2004}}, {{count:reference}})', 'vychozi' => t('[number]'), 'max' => 140], // 3.11: a placeholder, never an invented figure
            'pred' => ['typ' => 'text', 'popisek' => 'Before the number (e.g. “+”)', 'vychozi' => '', 'max' => 10],
            'za' => ['typ' => 'text', 'popisek' => 'After the number (e.g. “ %”, “+”, “ years”)', 'vychozi' => '+', 'max' => 20],
            'popisek' => ['typ' => 'text', 'popisek' => 'Label', 'vychozi' => t('spokojených zákazníků'), 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-pocitadlo { display: grid; gap: var(--ka-mezera-2xs); }
.ka-pocitadlo-cislo { font: 800 var(--ka-krok-5)/1 var(--ka-pismo-titulky); font-variant-numeric: tabular-nums; color: var(--ka-barva-primarni); }
.ka-pocitadlo-popisek { color: var(--ka-barva-tlumeny); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $raw = trim((string) $o['cislo']);
        if (preg_match('/^\d{1,9}$/', $raw)) {
            $number = (int) $raw;
            $format = format_count($number); // 1 200 in Czech, 1,200 in English (like Intl.NumberFormat in image/web.js)
        } elseif (preg_match(Facts::NUMBER_TOKEN_PATTERN, $raw) && !$k->editor) {
            // a fact or a computed token: the site fills it in here (not in the editor, which keeps the token); the digits drive the count-up
            $format = Facts::fillText($raw, $k->app);
            $number = (int) mb_substr((string) preg_replace('/\D+/', '', $format), 0, 9);
        } else {
            [$number, $format] = [0, $raw]; // the token in the editor, or something that is not a number – shown as typed, without the count-up
        }

        return '<div' . Text::withClass($a, 'ka-pocitadlo') . '><span class="ka-pocitadlo-cislo">' . e($o['pred'])
            . '<span' . ($number > 0 ? ' data-pocitadlo="' . $number . '"' : '') . '>' . e($format) . '</span>' . e($o['za']) . '</span>'
            . ($o['popisek'] !== '' ? '<span class="ka-pocitadlo-popisek">' . e($o['popisek']) . '</span>' : '') . '</div>';
    }
}
