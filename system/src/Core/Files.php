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
     * $extension = the type the file claims (a temporary upload has none in its path); without fileinfo a file the
     * signature check cannot confirm for it counts as forbidden (3.46.2).
     */
    public static function forbiddenContent(string $path, string $extension = ''): bool
    {
        $type = self::mimeOf($path, $extension);

        return $type === '' || preg_match(self::FORBIDDEN_TYPES, $type) === 1;
    }

    /**
     * 3.50 L10: may a visitor's file of a type the signature list cannot confirm (FileSignature::TYPES lacks it – a drawing,
     * a HEIC photo) be kept? Refused only on positive evidence: finfo names HTML, PHP, a script, SVG or a program, or the
     * bytes start like a program. A type the list knows goes through forbiddenContent() instead (enquiryUploadAccepted).
     */
    public static function enquiryUploadAccepted(string $path, string $extension): bool
    {
        $extension = strtolower($extension);
        if (isset(FileSignature::TYPES[$extension])) {
            return !self::forbiddenContent($path, $extension);
        }
        $type = self::mimeOf($path, $extension);

        return !FileSignature::isProgram((string) @file_get_contents($path, false, null, 0, 8)) && ($type === '' || preg_match(self::FORBIDDEN_TYPES, $type) !== 1);
    }

    /**
     * Test seam (3.46.2): true = work as if the fileinfo extension were missing. Honoured only on a test copy
     * (Core\TestSeams); the smoke test sets the switch KALETA_NO_FILEINFO on a server of its own instead.
     */
    public static bool $withoutFileinfo = false;

    /** Whether the fileinfo extension is there to ask (and not switched off for a test). */
    public static function hasFileinfo(): bool
    {
        if (TestSeams::enabled() && (self::$withoutFileinfo || TestSeams::on('KALETA_NO_FILEINFO'))) {
            return false;
        }

        return class_exists(\finfo::class);
    }

    /**
     * The one place that asks what a file is (3.46.2): the content type finfo detects, lower-case – or, when the server lacks
     * fileinfo or finfo fails (an unreadable magic database warns, and warnings crash here), the type for the claimed
     * extension that the signature allowlist confirms (Core\FileSignature), '' when it cannot. $extension defaults to the
     * path's own.
     */
    public static function mimeOf(string $path, string $extension = ''): string
    {
        $extension = strtolower($extension !== '' ? $extension : pathinfo($path, PATHINFO_EXTENSION));
        if (self::hasFileinfo()) {
            try {
                $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            } catch (\Throwable) {
                $type = false;
            }
            if (is_string($type) && $type !== '') {
                return strtolower($type);
            }
        }

        return FileSignature::sniff($path, $extension);
    }

    /** The longest file path asked of the disk – the lower of 1024 bytes and the platform's maximum. */
    public const int MAX_PATH = 1024;

    /**
     * is_file() for a path built from content (a link, a menu icon): a path over the platform's maximum makes PHP warn under
     * open_basedir, and the error handler turns the warning into a crash (3.18.2, N3182-3) – such a path is simply no file.
     */
    public static function isFile(string $path): bool
    {
        return strlen($path) < min(self::MAX_PATH, PHP_MAXPATHLEN - 1) && !str_contains($path, "\0") && is_file($path); // 1023 bytes still warn where the maximum is 1024 (N3182-11)
    }

    /** How many bytes one uploaded file can have: the smaller of upload_max_filesize and post_max_size (0 = no limit). */
    public static function limit(): int
    {
        $limits = array_filter([self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size'))]);

        return $limits === [] ? 0 : min($limits);
    }

    /** A size from php.ini ("8M", "512K", "1G") in bytes; 0 = no limit. */
    private static function iniBytes(string $ini): int
    {
        $number = (int) $ini;

        return $number <= 0 ? 0 : $number * match (strtolower(substr(trim($ini), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }

    /** The largest request body the server accepts (post_max_size) in bytes, 0 = no limit (3.47). */
    public static function postLimit(): int
    {
        return self::iniBytes((string) ini_get('post_max_size'));
    }

    /**
     * Was a request body over post_max_size (3.47)? PHP then empties $_POST and $_FILES, and a form would look as if nothing
     * was sent. Equal is still accepted; 0 = no limit. Pure.
     */
    public static function overPostLimit(int $contentLength, int $postMax): bool
    {
        return $postMax > 0 && $contentLength > $postMax;
    }

    /** The limit for humans: „2 MB“ instead of the shorthand from php.ini („2M“). */
    public static function limitText(): string
    {
        return self::megabytes(self::limit());
    }

    /** 3.49 A: the request limit (post_max_size) for humans, as limitText(). */
    public static function postLimitText(): string
    {
        return self::megabytes(self::postLimit());
    }

    /** Bytes as whole megabytes when they are whole („8 MB“), else with one decimal in the language's style. */
    private static function megabytes(int $bytes): string
    {
        $mb = $bytes / 1024 / 1024;

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
    public static function saveFile(string $path, string $displayName, bool $staged = false): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The file could not be uploaded.');
        }

        return self::process($path, $displayName, false, $staged);
    }

    /**
     * $staged (3.15): into the staging area outside the web root (Core\MediaStaging); the path returned is the public one.
     *
     * @return array{obr_poloha:string, obr_width:int, obr_height:int, obr_vel:int, nahl_poloha:string, nahl_width:int, nahl_height:int, nazev:string}
     */
    private static function process(string $tmp, string $displayName, bool $uploaded, bool $staged = false): array
    {
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::FILE_EXTENSIONS, true)) {
            throw new \RuntimeException(t('This file type cannot be uploaded. Images and these attachments are allowed: %s.', implode(', ', self::FILE_EXTENSIONS)));
        }
        if (self::forbiddenContent($tmp, $extension) || filesize($tmp) > self::MAX_BYTES) {
            throw new \RuntimeException(filesize($tmp) > self::MAX_BYTES ? 'The file is too large (200 MB at most).' : 'The file content does not match its extension.');
        }
        $folder = 'media/' . date('Y/m');
        if (!is_dir(MediaStaging::absolute($folder, $staged)) && !mkdir(MediaStaging::absolute($folder, $staged), 0775, true)) {
            throw new \RuntimeException(t('The folder %s cannot be created – check the write permissions.', $folder));
        }
        $name = pathinfo($displayName, PATHINFO_FILENAME);
        $target = $folder . '/' . slugify($name, 60) . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        $disk = MediaStaging::absolute($target, $staged);
        if (!($uploaded ? move_uploaded_file($tmp, $disk) : copy($tmp, $disk))) {
            throw new \RuntimeException('The file could not be saved.');
        }

        // an attachment is recognized in the media table by an empty thumbnail and zero dimensions
        return ['obr_poloha' => $target, 'obr_width' => 0, 'obr_height' => 0, 'obr_vel' => (int) filesize($disk),
            'nahl_poloha' => '', 'nahl_width' => 0, 'nahl_height' => 0, 'nazev' => mb_substr($name, 0, 150)];
    }

    /**
     * Deletes an attachment's file. 3.50 (N154): also one with another name – an import of a Kaleta export keeps the names of
     * the archive (media/folder/Price_list.pdf) – when SiteImport::mediaTarget() keeps the path as it is; removeFromMedia()
     * deletes only a file inside media/ (realpath), never a folder. stillOnDisk() tells the caller whether it is gone.
     */
    public static function delete(string $path): void
    {
        if (!preg_match('#^media/\d{4}/\d{2}/[a-z0-9-]+\.(' . implode('|', self::FILE_EXTENSIONS) . ')$#', $path) && !self::importedName($path)) {
            return;
        }
        self::removeFromMedia($path);
    }

    /** A media path in the form an import of a Kaleta export writes (3.50, N154): SiteImport::mediaTarget() keeps it unchanged. */
    public static function importedName(string $path): bool
    {
        return SiteImport::mediaTarget($path) === $path;
    }

    /**
     * Removes a file of media/ and its copy waiting for approval (3.15, Core\MediaStaging) – only when the path resolves
     * (realpath) to a file inside media/ or the staging folder: never a folder, never what a symbolic link outside points at
     * (3.50, N154).
     */
    public static function removeFromMedia(string $path): void
    {
        foreach ([[KALETA_ROOT . '/' . $path, KALETA_ROOT . '/media'], [MediaStaging::absolute($path, true), KALETA_ROOT . '/' . MediaStaging::FOLDER]] as [$disk, $root]) {
            if (self::isFile($disk) && self::inside($disk, $root)) {
                unlink($disk);
            }
        }
    }

    /**
     * Is the media file still on the disk (3.50, N154)? Then its row in Media stays – a deleted row with its file left behind
     * would be a file nobody can find or remove any more.
     */
    public static function stillOnDisk(string $path): bool
    {
        return str_starts_with($path, 'media/') && !str_contains($path, '..') && self::isFile(KALETA_ROOT . '/' . $path);
    }

    /** Does the file resolve (symbolic links followed) to a file inside the folder? */
    private static function inside(string $disk, string $folder): bool
    {
        $real = realpath($disk);
        $base = realpath($folder);

        return $real !== false && $base !== false && is_file($real) && str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    }

    public static function size(int $byteCount): string
    {
        return $byteCount >= 1048576 ? format_number($byteCount / 1048576) . ' MB' : max(1, (int) round($byteCount / 1024)) . ' kB';
    }
}
