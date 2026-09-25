<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Response;

/**
 * Poptávky a zprávy z formulářů webu (prvek Formulář v builderu, Front\Formulare). Stav: 0 nová, 1 přečtená, 2 vyřízená.
 * Obsahují osobní údaje – po nastaveném počtu měsíců se samy mažou a jdou vyvézt do CSV.
 */
final class Poptavky extends Modul
{
    public const string IDENT = 'poptavky';
    public const string ROZSIRENI = 'poptavky';
    public const string NAZEV = 'Poptávky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'poptavky';

    public const array STAVY = [0 => 'nová', 1 => 'přečtená', 2 => 'vyřízená'];
    private const int NA_STRANU = 50;

    protected function akceVypis(): Response
    {
        self::promazStare($this->db, $this->app->settings());
        $filtr = $this->request->get('stav');
        $podminky = match ($filtr) {
            'otevrene' => ['stav < 2'],
            'vyrizene' => ['stav = 2'],
            'moje' => ['prirazeno = ' . (int) $this->app->auth()->id()],
            default => [],
        };
        $params = [];
        $hledat = mb_substr(trim($this->request->get('hledat')), 0, 100);
        if ($hledat !== '') {
            $podminky[] = '(email LIKE ? OR formular LIKE ? OR data LIKE ? OR poznamka LIKE ?)';
            // data jsou JSON s \uXXXX místo diakritiky – hledá se i v té podobě
            $vzor = '%' . addcslashes($hledat, '%_\\') . '%';
            $vzorJson = '%' . addcslashes(substr((string) json_encode($hledat), 1, -1), '%_\\') . '%';
            array_push($params, $vzor, $vzor, $vzorJson, $vzor);
        }
        $kde = $podminky === [] ? '' : 'WHERE ' . implode(' AND ', $podminky);
        $strana = max(1, $this->request->getInt('strana', 1));

        return $this->view('vypis', 'Poptávky', [
            'poptavky' => $this->db->all('SELECT idp, datum, formular, stranka, email, stav, data, prirazeno FROM {poptavky} ' . $kde . ' ORDER BY idp DESC LIMIT ' . self::NA_STRANU . ' OFFSET ' . (($strana - 1) * self::NA_STRANU), $params),
            'celkem' => (int) $this->db->value('SELECT COUNT(*) FROM {poptavky} ' . $kde, $params),
            'filtr' => $filtr, 'hledat' => $hledat, 'strana' => $strana, 'naStranu' => self::NA_STRANU,
            'uzivatele' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2"),
            'mesice' => $this->app->settings()->int('poptavky_mesice'),
        ]);
    }

    protected function akceDetail(): Response
    {
        $p = $this->db->one('SELECT * FROM {poptavky} WHERE idp = ?', [$this->request->getInt('id')]);
        if ($p === null) {
            return $this->chyba('Poptávka neexistuje.', 404);
        }
        if ((int) $p['stav'] === 0) {
            $this->db->update('poptavky', ['stav' => 1], ['idp' => $p['idp']]);
            $p['stav'] = 1;
        }

        return $this->view('detail', t('Poptávka') . ' #' . $p['idp'], ['p' => $p, 'data' => json_decode((string) $p['data'], true) ?: [],
            'uzivatele' => $this->resitele((int) $p['prirazeno'])]);
    }

    /**
     * Kdo může poptávku vyřizovat: aktivní správci a uživatelé s právem k Poptávkám (ne třeba autor novinek, který je nevidí).
     * Už přiřazený uživatel v seznamu zůstane, i když právo mezitím ztratil – uložení poznámky ho potichu neodebere.
     *
     * @return array<int, string>
     */
    private function resitele(int $prirazeny = 0): array
    {
        return $this->db->pairs(
            "SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} u WHERE (blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))) OR idu = ? ORDER BY 2",
            [\Kaleta\Core\Auth::ADMIN, self::IDENT, $prirazeny],
        );
    }

    /** Interní poznámka a kdo poptávku vyřizuje. */
    protected function akcePoznamka(): Response
    {
        $idp = $this->request->postInt('idp');
        if ($this->request->isPost()) {
            $kdo = $this->request->postInt('prirazeno');
            $this->db->update('poptavky', ['poznamka' => mb_substr(trim($this->request->post('poznamka')), 0, 5000),
                'prirazeno' => $kdo > 0 && isset($this->resitele((int) $this->db->value('SELECT prirazeno FROM {poptavky} WHERE idp = ?', [$idp]))[$kdo]) ? $kdo : null], ['idp' => $idp]);
        }

        return $this->zpet('Poznámka byla uložena.', 'detail', ['id' => $idp]);
    }

