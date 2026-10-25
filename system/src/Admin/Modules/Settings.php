<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Updater;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Health;
use Kaleta\Core\Backup;

/**
 * Site settings (table ka_nastaveni) split into tabs.
 * Each tab has a template views/admin/settings/<tab>.php and a list of fields with a type - the values are cleaned by it.
 */
class Settings extends Module
{
    public const string IDENT = 'settings';
    public const string NAME = 'Nastavení';
    public const string GROUP = 'Administration';
    public const string ICON = 'nastaveni';
    public const bool ADMIN_ONLY = true;

    /** Tabs that became screens of their own in 3.2: the old address leads there, a form posted to the old tab still saves. */
    public const array MOVED_TABS = ['company' => 'business', 'health' => 'status'];

    public const array TABS = [
        'general' => 'General', 'company' => 'Company', 'seo' => 'SEO and GEO',
        'analytics' => 'Analytics', 'cookies' => 'Privacy and cookies', 'mail' => 'Mail', 'reports' => 'Reports', 'webhooks' => 'Webhooks', 'backups' => 'Backups and updates', 'firewall' => 'Firewall', 'console' => 'Fleet console', 'health' => 'System status',
    ];

    /** Company types for the field company_type (vyber:…). */
    private const string COMPANY_TYPES = 'Organization|LocalBusiness|HomeAndConstructionBusiness|ProfessionalService|LegalService|AccountingService|MedicalBusiness|AutomotiveBusiness|Store|FoodEstablishment|LodgingBusiness|SportsActivityLocation|EducationalOrganization';

    public const array SOCIAL_NETWORKS = ['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_x' => 'X (Twitter)', 'social_youtube' => 'YouTube', 'social_linkedin' => 'LinkedIn'];

    /**
     * Fields of the individual tabs: key in ka_nastaveni => type.
     * text | tajne (secret key: not printed back, empty field = no change; tajne:/regex/ also checks the format) | radky (multi-line text) | kod (HTML/JS - entered only by the administrator) | url | email | emaily (up to 10 addresses, comma or line separated) | ano (yes/no) | cislo:min:max (number) | vyber:a|b (choice) | seznam:a|b (checkboxes, saved as "a,b") | vzor:/regex/ (pattern)
     */
    private const array FIELDS = [
        'general' => [
            'site_name' => 'text', 'site_url' => 'vzor:#^https?://[a-z0-9.-]+(:\d+)?$#i', 'site_description' => 'radky', 'site_email' => 'email', 'footer_text' => 'text',
            'social_facebook' => 'url', 'social_instagram' => 'url', 'social_x' => 'url', 'social_youtube' => 'url', 'social_linkedin' => 'url',
            'home_page' => 'cislo:0:4294967295', 'news_per_page' => 'cislo:1:100', 'news_slug' => 'vzor:/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/', 'news_title' => 'text', 'share_buttons' => 'ano', 'social_networks' => 'seznam:' . \Kaleta\Core\SocialDrafts::NETWORK_KEYS, 'link_check' => 'ano', 'article_outline' => 'ano', 'related_news_auto' => 'ano', 'page_cache' => 'ano', 'maintenance' => 'ano', 'maintenance_text' => 'text', 'require_2fa' => 'vyber:|spravci|vsichni',
            // screen mode (2.11, Front\Screen); screen_collections is added by fields() from the site's collections, the secret is created by actionSave
            'screen_mode' => 'ano', 'screen_seconds' => 'cislo:' . \Kaleta\Front\Screen::MIN_SECONDS . ':' . \Kaleta\Front\Screen::MAX_SECONDS, 'screen_news' => 'ano', 'screen_hours' => 'ano', 'screen_clock' => 'ano',
            'auto_suspend' => 'seznam:' . \Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS . '|' . \Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS,
            'agency_name' => 'text', 'agency_url' => 'url', 'agency_email' => 'email', 'agency_phone' => 'vzor:/^[+()\d\s\/.-]{0,30}$/',
            'agency_logo' => 'vzor:#^((media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif))?$#',
            'time_zone' => 'pasmo', 'site_language' => 'vyber:' . \Kaleta\Core\Language::CODES, 'german_register' => 'vyber:formal|informal', 'additional_languages' => 'seznam:' . \Kaleta\Core\Language::CODES,
            'slugs_per_language' => 'ano', // 3.9: never written directly; 3.19: only switched on, by Core\Slug::enableEverywhere (actionSave)
        ],
        // the site appearance is saved by the Appearance module; here only types for checking values from the Claude connection (it is not a Settings tab)
        'vzhled' => ['dark_mode' => 'vyber:vypnuto|auto|tmavy', 'theme_switcher' => 'ano'],
        // redirects for missing addresses are set on the Redirects screen (2.14); here only the types for values from the Claude connection
        'presmerovani' => ['redirect_auto' => 'ano', 'redirect_auto_threshold' => 'cislo:50:100'],
        'company' => [
            'company_name' => 'text', 'company_type' => 'vyber:' . self::COMPANY_TYPES, 'company_id' => 'vzor:/^((?=.*\d)[A-Za-z0-9 .\/-]{1,24})?$/', 'company_register' => 'text', 'company_representative' => 'text', 'company_vat_id' => 'vzor:/^([A-Z]{2}[A-Z0-9]{6,12})?$/',
            'company_street' => 'text', 'company_city' => 'text', 'company_postcode' => 'vzor:/^[A-Z0-9 -]{0,10}$/i', 'company_country' => 'vzor:/^([A-Z]{2})?$/',
            'company_phone' => 'vzor:/^[+()\d\s\/.-]{0,30}$/', 'company_email' => 'email', 'company_hours' => 'hodiny', 'company_map' => 'url', 'company_gps' => 'vzor:/^(-?\d{1,2}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?)?$/',
        ],
        'seo' => [
            'indexing' => 'ano', 'schema_org' => 'ano', 'share_image' => 'text', 'share_image_auto' => 'ano', 'verification_google' => 'vzor:/^[A-Za-z0-9_-]{0,100}$/',
            'verification_bing' => 'vzor:/^[A-Za-z0-9]{0,64}$/', 'robots_extra' => 'radky', 'ai_crawlers' => 'vyber:povolit|zakazat', 'url_slash' => 'vyber:bez|s|html', 'llms_txt' => 'ano', 'markdown_news' => 'ano', 'indexnow' => 'ano',
            'security_contact' => 'vzor:#^([^\s@<>]+@[^\s@<>]+\.[a-z]{2,}|https://[^\s<>]+)?$#i',
        ],
        'analytics' => [
            'ga4_id' => 'vzor:/^(G-[A-Z0-9]{4,20})?$/', 'gtm_id' => 'vzor:/^(GTM-[A-Z0-9]{4,12})?$/', 'matomo_url' => 'url', 'matomo_id' => 'cislo:0:99999',
            'plausible_domain' => 'vzor:/^([a-z0-9.-]{3,100})?$/', 'head_code' => 'kod', // 'stats' is no longer here (3.2): the Statistics feature is the only switch
        ],
        'cookies' => ['cookies_mode' => 'vyber:zadna|vestavena|externi', 'cookies_external_code' => 'kod', 'cookies_text' => 'radky', 'cookies_policy_url' => 'vzor:#^((/(?![/\\\\])|https://)[^\s"<>\\\\]{0,250})?$#i', 'marketing_code' => 'kod', 'cookies_log' => 'ano', 'lead_attribution' => 'ano', 'cookies_log_months' => 'cislo:0:120', 'accessibility_toolbar' => 'ano',
            'captcha_provider' => 'vyber:|hcaptcha|recaptcha|turnstile', 'captcha_site_key' => 'vzor:/^[A-Za-z0-9_.-]{0,100}$/', 'captcha_secret' => 'tajne', 'captcha_fail_open' => 'ano'],
        'mail' => ['mail_mode' => 'vyber:mail|smtp', 'mail_from' => 'email', 'mail_reply_to' => 'email', 'smtp_host' => 'vzor:/^[A-Za-z0-9.-]{0,120}$/', 'smtp_port' => 'cislo:1:65535',
            'smtp_encryption' => 'vyber:tls|ssl|zadne', 'smtp_user' => 'text', 'smtp_password' => 'tajne', 'newsletter_hourly_limit' => 'cislo:10:100000',
            'smtp_provider' => 'vyber:' . \Kaleta\Core\MailServices::CHOICES], // 3.9: the mail service; MailServices::settle() fills its server after the save
        // the monthly report (2.9) and its archive (3.16, Core\ReportArchive): the e-mail switch and the recipients moved here from Mail
        'reports' => ['report_monthly' => 'ano', 'report_recipients' => 'emaily'],
        // Claude's instructions and guardrails (3.2: own screen, Modules\ClaudeSettings – the keys stay)
        'claude' => ['claude_instructions' => 'radky', 'claude_change_limit' => 'cislo:0:10000', 'claude_destructive' => 'ano', 'claude_apps_only' => 'ano', 'claude_protected_pages' => 'vzor:/^[0-9 ,;]{0,500}$/'],
        'extensions' => ['ai_provider' => 'vyber:' . \Kaleta\Core\Assistant::PROVIDER_KEYS, 'ai_key' => 'tajne', 'ai_model' => 'vzor:#^[A-Za-z0-9._:/-]{0,80}$#',
            'newsletter_service' => 'vyber:|brevo|mailerlite|mailchimp|ecomail|smartemailing|webhook', 'newsletter_key' => 'tajne',
            'newsletter_list' => 'vzor:#^[A-Za-z0-9_-]{0,64}$#', 'newsletter_webhook' => 'url'],
        'webhooks' => ['webhook_enquiries' => 'url', 'webhook_url' => 'url'],
        'backups' => ['remote_backup' => 'vyber:vypnuto|ftp|s3', 'backup_host' => 'vzor:#^[A-Za-z0-9.:/-]{0,150}$#', 'backup_user' => 'text', 'backup_password' => 'tajne',
            'backup_folder' => 'vzor:#^[A-Za-z0-9._/-]{0,150}$#', 'backup_region' => 'vzor:/^[a-z0-9-]{0,40}$/', 'auto_backups' => 'ano', 'backup_media' => 'ano', 'auto_updates' => 'ano', 'update_channel' => 'vyber:latest|stable', 'update_url' => 'url'],
        'firewall' => ['firewall_enabled' => 'ano', 'firewall_proxy' => 'vyber:|cloudflare', 'firewall_ips' => 'radky', 'firewall_countries' => 'vzor:/^[A-Za-z,;\s]{0,400}$/',
            'firewall_rate' => 'cislo:0:10000', 'firewall_probes' => 'ano'],
        'console' => [], // paired and changed by its own buttons (Fleet\Link), nothing to save
        'health' => ['health_token' => 'vzor:/^[A-Za-z0-9]{0,64}$/', 'alerts_enabled' => 'ano', 'alerts_email' => 'email'],
    ];

