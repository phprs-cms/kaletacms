<?php
/**
 * Kaleta 3.46 (N162, N165, owner decision 5): private files sealed at rest like the CVs of job applicants (Core\SealedFiles).
 *
 *  1. The structure, under any code: ka_internal_file_versions.file_key (each internal file's own key, sealed) and
 *     ka_requests.files (the private attachments of a request, a sealed list) – added when missing, so the run can be
 *     repeated and a database whose column exists already is left as it is.
 *  2. The files of before 3.46: whistleblowing attachments (Core\Whistleblowing::sealPending) and internal files
 *     (Core\InternalFiles::sealPending) are sealed in place under the same name – a file whose header this site's key
 *     opens is sealed already and never sealed again; the plain file is replaced only after the sealed copy opened to
 *     the same content (for internal files: to the sha256 the version keeps); its key goes into the row only while the
 *     row is still the one the run read. A file that cannot be sealed (gone, changed, too large for the memory) stays
 *     as it is and readable – the daily jobs try again. Nothing is deleted.
 *
 * Interrupted (a killed request, out of time): every step leaves a state the next run finishes – a sealed copy left
 * beside the file (.sealing) is ignored and swept after an hour; a file sealed whose row still calls it plain opens by
 * its header and the next run takes the key from there. Each run works for at most Core\Migration::$seconds (2 s inside
 * a visitor's request) and puts its name into data_migrations_unfinished until nothing is left (the 0120 pattern), under
 * the lock of the file work (SealedFiles::lock) – never two runs at once.
 *
 * Review H1: a visitor's page request (Migration::$visitor) writes only the structure; the files are sealed from the
 * administration, cron and the daily job. A file a run died on (memory or time) is skipped by the next run
 * (SealedFiles::takeOverDeadRun) until the memory limit is raised.
 *
 * Under the code of an older release (the update request of 3.45 or older runs this file with its own classes) only the
 * structure is written; and nothing is sealed while an update checks itself (update_probe, at most 10 minutes): an update
 * that rolls back must find the files as its code reads them. The sealing starts on the first request of the 3.46 code.
 * Safe to run again.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $name = '0134-seal-private-files';
    $others = array_values(array_filter(explode(',', $settings->get('data_migrations_unfinished')), static fn (string $n): bool => $n !== '' && $n !== $name));
    $finish = static function (bool $left) use ($settings, $name, $others): void {
        $settings->set('data_migrations_unfinished', implode(',', $left ? [...$others, $name] : $others));
    };

    // --- 1. the structure
    foreach ([['internal_file_versions', 'file_key', 'sha256'], ['requests', 'files', 'attachments']] as [$table, $column, $after]) {
        $physical = trim($db->sql('{' . $table . '}'), '`');
        try {
            if ($db->value('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1', [$physical, $column]) === null) {
                $db->run('ALTER TABLE {' . $table . '} ADD COLUMN ' . $column . ' TEXT NULL AFTER ' . $after);
            }
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) { // 1060: added meanwhile by a parallel run
                Db::rethrowUnlessMissing($e);
            }
        }
    }

    // --- 2. the files – only under the 3.46 code, never while an update checks itself
    // the Migration class of the update request is the old release's (loaded before it ran this file): only the 3.46 one lists this
    // migration – a class autoloaded fresh from the new files would not tell
    $current = !defined('KALETA_MIGRATION_AS_OLD_CODE') && in_array($name, \Kaleta\Core\Migration::DATA, true) && class_exists(\Kaleta\Core\SealedFiles::class)
        && method_exists(\Kaleta\Core\Whistleblowing::class, 'sealPending') && method_exists(\Kaleta\Core\InternalFiles::class, 'sealPending');
    if (!$current) {
        $finish(true);

        return;
    }
    // 3.46 review H1: never inside a visitor's page request – the administration, cron (/ulohy) and the daily job seal, each
    // within its time budget, so a large file never costs a visitor's page its memory
    if (\Kaleta\Core\Migration::$visitor) {
        $finish(true);

        return;
    }
    $probe = (string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'update_probe'");
    if ($probe !== '') {
        $probeAt = (int) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'update_probe_at'");
        if ($probeAt <= 0) { // a probe an older release's updater wrote: counted from when this migration first saw it
            [$seenHash, $seenAt] = array_pad(explode('|', $settings->get('private_files_probe_seen'), 2), 2, '');
            $probeAt = hash_equals($seenHash, hash('sha256', $probe)) ? (int) $seenAt : 0;
            if ($probeAt <= 0) {
                $probeAt = time();
                $settings->set('private_files_probe_seen', hash('sha256', $probe) . '|' . $probeAt);
            }
        }
        $stale = defined('KALETA_MIGRATION_PROBE_STALE') ? (int) constant('KALETA_MIGRATION_PROBE_STALE') : 600;
        if (time() - $probeAt < $stale) {
            $finish(true);

            return;
        }
    }
    if ((int) ini_get('max_execution_time') > 0) {
        @set_time_limit(300);
    }
    // the tests make a run one file (KALETA_MIGRATION_ROWS) or stop it after a step (KALETA_MIGRATION_STOP_AFTER), as a killed request would
    $seconds = defined('KALETA_MIGRATION_SECONDS') ? max(0.0, (float) constant('KALETA_MIGRATION_SECONDS')) : (float) \Kaleta\Core\Migration::$seconds;
    $max = defined('KALETA_MIGRATION_ROWS') ? max(1, (int) constant('KALETA_MIGRATION_ROWS')) : PHP_INT_MAX;
    $stopAfter = defined('KALETA_MIGRATION_STOP_AFTER') ? (int) constant('KALETA_MIGRATION_STOP_AFTER') : 0;
    $deadline = microtime(true) + $seconds;

    $reports = \Kaleta\Core\Whistleblowing::sealPending($settings, $deadline, $max, $stopAfter);
    $remaining = $max === PHP_INT_MAX ? PHP_INT_MAX : $max - $reports['sealed'] - $reports['failed'];
    $files = $reports['left'] || $remaining <= 0 ? ['sealed' => 0, 'failed' => 0, 'left' => true]
        : \Kaleta\Core\InternalFiles::sealPending($settings, $deadline, $remaining, $stopAfter);
    $left = $reports['left'] || $files['left'] || $stopAfter > 0;

    $sealed = $reports['sealed'] + $files['sealed'];
    $failed = $reports['failed'] + $files['failed'];
    if ($sealed > 0 || $failed > 0) {
        try {
            $db->insert('events', ['created_at' => date('Y-m-d H:i:s'), 'type' => 'files.sealed', 'severity' => $failed > 0 ? 'warning' : 'info',
                'message' => mb_substr($sealed . ' private file(s) sealed at rest (whistleblowing attachments, internal files)' . ($failed > 0 ? '; ' . $failed . ' could not be sealed now and stay readable – the daily jobs try again' : '') . ($left ? '; the rest follows on the next requests' : ''), 0, 255),
                'data' => (string) json_encode(['whistleblowing' => $reports['sealed'], 'internal_files' => $files['sealed'], 'failed' => $failed, 'left' => $left])]);
        } catch (\Throwable) {
            // a record never stops the migration
        }
    }
    $finish($left);
};
