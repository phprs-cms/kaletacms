<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The PHP upload limit (3.22, C14): how to raise it on the server the site runs on, and a .user.ini with larger limits
 * written by Kaleta itself where PHP reads one (PHP-FPM and CGI hosts) – at installation and from System status.
 * A .user.ini that exists is never overwritten, and a limit that is already larger is never lowered.
 */
final class UploadLimit
{
    /** The values Kaleta asks for: a phone photo or a PDF fits, several of them fit in one upload. */
    public const array WANTED = ['upload_max_filesize' => '16M', 'post_max_size' => '20M'];

    /** The first line of a file Kaleta wrote – System status tells it from one written by hand. */
    public const string MARK = '; Written by Kaleta';

    /** Where the limits are set on this server: user_ini (PHP-FPM, CGI), htaccess (Apache mod_php, LiteSpeed) or php_ini. */
    public static function environment(string $sapi = PHP_SAPI): string
    {
        return match (true) {
            self::readsUserIni($sapi) => 'user_ini',
            in_array($sapi, ['apache2handler', 'litespeed'], true) => 'htaccess',
            default => 'php_ini',
        };
    }

    /** Does PHP read a .user.ini in the site's folder (only the CGI and FastCGI servers do, and only when the name is set)? */
    public static function readsUserIni(string $sapi = PHP_SAPI): bool
    {
        return in_array($sapi, ['cgi-fcgi', 'fpm-fcgi'], true) && self::fileName() !== '';
    }

    /** The name PHP looks for (user_ini.filename, .user.ini by default); '' = switched off on this server. */
    public static function fileName(): string
    {
        return basename(trim((string) ini_get('user_ini.filename')));
    }

    /** How long the host keeps the previous values before it reads the file again (user_ini.cache_ttl, 300 s by default). */
    public static function cacheSeconds(): int
    {
        $ttl = (int) ini_get('user_ini.cache_ttl');

        return $ttl > 0 ? $ttl : 300;
    }

    /**
     * The lines of the file: only the limits that are smaller than the wanted ones now – a larger limit stays as it is.
     *
     * @param array<string, string>|null $current upload_max_filesize and post_max_size as php.ini writes them (tests); null = this server's
     * @return array<string, string> directive => value
     */
    public static function missing(?array $current = null): array
    {
        $missing = [];
        foreach (self::WANTED as $directive => $value) {
            $now = self::bytes((string) ($current[$directive] ?? ini_get($directive)));
            if ($now > 0 && $now < self::bytes($value)) { // 0 = no limit at all
                $missing[$directive] = $value;
            }
        }

        return $missing;
    }

    /**
     * The content Kaleta writes.
     *
     * @param array<string, string> $lines directive => value (missing())
     */
    public static function content(array $lines): string
    {
        $text = self::MARK . ' (System status): larger uploads for phone photos and documents.' . "\n"
            . '; The host reads this file within a few minutes (user_ini.cache_ttl). Delete it to go back to the host\'s limits.' . "\n";
        foreach ($lines as $directive => $value) {
            $text .= $directive . ' = ' . $value . "\n";
        }

        return $text;
    }

    /** The file in the folder (empty when PHP reads none here). */
    public static function path(string $folder = KALETA_ROOT): string
    {
        return self::fileName() !== '' ? rtrim($folder, '/') . '/' . self::fileName() : '';
    }

    /**
     * What the file is like: none | ours (written by Kaleta) | other (written by someone else).
     */
    public static function fileState(string $folder = KALETA_ROOT): string
    {
        $file = self::path($folder);
        if ($file === '' || (!is_file($file) && !is_link($file))) {
            return 'none';
        }

        return str_starts_with((string) @file_get_contents($file, false, null, 0, 200), self::MARK) ? 'ours' : 'other';
    }

