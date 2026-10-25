<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Presets;

/**
 * Document library (2.11, preset documents): price lists, terms, manuals and forms are collection items with a file.
 *
 *  - Versions: when the file of a document changes (the admin form, save_collection_item, a restored item version), the
 *    previous file and its version number are kept in ka_document_versions for good – the item history keeps only the
 *    last few saves, a library needs every edition. {{versions}} lists them on the item page.
 *  - A stable address: /<collection>/<document>/latest redirects to the current file ({{latest}} in buttons and links), so
 *    a link printed in a brochure or sent by e-mail stays valid when the file is replaced. A hidden, expired or deleted
 *    document answers 404 – or the collection's redirect of hidden items, like its page.
 *  - Download counts: one row per document and day (ka_document_downloads) and nothing about the visitor; bots and
 *    signed-in users are not counted, one address counts once an hour per document (Core\Antispam).
 *  - Gated downloads: a Form element may e-mail a file from Media to the visitor after sending (Front\Forms). The link
 *    /download/<token> is signed with the site's secret and expires after TOKEN_DAYS. A file in Media stays reachable by
 *    its own address – gating stops casual sharing, not a determined person.
 *  - Expiry: valid_until of the item (Core\Validity) hides the document by itself; the site audit lists the documents
 *    that expire within EXPIRY_WARNING_DAYS.
 */
final class Documents
{
    public const string PRESET = 'documents';

    /** How long a gated download link works. */
    public const int TOKEN_DAYS = 7;

    /** The site audit warns this many days before a document expires. */
    public const int EXPIRY_WARNING_DAYS = 30;

    /** The key of the file field when the collection is a document library made from the preset and still has the field. */
    public static function fileField(array $collection): ?string
    {
        return Presets::field($collection, self::PRESET, 'file', ['soubor']);
    }

    /* ---------- versions ---------- */

    /**
     * The file a save replaces: [file, version] when the item is a document and its file changes to another one or is
     * removed; null when nothing is to be kept. Pure – unit-tested.
     *
     * @param array<string, string> $previousData item values before the save
     * @param array<string, string> $newData item values after it
     * @return array{0: string, 1: string}|null
     */
    public static function replacedFile(array $collection, array $previousData, array $newData): ?array
    {
        $fileKey = self::fileField($collection);
        if ($fileKey === null) {
            return null;
        }
        $old = (string) ($previousData[$fileKey] ?? '');
        if ($old === '' || $old === (string) ($newData[$fileKey] ?? '')) {
            return null;
        }
        $versionKey = Presets::field($collection, self::PRESET, 'version', ['text']);

        return [$old, $versionKey !== null ? (string) ($previousData[$versionKey] ?? '') : ''];
    }

    /**
     * Keeps the previous file of a document when a save changes it (called from Collections::saveVersion, so every way
     * of saving an item goes through it).
     *
     * @param array<string, mixed> $previous the item row before the save
     * @param array<string, mixed> $new the columns being written
     */
    public static function keepVersion(App $app, array $previous, array $new): void
    {
        if (!is_string($new['data'] ?? null) || $new['data'] === ($previous['data'] ?? null)) {
            return;
        }
        $db = $app->db();
        $collection = Collections::byId($db, (int) $previous['idk']);
        $replaced = $collection === null ? null : self::replacedFile($collection, json_decode((string) $previous['data'], true) ?: [], json_decode($new['data'], true) ?: []);
        if ($replaced === null) {
            return;
        }
        $user = $app->auth()->user();
        $who = $user === null ? '' : (string) (($user['jmeno'] ?? '') !== '' ? $user['jmeno'] : ($user['user'] ?? '')) . ($app->auth()->connection() !== null ? ' (Claude)' : '');
        $db->insert('document_versions', ['idp' => (int) $previous['idp'], 'file' => mb_substr($replaced[0], 0, 500), 'version' => mb_substr($replaced[1], 0, 100),
            'replaced_at' => date('Y-m-d H:i:s'), 'replaced_by' => mb_substr(trim($who), 0, 100)]);
    }

    /** @return list<array{file: string, version: string, replaced_at: string, replaced_by: string}> newest first */
    public static function versions(Db $db, int $idp): array
    {
        return $db->all('SELECT file, version, replaced_at, replaced_by FROM {document_versions} WHERE idp = ? ORDER BY id DESC LIMIT 100', [$idp]);
    }

