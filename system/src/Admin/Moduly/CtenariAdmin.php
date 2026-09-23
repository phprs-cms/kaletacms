<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Registrovaní čtenáři: přehled, zápis předplatného, odstranění účtu, export.
 * Předplatné (datum "do") zapisuje administrátor ručně, nebo ho s nastavenými platbami zapíná a prodlužuje Stripe (Front\Platby);
 * ruční zápis funguje vedle toho dál. Odsud se do Stripe nikdy nevolá - běžící předplatné se ruší ve Stripe.
 */
final class CtenariAdmin extends Modul
{
    public const string IDENT = 'ctenari';
    public const string NAZEV = 'Čtenáři';
    public const string SKUPINA = 'Čtenáři';
    public const string IKONA = 'ctenari';
    public const string ROZSIRENI = 'ctenari';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $q = mb_substr($this->request->get('q'), 0, 100);
        $kde = $q === '' ? '1 = 1' : '(email LIKE ? OR jmeno LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        if ($this->request->get('format') === 'csv') {
            // hodnoty od čtenářů: bez konců řádků a bez možnosti spustit vzorec v tabulkovém programu (= + - @)
            $bunka = fn (string $v): string => '"' . str_replace('"', '""', preg_replace('/^[=+\-@\t]/', "'$0", str_replace(["\r", "\n"], ' ', $v)) ?? '') . '"';
            $radky = array_map(fn (array $c): string => implode(';', [$bunka($c['email']), $bunka($c['jmeno']), $c['vytvoren'], (string) $c['predplatne_do']]), $this->db->all('SELECT * FROM {ctenari} WHERE potvrzen = 1 ORDER BY email'));

            return new Response("email;jmeno;registrace;predplatne_do\n" . implode("\n", $radky), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="ctenari.csv"']);
        }

        return $this->view('vypis', 'Čtenáři', [
            'q' => $q,
            'ctenari' => $this->db->all("SELECT * FROM {ctenari} WHERE {$kde} ORDER BY idct DESC LIMIT 300", $q === '' ? [] : [$like, $like]),
            'stripe' => \MiroCMS\Core\Stripe::nastaveno($this->app->settings()),
            'pocty' => [
                'Registrovaní' => (int) $this->db->value('SELECT COUNT(*) FROM {ctenari} WHERE potvrzen = 1'),
                'Předplatitelé' => (int) $this->db->value('SELECT COUNT(*) FROM {ctenari} WHERE potvrzen = 1 AND predplatne_do >= CURDATE()'),
                'Platí přes Stripe' => (int) $this->db->value("SELECT COUNT(*) FROM {ctenari} WHERE stripe_predplatne IS NOT NULL AND predplatne_stav IN ('aktivni', 'konci', 'nezaplaceno')"),
                'Zamčené články' => (int) $this->db->value('SELECT COUNT(*) FROM {clanky} WHERE pristup > 0 AND smazano IS NULL'),
            ],
        ]);
    }

    /** Předplatné: o kolik měsíců prodloužit (od dneška, nebo od konce běžícího), případně konkrétní datum či zrušení. */
    protected function akcePredplatne(): Response
    {
        $ctenar = $this->db->one('SELECT * FROM {ctenari} WHERE idct = ?', [$this->request->postInt('idct')]);
        if (!$this->request->isPost() || $ctenar === null) {
            return $this->zpet();
        }
        $volba = $this->request->post('volba');
        $do = match (true) {
            $volba === 'zrusit' => null,
            in_array($volba, ['1', '3', '12'], true) => date('Y-m-d', strtotime('+' . $volba . ' month', max(time(), (int) strtotime((string) $ctenar['predplatne_do'])))),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->post('datum')) => $this->request->post('datum'),
            default => $ctenar['predplatne_do'],
        };
        $this->db->update('ctenari', ['predplatne_do' => $do], ['idct' => $ctenar['idct']]);

        return $this->zpet($do === null ? 'Předplatné bylo zrušeno.' : t('Předplatné platí do %s.', datum($do)));
    }

    protected function akceSmaz(): Response
    {
        $ctenar = $this->db->one('SELECT * FROM {ctenari} WHERE idct = ?', [$this->request->postInt('idct')]);
        if (!$this->request->isPost() || $ctenar === null) {
            return $this->zpet();
        }
        $this->db->delete('ctenari', ['idct' => $ctenar['idct']]); // platby zůstávají bez vazby na čtenáře (účetnictví)

        return \MiroCMS\Front\Ctenari::beziStripe($ctenar)
            ? $this->zpet(t('Účet čtenáře byl smazán. Jeho předplatné ve Stripe (%s) ale běží dál – zrušte ho ve Stripe, jinak se mu budou strhávat další platby.', (string) $ctenar['stripe_predplatne']), '', [], 'chyba')
            : $this->zpet('Účet čtenáře byl smazán.');
    }
}
