<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Kopie zálohy databáze mimo server: FTP/FTPS (jiný hosting, NAS) nebo úložiště kompatibilní s S3
 * (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2…). Záloha na stejném disku jako web nechrání před
 * ztrátou hostingu. Bez knihoven: FTP přes rozšíření PHP, S3 přes cURL s podpisem AWS Signature V4.
 */
final class VzdalenaZaloha
{
    /** @return string|null text chyby, null = nahráno (nebo je vzdálené zálohování vypnuté) */
    public static function nahraj(Settings $s, string $cesta): ?string
    {
        $rezim = $s->get('zaloha_vzdalena');
        if (!in_array($rezim, ['ftp', 's3'], true) || $s->get('zaloha_host') === '') {
            return null;
        }
        try {
            $rezim === 'ftp' ? self::ftp($s, $cesta) : self::s3($s, $cesta);
            $chyba = null;
        } catch (\Throwable $e) {
            $chyba = $e->getMessage();
        }
        $s->set('zaloha_vzdalena_stav', date('Y-m-d H:i') . '|' . ($chyba ?? 'ok'));

        return $chyba;
    }

    private static function ftp(Settings $s, string $cesta): void
    {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('Na serveru chybí rozšíření PHP pro FTP.');
        }
        $host = $s->get('zaloha_host');
        $spojeni = function_exists('ftp_ssl_connect') ? @ftp_ssl_connect($host, 21, 15) : false;
        $spojeni = $spojeni ?: @ftp_connect($host, 21, 15); // server bez FTPS: obyčejné FTP
        if ($spojeni === false || !@ftp_login($spojeni, $s->get('zaloha_uzivatel'), $s->get('zaloha_heslo'))) {
            throw new \RuntimeException('K FTP serveru ' . $host . ' se nepodařilo přihlásit.');
        }
        ftp_pasv($spojeni, true);
        $slozka = trim($s->get('zaloha_slozka'), '/');
        if ($slozka !== '' && !@ftp_chdir($spojeni, '/' . $slozka)) {
            @ftp_mkdir($spojeni, '/' . $slozka);
            if (!@ftp_chdir($spojeni, '/' . $slozka)) {
                throw new \RuntimeException('Složka ' . $slozka . ' na FTP serveru neexistuje a nejde vytvořit.');
            }
        }
        $ok = @ftp_put($spojeni, basename($cesta), $cesta, FTP_BINARY);
        ftp_close($spojeni);
        if (!$ok) {
            throw new \RuntimeException('Soubor se na FTP server nepodařilo nahrát.');
        }
    }

    private static function s3(Settings $s, string $cesta): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Na serveru chybí rozšíření cURL.');
        }
        $host = preg_replace('#^https?://|/.*$#', '', $s->get('zaloha_host')) ?? '';
        $kbelik = trim($s->get('zaloha_slozka'), '/');
        if ($kbelik === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new \RuntimeException('Vyplňte adresu úložiště (např. s3.eu-central-1.amazonaws.com) a název bucketu.');
        }
        $uri = '/' . implode('/', array_map(rawurlencode(...), explode('/', $kbelik . '/' . basename($cesta))));
        $hlavicky = self::podpisS3('PUT', $host, $uri, hash_file('sha256', $cesta), $s->get('zaloha_region') ?: 'us-east-1', $s->get('zaloha_uzivatel'), $s->get('zaloha_heslo'), time());
        $f = fopen($cesta, 'rb');
        $ch = curl_init('https://' . $host . $uri);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true, CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => filesize($cesta), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($hlavicky), $hlavicky),
        ]);
        $odpoved = (string) curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($f);
        if ($kod < 200 || $kod >= 300) {
            throw new \RuntimeException('Úložiště nahrání odmítlo (kód ' . $kod . ')' . (preg_match('#<Message>([^<]+)#', $odpoved, $m) ? ': ' . $m[1] : ($kod === 0 ? ': nepodařilo se připojit' : '')) . '.');
        }
    }

    /**
     * Hlavičky požadavku podepsané AWS Signature Version 4 (služba s3).
     *
     * @return array<string, string>
     */
    public static function podpisS3(string $metoda, string $host, string $uri, string $otiskTela, string $region, string $klic, string $tajemstvi, int $cas): array
    {
        $datum = gmdate('Ymd\THis\Z', $cas);
        $den = substr($datum, 0, 8);
        $hlavicky = ['host' => $host, 'x-amz-content-sha256' => $otiskTela, 'x-amz-date' => $datum];
        $podepsane = implode(';', array_keys($hlavicky));
        $kanonicky = $metoda . "\n" . $uri . "\n\n" . implode('', array_map(fn (string $k, string $v): string => $k . ':' . $v . "\n", array_keys($hlavicky), $hlavicky)) . "\n" . $podepsane . "\n" . $otiskTela;
        $rozsah = $den . '/' . $region . '/s3/aws4_request';
        $kPodpisu = "AWS4-HMAC-SHA256\n" . $datum . "\n" . $rozsah . "\n" . hash('sha256', $kanonicky);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $den, 'AWS4' . $tajemstvi, true), true), true), true);

        return [
            'Host' => $host, 'x-amz-content-sha256' => $otiskTela, 'x-amz-date' => $datum,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $klic . '/' . $rozsah . ', SignedHeaders=' . $podepsane . ', Signature=' . hash_hmac('sha256', $kPodpisu, $k),
        ];
    }
}