    /** Příloha z formuláře ke stažení (jen přihlášenému s přístupem k poptávkám). */
    protected function akcePriloha(): Response
    {
        $p = $this->db->one('SELECT data FROM {poptavky} WHERE idp = ?', [$this->request->getInt('id')]);
        $polozka = ($p !== null ? (json_decode((string) $p['data'], true) ?: []) : [])[$this->request->getInt('pole')] ?? null;
        $cesta = is_array($polozka) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#', (string) ($polozka[2] ?? '')) ? KALETA_ROOT . '/storage/prilohy/' . $polozka[2] : null;
        if ($cesta === null || !is_file($cesta)) {
            return $this->chyba('Příloha už neexistuje.', 404);
        }
        $jmeno = preg_replace('/ \([^)]*\)$/', '', (string) $polozka[1]) ?: basename($cesta);

        return new Response((string) file_get_contents($cesta), 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($jmeno)]);
    }

    /** Hromadně: označit jako vyřízené, nebo smazat (i s přílohami). */
    protected function akceHromadne(): Response
    {
        $ids = array_map('intval', $this->request->postList('oznacene'));
        if (!$this->request->isPost() || $ids === []) {
            return $this->zpet();
        }
        $v = implode(',', $ids);
        if ($this->request->post('provest') === 'smazat') {
            self::smazPrilohy($this->db->all('SELECT data FROM {poptavky} WHERE idp IN (' . $v . ')'));
            $this->db->run('DELETE FROM {poptavky} WHERE idp IN (' . $v . ')');

            return $this->zpet(t('Smazáno poptávek: %d.', count($ids)));
        }
        $this->db->run('UPDATE {poptavky} SET stav = 2 WHERE idp IN (' . $v . ')');

        return $this->zpet(t('Vyřízeno poptávek: %d.', count($ids)));
    }

    /** @param list<array{data: string}> $radky */
    private static function smazPrilohy(array $radky): void
    {
        foreach ($radky as $r) {
            foreach (json_decode((string) $r['data'], true) ?: [] as $polozka) {
                if (is_array($polozka) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#', (string) ($polozka[2] ?? ''))) {
                    @unlink(KALETA_ROOT . '/storage/prilohy/' . $polozka[2]);
                }
            }
        }
    }

    protected function akceStav(): Response
    {
        if ($this->request->isPost()) {
            $stav = $this->request->postInt('stav');
            $this->db->update('poptavky', ['stav' => isset(self::STAVY[$stav]) ? $stav : 1], ['idp' => $this->request->postInt('idp')]);
        }

        return $this->zpet($this->request->postInt('stav') === 2 ? 'Poptávka je vyřízená.' : 'Poptávka je znovu otevřená.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            self::smazPrilohy($this->db->all('SELECT data FROM {poptavky} WHERE idp = ?', [$this->request->postInt('idp')]));
            $this->db->delete('poptavky', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->zpet('Poptávka byla smazána.');
    }

    /** Uložení doby, po které se poptávky samy mažou (jen správce). */
    protected function akceNastaveni(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->app->settings()->set('poptavky_mesice', (string) max(0, min(120, $this->request->postInt('mesice'))));
        }

        return $this->zpet('Nastavení poptávek bylo uloženo.');
    }

    /** Všechny poptávky do CSV (UTF-8 s BOM, středník – otevře se rovnou v Excelu). */
    protected function akceCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Číslo'), t('Datum'), t('Formulář'), t('Stav'), t('E-mail'), t('Stránka'), t('Kampaň'), t('Obsah')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {poptavky} ORDER BY idp') as $p) {
            $obsah = implode("\n", array_map(fn (array $d): string => $d[0] . ': ' . $d[1], json_decode((string) $p['data'], true) ?: []));
            // buňka začínající = + - @ by se v tabulkovém procesoru spustila jako vzorec
            $radek = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $p['idp'], (string) $p['datum'], (string) $p['formular'], t(self::STAVY[(int) $p['stav']] ?? ''), (string) $p['email'], (string) $p['stranka'], \Kaleta\Front\Formulare::kampanText((string) ($p['kampan'] ?? '')), $obsah]);
            fputcsv($f, $radek, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\Protokol::zapis($this->app, 'poptavky', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="poptavky-' . date('Y-m-d') . '.csv"']);
    }

    /** Smaže poptávky starší než nastavený počet měsíců. */
    /** Smaže poptávky starší než nastavený počet měsíců i s přílohami (volá i úklid na pozadí, Core\Oznameni). */
    public static function promazStare(\Kaleta\Core\Db $db, \Kaleta\Core\Settings $web): void
    {
        $mesice = $web->int('poptavky_mesice');
        if ($mesice > 0) {
            self::smazPrilohy($db->all('SELECT data FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$mesice]));
            $db->run('DELETE FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$mesice]);
        }
    }
}
