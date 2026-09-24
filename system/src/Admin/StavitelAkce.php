<?php

declare(strict_types=1);

namespace MiroCMS\Admin;

use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Response;
use MiroCMS\Stavitel\DesignSystem;
use MiroCMS\Stavitel\Knihovna;
use MiroCMS\Stavitel\Kolekce;
use MiroCMS\Stavitel\Publikace;
use MiroCMS\Stavitel\Stavba;
use MiroCMS\Stavitel\Styl;

/**
 * Akce stavitele společné pro stránky (Moduly\Stranky) a části webu (Moduly\Casti): editor, průběžné ukládání konceptu,
 * publikování, zahození změn, verze, sekce z knihovny a sdílené třídy. Modul dodá, co je „cíl“ stavby a jak ho uložit.
 *
 * Cíl: ['radek' => řádek z databáze, 'stavba' => ?string, 'koncept' => ?string, 'jazyk' => kód obsahu, 'titulek' => string,
 *       'revize' => ['ids' => …] | ['cast' => …], 'parametry' => parametry adres akcí (id nebo typ a jazyk)]
 */
trait StavitelAkce
{
    /** @return array<string, mixed>|null cíl z parametrů požadavku */
    abstract protected function cilStavby(): ?array;

    /** Uloží rozpracovaný koncept (null = zahodit). */
    abstract protected function ulozKoncept(array $cil, ?string $koncept): void;

    abstract protected function publikujCil(array $cil): void;

    /**
     * Údaje pro editor: titulek, adresa (veřejná), nahled (plátno), zobrazena, casti (nabízet prvky částí), zpet [adresa, text],
     * nastaveni (adresa nastavení cíle, nebo null).
     *
     * @return array<string, mixed>
     */
    abstract protected function editorCile(array $cil): array;

