<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site search (3.52, B1–B3): one list ranked by relevance across pages, collection items with their own page and news,
 * for /hledani and MCP search_site. Only what a visitor of the language version sees: published, without a password,
 * without noindex (news too), not in the trash.
 *
 * The database picks the candidates (Db::likeWords on the stems of the words, at most CANDIDATES of each kind), PHP ranks
 * them: a word in the title weighs most, the exact form and the whole word add a little, then the number of occurrences
 * (at most 20 a word), a fresh news item a little more. A text word matches when it starts with the query word or its stem
 * (Search::terms) – "IT" finds "IT", not "sit amet". When no candidate has every word, the ones with some of them are
 * shown and the result says so (partial).
 */
final class SiteSearch
{
    /** A query shorter than this (in characters) is not searched; the forms of the site ask for it too. */
    public const int MIN_LENGTH = 2;

    /** At most this many candidates of each kind come from the database. */
    public const int CANDIDATES = 300;

    /** At most this many words of a query are searched (Db::likeWords). */
    public const int MAX_WORDS = 6;

    /** The longest query the statistics keep (B5, {search_queries}.query). */
    public const int QUERY_LENGTH = 60;

    /** About this many words around the matches form a snippet. */
    private const int SNIPPET_WORDS = 30;

    /**
     * The words of a query with the beginnings each may have in the text: [word, terms]. Normalized (Search::normalize),
     * words of at least MIN_LENGTH characters, at most MAX_WORDS, without repeats.
     *
     * @return list<array{word: string, terms: list<string>}>
     */
    public static function words(string $q, string $language): array
    {
        $words = array_values(array_unique(array_filter(explode(' ', Search::normalize(mb_substr($q, 0, 100))), fn (string $w): bool => mb_strlen($w) >= self::MIN_LENGTH)));

        return array_map(fn (string $w): array => ['word' => $w, 'terms' => Search::terms($w, $language)], array_slice($words, 0, self::MAX_WORDS));
    }

    /**
     * The results for a query in one language version ('' = the default one): every result ranked, the best first –
     * [type (page | collection_item | news), label (the kind for the visitor), title, path (for App::url), text (plain,
     * for the snippet; news: the intro), id, date (news)]. partial = no result has every word, these have some of them.
     *
     * @return array{results: list<array<string, mixed>>, partial: bool, words: list<array{word: string, terms: list<string>}>}
     */
    public static function find(App $app, string $q, string $column): array
    {
        $language = Language::ofContent($app->settings(), $column);
        $words = self::words($q, $language);
        if ($words === []) {
            return ['results' => [], 'partial' => false, 'words' => []];
        }
        $results = self::rank($words, self::candidates($app, $words, $column, true), true);
        $partial = $results === [] && count($words) > 1;
        if ($partial) {
            $results = self::rank($words, self::candidates($app, $words, $column, false), false);
        }

        return ['results' => $results, 'partial' => $partial && $results !== [], 'words' => $words];
    }

