<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;

/**
 * Úprava bloků. Stránka webu má pevné místo pro obsah a kolem něj zóny, do kterých se skládají bloky.
 * Které zóny existují, určuje zvolené rozvržení stránky; pořadí bloků se mění přetažením myší.
 * Běžný blok nese vlastní HTML, systémový vykresluje systém (rubriky, novinky, vyhledávání...).
 */
final class Bloky extends Modul
{
    public const string IDENT = 'bloky';
    public const string NAZEV = 'Bloky a rozvržení';
    public const string SKUPINA = 'Vzhled';
    public const string IKONA = 'bloky';

    public const array ZONY = [
        'hlavicka' => 'Hlavička', 'leva' => 'Levý sloupec', 'nad' => 'Nad obsahem',
        'pod' => 'Pod obsahem', 'prava' => 'Pravý sloupec', 'paticka' => 'Patička',
    ];

    /** Rozvržení stránky: název, popis a zóny, které v něm existují. */
    public const array ROZVRZENI = [
        'tri' => ['3 sloupce', 'Bloky vlevo i vpravo, obsah uprostřed.', ['hlavicka', 'leva', 'nad', 'pod', 'prava', 'paticka']],
        'dva' => ['2 sloupce', 'Obsah a vpravo úzký sloupec s bloky.', ['hlavicka', 'nad', 'pod', 'prava', 'paticka']],
        'jeden' => ['1 sloupec', 'Úzký sloupec pro pohodlné čtení, bloky pod obsahem.', ['hlavicka', 'nad', 'pod', 'paticka']],
        'plna' => ['Plná šířka', 'Obsah přes celou šířku stránky, bloky pod obsahem.', ['hlavicka', 'nad', 'pod', 'paticka']],
    ];

    /** Období bloku Nejčtenější (dny => popis); 0 = celkový počet přečtení. Uloženo v data_sys jako „počet:dny“. */
    public const array OBDOBI_NEJ = [7 => 'za posledních 7 dní', 30 => 'za posledních 30 dní', 365 => 'za poslední rok', 0 => 'za celou dobu'];

    /** Období z data_sys bloku Nejčtenější; bloky z doby před volbou období („5“) počítají posledních 30 dní. */
    public static function obdobiNej(string $data): int
    {
        $dni = explode(':', $data)[1] ?? '30';

        return isset(self::OBDOBI_NEJ[(int) $dni]) && ctype_digit($dni) ? (int) $dni : 30;
    }

    /** Kam se přesunou bloky ze zóny, která v novém rozvržení není. */
    private const array NAHRADNI_ZONA = ['dva' => ['leva' => 'prava'], 'jeden' => ['leva' => 'pod', 'prava' => 'pod'], 'plna' => ['leva' => 'pod', 'prava' => 'pod']];

    /** Typy bloků, které vykresluje systém: zkratka => název. Blok bez zkratky nese vlastní HTML. */
    public const array SYSTEMOVE = [
        'otv' => 'Otvírák – velká upoutávka na připnutý nebo nejnovější článek',
        'cla' => 'Články z rubriky',
        'nej' => 'Nejčtenější články',
        'rub' => 'Seznam rubrik',
        'sti' => 'Štítky',
        'arc' => 'Archiv po měsících',
        'aut' => 'Autoři',
        'men' => 'Menu – vlastní odkazy',
        'str' => 'Stránky (O nás, Kontakt…)',
        'hle' => 'Vyhledávání',
        'nov' => 'Novinky',
        'ank' => 'Anketa',
        'nws' => 'Newsletter – přihlášení k odběru',
        'cte' => 'Účet čtenáře – přihlášení a registrace',
        'psh' => 'Oznámení – upozornění na nové články v prohlížeči',
        'pod' => 'Podpořte nás – výzva k dobrovolnému příspěvku',
        'soc' => 'Sociální sítě',
        'kon' => 'Kontakt na redakci',
        'rek' => 'Reklama',
    ];

