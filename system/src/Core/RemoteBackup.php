<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Copy of the database backup off the server: FTP/FTPS (another hosting, NAS) or S3-compatible storage
 * (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2…). A backup on the same disk as the site does not protect against
 * losing the hosting. Since 1.8 the media go to the same place, incrementally (syncMedia). No libraries: S3 through cURL
 * with an AWS Signature V4 signature; FTPS through cURL too since 3.19.1 (N80) – PHP's FTP extension cannot check the
 * server's certificate, cURL checks it like the S3 path does. Only a server whose PHP has no cURL with FTPS falls back to
 * the extension, and the Backups tab says that the certificate is not checked there (ftpsVerified()).
 *
 * Since 3.28 (D-W12) the database backup leaves the server only encrypted at rest (Core\BackupEncryption under the
 * recovery key, Core\RecoveryKey). The media copy stays as it is: media/ is the site's public folder – every file in it
 * is served to any visitor by its address – and the customer data are in the database. Since 3.46 (N165) the private
 * folders (PRIVATE_FOLDERS: CVs, internal files, whistleblowing attachments, the attachments of requests to Claude, enquiry
 * attachments) go to the same target too, under private/, incrementally like the media (syncPrivate) – but every file
 * only encrypted at rest with BackupEncryption under the recovery key, as <path>.enc, never in the clear; until the
 * recovery key is saved nothing private leaves the server. Most of them are sealed on the disk already (Core\SealedFiles):
 * restoring them needs the recovery key for the copy and the site's vault_key (config.php) for the content.
 */
final class RemoteBackup
{
    /** Which media files are already copied (path => "size:mtime") and to which target. */
    public const string MEDIA_MANIFEST = Backup::FOLDER . '/media-kopie.json';

    /** Which private files are already copied (path => "size:mtime") and to which target under which recovery key (3.46). */
    public const string PRIVATE_MANIFEST = Backup::FOLDER . '/private-kopie.json';

    /** The private folders of the off-site copy (3.46, N165), relative to the site root – each file encrypted (syncPrivate). */
    public const array PRIVATE_FOLDERS = ['storage/applications', 'storage/internal-files', 'storage/oznameni', 'storage/requests', 'storage/prilohy'];

    /** 3.46.1 (re-audit M4): failed deletions of a private copy after which it counts as "could not be deleted" (still tried). */
    public const int DELETE_TRIES = 3;

    /** 3.46.1 (N2): at most this many deletions of private copies per run – the uploads after them always get their turn. */
    public const int DELETES_PER_RUN = 200;

    /** Seconds between background runs of the media copy when nothing is waiting. */
    private const int MEDIA_INTERVAL = 3600;

    /** remote_backup_status when a backup was not copied because the recovery key has not been saved yet (3.28). */
    public const string PAUSED = 'paused';

    /** The longest short system text of a failed off-site copy (failureText, 3.31.1). */
    public const int FAILURE_LENGTH = 200;

    /** Days between two backup.copy_paused events (and so alert e-mails) while the copies wait for the recovery key. */
    private const int PAUSED_EVENT_DAYS = 7;

    /**
     * Copies one database backup off the server – encrypted (3.28, D-W12): BackupEncryption under the recovery key, as
     * <name>.enc, through a temporary file in storage/zalohy/ that is deleted afterwards; the local backup stays as it is.
     * Until the administrator has saved the recovery key (RecoveryKey::ready) nothing is uploaded: the status says
     * "paused", System status, the Backups tab and the hand-over checklist say why, and an alert goes out (event
     * backup.copy_paused, at most once a week) – a backup leaves the server only encrypted, never in the clear.
     *
     * @return string|null error text, null = uploaded (or remote backup is turned off)
     */
    public static function upload(Settings $s, string $path): ?string
    {
        if (!self::isOn($s)) {
            return null;
        }
        if (!RecoveryKey::ready($s)) {
            $s->set('remote_backup_status', date('Y-m-d H:i') . '|' . self::PAUSED);
            self::recordPaused($s);

            return self::pausedText();
        }
        $encrypted = null;
        try {
            $key = RecoveryKey::load();
            if ($key === null) {
                throw new \RuntimeException(self::pausedText());
            }
            $encrypted = dirname($path) . '/.upload-' . bin2hex(random_bytes(6)) . BackupEncryption::EXTENSION;
            BackupEncryption::encryptFile($path, $encrypted, $key);
            [$put, $close, $list, $delete] = self::open($s);
            try {
                $put(basename($path) . BackupEncryption::EXTENSION, $encrypted);
                self::pruneRemote($s, $list, $delete, basename($path) . BackupEncryption::EXTENSION);
            } finally {
                $close();
            }
            $error = null;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if ($encrypted !== null) {
                @unlink($encrypted);
            }
        }
        if ($error === null) {
            $s->set('remote_backup_status', date('Y-m-d H:i') . '|ok');

            return null;
        }
        // 3.31.1: a failed copy (connection, sign-in, quota, the FTPS certificate…) is the event backup.failed, once per failed
        // upload, so the alert e-mail, System status and the fleet heartbeat see it – with a short system text, never a
        // secret; the caller still gets the full text to show the administrator once
        $text = self::failureText($error, $s);
        $s->set('remote_backup_status', date('Y-m-d H:i') . '|' . $text);
        Events::record($s->db(), 'backup.failed', 'error', t('The off-site copy of the backup failed: %s', $text), ['file' => basename($path), 'offsite' => true]);

        return $error;
    }

