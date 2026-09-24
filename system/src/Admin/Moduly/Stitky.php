<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Štítky a témata. Štítky vznikají samy při psaní článků; tady se dají přejmenovat, sloučit a smazat.
 * Štítek s popisem a obrázkem se na webu chová jako stránka tématu (/stitek/<adresa>).
 */
final class Stitky extends Modul
{
    public const string IDENT = 'stitky';
    public const string NAZEV = 'Štítky a témata';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'stitky';

    protected function akceVypis(): Response
    {
        $uprav = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->getInt('uprav')]);

        return $this->view('vypis', 'Štítky a témata', [
            'stitky' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {clanky_stitky} cs WHERE cs.ids = s.ids) AS pocet FROM {stitky} s ORDER BY (s.popis IS NOT NULL AND s.popis <> \'\') DESC, pocet DESC, s.nazev LIMIT 500'),
            'uprav' => $uprav,
        ]);
    }

    protected function akceUloz(): Response
    {
        $stitek = $this->db->one('SELECT * FROM {stitky} WHERE ids = ?', [$this->request->postInt('ids')]);
        $nazev = mb_substr(trim($this->request->post('nazev')), 0, 80);
        if (!$this->request->isPost() || $stitek === null || $nazev === '') {
            return $this->zpet('Vyplňte název štítku.', typ: 'chyba');
        }
        $this->db->update('stitky', ['nazev' => $nazev, 'popis' => trim($this->request->post('popis')), 'obrazek' => mb_substr($this->request->post('obrazek'), 0, 255)], ['ids' => $stitek['ids']]);

        // sloučení: články dostanou cílový štítek, tento zanikne a jeho adresa se přesměruje
        $cil = $this->db->one('SELECT * FROM {stitky} WHERE ids = ? AND ids <> ?', [$this->request->postInt('sloucit_do'), $stitek['ids']]);
        if ($cil !== null) {
            $this->db->run('INSERT IGNORE INTO {clanky_stitky} (idc, ids) SELECT idc, ? FROM {clanky_stitky} WHERE ids = ?', [$cil['ids'], $stitek['ids']]);
            $this->db->delete('clanky_stitky', ['ids' => $stitek['ids']]);
            $this->db->delete('stitky', ['ids' => $stitek['ids']]);
            Presmerovani::pridej($this->db, 'stitek/' . $stitek['seo_link'], 'stitek/' . $cil['seo_link']);

            return $this->zpet(t('Štítek „%s“ byl sloučen do „%s“.', $stitek['nazev'], $cil['nazev']));
        }

        return $this->zpet('Štítek byl uložen.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('clanky_stitky', ['ids' => $this->request->postInt('ids')]);
            $this->db->delete('stitky', ['ids' => $this->request->postInt('ids')]);
        }

        return $this->zpet('Štítek byl smazán. Novinky zůstaly, jen ho už nemají.');
    }
}
