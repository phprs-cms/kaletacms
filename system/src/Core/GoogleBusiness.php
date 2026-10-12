<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Connectors\Google;

/**
 * Google Business Profile (2.13, on the Google connection of Core\Connectors): what the site already knows goes to the
 * profile, what customers write on Google comes to the site.
 *
 *  - Push: the regular week from Business details (Core\Hours::week) as regularHours and the exceptions (closed days,
 *    changed hours, the next 12 months) as specialHours of the chosen location – whenever the hours change and once a day
 *    by the scheduler job 'gbp'; a published news item as a STANDARD post with a LEARN_MORE button (opt-in). Both go
 *    through the delivery queue (action prefix 'gbp'), so a Google outage is retried, never lost.
 *  - Pull: once a day the latest reviews into ka_google_reviews (name, stars, text, time, the owner's reply – what Google
 *    shows publicly, nothing more) and the profile's average rating and review count into the settings google_rating /
 *    google_reviews ({{fact.google_rating}}, {{fact.google_reviews}}). Reviews that disappeared from Google disappear here;
 *    disconnecting Google deletes them all (Google::disconnected).
 *
 * The APIs: Business Information v1 (accounts, locations, hours), My Business v4 (posts, reviews). The chosen location is
 * stored in the connection's config as accounts/{a}/locations/{l}; v1 addresses the location without its account.
 * The pure mapping functions take the week, the exceptions and "today", so they are tested without a database.
 */
final class GoogleBusiness
{
    /** Keys of the Google connection's config this feature owns (rendered by its own section of the Connections screen). */
    public const array CONFIG = ['location', 'post_news'];

    /** How many of the latest reviews are kept; older ones are Google's to show. */
    public const int KEEP_REVIEWS = 50;

    /** A Business Profile post summary may have at most this many characters. */
    public const int POST_SUMMARY_LENGTH = 1500;

    private const string ACCOUNTS_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
    private const string INFORMATION_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
    private const string V4_URL = 'https://mybusiness.googleapis.com/v4/';

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /* ---------- the connection ---------- */

    /** The location to sync (accounts/{a}/locations/{l}), '' when none is chosen. */
    public static function location(Db $db): string
    {
        $location = Connectors::config($db, Google::KEY)['location'] ?? '';

        return preg_match('#^accounts/[^/\s]+/locations/[^/\s]+$#D', $location) === 1 ? $location : '';
    }

    /** Connected to Google and a location is chosen – the only state in which anything is sent or fetched. */
    public static function ready(Db $db): bool
    {
        return self::location($db) !== '' && Connectors::isConnected($db, Google::KEY);
    }

    public static function postsNews(Db $db): bool
    {
        return (Connectors::config($db, Google::KEY)['post_news'] ?? '') === '1';
    }

