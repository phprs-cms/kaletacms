<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Notebook;

/**
 * MCP tools of the agent notebook (2.15, Core\Notebook): the notes the site keeps for whoever works on it next –
 * read them before larger changes, write decisions down. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait NotebookTools
{
    /** read_notebook */
    private function toolReadNotebook(string $name, array $a): mixed
    {
        $this->notebookAccess(); // 3.15 (N71): reading needs the Notebook section too, as in the administration
        $topic = isset($a['topic']) && (string) $a['topic'] !== '' ? Notebook::topic($a['topic']) : '';
        if ($topic === null) {
            throw new \DomainException('The topic must be one of: ' . implode(', ', array_keys(Notebook::TOPICS)) . '.');
        }
        $notes = Notebook::all($this->app->db(), $topic, mb_substr(trim((string) ($a['search'] ?? '')), 0, 100), max(1, min(Notebook::MAX_NOTES, (int) ($a['limit'] ?? Notebook::MAX_NOTES))));

        return ['notes' => $notes, 'count' => count($notes), 'topics' => array_keys(Notebook::TOPICS),
            'next' => $notes === [] ? 'No notes yet. When the user decides something that the next person should keep to, write it down with write_notebook.'
                : 'Keep to these notes. When the user decides something new (wording, a page they are sensitive about, a credit), write it down with write_notebook; pin what everyone must know.'];
    }

    /** write_notebook */
    private function toolWriteNotebook(string $name, array $a): mixed
    {
        $this->notebookAccess();
        $data = array_filter(['topic' => $a['topic'] ?? null, 'title' => $a['title'] ?? null, 'text' => $a['text'] ?? null, 'pinned' => isset($a['pinned']) ? (bool) $a['pinned'] : null], fn (mixed $v): bool => $v !== null);
        $saved = Notebook::save($this->app, $data, (int) ($a['id'] ?? 0));
        if (is_string($saved)) {
            throw new \DomainException($saved . (str_contains($saved, 'does not exist') ? ' Use read_notebook.' : ''));
        }

        return ['note' => $saved, 'next' => 'The note is kept for the next conversation and for colleagues (Administration → Notebook). Nothing is shown on the site.'];
    }

    /** delete_notebook_entry */
    private function toolDeleteNotebookEntry(string $name, array $a): mixed
    {
        $this->notebookAccess();
        $id = (int) ($a['id'] ?? 0);
        if (!Notebook::delete($this->app, $id)) {
            throw new \DomainException('The note does not exist. Use read_notebook.');
        }

        return ['deleted' => $id, 'count' => Notebook::summary($this->app->db())['count']];
    }

    /** Reading and writing need the Notebook section (administrators always have it; editors and authors when their role gives it). */
    private function notebookAccess(): void
    {
        if (!$this->app->auth()->hasModule('notebook')) {
            throw new \DomainException('This user has no access to the Notebook – an administrator gives it in Users or Roles.');
        }
    }
}
