<?php

declare(strict_types=1);

/* ---------- 3.34 third review F3: a stale update probe no longer blocks the move of job applications ---------- */
// The Updater writes the time of its probe and clears both in every way out; migration 0120 waits for a probe 10 minutes at
// most (update_probe_at, or when it first saw a probe an older release's updater wrote) – tools/test-migrations.sh runs it.
$f3Updater = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/Updater.php');
$f3Move = (string) file_get_contents(KALETA_ROOT . '/system/sql/migrace/0120-applications-move.php');
check('[source-pin] 3.34 third review F3: the probe carries its time, set before the code and cleared with it on both ways out',
    [substr_count($f3Updater, "\$this->settings->set('update_probe_at', ''"), str_contains($f3Updater, "\$this->settings->set('update_probe_at', (string) time()); // 3.34 third review F3"),
        array_key_exists('update_probe_at', Kaleta\Core\Settings::DEFAULTS), array_key_exists('applications_probe_seen', Kaleta\Core\Settings::DEFAULTS)],
    [2, true, true, true]);
check('[source-pin] 3.34 third review F3: 0120 waits for a probe 600 s at most, from update_probe_at or from when it first saw the probe',
    [str_contains($f3Move, ": 600;\n        if (time() - \$probeAt < \$stale) {"), str_contains($f3Move, "promenna = 'update_probe_at'"), str_contains($f3Move, "\$settings->set('applications_probe_seen', hash('sha256', \$probe) . '|' . \$probeAt);")],
    [true, true, true]);
