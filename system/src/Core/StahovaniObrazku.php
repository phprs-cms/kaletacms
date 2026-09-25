<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Stahování obrázků ze starého webu při importu z WordPressu.
 *
 * Adresy obrázků pocházejí z importovaného souboru, tedy z nedůvěryhodného vstupu. Kdyby server stahoval cokoli, co soubor řekne,
 * šlo by ho zneužít k ohledávání vnitřní sítě hostingu (útok SSRF). Proto platí bez výjimky:
 *  1. jen http a https, jen doména starého webu (nebo její varianta s „www.“), jen výchozí port, žádné jméno a heslo v adrese;
 *  2. doména se přeloží na IP adresy a VŠECHNY musí být veřejné (ne 10.x, 192.168.x, 127.x, 169.254.x, 100.64.x, ::1, fc00::/7…);
 *     spojení se pak připne na ověřenou adresu, aby ji mezi kontrolou a stažením nešlo vyměnit (DNS rebinding);
 *  3. přesměrování nejvýš 3, nikdy automaticky – každý krok projde znovu body 1 a 2;
 *  4. spojení do 5 s, celé stažení do 20 s, nejvýš 15 MB (hlídá se už při čtení);
 *  5. přijme se jen JPEG, PNG, GIF a WebP – podle hlavičky odpovědi I podle skutečného obsahu. SVG nikdy;
 *  6. neposílají se cookies, přihlašovací údaje ani hlavičky z importu; prohlížeč se hlásí jako „Kaleta-import“.
 * Stažená data jdou dál jen přes Core\Obrazky, který obrázek znovu zakóduje.
 */
final class StahovaniObrazku
{
    public const int MAX_BAJTU = 15 * 1024 * 1024;
    public const int MAX_PRESMEROVANI = 3;
    public const int CAS_SPOJENI = 5;
    public const int CAS_CELKEM = 20;
    private const string PROHLIZEC = 'Kaleta-import';
    private const array TYPY = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Rozsahy, kam se server nikdy nepřipojí: vnitřní sítě, smyčka, link-local, CGNAT, multicast, vyhrazené a dokumentační adresy
     * a také přechodové IPv6 rozsahy, do kterých jde zabalit vnitřní IPv4 adresa (::a.b.c.d, NAT64, Teredo, 6to4).
     */
    private const array ZAKAZANE_SITE = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** Doména starého webu malými písmeny a bez „www.“. */
    private readonly string $domena;

    /** @param string $adresaWebu adresa starého webu z importovaného souboru (<channel><link>) */
    public function __construct(string $adresaWebu)
    {
        $this->domena = self::domenaZAdresy($adresaWebu);
    }

