<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Backup;
use Kaleta\Core\Events;
use Kaleta\Core\Health;
use Kaleta\Core\Language;
use Kaleta\Core\Scheduler;

/**
 * MCP tools for a site that runs itself (2.8): its health and what happened on it. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait HealthTools
{
    /** get_health */
    private function toolGetHealth(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The health of the site is for administrators.');
        }
        $db = $this->app->db();
        $checks = Language::runWith('en', fn (): array => Health::checks($this->app), 'admin-'); // as System status in an English administration (the group names are Czech keys of the admin dictionary)
        $backup = Backup::listAll()[0] ?? null;
        $cron = $this->app->settings()->int('tasks_last_run');
        $update = (new \Kaleta\Core\Updater($this->app->settings()))->state(); // remembered from the checks above, no new request

        return [
            'status' => ['ok' => 'ok', 'varovani' => 'warning', 'chyba' => 'error'][Health::summary($checks)],
            'kaleta_version' => KALETA_VERSION,
            // 3.8 (D3): the release channel (latest | stable | custom), the version it offers, and the stable version a stable site is already past
            'update' => ['channel' => $update['channel'], 'available' => $update['nova']['verze'] ?? $update['vyzaduje_php']['verze'] ?? null, 'ahead_of_stable' => $update['ahead_of']],
            'problems' => array_values(array_map(fn (array $c): array => ['group' => $c['skupina'], 'check' => $c['nazev'], 'status' => $c['stav'] === 'chyba' ? 'error' : 'warning', 'detail' => strip_tags((string) $c['info'])],
                array_filter($checks, fn (array $c): bool => $c['stav'] !== 'ok'))),
            'jobs' => array_map(fn (array $j): array => ['job' => $j['name'], 'last_run' => $j['last_run'], 'failures_in_a_row' => $j['failures'], 'last_error' => $j['last_error'] !== '' ? $j['last_error'] : null], Scheduler::overview($db, $this->app->settings())),
            'cron_last_run_minutes' => $cron > 0 ? (int) floor((time() - $cron) / 60) : null,
            // 3.9: the mail service the site sends through (null = another SMTP server or the host's mail()) – never its credentials
            'mail_service' => \Kaleta\Core\MailServices::name(\Kaleta\Core\MailServices::current($this->app->settings())) ?: null,
            'last_backup' => $backup !== null ? date('Y-m-d H:i', (int) $backup['cas']) : null,
            'events_last_7_days' => Events::problems($db, 168),
            'next' => 'Fix what you can (e.g. with update_settings or the site audit) and tell the user what needs them: hosting, DNS or a password are theirs. list_events shows what happened.',
        ];
    }

    /** list_events */
    private function toolListEvents(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The events of the site are for administrators.');
        }
        $types = array_values(array_filter(array_map(fn ($t): string => trim((string) $t), is_array($a['types'] ?? null) ? $a['types'] : []), fn (string $t): bool => preg_match('/^[a-z_]+(\.[a-z_]*)?$/D', $t) === 1));
        $severity = in_array($a['min_severity'] ?? 'info', Events::SEVERITIES, true) ? (string) ($a['min_severity'] ?? 'info') : 'info';
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $since = (int) ($a['since_id'] ?? 0);
        $db = $this->app->db();
        if ($since <= 0 && ($a['days'] ?? null) !== null) {
            $since = (int) $db->value('SELECT COALESCE(MAX(id), 0) FROM {events} WHERE created_at < NOW() - INTERVAL ? DAY', [max(1, min(180, (int) $a['days']))]);
        }
        $events = Events::since($db, $since, $types, $limit, $severity);

        return ['events' => $events, 'next_since_id' => $events !== [] ? end($events)['id'] : $since, 'more' => count($events) === $limit,
            'types' => array_keys(Events::TYPES)];
    }
}
