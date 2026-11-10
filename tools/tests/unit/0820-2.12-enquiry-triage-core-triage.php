<?php

declare(strict_types=1);

/* ---------- 2.12: enquiry triage (Core\Triage) ---------- */
use Kaleta\Core\Triage;

check('2.12 Triage::clean – a known kind and priority (number or word), a plain-text reply; anything else is left unchanged', [
    Triage::clean('sales', 3, "Dobrý den,\r\n<b>děkujeme</b>."), Triage::clean('hack', 9, null), Triage::clean('spam', 'low', str_repeat('x', 6000))['navrh_odpovedi'] !== null ? mb_strlen((string) Triage::clean('spam', 'low', str_repeat('x', 6000))['navrh_odpovedi']) : 0,
    Triage::clean(null, 'high', null)['priorita']],
    [['kategorie' => 'sales', 'priorita' => 3, 'navrh_odpovedi' => "Dobrý den,\nděkujeme."], ['kategorie' => null, 'priorita' => null, 'navrh_odpovedi' => null], 5000, 3]);
check('2.12 Triage::rule – an application from a job opening is a job; anything else is left to Claude or the assistant', [Triage::rule(['zdroj' => 'kolekce:7'], ['kolekce:7']), Triage::rule(['zdroj' => 'stranka:3'], ['kolekce:7'])],
    [['kategorie' => 'job', 'priorita' => 2, 'navrh_odpovedi' => null], null]);
check('2.12 Triage::text – the form, the page, what it was about and every field; never the attachment path', Triage::text(['formular' => 'Kontakt', 'stranka' => '/kontakt', 'tema' => 'Služby – Koupelny',
    'data' => json_encode([['Jméno', 'Eva'], ['Životopis', 'cv.pdf (20 kB)', '2026/10/abc.pdf']])]), "Form: Kontakt\nPage: /kontakt\nAbout: Služby – Koupelny\nJméno: Eva\nŽivotopis: cv.pdf (20 kB)");
check('2.12 Triage: the background job is known and a machine never overwrites a person', [Kaleta\Core\Scheduler::JOBS['triage'][0], in_array('claude', Triage::MACHINES, true), in_array('Jana', Triage::MACHINES, true)], [300, true, false]);

