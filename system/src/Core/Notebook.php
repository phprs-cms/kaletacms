<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * Agent notebook (2.15): notes the site keeps for whoever works on it next – Claude in a new conversation, or a
 * colleague. Decisions ("we never use the word cheap"), style rules, photo credits, the history of the redesign, which
 * pages the client is sensitive about. Nothing here is shown on the site; it is read in the admin (Notebook) and over
 * MCP (read_notebook, write_notebook), and site_info tells Claude early that notes exist.
 *
 * A note is plain text under a topic; pinned notes come first everywhere. The author is the signed-in user's name, or
 * the name of the Claude connection that wrote it – so the next reader knows who decided.
 *
 * 3.39 (N110): every note keeps through which connection it was written (via, its access; '' = a person in the
 * administration). A note written through a drafts-only connection is a proposal: it cannot be pinned (pinned titles
 * reach every connection in site_info), read_notebook marks it so that a later connection – also a full one – treats it as
 * a suggestion to check with the user, never as a decision; a drafts-only connection changes only proposals. A person who
 * saves or pins it in the administration makes it a note of their own.
 */
final class Notebook
{
    /** topic => label (English, the admin translates it) */
    public const array TOPICS = ['decisions' => 'Decisions', 'style' => 'Style and wording', 'credits' => 'Credits', 'history' => 'History', 'todo' => 'To do', 'other' => 'Other'];

    public const int MAX_NOTES = 100;
    public const int MAX_TEXT = 20000;

    /** The topic as stored, or null when it is not one of TOPICS (case and surrounding spaces do not matter). */
    public static function topic(mixed $topic): ?string
    {
        $topic = is_string($topic) ? mb_strtolower(trim($topic)) : '';

        return isset(self::TOPICS[$topic]) ? $topic : null;
    }

