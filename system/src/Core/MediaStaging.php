<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Files waiting for approval (3.15, audit finding N10): what a Claude connection limited to drafts stores through MCP
 * (upload_file, the media downloads of save_collection_items) does not go to the public media/ folder. It is processed with
 * the same checks (Images re-encodes, Files checks the content type, SVG is cleaned) and saved outside the web root under
 * storage/staging/YYYY/MM/<the name the media file gets>; the media row has staged_at set. The path in the row and in the tool
 * answer is the final public one (media/YYYY/MM/name.ext), so drafts point at it unchanged.
 *
 * - Showing: a request for a missing media/… file reaches index.php on Apache (.htaccess), nginx (try_files in
 *   system/nginx.example.conf) and the PHP development server (dev-router.php); Front\Kernel asks serve(). A staged file is
 *   sent only to a signed-in user or a visitor of a draft preview (the signed cookie a preview sets, see previewCookie()),
 *   with the headers media/ has (nosniff, attachment for documents, the SVG sandbox). Everyone else gets the usual 404.
 * - Going public: once published content refers to a staged file, the file moves to media/ (rename, then staged_at = NULL):
 *   after every change in the administration and every write call of a connection that may publish (promotePublished()),
 *   when the draft look is published (promoteReferenced()), at the first anonymous request for it and in the background
 *   job; a person can also approve it in Media → Waiting for approval (promote()).
 * - Nobody approved it: deleted after DAYS days by the background job (cleanup()).
 *
 * Full-access connections, the administrators' own uploads and imports never stage anything (appliesTo()).
 */
final class MediaStaging
{
    /** Staged files, outside the web root (storage/.htaccess, the nginx example and dev-router.php refuse storage/). */
    public const string FOLDER = 'storage/staging';

    /** A staged file nobody approved is deleted after this many days. */
    public const int DAYS = 30;

    /** Cookie of a draft preview that lets its page load staged files (path /media/, signed like a preview link). */
    public const string COOKIE = 'ka_nahled_media';

    /** The Core\Preview target of that cookie. */
    public const string PREVIEW_TARGET = 'media';


    /** Published content that may refer to media: [table, the content, which rows are on the site]. */
    private const array PUBLISHED = [
        ['stranky', "CONCAT_WS(' ', stavba, text, obrazek)", 'zobrazit = 1 AND smazano IS NULL'],
        ['novinky', "CONCAT_WS(' ', obrazek, uvod, text, faq)", 'visible = 1 AND smazano IS NULL'],
        ['kolekce_polozky', "CONCAT_WS(' ', data, obrazek)", 'zobrazit = 1 AND smazano IS NULL'],
        ['casti', 'stavba', '1 = 1'],
        ['popupy', 'stavba', '1 = 1'],
        ['komponenty', 'stavba', '1 = 1'],
        ['kolekce', 'stavba', '1 = 1'],
        ['kolekce_sablony', 'stavba', '1 = 1'],
        ['collection_category_templates', 'stavba', '1 = 1'],
        ['collection_categories', 'image', 'visible = 1'],
        ['kategorie', 'popis', '1 = 1'],
        ['stitky', "CONCAT_WS(' ', popis, obrazek)", '1 = 1'],
        // the draft look is not on the site; a class counts only once a published build uses it (publishedStems)
        ['nastaveni', 'hodnota', "promenna <> 'look_draft'"],
        ['newsletters', 'html', "status IN ('sending', 'sent')"],
    ];

    /** Published builds, where a class with a waiting background image counts as published: [table, which rows]. */
    private const array BUILDS = [
        ['stranky', 'zobrazit = 1 AND smazano IS NULL'], ['casti', '1 = 1'], ['popupy', '1 = 1'], ['komponenty', '1 = 1'], ['kolekce', '1 = 1'],
        ['kolekce_sablony', '1 = 1'], ['collection_category_templates', '1 = 1'],
    ];

    /** How many staged rows one scan looks at (they are few: a connection's uploads waiting for a person). */
    private const int SCAN_LIMIT = 300;

    /** Does a file stored by this caller wait for approval: a Claude connection limited to drafts. */
    public static function appliesTo(Auth $auth): bool
    {
        return $auth->connection() !== null && $auth->draftsOnly();
    }

    /** The file on disk for a path under media/ (a folder too): in media/ itself, or staged under storage/staging/. */
    public static function absolute(string $mediaPath, bool $staged): string
    {
        return KALETA_ROOT . '/' . ($staged && str_starts_with($mediaPath, 'media/') ? self::FOLDER . '/' . substr($mediaPath, 6) : $mediaPath);
    }

    /** The request path of a file under media/ ('/media/2026/10/a-1f2e3d.jpg' => 'media/2026/10/a-1f2e3d.jpg'), null for anything else. */
    public static function requestedPath(string $requestPath): ?string
    {
        $path = ltrim($requestPath, '/');

        return preg_match(self::pattern(), $path) ? $path : null;
    }

