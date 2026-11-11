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

echo "== migration numbers (T1, Q12)"
php "$ROOT/tools/check-migrations.php" || ERRORS=$((ERRORS+1))

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
[ "$(langcols "$OLD")" = "$(langcols "$NEW")" ] && [ "$(langcols "$OLD" | grep -o ':varchar(35):ascii' | wc -l | tr -d ' ')" = 17 ] && echo "  ok     3.9 (0083), 3.14 (0095), 3.28 (0114) and 3.34 (0119): the 17 language columns are VARCHAR(35) ASCII, as on a fresh install" || { echo "  CHYBA  0083/0095 language columns: $(langcols "$OLD")"; ERRORS=$((ERRORS+1)); }
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
# 3.14 (0094) adds the 21st: ka_presmerovani.source_norm, the normalised old address of a redirect; 3.28 (0114) the 22nd:
# ka_contacts.email_norm, the normalised address a contact is keyed by
[ "$(tokencols "$OLD")" = "$(tokencols "$NEW")" ] && [ "$(tokencols "$OLD" | tr ',' '\n' | grep -c .)" = 23 ] && tokencols "$OLD" | grep -q 'ka_presmerovani.source_norm:varchar(255):YES:NULL' && tokencols "$OLD" | grep -q 'ka_contacts.email_norm:varchar(190):YES:NULL' && tokencols "$OLD" | grep -q 'ka_applications.email_norm:varchar(190):YES:NULL' && echo "  ok     3.12 (0092), 3.14 (0094), 3.28 (0114), 3.34 (0119): the 20 token and hash columns, the redirect source_norm and the contacts' and applications' email_norm are utf8mb4_bin, as on a fresh install" || { echo "  CHYBA  0092 token columns: $(tokencols "$OLD")"; ERRORS=$((ERRORS+1)); }
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

# 3.34 (0120): job applications move out of enquiries – a site as 3.33 left it: a jobs collection, an application (with a
# note, a task, a deal made from it and a customer record only it made), an application whose customer has another enquiry,
# and an ordinary enquiry; then the data migration runs again by name and once more – nothing moves twice
T340_AT=$(site_time) # one moment for created_at and updated_at: a record whose updated_at is later counts as edited and stays
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; INSERT INTO ka_kolekce (idk, nazev, seo_link, pole, preset) VALUES (9340, 'Jobs', 'jobs-340', '[]', 'jobs');
  INSERT INTO ka_kolekce_polozky (idp, idk, nazev, seo_link, data, datum) VALUES (9340, 9340, 'Welder', 'welder', '{}', '$(site_time)');
  INSERT INTO ka_contacts (id, email, email_norm, name, source, created_at, updated_at) VALUES (9340, 'lone@example.com', 'lone@example.com', 'Lone', 'enquiry', '$T340_AT', '$T340_AT'),
    (9341, 'shared@example.com', 'shared@example.com', 'Shared', 'enquiry', '$T340_AT', '$T340_AT');
  INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav, prirazeno, contact_id) VALUES
    (9340, '$(site_time '-3 days')', 'Job application', 'kolekce:9340', '/jobs-340/welder', 'Jobs – Welder', 'lone@example.com', '[[\"Full name\",\"Lone One\"]]', 1, NULL, 9340),
    (9341, '$(site_time '-2 days')', 'Job application', 'kolekce:9340', '/jobs-340/welder', 'Jobs – Welder', 'shared@example.com', '[]', 0, NULL, 9341),
    (9342, '$(site_time '-2 days')', 'Kontakt', 'stranka:1', '/kontakt', '', 'shared@example.com', '[]', 0, NULL, 9341);
  INSERT INTO ka_comments (target, kind, author_name, text, via, created_at) VALUES ('enquiry:9340', 'note', 'Hana', 'note 340', 'admin', '$(site_time)');
  INSERT INTO ka_tasks (title, target, created_at, updated_at) VALUES ('task 340', 'enquiry:9340', '$(site_time)', '$(site_time)');
  INSERT INTO ka_deals (id, title, stage, source_enquiry_id, created_at, updated_at) VALUES (9340, 'Lone One – job', 'new', 9340, '$(site_time)', '$(site_time)');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', 'novinky,poptavky,claude'), ('job_applications_months', '4');
  DELETE FROM ka_nastaveni WHERE promenna = 'applications_months';
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
moved340() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT GROUP_CONCAT(CONCAT(source_enquiry_id, ':', status, ':', job_title) ORDER BY source_enquiry_id) FROM ka_applications),
  (SELECT GROUP_CONCAT(idp ORDER BY idp) FROM ka_poptavky WHERE idp BETWEEN 9340 AND 9342), (SELECT COUNT(*) FROM ka_comments c JOIN ka_applications a ON c.target = CONCAT('application:', a.id) WHERE a.source_enquiry_id = 9340),
  (SELECT COUNT(*) FROM ka_tasks t JOIN ka_applications a ON t.target = CONCAT('application:', a.id) WHERE a.source_enquiry_id = 9340), (SELECT IFNULL(source_enquiry_id, 'none') FROM ka_deals WHERE id = 9340),
  (SELECT GROUP_CONCAT(id ORDER BY id) FROM ka_contacts WHERE id IN (9340, 9341)), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_months'))"; }
