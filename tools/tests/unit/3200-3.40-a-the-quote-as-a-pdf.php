<?php

declare(strict_types=1);

use Kaleta\Core\Mail;
use Kaleta\Core\Pdf;
use Kaleta\Front\QuotePdf;

/* ---------- 3.40 A: Core\Pdf (an own small PDF writer), the quote as a PDF (Front\QuotePdf) and e-mail attachments ---------- */

// a small reader for the checks: the objects, the cross-reference table, the pages and their text (through /ToUnicode)
$u340Read = static function (string $pdf): array {
    $objects = [];
    preg_match_all('/(?<=\n)(\d+) 0 obj\n(.*?)\nendobj\n/s', $pdf, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $o) {
        $body = $o[2][0];
        $stream = null;
        if (preg_match('/^(<<.*?>>)\nstream\n(.*)\nendstream$/s', $body, $s) === 1) {
            $body = $s[1];
            $stream = str_contains($body, '/FlateDecode') && !str_contains($body, '/Subtype /Image') ? (string) gzuncompress($s[2]) : $s[2];
        }
        $objects[(int) $o[1][0]] = ['dict' => $body, 'stream' => $stream, 'offset' => $o[0][1]];
    }
    $xrefOk = false;
    $size = 0;
    if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $x) === 1 && substr($pdf, (int) $x[1], 4) === 'xref'
        && preg_match('/^xref\n0 (\d+)\n((?:\d{10} \d{5} [nf] \n)+)trailer\n<< \/Size (\d+) \/Root 1 0 R/', substr($pdf, (int) $x[1]), $t) === 1) {
        $size = (int) $t[3];
        $xrefOk = (int) $t[1] === $size && count($objects) === $size - 1;
        foreach (array_slice(explode("\n", trim($t[2])), 1) as $i => $entry) {
            $xrefOk = $xrefOk && isset($objects[$i + 1]) && (int) substr($entry, 0, 10) === $objects[$i + 1]['offset'];
        }
    }
    $map = [];
    foreach ($objects as $o) {
        if ($o['stream'] !== null && str_contains($o['stream'], 'beginbfchar')) {
            preg_match_all('/<([0-9A-F]{2})> <([0-9A-F]{4})>/', $o['stream'], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) {
                $map[hexdec($p[1])] = mb_chr((int) hexdec($p[2]));
            }
        }
    }
    $pages = [];
    preg_match('/\/Kids \[([^\]]*)\]/', $objects[2]['dict'] ?? '', $kids);
    foreach (preg_match_all('/(\d+) 0 R/', $kids[1] ?? '', $k) ? $k[1] : [] as $pageObj) {
        preg_match('/\/Contents (\d+) 0 R/', $objects[(int) $pageObj]['dict'] ?? '', $c);
        preg_match_all('/<([0-9a-f]*)> Tj/', (string) ($objects[(int) ($c[1] ?? 0)]['stream'] ?? ''), $texts);
        $pages[] = implode("\n", array_map(fn (string $hex): string => implode('', array_map(fn (string $b): string => $map[ord($b)] ?? '?', str_split((string) hex2bin($hex)))), $texts[1]));
    }

    return ['xref' => $xrefOk, 'size' => $size, 'pages' => $pages, 'count' => preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/', $objects[2]['dict'] ?? '', $n) === 1 ? (int) $n[1] : -1,
        'objects' => $objects, 'head' => substr($pdf, 0, 8)];
};

// the writer: a valid file (header, every object where the cross-reference table says, the trailer), two pages, Czech, German and Spanish text
$u340Pdf = new Pdf('Nabídka', 'Firma', 'cs');
$u340Pdf->addPage();
$u340Pdf->text(50, 80, 'Příliš žluťoučký kůň: ěščřžýáíéůú ĚŠČŘŽÝÁÍÉŮÚ', Pdf::font('sans', true), 14);
$u340Pdf->text(545, 100, 'Straße, Größe; niño, ñandú, ¿qué? – „uvozovky“ 1 250,50 €', Pdf::font('serif'), 10, Pdf::color('#2b5be3'), 'right');
$u340Pdf->line(50, 110, 545, 110, 1.0, [0.5, 0.5, 0.5]);
$u340Pdf->rect(50, 120, 100, 20, [0.9, 0.9, 0.95]);
$u340Pdf->addPage();
$u340Pdf->text(50, 80, 'Strana 2 – Łódź, Őrség, Şişli, București (ș ț)', Pdf::font('mono'), 10);
$u340File = $u340Pdf->output();
$u340 = $u340Read($u340File);
check('3.40 A: Core\Pdf writes a valid PDF – the header, every object at the offset of the cross-reference table, the trailer, two pages', [
    $u340['head'], $u340['xref'], $u340['count'], count($u340['pages']), $u340Pdf->missing], ['%PDF-1.4', true, 2, 2, 0]);
