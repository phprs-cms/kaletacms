<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Built-in features (the admin screen "Features"; "extensions" in the code and the settings key) – optional parts of the
 * system that the administrator switches on and off.
 *
 * All of them are part of the package and are made in the Kaleta project. Code of other developers comes as add-ons
 * (Extension\Registry, 3.0) – not through this list. A disabled feature disappears from the administration menu and from
 * the site, the data stays.
 *
 * A feature that is new and switched on by default needs a migration that adds it to sites with a saved choice (0016);
 * one that is off by default needs a migration that switches it on where a site already uses it (0073: bookings and
 * whistleblowing, 3.2; 0135: customers and pipeline, 3.48).
 *
 * 3.48 (decision 3): three profiles – cumulative sets a person picks at install or in Features. A profile is derived from
 * the fourth column of CATALOG (the lowest profile that includes the key), so it can never list a key that does not exist,
 * and a future feature joins a profile by its own row. A profile is applied at install and by an explicit choice in
 * Features; it never changes a stored list by itself.
 */
final class Extensions
{
    /** key => [name, description, enabled by default, the lowest profile (PROFILES) that includes it – '' for none] */
    public const array CATALOG = [
        'novinky' => ['Novinky', 'News and blog: the %s listing with categories and tags, RSS, the News element in the builder and a link in the automatic menu.', true, 'website'],
        'poptavky' => ['Forms and enquiries', 'The Form element in the builder and the Enquiries inbox: submitted enquiries are stored, arrive by e-mail and can be passed to a colleague or a CRM.', true, 'enquiries'],
        // 3.48 (decision 3): the CRM of 3.28 and 3.31 as two features, off by default; migration 0135 switches them on where a
        // site has customers, deals, customised stages or people who were given the sections; Pipeline needs Customers (REQUIRES)
        'customers' => ['Customers', 'The contacts and companies your enquiries, bookings and newsletter sign-ups come from, in one record per e-mail address with a timeline, a responsible person, comments and tasks – in Workspace for the people you choose. Contacts are kept for every enquiry also while it is off.', false, 'office'],
        'pipeline' => ['Pipeline', 'Deals of your customers moving through the stages you set, on a board and in a list, with a value, a responsible person and a reason when lost. Needs Customers.', false, 'office'],
        'newsletter' => ['Newsletter', 'The Newsletter sign-up element in the builder: visitors enter an e-mail and confirm it by a link (double opt-in). Send them your latest news in an e-mail styled by the design system (through an SMTP server, while cron runs), pass confirmed subscribers to your mailing service (Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing, webhook), or export them to CSV.', false, ''],
        'bookings' => ['Bookings', 'Online booking of appointments: the Booking element in the builder, services and people with their hours, reminders by e-mail and the Bookings list in the administration.', false, ''],
        'statistika' => ['Statistics', 'Your own cookie-free traffic analytics.', true, 'website'],
        'presmerovani' => ['Redirects', '301 redirects from old addresses – essential after moving from another site.', true, 'website'],
        'jazyky' => ['Language versions of the site', 'A site in several languages: each further version (e.g. /cs/…) has its own pages, categories and news, a language switcher and hreflang tags. Pick the languages in Settings → Languages.', false, ''],
        'asistent' => ['Writing assistant (your own key)', 'In the builder, new sections from a description and text rewrites; in news, headlines, intro, SEO description, tags, proofreading, image descriptions and translation. Needs your own Claude, OpenAI, Google or Mistral key (below); text is sent only when you click an assistant button.', false, ''],
        // 3.34 (Core\Applications): off by default; migration 0120 switches it on where a Job openings collection exists
        'applications' => ['Job applications', 'Applications from your job openings apart from enquiries: their own list in Workspace for the people you choose, six statuses, CVs encrypted on the server, a retention of their own, and no customer record or deal.', false, ''],
        // 3.38 (Core\Prices, Core\Quotes): off by default; nobody but administrators gets the section by an update
        'quotes' => ['Quotes and internal prices', 'Prices for your products that nobody outside sees, in one or more price lists, and numbered quotes made from them – from an enquiry or by hand, with VAT – in Workspace for the people you choose. Prices never reach the site, search, exports or blueprints.', false, 'office'],
        'whistleblowing' => ['Whistleblowing', 'An internal reporting channel under the EU Whistleblower Directive: an encrypted report form at /_report that only the readers you choose can open.', false, ''],
        'fleet' => ['Fleet console', 'Makes this installation the console of your other Kaleta sites: they report to it every hour, it shows all of them on one screen sorted by what needs attention, checks that they are up and decides when they install a new version – test sites first, the rest two days later. It never gets into the sites.', false, ''],
        'claude' => ['Claude connection', 'MCP server at /mcp: Claude builds pages in the builder with your account\'s permissions, edits the header, footer, collections and look, and writes news. Pages and news are saved as drafts that you publish, the menu and look go into the draft look; settings apply straight away. Everyone creates their access token under My account.', true, 'website'],
    ];

