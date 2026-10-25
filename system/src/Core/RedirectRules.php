<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Redirects;

/**
 * Redirect rules beyond one exact address (3.6, INV-10), and saving many redirects at once (CSV in the admin, MCP
 * save_redirects).
 *
 * A pattern rule is a row of ka_presmerovani whose old address holds a wildcard `*`: "blog/*" → "news/*" keeps the rest of
 * the path (/blog/2019/post → /news/2019/post), "* /amp" → "*" drops a suffix. A `*` matches one or more characters, also
 * across slashes; the target takes the matched parts in order (a target may use fewer of them – "shop/*" → "products"
 * sends everything to one page). No regular expressions from users. Code 410 (exact or pattern) has no target: the
 * address answers 410 Gone with the not-found page, so search engines drop it (spam addresses of a hacked site).
 *
 * Order: a page, then an exact redirect, then patterns – so patterns are looked up only for an address that would end
 * with 404 (Front\Kernel::notFound). Among patterns the longest fixed beginning wins, then the longest fixed text, then the
 * older rule.
 *
 * Safety (the 3.4.2 guarantee): what a visitor typed never decides the host. A matched part is split at slashes, an
 * empty, "." or ".." segment refuses the match (so "//evil.example" can never form) and every segment is percent-encoded
 * (no backslash, no control character). A path target goes through App::url(), which always starts it with the site's
 * own base path; an absolute target is an address the administrator saved, and a wildcard there is accepted only after
 * the host (https://new.example/*, never https://*.example or https://new.example*). Rules that fail these checks – for
 * example from an imported export – are never used. Loops are refused on save.
 */
final class RedirectRules
{
    public const int MAX_WILDCARDS = 3;

    /** Pattern rules a site may have; the 404 path reads them all, so the number stays small. */
    public const int MAX_PATTERNS = 500;

    /** Rows of one save_redirects call. */
    public const int MAX_BATCH = 500;

    /** Rows of one CSV import in the admin. */
    public const int MAX_CSV_ROWS = 5000;

    /** The code of a rule that redirects nowhere: the address answers 410 Gone with the not-found page (3.6). */
    public const int GONE = 410;

    private const int MAX_HOPS = 20;

    /** Walks from existing redirects into a new pattern when checking it for loops. */
    private const int MAX_LOOP_WALKS = 200;

    /** @var array<string, string> lower-case old address => target (exact rules) */
    private array $exact = [];

    /** @var array<string, array<string, true>> lower-case target => the exact old addresses (keys of $exact) leading there */
    private array $byTarget = [];

    /** @var array<string, string> old address with wildcards => target */
    private array $patterns = [];

    /** @var list<array{0: string, 1: string}>|null pattern rules by precedence */
    private ?array $sorted = null;

    public static function isPattern(string $from): bool
    {
        return str_contains($from, '*');
    }