    /**
     * Fields of a tab. The general tab also has the site name and description for each additional language version
     * (nazev_webu_en, popis_webu_de…) - an empty value means "the same as in the default language".
     *
     * @return array<string, string>
     */
    protected function fields(string $tab): array
    {
        $field = self::FIELDS[$tab];
        if ($tab === 'general') {
            foreach (\Kaleta\Core\Language::additional($this->app->settings()) as $language) {
                $field += ['nazev_webu_' . $language => 'text', 'popis_webu_' . $language => 'radky'];
            }
            $field['screen_collections'] = 'seznam:' . implode('|', array_keys($this->screenCollections())); // the screen shows only collections that exist
        }
        if ($tab === 'cookies') {
            // 3.9 (UXM-11): the cookie bar text and the policy link for each further language version; empty = as in the default language
            foreach (\Kaleta\Core\Language::additional($this->app->settings()) as $language) {
                $field += ['cookies_text_' . $language => $field['cookies_text'], 'cookies_policy_url_' . $language => $field['cookies_policy_url']];
            }
        }

        return $field;
    }

    /** @return array<string, string> address => name of every collection, for the screen mode checkboxes */
    private function screenCollections(): array
    {
        return array_map('strval', $this->db->pairs('SELECT seo_link, nazev FROM {kolekce} ORDER BY ' . $this->db->sortText('nazev')));
    }

    /** Invalid values: a message with the field names as the user sees them, and the entered values back into the highlighted fields. */
    private function rejectInvalid(string $tab, array $errors, array $given, string $reason = ''): Response
    {
        $template = (string) @file_get_contents(KALETA_SYSTEM . '/views/admin/settings/' . $tab . '.php');
        $names = array_map(fn (string $key): string => preg_match('/\$(?:pole|field)\(\s*\'' . preg_quote($key, '/') . '\',\s*\'([^\']+)\'/', $template, $m) ? '„' . t($m[1]) . '“' : $key, $errors);
        $this->app->session->set('konfigurace_chybne', ['tab' => $tab, 'pole' => $errors, 'hodnoty' => $given]);

        return $this->back(t('These fields have an invalid format and were not saved: %s. Please correct them (they are highlighted); the other settings are saved.', implode(', ', $names))
            . ($reason !== '' ? ' ' . t($reason) : ''), '', static::IDENT === 'settings' ? ['tab' => $tab] : [], 'chyba');
    }

