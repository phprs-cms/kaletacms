<?php
/**
 * Kaleta 3.19 (docs/design/4.0-data-model.md §3 step 2 and §1.3 – the contract, shipped as a normal 3.x release after the
 * owner's decisions of 21 and 22 October 2026): addresses unique per language version on every site, tags per language,
 * redirects looked up by their exact normalised address.
 *
 * 1. Redirects: every row the background job "redirect_sources" has not normalised yet gets its source_norm now (Kaleta
 *    looks redirects up only by it from 3.19). Old addresses that counted as one under the Czech collation and differ once
 *    compared exactly are recorded as an event (redirect.collisions) – nothing is deleted; System status lists them.
 * 2. Slugs: the checks Core\Slug::switchPerLanguage made when an administrator switched "The same address in every
 *    language" on – the per-language keys (jazyk, seo_link) of pages, news, news categories and tags exist (a missing one is
 *    added, which fails only when two rows of one language share a slug) – then the global keys go and slugs_per_language
 *    is '1'. A site that cannot switch stays exactly as it is (setting '0', the global keys in place, tags not split); the
 *    reason goes to the setting slugs_switch_failed and to the event slugs.per_language_failed, and System status says what
 *    to do. Settings → General runs this file again once the cause is fixed (Core\Slug::enableEverywhere).
 * 3. Tags (only when slugs are per language): a tag used by news items of several language versions becomes one tag per
 *    language and the news are linked to the tag of their own language. The tag keeps its row (id, description, image) in
 *    its own language when news of that language use it, otherwise in the language with the most of its news items (a tie:
 *    the smaller language code); every other language gets the existing tag with that slug in that language, or a copy of
 *    this one (name, slug, description, image) – languages in the order of their codes, so a second run changes nothing.
 *
 * Self-contained on purpose: the update request of 3.18.3 runs it with its own classes already loaded, so only Db, Settings
 * and Core\RedirectSources (unchanged since 3.14) are used. Safe to run again: every step looks at the state first.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $event = static function (string $type, string $severity, string $message, array $data) use ($db): void {
        try {
            $db->insert('events', ['created_at' => date('Y-m-d H:i:s'), 'type' => $type, 'severity' => $severity, 'message' => mb_substr($message, 0, 255),
                'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable) {
            // an event never stops the migration
        }
    };
    $hasKey = static fn (string $table, string $key): bool => $db->value('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        [$db->tableName($table), $key]) !== null;

    // --- 1. redirects by the exact address
    if (class_exists(\Kaleta\Core\RedirectSources::class)) {
        for ($round = 0; $round < 1000 && \Kaleta\Core\RedirectSources::fill($db, 20.0)['left']; $round++) {
            // batches of 500 until every row has its normal form
        }
        $groups = \Kaleta\Core\RedirectSources::collisionCount($db);
        if ($groups > 0 && $db->value("SELECT 1 FROM {events} WHERE type = 'redirect.collisions' LIMIT 1") === null) {
            $listed = array_map(static fn (array $g): string => implode(' = ', array_map(static fn (string $s): string => '/' . $s, $g)), \Kaleta\Core\RedirectSources::collisions($db, 10));
            $event('redirect.collisions', 'warning', $groups . ' group(s) of redirects had old addresses that counted as one address; from 3.19 they are compared exactly – see System status',
                ['groups' => $groups, 'first' => $listed]);
        }
    }

    // --- 2. slugs unique per language version
    $tables = [ // table => [id column, global key, per-language key, the address prefix a person recognises]
        'stranky' => ['ids', 'uq_stranky_seo', 'uq_stranky_jazyk_seo', '/'],
        'novinky' => ['idc', 'uq_clanky_seo', 'uq_clanky_jazyk_seo', '/novinky/'],
        'kategorie' => ['idt', 'uq_topic_seo', 'uq_topic_jazyk_seo', '/novinky/kategorie/'],
        'stitky' => ['ids', 'uq_stitky_seo', 'uq_stitky_jazyk_seo', '/novinky/stitek/'],
    ];
    $on = (string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'slugs_per_language'") === '1';
    $globalLeft = array_filter($tables, static fn (array $t, string $table): bool => $hasKey($table, $t[1]), ARRAY_FILTER_USE_BOTH);
    if (!$on || $globalLeft !== []) {
        $failure = null;
        foreach ($tables as $table => [, , $perLanguage, $prefix]) {
            if ($hasKey($table, $perLanguage)) {
                continue;
            }
            // the key of migrations 0083/0095 is missing (removed by hand, a restore of a partial backup): it is added again –
            // impossible only while two rows of one language version share a slug, which a person has to resolve
            $shared = array_map(static fn (array $r): string => $prefix . $r['seo_link'] . ($r['jazyk'] !== '' ? ' (' . $r['jazyk'] . ')' : ''),
                $db->all("SELECT jazyk, seo_link FROM {{$table}} GROUP BY jazyk, seo_link HAVING COUNT(*) > 1 ORDER BY jazyk, seo_link LIMIT 10"));
            if ($shared !== []) {
                $failure = ['reason' => 'shared', 'table' => $table, 'slugs' => $shared];
                break;
            }
            try {
                $db->run("ALTER TABLE {{$table}} ADD UNIQUE KEY {$perLanguage} (jazyk, seo_link)");
            } catch (\Throwable $e) {
                $failure = ['reason' => 'database', 'table' => $table, 'error' => mb_substr($e->getMessage(), 0, 200)];
                break;
            }
        }
        $dropped = [];
        if ($failure === null) {
            try {
                foreach ($tables as $table => [, $global]) {
                    if ($hasKey($table, $global)) {
                        $db->run("ALTER TABLE {{$table}} DROP INDEX {$global}");
                        $dropped[$table] = $global;
                    }
                }
            } catch (\Throwable $e) {
                $failure = ['reason' => 'database', 'table' => (string) $table, 'error' => mb_substr($e->getMessage(), 0, 200)];
                foreach ($dropped as $table => $global) { // the site stays as it was: the keys this run dropped come back
                    try {
                        $db->run("ALTER TABLE {{$table}} ADD UNIQUE KEY {$global} (seo_link)");
                    } catch (\Throwable) {
                        // checks stay global with the setting off – a missing key refuses nothing the code would allow
                    }
                }
            }
        }
        if ($failure === null) {
            $settings->set('slugs_per_language', '1');
            $settings->set('slugs_switch_failed', '');
            $on = true;
            $event('slugs.per_language', 'info', 'Addresses are unique per language version now – the same address may be used once in each language', []);
        } else {
            $settings->set('slugs_per_language', '0');
            $settings->set('slugs_switch_failed', (string) json_encode($failure + ['at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $event('slugs.per_language_failed', 'warning', $failure['reason'] === 'shared'
                ? 'Addresses could not become unique per language: two items of one language share an address – see System status'
                : 'Addresses could not become unique per language: the database refused to change a key – see System status', $failure);
        }
    }

    // --- 3. tags per language (only with per-language slugs: a second tag with the same slug needs the global key gone)
    if (!$on || $hasKey('stitky', 'uq_stitky_seo')) {
        return;
    }
    $usage = [];
    foreach ($db->all('SELECT t.ids, t.jazyk AS own, n.jazyk AS language, COUNT(*) AS n FROM {stitky} t JOIN {novinky_stitky} ns ON ns.ids = t.ids JOIN {novinky} n ON n.idc = ns.idc
        GROUP BY t.ids, t.jazyk, n.jazyk ORDER BY t.ids, n.jazyk') as $r) {
        $usage[(int) $r['ids']]['own'] = (string) $r['own'];
        $usage[(int) $r['ids']]['languages'][(string) $r['language']] = (int) $r['n'];
    }
    $split = [];
    $created = 0;
    $relinked = 0;
    foreach ($usage as $ids => ['own' => $own, 'languages' => $languages]) {
        if (array_keys($languages) === [$own]) {
            continue; // every news item of the tag is in its language
        }
        $tag = $db->one('SELECT * FROM {stitky} WHERE ids = ?', [$ids]);
        if ($tag === null) {
            continue;
        }
        ksort($languages, SORT_STRING);
        $keeper = $own;
        if (!isset($languages[$own])) { // no news of its own language: the tag moves to the language most of its news are in
            $most = max($languages);
            $keeper = (string) array_key_first(array_filter($languages, static fn (int $n): bool => $n === $most));
            $taken = $db->value('SELECT ids FROM {stitky} WHERE jazyk = ? AND seo_link = ? AND ids <> ?', [$keeper, $tag['seo_link'], $ids]);
            if ($taken === null) {
                $db->update('stitky', ['jazyk' => $keeper], ['ids' => $ids]);
                unset($languages[$keeper]);
            }
        } else {
            unset($languages[$own]);
        }
        foreach (array_keys($languages) as $language) {
            $language = (string) $language;
            $target = $db->value('SELECT ids FROM {stitky} WHERE jazyk = ? AND seo_link = ? AND ids <> ?', [$language, $tag['seo_link'], $ids]);
            if ($target === null) {
                $target = $db->insert('stitky', ['nazev' => $tag['nazev'], 'seo_link' => $tag['seo_link'], 'jazyk' => $language, 'popis' => $tag['popis'], 'obrazek' => $tag['obrazek']]);
                $created++;
            }
            $db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) SELECT ns.idc, ? FROM {novinky_stitky} ns JOIN {novinky} n ON n.idc = ns.idc WHERE ns.ids = ? AND n.jazyk = ?', [(int) $target, $ids, $language]);
            $relinked += $db->run('DELETE ns FROM {novinky_stitky} ns JOIN {novinky} n ON n.idc = ns.idc WHERE ns.ids = ? AND n.jazyk = ?', [$ids, $language])->rowCount();
        }
        $split[] = (string) $tag['nazev'];
    }
    if ($split !== []) {
        $event('tags.split', 'info', count($split) . ' tag(s) used in several language versions now have one tag per language', [
            'tags' => count($split), 'created' => $created, 'news_relinked' => $relinked, 'first' => array_slice($split, 0, 20)]);
    }
};
