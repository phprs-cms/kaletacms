<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site search (3.52, B1–B3; 3.53, A5 on the stored index): one list ranked by relevance across pages, collection items
 * with their own page and news, for /hledani and MCP search_site. Only what a visitor of the language version sees:
 * published, without a password, without noindex (news too), not in the trash.
 *
 * Every kind is read from its normalized search text (hledani, Core\SearchIndex) in one scan: SQL selects the id, the
 * title, one match flag per query word and the occurrences of the rows matching any word (at most MAX_ROWS a kind, no
 * LIMIT that cuts by relevance); PHP counts the exact total, orders them by the number of words matched, the words in the
 * title, the occurrences and then the list order, and keeps the best CANDIDATES of each kind. Only those are read again
 * with their search text and ranked (rank()): a word in the title weighs most, the exact form and the whole word add a
 * little, then the occurrences (at most 20 a word), a fresh news item a little more. A text word matches when it starts
 * with the query word or its stem (Search::terms) – "IT" finds "IT", not "sit amet"; a Chinese or Japanese word (raw) is
 * found anywhere in the text (no spaces between words there). Words inside HTML tags, links and JSON keys are not in the
 * search text, so they never make a candidate. When no row has every word, the ones with some of them are shown and the
 * result says so (partial).
 *
 * Rows the background job has not indexed yet (hledani NULL, or a database before migration 0141) are searched the 3.52
 * way – collation LIKE over the raw columns, at most FALLBACK_PAGES / FALLBACK_ITEMS, ranked in PHP – so body text is found
 * right after an update too. To be removed in 3.54, with the news alternative for a re-index (newsReindexing()).
 */
final class SiteSearch
{
    /** A query shorter than this (in characters) is not searched, unless it is one CJK character (Search::longEnough). */
    public const int MIN_LENGTH = 2;

    /** The best rows of each kind (pages, items, news) that are read again and ranked; the total counts all of them. */
    public const int CANDIDATES = 300;

    /** A hard guard: at most this many matching rows of one kind are scanned (the total is then at least this). */
    public const int MAX_ROWS = 50000;

    /** Pages and items not indexed yet read at most (as in 3.52) – until the job has filled the index after an update. */
    public const int FALLBACK_PAGES = 500;

    public const int FALLBACK_ITEMS = 2000;

    /**
     * Whether the scan orders the rows by the occurrences of the query words (REPLACE over the search text) before the cut
     * to CANDIDATES – measured by tools/bench-search.php (3.53, A7). false = the number of words and the title only.
     */
    public const bool ORDER_BY_OCCURRENCES = true;

    /** Chinese characters and kana – words without spaces between them, matched anywhere (raw); a regex class. */
    private const string CJK = Search::CJK;

    /** The days a searched term is kept in the Statistics (B5); the daily clean-up deletes older rows (Retention::FIXED). */
    public const int KEEP_DAYS = 400;

    /** At most this many words of a query are searched. */
    public const int MAX_WORDS = 6;

    /** The longest query the statistics keep (B5, {search_queries}.query). */
    public const int QUERY_LENGTH = 60;

    /** At most this many distinct queries a day and language version are kept (3.53 B6); a known one is still counted. */
    public const int DAY_QUERIES = 2000;

    /** About this many words around the matches form a snippet. */
    private const int SNIPPET_WORDS = 30;

    /** At most this many overlapping pairs of a long Chinese or Japanese word are searched when it has no exact hit (B2). */
    public const int BIGRAMS = 4;