M340_EXPECTED='9340:reviewed:Welder,9341:new:Welder|9342|1|1|none|9341|novinky,poptavky,claude,applications|4'
[ "$(moved340)" = "$M340_EXPECTED" ] && echo "  ok     3.34 (0120): the job enquiries became applications with their note and task, the deal lost the link, the lone customer went, the shared one stayed; the feature is on and the retention taken over" || { echo "  CHYBA  0120: $(moved340)"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
[ "$(moved340)" = "$M340_EXPECTED" ] && echo "  ok     3.34 (0120): run again, nothing moves twice" || { echo "  CHYBA  0120 run again: $(moved340)"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_comments WHERE target LIKE 'application:%'; DELETE FROM ka_tasks WHERE target LIKE 'application:%'; DELETE FROM ka_applications; DELETE FROM ka_poptavky WHERE idp = 9342;
  DELETE FROM ka_contacts WHERE id IN (9340, 9341); DELETE FROM ka_deals WHERE id = 9340; DELETE FROM ka_kolekce_polozky WHERE idp = 9340; DELETE FROM ka_kolekce WHERE idk = 9340"

# 3.34 review H1: the update never shortens the retention – applications_months starts at job_applications_months when it was
# set, otherwise at enquiries_months (24 stays 24, 0 = keep stays keep); nothing moved is purged until the administrator confirms
retention340() { # $1 job_applications_months ('' = never stored), $2 enquiries_months ('' = never stored)
  "${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; DELETE FROM ka_nastaveni WHERE promenna IN ('applications_months', 'job_applications_months', 'enquiries_months');
    REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_retention_confirmed', '999999999');
    $([ -n "$1" ] && echo "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('job_applications_months', '$1');") $([ -n "$2" ] && echo "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '$2');")
    INSERT INTO ka_kolekce (idk, nazev, seo_link, pole, preset) VALUES (9343, 'Jobs', 'jobs-343', '[]', 'jobs');
    INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav) VALUES (9343, '$(site_time '-3 days')', 'Job application', 'kolekce:9343', '/jobs-343/x', '', 'r@example.com', '[]', 0);
    UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations'" && migrate > /dev/null
  "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_months'), (SELECT CAST(hodnota AS UNSIGNED) < (SELECT MIN(id) FROM ka_applications WHERE source_enquiry_id = 9343) FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'), (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = 9343));
    DELETE FROM ka_applications; DELETE FROM ka_poptavky WHERE idp = 9343; DELETE FROM ka_kolekce WHERE idk = 9343"
}
expect_sql_value() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: $2 (expected $3)"; ERRORS=$((ERRORS+1)); }; }
expect_sql_value "3.34 review H1 (0120): enquiries kept 24 months – the applications keep 24, the moved one waits for the confirmation (N8: the confirmed id lies below it)" "$(retention340 '' 24)" "24|1|1"
expect_sql_value "3.34 review H1 (0120): enquiries kept for good (0) – the applications are kept too" "$(retention340 '' 0)" "0|1|1"
expect_sql_value "3.34 review H1 (0120): enquiries_months never stored – its default 24, not the 6 of applications" "$(retention340 0 '')" "24|1|1"
expect_sql_value "3.34 review H1 (0120): job_applications_months set – that one" "$(retention340 5 24)" "5|1|1"

# 3.34 review M3: the move is resumable – a run that stops after one row (as a web request out of time) leaves its name in
# data_migrations_unfinished and the rest hidden from Enquiries; the next request finishes it. A row that fails stays an
# enquiry (counted, by number only), the customer clean-up comes from the data: the record only applications made goes, a
# customer with a responsible person stays
migrate_one_row() {
  php -r '
    define("KALETA_MIGRATION_ROWS", 1);
    require $argv[1] . "/system/bootstrap.php";
    $db = new Kaleta\Core\Db(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $argv[2], (int) $argv[3], $argv[6]), $argv[4], $argv[5]);
    Kaleta\Core\Migration::apply($db, new Kaleta\Core\Settings($db));
  ' "$ROOT" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$OLD"
}
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; DELETE FROM ka_events WHERE type LIKE 'applications.move%';
  INSERT INTO ka_kolekce (idk, nazev, seo_link, pole, preset) VALUES (9344, 'Jobs', 'jobs-344', '[]', 'jobs');
  INSERT INTO ka_contacts (id, email, email_norm, name, source, owner_id, created_at, updated_at) VALUES (9344, 'only@example.com', 'only@example.com', 'Only', 'enquiry', NULL, '$T340_AT', '$T340_AT'),
    (9345, 'owned@example.com', 'owned@example.com', 'Owned', 'enquiry', 1, '$T340_AT', '$T340_AT');
  INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav, contact_id) VALUES
    (9344, '$(site_time '-3 days')', 'Job application', 'kolekce:9344', '/jobs-344/x', '', 'only@example.com', '[]', 0, 9344),
    (9345, '$(site_time '-3 days')', 'Job application', 'kolekce:9344', '/jobs-344/x', '', 'owned@example.com', '[]', 0, 9345),
    (9346, '$(site_time '-3 days')', 'FAIL', 'kolekce:9344', '/jobs-344/x', '', 'f@example.com', '[]', 0, NULL),
    (9347, '$(site_time '-3 days')', 'Job application', 'kolekce:9344', '/jobs-344/x', '', 'late@example.com', '[]', 0, NULL);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('data_migrations_unfinished', ''), ('applications_move_until', '');
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations'"
"${MYSQL[@]}" "$OLD" <<'SQL'
DELIMITER //
CREATE TRIGGER ka_t344 BEFORE INSERT ON ka_applications FOR EACH ROW BEGIN IF NEW.form = 'FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'f@example.com'; END IF; END//
DELIMITER ;
SQL
migrate_one_row > /dev/null
resume344() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id BETWEEN 9344 AND 9347), (SELECT GROUP_CONCAT(idp ORDER BY idp) FROM ka_poptavky WHERE idp BETWEEN 9344 AND 9347),
  (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_move_until'), (SELECT IFNULL(GROUP_CONCAT(id ORDER BY id), '-') FROM ka_contacts WHERE id IN (9344, 9345)),
  (SELECT COUNT(*) FROM ka_events WHERE type = 'applications.move_failed' AND message LIKE '%#9346%' AND message NOT LIKE '%example.com%'), (SELECT COUNT(*) FROM ka_applications WHERE source_contact_id IS NOT NULL))"; }
