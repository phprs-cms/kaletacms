<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Internal comments of the team on a record (3.25, table ka_comments): appended, each with who wrote it, when and from where
 * (the administration or Claude). One table for every thread – the target says where it belongs:
 *
 *  - 'enquiry:<idp>', kind 'note' (3.25): internal notes on an enquiry the customer never sees.
 *  - 'page:<ids>' and 'item:<idp>', kind 'comment' (3.26): the team's thread on a page (page settings, the Team tab of the
 *    builder) and on a collection item (its form). Commenting needs the section of the target (Pages, Collections,
 *    Enquiries – the admin action lives in that module); the guest comments of a shared preview stay in Core\DraftComments.
 *
 * Mentions (3.26): "@" and a sign-in name (the "@" autocomplete of image/admin.js inserts it). The server resolves them
 * (mentions()) and refuses anyone who is blocked or cannot open the target's section (Assignment::allowed – the rule of
 * the responsible person); the ids go to the `mentions` column (JSON list) and the thread shows the names (html()). Each new
 * mention, and a new comment on a page or item somebody is responsible for (owner_id, 3.24), calls Core\Inbox::notify –
 * never for the author.
 *
 * Plain text only, escaped on output. Authors edit their own comments; a deleted one (by its author or an administrator)
 * stays as a placeholder in the thread – who and when, the text and the mentions go (soft delete). A comment follows its
 * target:
 *
 *  - Enquiry notes follow the enquiry (GDPR): deleted with it wherever an enquiry is deleted (forgetEnquiries – the target
 *    is text, so there is no foreign key; sweep() removes any a path missed), blanked when it is anonymised (who and when
 *    stay), part of the personal data file of its customer, never journaled for undo (AgentJournal::PERSONAL_TABLES).
 *  - The comments of a page or an item stay while it is in the trash and go when it is deleted for good (forget() from
 *    the permanent delete and the emptying of the trash; sweepContent() for any other path).
 *  - The old single note (ka_poptavky.poznamka) is not moved here: it stays the editable summary of the enquiry.
 */
final class Comments
{
    /** The longest text of one comment. */
    public const int MAX_TEXT = 5000;

    /** Each kind of target => the kind of its comments (3.26); the kind of target is also the Assignment kind of its section. */
    public const array KINDS = ['enquiry' => 'note', 'page' => 'comment', 'item' => 'comment'];

    /** The name of the section each kind of target needs (for the refusal of a mention). */
    private const array SECTION_NAMES = ['enquiry' => 'Enquiries', 'page' => 'Pages', 'item' => 'Collections'];

    /** A mention (3.26): "@" and a sign-in name (Users allows letters without diacritics, digits and ._-), not inside a word or an e-mail address. */
    public const string MENTION = '/(?<![\p{L}\p{N}._@-])@([a-zA-Z0-9._-]{2,40})/u';

    /** At most this many people mentioned in one comment. */
    public const int MAX_MENTIONS = 20;

    /**
     * At most this many people in the "@" list of a thread (N326-3): the list is embedded in every composer and edit form of
     * the screen, so a site with very many accounts does not bloat each page; a person beyond it can still be typed – the
     * server checks every mention (mentions()).
     */
    public const int MENTIONABLE_MAX = 200;

    /** The longest excerpt a notification carries (Core\Inbox). */
    public const int EXCERPT = 200;

    /** The target of an enquiry's notes. */
    public static function enquiry(int $idp): string
    {
        return 'enquiry:' . $idp;
    }

    /** The target of a page's thread (3.26). */
    public static function page(int $ids): string
    {
        return 'page:' . $ids;
    }

    /** The target of a collection item's thread (3.26). */
    public static function item(int $idp): string
    {
        return 'item:' . $idp;
    }

    /**
     * What a target names: an enquiry, a page or a collection item and its positive id; null for anything else. Pure.
     *
     * @return array{type: 'enquiry'|'page'|'item', id: int}|null
     */
    public static function parseTarget(string $target): ?array
    {
        return preg_match('/^(enquiry|page|item):([1-9]\d{0,9})$/D', $target, $m) === 1 ? ['type' => $m[1], 'id' => (int) $m[2]] : null;
    }

