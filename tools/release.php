<?php
/**
 * Kaleta - release preparation (run by the publisher on their own computer, not uploaded to the web).
 *
 *   php tools/release.php 1.0.1 --url=https://github.com/phprs-cms/kaletacms/releases/download/v1.0.1/kaleta-1.0.1.zip \
 *       --zmena="Oprava ..." --zmena="Nové ..." [--bezpecnostni]
 *
 * --bezpecnostni marks the release as a security fix: installations update to it by themselves and the administrator gets an e-mail.
 * --channel=stable (3.8, D3) writes dist/aktualizace-stable.json instead of dist/aktualizace.json: the manifest of the stable
 * channel (same key, same format, "kanal": "stable"). With --package=dist/kaleta-X.Y.Z.zip the package already built and
 * uploaded for the latest channel is signed for the stable channel as it is (a promotion) instead of building a new one.
 *   php tools/release.php 3.8.4 --channel=stable --package=dist/kaleta-3.8.4.zip --url=…/v3.8.4/kaleta-3.8.4.zip --zmena="…"
 * Instead of a file, the private key can be passed in the KALETA_KLIC environment variable (base64) - for releasing from GitHub Actions.
 *
 * Creates dist/kaleta-<version>.zip (files tracked by git) and dist/aktualizace.json signed with the private key – with both
 * manifest signatures (3.9): v1 for the installed versions up to 3.8, v2 over every field for 3.9 and later. Never edit
 * the manifest by hand afterwards (not even the URL or a change line): v2 would no longer hold – run this again instead.
 * Keys: tools/klice/vydavatel.key (primary) and tools/klice/zalozni.key (backup, should be kept offline) are PRIVATE - never into git.
 * system/aktualizace.pub carries the public keys (one per line), it is part of the system. Key rotation and revocation: docs/RELEASING.md.
 *   php tools/release.php --novy-klic=zalozni      creates a key pair and appends the public one to system/aktualizace.pub
 *   php tools/release.php 1.0.1 --klic=zalozni …   signs the release with the backup key (the primary one lost or leaked)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Jen z příkazové řádky.');
}
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$options = ['url' => '', 'zmeny' => [], 'bezpecnostni' => false, 'klic' => 'provozni', 'channel' => 'latest', 'package' => ''];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $options['url'] = substr($arg, 6);
    } elseif ($arg === '--bezpecnostni') {
        $options['bezpecnostni'] = true;
    } elseif (str_starts_with($arg, '--zmena=')) {
        $options['zmeny'][] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--klic=')) {
        $options['klic'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--channel=')) {
        $options['channel'] = substr($arg, 10);
    } elseif (str_starts_with($arg, '--package=')) {
        $options['package'] = substr($arg, 10);
    }
}
if (str_starts_with($version, '--novy-klic=')) {
    // a new key pair: the private one into tools/klice/ (never into git), the public one is APPENDED to system/aktualizace.pub
    require_once $root . '/system/src/Core/Signature.php';
    $name = substr($version, 12);
    $target = ['provozni' => $root . '/tools/klice/vydavatel.key', 'zalozni' => $root . '/tools/klice/zalozni.key'][$name] ?? exit("Použití: --novy-klic=provozni nebo --novy-klic=zalozni\n");
    if (is_file($target)) {
        exit("Soubor {$target} už existuje. Při výměně klíče ho nejdřív přesuňte do archivu - nikdy ho nepřepisujte naslepo.\n");
    }
    @mkdir(dirname($target), 0700, true);
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($target, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    chmod($target, 0600);
    $pk = sodium_crypto_sign_publickey($pair);
    $pub = $root . '/system/aktualizace.pub';
    file_put_contents($pub, rtrim((string) @file_get_contents($pub)) . "\n" . base64_encode($pk) . ' ' . $name . ' ' . date('Y-m-d') . ' id=' . Kaleta\Core\Signature::id($pk) . "\n");
    file_put_contents($pub, ltrim((string) file_get_contents($pub)));
    exit("Nový klíč „{$name}“ (id " . Kaleta\Core\Signature::id($pk) . ") je v {$target}.\n"
        . "1) Soukromý soubor si HNED zazálohujte mimo tento počítač" . ($name === 'zalozni' ? " a z disku ho pak smažte - záložní klíč má ležet offline" : '') . ".\n"
        . "2) system/aktualizace.pub commitněte; instalace nový klíč poznají až po vydání, které ho přinese (podepsaném klíčem, který už znají).\n");
}
if (!preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
    exit("Použití: php tools/release.php <verze> --url=<adresa ZIPu> [--zmena=\"...\"]\n");
}
if (!in_array($options['channel'], ['latest', 'stable'], true)) {
    exit("--channel must be latest or stable.\n");
}
// the files of the release: the working tree, or (--package) the package that was already built – read from inside the ZIP
$releaseFile = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);
if ($options['package'] !== '') {
    $packageZip = new ZipArchive();
    if (!is_file($options['package']) || $packageZip->open($options['package']) !== true) {
        exit("The package {$options['package']} cannot be opened.\n");
    }
    $releaseFile = static fn (string $path): string => (string) $packageZip->getFromName($path);
}
if (!str_contains($releaseFile('system/bootstrap.php'), "const KALETA_VERSION = '{$version}';")) {
    exit($options['package'] !== '' ? "The package {$options['package']} is not version {$version}.\n" : "V system/bootstrap.php není KALETA_VERSION = '{$version}'. Nejprve zvyšte verzi a změnu commitněte.\n");
}
// the oldest PHP the release runs on: sites on an older one are not offered it (Core\Updater::state) and refuse to install it
if (!preg_match("/const KALETA_MIN_PHP = '(\\d+\\.\\d+)';/", $releaseFile('system/bootstrap.php'), $minPhp)) {
    exit("V system/bootstrap.php chybí KALETA_MIN_PHP.\n");
}
// a release on the stable channel must know the channel itself (3.8+): a site that installed one without it would read the
// latest manifest again and leave the stable channel without noticing
if ($options['channel'] === 'stable' && !str_contains($releaseFile('system/src/Core/Updater.php'), "STABLE_FILE = 'aktualizace-stable.json'")) {
    exit("Version {$version} does not know the stable channel (Kaleta 3.8 and later do) – it cannot be published on it.\n");
}

// --- keys: system/aktualizace.pub carries several public keys (primary + backup), a signature is valid against any of them - see docs/RELEASING.md
require_once $root . '/system/src/Core/Signature.php';
$publicKeyFile = $root . '/system/aktualizace.pub';
$keyFiles = ['provozni' => $root . '/tools/klice/vydavatel.key', 'zalozni' => $root . '/tools/klice/zalozni.key'];
$privateKeyFile = $keyFiles[$options['klic']] ?? exit("Neznámý klíč „{$options['klic']}“ - použijte --klic=provozni nebo --klic=zalozni.\n");
$sk = base64_decode(trim(getenv('KALETA_KLIC') !== false ? (string) getenv('KALETA_KLIC') : (string) @file_get_contents($privateKeyFile)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    exit("Soukromý klíč {$privateKeyFile} chybí nebo je poškozený. Nový pár založíte příkazem: php tools/release.php --novy-klic={$options['klic']}\n");
}
$keyId = Kaleta\Core\Signature::id(sodium_crypto_sign_publickey_from_secretkey($sk));
if (!isset(Kaleta\Core\Signature::keys($publicKeyFile)[$keyId])) {
    exit("Klíč {$keyId} není uveden v system/aktualizace.pub - instalace by jeho podpis odmítly.\n");
}

if ($options['package'] === '') {
    // --- package from the files tracked by git
    $files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files')));
    $exclude = ['tools/', 'docs/', 'integrations/', '.github/', '.claude/', 'CLAUDE.md', '.gitignore', '.gitleaks.toml', '.git-blame-ignore-revs', 'phpstan.neon.dist', 'phpstan-baseline.neon', 'docker/', 'Dockerfile', 'compose.yaml', '.dockerignore']; // the root CLAUDE.md is for development; layout/CLAUDE.md (layout rules) belongs in the package
    @mkdir($root . '/dist');
    $zipFile = $root . "/dist/kaleta-{$version}.zip";
    @unlink($zipFile);
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $hashes = [];
    foreach ($files as $file) {
        foreach ($exclude as $v) {
            if ($file === $v || str_starts_with($file, $v)) {
                continue 2;
            }
        }
        $zip->addFile($root . '/' . $file, $file);
        // the list of core files with hashes: by it an installation recognizes changed, missing and added files (Core\Integrity)
        // without user folders and without install.php (an update does not overwrite it and the administrator may delete it after installation)
        if (!preg_match('#^(media|storage)/|^install\.php$#', $file)) {
            $hashes[$file] = hash_file('sha256', $root . '/' . $file);
        }
    }
    // classes of older releases that this one renamed or removed ride along unchanged: an older release installs this package
    // and, in the same request, may still load its own classes after its cleanup. Every earlier release counts, not only the
    // previous one – a site may skip releases (1.3 straight to 1.4.1). Each file comes from the newest release that had it.
    // The new version deletes them on the first admin load (Updater::cleanUpRemoved, 'legacy' list).
    $legacy = [];
    $git = fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
    foreach (array_filter(explode("\n", $git('tag --sort=-v:refname --merged HEAD^ "v*"'))) as $release) {
        // system/class-aliases.php (1.4–2.0): the autoloader of 1.x requires it on a class it cannot find
        foreach (array_filter(explode("\n", $git('ls-tree -r --name-only ' . escapeshellarg($release) . ' -- system/src/ system/class-aliases.php'))) as $old) {
            if (!in_array($old, $files, true) && !isset($legacy[$old])) {
                $content = $git('show ' . escapeshellarg($release . ':' . $old));
                $zip->addFromString($old, $content);
                $legacy[$old] = hash('sha256', $content);
            }
        }
    }
    require_once $root . '/system/src/Core/Integrity.php';
    ksort($hashes);
    $zip->addFromString('system/soubory.json', json_encode([
        'verze' => $version, 'soubory' => $hashes, 'legacy' => $legacy,
        'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrity::stringToSign($version, $hashes), $sk)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $zip->close();
} else {
    $zipFile = $options['package']; // a promotion: the package keeps its hash, only the manifest is new
}

$sha = hash_file('sha256', $zipFile);
// two signatures (3.9): "podpis" (v1: version|sha256|security flag – the only one installed versions up to 3.8 check) and
// "podpis2" (v2: every field below, the channel and min_php included – checked by 3.9 and later, Core\Signature::manifestMessage)
$manifest = Kaleta\Core\Signature::signManifest([
    'verze' => $version, 'vydano' => date('Y-m-d'), 'url' => $options['url'], 'sha256' => $sha,
    'podpis' => '', // filled in by signManifest
    'klic' => $keyId, // the key that signed it; signature v2 is checked against this key only
    'min_php' => $minPhp[1], 'bezpecnostni' => $options['bezpecnostni'], 'zmeny' => $options['zmeny'],
    'kanal' => $options['channel'], // 3.8: a site on the stable channel accepts only a manifest that says "stable" (Core\Updater::choose)
], $sk);
$manifestName = $options['channel'] === 'stable' ? 'aktualizace-stable.json' : 'aktualizace.json';
@mkdir($root . '/dist');
file_put_contents($root . '/dist/' . $manifestName, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Hotovo: " . ($options['package'] !== '' ? $options['package'] : "dist/kaleta-{$version}.zip") . " (" . round(filesize($zipFile) / 1024) . " kB) a dist/{$manifestName}\n";
if ($options['url'] === '') {
    echo "POZOR: nezadali jste --url, doplňte adresu ZIPu do dist/{$manifestName} PŘED podpisem (spusťte znovu s --url).\n";
} elseif ($options['channel'] === 'stable') {
    echo "1) The ZIP must be at {$options['url']} (its release on GitHub)\n2) gh release upload stable-channel dist/aktualizace-stable.json --clobber (docs/RELEASING.md, Release channels)\n";
} else {
    echo "1) ZIP nahrajte na {$options['url']}\n2) aktualizace.json nahrajte na web projektu.\n";
}
