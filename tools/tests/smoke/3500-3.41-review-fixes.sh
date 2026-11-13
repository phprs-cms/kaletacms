echo "== 3.41 review: sign-in before migration 0130, redirecting site address, epochs, team totals, update source, fleet pairing, reset links"
r341_csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//' || true; }
r341_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1" -- "${@:2}"); }

# M1: files of 3.41 on a database without migration 0130 (a manual upload, a failed migration) – an administrator whose
# session expired can still sign in, and that first administrator request applies the migration
sq "ALTER TABLE ka_uzivatele DROP COLUMN sign_in_epoch; UPDATE ka_nastaveni SET hodnota = '129' WHERE promenna = 'db_version'" > /dev/null
R341_JAR="$WORK/jar-r341"; curl -s -c "$R341_JAR" -o "$WORK/response" "$B/admin.php"
R341_SIGNIN=$(curl -s -b "$R341_JAR" -c "$R341_JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(r341_csrf "$WORK/response")" -d user=admin --data-urlencode "password=$PASSWORD")
curl -s -b "$R341_JAR" -c "$R341_JAR" -o "$WORK/response" "$B/admin.php"
expect "3.41 review M1: without sign_in_epoch the sign-in succeeds and the next administrator request migrates the column back" \
  "$R341_SIGNIN|$(grep -c 'name="password"' "$WORK/response")|$(sq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_uzivatele' AND COLUMN_NAME = 'sign_in_epoch'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" \
  "302|0|1|130"
if [ "$(sq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_uzivatele' AND COLUMN_NAME = 'sign_in_epoch'")" = "0" ]; then
  sq "ALTER TABLE ka_uzivatele ADD COLUMN sign_in_epoch INT UNSIGNED NOT NULL DEFAULT 0; UPDATE ka_nastaveni SET hodnota = '130' WHERE promenna = 'db_version'" > /dev/null
fi

# L1: a check that met a redirect to another host is a warning "couldn't check", never "the web server refuses them"
sq "UPDATE ka_nastaveni SET hodnota = '{\"at\":$(date +%s),\"open\":[],\"error\":\"\",\"redirect\":\"https://elsewhere.example/\"}' WHERE promenna = 'server_rules'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.41 review L1: System status says the check couldn't run because the address redirects, not that the folders are refused" \
  "$(grep -c 'Nepodařilo se ověřit, zda je webový server návštěvníkům odmítá – adresa webu přesměrovává (na https://elsewhere.example/)' "$WORK/response")|$(grep -c 'webový server je návštěvníkům odmítá (zkontrolováno' "$WORK/response")" "1|0"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'server_rules'" > /dev/null
