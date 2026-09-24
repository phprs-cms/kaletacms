<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Admin\StavitelAkce;
use Kaleta\Core\Jazyk;
use Kaleta\Core\Response;
use Kaleta\Stavitel\Komponenty as KomponentyStavby;
use Kaleta\Stavitel\Publikace;
use Kaleta\Stavitel\Stavba;

/**
 * Komponenty – znovupoužitelné bloky (karta služby, blok s kontaktem, výzva…). Vznikají ve staviteli tlačítkem
 * „Uložit jako komponentu“ nebo tady; upravují se ve staviteli a změna se projeví všude, kde jsou použité.
 */
final class Komponenty extends Modul
{
    use StavitelAkce {
        akceStavitel as protected editorStavby;
    }

    public const string IDENT = 'komponenty';
    public const string NAZEV = 'Komponenty';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'komponenta';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $komponenty = KomponentyStavby::vsechny($this->db);
        foreach ($komponenty as &$k) {
            $k['pouziti'] = $this->pouziti((int) $k['idm']);
        }
        unset($k);

        return $this->view('vypis', 'Komponenty', ['komponenty' => $komponenty]);
    }

    protected function akceNovy(): Response
    {
        return $this->view('formular', 'Nová komponenta', ['k' => ['idm' => 0, 'nazev' => '', 'vlastnosti' => []]]);
    }

    protected function akceEdit(): Response
    {
        $k = KomponentyStavby::podleId($this->db, $this->request->getInt('id'));

        return $k === null ? $this->chyba('Komponenta neexistuje.', 404) : $this->view('formular', $k['nazev'], ['k' => $k]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $id = $this->request->postInt('idm');
        $nazev = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if ($nazev === '') {
            return $this->zpet('Komponenta musí mít název.', $id > 0 ? 'edit' : 'novy', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        $data = ['nazev' => $nazev, 'vlastnosti' => (string) json_encode(KomponentyStavby::vycistiVlastnosti(is_array($_POST['vlastnosti'] ?? null) ? $_POST['vlastnosti'] : []), JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')];
        if ($id > 0 && KomponentyStavby::podleId($this->db, $id) !== null) {
            $this->db->update('komponenty', $data, ['idm' => $id]);
        } else {
            $id = $this->db->insert('komponenty', $data + ['stavba_koncept' => Stavba::naJson(['v' => Stavba::VERZE, 'deti' => [Stavba::novy('sekce')]])]);
        }
        \Kaleta\Front\Cache::vymaz();

        return $this->zpet('Komponenta byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('komponenty', ['idm' => $this->request->postInt('idm')]);
            \Kaleta\Front\Cache::vymaz();
        }

        return $this->zpet('Komponenta byla smazána. Místa, kde byla použitá, zůstanou prázdná.');
    }

    /** Ze stavitele: vybraný prvek se stane komponentou (JSON). Editor ho pak nahradí jejím použitím. */
    protected function akceZPrvku(): Response
    {
        $prvek = $this->request->isPost() ? json_decode((string) ($_POST['prvek'] ?? ''), true) : null;
        $nazev = mb_substr(trim($this->request->post('nazev')), 0, 100);
        if (!is_array($prvek) || $nazev === '') {
            return Response::json(['ok' => false, 'chyba' => t('Chybí název nebo prvek.')], 400);
        }
        [$stavba] = Stavba::vycisti(['v' => Stavba::VERZE, 'deti' => [$prvek]], true);
        if ($stavba['deti'] === []) {
            return Response::json(['ok' => false, 'chyba' => t('Z tohoto prvku komponenta vzniknout nemůže.')], 400);
        }
        $id = $this->db->insert('komponenty', ['nazev' => $nazev, 'vlastnosti' => '[]', 'stavba' => Stavba::naJson($stavba), 'zmeneno' => date('Y-m-d H:i:s')]);

        return Response::json(['ok' => true, 'id' => $id, 'komponenty' => self::proEditor($this->db)]);
    }

    /** @return list<array{id: int, nazev: string, vlastnosti: list<array<string, string>>}> */
    public static function proEditor(\Kaleta\Core\Db $db): array
    {
        return array_map(fn (array $k): array => ['id' => (int) $k['idm'], 'nazev' => $k['nazev'], 'vlastnosti' => $k['vlastnosti']], KomponentyStavby::vsechny($db));
    }

    /* ---------- úprava ve staviteli ---------- */

    protected function akceStavitel(): Response
    {
        return $this->editorStavby();
    }

    protected function cilStavby(): ?array
    {
        $k = KomponentyStavby::podleId($this->db, $this->request->getInt('id'));

        return $k === null ? null : [
            'radek' => $k, 'stavba' => $k['stavba'], 'koncept' => $k['stavba_koncept'], 'jazyk' => Jazyk::vychozi($this->app->settings()),
            'titulek' => t('Komponenta: %s', $k['nazev']), 'revize' => ['cast' => 'komponenta:' . (int) $k['idm']], 'parametry' => ['id' => (int) $k['idm']],
        ];
    }

    protected function ulozKoncept(array $cil, ?string $koncept): void
    {
        $this->db->update('komponenty', ['stavba_koncept' => $koncept], ['idm' => $cil['radek']['idm']]);
    }

    protected function publikujCil(array $cil): void
    {
        Publikace::komponenta($this->app, $cil['radek']);
    }

    protected function editorCile(array $cil): array
    {
        $k = $cil['radek'];
        $adresa = $this->app->url('_komponenta/' . (int) $k['idm']);

        return [
            'adresa' => $adresa . '?stavba=koncept', 'nahled' => $adresa . '?stavba=koncept&editor=1', 'zobrazena' => true, 'casti' => false,
            'zpet' => ['adresa' => $this->url(), 'text' => t('Komponenty')], 'nastaveni' => $this->url('edit', ['id' => (int) $k['idm']]),
            // nápověda {{vlastností}} v editoru (stejná jako u kolekce, jen bez vestavěných hodnot)
            'kolekce' => ['seo_link' => '', 'nazev' => $k['nazev'], 'pole' => $k['vlastnosti'], 'detail' => false, 'vestavene' => false],
        ];
    }

    /** Kolikrát je komponenta použitá (stránky, části webu, kolekce, jiné komponenty). */
    private function pouziti(int $idm): int
    {
        $vzor = '%"typ":"komponenta"%"komponenta":"' . $idm . '"%';
        $pocet = 0;
        foreach (['stranky', 'casti', 'kolekce', 'komponenty'] as $tabulka) {
            // komponenta sama v sobě se nepočítá (a na webu se ani nevykreslí)
            $pocet += (int) $this->db->value('SELECT COUNT(*) FROM {' . $tabulka . '} WHERE (stavba LIKE ? OR stavba_koncept LIKE ?)' . ($tabulka === 'komponenty' ? ' AND idm <> ?' : ''), [$vzor, $vzor, ...($tabulka === 'komponenty' ? [$idm] : [])]);
        }

        return $pocet;
    }
}
