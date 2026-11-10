<?php

declare(strict_types=1);

/* ---------- 3.1: "Ask Claude" on the dashboard (Core\AskClaude) ---------- */
$ask = Kaleta\Core\AskClaude::class;
check('3.1 AskClaude::title – the first sentence, whitespace folded, shortened at a word with an ellipsis; "e.g." and dates do not end a sentence; empty stays empty', [
    $ask::title("We are closed from 24 to 26 December – put it on the site."),
    $ask::title("  Update the price list.\n\nThe PDF is attached, the old one goes away. "),
    $ask::title('Write a news item, e.g. about the new service. Thanks!'),
    $ask::title('Closed on 24. 12. because of the holiday. Put it on the site.'),
    $ask::title(str_repeat('word ', 30)), mb_strlen($ask::title(str_repeat('a', 200))), $ask::title("  \n ")],
    ['We are closed from 24 to 26 December – put it on the site.', 'Update the price list.', 'Write a news item, e.g. about the new service.', 'Closed on 24. 12. because of the holiday.',
        rtrim(str_repeat('word ', 15)) . '…', 80, '']);
check('3.1 AskClaude::examples – only those whose section the person may open, at most eight, every module ident exists; the prompt carries the site address and one place for the text', [
    array_keys($ask::examples(['enquiries' => 1])), count($ask::examples(['pages' => 1, 'news' => 1, 'collections' => 1, 'enquiries' => 1, 'stats' => 1, 'audit' => 1])), $ask::examples([]),
    array_values(array_diff(array_unique(array_column($ask::EXAMPLES, 0)), array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES))),
    str_contains($ask::prompt('https://example.com/'), 'https://example.com (') && substr_count($ask::prompt('https://example.com'), '{text}') === 1],
    [['triage'], 8, [], [], true]);

