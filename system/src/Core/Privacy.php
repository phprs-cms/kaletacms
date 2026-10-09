<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;

/**
 * EU duties a site owner would otherwise do by hand on every site (2.14): what cookies and storage the site really uses,
 * anonymising enquiries instead of deleting them, a record of processing assembled from the configuration (GDPR Art. 30
 * style) and an accessibility statement filled from the site audit. Everything generated here is a template the owner
 * reviews – never legal advice; the admin screens say so where a document is produced.
 *
 * The cookie table has three sources: what Kaleta itself sets (a fixed list – the code is the source of truth), the known
 * third-party embeds and tags found in published builds and in the settings (KNOWN maps a provider to its cookies), and the
 * Set-Cookie headers the site's own pages answer with (scan(): only the site's own address, a few pages, stored in the
 * setting cookie_scan). Visitors see the table through the {{cookie_table}} placeholder on the cookie policy page.
 */
final class Privacy
{
    /**
     * The link to the privacy policy (setting cookies_policy_url) as it may be printed (3.3.2, N17): a path on the site or an
     * http(s) address; anything else (javascript:, data:, //host) gives '' and no link. Saving accepts only / and https://.
     */
    public static function policyUrl(Settings $s): string
    {
        $url = trim($s->get('cookies_policy_url'));

        return preg_match('#^(/(?![/\\\\])|https?://)[^\s"<>\\\\]*$#iD', $url) === 1 ? $url : '';
    }

    public const array CATEGORIES = ['necessary' => 'Necessary', 'statistics' => 'Statistics', 'marketing' => 'Marketing'];

    /**
     * Known providers: key => [name, pattern that finds the provider in HTML, settings or builds, [[cookie, purpose, duration, category]]].
     * The order is the order of the table.
     */
    public const array KNOWN = [
        'youtube' => ['YouTube', '~youtube(-nocookie)?\.com|youtu\.be~i', [
            ['VISITOR_INFO1_LIVE', 'Video player: bandwidth and preferences', '6 months', 'marketing'],
            ['YSC', 'Video player: views within a session', 'session', 'marketing'],
            ['PREF', 'Video player: preferences', '8 months', 'marketing'],
        ]],
        'vimeo' => ['Vimeo', '~vimeo\.com~i', [['vuid', 'Video player: statistics of plays', '2 years', 'statistics']]],
        'google-maps' => ['Google Maps', '~maps\.google\.|google\.com/maps~i', [
            ['NID', 'Map: preferences and usage', '6 months', 'marketing'],
            ['CONSENT', 'Map: the consent given to Google', '2 years', 'necessary'],
        ]],
        'google-analytics' => ['Google Analytics', '~G-[A-Z0-9]{4,20}|googletagmanager\.com/gtag|google-analytics\.com~', [
            ['_ga', 'Statistics: tells visitors apart', '2 years', 'statistics'],
            ['_ga_*', 'Statistics: keeps the session state', '2 years', 'statistics'],
            ['_gid', 'Statistics: tells visitors apart', '24 hours', 'statistics'],
        ]],
        'google-tag-manager' => ['Google Tag Manager', '~GTM-[A-Z0-9]{4,12}|googletagmanager\.com/gtm~', [
            ['_dc_gtm_*', 'Tag manager: limits the number of requests', '1 minute', 'statistics'],
        ]],
        'matomo' => ['Matomo', '~matomo|piwik~i', [
            ['_pk_id.*', 'Statistics: tells visitors apart', '13 months', 'statistics'],
            ['_pk_ses.*', 'Statistics: the session', '30 minutes', 'statistics'],
        ]],
        'facebook' => ['Meta (Facebook) Pixel', '~connect\.facebook\.net|fbq\(|facebook\.com/tr~i', [
            ['_fbp', 'Advertising: tells visitors apart across sites', '3 months', 'marketing'],
            ['fr', 'Advertising: shows and measures ads', '3 months', 'marketing'],
        ]],
        'recaptcha' => ['Google reCAPTCHA', '~recaptcha~i', [['_GRECAPTCHA', 'Spam check: the risk analysis of the visitor', '6 months', 'necessary']]],
        'turnstile' => ['Cloudflare Turnstile', '~turnstile|challenges\.cloudflare\.com~i', [['cf_clearance', 'Spam check: the passed challenge', '30 minutes', 'necessary']]],
        'hcaptcha' => ['hCaptcha', '~hcaptcha~i', [['hc_accessibility', 'Spam check: accessibility of the challenge', '1 month', 'necessary']]],
    ];