    /**
     * The common part of a media file and its variants: 'media/2026/10/a-1f2e3d-nahled.jpg.webp' => 'media/2026/10/a-1f2e3d'.
     * Every name ends with six random hex digits, so the stem names one upload.
     */
    public static function stem(string $mediaPath): string
    {
        $name = basename($mediaPath);
        $name = (string) preg_replace('/-(?:1200|nahled)$/', '', explode('.', $name)[0]);

        return dirname($mediaPath) . '/' . $name;
    }

    /**
     * Stems of every media file the content refers to (also in JSON, media\/2026\/…, and with the site URL).
     *
     * @return list<string>
     */
    public static function referencedStems(string ...$content): array
    {
        $stems = [];
        foreach (\Kaleta\Core\MediaHygiene::paths(implode(' ', $content)) as $path) {
            $stems[self::stem($path)] = true;
        }

        return array_keys($stems);
    }

    /**
     * The staged rows among $rows whose file the stems name – the promotion scan.
     *
     * @param list<array<string, mixed>> $rows media rows with obr_poloha
     * @param list<string> $stems from referencedStems()
     * @return list<array<string, mixed>>
     */
    public static function matching(array $rows, array $stems): array
    {
        $wanted = array_fill_keys($stems, true);

        return array_values(array_filter($rows, fn (array $o): bool => ($o['staged_at'] ?? null) !== null && isset($wanted[self::stem((string) $o['obr_poloha'])])));
    }

    /**
     * Who gets a staged file: 'serve' (a signed-in user, a draft preview), 'publish' (anyone, when published content already
     * refers to it: it moves to media/ first) or 'none' (the usual 404). The routing decision of serve().
     *
     * @return 'serve'|'publish'|'none'
     */
    public static function access(bool $signedIn, bool $preview, bool $published): string
    {
        return $signedIn || $preview ? 'serve' : ($published ? 'publish' : 'none');
    }

    /**
     * Response headers for a staged file – the same safeguards media/.htaccess and the nginx example give the public folder:
     * nosniff, documents as a download, SVG in a sandbox; never in a shared cache nor a search engine.
     *
     * @return array<string, string>
     */
    public static function headers(string $mediaPath): array
    {
        $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $headers = ['Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'];
        if ($extension === 'svg') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }
        if (preg_match('/^(docx?|xlsx?|pptx?|od[tsp]|rtf|csv|zip|epub|gpx|ics)$/', $extension)) {
            $headers['Content-Disposition'] = 'attachment';
        }

