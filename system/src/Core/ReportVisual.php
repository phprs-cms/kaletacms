<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\DesignSystem;

/**
 * The building blocks of the monthly report (3.26.1): one set of components the e-mail (Core\MonthlyReport::render), the
 * report page on screen and in print (views/admin/settings/report_print.php through Core\ReportPage) all draw with.
 *
 * Every block is e-mail safe: tables, inline styles, no SVG, no script, no web font, at most the width it is given
 * (600 px in the e-mail). Bars and charts are table cells whose widths and heights come from the data; columns of tiles
 * and groups are inline blocks that wrap on a phone (with ghost tables for Outlook). Every element has an explicit
 * background and colour, and a class (kr-*) the dark rules of darkCss() switch, so Apple Mail, Outlook and the screen view
 * in dark mode read well while Gmail's own inversion has something solid to invert. The accent is the site's design system
 * colour, darkened until bars keep a 3 : 1 contrast on white (and links 4.5 : 1); Kaleta's Ink is the fallback.
 *
 * Texts named $text are plain text (escaped here); $html is HTML the caller built and escaped.
 */
final class ReportVisual
{
    /** The card of the e-mail and the space inside it: the blocks of the e-mail are this wide at most. */
    public const int EMAIL_WIDTH = 600;
    public const int EMAIL_INNER = 548; // 600 less the card's border and its 24 px sides, rounded down

    /** One font stack everywhere – system fonts only, nothing to download. */
    public const string FONT = "-apple-system, 'Segoe UI', Roboto, Arial, sans-serif";

    /** The fixed colours (Kaleta's admin palette: Ink, Paper and their shades) and their dark-mode counterparts in darkCss(). */
    private const array LIGHT = ['ink' => '#121212', 'text' => '#2b2a27', 'muted' => '#5c5a55', 'line' => '#e4e1d8', 'track' => '#ece8de', 'page' => '#f6f4ee',
        'card' => '#ffffff', 'tile' => '#faf8f3', 'up' => '#067647', 'down' => '#c4281c', 'warn' => '#945800', 'warnBg' => '#fff4e0', 'warnLine' => '#f79009',
        'okBg' => '#ecfdf3', 'people' => '#8a877f'];

    /**
     * The colours of the report: the fixed ones and the accent from the site's design system (DesignSystem::load).
     *
     * @return array<string, string>
     */
    public static function palette(Settings $s): array
    {
        try {
            $accent = strtolower((string) (DesignSystem::load($s)['barvy']['primarni'] ?? ''));
        } catch (\Throwable) {
            $accent = '';
        }

        return self::paletteFor($accent);
    }

    /**
     * The palette for an accent colour (#rrggbb; anything else = Kaleta's Ink).
     *
     * @return array<string, string>
     */
    public static function paletteFor(string $accent): array
    {
        $accent = preg_match('/^#[0-9a-f]{6}$/iD', $accent) === 1 ? strtolower($accent) : self::LIGHT['ink'];
        $bars = self::darkenTo($accent, 3.0);
        $link = self::darkenTo($accent, 4.5);
        $onAccent = DesignSystem::contrastColor($bars);

        return self::LIGHT + ['accent' => $bars, 'onAccent' => $onAccent, 'onAccentSoft' => self::mix($onAccent, $bars, 0.22), 'link' => $link,
            'tint' => self::mix($bars, '#ffffff', 0.5)];
    }

    /** A colour darkened in steps until it has the given contrast on white. */
    private static function darkenTo(string $hex, float $ratio): string
    {
        for ($i = 0; $i < 12 && DesignSystem::contrast($hex, '#ffffff') < $ratio; $i++) {
            $hex = self::mix($hex, '#000000', 0.12);
        }

        return $hex;
    }

    /** Two #rrggbb colours mixed: $share of the second one. */
    public static function mix(string $a, string $b, float $share): string
    {
        [$x, $y] = [array_map('hexdec', str_split(ltrim($a, '#'), 2)), array_map('hexdec', str_split(ltrim($b, '#'), 2))];

        return '#' . implode('', array_map(static fn (int $i): string => str_pad(dechex((int) round($x[$i] + ($y[$i] - $x[$i]) * $share)), 2, '0', STR_PAD_LEFT), [0, 1, 2]));
    }

