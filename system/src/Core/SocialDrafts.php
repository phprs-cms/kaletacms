<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Front\ShareImage;

/**
 * Social post drafts (2.13). When a news item is published – in the administration, through Claude or by the scheduler –
 * a draft per chosen network (Settings → General, default Facebook and LinkedIn) is written from the title and the lead:
 * hashtags from the tags, the news image (or the picture the site draws, Front\ShareImage) and a tracked link, so the
 * statistics show the visits from each network (utm_source = the network, utm_campaign = the news slug). A person edits,
 * copies and posts them – the site never posts anywhere and calls no outside service; the AI assistant rewrites them only
 * on a click. Stored in ka_social_drafts (one row per network, so Claude can polish one draft by its id).
 *
 * The text builders are pure functions, tested without a database.
 */
final class SocialDrafts
{
    /** network key => name */
    public const array NETWORKS = ['facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'x' => 'X', 'instagram' => 'Instagram'];

    /** For the settings field type (seznam:…). */
    public const string NETWORK_KEYS = 'facebook|linkedin|x|instagram';

    public const string DEFAULT_NETWORKS = 'facebook,linkedin';

    /** X counts every link as 23 characters, whatever its length. */
    public const int X_LIMIT = 280;
    public const int X_LINK_LENGTH = 23;

    public const int MAX_HASHTAGS = 3;
    public const int MAX_TEXT = 3000;

    /** How much of the lead each network gets (characters); X takes what is left under its limit. */
    private const array LEAD_LENGTH = ['facebook' => 500, 'linkedin' => 700, 'instagram' => 500];

    /**
     * The networks chosen in the settings (unknown ones are dropped), in the order of NETWORKS.
     *
     * @return list<string>
     */
    public static function networks(Settings $s): array
    {
        return self::chosen($s->get('social_networks'));
    }

    /** @return list<string> */
    public static function chosen(string $setting): array
    {
        $wanted = array_map(trim(...), explode(',', $setting));

        return array_values(array_filter(array_keys(self::NETWORKS), fn (string $k): bool => in_array($k, $wanted, true)));
    }

    /** The news URL with the campaign parameters the statistics already count (Front\Stats, ka_stat_kampane). */
    public static function trackedLink(string $url, string $network, string $slug): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'utm_source=' . rawurlencode($network) . '&utm_medium=social&utm_campaign=' . rawurlencode($slug);
    }

    /**
     * Hashtags from the tags: "Nová hala" => #NovaHala, at most MAX_HASHTAGS, without duplicates.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public static function hashtags(array $tags, int $max = self::MAX_HASHTAGS): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $slug = slugify((string) $tag, 60);
            if ($slug === 'n-a') {
                continue;
            }
            $hashtag = '#' . implode('', array_map(ucfirst(...), explode('-', $slug)));
            if (!in_array($hashtag, $out, true)) {
                $out[] = $hashtag;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * The draft text of one network from the title, the plain-text lead, the hashtags and the tracked link.
     * Instagram gets no link (it is not clickable there): "link in bio". X fits its limit with the link counted as 23.
     *
     * @param list<string> $hashtags
     */
    public static function text(string $network, string $title, string $lead, array $hashtags, string $link): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $lead = trim((string) preg_replace('/\s+/u', ' ', $lead));
        $tags = implode(' ', $hashtags);
        if ($network === 'x') {
            // the budget: the limit minus the link and the hashtags with their separating line breaks
            $budget = self::X_LIMIT - ($link !== '' ? self::X_LINK_LENGTH + 1 : 0) - ($tags !== '' ? mb_strlen($tags) + 1 : 0);
            $title = self::shorten($title, $budget);
            $lead = self::shorten($lead, $budget - mb_strlen($title) - 1);
            $parts = [$title, $lead, $tags, $link];

            return implode("\n", array_filter($parts, fn (string $p): bool => $p !== ''));
        }
        $lead = self::shorten($lead, self::LEAD_LENGTH[$network] ?? 500);
        $parts = [$title, $lead, $tags, $network === 'instagram' ? t('Link in bio') : $link];

