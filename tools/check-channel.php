<?php
/**
 * Verifies the update channel the way a Kaleta installation sees it: whatever is currently on the project website must be
 * signed with a key from the repository and the download package must match the signed hash. Runs daily in GitHub Actions
 * (.github/workflows/denni-kontrola.yml) - catches a forged or damaged file before users' sites run into it.
 *
 *   php tools/check-channel.php [aktualizace.json url] [--stable=<aktualizace-stable.json url>]
 *
 * 3.8 (D3): the stable channel is checked too – aktualizace-stable.json next to aktualizace.json (Core\Updater::stableUrl),
 * or the address given with --stable. While no stable manifest is published (the address answers 404), only the latest
 * channel is checked. A stable manifest must say "kanal": "stable" and must not offer a newer version than the latest one;
 * the latest manifest must not say "stable".
 *
 * 3.9: manifest signature v2 ("podpis2", over every field – Core\Signature::manifestMessage) must hold when it is there,
 * and must be there on the stable manifest and on any manifest of 3.9.0 or later (sites from 3.9 on refuse them without it).
 * A latest manifest of an older release without it passes on its v1 signature, as before.
 *
 * Signs nothing and needs no private key.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Signature;
use Kaleta\Core\Updater;

$arguments = array_slice($argv, 1);
$stableArgument = null;
foreach ($arguments as $i => $argument) {
    if (str_starts_with($argument, '--stable=')) {
        $stableArgument = substr($argument, 9);
        unset($arguments[$i]);
    }
}
$manifestUrl = array_values($arguments)[0] ?? Updater::DEFAULT_URL;
$stableUrl = $stableArgument ?? Updater::stableUrl($manifestUrl);
$keys = dirname(__DIR__) . '/system/aktualizace.pub';
$local = str_starts_with($manifestUrl, 'http://127.0.0.1:'); // a test channel on this computer (tools/test.sh) may serve its packages over http
$errors = [];
/** @return array{0: int, 1: string} status and body (status 0 = no answer) */
$fetch = static function (string $url): array {
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true, 'header' => "User-Agent: Kaleta-kontrola\r\n"]]));
    $status = 0;
    foreach (last_response_headers($http_response_header ?? null) as $line) { // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $s)) {
            $status = (int) $s[1]; // the last one wins (after redirects)
        }
    }

    return [$status, $data === false ? '' : $data];
};
$download = static function (string $url) use ($fetch): string {
    [$status, $data] = $fetch($url);
    if ($status !== 200) {
        throw new RuntimeException("nelze stáhnout $url" . ($status > 0 ? " (HTTP $status)" : ''));
    }

    return $data;
};

/**
 * One manifest: the signature, the package behind it and the keys inside the package.
 *
 * @return array<string, mixed> the manifest
 */
