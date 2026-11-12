<?php

declare(strict_types=1);

/* ---------- 3.14: normalised redirect sources (Core\RedirectSources) ---------- */
check('3.14 RedirectSources::normalize – the stored form in lower case: slashes, percent-encoding and case folded, accents and the language prefix kept', array_map(Kaleta\Core\RedirectSources::normalize(...),
    ['/Stara-Adresa/', 'ko%C4%8Dka', 'EN/Stary', '/?p=12', 'Café', 'https://old.example.com/Blog/Post/', 'blog/*']),
    ['stara-adresa', 'kočka', 'en/stary', '?p=12', 'café', 'blog/post', 'blog/*']);
check('3.14 the background job that normalises redirect sources is listed', [Kaleta\Core\Scheduler::JOBS['redirect_sources'][0] ?? null, Kaleta\Core\Scheduler::JOBS['redirect_sources'][1] ?? null], [0, 'any']);
