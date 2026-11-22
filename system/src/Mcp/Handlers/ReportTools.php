<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\ReportArchive;
use Kaleta\Mcp\Schema;

/**
 * The archive of the monthly reports over MCP (3.16, Core\ReportArchive): which months are stored and one month's data –
 * the same JSON Settings → Reports prints. Administrators only, like the Reports tab. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait ReportTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function reportToolsDefinitions(): array
    {
        return [
            'list_monthly_reports' => ['Archived monthly reports (read-only, administrators): the months stored, when each was made or rebuilt. One a month, kept 24 months; none before 3.16.', Schema::object([])],
            'get_monthly_report' => ['One archived monthly report (read-only, administrators): what happened to the site that month – requests, changes, Claude runs, updates, backups, security, audit, traffic, enquiries, bookings, newsletter, what was open, uptime by day (with a fleet console; null without). Counts and titles, no personal data; print_url opens the printable page; care counts what it took to run the site – done automatically, by Claude and by people, and the decisions it needed (counts, never hours or money).',
                Schema::object(['month' => Schema::text('YYYY-MM from list_monthly_reports')], ['month'])],
        ];
    }

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
        ], fn ($v): bool => $v !== null) + \Kaleta\Mcp\Untrusted::mark(['report']); // 3.44 (N128): campaigns, sources and search queries come from visitors' links and searches
    }

    private function onlyAdministrators(): void
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The monthly reports are read by an administrator (Settings → Reports).');
        }
    }
}
