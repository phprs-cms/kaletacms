<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;

/**
 * The e-mail signature of a user account (3.37): the one from the person's record in the Team collection when the account
 * has one (Builder\TeamRecord, Builder\EmailSignature), otherwise the fallback signature made from the account – name,
 * position, work phone and e-mail – and the company details. My account shows it with the Copy button, replies sent from
 * Kaleta (Core\EnquiryReply, job applications) carry it and MCP get_email_signature returns it. Nothing is stored: it is
 * made on every read, so it is current as soon as the record, the account or the company details change.
 *
 * The fallback has the standard layout of EmailSignature (the brand colour, the fonts, the logo and the photo of the
 * account) unless an administrator writes a template in Settings → Business details (email_signature_template): lines of
 * text with placeholders; a line whose placeholders are all empty is left out, so "Phone: {phone}" disappears for someone
 * without a phone. Everything from the account and the settings is escaped; the template is text, never HTML.
 */
final class UserSignature
{
    /** The placeholders of a template, in the order the settings help lists them. */
    public const array PLACEHOLDERS = ['name', 'position', 'phone', 'email', 'company', 'website', 'address'];

    /** The most lines a template keeps (a signature is short); longer lines are cut at MAX_LINE characters. */
    public const int MAX_LINES = 12;

    public const int MAX_LINE = 200;

    /**
     * The signature of a user account: from the Team record (source "team") or the fallback (source "fallback").
     *
     * @param array<string, mixed> $user a row of ka_uzivatele
     * @return array{html: string, text: string, source: 'team'|'fallback', record: array{collection: array<string, mixed>, item: array<string, mixed>, chosen: bool}|null}
     */
    public static function forUser(App $app, array $user): array
    {
        $record = TeamRecord::forUser($app->db(), $user);
        if ($record !== null) {
            return EmailSignature::forItem($app, $record['collection'], $record['item']) + ['source' => 'team', 'record' => $record];
        }

        return self::fallback($user, EmailSignature::site($app), $app->settings()->get('email_signature_template')) + ['source' => 'fallback', 'record' => null];
    }

    /**
     * The fallback signature of an account: the template's lines, or the standard layout without a template (or when the
     * template leaves no line). Pure.
     *
     * @param array<string, mixed> $user jmeno, user, pozice, phone, email, foto
     * @param array{name: string, url: string, base: string, phone: string, address: string, color: string, text_font: string, heading_font: string, logo: string} $site EmailSignature::site()
     * @return array{html: string, text: string}
     */
    public static function fallback(array $user, array $site, string $template): array
    {
        $values = self::values($user, $site);
        $made = trim($template) !== '' ? self::fromTemplate($template, $values, $site) : ['html' => '', 'text' => ''];
        if ($made['text'] === '') {
            // the standard layout of a Team record, fed with the account: the keys are the ones EmailSignature::fields() finds
            $collection = ['pole' => [['klic' => 'foto', 'typ' => 'obrazek'], ['klic' => 'pozice', 'typ' => 'text'], ['klic' => 'telefon', 'typ' => 'text'], ['klic' => 'email', 'typ' => 'text']]];
            $item = ['nazev' => $values['name'], 'data' => ['foto' => trim((string) ($user['foto'] ?? '')), 'pozice' => $values['position'], 'telefon' => trim((string) ($user['phone'] ?? '')), 'email' => $values['email']]];

            return EmailSignature::render($collection, $item, $site);
        }

        return $made; // a template that leaves nothing (every line empty) falls back to the standard layout above
    }

    /**
     * What the placeholders stand for. The phone is the account's, the company phone when it has none (as for a Team record).
     *
     * @param array<string, mixed> $user
     * @param array{name: string, url: string, phone: string, address: string} $site
     * @return array<string, string>
     */
    public static function values(array $user, array $site): array
    {
        $clean = fn (mixed $v): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        $name = $clean($user['jmeno'] ?? '') !== '' ? $clean($user['jmeno']) : $clean($user['user'] ?? '');
        $email = $clean($user['email'] ?? '');
        $url = preg_match('#^https?://[^\s"<>]+$#D', $site['url']) ? $site['url'] : '';

        return [
            'name' => $name,
            'position' => $clean($user['pozice'] ?? ''),
            'phone' => $clean($user['phone'] ?? '') !== '' ? $clean($user['phone']) : $clean($site['phone']),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'company' => $clean($site['name']),
            'website' => (string) preg_replace('#^https?://|/$#', '', $url),
            'address' => $clean($site['address']),
        ];
    }

