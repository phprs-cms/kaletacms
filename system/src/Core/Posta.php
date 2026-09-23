<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Odesílání e-mailů: buď funkcí mail() serveru, nebo přes vlastní SMTP server (Nastavení → Pošta).
 *
 * SMTP je spolehlivější - zprávy odcházejí z ověřené schránky (SPF, DKIM) a nekončí ve spamu. Klient je
 * záměrně malý a bez knihoven: STARTTLS nebo SSL, přihlášení AUTH LOGIN/PLAIN, jedno spojení se při
 * rozesílce newsletteru používá opakovaně.
 */
final class Posta
{
    /** Text poslední chyby (pro zkušební e-mail a Stav systému). */
    public static string $chyba = '';

    /** @var resource|null otevřené SMTP spojení */
    private static $spojeni = null;

    /** Za jak dlouho se nepovedené odeslání zkusí znovu (minuty); po posledním pokusu zpráva zůstane ve frontě jako chybná. */
    private const array OPAKOVANI = [5, 30, 120, 720];

    /**
     * Odešle zprávu hned. Když to nejde (výpadek SMTP), uloží ji do fronty a zkusí to později znovu - potvrzení
     * registrace nebo nové heslo se tak neztratí. Každá zpráva má záznam v protokolu (Nastavení → Pošta).
     *
     * @param array<string, string> $hlavicky další hlavičky (např. List-Unsubscribe)
     * @param bool $doFronty false = jednorázová zpráva, která se při chybě neopakuje (zkušební e-mail)
     */
    public static function odesli(Settings $web, string $komu, string $predmet, string $text, string $html = '', array $hlavicky = [], bool $doFronty = true): bool
    {
        $ok = self::posli($web, $komu, $predmet, $text, $html, $hlavicky);
        $chyba = self::$chyba;
        try {
            $web->db()->insert('posta', [
                'komu' => mb_substr($komu, 0, 190), 'predmet' => mb_substr($predmet, 0, 255), 'vytvoreno' => date('Y-m-d H:i:s'), 'pokusu' => 1,
                'odeslano' => $ok ? date('Y-m-d H:i:s') : null, 'chyba' => mb_substr($chyba, 0, 255),
                'telo' => $ok || !$doFronty ? null : json_encode(['text' => $text, 'html' => $html, 'hlavicky' => $hlavicky], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'dalsi_pokus' => $ok || !$doFronty ? null : date('Y-m-d H:i:s', time() + self::OPAKOVANI[0] * 60),
            ]);
            if (random_int(1, 50) === 1) {
                $web->db()->run('DELETE FROM {posta} WHERE vytvoreno < NOW() - INTERVAL 30 DAY');
            }
        } catch (\Throwable) {
            // protokol pošty nesmí shodit odeslání (např. před provedením migrace tabulka ještě není)
        }
        self::$chyba = $chyba;

        return $ok;
    }

    /** Další pokus o zprávy čekající ve frontě; volá se z úloh na pozadí. Vrací počet odeslaných. */
    public static function zpracujFrontu(Settings $web, int $nejvys = 10): int
    {
        $db = $web->db();
        $odeslano = 0;
        foreach ($db->all('SELECT * FROM {posta} WHERE odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus <= NOW() ORDER BY idp LIMIT ' . max(1, $nejvys)) as $z) {
            $telo = json_decode((string) $z['telo'], true) ?: [];
            $pokus = (int) $z['pokusu'] + 1;
            // nejdřív posunout další pokus: souběžný požadavek tak stejnou zprávu neodešle podruhé
            $db->update('posta', ['pokusu' => $pokus, 'dalsi_pokus' => date('Y-m-d H:i:s', time() + (self::OPAKOVANI[$pokus - 1] ?? 0) * 60)], ['idp' => $z['idp']]);
            if (self::posli($web, $z['komu'], $z['predmet'], (string) ($telo['text'] ?? ''), (string) ($telo['html'] ?? ''), (array) ($telo['hlavicky'] ?? []))) {
                $db->update('posta', ['odeslano' => date('Y-m-d H:i:s'), 'telo' => null, 'dalsi_pokus' => null, 'chyba' => ''], ['idp' => $z['idp']]);
                $odeslano++;
            } else {
                $konec = !isset(self::OPAKOVANI[$pokus - 1]);
                $db->update('posta', ['chyba' => mb_substr(self::$chyba, 0, 255)] + ($konec ? ['telo' => null, 'dalsi_pokus' => null] : []), ['idp' => $z['idp']]);
            }
        }

        return $odeslano;
    }

    /** @param array<string, string> $hlavicky */
    private static function posli(Settings $web, string $komu, string $predmet, string $text, string $html = '', array $hlavicky = []): bool
    {
        self::$chyba = '';
        $od = $web->get('posta_od') !== '' ? $web->get('posta_od') : $web->get('email_webu');
        if ($od === '' || filter_var($komu, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20\x7F"<>]/', $komu)) {
            self::$chyba = $od === '' ? 'Není vyplněný e-mail redakce (Nastavení → Základní) ani adresa odesílatele.' : 'Adresa příjemce nemá platný tvar.';

            return false;
        }
        $jmeno = '=?UTF-8?B?' . base64_encode($web->get('nazev_webu')) . '?=';
        $h = ['From' => "{$jmeno} <{$od}>", 'MIME-Version' => '1.0'] + $hlavicky;
        if ($web->get('posta_odpoved') !== '') {
            $h['Reply-To'] = $web->get('posta_odpoved');
        }
        if ($html === '') {
            $h['Content-Type'] = 'text/plain; charset=utf-8';
            $h['Content-Transfer-Encoding'] = 'base64';
            $telo = chunk_split(base64_encode($text));
        } else {
            $hranice = 'mirocms-' . bin2hex(random_bytes(8));
            $h['Content-Type'] = 'multipart/alternative; boundary="' . $hranice . '"';
            $telo = "--{$hranice}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
                . "--{$hranice}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--{$hranice}--\r\n";
        }
        $predmetKod = '=?UTF-8?B?' . base64_encode($predmet) . '?=';
        $radky = [];
        foreach ($h as $nazev => $hodnota) {
            $radky[] = $nazev . ': ' . str_replace(["\r", "\n"], '', $hodnota); // hlavičky nesmí jít rozdělit vloženým koncem řádku
        }

        if ($web->get('posta_rezim') === 'smtp' && $web->get('smtp_host') !== '') {
            try {
                self::smtp($web, $od, $komu, array_merge(['Date: ' . date('r'), 'To: ' . $komu, 'Subject: ' . $predmetKod,
                    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr((string) strrchr($od, '@'), 1) ?: 'localhost') . '>'], $radky), $telo);

                return true;
            } catch (\RuntimeException $e) {
                self::$chyba = $e->getMessage();
                self::zavri();

                return false;
            }
        }
        if (!function_exists('mail')) {
            self::$chyba = 'Funkce mail() je na serveru vypnutá – nastavte odesílání přes SMTP.';

            return false;
        }
        $ok = @mail($komu, $predmetKod, $telo, implode("\r\n", $radky));
        if (!$ok) {
            self::$chyba = 'Server zprávu odmítl odeslat (funkce mail() selhala).';
        }

        return $ok;
    }

    /** @param list<string> $hlavicky */
    private static function smtp(Settings $web, string $od, string $komu, array $hlavicky, string $telo): void
    {
        if (!is_resource(self::$spojeni)) {
            self::pripoj($web);
        } else {
            self::prikaz('RSET', [250]);
        }
        self::prikaz('MAIL FROM:<' . $od . '>', [250]);
        self::prikaz('RCPT TO:<' . $komu . '>', [250, 251]);
        self::prikaz('DATA', [354]);
        // řádek začínající tečkou se zdvojuje, samotná tečka ukončuje zprávu
        $zprava = preg_replace('/^\./m', '..', implode("\r\n", $hlavicky) . "\r\n\r\n" . $telo) ?? '';
        self::prikaz(rtrim($zprava, "\r\n") . "\r\n.", [250]);
    }

    private static function pripoj(Settings $web): void
    {
        $host = $web->get('smtp_host');
        $sifrovani = $web->get('smtp_sifrovani');
        $port = $web->int('smtp_port') ?: ($sifrovani === 'ssl' ? 465 : 587);
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new \RuntimeException('Adresa SMTP serveru nemá platný tvar.');
        }
        $spojeni = @stream_socket_client(($sifrovani === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $cislo, $chyba, 10);
        if ($spojeni === false) {
            throw new \RuntimeException("K SMTP serveru {$host}:{$port} se nepodařilo připojit" . ($chyba !== '' ? " ({$chyba})" : '') . '. Zkontrolujte adresu, port a šifrování; některé hostingy odchozí SMTP blokují.');
        }
        stream_set_timeout($spojeni, 15);
        self::$spojeni = $spojeni;
        self::odpoved([220]);
        $ja = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost');
        $moznosti = self::prikaz($ja, [250]);
        if ($sifrovani === 'tls') {
            self::prikaz('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($spojeni, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new \RuntimeException('Šifrované spojení (STARTTLS) se nepodařilo navázat – server má nejspíš neplatný certifikát.');
            }
            $moznosti = self::prikaz($ja, [250]);
        }
        if ($web->get('smtp_uzivatel') !== '') {
            try {
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $moznosti)) {
                    self::prikaz('AUTH PLAIN ' . base64_encode("\0" . $web->get('smtp_uzivatel') . "\0" . $web->get('smtp_heslo')), [235], true);
                } else {
                    self::prikaz('AUTH LOGIN', [334]);
                    self::prikaz(base64_encode($web->get('smtp_uzivatel')), [334], true);
                    self::prikaz(base64_encode($web->get('smtp_heslo')), [235], true);
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('SMTP server odmítl přihlášení – zkontrolujte jméno a heslo (u Gmailu a Seznamu je potřeba „heslo pro aplikace“). ' . $e->getMessage());
            }
        }
        register_shutdown_function(self::zavri(...));
    }