    /** Editor stavby na celou obrazovku: plátno se skutečnou stránkou webu, strom, vlastnosti. */
    protected function akceStavitel(): Response
    {
        $cil = $this->cilStavby();
        if ($cil === null) {
            return $this->chyba('Stránka neexistuje.', 404);
        }
        $app = $this->app;
        $e = $this->editorCile($cil);
        $kolekce = array_map(fn (array $k): array => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']], Kolekce::vsechny($this->db));
        $schema = Stavba::schema($app->auth()->isAdmin(), $cil['jazyk'], $e['casti']);
        $komponenty = \MiroCMS\Admin\Moduly\Komponenty::proEditor($this->db);
        foreach ($schema['prvky'] as &$prvek) {
            if ($prvek['typ'] === 'komponenta') {
                $prvek['vlastnosti']['komponenta'] = ['typ' => 'vyber', 'popisek' => 'Komponenta', 'vychozi' => '',
                    'moznosti' => ['' => '—'] + array_column(array_map(fn (array $k): array => ['id' => (string) $k['id'], 'nazev' => $k['nazev']], $komponenty), 'nazev', 'id')];
            }
            if ($prvek['typ'] === 'kolekce') {
                // v editoru výběr z kolekcí webu (validátor bere adresu kolekce jako text)
                $prvek['vlastnosti']['kolekce'] = ['typ' => 'vyber', 'popisek' => 'Kolekce', 'vychozi' => $kolekce[0]['seo_link'] ?? '',
                    'moznosti' => ['' => '—'] + array_column($kolekce, 'nazev', 'seo_link')];
            }
        }
        unset($prvek);
        $schema = self::prelozSchema($schema);
        Knihovna::zalozTridy($this->db, ['karta']); // vzor karty ve Výpisu kolekce
        $data = [
            'stranka' => ['titulek' => $cil['titulek'], 'adresa' => $e['adresa'], 'zobrazena' => $e['zobrazena'], 'publikovana' => $cil['stavba'] !== null,
                'nadpisy' => (bool) ($e['nadpisy'] ?? false)], // kontrola před publikováním: stránka má mít jeden h1 a nepřeskakovat úrovně
            'stavba' => Stavba::zJson($cil['koncept'] ?? $cil['stavba']),
            'zmeny' => $cil['koncept'] !== null && $cil['koncept'] !== $cil['stavba'],
            'verze' => self::verzeStavby($cil['koncept'] ?? $cil['stavba']),
            'schema' => $schema,
            'kolekce' => $kolekce,
            'komponenty' => $komponenty,
            'ai' => (new \MiroCMS\Core\Asistent($app->settings()))->pripraven(),
            'kolekceDetailu' => $e['kolekce'] ?? null,
            'knihovna' => Knihovna::seznam(),
            'kategorieKnihovny' => array_map(fn (string $k): string => t($k), Knihovna::KATEGORIE),
            'tridy' => $this->tridyStavitele(),
            'mojeSekce' => self::mojeSekce($this->db),
            'barvy' => DesignSystem::nacti($app->settings())['barvy'],
            // nabídka pro pole odkazu: stránky webu (s jazykovou předponou) a novinky; kotvy na stránce doplní editor
            'odkazy' => [...array_map(fn (array $s): array => ['/' . ($s['jazyk'] !== '' ? $s['jazyk'] . '/' : '') . ((int) $s['ids'] === $app->settings()->int('titulni_stranka') ? '' : $s['seo_link']), $s['titulek'] . ($s['zobrazit'] ? '' : ' (' . t('skrytá') . ')')],
                $this->db->all('SELECT ids, titulek, seo_link, jazyk, zobrazit FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek LIMIT 300')), ['/novinky', t('Novinky')]],
            'nahled' => $e['nahled'],
            'zpet' => $e['zpet'],
            'adresy' => array_map(fn (string $akce): string => $this->url($akce, $cil['parametry']), [
                'uloz' => 'stavba_uloz', 'publikuj' => 'stavba_publikuj', 'zahod' => 'stavba_zahod', 'sekce' => 'stavba_sekce', 'trida' => 'stavba_trida',
                'revize' => 'stavba_revize', 'obnov' => 'stavba_obnov', 'aiSekce' => 'stavba_ai_sekce', 'aiText' => 'stavba_ai_text', 'ulozSekci' => 'stavba_uloz_sekci',
            ]) + ['smazSekci' => $app->auth()->isAdmin() ? $this->url('stavba_smaz_sekci', $cil['parametry']) : null] + ['admin' => $app->url('admin.php'), 'nastaveni' => $e['nastaveni'],
                'komponenta' => $app->auth()->isAdmin() ? $app->url('admin.php?modul=komponenty&akce=z_prvku') : null,
                'nahledSekce' => $app->url('_sekce/')],
        ];

        return Response::html($app->view->render('admin/stranky/stavitel', ['app' => $app, 'data' => $data, 'titulek' => $cil['titulek']]));
    }

    /** Průběžné ukládání konceptu z editoru (JSON). Vrací vyčištěnou stavbu a chyby, které editor ukáže. */
    protected function akceStavbaUloz(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        if ($cil === null) {
            return Response::json(['ok' => false, 'chyba' => t('Stránka neexistuje.')], 404);
        }
        $vstup = json_decode((string) ($_POST['stavba'] ?? ''), true);
        if (!is_array($vstup)) {
            return Response::json(['ok' => false, 'chyba' => t('Stavba nemá platný tvar JSON.')], 400);
        }
        if (($konflikt = $this->konfliktVerze($cil)) !== null) {
            return $konflikt;
        }
        [$stavba, $chyby] = Stavba::vycisti($vstup, $this->app->auth()->isAdmin(), Stavba::zJson($cil['koncept'] ?? $cil['stavba']));
        $json = Stavba::naJson($stavba);
        $this->ulozKoncept($cil, $json);

        return Response::json(['ok' => true, 'stavba' => $stavba, 'chyby' => $chyby, 'zmeny' => $json !== $cil['stavba'], 'verze' => self::verzeStavby($json)]);
    }

    /** Publikování: koncept se stane stavbou; předchozí publikovaná verze jde do historie. */
    protected function akceStavbaPublikuj(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        if ($cil === null || ($cil['koncept'] ?? $cil['stavba']) === null) {
            return Response::json(['ok' => false, 'chyba' => t('Není co publikovat.')], 400);
        }
        // publikuje se jen to, co editor naposledy uložil – ne starší koncept, ani cizí rozpracované změny
        if (($konflikt = $this->konfliktVerze($cil)) !== null) {
            return $konflikt;
        }
        $this->publikujCil($cil);
        Protokol::zapis($this->app, static::IDENT, 'publikování stavby', mb_substr($cil['titulek'], 0, 80));

        return Response::json(['ok' => true]);
    }

    /** Zahodí rozpracované změny: editor se vrátí k publikované stavbě. */
    protected function akceStavbaZahod(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        if ($cil === null || $cil['stavba'] === null) {
            return Response::json(['ok' => false, 'chyba' => t('Zatím není publikovaná verze – není k čemu se vrátit.')], 400);
        }
        $this->ulozKoncept($cil, null);

        return Response::json(['ok' => true, 'stavba' => Stavba::zJson($cil['stavba']), 'verze' => self::verzeStavby($cil['stavba'])]);
    }

    /** Sekce z knihovny jako nové prvky (JSON) v jazyce cíle; chybějící třídy, které používá, se založí. */
    protected function akceStavbaSekce(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        $sekce = $cil !== null ? Knihovna::sekci($this->request->get('klic'), $cil['jazyk']) : null;
        if ($sekce === null) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce v knihovně není.')], 404);
        }
        Knihovna::zalozTridy($this->db, $sekce['tridy']);

        return Response::json(['ok' => true, 'prvek' => $sekce['prvek'], 'tridy' => $this->tridyStavitele()]);
    }