    /**
     * 3.46 (N157, owner decision 8): the off-site database copies older than backup_remote_days (90 by default; 0 = Kaleta
     * deletes none – a lifecycle rule of the bucket does it) are deleted after a successful upload, in the same connection.
     * Only Kaleta's own backup names of this site (review L6: its mark, Backup::siteMark) in the backup folder itself, never
     * the media/ or private/ folder or another file, never the copy just uploaded; copies uploaded before 3.46 (no mark) and
     * unencrypted copies from before 3.28 stay – Kaleta never deletes them silently. A failure is an event and never fails the upload.
     *
     * @param callable(): list<string> $list
     * @param callable(string): void $delete
     */
    private static function pruneRemote(Settings $s, callable $list, callable $delete, string $uploaded): int
    {
        $days = Retention::effective($s, 'offsite_backups'); // 3.48 B
        if ($days <= 0) {
            return 0;
        }
        $deleted = 0;
        try {
            foreach (self::expired($list(), $days, Clock::at('-' . $days . ' days'), $uploaded, Backup::siteMark($s->db())) as $name) {
                $delete($name);
                $deleted++;
            }
        } catch (\Throwable $e) {
            Events::record($s->db(), 'backup.prune_failed', 'warning', t('Old off-site copies of the backup could not be deleted: %s', self::failureText($e->getMessage(), $s)), ['offsite' => true]);
        }

        return $deleted;
    }

    /**
     * Which off-site copies are past the retention (pure): Kaleta's encrypted backup names (….enc) made before $before (the
     * site's wall time, from the name), never $keep.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function expired(array $names, int $days, string $before, string $keep = '', string $mark = ''): array
    {
        if ($days <= 0 || preg_match('/^s[a-f0-9]{8}$/D', $mark) !== 1) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if (str_contains($name, '/')) {
                continue; // never a file of another folder (media/)
            }
            $made = RestoreLedger::madeAt($name);
            // review L6: only this site's names (its mark before .sql) – a neighbour sharing the folder, a copy kept by hand under
            // another name and the unencrypted copies from before 3.28 are never deleted by Kaleta
            if ($name !== $keep && $made !== null && $made < $before && Backup::encrypted($name) && preg_match('/^kaleta-[0-9a-z-]+-' . $mark . '\.sql(\.gz)?\.enc$/D', $name) === 1) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The short system text of a failed off-site copy (3.31.1) for the status, the event and so the alert e-mail and the
     * heartbeat: one line (no "|") of at most FAILURE_LENGTH characters, without the target's password and user name (a server's
     * reply may repeat them), credentials in an address, e-mail addresses or this site's folder on the disk. Pure and
     * idempotent (Health runs it again over a status saved before 3.31.1).
     */
    public static function failureText(string $error, Settings $s): string
    {
        $flat = static fn (string $text): string => trim((string) preg_replace('/[\s|]+/u', ' ', $text)); // one line; "|" separates the parts of the status
        $secrets = array_values(array_filter([$s->get('backup_password'), $s->get('backup_user')], static fn (string $secret): bool => mb_strlen($secret) >= 4));
        // the secrets are masked in the raw text first – a password with "|" or two spaces no longer matches once the text is
        // one line – and then once more as one line themselves (a server that already squeezed the spaces of its reply)
        $text = $flat(str_ireplace($secrets, '…', $error));
        foreach ($secrets as $secret) {
            if (mb_strlen($flat($secret)) >= 4) {
                $text = str_ireplace($flat($secret), '…', $text);
            }
        }
        $text = (string) preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s/@]+@#i', '$1', $text); // ftp://user:pass@host → ftp://host
        $text = (string) preg_replace('/[^\s@<>()"\']+@[^\s@<>()"\']+\.[a-z]{2,}/i', '…', $text); // e-mail addresses
        $text = str_replace([KALETA_ROOT . '/', KALETA_ROOT], '', $text);