expect_sql_value "3.34 review M3 (0120): out of time after one row – one moved, the name in data_migrations_unfinished, the rest still to move" "$(resume344)" "1|9345,9346,9347|0120-applications-move|9347|9345|0|0"
M344=$(php -r 'require $argv[1] . "/system/bootstrap.php"; $db = new Kaleta\Core\Db(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $argv[2], (int) $argv[3], $argv[6]), $argv[4], $argv[5]); $s = new Kaleta\Core\Settings($db);
  echo Kaleta\Core\Migration::pending($s) ? "pending" : "idle", "|", Kaleta\Core\Applications::movingCondition($db, $s) !== null ? "hidden" : "shown", "|", Kaleta\Core\Applications::movingLeft($db, $s), "\n";' "$ROOT" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$OLD" 2>&1)
expect_sql_value "3.34 review M3 (0120): the next request runs it again; meanwhile the enquiries still to move are hidden and counted" "$M344" "pending|hidden|3"
migrate > /dev/null
expect_sql_value "3.34 review M3 (0120): the next request finishes – the failed row stays an enquiry (its number in the event, no address), nothing left to hide, the customer with a responsible person stays" "$(resume344)" "3|9346|||9345|1|0"
"${MYSQL[@]}" "$OLD" -e "DROP TRIGGER ka_t344; DELETE FROM ka_comments WHERE target LIKE 'application:%'; DELETE FROM ka_applications; DELETE FROM ka_poptavky WHERE idp BETWEEN 9344 AND 9347; DELETE FROM ka_contacts WHERE id IN (9344, 9345); DELETE FROM ka_kolekce WHERE idk = 9344"

# 3.34 second review N2: the customer clean-up of 0120 goes in batches within the run's time – more than 1000 lone records all go
# in one run, and a run out of time leaves the migration unfinished while an application still names a record to look at
migrate_with() { # $1 PHP defines run before the bootstrap
  php -r "$1"'
    require $argv[1] . "/system/bootstrap.php";
    $db = new Kaleta\Core\Db(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $argv[2], (int) $argv[3], $argv[6]), $argv[4], $argv[5]);
    Kaleta\Core\Migration::apply($db, new Kaleta\Core\Settings($db));
  ' "$ROOT" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$OLD"
}
N2_AT=$(site_time '-2 days')
N2_CONTACTS=$(for i in $(seq 1 1003); do printf "%s(%d, 'n2-%d@example.com', 'n2-%d@example.com', 'N2', 'enquiry', '%s', '%s')" "$([ "$i" -gt 1 ] && echo ,)" $((934800 + i)) "$i" "$i" "$T340_AT" "$T340_AT"; done)
N2_ENQUIRIES=$(for i in $(seq 1 1003); do printf "%s(%d, '%s', 'Job application', 'kolekce:9348', '/jobs-348/x', '', 'n2-%d@example.com', '[]', 0, %d)" "$([ "$i" -gt 1 ] && echo ,)" $((934800 + i)) "$N2_AT" "$i" $((934800 + i)); done)
"${MYSQL[@]}" "$OLD" <<SQL # through stdin: 1003 rows are longer than one argument may be on Linux
DELETE FROM ka_applications; INSERT INTO ka_kolekce (idk, nazev, seo_link, pole, preset) VALUES (9348, 'Jobs', 'jobs-348', '[]', 'jobs');
  INSERT INTO ka_contacts (id, email, email_norm, name, source, created_at, updated_at) VALUES $N2_CONTACTS;
  INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav, contact_id) VALUES $N2_ENQUIRIES;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('data_migrations_unfinished', ''), ('applications_move_until', '');
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations';
SQL
migrate_with 'define("KALETA_MIGRATION_CONTACTS", 400); define("KALETA_MIGRATION_SECONDS", 600);' > /dev/null # the batches are the point, not the time a loaded server takes
n2_state() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id BETWEEN 934801 AND 935803), (SELECT COUNT(*) FROM ka_contacts WHERE id BETWEEN 934801 AND 935803),
  (SELECT COUNT(*) FROM ka_applications WHERE source_contact_id IS NOT NULL), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'))"; }
