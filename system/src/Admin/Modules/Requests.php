<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Requests as Inbox;
use Kaleta\Core\Response;

/**
 * Requests to Claude (2.15, Core\Requests): staff with this section write what they need changed on the site; Claude reads
 * the requests over MCP, works as drafts and leaves notes; the requester reads the notes and replies here. Every user with
 * the section sees the whole inbox – it is the team's list of work, not private mail.
 */
final class Requests extends Module
{
    public const string IDENT = 'requests';
    public const string HUB = 'claude';
    public const string NAME = 'Ask Claude';
    public const string GROUP = 'Claude';
    public const string ICON = 'komentare';

    protected function actionList(): Response
    {
        $status = $this->request->get('status');
        $status = isset(Inbox::STATUSES[$status]) ? $status : '';

        return $this->view('list', 'Ask Claude', ['requests' => Inbox::all($this->app, $status, 300), 'status' => $status,
            'open' => (int) $this->db->value("SELECT COUNT(*) FROM {requests} WHERE status IN ('new', 'in_progress')"),
            'claudeOn' => \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'claude')]);
    }

    protected function actionNew(): Response
    {
        return $this->view('new', 'New request', [
            'pages' => $this->db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY ' . $this->db->sortText('titulek')),
            'news' => \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky') ? $this->db->pairs('SELECT idc, titulek FROM {novinky} WHERE smazano IS NULL ORDER BY datum DESC LIMIT 100') : [],
            'items' => $this->db->pairs('SELECT p.idp, CONCAT(k.nazev, \' – \', p.nazev) FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NULL ORDER BY ' . $this->db->sortText('k.nazev') . ', ' . $this->db->sortText('p.nazev') . ' LIMIT 300'),
            'maxAttachments' => Inbox::MAX_ATTACHMENTS, 'limit' => \Kaleta\Core\Files::limitText(),
        ]);
    }

    /** Saves a new request; its attachments go to Media first (the same path as the Media screen), so Claude can use them. */
    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        // "Ask Claude" on the dashboard (3.1) sends only the text: the title is its first sentence, and the person goes
        // back to the dashboard, where the request is listed under their requests
        $fromDashboard = $this->request->post('from') === 'dashboard';
        $title = $this->request->post('quick') === '1' && trim($this->request->post('title')) === ''
            ? \Kaleta\Core\AskClaude::title($this->request->post('text')) : $this->request->post('title');
        if (trim($title) === '' || trim($this->request->post('text')) === '') {
            // checked before the uploads, so a request sent back for its text leaves no stray files in Media
            $message = trim($this->request->post('text')) === '' ? 'Write what should change.' : 'Give the request a title.';

            return $fromDashboard ? $this->toDashboard($message, 'chyba') : $this->back($message, 'new', [], 'chyba');
        }
        $attachments = [];
        foreach (Media::uploadedFiles('prilohy', Inbox::MAX_ATTACHMENTS) as $file) {
            try {
                $attachments[] = (int) Media::store($this->app, $file)['ido'];
            } catch (\RuntimeException $e) {
                $message = t('The attachment %s could not be saved: %s', (string) ($file['name'] ?? ''), t($e->getMessage()));

                return $fromDashboard ? $this->toDashboard($message, 'chyba') : $this->back($message, 'new', [], 'chyba');
            }
        }
        $about = $this->request->post('about_url') !== '' ? $this->request->post('about_url') : $this->request->post('about');
        try {
            $id = Inbox::create($this->app, $this->app->auth()->id(), $title, $this->request->post('text'), $about, $attachments);
        } catch (\DomainException $e) {
            return $fromDashboard ? $this->toDashboard($e->getMessage(), 'chyba') : $this->back($e->getMessage(), 'new', [], 'chyba');
        }
        // nothing is "sent" while no Claude connection has ever called the site (3.5): the request waits for it
        $connected = \Kaleta\Core\AskClaude::connected($this->db, $this->app->settings());
        if ($fromDashboard) {
            return $this->toDashboard($connected ? t('Sent to Claude as request #%d. Claude does it as drafts the next time it works on the site; its notes appear in the request.', $id)
                : t('Saved as request #%d – Claude picks it up once it is connected to the site.', $id));
        }

        return $this->back($connected ? 'The request is saved. Claude will see it the next time it works on the site; you will read its notes here.'
            : 'The request is saved – Claude picks it up once it is connected to the site; you will read its notes here.', 'detail', ['id' => $id]);
    }

    private function toDashboard(string $message, string $type = 'ok'): Response
    {
        $this->app->session->flash($type, $message);

        return Response::redirect($this->app->url('admin.php'));
    }

    protected function actionDetail(): Response
    {
        $request = Inbox::get($this->app, $this->request->getInt('id'));
        if ($request === null) {
            return $this->error('The request does not exist.', 404);
        }

        return $this->view('detail', t('Request #%d', (int) $request['id']), [
            'r' => $request, 'about' => Inbox::describeAbout($this->app, (string) $request['about']), 'attachments' => Inbox::attachments($this->app, $request['attachments']),
            'messages' => Inbox::messages($this->db, (int) $request['id']), 'mine' => (int) $request['author_id'] === $this->app->auth()->id(),
        ]);
    }

    /** The person's reply to Claude's notes. */
    protected function actionReply(): Response
    {
        $id = $this->request->postInt('id');
        $request = $this->request->isPost() ? Inbox::get($this->app, $id) : null;
        if ($request === null) {
            return $this->back();
        }
        $user = $this->app->auth()->user();
        if (!Inbox::addMessage($this->app, $id, 'person', (string) (($user['jmeno'] ?? '') !== '' ? $user['jmeno'] : ($user['user'] ?? '')), $this->request->post('text'))) {
            return $this->back('Write the reply first.', 'detail', ['id' => $id], 'chyba');
        }

        return $this->back('The reply is added – Claude reads it with the request.', 'detail', ['id' => $id]);
    }

    /** A person changes the status: closes a request, declines it, or reopens a done one. */
    protected function actionStatus(): Response
    {
        $id = $this->request->postInt('id');
        $request = $this->request->isPost() ? Inbox::get($this->app, $id) : null;
        if ($request === null) {
            return $this->back();
        }
        // the requester closing their own request needs no e-mail about it
        if (!Inbox::setStatus($this->app, $request, $this->request->post('status'), '', (int) $request['author_id'] !== $this->app->auth()->id())) {
            return $this->back('This status change is not possible.', 'detail', ['id' => $id], 'chyba');
        }

        return $this->back('The status is saved.', 'detail', ['id' => $id]);
    }
}
