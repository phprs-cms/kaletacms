#!/usr/bin/env bash
# Kaleta – database upgrade test: the schema of an older release plus the current migrations must give the same structure
# as a fresh install (tables, columns, types, indexes), migrations must be repeatable, and data migrations must do their job.
# Same env as tools/test.sh: DB_HOST DB_PORT DB_USER DB_PASS; DB_NAME is used as a prefix (…_old, …_new). FROM = release tag (v1.0.0).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-kaleta_test_mig}"; FROM="${FROM:-v1.0.0}"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
# the database session runs in UTC (as the MySQL service on CI), seeded times come from the site's clock (Europe/Prague)
site_time() { php -d date.timezone=Europe/Prague -r 'echo date($argv[2], strtotime($argv[1]));' -- "${1:-now}" "${2:-Y-m-d H:i:s}"; }
OLD="${DB_NAME}_old"; NEW="${DB_NAME}_new"; ERRORS=0
cleanup() { "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$OLD\`; DROP DATABASE IF EXISTS \`$NEW\`" 2>/dev/null; }
trap cleanup EXIT

echo "== database upgrade from $FROM"
OLD_DB_VERSION=$(git -C "$ROOT" show "${FROM}:system/bootstrap.php" | sed -nE 's/^const KALETA_(VERZE_DB|DB_VERSION) = ([0-9]+);/\2/p') # renamed in 1.4
[ -n "$OLD_DB_VERSION" ] || { echo "  CHYBA  cannot read the database version of $FROM"; exit 1; }
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$OLD\`; DROP DATABASE IF EXISTS \`$NEW\`; CREATE DATABASE \`$OLD\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci; CREATE DATABASE \`$NEW\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
git -C "$ROOT" show "${FROM}:system/sql/schema.sql" | "${MYSQL[@]}" "$OLD" || { echo "  CHYBA  old schema"; exit 1; }
"${MYSQL[@]}" "$NEW" < "$ROOT/system/sql/schema.sql" || { echo "  CHYBA  current schema"; exit 1; }
# a site as it was: its settings, including values that data migrations take over
"${MYSQL[@]}" "$OLD" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('verze_db', '$OLD_DB_VERSION'), ('email_webu', 'owner@example.com'), ('nazev_webu_en', 'Northfield')"

migrate() {
  php -r '
    require $argv[1] . "/system/bootstrap.php";
    $db = new Kaleta\Core\Db(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $argv[2], (int) $argv[3], $argv[6]), $argv[4], $argv[5]);
    try { echo implode(",", Kaleta\Core\Migration::apply($db, new Kaleta\Core\Settings($db))), "\n"; }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
  ' "$ROOT" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$OLD"
}
if OUTPUT=$(migrate 2>&1); then echo "  ok     migrations ran: ${OUTPUT:-none}"; else echo "  CHYBA  migration failed: $OUTPUT"; ERRORS=$((ERRORS+1)); fi
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = '$OLD_DB_VERSION' WHERE promenna = 'db_version'"
# admin idents of 1.3 in a role and the change log: 0025 renames them to the English ones (Admin\LegacyUrls)
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_role (nazev, uroven, moduly) VALUES ('Legacy', 0, 'stranky,novinky,config'); INSERT INTO ka_protokol (cas, modul, akce) VALUES ('$(site_time)', 'intergal', 'uloz'); INSERT INTO ka_oauth_klienti (client_id, nazev, presmerovani, vytvoren) VALUES (REPEAT('a', 32), 'Claude', '[]', '$(site_time)'); UPDATE ka_nastaveni SET hodnota = '24' WHERE promenna = 'db_version'"
if OUTPUT=$(migrate 2>&1); then echo "  ok     migrations are repeatable"; else echo "  CHYBA  second run failed: $OUTPUT"; ERRORS=$((ERRORS+1)); fi

structure() {
  "${MYSQL[@]}" -N -e "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, 'NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' ORDER BY TABLE_NAME, COLUMN_NAME;
    SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$1' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME"
}
DIFF=$(diff <(structure "$OLD") <(structure "$NEW"))
[ -z "$DIFF" ] && echo "  ok     upgraded database = fresh install (columns and indexes)" || { echo "  CHYBA  upgraded database differs from a fresh install:"; echo "$DIFF" | head -30; ERRORS=$((ERRORS+1)); }

LAST_MIGRATION=$(ls "$ROOT"/system/sql/migrace/[0-9]*-*.sql "$ROOT"/system/sql/migrace/[0-9]*-*.php 2>/dev/null | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
[ "$("${MYSQL[@]}" "$OLD" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" = "$LAST_MIGRATION" ] && echo "  ok     verze_db = $LAST_MIGRATION" || { echo "  CHYBA  verze_db after upgrade"; ERRORS=$((ERRORS+1)); }
[ "$("${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT((SELECT moduly FROM ka_role WHERE nazev = 'Legacy'), '|', (SELECT CONCAT(modul, ':', akce) FROM ka_protokol ORDER BY idp DESC LIMIT 1))")" = "pages,news,settings|media:save" ] && echo "  ok     1.3 admin idents in roles and the change log are English" || { echo "  CHYBA  admin idents not migrated"; ERRORS=$((ERRORS+1)); }
[ "$("${MYSQL[@]}" "$OLD" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_email'")" = "owner@example.com" ] && echo "  ok     existing site keeps its public contact email" || { echo "  CHYBA  firma_email not taken over"; ERRORS=$((ERRORS+1)); }

expect_sql() { local got; got=$("${MYSQL[@]}" "$OLD" -N -e "$2"); [ "$got" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: $got"; ERRORS=$((ERRORS+1)); }; }
expect_sql "settings keys of 1.4.0 renamed (0026), per-language ones too" "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name_en'))" "owner@example.com|Northfield"
expect_sql "no settings row left under an old key" "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('verze_db', 'email_webu', 'nazev_webu_en', 'firma_email')" "0"
expect_sql "a site from before 2.2 keeps its extensions (0035) – the Claude connection does not switch itself on" "SELECT hodnota <> '' AND hodnota NOT LIKE '%claude%' FROM ka_nastaveni WHERE promenna = 'extensions'" "1"
expect_sql "3.3.4 (0076): an OAuth client registered before the upgrade counts as approved – the daily clean-up never removes it" "SELECT approved = vytvoren FROM ka_oauth_klienti WHERE client_id = REPEAT('a', 32)" "1"

# 3.9 (0083): the language columns hold a BCP 47 tag – VARCHAR(35) in ASCII on an upgraded site as on a fresh one (the
# structure check above compares the type, not the character set), and a site's language values stay as they were
langcols() { "${MYSQL[@]}" -N -e "SELECT GROUP_CONCAT(CONCAT(TABLE_NAME, '.', COLUMN_NAME, ':', COLUMN_TYPE, ':', CHARACTER_SET_NAME) ORDER BY TABLE_NAME, COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' AND COLUMN_NAME IN ('jazyk', 'language')"; }
[ "$(langcols "$OLD")" = "$(langcols "$NEW")" ] && [ "$(langcols "$OLD" | grep -o ':varchar(35):ascii' | wc -l | tr -d ' ')" = 15 ] && echo "  ok     3.9 (0083) and 3.14 (0095): the 15 language columns are VARCHAR(35) ASCII, as on a fresh install" || { echo "  CHYBA  0083/0095 language columns: $(langcols "$OLD")"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stranky (ids, seo_link, titulek, text, jazyk) VALUES (9083, 'kontakt-83', 'Kontakt', '', ''), (9084, 'contact-83', 'Contact', '', 'en')"
# 3.19 (0105, 0106): the global keys are gone after the upgrade (the empty site could switch), the per-language ones stay
expect_sql "3.9 (0083), 3.19 (0106): pages, news and categories keep the per-language slug key, the global one is gone" \
  "SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND INDEX_NAME IN ('uq_stranky_seo', 'uq_stranky_jazyk_seo', 'uq_clanky_seo', 'uq_clanky_jazyk_seo', 'uq_topic_seo', 'uq_topic_jazyk_seo') AND SEQ_IN_INDEX = 1" "uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo"
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stranky (ids, seo_link, titulek, text, jazyk) VALUES (9085, 'kontakt-83', 'Contact', '', 'en')" 2>/dev/null && echo "  ok     3.19 (0106): … so the same slug in another language is stored" || { echo "  CHYBA  0106: the same slug in another language refused"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stranky (ids, seo_link, titulek, text, jazyk) VALUES (9086, 'kontakt-83', 'Contact', '', 'en')" 2>/dev/null && { echo "  CHYBA  0106: a duplicate slug in one language passed"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.9 (0083): … and a duplicate in the same language is refused by the database"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_stranky WHERE ids = 9085"
expect_sql "3.9 (0083): language values read back unchanged" "SELECT GROUP_CONCAT(CONCAT(ids, ':', jazyk, ':', LENGTH(jazyk)) ORDER BY ids) FROM ka_stranky WHERE ids IN (9083, 9084)" "9083::0,9084:en:2"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_stranky WHERE ids IN (9083, 9084)"

# 3.14 (0095): tags get a language; on a site with "the same address in every language version" switched on, the global
# tag key goes with the migration (the other global keys went with the switch). The column is taken off again and the
# migration runs again from version 94 with the setting on; the tags keep their rows and become the default language.
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stitky (ids, nazev, seo_link) VALUES (9095, 'Trh', 'trh-95'); ALTER TABLE ka_stitky DROP INDEX uq_stitky_jazyk_seo, DROP COLUMN jazyk;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('slugs_per_language', '1'); UPDATE ka_nastaveni SET hodnota = '94' WHERE promenna = 'db_version'" && migrate > /dev/null
tagkeys() { "${MYSQL[@]}" -N -e "SELECT GROUP_CONCAT(DISTINCT INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND TABLE_NAME = 'ka_stitky' AND INDEX_NAME LIKE 'uq_%'"; }
expect_sql "3.14 (0095): with slugs per language on, the tags lose the global key and keep the per-language one; an existing tag is the default language" \
  "SELECT CONCAT('$(tagkeys)', '|', (SELECT CONCAT(jazyk, ':', seo_link) FROM ka_stitky WHERE ids = 9095))" "uq_stitky_jazyk_seo|:trh-95"
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stitky (ids, nazev, seo_link, jazyk) VALUES (9096, 'Market', 'trh-95', 'en')" && echo "  ok     3.14 (0095): … so an English tag can have the slug of a Czech one" || { echo "  CHYBA  0095: the same tag slug in another language refused"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_stitky WHERE ids IN (9095, 9096)" # 3.19: the site keeps per-language slugs (no global tag key)

# 3.10 (0085): a Claude connection and an OAuth code from before the upgrade keep every tool (tool_set all) – the column is
# taken off again, rows are added as 3.9.2 wrote them, and the migration runs again from version 84
"${MYSQL[@]}" "$OLD" -e "ALTER TABLE ka_api_tokeny DROP COLUMN tool_set; ALTER TABLE ka_oauth_kody DROP COLUMN tool_set; SET FOREIGN_KEY_CHECKS = 0;
  INSERT INTO ka_api_tokeny (idu, nazev, klient, druh, access, otisk, vytvoren) VALUES (9085, 'Claude', REPEAT('d', 32), 'obnova', 'drafts', REPEAT('e', 64), '$(site_time)');
  INSERT INTO ka_oauth_kody (otisk, client_id, idu, presmerovani, vyzva, access, expirace) VALUES (REPEAT('f', 64), REPEAT('d', 32), 9085, 'https://claude.ai/cb', 'x', 'read', '$(site_time)');
  SET FOREIGN_KEY_CHECKS = 1; UPDATE ka_nastaveni SET hodnota = '84' WHERE promenna = 'db_version'" && migrate > /dev/null
expect_sql "3.10 (0085): an existing connection and code keep all tools and their access" "SELECT CONCAT((SELECT CONCAT(access, ':', tool_set) FROM ka_api_tokeny WHERE idu = 9085), '|', (SELECT CONCAT(access, ':', tool_set) FROM ka_oauth_kody WHERE idu = 9085))" "drafts:all|read:all"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_api_tokeny WHERE idu = 9085; DELETE FROM ka_oauth_kody WHERE idu = 9085"

# 3.12 (0092): tokens, hashes and client ids are ASCII binary on an upgraded site as on a fresh one (the structure check
# above compares the type, not the collation), the values stay, and a lookup by token still uses its unique key
tokencols() { "${MYSQL[@]}" -N -e "SELECT GROUP_CONCAT(CONCAT(TABLE_NAME, '.', COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE, ':', COALESCE(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' AND COLLATION_NAME = 'utf8mb4_bin'"; }
# 3.14 (0094) adds the 21st: ka_presmerovani.source_norm, the normalised old address of a redirect
[ "$(tokencols "$OLD")" = "$(tokencols "$NEW")" ] && [ "$(tokencols "$OLD" | tr ',' '\n' | grep -c .)" = 21 ] && tokencols "$OLD" | grep -q 'ka_presmerovani.source_norm:varchar(255):YES:NULL' && echo "  ok     3.12 (0092), 3.14 (0094): the 20 token and hash columns and the redirect source_norm are utf8mb4_bin, as on a fresh install" || { echo "  CHYBA  0092 token columns: $(tokencols "$OLD")"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "SET FOREIGN_KEY_CHECKS = 0; INSERT INTO ka_api_tokeny (idu, nazev, klient, druh, otisk, vytvoren) VALUES (9092, 'Claude', REPEAT('a', 32), 'token', REPEAT('9', 64), '$(site_time)'); SET FOREIGN_KEY_CHECKS = 1"
expect_sql "3.12 (0092): a token reads back by its hash and the client id compares exactly (no case folding)" "SELECT CONCAT((SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = REPEAT('9', 64)), (SELECT COUNT(*) FROM ka_api_tokeny WHERE idu = 9092 AND klient = REPEAT('A', 32)))" "10"
# the traditional EXPLAIN names the key: MySQL takes FORMAT=TRADITIONAL (its default may be the tree), MariaDB knows only the plain one
EXPLAIN312=$("${MYSQL[@]}" "$OLD" -e "EXPLAIN FORMAT=TRADITIONAL SELECT idt FROM ka_api_tokeny WHERE otisk = REPEAT('9', 64)" 2>/dev/null \
  || "${MYSQL[@]}" "$OLD" -e "EXPLAIN SELECT idt FROM ka_api_tokeny WHERE otisk = REPEAT('9', 64)" 2>&1)
grep -q uq_tokeny_otisk <<<"$EXPLAIN312" \
  && echo "  ok     3.12 (0092): the lookup by token hash uses uq_tokeny_otisk" || { echo "  CHYBA  0092: the token lookup does not use its key"; echo "$EXPLAIN312"; "${MYSQL[@]}" "$OLD" -e "SHOW CREATE TABLE ka_api_tokeny; SELECT COUNT(*) FROM ka_api_tokeny" 2>&1 | head -40; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_api_tokeny WHERE idu = 9092"

# 3.2 (0073): a site as 3.1.1 left it gets the new feature defaults – Bookings and Whistleblowing stay on where they are in
# use and are off where not; Statistics are off where the old "stats" setting was off. The data migration runs again by name.
site_311() { "${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_bookings; DELETE FROM ka_booking_services; DELETE FROM ka_whistleblowing_cases;
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0073-feature-defaults,', ',')) WHERE promenna = 'data_migrations';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$1'), ('whistleblowing_enabled', '$2'), ('stats', '$3'); $4" && migrate > /dev/null; }
features() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'stats'))"; }
site_311 'novinky,poptavky,statistika,presmerovani,claude' 1 0 "INSERT INTO ka_booking_services (name) VALUES ('Haircut')"
[ "$(features)" = "novinky,poptavky,bookings,presmerovani,whistleblowing,claude|1" ] && echo "  ok     3.2 (0073): a site with booking services and an open whistleblowing channel keeps both; statistics that were off stay off" || { echo "  CHYBA  0073 on a site that uses the features: $(features)"; ERRORS=$((ERRORS+1)); }
site_311 'novinky,poptavky,statistika,presmerovani,claude' 0 1 ""
[ "$(features)" = "novinky,poptavky,statistika,presmerovani,claude|1" ] && echo "  ok     3.2 (0073): a site without bookings or whistleblowing hides both and keeps its statistics" || { echo "  CHYBA  0073 on a site that does not use the features: $(features)"; ERRORS=$((ERRORS+1)); }
site_311 '' 0 1 "INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, token_hash, created_at) VALUES (1, 1, '$(site_time)', '$(site_time)', REPEAT('b', 64), '$(site_time)'); INSERT INTO ka_whistleblowing_cases (number, created_at, feedback_due, text, code_hash) VALUES ('2026-0001', '$(site_time)', '$(site_time)', 'x', REPEAT('c', 64))"
[ "$(features)" = "novinky,poptavky,bookings,statistika,presmerovani,whistleblowing,claude|1" ] && echo "  ok     3.2 (0073): a site that never saved its choice gets its old defaults written down, plus the features its bookings and cases use" || { echo "  CHYBA  0073 on a site without a saved choice: $(features)"; ERRORS=$((ERRORS+1)); }
site_311 'novinky,claude' 0 0 "" && "${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = 'novinky,statistika,claude' WHERE promenna = 'extensions'; UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0073-feature-defaults,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
[ "$(features)" = "novinky,statistika,claude|1" ] && echo "  ok     3.2 (0073): run again, it does not switch off the statistics the administrator switched on afterwards" || { echo "  CHYBA  0073 run again: $(features)"; ERRORS=$((ERRORS+1)); }

# 3.3.3 (0074, N63): content imported before 3.3.2 is checked again – only risky markup of imported records changes (the
# version before goes into the history), everything else stays byte for byte; running it again changes nothing more
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_import_mapa; DELETE FROM ka_nastaveni WHERE promenna = 'imported_recheck';
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0074-imported-content-recheck,', ',')) WHERE promenna = 'data_migrations';
  INSERT INTO ka_stranky (ids, seo_link, titulek, text, stavba) VALUES
    (9001, 'n63-wp', 'WP', '<p>Hi<img src=\"a.jpg\" onerror=\"alert(1)\"></p>', NULL),
    (9002, 'n63-clean', 'Clean', '<p class=\"lead\">Fine&nbsp;text <a href=\"https://example.com/\">x</a></p>', NULL),
    (9003, 'n63-raw', 'Raw', '<p><img alt=\"<b>x</b>\" src=\"a.jpg\"></p>', NULL),
    (9004, 'n63-own', 'Own', '<p>Hi<img src=\"a.jpg\" onerror=\"alert(1)\"></p>', NULL),
    (9005, 'n63-build', 'Build', '', '{\"v\":1,\"deti\":[{\"id\":\"txt001\",\"typ\":\"text\",\"znacka\":\"div\",\"obsah\":{\"html\":\"<p onclick=\\\\\"x()\\\\\">T</p>\"}},{\"id\":\"htm001\",\"typ\":\"html\",\"znacka\":\"div\",\"obsah\":{\"html\":\"<script>own()</script>\"}}]}');
  INSERT INTO ka_kategorie (idt, nazev, seo_link, popis) VALUES (9001, 'N63', 'n63', '');
  INSERT INTO ka_novinky (idc, seo_link, titulek, uvod, text, tema, datum) VALUES (9001, 'n63-news', 'News', '<p>Intro</p>', '<p><a href=\"javascript:alert(1)\">x</a> ok</p>', 9001, '$(site_time)');
  INSERT INTO ka_import_mapa (zdroj, typ, cizi_id, nase_id) VALUES ('wp:old.example', 'stranka', '1', 9001), ('web:old.example', 'stranka', '2', 9002),
    ('web:old.example', 'stranka', '3', 9003), ('web:old.example', 'stranka', '5', 9005), ('wp:old.example', 'clanek', '7', 9001)" && migrate > /dev/null
n63() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT text FROM ka_stranky WHERE ids = 9001), (SELECT text FROM ka_stranky WHERE ids = 9002), (SELECT text FROM ka_stranky WHERE ids = 9003),
  (SELECT text FROM ka_stranky WHERE ids = 9004), (SELECT stavba LIKE '%onclick%' FROM ka_stranky WHERE ids = 9005), (SELECT stavba LIKE '%<script>own()</script>%' FROM ka_stranky WHERE ids = 9005),
  (SELECT text FROM ka_novinky WHERE idc = 9001), (SELECT COUNT(*) FROM ka_stranky_revize WHERE ids IN (9001, 9003)), (SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = 9005),
  (SELECT COUNT(*) FROM ka_novinky_revize WHERE idc = 9001), (SELECT CONCAT_WS(',', JSON_EXTRACT(hodnota, '$.done'), JSON_EXTRACT(hodnota, '$.checked'), JSON_EXTRACT(hodnota, '$.changed')) FROM ka_nastaveni WHERE promenna = 'imported_recheck'))"; }
N63_EXPECTED='<p>Hi<img src="a.jpg" alt="" loading="lazy"></p>|<p class="lead">Fine&nbsp;text <a href="https://example.com/">x</a></p>|<p><img alt="&lt;b&gt;x&lt;/b&gt;" src="a.jpg"></p>|<p>Hi<img src="a.jpg" onerror="alert(1)"></p>|0|1|<p>x ok</p>|2|1|1|true,5,4' # each key by name: MariaDB returns several JSON paths in document order, MySQL in the order asked
[ "$(n63)" = "$N63_EXPECTED" ] && echo "  ok     3.3.3 (0074): imported content re-checked – risky markup removed with a revision, clean and own content untouched, Custom HTML kept" || { echo "  CHYBA  0074: $(n63)"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0074-imported-content-recheck,', ',')) WHERE promenna = 'data_migrations'; DELETE FROM ka_nastaveni WHERE promenna = 'imported_recheck'" && migrate > /dev/null
[ "$(n63)" = "${N63_EXPECTED%|*}|true,5,0" ] && echo "  ok     3.3.3 (0074): run again, nothing changes and no new revisions" || { echo "  CHYBA  0074 run again: $(n63)"; ERRORS=$((ERRORS+1)); }

# 3.18.3 (0103): every existing collection gets its own item in the admin menu – the column is taken off again, a
# collection is added as 3.18.2 wrote it, and the migration runs again from version 102
"${MYSQL[@]}" "$OLD" -e "ALTER TABLE ka_kolekce DROP COLUMN admin_menu; INSERT INTO ka_kolekce (idk, nazev, seo_link, pole) VALUES (9103, 'Produkty', 'produkty-103', '[]');
  UPDATE ka_nastaveni SET hodnota = '102' WHERE promenna = 'db_version'" && migrate > /dev/null
expect_sql "3.18.3 (0103): an existing collection is in the admin menu after the upgrade, a new one too" \
  "SELECT CONCAT((SELECT admin_menu FROM ka_kolekce WHERE idk = 9103), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'))" "1|$LAST_MIGRATION"
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_kolekce (idk, nazev, seo_link, pole) VALUES (9104, 'Jobs', 'jobs-103', '[]')"
expect_sql "3.18.3 (0103): … a collection created afterwards is in it by default" "SELECT admin_menu FROM ka_kolekce WHERE idk = 9104" "1"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_kolekce WHERE idk IN (9103, 9104)"

# 3.19 (0105, 0106): a site as 3.18.3 left it – the setting off, the global keys, no plain lookup keys, a tag shared by a
# Czech and an English news item, a tag from before 3.14 used only by English news, redirects not normalised yet (two of
# them collide once compared exactly) – then 0105 and 0106 run again; a second run changes nothing; a site whose two
# categories of one language share a slug (no key holds them apart) is left as it is and says why
"${MYSQL[@]}" "$OLD" -e "SET FOREIGN_KEY_CHECKS = 0;
  ALTER TABLE ka_stranky ADD UNIQUE KEY uq_stranky_seo (seo_link), DROP INDEX ix_stranky_seo; ALTER TABLE ka_novinky ADD UNIQUE KEY uq_clanky_seo (seo_link), DROP INDEX ix_clanky_seo;
  ALTER TABLE ka_kategorie ADD UNIQUE KEY uq_topic_seo (seo_link), DROP INDEX ix_topic_seo; ALTER TABLE ka_stitky ADD UNIQUE KEY uq_stitky_seo (seo_link), DROP INDEX ix_stitky_seo;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('slugs_per_language', '0');
  INSERT INTO ka_kategorie (idt, nazev, seo_link, popis, jazyk) VALUES (9190, 'Zpravy', 'zpravy-190', '', ''), (9191, 'News', 'news-191', '', 'en');
  INSERT INTO ka_novinky (idc, seo_link, titulek, uvod, text, tema, datum, jazyk) VALUES (9190, 'trh-190', 'Trh', '', '', 9190, '$(site_time)', ''), (9191, 'fair-191', 'Fair', '', '', 9191, '$(site_time)', 'en'), (9192, 'fair-192', 'Fair 2', '', '', 9191, '$(site_time)', 'en');
  INSERT INTO ka_stitky (ids, nazev, seo_link, jazyk, popis) VALUES (9190, 'Trh', 'trh-190', '', NULL), (9191, 'Stary', 'stary-191', '', '<p>T</p>');
  INSERT INTO ka_novinky_stitky (idc, ids) VALUES (9190, 9190), (9191, 9190), (9192, 9190), (9191, 9191);
  INSERT INTO ka_presmerovani (idp, z_adresy, source_norm, na_adresu, vytvoreno) VALUES (9190, 'Cenik-190/', NULL, 'a', '$(site_time)'), (9191, 'cen%C3%ADk-190', NULL, 'b', '$(site_time)'), (9192, 'jiny-190', NULL, 'c', '$(site_time)');
  UPDATE ka_nastaveni SET hodnota = '104' WHERE promenna = 'db_version';
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations';
  SET FOREIGN_KEY_CHECKS = 1" && migrate > /dev/null
s319() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'),
  (SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND INDEX_NAME IN ('uq_stranky_seo', 'uq_clanky_seo', 'uq_topic_seo', 'uq_stitky_seo', 'ix_stranky_seo', 'ix_clanky_seo', 'ix_topic_seo', 'ix_stitky_seo') AND SEQ_IN_INDEX = 1),
  (SELECT GROUP_CONCAT(CONCAT(t.jazyk, ':', t.seo_link, ':', ns.idc) ORDER BY t.seo_link, t.jazyk, ns.idc) FROM ka_stitky t JOIN ka_novinky_stitky ns ON ns.ids = t.ids WHERE t.seo_link IN ('trh-190', 'stary-191')),
  (SELECT CONCAT(jazyk, ':', popis) FROM ka_stitky WHERE ids = 9191), (SELECT COUNT(*) FROM ka_stitky WHERE seo_link IN ('trh-190', 'stary-191')),
  (SELECT GROUP_CONCAT(HEX(source_norm) ORDER BY idp) FROM ka_presmerovani WHERE idp BETWEEN 9190 AND 9192),
  (SELECT GROUP_CONCAT(type ORDER BY id) FROM ka_events WHERE type IN ('slugs.per_language', 'slugs.per_language_failed', 'tags.split', 'redirect.collisions')))"; }
S319_EXPECTED='1|ix_clanky_seo,ix_stitky_seo,ix_stranky_seo,ix_topic_seo|en:stary-191:9191,:trh-190:9190,en:trh-190:9191,en:trh-190:9192|en:<p>T</p>|3|63656E696B2D313930,63656EC3AD6B2D313930,6A696E792D313930|slugs.per_language,redirect.collisions,slugs.per_language,tags.split'
[ "$(s319)" = "$S319_EXPECTED" ] && echo "  ok     3.19 (0105, 0106): an upgraded site gets per-language slugs, the plain lookup keys, one tag per language with the news re-linked, normalised redirects and the events" || { echo "  CHYBA  0106: $(s319)"; ERRORS=$((ERRORS+1)); }
DIFF=$(diff <(structure "$OLD") <(structure "$NEW"))
[ -z "$DIFF" ] && echo "  ok     3.19: … and its structure equals a fresh install again" || { echo "  CHYBA  3.19: upgraded structure differs:"; echo "$DIFF" | head -10; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'; UPDATE ka_nastaveni SET hodnota = '104' WHERE promenna = 'db_version'" && migrate > /dev/null
[ "$(s319)" = "$S319_EXPECTED" ] && echo "  ok     3.19 (0105, 0106): run again, nothing changes (no new tag, no new event)" || { echo "  CHYBA  0106 run again: $(s319)"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "SET FOREIGN_KEY_CHECKS = 0; DELETE FROM ka_novinky_stitky WHERE idc BETWEEN 9190 AND 9192; DELETE FROM ka_stitky WHERE seo_link IN ('trh-190', 'stary-191'); DELETE FROM ka_novinky WHERE idc BETWEEN 9190 AND 9192;
  DELETE FROM ka_presmerovani WHERE idp BETWEEN 9190 AND 9192; SET FOREIGN_KEY_CHECKS = 1;
  UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'slugs_per_language'; ALTER TABLE ka_kategorie DROP INDEX uq_topic_jazyk_seo;
  INSERT INTO ka_kategorie (idt, nazev, seo_link, popis, jazyk) VALUES (9192, 'A', 'dvojice-192', '', ''), (9193, 'B', 'dvojice-192', '', '');
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
expect_sql "3.19 (0106): a site that cannot switch stays as it is (off) and stores why" \
  "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '|', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.reason')), ':', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.slugs[0]')), '|', (SELECT type FROM ka_events ORDER BY id DESC LIMIT 1)) FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'" \
  "0|shared:/novinky/kategorie/dvojice-192|slugs.per_language_failed"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_kategorie WHERE idt IN (9190, 9191, 9192, 9193); UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
expect_sql "3.19 (0106): once the cause is gone it switches on – the per-language key is added back, the reason cleared" \
  "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '|', (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND INDEX_NAME = 'uq_topic_jazyk_seo' AND SEQ_IN_INDEX = 1), '|', hodnota) FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'" "1|1|"

# 3.19 (N319-1): a site that was on already (3.9–3.18) with a global key left (a partial restore) and the per-language key
# of categories missing while two categories of one language share a slug – the migration fails there, and the site stays
# on: the setting '1', the failure recorded, no global key put back; once fixed, the next run completes and clears it
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = '1' WHERE promenna = 'slugs_per_language'; ALTER TABLE ka_stitky ADD UNIQUE KEY uq_stitky_seo (seo_link); ALTER TABLE ka_kategorie DROP INDEX uq_topic_jazyk_seo;
  INSERT INTO ka_kategorie (idt, nazev, seo_link, popis, jazyk) VALUES (9194, 'A', 'dvojice-194', '', ''), (9195, 'B', 'dvojice-194', '', '');
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
expect_sql "3.19 (N319-1): an on site where 0106 fails stays on – the failure recorded, the event written, the keys as they were" \
  "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '|', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.reason')), ':', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.slugs[0]')), '|', (SELECT type FROM ka_events ORDER BY id DESC LIMIT 1), '|', (SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND INDEX_NAME IN ('uq_stitky_seo', 'uq_topic_seo', 'uq_topic_jazyk_seo') AND SEQ_IN_INDEX = 1)) FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'" \
  "1|shared:/novinky/kategorie/dvojice-194|slugs.per_language_failed|uq_stitky_seo"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_kategorie WHERE idt IN (9194, 9195); UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
expect_sql "3.19 (N319-1): … once fixed the next run adds the key, drops the global one and clears the failure" \
  "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '|', (SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND INDEX_NAME IN ('uq_stitky_seo', 'uq_topic_seo', 'uq_topic_jazyk_seo') AND SEQ_IN_INDEX = 1), '|', hodnota) FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'" "1|uq_topic_jazyk_seo|"
# 3.19 (N319-4): the tag split relinks without INSERT IGNORE – a news item already linked to the copy keeps one link, the
# links of the other language move, nothing else is touched
"${MYSQL[@]}" "$OLD" -e "SET FOREIGN_KEY_CHECKS = 0;
  INSERT INTO ka_kategorie (idt, nazev, seo_link, popis, jazyk) VALUES (9196, 'Z', 'z-196', '', ''), (9197, 'N', 'n-197', '', 'en');
  INSERT INTO ka_novinky (idc, seo_link, titulek, uvod, text, tema, datum, jazyk) VALUES (9196, 'a-196', 'A', '', '', 9196, '$(site_time)', ''), (9197, 'b-197', 'B', '', '', 9197, '$(site_time)', 'en'), (9198, 'c-198', 'C', '', '', 9197, '$(site_time)', 'en');
  INSERT INTO ka_stitky (ids, nazev, seo_link, jazyk) VALUES (9196, 'Spolecny', 'spolecny-196', ''), (9197, 'Spolecny', 'spolecny-196', 'en');
  INSERT INTO ka_novinky_stitky (idc, ids) VALUES (9196, 9196), (9197, 9196), (9198, 9196), (9197, 9197);
  SET FOREIGN_KEY_CHECKS = 1;
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
expect_sql "3.19 (N319-4): the split relinks to the existing English tag – one link per news item, the Czech one stays" \
  "SELECT GROUP_CONCAT(CONCAT(ns.idc, ':', t.jazyk) ORDER BY ns.idc, t.jazyk) FROM ka_novinky_stitky ns JOIN ka_stitky t ON t.ids = ns.ids WHERE ns.idc BETWEEN 9196 AND 9198" "9196:,9197:en,9198:en"
"${MYSQL[@]}" "$OLD" -e "SET FOREIGN_KEY_CHECKS = 0; DELETE FROM ka_novinky_stitky WHERE idc BETWEEN 9196 AND 9198; DELETE FROM ka_stitky WHERE ids IN (9196, 9197); DELETE FROM ka_novinky WHERE idc BETWEEN 9196 AND 9198; DELETE FROM ka_kategorie WHERE idt IN (9196, 9197); SET FOREIGN_KEY_CHECKS = 1"

[ "$ERRORS" = 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
