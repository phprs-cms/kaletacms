echo "== 3.53 A2, A3: the search text of pages, collection items and news (Core\\SearchIndex) – migration 0141, the background job fills it in slices, resumes, never retries a row that cannot be read"
S910_NOW=$(site_time)
s910_null() { sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_stranky WHERE hledani IS NULL), '|', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE hledani IS NULL), '|', (SELECT COUNT(*) FROM ka_novinky WHERE hledani IS NULL))"; }
s910_complete() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $r = Kaleta\Core\SearchIndex::complete($app->db(), (float) $argv[1]); echo ($r["pages"] + $r["items"] + $r["news"]), "|", $r["left"] ? "left" : "done";' -- "$1"); }
s910_poison_log() { grep -c 'Array to string conversion in system/src/Core/SearchIndex.php' "$WORK/web/storage/log/chyby.log" 2>/dev/null || true; }

expect "3.53 A2: migration 0141 – hledani on pages and items is MEDIUMTEXT utf8mb4_bin NULL, no FULLTEXT left on the news, database version 141 or later (3.54: 142)" \
  "$(sq "SELECT CONCAT((SELECT GROUP_CONCAT(CONCAT(TABLE_NAME, ':', DATA_TYPE, ':', COLLATION_NAME, ':', IS_NULLABLE) ORDER BY TABLE_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ka_stranky', 'ka_kolekce_polozky') AND COLUMN_NAME = 'hledani'), '|',
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_novinky' AND INDEX_TYPE = 'FULLTEXT'), '|', (SELECT CAST(hodnota AS UNSIGNED) >= 141 FROM ka_nastaveni WHERE promenna = 'db_version'))")" \
  "ka_kolekce_polozky:mediumtext:utf8mb4_bin:YES,ka_stranky:mediumtext:utf8mb4_bin:YES|0|1"

# a builder page whose text column is older than its build, an item with fields of every kind, a Cyrillic news item, and an
# item of a collection whose field definition cannot be read (a poison row: its text throws)
S910_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1"); S910_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
S910_BUILD='{"v":1,"deti":[{"id":"s910a","typ":"nadpis","znacka":"h2","obsah":{"text":"Rekonstrukce koupelen S910"}},{"id":"s910b","typ":"text","obsah":{"html":"<p>Obklady Łódź</p>"}}]}'
S910_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, stavba, zobrazit) VALUES ('koupelny-s910', 'Koupelny S910', '<p>Stará verze S910</p>', '$S910_BUILD', 1); SELECT LAST_INSERT_ID();")
S910_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole) VALUES ('Stany S910', 'stany-s910', '[{\"klic\":\"podnadpis\",\"popisek\":\"Podnadpis\",\"typ\":\"text\"},{\"klic\":\"foto\",\"popisek\":\"Foto\",\"typ\":\"obrazek\"},{\"klic\":\"varianty\",\"popisek\":\"Varianty\",\"typ\":\"varianty\"}]'); SELECT LAST_INSERT_ID();")
S910_ITEM=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum) VALUES ($S910_IDK, 'Stan Horizont S910', 'stan-s910', '{\"podnadpis\":\"Lehký stan\",\"foto\":\"/media/stan-s910.jpg\",\"varianty\":\"Malý | ST-910 | 4990\"}', '$S910_NOW'); SELECT LAST_INSERT_ID();")
S910_BAD_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole) VALUES ('Vadná S910', 'vadna-s910', '[{\"klic\":[\"x\"],\"popisek\":\"X\",\"typ\":\"text\"}]'); SELECT LAST_INSERT_ID();")
S910_BAD=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum) VALUES ($S910_BAD_IDK, 'Vadná položka S910', 'vadna-s910', '{\"x\":\"y\"}', '$S910_NOW'); SELECT LAST_INSERT_ID();")
# the news item as a 3.52 index left it: version 1 dropped the Cyrillic words
S910_NEWS=$(sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, autor, hledani) VALUES ('novosti-s910', 'Новости S910', '', '<p>Київ і Львів</p>', $S910_TEMA, '$S910_NOW', 1, $S910_ADMIN, 's910'); SELECT LAST_INSERT_ID();")

