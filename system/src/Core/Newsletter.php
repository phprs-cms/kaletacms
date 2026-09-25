<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Odběratelé do mailingové služby, kterou web už používá: po potvrzení odběru (double opt-in) se adresa přidá do seznamu
 * ve službě, po odhlášení se z něj odebere. Rozesílání, doručitelnost a odhlašování z e-mailů řeší služba.
 *
 * Potvrzení a odhlášení jen zapíšou úlohu do fronty (ka_odber_fronta); odešle ji úklid na pozadí (Oznameni::naPozadi),
 * aby návštěvník na službu nečekal. Nepovedený pokus se zopakuje později (5 min, 30 min, 2 h, 12 h), potom se vzdá –
 * odběratel má v administraci stav „chyba“ a jde zkusit znovu.
 *
 * Klíč API se ukládá jen na webu (Nastavení → Rozšíření) a přes MCP se neukazuje ani nemění.
 */
final class Newsletter
{
    /** služba => [název, potřebuje klíč a seznam] */
    public const array SLUZBY = [
        'brevo' => ['Brevo', true],
        'mailerlite' => ['MailerLite', true],
        'mailchimp' => ['Mailchimp', true],
        'ecomail' => ['Ecomail', true],
        'smartemailing' => ['SmartEmailing', true],
        'webhook' => ['Jiná služba přes webhook (Make, Zapier, n8n)', false],
    ];

    /** Po kolika minutách další pokus (podle počtu nepovedených). */
    private const array PRODLEVY = [5, 30, 120, 720];

    public static function zapnuto(Settings $s): bool
    {
        $sluzba = $s->get('newsletter_sluzba');
        if (!isset(self::SLUZBY[$sluzba])) {
            return false;
        }

        return self::SLUZBY[$sluzba][1] ? $s->get('newsletter_klic') !== '' && $s->get('newsletter_seznam') !== '' : preg_match('#^https://#i', $s->get('newsletter_webhook')) === 1;
    }

    /** Zařadí přidání (po potvrzení) nebo odebrání (po odhlášení) adresy; bez nastavené služby nic. */
    public static function zarad(App $app, string $email, string $akce): void
    {
        if (!self::zapnuto($app->settings()) || !in_array($akce, ['pridat', 'odebrat'], true)) {
            return;
        }
        $db = $app->db();
        // starší nevyřízená úloha pro tutéž adresu je přebytečná – platí poslední stav
        $db->run('DELETE FROM {odber_fronta} WHERE email = ?', [$email]);
        $db->insert('odber_fronta', ['email' => $email, 'akce' => $akce, 'pokusy' => 0, 'dalsi' => date('Y-m-d H:i:s'), 'vytvoreno' => date('Y-m-d H:i:s')]);
        $db->run("UPDATE {odberatele} SET sync = 'ceka', sync_chyba = '' WHERE email = ?", [$email]);
    }

    /** Všichni potvrzení odběratelé, kteří ve službě ještě nejsou (po napojení služby). @return int kolik jich čeká */
    public static function zaradVsechny(App $app): int
    {
        if (!self::zapnuto($app->settings())) {
            return 0;
        }
        $pocet = 0;
        foreach ($app->db()->all("SELECT email FROM {odberatele} WHERE stav = 1 AND sync <> 'ok'") as $o) {
            self::zarad($app, (string) $o['email'], 'pridat');
            $pocet++;
        }

        return $pocet;
    }

    /** Vzdané úlohy zkusit znovu hned. */
    public static function znovu(App $app): int
    {
        return $app->db()->run('UPDATE {odber_fronta} SET dalsi = NOW(), pokusy = 0 WHERE dalsi IS NULL')->rowCount();
    }

    /** Odešle úlohy, na které přišla řada (volá úklid na pozadí). @return int vyřízených */
    public static function zpracujFrontu(App $app, int $limit = 10): int
    {
        $s = $app->settings();
        if (!self::zapnuto($s)) {
            return 0;
        }
        $db = $app->db();
        $hotovo = 0;
        foreach ($db->all('SELECT * FROM {odber_fronta} WHERE dalsi IS NOT NULL AND dalsi <= NOW() ORDER BY idf LIMIT ' . max(1, $limit)) as $u) {
            try {
                self::proved($s, (string) $u['email'], (string) $u['akce'], (string) $db->value('SELECT zdroj FROM {odberatele} WHERE email = ?', [$u['email']]));
                $db->delete('odber_fronta', ['idf' => $u['idf']]);
                $db->run("UPDATE {odberatele} SET sync = 'ok', sync_chyba = '' WHERE email = ?", [$u['email']]);
                $hotovo++;
            } catch (\RuntimeException $e) {
                $pokusy = (int) $u['pokusy'] + 1;
                $prodleva = self::PRODLEVY[$pokusy - 1] ?? null;
                $chyba = mb_substr($e->getMessage(), 0, 250);
                $db->update('odber_fronta', ['pokusy' => $pokusy, 'chyba' => $chyba, 'dalsi' => $prodleva === null ? null : date('Y-m-d H:i:s', time() + $prodleva * 60)], ['idf' => $u['idf']]);
                if ($prodleva === null) {
                    $db->run("UPDATE {odberatele} SET sync = 'chyba', sync_chyba = ? WHERE email = ?", [$chyba, $u['email']]);
                }
            }
        }

        return $hotovo;
    }

