<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Site settings from the table ka_nastaveni (promenna => hodnota).
 */
final class Settings
{
    /** Default values; at the same time the list of all known settings. */
    public const array DEFAULTS = [
        'site_name' => 'Můj web',
        'site_url' => '',          // https://www.example.cz - links in e-mails, feeds and notifications are built from it (not from the Host header)
        'site_description' => '',
        'keywords' => '',
        'site_email' => '',
        'logo' => '',
        'favicon' => '',
        'company_name' => '',          // registered business name (s.r.o., sole trader…) – "Nastavení → Firma" (Business details); on the site the Company details element, for search engines schema.org
        'company_type' => 'LocalBusiness',
        'company_id' => '',
        'company_vat_id' => '',
        'company_register' => '',       // entry in the commercial register (court and file number, Handelsregister…) – imprint
        'company_representative' => '',       // who represents the company (managing director, Geschäftsführer…) – imprint
        'company_street' => '',
        'company_city' => '',
        'company_postcode' => '',
        'company_country' => '',        // 3.5: empty = not stated (was CZ; a country the site did not set is no longer claimed in the structured data)
        'company_phone' => '',
        'company_email' => '',          // the company's public contact e-mail (Company details element, schema.org); the site e-mail is not published
        'company_hours' => '',         // opening hours by lines: "Po–Pá 8:00–17:00" (Front\Company::openingHoursLines)
        'company_map' => '',
        'company_gps' => '',
        'enquiries_months' => '24',    // form enquiries older than this many months are deleted (personal data should not be kept forever); 0 = do not delete
        'triage_assistant' => '0',      // new enquiries are sorted by the AI assistant (2.12, Core\Triage) – off: the text goes to the AI provider
        'job_applications_months' => '0', // applications to job openings (enquiries from a Job openings item page, Core\Jobs) are deleted after this many months; 0 = like other enquiries
        'whistleblowing_enabled' => '0',  // the whistleblowing channel at /_report (2.14, Core\Whistleblowing)
        'whistleblowing_readers' => '',   // ids of the users who may open reports (comma-separated); nobody else sees their content
        'whistleblowing_intro' => '',     // the introduction above the report form (plain text)
        'whistleblowing_retention_months' => '24', // closed cases are deleted this many months after closing
        'look_draft' => '',           // draft of the look not published yet (JSON, Core\Look): design system, classes, menus
        'design_system' => '',        // colors, fonts, scale and dimensions of the site (JSON, Builder\DesignSystem); empty = default
        'brand_accent' => '',         // legacy: the site's main color, read only until design_system is saved
        'dark_mode' => 'vypnuto',   // dark appearance of the site: vypnuto (off) | auto (by the visitor's device) | tmavy (always dark)
        'theme_switcher' => '0',      // light / dark / by device switcher for visitors (in the header next to the languages)
        'brand_heading_font' => 'vychozi', // key from Front\SiteIdentity::TITLE_FONTS
        'brand_text_font' => 'vychozi',            // image instead of the text name in the header
        'footer_text' => '',
        'social_facebook' => '',
        'social_instagram' => '',
        'social_x' => '',
        'social_youtube' => '',
        'social_linkedin' => '',
        'time_zone' => 'Europe/Prague', // the site's time zone: news dates, scheduled publishing, statistics (App::applyTimezone)
        'site_language' => 'cs',         // site language: template texts, <html lang>, structured data (Core\Language)
        'german_register' => 'formal',        // form of address in the German texts for visitors: formal (Sie) | informal (du); the administration has its own choice per user
        'additional_languages' => '',         // further language versions at /en/, /de/… (Language versions extension), comma-separated codes
        'home_page' => '0',     // page (ka_stranky.ids) as the site's home page; 0 = news listing
        'news_per_page' => '9',        // news items per listing page
        'maintenance' => '0',              // maintenance mode: visitors see a notice, logged-in administrators see the site
        'maintenance_text' => 'Na webu právě pracujeme. Zkuste to prosím za chvíli.',
        'screen_mode' => '0',          // screen mode (2.11, Front\Screen): the kiosk page /screen/<secret> for a TV in the reception
        'screen_seconds' => '10',      // seconds per slide (5–60)
        'screen_collections' => '',    // addresses of the collections shown, comma-separated
        'screen_news' => '1',          // the latest news with their images
        'screen_hours' => '1',         // today's opening hours with "Open now, until 17:00"
        'screen_clock' => '1',
        'screen_secret' => '',         // the secret part of the address; created when the mode is switched on, shown only in the administration
        'webhook_url' => '',          // where to send the data of a just-published news item (Make, Zapier...)
        'webhook_enquiries' => '',    // where to send a new enquiry from a form (CRM, Make, Zapier, n8n…)
        'webhook_secret' => '',       // created by itself; signs webhook calls (X-Kaleta-Signature), shown only to administrators
        'require_2fa' => '',          // '' | spravci (administrators) | vsichni (everyone) – mandatory two-factor login
        'auto_suspend' => '',         // automatic suspension (2.8, Core\SecurityHygiene), a list: ucty = block accounts unused for 90 days, napojeni = revoke Claude connections unused for 60 days
        'page_cache' => '1',       // full-page cache for visitors who are not logged in (5 minutes)
        'link_check' => '1',     // look for broken links in news, page builds and collection items in the background
        'link_check_time' => '0',
        'redirect_auto' => '0',  // create redirects for addresses visitors could not find by itself (2.14, Core\RedirectMatcher)
        'redirect_auto_threshold' => '90', // only candidates with at least this score (50–100)
        'share_buttons' => '1',             // share links under a news item
        'social_networks' => 'facebook,linkedin', // networks a published news item gets social post drafts for (2.13, Core\SocialDrafts)
        'article_outline' => '1',       // table of contents of a news item from subheadings (from three H2)
        'related_news_auto' => '1',    // related news by tags and category
        'tasks_token' => '',          // secret part of the cron URL /tasks (the older /ulohy too)
        'stats' => '1',          // no longer read since 3.2: the Statistics feature (Core\Extensions 'statistika') is the only switch; kept for MCP update_settings
        'secret_key' => '',           // created by itself; signs links and salts the statistics hashes
        // SEO and GEO
        'indexing' => '1',          // 0 = the whole site noindex + Disallow in robots.txt
        'schema_org' => '1',          // structured data JSON-LD
        'share_image' => '',           // default image for sharing
        'share_image_auto' => '1',     // a page without any share image gets one drawn by the site (2.12, Front\ShareImage)
        'verification_google' => '',
        'verification_bing' => '',
        'robots_extra' => '',
        'ai_crawlers' => 'povolit',   // povolit | zakazat (GPTBot, ClaudeBot, PerplexityBot...)
        'url_slash' => 'bez',         // bez (/path) | s (/path/) | html (/path.html) – the preferred form is canonical, the others redirect
        'llms_txt' => '1',
        'data_migrations' => '',       // PHP data migrations that have run, by name (Core\Migration, 2.2)
        'imported_recheck' => '',      // imported content checked again with today's sanitizers, JSON state (Core\ImportRecheck, 3.3.3)
        'agency_name' => '',           // who built the site and looks after it – on the sign-in screen and in the admin (2.4)
        'agency_url' => '',
        'agency_email' => '',
        'agency_phone' => '',
        'agency_logo' => '',           // a path from Media (media/…) or the system (image/…)
        'lead_attribution' => '0',     // remember the first page, campaign and referring site of a visit for its leads – with consent to marketing (2.3)
        'claude_change_limit' => '0',    // guardrails for Claude (2.15, Core\Guardrails): changes per connection and hour, 0 = no limit
        'claude_destructive' => '1',     // 0 = no deleting, trashing or discarding through Claude
        'claude_protected_pages' => '',  // page ids Claude must not change
        'claude_apps_only' => '0',       // 1 = OAuth registration and sign-in only for Claude's own apps (Front\OAuth::CLAUDE_HOSTS, 3.3.4)
        'claude_instructions' => '',   // what the site owner wants Claude to keep to (brand voice, house rules) – every connection gets it (2.2)
        'security_contact' => '',      // who takes reports of security problems (e-mail or https page) – /.well-known/security.txt (2.1)
        'news_slug' => '',          // first segment of the news URLs in every language (blog → /blog/x); empty = novinky / news (Core\Routes)
        'news_slug_previous' => '', // earlier news_slug values, comma-separated: their URLs redirect to the current ones (kept by Settings::set)
        'slugs_per_language' => '0', // 3.9: a page, news item or category may share its slug with another language version (/kontakt, /en/kontakt); changed only by Core\Slug::switchPerLanguage
        'markdown_news' => '1',     // /novinky/<slug>.md
        'indexnow' => '0',            // after a news item is published, announce its URL to search engines (Bing, Seznam, Yandex)
        'indexnow_key' => '',
        // analytics
        'ga4_id' => '',
        'gtm_id' => '',                // Google Tag Manager container GTM-… (2.6): consent mode and conversion events in the data layer
        'matomo_url' => '',
        'matomo_id' => '0',
        'plausible_domain' => '',
        'head_code' => '',
        // privacy and cookies
        'cookies_mode' => 'vestavena', // zadna | vestavena | externi
        'cookies_external_code' => '',
        'cookies_text' => 'Používáme cookies k měření návštěvnosti. Pomáhají nám zlepšovat web.',
        'cookies_policy_url' => '',
        'marketing_code' => '',
        'cookies_log' => '1',    // record the consents given (evidence for a possible inspection)
        'captcha_provider' => '',      // an extra spam check for forms (2.6): hcaptcha | recaptcha | turnstile; empty = only the built-in protection
        'captcha_site_key' => '',
        'captcha_secret' => '',
        'captcha_fail_open' => '1',    // when the provider cannot be reached, accept the form on the built-in protection alone
        'cookies_log_months' => '36', // consent records older than this many months are deleted; 0 = do not delete
        'accessibility_toolbar' => '0', // the accessibility toolbar for visitors: larger text, contrast, underlined links, reduced motion (2.14, Core\Privacy)
        'enquiries_expiry' => 'delete', // what happens to an enquiry after enquiries_months: delete | anonymise (2.14, Core\Privacy)
        'booking_lead_hours' => '2',       // online booking (3.0, Core\Booking): the earliest time is this many hours ahead
        'booking_horizon_days' => '60',    // how far ahead a visitor may book
        'booking_cancel_hours' => '24',    // the customer's cancel link works until this many hours before the start
        'booking_reminder_hours' => '24',  // the reminder e-mail goes out this many hours before; 0 = none
        'booking_hold_hours' => '48',      // 3.3: a pending booking (a service that needs confirmation) holds its time this many hours
        'booking_pending_mail' => '',      // own text of the acknowledgement e-mail; empty = the built-in one (form of address and tone are yours)
        'booking_declined_mail' => '',     // own text of the decline e-mail; empty = the built-in one
        'booking_pending_thanks' => '',    // own thank-you message after a request; empty = the built-in one
        'health_token' => '',
        'alerts_enabled' => '1',       // alert e-mails when something breaks (2.8, Core\Alerts)
        'alerts_email' => '',          // where to; empty = the site e-mail
        'alerts_cursor' => '',         // the last event reported (internal)
        'alerts_last_sent' => '0',     // when the last alert went out (internal)
        'report_monthly' => '0',       // monthly report by e-mail (2.9, Core\MonthlyReport) – off, so an update never starts e-mailing by itself
        'report_recipients' => '',     // where to, comma or line separated (at most 10); empty = the site e-mail
        'report_last_month' => '',     // the month (Y-m) whose report went out last (internal)
        'firewall_enabled' => '0',     // the firewall of the public site (2.8, Core\Firewall)
        'firewall_proxy' => '',        // '' | cloudflare – where the visitor's address and country come from
        'firewall_ips' => '',          // blocked addresses and networks, one per line
        'firewall_countries' => '',    // blocked countries (ISO codes), only with a known country
        'firewall_rate' => '0',        // requests per minute from one address (0 = no limit)
        'firewall_probes' => '1',      // block for a day an address probing for other systems
        'update_probe' => '',          // one-time code while an update checks that the new version runs (internal, 2.8)
        'site_key_secret' => '',       // this site's Ed25519 key (2.9, Fleet\Keys) – internal, never exported or shown
        'fleet_console_url' => '',     // the fleet console this site reports to (2.9, Fleet\Link)
        'fleet_console_key' => '',     // its public key: the console's answers must be signed by it
        'fleet_console_name' => '',
        'fleet_site_id' => '',         // this site's number on the console
        'fleet_updates' => '0',        // the console decides when new versions install here
        'fleet_update_allowed' => '',  // the version the console allowed in its last answer
        'fleet_last_sent' => '',
        'fleet_last_error' => '',
        'fleet_versions' => '',        // console: versions it has seen and when (JSON, staged updates)
        'fleet_kit' => '0',            // receive the console's shared design kit as drafts (2.16, Fleet\Kit) – off by default
        'fleet_kit_version' => '0',    // the kit version applied here
        'fleet_kit_applied_at' => '',
        'fleet_kit_error' => '',       // why the last kit was refused (English, translated where shown)
        'domain_watch' => '',          // the last domain and mail check (JSON with the time of the check, Core\DomainWatch, 2.8) – internal, not editable
        'remote_backup' => 'vypnuto', // copy of the backup off the server: vypnuto (off) | ftp | s3
        'backup_host' => '',          // FTP server, or the S3 storage URL (s3.eu-central-1.amazonaws.com)
        'backup_user' => '',      // FTP user name / S3 access key
        'backup_password' => '',         // FTP password / S3 secret key (type "tajne")
        'backup_folder' => '',        // folder on FTP / bucket name
        'backup_region' => '',        // region S3 (eu-central-1…)
        'remote_backup_status' => '', // "YYYY-MM-DD HH:MM|ok" or the error text
        'auto_backups' => '1',         // automatic database backup: daily when something changed, otherwise weekly
        'backup_media' => '1',         // copy media/ to the off-site target too, incrementally (1.8)
        'remote_media_status' => '',   // "YYYY-MM-DD HH:MM|ok or error|files waiting"
        'media_sync_check' => '0',     // when the background media copy last ran
        'update_url' => '',      // URL of the aktualizace.json file; empty = the project's default source
        'update_channel' => 'latest', // 3.8: latest (a new minor every week) or stable (security fixes, a new minor about monthly) – Core\Updater
        'update_cache' => '',
        'auto_updates' => '1',    // install security releases automatically
        'update_attempt' => '',    // the version the background maintenance has already tried / announced
        'extensions' => '',            // enabled extensions (Core\Extensions); empty = default set
        'mail_mode' => 'mail',      // mail = the server's mail() function | smtp = own SMTP server
        'mail_from' => '',             // sender address; empty = the site e-mail
        'mail_reply_to' => '',        // address for replies (Reply-To)
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',    // tls (STARTTLS, port 587) | ssl (port 465) | zadne
        'smtp_user' => '',
        'smtp_password' => '',           // type "tajne": never written back into the form
        'notification_check' => '0',   // when the check for newly published news items last ran
        'tasks_last_run' => '0',       // when cron last called /tasks (/ulohy) (newsletters are sent only while cron runs)
        'newsletter_hourly_limit' => '300', // newsletters: at most this many e-mails per hour (the SMTP relay's limit)
        'data_cleanup' => '0',         // when the daily cleanup of personal data last ran (Core\Notifications)
        'ai_provider' => 'anthropic', // anthropic | openai | google | mistral (Core\Assistant::PROVIDERS)
        'ai_key' => '',              // API key of the AI assistant (never written back into the form)
        'ai_model' => 'claude-sonnet-5',
        'first_steps_hidden' => '0',      // the administrator hid the first steps on the dashboard
        'appearance_saved' => '',        // the administrator has already saved the site appearance (first steps do not count the appearance from the starter site)
        'claude_first_used' => '',       // when a Claude connection first called the site (3.5, Mcp\Server) – until then the dashboard leads with "Connect Claude"
        'cleaned_version' => '',       // the version after whose deployment the one-time cleanup of removed files has already run
        'db_version' => '1',            // number of the last applied migration (system/sql/migrace)
    ];

