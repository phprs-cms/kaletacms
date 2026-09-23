<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Platby předplatného přes Stripe - bez knihovny, jen tři volání API a ověření podpisu webhooku.
 *
 * Platba i správa karty probíhá na stránkách Stripe (Checkout a Customer Portal); MiroCMS nikdy nevidí číslo karty.
 * Ceny a produkty se zakládají v administraci Stripe, tady se jen odkazuje na jejich čísla (price_…).
 * Komu a do kdy předplatné platí, se zapisuje výhradně z ověřeného webhooku (Front\Platby), nikdy z prohlížeče.
 */
class Stripe
{
    /** Pevná verze API: tvar odpovědí se nezmění, dokud ji tady někdo vědomě nezvýší. */
    public const string VERZE_API = '2025-03-31.basil';
    public const int TOLERANCE = 300;

    /** Tvar hodnot v Nastavení (Moduly\Konfigurace). Tajný klíč smí být i omezený (rk_…). */
    public const string VZOR_KLIC = '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,250}$/D';
    public const string VZOR_WEBHOOK = '/^whsec_[A-Za-z0-9+\/=]{10,250}$/D';
    public const string VZOR_CENA = '/^(price_[A-Za-z0-9]{8,100})?$/D';

    /** Události, které web zpracovává - tytéž se zapínají u webhooku ve Stripe. */
    public const array UDALOSTI = ['checkout.session.completed', 'invoice.paid', 'customer.subscription.updated', 'customer.subscription.deleted'];

    /** Měny bez setin: částka ze Stripe je rovnou v celých jednotkách. */
    private const array BEZ_SETIN = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** Jazyky stránek Stripe, které odpovídají jazykům webu; cokoli jiného nechá Stripe na prohlížeči čtenáře. */
    private const array JAZYKY = ['cs', 'sk', 'en', 'de'];

    public function __construct(private readonly Settings $settings)
    {
    }

    /** Platby jsou nastavené: tajný klíč, tajemství webhooku a aspoň jedna cena. */
    public static function nastaveno(Settings $s): bool
    {
        return $s->get('stripe_tajny_klic') !== '' && $s->get('stripe_webhook_tajemstvi') !== '' && self::ceny($s) !== [];
    }

    /** @return array<string, string> nabízená období: mesic|rok => číslo ceny ve Stripe */
    public static function ceny(Settings $s): array
    {
        return array_filter(['mesic' => $s->get('stripe_cena_mesic'), 'rok' => $s->get('stripe_cena_rok')], static fn (string $c): bool => $c !== '' && preg_match(self::VZOR_CENA, $c) === 1);
    }

    /** @return array<string, string> nabízená období: mesic|rok => popis ceny u tlačítka („99 Kč měsíčně“; smí být prázdný) */
    public static function nabidka(Settings $s): array
    {
        $nabidka = [];
        foreach (array_keys(self::ceny($s)) as $obdobi) {
            $nabidka[$obdobi] = $s->get('stripe_cena_' . $obdobi . '_text');
        }

        return $nabidka;
    }

    /**
     * Ověření podpisu webhooku. Hlavička má tvar "t=<čas>,v1=<podpis>[,v1=<podpis>]", podpis je HMAC-SHA256
     * řetězce "<čas>.<tělo>" tajemstvím webhooku. Čas smí být nejvýš TOLERANCE vteřin od teď (ochrana proti přehrání).
     */
    public static function overPodpis(string $telo, string $hlavicka, string $tajemstvi, ?int $ted = null): bool
    {
        if ($tajemstvi === '' || strlen($hlavicka) > 1000) {
            return false;
        }
        $cas = null;
        $podpisy = [];
        foreach (explode(',', $hlavicka) as $cast) {
            [$klic, $hodnota] = explode('=', trim($cast), 2) + [1 => ''];
            if ($klic === 't' && $cas === null && ctype_digit($hodnota) && strlen($hodnota) <= 12) {
                $cas = (int) $hodnota;
            } elseif ($klic === 'v1' && preg_match('/^[a-f0-9]{64}$/', $hodnota)) {
                $podpisy[] = $hodnota;
            }
        }
        if ($cas === null || $podpisy === [] || abs(($ted ?? time()) - $cas) > self::TOLERANCE) {
            return false;
        }
        $ocekavany = hash_hmac('sha256', $cas . '.' . $telo, $tajemstvi);
        $plati = false;
        foreach ($podpisy as $podpis) {
            $plati = hash_equals($ocekavany, $podpis) || $plati; // projdou se vždy všechny, ať doba ověření nic neprozradí
        }

        return $plati;
    }