    /**
     * Ranks candidates [title, text (plain), bonus?, …]: with $all each must have every word in the title or the text,
     * otherwise at least one; the best first, an equal score keeps the order given. Each gets its score.
     *
     * @param list<array{word: string, terms: list<string>}> $words
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public static function rank(array $words, array $candidates, bool $all): array
    {
        $ranked = [];
        $scores = [];
        foreach ($candidates as $candidate) {
            $title = ' ' . Search::normalize((string) $candidate['title']) . ' ';
            $text = ' ' . (string) ($candidate['normal'] ?? Search::normalize((string) $candidate['text'])) . ' ';
            $score = 0;
            $found = 0;
            foreach ($words as $w) {
                $inTitle = self::starts($title, $w['terms']);
                $occurrences = max(array_map(fn (string $term): int => substr_count($text, ' ' . $term), $w['terms']));
                if (!$inTitle && $occurrences === 0) {
                    continue;
                }
                $found++;
                $score += ($inTitle ? 100 : 0) + min(20, $occurrences)
                    + (str_contains($title . $text, ' ' . $w['word']) ? 5 : 0) // the exact form
                    + (str_contains($title . $text, ' ' . $w['word'] . ' ') ? 5 : 0); // the whole word
            }
            if ($found === 0 || ($all && $found < count($words))) {
                continue;
            }
            unset($candidate['normal']);
            $ranked[] = $candidate + ['score' => $score + (int) ($candidate['bonus'] ?? 0)];
            $scores[] = $score + (int) ($candidate['bonus'] ?? 0);
        }
        $order = array_keys($ranked);
        array_multisort($scores, SORT_DESC, $order, SORT_ASC, $ranked);

        return $ranked;
    }

    /**
     * A snippet of plain text around the place with the most query words, as pieces [text, matched] – the matched pieces
     * are whole words, printed in <mark> (snippetHtml) or as they are (MCP). Offsets come from the words of the original
     * text, so letters that normalize to two (ß, æ) never shift the window. Without a match: the beginning of the text.
     *
     * @param list<array{word: string, terms: list<string>}> $words
     * @return list<array{0: string, 1: bool}>
     */
    public static function snippet(string $plain, array $words): array
    {
        if (preg_match_all('/[\p{L}\p{N}]+/u', $plain, $tokens, PREG_OFFSET_CAPTURE) === 0 || $tokens[0] === []) {
            return $plain === '' ? [] : [[mb_strimwidth($plain, 0, 180, '…'), false]];
        }
        $tokens = $tokens[0];
        // which query word each text word matches (-1 = none)
        $hits = array_map(function (array $token) use ($words): int {
            $normal = ' ' . Search::normalize($token[0]);
            foreach ($words as $i => $w) {
                if (self::starts($normal, $w['terms'])) {
                    return $i;
                }
            }

            return -1;
        }, $tokens);
        // the window of SNIPPET_WORDS words with the most different query words, then the most matches
        $best = 0;
        $bestValue = -1;
        foreach ($hits as $start => $hit) {
            if ($hit < 0) {
                continue;
            }
            $window = array_filter(array_slice($hits, $start, self::SNIPPET_WORDS), fn (int $h): bool => $h >= 0);
            $value = count(array_unique($window)) * 100 + count($window);
            if ($value > $bestValue) {
                [$best, $bestValue] = [$start, $value];
            }
        }
        $from = max(0, $best - 6);
        $to = min(count($tokens), $from + self::SNIPPET_WORDS) - 1;
        $pieces = $from > 0 ? [['…', false]] : [[substr($plain, 0, $tokens[0][1]), false]];
        for ($i = $from; $i <= $to; $i++) {
            $pieces[] = [$tokens[$i][0], $hits[$i] >= 0];
            $end = $tokens[$i][1] + strlen($tokens[$i][0]);
            $pieces[] = [$i < $to ? substr($plain, $end, $tokens[$i + 1][1] - $end) : '', false];
        }
        if ($to < count($tokens) - 1) {
            $pieces[] = ['…', false];
        } else {
            $pieces[] = [substr($plain, $tokens[$to][1] + strlen($tokens[$to][0])), false];
        }
        // neighbouring pieces of the same kind joined, empty ones left out
        $joined = [];
        foreach ($pieces as [$text, $marked]) {
            if ($text === '') {
                continue;
            }
            if ($joined !== [] && $joined[count($joined) - 1][1] === $marked && !$marked) {
                $joined[count($joined) - 1][0] .= $text;
            } else {
                $joined[] = [$text, $marked];
            }
        }

        return $joined;
    }

    /** @param list<array{0: string, 1: bool}> $pieces the snippet as HTML: escaped, the matched words in <mark> */
    public static function snippetHtml(array $pieces): string
    {
        return implode('', array_map(fn (array $p): string => $p[1] ? '<mark>' . e($p[0]) . '</mark>' : e($p[0]), $pieces));
    }

    /** @param list<array{0: string, 1: bool}> $pieces the snippet as plain text (MCP) */
    public static function snippetText(array $pieces): string
    {
        return implode('', array_column($pieces, 0));
    }

