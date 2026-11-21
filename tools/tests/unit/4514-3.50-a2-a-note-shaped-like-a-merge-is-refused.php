<?php

declare(strict_types=1);

/* ---------- 3.50 A2: nobody but Kaleta writes a note shaped like a legacy merge note (N141) ---------- */
use Kaleta\Core\Contacts as A514Contacts;

check('3.50 N141: mergeNoteAddress recognises the merge note in any admin language and spacing, with any address; other texts are not one', [
    A514Contacts::mergeNoteAddress('Merged with the contact #12 (x@example.com).'),
    A514Contacts::mergeNoteAddress(Kaleta\Core\Language::runWith('cs', fn (): string => t('Merged with the contact #%d (%s).', 7, 'jana@example.com'), 'admin-')),
    A514Contacts::mergeNoteAddress("  Merged with the contact #3\u{00A0}(victim@example.org).  "),
    A514Contacts::mergeNoteAddress('Called, merged with the contact #12 (x@example.com) later.'), A514Contacts::mergeNoteAddress('Merged with the contact #12.'),
    A514Contacts::mergeNoteNames('Merged with the contact #12 (X@Example.com).', 'x@example.com'), A514Contacts::mergeNoteNames('Merged with the contact #12 (x@example.com).', 'y@example.com')],
    ['x@example.com', 'jana@example.com', 'victim@example.org', null, null, true, false]);
check('3.50 N141: the refusal of log_activity is translated (cs, de, es)', array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code,
    fn (): string => t('Only Kaleta writes the note of a merged contact – word the entry differently.'), 'admin-') !== 'Only Kaleta writes the note of a merged contact – word the entry differently.', ['cs', 'de', 'es']),
    [true, true, true]);
