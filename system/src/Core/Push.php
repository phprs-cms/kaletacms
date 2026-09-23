<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Web Push (rozšíření "push"): oznámení o novém článku v prohlížeči čtenáře. Bez knihoven a bez cizí služby.
 *
 * Posílá se zpráva BEZ obsahu - stačí na ni podpis VAPID (ES256), není potřeba šifrování. Service worker
 * (sw.js) si po probuzení sám stáhne /push.json s titulkem a adresou posledního oznámení.
 */
final class Push
{
    /** Služby prohlížečů, kterým smí server oznámení poslat - adresu dodává prohlížeč čtenáře, nesmí vést jinam (SSRF). */
    private const string SLUZBY = '#^https://([a-z0-9.-]+\.)?(fcm\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com|push\.apple\.com)/#i';
    private const int DAVKA = 300;

    public function __construct(private readonly Db $db, private readonly Settings $settings)
    {
    }

    public function zapnuto(): bool
    {
        return Rozsireni::je($this->settings, 'push') && function_exists('openssl_pkey_new') && function_exists('curl_multi_init');
    }

    /** Veřejný klíč VAPID (base64url) pro prohlížeč; pár klíčů vznikne při prvním použití. */
    public function verejnyKlic(): string
    {
        if ($this->settings->get('push_klic_verejny') === '') {
            // pár klíčů vzniká jednou; zámek brání tomu, aby dva souběžné požadavky uložily každý půlku jiného páru
            if ((int) $this->db->value("SELECT GET_LOCK('mirocms_push_klice', 5)") !== 1) {
                return '';
            }
            $ulozeny = (string) $this->db->value("SELECT hodnota FROM {config} WHERE promenna = 'push_klic_verejny'");
            if ($ulozeny !== '') {
                $this->db->run("SELECT RELEASE_LOCK('mirocms_push_klice')");

                return $ulozeny;
            }
            $par = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($par === false || !openssl_pkey_export($par, $pem)) {
                $this->db->run("SELECT RELEASE_LOCK('mirocms_push_klice')");

                return '';
            }
            $ec = openssl_pkey_get_details($par)['ec'];
            $this->settings->set('push_klic_soukromy', $pem);
            $this->settings->set('push_klic_verejny', self::b64("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)));
            $this->db->run("SELECT RELEASE_LOCK('mirocms_push_klice')");
        }

        return $this->settings->get('push_klic_verejny');
    }

    public function prihlas(string $endpoint, string $p256dh, string $auth): bool
    {
        if (strlen($endpoint) > 700 || !preg_match(self::SLUZBY, $endpoint)) {
            return false;
        }
        $this->db->run(
            'INSERT INTO {push} (endpoint, otisk, p256dh, auth, vytvoreno) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth)',
            [$endpoint, hash('sha256', $endpoint), substr($p256dh, 0, 120), substr($auth, 0, 40)],
        );

        return true;
    }

    public function odhlas(string $endpoint): void
    {
        $this->db->delete('push', ['otisk' => hash('sha256', $endpoint)]);
    }

    /**
     * Připraví nové oznámení: uloží jeho obsah (čte ho service worker) a vynuluje ukazatel rozesílky.
     *
     * @param array{titulek:string, text:string, url:string, obrazek:string} $zprava
     */
    public function oznam(array $zprava): void
    {
        $this->settings->set('push_zprava', (string) json_encode($zprava + ['cas' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->settings->set('push_ukazatel', '0');
    }

    /** Obsah posledního oznámení pro /push.json. */
    public function zprava(): array
    {
        return json_decode($this->settings->get('push_zprava'), true) ?: [];
    }

    /** Rozešle další dávku rozpracovaného oznámení. Vrací počet oslovených odběrů. */
    public function rozesli(): int
    {
        $ukazatel = $this->settings->get('push_ukazatel');
        if ($ukazatel === '' || $ukazatel === 'hotovo' || !$this->zapnuto()) {
            return 0;
        }
        $odbery = $this->db->all('SELECT idp, endpoint FROM {push} WHERE idp > ? ORDER BY idp LIMIT ' . self::DAVKA, [(int) $ukazatel]);
        // ukazatel se posouvá předem: souběžná návštěva tak nerozešle stejnou dávku podruhé
        $this->settings->set('push_ukazatel', count($odbery) < self::DAVKA ? 'hotovo' : (string) end($odbery)['idp']);
        if ($odbery === []) {
            return 0;
        }

        $hlavicky = [];
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 20);
        $spojeni = [];
        foreach ($odbery as $o) {
            $cil = parse_url($o['endpoint'], PHP_URL_SCHEME) . '://' . parse_url($o['endpoint'], PHP_URL_HOST);
            $hlavicky[$cil] ??= $this->vapid($cil);
            if ($hlavicky[$cil] === null || !preg_match(self::SLUZBY, $o['endpoint'])) {
                continue;
            }
            $ch = curl_init($o['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: normal', 'Content-Length: 0', 'Authorization: ' . $hlavicky[$cil]],
            ]);
            curl_multi_add_handle($multi, $ch);
            $spojeni[(int) $o['idp']] = $ch;
        }
        do {
            $stav = curl_multi_exec($multi, $bezi);
            if ($bezi) {
                curl_multi_select($multi, 1.0);
            }
        } while ($bezi && $stav === CURLM_OK);
        foreach ($spojeni as $idp => $ch) {
            // 404 a 410 = čtenář oznámení zrušil nebo odběr zanikl
            if (in_array((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), [404, 410], true)) {
                $this->db->delete('push', ['idp' => $idp]);
            }
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        return count($spojeni);
    }

    /** Hlavička Authorization podle RFC 8292: JWT podepsaný soukromým klíčem webu (ES256). */
    private function vapid(string $cil): ?string
    {
        $verejny = $this->verejnyKlic();
        $klic = openssl_pkey_get_private($this->settings->get('push_klic_soukromy'));
        if ($verejny === '' || $klic === false) {
            return null;
        }
        $email = $this->settings->get('email_webu');
        $jwt = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
            . self::b64((string) json_encode(['aud' => $cil, 'exp' => time() + 12 * 3600, 'sub' => $email !== '' ? 'mailto:' . $email : 'https://mirocms.invalid/'], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($jwt, $der, $klic, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return 'vapid t=' . $jwt . '.' . self::b64(self::derNaRaw($der)) . ', k=' . $verejny;
    }

    /** Podpis ECDSA z formátu DER (SEQUENCE { r, s }) na 64 bajtů r||s, jak je chce JWT. */
    private static function derNaRaw(string $der): string
    {
        $pozice = 2 + (ord($der[1]) > 0x80 ? ord($der[1]) - 0x80 : 0);
        $casti = [];
        for ($i = 0; $i < 2; $i++) {
            $delka = ord($der[$pozice + 1]);
            $casti[] = str_pad(ltrim(substr($der, $pozice + 2, $delka), "\0"), 32, "\0", STR_PAD_LEFT);
            $pozice += 2 + $delka;
        }

        return $casti[0] . $casti[1];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