    /**
     * The old address as the table keeps it: a path without the slashes around it, percent-decoded (Request::path() is
     * decoded too), from a full URL only its path; "/?p=123" stays "?p=123" (Front\Kernel looks old WordPress addresses
     * up by the parameter). An encoded asterisk stays encoded, so it never becomes a wildcard.
     */
    public static function normalizeFrom(string $from): string
    {
        $from = trim($from);
        if (preg_match('#^https?://#i', $from)) {
            $parts = parse_url($from);
            if ($parts === false) {
                return '';
            }
            $from = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
        if (preg_match('#^/?\?p=(\d{1,10})$#D', $from, $m)) {
            return '?p=' . $m[1];
        }
        $decoded = rawurldecode(str_ireplace('%2A', '%252A', $from));
        if (mb_check_encoding($decoded, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
            $from = $decoded;
        }

        return trim($from, '/ ');
    }

    /** The target as the table keeps it: a full http(s) URL as it is, a path without the slashes around it. */
    public static function normalizeTo(string $to): string
    {
        $to = trim($to);

        return preg_match('#^https?://#i', $to) ? $to : trim($to, '/ ');
    }

    /**
     * Why the rule cannot be used, or null. Both addresses normalized. English text for t() and for MCP.
     */
    public static function problem(string $from, string $to): ?string
    {
        if ($from === '') {
            return 'Enter the old address – a path on this site, e.g. /old-page.';
        }
        if (mb_strlen($from) > 255 || mb_strlen($to) > 255) {
            return 'An address may have at most 255 characters.';
        }
        $numeric = preg_match('#^\?p=\d{1,10}$#D', $from) === 1;
        if (!$numeric && (preg_match('#[\x00-\x1f\x7f?\#]#', $from) || str_contains($from, '//') || !mb_check_encoding($from, 'UTF-8'))) {
            return 'The old address must be a path on this site, e.g. /old-page (without ? or #).';
        }
        $wildcards = substr_count($from, '*');
        if ($wildcards > self::MAX_WILDCARDS || str_contains($from, '**')) {
            return 'Use at most 3 wildcards (*) in the old address, never two in a row.';
        }
        if ($wildcards > 0 && trim(str_replace('*', '', $from), '/') === '') {
            return 'A pattern needs a fixed part besides the wildcards, e.g. /blog/*.';
        }
        // an empty target is the home page ("/"), or nothing for a 410 rule
        if (preg_match('#^https?://#i', $to)) {
            // with a wildcard the host stays fixed: no wildcard, user or backslash before the path – a visitor's input never decides it
            if (preg_match('/\s/u', $to) || (str_contains($to, '*') && !preg_match('#^https?://[^\s/?\#*@\\\\]+([/?\#][^\s\\\\]*)?$#iD', $to))) {
                return 'The target must be a path on this site or a full https://… address; a wildcard (*) only after the domain.';
            }
        } elseif (preg_match('#[\s:\\\\]#', $to) || !mb_check_encoding($to, 'UTF-8')) {
            return 'The target must be a path on this site or a full https://… address; a wildcard (*) only after the domain.';
        }
        if (substr_count($to, '*') > $wildcards) {
            return 'The target has more wildcards (*) than the old address.';
        }
        if ($wildcards === 0 && mb_strtolower($from) === mb_strtolower($to)) {
            return 'The old address and the target are the same.';
        }

        return null;
    }

    /**
     * The parts of the path the wildcards matched (case-insensitive, like the exact lookup in the database), or null.
     *
     * @return list<string>|null
     */
    public static function match(string $pattern, string $path): ?array
    {
        $regex = '#^' . implode('(.+?)', array_map(fn (string $part): string => preg_quote($part, '#'), explode('*', $pattern))) . '$#iu';
        if (preg_match($regex, $path, $m) !== 1) {
            return null;
        }

        return array_values(array_slice($m, 1));
    }

    /**
     * The target with the matched parts in place of its wildcards, or null when a part is not a clean path (an empty,
     * "." or ".." segment). Every segment is percent-encoded.
     *
     * @param list<string> $captures
     */
    public static function substitute(string $to, array $captures): ?string
    {
        $parts = explode('*', $to);
        if (count($parts) - 1 > count($captures)) {
            return null;
        }
        $out = $parts[0];
        foreach (array_slice($parts, 1) as $i => $part) {
            $segments = explode('/', $captures[$i]);
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    return null;
                }
            }
            $out .= implode('/', array_map(rawurlencode(...), $segments)) . $part;
        }

        return $out;
    }

    /**
     * Pattern rows by precedence: the longest fixed beginning, the longest fixed text, then the older rule.
     *
     * @template T of array<string, mixed>
     * @param list<T> $rows rows with z_adresy (and idp)
     * @return list<T>
     */
    public static function byPrecedence(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => self::precedence((string) $b['z_adresy']) <=> self::precedence((string) $a['z_adresy'])
            ?: (int) ($a['idp'] ?? 0) <=> (int) ($b['idp'] ?? 0));

