<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Editace novinek - krátké zprávy pro systémový blok Novinky.
 */
final class Novinky extends Modul
{
    public const string IDENT = 'news';
    public const string NAZEV = 'Novinky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'novinky';
    public const string ROZSIRENI = 'novinky';

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Novinky', [
            'novinky' => $this->db->all('SELECT * FROM {news} ORDER BY datum DESC, idn DESC LIMIT 200'),
            'novinka' => ['idn' => 0, 'titulek' => '', 'informace' => '', 'datum' => date('Y-m-d H:i:s'), 'jazyk' => ''],
        ]);
    }

    protected function akceEdit(): Response
    {
        $novinka = $this->db->one('SELECT * FROM {news} WHERE idn = ?', [$this->request->getInt('id')]);
        if ($novinka === null) {
            return $this->chyba('Novinka neexistuje.', 404);
        }

        return $this->view('vypis', 'Úprava novinky', ['novinky' => [], 'novinka' => $novinka]);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('idn');
        $datum = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($r->post('datum'), 0, 16));
        $data = [
            'titulek' => $r->post('titulek'),
            'informace' => $r->post('informace'),
            'datum' => ($datum ?: new \DateTimeImmutable())->format('Y-m-d H:i:00'),
            'jazyk' => \MiroCMS\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk')),
        ];
        if ($data['titulek'] === '') {
            return $this->zpet('Vyplňte titulek novinky.', $id > 0 ? 'edit' : '', $id > 0 ? ['id' => $id] : [], 'chyba');
        }
        if ($id > 0) {
            $this->db->update('news', $data, ['idn' => $id]);
        } else {
            $this->db->insert('news', $data);
        }

        return $this->zpet('Novinka byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            foreach ($this->request->postList('smaz') as $id) {
                $this->db->delete('news', ['idn' => (int) $id]);
            }
        }

        return $this->zpet('Označené novinky byly smazány.');
    }
}
