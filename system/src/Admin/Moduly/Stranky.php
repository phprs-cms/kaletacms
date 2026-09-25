<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Response;
use Kaleta\Stavitel\Publikace;
use Kaleta\Stavitel\Stavba;

/**
 * Stránky webu: úvod, O nás, Služby, Kontakt, Zásady ochrany soukromí… Úvodní stránku určuje Nastavení → Základní.
 * Stránka má adresu /<seo_link>. Obsah je buď text z editoru, nebo stavba z builderu (sloupec stavba, rozpracovaná stavba_koncept).
 */
final class Stranky extends Modul
{
    use \Kaleta\Admin\StavitelAkce {
        akceStavitel as protected editorStavby;
    }

    public const string IDENT = 'stranky';
    public const string NAZEV = 'Stránky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'stranky';

    /** Adresy, které patří systému a stránka je mít nemůže. */
    public const array VYHRAZENE = ['novinky', 'hledani', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'stav', 'ulohy', 'souhlas', 'formular'];

    /** Stránky v koši vydrží tolik dní, pak se smažou natrvalo (jako novinky). */
    public const int DNY_V_KOSI = 30;

    protected function akceVypis(): Response
    {
        $kos = $this->request->get('stav') === 'kos';
        $hledat = mb_substr(trim($this->request->get('hledat')), 0, 100);
        $where = [$kos ? 'smazano IS NOT NULL' : 'smazano IS NULL'];
        $params = [];
        if ($hledat !== '') {
            $where[] = '(titulek LIKE ? OR seo_link LIKE ?)';
            $vzor = '%' . addcslashes($hledat, '%_\\') . '%';
            array_push($params, $vzor, $vzor);
        }

        $stranky = $this->db->all('SELECT * FROM {stranky} WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($kos ? 'smazano DESC' : 'jazyk, poradi, titulek'), $params);

        return $this->view('vypis', 'Stránky', [
            'stranky' => $kos || $hledat !== '' ? $stranky : self::stromem($stranky),
            'kos' => $kos, 'hledat' => $hledat,
            'vKosi' => (int) $this->db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NOT NULL'),
        ]);
    }

    /**
     * Stránky seřazené jako strom: podstránka hned za nadřazenou, s hloubkou (klíč „uroven“) pro odsazení ve výpisu.
     *
     * @param list<array<string, mixed>> $stranky
     * @return list<array<string, mixed>>
     */
    private static function stromem(array $stranky): array
    {
        $podle = [];
        foreach ($stranky as $s) {
            $podle[(int) ($s['nadrazena'] ?? 0)][] = $s;
        }
        $ids = array_column($stranky, 'ids');
        $vysledek = [];
        $pridej = function (int $rodic, int $uroven) use (&$pridej, &$vysledek, $podle): void {
            foreach ($podle[$rodic] ?? [] as $s) {
                $vysledek[] = $s + ['uroven' => $uroven];
                if ($uroven < 4) {
                    $pridej((int) $s['ids'], $uroven + 1);
                }
            }
        };
        $pridej(0, 0);
        // stránky, jejichž nadřazená je v koši nebo neexistuje, se ukážou na nejvyšší úrovni
        foreach ($podle as $rodic => $deti) {
            if ($rodic !== 0 && !in_array($rodic, array_map('intval', $ids), true)) {
                foreach ($deti as $s) {
                    $vysledek[] = $s + ['uroven' => 0];
                }
            }
        }

        return $vysledek;
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['ids' => 0, 'seo_link' => '', 'titulek' => '', 'popis' => '', 'seo_titulek' => '', 'obrazek' => '', 'noindex' => 0, 'text' => '', 'zobrazit' => 1, 'v_menu' => 1, 'poradi' => 100, 'stavba' => null, 'stavba_koncept' => null,
            'nadrazena' => $this->request->getInt('nadrazena') ?: null, 'zverejnit_od' => null]);
    }

