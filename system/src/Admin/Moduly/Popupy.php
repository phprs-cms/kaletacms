<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Admin\StavitelAkce;
use Kaleta\Core\Jazyk;
use Kaleta\Core\Response;
use Kaleta\Stavitel\Popupy as Okna;
use Kaleta\Stavitel\Publikace;
use Kaleta\Stavitel\Stavba;

/**
 * Pop-up okna: obsah se staví v builderu jako část webu, v nastavení se určí typ, spouštěč, pravidla a četnost.
 * Na webu se okno objeví, až je publikované a zapnuté. Počitadla zobrazení, zavření a konverzí jsou bez cookies.
 */
final class Popupy extends Modul
{
    use StavitelAkce;

    public const string IDENT = 'popupy';
    public const string NAZEV = 'Pop-up okna';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'popupy';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Pop-up okna', ['okna' => Okna::vsechny($this->db)]);
    }

    protected function akceNovy(): Response
    {
        return $this->view('novy', 'Nové pop-up okno', []);
    }

    /** Nové okno z hotového vzoru: koncept stavby v jazyce webu, typ a spouštěč ze vzoru, vypnuté – rovnou do builderu. */
    protected function akceZaloz(): Response
    {
        $r = $this->request;
        $vzor = Okna::KNIHOVNA[$r->post('vzor')] ?? null;
        if (!$r->isPost() || $vzor === null) {
            return $this->zpet('', 'novy');
        }
        $nazev = mb_substr(trim($r->post('nazev')), 0, 100) ?: t($vzor[0]);
        $id = $this->db->insert('popupy', [
            'nazev' => $nazev, 'adresa' => Okna::adresa($this->db, $nazev), 'typ' => $vzor[2], 'spoustec' => $vzor[3], 'hodnota' => $vzor[4],
            'pravidla' => (string) json_encode(Okna::vychoziPravidla()), 'cetnost' => 'relace', 'dni' => 7, 'aktivni' => 0,
            'stavba_koncept' => Stavba::naJson(Okna::stavbaZKnihovny((string) $r->post('vzor'), Jazyk::vychozi($this->app->settings()))), 'zmeneno' => date('Y-m-d H:i:s'),
        ]);

        return Response::redirect($this->url('stavitel', ['id' => $id]));
    }

    protected function akceEdit(): Response
    {
        $p = Okna::podleId($this->db, $this->request->getInt('id'));

        return $p === null ? $this->chyba('Pop-up okno neexistuje.', 404) : $this->view('formular', $p['nazev'], ['p' => $p] + $this->volby());
    }

    protected function akceUloz(): Response
    {
        $r = $this->request;
        $p = $r->isPost() ? Okna::podleId($this->db, $r->postInt('idpp')) : null;
        if ($p === null) {
            return $this->zpet();
        }
        $nazev = mb_substr(trim($r->post('nazev')), 0, 100);
        if ($nazev === '') {
            return $this->zpet('Okno musí mít název.', 'edit', ['id' => $p['idpp']], 'chyba');
        }
        $adresa = $r->post('adresa') !== '' ? slugify($r->post('adresa'), 60) : $p['adresa'];
        if (!preg_match(Okna::VZOR_ADRESY, $adresa) || $this->db->value('SELECT idpp FROM {popupy} WHERE adresa = ? AND idpp <> ?', [$adresa, $p['idpp']]) !== null) {
            return $this->zpet(t('Adresu „%s“ už používá jiné okno.', $adresa), 'edit', ['id' => $p['idpp']], 'chyba');
        }
        $pravidla = Okna::vycistiPravidla([
            'kde' => $r->post('kde'), 'stranky' => is_array($_POST['stranky'] ?? null) ? $_POST['stranky'] : [], 'kolekce' => is_array($_POST['kolekce'] ?? null) ? $_POST['kolekce'] : [],
            'novinky' => $r->postBool('novinky'), 'jazyk' => $r->post('jazyk'), 'od' => $r->post('od'), 'do' => $r->post('do'),
            'zarizeni' => $r->post('zarizeni'), 'utm' => $r->post('utm'), 'odkud' => $r->post('odkud'),
        ]);
        $this->db->update('popupy', [
            'nazev' => $nazev, 'adresa' => $adresa,
            'typ' => isset(Okna::TYPY[$r->post('typ')]) ? $r->post('typ') : $p['typ'],
            'spoustec' => isset(Okna::SPOUSTECE[$r->post('spoustec')]) ? $r->post('spoustec') : $p['spoustec'],
            'hodnota' => max(0, min(3600, $r->postInt('hodnota'))),
            'cetnost' => isset(Okna::CETNOSTI[$r->post('cetnost')]) ? $r->post('cetnost') : $p['cetnost'],
            'dni' => max(1, min(365, $r->postInt('dni', 7))),
            'poradi' => max(-9999, min(9999, $r->postInt('poradi', 100))),
            'pravidla' => (string) json_encode($pravidla, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s'),
        ], ['idpp' => $p['idpp']]);
        \Kaleta\Front\Cache::vymaz();

        return $this->zpet('Nastavení okna bylo uloženo.');
    }

    /** Zapnout nebo vypnout okno na webu; zapnout jde jen publikované. */
    protected function akcePrepni(): Response
    {
        $p = $this->request->isPost() ? Okna::podleId($this->db, $this->request->postInt('idpp')) : null;
        if ($p === null) {
            return $this->zpet();
        }
        if (!$p['aktivni'] && $p['stavba'] === null) {
            return $this->zpet('Okno nejdřív publikujte v builderu – teprve pak ho jde zapnout.', '', [], 'chyba');
        }
        $this->db->update('popupy', ['aktivni' => $p['aktivni'] ? 0 : 1], ['idpp' => $p['idpp']]);
        \Kaleta\Front\Cache::vymaz();

        return $this->zpet($p['aktivni'] ? 'Okno je vypnuté – na webu se už neukáže.' : 'Okno je zapnuté a ukáže se na webu podle pravidel.');
    }

    protected function akceVynuluj(): Response
    {
        if ($this->request->isPost()) {
            $this->db->update('popupy', ['zobrazeni' => 0, 'zavreni' => 0, 'konverze' => 0], ['idpp' => $this->request->postInt('idpp')]);
        }

        return $this->zpet('Počitadla okna jsou vynulovaná.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('popupy', ['idpp' => $this->request->postInt('idpp')]);
            \Kaleta\Front\Cache::vymaz();
        }

        return $this->zpet('Pop-up okno bylo smazáno.');
    }

    /** Stránky a kolekce pro výběr „kde se okno ukáže“, jazyky webu. @return array<string, mixed> */
    private function volby(): array
    {
        $web = $this->app->settings();
        $jazyky = array_merge([Jazyk::vychozi($web)], Jazyk::dalsi($web));

        return [
            'stranky' => $this->db->all('SELECT ids, titulek, jazyk FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek LIMIT 500'),
            'kolekce' => $this->db->all('SELECT seo_link, nazev FROM {kolekce} WHERE detail = 1 ORDER BY nazev'),
            'jazyky' => count($jazyky) > 1 ? array_combine($jazyky, array_map(fn (string $j): string => Jazyk::DOSTUPNE[$j][0] ?? $j, $jazyky)) : [],
        ];
    }

    /* ---------- builder ---------- */

    protected function cilStavby(): ?array
    {
        $p = Okna::podleId($this->db, $this->request->getInt('id'));

        return $p === null ? null : [
            'radek' => $p, 'stavba' => $p['stavba'], 'koncept' => $p['stavba_koncept'], 'jazyk' => Jazyk::vychozi($this->app->settings()),
            'titulek' => t('Pop-up: %s', $p['nazev']), 'revize' => ['cast' => 'popup:' . $p['idpp']], 'parametry' => ['id' => $p['idpp']],
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('popupy', ['stavba_koncept' => $koncept], ['idpp' => $cil['radek']['idpp']]);
    }

    protected function publikujCil(array $cil): void
    {
        Publikace::popup($this->app, $cil['radek']);
    }

    protected function editorCile(array $cil): array
    {
        $p = $cil['radek'];
        $adresa = $this->app->url('_popup/' . $p['idpp']);

        return [
            'adresa' => $adresa . '?stavba=koncept', 'nahled' => $adresa . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $p['aktivni'], 'casti' => false,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Pop-up okna')], 'nastaveni' => $this->url('edit', ['id' => $p['idpp']]),
            'textNastaveni' => t('Nastavení okna (kdy a kde se ukáže)'), 'podpis' => 'popup:' . $p['idpp'],
        ];
    }
}
