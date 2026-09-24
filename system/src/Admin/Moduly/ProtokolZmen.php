<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/** Protokol změn - přehled akcí v administraci (jen pro administrátora). */
final class ProtokolZmen extends Modul
{
    public const string IDENT = 'protokol';
    public const string NAZEV = 'Protokol změn';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'protokol';
    public const bool JEN_ADMIN = true;

    private const int NA_STRANU = 100;

    protected function akceVypis(): Response
    {
        $kdo = $this->request->getInt('kdo');
        $kde = $this->request->get('kde');
        $hledat = mb_substr(trim($this->request->get('hledat')), 0, 100);
        $podminky = [];
        $params = [];
        if ($kdo > 0) {
            $podminky[] = 'kdo = ?';
            $params[] = $kdo;
        }
        if ($kde !== '' && preg_match('/^[a-z_]{2,30}$/', $kde)) {
            $podminky[] = 'modul = ?';
            $params[] = $kde;
        }
        if ($hledat !== '') {
            $podminky[] = 'popis LIKE ?';
            $params[] = '%' . addcslashes($hledat, '%_\\') . '%';
        }
        $sql = $podminky === [] ? '' : ' WHERE ' . implode(' AND ', $podminky);
        $celkem = (int) $this->db->value('SELECT COUNT(*) FROM {protokol}' . $sql, $params);
        $stran = max(1, (int) ceil($celkem / self::NA_STRANU));
        $strana = max(1, min($stran, $this->request->getInt('strana', 1)));

        return $this->view('vypis', 'Protokol změn', [
            'zaznamy' => $this->db->all('SELECT * FROM {protokol}' . $sql . ' ORDER BY idp DESC LIMIT ' . self::NA_STRANU . ' OFFSET ' . (($strana - 1) * self::NA_STRANU), $params),
            'uzivatele' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} ORDER BY 2"),
            'moduly' => array_column($this->db->all('SELECT DISTINCT modul FROM {protokol} ORDER BY modul'), 'modul'),
            'kdo' => $kdo, 'kde' => $kde, 'hledat' => $hledat, 'strana' => $strana, 'stran' => $stran, 'celkem' => $celkem,
        ]);
    }
}
