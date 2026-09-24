<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Db;
use MiroCMS\Core\Response;

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

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Přesměrování', [
            'zaznamy' => $this->db->all('SELECT * FROM {presmerovani} ORDER BY idp DESC LIMIT 500'),
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
        self::pridej($this->db, $z, preg_match('#^https?://#i', $na) ? $na : trim($na, '/'));
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
