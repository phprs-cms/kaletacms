<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Menu webu (Vzhled → Menu): hlavní navigace a menu v patičce, zvlášť pro každou jazykovou verzi.
 * Položky: stránka (s vlastním textem nebo názvem stránky), vlastní odkaz, novinky, skupina (jen text) –
 * a pod každou z nich jedna úroveň podmenu. Dokud hlavní menu nikdo neuloží, skládá se samo ze stránek „v menu“.
 */
final class Menu
{
    /** @var array<string, string> umístění => popisek */
    public const array UMISTENI = ['hlavni' => 'Hlavní menu', 'paticka' => 'Menu v patičce'];

    public const array TYPY = ['stranka', 'odkaz', 'novinky', 'skupina'];

    public const int MAX_POLOZEK = 80;

    /** @return list<array<string, mixed>>|null uložené položky, null = menu se skládá automaticky */
    public static function nacti(Db $db, string $umisteni, string $jazyk): ?array
    {
        $json = $db->value('SELECT polozky FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$umisteni, $jazyk]);

        return $json === null ? null : self::vycisti(json_decode((string) $json, true));
    }

    /** Uloží položky; null vrátí menu do automatického režimu. */
    public static function uloz(Db $db, string $umisteni, string $jazyk, ?array $polozky): void
    {
        if ($polozky === null) {
            $db->run('DELETE FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$umisteni, $jazyk]);

            return;
        }
        $db->run('INSERT INTO {menu} (umisteni, jazyk, polozky, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE polozky = VALUES(polozky), zmeneno = NOW()',
            [$umisteni, $jazyk, (string) json_encode(self::vycisti($polozky), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Jediný validátor položek (administrace i MCP): známé typy, platné adresy, nejvýš jedna úroveň podmenu.
     *
     * @return list<array<string, mixed>>
     */
    public static function vycisti(mixed $vstup, int $hloubka = 0, int &$pocet = 0): array
    {
        $vysledek = [];
        foreach (is_array($vstup) ? $vstup : [] as $p) {
            if (!is_array($p) || $pocet >= self::MAX_POLOZEK || !in_array($p['typ'] ?? null, self::TYPY, true)) {
                continue;
            }
            $polozka = ['typ' => $p['typ'], 'text' => mb_substr(trim((string) ($p['text'] ?? '')), 0, 80)];
            if ($p['typ'] === 'stranka') {
                $polozka['ids'] = (int) ($p['ids'] ?? 0);
                if ($polozka['ids'] <= 0) {
                    continue;
                }
            } elseif ($p['typ'] === 'odkaz') {
                $polozka['url'] = trim((string) ($p['url'] ?? ''));
                $polozka['nove_okno'] = !empty($p['nove_okno']);
                if ($polozka['text'] === '' || !self::platnaAdresa($polozka['url'])) {
                    continue;
                }
            } elseif ($p['typ'] === 'skupina' && $polozka['text'] === '') {
                continue;
            }
            $pocet++;
            if ($hloubka === 0) {
                $deti = self::vycisti($p['deti'] ?? [], 1, $pocet);
                if ($deti !== []) {
                    $polozka['deti'] = $deti;
                }
            }
            $vysledek[] = $polozka;
        }

        return $vysledek;
    }

    /** Adresa vlastního odkazu: https, cesta na webu (/…), kotva, e-mail nebo telefon. */
    public static function platnaAdresa(string $url): bool
    {
        return (bool) preg_match('#^(https?://[^\s<>"]{1,500}|/[^\s<>"]{0,500}|\#[A-Za-z0-9_-]{1,80}|mailto:[^\s<>"]{3,200}|tel:[+\d ()-]{3,40})$#', $url);
    }

    /**
     * Položky k vykreslení; skryté a smazané stránky (i s podmenu) vypadnou, skupina bez podmenu taky.
     *
     * @return list<array{text: string, url: string, nove_okno: bool, deti: list<array<string, mixed>>, novinky?: bool, auto?: bool}>  auto = odkaz na novinky přidaný automatickým menu
     */
    public static function polozky(App $app, string $umisteni, string $jazyk, int $uvod): array
    {
        $db = $app->db();
        $stranky = [];
        foreach ($db->all('SELECT ids, titulek, seo_link, v_menu FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = ? ORDER BY poradi, titulek', [$jazyk]) as $s) {
            $stranky[(int) $s['ids']] = $s;
        }
        $adresa = fn (array $s): string => $app->url((int) $s['ids'] === $uvod ? '' : $s['seo_link']);
        $ulozene = self::nacti($db, $umisteni, $jazyk);
        if ($ulozene === null) {
            if ($umisteni !== 'hlavni') {
                return [];
            }
            // automaticky: stránky „v menu“ podle pořadí a na konci novinky (prvek Navigace je může vypnout)
            $auto = array_map(fn (array $s): array => ['text' => $s['titulek'], 'url' => $adresa($s), 'nove_okno' => false, 'deti' => []],
                array_values(array_filter($stranky, fn (array $s): bool => (bool) $s['v_menu'])));
            $auto[] = ['text' => t('Novinky'), 'url' => $app->url('novinky'), 'nove_okno' => false, 'deti' => [], 'novinky' => true, 'auto' => true];

            return $auto;
        }
        $preved = function (array $p) use (&$preved, $stranky, $adresa, $app): ?array {
            $deti = array_values(array_filter(array_map($preved, $p['deti'] ?? [])));

            return match ($p['typ']) {
                'stranka' => isset($stranky[$p['ids']])
                    ? ['text' => $p['text'] !== '' ? $p['text'] : $stranky[$p['ids']]['titulek'], 'url' => $adresa($stranky[$p['ids']]), 'nove_okno' => false, 'deti' => $deti]
                    : null,
                'novinky' => ['text' => $p['text'] !== '' ? $p['text'] : t('Novinky'), 'url' => $app->url('novinky'), 'nove_okno' => false, 'deti' => $deti, 'novinky' => true],
                'odkaz' => ['text' => $p['text'], 'url' => str_starts_with($p['url'], '/') ? $app->url($p['url']) : $p['url'], 'nove_okno' => $p['nove_okno'], 'deti' => $deti],
                'skupina' => $deti === [] ? null : ['text' => $p['text'], 'url' => '', 'nove_okno' => false, 'deti' => $deti],
                default => null,
            };
        };

        return array_values(array_filter(array_map($preved, $ulozene)));
    }

    /**
     * Seznam <li> (bez obalového <ul>) pro šablonu i prvek Navigace. Položka s podmenu má třídu „podmenu“ a vnořený <ul>;
     * aktivní odkaz dostane aria-current, jeho nadřazená položka třídu „aktivni“.
     *
     * @param list<array<string, mixed>> $polozky z polozky()
     * @param string $cesta  cesta zobrazené stránky (např. /web/en/sluzby)
     * @param string $koren  adresa úvodu ($app->url('')) – úvod je aktivní jen přesnou shodou
     */
    public static function html(array $polozky, string $cesta, string $koren): string
    {
        $aktivni = function (string $url) use ($cesta, $koren): bool {
            if ($url === '' || preg_match('#^[a-z]+:|^\##i', $url)) {
                return false;
            }
            $cil = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

            return $cil === rtrim($cesta, '/') || ($cil !== rtrim($koren, '/') && $cil !== '' && str_starts_with($cesta, $cil . '/'));
        };
        $li = function (array $p) use (&$li, $aktivni): string {
            $je = $aktivni($p['url']);
            $odkaz = $p['url'] === ''
                ? '<span>' . e($p['text']) . '</span>'
                : '<a href="' . e($p['url']) . '"' . ($je ? ' aria-current="page"' : '') . ($p['nove_okno'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($p['text']) . '</a>';
            if ($p['deti'] === []) {
                return '<li>' . $odkaz . '</li>';
            }
            $vnitrek = implode('', array_map($li, $p['deti']));
            $vetev = str_contains($vnitrek, 'aria-current');

            return '<li class="podmenu' . ($vetev ? ' aktivni' : '') . '">' . $odkaz . '<ul>' . $vnitrek . '</ul></li>';
        };

        return implode('', array_map($li, $polozky));
    }

    /**
     * Zařazení stránky do menu z jejího formuláře (zaškrtávátko „v navigaci“): v automatickém režimu stačí sloupec v_menu,
     * v uloženém menu se stránka přidá na konec, nebo se odebere (její podmenu se posune o úroveň výš).
     */
    public static function nastavStranku(Db $db, int $ids, string $jazyk, bool $vMenu): void
    {
        $polozky = self::nacti($db, 'hlavni', $jazyk);
        if ($polozky === null) {
            return;
        }
        $je = false;
        $bez = [];
        foreach ($polozky as $p) {
            if ($p['typ'] === 'stranka' && $p['ids'] === $ids) {
                $je = true;
                if (!$vMenu) {
                    array_push($bez, ...($p['deti'] ?? []));
                    continue;
                }
            }
            $deti = [];
            foreach ($p['deti'] ?? [] as $d) {
                if ($d['typ'] === 'stranka' && $d['ids'] === $ids) {
                    $je = true;
                    if (!$vMenu) {
                        continue;
                    }
                }
                $deti[] = $d;
            }
            if (isset($p['deti'])) {
                $p['deti'] = $deti;
            }
            $bez[] = $p;
        }
        if ($vMenu && !$je) {
            $bez[] = ['typ' => 'stranka', 'ids' => $ids, 'text' => ''];
        }
        if ($vMenu !== $je || !$vMenu) {
            self::uloz($db, 'hlavni', $jazyk, $bez);
        }
    }

    /** Je stránka v uloženém hlavním menu? null = menu je automatické (platí sloupec v_menu). */
    public static function obsahujeStranku(Db $db, int $ids, string $jazyk): ?bool
    {
        $polozky = self::nacti($db, 'hlavni', $jazyk);
        if ($polozky === null) {
            return null;
        }
        foreach ($polozky as $p) {
            foreach ([$p, ...($p['deti'] ?? [])] as $x) {
                if ($x['typ'] === 'stranka' && $x['ids'] === $ids) {
                    return true;
                }
            }
        }

        return false;
    }
}