    /**
     * Appends a note as the given user (an administration user or the user of an MCP connection) and returns its id.
     * Throws \DomainException when there is nothing to save.
     *
     * The kind follows the target (KINDS: a note on an enquiry, a comment on a page or an item). The mentions are stored as
     * given – post() checks them first; add() alone is the 3.25 note without mentions (MCP update_enquiry add_note).
     *
     * @param array<string, mixed> $user a row of ka_uzivatele (idu, jmeno, user)
     * @param 'admin'|'mcp' $via
     * @param list<int> $mentions ids of the people mentioned (checked by mentions())
     */
    public static function add(Db $db, string $target, string $text, array $user, string $via = 'admin', array $mentions = []): int
    {
        $type = self::parseTarget($target)['type'] ?? 'enquiry';
        $text = self::clean($text);
        if ($text === '') {
            throw new \DomainException($type === 'enquiry' ? 'Write the note first.' : 'Write the comment first.');
        }
        $name = trim((string) ($user['jmeno'] ?? '')) !== '' ? trim((string) $user['jmeno']) : (string) ($user['user'] ?? '');

        return $db->insert('comments', ['target' => mb_substr($target, 0, 40), 'kind' => self::KINDS[$type], 'author_id' => (int) ($user['idu'] ?? 0) > 0 ? (int) $user['idu'] : null,
            'author_name' => mb_substr($name, 0, 100), 'text' => $text, 'via' => $via === 'mcp' ? 'mcp' : 'admin', 'mentions' => self::mentionsJson($mentions), 'created_at' => Clock::now()]);
    }

    /**
     * Writes a comment into a thread the way the admin does (3.26): the mentions checked (mentions()), stored, and the people
     * it concerns notified – each person mentioned, and the responsible person of a page or an item (owner_id) for a new
     * comment – never the author. Returns the id and who is mentioned.
     *
     * @param array<string, mixed> $user a row of ka_uzivatele (idu, jmeno, user)
     * @param 'admin'|'mcp' $via
     * @return array{id: int, mentions: list<int>}
     * @throws \DomainException nothing to save, or someone mentioned who may not be
     */
    public static function post(App $app, string $target, string $text, array $user, string $via = 'admin'): array
    {
        $db = $app->db();
        $mentions = self::mentions($db, $target, self::clean($text));
        $id = self::add($db, $target, $text, $user, $via, $mentions);
        self::notify($app, $target, self::clean($text), $mentions, $mentions, (int) ($user['idu'] ?? 0), self::owner($db, $target));

        return ['id' => $id, 'mentions' => $mentions];
    }

    /**
     * Rewrites one's own comment (3.26): the text and its mentions again, edited_at stamped; only the people newly mentioned
     * are notified. Throws \DomainException for a comment of another thread, of someone else, deleted, or an empty text.
     *
     * @param array<string, mixed> $user the signed-in user
     * @return array{id: int, mentions: list<int>}
     * @throws \DomainException when it may not
     */
    public static function edit(App $app, string $target, int $id, string $text, array $user): array
    {
        $db = $app->db();
        $comment = self::find($db, $id);
        if ($comment === null || $comment['target'] !== $target || $comment['deleted']) {
            throw new \DomainException('The comment does not exist.');
        }
        $author = (int) ($user['idu'] ?? 0);
        if ($author <= 0 || $comment['by_id'] !== $author) {
            throw new \DomainException('You can edit only your own comments.');
        }
        $text = self::clean($text);
        if ($text === '') {
            throw new \DomainException('Write the comment first – or delete it.');
        }
        $mentions = self::mentions($db, $target, $text);
        $db->update('comments', ['text' => $text, 'mentions' => self::mentionsJson($mentions), 'edited_at' => Clock::now()], ['id' => $id]);
        // the notifications quoting the old text quote the new one (Core\Inbox)
        Inbox::requote($db, $target, self::excerpt($comment['text'], self::people($db, $comment['mentions'])), self::excerpt($text, self::people($db, $mentions)));
        self::notify($app, $target, $text, $mentions, array_values(array_diff($mentions, $comment['mentions'])), $author, null);

        return ['id' => $id, 'mentions' => $mentions];
    }

