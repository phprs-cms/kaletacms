<?php

declare(strict_types=1);

/**
 * The site search at scale (3.53, A7) – a dev tool, not in the package and not in tools/test.sh.
 *
 *   php tools/bench-search.php [--seed] [--runs=3]
 *   env: DB_HOST (127.0.0.1) DB_PORT (3306) DB_USER (root) DB_PASS () DB_NAME (kaleta_bench353)
 *        PAGES (5000) ITEMS (50000) NEWS (5000)
 *
 * --seed drops and creates the throwaway database DB_NAME from system/sql/schema.sql, fills it with generated pages,
 * collection items and news (deterministic) and fills the search index the way the background job does (slices of 20 s,
 * as from cron) – it prints how many runs that took. Then it times SiteSearch::find() for a rare word, a common word, two
 * words, no hit, two words without a full match, a Cyrillic and an Arabic word: the current search with the occurrences
 * ordering, the same without it (ORDER_BY_OCCURRENCES = false) and the search of 3.52.0 (from git), each the median of
 * --runs, with its peak memory. Never point DB_NAME at a database you want to keep.
 */

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Migration;
use Kaleta\Core\SearchIndex;

$options = getopt('', ['seed', 'runs:']);
$config = ['db' => ['host' => getenv('DB_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('DB_PORT') ?: 3306), 'name' => getenv('DB_NAME') ?: 'kaleta_bench353',
    'user' => getenv('DB_USER') ?: 'root', 'password' => (string) getenv('DB_PASS'), 'prefix' => 'ka_']];
$counts = ['pages' => (int) (getenv('PAGES') ?: 5000), 'items' => (int) (getenv('ITEMS') ?: 50000), 'news' => (int) (getenv('NEWS') ?: 5000)];
$runs = max(1, (int) ($options['runs'] ?? 3));

if (isset($options['seed'])) {
    if (!preg_match('/^[a-z0-9_]+$/', $config['db']['name']) || !str_contains($config['db']['name'], 'bench')) {
        exit("DB_NAME must contain \"bench\" – it is dropped.\n");
    }
    $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['db']['host'], $config['db']['port']), $config['db']['user'], $config['db']['password']);
    $server->exec('DROP DATABASE IF EXISTS ' . $config['db']['name']);
    $server->exec('CREATE DATABASE ' . $config['db']['name'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci');
    $db = Db::fromConfig($config['db']);
    foreach (Migration::statements((string) file_get_contents(KALETA_ROOT . '/system/sql/schema.sql'), 'ka_') as $sql) {
        $db->run($sql);
    }
    seed($db, $counts);
}

$app = new App($config);
$app->applyTimezone();
$db = $app->db();
$settings = $app->settings();

if (isset($options['seed'])) {
    $start = microtime(true);
    $jobs = 0;
    do {
        SearchIndex::run($db, $settings, 20.0);
        $jobs++;
    } while (array_sum(SearchIndex::pending($db, $settings)) > 0 && $jobs < 1000);
    $seconds = microtime(true) - $start;
    printf("Index filled: %d job runs of 20 s (cron), %.1f s of work in all – about %d visit slices of 1.5 s\n\n", $jobs, $seconds, (int) ceil($seconds / 1.5));
}

$sizes = $db->one('SELECT (SELECT COUNT(*) FROM {stranky}) AS pages, (SELECT COUNT(*) FROM {kolekce_polozky}) AS items, (SELECT COUNT(*) FROM {novinky}) AS news,
    (SELECT ROUND(SUM(LENGTH(hledani)) / 1048576, 1) FROM {stranky}) + (SELECT ROUND(SUM(LENGTH(hledani)) / 1048576, 1) FROM {kolekce_polozky}) + (SELECT ROUND(SUM(LENGTH(hledani)) / 1048576, 1) FROM {novinky}) AS mb');
printf("%s %s – %d pages, %d items, %d news, search text %.1f MB\n\n", $db->value('SELECT VERSION()'), PHP_VERSION, $sizes['pages'], $sizes['items'], $sizes['news'], $sizes['mb']);

$variants = [
    '3.53' => variant('Bench353', (string) file_get_contents(KALETA_ROOT . '/system/src/Core/SiteSearch.php'), []),
    '3.53 title order' => variant('Bench353Title', (string) file_get_contents(KALETA_ROOT . '/system/src/Core/SiteSearch.php'), ['ORDER_BY_OCCURRENCES = true' => 'ORDER_BY_OCCURRENCES = false']),
];
$old = (string) shell_exec('cd ' . escapeshellarg(KALETA_ROOT) . ' && git show v3.52.0:system/src/Core/SiteSearch.php 2>/dev/null');
if ($old !== '') {
    $variants['3.52.0'] = variant('Bench352', $old, []);
}
$queries = ['rare word' => 'vzacnyx', 'common word' => 'firma', 'two words' => 'firma sluzby', 'no hit' => 'zzqxnic',
    'two words, no full match' => 'vzacnyx zzqxnic', 'Cyrillic' => 'компания', 'Arabic' => 'شركه'];

printf("%-26s", 'query');
foreach ($variants as $name => $class) {
    printf(" | %-24s", $name . ' (s, MB, results/total)');
}
echo "\n";
foreach ($queries as $label => $q) {
    printf("%-26s", $label);
    foreach ($variants as $class) {
        $times = [];
        $peak = 0;
        $found = [];
        for ($i = 0; $i < $runs; $i++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $base = memory_get_usage();
            $t = microtime(true);
            $found = $class::find($app, $q, '');
            $times[] = microtime(true) - $t;
            $peak = max($peak, memory_get_peak_usage() - $base);
        }
        sort($times);
        printf(" | %6.3f %6.1f %5d/%-6s", $times[intdiv(count($times), 2)], $peak / 1048576, count($found['results']), (string) ($found['total'] ?? count($found['results'])));
    }
    echo "\n";
}

/**
 * A copy of a SiteSearch source as its own class (another name, some text replaced), so the variants run side by side.
 * eval() runs only the project's own source – the working tree or the tag v3.52.0 of this repository, nothing from outside.
 */
function variant(string $name, string $source, array $replace): string
{
    $source = strtr(str_replace('final class SiteSearch', 'final class ' . $name, $source), $replace);
    eval('?>' . $source);

    return 'Kaleta\\Core\\' . $name;
}

/**
 * Generated content: words from a fixed vocabulary (deterministic), HTML with links and images; a common word in about
 * 60 % of the rows, "sluzby" in about 30 %, a rare word in three rows, a Cyrillic and an Arabic word in about 1 %.
 *
 * @param array{pages: int, items: int, news: int} $counts
 */
function seed(Db $db, array $counts): void
{
    mt_srand(353);
    $syllables = ['ka', 'le', 'to', 'ma', 'ri', 'no', 'vé', 'stá', 'po', 'du', 'ře', 'ch', 'ny', 'ško', 'la', 'be', 'zi', 'ku', 'pa', 'mě', 'tr', 'os'];
    $vocabulary = [];
    while (count($vocabulary) < 3000) {
        $word = '';
        for ($i = mt_rand(2, 4); $i > 0; $i--) {
            $word .= $syllables[mt_rand(0, count($syllables) - 1)];
        }
        $vocabulary[$word] = true;
    }
    $vocabulary = array_keys($vocabulary);
    $sentence = function (int $words) use ($vocabulary): string {
        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = $vocabulary[(int) floor((mt_rand() / mt_getrandmax()) ** 2 * (count($vocabulary) - 1))]; // a few words are frequent
        }
        if (mt_rand(1, 100) <= 60) {
            $out[mt_rand(0, $words - 1)] = 'firma';
        }
        if (mt_rand(1, 100) <= 30) {
            $out[mt_rand(0, $words - 1)] = 'služby';
        }
        if (mt_rand(1, 100) === 1) {
            $out[mt_rand(0, $words - 1)] = 'компания';
        }
        if (mt_rand(1, 100) === 1) {
            $out[mt_rand(0, $words - 1)] = 'شركة';
        }

        return implode(' ', $out);
    };
    $html = fn (int $paragraphs): string => implode('', array_map(fn (): string => '<p>' . $sentence(mt_rand(40, 120)) . ' <a href="/media/firma/' . mt_rand() . '.pdf">' . $sentence(3)
        . '</a> <img src="/media/obrazky/firma-' . mt_rand() . '.jpg" alt=""></p>', range(1, $paragraphs)));
    $insert = function (string $table, array $columns, \Closure $row, int $count) use ($db): void {
        for ($from = 1; $from <= $count; $from += 500) {
            $rows = [];
            $params = [];
            for ($i = $from; $i < min($count + 1, $from + 500); $i++) {
                $values = $row($i);
                $rows[] = '(' . implode(', ', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            }
            $db->run('INSERT INTO {' . $table . '} (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $rows), $params);
        }
    };
    $rare = fn (int $i, int $count, string $text): string => in_array($i, [(int) ($count * 0.3), (int) ($count * 0.6), $count], true) ? $text . ' vzácnýx' : $text;
    $insert('stranky', ['seo_link', 'titulek', 'popis', 'text', 'zobrazit', 'v_menu', 'poradi'], fn (int $i): array => ['strana-' . $i, ucfirst($sentence(4)), $sentence(15),
        $rare($i, $counts['pages'], $html(mt_rand(3, 12))), 1, 0, $i % 1000], $counts['pages']);
    $db->run("INSERT INTO {kolekce} (nazev, seo_link, pole, detail) VALUES ('Katalog', 'katalog', ?, 1)",
        [json_encode([['klic' => 'popis', 'typ' => 'radky'], ['klic' => 'vlastnosti', 'typ' => 'parametry'], ['klic' => 'foto', 'typ' => 'obrazek'], ['klic' => 'web', 'typ' => 'odkaz']], JSON_UNESCAPED_UNICODE)]);
    $idk = (int) $db->value('SELECT MAX(idk) FROM {kolekce}');
    $insert('kolekce_polozky', ['idk', 'nazev', 'seo_link', 'popis', 'data', 'datum', 'zobrazit', 'poradi'], fn (int $i): array => [$idk, ucfirst($sentence(3)), 'polozka-' . $i, $sentence(10),
        json_encode(['popis' => $rare($i, $counts['items'], $sentence(mt_rand(20, 80))), 'vlastnosti' => 'Barva: ' . $sentence(1) . "\nFirma: " . $sentence(1),
            'foto' => '/media/firma/' . $i . '.jpg', 'web' => 'https://firma.example/' . $i], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), 1, $i % 1000], $counts['items']);
    $db->run("INSERT INTO {kategorie} (nazev, seo_link, popis) VALUES ('Novinky', 'novinky', '')");
    $tema = (int) $db->value('SELECT MAX(idt) FROM {kategorie}');
    $insert('novinky', ['seo_link', 'titulek', 'uvod', 'text', 'tema', 'datum', 'visible', 'noindex'], fn (int $i): array => ['zprava-' . $i, ucfirst($sentence(6)), '<p>' . $sentence(25) . '</p>',
        $rare($i, $counts['news'], $html(mt_rand(2, 8))), $tema, date('Y-m-d H:i:s', time() - $i * 3600), 1, 0], $counts['news']);
    $db->run("REPLACE INTO {nastaveni} (promenna, hodnota) VALUES ('db_version', ?), ('search_index_at', ?)", [(string) KALETA_DB_VERSION, Kaleta\Core\Search::VERSION . ':0']);
}
