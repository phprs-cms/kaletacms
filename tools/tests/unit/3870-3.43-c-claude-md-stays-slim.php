<?php

declare(strict_types=1);

/* ---------- 3.43 C: CLAUDE.md is loaded into every agent session – it holds the binding rules only, at most 16 KB ---------- */

$c343Claude = (string) file_get_contents(KALETA_ROOT . '/CLAUDE.md');
check('3.43 C: CLAUDE.md is at most 16 384 bytes and points to the docs that took the feature notes and the release history', [
    strlen($c343Claude) <= 16384,
    array_values(array_filter(['docs/ARCHITECTURE.md', 'docs/FEATURES.md', 'docs/CHANGELOG.md', 'docs/ROADMAP.md'],
        fn (string $doc): bool => !str_contains($c343Claude, $doc) || !is_file(KALETA_ROOT . '/' . $doc))),
], [true, []]);