check('3.40 A: the text of the pages reads back through /ToUnicode – Czech (ěščřžýáíéůú), German (ß), Spanish (ñ ¿), Polish, Hungarian, Turkish, Romanian', [
    $u340['pages'][0], $u340['pages'][1]], ["Příliš žluťoučký kůň: ěščřžýáíéůú ĚŠČŘŽÝÁÍÉŮÚ\nStraße, Größe; niño, ñandú, ¿qué? – „uvozovky“ 1 250,50 €", 'Strana 2 – Łódź, Őrség, Şişli, București (ș ț)']);
check('3.40 A: the standard fonts are not embedded – each font names its encoding (WinAnsi + /Differences), its widths and its /ToUnicode map', [
    count(array_filter($u340['objects'], fn (array $o): bool => preg_match('#^<< /Type /Font /Subtype /Type1 /BaseFont /(Helvetica-Bold|Times-Roman|Courier) /Encoding 4 0 R /FirstChar 1 /LastChar 255 /Widths \[[0-9 ]+\] /ToUnicode 5 0 R >>$#', $o['dict']) === 1)),
    str_contains($u340['objects'][4]['dict'], '/BaseEncoding /WinAnsiEncoding /Differences ['), str_contains($u340['objects'][4]['dict'], '/ecaron'), str_contains($u340File, 'FontFile')],
    [3, true, true, false]);

// what the standard fonts cannot draw is refused beforehand and counted when drawn anyway
$u340Missing = new Pdf();
$u340Missing->text(50, 50, 'Привет Ελλάδα 你好 Việt', 'Helvetica', 10);
check('3.40 A: Greek, Cyrillic, Chinese and Vietnamese tones are not drawable – supports() says so before, $missing counts them after (as "?")', [
    Pdf::supports('ěščř ßñ ąęł őű ğış șț – „“ … €'), Pdf::supports('Привет'), Pdf::supports('Ελλάδα'), Pdf::supports('你好'), Pdf::supports('Việt'), $u340Missing->missing], [true, false, false, false, false, 15]);

// widths and wrapping: Helvetica's own widths, a line never wider than asked, a no-break space never broken, a long word cut
$u340Wrap = Pdf::wrap("Laserová řezačka s velmi dlouhým názvem a montáží\n\nDruhý odstavec 12\u{00A0}500,00\u{00A0}Kč", 'Helvetica', 10, 120);
check('3.40 A: widths are the standard metrics (Helvetica "a" 556, bold the same, Times 444, Courier 600) and wrap() keeps every line within the width', [
    Pdf::width('a', 'Helvetica', 1000), Pdf::width('a', 'Helvetica-Bold', 1000), Pdf::width('a', 'Times-Roman', 1000), Pdf::width('ž', 'Courier', 1000),
    array_filter($u340Wrap, fn (string $l): bool => Pdf::width($l, 'Helvetica', 10) > 120), in_array("12\u{00A0}500,00\u{00A0}Kč", $u340Wrap, true) || in_array("odstavec 12\u{00A0}500,00\u{00A0}Kč", $u340Wrap, true),
    in_array('', $u340Wrap, true), count(Pdf::wrap(str_repeat('W', 60), 'Helvetica', 10, 100)) > 1], [556.0, 556.0, 444.0, 600.0, [], true, true, true]);