    /**
     * Nabídka bloků ve vizuálním editoru: skupina => [typ => [název, popis jednou větou, ikona]].
     * Typ "" je blok s vlastním textem.
     */
    public const array KATALOG = [
        'Články' => [
            'otv' => ['Otvírák', 'Velká upoutávka na připnutý nebo nejnovější článek.', 'b-otvirak'],
            'cla' => ['Články z rubriky', 'Seznam posledních článků z rubriky, kterou vyberete.', 'b-seznam'],
            'nej' => ['Nejčtenější', 'Žebříček článků podle přečtení za zvolené období.', 'b-nejctenejsi'],
            'sti' => ['Štítky', 'Nejpoužívanější štítky jako odkazy.', 'b-hash'],
            'arc' => ['Archiv', 'Články po měsících.', 'b-archiv'],
            'aut' => ['Autoři', 'Seznam autorů s počtem článků.', 'b-autori'],
        ],
        'Navigace' => [
            'rub' => ['Rubriky', 'Seznam rubrik webu.', 'rubriky'],
            'men' => ['Menu', 'Vlastní odkazy – na stránky webu i jinam.', 'b-menu'],
            'str' => ['Stránky', 'Odkazy na stránky jako O nás a Kontakt.', 'stranky'],
            'hle' => ['Vyhledávání', 'Pole pro hledání v článcích.', 'b-hledani'],
        ],
        'Čtenáři a redakce' => [
            'nov' => ['Novinky', 'Krátké zprávy redakce.', 'novinky'],
            'ank' => ['Anketa', 'Aktuální anketa s hlasováním.', 'b-anketa'],
            'nws' => ['Newsletter', 'Formulář pro přihlášení k odběru novinek e-mailem.', 'newsletter'],
            'pod' => ['Podpořte nás', 'Krátká výzva a tlačítko na platbu nebo stránku s číslem účtu.', 'b-srdce'],
            'psh' => ['Oznámení', 'Tlačítko, kterým si čtenář zapne upozornění na nové články v prohlížeči.', 'b-zvonek'],
            'cte' => ['Účet čtenáře', 'Odkaz na přihlášení, registraci a účet čtenáře.', 'b-ucet'],
            'soc' => ['Sociální sítě', 'Odkazy na profily vyplněné v Nastavení.', 'b-site'],
            'kon' => ['Kontakt', 'E-mail redakce a text z patičky.', 'b-kontakt'],
        ],
        'Vlastní' => [
            '' => ['Text', 'Vlastní text, obrázek nebo vložený kód (video, mapa…).', 'b-text'],
            'rek' => ['Reklama', 'Pozice, na které se střídají bannery z Reklamního systému.', 'reklama'],
        ],
    ];

    public const array ZARIZENI = ['vse' => 'všude', 'mobil' => 'jen na mobilu', 'pocitac' => 'jen na počítači a tabletu'];

    /** Vzhled bloku; v databázi číslo 1-5 jako v MiroCMS 2 (rs_bloky.typ). */
    public const array VZHLEDY = [1 => 'Běžný', 2 => 'Podbarvený', 3 => 'Zvýrazněný nadpis', 4 => 'V rámečku', 5 => 'Bez nadpisu'];

    public const array KDE = [0 => 'na všech stránkách', 1 => 'jen na hlavní stránce', 2 => 'všude kromě hlavní stránky'];

    /** Výchozí akce modulu vede rovnou do vizuálního editoru; schéma stránky zůstává jako záložní cesta bez JavaScriptu. */
    protected function akceVypis(): Response
    {
        return $this->request->get('schema') === '1' ? $this->schema() : $this->akceVizualne();
    }

