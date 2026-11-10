<?php

declare(strict_types=1);

/* ---------- admin and site scripts without system dialogs (they cannot be styled or translated, and browsers suppress them) ---------- */
$nativeDialogs = [];
foreach (glob(dirname(__DIR__, 3) . '/image/*.js') ?: [] as $file) {
    $code = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//.*$#m'], ['', '$1'], (string) file_get_contents($file)); // without comments
    if (preg_match_all('/\b(alert|prompt|confirm)\s*\(/', (string) $code, $m)) {
        $nativeDialogs[] = basename($file) . ': ' . implode(', ', array_unique($m[1]));
    }
}
check('Skripty bez window.alert/prompt/confirm', $nativeDialogs, []);

