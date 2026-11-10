<?php

declare(strict_types=1);

/* ---------- 2.14: EU duties as templates (Core\Privacy) ---------- */
$f17Embeds = ['{"typ":"video","obsah":{"url":"https://www.youtube.com/watch?v=abc123","titulek":""}}', '<p>Text without any embed</p>', '{"typ":"mapa","obsah":{"adresa":"Praha"}}'];
check('2.14 Privacy::providersIn – a YouTube video in a build, Analytics and Tag Manager from the settings, the CAPTCHA from its setting; a map element alone is not Google Maps (it embeds after a click)', [
    Kaleta\Core\Privacy::providersIn($f17Embeds, ['ga4_id' => 'G-ABCD1234', 'gtm_id' => 'GTM-XYZ12', 'captcha_provider' => 'turnstile']),
    Kaleta\Core\Privacy::providersIn(['<iframe src="https://maps.google.com/maps?q=Praha&output=embed"></iframe>', '<script>fbq("init", "1")</script>'], ['matomo_url' => 'https://stats.example.com/']),
    Kaleta\Core\Privacy::providersIn(['<p>nothing</p>'], ['ga4_id' => '', 'captcha_provider' => ''])],
    [['youtube', 'google-analytics', 'google-tag-manager', 'turnstile'], ['google-maps', 'matomo', 'facebook'], []]);
$f17Rows = [];
foreach (Kaleta\Core\Privacy::providersIn($f17Embeds, []) as $f17Key) {
    foreach (Kaleta\Core\Privacy::KNOWN[$f17Key][2] as $f17Cookie) {
        $f17Rows[] = $f17Cookie[0] . ':' . $f17Cookie[3];
    }
}
check('2.14 Privacy: the YouTube embed maps to its cookies with the marketing category; every known cookie has a known category', [$f17Rows,
    array_values(array_unique(array_filter(array_merge(...array_values(array_map(fn (array $p): array => array_column($p[2], 3), Kaleta\Core\Privacy::KNOWN))), fn (string $c): bool => !isset(Kaleta\Core\Privacy::CATEGORIES[$c]))))],
    [['VISITOR_INFO1_LIVE:marketing', 'YSC:marketing', 'PREF:marketing'], []]);
check('2.14 Privacy::anonymiseData – labels stay, values go, the attachment entry is left out', Kaleta\Core\Privacy::anonymiseData([['Jméno', 'Jan Novák'], ['Email', 'jan@example.com'], ['Telefon', '+420 777 123 456'], ['Zpráva', 'Dobrý den, …'], ['CV', 'cv.pdf (120 kB)', '2026/10/abcdefabcdefabcdefabcdef.pdf']]),
    [['Jméno', ''], ['Email', ''], ['Telefon', ''], ['Zpráva', '']]);
$f17Md = Kaleta\Core\Privacy::markdown([['heading' => 'Forms', 'lines' => ['Form “Contact”: fields Name (text), Email (e-mail)']]], 'Record of processing');
check('2.14 Privacy::markdown – a title, the template notice and the sections as lists; the scheduler job and the settings are known', [str_starts_with($f17Md, '# Record of processing'), str_contains($f17Md, "## Forms\n\n- Form “Contact”: fields Name (text), Email (e-mail)"), str_contains($f17Md, t('Generated on %s from the site’s configuration. A template to review and complete – not legal advice.', date('j. n. Y'))),
    Kaleta\Core\Scheduler::JOBS['cookie_scan'], isset(Kaleta\Core\Scheduler::jobs()['cookie_scan']), Kaleta\Core\Settings::DEFAULTS['enquiries_expiry'], Kaleta\Core\Settings::DEFAULTS['accessibility_toolbar'],
    Kaleta\Mcp\Catalog::TOOLS['processing_record'], Kaleta\Mcp\Catalog::TOOLS['accessibility_statement']],
    [true, true, true, [86400, 'cron', 'What cookies the site sets'], true, 'delete', '0', ['read', ''], ['read', '']]);
