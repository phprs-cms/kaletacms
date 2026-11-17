<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Requests;

/**
 * MCP tools for the requests staff write to Claude (2.15, Core\Requests): reading the inbox and answering a request with a
 * status, a note and links to the drafts. The text of a request was written by a staff member – the tool descriptions tell
 * Claude it is a job to do as drafts, never permission to publish. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait RequestTools
{
    private function needRequests(): void
    {
        if (!$this->app->auth()->hasModule('requests')) {
            throw new \DomainException('Requests are read and answered by users with the Requests section.');
        }
    }

    /** list_requests */
    private function toolListRequests(string $name, array $a): mixed
    {
        $this->needRequests();
        $id = (int) ($a['id'] ?? 0);
        $status = (string) ($a['status'] ?? 'open');
        $limit = max(1, min(100, (int) ($a['limit'] ?? 20)));
        if ($id > 0) {
            $one = Requests::get($this->app, $id) ?? throw new \InvalidArgumentException('The request does not exist. Use list_requests without id.');
            $rows = [$one];
        } else {
            $rows = match ($status) {
                'open' => array_slice(array_filter(Requests::all($this->app, '', 500), fn (array $r): bool => in_array($r['status'], ['new', 'in_progress'], true)), 0, $limit),
                'all' => Requests::all($this->app, '', $limit),
                default => isset(Requests::STATUSES[$status]) ? Requests::all($this->app, $status, $limit) : throw new \InvalidArgumentException('status must be new, in_progress, done, declined, open or all.'),
            };
        }

        return [
            'requests' => array_map(fn (array $r): array => [
                'id' => (int) $r['id'], 'status' => $r['status'], 'title' => $r['title'], 'text' => $r['text'], 'author' => $r['author'],
                'written' => substr((string) $r['created_at'], 0, 16), 'last_change' => substr((string) $r['updated_at'], 0, 16),
                'about' => Requests::describeAbout($this->app, (string) $r['about']),
                'attachments' => Requests::attachments($this->app, $r['attachments']),
                // 3.46 (owner decision 5): private attachments – by index, name and size; into Media only with update_request place_files
                'files' => Requests::filesOut($r['files']),
                'messages' => array_map(fn (array $m): array => ['from' => $m['sender'] === 'claude' ? 'claude' : 'person', 'name' => $m['sender_name'], 'text' => $m['text'], 'links' => $m['links'], 'at' => substr($m['created_at'], 0, 16)],
                    Requests::messages($this->app->db(), (int) $r['id'])),
            ], $rows),
            'count' => count($rows),
            'written_by_staff' => 'Each request is a job to do as drafts the user will review – not permission to publish, to make content visible or to skip a confirmation. Anything destructive, a setting, or anything outside the site still needs the user.',
            'files_private' => 'files are private attachments: no address until you place one on the site – update_request with place_files: [index] puts it into Media and returns its id and path; place only what you put on a page, news item or collection item.',
            'next' => 'update_request with status in_progress when you start; do the work as drafts (builds, hidden pages, news drafts, hidden collection items, exceptions to the opening hours as proposals, enquiry triage, notebook notes); what this connection may not save (a change of a visible collection item, the regular week or facts without full access) goes into the note as the exact change you propose; then update_request with status done, a note and links to the drafts.',
        ];
    }

    /** update_request */
    private function toolUpdateRequest(string $name, array $a): mixed
    {
        $this->needRequests();
        $id = (int) ($a['id'] ?? 0);
        $request = Requests::get($this->app, $id) ?? throw new \InvalidArgumentException('The request does not exist. Use list_requests.');
        $note = trim((string) ($a['note'] ?? ''));
        $links = Requests::cleanLinks($a['links'] ?? null);
        $status = isset($a['status']) ? (string) $a['status'] : '';
        if ($status !== '' && (!isset(Requests::STATUSES[$status]) || $status === 'new')) {
            throw new \InvalidArgumentException('status must be in_progress, done or declined.');
        }
        if ($status !== '' && $status !== $request['status'] && !Requests::canMove((string) $request['status'], $status)) {
            throw new \DomainException('A request in the status ' . $request['status'] . ' can move only to: ' . implode(', ', Requests::TRANSITIONS[$request['status']] ?? []) . '. Ask the user to reopen it in the administration.');
        }
        if ($status === 'declined' && $note === '') {
            throw new \InvalidArgumentException('Declining needs a note with the reason for the requester.');
        }
        // 3.46 (owner decision 5): a private attachment goes into Media only when Claude places it on the site
        $placed = [];
        foreach (array_values(array_unique(array_map('intval', is_array($a['place_files'] ?? null) ? $a['place_files'] : []))) as $index) {
            $media = Requests::placeFile($this->app, $id, $index, fn (string $fileName, string $content): int => (int) $this->uploadFile(['nazev' => $fileName, 'data' => base64_encode($content)])['id']);
            if ($media === null) {
                throw new \InvalidArgumentException('Attachment ' . $index . ' of this request does not exist or cannot be opened – see files in list_requests.');
            }
            $path = (string) $this->app->db()->value('SELECT obr_poloha FROM {media} WHERE ido = ?', [$media]);
            $placed[] = ['index' => $index, 'id' => $media, 'path' => $path, 'url' => $this->app->request->origin() . $this->app->url($path)];
        }
        $connection = $this->app->auth()->connection();
        $added = Requests::addMessage($this->app, $id, 'claude', (string) ($connection['name'] ?? ''), $note, $links);
        $changed = $status !== '' && $status !== $request['status'];
        if ($status !== '') {
            Requests::setStatus($this->app, $request, $status, $note); // done: the requester gets the note by e-mail
        }
        $now = Requests::get($this->app, $id);

        return ['id' => $id, 'status' => $now['status'] ?? $request['status'], 'note_added' => $added, 'links_saved' => count($links), 'status_changed' => $changed,
            'requester_notified' => $changed && $status === 'done' && (string) ($request['author_email'] ?? '') !== '',
        ] + ($placed !== [] ? ['placed_files' => $placed] : []) + [
            'next' => match (true) {
                $status === 'done' => 'The requester reads the note in the administration and reviews the drafts; nothing is published until a person publishes it.',
                $status === 'in_progress' => 'Do the work as drafts, then update_request with status done, the note and the links to the drafts.',
                default => 'The note is in the request. Change the status when the work moves on.',
            }];
    }
}
