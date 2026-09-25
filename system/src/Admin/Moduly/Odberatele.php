<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Response;

/**
 * Odběratelé novinek (rozšíření Newsletter): kdo se přihlásil prvkem Odběr novinek a zda odběr potvrdil.
 * Rozesílání Kaleta nedělá – potvrzené adresy se vyvezou do CSV i s odkazem na odhlášení pro rozesílací nástroj.
 */
final class Odberatele extends Modul
{
    public const string IDENT = 'odberatele';
    public const string ROZSIRENI = 'newsletter';
    public const string NAZEV = 'Odběratelé';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'newsletter';

    protected function akceVypis(): Response
    {
        $hledat = mb_substr($this->request->get('hledat'), 0, 100);
        $strana = max(1, $this->request->getInt('strana', 1));
        $kde = $hledat !== '' ? ' WHERE email LIKE ?' : '';
        $parametry = $hledat !== '' ? ['%' . addcslashes($hledat, '%_\\') . '%'] : [];

        return $this->view('vypis', 'Odběratelé', [
            'odberatele' => $this->db->all('SELECT * FROM {odberatele}' . $kde . ' ORDER BY ido DESC LIMIT 100 OFFSET ' . (($strana - 1) * 100), $parametry),
            'celkem' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele}' . $kde, $parametry),
            'potvrzenych' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1'),
            'hledat' => $hledat, 'strana' => $strana,
        ]);
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('odberatele', ['ido' => $this->request->postInt('ido')]);
        }

        return $this->zpet('Odběratel byl smazán.');
    }

    /** Potvrzení odběratelé do CSV (UTF-8 s BOM, středník) i s odkazem na odhlášení. */
    protected function akceCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('E-mail'), t('Přihlášen'), t('Potvrzeno'), t('Odkaz na odhlášení')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {odberatele} WHERE stav = 1 ORDER BY ido') as $o) {
            $radek = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $o['email'], (string) $o['datum'], (string) $o['potvrzeno'], \Kaleta\Front\Odber::odkazOdhlaseni($this->app, (string) $o['token'])]);
            fputcsv($f, $radek, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\Protokol::zapis($this->app, 'odberatele', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="odberatele-' . date('Y-m-d') . '.csv"']);
    }
}
