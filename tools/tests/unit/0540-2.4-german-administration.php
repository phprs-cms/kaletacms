<?php

declare(strict_types=1);

/* ---------- 2.4: German administration ---------- */
// every admin text translated into Czech has a German translation too, with the same placeholders and tags
$placeholders = function (string $text): array { preg_match_all('/%(?:\d+\$)?[sd]|<\/?[a-z]+/i', $text, $m); sort($m[0]); return $m[0]; };
$jsDictionary = function (string $file): array { preg_match_all('/^\t("(?:[^"\\\\]|\\\\.)*"):\s*("(?:[^"\\\\]|\\\\.)*"),?$/m', (string) file_get_contents($file), $m, PREG_SET_ORDER);
    return array_combine(array_map(fn (array $x): string => json_decode($x[1]), $m), array_map(fn (array $x): string => json_decode($x[2]), $m)); };
$deGaps = [];
foreach ([[require KALETA_SYSTEM . '/jazyky/admin-cs.php', require KALETA_SYSTEM . '/jazyky/admin-de.php'],
    [$jsDictionary(KALETA_ROOT . '/image/jazyky/admin-cs.js'), $jsDictionary(KALETA_ROOT . '/image/jazyky/admin-de.js')]] as [$csTexts, $deTexts]) {
    foreach ($csTexts as $key => $_) {
        $key = (string) $key;
        if (!isset($deTexts[$key]) && !in_array($key, ['Name'], true)) { $deGaps[] = 'missing: ' . $key; }
        elseif (isset($deTexts[$key]) && $placeholders($key) !== $placeholders((string) $deTexts[$key])) { $deGaps[] = 'placeholders: ' . $key; }
    }
}
check('2.4: German admin covers every Czech admin text', array_slice($deGaps, 0, 5), []);
check('2.4: German is an admin language', [isset(Kaleta\Core\Language::ADMIN_LANGUAGES['de']), count($jsDictionary(KALETA_ROOT . '/image/jazyky/admin-de.js')) > 2500], [true, true]);

