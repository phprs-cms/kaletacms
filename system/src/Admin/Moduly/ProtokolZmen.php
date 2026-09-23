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

    protected function akceVypis(): Response
    {
        $kdo = $this->request->getInt('kdo');

        return $this->view('vypis', 'Protokol změn', [
            'zaznamy' => $this->db->all('SELECT * FROM {protokol}' . ($kdo > 0 ? ' WHERE kdo = ?' : '') . ' ORDER BY idp DESC LIMIT 300', $kdo > 0 ? [$kdo] : []),
            'uzivatele' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {user} ORDER BY 2"),
            'kdo' => $kdo,
        ]);
    }
}