    /**
     * Konec zaplaceného období (unixový čas) z faktury nebo z předplatného; 0 = v objektu není.
     * Faktura ho nese na řádcích (lines.data[].period.end), předplatné podle verze API buď přímo, nebo na položkách.
     *
     * @param array<string, mixed> $objekt
     */
    public static function konecObdobi(array $objekt): int
    {
        $konec = (int) ($objekt['current_period_end'] ?? 0);
        foreach ([...(array) ($objekt['lines']['data'] ?? []), ...(array) ($objekt['items']['data'] ?? [])] as $radek) {
            if (is_array($radek)) {
                $konec = max($konec, (int) ($radek['period']['end'] ?? 0), (int) ($radek['current_period_end'] ?? 0));
            }
        }

        return $konec;
    }

    /** Do kdy platí předplatné zaplacené do $konec: den konce období a den navíc, než Stripe strhne další platbu. */
    public static function predplatneDo(int $konec): string
    {
        return date('Y-m-d', $konec + 86400);
    }

    /**
     * Stav předplatného pro sloupec rs_ctenari.predplatne_stav; '' = z tohoto stavu se nic nezapisuje.
     *
     * @param array<string, mixed> $predplatne objekt subscription ze Stripe
     */
    public static function stav(array $predplatne): string
    {
        return match ((string) ($predplatne['status'] ?? '')) {
            'active', 'trialing' => !empty($predplatne['cancel_at_period_end']) || !empty($predplatne['cancel_at']) ? 'konci' : 'aktivni',
            'past_due', 'unpaid', 'paused' => 'nezaplaceno',
            'canceled', 'incomplete_expired' => 'zruseno',
            default => '', // incomplete (první platba ještě běží) a neznámé stavy nic nemění
        };
    }

    /** Částka z nejmenších jednotek měny (haléře, centy) na celé; některé měny setiny nemají. */
    public static function castka(int $castka, string $mena): float
    {
        return in_array(strtolower($mena), self::BEZ_SETIN, true) ? (float) $castka : $castka / 100;
    }

    /**
     * Pole požadavku na založení platby (Checkout Session). Zvlášť kvůli testům - nic neodesílá.
     *
     * @param array{idct:int|string, email:string, stripe_zakaznik?:string|null} $ctenar
     * @return array<string, mixed>
     */
    public static function poleCheckout(string $cena, array $ctenar, string $navrat, string $jazyk): array
    {
        $spoj = str_contains($navrat, '?') ? '&' : '?';
        $zakaznik = (string) ($ctenar['stripe_zakaznik'] ?? '');

        return [
            'mode' => 'subscription',
            'line_items' => [['price' => $cena, 'quantity' => 1]],
            'client_reference_id' => (string) $ctenar['idct'],
            'success_url' => $navrat . $spoj . 'stav=zaplaceno',
            'cancel_url' => $navrat . $spoj . 'stav=platba-zrusena',
            'locale' => in_array($jazyk, self::JAZYKY, true) ? $jazyk : 'auto',
            'metadata' => ['idct' => (string) $ctenar['idct']],
            'subscription_data' => ['metadata' => ['idct' => (string) $ctenar['idct']]],
        ] + ($zakaznik !== '' ? ['customer' => $zakaznik] : ['customer_email' => $ctenar['email']]);
    }

    /**
     * Založí platbu předplatného a vrátí adresu platební stránky Stripe.
     *
     * @param array<string, mixed> $ctenar řádek rs_ctenari
     * @throws \RuntimeException
     */
    public function checkout(string $cena, array $ctenar, string $navrat, string $jazyk): string
    {
        return $this->adresa($this->zavolej('POST', '/v1/checkout/sessions', self::poleCheckout($cena, $ctenar, $navrat, $jazyk)));
    }

