<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The uptime strip (3.26.1): one bar a day – green without an outage, amber for a brief one (99 % of the checks or
 * more answered), red for an outage, a short grey stub for a day nobody measured – with a legend and the first and the
 * last day under it. One look for the fleet console's site page (views/admin/fleet/trend.php), the site's monthly report
 * (Core\MonthlyReport 'uptime') and the console's customer report (Fleet\Report).
 *
 * E-mail safe: tables with inline styles and bgcolor only – no SVG, no CSS classes, no script – so Gmail and Outlook draw
 * it as the admin does. The colours are explicit mid tones that keep at least 3:1 against a white and a dark background,
 * because mail clients in dark mode keep or invert the page around them. The strip is one image for a screen reader
 * (role="img" with a summary); each day has its date and percent as a title, and text() says the same in plain words.
 *
 * A day is ['date' => 'Y-m-d', 'state' => 'up'|'down'|'partial'|'none', 'percent' => float|null] (day(), fromPercent()).
 * The percent is the share of answered checks rounded down, as Fleet\History::uptime counts it – never 100 % with a failure.
 */
final class UptimeStrip
{
    /** The states of a day. */
    public const array STATES = ['up', 'partial', 'down', 'none'];

    /** The colour of each tone (tone()). */
    public const array COLOURS = ['up' => '#12A150', 'weak' => '#C97F00', 'down' => '#D92D20', 'none' => '#8F8B82'];

    /** The legend of each tone, in the order it is shown. */
    public const array LEGEND = ['up' => 'No outage', 'weak' => 'Brief outage (99 % or more)', 'down' => 'Outage', 'none' => 'Not measured'];

    /** A partial day with at least this share answered is a brief outage (amber), as the fleet trend has drawn it since 3.17. */
    public const float WEAK_FROM = 99.0;

    /** The share of answered checks, rounded down to hundredths; null without a check. Pure. */
    public static function percent(int $checks, int $failed): ?float
    {
        if ($checks <= 0) {
            return null;
        }
        $failed = max(0, min($checks, $failed));

        return floor(10000 * ($checks - $failed) / $checks) / 100;
    }

    /** The state of a day from its checks: none without a check, up without a failure, down when every check failed. Pure. */
    public static function state(int $checks, int $failed): string
    {
        return match (true) {
            $checks <= 0 => 'none',
            $failed <= 0 => 'up',
            $failed >= $checks => 'down',
            default => 'partial',
        };
    }

    /**
     * One day from its checks and failures. Pure.
     *
     * @return array{date: string, state: string, percent: ?float}
     */
    public static function day(string $date, int $checks, int $failed): array
    {
        return ['date' => $date, 'state' => self::state($checks, $failed), 'percent' => self::percent($checks, $failed)];
    }

    /**
     * One day from its percent alone (the fleet trend's bars, Fleet\History::bars). Pure.
     *
     * @return array{date: string, state: string, percent: ?float}
     */
    public static function fromPercent(string $date, ?float $percent): array
    {
        $state = match (true) {
            $percent === null => 'none',
            $percent >= 100.0 => 'up',
            $percent <= 0.0 => 'down',
            default => 'partial',
        };

        return ['date' => $date, 'state' => $state, 'percent' => $percent === null ? null : max(0.0, min(100.0, $percent))];
    }

    /**
     * The colour a day is drawn in: up, weak (a brief outage), down or none. A partial day below WEAK_FROM is an outage. Pure.
     *
     * @param array<mixed> $day
     */
    public static function tone(array $day): string
    {
        $state = in_array($day['state'] ?? null, self::STATES, true) ? $day['state'] : 'none';
        $percent = is_int($day['percent'] ?? null) || is_float($day['percent'] ?? null) ? (float) $day['percent'] : null;

        return match ($state) {
            'partial' => $percent !== null && $percent >= self::WEAK_FROM ? 'weak' : 'down',
            default => $state,
        };
    }

