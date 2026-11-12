<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Contacts;
use Kaleta\Core\QuoteLineSets;
use Kaleta\Core\Quotes;
use Kaleta\Core\Response;

/**
 * The second step of quotes (3.40, design A12 "Release Q2") – actions of Admin\Modules\Quotes kept apart from the quote
 * screens and from sending:
 *
 *  - task (Admin\TaskActions): the tasks of a quote (target quote:<id>) on its detail – add, done, snooze, edit, delete.
 *  - follow_up (POST id, days): "Remind me in N days" – a task about the quote for its responsible person
 *    (Quotes::followUp); the send dialog offers the same box (followUpAfterSend).
 *  - customer_list (POST kind contact|company, id, price_list_id): the price list a new quote for that customer takes,
 *    chosen in the box Quotes on the customer's or company's page (needs Customers as well).
 *  - line_set_rename, line_set_delete (POST, administrators): the saved line sets on the Settings tab. Saving and inserting a
 *    set happen in the editor (actionSave, next=save_set / line_set).
 *
 * The module's section is checked by the admin kernel; every write is a POST with the CSRF token.
 */
trait QuoteExtras
{
    use TaskActions;

    /** The quote a posted task form is about (its detail is where the panel lives). */
    protected function taskTarget(): ?array
    {
        $quote = Quotes::get($this->db, $this->request->postInt('id'));

        return $quote === null ? null : ['target' => 'quote:' . $quote['id'], 'back' => ['detail', ['id' => $quote['id']]], 'title' => $quote['label']];
    }

    protected function actionFollowUp(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        try {
            $task = Quotes::followUp($this->app, $id, $this->request->postInt('days', Quotes::FOLLOW_UP_DAYS), $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }
        $this->app->session->flash('ok', $this->followUpMessage($task));

        return Response::redirect($this->url('detail', ['id' => $id]) . '#tasks');
    }

    /** The send dialog's box "Remind me in N days" after a quote went out or was finalised – a message, never an error. */
    protected function followUpAfterSend(int $id): void
    {
        if (!$this->request->postBool('follow_up')) {
            return;
        }
        try {
            $task = Quotes::followUp($this->app, $id, $this->request->postInt('follow_up_days', Quotes::FOLLOW_UP_DAYS), $this->app->auth()->id());
            $this->app->session->flash('info', $this->followUpMessage($task));
        } catch (\DomainException $e) {
            $this->app->session->flash('info', t('The reminder was not added: %s', $e->getMessage()));
        }
    }

    protected function actionCustomerList(): Response
    {
        $kind = $this->request->post('kind') === 'company' ? 'company' : 'contact';
        $id = $this->request->postInt('id');
        $back = $this->app->url(($kind === 'company' ? Contacts::companyAdminPath($id) : Contacts::adminPath($id))) . '#quotes';
        if (!$this->request->isPost()) {
            return Response::redirect($back);
        }
        try {
            $changed = Quotes::setCustomerList($this->app, $kind, $id, $this->request->postInt('price_list_id'), $this->app->auth()->id());
            $this->app->session->flash('ok', t($changed ? 'New quotes for this customer take the chosen price list.' : 'The price list stays as it was.'));
        } catch (\DomainException $e) {
            $this->app->session->flash('chyba', $e->getMessage());
        }

        return Response::redirect($back);
    }

    protected function actionLineSetRename(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'settings');
        }
        try {
            $name = QuoteLineSets::cleanName($this->request->post('name'));
            $former = QuoteLineSets::rename($this->app, $this->request->postInt('id'), $name);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'settings', [], 'chyba');
        }
        if ($former !== $name) {
            ChangeLog::write($this->app, Quotes::SECTION, 'line_set', t('Line set “%1$s” renamed to “%2$s”', $former, $name));
        }

        return Response::redirect($this->url('settings') . '#line-sets');
    }

    protected function actionLineSetDelete(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'settings');
        }
        $name = QuoteLineSets::delete($this->app, $this->request->postInt('id'));
        if ($name !== null) {
            ChangeLog::write($this->app, Quotes::SECTION, 'line_set', t('Line set “%s” deleted', $name));
            $this->app->session->flash('ok', t('The line set “%s” was deleted.', $name));
        }

        return Response::redirect($this->url('settings') . '#line-sets');
    }

    /** "Remind me" done: for whom and when. */
    private function followUpMessage(int $taskId): string
    {
        $task = \Kaleta\Core\Tasks::find($this->db, $taskId) ?? [];
        $for = (int) ($task['assignee_id'] ?? 0);
        $when = format_date((string) ($task['due_at'] ?? ''), true);

        return $for === $this->app->auth()->id() ? t('A reminder for %s is in My work.', $when) : t('A reminder for %1$s is in the work of %2$s – they are notified.', $when, \Kaleta\Core\Assignment::label($this->db, $for));
    }
}
