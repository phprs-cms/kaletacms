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
 *    to do. Settings → General runs this file again once the cause is fixed (Core\Slug::enableEverywhere). A site that was
 *    on already (switched in 3.9–3.18, a global key left by a partial restore) stays on when this step fails – the failure
 *    is recorded the same way, the keys it has are not touched (N319-1).
 * 3. Tags (only when slugs are per language): a tag used by news items of several language versions becomes one tag per
 *    language and the news are linked to the tag of their own language. The tag keeps its row (id, description, image) in
 *    its own language when news of that language use it, otherwise in the language with the most of its news items (a tie:
 *    the smaller language code); every other language gets the existing tag with that slug in that language, or a copy of
 *    this one (name, slug, description, image) – languages in the order of their codes, so a second run changes nothing.
 *
 * Self-contained on purpose: the update request of 3.18.3 runs it with its own classes already loaded, so only Db, Settings
 * and Core\RedirectSources (unchanged since 3.14) are used. Safe to run again: every step looks at the state first.
 * Runs under the migration lock (Core\Migration::apply, Core\Slug::enableEverywhere); on a web request with a time limit
 * it gets 300 seconds, as the updater gives an update (a large site's first request after an upload by FTP, N319-5).
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    if ((int) ini_get('max_execution_time') > 0) {
        @set_time_limit(300); // N319-5: the redirect fill and the tag split of a large site on a host with 30 seconds
    }
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
    $wasOn = $on; // N319-1: a site that was on already is never switched off here
    $globalLeft = array_filter($tables, static fn (array $t, string $table): bool => $hasKey($table, $t[1]), ARRAY_FILTER_USE_BOTH);
    // a failure recorded on a site that is on (N319-1) is looked at again too, so a run after the fix clears it
    $failedBefore = (string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'slugs_switch_failed'") !== '';
    if (!$on || $globalLeft !== [] || $failedBefore) {
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
                // a site that was off stays as it was: the keys this run dropped come back; one that was on keeps checking
                // per language, so a global key is not put back there (N319-1)
                foreach ($wasOn ? [] : $dropped as $table => $global) {
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
            if (!$wasOn) {
                $settings->set('slugs_per_language', '0');
            }
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
    $pdo = $db->pdo();
    foreach ($usage as $ids => ['own' => $own, 'languages' => $languages]) {
        if (array_keys($languages) === [$own]) {
            continue; // every news item of the tag is in its language
        }
        // N319-4: one tag in one transaction (the tables are InnoDB) – its move, its copies, the links copied and the old
        // ones removed together; a run stopped halfway leaves the tag as it was, and the next run splits it
        $pdo->beginTransaction();
        try {
            $tag = $db->one('SELECT * FROM {stitky} WHERE ids = ? FOR UPDATE', [$ids]);
            if ($tag === null) {
                $pdo->commit();
                continue;
            }
            ksort($languages, SORT_STRING);
            $keeper = $own;
            $copies = 0;
            $moved = 0;
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
                    $copies++;
                }
                // duplicates handled explicitly (no INSERT IGNORE, which would also hide a foreign-key error): only news not
                // linked to the target yet get a link, and only links whose copy exists are removed
                $missing = array_map('intval', array_column($db->all('SELECT ns.idc FROM {novinky_stitky} ns JOIN {novinky} n ON n.idc = ns.idc
                    WHERE ns.ids = ? AND n.jazyk = ? AND NOT EXISTS (SELECT 1 FROM {novinky_stitky} x WHERE x.idc = ns.idc AND x.ids = ?)', [$ids, $language, (int) $target]), 'idc'));
                foreach (array_chunk($missing, 500) as $chunk) {
                    $db->run('INSERT INTO {novinky_stitky} (idc, ids) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                        array_merge(...array_map(static fn (int $idc): array => [$idc, (int) $target], $chunk)));
                }
                $moved += $db->run('DELETE ns FROM {novinky_stitky} ns JOIN {novinky} n ON n.idc = ns.idc JOIN {novinky_stitky} x ON x.idc = ns.idc AND x.ids = ?
                    WHERE ns.ids = ? AND n.jazyk = ?', [(int) $target, $ids, $language])->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e; // the migration is not recorded as done – the next request runs it again
        }
        $created += $copies;
        $relinked += $moved;
        $split[] = (string) $tag['nazev'];
    }
    if ($split !== []) {
        $event('tags.split', 'info', count($split) . ' tag(s) used in several language versions now have one tag per language', [
            'tags' => count($split), 'created' => $created, 'news_relinked' => $relinked, 'first' => array_slice($split, 0, 20)]);
    }
};
