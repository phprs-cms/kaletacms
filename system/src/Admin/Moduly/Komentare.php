<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;
use MiroCMS\Front\Interakce;

/**
 * Komentáře: moderace. Čekající komentáře (režim schvalování nebo podezřelé z odkazů) se zde schvalují.
 */
final class Komentare extends Modul
{
    public const string IDENT = 'comment';
    public const string NAZEV = 'Komentáře';
    public const string SKUPINA = 'Čtenáři';
    public const string IKONA = 'komentare';
    public const string ROZSIRENI = 'komentare';

    private const int NA_STRANKU = 30;

    protected function akceVypis(): Response
    {
        $cekajici = $this->request->get('stav') === 'cekajici';
        $strana = max(1, $this->request->getInt('strana', 1));
        $where = $cekajici ? 'k.zobrazit = 0' : '1 = 1';
        $celkem = (int) $this->db->value("SELECT COUNT(*) FROM {komentare} k WHERE {$where}");

        return $this->view('vypis', 'Komentáře', [
            'komentare' => $this->db->all(
                "SELECT k.*, c.titulek, c.seo_link FROM {komentare} k JOIN {clanky} c ON c.idc = k.clanek WHERE {$where} ORDER BY k.idk DESC LIMIT ? OFFSET ?",
                [self::NA_STRANKU, ($strana - 1) * self::NA_STRANKU],
            ),
            'cekajici' => $cekajici,
            'pocetCekajicich' => (int) $this->db->value('SELECT COUNT(*) FROM {komentare} WHERE zobrazit = 0'),
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / self::NA_STRANKU)),
        ]);
    }

    /** Hromadná akce: schválit, skrýt, nebo smazat označené. */
    protected function akceHromadne(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $co = $this->request->post('provest');
        $clanky = [];
        foreach ($this->request->postList('oznacene') as $id) {
            $k = $this->db->one('SELECT idk, clanek FROM {komentare} WHERE idk = ?', [(int) $id]);
            if ($k === null) {
                continue;
            }
            match ($co) {
                'schvalit' => $this->db->update('komentare', ['zobrazit' => 1, 'nahlaseno' => 0], ['idk' => $k['idk']]),
                'skryt' => $this->db->update('komentare', ['zobrazit' => 0], ['idk' => $k['idk']]),
                'smazat' => $this->db->delete('komentare', ['idk' => $k['idk']]),
                default => null,
            };
            if ($co === 'schvalit') {
                Interakce::upozorniNaOdpoved($this->app, (int) $k['idk']); // autor původního komentáře se o schválené odpovědi dozví e-mailem
            }
            $clanky[(int) $k['clanek']] = true;
        }
        foreach (array_keys($clanky) as $idc) {
            Interakce::prepocitej($this->db, $idc);
        }

        return $this->zpet('Hotovo.', '', $this->request->post('stav') === 'cekajici' ? ['stav' => 'cekajici'] : []);
    }
}
