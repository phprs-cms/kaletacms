<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The CVs of job applications (3.34, split out of Core\Applications in 3.47): what an upload may be (checkUpload – a narrow
 * list of types, CV_EXTENSIONS, whose detected content must fit the extension), the sealed files under storage/applications
 * (sealFile, writeSealed, openSealed – each file under its own random key, the format of Core\SealedFiles), the sealed list
 * of a row (attachments, encodeAttachments, attachmentsLocked), the download (attachment), and the daily work on them:
 * sealing what migration 0120 moved (sealPending, sealEntry – crash-safe), the sweep of files no row lists (sweepOrphans)
 * and the lists held under the database key (resealHeld). Core\Applications keeps a forward for every public member.
 */
final class ApplicationFiles
{
    /** The file types an application accepts (no archives, spreadsheets or drawings). */
    public const array CV_EXTENSIONS = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'jpg', 'jpeg', 'png'];

    /** extension => the content types finfo (or Core\FileSignature without it) may report for it. */
    public const array CONTENT_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-word', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream'],
        'rtf' => ['text/rtf', 'application/rtf', 'text/plain'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** At most this many file fields of one application are stored. */
    public const int MAX_FILES = 3;

    /** Where the sealed files live (not reachable from the web, like storage/prilohy). */
    public const string FOLDER = KALETA_ROOT . '/storage/applications';

    /** Where the files of enquiries were (the applications moved by migration 0120 until sealPending()). */
    public const string OLD_FOLDER = KALETA_ROOT . '/storage/prilohy';
    /** Files sealPending() seals per run (the daily clean-up). */
    public const int SEAL_BATCH = 200;
    /**
     * The extension of an uploaded file when an application may keep it, null when it is refused: the type must be in
     * CV_EXTENSIONS, the detected content must fit it and never be HTML, PHP, a script, SVG or a program.
     */
    public static function checkUpload(string $tmpPath, string $originalName): ?string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::CV_EXTENSIONS, true) || !is_file($tmpPath) || Files::forbiddenContent($tmpPath, $extension)) {
            return null;
        }

        return self::contentFits($extension, Files::mimeOf($tmpPath, $extension)) ? $extension : null;
    }

    /** Does a detected content type fit the extension (CONTENT_TYPES)? Pure. */
    public static function contentFits(string $extension, string $type): bool
    {
        return in_array(strtolower($type), self::CONTENT_TYPES[$extension] ?? [], true);
    }

    /**
     * The files of an application row on disk (N7): what attachment_paths names, and what the list names when it opens;
     * null when the list cannot be opened and the row has no attachment_paths – its files cannot be located.
     *
     * @param array<string, mixed> $row attachments, attachment_paths
     * @return list<string>|null
     */
    public static function rowFiles(Settings $s, array $row): ?array
    {
        $locked = self::attachmentsLocked($s, $row['attachments'] ?? null);
        if ($locked && ($row['attachment_paths'] ?? null) === null) {
            return null;
        }

        return array_values(array_unique([...self::pathFiles($row['attachment_paths'] ?? null), ...($locked ? [] : self::pathFiles(self::paths(self::attachments($s, $row['attachments'] ?? null))))]));
    }

    /* ---------- files ---------- */

    /** The first bytes of a sealed CV (3.34 second review N1/N9): the length of the wrapped key, the key wrapped with the Vault key, then the file. */
    public const string FILE_MAGIC = SealedFiles::MAGIC;

    /** Days an encrypted file no application lists stays before the daily clean-up removes it (an interrupted run, a restored backup). */
    public const int ORPHAN_DAYS = 7;

    /**
     * The suffix of an encrypted file the sweep set aside (3.34 third review F1): an old file no row lists is renamed to it
     * and its time set to now – it goes only ORPHAN_DAYS after that, and comes back the moment a row lists it again
     * (sweepOrphans()) or something opens it (openSealed()). A restored backup never costs a CV its seven days.
     */
    public const string ORPHAN_SUFFIX = '.orphan';

    /**
     * Encrypts a file into FOLDER under a new random key and returns where it is and the key (base64). 3.34 second review
     * N1: the source is never removed here – the caller removes it once the row lists the sealed copy (receive(), and
     * sealEntry() only after the copy opened with the key the row holds). $target: the place under FOLDER – sealedTarget()
     * for a CV moved from storage/prilohy, so an interrupted run finds its copy again; a random one otherwise. Null when
     * the source cannot be read or the copy cannot be written (writeSealed()).
     *
     * @return array{path: string, key: string}|null
     */
    public static function sealFile(Settings $s, string $source, ?string $target = null): ?array
    {
        $plain = @file_get_contents($source);
        if (!is_string($plain)) {
            return null;
        }
        $path = $target ?? date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.bin';
        $key = self::writeSealed($s, $plain, $path);
        sodium_memzero($plain);

        return $key === null ? null : ['path' => $path, 'key' => $key];
    }

    /**
     * Writes a sealed file at $path under FOLDER (3.34 second review N1/N9): FILE_MAGIC, the length of the wrapped key (2
     * bytes), the per-file key wrapped with the Vault key of the purpose (wrapKey()), the nonce and the secretbox – written
     * next to its place and renamed, so the name never holds half a file. The wrapped key makes a sealed file openable with
     * the Vault key alone when its row lost the key (a restored backup older than the sealing, an interrupted run). Returns
     * the per-file key (base64), null when it cannot be written.
     */
    public static function writeSealed(Settings $s, string $plain, string $path): ?string
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#D', $path) !== 1) {
            return null;
        }
        $key = SealedFiles::write($s, Applications::SECTION, $plain, self::FOLDER . '/' . $path); // 3.46: the format and crypto moved to Core\SealedFiles unchanged
        if ($key === null) {
            return null;
        }
        self::protectFolder();
        self::markFilesSince($s);

        return $key;
    }

    /** Since when CVs of job applications are kept encrypted here (unix time; the first sealed file or move – 3.34 second review N9). */
    public const string FILES_SINCE = 'applications_files_since';

    /**
     * The first CV sealed or the first application moved from Enquiries (N9): from then on a database backup older than
     * that misses what only the files on disk hold – the restore screen says so.
     */
    public static function markFilesSince(Settings $s): void
    {
        try {
            if ($s->get(self::FILES_SINCE) === '') {
                $s->set(self::FILES_SINCE, (string) time());
            }
        } catch (\PDOException $e) {
            ErrorLog::write(ErrorLog::exception($e)); // logged, but a marker never fails the sealing of a CV
        }
    }

    /**
     * Restoring a backup made at $backupTime loses what only the disk holds (N9): the backup is older than the first sealed
     * CV or move. Pure.
     */
    public static function backupMissesFiles(int $backupTime, string $filesSince): bool
    {
        return ctype_digit($filesSince) && (int) $filesSince > 0 && $backupTime < (int) $filesSince;
    }

    /**
     * The per-file key of a sealed file from its header (N9): unwrapped with the Vault key, the key in the database or the
     * previous signing key; null when none opens it or the file has no header (Core\SealedFiles::headerKey).
     */
    public static function headerKey(Settings $s, string $file): ?string
    {
        return SealedFiles::headerKey($s, Applications::SECTION, $file);
    }

    /**
     * The content of a sealed file: with the key the row holds (base64), otherwise with the key of its header (headerKey());
     * null when it is gone or neither opens it. A file without the header (sealed before it) opens with the row's key only.
     */
    public static function openSealed(Settings $s, string $file, ?string $key): ?string
    {
        if (!is_file($file) && is_file($file . self::ORPHAN_SUFFIX)) {
            @rename($file . self::ORPHAN_SUFFIX, $file); // F1: set aside by the sweep, wanted again (a newer backup restored)
        }

        return SealedFiles::open($s, Applications::SECTION, $file, $key);
    }

    /**
     * Where the sealed copy of a CV moved from storage/prilohy goes (N1/N9): the same month folder and a name derived from
     * its old path – a run that stopped after writing it, and a database restored from a backup older than the sealing,
     * find the copy again. Pure.
     */
    public static function sealedTarget(string $oldPath): string
    {
        return substr($oldPath, 0, 7) . '/' . substr(hash('sha256', 'kaleta-cv|' . $oldPath), 0, 32) . '.bin';
    }

    /** A deny-all .htaccess in the folder (storage/ has one too – a second line of defence on a misconfigured host). */
    private static function protectFolder(): void
    {
        SealedFiles::protect(self::FOLDER);
    }

    /**
     * The file of an attachment on disk, or null (the stored path is validated – nothing outside the two folders).
     *
     * @param array<string, mixed> $attachment a raw entry of the sealed list
     */
    public static function filePath(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');
        if (!empty($attachment['sealed'])) {
            return preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#D', $path) === 1 ? self::FOLDER . '/' . $path : null;
        }

        return preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', $path) === 1 ? self::OLD_FOLDER . '/' . $path : null;
    }

    /**
     * Where the files of an attachment list are, in plain words (3.34 second review N7 – the column attachment_paths): the
     * sealed copies (bin) and the CVs still in storage/prilohy (old – unsealed ones, and the old place a sealed one came
     * from). Random names only, nothing of the person: whatever happens to the sealed list (the encryption key), the files
     * can always be found and removed. Null when there are none. Pure.
     *
     * @param list<array<string, mixed>> $list raw entries
     */
    public static function paths(array $list): ?string
    {
        $out = ['bin' => [], 'old' => []];
        foreach ($list as $a) {
            $path = (string) ($a['path'] ?? '');
            if (!empty($a['sealed'])) {
                $out['bin'][] = $path;
                if (is_string($a['from'] ?? null) && $a['from'] !== '') {
                    $out['old'][] = $a['from'];
                }
            } elseif ($path !== '') {
                $out['old'][] = $path;
            }
        }

        return $out['bin'] === [] && $out['old'] === [] ? null : (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The files on disk a row's attachment_paths names (validated as filePath() does): the sealed copies, the CVs in
     * storage/prilohy and the copy an interrupted run may have written for them (sealedTarget()). Pure apart from nothing.
     *
     * @return list<string>
     */
    public static function pathFiles(mixed $paths): array
    {
        $list = is_string($paths) ? json_decode($paths, true) : null;
        $files = [];
        foreach (is_array($list) && is_array($list['bin'] ?? null) ? $list['bin'] : [] as $path) {
            $files[] = self::filePath(['path' => (string) $path, 'sealed' => true]);
        }
        foreach (is_array($list) && is_array($list['old'] ?? null) ? $list['old'] : [] as $path) {
            $files[] = self::filePath(['path' => (string) $path, 'sealed' => false]);
            $files[] = preg_match('#^\d{4}/\d{2}/#', (string) $path) === 1 ? self::filePath(['path' => self::sealedTarget((string) $path), 'sealed' => true]) : null;
        }

        return array_values(array_unique(array_filter($files, 'is_string')));
    }

    /**
     * The content of the attachment number $index of an application, decrypted in memory, with its original name; null
     * when there is none or its file is gone or cannot be opened. Only for the admin download of a person with the
     * section – never for MCP or a public address. N9: a sealed file opens with the key of its header when the row's key
     * does not open it; a CV still waiting in storage/prilohy whose plain file is gone opens from the copy an earlier run
     * sealed (sealedTarget()).
     *
     * @return array{name: string, content: string}|null
     */
    /**
     * The name and size of one attachment, without opening it (3.46.2: the download checks the memory first); null when
     * there is none.
     *
     * @return array{name: string, size: int}|null
     */
    public static function attachmentInfo(App $app, int $id, int $index): ?array
    {
        $row = $app->db()->one('SELECT attachments FROM {applications} WHERE id = ?', [$id]);
        $a = $row !== null ? (self::attachments($app->settings(), $row['attachments'] ?? null)[$index] ?? null) : null;

        return $a === null ? null : ['name' => (string) ($a['name'] ?? ''), 'size' => (int) ($a['size'] ?? 0)];
    }

    public static function attachment(App $app, int $id, int $index): ?array
    {
        $s = $app->settings();
        $row = $app->db()->one('SELECT attachments FROM {applications} WHERE id = ?', [$id]);
        $a = $row !== null ? (self::attachments($s, $row['attachments'] ?? null)[$index] ?? null) : null;
        $path = $a !== null ? self::filePath($a) : null;
        if ($a === null || $path === null) {
            return null;
        }
        if (empty($a['sealed'])) {
            $raw = is_file($path) ? @file_get_contents($path) : false;
            $raw = is_string($raw) ? $raw : self::openSealed($s, self::FOLDER . '/' . self::sealedTarget((string) $a['path']), null);

            return $raw === null ? null : ['name' => (string) $a['name'], 'content' => $raw];
        }
        $plain = self::openSealed($s, $path, is_string($a['key'] ?? null) ? $a['key'] : null);

        return $plain === null ? null : ['name' => (string) $a['name'], 'content' => $plain];
    }

    /**
     * The raw attachment list of a row (with paths and keys – stays inside this class and the download): the sealed JSON
     * opened with the Vault key of the purpose; a plain JSON list is read too (migration 0120 on a server where sealing
     * was not possible). Unreadable = no attachments.
     *
     * @return list<array<string, mixed>>
     */
    public static function attachments(Settings $s, mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        $json = str_starts_with($stored, '[') ? $stored : self::openList($s, $stored);
        $list = $json !== null ? json_decode($json, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** The JSON of a sealed attachment list, null when it cannot be opened (a DB_SEALED one with the key kept in the database). */
    private static function openList(Settings $s, string $stored): ?string
    {
        if (str_starts_with($stored, self::DB_SEALED)) {
            $sealed = substr($stored, strlen(self::DB_SEALED));
            [$previous] = Vault::previousKey($s);

            return Vault::open(Vault::derive((new Antispam($s->db(), $s))->key(), Applications::SECTION), $sealed) ?? ($previous !== null ? Vault::open(Vault::derive($previous, Applications::SECTION), $sealed) : null);
        }

        return Vault::openFor($s, Applications::SECTION, $stored);
    }

    /**
     * An attachment list is stored but cannot be opened – the encryption key (Vault) is not the one it was sealed with
     * (3.34 review M2, M5): never "no attachments", and nothing may move or delete as if there were none.
     */
    public static function attachmentsLocked(Settings $s, mixed $stored): bool
    {
        return is_string($stored) && $stored !== '' && !str_starts_with($stored, '[') && self::openList($s, $stored) === null;
    }

    /**
     * The attachment list as stored: sealed JSON, null when there is none.
     *
     * @param list<array<string, mixed>> $list
     */
    public static function encodeAttachments(Settings $s, array $list): ?string
    {
        if ($list === []) {
            return null;
        }
        $json = (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (Vault::state($s) === 'missing') {
            // M5: the data belongs to a key config.php does not have – a list sealed now would be lost once the key is put back.
            // It is sealed with the key kept in the database instead, marked (DB_SEALED), and sealPending() reseals it once the
            // key is back; the event says so (receive()).
            return self::DB_SEALED . Vault::seal(Vault::derive((new Antispam($s->db(), $s))->key(), Applications::SECTION), $json);
        }

        return Vault::seal(Vault::key($s, Applications::SECTION), $json);
    }

    /** The mark of a list sealed with the database key while the Vault key was missing (encodeAttachments). */
    public const string DB_SEALED = 'db:';

    /**
     * Applications whose attachment list cannot be opened (3.34 review M5 – System status): how many; 0 before 0119.
     */
    public static function lockedCount(App $app): int
    {
        $s = $app->settings();
        $count = 0;
        try {
            foreach ($app->db()->all('SELECT attachments FROM {applications} WHERE attachments IS NOT NULL') as $row) {
                $count += self::attachmentsLocked($s, $row['attachments']) ? 1 : 0;
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }

        return $count;
    }

    /**
     * The database lock the file work of applications takes (3.34 second review N4): sealPending() and a move of
     * applications either way never run at the same time. $wait seconds; false when another holds it.
     */
    public static function lockFiles(Db $db, int $wait): bool
    {
        return (int) $db->value('SELECT GET_LOCK(' . self::LOCK_NAME . ', ' . max(0, $wait) . ')', [$db->prefix]) === 1;
    }

    public static function unlockFiles(Db $db): void
    {
        $db->run('SELECT RELEASE_LOCK(' . self::LOCK_NAME . ')', [$db->prefix]);
    }

    /**
     * The name of that lock (3.34 third review F8): MySQL locks are server-wide, so it carries the database and the table
     * prefix – two sites on one server with the same prefix never wait for each other. Hashed: at most 64 characters.
     */
    private const string LOCK_NAME = "CONCAT('kaleta_cv_', LEFT(SHA2(CONCAT(IFNULL(DATABASE(), ''), '|', ?), 256), 40))"; // SHA2: newer MySQL has no SHA1

    /**
     * Seals the files of applications moved from enquiries (migration 0120, the move by form: still in storage/prilohy,
     * sealed false) – at most $limit files per run, from the daily clean-up. 3.34 review M1: the rows are walked with an
     * id cursor (the setting applications_seal_after), so a row whose file is gone or cannot be sealed does not hold the
     * others back; at the end the cursor starts over. A file that is gone stays listed (the download says it no longer
     * exists). 3.34 second review N1: every file goes through sealEntry() – crash-safe, an interrupted run is finished by
     * the next one – and the run ends with sweepOrphans() (third review F2: once a day); N4: under lockFiles(), never while a move runs. Returns how many
     * files were sealed.
     */
    public static function sealPending(App $app, int $limit = self::SEAL_BATCH): int
    {
        $db = $app->db();
        try {
            if (!self::lockFiles($db, 0)) {
                return 0; // N4: a move runs – the next clean-up seals
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
        try {
            return self::sealPendingLocked($app, $limit);
        } finally {
            self::unlockFiles($db);
        }
    }

    /** sealPending() under the lock. */
    private static function sealPendingLocked(App $app, int $limit): int
    {
        $db = $app->db();
        $s = $app->settings();
        $sealed = 0;
        $after = $s->int('applications_seal_after');
        while (true) {
            try {
                $rows = $db->all('SELECT id, attachments FROM {applications} WHERE source_enquiry_id IS NOT NULL AND attachments IS NOT NULL AND id > ? ORDER BY id LIMIT 200', [$after]);
            } catch (\PDOException $e) {
                Db::rethrowUnlessMissing($e);
                return 0;
            }
            if ($rows === []) {
                $s->set('applications_seal_after', '0'); // the end: the next run starts over (rows moved since)
                break;
            }
            foreach ($rows as $row) {
                $list = self::attachments($s, $row['attachments']);
                // unsealed ones, and sealed ones whose plain file an interrupted run left in storage/prilohy
                $waiting = array_keys(array_filter($list, fn (array $a): bool => empty($a['sealed'])
                    || (is_string($a['from'] ?? null) && $a['from'] !== '' && is_file((string) self::filePath(['path' => $a['from'], 'sealed' => false])))));
                if ($sealed > 0 && count(array_filter($waiting, fn (int $i): bool => empty($list[$i]['sealed']))) > $limit - $sealed) {
                    $s->set('applications_seal_after', (string) $after); // this row whole next time
                    break 2;
                }
                foreach ($waiting as $i) {
                    $sealed += in_array(self::sealEntry($app, (int) $row['id'], $i), ['sealed', 'recovered'], true) ? 1 : 0;
                }
                $after = (int) $row['id'];
            }
            $s->set('applications_seal_after', (string) $after);
        }
        if ($s->get('applications_swept_on') !== Clock::today()) { // 3.34 third review F2: the sweep reads every row – once a day, not on every cron run
            $s->set('applications_swept_on', Clock::today());
            self::sweepOrphans($app);
        }

        return $sealed;
    }

    /**
     * One file of an application sealed, crash-safe (3.34 second review N1). The steps, each one safe to be the last a
     * killed request ran:
     *  1. the sealed copy is written at sealedTarget() of its old path (written next to it and renamed) – a copy an earlier
     *     run left there is taken when it opens to the same content;
     *  2. the row's list names the copy with its key and the old path (from) – only when the list is still the one this run
     *     read (N4: a move back or a delete meanwhile wins, a copy written for nothing goes);
     *  3. the copy is opened with the key the row now holds and compared with the original;
     *  4. only then the plain file in storage/prilohy goes.
     * A plain file that is gone is taken back from a copy an earlier run sealed (a database restored from a backup older
     * than the sealing – the key from the copy's header); an entry sealed already whose plain file is still there (a run
     * killed after step 2 or 3) is checked and the plain file removed, or – when the copy does not open to it – listed
     * plain again. $steps (tests): the run stops after this step, as a killed request would.
     *
     * @return 'sealed'|'recovered'|'cleaned'|'reverted'|'gone'|'failed'|'changed'|'skip'|'stopped'
     */
    public static function sealEntry(App $app, int $id, int $index, int $steps = 4): string
    {
        $db = $app->db();
        $s = $app->settings();
        $row = $db->one('SELECT attachments FROM {applications} WHERE id = ?', [$id]);
        if ($row === null || self::attachmentsLocked($s, $row['attachments'])) {
            return 'skip';
        }
        $list = self::attachments($s, $row['attachments']);
        $a = $list[$index] ?? null;
        if ($a === null) {
            return 'skip';
        }
        if (!empty($a['sealed'])) {
            return self::cleanLeftover($app, $id, $index, $list, (string) $row['attachments']);
        }
        $oldFile = self::filePath($a);
        if ($oldFile === null) {
            return 'skip';
        }
        $target = self::sealedTarget((string) $a['path']);
        $targetFile = self::FOLDER . '/' . $target;
        $plain = is_file($oldFile) ? @file_get_contents($oldFile) : false;
        $wrote = false;
        if (!is_string($plain)) {
            // gone from storage/prilohy: a copy sealed by an earlier run (a restored backup) is taken back
            $key = is_file($targetFile) ? self::headerKey($s, $targetFile) : null;
            if ($key === null || self::openSealed($s, $targetFile, $key) === null) {
                return 'gone';
            }
            $plain = null;
        } else {
            $key = is_file($targetFile) ? self::headerKey($s, $targetFile) : null; // a copy an interrupted run left
            $existing = $key !== null ? self::openSealed($s, $targetFile, $key) : null;
            if ($existing === null || !hash_equals(hash('sha256', $plain), hash('sha256', $existing))) {
                $key = self::writeSealed($s, $plain, $target);
                $wrote = true;
                if ($key === null) {
                    return 'failed';
                }
            }
        }
        if ($steps < 2) {
            return 'stopped';
        }
        $list[$index] = ['path' => $target, 'key' => $key, 'sealed' => true, 'from' => (string) $a['path']] + $a;
        if ($db->run('UPDATE {applications} SET attachments = ?, attachment_paths = ? WHERE id = ? AND attachments = ?',
            [self::encodeAttachments($s, $list), self::paths($list), $id, (string) $row['attachments']])->rowCount() !== 1) {
            if ($wrote && $db->value('SELECT 1 FROM {applications} WHERE id = ?', [$id]) === null) {
                @unlink($targetFile); // the application went meanwhile – nobody lists the copy
            }

            return 'changed';
        }
        if ($steps < 3) {
            return 'stopped';
        }
        $now = $db->one('SELECT attachments FROM {applications} WHERE id = ?', [$id]);
        $stored = $now !== null ? (self::attachments($s, $now['attachments'])[$index] ?? null) : null;
        $check = $stored !== null && ($stored['path'] ?? '') === $target ? self::openSealed($s, $targetFile, is_string($stored['key'] ?? null) ? $stored['key'] : null) : null;
        if ($check === null || ($plain !== null && !hash_equals(hash('sha256', $plain), hash('sha256', $check)))) {
            return 'failed'; // the plain file stays; the next run lists it plain again (cleanLeftover)
        }
        if ($steps < 4 || $plain === null) {
            return $plain === null ? 'recovered' : 'stopped';
        }
        @unlink($oldFile);

        return 'sealed';
    }

    /**
     * A sealed entry whose old plain file is still in storage/prilohy (a run killed between steps 2 and 4 of sealEntry()):
     * the plain file goes when the sealed copy opens to the same content; otherwise the entry is listed plain again, so
     * nothing depends on a copy that does not open.
     *
     * @param list<array<string, mixed>> $list
     * @return 'cleaned'|'reverted'|'changed'|'skip'
     */
    private static function cleanLeftover(App $app, int $id, int $index, array $list, string $stored): string
    {
        $s = $app->settings();
        $a = $list[$index];
        $from = is_string($a['from'] ?? null) ? $a['from'] : '';
        $oldFile = $from !== '' ? self::filePath(['path' => $from, 'sealed' => false]) : null;
        $plain = $oldFile !== null && is_file($oldFile) ? @file_get_contents($oldFile) : false;
        if (!is_string($plain)) {
            return 'skip';
        }
        $file = self::filePath($a);
        $sealed = $file !== null ? self::openSealed($s, $file, is_string($a['key'] ?? null) ? $a['key'] : null) : null;
        if ($sealed !== null && hash_equals(hash('sha256', $plain), hash('sha256', $sealed))) {
            @unlink((string) $oldFile);

            return 'cleaned';
        }
        $list[$index] = ['field' => $a['field'] ?? -1, 'name' => $a['name'] ?? '', 'size' => $a['size'] ?? 0, 'ext' => $a['ext'] ?? '', 'path' => $from, 'sealed' => false];

        return $app->db()->run('UPDATE {applications} SET attachments = ?, attachment_paths = ? WHERE id = ? AND attachments = ?',
            [self::encodeAttachments($s, $list), self::paths($list), $id, $stored])->rowCount() === 1 ? 'reverted' : 'changed';
    }

    /**
     * Encrypted files no application lists (3.34 second review N1 – a run killed after writing a copy, a move back killed
     * before it removed the sealed copy, an upload whose row was never stored, a database restored from an older backup),
     * and half-written ones (.part). 3.34 third review F1: age alone never removes a CV – an unlisted .bin older than
     * ORPHAN_DAYS is set aside (renamed to ORPHAN_SUFFIX, its time set to now) and removed only once it has stayed unlisted
     * for ORPHAN_DAYS more; a set-aside file a row lists again is put back. A .part older than ORPHAN_DAYS goes at once.
     * What a row lists is read from attachment_paths (always readable) – while a row without it has a list that cannot be
     * opened (what it lists is unknown), only files whose header this site's key opens are taken. The copy sealedTarget()
     * would give a CV still waiting in storage/prilohy – of an application or of an enquiry (N9: a restored backup takes it
     * back) – counts as listed; F2: the enquiries are read only when a file is still unlisted after the applications. Returns
     * how many files went.
     */
    public static function sweepOrphans(App $app): int
    {
        $s = $app->settings();
        $cutoff = time() - self::ORPHAN_DAYS * 86400;
        $files = array_filter(glob(self::FOLDER . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*') ?: [], 'is_file');
        $aside = array_filter($files, fn (string $f): bool => str_ends_with($f, '.bin' . self::ORPHAN_SUFFIX));
        $old = array_filter($files, fn (string $f): bool => (str_ends_with($f, '.bin') || str_ends_with($f, '.part')) && (int) @filemtime($f) < $cutoff);
        if ($old === [] && $aside === []) {
            return 0;
        }
        $listed = [];
        $unknown = false;
        try {
            foreach ($app->db()->all('SELECT attachments, attachment_paths FROM {applications} WHERE attachments IS NOT NULL OR attachment_paths IS NOT NULL') as $row) {
                if ($row['attachment_paths'] === null && self::attachmentsLocked($s, $row['attachments'])) {
                    $unknown = true; // what it lists is unknown – only files this site's key opens can be orphans then
                    continue;
                }
                $paths = $row['attachment_paths'] ?? self::paths(self::attachments($s, $row['attachments']));
                foreach (self::pathFiles($paths) as $file) {
                    $listed[$file] = true;
                }
            }
            // F2: the enquiries are read only for a file the applications leave unlisted
            $candidates = array_filter([...$old, ...array_map(fn (string $f): string => substr($f, 0, -strlen(self::ORPHAN_SUFFIX)), $aside)],
                fn (string $f): bool => str_ends_with($f, '.bin') && !isset($listed[$f]));
            // N9: an enquiry restored from a backup older than the sealing opens its CV from the copy (Enquiries::actionAttachment)
            $after = 0;
            while ($candidates !== [] && ($enquiries = $app->db()->all('SELECT idp, data FROM {poptavky} WHERE idp > ? ORDER BY idp LIMIT 500', [$after])) !== []) {
                foreach ($enquiries as $e) {
                    $after = (int) $e['idp'];
                    foreach (json_decode((string) $e['data'], true) ?: [] as $field) {
                        if (is_array($field) && is_string($field[2] ?? null) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', $field[2]) === 1) {
                            $listed[self::FOLDER . '/' . self::sealedTarget($field[2])] = true;
                        }
                    }
                }
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
        $removed = 0;
        foreach ($aside as $file) {
            $original = substr($file, 0, -strlen(self::ORPHAN_SUFFIX));
            if (isset($listed[$original])) {
                is_file($original) ? @unlink($file) : @rename($file, $original); // listed again (a newer backup restored): back
            } elseif (($mtime = (int) @filemtime($file)) > 0 && $mtime < $cutoff && (!$unknown || self::headerKey($s, $file) !== null)) {
                $removed += @unlink($file) ? 1 : 0; // unlisted for ORPHAN_DAYS since it was set aside
            }
        }
        foreach ($old as $file) {
            if (str_ends_with($file, '.part')) {
                $removed += @unlink($file) ? 1 : 0;
            } elseif (!isset($listed[$file]) && (!$unknown || self::headerKey($s, $file) !== null) && @touch($file)) {
                // F1: the seven days count from now, not from when the file was written. Touch first and only then set
                // the file aside, so a set-aside file never keeps its old time (a failed touch leaves it where it is).
                @rename($file, $file . self::ORPHAN_SUFFIX);
            }
        }

        return $removed;
    }

    /**
     * Lists sealed with the database key while the Vault key was missing (DB_SEALED) are sealed again with the proper key
     * once it is back (3.34 review M5 – the daily clean-up). Returns how many.
     */
    public static function resealHeld(App $app): int
    {
        $s = $app->settings();
        if (Vault::state($s) === 'missing') {
            return 0;
        }
        $done = 0;
        try {
            $rows = $app->db()->all("SELECT id, attachments FROM {applications} WHERE attachments LIKE 'db:%' LIMIT 500");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
        foreach ($rows as $row) {
            $json = self::openList($s, (string) $row['attachments']);
            $list = $json !== null ? json_decode($json, true) : null;
            if (is_array($list)) {
                $app->db()->update('applications', ['attachments' => self::encodeAttachments($s, array_values(array_filter($list, 'is_array')))], ['id' => (int) $row['id']]);
                $done++;
            }
        }

        return $done;
    }

    /** CV lists still held under the key in the database (DB_SEALED – 3.34 second review N10: SigningKey::rotate refuses while any is left). */
    public static function heldCount(Db $db): int
    {
        try {
            return (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE attachments LIKE 'db:%'");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /**
     * How many CVs moved from enquiries still wait in storage/prilohy to be sealed (3.34 review M1 – the settings tab says
     * so): unsealed entries whose file is still there.
     */
    public static function unsealedCount(App $app): int
    {
        $s = $app->settings();
        $count = 0;
        try {
            foreach ($app->db()->all('SELECT attachments FROM {applications} WHERE source_enquiry_id IS NOT NULL AND attachments IS NOT NULL') as $row) {
                foreach (self::attachments($s, $row['attachments']) as $a) {
                    $path = empty($a['sealed']) ? self::filePath($a) : null;
                    $count += $path !== null && is_file($path) ? 1 : 0;
                }
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0119
        }

        return $count;
    }
}
