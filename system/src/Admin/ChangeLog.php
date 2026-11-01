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
    /** Areas (ka_protokol.modul) written in this request – the admin's own row of an action is left out when its module wrote one (3.23.1, N-C3). @var array<string, true> */
    private static array $written = [];

    private static bool $changedAnyway = false;

    /** @return int the id of the row, 0 when it could not be written */
    public static function write(App $app, string $module, string $action, string $description = '', string $reason = ''): int
    {
        self::$written[mb_substr($module, 0, 30)] = true;
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
     * A change Kaleta made by itself, outside a request of a person or a connection (3.12: a category shown by a scheduled
     * publication or an import step): no user, the name "Kaleta".
     */
    public static function system(\Kaleta\Core\Db $db, string $module, string $action, string $description = ''): int
    {
        try {
            return $db->insert('protokol', ['cas' => date('Y-m-d H:i:s'), 'kdo' => null, 'jmeno' => 'Kaleta', 'via' => '',
                'modul' => mb_substr($module, 0, 30), 'akce' => mb_substr($action, 0, 40), 'popis' => mb_substr($description, 0, 255)]);
        } catch (\Throwable) {
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

    /** Whether a row of one of these areas has been written in this request (by write()). */
    public static function wrote(string ...$modules): bool
    {
        foreach ($modules as $module) {
            if (isset(self::$written[mb_substr($module, 0, 30)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The action changed something although it ends with an error message ("The settings are saved, but …"): the Kernel
     * still writes its row – an error message alone means a refused action (3.23.1, N3231-1).
     */
    public static function changedAnyway(): void
    {
        self::$changedAnyway = true;
    }

    /** Whether the action said it changed something despite its error message (changedAnyway()). */
    public static function isChangedAnyway(): bool
    {
        return self::$changedAnyway;
    }

    /** Forgets what was written – before the next action of the same process (Admin\Kernel). */
    public static function forget(): void
    {
        self::$written = [];
        self::$changedAnyway = false;
    }

    /** Removes a row written before a change that did not happen after all (the tool refused the call). */
    public static function remove(App $app, int $id): void
    {
        if ($id > 0) {
            $app->db()->delete('protokol', ['idp' => $id]);
        }
    }
}