# as right after the update from 3.52: nothing indexed, the news index of version 1 to be re-made
sq "UPDATE ka_stranky SET hledani = NULL; UPDATE ka_kolekce_polozky SET hledani = NULL; UPDATE ka_novinky SET hledani = NULL WHERE idc <> $S910_NEWS;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', ''), ('search_index_checked', '')" > /dev/null
S910_BEFORE=$(s910_null)
# a run cut short (a killed request, a visit's small budget): what it wrote stays (one short transaction a row), the next run
# goes on with the rows still NULL
S910_FIRST=$(sq "SELECT MIN(ids) FROM ka_stranky WHERE hledani IS NULL")
S910_SHORT=$(s910_complete 0.000001)
expect "3.53 A3: a run with almost no time writes one row – the first page waiting – and says more is left" \
  "$S910_SHORT|$(sq "SELECT hledani IS NOT NULL FROM ka_stranky WHERE ids = $S910_FIRST")|$([ "${S910_BEFORE%%|*}" -gt 1 ] && echo pages-waited)" "1|left|1|pages-waited"
S910_FILLED=0; fill_search_index || S910_FILLED=$?
expect "3.53 A3: cron runs of the job alone bring the waiting rows to 0 – no NULL left on pages, items and news, the news index re-made to version 2" \
  "$S910_FILLED|$(s910_null)|$(search_index_pending)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'")" "0|0|0|0|0|2:0"
expect "3.53 A3: the page text is its published build (not the older text column), normalized; the item text has the text and variant fields, not the image path" \
  "$(sq "SELECT CONCAT(hledani LIKE '%rekonstrukce koupelen s910 obklady lodz%', hledani LIKE '%stara verze%' OR hledani LIKE '%Ł%') FROM ka_stranky WHERE ids = $S910_PAGE")|$(sq "SELECT CONCAT(hledani LIKE 'stan horizont s910 %', hledani LIKE '%lehky stan%', hledani LIKE '%maly st 910 4990%', hledani LIKE '%media%') FROM ka_kolekce_polozky WHERE idp = $S910_ITEM")" \
  "10|1110"
expect "3.53 A3: the news index of version 1 is re-made row by row – the Cyrillic words are in now" \
  "$(sq "SELECT hledani FROM ka_novinky WHERE idc = $S910_NEWS")" "новости s910 київ і львів"
expect "3.53 A3: the poison row is written empty and logged once; the next run of the job does not read it again" \
  "$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_kolekce_polozky WHERE idp = $S910_BAD")|$(s910_poison_log)|$(run_job search_index | grep -c 'could not be read')|$(s910_poison_log)" "|1|0|1"
check "3.53 A3: System status has the row of the search index – nothing waits" 200 "/admin.php?module=status" "Vyhledávací index"
contains -q 'každou stránku, položku i novinku lze najít' "$WORK/response" && echo "  ok     3.53 A3: … every page, item and news item can be found" || { echo "  CHYBA  3.53 A3: status row of the search index"; ERRORS=$((ERRORS+1)); }
expect "3.53 A3: the job remembers the waiting rows of its last two runs, the label is the new one, it runs after the mail and the webhooks" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_checked'")|$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $j = array_keys(Kaleta\Core\Scheduler::jobs()); echo Kaleta\Core\Scheduler::JOBS["search_index"][2], "|", (int) (array_search("search_index", $j, true) > array_search("webhooks", $j, true));')" \
  "0:0|Search index filled in|1"

# the poison row's log lines go (the suite fails on any line left in the error log); the seeded rows go
php -r '$f = $argv[1]; file_put_contents($f, implode("", array_filter(file($f) ?: [], fn ($r) => !str_contains($r, "Array to string conversion in system/src/Core/SearchIndex.php"))));' -- "$WORK/web/storage/log/chyby.log"
sq "DELETE FROM ka_kolekce WHERE idk IN ($S910_IDK, $S910_BAD_IDK); DELETE FROM ka_stranky WHERE ids = $S910_PAGE; DELETE FROM ka_novinky WHERE idc = $S910_NEWS" > /dev/null
