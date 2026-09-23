<?php

declare(strict_types=1);

namespace MiroCMS\Admin;

/**
 * Cesty v nabídce jako odkazy. Hlášky, Stav systému a nápovědy u polí říkají „Nastavení → Zálohy a aktualizace“ -
 * místo aby uživatel cestu hledal v nabídce, stane se z ní odkaz. Texty zůstávají obyčejné věty (a obyčejné klíče
 * slovníku); odkaz doplní až vykreslení, v jazyce, ve kterém se text právě zobrazuje.
 */
final class Cesty
{
    /**
     * Známé cesty: části cesty tak, jak stojí v nabídce (česky = klíče slovníku) => [modul potřebný k přístupu, dotaz adresy].
     * Víceslovné cesty mají přednost před kratšími (řadí se podle délky).
     *
     * @var list<array{0: list<string>, 1: string, 2: string}>
     */
    private const array CESTY = [
        [['Nastavení', 'Zálohy a aktualizace'], 'config', 'modul=config&zalozka=zalohy'],
        [['Nastavení', 'Stav systému'], 'config', 'modul=config&zalozka=stav'],
        [['Nastavení', 'Soukromí a cookies'], 'config', 'modul=config&zalozka=cookies'],
        [['Nastavení', 'SEO a GEO'], 'config', 'modul=config&zalozka=seo'],
        [['Nastavení', 'Základní'], 'config', 'modul=config&zalozka=zakladni'],
        [['Nastavení', 'Čtenáři a platby'], 'config', 'modul=config&zalozka=ctenari'],
        [['Nastavení', 'Měření'], 'config', 'modul=config&zalozka=mereni'],
        [['Nastavení', 'Pošta'], 'config', 'modul=config&zalozka=posta'],
        [['Vzhled', 'Identita webu'], 'vzhled', 'modul=vzhled'],
        [['Zálohy a aktualizace'], 'config', 'modul=config&zalozka=zalohy'],
        [['Bloky a rozvržení'], 'bloky', 'modul=bloky'],
        [['Články', 'Koš'], 'clanky', 'modul=clanky&stav=kos'],
        [['Identita webu'], 'vzhled', 'modul=vzhled'],
        [['Stav systému'], 'config', 'modul=config&zalozka=stav'],
        [['Můj účet'], '', 'akce=ucet'],
    ];

    /**
     * Vrátí text připravený do HTML (escapovaný) se známými cestami proměněnými v odkazy.
     *
     * @param string $adresaAdministrace adresa admin.php (např. $app->url('admin.php'))
     * @param list<string> $moduly identifikátory modulů, do kterých přihlášený smí - jinam se neodkazuje
     */
    public static function odkazy(string $adresaAdministrace, string $text, array $moduly): string
    {
        $html = e($text);
        $nahrady = [];
        foreach (self::CESTY as [$casti, $modul, $dotaz]) {
            if ($modul !== '' && !in_array($modul, $moduly, true)) {
                continue;
            }
            $fraze = e(implode(' → ', array_map(static fn (string $c): string => t($c), $casti)));
            $nahrady[$fraze] = '<a href="' . e($adresaAdministrace . '?' . $dotaz) . '">' . $fraze . '</a>';
        }
        uksort($nahrady, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        // jedním průchodem: delší fráze vyhrává a už vložený odkaz se znovu nepřepisuje
        $vzor = '/' . implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), array_keys($nahrady))) . '/u';

        return $nahrady === [] ? $html : (string) preg_replace_callback($vzor, static fn (array $m): string => $nahrady[$m[0]], $html);
    }
}