    /**
     * The locations of every account the connected Google user manages, for the administrator to choose from
     * (stored in the setting google_locations so the chosen one keeps its title). Returns the error, or null.
     *
     * @return array{0: list<array{name: string, title: string}>, 1: ?string}
     */
    public static function loadLocations(App $app): array
    {
        $accounts = Connectors::request($app, Google::KEY, 'GET', self::ACCOUNTS_URL, null, [], 'gbp.accounts');
        if ($accounts['error'] !== '') {
            return [[], $accounts['error']];
        }
        $out = [];
        foreach (array_slice((array) ($accounts['json']['accounts'] ?? []), 0, 20) as $account) {
            $accountName = (string) ($account['name'] ?? '');
            if (!preg_match('#^accounts/[^/\s]+$#D', $accountName)) {
                continue;
            }
            $locations = Connectors::request($app, Google::KEY, 'GET', self::INFORMATION_URL . $accountName . '/locations?readMask=name,title&pageSize=100', null, [], 'gbp.locations');
            if ($locations['error'] !== '') {
                return [[], $locations['error']];
            }
            foreach ((array) ($locations['json']['locations'] ?? []) as $location) {
                $name = (string) ($location['name'] ?? '');
                if (preg_match('#^locations/[^/\s]+$#D', $name)) {
                    $out[] = ['name' => $accountName . '/' . $name, 'title' => mb_substr(trim(strip_tags((string) ($location['title'] ?? ''))), 0, 150)];
                }
            }
        }
        $app->settings()->set('google_locations', (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$out, null];
    }

    /** The locations loaded last time (loadLocations), for the select on the Connections screen. @return list<array{name: string, title: string}> */
    public static function knownLocations(Settings $s): array
    {
        $list = json_decode($s->get('google_locations'), true);

        return is_array($list) ? array_values(array_filter($list, fn (mixed $l): bool => is_array($l) && is_string($l['name'] ?? null) && is_string($l['title'] ?? null))) : [];
    }

    /** The title of the chosen location when it is known, otherwise its name. */
    public static function locationTitle(App $app): string
    {
        $location = self::location($app->db());
        foreach (self::knownLocations($app->settings()) as $l) {
            if ($l['name'] === $location && $l['title'] !== '') {
                return $l['title'];
            }
        }

        return $location;
    }

    /* ---------- hours → the profile ---------- */

    /**
     * The regular week as the Business Information API wants it: one period per range of a day (an empty week = no
     * periods, which clears the hours on Google – rather none than wrong ones).
     *
     * @param array<string, list<array{0: string, 1: string}>> $week Core\Hours::week
     * @return array{periods: list<array<string, mixed>>}
     */
    public static function regularHours(array $week): array
    {
        $periods = [];
        foreach (Hours::DAYS as $day) {
            foreach ($week[$day] ?? [] as [$opens, $closes]) {
                $googleDay = strtoupper($day);
                $periods[] = ['openDay' => $googleDay, 'openTime' => self::time($opens), 'closeDay' => $googleDay, 'closeTime' => self::time($closes)];
            }
        }

        return ['periods' => $periods];
    }

    /**
     * The exceptions as specialHours: Google takes one period per calendar day (a closed day, or one period per range of
     * changed hours), so a span of days is written out day by day – from today for the next 12 months, at most 400
     * periods (more would be a mistake in the data, not a holiday plan).
     *
     * @param list<array{from: string, to: string, closed: bool, hours: string}> $exceptions Core\Hours::exceptions
     * @return array{specialHourPeriods: list<array<string, mixed>>}
     */
    public static function specialHours(array $exceptions, \DateTimeImmutable $today): array
    {
        $first = $today->format('Y-m-d');
        $last = $today->modify('+12 months')->format('Y-m-d');
        $periods = [];
        foreach ($exceptions as $e) {
            $ranges = $e['closed'] ? [] : (Hours::parseRanges((string) $e['hours']) ?? []);
            if (!$e['closed'] && $ranges === []) {
                continue; // nothing Google could show
            }
            for ($day = new \DateTimeImmutable(max($e['from'], $first)); $day->format('Y-m-d') <= min($e['to'], $last); $day = $day->modify('+1 day')) {
                $date = ['year' => (int) $day->format('Y'), 'month' => (int) $day->format('n'), 'day' => (int) $day->format('j')];
                if ($e['closed']) {
                    $periods[] = ['startDate' => $date, 'endDate' => $date, 'closed' => true];
                }
                foreach ($ranges as [$opens, $closes]) {
                    $periods[] = ['startDate' => $date, 'openTime' => self::time($opens), 'endDate' => $date, 'closeTime' => self::time($closes)];
                }
                if (count($periods) >= 400) {
                    return ['specialHourPeriods' => array_slice($periods, 0, 400)];
                }
            }
        }

        return ['specialHourPeriods' => $periods];
    }

    /** "09:30" → TimeOfDay {hours, minutes}; "24:00" is how Google writes midnight at the end of a day. @return array{hours: int, minutes: int} */
    private static function time(string $hhmm): array
    {
        return ['hours' => (int) substr($hhmm, 0, 2), 'minutes' => (int) substr($hhmm, 3, 2)];
    }

    /**
     * The hours changed (Business details, an exception saved or deleted, update_settings): the profile gets them with
     * the next delivery run. One pending delivery is enough however many times the hours were saved.
     */
    public static function hoursChanged(App $app): void
    {
        $db = $app->db();
        if (!self::ready($db) || (int) $db->value("SELECT COUNT(*) FROM {connector_queue} WHERE action = 'gbp.hours' AND next_attempt IS NOT NULL") > 0) {
            return;
        }
        Connectors::queue($db, 'gbp.hours', ['location' => self::location($db)]);
    }

    /* ---------- news → a post ---------- */

    /** A news item was published (Core\Notifications): with the opt-in it becomes a post on the profile. */
    public static function newsPublished(App $app, int $idc): void
    {
        $db = $app->db();
        if (!self::ready($db) || !self::postsNews($db)) {
            return;
        }
        Connectors::queue($db, 'gbp.post', ['idc' => $idc, 'location' => self::location($db)]);
    }

    /**
     * The body of a STANDARD post from a news item: the title and the intro as plain text within Google's limit, the
     * image, a LEARN_MORE button to the news item. Pure – the test checks the shape.
     *
     * @param array{titulek: string, uvod: string, jazyk?: string} $news
     * @return array<string, mixed>
     */
    public static function postBody(array $news, string $url, string $imageUrl, string $language): array
    {
        $title = trim(html_entity_decode(strip_tags($news['titulek']), ENT_QUOTES | ENT_HTML5));
        $intro = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($news['uvod']), ENT_QUOTES | ENT_HTML5)));
        $summary = $title . ($intro !== '' ? "\n\n" . $intro : '');
        if (mb_strlen($summary) > self::POST_SUMMARY_LENGTH) {
            $summary = rtrim(mb_substr($summary, 0, self::POST_SUMMARY_LENGTH - 1)) . '…';
        }

        return array_filter([
            'languageCode' => $language,
            'summary' => $summary,
            'topicType' => 'STANDARD',
            'callToAction' => ['actionType' => 'LEARN_MORE', 'url' => $url],
            'media' => $imageUrl !== '' ? [['mediaFormat' => 'PHOTO', 'sourceUrl' => $imageUrl]] : null,
        ], fn (mixed $v): bool => $v !== null);
    }

    /* ---------- the delivery queue ---------- */

    /** Core\Connectors::HANDLERS: delivers gbp.hours (PATCH the location's hours) and gbp.post (POST a local post). '' = done. */
    public static function deliver(App $app, string $action, array $payload): string
    {
        $db = $app->db();
        $location = self::location($db);
        if ($location === '' || !Connectors::isConnected($db, Google::KEY)) {
            return ''; // disconnected or the location unset in the meantime: nothing to deliver any more
        }
        if ($action === 'gbp.hours') {
            $body = ['regularHours' => self::regularHours(Hours::week($app->settings())), 'specialHours' => self::specialHours(Hours::exceptions($db), new \DateTimeImmutable('today'))];
            $answer = Connectors::request($app, Google::KEY, 'PATCH', self::INFORMATION_URL . (string) substr($location, (int) strpos($location, '/locations/') + 1) . '?updateMask=regularHours,specialHours', $body, [], 'gbp.hours');

            return $answer['error'];
        }
        if ($action === 'gbp.post') {
            $news = $db->one('SELECT idc, titulek, uvod, obrazek, seo_link, jazyk FROM {novinky} WHERE idc = ? AND visible = 1 AND smazano IS NULL AND datum <= ?', [(int) ($payload['idc'] ?? 0), Clock::now()]);
            if ($news === null) {
                return ''; // unpublished or deleted before the delivery: no post
            }
            $origin = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
            $image = (string) $news['obrazek'];
            $imageUrl = $image === '' ? '' : (preg_match('#^https?://#', $image) ? $image : $origin . $app->request->basePath() . '/' . ltrim($image, '/'));
            $language = (string) $news['jazyk'] !== '' ? (string) $news['jazyk'] : Language::defaults($app->settings());
            $answer = Connectors::request($app, Google::KEY, 'POST', self::V4_URL . $location . '/localPosts',
                self::postBody($news, $origin . $app->newsItemUrl((string) $news['seo_link'], (string) $news['jazyk']), $imageUrl, $language), [], 'gbp.post');

            return $answer['error'];
        }

        return 'Unknown Business Profile action ' . $action;
    }

    /* ---------- reviews ← the profile ---------- */

    /**
     * The daily job: the hours go out (through the queue), the latest reviews and the rating come in. Skipped quietly
     * when Google is not connected or no location is chosen.
     */
    public static function run(App $app): string
    {
        if (!self::ready($app->db())) {
            return 'not connected';
        }
        self::hoursChanged($app);
        $error = self::pullReviews($app);

        return $error ?? 'hours queued, reviews ' . (int) $app->db()->value('SELECT COUNT(*) FROM {google_reviews}');
    }

    /**
     * Fetches the latest reviews of the location and keeps only them: a review Google no longer returns is deleted. The
     * profile's average rating and review count (over all reviews, not only the kept ones) go to the settings. Returns
     * the error, or null.
     */
    public static function pullReviews(App $app): ?string
    {
        $db = $app->db();
        $location = self::location($db);
        $answer = Connectors::request($app, Google::KEY, 'GET', self::V4_URL . $location . '/reviews?pageSize=' . self::KEEP_REVIEWS . '&orderBy=updateTime%20desc', null, [], 'gbp.reviews');
        if ($answer['error'] !== '') {
            return $answer['error'];
        }
        $now = date('Y-m-d H:i:s');
        $kept = [];
        foreach (array_slice((array) ($answer['json']['reviews'] ?? []), 0, self::KEEP_REVIEWS) as $r) {
            $row = self::reviewRow(is_array($r) ? $r : [], $now);
            if ($row === null) {
                continue;
            }
            $kept[] = $row['review_id'];
            $db->run('INSERT INTO {google_reviews} (review_id, author, stars, comment, reviewed_at, reply, replied_at, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE author = VALUES(author), stars = VALUES(stars), comment = VALUES(comment), reviewed_at = VALUES(reviewed_at), reply = VALUES(reply), replied_at = VALUES(replied_at), fetched_at = VALUES(fetched_at)',
                [$row['review_id'], $row['author'], $row['stars'], $row['comment'], $row['reviewed_at'], $row['reply'], $row['replied_at'], $now]);
        }
        // everything Google did not return this time goes (by id – two fetches within one second must not keep a deleted review)
        $db->run('DELETE FROM {google_reviews}' . ($kept !== [] ? ' WHERE review_id NOT IN (' . implode(', ', array_fill(0, count($kept), '?')) . ')' : ''), $kept);
        $s = $app->settings();
        $rating = $answer['json']['averageRating'] ?? null;
        $s->set('google_rating', is_numeric($rating) ? (string) round((float) $rating, 1) : '');
        $s->set('google_reviews', (string) max(0, (int) ($answer['json']['totalReviewCount'] ?? count($kept))));
        $s->set('google_reviews_synced', $now);
        \Kaleta\Front\Cache::clear();

        return null;
    }

    /**
     * One review from the v4 API as a row: the public display name, the stars (ONE…FIVE), the comment and the owner's
     * reply; null when it has no id or no stars.
     *
     * @param array<string, mixed> $r
     * @return array{review_id: string, author: string, stars: int, comment: string, reviewed_at: string, reply: ?string, replied_at: ?string}|null
     */
    public static function reviewRow(array $r, string $now): ?array
    {
        $id = (string) ($r['reviewId'] ?? '');
        $stars = self::STARS[(string) ($r['starRating'] ?? '')] ?? 0;
        if ($id === '' || $stars === 0 || strlen($id) > 190) {
            return null;
        }
        $reviewer = is_array($r['reviewer'] ?? null) ? $r['reviewer'] : [];
        $reply = is_array($r['reviewReply'] ?? null) ? $r['reviewReply'] : null;
        $when = fn (mixed $iso): ?string => is_string($iso) && ($t = strtotime($iso)) !== false ? date('Y-m-d H:i:s', $t) : null;

        return ['review_id' => $id, 'author' => mb_substr(trim(strip_tags((string) ($reviewer['displayName'] ?? ''))), 0, 190), 'stars' => $stars,
            'comment' => mb_substr(trim(strip_tags((string) ($r['comment'] ?? ''))), 0, 5000), 'reviewed_at' => $when($r['createTime'] ?? null) ?? $now,
            'reply' => $reply !== null ? mb_substr(trim(strip_tags((string) ($reply['comment'] ?? ''))), 0, 5000) : null, 'replied_at' => $reply !== null ? $when($reply['updateTime'] ?? null) : null];
    }

    /**
     * The newest reviews with at least $minStars stars, $count of them (Google's own rule for showing reviews: a reply
     * stays with its review). [] when Google is not connected – nothing stale is shown.
     *
     * @return list<array{review_id: string, author: string, stars: int, comment: string, reviewed_at: string, reply: ?string, replied_at: ?string}>
     */
    public static function reviews(Db $db, int $count, int $minStars): array
    {
        if (!Connectors::isConnected($db, Google::KEY)) {
            return [];
        }
        try {
            $rows = $db->all('SELECT review_id, author, stars, comment, reviewed_at, reply, replied_at FROM {google_reviews} ORDER BY reviewed_at DESC, review_id');
        } catch (\Throwable) {
            return []; // before the 2.13 migration
        }

        return self::filter(array_map(fn (array $r): array => ['review_id' => (string) $r['review_id'], 'author' => (string) $r['author'], 'stars' => (int) $r['stars'], 'comment' => (string) $r['comment'],
            'reviewed_at' => (string) $r['reviewed_at'], 'reply' => $r['reply'] !== null ? (string) $r['reply'] : null, 'replied_at' => $r['replied_at'] !== null ? (string) $r['replied_at'] : null], $rows), $count, $minStars);
    }

    /**
     * The newest $count reviews with at least $minStars stars from rows sorted newest first – pure.
     *
     * @template T of array{stars: int}
     * @param list<T> $rows
     * @return list<T>
     */
    public static function filter(array $rows, int $count, int $minStars): array
    {
        return array_slice(array_values(array_filter($rows, fn (array $r): bool => $r['stars'] >= max(1, min(5, $minStars)))), 0, max(1, min(50, $count)));
    }

    /** The profile's rating and review count as last fetched (null rating = never). @return array{rating: ?float, count: int, synced: string} */
    public static function summary(Settings $s): array
    {
        $rating = $s->get('google_rating');

        return ['rating' => is_numeric($rating) ? (float) $rating : null, 'count' => $s->int('google_reviews'), 'synced' => $s->get('google_reviews_synced')];
    }

    /** Google was disconnected: the reviews and the rating are Google's data, kept only while the connection stands. */
    public static function forget(App $app): void
    {
        try {
            $app->db()->run('DELETE FROM {google_reviews}');
        } catch (\Throwable) {
            // before the 2.13 migration
        }
        $s = $app->settings();
        foreach (['google_rating', 'google_reviews', 'google_reviews_synced', 'google_locations'] as $key) {
            $s->set($key, '');
        }
        \Kaleta\Front\Cache::clear();
    }
}
