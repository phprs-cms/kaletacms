<?php
/**
 * Ověří kanál aktualizací tak, jak ho vidí instalace MiroCMS: co právě visí na webu projektu, musí být podepsané
 * klíčem z repozitáře a balíček ke stažení musí odpovídat podepsanému otisku. Běží denně v GitHub Actions
 * (.github/workflows/denni-kontrola.yml) - odhalí podvržený nebo poškozený soubor dřív, než ho potkají weby uživatelů.
 *
 *   php tools/over-kanal.php [adresa aktualizace.json]
 *
 * Nic nepodepisuje a žádný soukromý klíč nepotřebuje.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use MiroCMS\Core\Podpis;

$adresa = $argv[1] ?? 'https://mirocms.eu/aktualizace.json';
$klice = dirname(__DIR__) . '/system/aktualizace.pub';
$chyby = [];
$stahni = static function (string $url): string {
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: MiroCMS-kontrola\r\n"]]));
    if ($data === false) {
        throw new RuntimeException("nelze stáhnout $url");
    }

    return $data;
};

try {
    $m = json_decode($stahni($adresa), true, 16, JSON_THROW_ON_ERROR);
    foreach (['verze', 'url', 'sha256', 'podpis'] as $klic) {
        if (!is_string($m[$klic] ?? null) || $m[$klic] === '') {
            throw new RuntimeException("v souboru chybí položka $klic");
        }
    }
    echo "Nabízená verze: {$m['verze']}" . (!empty($m['bezpecnostni']) ? ' (bezpečnostní)' : '') . "\n";
    if (!str_starts_with($m['url'], 'https://')) {
        $chyby[] = 'adresa balíčku není https';
    }
    if (!Podpis::plati(Podpis::zpravaBalicku($m['verze'], strtolower($m['sha256']), !empty($m['bezpecnostni'])), $m['podpis'], $klice)) {
        $chyby[] = 'podpis souboru aktualizace.json NEPLATÍ pro žádný klíč v system/aktualizace.pub';
    }
    $zip = tempnam(sys_get_temp_dir(), 'mirocms');
    file_put_contents($zip, $stahni($m['url']));
    if (!hash_equals(strtolower($m['sha256']), hash_file('sha256', $zip))) {
        $chyby[] = 'otisk staženého balíčku neodpovídá podepsanému otisku';
    }
    // balíček nesmí instalacím podstrčit jiné veřejné klíče, než jaké jsou v repozitáři
    $archiv = new ZipArchive();
    if ($archiv->open($zip) === true) {
        $vBalicku = $archiv->getFromName('system/aktualizace.pub');
        $ocisti = static fn (string $s): array => array_values(array_filter(array_map(static fn (string $r): string => trim(explode(' ', trim($r))[0]), explode("\n", $s)), static fn (string $r): bool => $r !== '' && $r[0] !== '#'));
        if ($vBalicku === false) {
            $chyby[] = 'balíček neobsahuje system/aktualizace.pub';
        } elseif (array_diff($ocisti($vBalicku), $ocisti((string) file_get_contents($klice))) !== []) {
            $chyby[] = 'balíček obsahuje veřejný klíč, který v repozitáři není';
        }
        $archiv->close();
    } else {
        $chyby[] = 'balíček nejde otevřít jako ZIP';
    }
    @unlink($zip);
} catch (Throwable $e) {
    $chyby[] = $e->getMessage();
}

if ($chyby !== []) {
    fwrite(STDERR, "KANÁL AKTUALIZACÍ NENÍ V POŘÁDKU:\n- " . implode("\n- ", $chyby) . "\n");
    exit(1);
}
echo "Kanál aktualizací je v pořádku: podpis platí, balíček odpovídá otisku, klíče sedí.\n";
