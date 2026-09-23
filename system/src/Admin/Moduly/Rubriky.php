<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Db;
use MiroCMS\Core\Response;

/**
 * Úprava rubrik. Rubriky tvoří strom; každý článek patří právě do jedné.
 */
final class Rubriky extends Modul
{
    public const string IDENT = 'topic';
    public const string NAZEV = 'Rubriky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'rubriky';

    /**
     * Rubriky seřazené do stromu (do hloubky), každá s klíčem "uroven" (0 = hlavní rubrika).
     *
     * @return list<array<string, mixed>>
     */
    public static function strom(Db $db, bool $jenZobrazene = false, ?string $jazyk = null): array
    {
        $kde = array_filter([$jenZobrazene ? 't.zobrazit = 1' : '', $jazyk !== null && preg_match('/^([a-z]{2})?$/', $jazyk) ? "t.jazyk = '{$jazyk}'" : '']);
        $vse = $db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM {clanky} c WHERE c.tema = t.idt AND c.smazano IS NULL) AS pocet_clanku
             FROM {topic} t' . ($kde !== [] ? ' WHERE ' . implode(' AND ', $kde) : '') . '
             ORDER BY t.hodnost DESC, t.nazev',
        );
        $deti = [];
        foreach ($vse as $rubrika) {
            $deti[(int) $rubrika['id_predka']][] = $rubrika;
        }
        $strom = [];
        $pridej = static function (int $predek, int $uroven) use (&$pridej, &$strom, $deti): void {
            foreach ($deti[$predek] ?? [] as $rubrika) {
                $strom[] = $rubrika + ['uroven' => $uroven];
                $pridej((int) $rubrika['idt'], $uroven + 1);
            }
        };
        $pridej(0, 0);

        return $strom;
    }

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Rubriky', ['rubriky' => self::strom($this->db)]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['idt' => 0, 'nazev' => '', 'seo_link' => '', 'popis' => '', 'obrazek' => '', 'id_predka' => null, 'hodnost' => 100, 'zobrazit' => 1]);
    }

    protected function akceEdit(): Response
    {
        $rubrika = $this->db->one('SELECT * FROM {topic} WHERE idt = ?', [$this->request->getInt('id')]);

        return $rubrika === null ? $this->chyba('Rubrika neexistuje.', 404) : $this->formular($rubrika);
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
            'obrazek' => $r->post('obrazek'),
            'id_predka' => $r->postInt('id_predka') ?: null,
            'hodnost' => max(0, min(65535, $r->postInt('hodnost', 100))),
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'jazyk' => \MiroCMS\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk')),
        ];
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT idt FROM {topic} WHERE idt = ? AND jazyk = '' AND idt <> ?", [$r->postInt('preklad_z'), $id]) ?: null);

        $chyby = [];
        if ($data['nazev'] === '') {
            $chyby['nazev'] = 'Vyplňte název rubriky.';
        }
        if ($id > 0 && $data['id_predka'] !== null && in_array($data['id_predka'], [$id, ...$this->potomci($id)], true)) {
            $chyby['id_predka'] = 'Rubrika nemůže být podřízená sama sobě ani své podrubrice.';
        }
        if ($chyby !== []) {
            return $this->formular(['idt' => $id] + $data, $chyby);
        }

        $zaklad = $data['seo_link'];
        for ($i = 2; $this->db->value('SELECT idt FROM {topic} WHERE seo_link = ? AND idt <> ?', [$data['seo_link'], $id]) !== null; $i++) {
            $data['seo_link'] = $zaklad . '-' . $i;
        }

        if ($id > 0) {
            $puvodni = $this->db->one('SELECT seo_link, zobrazit FROM {topic} WHERE idt = ?', [$id]);
            $this->db->update('topic', $data, ['idt' => $id]);
            if ($puvodni !== null && $puvodni['zobrazit'] && $puvodni['seo_link'] !== $data['seo_link']) {
                // zobrazená rubrika změnila adresu: stará se přesměruje, odkazy ani vyhledávače o stránku nepřijdou
                Presmerovani::pridej($this->db, 'rubrika/' . $puvodni['seo_link'], 'rubrika/' . $data['seo_link']);
            }
            $this->db->run('UPDATE {clanky} SET jazyk = ? WHERE tema = ?', [$data['jazyk'], $id]); // články mají jazyk své rubriky
        } else {
            $this->db->insert('topic', $data);
        }

        return $this->zpet('Rubrika byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $id = $this->request->postInt('idt');
        if ((int) $this->db->value('SELECT COUNT(*) FROM {clanky} WHERE tema = ? AND smazano IS NULL', [$id]) > 0) {
            return $this->zpet('Rubriku nelze smazat, dokud obsahuje články. Nejprve je přesuňte jinam.', typ: 'chyba');
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM {clanky} WHERE tema = ?', [$id]) > 0) {
            return $this->zpet('V rubrice jsou ještě články v koši. Obnovte je a přesuňte jinam, nebo je smažte natrvalo (Články → Koš).', typ: 'chyba');
        }
        // podrubriky se posunou o úroveň výš (ON DELETE SET NULL by z nich udělal hlavní rubriky)
        $predek = $this->db->value('SELECT id_predka FROM {topic} WHERE idt = ?', [$id]);
        $this->db->run('UPDATE {topic} SET id_predka = ? WHERE id_predka = ?', [$predek, $id]);
        $this->db->delete('topic', ['idt' => $id]);

        return $this->zpet('Rubrika byla smazána.');
    }

    /**
     * @param array<string, mixed> $rubrika
     * @param array<string, string> $chyby
     */
    private function formular(array $rubrika, array $chyby = []): Response
    {
        return $this->view('formular', $rubrika['idt'] ? 'Úprava rubriky' : 'Nová rubrika', [
            'rubrika' => $rubrika,
            'chyby' => $chyby,
            'rubriky' => self::strom($this->db),
            'zakazane' => $rubrika['idt'] ? [(int) $rubrika['idt'], ...$this->potomci((int) $rubrika['idt'])] : [],
        ]);
    }

    /** @return list<int> ID všech podrubrik (i nepřímých) */
    private function potomci(int $id): array
    {
        $potomci = [];
        $uroven = null;
        foreach (self::strom($this->db) as $rubrika) {
            if ($uroven !== null) {
                if ($rubrika['uroven'] <= $uroven) {
                    break;
                }
                $potomci[] = (int) $rubrika['idt'];
            } elseif ((int) $rubrika['idt'] === $id) {
                $uroven = $rubrika['uroven'];
            }
        }

        return $potomci;
    }
}