    /** A percent as the strip writes it: "99.65 %", or "not measured". */
    public static function percentText(mixed $percent): string
    {
        return is_int($percent) || is_float($percent) ? format_number((float) $percent, 2) . ' %' : t('not measured');
    }

    /**
     * The strip as e-mail-safe HTML. Options:
     *  - label (string): what a screen reader hears for the whole strip; default summary()
     *  - height (int): the height of a bar in pixels, 8–80, default 32
     *  - legend (bool): the colours explained under the strip (only those that occur), default true
     *  - dates (bool): the first and the last day under the strip, default true
     *  - color (string): the colour of the small text under the strip (#RGB, #RRGGBB or inherit), default #6B6862
     * Nothing for an empty list of days.
     *
     * @param array<mixed> $days days (anything else in the list is skipped)
     * @param array{label?: string, height?: int, legend?: bool, dates?: bool, color?: string} $options
     */
    public static function html(array $days, array $options = []): string
    {
        $days = array_values(array_filter($days, static fn (mixed $d): bool => is_array($d) && is_string($d['date'] ?? null)));
        if ($days === []) {
            return '';
        }
        $height = max(8, min(80, (int) ($options['height'] ?? 32)));
        $stub = max(3, intdiv($height, 8));
        $color = is_string($options['color'] ?? null) && preg_match('/^(#([0-9a-f]{3}|[0-9a-f]{6})|inherit)$/iD', $options['color']) === 1 ? $options['color'] : '#6B6862';
        $small = 'font:12px/1.4 Arial,Helvetica,sans-serif;color:' . $color . ';';
        // a narrow gap between many bars, or the gaps eat the strip on a phone
        $gap = count($days) > 40 ? 1 : 2;
        $label = (string) ($options['label'] ?? self::summary($days));

        $cells = '';
        $tones = [];
        foreach ($days as $day) {
            $tone = self::tone($day);
            $tones[$tone] = true;
            $title = e(self::dateText((string) $day['date']) . ': ' . self::percentText($day['percent'] ?? null));
            $colour = self::COLOURS[$tone];
            $cells .= $tone === 'none'
                // a short stub at the bottom, like the trend's day without a check
                ? '<td title="' . $title . '" height="' . $height . '" valign="bottom" style="border:0;height:' . $height . 'px;padding:0;vertical-align:bottom;font-size:0;line-height:0;">'
                    . '<div style="height:' . $stub . 'px;background-color:' . $colour . ';font-size:0;line-height:0;">&nbsp;</div></td>'
                : '<td title="' . $title . '" bgcolor="' . $colour . '" height="' . $height . '" style="border:0;height:' . $height . 'px;padding:0;background-color:' . $colour . ';border-radius:2px;font-size:0;line-height:0;">&nbsp;</td>';
        }
        $html = '<table role="img" aria-label="' . e($label) . '" cellpadding="0" cellspacing="' . $gap . '" border="0" width="100%" style="width:100%;border-collapse:separate;border-spacing:' . $gap . 'px;table-layout:fixed;">'
            . '<tr>' . $cells . '</tr></table>';

        if (($options['dates'] ?? true) && count($days) > 1) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;margin:0;"><tr>'
                . '<td align="left" style="border:0;padding:2px ' . $gap . 'px 0;' . $small . '">' . e(self::dateText((string) $days[0]['date'])) . '</td>'
                . '<td align="right" style="border:0;padding:2px ' . $gap . 'px 0;text-align:right;' . $small . '">' . e(self::dateText((string) $days[count($days) - 1]['date'])) . '</td></tr></table>';
        }
        if ($options['legend'] ?? true) {
            $items = '';
            foreach (self::LEGEND as $tone => $text) {
                if (!isset($tones[$tone])) {
                    continue;
                }
                $swatch = $tone === 'none'
                    ? '<td width="10" valign="bottom" style="border:0;width:10px;padding:0;vertical-align:bottom;font-size:0;line-height:0;"><div style="height:3px;background-color:' . self::COLOURS[$tone] . ';font-size:0;line-height:0;">&nbsp;</div></td>'
                    : '<td width="10" height="10" bgcolor="' . self::COLOURS[$tone] . '" style="border:0;width:10px;height:10px;padding:0;background-color:' . self::COLOURS[$tone] . ';border-radius:2px;font-size:0;line-height:0;">&nbsp;</td>';
                // each item its own small table aligned left: they wrap on a phone instead of widening the e-mail
                $items .= '<table role="presentation" align="left" cellpadding="0" cellspacing="0" border="0" style="width:auto;margin:6px 14px 0 0;"><tr>' . $swatch
                    . '<td style="border:0;padding:0 0 0 6px;white-space:nowrap;' . $small . '">' . e(t($text)) . '</td></tr></table>';
            }
            $html .= '<div style="overflow:hidden;">' . $items . '</div><div style="clear:both;font-size:0;line-height:0;"></div>';
        }