    /**
     * The dark-mode rules of every block (without the @media around them – the e-mail puts them in prefers-color-scheme,
     * the screen view in "screen and prefers-color-scheme"). The accent stays: bars and the header band keep the brand.
     */
    public static function darkRules(): string
    {
        return '.kr-page{background:#121212!important}.kr-card{background:#1c1c1b!important;border-color:#33322f!important}'
            . '.kr-tile,.kr-box{background:#242422!important;border-color:#33322f!important}.kr-ink,.kr-text{color:#f6f4ee!important}'
            . '.kr-muted{color:#b8b5ad!important}.kr-line{border-color:#33322f!important}.kr-track{background:#33322f!important}'
            . '.kr-up{color:#6ce9a6!important}.kr-down{color:#ff8a80!important}.kr-warn{background:#33270f!important;border-color:#fdb022!important}'
            . '.kr-ok{background:#10291d!important}.kr-link{color:#f6f4ee!important}.kr-people{background:#b8b5ad!important}';
    }

    /**
     * The whole e-mail document around the rows of the card ($rows: <tr> elements).
     *
     * @param array<string, string> $p palette()
     */
    public static function document(string $language, string $title, string $preheader, string $above, string $rows, array $p): string
    {
        $css = 'body{margin:0;padding:0;-webkit-text-size-adjust:100%;}table{border-collapse:collapse;}img{border:0;outline:none;text-decoration:none;}'
            . 'a{color:' . $p['link'] . ';}'
            . '@media (max-width:620px){.kr-pad{padding-left:16px!important;padding-right:16px!important}.kr-wide{max-width:100%!important;width:100%!important}'
            . '.kr-half{max-width:50%!important}.kr-h1{font-size:26px!important}}'
            . '@media (prefers-color-scheme:dark){' . self::darkRules() . '}';
        $hidden = $preheader === '' ? '' : '<div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:' . $p['page'] . ';">'
            . e($preheader) . str_repeat('&#8199;&#847;', 40) . '</div>';

        return '<!DOCTYPE html><html lang="' . e(Language::tag($language)) . '" dir="' . Language::dir($language) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<meta name="x-apple-disable-message-reformatting"><title>' . e($title) . '</title><style>' . $css . '</style></head>'
            . '<body class="kr-page" style="margin:0;padding:0;background:' . $p['page'] . ';">' . $hidden
            . '<table role="presentation" class="kr-page" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . $p['page'] . ';"><tr><td align="center" style="padding:24px 12px 32px;">'
            . '<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="' . self::EMAIL_WIDTH . '" align="center"><tr><td><![endif]-->'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;max-width:' . self::EMAIL_WIDTH . 'px;">'
            . ($above !== '' ? '<tr><td style="padding:0 4px 14px;text-align:left;">' . $above . '</td></tr>' : '')
            . '<tr><td><table role="presentation" class="kr-card" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:' . $p['card'] . ';border:1px solid ' . $p['line'] . ';border-radius:14px;">'
            . $rows . '</table></td></tr></table>'
            . '<!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';
    }

    /** The font as separate properties – Outlook does not read the "font" shorthand – ending with ";". */
    public static function font(int $size, int $weight = 400, string $lineHeight = '1.5'): string
    {
        return 'font-family:' . self::FONT . ';font-size:' . $size . 'px;line-height:' . $lineHeight . ';' . ($weight !== 400 ? 'font-weight:' . $weight . ';' : '');
    }

    /** A row of the e-mail card with the usual side padding. */
    public static function row(string $html, int $top = 0, int $bottom = 24): string
    {
        return '<tr><td class="kr-pad" style="padding:' . $top . 'px 24px ' . $bottom . 'px;text-align:left;">' . $html . '</td></tr>';
    }

