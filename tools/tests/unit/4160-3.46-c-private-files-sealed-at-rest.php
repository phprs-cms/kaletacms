<?php

declare(strict_types=1);

use Kaleta\Core\Applications;
use Kaleta\Core\InternalFiles;
use Kaleta\Core\RemoteBackup;
use Kaleta\Core\Requests;
use Kaleta\Core\SealedFiles;
use Kaleta\Core\Vault;
use Kaleta\Core\Whistleblowing;

/* ---------- 3.46 C: private files sealed at rest (N162, N165), the off-site copy of the private folders, owner decision 5 ---------- */

// a site whose key is still in the database (Vault state "database"), no database connection needed: the fake of 2100
$c346Settings = new Kaleta\Core\Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''));
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($c346Settings, ['secret_key' => bin2hex(random_bytes(32)), 'vault_check' => '']);
$c346Dir = sys_get_temp_dir() . '/kaleta-c346-' . bin2hex(random_bytes(4));
mkdir($c346Dir . '/2026', 0777, true);

// the format and crypto of the CVs, taken out of Applications unchanged: a CV sealed by Applications opens through SealedFiles and back
$c346Plain = 'Contract with Acme – ' . bin2hex(random_bytes(8));
$c346Key = SealedFiles::write($c346Settings, 'internal_files', $c346Plain, $c346Dir . '/2026/a.bin');
$c346Raw = (string) file_get_contents($c346Dir . '/2026/a.bin');
$c346Cv = Applications::sealFile($c346Settings, (string) (function () use ($c346Dir): string { file_put_contents($c346Dir . '/cv.txt', 'CV text'); return $c346Dir . '/cv.txt'; })());
check('3.46 C: SealedFiles is the CV format (KCV1, the wrapped key, secretbox) – a file opens with its own key or its header, not with another purpose; a CV of Applications opens through it', [
    $c346Key !== null && strlen((string) base64_decode($c346Key, true)) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES, str_starts_with($c346Raw, SealedFiles::MAGIC), str_contains($c346Raw, $c346Plain),
    SealedFiles::open($c346Settings, 'internal_files', $c346Dir . '/2026/a.bin', $c346Key), SealedFiles::open($c346Settings, 'internal_files', $c346Dir . '/2026/a.bin', null),
    SealedFiles::open($c346Settings, 'whistleblowing', $c346Dir . '/2026/a.bin', null), Applications::FILE_MAGIC === SealedFiles::MAGIC,
    $c346Cv !== null ? SealedFiles::open($c346Settings, Applications::SECTION, Applications::FOLDER . '/' . $c346Cv['path'], null) : null,
    SealedFiles::isSealed($c346Settings, 'internal_files', $c346Dir . '/2026/a.bin'), glob($c346Dir . '/2026/*.part') ?: []],
    [true, true, false, $c346Plain, $c346Plain, null, true, 'CV text', true, []]);
if ($c346Cv !== null) {
    @unlink(Applications::FOLDER . '/' . $c346Cv['path']);
}

// sealInPlace: crash-safe, never twice, the plain file replaced only after the sealed copy opened to the same content
$c346File = $c346Dir . '/2026/' . str_repeat('ab', 16) . '.pdf';
$c346Evidence = "%PDF-1.4\nEvidence – " . bin2hex(random_bytes(8));
file_put_contents($c346File, $c346Evidence);
$c346Stopped = SealedFiles::sealInPlace($c346Settings, 'whistleblowing', $c346File, '', 1); // killed after the copy was written
$c346AfterStop = [$c346Stopped, (string) file_get_contents($c346File) === $c346Evidence, count(glob($c346File . '.*.sealing') ?: [])];
$c346First = SealedFiles::sealInPlace($c346Settings, 'whistleblowing', $c346File);
$c346Bytes = (string) file_get_contents($c346File);
$c346Again = SealedFiles::sealInPlace($c346Settings, 'whistleblowing', $c346File); // the next run: sealed already
check('3.46 C: sealInPlace – an interrupted run leaves the plain file and a .sealing copy; the next run seals; a third run finds it sealed and returns the same key without writing (never sealed twice)', [
    ...$c346AfterStop, $c346First !== null, str_contains($c346Bytes, 'Evidence'), $c346Again === $c346First, (string) file_get_contents($c346File) === $c346Bytes,
    SealedFiles::open($c346Settings, 'whistleblowing', $c346File, $c346First), SealedFiles::open($c346Settings, 'whistleblowing', $c346File, null)],
    [null, true, 1, true, false, true, true, $c346Evidence, $c346Evidence]);
