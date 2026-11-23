<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site search (3.52, B1–B3): one list ranked by relevance across pages, collection items with their own page and news,
 * for /hledani and MCP search_site. Only what a visitor of the language version sees: published, without a password,
 * without noindex (news too), not in the trash.
 *
 * The database picks the candidates, PHP ranks them. News: the normalized index (hledani) holds every word, so SQL keeps
 * only the news where a word starts with the query word (at most CANDIDATES). Pages and collection items have no index:
 * SQL keeps the rows with the word anywhere in the raw columns, or with a letter the collation does not fold the way
 * Search::normalize does (FOLDED: ß, ł, đ, ø, æ – "Straße" for "strasse"); a query in Arabic or Hebrew reads every visible
 * row; at most SCAN of a kind, the likely matches first. A word in the title weighs most, the exact form and the whole
 * word add a little, then the number of occurrences (at most 20 a word), a fresh news item a little more. A text word matches when it starts with the query word or its stem
 * (Search::terms) – "IT" finds "IT", not "sit amet". A word in a script normalize() drops (Chinese, Cyrillic, Greek…)
 * is searched as it was typed, in lower case, anywhere in the text (raw). When no candidate has every word, the ones with
 * some of them are shown and the result says so (partial).
 */
final class SiteSearch
{
    /** A query shorter than this (in characters) is not searched; the forms of the site ask for it too. */
    public const int MIN_LENGTH = 2;

    /** At most this many news come from the database (each with a word starting with the query word). */
    public const int CANDIDATES = 300;

    /** At most this many pages and this many collection items of the language version are ranked (3.51 read 500 and 2000). */
    public const int SCAN = 2000;

    /**
     * Letters Search::normalize folds (remove_diacritics) but utf8mb4_unicode_ci does not in LIKE (it ignores their case),
     * with the first letter they normalize to: a row holding one may match a normalized query word with that letter in PHP
     * although SQL said no ("Straße" for "strasse", "usługi" for "uslugi"), so such rows are candidates too.
     */
    public const array FOLDED = ['ß' => 's', 'ẞ' => 's', 'ł' => 'l', 'đ' => 'd', 'ø' => 'o', 'æ' => 'a'];

    /** The days a searched term is kept in the Statistics (B5); the daily clean-up deletes older rows (Retention::FIXED). */
    public const int KEEP_DAYS = 400;

    /** At most this many words of a query are searched. */
    public const int MAX_WORDS = 6;

    /** The longest query the statistics keep (B5, {search_queries}.query). */
    public const int QUERY_LENGTH = 60;

    /** About this many words around the matches form a snippet. */
    private const int SNIPPET_WORDS = 30;

