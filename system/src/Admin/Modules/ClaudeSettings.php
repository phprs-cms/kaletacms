<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\Response;

/**
 * Claude settings (3.2): how to connect, the instructions and guardrails every connection gets (formerly in Extensions –
 * the settings keys stay), and every Claude connection of the site. The home of the Claude hub (Admin\Hubs) with Ask
 * Claude, the scheduled runs, the notebook and the sessions.
 */
final class ClaudeSettings extends Settings
{
    public const string IDENT = 'claude_settings';
    public const string NAME = 'Claude settings';
    public const string GROUP = 'Claude';
    public const string ICON = 'nastaveni';
    public const string HUB = 'claude';

    protected function tab(string $tab): string
    {
        return 'claude';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        // every connector and personal token of every user, newest use first (the secrets themselves are never stored); a
        // connected application is one row per user and client – its last use is that of its access tokens, as in My account (3.11, UX310-19)
        $data['connections'] = $this->db->all("SELECT MAX(t.nazev) AS nazev, t.klient, ELT(MIN(FIELD(t.access, 'full', 'drafts', 'read')), 'full', 'drafts', 'read') AS access, MIN(t.vytvoren) AS vytvoren, MAX(t.pouzit) AS pouzit,
                MAX(IF(t.druh = 'pristup', NULL, t.expirace)) AS expirace, MAX(IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno)) AS user
            FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu WHERE t.druh IN ('token', 'obnova', 'pristup')
            GROUP BY t.idu, t.klient, IF(t.klient IS NULL, t.idt, 0) HAVING t.klient IS NULL OR SUM(t.druh = 'obnova') > 0
            ORDER BY MAX(t.pouzit) IS NULL, MAX(t.pouzit) DESC, MAX(t.idt) DESC LIMIT 200");

        // protected pages are picked from a list (3.12, UX310-19) instead of typing numbers from the editor's address
        $data['protectablePages'] = $this->db->all('SELECT ids, titulek, seo_link, jazyk FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek LIMIT 500');

        // 3.15 (N44): opening the settings is looking at the instructions – the change is shown here once, the dashboard notice goes
        if ($template === 'list' && !$this->request->isPost() && $this->app->auth()->isAdmin()) {
            $data['instructionsChange'] = \Kaleta\Core\ClaudeInstructions::notice($this->app->settings());
            \Kaleta\Core\ClaudeInstructions::seen($this->app->settings());
        }

        return parent::view($template, $heading, $data);
    }

    /** The dashboard notice about changed instructions for Claude is dismissed without opening the settings (3.15, N44). */
    protected function actionInstructionsSeen(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Core\ClaudeInstructions::seen($this->app->settings());
        }

        return Response::redirect($this->app->url('admin.php'));
    }
}
