<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Quotes as QuotesModule344;

/* ---------- 3.44 B: the quote editor – moving a line without JavaScript, the name of a draft ---------- */

$b344Lines = ['a', 'b', 'c'];
check('3.44 B (U58): Up/Down without JavaScript swaps a line with its neighbour; past either end or anything else leaves the order', [
    QuotesModule344::moveLine($b344Lines, '0:down'), QuotesModule344::moveLine($b344Lines, '2:up'), QuotesModule344::moveLine($b344Lines, '0:up'),
    QuotesModule344::moveLine($b344Lines, '2:down'), QuotesModule344::moveLine($b344Lines, '9:up'), QuotesModule344::moveLine($b344Lines, '1:left'),
    QuotesModule344::moveLine($b344Lines, ''), QuotesModule344::moveLine($b344Lines, '-1:down'), QuotesModule344::moveLine([], '0:down'),
], [['b', 'a', 'c'], ['a', 'c', 'b'], $b344Lines, $b344Lines, $b344Lines, $b344Lines, $b344Lines, $b344Lines, []]);

$b344Quote = fn (?string $number, string $title, string $company = '', string $name = ''): array => ['number' => $number, 'title' => $title, 'customer' => ['company' => $company, 'name' => $name]];
check('3.44 B (U59): a draft is "Draft quote – <title, company or name>", never "#id"; a numbered quote keeps its number', [
    QuotesModule344::heading($b344Quote(null, '')), QuotesModule344::heading($b344Quote(null, 'Brackets')), QuotesModule344::heading($b344Quote(null, '', 'Acme', 'Greta')),
    QuotesModule344::heading($b344Quote(null, ' ', '', 'Greta')), QuotesModule344::heading($b344Quote('2026-0007', 'Brackets')), QuotesModule344::heading($b344Quote('2026-0008', '')),
], ['Draft quote', 'Draft quote – Brackets', 'Draft quote – Acme', 'Draft quote – Greta', '2026-0007 – Brackets', '2026-0008']);
