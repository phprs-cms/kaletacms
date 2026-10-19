<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Masking personal data in short texts that leave their place (3.16, N316-2): request titles a site sends to its fleet
 * console (Fleet\Heartbeat::cleanTitle, masked again by the console), every string of the archived monthly report
 * (MonthlyReport::scrub) and the e-mail written from it. One masker, so the places cannot drift apart.
 *
 * It looks at a normalised copy of the text – HTML entities decoded (twice: "&amp;#64;"), Unicode NFKC (the full-width
 * "＠" and the small "﹫" become "@", full-width digits plain ones), invisible characters removed – and masks:
 *  - e-mail addresses, also with spaces around the "@" ("jan @ firma.cz", "jan@ firma.cz"), and any other word with "@";
 *  - addresses written out: "jan [at] firma [dot] cz", "jan(at)firma.cz", "jan at firma dot cz", "jan at firma.cz";
 *  - phone numbers: 9 or more digits in groups split by spaces, dots, dashes or slashes, with an optional "+420" or
 *    "(+420)" prefix ("+420 777 123 456", "777-123-456", "777123456").
 * Dates, times, prices, versions and years stay. When nothing is masked the text comes back as it was – only control
 * characters and bidirectional overrides are removed – so the normalisation never changes a harmless title ("m²", "&").
 */
final class Redact
{
    public const string EMAIL = '[e-mail]';
    public const string PHONE = '[phone]';

    /** The text with e-mail addresses and phone numbers replaced by $email and $phone. */
    public static function text(string $text, string $email = self::EMAIL, string $phone = self::PHONE): string
    {
        $text = self::withoutControls($text);
        $plain = self::normalise($text);
        $masked = self::phones(self::emails($plain, $email), $phone);

        return $masked === $plain ? $text : $masked;
    }

    /**
     * Valid UTF-8 without control characters (tab and line breaks stay) and without the bidirectional embeddings, overrides
     * and isolates (U+202A–202E, U+2066–2069) that make a text read differently from what it says.
     */
    public static function withoutControls(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
    }

    /** The copy the masking looks at: entities decoded, NFKC, no invisible characters, spaces as plain spaces. */
    public static function normalise(string $text): string
    {
        for ($round = 0; $round < 2 && str_contains($text, '&'); $round++) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        } else {
            // without the intl extension: the full-width forms (U+FF01–FF5E) and the small and leader forms of "@" and "."
            $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', static fn (array $m): string => chr((int) mb_ord($m[0], 'UTF-8') - 0xFEE0), $text) ?? $text;
            $text = strtr($text, ['﹫' => '@', '﹒' => '.', '․' => '.', '｡' => '.']);
        }
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', self::withoutControls($text)) ?? $text;

        return preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
    }

    /** E-mail addresses – plain, spaced around "@" or written out with at/dot – and any other word with "@". */
    private static function emails(string $text, string $mask): string
    {
        $word = '[^\s@<>()\[\]{},;:"\'`]';
        // "at" and "dot" in English and Czech (zavináč, tečka) – in brackets anywhere, as plain words between spaces
        $at = '(?:\s*[\[({<]\s*(?:at|zavin[a\x{e1}][c\x{10d}])\s*[\])}>]\s*|\s+at\s+)';
        $dot = '(?:\s*[\[({<]\s*(?:dot|te[c\x{10d}]ka)\s*[\])}>]\s*|\s+dot\s+|\.)';
        $text = preg_replace('/' . $word . '+\s*@\s*(?:' . $word . '+\.)+[a-z]{2,24}\b/iu', $mask, $text) ?? $text;
        $text = preg_replace('/[\w.+-]+' . $at . '[\w-]+(?:' . $dot . '[\w-]+)*' . $dot . '[a-z]{2,24}\b/iu', $mask, $text) ?? $text;

        return preg_replace('/\S*@\S*/u', $mask, $text) ?? $text;
    }

    /**
     * Phone numbers: a run of digits and separators with 9 or more digits (card and account numbers go too). A date ("2026-10-19", "19. 10. 2026") inside the run,
     * a time after it ("10:30") or a currency after it (a price) keeps it as it is.
     */
    private static function phones(string $text, string $mask): string
    {
        return preg_replace_callback('/(?<![\w.,\/+-])(?:\(\s*\+?\d{1,4}\s*\)\s?|\+\s?)?\d[\d \t.\/-]{6,}\d(?!\w|[.,:\/-]\d)/u', static function (array $m) use ($text, $mask): string {
            [$run, $offset] = $m[0];
            $digits = strlen((string) preg_replace('/\D/', '', $run));
            $after = substr($text, $offset + strlen($run), 12);
            $date = preg_match('/\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\. ?\d{1,2}\. ?\d{4}|\d{1,2}\/\d{1,2}\/\d{4}/', $run) === 1;
            $price = preg_match('/^\s*(Kč|CZK|EUR|€|\$|USD|GBP|£|zł|PLN|Ft|HUF|,-|\.-)/u', $after) === 1;

            return $digits < 9 || $date || $price ? $run : $mask;
        }, $text, -1, flags: PREG_OFFSET_CAPTURE) ?? $text;
    }
}
