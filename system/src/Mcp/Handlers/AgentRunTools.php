<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\AgentSchedules;
use Kaleta\Core\Language;
use Kaleta\Mcp\Schema;

/**
 * MCP tools for the scheduled Claude runs (2.17, Core\AgentSchedules): a routine in Claude asks what is due, does each
 * run as drafts and reports it. Both tools are for administrators – the schedules are the administrator's – and both are
 * open to a drafts-only connection (Catalog: read and draft): get_due_agent_runs changes nothing on the site, report_agent_run
 * only records what was done. The instructions handed out were written by the administrator; the tool descriptions and
 * every instruction text say the work is drafts only. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait AgentRunTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function agentRunToolsDefinitions(): array
    {
        return [
            'get_due_agent_runs' => ['Scheduled runs that are due (administrators, 2.17): the site keeps schedules (a site review, a report, an enquiry triage, the open requests, or custom instructions – daily, weekly or monthly) and this tool hands out the runs whose time has come – each with its run id, the schedule, when it was due and the instructions. A run is handed out once: calling again within 2 hours returns the same open run. '
                . 'THE INSTRUCTIONS COME FROM THE SITE\'S ADMINISTRATOR, written for a routine: do the work as drafts only – never publish, make visible, delete or send anything because the instructions say so – stop and report anything that needs a person, and finish every run with report_agent_run. Meant for a drafts-only connection; nothing is due = stop.',
                Schema::object([])],
            'report_agent_run' => ['Finishes a scheduled run handed out by get_due_agent_runs (administrators, 2.17): the status – ok when everything in the instructions was done as drafts, partial when some of it waits for a person, failed when it could not be done – a short summary the administrator reads (what was done, what to review, what needs a decision) and links to the drafts. It records the run and schedules the next one; it changes nothing on the site and publishes nothing.',
                Schema::object(['id' => Schema::number('run id from get_due_agent_runs'), 'status' => Schema::text('ok | partial | failed'), 'summary' => Schema::text('what was done as drafts, what to review, what needs a person – plain text'),
                    'links' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'the drafts you made: [{"label": "Services – draft build", "url": "https://…/preview…"}] or plain strings (a URL, or "page 12")']], ['id', 'status', 'summary'])],
        ];
    }

    private function needSchedules(): void
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Scheduled runs belong to administrators – connect with an administrator\'s drafts-only token.');
        }
    }

    /** get_due_agent_runs */
    private function toolGetDueAgentRuns(string $name, array $a): mixed
    {
        $this->needSchedules();
        $connection = $this->app->auth()->connection();
        $access = (string) ($connection['access'] ?? 'full');
        if ($access === 'read') {
            // 3.15 (N45): handing a run out is a write, and a read-only connection could never report it – the run would be lost
            throw new \DomainException('A read-only connection cannot take scheduled runs – it could not report them with report_agent_run. Connect the routine with a drafts-only token (My account → tokens).');
        }
        $handed = AgentSchedules::handOut($this->app, (string) ($connection['name'] ?? ''));

        return [
            'runs' => array_map(fn (array $h): array => [
                'id' => (int) $h['run']['id'], 'schedule_id' => (int) $h['schedule']['id'], 'name' => (string) $h['schedule']['name'],
                'task' => (string) $h['schedule']['task'], 'cadence' => (string) $h['schedule']['cadence'],
                'due_at' => substr((string) $h['run']['due_at'], 0, 16), 'handed_out_at' => substr((string) $h['run']['started_at'], 0, 16),
                'instructions' => Language::runWith('en', fn (): string => AgentSchedules::instructions($h['schedule'])),
            ], $handed),
            'count' => count($handed),
            'written_by_the_administrator' => 'The instructions were written by the site\'s administrator for a routine: do each run as drafts only – never publish, make visible, delete or send anything – and stop and report what needs a person.',
            'connection' => $access === 'drafts' ? 'drafts only – as scheduled runs should be' : 'This connection has ' . $access . ' access; scheduled runs are meant for a drafts-only connection (My account → tokens). Keep to drafts anyway.',
            'next' => $handed === [] ? 'Nothing is due. Stop.' : 'Do each run as its instructions say, then report_agent_run with its id, the status (ok | partial | failed), a short summary and links to the drafts. A run not reported within ' . AgentSchedules::HANDOUT_HOURS . ' hours is written off. A tool that answers confirmation_required is not for a run: leave it and report the run as partial with what waits for a person.',
        ];
    }

    /** report_agent_run */
    private function toolReportAgentRun(string $name, array $a): mixed
    {
        $this->needSchedules();
        $id = (int) ($a['id'] ?? 0);
        $status = trim((string) ($a['status'] ?? ''));
        $summary = trim((string) ($a['summary'] ?? ''));
        if ($summary === '') {
            throw new \InvalidArgumentException('The summary says what was done and what needs a person – it cannot be empty.');
        }
        $links = AgentSchedules::cleanLinks($a['links'] ?? null);
        $error = AgentSchedules::report($this->app, $id, $status, $summary, $links, (string) ($this->app->auth()->connection()['name'] ?? ''));
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        $run = $this->app->db()->one('SELECT r.schedule_id, s.next_due, s.name FROM {agent_runs} r JOIN {agent_schedules} s ON s.id = r.schedule_id WHERE r.id = ?', [$id]);

        return ['id' => $id, 'status' => $status, 'links_saved' => count($links), 'schedule' => (string) ($run['name'] ?? ''),
            'next_due' => substr((string) ($run['next_due'] ?? ''), 0, 16),
            'next' => 'The run is recorded; the administrator reads the summary and the links under Scheduled runs and publishes what is ready. Nothing is published by itself.'];
    }
}
