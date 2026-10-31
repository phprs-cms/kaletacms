<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Countdown to a date (an event, an opening, a deadline): days, hours, minutes and seconds. The server outputs the state at the moment
 * of rendering, web.js then counts it down every second; once it has passed, the „po skončení“ (after the end) text is shown.
 */
final class Countdown extends Element
{
    public const string TYPE = 'odpocet';
    public const string NAME = 'Countdown';
    public const string DESCRIPTION = 'Time remaining until a date – an event, an opening, a registration deadline.';
    public const string ICON = 'odpocet';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            // 3.21 (U24): a date-and-time picker in the editor; the stored string stays "YYYY-MM-DD HH:MM" (Build::dateTime), so older pages are valid as they are
            'cil' => ['typ' => 'termin', 'popisek' => 'Until', 'vychozi' => date('Y-m-d', strtotime('+30 days')) . ' 09:00'],
            'konec' => ['typ' => 'text', 'popisek' => 'Text when finished', 'vychozi' => t('The event is on now.'), 'max' => 200],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-odpocet { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-s); margin: 0; }
.ka-odpocet > dl { display: contents; }
.ka-odpocet dl > div { display: grid; min-width: 4.5rem; padding: var(--ka-mezera-s); border-radius: var(--ka-zaobleni); background: var(--ka-barva-plocha); text-align: center; }
.ka-odpocet dd { order: -1; margin: 0; font: 800 var(--ka-krok-4)/1 var(--ka-pismo-titulky); font-variant-numeric: tabular-nums; }
.ka-odpocet dt { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $target = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/D', (string) $o['cil']) ? strtotime((string) $o['cil']) : false;
        if ($target === false) {
            return $k->editor ? '<p' . $a . '>' . e(t('Choose the date and time in the Content panel.')) . '</p>' : '';
        }
        $remaining = $target - time();
        if ($remaining <= 0) {
            return '<p' . Text::withClass($a, 'ka-odpocet-konec') . '>' . e($o['konec']) . '</p>';
        }
        $parts = ['d' => [intdiv($remaining, 86400), t('days')], 'h' => [intdiv($remaining % 86400, 3600), t('hodin')], 'm' => [intdiv($remaining % 3600, 60), t('minutes')], 's' => [$remaining % 60, t('seconds')]];
        $html = '';
        foreach ($parts as $key => [$number, $name]) {
            $html .= '<div><dt>' . e($name) . '</dt><dd data-cast="' . $key . '">' . ($key === 'd' ? $number : str_pad((string) $number, 2, '0', STR_PAD_LEFT)) . '</dd></div>';
        }

        // the timer role on a wrapper: on the <dl> itself it would replace the list role and orphan its <dt>/<dd> (3.5);
        // the list takes no box of its own, so the element's style still lays out the parts
        return '<div' . Text::withClass($a, 'ka-odpocet') . ' data-odpocet="' . e(date('c', $target)) . '" data-konec="' . e($o['konec']) . '" role="timer" aria-live="off"><dl>' . $html . '</dl></div>';
    }
}
