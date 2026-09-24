<?php

declare(strict_types=1);

namespace MiroCMS\Mcp;

use MiroCMS\Admin\Moduly\Galerie;
use MiroCMS\Admin\Moduly\Kategorie;
use MiroCMS\Admin\Moduly\Stranky;
use MiroCMS\Core\App;
use MiroCMS\Front\Layouty;

/**
 * Nástroje, které MCP server nabízí Claudovi. Každý nástroj respektuje práva uživatele, jehož tokenem se Claude hlásí:
 * autor pracuje jen se svými novinkami a nevydává, editor s veškerým obsahem, správce navíc se šablonami webu.
 *
 * Chyba určená Claudovi (špatný vstup, chybějící právo) se hlásí výjimkou InvalidArgumentException / DomainException.
 */
final class Nastroje
{
    /** Šablony dodávané se systémem - přepsala by je aktualizace, proto se upravují jen jejich kopie. */
    private const array VESTAVENE_SABLONY = ['zakladni'];

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> definice nástrojů pro tools/list */
    public function seznam(): array
    {
        $s = fn (array $vlastnosti, array $povinne = []): array => ['type' => 'object', 'properties' => $vlastnosti === [] ? new \stdClass() : $vlastnosti, 'required' => $povinne];
        $text = fn (string $popis): array => ['type' => 'string', 'description' => $popis];
        $cislo = fn (string $popis): array => ['type' => 'integer', 'description' => $popis];
        $novinka = [
            'titulek' => $text('Titulek novinky'), 'uvod' => $text('Perex jako HTML (1-2 odstavce)'), 'text' => $text('Text jako HTML'),
            'kategorie' => $text('Název nebo adresa (seo_link) kategorie'), 'stitky' => $text('Štítky oddělené čárkou'),
            'seo_titulek' => $text('Titulek pro vyhledávače (nepovinné)'), 'seo_popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'obrazek' => $text('Adresa hlavního obrázku (z nástroje seznam_medii)'), 'obrazek_popis' => $text('Popisek hlavního obrázku (prázdné = z knihovny médií)'),
            'faq' => $text('Otázky a odpovědi: otázka na řádku, odpověď pod ní, mezi dvojicemi prázdný řádek'),
            'datum' => $text('Datum vydání RRRR-MM-DD HH:MM; budoucí = naplánování'),
            'vydat' => ['type' => 'boolean', 'description' => 'true = vydat (jen s právem vydávat a na výslovný pokyn uživatele), jinak koncept'],
        ];
        $stranka = [
            'titulek' => $text('Název stránky (zobrazí se v navigaci a jako nadpis)'), 'text' => $text('Obsah stránky jako HTML'),
            'adresa' => $text('Část adresy za doménou (seo_link); bez ní vznikne z názvu'), 'popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'v_menu' => ['type' => 'boolean', 'description' => 'true = odkaz v hlavní navigaci webu'], 'poradi' => $cislo('Pořadí v navigaci, menší = dřív'),
            'zobrazit' => ['type' => 'boolean', 'description' => 'true = stránka je na webu vidět (jen na výslovný pokyn uživatele), jinak skrytá'],
        ];
        $nastroje = [
            ['info_o_webu', 'Název webu, úvodní stránka, šablona, počty stránek a novinek, role přihlášeného uživatele a jeho oprávnění.', $s([])],
            ['seznam_stranek', 'Stránky webu (Úvod, O nás, Služby, Kontakt…) s adresami.', $s([])],
            ['nacti_stranku', 'Celá stránka včetně HTML obsahu.', $s(['id' => $cislo('ID stránky')], ['id'])],
            ['vytvor_stranku', 'Založí stránku (editor a správce). Bez "zobrazit": true zůstane skrytá.', $s($stranka, ['titulek'])],
            ['uprav_stranku', 'Změní zadaná pole stránky; ostatní ponechá.', $s(['id' => $cislo('ID stránky')] + $stranka, ['id'])],
            ['seznam_novinek', 'Seznam novinek (nejnovější první).', $s(['stav' => $text('vse | vydane | plan | koncepty'), 'kategorie' => $text('název nebo adresa kategorie'), 'hledat' => $text('text v titulku'), 'limit' => $cislo('1-50, výchozí 20')])],
            ['nacti_novinku', 'Celá novinka včetně textu a štítků.', $s(['id' => $cislo('ID novinky (idc)')], ['id'])],
            ['vytvor_novinku', 'Založí novinku. Bez "vydat": true vznikne koncept.', $s($novinka, ['titulek', 'kategorie'])],
            ['uprav_novinku', 'Změní zadaná pole novinky; ostatní ponechá. Předchozí verze se uloží do historie.', $s(['id' => $cislo('ID novinky')] + $novinka, ['id'])],
            ['seznam_kategorii', 'Kategorie novinek s počty.', $s([])],
            ['vytvor_kategorii', 'Založí kategorii novinek (editor a správce).', $s(['nazev' => $text('Název'), 'popis' => $text('Popis (HTML)')], ['nazev'])],
            ['seznam_medii', 'Naposledy nahrané obrázky s adresami a rozměry.', $s(['limit' => $cislo('1-50, výchozí 20')])],
            ['seznam_sablon', 'Šablony webu (layouty), která je aktivní a které jdou upravovat (správce).', $s([])],
            ['vytvor_sablonu', 'Zkopíruje existující šablonu pod novým názvem, aby se dala upravovat (správce).', $s(['nazev' => $text('složka nové šablony: malá písmena, číslice, pomlčky'), 'podle' => $text('zdrojová šablona, výchozí ' . Layouty::VYCHOZI), 'popisny_nazev' => $text('název pro výběr ve Vzhledu')], ['nazev'])],
            ['nacti_soubor_sablony', 'Přečte soubor šablony (base.php, novinka.php, vypis.php, stranka.php, style.css, info.php…).', $s(['sablona' => $text('složka šablony'), 'soubor' => $text('název souboru; bez něj vrátí seznam souborů')], ['sablona'])],
            ['uloz_soubor_sablony', 'Uloží soubor vlastní šablony (.php nebo .css). PHP se před uložením kontroluje. Vestavěné šablony upravit nejde.', $s(['sablona' => $text('složka šablony'), 'soubor' => $text('název souboru'), 'obsah' => $text('celý nový obsah souboru')], ['sablona', 'soubor', 'obsah'])],
            ['aktivuj_sablonu', 'Přepne web na danou šablonu (správce). Před tím ji ukaž uživateli v náhledu: adresa webu s ?sablona=<složka> funguje přihlášenému správci.', $s(['sablona' => $text('složka šablony')], ['sablona'])],
        ];

        return array_map(fn (array $n): array => ['name' => $n[0], 'description' => $n[1], 'inputSchema' => $n[2]], $nastroje);
    }

