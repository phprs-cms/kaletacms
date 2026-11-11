<?php

declare(strict_types=1);

use Kaleta\Core\OldPage;

/* ---------- 3.33 review M3: read_old_page splits declarations linearly and keeps a time budget ---------- */
check('3.33 review M3: declarations split at semicolons outside parentheses, as before', [
    OldPage::declarations('color:red;background:url("a;b.png");--x:calc(1px + (2px; 3px))'), OldPage::declarations('a:1;'),
], [['color:red', 'background:url("a;b.png")', '--x:calc(1px + (2px; 3px))'], ['a:1', '']]);
$i333oBig = str_repeat('color:red;', 1500); // 15 KB of declarations: still read, in one linear pass
$i333oStart = microtime(true);
$i333oRules = OldPage::rules('.a{' . $i333oBig . '}.b{' . str_repeat('x', 30_000) . ';color:blue}.c{color:green}' . str_repeat('.d{' . str_repeat('margin:0;', 2000) . '}', 30));
check('3.33 review M3: long rule bodies are fast; a body over RULE_BYTES is skipped, the rest read', [
    microtime(true) - $i333oStart < 2.0, array_values(array_unique(array_column($i333oRules, 0))), $i333oRules[0][1]['color'] ?? null,
], [true, ['.a', '.c', '.d'], 'red']);
check('3.33 review M3: the time budget – another request fits only with the gap and room for the analysis', [
    OldPage::withinBudget(microtime(true), 20.0, 0.0), OldPage::withinBudget(microtime(true) - 17.0, 20.0, 1.0), OldPage::withinBudget(microtime(true) - 10.0, 20.0, 2.0),
], [true, false, true]);

/* ---------- 3.33 review L5: a total budget for linked files per import run (admin and MCP) ---------- */
check('3.33 review L5: the import downloads linked files until the run\'s count or size budget is used up', [
    Kaleta\Core\WebImport::filesLeft([]), Kaleta\Core\WebImport::filesLeft(['zachovano' => ['soubory' => Kaleta\Core\WebImport::FILES_PER_IMPORT - 1]]),
    Kaleta\Core\WebImport::filesLeft(['zachovano' => ['soubory' => Kaleta\Core\WebImport::FILES_PER_IMPORT]]),
    Kaleta\Core\WebImport::filesLeft(['soubory_bajty' => Kaleta\Core\WebImport::FILE_BYTES_PER_IMPORT]),
    Kaleta\Core\Language::runWith('cs', fn (): array => Kaleta\Core\WebImport::attentionLines(['pozor' => [['/%s – the file %s was not downloaded: the import reached its limit of %s linked files; it still links to the old site.', 'cenik', '/files/a.pdf', '200']]], true), 'admin-'),
], [true, true, false, false, ['/cenik – soubor /files/a.pdf se nestáhl: import dosáhl svého limitu 200 odkazovaných souborů; odkaz dál vede na starý web.']]);