    /** @return list<array{id:int, nazev:string, prvek:array<string, mixed>}> vlastní sekce webu (panel Přidat → Moje sekce) */
    public static function mojeSekce(\MiroCMS\Core\Db $db): array
    {
        return array_values(array_filter(array_map(fn (array $r): ?array => is_array($p = json_decode((string) $r['prvek'], true)) ? ['id' => (int) $r['idx'], 'nazev' => $r['nazev'], 'prvek' => $p] : null,
            $db->all('SELECT idx, nazev, prvek FROM {sekce} ORDER BY nazev LIMIT 200'))));
    }

    /** Uloží vybraný prvek do vlastní knihovny sekcí (projde validátorem jako každá stavba). */
    protected function akceStavbaUlozSekci(): Response
    {
        $nazev = mb_substr(trim($this->request->post('nazev')), 0, 100);
        $prvek = json_decode((string) ($_POST['prvek'] ?? ''), true);
        if (!$this->request->isPost() || $nazev === '' || !is_array($prvek)) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce potřebuje název.')], 400);
        }
        [$stavba] = Stavba::vycisti(['deti' => [$prvek]], $this->app->auth()->isAdmin());
        if (($stavba['deti'][0] ?? null) === null) {
            return Response::json(['ok' => false, 'chyba' => t('Prvek se nepodařilo uložit.')], 400);
        }
        $this->db->insert('sekce', ['nazev' => $nazev, 'prvek' => (string) json_encode($stavba['deti'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'zmeneno' => date('Y-m-d H:i:s')]);
        Protokol::zapis($this->app, static::IDENT, 'uložení sekce do knihovny', $nazev);

        return Response::json(['ok' => true, 'sekce' => self::mojeSekce($this->db)]);
    }

    protected function akceStavbaSmazSekci(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce smí odebrat jen správce.')], 403);
        }
        $this->db->delete('sekce', ['idx' => $this->request->postInt('idx')]);

        return Response::json(['ok' => true, 'sekce' => self::mojeSekce($this->db)]);
    }

