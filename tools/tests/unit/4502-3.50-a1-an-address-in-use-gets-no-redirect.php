<?php

declare(strict_types=1);

/* ---------- 3.50 A1 (N125): every importer asks WpImport::redirectRefusal before it adds a redirect ---------- */
use Kaleta\Core\WpImport as A350Wp;

// the answers that need no database (an address that is not literal is refused before anything is looked up); the live
// page, collection item, /en/ page and existing redirect are smoke 4502 – they need the site's tables
$a350Db = (new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(); // never reached: refused first
$a350Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
check('3.50 N125: a pattern rule, a control character, a foreign query or an address over 255 characters is refused as not_literal, before the database', [
    A350Wp::redirectRefusal($a350Db, $a350Settings, 'shop/*', 'shop'), A350Wp::redirectRefusal($a350Db, $a350Settings, "a\u{202E}b", 'ab'),
    A350Wp::redirectRefusal($a350Db, $a350Settings, '?q=1', 'x'), A350Wp::redirectRefusal($a350Db, $a350Settings, str_repeat('a', 256), 'x'),
    A350Wp::redirectRefusal($a350Db, $a350Settings, "\xC3\x28", 'x')],
    ['not_literal', 'not_literal', 'not_literal', 'not_literal', 'not_literal']);
check('3.50 N125: shownPath lists a refused address readably – valid text as it is, invalid UTF-8 replaced', [A350Wp::shownPath('kontakt/čeština'), mb_check_encoding(A350Wp::shownPath("bad\xC3\x28"), 'UTF-8')], ['kontakt/čeština', true]);
// the importers share it: the website import, the structured exports (Blogger, Ghost…) and WordPress have no refusal of their own
$a350Calls = array_map(fn (string $class): bool => in_array('redirectRefusal', array_column(array_filter((new ReflectionClass(A350Wp::class))->getMethods(), fn (ReflectionMethod $m): bool => $m->isPublic() && $m->isStatic()), 'name'), true) && class_exists($class),
    [Kaleta\Core\WebImport::class, Kaleta\Import\Batch::class]);
check('3.50 N125: redirectRefusal is public and static, so Core\WebImport and Import\Batch can ask it', $a350Calls, [true, true]);
