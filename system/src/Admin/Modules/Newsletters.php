<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Language;
use Kaleta\Core\Mailing;
use Kaleta\Core\Response;

/**
 * Newsletters (Newsletter extension): the latest news to confirmed subscribers, in one template styled by the design
 * system (Core\Mailing). Anyone with the module writes drafts and sends tests; sending to subscribers needs the
 * publishing permission, like publishing a page.
 */
final class Newsletters extends Module
{
    public const string IDENT = 'newsletters';
    public const string EXTENSION = 'newsletter';
    public const string NAME = 'Newsletters';
    public const string GROUP = 'Content';
    public const string ICON = 'rozesilka';

    protected function actionList(): Response
    {
        return $this->view('list', 'Newsletters', [
            'newsletters' => Mailing::all($this->db), 'confirmed' => Mailing::confirmedCount($this->db), 'problem' => Mailing::problem($this->app),
        ]);
    }

    protected function actionNew(): Response
    {
        $last = $this->db->one('SELECT subject, preheader, intro, news_mode, news_count, button_label, button_url, language FROM {newsletters} ORDER BY id DESC LIMIT 1');

        // a new one starts from the previous one's settings (the intro and button often repeat), with an empty subject
        return $this->form(['id' => 0, 'subject' => '', 'preheader' => '', 'status' => 'draft', 'news_ids' => '', 'scheduled_at' => null]
            + ($last ?? ['intro' => '', 'news_mode' => 'latest', 'news_count' => 3, 'button_label' => '', 'button_url' => '', 'language' => '']));
    }

    protected function actionEdit(): Response
    {
        $n = Mailing::byId($this->db, $this->request->getInt('id'));

        return $n === null ? $this->error('The newsletter does not exist.', 404) : $this->form($n);
    }

    /** Saves the draft; "test" also sends it to the signed-in user's address. */
    protected function actionSave(): Response
    {
        $r = $this->request;
        if (!$r->isPost()) {
            return $this->back();
        }
        $input = [
            'subject' => $r->post('subject'), 'preheader' => $r->post('preheader'), 'intro' => $r->post('intro'), 'news_mode' => $r->post('news_mode'),
            'news_count' => $r->postInt('news_count', 3), 'news_ids' => is_array($_POST['news_ids'] ?? null) ? $_POST['news_ids'] : [],
            'button_label' => $r->post('button_label'), 'button_url' => $r->post('button_url'), 'language' => $r->post('language'),
        ];
        $id = $r->postInt('id');
        try {
            $id = Mailing::save($this->app, $input, $id);
        } catch (\InvalidArgumentException | \DomainException $e) {
            $this->app->session->flash('chyba', t($e->getMessage()));

            return $id > 0 ? $this->back('', 'edit', ['id' => $id]) : $this->back('', 'new');
        }
        if ($r->postBool('test')) {
            return $this->sendTest($id);
        }

        return $this->back('The newsletter was saved.', 'edit', ['id' => $id]);
    }

    /** The e-mail as subscribers get it, for the preview frame; links open in a new window. */
    protected function actionPreview(): Response
    {
        $n = Mailing::byId($this->db, $this->request->getInt('id'));
        if ($n === null) {
            return $this->error('The newsletter does not exist.', 404);
        }
        $html = $n['html'] ?? Mailing::render($this->app, $n)[0];
        $html = str_replace(['<head>', Mailing::UNSUBSCRIBE], ['<head><base target="_blank">', e(Mailing::absolute($this->app, 'odber'))], (string) $html);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src * data:; frame-ancestors 'self'; base-uri 'self' https: http:"]);
    }

    protected function actionTest(): Response
    {
        return $this->request->isPost() ? $this->sendTest($this->request->postInt('id')) : $this->back();
    }

    /** Send now or at a time – only with the publishing permission. */
    protected function actionSend(): Response
    {
        $r = $this->request;
        $id = $r->postInt('id');
        if (!$r->isPost() || !$this->app->auth()->canPublish()) {
            return $this->back('Sending to subscribers needs the publishing permission.', 'edit', ['id' => $id], 'chyba');
        }
        try {
            Mailing::send($this->app, $id, $r->post('when') === 'later' ? $r->post('at') : null);
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->back(t($e->getMessage()), 'edit', ['id' => $id], 'chyba');
        }
        $n = (array) Mailing::byId($this->db, $id);

        return $this->back($n['status'] === 'scheduled'
            ? t('The newsletter is scheduled for %s.', format_date((string) $n['scheduled_at'], true))
            : t('Sending has started: %d subscribers. The e-mails go out in batches each time cron runs.', (int) $n['recipients']), 'edit', ['id' => $id]);
    }

    protected function actionUnschedule(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->canPublish()) {
            Mailing::unschedule($this->app, $this->request->postInt('id'));
        }

        return $this->back('Scheduling was cancelled – the newsletter is a draft again.', 'edit', ['id' => $this->request->postInt('id')]);
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            Mailing::delete($this->app, $this->request->postInt('id'));
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->back(t($e->getMessage()), '', [], 'chyba');
        }

        return $this->back('The newsletter was deleted.');
    }

    private function sendTest(int $id): Response
    {
        $n = Mailing::byId($this->db, $id);
        $email = (string) ($this->app->auth()->user()['email'] ?? '');
        if ($n === null) {
            return $this->back();
        }
        if ($email === '') {
            return $this->back('Your account has no e-mail address – add one under My account.', 'edit', ['id' => $id], 'chyba');
        }
        $ok = Mailing::sendTest($this->app, $n, $email);

        return $this->back($ok ? t('The test e-mail went to %s.', $email) : t('The test e-mail could not be sent: %s', t(\Kaleta\Core\Mail::$error)), 'edit', ['id' => $id], $ok ? 'ok' : 'chyba');
    }

    /** @param array<string, mixed> $n */
    private function form(array $n): Response
    {
        $s = $this->app->settings();
        $languages = Language::additional($s) === [] ? [] : [Language::defaults($s), ...Language::additional($s)];
        $news = \Kaleta\Core\Extensions::isEnabled($s, 'novinky')
            ? $this->db->all('SELECT idc, titulek, datum, jazyk FROM {novinky} WHERE visible = 1 AND datum <= ? AND smazano IS NULL ORDER BY datum DESC, idc DESC LIMIT 40', [\Kaleta\Core\Clock::now()]) : [];

        return $this->view('form', (int) $n['id'] > 0 ? (string) $n['subject'] : 'New newsletter', [
            'n' => $n, 'news' => $news, 'languages' => $languages, 'newsEnabled' => \Kaleta\Core\Extensions::isEnabled($s, 'novinky'),
            'confirmed' => Mailing::confirmedCount($this->db), 'problem' => Mailing::problem($this->app), 'canPublish' => $this->app->auth()->canPublish(),
            'email' => (string) ($this->app->auth()->user()['email'] ?? ''),
        ]);
    }
}
