<?php

declare(strict_types=1);

/* ---------- 3.29: visits confirmed by a browser (Front\Stats::hit) – who is a bot, which beacon is a person's, what the page carries ---------- */
use Kaleta\Front\Stats as Stats329;
$bots329 = [
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Google-InspectionTool/1.0;)',
    'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 (compatible; GoogleOther)',
    'Google-Extended',
    'Mediapartners-Google',
    'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
    'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0.6367.60 Safari/537.36',
    'Mozilla/5.0 (Unknown; Linux x86_64) AppleWebKit/538.1 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/538.1',
    'Go-http-client/2.0',
    'okhttp/4.12.0',
    'axios/1.6.8',
    'node-fetch/1.0 (+https://github.com/bitinn/node-fetch)',
    'python-requests/2.31.0',
    'Mozilla/5.0 (compatible; Dataprovider.com)',
    'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 PTST/240401.181010',
    'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)',
    'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 StatusCake',
    'WhatsApp/2.23.20.0 A',
    'TelegramBot (like TwitterBot)',
    'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
    'Mozilla/5.0 (Windows NT 6.1; WOW64) SkypeUriPreview Preview/0.5 skype-url-preview@microsoft.com',
    'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
    'Twitterbot/1.0',
    'Pinterest/0.2 (+https://www.pinterest.com/bot.html)',
    'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
    'curl/8.4.0',
];
$people329 = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
    'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.67',
    'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A.230805.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/124.0.6367.82 Mobile Safari/537.36',
    'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/124.0.6367.71 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/460.0.0.36.103;FBBV/584155476]',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36 OPR/81.0.4254.76802',
    // N329-2: a person reading in the Pinterest app's own browser
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [Pinterest/iOS]',
    'Mozilla/5.0 (Linux; Android 14; SM-S921B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/124.0.6367.82 Mobile Safari/537.36 [Pinterest/Android]',
];
check('3.29: the bot list matches crawlers, AI fetchers, HTTP libraries, headless browsers, link previews and speed and uptime tools by their real user agents',
    array_values(array_filter($bots329, fn (string $ua): bool => !Stats329::isBot($ua))), []);
check('3.29: … and never Chrome, Safari, Firefox, Edge, Samsung Internet, an Android WebView, Chrome on iOS, the Facebook in-app browser, Opera or the Pinterest app\'s browser',
    array_values(array_filter($people329, Stats329::isBot(...))), []);
$person329 = ['HTTP_USER_AGENT' => $people329[0], 'HTTP_HOST' => 'www.firma.cz'];
check('3.29: Stats::refusal – a person\'s beacon from this site passes; a bot, no browser, a prefetch or prerender, another site and a cross-site fetch are refused', [
    Stats329::refusal($person329, 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'https://www.firma.cz', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], 'https://www.firma.cz'),
    Stats329::refusal(['HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:8080'] + $person329, 'http://localhost:8080'),
    Stats329::refusal(['HTTP_HOST' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'www.firma.cz', 'HTTP_ORIGIN' => 'https://www.firma.cz'] + $person329, 'https://www.firma.cz'), // behind a proxy
    Stats329::refusal(['HTTP_HOST' => '10.0.0.5', 'HTTP_ORIGIN' => 'https://firma.cz'] + $person329, 'https://firma.cz'),                                         // the configured address
    Stats329::refusal(['HTTP_USER_AGENT' => $bots329[0]] + $person329, 'https://www.firma.cz'),
    Stats329::refusal(['HTTP_USER_AGENT' => '  '] + $person329, 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_SEC_PURPOSE' => 'prefetch;prerender'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_PURPOSE' => 'prefetch'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'https://evil.example'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'null'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'https://www.firma.cz.evil.example'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_SEC_FETCH_SITE' => 'same-site'], 'https://www.firma.cz'),
], [null, null, null, null, null, 'agent', 'agent', 'prefetch', 'prefetch', 'origin', null, 'origin', 'site', 'site']);
// N329-1: a page with the referrer policy no-referrer (a server's hardening header, a meta tag, the private whistleblowing page)
// makes a real browser send Origin: null on its own same-origin beacon – Sec-Fetch-Site decides; a sandboxed frame of another
// site is cross-site there
check('3.29 (N329-1): Origin: null is no host – a same-origin beacon (or a browser without Sec-Fetch-Site) passes, a cross-site one is refused', [
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'null'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], 'https://www.firma.cz'),
    Stats329::refusal($person329 + ['HTTP_ORIGIN' => 'NULL', 'HTTP_SEC_FETCH_SITE' => 'same-site'], 'https://www.firma.cz'),
], [null, null, 'site', 'site']);
check('3.29: the flood guard – 300 views a day at most, 10 views within 10 seconds is a machine', [Stats329::DAILY_VIEWS, Stats329::BURST_VIEWS, Stats329::BURST_SECONDS], [300, 10, 10]);
$vitalsJs329 = (string) file_get_contents(KALETA_ROOT . '/image/vitals.js');
check('[source-pin] 3.29: the beacon script counts the page view only when a person can see it – visible, not prerendered, never under browser automation – once, with sendBeacon (fetch keepalive as a fallback), without cookies or storage', [
    str_contains($vitalsJs329, "document.visibilityState !== 'visible' || document.prerendering"), str_contains($vitalsJs329, "addEventListener('prerenderingchange', count)"),
    str_contains($vitalsJs329, "addEventListener('visibilitychange', count)"), (bool) preg_match('/if \(!script \|\| navigator\.webdriver/', $vitalsJs329),
    str_contains($vitalsJs329, "getAttribute('data-hit')"), str_contains($vitalsJs329, 'keepalive: true'), str_contains($vitalsJs329, 'counted = true;'),
    (bool) preg_match('/document\.cookie|localStorage|sessionStorage|indexedDB/', $vitalsJs329)],
    [true, true, true, true, true, true, true, false]);
$kernel329 = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php');
check('[source-pin] 3.29: rendering a page or serving it from the page cache counts nothing; the beacon (and so the counting) is only on indexable pages outside previews and for nobody signed in', [
    str_contains($kernel329, 'Stats::record('), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Front/Cache.php'), 'Stats::record('),
    preg_match('/visit = visit \+ 1/', $kernel329 . file_get_contents(KALETA_SYSTEM . '/src/Front/Cache.php')),
    str_contains($kernel329, "\$meta['vitals'] = Stats::isOn(\$this->app) && \$this->app->request->get('preview') === '' && !\$this->wantsDraft() && \$this->app->auth()->user() === null;"),
    in_array('hit', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), in_array('hit', Kaleta\Core\Routes::NEWS_RESERVED, true), Kaleta\Core\Routes::pageLike('/hit'), Kaleta\Core\Settings::DEFAULTS['stats_confirmed_since']],
    [false, false, 0, true, true, true, false, '']);