    /**
     * Deletes a comment (3.26): its author, or an administrator for any. Soft – the thread keeps a placeholder with who wrote
     * it and when; the text and the mentions go. Throws \DomainException when it may not.
     *
     * @param array<string, mixed> $user the signed-in user
     * @throws \DomainException when it may not
     */
    public static function delete(Db $db, string $target, int $id, array $user, bool $admin): void
    {
        $comment = self::find($db, $id);
        if ($comment === null || $comment['target'] !== $target || $comment['deleted']) {
            throw new \DomainException('The comment does not exist.');
        }
        if (!$admin && ((int) ($user['idu'] ?? 0) <= 0 || $comment['by_id'] !== (int) ($user['idu'] ?? 0))) {
            throw new \DomainException('You can delete only your own comments.');
        }
        $db->update('comments', ['text' => '', 'mentions' => null, 'deleted_at' => Clock::now()], ['id' => $id]);
        Inbox::requote($db, $target, self::excerpt($comment['text'], self::people($db, $comment['mentions'])), ''); // the quote goes with the text
    }

    /**
     * One comment by its id (a deleted one too), or null.
     *
     * @return array{id: int, target: string, at: string, by: string, by_id: int, text: string, via: string, kind: string, mentions: list<int>, edited: ?string, deleted: bool}|null
     */
    public static function find(Db $db, int $id): ?array
    {
        try {
            $row = $id > 0 ? $db->one('SELECT * FROM {comments} WHERE id = ?', [$id]) : null;
        } catch (\PDOException) {
            return null;
        }

        return $row === null ? null : self::row($row);
    }

    /**
     * A thread for the Team tab of the builder (3.26, JSON): who, when, the text as HTML with the mentions as names (html()),
     * the text itself for editing, and what the signed-in person may do with each comment.
     *
     * @return list<array<string, mixed>>
     */
    public static function forBuilder(App $app, string $target): array
    {
        $db = $app->db();
        $comments = self::of($db, $target, true);
        $people = self::people($db, array_merge(...array_column($comments, 'mentions')));
        $me = $app->auth()->id();
        $admin = $app->auth()->isAdmin();

        return array_map(fn (array $c): array => ['id' => $c['id'], 'kdo' => $c['by'] !== '' ? $c['by'] : '—', 'kdy' => format_date($c['at'], true),
            'html' => $c['deleted'] ? '' : self::html($c['text'], $people), 'text' => $c['deleted'] ? '' : $c['text'], 'pres' => $c['via'] === 'mcp',
            'upraveno' => $c['edited'] !== null, 'smazano' => $c['deleted'], 'muj' => $me > 0 && $c['by_id'] === $me,
            'smaze' => !$c['deleted'] && (($me > 0 && $c['by_id'] === $me) || $admin)], $comments);
    }

    /**
     * The people a text mentions, checked (3.26): every "@name" that is the sign-in name of an account must be an active
     * account that may open the target's section (Assignment::allowed – the rule of the responsible person); an "@word"
     * that is nobody's sign-in name stays plain text. Throws \DomainException naming whoever may not be mentioned – the
     * server never relies on the autocomplete. Returns the ids in the order of the text.
     *
     * @return list<int>
     */
    public static function mentions(Db $db, string $target, string $text): array
    {
        $type = self::parseTarget($target)['type'] ?? null;
        $tokens = self::mentionTokens($text);
        if ($type === null || $tokens === []) {
            return [];
        }
        $wanted = [];
        foreach ($tokens as $token) {
            $wanted[strtolower($token)] = true;
            $wanted[strtolower(rtrim($token, '._-'))] = true;
        }
        $accounts = [];
        foreach ($db->all('SELECT idu, user, blokovat FROM {uzivatele} WHERE user IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')', array_map('strval', array_keys($wanted))) as $r) {
            $accounts[strtolower((string) $r['user'])] = ['id' => (int) $r['idu'], 'user' => (string) $r['user'], 'blocked' => (int) $r['blokovat'] !== 0];
        }
        $ids = [];
        foreach ($tokens as $token) {
            $account = self::resolveToken($token, $accounts)[0] ?? null;
            if ($account === null || isset($ids[$account['id']])) {
                continue;
            }
            if ($account['blocked']) {
                throw new \DomainException(t('%s cannot be mentioned: the account is blocked.', '@' . $account['user']));
            }
            if (!Assignment::allowed($db, $type, $account['id'])) {
                throw new \DomainException(t('%s cannot be mentioned here: they cannot open %s.', '@' . $account['user'], t(self::SECTION_NAMES[$type])));
            }
            $ids[$account['id']] = true;
        }
        if (count($ids) > self::MAX_MENTIONS) {
            throw new \DomainException(t('Mention at most %d people in one comment.', self::MAX_MENTIONS));
        }

        return array_keys($ids);
    }

