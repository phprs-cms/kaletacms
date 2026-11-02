<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Comments;
use Kaleta\Core\Response;

/**
 * The team's thread on a record (3.26, Core\Comments) in the module that owns the record – Pages (page settings and the
 * Team tab of the builder), Collections (the item form) and Enquiries (the notes in the conversation). One action,
 * "comment", adds (op=add), rewrites one's own (op=edit) or deletes (op=delete) a comment; the module says which record
 * the form is about (commentTarget), so the section's permission guards the thread and a comment id alone never reaches
 * another record. A form gets a redirect with a message; the builder (format=json) gets the fresh thread.
 */
trait CommentActions
{
    /**
     * The record the posted form is about: its comment target, the screen to return to ([action, params]) and its title
     * for the change log; null when it does not exist (or is in the trash).
     *
     * @return array{target: string, back: array{0: string, 1: array<string, int|string>}, title: string}|null
     */
    abstract protected function commentTarget(): ?array;

    protected function actionComment(): Response
    {
        $json = $this->request->post('format') === 'json';
        $record = $this->request->isPost() ? $this->commentTarget() : null;
        if ($record === null) {
            return $json ? Response::json(['ok' => false, 'chyba' => t('The comment does not exist.')], 404) : $this->back();
        }
        $target = $record['target'];
        $op = match ($this->request->post('op')) {
            'edit' => 'edit',
            'delete' => 'delete',
            default => 'add',
        };
        $id = $this->request->postInt('comment');
        $user = $this->app->auth()->user() ?? [];
        try {
            $id = match ($op) {
                'edit' => Comments::edit($this->app, $target, $id, $this->request->post('text'), $user)['id'],
                'delete' => (function () use ($target, $id, $user): int {
                    Comments::delete($this->db, $target, $id, $user, $this->app->auth()->isAdmin());

                    return $id;
                })(),
                default => Comments::post($this->app, $target, $this->request->post('text'), $user)['id'],
            };
        } catch (\DomainException $e) {
            if ($json) {
                return Response::json(['ok' => false, 'chyba' => t($e->getMessage())], 400);
            }
            if ($op !== 'delete') {
                // what was written is not lost: the form shows it again once (commentThread)
                $this->app->session->set('comment_draft', ['target' => $target, 'comment' => $op === 'edit' ? $id : 0, 'text' => mb_substr($this->request->post('text'), 0, Comments::MAX_TEXT)]);
            }
            $this->app->session->flash('chyba', $e->getMessage());

            return Response::redirect($this->url($record['back'][0], $record['back'][1]) . '#comments');
        }
        $done = ['add' => 'added', 'edit' => 'edited', 'delete' => 'deleted'][$op];
        ChangeLog::write($this->app, static::IDENT, 'comment ' . $done, mb_substr('#' . (Comments::parseTarget($target)['id'] ?? 0) . ' ' . $record['title'] . ' (comment #' . $id . ')', 0, 255));
        if ($json) {
            return Response::json(['ok' => true, 'komentare' => Comments::forBuilder($this->app, $target)]);
        }
        $this->app->session->flash('ok', ['add' => 'The comment was added.', 'edit' => 'The comment was saved.', 'delete' => 'The comment was deleted.'][$op]);

        return Response::redirect($this->url($record['back'][0], $record['back'][1]) . '#comments');
    }

    /**
     * What views/admin/comments.php needs for a thread: the comments (deleted ones as placeholders), the names of the people
     * mentioned, who may be mentioned, the hidden fields of the form and a refused text to show again.
     *
     * @param array<string, int|string> $fields the hidden fields that name the record (ids, idp, idk)
     * @return array<string, mixed>
     */
    protected function commentThread(string $target, array $fields): array
    {
        $comments = Comments::of($this->db, $target, true);
        $draft = $this->app->session->get('comment_draft');
        if (is_array($draft)) {
            $this->app->session->remove('comment_draft');
        }

        return ['target' => $target, 'comments' => $comments, 'fields' => $fields, 'action' => $this->url('comment'),
            'people' => Comments::people($this->db, array_merge(...array_column($comments, 'mentions'))),
            'mentionable' => Comments::mentionable($this->db, $target), 'me' => $this->app->auth()->id(), 'admin' => $this->app->auth()->isAdmin(),
            'draft' => is_array($draft) && ($draft['target'] ?? '') === $target ? ['comment' => (int) ($draft['comment'] ?? 0), 'text' => (string) ($draft['text'] ?? '')] : null];
    }
}
