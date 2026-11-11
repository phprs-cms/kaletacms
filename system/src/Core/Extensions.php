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
 * whistleblowing, 3.2).
 */
final class Extensions
{
    /** key => [name, description, enabled by default] */
    public const array CATALOG = [
        'novinky' => ['Novinky', 'News and blog: the %s listing with categories and tags, RSS, the News element in the builder and a link in the automatic menu.', true],
        'poptavky' => ['Forms and enquiries', 'The Form element in the builder and the Enquiries inbox: submitted enquiries are stored, arrive by e-mail and can be passed to a colleague or a CRM.', true],
        'newsletter' => ['Newsletter', 'The Newsletter sign-up element in the builder: visitors enter an e-mail and confirm it by a link (double opt-in). Send them your latest news in an e-mail styled by the design system (through an SMTP server, while cron runs), pass confirmed subscribers to your mailing service (Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing, webhook), or export them to CSV.', false],
        'bookings' => ['Bookings', 'Online booking of appointments: the Booking element in the builder, services and people with their hours, reminders by e-mail and the Bookings list in the administration.', false],
        'statistika' => ['Statistics', 'Your own cookie-free traffic analytics.', true],
        'presmerovani' => ['Redirects', '301 redirects from old addresses – essential after moving from another site.', true],
        'jazyky' => ['Language versions of the site', 'A site in several languages: each further version (e.g. /cs/…) has its own pages, categories and news, a language switcher and hreflang tags. Pick the languages in Settings → Languages.', false],
        'asistent' => ['Writing assistant (your own key)', 'In the builder, new sections from a description and text rewrites; in news, headlines, intro, SEO description, tags, proofreading, image descriptions and translation. Needs your own Claude, OpenAI, Google or Mistral key (below); text is sent only when you click an assistant button.', false],
        // 3.34 (Core\Applications): off by default; migration 0120 switches it on where a Job openings collection exists
        'applications' => ['Job applications', 'Applications from your job openings apart from enquiries: their own list in Workspace for the people you choose, six statuses, CVs encrypted on the server, a retention of their own, and no customer record or deal.', false],
        'whistleblowing' => ['Whistleblowing', 'An internal reporting channel under the EU Whistleblower Directive: an encrypted report form at /_report that only the readers you choose can open.', false],
        'fleet' => ['Fleet console', 'Makes this installation the console of your other Kaleta sites: they report to it every hour, it shows all of them on one screen sorted by what needs attention, checks that they are up and decides when they install a new version – test sites first, the rest two days later. It never gets into the sites.', false],
        'claude' => ['Claude connection', 'MCP server at /mcp: Claude builds pages in the builder with your account\'s permissions, edits the header, footer, collections and look, and writes news. Pages and news are saved as drafts that you publish, the menu and look go into the draft look; settings apply straight away. Everyone creates their access token under My account.', true],
    ];

    /** @return list<string> */
    public static function enabled(Settings $settings): array
    {
        $stored = $settings->get('extensions');
        if ($stored === '') {
            return array_keys(array_filter(self::CATALOG, fn (array $r): bool => $r[2]));
        }

        return array_values(array_intersect(explode(',', $stored), array_keys(self::CATALOG)));
    }

    public static function isEnabled(Settings $settings, string $key): bool
    {
        return $key === '' || in_array($key, self::enabled($settings), true);
    }

    /** @param list<string> $keys */
    public static function save(Settings $settings, array $keys): void
    {
        $keys = array_values(array_intersect($keys, array_keys(self::CATALOG)));
        // an empty string means "default state", so an empty selection is saved as "-"
        $settings->set('extensions', $keys === [] ? '-' : implode(',', $keys));
    }
}