    protected function akceEdit(): Response
    {
        $stranka = $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$this->request->getInt('id')]);

        return $stranka === null ? $this->chyba('Stránka neexistuje.', 404) : $this->formular($stranka);
    }

    /** Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen název a text stránky. */
    protected function akceUlozText(): Response
    {
        $r = $this->request;
        $stranka = $r->isPost() ? $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$r->postInt('id')]) : null;
        if ($stranka === null || (!$this->app->auth()->smiVydavat() && $stranka['zobrazit'])) {
            return $this->zpetNaWeb($r->post('zpet'));
        }
        $titulek = mb_substr($r->post('titulek'), 0, 200);
        if ($titulek === '') {
            return $this->zpetNaWeb($r->post('zpet'), '?upravit=text&chyba=1');
        }
        $text = \Kaleta\Core\Html::proUzivatele($r->post('text'), $this->app->auth());
        if ($stranka['titulek'] !== $titulek || (string) $stranka['text'] !== $text) {
            $this->ulozRevizi((int) $stranka['ids'], $stranka['titulek'], (string) $stranka['text']); // úprava přímo na webu jde do historie jako v administraci
        }
        $this->db->update('stranky', ['titulek' => $titulek, 'text' => $text, 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $stranka['ids']]);
        \Kaleta\Admin\Protokol::zapis($this->app, 'stranky', 'úprava přímo na webu', mb_substr($titulek, 0, 80));

        return $this->zpetNaWeb($r->post('zpet'));
    }

    /**
     * Role na úrovni autora (i vlastní role bez práva vydávat) smí stránky připravovat, ale ne zveřejnit, měnit zveřejněné ani mazat.
     * Vrací odpověď s odmítnutím, nebo null, když smí.
     */
    private function jenSPravemVydavat(?array $stranka = null): ?Response
    {
        if ($this->app->auth()->smiVydavat() || ($stranka !== null && !$stranka['zobrazit'])) {
            return null;
        }

        return $this->zpet('Zveřejněné stránky upravuje, zveřejňuje a maže jen editor nebo správce. Můžete připravit novou skrytou stránku.', '', [], 'chyba');
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('ids');
        if ($id > 0 && ($odmitnuti = $this->jenSPravemVydavat($this->db->one('SELECT zobrazit FROM {stranky} WHERE ids = ?', [$id]) ?? ['zobrazit' => 1])) !== null) {
            return $odmitnuti;
        }
        $jazyk = \Kaleta\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk'));
        // nadřazená stránka: stejný jazyk, ne ona sama ani její podstránka (jinak by vznikl kruh)
        $vlastni = $id > 0 ? (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$id]) : '';
        $rodic = $r->postInt('nadrazena') > 0 ? $this->db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ? AND ids <> ? AND jazyk = ? AND smazano IS NULL', [$r->postInt('nadrazena'), $id, $jazyk]) : null;
        if ($rodic !== null && $vlastni !== '' && str_starts_with($rodic['seo_link'] . '/', $vlastni . '/')) {
            $rodic = null;
        }
        $predpona = $rodic !== null ? $rodic['seo_link'] . '/' : '';
        $slug = slugify($r->post('seo_link') !== '' ? basename(str_replace('\\', '/', $r->post('seo_link'))) : $r->post('titulek'), max(20, 118 - strlen($predpona)));
        $data = [
            'titulek' => mb_substr($r->post('titulek'), 0, 200),
            'seo_link' => $predpona . $slug,
            'nadrazena' => $rodic !== null ? (int) $rodic['ids'] : null,
            'popis' => mb_substr($r->post('popis'), 0, 300),
            'seo_titulek' => mb_substr(trim($r->post('seo_titulek')), 0, 200),
            'obrazek' => mb_substr(trim($r->post('obrazek')), 0, 255),
            'noindex' => (int) $r->postBool('noindex'),
            'text' => \Kaleta\Core\Html::proUzivatele($r->post('text'), $this->app->auth()),
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'v_menu' => (int) $r->postBool('v_menu'),
            'poradi' => max(0, min(65535, $r->postInt('poradi', 100))),
            'zmeneno' => date('Y-m-d H:i:s'),
            'jazyk' => $jazyk,
        ];
        // plánované zveřejnění: jen u skryté stránky s budoucím časem; prošlý čas stránku rovnou zveřejní
        $od = strtotime(str_replace('T', ' ', $r->post('zverejnit_od'))) ?: null;
        $data['zverejnit_od'] = !$data['zobrazit'] && $od !== null && $od > time() ? date('Y-m-d H:i:s', $od) : null;
        if (!$data['zobrazit'] && $od !== null && $od <= time()) {
            $data['zobrazit'] = 1;
        }
        if (!$this->app->auth()->smiVydavat()) {
            $data['zobrazit'] = 0; // bez práva vydávat zůstává stránka skrytá, zveřejní ji editor
            $data['zverejnit_od'] = null;
        }
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        $chyby = [];
        if ($data['titulek'] === '') {
            $chyby['titulek'] = 'Vyplňte název stránky.';
        }
        if ($r->post('seo_link') === '') {
            // adresa z názvu: obsazená dostane číslo (o-nas-2), jako u novinek
            $data['seo_link'] = $this->volnaAdresa($data['seo_link'], $id);
        }
        if ($rodic === null && (in_array($data['seo_link'], self::VYHRAZENE, true) || isset(\Kaleta\Core\Jazyk::DOSTUPNE[$data['seo_link']]))) {
            $chyby['seo_link'] = 'Tuto adresu používá systém, zvolte jinou.';
        } elseif (($jina = $this->db->one('SELECT ids, smazano FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$data['seo_link'], $id])) !== null) {
            $chyby['seo_link'] = $jina['smazano'] !== null ? 'Tuto adresu má stránka v koši – obnovte ji, nebo ji smažte natrvalo.' : 'Stránka s touto adresou už existuje.';
        }
        if ($id > 0 && $id === $this->app->settings()->int('titulni_stranka') && !$data['zobrazit']) {
            $chyby['zobrazit'] = 'Úvodní stránku nejde skrýt. Nejdřív v Nastavení → Základní vyberte jinou úvodní stránku.';
        }
        if ($chyby !== []) {
            $puvodni = $id > 0 ? $this->db->one('SELECT stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [$id]) : null;

            return $this->formular(['ids' => $id] + $data + ($puvodni ?? ['stavba' => null, 'stavba_koncept' => null]), $chyby);
        }
        if ($id > 0) {
            $puvodni = $this->db->one('SELECT seo_link, zobrazit, titulek, text FROM {stranky} WHERE ids = ?', [$id]);
            if ($puvodni !== null && ($puvodni['text'] !== $data['text'] || $puvodni['titulek'] !== $data['titulek'])) {
                $this->ulozRevizi($id, $puvodni['titulek'], (string) $puvodni['text']);
            }
            $this->db->update('stranky', $data, ['ids' => $id]);
            if ($puvodni !== null && $puvodni['seo_link'] !== $data['seo_link']) {
                $this->presunPodstranky($puvodni['seo_link'], $data['seo_link'], (bool) $puvodni['zobrazit']);
            }
        } else {
            $id = $this->db->insert('stranky', $data);
            $sablona = \Kaleta\Stavitel\Knihovna::SABLONY_STRANEK[$r->post('sablona')] ?? null;
            if ($sablona !== null && $sablona[1] !== []) {
                // nová stránka podle šablony: sekce z knihovny jako koncept a rovnou do builderu
                $stavba = \Kaleta\Stavitel\Knihovna::stranka($this->db, $sablona[1], $data['titulek'], $this->jazykObsahu($jazyk));
                $this->db->update('stranky', ['stavba_koncept' => Stavba::naJson($stavba)], ['ids' => $id]);
                \Kaleta\Core\Menu::nastavStranku($this->db, $id, $jazyk, (bool) $data['v_menu']);

                return \Kaleta\Core\Response::redirect($this->url('stavitel', ['id' => $id]));
            }
            if ($sablona !== null && $data['text'] === '') {
                $this->db->update('stranky', ['text' => \Kaleta\Stavitel\Knihovna::textZasad()], ['ids' => $id]);

                return $this->zpet('Stránka je založená s kostrou zásad – doplňte údaje v hranatých závorkách.', 'edit', ['id' => $id]);
            }
        }
        // sestavené menu (Vzhled → Menu): zaškrtávátko „v navigaci“ stránku do menu přidá nebo z něj odebere
        \Kaleta\Core\Menu::nastavStranku($this->db, $id, $data['jazyk'], (bool) $data['v_menu']);
        if ($r->post('po_ulozeni') === 'stavitel') {
            return \Kaleta\Core\Response::redirect($this->url('stavitel', ['id' => $id]));
        }

        return $this->zpet('Stránka byla uložena.');
    }

    /* ---------- builder (akce v Admin\StavitelAkce) ---------- */

    /** Editor; textová stránka se při prvním otevření převede na stavbu (úzká sekce s nadpisem a textem, text zůstane). */
    protected function akceStavitel(): Response
    {
        $stranka = $this->nactiStranku($this->request->getInt('id'));
        if ($stranka !== null && $stranka['stavba'] === null && $stranka['stavba_koncept'] === null) {
            $this->db->update('stranky', ['stavba_koncept' => Stavba::naJson(Stavba::zTextu($stranka['titulek'], (string) $stranka['text']))], ['ids' => $stranka['ids']]);
        }

        return $this->editorStavby();
    }

    protected function cilStavby(): ?array
    {
        $stranka = $this->nactiStranku($this->request->getInt('id'));

        return $stranka === null ? null : [
            'radek' => $stranka, 'stavba' => $stranka['stavba'], 'koncept' => $stranka['stavba_koncept'], 'jazyk' => $this->jazykObsahu($stranka['jazyk']),
            'titulek' => $stranka['titulek'], 'revize' => ['ids' => (int) $stranka['ids']], 'parametry' => ['id' => (int) $stranka['ids']],
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('stranky', ['stavba_koncept' => $koncept], ['ids' => $cil['radek']['ids']]);
    }

    protected function publikujCil(array $cil): void
    {
        Publikace::stranka($this->app, $cil['radek']);
    }

    protected function editorCile(array $cil): array
    {
        $stranka = $cil['radek'];
        $uvod = $this->app->settings()->int('titulni_stranka') === (int) $stranka['ids'];
        $adresa = $this->app->url(($stranka['jazyk'] !== '' ? $stranka['jazyk'] . '/' : '') . ($uvod ? '' : $stranka['seo_link']));

        return [
            'adresa' => $adresa, 'nahled' => $adresa . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $stranka['zobrazit'], 'casti' => false, 'nadpisy' => true,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Stránky')], 'nastaveni' => $this->url('edit', ['id' => (int) $stranka['ids']]),
            'podpis' => 'stranka:' . (int) $stranka['ids'],
        ];
    }

    /** Publikuje koncept stránky (i z MCP). */
    public static function publikuj(\Kaleta\Core\App $app, array $stranka): void
    {
        Publikace::stranka($app, $stranka);
    }

    /** Stránka se vrátí k textu z editoru (stavba zůstane ve verzích). */
    protected function akceStavbaText(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->postInt('ids')) : null;
        if ($stranka !== null && $stranka['stavba'] !== null) {
            $this->db->insert('stavba_revize', ['ids' => $stranka['ids'], 'datum' => date('Y-m-d H:i:s'), 'kdo' => $this->app->auth()->id(), 'stavba' => $stranka['stavba']]);
            $this->db->update('stranky', ['stavba' => null, 'stavba_koncept' => null], ['ids' => $stranka['ids']]);
        }

        return $this->zpet('Stránka zobrazuje text z editoru (obsah stavby bez rozložení). Stavbu najdete ve verzích, když otevřete builder.', 'edit', ['id' => (int) ($stranka['ids'] ?? 0)]);
    }

    /** @return array<string, mixed>|null */
    private function nactiStranku(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
    }

    /** Předchozí podoba textu stránky do historie (posledních 30 verzí). */
    private function ulozRevizi(int $ids, string $titulek, string $text): void
    {
        self::revize($this->db, $ids, $this->app->auth()->id(), $titulek, $text);
    }

    /** Totéž pro MCP a jiné vstupy mimo modul. */
    public static function revize(\Kaleta\Core\Db $db, int $ids, int $kdo, string $titulek, string $text): void
    {
        $db->insert('stranky_revize', ['ids' => $ids, 'datum' => date('Y-m-d H:i:s'), 'kdo' => $kdo, 'titulek' => $titulek, 'text' => $text]);
        $db->run('DELETE FROM {stranky_revize} WHERE ids = ? AND idr NOT IN (SELECT idr FROM (SELECT idr FROM {stranky_revize} WHERE ids = ? ORDER BY idr DESC LIMIT 30) t)', [$ids, $ids]);
    }

    /** Stránka změnila adresu: podstránky se posunou s ní a staré adresy zobrazených stránek se přesměrují. */
    private function presunPodstranky(string $stara, string $nova, bool $zobrazena): void
    {
        self::presun($this->db, $stara, $nova, $zobrazena);
    }

    public static function presun(\Kaleta\Core\Db $db, string $stara, string $nova, bool $zobrazena): void
    {
        if ($zobrazena) {
            Presmerovani::pridej($db, $stara, $nova);
        }
        foreach ($db->all('SELECT ids, seo_link, zobrazit FROM {stranky} WHERE seo_link LIKE ?', [addcslashes($stara, '%_\\') . '/%']) as $p) {
            $cil = $nova . substr($p['seo_link'], strlen($stara));
            $db->update('stranky', ['seo_link' => $cil], ['ids' => $p['ids']]);
            if ($p['zobrazit']) {
                Presmerovani::pridej($db, $p['seo_link'], $cil);
            }
        }
    }

    /** Obnovení starší verze textu stránky (současná podoba jde do historie). */
    protected function akceObnovVerzi(): Response
    {
        $revize = $this->request->isPost() ? $this->db->one('SELECT * FROM {stranky_revize} WHERE idr = ?', [$this->request->postInt('idr')]) : null;
        $stranka = $revize !== null ? $this->nactiStranku((int) $revize['ids']) : null;
        if ($stranka === null) {
            return $this->zpet();
        }
        if (($odmitnuti = $this->jenSPravemVydavat($stranka)) !== null) {
            return $odmitnuti;
        }
        $this->ulozRevizi((int) $stranka['ids'], $stranka['titulek'], (string) $stranka['text']);
        $this->db->update('stranky', ['titulek' => $revize['titulek'], 'text' => $revize['text'], 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $stranka['ids']]);

        return $this->zpet(t('Obnovena verze z %s.', datum($revize['datum'], true)), 'edit', ['id' => (int) $stranka['ids']]);
    }

    /** Stránka jako soubor JSON (název, popis a stavba) – pro přenos na jiný web s Kaletou. */
    protected function akceExport(): Response
    {
        $s = $this->nactiStranku($this->request->getInt('id'));
        if ($s === null) {
            return $this->chyba('Stránka neexistuje.', 404);
        }
        $json = (string) json_encode(['format' => 'kaleta-stranka', 'verze' => 1, 'titulek' => $s['titulek'], 'popis' => $s['popis'], 'text' => $s['text'],
            'stavba' => Stavba::zJson($s['stavba_koncept'] ?? $s['stavba'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="stranka-' . basename(str_replace('/', '-', $s['seo_link'])) . '.json"']);
    }

    /** Import stránky z JSON exportu: vznikne skrytá stránka, stavba projde validátorem jako každá jiná. */
    protected function akceImport(): Response
    {
        $soubor = $_FILES['soubor']['tmp_name'] ?? '';
        $data = $this->request->isPost() && is_uploaded_file($soubor) && filesize($soubor) < 5_000_000 ? json_decode((string) file_get_contents($soubor), true) : null;
        if (!is_array($data) || ($data['format'] ?? '') !== 'kaleta-stranka' || trim((string) ($data['titulek'] ?? '')) === '') {
            return $this->zpet('Soubor není export stránky.', '', [], 'chyba');
        }
        $titulek = mb_substr(trim((string) $data['titulek']), 0, 200);
        $zaznam = ['titulek' => $titulek, 'seo_link' => $this->volnaAdresa(slugify($titulek, 110), 0), 'popis' => mb_substr((string) ($data['popis'] ?? ''), 0, 300),
            'text' => \Kaleta\Core\WpObsah::bezpecneHtml((string) ($data['text'] ?? '')), 'zobrazit' => 0, 'v_menu' => 0, 'zmeneno' => date('Y-m-d H:i:s')];
        if (is_array($data['stavba'] ?? null)) {
            [$stavba, $chyby] = Stavba::vycisti($data['stavba'], $this->app->auth()->isAdmin());
            $zaznam['stavba_koncept'] = Stavba::naJson($stavba);
        }
        $id = $this->db->insert('stranky', $zaznam);

        return $this->zpet('Stránka je importovaná jako skrytá – zkontrolujte ji a zveřejněte.', 'edit', ['id' => $id]);
    }

    /** Volná adresa odvozená z $zaklad: o-nas, o-nas-2, o-nas-3… */
    private function volnaAdresa(string $zaklad, int $id): string
    {
        $adresa = $zaklad;
        for ($i = 2; $this->db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$adresa, $id]) !== null; $i++) {
            $adresa = mb_substr($zaklad, 0, 105) . '-' . $i;
        }

        return $adresa;
    }

    /** Smazání = přesun do koše: stránka zmizí z webu, adresa zůstane rezervovaná a jde ji obnovit. */
    protected function akceSmaz(): Response
    {
        $ids = $this->request->postInt('ids');
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        if (($odmitnuti = $this->jenSPravemVydavat()) !== null) {
            return $odmitnuti;
        }
        if ($ids === $this->app->settings()->int('titulni_stranka')) {
            return $this->zpet('Úvodní stránku nejde smazat. Nejdřív v Nastavení → Základní vyberte jinou úvodní stránku.', '', [], 'chyba');
        }
        $this->db->run('UPDATE {stranky} SET smazano = NOW(), zobrazit = 0 WHERE ids = ? AND smazano IS NULL', [$ids]);

        return $this->zpet(t('Stránka je v koši. Obnovit ji můžete %d dní.', self::DNY_V_KOSI));
    }

    /** Obnovení z koše: stránka se vrátí skrytá, zveřejní ji až uživatel. */
    protected function akceObnov(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('UPDATE {stranky} SET smazano = NULL WHERE ids = ?', [$this->request->postInt('ids')]);
        }

        return $this->zpet('Stránka je obnovená jako skrytá – zveřejníte ji v jejím nastavení.');
    }

    protected function akceSmazNatrvalo(): Response
    {
        if (($odmitnuti = $this->jenSPravemVydavat()) !== null) {
            return $odmitnuti;
        }
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {stranky} WHERE ids = ? AND smazano IS NOT NULL', [$this->request->postInt('ids')]);
        }

        return $this->zpet('Stránka byla smazána natrvalo.', '', ['stav' => 'kos']);
    }

    /** Stránky v koši déle než DNY_V_KOSI se smažou natrvalo (volá Admin\Kernel). */
    public static function vysypKos(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {stranky} WHERE smazano < NOW() - INTERVAL ' . self::DNY_V_KOSI . ' DAY')->rowCount();
    }

    /** Kopie stránky i se stavbou a rozpracovaným konceptem – skrytá, s volnou adresou. */
    protected function akceDuplikuj(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->postInt('ids')) : null;
        if ($stranka === null) {
            return $this->zpet();
        }
        $kopie = array_diff_key($stranka, ['ids' => 0, 'smazano' => 0]);
        $kopie['titulek'] = mb_substr(t('%s (kopie)', $stranka['titulek']), 0, 200);
        $kopie['seo_link'] = $this->volnaAdresa(mb_substr($stranka['seo_link'] . '-kopie', 0, 110), 0);
        $kopie['zobrazit'] = 0;
        $kopie['v_menu'] = 0; // kopie se do navigace nedostane, dokud ji tam někdo nezařadí
        $kopie['preklad_z'] = null;
        $kopie['zmeneno'] = date('Y-m-d H:i:s');
        $id = $this->db->insert('stranky', $kopie);

        return $this->zpet('Kopie stránky je skrytá – upravte ji a zveřejněte.', 'edit', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $stranka
     * @param array<string, string> $chyby
     */
    private function formular(array $stranka, array $chyby = []): Response
    {
        $jazyk = (string) ($stranka['jazyk'] ?? '');
        $vlastni = (string) ($stranka['seo_link'] ?? '');

        return $this->view('formular', $stranka['ids'] ? 'Úprava stránky' : 'Nová stránka', [
            'stranka' => $stranka, 'chyby' => $chyby,
            // možné nadřazené stránky: stejný jazyk, ne ona sama ani její podstránky
            'rodice' => array_values(array_filter($this->db->all('SELECT ids, titulek, seo_link FROM {stranky} WHERE jazyk = ? AND smazano IS NULL AND ids <> ? ORDER BY seo_link', [$jazyk, (int) $stranka['ids']]),
                fn (array $s): bool => $vlastni === '' || !str_starts_with($s['seo_link'] . '/', $vlastni . '/'))),
            'revize' => $stranka['ids'] ? $this->db->all('SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = \'\', u.user, u.jmeno) AS kdo FROM {stranky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.ids = ? ORDER BY r.idr DESC LIMIT 30', [(int) $stranka['ids']]) : [],
            'uvod' => $stranka['ids'] > 0 && (int) $stranka['ids'] === $this->app->settings()->int('titulni_stranka'),
            'vMenu' => $stranka['ids'] > 0 ? \Kaleta\Core\Menu::obsahujeStranku($this->db, (int) $stranka['ids'], (string) ($stranka['jazyk'] ?? '')) : null,
            'vlastniMenu' => \Kaleta\Core\Menu::nacti($this->db, 'hlavni', (string) ($stranka['jazyk'] ?? '')) !== null,
        ]);
    }
}
