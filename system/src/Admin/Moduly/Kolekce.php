<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Admin\StavitelAkce;
use Kaleta\Core\Jazyk;
use Kaleta\Core\Response;
use Kaleta\Stavitel\Kolekce as KolekceObsahu;
use Kaleta\Stavitel\Publikace;
use Kaleta\Stavitel\Stavba;

/**
 * Kolekce – vlastní typy obsahu (reference, tým, produkty, pobočky…). Definici polí a šablonu detailu mění správce,
 * položky každý, kdo má k modulu přístup. Na web je dostane prvek Výpis kolekce v builderu.
 */
final class Kolekce extends Modul
{
    use StavitelAkce {
        akceStavitel as protected editorStavby;
    }

    public const string IDENT = 'kolekce';
    public const string NAZEV = 'Kolekce';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'kolekce';

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Kolekce', [
            'kolekce' => $this->db->all('SELECT k.idk, k.nazev, k.seo_link, k.detail, (SELECT COUNT(*) FROM {kolekce_polozky} p WHERE p.idk = k.idk) AS pocet FROM {kolekce} k ORDER BY k.nazev'),
        ]);
    }

    /* ---------- definice kolekce (správce) ---------- */

    protected function akceNovy(): Response
    {
        return $this->spravce() ?? $this->view('formular', 'Nová kolekce', ['k' => ['idk' => 0, 'nazev' => '', 'seo_link' => '', 'detail' => 0, 'pole' => [
            ['klic' => '', 'popisek' => t('Popis'), 'typ' => 'radky'], ['klic' => '', 'popisek' => t('Obrázek'), 'typ' => 'obrazek'],
        ]]]);
    }

    protected function akceEdit(): Response
    {
        $k = KolekceObsahu::podleId($this->db, $this->request->getInt('id'));

        return $this->spravce() ?? ($k === null ? $this->chyba('Kolekce neexistuje.', 404) : $this->view('formular', $k['nazev'], ['k' => $k]));
    }

    protected function akceUloz(): Response
    {
        if (($odmitnuti = $this->spravce()) !== null || !$this->request->isPost()) {
            return $odmitnuti ?? $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('idk');
        $puvodni = $id > 0 ? KolekceObsahu::podleId($this->db, $id) : null;
        $nazev = mb_substr(trim($r->post('nazev')), 0, 100);
        if ($nazev === '') {
            return $this->zpet('Kolekce musí mít název.', $id > 0 ? 'edit' : 'novy', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $nazev, 110);
        if (in_array($seo, Stranky::VYHRAZENE, true) || isset(Jazyk::DOSTUPNE[$seo]) || $this->db->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $id]) !== null) {
            return $this->zpet(t('Adresu „%s“ už používá systém nebo jiná kolekce.', $seo), $id > 0 ? 'edit' : 'novy', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        // klíč existujícího pole se nemění (jsou pod ním uložené hodnoty položek); nová pole ho dostanou z popisku
        $pole = KolekceObsahu::vycistiPole(is_array($_POST['pole'] ?? null) ? array_values($_POST['pole']) : []);
        $data = ['nazev' => $nazev, 'seo_link' => $seo, 'detail' => $r->postBool('detail') ? 1 : 0, 'pole' => (string) json_encode($pole, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($puvodni !== null) {
            $this->db->update('kolekce', $data, ['idk' => $id]);
        } else {
            $id = $this->db->insert('kolekce', $data);
        }
        \Kaleta\Front\Cache::vymaz();

        return $this->zpet('Kolekce byla uložena.', 'polozky', ['id' => $id]);
    }

    protected function akceSmaz(): Response
    {
        if (($odmitnuti = $this->spravce()) !== null) {
            return $odmitnuti;
        }
        if ($this->request->isPost()) {
            $this->db->delete('kolekce', ['idk' => $this->request->postInt('idk')]);
        }

        return $this->zpet('Kolekce i s položkami byla smazána.');
    }

    /* ---------- položky ---------- */

    protected function akcePolozky(): Response
    {
        $k = KolekceObsahu::podleId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->chyba('Kolekce neexistuje.', 404);
        }

        return $this->view('polozky', $k['nazev'], ['k' => $k,
            'polozky' => $this->db->all('SELECT idp, nazev, seo_link, poradi, zobrazit, jazyk, datum FROM {kolekce_polozky} WHERE idk = ? ORDER BY poradi, nazev', [$k['idk']])]);
    }

    protected function akcePolozka(): Response
    {
        $k = KolekceObsahu::podleId($this->db, $this->request->getInt('id'));
        if ($k === null) {
            return $this->chyba('Kolekce neexistuje.', 404);
        }
        $idp = $this->request->getInt('polozka');
        $p = $idp > 0 ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) : null;
        if ($idp > 0 && $p === null) {
            return $this->chyba('Položka neexistuje.', 404);
        }
        $p ??= ['idp' => 0, 'nazev' => '', 'seo_link' => '', 'data' => '{}', 'poradi' => 100, 'zobrazit' => 1, 'jazyk' => '', 'datum' => date('Y-m-d H:i:s')];
        $p['data'] = json_decode((string) $p['data'], true) ?: [];

        return $this->view('polozka', $p['nazev'] !== '' ? $p['nazev'] : t('Nová položka'), ['k' => $k, 'p' => $p]);
    }

    protected function akceUlozPolozku(): Response
    {
        $r = $this->request;
        $k = $r->isPost() ? KolekceObsahu::podleId($this->db, $r->postInt('idk')) : null;
        if ($k === null) {
            return $this->zpet();
        }
        $idp = $r->postInt('idp');
        $nazev = mb_substr(trim($r->post('nazev')), 0, 200);
        if ($nazev === '') {
            return $this->zpet('Položka musí mít název.', 'polozka', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }
        $chyby = [];
        $data = KolekceObsahu::vycistiData($k['pole'], is_array($_POST['data'] ?? null) ? $_POST['data'] : [], $chyby);
        $seo = slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $nazev, 150);
        for ($i = 2, $zaklad = $seo; $this->db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND idp <> ?', [$k['idk'], $seo, $idp]) !== null; $i++) {
            $seo = $zaklad . '-' . $i;
        }
        $radek = ['idk' => $k['idk'], 'nazev' => $nazev, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'poradi' => max(-9999, min(9999, $r->postInt('poradi'))), 'zobrazit' => $r->postBool('zobrazit') ? 1 : 0,
            'jazyk' => Jazyk::sloupec($this->app->settings(), $r->post('jazyk')), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($idp > 0 && $this->db->value('SELECT idp FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$idp, $k['idk']]) !== null) {
            $this->db->update('kolekce_polozky', $radek, ['idp' => $idp]);
        } else {
            $idp = $this->db->insert('kolekce_polozky', $radek + ['datum' => date('Y-m-d H:i:s')]);
        }
        \Kaleta\Front\Cache::vymaz();
        if ($chyby !== []) {
            return $this->zpet(t('Položka je uložená, ale tato pole měla neplatnou hodnotu a zůstala prázdná: %s', implode(', ', $chyby)), 'polozka', ['id' => $k['idk'], 'polozka' => $idp], 'chyba');
        }

        return $this->zpet('Položka byla uložena.', 'polozky', ['id' => $k['idk']]);
    }

    /** Kopie položky (skrytá, s volnou adresou) – rychlý začátek podobné reference, člena týmu, produktu. */
    protected function akceDuplikujPolozku(): Response
    {
        $idk = $this->request->postInt('idk');
        $p = $this->request->isPost() ? $this->db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$this->request->postInt('idp'), $idk]) : null;
        if ($p === null) {
            return $this->zpet('', 'polozky', ['id' => $idk]);
        }
        $seo = mb_substr($p['seo_link'] . '-kopie', 0, 150);
        for ($i = 2, $zaklad = $seo; $this->db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ?', [$idk, $seo]) !== null; $i++) {
            $seo = $zaklad . '-' . $i;
        }
        $id = $this->db->insert('kolekce_polozky', ['idk' => $idk, 'nazev' => mb_substr(t('%s (kopie)', $p['nazev']), 0, 200), 'seo_link' => $seo, 'data' => $p['data'],
            'poradi' => $p['poradi'], 'zobrazit' => 0, 'jazyk' => $p['jazyk'], 'datum' => date('Y-m-d H:i:s')]);

        return $this->zpet('Kopie položky je skrytá – upravte ji a zveřejněte.', 'polozka', ['id' => $idk, 'polozka' => $id]);
    }

    protected function akceSmazPolozku(): Response
    {
        $idk = $this->request->postInt('idk');
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [$this->request->postInt('idp'), $idk]);
            \Kaleta\Front\Cache::vymaz();
        }

        return $this->zpet('Položka byla smazána.', 'polozky', ['id' => $idk]);
    }

    /* ---------- šablona detailu v builderu (správce) ---------- */

    protected function akceStavitel(): Response
    {
        if (($odmitnuti = $this->spravce()) !== null) {
            return $odmitnuti;
        }
        $k = KolekceObsahu::podleId($this->db, $this->request->getInt('id'));
        if ($k !== null && $k['stavba'] === null && $k['stavba_koncept'] === null) {
            $this->db->update('kolekce', ['stavba_koncept' => Stavba::naJson(KolekceObsahu::vychoziSablona($k))], ['idk' => $k['idk']]);
        }

        return $this->editorStavby();
    }

    protected function cilStavby(): ?array
    {
        if (!$this->app->auth()->isAdmin()) {
            return null;
        }
        $k = KolekceObsahu::podleId($this->db, $this->request->getInt('id'));

        return $k === null ? null : [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Jazyk::vychozi($this->app->settings()),
            'titulek' => t('Detail: %s', $k['nazev']), 'revize' => ['cast' => 'kolekce:' . (int) $k['idk']], 'parametry' => ['id' => (int) $k['idk']],
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('kolekce', ['stavba_koncept' => $koncept], ['idk' => $cil['radek']['idk']]);
    }

    protected function publikujCil(array $cil): void
    {
        Publikace::kolekce($this->app, $cil['radek']);
    }

    protected function editorCile(array $cil): array
    {
        $k = $cil['radek'];
        $seo = $this->db->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 ORDER BY poradi, nazev LIMIT 1', [$k['idk']]);
        $adresa = $this->app->url($k['seo_link'] . '/' . ($seo ?? '_ukazka'));

        return [
            'adresa' => $adresa, 'nahled' => $adresa . '?stavba=koncept&editor=1', 'zobrazena' => (bool) $k['detail'], 'casti' => false,
            'zpet' => ['adresa' => $this->url('polozky', ['id' => (int) $k['idk']]), 'text' => $k['nazev']], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idk']]),
            'kolekce' => ['seo_link' => $k['seo_link'], 'nazev' => $k['nazev'], 'pole' => $k['pole'], 'detail' => (bool) $k['detail']],
            'podpis' => 'kolekce:' . (int) $k['idk'],
        ];
    }

    private function spravce(): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->chyba('Definici kolekce a šablonu detailu mění jen správce webu.', 403);
    }
}