    public function meni(string $nazev): bool
    {
        return in_array($nazev, ['vytvor_stranku', 'uprav_stranku', 'vytvor_novinku', 'uprav_novinku', 'vytvor_kategorii', 'vytvor_sablonu', 'uloz_soubor_sablony', 'aktivuj_sablonu'], true);
    }

    /** @param array<string, mixed> $a */
    public function zavolej(string $nazev, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $web = $this->app->settings();
        $jenAdmin = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        switch ($nazev) {
            case 'info_o_webu':
                return [
                    'web' => $web->get('nazev_webu'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $web->get('popis_webu'),
                    'sablona' => $web->get('layout'), 'uvodni_stranka' => $web->int('titulni_stranka') ?: null, 'verze_mirocms' => MIROCMS_VERSION,
                    'stranek' => (int) $db->value('SELECT COUNT(*) FROM {stranky}'),
                    'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL'),
                    'uzivatel' => $auth->user()['user'], 'role' => \MiroCMS\Core\Auth::TYPY[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->smiVydavat(),
                    'smi_upravovat_stranky' => $auth->maModul('stranky'),
                ];

            case 'seznam_stranek':
                $uvod = $web->int('titulni_stranka');

                return array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'titulek' => $r['titulek'], 'adresa' => $this->app->request->origin() . $this->app->url((int) $r['ids'] === $uvod ? '' : $r['seo_link']),
                    'uvodni' => (int) $r['ids'] === $uvod, 'zobrazena' => (bool) $r['zobrazit'], 'v_menu' => (bool) $r['v_menu'], 'jazyk' => $r['jazyk']],
                    $db->all('SELECT ids, titulek, seo_link, zobrazit, v_menu, jazyk FROM {stranky} ORDER BY jazyk, poradi, titulek'));

            case 'nacti_stranku':
                return $this->stranka((int) ($a['id'] ?? 0));

            case 'vytvor_stranku':
            case 'uprav_stranku':
                if (!$auth->maModul('stranky')) {
                    throw new \DomainException('Stránky smí upravovat editor nebo správce.');
                }

                return $this->ulozStranku($nazev === 'uprav_stranku' ? $this->stranka((int) ($a['id'] ?? 0)) : null, $a);

            case 'seznam_novinek':
                $where = ['c.smazano IS NULL']; // koš se přes MCP nevypisuje ani needituje
                $p = [];
                if (($autori = $auth->spravovaniAutori()) !== null) {
                    $where[] = 'c.autor IN (' . implode(',', $autori) . ')';
                }
                $stavy = ['vydane' => 'c.visible = 1 AND c.datum <= NOW()', 'plan' => 'c.visible = 1 AND c.datum > NOW()', 'koncepty' => 'c.visible = 0'];
                if (isset($stavy[$a['stav'] ?? ''])) {
                    $where[] = $stavy[$a['stav']];
                }
                if (!empty($a['kategorie'])) {
                    $where[] = 'c.tema = ?';
                    $p[] = $this->kategorie((string) $a['kategorie']);
                }
                if (!empty($a['hledat'])) {
                    $where[] = 'c.titulek LIKE ?';
                    $p[] = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
                }

                return $db->all(
                    'SELECT c.idc AS id, c.titulek, c.seo_link, t.nazev AS kategorie, c.datum, c.visible AS vydana
                     FROM {clanky} c JOIN {topic} t ON t.idt = c.tema WHERE ' . implode(' AND ', $where) . ' ORDER BY c.datum DESC LIMIT ?',
                    [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
                );

            case 'nacti_novinku':
                $c = $this->novinka((int) ($a['id'] ?? 0));

                return array_intersect_key($c, array_flip(['idc', 'titulek', 'seo_link', 'uvod', 'text', 'obrazek', 'obrazek_popis', 'datum', 'visible', 'faq', 'seo_titulek', 'seo_popis']))
                    + ['kategorie' => $db->value('SELECT nazev FROM {topic} WHERE idt = ?', [$c['tema']]),
                        'stitky' => array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {clanky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
                        'adresa' => $this->app->request->origin() . $this->app->url('novinky/' . $c['seo_link'])];

            case 'vytvor_novinku':
            case 'uprav_novinku':
                return $this->ulozNovinku($nazev === 'uprav_novinku' ? $this->novinka((int) ($a['id'] ?? 0)) : null, $a);

            case 'seznam_kategorii':
                return array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'jazyk' => $r['jazyk'], 'novinek' => (int) $r['pocet_clanku']], Kategorie::seznam($db));

            case 'vytvor_kategorii':
                if (!$auth->smiVydavat()) {
                    throw new \DomainException('Kategorie smí zakládat editor nebo správce.');
                }
                $jmeno = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($jmeno === '') {
                    throw new \InvalidArgumentException('Chybí název kategorie.');
                }
                $seo = $this->volnaAdresa('topic', 'idt', slugify($jmeno, 110));

                return ['id' => $db->insert('topic', ['nazev' => $jmeno, 'seo_link' => $seo, 'popis' => (string) ($a['popis'] ?? '')]), 'adresa' => $seo];

            case 'seznam_medii':
                return array_map(fn (array $o): array => ['id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'adresa' => $this->app->url($o['obr_poloha']), 'rozmery' => $o['obr_width'] . '×' . $o['obr_height']],
                    $db->all('SELECT * FROM {imggal_obr} ORDER BY ido DESC LIMIT ?', [max(1, min(50, (int) ($a['limit'] ?? 20)))]));

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

                return 'Web nyní používá šablonu ' . $slozka . '.';
        }
        throw new \InvalidArgumentException('Neznámý nástroj: ' . $nazev);
    }