    /** Settings that can be filled in separately for each additional language version of the site (key_en, key_de…). */
    public const array PER_LANGUAGE = ['site_name', 'site_description'];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** The database the settings come from (the mail queue needs it). */
    public function db(): Db
    {
        return $this->db;
    }

    /** Default values that are text for visitors – they are translated into the site language until the administrator changes them. */
    private const array TRANSLATED_DEFAULTS = ['maintenance_text', 'cookies_text'];

    public function get(string $key): string
    {
        $this->values ??= $this->db->pairs('SELECT promenna, hodnota FROM {nastaveni}');
        // a language version (/en/, /de/…) may have its own site name and description; empty = as in the default language
        if (in_array($key, self::PER_LANGUAGE, true) && ($language = Language::siteColumn()) !== '' && ($this->values[$key . '_' . $language] ?? '') !== '') {
            return $this->values[$key . '_' . $language];
        }

        if (isset($this->values[$key])) {
            return $this->values[$key];
        }

        // default texts the visitor sees, in the site language (an English site must not show the Czech maintenance notice or cookie bar)
        return in_array($key, self::TRANSLATED_DEFAULTS, true) ? t(self::DEFAULTS[$key]) : (self::DEFAULTS[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function set(string $key, string $value): void
    {
        if ($key === 'news_slug' && ($old = $this->get('news_slug')) !== $value) {
            // the old news URLs keep redirecting to the new ones (Core\Routes::internalPath)
            $this->set('news_slug_previous', Routes::rememberSlug($this->get('news_slug_previous'), $old, $value));
        }
        $this->db->run(
            'INSERT INTO {nastaveni} (promenna, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)',
            [$key, $value],
        );
        if ($this->values !== null) {
            $this->values[$key] = $value;
        }
        if ($key === 'news_slug' || $key === 'news_slug_previous') {
            Routes::setNewsSlug(null); // read again (and checked) on the next use
        }
        if ($key === 'slugs_per_language') {
            Slug::setPerLanguage(null);
        }
    }
}
