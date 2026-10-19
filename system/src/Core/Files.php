<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Downloadable attachments in Media: PDF, documents, spreadsheets, audio and video. Images are handled by Core\Images.
 *
 * Only the listed extensions are allowed; the file gets a new safe name and is never executed
 * (media/.htaccess). HTML, SVG and scripts cannot be uploaded - the browser would open them as part of the site.
 */
final class Files
{
    public const array FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'zip', 'epub', 'gpx', 'ics', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'webm', 'woff2', 'woff'];
    private const int MAX_BYTES = 200 * 1024 * 1024;
    private const string FORBIDDEN_TYPES = '#html|php|javascript|svg|x-sh|x-dosexec|x-executable|x-mach|x-msdownload#i';

    /**
     * Does the content say HTML, PHP, a script, SVG or a program, whatever the extension claims? The browser could open such
     * a file as part of the site, or the server run it. Shared by uploads and the import of a Kaleta export (3.15, N72).
     */
    public static function forbiddenContent(string $path): bool
    {
        return preg_match(self::FORBIDDEN_TYPES, (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path)) === 1;
    }

    /** How many bytes one uploaded file can have: the smaller of upload_max_filesize and post_max_size (0 = no limit). */
    public static function limit(): int
    {
        $bytes = static function (string $ini): int {
            $number = (int) $ini;

            return $number <= 0 ? 0 : $number * match (strtolower(substr(trim($ini), -1))) {
                'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
            };
        };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limits === [] ? 0 : min($limits);
    }

    /** The limit for humans: „2 MB“ instead of the shorthand from php.ini („2M“). */
    public static function limitText(): string
    {
        $mb = self::limit() / 1024 / 1024;

        return $mb <= 0 ? '' : (fmod($mb, 1.0) === 0.0 ? (string) (int) $mb : number_format($mb, 1, Language::code() === 'cs' ? ',' : '.', '')) . ' MB';
    }

    /** Message for a file over the server limit – translated, with the limit in MB and advice on what to do. */
    public static function limitMessage(): string
    {
        return t('The file is larger than the server allows (%s at most). Make it smaller or ask your hosting provider to raise the limit.', self::limitText());
    }

    public static function isAttachment(string $displayName): bool
    {
        return in_array(strtolower(pathinfo($displayName, PATHINFO_EXTENSION)), self::FILE_EXTENSIONS, true);
    }

    /**
     * @param array<string, mixed> $file item from $_FILES
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     * @throws \RuntimeException with a Czech message for the user
     */
    public static function save(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::limitMessage(),
                default => 'The file could not be uploaded.',
            });
        }

        return self::process((string) $file['tmp_name'], (string) ($file['name'] ?? ''), true);
    }

    /**
     * An attachment from a file already on disk (MCP, import) – the source stays, a copy is saved.
     *
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     */
    public static function saveFile(string $path, string $displayName): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The file could not be uploaded.');
        }

        return self::process($path, $displayName, false);
    }

    /** @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string} */
    private static function process(string $tmp, string $displayName, bool $uploaded): array
    {
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::FILE_EXTENSIONS, true)) {
            throw new \RuntimeException(t('This file type cannot be uploaded. Images and these attachments are allowed: %s.', implode(', ', self::FILE_EXTENSIONS)));
        }
        if (self::forbiddenContent($tmp) || filesize($tmp) > self::MAX_BYTES) {
            throw new \RuntimeException(filesize($tmp) > self::MAX_BYTES ? 'The file is too large (200 MB at most).' : 'The file content does not match its extension.');
        }
        $folder = 'media/' . date('Y/m');
        if (!is_dir(KALETA_ROOT . '/' . $folder) && !mkdir(KALETA_ROOT . '/' . $folder, 0775, true)) {
            throw new \RuntimeException(t('The folder %s cannot be created – check the write permissions.', $folder));
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $target = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        if (!($uploaded ? move_uploaded_file($tmp, KALETA_ROOT . '/' . $target) : copy($tmp, KALETA_ROOT . '/' . $target))) {
            throw new \RuntimeException('The file could not be saved.');
        }

        // an attachment is recognized in the media table by an empty thumbnail and zero dimensions
        return ['obr_poloha' => $target, 'obr_width' => 0, 'obr_height' => 0, 'obr_vel' => (int) filesize(KALETA_ROOT . '/' . $target),
            'nahl_poloha' => '', 'nahl_width' => 0, 'nahl_height' => 0, 'nazev' => mb_substr($name, 0, 150)];
    }

    public static function delete(string $path): void
    {
        if (preg_match('#^media/\d{4}/\d{2}/[a-z0-9-]+\.(' . implode('|', self::FILE_EXTENSIONS) . ')$#', $path) && is_file(KALETA_ROOT . '/' . $path)) {
            unlink(KALETA_ROOT . '/' . $path);
        }
    }

    public static function size(int $byteCount): string
    {
        return $byteCount >= 1048576 ? format_number($byteCount / 1048576) . ' MB' : max(1, (int) round($byteCount / 1024)) . ' kB';
    }
}
