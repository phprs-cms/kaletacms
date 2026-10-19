<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Whistleblowing as Channel;

/**
 * Whistleblowing channel (2.14, Core\Whistleblowing). Three levels of access, on purpose:
 *  - administrators set the channel up (on/off, the readers, the introduction, the retention) and see the list of cases –
 *    numbers, dates, statuses and deadlines, never the content;
 *  - the chosen readers (administrators or other users; a non-administrator reader gets the module permission with the
 *    setup) open a case, read it, answer the reporter and change the status;
 *  - nobody else, and no MCP tool.
 */
final class Whistleblowing extends Module
{
    public const string IDENT = 'whistleblowing';
    public const string NAME = 'Whistleblowing';
    public const string GROUP = 'Administration';
    public const string ICON = 'stit';
    public const string EXTENSION = 'whistleblowing'; // a feature (3.2): off on new installs, switched on by 0073 where a site uses it

    /** Only the appointed readers and administrators: an editor with every section never meets a 403 here (3.1.1). */
    public static function availableTo(App $app): bool
    {
        return $app->auth()->isAdmin() || Channel::isReader($app);
    }

    /** 3.15: reports received and not acknowledged yet – the law sets a deadline for the acknowledgement. */
    public static function badge(App $app): int
    {
        return (int) $app->db()->value("SELECT COUNT(*) FROM {whistleblowing_cases} WHERE status = 'received'");
    }

    protected function actionList(): Response
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !Channel::isReader($this->app)) {
            return $this->error('Only the appointed readers and administrators see the whistleblowing channel.', 403);
        }
        $cases = $this->db->all('SELECT id, number, created_at, status, acknowledged_at, feedback_due, closed_at, flood FROM {whistleblowing_cases} ORDER BY id DESC LIMIT 500');
        foreach ($cases as &$case) {
            $case['overdue'] = Channel::overdue($case);
            $case['acknowledge_by'] = Channel::deadlines((string) $case['created_at'])['acknowledge_by'];
        }
        unset($case);

        return $this->view('list', 'Whistleblowing', [
            'cases' => $cases, 'isReader' => Channel::isReader($this->app), 'isAdmin' => $auth->isAdmin(),
            'on' => Channel::isOn($this->app->settings()), 'readerIds' => Channel::readerIds($this->app->settings()),
            'users' => $auth->isAdmin() ? $this->db->all("SELECT idu, user, jmeno, email, admin FROM {uzivatele} WHERE blokovat = 0 ORDER BY admin DESC, " . $this->db->sortText('jmeno') . ', user') : [],
            'intro' => $this->app->settings()->get('whistleblowing_intro'),
            'retention' => $this->app->settings()->int('whistleblowing_retention_months') ?: Channel::DEFAULT_RETENTION_MONTHS,
            'publicUrl' => rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . $this->app->url('_report'),
        ]);
    }

    /** The setup – administrators only. */
    protected function actionSettings(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back();
        }
        $readers = array_map('intval', array_filter($this->request->postList('readers'), 'is_numeric'));
        Channel::saveSetup($this->app, $this->request->postBool('enabled'), $readers, $this->request->post('intro'), $this->request->postInt('retention', Channel::DEFAULT_RETENTION_MONTHS));

        return $this->back($readers === [] && $this->request->postBool('enabled') ? 'Saved. Choose at least one reader – until then nobody can open the reports.' : 'Saved.');
    }

    /** @return array<string, mixed>|Response the case, or the refusal */
    private function readableCase(int $id): array|Response
    {
        if (!Channel::isReader($this->app)) {
            // an administrator who is not a reader is refused too: the list of readers is the whole point
            return $this->error('Only the appointed readers open reports. An administrator can add readers in the setup of the channel.', 403);
        }
        $case = $this->db->one('SELECT * FROM {whistleblowing_cases} WHERE id = ?', [$id]);

        return $case ?? $this->error('The case does not exist.', 404);
    }

    protected function actionDetail(): Response
    {
        $case = $this->readableCase($this->request->getInt('id'));
        if ($case instanceof Response) {
            return $case;
        }

        return $this->view('detail', t('Case %s', (string) $case['number']), [
            'case' => $case, 'contents' => Channel::contents($this->app->settings(), $case), 'messages' => Channel::messages($this->app, (int) $case['id']),
            'overdue' => Channel::overdue($case), 'deadlines' => Channel::deadlines((string) $case['created_at']),
        ]);
    }

    /** The handler's answer to the reporter; the first one acknowledges the receipt. */
    protected function actionReply(): Response
    {
        $case = $this->readableCase($this->request->postInt('id'));
        if ($case instanceof Response) {
            return $case;
        }
        if (!$this->request->isPost() || !Channel::addMessage($this->app, (int) $case['id'], 'handler', $this->request->post('text'))) {
            return $this->back('Write the message first.', 'detail', ['id' => (int) $case['id']], 'chyba');
        }

        return $this->back('The message was added – the reporter sees it after opening the case with the code.', 'detail', ['id' => (int) $case['id']]);
    }

    protected function actionStatus(): Response
    {
        $case = $this->readableCase($this->request->postInt('id'));
        if ($case instanceof Response) {
            return $case;
        }
        if ($this->request->isPost() && Channel::setStatus($this->app, (int) $case['id'], $this->request->post('status'))) {
            return $this->back('The status is saved.', 'detail', ['id' => (int) $case['id']]);
        }

        return $this->back('', 'detail', ['id' => (int) $case['id']]);
    }

    protected function actionAttachment(): Response
    {
        $case = $this->readableCase($this->request->getInt('id'));
        if ($case instanceof Response) {
            return $case;
        }
        $attachment = Channel::contents($this->app->settings(), $case)['attachments'][$this->request->getInt('index')] ?? null;
        $path = $attachment !== null ? Channel::attachmentPath($attachment) : null;
        if ($path === null) {
            return $this->error('The attachment no longer exists.', 404);
        }

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode((string) $attachment['name'])]);
    }
}