    /**
     * The "@names" written in a text, in order, without repeats (as written). Pure.
     *
     * @return list<string>
     */
    public static function mentionTokens(string $text): array
    {
        preg_match_all(self::MENTION, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Who may be mentioned in a thread (3.26, the "@" autocomplete): active accounts that may open the target's section,
     * by name – the same rule mentions() checks; at most MENTIONABLE_MAX.
     *
     * @return list<array{id: int, user: string, name: string}>
     */
    public static function mentionable(Db $db, string $target): array
    {
        $type = self::parseTarget($target)['type'] ?? null;
        if ($type === null) {
            return [];
        }
        $name = "IF(jmeno = '', user, jmeno)";

        return array_map(fn (array $r): array => ['id' => (int) $r['idu'], 'user' => (string) $r['user'], 'name' => (string) $r['name']], $db->all(
            'SELECT idu, user, ' . $name . ' AS name FROM {uzivatele} u WHERE blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?)) ORDER BY ' . $db->sortText($name) . ' LIMIT ' . self::MENTIONABLE_MAX,
            [Auth::ADMIN, Assignment::KINDS[$type]],
        ));
    }

    /**
     * The sign-in names and the names of people, for showing their mentions (html(), excerpt()); a removed account is left out
     * and its mention stays as written.
     *
     * @param list<int> $ids
     * @return array<int, array{user: string, name: string}>
     */
    public static function people(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT idu, user FROM {uzivatele} WHERE idu IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['idu']] = ['user' => (string) $r['user'], 'name' => Assignment::label($db, (int) $r['idu'])];
        }

        return $out;
    }

    /**
     * The text of a comment as HTML (3.26): escaped, line breaks kept, and the mention of each person in $people shown as
     * "@" and their name. Pure.
     *
     * @param array<int, array{user: string, name: string}> $people id => sign-in name and name (people())
     */
    public static function html(string $text, array $people): string
    {
        $out = '';
        foreach (self::segments($text, $people) as $part) {
            $out .= is_string($part) ? e($part) : '<span class="zminka" data-mention="' . $part[0] . '">@' . e($part[1]) . '</span>';
        }

        return nl2br($out);
    }

    /**
     * The excerpt a notification carries (3.26): plain text with the mentions as names, the whitespace of the lines folded,
     * at most EXCERPT characters. Pure.
     *
     * @param array<int, array{user: string, name: string}> $people
     */
    public static function excerpt(string $text, array $people): string
    {
        $plain = '';
        foreach (self::segments($text, $people) as $part) {
            $plain .= is_string($part) ? $part : '@' . $part[1];
        }
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return mb_strlen($plain) > self::EXCERPT ? rtrim(mb_substr($plain, 0, self::EXCERPT - 1)) . '…' : $plain;
    }

    /**
     * Who a comment notifies and why (3.26, pure): each person newly mentioned (mention), and the responsible person of a
     * new comment on a page or an item (comment) unless they are mentioned anyway – never the author.
     *
     * @param list<int> $mentioned the people newly mentioned
     * @param int|null $owner the responsible person of a new comment's page or item; null for an edit or an enquiry
     * @return list<array{0: int, 1: 'mention'|'comment'}>
     */
    public static function recipients(array $mentioned, ?int $owner, int $author): array
    {
        $out = [];
        foreach ($mentioned as $id) {
            if ($id > 0 && $id !== $author && !isset($out[$id])) {
                $out[$id] = [$id, 'mention'];
            }
        }
        if ($owner !== null && $owner > 0 && $owner !== $author && !isset($out[$owner])) {
            $out[$owner] = [$owner, 'comment'];
        }

        return array_values($out);
    }

