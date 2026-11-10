<?php

declare(strict_types=1);

/* ---------- 3.13: the release audit (N313) ---------- */
$tooLong313 = '';
try { Kaleta\Mcp\Prompts::get('migrate_site', ['old_url' => str_repeat('a', Kaleta\Mcp\Prompts::MAX_ARGUMENT + 1)]); } catch (InvalidArgumentException $e) { $tooLong313 = $e->getMessage(); }
check('3.13 N313-3: a prompt argument longer than MAX_ARGUMENT is refused', str_contains($tooLong313, 'at most'), true);
