<?php

declare(strict_types=1);

use Kaleta\Core\ImportPath;

/* ---------- 3.33 part A (M3): imports keep nested addresses ---------- */
$i333Long = str_repeat('dlouha-cast-', 5) . 'konec'; // 65 characters
check('3.33 M3 ImportPath::plan: the old path is kept with its parents; a single part, the language prefix of the version and file extensions as before', [
    ImportPath::plan('services/heating/boilers', 'boilers'), ImportPath::plan('kontakt', 'kontakt'), ImportPath::plan('', 'home'),
    ImportPath::plan('en/about/team', 'team', 'en'), ImportPath::plan('en/about', 'about'), ImportPath::plan('Služby/Topení.html/kotle', 'kotle'),
], [['services/heating/boilers', ''], ['kontakt', ''], ['home', ''], ['about/team', ''], ['about', 'flattened'], ['sluzby/topeni/kotle', '']]);
check('3.33 M3 ImportPath::plan: over 120 characters the last part is shortened; a parent path too long, or a first part the system uses, lands at the last part', [
    ImportPath::plan($i333Long . '/' . $i333Long, $i333Long), ImportPath::plan($i333Long . '/' . $i333Long . '/x', 'x'), ImportPath::plan('novinky/clanek', 'clanek'), ImportPath::plan('form/poptavka', 'poptavka'),
], [[$i333Long . '/' . substr($i333Long, 0, 120 - 66), 'shortened'], ['x', 'flattened'], ['clanek', 'flattened'], ['poptavka', 'flattened']]);
check('3.33 M3 ImportPath::plan never gives an address over the column', strlen(ImportPath::plan(str_repeat('a', 60) . '/' . str_repeat('b', 50), str_repeat('c', 110))[0]) <= ImportPath::MAX, true);
check('3.33 M3 ImportPath::keptPrefix: an orphan nested page keeps its directory when saved; a page with a parent, a flat page or a new one does not', [
    ImportPath::keptPrefix(['seo_link' => 'reference/projekt-a', 'nadrazena' => null]), ImportPath::keptPrefix(['seo_link' => 'reference/projekt-a', 'nadrazena' => 5]),
    ImportPath::keptPrefix(['seo_link' => 'kontakt', 'nadrazena' => null]), ImportPath::keptPrefix(null), ImportPath::keptPrefix(['seo_link' => 'a/b/c', 'nadrazena' => null]),
], ['reference/', '', '', '', 'a/b/']);
check('3.33 M3 ImportPath::summary: what could not be kept, in English', array_column(ImportPath::summary([['/a/b', '/b', 'flattened'], ['/x/y', '/x/y-short', 'shortened'], 'junk']), 'old'), ['/a/b', '/x/y']);