    /**
     * The coloured band at the top: a small line above the title, the title (the month) and a line under it.
     *
     * @param array<string, string> $p
     */
    public static function header(array $p, string $kicker, string $title, string $subtitle): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="background:' . $p['accent'] . ';border-radius:13px 13px 0 0;padding:28px 24px 26px;" class="kr-pad">'
            . '<div style="' . self::font(12, 600, '1.4') . 'letter-spacing:.08em;text-transform:uppercase;color:' . $p['onAccentSoft'] . ';">' . e($kicker) . '</div>'
            . '<div class="kr-h1" style="' . self::font(30, 700, '1.15') . 'color:' . $p['onAccent'] . ';margin-top:6px;">' . e($title) . '</div>'
            . '<div style="' . self::font(15, 400, '1.5') . 'color:' . $p['onAccentSoft'] . ';margin-top:6px;">' . e($subtitle) . '</div>'
            . '</td></tr></table>';
    }

    /**
     * A paragraph.
     *
     * @param array<string, string> $p
     */
    public static function lead(string $html, array $p, int $size = 16): string
    {
        return '<p class="kr-text" style="margin:0;' . self::font($size, 400, '1.6') . 'color:' . $p['text'] . ';">' . $html . '</p>';
    }

    /**
     * A small grey note.
     *
     * @param array<string, string> $p
     */
    public static function note(string $text, array $p): string
    {
        return '<p class="kr-muted" style="margin:8px 0 0;' . self::font(12, 400, '1.5') . 'color:' . $p['muted'] . ';">' . e($text) . '</p>';
    }

    /**
     * A section heading with an optional line under it.
     *
     * @param array<string, string> $p
     */
    public static function title(string $text, array $p, string $hint = ''): string
    {
        return '<div class="kr-ink" style="' . self::font(19, 700, '1.3') . 'color:' . $p['ink'] . ';margin:0 0 ' . ($hint === '' ? '12' : '2') . 'px;">' . e($text) . '</div>'
            . ($hint !== '' ? '<div class="kr-muted" style="' . self::font(14, 400, '1.5') . 'color:' . $p['muted'] . ';margin:0 0 12px;">' . e($hint) . '</div>' : '');
    }

    /**
     * A small heading inside a section.
     *
     * @param array<string, string> $p
     */
    public static function subtitle(string $text, array $p): string
    {
        return '<div class="kr-ink" style="' . self::font(14, 700, '1.4') . 'color:' . $p['ink'] . ';margin:18px 0 2px;">' . e($text) . '</div>';
    }

    /**
     * Columns that sit side by side and wrap on a phone: inline blocks at most $width / $perRow wide (default: all in one
     * row), with a ghost table for Outlook (which knows no inline block).
     *
     * @param list<string> $cells HTML of each column
     */
    private static function columns(array $cells, int $width, string $class, int $perRow = 0): string
    {
        $perRow = $perRow > 0 ? $perRow : max(1, count($cells));
        $column = intdiv($width, $perRow);
        $html = '<div style="font-size:0;line-height:0;text-align:left;"><!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="' . $width . '"><tr><![endif]-->';
        foreach ($cells as $i => $cell) {
            // Outlook: a new row of the ghost table after every $perRow cells
            $html .= ($i > 0 && $i % $perRow === 0 ? '<!--[if mso]></tr><tr><![endif]-->' : '')
                . '<!--[if mso]><td width="' . $column . '" valign="top"><![endif]--><div class="' . $class . '" style="display:inline-block;width:100%;max-width:' . $column . 'px;vertical-align:top;">'
                . $cell . '</div><!--[if mso]></td><![endif]-->';
        }

        return $html . '<!--[if mso]></tr></table><![endif]--></div>';
    }

    /**
     * The headline numbers: a tile each, with the change against the month before (tone up | down | same) or a note.
     *
     * @param list<array{label: string, value: string, change?: string, tone?: string, note?: string}> $tiles
     * @param array<string, string> $p
     */
    public static function tiles(array $tiles, array $p, int $width = self::EMAIL_INNER): string
    {
        $cells = [];
        foreach ($tiles as $tile) {
            $tone = $tile['tone'] ?? '';
            $under = isset($tile['change']) && $tile['change'] !== ''
                ? '<div class="' . ($tone === 'up' ? 'kr-up' : ($tone === 'down' ? 'kr-down' : 'kr-muted')) . '" style="' . self::font(12, 600, '1.4') . 'color:' . ($p[$tone === 'up' ? 'up' : ($tone === 'down' ? 'down' : 'muted')]) . ';margin-top:6px;">' . e($tile['change']) . '</div>'
                : '<div class="kr-muted" style="' . self::font(12, 400, '1.4') . 'color:' . $p['muted'] . ';margin-top:6px;">' . (($tile['note'] ?? '') !== '' ? e((string) $tile['note']) : '&nbsp;') . '</div>';
            $cells[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="padding:4px;">'
                . '<table role="presentation" class="kr-tile" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . $p['tile'] . ';border:1px solid ' . $p['line'] . ';border-top:3px solid ' . $p['accent'] . ';border-radius:10px;">'
                . '<tr><td style="padding:12px 14px 12px;text-align:left;">'
                . '<div class="kr-muted" style="' . self::font(12, 600, '1.35') . 'color:' . $p['muted'] . ';">' . e($tile['label']) . '</div>'
                . '<div class="kr-ink" style="' . self::font(26, 700, '1.15') . 'color:' . $p['ink'] . ';margin-top:6px;white-space:nowrap;">' . e($tile['value']) . '</div>'
                . $under . '</td></tr></table></td></tr></table>';
        }

        return $cells === [] ? '' : self::columns($cells, $width, 'kr-half');
    }

    /**
     * Horizontal bars, one per row: the label and the number above a bar as long as the share of the largest value.
     *
     * @param list<array{0: string, 1: int, 2?: string}> $rows label, value, the value as shown (default: the number)
     * @param array<string, string> $p
     */
    public static function bars(array $rows, array $p): string
    {
        $max = max(1, ...array_map(static fn (array $r): int => max(0, (int) $r[1]), $rows ?: [[0, 0]]));
        $html = '';
        foreach ($rows as $r) {
            $share = (int) $r[1] <= 0 ? 0 : max(2, (int) round(100 * (int) $r[1] / $max));
            $html .= '<tr><td class="kr-text" style="' . self::font(14, 400, '1.4') . 'color:' . $p['text'] . ';padding:8px 12px 4px 0;word-break:break-word;">' . e($r[0]) . '</td>'
                . '<td class="kr-ink" align="right" style="' . self::font(14, 600, '1.4') . 'color:' . $p['ink'] . ';padding:8px 0 4px;white-space:nowrap;">' . e($r[2] ?? format_count((int) $r[1])) . '</td></tr>'
                . '<tr><td colspan="2" style="padding:0 0 4px;">' . self::track($share, $p['accent'], $p, 6) . '</td></tr>';
        }

        return $html === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $html . '</table>';
    }

    /**
     * One bar on its track ($share 0–100 %).
     *
     * @param array<string, string> $p
     */
    private static function track(int $share, string $color, array $p, int $height): string
    {
        $fill = $share > 0 ? '<td width="' . $share . '%" style="width:' . $share . '%;background:' . $color . ';height:' . $height . 'px;font-size:0;line-height:' . $height . 'px;border-radius:' . intdiv($height, 2) . 'px;">&nbsp;</td>' : '';

        return '<table role="presentation" class="kr-track" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . $p['track'] . ';border-radius:' . intdiv($height, 2) . 'px;"><tr>'
            . $fill . ($share < 100 ? '<td style="height:' . $height . 'px;font-size:0;line-height:' . $height . 'px;">&nbsp;</td>' : '') . '</tr></table>';
    }

    /**
     * A small column chart – one column a day – with the first and the last day under it and the highest value.
     *
     * @param list<int> $values
     * @param array<string, string> $p
     */
    public static function chart(array $values, string $first, string $last, string $peak, array $p, int $height = 64): string
    {
        if ($values === []) {
            return '';
        }
        $max = max(1, ...$values);
        $cells = '';
        foreach ($values as $value) {
            $h = $value <= 0 ? 2 : max(3, (int) round($height * $value / $max));
            $cells .= '<td valign="bottom" style="height:' . $height . 'px;padding:0 1px;vertical-align:bottom;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td ' . ($value <= 0 ? 'class="kr-track" ' : '') . 'height="' . $h . '" style="height:' . $h . 'px;background:' . ($value <= 0 ? $p['track'] : $p['accent'])
                . ';font-size:0;line-height:' . $h . 'px;mso-line-height-rule:exactly;border-radius:2px 2px 0 0;">&nbsp;</td></tr></table></td>';
        }
        $small = '' . self::font(12, 400, '1.4') . 'color:' . $p['muted'] . ';padding-top:6px;';

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="table-layout:fixed;"><tr>' . $cells . '</tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="kr-muted" style="' . $small . '">' . e($first) . '</td>'
            . '<td class="kr-muted" align="center" style="' . $small . 'text-align:center;">' . e($peak) . '</td><td class="kr-muted" align="right" style="' . $small . 'text-align:right;">' . e($last) . '</td></tr></table>';
    }

    /**
     * A bar split into shares (who did the work) with a legend under it.
     *
     * @param list<array{label: string, value: int, color: string, class?: string}> $parts
     * @param array<string, string> $p
     */
    public static function stack(array $parts, array $p): string
    {
        $total = array_sum(array_map(static fn (array $s): int => max(0, $s['value']), $parts));
        if ($total <= 0) {
            return '';
        }
        $cells = '';
        $legend = '';
        $left = 100;
        $shown = array_values(array_filter($parts, static fn (array $s): bool => $s['value'] > 0));
        foreach ($shown as $i => $s) {
            $share = $i === count($shown) - 1 ? $left : max(1, (int) round(100 * $s['value'] / $total));
            $left -= $share;
            $class = ($s['class'] ?? '') !== '' ? ' class="' . $s['class'] . '"' : '';
            $cells .= '<td' . $class . ' width="' . $share . '%" style="width:' . $share . '%;background:' . $s['color'] . ';height:14px;font-size:0;line-height:14px;">&nbsp;</td>';
            $legend .= '<span style="display:inline-block;margin:10px 18px 0 0;white-space:nowrap;"><span' . $class . ' style="display:inline-block;width:10px;height:10px;border-radius:5px;background:' . $s['color'] . ';vertical-align:middle;"></span>'
                . '<span class="kr-text" style="' . self::font(13, 400, '1.4') . 'color:' . $p['text'] . ';vertical-align:middle;padding-left:6px;">' . e($s['label']) . ' <strong>' . e(format_count($s['value'])) . '</strong></span></span>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-radius:7px;"><tr>' . $cells . '</tr></table>'
            . '<div style="font-size:0;line-height:0;">' . $legend . '</div>';
    }

    /**
     * Groups of counts, one box each: a coloured dot, the title and the total on one line, then the counts in two columns
     * (one on a phone); rows with "muted" are details that are not part of the total.
     *
     * @param list<array{title: string, total: int, color: string, class?: string, rows: list<array{0: string, 1: string, 2?: bool}>, empty: string}> $groups
     * @param array<string, string> $p
     */
    public static function groups(array $groups, array $p, int $width = self::EMAIL_INNER): string
    {
        $html = '';
        $inner = $width - 36; // the box's borders (4 + 1) and padding (2 × 14), rounded down
        foreach ($groups as $g) {
            $cells = [];
            foreach ($g['rows'] as $r) {
                $muted = !empty($r[2]);
                $cells[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
                    . '<td class="' . ($muted ? 'kr-muted' : 'kr-text') . ' kr-line" style="' . self::font(13, 400, '1.4') . 'color:' . ($muted ? $p['muted'] : $p['text']) . ';padding:7px 8px 7px 0;border-top:1px solid ' . $p['line'] . ';">' . e($r[0]) . '</td>'
                    . '<td class="' . ($muted ? 'kr-muted' : 'kr-ink') . ' kr-line" align="right" style="' . self::font(13, $muted ? 400 : 600, '1.4') . 'color:' . ($muted ? $p['muted'] : $p['ink']) . ';padding:7px 14px 7px 0;border-top:1px solid ' . $p['line'] . ';white-space:nowrap;text-align:right;">' . e($r[1]) . '</td></tr></table>';
            }
            $class = ($g['class'] ?? '') !== '' ? ' class="' . $g['class'] . '"' : '';
            $html .= '<table role="presentation" class="kr-box" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . $p['tile'] . ';border:1px solid ' . $p['line'] . ';border-left:4px solid ' . $g['color'] . ';border-radius:10px;margin:0 0 10px;">'
                . '<tr><td style="padding:12px 14px 8px;text-align:left;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
                . '<td style="' . self::font(15, 700, '1.3') . 'vertical-align:middle;"><span' . $class . ' style="display:inline-block;width:10px;height:10px;border-radius:5px;background:' . $g['color'] . ';vertical-align:middle;"></span>'
                . '<span class="kr-ink" style="color:' . $p['ink'] . ';vertical-align:middle;padding-left:8px;">' . e($g['title']) . '</span></td>'
                . '<td class="kr-ink" align="right" style="' . self::font(26, 700, '1.15') . 'color:' . $p['ink'] . ';text-align:right;white-space:nowrap;vertical-align:middle;">' . e(format_count($g['total'])) . '</td></tr></table>'
                . '<div style="height:8px;line-height:8px;font-size:0;">&nbsp;</div>'
                . ($cells !== [] ? self::columns($cells, $inner, 'kr-wide', 2)
                    : '<div class="kr-muted" style="' . self::font(13, 400, '1.4') . 'color:' . $p['muted'] . ';padding:0 0 6px;">' . e($g['empty']) . '</div>')
                . '</td></tr></table>';
        }

        return $html;
    }

    /**
     * A box that asks for something (amber) or says all is well (green), with lines that may link.
     *
     * @param list<array{0: string, 1?: string}> $items text and an optional address
     * @param array<string, string> $p
     */
    public static function callout(string $title, array $items, array $p, bool $ok = false): string
    {
        $lines = '';
        foreach ($items as $item) {
            $text = e($item[0]);
            $lines .= '<div class="kr-text" style="' . self::font(14, 400, '1.5') . 'color:' . $p['text'] . ';margin-top:6px;">'
                . (isset($item[1]) && $item[1] !== '' ? '<a class="kr-link" href="' . e($item[1]) . '" style="color:' . $p['ink'] . ';text-decoration:underline;">' . $text . '</a>' : $text) . '</div>';
        }

        return '<table role="presentation" class="' . ($ok ? 'kr-ok' : 'kr-warn') . '" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . ($ok ? $p['okBg'] : $p['warnBg'])
            . ';border-left:4px solid ' . ($ok ? $p['up'] : $p['warnLine']) . ';border-radius:8px;"><tr><td style="padding:14px 16px;text-align:left;">'
            . '<div class="kr-ink" style="' . self::font(15, 700, '1.4') . 'color:' . $p['ink'] . ';">' . ($ok ? '&#10003;&nbsp;' : '') . e($title) . '</div>' . $lines . '</td></tr></table>';
    }

    /**
     * Label and value rows with a line between them.
     *
     * @param list<array{0: string, 1: string}> $rows
     * @param array<string, string> $p
     */
    public static function pairs(array $rows, array $p): string
    {
        $html = '';
        foreach ($rows as $i => $r) {
            $border = $i === 0 ? '' : 'border-top:1px solid ' . $p['line'] . ';';
            $html .= '<tr><td class="kr-text kr-line" style="' . self::font(14, 400, '1.45') . 'color:' . $p['text'] . ';padding:8px 12px 8px 0;' . $border . '">' . e($r[0]) . '</td>'
                . '<td class="kr-ink kr-line" align="right" style="' . self::font(14, 600, '1.45') . 'color:' . $p['ink'] . ';padding:8px 0;text-align:right;' . $border . '">' . e($r[1]) . '</td></tr>';
        }

        return $html === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $html . '</table>';
    }

    /**
     * A button that works without images or CSS support (the link is the button).
     *
     * @param array<string, string> $p
     */
    public static function button(string $text, string $url, array $p): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="background:' . $p['accent'] . ';border-radius:8px;">'
            . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;' . self::font(15, 600, '1.2') . 'color:' . $p['onAccent'] . ';text-decoration:none;border-radius:8px;">' . e($text) . '</a></td></tr></table>';
    }

    /** A horizontal rule between sections. */
    public static function rule(): string
    {
        return '<tr><td class="kr-pad" style="padding:0 24px 24px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="kr-line" style="border-top:1px solid ' . self::LIGHT['line'] . ';font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>';
    }
}
