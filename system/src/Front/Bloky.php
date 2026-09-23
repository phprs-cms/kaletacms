<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Admin\Moduly\Bloky as Nastaveni;
use MiroCMS\Admin\Moduly\Rubriky;
use MiroCMS\Core\App;
use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Rozsireni;
use MiroCMS\Core\View;

/**
 * Zóny stránky a jejich bloky. Každý blok se vykreslí šablonou "blok" z layoutu;
 * obsah systémových bloků dodávají šablony blok_<zkratka> (blok_rub, blok_nov...).
 */
final class Bloky
{
    public function __construct(private readonly App $app, private readonly View $view)
    {
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $rubrikyMenu = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $strankyMenu = null;

    /** Strom zobrazených rubrik jazykové verze - potřebuje ho navigace šablony i blok Rubriky, dotaz stačí jednou za požadavek. */
    public function rubrikyMenu(): array
    {
        return $this->rubrikyMenu ??= Rubriky::strom($this->app->db(), true, Jazyk::sloupecWebu());
    }

    /** Stránky do menu - navigace šablony i blok Stránky, dotaz jednou za požadavek. */
    public function strankyMenu(): array
    {
        return $this->strankyMenu ??= $this->app->db()->all('SELECT titulek, seo_link FROM {stranky} WHERE zobrazit = 1 AND v_menu = 1 AND jazyk = ? ORDER BY poradi, titulek', [Jazyk::sloupecWebu()]);
    }

    /** Rubrika právě skládané stránky - bloky s reklamou podle ní vybírají cílené bannery. */
    private ?int $rubrika = null;

    public function rozvrzeni(): string
    {
        $rozvrzeni = $this->app->settings()->get('rozvrzeni');

        return isset(Nastaveni::ROZVRZENI[$rozvrzeni]) ? $rozvrzeni : 'tri';
    }

    /**
     * @param int|null $rubrika rubrika právě zobrazené stránky (výpis rubriky nebo článek) - kvůli blokům "jen v rubrice"
     * @param bool $upravit režim vizuálního editoru: bloky a zóny dostanou značky, ukážou se i skryté bloky a prázdné zóny
     * @return array<string, string> zóna => HTML bloků; vždy všechny klíče, prázdná zóna = ''
     */
    public function zony(bool $hlavniStranka, ?int $rubrika = null, bool $upravit = false): array
    {
        $this->rubrika = $rubrika;
        $existujici = Nastaveni::ROZVRZENI[$this->rozvrzeni()][2];
        $html = array_fill_keys(array_keys(Nastaveni::ZONY), '');

        foreach ($this->app->db()->all('SELECT * FROM {bloky} ORDER BY hodnost DESC, idb') as $blok) {
            // proč se blok na této stránce čtenáři neukáže (prázdné = ukáže se)
            $duvod = match (true) {
                !$blok['zobrazit'] => 'blok je skrytý',
                (int) $blok['zobrazit_kde'] === 1 && !$hlavniStranka => 'jen na hlavní stránce',
                (int) $blok['zobrazit_kde'] === 2 && $hlavniStranka => 'všude kromě hlavní stránky',
                $blok['jen_rubrika'] !== null && (int) $blok['jen_rubrika'] !== $rubrika => 'jen ve vybrané rubrice',
                $blok['jen_jazyk'] !== '' && $blok['jen_jazyk'] !== (Jazyk::sloupecWebu() === '' ? 'vy' : Jazyk::sloupecWebu()) => 'jen v jiné jazykové verzi',
                default => '',
            };
            if ($duvod !== '' && !$upravit) {
                continue;
            }
            $obsah = $duvod !== '' ? '' : ($blok['sys_funkce'] === '' ? $blok['obsah'] : $this->systemovy($blok['sys_funkce'], (string) $blok['data_sys'], (string) $blok['obsah']));
            if (trim($obsah) === '' && !$upravit) {
                continue;
            }
            // blok ze zóny, kterou zvolené rozvržení nemá, se ukáže pod obsahem
            $zona = in_array($blok['zona'], $existujici, true) ? $blok['zona'] : 'pod';
            $blokHtml = trim($obsah) === ''
                ? '<div class="mc-duch"><strong>' . e($blok['nazev']) . '</strong><br>' . e($duvod !== '' ? $this->ta('Teď se nezobrazuje: %s.', $this->ta($duvod)) : $this->ta('Zatím nemá co zobrazit.')) . '</div>'
                : $this->view->render('blok', ['nadpis' => $blok['nazev'], 'obsah' => $obsah, 'typ' => (int) $blok['typ'], 'sys' => $blok['sys_funkce'], 'zona' => $zona]);
            if ($upravit) {
                $blokHtml = '<div class="mc-blok" data-blok="' . (int) $blok['idb'] . '" data-nazev="' . e($blok['nazev']) . '" draggable="true">' . $blokHtml . '</div>';
            }
            // "jen na mobilu / jen na počítači" řeší obal s třídou; styl je v hlavičce stránky (Front\Seo), layout ho nemusí znát
            $html[$zona] .= $blok['zarizeni'] === 'vse' || $upravit ? $blokHtml : '<div class="jen-' . e($blok['zarizeni']) . '">' . $blokHtml . '</div>';
        }
        if ($upravit) {
            foreach ($existujici as $zona) {
                // display: contents - obal nesmí rozbít mřížku, do které layout bloky skládá
                $html[$zona] = '<div class="mc-zona" data-zona="' . e($zona) . '" data-nazev="' . e(Nastaveni::ZONY[$zona]) . '">' . $html[$zona]
                    . '<button type="button" class="mc-pridat" data-zona="' . e($zona) . '">+ Přidat blok <small>' . e(Nastaveni::ZONY[$zona]) . '</small></button></div>';
            }
        }

        return $html;
    }

    /** Text pro vizuální editor: v jazyce administrace přihlášeného, ne v jazyce zobrazené verze webu. */
    private function ta(string $text, string ...$hodnoty): string
    {
        $kod = (string) ($this->app->auth()->user()['jazyk'] ?? '') ?: Jazyk::vychozi($this->app->settings());

        return Jazyk::docasne(isset(Jazyk::ADMINISTRACE[$kod]) ? $kod : 'cs', fn (): string => t($text, ...$hodnoty), 'admin-');
    }

    private function systemovy(string $zkratka, string $data, string $obsah): string
    {
        $rozsireni = ['nov' => 'novinky', 'ank' => 'ankety', 'rek' => 'reklama', 'nws' => 'newsletter', 'cte' => 'ctenari', 'psh' => 'push'][$zkratka] ?? '';
        if (!Rozsireni::je($this->app->settings(), $rozsireni)) {
            return '';
        }
        $url = $this->app->url(...);
        $db = $this->app->db();
        $web = $this->app->settings();
        $clanky = new Clanky($db, $web, $this->app->request->basePath());
        $pocet = max(1, min(50, (int) $data ?: 5));

        return match ($zkratka) {
            'rub' => $this->view->render('blok_rub', ['rubriky' => $this->rubrikyMenu(), 'url' => $url]),
            'nov' => $this->view->render('blok_nov', [
                'novinky' => $db->all('SELECT * FROM {news} WHERE datum <= NOW() AND jazyk = ? ORDER BY datum DESC, idn DESC LIMIT ?', [Jazyk::sloupecWebu(), $web->int('pocet_novinek')]),
            ]),
            'hle' => $this->view->render('blok_hle', ['url' => $url, 'q' => $this->app->request->get('q')]),
            'nej' => $this->view->render('blok_nej', ['clanky' => $clanky->nejctenejsi($pocet, \MiroCMS\Admin\Moduly\Bloky::obdobiNej($data)), 'url' => $url]),
            'ank' => (new Interakce($this->app, $this->view))->anketaHtml(),
            'rek' => (new Reklama($this->app))->html($data !== '' ? $data : 'sloupec', $this->rubrika),
            'cla' => (function () use ($clanky, $data, $url): string {
                [$idt, $kolik] = array_map(intval(...), explode(':', $data . ':5'));
                $seznam = $idt > 0 ? $clanky->zRubriky($idt, 1, max(1, min(20, $kolik)))[0] : $clanky->naHlavniStranku(1, max(1, min(20, $kolik)))[0];

                return $seznam === [] ? '' : $this->view->render('blok_cla', ['clanky' => $seznam, 'url' => $url]);
            })(),
            'otv' => ($otvirak = $clanky->naHlavniStranku(1, 1)[0][0] ?? null) === null ? '' : $this->view->render('blok_otv', ['clanek' => $otvirak, 'url' => $url]),
            'sti' => $this->view->render('blok_sti', ['url' => $url, 'stitky' => $db->all(
                'SELECT s.nazev, s.seo_link, COUNT(*) AS pocet FROM {stitky} s JOIN {clanky_stitky} cs ON cs.ids = s.ids JOIN {clanky} c ON c.idc = cs.idc
                 WHERE c.visible = 1 AND c.datum <= NOW() AND c.jazyk = ? GROUP BY s.ids, s.nazev, s.seo_link ORDER BY pocet DESC, s.nazev LIMIT ?',
                [Jazyk::sloupecWebu(), $pocet],
            )]),
            'arc' => $this->view->render('blok_arc', ['url' => $url, 'mesice' => $db->all(
                "SELECT DATE_FORMAT(datum, '%Y-%m') AS mesic, COUNT(*) AS pocet FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND jazyk = ? GROUP BY mesic ORDER BY mesic DESC LIMIT ?",
                [Jazyk::sloupecWebu(), $pocet],
            )]),
            'aut' => $this->view->render('blok_aut', ['url' => $url, 'autori' => $db->all(
                "SELECT u.idu, u.jmeno, COUNT(*) AS pocet FROM {user} u JOIN {clanky} c ON c.autor = u.idu
                 WHERE u.jmeno <> '' AND c.visible = 1 AND c.datum <= NOW() AND c.jazyk = ? GROUP BY u.idu, jmeno ORDER BY pocet DESC LIMIT ?",
                [Jazyk::sloupecWebu(), $pocet],
            )]),
            'men' => $this->view->render('blok_men', ['url' => $url, 'odkazy' => self::odkazy($obsah)]),
            'str' => $this->view->render('blok_men', ['url' => $url, 'odkazy' => array_map(
                fn (array $st): array => [$st['titulek'], $st['seo_link']],
                $this->strankyMenu(),
            )]),
            'soc' => $this->view->render('blok_men', ['url' => $url, 'odkazy' => array_values(array_filter(array_map(
                fn (string $klic, string $nazev): ?array => $web->get($klic) !== '' ? [$nazev, $web->get($klic)] : null,
                array_keys(\MiroCMS\Admin\Moduly\Konfigurace::SITE),
                \MiroCMS\Admin\Moduly\Konfigurace::SITE,
            )))]),
            'kon' => $web->get('email_webu') === '' && $web->get('text_paticky') === '' ? '' : '<p class="blok-kontakt">' . nl2br(e($web->get('text_paticky')))
                . ($web->get('email_webu') !== '' ? '<br><a href="mailto:' . e($web->get('email_webu')) . '">' . e($web->get('email_webu')) . '</a>' : '') . '</p>',
            'nws' => (new Newsletter($this->app, $this->view))->formularHtml(),
            // stránka může být z cache, proto blok nerozlišuje přihlášeného - /ctenar ukáže přihlášení, nebo účet
            'pod' => (function () use ($data, $obsah, $url): string {
                [$tlacitko, $adresa] = explode('|', $data . '|', 3);
                $text = trim(strip_tags($obsah)) !== '' ? '<p>' . nl2br(e(trim(strip_tags($obsah)))) . '</p>' : '<p>' . e(t('Děláme nezávislou žurnalistiku. Pokud vám naše práce dává smysl, podpořte ji.')) . '</p>';

                return '<div class="mc-podpora">' . $text . ($adresa === '' ? '' : '<p><a class="mc-tl" href="' . e(str_starts_with($adresa, '/') ? $url(ltrim($adresa, '/')) : $adresa) . '"'
                    . (str_starts_with($adresa, '/') ? '' : ' rel="noopener"') . '>♥ ' . e($tlacitko !== '' ? $tlacitko : t('Podpořit redakci')) . '</a></p>') . '</div>';
            })(),
            // tlačítko oživí image/web.js; v prohlížeči bez podpory oznámení zůstane blok skrytý
            'psh' => '<div class="mc-push" data-push hidden><p>' . e($obsah !== '' ? strip_tags($obsah) : t('Dáme vám vědět, když vyjde nový článek.')) . '</p>'
                . '<button type="button" class="mc-tl" data-push-tl>' . e(t('Zapnout oznámení')) . '</button><p class="mc-drobne" data-push-stav role="status"></p></div>',
            'cte' => '<p class="blok-ctenar"><a class="mc-tl" href="' . e($url('ctenar')) . '">' . e(t('Přihlášení / Můj účet')) . '</a></p>',
            default => '',
        };
    }

    /**
     * Řádky "text | adresa" z bloku Menu.
     *
     * @return list<array{0:string, 1:string}>
     */
    private static function odkazy(string $text): array
    {
        $odkazy = [];
        foreach (preg_split('/\R/', $text) ?: [] as $radek) {
            [$popisek, $adresa] = array_map(trim(...), explode('|', $radek, 2) + [1 => '']);
            if ($popisek !== '' && $adresa !== '' && !preg_match('#^\s*(javascript|data|vbscript):#i', $adresa)) {
                $odkazy[] = [$popisek, $adresa];
            }
        }

        return $odkazy;
    }
}