    /** 3.48: the profiles, cumulative – each includes the keys of the ones before it (the order of this list). */
    public const array PROFILES = ['website' => 'Website', 'enquiries' => 'Website with enquiries', 'office' => 'Front office'];

    /** 3.48: a feature that needs another one – enabled() and save() always add it. key => the key it needs. */
    public const array REQUIRES = ['pipeline' => 'customers'];

    /** @return list<string> */
    public static function enabled(Settings $settings): array
    {
        $stored = $settings->get('extensions');
        if ($stored === '') {
            return self::closed(array_keys(array_filter(self::CATALOG, fn (array $r): bool => $r[2])));
        }

        return self::closed(array_values(array_intersect(explode(',', $stored), array_keys(self::CATALOG))));
    }

    public static function isEnabled(Settings $settings, string $key): bool
    {
        return $key === '' || in_array($key, self::enabled($settings), true);
    }

    /** @param list<string> $keys */
    public static function save(Settings $settings, array $keys): void
    {
        $keys = self::closed(array_values(array_filter(array_intersect($keys, array_keys(self::CATALOG)), fn (string $key): bool => self::unavailable($settings, $key) === null)));
        // an empty string means "default state", so an empty selection is saved as "-"
        $settings->set('extensions', $keys === [] ? '-' : implode(',', $keys));
    }

    /**
     * The keys of a profile (PROFILES) in the order of the catalogue: every key whose lowest profile is this one or one before
     * it, minus those unavailable() refuses on this site. An unknown profile has no keys. $settings null = no site yet (the
     * installer).
     *
     * @return list<string>
     */
    public static function profile(?Settings $settings, string $profile): array
    {
        return self::profileOf(self::CATALOG, $profile, fn (string $key): ?string => self::unavailable($settings, $key));
    }

    /**
     * profile() over any catalogue (unit 4300 passes one with a key that cannot be switched on). Pure.
     *
     * @param array<string, array<int, mixed>> $catalog rows as CATALOG
     * @param callable(string): ?string $unavailable why a key cannot be switched on, null when it can
     * @return list<string>
     */
    public static function profileOf(array $catalog, string $profile, callable $unavailable): array
    {
        $rank = array_search($profile, array_keys(self::PROFILES), true);
        if ($rank === false) {
            return [];
        }
        $keys = [];
        foreach ($catalog as $key => $row) {
            $from = array_search($row[3] ?? '', array_keys(self::PROFILES), true);
            if ($from !== false && $from <= $rank && $unavailable($key) === null) {
                $keys[] = $key;
            }
        }

        return self::closed($keys);
    }

    /**
     * Why a feature cannot be switched on on this site (an English text for t()), or null when it can. 3.48: every key can –
     * the seam for later features that need something first (HR the vault, invoices a Czech company). The installer, the
     * preselection in Features and save() ask it.
     */
    public static function unavailable(?Settings $settings, string $key): ?string // @phpstan-ignore return.unusedType (the seam: no feature has a condition in 3.48)
    {
        return null;
    }

    /**
     * The list with what REQUIRES adds: a key that needs another one brings it along (right before itself when missing).
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function closed(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $needed = self::REQUIRES[$key] ?? null;
            if ($needed !== null && !in_array($needed, $out, true) && !in_array($needed, $keys, true)) {
                $out[] = $needed;
            }
            if (!in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
