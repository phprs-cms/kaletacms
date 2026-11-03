<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Comments;
use Kaleta\Core\Handover;

/**
 * MCP tools of the team's comment threads (3.26): list_comments reads the thread of an enquiry, a page or a collection item,
 * add_comment appends to it as the connected user. Plain text only; an @mention (a sign-in name) notifies the colleague in
 * the administration and by e-mail, so only a full-access connection may mention, and only people who may open the record
 * (Core\Comments::mentions – the same check as the administration's). A drafts-only connection writes plain comments and
 * notifies nobody. A comment Claude writes on a page or an item is part of its session (undo_agent_session takes it back);
 * an enquiry note never enters the journal (personal data). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait CommentTools
{
    /** list_comments */
    private function toolListComments(string $name, array $a): mixed
    {
        [$type, $id, $target] = $this->commentThread($a);
        $db = $this->app->db();
        $all = Comments::of($db, $target);
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $shown = array_slice($all, -$limit);

        return ['target' => $target, 'total' => count($all),
            'comments' => array_map(fn (array $c): array => ['id' => $c['id'], 'at' => substr($c['at'], 0, 16), 'by' => ($c['by_id'] > 0 ? ['id' => $c['by_id']] : []) + ['name' => $c['by']], 'text' => $c['text']]
                + ($c['via'] === 'mcp' ? ['via' => 'claude'] : [])
                + ($c['mentions'] !== [] ? ['mentions' => $this->mentionedPeople($c['mentions'])] : [])
                + ($c['edited'] !== null ? ['edited' => substr($c['edited'], 0, 16)] : []), $shown)]
            + (count($all) > $limit ? ['note' => 'The newest ' . $limit . ' of ' . count($all) . ' comments, oldest first; raise limit (up to 200) for more.'] : [])
            + ['admin_url' => $this->threadUrl($type, $id)];
    }

    /** add_comment */
    private function toolAddComment(string $name, array $a): mixed
    {
        [$type, $id, $target] = $this->commentThread($a);
        $text = is_string($a['text'] ?? null) ? Comments::clean($a['text']) : '';
        if ($text === '') {
            throw new \InvalidArgumentException('text is required: the comment as plain text (up to ' . Comments::MAX_TEXT . ' characters).');
        }
        $auth = $this->app->auth();
        $db = $this->app->db();
        // the administration's check of the mentions, its refusal in English (the MCP interface); nothing is saved after a refusal
        try {
            $mentions = \Kaleta\Core\Language::runWith('en', fn (): array => Comments::mentions($db, $target, $text), 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing was saved – mention only colleagues who may open it.');
        }
        if ($mentions !== [] && $auth->draftsOnly()) {
            throw new \DomainException('A mention notifies the colleague by e-mail, so this drafts-only connection cannot mention anyone. Write the comment without the @mention, '
                . 'or the user mentions them in the administration (or connects Claude with full access). Nothing was saved.');
        }
        // a full-access connection posts as the administration does (mentions stored, the mentioned and the responsible person
        // notified); a drafts-only one only appends the comment and sends nobody anything
        $commentId = $auth->draftsOnly() ? Comments::add($db, $target, $text, $auth->user() ?? [], 'mcp') : Comments::post($this->app, $target, $text, $auth->user() ?? [], 'mcp')['id'];
        if ($db->journal !== null && !in_array($type, ['enquiry', 'contact'], true)) { // 3.28: nor a note on a customer (personal data)
            $db->journal->inserted('comments', [], $commentId); // ka_comments is not a journaled table (AgentJournal::PERSONAL_TABLES): this row is
        }
        $people = array_map(fn (array $p): string => strtolower($p['user']), Comments::people($db, $mentions));
        $plain = array_values(array_filter(Comments::mentionTokens($text), fn (string $t): bool => !in_array(strtolower($t), $people, true) && !in_array(strtolower(rtrim($t, '._-')), $people, true)));

        return ['id' => $commentId, 'target' => $target, 'added' => true]
            + ($mentions !== [] ? ['mentioned' => $this->mentionedPeople($mentions)] : [])
            + ($plain !== [] ? ['not_mentioned' => array_map(fn (string $t): string => '@' . $t, $plain),
                'note' => 'Nobody signs in under these names, so they stay plain text. Mention a colleague by their sign-in name, e.g. @jana.'] : [])
            + ['admin_url' => $this->threadUrl($type, $id)];
    }

    /**
     * The thread a call names (target "enquiry:12" | "page:5" | "item:40"): the connected user must be able to open its
     * section (Handover::mayOpen – an active account with the section, the feature on) and the record must exist (a page
     * or an item not in the trash) – otherwise an error says why and nothing is read or written.
     *
     * @param array<string, mixed> $a
     * @return array{0: 'enquiry'|'page'|'item'|'contact'|'company'|'deal', 1: int, 2: string}
     */
    private function commentThread(array $a): array
    {
        $parsed = Comments::parseTarget(trim(is_scalar($a['target'] ?? null) ? (string) $a['target'] : ''))
            ?? throw new \InvalidArgumentException('target must be "enquiry:<id>", "page:<id>", "item:<id>", "contact:<id>" or "company:<id>" – e.g. "page:12" (ids from list_enquiries, list_pages, list_collection_items, list_contacts).');
        ['type' => $type, 'id' => $id] = $parsed;
        // 3.28: a customer or a company (Core\Contacts) – the section Customers
        // 3.31: a deal (Core\Deals) – the section Pipeline
        [$section, , $table, $idColumn] = Handover::KINDS[$type] ?? ($type === 'deal' ? [\Kaleta\Core\Deals::SECTION, '', 'deals', 'id']
            : [\Kaleta\Core\Contacts::SECTION, '', $type === 'contact' ? 'contacts' : 'companies', 'id']);
        if (!Handover::mayOpen($this->app->db(), $this->app->settings(), $this->app->auth()->id(), $section)) {
            throw new \DomainException('Only a user who may open ' . ['enquiry' => 'Enquiries', 'page' => 'Pages', 'item' => 'Collections', 'contact' => 'Customers', 'company' => 'Customers', 'deal' => 'Pipeline'][$type] . ' can read and write its comments.');
        }
        $where = match ($type) {
            'page', 'item' => ' AND smazano IS NULL',
            'contact' => ' AND anonymised_at IS NULL',
            default => '',
        };
        if ($this->app->db()->value('SELECT 1 FROM {' . $table . '} WHERE ' . $idColumn . ' = ?' . $where, [$id]) === null) {
            throw new \InvalidArgumentException(['page' => 'The page does not exist (or is in the trash). Use list_pages.', 'item' => 'The collection item does not exist (or is in the trash). Use list_collection_items.',
                'enquiry' => 'The enquiry does not exist. Use list_enquiries.', 'contact' => 'The contact does not exist (or was anonymised). Use list_contacts.',
                'company' => 'The company does not exist. Use list_contacts with kind companies.', 'deal' => 'The deal does not exist.'][$type]);
        }

        return [$type, $id, $type . ':' . $id];
    }

    /**
     * The people a comment mentions: id and name (Handover::person) and – additive, N326-2 – the sign-in name a mention is
     * written with, so Claude can mention them again (a removed account keeps its id and "(former user)", without one).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>|null>
     */
    private function mentionedPeople(array $ids): array
    {
        $db = $this->app->db();
        $people = Comments::people($db, $ids);

        return array_map(fn (int $uid): ?array => ($person = Handover::person($db, $uid)) === null ? null : $person + (isset($people[$uid]) ? ['user' => $people[$uid]['user']] : []), $ids);
    }

    /** The address of the administration screen that shows a thread. */
    private function threadUrl(string $type, int $id): string
    {
        $path = match ($type) {
            'enquiry' => 'admin.php?module=enquiries&action=detail&id=' . $id,
            'page' => 'admin.php?module=pages&action=edit&id=' . $id,
            'contact' => \Kaleta\Core\Contacts::adminPath($id), // 3.28
            'company' => \Kaleta\Core\Contacts::companyAdminPath($id),
            'deal' => \Kaleta\Core\Deals::adminPath($id), // 3.31
            default => 'admin.php?module=collections&action=item&id=' . (int) $this->app->db()->value('SELECT idk FROM {kolekce_polozky} WHERE idp = ?', [$id]) . '&item=' . $id,
        };

        return $this->app->request->origin() . $this->app->url($path);
    }
}