        return $html;
    }

    /**
     * The strip in a sentence: how many days were measured, how many of them without an outage, and the lowest day.
     *
     * @param array<mixed> $days days (anything else in the list is skipped)
     */
    public static function summary(array $days): string
    {
        $measured = array_values(array_filter($days, static fn (mixed $d): bool => is_array($d) && self::tone($d) !== 'none'));
        if ($measured === []) {
            return t('Uptime: not measured yet.');
        }
        $lowest = array_reduce($measured, static fn (?array $low, array $d): array => $low === null || (float) $d['percent'] < (float) $low['percent'] ? $d : $low);
        $full = count(array_filter($measured, static fn (array $d): bool => self::tone($d) === 'up'));

        return $full === count($measured)
            ? t('Uptime: %d days measured, all without an outage.', count($measured))
            : t('Uptime: %d days measured, %d of them without an outage; the lowest %s on %s.', count($measured), $full, self::percentText($lowest['percent']), self::dateText((string) $lowest['date']));
    }

    /**
     * The plain-text twin of the strip for the e-mail's text part: the month's percent with the checks, the days with an
     * outage (at most 10, then how many more) and the days not measured. '' without uptime.
     *
     * @param array<string, mixed>|null $uptime ['percent', 'checks', 'failed', 'days'] (Core\MonthlyReport 'uptime')
     */
    public static function text(?array $uptime): string
    {
        if ($uptime === null) {
            return '';
        }
        $days = array_values(array_filter((array) ($uptime['days'] ?? []), static fn (mixed $d): bool => is_array($d) && is_string($d['date'] ?? null)));
        $checks = (int) ($uptime['checks'] ?? 0);
        $lines = [t('Uptime') . ': ' . self::percentText($uptime['percent'] ?? null)
            . ($checks > 0 ? ' (' . t('%s checks, %s failed', format_count($checks), format_count((int) ($uptime['failed'] ?? 0))) . ')' : '')];
        $outages = array_values(array_filter($days, static fn (array $d): bool => in_array(self::tone($d), ['weak', 'down'], true)));
        $missing = count(array_filter($days, static fn (array $d): bool => self::tone($d) === 'none'));
        if ($outages === []) {
            $lines[] = $days !== [] && $missing < count($days) ? t('No outage on any measured day.') : '';
        } else {
            $shown = array_map(static fn (array $d): string => self::dateText((string) $d['date']) . ' (' . self::percentText($d['percent'] ?? null) . ')', array_slice($outages, 0, 10));
            $lines[] = t('Days with an outage: %s', implode(', ', $shown)) . (count($outages) > 10 ? ' ' . t('and %d more', count($outages) - 10) : '');
        }
        if ($missing > 0) {
            $lines[] = t('Days not measured: %d', $missing);
        }

        return implode("\n", array_filter($lines, static fn (string $l): bool => $l !== ''));
    }

    /** A day as the strip names it (format_date in the current language); the text itself for anything that is not a date. */
    private static function dateText(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) ? format_date($date) : $date;
    }
}
