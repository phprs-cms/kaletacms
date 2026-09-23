<?php

declare(strict_types=1);

namespace MiroCMS\Mcp;

use MiroCMS\Admin\Moduly\Bloky;
use MiroCMS\Admin\Moduly\Galerie;
use MiroCMS\Admin\Moduly\Rubriky;
use MiroCMS\Core\App;
use MiroCMS\Front\Layouty;

/**
 * Nástroje, které MCP server nabízí Claudovi. Každý nástroj respektuje práva uživatele, jehož tokenem se Claude hlásí:
 * autor pracuje jen se svými články a nevydává, redaktor se všemi, administrátor navíc s bloky a šablonami webu.
 *
 * Chyba určená Claudovi (špatný vstup, chybějící právo) se hlásí výjimkou InvalidArgumentException / DomainException.
 */
final class Nastroje
{
    /** Šablony dodávané se systémem - přepsala by je aktualizace, proto se upravují jen jejich kopie. */
    private const array VESTAVENE_SABLONY = ['classic-newspaper', 'modern-magazine', 'minimal'];

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> definice nástrojů pro tools/list */
    public function seznam(): array
    {
        $s = fn (array $vlastnosti, array $povinne = []): array => ['type' => 'object', 'properties' => $vlastnosti === [] ? new \stdClass() : $vlastnosti, 'required' => $povinne];
        $text = fn (string $popis): array => ['type' => 'string', 'description' => $popis];
        $cislo = fn (string $popis): array => ['type' => 'integer', 'description' => $popis];
        $clanek = [
            'titulek' => $text('Titulek článku'), 'uvod' => $text('Perex jako HTML (1-2 odstavce)'), 'text' => $text('Text článku jako HTML'),
            'rubrika' => $text('Název nebo adresa (seo_link) rubriky'), 'stitky' => $text('Štítky oddělené čárkou'),
            'shrnuti' => $text('Blok "Ve zkratce": 3-5 bodů, každý na vlastním řádku'), 'seo_popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'obrazek' => $text('Adresa hlavního obrázku (z nástroje seznam_medii)'), 'obrazek_popis' => $text('Popisek hlavní fotky (prázdné = z knihovny médií)'),
            'obrazek_autor' => $text('Autor hlavní fotky, např. „Jana Nováková / ČTK“'),
            'komercni' => ['type' => 'boolean', 'description' => 'true = komerční sdělení (placený nebo partnerský obsah); na webu se viditelně označí'],
            'komercni_partner' => $text('Partner komerčního sdělení (nepovinné)'), 'datum' => $text('Datum vydání RRRR-MM-DD HH:MM; budoucí = naplánování'),
            'vydat' => ['type' => 'boolean', 'description' => 'true = vydat (jen s právem vydávat a na výslovný pokyn uživatele), jinak koncept'],
        ];
        $nastroje = [
            ['info_o_webu', 'Název webu, šablona, rozvržení, počty článků, role přihlášeného uživatele a jeho oprávnění.', $s([])],
            ['seznam_clanku', 'Seznam článků (nejnovější první).', $s(['stav' => $text('vse | vydane | plan | koncepty'), 'rubrika' => $text('název nebo adresa rubriky'), 'hledat' => $text('text v titulku'), 'limit' => $cislo('1-50, výchozí 20')])],
            ['nacti_clanek', 'Celý článek včetně textu a štítků.', $s(['id' => $cislo('ID článku (idc)')], ['id'])],
            ['vytvor_clanek', 'Založí nový článek. Bez "vydat": true vznikne koncept.', $s($clanek, ['titulek', 'rubrika'])],
            ['uprav_clanek', 'Změní zadaná pole článku; ostatní ponechá. Předchozí verze se uloží do historie.', $s(['id' => $cislo('ID článku')] + $clanek, ['id'])],
            ['seznam_rubrik', 'Strom rubrik s počty článků.', $s([])],
            ['vytvor_rubriku', 'Založí rubriku (redaktor a administrátor).', $s(['nazev' => $text('Název'), 'popis' => $text('Popis (HTML)'), 'nadrazena' => $text('Název nebo adresa nadřazené rubriky')], ['nazev'])],
            ['seznam_medii', 'Naposledy nahrané obrázky s adresami a rozměry.', $s(['limit' => $cislo('1-50, výchozí 20')])],
            ['seznam_bloku', 'Bloky webu podle zón a zvolené rozvržení stránky (administrátor).', $s([])],
            ['uloz_blok', 'Založí nebo upraví blok (administrátor). Typy: "" = vlastní HTML, ' . implode(', ', array_keys(Bloky::SYSTEMOVE)) . '.', $s([
                'id' => $cislo('ID bloku; bez něj vznikne nový'), 'nazev' => $text('Nadpis bloku'), 'typ_bloku' => $text('zkratka typu, prázdné = vlastní HTML'),
                'obsah' => $text('HTML bloku, u typu "men" řádky "text | adresa"'), 'zona' => $text(implode(' | ', array_keys(Bloky::ZONY))), 'zobrazit' => ['type' => 'boolean'],
            ], ['nazev'])],
            ['seznam_sablon', 'Šablony webu (layouty), která je aktivní a které jdou upravovat (administrátor).', $s([])],
            ['vytvor_sablonu', 'Zkopíruje existující šablonu pod novým názvem, aby se dala upravovat (administrátor).', $s(['nazev' => $text('složka nové šablony: malá písmena, číslice, pomlčky'), 'podle' => $text('zdrojová šablona, výchozí "classic-newspaper"'), 'popisny_nazev' => $text('název zobrazený v administraci')], ['nazev'])],
            ['nacti_soubor_sablony', 'Přečte soubor šablony (base.php, blok.php, cla_standard.php, style.css, info.php…).', $s(['sablona' => $text('složka šablony'), 'soubor' => $text('název souboru; bez něj vrátí seznam souborů')], ['sablona'])],
            ['uloz_soubor_sablony', 'Uloží soubor vlastní šablony (.php nebo .css). PHP se před uložením kontroluje na syntaxi. Vestavěné šablony upravit nejde.', $s(['sablona' => $text('složka šablony'), 'soubor' => $text('název souboru'), 'obsah' => $text('celý nový obsah souboru')], ['sablona', 'soubor', 'obsah'])],
            ['aktivuj_sablonu', 'Přepne web na danou šablonu (administrátor). Před tím ji ukaž uživateli v náhledu: adresa webu s ?sablona=<složka> funguje přihlášenému administrátorovi.', $s(['sablona' => $text('složka šablony')], ['sablona'])],
        ];

        return array_map(fn (array $n): array => ['name' => $n[0], 'description' => $n[1], 'inputSchema' => $n[2]], $nastroje);
    }

