<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Response;

/**
 * Statistika: vlastní měření bez cookies (návštěvy, zobrazení, nejčtenější novinky, zdroje návštěv).
 */
final class Statistika extends Modul
{
    public const string IDENT = 'stat';
    public const string NAZEV = 'Statistika';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'statistika';
    public const string ROZSIRENI = 'statistika';

    protected function akceVypis(): Response
    {
        $dni = in_array($this->request->getInt('dni'), [7, 30, 90], true) ? $this->request->getInt('dni') : 30;
        $radky = $this->db->pairs('SELECT den, CONCAT(navstevy, ":", zobrazeni) FROM {stat_dny} WHERE den > CURDATE() - INTERVAL ? DAY', [$dni]);
        $graf = [];
        for ($i = $dni - 1; $i >= 0; $i--) {
            $den = date('Y-m-d', strtotime("-{$i} days"));
            [$n, $z] = array_map(intval(...), explode(':', $radky[$den] ?? '0:0'));
            $graf[$den] = ['navstevy' => $n, 'zobrazeni' => $z];
        }

        return $this->view('vypis', 'Statistika', [
            'dni' => $dni,
            'graf' => $graf,
            'zapnuto' => $this->app->settings()->bool('statistika'),
            'clanky' => $this->db->all(
                'SELECT c.idc, c.titulek, c.seo_link, SUM(s.pocet) AS pocet FROM {stat_novinky} s JOIN {novinky} c ON c.idc = s.idc
                 WHERE s.den > CURDATE() - INTERVAL ? DAY GROUP BY c.idc, c.titulek, c.seo_link ORDER BY pocet DESC LIMIT 15',
                [$dni],
            ),
            'stranky' => $this->db->all('SELECT cesta, SUM(pocet) AS pocet FROM {stat_stranky} WHERE den > CURDATE() - INTERVAL ? DAY GROUP BY cesta ORDER BY pocet DESC LIMIT 20', [$dni]),
            'zdroje' => $this->db->all('SELECT zdroj, SUM(pocet) AS pocet FROM {stat_zdroje} WHERE den > CURDATE() - INTERVAL ? DAY GROUP BY zdroj ORDER BY pocet DESC LIMIT 15', [$dni]),
        ]);
    }
}
