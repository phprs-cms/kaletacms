<?php
/**
 * Kaleta - příprava vydání (spouští vydavatel na svém počítači, na web se nenahrává).
 *
 *   php tools/vydani.php 1.0.1 --url=https://github.com/phprs-cms/kaletacms/releases/download/v1.0.1/kaleta-1.0.1.zip \
 *       --zmena="Oprava ..." --zmena="Nové ..." [--bezpecnostni]
 *
 * --bezpecnostni označí vydání jako bezpečnostní opravu: instalace se na ně aktualizují samy a správce dostane e-mail.
 * Soukromý klíč lze místo souboru předat proměnnou prostředí KALETA_KLIC (base64) - pro vydávání z GitHub Actions.
 *
 * Vytvoří dist/kaleta-<verze>.zip (soubory sledované gitem) a dist/aktualizace.json podepsaný soukromým klíčem.
 * Klíče: tools/klice/vydavatel.key (provozní) a tools/klice/zalozni.key (záložní, má ležet offline) jsou SOUKROMÉ - nikdy do gitu.
 * system/aktualizace.pub nese veřejné klíče (na řádek jeden), je součástí systému. Výměna a odvolání klíče: docs/VYDAVANI.md.
 *   php tools/vydani.php --novy-klic=zalozni      založí pár klíčů a veřejný připíše do system/aktualizace.pub
 *   php tools/vydani.php 1.0.1 --klic=zalozni …   podepíše vydání záložním klíčem (ztráta nebo únik provozního)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Jen z příkazové řádky.');
}
$koren = dirname(__DIR__);
$verze = $argv[1] ?? '';
$volby = ['url' => '', 'zmeny' => [], 'bezpecnostni' => false, 'klic' => 'provozni'];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $volby['url'] = substr($arg, 6);
    } elseif ($arg === '--bezpecnostni') {
        $volby['bezpecnostni'] = true;
    } elseif (str_starts_with($arg, '--zmena=')) {
        $volby['zmeny'][] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--klic=')) {
        $volby['klic'] = substr($arg, 7);
    }
}
if (str_starts_with($verze, '--novy-klic=')) {
    // nový pár klíčů: soukromý do tools/klice/ (nikdy do gitu), veřejný se PŘIPÍŠE do system/aktualizace.pub
    require_once $koren . '/system/src/Core/Podpis.php';
    $jmeno = substr($verze, 12);
    $cil = ['provozni' => $koren . '/tools/klice/vydavatel.key', 'zalozni' => $koren . '/tools/klice/zalozni.key'][$jmeno] ?? exit("Použití: --novy-klic=provozni nebo --novy-klic=zalozni\n");
    if (is_file($cil)) {
        exit("Soubor {$cil} už existuje. Při výměně klíče ho nejdřív přesuňte do archivu - nikdy ho nepřepisujte naslepo.\n");
    }
    @mkdir(dirname($cil), 0700, true);
    $par = sodium_crypto_sign_keypair();
    file_put_contents($cil, base64_encode(sodium_crypto_sign_secretkey($par)) . "\n");
    chmod($cil, 0600);
    $pk = sodium_crypto_sign_publickey($par);
    $pub = $koren . '/system/aktualizace.pub';
    file_put_contents($pub, rtrim((string) @file_get_contents($pub)) . "\n" . base64_encode($pk) . ' ' . $jmeno . ' ' . date('Y-m-d') . ' id=' . Kaleta\Core\Podpis::id($pk) . "\n");
    file_put_contents($pub, ltrim((string) file_get_contents($pub)));
    exit("Nový klíč „{$jmeno}“ (id " . Kaleta\Core\Podpis::id($pk) . ") je v {$cil}.\n"
        . "1) Soukromý soubor si HNED zazálohujte mimo tento počítač" . ($jmeno === 'zalozni' ? " a z disku ho pak smažte - záložní klíč má ležet offline" : '') . ".\n"
        . "2) system/aktualizace.pub commitněte; instalace nový klíč poznají až po vydání, které ho přinese (podepsaném klíčem, který už znají).\n");
}
if (!preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $verze)) {
    exit("Použití: php tools/vydani.php <verze> --url=<adresa ZIPu> [--zmena=\"...\"]\n");
}
if (!str_contains((string) file_get_contents($koren . '/system/bootstrap.php'), "const KALETA_VERSION = '{$verze}';")) {
    exit("V system/bootstrap.php není KALETA_VERSION = '{$verze}'. Nejprve zvyšte verzi a změnu commitněte.\n");
}

// --- klíče: system/aktualizace.pub nese víc veřejných klíčů (provozní + záložní), podpis platí vůči kterémukoli - viz docs/VYDAVANI.md
require_once $koren . '/system/src/Core/Podpis.php';
$verejny = $koren . '/system/aktualizace.pub';
$soubory_klicu = ['provozni' => $koren . '/tools/klice/vydavatel.key', 'zalozni' => $koren . '/tools/klice/zalozni.key'];
$soukromy = $soubory_klicu[$volby['klic']] ?? exit("Neznámý klíč „{$volby['klic']}“ - použijte --klic=provozni nebo --klic=zalozni.\n");
$sk = base64_decode(trim(getenv('KALETA_KLIC') !== false ? (string) getenv('KALETA_KLIC') : (string) @file_get_contents($soukromy)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    exit("Soukromý klíč {$soukromy} chybí nebo je poškozený. Nový pár založíte příkazem: php tools/vydani.php --novy-klic={$volby['klic']}\n");
}
$idKlice = Kaleta\Core\Podpis::id(sodium_crypto_sign_publickey_from_secretkey($sk));
if (!isset(Kaleta\Core\Podpis::klice($verejny)[$idKlice])) {
    exit("Klíč {$idKlice} není uveden v system/aktualizace.pub - instalace by jeho podpis odmítly.\n");
}

// --- balíček ze souborů sledovaných gitem
$soubory = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($koren) . ' && git ls-files')));
$vynechat = ['tools/', 'docs/', '.github/', '.claude/', 'CLAUDE.md', '.gitignore', '.gitleaks.toml']; // kořenový CLAUDE.md je pro vývoj; layout/CLAUDE.md (pravidla šablon) do balíčku patří
@mkdir($koren . '/dist');
$zipSoubor = $koren . "/dist/kaleta-{$verze}.zip";
@unlink($zipSoubor);
$zip = new ZipArchive();
$zip->open($zipSoubor, ZipArchive::CREATE);
$otisky = [];
foreach ($soubory as $soubor) {
    foreach ($vynechat as $v) {
        if ($soubor === $v || str_starts_with($soubor, $v)) {
            continue 2;
        }
    }
    $zip->addFile($koren . '/' . $soubor, $soubor);
    // seznam souborů jádra s otisky: instalace podle něj pozná změněné, chybějící a přidané soubory (Core\Integrita)
    // bez uživatelských složek a bez install.php (aktualizace ho nepřepisuje a správce ho po instalaci může smazat)
    if (!preg_match('#^(media|storage)/|^install\.php$#', $soubor)) {
        $otisky[$soubor] = hash_file('sha256', $koren . '/' . $soubor);
    }
}
require_once $koren . '/system/src/Core/Integrita.php';
ksort($otisky);
$zip->addFromString('system/soubory.json', json_encode([
    'verze' => $verze, 'soubory' => $otisky,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrita::kPodpisu($verze, $otisky), $sk)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$zip->close();

$sha = hash_file('sha256', $zipSoubor);
$manifest = [
    'verze' => $verze, 'vydano' => date('Y-m-d'), 'url' => $volby['url'], 'sha256' => $sha,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Podpis::zpravaBalicku($verze, $sha, $volby['bezpecnostni']), $sk)),
    'klic' => $idKlice, // jen pro přehled, kterým klíčem se podepisovalo; instalace zkouší všechny klíče, které znají
    'min_php' => '8.4', 'bezpecnostni' => $volby['bezpecnostni'], 'zmeny' => $volby['zmeny'],
];
file_put_contents($koren . '/dist/aktualizace.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Hotovo: dist/kaleta-{$verze}.zip (" . round(filesize($zipSoubor) / 1024) . " kB) a dist/aktualizace.json\n";
echo $volby['url'] === '' ? "POZOR: nezadali jste --url, doplňte adresu ZIPu do dist/aktualizace.json PŘED podpisem (spusťte znovu s --url).\n" : "1) ZIP nahrajte na {$volby['url']}\n2) aktualizace.json nahrajte na web projektu.\n";
