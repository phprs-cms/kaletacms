<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Kontrola nefunkčních odkazů ve vydaných článcích. Běží na pozadí po malých dávkách: jeden článek za pět minut,
 * každý článek jednou za 30 dní. Ukládají se jen odkazy, které nefungují (Články → Nefunkční odkazy).
 *
 * Server se při kontrole připojuje na adresy z článků, proto jen http(s) na veřejné adresy a standardní porty,
 * bez následování přesměrování - odkaz v článku nesmí jít zneužít k ohledávání vnitřní sítě hostingu.
 */
final class Odkazy
{
    /** Kódy, které neznamenají rozbitý odkaz: weby jimi jen odmítají roboty nebo metodu HEAD. */
    private const array NEJISTE = [401, 403, 405, 406, 429, 999];

    public static function naPozadi(App $app): void
    {
        $s = $app->settings();
        if (!$s->bool('kontrola_odkazu') || !function_exists('curl_init') || time() - $s->int('kontrola_odkazu_cas') < 300) {
            return;
        }
        $s->set('kontrola_odkazu_cas', (string) time());
        $db = $app->db();
        $clanek = $db->one('SELECT idc, uvod, text FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND (odkazy_cas IS NULL OR odkazy_cas < NOW() - INTERVAL 30 DAY) ORDER BY odkazy_cas IS NOT NULL, odkazy_cas, datum DESC LIMIT 1');
        if ($clanek === null) {
            return;
        }
        $db->update('novinky', ['odkazy_cas' => date('Y-m-d H:i:s')], ['idc' => $clanek['idc']]);
        $db->delete('odkazy_vadne', ['idc' => $clanek['idc']]);
        $konec = microtime(true) + 12; // na jeden běh nejvýš 12 vteřin
        foreach (self::odkazy($clanek['uvod'] . $clanek['text']) as $url) {
            if (microtime(true) > $konec) {
                break;
            }
            $stav = self::over($app, $url);
            if ($stav !== null) {
                $db->insert('odkazy_vadne', ['idc' => $clanek['idc'], 'url' => mb_substr($url, 0, 500), 'stav' => $stav, 'cas' => date('Y-m-d H:i:s')]);
            }
        }
    }

    /** @return list<string> jedinečné odkazy z HTML (nejvýš 25) */
    public static function odkazy(string $html): array
    {
        preg_match_all('#<a\b[^>]*\bhref="([^"]+)"#i', $html, $m);
        $odkazy = array_filter(array_map(fn (string $u): string => html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5), $m[1]), fn (string $u): bool => $u !== '' && !preg_match('#^(mailto:|tel:|\#|javascript:)#i', $u));

        return array_slice(array_values(array_unique($odkazy)), 0, 25);
    }

    /** @return int|null kód chyby (0 = bez odpovědi), null = odkaz je v pořádku nebo ho nejde posoudit */
    public static function over(App $app, string $url): ?int
    {
        // odkaz na vlastní web: stačí se podívat do databáze
        $vlastni = $app->request->origin();
        $cesta = str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : (str_starts_with($url, $vlastni . '/') ? substr($url, strlen($vlastni)) : null);
        if ($cesta !== null) {
            $cesta = (string) parse_url(substr($cesta, strlen($app->request->basePath())), PHP_URL_PATH);
            if (preg_match('#^/(?:[a-z]{2}/)?novinky/([a-z0-9-]+)$#', $cesta, $m)) {
                return $app->db()->value('SELECT idc FROM {novinky} WHERE seo_link = ?', [$m[1]]) === null
                    && $app->db()->value('SELECT idp FROM {presmerovani} WHERE z_adresy = ?', ['novinky/' . $m[1]]) === null ? 404 : null;
            }

            return null; // ostatní vlastní adresy (rubriky, soubory) se neověřují
        }
        if (!self::jeVerejna($url)) {
            return null;
        }
        // adresa se přeloží jednou a spojení se na ni připne - mezi kontrolou a připojením ji nejde podvrhnout (DNS rebinding)
        $c = parse_url($url);
        $ip = filter_var(trim((string) $c['host'], '[]'), FILTER_VALIDATE_IP) !== false ? null : (gethostbynamel((string) $c['host'])[0] ?? null);
        $port = $c['port'] ?? (strtolower((string) $c['scheme']) === 'https' ? 443 : 80);
        $ch = curl_init($url);
        if ($ip !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$c['host'] . ':' . $port . ':' . $ip]);
        }
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MiroCMS kontrola odkazu)',
        ]);
        curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $kod >= 200 && $kod < 400 || in_array($kod, self::NEJISTE, true) ? null : $kod;
    }

    /** Jen http(s), standardní port a adresa, která nevede do vnitřní sítě. */
    public static function jeVerejna(string $url): bool
    {
        $c = parse_url($url);
        if (!is_array($c) || !in_array(strtolower($c['scheme'] ?? ''), ['http', 'https'], true) || empty($c['host']) || (isset($c['port']) && !in_array($c['port'], [80, 443], true))) {
            return false;
        }
        $host = trim($c['host'], '[]');
        $adresy = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($adresy === []) {
            return true; // neexistující doména není vnitřní síť - kontrola ji vyhodnotí jako nedostupnou
        }
        foreach ($adresy as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