    /** Settings and the screens built on it (Features, Business details, Claude settings, System status) share the templates in admin/settings/. */
    protected function view(string $template, string $heading, array $data = []): Response
    {
        $data += ['app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField()];

        return $this->kernel->page(static::IDENT === 'settings' ? $heading : static::NAME, $this->hubTabs() . $this->app->view->render('admin/settings/' . $template, $data));
    }

    protected function actionList(): Response
    {
        if (static::IDENT === 'settings' && $this->request->get('tab') === 'extensions') {
            return Response::redirect($this->app->url('admin.php?module=extensions')); // Extensions have their own menu item
        }
        if (static::IDENT === 'settings' && isset(self::MOVED_TABS[$this->request->get('tab')])) {
            return Response::redirect($this->app->url('admin.php?module=' . self::MOVED_TABS[$this->request->get('tab')]));
        }
        $tab = $this->tab($this->request->get('tab'));
        $settings = $this->app->settings();
        $values = [];
        foreach ($this->fields($tab) as $key => $type) {
            $values[$key] = $settings->get($key);
            if (str_starts_with($type, 'tajne') && $values[$key] !== '') {
                $values[$key] = '…' . substr($values[$key], -4); // only the end of the key goes to the page, for checking
            }
        }

        $invalid = $this->app->session->get('konfigurace_chybne');
        $this->app->session->set('konfigurace_chybne', null);
        $invalid = is_array($invalid) && ($invalid['tab'] ?? '') === $tab ? $invalid : ['pole' => [], 'hodnoty' => []];

        return $this->view('list', 'Nastavení', [
            'tab' => $tab,
            'invalidFields' => $invalid['pole'],
            'values' => $invalid['hodnoty'] + $values,
            'checks' => $tab === 'health' ? Health::checks($this->app) : [],
            'readiness' => $tab === 'health' ? \Kaleta\Core\Readiness::checks($this->app) : [], // 3.14: Ready for 4.0, its own section
            'remoteStatus' => $settings->get('remote_backup_status'),
            'mediaStatus' => $settings->get('remote_media_status'),
            'tasksToken' => $settings->get('tasks_token'),
            'errorLog' => $tab === 'health' ? self::readFileTail(KALETA_ROOT . '/storage/log/chyby.log', 40) : [],
            'domainWatch' => in_array($tab, ['health', 'mail'], true) ? \Kaleta\Core\DomainWatch::cached($settings) : null, // Mail (3.9): the SPF and DKIM of the chosen service
            'mail' => $tab === 'mail' ? $this->db->all('SELECT komu, predmet, vytvoreno, odeslano, pokusu, dalsi_pokus, chyba FROM {posta} ORDER BY idp DESC LIMIT 30') : [],
            'webhookSecret' => $tab === 'webhooks' ? \Kaleta\Core\Webhook::secret($settings) : '',
            'deliveries' => $tab === 'webhooks' ? $this->db->all('SELECT id, event, url, attempts, status, error, created, next_attempt, delivered, body IS NOT NULL AS resendable FROM {webhook_deliveries} ORDER BY id DESC LIMIT 30') : [],
            'enabledExtensions' => Extensions::enabled($settings),
            'pages' => $tab === 'general' ? $this->db->pairs("SELECT ids, titulek FROM {stranky} WHERE zobrazit = 1 AND jazyk = '' ORDER BY poradi, " . $this->db->sortText('titulek')) : [],
            'screenCollections' => $tab === 'general' ? $this->screenCollections() : [],
            'screenUrl' => $tab === 'general' ? \Kaleta\Front\Screen::url($this->app) : '',
            'backups' => $tab === 'backups' ? Backup::listAll() : [],
            'vaultState' => $tab === 'backups' ? \Kaleta\Core\Vault::state($settings) : '', // 3.15 (N42): where the encryption key is
            'update' => $tab === 'backups' ? (new Updater($settings))->state() : null,
            'major' => in_array($tab, ['backups', 'health'], true) ? Updater::nextMajor($settings) : null, // 3.15: Kaleta 4.0 on its own update source
            'siteUrl' => $this->app->request->origin() . $this->app->url(''),
            'shareImages' => \Kaleta\Front\ShareImage::available(), // the SEO tab says when the server cannot draw them (2.12)
            'firewall' => $tab === 'firewall' ? [
                'blocks' => $this->db->all('SELECT ip, until, reason FROM {firewall_blocks} WHERE until > ? ORDER BY until DESC LIMIT 100', [\Kaleta\Core\Clock::now()]),
                'log' => $this->db->all('SELECT created_at, ip, reason, path FROM {firewall_log} ORDER BY id DESC LIMIT 50'),
                'ip' => \Kaleta\Core\Firewall::visitorIp($this->request->serverValues(), $settings->get('firewall_proxy')),
                'country' => \Kaleta\Core\Firewall::country($this->request->serverValues(), $settings->get('firewall_proxy')),
                'invalid' => \Kaleta\Core\Firewall::parseList($settings->get('firewall_ips'))[1],
            ] : [],
            // 3.16: the archived monthly reports, and the month the archive starts with (nothing exists before 3.16)
            'reports' => $tab === 'reports' ? ['months' => \Kaleta\Core\ReportArchive::months($this->db), 'first' => \Kaleta\Core\ReportArchive::firstMonth($this->db),
                'previous' => \Kaleta\Core\MonthlyReport::previousMonth(new \DateTimeImmutable())->format('Y-m')] : [],
            'hoursExceptions' => $tab === 'company' ? \Kaleta\Core\Hours::exceptions($this->db) : [],
            'hoursProposed' => $tab === 'company' ? \Kaleta\Core\Hours::proposed($this->db) : [], // by a drafts-only Claude connection (3.2)
            'fleet' => $tab === 'console' ? [
                'paired' => \Kaleta\Fleet\Link::isPaired($settings), 'url' => $settings->get('fleet_console_url'), 'name' => $settings->get('fleet_console_name'),
                'fingerprint' => \Kaleta\Fleet\Keys::fingerprint($settings->get('fleet_console_key')), 'own' => \Kaleta\Fleet\Keys::fingerprint(\Kaleta\Fleet\Keys::publicKey($settings)),
                'updates' => $settings->bool('fleet_updates'), 'allowed' => $settings->get('fleet_update_allowed'),
                'sent' => $settings->int('fleet_last_sent'), 'error' => $settings->get('fleet_last_error'),
                // the shared design kit (2.16, Fleet\Kit): opt-in, the version that arrived as drafts, and whether those drafts still wait
                'kit' => $settings->bool('fleet_kit'), 'kitVersion' => $settings->int('fleet_kit_version'), 'kitApplied' => $settings->int('fleet_kit_applied_at'),
                'kitError' => $settings->get('fleet_kit_error'), 'kitWaiting' => \Kaleta\Fleet\Kit::waiting($this->db, $settings),
                'titles' => $settings->bool('fleet_request_titles'), // 3.16: request titles in the heartbeat
            ] : [],
            'consents' => $tab === 'cookies' ? $this->db->all("SELECT kategorie, COUNT(*) AS pocet FROM {souhlasy} WHERE cas > ? GROUP BY kategorie ORDER BY pocet DESC", [\Kaleta\Core\Clock::at('-30 days')]) : [],
            'cookieTable' => $tab === 'cookies' ? \Kaleta\Core\Privacy::cookieTable($this->app) : [],
            'cookieScan' => $tab === 'cookies' ? \Kaleta\Core\Privacy::lastScan($settings) : [],
            'statementPage' => $tab === 'cookies' && $settings->int('accessibility_statement_page') > 0
                ? $this->db->one('SELECT ids, titulek, seo_link, zobrazit, zmeneno FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$settings->int('accessibility_statement_page')]) : null,
        ]);
    }

    protected function actionSave(): Response
    {
        $tab = $this->tab($this->request->post('tab'));
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $settings = $this->app->settings();
        $errors = [];
        $given = [];
        $reason = ''; // why the news URL was refused (Core\Routes::slugError)
        $zoneBefore = $settings->get('time_zone');
        $instructionsBefore = $settings->get('claude_instructions'); // 3.15 (N44): a change goes to the change log and the dashboard
        $newZone = ''; // 3.12: a new time zone is confirmed on its own screen (actionTimeZone)
        foreach ($this->fields($tab) as $key => $type) {
            if (\Kaleta\Core\Demo::active() && \Kaleta\Core\Demo::blocksSetting($key, $type)) {
                continue; // the public demo keeps code fields, secret keys and the site e-mail as they are
            }
            // a field the form did not show (e.g. the instructions for Claude while the extension is off) keeps its value;
            // a missing checkbox or list still means "off" / "none"
            if ($type !== 'ano' && !str_starts_with($type, 'seznam:') && !array_key_exists($key, $_POST)) {
                continue;
            }
            // "kod" is not trimmed or modified in any other way - it is HTML/JS inserted by the administrator
            $value = $type === 'kod' ? (string) ($_POST[$key] ?? '') : $this->request->post($key);
            if ($key === 'claude_protected_pages' && is_array($_POST[$key] ?? null)) {
                // the page picker of Claude settings (3.12) sends the ticked pages; the setting keeps its "12, 15" form
                $value = implode(', ', array_unique(array_filter(array_map('intval', $this->request->postList($key)))));
            }
            if (str_starts_with($type, 'seznam:')) {
                $settings->set($key, implode(',', array_intersect($this->request->postList($key), explode('|', substr($type, 7)))));
                continue;
            }
            if (str_starts_with($type, 'tajne')) {
                if ($this->request->postBool($key . '_smazat')) {
                    $settings->set($key, '');
                } elseif ($value !== '' && $type !== 'tajne' && !preg_match(substr($type, 6), $value)) {
                    $errors[] = $key; // the value is never printed in the message, only the field name
                } elseif ($value !== '') {
                    $settings->set($key, mb_substr($value, 0, 300));
                }
                continue;
            }
            $clean = self::sanitize($type, $value, $this->request->postBool($key));
            if ($key === 'time_zone' && $clean !== null && $clean !== $zoneBefore) {
                $newZone = $clean; // saved only after the administrator chooses what happens to the dates already saved
                continue;
            }
            if ($key === 'slugs_per_language') {
                // 3.19: on for every site; a site the update could not switch runs the same checks again (migration 0106) –
                // the form never switches it off (absent or unticked = nothing changes)
                if ($clean === '1' && !\Kaleta\Core\Slug::perLanguage($this->db) && ($failure = \Kaleta\Core\Slug::enableEverywhere($this->db, $settings)) !== null) {
                    $errors[] = $key;
                    $reason = \Kaleta\Core\Slug::failureText($failure);
                }
                continue;
            }
            if ($key === 'news_slug' && ($slugError = \Kaleta\Core\Routes::slugError($clean ?? $value, $this->db)) !== null) {
                $clean = null; // the length and the system addresses are checked here, the message says why
                $reason = $slugError;
            }
            if ($clean === null) {
                $errors[] = $key;
                $given[$key] = mb_substr($value, 0, 2000); // returned to the form for correction (secret keys not)
                continue;
            }
            $settings->set($key, $clean);
        }
        \Kaleta\Core\ClaudeInstructions::changed($this->app, $instructionsBefore, $settings->get('claude_instructions'));
        // 3.9: a mail service chosen without JavaScript (or switched from another one) gets its server, port and encryption;
        // a server that already is the service's stays as it is – the user name and the password are never touched
        if ($tab === 'mail' && array_key_exists('smtp_provider', $_POST) && array_intersect(['smtp_host', 'smtp_provider'], $errors) === [] && !\Kaleta\Core\Demo::active()) {
            \Kaleta\Core\MailServices::settle($settings, $this->request->post('smtp_ses_region'));
        }
        if ($tab === 'seo' && $settings->bool('indexnow') && $settings->get('indexnow_key') === '') {
            $settings->set('indexnow_key', bin2hex(random_bytes(16)));
        }
        if ($tab === 'extensions') {
            Extensions::save($settings, $this->request->postList('rozsireni'));
            if (Extensions::isEnabled($settings, 'novinky')) {
                Categories::createDefault($this->db, $settings); // news enabled after installation: right away with a category, as from the installation
            }
            if (($this->request->post('ai_key') !== '' || $this->request->post('ai_provider') !== $this->request->post('ai_poskytovatel_puvodni')) && $settings->get('ai_key') !== '' && ($keyError = (new \Kaleta\Core\Assistant($settings))->verifyKey()) !== null) {
                return $this->back(t('The settings are saved, but the assistant key does not work: %s', t($keyError)), '', static::IDENT === 'settings' ? ['tab' => $tab] : [], 'chyba');
            }
        }
        if ($tab === 'company') {
            \Kaleta\Core\GoogleBusiness::hoursChanged($this->app); // the regular week goes to the Business Profile (2.13)
        }
        // the cron and monitoring tokens are replaced only from System status by an administrator (3.3.2, N41): Business
        // details shares this action with editors, and a new token silently breaks the hosting's cron and the monitoring
        $tokens = $tab === 'health' && $this->app->auth()->isAdmin();
        if ($tokens && $this->request->postBool('novy_token_ulohy')) {
            $settings->set('tasks_token', bin2hex(random_bytes(16)));
        }
        if ($tokens && $this->request->postBool('novy_token')) {
            $settings->set('health_token', bin2hex(random_bytes(16)));
        }
        if ($tab === 'general' && ($settings->bool('screen_mode') || $this->request->postBool('novy_token_obrazovka'))) {
            // the screen address exists as soon as the mode is on; the button replaces it (the old one stops working)
            \Kaleta\Front\Screen::ensureSecret($settings, $this->request->postBool('novy_token_obrazovka'));
        }

        if ($tab === 'general' && $errors === [] && ($english = self::languagesInEnglish($settings)) !== []) {
            // 3.12 (UX310-12): a language Kaleta has no visitor texts for – said right after saving, not only in a help line
            $this->app->session->flash('info', t('Visitors of %s see the site texts (Search, Read more, the cookie bar…) in English – Kaleta has no translation of them yet. Your pages and news are in the language you write them in.',
                implode(', ', $english)));
        }
        if ($errors === [] && $newZone !== '') {
            // 3.12 (data model §6): the dates are wall time – the next screen offers to convert them or to keep their clock time
            return $this->back(t('Settings saved. Now confirm the new time zone and choose what happens to the dates already saved.'), 'time_zone', ['zone' => $newZone], 'info');
        }

        return $errors === []
            ? $this->back('Settings saved.', '', static::IDENT === 'settings' ? ['tab' => $tab] : [])
            : $this->rejectInvalid($tab, $errors, $given, $reason);
    }

    /**
     * A new time zone (3.12, docs/design/4.0-data-model.md §6, Core\TimeZoneConversion): the screen asks whether the saved
     * moments move to the new zone's clock ("Convert saved dates") or stay as written ("Keep their clock time"); the POST
     * sets the zone, writes the change log and converts the first batches at once – the background job finishes the rest.
     */
    protected function actionTimeZone(): Response
    {
        $settings = $this->app->settings();
        $from = $settings->get('time_zone');
        $to = $this->request->isPost() ? $this->request->post('zone') : $this->request->get('zone');
        if (static::IDENT !== 'settings' || $to === $from || !in_array($to, \DateTimeZone::listIdentifiers(), true)) {
            return $this->back('', '', ['tab' => 'general']);
        }
        if (\Kaleta\Core\TimeZoneConversion::running($this->db) !== null) {
            return $this->back(t('Saved dates are still being converted after the last time zone change. Change the zone again when that has finished.'), '', ['tab' => 'general'], 'chyba');
        }
        if (!$this->request->isPost()) {
            return $this->view('time_zone', 'Time zone', ['from' => $from, 'to' => $to]);
        }
        if (!in_array($this->request->post('choice'), ['convert', 'keep'], true)) {
            return $this->back('', 'time_zone', ['zone' => $to]);
        }
        $convert = $this->request->post('choice') === 'convert';
        try {
            // 3.12.1 (N312-1): the zone changes only while it is still $from, under a lock, together with the start – a
            // second click or tab finds the new zone and changes nothing
            $started = \Kaleta\Core\TimeZoneConversion::begin($this->db, $settings, $from, $to, $convert, $this->app->auth()->id() ?: null);
        } catch (\Throwable $e) {
            return $this->back(t('The time zone could not be changed: %s', $e->getMessage()), '', ['tab' => 'general'], 'chyba');
        }
        if ($started === null) {
            return $this->back(t('The time zone was not changed: it has just been changed in another window, or saved dates are still being converted.'), '', ['tab' => 'general'], 'chyba');
        }
        $this->app->applyTimezone(); // the rest of this request (the change log, the messages) runs on the new clock
        if (!$convert) {
            \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'time_zone', $from . ' → ' . $to . ': saved dates keep their clock time');

            return $this->back(t('The time zone is now %s. Dates already saved keep their clock time.', $to), '', ['tab' => 'general']);
        }
        \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'time_zone', $from . ' → ' . $to . ': converting saved dates');
        // a larger site: the first batches right away, under the background jobs' lock, so no job writes into a table
        // half converted; a failing table is skipped and reported, never an error page (N312-2)
        $state = null;
        if ((int) $this->db->value('SELECT GET_LOCK(?, 10)', [\Kaleta\Core\Scheduler::LOCK]) === 1) {
            try {
                $state = \Kaleta\Core\TimeZoneConversion::run($this->db, 8.0)
                    ?? \Kaleta\Core\TimeZoneConversion::state($this->db, $started, $from, $to); // converted at once in begin()
            } catch (\Throwable) {
                $state = null; // the background job continues it
            } finally {
                $this->db->value('SELECT RELEASE_LOCK(?)', [\Kaleta\Core\Scheduler::LOCK]);
            }
        }
        if ($state !== null && ($state['finished_now'] || ($state['done'] && $state['id'] === $started))) { // finished now, or at once in begin()
            \Kaleta\Core\TimeZoneConversion::logFinished($this->app, $state);
        }

        return $this->back($state !== null && $state['done']
            ? t('The time zone is now %s. Saved dates were converted: %d values in %d tables.', $to, $state['values'], $state['tables'])
                . ($state['out_of_range'] + count($state['failed']) > 0 ? ' ' . t('Some were left as they were – see the time zone below and System status.') : '')
            : t('The time zone is now %s. Saved dates are being converted in the background – the progress is under the time zone below.', $to), '', ['tab' => 'general']);
    }

