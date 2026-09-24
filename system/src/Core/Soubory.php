<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Přílohy ke stažení v Médiích: PDF, dokumenty, tabulky, zvuk a video. Obrázky řeší Core\Obrazky.
 *
 * Povolené jsou jen vyjmenované přípony; soubor dostane nové bezpečné jméno a nikdy se nespouští
 * (media/.htaccess). HTML, SVG ani skripty nahrát nejdou - prohlížeč by je otevřel jako součást webu.
 */
final class Soubory
{
    public const array PRIPONY = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'zip', 'epub', 'gpx', 'ics', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'webm', 'woff2', 'woff'];
    private const int MAX_BAJTU = 200 * 1024 * 1024;
    private const string ZAKAZANE_TYPY = '#html|php|javascript|svg|x-sh|x-dosexec|x-executable|x-mach|x-msdownload#i';

    public static function jePriloha(string $jmeno): bool
    {
        return in_array(strtolower(pathinfo($jmeno, PATHINFO_EXTENSION)), self::PRIPONY, true);
    }

    /**
     * @param array<string, mixed> $file položka z $_FILES
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public static function uloz(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je větší, než server dovoluje nahrát (' . ini_get('upload_max_filesize') . ').',
                default => 'Soubor se nepodařilo nahrát.',
            });
        }
        $tmp = (string) $file['tmp_name'];
        $pripona = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($pripona, self::PRIPONY, true)) {
            throw new \RuntimeException('Tento typ souboru nahrát nejde. Povolené jsou obrázky a přílohy: ' . implode(', ', self::PRIPONY) . '.');
        }
        $typ = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (preg_match(self::ZAKAZANE_TYPY, $typ) || filesize($tmp) > self::MAX_BAJTU) {
            throw new \RuntimeException(filesize($tmp) > self::MAX_BAJTU ? 'Soubor je příliš velký (nejvýše 200 MB).' : 'Obsah souboru neodpovídá jeho příponě.');
        }
        $slozka = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $slozka) && !mkdir(KALETA_ROOT . '/' . $slozka, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku ' . $slozka . ' - zkontrolujte práva k zápisu.');
        }
        $nazev = pathinfo((string) $file['name'], PATHINFO_FILENAME);
        $cil = $slozka . '/' . slugify($nazev, 60) . '-' . bin2hex(random_bytes(3)) . '.' . $pripona;
        if (!move_uploaded_file($tmp, KALETA_ROOT . '/' . $cil)) {
            throw new \RuntimeException('Soubor se nepodařilo uložit.');
        }

        // příloha se v tabulce médií pozná podle prázdného náhledu a nulových rozměrů
        return ['obr_poloha' => $cil, 'obr_width' => 0, 'obr_height' => 0, 'obr_vel' => (int) filesize(KALETA_ROOT . '/' . $cil),
            'nahl_poloha' => '', 'nahl_width' => 0, 'nahl_height' => 0, 'nazev' => mb_substr($nazev, 0, 150)];
    }

    public static function smaz(string $cesta): void
    {
        if (preg_match('#^media/\d{4}/\d{2}/[a-z0-9-]+\.(' . implode('|', self::PRIPONY) . ')$#', $cesta) && is_file(KALETA_ROOT . '/' . $cesta)) {
            unlink(KALETA_ROOT . '/' . $cesta);
        }
    }

    public static function velikost(int $bajtu): string
    {
        return $bajtu >= 1048576 ? cislo($bajtu / 1048576) . ' MB' : max(1, (int) round($bajtu / 1024)) . ' kB';
    }
}