    /** The responsible person of a page or an item a thread belongs to (3.24, owner_id); null for an enquiry or nobody. */
    public static function owner(Db $db, string $target): ?int
    {
        $parsed = self::parseTarget($target);
        if ($parsed === null) {
            return null;
        }
        $owner = match ($parsed['type']) {
            'page' => $db->value('SELECT owner_id FROM {stranky} WHERE ids = ?', [$parsed['id']]),
            'item' => $db->value('SELECT owner_id FROM {kolekce_polozky} WHERE idp = ?', [$parsed['id']]),
            default => null,
        };

        return $owner === null ? null : (int) $owner;
    }

    /**
     * Notifies the people a comment concerns through Core\Inbox (3.26, the bell and the e-mail as each person chose);
     * the responsible person only while they may open the section. Never fails the comment.
     *
     * @param list<int> $all everyone the text mentions (for the names in the excerpt)
     * @param list<int> $new the people to notify of their mention
     */
    private static function notify(App $app, string $target, string $text, array $all, array $new, int $author, ?int $owner): void
    {
        $recipients = self::recipients($new, $owner, $author);
        if ($recipients === []) {
            return;
        }
        $db = $app->db();
        $type = self::parseTarget($target)['type'] ?? 'enquiry';
        $excerpt = self::excerpt($text, self::people($db, $all));
        foreach ($recipients as [$userId, $kind]) {
            if ($kind === 'comment' && !Assignment::allowed($db, $type, $userId)) {
                continue;
            }
            Inbox::notify($app, $userId, $kind, $target, $author > 0 ? $author : null, $excerpt); // never throws
        }
    }