    /**
     * Names of the site's languages (the default and the further versions) without visitor texts (3.12, UX310-12).
     *
     * @return list<string>
     */
    public static function languagesInEnglish(\Kaleta\Core\Settings $settings): array
    {
        $codes = array_unique([\Kaleta\Core\Language::defaults($settings), ...\Kaleta\Core\Language::additional($settings)]);

        return array_values(array_map(fn (string $code): string => \Kaleta\Core\Language::AVAILABLE[$code][0],
            array_filter($codes, fn (string $code): bool => \Kaleta\Core\Language::isOffered($code) && !\Kaleta\Core\Language::hasVisitorTexts($code))));
    }

    protected function actionBackup(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $file = Backup::create($this->db);
        } catch (\Throwable $e) {
            return $this->back(t('The backup could not be created: %s', t($e->getMessage())), '', ['tab' => 'backups'], 'chyba');
        }
        $remote = \Kaleta\Core\RemoteBackup::upload($this->app->settings(), (string) Backup::path($file));
        if ($remote !== null) {
            return $this->back(t('Backup %s is ready, but the off-site copy could not be uploaded: %s', $file, t($remote)), '', ['tab' => 'backups'], 'chyba');
        }
        // the media go along (only new and changed files); what does not fit in this request continues in the background
        $media = \Kaleta\Core\RemoteBackup::syncMedia($this->app->settings(), 20);
        if ($media !== null) {
            return $this->back(t('Backup %s is ready, but the media could not be copied: %s', $file, t($media)), '', ['tab' => 'backups'], 'chyba');
        }

