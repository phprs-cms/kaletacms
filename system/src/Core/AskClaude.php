<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * "Ask Claude" on the dashboard (3.1): one box where staff write what they need – it becomes a request (Core\Requests),
 * which Claude works through as drafts the next time it works on the site (a scheduled run of the task "requests", or a
 * person asking it). Example requests show what Claude can do; they depend on the sections the person may open.
 *
 * Deliberately not a chat: the site never runs Claude itself and holds no Anthropic key (decided on 3 October 2026). The
 * box is a front door to the requests inbox; "Copy for the Claude app" puts the text with the site's address on the
 * clipboard for people who want to work with Claude right away in the Claude app.
 */
final class AskClaude
{
    /**
     * Example requests: key => [the admin module the person needs, the text (English source, shown through t())]. The
     * order is the order on the dashboard; at most MAX_EXAMPLES are shown.
     */
    public const array EXAMPLES = [
        // first what a drafts-only connection does completely (pages, news, reading); then what it answers with a proposal
        // in the note until 3.2 lets it save hidden items and proposed hours (save_collection_item, save_hours_exception)
        'news' => ['news', 'Write a news item about our new service and link it from the home page.'],
        'price_list' => ['pages', 'Update the price list from the attached PDF.'],
        'landing' => ['pages', 'Make a landing page for our spring campaign with an enquiry form.'],
        'links' => ['pages', 'Find broken links and missing image descriptions and fix them as drafts.'],
        'triage' => ['enquiries', 'Sort this week\'s enquiries and draft a reply to each.'],
        'leads' => ['stats', 'Why did we get fewer enquiries this month than last month?'],
        'translate' => ['pages', 'Translate the Services page into German.'],
        'report' => ['stats', 'Prepare a short report of this month for the owner.'],
        'topics' => ['stats', 'Suggest three news topics from what people search for on Google.'],
        'handover' => ['audit', 'Check the site before we hand it over to the client and list what is missing.'],
        'closed' => ['pages', 'We are closed from 24 to 26 December – put it on the site.'],
        'person' => ['collections', 'Add our new colleague to the team page – the photo and a short bio are attached.'],
    ];

    public const int MAX_EXAMPLES = 8;

    /** The longest title made from the text of a request. */
    public const int TITLE_LENGTH = 80;

    /** How many of the person's own requests the box lists. */
    public const int RECENT = 5;

    /**
     * The examples the person can use: those whose module they may open (the module list of Admin\Kernel::modules()).
     *
     * @param array<string, mixed> $modules ident => class
     * @return array<string, string> key => text (English source)
     */
    public static function examples(array $modules): array
    {
        $out = [];
        foreach (self::EXAMPLES as $key => [$module, $text]) {
            if (isset($modules[$module])) {
                $out[$key] = $text;
            }
        }

        return array_slice($out, 0, self::MAX_EXAMPLES, true);
    }

    /**
     * The title of a request written in the box: the first sentence or line, shortened at a word to TITLE_LENGTH
     * characters. Empty text gives an empty title (Requests::create refuses it).
     */
    public static function title(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        // the first sentence – a full stop, question or exclamation mark followed by a space (not "e.g. " or "24. 12.")
        if (preg_match('/^(.{12,}?[.!?])\s+\p{Lu}/u', $text, $m) === 1) {
            $text = $m[1];
        }
        if (mb_strlen($text) <= self::TITLE_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::TITLE_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > self::TITLE_LENGTH / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;:–-') . '…';
    }

    /**
     * The text for the Claude app: the site's address first, so Claude uses the right connector, then the request and
     * the rule about drafts.
     */
    public static function prompt(string $siteUrl, ?string $visitorAddress = null): string
    {
        // the texts for visitors follow the site's form of address, not the one the administration speaks to the person
        $address = $visitorAddress === null ? '' : ' ' . t($visitorAddress === 'informal' ? 'Write German texts for visitors with the informal “du”.' : 'Write German texts for visitors with the formal “Sie”.');

        return t('On my Kaleta site %s (use its connector):', rtrim($siteUrl, '/')) . "\n\n" . '{text}' . "\n\n"
            . t('Work as drafts and send me the preview links – do not publish anything.') . $address;
    }

    /**
     * The person's own latest requests for the box.
     *
     * @return list<array{id: int, title: string, status: string, updated_at: string}>
     */
    public static function recent(Db $db, int $userId, int $limit = self::RECENT): array
    {
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'status' => (string) $r['status'], 'updated_at' => (string) $r['updated_at']],
            $db->all('SELECT id, title, status, updated_at FROM {requests} WHERE author_id = ? ORDER BY id DESC LIMIT ' . max(1, min(20, $limit)), [$userId]));
    }

    /**
     * Whether a Claude connection exists now (3.18: state idle or active of Core\ClaudeConnection) – a valid OAuth
     * connection or personal token of an account that is not blocked, with the extension "claude" on. Until 3.18 this meant
     * "was ever used"; revoking every connection now says "not connected" again. The first call stays remembered in the
     * setting claude_first_used (Mcp\Server, 3.5) as history; a site connected before 3.5 is recognised by a used token or
     * an OAuth refresh token and the moment is remembered then.
     */
    public static function connected(Db $db, Settings $settings): bool
    {
        if ($settings->get('claude_first_used') === '') {
            $used = $db->value("SELECT MIN(COALESCE(pouzit, vytvoren)) FROM {api_tokeny} WHERE pouzit IS NOT NULL OR druh = 'obnova'");
            if ($used !== null) {
                $settings->set('claude_first_used', (string) $used);
                ClaudeConnection::forget();
            }
        }

        return ClaudeConnection::reachable(ClaudeConnection::status($db, $settings));
    }

    /**
     * When a Claude connection last called the site (any person, any connection), or null – 3.18: reliable for an OAuth
     * connection idle for more than an hour too (Core\ClaudeConnection).
     */
    public static function lastCall(Db $db, Settings $settings): ?string
    {
        return ClaudeConnection::status($db, $settings)['last_call'];
    }

    /** The address of the site's MCP endpoint for the Claude connector, with the scheme and host of the request. */
    public static function mcpUrl(App $app): string
    {
        return $app->request->origin() . $app->url('mcp');
    }

    /** Whether the connector address is on HTTPS – the Claude app adds custom connectors only there. */
    public static function secure(string $mcpUrl): bool
    {
        return str_starts_with($mcpUrl, 'https://');
    }

    /**
     * Whether a scheduled run works through the requests: the active schedule of the task "requests" due first, or null.
     *
     * @return array{cadence: string, day: int, time: string, next_due: ?string}|null
     */
    public static function routine(Db $db): ?array
    {
        try {
            $row = $db->one("SELECT cadence, day, time, next_due FROM {agent_schedules} WHERE active = 1 AND task = 'requests' ORDER BY next_due IS NULL, next_due LIMIT 1");
        } catch (\Throwable) {
            return null; // before the 2.17 migration
        }

        return $row === null ? null : ['cadence' => (string) $row['cadence'], 'day' => (int) $row['day'], 'time' => (string) $row['time'], 'next_due' => $row['next_due'] !== null ? (string) $row['next_due'] : null];
    }
}
