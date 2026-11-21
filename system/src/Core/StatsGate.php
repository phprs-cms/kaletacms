<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.50.1: whether the built-in statistics count at all and who never counts – the switch and the bot rule every counter
 * shares (page views, conversions, real-user speed, document downloads, the privacy text, the fleet heartbeat). It lived
 * in Front\Stats (isOn, enabled, isBot, BOTS), which forwards here; Core calls it here, so Core never names Front for it
 * (docs/design/code-layers.md).
 */
final class StatsGate
{
    /**
     * Who is never counted, by the browser's own description (case-insensitive). Generic words first – most crawlers,
     * monitors and link previewers call themselves a bot, crawler, spider or preview – then well-known names without
     * such a word: HTTP libraries and headless browsers, search engines' and AI companies' fetchers, link previews of chat
     * apps and social networks, performance and uptime tools. tools/unit-tests.php checks it against real user agents
     * (bots matched, Chrome, Safari, Firefox, Edge, Samsung Internet and Android WebView not).
     */
    public const string BOTS = '/'
        . 'bot|crawl|spider|slurp|preview|monitor|scan|check|feed|headless|lighthouse'                       // generic words
        . '|curl|wget|python|java\/|http|okhttp|axios|node-fetch|phantomjs|puppeteer|playwright|selenium'      // libraries, headless and test browsers (Go-http-client, python-requests)
        . '|facebookexternalhit|meta-externalagent|bytespider|google-inspectiontool|google-extended|googleother'
        . '|mediapartners-google|google-read-aloud|applebot|dataprovider|chatgpt-user|perplexity-user|claude-user'
        . '|claude-web|anthropic-ai|cohere-ai|mistralai-user|ccbot|amazonbot'                                // search engines, ads and AI fetchers
        // link previews; not "pinterest": the app's own browser says [Pinterest/iOS] for a person, its crawler says bot (N329-2)
        . '|whatsapp|telegrambot|slackbot|discordbot|skypeuripreview|linkedinbot|twitterbot|embedly|iframely|vkshare|viber'
        . '|chrome-lighthouse|ptst|gtmetrix|pingdom|uptimerobot|statuscake|site24x7|newrelic|datadog'        // speed and uptime tools
        . '/i';

    /**
     * The built-in statistics are on: the Statistics feature is the only switch (3.2 – the old "stats" setting is no longer
     * read; migration 0073 switched the feature off where that setting was off). Real-user speed follows the same switch.
     */
    public static function isOn(App $app): bool
    {
        return self::enabled($app->settings());
    }

    /** The same, from the settings alone (the privacy text, the fleet heartbeat, MCP). */
    public static function enabled(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, 'statistika');
    }

    /** A crawler, a monitoring tool or a test browser by its own description – never counted. */
    public static function isBot(string $userAgent): bool
    {
        return preg_match(self::BOTS, $userAgent) === 1;
    }
}