    /**
     * Notes, pinned first and then the most recently changed; by a topic and a searched text (title or text).
     *
     * @return list<array{id: int, topic: string, title: string, text: string, pinned: bool, author: string, created_at: string, updated_at: string, via: string, access: string, proposal: bool}>
     */
    public static function all(Db $db, string $topic = '', string $search = '', int $limit = self::MAX_NOTES): array
    {
        $where = [];
        $params = [];
        if ($topic !== '') {
            $where[] = 'topic = ?';
            $params[] = $topic;
        }
        if ($search !== '') {
            $where[] = '(title LIKE ? OR text LIKE ?)';
            array_push($params, '%' . addcslashes($search, '%_\\') . '%', '%' . addcslashes($search, '%_\\') . '%');
        }
        try {
            $rows = $db->all('SELECT * FROM {notebook}' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY pinned DESC, updated_at DESC, id DESC LIMIT ' . max(1, min(self::MAX_NOTES, $limit)), $params);
        } catch (\Throwable) {
            $rows = []; // before the 2.15 migration
        }

        return array_map(self::row(...), $rows);
    }

    /** @return array{id: int, topic: string, title: string, text: string, pinned: bool, author: string, created_at: string, updated_at: string, via: string, access: string, proposal: bool}|null */
    public static function find(Db $db, int $id): ?array
    {
        $row = $id > 0 ? $db->one('SELECT * FROM {notebook} WHERE id = ?', [$id]) : null;

        return $row === null ? null : self::row($row);
    }

    /** How many notes there are and the titles of the pinned ones – site_info, so Claude learns early that notes exist. @return array{count: int, pinned: list<string>} */
    public static function summary(Db $db): array
    {
        try {
            return ['count' => (int) $db->value('SELECT COUNT(*) FROM {notebook}'),
                'pinned' => array_map('strval', array_column($db->all("SELECT title FROM {notebook} WHERE pinned = 1 AND access <> 'drafts' ORDER BY updated_at DESC, id DESC LIMIT 20"), 'title'))];
        } catch (\Throwable) {
            return ['count' => 0, 'pinned' => []]; // before the 2.15 migration
        }
    }

    /**
     * Creates a note, or changes the given fields of an existing one (the others stay). Returns the saved note, or the
     * error as a string.
     *
     * @param array{topic?: mixed, title?: mixed, text?: mixed, pinned?: mixed} $data
     * @return array<string, mixed>|string
     */
    public static function save(App $app, array $data, int $id = 0): array|string
    {
        $db = $app->db();
        $existing = $id > 0 ? self::find($db, $id) : null;
        if ($id > 0 && $existing === null) {
            return t('The note does not exist.');
        }
        // 3.39 (N110): a drafts-only connection proposes – it changes only its own kind of note and never pins
        $access = (string) ($app->auth()->connection()['access'] ?? '');
        $proposal = $access === 'drafts';
        if ($proposal && $existing !== null && !$existing['proposal']) {
            return t('A drafts-only connection cannot change a note a person or a full connection wrote – write a new note as a proposal instead.');
        }
        $topic = array_key_exists('topic', $data) || $existing === null ? self::topic($data['topic'] ?? 'other') : $existing['topic'];
        if ($topic === null) {
            return t('The topic must be one of: %s.', implode(', ', array_keys(self::TOPICS)));
        }
        $title = array_key_exists('title', $data) || $existing === null ? mb_substr(trim(strip_tags(is_scalar($data['title'] ?? null) ? (string) $data['title'] : '')), 0, 150) : $existing['title'];
        $text = array_key_exists('text', $data) || $existing === null ? mb_substr(trim(strip_tags(is_scalar($data['text'] ?? null) ? (string) $data['text'] : '')), 0, self::MAX_TEXT) : $existing['text'];
        if ($title === '') {
            return t('A note needs a title.');
        }
        if ($text === '') {
            return t('A note needs a text.');
        }
        $now = date('Y-m-d H:i:s');
        $row = ['topic' => $topic, 'title' => $title, 'text' => $text, 'pinned' => $proposal ? 0 : (array_key_exists('pinned', $data) ? (int) (bool) $data['pinned'] : (int) ($existing['pinned'] ?? false)),
            'author' => self::author($app), 'updated_at' => $now,
            'via' => mb_substr((string) ($app->auth()->connection()['name'] ?? ''), 0, 100), 'access' => in_array($access, ['full', 'drafts', 'read'], true) ? $access : '']; // the access levels of Mcp\Catalog::CONNECTION_ACCESS (Core may not call Mcp – docs/design/code-layers.md)
        if ($existing === null) {
            $id = $db->insert('notebook', $row + ['created_at' => $now]);
        } else {
            $db->update('notebook', $row, ['id' => $id]);
        }
        ChangeLog::write($app, 'notebook', $existing === null ? 'create' : 'update', $title);

        return self::find($db, $id) ?? $row + ['id' => $id];
    }

    public static function delete(App $app, int $id): bool
    {
        $note = self::find($app->db(), $id);
        if ($note === null) {
            return false;
        }
        $app->db()->delete('notebook', ['id' => $id]);
        ChangeLog::write($app, 'notebook', 'delete', $note['title']);

        return true;
    }

    /** Who writes: the Claude connection that makes the request, otherwise the signed-in user's name (or login). */
    public static function author(App $app): string
    {
        $user = $app->auth()->user();

        return mb_substr((string) ($app->auth()->connection()['name'] ?? '') ?: ((string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? '')), 0, 100);
    }

    /** @param array<string, mixed> $r @return array{id: int, topic: string, title: string, text: string, pinned: bool, author: string, created_at: string, updated_at: string, via: string, access: string, proposal: bool} */
    private static function row(array $r): array
    {
        $access = (string) ($r['access'] ?? '');

        return ['id' => (int) $r['id'], 'topic' => (string) $r['topic'], 'title' => (string) $r['title'], 'text' => (string) $r['text'], 'pinned' => (bool) $r['pinned'],
            'author' => (string) $r['author'], 'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
            'via' => (string) ($r['via'] ?? ''), 'access' => $access, 'proposal' => $access === 'drafts'];
    }
}
