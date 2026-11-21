<?php

declare(strict_types=1);

/* ---------- 3.50.1 B4 (CODE-c3): the largest classes do not multiply ---------- */
// A rule over the whole of system/src, not a pin of one file. 3.50.1 splits no class (Front\Kernel, Core\Booking and
// Core\WebImport are a 3.51 preparation item); this ratchet only stops more files from growing past the two sizes.
// Measured at 3.50.1: 10 files over 1,500 lines, 21 over 1,000 – lower the numbers when one shrinks, never raise them
// (split the class instead: a new topic is a new class, as docs/design/code-layers.md says).
$u4634Src = KALETA_SYSTEM . '/src';
$u4634Over = [1500 => [], 1000 => []];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($u4634Src, FilesystemIterator::SKIP_DOTS)) as $u4634File) {
    if (!$u4634File instanceof SplFileInfo || $u4634File->getExtension() !== 'php') {
        continue;
    }
    $u4634Lines = substr_count((string) file_get_contents($u4634File->getPathname()), "\n");
    foreach (array_keys($u4634Over) as $u4634Limit) {
        if ($u4634Lines > $u4634Limit) {
            $u4634Over[$u4634Limit][] = substr($u4634File->getPathname(), strlen($u4634Src) + 1) . ' (' . $u4634Lines . ')';
        }
    }
}
check('3.50.1 B4 (CODE-c3): at most 10 files under system/src have more than 1,500 lines (measured 10 at 3.50.1 – lower when it shrinks)',
    count($u4634Over[1500]) <= 10 ? [] : $u4634Over[1500], []);
check('3.50.1 B4 (CODE-c3): at most 21 files under system/src have more than 1,000 lines (measured 21 at 3.50.1 – lower when it shrinks)',
    count($u4634Over[1000]) <= 21 ? [] : $u4634Over[1000], []);