// the leftover copy is swept only after an hour
$c346Leftover = (glob($c346File . '.*.sealing') ?: [''])[0];
$c346Swept = [SealedFiles::sweepLeftovers($c346Dir), is_file($c346Leftover)];
@touch($c346Leftover, time() - 7200);
$c346Swept[] = SealedFiles::sweepLeftovers($c346Dir);
$c346Swept[] = is_file($c346Leftover);
check('3.46 C: a sealed copy an interrupted run left beside the file is swept after an hour, not before', $c346Swept, [0, true, 1, false]);

// sealInPlace with the hash the row keeps: a file that is not the one the row describes is left as it is
$c346Other = $c346Dir . '/2026/other.md';
file_put_contents($c346Other, 'changed on the disk');
check('3.46 C: sealInPlace refuses a file whose content does not have the sha256 of its row – it stays plain', [
    SealedFiles::sealInPlace($c346Settings, 'internal_files', $c346Other, hash('sha256', 'what was uploaded')), (string) file_get_contents($c346Other)],
    [null, 'changed on the disk']);

// read(): a sealed file never as ciphertext, a plain one only when the row says plain, the hash guards both
$c346Magic = $c346Dir . '/2026/magic.txt';
file_put_contents($c346Magic, SealedFiles::MAGIC . "\x00\x10 a text that starts like a sealed file");
check('3.46 C: read() – a sealed file opens; a plain one is read only when the row says plain; a plain file starting with KCV1 only with its hash; a wrong hash gives nothing', [
    SealedFiles::read($c346Settings, 'whistleblowing', $c346File, null, false), SealedFiles::read($c346Settings, 'whistleblowing', $c346File, null, true, hash('sha256', $c346Evidence)),
    SealedFiles::read($c346Settings, 'internal_files', $c346Other, null, true), SealedFiles::read($c346Settings, 'internal_files', $c346Other, null, false),
    SealedFiles::read($c346Settings, 'internal_files', $c346Magic, null, true), SealedFiles::read($c346Settings, 'internal_files', $c346Magic, null, true, hash('sha256', (string) file_get_contents($c346Magic))) !== null,
    SealedFiles::read($c346Settings, 'whistleblowing', $c346File, null, false, hash('sha256', 'something else')), SealedFiles::read($c346Settings, 'whistleblowing', $c346Dir . '/gone.pdf', null, true)],
    [$c346Evidence, $c346Evidence, 'changed on the disk', null, null, true, null, null]);

check('3.46 C: memory – "128M" and friends in bytes; a small file always has room', [SealedFiles::bytes('128M'), SealedFiles::bytes('1G'), SealedFiles::bytes('512k'), SealedFiles::bytes('-1'), SealedFiles::bytes('1000'), SealedFiles::roomFor(1024)],
    [134217728, 1073741824, 524288, -1, 1000, true]);

// the domains: their folders, paths and Vault purposes; the per-file keys are in columns the Vault reseals (rotation, move)
check('3.46 C: whistleblowing attachments, internal files and request attachments are sealed under their own purposes; the per-file keys sit in sealed columns (Vault::SEALED)', [
    Whistleblowing::FILE_PURPOSE, InternalFiles::FILE_PURPOSE, Requests::FILE_PURPOSE, Vault::SEALED['whistleblowing_cases'][2], Vault::SEALED['internal_file_versions'], Vault::SEALED['requests'],
    str_ends_with(Requests::FOLDER, '/storage/requests'), str_contains(Requests::FOLDER, '/media'),
    Requests::filePath('2026/11/' . str_repeat('cd', 16) . '.bin') === Requests::FOLDER . '/2026/11/' . str_repeat('cd', 16) . '.bin', Requests::filePath('../config.php'), Requests::filePath('2026/11/../../x.bin'),
    Whistleblowing::attachmentPath(['path' => '../../config.php']), Whistleblowing::attachmentPath(['path' => '2026/x.bin'])],
    ['whistleblowing', 'internal_files', 'requests', ['text', 'contact', 'attachments'], ['internal_files', 'id', ['file_key']], ['requests', 'id', ['files']],
        true, false, true, null, null, null, null]);