    /** Cookies and storage Kaleta sets itself – the code is the source of truth, so the list is fixed. */
    private const array OWN = [
        ['kaleta_souhlas', 'Cookie bar: the categories the visitor allowed (cookie)', '1 year', 'necessary'],
        ['ka-tema', 'The chosen light or dark appearance (local storage)', 'until removed', 'necessary'],
        ['ka-jazyk', 'The chosen language version (local storage)', 'until removed', 'necessary'],
        ['ka-pristupnost', 'Accessibility toolbar: text size, contrast, underlined links, reduced motion (local storage)', 'until removed', 'necessary'],
        ['PHPSESSID', 'Sign-in to the administration – only after signing in, never for visitors', 'session', 'necessary'],
    ];

    /** Pages the scan fetches at most – a few published pages are enough to see what the site answers with. */
    private const int SCAN_PAGES = 4;

    /* ---------- cookie table ---------- */

    /**
     * The table of cookies and storage: Kaleta's own, the known providers found in content and settings, and names the
     * last scan saw in Set-Cookie headers that no known provider explains.
     *
     * @return list<array{name: string, provider: string, purpose: string, duration: string, category: string}>
     */
    public static function cookieTable(App $app): array
    {
        $s = $app->settings();
        $rows = self::ownRows($s);
        $found = self::providersIn(self::contentSamples($app), self::settingSamples($s));
        foreach ($found as $key) {
            foreach (self::KNOWN[$key][2] as [$name, $purpose, $duration, $category]) {
                $rows[] = ['name' => $name, 'provider' => self::KNOWN[$key][0], 'purpose' => t($purpose), 'duration' => t($duration), 'category' => $category];
            }
        }
        $known = array_map(fn (array $r): string => strtolower(rtrim($r['name'], '*')), $rows);
        foreach ((self::lastScan($s)['cookies'] ?? []) as $name) {
            $name = (string) $name;
            $explained = false;
            foreach ($known as $prefix) {
                $explained = $explained || str_starts_with(strtolower($name), $prefix);
            }
            if (!$explained) {
                $rows[] = ['name' => $name, 'provider' => t('this site'), 'purpose' => t('Set by the server – describe its purpose'), 'duration' => t('see the browser'), 'category' => 'necessary'];
            }
        }

        return $rows;
    }

    /** @return list<array{name: string, provider: string, purpose: string, duration: string, category: string}> */
    private static function ownRows(Settings $s): array
    {
        $rows = [];
        foreach (self::OWN as [$name, $purpose, $duration, $category]) {
            if (($name === 'ka-tema' && !$s->bool('theme_switcher')) || ($name === 'ka-pristupnost' && !$s->bool('accessibility_toolbar'))
                || ($name === 'kaleta_souhlas' && $s->get('cookies_mode') !== 'vestavena') || ($name === 'ka-jazyk' && Language::additional($s) === [])) {
                continue;
            }
            $rows[] = ['name' => $name, 'provider' => 'Kaleta', 'purpose' => t($purpose), 'duration' => t($duration), 'category' => $category];
        }

        return $rows;
    }

