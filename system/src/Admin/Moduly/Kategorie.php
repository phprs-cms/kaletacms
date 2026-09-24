<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Db;
use MiroCMS\Core\Response;

/**
 * Kategorie novinek (tabulka rs_topic). Plochý seznam – firemní blog stromové rubriky nepotřebuje.
 * Kategorie určuje i jazykovou verzi novinky.
 */
final class Kategorie extends Modul
{
    public const string IDENT = 'kategorie';
    public const string NAZEV = 'Kategorie';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'rubriky';

    /**
     * Kategorie seřazené podle pořadí a názvu, s počtem novinek.
     *
     * @return list<array<string, mixed>>
     */
    public static function seznam(Db $db, ?string $jazyk = null): array
    {
        $kde = $jazyk !== null && preg_match('/^([a-z]{2})?$/', $jazyk) ? " WHERE t.jazyk = '{$jazyk}'" : '';

        return $db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM {clanky} c WHERE c.tema = t.idt AND c.smazano IS NULL) AS pocet_clanku
             FROM {topic} t' . $kde . ' ORDER BY t.hodnost DESC, t.nazev',
        );
    }

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Kategorie', ['kategorie' => self::seznam($this->db)]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['idt' => 0, 'nazev' => '', 'seo_link' => '', 'popis' => '', 'hodnost' => 100]);
    }

    protected function akceEdit(): Response
    {
        $kategorie = $this->db->one('SELECT * FROM {topic} WHERE idt = ?', [$this->request->getInt('id')]);

        return $kategorie === null ? $this->chyba('Kategorie neexistuje.', 404) : $this->formular($kategorie);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('idt');
        $data = [
            'nazev' => $r->post('nazev'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('nazev'), 110),
            'popis' => $r->post('popis'),
            'hodnost' => max(0, min(65535, $r->postInt('hodnost', 100))),
            'jazyk' => \MiroCMS\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk')),
        ];
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT idt FROM {topic} WHERE idt = ? AND jazyk = '' AND idt <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        if ($data['nazev'] === '') {
            return $this->formular(['idt' => $id] + $data, ['nazev' => 'Vyplňte název kategorie.']);
        }

        $zaklad = $data['seo_link'];
        for ($i = 2; $this->db->value('SELECT idt FROM {topic} WHERE seo_link = ? AND idt <> ?', [$data['seo_link'], $id]) !== null; $i++) {
            $data['seo_link'] = $zaklad . '-' . $i;
        }

        if ($id > 0) {
            $puvodni = $this->db->value('SELECT seo_link FROM {topic} WHERE idt = ?', [$id]);
            $this->db->update('topic', $data, ['idt' => $id]);
            if ($puvodni !== null && $puvodni !== $data['seo_link']) {
                // kategorie změnila adresu: stará se přesměruje, odkazy ani vyhledávače o stránku nepřijdou
                Presmerovani::pridej($this->db, 'novinky/kategorie/' . $puvodni, 'novinky/kategorie/' . $data['seo_link']);
            }
            $this->db->run('UPDATE {clanky} SET jazyk = ? WHERE tema = ?', [$data['jazyk'], $id]); // novinky mají jazyk své kategorie
        } else {
            $this->db->insert('topic', $data);
        }

        return $this->zpet('Kategorie byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $id = $this->request->postInt('idt');
        if ((int) $this->db->value('SELECT COUNT(*) FROM {clanky} WHERE tema = ?', [$id]) > 0) {
            return $this->zpet('Kategorii nelze smazat, dokud v ní jsou novinky (i v koši). Nejprve je přesuňte jinam.', typ: 'chyba');
        }
        $this->db->delete('topic', ['idt' => $id]);

        return $this->zpet('Kategorie byla smazána.');
    }

    /**
     * @param array<string, mixed> $kategorie
     * @param array<string, string> $chyby
     */
    private function formular(array $kategorie, array $chyby = []): Response
    {
        return $this->view('formular', $kategorie['idt'] ? 'Úprava kategorie' : 'Nová kategorie', ['kategorie' => $kategorie, 'chyby' => $chyby]);
    }
}
