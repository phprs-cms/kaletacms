<?php

declare(strict_types=1);

/* ---------- date in words in the administration (3.2.1: the English and German admin showed Czech months) ---------- */
check('Date in words: Czech admin', format_date_long('2026-10-07'), 'středa 7. října 2026');
Kaleta\Core\Language::set('en', 'admin-');
check('Date in words: English admin', format_date_long('2026-10-07'), 'Wednesday 7 October 2026');
Kaleta\Core\Language::set('de', 'admin-');
check('Date in words: German admin', format_date_long('2026-10-07'), 'Mittwoch, 7. Oktober 2026');
Kaleta\Core\Language::set('cs', 'admin-');

