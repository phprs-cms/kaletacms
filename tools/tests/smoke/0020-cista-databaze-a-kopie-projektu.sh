echo "== čistá databáze a kopie projektu"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web") # soubory smazané a ještě nezapsané do gitu se nekopírují
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
CAPTCHA_PORT=$((PORT + 9)) # a fake CAPTCHA provider (2.6): Core\Captcha asks it instead of hCaptcha, Google or Cloudflare
FAKE_PORT=$((PORT + 15)) # the fake of every outside service a connector talks to (2.13, tools/fake-services.php)
mkdir "$WORK/fake" # T1 (Q4): the fake services write their logs and flags here, not in the system temp folder
(cd "$ROOT/tools" && KALETA_FAKE_DIR="$WORK/fake" exec php -S "127.0.0.1:$FAKE_PORT" fake-services.php > /dev/null 2>&1) & FAKE_PID=$!
(cd "$WORK/web" && KALETA_CAPTCHA_VERIFY="http://127.0.0.1:$CAPTCHA_PORT/" KALETA_CONNECTORS_FAKE="http://127.0.0.1:$FAKE_PORT" KALETA_IMPORT_LOCAL=1 KALETA_FIREWALL_LOCAL=1 KALETA_LINKS_LOCAL=1 KALETA_FLEET_LOCAL=1 exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done

check() { # over <popis> <očekávaný kód> <adresa> [hledaný text]
  local code; code=$(curl -s -b "$JAR" -c "$JAR" -A "Mozilla/5.0 test" -o "$WORK/response" -w '%{http_code}' "$B$3")
  if [ "$code" != "$2" ] || grep -qE 'Fatal error|Warning:|Deprecated:|Notice:' "$WORK/response" || { [ -n "${4:-}" ] && ! grep -q "$4" "$WORK/response"; }; then
    echo "  CHYBA  $1 ($3): kód $code, čekal jsem $2${4:+, text „$4“}"; ERRORS=$((ERRORS+1))
  else echo "  ok     $1"; fi
}
# 3.2: the Statistics feature is the only switch of the built-in statistics (stats_feature 0|1); cached pages go with it
stats_feature() {
  "${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = $([ "$1" = 1 ] && echo "IF(FIND_IN_SET('statistika', hodnota), hodnota, CONCAT(hodnota, ',statistika'))" || echo "TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',statistika,', ','))") WHERE promenna = 'extensions'"
  rm -f "$WORK"/web/storage/cache/stranky/*.html
}

LAST_MIGRATION=$(ls "$ROOT"/system/sql/migrace/[0-9]*-*.sql "$ROOT"/system/sql/migrace/[0-9]*-*.php 2>/dev/null | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
grep -q "const KALETA_DB_VERSION = $LAST_MIGRATION;" "$ROOT/system/bootstrap.php" && echo "  ok     KALETA_DB_VERSION odpovídá poslední migraci ($LAST_MIGRATION)" || { echo "  CHYBA  KALETA_DB_VERSION v system/bootstrap.php neodpovídá poslední migraci ($LAST_MIGRATION)"; ERRORS=$((ERRORS+1)); }