    /** @param list<string> $terms does a word of the normalized text (with a space before it) start with one of the terms */
    private static function starts(string $normal, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($normal, ' ' . $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The candidates from the database: each kind picked by every word ($all) or any word, by the shortest beginning of
     * each word (its stem – a text word starting with it contains it), at most CANDIDATES of a kind, the title matches first.
     *
     * @param list<array{word: string, terms: list<string>}> $words
     * @return list<array<string, mixed>>
     */
    private static function candidates(App $app, array $words, string $column, bool $all): array
    {
        $db = $app->db();
        $settings = $app->settings();
        // each word by its shortest terms (prefixes()): a text word that starts with a longer one starts with them too;
        // the mobile-e variant is no prefix of the stem and gets its own alternative, joined by OR
        $where = function (\Closure $condition) use ($words, $all): array {
            $parts = [];
            $params = [];
            foreach ($words as $w) {
                $alternatives = [];
                foreach (self::prefixes($w['terms']) as $term) {
                    [$sql, $p] = $condition($term);
                    $alternatives[] = $sql;
                    array_push($params, ...$p);
                }
                $parts[] = '(' . implode(' OR ', $alternatives) . ')';
            }

            return ['(' . implode($all ? ' AND ' : ' OR ', $parts) . ')', $params];
        };
        // the candidates with the first word in the title come first when there are more than CANDIDATES
        $titleFirst = fn (string $column): string => '(' . $column . ' COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?) DESC';
        $first = '%' . addcslashes(self::prefixes($words[0]['terms'])[0], '%_\\') . '%';
        $candidates = [];

        $home = $settings->int('home_page');
        if ($column !== '' && $home > 0) {
            $home = (int) $db->value('SELECT ids FROM {stranky} WHERE preklad_z = ? AND jazyk = ?', [$home, $column]);
        }
        [$sql, $params] = $where(fn (string $term): array => Db::likeWords(['titulek', 'seo_link', 'popis', 'text'], $term));
        foreach ($db->all('SELECT ids, titulek, seo_link, popis, text FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND heslo_hash IS NULL AND smazano IS NULL AND jazyk = ? AND ' . $sql
            . ' ORDER BY ' . $titleFirst('titulek') . ', poradi LIMIT ' . self::CANDIDATES, [$column, ...$params, $first]) as $s) {
            $candidates[] = ['type' => 'page', 'label' => t('Page'), 'id' => (int) $s['ids'], 'title' => $s['titulek'], 'path' => (int) $s['ids'] === $home ? '' : $s['seo_link'],
                'text' => trim($s['popis'] . ' ' . plain_text((string) $s['text']))];
        }

        [$sql, $params] = $where(fn (string $term): array => Db::likeWords(['p.nazev', 'p.seo_link', 'p.popis'], $term, ['p.data']));
        foreach ($db->all('SELECT p.idp, p.nazev, p.seo_link, p.popis, p.data, k.seo_link AS kolekce, k.nazev AS kolekce_nazev, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk
            WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL AND p.jazyk = ? AND ' . $sql
            . ' ORDER BY ' . $titleFirst('p.nazev') . ', p.poradi LIMIT ' . self::CANDIDATES, [$column, ...$params, $first]) as $p) {
            $data = json_decode((string) $p['data'], true);
            // only text fields – image paths and link URLs would add noise to the results and snippets
            $textFields = array_column(array_filter(json_decode((string) $p['pole'], true) ?: [], fn (mixed $f): bool => is_array($f) && in_array($f['typ'] ?? '', ['text', 'radky', 'html'], true)), 'klic');
            $text = implode(' ', array_map(fn (string $v): string => plain_text($v), array_filter(array_intersect_key(is_array($data) ? $data : [], array_flip($textFields)), 'is_string')));
            $candidates[] = ['type' => 'collection_item', 'label' => $p['kolekce_nazev'], 'id' => (int) $p['idp'], 'title' => $p['nazev'], 'path' => $p['kolekce'] . '/' . $p['seo_link'],
                'text' => trim($p['popis'] . ' ' . $text)];
        }

        if (Extensions::isEnabled($settings, 'novinky')) {
            $now = Clock::now();
            $recent = Clock::at('-90 days');
            [$sql, $params] = $where(fn (string $term): array => Search::newsWords($term, 'c.'));
            foreach ($db->all('SELECT c.idc, c.titulek, c.seo_link, c.uvod, c.datum, c.hledani FROM {novinky} c
                WHERE c.visible = 1 AND c.datum <= ? AND c.smazano IS NULL AND c.noindex = 0 AND c.jazyk = ? AND ' . $sql
                . ' ORDER BY ' . $titleFirst('c.titulek') . ', c.datum DESC, c.idc DESC LIMIT ' . self::CANDIDATES, [$now, $column, ...$params, $first]) as $c) {
                $candidates[] = ['type' => 'news', 'label' => $settings->newsTitle(), 'id' => (int) $c['idc'], 'title' => $c['titulek'], 'path' => 'novinky/' . $c['seo_link'],
                    'text' => plain_text((string) $c['uvod']), 'normal' => (string) $c['hledani'], 'date' => (string) $c['datum'],
                    'bonus' => $c['datum'] >= $recent ? 3 : 0]; // a fresh news item a little higher
            }
        }

        return $candidates;
    }

    /**
     * The terms of a word that do not start with another of its terms: ["stavby", "stavb", "staveb"] → ["stavb", "staveb"]
     * (what starts with "stavby" starts with "stavb" too).
     *
     * @param list<string> $terms
     * @return list<string>
     */
    private static function prefixes(array $terms): array
    {
        return array_values(array_filter($terms, function (string $term) use ($terms): bool {
            foreach ($terms as $other) {
                if ($other !== $term && str_starts_with($term, $other)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The snippet of one result: around the matches in its text; a news item whose title and intro have none of the words
     * takes it from its text (read for the shown results only).
     *
     * @param array<string, mixed> $result
     * @param list<array{word: string, terms: list<string>}> $words
     * @return list<array{0: string, 1: bool}>
     */
    public static function resultSnippet(App $app, array $result, array $words): array
    {
        $text = (string) $result['text'];
        if ($result['type'] === 'news') {
            $normal = ' ' . Search::normalize($result['title'] . ' ' . $text);
            $matched = array_filter($words, fn (array $w): bool => self::starts($normal, $w['terms']));
            if (count($matched) < count($words)) {
                $text = trim($text . ' ' . plain_text((string) $app->db()->value('SELECT text FROM {novinky} WHERE idc = ?', [$result['id']])));
            }
        }

        return self::snippet($text, $words);
    }

    /**
     * The form of a query the statistics keep (3.52, B5), null when it is not kept: trimmed, lower case, spaces collapsed,
     * at most QUERY_LENGTH characters. A query that may name a person is never kept – one with an @ (an e-mail address) or
     * with 6 or more digits (a phone number, an account, a birth number). Pure.
     */
    public static function recordedQuery(string $q): ?string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($q)));
        if (mb_strlen($q) < self::MIN_LENGTH || str_contains($q, '@') || preg_match_all('/\d/u', $q) >= 6) {
            return null;
        }

        return trim(mb_substr($q, 0, self::QUERY_LENGTH));
    }

    /**
     * Counts one search of the site for the Statistics (3.52, B5): the day, the language version ('' = the default one),
     * the query (recordedQuery) and whether it found nothing. Only while the statistics are on, never for a signed-in user
     * or a bot; the caller counts only the first page of the results. No IP and nothing about the visitor is stored, rows
     * older than 400 days are deleted now and then.
     */
    public static function record(App $app, string $q, string $column, int $total): void
    {
        $query = self::recordedQuery($q);
        $agent = (string) ($app->request->serverValues()['HTTP_USER_AGENT'] ?? '');
        if ($query === null || !StatsGate::isOn($app) || trim($agent) === '' || StatsGate::isBot($agent) || $app->auth()->user() !== null) {
            return;
        }
        $empty = $total === 0 ? 1 : 0;
        try {
            $app->db()->run('INSERT INTO {search_queries} (day, language, query, searches, without_result) VALUES (?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE searches = searches + 1, without_result = without_result + ?', [Clock::today(), $column, $query, $empty, $empty]);
            if (random_int(1, 200) === 1) {
                $app->db()->run('DELETE FROM {search_queries} WHERE day < ?', [Clock::day('-400 days')]);
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // new files, the database before migration 0140: the search still answers
        }
    }

    /**
     * What visitors searched for since a day (B5, Core\Report): terms = the most searched queries, without_result = the
     * queries that found nothing most often; at most 20 each, [query, language (only for an additional language version),
     * searches, without_result].
     *
     * @return array{terms: list<array<string, mixed>>, without_result: list<array<string, mixed>>}
     */
    public static function queries(Db $db, string $since): array
    {
        $rows = fn (string $having, string $order): array => array_map(fn (array $r): array => ['query' => (string) $r['query']]
            + ($r['language'] !== '' ? ['language' => (string) $r['language']] : []) + ['searches' => (int) $r['s'], 'without_result' => (int) $r['w']],
            $db->all("SELECT query, language, SUM(searches) AS s, SUM(without_result) AS w FROM {search_queries} WHERE day >= ?
                GROUP BY query, language {$having} ORDER BY {$order}, query LIMIT 20", [$since]));

        try {
            return ['terms' => $rows('', 's DESC'), 'without_result' => $rows('HAVING w > 0', 'w DESC, s DESC')];
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // the database before migration 0140: nothing yet

            return ['terms' => [], 'without_result' => []];
        }
    }
}
