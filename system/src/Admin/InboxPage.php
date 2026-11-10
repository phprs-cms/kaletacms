<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Inbox;
use Kaleta\Core\Response;

/**
 * Notifications (3.26, Core\Inbox): the screen behind the bell in the header bar, admin.php?action=inbox – like My account
 * it belongs to every signed-in user and is no item of the left menu (no section, no permission, no menu group).
 *
 *  - GET: the person's notifications, newest first (Inbox::PAGE per page, `page`).
 *  - GET `open=<id>`: marks that notification read and goes to its place (an enquiry, a page, an item); a place that is
 *    gone leaves the person on this screen with a message. Only the person's own notification – another id is "not found".
 *  - POST `read_all`: everything read.
 *
 * Reading and marking notifications never changes the site: the action is in Kernel::SITE_NEUTRAL_ACTIONS[''].
 */
final class InboxPage
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(): Response
    {
        $app = $this->kernel->app;
        $r = $app->request;
        $db = $app->db();
        $userId = $app->auth()->id();
        $self = 'admin.php?action=inbox';

        if ($r->isPost()) {
            if ($r->post('read_all') !== '') {
                Inbox::markAllRead($db, $userId);
                $app->session->flash('ok', 'All notifications are marked as read.');
            }

            return Response::redirect($app->url($self));
        }
        if ($r->getInt('open') > 0) {
            $note = Inbox::one($db, $userId, $r->getInt('open'));
            if ($note === null) {
                $app->session->flash('chyba', 'This notification no longer exists – notifications are kept for 90 days.');

                return Response::redirect($app->url($self));
            }
            Inbox::markRead($db, $userId, $note['id']);
            if ($note['place'] === null) {
                $app->session->flash('info', 'What this notification was about is no longer available.');

                return Response::redirect($app->url($self));
            }

            return Response::redirect($app->url((string) $note['place']['path']));
        }
        $page = max(1, $r->getInt('page', 1));
        $list = Inbox::list($db, $userId, $page);

        return $this->kernel->page('Notifications', $app->view->render('admin/inbox', [
            'app' => $app,
            'rows' => $list['rows'],
            'more' => $list['more'],
            'page' => $page,
            'unread' => Inbox::unreadCount($db, $userId),
            'modules' => $this->kernel->modules(), // 3.32: a record is named (who, what) only for a section the person may open
            'email' => Inbox::emailMode((string) ($app->auth()->user()['notify_email'] ?? '')),
            'csrf' => $app->session->csrfField(),
        ]));
    }
}
