<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Response;
use MiroCMS\Stavitel\DesignSystem;
use MiroCMS\Stavitel\Knihovna;
use MiroCMS\Stavitel\Stavba;
use MiroCMS\Stavitel\Styl;

/**
 * Stránky webu: úvod, O nás, Služby, Kontakt, Zásady ochrany soukromí… Úvodní stránku určuje Nastavení → Základní.
 * Stránka má adresu /<seo_link>. Obsah je buď text z editoru, nebo stavba ze stavitele (sloupec stavba, rozpracovaná stavba_koncept).
 */
final class Stranky extends Modul
{
    public const string IDENT = 'stranky';
    public const string NAZEV = 'Stránky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'stranky';

    /** Adresy, které patří systému a stránka je mít nemůže. */
    public const array VYHRAZENE = ['novinky', 'hledani', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'stav', 'ulohy', 'souhlas'];

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Stránky', ['stranky' => $this->db->all('SELECT * FROM {stranky} ORDER BY poradi, titulek')]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['ids' => 0, 'seo_link' => '', 'titulek' => '', 'popis' => '', 'text' => '', 'zobrazit' => 1, 'v_menu' => 1, 'poradi' => 100, 'stavba' => null]);
    }

    protected function akceEdit(): Response
    {
        $stranka = $this->db->one('SELECT * FROM {stranky} WHERE ids = ?', [$this->request->getInt('id')]);

        return $stranka === null ? $this->chyba('Stránka neexistuje.', 404) : $this->formular($stranka);
    }

    /** Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen název a text stránky. */
    protected function akceUlozText(): Response
    {
        $r = $this->request;
        $stranka = $r->isPost() ? $this->db->one('SELECT * FROM {stranky} WHERE ids = ?', [$r->postInt('id')]) : null;
        if ($stranka === null) {
            return $this->zpetNaWeb($r->post('zpet'));
        }
        $titulek = mb_substr($r->post('titulek'), 0, 200);
        if ($titulek === '') {
            return $this->zpetNaWeb($r->post('zpet'), '?upravit=text&chyba=1');
        }
        $this->db->update('stranky', ['titulek' => $titulek, 'text' => $r->post('text'), 'zmeneno' => date('Y-m-d H:i:s')], ['ids' => $stranka['ids']]);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'stranky', 'úprava přímo na webu', mb_substr($titulek, 0, 80));

        return $this->zpetNaWeb($r->post('zpet'));
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('ids');
        $data = [
            'titulek' => mb_substr($r->post('titulek'), 0, 200),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('titulek'), 110),
            'popis' => mb_substr($r->post('popis'), 0, 300),
            'text' => $r->post('text'),
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'v_menu' => (int) $r->postBool('v_menu'),
            'poradi' => max(0, min(65535, $r->postInt('poradi', 100))),
            'zmeneno' => date('Y-m-d H:i:s'),
            'jazyk' => \MiroCMS\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk')),
        ];
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        $chyby = [];
        if ($data['titulek'] === '') {
            $chyby['titulek'] = 'Vyplňte název stránky.';
        }
        if (in_array($data['seo_link'], self::VYHRAZENE, true) || isset(\MiroCMS\Core\Jazyk::DOSTUPNE[$data['seo_link']])) {
            $chyby['seo_link'] = 'Tuto adresu používá systém, zvolte jinou.';
        } elseif ($this->db->value('SELECT ids FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$data['seo_link'], $id]) !== null) {
            $chyby['seo_link'] = 'Stránka s touto adresou už existuje.';
        }
        if ($chyby !== []) {
            return $this->formular(['ids' => $id] + $data, $chyby);
        }
        if ($id > 0) {
            $puvodni = $this->db->one('SELECT seo_link, zobrazit FROM {stranky} WHERE ids = ?', [$id]);
            $this->db->update('stranky', $data, ['ids' => $id]);
            if ($puvodni !== null && $puvodni['zobrazit'] && $puvodni['seo_link'] !== $data['seo_link']) {
                // zobrazená stránka změnila adresu: stará se přesměruje na novou
                Presmerovani::pridej($this->db, $puvodni['seo_link'], $data['seo_link']);
            }
        } else {
            $id = $this->db->insert('stranky', $data);
        }
        if ($r->post('po_ulozeni') === 'stavitel') {
            return \MiroCMS\Core\Response::redirect($this->url('stavitel', ['id' => $id]));
        }

        return $this->zpet('Stránka byla uložena.');
    }

    /* ---------- stavitel ---------- */

    /** Editor stavby na celou obrazovku: plátno se skutečnou stránkou webu, strom, vlastnosti. */
    protected function akceStavitel(): Response
    {
        $stranka = $this->nactiStranku($this->request->getInt('id'));
        if ($stranka === null) {
            return $this->chyba('Stránka neexistuje.', 404);
        }
        if ($stranka['stavba'] === null && $stranka['stavba_koncept'] === null) {
            // textová stránka: převod na stavbu (úzká sekce s nadpisem a textem); text zůstane ve sloupci text
            $this->db->update('stranky', ['stavba_koncept' => Stavba::naJson(Stavba::zTextu($stranka['titulek'], (string) $stranka['text']))], ['ids' => $stranka['ids']]);
            $stranka = $this->nactiStranku((int) $stranka['ids']);
        }
        $app = $this->app;
        $uvod = $app->settings()->int('titulni_stranka') === (int) $stranka['ids'];
        $adresa = $app->url(($stranka['jazyk'] !== '' ? $stranka['jazyk'] . '/' : '') . ($uvod ? '' : $stranka['seo_link']));
        $data = [
            'stranka' => ['id' => (int) $stranka['ids'], 'titulek' => $stranka['titulek'], 'adresa' => $adresa, 'zobrazena' => (bool) $stranka['zobrazit'], 'publikovana' => $stranka['stavba'] !== null],
            'stavba' => Stavba::zJson($stranka['stavba_koncept'] ?? $stranka['stavba']),
            'zmeny' => $stranka['stavba_koncept'] !== null && $stranka['stavba_koncept'] !== $stranka['stavba'],
            'schema' => Stavba::schema($app->auth()->isAdmin(), Jazyk::obsahu($app->settings(), $stranka['jazyk'])),
            'knihovna' => Knihovna::seznam(),
            'tridy' => $this->tridy(),
            'barvy' => DesignSystem::nacti($app->settings())['barvy'],
            'nahled' => $adresa . '?stavba=koncept&editor=1',
            'adresy' => array_map(fn (string $akce): string => $this->url($akce, ['id' => (int) $stranka['ids']]), [
                'uloz' => 'stavba_uloz', 'publikuj' => 'stavba_publikuj', 'zahod' => 'stavba_zahod', 'sekce' => 'stavba_sekce', 'trida' => 'stavba_trida',
                'revize' => 'stavba_revize', 'obnov' => 'stavba_obnov', 'text' => 'stavba_text', 'nastaveni' => 'edit',
            ]) + ['admin' => $app->url('admin.php'), 'stranky' => $this->url()],
        ];

        return Response::html($app->view->render('admin/stranky/stavitel', ['app' => $app, 'data' => $data, 'titulek' => $stranka['titulek']]));
    }

    /** Průběžné ukládání konceptu z editoru (JSON). Vrací vyčištěnou stavbu a chyby, které editor ukáže. */
    protected function akceStavbaUloz(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->getInt('id')) : null;
        if ($stranka === null) {
            return Response::json(['ok' => false, 'chyba' => t('Stránka neexistuje.')], 404);
        }
        $vstup = json_decode((string) ($_POST['stavba'] ?? ''), true);
        if (!is_array($vstup)) {
            return Response::json(['ok' => false, 'chyba' => t('Stavba nemá platný tvar JSON.')], 400);
        }
        [$stavba, $chyby] = Stavba::vycisti($vstup, $this->app->auth()->isAdmin(), Stavba::zJson($stranka['stavba_koncept'] ?? $stranka['stavba']));
        $this->db->update('stranky', ['stavba_koncept' => Stavba::naJson($stavba)], ['ids' => $stranka['ids']]);

        return Response::json(['ok' => true, 'stavba' => $stavba, 'chyby' => $chyby, 'zmeny' => Stavba::naJson($stavba) !== $stranka['stavba']]);
    }

    /** Publikování: koncept se stane stavbou stránky; předchozí publikovaná verze jde do historie (20 posledních). */
    protected function akceStavbaPublikuj(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->getInt('id')) : null;
        if ($stranka === null || ($stranka['stavba_koncept'] ?? $stranka['stavba']) === null) {
            return Response::json(['ok' => false, 'chyba' => t('Není co publikovat.')], 400);
        }
        self::publikuj($this->app, $stranka);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'stranky', 'publikování stavby', mb_substr($stranka['titulek'], 0, 80));

        return Response::json(['ok' => true]);
    }

    /** Publikuje koncept stránky (i z MCP). */
    public static function publikuj(\MiroCMS\Core\App $app, array $stranka): void
    {
        $db = $app->db();
        $novy = $stranka['stavba_koncept'] ?? $stranka['stavba'];
        if ($stranka['stavba'] !== null && $stranka['stavba'] !== $novy) {
            $db->insert('stavba_revize', ['ids' => $stranka['ids'], 'datum' => $stranka['zmeneno'] ?? date('Y-m-d H:i:s'), 'kdo' => $app->auth()->id() ?: null, 'stavba' => $stranka['stavba']]);
            $hranice = $db->value('SELECT idr FROM {stavba_revize} WHERE ids = ? ORDER BY idr DESC LIMIT 1 OFFSET 20', [$stranka['ids']]);
            if ($hranice !== null) {
                $db->run('DELETE FROM {stavba_revize} WHERE ids = ? AND idr <= ?', [$stranka['ids'], $hranice]);
            }
        }
        // text stránky = obsah stavby bez rozložení: z něj čerpá hledání, llms.txt, .md, API i návrat k textu
        $text = Stavba::jakoText(Stavba::zJson($novy) ?? []);
        $db->update('stranky', ['stavba' => $novy, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')] + ($text !== '' ? ['text' => $text] : []), ['ids' => $stranka['ids']]);
        \MiroCMS\Front\Cache::vymaz();
    }

    /** Zahodí rozpracované změny: editor se vrátí k publikované stavbě. */
    protected function akceStavbaZahod(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->getInt('id')) : null;
        if ($stranka === null || $stranka['stavba'] === null) {
            return Response::json(['ok' => false, 'chyba' => t('Stránka zatím nemá publikovanou stavbu – není k čemu se vrátit.')], 400);
        }
        $this->db->update('stranky', ['stavba_koncept' => null], ['ids' => $stranka['ids']]);

        return Response::json(['ok' => true, 'stavba' => Stavba::zJson($stranka['stavba'])]);
    }

    /** Stránka se vrátí k textu z editoru (stavba zůstane ve verzích). */
    protected function akceStavbaText(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->postInt('ids')) : null;
        if ($stranka !== null && $stranka['stavba'] !== null) {
            $this->db->insert('stavba_revize', ['ids' => $stranka['ids'], 'datum' => date('Y-m-d H:i:s'), 'kdo' => $this->app->auth()->id(), 'stavba' => $stranka['stavba']]);
            $this->db->update('stranky', ['stavba' => null, 'stavba_koncept' => null], ['ids' => $stranka['ids']]);
        }

        return $this->zpet('Stránka zobrazuje text z editoru (obsah stavby bez rozložení). Stavbu najdete ve verzích, když otevřete stavitel.', 'edit', ['id' => (int) ($stranka['ids'] ?? 0)]);
    }

    /** Sekce z knihovny jako nové prvky (JSON); chybějící třídy, které používá, se založí. */
    protected function akceStavbaSekce(): Response
    {
        $stranka = $this->request->isPost() ? $this->nactiStranku($this->request->getInt('id')) : null;
        $sekce = $stranka !== null ? Knihovna::sekci($this->request->get('klic'), Jazyk::obsahu($this->app->settings(), $stranka['jazyk'])) : null;
        if ($sekce === null) {
            return Response::json(['ok' => false, 'chyba' => t('Sekce v knihovně není.')], 404);
        }
        Knihovna::zalozTridy($this->db, $sekce['tridy']);

        return Response::json(['ok' => true, 'prvek' => $sekce['prvek'], 'tridy' => $this->tridy()]);
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
                return Response::json(['ok' => true, 'tridy' => $this->tridy(), 'chyby' => $chyby + array_map(fn (string $d): string => t('Nepovolená deklarace: %s', $d), $zahozeno)]);
            }
        }
        \MiroCMS\Front\Cache::vymaz();

        return Response::json(['ok' => true, 'tridy' => $this->tridy()]);
    }

    /** Publikované verze stavby (JSON pro dialog Verze). */
    protected function akceStavbaRevize(): Response
    {
        return Response::json(['revize' => $this->db->all(
            "SELECT r.idr, r.datum, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo FROM {stavba_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.ids = ? ORDER BY r.idr DESC",
            [$this->request->getInt('id')],
        )]);
    }

    /** Starší verze se načte do konceptu; publikuje se až tlačítkem Publikovat. */
    protected function akceStavbaObnov(): Response
    {
        $revize = $this->request->isPost() ? $this->db->one('SELECT * FROM {stavba_revize} WHERE idr = ? AND ids = ?', [$this->request->postInt('idr'), $this->request->getInt('id')]) : null;
        if ($revize === null) {
            return Response::json(['ok' => false, 'chyba' => t('Verze neexistuje.')], 404);
        }
        $this->db->update('stranky', ['stavba_koncept' => $revize['stavba']], ['ids' => $revize['ids']]);

        return Response::json(['ok' => true, 'stavba' => Stavba::zJson($revize['stavba'])]);
    }

    /** @return array<string, array{styl: array<string, mixed>, css: string}> */
    private function tridy(): array
    {
        $tridy = [];
        foreach ($this->db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev') as $r) {
            $tridy[$r['nazev']] = ['styl' => json_decode((string) $r['styl'], true) ?: new \stdClass(), 'css' => (string) $r['css']];
        }

        return $tridy;
    }

    /** @return array<string, mixed>|null */
    private function nactiStranku(int $id): ?array
    {
        return $this->db->one('SELECT * FROM {stranky} WHERE ids = ?', [$id]);
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('stranky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->zpet('Stránka byla smazána.');
    }

    /**
     * @param array<string, mixed> $stranka
     * @param array<string, string> $chyby
     */
    private function formular(array $stranka, array $chyby = []): Response
    {
        return $this->view('formular', $stranka['ids'] ? 'Úprava stránky' : 'Nová stránka', ['stranka' => $stranka, 'chyby' => $chyby]);
    }
}
