<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Internal comments of the team on a record (3.25, table ka_comments): appended, never overwritten, each with who wrote it,
 * when and from where (the administration or Claude). The table is generic – target 'enquiry:12' today, pages and
 * collection items later – and the only kind so far is 'note': an internal note on an enquiry the customer never sees.
 *
 *  - Enquiry notes follow the enquiry (GDPR): deleted with it wherever an enquiry is deleted (forgetEnquiries – the target
 *    is text, so there is no foreign key; sweep() removes any a path missed), blanked when it is anonymised (who and when
 *    stay), part of the personal data file of its customer, never journaled for undo (AgentJournal::PERSONAL_TABLES).
 *  - The old single note (ka_poptavky.poznamka) is not moved here: it stays the editable summary of the enquiry.
 */
final class Comments
{
    /** The longest text of one comment. */
    public const int MAX_TEXT = 5000;

    /** The target of an enquiry's notes. */
    public static function enquiry(int $idp): string
    {
        return 'enquiry:' . $idp;
    }

    /**
     * Appends a note as the given user (an administration user or the user of an MCP connection) and returns its id.
     * Throws \DomainException when there is nothing to save.
     *
     * @param array<string, mixed> $user a row of ka_uzivatele (idu, jmeno, user)
     * @param 'admin'|'mcp' $via
     */
    public static function add(Db $db, string $target, string $text, array $user, string $via = 'admin'): int
    {
        $text = self::clean($text);
        if ($text === '') {
            throw new \DomainException('Write the note first.');
        }
        $name = trim((string) ($user['jmeno'] ?? '')) !== '' ? trim((string) $user['jmeno']) : (string) ($user['user'] ?? '');

        return $db->insert('comments', ['target' => mb_substr($target, 0, 40), 'kind' => 'note', 'author_id' => (int) ($user['idu'] ?? 0) > 0 ? (int) $user['idu'] : null,
            'author_name' => mb_substr($name, 0, 100), 'text' => $text, 'via' => $via === 'mcp' ? 'mcp' : 'admin', 'created_at' => Clock::now()]);
    }

    /** The text as stored: line breaks normalised, control characters (but line breaks and tabs) removed, trimmed, capped. Pure. */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $text));

        return trim(mb_substr(trim($text), 0, self::MAX_TEXT));
    }

    /**
     * The comments on a target, oldest first (not the deleted ones).
     *
     * @return list<array{id: int, at: string, by: string, by_id: int, text: string, via: string, kind: string}>
     */
    public static function of(Db $db, string $target): array
    {
        try {
            $rows = $db->all('SELECT id, kind, author_id, author_name, text, via, created_at FROM {comments} WHERE target = ? AND deleted_at IS NULL ORDER BY id', [$target]);
        } catch (\PDOException) {
            return []; // before the 3.25 migration
        }

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'at' => (string) $r['created_at'], 'by' => (string) $r['author_name'], 'by_id' => (int) $r['author_id'],
            'text' => (string) $r['text'], 'via' => (string) $r['via'], 'kind' => (string) $r['kind']], $rows);
    }

    /**
     * How many notes each enquiry has (list_enquiries' notes_count).
     *
     * @param list<int> $idps
     * @return array<int, int>
     */
    public static function countEnquiries(Db $db, array $idps): array
    {
        $targets = array_map(fn (int $idp): string => self::enquiry($idp), array_values(array_unique(array_map('intval', $idps))));
        if ($targets === []) {
            return [];
        }
        try {
            $pairs = $db->pairs('SELECT target, COUNT(*) FROM {comments} WHERE deleted_at IS NULL AND target IN (' . implode(',', array_fill(0, count($targets), '?')) . ') GROUP BY target', $targets);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($pairs as $target => $n) {
            $out[(int) substr((string) $target, 8)] = (int) $n;
        }

        return $out;
    }

    /**
     * Deletes the notes of the given enquiries – call it wherever enquiries are deleted (the detail, the list, retention,
     * job applications, erasure on request, MCP). Returns how many rows went.
     *
     * @param list<int> $idps
     */
    public static function forgetEnquiries(Db $db, array $idps): int
    {
        $targets = array_map(fn (int $idp): string => self::enquiry($idp), array_values(array_unique(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0))));
        if ($targets === []) {
            return 0;
        }
        Inbox::forgetTargets($db, $targets); // 3.26: the notifications about them (they may quote a note) go too
        try {
            return $db->run('DELETE FROM {comments} WHERE target IN (' . implode(',', array_fill(0, count($targets), '?')) . ')', $targets)->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Anonymised enquiries (Core\Privacy): who wrote a note and when stay for the history, the text goes.
     *
     * @param list<int> $idps
     */
    public static function anonymiseEnquiries(Db $db, array $idps): int
    {
        $targets = array_map(fn (int $idp): string => self::enquiry($idp), array_values(array_unique(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0))));
        if ($targets === []) {
            return 0;
        }
        Inbox::forgetTargets($db, $targets, true); // 3.26: a notification quoting a note loses the quote too
        try {
            return $db->run("UPDATE {comments} SET text = '', mentions = NULL WHERE target IN (" . implode(',', array_fill(0, count($targets), '?')) . ')', $targets)->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Notes whose enquiry no longer exists (deleted by a path that did not call forgetEnquiries) – the retention cleanup
     * removes them. It runs on every visit of the Enquiries list, so the enquiries are looked up by their primary key (a
     * join on CONCAT('enquiry:', idp) read every enquiry each time, N325-1) and nothing is cast in SQL (a strict sql_mode
     * turns a cast of a malformed target into an error).
     */
    public static function sweep(Db $db): int
    {
        try {
            $targets = array_map(fn (array $r): string => (string) $r['target'], $db->all("SELECT DISTINCT target FROM {comments} WHERE target LIKE 'enquiry:%'"));
            $gone = [];
            foreach (array_chunk($targets, 500) as $chunk) {
                $ids = array_values(array_unique(array_filter(array_map(fn (string $t): int => preg_match('/^enquiry:(\d{1,10})$/D', $t, $m) ? (int) $m[1] : 0, $chunk))));
                $existing = $ids === [] ? [] : array_flip(array_map(fn (array $r): string => self::enquiry((int) $r['idp']), $db->all('SELECT idp FROM {poptavky} WHERE idp IN (' . implode(',', $ids) . ')')));
                $gone = [...$gone, ...array_filter($chunk, fn (string $t): bool => !isset($existing[$t]))];
            }
            $deleted = 0;
            foreach (array_chunk($gone, 500) as $chunk) {
                $deleted += $db->run('DELETE FROM {comments} WHERE target IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->rowCount();
            }

            return $deleted;
        } catch (\PDOException) {
            return 0;
        }
    }
}
