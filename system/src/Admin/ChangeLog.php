<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;

/**
 * Change log: who did what in the admin and when, and – for Claude's changes – why (2.15: the reason a write tool was
 * given). Records older than half a year are deleted.
 */
final class ChangeLog
{
    /** @return int the id of the row, 0 when it could not be written */
    public static function write(App $app, string $module, string $action, string $description = '', string $reason = ''): int
    {
        try {
            $user = $app->auth()->user();
            $id = $app->db()->insert('protokol', [
                'cas' => date('Y-m-d H:i:s'), 'kdo' => $user['idu'] ?? null, 'jmeno' => (string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? ''),
                'via' => mb_substr((string) ($app->auth()->connection()['name'] ?? ''), 0, 100), // a change made by Claude names its connection
                'modul' => mb_substr($module, 0, 30), 'akce' => mb_substr($action, 0, 40), 'popis' => mb_substr($description, 0, 255),
            ] + ($reason !== '' ? ['duvod' => mb_substr($reason, 0, 255)] : []));
            if (random_int(1, 100) === 1) {
                $app->db()->run('DELETE FROM {protokol} WHERE cas < ?', [\Kaleta\Core\Clock::at('-180 days')]);
            }

            return $id;
        } catch (\Throwable) {
            // the log must not break the action it records (e.g. before the migration runs, the table does not exist yet)
            return 0;
        }
    }

    /**
     * A row written before the change it records (Claude's guardrails, 3.7: the hourly limit counts it while the tool
     * runs) gets its final description once the change is done – one row per change, never a second one.
     */
    public static function describe(App $app, int $id, string $description): void
    {
        if ($id > 0) {
            $app->db()->update('protokol', ['popis' => mb_substr($description, 0, 255)], ['idp' => $id]);
        }
    }

    /** Removes a row written before a change that did not happen after all (the tool refused the call). */
    public static function remove(App $app, int $id): void
    {
        if ($id > 0) {
            $app->db()->delete('protokol', ['idp' => $id]);
        }
    }
}