    /**
     * Keys of KNOWN found in the given HTML or JSON samples (published builds, texts) and in the settings. Pure – the unit
     * test feeds it a sample of embeds.
     *
     * @param list<string> $samples
     * @param array<string, string> $settings analytics and privacy settings (ga4_id, gtm_id, matomo_url, captcha_provider, head_code, marketing_code…)
     * @return list<string>
     */
    public static function providersIn(array $samples, array $settings = []): array
    {
        $haystack = implode("\n", $samples) . "\n" . implode("\n", array_map('strval', $settings));
        $found = [];
        foreach (self::KNOWN as $key => [, $pattern]) {
            if (preg_match($pattern, $haystack) === 1 || ($key === 'matomo' && ($settings['matomo_url'] ?? '') !== '')
                || (in_array($key, ['recaptcha', 'turnstile', 'hcaptcha'], true) && ($settings['captcha_provider'] ?? '') === $key)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /** @return list<string> the published builds and texts of pages, site parts, pop-ups and components, as stored */
    private static function contentSamples(App $app): array
    {
        $db = $app->db();
        $samples = [];
        foreach ($db->all('SELECT text, stavba FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1') as $p) {
            $samples[] = (string) $p['stavba'] . "\n" . $p['text'];
        }
        foreach ($db->all('SELECT stavba FROM {casti}') as $c) {
            $samples[] = (string) $c['stavba'];
        }
        foreach ($db->all('SELECT stavba FROM {popupy}') as $p) {
            $samples[] = (string) $p['stavba'];
        }
        foreach ($db->all('SELECT stavba FROM {komponenty}') as $m) {
            $samples[] = (string) $m['stavba'];
        }

        return $samples;
    }

    /** @return array<string, string> */
    private static function settingSamples(Settings $s): array
    {
        $out = [];
        foreach (['ga4_id', 'gtm_id', 'matomo_url', 'plausible_domain', 'head_code', 'marketing_code', 'cookies_external_code', 'captcha_provider'] as $key) {
            $out[$key] = $s->get($key);
        }

        return $out;
    }

    /**
     * Asks the site's own pages what cookies they set: the home page and a few published pages over the site's own address –
     * never any other. Needs curl; a server that cannot reach itself records that and the table still has the other two sources.
     *
     * @return array{time: int, pages: int, cookies: list<string>, error: string}
     */
    public static function scan(App $app): array
    {
        $s = $app->settings();
        $result = ['time' => time(), 'pages' => 0, 'cookies' => [], 'error' => ''];
        if (!function_exists('curl_init')) {
            $result['error'] = 'curl is not available';
        } else {
            $base = $app->request->origin() . $app->url('');
            $paths = array_merge([''], array_map('strval', array_column($app->db()->all("SELECT seo_link FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = '' AND nadrazena IS NULL ORDER BY poradi LIMIT ?", [self::SCAN_PAGES - 1]), 'seo_link')));
            foreach ($paths as $path) {
                $names = self::setCookies($base . $path);
                if ($names === null) {
                    $result['error'] = 'the site did not answer at ' . $base . $path;
                    break; // the server cannot reach itself (a firewall, a single-worker server) – the other pages would wait for nothing
                }
                $result['pages']++;
                $result['cookies'] = array_values(array_unique(array_merge($result['cookies'], $names)));
            }
        }
        $s->set('cookie_scan', (string) json_encode($result));

        return $result;
    }

    /** @return array{time?: int, pages?: int, cookies?: list<string>, error?: string} */
    public static function lastScan(Settings $s): array
    {
        $scan = json_decode($s->get('cookie_scan'), true);

        return is_array($scan) ? $scan : [];
    }

    /**
     * Cookie names of one GET to the site's own address, or null when there was no answer.
     *
     * @return list<string>|null
     */
    private static function setCookies(string $url): ?array
    {
        $names = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Kaleta cookie scan)',
            CURLOPT_HEADERFUNCTION => function (\CurlHandle $ch, string $header) use (&$names): int {
                if (preg_match('/^set-cookie:\s*([^=;\s]+)=/i', $header, $m)) {
                    $names[] = $m[1];
                }

                return strlen($header);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $code === 0 ? null : array_values(array_unique($names));
    }

    /** The table for visitors, in the site language – {{cookie_table}} on the cookie policy page. */
    public static function cookieTableHtml(App $app): string
    {
        $html = '<table class="ka-cookies-tabulka"><thead><tr><th>' . e(t('Name')) . '</th><th>' . e(t('Provider')) . '</th><th>' . e(t('Purpose')) . '</th><th>' . e(t('Duration')) . '</th><th>' . e(t('Category')) . '</th></tr></thead><tbody>';
        foreach (self::cookieTable($app) as $r) {
            $html .= '<tr><td><code>' . e($r['name']) . '</code></td><td>' . e($r['provider']) . '</td><td>' . e($r['purpose']) . '</td><td>' . e($r['duration']) . '</td><td>' . e(t(self::CATEGORIES[$r['category']] ?? $r['category'])) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** Replaces {{cookie_table}} in the HTML of a page (also when it stands alone in a paragraph). */
    public static function fillCookieTable(string $html, App $app): string
    {
        if (!str_contains($html, '{{cookie_table}}')) {
            return $html;
        }
        $table = self::cookieTableHtml($app);

        return str_replace(['<p>{{cookie_table}}</p>', '{{cookie_table}}'], [$table, $table], $html);
    }

    /* ---------- anonymisation ---------- */

    /**
     * The form data of an enquiry without the person: labels stay, values go, attachments are left out (their files are
     * deleted by the caller). Pure – the unit test checks a sample.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $data
     * @return list<array{0: string, 1: string}>
     */
    public static function anonymiseData(array $data): array
    {
        return array_values(array_map(fn (array $d): array => [$d[0], ''], array_filter($data, fn (array $d): bool => !isset($d[2]))));
    }

    /**
     * Keeps the rows of the given enquiries for statistics (date, form, page, topic, triage kind, status) and blanks
     * everything about the person: the form values, the e-mail, the drafted reply, the note, the campaign origin; the
     * attachments are deleted. Returns how many were anonymised (already anonymised ones are skipped).
     *
     * @param list<int> $ids
     */
    public static function anonymise(Db $db, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $rows = $db->all('SELECT idp, data FROM {poptavky} WHERE anonymizovano IS NULL AND idp IN (' . implode(',', $ids) . ')');
        \Kaleta\Admin\Modules\Enquiries::deleteAttachments($rows);
        foreach ($rows as $r) {
            $db->update('poptavky', ['data' => (string) json_encode(self::anonymiseData(json_decode((string) $r['data'], true) ?: []), JSON_UNESCAPED_UNICODE),
                'email' => '', 'navrh_odpovedi' => null, 'poznamka' => null, 'vstup' => '', 'odkud' => '', 'anonymizovano' => date('Y-m-d H:i:s')], ['idp' => $r['idp']]);
        }

        return count($rows);
    }

    /* ---------- record of processing ---------- */

    /**
     * The record of processing activities assembled from what is configured: the forms and their fields, the newsletter,
     * the statistics, job applications and enquiries with their retention, connected services, mail, backups, the AI
     * assistant, the spam check and the cookies. Sections of lines; markdown() and the admin screen render them.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    public static function processingRecord(App $app): array
    {
        $s = $app->settings();
        $db = $app->db();
        $yes = fn (bool $v): string => t($v ? 'yes' : 'no');
        $sections = [];
        $sections[] = ['heading' => t('Controller'), 'lines' => array_values(array_filter([
            $s->get('company_name') !== '' ? t('Company: %s', $s->get('company_name')) : t('Company: not filled in (Business details)'),
            $s->get('company_address') !== '' ? t('Address: %s', $s->get('company_address')) : '',
            $s->get('site_email') !== '' ? t('Contact: %s', $s->get('site_email')) : t('Contact: no site e-mail (Settings → General)'),
            t('Website: %s', $app->request->origin() . $app->url('')),
        ]))];
        // forms: every Form element of the published site with its fields
        $forms = [];
        foreach (self::forms($app) as $f) {
            $forms[] = t('Form “%s” on %s: fields %s; notification to %s%s', $f['name'], $f['where'], implode(', ', array_map(fn (array $field): string => $field['label'] . ' (' . t($field['type']) . ')', $f['fields'])),
                $f['recipient'] !== '' ? $f['recipient'] : t('the site e-mail'), $f['confirmation'] ? '; ' . t('the sender gets a confirmation e-mail') : '');
        }
        $months = $s->int('enquiries_months');
        $sections[] = ['heading' => t('Enquiries and form messages'), 'lines' => array_merge($forms !== [] ? $forms : [t('No form on the published site.')], [
            t('Purpose: answering the enquiry and the business relationship that follows. Legal basis: performance of a contract or steps before it (Art. 6(1)(b)), legitimate interest in answering.'),
            $months > 0 ? t('Retention: %d months, then %s automatically.', $months, t($s->get('enquiries_expiry') === 'anonymise' ? 'anonymised (the row stays for statistics without the person)' : 'deleted including attachments'))
                : t('Retention: not limited – set it in Enquiries → Settings.'),
            t('Recipients: the people with access to Enquiries in the administration%s.', $s->get('webhook_enquiries') !== '' ? ', ' . t('a webhook at %s', (string) parse_url($s->get('webhook_enquiries'), PHP_URL_HOST)) : ''),
        ])];
        if (Jobs::sources($db) !== []) {
            $jobMonths = $s->int('job_applications_months');
            $sections[] = ['heading' => t('Job applications'), 'lines' => [
                t('Applications to job openings arrive as enquiries from the job pages, with CVs as attachments.'),
                $jobMonths > 0 ? t('Retention: %d months, then deleted including the CVs.', $jobMonths) : t('Retention: the same as other enquiries (%d months).', $months),
                t('Legal basis: steps before a contract (Art. 6(1)(b)); keeping an application longer needs the applicant’s consent.'),
            ]];
        }
        if (Extensions::isEnabled($s, 'newsletter')) {
            $sections[] = ['heading' => t('Newsletter'), 'lines' => [
                t('Subscribers: %d confirmed, %d waiting for confirmation (double opt-in; unconfirmed sign-ups are deleted after 30 days).', (int) $db->value('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1'), (int) $db->value('SELECT COUNT(*) FROM {odberatele} WHERE stav = 0')),
                t('Data: e-mail address, date and language of the sign-up%s.', $s->bool('lead_attribution') ? ', ' . t('the first page of the visit and its campaign (with consent to marketing)') : ''),
                t('Legal basis: consent (Art. 6(1)(a)); every e-mail carries an unsubscribe link.'),
                $s->get('newsletter_service') !== '' ? t('Processor: the mailing service %s (confirmed subscribers are passed to it).', ucfirst($s->get('newsletter_service'))) : t('Processor: none – the newsletter is sent from this site.'),
            ]];
        }
        $statistics = [];
        if (\Kaleta\Front\Stats::enabled($s)) {
            $statistics[] = t('Built-in statistics: page views, devices and campaigns counted without cookies; the visitor’s address is hashed with a daily salt and never stored.');
        }
        foreach (['ga4_id' => 'Google Analytics', 'gtm_id' => 'Google Tag Manager', 'matomo_url' => 'Matomo', 'plausible_domain' => 'Plausible'] as $key => $name) {
            if ($s->get($key) !== '') {
                $statistics[] = t('%s – a third-party service (processor); runs only after consent in the cookie bar unless the bar is off.', $name);
            }
        }
        $sections[] = ['heading' => t('Statistics'), 'lines' => $statistics !== [] ? $statistics : [t('No traffic statistics.')]];
        $services = [];
        if (class_exists(Connectors::class)) {
            foreach ($db->all('SELECT service, account FROM {connectors} WHERE connected_at IS NOT NULL') as $c) {
                $services[] = t('%s (account %s) – data the deliveries send leaves the site to this service.', ucfirst((string) $c['service']), (string) $c['account']);
            }
        }
        $sections[] = ['heading' => t('Connected services and processors'), 'lines' => array_merge($services, [
            match (true) {
                MailServices::current($s) !== null => t('E-mail: sent through %s (SMTP server %s) – the service processes the recipients’ addresses and the messages.', MailServices::name(MailServices::current($s)), $s->get('smtp_host')),
                $s->get('mail_mode') === 'smtp' => t('E-mail: sent through the SMTP server %s.', $s->get('smtp_host')),
                default => t('E-mail: sent by the hosting server’s mail function.'),
            },
            $s->get('remote_backup') !== 'vypnuto' ? t('Backups: automatic %s, an off-site copy over %s to %s.', $yes($s->bool('auto_backups')), strtoupper($s->get('remote_backup')), $s->get('backup_host')) : t('Backups: automatic %s, kept on this server only.', $yes($s->bool('auto_backups'))),
            Extensions::isEnabled($s, 'asistent') && $s->get('ai_provider') !== '' ? t('Writing assistant: %s – texts the administrators ask about%s are sent to it.', $s->get('ai_provider'), $s->bool('triage_assistant') ? ' ' . t('and new enquiries (triage)') : '') : t('Writing assistant: off.'),
            Extensions::isEnabled($s, 'claude') ? t('Claude (MCP): administrators’ assistants work with the content; enquiries are shown to them as the Enquiries screen shows them.') : '',
            $s->get('captcha_provider') !== '' ? t('Spam check: %s receives the visitor’s address and browser details when a form is sent.', Captcha::PROVIDERS[$s->get('captcha_provider')][0] ?? $s->get('captcha_provider')) : t('Spam check: built-in only (a signed time, a trap field, a limit per address) – no third party.'),
        ])];
        $cookies = array_map(fn (array $r): string => $r['name'] . ' – ' . $r['provider'] . ' (' . t(self::CATEGORIES[$r['category']] ?? $r['category']) . ', ' . $r['duration'] . ')', self::cookieTable($app));
        $sections[] = ['heading' => t('Cookies and storage'), 'lines' => array_merge([
            t('Cookie bar: %s.', t(['vestavena' => 'built-in, tracking starts after consent', 'externi' => 'an external service', 'zadna' => 'none'][$s->get('cookies_mode')] ?? $s->get('cookies_mode'))),
            $s->bool('cookies_log') ? t('Consents are logged (time, a random identifier, the categories – no IP address)%s.', $s->int('cookies_log_months') > 0 ? ' ' . t('and deleted after %d months', $s->int('cookies_log_months')) : '') : t('Consents are not logged.'),
        ], $cookies)];
        $sections[] = ['heading' => t('Security'), 'lines' => array_values(array_filter([
            t('Access to personal data: %d administration account(s); two-step sign-in %s.', (int) $db->value('SELECT COUNT(*) FROM {uzivatele} WHERE blokovat = 0'), (int) $db->value("SELECT COUNT(*) FROM {uzivatele} WHERE blokovat = 0 AND totp_tajemstvi = ''") === 0 ? t('on for everyone') : t('not on for every account')),
            $s->bool('firewall_enabled') ? t('Firewall: on (rate limits, probes, country and address blocks).') : '',
            t('Transport: %s.', $app->request->isHttps() ? 'HTTPS' : t('HTTP – switch the site to HTTPS')),
        ]))];

        return $sections;
    }

    /**
     * @param list<array{heading: string, lines: list<string>}> $sections
     */
    public static function markdown(array $sections, string $title): string
    {
        $md = '# ' . $title . "\n\n" . t('Generated on %s from the site’s configuration. A template to review and complete – not legal advice.', date('j. n. Y')) . "\n";
        foreach ($sections as $sec) {
            $md .= "\n## " . $sec['heading'] . "\n\n" . implode("\n", array_map(fn (string $l): string => '- ' . $l, $sec['lines'])) . "\n";
        }

        return $md;
    }

    /**
     * The Form elements of the published site with their fields.
     *
     * @return list<array{name: string, where: string, fields: list<array{label: string, type: string}>, recipient: string, confirmation: bool}>
     */
    public static function forms(App $app): array
    {
        $forms = [];
        foreach (Facts::texts($app->db()) as $t) {
            if ($t['build'] === null) {
                continue;
            }
            $walk = function (array $nodes) use (&$walk, &$forms, $t): void {
                foreach ($nodes as $n) {
                    if (($n['typ'] ?? '') === 'formular') {
                        $fields = [];
                        foreach ((array) ($n['obsah']['pole'] ?? []) as $field) {
                            $type = (string) ($field['typ'] ?? 'text');
                            if (!in_array($type, ['krok', 'skryte', 'odhad'], true)) {
                                $fields[] = ['label' => (string) ($field['popisek'] ?? ''), 'type' => \Kaleta\Builder\Elements\Form::FIELD_TYPES[$type] ?? $type];
                            }
                        }
                        $forms[] = ['name' => (string) ($n['obsah']['nazev'] ?? ''), 'where' => $t['where'], 'fields' => $fields, 'recipient' => (string) ($n['obsah']['prijemce'] ?? ''), 'confirmation' => !empty($n['obsah']['potvrzeni'])];
                    }
                    $walk((array) ($n['deti'] ?? []));
                }
            };
            $walk((array) ($t['build']['deti'] ?? []));
        }

        return $forms;
    }

    /* ---------- accessibility statement ---------- */

    /**
     * The statement filled from the site audit run now: the standard, the status (fully or partially compliant by the
     * accessibility findings), the known barriers, the contact and the date. A template for the owner to review.
     *
     * @return array{title: string, status: 'full'|'partial', findings: list<string>, date: string, html: string, text: string}
     */
    public static function accessibilityStatement(App $app): array
    {
        $s = $app->settings();
        $findings = [];
        foreach ((new Audit($app))->run() as $f) {
            if ($f['kind'] === 'accessibility' && ($f['target']['site'] ?? '') !== 'accessibility_statement') {
                $findings[] = $f['where'] . ': ' . $f['message'];
            }
        }
        $findings = array_slice(array_values(array_unique($findings)), 0, 30);
        $status = $findings === [] ? 'full' : 'partial';
        $site = $s->get('site_name');
        $contact = $s->get('site_email');
        $paragraphs = [
            t('%s is committed to making its website accessible in line with the European Accessibility Act and the national legislation that implements it. This accessibility statement applies to %s.', $site, $app->request->origin() . $app->url('')),
            t('The website aims to meet EN 301 549 and WCAG 2.1 level AA.'),
            $status === 'full' ? t('Compliance status: this website is fully compliant – the automatic check found no barriers.') : t('Compliance status: this website is partially compliant because of the non-compliances listed below.'),
        ];
        $list = $findings !== [] ? '<p>' . e(t('Non-accessible content:')) . '</p><ul>' . implode('', array_map(fn (string $f): string => '<li>' . e($f) . '</li>', $findings)) . '</ul>' : '';
        $closing = [
            t('This statement was prepared on %s from an automatic check of the site. The automatic check does not cover everything a person would notice; the owner reviews the statement before publishing it.', date('j. n. Y')),
            $contact !== '' ? t('Feedback and contact: if you find a barrier or need content in another form, write to %s. We will answer as soon as we can.', $contact) : t('Feedback and contact: add the e-mail address people can write to about barriers (Settings → General → Site e-mail).'),
            t('Enforcement: if you are not satisfied with our answer, you can contact the body in your country that supervises the accessibility of websites.'),
        ];
        $html = implode('', array_map(fn (string $p): string => '<p>' . e($p) . '</p>', $paragraphs)) . $list . implode('', array_map(fn (string $p): string => '<p>' . e($p) . '</p>', $closing));
        $text = implode("\n\n", $paragraphs) . ($findings !== [] ? "\n\n" . t('Non-accessible content:') . "\n" . implode("\n", array_map(fn (string $f): string => '- ' . $f, $findings)) : '') . "\n\n" . implode("\n\n", $closing);

        return ['title' => t('Accessibility statement'), 'status' => $status, 'findings' => $findings, 'date' => date('Y-m-d'), 'html' => $html, 'text' => $text];
    }

    /**
     * Creates the statement as a hidden page, or updates the one created before (remembered in the setting
     * accessibility_statement_page; a page deleted since is created anew). Returns the page id.
     */
    public static function saveStatementDraft(App $app): int
    {
        $db = $app->db();
        $s = $app->settings();
        $statement = self::accessibilityStatement($app);
        [$build] = Build::sanitize(['v' => 1, 'deti' => [Build::fresh('sekce', [], [array_replace(Build::fresh('nadpis', ['text' => $statement['title']]), ['znacka' => 'h1']), Build::fresh('text', ['html' => $statement['html']])])]]);
        $record = ['titulek' => $statement['title'], 'stavba' => Build::toJson($build), 'text' => Build::asText($build), 'zmeneno' => date('Y-m-d H:i:s')];
        $id = $s->int('accessibility_statement_page');
        if ($id > 0 && $db->value('SELECT 1 FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]) !== null) {
            $db->update('stranky', $record, ['ids' => $id]);
            \Kaleta\Admin\ChangeLog::write($app, 'pages', 'accessibility_statement', 'updated #' . $id);

            return $id;
        }
        $slug = \Kaleta\Admin\Modules\Pages::freeSlug($db, slugify($statement['title'], 110));
        $id = $db->insert('stranky', $record + ['seo_link' => $slug, 'zobrazit' => 0, 'v_menu' => 1, 'poradi' => 90]);
        $s->set('accessibility_statement_page', (string) $id);
        \Kaleta\Admin\ChangeLog::write($app, 'pages', 'accessibility_statement', 'created #' . $id);

        return $id;
    }
}