    /**
     * The {{versions}} list for the item page: a heading and the previous files with their version and the day they
     * were replaced – nothing when there is none. Everything is escaped; the files link to where they are.
     *
     * @param list<array{file: string, version: string, replaced_at: string}> $versions
     */
    public static function versionsHtml(array $versions, string $basePath): string
    {
        if ($versions === []) {
            return '';
        }
        $html = '<h2>' . e(t('Previous versions')) . '</h2><ul class="ka-dokument-verze">';
        foreach ($versions as $v) {
            $label = self::fileName((string) $v['file']) . ((string) $v['version'] !== '' ? ' · ' . t('Version %s', (string) $v['version']) : '');
            $html .= '<li><a href="' . e(self::filePath((string) $v['file'], $basePath)) . '">' . e($label) . '</a> (' . e(t('replaced on %s', format_date((string) $v['replaced_at']))) . ')</li>';
        }

        return $html . '</ul>';
    }

    /* ---------- placeholders and the stable address ---------- */

    /**
     * The values a document adds to its placeholders: {{latest}} = the stable address of its current file, {{versions}} =
     * the list of previous versions (on the item page only – a list of cards would ask for it once per card). Nothing for
     * a collection that is not a document library.
     *
     * @param array<string, mixed> $item with idp, seo_link
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(App $app, array $collection, array $item, bool $withVersions = true): array
    {
        if (self::fileField($collection) === null || !$collection['detail']) {
            return [];
        }
        $values = ['latest' => [$app->url($collection['seo_link'] . '/' . $item['seo_link'] . '/latest'), 'odkaz']];
        if ($withVersions) {
            $values['versions'] = [self::versionsHtml(self::versions($app->db(), (int) $item['idp']), $app->request->basePath()), 'html'];
        }

        return $values;
    }

    /**
     * /<collection>/<document>/latest: a redirect to the current file of a visible document that has not expired, counted
     * as a download; null = not found. A hidden or expired document with a redirect of hidden items leads there, like its page.
     */
    public static function latest(App $app, string $collectionSlug, string $seo): ?Response
    {
        $db = $app->db();
        $collection = Collections::bySlug($db, $collectionSlug);
        $fileKey = $collection === null ? null : self::fileField($collection);
        if ($collection === null || $fileKey === null) {
            return null;
        }
        // the hourly job hides an expired document a little later – the address stops working on the day itself
        $item = $db->one('SELECT idp, data FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL AND (valid_until IS NULL OR valid_until >= ?)',
            [$collection['idk'], $seo, Language::siteColumn(), Clock::today()]);
        $file = $item === null ? '' : (string) ((json_decode((string) $item['data'], true) ?: [])[$fileKey] ?? '');
        if ($file === '') {
            $to = (string) ($collection['hidden_redirect'] ?? '');
            if ($to !== '' && $db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ?', [$collection['idk'], $seo]) !== null) {
                return Response::redirect(str_starts_with($to, 'https://') ? $to : $app->url(ltrim($to, '/')), 301);
            }

            return null;
        }
        self::count($app, (int) $item['idp']);

        return self::redirectToFile($app, $file);
    }

    /** The file itself is served by the web server; the redirect is never cached, so a replaced file shows at once. */
    private static function redirectToFile(App $app, string $file): Response
    {
        return new Response('', 302, ['Location' => self::fileUrl($file, $app->request), 'Cache-Control' => 'no-store']);
    }

    /** A stored file value as a path the browser can open: an https address stays, a path in Media gets the installation folder. */
    public static function filePath(string $file, string $basePath): string
    {
        return preg_match('#^https://#i', $file) === 1 || str_starts_with($file, '/') ? $file : $basePath . '/' . $file;
    }

    public static function fileUrl(string $file, Request $r): string
    {
        $path = self::filePath($file, $r->basePath());

        return preg_match('#^https://#i', $path) === 1 ? $path : $r->origin() . $path;
    }

    /** The file name a visitor sees (Ceník 2026.pdf), from a path in Media or an https address. */
    public static function fileName(string $file): string
    {
        return rawurldecode(basename((string) parse_url($file, PHP_URL_PATH)));
    }

    /* ---------- download counts ---------- */

    /** Counts a download: not a bot, not a signed-in user, one address once an hour per document; nothing about the visitor is kept. */
    public static function count(App $app, int $idp): void
    {
        $r = $app->request;
        $userAgent = (string) ($r->serverValues()['HTTP_USER_AGENT'] ?? '');
        if ($userAgent === '' || \Kaleta\Front\Stats::isBot($userAgent) || $app->auth()->user() !== null) {
            return;
        }
        $antispam = new Antispam($app->db(), $app->settings());
        if ($antispam->count($r->ip(), 'stazeni', $idp, 60) > 0) {
            return;
        }
        $antispam->write($r->ip(), 'stazeni', $idp);
        $app->db()->run('INSERT INTO {document_downloads} (idp, day, count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1', [$idp, Clock::today()]);
    }

