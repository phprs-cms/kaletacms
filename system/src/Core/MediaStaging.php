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
 *   when a person (or a connection that may publish) publishes – a build (promoteBuild(), with the classes it uses), the
 *   draft look (promoteReferenced()), a change in a section of the administration that shows content on the site, a write
 *   call of such a connection, scheduled publishing (promotePublished()) – in the background job, and at an anonymous
 *   request for it (at most every CHECK_SECONDS per file); a person can also approve it in Media (promote()).
 * - Shared classes never count by themselves (N315-1): a drafts-only connection creates new classes live, so a class's
 *   background goes public only with a build a person publishes, the draft look, or the approval in Media.
 * - Nobody approved it: deleted after DAYS days by the background job (cleanup()) – unless live content uses it: published
 *   content makes it public, a live class keeps it waiting for a person (N315-4).
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


    /**
     * Published content that may refer to media – everything the site shows: [FROM, the content, which rows are on the site].
     * Only a person or a connection that may publish can put a row here in its public state (a drafts-only connection saves
     * hidden pages, items and categories, news drafts and build drafts only). Shared classes are not here (N315-1).
     */
    public const array PUBLISHED = [
        ['{stranky}', "CONCAT_WS(' ', stavba, text, obrazek, kod_hlavicky)", 'zobrazit = 1 AND smazano IS NULL'],
        ['{novinky}', "CONCAT_WS(' ', obrazek, uvod, text, faq)", 'visible = 1 AND smazano IS NULL'],
        ['{kolekce_polozky}', "CONCAT_WS(' ', data, obrazek, popis)", 'zobrazit = 1 AND smazano IS NULL'],
        // the earlier files of a document, listed on its page (Core\Documents::versionsHtml)
        ['{document_versions} v JOIN {kolekce_polozky} p ON p.idp = v.idp', 'v.file', 'p.zobrazit = 1 AND p.smazano IS NULL'],
        ['{casti}', 'stavba', '1 = 1'],
        ['{popupy}', 'stavba', '1 = 1'],
        ['{komponenty}', 'stavba', '1 = 1'],
        ['{kolekce}', 'stavba', '1 = 1'],
        ['{kolekce_sablony}', 'stavba', '1 = 1'],
        ['{collection_category_templates}', 'stavba', '1 = 1'],
        ['{collection_categories}', 'image', 'visible = 1'],
        ['{collection_category_texts} t JOIN {collection_categories} c ON c.id = t.category_id', 't.description', 'c.visible = 1'],
        ['{kategorie}', 'popis', '1 = 1'],
        ['{stitky}', "CONCAT_WS(' ', popis, obrazek)", '1 = 1'],
        ['{menu}', 'polozky', '1 = 1'],
        ['{facts}', 'value', '1 = 1'],
        ['{booking_services}', 'description', 'active = 1'],
        ['{uzivatele}', "CONCAT_WS(' ', foto, bio)", '1 = 1'], // the author box of news items
        ['{social_drafts}', 'image', '1 = 1'], // posted by a person from the news item's drafts
        // the draft look is not on the site
        ['{nastaveni}', 'hodnota', "promenna <> 'look_draft'"],
        ['{newsletters}', "CONCAT_WS(' ', intro, button_url, html)", "status IN ('sending', 'sent')"],
    ];

    /** How many staged rows one step of a scan looks at; a scan goes through all of them, step by step (N315-4). */
    private const int BATCH = 300;

    /** An anonymous request asks again whether published content uses a waiting file at most this often (N315-5). */
    public const int CHECK_SECONDS = 300;

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
            // published content may already show it (a change outside the usual places): it goes public now, for everyone –
            // asked at most every CHECK_SECONDS per file, so repeated requests for a known path cost one indexed query (N315-5)
            if (!self::checkDue((int) $row['ido'])) {
                return null;
            }
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

    /** Does published content refer to the staged file? (Shared classes do not count, N315-1.) */
    public static function isPublished(Db $db, array $row): bool
    {
        return self::matching([$row + ['staged_at' => 'x']], self::publishedStems($db, [$row])) !== [];
    }

    /**
     * Every staged file that published content refers to goes public, all waiting files step by step (N315-4). Cheap when
     * nothing waits (one indexed query). Called where content is published: a change in a section of the administration
     * that shows content on the site (admin.php, Admin\Kernel::PUBLISHING_MODULES), a write call of a connection that may
     * publish (Mcp\Server), scheduled publishing and the background job. Returns the paths that went public.
     *
     * @return list<string>
     */
    public static function promotePublished(Db $db): array
    {
        $done = [];
        $after = 0;
        while (($rows = self::staged($db, $after)) !== []) {
            $after = (int) $rows[count($rows) - 1]['ido'];
            array_push($done, ...self::promoteRows($db, self::matching($rows, self::publishedStems($db, $rows))));
        }

        return $done;
    }

    /**
     * The stems published content (PUBLISHED) refers to, looked up only for the given staged rows: one pass over each table.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function publishedStems(Db $db, array $rows): array
    {
        $stems = [];
        foreach (self::PUBLISHED as [$from, $content, $where]) {
            array_push($stems, ...self::stemsIn($db, $from, $content, $where, $rows));
        }

        return array_values(array_unique($stems));
    }

    /**
     * The stems of the given staged rows that one source refers to (N315-5): one pass over the table with a LIKE per upload
     * folder of the rows (media/2026/10/, also JSON-escaped media\/2026\/10\/) – usually one or two, however many files
     * wait – then the paths in the rows found are read in PHP (MediaHygiene::paths) and only the waiting ones kept.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function stemsIn(Db $db, string $from, string $content, string $where, array $rows): array
    {
        $wanted = [];
        $folders = [];
        foreach ($rows as $o) {
            $stem = self::stem((string) $o['obr_poloha']);
            $wanted[$stem] = true;
            if (preg_match('#^media/(\d{4})/(\d{2})/#', $stem, $m)) {
                $folders['%media/' . $m[1] . '/' . $m[2] . '/%'] = true;
                $folders['%media\\\\/' . $m[1] . '\\\\/' . $m[2] . '\\\\/%'] = true; // a LIKE pattern for media\/2026\/10\/
            }
        }
        if ($folders === []) {
            return [];
        }
        $stems = [];
        $sql = 'SELECT ' . $content . ' AS obsah FROM ' . $from . ' WHERE ' . $where . ' AND (' . implode(' OR ', array_fill(0, count($folders), $content . ' LIKE ?')) . ')';
        foreach ($db->all($sql, array_keys($folders)) as $r) {
            foreach (self::referencedStems((string) $r['obsah']) as $stem) {
                if (isset($wanted[$stem])) {
                    $stems[$stem] = true;
                }
            }
        }

        return array_keys($stems);
    }

    /**
     * Does a published build list the class (an element's "tridy")? Then a class of that name changes what the site shows,
     * so a new one waits in the draft look like a change of an existing one (Core\Look::setClass, HtmlConverter, N315-1).
     */
    public static function classInPublishedBuild(Db $db, string $name): bool
    {
        foreach (self::PUBLISHED as [$from, $content, $where]) {
            if (!str_contains($content, 'stavba')) {
                continue;
            }
            foreach ($db->all('SELECT stavba FROM ' . $from . ' WHERE ' . $where . ' AND stavba LIKE ?', ['%"' . addcslashes($name, '%_\\') . '"%']) as $r) {
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
     * The staged files the given content refers to go public (the draft look when it is published, a sent newsletter).
     *
     * @return list<string> the paths that went public
     */
    public static function promoteReferenced(Db $db, string ...$content): array
    {
        $stems = self::referencedStems(...$content);
        $done = [];
        $after = 0;
        while ($stems !== [] && ($rows = self::staged($db, $after)) !== []) {
            $after = (int) $rows[count($rows) - 1]['ido'];
            array_push($done, ...self::promoteRows($db, self::matching($rows, $stems)));
        }

        return $done;
    }

    /**
     * A build a person (or a connection that may publish) publishes (Builder\Publisher): the staged files it refers to go
     * public, with those of the shared classes it uses – the one way a class's background image goes public by itself (N315-1).
     *
     * @return list<string> the paths that went public
     */
    public static function promoteBuild(Db $db, string $build): array
    {
        if (self::staged($db) === []) {
            return [];
        }
        $content = [$build];
        $classes = self::buildClasses($build);
        if ($classes !== []) {
            foreach ($db->all('SELECT styl, css FROM {tridy} WHERE nazev IN (' . implode(', ', array_fill(0, count($classes), '?')) . ')', $classes) as $class) {
                $content[] = (string) $class['styl'] . ' ' . (string) $class['css'];
            }
        }

        return self::promoteReferenced($db, ...$content);
    }

    /**
     * Moves a staged file with all its variants to media/ (rename on the same disk), then marks the row public. A rename that
     * fails moves the already moved files back and leaves the row waiting. Not journaled for undo: the files have moved.
     * Under a lock per file (N315-7): two requests promoting the same file at once never interleave – the second finds it done.
     */
    public static function promote(Db $db, array $row): bool
    {
        $lock = $db->lockName('stage', (string) (int) $row['ido']);
        if ((int) $db->value('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
            return false;
        }
        try {
            $path = (string) $row['obr_poloha'];
            if ($db->value('SELECT staged_at FROM {media} WHERE ido = ?', [(int) $row['ido']]) === null) {
                return is_file(KALETA_ROOT . '/' . $path); // another request promoted it meanwhile (or the row is gone)
            }
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
            @unlink(self::checkFile((int) $row['ido']));
            \Kaleta\Front\Cache::clear();

            return true;
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Deletes a staged file with its variants and its media row (Media → Waiting for approval → Delete, the background job). */
    public static function discard(Db $db, array $row): void
    {
        foreach (self::stagedFiles((string) $row['obr_poloha']) as $file) {
            @unlink($file);
        }
        @unlink(self::checkFile((int) $row['ido']));
        $db->delete('media', ['ido' => (int) $row['ido']]);
    }

    /**
     * The background job: staged files published content refers to go public, those nobody approved within DAYS days are
     * deleted. A file whose public copy is already in media/ (moved, but its row not marked) is only marked public. A file
     * live content uses is never deleted (N315-4): published content makes it public, a live shared class keeps it waiting
     * (Media says so) – a class's background goes public only with a person's publish or approval (N315-1).
     */
    public static function cleanup(Db $db): string
    {
        $published = count(self::promotePublished($db));
        $deleted = 0;
        $kept = 0;
        $after = 0;
        $before = Clock::at('-' . self::DAYS . ' days');
        while (($rows = $db->all('SELECT * FROM {media} WHERE staged_at IS NOT NULL AND staged_at < ? AND ido > ? ORDER BY ido LIMIT ' . self::BATCH, [$before, $after])) !== []) {
            $after = (int) $rows[count($rows) - 1]['ido'];
            $inUse = array_fill_keys([...self::publishedStems($db, $rows), ...self::stemsIn($db, '{tridy}', "CONCAT_WS(' ', styl, css)", '1 = 1', $rows)], true);
            foreach ($rows as $row) {
                if (is_file(KALETA_ROOT . '/' . $row['obr_poloha'])) {
                    $db->run('UPDATE {media} SET staged_at = NULL WHERE ido = ?', [(int) $row['ido']]);
                    continue;
                }
                if (isset($inUse[self::stem((string) $row['obr_poloha'])])) {
                    $kept++;
                    continue;
                }
                self::discard($db, $row);
                $deleted++;
            }
        }
        // files without a row (an undone Claude session removed it, Core\AgentJournal) are older than any waiting file may be
        $folder = KALETA_ROOT . '/' . self::FOLDER;
        if (is_dir($folder)) {
            $old = time() - (self::DAYS + 1) * 86400;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getMTime() < $old && $db->value('SELECT 1 FROM {media} WHERE obr_poloha LIKE ? AND staged_at IS NOT NULL LIMIT 1', [self::stem('media/' . substr($file->getPathname(), strlen($folder) + 1)) . '.%']) === null && @unlink($file->getPathname())) {
                    $deleted++;
                }
            }
        }

        return 'published ' . $published . ', deleted ' . $deleted . ($kept > 0 ? ', kept ' . $kept . ' that live content uses (Media → Waiting for approval)' : '');
    }

    /**
     * Is it time to ask again whether published content uses a waiting file (an anonymous request, N315-5)? Remembers the
     * question for CHECK_SECONDS in storage/cache – a file per waiting file, removed when it goes public or is deleted.
     */
    private static function checkDue(int $ido): bool
    {
        $file = self::checkFile($ido);
        $at = @filemtime($file);
        if ($at !== false && time() - $at < self::CHECK_SECONDS) {
            return false;
        }
        @touch($file);

        return true;
    }

    private static function checkFile(int $ido): string
    {
        return KALETA_ROOT . '/storage/cache/media-staging-' . $ido;
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

    /**
     * One step of the waiting files, oldest first, after the given id (BATCH rows).
     *
     * @return list<array<string, mixed>>
     */
    private static function staged(Db $db, int $after = 0): array
    {
        return $db->all('SELECT ido, obr_poloha, nahl_poloha, staged_at FROM {media} WHERE staged_at IS NOT NULL AND ido > ? ORDER BY ido LIMIT ' . self::BATCH, [$after]);
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
