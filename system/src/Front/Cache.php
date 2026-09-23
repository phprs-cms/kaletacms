<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\App;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;

/**
 * Cache celých stránek pro nepřihlášené čtenáře (soubory ve storage/cache/stranky, platnost 5 minut).
 * Stránka z cache stojí jeden dotaz do databáze místo desítek. Jakákoli změna v administraci i nový
 * komentář cache smaže. Necachuje se nic osobního: přihlášená redakce, čtenář, který už hlasoval, náhledy.
 */
final class Cache
{
    private const string SLOZKA = MIROCMS_ROOT . '/storage/cache/stranky';
    private const int PLATNOST = 300;

    /** @var resource|null zámek stránky, kterou tenhle požadavek právě přegenerovává */
    private static $zamek = null;

    public static function nacti(App $app): ?Response
    {
        $soubor = self::soubor($app);
        if ($soubor === null || !is_file($soubor)) {
            return null;
        }
        if (filemtime($soubor) < time() - self::PLATNOST) {
            // Prošlou stránku přegeneruje první, kdo přijde; ostatní, kteří dorazí ve stejné vteřině, dostanou ještě tu starou
            // (nejdéle o minutu déle). Bez toho by po vypršení skládalo tutéž stránku z databáze najednou všech sto čtenářů.
            $zamek = @fopen($soubor . '.zamek', 'c');
            if ($zamek === false || flock($zamek, LOCK_EX | LOCK_NB)) {
                self::$zamek = $zamek ?: null; // drží se do uloz() nebo do konce požadavku

                return null;
            }
            fclose($zamek);
            if (filemtime($soubor) < time() - self::PLATNOST - 60) {
                return null;
            }
        }
        [$hlavicka, $html] = explode("\n", (string) file_get_contents($soubor), 2) + [1 => ''];
        $meta = json_decode($hlavicka, true);
        if (!is_array($meta) || $html === '') {
            return null;
        }
        Statistika::zaznamenej($app, $meta['idc'] ?? null);
        if (!empty($meta['idc'])) {
            $app->db()->run('UPDATE {clanky} SET visit = visit + 1 WHERE idc = ?', [(int) $meta['idc']]);
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Cache' => 'mirocms']);
    }

    public static function uloz(App $app, string $html, ?int $idc): void
    {
        $soubor = self::soubor($app);
        if ($soubor === null) {
            return;
        }
        if (!is_dir(self::SLOZKA)) {
            @mkdir(self::SLOZKA, 0775, true);
        }
        @file_put_contents($soubor, json_encode(['idc' => $idc]) . "\n" . $html, LOCK_EX);
        if (self::$zamek !== null) {
            flock(self::$zamek, LOCK_UN);
            fclose(self::$zamek);
            self::$zamek = null;
            @unlink($soubor . '.zamek');
        }
        if (random_int(1, 100) === 1) {
            // občasný úklid: prošlé soubory by jinak mizely jen při změně v administraci
            foreach (glob(self::SLOZKA . '/*.html') ?: [] as $stary) {
                if (filemtime($stary) < time() - self::PLATNOST) {
                    @unlink($stary);
                }
            }
        }
    }

    /**
     * Krátká cache textových výstupů, které jsou pro všechny stejné (RSS, mapa webu, feed.json, llms.txt): čtečky a roboti
     * si je stahují pořád dokola a bez cache se pokaždé skládají z databáze. Klíč musí zahrnout vše, na čem výstup závisí.
     *
     * @param callable(): string $vyrob
     */
    public static function text(App $app, string $klic, callable $vyrob): string
    {
        if (!$app->settings()->bool('cache_stranek')) {
            return $vyrob();
        }
        // přípona .html jen kvůli vymaz() - jakákoli změna v administraci smaže i tyhle soubory
        $soubor = self::SLOZKA . '/zdroj-' . sha1($app->request->basePath() . '|' . $klic) . '.html';
        if (is_file($soubor) && filemtime($soubor) >= time() - self::PLATNOST && ($obsah = file_get_contents($soubor)) !== false && $obsah !== '') {
            return $obsah;
        }
        $obsah = $vyrob();
        if (!is_dir(self::SLOZKA)) {
            @mkdir(self::SLOZKA, 0775, true);
        }
        @file_put_contents($soubor, $obsah, LOCK_EX);

        return $obsah;
    }

    public static function vymaz(): void
    {
        foreach (array_merge(glob(self::SLOZKA . '/*.html') ?: [], glob(self::SLOZKA . '/*.zamek') ?: []) as $soubor) {
            @unlink($soubor);
        }
    }

    /** Soubor cache pro tento požadavek, nebo null, když se cachovat nemá. */
    private static function soubor(App $app): ?string
    {
        $r = $app->request;
        $s = $app->settings();
        if (!$s->bool('cache_stranek') || $r->isPost() || Rozsireni::je($s, 'reklama')) {
            return null; // reklamy se střídají a počítají při každém zobrazení
        }
        if (array_diff(array_keys($_GET), ['strana']) !== []) {
            return null;
        }
        foreach (array_keys($_COOKIE) as $cookie) {
            if ($cookie === 'mirocms' || str_starts_with((string) $cookie, 'mirocms_h') || str_starts_with((string) $cookie, 'mirocms_a') || $cookie === Ctenari::COOKIE) {
                return null;
            }
        }

        return self::SLOZKA . '/' . md5($r->origin() . '|' . \MiroCMS\Core\Jazyk::kod() . '|' . $r->path() . '|' . $r->getInt('strana', 1) . '|' . $s->get('layout') . '|' . $s->get('rozvrzeni')) . '.html';
    }
}
