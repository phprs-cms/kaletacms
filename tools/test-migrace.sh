#!/usr/bin/env bash
# Kaleta – database upgrade test: the schema of an older release plus the current migrations must give the same structure
# as a fresh install (tables, columns, types, indexes), migrations must be repeatable, and data migrations must do their job.
# Same env as tools/test.sh: DB_HOST DB_PORT DB_USER DB_PASS; DB_NAME is used as a prefix (…_old, …_new). FROM = release tag (v1.0.0).
set -uo pipefail
KOREN="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-kaleta_test_mig}"; FROM="${FROM:-v1.0.0}"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
OLD="${DB_NAME}_old"; NEW="${DB_NAME}_new"; CHYB=0
uklid() { "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$OLD\`; DROP DATABASE IF EXISTS \`$NEW\`" 2>/dev/null; }
trap uklid EXIT

echo "== database upgrade from $FROM"
VERZE_OLD=$(git -C "$KOREN" show "${FROM}:system/bootstrap.php" | sed -n 's/^const KALETA_VERZE_DB = \([0-9]*\);/\1/p')
[ -n "$VERZE_OLD" ] || { echo "  CHYBA  cannot read KALETA_VERZE_DB of $FROM"; exit 1; }
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$OLD\`; DROP DATABASE IF EXISTS \`$NEW\`; CREATE DATABASE \`$OLD\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci; CREATE DATABASE \`$NEW\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
git -C "$KOREN" show "${FROM}:system/sql/schema.sql" | "${MYSQL[@]}" "$OLD" || { echo "  CHYBA  old schema"; exit 1; }
"${MYSQL[@]}" "$NEW" < "$KOREN/system/sql/schema.sql" || { echo "  CHYBA  current schema"; exit 1; }
# a site as it was: its settings, including values that data migrations take over
"${MYSQL[@]}" "$OLD" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('verze_db', '$VERZE_OLD'), ('email_webu', 'owner@example.com')"

migruj() {
  php -r '
    require $argv[1] . "/system/bootstrap.php";
    $db = new Kaleta\Core\Db(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $argv[2], (int) $argv[3], $argv[6]), $argv[4], $argv[5]);
    try { echo implode(",", Kaleta\Core\Migrace::proved($db, new Kaleta\Core\Settings($db))), "\n"; }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
  ' "$KOREN" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$OLD"
}
if VYSTUP=$(migruj 2>&1); then echo "  ok     migrations ran: ${VYSTUP:-none}"; else echo "  CHYBA  migration failed: $VYSTUP"; CHYB=$((CHYB+1)); fi
"${MYSQL[@]}" "$OLD" -e "UPDATE ka_nastaveni SET hodnota = '$VERZE_OLD' WHERE promenna = 'verze_db'"
if VYSTUP=$(migruj 2>&1); then echo "  ok     migrations are repeatable"; else echo "  CHYBA  second run failed: $VYSTUP"; CHYB=$((CHYB+1)); fi

struktura() {
  "${MYSQL[@]}" -N -e "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, 'NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' ORDER BY TABLE_NAME, COLUMN_NAME;
    SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$1' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME"
}
ROZDIL=$(diff <(struktura "$OLD") <(struktura "$NEW"))
[ -z "$ROZDIL" ] && echo "  ok     upgraded database = fresh install (columns and indexes)" || { echo "  CHYBA  upgraded database differs from a fresh install:"; echo "$ROZDIL" | head -30; CHYB=$((CHYB+1)); }

POSLEDNI=$(ls "$KOREN"/system/sql/migrace/*.sql | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
[ "$("${MYSQL[@]}" "$OLD" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'verze_db'")" = "$POSLEDNI" ] && echo "  ok     verze_db = $POSLEDNI" || { echo "  CHYBA  verze_db after upgrade"; CHYB=$((CHYB+1)); }
[ "$("${MYSQL[@]}" "$OLD" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'firma_email'")" = "owner@example.com" ] && echo "  ok     existing site keeps its public contact email" || { echo "  CHYBA  firma_email not taken over"; CHYB=$((CHYB+1)); }

[ "$CHYB" = 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $CHYB"; exit 1; }