// images: a JPEG goes in as it is; PNG (with GD) is redrawn on white; SVG and broken data are refused
$u340Img = new Pdf();
$u340Img->addPage();
$u340Results = [$u340Img->image('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 0, 0, 10, 10), $u340Img->image('not an image', 0, 0, 10, 10)];
if (function_exists('imagecreatetruecolor')) {
    $u340Gd = imagecreatetruecolor(40, 20);
    imagefill($u340Gd, 0, 0, (int) imagecolorallocate($u340Gd, 200, 30, 30));
    ob_start();
    imagejpeg($u340Gd);
    $u340Jpeg = (string) ob_get_clean();
    ob_start();
    imagepng($u340Gd);
    $u340Png = (string) ob_get_clean();
    $u340Results[] = $u340Img->image($u340Jpeg, 50, 50, 80, 80);
    $u340Results[] = $u340Img->image($u340Png, 50, 150, 30, 30);
    $u340ImgRead = $u340Read($u340Img->output());
    $u340Kinds = array_values(array_map(fn (array $o): string => (string) preg_replace('/^.*\/Filter \/(\w+).*$/s', '$1', $o['dict']), array_filter($u340ImgRead['objects'], fn (array $o): bool => str_contains($o['dict'], '/Subtype /Image'))));
    check('3.40 A: an image keeps its proportions in the box; a JPEG is embedded as it is (DCTDecode), a PNG redrawn (FlateDecode); SVG and broken data are refused',
        [$u340Results, $u340Kinds, $u340ImgRead['xref']], [[null, null, [80.0, 40.0], [30.0, 15.0]], ['DCTDecode', 'FlateDecode'], true]);
}

// the quote as a PDF: the same content as the page, in the quote's language, the header of the lines on every page, page numbers
$u340Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($u340Settings, ['site_name' => 'Firma 340', 'site_language' => 'cs', 'logo' => '', 'german_register' => '', 'design_system' => '']);
$u340App = new Kaleta\Core\App([]);
(new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($u340App, $u340Settings);
$u340Lines = [];
for ($u340I = 1; $u340I <= 40; $u340I++) {
    $u340Lines[] = ['code' => 'LS-' . $u340I, 'name' => 'Laserová řezačka ' . $u340I, 'description' => $u340I % 2 === 1 ? 'Montáž a zaškolení obsluhy.' : '', 'quantity' => '2.000', 'unit' => 'ks',
        'unit_price' => '12500.50', 'discount_percent' => $u340I === 2 ? '10.00' : '0.00', 'vat_rate' => '21.00', 'line_net' => '25001.00'];
}
$u340Snapshot = ['v' => 1, 'number' => '2026-0340', 'title' => 'Lasery pro dílnu', 'currency' => 'CZK', 'language' => '', 'vat_mode' => 'standard', 'vat_payer' => true,
    'valid_until' => '2026-12-12', 'discount_percent' => '0.00', 'sent_at' => '2026-11-12 10:00:00',
    'supplier' => ['name' => 'Řezání 340 s.r.o.', 'reg_no' => '12345678', 'vat_id' => 'CZ12345678', 'register' => '', 'street' => 'Údolní 12', 'city' => 'Brno', 'postcode' => '602 00', 'country' => '', 'phone' => '', 'email' => 'info@example.cz'],
    'customer' => ['name' => 'Jiří Dvořák', 'company' => 'Dvořák a syn s.r.o.', 'address' => "Křižíkova 5\n186 00 Praha 8", 'reg_no' => '', 'vat_id' => '', 'email' => 'jiri@example.cz'],
    'intro' => 'Posíláme nabídku podle poptávky.', 'terms' => 'Dodání do 4 týdnů.', 'footer' => 'Řezání 340 s.r.o., zapsaná u KS v Brně', 'lines' => $u340Lines,
    'totals' => ['net' => '1000040.00', 'vat' => '210008.40', 'gross' => '1210048.40', 'rates' => [['rate' => '21.00', 'net' => '1000040.00', 'vat' => '210008.40']], 'unpriced' => 0]];
$u340Sent = ['status' => 'sent', 'replaced_by' => null, 'draft' => false];
$u340Quote = QuotePdf::render($u340App, $u340Snapshot, $u340Sent);
$u340Q = $u340Read((string) $u340Quote);
$u340All = implode("\n", $u340Q['pages']);
check('3.40 A: the quote\'s PDF is valid and has more pages for 40 lines, each with the header of the lines and "Strana x z y"', [
    $u340Q['xref'], $u340Q['count'] >= 2, count(array_filter($u340Q['pages'], fn (string $p): bool => str_contains($p, "Položka\nMnožství\nCena za jednotku\nSleva\nDPH\nCelkem"))) === $u340Q['count'],
    str_contains($u340Q['pages'][0], 'Strana 1 z ' . $u340Q['count']), str_contains(end($u340Q['pages']), 'Strana ' . $u340Q['count'] . ' z ' . $u340Q['count'])], [true, true, true, true, true]);
// a no-break space is drawn (and read back) as a space; wrap() never breaks there
check('3.40 A: the PDF carries what the page shows – number, title, dates, supplier, customer, lines with codes, discount, VAT per rate, totals, terms and footer', array_map(fn (string $s): bool => str_contains($u340All, $s), [
    'Nabídka 2026-0340', 'Lasery pro dílnu', 'Datum: 12. 11. 2026', 'Platná do: 12. 12. 2026', 'Řezání 340 s.r.o.', 'IČO: 12345678', 'ZÁKAZNÍK', 'Dvořák a syn s.r.o.', 'Křižíkova 5',
    'Posíláme nabídku podle poptávky.', 'Laserová řezačka 40', 'LS-40', 'Montáž a zaškolení obsluhy.', "10 %", "12 500,50 Kč", 'DPH 21 % z 1 000 040,00 Kč',
    'Celkem včetně DPH', "1 210 048,40 Kč", 'Ceny jsou bez DPH; DPH se počítá pro každou sazbu zvlášť.', 'Podmínky', 'Dodání do 4 týdnů.', 'zapsaná u KS v Brně']),
    array_fill(0, 22, true));
$u340Expired = $u340Read((string) QuotePdf::render($u340App, ['language' => 'en', 'lines' => array_slice($u340Lines, 0, 2)] + $u340Snapshot, ['status' => 'expired', 'replaced_by' => '2026-0341', 'draft' => false]));
check('3.40 A: a quote in English is labelled in English, and the status line of a replaced quote is there as on the page', [
    str_contains($u340Expired['pages'][0] ?? '', 'Quotation 2026-0340'), str_contains($u340Expired['pages'][0] ?? '', 'This quotation was replaced by quotation 2026-0341.'),
    str_contains($u340Expired['pages'][0] ?? '', 'Page 1 of 1'), $u340Expired['count']], [true, true, true, 1]);
check('3.40 A (A-9): no PDF for a right-to-left quote or for text the standard fonts cannot draw – the page keeps "Print or save as PDF"', [
    QuotePdf::render($u340App, ['language' => 'ar'] + $u340Snapshot, $u340Sent), QuotePdf::render($u340App, ['customer' => ['name' => 'Иван Петров'] + $u340Snapshot['customer']] + $u340Snapshot, $u340Sent)],
    [null, null]);
check('3.40 A: the file name is the quote\'s number (letters, digits, dot, dash, underscore), else quote-<id>.pdf', [
    QuotePdf::filename('2026-0340', 7), QuotePdf::filename('N/2026 "12"', 7), QuotePdf::filename(null, 7), QuotePdf::filename('../', 9)],
    ['2026-0340.pdf', 'N-2026-12.pdf', 'quote-7.pdf', 'quote-9.pdf']);
$u340Response = QuotePdf::response('%PDF-', '2026-0340.pdf');
check('3.40 A: the download is an attachment, never cached or indexed', [$u340Response->headers['Content-Type'], $u340Response->headers['Content-Disposition'], $u340Response->headers['Cache-Control'], $u340Response->headers['X-Robots-Tag']],
    ['application/pdf', 'attachment; filename="2026-0340.pdf"', 'private, no-store', 'noindex, nofollow']);

// e-mail attachments: safe names and types, a size limit; the setting attaches the PDF by default
$u340Too = null;
try {
    Mail::attachments([['name' => 'a.pdf', 'type' => 'application/pdf', 'data' => str_repeat('x', Mail::MAX_ATTACHMENTS + 1)]]);
} catch (InvalidArgumentException $e) {
    $u340Too = $e->getMessage();
}
check('3.40 A: an attachment\'s name loses paths, quotes and line breaks, an odd media type becomes octet-stream, too large is refused; quote_attach_pdf is on by default', [
    array_map(fn (array $a): array => [$a['name'], $a['type']], Mail::attachments([['name' => "../../etc/Nab\"ídka\r\n.pdf", 'type' => 'application/pdf', 'data' => 'x'], ['name' => '', 'type' => "text/html\r\nX: y", 'data' => 'y']])),
    $u340Too, Kaleta\Core\Settings::DEFAULTS['quote_attach_pdf']],
    [[['Nab-dka-.pdf', 'application/pdf'], ['attachment', 'application/octet-stream']], 'The attachments of an e-mail are too large.', '1']);
