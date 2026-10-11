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
        $data['connections'] = $this->db->all("SELECT MAX(t.nazev) AS nazev, t.klient, MAX(t.access) AS access, MIN(t.vytvoren) AS vytvoren, MAX(t.pouzit) AS pouzit,
                MAX(IF(t.druh = 'pristup', NULL, t.expirace)) AS expirace, MAX(IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno)) AS user
            FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu WHERE t.druh IN ('token', 'obnova', 'pristup')
            GROUP BY t.idu, t.klient, IF(t.klient IS NULL, t.idt, 0) HAVING t.klient IS NULL OR SUM(t.druh = 'obnova') > 0
            ORDER BY MAX(t.pouzit) IS NULL, MAX(t.pouzit) DESC, MAX(t.idt) DESC LIMIT 200");

        return parent::view($template, $heading, $data);
    }
}
