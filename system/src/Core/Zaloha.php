<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Zálohy databáze do storage/zalohy/ (z webu nepřístupné). Bez mysqldump - funguje i na sdíleném hostingu.
 */
final class Zaloha
{
    public const string SLOZKA = KALETA_ROOT . '/storage/zalohy';
    private const int PONECHAT = 10;

    /** @return string název vytvořeného souboru */
    public static function vytvor(Db $db, string $duvod = 'rucni'): string
    {
        if (!is_dir(self::SLOZKA) && !mkdir(self::SLOZKA, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku storage/zalohy - zkontrolujte práva k zápisu.');
        }
        $gz = function_exists('gzopen');
        $soubor = 'kaleta-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/', '', $duvod) . '-' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
        $cesta = self::SLOZKA . '/' . $soubor;
        $f = $gz ? gzopen($cesta, 'wb6') : fopen($cesta, 'wb');
        $zapis = fn (string $s) => $gz ? gzwrite($f, $s) : fwrite($f, $s);

        $pdo = $db->pdo();
        $zapis("-- Kaleta " . KALETA_VERSION . " - záloha databáze " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tabulky = $db->run('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name', [addcslashes($db->prefix, '_%') . '%'])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tabulky as $tabulka) {
            // dočasná data se nezálohují
            $jenStruktura = in_array(substr($tabulka, strlen($db->prefix)), ['stat_navstevnici', 'kontrola_ip'], true);
            $create = $pdo->query('SHOW CREATE TABLE `' . $tabulka . '`')->fetch(\PDO::FETCH_NUM)[1];
            $zapis("DROP TABLE IF EXISTS `{$tabulka}`;\n{$create};\n\n");
            if ($jenStruktura) {
                continue;
            }
            $radky = $pdo->query('SELECT * FROM `' . $tabulka . '`', \PDO::FETCH_NUM);
            $davka = [];
            foreach ($radky as $radek) {
                $davka[] = '(' . implode(',', array_map(fn ($h): string => $h === null ? 'NULL' : (is_int($h) || is_float($h) ? (string) $h : $pdo->quote((string) $h)), $radek)) . ')';
                if (count($davka) >= 200) {
                    $zapis("INSERT INTO `{$tabulka}` VALUES\n" . implode(",\n", $davka) . ";\n");
                    $davka = [];
                }
            }
            if ($davka !== []) {
                $zapis("INSERT INTO `{$tabulka}` VALUES\n" . implode(",\n", $davka) . ";\n");
            }
            $zapis("\n");
        }
        $zapis("SET FOREIGN_KEY_CHECKS = 1;\n");
        $gz ? gzclose($f) : fclose($f);

        foreach (array_slice(self::seznam(), self::PONECHAT) as $stara) {
            @unlink(self::SLOZKA . '/' . $stara['soubor']);
        }

        return $soubor;
    }

    /**
     * Obnoví databázi ze zálohy vytvořené touto třídou. Příkaz v záloze vždy končí středníkem na konci řádku
     * (hodnoty zapisuje PDO::quote, konce řádků v textech jsou v nich zakódované), takže ji lze číst po řádcích
     * bez načtení celého souboru do paměti.
     *
     * @return int počet provedených příkazů
     * @throws \RuntimeException
     */
    public static function obnov(Db $db, string $soubor): int
    {
        $cesta = self::cesta($soubor);
        if ($cesta === null) {
            throw new \RuntimeException('Záloha neexistuje.');
        }
        $gz = str_ends_with($cesta, '.gz');
        if ($gz && !function_exists('gzopen')) {
            throw new \RuntimeException('Server neumí číst komprimované zálohy (chybí zlib).');
        }
        $f = $gz ? gzopen($cesta, 'rb') : fopen($cesta, 'rb');
        $prvni = (string) ($gz ? gzgets($f) : fgets($f));
        if (!str_starts_with($prvni, '-- Kaleta ')) {
            throw new \RuntimeException('Soubor není záloha vytvořená systémem Kaleta.');
        }
        @set_time_limit(300);
        $pdo = $db->pdo();
        $prikaz = '';
        $pocet = 0;
        while (($radek = $gz ? gzgets($f) : fgets($f)) !== false) {
            if ($prikaz === '' && (trim($radek) === '' || str_starts_with($radek, '--'))) {
                continue;
            }
            $prikaz .= $radek;
            if (str_ends_with(rtrim($radek), ';')) {
                // záloha smí obsahovat jen tabulky této instalace
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $prikaz, $m) && !str_starts_with($m[2], $db->prefix)) {
                    throw new \RuntimeException('Záloha obsahuje cizí tabulku ' . $m[2] . ' – obnova byla zastavena.');
                }
                $pdo->exec($prikaz);
                $prikaz = '';
                $pocet++;
            }
        }
        $gz ? gzclose($f) : fclose($f);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $pocet;
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> od nejnovější */
    public static function seznam(): array
    {
        $zalohy = [];
        foreach (glob(self::SLOZKA . '/kaleta-*.sql*') ?: [] as $cesta) {
            $zalohy[] = ['soubor' => basename($cesta), 'velikost' => (int) filesize($cesta), 'cas' => (int) filemtime($cesta)];
        }
        usort($zalohy, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $zalohy;
    }

    /** Cesta k existující záloze podle názvu z adresy; null = neplatný název. */
    public static function cesta(string $soubor): ?string
    {
        return preg_match('/^kaleta-[0-9a-z-]+\.sql(\.gz)?$/', $soubor) && is_file(self::SLOZKA . '/' . $soubor) ? self::SLOZKA . '/' . $soubor : null;
    }

    /** Automatická týdenní záloha - volá se při vstupu administrátora do administrace. */
    public static function automaticka(Db $db, Settings $settings): void
    {
        if (!$settings->bool('zalohy_auto')) {
            return;
        }
        $posledni = self::seznam()[0]['cas'] ?? 0;
        if (time() - $posledni > 7 * 86400) {
            try {
                $soubor = self::vytvor($db, 'auto');
                VzdalenaZaloha::nahraj($settings, (string) self::cesta($soubor)); // výsledek ukáže Stav systému a záložka Zálohy
            } catch (\Throwable) {
                // záloha nesmí shodit administraci; na chybějící zálohu upozorní Stav systému
            }
        }
    }
}