    /**
     * @param array<string, mixed>|null $puvodni
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function ulozNovinku(?array $puvodni, array $a): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if ($puvodni !== null && $puvodni['visible'] && !$auth->smiVydavat()) {
            throw new \DomainException('Vydanou novinku může upravit jen editor nebo správce.');
        }
        $data = [];
        foreach (['titulek' => 255, 'uvod' => 0, 'text' => 0, 'faq' => 0, 'seo_titulek' => 255, 'seo_popis' => 320, 'obrazek' => 255, 'obrazek_popis' => 300] as $pole => $max) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = $max > 0 ? mb_substr((string) $a[$pole], 0, $max) : (string) $a[$pole];
            }
        }
        if (array_key_exists('kategorie', $a)) {
            $data['tema'] = $this->kategorie((string) $a['kategorie']);
            // novinka přebírá jazykovou verzi kategorie – stejně jako při uložení v administraci
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
                throw new \DomainException('Uživatel nemá právo vydávat – novinku lze uložit jen jako koncept.');
            }
            $data['visible'] = (int) (bool) $a['vydat'];
        }
        if (($data['titulek'] ?? $puvodni['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Novinka musí mít titulek.');
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');

        if ($puvodni === null) {
            if (!isset($data['tema'])) {
                throw new \InvalidArgumentException('Chybí kategorie.');
            }
            $data += ['uvod' => '', 'text' => '', 'autor' => $auth->id(), 'datum' => date('Y-m-d H:i:s'), 'visible' => 0,
                'seo_link' => $this->volnaAdresa('clanky', 'idc', slugify($data['titulek'], 150))];
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
        $ulozena = $db->one('SELECT * FROM {clanky} WHERE idc = ?', [$id]);
        Galerie::zapisPouziti($db, $id, $ulozena['obrazek'], $ulozena['uvod'], $ulozena['text']);

        return ['id' => $id, 'stav' => !$ulozena['visible'] ? 'koncept' : (strtotime($ulozena['datum']) > time() ? 'naplánováno' : 'vydáno'),
            'nahled' => $this->app->request->origin() . $this->app->url('novinky/' . $ulozena['seo_link'] . '?nahled=1'),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?modul=novinky&akce=edit&id=' . $id)];
    }

    /**
     * @param array<string, mixed>|null $puvodni
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function ulozStranku(?array $puvodni, array $a): array
    {
        $db = $this->app->db();
        $data = [];
        foreach (['titulek' => 200, 'text' => 0, 'popis' => 300] as $pole => $max) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = $max > 0 ? mb_substr((string) $a[$pole], 0, $max) : (string) $a[$pole];
            }
        }
        foreach (['v_menu', 'zobrazit'] as $pole) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = (int) (bool) $a[$pole];
            }
        }
        if (array_key_exists('poradi', $a)) {
            $data['poradi'] = max(0, min(65535, (int) $a['poradi']));
        }
        if (($data['titulek'] ?? $puvodni['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Stránka musí mít název.');
        }
        if (array_key_exists('adresa', $a) || $puvodni === null) {
            $seo = slugify((string) (($a['adresa'] ?? '') !== '' ? $a['adresa'] : $data['titulek']), 110);
            if (in_array($seo, Stranky::VYHRAZENE, true) || isset(\MiroCMS\Core\Jazyk::DOSTUPNE[$seo])) {
                throw new \InvalidArgumentException('Adresu „' . $seo . '“ používá systém, zvol jinou.');
            }
            if ($db->value('SELECT ids FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$seo, (int) ($puvodni['ids'] ?? 0)]) !== null) {
                throw new \InvalidArgumentException('Stránka s adresou „' . $seo . '“ už existuje.');
            }
            $data['seo_link'] = $seo;
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');
        if ($puvodni === null) {
            $id = $db->insert('stranky', $data + ['text' => '', 'zobrazit' => 0, 'v_menu' => 0]);
        } else {
            $id = (int) $puvodni['ids'];
            $db->update('stranky', $data, ['ids' => $id]);
            if (isset($data['seo_link']) && $data['seo_link'] !== $puvodni['seo_link'] && $puvodni['zobrazit']) {
                \MiroCMS\Admin\Moduly\Presmerovani::pridej($db, $puvodni['seo_link'], $data['seo_link']);
            }
        }
        $ulozena = $this->stranka($id);

        return ['id' => $id, 'stav' => $ulozena['zobrazit'] ? 'zveřejněná' : 'skrytá',
            'adresa' => $this->app->request->origin() . $this->app->url($ulozena['seo_link']),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?modul=stranky&akce=edit&id=' . $id)];
    }

    /** @return array<string, mixed> */
    private function stranka(int $id): array
    {
        $stranka = $this->app->db()->one('SELECT ids, titulek, seo_link, popis, text, zobrazit, v_menu, poradi, jazyk FROM {stranky} WHERE ids = ?', [$id]);
        if ($stranka === null) {
            throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
        }

        return $stranka;
    }

    /** @return array<string, mixed> novinka, ke které má uživatel přístup */
    private function novinka(int $id): array
    {
        $novinka = $this->app->db()->one('SELECT * FROM {clanky} WHERE idc = ? AND smazano IS NULL', [$id]);
        $autori = $this->app->auth()->spravovaniAutori();
        if ($novinka === null || ($autori !== null && !in_array((int) $novinka['autor'], $autori, true))) {
            throw new \InvalidArgumentException('Novinka neexistuje nebo k ní uživatel nemá přístup.');
        }

        return $novinka;
    }

    private function kategorie(string $nazevNeboAdresa): int
    {
        $idt = $this->app->db()->value('SELECT idt FROM {topic} WHERE seo_link = ? OR nazev = ? LIMIT 1', [$nazevNeboAdresa, $nazevNeboAdresa]);
        if ($idt === null) {
            throw new \InvalidArgumentException('Kategorie „' . $nazevNeboAdresa . '“ neexistuje. Použij nástroj seznam_kategorii.');
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
