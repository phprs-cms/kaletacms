<?php

declare(strict_types=1);

namespace MiroCMS\Admin\Moduly;

use MiroCMS\Admin\Modul;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;

/**
 * Příjmy: jedno místo, kde vydavatel vidí, z čeho web žije - předplatné, dobrovolná podpora, reklama a newsletter.
 * Žádná nová logika: každá karta říká, jestli je zdroj zapnutý, ukáže jedno číslo a vede tam, kde se nastavuje.
 */
final class Prijmy extends Modul
{
    public const string IDENT = 'prijmy';
    public const string NAZEV = 'Příjmy';
    public const string SKUPINA = 'Čtenáři';
    public const string IKONA = 'b-srdce';
    public const bool JEN_ADMIN = true;

    protected function akceVypis(): Response
    {
        $web = $this->app->settings();
        $je = static fn (string $rozsireni): bool => Rozsireni::je($web, $rozsireni);
        $url = fn (string $dotaz): string => $this->app->url('admin.php?' . $dotaz);
        $stripe = \MiroCMS\Core\Stripe::nastaveno($web);
        // součet přijatých plateb za 30 dní, zvlášť pro každou měnu: "1 490,00 CZK, 20,00 EUR"
        $platby = implode(', ', array_map(
            static fn (array $p): string => cislo(\MiroCMS\Core\Stripe::castka((int) $p['soucet'], $p['mena']), 2) . ' ' . strtoupper($p['mena']),
            $this->db->all('SELECT mena, SUM(castka) AS soucet FROM {platby} WHERE vytvoreno > NOW() - INTERVAL 30 DAY GROUP BY mena ORDER BY mena'),
        ));
        $podpora = (int) $this->db->value("SELECT COUNT(*) FROM {bloky} WHERE sys_funkce = 'pod' AND zobrazit = 1");

        $karty = [
            [
                'nazev' => 'Předplatné', 'zapnuto' => $je('ctenari'),
                'cislo' => $je('ctenari') ? (int) $this->db->value('SELECT COUNT(*) FROM {ctenari} WHERE predplatne_do >= CURDATE()') : 0,
                'popisek' => 'čtenářů s platným předplatným',
                'text' => $stripe
                    ? 'Zamčené články čtou jen předplatitelé. Předplatné si čtenáři platí kartou přes Stripe a prodlužuje se samo; ručně ho můžete zapsat komukoli dál.'
                    : 'Zamčené články čtou jen předplatitelé. Předplatné zapisujete ručně u čtenáře, nebo si ho čtenáři platí sami přes Stripe (Nastavení → Čtenáři a platby); kde ho čtenář získá, říká adresa tamtéž.',
                'odkaz' => $je('ctenari') ? [$url('modul=ctenari'), 'Čtenáři'] : [$url('modul=rozsireni'), 'Zapnout v Rozšířeních'],
                'poznamka' => match (true) {
                    !$je('ctenari') => '',
                    $stripe || $platby !== '' => t('Platí přes Stripe: %d.', (int) $this->db->value("SELECT COUNT(*) FROM {ctenari} WHERE stripe_predplatne IS NOT NULL AND predplatne_stav IN ('aktivni', 'konci', 'nezaplaceno')"))
                        . ' ' . ($platby !== '' ? t('Platby za posledních 30 dní: %s.', $platby) : t('Za posledních 30 dní žádná platba.')),
                    $web->get('predplatne_url') === '' => t('Není vyplněno, kde čtenář předplatné získá.'),
                    default => '',
                },
            ],
            [
                'nazev' => 'Dobrovolná podpora', 'zapnuto' => $podpora > 0,
                'cislo' => $podpora, 'popisek' => 'bloků „Podpořte nás“ na webu',
                'text' => 'Krátká výzva s tlačítkem na platbu nebo na stránku s číslem účtu. Blok přidáte ve vizuálním editoru rozvržení.',
                'odkaz' => [$url('modul=bloky'), 'Bloky a rozvržení'], 'poznamka' => '',
            ],
            [
                'nazev' => 'Reklama', 'zapnuto' => $je('reklama'),
                'cislo' => $je('reklama') ? (int) $this->db->value('SELECT COALESCE(SUM(zobrazeni), 0) FROM {reklama} WHERE aktivni = 1') : 0,
                'popisek' => 'zobrazení aktivních reklam',
                'text' => 'Vlastní reklamní pozice s cílením na rubriky, časovými kampaněmi a výkazem zobrazení a prokliků.',
                'odkaz' => $je('reklama') ? [$url('modul=reklama'), 'Reklama'] : [$url('modul=rozsireni'), 'Zapnout v Rozšířeních'],
                'poznamka' => $je('reklama') ? t('Aktivních reklam: %d, prokliků: %d.', (int) $this->db->value('SELECT COUNT(*) FROM {reklama} WHERE aktivni = 1'), (int) $this->db->value('SELECT COALESCE(SUM(kliky), 0) FROM {reklama} WHERE aktivni = 1')) : '',
            ],
            [
                'nazev' => 'Newsletter', 'zapnuto' => $je('newsletter'),
                'cislo' => $je('newsletter') ? (int) $this->db->value('SELECT COUNT(*) FROM {odberatele} WHERE potvrzen = 1') : 0,
                'popisek' => 'potvrzených odběratelů',
                'text' => 'Sám peníze nepřináší, ale vrací čtenáře na web – a s nimi předplatné, podporu i zobrazení reklam.',
                'odkaz' => $je('newsletter') ? [$url('modul=newsletter'), 'Newsletter'] : [$url('modul=rozsireni'), 'Zapnout v Rozšířeních'],
                'poznamka' => '',
            ],
        ];

        return $this->view('vypis', 'Příjmy', ['karty' => $karty]);
    }
}