    /**
     * Jedno přidání nebo odebrání ve službě. Chyba služby = RuntimeException s kódem a začátkem odpovědi (bez klíče).
     */
    public static function proved(Settings $s, string $email, string $akce, string $zdroj = ''): void
    {
        $sluzba = $s->get('newsletter_sluzba');
        $klic = $s->get('newsletter_klic');
        $seznam = $s->get('newsletter_seznam');
        $pridat = $akce === 'pridat';
        [$metoda, $url, $hlavicky, $telo, $chybiOk] = match ($sluzba) {
            'brevo' => $pridat
                ? ['POST', 'https://api.brevo.com/v3/contacts', ['api-key: ' . $klic], ['email' => $email, 'listIds' => [(int) $seznam], 'updateEnabled' => true], false]
                : ['POST', 'https://api.brevo.com/v3/contacts/lists/' . rawurlencode($seznam) . '/contacts/remove', ['api-key: ' . $klic], ['emails' => [$email]], true],
            'mailerlite' => ['POST', 'https://connect.mailerlite.com/api/subscribers', ['Authorization: Bearer ' . $klic],
                $pridat ? ['email' => $email, 'groups' => [$seznam], 'status' => 'active'] : ['email' => $email, 'status' => 'unsubscribed'], !$pridat],
            'mailchimp' => [$pridat ? 'PUT' : 'PATCH', 'https://' . self::datoveCentrum($klic) . '.api.mailchimp.com/3.0/lists/' . rawurlencode($seznam) . '/members/' . md5(mb_strtolower($email)),
                ['Authorization: Basic ' . base64_encode('kaleta:' . $klic)], $pridat ? ['email_address' => $email, 'status_if_new' => 'subscribed', 'status' => 'subscribed'] : ['status' => 'unsubscribed'], !$pridat],
            'ecomail' => $pridat
                ? ['POST', 'https://api2.ecomailapp.cz/lists/' . rawurlencode($seznam) . '/subscribe', ['key: ' . $klic], ['subscriber_data' => ['email' => $email], 'update_existing' => true, 'resubscribe' => true, 'skip_confirmation' => true], false]
                : ['DELETE', 'https://api2.ecomailapp.cz/lists/' . rawurlencode($seznam) . '/unsubscribe', ['key: ' . $klic], ['email' => $email], true],
            'smartemailing' => ['POST', 'https://app.smartemailing.cz/api/v3/import', ['Authorization: Basic ' . base64_encode($klic)],
                ['settings' => ['update' => true, 'skip_invalid_emails' => true], 'data' => [['emailaddress' => $email, 'contactlists' => [['id' => (int) $seznam, 'status' => $pridat ? 'confirmed' : 'unsubscribed']]]]], false],
            'webhook' => ['POST', $s->get('newsletter_webhook'), [], ['udalost' => $pridat ? 'novy_odberatel' : 'odhlaseni_odberu', 'web' => $s->get('nazev_webu'), 'email' => $email,
                'zdroj' => $zdroj, 'cas' => date('c')], false],
            default => throw new \RuntimeException('Mailingová služba není nastavená.'),
        };
        // testy: adresa služby se dá přesměrovat na místní falešný server (jen přes databázi, v administraci není)
        $test = $s->get('newsletter_test_url');
        if ($test !== '' && preg_match('#^http://127\.0\.0\.1:\d+$#', $test)) {
            $url = $test . '/' . $sluzba . (string) parse_url($url, PHP_URL_PATH);
        }
        [$kod, $odpoved] = self::http($metoda, $url, $hlavicky, $telo);
        if (($kod >= 200 && $kod < 300) || ($chybiOk && $kod === 404)) {
            return; // odebrání adresy, kterou služba nezná, je v pořádku
        }
        // text chyby se ukládá k odběrateli; „Služba neodpověděla.“ přeloží administrace při zobrazení
        throw new \RuntimeException($kod === 0 ? 'Služba neodpověděla.' : 'HTTP ' . $kod . ($odpoved !== '' ? ': ' . mb_substr(trim(strip_tags($odpoved)), 0, 180) : ''));
    }

    /** Mailchimp: datové centrum je za pomlčkou v klíči (…-us21). */
    private static function datoveCentrum(string $klic): string
    {
        return preg_match('/-([a-z]{2}\d{1,3})$/', $klic, $m) ? $m[1] : 'us1';
    }

    /** @param list<string> $hlavicky @return array{0: int, 1: string} kód odpovědi (0 = bez odpovědi) a tělo */
    private static function http(string $metoda, string $url, array $hlavicky, ?array $telo): array
    {
        $odpoved = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $metoda, 'timeout' => 6, 'ignore_errors' => true,
            'header' => implode("\r\n", array_merge(['Content-Type: application/json; charset=utf-8', 'Accept: application/json', 'User-Agent: Kaleta/' . KALETA_VERSION], $hlavicky)) . "\r\n",
            'content' => $telo === null ? '' : (string) json_encode($telo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]));
        $kod = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) ? (int) $m[1] : 0;

        return [$kod, is_string($odpoved) ? $odpoved : ''];
    }
}
