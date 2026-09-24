<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Aktualizace struktury databáze.
 *
 * Soubory system/sql/migrace/NNNN-popis.sql se provedou vzestupně, číslo poslední provedené
 * je v ka_nastaveni (verze_db). Nová instalace dostane rovnou úplné schema.sql a nejvyšší číslo.
 */
final class Migrace
{
    private const string SLOZKA = KALETA_SYSTEM . '/sql/migrace';

    /** Chyby MySQL, které znamenají „tahle změna už v databázi je“: tabulka, sloupec, index, cizí klíč existuje, rušený sloupec či index chybí. */
    private const array UZ_PROVEDENO = [1050, 1060, 1061, 1022, 1826, 1091];

    /** @return array<int, string> číslo => soubor, vzestupně */
    public static function soubory(): array
    {
        $soubory = [];
        foreach (glob(self::SLOZKA . '/[0-9][0-9][0-9][0-9]-*.sql') ?: [] as $soubor) {
            $soubory[(int) substr(basename($soubor), 0, 4)] = $soubor;
        }
        ksort($soubory);

        return $soubory;
    }

    public static function posledni(): int
    {
        return max(1, ...array_keys(self::soubory()));
    }

    /** @return list<string> názvy právě provedených migrací */
    public static function proved(Db $db, Settings $settings): array
    {
        // zámek: migrace spouští administrace i web, dva souběžné požadavky nesmí tutéž změnu provést dvakrát
        $zamek = 'kaleta_migrace_' . $db->prefix;
        if ((int) $db->value('SELECT GET_LOCK(?, 15)', [$zamek]) !== 1) {
            return [];
        }
        $provedene = [];
        try {
            $verze = max(1, (int) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'verze_db'"));
            foreach (self::soubory() as $cislo => $soubor) {
                if ($cislo <= $verze) {
                    continue;
                }
                foreach (self::prikazy((string) file_get_contents($soubor), $db->prefix) as $sql) {
                    try {
                        $db->pdo()->exec($sql);
                    } catch (\PDOException $e) {
                        // migrace přerušená uprostřed (výpadek, časový limit) se při dalším pokusu dokončí: změny, které už
                        // proběhly (tabulka, sloupec, index či cizí klíč existuje / chybí), se přeskočí místo chyby 500 napořád
                        if (!in_array((int) ($e->errorInfo[1] ?? 0), self::UZ_PROVEDENO, true)) {
                            throw $e;
                        }
                    }
                }
                $settings->set('verze_db', (string) $cislo);
                $provedene[] = basename($soubor, '.sql');
            }
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$zamek]);
        }

        return $provedene;
    }

    /**
     * Rozdělí SQL skript na příkazy a nahradí předponu "ka_" předponou instalace.
     *
     * @return list<string>
     */
    public static function prikazy(string $sql, string $prefix): array
    {
        // názvy omezení musí být v databázi jedinečné - dostanou předponu také
        $sql = preg_replace('/\b((?:CONSTRAINT|DROP FOREIGN KEY)\s+)fk_/', '$1' . $prefix . 'fk_', $sql) ?? $sql;
        $sql = preg_replace('/\bka_(?=[a-z])/', $prefix, $sql) ?? $sql;
        // příkaz končí středníkem na konci řádku; za středníkem smí být už jen komentář
        $prikazy = preg_split('/;[ \t]*(--[^\n]*)?(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $prikazy), function (string $prikaz): bool {
            return trim((string) preg_replace('/^\s*--.*$/m', '', $prikaz)) !== '';
        }));
    }
}
