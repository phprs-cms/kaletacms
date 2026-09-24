<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Response;

/**
 * Novinky a blog firmy (v databázi tabulka ka_novinky, kategorie = ka_kategorie).
 *
 * Pravidla:
 *  - autor vidí a upravuje jen své novinky a nesmí vydávat,
 *  - editor a správce vidí všechny novinky a vydávají je,
 *  - vydanou novinku smí měnit jen ten, kdo smí vydávat.
 */
final class Novinky extends Modul
{
    public const string IDENT = 'novinky';
    public const string NAZEV = 'Novinky';
    public const string SKUPINA = 'Obsah';
    public const string IKONA = 'clanek';

    private const int NA_STRANKU = 20;

    protected function akceVypis(): Response
    {
        $auth = $this->app->auth();
        $where = ['1 = 1'];
        $params = [];
        if (($autori = $auth->spravovaniAutori()) !== null) {
            $where[] = 'c.autor IN (' . implode(',', $autori) . ')';
        }
        if (($tema = $this->request->getInt('tema')) > 0) {
            $where[] = 'c.tema = ?';
            $params[] = $tema;
        }
        // jazyková verze: výchozí jazyk webu je v sloupci uložený jako ''
        $s = $this->app->settings();
        $jazykyWebu = ($dalsi = \Kaleta\Core\Jazyk::dalsi($s)) === [] ? [] : [\Kaleta\Core\Jazyk::vychozi($s), ...$dalsi];
        $jazyk = in_array($this->request->get('jazyk'), $jazykyWebu, true) ? $this->request->get('jazyk') : '';
        if ($jazyk !== '') {
            $where[] = 'c.jazyk = ?';
            $params[] = \Kaleta\Core\Jazyk::sloupec($s, $jazyk);
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
            'koncepty' => 'c.visible = 0',
        ];
        if (isset($podminkyStavu[$stav])) {
            $where[] = $podminkyStavu[$stav];
        }
        $cond = implode(' AND ', $where);

        $celkem = (int) $this->db->value("SELECT COUNT(*) FROM {novinky} c WHERE {$cond}", $params);
        $strana = max(1, $this->request->getInt('strana', 1));
        $novinky = $this->db->all(
            "SELECT c.idc, c.seo_link, c.titulek, c.datum, c.visible, c.visit, c.smazano,
                    t.nazev AS tema_jm, u.jmeno AS autor_jm, u.user AS autor_login
             FROM {novinky} c
             JOIN {kategorie} t ON t.idt = c.tema
             LEFT JOIN {uzivatele} u ON u.idu = c.autor
             WHERE {$cond}
             ORDER BY " . ($vKosi ? 'c.smazano DESC' : 'c.datum DESC') . ", c.idc DESC
             LIMIT ? OFFSET ?",
            [...$params, self::NA_STRANKU, ($strana - 1) * self::NA_STRANKU],
        );

        return $this->view('vypis', 'Novinky', [
            'novinky' => $novinky,
            'celkem' => $celkem,
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / self::NA_STRANKU)),
            'kategorie' => Kategorie::seznam($this->db),
            'filtr' => ['tema' => $tema, 'jazyk' => $jazyk, 'hledat' => $hledat, 'stav' => isset($podminkyStavu[$stav]) || $vKosi ? $stav : ''],
            'vKosi' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} c WHERE c.smazano IS NOT NULL' . $auth->articleScope('c.')),
            'jazykyWebu' => $jazykyWebu,
        ]);
    }

    protected function akceNovy(): Response
    {
        if (Kategorie::seznam($this->db) === []) {
            return $this->chyba('Nejprve založte alespoň jednu kategorii (Novinky → Kategorie).');
        }

        return $this->formular($this->vychozi());
    }

    /** Hodnoty nové novinky; doplňují se jimi i pole, která při neúspěšné validaci ve formuláři chybí. */
    private function vychozi(): array
    {
        return [
            'idc' => 0, 'seo_link' => '', 'titulek' => '', 'uvod' => '', 'text' => '', 'obrazek' => '', 'obrazek_popis' => '', 'obrazek_autor' => '',
            'tema' => (int) (Kategorie::seznam($this->db)[0]['idt'] ?? 0), 'autor' => $this->app->auth()->id(), 'datum' => date('Y-m-d H:i:s'),
            'visible' => 0, 't_slova' => '', 'seo_titulek' => '', 'seo_popis' => '', 'noindex' => 0, 'preklad_z' => null, 'faq' => '', 'jazyk' => '',
        ];
    }

    /** Kopie novinky jako koncept (i se štítky) – rychlý začátek podobné novinky. */
    protected function akceDuplikuj(): Response
    {
        $novinka = $this->request->isPost() ? $this->nacti($this->request->postInt('idc')) : null;
        if ($novinka === null) {
            return $this->zpet();
        }
        $kopie = array_intersect_key($novinka, array_flip(['uvod', 'text', 'obrazek', 'obrazek_popis', 'obrazek_autor', 'tema', 't_slova', 'seo_popis', 'noindex', 'faq', 'jazyk']));
        $seo = mb_substr($novinka['seo_link'] . '-kopie', 0, 150);
        for ($i = 2, $zaklad = $seo; $this->db->value('SELECT 1 FROM {novinky} WHERE seo_link = ?', [$seo]) !== null; $i++) {
            $seo = $zaklad . '-' . $i;
        }
        $id = $this->db->insert('novinky', $kopie + ['titulek' => mb_substr(t('%s (kopie)', $novinka['titulek']), 0, 255), 'seo_link' => $seo, 'visible' => 0,
            'datum' => date('Y-m-d H:i:s'), 'autor' => $this->app->auth()->id(), 'zmeneno' => date('Y-m-d H:i:s')]);
        $this->db->run('INSERT INTO {novinky_stitky} (idc, ids) SELECT ?, ids FROM {novinky_stitky} WHERE idc = ?', [$id, $novinka['idc']]);
        \Kaleta\Core\Hledani::indexuj($this->db, $id);

        return $this->zpet('Kopie novinky je uložená jako koncept.', 'edit', ['id' => $id]);
    }

    protected function akceEdit(): Response
    {
        $novinka = $this->nacti($this->request->getInt('id'));

        return $novinka === null ? $this->chyba('Novinka neexistuje nebo k ní nemáte přístup.', 404) : $this->formular($novinka);
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
                return $this->chyba('Novinka neexistuje nebo k ní nemáte přístup.', 404);
            }
            if ($puvodni['visible'] && !$auth->smiVydavat()) {
                return $this->chyba('Vydanou novinku může upravit jen editor nebo správce.', 403);
            }
        }

        $data = [
            'titulek' => $r->post('titulek'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('titulek'), 150),
            'uvod' => $r->post('uvod'),
            'text' => $r->post('text'),
            'obrazek' => $r->post('obrazek'),
            'obrazek_popis' => mb_substr(trim($r->post('obrazek_popis')), 0, 300),
            'obrazek_autor' => mb_substr(trim($r->post('obrazek_autor')), 0, 120),
            'tema' => $r->postInt('tema'),
            'autor' => $r->postInt('autor'),
            'datum' => self::datumZFormulare($r->post('datum')) ?? date('Y-m-d H:i:s'),
            'visible' => (int) ($r->post('stav') === 'vydany' && $auth->smiVydavat()),
            't_slova' => $r->post('t_slova'),
            'seo_titulek' => mb_substr($r->post('seo_titulek'), 0, 255),
            'seo_popis' => mb_substr($r->post('seo_popis'), 0, 320),
            'noindex' => (int) $r->postBool('noindex'),
            'faq' => $r->post('faq'),
            'zmeneno' => date('Y-m-d H:i:s'),
        ];

        $chyby = [];
        if ($data['titulek'] === '') {
            $chyby['titulek'] = 'Vyplňte titulek.';
        }
        if ($this->db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$data['tema']]) === null) {
            $chyby['tema'] = 'Vyberte kategorii.';
        }
        $povoleniAutori = $auth->spravovaniAutori();
        if ($povoleniAutori !== null && !in_array($data['autor'], $povoleniAutori, true)) {
            $data['autor'] = $auth->id();
        }
        if ($this->db->value('SELECT idu FROM {uzivatele} WHERE idu = ?', [$data['autor']]) === null) {
            $chyby['autor'] = 'Vyberte autora.';
        }
        if ($chyby !== []) {
            return $this->formular(['idc' => $id] + $data + ($puvodni ?? $this->vychozi()), $chyby);
        }
        // jazyková verze se přebírá z kategorie; překlad se propojuje s novinkou ve výchozím jazyce (adresa nebo číslo)
        $data['jazyk'] = (string) $this->db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$data['tema']]);
        $original = trim($r->post('preklad_z'));
        $data['preklad_z'] = $original === '' || $data['jazyk'] === '' ? null
            : ($this->db->value("SELECT idc FROM {novinky} WHERE (idc = ? OR seo_link = ?) AND jazyk = '' AND idc <> ?", [(int) $original, basename((string) parse_url($original, PHP_URL_PATH)), $id]) ?: null);

        if ($r->postBool('oznacit_aktualizaci') && $data['visible']) {
            $data['aktualizovano'] = date('Y-m-d H:i:s');
        }
        $data['seo_link'] = $this->volnySeoLink($data['seo_link'], $id);
        if ($id > 0) {
            if ([$puvodni['titulek'], $puvodni['uvod'], $puvodni['text']] !== [$data['titulek'], $data['uvod'], $data['text']]) {
                $this->ulozRevizi($puvodni);
            }
            $this->db->update('novinky', $data, ['idc' => $id]);
            if ($puvodni['seo_link'] !== $data['seo_link'] && $puvodni['visible']) {
                // vydaná novinka změnila adresu: stará se přesměruje, aby odkazy a vyhledávače nepřišly o stránku
                Presmerovani::pridej($this->db, 'novinky/' . $puvodni['seo_link'], 'novinky/' . $data['seo_link']);
            }
        } else {
            $id = $this->db->insert('novinky', $data);
        }

        Galerie::zapisPouziti($this->db, $id, $data['obrazek'], $data['uvod'], $data['text']);
        \Kaleta\Core\Hledani::indexuj($this->db, $id);
        // uložená novinka ruší rozepsaný stav na serveru (u nové je veden pod číslem 0)
        $this->db->run('DELETE FROM {novinky_koncepty} WHERE kdo = ? AND idc IN (0, ?)', [$auth->id(), $id]);
        $this->ulozStitky($id, $r->post('stitky'));
        // nově vydaná novinka se oznámí (webhook, IndexNow); naplánovaná počká na svůj čas - viz Core\Oznameni
        \Kaleta\Core\Oznameni::zpracuj($this->app);
        if ($data['visible'] && !empty($puvodni['visible']) && !$data['noindex'] && strtotime($data['datum']) <= time()) {
            (new \Kaleta\Front\Seo($this->app))->indexNow($this->app->urlNovinky($data['seo_link'], $data['jazyk']));
        }

        $hlaska = $auth->smiVydavat() ? 'Novinka byla uložena.' : 'Novinka byla uložena. Na webu se objeví, až ji vydá editor.';

        return $r->post('po_ulozeni') === 'zustat' ? $this->zpet($hlaska, 'edit', ['id' => $id]) : $this->zpet($hlaska);
    }

    /**
     * Uložení z úpravy „přímo na webu“ (views/front/upravit.php): jen titulek, perex a text. Platí stejná pravidla jako
     * u běžného uložení - oprávnění přes nacti(), vydaná novinka jen s právem vydávat, revize, hledání, použití obrázků.
     */
    protected function akceUlozText(): Response
    {
        $r = $this->request;
        $novinka = $r->isPost() ? $this->nacti($r->postInt('id')) : null;
        if ($novinka === null || ($novinka['visible'] && !$this->app->auth()->smiVydavat())) {
            return $this->zpetNaWeb($r->post('zpet'));
        }
        $data = ['titulek' => mb_substr($r->post('titulek'), 0, 255), 'uvod' => $r->post('uvod'), 'text' => $r->post('text')];
        // nevydaná novinka je na webu vidět jen v náhledu
        $nahled = $novinka['visible'] && strtotime((string) $novinka['datum']) <= time() ? '' : 'nahled=1';
        if ($data['titulek'] === '') {
            return $this->zpetNaWeb($r->post('zpet'), '?' . ($nahled !== '' ? $nahled . '&' : '') . 'upravit=text&chyba=1');
        }
        if ([$novinka['titulek'], $novinka['uvod'], $novinka['text']] !== array_values($data)) {
            $this->ulozRevizi($novinka);
        }
        $this->db->update('novinky', $data + ['zmeneno' => date('Y-m-d H:i:s')], ['idc' => $novinka['idc']]);
        Galerie::zapisPouziti($this->db, (int) $novinka['idc'], (string) $novinka['obrazek'], $data['uvod'], $data['text']);
        \Kaleta\Core\Hledani::indexuj($this->db, (int) $novinka['idc']);
        \Kaleta\Admin\Protokol::zapis($this->app, 'novinky', 'úprava přímo na webu', mb_substr($data['titulek'], 0, 80));
        if ($novinka['visible'] && !$novinka['noindex'] && strtotime((string) $novinka['datum']) <= time()) {
            (new \Kaleta\Front\Seo($this->app))->indexNow($this->app->urlNovinky($novinka['seo_link'], $novinka['jazyk']));
        }

        return $this->zpetNaWeb($r->post('zpet'), $nahled !== '' ? '?' . $nahled : '');
    }

    /**
     * Průběžné ukládání rozepsané novinky na server (image/editor.js). Neukládá novinku - jen stav formuláře
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
            $this->db->delete('novinky_koncepty', ['kdo' => $ja, 'idc' => $idc]);

            return Response::json(['ok' => true, 'smazano' => true]);
        }
        $this->db->run('INSERT INTO {novinky_koncepty} (kdo, idc, cas, data) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE cas = NOW(), data = VALUES(data)', [$ja, $idc, $data]);
        if (random_int(1, 40) === 1) {
            $this->db->run('DELETE FROM {novinky_koncepty} WHERE cas < NOW() - INTERVAL 30 DAY');
        }

        return Response::json(['ok' => true]);
    }

    /** Hledání novinek podle titulku pro dialog odkazu v editoru a pro paletu příkazů (?uprava=1). */
    protected function akceHledejJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['clanky' => []]);
        }
        $uprava = $this->request->get('uprava') === '1';
        $novinky = $this->db->all(
            'SELECT idc, titulek, seo_link, jazyk, visible AND datum <= NOW() AS vydany FROM {novinky} WHERE smazano IS NULL AND titulek LIKE ?'
                . ($uprava ? $this->app->auth()->articleScope() : '') . ' ORDER BY datum DESC LIMIT 8',
            ['%' . addcslashes($q, '%_\\') . '%'],
        );

        return Response::json(['clanky' => array_map(fn (array $c): array => [
            'titulek' => $c['titulek'], 'vydany' => (bool) $c['vydany'],
            'url' => $uprava ? $this->url('edit', ['id' => $c['idc']]) : $this->app->url(($c['jazyk'] !== '' ? $c['jazyk'] . '/' : '') . 'novinky/' . $c['seo_link']),
        ], $novinky)]);
    }

    /**
     * AI asistent: návrh k rozepsané novince (titulky, perex, SEO popis, štítky, korektura, popis obrázku).
     * Pracuje s textem z formuláře, nic neukládá - o použití návrhu rozhoduje člověk.
     */
    protected function akceAsistent(): Response
    {
        $asistent = new \Kaleta\Core\Asistent($this->app->settings());
        if (!$this->request->isPost() || !$asistent->pripraven()) {
            return Response::json(['chyba' => t('AI asistent není zapnutý nebo chybí klíč (nabídka Rozšíření).')], 400);
        }
        // pojistka proti nechtěné útratě: nejvýš 60 dotazů za hodinu na uživatele
        if ($this->prilisDotazu()) {
            return Response::json(['chyba' => t('Za poslední hodinu jste asistenta použili 60×. Zkuste to prosím později.')], 429);
        }
        $ukol = $this->request->post('ukol');
        $obrazek = null;
        if ($ukol === 'alt') {
            // jen soubory z media/: cesta se skládá z ověřených částí adresy
            $obrazek = preg_match('#media/(\d{4}/\d{2}/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif))$#', (string) parse_url($this->request->post('obrazek'), PHP_URL_PATH), $m) ? KALETA_ROOT . '/media/' . $m[1] : null;
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
        \Kaleta\Admin\Protokol::zapis($this->app, 'asistent', $ukol, mb_substr($this->request->post('titulek'), 0, 80));

        return Response::json($vysledek);
    }

    /**
     * „Přeložit asistentem“: z uložené verze novinky ve výchozím jazyce založí koncept v kategorii cílového jazyka,
     * propojený s originálem. Překlad vždy čeká na přečtení člověkem – nikdy se nevydává sám.
     */
    protected function akcePreloz(): Response
    {
        $novinka = $this->request->isPost() ? $this->nacti($this->request->postInt('idc')) : null;
        if ($novinka === null) {
            return $this->zpet('Novinku nejdřív uložte, pak ji půjde přeložit.', typ: 'chyba');
        }
        $zpetNaNovinku = fn (string $hlaska): Response => $this->zpet($hlaska, 'edit', ['id' => $novinka['idc']], typ: 'chyba');
        $jazyk = $this->request->post('prelozit_do');
        $asistent = new \Kaleta\Core\Asistent($this->app->settings());
        if (!$asistent->pripraven()) {
            return $zpetNaNovinku('AI asistent není zapnutý nebo chybí klíč (nabídka Rozšíření).');
        }
        if ($novinka['jazyk'] !== '' || !in_array($jazyk, \Kaleta\Core\Jazyk::dalsi($this->app->settings()), true)) {
            return $zpetNaNovinku('Přeložit jde jen novinka ve výchozím jazyce, a to do některé z dalších jazykových verzí webu.');
        }
        if (($hotova = $this->db->value('SELECT idc FROM {novinky} WHERE preklad_z = ? AND jazyk = ?', [$novinka['idc'], $jazyk])) !== null) {
            return $this->zpet('Překlad do tohoto jazyka už existuje – tady je.', 'edit', ['id' => (int) $hotova]);
        }
        // cílová kategorie: protějšek kategorie originálu, jinak první kategorie daného jazyka
        $kategorie = $this->db->value('SELECT idt FROM {kategorie} WHERE jazyk = ? ORDER BY (preklad_z <=> ?) DESC, hodnost DESC, idt LIMIT 1', [$jazyk, $novinka['tema']]);
        if ($kategorie === null) {
            return $zpetNaNovinku('V cílovém jazyce zatím není žádná kategorie. Založte ji v Novinky → Kategorie (pole Jazyková verze).');
        }
        if ($this->prilisDotazu()) {
            return $zpetNaNovinku('Za poslední hodinu jste asistenta použili 60×. Zkuste to prosím později.');
        }

        set_time_limit(600); // dlouhý text se překládá po dávkách
        $prosta = ['titulek', 'seo_titulek', 'seo_popis', 'faq', 't_slova'];
        try {
            $preklad = $asistent->preloz(array_map(strval(...), array_intersect_key($novinka, array_flip([...$prosta, 'uvod', 'text']))), $jazyk, $prosta);
        } catch (\RuntimeException $e) {
            return $zpetNaNovinku(t($e->getMessage()));
        }
        \Kaleta\Admin\Protokol::zapis($this->app, 'asistent', 'preklad-' . $jazyk, mb_substr($novinka['titulek'], 0, 80));

        // koncept přebírá z originálu vše, co se nepřekládá (obrázek, autora…); počitadla ne
        $data = array_intersect_key($novinka, array_flip(['obrazek', 'autor', 'noindex'])) + [
            'tema' => (int) $kategorie, 'jazyk' => $jazyk, 'preklad_z' => $novinka['idc'], 'visible' => 0, 'datum' => date('Y-m-d H:i:s'),
        ];
        foreach ($preklad as $pole => $hodnota) {
            $data[$pole] = $pole === 'titulek' ? mb_substr($hodnota, 0, 255) : $hodnota;
        }
        $data['seo_link'] = $this->volnySeoLink(slugify($data['titulek'], 100), 0);
        $id = $this->db->insert('novinky', $data);
        Galerie::zapisPouziti($this->db, $id, (string) $data['obrazek'], $data['uvod'], $data['text']);
        $this->db->run('INSERT INTO {novinky_stitky} (idc, ids) SELECT ?, ids FROM {novinky_stitky} WHERE idc = ?', [$id, $novinka['idc']]);
        \Kaleta\Core\Hledani::indexuj($this->db, $id);

        return $this->zpet('Překlad je založený jako koncept. Než ho vydáte, přečtěte ho – asistent může chybovat ve jménech, číslech a odborných výrazech.', 'edit', ['id' => $id]);
    }

    private function prilisDotazu(): bool
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM {protokol} WHERE kdo = ? AND modul = 'asistent' AND cas > NOW() - INTERVAL 1 HOUR", [$this->app->auth()->id()]) >= 60;
    }

    /** Načte do editoru starší verzi novinky; uloží se až odesláním formuláře. */
    protected function akceRevize(): Response
    {
        $novinka = $this->nacti($this->request->getInt('id'));
        $revize = $novinka === null ? null : $this->db->one('SELECT * FROM {novinky_revize} WHERE idr = ? AND idc = ?', [$this->request->getInt('idr'), $novinka['idc']]);
        if ($revize === null) {
            return $this->chyba('Verze novinky neexistuje.', 404);
        }
        $this->app->session->flash('info', t('V editoru je verze z %s. Platit začne, až novinku uložíte.', datum($revize['datum'], true)));

        return $this->formular(['titulek' => $revize['titulek'], 'uvod' => $revize['uvod'], 'text' => $revize['text']] + $novinka);
    }

    /** Co se od uložené verze změnilo: porovnání starší verze se současným zněním. */
    protected function akcePorovnej(): Response
    {
        $novinka = $this->nacti($this->request->getInt('id'));
        $revize = $novinka === null ? null : $this->db->one(
            "SELECT r.*, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm FROM {novinky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.idr = ? AND r.idc = ?",
            [$this->request->getInt('idr'), $novinka['idc'] ?? 0],
        );
        if ($revize === null) {
            return $this->chyba('Verze novinky neexistuje.', 404);
        }

        return $this->view('porovnani', 'Porovnání verzí', [
            'novinka' => $novinka,
            'revize' => $revize,
            'titulek' => \Kaleta\Core\Rozdil::html((string) $revize['titulek'], (string) $novinka['titulek']),
            'uvod' => \Kaleta\Core\Rozdil::html((string) $revize['uvod'], (string) $novinka['uvod']),
            'text' => \Kaleta\Core\Rozdil::html((string) $revize['text'], (string) $novinka['text']),
        ]);
    }

    /** Nefunkční odkazy nalezené kontrolou na pozadí (Core\Odkazy). */
    protected function akceOdkazy(): Response
    {
        if ($this->request->isPost()) {
            // "zkontrolovat znovu": novinka se zařadí na začátek fronty
            $this->db->update('novinky', ['odkazy_cas' => null], ['idc' => $this->request->postInt('idc')]);
            $this->db->delete('odkazy_vadne', ['idc' => $this->request->postInt('idc')]);

            return $this->zpet('Novinka se zkontroluje znovu během několika minut.', 'odkazy');
        }

        return $this->view('odkazy', 'Nefunkční odkazy', [
            'odkazy' => $this->db->all('SELECT o.*, c.titulek FROM {odkazy_vadne} o JOIN {novinky} c ON c.idc = o.idc WHERE 1 = 1' . $this->app->auth()->articleScope('c.') . ' ORDER BY o.cas DESC LIMIT 300'),
            'zkontrolovano' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} WHERE odkazy_cas IS NOT NULL'),
            'celkem' => (int) $this->db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW()'),
            'zapnuto' => $this->app->settings()->bool('kontrola_odkazu'),
        ]);
    }

    protected function akceSmaz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $presunuto = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $novinka = $this->nacti((int) $id);
            if ($novinka === null || ($novinka['visible'] && !$this->app->auth()->smiVydavat())) {
                continue;
            }
            // koš: novinka zmizí z webu i z výpisů, ale 30 dní ji jde obnovit; vrátí se jako koncept, nikdy sama nevyjde
            $presunuto += $this->db->update('novinky', ['smazano' => date('Y-m-d H:i:s'), 'visible' => 0], ['idc' => $novinka['idc']]);
            \Kaleta\Admin\Protokol::zapis($this->app, 'novinky', 'do koše', mb_substr($novinka['titulek'], 0, 80));
        }

        return $this->zpet(t('Do koše přesunuto novinek: %d. Obnovit je jde 30 dní (Novinky → Koš).', $presunuto), typ: $presunuto > 0 ? 'ok' : 'chyba');
    }

    /** Obnovení z koše: novinka se vrátí jako koncept (ne vydaná). */
    protected function akceObnov(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $obnoveno = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $novinka = $this->nacti((int) $id, true);
            if ($novinka === null) {
                continue;
            }
            $obnoveno += $this->db->update('novinky', ['smazano' => null], ['idc' => $novinka['idc']]);
            \Kaleta\Admin\Protokol::zapis($this->app, 'novinky', 'obnovení z koše', mb_substr($novinka['titulek'], 0, 80));
        }

        return $this->zpet(t('Obnoveno novinek: %d. Vrátily se jako koncepty.', $obnoveno), '', ['stav' => 'kos'], $obnoveno > 0 ? 'ok' : 'chyba');
    }

    /** Smazání natrvalo z koše (jen ten, kdo smí vydávat). */
    protected function akceSmazNatrvalo(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->smiVydavat()) {
            return $this->zpet();
        }
        $smazano = 0;
        foreach ($this->request->postList('smaz') as $id) {
            $novinka = $this->nacti((int) $id, true);
            if ($novinka !== null) {
                $smazano += $this->db->delete('novinky', ['idc' => $novinka['idc']]);
                \Kaleta\Admin\Protokol::zapis($this->app, 'novinky', 'smazání natrvalo', mb_substr($novinka['titulek'], 0, 80));
            }
        }

        return $this->zpet(t('Natrvalo smazáno novinek: %d.', $smazano), '', ['stav' => 'kos'], $smazano > 0 ? 'ok' : 'chyba');
    }

    /** Koš se vysypává sám: novinky starší 30 dní se smažou natrvalo (volá Admin\Kernel při vstupu do administrace). */
    public static function vysypKos(\Kaleta\Core\Db $db): int
    {
        return $db->run('DELETE FROM {novinky} WHERE smazano < NOW() - INTERVAL 30 DAY')->rowCount();
    }

    /**
     * @param array<string, mixed> $novinka
     * @param array<string, string> $chyby
     */
    private function formular(array $novinka, array $chyby = []): Response
    {
        $auth = $this->app->auth();
        $povoleni = $auth->spravovaniAutori();
        $autori = $povoleni === null
            ? $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2")
            : $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE idu IN (" . implode(',', $povoleni) . ') ORDER BY 2');

        return $this->view('formular', $novinka['idc'] ? 'Úprava novinky' : 'Nová novinka', [
            'novinka' => $novinka,
            'chyby' => $chyby,
            'kategorie' => Kategorie::seznam($this->db),
            'autori' => $autori,
            'smiVydavat' => $auth->smiVydavat(),
            'konceptServer' => $this->request->isPost() ? null : $this->db->one('SELECT cas, data FROM {novinky_koncepty} WHERE kdo = ? AND idc = ?', [$auth->id(), (int) $novinka['idc']]),
            'jazykyWebu' => \Kaleta\Core\Jazyk::dalsi($this->app->settings()) !== [],
            // u novinky ve výchozím jazyce: do kterých jazyků jde přeložit a které překlady už existují (jazyk => číslo)
            'jazykyPrekladu' => $novinka['idc'] && ($novinka['jazyk'] ?? '') === '' ? \Kaleta\Core\Jazyk::dalsi($this->app->settings()) : [],
            'preklady' => $novinka['idc'] ? array_map(intval(...), $this->db->pairs("SELECT jazyk, idc FROM {novinky} WHERE preklad_z = ? AND jazyk <> ''", [(int) $novinka['idc']])) : [],
            'original' => empty($novinka['preklad_z']) ? '' : (string) $this->db->value('SELECT seo_link FROM {novinky} WHERE idc = ?', [$novinka['preklad_z']]),
            'asistent' => (new \Kaleta\Core\Asistent($this->app->settings()))->pripraven(),
            'stitky' => $this->request->isPost() ? $this->request->post('stitky') : implode(', ', array_column(
                $this->db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [(int) $novinka['idc']]),
                'nazev',
            )),
            'vsechnyStitky' => array_column($this->db->all('SELECT nazev FROM {stitky} ORDER BY nazev LIMIT 500'), 'nazev'),
            'revize' => $this->db->all(
                "SELECT r.idr, r.datum, r.titulek, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo_jm
                 FROM {novinky_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.idc = ? ORDER BY r.idr DESC",
                [(int) $novinka['idc']],
            ),
        ]);
    }

    /** Uloží předchozí podobu novinky; drží se posledních 20 verzí. */
    private function ulozRevizi(array $puvodni): void
    {
        $this->db->insert('novinky_revize', [
            'idc' => $puvodni['idc'], 'datum' => $puvodni['zmeneno'] ?? $puvodni['datum'], 'kdo' => $this->app->auth()->id(),
            'titulek' => $puvodni['titulek'], 'uvod' => $puvodni['uvod'], 'text' => $puvodni['text'],
        ]);
        $hranice = $this->db->value('SELECT idr FROM {novinky_revize} WHERE idc = ? ORDER BY idr DESC LIMIT 1 OFFSET 20', [$puvodni['idc']]);
        if ($hranice !== null) {
            $this->db->run('DELETE FROM {novinky_revize} WHERE idc = ? AND idr <= ?', [$puvodni['idc'], $hranice]);
        }
    }

    /** Štítky zapsané čárkami; neznámé se založí. */
    private function ulozStitky(int $idc, string $vstup): void
    {
        $this->db->delete('novinky_stitky', ['idc' => $idc]);
        $nazvy = array_unique(array_filter(array_map(fn (string $n): string => mb_substr(trim($n), 0, 80), explode(',', $vstup))));
        foreach (array_slice($nazvy, 0, 20) as $nazev) {
            $seo = slugify($nazev, 90);
            $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
            $ids = $ids !== null ? (int) $ids : $this->db->insert('stitky', ['nazev' => $nazev, 'seo_link' => $seo]);
            $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        }
    }

    /** Načte novinku, jen pokud ji přihlášený smí spravovat. Novinku v koši jen s $zKose (obnovení, smazání natrvalo). */
    private function nacti(int $id, bool $zKose = false): ?array
    {
        $novinka = $this->db->one('SELECT * FROM {novinky} WHERE idc = ? AND smazano IS ' . ($zKose ? 'NOT NULL' : 'NULL'), [$id]);
        $autori = $this->app->auth()->spravovaniAutori();

        return $novinka === null || ($autori !== null && !in_array((int) $novinka['autor'], $autori, true)) ? null : $novinka;
    }

    private function volnySeoLink(string $seo, int $idc): string
    {
        $kandidat = $seo;
        for ($i = 2; $this->db->value('SELECT idc FROM {novinky} WHERE seo_link = ? AND idc <> ?', [$kandidat, $idc]) !== null; $i++) {
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
