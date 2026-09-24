<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

/**
 * Přesměrování 301: stará adresa -> nová. Vzniká samo při změně adresy stránky, novinky nebo kategorie,
 * ručně se hodí po přechodu z jiného systému. Použije se, až když web pro adresu nic nenajde.
 */
final class Presmerovani extends Modul
{
    public const string IDENT = 'presmerovani';
    public const string NAZEV = 'Přesměrování';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'presmerovani';
    public const string ROZSIRENI = 'presmerovani';
    public const bool JEN_ADMIN = true;

    public static function pridej(Db $db, string $z, string $na): void
    {
        $z = trim($z, '/ ');
        if ($z === '' || $z === trim($na, '/ ')) {
            return;
        }
        // nová cílová adresa přebírá i starší přesměrování, aby nevznikaly řetězy
        $db->run('UPDATE {presmerovani} SET na_adresu = ? WHERE na_adresu = ?', [$na, $z]);
        $db->run('DELETE FROM {presmerovani} WHERE z_adresy = ?', [trim($na, '/ ')]);
        $db->run(
            'INSERT INTO {presmerovani} (z_adresy, na_adresu, vytvoreno) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE na_adresu = VALUES(na_adresu)',
            [mb_substr($z, 0, 255), mb_substr($na, 0, 255)],
        );
    }

    private const int NA_STRANU = 50;

    protected function akceVypis(): Response
    {
        $hledat = mb_substr(trim($this->request->get('hledat')), 0, 100);
        $kde = $hledat !== '' ? 'WHERE z_adresy LIKE ? OR na_adresu LIKE ?' : '';
        $params = $hledat !== '' ? array_fill(0, 2, '%' . addcslashes($hledat, '%_\\') . '%') : [];
        $celkem = (int) $this->db->value('SELECT COUNT(*) FROM {presmerovani} ' . $kde, $params);
        $strana = max(1, min((int) ceil(max(1, $celkem) / self::NA_STRANU), $this->request->getInt('strana', 1)));

        return $this->view('vypis', 'Přesměrování', [
            'zaznamy' => $this->db->all('SELECT * FROM {presmerovani} ' . $kde . ' ORDER BY idp DESC LIMIT ' . self::NA_STRANU . ' OFFSET ' . (($strana - 1) * self::NA_STRANU), $params),
            'celkem' => $celkem, 'strana' => $strana, 'stran' => (int) ceil($celkem / self::NA_STRANU), 'hledat' => $hledat,
            'upravit' => $this->request->getInt('upravit') > 0 ? $this->db->one('SELECT * FROM {presmerovani} WHERE idp = ?', [$this->request->getInt('upravit')]) : null,
            'nenalezeno' => $this->db->all('SELECT * FROM {nenalezeno} WHERE naposledy > NOW() - INTERVAL 60 DAY ORDER BY pocet DESC, naposledy DESC LIMIT 25'),
            'zAdresy' => mb_substr($this->request->get('z'), 0, 255),
        ]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $z = (string) parse_url($this->request->post('z_adresy'), PHP_URL_PATH);
        $na = $this->request->post('na_adresu');
        if (trim($z, '/') === '' || $na === '' || (!preg_match('#^https?://#i', $na) && !preg_match('#^/?[^\s:]*$#', $na))) {
            return $this->zpet('Vyplňte starou adresu (cestu na tomto webu) a cíl – cestu, nebo celou adresu https://…', typ: 'chyba');
        }
        $cil = preg_match('#^https?://#i', $na) ? $na : trim($na, '/');
        $idp = $this->request->postInt('idp');
        if ($idp > 0) {
            // úprava existujícího záznamu
            $this->db->update('presmerovani', ['z_adresy' => mb_substr(trim($z, '/ '), 0, 255), 'na_adresu' => mb_substr($cil, 0, 255)], ['idp' => $idp]);
        } else {
            self::pridej($this->db, $z, $cil);
        }
        $this->db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [$this->request->postInt('typ') === 302 ? 302 : 301, trim($z, '/ ')]);
        $this->db->delete('nenalezeno', ['cesta' => trim($z, '/')]);

        return $this->zpet('Přesměrování bylo uloženo.');
    }

    /** Vyprázdní přehled nenalezených adres. */
    protected function akceVycisti(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {nenalezeno}');
        }

        return $this->zpet('Přehled nenalezených adres je prázdný.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('presmerovani', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->zpet('Přesměrování bylo smazáno.');
    }
}