    /**
     * Can Kaleta write the file now (the button in System status)?
     *
     * @param array<string, string>|null $current see missing()
     */
    public static function canWrite(string $folder = KALETA_ROOT, string $sapi = PHP_SAPI, ?array $current = null): bool
    {
        return self::readsUserIni($sapi) && self::missing($current) !== [] && self::fileState($folder) === 'none' && is_writable($folder);
    }

    /**
     * Writes the file when it helps and is allowed: written | not_needed | unsupported | exists | not_writable | failed.
     * The file is created with the "x" mode – a file that appeared in the meantime is never overwritten either.
     *
     * @param array<string, string>|null $current see missing()
     */
    public static function write(string $folder = KALETA_ROOT, string $sapi = PHP_SAPI, ?array $current = null): string
    {
        $lines = self::missing($current);
        if ($lines === []) {
            return 'not_needed';
        }
        if (!self::readsUserIni($sapi)) {
            return 'unsupported';
        }
        if (self::fileState($folder) !== 'none') {
            return 'exists';
        }
        if (!is_dir($folder) || !is_writable($folder)) {
            return 'not_writable';
        }
        $handle = @fopen(self::path($folder), 'x');
        if ($handle === false) {
            return 'failed';
        }
        $written = fwrite($handle, self::content($lines));
        fclose($handle);

        return $written === false ? 'failed' : 'written';
    }

    /**
     * What to do about a small limit on this server, in words – the text of the warning in System status.
     *
     * @param array<string, string>|null $current see missing()
     */
    public static function advice(string $folder = KALETA_ROOT, string $sapi = PHP_SAPI, ?array $current = null): string
    {
        $values = 'upload_max_filesize = ' . self::WANTED['upload_max_filesize'] . ', post_max_size = ' . self::WANTED['post_max_size'];
        $panel = t('You can also raise both limits in the PHP settings of your hosting\'s control panel.');

        return match (self::environment($sapi)) {
            'htaccess' => t('To allow larger uploads, add these two lines to the .htaccess file in the site\'s folder: %s.', 'php_value upload_max_filesize ' . self::WANTED['upload_max_filesize'] . ', php_value post_max_size ' . self::WANTED['post_max_size']) . ' ' . $panel,
            'user_ini' => match (self::fileState($folder)) {
                'ours' => t('Kaleta has written %s with %s – the host reads it within a few minutes (user_ini.cache_ttl = %d s). If the limit stays the same after that, the host does not allow these values: raise them in the PHP settings of your hosting\'s control panel.', self::fileName(), $values, self::cacheSeconds()),
                'other' => t('To allow larger uploads, add %s to the %s file in the site\'s folder (it exists already, so Kaleta leaves it alone). The host reads it within a few minutes.', $values, self::fileName()) . ' ' . $panel,
                default => (self::canWrite($folder, $sapi, $current)
                    ? t('To allow larger uploads, Kaleta can write a %s file with %s into the site\'s folder – the button is below the table. The host reads it within a few minutes (user_ini.cache_ttl = %d s).', self::fileName(), $values, self::cacheSeconds())
                    : t('To allow larger uploads, create a %s file in the site\'s folder with %s (over FTP or in the hosting\'s file manager). The host reads it within a few minutes.', self::fileName(), $values)) . ' ' . $panel,
            },
            default => t('To allow larger uploads, set %s in the server\'s php.ini and restart PHP.', $values) . ' ' . $panel,
        };
    }

    /**
     * How many bytes of files fit in one request (post_max_size less room for the other form fields); 0 = no limit.
     * The editor sends larger sets of files in several requests (image/editor.js, data-max-davka).
     */
    public static function requestLimit(): int
    {
        $post = self::bytes((string) ini_get('post_max_size'));

        return $post <= 0 ? 0 : max(1, $post - 64 * 1024);
    }

    /** "16M" → bytes (the php.ini shorthand). */
    public static function bytes(string $ini): int
    {
        $number = (int) $ini;

        return match (strtoupper(substr(trim($ini), -1))) {
            'G' => $number * 1024 ** 3, 'M' => $number * 1024 ** 2, 'K' => $number * 1024, default => $number,
        };
    }
}
