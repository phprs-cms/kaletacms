<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Poptávky a zprávy z formulářů webu (prvek Formulář ve staviteli, Front\Formulare). Stav: 0 nová, 1 přečtená, 2 vyřízená.
 * Obsahují osobní údaje – po nastaveném počtu měsíců se samy mažou a jdou vyvézt do CSV.
 */
final class Poptavky extends Modul
{
    public const string IDENT = 'poptavky';
    public const string NAZEV = 'Poptávky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'poptavky';

    public const array STAVY = [0 => 'nová', 1 => 'přečtená', 2 => 'vyřízená'];
    private const int NA_STRANU = 50;

    protected function akceVypis(): Response
    {
        $this->promaz();
        $filtr = $this->request->get('stav');
        $kde = match ($filtr) {
            'otevrene' => 'WHERE stav < 2',
            'vyrizene' => 'WHERE stav = 2',
            default => '',
        };
        $strana = max(1, $this->request->getInt('strana', 1));

        return $this->view('vypis', 'Poptávky', [
            'poptavky' => $this->db->all('SELECT idp, datum, formular, stranka, email, stav, data FROM {poptavky} ' . $kde . ' ORDER BY idp DESC LIMIT ' . self::NA_STRANU . ' OFFSET ' . (($strana - 1) * self::NA_STRANU)),
            'celkem' => (int) $this->db->value('SELECT COUNT(*) FROM {poptavky} ' . $kde),
            'filtr' => $filtr, 'strana' => $strana, 'naStranu' => self::NA_STRANU,
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

        return $this->view('detail', t('Poptávka') . ' #' . $p['idp'], ['p' => $p, 'data' => json_decode((string) $p['data'], true) ?: []]);
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
        fputcsv($f, [t('Číslo'), t('Datum'), t('Formulář'), t('Stav'), t('E-mail'), t('Stránka'), t('Obsah')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {poptavky} ORDER BY idp') as $p) {
            $obsah = implode("\n", array_map(fn (array $d): string => $d[0] . ': ' . $d[1], json_decode((string) $p['data'], true) ?: []));
            // buňka začínající = + - @ by se v tabulkovém procesoru spustila jako vzorec
            $radek = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $p['idp'], (string) $p['datum'], (string) $p['formular'], t(self::STAVY[(int) $p['stav']] ?? ''), (string) $p['email'], (string) $p['stranka'], $obsah]);
            fputcsv($f, $radek, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'poptavky', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="poptavky-' . date('Y-m-d') . '.csv"']);
    }

    /** Smaže poptávky starší než nastavený počet měsíců. */
    private function promaz(): void
    {
        $mesice = $this->app->settings()->int('poptavky_mesice');
        if ($mesice > 0) {
            $this->db->run('DELETE FROM {poptavky} WHERE datum < NOW() - INTERVAL ? MONTH', [$mesice]);
        }
    }
}