    /** Uložení nebo smazání sdílené třídy (JSON). */
    protected function akceStavbaTrida(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $nazev = $this->request->post('nazev');
        if (!preg_match(Stavba::VZOR_TRIDA, $nazev)) {
            return Response::json(['ok' => false, 'chyba' => t('Název třídy: malá písmena bez diakritiky, číslice a pomlčky (např. karta, karta--zvyraznena).')], 400);
        }
        if ($this->request->post('pouziti') === '1') {
            return Response::json(['ok' => true, 'pouziti' => $this->pouzitiTridy($nazev)]);
        }
        if (($this->request->post('novy_nazev') !== '' || $this->request->post('smazat') === '1') && !$this->app->auth()->isAdmin()) {
            return Response::json(['ok' => false, 'chyba' => t('Třídu smí přejmenovat nebo smazat jen správce – mění vzhled celého webu.')], 403);
        }
        if (($novy = $this->request->post('novy_nazev')) !== '') {
            // přejmenování: řádek třídy i všechny stavby, které ji používají (stránky, části, šablony kolekcí, komponenty, moje sekce)
            if (!preg_match(Stavba::VZOR_TRIDA, $novy) || $this->db->value('SELECT 1 FROM {tridy} WHERE nazev = ?', [$novy]) !== null) {
                return Response::json(['ok' => false, 'chyba' => t('Nový název musí být volný a psaný malými písmeny bez diakritiky (např. karta-velka).')], 400);
            }
            $this->db->update('tridy', ['nazev' => $novy, 'zmeneno' => date('Y-m-d H:i:s')], ['nazev' => $nazev]);
            foreach (self::ZDROJE_STAVEB as $tabulka => [$klic, $sloupce]) {
                foreach ($this->db->all('SELECT ' . $klic . ', ' . implode(', ', $sloupce) . ' FROM {' . $tabulka . '} WHERE ' . implode(' OR ', array_map(fn (string $s): string => $s . ' LIKE ?', $sloupce)), array_fill(0, count($sloupce), '%"' . $nazev . '"%')) as $r) {
                    $zmena = [];
                    foreach ($sloupce as $s) {
                        if ($r[$s] !== null && str_contains($r[$s], '"' . $nazev . '"')) {
                            $zmena[$s] = self::prejmenujTridu((string) $r[$s], $nazev, $novy);
                        }
                    }
                    $this->db->update($tabulka, $zmena, [$klic => $r[$klic]]);
                }
            }
            \MiroCMS\Front\Cache::vymaz();

            return Response::json(['ok' => true, 'tridy' => $this->tridyStavitele(), 'nazev' => $novy]);
        }
        if ($this->request->post('smazat') === '1') {
            $this->db->delete('tridy', ['nazev' => $nazev]);
        } else {
            $chyby = [];
            $zahozeno = [];
            $styl = Styl::vycisti(json_decode((string) ($_POST['styl'] ?? ''), true), $nazev, $chyby);
            $css = Styl::vlastniCss($this->request->post('css'), $zahozeno);
            $this->db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = NOW()',
                [$nazev, (string) json_encode($styl ?: new \stdClass(), JSON_UNESCAPED_UNICODE), $css]);
            if ($chyby !== [] || $zahozeno !== []) {
                \MiroCMS\Front\Cache::vymaz(); // platná část třídy se uložila – web ji musí vidět
                return Response::json(['ok' => true, 'tridy' => $this->tridyStavitele(), 'chyby' => $chyby + array_map(fn (string $d): string => t('Nepovolená deklarace: %s', $d), $zahozeno)]);
            }
        }
        \MiroCMS\Front\Cache::vymaz();

