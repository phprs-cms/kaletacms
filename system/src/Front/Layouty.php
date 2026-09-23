<?php

declare(strict_types=1);

namespace MiroCMS\Front;

/**
 * Přehled layoutů (šablon vzhledu webu) ve složce layout/.
 * Layout se může představit souborem info.php: return ['nazev' => ..., 'popis' => ..., 'rozvrzeni' => ...];
 * "rozvrzeni" je rozvržení stránky, které layoutu nejvíc sluší (tri | dva | jeden | plna) - nastaví se při jeho výběru.
 */
final class Layouty
{
    /** Šablona nové instalace a náhrada, když nastavená šablona ve složce layout/ chybí. */
    public const string VYCHOZI = 'classic-newspaper';

    /** @return array<string, array{nazev:string, popis:string, rozvrzeni:string}> složka => informace, výchozí layout první */
    public static function seznam(): array
    {
        $layouty = [];
        foreach (glob(MIROCMS_ROOT . '/layout/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slozka = basename($dir);
            if (!preg_match('/^[a-z0-9_-]+$/i', $slozka) || !is_file($dir . '/base.php')) {
                continue;
            }
            $info = is_file($dir . '/info.php') ? (array) require $dir . '/info.php' : [];
            $layouty[$slozka] = ['nazev' => (string) ($info['nazev'] ?? $slozka), 'popis' => (string) ($info['popis'] ?? ''),
                'rozvrzeni' => in_array($info['rozvrzeni'] ?? '', ['tri', 'dva', 'jeden', 'plna'], true) ? $info['rozvrzeni'] : 'tri',
            ];
        }
        uksort($layouty, fn (string $a, string $b): int => [$a !== self::VYCHOZI, $a] <=> [$b !== self::VYCHOZI, $b]);

        return $layouty;
    }
}
