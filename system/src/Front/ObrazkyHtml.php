<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Db;
use Kaleta\Core\Obrazky;

/**
 * Méně poskakování stránky při načítání (CLS): obrázkům z Médií doplní do hotového HTML rozměry a převládající barvu
 * jako podklad, než se fotka načte. Dělá se na jednom místě nad výsledným HTML, takže to platí ve všech šablonách
 * včetně vlastních. Barva se počítá jednou (při prvním zobrazení) a ukládá k obrázku.
 */
final class ObrazkyHtml
{
    /** Nejvýš tolik barev se dopočítá při jednom požadavku – starší weby se doplní postupně. */
    private const int NAJEDNOU = 6;

    public static function dopln(Db $db, string $html): string
    {
        if (!preg_match_all('#<img\b[^>]*?\bsrc="[^"]*?(media/\d{4}/\d{2}/[a-z0-9-]+?)(?:-1200|-nahled)?\.(jpg|png|webp)"#i', $html, $nalezene, PREG_SET_ORDER)) {
            return $html;
        }
        $cesty = array_values(array_unique(array_map(fn (array $m): string => $m[1] . '.' . strtolower($m[2]), $nalezene)));
        $zname = [];
        foreach ($db->all('SELECT ido, obr_poloha, obr_width, obr_height, nahl_poloha, barva, ohnisko FROM {media} WHERE obr_poloha IN (' . implode(',', array_fill(0, count($cesty), '?')) . ')', $cesty) as $o) {
            $zname[$o['obr_poloha']] = $o;
        }
        $pocitano = 0;
        foreach ($zname as $cesta => $o) {
            if ($o['barva'] === '' && $pocitano < self::NAJEDNOU) {
                $pocitano++;
                $zname[$cesta]['barva'] = Obrazky::barva(KALETA_ROOT . '/' . ($o['nahl_poloha'] !== '' ? $o['nahl_poloha'] : $cesta)) ?: '-';
                $db->update('media', ['barva' => $zname[$cesta]['barva']], ['ido' => $o['ido']]); // „-“ = nejde zjistit, znovu nezkoušet
            }
        }

        return preg_replace_callback('#<img\b([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[a-z0-9-]+?)(?:-1200|-nahled)?\.(jpg|png|webp))"([^>]*)>#i', function (array $m) use ($zname): string {
            $o = $zname[$m[3] . '.' . strtolower($m[4])] ?? null;
            $atributy = $m[1] . $m[5];
            if ($o === null) {
                return $m[0];
            }
            $pridat = '';
            if ((int) $o['obr_width'] > 0 && (int) $o['obr_height'] > 0 && !preg_match('#\b(width|height)=#i', $atributy)) {
                $pridat .= ' width="' . (int) $o['obr_width'] . '" height="' . (int) $o['obr_height'] . '"';
            }
            // podkladová barva jen u fotek: PNG bývá logo nebo ilustrace s průhledností a barevný obdélník by za ní prosvítal;
            // ohnisko: kam se soustředí ořez, když fotka vyplňuje jiný tvar (object-fit: cover)
            $styl = (strtolower($m[4]) !== 'png' && preg_match('/^#[0-9a-f]{6}$/', (string) $o['barva']) ? 'background-color:' . $o['barva'] . ';' : '')
                . (preg_match('/^\d{1,3}% \d{1,3}%$/', (string) ($o['ohnisko'] ?? '')) ? 'object-position:' . $o['ohnisko'] . ';' : '');
            if ($styl !== '' && !preg_match('#\bstyle=#i', $atributy)) {
                $pridat .= ' style="' . $styl . '"';
            } elseif ($styl !== '' && preg_match('#\bstyle="([^"]*)"#i', $m[1] . $m[5])) {
                // obrázek už styl má (galerie: poměr stran) – ohnisko se připojí
                [$m[1], $m[5]] = array_map(fn (string $x): string => (string) preg_replace('#\bstyle="([^"]*)"#i', 'style="$1;' . $styl . '"', $x, 1), [$m[1], $m[5]]);
            }

            return $pridat === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '"' . $pridat . $m[5] . '>';
        }, $html) ?? $html;
    }
}
