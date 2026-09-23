<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Db;
use MiroCMS\Core\Response;
use MiroCMS\Core\Settings;
use MiroCMS\Core\Stripe;

/**
 * Webhook Stripe (POST /platba/stripe): jediné místo, kde se čtenáři zapisuje zaplacené předplatné.
 *
 * Ověřením je podpis zprávy tajemstvím webhooku - proto tu není Antispam ani cookie. Nejdřív podpis, potom teprve čtení obsahu.
 * Po platném podpisu se odpovídá 200 i na události, které web nezajímají (jinak je Stripe posílá znovu a nakonec webhook vypne).
 * Události mohou přijít vícekrát a v jiném pořadí, než vznikly: platbu hlídá unikátní číslo události, zbytek jsou zápisy stavu.
 */
final class Platby
{
    public const int MAX_TELO = 512 * 1024;

    public function __construct(private readonly Db $db, private readonly Settings $settings)
    {
    }

    public function webhook(string $telo, string $podpis): Response
    {
        $text = static fn (string $t, int $kod): Response => new Response($t . "\n", $kod, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        if (strlen($telo) > self::MAX_TELO) {
            return $text('Zprava je prilis velka.', 413);
        }
        if (!Stripe::overPodpis($telo, $podpis, $this->settings->get('stripe_webhook_tajemstvi'))) {
            return $text('Neplatny podpis.', 400);
        }
        $udalost = json_decode($telo, true);
        $objekt = is_array($udalost) ? ($udalost['data']['object'] ?? null) : null;
        $id = is_array($udalost) ? (string) ($udalost['id'] ?? '') : '';
        if (!is_array($objekt) || !preg_match('/^evt_[A-Za-z0-9_]{1,76}$/D', $id)) {
            return $text('Necitelna udalost.', 400);
        }
        match ((string) ($udalost['type'] ?? '')) {
            'checkout.session.completed' => $this->dokoncenaPlatba($objekt),
            'invoice.paid' => $this->zaplacenaFaktura($id, $objekt),
            'customer.subscription.updated', 'customer.subscription.deleted' => $this->zmenaPredplatneho($objekt, $udalost['type'] === 'customer.subscription.deleted'),
            default => null,
        };

        return $text('OK', 200);
    }

    /**
     * Zapíše čtenáři stav předplatného ze Stripe. Datum "do" se tu nemění - to prodlužuje jen zaplacená faktura,
     * takže zrušené předplatné doběhne do konce období, které už čtenář zaplatil.
     *
     * @param array<string, mixed> $ctenar řádek rs_ctenari
     * @param array<string, mixed> $predplatne objekt subscription
     */
    public function zapisStav(array $ctenar, array $predplatne, bool $smazano = false): void
    {
        $id = (string) ($predplatne['id'] ?? '');
        $stav = $smazano ? 'zruseno' : Stripe::stav($predplatne);
        $ulozene = (string) $ctenar['stripe_predplatne'];
        if ($id === '' || $stav === '' || ($id === $ulozene && $ctenar['predplatne_stav'] === 'zruseno')) {
            return; // zrušené předplatné už ve Stripe neožije: opožděná starší událost nesmí stav vrátit
        }
        if ($id !== $ulozene && ($stav === 'zruseno' || in_array($ctenar['predplatne_stav'], ['aktivni', 'konci', 'nezaplaceno'], true))) {
            return; // událost jiného (staršího) předplatného, než které čtenáři právě běží
        }
        $this->db->update('ctenari', ['stripe_predplatne' => $id, 'predplatne_stav' => $stav] + $this->zakaznik($predplatne), ['idct' => $ctenar['idct']]);
    }

    /** @param array<string, mixed> $sezeni objekt checkout.session */
    private function dokoncenaPlatba(array $sezeni): void
    {
        $predplatne = (string) ($sezeni['subscription'] ?? '');
        $ctenar = $this->ctenar('', (string) ($sezeni['client_reference_id'] ?? $sezeni['metadata']['idct'] ?? ''));
        if (($sezeni['mode'] ?? '') !== 'subscription' || $predplatne === '' || $ctenar === null) {
            return;
        }
        $data = $this->zakaznik($sezeni);
        if ($ctenar['stripe_predplatne'] !== $predplatne) {
            $data += ['stripe_predplatne' => mb_substr($predplatne, 0, 64), 'predplatne_stav' => 'aktivni'];
        }
        if ($data !== []) {
            $this->db->update('ctenari', $data, ['idct' => $ctenar['idct']]);
        }
    }

    /** @param array<string, mixed> $faktura objekt invoice */
    private function zaplacenaFaktura(string $udalost, array $faktura): void
    {
        // jen faktury předplatného; číslo předplatného je podle verze API přímo na faktuře, nebo v "parent"
        $podrobnosti = $faktura['parent']['subscription_details'] ?? $faktura['subscription_details'] ?? [];
        $konec = Stripe::konecObdobi($faktura);
        if ((string) ($faktura['subscription'] ?? $podrobnosti['subscription'] ?? '') === '' || $konec === 0) {
            return;
        }
        // Jen předplatné, které založil tento web: pozná se podle čísla čtenáře v metadatech (vkládá ho náš Checkout). Vydavatel může
        // přes stejný účet Stripe prodávat i něco jiného – cizí faktura téhož zákazníka nesmí čtenáři prodloužit přístup.
        $idct = (string) ($podrobnosti['metadata']['idct'] ?? $faktura['lines']['data'][0]['metadata']['idct'] ?? '');
        if ($idct === '') {
            return;
        }
        $ctenar = $this->ctenar((string) ($faktura['customer'] ?? ''), $idct);
        $castka = (int) ($faktura['amount_paid'] ?? 0);
        $this->db->transaction(function () use ($udalost, $faktura, $ctenar, $castka, $konec): void {
            if ($castka > 0) {
                // unikátní číslo události: stejná zpráva doručená podruhé nic nezapíše
                $nove = $this->db->run('INSERT IGNORE INTO {platby} (udalost, idct, castka, mena, typ, vytvoreno) VALUES (?, ?, ?, ?, ?, ?)',
                    [$udalost, $ctenar['idct'] ?? null, $castka, strtolower(substr((string) ($faktura['currency'] ?? ''), 0, 3)), 'predplatne', date('Y-m-d H:i:s')])->rowCount();
                if ($nove === 0) {
                    return;
                }
            }
            if ($ctenar !== null) {
                // nikdy nezkracovat: platí pozdější z obou dat (ruční zápis administrátora, dřív zaplacené období)
                $do = Stripe::predplatneDo($konec);
                $this->db->run('UPDATE {ctenari} SET predplatne_do = GREATEST(COALESCE(predplatne_do, ?), ?), stripe_zakaznik = COALESCE(stripe_zakaznik, ?) WHERE idct = ?',
                    [$do, $do, $this->zakaznik($faktura)['stripe_zakaznik'] ?? null, $ctenar['idct']]);
            }
        });
    }

    /** @param array<string, mixed> $predplatne objekt subscription */
    private function zmenaPredplatneho(array $predplatne, bool $smazano): void
    {
        $id = (string) ($predplatne['id'] ?? '');
        $idct = (string) ($predplatne['metadata']['idct'] ?? '');
        // předplatné, které web nezná, přijme jen s vlastním číslem čtenáře v metadatech (jiný prodej přes stejný účet Stripe se ignoruje)
        $ctenar = ($id !== '' ? $this->db->one('SELECT * FROM {ctenari} WHERE stripe_predplatne = ?', [$id]) : null)
            ?? ($idct !== '' ? $this->ctenar((string) ($predplatne['customer'] ?? ''), $idct) : null);
        if ($ctenar !== null) {
            $this->zapisStav($ctenar, $predplatne, $smazano);
        }
    }

    /**
     * Čtenář podle zákazníka Stripe, jinak podle čísla čtenáře, které web sám vložil do platby (client_reference_id, metadata).
     *
     * @return array<string, mixed>|null
     */
    private function ctenar(string $zakaznik, string $idct): ?array
    {
        $ctenar = $zakaznik !== '' ? $this->db->one('SELECT * FROM {ctenari} WHERE stripe_zakaznik = ? ORDER BY idct LIMIT 1', [$zakaznik]) : null;

        return $ctenar ?? (ctype_digit($idct) && strlen($idct) <= 10 ? $this->db->one('SELECT * FROM {ctenari} WHERE idct = ?', [(int) $idct]) : null);
    }

    /**
     * @param array<string, mixed> $objekt
     * @return array{stripe_zakaznik?: string}
     */
    private function zakaznik(array $objekt): array
    {
        $zakaznik = $objekt['customer'] ?? '';

        return is_string($zakaznik) && preg_match('/^cus_[A-Za-z0-9]{1,60}$/D', $zakaznik) ? ['stripe_zakaznik' => $zakaznik] : [];
    }
}
