<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Editace článků.
 *
 * Pravidla:
 *  - autor vidí a edituje články své a svých podřízených, redaktor a administrátor všechny,
 *  - vydaný článek smí měnit jen ten, kdo má "právo vydávat",
 *  - bez práva vydávat nejde nastavit "Vydat článek: Ano" (článek čeká na redaktora).
 */
final class Clanky extends Modul
{
    public const string IDENT = 'clanky';
    public const string NAZEV = 'Články';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'clanek';

    private const int NA_STRANKU = 20;

    protected function akceVypis(): Response
    {
        $auth = $this->app->auth();
        $where = ['1 = 1'];
        $params = [];

        $autori = $auth->spravovaniAutori();
        if ($this->request->get('moje') === '1') {
            $autori = [$auth->id()];
        }
        if ($autori !== null) {
            $where[] = 'c.autor IN (' . implode(',', $autori) . ')';
        }
        if (($povolene = $auth->povoleneRubriky()) !== null) {
            $where[] = 'c.tema IN (' . implode(',', $povolene) . ')'; // uživatel omezený na vybrané rubriky
        }
        if (($tema = $this->request->getInt('tema')) > 0) {
            $where[] = 'c.tema = ?';
            $params[] = $tema;
        }
        // jazyková verze: výchozí jazyk webu je v sloupci uložený jako ''
        $s = $this->app->settings();
        $jazykyWebu = ($dalsi = \MiroCMS\Core\Jazyk::dalsi($s)) === [] ? [] : [\MiroCMS\Core\Jazyk::vychozi($s), ...$dalsi];
        $jazyk = in_array($this->request->get('jazyk'), $jazykyWebu, true) ? $this->request->get('jazyk') : '';
        if ($jazyk !== '') {
            $where[] = 'c.jazyk = ?';
            $params[] = \MiroCMS\Core\Jazyk::sloupec($s, $jazyk);
        }
        if (($hledat = $this->request->get('hledat')) !== '') {
            $where[] = 'c.titulek LIKE ?';
            $params[] = '%' . addcslashes($hledat, '%_\\') . '%';
        }
        $stav = $this->request->get('stav');
        $vKosi = $stav === 'kos';
        $where[] = $vKosi ? 'c.smazano IS NOT NULL' : 'c.smazano IS NULL';
        $podminkyStavu = [
            'vydane' => 'c.visible = 1 AND c.datum <= NOW()',
            'plan' => 'c.visible = 1 AND c.datum > NOW()',
            'koncepty' => "c.visible = 0 AND c.stav_redakce = ''",
            'korektura' => "c.visible = 0 AND c.stav_redakce = 'korektura'",
            'schvaleno' => "c.visible = 0 AND c.stav_redakce = 'schvaleno'",
        ];
        if (isset($podminkyStavu[$stav])) {
            $where[] = $podminkyStavu[$stav];
        }
        $cond = implode(' AND ', $where);

        $celkem = (int) $this->db->value("SELECT COUNT(*) FROM {clanky} c WHERE {$cond}", $params);
        $strana = max(1, $this->request->getInt('strana', 1));
        $clanky = $this->db->all(
            "SELECT c.idc, c.stav_redakce, c.seo_link, c.titulek, c.datum, c.visible, c.visit, c.kom, c.priority, c.smazano, c.smazano_vydany,
                    t.nazev AS tema_jm, u.jmeno AS autor_jm, u.user AS autor_login, IF(s.jmeno = '', s.user, s.jmeno) AS smazal_jm
             FROM {clanky} c
             JOIN {topic} t ON t.idt = c.tema
             LEFT JOIN {user} u ON u.idu = c.autor
             LEFT JOIN {user} s ON s.idu = c.smazal
             WHERE {$cond}
             ORDER BY " . ($vKosi ? 'c.smazano DESC' : 'c.datum DESC') . ", c.idc DESC
             LIMIT ? OFFSET ?",
            [...$params, self::NA_STRANKU, ($strana - 1) * self::NA_STRANKU],
        );

        return $this->view('vypis', 'Články', [
            'clanky' => $clanky,
            'celkem' => $celkem,
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / self::NA_STRANKU)),
            'rubriky' => $this->nabidkaRubrik(),
            'filtr' => ['tema' => $tema, 'jazyk' => $jazyk, 'hledat' => $hledat, 'moje' => $this->request->get('moje'), 'stav' => isset($podminkyStavu[$stav]) || $vKosi ? $stav : ''],
            'vKosi' => (int) $this->db->value('SELECT COUNT(*) FROM {clanky} c WHERE c.smazano IS NOT NULL' . $this->app->auth()->articleScope('c.')),
            'jazykyWebu' => $jazykyWebu,
            'smiVydavat' => $auth->smiVydavat(),
            'ctenari' => \MiroCMS\Core\Rozsireni::je($this->app->settings(), 'ctenari'),
        ]);
    }

    protected function akceNovy(): Response
    {
        if (Rubriky::strom($this->db) === []) {
            return $this->chyba('Nejprve založte alespoň jednu rubriku (Úprava rubrik).');
        }

        return $this->formular($this->vychozi());
    }

    /** Hodnoty nového článku; doplňují se jimi i pole, která při neúspěšné validaci ve formuláři chybí. */
    private function vychozi(): array
    {
        return [
            'idc' => 0, 'seo_link' => '', 'titulek' => '', 'uvod' => '', 'text' => '', 'obrazek' => '', 'obrazek_popis' => '', 'obrazek_autor' => '', 'komercni' => 0, 'komercni_partner' => '',
            'tema' => 0, 'autor' => $this->app->auth()->id(), 'datum' => date('Y-m-d H:i:s'), 'datum_pl' => null,
            'visible' => 0, 'zobr_na_indexu' => 1, 'priority' => 0, 'typ_clanku' => 1, 'sablona' => null,
            'zdroj' => '', 't_slova' => '', 'povolit_kom' => 1, 'skupina_cl' => null,
            'seo_titulek' => '', 'seo_popis' => '', 'noindex' => 0, 'externi_autor' => '', 'pristup' => 0, 'preklad_z' => null, 'medium_url' => '', 'zive' => 0, 'recenze_predmet' => '', 'recenze_hodnoceni' => null, 'shrnuti' => '', 'faq' => '', 'stav_redakce' => '', 'poznamka' => '',
        ];
    }

    protected function akceEdit(): Response
    {
        $clanek = $this->nacti($this->request->getInt('id'));
        if ($clanek === null) {
            return $this->chyba('Článek neexistuje nebo k němu nemáte přístup.', 404);
        }
        // zámek proti souběžné úpravě: platí 3 minuty od posledního "jsem tu" z editoru
        $ja = $this->app->auth()->id();
        if ($clanek['zamek_kdo'] !== null && (int) $clanek['zamek_kdo'] !== $ja && strtotime((string) $clanek['zamek_cas']) > time() - 180) {
            $kdo = $this->db->value("SELECT IF(jmeno = '', user, jmeno) FROM {user} WHERE idu = ?", [$clanek['zamek_kdo']]);
            $this->app->session->flash('chyba', t('Článek má právě otevřený %s. Když ho uložíte oba, přepíšete si navzájem změny – domluvte se, kdo bude pokračovat.', (string) $kdo));
        } else {
            $this->db->update('clanky', ['zamek_kdo' => $ja, 'zamek_cas' => date('Y-m-d H:i:s')], ['idc' => $clanek['idc']]);
        }

        return $this->formular($clanek);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $auth = $this->app->auth();
        $r = $this->request;
        $id = $r->postInt('idc');

        $puvodni = null;
        if ($id > 0) {
            $puvodni = $this->nacti($id);
            if ($puvodni === null) {
                return $this->chyba('Článek neexistuje nebo k němu nemáte přístup.', 404);
            }
            if ($puvodni['visible'] && !$auth->smiVydavat()) {
                return $this->chyba('Vydaný článek může upravit jen uživatel s právem vydávat.', 403);
            }
        }

        $data = [
            'titulek' => $r->post('titulek'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('titulek'), 150),
            'uvod' => $r->post('uvod'),
            'text' => $r->post('text'),
            'obrazek' => $r->post('obrazek'),
            'tema' => $r->postInt('tema'),
            'autor' => $r->postInt('autor'),
            'datum' => self::datumZFormulare($r->post('datum')) ?? date('Y-m-d H:i:s'),
            'datum_pl' => self::datumZFormulare($r->post('datum_pl')),
            'visible' => (int) ($r->post('stav') === 'vydany' && $auth->smiVydavat()),
            'stav_redakce' => in_array($r->post('stav'), ['korektura', 'schvaleno'], true) && ($r->post('stav') !== 'schvaleno' || $auth->smiVydavat()) ? $r->post('stav') : '',
            'poznamka' => $r->post('poznamka'),
            // o titulní straně rozhoduje ten, kdo smí vydávat; autorovi zůstává, co článek měl (nový: na hlavní stránce, nepřipnutý)
            'zobr_na_indexu' => $auth->smiVydavat() ? (int) $r->postBool('zobr_na_indexu') : (int) ($puvodni['zobr_na_indexu'] ?? 1),
            // připnutý článek = priorita > 0; hlavní stránka řadí podle priority a pak podle data
            'priority' => !$auth->smiVydavat() ? (int) ($puvodni['priority'] ?? 0) : ($r->postBool('pripnout') ? max(100, (int) ($puvodni['priority'] ?? 0)) : 0),
            'typ_clanku' => $r->postBool('kratky') ? 2 : 1,
            'sablona' => $r->postInt('sablona') ?: null,
            'zdroj' => $r->post('zdroj'),
            't_slova' => $r->post('t_slova'),
            'povolit_kom' => (int) $r->postBool('povolit_kom'),
            'seo_titulek' => mb_substr($r->post('seo_titulek'), 0, 255),
            'seo_popis' => mb_substr($r->post('seo_popis'), 0, 320),
            'noindex' => (int) $r->postBool('noindex'),
            'externi_autor' => mb_substr(trim($r->post('externi_autor')), 0, 120),
            'komercni' => (int) $r->postBool('komercni'),
            'komercni_partner' => $r->postBool('komercni') ? mb_substr(trim($r->post('komercni_partner')), 0, 120) : '',
            'obrazek_popis' => mb_substr(trim($r->post('obrazek_popis')), 0, 300),
            'obrazek_autor' => mb_substr(trim($r->post('obrazek_autor')), 0, 120),
            'medium_url' => mb_substr(trim($r->post('medium_url')), 0, 255),
            'zive' => min(2, max(0, $r->postInt('zive'))),
            'recenze_predmet' => mb_substr($r->post('recenze_predmet'), 0, 160),
            'recenze_hodnoceni' => $r->post('recenze_hodnoceni') === '' ? null : min(100, max(0, $r->postInt('recenze_hodnoceni'))),
            'pristup' => \MiroCMS\Core\Rozsireni::je($this->app->settings(), 'ctenari') ? min(2, max(0, $r->postInt('pristup'))) : (int) ($puvodni['pristup'] ?? 0),
            'shrnuti' => $r->post('shrnuti'),
            'faq' => $r->post('faq'),
            'zmeneno' => date('Y-m-d H:i:s'),
            'zamek_kdo' => null, // uložením se článek uvolní
            'zamek_cas' => null,
        ];

        $chyby = [];
        if ($data['titulek'] === '') {
            $chyby['titulek'] = 'Vyplňte titulek článku.';
        }
        if ($this->db->value('SELECT idt FROM {topic} WHERE idt = ?', [$data['tema']]) === null) {
            $chyby['tema'] = 'Vyberte rubriku.';
        } elseif (($povoleneRubriky = $auth->povoleneRubriky()) !== null && !in_array($data['tema'], $povoleneRubriky, true)) {
            $chyby['tema'] = 'Do této rubriky nemáte oprávnění psát.';
        }
        $povoleniAutori = $auth->spravovaniAutori();
        if ($povoleniAutori !== null && !in_array($data['autor'], $povoleniAutori, true)) {
            $data['autor'] = $auth->id();
        }
        if ($this->db->value('SELECT idu FROM {user} WHERE idu = ?', [$data['autor']]) === null) {
            $chyby['autor'] = 'Vyberte autora.';
        }
        if ($chyby !== []) {
            return $this->formular(['idc' => $id, 'skupina_cl' => $r->postInt('skupina_cl') ?: null] + $data + ($puvodni ?? $this->vychozi()), $chyby);
        }
        // jazyková verze se přebírá z rubriky; překlad se propojuje s článkem ve výchozím jazyce (adresa nebo číslo článku)
        $data['jazyk'] = (string) $this->db->value('SELECT jazyk FROM {topic} WHERE idt = ?', [$data['tema']]);
        $original = trim($r->post('preklad_z'));
        $data['preklad_z'] = $original === '' || $data['jazyk'] === '' ? null
            : ($this->db->value("SELECT idc FROM {clanky} WHERE (idc = ? OR seo_link = ?) AND jazyk = '' AND idc <> ?", [(int) $original, basename((string) parse_url($original, PHP_URL_PATH)), $id]) ?: null);

        if ($r->postBool('oznacit_aktualizaci') && $data['visible']) {
            $data['aktualizovano'] = date('Y-m-d H:i:s');
        }
        $data['seo_link'] = $this->volnySeoLink($data['seo_link'], $id);
        $data['skupina_cl'] = $this->serial($r->postInt('skupina_cl'), $r->post('serial_novy'));
        if ($id > 0) {
            if ([$puvodni['titulek'], $puvodni['uvod'], $puvodni['text']] !== [$data['titulek'], $data['uvod'], $data['text']]) {
                $this->ulozRevizi($puvodni);
            }
            $this->db->update('clanky', $data, ['idc' => $id]);
            if ($puvodni['seo_link'] !== $data['seo_link'] && $puvodni['visible']) {
                // vydaný článek změnil adresu: stará se přesměruje, aby odkazy a vyhledávače nepřišly o stránku
                Presmerovani::pridej($this->db, 'clanek/' . $puvodni['seo_link'], 'clanek/' . $data['seo_link']);
            }
        } else {
            $id = $this->db->insert('clanky', $data);
        }

        Galerie::zapisPouziti($this->db, $id, $data['obrazek'], $data['uvod'], $data['text']);
        // spoluautoři: jen existující účty, bez hlavního autora
        $this->db->delete('clanky_autori', ['idc' => $id]);
        foreach (array_unique(array_map(intval(...), $r->postList('spoluautori'))) as $idu) {
            if ($idu !== $data['autor'] && $this->db->value('SELECT idu FROM {user} WHERE idu = ?', [$idu]) !== null) {
                $this->db->insert('clanky_autori', ['idc' => $id, 'idu' => $idu]);
            }
        }
        \MiroCMS\Core\Hledani::indexuj($this->db, $id);
        // uložený článek ruší rozepsaný stav na serveru (u nového článku je veden pod číslem 0)
        $this->db->run('DELETE FROM {clanky_koncepty} WHERE kdo = ? AND idc IN (0, ?)', [$auth->id(), $id]);
        $this->ulozStitky($id, $r->post('stitky'));
        // nově vydaný článek se oznámí (webhook, IndexNow, Web Push); naplánovaný počká na svůj čas - viz Core\Oznameni
        \MiroCMS\Core\Oznameni::zpracuj($this->app);
        if ($data['visible'] && !empty($puvodni['visible']) && !$data['noindex'] && strtotime($data['datum']) <= time()) {
            (new \MiroCMS\Front\Seo($this->app))->indexNow($this->app->urlClanku($data['seo_link'], $data['jazyk'])); // úprava vydaného článku
        }

        $this->upozorniRedakci($puvodni, $data, $id);

        $hlaska = $auth->smiVydavat() ? 'Článek byl uložen.' : 'Článek byl uložen. Na webu se objeví, až ho vydá redaktor.';

        return $r->post('po_ulozeni') === 'zustat'
            ? $this->zpet($hlaska, 'edit', ['id' => $id])
            : $this->zpet($hlaska);
    }

    /**
     * Redakční předávka e-mailem: autor pošle článek ke korektuře -> dozvědí se to ti, kdo ho smí vydat;
     * redaktor článek vydá nebo vrátí do konceptu -> dozví se to autor. Kdo upozornění nechce, vypne si je v Můj účet.
     *
     * @param array<string, mixed>|null $puvodni
     * @param array<string, mixed> $data
     */
    private function upozorniRedakci(?array $puvodni, array $data, int $id): void
    {
        $ja = $this->app->auth()->id();
        $byloKorektura = ($puvodni['stav_redakce'] ?? '') === 'korektura' && empty($puvodni['visible']);
        $prijemci = [];
        $udalost = '';
        if (!$data['visible'] && $data['stav_redakce'] === 'korektura' && !$byloKorektura) {
            $udalost = 'korektura';
            // kdo smí vydávat a není omezen na jiné rubriky
            $prijemci = $this->db->all(
                'SELECT u.* FROM {user} u WHERE u.blokovat = 0 AND u.upozorneni = 1 AND u.email <> \'\' AND u.idu <> ? AND (u.admin >= ? OR u.pravo_vydavat = 1)
                 AND (NOT EXISTS (SELECT 1 FROM {user_rubriky} r WHERE r.idu = u.idu) OR EXISTS (SELECT 1 FROM {user_rubriky} r WHERE r.idu = u.idu AND r.idt IN (' . implode(',', $this->rubrikaSPredky((int) $data['tema'])) . ')))',
                [$ja, \MiroCMS\Core\Auth::REDAKTOR],
            );
        } elseif ((int) $data['autor'] !== $ja && $puvodni !== null && ($data['visible'] && empty($puvodni['visible']) || (!$data['visible'] && $byloKorektura && $data['stav_redakce'] === ''))) {
            $udalost = $data['visible'] ? 'vydano' : 'vraceno';
            $prijemci = $this->db->all("SELECT * FROM {user} WHERE idu = ? AND blokovat = 0 AND upozorneni = 1 AND email <> ''", [$data['autor']]);
        }
        $web = $this->app->settings();
        $kdo = (string) ($this->app->auth()->user()['jmeno'] ?: $this->app->auth()->user()['user']);
        $uprava = $this->app->request->origin() . $this->url('edit', ['id' => $id]);
        $naWebu = $this->app->request->origin() . $this->app->url(($data['jazyk'] !== '' ? $data['jazyk'] . '/' : '') . 'clanek/' . $data['seo_link']);
        foreach ($prijemci as $komu) {
            $jazyk = isset(\MiroCMS\Core\Jazyk::ADMINISTRACE[$komu['jazyk']]) ? $komu['jazyk'] : \MiroCMS\Core\Jazyk::vychozi($web);
            [$predmet, $text] = \MiroCMS\Core\Jazyk::docasne($jazyk, fn (): array => match ($udalost) {
                'korektura' => [t('Ke korektuře: %s', $data['titulek']), t('%s posílá článek „%s“ ke korektuře.', $kdo, $data['titulek']) . ($data['poznamka'] !== '' ? "\n\n" . t('Poznámka pro redakci') . ': ' . $data['poznamka'] : '') . "\n\n" . $uprava],
                'vydano' => [t('Váš článek vyšel: %s', $data['titulek']), t('%s vydal(a) váš článek „%s“.', $kdo, $data['titulek']) . "\n\n" . (strtotime($data['datum']) > time() ? t('Na webu se objeví %s.', datum($data['datum'], true)) . "\n\n" . $uprava : $naWebu)],
                default => [t('Článek se vrací k dopracování: %s', $data['titulek']), t('%s vrátil(a) článek „%s“ do konceptu.', $kdo, $data['titulek']) . ($data['poznamka'] !== '' ? "\n\n" . t('Poznámka pro redakci') . ': ' . $data['poznamka'] : '') . "\n\n" . $uprava],
            }, 'admin-');
            \MiroCMS\Core\Posta::odesli($web, $komu['email'], $predmet . ' – ' . $web->get('nazev_webu'), $text . "\n\n--\n" . $web->get('nazev_webu'));
        }
    }

    /**
     * Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen titulek, perex a text. Platí stejná pravidla jako
     * u běžného uložení - oprávnění přes nacti(), vydaný článek jen s právem vydávat, zámek, revize, hledání, použití obrázků.
     */
    protected function akceUlozText(): Response
    {
        $r = $this->request;
        $clanek = $r->isPost() ? $this->nacti($r->postInt('id')) : null;
        $ja = $this->app->auth()->id();
        if ($clanek === null || ($clanek['visible'] && !$this->app->auth()->smiVydavat())
            || ($clanek['zamek_kdo'] !== null && (int) $clanek['zamek_kdo'] !== $ja && strtotime((string) $clanek['zamek_cas']) > time() - 180)) {
            return $this->zpetNaWeb($r->post('zpet'));
        }
        $data = ['titulek' => mb_substr($r->post('titulek'), 0, 255), 'uvod' => $r->post('uvod'), 'text' => $r->post('text')];
        // nevydaný článek je na webu vidět jen v náhledu
        $nahled = $clanek['visible'] && strtotime((string) $clanek['datum']) <= time() ? '' : 'nahled=1';
        if ($data['titulek'] === '') {
            return $this->zpetNaWeb($r->post('zpet'), '?' . ($nahled !== '' ? $nahled . '&' : '') . 'upravit=text&chyba=1');
        }
        if ([$clanek['titulek'], $clanek['uvod'], $clanek['text']] !== array_values($data)) {
            $this->ulozRevizi($clanek);
        }
        $this->db->update('clanky', $data + ['zmeneno' => date('Y-m-d H:i:s'), 'zamek_kdo' => null], ['idc' => $clanek['idc']]);
        Galerie::zapisPouziti($this->db, (int) $clanek['idc'], (string) $clanek['obrazek'], $data['uvod'], $data['text']);
        \MiroCMS\Core\Hledani::indexuj($this->db, (int) $clanek['idc']);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'clanky', 'úprava přímo na webu', mb_substr($data['titulek'], 0, 80));
        if ($clanek['visible'] && !$clanek['noindex'] && strtotime((string) $clanek['datum']) <= time()) {
            (new \MiroCMS\Front\Seo($this->app))->indexNow($this->app->urlClanku($clanek['seo_link'], $clanek['jazyk']));
        }

        return $this->zpetNaWeb($r->post('zpet'), $nahled !== '' ? '?' . $nahled : '');
    }

    /** "Jsem tu" z otevřeného editoru - prodlužuje zámek článku. */
    protected function akceZamek(): Response
    {
        $clanek = $this->request->isPost() ? $this->nacti($this->request->postInt('idc')) : null;
        $ja = $this->app->auth()->id();
        if ($clanek !== null && ($clanek['zamek_kdo'] === null || (int) $clanek['zamek_kdo'] === $ja || strtotime((string) $clanek['zamek_cas']) <= time() - 180)) {
            $this->db->update('clanky', ['zamek_kdo' => $ja, 'zamek_cas' => date('Y-m-d H:i:s')], ['idc' => $clanek['idc']]);
        }

        return Response::json(['ok' => $clanek !== null]);
    }

    /**
     * Titulní strana: ruční pořadí článků nahoře na hlavní stránce. Připnuté články mají prioritu 250, 245, 240…
     * (hlavní stránka řadí podle priority a pak podle data), ostatní nulu.
     */
    /**
     * Rubrika a všechny její nadřazené: kdo je omezen na rubriku, smí i do jejích podrubrik (viz Auth::povoleneRubriky()).
     *
     * @return list<int>
     */
    private function rubrikaSPredky(int $idt): array
    {
        $ids = [];
        for ($i = 0; $idt > 0 && $i < 10 && !in_array($idt, $ids, true); $i++) {
            $ids[] = $idt;
            $idt = (int) $this->db->value('SELECT id_predka FROM {topic} WHERE idt = ?', [$idt]);
        }

        return $ids === [] ? [0] : $ids;
    }

    protected function akceTitulni(): Response
    {
        if (!$this->app->auth()->smiVydavat()) {
            return $this->chyba('Titulní stranu může skládat jen uživatel s právem vydávat.', 403);
        }
        if ($this->request->isPost()) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map(intval(...), explode(',', $this->request->post('poradi')))))), 0, 30);
            $this->db->transaction(function () use ($ids): void {
                $this->db->run("UPDATE {clanky} SET priority = 0 WHERE priority > 0 AND jazyk = ''"); // obrazovka skládá jen výchozí jazyk; jiným jazykům připnutí nebere
                foreach ($ids as $i => $idc) {
                    $this->db->update('clanky', ['priority' => 250 - $i * 5], ['idc' => $idc]); // sloupec je TINYINT: 250, 245… (30 míst)
                }
            });

            return $this->zpet('Titulní strana je uložená.', 'titulni');
        }
        $sloupce = "c.idc, c.titulek, c.datum, c.obrazek, c.priority, t.nazev AS tema_jm";
        $vydane = "c.visible = 1 AND c.datum <= NOW() AND c.zobr_na_indexu = 1 AND c.jazyk = ''";

        return $this->view('titulni', 'Titulní strana', [
            'pripnute' => $this->db->all("SELECT {$sloupce} FROM {clanky} c JOIN {topic} t ON t.idt = c.tema WHERE {$vydane} AND c.priority > 0 ORDER BY c.priority DESC, c.datum DESC"),
            'dalsi' => $this->db->all("SELECT {$sloupce} FROM {clanky} c JOIN {topic} t ON t.idt = c.tema WHERE {$vydane} AND c.priority = 0 ORDER BY c.datum DESC LIMIT 30"),
        ]);
    }

    /**
     * Průběžné ukládání rozepsaného článku na server (image/editor.js). Neukládá článek - jen stav formuláře
     * přihlášeného uživatele, aby v psaní mohl pokračovat jinde. POST bez pole "pole" rozepsaný stav smaže.
     */
    protected function akceKoncept(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $idc = $this->request->postInt('idc');
        if ($idc > 0 && $this->nacti($idc) === null) {
            return Response::json(['ok' => false], 404);
        }
        $ja = $this->app->auth()->id();
        $data = (string) ($_POST['pole'] ?? '');
        if ($data === '' || strlen($data) > 3_000_000 || !is_array(json_decode($data, true))) {
            $this->db->delete('clanky_koncepty', ['kdo' => $ja, 'idc' => $idc]);

            return Response::json(['ok' => true, 'smazano' => true]);
        }
        $this->db->run('INSERT INTO {clanky_koncepty} (kdo, idc, cas, data) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE cas = NOW(), data = VALUES(data)', [$ja, $idc, $data]);
        if (random_int(1, 40) === 1) {
            $this->db->run('DELETE FROM {clanky_koncepty} WHERE cas < NOW() - INTERVAL 30 DAY');
        }

        return Response::json(['ok' => true]);
    }

    /** Hledání článků podle titulku pro dialog odkazu v editoru. */
    protected function akceHledejJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['clanky' => []]);
        }
        if ($this->request->get('uprava') === '1') {
            // paleta příkazů: odkazy do editoru, jen na články, které smí přihlášený upravovat
            $clanky = $this->db->all(
                'SELECT idc, titulek, visible AND datum <= NOW() AS vydany FROM {clanky} WHERE smazano IS NULL AND titulek LIKE ?' . $this->app->auth()->articleScope() . ' ORDER BY datum DESC LIMIT 8',
                ['%' . addcslashes($q, '%_\\') . '%'],
            );

            return Response::json(['clanky' => array_map(fn (array $c): array => [
                'titulek' => $c['titulek'], 'vydany' => (bool) $c['vydany'], 'url' => $this->url('edit', ['id' => $c['idc']]),
            ], $clanky)]);
        }
        $clanky = $this->db->all(
            "SELECT titulek, seo_link, jazyk, visible AND datum <= NOW() AS vydany FROM {clanky} WHERE smazano IS NULL AND titulek LIKE ? AND typ_clanku = 1 ORDER BY datum DESC LIMIT 8",
            ['%' . addcslashes($q, '%_\\') . '%'],
        );

        return Response::json(['clanky' => array_map(fn (array $c): array => [
            'titulek' => $c['titulek'], 'vydany' => (bool) $c['vydany'],
            'url' => $this->app->url(($c['jazyk'] !== '' ? $c['jazyk'] . '/' : '') . 'clanek/' . $c['seo_link']),
        ], $clanky)]);
    }

    /** Živá reportáž: rychlé psaní průběžných zápisů k článku. */
    protected function akceZive(): Response
    {
        $clanek = $this->nacti($this->request->isPost() ? $this->request->postInt('idc') : $this->request->getInt('id'));
        if ($clanek === null) {
            return $this->chyba('Článek neexistuje nebo k němu nemáte přístup.', 404);
        }
        if ($clanek['visible'] && !$this->app->auth()->smiVydavat()) {
            return $this->chyba('Zápisy k vydanému článku může psát jen uživatel s právem vydávat.', 403);
        }
        if ($this->request->isPost()) {
            if ($this->request->postInt('smazat') > 0) {
                $this->db->delete('zive', ['idz' => $this->request->postInt('smazat'), 'idc' => $clanek['idc']]);
            } elseif ($this->request->post('stav') !== '') {
                $this->db->update('clanky', ['zive' => $this->request->post('stav') === 'ukoncit' ? 2 : 1, 'zmeneno' => date('Y-m-d H:i:s')], ['idc' => $clanek['idc']]);
            } elseif (trim(strip_tags($this->request->post('text'), '<img><iframe>')) !== '') {
                $this->db->insert('zive', ['idc' => $clanek['idc'], 'cas' => date('Y-m-d H:i:s'), 'text' => $this->request->post('text'), 'dulezite' => (int) $this->request->postBool('dulezite'), 'autor' => $this->app->auth()->id()]);
                $this->db->update('clanky', ['zmeneno' => date('Y-m-d H:i:s')], ['idc' => $clanek['idc']]);
            }

            return Response::redirect($this->url('zive', ['id' => (int) $clanek['idc']]));
        }

        return $this->view('zive', 'Živá reportáž', [
            'clanek' => $clanek,
            'zapisy' => $this->db->all("SELECT z.*, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS autor_jm FROM {zive} z LEFT JOIN {user} u ON u.idu = z.autor WHERE z.idc = ? ORDER BY z.idz DESC", [$clanek['idc']]),
        ]);
    }

    /**
     * AI asistent: návrh k rozepsanému článku (titulky, perex, shrnutí, SEO popis, štítky, korektura, popis obrázku).
     * Pracuje s textem z formuláře, nic neukládá - o použití návrhu rozhoduje redaktor.
     */
    protected function akceAsistent(): Response
    {
        $asistent = new \MiroCMS\Core\Asistent($this->app->settings());
        if (!$this->request->isPost() || !$asistent->pripraven()) {
            return Response::json(['chyba' => t('AI asistent není zapnutý nebo chybí klíč (nabídka Rozšíření).')], 400);
        }
        // pojistka proti nechtěné útratě: nejvýš 60 dotazů za hodinu na uživatele
        $ja = $this->app->auth()->id();
        if ((int) $this->db->value("SELECT COUNT(*) FROM {protokol} WHERE kdo = ? AND modul = 'asistent' AND cas > NOW() - INTERVAL 1 HOUR", [$ja]) >= 60) {
            return Response::json(['chyba' => t('Za poslední hodinu jste asistenta použili 60×. Zkuste to prosím později.')], 429);
        }
        $ukol = $this->request->post('ukol');
        $obrazek = null;
        if ($ukol === 'alt') {
            // jen soubory z media/: cesta se skládá z ověřených částí adresy
            $obrazek = preg_match('#media/(\d{4}/\d{2}/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif))$#', (string) parse_url($this->request->post('obrazek'), PHP_URL_PATH), $m) ? MIROCMS_ROOT . '/media/' . $m[1] : null;
            $mensi = $obrazek === null ? null : preg_replace('/\.(\w+)$/', '-1200.$1', $obrazek);
            $obrazek = $mensi !== null && is_file($mensi) ? $mensi : $obrazek;
        }
        try {
            $vysledek = $asistent->navrhni($ukol, [
                'titulek' => $this->request->post('titulek'),
                'uvod' => $this->request->post('uvod'),
                'text' => $this->request->post('text'),
                'stitky_webu' => $ukol === 'stitky' ? array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 300'), 'nazev') : [],
            ], $obrazek);
        } catch (\RuntimeException $e) {
            return Response::json(['chyba' => t($e->getMessage())], 502);
        }
        \MiroCMS\Admin\Protokol::zapis($this->app, 'asistent', $ukol, mb_substr($this->request->post('titulek'), 0, 80));

        return Response::json($vysledek);
    }

    /**
     * „Přeložit asistentem“: z uložené verze článku ve výchozím jazyce založí koncept v rubrice cílového jazyka,
     * propojený s originálem. Překlad vždy čeká na přečtení člověkem – nikdy se nevydává sám.
     */
    protected function akcePreloz(): Response
    {
        $clanek = $this->request->isPost() ? $this->nacti($this->request->postInt('idc')) : null;
        if ($clanek === null) {
            return $this->zpet('Článek nejdřív uložte, pak ho půjde přeložit.', typ: 'chyba');
        }
        $zpetNaClanek = fn (string $hlaska): Response => $this->zpet($hlaska, 'edit', ['id' => $clanek['idc']], typ: 'chyba');
        $jazyk = $this->request->post('prelozit_do');
        $asistent = new \MiroCMS\Core\Asistent($this->app->settings());
        if (!$asistent->pripraven()) {
            return $zpetNaClanek('AI asistent není zapnutý nebo chybí klíč (nabídka Rozšíření).');
        }
        if ($clanek['jazyk'] !== '' || !in_array($jazyk, \MiroCMS\Core\Jazyk::dalsi($this->app->settings()), true)) {
            return $zpetNaClanek('Přeložit jde jen článek ve výchozím jazyce, a to do některé z dalších jazykových verzí webu.');
        }
        if (($hotovy = $this->db->value('SELECT idc FROM {clanky} WHERE preklad_z = ? AND jazyk = ?', [$clanek['idc'], $jazyk])) !== null) {
            return $this->zpet('Překlad do tohoto jazyka už existuje – tady je.', 'edit', ['id' => (int) $hotovy]);
        }
        // cílová rubrika: protějšek rubriky originálu, jinak první rubrika daného jazyka (a vždy jen taková, kam uživatel smí psát)
        $povolene = $this->app->auth()->povoleneRubriky();
        $rubrika = null;
        foreach ($this->db->all('SELECT idt, preklad_z FROM {topic} WHERE jazyk = ? ORDER BY (preklad_z <=> ?) DESC, hodnost DESC, idt', [$jazyk, $clanek['tema']]) as $kandidat) {
            if ($povolene === null || in_array((int) $kandidat['idt'], $povolene, true)) {
                $rubrika = (int) $kandidat['idt'];
                break;
            }
        }
        if ($rubrika === null) {
            return $zpetNaClanek('V cílovém jazyce zatím není žádná rubrika, do které smíte psát. Založte ji v Rubrikách (pole Jazyková verze).');
        }
        $ja = $this->app->auth()->id();
        if ((int) $this->db->value("SELECT COUNT(*) FROM {protokol} WHERE kdo = ? AND modul = 'asistent' AND cas > NOW() - INTERVAL 1 HOUR", [$ja]) >= 60) {
            return $zpetNaClanek('Za poslední hodinu jste asistenta použili 60×. Zkuste to prosím později.');
        }

        set_time_limit(600); // dlouhý článek se překládá po dávkách
        $prosta = ['titulek', 'seo_titulek', 'seo_popis', 'shrnuti', 'faq', 'recenze_predmet', 't_slova'];
        try {
            $preklad = $asistent->preloz(array_map(strval(...), array_intersect_key($clanek, array_flip([...$prosta, 'uvod', 'text']))), $jazyk, $prosta);
        } catch (\RuntimeException $e) {
            return $zpetNaClanek(t($e->getMessage()));
        }
        \MiroCMS\Admin\Protokol::zapis($this->app, 'asistent', 'preklad-' . $jazyk, mb_substr($clanek['titulek'], 0, 80));

        // koncept přebírá z originálu vše, co se nepřekládá (obrázek, typ, šablonu, přístup, autora…); počitadla a zámky ne
        $prevzit = ['obrazek', 'autor', 'externi_autor', 'typ_clanku', 'sablona', 'zdroj', 'povolit_kom', 'noindex', 'pristup', 'medium_url', 'recenze_hodnoceni', 'zobr_na_indexu'];
        $data = array_intersect_key($clanek, array_flip($prevzit)) + [
            'tema' => $rubrika, 'jazyk' => $jazyk, 'preklad_z' => $clanek['idc'], 'visible' => 0, 'datum' => date('Y-m-d H:i:s'),
        ];
        foreach ($preklad as $pole => $hodnota) {
            $data[$pole] = $pole === 'titulek' ? mb_substr($hodnota, 0, 255) : $hodnota;
        }
        $data['seo_link'] = $this->volnySeoLink(slugify($data['titulek'], 100), 0);
        $id = $this->db->insert('clanky', $data);
        Galerie::zapisPouziti($this->db, $id, (string) $data['obrazek'], $data['uvod'], $data['text']);
        $this->db->run('INSERT INTO {clanky_autori} (idc, idu) SELECT ?, idu FROM {clanky_autori} WHERE idc = ?', [$id, $clanek['idc']]);
        $this->db->run('INSERT INTO {clanky_stitky} (idc, ids) SELECT ?, ids FROM {clanky_stitky} WHERE idc = ?', [$id, $clanek['idc']]);
        \MiroCMS\Core\Hledani::indexuj($this->db, $id);

        return $this->zpet('Překlad je založený jako koncept. Než ho vydáte, přečtěte ho – asistent může chybovat ve jménech, číslech a odborných výrazech.', 'edit', ['id' => $id]);
    }

    /** Redakční kalendář: články podle data vydání v měsíční mřížce. */
    protected function akceKalendar(): Response
    {
        $mesic = preg_match('/^\d{4}-\d{2}$/', $this->request->get('mesic')) ? $this->request->get('mesic') : date('Y-m');
        $od = new \DateTimeImmutable($mesic . '-01');
        $clanky = $this->db->all(
            'SELECT idc, titulek, datum, visible, stav_redakce FROM {clanky} WHERE smazano IS NULL AND datum >= ? AND datum < ?' . $this->app->auth()->articleScope() . ' ORDER BY datum',
            [$od->format('Y-m-d'), $od->modify('+1 month')->format('Y-m-d')],
        );
        $dny = [];
        foreach ($clanky as $c) {
            $dny[(int) date('j', strtotime($c['datum']))][] = $c;
        }

        return $this->view('kalendar', 'Redakční kalendář', ['od' => $od, 'dny' => $dny]);
    }

    /** Vydání konceptu jedním kliknutím z přehledu (dřív modul Redaktor). */
    protected function akceVydat(): Response
    {
        $clanek = $this->nacti($this->request->postInt('idc'));
        if (!$this->request->isPost() || $clanek === null || !$this->app->auth()->smiVydavat()) {
            return $this->zpet('Článek nelze vydat.', typ: 'chyba');
        }
        $this->db->update('clanky', ['visible' => 1, 'stav_redakce' => '', 'zmeneno' => date('Y-m-d H:i:s')], ['idc' => $clanek['idc']]);
        // stejné upozornění jako při vydání z formuláře: autor se dozví, že jeho článek vyšel
        $this->upozorniRedakci($clanek, ['visible' => 1, 'stav_redakce' => ''] + $clanek, (int) $clanek['idc']);
        \MiroCMS\Core\Oznameni::zpracuj($this->app);

        return $this->zpet(strtotime($clanek['datum']) > time() ? t('Článek je naplánován na %s.', datum($clanek['datum'], true)) : 'Článek byl vydán.', '', ['stav' => 'koncepty']);
    }

    /** Načte do editoru starší verzi článku; uloží se až odesláním formuláře. */
    protected function akceRevize(): Response
    {
        $clanek = $this->nacti($this->request->getInt('id'));
        $revize = $clanek === null ? null : $this->db->one('SELECT * FROM {clanky_revize} WHERE idr = ? AND idc = ?', [$this->request->getInt('idr'), $clanek['idc']]);
        if ($revize === null) {
            return $this->chyba('Verze článku neexistuje.', 404);
        }
        $this->app->session->flash('info', t('V editoru je verze z %s. Platit začne, až článek uložíte.', datum($revize['datum'], true)));

        return $this->formular(['titulek' => $revize['titulek'], 'uvod' => $revize['uvod'], 'text' => $revize['text']] + $clanek);
    }

    /** Co se od uložené verze změnilo: porovnání starší verze se současným zněním článku. */
    protected function akcePorovnej(): Response
    {
        $clanek = $this->nacti($this->request->getInt('id'));
        $revize = $clanek === null ? null : $this->db->one(
            "SELECT r.*, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm FROM {clanky_revize} r LEFT JOIN {user} u ON u.idu = r.kdo WHERE r.idr = ? AND r.idc = ?",
            [$this->request->getInt('idr'), $clanek['idc'] ?? 0],
        );
        if ($revize === null) {
            return $this->chyba('Verze článku neexistuje.', 404);
        }

        return $this->view('porovnani', 'Porovnání verzí', [
            'clanek' => $clanek,
            'revize' => $revize,
            'titulek' => \MiroCMS\Core\Rozdil::html((string) $revize['titulek'], (string) $clanek['titulek']),
            'uvod' => \MiroCMS\Core\Rozdil::html((string) $revize['uvod'], (string) $clanek['uvod']),
            'text' => \MiroCMS\Core\Rozdil::html((string) $revize['text'], (string) $clanek['text']),
        ]);
    }

    /** Nefunkční odkazy nalezené kontrolou na pozadí (Core\Odkazy). */
    protected function akceOdkazy(): Response
    {
        if ($this->request->isPost()) {
            // "zkontrolovat znovu": článek se zařadí na začátek fronty
            $this->db->update('clanky', ['odkazy_cas' => null], ['idc' => $this->request->postInt('idc')]);
            $this->db->delete('odkazy_vadne', ['idc' => $this->request->postInt('idc')]);

            return $this->zpet('Článek se zkontroluje znovu během několika minut.', 'odkazy');
        }
        return $this->view('odkazy', 'Nefunkční odkazy', [
            'odkazy' => $this->db->all('SELECT o.*, c.titulek FROM {odkazy_vadne} o JOIN {clanky} c ON c.idc = o.idc WHERE 1 = 1' . $this->app->auth()->articleScope('c.') . ' ORDER BY o.cas DESC LIMIT 300'),
            'zkontrolovano' => (int) $this->db->value('SELECT COUNT(*) FROM {clanky} WHERE odkazy_cas IS NOT NULL'),
            'celkem' => (int) $this->db->value('SELECT COUNT(*) FROM {clanky} WHERE visible = 1 AND datum <= NOW()'),
            'zapnuto' => $this->app->settings()->bool('kontrola_odkazu'),
        ]);
    }

    /** Hromadné akce ve výpisu: přesun do rubriky, přidání štítku, zamknutí / odemknutí pro čtenáře. */
    protected function akceHromadne(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $co = $this->request->post('provest');
        $rubrika = $this->db->one('SELECT idt, jazyk FROM {topic} WHERE idt = ?', [$this->request->postInt('do_rubriky')]);
        $stitek = mb_substr(trim($this->request->post('stitek')), 0, 80);
        if ($co === 'rubrika' && $rubrika !== null && ($povolene = $this->app->auth()->povoleneRubriky()) !== null && !in_array((int) $rubrika['idt'], $povolene, true)) {
            return $this->zpet('Do této rubriky nemáte oprávnění články přesouvat.', typ: 'chyba');
        }
        if (($co === 'rubrika' && $rubrika === null) || ($co === 'stitek' && $stitek === '')) {
            return $this->zpet($co === 'rubrika' ? 'Vyberte rubriku, do které se mají články přesunout.' : 'Napište štítek, který se má článkům přidat.', typ: 'chyba');
        }
        $pocet = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $clanek = $this->nacti((int) $id);
            if ($clanek === null || ($clanek['visible'] && !$this->app->auth()->smiVydavat())) {
                continue;
            }
            match ($co) {
                'rubrika' => $this->db->update('clanky', ['tema' => $rubrika['idt'], 'jazyk' => $rubrika['jazyk']], ['idc' => $clanek['idc']]),
                'zamknout' => $this->db->update('clanky', ['pristup' => 1], ['idc' => $clanek['idc']]),
                'odemknout' => $this->db->update('clanky', ['pristup' => 0], ['idc' => $clanek['idc']]),
                'stitek' => $this->pridejStitek((int) $clanek['idc'], $stitek),
                default => null,
            };
            if (in_array($co, ['zamknout', 'odemknout'], true)) {
                \MiroCMS\Core\Hledani::indexuj($this->db, (int) $clanek['idc']); // zamčený článek se hledá jen podle titulku a perexu
            }
            $pocet++;
        }

        return $this->zpet($pocet === 0 ? 'Neoznačili jste žádný článek.' : t('Upraveno článků: %d.', $pocet), typ: $pocet === 0 ? 'chyba' : 'ok');
    }

    private function pridejStitek(int $idc, string $nazev): void
    {
        $seo = slugify($nazev, 100);
        $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]) ?? $this->db->insert('stitky', ['nazev' => $nazev, 'seo_link' => $seo]);
        $this->db->run('INSERT IGNORE INTO {clanky_stitky} (idc, ids) VALUES (?, ?)', [$idc, (int) $ids]);
    }

    protected function akceSmaz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $presunuto = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $clanek = $this->nacti((int) $id);
            if ($clanek === null || ($clanek['visible'] && !$this->app->auth()->smiVydavat())) {
                continue;
            }
            // koš: článek zmizí z webu i z výpisů, ale 30 dní ho jde obnovit; vrátí se jako koncept, nikdy sám nevyjde
            $presunuto += $this->db->update('clanky', [
                'smazano' => date('Y-m-d H:i:s'), 'smazal' => $this->app->auth()->id(), 'smazano_vydany' => (int) $clanek['visible'],
                'visible' => 0, 'priority' => 0, 'zamek_kdo' => null,
            ], ['idc' => $clanek['idc']]);
            \MiroCMS\Admin\Protokol::zapis($this->app, 'clanky', 'do koše', mb_substr($clanek['titulek'], 0, 80));
        }

        return $this->zpet(t('Do koše přesunuto článků: %d. Obnovit je jde 30 dní (Články → Koš).', $presunuto), typ: $presunuto > 0 ? 'ok' : 'chyba');
    }

    /** Obnovení z koše: článek se vrátí jako koncept (ne vydaný), redakce ho vydá sama. */
    protected function akceObnov(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $obnoveno = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $clanek = $this->nacti((int) $id, true);
            if ($clanek === null) {
                continue;
            }
            $obnoveno += $this->db->update('clanky', ['smazano' => null, 'smazal' => null, 'smazano_vydany' => 0, 'stav_redakce' => ''], ['idc' => $clanek['idc']]);
            \MiroCMS\Admin\Protokol::zapis($this->app, 'clanky', 'obnovení z koše', mb_substr($clanek['titulek'], 0, 80));
        }

        return $this->zpet(t('Obnoveno článků: %d. Vrátily se jako koncepty.', $obnoveno), '', ['stav' => 'kos'], $obnoveno > 0 ? 'ok' : 'chyba');
    }

    /** Smazání natrvalo z koše (vydaný článek jen ten, kdo smí vydávat). */
    protected function akceSmazNatrvalo(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $smazano = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $clanek = $this->nacti((int) $id, true);
            if ($clanek === null || ($clanek['smazano_vydany'] && !$this->app->auth()->smiVydavat())) {
                continue;
            }
            $smazano += $this->db->delete('clanky', ['idc' => $clanek['idc']]);
            \MiroCMS\Admin\Protokol::zapis($this->app, 'clanky', 'smazání natrvalo', mb_substr($clanek['titulek'], 0, 80));
        }

        return $this->zpet(t('Natrvalo smazáno článků: %d.', $smazano), '', ['stav' => 'kos'], $smazano > 0 ? 'ok' : 'chyba');
    }

    /** Koš se vysypává sám: články starší 30 dní se smažou natrvalo (volá Admin\Kernel při vstupu do administrace). */
    public static function vysypKos(\MiroCMS\Core\Db $db): int
    {
        return $db->run('DELETE FROM {clanky} WHERE smazano < NOW() - INTERVAL 30 DAY')->rowCount();
    }

    /**
     * @param array<string, mixed> $clanek
     * @param array<string, string> $chyby
     */
    /** Strom rubrik, do kterých smí přihlášený psát (uživatel omezený na rubriky vidí jen ty své). */
    private function nabidkaRubrik(): array
    {
        $povolene = $this->app->auth()->povoleneRubriky();

        return array_values(array_filter(Rubriky::strom($this->db), static fn (array $r): bool => $povolene === null || in_array((int) $r['idt'], $povolene, true)));
    }

    private function formular(array $clanek, array $chyby = []): Response
    {
        $auth = $this->app->auth();
        $povoleni = $auth->spravovaniAutori();
        $autori = $povoleni === null
            ? $this->db->pairs('SELECT idu, IF(jmeno = \'\', user, jmeno) FROM {user} ORDER BY 2')
            : $this->db->pairs('SELECT idu, IF(jmeno = \'\', user, jmeno) FROM {user} WHERE idu IN (' . implode(',', $povoleni) . ') ORDER BY 2');

        return $this->view('formular', $clanek['idc'] ? 'Úprava článku' : 'Nový článek', [
            'clanek' => $clanek,
            'chyby' => $chyby,
            'rubriky' => $this->nabidkaRubrik(),
            'autori' => $autori,
            'sablony' => $this->db->pairs('SELECT ids, nazev_cla_sab FROM {cla_sab} ORDER BY ids'),
            'sablonySoubory' => $this->db->pairs('SELECT ids, soubor_cla_sab FROM {cla_sab} ORDER BY ids'),
            'smiVydavat' => $auth->smiVydavat(),
            'ctenari' => \MiroCMS\Core\Rozsireni::je($this->app->settings(), 'ctenari'),
            'konceptServer' => $this->request->isPost() ? null : $this->db->one('SELECT cas, data FROM {clanky_koncepty} WHERE kdo = ? AND idc = ?', [$auth->id(), (int) $clanek['idc']]),
            'vsichniAutori' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {user} WHERE blokovat = 0 ORDER BY 2"),
            'spoluautori' => $this->request->isPost() ? array_map(intval(...), $this->request->postList('spoluautori')) : array_map(intval(...), array_column($this->db->all('SELECT idu FROM {clanky_autori} WHERE idc = ?', [(int) $clanek['idc']]), 'idu')),
            'viceLidi' => (int) $this->db->value('SELECT COUNT(*) FROM {user} WHERE blokovat = 0') > 1, // jednočlenná redakce nepotřebuje poznámky pro redakci
            'jazykyWebu' => \MiroCMS\Core\Jazyk::dalsi($this->app->settings()) !== [],
            // u článku ve výchozím jazyce: do kterých jazyků jde přeložit a které překlady už existují (jazyk => číslo článku)
            'jazykyPrekladu' => $clanek['idc'] && ($clanek['jazyk'] ?? '') === '' ? \MiroCMS\Core\Jazyk::dalsi($this->app->settings()) : [],
            'preklady' => $clanek['idc'] ? array_map(intval(...), $this->db->pairs("SELECT jazyk, idc FROM {clanky} WHERE preklad_z = ? AND jazyk <> ''", [(int) $clanek['idc']])) : [],
            'original' => empty($clanek['preklad_z']) ? '' : (string) $this->db->value('SELECT seo_link FROM {clanky} WHERE idc = ?', [$clanek['preklad_z']]),
            'asistent' => (new \MiroCMS\Core\Asistent($this->app->settings()))->pripraven(),
            'serialy' => $this->db->pairs('SELECT ids, nazev_skup FROM {skup_cl} ORDER BY nazev_skup'),
            'stitky' => $this->request->isPost() ? $this->request->post('stitky') : implode(', ', array_column(
                $this->db->all('SELECT s.nazev FROM {stitky} s JOIN {clanky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [(int) $clanek['idc']]),
                'nazev',
            )),
            'vsechnyStitky' => array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 500'), 'nazev'),
            'revize' => $this->db->all(
                "SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm
                 FROM {clanky_revize} r LEFT JOIN {user} u ON u.idu = r.kdo WHERE r.idc = ? ORDER BY r.idr DESC",
                [(int) $clanek['idc']],
            ),
        ]);
    }

    /** Seriál: vybraný, nebo nově založený podle názvu. */
    private function serial(int $ids, string $novy): ?int
    {
        $novy = mb_substr($novy, 0, 150);
        if ($novy !== '') {
            $existujici = $this->db->value('SELECT ids FROM {skup_cl} WHERE nazev_skup = ?', [$novy]);

            return $existujici !== null ? (int) $existujici : $this->db->insert('skup_cl', ['nazev_skup' => $novy]);
        }

        return $this->db->value('SELECT ids FROM {skup_cl} WHERE ids = ?', [$ids]) !== null ? $ids : null;
    }

    /** Uloží předchozí podobu článku; drží se posledních 20 verzí. */
    private function ulozRevizi(array $puvodni): void
    {
        $this->db->insert('clanky_revize', [
            'idc' => $puvodni['idc'], 'datum' => $puvodni['zmeneno'] ?? $puvodni['datum'], 'kdo' => $this->app->auth()->id(),
            'titulek' => $puvodni['titulek'], 'uvod' => $puvodni['uvod'], 'text' => $puvodni['text'],
        ]);
        $hranice = $this->db->value('SELECT idr FROM {clanky_revize} WHERE idc = ? ORDER BY idr DESC LIMIT 1 OFFSET 20', [$puvodni['idc']]);
        if ($hranice !== null) {
            $this->db->run('DELETE FROM {clanky_revize} WHERE idc = ? AND idr <= ?', [$puvodni['idc'], $hranice]);
        }
    }

    /** Štítky zapsané čárkami; neznámé se založí. */
    private function ulozStitky(int $idc, string $vstup): void
    {
        $this->db->delete('clanky_stitky', ['idc' => $idc]);
        $nazvy = array_unique(array_filter(array_map(fn (string $n): string => mb_substr(trim($n), 0, 80), explode(',', $vstup))));
        foreach (array_slice($nazvy, 0, 20) as $nazev) {
            $seo = slugify($nazev, 90);
            $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
            $ids = $ids !== null ? (int) $ids : $this->db->insert('stitky', ['nazev' => $nazev, 'seo_link' => $seo]);
            $this->db->run('INSERT IGNORE INTO {clanky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        }
    }

    /** Načte článek, jen pokud ho přihlášený uživatel smí spravovat. Článek v koši jen s $zKose (obnovení, smazání natrvalo). */
    private function nacti(int $id, bool $zKose = false): ?array
    {
        $clanek = $this->db->one('SELECT * FROM {clanky} WHERE idc = ? AND smazano IS ' . ($zKose ? 'NOT NULL' : 'NULL'), [$id]);
        $autori = $this->app->auth()->spravovaniAutori();
        $rubriky = $this->app->auth()->povoleneRubriky();
        if ($clanek === null || ($autori !== null && !in_array((int) $clanek['autor'], $autori, true)) || ($rubriky !== null && !in_array((int) $clanek['tema'], $rubriky, true))) {
            return null;
        }

        return $clanek;
    }

    private function volnySeoLink(string $seo, int $idc): string
    {
        $kandidat = $seo;
        for ($i = 2; $this->db->value('SELECT idc FROM {clanky} WHERE seo_link = ? AND idc <> ?', [$kandidat, $idc]) !== null; $i++) {
            $kandidat = $seo . '-' . $i;
        }

        return $kandidat;
    }

    /** Hodnota z <input type="datetime-local"> -> DATETIME; prázdné nebo neplatné = null. */
    private static function datumZFormulare(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16));

        return $dt === false ? null : $dt->format('Y-m-d H:i:00');
    }
}
