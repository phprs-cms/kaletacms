<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Db;
use Kaleta\Core\Images;

/**
 * Less page jumping while loading (CLS): adds dimensions and the dominant color, as a background until the photo loads,
 * to images from Media in the finished HTML. It is done in one place over the resulting HTML, so it applies in all
 * layouts, custom ones included. The color is computed once (on first display) and stored with the image.
 */
final class ImageHtml
{
    /** At most this many colors are computed in one request – older sites are filled in gradually. */
    private const int PER_REQUEST = 6;

    /**
     * width and height attributes for a logo that is an SVG file from Media or image/ (3.5): complete() reads raster images from the
     * media table, an SVG has its size in the file. Explicit width and height of the root element are its own size (the
     * same as without the attributes); with only a viewBox the image has no size of its own (Chrome draws such a logo in
     * the built-in header 0 px wide), so the viewBox's proportions are scaled to a height of 88 px – the logo's CSS caps the
     * height (2.75rem), only the proportions matter. Anything else (an address, an unreadable file) gives ''.
     */
    public static function logoSize(string $logo): string
    {
        if (!preg_match('#^/?((?:media|image)/[A-Za-z0-9/_.-]+\.svg)$#iD', $logo, $m) || str_contains($m[1], '..') || !is_file(KALETA_ROOT . '/' . $m[1])) {
            return '';
        }

        return self::svgSize((string) file_get_contents(KALETA_ROOT . '/' . $m[1], false, null, 0, 8192));
    }

    /** The attributes of logoSize() from the start of an SVG file (its root element). */
    public static function svgSize(string $svg): string
    {
        if (!preg_match('#<svg\b[^>]*>#i', $svg, $tag)) {
            return '';
        }
        $attribute = fn (string $name): string => preg_match('#\s' . $name . '\s*=\s*(["\'])\s*([^"\']*?)\s*\1#i', $tag[0], $a) ? $a[2] : '';
        [$width, $height] = [$attribute('width'), $attribute('height')];
        if (preg_match('#^\d+(\.\d+)?(px)?$#', $width) && preg_match('#^\d+(\.\d+)?(px)?$#', $height) && (float) $width > 0 && (float) $height > 0) {
            return ' width="' . (int) round((float) $width) . '" height="' . (int) round((float) $height) . '"';
        }
        $box = preg_split('#[\s,]+#', trim($attribute('viewBox'))) ?: [];
        if (count($box) !== 4 || !is_numeric($box[2]) || !is_numeric($box[3]) || (float) $box[2] <= 0 || (float) $box[3] <= 0) {
            return '';
        }

        return ' width="' . max(1, (int) round(88 * (float) $box[2] / (float) $box[3])) . '" height="88"';
    }

    /**
     * Addresses of Media written relatively in formatted text (upload_file gives media/…, an import or an editor keeps it):
     * on a page one level deep (/realizace/kuchyne) src="media/…" would point to /realizace/media/… and end in a 404. Every
     * src, href, poster and srcset starting with media/ gets the root of the site in the finished HTML.
     */
    public static function rootMedia(string $html, string $base): string
    {
        if (!str_contains($html, '"media/') && !str_contains($html, ', media/')) {
            return $html;
        }

        return (string) preg_replace_callback('#(\s(?:src|href|poster|srcset)=")([^"]*)"#i', function (array $m) use ($base): string {
            if (!str_contains($m[2], 'media/')) {
                return $m[0];
            }
            $value = str_starts_with(strtolower(ltrim($m[1])), 'srcset')
                ? (string) preg_replace('#(^|,\s*)media/#', '$1' . $base . '/media/', $m[2])
                : (str_starts_with($m[2], 'media/') ? $base . '/' . $m[2] : $m[2]);

            return $m[1] . $value . '"';
        }, $html);
    }

    public static function complete(Db $db, string $html): string
    {
        if (!preg_match_all('#<img\b[^>]*?\bsrc="[^"]*?(media/\d{4}/\d{2}/[a-z0-9-]+?)(?:-1200|-nahled)?\.(jpg|png|webp)"#i', $html, $found, PREG_SET_ORDER)) {
            return $html;
        }
        $paths = array_values(array_unique(array_map(fn (array $m): string => $m[1] . '.' . strtolower($m[2]), $found)));
        $known = [];
        foreach ($db->all('SELECT ido, obr_poloha, obr_width, obr_height, nahl_poloha, barva, ohnisko FROM {media} WHERE obr_poloha IN (' . implode(',', array_fill(0, count($paths), '?')) . ')', $paths) as $o) {
            $known[$o['obr_poloha']] = $o;
        }
        $computed = 0;
        foreach ($known as $path => $o) {
            if ($o['barva'] === '' && $computed < self::PER_REQUEST) {
                $computed++;
                $known[$path]['barva'] = Images::color(KALETA_ROOT . '/' . ($o['nahl_poloha'] !== '' ? $o['nahl_poloha'] : $path)) ?: '-';
                $db->update('media', ['barva' => $known[$path]['barva']], ['ido' => $o['ido']]); // „-“ = cannot be determined, do not try again
            }
        }

        return preg_replace_callback('#<img\b([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[a-z0-9-]+?)(?:-1200|-nahled)?\.(jpg|png|webp))"([^>]*)>#i', function (array $m) use ($known): string {
            $o = $known[$m[3] . '.' . strtolower($m[4])] ?? null;
            $attributes = $m[1] . $m[5];
            if ($o === null) {
                return $m[0];
            }
            $toAdd = '';
            if ((int) $o['obr_width'] > 0 && (int) $o['obr_height'] > 0 && !preg_match('#\b(width|height)=#i', $attributes)) {
                $toAdd .= ' width="' . (int) $o['obr_width'] . '" height="' . (int) $o['obr_height'] . '"';
            }
            // background color only for photos: a PNG is often a logo or an illustration with transparency and a colored
            // rectangle would show through behind it;
            // focal point: where the crop centers when the photo fills a different shape (object-fit: cover)
            $style = (strtolower($m[4]) !== 'png' && preg_match('/^#[0-9a-f]{6}$/D', (string) $o['barva']) ? 'background-color:' . $o['barva'] . ';' : '')
                . (preg_match('/^\d{1,3}% \d{1,3}%$/D', (string) ($o['ohnisko'] ?? '')) ? 'object-position:' . $o['ohnisko'] . ';' : '');
            if ($style !== '' && !preg_match('#\bstyle=#i', $attributes)) {
                $toAdd .= ' style="' . $style . '"';
            } elseif ($style !== '' && preg_match('#\bstyle="([^"]*)"#i', $m[1] . $m[5])) {
                // the image already has a style (gallery: aspect ratio) – the focal point is appended
                [$m[1], $m[5]] = array_map(fn (string $x): string => (string) preg_replace('#\bstyle="([^"]*)"#i', 'style="$1;' . $style . '"', $x, 1), [$m[1], $m[5]]);
            }

            return $toAdd === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '"' . $toAdd . $m[5] . '>';
        }, $html) ?? $html;
    }
}
