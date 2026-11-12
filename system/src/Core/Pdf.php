<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * A small PDF writer of its own (3.40, design A7, owner decision A-9: no third-party PDF library) – A4 pages with text,
 * lines, filled rectangles and one kind of image, enough for a business document such as a quote (Front\QuotePdf).
 *
 *  - Coordinates are points from the TOP-left corner of the page (y grows downwards, a text's y is its baseline); the
 *    writer turns them into PDF's bottom-up space.
 *  - Text uses the standard fonts every PDF reader carries (Helvetica, Times, Courier – regular and bold), so nothing is
 *    embedded and a quote is a few kilobytes. One encoding per document: ASCII as itself, every other character the
 *    document uses gets a free byte (128–255, then 1–31) and its glyph name in /Differences, so Czech, Slovak, Polish,
 *    Hungarian, Romanian, Turkish, German, Spanish, French or Nordic text all draw (Core\PdfFonts lists what the fonts
 *    have). Each font carries /Widths and a /ToUnicode map, so copying text out of the PDF and searching in it work.
 *  - Limits, on purpose: no Greek, Cyrillic, Arabic, Hebrew, Chinese, Japanese, Korean or Vietnamese – that would need an
 *    embedded font (subsetting TrueType is a project of its own) and, for Arabic, text shaping. A character the fonts
 *    cannot draw becomes "?" and counts in $missing; supports() answers beforehand, so a caller offers no PDF at all
 *    instead of a broken one (Front\QuotePdf keeps the print page for such a quote). Right-to-left text is not handled.
 *  - Images: a JPEG up to JPEG_BYTES goes in as it is (grey or RGB); a larger one is scaled to IMAGE_SIDE and saved
 *    again as a JPEG with GD (3.40 review L5: a 5 MB logo would make every quote's PDF too large for an e-mail), or left
 *    out without GD; PNG, GIF and WebP only with PHP's GD, redrawn on white (no alpha channel in the file) and stored
 *    compressed. SVG is not supported. Inputs are bounded (IMAGE_BYTES, IMAGE_PIXELS).
 *  - Content streams are compressed with zlib when PHP has it; the file has a classic cross-reference table.
 */
final class Pdf
{
    /** A4 in points (1/72 inch). */
    public const float WIDTH = 595.28;
    public const float HEIGHT = 841.89;

    /** The standard fonts per family: [regular, bold]. */
    public const array FAMILIES = ['sans' => ['Helvetica', 'Helvetica-Bold'], 'serif' => ['Times-Roman', 'Times-Bold'], 'mono' => ['Courier', 'Courier-Bold']];

    /** The largest image file and the most pixels (width × height) the writer takes. */
    public const int IMAGE_BYTES = 5_000_000;
    public const int IMAGE_PIXELS = 4_000_000;

    /** The largest JPEG that goes into the file as it is (3.40 review L5); a larger one is scaled down and saved again. */
    public const int JPEG_BYTES = 300_000;

    /** A redrawn image (not a JPEG) is scaled down to at most this many pixels on its longer side – a logo on paper needs no more. */
    public const int IMAGE_SIDE = 800;

    /** Characters that could not be drawn (written as "?"). */
    public int $missing = 0;

    /** @var list<string> the content stream of each page */
    private array $pages = [];

    private int $current = -1;

    /** @var array<int, int> code point => the byte it is drawn with (beyond ASCII) */
    private array $codes = [];

    /** @var list<int> the free bytes, in the order they are handed out */
    private array $free;

    /** @var array<string, string> base font => resource name (/F1 …) */
    private array $fonts = [];

    /** @var list<array{w: int, h: int, space: string, filter: string, data: string}> */
    private array $images = [];

    /** @var list<array{0: int, 1: string}> images used per page: [page, resource name] */
    private array $imageUse = [];

    public function __construct(private readonly string $title = '', private readonly string $author = '', private readonly string $language = '')
    {
        $this->free = [...range(128, 255), ...range(1, 31)];
    }

    /* ---------- pages ---------- */

    /** Starts a new page and makes it the current one; returns its index (0 = the first). */
    public function addPage(): int
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;

        return $this->current;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Draws on an earlier page again (page numbers once the last page is known). */
    public function onPage(int $index): void
    {
        if (!isset($this->pages[$index])) {
            throw new \OutOfRangeException('No such page.');
        }
        $this->current = $index;
    }

    /* ---------- text ---------- */

    /** The base font of a family ('sans', 'serif', 'mono'; anything else is 'sans'). */
    public static function font(string $family, bool $bold = false): string
    {
        return (self::FAMILIES[$family] ?? self::FAMILIES['sans'])[$bold ? 1 : 0];
    }

    /** Can every character of the text be drawn with the standard fonts? */
    public static function supports(string $text): bool
    {
        foreach (self::characters($text) as $cp) {
            if (!self::drawable($cp)) {
                return false;
            }
        }

        return true;
    }

    /** The width of a text in points. */
    public static function width(string $text, string $font, float $size): float
    {
        $sum = 0;
        foreach (self::characters($text) as $cp) {
            $sum += self::glyphWidth($font, $cp);
        }

        return $sum * $size / 1000;
    }

    /**
     * The text broken into lines no wider than $max: at spaces (never at a no-break space), at line breaks, and inside a
     * word only when the word alone is wider than the line.
     *
     * @return list<string>
     */
    public static function wrap(string $text, string $font, float $size, float $max): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', self::clean($text)) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/ +/u', trim($paragraph)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (self::width($candidate, $font, $size) <= $max) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
                $line = '';
                while (self::width($word, $font, $size) > $max && mb_strlen($word) > 1) {
                    $cut = 1;
                    while ($cut < mb_strlen($word) && self::width(mb_substr($word, 0, $cut + 1), $font, $size) <= $max) {
                        $cut++;
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Writes one line of text with its baseline at $y; $align 'right' ends it at $x, 'center' centres it on $x.
     *
     * @param array{0: float, 1: float, 2: float} $color RGB 0–1
     */
    public function text(float $x, float $y, string $text, string $font, float $size, array $color = [0.0, 0.0, 0.0], string $align = 'left'): void
    {
        $text = self::clean($text);
        if ($text === '') {
            return;
        }
        if ($align !== 'left') {
            $x -= self::width($text, $font, $size) / ($align === 'center' ? 2 : 1);
        }
        $name = $this->fonts[$font] ??= 'F' . (count($this->fonts) + 1);
        $this->write(sprintf("BT /%s %s Tf %s rg %s %s Td <%s> Tj ET\n", $name, self::n($size), self::rgb($color), self::n($x), self::n(self::HEIGHT - $y), bin2hex($this->encode($text))));
    }

    /* ---------- shapes and images ---------- */

    /** @param array{0: float, 1: float, 2: float} $color */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $color = [0.0, 0.0, 0.0]): void
    {
        $this->write(sprintf("q %s RG %s w %s %s m %s %s l S Q\n", self::rgb($color), self::n($width), self::n($x1), self::n(self::HEIGHT - $y1), self::n($x2), self::n(self::HEIGHT - $y2)));
    }

    /** A filled rectangle; ($x, $y) is its top-left corner. @param array{0: float, 1: float, 2: float} $fill */
    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->write(sprintf("q %s rg %s %s %s %s re f Q\n", self::rgb($fill), self::n($x), self::n(self::HEIGHT - $y - $h), self::n($w), self::n($h)));
    }

    /**
     * Places an image with its top-left corner at ($x, $y), scaled to fit $maxW × $maxH with its proportions kept.
     * Returns the drawn size, or null when the image cannot be used (unknown or broken format, too large, SVG, no GD).
     *
     * @return array{0: float, 1: float}|null
     */
    public function image(string $bytes, float $x, float $y, float $maxW, float $maxH): ?array
    {
        $image = self::prepareImage($bytes);
        if ($image === null || $maxW <= 0 || $maxH <= 0) {
            return null;
        }
        $this->images[] = $image;
        $name = 'Im' . count($this->images);
        $this->imageUse[] = [$this->current, $name];
        $scale = min($maxW / $image['w'], $maxH / $image['h']);
        $w = $image['w'] * $scale;
        $h = $image['h'] * $scale;
        $this->write(sprintf("q %s 0 0 %s %s %s cm /%s Do Q\n", self::n($w), self::n($h), self::n($x), self::n(self::HEIGHT - $y - $h), $name));

        return [$w, $h];
    }

    /** "#2b5be3" → [0.17, 0.36, 0.89]; anything else is black. @return array{0: float, 1: float, 2: float} */
    public static function color(string $hex): array
    {
        if (preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/iD', trim($hex), $m) !== 1) {
            return [0.0, 0.0, 0.0];
        }

        return [hexdec($m[1]) / 255, hexdec($m[2]) / 255, hexdec($m[3]) / 255];
    }

    /* ---------- the file ---------- */

    /** The whole document as a PDF file. */
    public function output(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $fontNames = array_keys($this->fonts);
        // object numbers: 1 catalog, 2 pages, 3 info, 4 encoding, 5 ToUnicode, then fonts, images, and per page its page and content
        $fontObj = [];
        $next = 6;
        foreach ($fontNames as $font) {
            $fontObj[$font] = $next++;
        }
        $imageObj = [];
        foreach (array_keys($this->images) as $i) {
            $imageObj[$i] = $next++;
        }
        $pageObj = [];
        foreach (array_keys($this->pages) as $i) {
            $pageObj[$i] = $next;
            $next += 2;
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R' . ($this->language !== '' ? ' /Lang ' . self::string($this->language) : '') . ' >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn (int $n): string => $n . ' 0 R', $pageObj)) . '] /Count ' . count($pageObj) . ' >>';
        $objects[3] = '<< /Producer (Kaleta)' . ($this->title !== '' ? ' /Title ' . self::string($this->title) : '') . ($this->author !== '' ? ' /Author ' . self::string($this->author) : '')
            . ' /CreationDate (D:' . date('YmdHis', Clock::time()) . ') >>';
        $differences = '';
        $sorted = array_flip($this->codes);
        ksort($sorted);
        foreach ($sorted as $code => $cp) {
            $differences .= ' ' . $code . ' /' . PdfFonts::GLYPHS[$cp];
        }
        $objects[4] = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding' . ($differences !== '' ? ' /Differences [' . $differences . ' ]' : '') . ' >>';
        $objects[5] = $this->stream($this->toUnicode());
        foreach ($fontNames as $font) {
            $widths = [];
            for ($code = 1; $code <= 255; $code++) {
                $cp = $code >= 32 && $code <= 126 ? $code : ($sorted[$code] ?? null);
                $widths[] = $cp === null ? 0 : self::glyphWidth($font, $cp);
            }
            $objects[$fontObj[$font]] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $font . ' /Encoding 4 0 R /FirstChar 1 /LastChar 255 /Widths [' . implode(' ', $widths) . '] /ToUnicode 5 0 R >>';
        }
        foreach ($this->images as $i => $image) {
            $objects[$imageObj[$i]] = ['<< /Type /XObject /Subtype /Image /Width ' . $image['w'] . ' /Height ' . $image['h'] . ' /ColorSpace /' . $image['space']
                . ' /BitsPerComponent 8 /Filter /' . $image['filter'] . ' /Length ' . strlen($image['data']) . ' >>', $image['data']];
        }
        $fontResources = implode(' ', array_map(fn (string $font): string => '/' . $this->fonts[$font] . ' ' . $fontObj[$font] . ' 0 R', $fontNames));
        foreach ($this->pages as $i => $content) {
            $xobjects = '';
            foreach ($this->imageUse as $n => [$page, $name]) {
                if ($page === $i) {
                    $xobjects .= ' /' . $name . ' ' . $imageObj[$n] . ' 0 R';
                }
            }
            $objects[$pageObj[$i]] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n(self::WIDTH) . ' ' . self::n(self::HEIGHT) . ']'
                . ' /Resources << /ProcSet [/PDF /Text /ImageC]' . ($fontResources !== '' ? ' /Font << ' . $fontResources . ' >>' : '') . ($xobjects !== '' ? ' /XObject <<' . $xobjects . ' >>' : '') . ' >>'
                . ' /Contents ' . ($pageObj[$i] + 1) . ' 0 R >>';
            $objects[$pageObj[$i] + 1] = $this->stream($content);
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . (is_array($body) ? $body[0] . "\nstream\n" . $body[1] . "\nendstream" : $body) . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($next) . "\n0000000000 65535 f \n";
        for ($number = 1; $number < $next; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $id = md5($pdf);

        return $pdf . "trailer\n<< /Size " . $next . ' /Root 1 0 R /Info 3 0 R /ID [<' . $id . '> <' . $id . "> ] >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /* ---------- inside ---------- */

    private function write(string $operators): void
    {
        if ($this->current < 0) {
            $this->addPage();
        }
        $this->pages[$this->current] .= $operators;
    }

    /** A stream object: compressed when zlib is there. @return array{0: string, 1: string} */
    private function stream(string $data): array
    {
        if (function_exists('gzcompress')) {
            $packed = gzcompress($data, 6);
            if (is_string($packed)) {
                return ['<< /Filter /FlateDecode /Length ' . strlen($packed) . ' >>', $packed];
            }
        }

        return ['<< /Length ' . strlen($data) . ' >>', $data];
    }

    /** The ToUnicode map of the document's encoding (one map for every font). */
    private function toUnicode(): string
    {
        $pairs = [];
        for ($code = 32; $code <= 126; $code++) {
            $pairs[] = sprintf('<%02X> <%04X>', $code, $code);
        }
        foreach ($this->codes as $cp => $code) {
            $pairs[] = sprintf('<%02X> <%04X>', $code, $cp === 0xA0 ? 0x20 : $cp);
        }
        $body = '';
        foreach (array_chunk($pairs, 100) as $chunk) {
            $body .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Kaleta-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<00> <FF>\nendcodespacerange\n" . $body . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    /** The bytes of a text in the document's encoding; a character the fonts lack is "?". */
    private function encode(string $text): string
    {
        $out = '';
        foreach (self::characters($text) as $cp) {
            if ($cp >= 32 && $cp <= 126) {
                $out .= chr($cp);
            } elseif ($cp === 0xA0) {
                $out .= ' ';
            } elseif (isset(PdfFonts::GLYPHS[$cp]) && (isset($this->codes[$cp]) || $this->free !== [])) {
                $out .= chr($this->codes[$cp] ??= (int) array_shift($this->free));
            } else {
                $this->missing++;
                $out .= '?';
            }
        }

        return $out;
    }

    private static function drawable(int $cp): bool
    {
        return ($cp >= 32 && $cp <= 126) || isset(PdfFonts::GLYPHS[$cp]);
    }

    private static function glyphWidth(string $font, int $cp): int
    {
        if (str_starts_with($font, 'Courier')) {
            return 600;
        }
        $widths = PdfFonts::WIDTHS[$font] ?? PdfFonts::WIDTHS['Helvetica'];

        return $widths[$cp] ?? $widths[63];
    }

    /** Text as the writer draws it: composed (NFC), tabs and odd spaces as spaces, no control characters. */
    private static function clean(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $text = str_replace(["\t", "\u{2009}", "\u{202F}", "\u{2007}", "\u{200A}", "\u{2002}", "\u{2003}"], ["  ", ' ', "\u{00A0}", "\u{00A0}", ' ', ' ', ' '], $text);

        return (string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);
    }

    /** @return list<int> the code points of a text, cleaned */
    private static function characters(string $text): array
    {
        $text = self::clean($text);
        if ($text === '') {
            return [];
        }

        return array_map('mb_ord', mb_str_split(str_replace(["\r", "\n"], '', $text)));
    }

    /** A text string of the document's dictionaries (title, language): UTF-16 with the byte order mark, as hex. */
    private static function string(string $text): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding(self::clean($text), 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /** @param array{0: float, 1: float, 2: float} $color */
    private static function rgb(array $color): string
    {
        return self::n(max(0, min(1, $color[0]))) . ' ' . self::n(max(0, min(1, $color[1]))) . ' ' . self::n(max(0, min(1, $color[2])));
    }

    /** A number as PDF writes it: up to three decimals, never an exponent or a comma. */
    private static function n(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    /**
     * An image ready for the file, or null: a JPEG as it is; anything GD reads redrawn on white as RGB, compressed.
     *
     * @return array{w: int, h: int, space: string, filter: string, data: string}|null
     */
    private static function prepareImage(string $bytes): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::IMAGE_BYTES || str_contains(substr($bytes, 0, 256), '<svg')) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::IMAGE_PIXELS) {
            return null;
        }
        $channels = (int) ($info['channels'] ?? 3);
        if ($info[2] === IMAGETYPE_JPEG && in_array($channels, [1, 3], true) && strlen($bytes) <= self::JPEG_BYTES) {
            return ['w' => $info[0], 'h' => $info[1], 'space' => $channels === 1 ? 'DeviceGray' : 'DeviceRGB', 'filter' => 'DCTDecode', 'data' => $bytes];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('gzcompress')) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        if (imagesx($source) > self::IMAGE_SIDE || imagesy($source) > self::IMAGE_SIDE) {
            $scale = self::IMAGE_SIDE / max(imagesx($source), imagesy($source));
            $scaled = imagescale($source, max(1, (int) round(imagesx($source) * $scale)), max(1, (int) round(imagesy($source) * $scale)));
            if ($scaled === false) {
                return null;
            }
            $source = $scaled;
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $canvas = imagecreatetruecolor($w, $h);
        if ($canvas === false) {
            return null;
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, $w, $h);
        if ($info[2] === IMAGETYPE_JPEG) { // 3.40 review L5: a large JPEG is saved again, smaller – or left out
            ob_start();
            imagejpeg($canvas, null, 85);
            $jpeg = (string) ob_get_clean();

            return $jpeg !== '' && strlen($jpeg) <= self::JPEG_BYTES ? ['w' => $w, 'h' => $h, 'space' => 'DeviceRGB', 'filter' => 'DCTDecode', 'data' => $jpeg] : null;
        }
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($canvas, $x, $y);
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }
        $packed = gzcompress($raw, 6);

        return is_string($packed) ? ['w' => $w, 'h' => $h, 'space' => 'DeviceRGB', 'filter' => 'FlateDecode', 'data' => $packed] : null;
    }
}