    private function schema(): Response
    {
        $rozvrzeni = $this->rozvrzeni();
        $bloky = array_fill_keys(self::ROZVRZENI[$rozvrzeni][2], []);
        foreach ($this->db->all('SELECT * FROM {bloky} ORDER BY hodnost DESC, idb') as $blok) {
            $zona = isset($bloky[$blok['zona']]) ? $blok['zona'] : 'pod';
            $bloky[$zona][] = $blok;
        }

        return $this->view('vypis', 'Bloky a rozvržení', ['rozvrzeni' => $rozvrzeni, 'bloky' => $bloky]);
    }

    /**
     * Nastaví rozvržení a bloky ze zón, které v něm nejsou, přesune do nejbližší existující – jinak by z webu tiše zmizely.
     * Volá se i při přepnutí šablony (Vzhled, MCP), protože šablona si rozvržení nese s sebou.
     */
    public static function prepniRozvrzeni(\MiroCMS\Core\Db $db, \MiroCMS\Core\Settings $web, string $nove): void
    {
        if (!isset(self::ROZVRZENI[$nove])) {
            return;
        }
        foreach (self::NAHRADNI_ZONA[$nove] ?? [] as $z => $do) {
            // přesunuté bloky se zařadí za ty, které v cílové zóně už jsou
            $nejniz = (int) $db->value('SELECT COALESCE(MIN(hodnost), 1000) FROM {bloky} WHERE zona = ?', [$do]);
            $db->run('UPDATE {bloky} SET zona = ?, hodnost = GREATEST(0, ? - 10 - (1000 - LEAST(hodnost, 1000)) DIV 10) WHERE zona = ?', [$do, $nejniz, $z]);
        }
        $web->set('rozvrzeni', $nove);
    }

    /** Změna rozvržení stránky; bloky ze zrušených zón se přesunou do nejbližší existující. */
    protected function akceRozvrzeni(): Response
    {
        $nove = $this->request->post('rozvrzeni');
        if (!$this->request->isPost() || !isset(self::ROZVRZENI[$nove])) {
            return $this->zpet();
        }
        self::prepniRozvrzeni($this->db, $this->app->settings(), $nove);

        return $this->zpet(t('Rozvržení stránky: %s.', t(self::ROZVRZENI[$nove][0])));
    }

    /** Uložení pořadí po přetažení: JSON {"zona": [idb, idb...], ...}. */
    protected function akcePoradi(): Response
    {
        $poradi = json_decode($this->request->post('poradi'), true);
        if (!$this->request->isPost() || !is_array($poradi)) {
            return Response::json(['ok' => false], 400);
        }
        $povolene = self::ROZVRZENI[$this->rozvrzeni()][2];
        $this->db->transaction(function () use ($poradi, $povolene): void {
            foreach ($poradi as $zona => $ids) {
                if (!in_array($zona, $povolene, true) || !is_array($ids)) {
                    continue;
                }
                foreach (array_values($ids) as $i => $idb) {
                    $this->db->update('bloky', ['zona' => $zona, 'hodnost' => 1000 - $i * 10], ['idb' => (int) $idb]);
                }
            }
        });

        return Response::json(['ok' => true]);
    }

    protected function akceNovy(): Response
    {
        $sys = $this->request->get('sys');
        $zona = $this->request->get('zona');

        return $this->formular([
            'idb' => 0, 'nazev' => self::SYSTEMOVE[$sys] ?? '', 'obsah' => '', 'typ' => 1,
            'sys_funkce' => isset(self::SYSTEMOVE[$sys]) ? $sys : '', 'zobrazit' => 1, 'zobrazit_kde' => 0, 'data_sys' => $sys === 'rek' ? 'sloupec' : '', 'jen_rubrika' => null, 'zarizeni' => 'vse', 'jen_jazyk' => '',
            'zona' => isset(self::ZONY[$zona]) ? $zona : 'prava',
        ]);
    }

    protected function akceEdit(): Response
    {
        $blok = $this->db->one('SELECT * FROM {bloky} WHERE idb = ?', [$this->request->getInt('id')]);

        return $blok === null ? $this->chyba('Blok neexistuje.', 404) : $this->formular($blok);
    }