    /**
     * The text cut into plain pieces and the mentions of known people ([id, name]). A token that is not a sign-in name as
     * written may end with punctuation ("@jana." at the end of a sentence): the name without it counts then.
     *
     * @param array<int, array{user: string, name: string}> $people
     * @return list<string|array{0: int, 1: string}>
     */
    private static function segments(string $text, array $people): array
    {
        $byName = [];
        foreach ($people as $id => $p) {
            $byName[strtolower($p['user'])] = ['id' => (int) $id, 'name' => $p['name']];
        }
        preg_match_all(self::MENTION, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $out = [];
        $offset = 0;
        foreach ($matches as $match) {
            [$person, $length] = self::resolveToken($match[1][0], $byName) ?? [null, 0];
            if ($person === null) {
                continue;
            }
            $out[] = substr($text, $offset, $match[0][1] - $offset);
            $out[] = [$person['id'], $person['name']];
            $offset = $match[0][1] + 1 + $length;
        }
        $out[] = substr($text, $offset);

        return array_values(array_filter($out, fn (string|array $p): bool => $p !== ''));
    }

    /**
     * The account a token names – as written, or without trailing punctuation – and how many characters of the token it took.
     *
     * @template T
     * @param array<string, T> $accounts lower-case sign-in name => account
     * @return array{0: T, 1: int}|null
     */
    private static function resolveToken(string $token, array $accounts): ?array
    {
        if (isset($accounts[strtolower($token)])) {
            return [$accounts[strtolower($token)], strlen($token)];
        }
        $short = rtrim($token, '._-');

        return $short !== '' && isset($accounts[strtolower($short)]) ? [$accounts[strtolower($short)], strlen($short)] : null;
    }

    /** @param list<int> $mentions */
    private static function mentionsJson(array $mentions): ?string
    {
        return $mentions === [] ? null : (string) json_encode(array_map('intval', $mentions));
    }

    /**
     * A row of ka_comments as the threads use it.
     *
     * @param array<string, mixed> $r
     * @return array{id: int, target: string, at: string, by: string, by_id: int, text: string, via: string, kind: string, mentions: list<int>, edited: ?string, deleted: bool}
     */
    private static function row(array $r): array
    {
        $mentions = json_decode((string) ($r['mentions'] ?? ''), true);

        return ['id' => (int) $r['id'], 'target' => (string) $r['target'], 'at' => (string) $r['created_at'], 'by' => (string) $r['author_name'], 'by_id' => (int) $r['author_id'],
            'text' => (string) $r['text'], 'via' => (string) $r['via'], 'kind' => (string) $r['kind'],
            'mentions' => is_array($mentions) ? array_values(array_filter(array_map('intval', $mentions), fn (int $id): bool => $id > 0)) : [],
            'edited' => $r['edited_at'] === null ? null : (string) $r['edited_at'], 'deleted' => $r['deleted_at'] !== null];
    }

    /** The text as stored: line breaks normalised, control characters (but line breaks and tabs) removed, trimmed, capped. Pure. */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $text));

        return trim(mb_substr(trim($text), 0, self::MAX_TEXT));
    }

    /**
     * The comments on a target, oldest first – without the deleted ones unless asked for (a thread shows them as a
     * placeholder, 3.26). The keys of 3.25 come first; mentions, edited and deleted were added in 3.26.
     *
     * @return list<array{id: int, target: string, at: string, by: string, by_id: int, text: string, via: string, kind: string, mentions: list<int>, edited: ?string, deleted: bool}>
     */
    public static function of(Db $db, string $target, bool $withDeleted = false): array
    {
        try {
            $rows = $db->all('SELECT * FROM {comments} WHERE target = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL') . ' ORDER BY id', [$target]);
        } catch (\PDOException) {
            return []; // before the 3.25 migration
        }

        return array_map(self::row(...), $rows);
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
            Inbox::forgetTargets($db, $gone); // 3.26: the notifications about them too
            foreach (array_chunk($gone, 500) as $chunk) {
                $deleted += $db->run('DELETE FROM {comments} WHERE target IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->rowCount();
            }

            return $deleted;
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Deletes the threads of the given targets for good – a page or an item deleted permanently (3.26). Returns how many
     * rows went.
     *
     * @param list<string> $targets
     */
    public static function forget(Db $db, array $targets): int
    {
        $targets = array_values(array_unique(array_filter($targets, fn (string $t): bool => self::parseTarget($t) !== null)));
        Inbox::forgetTargets($db, $targets); // the notifications about them go too (they may quote a comment)
        $deleted = 0;
        try {
            foreach (array_chunk($targets, 500) as $chunk) {
                $deleted += $db->run('DELETE FROM {comments} WHERE target IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->rowCount();
            }
        } catch (\PDOException) {
            // before the 3.25 migration
        }

        return $deleted;
    }

    /**
     * The threads of pages and items that no longer exist (deleted by a path that did not call forget(), e.g. a whole
     * collection or an import into an empty site) – removed like sweep() does for enquiries: the rows are looked up by
     * their primary key, nothing is cast in SQL. A page or an item in the trash still exists: its thread stays.
     */
    public static function sweepContent(Db $db): int
    {
        $deleted = 0;
        foreach (['page' => ['stranky', 'ids'], 'item' => ['kolekce_polozky', 'idp']] as $type => [$table, $key]) {
            try {
                $targets = array_map(fn (array $r): string => (string) $r['target'], $db->all('SELECT DISTINCT target FROM {comments} WHERE target LIKE ?', [$type . ':%']));
                $gone = [];
                foreach (array_chunk($targets, 500) as $chunk) {
                    $ids = array_values(array_unique(array_filter(array_map(fn (string $t): int => self::parseTarget($t)['id'] ?? 0, $chunk))));
                    $existing = $ids === [] ? [] : array_flip(array_map(fn (array $r): string => $type . ':' . (int) $r[$key], $db->all('SELECT ' . $key . ' FROM {' . $table . '} WHERE ' . $key . ' IN (' . implode(',', $ids) . ')')));
                    $gone = [...$gone, ...array_filter($chunk, fn (string $t): bool => !isset($existing[$t]))];
                }
                Inbox::forgetTargets($db, $gone); // 3.26: the notifications about them too
                foreach (array_chunk($gone, 500) as $chunk) {
                    $deleted += $db->run('DELETE FROM {comments} WHERE target IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->rowCount();
                }
            } catch (\PDOException) {
                return $deleted;
            }
        }

        return $deleted;
    }
}
