<?php

declare(strict_types=1);

namespace Kaleta\Front;

/**
 * Přehled layoutů (šablon vzhledu webu) ve složce layout/.
 * Layout se může představit souborem info.php: return ['nazev' => ..., 'popis' => ...];
 */
final class Layouty
{
    /** Šablona nové instalace a náhrada, když nastavená šablona ve složce layout/ chybí. */
    public const string VYCHOZI = 'zakladni';

    /** @return array<string, array{nazev:string, popis:string}> složka => informace, výchozí layout první */
    public static function seznam(): array
    {
        $layouty = [];
        foreach (glob(KALETA_ROOT . '/layout/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slozka = basename($dir);
            if (!preg_match('/^[a-z0-9_-]+$/i', $slozka) || !is_file($dir . '/base.php')) {
                continue;
            }
            $info = is_file($dir . '/info.php') ? (array) require $dir . '/info.php' : [];
            $layouty[$slozka] = ['nazev' => (string) ($info['nazev'] ?? $slozka), 'popis' => (string) ($info['popis'] ?? '')];
        }
        uksort($layouty, fn (string $a, string $b): int => [$a !== self::VYCHOZI, $a] <=> [$b !== self::VYCHOZI, $b]);

        return $layouty;
    }
}
