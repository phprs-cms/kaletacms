<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What a file is when the PHP extension fileinfo is missing (3.46.2): an allowlist keyed by the extension the file claims,
 * stricter than libmagic, never an imitation of it. Files::mimeOf() asks finfo first and comes here only without it.
 *
 * sniff() answers the content type for the extension only when the bytes confirm it – a binary type must start with its
 * signature (a ZIP-based document must also carry its own parts), a text type must be valid UTF-8 without NUL bytes and
 * without markup a browser would run – and '' for everything else, which the callers refuse exactly as when finfo names
 * the wrong type. A program (MZ, ELF, Mach-O) or a script with #! is refused whatever the extension says.
 */
final class FileSignature
{
    /** extension => the content type answered when the signature fits. */
    public const array TYPES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation', 'epub' => 'application/epub+zip', 'zip' => 'application/zip',
        'rtf' => 'text/rtf', 'txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/csv', 'gpx' => 'application/gpx+xml', 'ics' => 'text/calendar',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/x-wav', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    /** OOXML: extension => [the main part's content type, a part every such file has]. */
    private const array OOXML = [
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', 'word/'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', 'xl/'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', 'ppt/'],
    ];

    /** How much of the start is read for the signature and the text check. */
    private const int HEAD = 65536;

    /** A ZIP-based document is read whole without ZipArchive only up to this size. */
    private const int ZIP_SCAN = 64 * 1024 * 1024;

    /**
     * Markup in a text file that a browser would show as a page or run – what libmagic calls text/html, and more (3.46.2
     * review L3): any doctype, images, styles, frames, SVG, a link to javascript: and an on…= handler inside a tag. The two
     * tag patterns look at most 2000 characters ahead (3.47 N171): unbounded, a file of many "<a " took seconds per check
     * without the JIT and could end at the backtrack limit, which no longer counts as plain text either. They do not stop
     * at a "<" (a browser takes it inside an attribute value), they take "/" as the separator a browser takes (<p/onclick=),
     * and a tag still open after 2000 characters counts as markup, so padding cannot push a handler out of reach (3.47 review).
     */
    private const string MARKUP = '/<\?php|<\?=|<script|<html|<!doctype|<svg|<iframe|<frame|<body|<object|<embed|<img[\s\/>]|<style|<link[\s\/>]|<meta[\s\/>]|<a[\s\/][^>]{0,2000}javascript:|<[a-z][^>]{0,2000}[\s\/]on[a-z]+\s*=|<[a-z][^>]{2000}/i';

    /** The content type of the file for the extension it claims, '' when the bytes do not confirm it. */
    public static function sniff(string $path, string $extension): string
    {
        $extension = strtolower($extension);
        $type = self::TYPES[$extension] ?? null;
        if ($type === null || !Files::isFile($path) || !is_readable($path)) {
            return '';
        }
        $head = (string) @file_get_contents($path, false, null, 0, self::HEAD);
        if ($head === '' || self::isProgram($head)) {
            return '';
        }
        $ok = match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'docx', 'xlsx', 'pptx' => str_starts_with($head, "PK\x03\x04") && self::ooxml($path, $extension),
            'odt', 'ods', 'odp', 'epub' => str_starts_with($head, "PK\x03\x04") && self::odf($path, $head, $type),
            'zip' => str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06"),
            'rtf' => str_starts_with($head, '{\\rtf'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'gif' => str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'wav' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE',
            'mp3' => str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
            'm4a', 'mp4' => substr($head, 4, 4) === 'ftyp',
            'ogg' => str_starts_with($head, 'OggS'),
            'webm' => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            'woff' => str_starts_with($head, 'wOFF'),
            'woff2' => str_starts_with($head, 'wOF2'),
            'svg' => self::isText($head, (int) filesize($path)) && preg_match('/<svg[\s>]/i', substr($head, 0, 4096)) === 1,
            'gpx' => self::isPlainText($head, (int) filesize($path)) && preg_match('/<gpx[\s>]/i', $head) === 1,
            'ics' => self::isPlainText($head, (int) filesize($path)) && str_starts_with(ltrim(self::withoutBom($head)), 'BEGIN:VCALENDAR'),
            default => self::isPlainText($head, (int) filesize($path)), // txt, md, csv
        };

        return $ok ? $type : '';
    }

    /** A program or a script, whatever the extension says: MZ (Windows), ELF, Mach-O, #! (pure). */
    public static function isProgram(string $head): bool
    {
        return str_starts_with($head, 'MZ') || str_starts_with($head, "\x7FELF") || str_starts_with($head, '#!')
            || in_array(substr($head, 0, 4), ["\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE", "\xCA\xFE\xBA\xBE"], true);
    }

    /** Valid UTF-8 without NUL bytes; a character cut at the end of the head read does not count against it (pure). */
    public static function isText(string $head, int $size): bool
    {
        if (str_contains($head, "\0")) {
            return false;
        }
        if ($size > strlen($head)) {
            for ($cut = 0; $cut < 4 && !mb_check_encoding(substr($head, 0, strlen($head) - $cut), 'UTF-8'); $cut++) {
                // a multi-byte character may be split where the head read ends
            }

            return $cut < 4;
        }

        return mb_check_encoding($head, 'UTF-8');
    }

    /** Text without markup a browser would run (pure). */
    public static function isPlainText(string $head, int $size): bool
    {
        return self::isText($head, $size) && preg_match(self::MARKUP, $head) === 0; // false (an error) is no proof of plain text
    }

    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    /**
     * An Office Open XML document of this kind: its [Content_Types].xml names the main part of the kind and nothing
     * macro-enabled, and it has no VBA project. With ZipArchive the parts are read; without it the names in the archive
     * (stored uncompressed in every ZIP) are looked for.
     */
    private static function ooxml(string $path, string $extension): bool
    {
        [$main, $folder] = self::OOXML[$extension];
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            $stat = $zip->statName('[Content_Types].xml');
            $types = is_array($stat) && $stat['size'] <= 1048576 ? $zip->getFromName('[Content_Types].xml') : false;
            $vba = false;
            for ($i = 0; $i < $zip->numFiles && !$vba; $i++) {
                $vba = preg_match('#(^|/)vbaProject\.bin$#i', (string) $zip->getNameIndex($i)) === 1;
            }
            $zip->close();

            return is_string($types) && str_contains($types, $main) && stripos($types, 'macroEnabled') === false && !$vba;
        }
        if ((int) filesize($path) > self::ZIP_SCAN) {
            return false;
        }
        $raw = (string) file_get_contents($path);

        return str_contains($raw, '[Content_Types].xml') && str_contains($raw, $folder) && stripos($raw, 'vbaProject.bin') === false;
    }

    /** An OpenDocument or EPUB file of this kind: its "mimetype" entry says so (with ZipArchive, else the first entry). */
    private static function odf(string $path, string $head, string $type): bool
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            $stat = $zip->statName('mimetype');
            $mimetype = is_array($stat) && $stat['size'] <= 200 ? $zip->getFromName('mimetype') : false;
            $zip->close();

            return is_string($mimetype) && trim($mimetype) === $type;
        }

        // the format requires "mimetype" as the first entry, stored uncompressed: its name at offset 30, the value after it
        return substr($head, 30, 8) === 'mimetype' && substr($head, 38, strlen($type)) === $type;
    }
}
