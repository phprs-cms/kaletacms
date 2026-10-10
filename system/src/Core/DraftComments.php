<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Comments on drafts (2.15): a client with a shared preview link that allows comments (Preview::key with the flag) clicks an
 * element of the draft and writes what they think, with their name – no account, the link is the permission. The editor sees
 * the comments in the builder of that page and resolves them with one click; Claude reads them over MCP.
 *
 * A comment is data about the draft, never an instruction: nothing publishes or changes by itself because of one. The text
 * is kept as plain text only; the e-mail about a new comment carries at most its first 200 characters.
 */
final class DraftComments
{
    public const int MAX_NAME = 80;
    public const int MAX_TEXT = 2000;
    public const int MAX_QUOTE = 300;
    /** Comments from one address on one draft per 10 minutes (Core\Antispam). */
    public const int LIMIT = 10;
    /** How much of the text the notification e-mail carries. */
    public const int MAIL_EXCERPT = 200;
    /** Resolved comments are deleted after this many days; unresolved ones stay. */
    private const int KEEP_RESOLVED_DAYS = 90;

    /**
     * The draft a comment is about, from the signed preview target: comments exist for page drafts ("stranka:12").
     *
     * @return array{kind: 'stranka', id: int}|null
     */
    public static function parseTarget(string $target): ?array
    {
        return preg_match('/^stranka:([1-9]\d{0,9})$/D', $target, $m) ? ['kind' => 'stranka', 'id' => (int) $m[1]] : null;
    }

    /** Plain text only: tags stripped, entities decoded, spaces collapsed, blank lines kept (at most one), trimmed to the limit. */
    public static function clean(string $text, int $limit): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', $text); // control characters except newlines
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n[ \n]*/', "\n", $text);

