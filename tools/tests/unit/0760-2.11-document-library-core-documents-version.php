<?php

declare(strict_types=1);

/* ---------- 2.11: document library (Core\Documents) – version-change detection, the download token, file paths ---------- */
$documentsCollection = ['preset' => 'documents', 'detail' => 1, 'pole' => [['klic' => 'file', 'popisek' => 'File', 'typ' => 'soubor'], ['klic' => 'version', 'popisek' => 'Version', 'typ' => 'text']]];
check('2.11 Documents::replacedFile – a changed file keeps the previous file with its version', Kaleta\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/cenik-v1.pdf', 'version' => '1.0'], ['file' => '/media/cenik-v2.pdf', 'version' => '2.0']), ['/media/cenik-v1.pdf', '1.0']);
check('2.11 Documents::replacedFile – the same file, a first file, a collection without the preset and a file field of another type keep nothing', [
    Kaleta\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/a.pdf', 'version' => '1'], ['file' => '/media/a.pdf', 'version' => '2']),
    Kaleta\Core\Documents::replacedFile($documentsCollection, ['file' => '', 'version' => ''], ['file' => '/media/a.pdf']),
    Kaleta\Core\Documents::replacedFile(['preset' => '', 'pole' => $documentsCollection['pole']], ['file' => '/media/a.pdf'], ['file' => '/media/b.pdf']),
    Kaleta\Core\Documents::replacedFile(['preset' => 'documents', 'pole' => [['klic' => 'file', 'popisek' => 'File', 'typ' => 'text']]], ['file' => '/media/a.pdf'], ['file' => '/media/b.pdf']),
], [null, null, null, null]);
check('2.11 Documents::replacedFile – a removed file is kept too; without a version field the version is empty', [
    Kaleta\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/a.pdf', 'version' => '3'], ['file' => '']),
    Kaleta\Core\Documents::replacedFile(['preset' => 'documents', 'pole' => [$documentsCollection['pole'][0]]], ['file' => '/media/a.pdf', 'version' => '3'], ['file' => '/media/b.pdf'])], [['/media/a.pdf', '3'], ['/media/a.pdf', '']]);
$documentKey = str_repeat('k', 64);
$documentToken = Kaleta\Core\Documents::token($documentKey, '/media/docs/cenik-2026.pdf', 1_900_000_000);
$tamperedSignature = substr($documentToken, 0, -1) . (substr($documentToken, -1) === 'a' ? 'b' : 'a');
$otherFile = (string) preg_replace('/^(\d+)\.[^.]+/', '$1.' . rtrim(strtr(base64_encode('/media/other.pdf'), '+/', '-_'), '='), $documentToken);
check('2.11 Documents::token – a valid token gives its file; expired, a changed signature, a changed file, another key and nonsense are refused', [
    Kaleta\Core\Documents::verifyToken($documentKey, $documentToken, 1_899_999_999), Kaleta\Core\Documents::verifyToken($documentKey, $documentToken, 1_900_000_001),
    Kaleta\Core\Documents::verifyToken($documentKey, $tamperedSignature, 1_899_999_999), Kaleta\Core\Documents::verifyToken($documentKey, $otherFile, 1_899_999_999),
    Kaleta\Core\Documents::verifyToken(str_repeat('x', 64), $documentToken, 1_899_999_999), Kaleta\Core\Documents::verifyToken($documentKey, 'nonsense', 1_899_999_999),
], ['/media/docs/cenik-2026.pdf', null, null, null, null, null]);
check('2.11 Documents::token – a signed token still serves only files from Media or https', [
    Kaleta\Core\Documents::verifyToken($documentKey, Kaleta\Core\Documents::token($documentKey, '/config.php', 1_900_000_000), 1_899_999_999),
    Kaleta\Core\Documents::verifyToken($documentKey, Kaleta\Core\Documents::token($documentKey, '/media/../config.php', 1_900_000_000), 1_899_999_999),
    Kaleta\Core\Documents::verifyToken($documentKey, Kaleta\Core\Documents::token($documentKey, 'https://files.example/a.pdf', 1_900_000_000), 1_899_999_999),
    preg_match('/^\d{10}\.[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $documentToken)], [null, null, 'https://files.example/a.pdf', 1]);
$versionsHtml = Kaleta\Core\Documents::versionsHtml([['file' => 'media/docs/cenik-v1.pdf', 'version' => '1.0 <b>', 'replaced_at' => '2026-03-01 10:00:00', 'replaced_by' => 'x']], '/web');
check('2.11 Documents::versionsHtml – a heading, a link to the file with the installation folder, the version and the day, everything escaped; nothing without versions',
    [str_starts_with($versionsHtml, '<h2>'), str_contains($versionsHtml, 'href="/web/media/docs/cenik-v1.pdf"'), str_contains($versionsHtml, 'cenik-v1.pdf · '), str_contains($versionsHtml, '&lt;b&gt;'), str_contains($versionsHtml, '<b>'),
        str_contains($versionsHtml, format_date('2026-03-01 10:00:00')), Kaleta\Core\Documents::versionsHtml([], '/web')], [true, true, true, true, false, true, '']);
check('2.11 Documents::filePath and fileName – an https address and an absolute path stay, a path in Media gets the installation folder', [Kaleta\Core\Documents::filePath('https://x.example/a.pdf', '/web'), Kaleta\Core\Documents::filePath('/media/a.pdf', '/web'),
    Kaleta\Core\Documents::filePath('media/a.pdf', '/web'), Kaleta\Core\Documents::fileName('/media/docs/Cen%C3%ADk%202026.pdf')], ['https://x.example/a.pdf', '/media/a.pdf', '/web/media/a.pdf', 'Ceník 2026.pdf']);
check('2.11 Documents::gatedFile – a file from Media, never a path out of it', [Kaleta\Core\Documents::gatedFile(['poslat_soubor' => '/media/x.pdf']), Kaleta\Core\Documents::gatedFile(['poslat_soubor' => '/media/../config.php']), Kaleta\Core\Documents::gatedFile([])], ['/media/x.pdf', '', '']);
$documentsTemplate = (string) json_encode(Kaleta\Builder\Presets::itemTemplate((array) Kaleta\Builder\Presets::get('documents'), Kaleta\Builder\Collections::sanitizeFields([['klic' => 'file', 'popisek' => 'File', 'typ' => 'soubor']])));
check('2.11 documents preset: the item template downloads through the stable address and lists the previous versions; the kind, the address and the English option name are known',
    [str_contains($documentsTemplate, '{{latest}}'), str_contains($documentsTemplate, '{{versions}}'), Kaleta\Core\Audit::KINDS['document'], in_array('download', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), Kaleta\Mcp\Vocabulary::CONTENT['poslat_soubor']],
    [true, true, 'Document expires soon', true, 'send_file']);