    protected function akceUloz(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $id = $this->request->postInt('idb');
        $data = $this->dataZPozadavku();
        if ($data['nazev'] === '') {
            return $this->formular(['idb' => $id] + $data, ['nazev' => 'Vyplňte název bloku.']);
        }

        if ($id > 0) {
            $this->db->update('bloky', $data, ['idb' => $id]);
        } else {
            // nový blok se zařadí na konec své zóny
            $nejniz = (int) $this->db->value('SELECT COALESCE(MIN(hodnost), 1010) FROM {bloky} WHERE zona = ?', [$data['zona']]);
            $this->db->insert('bloky', $data + ['hodnost' => max(0, $nejniz - 10)]);
        }

        return $this->zpet('Blok byl uložen.');
    }

    /**
     * Hodnoty bloku z odeslaného formuláře (společné pro klasický formulář i vizuální editor).
     *
     * @return array<string, scalar|null>
     */
    private function dataZPozadavku(): array
    {
        $r = $this->request;
        $sys = $r->post('sys_funkce');
        $povolene = self::ROZVRZENI[$this->rozvrzeni()][2];
        $data = [
            'nazev' => $r->post('nazev'),
            'obsah' => $sys === 'men' ? $r->post('obsah_menu') : $r->post('obsah'),
            'typ' => isset(self::VZHLEDY[$r->postInt('typ')]) ? $r->postInt('typ') : 1,
            'sys_funkce' => isset(self::SYSTEMOVE[$sys]) ? $sys : '',
            'zobrazit' => (int) $r->postBool('zobrazit'),
            'zobrazit_kde' => isset(self::KDE[$r->postInt('zobrazit_kde')]) ? $r->postInt('zobrazit_kde') : 0,
            'zona' => in_array($r->post('zona'), $povolene, true) ? $r->post('zona') : end($povolene),
            'data_sys' => match ($sys) {
                'rek' => isset(Reklama::POZICE[$r->post('data_sys')]) ? $r->post('data_sys') : 'sloupec',
                // blok Podpořte nás: "text tlačítka|adresa" (platební odkaz, stránka s číslem účtu…)
                'pod' => mb_substr(str_replace('|', ' ', $r->post('pod_tlacitko')) . '|' . (preg_match('#^(https?://|/)[^\s"<>]*$#', $r->post('pod_adresa')) ? $r->post('pod_adresa') : ''), 0, 255),
                'cla' => $r->postInt('blok_rubrika') . ':' . max(1, min(20, $r->postInt('blok_pocet', 5))),
                'nej' => max(1, min(50, $r->postInt('blok_pocet', 5))) . ':' . (isset(self::OBDOBI_NEJ[$r->postInt('blok_obdobi', 30)]) ? $r->postInt('blok_obdobi', 30) : 30),
                'sti', 'aut', 'arc' => (string) max(1, min(50, $r->postInt('blok_pocet', 5))),
                default => '',
            },
            'jen_rubrika' => $this->db->value('SELECT idt FROM {topic} WHERE idt = ?', [$r->postInt('jen_rubrika')]) !== null ? $r->postInt('jen_rubrika') : null,
            'zarizeni' => isset(self::ZARIZENI[$r->post('zarizeni')]) ? $r->post('zarizeni') : 'vse',
            // '' = všechny jazykové verze, 'vy' = jen výchozí jazyk, jinak kód další verze
            'jen_jazyk' => in_array($r->post('jen_jazyk'), ['vy', ...\MiroCMS\Core\Jazyk::dalsi($this->app->settings())], true) ? $r->post('jen_jazyk') : '',
        ];

        return $data;
    }

    /** Výchozí akce: vizuální editor přímo ve stránce webu. */
    protected function akceVizualne(): Response
    {
        return Response::redirect($this->app->url('?upravit=1'));
    }

