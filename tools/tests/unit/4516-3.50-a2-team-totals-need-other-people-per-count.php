<?php

declare(strict_types=1);

/* ---------- 3.50 A2: a team total is shown only when enough people other than the viewer share the count (N148) ---------- */
use Kaleta\Core\TeamSummary as A516Summary;

// the viewer (1) and colleague A (2) have counted work, B (3) only has a planted task; "nobody responsible" (0) has some too
$a516Collected = [0 => ['tasks_open' => 4], 1 => ['tasks_open' => 2, 'tasks_done' => 5], 2 => ['tasks_open' => 3, 'tasks_done' => 1], 3 => ['tasks_open' => 1]];
$a516Withdrawn = [0 => ['tasks_open' => 4], 1 => ['tasks_open' => 2, 'tasks_done' => 5], 2 => ['tasks_open' => 3, 'tasks_done' => 1], 3 => ['tasks_open' => 0]];
check('3.50 N148: visibleTotals – per count, at least MIN_PEOPLE − 1 other people (never the viewer, never "nobody"); a planted task does not count; nothing left = null', [
    A516Summary::visibleTotals($a516Collected, $a516Collected, ['tasks'], 1),
    A516Summary::visibleTotals($a516Withdrawn, $a516Collected, ['tasks'], 1),
    A516Summary::visibleTotals($a516Collected, $a516Collected, ['tasks'], 3),
    A516Summary::visibleTotals([1 => ['tasks_done' => 1], 2 => ['tasks_done' => 1]], [1 => ['tasks_done' => 1], 2 => ['tasks_done' => 1]], ['tasks'], 1)],
    [['tasks_open' => 10], null, ['tasks_open' => 10, 'tasks_done' => 6], null]);
check('3.50 N148: the threshold is unchanged in size (three people with the viewer)', A516Summary::MIN_PEOPLE, 3);