        return $rows;
    }

    /** @return array{0: int, 1: int} */
    private static function precedence(string $pattern): array
    {
        return [mb_strlen(explode('*', $pattern, 2)[0]), mb_strlen(str_replace('*', '', $pattern))];
    }

    /**
     * The first pattern rule that answers one of the paths (in the order given), with the target filled in.
     *
     * @param list<array<string, mixed>> $rows pattern rows (z_adresy, na_adresu, idp…)
     * @param list<string> $paths paths without the slashes around them
     * @return array{row: array<string, mixed>, to: string}|null
     */
    public static function resolve(array $rows, array $paths): ?array
    {
        foreach (self::byPrecedence($rows) as $row) {
            $from = (string) $row['z_adresy'];
            $to = (string) $row['na_adresu'];
            if (!self::isPattern($from) || self::problem($from, $to) !== null) {
                continue; // e.g. an imported row that a save would refuse
            }
            foreach ($paths as $path) {
                $captures = self::match($from, $path);
                $target = $captures !== null ? self::substitute($to, $captures) : null;
                if ($target !== null) {
                    return ['row' => $row, 'to' => $target];
                }
            }
        }

        return null;
    }

    /** The pattern rows of the site (at most MAX_PATTERNS). @return list<array<string, mixed>> */
    public static function patternRows(Db $db): array
    {
        return $db->all("SELECT idp, z_adresy, na_adresu, typ FROM {presmerovani} WHERE z_adresy LIKE '%*%' ORDER BY idp LIMIT " . self::MAX_PATTERNS);
    }

    /**
     * Why one rule from the admin form cannot be saved, or null. A pattern – or any rule on a site that has patterns – is
     * checked like a row of the CSV import; exact rules on a site without patterns are left as before 3.6 (Redirects::add
     * keeps them free of loops by itself).
     */
    public static function refusal(Db $db, string $from, string $to, int $exceptIdp = 0, int $code = 301): ?string
    {
        $set = self::load($db, $exceptIdp);
        if (!self::isPattern($from . $to) && $set->patternCount() === 0 && $code !== self::GONE) {
            return null;
        }
        if (($problem = self::problem($from, $to)) !== null) {
            return $problem;
        }
        $known = $db->value('SELECT idp FROM {presmerovani} WHERE z_adresy = ?', [$from]) !== null;
        if (self::isPattern($from) && $exceptIdp === 0 && !$known && $set->patternCount() >= self::MAX_PATTERNS) {
            return self::TOO_MANY;
        }

        return $set->addUnlessLoop($from, $to, $code === self::GONE) ? null : self::LOOP;
    }

    public const string LOOP = 'It would make a loop – the redirects would lead back to this address.';

    public const string TOO_MANY = 'The site already has the most pattern rules it can use (500).';

    /** N319-2: plan() refuses a row whose old address differs from a saved one only in accents (the saved one is in existing). */
    public const string TWIN = 'An old address that differs from this one only in letter case or accents is already saved – change that redirect instead (until Kaleta 4.0 the two cannot have different targets):';

    /** All redirects of the site (but the one being edited), for checking new ones for loops. */
    public static function load(Db $db, int $exceptIdp = 0): self
    {
        $set = new self();
        foreach ($db->all('SELECT z_adresy, na_adresu FROM {presmerovani} WHERE idp <> ?', [$exceptIdp]) as $r) {
            $set->put((string) $r['z_adresy'], (string) $r['na_adresu']);
        }

        return $set;
    }

    private function put(string $from, string $to): void
    {
        if (self::isPattern($from)) {
            $this->patterns[$from] = $to;
            $this->sorted = null;
        } else {
            $this->putExact(mb_strtolower($from), $to);
        }
    }

    /** An exact rule, also in the index by target (thousands of rows of an import are checked one by one). */
    private function putExact(string $key, string $to): void
    {
        $this->dropExact($key);
        $this->exact[$key] = $to;
        $this->byTarget[mb_strtolower(trim($to, '/'))][$key] = true;
    }

    private function dropExact(string $key): void
    {
        if (isset($this->exact[$key])) {
            unset($this->byTarget[mb_strtolower(trim($this->exact[$key], '/'))][$key]);
            unset($this->exact[$key]);
        }
    }

    public function patternCount(): int
    {
        return count($this->patterns);
    }

    /**
     * Adds the rule the way it will be saved (an exact one like Redirects::add: older redirects to its old address take
     * its target, a redirect from its target goes) and says whether it closes a loop; with a loop nothing is added.
     */
    public function addUnlessLoop(string $from, string $to, bool $gone = false): bool
    {
        [$exact, $byTarget, $patterns] = [$this->exact, $this->byTarget, $this->patterns];
        if ($gone) {
            $this->put($from, ''); // a 410 rule ends every chain and changes no other redirect
        } elseif (self::isPattern($from)) {
            $this->put($from, $to);
        } else {
            // Redirects::add: every redirect to the old address now leads to the new target (patterns too), no chains
            foreach (array_keys($this->byTarget[mb_strtolower($from)] ?? []) as $key) {
                $this->putExact((string) $key, $to);
            }
            foreach ($this->patterns as $key => $target) {
                if ($target === $from) {
                    $this->patterns[$key] = $to;
                    $this->sorted = null;
                }
            }
            $this->dropExact(mb_strtolower(trim($to, '/')));
            $this->putExact(mb_strtolower($from), $to);
        }
        if (!$this->loops($from)) {
            return true;
        }
        [$this->exact, $this->byTarget, $this->patterns, $this->sorted] = [$exact, $byTarget, $patterns, null];

        return false;
    }

    private function loops(string $from): bool
    {
        if (!self::isPattern($from)) {
            return $this->walk($from);
        }
        // a sample address the pattern answers: blog/* → blog/k1
        $i = 0;
        if ($this->walk((string) preg_replace_callback('/\*/', function () use (&$i): string {
            return 'k' . ++$i;
        }, $from))) {
            return true;
        }
        // an exact redirect into the pattern, which leads back to it (news/x → blog/x with blog/* → news/*)
        $walks = 0;
        foreach ($this->exact as $old => $target) {
            if (self::match($from, trim($target, '/')) !== null && ($walks++ >= self::MAX_LOOP_WALKS || $this->walk((string) $old))) {
                return true;
            }
        }

        return false;
    }

    /** Follows the redirects from a path as the site would (no page anywhere): true = it comes back or never ends. */
    private function walk(string $path): bool
    {
        $seen = [mb_strtolower($path) => true];
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $next = $this->next($path);
            if ($next === null || preg_match('#^https?://#i', $next)) {
                return false;
            }
            $path = trim((string) preg_replace('/[?\#].*$/s', '', $next), '/');
            if ($path === '' || isset($seen[mb_strtolower($path)])) {
                return $path !== '';
            }
            $seen[mb_strtolower($path)] = true;
        }

        return true;
    }

    private function next(string $path): ?string
    {
        if (isset($this->exact[mb_strtolower($path)])) {
            return $this->exact[mb_strtolower($path)];
        }
        if ($this->sorted === null) {
            $rows = [];
            foreach ($this->patterns as $from => $to) {
                $rows[] = ['z_adresy' => (string) $from, 'na_adresu' => $to];
            }
            $this->sorted = array_map(fn (array $r): array => [$r['z_adresy'], $r['na_adresu']], self::byPrecedence($rows));
        }
        foreach ($this->sorted as [$from, $to]) {
            $captures = self::match($from, $path);
            if ($captures !== null) {
                return self::substitute($to, $captures);
            }
        }

        return null;
    }

    /** 301, 302 or 410 from what a file or a call says ('' = 301; 307 and 308 are their modern twins); null = not supported. */
    public static function code(string $code): ?int
    {
        return match (trim($code)) {
            '', '301', '308' => 301,
            '302', '307' => 302,
            '410' => self::GONE,
            default => null,
        };
    }

    /**
     * What saving these rows would do, row by row, without saving: added, changed, unchanged or refused (with the reason).
     * Rows are checked in order against the site's redirects and the rows before them; a later row for the same old
     * address is refused, the first one wins.
     *
     * @param list<array{from: string, to: string, code?: string, refused?: string}> $rows
     * @return list<array{row: int, from: string, to: string, code: int, status: string, reason: string, existing?: string}>
     */
    public static function plan(Db $db, array $rows): array
    {
        $twin = null;
        $set = self::load($db);
        $existing = [];
        foreach ($db->all('SELECT z_adresy, na_adresu, typ FROM {presmerovani}') as $r) {
            $existing[mb_strtolower((string) $r['z_adresy'])] = [(string) $r['na_adresu'], (int) $r['typ']];
        }
        $seen = [];
        $patterns = $set->patternCount();
        $out = [];
        foreach ($rows as $i => $r) {
            $from = self::normalizeFrom($r['from']);
            $code = self::code($r['code'] ?? '');
            $to = $code === self::GONE ? '' : self::normalizeTo($r['to']); // a 410 rule has no target
            $result = ['row' => $i + 1, 'from' => $from === '' || str_starts_with($from, '?') ? $from : '/' . $from, 'to' => $to === '' || preg_match('#^https?://#i', $to) ? $to : '/' . $to,
                'code' => $code ?? 301, 'status' => 'refused', 'reason' => ''];
            $key = mb_strtolower($from);
            $current = $existing[$key] ?? null;
            $result['reason'] = match (true) {
                isset($r['refused']) => $r['refused'],
                $code === null => 'Only 301 (permanent), 302 (temporary) and 410 (gone for good).',
                $code !== self::GONE && trim($r['to']) === '' => 'Enter the target – a path on this site or a full https://… address.',
                ($problem = self::problem($from, $to)) !== null => $problem,
                isset($seen[$key]) => 'The same old address is in an earlier row – the first one counts.',
                // N319-2: a saved address the database counts as the same one (cafe for café) – the row is refused, the
                // existing redirect named (existing), never silently retargeted
                ($twin = Redirects::twin($db, $from)) !== null => self::TWIN,
                $current !== null && $current[0] === $to && $current[1] === $code => '',
                self::isPattern($from) && $current === null && $patterns >= self::MAX_PATTERNS => self::TOO_MANY,
                !$set->addUnlessLoop($from, $to, $code === self::GONE) => self::LOOP,
                default => '',
            };
            if ($result['reason'] === self::TWIN && $twin !== null) {
                $result['existing'] = '/' . $twin['z_adresy'];
            }
            if ($result['reason'] === '') {
                $result['status'] = $current === null ? 'added' : ($current[0] === $to && $current[1] === $code ? 'unchanged' : 'changed');
                $seen[$key] = true;
                $patterns += self::isPattern($from) && $current === null ? 1 : 0;
            }
            $out[] = $result;
        }

        return $out;
    }

    /**
     * Saves the rows plan() accepted (added, changed) in one transaction and returns the plan with the outcome. Exact
     * redirects go through Redirects::add (no chains), pattern and 410 rules are stored as they are. Links on the site are
     * not rewritten here – thousands of rows of a moved site would each scan all content; the site audit finds such links.
     *
     * @param list<array{from: string, to: string, code?: string, refused?: string}> $rows
     * @return list<array{row: int, from: string, to: string, code: int, status: string, reason: string}>
     */
    public static function save(Db $db, array $rows): array
    {
        $plan = self::plan($db, $rows);
        $db->transaction(function (Db $db) use ($plan, $rows): void {
            foreach ($plan as $i => $p) {
                if (in_array($p['status'], ['added', 'changed'], true)) {
                    self::store($db, self::normalizeFrom($rows[$i]['from']), $p['code'] === self::GONE ? '' : self::normalizeTo($rows[$i]['to']), $p['code']);
                }
            }
        });

        return $plan;
    }

    /** One checked rule into the table (a pattern from the admin form, CSV import, MCP save_redirects). */
    public static function store(Db $db, string $from, string $to, int $code): void
    {
        if (self::isPattern($from) || $code === self::GONE) {
            if (($twin = Redirects::twin($db, $from)) !== null) {
                // N319-2: a rule for an address the database counts as one already saved (callers refuse it first): the
                // new rule takes that row over, recorded – the saved one is never turned into this rule unseen
                Redirects::takeOver($db, $twin, $from, $to, $code);
                $db->delete('nenalezeno', ['cesta' => $from]);

                return;
            }
            $db->run('INSERT INTO {presmerovani} (z_adresy, source_norm, na_adresu, typ, vytvoreno) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE na_adresu = VALUES(na_adresu), typ = VALUES(typ), auto_score = NULL',
                [$from, RedirectSources::normalize($from), $to, $code, date('Y-m-d H:i:s')]); // 3.19: with its exact normal form

            $db->delete('nenalezeno', ['cesta' => $from]);

            return;
        }
        Redirects::add($db, $from, $to, false);
        $db->run('UPDATE {presmerovani} SET typ = ?, auto_score = NULL WHERE z_adresy = ?', [$code, $from]);
        $db->delete('nenalezeno', ['cesta' => $from]);
    }

    /**
     * Rows of a CSV file: "old,new[,code]" with or without a header, or the CSV export of the WordPress Redirection plugin
     * (source,target,regex,code,…). The delimiter may be a comma, a semicolon or a tab. A simple regular expression of
     * that plugin (^/old/(.*)$ → /new/$1) becomes a pattern rule; any other one is refused, as is a disabled rule.
     *
     * @return list<array{from: string, to: string, code: string, refused?: string}>
     */
    public static function parseCsv(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $first = strtok($text, "\n");
        $first = $first === false ? '' : $first;
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($first, $candidate) > substr_count($first, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $text);
        rewind($stream);
        $lines = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn (?string $c): string => trim((string) $c), $cells);
            if (implode('', $cells) !== '') {
                $lines[] = $cells;
            }
            if (count($lines) > self::MAX_CSV_ROWS + 1) {
                break;
            }
        }
        fclose($stream);
        if ($lines === []) {
            return [];
        }
        $columns = ['from' => 0, 'to' => 1, 'code' => 2, 'regex' => null, 'status' => null];
        $header = array_map(fn (string $c): string => strtolower($c), $lines[0]);
        $names = ['from' => ['source', 'from', 'old', 'old url', 'old address', 'redirect from', 'z', 'z_adresy'],
            'to' => ['target', 'to', 'new', 'new url', 'new address', 'destination', 'redirect to', 'na', 'na_adresu'],
            'code' => ['code', 'status code', 'http code', 'typ'], 'regex' => ['regex'], 'status' => ['status', 'enabled']];
        if (array_intersect($header, [...$names['from'], ...$names['to']]) !== []) {
            array_shift($lines);
            foreach ($names as $column => $aliases) {
                $found = array_keys(array_filter($header, fn (string $c): bool => in_array($c, $aliases, true)));
                if ($found === [] && $column === 'code') {
                    $found = array_keys($header, 'type', true); // a numeric "type" column, when there is no "code"
                }
                $columns[$column] = $found[0] ?? null;
            }
        }
        $rows = [];
        foreach (array_slice($lines, 0, self::MAX_CSV_ROWS) as $cells) {
            $cell = fn (?int $i): string => $i !== null ? ($cells[$i] ?? '') : '';
            $row = ['from' => $cell($columns['from']), 'to' => $cell($columns['to']), 'code' => $cell($columns['code'])];
            if (!preg_match('/^\d*$/', $row['code'])) {
                $row['code'] = ''; // the plugin's "type" column says "url"
            }
            if (in_array(strtolower($cell($columns['status'])), ['disabled', '0', 'false', 'no'], true)) {
                $row['refused'] = 'The rule is switched off in the file.';
            } elseif (in_array(strtolower($cell($columns['regex'])), ['1', 'true', 'yes'], true)) {
                $converted = self::fromRegex($row['from'], $row['to']);
                if ($converted !== null) {
                    [$row['from'], $row['to']] = $converted;
                } else {
                    $row['refused'] = 'A regular expression – add this one by hand as a pattern with * (e.g. /blog/* → /news/*).';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A simple regular expression of the Redirection plugin as a pattern: a fixed path with (.*) or (.+) groups, optionally
     * between ^ and $, and a target that uses the groups in order ($1, $2…); without groups it is an exact address.
     * An expression without ^ matches anywhere in the plugin – here it matches from the start, which is only ever narrower.
     * Anything else is null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function fromRegex(string $source, string $target): ?array
    {
        if (!preg_match('#^\^?((?:[A-Za-z0-9/_~%-]|\\\\[./-]|\((?:\.\*\??|\.\+\??)\))+)\$?$#', $source, $m)) {
            return null;
        }
        $groups = preg_match_all('/\((?:\.\*\??|\.\+\??)\)/', $m[1]);
        $from = str_replace(['\\.', '\\/', '\\-'], ['.', '/', '-'], (string) preg_replace('/\((?:\.\*\??|\.\+\??)\)/', '*', $m[1]));
        preg_match_all('/[$\\\\](\d)/', $target, $used);
        $order = array_map('intval', $used[1]);
        $inOrder = $order === [] || $order === range(1, count($order));
        if ($groups > self::MAX_WILDCARDS || !$inOrder || str_contains((string) preg_replace('/[$\\\\]\d/', '', $target), '$')) {
            return null;
        }

        return [$from, (string) preg_replace('/[$\\\\]\d/', '*', $target)];
    }
}
