<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Obrazky;
use MiroCMS\Core\Response;

/**
 * Média: nahrávání i přetažením a přímo z editoru, složky,
 * popisky, mazání a přehled, ve kterých článcích je obrázek použitý.
 * Obrázek se do textu vkládá z editoru.
 */
final class Galerie extends Modul
{
    public const string IDENT = 'intergal';
    public const string NAZEV = 'Média';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'media';

    /** Nahrávat musí umět každý, kdo píše novinky; cizí obrázky ale mění a maže jen správce. */
    public const bool PRO_VSECHNY = true;

    private const int NA_STRANKU = 40;

    protected function akceVypis(): Response
    {
        $strana = max(1, $this->request->getInt('strana', 1));
        [$where, $params, $filtr] = $this->filtr();
        $celkem = (int) $this->db->value("SELECT COUNT(*) FROM {imggal_obr} o WHERE {$where}", $params);

        return $this->view('vypis', 'Média', [
            'obrazky' => $this->nacti($where, $params, $strana, self::NA_STRANKU),
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / self::NA_STRANKU)),
            'celkem' => $celkem,
            'limit' => ini_get('upload_max_filesize'),
            'filtr' => $filtr,
            'slozky' => $this->slozky(),
            'clanek' => $filtr['clanek'] > 0 ? $this->db->value('SELECT titulek FROM {clanky} WHERE idc = ?', [$filtr['clanek']]) : null,
        ]);
    }

    /** JSON seznam pro okno výběru obrázku v editoru; filtry stejné jako ve výpisu. */
    protected function akceSeznam(): Response
    {
        [$where, $params] = $this->filtr();

        return Response::json([
            'obrazky' => array_map($this->proJson(...), $this->nacti($where, $params, max(1, $this->request->getInt('strana', 1)), 60)),
            'slozky' => array_map(fn (array $s): array => ['id' => (int) $s['ids'], 'nazev' => $s['nazev']], $this->slozky()),
        ]);
    }

    /** Založení nebo přejmenování složky. */
    protected function akceSlozka(): Response
    {
        $nazev = mb_substr($this->request->post('nazev'), 0, 100);
        if (!$this->request->isPost() || $nazev === '') {
            return $this->zpet();
        }
        $ids = $this->request->postInt('ids');
        if ($ids > 0) {
            $this->db->update('imggal_sekce', ['nazev' => $nazev], ['ids' => $ids]);
        } else {
            $ids = $this->db->insert('imggal_sekce', ['nazev' => $nazev]);
        }

        return $this->zpet('Složka byla uložena.', '', ['sekce' => $ids]);
    }

    /** Smazání složky; obrázky zůstávají a přejdou mezi nezařazené. */
    protected function akceSlozkaSmaz(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->db->delete('imggal_sekce', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->zpet('Složka byla smazána, její obrázky jsou mezi nezařazenými.');
    }

    /**
     * Přepočítá, které obrázky novinka používá: hlavní obrázek, obrázky vložené editorem
     * (data-id, adresa souboru). Volá se při uložení novinky.
     */
    public static function zapisPouziti(\MiroCMS\Core\Db $db, int $idc, string ...$html): void
    {
        $vse = implode(' ', $html);
        preg_match_all('/data-id="(\d+)"/', $vse, $m);
        $ids = array_map(intval(...), $m[1]);
        preg_match_all('#media/\d{4}/\d{2}/[a-z0-9-]+\.(?:jpg|png|webp|gif)#', $vse, $cesty);
        foreach (array_unique($cesty[0]) as $cesta) {
            $ido = $db->value('SELECT ido FROM {imggal_obr} WHERE obr_poloha = ? OR nahl_poloha = ?', [$cesta, $cesta]);
            if ($ido !== null) {
                $ids[] = (int) $ido;
            }
        }
        $db->delete('imggal_pouziti', ['idc' => $idc]);
        foreach (array_unique($ids) as $ido) {
            $db->run('INSERT IGNORE INTO {imggal_pouziti} (ido, idc) SELECT ido, ? FROM {imggal_obr} WHERE ido = ?', [$idc, $ido]);
        }
    }

    /** Nahrání jednoho či více souborů; s parametrem format=json odpovídá editoru JSONem. */
    protected function akceNahraj(): Response
    {
        $json = $this->request->get('format') === 'json';
        $sekce = $this->db->value('SELECT ids FROM {imggal_sekce} WHERE ids = ?', [$this->request->postInt('sekce')]);
        $sekce = $sekce === null ? null : (int) $sekce;
        $nahrane = [];
        $chyby = [];
        foreach ($this->soubory() as $file) {
            try {
                $data = \MiroCMS\Core\Soubory::jePriloha((string) ($file['name'] ?? '')) ? \MiroCMS\Core\Soubory::uloz($file) : Obrazky::uloz($file);
                $data['ido'] = $this->db->insert('imggal_obr', $data + ['vlastnik' => $this->app->auth()->id(), 'sekce' => $sekce, 'datum' => date('Y-m-d H:i:s')]);
                $nahrane[] = $this->proJson($data + ['popis' => '']);
            } catch (\RuntimeException $e) {
                $chyby[] = ($file['name'] ?? t('soubor')) . ': ' . t($e->getMessage());
            }
        }
        if ($nahrane === [] && $chyby === []) {
            $chyby[] = t('Nebyl vybrán žádný soubor.');
        }
        if ($json) {
            return Response::json(['obrazky' => $nahrane, 'chyby' => $chyby], $nahrane === [] ? 400 : 200);
        }
        foreach ($chyby as $chyba) {
            $this->app->session->flash('chyba', $chyba);
        }

        return $this->zpet($nahrane !== [] ? t('Nahráno souborů: %d.', count($nahrane)) : '', '', $sekce !== null ? ['sekce' => $sekce] : []);
    }

    protected function akceUloz(): Response
    {
        if ($this->request->isPost() && $this->smiMenit($this->request->postInt('ido'))) {
            $this->db->update('imggal_obr', [
                'nazev' => mb_substr($this->request->post('nazev'), 0, 150),
                'popis' => mb_substr($this->request->post('popis'), 0, 500),
                'autor' => mb_substr(trim($this->request->post('autor')), 0, 120),
            ], ['ido' => $this->request->postInt('ido')]);
        }

        return $this->zpet('Popis obrázku byl uložen.');
    }

    /** Hromadná akce nad označenými obrázky: smazání, nebo přesun do složky. */
    protected function akceHromadne(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $presun = $this->request->post('provest') === 'presun';
        $cil = $this->request->postInt('do_sekce') ?: null;
        $pocet = 0;
        foreach ($this->request->postList('oznacene') as $id) {
            $obr = $this->db->one('SELECT * FROM {imggal_obr} WHERE ido = ?', [(int) $id]);
            if ($obr === null || !$this->smiMenit((int) $obr['ido'])) {
                continue;
            }
            if ($presun) {
                $pocet += $this->db->update('imggal_obr', ['sekce' => $cil], ['ido' => $obr['ido']]) >= 0 ? 1 : 0;
            } else {
                Obrazky::smaz($obr['obr_poloha'], $obr['nahl_poloha']);
                \MiroCMS\Core\Soubory::smaz($obr['obr_poloha']);
                $pocet += $this->db->delete('imggal_obr', ['ido' => $obr['ido']]);
            }
        }

        return $this->zpet($presun ? t('Přesunuto obrázků: %d.', $pocet) : t('Smazáno obrázků: %d.', $pocet), '', $presun && $cil ? ['sekce' => $cil] : []);
    }

    private function smiMenit(int $ido): bool
    {
        $vlastnik = $this->db->value('SELECT vlastnik FROM {imggal_obr} WHERE ido = ?', [$ido]);

        return $this->app->auth()->isAdmin() || (int) $vlastnik === $this->app->auth()->id();
    }

    /**
     * Filtr výpisu z adresy: sekce (číslo složky, 0 = nezařazené), clanek (idc), nepouzite=1, hledat (název, popisek nebo jméno souboru).
     *
     * @return array{0: string, 1: list<int|string>, 2: array{sekce: ?int, clanek: int, nepouzite: bool}}
     */
    private function filtr(): array
    {
        $where = ['1 = 1'];
        $params = [];
        $sekce = $this->request->get('sekce') === '' ? null : $this->request->getInt('sekce');
        if ($sekce !== null) {
            $where[] = $sekce > 0 ? 'o.sekce = ?' : 'o.sekce IS NULL';
            if ($sekce > 0) {
                $params[] = $sekce;
            }
        }
        $clanek = $this->request->getInt('clanek');
        if ($clanek > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {imggal_pouziti} p WHERE p.ido = o.ido AND p.idc = ?)';
            $params[] = $clanek;
        }
        $hledat = mb_substr(trim($this->request->get('hledat')), 0, 100);
        if ($hledat !== '') {
            $where[] = '(o.nazev LIKE ? OR o.popis LIKE ? OR o.obr_poloha LIKE ?)';
            $vzor = '%' . addcslashes($hledat, '%_\\') . '%';
            array_push($params, $vzor, $vzor, $vzor);
        }
        $nepouzite = $this->request->get('nepouzite') === '1';
        if ($nepouzite) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {imggal_pouziti} p WHERE p.ido = o.ido)';
        }

        return [implode(' AND ', $where), $params, ['sekce' => $sekce, 'clanek' => $clanek, 'nepouzite' => $nepouzite]];
    }

    /** @return list<array<string, mixed>> složky s počtem obrázků */
    private function slozky(): array
    {
        return $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {imggal_obr} o WHERE o.sekce = s.ids) AS pocet FROM {imggal_sekce} s ORDER BY s.nazev');
    }

    /** @return list<array<string, mixed>> */
    private function nacti(string $where, array $params, int $strana, int $pocet): array
    {
        return $this->db->all(
            "SELECT o.*, (SELECT COUNT(*) FROM {imggal_pouziti} p WHERE p.ido = o.ido) AS pouzito
             FROM {imggal_obr} o WHERE {$where} ORDER BY o.ido DESC LIMIT ? OFFSET ?",
            [...$params, $pocet, ($strana - 1) * $pocet],
        );
    }

    /** @param array<string, mixed> $o */
    private function proJson(array $o): array
    {
        return [
            'id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'popis' => $o['popis'] ?? '',
            'url' => $this->app->url($o['obr_poloha']), 'nahled' => $o['nahl_poloha'] === '' ? '' : $this->app->url($o['nahl_poloha']),
            'sirka' => (int) $o['obr_width'], 'vyska' => (int) $o['obr_height'],
            // příloha ke stažení (PDF, dokument, zvuk…): bez náhledu, do textu se vkládá jako odkaz
            'soubor' => $o['nahl_poloha'] === '', 'pripona' => strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION)), 'velikost' => \MiroCMS\Core\Soubory::velikost((int) ($o['obr_vel'] ?? 0)),
        ];
    }

    /** $_FILES['soubory'] (i vícenásobné) převedené na seznam jednotlivých souborů. */
    private function soubory(): array
    {
        $f = $_FILES['soubory'] ?? null;
        if (!is_array($f)) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $soubory = [];
        foreach (array_keys($f['name']) as $i) {
            if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $soubory[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        }

        return array_slice($soubory, 0, 30);
    }
}