        return $this->back(t('Backup %s is ready.', $file), '', ['tab' => 'backups']);
    }

    protected function actionDownloadBackup(): Response
    {
        if (Backup::refusedInDemo()) {
            return $this->error(\Kaleta\Core\Demo::refusal(), 403);
        }
        $path = Backup::path($this->request->get('file'));
        if ($path === null) {
            return $this->error('Backup does not exist.', 404);
        }
        // a backup holds every account and key of the site: who downloaded which one is in the change log (3.15, N42)
        \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'backup downloaded', basename($path));
        $this->sendFile($path, basename($path)); // streamed: a large backup does not have to fit in memory
    }

    /**
     * Moves the encryption key out of the database (3.15, N42, Core\Vault::move): into config.php, the stored secrets and
     * reports encrypted again in one transaction. When config.php cannot be written, the line to add is shown once – on
     * this page only, never in a message kept in the session, a log or the change log.
     */
    protected function actionVaultMove(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        $result = \Kaleta\Core\Vault::move($this->app);

        return match ($result['status']) {
            'moved' => $this->back(t('The encryption key is now in config.php and the stored secrets and reports are encrypted with it (%d values).', $result['count'] ?? 0)
                . ' ' . t('Backups made before the move still hold the old key with the data – delete them, or keep them as safe as config.php.'), '', ['tab' => 'backups']),
            'line' => $this->view('vault_line', 'Encryption key', ['line' => $result['line'] ?? '']),
            'already' => $this->back(t('The encryption key is already in config.php.'), '', ['tab' => 'backups']),
            'missing' => $this->back(\Kaleta\Core\Vault::describe('missing'), '', ['tab' => 'backups'], 'chyba'),
            default => $this->back(t('The move failed and nothing has changed in the database: %s', (string) ($result['error'] ?? '')), '', ['tab' => 'backups'], 'chyba'),
        };
    }

    protected function actionDeleteBackup(): Response
    {
        $path = Backup::path($this->request->post('soubor'));
        if ($this->request->isPost() && $path !== null && !Backup::refusedInDemo()) {
            unlink($path);
        }

        return $this->back('Backup deleted.', '', ['tab' => 'backups']);
    }

    /** Empties the application error log. */
    /** Lifts a temporary block of the firewall (2.8). */
    /** An exception to the opening hours (2.10, Core\Hours). */
    protected function actionHoursAdd(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'company']);
        }
        $error = \Kaleta\Core\Hours::save($this->app, ['from' => $this->request->post('exception_from'), 'to' => $this->request->post('exception_to'),
            'closed' => $this->request->postBool('exception_closed'), 'hours' => $this->request->post('exception_hours'), 'note' => $this->request->post('exception_note'),
            'notice_days' => $this->request->postInt('exception_notice', 7)]);

        return $error !== null ? $this->back($error, '', ['tab' => 'company'], 'chyba') : $this->back('The exception is saved.', '', ['tab' => 'company']);
    }

    protected function actionHoursDelete(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Core\Hours::delete($this->app, $this->request->postInt('exception'));
        }

        return $this->back('The exception is deleted.', '', ['tab' => 'company']);
    }

    /** Applies an exception Claude proposed (3.2): from now on the site uses it. */
    protected function actionHoursApply(): Response
    {
        if ($this->request->isPost() && \Kaleta\Core\Hours::apply($this->app, $this->request->postInt('exception'))) {
            return $this->back('The exception is applied.', '', ['tab' => 'company']);
        }

        return $this->back('The proposal no longer exists.', '', ['tab' => 'company'], 'chyba');
    }

    /** Discards an exception Claude proposed (3.2) – the site never used it. */
    protected function actionHoursDiscard(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Core\Hours::discard($this->app, $this->request->postInt('exception'));
        }

        return $this->back('The proposal is discarded.', '', ['tab' => 'company']);
    }

    /** A door sign for an exception, printable in a new tab (2.10, Core\HoursSign) – GET that only renders, so the demo shows it too. */
    protected function actionHoursSign(): Response
    {
        $exception = \Kaleta\Core\Hours::find($this->db, $this->request->getInt('exception'));
        if ($exception === null) {
            return $this->error('The exception no longer exists.', 404);
        }
        $urls = [];
        foreach (\Kaleta\Core\HoursSign::FORMATS as $format) {
            $urls[$format] = $this->url('hours_sign', ['exception' => $exception['id']] + ($format === 'a4' ? [] : ['format' => $format]));
        }

        // its own inline styles and the Print button script, nothing else may run – like the report preview
        return new Response(\Kaleta\Core\HoursSign::render($this->app, $exception, $this->request->get('format'), $urls), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; script-src 'self'; frame-ancestors 'self'; base-uri 'none'"]);
    }

    /** Pairs the site with a fleet console (2.9, Fleet\Link) – the pairing key comes from the console. */
    protected function actionFleetPair(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'console']);
        }
        try {
            \Kaleta\Fleet\Link::pair($this->app, $this->request->post('pairing_key'), $this->request->postBool('fleet_updates'));
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), '', ['tab' => 'console'], 'chyba');
        }

        return $this->back('The site is paired with the console and has sent its first report.', '', ['tab' => 'console']);
    }

    protected function actionFleetSend(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'console']);
        }
        try {
            \Kaleta\Fleet\Link::send($this->app);
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), '', ['tab' => 'console'], 'chyba');
        }

        return $this->back('The report has been sent to the console.', '', ['tab' => 'console']);
    }

    protected function actionFleetUpdates(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Fleet\Link::setManageUpdates($this->app, $this->request->postBool('fleet_updates'));
        }

        return $this->back('The choice about updates is saved.', '', ['tab' => 'console']);
    }

    protected function actionFleetUnpair(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Fleet\Link::unpair($this->app);
        }

        return $this->back('The site no longer reports to the console.', '', ['tab' => 'console']);
    }

    /** Opt in to (or out of) the console's shared design kit (2.16, Fleet\Kit) – it arrives as drafts with the next report. */
    protected function actionFleetKit(): Response
    {
        if ($this->request->isPost()) {
            $on = $this->request->postBool('fleet_kit');
            $this->app->settings()->set('fleet_kit', $on ? '1' : '0');
            \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'fleet_kit', $on ? 'on' : 'off');
        }

        return $this->back('The choice about the shared kit is saved.', '', ['tab' => 'console']);
    }

    /** 3.16: whether the heartbeat carries the titles of Ask Claude requests (Fleet\Heartbeat) – the console's inbox across sites. */
    protected function actionFleetTitles(): Response
    {
        if ($this->request->isPost()) {
            $on = $this->request->postBool('fleet_request_titles');
            $this->app->settings()->set('fleet_request_titles', $on ? '1' : '0');
            \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'fleet_request_titles', $on ? 'on' : 'off');
        }

        return $this->back('The choice about request titles is saved.', '', ['tab' => 'console']);
    }

    protected function actionFirewallUnblock(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {firewall_blocks} WHERE ip = ?', [mb_substr($this->request->post('ip'), 0, 45)]);
        }

        return $this->back('The address is no longer blocked.', '', ['tab' => 'firewall']);
    }

    protected function actionDeleteLog(): Response
    {
        if ($this->request->isPost() && is_file(KALETA_ROOT . '/storage/log/chyby.log')) {
            file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', '');
        }

        return $this->back('The error log is empty.', '', ['tab' => 'health']);
    }

    /**
     * The last N lines of a file without loading the whole file into memory.
     *
     * @return list<string>
     */
    private static function readFileTail(string $file, int $lines): array
    {
        if (!is_file($file) || filesize($file) === 0) {
            return [];
        }
        $f = fopen($file, 'rb');
        fseek($f, -min(filesize($file), 64 * 1024), SEEK_END);
        $end = (string) stream_get_contents($f);
        fclose($f);

        return array_slice(array_values(array_filter(explode("\n", $end), fn (string $r): bool => trim($r) !== '')), -$lines);
    }

    /** Restoring the database from a backup; right before it, a safety backup of the current state is created. */
    protected function actionRestoreBackup(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        try {
            $safetyBackup = Backup::create($this->db, 'predobnovou');
            $statementCount = Backup::restore($this->db, $this->request->post('soubor'));
        } catch (\Throwable $e) {
            $reverted = false;
            if (isset($safetyBackup)) {
                // failure in the middle of the restore: the database returns by itself to the state before the restore
                try {
                    Backup::restore($this->db, $safetyBackup);
                    $reverted = true;
                } catch (\Throwable) {
                    // the revert failed – the administrator runs it manually from the backup $safetyBackup
                }
            }
            \Kaleta\Front\Cache::clear();

            return $this->back(t('Obnova se nezdařila: %s', t($e->getMessage())) . ' ' . ($reverted ? t('The database is back in its state before the restore.') : (isset($safetyBackup) ? t('The state before the restore is in backup %s – please restore it.', $safetyBackup) : '')), '', ['tab' => 'backups'], 'chyba');
        }
        \Kaleta\Front\Cache::clear();

        return $this->back(t('The database has been restored from the backup (statements: %d). The state before the restore is saved in backup %s.', $statementCount, $safetyBackup), '', ['tab' => 'backups']);
    }

    /** Checks again whether a newer version is available. */
    protected function actionCheck(): Response
    {
        if ($this->request->isPost()) {
            (new Updater($this->app->settings()))->state(true);
        }

        return $this->back('', '', ['tab' => 'backups']);
    }

    /**
     * 3.10 (N310-3): forgets the remembered update information (the newest manifest of each source, Core\Updater::forgetSeen)
     * – the way out when the source really is right again after a stale or future-dated manifest. Logged in the change log.
     */
    protected function actionForgetUpdates(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        (new Updater($this->app->settings()))->forgetSeen();
        \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'forget update information');

        return $this->back(t('The update history was reset; the next check accepts any update information signed by the publisher.'), '', ['tab' => 'backups']);
    }

    /** Downloads, verifies and installs the new version. Before that it backs up the database. */
    protected function actionUpdate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            Backup::create($this->db, 'predaktualizaci');
            // the version the administrator saw on the button (3.3.2): another one offered meanwhile is not installed
            $version = (new Updater($this->app->settings()))->install($this->app->db(), $this->request->post('verze') !== '' ? $this->request->post('verze') : null);
        } catch (\Throwable $e) {
            return $this->back(t('The update failed: %s Nothing has changed on the site.', t($e->getMessage())), '', ['tab' => 'backups'], 'chyba');
        }

        return $this->back(t('The system has been updated to version %s.', $version), '', ['tab' => 'backups']); // the database is migrated during the update (2.8)
    }

    /**
     * 3.15: installs the next major version (Kaleta 4.0) – never by itself, only here: for the version the administrator saw,
     * after they confirmed they read what changes, from the newest release of the current line, and after a fresh backup.
     */
    protected function actionUpdateMajor(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        $version = $this->request->post('verze');
        if ($version === '' || $this->request->post('precteno') !== '1') {
            return $this->back(t('Confirm that you have read what changes and checked Ready for 4.0, then start the update again.'), '', ['tab' => 'backups'], 'chyba');
        }
        // N315-3: a fresh check of the current line must confirm this is its newest release – fails closed
        if (($blocker = Updater::majorBlocker((new Updater($this->app->settings()))->state(true))) !== null) {
            return $this->back($blocker, '', ['tab' => 'backups'], 'chyba');
        }
        // the database server or its rights would make the update fail half-way – Ready for 4.0 must clear them first
        if (($blockers = \Kaleta\Core\Readiness::blockers(\Kaleta\Core\Readiness::checks($this->app))) !== []) {
            return $this->back(t('The update to a new major version waits until Ready for 4.0 clears: %s.', implode(', ', $blockers)), '', ['tab' => 'backups'], 'chyba');
        }
        try {
            $backup = Backup::create($this->db, 'predaktualizaci');
            $installed = (new Updater($this->app->settings(), major: true))->install($this->app->db(), $version);
        } catch (\Throwable $e) {
            return $this->back(t('The update failed: %s Nothing has changed on the site.', t($e->getMessage())), '', ['tab' => 'backups'], 'chyba');
        }

        return $this->back(t('The system has been updated to version %s. The database backup from before the update is %s.', $installed, $backup), '', ['tab' => 'backups']);
    }

    /**
     * Backup of uploaded media: a ZIP of the media/ folder for download. Files are only stored, not compressed (images
     * are compressed already), so even a large library is packed quickly; the ZIP is sent in chunks and deleted after.
     * A library too large for one request belongs to the off-site copy (RemoteBackup::syncMedia) or FTP.
     */
    protected function actionMediaBackup(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'backups']);
        }
        if (!class_exists(\ZipArchive::class) || !is_dir(KALETA_ROOT . '/media')) {
            return $this->back('The zip extension is missing on the server – download the media via FTP.', '', ['tab' => 'backups'], 'chyba');
        }
        $files = [];
        foreach (\Kaleta\Core\SiteExport::mediaFiles() as $path => $size) {
            // variants for srcset and the WebP/AVIF siblings (foto.jpg.webp) can be recreated at any time - only originals go to the backup,
            // including an original uploaded as WebP (foto.webp, one extension)
            if (!preg_match('/(-1200|-nahled)\.[a-z]+$|\.[a-z0-9]+\.(webp|avif)$/i', basename($path))) {
                $files[$path] = $size;
            }
        }
        if ($files === []) {
            return $this->back('There is nothing in the media/ folder yet.', '', ['tab' => 'backups'], 'chyba');
        }
        $free = @disk_free_space(KALETA_ROOT . '/storage/cache');
        if (array_sum($files) > \Kaleta\Core\SiteExport::MAX_MEDIA || ($free !== false && array_sum($files) * 1.1 > $free)) {
            return $this->back('The media are too large to pack in one go. Turn on the off-site copy (it copies the media bit by bit) or download the media/ folder over FTP.', '', ['tab' => 'backups'], 'chyba');
        }
        @set_time_limit(300);
        $file = KALETA_ROOT . '/storage/cache/media-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        foreach (array_keys($files) as $path) {
            $zip->addFile(KALETA_ROOT . '/' . $path, $path);
            $zip->setCompressionName($path, \ZipArchive::CM_STORE);
        }
        if (!$zip->close() || !is_file($file)) {
            @unlink($file);

            return $this->back('The archive could not be finished – the disk is probably full.', '', ['tab' => 'backups'], 'chyba');
        }
        register_shutdown_function(static fn () => @unlink($file));
        $this->sendFile($file, 'media-' . date('Ymd') . '.zip', 'application/zip');
    }

    /** Test e-mail to the site e-mail - verifies that the server can send mail. */
    protected function actionTestMail(): Response
    {
        $recipient = $this->app->settings()->get('site_email');
        if (!$this->request->isPost() || $recipient === '') {
            return $this->back('First fill in the Site e-mail on the General tab.', '', ['tab' => $this->request->post('tab') === 'mail' ? 'mail' : 'health'], 'chyba');
        }
        $siteSettings = $this->app->settings()->get('site_name');
        // the site e-mail has no account with a language: the message goes in the site's default language (like the rest of the site's mail)
        [$subject, $text] = \Kaleta\Core\Language::runWith(\Kaleta\Core\Language::defaults($this->app->settings()), fn (): array => [
            t('Test message from %s', $siteSettings),
            t('Hello,') . "\n\n" . t('this message confirms that the website %s can send e-mail.', $siteSettings) . "\n\nKaleta " . KALETA_VERSION,
        ], 'admin-');
        $ok = \Kaleta\Core\Mail::send($this->app->settings(), $recipient, $subject, $text, queueOnFailure: false);
        $back = $this->request->post('tab') === 'mail' ? 'mail' : 'health';
        $service = \Kaleta\Core\MailServices::current($this->app->settings()); // 3.9: a failed sign-in says what this service wants as the password

        return $this->back(
            match (true) {
                !$ok && $service !== null => t('Sending failed: %s', t(\Kaleta\Core\Mail::$error)) . ' ' . t('%s – password: %s', \Kaleta\Core\MailServices::name($service), t(\Kaleta\Core\MailServices::PROVIDERS[$service]['password'])),
                !$ok => t('Sending failed: %s', t(\Kaleta\Core\Mail::$error)),
                $this->app->settings()->get('mail_mode') === 'smtp' => t('The message has been handed over for delivery to %s. If it does not arrive, check your spam folder.', $recipient),
                default => t('The message has been handed over for delivery to %s. If it does not arrive, check your spam folder – or set up sending via SMTP (Settings → Mail).', $recipient),
            },
            '',
            ['tab' => $back],
            $ok ? 'ok' : 'chyba',
        );
    }

    /** The previous month's report as the e-mail shows it, in a new tab (2.9, Core\MonthlyReport) – the owner sees what the recipients get. */
    protected function actionReportPreview(): Response
    {
        $mail = \Kaleta\Core\MonthlyReport::compose($this->app, \Kaleta\Core\MonthlyReport::previousMonth(new \DateTimeImmutable()));

        // the e-mail's own inline styles, nothing else may run – like the newsletter preview
        return new Response($mail['html'], 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src * data:; frame-ancestors 'self'; base-uri 'self' https: http:"]);
    }

    /**
     * Sends a month's report to the recipients right away (the public demo never gets here – Demo::blocksAdmin): the previous
     * month, or an archived one chosen in Settings → Reports (3.16).
     */
    protected function actionReportSend(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'reports']);
        }
        $month = $this->request->post('month');
        if ($month !== '' && \Kaleta\Core\ReportArchive::get($this->db, $month) === null) {
            return $this->back('This month is not in the archive.', '', ['tab' => 'reports'], 'chyba');
        }
        $start = $month !== '' ? new \DateTimeImmutable($month . '-01') : \Kaleta\Core\MonthlyReport::previousMonth(new \DateTimeImmutable());
        $sent = \Kaleta\Core\MonthlyReport::sendAndRecord($this->app, $start);

        return $sent === 0
            ? $this->back('There is nobody to send the report to – fill in the recipients, or the site e-mail on the General tab.', '', ['tab' => 'reports'], 'chyba')
            : $this->back(t('The report for %s has been handed over for delivery to %d recipient(s).', \Kaleta\Core\ReportPage::monthName($start->format('Y-m')), $sent), '', ['tab' => 'reports']);
    }

    /** One archived month as the printable page (3.16, Core\ReportPage) – GET that only renders, with its Print button. */
    protected function actionReportView(): Response
    {
        $stored = \Kaleta\Core\ReportArchive::get($this->db, $this->request->get('month'));
        if ($stored === null) {
            return $this->error('This month is not in the archive.', 404);
        }
        $html = \Kaleta\Core\ReportPage::render($this->app->view, $stored, $this->app->settings(), $this->siteAddress(), [
            'script' => $this->app->url('image/tisk.js') . '?v=' . KALETA_VERSION, 'download' => $this->url('report_download', ['month' => $stored['month']]), 'back' => $this->url('', ['tab' => 'reports'])]);

        // its own inline styles and the Print button script, nothing else may run – like the door sign
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; script-src 'self'; frame-ancestors 'self'; base-uri 'none'"]);
    }

    /** The same page as an HTML file to keep or attach (3.16): no toolbar, no script, the logo by its full address. */
    protected function actionReportDownload(): Response
    {
        $stored = \Kaleta\Core\ReportArchive::get($this->db, $this->request->get('month'));
        if ($stored === null) {
            return $this->error('This month is not in the archive.', 404);
        }
        $host = (string) preg_replace('/[^a-z0-9.-]+/', '-', strtolower((string) (parse_url($this->siteAddress(), PHP_URL_HOST) ?: 'site')));

        return new Response(\Kaleta\Core\ReportPage::render($this->app->view, $stored, $this->app->settings(), $this->siteAddress()), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="website-report-' . $host . '-' . $stored['month'] . '.html"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; frame-ancestors 'none'; base-uri 'none'"]);
    }

    /**
     * Builds an archived month again from the data still available (3.16) – the administrator's explicit choice after a
     * warning: the sources are kept 30–180 days, so a rebuilt older month can show less than the stored one.
     */
    protected function actionReportRebuild(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'reports']);
        }
        $month = $this->request->post('month');
        if (\Kaleta\Core\ReportArchive::get($this->db, $month) === null) {
            return $this->back('This month is not in the archive.', '', ['tab' => 'reports'], 'chyba');
        }
        \Kaleta\Core\ReportArchive::store($this->app, new \DateTimeImmutable($month . '-01'), true);

        return $this->back(t('The report for %s was rebuilt from the data still available.', \Kaleta\Core\ReportPage::monthName($month)), '', ['tab' => 'reports']);
    }

    /** The site address for the report's links and logo: the setting, not the Host header. */
    private function siteAddress(): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . rtrim($this->app->url(''), '/');
    }

    /** Checks the mail DNS records, the certificate and the domain registration right away (2.8, Core\DomainWatch). */
    protected function actionDomainCheck(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'health']);
        }
        $result = (new \Kaleta\Core\DomainWatch())->refresh($this->app);

        return $this->back(!empty($result['local'])
            ? 'The site runs on a local address – the certificate, the domain and the mail records are checked once it has its public address.'
            : 'The domain and mail check has run – the results are in the table above.', '', ['tab' => 'health']);
    }

    /** A test call to the webhook addresses, sent right away – the result is in the delivery log below. */
    protected function actionTestWebhook(): Response
    {
        $ids = $this->request->isPost() ? \Kaleta\Core\Webhook::test($this->app) : [];
        if ($ids === []) {
            return $this->back('First fill in and save at least one webhook address (https://).', '', ['tab' => 'webhooks'], 'chyba');
        }
        \Kaleta\Core\Webhook::processQueue($this->app->settings());
        $failed = (int) $this->db->value('SELECT COUNT(*) FROM {webhook_deliveries} WHERE id IN (' . implode(',', $ids) . ') AND delivered IS NULL');

        return $failed === 0
            ? $this->back('The test call has been delivered.', '', ['tab' => 'webhooks'])
            : $this->back('The test call was not delivered – the reason is in the delivery log. It will be retried automatically.', '', ['tab' => 'webhooks'], 'chyba');
    }

    /** Sends a failed webhook call once more. */
    protected function actionRetryWebhook(): Response
    {
        if ($this->request->isPost() && \Kaleta\Core\Webhook::retry($this->db, $this->request->postInt('id'))) {
            \Kaleta\Core\Webhook::processQueue($this->app->settings(), 1);
        }

        return $this->back('', '', ['tab' => 'webhooks']);
    }

    /** A new signing secret – the receivers must get it too, calls signed with the old one stop being accepted. */
    protected function actionNewWebhookSecret(): Response
    {
        if ($this->request->isPost()) {
            $this->app->settings()->set('webhook_secret', '');
            \Kaleta\Core\Webhook::secret($this->app->settings());
            \Kaleta\Admin\ChangeLog::write($this->app, 'settings', 'new webhook secret');
        }

        return $this->back('A new secret has been created. Paste it into every receiver that checks the signature.', '', ['tab' => 'webhooks']);
    }

    /** Asks the site's own pages what cookies they set (2.14, Core\Privacy::scan) – on demand; cron repeats it daily. */
    protected function actionCookieScan(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'cookies']);
        }
        $scan = \Kaleta\Core\Privacy::scan($this->app);

        return $this->back($scan['error'] !== '' ? t('The scan could not reach the site from the server (%s). The table shows what Kaleta and the known embeds set.', $scan['error'])
            : t('Scanned %d pages: %d cookies set by the server.', $scan['pages'], count($scan['cookies'])), '', ['tab' => 'cookies'], $scan['error'] !== '' ? 'chyba' : 'ok');
    }

    /** The record of processing assembled from the configuration (2.14, Core\Privacy) – a printable page, a template to review. */
    protected function actionProcessingRecord(): Response
    {
        return $this->view('record', 'Record of processing', ['sections' => \Kaleta\Core\Privacy::processingRecord($this->app)]);
    }

    /** Creates or updates the accessibility statement as a hidden draft page from the site audit (2.14, Core\Privacy). */
    protected function actionAccessibilityStatement(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'cookies']);
        }
        $id = \Kaleta\Core\Privacy::saveStatementDraft($this->app);

        return $this->back(t('The accessibility statement is ready as a hidden page – review it, then publish it: %s', $this->app->url('admin.php?module=pages&action=builder&id=' . $id)), '', ['tab' => 'cookies']);
    }

    protected function tab(string $tab): string
    {
        return isset(self::TABS[$tab]) ? $tab : 'general';
    }

    /**
     * Does the key have a type verifyValue() checks – a field of the admin form that is neither a secret nor a list? The
     * import of a Kaleta archive validates such settings like the form and MCP do (3.3.3, N55).
     */
    public static function checkable(string $key): bool
    {
        $type = self::fieldType($key);

        return $type !== null && !str_starts_with($type, 'tajne') && !str_starts_with($type, 'seznam');
    }

    /**
     * The type of a settings field; a language variant of a per-language setting (Settings::PER_LANGUAGE: site_name_de,
     * cookies_text_de, cookies_policy_url_de – 3.9) has the type of its base. null = not a field of the admin form.
     */
    private static function fieldType(string $key): ?string
    {
        foreach (self::FIELDS as $field) {
            if (isset($field[$key])) {
                return $field[$key];
            }
        }
        if (($m = \Kaleta\Core\Language::settingKey($key, \Kaleta\Core\Settings::PER_LANGUAGE)) !== null) {
            return self::fieldType($m[0]);
        }

        return null;
    }

    /**
     * A settings value validated the same way as in the admin form (for MCP). null = unknown key or invalid value.
     * Switches (type ano) take 1/0, true/false.
     */
    public static function verifyValue(string $key, string $value): ?string
    {
        $type = self::fieldType($key);
        if ($type === null && ($m = \Kaleta\Core\Language::settingKey($key, ['nazev_webu', 'popis_webu'])) !== null) {
            $type = $m[0] === 'nazev_webu' ? 'text' : 'radky';
        }
        if ($type === null || str_starts_with($type, 'tajne') || str_starts_with($type, 'seznam')) {
            return null;
        }

        return self::sanitize($type, trim($value), in_array(strtolower(trim($value)), ['1', 'true', 'ano'], true));
    }

    private static function sanitize(string $type, string $value, bool $checked): ?string
    {
        [$kind, $parameter] = explode(':', $type, 2) + [1 => ''];

        return match ($kind) {
            'ano' => $checked ? '1' : '0',
            'text' => mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value), 0, 500), // one line: every control character (a form feed too, N316-4) is a space
            'radky' => mb_substr($value, 0, 5000),
            'kod' => mb_substr($value, 0, 20000),
            'email' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null,
            'emaily' => (function () use ($value): ?string {
                $list = array_values(array_filter(preg_split('/[\s,;]+/', $value) ?: [], static fn (string $e): bool => $e !== ''));
                if (count($list) > \Kaleta\Core\MonthlyReport::MAX_RECIPIENTS || array_filter($list, static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) === false) !== []) {
                    return null; // one bad address rejects the field, so the owner sees it instead of a silently dropped recipient
                }

                return implode("\n", $list);
            })(),
            'url' => $value === '' || (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) ? rtrim($value) : null,
            'cislo' => (function () use ($value, $parameter): string {
                [$min, $max] = array_map(intval(...), explode(':', $parameter));

                return (string) max($min, min($max, (int) $value));
            })(),
            'vyber' => in_array($value, explode('|', $parameter), true) ? $value : null,
            'pasmo' => in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null,
            'vzor' => preg_match($parameter, $value) ? $value : null,
            'hodiny' => \Kaleta\Front\Company::parseOpeningHours($value) !== null ? mb_substr(trim($value), 0, 1000) : null,
            default => null,
        };
    }
}
