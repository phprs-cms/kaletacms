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

expect_sql() { [ "$("${MYSQL[@]}" "$OLD" -N -e "$2")" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1"; ERRORS=$((ERRORS+1)); }; }
expect_sql "settings keys of 1.4.0 renamed (0026), per-language ones too" "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name_en'))" "owner@example.com|Northfield"
expect_sql "no settings row left under an old key" "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('verze_db', 'email_webu', 'nazev_webu_en', 'firma_email')" "0"
expect_sql "a site from before 2.2 keeps its extensions (0035) – the Claude connection does not switch itself on" "SELECT hodnota <> '' AND hodnota NOT LIKE '%claude%' FROM ka_nastaveni WHERE promenna = 'extensions'" "1"
expect_sql "3.3.4 (0076): an OAuth client registered before the upgrade counts as approved – the daily clean-up never removes it" "SELECT approved = vytvoren FROM ka_oauth_klienti WHERE client_id = REPEAT('a', 32)" "1"

# 3.9 (0083): the language columns hold a BCP 47 tag – VARCHAR(35) in ASCII on an upgraded site as on a fresh one (the
# structure check above compares the type, not the character set), and a site's language values stay as they were
langcols() { "${MYSQL[@]}" -N -e "SELECT GROUP_CONCAT(CONCAT(TABLE_NAME, '.', COLUMN_NAME, ':', COLUMN_TYPE, ':', CHARACTER_SET_NAME) ORDER BY TABLE_NAME, COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' AND COLUMN_NAME IN ('jazyk', 'language')"; }
[ "$(langcols "$OLD")" = "$(langcols "$NEW")" ] && [ "$(langcols "$OLD" | grep -o ':varchar(35):ascii' | wc -l | tr -d ' ')" = 14 ] && echo "  ok     3.9 (0083): the 14 language columns are VARCHAR(35) ASCII, as on a fresh install" || { echo "  CHYBA  0083 language columns: $(langcols "$OLD")"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stranky (ids, seo_link, titulek, text, jazyk) VALUES (9083, 'kontakt-83', 'Kontakt', '', ''), (9084, 'contact-83', 'Contact', '', 'en')"
expect_sql "3.9 (0083): pages, news and categories have the per-language slug key next to the global one" \
  "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$OLD' AND INDEX_NAME IN ('uq_stranky_seo', 'uq_stranky_jazyk_seo', 'uq_clanky_seo', 'uq_clanky_jazyk_seo', 'uq_topic_seo', 'uq_topic_jazyk_seo') AND SEQ_IN_INDEX = 1" "6"
"${MYSQL[@]}" "$OLD" -e "INSERT INTO ka_stranky (ids, seo_link, titulek, text, jazyk) VALUES (9085, 'kontakt-83', 'Contact', '', 'en')" 2>/dev/null && { echo "  CHYBA  0083: a duplicate slug passed the global key"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.9 (0083): … a duplicate slug in another language is refused by the database"
expect_sql "3.9 (0083): language values read back unchanged" "SELECT GROUP_CONCAT(CONCAT(ids, ':', jazyk, ':', LENGTH(jazyk)) ORDER BY ids) FROM ka_stranky WHERE ids IN (9083, 9084)" "9083::0,9084:en:2"
"${MYSQL[@]}" "$OLD" -e "DELETE FROM ka_stranky WHERE ids IN (9083, 9084)"

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

[ "$ERRORS" = 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
