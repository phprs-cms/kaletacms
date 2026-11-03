<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\ReportArchive;

/**
 * The archive of the monthly reports over MCP (3.16, Core\ReportArchive): which months are stored and one month's data –
 * the same JSON Settings → Reports prints. Administrators only, like the Reports tab. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait ReportTools
{
    /** list_monthly_reports */
    private function toolListMonthlyReports(string $name, array $a): mixed
    {
        $this->onlyAdministrators();
        $months = ReportArchive::months($this->app->db());

        return [
            'reports' => array_map(fn (array $m): array => array_filter(['month' => $m['month'], 'created_at' => $m['created_at'], 'rebuilt_at' => $m['rebuilt_at']], fn ($v): bool => $v !== null), $months),
            'kept_months' => ReportArchive::KEEP_MONTHS,
            'next' => $months === [] ? 'No report is stored yet: the previous month is stored within an hour once a month has turned; nothing exists before Kaleta 3.16.'
                : 'get_monthly_report with a month gives its data and the printable page (print_url) to share with the user.',
        ];
    }

    /** get_monthly_report */
    private function toolGetMonthlyReport(string $name, array $a): mixed
    {
        $this->onlyAdministrators();
        $month = trim((string) ($a['month'] ?? ''));
        if (preg_match(ReportArchive::MONTH_PATTERN, $month) !== 1) {
            throw new \InvalidArgumentException('month must be YYYY-MM (list_monthly_reports lists the stored months).');
        }
        $stored = ReportArchive::get($this->app->db(), $month) ?? throw new \InvalidArgumentException('No report is stored for ' . $month . ' – list_monthly_reports lists the stored months.');

        return array_filter([
            'month' => $stored['month'], 'created_at' => $stored['created_at'], 'rebuilt_at' => $stored['rebuilt_at'], 'schema_version' => $stored['schema_version'],
            'print_url' => $this->app->request->origin() . $this->app->url('admin.php?module=settings&action=report_view&month=' . $stored['month']),
            'report' => \Kaleta\Fleet\Uptime::fill($this->app->settings(), $stored['data']), // 3.26.1: uptime added, also to older months
            // 3.26.1: what it took to run the site, as the report's "What it took to run the site" counts it (Core\ReportSummary::care)
            'care' => \Kaleta\Core\ReportSummary::care($stored['data']),
            // 3.29.1 (additive): the month or the one it is compared with reaches across the switch to browser-confirmed counting
            'counting_changed' => ($since = \Kaleta\Core\ReportSummary::countingChanged(is_array($stored['data']['stats'] ?? null) ? $stored['data']['stats'] : null)) !== null
                ? ['on' => $since, 'note' => 'From ' . $since . ' only visits confirmed by a visitor\'s browser are counted; earlier days include some bots, link previews and the team\'s own visits. A drop of visits or page views against the month before is fewer bots, not fewer people – do not report it as a decline.'] : null,
        ], fn ($v): bool => $v !== null);
    }

    private function onlyAdministrators(): void
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The monthly reports are read by an administrator (Settings → Reports).');
        }
    }
}