    public function meni(string $nazev): bool
    {
        return in_array($nazev, ['vytvor_clanek', 'uprav_clanek', 'vytvor_rubriku', 'uloz_blok', 'vytvor_sablonu', 'uloz_soubor_sablony', 'aktivuj_sablonu'], true);
    }

    /** @param array<string, mixed> $a */
    public function zavolej(string $nazev, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $web = $this->app->settings();
        $jenAdmin = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen administrátor.');
            }
        };

        switch ($nazev) {
            case 'info_o_webu':
                return [
                    'web' => $web->get('nazev_webu'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $web->get('popis_webu'),
                    'sablona' => $web->get('layout'), 'rozvrzeni' => $web->get('rozvrzeni'), 'verze_mirocms' => MIROCMS_VERSION,
                    'clanku_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {clanky} WHERE visible = 1 AND datum <= NOW()'),
                    'uzivatel' => $auth->user()['user'], 'role' => \MiroCMS\Core\Auth::TYPY[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->smiVydavat(),
                ];

            case 'seznam_clanku':
                $where = ['c.smazano IS NULL']; // koš se přes MCP nevypisuje ani needituje
                $p = [];
                if (($autori = $auth->spravovaniAutori()) !== null) {
                    $where[] = 'c.autor IN (' . implode(',', $autori) . ')';
                }
                if (($rubriky = $auth->povoleneRubriky()) !== null) {
                    $where[] = 'c.tema IN (' . implode(',', $rubriky) . ')';
                }
                $stavy = ['vydane' => 'c.visible = 1 AND c.datum <= NOW()', 'plan' => 'c.visible = 1 AND c.datum > NOW()', 'koncepty' => 'c.visible = 0'];
                if (isset($stavy[$a['stav'] ?? ''])) {
                    $where[] = $stavy[$a['stav']];
                }
                if (!empty($a['rubrika'])) {
                    $where[] = 'c.tema = ?';
                    $p[] = $this->rubrika((string) $a['rubrika']);
                }
                if (!empty($a['hledat'])) {
                    $where[] = 'c.titulek LIKE ?';
                    $p[] = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
                }

                return $db->all(
                    'SELECT c.idc AS id, c.titulek, c.seo_link, t.nazev AS rubrika, c.datum, c.visible AS vydany, c.visit AS precteno
                     FROM {clanky} c JOIN {topic} t ON t.idt = c.tema WHERE ' . implode(' AND ', $where) . ' ORDER BY c.datum DESC LIMIT ?',
                    [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
                );

            case 'nacti_clanek':
                $c = $this->clanek((int) ($a['id'] ?? 0));

                return array_intersect_key($c, array_flip(['idc', 'titulek', 'seo_link', 'uvod', 'text', 'obrazek', 'obrazek_popis', 'obrazek_autor', 'komercni', 'komercni_partner', 'datum', 'visible', 'shrnuti', 'faq', 'seo_titulek', 'seo_popis', 'visit']))
                    + ['rubrika' => $db->value('SELECT nazev FROM {topic} WHERE idt = ?', [$c['tema']]),
                        'stitky' => array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {clanky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
                        'adresa' => $this->app->request->origin() . $this->app->url('clanek/' . $c['seo_link'])];

            case 'vytvor_clanek':
            case 'uprav_clanek':
                return $this->ulozClanek($nazev === 'uprav_clanek' ? $this->clanek((int) ($a['id'] ?? 0)) : null, $a);

            case 'seznam_rubrik':
                return array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => str_repeat('— ', $r['uroven']) . $r['nazev'], 'adresa' => $r['seo_link'], 'clanku' => (int) $r['pocet_clanku']], Rubriky::strom($db));

            case 'vytvor_rubriku':
                if (!$auth->isAdmin() && !$auth->isRedaktor()) {
                    throw new \DomainException('Rubriky smí zakládat redaktor nebo administrátor.');
                }
                $jmeno = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($jmeno === '') {
                    throw new \InvalidArgumentException('Chybí název rubriky.');
                }
                $seo = $this->volnaAdresa('topic', 'idt', slugify($jmeno, 110));
                $id = $db->insert('topic', ['nazev' => $jmeno, 'seo_link' => $seo, 'popis' => (string) ($a['popis'] ?? ''), 'id_predka' => !empty($a['nadrazena']) ? $this->rubrika((string) $a['nadrazena']) : null]);

                return ['id' => $id, 'adresa' => $seo];

            case 'seznam_medii':
                return array_map(fn (array $o): array => ['id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'adresa' => $this->app->url($o['obr_poloha']), 'rozmery' => $o['obr_width'] . '×' . $o['obr_height']],
                    $db->all('SELECT * FROM {imggal_obr} ORDER BY ido DESC LIMIT ?', [max(1, min(50, (int) ($a['limit'] ?? 20)))]));

            case 'seznam_bloku':
                $jenAdmin();

                return ['rozvrzeni' => $web->get('rozvrzeni'), 'zony' => Bloky::ROZVRZENI[$web->get('rozvrzeni')][2] ?? [],
                    'bloky' => $db->all('SELECT idb AS id, nazev, sys_funkce AS typ_bloku, zona, zobrazit, LEFT(obsah, 300) AS obsah FROM {bloky} ORDER BY zona, hodnost DESC')];

            case 'uloz_blok':
                $jenAdmin();
                $typ = (string) ($a['typ_bloku'] ?? '');
                if ($typ !== '' && !isset(Bloky::SYSTEMOVE[$typ])) {
                    throw new \InvalidArgumentException('Neznámý typ bloku. Povolené: ' . implode(', ', array_keys(Bloky::SYSTEMOVE)));
                }
                $data = ['nazev' => mb_substr((string) $a['nazev'], 0, 100), 'sys_funkce' => $typ, 'obsah' => (string) ($a['obsah'] ?? ''),
                    'zona' => isset(Bloky::ZONY[$a['zona'] ?? '']) ? $a['zona'] : 'prava', 'zobrazit' => (int) ($a['zobrazit'] ?? true)];
                if (!empty($a['id'])) {
                    $db->update('bloky', $data, ['idb' => (int) $a['id']]);

                    return ['id' => (int) $a['id'], 'stav' => 'upraveno'];
                }

                return ['id' => $db->insert('bloky', $data + ['hodnost' => 0]), 'stav' => 'vytvořeno'];

            case 'seznam_sablon':
                $jenAdmin();

                return array_map(fn (string $slozka, array $l): array => ['sablona' => $slozka, 'nazev' => $l['nazev'], 'popis' => $l['popis'], 'aktivni' => $slozka === $web->get('layout'),
                    'lze_upravovat' => !in_array($slozka, self::VESTAVENE_SABLONY, true)], array_keys(Layouty::seznam()), Layouty::seznam());

            case 'vytvor_sablonu':
                $jenAdmin();
                $nova = (string) ($a['nazev'] ?? '');
                $podle = (string) ($a['podle'] ?? Layouty::VYCHOZI);
                if (!preg_match('/^[a-z][a-z0-9-]{2,40}$/', $nova) || is_dir(MIROCMS_ROOT . '/layout/' . $nova)) {
                    throw new \InvalidArgumentException('Název šablony: 3-40 znaků, malá písmena, číslice a pomlčky; složka ještě nesmí existovat.');
                }
                if (!isset(Layouty::seznam()[$podle])) {
                    throw new \InvalidArgumentException('Zdrojová šablona neexistuje.');
                }
                mkdir(MIROCMS_ROOT . '/layout/' . $nova, 0775);
                foreach (glob(MIROCMS_ROOT . '/layout/' . $podle . '/*.{php,css}', GLOB_BRACE) ?: [] as $soubor) {
                    $obsah = (string) file_get_contents($soubor);
                    // zkopírovaná šablona musí odkazovat na vlastní style.css
                    file_put_contents(MIROCMS_ROOT . '/layout/' . $nova . '/' . basename($soubor), str_replace("layout/{$podle}/", "layout/{$nova}/", $obsah));
                }
                file_put_contents(MIROCMS_ROOT . '/layout/' . $nova . '/info.php', "<?php\n\nreturn " . var_export([
                    'nazev' => mb_substr((string) ($a['popisny_nazev'] ?? $nova), 0, 60), 'popis' => 'Vlastní šablona (vychází z ' . $podle . ').',
                    'rozvrzeni' => Layouty::seznam()[$podle]['rozvrzeni'],
                ], true) . ";\n");

                return ['sablona' => $nova, 'soubory' => $this->souborySablony($nova), 'nahled' => $this->app->request->origin() . $this->app->url('?sablona=' . $nova)];

            case 'nacti_soubor_sablony':
                $jenAdmin();
                $slozka = $this->sablona((string) ($a['sablona'] ?? ''));
                if (empty($a['soubor'])) {
                    return ['soubory' => $this->souborySablony($slozka)];
                }

                return (string) file_get_contents($this->souborSablony($slozka, (string) $a['soubor'], true));

            case 'uloz_soubor_sablony':
                $jenAdmin();
                $slozka = $this->sablona((string) ($a['sablona'] ?? ''));
                if (in_array($slozka, self::VESTAVENE_SABLONY, true)) {
                    throw new \DomainException('Vestavěnou šablonu by přepsala aktualizace systému. Nejprve ji zkopíruj nástrojem vytvor_sablonu a upravuj kopii.');
                }
                $cesta = $this->souborSablony($slozka, (string) ($a['soubor'] ?? ''), false);
                $obsah = (string) ($a['obsah'] ?? '');
                if (strlen($obsah) > 300 * 1024) {
                    throw new \InvalidArgumentException('Soubor je větší než 300 kB.');
                }
                if (str_ends_with($cesta, '.php')) {
                    // šablona je jen prezentační vrstva: nesmí na soubory, databázi, síť ani na kód systému (Core\SablonaKontrola)
                    $vady = \MiroCMS\Core\SablonaKontrola::over($obsah);
                    if ($vady !== []) {
                        throw new \InvalidArgumentException("Soubor se neuložil – šablona smí jen vypisovat data, která dostává:\n- " . implode("\n- ", array_slice($vady, 0, 12))
                            . "\nPovolené: výpis, if/foreach/match, uzávěry, \$web->get(), \$url(), e(), t(), datum() a běžné funkce pro text, čísla a pole. Pravidla: layout/CLAUDE.md.");
                    }
                } elseif (preg_match('#expression\s*\(|behavior\s*:#i', $obsah)) {
                    throw new \InvalidArgumentException('Styl obsahuje zastaralé spustitelné konstrukce (expression, behavior). Nic se neuložilo.');
                }
                file_put_contents($cesta, $obsah, LOCK_EX);

                return ['ulozeno' => basename($cesta), 'velikost' => strlen($obsah), 'nahled' => $this->app->request->origin() . $this->app->url('?sablona=' . $slozka)];

            case 'aktivuj_sablonu':
                $jenAdmin();
                $slozka = $this->sablona((string) ($a['sablona'] ?? ''));
                $web->set('layout', $slozka);
                \MiroCMS\Admin\Moduly\Bloky::prepniRozvrzeni($this->app->db(), $web, Layouty::seznam()[$slozka]['rozvrzeni']);

                return 'Web nyní používá šablonu ' . $slozka . '.';
        }
        throw new \InvalidArgumentException('Neznámý nástroj: ' . $nazev);
    }

    /**
     * @param array<string, mixed>|null $puvodni
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function ulozClanek(?array $puvodni, array $a): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if ($puvodni !== null && $puvodni['visible'] && !$auth->smiVydavat()) {
            throw new \DomainException('Vydaný článek může upravit jen uživatel s právem vydávat.');
        }
        $data = [];
        foreach (['titulek' => 255, 'uvod' => 0, 'text' => 0, 'shrnuti' => 0, 'seo_popis' => 320, 'obrazek' => 255, 'obrazek_popis' => 300, 'obrazek_autor' => 120, 'komercni_partner' => 120] as $pole => $max) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = $max > 0 ? mb_substr((string) $a[$pole], 0, $max) : (string) $a[$pole];
            }
        }
        if (array_key_exists('komercni', $a)) {
            $data['komercni'] = (int) (bool) $a['komercni'];
        }
        if (array_key_exists('rubrika', $a)) {
            $data['tema'] = $this->rubrika((string) $a['rubrika']);
            // článek přebírá jazykovou verzi rubriky – stejně jako při uložení v administraci
            $data['jazyk'] = (string) $db->value('SELECT jazyk FROM {topic} WHERE idt = ?', [$data['tema']]);
        }
        if (!empty($a['datum'])) {
            $ts = strtotime((string) $a['datum']);
            if ($ts === false) {
                throw new \InvalidArgumentException('Datum nemá platný tvar (RRRR-MM-DD HH:MM).');
            }
            $data['datum'] = date('Y-m-d H:i:s', $ts);
        }
        if (array_key_exists('vydat', $a)) {
            if ($a['vydat'] && !$auth->smiVydavat()) {
                throw new \DomainException('Uživatel nemá právo vydávat – článek lze uložit jen jako koncept.');
            }
            $data['visible'] = (int) (bool) $a['vydat'];
        }
        if (($data['titulek'] ?? $puvodni['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Článek musí mít titulek.');
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');

        if ($puvodni === null) {
            if (!isset($data['tema'])) {
                throw new \InvalidArgumentException('Chybí rubrika.');
            }
            $data += ['uvod' => '', 'text' => '', 'autor' => $auth->id(), 'datum' => date('Y-m-d H:i:s'), 'visible' => 0,
                'sablona' => $db->value('SELECT MIN(ids) FROM {cla_sab}'), 'seo_link' => $this->volnaAdresa('clanky', 'idc', slugify($data['titulek'], 150))];
            $id = $db->insert('clanky', $data);
        } else {
            $id = (int) $puvodni['idc'];
            $db->insert('clanky_revize', ['idc' => $id, 'datum' => $puvodni['zmeneno'] ?? $puvodni['datum'], 'kdo' => $auth->id(), 'titulek' => $puvodni['titulek'], 'uvod' => $puvodni['uvod'], 'text' => $puvodni['text']]);
            $db->update('clanky', $data, ['idc' => $id]);
        }
        \MiroCMS\Core\Hledani::indexuj($db, $id);
        if (array_key_exists('stitky', $a)) {
            $db->delete('clanky_stitky', ['idc' => $id]);
            foreach (array_slice(array_unique(array_filter(array_map(trim(...), explode(',', (string) $a['stitky'])))), 0, 20) as $stitek) {
                $seo = slugify($stitek, 90);
                $ids = $db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]) ?? $db->insert('stitky', ['nazev' => mb_substr($stitek, 0, 80), 'seo_link' => $seo]);
                $db->run('INSERT IGNORE INTO {clanky_stitky} (idc, ids) VALUES (?, ?)', [$id, (int) $ids]);
            }
        }
        $ulozeny = $db->one('SELECT * FROM {clanky} WHERE idc = ?', [$id]);
        Galerie::zapisPouziti($db, $id, $ulozeny['obrazek'], $ulozeny['uvod'], $ulozeny['text']);

        return ['id' => $id, 'stav' => !$ulozeny['visible'] ? 'koncept' : (strtotime($ulozeny['datum']) > time() ? 'naplánováno' : 'vydáno'),
            'nahled' => $this->app->request->origin() . $this->app->url('clanek/' . $ulozeny['seo_link'] . '?nahled=1'),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?modul=clanky&akce=edit&id=' . $id)];
    }

    /** @return array<string, mixed> článek, ke kterému má uživatel přístup */
    private function clanek(int $id): array
    {
        $clanek = $this->app->db()->one('SELECT * FROM {clanky} WHERE idc = ? AND smazano IS NULL', [$id]);
        $autori = $this->app->auth()->spravovaniAutori();
        $rubriky = $this->app->auth()->povoleneRubriky();
        if ($clanek === null || ($autori !== null && !in_array((int) $clanek['autor'], $autori, true)) || ($rubriky !== null && !in_array((int) $clanek['tema'], $rubriky, true))) {
            throw new \InvalidArgumentException('Článek neexistuje nebo k němu uživatel nemá přístup.');
        }

        return $clanek;
    }

    private function rubrika(string $nazevNeboAdresa): int
    {
        $idt = $this->app->db()->value('SELECT idt FROM {topic} WHERE seo_link = ? OR nazev = ? LIMIT 1', [$nazevNeboAdresa, $nazevNeboAdresa]);
        if ($idt === null) {
            throw new \InvalidArgumentException('Rubrika „' . $nazevNeboAdresa . '“ neexistuje. Použij nástroj seznam_rubrik.');
        }

        if (($povolene = $this->app->auth()->povoleneRubriky()) !== null && !in_array((int) $idt, $povolene, true)) {
            throw new \InvalidArgumentException('Do rubriky „' . $nazevNeboAdresa . '“ nemá uživatel oprávnění psát.');
        }

        return (int) $idt;
    }

    private function volnaAdresa(string $tabulka, string $klic, string $seo): string
    {
        $kandidat = $seo;
        for ($i = 2; $this->app->db()->value("SELECT {$klic} FROM {{$tabulka}} WHERE seo_link = ?", [$kandidat]) !== null; $i++) {
            $kandidat = $seo . '-' . $i;
        }

        return $kandidat;
    }

    private function sablona(string $slozka): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]{2,40}$/', $slozka) || !isset(Layouty::seznam()[$slozka])) {
            throw new \InvalidArgumentException('Šablona neexistuje. Použij nástroj seznam_sablon.');
        }

        return $slozka;
    }

    /** @return list<string> */
    private function souborySablony(string $slozka): array
    {
        return array_map(basename(...), glob(MIROCMS_ROOT . '/layout/' . $slozka . '/*.{php,css}', GLOB_BRACE) ?: []);
    }

    private function souborSablony(string $slozka, string $soubor, bool $musiExistovat): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,40}\.(php|css)$/', $soubor)) {
            throw new \InvalidArgumentException('Název souboru: malá písmena, číslice, pomlčky a podtržítka, přípona .php nebo .css.');
        }
        $cesta = MIROCMS_ROOT . '/layout/' . $slozka . '/' . $soubor;
        if ($musiExistovat && !is_file($cesta)) {
            throw new \InvalidArgumentException('Soubor v šabloně není. Dostupné: ' . implode(', ', $this->souborySablony($slozka)));
        }

        return $cesta;
    }
}
