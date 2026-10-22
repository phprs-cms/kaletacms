<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Receiving uploaded images: verification, shrinking to a reasonable size, thumbnail, saving to media/YYYY/MM/.
 * The image is always re-encoded through GD - this removes EXIF (location from a phone) and any smuggled-in code.
 *
 * Every image gets variants: <name>-1200.<ext> (medium) and <name>-nahled.<ext> (640 px) for srcset,
 * and each of them a sibling <file>.webp, which the server serves to browsers with WebP support (.htaccess).
 */
final class Images
{
    public const int MAX_SIDE = 2000;
    public const int THUMBNAIL_SIDE = 640;
    public const int MEDIUM_SIDE = 1200;
    private const int MAX_BYTES = 20 * 1024 * 1024;
    private const int MAX_PIXELS = 50_000_000;

    private const array TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

    /**
     * @param array<string, mixed> $file item from $_FILES
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException with a Czech message for the user
     */
    public static function save(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => Files::limitMessage(),
                default => 'The file could not be uploaded.',
            });
        }

        return self::process((string) $file['tmp_name'], (string) ($file['name'] ?? 'obrazek'), true);
    }

    /**
     * An image that already lies on the server (downloaded during an import from WordPress): it goes the same way as an uploaded one,
     * so it is indistinguishable from it - re-encoding through GD, shrinking, thumbnail, WebP. The source file stays in place.
     *
     * With $staged (3.15) the files go to the staging area outside the web root (Core\MediaStaging) instead of media/; the
     * returned paths are the public ones the files get once approved.
     *
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException with a Czech message for the user
     */
    public static function saveFile(string $path, string $name, bool $staged = false): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The file could not be uploaded.');
        }

        return self::process($path, $name, false, $staged);
    }

    /**
     * @param bool $uploaded the file came through a form (it is moved with move_uploaded_file); otherwise it is only copied
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     */
    private static function process(string $tmp, string $fileName, bool $uploaded, bool $staged = false): array
    {
        $disk = fn (string $mediaPath): string => MediaStaging::absolute($mediaPath, $staged);
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The GD extension for image processing is missing on the server.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new \RuntimeException('Only JPG, PNG, WebP and GIF images are allowed.');
        }
        if (filesize($tmp) > self::MAX_BYTES || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('The image is too large (20 MB and 50 megapixels at most).');
        }

        $extension = self::TYPES[$info[2]];
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $folder = 'media/' . date('Y/m');
        if (!is_dir($disk($folder)) && !mkdir($disk($folder), 0775, true)) {
            throw new \RuntimeException(t('The folder %s cannot be created – check the write permissions.', $folder));
        }
        $base = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3));

        if ($extension === 'gif') {
            // a GIF can be animated - it is saved unchanged, the thumbnail is the first frame
            $target = $base . '.gif';
            if (!($uploaded ? move_uploaded_file($tmp, $disk($target)) : copy($tmp, $disk($target)))) {
                throw new \RuntimeException('The file could not be saved.');
            }
            $image = imagecreatefromgif($disk($target));
            [$w, $h] = [$info[0], $info[1]];
        } else {
            $image = @imagecreatefromstring((string) file_get_contents($tmp));
            if ($image === false) {
                throw new \RuntimeException('The image is damaged and cannot be processed.');
            }
            $image = self::rotateByExif($image, $tmp, $extension);
            $image = self::shrink($image, self::MAX_SIDE);
            [$w, $h] = [imagesx($image), imagesy($image)];
            $target = $base . '.' . $extension;
            self::write($image, $disk($target), $extension);
            self::webp($image, $disk($target), $extension);
            if (self::ratio($w, $h, self::MEDIUM_SIDE) < 1.0) {
                $medium = self::shrink($image, self::MEDIUM_SIDE);
                $mediumPath = $disk($base . '-1200.' . $extension);
                self::write($medium, $mediumPath, $extension);
                self::webp($medium, $mediumPath, $extension);
            }
        }

        $preview = self::shrink($image, self::THUMBNAIL_SIDE);
        $thumbnailExtension = $extension === 'gif' ? 'png' : $extension;
        $thumbnailPath = $base . '-nahled.' . $thumbnailExtension;
        self::write($preview, $disk($thumbnailPath), $thumbnailExtension);
        self::webp($preview, $disk($thumbnailPath), $thumbnailExtension);

        return [
            'obr_poloha' => $target, 'obr_width' => $w, 'obr_height' => $h, 'obr_vel' => (int) filesize($disk($target)),
            'nahl_poloha' => $thumbnailPath, 'nahl_width' => imagesx($preview), 'nahl_height' => imagesy($preview),
            'nazev' => mb_substr(trim(str_replace(['_', '-'], ' ', $name)), 0, 150),
        ];
    }

    /**
     * Replacing an image while keeping its URL: the new file goes through the same processing and is written in place of the old one
     * (including variants and WebP), in the format of the old file – the URL does not change, links on the site keep working.
     *
     * @param array<string, mixed> $file item from $_FILES
     * @return array{obr_width:int, obr_height:int, obr_vel:int, nahl_width:int, nahl_height:int}
     */
    public static function replace(string $old, array $file): array
    {
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp)$#D', $old, $m)) {
            throw new \RuntimeException('Only a JPG, PNG or WebP image can be replaced.');
        }
        $new = self::save($file); // verifies, shrinks and re-encodes the uploaded file
        $image = @imagecreatefromstring((string) file_get_contents(KALETA_ROOT . '/' . $new['obr_poloha']));
        self::delete($new['obr_poloha'], $new['nahl_poloha']);
        if ($image === false) {
            throw new \RuntimeException('The image is damaged and cannot be processed.');
        }
        self::delete($old, $m[1] . '-nahled.' . $m[2]);

        return self::writeInPlace($image, $m[1], $m[2]);
    }

    /**
     * An existing image made smaller in place (Media → Clean-up, 2.14): re-encoded through GD to MAX_SIDE at the usual
     * quality, with fresh variants and WebP/AVIF siblings – the URL stays, so every page that shows it keeps working.
     *
     * @return array{obr_width:int, obr_height:int, obr_vel:int, nahl_width:int, nahl_height:int}
     */
    public static function shrinkFile(string $path): array
    {
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp)$#D', $path, $m) || !is_file(KALETA_ROOT . '/' . $path)) {
            throw new \RuntimeException('Only a JPG, PNG or WebP image can be made smaller.');
        }
        $image = @imagecreatefromstring((string) file_get_contents(KALETA_ROOT . '/' . $path));
        if ($image === false) {
            throw new \RuntimeException('The image is damaged and cannot be processed.');
        }
        imagepalettetotruecolor($image);
        imagesavealpha($image, true);
        self::delete($path, $m[1] . '-nahled.' . $m[2]);

        return self::writeInPlace(self::shrink($image, self::MAX_SIDE), $m[1], $m[2]);
    }

    /**
     * Writes an image as <base>.<extension> with its medium variant, thumbnail and WebP/AVIF siblings – the second half of
     * replace() and shrinkFile(), which only differ in where the image comes from.
     *
     * @return array{obr_width:int, obr_height:int, obr_vel:int, nahl_width:int, nahl_height:int}
     */
    private static function writeInPlace(\GdImage $image, string $base, string $extension): array
    {
        $path = $base . '.' . $extension;
        self::write($image, KALETA_ROOT . '/' . $path, $extension);
        self::webp($image, KALETA_ROOT . '/' . $path, $extension);
        if (self::ratio(imagesx($image), imagesy($image), self::MEDIUM_SIDE) < 1.0) {
            $medium = self::shrink($image, self::MEDIUM_SIDE);
            self::write($medium, KALETA_ROOT . '/' . $base . '-1200.' . $extension, $extension);
            self::webp($medium, KALETA_ROOT . '/' . $base . '-1200.' . $extension, $extension);
        }
        $preview = self::shrink($image, self::THUMBNAIL_SIDE);
        self::write($preview, KALETA_ROOT . '/' . $base . '-nahled.' . $extension, $extension);
        self::webp($preview, KALETA_ROOT . '/' . $base . '-nahled.' . $extension, $extension);

        return ['obr_width' => imagesx($image), 'obr_height' => imagesy($image), 'obr_vel' => (int) filesize(KALETA_ROOT . '/' . $path),
            'nahl_width' => imagesx($preview), 'nahl_height' => imagesy($preview)];
    }

    /** Deletes the image's files; ignores paths outside media/. */
    public static function delete(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+)\.(jpg|png|webp|gif|svg)$#D', $path, $m)) {
                continue;
            }
            // together with the image its variants for srcset and WebP are removed too
            foreach ([$path, $path . '.webp', $path . '.avif', $m[1] . '-1200.' . $m[2], $m[1] . '-1200.' . $m[2] . '.webp', $m[1] . '-1200.' . $m[2] . '.avif'] as $file) {
                // also the copy waiting for approval (3.15, Core\MediaStaging)
                foreach ([KALETA_ROOT . '/' . $file, MediaStaging::absolute($file, true)] as $disk) {
                    if (is_file($disk)) {
                        unlink($disk);
                    }
                }
            }
        }
    }

    /**
     * Shrink ratio for the given side. A common image fits by its longer side; a tall one (height over twice the width – full-page
     * screenshots, infographics) is measured by width and the height can be up to three times that, otherwise only a narrow blurry strip would remain.
     */
    public static function ratio(int $w, int $h, int $pageNumber): float
    {
        if ($w < 1 || $h < 1) {
            return 1.0;
        }

        return $h > 2 * $w ? min(1.0, $pageNumber / $w, 3 * $pageNumber / $h) : min(1.0, $pageNumber / max($w, $h));
    }

    private static function shrink(\GdImage $image, int $maxSide): \GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $ratio = self::ratio($w, $h, $maxSide);
        if ($ratio >= 1.0) {
            return $image;
        }
        $new = imagecreatetruecolor(max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));
        imagealphablending($new, false);
        imagesavealpha($new, true);
        imagecopyresampled($new, $image, 0, 0, 0, 0, imagesx($new), imagesy($new), $w, $h);

        return $new;
    }

    private static function write(\GdImage $image, string $file, string $extension): void
    {
        $ok = match ($extension) {
            'jpg' => imagejpeg($image, $file, 85),
            'webp' => imagewebp($image, $file, 85),
            default => (function () use ($image, $file): bool {
                imagesavealpha($image, true);

                return imagepng($image, $file, 7);
            })(),
        };
        if (!$ok) {
            throw new \RuntimeException('The image could not be saved - check the permissions of the media/ folder.');
        }
    }

    /**
     * Smaller siblings for modern browsers: foto.jpg.webp (25–35 % smaller) and foto.jpg.avif (another ~20 %),
     * when PHP supports them. The server serves the one the browser accepts (.htaccess, nginx).
     */
    private static function webp(\GdImage $image, string $file, string $extension): void
    {
        imagepalettetotruecolor($image);
        if ($extension !== 'webp' && function_exists('imagewebp')) {
            @imagewebp($image, $file . '.webp', 82);
        }
        if (function_exists('imageavif')) {
            @imageavif($image, $file . '.avif', 55, 8); // speed 8: encoding does not slow down the upload
        }
    }

    /**
     * The srcset attribute for an image from media/ according to the existing variants; an empty string when there are none.
     *
     * @param string $path path from the site root without a leading slash (media/2026/09/foto.jpg)
     */
    /** The dominant color of the image as #rrggbb (average over the whole area); null when the file cannot be loaded. */
    public static function color(string $file): ?string
    {
        $type = is_file($file) ? @getimagesize($file) : false;
        $image = match ($type[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => false,
        };
        if ($image === false) {
            return null;
        }
        $pixel = imagecreatetruecolor(1, 1);
        imagefill($pixel, 0, 0, imagecolorallocate($pixel, 255, 255, 255)); // transparent PNG on a white background
        imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = imagecolorat($pixel, 0, 0);

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    /** Sizes of the site icon: browser tab, iPhone home screen, Android and site installation. */
    public const array ICON_SIZES = [32, 180, 192, 512];

    /**
     * Square PNG site icons (media/ikona-<n>.png) from an image in Media: crops the center to a square and shrinks it.
     * Returns false when the source is not a raster image (an SVG icon is then used only as rel=icon).
     */
    public static function icons(string $source): bool
    {
        $type = is_file($source) ? @getimagesize($source) : false;
        $image = match ($type[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if ($image === false) {
            return false;
        }
        $pageNumber = min(imagesx($image), imagesy($image));
        [$x, $y] = [intdiv(imagesx($image) - $pageNumber, 2), intdiv(imagesy($image) - $pageNumber, 2)];
        foreach (self::ICON_SIZES as $n) {
            $icon = imagecreatetruecolor($n, $n);
            imagealphablending($icon, false);
            imagesavealpha($icon, true);
            imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
            imagecopyresampled($icon, $image, 0, 0, $x, $y, $n, $n, $pageNumber, $pageNumber);
            imagepng($icon, KALETA_ROOT . '/media/ikona-' . $n . '.png', 9);
        }

        return true;
    }

    public static function srcset(string $path, string $base): string
    {
        // the same image is often on a page several times (lead image, list, block): disk lookups once per request are enough
        static $cache = [];
        if (isset($cache[$path . '|' . $base])) {
            return $cache[$path . '|' . $base];
        }
        if (!preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+?)(-1200|-nahled)?\.(jpg|png|webp)$#D', $path, $m)) {
            return '';
        }
        $variants = [];
        $widths = [];
        foreach (['-nahled', '-1200', ''] as $extension) {
            $file = $m[1] . $extension . '.' . $m[3];
            // the actual width of each variant: earlier images were shrunk by the longer side, so for tall images „1200“ did not mean the width
            $info = Files::isFile(KALETA_ROOT . '/' . $file) ? @getimagesize(KALETA_ROOT . '/' . $file) : false; // a 4 000-character address in a news text (N3182-9)
            if ($info !== false && !isset($widths[$info[0]])) {
                $widths[$info[0]] = true;
                $variants[] = $base . '/' . $file . ' ' . $info[0] . 'w';
            }
        }

        return $cache[$path . '|' . $base] = count($variants) > 1 ? implode(', ', $variants) : '';
    }

    /** Photos from a phone are often stored lying sideways with a rotation flag in EXIF. */
    private static function rotateByExif(\GdImage $image, string $file, string $extension): \GdImage
    {
        if ($extension !== 'jpg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $angle = match ((int) (@exif_read_data($file)['Orientation'] ?? 1)) {
            3 => 180, 6 => 270, 8 => 90, default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
