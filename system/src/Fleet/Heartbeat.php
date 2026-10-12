<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Audit;
use Kaleta\Core\Backup;
use Kaleta\Core\Events;
use Kaleta\Core\Extensions;
use Kaleta\Core\Health;
use Kaleta\Core\Language;
use Kaleta\Core\Scheduler;
use Kaleta\Core\Updater;

/**
 * What a site tells its console (2.9): version, health, background jobs, backups, updates, enquiries waiting for an answer,
 * visits, the site audit and errors. Counts and states only – never names, e-mail addresses or the content of enquiries.
 * Texts are English (the console translates its own screens, not the site's sentences).
 */
final class Heartbeat
{
    /** @return array<string, mixed> */
    public static function build(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $checks = Language::runWith('en', fn (): array => Health::checks($app));
        $problems = array_values(array_map(fn (array $c): array => ['group' => $c['skupina'], 'check' => $c['nazev'], 'status' => $c['stav'] === 'chyba' ? 'error' : 'warning',
            'detail' => mb_substr(trim(strip_tags((string) $c['info'])), 0, 200)], array_filter($checks, fn (array $c): bool => $c['stav'] !== 'ok')));
        $backup = Backup::listAll()[0] ?? null;
        $update = (new Updater($s))->state();
        $rolledBack = $db->one("SELECT created_at, message FROM {events} WHERE type IN ('update.rolled_back', 'update.failed') ORDER BY id DESC LIMIT 1");
        $audit = [];
        try {
            foreach (Language::runWith('en', fn (): array => (new Audit($app))->run()) as $finding) {
                $audit[$finding['kind']] = ($audit[$finding['kind']] ?? 0) + 1;
            }
        } catch (\Throwable) {
            $audit = null; // the audit must never stop the heartbeat
        }

        return [
            'name' => $s->get('site_name'),
            'url' => rtrim($s->get('site_url'), '/'),
            'version' => KALETA_VERSION,
            'php' => PHP_VERSION,
            'db_version' => $s->int('db_version'),
            'status' => ['ok' => 'ok', 'varovani' => 'warning', 'chyba' => 'error'][Health::summary($checks)],
            'problems' => array_slice($problems, 0, 20),
            'jobs_failing' => array_values(array_map(fn (array $j): string => $j['name'], array_filter(Scheduler::overview($db, $s), fn (array $j): bool => $j['failures'] > 0))),
            'cron_last_run' => $s->int('tasks_last_run') ?: null,
            'last_backup' => $backup !== null ? (int) $backup['cas'] : null,
            'offsite_backup' => $s->get('remote_backup') !== 'vypnuto',
            'update_available' => $update['nova']['verze'] ?? null,
            'update_problem' => $rolledBack !== null && strtotime((string) $rolledBack['created_at']) > time() - 14 * 86400 ? mb_substr((string) $rolledBack['message'], 0, 200) : null,
            'auto_updates' => $s->bool('auto_updates'),
            'update_channel' => $update['channel'], // 3.8 (D3): latest | stable | custom
            'enquiries_unanswered' => Extensions::isEnabled($s, 'poptavky') ? (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE stav = 0') : null,
            'enquiries_7_days' => Extensions::isEnabled($s, 'poptavky') ? (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE datum > ?', [\Kaleta\Core\Clock::at('-7 days')]) : null,
            'visits_7_days' => \Kaleta\Front\Stats::enabled($s) ? (int) $db->value('SELECT COALESCE(SUM(navstevy), 0) FROM {stat_dny} WHERE den > ?', [\Kaleta\Core\Clock::day('-7 days')]) : null,
            'audit' => $audit,
            'problems_7_days' => Events::problems($db, 168),
            'claude' => Extensions::isEnabled($s, 'claude'),
            'kit_version' => $s->int('fleet_kit_version') ?: null, // the shared design kit this site applied (2.16, Fleet\Kit)
        ];
    }
}
