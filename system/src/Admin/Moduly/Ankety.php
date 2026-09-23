<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Ankety. Na webu se zobrazuje jedna aktivní anketa v bloku "Anketa" (Bloky a rozvržení).
 */
final class Ankety extends Modul
{
    public const string IDENT = 'ankety';
    public const string NAZEV = 'Ankety';
    public const string SKUPINA = 'Čtenáři';
    public const string IKONA = 'ankety';
    public const string ROZSIRENI = 'ankety';

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Ankety', [
            'ankety' => $this->db->all('SELECT a.*, (SELECT COALESCE(SUM(pocitadlo), 0) FROM {odpovedi} o WHERE o.anketa = a.ida) AS hlasu FROM {ankety} a ORDER BY a.ida DESC'),
            'aktivni' => $this->app->settings()->int('aktivni_anketa'),
            'maBlok' => $this->db->value("SELECT idb FROM {bloky} WHERE sys_funkce = 'ank' AND zobrazit = 1") !== null,
        ]);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['ida' => 0, 'titulek' => '', 'otazka' => '', 'zobrazit' => 1, 'uzavrena' => 0, 'jazyk' => ''], []);
    }

    protected function akceEdit(): Response
    {
        $anketa = $this->db->one('SELECT * FROM {ankety} WHERE ida = ?', [$this->request->getInt('id')]);

        return $anketa === null
            ? $this->chyba('Anketa neexistuje.', 404)
            : $this->formular($anketa, $this->db->all('SELECT * FROM {odpovedi} WHERE anketa = ? ORDER BY poradi, ido', [$anketa['ida']]));
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('ida');
        $data = [
            'titulek' => mb_substr($r->post('otazka'), 0, 150), 'otazka' => $r->post('otazka'),
            'zobrazit' => (int) $r->postBool('zobrazit'), 'uzavrena' => (int) $r->postBool('uzavrena'),
            'jazyk' => \MiroCMS\Core\Jazyk::sloupec($this->app->settings(), $r->post('jazyk')),
        ];
        // odpovědi: existující podle ido (prázdná = smazat), nové z posledních polí
        $existujici = is_array($_POST['odpoved'] ?? null) ? $_POST['odpoved'] : [];
        $nove = array_values(array_filter(array_map(fn ($o): string => mb_substr(trim((string) $o), 0, 255), is_array($_POST['nova'] ?? null) ? $_POST['nova'] : [])));
        $zustane = count(array_filter($existujici, fn ($o): bool => trim((string) $o) !== '')) + count($nove);
        if ($data['otazka'] === '' || $zustane < 2) {
            $this->app->session->flash('chyba', 'Anketa potřebuje otázku a alespoň dvě odpovědi.');

            return $id > 0 ? $this->zpet('', 'edit', ['id' => $id]) : $this->zpet('', 'novy');
        }
        $this->db->transaction(function () use (&$id, $data, $existujici, $nove): void {
            if ($id > 0) {
                $this->db->update('ankety', $data, ['ida' => $id]);
            } else {
                $id = $this->db->insert('ankety', $data + ['datum' => date('Y-m-d H:i:s'), 'kdo' => $this->app->auth()->id()]);
            }
            $poradi = 0;
            foreach ($existujici as $ido => $text) {
                $text = mb_substr(trim((string) $text), 0, 255);
                if ($text === '') {
                    $this->db->delete('odpovedi', ['ido' => (int) $ido, 'anketa' => $id]);
                } else {
                    $this->db->update('odpovedi', ['odpoved' => $text, 'poradi' => $poradi++], ['ido' => (int) $ido, 'anketa' => $id]);
                }
            }
            foreach ($nove as $text) {
                $this->db->insert('odpovedi', ['anketa' => $id, 'odpoved' => $text, 'poradi' => $poradi++]);
            }
        });
        if ($r->postBool('aktivni')) {
            $this->app->settings()->set('aktivni_anketa', (string) $id);
        } elseif ($this->app->settings()->int('aktivni_anketa') === $id) {
            $this->app->settings()->set('aktivni_anketa', '0');
        }

        return $this->zpet('Anketa byla uložena.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('ankety', ['ida' => $this->request->postInt('ida')]);
        }

        return $this->zpet('Anketa byla smazána.');
    }

    /**
     * @param array<string, mixed> $anketa
     * @param list<array<string, mixed>> $odpovedi
     */
    private function formular(array $anketa, array $odpovedi): Response
    {
        return $this->view('formular', $anketa['ida'] ? 'Úprava ankety' : 'Nová anketa', [
            'anketa' => $anketa, 'odpovedi' => $odpovedi,
            'aktivni' => $anketa['ida'] > 0 ? $this->app->settings()->int('aktivni_anketa') === (int) $anketa['ida'] : true,
        ]);
    }
}