    /** @param list<int> $ocekavane */
    private static function prikaz(string $prikaz, array $ocekavane, bool $tajne = false): string
    {
        if (@fwrite(self::$spojeni, $prikaz . "\r\n") === false) {
            throw new \RuntimeException('Spojení se SMTP serverem se přerušilo.');
        }

        return self::odpoved($ocekavane, $tajne ? '(přihlašovací údaje)' : strtok($prikaz, "\r\n "));
    }

    /** @param list<int> $ocekavane */
    private static function odpoved(array $ocekavane, string $na = 'připojení'): string
    {
        $odpoved = '';
        do {
            $radek = fgets(self::$spojeni, 1024);
            if ($radek === false) {
                throw new \RuntimeException('SMTP server neodpověděl včas.');
            }
            $odpoved .= $radek;
        } while (isset($radek[3]) && $radek[3] === '-');
        if (!in_array((int) substr($odpoved, 0, 3), $ocekavane, true)) {
            throw new \RuntimeException('Odpověď SMTP serveru na ' . $na . ': ' . mb_substr(trim($odpoved), 0, 200));
        }

        return $odpoved;
    }

    public static function zavri(): void
    {
        if (is_resource(self::$spojeni)) {
            @fwrite(self::$spojeni, "QUIT\r\n");
            @fclose(self::$spojeni);
        }
        self::$spojeni = null;
    }
}