    /** Vizuální editor: přidá blok daného typu na konec zóny s rozumným výchozím nastavením. */
    protected function akceRychlePridat(): Response
    {
        $typ = $this->request->post('typ');
        $zona = $this->request->post('zona');
        $polozka = null;
        foreach (self::KATALOG as $skupina) {
            $polozka ??= $skupina[$typ] ?? null;
        }
        if (!$this->request->isPost() || $polozka === null || !in_array($zona, self::ROZVRZENI[$this->rozvrzeni()][2], true)) {
            return Response::json(['ok' => false], 400);
        }
        // nadpis bloku čtou čtenáři: dostane ho ve výchozím jazyce webu, ne v jazyce administrace toho, kdo blok přidává
        $vJazyceWebu = fn (string $text): string => \MiroCMS\Core\Jazyk::docasne(\MiroCMS\Core\Jazyk::vychozi($this->app->settings()), static fn (): string => t($text));
        $nejniz = (int) $this->db->value('SELECT COALESCE(MIN(hodnost), 1010) FROM {bloky} WHERE zona = ?', [$zona]);
        $idb = $this->db->insert('bloky', [
            'nazev' => $vJazyceWebu($polozka[0]), 'obsah' => $typ === '' ? '<p>' . $vJazyceWebu('Sem napište svůj text.') . '</p>' : '', 'sys_funkce' => $typ, 'zona' => $zona,
            'hodnost' => max(0, $nejniz - 10), 'data_sys' => ['cla' => '0:5', 'rek' => 'sloupec', 'nej' => '5:30', 'sti' => '15', 'arc' => '12', 'aut' => '10'][$typ] ?? '',
        ]);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'bloky', 'uloz', $polozka[0]);

        return Response::json(['ok' => true, 'idb' => $idb]);
    }

    /** Vizuální editor: nastavení jednoho bloku. */
    protected function akceNastaveniJson(): Response
    {
        $blok = $this->db->one('SELECT * FROM {bloky} WHERE idb = ?', [$this->request->getInt('id')]);

        return $blok === null ? Response::json(['ok' => false], 404) : Response::json(['ok' => true, 'blok' => $blok]);
    }

    /** Vizuální editor: uložení nastavení bloku. */
    protected function akceUlozJson(): Response
    {
        $id = $this->request->postInt('idb');
        if (!$this->request->isPost() || $this->db->value('SELECT idb FROM {bloky} WHERE idb = ?', [$id]) === null) {
            return Response::json(['ok' => false], 400);
        }
        $data = $this->dataZPozadavku();
        if ($data['nazev'] === '') {
            return Response::json(['ok' => false, 'chyba' => t('Vyplňte nadpis bloku.')], 422);
        }
        unset($data['zona']); // umístění se ve vizuálním editoru mění přetažením
        $this->db->update('bloky', $data, ['idb' => $id]);
        \MiroCMS\Admin\Protokol::zapis($this->app, 'bloky', 'uloz', (string) $data['nazev']);

        return Response::json(['ok' => true]);
    }

    protected function akceSmaz(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('bloky', ['idb' => $this->request->postInt('idb')]);
        }

        return $this->zpet('Blok byl smazán.');
    }

    private function rozvrzeni(): string
    {
        $rozvrzeni = $this->app->settings()->get('rozvrzeni');

        return isset(self::ROZVRZENI[$rozvrzeni]) ? $rozvrzeni : 'tri';
    }

    /**
     * @param array<string, mixed> $blok
     * @param array<string, string> $chyby
     */
    private function formular(array $blok, array $chyby = []): Response
    {
        return $this->view('formular', $blok['idb'] ? 'Úprava bloku' : 'Nový blok', [
            'blok' => $blok,
            'chyby' => $chyby,
            'zony' => array_intersect_key(self::ZONY, array_flip(self::ROZVRZENI[$this->rozvrzeni()][2])),
            'rubriky' => Rubriky::strom($this->db),
        ]);
    }
}
