<?php

declare(strict_types=1);

/* ---------- 2.17: undo a whole Claude session (Core\AgentJournal) ---------- */
check('2.17 AgentJournal: content tables are journaled, logs and security tables are not', [Kaleta\Core\AgentJournal::journaled('stranky'), Kaleta\Core\AgentJournal::journaled('nastaveni'),
    Kaleta\Core\AgentJournal::journaled('protokol'), Kaleta\Core\AgentJournal::journaled('api_tokeny'), Kaleta\Core\AgentJournal::journaled('agent_journal'), Kaleta\Core\AgentJournal::journaled('whistleblowing_cases')],
    [true, true, false, false, false, false]);
check('2.17 AgentJournal::same – rows compare by value (the database gives strings, JSON numbers), a missing row only equals a missing row', [
    Kaleta\Core\AgentJournal::same(['ids' => '5', 'titulek' => 'A', 'x' => null], ['ids' => 5, 'titulek' => 'A', 'x' => null]), Kaleta\Core\AgentJournal::same(['ids' => '5'], ['ids' => '6']),
    Kaleta\Core\AgentJournal::same(null, null), Kaleta\Core\AgentJournal::same(null, ['ids' => 1]), Kaleta\Core\Scheduler::JOBS['agent_journal'][0]],
    [true, false, true, false, 86400]);