    /**
     * Downloads of the documents of a collection: idp => [last 30 days, total].
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function counts(Db $db, int $idk): array
    {
        $out = [];
        foreach ($db->all('SELECT d.idp, SUM(d.count) AS total, SUM(IF(d.day >= ?, d.count, 0)) AS recent FROM {document_downloads} d JOIN {kolekce_polozky} p ON p.idp = d.idp WHERE p.idk = ? GROUP BY d.idp', [Clock::day('-30 days'), $idk]) as $r) {
            $out[(int) $r['idp']] = [(int) $r['recent'], (int) $r['total']];
        }

        return $out;
    }

    /* ---------- gated downloads ---------- */

    /** The file a form e-mails after sending (its poslat_soubor content), checked; '' = none. */
    public static function gatedFile(array $content): string
    {
        $file = trim((string) ($content['poslat_soubor'] ?? ''));

        return preg_match(Collections::MEDIA_PATTERN, $file) === 1 && !str_contains($file, '..') ? $file : '';
    }

    /**
     * A download token "<expiry>.<file in base64url>.<signature>": the file is inside, the signature (an HMAC with the
     * site's secret, like preview links) makes sure nobody changes it or the expiry. Pure – unit-tested.
     */
    public static function token(string $key, string $file, int $expires): string
    {
        return $expires . '.' . rtrim(strtr(base64_encode($file), '+/', '-_'), '=') . '.' . self::signature($key, $file, $expires);
    }

    /** The file of a valid, unexpired token with a file that may be served; null otherwise. */
    public static function verifyToken(string $key, string $token, ?int $now = null): ?string
    {
        if (preg_match('/^(\d{10})\.([A-Za-z0-9_-]{1,700})\.([a-f0-9]{64})$/D', $token, $m) !== 1 || (int) $m[1] < ($now ?? time())) {
            return null;
        }
        $encoded = strtr($m[2], '-_', '+/');
        $file = base64_decode($encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if ($file === false || !hash_equals(self::signature($key, $file, (int) $m[1]), $m[3])) {
            return null;
        }

        return preg_match(Collections::MEDIA_PATTERN, $file) === 1 && !str_contains($file, '..') ? $file : null;
    }

    private static function signature(string $key, string $file, int $expires): string
    {
        return hash_hmac('sha256', 'stazeni|' . $file . '|' . $expires, $key);
    }

    /** E-mails the visitor the link to the file; returns whether the e-mail went out (or waits in the mail queue). */
    public static function sendGated(App $app, string $email, string $file): bool
    {
        $s = $app->settings();
        $token = self::token((new Antispam($app->db(), $s))->key(), $file, time() + self::TOKEN_DAYS * 86400);
        $siteName = $s->get('site_name');
        $text = t('Thank you for your interest. Here is the file you asked for:') . "\n\n" . self::fileName($file) . "\n" . $app->request->origin() . $app->url('download/' . $token)
            . "\n\n" . t('The link works for %d days.', self::TOKEN_DAYS) . "\n\n—\n" . $siteName . "\n" . rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/');

        return Mail::send($s, $email, t('Your file from %s', $siteName), $text);
    }

    /**
     * /download/<token>: a redirect to the file of a valid token; null = not found (expired, tampered). When the file is
     * the current file of a document in a library, the download counts for that document.
     */
    public static function gatedDownload(App $app, string $token): ?Response
    {
        $db = $app->db();
        $file = self::verifyToken((new Antispam($db, $app->settings()))->key(), $token);
        if ($file === null) {
            return null;
        }
        foreach (Collections::all($db) as $collection) {
            $fileKey = self::fileField($collection);
            $value = $fileKey === null ? null : Collections::jsonField($fileKey); // N3183-1: the key is checked where it becomes SQL
            if ($value === null) {
                continue;
            }
            $idp = $db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND smazano IS NULL AND ' . $value . ' = ? ORDER BY idp LIMIT 1', [$collection['idk'], $file]);
            if ($idp !== null) {
                self::count($app, (int) $idp);
                break;
            }
        }

        return self::redirectToFile($app, $file);
    }
}