expect_sql_value "3.34 second review N2 (0120): 1003 lone customer records in batches of 400 – all go in one run, nothing named any more, the migration is finished" "$(n2_state)" "1003|0|0|"
# a run whose time is up before the clean-up: unfinished while an application still names a record, finished by the next run
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_contacts (id, email, email_norm, name, source, created_at, updated_at) VALUES (935900, 'n2-late@example.com', 'n2-late@example.com', 'N2', 'enquiry', '$T340_AT', '$T340_AT');
  UPDATE ka_applications SET source_contact_id = 935900 WHERE source_enquiry_id = 934801;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('data_migrations_unfinished', '0120-applications-move'), ('applications_move_until', '935803')"
migrate_with 'define("KALETA_MIGRATION_SECONDS", 0);' > /dev/null
expect_sql_value "3.34 second review N2 (0120): out of time before the clean-up – still unfinished, the record still named" \
  "$("${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'), (SELECT COUNT(*) FROM ka_contacts WHERE id = 935900), (SELECT COUNT(*) FROM ka_applications WHERE source_contact_id = 935900))")" \
  "0120-applications-move|1|1"
migrate > /dev/null
expect_sql_value "3.34 second review N2 (0120): the next run finishes the clean-up and the migration" \
  "$("${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'), (SELECT COUNT(*) FROM ka_contacts WHERE id = 935900), (SELECT COUNT(*) FROM ka_applications WHERE source_contact_id IS NOT NULL))")" "|0|0"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; DELETE FROM ka_poptavky WHERE idp BETWEEN 934801 AND 935803; DELETE FROM ka_contacts WHERE id BETWEEN 934801 AND 935900; DELETE FROM ka_kolekce WHERE idk = 9348"

# 3.34 second review N5: run by an older release's update request (KALETA_MIGRATION_AS_OLD_CODE plays it), 0120 only writes the
# state – nothing moves, no customer record goes; nothing moves while an update checks itself (update_probe); the first run of
# the 3.34 code moves everything, the job enquiry that arrived in between too
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; INSERT INTO ka_kolekce (idk, nazev, seo_link, pole, preset) VALUES (9349, 'Jobs', 'jobs-349', '[]', 'jobs');
  INSERT INTO ka_contacts (id, email, email_norm, name, source, created_at, updated_at) VALUES (9349, 'n5@example.com', 'n5@example.com', 'N5', 'enquiry', '$T340_AT', '$T340_AT');
  INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav, contact_id) VALUES (9349, '$(site_time '-3 days')', 'Job application', 'kolekce:9349', '/jobs-349/x', '', 'n5@example.com', '[]', 0, 9349);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('data_migrations_unfinished', ''), ('applications_move_until', ''), ('applications_move_deferred', ''), ('extensions', 'novinky,poptavky');
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations'"
migrate_with 'define("KALETA_MIGRATION_AS_OLD_CODE", true);' > /dev/null
n5_state() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id IN (9349, 9350)), (SELECT IFNULL(GROUP_CONCAT(idp ORDER BY idp), '-') FROM ka_poptavky WHERE idp IN (9349, 9350)),
  (SELECT COUNT(*) FROM ka_contacts WHERE id = 9349), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_move_until'),
  (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_move_deferred'), (SELECT hodnota LIKE '%applications%' FROM ka_nastaveni WHERE promenna = 'extensions'))"; }
expect_sql_value "3.34 second review N5 (0120): under an older release's code – the feature on and what is to move written, nothing moved, the customer record kept, unfinished" "$(n5_state)" "0|9349|1|0120-applications-move|9349|1|1"
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav) VALUES (9350, '$(site_time '-1 hour')', 'Job application', 'kolekce:9349', '/jobs-349/x', '', 'n5-later@example.com', '[]', 0);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_probe', 'n5probe')"
migrate > /dev/null
expect_sql_value "3.34 second review N5 (0120): while the update checks itself nothing moves either" "$(n5_state)" "0|9349,9350|1|0120-applications-move|9350||1"
# 3.34 third review F3: a probe with its time (the Updater of 3.34) holds the move back for 10 minutes only – a check killed
# before it cleared the probe no longer blocks the move for ever
"${MYSQL[@]}" "$OLD" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_probe_at', UNIX_TIMESTAMP() - 60)"
migrate > /dev/null
expect_sql_value "3.34 third review F3 (0120): a probe set a minute ago still holds the move back" "$(n5_state)" "0|9349,9350|1|0120-applications-move|9350||1"
"${MYSQL[@]}" "$OLD" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_probe_at', UNIX_TIMESTAMP() - 700)"
migrate > /dev/null
expect_sql_value "3.34 second review N5 / third review F3 (0120): a probe older than 10 minutes is stale – the 3.34 code moves both, the one that arrived in between too, and the lone customer record goes" "$(n5_state)" "2|-|0||||1"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; DELETE FROM ka_kolekce WHERE idk = 9349; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_probe', ''), ('update_probe_at', '')"

