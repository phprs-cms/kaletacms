<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The download links of uploaded files in the notification e-mails of forms (3.46.2): a link to the existing download of
 * the record in the administration (Applications action attachment, Enquiries action attachment), signed with the site's
 * secret key (HMAC, as Core\Preview) over the section, the record, the file and the expiry. No file is ever attached to an
 * e-mail.
 *
 * The signature never serves a file by itself: the download still needs a signed-in user with the section (and, for an
 * enquiry, the record in their view) – it only lets a link stop working after DAYS days and refuses a link somebody
 * changed. A user who is not signed in comes back to the link after signing in (Admin\Kernel::rememberLink).
 */
final class FileLink
{
    /** How long a link in an e-mail works. */
    public const int DAYS = 30;

    /** section => the query parameter that names the file in its download (the existing actions). */
    public const array SECTIONS = ['applications' => 'index', 'enquiries' => 'field'];

    /** The signature of a link (pure). */
    public static function signature(string $key, string $section, int $id, int $file, int $expires): string
    {
        return hash_hmac('sha256', 'file-link|' . $section . '|' . $id . '|' . $file . '|' . $expires, $key);
    }

    /**
     * What a link is worth (pure): ok, expired (a true signature past its day) or invalid (a changed or made-up link, an
     * unknown section). The expiry counts only once the signature holds, so nobody learns anything from a forged date.
     */
    public static function check(string $key, string $section, int $id, int $file, int $expires, string $signature, int $now): string
    {
        if (!isset(self::SECTIONS[$section]) || $id <= 0 || $file < 0 || $key === ''
            || !hash_equals(self::signature($key, $section, $id, $file, $expires), $signature)) {
            return 'invalid';
        }

        return $expires < $now ? 'expired' : 'ok';
    }

    /** The administration path of a link (pure): admin.php?module=<section>&action=attachment&id=…&<index|field>=…&expires=…&sig=… */
    public static function path(string $key, string $section, int $id, int $file, int $expires): string
    {
        return 'admin.php?' . http_build_query(['module' => $section, 'action' => 'attachment', 'id' => $id, self::SECTIONS[$section] => $file,
            'expires' => $expires, 'sig' => self::signature($key, $section, $id, $file, $expires)]);
    }

    /** The absolute link for an e-mail, valid DAYS days from now. */
    public static function url(App $app, string $section, int $id, int $file): string
    {
        $base = rtrim($app->settings()->get('site_url') !== '' ? $app->settings()->get('site_url') : $app->request->origin(), '/');

        return $base . $app->url(self::path(self::key($app), $section, $id, $file, time() + self::DAYS * 86400));
    }

    /** The secret the links are signed with (the site's signing key, Core\Antispam). */
    public static function key(App $app): string
    {
        return (new Antispam($app->db(), $app->settings()))->key();
    }

    /**
     * The link of the current request, rebuilt from its own parameters only – what the sign-in comes back to; null when the
     * request is no signed file link. Pure apart from reading the given parameters.
     *
     * @param array<string, mixed> $query the query of the request
     */
    public static function fromQuery(array $query): ?string
    {
        $section = is_string($query['module'] ?? null) ? $query['module'] : '';
        if (!isset(self::SECTIONS[$section]) || ($query['action'] ?? '') !== 'attachment') {
            return null;
        }
        $number = fn (string $name): ?int => is_string($query[$name] ?? null) && preg_match('/^\d{1,12}$/D', $query[$name]) === 1 ? (int) $query[$name] : null;
        [$id, $file, $expires] = [$number('id'), $number(self::SECTIONS[$section]), $number('expires')];
        $signature = is_string($query['sig'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $query['sig']) === 1 ? $query['sig'] : null;
        if ($id === null || $file === null || $expires === null || $signature === null) {
            return null;
        }

        return 'admin.php?' . http_build_query(['module' => $section, 'action' => 'attachment', 'id' => $id, self::SECTIONS[$section] => $file, 'expires' => $expires, 'sig' => $signature]);
    }

    /**
     * 3.50 L9: the link of the request as the sign-in may come back to it – only a link whose signature holds and whose
     * day has not passed (check() = ok); null for anything else, so a made-up or dead link never waits in the session.
     * Pure apart from reading the given parameters.
     *
     * @param array<string, mixed> $query the query of the request
     */
    public static function rememberable(string $key, array $query, int $now): ?string
    {
        $link = self::fromQuery($query);
        if ($link === null) {
            return null;
        }
        $section = (string) $query['module'];

        return self::check($key, $section, (int) $query['id'], (int) $query[self::SECTIONS[$section]], (int) $query['expires'], (string) $query['sig'], $now) === 'ok' ? $link : null;
    }

    /** A file name that says nothing of the person (pure): "<kind>-<record>-<file number>.<extension of the original>". */
    public static function neutralName(string $kind, int $id, int $file, string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        return $kind . '-' . $id . '-' . ($file + 1) . (preg_match('/^[a-z0-9]{1,5}$/D', $extension) === 1 ? '.' . $extension : '');
    }

    /**
     * The lines of the files for a notification e-mail: "<label>: <name>" and the link under it, then a note that the links
     * need signing in and stop working after DAYS days; '' without files.
     *
     * @param list<array{label: string, name: string, url: string}> $files
     */
    public static function text(array $files): string
    {
        if ($files === []) {
            return '';
        }
        $lines = array_map(fn (array $f): string => ($f['label'] !== '' ? $f['label'] . ': ' : '') . $f['name'] . "\n" . $f['url'], $files);

        return t('Uploaded files') . ":\n" . implode("\n\n", $lines) . "\n" . t('The links open the files only for a colleague signed in to the administration with access to the record; a forwarded copy of a link stops working after %d days.', self::DAYS);
    }
}
