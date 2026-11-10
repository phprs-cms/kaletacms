<?php

declare(strict_types=1);

/* ---------- 2.15: agent notebook – topic validation ---------- */
check('2.15: Notebook::topic – a known topic in any case and with spaces around; anything else (an unknown word, empty, not a string) is refused', [
    Kaleta\Core\Notebook::topic('decisions'), Kaleta\Core\Notebook::topic(' Style '), Kaleta\Core\Notebook::topic('TODO'), Kaleta\Core\Notebook::topic('pricing'),
    Kaleta\Core\Notebook::topic(''), Kaleta\Core\Notebook::topic(null), Kaleta\Core\Notebook::topic(['style'])],
    ['decisions', 'style', 'todo', null, null, null, null]);
check('2.15: Notebook::TOPICS – the topics the admin filter, read_notebook and the export know, every one with a label', array_keys(Kaleta\Core\Notebook::TOPICS), ['decisions', 'style', 'credits', 'history', 'todo', 'other']);