    /**
     * The words of a query with the beginnings each may have in the text: [word, terms, raw?]. Normalized
     * (Search::normalize), words of at least MIN_LENGTH characters, at most MAX_WORDS, without repeats. A word of letters
     * normalize() drops (Chinese, Cyrillic, Greek…) stays as typed, in lower case, with raw = true: it is found anywhere in
     * the text, not only at the start of a word (Chinese has no spaces between words).
     *
     * @return list<array{word: string, terms: list<string>, raw?: true}>
     */
    public static function words(string $q, string $language): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}\p{M}]+/u', mb_strtolower(mb_substr($q, 0, 100)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $normal = Search::normalize($token);
            $raw = $normal === '' && preg_match('/\p{L}/u', $token) === 1;
            foreach ($raw ? [$token] : explode(' ', $normal) as $w) {
                if (mb_strlen($w) >= self::MIN_LENGTH && !isset($words[$w])) {
                    $words[$w] = ['word' => $w, 'terms' => $raw ? [$w] : Search::terms($w, $language)] + ($raw ? ['raw' => true] : []);
                }
            }
        }

        return array_slice(array_values($words), 0, self::MAX_WORDS);
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
        $raw = array_filter($words, fn (array $w): bool => isset($w['raw']));
        foreach ($candidates as $candidate) {
            $title = ' ' . Search::normalize((string) $candidate['title']) . ' ';
            $text = ' ' . (string) ($candidate['normal'] ?? Search::normalize((string) $candidate['text'])) . ' ';
            // a raw word is looked for in the lower-case title and text as they are
            [$rawTitle, $rawText] = $raw === [] ? ['', ''] : [mb_strtolower((string) $candidate['title']), mb_strtolower((string) $candidate['text'])];
            $score = 0;
            $found = 0;
            foreach ($words as $w) {
                if (isset($w['raw'])) {
                    $inTitle = str_contains($rawTitle, $w['word']);
                    $occurrences = substr_count($rawText, $w['word']);
                    $exact = 5;
                } else {
                    $inTitle = self::starts($title, $w['terms']);
                    $occurrences = max(array_map(fn (string $term): int => substr_count($text, ' ' . $term), $w['terms']));
                    $exact = (str_contains($title . $text, ' ' . $w['word']) ? 5 : 0) // the exact form
                        + (str_contains($title . $text, ' ' . $w['word'] . ' ') ? 5 : 0); // the whole word
                }
                if (!$inTitle && $occurrences === 0) {
                    continue;
                }
                $found++;
                $score += ($inTitle ? 100 : 0) + min(20, $occurrences) + $exact;
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
        // words of letters, digits and marks; a Chinese or Japanese character is a word of its own (no spaces there)
        $cjk = '\p{Han}\p{Hiragana}\p{Katakana}';
        if (preg_match_all('/[' . $cjk . ']|(?:(?![' . $cjk . '])[\p{L}\p{N}\p{M}])+/u', $plain, $tokens, PREG_OFFSET_CAPTURE) === 0 || $tokens[0] === []) {
            return $plain === '' ? [] : [[mb_strimwidth($plain, 0, 180, '…'), false]];
        }
        $tokens = $tokens[0];
        $count = count($tokens);
        // which query word each text word matches (-1 = none) and which text words are printed marked
        $hits = array_fill(0, $count, -1);
        $marked = array_fill(0, $count, false);
        foreach ($tokens as $t => [$token, $offset]) {
            $normal = null;
            foreach ($words as $i => $w) {
                $end = null; // the byte the match ends at
                if (isset($w['raw'])) {
                    if (str_contains(mb_strtolower($token), $w['word'])) {
                        $end = $offset + strlen($token);
                    } else {
                        // a raw word over several characters of Chinese: it starts here and goes on in the next ones
                        $chunk = mb_substr(substr($plain, $offset, strlen($w['word']) * 2), 0, mb_strlen($w['word']));
                        $end = mb_strlen($w['word']) > mb_strlen($token) && mb_strtolower($chunk) === $w['word'] ? $offset + strlen($chunk) : null;
                    }
                } elseif (self::starts($normal ??= ' ' . Search::normalize($token), $w['terms'])) {
                    $end = $offset + strlen($token);
                }
                if ($end !== null) {
                    $hits[$t] = $i;
                    for ($k = $t; $k < $count && $tokens[$k][1] < $end; $k++) {
                        $marked[$k] = true;
                    }
                    break;
                }
            }
        }
        // the window of SNIPPET_WORDS words with the most different query words, then the most matches
        $best = 0;
        $bestValue = -1;
        $last = 0; // the last match in the best window
        foreach ($hits as $start => $hit) {
            if ($hit < 0) {
                continue;
            }
            $window = array_filter(array_slice($hits, $start, self::SNIPPET_WORDS, true), fn (int $h): bool => $h >= 0);
            $value = count(array_unique($window)) * 100 + count($window);
            if ($value > $bestValue) {
                [$best, $bestValue, $last] = [$start, $value, (int) array_key_last($window)];
            }
        }
        // printed: up to 6 words before the first match, fewer when the matches of the window need the room
        $from = max(0, $best - 6, $last - self::SNIPPET_WORDS + 1);
        $to = min($count, $from + self::SNIPPET_WORDS) - 1;
        $pieces = $from > 0 ? [['…', false]] : [[substr($plain, 0, $tokens[0][1]), false]];
        for ($i = $from; $i <= $to; $i++) {
            $pieces[] = [$tokens[$i][0], $marked[$i]];
            $end = $tokens[$i][1] + strlen($tokens[$i][0]);
            $pieces[] = [$i < $to ? substr($plain, $end, $tokens[$i + 1][1] - $end) : '', false];
        }
        if ($to < $count - 1) {
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
            if ($joined !== [] && $joined[count($joined) - 1][1] === $marked) { // marked ones only meet inside Chinese text
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
     * The candidates from the database, each kind picked by every word ($all) or any word. A normalized word is looked for
     * by its shortest beginnings (prefixes() – a text word starting with a longer term starts with them too), a raw word as
     * it is. News: a word of the index starts with the term (an index not filled yet: the title), at most CANDIDATES, the
     * title matches first. Pages and items: see the class – at most SCAN, the title matches first, then the rows SQL found.
     *
     * @param list<array{word: string, terms: list<string>, raw?: true}> $words
     * @return list<array<string, mixed>>
     */
    private static function candidates(App $app, array $words, string $column, bool $all): array
    {
        $db = $app->db();
        $settings = $app->settings();
        $like = fn (string $column, string $pattern): array => [$column . ' COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?', [$pattern]];
        $contains = fn (string $term): string => '%' . addcslashes($term, '%_\\') . '%';
        // every word ($all) or any word: one condition per word, built from its terms by $condition(term, raw?)
        $where = function (\Closure $condition) use ($words, $all): array {
            $parts = [];
            $params = [];
            foreach ($words as $w) {
                $alternatives = [];
                foreach (isset($w['raw']) ? $w['terms'] : self::prefixes($w['terms']) as $term) {
                    [$sql, $p] = $condition($term, isset($w['raw']));
                    $alternatives[] = $sql;
                    array_push($params, ...$p);
                }
                $parts[] = '(' . implode(' OR ', $alternatives) . ')';
            }

            return ['(' . implode($all ? ' AND ' : ' OR ', $parts) . ')', $params];
        };
        // the word anywhere in the raw columns (text and JSON) – what rank() can keep, unless FOLDED letters are involved
        $anywhere = fn (array $columns, array $json = []): \Closure => function (string $term) use ($columns, $json, $like, $contains): array {
            $sql = [];
            $params = [];
            foreach ($columns as $c) {
                [$sql[], $p] = $like($c, $contains($term));
                array_push($params, ...$p);
            }
            foreach ($json as $c) {
                [$sql[], $p] = Db::likeJson($c, $term);
                array_push($params, ...$p);
            }

            return ['(' . implode(' OR ', $sql) . ')', $params];
        };
        // a row with a FOLDED letter that could become part of a query word (ß when a word has an s…; in JSON maybe as
        // \u00df) – SQL cannot tell, rank() does; one pass a letter over the columns joined
        $letters = array_keys(array_filter(self::FOLDED, fn (string $ascii): bool => array_filter($words, fn (array $w): bool => !isset($w['raw']) && str_contains($w['word'], $ascii)) !== []));
        $folded = function (array $columns, array $json = []) use ($letters, $like): array {
            $sql = [];
            $params = [];
            foreach ($letters as $letter) {
                [$sql[], $p] = $like('CONCAT_WS(\' \', ' . implode(', ', [...$columns, ...$json]) . ')', '%' . $letter . '%');
                array_push($params, ...$p);
                foreach ($json as $c) {
                    foreach ([$letter, ...(['ł' => ['Ł'], 'đ' => ['Đ'], 'ø' => ['Ø'], 'æ' => ['Æ']][$letter] ?? [])] as $form) { // \u escapes keep the case
                        [$sql[], $p] = $like($c, '%' . addcslashes(substr((string) json_encode($form), 1, -1), '%_\\') . '%');
                        array_push($params, ...$p);
                    }
                }
            }

            return $sql === [] ? ['0 = 1', []] : ['(' . implode(' OR ', $sql) . ')', $params];
        };
        // a normalized word with Arabic or Hebrew letters: the vowel marks and letter forms normalize() folds – every row
        $unfiltered = array_filter($words, fn (array $w): bool => !isset($w['raw']) && preg_match('/^[a-z0-9]+$/D', $w['word']) !== 1) !== [];
        $normalWords = array_filter($words, fn (array $w): bool => !isset($w['raw'])) !== [];
        // the WHERE of pages and items and the order: the first word at the start of a title word, then the rows SQL found
        $scan = function (array $columns, array $json, string $title) use ($where, $anywhere, $folded, $unfiltered, $normalWords, $words, $like): array {
            [$match, $params] = $where($anywhere($columns, $json));
            [$foldedSql, $foldedParams] = $folded($columns, $json);
            [$filter, $filterParams] = $unfiltered ? ['1 = 1', []] : ($normalWords ? ['(' . $match . ' OR ' . $foldedSql . ')', [...$params, ...$foldedParams]] : [$match, $params]);
            $first = isset($words[0]['raw']) ? $words[0]['word'] : self::prefixes($words[0]['terms'])[0];
            [$titleSql, $titleParams] = $like('CONCAT(\' \', ' . $title . ')', (isset($words[0]['raw']) ? '%' : '% ') . addcslashes($first, '%_\\') . '%');

            return [$filter, $filterParams, $titleSql . ' DESC, ' . $match . ' DESC', [...$titleParams, ...$params]];
        };
        $candidates = [];

        $home = $settings->int('home_page');
        if ($column !== '' && $home > 0) {
            $home = (int) $db->value('SELECT ids FROM {stranky} WHERE preklad_z = ? AND jazyk = ?', [$home, $column]);
        }
        [$filter, $filterParams, $order, $orderParams] = $scan(['titulek', 'seo_link', 'popis', 'text'], [], 'titulek');
        foreach ($db->all('SELECT ids, titulek, seo_link, popis, text FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND heslo_hash IS NULL AND smazano IS NULL AND jazyk = ? AND ' . $filter
            . ' ORDER BY ' . $order . ', poradi, ids LIMIT ' . self::SCAN, [$column, ...$filterParams, ...$orderParams]) as $s) {
            $candidates[] = ['type' => 'page', 'label' => t('Page'), 'id' => (int) $s['ids'], 'title' => $s['titulek'], 'path' => (int) $s['ids'] === $home ? '' : $s['seo_link'],
                'text' => trim($s['popis'] . ' ' . plain_text((string) $s['text']))];
        }

        [$filter, $filterParams, $order, $orderParams] = $scan(['p.nazev', 'p.seo_link', 'p.popis'], ['p.data'], 'p.nazev');
        foreach ($db->all('SELECT p.idp, p.nazev, p.seo_link, p.popis, p.data, k.seo_link AS kolekce, k.nazev AS kolekce_nazev, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk
            WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL AND p.jazyk = ? AND ' . $filter
            . ' ORDER BY ' . $order . ', p.poradi, p.idp LIMIT ' . self::SCAN, [$column, ...$filterParams, ...$orderParams]) as $p) {
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
            // the index is normalized and its words are separated by spaces: a word starting with the term is exact in SQL,
            // so the CANDIDATES only count news rank() keeps; a raw word in the title or the intro (a locked text has no
            // other words in the index either)
            [$sql, $params] = $where(fn (string $term, bool $raw): array => $raw
                ? ['(' . $like('c.titulek', $contains($term))[0] . ' OR ' . $like('c.uvod', $contains($term))[0] . ')', [$contains($term), $contains($term)]]
                : ['(CONCAT(\' \', c.hledani) LIKE ? OR (c.hledani IS NULL AND ' . $like('c.titulek', $contains($term))[0] . '))', ['% ' . addcslashes($term, '%_\\') . '%', $contains($term)]]);
            $first = isset($words[0]['raw']) ? $words[0]['word'] : self::prefixes($words[0]['terms'])[0];
            [$titleSql, $titleParams] = $like('CONCAT(\' \', c.titulek)', (isset($words[0]['raw']) ? '%' : '% ') . addcslashes($first, '%_\\') . '%');
            foreach ($db->all('SELECT c.idc, c.titulek, c.seo_link, c.uvod, c.datum, c.hledani FROM {novinky} c
                WHERE c.visible = 1 AND c.datum <= ? AND c.smazano IS NULL AND c.noindex = 0 AND c.jazyk = ? AND ' . $sql
                . ' ORDER BY ' . $titleSql . ' DESC, c.datum DESC, c.idc DESC LIMIT ' . self::CANDIDATES, [$now, $column, ...$params, ...$titleParams]) as $c) {
                $candidates[] = ['type' => 'news', 'label' => $settings->newsTitle(), 'id' => (int) $c['idc'], 'title' => $c['titulek'], 'path' => 'novinky/' . $c['seo_link'],
                    'text' => plain_text((string) $c['uvod']), 'date' => (string) $c['datum'], 'bonus' => $c['datum'] >= $recent ? 3 : 0] // a fresh news item a little higher
                    // the index for the normalized words; a raw word is looked for in the title and the intro (text)
                    + ($c['hledani'] !== null ? ['normal' => (string) $c['hledani']] : []);
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
            $lower = mb_strtolower($result['title'] . ' ' . $text);
            $matched = array_filter($words, fn (array $w): bool => isset($w['raw']) ? str_contains($lower, $w['word']) : self::starts($normal, $w['terms']));
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
     * or a bot; the caller counts only the first page of the results. No IP and nothing about the visitor is stored; the
     * daily clean-up deletes rows older than KEEP_DAYS (prune()).
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // new files, the database before migration 0140: the search still answers
        }
    }

    /**
     * Deletes the searched terms older than KEEP_DAYS – a step of the daily clean-up (Notifications::cleanups), so it runs
     * on a quiet site and with the statistics off too.
     */
    public static function prune(Db $db): void
    {
        try {
            $db->run('DELETE FROM {search_queries} WHERE day < ?', [Clock::day('-' . self::KEEP_DAYS . ' days')]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // the database before migration 0140: nothing to delete
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