# 3.34 third review F3: a probe without its time (an older release's updater wrote it, then its request was killed) counts from
# when 0120 first saw it – the move waits 10 minutes from then, not for ever
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; INSERT INTO ka_kolekce (idk, nazev, seo_link, pole, preset) VALUES (9351, 'Jobs', 'jobs-351', '[]', 'jobs');
  INSERT INTO ka_poptavky (idp, datum, formular, zdroj, stranka, tema, email, data, stav) VALUES (9351, '$(site_time '-2 days')', 'Job application', 'kolekce:9351', '/jobs-351/x', '', 'f3@example.com', '[]', 0);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('data_migrations_unfinished', ''), ('applications_move_until', ''), ('applications_move_deferred', ''), ('update_probe', 'f3probe'), ('update_probe_at', ''), ('applications_probe_seen', '');
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0120-applications-move,', ',')) WHERE promenna = 'data_migrations'"
f3_state() { "${MYSQL[@]}" "$OLD" -N -e "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = 9351), (SELECT COUNT(*) FROM ka_poptavky WHERE idp = 9351),
  (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'), (SELECT SUBSTRING_INDEX(hodnota, '|', 1) = SHA2('f3probe', 256) FROM ka_nastaveni WHERE promenna = 'applications_probe_seen'))"; }
migrate > /dev/null; migrate > /dev/null
expect_sql_value "3.34 third review F3 (0120): a probe without its time – nothing moves, the first sight of it is noted" "$(f3_state)" "0|1|0120-applications-move|1"
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = CONCAT(SUBSTRING_INDEX(hodnota, '|', 1), '|', UNIX_TIMESTAMP() - 700) WHERE promenna = 'applications_probe_seen'"
migrate > /dev/null
expect_sql_value "3.34 third review F3 (0120): ten minutes after it was first seen the probe is stale and the move goes on" "$(f3_state)" "1|0||1"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_applications; DELETE FROM ka_kolekce WHERE idk = 9351; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_probe', ''), ('applications_probe_seen', '')"

[ "$ERRORS" = 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