    /**
     * The words of a query with the beginnings each may have in the text: [word, terms, raw?, title?]. Normalized like the
     * stored index (Search::normalize), long enough (Search::longEnough – two characters, or one CJK character), at most
     * MAX_WORDS, without repeats. A run of Chinese characters or kana is a word of its own with raw = true ("iPhone手机" →
     * iphone and 手机): it is found anywhere in the text, not only at the start of a word. Every other script is an ordinary
     * word. 3.53 (B1): the query is read by its parts between spaces – a part whose normal form has a one-letter piece
     * ("R&B" → "r b", "e-mail", "s.r.o.") is one phrase (its pieces in a row, from the start of a word); a letter with
     * + or # ("C++", "C#") has no letters to search in the search text and is looked for in the titles as written (title).
     * An apostrophe ends a part: "l'hôtel", "d'emploi" and "women's" search "hotel", "emploi" and "women" – the elided
     * article or the possessive s is one letter and too short to be a word. Not in Cyrillic, where it is inside the word
     * (Ukrainian "п'ять" stays the phrase "п ять"), nor when no piece would be long enough ("п'є" → "п є").
     *
     * @return list<array{word: string, terms: list<string>, raw?: true, title?: true}>
     */
    public static function words(string $q, string $language): array
    {
        $words = [];
        $add = function (string $w, array $word) use (&$words): void {
            if ($w !== '' && !isset($words[$w])) {
                $words[$w] = ['word' => $w] + $word;
            }
        };
        $parts = [];
        foreach (preg_split('/\s+/u', mb_substr($q, 0, 100), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $spaced) {
            // an apostrophe ends a part in a Latin text (elision, the possessive s); in Cyrillic it is inside a word
            // (Ukrainian "п'ять", "м'ясо"), and a part whose pieces are all too short ("п'є", "b'z") stays one phrase
            // (U+02BC itself counts as Cyrillic in PCRE's script extensions, so the apostrophes are left out of the test)
            $split = preg_match('/\p{Cyrillic}/u', (string) preg_replace('/[\'\x{2019}\x{02BC}]/u', '', $spaced)) === 1 ? [$spaced]
                : (preg_split('/[\'\x{2019}\x{02BC}]+/u', $spaced, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $long = array_filter($split, fn (string $p): bool => array_filter(explode(' ', Search::normalize($p)), Search::longEnough(...)) !== []);
            array_push($parts, ...(count($split) > 1 && $long === [] ? [$spaced] : $split));
        }
        foreach ($parts as $part) {
            $normal = Search::normalize($part);
            $pieces = $normal === '' ? [] : explode(' ', $normal);
            if (count($pieces) > 1 && min(array_map(mb_strlen(...), $pieces)) === 1 && preg_match('/[' . self::CJK . ']/u', $normal) !== 1) {
                $add($normal, ['terms' => [$normal]]);
                continue;
            }
            if (preg_match('/^\p{L}[+#]+$/u', $part) === 1) {
                $title = mb_strtolower($part);
                $add($title, ['terms' => [$title], 'title' => true]);
                continue;
            }
            foreach (preg_split('/([' . self::CJK . ']+)/u', $normal, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $run) {
                $raw = preg_match('/^[' . self::CJK . ']/u', $run) === 1;
                foreach ($raw ? [$run] : explode(' ', $run) as $w) {
                    if (Search::longEnough($w)) {
                        $add($w, ['terms' => $raw ? [$w] : Search::terms($w, $language)] + ($raw ? ['raw' => true] : []));
                    }
                }
            }
        }

        return array_slice(array_values($words), 0, self::MAX_WORDS);
    }

    /**
     * The words for the partial ranking when no row has every word (3.53, B2): a Chinese or Japanese word of three or more
     * characters becomes its overlapping pairs (at most BIGRAMS, any of them matches) – "北京大学" also finds "北京" and
     * "大学". null = no such word.
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @return list<array{word: string, terms: list<string>, raw?: true, title?: true}>|null
     */
    public static function bigramWords(array $words): ?array
    {
        $changed = false;
        foreach ($words as $i => $w) {
            if (isset($w['raw']) && ($length = mb_strlen($w['word'])) >= 3) {
                $words[$i]['terms'] = array_map(fn (int $k): string => mb_substr($w['word'], $k, 2), range(0, min($length - 1, self::BIGRAMS) - 1));
                $changed = true;
            }
        }

        return $changed ? $words : null;
    }

    /**
     * The results for a query in one language version ('' = the default one): the best of every kind ranked, the best
     * first – [type (page | collection_item | news), label (the kind for the visitor), title, path (for App::url), id, date
     * (news), score]. total = how many rows match (exact; more than the results when a kind has more than CANDIDATES).
     * partial = no row has every word, these have some of them – a long Chinese word then also by its pairs (bigramWords,
     * 3.53 B2). The snippets come from snippets(), for the shown ones.
     *
     * @return array{results: list<array<string, mixed>>, total: int, partial: bool, words: list<array{word: string, terms: list<string>, raw?: true, title?: true}>}
     */
    public static function find(App $app, string $q, string $column): array
    {
        $language = Language::ofContent($app->settings(), $column);
        $words = self::words($q, $language);
        if ($words === []) {
            return ['results' => [], 'total' => 0, 'partial' => false, 'words' => []];
        }
        ['counts' => $counts, 'best' => $best] = self::pool($app, $words, $column);
        // every word, or – with nothing that has every word – some of them; a long Chinese word then by its pairs (B2)
        $needed = count($words);
        $partial = false;
        if (array_sum(array_map(fn (array $c): int => $c[$needed] ?? 0, $counts)) === 0) {
            if (($loose = self::bigramWords($words)) !== null) {
                $words = $loose;
                ['counts' => $counts, 'best' => $best] = self::pool($app, $words, $column);
            }
            $partial = $needed > 1 || $loose !== null;
            $needed = 1;
        }
        $results = [];
        $total = 0;
        foreach ($best as $type => $rows) {
            $total += array_sum(array_filter($counts[$type], fn (int $found): bool => $found >= $needed, ARRAY_FILTER_USE_KEY));
            // the best rows come first (pool()); a row with fewer words than needed was only kept for a partial result
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row[0] >= $needed));
            array_push($results, ...self::ranked($app, $words, $rows, $type, $needed, $column));
        }
        $scores = array_column($results, 'score');
        $order = array_keys($results);
        array_multisort($scores, SORT_DESC, $order, SORT_ASC, $results);

        return ['results' => $results, 'total' => $total, 'partial' => $partial && $results !== [], 'words' => $words];
    }

    /**
     * Ranks candidates [title, text (plain) or normal (the search text), bonus?, …]: with $all each must have every word in
     * the title or the text, otherwise at least one; the best first, an equal score keeps the order given. Each gets its score.
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public static function rank(array $words, array $candidates, bool $all): array
    {
        $ranked = [];
        $scores = [];
        foreach ($candidates as $candidate) {
            $match = self::match($words, $candidate);
            if ($match['found'] === 0 || ($all && $match['found'] < count($words))) {
                continue;
            }
            unset($candidate['normal']);
            $ranked[] = $candidate + ['score' => $match['score']];
            $scores[] = $match['score'];
        }
        $order = array_keys($ranked);
        array_multisort($scores, SORT_DESC, $order, SORT_ASC, $ranked);

        return $ranked;
    }

    /**
     * How one candidate matches the words: found = the words it has, title_hits = the words in its title, occurrences
     * (at most 20 a word) and the score rank() orders by. The text is the search text (normal) or the plain text normalized.
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @param array<string, mixed> $candidate
     * @return array{found: int, title_hits: int, occurrences: int, score: int}
     */
    private static function match(array $words, array $candidate): array
    {
        $title = ' ' . Search::normalize((string) $candidate['title']) . ' ';
        $text = ' ' . (string) ($candidate['normal'] ?? Search::normalize((string) ($candidate['text'] ?? ''))) . ' ';
        $match = ['found' => 0, 'title_hits' => 0, 'occurrences' => 0, 'score' => (int) ($candidate['bonus'] ?? 0)];
        foreach ($words as $w) {
            if (isset($w['title'])) { // "C++": in the title as written
                $inTitle = str_contains(mb_strtolower((string) $candidate['title']), $w['word']);
                $occurrences = 0;
                $exact = 5;
            } elseif (isset($w['raw'])) { // its terms: the word, or its pairs in the partial pass (bigramWords)
                $inTitle = array_filter($w['terms'], fn (string $term): bool => str_contains($title, $term)) !== [];
                $occurrences = max(array_map(fn (string $term): int => substr_count($text, $term), $w['terms']));
                $exact = str_contains($title . $text, $w['word']) ? 5 : 0;
            } else {
                $inTitle = self::starts($title, $w['terms']);
                $occurrences = max(array_map(fn (string $term): int => substr_count($text, ' ' . $term), $w['terms']));
                $exact = (str_contains($title . $text, ' ' . $w['word']) ? 5 : 0) // the exact form
                    + (str_contains($title . $text, ' ' . $w['word'] . ' ') ? 5 : 0); // the whole word
            }
            if (!$inTitle && $occurrences === 0) {
                continue;
            }
            $match['found']++;
            $match['title_hits'] += $inTitle ? 1 : 0;
            $match['occurrences'] += min(20, $occurrences);
            $match['score'] += ($inTitle ? 100 : 0) + min(20, $occurrences) + $exact;
        }

        return $match;
    }

    /**
     * A snippet of plain text around the place with the most query words, as pieces [text, matched] – the matched pieces
     * are whole words, printed in <mark> (snippetHtml) or as they are (MCP). Offsets come from the words of the original
     * text, so letters that normalize to two (ß, æ) never shift the window. Without a match: the beginning of the text.
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
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
                if (isset($w['title'])) {
                    continue; // looked for in the title only
                }
                if (isset($w['raw'])) {
                    foreach ($w['terms'] as $term) {
                        if (str_contains(mb_strtolower($token), $term)) {
                            $end = $offset + strlen($token);
                        } else {
                            // a raw word over several characters of Chinese: it starts here and goes on in the next ones
                            $chunk = mb_substr(substr($plain, $offset, strlen($term) * 2), 0, mb_strlen($term));
                            $end = mb_strlen($term) > mb_strlen($token) && mb_strtolower($chunk) === $term ? $offset + strlen($chunk) : null;
                        }
                        if ($end !== null) {
                            break;
                        }
                    }
                } elseif (str_contains($w['word'], ' ')) {
                    // a phrase ("r b"): its pieces are this text word and the next ones
                    $last = min($count, $t + substr_count($w['word'], ' ') + 1) - 1;
                    $joined = ' ' . Search::normalize(implode(' ', array_column(array_slice($tokens, $t, $last - $t + 1), 0)));
                    $end = self::starts($joined, $w['terms']) ? $tokens[$last][1] + strlen($tokens[$last][0]) : null;
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
     * The SQL of the scan over a search text $h (a column or an expression, normalized, words separated by spaces): one
     * condition per word – an ordinary word starts a word of the text (CONCAT(' ', h) LIKE '% term%', by its shortest
     * beginnings, prefixes()), a raw word is anywhere in it – and the occurrences: per word, how often its first beginning
     * starts a word of the text (at most 20; counted in bytes with REPLACE, the text is not copied), summed; '0' with
     * $byOccurrences false (ORDER_BY_OCCURRENCES, which tools/bench-search.php turns off). $more(word) may add an alternative to a word's condition (news during a re-index). A title
     * word ("C++", B1) is a collation LIKE on $title. Never a condition on raw HTML, JSON or a "1 = 1".
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @return array{conditions: list<array{0: string, 1: list<string>}>, occurrences: array{0: string, 1: list<string>}}
     */
    public static function indexSql(array $words, string $h, ?\Closure $more = null, ?string $title = null, bool $byOccurrences = self::ORDER_BY_OCCURRENCES): array
    {
        $conditions = [];
        $occurrences = [];
        $occurrenceParams = [];
        foreach ($words as $w) {
            if (isset($w['title'])) { // "C++" (B1): not in the search text, in the title as written (no title = no match)
                $conditions[] = $title === null ? ['(0 = 1)', []] : ['(' . $title . ' COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?)', ['%' . addcslashes($w['word'], '%_\\') . '%']];
                continue;
            }
            $raw = isset($w['raw']);
            $text = $raw ? $h : 'CONCAT(\' \', ' . $h . ')';
            $sql = [];
            $params = [];
            $terms = $raw ? $w['terms'] : self::prefixes($w['terms']);
            foreach ($terms as $term) {
                $sql[] = $text . ' LIKE ?';
                $params[] = ($raw ? '%' : '% ') . addcslashes($term, '%_\\') . '%';
            }
            if ($more !== null && ($extra = $more($w)) !== null) {
                $sql[] = $extra[0];
                array_push($params, ...$extra[1]);
            }
            $conditions[] = ['(' . implode(' OR ', $sql) . ')', $params];
            // ' term' inside the text, and the term at its very start
            $needle = ($raw ? '' : ' ') . $terms[0];
            $occurrences[] = 'LEAST(20, (LENGTH(' . $h . ') - LENGTH(REPLACE(' . $h . ', ?, \'\'))) DIV ' . strlen($needle) . ($raw ? '' : ' + (' . $h . ' LIKE ?)') . ')';
            array_push($occurrenceParams, $needle, ...($raw ? [] : [addcslashes($terms[0], '%_\\') . '%']));
        }

        return ['conditions' => $conditions, 'occurrences' => $byOccurrences && $occurrences !== [] ? ['(' . implode(' + ', $occurrences) . ')', $occurrenceParams] : ['0', []]];
    }

    /**
     * The rows of the language version that match at least one word, of every kind: counts = how many rows of each kind
     * have how many of the words (for the exact total), best = the best CANDIDATES rows of each kind as [found, title_hits,
     * occurrences, -seq, id, candidate|null], the best first – by the words found, the words in the title (collation LIKE
     * on the title, for the order only), the occurrences and the list order (seq). The scan rows are read one at a time and
     * only the best are kept, so a common word costs no memory per row; a row not indexed yet comes with its candidate,
     * already read and matched.
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @return array{counts: array<string, array<int, int>>, best: array<string, list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: array<string, mixed>|null}>>}
     */
    private static function pool(App $app, array $words, string $column): array
    {
        $db = $app->db();
        $settings = $app->settings();
        $ready = SearchIndex::ready($settings);
        $counts = [];
        $best = [];
        $keep = function (string $type, array $row) use (&$counts, &$best): void {
            $counts[$type][$row[0]] = ($counts[$type][$row[0]] ?? 0) + 1;
            $best[$type][] = $row;
            if (count($best[$type]) >= 2 * self::CANDIDATES) {
                rsort($best[$type]);
                $best[$type] = array_slice($best[$type], 0, self::CANDIDATES);
            }
        };
        // the words in a title, for the order before the cut: each word at the start of a title word (collation LIKE)
        $titleHits = function (string $title) use ($words): array {
            $sql = [];
            $params = [];
            foreach ($words as $w) {
                $sql[] = '(CONCAT(\' \', ' . $title . ') COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?)';
                $params[] = (isset($w['raw']) || isset($w['title']) ? '%' : '% ') . addcslashes(isset($w['raw']) || isset($w['title']) ? $w['terms'][0] : self::prefixes($w['terms'])[0], '%_\\') . '%';
            }

            return ['(' . implode(' + ', $sql) . ')', $params];
        };
        // the rows of one kind from the index: SQL flags each word, PHP sums them (exact – the search text is what rank() reads)
        $scan = function (string $type, string $id, string $title, string $from, string $h, array $params, string $order, ?\Closure $more = null, ?int $recheckAbove = null) use ($db, $words, $keep, $titleHits): void {
            $sql = self::indexSql($words, $h, $more, $title);
            [$titleSql, $titleParams] = $titleHits($title);
            $flags = [];
            $flagParams = [];
            foreach ($sql['conditions'] as [$condition, $p]) {
                $flags[] = $condition;
                array_push($flagParams, ...$p);
            }
            // a row above the re-index cursor needs the recheck only when a word was flagged by the title/intro alternative
            // alone (its index condition false): the other rows are counted from the index as everywhere else
            $need = '0';
            $needParams = [];
            if ($recheckAbove !== null && $more !== null) {
                $parts = [];
                foreach (self::indexSql($words, $h, null, $title)['conditions'] as $i => [$condition, $p]) {
                    if (($extra = $more($words[$i])) !== null) {
                        $parts[] = '(NOT ' . $condition . ' AND ' . $extra[0] . ')';
                        array_push($needParams, ...$p, ...$extra[1]);
                    }
                }
                $need = $parts === [] ? '0' : '(' . implode(' OR ', $parts) . ')';
            }
            $statement = $db->run('SELECT ' . $id . ', ' . $titleSql . ', ' . $sql['occurrences'][0] . ', ' . $need . ', ' . implode(', ', $flags) . ' FROM ' . $from
                . ' AND (' . implode(' OR ', $flags) . ') ORDER BY ' . $order . ' LIMIT ' . self::MAX_ROWS,
                [...$titleParams, ...$sql['occurrences'][1], ...$needParams, ...$flagParams, ...$params, ...$flagParams]);
            $seq = 0;
            $recheck = [];
            while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
                if ((int) $row[3] > 0) {
                    $recheck[(int) $row[0]] = -$seq++; // a word only in a substring of the title or intro: counted below by match()
                    continue;
                }
                $found = 0;
                for ($i = 4, $n = count($row); $i < $n; $i++) {
                    $found += (int) $row[$i] > 0 ? 1 : 0;
                }
                $keep($type, [$found, (int) $row[1], (int) $row[2], -$seq++, (int) $row[0], null]);
            }
            // news above the re-index cursor (3.53, removed with the re-index in 3.54): the SQL alternative only pre-filters
            // them (a substring, also inside tags), so their words are counted as ranked() reads them – the version 1 index
            // with the normalized title and intro – and the total and the partial pass stay exact
            foreach (array_chunk(array_keys($recheck), 200) as $ids) {
                $in = implode(', ', array_fill(0, count($ids), '?'));
                foreach ($db->all('SELECT idc, titulek, uvod, hledani FROM {novinky} WHERE idc IN (' . $in . ')', $ids) as $r) {
                    $match = self::match($words, ['title' => (string) $r['titulek'], 'normal' => (string) $r['hledani'] . ' ' . Search::normalize($r['titulek'] . ' ' . $r['uvod'])]);
                    if ($match['found'] > 0) {
                        $keep($type, [$match['found'], $match['title_hits'], $match['occurrences'], $recheck[(int) $r['idc']], (int) $r['idc'], null]);
                    }
                }
            }
        };
        // the rows not indexed yet, the 3.52 way: collation LIKE over the raw columns, read whole and matched in PHP
        $fallback = function (string $type, string $select, string $from, array $columns, array $json, string $title, array $params, string $order, int $limit) use ($app, $db, $words, $column, $keep): void {
            $conditions = [];
            $conditionParams = [];
            foreach ($words as $w) {
                $sql = [];
                foreach (isset($w['raw']) || isset($w['title']) ? $w['terms'] : self::prefixes($w['terms']) as $term) {
                    foreach ($columns as $c) {
                        $sql[] = $c . ' COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?';
                        $conditionParams[] = '%' . addcslashes($term, '%_\\') . '%';
                    }
                    foreach ($json as $c) {
                        [$sql[], $p] = Db::likeJson($c, $term);
                        array_push($conditionParams, ...$p);
                    }
                }
                $conditions[] = '(' . implode(' OR ', $sql) . ')';
            }
            $first = isset($words[0]['raw']) || isset($words[0]['title']) ? $words[0]['terms'][0] : self::prefixes($words[0]['terms'])[0];
            $rows = $db->all('SELECT ' . $select . ' FROM ' . $from . ' AND (' . implode(' OR ', $conditions) . ')'
                . ' ORDER BY (' . implode(' + ', $conditions) . ') DESC, CONCAT(\' \', ' . $title . ') COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ? DESC, ' . $order . ' LIMIT ' . $limit,
                [...$params, ...$conditionParams, ...$conditionParams, (isset($words[0]['raw']) || isset($words[0]['title']) ? '%' : '% ') . addcslashes($first, '%_\\') . '%']);
            foreach ($rows as $seq => $row) {
                $candidate = self::candidate($app, $type, $row, $column);
                $match = self::match($words, $candidate);
                if ($match['found'] > 0) {
                    $keep($type, [$match['found'], $match['title_hits'], $match['occurrences'], -self::MAX_ROWS - $seq, $candidate['id'], $candidate]);
                }
            }
        };

        $visible = 'zobrazit = 1 AND noindex = 0 AND heslo_hash IS NULL AND smazano IS NULL AND jazyk = ?';
        if ($ready) {
            $scan('page', 'ids', 'titulek', '{stranky} WHERE ' . $visible . ' AND hledani IS NOT NULL', 'hledani', [$column], 'poradi, ids');
        }
        $fallback('page', 'ids, titulek, seo_link, popis, text', '{stranky} WHERE ' . $visible . ($ready ? ' AND hledani IS NULL' : ''),
            ['titulek', 'seo_link', 'popis', 'text'], [], 'titulek', [$column], 'poradi, ids', self::FALLBACK_PAGES);

        $items = '{kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL AND p.jazyk = ?';
        if ($ready) {
            $scan('collection_item', 'p.idp', 'p.nazev', $items . ' AND p.hledani IS NOT NULL', 'p.hledani', [$column], 'p.poradi, p.idp');
        }
        $fallback('collection_item', 'p.idp, p.nazev, p.seo_link, p.popis, p.data, k.seo_link AS kolekce, k.nazev AS kolekce_nazev, k.pole', $items . ($ready ? ' AND p.hledani IS NULL' : ''),
            ['p.nazev', 'p.seo_link', 'p.popis'], ['p.data'], 'p.nazev', [$column], 'p.poradi, p.idp', self::FALLBACK_ITEMS);

        if (Extensions::isEnabled($settings, 'novinky')) {
            $news = '{novinky} c WHERE c.visible = 1 AND c.datum <= ? AND c.smazano IS NULL AND c.noindex = 0 AND c.jazyk = ?';
            $cursor = self::newsReindexing($settings);
            // during a re-index the news above the cursor still have a version 1 index, which lacks the letters it did not
            // know (Cyrillic, Chinese…) and split the words with letters it did not fold ("książka" as "ksi zka"): every word
            // is also looked for as a substring of their title and intro (the search collation folds "ksiazka" to "książka");
            // that only selects the rows – $scan counts them with match(), as ranked() reads them
            $more = $cursor === null ? null : function (array $w) use ($cursor): ?array {
                if (isset($w['title'])) {
                    return null;
                }
                [$sql, $params] = [[], []];
                // a raw word by its terms (the word, or its pairs in the pair pass), a phrase ("r b") by its longest piece
                foreach (isset($w['raw']) ? $w['terms'] : self::prefixes($w['terms']) as $term) {
                    $pieces = explode(' ', $term);
                    usort($pieces, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
                    foreach (['c.titulek', 'c.uvod'] as $c) {
                        $sql[] = $c . ' COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?';
                        $params[] = '%' . addcslashes($pieces[0], '%_\\') . '%';
                    }
                }

                return ['(c.idc > ' . $cursor . ' AND (' . implode(' OR ', $sql) . '))', $params];
            };
            $scan('news', 'c.idc', 'c.titulek', $news . ' AND c.hledani IS NOT NULL', 'c.hledani COLLATE utf8mb4_bin', [Clock::now(), $column], 'c.datum DESC, c.idc DESC', $more, $cursor);
            $fallback('news', 'c.idc, c.titulek, c.seo_link, c.uvod, c.datum', $news . ' AND c.hledani IS NULL', ['c.titulek', 'c.uvod'], [], 'c.titulek',
                [Clock::now(), $column], 'c.datum DESC, c.idc DESC', self::CANDIDATES);
        }
        foreach ($best as $type => $rows) {
            rsort($rows);
            $best[$type] = array_slice($rows, 0, self::CANDIDATES);
        }

        return ['counts' => $counts, 'best' => $best];
    }

    /**
     * The rows kept for one kind ranked: the indexed ones read again with their search text (in batches), the others as
     * read; each with every word it needs ($needed) gets its score, in the order given (rows as pool() keeps them).
     *
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: array<string, mixed>|null}> $rows
     * @return list<array<string, mixed>>
     */
    private static function ranked(App $app, array $words, array $rows, string $type, int $needed, string $column): array
    {
        $db = $app->db();
        $cursor = $type === 'news' ? self::newsReindexing($app->settings()) : null;
        $read = [];
        foreach (array_chunk(array_column(array_filter($rows, fn (array $r): bool => $r[5] === null), 4), 100) as $ids) {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $found = match ($type) {
                'page' => $db->all('SELECT ids, titulek, seo_link, hledani FROM {stranky} WHERE ids IN (' . $in . ')', $ids),
                'collection_item' => $db->all('SELECT p.idp, p.nazev, p.seo_link, p.hledani, k.seo_link AS kolekce, k.nazev AS kolekce_nazev FROM {kolekce_polozky} p
                    JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp IN (' . $in . ')', $ids),
                default => $db->all('SELECT idc, titulek, seo_link, datum, hledani' . ($cursor !== null ? ', uvod' : '') . ' FROM {novinky} WHERE idc IN (' . $in . ')', $ids),
            };
            foreach ($found as $row) {
                $candidate = self::candidate($app, $type, $row, $column);
                // a version 1 news index (a re-index running) lacks other scripts: its title and intro are added
                $candidate['normal'] = (string) $row['hledani'] . ($cursor !== null && $candidate['id'] > $cursor ? ' ' . Search::normalize($row['titulek'] . ' ' . $row['uvod']) : '');
                $read[$candidate['id']] = $candidate;
            }
        }
        $ranked = [];
        foreach ($rows as $row) {
            $candidate = $row[5] ?? $read[$row[4]] ?? null;
            if ($candidate === null || ($match = self::match($words, $candidate))['found'] < $needed) {
                continue; // deleted or changed since the scan
            }
            unset($candidate['normal'], $candidate['text']);
            $ranked[] = $candidate + ['score' => $match['score']];
        }

        return $ranked;
    }

    /**
     * A candidate of one kind from its row: [type, label, id, title, path, date + bonus (news), text (the plain text of a
     * row read whole – not indexed yet)]. $column = the language version, for the home page of a page result.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function candidate(App $app, string $type, array $row, string $column): array
    {
        if ($type === 'page') {
            return ['type' => 'page', 'label' => t('Page'), 'id' => (int) $row['ids'], 'title' => (string) $row['titulek'],
                'path' => (int) $row['ids'] === self::home($app, $column) ? '' : (string) $row['seo_link']]
                + (array_key_exists('text', $row) ? ['text' => trim($row['popis'] . ' ' . plain_text((string) $row['text']))] : []);
        }
        if ($type === 'collection_item') {
            return ['type' => 'collection_item', 'label' => (string) $row['kolekce_nazev'], 'id' => (int) $row['idp'], 'title' => (string) $row['nazev'],
                'path' => $row['kolekce'] . '/' . $row['seo_link']]
                + (array_key_exists('data', $row) ? ['text' => trim($row['popis'] . ' ' . self::itemText((string) $row['data'], (string) $row['pole']))] : []);
        }

        return ['type' => 'news', 'label' => $app->settings()->newsTitle(), 'id' => (int) $row['idc'], 'title' => (string) $row['titulek'], 'path' => 'novinky/' . $row['seo_link'],
            'date' => (string) $row['datum'], 'bonus' => $row['datum'] >= Clock::at('-90 days') ? 3 : 0] // a fresh news item a little higher
            + (array_key_exists('uvod', $row) && !array_key_exists('hledani', $row) ? ['text' => plain_text((string) $row['uvod'])] : []);
    }

    /** @var array<string, int> the home page of each language version, read once a request */
    private static array $home = [];

    /** The page that is the home page of the language version $column (its result has the path ''). */
    private static function home(App $app, string $column): int
    {
        if (!isset(self::$home[$column])) {
            $home = $app->settings()->int('home_page');
            self::$home[$column] = $column !== '' && $home > 0 ? (int) $app->db()->value('SELECT ids FROM {stranky} WHERE preklad_z = ? AND jazyk = ?', [$home, $column]) : $home;
        }

        return self::$home[$column];
    }

    /** The plain values of an item's fields the index holds (SearchIndex::SEARCH_TYPES) – for a snippet and a row not indexed yet. */
    private static function itemText(string $data, string $pole): string
    {
        $values = json_decode($data, true);
        $fields = json_decode($pole, true);
        if (!is_array($values) || !is_array($fields)) {
            return '';
        }
        $text = [];
        foreach ($fields as $field) {
            $value = is_array($field) && in_array($field['typ'] ?? '', SearchIndex::SEARCH_TYPES, true) ? ($values[(string) ($field['klic'] ?? '')] ?? null) : null;
            if (is_scalar($value) && (string) $value !== '') {
                $text[] = plain_text((string) $value);
            }
        }

        return implode(' ', $text);
    }

    /**
     * The cursor of a news re-index that is running (Search::VERSION above the stored one): the news above it still have
     * an index of the older version. null = the news index is current. 3.53; to be removed in 3.54.
     */
    private static function newsReindexing(Settings $settings): ?int
    {
        [$version, $cursor] = SearchIndex::state($settings);

        return $version < Search::VERSION ? $cursor : null;
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
     * The snippets of the shown results, in their order: around the matches in their plain text, read in one query a
     * kind (3.53, A5) – a page its description and text, an item its description and the values of its indexed fields, a
     * news item its intro, and its text too when the title and the intro miss a word. A result that carries its text
     * (text) uses it.
     *
     * @param list<array<string, mixed>> $results
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @return list<list<array{0: string, 1: bool}>>
     */
    public static function snippets(App $app, array $results, array $words): array
    {
        $db = $app->db();
        $texts = [];
        foreach (['page', 'collection_item', 'news'] as $type) {
            $ids = array_values(array_unique(array_map(intval(...), array_column(array_filter($results, fn (array $r): bool => $r['type'] === $type && !isset($r['text'])), 'id'))));
            if ($ids === []) {
                continue;
            }
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $rows = match ($type) {
                'page' => $db->all('SELECT ids AS id, popis, text FROM {stranky} WHERE ids IN (' . $in . ')', $ids),
                'collection_item' => $db->all('SELECT p.idp AS id, p.popis, p.data, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp IN (' . $in . ')', $ids),
                default => $db->all('SELECT idc AS id, titulek, uvod, text FROM {novinky} WHERE idc IN (' . $in . ')', $ids),
            };
            foreach ($rows as $row) {
                $texts[$type][(int) $row['id']] = match ($type) {
                    'page' => trim($row['popis'] . ' ' . plain_text((string) $row['text'])),
                    'collection_item' => trim($row['popis'] . ' ' . self::itemText((string) $row['data'], (string) $row['pole'])),
                    default => self::newsText($row, $words),
                };
            }
        }

        return array_map(fn (array $r): array => self::snippet((string) ($r['text'] ?? $texts[$r['type']][(int) $r['id']] ?? ''), $words), $results);
    }

    /**
     * The text a news snippet comes from: the intro, and the text after it when the title and the intro miss a word.
     *
     * @param array<string, mixed> $row
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     */
    private static function newsText(array $row, array $words): string
    {
        $intro = plain_text((string) $row['uvod']);
        $normal = ' ' . Search::normalize($row['titulek'] . ' ' . $intro);
        $matched = array_filter($words, fn (array $w): bool => match (true) {
            isset($w['title']) => true, // in the title or nowhere
            isset($w['raw']) => array_filter($w['terms'], fn (string $term): bool => str_contains($normal, $term)) !== [],
            default => self::starts($normal, $w['terms']),
        });

        return count($matched) < count($words) ? trim($intro . ' ' . plain_text((string) $row['text'])) : $intro;
    }

    /**
     * The snippet of one result (snippets() for one).
     *
     * @param array<string, mixed> $result
     * @param list<array{word: string, terms: list<string>, raw?: true, title?: true}> $words
     * @return list<array{0: string, 1: bool}>
     */
    public static function resultSnippet(App $app, array $result, array $words): array
    {
        return self::snippets($app, [$result], $words)[0];
    }

    /**
     * The form of a query the statistics keep (3.52, B5), null when it is not kept: trimmed, lower case, spaces collapsed,
     * at most QUERY_LENGTH characters. A query that may name a person is never kept – one with an @ (an e-mail address) or
     * with 6 or more digits (a phone number, an account, a birth number). Pure.
     */
    public static function recordedQuery(string $q): ?string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($q)));
        if (!Search::longEnough($q) || str_contains($q, '@') || preg_match_all('/\d/u', $q) >= 6) {
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
        try {
            self::tally($app->db(), Clock::today(), $column, $query, $total === 0);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // new files, the database before migration 0140: the search still answers
        }
    }

    /**
     * Adds one search to the row of the day, language version and query (record()). A query not seen that day yet gets a
     * new row only while the day and language have fewer than DAY_QUERIES rows (3.53 B6) – one COUNT on the prefix of the
     * primary key, only on that path – so random queries cannot grow the table without bound. Returns whether it counted.
     */
    public static function tally(Db $db, string $day, string $language, string $query, bool $empty): bool
    {
        $add = fn (): bool => $db->run('UPDATE {search_queries} SET searches = searches + 1, without_result = without_result + ?
            WHERE day = ? AND language = ? AND query = ?', [(int) $empty, $day, $language, $query])->rowCount() > 0;
        if ($add()) {
            return true;
        }
        if ((int) $db->value('SELECT COUNT(*) FROM {search_queries} WHERE day = ? AND language = ?', [$day, $language]) >= self::DAY_QUERIES) {
            return false;
        }
        try {
            $db->run('INSERT INTO {search_queries} (day, language, query, searches, without_result) VALUES (?, ?, ?, 1, ?)', [$day, $language, $query, (int) $empty]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            return $add(); // a parallel search inserted the same query a moment ago
        }
        return true;
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