    /** Umí server vůbec stahovat? Bez curl i bez allow_url_fopen je potřeba obrázky přenést ručně. */
    public static function jeMozne(): bool
    {
        return function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Doména z adresy: malá písmena, bez „www.“ a bez tečky na konci; prázdný řetězec = adresa není http(s). */
    public static function domenaZAdresy(string $adresa): string
    {
        $c = parse_url(trim($adresa));
        if (!is_array($c) || !in_array(strtolower($c['scheme'] ?? ''), ['http', 'https'], true)) {
            return '';
        }
        $host = rtrim(strtolower(trim((string) ($c['host'] ?? ''), '[]')), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function domena(): string
    {
        return $this->domena;
    }

    /** Bod 1: smí se tahle adresa vůbec zkusit? Čistá funkce – nic nepřekládá ani nestahuje. */
    public function povolenaAdresa(string $url): bool
    {
        if ($this->domena === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }
        $c = parse_url($url);
        if (!is_array($c) || isset($c['user']) || isset($c['pass'])) {
            return false;
        }
        $schema = strtolower($c['scheme'] ?? '');
        if (!in_array($schema, ['http', 'https'], true) || (isset($c['port']) && $c['port'] !== ($schema === 'https' ? 443 : 80))) {
            return false;
        }

        return self::domenaZAdresy($url) === $this->domena;
    }

    /** Bod 2: je IP adresa veřejná? IPv4 zabalená v IPv6 (::ffff:10.0.0.1) se posuzuje jako IPv4. */
    public static function verejnaIp(string $ip): bool
    {
        $binarne = @inet_pton(trim($ip, '[]'));
        if ($binarne === false) {
            return false;
        }
        if (strlen($binarne) === 16 && str_starts_with($binarne, str_repeat("\0", 10) . "\xff\xff")) {
            $binarne = substr($binarne, 12); // ::ffff:a.b.c.d
        }
        foreach (self::ZAKAZANE_SITE as $sit) {
            [$adresa, $maska] = explode('/', $sit);
            $sitBinarne = (string) inet_pton($adresa);
            if (strlen($sitBinarne) === strlen($binarne) && self::stejnaSit($binarne, $sitBinarne, (int) $maska)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bod 5: typ obrázku podle hlavičky Content-Type A ZÁROVEŇ podle skutečných bajtů; null = odmítnuto.
     * Obojí musí být na seznamu povolených (SVG, HTML ani nic jiného neprojde, ať se tváří jakkoli).
     */
    public static function typObrazku(string $hlavickaTypu, string $data): ?string
    {
        $zHlavicky = strtolower(trim(explode(';', $hlavickaTypu)[0]));
        $info = $data === '' ? false : @getimagesizefromstring($data);
        $zObsahu = $info === false ? '' : (string) $info['mime'];

        return in_array($zHlavicky, self::TYPY, true) && in_array($zObsahu, self::TYPY, true) ? $zObsahu : null;
    }

    /** Cíl přesměrování: hlavička Location smí být i relativní (/jinam/foto.jpg). */
    public static function cilPresmerovani(string $odkud, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $c = parse_url($odkud);
        $koren = ($c['scheme'] ?? 'http') . '://' . ($c['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return ($c['scheme'] ?? 'http') . ':' . $location;
        }

        return str_starts_with($location, '/') ? $koren . $location : $koren . rtrim(dirname(($c['path'] ?? '/') . 'x'), '/') . '/' . $location;
    }

    /**
     * Přeloží doménu a vrátí IP adresu, na kterou se smí připojit; null = doména neexistuje nebo některá z adres není veřejná.
     */
    public function overenaIp(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::verejnaIp($host) ? $host : null;
        }
        $adresy = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $zaznam) {
            $adresy[] = (string) ($zaznam['ipv6'] ?? '');
        }
        $adresy = array_values(array_filter($adresy));
        foreach ($adresy as $ip) {
            if (!self::verejnaIp($ip)) {
                return null; // stačí jediná vnitřní adresa a doména je podezřelá celá
            }
        }

        return $adresy[0] ?? null;
    }

    /**
     * Stáhne obrázek a vrátí jeho obsah. S $jenObrazky = false i jiný soubor (písmo, PDF pro MCP) – jeho typ pak ověří
     * až ukládání (Core\Soubory: povolené přípony a skutečný obsah, SVG vyčistí Core\Svg); ostatní pravidla platí stejně.
     *
     * @throws \RuntimeException s důvodem, proč obrázek stáhnout nejde
     */
    public function stahni(string $url, bool $jenObrazky = true): string
    {
        for ($krok = 0; $krok <= self::MAX_PRESMEROVANI; $krok++) {
            if (!$this->povolenaAdresa($url)) {
                throw new \RuntimeException('Adresa nepatří starému webu.');
            }
            $ip = $this->overenaIp((string) parse_url($url, PHP_URL_HOST));
            if ($ip === null) {
                throw new \RuntimeException('Doména starého webu neexistuje nebo vede do vnitřní sítě.');
            }
            $odpoved = function_exists('curl_init') ? $this->pozadavekCurl($url, $ip) : $this->pozadavekProud($url, $ip);
            if (in_array($odpoved['kod'], [301, 302, 303, 307, 308], true) && $odpoved['location'] !== '') {
                $url = self::cilPresmerovani($url, $odpoved['location']);
                continue;
            }
            if ($odpoved['kod'] !== 200) {
                throw new \RuntimeException('Starý web obrázek nevydal, odpověděl chybou', $odpoved['kod']); // kód odpovědi nese getCode()
            }
            if ($jenObrazky && self::typObrazku($odpoved['typ'], $odpoved['data']) === null) {
                throw new \RuntimeException('Soubor není obrázek JPG, PNG, GIF ani WebP.');
            }

            return $odpoved['data'];
        }
        throw new \RuntimeException('Příliš mnoho přesměrování.');
    }

    /**
     * Jeden požadavek přes curl; spojení je připnuté na ověřenou IP adresu (CURLOPT_RESOLVE).
     *
     * @return array{kod:int, typ:string, location:string, data:string}
     */
    private function pozadavekCurl(string $url, string $ip): array
    {
        $c = parse_url($url);
        $port = strtolower((string) $c['scheme']) === 'https' ? 443 : 80;
        $data = '';
        $hlavicky = ['content-type' => '', 'location' => ''];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$c['host'] . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false, // přesměrování si hlídáme sami, krok po kroku
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CAS_SPOJENI,
            CURLOPT_TIMEOUT => self::CAS_CELKEM,
            CURLOPT_MAXFILESIZE => self::MAX_BAJTU,
            CURLOPT_USERAGENT => self::PROHLIZEC,
            CURLOPT_HEADERFUNCTION => function ($ch, string $radek) use (&$hlavicky): int {
                $casti = explode(':', $radek, 2);
                if (count($casti) === 2 && isset($hlavicky[strtolower(trim($casti[0]))])) {
                    $hlavicky[strtolower(trim($casti[0]))] = trim($casti[1]);
                }

                return strlen($radek);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $kus) use (&$data): int {
                $data .= $kus;

                return strlen($data) > self::MAX_BAJTU ? -1 : strlen($kus); // jiná hodnota než délka = curl stahování ukončí
            },
        ]);
        curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $chyba = curl_errno($ch);
        if (strlen($data) > self::MAX_BAJTU || $chyba === CURLE_FILESIZE_EXCEEDED) {
            throw new \RuntimeException('Obrázek je větší než 15 MB.');
        }
        if ($chyba !== 0) {
            throw new \RuntimeException('Starý web neodpovídá.');
        }

        return ['kod' => $kod, 'typ' => $hlavicky['content-type'], 'location' => $hlavicky['location'], 'data' => $data];
    }

    /**
     * Totéž bez curl (allow_url_fopen). Připojuje se přímo na ověřenou IP adresu; doména jde v hlavičce Host a do ověření certifikátu.
     *
     * @return array{kod:int, typ:string, location:string, data:string}
     */
    private function pozadavekProud(string $url, string $ip): array
    {
        $c = parse_url($url);
        $host = (string) $c['host'];
        $cil = $c['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ($c['path'] ?? '/') . (isset($c['query']) ? '?' . $c['query'] : '');
        $kontext = stream_context_create([
            'http' => ['method' => 'GET', 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => self::CAS_SPOJENI, 'ignore_errors' => true,
                'user_agent' => self::PROHLIZEC, 'header' => 'Host: ' . $host . "\r\nConnection: close\r\n"],
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $proud = @fopen($cil, 'rb', false, $kontext);
        if ($proud === false) {
            throw new \RuntimeException('Starý web neodpovídá.');
        }
        $konec = microtime(true) + self::CAS_CELKEM;
        $data = '';
        while (!feof($proud)) {
            $data .= (string) fread($proud, 65536);
            if (strlen($data) > self::MAX_BAJTU) {
                fclose($proud);
                throw new \RuntimeException('Obrázek je větší než 15 MB.');
            }
            if (microtime(true) > $konec) {
                fclose($proud);
                throw new \RuntimeException('Starý web odpovídá příliš pomalu.');
            }
        }
        $hlavicky = stream_get_meta_data($proud)['wrapper_data'] ?? [];
        fclose($proud);
        $odpoved = ['kod' => 0, 'typ' => '', 'location' => '', 'data' => $data];
        foreach ($hlavicky as $radek) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $radek, $m)) {
                $odpoved['kod'] = (int) $m[1];
            } elseif (preg_match('#^(content-type|location):\s*(.*)$#i', (string) $radek, $m)) {
                $odpoved[strtolower($m[1]) === 'location' ? 'location' : 'typ'] = trim($m[2]);
            }
        }

        return $odpoved;
    }

    /** Leží adresa v síti? Porovnává se prvních $maska bitů. */
    private static function stejnaSit(string $ip, string $sit, int $maska): bool
    {
        $bajtu = intdiv($maska, 8);
        $bitu = $maska % 8;
        if (substr($ip, 0, $bajtu) !== substr($sit, 0, $bajtu)) {
            return false;
        }

        return $bitu === 0 || ((ord($ip[$bajtu]) ^ ord($sit[$bajtu])) & (0xFF << (8 - $bitu) & 0xFF)) === 0;
    }
}
