<?php

declare(strict_types=1);

use Kaleta\Core\Applications;
use Kaleta\Core\Files;
use Kaleta\Core\FileSignature;
use Kaleta\Core\InternalFiles;

/* ---------- 3.46.2: file checks without the fileinfo extension (Files::mimeOf, Core\FileSignature) ---------- */

$u4230Dir = sys_get_temp_dir() . '/kaleta-u4230-' . bin2hex(random_bytes(4));
mkdir($u4230Dir, 0700);
/** writes a fixture and returns its path */
$u4230File = function (string $name, string $bytes) use ($u4230Dir): string {
    file_put_contents($u4230Dir . '/' . $name, $bytes);

    return $u4230Dir . '/' . $name;
};
/** a ZIP with the given entries (name => content); $stored = the entries kept uncompressed, in this order first */
$u4230Zip = function (string $name, array $entries, array $stored = []) use ($u4230Dir): string {
    $zip = new ZipArchive();
    $zip->open($u4230Dir . '/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $content) {
        $zip->addFromString($entry, $content);
        if (in_array($entry, $stored, true)) {
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
    }
    $zip->close();

    return $u4230Dir . '/' . $name;
};
$u4230Ooxml = fn (string $main, string $part, string $extra = ''): array => [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/' . $part . '" ContentType="' . $main . '"/>' . $extra . '</Types>',
    $part => '<?xml version="1.0"?><document/>',
];
$u4230Ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);
$u4230Pdf = $u4230File('cv.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

// every type of the allowlist: the signature confirms it
$u4230Cases = [
    'pdf' => $u4230Pdf,
    'doc' => $u4230File('a.doc', $u4230Ole), 'xls' => $u4230File('a.xls', $u4230Ole), 'ppt' => $u4230File('a.ppt', $u4230Ole),
    'docx' => $u4230Zip('a.docx', $u4230Ooxml('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', 'word/document.xml')),
    'xlsx' => $u4230Zip('a.xlsx', $u4230Ooxml('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', 'xl/workbook.xml')),
    'pptx' => $u4230Zip('a.pptx', $u4230Ooxml('application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', 'ppt/presentation.xml')),
    'odt' => $u4230Zip('a.odt', ['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<x/>'], ['mimetype']),
    'ods' => $u4230Zip('a.ods', ['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => '<x/>'], ['mimetype']),
    'odp' => $u4230Zip('a.odp', ['mimetype' => 'application/vnd.oasis.opendocument.presentation', 'content.xml' => '<x/>'], ['mimetype']),
    'epub' => $u4230Zip('a.epub', ['mimetype' => 'application/epub+zip', 'META-INF/container.xml' => '<x/>'], ['mimetype']),
    'zip' => $u4230Zip('a.zip', ['notes.txt' => 'hello']),
    'rtf' => $u4230File('a.rtf', '{\\rtf1\\ansi Hello}'),
    'txt' => $u4230File('a.txt', "Dobrý den,\nposílám životopis.\n"),
    'md' => $u4230File('a.md', "# Notes\n\n- one\n"),
    'csv' => $u4230File('a.csv', "name;city\nEva;Brno\n"),
    'gpx' => $u4230File('a.gpx', '<?xml version="1.0"?><gpx version="1.1"><trk><name>Trip</name></trk></gpx>'),
    'ics' => $u4230File('a.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"),
    'jpg' => $u4230File('a.jpg', "\xFF\xD8\xFF\xE0\0\x10JFIF\0" . str_repeat("\0", 20)),
    'jpeg' => $u4230File('a.jpeg', "\xFF\xD8\xFF\xE1" . str_repeat("\0", 20)),
    'png' => $u4230File('a.png', "\x89PNG\r\n\x1A\n\0\0\0\rIHDR" . str_repeat("\0", 20)),
    'gif' => $u4230File('a.gif', 'GIF89a' . str_repeat("\0", 20)),
    'webp' => $u4230File('a.webp', 'RIFF' . pack('V', 20) . 'WEBPVP8 ' . str_repeat("\0", 12)),
    'svg' => $u4230File('a.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'),
    'mp3' => $u4230File('a.mp3', 'ID3' . "\x04\0\0" . str_repeat("\0", 20)),
    'm4a' => $u4230File('a.m4a', "\0\0\0\x20ftypM4A " . str_repeat("\0", 20)),
    'mp4' => $u4230File('a.mp4', "\0\0\0\x20ftypisom" . str_repeat("\0", 20)),
    'ogg' => $u4230File('a.ogg', 'OggS' . str_repeat("\0", 20)),
    'wav' => $u4230File('a.wav', 'RIFF' . pack('V', 36) . 'WAVEfmt ' . str_repeat("\0", 20)),
    'webm' => $u4230File('a.webm', "\x1A\x45\xDF\xA3" . str_repeat("\0", 20)),
    'woff' => $u4230File('a.woff', 'wOFF' . str_repeat("\0", 20)),
    'woff2' => $u4230File('a.woff2', 'wOF2' . str_repeat("\0", 20)),
];
$u4230Sniffed = [];
foreach ($u4230Cases as $extension => $path) {
    $u4230Sniffed[$extension] = FileSignature::sniff($path, $extension);
}
check('3.46.2: the signature allowlist confirms every type it knows and answers its content type', $u4230Sniffed,
    array_map(fn (string $extension): string => FileSignature::TYPES[$extension], array_combine(array_keys($u4230Cases), array_keys($u4230Cases))));
check('3.46.2: the allowlist covers every attachment type of Media, every CV type of Applications and every type of Internal files', [
    array_values(array_diff(Files::FILE_EXTENSIONS, array_keys(FileSignature::TYPES))), array_values(array_diff(Applications::CV_EXTENSIONS, array_keys(FileSignature::TYPES))),
    array_values(array_diff(array_keys(InternalFiles::TYPES), array_keys(FileSignature::TYPES))),
], [[], [], []]);
check('3.46.2: what the allowlist answers for a CV type is what Applications accepts for it', array_values(array_filter(Applications::CV_EXTENSIONS,
    fn (string $extension): bool => !Applications::contentFits($extension, FileSignature::TYPES[$extension]))), []);

// what it refuses: a program or a script whatever the name, the wrong signature, a ZIP that is no document of the kind,
// a macro-enabled document named .docx, markup or binary bytes in a text file, an unknown extension
$u4230Refused = [
    'an .exe renamed .pdf' => [$u4230File('setup.pdf', 'MZ' . "\x90\0\x03\0\0\0\x04\0" . str_repeat("\0", 50) . 'This program cannot be run in DOS mode'), 'pdf'],
    'an ELF program named .txt' => [$u4230File('elf.txt', "\x7FELF\x02\x01\x01" . str_repeat("\0", 20)), 'txt'],
    'a Mach-O program named .doc' => [$u4230File('macho.doc', "\xCF\xFA\xED\xFE" . str_repeat("\0", 20)), 'doc'],
    'a shell script named .txt' => [$u4230File('run.txt', "#!/bin/sh\necho hi\n"), 'txt'],
    'a ZIP without the parts of a Word document, named .docx' => [$u4230Zip('plain.docx', ['notes.txt' => 'hello', 'word/readme.txt' => 'x']), 'docx'],
    'a .docm (macro-enabled content type) named .docx' => [$u4230Zip('macro.docx', $u4230Ooxml('application/vnd.ms-word.document.macroEnabled.main+xml', 'word/document.xml')), 'docx'],
    'a Word document with a VBA project named .docx' => [$u4230Zip('vba.docx', $u4230Ooxml('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', 'word/document.xml') + ['word/vbaProject.bin' => 'x']), 'docx'],
    'a Word document named .xlsx' => [$u4230Cases['docx'], 'xlsx'],
    'an OpenDocument spreadsheet named .odt' => [$u4230Cases['ods'], 'odt'],
    'a PDF named .docx' => [$u4230Pdf, 'docx'],
    'a PNG named .jpg' => [$u4230Cases['png'], 'jpg'],
    'HTML named .pdf' => [$u4230File('page.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'), 'pdf'],
    'HTML named .txt' => [$u4230File('page.txt', "Hello\n<script>alert(1)</script>\n"), 'txt'],
    'PHP named .csv' => [$u4230File('code.csv', "a;b\n<?php echo 1; ?>\n"), 'csv'],
    'a text file with a NUL byte' => [$u4230File('nul.txt', "abc\0def"), 'txt'],
    'a text file that is not UTF-8' => [$u4230File('latin.txt', "P\xF8\xEDli\xB9 \xBElu\xBBou\xE8k\xFD k\xF9\xF2"), 'txt'],
    'an SVG named .gpx' => [$u4230Cases['svg'], 'gpx'],
    'an empty file named .pdf' => [$u4230File('empty.pdf', ''), 'pdf'],
    'an unknown extension' => [$u4230Pdf, 'exe'],
    'a missing file' => [$u4230Dir . '/missing.pdf', 'pdf'],
];
check('3.46.2: the allowlist refuses what it cannot confirm (programs, scripts, wrong signatures, ZIPs that are no such document, macros, markup, binary or non-UTF-8 text)',
    array_keys(array_filter($u4230Refused, fn (array $case): bool => FileSignature::sniff($case[0], $case[1]) !== '')), []);
check('3.46.2: a text head cut inside a multi-byte character still counts as UTF-8 when the file goes on', [
    FileSignature::isText('Dobrý den ' . substr('ž', 0, 1), 100), FileSignature::isText('Dobrý den ' . substr('ž', 0, 1), 12), FileSignature::isProgram('MZ'), FileSignature::isProgram('%PDF-'),
], [true, false, true, false]);

// with finfo: Files::mimeOf answers what finfo answers (the behaviour of 3.46.1); without it: the allowlist
check('3.46.2: with fileinfo, Files::mimeOf answers what finfo detects', [Files::hasFileinfo(), Files::mimeOf($u4230Pdf), Files::mimeOf($u4230Pdf, 'pdf'), Files::forbiddenContent($u4230Pdf, 'pdf'),
    Files::forbiddenContent($u4230Refused['HTML named .pdf'][0], 'pdf'), Files::mimeOf($u4230Cases['png'], 'png')],
    [class_exists(finfo::class), (new finfo(FILEINFO_MIME_TYPE))->file($u4230Pdf), (new finfo(FILEINFO_MIME_TYPE))->file($u4230Pdf), false, true, 'image/png']);
// an upload without an extension in its path, as PHP keeps it
$u4230Tmp = $u4230File('phpA1b2C3', (string) file_get_contents($u4230Pdf));
$u4230Exe = $u4230File('phpD4e5F6', (string) file_get_contents($u4230Refused['an .exe renamed .pdf'][0]));
$u4230Docm = $u4230File('phpG7h8I9', (string) file_get_contents($u4230Refused['a .docm (macro-enabled content type) named .docx'][0]));
$u4230Png = $u4230File('phpJ1k2L3', (string) file_get_contents($u4230Cases['png']));
$u4230With = [Applications::checkUpload($u4230Tmp, 'CV Eva.pdf'), Applications::checkUpload($u4230Exe, 'cv.pdf')];
Files::$withoutFileinfo = true;
try {
    $u4230Without = [
        Files::hasFileinfo(), Files::mimeOf($u4230Pdf), Files::mimeOf($u4230Tmp, 'pdf'), Files::mimeOf($u4230Tmp), Files::forbiddenContent($u4230Tmp, 'pdf'), Files::forbiddenContent($u4230Exe, 'pdf'),
        Files::forbiddenContent($u4230Cases['svg'], 'svg'), Applications::checkUpload($u4230Tmp, 'CV Eva.pdf'), Applications::checkUpload($u4230Exe, 'cv.pdf'),
        Applications::checkUpload($u4230Docm, 'cv.docx'), Applications::checkUpload($u4230Png, 'photo.png'), Applications::checkUpload($u4230Png, 'photo.jpg'),
        InternalFiles::check($u4230Png, 'diagram.png'), InternalFiles::check($u4230Exe, 'manual.pdf'),
    ];
} finally {
    Files::$withoutFileinfo = false;
}
check('3.46.2: without fileinfo nothing crashes – a PDF CV is accepted, a program named .pdf, a macro document named .docx and a PNG named .jpg are refused, SVG stays forbidden in Media',
    [$u4230With, $u4230Without], [['pdf', null], [false, 'application/pdf', 'application/pdf', '', false, true, true, 'pdf', null, null, 'png', null,
        ['extension' => 'png', 'content' => null], 'The file content does not match its extension.']]);
check('3.46.2: the seam is for tests only – the flag is off again and a real site never reads it', [Files::hasFileinfo(), isset(Kaleta\Core\TestSeams::SWITCHES['KALETA_NO_FILEINFO'])], [class_exists(finfo::class), true]);

// every former finfo call site goes through the helper: no finfo, finfo_open or mime_content_type outside Core\Files
$u4230Callers = [];
$u4230Roots = [KALETA_ROOT . '/system', KALETA_ROOT . '/extensions'];
foreach ($u4230Roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), '/system/src/Core/Files.php')) {
            continue;
        }
        if (preg_match('/new\s+\\\\?finfo\b|\bfinfo_(open|file|buffer)\s*\(|\bmime_content_type\s*\(/i', (string) file_get_contents($file->getPathname())) === 1) {
            $u4230Callers[] = substr($file->getPathname(), strlen(KALETA_ROOT) + 1);
        }
    }
}
foreach (['admin.php', 'index.php', 'install.php'] as $entry) {
    if (preg_match('/new\s+\\\\?finfo\b|\bfinfo_(open|file|buffer)\s*\(|\bmime_content_type\s*\(/i', (string) @file_get_contents(KALETA_ROOT . '/' . $entry)) === 1) {
        $u4230Callers[] = $entry;
    }
}
check('3.46.2: no product file asks finfo (or mime_content_type) itself – every content check goes through Files::mimeOf', $u4230Callers, []);

// sodium: a missing extension is a message, never a fatal error; System status and the installer name both extensions
$u4230Installer = (new ReflectionClass(Kaleta\Install\Installer::class))->newInstanceWithoutConstructor();
$u4230Rows = array_column((new ReflectionMethod(Kaleta\Install\Installer::class, 'requirements'))->invoke($u4230Installer), null, 'nazev');
check('3.46.2: Vault knows whether sodium is there; the installer shows sodium as required (blocking) and fileinfo as recommended (never blocking); the event of a failed check is known', [
    Kaleta\Core\Vault::hasSodium(), $u4230Rows['sodium extension']['ok'] ?? null, $u4230Rows['fileinfo extension']['ok'] ?? null, $u4230Rows['fileinfo extension']['varovani'] ?? null,
    isset(Kaleta\Core\Events::TYPES['form.file_check_failed']),
], [function_exists('sodium_crypto_secretbox'), function_exists('sodium_crypto_secretbox'), true, !class_exists(finfo::class), true]);

array_map('unlink', glob($u4230Dir . '/*') ?: []);
rmdir($u4230Dir);
