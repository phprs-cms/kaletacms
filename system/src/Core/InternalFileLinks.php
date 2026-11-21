<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Files on a record (3.49 B, table ka_internal_file_links, migration 0136): an internal file linked to a customer, a company,
 * a deal or a quote – the price sheet of a deal, the contract of a customer. A link holds two ids and a kind, never a copy
 * of the file and no personal data; it changes nothing about who may download the file (InternalFiles::download decides).
 * The only class that names the table (a unit test keeps it so, beside the three tables of Core\InternalFiles).
 *
 * The access rule, in one place:
 *  - To link or unlink, a person must see the file (InternalFiles::file – the section and the folder's audience), may open
 *    the record's section with its feature on (the uniform mayOpen of Contacts, Deals and Quotes), and the record must
 *    exist. An unknown record and one the person cannot open give the same refusal.
 *  - A record lists only the files the viewer can open (InternalFiles::visibleRows); the others are not counted and not
 *    hinted at – in the administration and over MCP alike.
 *  - A file lists its records: a link where the viewer may open the section, "a record you cannot open" without a name
 *    otherwise, the record as text with "(section switched off)" when its feature is off (the 3.48 pattern).
 *  - Never customer-facing: the public quote page, the PDF and the customer's link carry nothing of it.
 *
 * Life cycle: InternalFiles::delete forgets the file's links; deleting a record, a merge of contacts (move), anonymising a
 * contact or a quote and an erasure on request forget the record's links – the files stay, they are company documents.
 * Links over MCP (save_internal_file link / unlink) are live changes of write connections, never journaled for undo
 * (AgentJournal::PERSONAL_TABLES).
 */
final class InternalFileLinks
{
    /** kind => [the section the record lives in, its feature key] – the words of Assignment::KINDS. */
    public const array KINDS = ['contact' => ['contacts', 'customers'], 'company' => ['contacts', 'customers'],
        'deal' => ['pipeline', 'pipeline'], 'quote' => ['quotes', 'quotes']];

    /** The most files linked to one record. */
    public const int PER_RECORD = 100;

    /** The refusal for an unknown record and for one the person cannot open – the same words for both. */
    public const string NO_RECORD = 'The record does not exist, or you cannot open it.';

    /** The refusal for a file that does not exist or whose folder is not for the person – the same words for both. */
    public const string NO_FILE = 'The file does not exist, or its folder is not for you.';

    /**
     * May the signed-in user link files to records of this kind: they open Internal files, the kind's feature is on and they
     * may open its section?
     */
    public static function mayLink(App $app, string $kind): bool
    {
        return isset(self::KINDS[$kind]) && InternalFiles::mayOpen($app) && self::mayOpenKind($app, $kind);
    }

    /**
     * May the signed-in user open records of this kind (the feature on and the section's right) – the uniform mayOpen of
     * Contacts, Deals and Quotes.
     */
    public static function mayOpenKind(App $app, string $kind): bool
    {
        $userId = (int) $app->auth()->id();
        if (!isset(self::KINDS[$kind]) || $userId <= 0 || !Extensions::isEnabled($app->settings(), self::KINDS[$kind][1])) {
            return false;
        }

        return match ($kind) {
            'contact', 'company' => Contacts::mayOpen($app->db(), $app->settings(), $userId),
            'deal' => Deals::mayOpen($app->db(), $app->settings(), $userId),
            default => Quotes::mayOpen($app->db(), $app->settings(), $userId), // quote
        };
    }

    /**
     * "contact:7" → ['contact', 7], or null for anything else (an unknown kind, no id). Pure.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parse(mixed $target): ?array
    {
        if (!is_string($target) || preg_match('/^\s*([a-z]+)\s*:\s*(\d{1,10})\s*$/D', $target, $m) !== 1 || !isset(self::KINDS[$m[1]]) || (int) $m[2] <= 0) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /** Does the record exist (a contact, company, deal or quote by its id)? */
    public static function recordExists(Db $db, string $kind, int $recordId): bool
    {
        return isset(self::KINDS[$kind]) && $recordId > 0 && Inbox::places($db, [$kind . ':' . $recordId]) !== [];
    }

    /**
     * Links a file to a record. Null when it is linked (also when it already was), or the English reason: the file is not
     * the person's to see, the record is unknown or not theirs to open (one message for both), the record has PER_RECORD
     * files already. One change-log line in Internal files ("#file → deal #12" – never a name).
     */
    public static function link(App $app, int $fileId, string $kind, int $recordId): ?string
    {
        if (InternalFiles::file($app, $fileId) === null) {
            return self::NO_FILE;
        }
        $db = $app->db();
        if (!self::mayLink($app, $kind) || !self::recordExists($db, $kind, $recordId)) {
            return self::NO_RECORD;
        }
        if ($db->value('SELECT 1 FROM {internal_file_links} WHERE file_id = ? AND kind = ? AND record_id = ?', [$fileId, $kind, $recordId]) !== null) {
            return null;
        }
        if ((int) $db->value('SELECT COUNT(*) FROM {internal_file_links} WHERE kind = ? AND record_id = ?', [$kind, $recordId]) >= self::PER_RECORD) {
            return 'A record can have at most 100 linked files.';
        }
        try {
            $db->insert('internal_file_links', ['file_id' => $fileId, 'kind' => $kind, 'record_id' => $recordId,
                'created_by' => (int) $app->auth()->id() > 0 ? (int) $app->auth()->id() : null, 'created_at' => Clock::now()]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') { // a second click at the same moment: linked already
                throw $e;
            }
        }
        ChangeLog::write($app, InternalFiles::SECTION, 'link', self::logText($fileId, $kind, $recordId));

        return null;
    }

    /**
     * Unlinks a file from a record (the same rule as link; the record need not exist any more). Returns whether a link went.
     */
    public static function unlink(App $app, int $fileId, string $kind, int $recordId): bool
    {
        if (InternalFiles::file($app, $fileId) === null || !self::mayLink($app, $kind)) {
            return false;
        }
        $done = $app->db()->run('DELETE FROM {internal_file_links} WHERE file_id = ? AND kind = ? AND record_id = ?', [$fileId, $kind, $recordId])->rowCount() > 0;
        if ($done) {
            ChangeLog::write($app, InternalFiles::SECTION, 'unlink', self::logText($fileId, $kind, $recordId));
        }

        return $done;
    }

    /**
     * The files linked to a record that the viewer can open, newest link first – each a file of InternalFiles::visibleRows
     * with 'linked_at'. Nothing without Internal files; the files of folders not for the viewer are left out, uncounted.
     *
     * @return list<array<string, mixed>>
     */
    public static function ofRecord(App $app, string $kind, int $recordId): array
    {
        return self::ofRecords($app, [[$kind, $recordId]]);
    }

    /**
     * The same for several records (the record's box, the attachments of a reply or a quote e-mail): each file once, in the
     * order of the targets, then the newest link first.
     *
     * @param list<array{0: string, 1: int}> $targets kind, record id
     * @return list<array<string, mixed>>
     */
    public static function ofRecords(App $app, array $targets): array
    {
        if (!InternalFiles::mayOpen($app)) {
            return [];
        }
        $db = $app->db();
        $links = [];
        foreach ($targets as [$kind, $recordId]) {
            if (!isset(self::KINDS[$kind]) || (int) $recordId <= 0) {
                continue;
            }
            try {
                foreach ($db->all('SELECT file_id, created_at FROM {internal_file_links} WHERE kind = ? AND record_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . self::PER_RECORD, [$kind, (int) $recordId]) as $r) {
                    $links[(int) $r['file_id']] ??= (string) $r['created_at'];
                }
            } catch (\PDOException $e) {
                Db::rethrowUnlessMissing($e);
                return []; // before migration 0136
            }
        }
        $files = InternalFiles::visibleRows($app, array_keys($links));
        $out = [];
        foreach ($links as $fileId => $at) {
            if (isset($files[$fileId])) {
                $out[] = $files[$fileId] + ['linked_at' => $at];
            }
        }

        return $out;
    }

    /**
     * The records a file is linked to, for a viewer who sees the file: kind, id, and – state open – its title and admin path;
     * state no_right = "a record you cannot open" (no title, no path); state off = the feature is off: the title as the
     * e-mails name it (a number, never a customer's name) and no path. A record that no longer exists is left out.
     *
     * @return list<array{kind: string, id: int, title: string|null, path: string|null, state: string}>
     */
    public static function ofFile(App $app, int $fileId): array
    {
        if (InternalFiles::file($app, $fileId) === null) {
            return [];
        }
        $db = $app->db();
        try {
            $rows = $db->all('SELECT kind, record_id FROM {internal_file_links} WHERE file_id = ? ORDER BY created_at DESC, id DESC LIMIT 500', [$fileId]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
        $rows = array_values(array_filter($rows, fn (array $r): bool => isset(self::KINDS[(string) $r['kind']])));
        $places = Inbox::places($db, array_map(fn (array $r): string => $r['kind'] . ':' . (int) $r['record_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $kind = (string) $r['kind'];
            $id = (int) $r['record_id'];
            $place = $places[$kind . ':' . $id] ?? null;
            if ($place === null) {
                continue;
            }
            if (!Extensions::isEnabled($app->settings(), self::KINDS[$kind][1])) {
                $out[] = ['kind' => $kind, 'id' => $id, 'title' => Inbox::where($place), 'path' => null, 'state' => 'off'];
            } elseif (!self::mayOpenKind($app, $kind)) {
                $out[] = ['kind' => $kind, 'id' => $id, 'title' => null, 'path' => null, 'state' => 'no_right'];
            } else {
                $out[] = ['kind' => $kind, 'id' => $id, 'title' => RecordTitle::place($place, [self::KINDS[$kind][0] => true]), 'path' => (string) $place['path'], 'state' => 'open'];
            }
        }

        return $out;
    }

    /** Forgets the links of records (deleted, anonymised, erased). Returns how many went. @param list<int> $recordIds */
    public static function forget(Db $db, string $kind, array $recordIds): int
    {
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds), fn (int $id): bool => $id > 0)));
        if ($recordIds === [] || !isset(self::KINDS[$kind])) {
            return 0;
        }
        try {
            return $db->run('DELETE FROM {internal_file_links} WHERE kind = ? AND record_id IN (' . implode(',', $recordIds) . ')', [$kind])->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0136
        }
    }

    /** Forgets the links of a file (InternalFiles::delete, inside its transaction). Returns how many went. */
    public static function forgetFile(Db $db, int $fileId): int
    {
        try {
            return $db->run('DELETE FROM {internal_file_links} WHERE file_id = ?', [$fileId])->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /**
     * A merge (Contacts::merge): the links of $from go to $into – a file linked to both stays linked once. Returns how many
     * links moved.
     */
    public static function move(Db $db, string $kind, int $from, int $into): int
    {
        if (!isset(self::KINDS[$kind]) || $from <= 0 || $into <= 0 || $from === $into) {
            return 0;
        }
        try {
            $db->run('DELETE l FROM {internal_file_links} l JOIN {internal_file_links} k ON k.file_id = l.file_id AND k.kind = l.kind AND k.record_id = ? WHERE l.kind = ? AND l.record_id = ?', [$into, $kind, $from]);

            return $db->run('UPDATE {internal_file_links} SET record_id = ? WHERE kind = ? AND record_id = ?', [$into, $kind, $from])->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /**
     * The raw count of links of records, whoever may see the files – only for an administrator's view (the personal data
     * request, the erasure's report).
     *
     * @param list<int> $recordIds
     */
    public static function countFor(Db $db, string $kind, array $recordIds): int
    {
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds), fn (int $id): bool => $id > 0)));
        if ($recordIds === [] || !isset(self::KINDS[$kind])) {
            return 0;
        }
        try {
            return (int) $db->value('SELECT COUNT(*) FROM {internal_file_links} WHERE kind = ? AND record_id IN (' . implode(',', $recordIds) . ')', [$kind]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /** The change-log text of a link: "#5 → deal #12" – ids only, never a customer's name. Pure. */
    public static function logText(int $fileId, string $kind, int $recordId): string
    {
        return '#' . $fileId . ' → ' . $kind . ' #' . $recordId;
    }
}
