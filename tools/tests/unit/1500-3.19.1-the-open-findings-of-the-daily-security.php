<?php

declare(strict_types=1);

/* ---------- 3.19.1: the open findings of the daily security audits ---------- */
// N88: a rollback whose copy back fails keeps the set-aside originals and says which files did not come back (the audit's
// harness: one target not writable stands in for a full disk or a file owned by another user)
$n88 = sys_get_temp_dir() . '/kaleta-n88-' . bin2hex(random_bytes(4));
$n88Aside = $n88 . '/storage/cache/aktualizace-test-puvodni';
mkdir($n88 . '/system/src', 0775, true);
mkdir($n88Aside . '/system/src', 0775, true);
foreach (['index.php' => 'new index', 'system/src/A.php' => 'new A', 'system/neu.php' => 'only in the new version'] as $n88File => $n88Content) {
    file_put_contents($n88 . '/' . $n88File, $n88Content);
}
file_put_contents($n88Aside . '/index.php', 'old index');
file_put_contents($n88Aside . '/system/src/A.php', 'old A');
chmod($n88 . '/system/src/A.php', 0444);
$n88Updater = new Kaleta\Core\Updater((new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor(), $n88);
$n88Restore = new ReflectionMethod(Kaleta\Core\Updater::class, 'restore');
$n88Written = ['index.php', 'system/src/A.php', 'system/neu.php'];
$n88Failed = $n88Restore->invoke($n88Updater, $n88Written, $n88Aside);
check('3.19.1 (N88): a file that cannot be put back is reported, the others come back and the set-aside originals stay', [
    is_writable($n88 . '/system/src/A.php') ? ['system/src/A.php'] : $n88Failed, file_get_contents($n88 . '/index.php'), is_file($n88 . '/system/neu.php'),
    is_file($n88Aside . '/system/src/A.php'), is_file($n88Aside . '/index.php')],
    [['system/src/A.php'], 'old index', false, true, true]);
chmod($n88 . '/system/src/A.php', 0644);
check('3.19.1 (N88): once every file comes back, the set-aside folder goes', [$n88Restore->invoke($n88Updater, $n88Written, $n88Aside), file_get_contents($n88 . '/system/src/A.php'), is_dir($n88Aside)],
    [[], 'old A', false]);
file_put_contents($n88 . '/udrzba.lock', Kaleta\Core\Updater::RESTORE_FAILED_MARK . "\nThe text.\n");
file_put_contents($n88 . '/udrzba-plain.lock', '');
check('3.19.1 (N88): the maintenance lock of a failed rollback is told from an ordinary one, and its text names the count, files, folder and version', [
    Kaleta\Core\Updater::restoreFailed($n88 . '/udrzba.lock'), Kaleta\Core\Updater::restoreFailed($n88 . '/udrzba-plain.lock'), Kaleta\Core\Updater::restoreFailed($n88 . '/none.lock'),
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Updater::restoreFailedText(7, ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php', 'g.php'], 'storage/cache/aktualizace-x-puvodni', '3.19.1'), 'admin-'),
    isset(Kaleta\Core\Events::TYPES['update.restore_failed'])],
    ['The text.', null, null, '7 file(s) could not be put back (a.php, b.php, c.php, d.php, e.php, …), so the site is a mix of two versions. The originals are in storage/cache/aktualizace-x-puvodni: copy them back over FTP, or upload the files of version 3.19.1 over FTP, then delete storage/udrzba.lock. Until then visitors see the maintenance page.', true]);
exec('rm -rf ' . escapeshellarg($n88));

// N76: what the browser drops from a Location before reading it (tab, newline, other controls, spaces at the ends) never
// turns a path into a protocol-relative address; the admin "back" address refuses them outright
$n76 = fn (string $url): string => Kaleta\Core\Response::redirect($url)->headers['Location'];
check('3.19.1 (N76): a tab, a newline or a space cannot make a redirect leave the site', [
    $n76("/\t/evil.example"), $n76("/\n/evil.example/x"), $n76("\t//evil.example"), $n76(" //evil.example"), $n76("/\\\t\\evil.example"), $n76("/\x00/evil.example"),
    $n76("/o-nas\t"), $n76('https://example.com/x'), $n76('/o-nas?a=1#b')],
    ['/evil.example', '/evil.example/x', '/evil.example', '/evil.example', '/evil.example', '/evil.example', '/o-nas', 'https://example.com/x', '/o-nas?a=1#b']);
// N78: a builder anchor takes no id the site's scripts look up – refused when saved, left out when an older build is rendered
[$n78Build, $n78Errors] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => array_map(fn (string $a, int $i): array => ['id' => 'k' . $i, 'typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'kotva' => $a],
    $n78Anchors = ['gtag', 'grecaptcha', 'hcaptcha', 'turnstile', 'poptavka', 'l', 'datalayer', 'obsah', 'ka-x', 's-x', 'cenik', 'o-nas'], array_keys($n78Anchors))], false);
check('3.19.1 (N78): builder anchors skip the ids the site reserves (as texts do since 3.15), ordinary anchors stay', [
    array_values(array_filter(array_map(fn (array $p): ?string => $p['kotva'] ?? null, $n78Build['deti']))), count($n78Errors),
    array_map(Kaleta\Builder\Build::isFreeAnchor(...), ['gtag', 'GTAG', 'cenik'])],
    [['cenik', 'o-nas'], 10, [false, false, true]]);
$n78Context = new Kaleta\Builder\Context($headerApp ?? new Kaleta\Core\App([]));
$n78Html = Kaleta\Builder\Build::html(['v' => 1, 'deti' => [['id' => 'st1', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Old'], 'kotva' => 'gtag'],
    ['id' => 'st2', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Styled'], 'kotva' => 'grecaptcha', 'styl' => ['zaklad' => ['barva' => 'primarni']]]]], $n78Context);
check('3.19.1 (N78): a reserved anchor of a stored build is not written – a styled element keeps its style id', [
    str_contains($n78Html, 'id="gtag"'), str_contains($n78Html, 'id="grecaptcha"'), str_contains($n78Html, 'id="s-st2"'), str_contains($n78Context->css, '#s-st2')], [false, false, true, true]);
// N79: an enquiry's page is a link only as an address on the site or an http(s) address
check('3.19.1 (N79): the page of an enquiry is a link only for /… and http(s)', array_map(Kaleta\Front\Forms::isSafePage(...), [
    '/kontakt', '/en/kontakt?x=1#a', 'https://old.example/kontakt', 'http://old.example', 'javascript:alert(1)', 'JaVaScRiPt:x', ' javascript:x', "java\tscript:x",
    '//evil.example', '/\\evil.example', "/\t/x", 'data:text/html,x', 'kontakt', 'https://', 'https://a.example/ b', '']),
    [true, true, true, true, false, false, false, false, false, false, false, false, false, false, false, false]);
$n79Page = fn (string $page): mixed => (array) Kaleta\Mcp\Tools::enquiryEntry(['date' => '2026-10-01 10:00', 'page' => $page, 'fields' => ['Name' => 'Jana']]) + ['stranka' => null];
$n79Page = fn (string $page): mixed => $n79Page($page)['stranka'];
check('3.19.1 (N79): import_enquiries keeps a safe page and drops a script address', [$n79Page('https://old.example/kontakt'), $n79Page('javascript:alert(document.domain)'), $n79Page('/kontakt')],
    ['https://old.example/kontakt', '', '/kontakt']);
// N80: FTPS through cURL checks the certificate and the host name, speaks only FTP, and needs TLS for commands and data
$n80 = Kaleta\Core\RemoteBackup::ftpsOptions('u', 'p');
check('3.19.1 (N80): the FTPS copy verifies the server (cURL), the address keeps the folder absolute and each segment encoded', [
    $n80[CURLOPT_USE_SSL], $n80[CURLOPT_SSL_VERIFYPEER], $n80[CURLOPT_SSL_VERIFYHOST], $n80[CURLOPT_PROTOCOLS], isset($n80[CURLOPT_SSL_VERIFYSTATUS]) || !isset($n80[CURLOPT_CAINFO]),
    Kaleta\Core\RemoteBackup::ftpUrl('nas.example.com', '/zalohy/web/', 'media/2026/10/a b.jpg'), Kaleta\Core\RemoteBackup::ftpUrl('ftps://nas.example.com:2121/x', '', 'kaleta-1.sql.gz'),
    Kaleta\Core\RemoteBackup::ftpsVerified() === in_array('ftps', curl_version()['protocols'], true),
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\RemoteBackup::ftpsError(60, 'self-signed certificate', 'nas.example.com', ''), 'admin-') !== ''],
    [CURLUSESSL_ALL, true, 2, CURLPROTO_FTP | CURLPROTO_FTPS, true, 'ftp://nas.example.com/%2Fzalohy/web/media/2026/10/a%20b.jpg', 'ftp://nas.example.com:2121/kaleta-1.sql.gz', true, true]);
// N81: the session's sql_mode drops NO_BACKSLASH_ESCAPES wherever it stands, nothing else
check('[source-pin] 3.19.1 (N81): the connection drops NO_BACKSLASH_ESCAPES from the session sql_mode', [str_contains(Kaleta\Core\Db::SQL_MODE, "',NO_BACKSLASH_ESCAPES,'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Db.php'), "\$this->pdo->exec('SET sql_mode = ' . self::SQL_MODE);"), str_contains(Kaleta\Core\Db::SQL_MODE, "',ANSI_QUOTES,'")], [true, true, true]);
// N83: config.php – 0600 when PHP owns the site's folder, 0640 otherwise; world-readable is told
$n83 = sys_get_temp_dir() . '/kaleta-n83-' . bin2hex(random_bytes(4));
mkdir($n83);
file_put_contents($n83 . '/config.php', "<?php\nreturn [];\n");
chmod($n83 . '/config.php', 0644);
$n83Before = Kaleta\Core\Vault::configWorldReadable($n83 . '/config.php');
check('3.19.1 (N83): config.php readable by everyone is told and fixed to 0600 (PHP owns the folder here)', [$n83Before, Kaleta\Core\Vault::canProtectConfig($n83 . '/config.php'),
    Kaleta\Core\Vault::protectConfig($n83 . '/config.php'), sprintf('%04o', fileperms($n83 . '/config.php') & 0777), Kaleta\Core\Vault::configWorldReadable($n83 . '/config.php'),
    Kaleta\Core\Vault::configMode($n83), Kaleta\Core\Vault::configMode('/')],
    [true, true, true, '0600', false, 0600, 0640]);
exec('rm -rf ' . escapeshellarg($n83));
check('[source-pin] 3.19.1 (N83, N3191-8): the installer protects config.php right after writing it – only to 0600, when PHP owns the folder', (bool) preg_match("/file_put_contents\\(KALETA_ROOT \\. '\\/config\\.php'.*?if \\(\\\\Kaleta\\\\Core\\\\Vault::configMode\\(\\) === 0600\\) \\{\s*\\\\Kaleta\\\\Core\\\\Vault::protectConfig\\(KALETA_ROOT \\. '\\/config\\.php'\\)/s",
    (string) file_get_contents(KALETA_SYSTEM . '/src/Install/Installer.php')), true);
// N84: what a new signing key withdraws is said, in every admin language
check('3.19.1 (N84, N3191-11): the confirmation lists what a new signing key stops and what it does not touch, translated', [count(Kaleta\Core\SigningKey::CONSEQUENCES),
    array_values(array_filter([...Kaleta\Core\SigningKey::CONSEQUENCES, Kaleta\Core\SigningKey::NOTE], fn (string $c): bool => Kaleta\Core\Language::runWith('cs', fn (): string => t($c), 'admin-') === $c)),
    isset(Kaleta\Core\Events::TYPES['security.signing_key_rotated'])], [8, [], true]);
// N85: install.sql – one statement each; a second one after a semicolon on the same line is refused (the audit's example)
check('3.19.1 (N85): the install.sql guard takes one statement – a semicolon outside quotes and comments ends it', array_map(Kaleta\Extension\Registry::isSingleStatement(...), [
    'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT); DROP TABLE ka_nastaveni;',
    'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT)', "CREATE TABLE IF NOT EXISTS ka_ext_x_a (a VARCHAR(5) DEFAULT ';', b TEXT COMMENT 'it''s; fine', `c;d` INT)",
    "CREATE TABLE IF NOT EXISTS ka_ext_x_a (a VARCHAR(9) DEFAULT 'x\\';DROP TABLE ka_nastaveni')", "CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT) -- a comment; with a semicolon\n",
    'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT) /*!50000 ; DROP TABLE ka_nastaveni */', 'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT); /* x */ DROP TABLE ka_nastaveni',
    "CREATE TABLE IF NOT EXISTS ka_ext_x_a (a VARCHAR(5) DEFAULT 'open", 'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT)--x;DROP TABLE ka_nastaveni']),
    [false, true, true, true, true, false, false, false, false]);
check('[source-pin] 3.19.1 (N85): the guard of Registry::enable checks the whole statement', str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Extension/Registry.php'), '|| !self::isPlainCreateTable($statement)'), true);
// N86: the add-on public/ rules of .htaccess (read from the file, as Apache matches them) refuse PHP with path info and a missing name
preg_match_all('/^\s*(RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-f\s*\n\s*)?RewriteRule\s+(\^extensions\/\S+)\s+-\s+\[F,L,NC\]/m', (string) file_get_contents(KALETA_ROOT . '/.htaccess'), $n86Rules, PREG_SET_ORDER);
$n86Refused = function (string $path, bool $isFile) use ($n86Rules): bool {
    foreach ($n86Rules as $rule) {
        if (preg_match('#' . str_replace('#', '\#', $rule[2]) . '#i', $path) === 1 && ($rule[1] === '' || !$isFile)) {
            return true;
        }
    }

    return false;
};
check('3.19.1 (N86): .htaccess refuses an add-on\'s PHP in public/ with path info, a double extension or a negotiated name, and serves its files', [count($n86Rules),
    $n86Refused('extensions/hello/public/run.php/x', true), $n86Refused('extensions/hello/public/RUN.PHP/x/y', true), $n86Refused('extensions/hello/public/run.php.txt', true),
    $n86Refused('extensions/hello/public/run.php', true), $n86Refused('extensions/hello/public/run', false), $n86Refused('extensions/hello/Extension.php', true),
    $n86Refused('extensions/hello/public/hello.css', true), $n86Refused('extensions/hello/public/img/photo.webp', true)],
    [3, true, true, true, true, true, true, false, false]);
preg_match("/if \\(preg_match\\('(#\\^\\/\\(system.+?#i)', \\\$path\\)\\)/", (string) file_get_contents(KALETA_SYSTEM . '/dev-router.php'), $n86Router);
check('[source-pin] 3.19.1 (N86): the development router and the nginx example refuse the same', [preg_match($n86Router[1] ?? '/$^/', '/extensions/hello/public/run.php/x'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/nginx.example.conf'), 'location ~* ^/extensions/[^/]+/public/.+\.(php\d?|pht|phps|phtml|phar)(\.|/|$) { return 403; }')], [1, true]);
// N82, N87: the events the change log's new rows go with
check('3.19.1 (N82, N87): backup restores and deletions and add-ons switched off are known event types', [isset(Kaleta\Core\Events::TYPES['backup.restored']),
    isset(Kaleta\Core\Events::TYPES['backup.deleted']), isset(Kaleta\Core\Events::TYPES['addon.disabled'])], [true, true, true]);

// Pre-release review of 3.19.1
// N3191-3: a file is on the rollback list before its copy is written – a copy that fails half-way leaves a truncated target
$n3191Updater = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Updater.php');
$n3191 = sys_get_temp_dir() . '/kaleta-n3191-' . bin2hex(random_bytes(4));
mkdir($n3191 . '/aside/system', 0775, true);
mkdir($n3191 . '/system', 0775, true);
file_put_contents($n3191 . '/aside/system/B.php', str_repeat('old ', 100));
file_put_contents($n3191 . '/system/B.php', 'ol'); // the copy stopped after two bytes (a full disk)
check('[source-pin] 3.19.1 (N3191-3): the rollback list takes a file before its copy, and a truncated target comes back', [
    strpos($n3191Updater, '$written[] = $relativePath;') < strpos($n3191Updater, "if (!copy(\$workDir . '/' . \$relativePath, \$target))"),
    (new ReflectionMethod(Kaleta\Core\Updater::class, 'restore'))->invoke(new Kaleta\Core\Updater((new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor(), $n3191), ['system/B.php'], $n3191 . '/aside'),
    file_get_contents($n3191 . '/system/B.php') === str_repeat('old ', 100), is_dir($n3191 . '/aside')], [true, [], true, false]);
exec('rm -rf ' . escapeshellarg($n3191));
// N3191-4, N3191-5: an earlier rollback's mark survives a failed or cleanly rolled-back update; the mark is written before the restore
check('[source-pin] 3.19.1 (N3191-4, N3191-5): the lock goes only after a complete update, an earlier mark comes back otherwise, the rollback marks first', [
    str_contains($n3191Updater, 'if ($succeeded || (!$restoreFailed && $stuckBefore === null)) {'), str_contains($n3191Updater, 'self::writeMark($stuckBefore);'),
    strpos($n3191Updater, '$marked = self::writeMark(') < strpos($n3191Updater, '$failed = $this->restore($written, $setAside);')], [true, true, true]);
// N3191-6: a form's and a booking's return anchor is the id they render – never a reserved one
check('3.19.1 (N3191-6): forms and bookings return to the id they render, never a reserved anchor', [
    Kaleta\Builder\Elements\Form::anchor(['id' => 'f1', 'kotva' => 'gtag']), Kaleta\Builder\Elements\Form::anchor(['id' => 'f1', 'kotva' => 'gtag', 'styl' => ['zaklad' => ['barva' => 'primarni']]]),
    Kaleta\Builder\Elements\Form::anchor(['id' => 'f1', 'kotva' => 'kontakt']), Kaleta\Builder\Elements\Booking::anchor(['id' => 'b1', 'kotva' => 'poptavka'])],
    ['formular-f1', 's-f1', 'kontakt', 'rezervace-b1']);
// N3191-7: install.sql – an executable comment of MariaDB and a SELECT anywhere in the statement are refused too
check('3.19.1 (N3191-7): the install.sql guard refuses /*M! and a CREATE TABLE … SELECT', array_map(Kaleta\Extension\Registry::isPlainCreateTable(...), [
    'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT) /*M! ; DROP TABLE ka_nastaveni */', 'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT) SELECT * FROM ka_uzivatele',
    'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT) AS select heslo FROM ka_uzivatele', 'CREATE TABLE IF NOT EXISTS ka_ext_x_a (SELECT * FROM ka_uzivatele)',
    "CREATE TABLE IF NOT EXISTS ka_ext_x_a (`select` INT, note VARCHAR(20) DEFAULT 'select me') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'CREATE TABLE IF NOT EXISTS ka_ext_x_a (id INT) -- select'."\n"]),
    [false, false, false, false, true, true]);
// N3191-2: a value sealed with the previous signing key still opens while the key is in the database; a new rotation reseals it
$n3191Db = (new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor();
$n3191Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(function () use ($n3191Db): void { $this->db = $n3191Db; })->call($n3191Settings);
[$n3191Old, $n3191New] = [str_repeat('a', 64), str_repeat('b', 64)];
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($n3191Settings, ['secret_key' => $n3191New, 'secret_key_previous' => $n3191Old . '|' . time(), 'vault_check' => '']);
$n3191Sealed = Kaleta\Core\Vault::seal(Kaleta\Core\Vault::derive($n3191Old, 'connectors'), 'token');
$n3191Resealed = Kaleta\Core\Vault::reseal(Kaleta\Core\Vault::derive($n3191New, 'connectors'), Kaleta\Core\Vault::derive(str_repeat('c', 64), 'connectors'), $n3191Sealed, Kaleta\Core\Vault::derive($n3191Old, 'connectors'));
check('3.19.1 (N3191-2): the previous signing key opens what a request sealed in the moment of the change, and the next reseal carries it over', [
    Kaleta\Core\Vault::state($n3191Settings), Kaleta\Core\Vault::previousKey($n3191Settings)[0] === $n3191Old, Kaleta\Core\Vault::openFor($n3191Settings, 'connectors', $n3191Sealed),
    Kaleta\Core\Vault::openFor($n3191Settings, 'whistleblowing', $n3191Sealed), $n3191Resealed !== null ? Kaleta\Core\Vault::open(Kaleta\Core\Vault::derive(str_repeat('c', 64), 'connectors'), $n3191Resealed) : null,
    Kaleta\Core\Vault::previousKey($reportSettings([]))], ['database', true, 'token', null, 'token', [null, 0]]);
check('[source-pin] 3.19.1 (N3191-1): a new signing key reads the key in force under a lock inside the transaction and stops when values would turn unreadable', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SigningKey.php'), "WHERE promenna = 'secret_key' FOR UPDATE"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SigningKey.php'), 'if ($unreadable > $before) {')], [true, true]);
// N3191-9: no total time limit on an FTPS upload, a stalled one ends
check('3.19.1 (N3191-9): FTPS uploads end when they stall, not after a fixed time', [isset($n80[CURLOPT_TIMEOUT]), $n80[CURLOPT_LOW_SPEED_LIMIT] ?? null, $n80[CURLOPT_LOW_SPEED_TIME] ?? null], [false, 1024, 60]);

