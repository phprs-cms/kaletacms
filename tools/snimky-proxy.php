<?php
/**
 * Pomůcka pro tools/snimky-prirucky.php: předá požadavek vývojové instanci a přidá k němu přihlašovací cookie,
 * aby bezhlavý prohlížeč viděl administraci. Do stránky vnutí světlý režim (snímky v příručce jsou vždy světlé)
 * a na přání rozbalí nabídku Nápověda. Běží jen místně (php -S 127.0.0.1:…), do balíčku nepatří.
 *
 * Prostředí: MIROCMS_SNIMKY_CIL = adresa instance (http://localhost:8080), MIROCMS_SNIMKY_COOKIE = "mirocms=…".
 */

declare(strict_types=1);

$cil = rtrim((string) getenv('MIROCMS_SNIMKY_CIL'), '/');
$adresa = (string) preg_replace('/([?&])_otevri=[a-z-]+/', '$1', $_SERVER['REQUEST_URI']);
$ch = curl_init($cil . rtrim($adresa, '?&'));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Cookie: ' . getenv('MIROCMS_SNIMKY_COOKIE'), 'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '*/*'), 'Accept-Language: ' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs')],
]);
$odpoved = (string) curl_exec($ch);
$delka = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
http_response_code((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502);
$typ = '';
foreach (explode("\r\n", substr($odpoved, 0, $delka)) as $hlavicka) {
    if (preg_match('/^(content-type|location):\s*(.*)$/i', $hlavicka, $m)) {
        header($hlavicka);
        $typ = strtolower($m[1]) === 'content-type' ? $m[2] : $typ;
    }
}
$telo = substr($odpoved, $delka);
if (str_contains($typ, 'text/html')) {
    $telo = (string) preg_replace('/<html\b/', '<html data-tema="svetly"', $telo, 1);
    if (($_GET['_otevri'] ?? '') === 'napoveda') {
        $telo = str_replace('<details class="napoveda-menu"', '<details open class="napoveda-menu"', $telo);
    }
}
echo $telo;