    /**
     * The signature from a template: the plain text line by line, the HTML in the frame of the standard layout (the brand
     * colour on the left, the site's fonts), the first line as the heading, the phone, the e-mail and the website as links.
     *
     * @param array<string, string> $values self::values()
     * @param array{url: string, color: string, text_font: string, heading_font: string} $site
     * @return array{html: string, text: string}
     */
    public static function fromTemplate(string $template, array $values, array $site): array
    {
        $color = preg_match('/^#[0-9a-f]{6}$/iD', $site['color']) ? strtolower($site['color']) : '#121212';
        $url = preg_match('#^https?://[^\s"<>]+$#D', $site['url']) ? $site['url'] : '';
        $fallback = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
        $font = e($site['text_font'] !== '' ? $site['text_font'] : $fallback);
        $headingFont = e($site['heading_font'] !== '' ? $site['heading_font'] : $fallback);
        $dark = '#1f2328';
        $link = function (string $key, string $value) use ($color, $dark, $url): string {
            return match ($key) {
                'phone' => '<a href="tel:' . e((string) preg_replace('/[^+\d]/', '', $value)) . '" style="color:' . $dark . ';text-decoration:none;">' . e($value) . '</a>',
                'email' => '<a href="mailto:' . e($value) . '" style="color:' . $color . ';text-decoration:none;">' . e($value) . '</a>',
                'website' => $url !== '' ? '<a href="' . e($url) . '" style="color:' . $color . ';text-decoration:none;">' . e($value) . '</a>' : e($value),
                default => e($value),
            };
        };

        $text = [];
        $rows = [];
        foreach (self::lines($template) as $line) {
            $pattern = '/\{(' . implode('|', self::PLACEHOLDERS) . ')\}/';
            preg_match_all($pattern, $line, $found);
            $used = count($found[1]);
            $filled = count(array_filter($found[1], fn (string $key): bool => ($values[$key] ?? '') !== ''));
            $plain = trim((string) preg_replace_callback($pattern, fn (array $m): string => $values[$m[1]] ?? '', $line));
            if ($plain === '' || ($used > 0 && $filled === 0)) {
                continue; // a line made only of empty placeholders (no phone, no position) is left out
            }
            $html = (string) preg_replace_callback($pattern, fn (array $m): string => ($values[$m[1]] ?? '') !== '' ? $link($m[1], $values[$m[1]]) : '', e(trim($line)));
            $style = $rows === [] ? 'font-family:' . $headingFont . ';font-size:17px;line-height:1.3;font-weight:700;' : '';
            $rows[] = '<tr><td style="padding:0 0 4px 0;font-family:' . $font . ';font-size:14px;line-height:1.5;color:' . $dark . ';' . $style . '">' . $html . '</td></tr>';
            $text[] = $plain;
        }
        if ($rows === []) {
            return ['html' => '', 'text' => ''];
        }

        return [
            'html' => '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;max-width:600px;"><tr>'
                . '<td style="padding:0 0 0 14px;vertical-align:top;border-left:3px solid ' . $color . ';">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . implode('', $rows) . '</table>'
                . '</td></tr></table>',
            'text' => implode("\n", $text),
        ];
    }

    /** @return list<string> the template's lines: without control characters, at most MAX_LINES of at most MAX_LINE characters */
    private static function lines(string $template): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $template));
        $lines = array_map(fn (string $l): string => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $l)), 0, self::MAX_LINE), $lines);

        return array_slice(array_values(array_filter($lines, fn (string $l): bool => $l !== '')), 0, self::MAX_LINES);
    }
}