        return $headers;
    }

    private const array TYPES = [
        'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'avif' => 'image/avif',
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'rtf' => 'application/rtf', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8', 'zip' => 'application/zip', 'epub' => 'application/epub+zip',
        'gpx' => 'application/gpx+xml', 'ics' => 'text/calendar', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
    ];

    /** The staged media row of a file or one of its variants; null when the file is not waiting for approval. */
    public static function row(Db $db, string $mediaPath): ?array
    {
        return $db->one('SELECT * FROM {media} WHERE staged_at IS NOT NULL AND obr_poloha LIKE ? ORDER BY ido LIMIT 1', [self::stem($mediaPath) . '.%']);
    }

    /**
     * A request for a missing media/… file (Front\Kernel, before anything else answers it): the staged file for whoever may
     * see it, null for the usual 404. $preview says whether the visitor has a valid draft preview (asked only when needed).
     */
    public static function serve(App $app, string $requestPath, \Closure $preview, string $accept): ?Response
    {
        $path = self::requestedPath($requestPath);
        if ($path === null || is_file(KALETA_ROOT . '/' . $path)) {
            return null;
        }
        $db = $app->db();
        $row = self::row($db, $path);
        if ($row === null) {
            return null;
        }
        $signedIn = $app->auth()->user() !== null;
        $access = self::access($signedIn, !$signedIn && $preview(), false);
        if ($access === 'none') {
            // published content may already show it (a change outside the usual places): it goes public now, for everyone
            $access = self::access(false, false, self::isPublished($db, $row));
            if ($access === 'none' || !self::promote($db, $row)) {
                return null;
            }
            $file = KALETA_ROOT . '/' . $path;
            $headers = self::headers($path);
            unset($headers['Cache-Control'], $headers['X-Robots-Tag']);
        } else {
            $file = self::absolute($path, true);
            $headers = self::headers($path);
        }
        // the AVIF or WebP sibling for a browser that accepts it, as .htaccess does for media/
        if (preg_match('/\.(jpe?g|png)$/', $path)) {
            $headers['Vary'] = 'Accept';
            foreach (['avif', 'webp'] as $format) {
                if (str_contains($accept, 'image/' . $format) && is_file($file . '.' . $format)) {
                    $file .= '.' . $format;
                    $headers['Content-Type'] = 'image/' . $format;
                    break;
                }
            }
        }
        if (!is_file($file)) {
            return null;
        }

        return new Response('', 200, $headers + ['Content-Length' => (string) filesize($file)], $file);
    }

    /**
     * The cookie a draft preview sets so that its page can show staged images (Front\Kernel, after a preview key was
     * verified): a key of its own for the target "media", valid as long as the preview's key, sent only with /media/ requests.
     */
    public static function previewCookie(App $app, string $previewKey): void
    {
        $until = (int) substr($previewKey, 0, 10);
        if ($until <= time() || headers_sent()) {
            return;
        }
        $r = $app->request;
        setcookie(self::COOKIE, Preview::key($app->db(), $app->settings(), self::PREVIEW_TARGET, (int) ceil(($until - time()) / 60)),
            ['expires' => $until, 'path' => $r->basePath() . '/media/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $r->isHttps()]);
    }

    /** Does the request carry a valid media preview key (the cookie, or ?preview_key= for target "media")? */
    public static function hasPreview(App $app): bool
    {
        foreach ([(string) ($_COOKIE[self::COOKIE] ?? ''), $app->request->get('preview_key')] as $key) {
            if ($key !== '' && Preview::verify($app->db(), $app->settings(), self::PREVIEW_TARGET, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Does published content refer to the staged file? */
    public static function isPublished(Db $db, array $row): bool
    {
        return self::matching([$row + ['staged_at' => 'x']], self::publishedStems($db, [$row])) !== [];
    }

    /**
     * Every staged file that published content refers to goes public. Cheap when nothing waits (one indexed query); called
     * after every change in the administration, every write call of a connection that may publish, scheduled publishing and
     * the background job. Returns the paths that went public.
     *
     * @return list<string>
     */
    public static function promotePublished(Db $db): array
    {
        $rows = self::staged($db);

        return $rows === [] ? [] : self::promoteRows($db, self::matching($rows, self::publishedStems($db, $rows)));
    }

    /**
     * The stems published content refers to, looked up only for the given staged rows. A class counts once a published
     * build uses it: a drafts-only connection creates classes live (save_classes), but cannot publish a build.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function publishedStems(Db $db, array $rows): array
    {
        $needles = array_map(fn (array $o): string => '%' . basename(self::stem((string) $o['obr_poloha'])) . '%', $rows);
        $stems = [];
        $matching = function (string $table, string $content, string $where) use ($db, $needles): array {
            $found = [];
            foreach (array_chunk($needles, 50) as $chunk) {
                $sql = 'SELECT ' . $content . ' AS obsah' . ($table === 'tridy' ? ', nazev' : '') . ' FROM {' . $table . '} WHERE ' . $where
                    . ' AND (' . implode(' OR ', array_fill(0, count($chunk), $content . ' LIKE ?')) . ')';
                array_push($found, ...$db->all($sql, $chunk));
            }

            return $found;
        };
        foreach (self::PUBLISHED as [$table, $content, $where]) {
            foreach ($matching($table, $content, $where) as $r) {
                array_push($stems, ...self::referencedStems((string) $r['obsah']));
            }
        }
        foreach ($matching('tridy', "CONCAT_WS(' ', styl, css)", '1 = 1') as $class) {
            if (self::classPublished($db, (string) $class['nazev'])) {
                array_push($stems, ...self::referencedStems((string) $class['obsah']));
            }
        }

        return array_values(array_unique($stems));
    }

    /** Does a published build use the class (an element's "tridy")? */
    private static function classPublished(Db $db, string $name): bool
    {
        foreach (self::BUILDS as [$table, $where]) {
            foreach ($db->all('SELECT stavba FROM {' . $table . '} WHERE ' . $where . ' AND stavba LIKE ?', ['%"' . addcslashes($name, '%_\\') . '"%']) as $r) {
                if (in_array($name, self::buildClasses((string) $r['stavba']), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The class names the elements of a build use.
     *
     * @return list<string>
     */
    public static function buildClasses(string $json): array
    {
        $names = [];
        $walk = function (array $children) use (&$walk, &$names): void {
            foreach ($children as $element) {
                if (!is_array($element)) {
                    continue;
                }
                foreach ((array) ($element['tridy'] ?? []) as $class) {
                    if (is_string($class)) {
                        $names[$class] = true;
                    }
                }
                $walk((array) ($element['deti'] ?? []));
            }
        };
        $build = json_decode($json, true);
        $walk(is_array($build) ? (array) ($build['deti'] ?? []) : []);

        return array_map(strval(...), array_keys($names));
    }

    /**
     * The staged files the given content refers to go public (the draft look when it is published).
     *
     * @return list<string> the paths that went public
     */
    public static function promoteReferenced(Db $db, string ...$content): array
    {
        $rows = self::staged($db);

        return $rows === [] ? [] : self::promoteRows($db, self::matching($rows, self::referencedStems(...$content)));
    }

    /**
     * Moves a staged file with all its variants to media/ (rename on the same disk), then marks the row public. A rename that
     * fails moves the already moved files back and leaves the row waiting. Not journaled for undo: the files have moved.
     */
    public static function promote(Db $db, array $row): bool
    {
        $path = (string) $row['obr_poloha'];
        $files = self::stagedFiles($path);
        $target = dirname(self::absolute($path, false));
        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            return false;
        }
        // the main file last: until it is there, nobody's request finds a half-moved upload
        $main = self::absolute($path, true);
        usort($files, fn (string $a, string $b): int => ($a === $main) <=> ($b === $main));
        $moved = [];
        foreach ($files as $file) {
            $to = $target . '/' . basename($file);
            if (is_file($to) || !@rename($file, $to)) {
                foreach ($moved as [$from, $back]) {
                    @rename($back, $from);
                }

                return false;
            }
            $moved[] = [$file, $to];
        }
        if (!is_file(KALETA_ROOT . '/' . $path)) {
            return false; // the staged file itself is gone: the row stays waiting and the background job removes it
        }
        $journal = $db->journal;
        $db->journal = null;
        try {
            $db->run('UPDATE {media} SET staged_at = NULL WHERE ido = ?', [(int) $row['ido']]);
        } finally {
            $db->journal = $journal;
        }
        \Kaleta\Front\Cache::clear();

        return true;
    }

    /** Deletes a staged file with its variants and its media row (Media → Waiting for approval → Delete, the background job). */
    public static function discard(Db $db, array $row): void
    {
        foreach (self::stagedFiles((string) $row['obr_poloha']) as $file) {
            @unlink($file);
        }
        $db->delete('media', ['ido' => (int) $row['ido']]);
    }

    /**
     * The background job: staged files published content refers to go public, those nobody approved within DAYS days are
     * deleted. A file whose public copy is already in media/ (moved, but its row not marked) is only marked public.
     */
    public static function cleanup(Db $db): string
    {
        $published = count(self::promotePublished($db));
        $deleted = 0;
        foreach ($db->all('SELECT * FROM {media} WHERE staged_at IS NOT NULL AND staged_at < ? ORDER BY ido LIMIT ' . self::SCAN_LIMIT, [Clock::at('-' . self::DAYS . ' days')]) as $row) {
            if (is_file(KALETA_ROOT . '/' . $row['obr_poloha'])) {
                $db->run('UPDATE {media} SET staged_at = NULL WHERE ido = ?', [(int) $row['ido']]);
                continue;
            }
            self::discard($db, $row);
            $deleted++;
        }
        // files without a row (an undone Claude session removed it, Core\AgentJournal) are older than any waiting file may be
        $folder = KALETA_ROOT . '/' . self::FOLDER;
        if (is_dir($folder)) {
            $before = time() - (self::DAYS + 1) * 86400;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getMTime() < $before && @unlink($file->getPathname())) {
                    $deleted++;
                }
            }
        }

        return 'published ' . $published . ', deleted ' . $deleted;
    }

    /** How many files wait for approval (Media). */
    public static function count(Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {media} WHERE staged_at IS NOT NULL');
    }

    /**
     * The staged file of a media path and its variants on disk.
     *
     * @return list<string>
     */
    public static function stagedFiles(string $mediaPath): array
    {
        if (!preg_match(self::pattern(), $mediaPath)) {
            return [];
        }
        $stem = self::absolute(self::stem($mediaPath), true);

        return array_values(array_filter([...(glob($stem . '.*') ?: []), ...(glob($stem . '-1200.*') ?: []), ...(glob($stem . '-nahled.*') ?: [])], is_file(...)));
    }

    /** A file under media/ as Images, Files and the SVG upload name it, with its variants (-1200, -nahled, .webp, .avif). */
    private static function pattern(): string
    {
        return '#^media/\d{4}/\d{2}/[a-z0-9][a-z0-9-]*\.(?:jpg|png|webp|gif|svg|' . implode('|', Files::FILE_EXTENSIONS) . ')(?:\.(?:webp|avif))?$#D';
    }

    /** @return list<array<string, mixed>> */
    private static function staged(Db $db): array
    {
        return $db->all('SELECT ido, obr_poloha, nahl_poloha, staged_at FROM {media} WHERE staged_at IS NOT NULL ORDER BY ido LIMIT ' . self::SCAN_LIMIT);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function promoteRows(Db $db, array $rows): array
    {
        $done = [];
        foreach ($rows as $row) {
            if (self::promote($db, $row)) {
                $done[] = (string) $row['obr_poloha'];
            }
        }

        return $done;
    }
}