        return implode("\n\n", array_filter($parts, fn (string $p): bool => $p !== ''));
    }

    /** The length X counts: every link is 23 characters. */
    public static function xLength(string $text): int
    {
        $withoutLinks = preg_replace('#https?://\S+#i', str_repeat('x', self::X_LINK_LENGTH), $text) ?? $text;

        return mb_strlen($withoutLinks);
    }

    /** Cuts a text at a word boundary with an ellipsis; '' when nothing fits. */
    public static function shorten(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        if ($max < 4) {
            return '';
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:-–") . '…';
    }

    /** The lead as plain text (the editor stores HTML). */
    public static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags(preg_replace('#</(p|h[2-4]|li|blockquote)>#i', ' ', $html) ?? $html), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Writes the drafts of a published news item for the chosen networks that have none yet. Nothing for a draft, a
     * scheduled or a trashed news item. Returns how many drafts were created. Safe to call again (the existing ones stay).
     */
    public static function prepare(App $app, int $idc): int
    {
        $db = $app->db();
        $c = $db->one('SELECT idc, titulek, uvod, seo_link, jazyk, obrazek, seo_titulek FROM {novinky} WHERE idc = ? AND visible = 1 AND datum <= NOW() AND smazano IS NULL', [$idc]);
        if ($c === null) {
            return 0;
        }
        try {
            $existing = array_column($db->all('SELECT network FROM {social_drafts} WHERE idc = ?', [$idc]), 'network');
        } catch (\Throwable) {
            return 0; // before the 2.13 migration
        }
        $networks = array_values(array_diff(self::networks($app->settings()), $existing));
        if ($networks === []) {
            return 0;
        }
        $hashtags = self::hashtags(self::tags($db, $idc));
        $url = self::origin($app) . $app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk']);
        $image = self::image($app, $c);
        $lead = self::plain((string) $c['uvod']);
        $now = Clock::now();
        foreach ($networks as $network) {
            $link = self::trackedLink($url, $network, (string) $c['seo_link']);
            $db->insert('social_drafts', ['idc' => $idc, 'network' => $network, 'text' => mb_substr(self::text($network, (string) $c['titulek'], $lead, $hashtags, $link), 0, self::MAX_TEXT),
                'link' => mb_substr($link, 0, 500), 'image' => mb_substr($image, 0, 500), 'created_at' => $now]);
        }

        return count($networks);
    }

    /**
     * The drafts of a news item in the order of NETWORKS.
     *
     * @return list<array{id: int, idc: int, network: string, network_name: string, text: string, link: string, image: string, created_at: string, posted_at: ?string}>
     */
    public static function forNews(Db $db, int $idc): array
    {
        try {
            $rows = $db->all('SELECT * FROM {social_drafts} WHERE idc = ? ORDER BY FIELD(network, ' . implode(', ', array_fill(0, count(self::NETWORKS), '?')) . '), id', [$idc, ...array_keys(self::NETWORKS)]);
        } catch (\Throwable) {
            return []; // before the 2.13 migration
        }

        return array_map(self::row(...), $rows);
    }

    /** @return array{id: int, idc: int, network: string, network_name: string, text: string, link: string, image: string, created_at: string, posted_at: ?string}|null */
    public static function find(Db $db, int $id): ?array
    {
        try {
            $row = $id > 0 ? $db->one('SELECT * FROM {social_drafts} WHERE id = ?', [$id]) : null;
        } catch (\Throwable) {
            return null;
        }

        return $row === null ? null : self::row($row);
    }

    /** A person or Claude changed the text; returns the error, or null. The X limit is kept (the link counts as 23). */
    public static function update(Db $db, int $id, string $text): ?string
    {
        $draft = self::find($db, $id);
        if ($draft === null) {
            return 'The draft does not exist.';
        }
        $text = trim(strip_tags(str_replace("\r", '', $text)));
        if ($text === '') {
            return 'The post needs a text.';
        }
        // fixed sentences, so the administration can translate them with t()
        if (mb_strlen($text) > self::MAX_TEXT) {
            return 'The post is too long (at most 3000 characters).';
        }
        if ($draft['network'] === 'x' && self::xLength($text) > self::X_LIMIT) {
            return 'X allows 280 characters with a link counted as 23 – this post is longer.';
        }
        $db->update('social_drafts', ['text' => $text], ['id' => $id]);

        return null;
    }

    /** "Mark as posted" (and back): copied_at remembers when the person posted it. */
    public static function markPosted(Db $db, int $id, bool $posted): void
    {
        $db->update('social_drafts', ['copied_at' => $posted ? Clock::now() : null], ['id' => $id]);
    }

    /**
     * "Suggest with the assistant" (only on a click): the AI assistant rewrites the drafts of a news item from its title,
     * lead and text; the hashtags and the tracked link are added back by the site. Returns the error for the user, or null.
     */
    public static function suggest(App $app, int $idc): ?string
    {
        $assistant = new Assistant($app->settings());
        if (!$assistant->isReady()) {
            return 'The writing assistant is not enabled or the key is missing (Features).';
        }
        $db = $app->db();
        $c = $db->one('SELECT titulek, uvod, text FROM {novinky} WHERE idc = ?', [$idc]);
        $drafts = self::forNews($db, $idc);
        if ($c === null || $drafts === []) {
            return 'There are no drafts to rewrite.';
        }
        try {
            $suggestions = $assistant->suggest('prispevky', ['titulek' => (string) $c['titulek'], 'uvod' => (string) $c['uvod'], 'text' => (string) $c['text']])['navrhy'];
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $order = array_keys(self::NETWORKS);
        $hashtags = self::hashtags(self::tags($db, $idc));
        foreach ($drafts as $draft) {
            $suggested = trim((string) ($suggestions[array_search($draft['network'], $order, true)] ?? ''));
            if ($suggested !== '') {
                self::update($db, $draft['id'], self::finish($draft['network'], $suggested, $hashtags, $draft['link']));
            }
        }

        return null;
    }

    /**
     * A text written by the assistant (or pasted by a person) made complete for its network: without links of its own, with
     * the hashtags and the tracked link (Instagram: "link in bio"), within the X limit.
     *
     * @param list<string> $hashtags
     */
    public static function finish(string $network, string $text, array $hashtags, string $link): string
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', (string) preg_replace('#\s*https?://\S+#i', '', strip_tags($text))));
        $tags = implode(' ', $hashtags);
        if ($network === 'x') {
            $budget = self::X_LIMIT - ($link !== '' ? self::X_LINK_LENGTH + 1 : 0) - ($tags !== '' ? mb_strlen($tags) + 1 : 0);

            return implode("\n", array_filter([self::shorten($text, $budget), $tags, $link], fn (string $p): bool => $p !== ''));
        }

        return implode("\n\n", array_filter([$text, $tags, $network === 'instagram' ? t('Link in bio') : $link], fn (string $p): bool => $p !== ''));
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, idc: int, network: string, network_name: string, text: string, link: string, image: string, created_at: string, posted_at: ?string}
     */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'idc' => (int) $r['idc'], 'network' => (string) $r['network'], 'network_name' => self::NETWORKS[$r['network']] ?? (string) $r['network'],
            'text' => (string) $r['text'], 'link' => (string) $r['link'], 'image' => (string) $r['image'], 'created_at' => (string) $r['created_at'], 'posted_at' => $r['copied_at'] === null ? null : (string) $r['copied_at']];
    }

    /**
     * The tags of the news item in the order they were given (tag ids grow as tags are created): the first ones matter most.
     *
     * @return list<string>
     */
    private static function tags(Db $db, int $idc): array
    {
        return array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY cs.ids', [$idc]), 'nazev');
    }

    /** The image to attach: the news image, else the site's sharing image, else the picture the site draws (2.12); '' = none. */
    private static function image(App $app, array $c): string
    {
        $root = self::origin($app) . $app->request->basePath() . '/';
        foreach ([(string) $c['obrazek'], $app->settings()->get('share_image')] as $image) {
            if ($image !== '') {
                return preg_match('#^https?://#i', $image) ? $image : $root . ltrim($image, '/');
            }
        }

        return ShareImage::url($app, Facts::fillText((string) ($c['seo_titulek'] !== '' ? $c['seo_titulek'] : $c['titulek']), $app)) ?? '';
    }

    /** Scheme and host of the site: the site address from Settings, not the Host header (the scheduler may run from cron). */
    private static function origin(App $app): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
    }
}