        return mb_strlen($text) > self::FAILURE_LENGTH ? rtrim(mb_substr($text, 0, self::FAILURE_LENGTH - 1)) . '…' : $text;
    }

    /** Why a backup was not copied off the server while the recovery key waits (3.28). */
    public static function pausedText(): string
    {
        return t('Off-site copies are paused until the recovery key is saved (Settings → Backups and updates).');
    }

    /** Is the last status "paused" (3.28)? */
    public static function paused(Settings $s): bool
    {
        return (explode('|', $s->get('remote_backup_status'), 2)[1] ?? '') === self::PAUSED;
    }

    /** The event (and so the alert e-mail, Alerts::WARNINGS) of paused copies – at most once a week. */
    private static function recordPaused(Settings $s): void
    {
        $db = $s->db();
        try {
            $recent = $db->value('SELECT 1 FROM {events} WHERE type = ? AND created_at > ? LIMIT 1', ['backup.copy_paused', date('Y-m-d H:i:s', time() - self::PAUSED_EVENT_DAYS * 86400)]);
        } catch (\Throwable) {
            $recent = null;
        }
        if ($recent === null) {
            Events::record($db, 'backup.copy_paused', 'warning', t('A backup was not copied off the server: off-site copies are encrypted now and wait until an administrator saves the recovery key in Settings → Backups and updates.'));
        }
    }

    public static function isOn(Settings $s): bool
    {
        if (Demo::active()) {
            return false;
        }
        return in_array($s->get('remote_backup'), ['ftp', 's3'], true) && $s->get('backup_host') !== '';
    }

    /**
     * Incremental copy of media/ to the same target as the database backups (1.8), into its folder media/. Only new and
     * changed files go (a manifest remembers what is there); a file deleted on the site stays in the copy. It runs for
     * at most $seconds and continues next time. Status: remote_media_status = "Y-m-d H:i|ok or error|files waiting".
     *
     * @return string|null error text, null = done or nothing to do
     */
    public static function syncMedia(Settings $s, int $seconds = 20): ?string
    {
        if (!self::isOn($s) || !$s->bool('backup_media')) {
            return null;
        }
        $target = sha1($s->get('remote_backup') . '|' . $s->get('backup_host') . '|' . $s->get('backup_folder'));
        $manifest = is_file(self::MEDIA_MANIFEST) ? json_decode((string) file_get_contents(self::MEDIA_MANIFEST), true) : null;
        if (!is_array($manifest) || ($manifest['cil'] ?? '') !== $target) {
            $manifest = ['cil' => $target, 'soubory' => []]; // a new target gets everything
        }
        $pending = [];
        foreach (SiteExport::mediaFiles() as $path => $size) {
            $signature = $size . ':' . (int) @filemtime(KALETA_ROOT . '/' . $path);
            if (($manifest['soubory'][$path] ?? '') !== $signature) {
                $pending[$path] = $signature;
            }
        }
        $error = null;
        $done = 0;
        if ($pending !== []) {
            $start = microtime(true);
            try {
                [$put, $close] = self::open($s);
                try {
                    foreach ($pending as $path => $signature) {
                        if (microtime(true) - $start > $seconds) {
                            break;
                        }
                        $put($path, KALETA_ROOT . '/' . $path);
                        $manifest['soubory'][$path] = $signature;
                        $done++;
                    }
                } finally {
                    $close();
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            if (!is_dir(Backup::FOLDER)) {
                @mkdir(Backup::FOLDER, 0775, true);
            }
            file_put_contents(self::MEDIA_MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $failing = !in_array(explode('|', $s->get('remote_media_status'))[1] ?? '', ['', 'ok'], true); // the copy before this one failed already
        $text = $error === null ? 'ok' : self::failureText($error, $s);
        $s->set('remote_media_status', date('Y-m-d H:i') . '|' . $text . '|' . (count($pending) - $done));
        if ($error !== null && !$failing) {
            // 3.31.1: the media copy runs every hour – its failure is one event when it starts failing, not one an hour
            Events::record($s->db(), 'backup.failed', 'error', t('The off-site copy of the media failed: %s', $text), ['offsite' => true, 'media' => true]);
        }

        return $error;
    }

    /**
     * Review M5 (pure): which copied private files are gone from the site and since when, and which of them are due for
     * deletion from the copy – gone for at least $days days ($days 0 = never). A file that came back is no longer gone.
     *
     * @param array<string, string> $copied the manifest's files (path => signature)
     * @param array<string, int> $gone path => when it was first missed
     * @param array<string, int> $files the files on the disk now
     * @return array{0: array<string, int>, 1: list<string>}
     */
    public static function privateGone(array $copied, array $gone, array $files, int $days, int $now): array
    {
        $gone = array_intersect_key($gone, $copied);
        foreach (array_keys($copied) as $path) {
            if (isset($files[$path])) {
                unset($gone[$path]);
            } else {
                $gone[$path] ??= $now;
            }
        }
        $due = $days > 0 ? array_map('strval', array_keys(array_filter($gone, fn (int $at): bool => $at <= $now - $days * 86400))) : [];

        return [$gone, $due];
    }

    /**
     * The files of the private folders (3.46): relative path => size; the deny-all .htaccess, hidden files and the
     * half-written copies of a run in progress (.part, .sealing) are left out.
     *
     * @return array<string, int>
     */
    public static function privateFiles(string $root = KALETA_ROOT): array
    {
        $files = [];
        foreach (self::PRIVATE_FOLDERS as $folder) {
            if (!is_dir($root . '/' . $folder)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $folder . '/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/' . $folder))), '/');
                if ($file->isFile() && !$file->isLink() && !preg_match('#(^|/)\.|\.(part|sealing)$#i', $path)) {
                    $files[$path] = (int) $file->getSize();
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Incremental copy of the private folders off the server (3.46, N165) into the folder private/ of the same target: only
     * new and changed files, each encrypted at rest with BackupEncryption under the recovery key first (a temporary
     * .enc in storage/zalohy/, deleted afterwards) and uploaded as <path>.enc. Nothing leaves the server until the
     * recovery key is saved (the status says "paused"); a new target or a new recovery key gets everything again. Review M5:
     * a file deleted on the site (an erased applicant's CV, a removed internal file) is deleted from the copy too, after
     * backup_remote_days like the database copies that may still name it (0 = never); the manifest remembers since when it
     * is gone. Runs for at most $seconds. Status: remote_private_status = "Y-m-d H:i|ok,
     * paused or error|files waiting".
     *
     * @return string|null error text, null = done, nothing to do, or paused for the recovery key
     */
    public static function syncPrivate(Settings $s, int $seconds = 20): ?string
    {
        if (!self::isOn($s)) {
            return null;
        }
        $files = self::privateFiles();
        $key = RecoveryKey::ready($s) ? RecoveryKey::load() : null;
        if ($key === null) {
            $s->set('remote_private_status', date('Y-m-d H:i') . '|' . self::PAUSED . '|' . count($files));

            return null; // the database copy says why (upload()); nothing private leaves the server unencrypted
        }
        $target = sha1($s->get('remote_backup') . '|' . $s->get('backup_host') . '|' . $s->get('backup_folder') . '|' . BackupEncryption::keyId($key));
        $manifest = is_file(self::PRIVATE_MANIFEST) ? json_decode((string) file_get_contents(self::PRIVATE_MANIFEST), true) : null;
        if (!is_array($manifest) || ($manifest['cil'] ?? '') !== $target) {
            $manifest = self::resetManifest(is_array($manifest) ? $manifest : null, $target, $files); // a new target or recovery key gets everything
        }
        $pending = [];
        foreach ($files as $path => $size) {
            $signature = $size . ':' . (int) @filemtime(KALETA_ROOT . '/' . $path);
            if (($manifest['soubory'][$path] ?? '') !== $signature) {
                $pending[$path] = $signature;
            }
        }
        [$gone, $due] = self::privateGone((array) ($manifest['soubory'] ?? []), (array) ($manifest['smazano'] ?? []), $files, Retention::effective($s, 'offsite_backups'), time());
        $goneChanged = $gone !== ($manifest['smazano'] ?? []);
        $manifest['smazano'] = $gone;
        $error = null;
        $done = 0;
        if ($due !== []) {
            try {
                [, $close, , $delete] = self::open($s);
                try {
                    foreach (array_slice(self::deleteOrder($due, (array) ($manifest['nesmazano'] ?? [])), 0, self::DELETES_PER_RUN) as $path) { // 3.46.1 (N2): the rest next time
                        if (!self::isPrivatePath($path)) { // 3.46.1 (re-audit L9): a tampered manifest deletes nothing outside private/
                            unset($manifest['soubory'][$path], $manifest['smazano'][$path], $manifest['nesmazano'][$path]);
                            continue;
                        }
                        try {
                            $delete('private/' . $path . BackupEncryption::EXTENSION);
                        } catch (\Throwable $e) {
                            // a passing failure is tried again next time; 3.46.1 (re-audit M4): after DELETE_TRIES the entry stays as
                            // failed – tried again on every run and shown in Settings → Backups, never dropped as gone
                            $tries = (int) ($manifest['nesmazano'][$path] ?? 0) + 1;
                            $manifest['nesmazano'][$path] = $tries;
                            if ($tries < self::DELETE_TRIES) {
                                $error ??= $e->getMessage();
                            }
                            continue;
                        }
                        unset($manifest['soubory'][$path], $manifest['smazano'][$path], $manifest['nesmazano'][$path]);
                    }
                } finally {
                    $close();
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            file_put_contents(self::PRIVATE_MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX);
        } elseif ($goneChanged && $pending === []) {
            file_put_contents(self::PRIVATE_MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX); // since when a file is gone
        }
        if ($pending !== []) {
            $start = microtime(true);
            if (!is_dir(Backup::FOLDER)) {
                @mkdir(Backup::FOLDER, 0775, true);
            }
            try {
                [$put, $close] = self::open($s);
                try {
                    foreach ($pending as $path => $signature) {
                        if (microtime(true) - $start > $seconds) {
                            break;
                        }
                        $encrypted = Backup::FOLDER . '/.upload-' . bin2hex(random_bytes(6)) . BackupEncryption::EXTENSION;
                        try {
                            BackupEncryption::encryptFile(KALETA_ROOT . '/' . $path, $encrypted, $key);
                            $put('private/' . $path . BackupEncryption::EXTENSION, $encrypted);
                        } finally {
                            @unlink($encrypted);
                        }
                        $manifest['soubory'][$path] = $signature;
                        $done++;
                    }
                } finally {
                    $close();
                }
            } catch (\Throwable $e) {
                $error ??= $e->getMessage();
            }
            file_put_contents(self::PRIVATE_MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $failing = !in_array(explode('|', $s->get('remote_private_status'))[1] ?? '', ['', 'ok', self::PAUSED], true);
        $text = $error === null ? 'ok' : self::failureText($error, $s);
        $s->set('remote_private_status', date('Y-m-d H:i') . '|' . $text . '|' . (count($pending) - $done));
        if ($error !== null && !$failing) {
            Events::record($s->db(), 'backup.failed', 'error', t('The off-site copy of the private files failed: %s', $text), ['offsite' => true, 'private' => true]);
        }

        return $error;
    }

    /**
     * The manifest of a new target or recovery key (pure): every file is copied again – 3.46.1 (re-audit M4): but a file copied
     * before and gone from the site since stays on the list with the day it went (and its failed deletions), so its copy is
     * still deleted after backup_remote_days instead of being forgotten.
     *
     * @param array<string, mixed>|null $old the manifest so far
     * @param array<string, int> $files the files on the disk now
     * @return array<string, mixed>
     */
    public static function resetManifest(?array $old, string $target, array $files): array
    {
        $carried = $old !== null ? array_diff_key((array) ($old['soubory'] ?? []), $files) : [];

        return ['cil' => $target, 'soubory' => $carried, 'smazano' => array_intersect_key((array) ($old['smazano'] ?? []), $carried),
            'nesmazano' => array_intersect_key((array) ($old['nesmazano'] ?? []), $carried)];
    }

    /** Is $path (relative to the site root) a file of one of the private folders – no "..", no backslash? Pure. */
    public static function isPrivatePath(string $path): bool
    {
        if (str_contains($path, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return false;
        }
        foreach (self::PRIVATE_FOLDERS as $folder) {
            if (str_starts_with($path, $folder . '/') && strlen($path) > strlen($folder) + 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 3.46.1 (re-audit M4): how many private files the off-site copy could not delete DELETE_TRIES times in a row (the target
     * refuses deletion – credentials that may write only); Settings → Backups shows the number.
     */
    public static function undeletedPrivate(): int
    {
        $manifest = is_file(self::PRIVATE_MANIFEST) ? json_decode((string) file_get_contents(self::PRIVATE_MANIFEST), true) : null;

        return is_array($manifest) ? count(array_filter((array) ($manifest['nesmazano'] ?? []), fn (mixed $tries): bool => (int) $tries >= self::DELETE_TRIES)) : 0;
    }

    /** From background tasks: at most once an hour, or right away while files are still waiting – the media and (3.46) the private folders. */
    public static function syncMediaInBackground(Settings $s, int $seconds): void
    {
        if (!self::isOn($s)) {
            return;
        }
        $waitingPrivate = (int) (explode('|', $s->get('remote_private_status'))[2] ?? 0);
        $paused = (explode('|', $s->get('remote_private_status'))[1] ?? '') === self::PAUSED;
        if ((($waitingPrivate > 0 && !$paused) || time() - $s->int('private_sync_check') >= self::MEDIA_INTERVAL)) {
            $s->set('private_sync_check', (string) time());
            self::syncPrivate($s, $seconds);
        }
        if (!$s->bool('backup_media')) {
            return;
        }
        $waiting = (int) (explode('|', $s->get('remote_media_status'))[2] ?? 0);
        if ($waiting === 0 && time() - $s->int('media_sync_check') < self::MEDIA_INTERVAL) {
            return;
        }
        $s->set('media_sync_check', (string) time());
        self::syncMedia($s, $seconds);
    }

    /**
     * A connection to the target: a function that uploads one file under a path relative to the backup folder, one that
     * closes the connection, and (3.46) one that lists the file names in the backup folder and one that deletes a file there.
     *
     * @return array{0: callable(string, string): void, 1: callable(): void, 2: callable(): list<string>, 3: callable(string): void}
     */
    private static function open(Settings $s): array
    {
        return $s->get('remote_backup') === 'ftp' ? self::ftp($s) : self::s3($s);
    }

    /**
     * 3.19.1 (N80): can this server send the FTPS copy with the server's certificate checked (cURL with FTPS)? When not,
     * the copy goes through PHP's FTP extension, which encrypts but cannot check whom it talks to.
     */
    public static function ftpsVerified(): bool
    {
        return function_exists('curl_init') && in_array('ftps', (array) (curl_version()['protocols'] ?? []), true);
    }

    /**
     * The address of one file on the FTP server for cURL (3.19.1): the folder from the settings is absolute (%2F – as
     * ftp_chdir('/' . folder) was before), each path segment is encoded; the host may carry a port and is never taken
     * with a scheme or a path.
     */
    public static function ftpUrl(string $host, string $folder, string $remote): string
    {
        $host = (string) preg_replace('#^[a-z]+://|/.*$#iD', '', trim($host));
        $parts = array_values(array_filter(explode('/', trim($folder, '/') . '/' . $remote), fn (string $p): bool => $p !== ''));

        return 'ftp://' . $host . '/' . (trim($folder, '/') !== '' ? '%2F' : '') . implode('/', array_map(rawurlencode(...), $parts));
    }

    /**
     * The FTP command that deletes $name (a file of the backup folder, or private/… below it) – review M6: with the folder's
     * absolute path, as the address of an upload names it (ftpUrl: %2F), because cURL sends it before changing into the
     * folder; without a folder the name is relative to the sign-in folder, where the uploads go. Pure.
     */
    public static function ftpDeleteCommand(string $folder, string $name): string
    {
        $folder = trim($folder, '/');
        $name = ltrim(str_replace(["\r", "\n"], '', $name), '/');

        return 'DELE ' . ($folder !== '' ? '/' . $folder . '/' : '') . $name;
    }

    /**
     * The cURL options of an FTPS upload (3.19.1, N80): explicit TLS (AUTH TLS) required for the commands and the data,
     * the certificate and the host name checked, only FTP spoken, missing folders created.
     *
     * @return array<int, mixed>
     */
    public static function ftpsOptions(string $user, string $password): array
    {
        return [
            CURLOPT_USE_SSL => CURLUSESSL_ALL, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS, CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
            CURLOPT_USERNAME => $user, CURLOPT_PASSWORD => $password, CURLOPT_UPLOAD => true,
            // N3191-9: no total time limit (a large backup over a slow line takes long) – a transfer that stalls below 1 kB/s
            // for a minute ends, and so does a connection not made in 30 s
            CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60, CURLOPT_RETURNTRANSFER => true,
        ];
    }

    /** @return array{0: callable(string, string): void, 1: callable(): void, 2: callable(): list<string>, 3: callable(string): void} */
    private static function ftp(Settings $s): array
    {
        if (self::ftpsVerified()) {
            return self::ftpsCurl($s);
        }
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('The PHP extension for FTP is missing on the server.');
        }
        $host = $s->get('backup_host');
        // encrypted FTPS only: the backup contains passwords and secret keys, over plain FTP they would travel the network
        // readable (the FTP password too)
        if (!function_exists('ftp_ssl_connect')) {
            throw new \RuntimeException('The server cannot use encrypted FTP (FTPS). Send backups to S3 storage, or download them manually.');
        }
        $connection = @ftp_ssl_connect($host, 21, 15);
        if ($connection === false) {
            throw new \RuntimeException(t('The FTP server %s does not support an encrypted connection (FTPS). Kaleta does not use unencrypted FTP – choose S3 storage.', $host));
        }
        if (!@ftp_login($connection, $s->get('backup_user'), $s->get('backup_password'))) {
            throw new \RuntimeException(t('Signing in to the FTP server %s failed.', $host));
        }
        ftp_pasv($connection, true);
        $folder = trim($s->get('backup_folder'), '/');
        if ($folder !== '' && !@ftp_chdir($connection, '/' . $folder)) {
            @ftp_mkdir($connection, '/' . $folder);
            if (!@ftp_chdir($connection, '/' . $folder)) {
                throw new \RuntimeException(t('The folder %s does not exist on the FTP server and cannot be created.', $folder));
            }
        }
        $made = [];
        $put = function (string $remote, string $local) use ($connection, &$made): void {
            // media/2026/09/photo.jpg: the folders on the way are created once per connection
            $dir = '';
            foreach (array_slice(explode('/', $remote), 0, -1) as $part) {
                $dir .= ($dir === '' ? '' : '/') . $part;
                if (!isset($made[$dir])) {
                    @ftp_mkdir($connection, $dir);
                    $made[$dir] = true;
                }
            }
            if (!@ftp_put($connection, $remote, $local, FTP_BINARY)) {
                throw new \RuntimeException(t('The file could not be uploaded to the FTP server.'));
            }
        };

        $list = fn (): array => array_values(array_map('basename', array_map('strval', @ftp_nlist($connection, '.') ?: [])));
        $delete = function (string $name) use ($connection): void {
            // 3.46.1 (N2): a file that is not there (deleted by hand, never on this server) counts as deleted – 3.50 N169: only
            // when the listing itself succeeded; a refused or broken listing keeps the entry for the next run
            if (!@ftp_delete($connection, $name)) {
                if (!self::goneFrom(fn (string $dir): array|false => @ftp_nlist($connection, $dir), $name)) {
                    throw new \RuntimeException(t('The file %s could not be deleted on the FTP server.', $name));
                }
            }
        };

        $close = function () use ($connection): void {
            @ftp_close($connection);
        };

        return [$put, $close, $list, $delete];
    }

    /**
     * 3.50 N169: does a listing that succeeded lack the file? False for a failed listing (false) – then nothing is known. The
     * names may come with their folder (ftp_nlist) or bare (a cURL listing). Pure.
     *
     * @param list<string>|array<int, string>|false $listing
     */
    public static function listingLacks(array|false $listing, string $name): bool
    {
        return is_array($listing) && !in_array(basename($name), array_map(fn (mixed $n): string => basename(trim((string) $n)), $listing), true);
    }

    /**
     * 3.50 review: the order in which due private files are deleted from the copy – those that already failed DELETE_TRIES
     * times go last (in their order), so they cannot fill the DELETES_PER_RUN slice of every run. Pure.
     *
     * @param list<string> $due
     * @param array<string, int> $tries the manifest's "nesmazano"
     * @return list<string>
     */
    public static function deleteOrder(array $due, array $tries): array
    {
        $failed = fn (int|string $path): bool => (int) ($tries[$path] ?? 0) >= self::DELETE_TRIES;

        return [...array_values(array_filter($due, fn (int|string $path): bool => !$failed($path))), ...array_values(array_filter($due, $failed))];
    }

    /**
     * 3.50 N169 + review: is the file gone from the target? Its folder's listing that succeeded and lacks it says so; when
     * the folder cannot be listed (ftp_nlist answers false for a folder removed by hand), the listing of the folder above it
     * that succeeded and lacks the folder says so, up to the backup folder. Only listings that failed all the way up, or a
     * folder that is there but cannot be read, leave it unknown (false). $list gets a folder ('.' = the backup folder) and
     * answers its names or false.
     *
     * @param callable(string): (array<int, string>|false) $list
     */
    public static function goneFrom(callable $list, string $name): bool
    {
        for ($missing = $name, $dir = dirname($name); ; $missing = $dir, $dir = dirname($dir)) {
            $listing = $list($dir);
            if ($listing !== false) {
                return self::listingLacks($listing, $missing);
            }
            if ($dir === '.' || $dir === '/' || $dir === '') {
                return false;
            }
        }
    }

    /** 3.50 N169: does an S3 answer to a DELETE say the object is gone – 204 is success; a 404 only with NoSuchKey. Pure. */
    public static function s3Gone(int $status, string $errorCode): bool
    {
        return $status === 404 && $errorCode === 'NoSuchKey';
    }

    /**
     * FTPS through cURL (3.19.1, N80): one handle for the whole run, so cURL keeps the control connection between files.
     *
     * @return array{0: callable(string, string): void, 1: callable(): void, 2: callable(): list<string>, 3: callable(string): void}
     */
    private static function ftpsCurl(Settings $s): array
    {
        $host = $s->get('backup_host');
        $folder = $s->get('backup_folder');
        $ch = curl_init();
        $put = function (string $remote, string $local) use ($ch, $s, $host, $folder): void {
            $f = fopen($local, 'rb');
            if ($f === false) {
                throw new \RuntimeException(t('The file could not be uploaded to the FTP server.'));
            }
            curl_setopt_array($ch, [CURLOPT_URL => self::ftpUrl($host, $folder, $remote), CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => (int) filesize($local)]
                + self::ftpsOptions($s->get('backup_user'), $s->get('backup_password')));
            $ok = curl_exec($ch) !== false;
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            fclose($f);
            if (!$ok) {
                throw new \RuntimeException(self::ftpsError($errno, $error, (string) preg_replace('#^[a-z]+://|[:/].*$#iD', '', $host), $folder));
            }
        };

        // 3.46: listing and deleting in the backup folder – a handle of their own, never an upload
        $folderUrl = rtrim(self::ftpUrl($host, $folder, ''), '/') . '/';
        $command = function (array $options) use ($s, $host, $folder, $folderUrl): string {
            $ch2 = curl_init();
            curl_setopt_array($ch2, $options + [CURLOPT_URL => $folderUrl, CURLOPT_UPLOAD => false] + self::ftpsOptions($s->get('backup_user'), $s->get('backup_password')));
            $out = curl_exec($ch2);
            if ($out === false) {
                throw new \RuntimeException(self::ftpsError(curl_errno($ch2), curl_error($ch2), (string) preg_replace('#^[a-z]+://|[:/].*$#iD', '', $host), $folder));
            }

            return is_string($out) ? $out : '';
        };
        $list = fn (): array => array_values(array_filter(array_map('trim', explode("\n", $command([CURLOPT_DIRLISTONLY => true]))), fn (string $n): bool => $n !== ''));
        // review M6: cURL sends QUOTE commands right after the sign-in, before it changes into the folder of the address – the
        // DELE names the file by its full path (ftpDeleteCommand), so it deletes in the backup folder and nowhere else
        $delete = function (string $name) use ($command, $folder, $host): void {
            try {
                $command([CURLOPT_QUOTE => [self::ftpDeleteCommand($folder, $name)], CURLOPT_NOBODY => true]);
            } catch (\RuntimeException $e) {
                // 3.46.1 (N2): a DELE refused because the file is not there (deleted by hand, never on this server) counts as
                // deleted – 3.50 N169: only when a listing of its folder succeeds and lacks the name (cURL's 78 also stands for
                // "not allowed to read", so the file itself is no proof); a failed listing keeps the entry
                $list = function (string $dir) use ($command, $host, $folder): array|false {
                    try {
                        return explode("\n", $command([CURLOPT_DIRLISTONLY => true] + ($dir !== '.' ? [CURLOPT_URL => rtrim(self::ftpUrl($host, $folder, $dir), '/') . '/'] : [])));
                    } catch (\RuntimeException) {
                        return false;
                    }
                };
                if (!self::goneFrom($list, $name)) {
                    throw $e;
                }
            }
        };

        return [$put, fn () => null, $list, $delete]; // PHP 8: the handle (and the connection) closes when $put is released
    }

    /** What a failed FTPS upload through cURL means for the administrator (N80: a certificate that cannot be checked is named). */
    public static function ftpsError(int $errno, string $error, string $host, string $folder): string
    {
        return match ($errno) {
            // the cURL error codes by number – PHP does not define every one of them: 60 peer failed verification, 51 its
            // older code, 77 CA file, 83 issuer, 90 pinned key
            60, 51, 77, 83, 90 => t('The certificate of the FTP server %s could not be checked (%s). Kaleta sends backups only to a server that proves who it is – ask the server\'s administrator for a certificate from a recognised authority (for example Let\'s Encrypt) for this name, or choose S3 storage.', $host, $error),
            CURLE_SSL_CONNECT_ERROR => t('The encrypted connection to the FTP server %s failed (%s).', $host, $error),
            64 /* CURLE_USE_SSL_FAILED */ => t('The FTP server %s does not support an encrypted connection (FTPS). Kaleta does not use unencrypted FTP – choose S3 storage.', $host),
            67 /* CURLE_LOGIN_DENIED */ => t('Signing in to the FTP server %s failed.', $host),
            CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT => t('The FTP server %s could not be reached (%s).', $host, $error),
            9 /* CURLE_REMOTE_ACCESS_DENIED */ => t('The folder %s does not exist on the FTP server and cannot be created.', trim($folder, '/')),
            default => t('The file could not be uploaded to the FTP server.') . ($error !== '' ? ' (' . $error . ')' : ''),
        };
    }

    /** @return array{0: callable(string, string): void, 1: callable(): void, 2: callable(): list<string>, 3: callable(string): void} */
    private static function s3(Settings $s): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException(t('The cURL extension is missing on the server.'));
        }
        $host = preg_replace('#^https?://|/.*$#', '', $s->get('backup_host')) ?? '';
        $bucket = trim($s->get('backup_folder'), '/');
        if ($bucket === '' || !preg_match('/^[a-z0-9.-]+$/iD', $host)) {
            throw new \RuntimeException(t('Fill in the storage address (e.g. s3.eu-central-1.amazonaws.com) and the bucket name.'));
        }
        // tests: the storage can be a local fake server (only through the database and only on a test copy)
        $base = 'https://' . $host;
        $test = TestSeams::address($s, 'backup_test_url');
        if ($test !== '' && preg_match('#^http://(127\.0\.0\.1:\d+)$#D', $test, $m)) {
            [$base, $host] = [$test, $m[1]];
        }
        $put = function (string $remote, string $local) use ($s, $host, $bucket, $base): void {
            $uri = '/' . implode('/', array_map(rawurlencode(...), explode('/', $bucket . '/' . $remote)));
            $headers = self::signS3('PUT', $host, $uri, (string) hash_file('sha256', $local), $s->get('backup_region') ?: 'us-east-1', $s->get('backup_user'), $s->get('backup_password'), time());
            $f = fopen($local, 'rb');
            $ch = curl_init($base . $uri);
            curl_setopt_array($ch, [
                CURLOPT_PUT => true, CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => filesize($local), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
                CURLOPT_PROTOCOLS => str_starts_with($base, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
            ]);
            $response = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            fclose($f);
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException(t('The storage refused the upload (code %s)', (string) $code) . (preg_match('#<Message>([^<]+)#', $response, $m) ? ': ' . $m[1] : ($code === 0 ? ': ' . t('could not connect') : '')) . '.');
            }
        };

        // 3.46: the bucket may carry a folder (bucket/folder): the listing asks the bucket for the names under the folder
        [$bucketName, $prefix] = explode('/', $bucket, 2) + [1 => ''];
        $prefix = $prefix !== '' ? trim($prefix, '/') . '/' : '';
        $request = function (string $method, string $uri, array $query) use ($s, $host, $base): string {
            ksort($query);
            $queryString = implode('&', array_map(fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v), array_keys($query), $query));
            $headers = self::signS3($method, $host, $uri, hash('sha256', ''), $s->get('backup_region') ?: 'us-east-1', $s->get('backup_user'), $s->get('backup_password'), time(), $queryString);
            $ch = curl_init($base . $uri . ($queryString !== '' ? '?' . $queryString : ''));
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_PROTOCOLS => str_starts_with($base, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers)]);
            $response = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code < 200 || $code >= 300) {
                // 3.50 N169: the storage's own error code (NoSuchKey, NoSuchBucket…) travels as the previous exception's message
                throw new \RuntimeException(t('The storage refused the request (code %s)', (string) $code) . (preg_match('#<Message>([^<]+)#', $response, $m) ? ': ' . $m[1] : '') . '.', $code,
                    new \RuntimeException(preg_match('#<Code>([^<]+)</Code>#', $response, $c) === 1 ? $c[1] : ''));
            }

            return $response;
        };
        $list = function () use ($request, $bucketName, $prefix): array {
            $names = [];
            $token = '';
            for ($page = 0; $page < 20; $page++) {
                $xml = $request('GET', '/' . rawurlencode($bucketName), ['list-type' => '2', 'prefix' => $prefix . 'kaleta-'] + ($token !== '' ? ['continuation-token' => $token] : []));
                preg_match_all('#<Key>([^<]+)</Key>#', $xml, $m);
                foreach ($m[1] as $key) {
                    $key = html_entity_decode($key, ENT_QUOTES | ENT_XML1);
                    if (str_starts_with($key, $prefix) && !str_contains(substr($key, strlen($prefix)), '/')) {
                        $names[] = substr($key, strlen($prefix));
                    }
                }
                $token = preg_match('#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $xml, $t) === 1 ? html_entity_decode($t[1], ENT_QUOTES | ENT_XML1) : '';
                if ($token === '') {
                    break;
                }
            }

            return $names;
        };
        $delete = function (string $name) use ($request, $bucket): void {
            try {
                $request('DELETE', '/' . implode('/', array_map(rawurlencode(...), explode('/', $bucket . '/' . $name))), []);
            } catch (\RuntimeException $e) {
                // 3.46.1 (N2): an object that is not there counts as deleted – 3.50 N169: only a 404 that says NoSuchKey (a
                // mistyped bucket answers 404 NoSuchBucket to everything)
                if (!self::s3Gone($e->getCode(), $e->getPrevious()?->getMessage() ?? '')) {
                    throw $e;
                }
            }
        };

        return [$put, fn () => null, $list, $delete];
    }

    /**
     * Request headers signed with AWS Signature Version 4 (service s3). $query: the canonical query string (3.46: the
     * listing) – names and values encoded and sorted by the caller.
     *
     * @return array<string, string>
     */
    public static function signS3(string $method, string $host, string $uri, string $bodyHash, string $region, string $key, string $secret, int $time, string $query = ''): array
    {
        $date = gmdate('Ymd\THis\Z', $time);
        $day = substr($date, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date];
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $uri . "\n" . $query . "\n" . implode('', array_map(fn (string $k, string $v): string => $k . ':' . $v . "\n", array_keys($headers), $headers)) . "\n" . $signedHeaders . "\n" . $bodyHash;
        $scope = $day . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $secret, true), true), true), true);

        return [
            'Host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . hash_hmac('sha256', $stringToSign, $k),
        ];
    }
}
