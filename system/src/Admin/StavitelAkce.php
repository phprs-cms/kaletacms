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
            'stranka' => ['titulek' => $cil['titulek'], 'adresa' => $e['adresa'], 'zobrazena' => $e['zobrazena'], 'publikovana' => $cil['stavba'] !== null],
            'stavba' => Stavba::zJson($cil['koncept'] ?? $cil['stavba']),
            'zmeny' => $cil['koncept'] !== null && $cil['koncept'] !== $cil['stavba'],
            'schema' => $schema,
            'kolekce' => $kolekce,
            'komponenty' => $komponenty,
            'ai' => (new \MiroCMS\Core\Asistent($app->settings()))->pripraven(),
            'kolekceDetailu' => $e['kolekce'] ?? null,
            'knihovna' => Knihovna::seznam(),
            'kategorieKnihovny' => array_map(fn (string $k): string => t($k), Knihovna::KATEGORIE),
            'tridy' => $this->tridyStavitele(),
            'barvy' => DesignSystem::nacti($app->settings())['barvy'],
            'nahled' => $e['nahled'],
            'zpet' => $e['zpet'],
            'adresy' => array_map(fn (string $akce): string => $this->url($akce, $cil['parametry']), [
                'uloz' => 'stavba_uloz', 'publikuj' => 'stavba_publikuj', 'zahod' => 'stavba_zahod', 'sekce' => 'stavba_sekce', 'trida' => 'stavba_trida',
                'revize' => 'stavba_revize', 'obnov' => 'stavba_obnov', 'aiSekce' => 'stavba_ai_sekce', 'aiText' => 'stavba_ai_text',
            ]) + ['admin' => $app->url('admin.php'), 'nastaveni' => $e['nastaveni'],
                'komponenta' => $app->auth()->isAdmin() ? $app->url('admin.php?modul=komponenty&akce=z_prvku') : null],
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
        [$stavba, $chyby] = Stavba::vycisti($vstup, $this->app->auth()->isAdmin(), Stavba::zJson($cil['koncept'] ?? $cil['stavba']));
        $json = Stavba::naJson($stavba);
        $this->ulozKoncept($cil, $json);

        return Response::json(['ok' => true, 'stavba' => $stavba, 'chyby' => $chyby, 'zmeny' => $json !== $cil['stavba']]);
    }

    /** Publikování: koncept se stane stavbou; předchozí publikovaná verze jde do historie. */
    protected function akceStavbaPublikuj(): Response
    {
        $cil = $this->request->isPost() ? $this->cilStavby() : null;
        if ($cil === null || ($cil['koncept'] ?? $cil['stavba']) === null) {
            return Response::json(['ok' => false, 'chyba' => t('Není co publikovat.')], 400);
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

        return Response::json(['ok' => true, 'stavba' => Stavba::zJson($cil['stavba'])]);
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
                return Response::json(['ok' => true, 'tridy' => $this->tridyStavitele(), 'chyby' => $chyby + array_map(fn (string $d): string => t('Nepovolená deklarace: %s', $d), $zahozeno)]);
            }
        }
        \MiroCMS\Front\Cache::vymaz();

        return Response::json(['ok' => true, 'tridy' => $this->tridyStavitele()]);
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

        return Response::json(['ok' => true, 'stavba' => Stavba::zJson($stavba)]);
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