    /**
     * Adresa zákaznického portálu Stripe: změna karty, faktury, zrušení předplatného.
     *
     * @throws \RuntimeException
     */
    public function portal(string $zakaznik, string $navrat, string $jazyk): string
    {
        return $this->adresa($this->zavolej('POST', '/v1/billing_portal/sessions', [
            'customer' => $zakaznik, 'return_url' => $navrat, 'locale' => in_array($jazyk, self::JAZYKY, true) ? $jazyk : 'auto',
        ]));
    }

    /**
     * @return array<string, mixed> objekt subscription
     * @throws \RuntimeException
     */
    public function predplatne(string $id): array
    {
        if (!preg_match('/^sub_[A-Za-z0-9]{8,100}$/D', $id)) {
            throw new \RuntimeException('Stripe: neplatné číslo předplatného.');
        }

        return $this->zavolej('GET', '/v1/subscriptions/' . $id);
    }

    /** Řádek do záznamu chyb (Nastavení → Stav systému). Klíče se do něj nikdy nedostanou. */
    public static function zaloguj(string $zprava): void
    {
        $zprava = preg_replace('/\b(sk|rk|whsec)_[A-Za-z0-9_+\/=]+/', '$1_…', str_replace(["\r", "\n"], ' ', $zprava)) ?? '';
        @file_put_contents(MIROCMS_ROOT . '/storage/log/chyby.log', sprintf("[%s] Stripe: %s\n", date('c'), mb_substr($zprava, 0, 500)), FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $odpoved */
    private function adresa(array $odpoved): string
    {
        $adresa = (string) ($odpoved['url'] ?? '');
        // čtenáře posíláme jen na https adresu; při přesměrovaném API (konstanta v config.php, testy) rozhoduje správce serveru
        if (!preg_match('#^https://[^\s"<>]+$#', $adresa) && !(defined('MIROCMS_STRIPE_URL') && preg_match('#^http://[^\s"<>]+$#', $adresa))) {
            throw new \RuntimeException('odpověď neobsahuje adresu platební stránky');
        }

        return $adresa;
    }

    /**
     * Volání API Stripe. Chráněná kvůli testům, které ji nahrazují.
     *
     * @param array<string, mixed> $pole
     * @return array<string, mixed>
     * @throws \RuntimeException zpráva je určená do záznamu chyb, čtenáři se nevypisuje
     */
    protected function zavolej(string $metoda, string $cesta, array $pole = []): array
    {
        $klic = $this->settings->get('stripe_tajny_klic');
        if ($klic === '') {
            throw new \RuntimeException('chybí tajný klíč');
        }
        // adresu jde změnit jen konstantou v config.php (testy, firemní brána) – z administrace nikdy, šel by tudy odeslat klíč jinam
        $adresa = rtrim(defined('MIROCMS_STRIPE_URL') ? (string) constant('MIROCMS_STRIPE_URL') : 'https://api.stripe.com', '/') . $cesta;
        $hlavicky = ['Authorization: Bearer ' . $klic, 'Stripe-Version: ' . self::VERZE_API, 'Content-Type: application/x-www-form-urlencoded', 'User-Agent: MiroCMS/' . MIROCMS_VERSION];
        $telo = $metoda === 'POST' ? http_build_query($pole) : '';
        if (function_exists('curl_init')) {
            $ch = curl_init($adresa);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HTTPHEADER => $hlavicky, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ] + ($metoda === 'POST' ? [CURLOPT_POSTFIELDS => $telo] : []));
            $odpoved = curl_exec($ch);
            $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $odpoved = @file_get_contents($adresa, false, stream_context_create([
                'http' => ['method' => $metoda, 'header' => implode("\r\n", $hlavicky), 'content' => $telo, 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]));
            $kod = preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        }
        $data = is_string($odpoved) ? json_decode($odpoved, true) : null;
        if ($kod === 200 && is_array($data)) {
            return $data;
        }

        throw new \RuntimeException($kod === 0
            ? 'službu se nepodařilo kontaktovat (' . $cesta . ')'
            : 'chyba ' . $kod . ' (' . $cesta . '): ' . (is_array($data) ? ($data['error']['code'] ?? $data['error']['type'] ?? '') . ' ' . ($data['error']['message'] ?? '') : 'nečitelná odpověď'));
    }
}
