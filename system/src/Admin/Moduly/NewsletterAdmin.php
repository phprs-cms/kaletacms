<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Newsletter: vydání = předmět, krátký úvod a vybrané články. Rozesílá se po dávkách, aby to zvládl i sdílený hosting.
 */
final class NewsletterAdmin extends Modul
{
    public const string IDENT = 'newsletter';
    public const string NAZEV = 'Newsletter';
    public const string SKUPINA = 'Čtenáři';
    public const string IKONA = 'newsletter';
    public const string ROZSIRENI = 'newsletter';


    protected function akceVypis(): Response
    {
        $posledni = (string) ($this->db->value('SELECT MAX(vytvoreno) FROM {newsletter} WHERE odeslano IS NOT NULL') ?? '2000-01-01');

        return $this->view('vypis', 'Newsletter', [
            'odberatelu' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele} WHERE potvrzen = 1'),
            'nepotvrzenych' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele} WHERE potvrzen = 0'),
            'vydani' => $this->db->all('SELECT * FROM {newsletter} ORDER BY idn DESC LIMIT 30'),
            // předvybrané jsou jen nové články výchozího jazyka – vydání je vždy v jednom jazyce
            'clanky' => $this->db->all("SELECT idc, titulek, datum, jazyk, (datum > ? AND jazyk = '') AS novy FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND typ_clanku = 1 ORDER BY datum DESC LIMIT " . (\MiroCMS\Core\Jazyk::dalsi($this->app->settings()) === [] ? 15 : 30), [$posledni]),
            'podleJazyka' => \MiroCMS\Core\Jazyk::dalsi($this->app->settings()) === [] ? [] : array_map(intval(...), $this->db->pairs('SELECT jazyk, COUNT(*) FROM {odberatele} WHERE potvrzen = 1 GROUP BY jazyk')),
            'maEmail' => $this->app->settings()->get('email_webu') !== '',
            'automat' => array_map($this->app->settings()->get(...), ['newsletter_auto' => 'newsletter_auto', 'newsletter_den' => 'newsletter_den', 'newsletter_hodina' => 'newsletter_hodina', 'newsletter_uvod' => 'newsletter_uvod']),
            'maBlok' => $this->db->value("SELECT idb FROM {bloky} WHERE sys_funkce = 'nws'") !== null,
        ]);
    }

    /** Uloží vydání a buď ho pošle na zkoušku redakci, nebo zahájí rozesílku. */
    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $r = $this->request;
        $clanky = array_slice(array_filter(array_map(intval(...), $r->postList('clanky'))), 0, 20);
        if ($r->post('predmet') === '' || $clanky === []) {
            return $this->zpet('Vyplňte předmět a vyberte alespoň jeden článek.', typ: 'chyba');
        }
        // jazyk vydání určují vybrané články; dostanou ho jen odběratelé téhož jazyka
        $jazyky = array_column($this->db->all('SELECT DISTINCT jazyk FROM {clanky} WHERE idc IN (' . implode(',', $clanky) . ')'), 'jazyk');
        if (count($jazyky) !== 1) {
            return $this->zpet('Vyberte články jen jedné jazykové verze – každý jazyk má vlastní vydání a vlastní odběratele.', typ: 'chyba');
        }
        $idn = $this->db->insert('newsletter', ['predmet' => mb_substr($r->post('predmet'), 0, 200), 'uvod' => $r->post('uvod'), 'clanky' => implode(',', $clanky), 'vytvoreno' => date('Y-m-d H:i:s'), 'jazyk' => $jazyky[0]]);
        if ($r->post('co') === 'zkouska') {
            $ok = \MiroCMS\Core\Rozesilka::posli($this->app, $idn, $this->app->settings()->get('email_webu'), 'zkouska');
            $this->db->delete('newsletter', ['idn' => $idn]);

            return $this->zpet($ok ? 'Zkušební zpráva odešla na e-mail redakce.' : 'Zkušební zprávu se nepodařilo odeslat – zkontrolujte E-mail redakce v Nastavení a poštu u hostingu.', typ: $ok ? 'ok' : 'chyba');
        }

        // naplánované vydání rozešlou úlohy na pozadí, až nastane jeho čas
        $kdy = strtotime($r->post('odeslat_v'));
        if ($r->post('co') === 'naplanovat' && $kdy !== false && $kdy > time()) {
            $this->db->update('newsletter', ['odeslat_v' => date('Y-m-d H:i:s', $kdy)], ['idn' => $idn]);

            return $this->zpet(t('Newsletter je naplánovaný na %s. Rozešle se sám.', datum(date('Y-m-d H:i:s', $kdy), true)));
        }

        return Response::redirect($this->url('rozeslat', ['id' => $idn]));
    }

    /** Automatický výběr nových článků: jak často, kdy a s jakým úvodním slovem. */
    protected function akceAutomat(): Response
    {
        if ($this->request->isPost()) {
            $s = $this->app->settings();
            $s->set('newsletter_auto', in_array($this->request->post('newsletter_auto'), ['tydne', 'denne'], true) ? $this->request->post('newsletter_auto') : 'vypnuto');
            $s->set('newsletter_den', (string) max(1, min(7, $this->request->postInt('newsletter_den', 5))));
            $s->set('newsletter_hodina', (string) max(0, min(23, $this->request->postInt('newsletter_hodina', 7))));
            $s->set('newsletter_uvod', mb_substr($this->request->post('newsletter_uvod'), 0, 1000));
        }

        return $this->zpet('Nastavení automatického newsletteru je uložené.');
    }

    /** Zruší naplánované vydání, které se ještě nezačalo rozesílat. */
    protected function akceZrus(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {newsletter} WHERE idn = ? AND odeslano IS NULL AND pocet = 0', [$this->request->postInt('idn')]);
        }

        return $this->zpet('Naplánované vydání bylo zrušeno.');
    }

    /** Jedna dávka rozesílky; stránka se sama obnovuje, dokud nejsou obslouženi všichni odběratelé. */
    protected function akceRozeslat(): Response
    {
        $vydani = $this->db->one('SELECT * FROM {newsletter} WHERE idn = ?', [$this->request->getInt('id')]);
        if ($vydani === null) {
            return $this->chyba('Vydání neexistuje.', 404);
        }
        if ($vydani['odeslano'] === null && $this->request->isPost()) {
            \MiroCMS\Core\Rozesilka::davka($this->app, (int) $vydani['idn']);
            $vydani = $this->db->one('SELECT * FROM {newsletter} WHERE idn = ?', [$vydani['idn']]);
        }

        return $this->view('rozeslat', 'Rozesílka newsletteru', [
            'vydani' => $vydani,
            'zbyva' => (int) $this->db->value('SELECT COUNT(*) FROM {odberatele} WHERE potvrzen = 1 AND jazyk = ? AND ido > ?', [$vydani['jazyk'], (int) $vydani['posledni']]),
        ]);
    }

    protected function akceOdberatele(): Response
    {
        if ($this->request->get('format') === 'csv') {
            $csv = "email;prihlasen;jazyk\n" . implode("\n", array_map(fn (array $o): string => $o['email'] . ';' . $o['prihlasen'] . ';' . ($o['jazyk'] !== '' ? $o['jazyk'] : \MiroCMS\Core\Jazyk::vychozi($this->app->settings())), $this->db->all('SELECT email, prihlasen, jazyk FROM {odberatele} WHERE potvrzen = 1 ORDER BY email')));

            return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="odberatele.csv"']);
        }

        return $this->view('odberatele', 'Odběratelé', ['odberatele' => $this->db->all('SELECT * FROM {odberatele} ORDER BY ido DESC LIMIT 500')]);
    }

    protected function akceSmazOdberatele(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('odberatele', ['ido' => $this->request->postInt('ido')]);
        }

        return $this->zpet('Odběratel byl odstraněn.', 'odberatele');
    }
}
