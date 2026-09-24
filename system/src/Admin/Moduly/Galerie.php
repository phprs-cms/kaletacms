<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Obrazky;
use Kaleta\Core\Response;

/**
 * Média: nahrávání i přetažením a přímo z editoru, složky,
 * popisky, mazání a přehled, kde je obrázek použitý (novinky, stavby, kolekce, logo…).
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
        $celkem = (int) $this->db->value("SELECT COUNT(*) FROM {media} o WHERE {$where}", $params);

        return $this->view('vypis', 'Média', [
            'obrazky' => $this->nacti($where, $params, $strana, self::NA_STRANKU),
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / self::NA_STRANKU)),
            'celkem' => $celkem,
            'limit' => ini_get('upload_max_filesize'),
            'filtr' => $filtr,
            'slozky' => $this->slozky(),
            'clanek' => $filtr['clanek'] > 0 ? $this->db->value('SELECT titulek FROM {novinky} WHERE idc = ?', [$filtr['clanek']]) : null,
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
            $this->db->update('media_slozky', ['nazev' => $nazev], ['ids' => $ids]);
        } else {
            $ids = $this->db->insert('media_slozky', ['nazev' => $nazev]);
        }

        return $this->zpet('Složka byla uložena.', '', ['sekce' => $ids]);
    }

    /** Smazání složky; obrázky zůstávají a přejdou mezi nezařazené. */
    protected function akceSlozkaSmaz(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->db->delete('media_slozky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->zpet('Složka byla smazána, její obrázky jsou mezi nezařazenými.');
    }

    /**
     * Přepočítá, které obrázky novinka používá: hlavní obrázek, obrázky vložené editorem
     * (data-id, adresa souboru). Volá se při uložení novinky.
     */
    public static function zapisPouziti(\Kaleta\Core\Db $db, int $idc, string ...$html): void
    {
        $vse = implode(' ', $html);
        preg_match_all('/data-id="(\d+)"/', $vse, $m);
        $ids = array_map(intval(...), $m[1]);
        preg_match_all('#media/\d{4}/\d{2}/[a-z0-9-]+\.(?:jpg|png|webp|gif)#', $vse, $cesty);
        foreach (array_unique($cesty[0]) as $cesta) {
            $ido = $db->value('SELECT ido FROM {media} WHERE obr_poloha = ? OR nahl_poloha = ?', [$cesta, $cesta]);
            if ($ido !== null) {
                $ids[] = (int) $ido;
            }
        }
        $db->delete('media_pouziti', ['idc' => $idc]);
        foreach (array_unique($ids) as $ido) {
            $db->run('INSERT IGNORE INTO {media_pouziti} (ido, idc) SELECT ido, ? FROM {media} WHERE ido = ?', [$idc, $ido]);
        }
    }

    /**
     * Média použitá mimo tabulku použití novinek: stavby stránek, částí webu, šablon kolekcí a komponent (i rozpracované),
     * textové stránky, položky kolekcí, třídy (obrázek pozadí) a nastavení (logo, ikona, obrázek pro sdílení).
     * Počítá se při zobrazení – přehled je tak vždy aktuální bez evidence při každém uložení.
     *
     * @return array<int, list<string>> ido => popisy míst
     */
    public static function pouzitiJinde(\Kaleta\Core\Db $db): array
    {
        $zdroje = [
            [t('stránka'), 'SELECT titulek AS kde, CONCAT_WS(\' \', text, stavba, stavba_koncept) AS obsah FROM {stranky}'],
            [t('část webu'), 'SELECT CONCAT(typ, IF(nazev = \'\', \'\', CONCAT(\' – \', nazev))) AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {casti}'],
            [t('kolekce'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {kolekce}'],
            [t('položka kolekce'), 'SELECT nazev AS kde, data AS obsah FROM {kolekce_polozky}'],
            [t('komponenta'), 'SELECT nazev AS kde, CONCAT_WS(\' \', stavba, stavba_koncept) AS obsah FROM {komponenty}'],
            [t('třída'), 'SELECT nazev AS kde, CONCAT_WS(\' \', styl, css) AS obsah FROM {tridy}'],
            [t('nastavení'), 'SELECT promenna AS kde, hodnota AS obsah FROM {nastaveni} WHERE hodnota LIKE \'%media%\''],
        ];
        $mista = [];
        foreach ($zdroje as [$druh, $sql]) {
            foreach ($db->all($sql) as $r) {
                // cesty i v JSON (media\/2026\/…), s adresou webu i bez ní
                preg_match_all('#media(?:\\\\?/)\d{4}(?:\\\\?/)\d{2}(?:\\\\?/)[A-Za-z0-9._-]+#', (string) $r['obsah'], $m);
                foreach ($m[0] as $cesta) {
                    $mista[str_replace('\\/', '/', $cesta)][$druh . ' ' . $r['kde']] = true;
                }
            }
        }
        if ($mista === []) {
            return [];
        }
        $pouzite = [];
        foreach ($db->all('SELECT ido, obr_poloha, nahl_poloha FROM {media}') as $o) {
            foreach ([$o['obr_poloha'], $o['nahl_poloha']] as $cesta) {
                if ($cesta !== '' && isset($mista[$cesta])) {
                    $pouzite[(int) $o['ido']] = array_keys(($pouzite[(int) $o['ido']] ?? []) + $mista[$cesta]);
                }
            }
        }

        return $pouzite;
    }

    /** Nahrání jednoho či více souborů; s parametrem format=json odpovídá editoru JSONem. */
    protected function akceNahraj(): Response
    {
        $json = $this->request->get('format') === 'json';
        $sekce = $this->db->value('SELECT ids FROM {media_slozky} WHERE ids = ?', [$this->request->postInt('sekce')]);
        $sekce = $sekce === null ? null : (int) $sekce;
        $nahrane = [];
        $chyby = [];
        foreach ($this->soubory() as $file) {
            try {
                $data = match (true) {
                    strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'svg' => self::ulozSvg($file),
                    \Kaleta\Core\Soubory::jePriloha((string) ($file['name'] ?? '')) => \Kaleta\Core\Soubory::uloz($file),
                    default => Obrazky::uloz($file),
                };
                $data['ido'] = $this->db->insert('media', $data + ['vlastnik' => $this->app->auth()->id(), 'sekce' => $sekce, 'datum' => date('Y-m-d H:i:s')]);
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

    /**
     * SVG (logo, ikona): vyčištěné na povolené značky a atributy; bez náhledu a variant, prohlížeč ho zmenší sám.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private static function ulozSvg(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        $svg = is_uploaded_file($tmp) && filesize($tmp) < 2_000_000 ? \Kaleta\Core\Svg::vycisti((string) file_get_contents($tmp)) : null;
        if ($svg === null) {
            throw new \RuntimeException('Soubor SVG se nepodařilo přečíst (nejvýš 2 MB, platné SVG).');
        }
        $slozka = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $slozka)) {
            mkdir(KALETA_ROOT . '/' . $slozka, 0775, true);
        }
        $nazev = pathinfo((string) ($file['name'] ?? 'obrazek'), PATHINFO_FILENAME);
        $cesta = $slozka . '/' . slugify($nazev, 60) . '-' . bin2hex(random_bytes(3)) . '.svg';
        file_put_contents(KALETA_ROOT . '/' . $cesta, $svg);
        [$w, $h] = \Kaleta\Core\Svg::rozmery($svg);

        return ['obr_poloha' => $cesta, 'obr_width' => min(65535, $w), 'obr_height' => min(65535, $h), 'obr_vel' => strlen($svg),
            'nahl_poloha' => $cesta, 'nahl_width' => min(65535, $w), 'nahl_height' => min(65535, $h), 'nazev' => mb_substr(str_replace(['_', '-'], ' ', $nazev), 0, 150)];
    }

    /** Nový soubor místo starého se stejnou adresou: odkazy na webu zůstanou a ukážou novou verzi. */
    protected function akceNahradit(): Response
    {
        $ido = $this->request->postInt('ido');
        $obr = $this->request->isPost() && $this->smiMenit($ido) ? $this->db->one('SELECT * FROM {media} WHERE ido = ?', [$ido]) : null;
        $soubor = $_FILES['soubor'] ?? null;
        if ($obr === null || !is_array($soubor)) {
            return $this->zpet();
        }
        try {
            $novy = Obrazky::nahrad($obr['obr_poloha'], $soubor);
        } catch (\RuntimeException $e) {
            return $this->zpet(t($e->getMessage()), 'vypis', ['uprav' => $ido], 'chyba');
        }
        $this->db->update('media', $novy + ['barva' => ''], ['ido' => $ido]);
        \Kaleta\Front\Cache::vymaz();

        return $this->zpet('Soubor byl nahrazen – všude, kde je použitý, se ukazuje nová verze.', 'vypis', ['uprav' => $ido]);
    }

    protected function akceUloz(): Response
    {
        if ($this->request->isPost() && $this->smiMenit($this->request->postInt('ido'))) {
            $x = max(0, min(100, $this->request->postInt('ohnisko_x', 50)));
            $y = max(0, min(100, $this->request->postInt('ohnisko_y', 50)));
            $this->db->update('media', [
                'nazev' => mb_substr($this->request->post('nazev'), 0, 150),
                'popis' => mb_substr($this->request->post('popis'), 0, 500),
                'autor' => mb_substr(trim($this->request->post('autor')), 0, 120),
                'ohnisko' => $x === 50 && $y === 50 ? '' : $x . '% ' . $y . '%',
            ], ['ido' => $this->request->postInt('ido')]);
            \Kaleta\Front\Cache::vymaz();
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
        $vynechano = 0;
        $jinde = $presun ? [] : self::pouzitiJinde($this->db);
        foreach ($this->request->postList('oznacene') as $id) {
            $obr = $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $id]);
            if ($obr === null || !$this->smiMenit((int) $obr['ido'])) {
                continue;
            }
            if ($presun) {
                $pocet += $this->db->update('media', ['sekce' => $cil], ['ido' => $obr['ido']]) >= 0 ? 1 : 0;
            } elseif (isset($jinde[(int) $obr['ido']]) || $this->db->value('SELECT 1 FROM {media_pouziti} WHERE ido = ? LIMIT 1', [$obr['ido']]) !== null) {
                $vynechano++; // použitý soubor by na webu zmizel – smaže se, až nebude nikde použitý
            } else {
                Obrazky::smaz($obr['obr_poloha'], $obr['nahl_poloha']);
                \Kaleta\Core\Soubory::smaz($obr['obr_poloha']);
                $pocet += $this->db->delete('media', ['ido' => $obr['ido']]);
            }
        }

        if ($vynechano > 0) {
            $this->app->session->flash('chyba', t('Nesmazáno %d použitých souborů – nejdřív je odeberte z webu (kde jsou použité, ukáže výpis).', $vynechano));
        }

        return $this->zpet($presun ? t('Přesunuto obrázků: %d.', $pocet) : t('Smazáno obrázků: %d.', $pocet), '', $presun && $cil ? ['sekce' => $cil] : []);
    }

    private function smiMenit(int $ido): bool
    {
        $vlastnik = $this->db->value('SELECT vlastnik FROM {media} WHERE ido = ?', [$ido]);

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
            $where[] = 'EXISTS (SELECT 1 FROM {media_pouziti} p WHERE p.ido = o.ido AND p.idc = ?)';
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
            $where[] = 'NOT EXISTS (SELECT 1 FROM {media_pouziti} p WHERE p.ido = o.ido)';
            $jinde = array_keys(self::pouzitiJinde($this->db));
            if ($jinde !== []) {
                $where[] = 'o.ido NOT IN (' . implode(',', array_map(intval(...), $jinde)) . ')';
            }
        }

        return [implode(' AND ', $where), $params, ['sekce' => $sekce, 'clanek' => $clanek, 'nepouzite' => $nepouzite]];
    }

    /** @return list<array<string, mixed>> složky s počtem obrázků */
    private function slozky(): array
    {
        return $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {media} o WHERE o.sekce = s.ids) AS pocet FROM {media_slozky} s ORDER BY s.nazev');
    }

    /** @return list<array<string, mixed>> */
    private function nacti(string $where, array $params, int $strana, int $pocet): array
    {
        $jinde = self::pouzitiJinde($this->db);

        return array_map(function (array $o) use ($jinde): array {
            // kde: novinky podle tabulky použití + místa mimo novinky
            $o['kde'] = $jinde[(int) $o['ido']] ?? [];
            $o['pouzito'] = (int) $o['pouzito'] + count($o['kde']);

            return $o;
        }, $this->db->all(
            "SELECT o.*, (SELECT COUNT(*) FROM {media_pouziti} p WHERE p.ido = o.ido) AS pouzito
             FROM {media} o WHERE {$where} ORDER BY o.ido DESC LIMIT ? OFFSET ?",
            [...$params, $pocet, ($strana - 1) * $pocet],
        ));
    }

    /** @param array<string, mixed> $o */
    private function proJson(array $o): array
    {
        return [
            'id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'popis' => $o['popis'] ?? '',
            'url' => $this->app->url($o['obr_poloha']), 'nahled' => $o['nahl_poloha'] === '' ? '' : $this->app->url($o['nahl_poloha']),
            'sirka' => (int) $o['obr_width'], 'vyska' => (int) $o['obr_height'],
            // příloha ke stažení (PDF, dokument, zvuk…): bez náhledu, do textu se vkládá jako odkaz
            'soubor' => $o['nahl_poloha'] === '', 'pripona' => strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION)), 'velikost' => \Kaleta\Core\Soubory::velikost((int) ($o['obr_vel'] ?? 0)),
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