check('3.46 C: the private attachments for MCP and the screens carry an index, the name and the size – never the path or the key', [
    Requests::filesOut([['name' => 'Price list.pdf', 'ext' => 'pdf', 'size' => 2048, 'path' => '2026/11/x.bin', 'key' => 'secret'], ['name' => 'Team.jpg', 'ext' => 'jpg', 'size' => 10, 'path' => 'p', 'key' => 'k', 'media' => 12]])],
    [[['index' => 0, 'name' => 'Price list.pdf', 'size' => Kaleta\Core\Files::size(2048), 'image' => false, 'media' => 0], ['index' => 1, 'name' => 'Team.jpg', 'size' => Kaleta\Core\Files::size(10), 'image' => true, 'media' => 12]]]);

// the off-site copy: the private folders, each file encrypted under the recovery key – the leftovers of a run in progress stay home
mkdir($c346Dir . '/storage/internal-files/2026/11', 0777, true);
mkdir($c346Dir . '/storage/oznameni/2026', 0777, true);
foreach (['storage/internal-files/.htaccess', 'storage/internal-files/2026/11/' . str_repeat('a', 32) . '.bin', 'storage/internal-files/2026/11/x.bin.1234abcd.part', 'storage/oznameni/2026/' . str_repeat('b', 32) . '.pdf',
    'storage/oznameni/2026/' . str_repeat('b', 32) . '.pdf.1234abcd.sealing'] as $c346Path) {
    file_put_contents($c346Dir . '/' . $c346Path, 'x');
}
check('3.46 C: the off-site copy takes the private folders (CVs, internal files, whistleblowing, requests, enquiries) without .htaccess and half-written copies; its status is a setting of its own', [
    RemoteBackup::PRIVATE_FOLDERS, array_keys(RemoteBackup::privateFiles($c346Dir)), array_key_exists('remote_private_status', Kaleta\Core\Settings::DEFAULTS), str_ends_with(RemoteBackup::PRIVATE_MANIFEST, '/storage/zalohy/private-kopie.json')],
    [['storage/applications', 'storage/internal-files', 'storage/oznameni', 'storage/requests', 'storage/prilohy'],
        ['storage/internal-files/2026/11/' . str_repeat('a', 32) . '.bin', 'storage/oznameni/2026/' . str_repeat('b', 32) . '.pdf'], true, true]);

// migration 0134: a data migration with the highest number, the structure under any code, the files only under the new one
check('3.46 C: migration 0134 is a data migration with the highest number (its behaviour – the structure under any code, interrupted runs, never twice – is tested by tools/test-migrations.sh and the smoke test 4160); update_request takes place_files', [
    in_array('0134-seal-private-files', Kaleta\Core\Migration::DATA, true), KALETA_DB_VERSION >= 134, max(array_keys(Kaleta\Core\Migration::files())) === KALETA_DB_VERSION, isset(Kaleta\Core\Events::TYPES['files.sealed']), // 3.48: 0135 is the highest now
    array_key_exists('place_files', (array) (array_values(array_filter(Kaleta\Mcp\Tools::definitions(), fn (array $t): bool => $t['name'] === 'update_request'))[0]['inputSchema']['properties'] ?? []))],
    [true, true, true, true, true]);

// clean up the temporary tree
$c346Clean = static function (string $dir) use (&$c346Clean): void {
    foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? $c346Clean($f) : @unlink($f);
    }
    @rmdir($dir);
};
$c346Clean($c346Dir);
