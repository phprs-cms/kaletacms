echo "== instalace"
# 3.13: the installer in Spanish – chosen by ?language=es and found in the browser's language, with no English left
curl -s -o "$WORK/response" "$B/install.php?language=es"
grep -q '<html lang="es">' "$WORK/response" && grep -q 'Base de datos' "$WORK/response" && grep -q '>Español</a>' "$WORK/response" \
  && echo "  ok     3.13: the installer in Spanish" || { echo "  CHYBA  3.13: the installer with ?language=es is not Spanish"; ERRORS=$((ERRORS+1)); }
expect "3.13: the Spanish installer shows no English text" "$(leftovers install-es "$WORK/response")" ""
curl -s -H 'Accept-Language: es-MX,es;q=0.9,en;q=0.8' -o "$WORK/response" "$B/install.php"
grep -q '<html lang="es">' "$WORK/response" && grep -q 'value="Europe/Madrid" selected' "$WORK/response" \
  && echo "  ok     3.13: the installer follows a Spanish browser (and offers Madrid time)" || { echo "  CHYBA  3.13: a Spanish browser does not get the Spanish installer"; ERRORS=$((ERRORS+1)); }
PASSWORD="Test-$(date +%s)-heslo"
# 3.2: Bookings and Whistleblowing are offered, unticked; the default features are ticked
curl -s -o "$WORK/response" "$B/install.php"
grep -q 'name="rozsireni\[\]" value="bookings">' "$WORK/response" && grep -q 'name="rozsireni\[\]" value="whistleblowing">' "$WORK/response" && grep -q 'name="rozsireni\[\]" value="statistika" checked>' "$WORK/response" \
  && echo "  ok     3.2: the installer offers Bookings and Whistleblowing unticked" || { echo "  CHYBA  installer: the feature checkboxes"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Testovací firma" -d "web=${WEB:-firemni}" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
grep -q "Hotovo, web běží" "$WORK/response" || { echo "  CHYBA  instalace selhala"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     instalace"
# the site under test never updates itself from the public channel: a security release there (auto_updates is on by default)
# would overwrite the copy under test in the middle of the run – the updater tests below set their own fake channel
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url', 'http://127.0.0.1:1/aktualizace.json') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_attempt')"
grep -qE '/tasks\?token=[a-f0-9]{32}' "$WORK/response" && echo "  ok     2.8: the installer shows the cron line with its own address (3.7: the English /tasks)" || { echo "  CHYBA  instalátor neukázal řádek pro cron"; ERRORS=$((ERRORS+1)); }
[ ! -f "$WORK/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; ERRORS=$((ERRORS+1)); }
# 3.19.1 (N83): config.php holds the database password and the encryption key – readable only by the site (PHP owns the folder here)
config_mode() { php -r 'clearstatcache(); printf("%o", fileperms($argv[1]) & 0777);' "$WORK/web/config.php"; }
expect "3.19.1 (N83): the installer wrote config.php with mode 600" "$(config_mode)" "600"
# T1 (Q1): a PHP deprecation on a live site is logged once a day and the page goes on – a host's PHP upgrade is no HTTP 500.
# The test copy then defines KALETA_STRICT_DEPRECATIONS, so any deprecation from here on fails the run as before.
# (a script file, not php -r: PHP never calls the exception handler for code given with -r)
printf '%s\n' '<?php require __DIR__ . "/system/bootstrap.php"; $app = Kaleta\Core\App::boot(); trigger_error("T1 smoke deprecation", E_USER_DEPRECATED); trigger_error("T1 smoke deprecation", E_USER_DEPRECATED); echo "survived";' > "$WORK/web/t1-deprecation.php"
t1_deprecation() { php "$WORK/web/t1-deprecation.php" 2>&1 | tail -c 300; }
expect "T1 (Q1): a deprecation after App::boot() is logged, not thrown – once, as one line" \
  "$(t1_deprecation)|$(grep -c '\] PHP deprecation: T1 smoke deprecation in t1-deprecation.php:1' "$WORK/web/storage/log/chyby.log")" "survived|1"
# 3.36 (T3/T4): the test copy also defines KALETA_TEST_SEAMS – Core\TestSeams answers "off" on every site without it
php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/^<\?php/", "<?php define(\x27KALETA_STRICT_DEPRECATIONS\x27, true); define(\x27KALETA_TEST_SEAMS\x27, true);", (string) file_get_contents($f), 1));' "$WORK/web/config.php"
expect "T1 (Q1): with KALETA_STRICT_DEPRECATIONS (the test copies) a deprecation is thrown again" "$(t1_deprecation | grep -c survived)|$(grep -c '\] ErrorException: T1 smoke deprecation' "$WORK/web/storage/log/chyby.log")" "0|1"
rm -f "$WORK/web/t1-deprecation.php"
php -r '$f = $argv[1]; file_put_contents($f, implode("", array_filter(file($f) ?: [], fn ($r) => !str_contains($r, "T1 smoke deprecation"))));' "$WORK/web/storage/log/chyby.log"
# T1 (Q13): an uncaught exception on a request is one line with the method and path (no query), the version and a short
# trace without arguments; an e-mail address in the message and a token in the query never reach the log
printf '%s\n' '<?php require __DIR__ . "/system/bootstrap.php"; $app = Kaleta\Core\App::boot(); (function () { throw new RuntimeException("T1 probe for probe@example.cz"); })();' > "$WORK/web/t1-probe.php"
T1_TOKEN="t1$(date +%s)x$(date +%s)tok"
T1_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$B/t1-probe.php?token=$T1_TOKEN"); T1_ROW=$(grep 'T1 probe' "$WORK/web/storage/log/chyby.log" || true)
expect "T1 (Q13): the error log line has the request, the version and a trace, and no e-mail address or query" \
  "$T1_CODE|$(printf '%s' "$T1_ROW" | grep -c 'RuntimeException: T1 probe for \[e-mail\] in t1-probe.php:1 · GET /t1-probe.php · Kaleta [0-9.]* · trace: #0 t1-probe.php:1 ')|$(printf '%s' "$T1_ROW" | grep -c "probe@example\|$T1_TOKEN" || true)" "500|1|0"
rm -f "$WORK/web/t1-probe.php"
php -r '$f = $argv[1]; file_put_contents($f, implode("", array_filter(file($f) ?: [], fn ($r) => !str_contains($r, "T1 probe"))));' "$WORK/web/storage/log/chyby.log"