        return Response::json(['ok' => true, 'tridy' => $this->tridyStavitele()]);
    }

    /** Tabulky se stavbami: tabulka => [klíč, sloupce se stavbou JSON]. */
    private const array ZDROJE_STAVEB = [
        'stranky' => ['ids', ['stavba', 'stavba_koncept']], 'casti' => ['typ', ['stavba', 'stavba_koncept']], 'kolekce' => ['idk', ['stavba', 'stavba_koncept']],
        'komponenty' => ['idm', ['stavba', 'stavba_koncept']], 'sekce' => ['idx', ['prvek']],
    ];

    /** Přejmenuje třídu v poli „tridy“ všech prvků stavby (JSON) – jiné výskyty textu zůstanou. */
    private static function prejmenujTridu(string $json, string $stary, string $novy): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $projdi = function (array $x) use (&$projdi, $stary, $novy): array {
            if (isset($x['tridy']) && is_array($x['tridy'])) {
                $x['tridy'] = array_map(fn (mixed $t): mixed => $t === $stary ? $novy : $t, $x['tridy']);
            }
            foreach ($x as $k => $v) {
                if (is_array($v)) {
                    $x[$k] = $projdi($v);
                }
            }

            return $x;
        };

        return (string) json_encode($projdi($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> kde je třída použitá (názvy stránek, částí, kolekcí, komponent, mých sekcí) */
    private function pouzitiTridy(string $nazev): array
    {
        $vzor = '%"tridy":[%"' . addcslashes($nazev, '%_\\') . '"%';
        $kde = [];
        foreach ($this->db->all('SELECT titulek FROM {stranky} WHERE smazano IS NULL AND (stavba LIKE ? OR stavba_koncept LIKE ?)', [$vzor, $vzor]) as $r) {
            $kde[] = t('stránka') . ' ' . $r['titulek'];
        }
        foreach ($this->db->all('SELECT typ, nazev FROM {casti} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', [$vzor, $vzor]) as $r) {
            $kde[] = t('část webu') . ' ' . ($r['nazev'] !== '' ? $r['nazev'] : $r['typ']);
        }
        foreach ([['kolekce', 'nazev', 'kolekce'], ['komponenty', 'nazev', 'komponenta']] as [$tabulka, $sloupec, $druh]) {
            foreach ($this->db->all('SELECT ' . $sloupec . ' AS n FROM {' . $tabulka . '} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', [$vzor, $vzor]) as $r) {
                $kde[] = t($druh) . ' ' . $r['n'];
            }
        }

        return array_values(array_unique($kde));
    }

    /** Publikované verze (JSON pro dialog Verze). */
    protected function akceStavbaRevize(): Response
    {
        $cil = $this->cilStavby();

        return Response::json(['revize' => $cil === null ? [] : Publikace::seznam($this->db, $cil['revize'])]);
    }

    /** Starší verze se načte do konceptu; publikuje se až tlačítkem Publikovat. */
    protected function akceStavbaObnov(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        $stavba = $cil !== null ? Publikace::nacti($this->db, $cil['revize'], $this->request->postInt('idr')) : null;
        if ($stavba === null) {
            return Response::json(['ok' => false, 'chyba' => t('Verze neexistuje.')], 404);
        }
        $this->ulozKoncept($cil, $stavba);

        return Response::json(['ok' => true, 'stavba' => Stavba::zJson($stavba), 'verze' => self::verzeStavby($stavba)]);
    }

    /** Otisk obsahu, který editor naposledy viděl na serveru (koncept, jinak publikovaná stavba). */
    private static function verzeStavby(?string $json): string
    {
        return substr(md5((string) $json), 0, 16);
    }

    /**
     * Ochrana před přepsáním cizích změn: editor posílá otisk verze, ze které vychází. Když se koncept mezitím změnil
     * (jiný editor, Claude přes MCP, druhá záložka), odmítne se a editor nabídne načíst novější, nebo přepsat.
     */
    private function konfliktVerze(array $cil): ?Response
    {
        $verze = $this->request->post('verze');
        $aktualni = self::verzeStavby($cil['koncept'] ?? $cil['stavba']);
        if ($verze === '' || $this->request->post('prepsat') === '1' || hash_equals($aktualni, $verze)) {
            return null;
        }

        return Response::json(['ok' => false, 'konflikt' => true, 'verze' => $aktualni, 'stavba' => Stavba::zJson($cil['koncept'] ?? $cil['stavba']),
            'chyba' => t('Stránku mezitím upravil někdo jiný (nebo jste ji otevřeli v jiném okně).')], 409);
    }

    /**
     * Popisky schématu (názvy prvků, polí, vlastností stylu a jejich voleb) do jazyka administrace. Výchozí obsah prvků
     * se nepřekládá – je v jazyce stránky (Stavba::schema).
     */
    private static function prelozSchema(array $schema): array
    {
        $pole = function (array $vlastnosti) use (&$pole): array {
            foreach ($vlastnosti as $klic => $d) {
                $vlastnosti[$klic]['popisek'] = t((string) ($d['popisek'] ?? ''));
                if (isset($d['moznosti'])) {
                    $vlastnosti[$klic]['moznosti'] = array_map(fn (string $m): string => t($m), $d['moznosti']);
                }
                if (isset($d['pole'])) {
                    $vlastnosti[$klic]['pole'] = $pole($d['pole']);
                }
            }

            return $vlastnosti;
        };
        foreach ($schema['prvky'] as $i => $p) {
            $schema['prvky'][$i] = ['nazev' => t($p['nazev']), 'popis' => t($p['popis']), 'skupina' => t($p['skupina']), 'vlastnosti' => $pole($p['vlastnosti'])] + $p;
        }
        $schema['styl'] = $pole($schema['styl']);
        $schema['skupiny_stylu'] = array_map(fn (string $s): string => t($s), $schema['skupiny_stylu']);

        return $schema;
    }

    /** AI asistent: nová sekce podle popisu (JSON s prvky k vložení). */
    protected function akceStavbaAiSekce(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        $asistent = new \MiroCMS\Core\Asistent($this->app->settings());
        if ($cil === null || !$asistent->pripraven()) {
            return Response::json(['ok' => false, 'chyba' => t('AI asistent není zapnutý (Rozšíření).')], 400);
        }
        try {
            $html = \MiroCMS\Core\Jazyk::docasne($cil['jazyk'], fn (): string => $asistent->navrhniSekci($this->request->post('zadani'), $cil['jazyk'], $cil['titulek']));
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 502);
        }
        ['stavba' => $stavba, 'hlaseni' => $hlaseni] = \MiroCMS\Stavitel\ZHtml::doWebu($this->db, $html, false);
        [$cista] = Stavba::vycisti($stavba, $this->app->auth()->isAdmin());
        if ($cista['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('Asistent nevrátil použitelnou sekci. Zkuste popis upřesnit.')], 502);
        }

        return Response::json(['ok' => true, 'prvky' => $cista['deti'], 'tridy' => $this->tridyStavitele(), 'hlaseni' => $hlaseni]);
    }

    /** AI asistent: přepis textu prvku (kratší, delší, formálněji…). Nic neukládá – editor text vloží jako běžnou změnu. */
    protected function akceStavbaAiText(): Response
    {
        $asistent = new \MiroCMS\Core\Asistent($this->app->settings());
        if (!$this->request->isPost() || !$asistent->pripraven()) {
            return Response::json(['ok' => false, 'chyba' => t('AI asistent není zapnutý (Rozšíření).')], 400);
        }
        try {
            $text = $asistent->prepis((string) ($_POST['text'] ?? ''), $this->request->post('pokyn'), $this->request->post('html') === '1');
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 502);
        }

        return Response::json(['ok' => true, 'text' => $text]);
    }

    /** @return array<string, array{styl: array<string, mixed>|\stdClass, css: string}> */
    protected function tridyStavitele(): array
    {
        $tridy = [];
        foreach ($this->db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev') as $r) {
            $tridy[$r['nazev']] = ['styl' => json_decode((string) $r['styl'], true) ?: new \stdClass(), 'css' => (string) $r['css']];
        }

        return $tridy;
    }

    protected function jazykObsahu(string $sloupec): string
    {
        return Jazyk::obsahu($this->app->settings(), $sloupec);
    }
}
