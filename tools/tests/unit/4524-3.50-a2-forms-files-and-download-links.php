<?php

declare(strict_types=1);

/* ---------- 3.50 A2: stricter file checks without fileinfo, the content check of enquiry files, a remembered link only when it is true and alive (L4, L6, L9, L10) ---------- */
use Kaleta\Core\FileLink as A524Link;
use Kaleta\Core\FileSignature as A524Signature;

check('3.50 L4: an MP3 needs a valid Layer III frame header (or ID3) – UTF-16 text with a byte order mark, AAC, a free or bad bit rate and a reserved sample rate are refused', [
    A524Signature::mp3Frame("\xFF\xFB\x90\x00"), A524Signature::mp3Frame("\xFF\xF3\x84\x00"), A524Signature::mp3Frame("\xFF\xFE<\x00h\x00t\x00"),
    A524Signature::mp3Frame("\xFF\xF1\x50\x80"), A524Signature::mp3Frame("\xFF\xFB\x00\x00"), A524Signature::mp3Frame("\xFF\xFB\xF0\x00"),
    A524Signature::mp3Frame("\xFF\xFB\x9C\x00"), A524Signature::mp3Frame("\xFF\xEB\x90\x00"), A524Signature::mp3Frame("\xFF")],
    [true, true, false, false, false, false, false, false, false]);

$a524Dir = sys_get_temp_dir() . '/kaleta-a524-' . bin2hex(random_bytes(4));
mkdir($a524Dir);
$a524File = function (string $name, string $content) use ($a524Dir): string {
    file_put_contents($a524Dir . '/' . $name, $content);

    return $a524Dir . '/' . $name;
};
// L6: the strings are found across the boundary of two pieces, whatever their case; a missing one stays false
$a524Scan = $a524File('scan.bin', str_repeat('x', 1000) . '[Content_Types].xml' . str_repeat('y', 37) . 'WORD/' . str_repeat('z', 50));
check('3.50 L6: scan() reads in overlapping pieces – a string split between two pieces is found, the case is ignored, a missing one is not', [
    A524Signature::scan($a524Scan, ['[Content_Types].xml', 'word/', 'vbaproject.bin'], 1010), A524Signature::scan($a524Scan, ['word/'], 7), A524Signature::scan($a524Dir . '/none', ['word/'])],
    [['[Content_Types].xml' => true, 'word/' => true, 'vbaproject.bin' => false], ['word/' => true], ['word/' => false]]);
// L6: a 48 MB document scanned in a PHP limited to 32 MB of memory (the whole-file read of 3.46.2 died there)
$a524Big = $a524Dir . '/big.docx';
$a524Handle = fopen($a524Big, 'wb');
fwrite($a524Handle, "PK\x03\x04[Content_Types].xml");
for ($a524i = 0; $a524i < 48; $a524i++) {
    fwrite($a524Handle, str_repeat("\0", 1024 * 1024));
}
fwrite($a524Handle, 'word/document.xml');
fclose($a524Handle);
$a524Child = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M -r ' . escapeshellarg('require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true) . '; echo json_encode(Kaleta\Core\FileSignature::scan($argv[1], ["[Content_Types].xml", "word/", "vbaproject.bin"]));') . ' ' . escapeshellarg($a524Big) . ' 2>&1');
check('3.50 L6: a 48 MB file is scanned under a 32 MB memory limit', json_decode($a524Child, true), ['[Content_Types].xml' => true, 'word/' => true, 'vbaproject.bin' => false]);

check('3.50 L10: an enquiry file – a text with markup, a program under any name, a drawing that starts like a program are refused; plain text, a real PDF and an unknown drawing pass', [
    Kaleta\Core\Files::enquiryUploadAccepted($a524File('a.txt', "Hello,\nthe dimensions are 2 x 3 m.\n"), 'txt'),
    Kaleta\Core\Files::enquiryUploadAccepted($a524File('b.txt', '<html><body><script>alert(1)</script></body></html>'), 'txt'),
    Kaleta\Core\Files::enquiryUploadAccepted($a524File('c.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"), 'pdf'),
    Kaleta\Core\Files::enquiryUploadAccepted($a524File('d.dwg', "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200)), 'dwg'),
    Kaleta\Core\Files::enquiryUploadAccepted($a524File('e.dwg', 'AC1032' . random_bytes(300)), 'dwg'),
    Kaleta\Core\Files::enquiryUploadAccepted($a524File('f.zip', '#!/bin/sh' . "\necho hi\n"), 'zip')],
    [true, false, true, false, true, false]);
array_map('unlink', glob($a524Dir . '/*') ?: []);
rmdir($a524Dir);

$a524Key = str_repeat('k', 40);
$a524Query = function (string $path): array {
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

    return $query;
};
$a524Live = $a524Query(A524Link::path($a524Key, 'enquiries', 12, 0, 2_000_000_000));
$a524Dead = $a524Query(A524Link::path($a524Key, 'applications', 5, 1, 1_000_000));
check('3.50 L9: only a link whose signature holds and whose day has not passed is remembered for after the sign-in', [
    A524Link::rememberable($a524Key, $a524Live, 1_800_000_000) !== null, A524Link::rememberable($a524Key, $a524Dead, 1_800_000_000),
    A524Link::rememberable($a524Key, ['id' => '13'] + $a524Live, 1_800_000_000), A524Link::rememberable('another key 0123456789', $a524Live, 1_800_000_000),
    A524Link::rememberable($a524Key, ['module' => 'pages', 'action' => 'list'], 1_800_000_000)],
    [true, null, null, null, null]);
