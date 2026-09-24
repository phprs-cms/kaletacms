<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Neporušenost jádra: balíček vydání nese podepsaný seznam souborů s otisky (system/soubory.json).
 * Stav systému podle něj pozná soubory jádra, které někdo změnil, smazal nebo přidal. Úpravy jádra se
 * nepodporují - vlastní je jen layout/<vlastní šablona>/, media/, storage/ a config.php; aktualizace
 * vrátí jádro do původní podoby.
 */
final class Integrita
{
    private const string SEZNAM = KALETA_SYSTEM . '/soubory.json';

    /** @return array{stav:string, info:string, zmenene:list<string>, chybi:list<string>, navic:list<string>} */
    public static function kontrola(string $klicSoubor = KALETA_SYSTEM . '/aktualizace.pub'): array
    {
        $prazdne = ['zmenene' => [], 'chybi' => [], 'navic' => []];
        if (!is_file(self::SEZNAM)) {
            return ['stav' => 'ok', 'info' => t('vývojová verze bez seznamu souborů – kontrola se týká jen vydaných balíčků')] + $prazdne;
        }
        $data = json_decode((string) file_get_contents(self::SEZNAM), true);
        $soubory = is_array($data['soubory'] ?? null) ? $data['soubory'] : null;
        if ($soubory === null || !Podpis::plati(self::kPodpisu((string) ($data['verze'] ?? ''), $soubory), (string) ($data['podpis'] ?? ''), $klicSoubor)) {
            return ['stav' => 'chyba', 'info' => t('seznam souborů jádra (system/soubory.json) je poškozený nebo nemá platný podpis vydavatele')] + $prazdne;
        }
        $zmenene = $chybi = [];
        foreach ($soubory as $cesta => $otisk) {
            $soubor = KALETA_ROOT . '/' . $cesta;
            if (!is_file($soubor)) {
                $chybi[] = $cesta;
            } elseif (!hash_equals((string) $otisk, hash_file('sha256', $soubor))) {
                $zmenene[] = $cesta;
            }
        }
        // soubory PHP, které do jádra nepatří (kořen webu a system/) - typická stopa po napadení webu
        $navic = [];
        $kandidati = glob(KALETA_ROOT . '/*.php') ?: [];
        $strom = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_SYSTEM, \FilesystemIterator::SKIP_DOTS));
        foreach ($strom as $f) {
            if ($f->isFile() && preg_match('/\.(php\d?|phtml|phar)$/i', $f->getFilename())) {
                $kandidati[] = $f->getPathname();
            }
        }
        foreach ($kandidati as $soubor) {
            $cesta = ltrim(str_replace('\\', '/', substr($soubor, strlen(KALETA_ROOT))), '/');
            if (!isset($soubory[$cesta]) && !in_array($cesta, ['config.php', 'install.php'], true)) {
                $navic[] = $cesta;
            }
        }
        $pocet = count($zmenene) + count($chybi) + count($navic);

        return [
            'stav' => $pocet === 0 ? 'ok' : 'varovani',
            'info' => $pocet === 0 ? t('všech %d souborů jádra odpovídá vydání %s', count($soubory), (string) ($data['verze'] ?? ''))
                : t('jádro se liší od vydání: změněno %d, chybí %d, navíc %d – %s', count($zmenene), count($chybi), count($navic), implode(', ', array_slice([...$zmenene, ...$chybi, ...$navic], 0, 6)) . ($pocet > 6 ? '…' : ''))
                    . '. ' . t('Úpravy jádra se nepodporují; do původní podoby je vrátí aktualizace (Zálohy a aktualizace).'),
            'zmenene' => $zmenene, 'chybi' => $chybi, 'navic' => $navic,
        ];
    }

    /**
     * Text, který vydavatel podepisuje (tools/vydani.php) a instalace ověřuje.
     *
     * @param array<string, string> $soubory cesta => sha256
     */
    public static function kPodpisu(string $verze, array $soubory): string
    {
        ksort($soubory);

        return 'kaleta-soubory|' . $verze . '|' . hash('sha256', (string) json_encode($soubory, JSON_UNESCAPED_SLASHES));
    }
}
