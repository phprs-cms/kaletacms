<?php
/**
 * Snímky obrazovek do příručky – pro každý jazyk příručky zvlášť (docs/prirucka/<jazyk>/obrazky/*.webp).
 *
 * Fotí běžící vývojovou instanci s ukázkovým obsahem bezhlavým Chromem, vždy ve světlém režimu a v jazyce příručky:
 * administrace se přepne jazykem účtu, web předponou adresy (/en/, /de/). Česká obrazovka v anglické příručce je chyba,
 * proto se snímek pro jazyk, ve kterém instance obsah nemá, raději nepořídí.
 *
 *   MIROCMS_SNIMKY_COOKIE="mirocms=<session přihlášeného správce>" php tools/snimky-prirucky.php [jazyk…] [--jen=nazev,nazev]
 *
 * Potřebuje: Google Chrome, cwebp, běžící instanci (výchozí http://localhost:8080, jinak MIROCMS_SNIMKY_CIL) a config.php
 * téže instance (jazyk účtu se přepíná v databázi a po skončení vrací). Seznam snímků: tools/snimky-prirucky.seznam.php.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SIRKA = 1280;
const PORT = 8098;

$cookie = (string) getenv('MIROCMS_SNIMKY_COOKIE');
$cil = rtrim((string) (getenv('MIROCMS_SNIMKY_CIL') ?: 'http://localhost:8080'), '/');
if (!preg_match('/^mirocms=[a-f0-9]{16,}$/', $cookie) || !is_file(CHROME)) {
    fwrite(STDERR, "Chybí MIROCMS_SNIMKY_COOKIE (mirocms=…) nebo Google Chrome.\n");
    exit(1);
}
$jazyky = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => in_array($a, ['cs', 'en', 'de'], true))) ?: ['cs', 'en', 'de'];
$jen = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--jen=')) {
        $jen = explode(',', substr($a, 6));
    }
}
$seznam = require __DIR__ . '/snimky-prirucky.seznam.php';

$config = require MIROCMS_ROOT . '/config.php';
$db = MiroCMS\Core\Db::fromConfig($config['db']);
// účet, kterému patří předaná session (jazyk administrace je volba účtu); výchozí jméno jde změnit přes MIROCMS_SNIMKY_UCET
$ucet = $db->one('SELECT idu, jazyk FROM {user} WHERE user = ?', [(string) (getenv('MIROCMS_SNIMKY_UCET') ?: 'admin')]);
if ($ucet === null) {
    fwrite(STDERR, "Nenašel jsem účet správce (MIROCMS_SNIMKY_UCET).\n");
    exit(1);
}

$proxy = proc_open(['php', '-S', '127.0.0.1:' . PORT, __DIR__ . '/snimky-proxy.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $roury, null,
    ['MIROCMS_SNIMKY_CIL' => $cil, 'MIROCMS_SNIMKY_COOKIE' => $cookie, 'PATH' => (string) getenv('PATH')]);
usleep(800000);
$tmp = sys_get_temp_dir() . '/mirocms-snimky-' . getmypid();
mkdir($tmp);
$chyb = 0;
try {
    foreach ($jazyky as $jazyk) {
        $db->update('user', ['jazyk' => $jazyk], ['idu' => $ucet['idu']]);
        $slozka = MIROCMS_ROOT . "/docs/prirucka/$jazyk/obrazky";
        is_dir($slozka) || mkdir($slozka, 0775, true);
        foreach ($seznam as $nazev => $s) {
            if ($jen !== [] && !in_array($nazev, $jen, true)) {
                continue;
            }
            $adresa = is_array($s['adresa']) ? ($s['adresa'][$jazyk] ?? null) : $s['adresa'];
            if ($adresa === null) {
                continue; // v tomhle jazyce není co fotit
            }
            // adresy webu dostanou předponu jazyka, administrace je společná
            $adresa = !str_starts_with($adresa, 'admin.php') && $jazyk !== 'cs' ? "$jazyk/$adresa" : $adresa;
            $png = "$tmp/$nazev.png";
            @unlink($png);
            $prikaz = sprintf('perl -e %s %s --headless=new --disable-gpu --hide-scrollbars --blink-settings=preferredColorScheme=1 --lang=%s --window-size=%d,%d --virtual-time-budget=4000 --screenshot=%s %s >/dev/null 2>&1',
                escapeshellarg('alarm 60; exec @ARGV'), escapeshellarg(CHROME), $jazyk, SIRKA, $s['vyska'], escapeshellarg($png), escapeshellarg('http://127.0.0.1:' . PORT . '/' . $adresa));
            exec($prikaz);
            if (!is_file($png)) {
                echo "CHYBA  $jazyk/$nazev\n";
                $chyb++;
                continue;
            }
            [$x, $y, $w, $h] = $s['orez'] ?? [0, 0, SIRKA, $s['vyska']];
            exec(sprintf('cwebp -quiet -q 82 -crop %d %d %d %d %s -o %s', $x, $y, $w, $h, escapeshellarg($png), escapeshellarg("$slozka/$nazev.webp")));
            echo "ok     $jazyk/$nazev\n";
        }
    }
} finally {
    $db->update('user', ['jazyk' => $ucet['jazyk']], ['idu' => $ucet['idu']]);
    proc_terminate($proxy);
    array_map(unlink(...), glob("$tmp/*") ?: []);
    @rmdir($tmp);
}
exit($chyb === 0 ? 0 : 1);