$check = static function (string $json, string $label, bool $stableManifest = false) use ($download, $keys, $local, &$errors): array {
    $m = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($m)) {
        throw new RuntimeException("$label: soubor není objekt JSON");
    }
    foreach (['verze', 'url', 'sha256', 'podpis'] as $key) {
        if (!is_string($m[$key] ?? null) || $m[$key] === '') {
            throw new RuntimeException("$label: v souboru chybí položka $key");
        }
    }
    echo "$label – nabízená verze: {$m['verze']}" . (!empty($m['bezpecnostni']) ? ' (bezpečnostní)' : '') . "\n";
    if (!str_starts_with($m['url'], 'https://') && !($local && str_starts_with($m['url'], 'http://127.0.0.1:'))) {
        $errors[] = "$label: adresa balíčku není https";
    }
    if (!Signature::isValid(Signature::packageMessage($m['verze'], strtolower($m['sha256']), !empty($m['bezpecnostni'])), $m['podpis'], $keys)) {
        $errors[] = "$label: podpis NEPLATÍ pro žádný klíč v system/aktualizace.pub";
    }
    // 3.9: signature v2 over every field (the channel and min_php too) – what installations from 3.9 on check (Core\Updater::verified)
    if (array_key_exists('podpis2', $m)) {
        if (!Signature::manifestValid($m, $keys)) {
            $errors[] = "$label: podpis v2 (podpis2) NEPLATÍ – weby od 3.9 manifest odmítnou (upravený po podpisu, nebo klíč z „klic“ není v system/aktualizace.pub)";
        }
    } elseif ($stableManifest || version_compare($m['verze'], Updater::SIGNED_V2_SINCE, '>=')) {
        $errors[] = "$label: chybí podpis v2 (podpis2) – weby od 3.9 manifest odmítnou; podepište ho znovu nástrojem tools/release.php z větve main";
    } else {
        echo "$label: bez podpisu v2 – vydání před " . Updater::SIGNED_V2_SINCE . ", platí podpis v1 (tak ho čtou weby do 3.8)\n";
    }
    $zip = (string) tempnam(sys_get_temp_dir(), 'kaleta');
    file_put_contents($zip, $download($m['url']));
    if (!hash_equals(strtolower($m['sha256']), (string) hash_file('sha256', $zip))) {
        $errors[] = "$label: otisk staženého balíčku neodpovídá podepsanému otisku";
    }
    // the package must not slip installations public keys other than those in the repository
    $archive = new ZipArchive();
    if ($archive->open($zip) === true) {
        $inPackage = $archive->getFromName('system/aktualizace.pub');
        $clean = static fn (string $s): array => array_values(array_filter(array_map(static fn (string $r): string => trim(explode(' ', trim($r))[0]), explode("\n", $s)), static fn (string $r): bool => $r !== '' && $r[0] !== '#'));
        if ($inPackage === false) {
            $errors[] = "$label: balíček neobsahuje system/aktualizace.pub";
        } elseif (array_diff($clean($inPackage), $clean((string) file_get_contents($keys))) !== []) {
            $errors[] = "$label: balíček obsahuje veřejný klíč, který v repozitáři není";
        }
        $archive->close();
    } else {
        $errors[] = "$label: balíček nejde otevřít jako ZIP";
    }
    @unlink($zip);

    return $m;
};

$latest = null;
try {
    $latest = $check($download($manifestUrl), 'aktualizace.json');
    if (($latest['kanal'] ?? 'latest') !== 'latest') {
        $errors[] = 'aktualizace.json: manifest říká "kanal": "' . (is_scalar($latest['kanal']) ? (string) $latest['kanal'] : '?') . '" – na kanálu Latest smí být jen "latest" (nebo nic)';
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
if ($stableUrl !== null) {
    try {
        [$status, $body] = $fetch($stableUrl);
        if ($status === 404) {
            echo "aktualizace-stable.json: stabilní kanál zatím není zveřejněný ($stableUrl odpovídá 404) – kontroluje se jen Latest.\n";
        } elseif ($status !== 200) {
            throw new RuntimeException("nelze stáhnout $stableUrl" . ($status > 0 ? " (HTTP $status)" : ''));
        } else {
            $stable = $check($body, 'aktualizace-stable.json', true);
            if (($stable['kanal'] ?? null) !== 'stable') {
                $errors[] = 'aktualizace-stable.json: manifest neříká "kanal": "stable" – weby na stabilním kanálu by nic nedostaly (přesměrování ukazuje na jiný soubor?)';
            }
            if (is_array($latest) && version_compare((string) $stable['verze'], (string) $latest['verze'], '>')) {
                $errors[] = "aktualizace-stable.json: stabilní kanál ({$stable['verze']}) je napřed před Latest ({$latest['verze']}) – soubory jsou nejspíš prohozené";
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    fwrite(STDERR, "KANÁL AKTUALIZACÍ NENÍ V POŘÁDKU:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "Kanál aktualizací je v pořádku: podpis platí, balíček odpovídá otisku, klíče sedí.\n";