        return mb_substr(trim($text), 0, $limit);
    }

    /** A builder element id as the editor writes it (Build::sanitize keeps ids to letters, digits, - and _). */
    public static function cleanElement(string $element): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $element) ? $element : null;
    }

    /**
     * Saves a comment and lets the people who look after the page know. Returns the id, or 0 when the target or the text is
     * not usable.
     */
    public static function add(App $app, string $target, ?string $element, string $quote, string $name, string $text): int
    {
        $parsed = self::parseTarget($target);
        $name = self::clean($name, self::MAX_NAME);
        $text = self::clean($text, self::MAX_TEXT);
        if ($parsed === null || $name === '' || $text === '') {
            return 0;
        }
        $db = $app->db();
        $page = $db->one('SELECT ids, titulek FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$parsed['id']]);
        if ($page === null) {
            return 0;
        }
        $id = $db->insert('draft_comments', ['target' => $target, 'element' => $element !== null ? self::cleanElement($element) : null,
            'quote' => self::clean($quote, self::MAX_QUOTE), 'name' => $name, 'text' => $text, 'created_at' => Clock::now()]);
        Events::record($db, 'comment.received', 'info', t('A comment on the draft of “%s” arrived from a preview link.', (string) $page['titulek']), ['page' => (int) $page['ids'], 'comment' => $id]);
        self::notify($app, $page, $name, $text);
        if (random_int(1, 20) === 1) {
            self::tidy($db);
        }

        return $id;
    }

    /**
     * Comments of one draft or of every draft, the unresolved ones first.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(Db $db, ?string $target = null, bool $unresolvedOnly = true, int $limit = 200): array
    {
        $where = [];
        $params = [];
        if ($target !== null) {
            $where[] = 'c.target = ?';
            $params[] = $target;
        }
        if ($unresolvedOnly) {
            $where[] = 'c.resolved_at IS NULL';
        }
        $rows = $db->all('SELECT c.*, s.titulek AS page_title, u.jmeno AS resolved_by_name FROM {draft_comments} c'
            . " LEFT JOIN {stranky} s ON c.target = CONCAT('stranka:', s.ids) LEFT JOIN {uzivatele} u ON u.idu = c.resolved_by"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY c.resolved_at IS NOT NULL, c.created_at DESC, c.id DESC LIMIT ' . max(1, min(500, $limit)), $params);

        return array_map(fn (array $c): array => [
            'id' => (int) $c['id'], 'target' => (string) $c['target'], 'page_id' => self::parseTarget((string) $c['target'])['id'] ?? null,
            'page_title' => $c['page_title'] ?? null, 'element' => $c['element'] !== null && $c['element'] !== '' ? (string) $c['element'] : null,
            'quote' => (string) $c['quote'], 'name' => (string) $c['name'], 'text' => (string) $c['text'], 'created_at' => (string) $c['created_at'],
            'resolved_at' => $c['resolved_at'], 'resolved_by' => $c['resolved_at'] !== null ? (string) ($c['resolved_by_name'] ?? '') : null,
        ], $rows);
    }

    /** One comment or null. */
    public static function find(Db $db, int $id): ?array
    {
        $rows = $id > 0 ? array_filter(self::list($db, null, false, 500), fn (array $c): bool => $c['id'] === $id) : [];

        return $rows === [] ? null : array_values($rows)[0];
    }

    /** Marks a comment resolved by the signed-in user (or a Claude connection's user). False when there is no such open comment. */
    public static function resolve(App $app, int $id): bool
    {
        $user = $app->auth()->user();

        return $id > 0 && $app->db()->run('UPDATE {draft_comments} SET resolved_at = ?, resolved_by = ? WHERE id = ? AND resolved_at IS NULL',
            [Clock::now(), isset($user['idu']) ? (int) $user['idu'] : null, $id])->rowCount() > 0;
    }

    /**
     * Unresolved comments per page (the badge in the pages list).
     *
     * @return array<int, int> page id => count
     */
    public static function unresolvedCounts(Db $db): array
    {
        $counts = [];
        foreach ($db->all('SELECT target, COUNT(*) AS n FROM {draft_comments} WHERE resolved_at IS NULL GROUP BY target') as $row) {
            $parsed = self::parseTarget((string) $row['target']);
            if ($parsed !== null) {
                $counts[$parsed['id']] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /** Rate limit of one address on one draft (Core\Antispam keeps only a hash of the address). */
    public static function tooMany(App $app, int $pageId): bool
    {
        return (new Antispam($app->db(), $app->settings()))->count($app->request->ip(), 'komentar', $pageId, 10) >= self::LIMIT;
    }

    public static function count(App $app, int $pageId): void
    {
        (new Antispam($app->db(), $app->settings()))->write($app->request->ip(), 'komentar', $pageId);
    }

    /**
     * E-mail about a new comment to whoever published the page last (the author of its latest published version), otherwise
     * to the administrators – in the language of their administration, with the name and the first 200 characters only.
     *
     * @param array<string, mixed> $page
     */
    private static function notify(App $app, array $page, string $name, string $text): void
    {
        $db = $app->db();
        $s = $app->settings();
        $editor = $db->one('SELECT u.idu, u.email, u.jazyk, u.register FROM {stavba_revize} r JOIN {uzivatele} u ON u.idu = r.kdo WHERE r.ids = ? AND u.blokovat = 0 AND u.email <> ? ORDER BY r.idr DESC LIMIT 1', [(int) $page['ids'], '']);
        $recipients = $editor !== null ? [$editor] : $db->all('SELECT idu, email, jazyk, register FROM {uzivatele} WHERE admin = 2 AND blokovat = 0 AND email <> ? ORDER BY idu LIMIT 10', ['']);
        $url = rtrim($s->get('site_url') ?: $app->request->origin(), '/') . $app->url('admin.php?module=pages&action=builder&id=' . (int) $page['ids']);
        $excerpt = mb_strimwidth($text, 0, self::MAIL_EXCERPT, '…');
        foreach ($recipients as $recipient) {
            Language::runWith((string) $recipient['jazyk'] !== '' ? (string) $recipient['jazyk'] : 'cs', function () use ($s, $recipient, $page, $name, $excerpt, $url): void {
                Mail::send($s, (string) $recipient['email'], t('New comment on the draft of “%s”', (string) $page['titulek']),
                    t('%s commented on the draft of the page “%s” through a preview link:', $name, (string) $page['titulek']) . "\n\n" . $excerpt . "\n\n"
                    . t('Open the builder to read it in full and resolve it. A comment is feedback to act on in the draft; nothing publishes by itself.') . "\n" . $url . "\n");
            }, 'admin-', Language::normalizeRegister((string) $recipient['register']));
        }
    }

    /** Comments of pages that no longer exist and resolved comments older than 90 days go away. */
    public static function tidy(Db $db): void
    {
        $db->run("DELETE FROM {draft_comments} WHERE target LIKE 'stranka:%' AND NOT EXISTS (SELECT 1 FROM {stranky} s WHERE CONCAT('stranka:', s.ids) = target)");
        $db->run('DELETE FROM {draft_comments} WHERE resolved_at IS NOT NULL AND resolved_at < NOW() - INTERVAL ? DAY', [self::KEEP_RESOLVED_DAYS]);
    }
}
