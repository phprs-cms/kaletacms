<?php

declare(strict_types=1);

use Kaleta\Core\Notebook;

/* ---------- 3.39 B (N110): a note from a drafts-only connection is a proposal ---------- */

$n110Row = new ReflectionMethod(Notebook::class, 'row');
$n110Base = ['id' => '4', 'topic' => 'decisions', 'title' => 'T', 'text' => 'X', 'pinned' => '0', 'author' => 'Claude', 'created_at' => '2026-11-12 10:00:00', 'updated_at' => '2026-11-12 10:00:00'];
check('3.39 N110: a note keeps the connection it came through; drafts = a proposal, a full connection or a person (also a row from before 3.39) = not', [
    array_intersect_key($n110Row->invoke(null, $n110Base + ['via' => 'Claude drafts', 'access' => 'drafts']), ['via' => 1, 'access' => 1, 'proposal' => 1]),
    $n110Row->invoke(null, $n110Base + ['via' => 'Claude', 'access' => 'full'])['proposal'],
    array_intersect_key($n110Row->invoke(null, $n110Base), ['via' => 1, 'access' => 1, 'proposal' => 1]),
], [['via' => 'Claude drafts', 'access' => 'drafts', 'proposal' => true], false, ['via' => '', 'access' => '', 'proposal' => false]]);
