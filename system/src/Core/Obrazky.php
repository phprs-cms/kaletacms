<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Příjem nahraných obrázků: ověření, zmenšení na rozumnou velikost, náhled, uložení do media/RRRR/MM/.
 * Obrázek se vždy znovu zakóduje přes GD - tím zmizí EXIF (poloha z mobilu) i případný podstrčený kód.
 *
 * Ke každému obrázku vznikají varianty: <jmeno>-1200.<ext> (střední) a <jmeno>-nahled.<ext> (640 px) pro srcset
 * a ke každé z nich sourozenec <soubor>.webp, který server podá prohlížečům s podporou WebP (.htaccess).
 */
final class Obrazky
{
    public const int MAX_STRANA = 2000;
    public const int NAHLED_STRANA = 640;
    public const int STREDNI_STRANA = 1200;
    private const int MAX_BAJTU = 20 * 1024 * 1024;
    private const int MAX_PIXELU = 50_000_000;

    private const array TYPY = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

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

        return self::zpracuj((string) $file['tmp_name'], (string) ($file['name'] ?? 'obrazek'), true);
    }

    /**
     * Obrázek, který už na serveru leží (stažený při importu z WordPressu): projde stejnou cestou jako nahraný,
     * takže je od něj k nerozeznání - překódování přes GD, zmenšení, náhled, WebP. Zdrojový soubor zůstává na místě.
     *
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public static function ulozSoubor(string $cesta, string $nazev): array
    {
        if (!is_file($cesta)) {
            throw new \RuntimeException('Soubor se nepodařilo nahrát.');
        }

        return self::zpracuj($cesta, $nazev, false);
    }

    /**
     * @param bool $nahrany soubor přišel formulářem (přesouvá se přes move_uploaded_file); jinak se jen kopíruje
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     */
    private static function zpracuj(string $tmp, string $jmenoSouboru, bool $nahrany): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Na serveru chybí rozšíření GD pro práci s obrázky.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TYPY[$info[2]])) {
            throw new \RuntimeException('Povolené jsou jen obrázky JPG, PNG, WebP a GIF.');
        }
        if (filesize($tmp) > self::MAX_BAJTU || $info[0] * $info[1] > self::MAX_PIXELU) {
            throw new \RuntimeException('Obrázek je příliš velký (nejvýše 20 MB a 50 megapixelů).');
        }

        $pripona = self::TYPY[$info[2]];
        $nazev = pathinfo($jmenoSouboru, PATHINFO_FILENAME);
        $slozka = 'media/' . date('Y/m');
        if (!is_dir(MIROCMS_ROOT . '/' . $slozka) && !mkdir(MIROCMS_ROOT . '/' . $slozka, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku ' . $slozka . ' - zkontrolujte práva k zápisu.');
        }
        $zaklad = $slozka . '/' . slugify($nazev, 60) . '-' . bin2hex(random_bytes(3));

        if ($pripona === 'gif') {
            // GIF může být animovaný - ukládá se beze změny, náhled je první snímek
            $cil = $zaklad . '.gif';
            if (!($nahrany ? move_uploaded_file($tmp, MIROCMS_ROOT . '/' . $cil) : copy($tmp, MIROCMS_ROOT . '/' . $cil))) {
                throw new \RuntimeException('Soubor se nepodařilo uložit.');
            }
            $obr = imagecreatefromgif(MIROCMS_ROOT . '/' . $cil);
            [$w, $h] = [$info[0], $info[1]];
        } else {
            $obr = @imagecreatefromstring((string) file_get_contents($tmp));
            if ($obr === false) {
                throw new \RuntimeException('Obrázek je poškozený a nelze ho zpracovat.');
            }
            $obr = self::otocPodleExif($obr, $tmp, $pripona);
            $obr = self::zmensi($obr, self::MAX_STRANA);
            [$w, $h] = [imagesx($obr), imagesy($obr)];
            $cil = $zaklad . '.' . $pripona;
            self::zapis($obr, MIROCMS_ROOT . '/' . $cil, $pripona);
            self::webp($obr, MIROCMS_ROOT . '/' . $cil, $pripona);
            if (max($w, $h) > self::STREDNI_STRANA) {
                $stredni = self::zmensi($obr, self::STREDNI_STRANA);
                $stredniCil = MIROCMS_ROOT . '/' . $zaklad . '-1200.' . $pripona;
                self::zapis($stredni, $stredniCil, $pripona);
                self::webp($stredni, $stredniCil, $pripona);
            }
        }

        $nahled = self::zmensi($obr, self::NAHLED_STRANA);
        $nahledPripona = $pripona === 'gif' ? 'png' : $pripona;
        $nahledCil = $zaklad . '-nahled.' . $nahledPripona;
        self::zapis($nahled, MIROCMS_ROOT . '/' . $nahledCil, $nahledPripona);
        self::webp($nahled, MIROCMS_ROOT . '/' . $nahledCil, $nahledPripona);

        return [
            'obr_poloha' => $cil, 'obr_width' => $w, 'obr_height' => $h, 'obr_vel' => (int) filesize(MIROCMS_ROOT . '/' . $cil),
            'nahl_poloha' => $nahledCil, 'nahl_width' => imagesx($nahled), 'nahl_height' => imagesy($nahled),
            'nazev' => mb_substr(trim(str_replace(['_', '-'], ' ', $nazev)), 0, 150),
        ];
    }

    /**
     * Náhrada obrázku se zachováním adresy: nový soubor projde stejným zpracováním a zapíše se na místo starého
     * (i s variantami a WebP), ve formátu starého souboru – adresa se nemění, odkazy na webu platí dál.
     *
     * @param array<string, mixed> $file položka z $_FILES
     * @return array{obr_width:int, obr_height:int, obr_vel:int, nahl_width:int, nahl_height:int}
     */
    public static function nahrad(string $stara, array $file): array
    {
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp)$#', $stara, $m)) {
            throw new \RuntimeException('Nahradit jde jen obrázek JPG, PNG nebo WebP.');
        }
        $novy = self::uloz($file); // ověří, zmenší a znovu zakóduje nahraný soubor
        $obr = @imagecreatefromstring((string) file_get_contents(MIROCMS_ROOT . '/' . $novy['obr_poloha']));
        self::smaz($novy['obr_poloha'], $novy['nahl_poloha']);
        if ($obr === false) {
            throw new \RuntimeException('Obrázek je poškozený a nelze ho zpracovat.');
        }
        self::smaz($stara, $m[1] . '-nahled.' . $m[2]);
        [$zaklad, $pripona] = [$m[1], $m[2]];
        self::zapis($obr, MIROCMS_ROOT . '/' . $stara, $pripona);
        self::webp($obr, MIROCMS_ROOT . '/' . $stara, $pripona);
        if (max(imagesx($obr), imagesy($obr)) > self::STREDNI_STRANA) {
            $stredni = self::zmensi($obr, self::STREDNI_STRANA);
            self::zapis($stredni, MIROCMS_ROOT . '/' . $zaklad . '-1200.' . $pripona, $pripona);
            self::webp($stredni, MIROCMS_ROOT . '/' . $zaklad . '-1200.' . $pripona, $pripona);
        }
        $nahled = self::zmensi($obr, self::NAHLED_STRANA);
        self::zapis($nahled, MIROCMS_ROOT . '/' . $zaklad . '-nahled.' . $pripona, $pripona);
        self::webp($nahled, MIROCMS_ROOT . '/' . $zaklad . '-nahled.' . $pripona, $pripona);

        return ['obr_width' => imagesx($obr), 'obr_height' => imagesy($obr), 'obr_vel' => (int) filesize(MIROCMS_ROOT . '/' . $stara),
            'nahl_width' => imagesx($nahled), 'nahl_height' => imagesy($nahled)];
    }

    /** Smaže soubory obrázku; cesty mimo media/ ignoruje. */
    public static function smaz(string ...$cesty): void
    {
        foreach ($cesty as $cesta) {
            if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp|gif|svg)$#', $cesta, $m)) {
                continue;
            }
            // s obrázkem mizí i jeho varianty pro srcset a WebP
            foreach ([$cesta, $cesta . '.webp', $cesta . '.avif', $m[1] . '-1200.' . $m[2], $m[1] . '-1200.' . $m[2] . '.webp', $m[1] . '-1200.' . $m[2] . '.avif'] as $soubor) {
                if (is_file(MIROCMS_ROOT . '/' . $soubor)) {
                    unlink(MIROCMS_ROOT . '/' . $soubor);
                }
            }
        }
    }

    private static function zmensi(\GdImage $obr, int $maxStrana): \GdImage
    {
        [$w, $h] = [imagesx($obr), imagesy($obr)];
        if (max($w, $h) <= $maxStrana) {
            return $obr;
        }
        $pomer = $maxStrana / max($w, $h);
        $novy = imagecreatetruecolor(max(1, (int) round($w * $pomer)), max(1, (int) round($h * $pomer)));
        imagealphablending($novy, false);
        imagesavealpha($novy, true);
        imagecopyresampled($novy, $obr, 0, 0, 0, 0, imagesx($novy), imagesy($novy), $w, $h);

        return $novy;
    }

    private static function zapis(\GdImage $obr, string $soubor, string $pripona): void
    {
        $ok = match ($pripona) {
            'jpg' => imagejpeg($obr, $soubor, 85),
            'webp' => imagewebp($obr, $soubor, 85),
            default => (function () use ($obr, $soubor): bool {
                imagesavealpha($obr, true);

                return imagepng($obr, $soubor, 7);
            })(),
        };
        if (!$ok) {
            throw new \RuntimeException('Obrázek se nepodařilo uložit - zkontrolujte práva ke složce media/.');
        }
    }

    /**
     * Menší sourozenci pro moderní prohlížeče: foto.jpg.webp (o 25–35 % menší) a foto.jpg.avif (o dalších ~20 %),
     * když je PHP umí. Server podá ten, který prohlížeč přijme (.htaccess, nginx).
     */
    private static function webp(\GdImage $obr, string $soubor, string $pripona): void
    {
        imagepalettetotruecolor($obr);
        if ($pripona !== 'webp' && function_exists('imagewebp')) {
            @imagewebp($obr, $soubor . '.webp', 82);
        }
        if (function_exists('imageavif')) {
            @imageavif($obr, $soubor . '.avif', 55, 8); // rychlost 8: kódování nezdrží nahrávání
        }
    }

    /**
     * Atribut srcset pro obrázek z media/ podle existujících variant; prázdný řetězec, když žádné nejsou.
     *
     * @param string $cesta cesta od kořene webu bez úvodního lomítka (media/2026/09/foto.jpg)
     */
    /** Převládající barva obrázku jako #rrggbb (průměr přes celou plochu); null, když soubor nejde načíst. */
    public static function barva(string $soubor): ?string
    {
        $typ = is_file($soubor) ? @getimagesize($soubor) : false;
        $obr = match ($typ[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($soubor),
            IMAGETYPE_PNG => @imagecreatefrompng($soubor),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($soubor) : false,
            default => false,
        };
        if ($obr === false) {
            return null;
        }
        $bod = imagecreatetruecolor(1, 1);
        imagefill($bod, 0, 0, imagecolorallocate($bod, 255, 255, 255)); // průhledné PNG na bílém podkladu
        imagecopyresampled($bod, $obr, 0, 0, 0, 0, 1, 1, imagesx($obr), imagesy($obr));
        $rgb = imagecolorat($bod, 0, 0);

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    /** Velikosti ikony webu: karta prohlížeče, plocha iPhonu, Android a instalace webu. */
    public const array IKONY = [32, 180, 192, 512];

    /**
     * Čtvercové PNG ikony webu (media/ikona-<n>.png) z obrázku z Médií: ořízne střed na čtverec a zmenší.
     * Vrací false, když zdroj není rastrový obrázek (SVG ikona se pak použije jen jako rel=icon).
     */
    public static function ikony(string $zdroj): bool
    {
        $typ = is_file($zdroj) ? @getimagesize($zdroj) : false;
        $obr = match ($typ[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($zdroj),
            IMAGETYPE_PNG => @imagecreatefrompng($zdroj),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($zdroj) : false,
            default => false,
        };
        if ($obr === false) {
            return false;
        }
        $strana = min(imagesx($obr), imagesy($obr));
        [$x, $y] = [intdiv(imagesx($obr) - $strana, 2), intdiv(imagesy($obr) - $strana, 2)];
        foreach (self::IKONY as $n) {
            $ikona = imagecreatetruecolor($n, $n);
            imagealphablending($ikona, false);
            imagesavealpha($ikona, true);
            imagefill($ikona, 0, 0, imagecolorallocatealpha($ikona, 0, 0, 0, 127));
            imagecopyresampled($ikona, $obr, 0, 0, $x, $y, $n, $n, $strana, $strana);
            imagepng($ikona, MIROCMS_ROOT . '/media/ikona-' . $n . '.png', 9);
        }

        return true;
    }

    public static function srcset(string $cesta, string $zaklad): string
    {
        // tentýž obrázek bývá na stránce víckrát (otvírák, výpis, blok): dotazy na disk stačí jednou za požadavek
        static $pamet = [];
        if (isset($pamet[$cesta . '|' . $zaklad])) {
            return $pamet[$cesta . '|' . $zaklad];
        }
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+?)(-1200|-nahled)?\.(jpg|png|webp)$#', $cesta, $m)) {
            return '';
        }
        $varianty = [];
        foreach (['-nahled' => self::NAHLED_STRANA, '-1200' => self::STREDNI_STRANA, '' => self::MAX_STRANA] as $pripona => $sirka) {
            $soubor = $m[1] . $pripona . '.' . $m[3];
            if (is_file(MIROCMS_ROOT . '/' . $soubor)) {
                $info = $pripona === '' ? @getimagesize(MIROCMS_ROOT . '/' . $soubor) : null;
                $varianty[] = $zaklad . '/' . $soubor . ' ' . ($info ? $info[0] : $sirka) . 'w';
            }
        }

        return $pamet[$cesta . '|' . $zaklad] = count($varianty) > 1 ? implode(', ', $varianty) : '';
    }

    /** Fotky z mobilu bývají uložené naležato s příznakem otočení v EXIF. */
    private static function otocPodleExif(\GdImage $obr, string $soubor, string $pripona): \GdImage
    {
        if ($pripona !== 'jpg' || !function_exists('exif_read_data')) {
            return $obr;
        }
        $uhel = match ((int) (@exif_read_data($soubor)['Orientation'] ?? 1)) {
            3 => 180, 6 => 270, 8 => 90, default => 0,
        };

        return $uhel === 0 ? $obr : (imagerotate($obr, $uhel, 0) ?: $obr);
    }
}
