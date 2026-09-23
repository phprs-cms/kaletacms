<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Reklamní systém: bannery (obrázek s odkazem) a reklamní kódy na pozicích webu.
 * Pozice "sloupec", "hlavicka" a "paticka" se na web umístí blokem Reklama (Bloky a rozvržení),
 * pozice "pod-clankem" se vypisuje pod každým článkem sama.
 */
final class Reklama extends Modul
{
    public const string IDENT = 'reklama';
    public const string NAZEV = 'Reklama';
    public const string SKUPINA = 'Čtenáři';
    public const string IKONA = 'reklama';
    public const string ROZSIRENI = 'reklama';

    public const array POZICE = [
        'sloupec' => 'Sloupec (čtverec, např. 300×250)',
        'hlavicka' => 'Hlavička (široký pruh, např. 970×210)',
        'pod-clankem' => 'Pod článkem (zobrazuje se automaticky)',
        'paticka' => 'Patička (široký pruh)',
    ];

    protected function akceVypis(): Response
    {
        return $this->view('vypis', 'Reklama', [
            'reklamy' => $this->db->all('SELECT * FROM {reklama} ORDER BY aktivni DESC, pozice, idr DESC'),
            'adsTxt' => $this->app->settings()->get('ads_txt'),
        ]);
    }

    /** Výkaz pro inzerenta: zobrazení, prokliky a míra prokliku všech bannerů jako CSV. */
    protected function akceVykaz(): Response
    {
        $radky = ["nazev;pozice;od;do;zobrazeni;prokliky;mira_prokliku_procent;stav"];
        foreach ($this->db->all('SELECT * FROM {reklama} ORDER BY idr DESC') as $b) {
            $bunka = fn (string $v): string => '"' . str_replace('"', '""', preg_replace('/^[=+\-@\t]/', "'$0", $v) ?? '') . '"';
            $radky[] = implode(';', [$bunka($b['nazev']), $b['pozice'], (string) $b['platna_od'], (string) $b['platna_do'], (int) $b['zobrazeni'], (int) $b['kliky'],
                $b['zobrazeni'] > 0 ? number_format($b['kliky'] / $b['zobrazeni'] * 100, 2, ',', '') : '0', $b['aktivni'] ? 'aktivni' : 'vypnuta']);
        }

        return new Response("\u{FEFF}" . implode("\n", $radky) . "\n", 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="reklama-vykaz-' . date('Y-m-d') . '.csv"']);
    }

    protected function akceNovy(): Response
    {
        return $this->formular(['idr' => 0, 'nazev' => '', 'pozice' => 'sloupec', 'typ' => 'obrazek', 'obrazek' => '', 'cil_url' => '', 'kod' => '',
            'platna_od' => null, 'platna_do' => null, 'aktivni' => 1, 'vaha' => 1, 'max_zobrazeni' => null, 'jen_rubrika' => null, 'zarizeni' => 'vse']);
    }

    protected function akceEdit(): Response
    {
        $reklama = $this->db->one('SELECT * FROM {reklama} WHERE idr = ?', [$this->request->getInt('id')]);

        return $reklama === null ? $this->chyba('Reklama neexistuje.', 404) : $this->formular($reklama);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $id = $r->postInt('idr');
        $datum = fn (string $v): ?string => ($dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($v, 0, 16))) ? $dt->format('Y-m-d H:i:00') : null;
        $data = [
            'nazev' => mb_substr($r->post('nazev'), 0, 150),
            'pozice' => isset(self::POZICE[$r->post('pozice')]) ? $r->post('pozice') : 'sloupec',
            'typ' => $r->post('typ') === 'kod' ? 'kod' : 'obrazek',
            'obrazek' => mb_substr($r->post('obrazek'), 0, 255),
            'cil_url' => mb_substr($r->post('cil_url'), 0, 500),
            'kod' => (string) ($_POST['kod'] ?? ''),
            'platna_od' => $datum($r->post('platna_od')),
            'platna_do' => $datum($r->post('platna_do')),
            'aktivni' => (int) $r->postBool('aktivni'),
            'vaha' => max(1, min(10, $r->postInt('vaha', 1))),
            'max_zobrazeni' => $r->postInt('max_zobrazeni') > 0 ? $r->postInt('max_zobrazeni') : null,
            'jen_rubrika' => $this->db->value('SELECT idt FROM {topic} WHERE idt = ?', [$r->postInt('jen_rubrika')]) !== null ? $r->postInt('jen_rubrika') : null,
            'zarizeni' => in_array($r->post('zarizeni'), ['mobil', 'pocitac'], true) ? $r->post('zarizeni') : 'vse',
        ];
        $chyby = [];
        if ($data['nazev'] === '') {
            $chyby['nazev'] = 'Vyplňte název.';
        }
        if ($data['typ'] === 'obrazek' && ($data['obrazek'] === '' || !preg_match('#^https?://#i', $data['cil_url']) || !filter_var($data['cil_url'], FILTER_VALIDATE_URL))) {
            $chyby['obrazek'] = 'Banner potřebuje obrázek a cílovou adresu začínající https://.';
        }
        if ($data['typ'] === 'kod' && trim($data['kod']) === '') {
            $chyby['kod'] = 'Vložte reklamní kód.';
        }
        if ($chyby !== []) {
            return $this->formular(['idr' => $id] + $data, $chyby);
        }
        if ($id > 0) {
            $this->db->update('reklama', $data, ['idr' => $id]);
        } else {
            $this->db->insert('reklama', $data);
        }

        return $this->zpet('Reklama byla uložena.');
    }

    /** Zapnutí / vypnutí reklamy jedním kliknutím z přehledu. */
    protected function akcePrepni(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('UPDATE {reklama} SET aktivni = 1 - aktivni WHERE idr = ?', [$this->request->postInt('idr')]);
        }

        return $this->zpet('Hotovo.');
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('reklama', ['idr' => $this->request->postInt('idr')]);
        }

        return $this->zpet('Reklama byla smazána.');
    }

    protected function akceAdsTxt(): Response
    {
        if ($this->request->isPost()) {
            $this->app->settings()->set('ads_txt', mb_substr($this->request->post('ads_txt'), 0, 20000));
        }

        return $this->zpet('Soubor ads.txt byl uložen.');
    }

    /**
     * @param array<string, mixed> $reklama
     * @param array<string, string> $chyby
     */
    private function formular(array $reklama, array $chyby = []): Response
    {
        return $this->view('formular', $reklama['idr'] ? 'Úprava reklamy' : 'Nová reklama', ['reklama' => $reklama, 'chyby' => $chyby]);
    }
}
