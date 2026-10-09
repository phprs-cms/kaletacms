#!/usr/bin/env bash
# Kaleta - smoke test: a clean install into a temporary copy and a pass through the main pages.
# Runs locally and in GitHub Actions. Takes the database from environment variables:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (empty) PORT (8099) WEB (firemni | remeslo | poradenstvi)
# The database DB_NAME is DROPPED during the test and created again.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
WORK="$(mktemp -d)"; JAR="$WORK/cookies.txt"; B="http://127.0.0.1:$PORT"; ERRORS=0
cleanup() { local status=$?; if [ "$status" -ne 0 ] && [ -s "$WORK/server.log" ]; then echo "== the last lines of the site's server log (exit $status; an empty reply = the PHP process died)"; tail -n 25 "$WORK/server.log"; fi
  [ -z "${RACE_PID:-}" ] || pkill -P "$RACE_PID" 2>/dev/null || true; [ -z "${RACE2_PID:-}" ] || pkill -P "$RACE2_PID" 2>/dev/null || true; for pid in "${RACE_PID:-}" "${RACE2_PID:-}" "${HOSTILE_PID:-}" "${SITEMAPS_PID:-}" "${SERVER_PID:-}" "${SERVER3_PID:-}" "${CHANNEL_PID:-}" "${SERVICE_PID:-}" "${SMTP_PID:-}" "${CAPTCHA_PID:-}" "${OLDSITE_PID:-}" "${FAKE_PID:-}"; do [ -z "$pid" ] || kill "$pid" 2>/dev/null || true; done; rm -rf "$WORK"; }
trap cleanup EXIT
# Clocks (INV-36): the site runs on its own time zone (Europe/Prague – bootstrap.php and the Czech installer), the database
# in UTC (the MySQL service on CI; this script's sessions are forced to UTC below, so a local run fails the same way).
# Times the tests seed or compare come from site_time, never from MySQL's NOW(): the site writes date() values in its zone.
# The shell's date and every php -r here run on the site's clock too (the CI runner's default is UTC).
SITE_TZ=Europe/Prague; export TZ="$SITE_TZ"
# a background server must be started with `command php` (or `exec php` in a subshell), not through this function: $! would
# then be a bash subshell, and killing it leaves the server listening on its port (bash 5 on CI)
php() { command php -d date.timezone="$SITE_TZ" "$@"; }
site_time() { php -r 'echo date($argv[2], strtotime($argv[1]));' -- "${1:-now}" "${2:-Y-m-d H:i:s}"; } # site_time ['-1 hour'|tomorrow…] [format]
site_date() { site_time "$1" Y-m-d; }

echo "== syntaxe PHP"
# Kaleta's own files only – not the git worktrees of parallel work under .claude/ nor add-ons in extensions/
find "$ROOT" -name '*.php' -not -path '*/.git/*' -not -path '*/dist/*' -not -path '*/.claude/*' -not -path "$ROOT/extensions/*" -print0 | xargs -0 -n1 php -l > /dev/null

echo "== čistá databáze a kopie projektu"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web") # soubory smazané a ještě nezapsané do gitu se nekopírují
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
CAPTCHA_PORT=$((PORT + 9)) # a fake CAPTCHA provider (2.6): Core\Captcha asks it instead of hCaptcha, Google or Cloudflare
FAKE_PORT=$((PORT + 15)) # the fake of every outside service a connector talks to (2.13, tools/fake-services.php)
(cd "$ROOT/tools" && exec php -S "127.0.0.1:$FAKE_PORT" fake-services.php > /dev/null 2>&1) & FAKE_PID=$!
rm -f "$(php -r 'echo sys_get_temp_dir();')"/kaleta-fake-"$FAKE_PORT"-*.log
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

echo "== jednotkové testy"
php "$ROOT/tools/unit-tests.php" || ERRORS=$((ERRORS+1))

csrf() { grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//'; }
# publish_look: the administrator publishes the draft look (design system, classes, menus – Core\Look) from Site appearance
publish_look() { curl -s -b "$JAR" -o "$WORK/look.html" "$B/admin.php?module=appearance"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=publish_look" -d "_csrf=$(grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$WORK/look.html" | head -1 | sed 's/.*value="//;s/"//')"; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
expect() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; ERRORS=$((ERRORS+1)); }; }

echo "== instalace"
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
grep -qE '/tasks\?token=[a-f0-9]{32}' "$WORK/response" && echo "  ok     2.8: the installer shows the cron line with its own address (3.7: the English /tasks)" || { echo "  CHYBA  instalátor neukázal řádek pro cron"; ERRORS=$((ERRORS+1)); }
[ ! -f "$WORK/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; ERRORS=$((ERRORS+1)); }

echo "== web"
check "úvodní stránka" 200 / "Testovací firma"
check "úvodní stránka má navigaci stránek a novinek" 200 / 'href="/o-nas"'
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/uvod"); expect "úvodní stránka má jen jednu adresu" "$code" "301 $B/"
check "stránka" 200 /sluzby "Služby"
check "úvodní stránka je ze sekcí builderu" 200 / 'class="stavba"'
check "služby mají otázky a odpovědi i pro vyhledávače" 200 /sluzby '"FAQPage"'
check "výpis novinek" 200 /novinky "Vítejte v Kaletě"
check "novinka" 200 /novinky/vitejte-v-kalete "Vítejte"
# 3.5 (UXP-06, UXP-16): a footer without links has no empty navigation landmark; the skip link's target takes focus
curl -s -o "$WORK/response" "$B/"; tr -d '\n\t' < "$WORK/response" > "$WORK/flat"
! grep -q '<ul></ul></nav>' "$WORK/flat" && ! grep -q 'aria-label="Odkazy v patičce"' "$WORK/response" && grep -q '<main id="obsah" class="stavba" tabindex="-1">' "$WORK/response" \
  && echo "  ok     3.5: no empty footer navigation, the main content is the skip link's focus target" || { echo "  CHYBA  3.5: footer navigation or main"; ERRORS=$((ERRORS+1)); }
check "kategorie" 200 /novinky/kategorie/aktuality
check "hledání najde novinku i stránku" 200 "/hledani?q=Kontakt" 'href="/kontakt"'
for u in /rss.xml /feed.json /sitemap.xml /robots.txt /llms.txt /novinky/vitejte-v-kalete.md; do check "$u" 200 "$u"; done
check "mapa webu obsahuje novinku" 200 /sitemap.xml "/novinky/vitejte-v-kalete"
check "llms.txt vyjmenuje stránky" 200 /llms.txt "## Stránky"
check "strukturovaná data novinky" 200 /novinky/vitejte-v-kalete '"BlogPosting"'
check "zásady z instalace jsou skryté, dokud je správce nedoplní" 404 /zasady-ochrany-osobnich-udaju
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'zasady-ochrany-osobnich-udaju'"
check "zásady ochrany osobních údajů po zveřejnění" 200 /zasady-ochrany-osobnich-udaju "Jaké údaje zpracováváme"
check "patička odkazuje na zásady" 200 /o-nas 'zasady-ochrany-osobnich-udaju'
check "neexistující stránka" 404 /tohle-neexistuje
check "system/ není přístupný" 403 /system/sql/schema.sql
check "config.php není přístupný" 403 /config.php
mkdir -p "$WORK/web/layout/vlastni" && echo '<?php echo "VLASTNI SABLONA";' > "$WORK/web/layout/vlastni/base.php"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni VALUES ('layout','vlastni')"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "themeless: the site uses the built-in frame, never a custom layout" 200 / "image/sablona.css"
grep -q "VLASTNI SABLONA" "$WORK/response" && { echo "  CHYBA  a custom layout was used"; ERRORS=$((ERRORS+1)); } || echo "  ok     a custom layout in layout/ is ignored"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "bez úvodní stránky je úvodem výpis novinek" 200 / "Vítejte v Kaletě"

echo "== administrace"
check "zapomenuté heslo – formulář" 200 "/admin.php?action=password" "Poslat odkaz"
check "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?action=password&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
check "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); expect "špatné heslo odmítnuto" "$code" 401
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$PASSWORD"); expect "POST bez CSRF odmítnut" "$code" 400
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$PASSWORD"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('extensions','novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
check "přehled" 200 /admin.php "Přehled"
# 3.5 (UXA-07, UXA-20): until a Claude connection has ever called the site, the dashboard leads with "Connect Claude" (the
# address with Copy, the HTTPS warning), then First steps, and Ask Claude below
dash_order() { php -r '$h = (string) file_get_contents($argv[1]); $p = array_map(fn (string $n) => strpos($h, $n), [$argv[2], $argv[3], $argv[4]]); echo in_array(false, $p, true) ? "missing" : ($p[0] < $p[1] && $p[1] < $p[2] ? "ok" : "order");' "$WORK/response" "$@"; }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.5: not connected – Connect Claude, First steps, then Ask Claude" "$(dash_order 'id="pripojit-claude"' 'class="pruvodce"' 'id="ask-claude-nadpis"')" "ok"
grep -q 'data-kopirovat="#mcp-adresa-prehled"' "$WORK/response" && grep -q "<code id=\"mcp-adresa-prehled\">$B/mcp</code>" "$WORK/response" && grep -q 'Web neběží na HTTPS' "$WORK/response" \
  && echo "  ok     3.5: the card has the MCP address with Copy and warns that the site is not on HTTPS" || { echo "  CHYBA  3.5: connect card"; ERRORS=$((ERRORS+1)); }
check "3.5: Claude settings have the address with Copy and the connection state" 200 "/admin.php?module=claude_settings" 'data-kopirovat="#mcp-adresa-nastaveni"'
grep -q 'Zatím nepřipojeno' "$WORK/response" && echo "  ok     3.5: Claude settings say Claude is not connected yet" || { echo "  CHYBA  3.5: Claude settings state"; ERRORS=$((ERRORS+1)); }
check "3.5: My account has the address with Copy" 200 "/admin.php?action=account" 'data-kopirovat="#mcp-adresa-ucet"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=requests&action=save" -d "_csrf=$TOKEN" -d quick=1 -d from=dashboard -d "text=Zkouška před připojením."
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
grep -q 'Claude si ho vezme, jakmile bude k webu připojený' "$WORK/response" && ! grep -q 'Odesláno Claudovi' "$WORK/response" \
  && echo "  ok     3.5: a request saved before Claude is connected says it waits, not that it was sent" || { echo "  CHYBA  3.5: request saved while not connected"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_requests WHERE title = 'Zkouška před připojením.'"
# a personal token nobody used is not a connection; its first use is, and stays remembered after the token is revoked
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, druh, otisk, vytvoren) SELECT idu, 'unused 3.5', 'token', SHA2('unused-3.5', 256), NOW() FROM ka_uzivatele WHERE user = 'admin'"
check "3.5: a token nobody used keeps the Connect Claude card" 200 /admin.php 'id="pripojit-claude"'
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_api_tokeny SET pouzit = NOW() WHERE nazev = 'unused 3.5'"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_api_tokeny WHERE nazev = 'unused 3.5'"
expect "3.5: once a connection was used, Ask Claude leads again and the card is gone" "$(dash_order 'id="ask-claude-nadpis"' 'class="pruvodce"' 'class="dlazdice"')|$(grep -c 'id="pripojit-claude"' "$WORK/response" || true)" "ok|0"
check "3.5: revoking the token later does not bring the card back" 200 /admin.php 'id="ask-claude-nadpis"'
grep -q 'id="pripojit-claude"' "$WORK/response" && { echo "  CHYBA  3.5: the card came back"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.5: the card stays gone"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna = 'claude_first_used'" # the first real MCP call below sets it again (checked there)
check "přehled: nadpis obrazovky je h1" 200 /admin.php "<h1>Přehled</h1>"
grep -q '<li class=""><a href="/admin.php?module=appearance">' "$WORK/response" && grep -q '<li class=""><a href="/admin.php?module=pages"><strong>Připravte stránky' "$WORK/response" && echo "  ok     první kroky nepočítají vzhled a stránky ze startovacího webu za hotové" || { echo "  CHYBA  první kroky odškrtnuté startovacím webem"; ERRORS=$((ERRORS+1)); }
check "administrace: nadpis h1 a hlavní menu v <nav>" 200 "/admin.php?module=pages" '<nav class="menu-obal" aria-label="Hlavní menu">'
for m in pages "pages&action=new" enquiries parts components "components&action=new" collections "collections&action=new" news "news&action=new" "news&action=links" categories "categories&action=new" tags media stats appearance users "users&action=new" redirects changelog transfer extensions; do check "modul $m" 200 "/admin.php?module=$m"; done
check "uživatelé se shrnutím oprávnění" 200 "/admin.php?module=users" "Smí všechno"
for z in general seo analytics cookies mail webhooks backups; do check "nastavení/$z" 200 "/admin.php?module=settings&tab=$z"; done
# 3.2: tabs that became screens of their own – the old address leads there; the new screens and hubs open
for z in company:business health:status; do expect "3.2: settings&tab=${z%%:*} leads to ${z##*:}" "$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' "$B/admin.php?module=settings&tab=${z%%:*}" | sed 's/.*module=//')" "${z##*:}"; done
check "3.2: System status is its own screen" 200 "/admin.php?module=status" "Cron"
check "3.2: Claude settings hold the instructions and the guardrails" 200 "/admin.php?module=claude_settings" 'name="claude_instructions"'
check "3.2: Business details show the hub tabs" 200 "/admin.php?module=facts" 'zalozky-hub'
check "3.2: the menu leads to the hubs" 200 "/admin.php" "module=claude_settings"
expect "3.2: Business details refuse the actions of Settings it does not offer" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=business&action=download_backup&file=x")" 404
# 3.3.2 (N41): the cron and monitoring tokens change only from System status – not through Business details, which editors share
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'before-n41'), ('health_token', 'before-n41')"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=save" -d "_csrf=$(csrf)" -d novy_token_ulohy=1 -d novy_token=1
N41_BUSINESS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(hodnota ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('tasks_token', 'health_token')")
N41_ALERTS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'alerts_enabled'")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=status&action=save" -d "_csrf=$(csrf)" -d novy_token=1 $([ "$N41_ALERTS" = 0 ] || echo "-d alerts_enabled=1")
expect "3.3.2: Business details never replace the cron or monitoring token, System status does" \
  "$N41_BUSINESS|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(hodnota <> 'before-n41', LENGTH(hodnota)) FROM ka_nastaveni WHERE promenna = 'health_token'")" "before-n41,before-n41|132"
check "nastavení: volba úvodní stránky" 200 "/admin.php?module=settings&tab=general" 'name="home_page"'
check "neznámý modul" 403 "/admin.php?module=neexistuje"
check "2.0: the public API of 1.x is gone" 404 /api/novinky
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('additional_languages','en') ON DUPLICATE KEY UPDATE hodnota='en'"
check "anglická verze webu" 200 /en/ 'lang="en"'
# 2.3.1: saving Settings → General as a browser does (every field of the form as it is) keeps the language versions
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post"
expect "saving Settings → General keeps the language versions" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")" "en"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/novinky/vitejte-v-kalete"); expect "novinka jiné jazykové verze přesměruje" "$code" 301

# a failed news item validation must return the form with a message, not error 500
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$code" = 200 ] && grep -q 'name="titulek"' "$WORK/response" && echo "  ok     chyba ve formuláři novinky vrátí formulář" || { echo "  CHYBA  validace novinky: kód $code"; ERRORS=$((ERRORS+1)); }

# news without a category (enabled only after installation): „Nová novinka“ (New news item) is not a dead end – a default category is created in the site language
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS kat_zaloha; CREATE TABLE kat_zaloha AS SELECT * FROM ka_kategorie; DELETE FROM ka_kategorie; UPDATE ka_nastaveni SET hodnota='en' WHERE promenna='site_language'"
check "nová novinka bez kategorie otevře editor" 200 "/admin.php?module=news&action=new" 'name="titulek"'
expect "výchozí kategorie založená v jazyce webu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(nazev)) FROM ka_kategorie")" "1:News"
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM ka_kategorie; INSERT INTO ka_kategorie SELECT * FROM kat_zaloha; DROP TABLE kat_zaloha; UPDATE ka_nastaveni SET hodnota='cs' WHERE promenna='site_language'"

# news author: sees only their own news items and does not publish
NEWS_ID=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" -d admin=0
# 3.9 N39-1: a new password set by an administrator ends the user's Claude connections (OAuth tokens live a year), personal tokens stay
IDU_AUTOR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'autor'")
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, klient, druh, otisk, vytvoren, expirace) VALUES ($IDU_AUTOR, 'Claude', 'n39client', 'obnova', SHA2('n39-refresh', 256), '$(site_time)', '$(site_time '+300 days')'), ($IDU_AUTOR, 'Claude', 'n39client', 'pristup', SHA2('n39-access', 256), '$(site_time)', '$(site_time '+1 hour')'), ($IDU_AUTOR, 'personal', NULL, 'token', SHA2('n39-personal', 256), '$(site_time)', NULL)"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$IDU_AUTOR" -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" -d admin=0
expect "3.9 N39-1: an administrator's new password ends the user's Claude connections, the personal token stays" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(SUM(druh <> 'token'), '|', SUM(druh = 'token')) FROM ka_api_tokeny WHERE idu = $IDU_AUTOR")" "0|1"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_api_tokeny WHERE idu = $IDU_AUTOR"
JAR2="$WORK/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=autor --data-urlencode "password=$PASSWORD"
code=$(curl -s -b "$JAR2" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=news")
[ "$code" = 200 ] && ! grep -q "action=edit&amp;id=$NEWS_ID\"" "$WORK/response" && echo "  ok     autor nevidí cizí novinky" || { echo "  CHYBA  autor – výpis: kód $code"; ERRORS=$((ERRORS+1)); }
expect "autor cizí novinku neotevře" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=news&action=edit&id=$NEWS_ID")" 404
expect "autor nemá přístup ke stránkám" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages")" 403
TOKEN2=$(curl -s -b "$JAR2" "$B/admin.php?module=news&action=new" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN2" -d idc=0 -d titulek=XSS-test -d tema=1 \
  --data-urlencode 'uvod=<p onmouseover="alert(1)">Perex</p><script>alert(2)</script>' --data-urlencode 'text=<p><img src=x onerror=alert(3)><a href="javascript:alert(4)">odkaz</a></p>'
expect "autor nevloží do novinky skript" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(uvod, text) REGEXP 'script|onerror|onmouseover|javascript' FROM ka_novinky WHERE titulek = 'XSS-test'")" "0"
check "editor vidí na přehledu novinky od autorů, které čekají na vydání" 200 /admin.php "Novinky od autorů čekají na vydání"
check "výpis novinek: filtr Čekají na vydání" 200 "/admin.php?module=news&status=ke_vydani" "XSS-test"

echo "== firma"
check "nastavení/firma" 200 "/admin.php?module=business" 'name="company_hours"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00
So 9–12" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
expect "údaje firmy uloženy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'")" 12345678
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company -d company_type=LocalBusiness -d company_country=CZ --data-urlencode "company_hours=kdykoli"
expect "nesrozumitelná otevírací doba odmítnuta" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%8:00%' AND hodnota NOT LIKE '%kdykoli%' FROM ka_nastaveni WHERE promenna = 'company_hours'")" 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '"@type":"HomeAndConstructionBusiness"' "$WORK/response" && grep -q '"openingHoursSpecification"' "$WORK/response" && grep -q '"latitude":50.0875' "$WORK/response" && grep -q '"vatID":"CZ12345678"' "$WORK/response" \
  && echo "  ok     firma ve strukturovaných datech (LocalBusiness, otevírací doba, souřadnice)" || { echo "  CHYBA  firma ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"
grep -q 'Dlouhá 12<br>110 00 Praha' "$WORK/response" && grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q '<li>Po–Pá 8:00–17:00</li>' "$WORK/response" && grep -q 'IČO 12345678, DIČ CZ12345678' "$WORK/response" \
  && echo "  ok     kontakt vypisuje údaje firmy z Nastavení" || { echo "  CHYBA  údaje firmy na kontaktu"; ERRORS=$((ERRORS+1)); }

echo "== vzhled webu (design systém)"
check "vzhled s předvolbami a náhledem" 200 "/admin.php?module=appearance" 'data-predvolba'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=appearance&action=preview" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy][primarni]=#ff00aa' -d 'ds[zaklad_min]=18'
grep -q 'ka-barva-primarni: #ff00aa' "$WORK/response" && grep -q '"kontrasty"' "$WORK/response" && echo "  ok     živý náhled vrátí tokeny a kontrasty" || { echo "  CHYBA  náhled vzhledu"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" -d layout=zakladni -d tmavy_rezim=vypnuto --data-urlencode 'ds[barvy][primarni]=#9a3412' --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280'
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && { echo "  CHYBA  the saved appearance is on the site before publishing"; ERRORS=$((ERRORS+1)); } || echo "  ok     the saved appearance waits in the draft look"
check "the admin shows the draft look with its changes" 200 "/admin.php?module=appearance" "Nepublikované změny vzhledu"
publish_look
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && grep -q 'ka-sirka: 80rem' "$WORK/response" && grep -q 'ka-pismo-titulky: Georgia' "$WORK/response" && echo "  ok     uložený vzhled je po publikování na webu" || { echo "  CHYBA  uložení vzhledu"; ERRORS=$((ERRORS+1)); }
grep -q 'body{' "$WORK/response" && { echo "  CHYBA  do CSS proniklo neplatné zadání barvy"; ERRORS=$((ERRORS+1)); } || echo "  ok     neplatná barva se nahradí výchozí"

echo "== builder stránek"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
check "builder se otevře a převede textovou stránku" 200 "/admin.php?module=pages&action=builder&id=$IDS" 'id="stavitel-data"'
contains -q '"pri_rolovani":{"typ"' "$WORK/response" && { echo "  CHYBA  3.6 UXA-12: a page section offers the header-only options"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.6 UXA-12: a page section does not offer the header-only options"
TOKEN=$(csrf)
page_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDS" -d "_csrf=$TOKEN" "${@:2}"; }
BUILD='{"v":1,"deti":[{"id":"sek1","typ":"sekce","deti":[{"id":"nad1","typ":"nadpis","znacka":"h1","obsah":{"text":"Builder test"},"styl":{"zaklad":{"barva":"primarni"},"mobil":{"velikost_pisma":"2"}},"tridy":["karta"]},{"id":"faq1","typ":"faq","obsah":{"polozky":[{"otazka":"Kolik to stojí?","odpoved":"<p>Záleží na rozsahu.</p>"}]}},{"id":"txt1","typ":"text","obsah":{"html":"<h2>Jak to funguje</h2><p>Krok za krokem.</p><h2>Jak to funguje</h2><h3 id=\"vlastni\">Vlastní</h3>"}},{"id":"zly1","typ":"skript"}]}]}'
code=$(page_action build_save --data-urlencode "stavba=$BUILD")
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && grep -q 'Neznámý typ prvku' "$WORK/response" && echo "  ok     uložení konceptu vrátí vyčištěnou stavbu a chyby" || { echo "  CHYBA  stavba_uloz: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný JSON stavby odmítnut" "$(page_action build_save -d 'stavba={nesmysl')" 400
expect "uložení z cizí verze odmítnuto (souběžná úprava)" "$(page_action build_save -d verze=0000000000000000 --data-urlencode "stavba=$BUILD")" 409
grep -q '"konflikt":true' "$WORK/response" && grep -q 'Builder test' "$WORK/response" && echo "  ok     konflikt vrátí novější verzi ze serveru" || { echo "  CHYBA  odpověď konfliktu"; ERRORS=$((ERRORS+1)); }
expect "publikování z cizí verze odmítnuto" "$(page_action build_publish -d verze=0000000000000000)" 409
expect "přepsání cizí verze na přání" "$(page_action build_save -d verze=0000000000000000 -d prepsat=1 --data-urlencode "stavba=$BUILD")" 200
expect "builder bez CSRF odmítnut" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_save&id=$IDS" --data-urlencode "stavba=$BUILD")" 400
expect "knihovna sekcí jen přes POST" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_section&id=$IDS&key=faq")" 404
code=$(page_action "build_section&key=vyhody"); [ "$code" = 200 ] && grep -q '"karta"' "$WORK/response" && echo "  ok     sekce z knihovny založí své třídy" || { echo "  CHYBA  stavba_sekce: kód $code"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_class -d nazev=karta --data-urlencode 'styl={"zaklad":{"pozadi":"plocha","odsazeni_y":"l"}}' --data-urlencode 'css=letter-spacing: 0.01em; background: url(x)')
[ "$code" = 200 ] && grep -q 'Nepovolená deklarace' "$WORK/response" && echo "  ok     třída uložena, nebezpečné CSS zahozeno" || { echo "  CHYBA  stavba_trida: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný název třídy odmítnut" "$(page_action build_class -d 'nazev=Karta Velka')" 400
expect "a change of an existing class in the builder goes to the draft look" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%\"karta\"%' FROM ka_nastaveni WHERE promenna = 'look_draft'")" "1"
publish_look
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     koncept není před publikováním na webu" || { echo "  CHYBA  koncept je na webu dřív, než se publikuje"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu pro editor" 200 "/o-nas?build=koncept&editor=1" 'data-ka-id="nad1"'
check "náhled konceptu se neindexuje" 200 "/o-nas?build=koncept" 'noindex'
curl -s -o "$WORK/response" "$B/o-nas?build=koncept&editor=1"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     náhled konceptu nevidí návštěvník" || { echo "  CHYBA  koncept vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_share -d dni=3); SHARED_LINK=$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' "$WORK/response")
curl -s -o "$WORK/response" "$SHARED_LINK"
[ "$code" = 200 ] && [[ "$SHARED_LINK" == "$B/o-nas?build=koncept&preview_key="* ]] && grep -q "Builder test" "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && echo "  ok     sdílený odkaz ukáže koncept bez přihlášení a bez značek editoru" || { echo "  CHYBA  stavba_sdilet: kód $code, odkaz $SHARED_LINK"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas?stavba=koncept&nahled_klic=${SHARED_LINK##*preview_key=}"; grep -q "Builder test" "$WORK/response" && echo "  ok     an old shared preview link (stavba=, nahled_klic=) still opens the draft" || { echo "  CHYBA  old preview link"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_publish); expect "publikování stavby" "$code" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"
grep -q '<h1 id="s-nad1" class="karta">Builder test</h1>' "$WORK/response" && echo "  ok     publikovaná stavba na webu, jedna značka na prvek" || { echo "  CHYBA  stavba na webu"; ERRORS=$((ERRORS+1)); }
grep -q '<h2 id="jak-to-funguje">' "$WORK/response" && grep -q '<h2 id="jak-to-funguje-2">' "$WORK/response" && grep -q '<h3 id="vlastni">' "$WORK/response" && echo "  ok     mezititulky textu mají kotvy (jedinečné, vlastní id zůstane)" || { echo "  CHYBA  kotvy mezititulků v textu"; ERRORS=$((ERRORS+1)); }
grep -q 'data-ka-id' "$WORK/response" && { echo "  CHYBA  značky editoru na veřejném webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     bez značek editoru na veřejném webu"
grep -q '@layer prvky' "$WORK/response" && grep -q '#s-nad1 { color: var(--ka-barva-primarni); }' "$WORK/response" && grep -q '.karta { background-color: var(--ka-barva-plocha)' "$WORK/response" && echo "  ok     CSS prvků a tříd ve vrstvách" || { echo "  CHYBA  CSS stavby"; ERRORS=$((ERRORS+1)); }
grep -q '"FAQPage"' "$WORK/response" && echo "  ok     otázky a odpovědi jako strukturovaná data" || { echo "  CHYBA  FAQPage chybí"; ERRORS=$((ERRORS+1)); }
check "hledání najde obsah stavby" 200 "/hledani?q=Builder+test" 'Nalezeno: 1'
page_action build_save --data-urlencode "stavba=${BUILD/Builder test/Druhá verze}" > /dev/null; page_action build_publish > /dev/null
expect "předchozí publikovaná verze je v historii" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")" 1
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")
page_action build_restore -d "idr=$IDR" > /dev/null; grep -q 'Builder test' "$WORK/response" && echo "  ok     obnovení verze do konceptu" || { echo "  CHYBA  stavba_obnov"; ERRORS=$((ERRORS+1)); }
page_action build_discard > /dev/null; grep -q 'Druhá verze' "$WORK/response" && echo "  ok     zahození změn vrátí publikovanou stavbu" || { echo "  CHYBA  stavba_zahod"; ERRORS=$((ERRORS+1)); }
expect "autor novinek do builderu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=builder&id=$IDS")" 403
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=build_text" -d "_csrf=$TOKEN" -d "ids=$IDS"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<h1>Druhá verze</h1>" "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && echo "  ok     návrat k textu zachová obsah stavby bez rozložení" || { echo "  CHYBA  stavba_text"; ERRORS=$((ERRORS+1)); }

echo "== Claude (MCP): builder"
API_TOKEN="kaleta_$(printf 'a%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$API_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'"
# a grep that reads the whole input: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
contains() { grep "$@" > /dev/null; }
mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q '"name":"create_page"' "$WORK/response" && ! grep -q '"name":"vytvor_stranku"' "$WORK/response" && grep -q '"title":{' "$WORK/response" && echo "  ok     MCP: nástroje s anglickými názvy a parametry" || { echo "  CHYBA  MCP tools/list anglicky"; ERRORS=$((ERRORS+1)); }
# 3.8 (Connectors Directory): every tool has a title (top level = annotations.title), the hints are by the English name
php -r '$t = json_decode(file_get_contents($argv[1]), true)["result"]["tools"]; $bad = array_filter($t, fn ($x) => !is_string($x["title"] ?? null) || $x["title"] === "" || $x["title"] !== ($x["annotations"]["title"] ?? null) || array_slice(array_keys($x), 0, 2) !== ["name", "title"]);
  $a = array_column($t, "annotations", "name"); exit($bad === [] && $a["upload_file"]["openWorldHint"] === true && $a["import_website"]["openWorldHint"] === true && $a["list_pages"]["openWorldHint"] === false
  && ($a["save_build"]["idempotentHint"] ?? null) === true && !isset($a["publish_build"]["idempotentHint"]) && $a["list_pages"]["title"] === "List pages" ? 0 : 1);' "$WORK/response" \
  && echo "  ok     3.8 MCP: every tool has a title, open-world and idempotent hints by the English name" || { echo "  CHYBA  3.8 MCP titles and hints"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' > "$WORK/initialize.json"
php -r '$i = json_decode(file_get_contents($argv[1]), true)["result"]; $s = $i["serverInfo"]; exit($i["protocolVersion"] === "2025-06-18" && str_starts_with($s["name"], "Kaleta – ") && $s["title"] === "Kaleta" && $s["websiteUrl"] === "https://kaletacms.com"
  && $s["icons"][0]["src"] === $argv[2] . "/image/kaleta-znacka.svg" && $s["icons"][1]["sizes"] === ["180x180"] ? 0 : 1);' "$WORK/initialize.json" "$B" \
  && [ "$(curl -s -o /dev/null -w '%{http_code}' "$B/image/kaleta-znacka-180.png")" = 200 ] \
  && echo "  ok     3.8 MCP: initialize names the server (title Kaleta, websiteUrl, icons the site serves)" || { echo "  CHYBA  3.8 MCP serverInfo"; head -c 400 "$WORK/initialize.json"; ERRORS=$((ERRORS+1)); }
mcp list_pages '{}' > "$WORK/response"; grep -q 'title\\":' "$WORK/response" && grep -q 'in_menu\\":' "$WORK/response" && echo "  ok     MCP: anglický nástroj vrací anglické klíče" || { echo "  CHYBA  MCP list_pages"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp seznam_stranek '{}' > "$WORK/response"; grep -q 'titulek\\":' "$WORK/response" && echo "  ok     MCP: český název funguje dál jako skrytý alias" || { echo "  CHYBA  MCP český alias"; ERRORS=$((ERRORS+1)); }
mcp get_page '{"id":99999}' > "$WORK/response"; grep -q 'The page does not exist. Use list_pages.' "$WORK/response" && echo "  ok     MCP: chyba anglického nástroje anglicky" || { echo "  CHYBA  MCP anglická chyba"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# #12 / PR #13: the server's own errors are English; the 401 keeps the WWW-Authenticate header Claude discovers OAuth by
code=$(curl -s -D "$WORK/headers" -o "$WORK/response" -w '%{http_code}' -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
expect "MCP without a token: 401 in English with the OAuth discovery header" "$code|$(grep -c '"error":"The token is invalid or missing."' "$WORK/response")|$(grep -ci '^www-authenticate: Bearer resource_metadata="http.*/.well-known/oauth-protected-resource"' "$WORK/headers")" "401|1|1"
expect "MCP: invalid JSON and an unknown method are answered in English" "$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d 'nope' | grep -c '"Invalid JSON."')|$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"foo/bar"}' | grep -c 'Unknown method: foo')" "1|1"
mcp update_settings '{"settings":{"home_page":999999,"social_facebook":"javascript:alert(1)","site_email":"x@example.com"}}' > "$WORK/response"
expect "MCP: update_settings errors in English (#12)" "$(grep -c 'Only a visible page can be the home page.' "$WORK/response")|$(grep -c 'Invalid value.' "$WORK/response")|$(grep -c 'cannot be changed through the Claude connection' "$WORK/response")|$(grep -cE 'Neplatn|Tohle nastaven|zveřejněná' "$WORK/response")" "1|1|1|0"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | contains 'build_from_html' && echo "  ok     MCP: pokyny serveru s anglickými názvy" || { echo "  CHYBA  MCP pokyny"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; grep -q 'knihovna' "$WORK/response" && grep -q 'ka-mezera' "$WORK/response" && echo "  ok     MCP: schéma builderu" || { echo "  CHYBA  MCP stavba_schema"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_z_html '{"titulek":"Z HTML","html":"<style>.uvod-x { padding-block: var(--ka-mezera-2xl); } .uvod-x h1 { color: red }</style><header class=\"uvod-x\"><div class=\"container\"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class=\"btn\" href=\"/kontakt\">Kontakt</a></div></header><form><input></form>"}' > "$WORK/response"
grep -q 'koncept' "$WORK/response" && grep -q 'Formul' "$WORK/response" && grep -q 'vynech.*btn' "$WORK/response" && echo "  ok     MCP: HTML převedeno na koncept stavby s hlášením (i formulář)" || { echo "  CHYBA  MCP stavba_z_html"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'z-html'")
expect "MCP: nová stránka zůstává skrytá a bez publikované stavby" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba IS NULL, '/', stavba_koncept LIKE '%od Clauda%') FROM ka_stranky WHERE ids = $IDZ")" "0/1/1"
expect "MCP: třída z <style> uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT css FROM ka_tridy WHERE nazev = 'uvod-x'")" "padding-block: var(--ka-mezera-2xl);"
mcp vloz_sekci "{\"id\":$IDZ,\"sekce\":\"faq\"}" > /dev/null
mcp publikuj_stavbu "{\"id\":$IDZ}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDZ"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h1>Stránka od Clauda</h1>' "$WORK/response" && grep -q 'class="uvod-x"' "$WORK/response" && ! grep -q 'container' "$WORK/response" && grep -q '"FAQPage"' "$WORK/response" && echo "  ok     MCP: publikovaná stránka od Clauda na webu" || { echo "  CHYBA  MCP publikování"; ERRORS=$((ERRORS+1)); }
mcp uprav_design_system '{"ds":{"barvy":{"primarni":"#0f766e"},"zaobleni":"l"}}' > "$WORK/response"; mcp publish_look '{}' > /dev/null; grep -q 'citelnost' "$WORK/response" && echo "  ok     MCP: úprava design systému" || { echo "  CHYBA  MCP uprav_design_system"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "design systém z MCP je na webu" 200 / 'ka-barva-primarni: #0f766e'
check "design systém z MCP zachoval ostatní barvy" 200 / 'ka-barva-plocha: #f5f6f8'
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"tmavy","tmavy_prepinac":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'data-tmavy data-tema="tmavy"' "$WORK/response" && grep -q 'data-tema-volba="svetly"' "$WORK/response" && grep -q 'localStorage.getItem(.ka-tema.)' "$WORK/response" && grep -q 'data-tema=\\"tmavy\\"\]\|data-tema="tmavy"\] {' "$WORK/response" \
    && echo "  ok     tmavý vzhled vždy a přepínač vzhledu pro návštěvníky (i přes MCP)" || { echo "  CHYBA  tmavý režim a přepínač vzhledu"; ERRORS=$((ERRORS+1)); }
grep -q 'class="ka-jazyky-vyber ka-tema" role="group"' "$WORK/response" && ! grep -q '<nav class="ka-jazyky-vyber ka-tema"' "$WORK/response" \
  && echo "  ok     3.5 (UXP-06): the appearance switcher is a labelled group, not a navigation inside the navigation" || { echo "  CHYBA  3.5: appearance switcher markup"; ERRORS=$((ERRORS+1)); }
# 3.6 (UXP-01): the dark block carries a primary, secondary and text on primary of its own – derived from the light ones
# (until 3.5 only text, background and surface, so the light primary #0f766e stayed on the dark page); a chosen one wins
darkblock() { awk '/^:root\[data-tmavy\]\[data-tema="tmavy"\] \{/{on=1} on{print} on&&/^\}/{exit}' "$WORK/response"; }
darkblock > "$WORK/dark.css"
contains -q -- '--ka-barva-primarni: #' "$WORK/dark.css" && contains -q -- '--ka-barva-sekundarni: #' "$WORK/dark.css" && contains -q -- '--ka-barva-na-primarni: #' "$WORK/dark.css" && ! contains -q -- '--ka-barva-primarni: #0f766e' "$WORK/dark.css" \
  && echo "  ok     3.6 (UXP-01): dark mode has its own readable primary, secondary and button text" || { echo "  CHYBA  3.6: the dark block has no primary of its own"; cat "$WORK/dark.css"; ERRORS=$((ERRORS+1)); }
mcp update_design_system '{"design":{"barvy_tmave":{"primarni":"#ffd7a8"}}}' > "$WORK/response"; mcp publish_look '{}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
contains -q 'readability_dark\\":' "$WORK/response" && contains -q 'dark_palette\\":' "$WORK/response" && contains -q 'primarni\\":\\"#ffd7a8' "$WORK/response" \
  && echo "  ok     3.6: update_design_system sets a dark primary and answers with the dark palette and its readability" || { echo "  CHYBA  3.6: update_design_system dark colours"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/"; darkblock > "$WORK/dark.css"
contains -q -- '--ka-barva-primarni: #ffd7a8' "$WORK/dark.css" && echo "  ok     3.6: the chosen dark primary is on the site" || { echo "  CHYBA  3.6: the chosen dark primary is not on the site"; ERRORS=$((ERRORS+1)); }
mcp update_design_system '{"design":{"barvy_tmave":{"primarni":""}}}' > /dev/null; mcp publish_look '{}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; darkblock > "$WORK/dark.css"
contains -q -- '--ka-barva-primarni: #' "$WORK/dark.css" && ! contains -q -- '--ka-barva-primarni: #ffd7a8' "$WORK/dark.css" && echo "  ok     3.6: an empty dark primary is automatic again" || { echo "  CHYBA  3.6: the dark primary does not return to automatic"; ERRORS=$((ERRORS+1)); }
# Site appearance: "automatic" ticked drops a picked dark primary; a hard-to-read dark pair warns on saving
check "3.6: Site appearance lists the dark mode readability" 200 "/admin.php?module=appearance" 'data-kontrasty-tmave'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=appearance&action=preview" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy_tmave][primarni]=#000000' -d 'ds[tmave_auto][]=primarni'
contains -q '"kontrasty_tmave"' "$WORK/response" && ! contains -q '"primarni":"#000000"' "$WORK/response" && echo "  ok     3.6: the appearance preview derives a dark primary left on automatic" || { echo "  CHYBA  3.6: appearance preview of dark colours"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" -d dark_mode=tmavy -d theme_switcher=1 --data-urlencode 'ds[barvy_tmave][primarni]=#16181d'
check "3.6: saving a hard-to-read dark colour warns" 200 "/admin.php?module=appearance" 'Tmavý režim: některé dvojice barev jsou špatně čitelné'
mcp discard_look '{}' > /dev/null
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"vypnuto","tmavy_prepinac":"0"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== Claude (MCP): stavba webu bez administrace"
mcp stavba_z_html '{"titulek":"Mrizka","html":"<style>.mriz-t { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .kar-t:hover { box-shadow: var(--ka-stin-m) } @media (max-width: 767px) { .mriz-t { grid-template-columns: 1fr } }</style><section><div class=\"mriz-t\"><div class=\"kar-t\"><h3>Jedna</h3></div><div class=\"kar-t\"><h3>Dva</h3></div></div></section>"}' > "$WORK/response"
IDM2=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'mrizka'")
expect "MCP: @media a :hover z <style> jako stavy třídy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT styl FROM ka_tridy WHERE nazev = 'mriz-t'), (SELECT styl FROM ka_tridy WHERE nazev = 'kar-t'))")" '{"mobil":{"sloupce":"1"}}{"hover":{"stin":"m"}}'
mcp stavba_nacti "{\"id\":$IDM2}" > "$WORK/response"
grep -q 'mriz-t' "$WORK/response" && ! grep -q 'zobrazeni' "$WORK/response" && ! grep -q '\\"odkaz\\":\\"\\"' "$WORK/response" && echo "  ok     MCP: stavba_nacti bez výchozích hodnot, prvek s třídou bez výchozího stylu" || { echo "  CHYBA  MCP stavba_nacti kompaktní"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDH3=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["stavba"]["deti"][0]["deti"][0]["deti"][0]["deti"][0]["id"];' "$WORK/response")
mcp stavba_uprav "{\"id\":$IDM2,\"operace\":[{\"op\":\"uprav\",\"id\":\"$IDH3\",\"obsah\":{\"text\":\"Opraveno\"}},{\"op\":\"smaz\",\"id\":\"neni\"}]}" > "$WORK/response"
grep -q 'chyby_operaci\\":{\\"op\[1\]' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%Opraveno%' FROM ka_stranky WHERE ids = $IDM2")" = 1 ] \
    && echo "  ok     MCP: dílčí úprava prvku podle id (chybná operace nahlášena)" || { echo "  CHYBA  MCP stavba_uprav"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
CHECK_RESULT=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo implode("|", array_column($j["kontrola"] ?? [], "zprava"));' "$WORK/response")
[[ "$CHECK_RESULT" == *"(h1)"* ]] && echo "  ok     MCP: zápis stavby vrátí kontrolu před publikováním (stránka bez h1)" || { echo "  CHYBA  MCP kontrola: $CHECK_RESULT"; ERRORS=$((ERRORS+1)); }
PREVIEW=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["nahled"];' "$WORK/response")
curl -s -o "$WORK/response" -w '%{http_code}' "$PREVIEW" > "$WORK/kod"; grep -q 'Opraveno' "$WORK/response" && grep -q 'noindex' "$WORK/response" && [ "$(cat "$WORK/kod")" = 200 ] \
    && echo "  ok     podepsaný náhled konceptu skryté stránky bez přihlášení" || { echo "  CHYBA  podepsaný náhled ($(cat "$WORK/kod"))"; ERRORS=$((ERRORS+1)); }
expect "náhled s cizím nebo pozměněným klíčem nejde" "$(curl -s -o /dev/null -w '%{http_code}' "${PREVIEW%?}x")" 404
expect "klíč náhledu jedné stránky neotevře jinou" "$(curl -s -o /dev/null -w '%{http_code}' "$B/z-html?build=koncept&preview_key=${PREVIEW##*preview_key=}" | tr -d '\n'; curl -s "$B/z-html?build=koncept&preview_key=${PREVIEW##*preview_key=}" | grep -c 'Opraveno')" "2000"
mcp nahled_odkaz '{"cast":"paticka"}' > "$WORK/response"; grep -q 'part=paticka&build=koncept&preview_key=' "$WORK/response" && echo "  ok     MCP: odkaz na náhled části webu" || { echo "  CHYBA  MCP nahled_odkaz"; ERRORS=$((ERRORS+1)); }
mcp uloz_tridy '{"css":".stitek-t { padding: var(--ka-mezera-2xs) var(--ka-mezera-s); border-radius: var(--ka-zaobleni) } @media (max-width: 1023px) { .stitek-t { font-size: var(--ka-krok--1) } }"}' > "$WORK/response"
mcp uloz_tridy '{"css":".stitek-t:hover { background-color: #ffe3dc }"}' > /dev/null
mcp seznam_trid '{"nazev":"stitek-t"}' > "$WORK/response"; grep -q 'velikost_pisma\\":\\"-1' "$WORK/response" && grep -q 'hover' "$WORK/response" && grep -q 'border-radius' "$WORK/response" && echo "  ok     MCP: sdílená třída z CSS i se stavem tablet" || { echo "  CHYBA  MCP uloz_tridy"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
PNG=$(php -r '$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 255, 79, 46)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
mcp nahraj_soubor "{\"nazev\":\"tym-foto.png\",\"data\":\"$PNG\",\"popis\":\"Tym v dilne\"}" > "$WORK/response"
MEDIUM=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["adresa"] ?? "";' "$WORK/response")
[ -n "$MEDIUM" ] && [ -f "$WORK/web/$MEDIUM" ] && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE obr_poloha = '$MEDIUM'")" = "Tym v dilne" ] \
    && echo "  ok     MCP: obrázek nahraný v base64 je v Médiích" || { echo "  CHYBA  MCP nahraj_soubor (obrázek)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor "{\"nazev\":\"pismo.woff2\",\"data\":\"$(base64 < image/pisma/bricolage-grotesque-latin.woff2 | tr -d '\n')\"}" > "$WORK/response"
grep -q 'vlastni_pisma' "$WORK/response" && grep -q 'pismo-[a-f0-9]*\.woff2' "$WORK/response" && echo "  ok     MCP: písmo WOFF2 do Médií s návodem pro design system" || { echo "  CHYBA  MCP nahraj_soubor (písmo)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor '{"nazev":"skript.php","data":"PD9waHAgZWNobyAxOw=="}' > "$WORK/response"; grep -q 'isError' "$WORK/response" && ! ls "$WORK"/web/media/*/*/skript* > /dev/null 2>&1 && echo "  ok     MCP: PHP ani jiný spustitelný soubor nahrát nejde" || { echo "  CHYBA  MCP nahraj_soubor pustil PHP"; ERRORS=$((ERRORS+1)); }
mcp uprav_nastaveni '{"nastaveni":{"text_paticky":"Paticka od Clauda","email_webu":"utocnik@example.com","firma_ico":"abc"}}' > "$WORK/response"
expect "MCP: nastavení webu – povolené se uloží, e-mail a neplatné IČO ne" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'footer_text'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '') <> 'utocnik@example.com', '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'), '') <> 'abc')")" "Paticka od Clauda|1|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'spravce@example.cz' WHERE promenna = 'site_email'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/" | contains 'spravce@example.cz' && { echo "  CHYBA  e-mail webu je vidět na webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     e-mail webu (poptávky, upozornění) se na webu neukazuje"
mcp uprav_nastaveni '{"nastaveni":{"firma_email":"info@example.cz"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "veřejný e-mail firmy v patičce" 200 / "info@example.cz"
check "security.txt: without a contact there is none" 404 /.well-known/security.txt
mcp update_settings '{"settings":{"security_contact":"security@example.com"}}' > /dev/null
check "security.txt from the security contact (RFC 9116)" 200 /.well-known/security.txt "Contact: mailto:security@example.com"
mcp uprav_nastaveni '{"nastaveni":{"logo_webu":"image/kaleta-logo.svg","favicon":"../config.php"}}' > "$WORK/response"
expect "MCP: logo webu ze systémových souborů, cesta mimo media/ a image/ neprojde" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'favicon'), ''))")" "image/kaleta-logo.svg|"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "3.5 (UXP-15): an SVG logo gets its width and height from the file" 200 / 'kaleta-logo.svg" alt="[^"]*" width="281" height="71"'
mcp uloz_presmerovani '{"z":"/stary-web/sluzby","na":"/z-html"}' > /dev/null
expect "MCP: přesměrování staré adresy" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/stary-web/sluzby")" "301 $B/z-html"
mcp smaz_stranku "{\"id\":$IDM2}" > /dev/null
expect "MCP: stránka do koše" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $IDM2")" 1
mcp smaz_stranku "{\"id\":$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")}" | contains 'isError' && echo "  ok     MCP: úvodní stránku smazat nejde" || { echo "  CHYBA  MCP smazal úvodní stránku"; ERRORS=$((ERRORS+1)); }
mcp vytvor_sablonu '{"nazev":"test-kopie"}' > "$WORK/response"; mcp copy_theme '{"name":"test-kopie2"}' >> "$WORK/response"
[ "$(grep -o 'isError' "$WORK/response" | wc -l | tr -d ' ')" = 2 ] && [ ! -d "$WORK/web/layout/test-kopie" ] && [ ! -d "$WORK/web/layout/test-kopie2" ] && echo "  ok     MCP: vlastní šablony už přes napojení nevznikají" || { echo "  CHYBA  MCP šablony"; ERRORS=$((ERRORS+1)); }
mcp vytvor_kategorii '{"nazev":"Kategorie XSS","popis":"<p>Úvod</p><script>alert(1)</script><img src=x onerror=alert(2)>"}' > /dev/null
check "MCP: popis kategorie se vyčistí" 200 "/novinky/kategorie/kategorie-xss" "Úvod"
! grep -qE '<script>alert|onerror' "$WORK/response" && echo "  ok     MCP: v popisu kategorie nezůstal skript" || { echo "  CHYBA  popis kategorie pustil skript"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_kategorie SET popis = '<p>Stary popis</p><script>alert(3)</script>' WHERE seo_link = 'kategorie-xss'"
check "Uložený starý popis kategorie" 200 "/novinky/kategorie/kategorie-xss" "Stary popis"
! grep -q '<script>alert(3)' "$WORK/response" && echo "  ok     Výpis čistí i dřív uložený popis kategorie" || { echo "  CHYBA  výpis kategorie vypsal skript"; ERRORS=$((ERRORS+1)); }

echo "== configurable news URL (news_slug, PR #11)"
slug_q() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
slug_code() { rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B$1"; }
mcp create_page '{"title":"Blog"}' > /dev/null
BLOGPAGE=$(slug_q "SELECT ids FROM ka_stranky WHERE seo_link = 'blog'")
mcp trash_page "{\"id\":$BLOGPAGE}" > /dev/null
mcp update_settings '{"settings":{"news_slug":"blog"}}' > /dev/null
expect "news_slug: set over MCP (a page in the trash does not block it)" "$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug'")" "blog"
expect "news_slug: /blog lists the news, the item and its .md work" "$(slug_code /blog)|$(slug_code /blog/vitejte-v-kalete)|$(slug_code /blog/vitejte-v-kalete.md)" "200 |200 |200 "
expect "news_slug: /novinky and its items redirect permanently to /blog" "$(slug_code /novinky)|$(slug_code /novinky/vitejte-v-kalete)" "301 $B/blog|301 $B/blog/vitejte-v-kalete"
expect "news_slug: the listing and the RSS feed link to /blog" "$(curl -s "$B/blog" | grep -c '/blog/vitejte-v-kalete"')|$(curl -s "$B/rss.xml" | grep -c '/blog/vitejte-v-kalete</link>')" "1|1"
mcp uloz_presmerovani '{"z":"/blog/old-wp-post","na":"/z-html"}' > /dev/null
expect "news_slug: a stored redirect under /blog fires (as a WordPress import writes it)" "$(slug_code /blog/old-wp-post)" "301 $B/z-html"
mcp restore_from_trash "{\"type\":\"page\",\"id\":$BLOGPAGE}" > "$WORK/response"
expect "news_slug: a page restored from the trash onto the news URL gets a free one" "$(slug_q "SELECT CONCAT(seo_link, '|', smazano IS NULL) FROM ka_stranky WHERE ids = $BLOGPAGE")|$(grep -c '/blog-2' "$WORK/response")" "blog-2|1|1"
slug_q "DELETE FROM ka_stranky WHERE ids = $BLOGPAGE"
mcp update_settings '{"settings":{"news_slug":"oauth"}}' > "$WORK/response"
mcp update_settings '{"settings":{"news_slug":"Blog Post"}}' >> "$WORK/response"
expect "news_slug: oauth and a badly formed slug are refused over MCP, with the reason in English" "$(grep -c 'This URL is used by the system' "$WORK/response")|$(grep -c 'Use only lowercase letters' "$WORK/response")|$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug'")" "1|1|blog"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post" -d news_slug=oauth
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=general"
expect "news_slug: oauth is refused in the admin with the reason" "$(grep -c 'Adresa novinek.*Tuto adresu používá systém' "$WORK/response")|$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug'")" "1|blog"
# a value written straight to the database (an old import, a direct edit) is ignored: OAuth and MCP keep working
slug_q "UPDATE ka_nastaveni SET hodnota = 'oauth' WHERE promenna = 'news_slug'"
expect "news_slug: a stored oauth is ignored – OAuth, MCP and /novinky keep working" "$(curl -s -X POST "$B/oauth/token" -d grant_type=refresh_token -d refresh_token=x -d client_id=x | grep -c '"error":"invalid_client"')|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?client_id=x")|$(mcp list_pages '{}' | grep -c '"result"')|$(slug_code /novinky)" "1|400|1|200 "
slug_q "UPDATE ka_nastaveni SET hodnota = 'blog' WHERE promenna = 'news_slug'"
mcp update_settings '{"settings":{"news_slug":"magazin"}}' > /dev/null
expect "news_slug: after blog → magazin the old URLs redirect, the stored redirect still fires" "$(slug_code /blog/vitejte-v-kalete)|$(slug_code /blog)|$(slug_code /magazin/vitejte-v-kalete)|$(slug_code /blog/old-wp-post)" "301 $B/magazin/vitejte-v-kalete|301 $B/magazin|200 |301 $B/z-html"
mcp update_settings '{"settings":{"news_slug":""}}' > /dev/null
expect "news_slug: back to empty, both earlier slugs redirect to /novinky" "$(slug_code /blog/vitejte-v-kalete)|$(slug_code /magazin)|$(slug_code /novinky/vitejte-v-kalete)|$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug_previous'")" "301 $B/novinky/vitejte-v-kalete|301 $B/novinky|200 |magazin,blog"
slug_q "DELETE FROM ka_presmerovani WHERE z_adresy LIKE '%old-wp-post'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('news_slug', 'news_slug_previous')"; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== části webu v builderu"
check "části webu" 200 "/admin.php?module=parts" "Záhlaví"
check "záhlaví se otevře v builderu s koncept podle šablony" 200 "/admin.php?module=parts&action=builder&type=hlavicka&language=" 'id="stavitel-data"'
contains -q '"pri_rolovani":{"typ"' "$WORK/response" && echo "  ok     3.6 UXA-12: the header part offers the header options" || { echo "  CHYBA  3.6 UXA-12: the header part lost its header options"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf)
part_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&type=$2&language=" -d "_csrf=$TOKEN" "${@:3}"; }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     nepublikované záhlaví kreslí šablona" || { echo "  CHYBA  nepublikované záhlaví je na webu"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu záhlaví pro editor" 200 "/o-nas?part=hlavicka&build=koncept&editor=1" 'data-ka-typ="navigace"'
curl -s -o "$WORK/response" "$B/o-nas?part=hlavicka&build=koncept&editor=1"; ! grep -q 'data-ka-typ' "$WORK/response" && echo "  ok     náhled části nevidí návštěvník" || { echo "  CHYBA  koncept části vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
expect "publikování záhlaví" "$(part_action build_publish hlavicka)" 200
curl -s -o "$WORK/response" "$B/o-nas"
grep -q 'class="ka-nav"' "$WORK/response" && ! grep -q 'header class="hlavicka"' "$WORK/response" && grep -q 'href="/o-nas" aria-current="page"' "$WORK/response" && echo "  ok     záhlaví z builderu na webu s aktivní položkou menu" || { echo "  CHYBA  záhlaví z builderu"; ERRORS=$((ERRORS+1)); }
[ "$(grep -o '<style>' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && [ "$(grep -o '@layer stavitel {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && echo "  ok     stránka a části webu mají jedno CSS" || { echo "  CHYBA  CSS částí webu se opakuje"; ERRORS=$((ERRORS+1)); }
WRAPPER='{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}'
check "obálka novinky v builderu" 200 "/admin.php?module=parts&action=builder&type=novinka&language=" 'id="stavitel-data"'
part_action build_save novinka --data-urlencode "stavba=$WRAPPER" > /dev/null; part_action build_publish novinka > /dev/null
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete"; grep -q 'Pod článkem' "$WORK/response" && grep -q '<main id="obsah" class="stavba" tabindex="-1">' "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && grep -q 'Vítejte' "$WORK/response" && echo "  ok     obálka kolem novinky" || { echo "  CHYBA  obálka novinky"; ERRORS=$((ERRORS+1)); }
part_action build_save hlavicka --data-urlencode 'stavba={"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}' > /dev/null; part_action build_publish hlavicka > /dev/null
expect "předchozí záhlaví je ve verzích" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'hlavicka:'")" 1
part_action template hlavicka > /dev/null
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     vrácení záhlaví na šablonu" || { echo "  CHYBA  vrácení na šablonu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]},"publikovat":true}' > "$WORK/response"
grep -q 'publikováno' "$WORK/response" && echo "  ok     MCP: patička ze stavby" || { echo "  CHYBA  MCP patička"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<p class=\"ka-udaj\">&copy; $(date +%Y) Testovací firma</p>" "$WORK/response" && ! grep -q 'footer class="paticka"' "$WORK/response" && echo "  ok     patička z MCP na webu" || { echo "  CHYBA  patička z MCP na webu"; ERRORS=$((ERRORS+1)); }
expect "autor novinek k částem webu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=parts")" 403

echo "== formuláře a poptávky"
# webhook receiver (1.8): logs every call with its signature headers; an address containing "chyba" answers 500
HOOK_PORT=$((PORT + 4)); mkdir -p "$WORK/hook"
cat > "$WORK/hook/router.php" <<'PHP'
<?php
$log = __DIR__ . '/calls.log';
$h = array_change_key_case(getallheaders());
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'event' => $h['x-kaleta-event'] ?? '', 'delivery' => $h['x-kaleta-delivery'] ?? '', 'ts' => $h['x-kaleta-timestamp'] ?? '',
    'sig' => $h['x-kaleta-signature'] ?? '', 'body' => file_get_contents('php://input')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
http_response_code(str_contains($_SERVER['REQUEST_URI'], 'chyba') ? 500 : 204); return true;
PHP
(cd "$WORK/hook" && exec php -S "127.0.0.1:$HOOK_PORT" router.php > /dev/null 2>&1) & HOOK_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$HOOK_PORT/ping" && break; sleep 0.2; done; : > "$WORK/hook/calls.log"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('webhook_enquiries', 'https://hooks.example.com/crm'), ('webhook_test_url', 'http://127.0.0.1:$HOOK_PORT')"
# hook_check <n>: is the n-th logged call signed with the site's secret? prints event|signature ok|uri
hook_check() { php -r '$c = json_decode(explode("\n", trim(file_get_contents($argv[1])))[$argv[2] - 1] ?? "null", true); if (!$c) { echo "none"; exit; }
  echo $c["event"], "|", hash_equals("sha256=" . hash_hmac("sha256", $c["ts"] . "." . $c["body"], $argv[3]), $c["sig"]) ? "signed" : "BAD", "|", $c["uri"];' "$WORK/hook/calls.log" "$1" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'")"; }
curl -s -o "$WORK/formular.html" "$B/kontakt"
grep -q 'class="ka-formular"' "$WORK/formular.html" && grep -q 'name="as_podpis"' "$WORK/formular.html" && echo "  ok     kontakt má poptávkový formulář" || { echo "  CHYBA  formulář na kontaktu"; ERRORS=$((ERRORS+1)); }
field_value() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/formular.html" | head -1 | sed 's/.*value="//;s/"$//'; }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
grep -q 'method="post" action="/form"' "$WORK/formular.html" && ! grep -q 'action="/formular"' "$WORK/formular.html" && echo "  ok     3.7: a form posts to the English /form" || { echo "  CHYBA  3.7: form action: $(grep -o 'action="[^"]*"' "$WORK/formular.html" | sort -u | tr '\n' ' ')"; ERRORS=$((ERRORS+1)); }
# 3.7: submit_form posts to the English /form, the checks right below to the Czech /formular (forms on pages cached before 3.7)
submit_form() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" "$@"; }
# too fast a submit (autofill): its own code and the message „počkejte chvilku“ (wait a moment), not „nepodařilo se ověřit“ (could not verify)
NOW=$(date +%s); FAST_SIGNATURE=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$FORM_SOURCE|$FORM_ELEMENT|$NOW" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$NOW" -d "as_podpis=$FAST_SIGNATURE" -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *result=rychle*) echo "  ok     příliš rychlé odeslání má vlastní výsledek";; *) echo "  CHYBA  příliš rychlé odeslání formuláře"; ERRORS=$((ERRORS+1));; esac
check "hlášení po příliš rychlém odeslání radí počkat" 200 "/kontakt?form=$FORM_ELEMENT&result=rychle" "Počkejte prosím chvilku a odešlete ho znovu"
grep -q 'type="text" autocomplete="name"' "$WORK/formular.html" && grep -q 'type="tel" autocomplete="tel" maxlength="30" pattern="' "$WORK/formular.html" && echo "  ok     jméno s automatickým vyplněním, telefon s kontrolou v prohlížeči" || { echo "  CHYBA  autocomplete jména nebo vzor telefonu"; ERRORS=$((ERRORS+1)); }
grep -q 'name="as_cas" value="[0-9]*" data-cekat="4"' "$WORK/formular.html" && echo "  ok     formulář nese minimální dobu pro odložené odeslání" || { echo "  CHYBA  data-cekat u formuláře"; ERRORS=$((ERRORS+1)); }
sleep 4
location=$(submit_form -H "Referer: $B/kontakt?utm_source=newsletter&utm_medium=email&utm_campaign=jaro" -d p0=Jana --data-urlencode p1=jana@example.cz -d p2= --data-urlencode "p3=Chci kuchyň na míru." -d p4=1)
case "$location" in *"/kontakt?form=$FORM_ELEMENT&result=ok#"*"$FORM_ELEMENT") echo "  ok     odeslání formuláře";; *) echo "  CHYBA  odeslání formuláře: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(stav)) FROM ka_poptavky")" "1/jana@example.cz/0"
expect "webhook: new enquiry delivered after the response, signed" "$(hook_check 1)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(event, '/', status, '/', delivered IS NOT NULL, '/', body IS NULL) FROM ka_webhook_deliveries")" "nova_poptavka|signed|/crm|nova_poptavka/204/1/1"
grep -q 'email.":."jana@example.cz' "$WORK/hook/calls.log" && echo "  ok     webhook: the enquiry data are in the body" || { echo "  CHYBA  webhook body: $(cat "$WORK/hook/calls.log")"; ERRORS=$((ERRORS+1)); }
expect "poptávka nese kampaň z utm_* stránky s formulářem" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT kampan FROM ka_poptavky")" "utm_source=newsletter&utm_medium=email&utm_campaign=jaro"
case "$(submit_form -d p0=Jana -d p1=neni-email -d p3=x -d p4=1)" in *result=pole\&field=1*) echo "  ok     neplatný e-mail odmítnut s číslem pole";; *) echo "  CHYBA  validace e-mailu"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/kontakt?form=$FORM_ELEMENT&result=pole&field=1"
grep -q 'aria-invalid="true" aria-describedby="f-'"$FORM_ELEMENT"'-1-chyba"' "$WORK/response" && grep -q 'data-obnovit' "$WORK/response" && echo "  ok     chybné pole je označené a vyplněné hodnoty se obnoví" || { echo "  CHYBA  označení chybného pole"; ERRORS=$((ERRORS+1)); }
case "$(submit_form -d p0=Jana --data-urlencode p1=jana@example.cz -d p3=x)" in *result=pole*) echo "  ok     chybějící souhlas odmítnut";; *) echo "  CHYBA  povinný souhlas"; ERRORS=$((ERRORS+1));; esac
submit_form -d p0=Robot --data-urlencode p1=r@example.cz -d p3=spam -d p4=1 -d web_adresa=http://spam.example > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d as_podpis=podvrh -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *result=overeni*) echo "  ok     podvržený podpis odmítnut";; *) echo "  CHYBA  podpis formuláře"; ERRORS=$((ERRORS+1));; esac
case "$(submit_form -d zdroj=stranka:999 -d p0=A)" in *form=*) echo "  CHYBA  neexistující formulář přijat"; ERRORS=$((ERRORS+1));; *) echo "  ok     neexistující formulář nic neuloží";; esac
expect "robot ani chyby poptávku nepřidaly" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_poptavky")" 1
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_poptavky")
check "poptávky v administraci" 200 "/admin.php?module=enquiries" "jana@example.cz"
check "detail poptávky" 200 "/admin.php?module=enquiries&action=detail&id=$IDP" "Chci kuchyň na míru."
grep -q '>Tester</option>' "$WORK/response" && ! grep -q '>Autor</option>' "$WORK/response" && echo "  ok     poptávku vyřizuje jen ten, kdo má přístup k Poptávkám" || { echo "  CHYBA  výběr Vyřizuje nabízí uživatele bez přístupu k Poptávkám"; ERRORS=$((ERRORS+1)); }
expect "otevřená poptávka je přečtená" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_poptavky")" 1
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=csv"; grep -q 'Chci kuchyň na míru.' "$WORK/response" && echo "  ok     export poptávek do CSV" || { echo "  CHYBA  CSV poptávek"; ERRORS=$((ERRORS+1)); }
check "poděkování po odeslání (na místě formuláře)" 200 "/kontakt?form=$FORM_ELEMENT&result=ok" 'class="ka-formular-hotovo"'

echo "== kolekce"
check "kolekce" 200 "/admin.php?module=collections" "Kolekce"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$TOKEN" -d idk=0 --data-urlencode "nazev=Tým" -d detail=1 \
  --data-urlencode "pole[0][popisek]=Funkce" -d "pole[0][typ]=text" --data-urlencode "pole[1][popisek]=Foto" -d "pole[1][typ]=obrazek" --data-urlencode "pole[2][popisek]=Medailonek" -d "pole[2][typ]=html"
IDK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
expect "kolekce založena s poli" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM ka_kolekce WHERE idk = $IDK")" 1
save_item() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$IDK" -d idp=0 "$@"; }
save_item --data-urlencode "nazev=Jana Nováková" --data-urlencode "data[funkce]=Jednatelka" --data-urlencode "data[medailonek]=<p>Dvacet let <b>v oboru</b>.</p><script>x</script>" -d poradi=1 -d zobrazit=1
save_item --data-urlencode "nazev=Skrytý Člen" --data-urlencode "data[funkce]=Tajný" -d poradi=2
check "položky kolekce" 200 "/admin.php?module=collections&action=items&id=$IDK" "Jana Nováková"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"smy1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\"},\"deti\":[{\"id\":\"kar1\",\"typ\":\"kontejner\",\"styl\":{\"zaklad\":{\"pozadi\":\"plocha\"}},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>{{funkce}}</p>{{medailonek}}\"}},{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Profil\",\"odkaz\":\"{{url}}\"}}]}]}]}]}}" > "$WORK/response"
grep -q 'publikováno' "$WORK/response" || { echo "  CHYBA  MCP stránka s výpisem kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h3>Jana Nováková</h3>' "$WORK/response" && grep -q '<p>Jednatelka</p>' "$WORK/response" && grep -q '^<p>Dvacet let <b>v oboru</b>.</p>' "$WORK/response" && grep -q 'href="/tym/jana-novakova"' "$WORK/response" && ! grep -q 'Skrytý' "$WORK/response" \
  && echo "  ok     výpis kolekce na stránce (jen zveřejněné položky, hodnoty dosazené)" || { echo "  CHYBA  výpis kolekce"; ERRORS=$((ERRORS+1)); }
grep -q 'class="s-kar1"' "$WORK/response" && ! grep -q 'id="s-kar1"' "$WORK/response" && grep -q '\.s-kar1 { background-color' "$WORK/response" && ! grep -q '<script>x' "$WORK/response" \
  && echo "  ok     opakované prvky mají styl přes třídu, ne duplicitní id" || { echo "  CHYBA  styl ve výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "detail položky kolekce" 200 /tym/jana-novakova "Jednatelka"
check "detail má nadpis položky" 200 /tym/jana-novakova "<h1>Jana Nováková</h1>"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/tym/skryty-clen"); expect "skrytá položka nemá detail" "$code" 404
check "mapa webu obsahuje detail položky" 200 /sitemap.xml "/tym/jana-novakova"
check "šablona detailu v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK" 'id="stavitel-data"'
mcp seznam_kolekci '{}' > "$WORK/response"; grep -q 'kolekce\\":\\"tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: seznam kolekcí s poli" || { echo "  CHYBA  MCP seznam_kolekci"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Petr Svoboda","data":{"funkce":"Mistr truhlář"},"zobrazit":true}' > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Text JSON","values":"{\"funkce\":\"Z textu\"}"}' > "$WORK/response"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Z textu"}' | contains 'Text JSON' && echo "  ok     MCP: data poslaná jako text JSON se uloží" || { echo "  CHYBA  MCP data jako text JSON"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_menu '{"umisteni":"hlavni","polozky":"nejde precist"}' | contains 'musí být seznam' && echo "  ok     MCP: nečitelné položky menu jsou chyba, menu se nevrátí na automatické" || { echo "  CHYBA  MCP nečitelné položky menu"; ERRORS=$((ERRORS+1)); }
mcp save_classes '{"classes":[{"name":"x"}]}' | contains 'unknown_parameters' && echo "  ok     MCP: neznámý parametr je ve výsledku, ne tiše vynechaný" || { echo "  CHYBA  MCP neznámé parametry"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Spatna data","data":"funkce=x"}' | contains 'musí být objekt' && echo "  ok     MCP: nečitelná data položky jsou chyba, ne tiché vynechání" || { echo "  CHYBA  MCP nečitelná data položky"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman","adresa":"zdenek","zobrazit":true}' > "$WORK/response"
grep -q 'tym\\/zdenek' "$WORK/response" && echo "  ok     MCP: vlastní adresa položky" || { echo "  CHYBA  MCP adresa položky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}}]}]}}' > "$WORK/response"
PREVIEW=$(php -r '$o = json_decode(file_get_contents($argv[1]), true); echo json_decode($o["result"]["content"][0]["text"] ?? "{}", true)["nahled"] ?? "";' "$WORK/response")
[ -n "$PREVIEW" ] && curl -s "$PREVIEW" | contains 'Profil: ' && echo "  ok     MCP: šablona detailu kolekce jako koncept s podepsaným náhledem" || { echo "  CHYBA  MCP šablona detailu kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s "$B/tym/zdenek" | contains 'Profil: ' && { echo "  CHYBA  koncept šablony kolekce je vidět bez publikování"; ERRORS=$((ERRORS+1)); } || echo "  ok     koncept šablony kolekce návštěvník nevidí"
mcp publikuj_stavbu '{"kolekce":"tym"}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: publikovaná šablona detailu kolekce" 200 /tym/zdenek "Profil: Zdenek Zeman"
check "llms.txt vyjmenuje položky kolekcí s detailem" 200 /llms.txt "/tym/zdenek"
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zuzana Zelena","data":{"funkce":"Jednatelka"},"zobrazit":true}' > /dev/null
mcp stavba_uloz '{"kolekce":"tym","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}},{"typ":"kolekce","obsah":{"kolekce":"tym","filtr_pole":"funkce","filtr_hodnota":"{{funkce}}","bez_aktualni":true},"deti":[{"typ":"nadpis","znacka":"h3","obsah":{"text":"Kolega: {{nazev}}"}}]}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q 'Kolega: Zuzana Zelena' "$WORK/response" && ! grep -q 'Kolega: Jana' "$WORK/response" && ! grep -q 'Kolega: Petr' "$WORK/response" && ! curl -s "$B/tym/petr-svoboda" | contains 'Kolega: Zuzana' \
    && echo "  ok     související položky: filtr podle pole zobrazené položky, bez ní samotné" || { echo "  CHYBA  související položky kolekce"; ERRORS=$((ERRORS+1)); }
# a collection in several languages: an item's translation has the same slug, another language its own item template, breadcrumbs lead to the translation of the hub page
mcp vytvor_stranku '{"titulek":"Náš tým","adresa":"tym","text":"<p>Tým</p>","zobrazit":true}' > /dev/null; IDTYM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'tym'")
mcp vytvor_stranku "{\"titulek\":\"Our team\",\"adresa\":\"team\",\"jazyk\":\"en\",\"preklad_z\":$IDTYM,\"text\":\"<p>Team</p>\",\"zobrazit\":true}" > /dev/null
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman EN","adresa":"zdenek","jazyk":"en","data":{"funkce":"Workshop lead"},"zobrazit":true}' > "$WORK/response"
grep -q 'en\\/tym\\/zdenek\\"' "$WORK/response" && mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Druhy Zdenek","adresa":"zdenek","jazyk":"en"}' | contains 'tym\\/zdenek-2' \
  && echo "  ok     adresa položky je jedinečná v jazyce (překlad smí mít stejnou)" || { echo "  CHYBA  adresa položky v jiném jazyce"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/en/tym/zdenek" | contains 'Profil: Zdenek Zeman EN' && echo "  ok     jazyk bez vlastní šablony použije šablonu výchozího jazyka, koncept je skrytý" || { echo "  CHYBA  šablona detailu jazyka bez publikování"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu '{"kolekce":"tym","jazyk":"en"}' > /dev/null; mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/en/tym/zdenek"
grep -q '<h1>Profile: Zdenek Zeman EN</h1>' "$WORK/response" && grep -q 'href="/en/team">Our team</a>' "$WORK/response" && grep -q 'hreflang="cs" href="[^"]*/tym/zdenek"' "$WORK/response" \
  && curl -s "$B/tym/zdenek" | contains 'Profil: Zdenek Zeman<' && curl -s "$B/tym/zdenek" | contains 'hreflang="en" href="[^"]*/en/tym/zdenek"' \
  && echo "  ok     šablona detailu v jazyce, drobečky přes překlad rozcestníku, hreflang mezi překlady položky" || { echo "  CHYBA  kolekce ve více jazycích"; grep -o '<nav class="ka-drobecky.\{0,300\}' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q 'class="logo"[^>]*><img src="/image/kaleta-logo.svg"' "$WORK/response" && ! grep -q 'src="/en/image/' "$WORK/response" && echo "  ok     logo a obrázky šablony na jazykové verzi bez předpony jazyka" || { echo "  CHYBA  adresa loga s předponou jazyka"; ERRORS=$((ERRORS+1)); }
expect "verze šablony jazyka zvlášť" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'kolekce:$IDK:en'")" 1
mcp seznam_stranek '{}' | contains 'en\\/team' && echo "  ok     MCP: seznam stránek ukazuje adresu s předponou jazyka" || { echo "  CHYBA  MCP adresa stránky jazykové verze"; ERRORS=$((ERRORS+1)); }
check "šablona detailu jazyka v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK&language=en" 'en\/tym\/zdenek'
# translation via MCP: the page as a copy of the original's build, texts by id, the language's header and footer start as a copy of the default one
mcp vytvor_stranku '{"titulek":"Bez originalu","adresa":"bez-originalu","kopie_stavby":true}' | contains 'potřebuje preklad_z' \
  && expect "kopie stavby bez originálu stránku nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'bez-originalu'")" 0 || { echo "  CHYBA  kopie stavby bez preklad_z"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"From HTML\",\"adresa\":\"from-html\",\"jazyk\":\"en\",\"preklad_z\":$IDZ,\"kopie_stavby\":true}" > /dev/null
IDZEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'from-html'")
expect "překlad stránky začíná kopií stavby originálu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT n.stavba_koncept = COALESCE(o.stavba_koncept, o.stavba) FROM ka_stranky n JOIN ka_stranky o ON o.ids = n.preklad_z WHERE n.ids = $IDZEN")" 1
mcp get_build "{\"id\":$IDZEN,\"texts_only\":true}" > "$WORK/response"
grep -q 'texts' "$WORK/response" && grep -q '{{nazev}}' "$WORK/response" && ! grep -q '\\"build\\"' "$WORK/response" && ! grep -q 'kolekce\\":\\"tym' "$WORK/response" \
  && echo "  ok     MCP: jen texty stavby pro překlad (bez struktury a technických polí)" || { echo "  CHYBA  MCP texty stavby"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Klic navic","data":{"funkce":"x","nazev":"Jinak"}}' | contains 'nezname_klice.*nazev' \
  && echo "  ok     MCP: klíč, který kolekce nemá, je ve výsledku" || { echo "  CHYBA  MCP neznámý klíč položky"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku '{"titulek":"Skryta textem","adresa":"skryta-textem","zobrazit":"false"}' > /dev/null
expect "MCP: zobrazit poslané jako text „false“ nechá stránku skrytou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'skryta-textem'")" 0
mcp stavba_nacti '{"cast":"paticka","jazyk":"en"}' > /dev/null
expect "MCP: čtení části, která ještě není, nic nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE typ = 'paticka' AND jazyk = 'en'")" 0
mcp stavba_uprav '{"cast":"paticka","jazyk":"en","operace":[]}' > /dev/null
expect "patička nového jazyka začíná kopií patičky výchozího jazyka" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT e.stavba_koncept = COALESCE(c.stavba_koncept, c.stavba) FROM ka_casti e JOIN ka_casti c ON c.typ = e.typ AND c.jazyk = '' AND c.varianta = '' WHERE e.typ = 'paticka' AND e.jazyk = 'en' AND e.varianta = ''")" 1
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'testtoken123'); INSERT INTO ka_souhlasy (id_souhlasu, cas, kategorie) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '$(site_time)' - INTERVAL 40 MONTH, 'nic'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '$(site_time)', 'nic')"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "úklid maže staré záznamy o souhlasech s cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(LEFT(id_souhlasu, 1) ORDER BY id_souhlasu) FROM ka_souhlasy WHERE id_souhlasu IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')")" "b"
echo 'ALTER TABLE ka_neexistuje ADD COLUMN x INT;' > "$WORK/web/system/sql/migrace/0099-rozbita.sql"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '19' WHERE promenna = 'db_version'"; rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/web/storage/cache/migrace-chyba"
check "nepovedená migrace neshodí web" 200 /
grep -q 'The database migration failed' "$WORK/web/storage/log/chyby.log" && echo "  ok     nepovedená migrace je v protokolu chyb" || { echo "  CHYBA  nepovedená migrace chybí v protokolu"; ERRORS=$((ERRORS+1)); }
check "nepovedená migrace nezamkne administraci" 200 "/admin.php" "Aktualizace databáze se nepovedla"
rm -f "$WORK/web/system/sql/migrace/0099-rozbita.sql"; sed -i.bak "/The database migration failed/d" "$WORK/web/storage/log/chyby.log"; rm -f "$WORK/web/storage/log/chyby.log.bak"
expect "migrace, které prošly, zůstanou provedené" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "$LAST_MIGRATION"
mcp uprav_kolekci '{"kolekce":"tym","nazev":"Nas tym"}' > "$WORK/response"
grep -q 'Nas tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: úprava kolekce ponechá pole" || { echo "  CHYBA  MCP uprav_kolekci"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: nová položka je ve výpisu" 200 /z-html "Mistr truhlář"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyp1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\",\"pocet\":1,\"razeni\":\"nazev\",\"filtr_pole\":\"funkce\",\"filtry\":true,\"strankovani\":true},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}}]}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
[ "$(grep -o '<h3>[^<]*</h3>' "$WORK/response" | tr -d '\n')" = "<h3>Jana Nováková</h3>" ] && grep -q 'href="/z-html?s-vyp1=2"' "$WORK/response" && grep -q 'href="/z-html" aria-current="true">Vše' "$WORK/response" && grep -q 'f-vyp1=Mistr' "$WORK/response" \
  && echo "  ok     výpis kolekce: řazení, stránkování a tlačítka filtru" || { echo "  CHYBA  stránkování výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "výpis kolekce: druhá strana" 200 "/z-html?s-vyp1=2" "<h3>Petr Svoboda</h3>"
curl -s -o "$WORK/response" "$B/z-html?f-vyp1=Mistr+truhl%C3%A1%C5%99"; grep -q '<h3>Petr Svoboda</h3>' "$WORK/response" && ! grep -q '<h3>Jana' "$WORK/response" && grep -q 'aria-current="true">Mistr truhlář' "$WORK/response" \
  && echo "  ok     výpis kolekce: filtr návštěvníka" || { echo "  CHYBA  filtr výpisu kolekce"; ERRORS=$((ERRORS+1)); }

echo "== komponenty"
check "komponenty" 200 "/admin.php?module=components" "Komponenty"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=components&action=save" -d "_csrf=$TOKEN" -d idm=0 --data-urlencode "nazev=Karta služby" \
  --data-urlencode "vlastnosti[0][popisek]=Nadpis" -d "vlastnosti[0][typ]=text" --data-urlencode "vlastnosti[0][vychozi]=Výchozí nadpis" --data-urlencode "vlastnosti[1][popisek]=Odkaz" -d "vlastnosti[1][typ]=odkaz" -d "vlastnosti[1][vychozi]=/kontakt"
IDM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty ORDER BY idm DESC LIMIT 1")
component_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=$1&id=$IDM" -d "_csrf=$TOKEN" "${@:2}"; }
check "komponenta v builderu" 200 "/admin.php?module=components&action=builder&id=$IDM" 'id="stavitel-data"'
check "2.4: builder links to its guide article" 200 "/admin.php?module=components&action=builder&id=$IDM" '"navod":"https:[^"]*guide[^"]*components"'
check "2.4: settings tab links to its guide article" 200 "/admin.php?module=settings&tab=backups" 'class="navod-odkaz" href="https://kaletacms.com/[a-z/]*guide/backups-updates"'
check "2.4: dashboard links to the guide" 200 "/admin.php" 'guide/first-steps#the-dashboard'
component_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kna1","typ":"nadpis","znacka":"h3","obsah":{"text":"{{nadpis}}"},"styl":{"zaklad":{"barva":"primarni"}}},{"typ":"tlacitko","obsah":{"text":"Více","odkaz":"{{odkaz}}"}},{"typ":"komponenta","obsah":{"komponenta":"'"$IDM"'"}}]}]}' > /dev/null
expect "publikování komponenty" "$(component_action build_publish)" 200
check "náhled komponenty pro editor" 200 "/_komponenta/$IDM?build=koncept&editor=1" "Výchozí nadpis"
check "3.7: the component preview at /_component" 200 "/_component/$IDM?build=koncept&editor=1" "Výchozí nadpis"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\",\"hodnoty\":{\"nadpis\":\"První <b>karta</b>\",\"odkaz\":\"javascript:alert(1)\"}}},{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\"}}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" -w '' "$B/z-html"
grep -q '<h3 class="s-kna1">První karta</h3>' "$WORK/response" && grep -q '<h3 class="s-kna1">Výchozí nadpis</h3>' "$WORK/response" && [ "$(grep -o 'href="/kontakt"' "$WORK/response" | wc -l | tr -d ' ')" -ge 1 ] && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     komponenta na stránce: vlastní i výchozí hodnoty, bez značek, nebezpečný odkaz pryč" || { echo "  CHYBA  komponenta na stránce"; ERRORS=$((ERRORS+1)); }
! grep -q 'id="s-kna1"' "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && [ "$(grep -o '\.s-kna1 {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     komponenta dvakrát na stránce: styl jednou, bez duplicitního id" || { echo "  CHYBA  styl komponenty"; ERRORS=$((ERRORS+1)); }
check "komponenty ukazují počet použití" 200 "/admin.php?module=components" "1×"
grep -q 'data-potvrdit="Komponentu „Karta služby“ používá: stránka „' "$WORK/response" && echo "  ok     potvrzení smazání komponenty vyjmenuje, kde je použitá" || { echo "  CHYBA  potvrzení smazání komponenty"; ERRORS=$((ERRORS+1)); }
# a form inside a component: the submit must find it (it used to be searched only in the page build)
component_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kfo1","typ":"formular","obsah":{"nazev":"Poptávka z komponenty"}}]}]}' > /dev/null; component_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/z-html"
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$(field_value zdroj)" -d prvek=kfo1 -d zpet=/z-html -d "as_cas=$(field_value as_cas)" -d "as_podpis=$(field_value as_podpis)")
case "$location" in *"form=kfo1"*) echo "  ok     formulář v komponentě se odešle";; *) echo "  CHYBA  formulář v komponentě: $location"; ERRORS=$((ERRORS+1));; esac
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=from_element" -d "_csrf=$TOKEN" --data-urlencode "nazev=Výzva" --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Zavolejte nám"}}]}')
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && echo "  ok     uložení prvku jako komponenty" || { echo "  CHYBA  z_prvku: $code"; ERRORS=$((ERRORS+1)); }

check "náhled hotové sekce pro panel builderu" 200 /_sekce/cenik "Vyberte si balíček"
check "3.7: the section preview at /_section" 200 /_section/cenik "Vyberte si balíček"
expect "náhled sekce jen pro přihlášené" "$(curl -s -o /dev/null -w '%{http_code}' "$B/_sekce/cenik")" 404

echo "== varianty záhlaví"
check "formulář varianty" 200 "/admin.php?module=parts&action=variant&type=hlavicka&language=" 'Název varianty'
TOKEN=$(csrf)
location=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=parts&action=save_variant&type=hlavicka&language=" -d "_csrf=$TOKEN" --data-urlencode "nazev=Landing page" -d "stranky[]=$IDZ")
case "$location" in *"variant=landing-page"*) echo "  ok     varianta založena a otevřena v builderu";; *) echo "  CHYBA  založení varianty: $location"; ERRORS=$((ERRORS+1));; esac
variant_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&type=hlavicka&language=&variant=landing-page" -d "_csrf=$TOKEN" "${@:2}"; }
variant_action build_save --data-urlencode 'stavba={"v":1,"deti":[]}' > /dev/null
expect "publikování varianty" "$(variant_action build_publish)" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; ! grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     stránka s prázdnou variantou je bez záhlaví" || { echo "  CHYBA  varianta záhlaví na stránce"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     ostatní stránky mají výchozí záhlaví" || { echo "  CHYBA  varianta se projevila i jinde"; ERRORS=$((ERRORS+1)); }
check "varianta v seznamu částí" 200 "/admin.php?module=parts" "Landing page"

echo "== Claude (MCP): varianty, verze, stránky a poptávky jako v administraci"
# a value from an MCP response: mcpv key [key…] (arrays and objects as JSON)
mcp_value() { php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; } echo is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$WORK/response" "$@"; }
mcp seznam_casti '{}' > "$WORK/response"; [[ "$(mcp_value)" == *'"varianta":"landing-page"'* ]] && echo "  ok     MCP: seznam částí webu s variantami" || { echo "  CHYBA  MCP seznam_casti"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"nazev\":\"Kampaň\",\"stranky\":[$IDZ]}" > "$WORK/response"; VARIANT=$(mcp_value varianta)
expect "MCP: varianta patičky založena" "$VARIANT|$(mcp_value stranky)" "kampan|[$IDZ]"
mcp stavba_uloz "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"znacka\":\"footer\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"p\",\"obsah\":{\"text\":\"Paticka kampane\"}}]}]}}" > "$WORK/response"
VARIANT_PREVIEW=$(mcp_value nahled); curl -s -o "$WORK/response" "$VARIANT_PREVIEW"
[[ "$VARIANT_PREVIEW" == *"variant=$VARIANT"* ]] && grep -q 'Paticka kampane' "$WORK/response" && echo "  ok     MCP: podepsaný náhled konceptu varianty" || { echo "  CHYBA  náhled varianty: $VARIANT_PREVIEW"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\"}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; grep -q 'Paticka kampane' "$WORK/response" && ! curl -s "$B/kontakt" | contains 'Paticka kampane' && echo "  ok     MCP: publikovaná varianta patičky jen na vybrané stránce" || { echo "  CHYBA  varianta patičky z MCP"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"smazat\":true}" > /dev/null
expect "MCP: varianta smazána" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE varianta = '$VARIANT'")" 0
mcp stavba_z_html '{"titulek":"Verze test","html":"<section><h1>Verze A</h1></section>","publikovat":true}' > /dev/null
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'verze-test'")
mcp stavba_z_html "{\"id\":$IDV,\"html\":\"<section><h1>Verze B</h1></section>\",\"publikovat\":true}" > /dev/null
mcp stavba_verze "{\"id\":$IDV}" > "$WORK/response"; IDR=$(mcp_value verze 0 idr)
mcp obnov_verzi "{\"id\":$IDV,\"idr\":$IDR}" > /dev/null
expect "MCP: starší verze v konceptu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba_koncept LIKE '%Verze A%', stavba LIKE '%Verze B%') FROM ka_stranky WHERE ids = $IDV")" 11
mcp zahod_koncept "{\"id\":$IDV}" > /dev/null
expect "MCP: koncept zahozen" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept IS NULL FROM ka_stranky WHERE ids = $IDV")" 1
mcp vytvor_stranku "{\"titulek\":\"Podstranka MCP\",\"nadrazena\":$IDS,\"zverejnit_od\":\"2099-01-01 10:00\"}" > "$WORK/response"
expect "MCP: podstránka s plánovaným zveřejněním" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(seo_link, '|', zverejnit_od, '|', zobrazit) FROM ka_stranky WHERE titulek = 'Podstranka MCP'")" "o-nas/podstranka-mcp|2099-01-01 10:00:00|0"
mcp seznam_poptavek '{"stav":"vse"}' > "$WORK/response"
expect "MCP: poptávky s kampaní" "$(mcp_value 0 email)|$(mcp_value 0 kampan)" "jana@example.cz|newsletter / email / jaro"

echo "== Claude (MCP): trash, deleting and the rest of the admin (1.6)"
sq() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
# the changes a Claude connection made in the last hour, counted like Core\Guardrails (a batch or an import step by its "<n> rows")
claude_used() { sq "SELECT COALESCE(SUM(CASE WHEN akce IN ('save_redirects','save_collection_items','importuj_web','import_wordpress') AND popis REGEXP '^[0-9]+ rows' THEN CAST(SUBSTRING_INDEX(popis, ' ', 1) AS UNSIGNED) ELSE 1 END), 0) FROM ka_protokol WHERE modul = 'claude' AND via = '$1' AND cas > '$(site_time '-1 hour')'"; }
echo "== 3.6: header and footer variants by kind of content (INV-9)"
# a variant for news items and pages under "O nás", sorted first by key; a variant listing a page always wins over it
mcp vytvor_stranku "{\"titulek\":\"Pod onas\",\"nadrazena\":$IDS,\"zobrazit\":true,\"text\":\"<p>Podstranka</p>\"}" > /dev/null
CHILD=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas/pod-onas'")
mcp save_part_variant '{"part":"footer","name":"A pravidla","news_items":true}' > "$WORK/response"
expect "3.6 MCP: a footer variant for news items (English names)" "$(mcp_value variant)|$(mcp_value news_items)|$(mcp_value news_list)|$(mcp_value under_pages)" "a-pravidla|1||[]"
mcp save_part_variant "{\"part\":\"footer\",\"variant\":\"a-pravidla\",\"name\":\"A pravidla\",\"under_pages\":[$IDS]}" > "$WORK/response"
expect "3.6 MCP: rules not sent stay (news_items), under_pages is added" "$(mcp_value news_items)|$(mcp_value under_pages)" "1|[$IDS]"
mcp uloz_variantu "{\"cast\":\"paticka\",\"nazev\":\"Z stranka\",\"stranky\":[$CHILD]}" > /dev/null
for v in a-pravidla:Paticka-pravidel z-stranka:Paticka-stranky; do
  mcp stavba_uloz "{\"cast\":\"paticka\",\"varianta\":\"${v%%:*}\",\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"znacka\":\"footer\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"p\",\"obsah\":{\"text\":\"${v##*:}\"}}]}]}}" > /dev/null
  mcp publikuj_stavbu "{\"cast\":\"paticka\",\"varianta\":\"${v%%:*}\"}" > /dev/null
done
rm -f "$WORK"/web/storage/cache/stranky/*.html
footer_on() { curl -s "$B$1" | grep -o 'Paticka-[a-z]*' | head -1 || true; }
expect "3.6: news item → rule variant, page under the parent → its own page variant wins, the parent and other pages → default" \
  "$(footer_on /novinky/vitejte-v-kalete)|$(footer_on /o-nas/pod-onas)|$(footer_on /o-nas)|$(footer_on /kontakt)|$(footer_on /novinky)" "Paticka-pravidel|Paticka-stranky|||"
mcp uloz_variantu "{\"cast\":\"paticka\",\"varianta\":\"z-stranka\",\"smazat\":true}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.6: without the page variant the page under the parent takes the rule variant" "$(footer_on /o-nas/pod-onas)" "Paticka-pravidel"
mcp seznam_casti '{}' > "$WORK/response"
[[ "$(mcp_value)" == *'"varianta":"a-pravidla"'*'"novinky":true'*"\"nadrazene\":[$IDS]"* ]] && echo "  ok     3.6 MCP: list_site_parts (Czech alias) shows the rules" || { echo "  CHYBA  3.6 seznam_casti rules: $(mcp_value)"; ERRORS=$((ERRORS+1)); }
check "3.6: the variant form offers kinds of content" 200 "/admin.php?module=parts&action=variant&type=paticka&language=&variant=a-pravidla" 'name="nadrazene\[\]"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=parts&action=save_variant&type=paticka&language=" -d "_csrf=$TOKEN" -d varianta=a-pravidla --data-urlencode "nazev=A pravidla" -d vypis=1 -d "kolekce[]=reference" -d "kolekce[]=Spatna adresa!"
expect "3.6 admin: rules saved from the form (news list, a collection; a bad slug dropped; unticked news items off)" "$(sq "SELECT pravidla FROM ka_casti WHERE varianta = 'a-pravidla'")" '{"novinky":false,"vypis":true,"kolekce":["reference"],"nadrazene":[]}'
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.6: the news list takes the variant now, a news item no longer" "$(footer_on /novinky)|$(footer_on /novinky/vitejte-v-kalete)" "Paticka-pravidel|"
mcp uloz_variantu '{"cast":"paticka","varianta":"a-pravidla","smazat":true}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== 3.6: pattern redirects, gone (410) and many redirects at once (INV-10)"
mcp save_redirects '{"redirects":[{"from":"/stary-blog/*","to":"/novinky/*"},{"from":"/stary-blog/zvlastni","to":"/z-html"},{"from":"/old/*","to":"/*"},{"from":"/ven/*","to":"https://example.org/*","code":302},{"from":"/spam/*","to":"","code":410},{"from":"/spam-jedna","code":410},{"from":"/kruh/*","to":"/kruh/x/*"},{"from":"/zly/*","to":"https://*.evil.example/"},{"from":"/stary-blog/*","to":"/jinam/*"}]}' > "$WORK/response"
expect "3.6 MCP save_redirects: per-row results (loop, a host from the visitor and a duplicate refused)" \
  "$(mcp_value added)|$(mcp_value refused)|$(mcp_value results 6 status)|$(mcp_value results 7 status)|$(mcp_value results 8 reason)" \
  "6|3|refused|refused|The same old address is in an earlier row – the first one counts."
rcode() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B$1"; }
expect "3.6: a pattern keeps the rest of the path, an exact redirect wins over it" "$(rcode /stary-blog/vitejte-v-kalete)|$(rcode /stary-blog/zvlastni)|$(rcode /old/kontakt)" \
  "301 $B/novinky/vitejte-v-kalete|301 $B/z-html|301 $B/kontakt"
expect "3.6: an absolute target the administrator saved keeps its host, the rest of the path follows" "$(rcode /ven/cenik/2026)" "302 https://example.org/cenik/2026"
# what a visitor types never decides the host: // and encoded slashes give empty segments, so no redirect at all (a backslash or
# a dot segment the server refuses before the site; the unit tests cover them), other characters are encoded into the path
rto() { curl -s -o /dev/null -w '%{redirect_url}' "$B$1"; }
expect "3.6: a pattern never forms an off-site redirect from visitor input" "$(rto '/old//evil.example')|$(rto '/old/%2F%2Fevil.example')|$(rto '/old/https:%2F%2Fevil.example')|$(rto '/ven/%2F%2Fevil.example')" "|||"
location=$(curl -s -o /dev/null -D - "$B/old/%40evil.example" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p')
[[ "$location" == /* && "$location" != //* && "$location" == *%40evil.example ]] && echo "  ok     3.6: visitor input stays an encoded path on this site ($location)" || { echo "  CHYBA  3.6 Location for visitor input: $location"; ERRORS=$((ERRORS+1)); }
expect "3.6: gone (410) for a pattern and an exact address, with the not-found page" "$(rcode /spam/produkty/levne)|$(rcode /spam-jedna)|$(curl -s -o "$WORK/gone.html" "$B/spam/x"; grep -q 'noindex' "$WORK/gone.html" && echo 1 || echo 0)" "410 |410 |1"
expect "3.6: refused rules were not saved" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy IN ('kruh/*', 'zly/*') OR na_adresu LIKE 'jinam%'")" 0
mcp save_redirects '{"redirects":[{"from":"/nanecisto/*","to":"/z-html"}],"dry_run":true}' > "$WORK/response"
expect "3.6 MCP save_redirects: dry_run says what would happen and saves nothing" "$(mcp_value dry_run)|$(mcp_value added)|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'nanecisto/*'")" "1|1|0"
# the admin form: a pattern, a refused loop and a 410 without a target
check "3.6: the redirects screen offers the CSV import and 410" 200 "/admin.php?module=redirects" 'name="soubor"'
TOKEN=$(csrf)
redirect_form() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" "$@"; }
redirect_form -d z_adresy=/katalog/* -d na_adresu=/z-html -d typ=301
redirect_form -d z_adresy=/novinky-old/* -d na_adresu=/stary-blog/* -d typ=301
redirect_form -d z_adresy=/smazano -d na_adresu= -d typ=410
expect "3.6 admin: a pattern and a 410 saved, a rule closing a loop refused" "$(rcode /katalog/stoly)|$(rcode /smazano)|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'novinky-old/*'")" "301 $B/z-html|410 |1"
redirect_form -d z_adresy=/novinky/x-stary -d na_adresu=/stary-blog/x-stary -d typ=301
expect "3.6 admin: an exact rule into a pattern that leads back is refused" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'novinky/x-stary'")|$(rcode /stary-blog/vitejte-v-kalete)" "0|301 $B/novinky/vitejte-v-kalete"
# CSV in the admin: the preview changes nothing, saving adds; the Redirection plugin export with a simple regular expression
printf 'source,target,regex,code,type,hits,title,status\n/csv-stara,/kontakt,0,301,url,0,,enabled\n"^/csv-blog/(.*)$",/novinky/$1,1,302,url,0,,enabled\n/csv-gone,,0,410,url,0,,enabled\n/csv-off,/kontakt,0,301,url,0,,disabled\n/csv-stara,/z-html,0,301,url,0,,enabled\n' > "$WORK/redirects.csv"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=redirects&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/redirects.csv;type=text/csv"
contains -q 'id="nahled-importu"' "$WORK/response" && contains -q 'Řádků: 5' "$WORK/response" && contains -q 'Pravidlo je v souboru vypnuté' "$WORK/response" \
  && [ "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'csv-%'")" = 0 ] && echo "  ok     3.6 CSV: the preview lists every row and saves nothing" || { echo "  CHYBA  3.6 CSV preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=import_save" -d "_csrf=$TOKEN" --data-urlencode "csv@$WORK/redirects.csv"
expect "3.6 CSV: saved – exact, the converted pattern (302) and a 410; the duplicate and the disabled row not" \
  "$(rcode /csv-stara)|$(rcode /csv-blog/vitejte-v-kalete)|$(rcode /csv-gone)|$(rcode /csv-off)" "301 $B/kontakt|302 $B/novinky/vitejte-v-kalete|410 |404 "
# sizes the moved sites need: 500 rows in one MCP call, 5,000 rows in one CSV, and the site still answers fast
php -r '$r = []; for ($i = 1; $i <= 500; $i++) { $r[] = ["from" => "/hromadne/stranka-$i", "to" => "/kontakt"]; } echo json_encode(["redirects" => $r]);' > "$WORK/batch.json"
mcp save_redirects "$(cat "$WORK/batch.json")" > "$WORK/response"
expect "3.6 MCP save_redirects: 500 rows in one call" "$(mcp_value added)|$(mcp_value refused)" "500|0"
php -r 'echo "from,to,code\n"; for ($i = 1; $i <= 5000; $i++) { echo "/velky-import/clanek-$i,/novinky/vitejte-v-kalete,301\n"; }' > "$WORK/big.csv"
started=$(php -r 'echo microtime(true);')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=import_save" -d "_csrf=$TOKEN" --data-urlencode "csv@$WORK/big.csv"
echo "  info   3.6 CSV: 5,000 rows saved in $(php -r 'printf("%.1f", microtime(true) - (float) $argv[1]);' "$started") s"
expect "3.6 CSV: 5,000 rows in one import" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'velky-import/%'")" 5000
expect "3.6: with thousands of rules the exact one and a pattern still answer" "$(rcode /velky-import/clanek-4321)|$(rcode /stary-blog/vitejte-v-kalete)" "301 $B/novinky/vitejte-v-kalete|301 $B/novinky/vitejte-v-kalete"
sq "DELETE FROM ka_presmerovani WHERE z_adresy LIKE 'velky-import/%' OR z_adresy LIKE 'hromadne/%' OR z_adresy IN ('old/*', 'katalog/*', 'ven/*')"

echo "== Claude (MCP): trash, deleting and the rest of the admin (continued)"
# 3.5 (UXA-02) over MCP: create_page with visible true and nothing to show waits hidden; the first published build shows it –
# the same end state as before for create_page → save_build(publish), without an empty page on the site in between
mcp create_page '{"title":"Prázdná MCP","slug":"prazdna-mcp","visible":true,"in_menu":true}' > "$WORK/response"
php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"
MCP_EMPTY=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'prazdna-mcp'")
contains -q 'hidden until it has content' "$WORK/text" && expect "3.5 MCP: an empty visible page waits hidden, the wish is kept" "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', v_menu) FROM ka_stranky WHERE ids = ${MCP_EMPTY:-0}")" "0/1/1" \
  || { echo "  CHYBA  3.5 MCP create_page status"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "3.5 MCP: visitors get a 404 before the build is published" "$(curl -s -o /dev/null -w '%{http_code}' "$B/prazdna-mcp")" 404
mcp save_build "{\"id\":${MCP_EMPTY:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Prázdná MCP\"}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.5 MCP: save_build with publish shows the page as asked" "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish) FROM ka_stranky WHERE ids = ${MCP_EMPTY:-0}")|$(curl -s -o /dev/null -w '%{http_code}' "$B/prazdna-mcp")" "1/0|200"
mcp create_page '{"title":"Prázdná MCP text","slug":"prazdna-mcp-text","visible":true}' > /dev/null
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'prazdna-mcp-text'"),\"text\":\"<p>Teď s textem</p>\"}" > /dev/null
expect "3.5 MCP: a waiting page is shown once update_page gives it text; with text at once as before" "$(sq "SELECT GROUP_CONCAT(zobrazit ORDER BY ids) FROM ka_stranky WHERE seo_link IN ('prazdna-mcp-text')")" "1"
# N35-1: a publish date takes over from "show with the first content" – neither text nor a published build shows the page early
mcp create_page '{"title":"Embargo MCP","slug":"embargo-mcp","visible":true}' > /dev/null
MCP_EMB=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'embargo-mcp'")
mcp update_page "{\"id\":${MCP_EMB:-0},\"publish_at\":\"$(site_time '+2 days' 'Y-m-d H:i')\"}" > /dev/null
mcp update_page "{\"id\":${MCP_EMB:-0},\"text\":\"<p>Embargoed news</p>\"}" > /dev/null
EMB_TEXT="$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE ids = ${MCP_EMB:-0}")"
mcp save_build "{\"id\":${MCP_EMB:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Embargoed news\"}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.5 N35-1: a scheduled waiting page stays hidden with its schedule after text and after a published build" \
  "$EMB_TEXT|$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE ids = ${MCP_EMB:-0}")|$(curl -s -o /dev/null -w '%{http_code}' "$B/embargo-mcp")" "0/0/1|0/0/1|404"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_pages"]["readOnlyHint"] === true && $t["trash_page"]["destructiveHint"] === true && $t["create_page"]["readOnlyHint"] === false && $t["delete_collection"]["destructiveHint"] === true ? 0 : 1);' "$WORK/response" \
  && echo "  ok     MCP: tools carry annotations (read-only, destructive)" || { echo "  CHYBA  MCP annotations"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists extensions and languages" "$(mcp_value extensions | grep -c novinky)|$(mcp_value languages default)" "1|cs"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema in the English vocabulary" "$(mcp_value elements heading | grep -c 'content: text')|$(mcp_value style gap | grep -c gap)|$(mcp_value states 2)" "1|1|mobile"
mcp builder_schema '{"elements":["form"]}' > "$WORK/response"
expect "MCP: full definition of an element by its English type" "$(mcp_value elements 0 type)|$(mcp_value elements 0 fields fields item_fields type options 5)" "form|radio"
VPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL ORDER BY ids LIMIT 1")
mcp save_build "{\"id\":$VPAGE,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Go\",\"variant\":\"outline\",\"icon\":\"arrow\"},\"style\":{\"mobile\":{\"gap\":\"s\",\"background\":\"primary-soft\"}}}]}]}}" > /dev/null
expect "MCP: an English build is stored in the Czech keys" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].typ')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.varianta')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.mobil.pozadi')) FROM ka_stranky WHERE ids = $VPAGE" | tr '\t' '|')" "tlacitko|obrys|primarni-jemna"
mcp get_build "{\"id\":$VPAGE}" > "$WORK/response"
expect "MCP: get_build answers in English" "$(mcp_value build children 0 children 0 type)|$(mcp_value build children 0 children 0 content variant)|$(mcp_value build children 0 children 0 style mobile background)" "button|outline|primary-soft"
BUTTON=$(mcp_value build children 0 children 0 id)
mcp edit_build "{\"id\":$VPAGE,\"operations\":[{\"op\":\"update\",\"id\":\"$BUTTON\",\"content\":{\"new_window\":true},\"style\":{\"base\":{\"radius\":\"full\"}}}]}" > /dev/null
expect "MCP: edit_build takes English content and style" "$(sq "SELECT CONCAT(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.nove_okno'), '|', JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.zaklad.zaobleni'))) FROM ka_stranky WHERE ids = $VPAGE")" "true|plne"
mcp discard_draft "{\"id\":$VPAGE}" > /dev/null
mcp create_collection '{"name":"Kos test","fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"kos-test","name":"Polozka","visible":true}' > "$WORK/response"; ITEM=$(mcp_value id)
mcp delete_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM}" > /dev/null
expect "MCP: a collection item goes to the trash, hidden" "$(sq "SELECT CONCAT(smazano IS NOT NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
check "collection trash in the admin" 200 "/admin.php?module=collections&action=items&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'kos-test'")&status=kos" "Polozka"
mcp list_trash '{}' > "$WORK/response"
expect "MCP: list_trash shows the item" "$(mcp_value collection_items 0 name)" "Polozka"
mcp save_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM,\"visible\":true}" | contains 'is in the trash' && [ "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $ITEM")" = 0 ] \
  && echo "  ok     MCP: an item in the trash cannot be published by saving it (1.9)" || { echo "  CHYBA  MCP saved an item from the trash"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items '{"collection":"kos-test"}' | contains 'Polozka' && { echo "  CHYBA  list_collection_items lists the trash"; ERRORS=$((ERRORS+1)); } || echo "  ok     list_collection_items leaves the trash out"
mcp restore_from_trash "{\"type\":\"collection_item\",\"id\":$ITEM}" > /dev/null
expect "MCP: restored from the trash as hidden" "$(sq "SELECT CONCAT(smazano IS NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
mcp delete_collection '{"collection":"kos-test"}' > /dev/null
expect "MCP: delete_collection removes it with its items" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'kos-test'")|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "0|0"
CATEGORY=$(sq "SELECT nazev FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1")
mcp create_news "{\"title\":\"Do kose\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Do kose'")
mcp trash_news "{\"id\":$NEWS}" > /dev/null
expect "MCP: trash_news" "$(sq "SELECT smazano IS NOT NULL FROM ka_novinky WHERE idc = $NEWS")" "1"
mcp restore_from_trash "{\"type\":\"news\",\"id\":$NEWS}" > /dev/null
expect "MCP: a news item back from the trash as a draft" "$(sq "SELECT CONCAT(smazano IS NULL, visible) FROM ka_novinky WHERE idc = $NEWS")" "10"
mcp create_category '{"name":"Docasna"}' > /dev/null; CAT=$(sq "SELECT idt FROM ka_kategorie WHERE nazev = 'Docasna'")
mcp update_category "{\"id\":$CAT,\"name\":\"Docasna 2\",\"slug\":\"docasna-2\"}" > /dev/null
expect "MCP: update_category renames and redirects the old address" "$(sq "SELECT CONCAT(nazev, '|', seo_link) FROM ka_kategorie WHERE idt = $CAT")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%kategorie/docasna'")" "Docasna 2|docasna-2|1"
mcp delete_category "{\"id\":$(sq "SELECT tema FROM ka_novinky WHERE idc = $NEWS")}" | contains 'still has news items' && echo "  ok     MCP: a category with news items is not deleted" || { echo "  CHYBA  MCP: delete_category of a used category"; ERRORS=$((ERRORS+1)); }
mcp delete_category "{\"id\":$CAT}" > /dev/null
expect "MCP: delete_category" "$(sq "SELECT COUNT(*) FROM ka_kategorie WHERE idt = $CAT")" "0"
mcp save_component '{"name":"Karta","properties":[{"klic":"titulek","popisek":"Titulek","typ":"text","vychozi":"Ahoj"}]}' > "$WORK/response"; COMP=$(mcp_value id)
mcp save_build "{\"component\":$COMP,\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"{{titulek}}\"}}]}]}}" > /dev/null
mcp publish_build "{\"component\":$COMP}" > /dev/null
expect "MCP: a component built and published through the component target" "$(sq "SELECT stavba LIKE '%{{titulek}}%' AND stavba_koncept IS NULL FROM ka_komponenty WHERE idm = $COMP")" "1"
mcp list_components '{}' > "$WORK/response"
expect "MCP: list_components" "$(mcp_value 0 name)|$(mcp_value 0 published)" "Karta|1"
mcp delete_component "{\"id\":$COMP}" > /dev/null
expect "MCP: delete_component" "$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $COMP")" "0"
PAGE=$(sq "SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND smazano IS NULL ORDER BY ids LIMIT 1")
ELEMENT=$(sq "SELECT COALESCE(stavba_koncept, stavba) FROM ka_stranky WHERE ids = $PAGE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["deti"][0]["id"];')
mcp save_section "{\"id\":$PAGE,\"element\":\"$ELEMENT\",\"name\":\"Moje sekce z MCP\"}" > "$WORK/response"; SECTION=$(mcp_value id)
mcp builder_schema '{}' | contains 'Moje sekce z MCP' && echo "  ok     MCP: saved sections in builder_schema" || { echo "  CHYBA  MCP: saved sections missing in builder_schema"; ERRORS=$((ERRORS+1)); }
BEFORE=$(sq "SELECT JSON_LENGTH(COALESCE(stavba_koncept, stavba), '$.deti') FROM ka_stranky WHERE ids = $PAGE")
mcp insert_section "{\"id\":$PAGE,\"saved_section\":$SECTION}" > /dev/null
expect "MCP: insert_section with a saved section adds it with new ids" "$(sq "SELECT JSON_LENGTH(stavba_koncept, '$.deti') FROM ka_stranky WHERE ids = $PAGE")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, CONCAT('$.deti[', JSON_LENGTH(stavba_koncept, '$.deti') - 1, '].id'))) <> '$ELEMENT' FROM ka_stranky WHERE ids = $PAGE")" "$((BEFORE + 1))|1"
mcp discard_draft "{\"id\":$PAGE}" > /dev/null; mcp delete_section "{\"id\":$SECTION}" > /dev/null
expect "MCP: delete_section" "$(sq "SELECT COUNT(*) FROM ka_sekce WHERE idx = $SECTION")" "0"
mcp save_popup '{"template":"announcement_bar","name":"Na smazani"}' > "$WORK/response"; POPUP=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Na smazani'")
mcp delete_popup "{\"id\":$POPUP}" > /dev/null
expect "MCP: delete_popup" "$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $POPUP")" "0"
PNG=$(php -r 'ob_start(); imagepng(imagecreatetruecolor(8, 8)); echo base64_encode(ob_get_clean());')
mcp upload_file "{\"filename\":\"mcp-smazat.png\",\"data\":\"$PNG\"}" > /dev/null; MEDIA=$(sq "SELECT ido FROM ka_media ORDER BY ido DESC LIMIT 1")
mcp update_media "{\"id\":$MEDIA,\"alt\":\"Cerny ctverec\",\"caption\":\"Popisek\"}" > /dev/null
expect "MCP: update_media" "$(sq "SELECT CONCAT(nazev, '|', popis) FROM ka_media WHERE ido = $MEDIA")" "Cerny ctverec|Popisek"
FILE=$(sq "SELECT obr_poloha FROM ka_media WHERE ido = $MEDIA")
mcp delete_media "{\"id\":$MEDIA}" > /dev/null
expect "MCP: delete_media removes the record and the file" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $MEDIA")|$([ -e "$WORK/web/$FILE" ] && echo file || echo gone)" "0|gone"
USED=$(sq "SELECT ido FROM ka_media m WHERE EXISTS (SELECT 1 FROM ka_stranky s WHERE CONCAT_WS(' ', s.stavba, s.stavba_koncept, s.text) LIKE CONCAT('%', REPLACE(m.obr_poloha, '/', '%'), '%')) LIMIT 1")
[ -z "$USED" ] || { mcp delete_media "{\"id\":$USED}" | contains 'still used on the site' && echo "  ok     MCP: a file in use is not deleted" || { echo "  CHYBA  MCP: delete_media deleted a file in use"; ERRORS=$((ERRORS+1)); }; }
READS=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'"); mcp list_enquiries '{}' > /dev/null
expect "MCP: every enquiry read is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'")" "$((READS + 1))"
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES ('$(site_time)', 'mcp@example.cz', '[]')"; ENQUIRY=$(sq "SELECT MAX(idp) FROM ka_poptavky")
mcp update_enquiry "{\"id\":$ENQUIRY,\"status\":\"resolved\",\"note\":\"Vyrizeno pres Clauda\"}" > /dev/null
expect "MCP: update_enquiry" "$(sq "SELECT CONCAT(stav, '|', poznamka) FROM ka_poptavky WHERE idp = $ENQUIRY")" "2|Vyrizeno pres Clauda"
mcp delete_enquiry "{\"id\":$ENQUIRY}" > /dev/null
expect "MCP: delete_enquiry" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQUIRY")" "0"

echo "== draft look and whole-site preview (1.7)"
mcp discard_look '{}' > /dev/null
OLDPRIMARY=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")
OLDFONT=$(sq "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.pismo_titulky')), 'moderni') FROM ka_nastaveni WHERE promenna = 'design_system'")
OLDRADIUS=$(sq "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.zaobleni')), 'm') FROM ka_nastaveni WHERE promenna = 'design_system'")
NEWFONT=$([ "$OLDFONT" = zaoblene ] && echo strojove || echo zaoblene); NEWRADIUS=$([ "$OLDRADIUS" = l ] && echo s || echo l)
mcp update_design_system "{\"ds\":{\"barvy\":{\"primarni\":\"#123456\"},\"pismo_titulky\":\"$NEWFONT\",\"zaobleni\":\"$NEWRADIUS\"}}" > "$WORK/response"
SITEPREVIEW=$(mcp_value preview)
expect "MCP: the design system goes to the draft look, the site keeps the published one" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.design_system.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$OLDPRIMARY|#123456"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'ka-barva-primarni: #123456' "$WORK/response" && { echo "  CHYBA  the draft look is on the public site"; ERRORS=$((ERRORS+1)); } || echo "  ok     visitors do not see the draft look"
# 3.5 (UXA-03): the preview in Site appearance shows the saved draft look – to the administrator only, never to visitors
curl -s -o "$WORK/response" "$B/?preview=vzhled"; grep -q 'ka-barva-primarni: #123456' "$WORK/response" && { echo "  CHYBA  ?preview=vzhled shows the draft look to a visitor"; ERRORS=$((ERRORS+1)); } || echo "  ok     ?preview=vzhled keeps the published look for visitors"
curl -s -b "$JAR" -o "$WORK/response" "$B/?preview=vzhled"; expect "the Site appearance preview shows the saved draft look to the administrator" "$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")" "1"
check "Site appearance labels the preview as the draft look" 200 "/admin.php?module=appearance" "Náhled: koncept vzhledu"
# 3.5 (UXA-04): the look bar names the options (not the stored keys zaoblene, l) and shows colour swatches
expect "the look bar names the options and shows colour swatches" "$(grep -c "Písmo nadpisů [^<]* → $([ "$NEWFONT" = zaoblene ] && echo Zaoblené || echo 'Psací stroj')" "$WORK/response")|$(grep -c "→ $([ "$NEWRADIUS" = l ] && echo velké || echo jemné)" "$WORK/response")|$(grep -c 'class="vzhled-vzorek" style="background:#123456"' "$WORK/response")|$(grep -c -e "→ $NEWFONT" -e "→ $NEWRADIUS[,<]" "$WORK/response" || true)" "1|1|1|0"
mcp save_classes '{"css":".look-test { padding: 1rem }"}' > /dev/null
mcp save_classes '{"css":".look-test { padding: 2rem }"}' > "$WORK/response"
expect "MCP: a new class is live at once, a change of it waits in the draft" "$(sq "SELECT styl LIKE '%\"odsazeni_y\"%' OR css LIKE '%1rem%' FROM ka_tridy WHERE nazev = 'look-test'")|$(mcp_value look_draft 0)" "1|look-test"
mcp list_classes '{"name":"look-test"}' > "$WORK/response"
expect "MCP: list_classes shows the draft" "$(mcp_value 0 draft)" "1"
sq "DROP TABLE IF EXISTS menu_before; CREATE TABLE menu_before AS SELECT * FROM ka_menu"
mcp save_menu '{"location":"main","items":[{"type":"link","text":"Draft link","url":"/draft-link"}]}' > /dev/null
expect "MCP: save_menu goes to the draft look" "$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni' AND polozky LIKE '%draft-link%'")" "0"
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists the draft look" "$(mcp_value look_draft | grep -c 'look-test')" "1"
expect "MCP: the draft look summary names the options in English" "$(mcp_value look_draft | grep -c "Heading font [A-Za-z -]* → $([ "$NEWFONT" = zaoblene ] && echo Rounded || echo Typewriter)")|$(mcp_value look_draft | grep -c "→ $NEWFONT" || true)" "1|0"
curl -s -c "$WORK/preview.jar" -o "$WORK/response" "$SITEPREVIEW"
expect "the whole-site preview shows the draft look, the draft menu and the bar, and is not indexed" "$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")|$(grep -c 'draft-link' "$WORK/response")|$(grep -c 'ka-nahled-lista' "$WORK/response")|$(grep -c 'noindex' "$WORK/response")" "1|1|1|1"
DRAFTPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL AND zobrazit = 1 AND stavba IS NOT NULL ORDER BY ids LIMIT 1")
DRAFTSLUG=$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $DRAFTPAGE")
mcp edit_build "{\"id\":$DRAFTPAGE,\"operations\":[{\"op\":\"insert\",\"elements\":[{\"type\":\"heading\",\"content\":{\"text\":\"Only in the draft\"}}],\"into\":null,\"position\":0}]}" > /dev/null
curl -s -b "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG"
expect "browsing on in the preview (cookie) shows page drafts too" "$(grep -c 'Only in the draft' "$WORK/response")|$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")" "1|1"
curl -s -o "$WORK/response" "$B/$DRAFTSLUG"; ! grep -q 'Only in the draft' "$WORK/response" && echo "  ok     without the preview the page draft stays hidden" || { echo "  CHYBA  page draft visible without the preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$WORK/preview.jar" -c "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG?preview_end=1"
curl -s -b "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG"; ! grep -q 'Only in the draft' "$WORK/response" && echo "  ok     ending the preview shows the published site again" || { echo "  CHYBA  the preview did not end"; ERRORS=$((ERRORS+1)); }
mcp discard_draft "{\"id\":$DRAFTPAGE}" > /dev/null
check "the admin shows the look bar on every screen" 200 "/admin.php?module=pages" 'id="vzhled-koncept"' # 3.6: one line, its button says Publish
mcp publish_look '{}' > "$WORK/response"
expect "MCP: publish_look publishes everything and keeps the previous look" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")|$(sq "SELECT css LIKE '%2rem%' OR styl LIKE '%2rem%' OR styl LIKE '%\"xl\"%' FROM ka_tridy WHERE nazev = 'look-test'")|$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni' AND polozky LIKE '%draft-link%'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COUNT(*) > 0 FROM ka_look_versions")" "#123456|1|1||1"
expect "publishing the look is in the change log with what changed" "$(sq "SELECT popis LIKE '%#123456%' FROM ka_protokol WHERE akce = 'publish look' ORDER BY idp DESC LIMIT 1")" "1"
# #12 / PR #13: on the Czech site Claude gets the summary in English; the stored version keeps the site's language as before
expect "MCP: publish_look answers in English, the stored version stays in the site language" "$(mcp_value published | grep -c 'Design system: Primary')|$(mcp_value published | grep -c 'Main menu')|$(mcp_value published | grep -c 'Hlavní')|$(sq "SELECT summary LIKE '%Hlavní%' FROM ka_look_versions ORDER BY id DESC LIMIT 1")" "1|1|0|1"
check "3.5: the change log names the publication of the look in words, not by its action key" 200 "/admin.php?module=changelog" "vzhled publikován"
mcp list_look_versions '{}' > "$WORK/response"; VERSION=$(mcp_value versions 0 id)
mcp restore_look_version "{\"id\":$VERSION}" > /dev/null
expect "MCP: an earlier look comes back into the draft" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.design_system.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$OLDPRIMARY"
check "earlier looks in Site appearance" 200 "/admin.php?module=appearance" "Vrátit tento vzhled"
mcp discard_look '{}' > /dev/null
expect "MCP: discard_look" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "|#123456"
mcp update_design_system "{\"ds\":{\"barvy\":{\"primarni\":\"$OLDPRIMARY\"},\"pismo_titulky\":\"$OLDFONT\",\"zaobleni\":\"$OLDRADIUS\"}}" > /dev/null; mcp publish_look '{}' > /dev/null
sq "DELETE FROM ka_menu; INSERT INTO ka_menu SELECT * FROM menu_before; DROP TABLE menu_before"; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== ready-made templates of site parts (1.7)"
check "templates of the header" 200 "/admin.php?module=parts&action=templates&type=hlavicka" "Logo uprostřed"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema lists the part templates" "$(mcp_value part_templates header na-stred | grep -c 'Centred logo')|$(mcp_value part_templates footer tiraz | grep -c 'imprint')" "1|1"
PUBLISHEDFOOTER=$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")
mcp apply_part_template '{"part":"footer","template":"kompaktni"}' > "$WORK/response"
expect "MCP: a template goes to the draft, the published footer stays" "$(sq "SELECT stavba_koncept LIKE '%\"udaj\":\"copyright\"%' AND stavba_koncept NOT LIKE '%\"mrizka\"%' FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")|$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")" "1|$PUBLISHEDFOOTER"
curl -s -o "$WORK/footer.html" "$(mcp_value preview)"; grep -q '<footer' "$WORK/footer.html" && echo "  ok     MCP: the part preview shows the template" || { echo "  CHYBA  part template preview"; ERRORS=$((ERRORS+1)); }
mcp apply_part_template '{"part":"footer","template":"nothing"}' | contains 'Unknown template' && echo "  ok     MCP: an unknown template is refused" || { echo "  CHYBA  unknown template accepted"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=parts&action=templates&type=nenalezeno"
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?module=parts&action=apply_template&type=nenalezeno" -d "_csrf=$(csrf)" -d sablona=s-hledanim)
case "$code" in "302 "*"module=parts&action=builder&type=nenalezeno"*) echo "  ok     admin: a template opens in the builder";; *) echo "  CHYBA  admin apply template: $code"; ERRORS=$((ERRORS+1));; esac
expect "admin: the 404 wrapper got the search template as a draft" "$(sq "SELECT stavba_koncept LIKE '%\"typ\":\"hledani\"%' FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = ''")" "1"
mcp discard_draft '{"part":"footer"}' > /dev/null; sq "DELETE FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = '' AND stavba IS NULL"

echo "== pop-up okna"
check "pop-up okna v administraci" 200 "/admin.php?module=popups" "Zatím žádná pop-up okna"
check "nové okno ze vzoru" 200 "/admin.php?module=popups&action=new" 'name="vzor" value="newsletter"'
mcp uloz_popup '{"vzor":"prazdny","nazev":"Akce okno"}' > "$WORK/response"; IDPP=$(mcp_value id)
expect "MCP: okno založené vypnuté a nepublikované" "$(mcp_value adresa)|$(mcp_value aktivni)|$(mcp_value publikovano)|$(mcp_value spoustec)" "akce-okno|||klik"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":true}" | contains 'nejdřív publikuj' && echo "  ok     MCP: nepublikované okno nejde zapnout" || { echo "  CHYBA  zapnutí nepublikovaného okna"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz "{\"popup\":$IDPP,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h2\",\"obsah\":{\"text\":\"Okno akce\"}},{\"id\":\"ab12cd3\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Z okna\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}}" > "$WORK/response"
mcp save_popup "{\"id\":$IDPP,\"type\":\"slide_in\",\"trigger\":\"time\",\"value\":3,\"frequency\":\"until_closed\",\"rules\":{\"device\":\"phone\"},\"active\":true}" > "$WORK/response"
expect "MCP anglicky: typ, spouštěč, četnost a pravidla" "$(mcp_value type)|$(mcp_value trigger)|$(mcp_value frequency)|$(mcp_value rules device)|$(mcp_value active)" "slide_in|time|until_closed|phone|1"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q "data-popup=\"$IDPP\"" "$WORK/response" && grep -q 'class="ka-popup ka-popup--panel" popover="manual" role="region"' "$WORK/response" && grep -q 'data-spoustec="cas" data-hodnota="3" data-cetnost="zavreni"' "$WORK/response" \
  && grep -q 'data-zarizeni="telefon"' "$WORK/response" && grep -q 'Okno akce' "$WORK/response" && grep -q 'image/web\.js' "$WORK/response" \
  && echo "  ok     zapnuté okno na webu se spouštěčem, pravidly prohlížeče a skriptem" || { echo "  CHYBA  pop-up okno na webu"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"kde\":\"vybrane\",\"stranky\":[$IDS]}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/" | contains "data-popup=\"$IDPP\"" && curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno jen na vybrané stránce" || { echo "  CHYBA  pravidlo vybraných stránek"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"2099-01-01\"}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno mimo období se do stránky nevloží" || { echo "  CHYBA  období okna"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"\",\"kde\":\"vse\"}}" > "$WORK/response"; POPUP_PREVIEW=$(mcp_value nahled); rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=zobrazeni; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=konverze; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=nic
expect "počitadla okna bez cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $IDPP")" "1/0/1"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/_popup/$IDPP?build=koncept"); expect "koncept okna bez přihlášení není" "$code" 404
curl -s -o "$WORK/response" "$POPUP_PREVIEW"; grep -q 'data-otevrit="1"' "$WORK/response" && grep -q 'noindex' "$WORK/response" && echo "  ok     podepsaný náhled okno rovnou otevře" || { echo "  CHYBA  náhled okna: $POPUP_PREVIEW"; ERRORS=$((ERRORS+1)); }
check "okno v builderu" 200 "/admin.php?module=popups&action=builder&id=$IDPP" 'id="stavitel-data"'
check "plátno okna v builderu" 200 "/_popup/$IDPP?build=koncept&editor=1" 'ka-popup--editor'
curl -s -b "$JAR" "$B/o-nas" | contains "data-popup=\"$IDPP\"" && ! curl -s -b "$JAR" "$B/o-nas?build=koncept&editor=1" | contains "data-popup=" \
  && echo "  ok     plátno builderu stránky je bez pop-up oken webu" || { echo "  CHYBA  pop-up okno v plátně builderu"; ERRORS=$((ERRORS+1)); }
curl -s "$B/" | sed -n '/data-popup=/,$p' > "$WORK/formular.html" # jen okno – stránka může mít vlastní formulář
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
expect "formulář v okně má zdroj okna" "$FORM_SOURCE|$FORM_ELEMENT" "popup:$IDPP|ab12cd3"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/ -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p0=okno@example.cz)
case "$location" in *"result=ok"*) echo "  ok     formulář v okně odeslán";; *) echo "  CHYBA  formulář v okně: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka z okna uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(formular, '|', zdroj) FROM ka_poptavky WHERE email = 'okno@example.cz'")" "Z okna|popup:$IDPP"
mcp seznam_popupu '{}' > "$WORK/response"; expect "MCP: seznam oken s počitadly" "$(mcp_value 0 nazev)|$(mcp_value 0 zobrazeni)|$(mcp_value 0 konverze)" "Akce okno|1|1"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":false}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html # další testy počítají se stránkami bez okna

# editing right on the site: the link and the form only for signed-in users with the permission
check "úprava na místě – odkaz" 200 /novinky/vitejte-v-kalete "ka-upravit-zde"
check "úprava na místě – formulář" 200 "/novinky/vitejte-v-kalete?edit=text" "ka-upravit-text"
check "úprava stránky na místě" 200 "/o-nas?edit=text" "ka-upravit-text"
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete?edit=text"; grep -q "ka-upravit" "$WORK/response" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; ERRORS=$((ERRORS+1)); } || echo "  ok     úprava na místě bez přihlášení není"

echo "== import z WordPressu a export"
check "import a export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
wp_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=progress&file=wordpress-sample.xml" -d "_csrf=$TOKEN"; }
wp_import() { # náhled (čtení souboru) → volby → import; ukázkový soubor se vejde do jedné dávky
  wp_batch
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d soubor=wordpress-sample.xml -d koncepty=1 -d stranky=1 -d stavitel=1 -d presmerovani=1 -d rubrika=0
  wp_batch
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-sample.xml"
wp_batch
# 3.6: a menu item is not an unknown type any more (menus are imported); the preview still names what is not converted
check "import z WordPressu – náhled upozorní na zkratku doplňku" 200 "/admin.php?module=transfer&action=preview&file=wordpress-sample.xml" "kontaktni-formular"
check "3.6 import z WordPressu – náhled nabízí vše skryté" 200 "/admin.php?module=transfer&action=preview&file=wordpress-sample.xml" 'name="skryte" value="1">'
check "import z WordPressu – náhled hlásí SEO data pluginů" 200 "/admin.php?module=transfer&action=preview&file=wordpress-sample.xml" "Rank Math"
wp_import
grep -q "Import obsahu je hotový" "$WORK/response" && echo "  ok     import z WordPressu doběhl" || { echo "  CHYBA  import z WordPressu nedoběhl"; ERRORS=$((ERRORS+1)); }
check "importovaná novinka" 200 /novinky/lavka-pres-bystrinu "Lávka přes Bystřinu"
check "importovaná novinka – galerie a video" 200 /novinky/lavka-pres-bystrinu 'class="galerie"'
check "importovaná stránka" 200 /o-zpravodaji "Kontakt"
check "importovaná stránka je rovnou v builderu" 200 /o-zpravodaji '<main id="obsah" class="stavba" tabindex="-1">'
check "importovaná stránka má nadpis z WordPressu" 200 /o-zpravodaji '<h1>O zpravodaji</h1>'
# SEO plugin data (Core\WpSeo): SmartCrawl title with the site name filled in, Yoast default pattern skipped, Rank Math noindex from a serialized array
expect "SEO ze SmartCrawlu: titulek s názvem webu, popis, bez noindex" "$(sq "SELECT CONCAT(seo_titulek LIKE 'Lávka přes Bystřinu znovu otevřena – %', '|', seo_popis, '|', noindex) FROM ka_novinky WHERE seo_link = 'lavka-pres-bystrinu'")" "1|Po roce oprav se lávka v Horní Lhotě otevřela chodcům i cyklistům.|0"
expect "SEO z Yoastu: výchozí vzor titulku se neimportuje, popis a noindex ano" "$(sq "SELECT CONCAT(seo_titulek, '|', seo_popis, '|', noindex) FROM ka_novinky WHERE seo_link = 'slavnosti-syra'")" "|Rekordní slavnosti sýra: tři tisíce lidí a vítězná farma z Dolní Lhoty.|1"
expect "SEO z Rank Math: titulek s proměnnými, noindex ze serializovaného pole" "$(sq "SELECT CONCAT(seo_titulek LIKE 'Fotografie čtenářů: lávka přes Bystřinu – %', '|', noindex) FROM ka_novinky WHERE seo_link = 'lavka-pres-bystrinu-2'")" "1|1"
expect "SEO ze SmartCrawlu na stránce: titulek a popis" "$(sq "SELECT CONCAT(seo_titulek, '|', popis, '|', noindex) FROM ka_stranky WHERE seo_link = 'o-zpravodaji'")" "O Podhorském zpravodaji – kdo jsme a kde nás najdete|Podhorský zpravodaj vychází od roku 1998 – redakce, kontakt a historie.|0"
check "importovaná novinka s noindex z pluginu ho vypisuje" 200 /novinky/slavnosti-syra 'noindex'
curl -s -o "$WORK/response" "$B/o-zpravodaji"; grep -q 'wp-block' "$WORK/response" && { echo "  CHYBA  třídy WordPressu ve stavbě"; ERRORS=$((ERRORS+1)); } || echo "  ok     třídy WordPressu bez stylu vynechány"
curl -s -o "$WORK/response" "$B/novinky/lavka-pres-bystrinu"; grep -qE "podvrh|onclick|kontaktni-formular|posta\.example" "$WORK/response" && { echo "  CHYBA  importovaná novinka obsahuje skript, zkratku doplňku nebo e-mail komentujícího"; ERRORS=$((ERRORS+1)); } || echo "  ok     importovaný obsah je vyčištěný"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2026/05/lavka-pres-bystrinu/"); expect "stará adresa WordPressu přesměruje na novinku" "$code" "301 $B/novinky/lavka-pres-bystrinu"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/?p=102"); expect "stará adresa /?p=102 přesměruje" "$code" 301
# a second import of the same file must not duplicate anything
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=wordpress-sample.xml
wp_import
COUNTS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'lavka-pres-bystrinu%' OR seo_link LIKE 'slavnosti-syra%' OR seo_link LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'o-zpravodaji%'))")
expect "opakovaný import nic nezdvojil (novinky/stránky)" "$COUNTS" "4/1"
# 2.7: a custom post type with ACF fields becomes a collection; its items keep their addresses
cpt_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=progress&file=wordpress-cpt.xml" -d "_csrf=$TOKEN"; }
cpt_import() {
  cpt_batch
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d soubor=wordpress-cpt.xml -d koncepty=1 -d stranky=1 -d presmerovani=1 -d rubrika=0 -d kolekce=1
  cpt_batch
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-cpt.xml"
cpt_batch
check "import z WordPressu – náhled ukáže vlastní typ obsahu jako kolekci" 200 "/admin.php?module=transfer&action=preview&file=wordpress-cpt.xml" "reference"
cpt_import
expect "vlastní typ obsahu → kolekce s poli podle hodnot" "$(sq "SELECT CONCAT(seo_link, '|', detail, '|', JSON_EXTRACT(pole, '\$[*].klic'), '|', JSON_EXTRACT(pole, '\$[*].typ')) FROM ka_kolekce WHERE nazev = 'Reference'")" 'reference|1|["klient", "rok_dokonceni", "datum_predani", "web_klienta", "fotka", "obsah"]|["text", "cislo", "datum", "odkaz", "obrazek", "html"]'
expect "položky kolekce: hodnoty polí, koncept skrytý" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', zobrazit, ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.klient')), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.datum_predani'))) ORDER BY idp SEPARATOR '|') FROM ka_kolekce_polozky WHERE idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 'reference')")" "kuchyne-novak:1:Rodina Novákových:2024-03-15|pekarna-u-mlyna:0:Pekárna U Mlýna:2023-11-01"
check "položka kolekce na staré adrese" 200 /reference/kuchyne-novak "Rodina Novákových"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/?p=401"); expect "stará adresa /?p=401 přesměruje na položku" "$code" "301 $B/reference/kuchyne-novak"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=wordpress-cpt.xml
cpt_import
expect "opakovaný import vlastního typu nic nezdvojil" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce WHERE nazev LIKE 'Reference%'), '/', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link LIKE 'kuchyne-novak%'))")" "1/1"
check "složka importu není přístupná z webu" 403 /storage/import/wordpress-sample.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
check "export webu je v seznamu" 200 "/admin.php?module=transfer" "action=download"
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/export" "$B/admin.php?module=transfer&action=download&file=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$WORK/export" obsah.json > "$WORK/obsah.json" 2>/dev/null || true; else cp "$WORK/export" "$WORK/obsah.json"; fi
grep -q '"format":"kaleta-export"' "$WORK/obsah.json" && grep -q '"novinky"' "$WORK/obsah.json" && ! grep -qE '"password"|smtp_heslo|tajny_klic|ai_klic' "$WORK/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export"; ERRORS=$((ERRORS+1)); }
grep -q '"kolekce_polozky":\[' "$WORK/obsah.json" && grep -q 'Jana Nováková' "$WORK/obsah.json" && grep -q '"tridy":\[' "$WORK/obsah.json" && grep -q '"casti":\[' "$WORK/obsah.json" && ! grep -q 'Chci kuchyň' "$WORK/obsah.json" \
  && grep -q '"adresa":"akce-okno"' "$WORK/obsah.json" && ! grep -q '"zobrazeni":' "$WORK/obsah.json" \
  && echo "  ok     export obsahuje builder, kolekce a pop-up okna, poptávky ani počitadla ne" || { echo "  CHYBA  export builderu a kolekcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/admin.php?module=transfer&action=download&file=$EXPORT"; grep -q "Heslo" "$WORK/response" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; ERRORS=$((ERRORS+1)); }

echo "== koš novinek"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'")
check "výpis novinek" 200 "/admin.php?module=news" "Smazat označené"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=delete" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
check "novinka v koši není na webu" 404 /novinky/vitejte-v-kalete
check "novinka v koši není ani v náhledu" 404 "/novinky/vitejte-v-kalete?preview=1"
check "záložka Koš" 200 "/admin.php?module=news&status=kos" "Vítejte"
check "novinka v koši nejde upravit" 404 "/admin.php?module=news&action=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=restore" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
expect "obnovená novinka se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM ka_novinky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_novinky SET visible = 1, smazano = '$(site_time)' - INTERVAL 31 DAY WHERE idc = $IDC"
check "vstup do administrace vysype starý koš" 200 /admin.php "Přehled"
expect "novinka starší 30 dní v koši je smazaná natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE idc = $IDC")" "0"

echo "== přesměrování po změně adresy kategorie a stránky"
check "formulář kategorie" 200 "/admin.php?module=categories" "Kategorie"
TOKEN=$(csrf)
IDT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_kategorie WHERE seo_link = 'aktuality'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d "idt=$IDT" -d nazev=Aktuality -d seo_link=aktuality-firmy -d hodnost=100
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/novinky/kategorie/aktuality"); expect "stará adresa kategorie přesměruje na novou" "$code" "301 $B/novinky/kategorie/aktuality-firmy"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakt'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/kontakt"); expect "stará adresa stránky přesměruje na novou" "$code" "301 $B/kontakty"

echo "== stránky: SEO, koš, duplikace"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakty'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>" \
  --data-urlencode "seo_titulek=Kontakt na truhlárnu" -d obrazek=media/2026/01/sdileni.jpg -d noindex=1
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '<title>Kontakt na truhlárnu' "$WORK/response" && grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$WORK/response" && grep -q 'noindex, follow' "$WORK/response" \
  && echo "  ok     stránka: vlastní titulek, úplná adresa obrázku pro sdílení, noindex" || { echo "  CHYBA  SEO stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "duplikát stránky je skrytý a má volnou adresu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', seo_link) FROM ka_stranky ORDER BY ids DESC LIMIT 1")" "0/kontakty-kopie"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDS"
check "stránka v koši není na webu" 404 /kontakty
check "záložka Koš u stránek" 200 "/admin.php?module=pages&status=kos" "Kontakt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=restore" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "obnovená stránka je skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', smazano IS NULL) FROM ka_stranky WHERE ids = $IDS")" "0/1"
IDU=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$IDU' WHERE promenna='home_page'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDU"
expect "úvodní stránku nejde smazat" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $IDU")" "1"
# a language version in progress (without a published translation of the home page) is not offered in the switcher, hreflang or the sitemap
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1, smazano = NULL WHERE ids = $IDU"; rm -f "$WORK"/web/storage/cache/stranky/*.html
# the pages are saved first: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
! grep -q 'hreflang="en"' "$WORK/response" && ! grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     jazyk bez zveřejněného překladu úvodu se návštěvníkům nenabízí" || { echo "  CHYBA  rozpracovaný jazyk v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"About home\",\"adresa\":\"about-home\",\"jazyk\":\"en\",\"preklad_z\":$IDU,\"text\":\"<p>Home</p>\",\"zobrazit\":1}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
grep -q 'hreflang="en"' "$WORK/response" && grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     se zveřejněným překladem úvodu se jazyk nabízí" || { echo "  CHYBA  hotový jazyk chybí v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}},{"typ":"jazyky"}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-jazyky-vyber--nahoru ka-jazyky-prvek' "$WORK/response" && grep -q 'hreflang="en" lang="en"' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     prvek Přepínač jazyků v patičce (nabídka nahoru) a web.js pro jazyk prohlížeče" || { echo "  CHYBA  prvek Přepínač jazyků"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"hlavicka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"navigace","obsah":{"jazyky":false}},{"typ":"navigace","obsah":{"menu":"paticka"}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
expect "Navigace s vypnutým přepínačem jazyků ho nemá, ostatní navigace ano" "$(grep -o '<nav class="ka-jazyky"' "$WORK/response" | wc -l | tr -d ' ')" 1
# 2.7: a header transparent at the top and smaller after scrolling (English vocabulary): fixed after its own sticky style, scroll-driven animation, CSS only now
mcp save_build '{"part":"header","publish":true,"build":{"v":1,"children":[{"type":"section","tag":"header","content":{"on_scroll":"transparent_shrink","text_at_top":"light"},"style":{"base":{"position":"sticky","background":"background"}},"children":[{"type":"navigation","content":{"mega_menu":true}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '<header id="s-[a-z0-9]*" class="ka-hlavicka-rolovani ka-hlavicka-rolovani--pruhledna">' "$WORK/response" && grep -q 'position: sticky; background-color: var(--ka-barva-pozadi); position: fixed; top: 0; inset-inline: 0; animation: ka-hlavicka-svetla linear both, ka-hlavicka-mensi linear both; animation-timeline: scroll(root); animation-range: 0 120px;' "$WORK/response" \
  && grep -q '@keyframes ka-hlavicka-svetla' "$WORK/response" && grep -q 'prefers-reduced-motion: reduce) { .ka-hlavicka-rolovani { animation: none !important; } }' "$WORK/response" \
  && echo "  ok     záhlaví nahoře průhledné a po odrolování menší (animace podle posuvu stránky, bez skriptu)" || { echo "  CHYBA  záhlaví při rolování"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_casti WHERE typ = 'hlavicka'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; ! grep -q 'ka-hlavicka-' "$WORK/response" && echo "  ok     bez takového záhlaví se CSS rolování nevypisuje" || { echo "  CHYBA  CSS rolování záhlaví i bez záhlaví"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]}}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_stranky WHERE seo_link = 'about-home'"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"

echo "== podstránky, plán, historie, šablony, export"
save_page() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" "$@"; }
save_page -d ids=0 --data-urlencode "titulek=Služby firmy" -d seo_link=sluzby-firmy -d zobrazit=1 -d v_menu=0 -d "text=<p>S</p>" > /dev/null
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby-firmy'")
save_page -d ids=0 --data-urlencode "titulek=Kuchyně" -d "nadrazena=$IDR" -d zobrazit=1 -d v_menu=0 -d "text=<p>Kuchyně na míru</p>" > /dev/null
expect "podstránka má adresu pod nadřazenou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "sluzby-firmy/kuchyne"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "podstránka na webu" 200 /sluzby-firmy/kuchyne "Kuchyně na míru"
save_page -d "ids=$IDR" --data-urlencode "titulek=Služby firmy" -d seo_link=nase-sluzby -d zobrazit=1 -d v_menu=0 -d "text=<p>S2</p>" > /dev/null
expect "změna adresy nadřazené posune podstránku" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "nase-sluzby/kuchyne"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/sluzby-firmy/kuchyne"); expect "stará adresa podstránky přesměruje" "$code" "301 $B/nase-sluzby/kuchyne"
expect "změna textu uloží předchozí verzi" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky_revize WHERE ids = $IDR ORDER BY idr DESC LIMIT 1")" "<p>S</p>"
save_page -d ids=0 --data-urlencode "titulek=Akce" -d v_menu=0 -d "text=<p>A</p>" -d "zverejnit_od=$(date -v+1d '+%Y-%m-%dT%H:%M' 2>/dev/null || date -d '+1 day' '+%Y-%m-%dT%H:%M')" > /dev/null
expect "naplánovaná stránka čeká skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE seo_link = 'akce'")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zverejnit_od = '$(site_time)' - INTERVAL 1 MINUTE WHERE seo_link = 'akce'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'notification_check'"
curl -s -o /dev/null "$B/novinky?x=$RANDOM"
# the job runs after the page is sent; under load (parallel suites) it can take a few seconds, and a request that comes
# while another background job holds the lock does nothing – as on a real site, the next visit gives it another chance
for i in $(seq 1 16); do [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" = 1 ] && break; sleep 0.5; curl -s -o /dev/null "$B/novinky?x=$RANDOM"; done
expect "naplánovaná stránka se v čase sama zveřejní" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" "1"
location=$(save_page -d ids=0 --data-urlencode "titulek=Nabídka" -d sablona=landing -d zobrazit=0 -d v_menu=0 -d text=)
case "$location" in *action=builder*) echo "  ok     nová stránka ze šablony jde rovnou do builderu";; *) echo "  CHYBA  šablona stránky: $location"; ERRORS=$((ERRORS+1));; esac
expect "šablona složí koncept ze sekcí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%\"typ\":\"sekce\"%' FROM ka_stranky WHERE seo_link = 'nabidka'")" "1"
# 3.5 (UXA-02): "Publish page" and "Show in navigation" ticked on a template page – visitors never get it empty: hidden and out
# of the navigation until its build is published, then shown as chosen
save_page -d ids=0 --data-urlencode "titulek=Workshopy" -d sablona=landing -d zobrazit=1 -d v_menu=1 -d text= > /dev/null
IDW=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'workshopy'")
expect "3.5: a template page with Publish ticked waits hidden for its build, the wish is kept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', v_menu, '/', stavba IS NULL) FROM ka_stranky WHERE ids = $IDW")" "0/1/1/1"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
expect "3.5: before the publish visitors get a 404 and no navigation link" "$(curl -s -o /dev/null -w '%{http_code}' "$B/workshopy")|$(grep -c 'href="/workshopy"' "$WORK/response" || true)" "404|0"
check "3.5: the builder says the page is hidden until it is published" 200 "/admin.php?module=pages&action=builder&id=$IDW" '"poPublikovani":true'
check "3.5: the page form keeps Publish ticked and explains the wait" 200 "/admin.php?module=pages&action=edit&id=$IDW" 'Skrytá, dokud nemá obsah'
check "3.5: the pages list marks it hidden until published" 200 "/admin.php?module=pages" 'skrytá do publikování'
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_publish&id=$IDW" -d "_csrf=$TOKEN")
expect "3.5: the first publish of the build shows the page and says so to the builder" "$code|$(grep -c '"zobrazena":true' "$WORK/response" || true)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', show_on_publish) FROM ka_stranky WHERE ids = $IDW")" "200|1|1/0"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
expect "3.5: after the publish the page is live and in the navigation" "$(curl -s -o /dev/null -w '%{http_code}' "$B/workshopy")|$(grep -c 'href="/workshopy"' "$WORK/response" || true)" "200|1"
# a blank page opened in the builder before it has text waits too; with text it is shown at once, as before
save_page -d ids=0 --data-urlencode "titulek=Prázdná do builderu" -d seo_link=prazdna-builder -d zobrazit=1 -d v_menu=0 -d text= -d po_ulozeni=stavitel > /dev/null
save_page -d ids=0 --data-urlencode "titulek=S textem hned" -d seo_link=s-textem-hned -d zobrazit=1 -d v_menu=0 -d "text=<p>Obsah</p>" -d po_ulozeni=stavitel > /dev/null
expect "3.5: an empty page for the builder waits, a page with text is shown at once" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(CONCAT(zobrazit, '/', show_on_publish) ORDER BY seo_link) FROM ka_stranky WHERE seo_link IN ('prazdna-builder', 's-textem-hned')")" "0/1,1/0"
# adding text later in the form shows it as chosen; unticking Publish drops the wish
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'prazdna-builder'")
save_page -d "ids=$IDP" --data-urlencode "titulek=Prázdná do builderu" -d seo_link=prazdna-builder -d zobrazit=1 -d v_menu=0 -d "text=<p>Už s textem</p>" > /dev/null
expect "3.5: the page waiting for content is shown once it gets text" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', show_on_publish) FROM ka_stranky WHERE ids = $IDP")" "1/0"
IDN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nabidka'")
curl -s -b "$JAR" -o "$WORK/stranka.json" "$B/admin.php?module=pages&action=export&id=$IDN"
grep -q '"format": "kaleta-stranka"' "$WORK/stranka.json" && echo "  ok     export stránky do JSON" || { echo "  CHYBA  export stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/stranka.json;type=application/json"
expect "import stránky vytvoří skrytou kopii se stavbou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba_koncept IS NOT NULL) FROM ka_stranky WHERE seo_link = 'nabidka-2'")" "0/1"
# 1.8: the export carries the classes and components of the build (a component inside a component too)
php -r '$d = json_decode(file_get_contents($argv[1]), true); $d["titulek"] = "Balíček";
  $d["stavba"]["deti"][] = ["typ" => "sekce", "tridy" => ["balicek-karta", "balicek-vlastni"], "deti" => [["typ" => "komponenta", "obsah" => ["komponenta" => "901", "hodnoty" => []]]]];
  $d["tridy"] = [["nazev" => "balicek-karta", "styl" => ["zaklad" => ["odsazeni" => "l"]], "css" => "color: red; behavior: url(x)"], ["nazev" => "balicek-vlastni", "styl" => ["zaklad" => ["pozadi" => "primarni"]], "css" => ""]];
  $d["komponenty"] = [["id" => 901, "nazev" => "Balíček vnější", "vlastnosti" => [], "stavba" => ["v" => 1, "deti" => [["typ" => "sekce", "deti" => [["typ" => "komponenta", "obsah" => ["komponenta" => "902"]]]]]]],
    ["id" => 902, "nazev" => "Balíček vnitřní", "vlastnosti" => [["klic" => "nadpis", "popisek" => "Nadpis", "typ" => "text", "vychozi" => "Ahoj"]], "stavba" => ["v" => 1, "deti" => [["typ" => "nadpis", "obsah" => ["text" => "{{nadpis}}"]]]]]];
  file_put_contents($argv[2], json_encode($d, JSON_UNESCAPED_UNICODE));' "$WORK/stranka.json" "$WORK/balicek.json"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('balicek-vlastni', '{}', 'color: blue', '$(site_time)')"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/balicek.json;type=application/json"
OUTER=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Balíček vnější'"); INNER=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Balíček vnitřní'")
expect "page import creates the missing class (cleaned) and keeps the site's own" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT(css LIKE '%color: red%', '/', css LIKE '%behavior%') FROM ka_tridy WHERE nazev = 'balicek-karta'")|$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', styl, ':', css) FROM ka_tridy WHERE nazev = 'balicek-vlastni'")" "1/0|1:{}:color: blue"
expect "page import creates both components and points the uses at them" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT((SELECT stavba LIKE '%\"komponenta\":\"$INNER\"%' FROM ka_komponenty WHERE idm = '$OUTER'), '/', stavba_koncept LIKE '%\"komponenta\":\"$OUTER\"%') FROM ka_stranky WHERE titulek = 'Balíček'")" "1/1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/balicek.json;type=application/json"
expect "a second import of the same page reuses the components" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_komponenty WHERE nazev LIKE 'Balíček%'")" "2"
IDB=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT MIN(ids) FROM ka_stranky WHERE titulek = 'Balíček'")
curl -s -b "$JAR" -o "$WORK/balicek-export.json" "$B/admin.php?module=pages&action=export&id=$IDB"
expect "the export lists the used classes and both components" "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["verze"], "|", implode(",", preg_grep("/^(balicek|karta$)/", array_column($d["tridy"], "nazev"))), "|", implode(",", array_column($d["komponenty"], "nazev"));' "$WORK/balicek-export.json")" "2|balicek-karta,balicek-vlastni,karta|Balíček vnější,Balíček vnitřní"

echo "== 2.7: copy and paste between Kaleta sites, display conditions"
paste_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDN" -d "_csrf=$TOKEN" "${@:2}"; }
code=$(paste_action build_package --data-urlencode "prvky=[{\"typ\":\"sekce\",\"tridy\":[\"balicek-karta\"],\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$OUTER\"}}]}]")
expect "copy packs the elements with their classes and components for the clipboard" "$code|$(php -r '$d = json_decode(file_get_contents($argv[1]), true)["schranka"] ?? []; echo $d["kaleta"] ?? "", "/", $d["v"] ?? "", "/", $d["site"] ?? "", "/", implode(",", array_column($d["classes"] ?? [], "nazev")), "/", implode(",", array_column($d["components"] ?? [], "nazev"));' "$WORK/response")" "200|elements/1/$B/balicek-karta/Balíček vnější,Balíček vnitřní"
FOREIGN='{"kaleta":"elements","v":1,"site":"https://jiny.example","elements":[{"id":"cizi1","typ":"sekce","kotva":"cizi","tridy":["schranka-nova","balicek-vlastni"],"deti":[{"id":"cizi2","typ":"obrazek","obsah":{"src":"media/2026/x.jpg","alt":"x"}},{"id":"cizi3","typ":"komponenta","obsah":{"komponenta":"950","hodnoty":{}}}]}],"classes":[{"nazev":"schranka-nova","styl":{"zaklad":{"pozadi":"primarni"}},"css":"color: red"},{"nazev":"balicek-vlastni","styl":{},"css":"color: green"}],"components":[{"id":950,"nazev":"Schránka komponenta","vlastnosti":[],"stavba":{"v":1,"deti":[{"typ":"nadpis","obsah":{"text":"Ze schránky"}}]}}]}'
code=$(paste_action build_paste --data-urlencode "schranka=$FOREIGN")
PASTED=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Schránka komponenta'")
expect "paste from another site: new ids, no anchor, the image points at the https source, the component use at the new component" "$code|$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $p = $d["prvky"][0] ?? []; echo $d["ok"] ? "ok" : "", "/", ($p["id"] ?? "") !== "cizi1" && preg_match("/^[a-z0-9]{3,16}$/", $p["id"] ?? "") ? "new-id" : "old-id", "/", isset($p["kotva"]) ? "anchor" : "no-anchor", "/", $p["deti"][0]["obsah"]["src"] ?? "", "/", $p["deti"][1]["obsah"]["komponenta"] ?? "", "/", (int) str_contains(implode(" ", $d["hlaseni"] ?? []), "1 ") + (int) str_contains(implode(" ", $d["hlaseni"] ?? []), "https://jiny.example"), "/", isset($d["tridy"]["schranka-nova"]) ? "class" : "";' "$WORK/response")" "200|ok/new-id/no-anchor/https://jiny.example/media/2026/x.jpg/$PASTED/2/class"
expect "paste creates the missing class and keeps the site's own" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT((SELECT css FROM ka_tridy WHERE nazev = 'schranka-nova'), '|', (SELECT css FROM ka_tridy WHERE nazev = 'balicek-vlastni'))")" "color: red;|color: blue"
expect "paste of plain text is refused" "$(paste_action build_paste --data-urlencode "schranka=just some text")" 400
expect "paste without the form token is refused" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_paste&id=$IDN" --data-urlencode "schranka=$FOREIGN")" 400
code=$(paste_action build_paste --data-urlencode "schranka={\"kaleta\":\"elements\",\"v\":1,\"site\":\"$B\",\"elements\":[{\"id\":\"svuj1\",\"typ\":\"nadpis\",\"tridy\":[\"schranka-stejny\"],\"obsah\":{\"text\":\"Odsud\"}}],\"classes\":[{\"nazev\":\"schranka-stejny\",\"styl\":{},\"css\":\"\"}],\"components\":[]}")
expect "paste from this site inserts the elements without importing anything" "$code|$(grep -c '"text":"Odsud"' "$WORK/response")|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_tridy WHERE nazev = 'schranka-stejny'")" "200|1|0"
# display conditions on the site: a URL parameter switches the element and takes the page out of the cache, a language version does not
paste_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"pod1","typ":"sekce","deti":[{"id":"pod2","typ":"nadpis","obsah":{"text":"Jarní sleva"},"podminky":{"parametr":{"nazev":"utm_campaign","hodnota":"jaro"}}},{"id":"pod3","typ":"nadpis","obsah":{"text":"Nur Deutsch"},"podminky":{"jazyky":["en"]}},{"id":"pod4","typ":"nadpis","obsah":{"text":"Pro všechny"}}]}]}' > /dev/null
grep -q '"podminky":{"parametr":{"nazev":"utm_campaign","hodnota":"jaro"}}' "$WORK/response" && grep -q '"podminky":{"jazyky":\["en"\]}' "$WORK/response" && echo "  ok     the validator keeps the URL parameter and language conditions" || { echo "  CHYBA  conditions after sanitize"; ERRORS=$((ERRORS+1)); }
paste_action build_publish > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDN"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/nabidka?utm_campaign=jaro"; grep -q 'Jarní sleva' "$WORK/response" && ! grep -q 'Nur Deutsch' "$WORK/response" && grep -q 'Pro všechny' "$WORK/response" && echo "  ok     element only with ?utm_campaign=jaro, none for another language version" || { echo "  CHYBA  conditions with the parameter"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/nabidka?utm_campaign=podzim"; ! grep -q 'Jarní sleva' "$WORK/response" && grep -q 'Pro všechny' "$WORK/response" && echo "  ok     another value of the parameter hides the element" || { echo "  CHYBA  parameter value"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/nabidka"; ! grep -q 'Jarní sleva' "$WORK/response" && echo "  ok     without the parameter the element is not on the page" || { echo "  CHYBA  element without the parameter"; ERRORS=$((ERRORS+1)); }
expect "a page with a URL parameter condition stays out of the page cache" "$(ls "$WORK"/web/storage/cache/stranky/*.html 2>/dev/null | wc -l | tr -d ' ')" 0
paste_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"pod1","typ":"sekce","deti":[{"id":"pod3","typ":"nadpis","obsah":{"text":"Nur Deutsch"},"podminky":{"jazyky":["en"]}},{"id":"pod4","typ":"nadpis","obsah":{"text":"Pro všechny"}}]}]}' > /dev/null; paste_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/nabidka"
expect "a page with only a language condition is cached (each language version has its own address)" "$(ls "$WORK"/web/storage/cache/stranky/*.html 2>/dev/null | wc -l | tr -d ' ')" 1

echo "== builder: vlastní CSS, atributy, animace, moje sekce, přejmenování třídy"
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nase-sluzby'")
version_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDV" -d "_csrf=$TOKEN" "${@:2}"; }
version_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"sv1","typ":"sekce","tridy":["karta"],"css":"backdrop-filter: blur(4px); background: url(x)","atributy":{"data-sledovat":"cta","onclick":"x"},"styl":{"zaklad":{"animace":"ka-vyjet","prechod":"linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))","okraj_vlevo":"auto"},"aktivni":{"pruhlednost":"0.8"}},"deti":[{"typ":"nadpis","obsah":{"text":"Test"}}]}]}' > /dev/null
grep -q 'Nepovolená deklarace' "$WORK/response" && grep -q 'Atribut může být jen' "$WORK/response" && echo "  ok     vlastní CSS a atributy prvku se čistí" || { echo "  CHYBA  čištění CSS a atributů"; ERRORS=$((ERRORS+1)); }
version_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/nase-sluzby"
grep -q 'data-sledovat="cta"' "$WORK/response" && ! grep -q 'onclick="x"' "$WORK/response" && grep -q 'backdrop-filter: blur(4px)' "$WORK/response" && grep -q 'animation-timeline: view()' "$WORK/response" \
  && grep -q '@keyframes ka-vyjet' "$WORK/response" && grep -q ':active {' "$WORK/response" && grep -q 'margin-inline-start: auto' "$WORK/response" \
  && echo "  ok     vlastní CSS, atributy, animace, stisknutí a okraj na webu" || { echo "  CHYBA  nové vlastnosti stylu na webu"; ERRORS=$((ERRORS+1)); }
expect "uložení do mých sekcí" "$(version_action build_save_section --data-urlencode 'nazev=Moje karta' --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Z knihovny"}}]}')" 200
grep -q '"nazev":"Moje karta"' "$WORK/response" && echo "  ok     moje sekce v seznamu" || { echo "  CHYBA  moje sekce"; ERRORS=$((ERRORS+1)); }
version_action build_class -d nazev=karta -d pouziti=1 > /dev/null; grep -q 'Služby firmy' "$WORK/response" && echo "  ok     přehled použití třídy" || { echo "  CHYBA  použití třídy"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "přejmenování třídy" "$(version_action build_class -d nazev=karta -d novy_nazev=karta-sluzby)" 200
expect "přejmenovaná třída ve stavbách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba LIKE '%\"karta-sluzby\"%' AND stavba NOT LIKE '%\"karta\"%' FROM ka_stranky WHERE ids = $IDV")" "1"

echo "== média, přesměrování, poptávky, uživatelé, písma"
php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 200, 80, 40)); imagejpeg($i, "'"$WORK"'/foto.jpg");'
printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10" onload="alert(1)"><script>alert(2)</script><rect width="20" height="10" fill="red"/></svg>' > "$WORK/logo.svg"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=upload" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/foto.jpg;type=image/jpeg" -F "soubory[]=@$WORK/logo.svg;type=image/svg+xml"
SVG=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE obr_poloha LIKE '%.svg' ORDER BY ido DESC LIMIT 1")
[ -n "$SVG" ] && ! grep -q 'onload\|<script' "$WORK/web/$SVG" && grep -q '<rect' "$WORK/web/$SVG" && echo "  ok     SVG nahrané a vyčištěné" || { echo "  CHYBA  SVG v Médiích"; ERRORS=$((ERRORS+1)); }
# a file over upload_max_filesize (but under post_max_size): a clear message with the limit in MB, not the php.ini shorthand
UPLOAD_LIMIT=$(php -r '$b = fn ($v) => (int) $v * (["k" => 1024, "m" => 1048576, "g" => 1073741824][strtolower(substr(trim($v), -1))] ?? 1); echo $b(ini_get("upload_max_filesize")), " ", $b(ini_get("post_max_size"));')
if [ "${UPLOAD_LIMIT% *}" -gt 0 ] && [ $(( ${UPLOAD_LIMIT% *} + 4096 )) -lt "${UPLOAD_LIMIT#* }" ]; then
  head -c $(( ${UPLOAD_LIMIT% *} + 1024 )) /dev/zero > "$WORK/velky.zip"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=media&action=upload&format=json" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/velky.zip"
  grep -q 'nejvýš [0-9,]* MB' "$WORK/response" && echo "  ok     soubor nad limit serveru: hláška s limitem v MB" || { echo "  CHYBA  hláška o limitu nahrávání"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
fi
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
PHOTO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE ido = $IDO")
expect "nahraný obrázek nedostane popis (alt) ze jména souboru" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT('[', nazev, ']') FROM ka_media WHERE ido = $IDO")" "[]"
php -r '$i = imagecreatetruecolor(800, 800); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); imagejpeg($i, "'"$WORK"'/nova.jpg");'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=replace" -F "_csrf=$TOKEN" -F "ido=$IDO" -F "soubor=@$WORK/nova.jpg;type=image/jpeg"
expect "náhrada souboru zachová adresu a změní rozměry" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(obr_poloha, ' ', obr_width, 'x', obr_height) FROM ka_media WHERE ido = $IDO")" "$PHOTO 800x800"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=save" -d "_csrf=$TOKEN" -d "ido=$IDO" -d nazev=Foto -d ohnisko_x=20 -d ohnisko_y=80
expect "ohnisko ořezu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ohnisko FROM ka_media WHERE ido = $IDO")" "20% 80%"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d z_adresy=/akce-leto -d na_adresu=/kontakty -d typ=302
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/akce-leto"); expect "dočasné přesměrování 302" "$code" "302"
check "hledání v přesměrováních" 200 "/admin.php?module=redirects&search=akce-leto" "akce-leto"
check "protokol s filtrem" 200 "/admin.php?module=changelog&area=stranky" "Protokol"
IDU2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=pozvany --data-urlencode email=pozvany@example.cz -d admin=2 -d pozvat=1)
expect "pozvaný uživatel má odkaz na heslo s delší platností" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obnova_otisk <> '' AND obnova_cas > '$(site_time)' FROM ka_uzivatele WHERE user = 'pozvany'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 -d nazev=Obchodník -d uroven=0 -d 'moduly[]=enquiries' -d 'moduly[]=collections'
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT MAX(idr) FROM ka_role")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=obchodnik --data-urlencode "password=$PASSWORD" -d "admin=r$IDR"
expect "vlastní role dá uživateli své sekce" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' AND u.role = $IDR")" "collections,enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d "idr=$IDR" -d nazev=Obchodník -d uroven=1 -d 'moduly[]=enquiries'
expect "změna role se přenese na členy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.ident_modulu)) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' GROUP BY u.idu")" "1:enquiries"
check "přehled rolí" 200 "/admin.php?module=roles" "Obchodník"
check "uživatelé ukazují vlastní roli" 200 "/admin.php?module=users" "Obchodník"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('require_2fa', 'spravci') ON DUPLICATE KEY UPDATE hodnota = 'spravci'"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?module=pages"); case "$code" in "302 "*action=account*) echo "  ok     povinné dvoufázové přihlášení pustí jen do Můj účet";; *) echo "  CHYBA  vynucení 2FA: $code"; ERRORS=$((ERRORS+1));; esac
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'require_2fa'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$TOKEN" -d co=totp_start
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
grep -q '<svg class="qr"' "$WORK/response" && grep -q 'class="totp-klic"' "$WORK/response" && echo "  ok     zapnutí 2FA ukáže QR kód i klíč k ručnímu zadání" || { echo "  CHYBA  QR kód při zapínání 2FA"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = JSON_SET(IF(hodnota = '' OR hodnota IS NULL, '{}', hodnota), '$.vlastni_pisma', JSON_ARRAY(JSON_OBJECT('nazev', 'Znacka Sans', 'soubor', 'media/2026/01/znacka.woff2', 'tucny', '')), '$.pismo_titulky', 'vlastni-1') WHERE promenna = 'design_system'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '@font-face { font-family: "Znacka Sans"; src: url("/media/2026/01/znacka.woff2")' "$WORK/response" && grep -q -- '--ka-pismo-titulky: "Znacka Sans"' "$WORK/response" && echo "  ok     vlastní písmo z Médií" || { echo "  CHYBA  vlastní písmo"; ERRORS=$((ERRORS+1)); }
expect "statistika po stránkách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_stat_stranky")" "1"
# since 2.12 a phone number or an e-mail address anywhere on the page (the footer) keeps web.js for the click counter while the statistics are on – off, the page does without the script
stats_feature 0
curl -s -o "$WORK/response" "$B/kontakty"; grep -q 'image/web.js' "$WORK/response" && echo "  CHYBA  web.js i na stránce, která ho nepotřebuje" && ERRORS=$((ERRORS+1)) || echo "  ok     web.js jen tam, kde je potřeba"
check "3.2: with the Statistics feature off the Analytics tab says so and links to Features" 200 "/admin.php?module=settings&tab=analytics" 'Off – nothing is measured\|Vypnuto – nic se neměří'
stats_feature 1
check "3.2: the Analytics tab shows the statistics feature with a link to Features, no checkbox of its own" 200 "/admin.php?module=settings&tab=analytics" 'admin.php?module=extensions'
grep -q 'name="stats"' "$WORK/response" && { echo "  CHYBA  the Analytics tab still has its own statistics checkbox"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.2: one switch for the statistics – no second checkbox"

echo "== menu"
check "editor menu" 200 "/admin.php?module=menu" 'data-menu-seznam'
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
# 2.7: an icon (lide = people) and a description on an item, a group inside the submenu with its own items (a column), an unknown icon drops out
MENU='[{"typ":"stranka","ids":'$IDO',"text":"O firmě","ikona":"lide","popis":"Kdo jsme","deti":[{"typ":"odkaz","text":"Kariéra","url":"https://example.cz/kariera","nove_okno":true},{"typ":"skupina","text":"Tým","ikona":"neexistuje","deti":[{"typ":"odkaz","text":"Vedení","url":"/vedeni"}]}]},{"typ":"novinky"},{"typ":"odkaz","text":"Zlý","url":"javascript:alert(1)"}]'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=hlavni" -d "_csrf=$TOKEN" --data-urlencode "polozky=$MENU"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Zásady ochrany soukromí","url":"/zasady"}]'
expect "the menu waits in the draft look" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'paticka' AND polozky LIKE '%/zasady%'")" "0"
publish_look
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/novinky"
grep -q '<li class="podmenu"><a href="[^"]*/o-nas"><svg class="menu-ikona"' "$WORK/response" && grep -q '</svg>O firmě</a><button type="button" class="menu-rozbalit" aria-controls="ka-podmenu-[0-9]*" aria-label="Podmenu: O firmě"></button><ul id="ka-podmenu-[0-9]*"><li><a href="https://example.cz/kariera" target="_blank" rel="noopener">Kariéra</a>' "$WORK/response" && grep -q 'aria-current="page">Novinky' "$WORK/response" && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     menu s podmenu na webu, ikona před textem, nebezpečný odkaz vypadl" || { echo "  CHYBA  menu na webu"; ERRORS=$((ERRORS+1)); }
grep -q '</li><li class="menu-sloupec"><span class="menu-nadpis">Tým</span><ul><li><a href="[^"]*/vedeni">Vedení</a></li></ul></li>' "$WORK/response" && ! grep -q 'menu-popis\|neexistuje' "$WORK/response" \
  && echo "  ok     skupina v podmenu jako sloupec s nadpisem; popis jen v mega menu, neznámá ikona vypadla" || { echo "  CHYBA  sloupec skupiny v menu"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web\.js' "$WORK/response" && echo "  ok     stránka s podmenu načte web.js (Esc podmenu zavře)" || { echo "  CHYBA  stránka s podmenu bez web.js"; ERRORS=$((ERRORS+1)); }
mcp get_menu '{"location":"main"}' > "$WORK/response"; grep -q 'icon\\":\\"people' "$WORK/response" && grep -q 'description\\":\\"Kdo jsme' "$WORK/response" && echo "  ok     MCP: get_menu vrací ikonu anglicky a popis položky" || { echo "  CHYBA  MCP get_menu ikona"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nacti_menu '{"umisteni":"paticka"}' > "$WORK/response"; grep -q 'Zásady ochrany soukromí' "$WORK/response" && echo "  ok     menu v patičce (MCP)" || { echo "  CHYBA  menu v patičce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
expect "zaškrtnutá stránka se přidá na konec sestaveného menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT polozky LIKE '%\"ids\":$IDS%' FROM ka_menu WHERE umisteni = 'hlavni'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=automatic&location=hlavni" -d "_csrf=$TOKEN"
publish_look
expect "návrat k automatickému menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni'")" "0"
# 3.6 (UXA-09): "Save and publish menu" publishes that menu at once and keeps a version; the rest of the draft look waits
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=hlavni" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Draft only","url":"/draft-only"}]'
LOOK_VERSION=$(sq "SELECT COALESCE(MAX(id), 0) FROM ka_look_versions")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$TOKEN" -d publikovat=1 --data-urlencode 'polozky=[{"typ":"odkaz","text":"Published at once","url":"/published-at-once"}]'
expect "3.6 UXA-09: Save and publish menu publishes that menu with a version, another menu waits in the draft" \
  "$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'paticka' AND polozky LIKE '%published-at-once%'")|$(sq "SELECT COUNT(*) FROM ka_menu WHERE polozky LIKE '%draft-only%'")|$(sq "SELECT hodnota LIKE '%draft-only%' AND hodnota NOT LIKE '%published-at-once%' FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COALESCE(MAX(id), 0) > $LOOK_VERSION FROM ka_look_versions")" "1|0|1|1"
check "3.6 UXA-09: the draft look bar is one line naming what waits, the details open below" 200 "/admin.php?module=pages" '<summary><strong>Nepublikované změny:</strong> menu</summary>'
publish_look

echo "== ikony, manifest, cache"
expect "favicon.ico bez ikony nevygeneruje stránku 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/favicon.ico")" 204
curl -s -o "$WORK/response" "$B/manifest.webmanifest"; grep -q '"start_url"' "$WORK/response" && echo "  ok     manifest webu" || { echo "  CHYBA  manifest"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/novinky"
expect "odkaz s utm parametry jde z cache" "$(curl -s -o /dev/null -D - "$B/novinky?utm_source=newsletter&fbclid=x" | grep -ci '^x-cache: kaleta')" 1
ETAG=$(curl -s -o /dev/null -D - "$B/novinky" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')
expect "stránka z cache odpoví 304 na shodný ETag" "$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$B/novinky")" 304

echo "== nové prvky builderu"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[
{\"typ\":\"drobecky\"},
{\"typ\":\"ikona\",\"obsah\":{\"ikona\":\"telefon\",\"tvar\":\"kruh\"}},
{\"typ\":\"galerie\",\"obsah\":{\"fotky\":[{\"src\":\"media/2026/01/a.jpg\",\"alt\":\"Dílna\"},{\"src\":\"media/2026/01/b.jpg\",\"alt\":\"\"}]}},
{\"typ\":\"zalozky\",\"obsah\":{\"karty\":[{\"nazev\":\"Základ\",\"obsah\":\"<p>A</p>\"},{\"nazev\":\"Plus\",\"obsah\":\"<p>B</p>\"}]}},
{\"typ\":\"karusel\",\"obsah\":{\"naraz\":\"2\"},\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Snímek</p>\"}}]},
{\"typ\":\"mapa\",\"obsah\":{\"adresa\":\"Brno, Náměstí Svobody\"}},
{\"typ\":\"faq\",\"obsah\":{\"jedna\":true,\"faq\":false,\"polozky\":[{\"otazka\":\"Co?\",\"odpoved\":\"<p>To.</p>\"}]}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     nové prvky projdou validátorem" || { echo "  CHYBA  validace nových prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'class="ka-drobecky"' 'aria-current="page">Z HTML' 'class="ka-ikona ka-ikona--kruh" aria-hidden="true"><svg' 'class="ka-galerie"' 'alt="Dílna"' 'role="tablist"' 'aria-controls="zp-' 'data-karusel' '--ka-naraz:2' 'data-vlozit="https://maps.google.com/maps?q=Brno' 'name="faq-'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  nový prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
grep -q '"BreadcrumbList"' "$WORK/response" && ! grep -q '"FAQPage"' "$WORK/response" && echo "  ok     nové prvky na webu, drobečky i pro vyhledávače, akordeon bez FAQPage" || { echo "  CHYBA  strukturovaná data stránky"; ERRORS=$((ERRORS+1)); }

echo "== další prvky: počítadlo, průběh, hodnocení, odpočet, sítě, hledání, nahoru, odběr, podmínky"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('social_instagram','https://instagram.com/firma') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"obsah\":{\"video\":\"media/2026/01/pozadi.mp4\"},\"deti\":[
{\"typ\":\"pocitadlo\",\"obsah\":{\"cislo\":1200,\"za\":\"+\"}},
{\"typ\":\"prubeh\",\"obsah\":{\"polozky\":[{\"nazev\":\"Termíny\",\"hodnota\":96}]}},
{\"typ\":\"hodnoceni\",\"obsah\":{\"hodnota\":\"4,5\"}},
{\"typ\":\"odpocet\",\"obsah\":{\"cil\":\"2099-01-01 09:00\"}},
{\"typ\":\"socialni\"},{\"typ\":\"hledani\"},{\"typ\":\"nahoru\"},{\"typ\":\"newsletter\"},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Jen pro redakci\"},\"podminky\":{\"prihlaseni\":\"ano\"}},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Stará akce\"},\"podminky\":{\"do\":\"2000-01-01\"}},
{\"typ\":\"video\",\"obsah\":{\"url\":\"media/2026/01/film.mp4\",\"plakat\":\"media/2026/01/plakat.jpg\"}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     další prvky projdou validátorem" || { echo "  CHYBA  validace dalších prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'data-pocitadlo="1200">1' '<meter min="0" max="100"' 'aria-label="Hodnocení 4,5 z 5' 'data-odpocet="2099-01-01T09:00' 'class="ka-socialni"' 'aria-label="Instagram"' 'role="search"' 'class="ka-nahoru"' 'class="ka-newsletter"' 'name="as_podpis"' 'class="ka-video-pozadi"' 'poster="/media/2026/01/plakat.jpg"' 'image/web.js'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  další prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
! grep -q 'Jen pro redakci\|Stará akce' "$WORK/response" && echo "  ok     podmínky zobrazení skryjí prvek nepřihlášenému i po datu" || { echo "  CHYBA  podmínky zobrazení"; ERRORS=$((ERRORS+1)); }
# 3.5 (UXP-05): the timer role sits on a wrapper – on the <dl> it replaced the list role and orphaned <dt>/<dd>
grep -qF 'role="timer" aria-live="off"><dl><div><dt>' "$WORK/response" && ! grep -q '<dl[^>]*role="timer"' "$WORK/response" \
  && echo "  ok     3.5: countdown – the timer role on a wrapper, the description list keeps its own" || { echo "  CHYBA  3.5: countdown markup"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/z-html"; ls "$WORK"/web/storage/cache/stranky/*.html >/dev/null 2>&1 && { echo "  CHYBA  stránka s podmínkou zobrazení šla do cache"; ERRORS=$((ERRORS+1)); } || echo "  ok     stránka s podmínkou zobrazení se necachuje"
curl -s -b "$JAR" -o "$WORK/response" "$B/z-html"; grep -q 'Jen pro redakci' "$WORK/response" && echo "  ok     přihlášený vidí prvek jen pro redakci" || { echo "  CHYBA  prvek pro přihlášené"; ERRORS=$((ERRORS+1)); }
# 3.5 (UXP-08): the first image of the first section loads at once (usually the hero, the LCP) unless its editor chose
# otherwise; later images stay lazy; builds saved before 3.5 keep working (priority true = at once)
mcp stavba_z_html '{"titulek":"Obrazky 35","html":"<section><div><img src=\"/media/2026/01/hero35.jpg\" alt=\"Hero35\"></div><img src=\"/media/2026/01/druhy35.jpg\" alt=\"Druhy35\"></section><section><img src=\"/media/2026/01/treti35.jpg\" alt=\"Treti35\"></section>"}' > /dev/null
ID35=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'obrazky-35'")
mcp publikuj_stavbu "{\"id\":$ID35}" > /dev/null; "${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $ID35"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/obrazky-35"
grep -q 'alt="Hero35" fetchpriority="high"' "$WORK/response" && grep -q 'alt="Druhy35" loading="lazy"' "$WORK/response" && grep -q 'alt="Treti35" loading="lazy"' "$WORK/response" \
  && echo "  ok     3.5: the first image of the first section loads at once, the others lazily" || { echo "  CHYBA  3.5: automatic priority of the first image"; grep -o '<img[^>]*>' "$WORK/response"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET stavba = REPLACE(stavba, '\"priorita\":\"\"', '\"priorita\":false') WHERE ids = $ID35"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/obrazky-35"
grep -q 'alt="Hero35" fetchpriority="high"' "$WORK/response" && echo "  ok     3.5: a build saved before 3.5 (priority false) gets the automatic priority too" || { echo "  CHYBA  3.5: an older build's first image"; ERRORS=$((ERRORS+1)); }
mcp get_build "{\"id\":$ID35}" > "$WORK/response"
BUILD35=$(php -r '$t = json_decode(json_decode((string) file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); $b = $t["build"]; $b["children"][0]["children"][0]["children"][0]["content"]["priority"] = "lazy"; $b["children"][0]["children"][1]["content"]["priority"] = true; echo json_encode($b);' "$WORK/response")
mcp save_build "{\"id\":$ID35,\"publish\":true,\"build\":$BUILD35}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/obrazky-35"
grep -q 'alt="Hero35" loading="lazy"' "$WORK/response" && grep -q 'alt="Druhy35" fetchpriority="high"' "$WORK/response" && grep -q 'alt="Treti35" loading="lazy"' "$WORK/response" \
  && expect "3.5: Claude chooses the loading in English (lazy, or true from before), stored as '0' and '1'" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba LIKE '%\"priorita\":\"0\"%', stavba LIKE '%\"priorita\":\"1\"%') FROM ka_stranky WHERE ids = $ID35")" "11" \
  || { echo "  CHYBA  3.5: an explicit loading setting wins over the automatic one"; grep -o '<img[^>]*>' "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/z-html"
NEWSLETTER_FORM=$(tr '\n' ' ' < "$WORK/response" | grep -o 'class="ka-newsletter".*' | sed 's#</form>.*##')
NL_SIGNATURE=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_podpis" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'); NL_TIME=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_cas" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
sleep 5
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/odber" -d "email=Odber@Example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$NL_SIGNATURE" -d "as_cas=$NL_TIME" -d web_adresa=)
case "$code" in "303 "*"/z-html?subscription=ok#x") echo "  ok     přihlášení k odběru";; *) echo "  CHYBA  přihlášení k odběru: $code"; ERRORS=$((ERRORS+1));; esac
SUB_TOKEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT token FROM ka_odberatele WHERE email = 'odber@example.cz' AND stav = 0")
check "odkaz z e-mailu jen nabídne potvrzení" 200 "/odber?confirm=$SUB_TOKEN" "Potvrdit odběr"
check "old link (potvrdit=) from an e-mail sent before the rename still offers the confirmation" 200 "/odber?potvrdit=$SUB_TOKEN" "Potvrdit odběr"
expect "otevření odkazu (skener pošty) odběr nepotvrdí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "0"
curl -s -o "$WORK/response" -X POST "$B/odber?confirm=$SUB_TOKEN"; grep -q "Odběr je potvrzený" "$WORK/response" && echo "  ok     potvrzení odběru tlačítkem" || { echo "  CHYBA  potvrzení odběru"; ERRORS=$((ERRORS+1)); }
expect "odběratel je potvrzený" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "1"
check "odběratelé v administraci" 200 "/admin.php?module=subscribers" "odber@example.cz"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers&action=csv"; grep -q "odber@example.cz;.*/subscription?unsubscribe=$SUB_TOKEN" "$WORK/response" && echo "  ok     export odběratelů s odkazem na odhlášení" || { echo "  CHYBA  export odběratelů"; head -3 "$WORK/response"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'newsletter,', '') WHERE promenna = 'extensions'"
check "odhlášení jde i s vypnutým Newsletterem" 200 "/odber?unsubscribe=$SUB_TOKEN" "Odhlásit odběr"
check "old link (odhlasit=) from an e-mail sent before the rename still offers the unsubscribe" 200 "/odber?odhlasit=$SUB_TOKEN" "Odhlásit odběr"
curl -s -o "$WORK/response" -X POST "$B/odber?unsubscribe=$SUB_TOKEN"; grep -q "Odhlášeno" "$WORK/response" && echo "  ok     odhlášení tlačítkem" || { echo "  CHYBA  odhlášení"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'poptavky,', 'poptavky,newsletter,') WHERE promenna = 'extensions'"
expect "odhlášený je smazaný" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_odberatele")" "0"

echo "== 3.7: English system paths next to the Czech ones (GitHub #17)"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
contains -q 'class="ka-newsletter" method="post" action="/subscription"' "$WORK/response" && echo "  ok     3.7: the newsletter sign-up posts to /subscription" || { echo "  CHYBA  3.7: sign-up action: $(grep -o 'ka-newsletter[^>]*' "$WORK/response" | head -1)"; ERRORS=$((ERRORS+1)); }
expect "3.7: cron answers at /tasks and at the older /ulohy, a wrong token at neither" \
  "$(curl -s "$B/tasks?token=testtoken123" | cut -c1-3)|$(curl -s "$B/ulohy?token=testtoken123" | cut -c1-3)|$(curl -s -o /dev/null -w '%{http_code}' "$B/tasks?token=wrong")|$(curl -s -o /dev/null -w '%{http_code}' "$B/tasks/?token=testtoken123")" "OK |OK |403|200"
HEALTH37=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'health_token'")
expect "3.7: monitoring answers at /status.json and at the older /stav.json" \
  "$(curl -s "$B/status.json?token=$HEALTH37" | grep -o '"verze":"[^"]*"' | head -1 | cut -c1-8)|$(curl -s "$B/stav.json?token=$HEALTH37" | grep -o '"verze":"[^"]*"' | head -1 | cut -c1-8)|$(curl -s -o /dev/null -w '%{http_code}' "$B/status.json?token=wrong")" '"verze":|"verze":|403'
check "3.7: System status shows the English cron and monitoring addresses" 200 "/admin.php?module=status" "tasks?token=testtoken123"
contains -q "status.json?token=$HEALTH37" "$WORK/response" && contains -q 'starší adresu …/ulohy?token=' "$WORK/response" && echo "  ok     3.7: … and says the older cron address keeps working" || { echo "  CHYBA  3.7: status addresses"; ERRORS=$((ERRORS+1)); }
COOKIES_LOG37=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'cookies_log'), '0')")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_log', '1')" > /dev/null
expect "3.7: the consent log takes POST /consent and the older /souhlas, both stored" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/consent" -d id=37373737373737373737373737373737 -d kategorie=analytika)|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/souhlas" -d id=37373737373737373737373737373738 -d kategorie=marketing)|$(sq "SELECT COUNT(*) FROM ka_souhlasy WHERE id_souhlasu LIKE '3737373737373737373737373737373%'")" "204|204|2"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_log', '$COOKIES_LOG37'); DELETE FROM ka_souhlasy WHERE id_souhlasu LIKE '3737373737373737373737373737373%'" > /dev/null
# a page that already had the English word before 3.7 keeps it; the site's forms then keep posting to /formular
mcp vytvor_stranku '{"titulek":"Formular 37","zobrazit":true}' > /dev/null
F37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Formular 37'")
mcp stavba_uloz "{\"id\":$F37,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Form 37\"}}]}]}}" > /dev/null
mcp vytvor_stranku '{"titulek":"Form page 37","zobrazit":true,"text":"<p>Our own form page</p>"}' > /dev/null
P37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Form page 37'")
sq "UPDATE ka_stranky SET seo_link = 'form' WHERE ids = $P37" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "3.7: a page that held /form before 3.7 keeps it" 200 /form "Our own form page"
curl -s -o "$WORK/response" "$B/formular-37"
contains -q 'method="post" action="/formular"' "$WORK/response" && ! contains -q 'action="/form"' "$WORK/response" && echo "  ok     3.7: … and the site's forms keep posting to /formular" || { echo "  CHYBA  3.7: form action next to a page /form"; ERRORS=$((ERRORS+1)); }
check "3.7: System status names the address the page holds" 200 "/admin.php?module=status" "Anglické systémové adresy"
mcp update_page "{\"id\":$P37,\"slug\":\"form\",\"title\":\"Form page 37b\"}" > "$WORK/response"
expect "3.7: the page keeps its slug when it is saved again with it (a reserved word it had before)" "$(grep -c 'unknown_parameters\|isError' "$WORK/response" || true)|$(sq "SELECT CONCAT(seo_link, '|', titulek) FROM ka_stranky WHERE ids = $P37")" "0|form|Form page 37b"
mcp create_page '{"title":"Tasks 37","slug":"tasks"}' > "$WORK/response"
contains -q 'používá systém\|used by the system' "$WORK/response" && expect "3.7: a new page cannot take an English system path" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'tasks'")" "0" || { echo "  CHYBA  3.7: a new page took /tasks: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE ids = $P37" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/formular-37"
contains -q 'method="post" action="/form"' "$WORK/response" && echo "  ok     3.7: without the page the forms post to /form again" || { echo "  CHYBA  3.7: form action after the page is gone"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE ids = $F37" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
# 3.7 security review N37-2: a page import, a restore from the trash and the page form never give a page an English system
# path – the page gets a numbered slug and the result says so; cron, the one-click unsubscribe and forms keep their path
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"; TOKEN37=$(csrf)
for title in Subscription Tasks; do
  printf '{"format":"kaleta-stranka","verze":2,"titulek":"%s","popis":"","text":"<p>Imported 37</p>"}' "$title" > "$WORK/page37.json"
  curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN37" -F "soubor=@$WORK/page37.json;type=application/json"
done
expect "3.7 N37-2: an imported page \"Tasks\" or \"Subscription\" gets a numbered slug, and the import says why" \
  "$(sq "SELECT GROUP_CONCAT(seo_link ORDER BY seo_link) FROM ka_stranky WHERE titulek IN ('Subscription', 'Tasks') AND smazano IS NULL")|$(grep -c 'Adresu /tasks používá systém, proto stránka dostala /tasks-2.' "$WORK/response" || true)" "subscription-2,tasks-2|1"
sq "INSERT INTO ka_odberatele (email, stav, token, datum) VALUES ('n37@example.cz', 0, '37373737373737373737373737373737', '$(site_time)')" > /dev/null
curl -s -o /dev/null -X POST "$B/subscription?unsubscribe=37373737373737373737373737373737" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "3.7 N37-2: … so cron still runs at /tasks and the one-click unsubscribe at /subscription still unsubscribes" \
  "$(curl -s "$B/tasks?token=testtoken123" | cut -c1-3)|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'n37@example.cz'")" "OK |0"
mcp create_page '{"title":"Consent 37","text":"<p>Consent page 37</p>"}' > /dev/null; mcp create_page '{"title":"Conversion 37","text":"<p>Conversion page 37</p>"}' > /dev/null
C37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Consent 37'"); V37=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Conversion 37'")
# pages "consent" and "conversion" from before 3.7, in the trash (while there, the system took the English address back)
sq "UPDATE ka_stranky SET seo_link = 'consent', smazano = '$(site_time)' WHERE ids = $C37; UPDATE ka_stranky SET seo_link = 'conversion', smazano = '$(site_time)' WHERE ids = $V37" > /dev/null
mcp restore_from_trash "{\"type\":\"page\",\"id\":$C37}" > "$WORK/response"
expect "3.7 N37-2: restore_from_trash brings a page back under a free slug when the system uses its old one" \
  "$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $C37")|$(mcp_value address)|$(mcp_value note)" "consent-2|/consent-2|The system uses the old address /consent of the page, so the page got a new one."
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=restore" -d "_csrf=$TOKEN37" -d "ids=$V37"
expect "3.7 N37-2: … and so does Pages → Trash → Restore, with the reason" \
  "$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $V37")|$(grep -c 'Stránka je obnovená s adresou /conversion-2, protože její dřívější adresu /conversion používá systém' "$WORK/response" || true)" "conversion-2|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN37" -d ids=0 --data-urlencode "titulek=Form" -d seo_link= -d zobrazit=0 -d v_menu=0 -d "text=<p>Form 37</p>"
expect "3.7 N37-2: the page form makes a free slug from a title that is a system word" "$(sq "SELECT seo_link FROM ka_stranky WHERE text = '<p>Form 37</p>'")" "form-2"
sq "DELETE FROM ka_stranky WHERE titulek IN ('Subscription', 'Tasks') OR ids IN ($C37, $V37) OR text = '<p>Form 37</p>'" > /dev/null
# a page that holds /tasks from before 3.7 while cron is not running: System status says why cron may be calling the page
TASKS_LAST37=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_last_run'")
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, v_menu, zmeneno) VALUES ('Tasks 37', 'tasks', '<p>Our tasks</p>', 1, 0, '$(site_time)'); UPDATE ka_nastaveni SET hodnota = '1' WHERE promenna = 'tasks_last_run'" > /dev/null
check "3.7 N37-2: System status warns when a page holds /tasks and cron does not run" 200 "/admin.php?module=status" "Cron, který volá /tasks, se dostane na tu stránku a nic nespustí – nasměrujte ho na /ulohy."
expect "3.7 N37-2: … the page answers at /tasks, cron at /ulohy" "$(curl -s "$B/tasks?token=testtoken123" | grep -c 'Our tasks' || true)|$(curl -s "$B/ulohy?token=testtoken123" | cut -c1-3)" "1|OK "
sq "DELETE FROM ka_stranky WHERE titulek = 'Tasks 37'; UPDATE ka_nastaveni SET hodnota = '$TASKS_LAST37' WHERE promenna = 'tasks_last_run'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== odběratelé do mailingové služby (falešný server)"
SERVICE_PORT=$((PORT + 2)); mkdir -p "$WORK/sluzba"
cat > "$WORK/sluzba/router.php" <<'PHP'
<?php
$log = __DIR__ . '/pozadavky.log';
if ($_SERVER['REQUEST_URI'] === '/_log') { header('Content-Type: text/plain'); @readfile($log); return true; }
$h = array_change_key_case(getallheaders());
file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . ($h['api-key'] ?? $h['authorization'] ?? $h['key'] ?? '-') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
if (str_contains($_SERVER['REQUEST_URI'], 'chyba')) { http_response_code(500); echo '{"message":"Invalid list"}'; return true; }
http_response_code(201); header('Content-Type: application/json'); echo '{}'; return true;
PHP
(cd "$WORK/sluzba" && exec php -S "127.0.0.1:$SERVICE_PORT" router.php > /dev/null 2>&1) & SERVICE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$SERVICE_PORT/_log" && break; sleep 0.2; done
set_service() { "${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('newsletter_service','$1'),('newsletter_key','$2'),('newsletter_list','$3'),('newsletter_webhook','$4'),('newsletter_test_url','http://127.0.0.1:$SERVICE_PORT'); DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('sluzba@example.cz', 1, '$(php -r 'echo bin2hex(random_bytes(16));')', '$(site_time)', '$(site_time)')"; : > "$WORK/sluzba/pozadavky.log"; }
subscriber_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=subscribers&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
last_request() { tail -1 "$WORK/sluzba/pozadavky.log"; }
set_service brevo brevo-klic 7 ''; subscriber_action sync
case "$(last_request)" in 'POST /brevo/v3/contacts brevo-klic {"email":"sluzba@example.cz","listIds":[7],"updateEnabled":true}') echo "  ok     Brevo: přidání do seznamu";; *) echo "  CHYBA  Brevo: $(last_request)"; ERRORS=$((ERRORS+1));; esac
expect "odběratel ve službě" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(sync, '/', (SELECT COUNT(*) FROM ka_odber_fronta)) FROM ka_odberatele")" "ok/0"
check "stav služby u odběratelů" 200 "/admin.php?module=subscribers" "odesláno"
IDOD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_odberatele"); subscriber_action delete -d "ido=$IDOD"; subscriber_action retry
case "$(last_request)" in 'POST /brevo/v3/contacts/lists/7/contacts/remove brevo-klic {"emails":["sluzba@example.cz"]}') echo "  ok     Brevo: smazaný odběratel odebrán ze seznamu";; *) echo "  CHYBA  Brevo odebrání: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailchimp 'abc123-us21' 'aud1' ''; subscriber_action sync
case "$(last_request)" in "PUT /mailchimp/3.0/lists/aud1/members/$(php -r 'echo md5("sluzba@example.cz");') Basic $(printf 'kaleta:abc123-us21' | base64) "*'"status":"subscribed"'*) echo "  ok     Mailchimp: člen audience";; *) echo "  CHYBA  Mailchimp: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailerlite ml-klic 99 ''; subscriber_action sync
case "$(last_request)" in 'POST /mailerlite/api/subscribers Bearer ml-klic {"email":"sluzba@example.cz","groups":["99"],"status":"active"}') echo "  ok     MailerLite: odběratel ve skupině";; *) echo "  CHYBA  MailerLite: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service smartemailing 'jmeno:klic' 5 ''; subscriber_action sync
case "$(last_request)" in "POST /smartemailing/api/v3/import Basic $(printf 'jmeno:klic' | base64) "*'"contactlists":[{"id":5,"status":"confirmed"}]'*) echo "  ok     SmartEmailing: import do seznamu";; *) echo "  CHYBA  SmartEmailing: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service webhook '' '' 'https://hook.example.com/odber'; subscriber_action sync
case "$(last_request)" in 'POST /webhook/odber - {"udalost":"novy_odberatel",'*'"email":"sluzba@example.cz"'*) echo "  ok     webhook: nový odběratel";; *) echo "  CHYBA  webhook: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service ecomail eco-klic chyba ''; subscriber_action sync
expect "nepovedený přenos čeká na další pokus s chybou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(pokusy, '|', chyba LIKE 'HTTP 500%', '|', dalsi > '$(site_time)') FROM ka_odber_fronta")" "1|1|1"
case "$(last_request)" in 'POST /ecomail/lists/chyba/subscribe eco-klic '*'"skip_confirmation":true'*) echo "  ok     Ecomail: přihlášení do seznamu";; *) echo "  CHYBA  Ecomail: $(last_request)"; ERRORS=$((ERRORS+1));; esac
mcp uprav_nastaveni '{}' | contains 'newsletter_klic\|eco-klic' && { echo "  CHYBA  MCP ukazuje klíč mailingové služby"; ERRORS=$((ERRORS+1)); } || echo "  ok     klíč mailingové služby MCP neukazuje"
kill "$SERVICE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna LIKE 'newsletter\_%'; DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele"

echo "== webhooks: signature, delivery log and retries (1.8)"
check "Settings → Webhooks shows the secret and the log" 200 "/admin.php?module=settings&tab=webhooks" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'")"
grep -q "<code>nova_poptavka</code>" "$WORK/response" && echo "  ok     the log lists the enquiry call" || { echo "  CHYBA  the delivery log"; ERRORS=$((ERRORS+1)); }
webhook_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=webhooks"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=$1" -d "_csrf=$(csrf)" -d tab=webhooks "${@:2}"; }
: > "$WORK/hook/calls.log"; webhook_action test_webhook
expect "test call signed and logged" "$(hook_check 1)" "test|signed|/crm"
db_q() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
db_q "UPDATE ka_nastaveni SET hodnota = 'https://hooks.example.com/chyba' WHERE promenna = 'webhook_enquiries'"
: > "$WORK/hook/calls.log"; webhook_action test_webhook
FAILED=$(db_q "SELECT MAX(id) FROM ka_webhook_deliveries")
expect "a failed call waits for the next attempt with the reason" "$(db_q "SELECT CONCAT(attempts, '|', status, '|', error, '|', next_attempt > '$(site_time)', '|', body IS NOT NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")" "1|500|HTTP 500|1|1"
for i in 2 3 4 5 6; do db_q "UPDATE ka_webhook_deliveries SET next_attempt = '$(site_time)' WHERE id = $FAILED"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"; done
expect "after six attempts the call is given up but kept for sending again" "$(db_q "SELECT CONCAT(attempts, '|', next_attempt IS NULL, '|', delivered IS NULL, '|', body IS NOT NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")|$(wc -l < "$WORK/hook/calls.log" | tr -d ' ')" "6|1|1|1|6"
check "the given-up call has Send again" 200 "/admin.php?module=settings&tab=webhooks" "name=\"id\" value=\"$FAILED\""
db_q "UPDATE ka_webhook_deliveries SET url = 'https://hooks.example.com/crm' WHERE id = $FAILED"
webhook_action retry_webhook -d "id=$FAILED"
expect "Send again delivers it" "$(db_q "SELECT CONCAT(attempts, '|', status, '|', delivered IS NOT NULL, '|', body IS NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")" "7|204|1|1"
OLD_SECRET=$(db_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'"); webhook_action new_webhook_secret
expect "a new secret replaces the old one" "$(db_q "SELECT hodnota != '$OLD_SECRET' AND hodnota LIKE 'whsec\_%' FROM ka_nastaveni WHERE promenna = 'webhook_secret'")" "1"
mcp site_info '{}' | contains "whsec_" && { echo "  CHYBA  MCP shows the webhook secret"; ERRORS=$((ERRORS+1)); } || echo "  ok     the webhook secret stays out of MCP"
db_q "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('webhook_enquiries', 'webhook_test_url')"
kill "$HOOK_PID" 2>/dev/null || true

echo "== newsletters (fake SMTP server)"
SMTP_PORT=$((PORT + 3)); mkdir -p "$WORK/smtp"
command php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp" > /dev/null 2>&1 & SMTP_PID=$!
db() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
tok() { php -r 'echo bin2hex(random_bytes(16));'; }
# eml <file>: headers, the decoded subject and the decoded text and HTML parts of a captured message
eml() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo $h, "\n"; preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n";
  preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }
  if ($p[1] === [] && preg_match("/^Content-Transfer-Encoding: base64/mi", $h)) { echo base64_decode(preg_replace("/\s+/", "", $b)), "\n"; }' "$1"; }
mail_to() { grep -l "^X-Rcpt-To: $1" "$WORK"/smtp/*.eml 2>/dev/null | tail -1; }
newsletter_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
ANNA=$(tok); PETR=$(tok)
db "UPDATE ka_uzivatele SET email = 'admin@example.cz' WHERE user = 'admin'; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES
  ('anna@example.cz', 1, '$ANNA', '$(site_time)', '$(site_time)'), ('petr@example.cz', 1, '$PETR', '$(site_time)', '$(site_time)'), ('odmitnout@example.cz', 1, '$(tok)', '$(site_time)', '$(site_time)'), ('ceka@example.cz', 0, '$(tok)', '$(site_time)', NULL);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('tasks_last_run', '0')"
check "newsletters: empty list" 200 "/admin.php?module=newsletters" "Napsat newsletter"
check "newsletters: new draft form" 200 "/admin.php?module=newsletters&action=new" 'name="subject"'
newsletter_action save -d id=0 --data-urlencode "subject=Jarní novinky" --data-urlencode "preheader=Co je nového" --data-urlencode $'intro=Dobrý den,\n\nposíláme novinky. Více na https://example.cz/akce' \
  -d news_mode=latest -d news_count=2 --data-urlencode "button_label=Všechny novinky" -d button_url=/novinky
NL=$(db "SELECT id FROM ka_newsletters ORDER BY id DESC LIMIT 1")
expect "newsletter draft saved" "$(db "SELECT CONCAT(status, '|', subject, '|', news_count) FROM ka_newsletters WHERE id = $NL")" "draft|Jarní novinky|2"
check "newsletter: e-mail preview" 200 "/admin.php?module=newsletters&action=preview&id=$NL" "utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=jarni-novinky"
expect "preview: 2 news items, linked address, button and unsubscribe" "$(grep -c 'Číst dál' "$WORK/response")|$(grep -c 'href="https://example.cz/akce"' "$WORK/response")|$(grep -c 'Všechny novinky' "$WORK/response")|$(grep -c 'Odhlásit odběr' "$WORK/response")" "2|1|1|1"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending without an SMTP server" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending while cron does not run" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
check "newsletter form tells why it cannot send" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Cron za posledních 30 minut"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
newsletter_action test -d "id=$NL"
F=$(mail_to admin@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "test e-mail to the signed-in user" "$(grep -c '^Subject-Decoded: \[Zkouška\] Jarní novinky$' "$WORK/eml.txt" 2>/dev/null)" "1"
newsletter_action send -d "id=$NL" -d when=now
expect "sending started for confirmed subscribers only" "$(db "SELECT CONCAT(status, '|', recipients, '|', html LIKE '%{{unsubscribe}}%') FROM ka_newsletters WHERE id = $NL")" "sending|3|1"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "cron sends a batch: 2 delivered, the refused one waits for a retry" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL AND next_attempt > '$(site_time)')) FROM ka_newsletters WHERE id = $NL")" "sending|2|0|1"
F=$(mail_to anna@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "subscriber e-mail: one-click unsubscribe with the own link, no one else's" "$(grep -c "^List-Unsubscribe: <http://127.0.0.1:$PORT/subscription?unsubscribe=$ANNA>" "$WORK/eml.txt")|$(grep -c '^List-Unsubscribe-Post: List-Unsubscribe=One-Click' "$WORK/eml.txt")|$(grep -c "unsubscribe=$ANNA" "$WORK/eml.txt")|$(grep -c "$PETR" "$WORK/eml.txt")" "1|1|3|0"
expect "subscriber e-mail: subject, text part and HTML part" "$(grep -c '^Subject-Decoded: Jarní novinky$' "$WORK/eml.txt")|$(grep -c '^Všechny novinky: http' "$WORK/eml.txt")|$(grep -c '<h1 ' "$WORK/eml.txt")" "1|1|1"
expect "newsletter recipients are not in the mail log" "$(db "SELECT COUNT(*) FROM ka_posta WHERE komu IN ('anna@example.cz', 'petr@example.cz')")" "0"
db "UPDATE ka_newsletter_queue SET next_attempt = '$(site_time)' WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
db "UPDATE ka_newsletter_queue SET next_attempt = '$(site_time)' WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a refused address is given up after three attempts, the newsletter is sent" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', finished_at IS NOT NULL) FROM ka_newsletters WHERE id = $NL")" "sent|2|1|1"
check "newsletters: list with counts" 200 "/admin.php?module=newsletters" "Odesláno"
check "a sent newsletter is read-only" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Příjemci"
contains -c 'name="subject"' "$WORK/response" && { echo "  CHYBA  a sent newsletter can still be edited"; ERRORS=$((ERRORS+1)); } || echo "  ok     a sent newsletter has no form"
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$ANNA" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "one-click unsubscribe from the mail client (RFC 8058) – the older /odber link of an e-mail sent before 3.7" "$(db "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'anna@example.cz'")" "0"

# 3.7: a sign-up through /subscription gets a confirmation e-mail with the English link; confirming and one-click unsubscribing work there too
rm -f "$WORK"/smtp/*.eml
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/subscription" -d "email=en37@example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$NL_SIGNATURE" -d "as_cas=$NL_TIME" -d web_adresa=)
case "$code" in "303 "*"/z-html?subscription=ok#x") echo "  ok     3.7: sign-up through POST /subscription";; *) echo "  CHYBA  3.7: sign-up through /subscription: $code"; ERRORS=$((ERRORS+1));; esac
: > "$WORK/eml.txt"; for i in $(seq 1 25); do F=$(mail_to en37@example.cz || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; grep -q 'potvrdit=' "$WORK/eml.txt" && break; fi; sleep 0.2; done
EN37=$(db "SELECT token FROM ka_odberatele WHERE email = 'en37@example.cz'")
expect "3.7: the confirmation e-mail links to /subscription, not /odber" "$(grep -c "http://127.0.0.1:$PORT/subscription?confirm=$EN37" "$WORK/eml.txt")|$(grep -c '/odber?' "$WORK/eml.txt")" "1|0"
check "3.7: the confirmation link opens at /subscription" 200 "/subscription?confirm=$EN37" "Potvrdit odběr"
contains -q "action=\"/subscription?confirm=$EN37\"" "$WORK/response" && echo "  ok     3.7: … and its button posts back to /subscription" || { echo "  CHYBA  3.7: confirm button action"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null -X POST "$B/subscription?confirm=$EN37"
curl -s -o /dev/null -X POST "$B/subscription?unsubscribe=$EN37" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "3.7: confirmed, then one-click unsubscribed at /subscription" "$(db "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'en37@example.cz'")" "0"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | contains '"name":"draft_newsletter"' && echo "  ok     MCP: newsletter tools listed" || { echo "  CHYBA  MCP: newsletter tools missing"; ERRORS=$((ERRORS+1)); }
db "DELETE FROM ka_odberatele WHERE email LIKE 'odmitnout%'"
mcp draft_newsletter '{"subject":"Novinky přes Clauda","intro":"Ahoj,\n\nkrátká zpráva.","news_mode":"none","button_label":"Kontakt","button_url":"/kontakt"}' > "$WORK/response"
NL2=$(mcp_value id)
expect "MCP: draft_newsletter returns the text version" "$(mcp_value status)|$(mcp_value text | grep -c '^Kontakt: http://127.0.0.1')" "draft|1"
mcp send_test_newsletter "{\"id\":$NL2}" > "$WORK/response"
expect "MCP: test goes to the connected user" "$(mcp_value sent_to)" "admin@example.cz"
mcp send_newsletter "{\"id\":$NL2,\"at\":\"2099-01-01 08:00\"}" > "$WORK/response"
expect "MCP: send_newsletter schedules" "$(mcp_value status)|$(mcp_value scheduled_at)" "scheduled|2099-01-01 08:00"
db "UPDATE ka_newsletters SET scheduled_at = '$(site_time)' - INTERVAL 1 MINUTE WHERE id = $NL2"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a due scheduled newsletter goes out on the next cron call" "$(db "SELECT CONCAT(status, '|', recipients, '|', sent_count) FROM ka_newsletters WHERE id = $NL2")" "sent|1|1"
mcp list_newsletters '{}' > "$WORK/response"
expect "MCP: list_newsletters with subscribers and no sending problem" "$(mcp_value confirmed_subscribers)|$(mcp_value sending_problem)|$(mcp_value newsletters 0 status)" "1|null|sent"
mcp delete_newsletter "{\"id\":$NL2}" > /dev/null
expect "MCP: delete_newsletter" "$(db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $NL2")" "0"
db "UPDATE ka_newsletters SET finished_at = '$(site_time)' - INTERVAL 2 DAY WHERE id = $NL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "recipients are kept only a day after sending" "$(db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL")|$(db "SELECT sent_count FROM ka_newsletters WHERE id = $NL")" "0|2"
check "health: cron check" 200 "/admin.php?module=status" "Cron"
# 2.8: the domain and mail watch shows its group and the Check now button; a site on 127.0.0.1 makes no DNS or network request
grep -q "Doména a pošta" "$WORK/response" && grep -q "action=domain_check" "$WORK/response" && grep -q "běží na místní adrese" "$WORK/response" && echo "  ok     health: domain and mail watch – group, Check now, nothing checked on a local address" || { echo "  CHYBA  health: domain and mail watch group missing"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=domain_check" -d "_csrf=$TOKEN"
check "health: Check now stores the result and reports the local address" 200 "/admin.php?module=status" "Naposledy zkontrolováno"
expect "health: the check result is cached in the domain_watch setting" "$(db "SELECT JSON_EXTRACT(hodnota, '$.local') FROM ka_nastaveni WHERE promenna = 'domain_watch'")" "true"
grep -q "vlastni ve složce layout/" "$WORK/response" && echo "  ok     health: a leftover custom layout is reported" || { echo "  CHYBA  health: leftover custom layout not reported"; ERRORS=$((ERRORS+1)); }
# 3.3.3 (N59): the reset link is queued and sent right after the response – it still arrives at once, and an unknown name leaves no trace
JAR_RESET="$WORK/jar-reset"; rm -f "$JAR_RESET"
reset_request() { curl -s -c "$JAR_RESET" -b "$JAR_RESET" -o "$WORK/response" "$B/admin.php?action=password"; curl -s -b "$JAR_RESET" -c "$JAR_RESET" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=password" -d "_csrf=$(csrf)" -d "kdo=$1"; }
db "DELETE FROM ka_posta; DELETE FROM ka_kontrola_ip WHERE typ = 'obnova'"; rm -f "$WORK"/smtp/*.eml
RESET_KNOWN=$(reset_request admin); sed 's/<[^>]*>//g' "$WORK/response" > "$WORK/reset-known.txt"
# the fake SMTP server writes its file a moment after it accepted the message – wait for it (the queue row already says sent)
: > "$WORK/eml.txt"; for i in $(seq 1 25); do F=$(mail_to admin@example.cz || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; grep -q 'action=password&token=' "$WORK/eml.txt" && break; fi; sleep 0.2; done
expect "3.3.3: the reset link went through the queue and was delivered by the time the answer was complete" \
  "$RESET_KNOWN|$(db "SELECT CONCAT(COUNT(*), '|', SUM(odeslano IS NOT NULL), '|', SUM(telo IS NULL), '|', MIN(pokusu)) FROM ka_posta WHERE komu = 'admin@example.cz'")|$(grep -c 'action=password&token=[a-f0-9]\{64\}' "$WORK/eml.txt")" "200|1|1|1|1|1"
RESET_UNKNOWN=$(reset_request nikdo-takovy); sed 's/<[^>]*>//g' "$WORK/response" > "$WORK/reset-unknown.txt"
expect "3.3.3: an unknown name gets the same page and queues nothing" "$RESET_UNKNOWN|$(cmp -s "$WORK/reset-known.txt" "$WORK/reset-unknown.txt" && echo same)|$(db "SELECT COUNT(*) FROM ka_posta")" "200|same|1"
# 3.9 (owner's idea): Settings → Mail → "Send through" a mail service. A configuration from before 3.9 (no smtp_provider) is shown as
# it is and saving the form unchanged keeps every value; a service fills its server, port and encryption only when the server is not
# already its own; the user name and the password are never touched; the test e-mail still reaches the fake SMTP server
mail_form() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=mail"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=mail -d mail_mode=smtp -d newsletter_hourly_limit=300 -d smtp_password= -d smtp_ses_region=eu-central-1 "$@"; }
mail_smtp() { db "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_host'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_port'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_encryption'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_user'), (SELECT hodnota = '$1' FROM ka_nastaveni WHERE promenna = 'smtp_password'))"; }
OLD_SMTP_PW=$(tok)
db "DELETE FROM ka_nastaveni WHERE promenna = 'smtp_provider'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', 'smtp.firma.example'), ('smtp_port', '465'), ('smtp_encryption', 'ssl'), ('smtp_user', 'web@firma.example'), ('smtp_password', '$OLD_SMTP_PW')"
check "3.9: the Mail tab shows a configuration from before 3.9 as it is" 200 "/admin.php?module=settings&tab=mail" 'value="smtp.firma.example"'
contains -q '<option value="other" selected>' "$WORK/response" && contains -q 'data-smtp-sluzba' "$WORK/response" && contains -q 'data-host="smtp-relay.brevo.com" data-port="587" data-sifrovani="tls"' "$WORK/response" && ! contains -q "$OLD_SMTP_PW" "$WORK/response" \
  && echo "  ok     3.9: … as “Other server”, with every service's server in the choice and the password never in the page" || { echo "  CHYBA  3.9: the mail service choice"; ERRORS=$((ERRORS+1)); }
mail_form -d smtp_provider=other -d smtp_host=smtp.firma.example -d smtp_port=465 -d smtp_encryption=ssl -d smtp_user=web@firma.example
expect "3.9: saving it unchanged keeps the custom server exactly – server, port, encryption, user name and password" "$(mail_smtp "$OLD_SMTP_PW")|$(db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_provider'")" "smtp.firma.example|465|ssl|web@firma.example|1|other"
# a Brevo server on port 2525 (set up before 3.9): shown as Brevo with what Brevo wants as the password, saved with its own port
db "DELETE FROM ka_nastaveni WHERE promenna = 'smtp_provider'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('smtp_host', 'smtp-relay.brevo.com'), ('smtp_port', '2525'), ('smtp_encryption', 'tls'), ('newsletter_service', 'brevo')"
check "3.9: a Brevo server is shown as Brevo, with its SMTP login and key explained" 200 "/admin.php?module=settings&tab=mail" 'SMTP login, který Brevo ukazuje'
contains -q '<option value="brevo" data-host="smtp-relay.brevo.com" data-port="587" data-sifrovani="tls" selected>' "$WORK/response" && contains -q 'Napojení newsletteru používá také Brevo. Jeho API klíč se sem nekopíruje' "$WORK/response" && contains -q 'href="/admin.php?module=extensions#newsletter"' "$WORK/response" && contains -q 'DNS záznamy pro Brevo' "$WORK/response" \
  && echo "  ok     3.9: … the newsletter integration on the same account is named and linked (no key copied), and the DNS records for Brevo follow" || { echo "  CHYBA  3.9: Brevo hint, newsletter link or DNS records"; ERRORS=$((ERRORS+1)); }
check "3.9: Features links the newsletter integration back to Settings → Mail" 200 "/admin.php?module=extensions" 'Vlastní pošta webu jde také přes Brevo'
contains -q 'href="/admin.php?module=settings&amp;tab=mail"' "$WORK/response" && echo "  ok     3.9: … with the link to the Mail tab" || { echo "  CHYBA  3.9: Features → Mail link"; ERRORS=$((ERRORS+1)); }
mail_form -d smtp_provider=brevo -d smtp_host=smtp-relay.brevo.com -d smtp_port=2525 -d smtp_encryption=tls -d smtp_user=web@firma.example
expect "3.9: saving Brevo keeps its own server on port 2525 and the password" "$(mail_smtp "$OLD_SMTP_PW")" "smtp-relay.brevo.com|2525|tls|web@firma.example|1"
# choosing a service without JavaScript: its server, port and encryption are filled; the user name and password stay
mail_form -d smtp_provider=postmark -d smtp_host= -d smtp_port=25 -d smtp_encryption=zadne -d smtp_user=web@firma.example
expect "3.9: choosing Postmark fills its server, port and STARTTLS; the user name and password stay" "$(mail_smtp "$OLD_SMTP_PW")" "smtp.postmarkapp.com|587|tls|web@firma.example|1"
mail_form -d smtp_provider=ses -d smtp_ses_region=eu-west-1 -d smtp_host=smtp.postmarkapp.com -d smtp_port=587 -d smtp_encryption=tls -d smtp_user=web@firma.example
expect "3.9: switching to Amazon SES gives the endpoint of the chosen region" "$(mail_smtp "$OLD_SMTP_PW")" "email-smtp.eu-west-1.amazonaws.com|587|tls|web@firma.example|1"
check "3.9: System status names the mail service" 200 "/admin.php?module=status" 'přes Amazon SES (SMTP server email-smtp.eu-west-1.amazonaws.com)'
mcp site_info '{}' > "$WORK/response"; SITE_MAIL=$(mcp_value mail); contains -q "$OLD_SMTP_PW" "$WORK/response" && SITE_MAIL="leaked"
mcp get_health '{}' > "$WORK/response"; HEALTH_MAIL=$(mcp_value mail_service); contains -q "$OLD_SMTP_PW" "$WORK/response" && HEALTH_MAIL="leaked"
mcp update_settings '{"settings":{"smtp_provider":"brevo","smtp_host":"smtp.evil.example","smtp_password":"x"}}' > /dev/null
expect "3.9: MCP names the service (site_info, get_health), never the password, and cannot change the mail server" "$SITE_MAIL|$HEALTH_MAIL|$(mail_smtp "$OLD_SMTP_PW")|$(db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_provider'")" \
  '{"sending":"smtp","service":"Amazon SES"}|Amazon SES|email-smtp.eu-west-1.amazonaws.com|587|tls|web@firma.example|1|ses'
# back to "Other server" – the fake SMTP server – through the form: the test e-mail arrives there
mail_form -d smtp_provider=other -d smtp_host=127.0.0.1 -d "smtp_port=$SMTP_PORT" -d smtp_encryption=zadne -d smtp_user=
rm -f "$WORK"/smtp/*.eml; SITE_MAILBOX=$(db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=test_mail" -d "_csrf=$(csrf)" -d tab=mail
: > "$WORK/eml.txt"; for i in $(seq 1 25); do F=$(mail_to "$SITE_MAILBOX" || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; break; fi; sleep 0.2; done
expect "3.9: “Other server” saved through the form – the test e-mail reaches the fake SMTP server" "$(mail_smtp "$OLD_SMTP_PW")|$(grep -c 'can send e-mail\|umí odesílat e-maily\|E-Mails senden kann' "$WORK/eml.txt")" "127.0.0.1|$SMTP_PORT|zadne||1|1"
db "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('newsletter_service', 'smtp_password', 'smtp_provider')"
kill "$SMTP_PID" 2>/dev/null || true
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_odberatele; DELETE FROM ka_newsletters; DELETE FROM ka_newsletter_queue"

echo "== média, tokeny DTCG, kolekce přes MCP"
IDOM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
check "média: hledání a řazení" 200 "/admin.php?module=media&search=jpg&sort=velikost" 'data-popis-media='
reply=$(curl -s -b "$JAR" -c "$JAR" -X POST "$B/admin.php?module=media&action=save_caption" -d "_csrf=$TOKEN" -d "ido=$IDOM" --data-urlencode "popis=Dilna zevnitr")
expect "popis obrázku bez znovunačtení" "$reply|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE ido = $IDOM")" '{"ok":true}|Dilna zevnitr'
curl -s -b "$JAR" -o "$WORK/tokeny.json" "$B/admin.php?module=appearance&action=tokens"
grep -q '"\$type": "color"' "$WORK/tokeny.json" && grep -q '"cz.kaleta"' "$WORK/tokeny.json" && echo "  ok     export tokenů DTCG" || { echo "  CHYBA  export tokenů"; ERRORS=$((ERRORS+1)); }
printf '{"color":{"primary":{"$type":"color","$value":"#aa3300"}}}' > "$WORK/cizi.tokens.json"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/cizi.tokens.json"
publish_look
expect "import barev z cizích tokenů" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "#aa3300"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/tokeny.json"
publish_look
expect "import vlastního exportu vrátí vzhled" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) <> '#aa3300' FROM ka_nastaveni WHERE promenna = 'design_system'")" "1"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Mistr truhlář"}' > "$WORK/response"
grep -q 'Petr Svoboda' "$WORK/response" && grep -q 'celkem\\":1' "$WORK/response" && echo "  ok     kolekce přes MCP: filtr podle pole" || { echo "  CHYBA  kolekce přes MCP s filtrem"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDPS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_kolekce_polozky WHERE nazev = 'Petr Svoboda'")
mcp uloz_polozku_kolekce "{\"kolekce\":\"tym\",\"id\":$IDPS,\"data\":{\"funkce\":\"Vedouci dilny\"}}" > /dev/null
expect "kolekce přes MCP: úprava položky bez názvu název zachová" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(nazev, '|', data LIKE '%Vedouci dilny%') FROM ka_kolekce_polozky WHERE idp = $IDPS")" "Petr Svoboda|1"

echo "== 1.9: collection items as pages, structured data, site audit, privacy template, deprecations"
JANA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-novakova' AND jazyk = ''")
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"seo_title\":\"Jana Nováková, jednatelka\",\"description\":\"Vede dílnu dvacet let.\",\"share_image\":\"javascript:x\"}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q '<title>Jana Nováková, jednatelka – ' "$WORK/response" && grep -q '<meta name="description" content="Vede dílnu dvacet let.">' "$WORK/response" && ! grep -q 'javascript:x' "$WORK/response" \
  && echo "  ok     item page: its own SEO title and description, an unsafe image dropped" || { echo "  CHYBA  item SEO fields: $(grep -o '<title>[^<]*' "$WORK/response")"; ERRORS=$((ERRORS+1)); }
mcp list_item_versions "{\"collection\":\"tym\",\"id\":$JANA}" > "$WORK/response"; VER=$(mcp_value versions 0 id)
mcp restore_item_version "{\"collection\":\"tym\",\"id\":$JANA,\"version\":$VER}" > /dev/null
expect "item versions: the earlier version comes back, the newer one goes to the history" "$(sq "SELECT CONCAT(seo_titulek = '', '|', (SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'polozka:$JANA') >= 2) FROM ka_kolekce_polozky WHERE idp = $JANA")" "1|1"
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"noindex\":true}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova" # into a file: grep -q on a pipe would cut curl off (pipefail)
grep -q 'content="noindex' "$WORK/response" && ! curl -s "$B/sitemap.xml" | contains '/tym/jana-novakova' && ! curl -s "$B/llms.txt" | contains '/tym/jana-novakova' \
  && echo "  ok     a noindex item is out of search engines, the sitemap and llms.txt" || { echo "  CHYBA  noindex item"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"noindex\":false}" > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Planovany Clen","publish_at":"2099-01-01 08:00"}' > "$WORK/response"; PLAN=$(mcp_value id)
expect "a scheduled item waits hidden" "$(sq "SELECT CONCAT(zobrazit, '|', zverejnit_od IS NOT NULL) FROM ka_kolekce_polozky WHERE idp = $PLAN")" "0|1"
sq "UPDATE ka_kolekce_polozky SET zverejnit_od = '$(site_time)' - INTERVAL 1 MINUTE WHERE idp = $PLAN" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "the scheduled item publishes itself" "$(sq "SELECT CONCAT(zobrazit, '|', zverejnit_od IS NULL) FROM ka_kolekce_polozky WHERE idp = $PLAN")" "1|1"
IDK_TYM=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
check "the item form has SEO fields, scheduling and the history" 200 "/admin.php?module=collections&action=item&id=$IDK_TYM&item=$JANA" 'Historie položky'
grep -q 'name="seo_titulek"' "$WORK/response" && grep -q 'name="zverejnit_od"' "$WORK/response" && echo "  ok     item form fields" || { echo "  CHYBA  item form fields"; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","structured_data":{"type":"Person","fields":{"jobTitle":"funkce"}}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/tym/zuzana-zelena"
grep -q '"@type":"Person","name":"Zuzana Zelena"' "$WORK/response" && grep -q '"jobTitle":"Jednatelka"' "$WORK/response" \
  && echo "  ok     structured data of a collection: item pages are a Person" || { echo "  CHYBA  collection structured data"; grep -o '"@graph".\{0,600\}' "$WORK/response" | head -c 800; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","structured_data":{"type":"Recipe"}}' | contains 'Unknown structured data type' && echo "  ok     MCP: an unknown schema type is refused" || { echo "  CHYBA  MCP unknown schema type"; ERRORS=$((ERRORS+1)); }
check "the collection form offers structured data" 200 "/admin.php?module=collections&action=edit&id=$IDK_TYM" "Strukturovaná data pro vyhledávače"
mcp list_collections '{}' | contains 'jobTitle' && echo "  ok     MCP: list_collections shows the structured data" || { echo "  CHYBA  list_collections structured data"; ERRORS=$((ERRORS+1)); }
# site audit
mcp create_page '{"title":"Audit test","text":"<p><a href=\"/neexistuje-audit\">x</a> <a href=\"/tym/zuzana-zelena\">ok</a></p>","visible":true}' > /dev/null
check "Administration → Site audit finds a broken internal link" 200 "/admin.php?module=audit" "Odkaz /neexistuje-audit vede na stránku, která neexistuje"
grep -q '/tym/zuzana-zelena vede' "$WORK/response" && { echo "  CHYBA  the audit reports a working item link"; ERRORS=$((ERRORS+1)); } || echo "  ok     a link to an existing item is fine"
mcp site_audit '{"kind":"link"}' > "$WORK/response"
grep -q 'neexistuje-audit' "$WORK/response" && grep -q '\\"page\\":' "$WORK/response" && echo "  ok     MCP: site_audit with the target to fix" || { echo "  CHYBA  MCP site_audit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_list() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'; }
mcp_list | php -r '$t = array_column(json_decode(stream_get_contents(STDIN), true)["result"]["tools"], null, "name"); exit(($t["site_audit"]["annotations"]["readOnlyHint"] ?? false) === true && ($t["restore_item_version"]["annotations"]["readOnlyHint"] ?? true) === false ? 0 : 1);' \
  && echo "  ok     MCP: site_audit is read-only, restore_item_version writes" || { echo "  CHYBA  MCP annotations of 1.9 tools"; ERRORS=$((ERRORS+1)); }
# addresses not found: bots are not recorded, what works again drops out, the warning can be dismissed
sq "DELETE FROM ka_nenalezeno" > /dev/null
for i in 1 2 3; do curl -s -o /dev/null "$B/wp/v2/users"; curl -s -o /dev/null "$B/_next"; curl -s -o /dev/null "$B/stara-cenik-2019"; curl -s -o /dev/null "$B/stary-kontakt"; done
sq "INSERT INTO ka_nenalezeno (cesta, pocet, naposledy) VALUES ('o-nas', 9, '$(site_time)')" > /dev/null
expect "404 log: bot probes are not recorded" "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta IN ('wp/v2/users', '_next')")" "0"
check "the start screen explains the 404 warning and offers to review it" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 2."
grep -q 'module=redirects#nenalezeno' "$WORK/response" && grep -q 'action=ignore_all' "$WORK/response" && echo "  ok     the warning links to the list and can be dismissed" || { echo "  CHYBA  404 warning actions"; ERRORS=$((ERRORS+1)); }
expect "an address that works again drops out of the log" "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta = 'o-nas'")" "0"
check "the 404 list says what to do" 200 "/admin.php?module=redirects" "Ignorovat – nic ji nenahrazuje"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=ignore" -d "_csrf=$(csrf)" -d cesta=stary-kontakt
expect "Ignore hides one address for good" "$(sq "SELECT ignorovano IS NOT NULL FROM ka_nenalezeno WHERE cesta = 'stary-kontakt'")" "1"
curl -s -o /dev/null "$B/stary-kontakt"; check "an ignored address does not come back in the warning" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 1."
mcp ignore_not_found '{"all":true}' > "$WORK/response"
expect "MCP: ignore_not_found dismisses the rest" "$(mcp_value ignored)" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"; grep -q 'skončily „stránka nenalezena“' "$WORK/response" && { echo "  CHYBA  the 404 warning stays after Ignore all"; ERRORS=$((ERRORS+1)); } || echo "  ok     after ignoring, the start screen has no 404 warning"
for i in 1 2 3; do curl -s -o /dev/null "$B/uplne-nova-adresa"; done
check "a new address brings the warning back" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 1."
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=ignore_all" -d "_csrf=$(csrf)" -d zpet=prehled
# privacy policy from the enabled features
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
save_page -d ids=0 --data-urlencode "titulek=Zásady test" -d sablona=zasady -d zobrazit=0 -d v_menu=0 -d text= > /dev/null
expect "privacy template: a disclaimer and only the enabled features" "$(sq "SELECT CONCAT(text LIKE '%nikoli právní rada%', '|', text LIKE '%poptávkovém formuláři%' OR text LIKE '%formuláře%', '|', text LIKE '%[ADDRESS]%' OR text LIKE '%[ADRESA]%' OR text LIKE '%sídlem%') FROM ka_stranky WHERE titulek = 'Zásady test'")" "1|1|1"
# streamed backup download and the media ZIP only on POST
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
LAST_BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ | grep '^kaleta-' | head -1)
curl -s -b "$JAR" -o "$WORK/backup-download" "$B/admin.php?module=settings&action=download_backup&file=$LAST_BACKUP"
expect "a backup downloads whole (streamed)" "$(wc -c < "$WORK/backup-download" | tr -d ' ')" "$(wc -c < "$WORK/web/storage/zalohy/$LAST_BACKUP" | tr -d ' ')"
expect "the media ZIP is not built by a GET" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$B/admin.php?module=settings&action=media_backup" | tr 'A-Z' 'a-z')" "text/html; charset=utf-8"
expect "the media ZIP by POST, with the originals" "$(curl -s -b "$JAR" -o "$WORK/media.zip" -w '%{content_type}' -X POST "$B/admin.php?module=settings&action=media_backup" -d "_csrf=$TOKEN")|$([ "$(unzip -Z1 "$WORK/media.zip" 2>/dev/null | grep -c '^media/')" -gt 0 ] && echo files)" "application/zip|files"

echo "== 3.7: collection categories, previous / next item, the attachment limit of a form"
mcp create_collection '{"name":"Produkty","slug":"produkty","item_pages":true,"fields":[{"label":"Foto","type":"image"},{"label":"Popis","type":"html"}]}' > /dev/null
mcp save_collection_category '{"collection":"produkty","name":"Běžecké pásy","slug":"bezecke-pasy","description":"<p>Pásy pro rehabilitaci chůze.</p><script>alert(1)</script>","seo_description":"Rehabilitační běžecké pásy.","visible":true,"order":10}' > "$WORK/response"
CAT_TOP=$(mcp_value id)
mcp save_collection_category '{"collection":"produkty","name":"Zdravotní pásy","slug":"zdravotni","parent":"bezecke-pasy","visible":true}' > "$WORK/response"; CAT_SUB=$(mcp_value id)
mcp save_collection_category '{"collection":"produkty","name":"Elektroléčba","slug":"elektro","visible":true,"order":20}' > "$WORK/response"; CAT_EL=$(mcp_value id)
expect "3.7 MCP: a category under a top-level one, the description through the allow-list, visible when asked" \
  "$(sq "SELECT CONCAT(c.parent_id = $CAT_TOP, '|', t.description NOT LIKE '%<script%', '|', (SELECT visible FROM ka_collection_categories WHERE id = $CAT_TOP)) FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id WHERE c.id = $CAT_SUB")" "1|1|1"
for i in $(seq -w 1 13); do mcp save_collection_item "{\"collection\":\"produkty\",\"name\":\"Pás $i\",\"slug\":\"pas-$i\",\"visible\":true,\"order\":$((10#$i)),\"categories\":[\"zdravotni\"]}" > /dev/null; done
mcp save_collection_item '{"collection":"produkty","name":"Stimulátor","slug":"stimulator","visible":true,"order":50,"categories":["elektro","neni-takova"]}' > "$WORK/response"
expect "3.7 MCP: an item's categories by slug, an unknown slug reported" "$(mcp_value categories)|$(mcp_value unknown_categories)" '["elektro"]|["neni-takova"]'
check "3.7: a category page lists the items of its subcategories" 200 /produkty/bezecke-pasy '<h1>Běžecké pásy</h1>'
grep -q 'href="/produkty/bezecke-pasy/zdravotni"' "$WORK/response" && grep -q 'href="/produkty/pas-01"' "$WORK/response" && ! grep -q 'href="/produkty/stimulator"' "$WORK/response" \
  && grep -q '"@type":"CollectionPage"' "$WORK/response" && grep -q '"@type":"BreadcrumbList"' "$WORK/response" && grep -q '<link rel="canonical" href="[^"]*/produkty/bezecke-pasy">' "$WORK/response" \
  && grep -q '<meta name="description" content="Rehabilitační běžecké pásy.">' "$WORK/response" && grep -q 'aria-label="Drobečková navigace"\|class="ka-drobecky"' "$WORK/response" \
  && echo "  ok     3.7: subcategory cards, items, CollectionPage, BreadcrumbList, canonical and description" || { echo "  CHYBA  3.7 category page"; ERRORS=$((ERRORS+1)); }
check "3.7: the second page of a category" 200 "/produkty/bezecke-pasy?page=2" 'href="/produkty/pas-13"'
grep -q '<link rel="canonical" href="[^"]*/produkty/bezecke-pasy?page=2">' "$WORK/response" && ! grep -q 'href="/produkty/pas-01"' "$WORK/response" \
  && echo "  ok     3.7: paging with ?page= and its own canonical address" || { echo "  CHYBA  3.7 category paging"; ERRORS=$((ERRORS+1)); }
check "3.7: a page past the last one is a 404" 404 "/produkty/bezecke-pasy?page=9"
check "3.7: a subcategory page has the parent in its breadcrumbs" 200 /produkty/bezecke-pasy/zdravotni '<a href="/produkty/bezecke-pasy">Běžecké pásy</a>'
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/produkty/zdravotni"); expect "3.7: a subcategory at the first level redirects to its own address" "$code" "301 $B/produkty/bezecke-pasy/zdravotni"
check "3.7: a subcategory under another parent is a 404" 404 /produkty/elektro/zdravotni
check "3.7: the address latest is never a category" 404 /produkty/bezecke-pasy/latest
check "3.7: an item page still works next to the categories" 200 /produkty/stimulator '<h1>Stimulátor</h1>'
check "3.7: the sitemap lists category pages" 200 /sitemap.xml '/produkty/bezecke-pasy/zdravotni</loc>'
# collisions: an address is never both a category and an item of the collection
mcp save_collection_category '{"collection":"produkty","name":"Pás","slug":"pas-01"}' | contains 'already used by an item of this collection' \
  && mcp save_collection_item '{"collection":"produkty","name":"Kolize","slug":"elektro"}' | contains 'belongs to a category of this collection' \
  && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Kolize'")" = 0 ] \
  && echo "  ok     3.7 MCP: an address shared by a category and an item is refused both ways" || { echo "  CHYBA  3.7 slug collisions over MCP"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item '{"collection":"produkty","name":"Elektro"}' > "$WORK/response"
expect "3.7: an item address made from the name skips a category address" "$(mcp_value url | sed 's#.*/##')" "elektro-2"
PRODUKTY=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=categories&id=$PRODUKTY"
grep -q 'Zdravotní pásy' "$WORK/response" && grep -q '/produkty/bezecke-pasy/zdravotni' "$WORK/response" && echo "  ok     3.7 admin: the category tree with addresses" || { echo "  CHYBA  3.7 admin categories"; ERRORS=$((ERRORS+1)); }
check "3.7 admin: the category form" 200 "/admin.php?module=collections&action=category&id=$PRODUKTY&category=$CAT_SUB" 'name="parent_id"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_category" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d id=0 --data-urlencode "name=Kolizní" -d slug=pas-02 -d visible=1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_category" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d id=0 --data-urlencode "name=Příslušenství" -d slug=prislusenstvi -d visible=1 -d sort_order=30
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d idp=0 --data-urlencode "nazev=Admin kolize" -d seo_link=prislusenstvi -d zobrazit=1
CAT_ACC=$(sq "SELECT category_id FROM ka_collection_category_texts WHERE idk = $PRODUKTY AND slug = 'prislusenstvi'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d idp=0 --data-urlencode "nazev=Madla" -d seo_link=madla -d poradi=100 -d zobrazit=1 -d kategorie_formular=1 -d "kategorie[]=$CAT_ACC" -d "kategorie[]=$CAT_SUB"
expect "3.7 admin: a clash refused both ways, the ticked categories saved" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_collection_category_texts WHERE slug = 'pas-02'), '|', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Admin kolize'), '|',
  (SELECT GROUP_CONCAT(category_id ORDER BY category_id) FROM ka_collection_item_categories ic JOIN ka_kolekce_polozky p ON p.idp = ic.idp WHERE p.seo_link = 'madla'))")" "0|0|$(printf '%s\n' "$CAT_SUB" "$CAT_ACC" | sort -n | paste -sd, -)"
MADLA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'madla'")
check "3.7 admin: the item form ticks the item's categories" 200 "/admin.php?module=collections&action=item&id=$PRODUKTY&item=$MADLA" "name=\"kategorie\[\]\" value=\"$CAT_ACC\" checked"
# MCP reading: the categories with counts, items filtered by a category (its subcategories included)
mcp list_collection_categories '{"collection":"produkty"}' > "$WORK/response"
expect "3.7 MCP: list_collection_categories – the tree with item counts" "$(mcp_value categories 0 slug)|$(mcp_value categories 0 items)|$(mcp_value categories 1 parent)" "bezecke-pasy|14|bezecke-pasy"
mcp list_collection_items '{"collection":"produkty","category":"bezecke-pasy"}' > "$WORK/response"
expect "3.7 MCP: list_collection_items by a category with its subcategories" "$(mcp_value total)|$(mcp_value items 0 categories)" '14|["zdravotni"]'
# languages: the English texts of a category – its own address, hreflang both ways
mcp save_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_TOP,\"language\":\"en\",\"name\":\"Treadmills\",\"slug\":\"treadmills\"}" > /dev/null
mcp save_collection_item '{"collection":"produkty","name":"Belt EN","slug":"pas-01","language":"en","visible":true,"categories":["treadmills"]}' > /dev/null
check "3.7: the English category page" 200 /en/produkty/treadmills '<h1>Treadmills</h1>'
grep -q 'href="/en/produkty/pas-01"' "$WORK/response" && grep -q 'hreflang="cs" href="[^"]*/produkty/bezecke-pasy"' "$WORK/response" && echo "  ok     3.7: the English items and hreflang to the Czech page" || { echo "  CHYBA  3.7 category in English"; ERRORS=$((ERRORS+1)); }
check "3.7: the Czech page points to its English counterpart" 200 /produkty/bezecke-pasy 'hreflang="en" href="[^"]*/en/produkty/treadmills"'
check "3.7: the sitemap lists the English category" 200 /sitemap.xml '/en/produkty/treadmills</loc>'
# the category template: a draft from MCP is not on the site until it is published
mcp save_build '{"collection":"produkty","category_template":true,"build":{"v":1,"children":[{"type":"section","children":[{"type":"breadcrumbs"},{"type":"heading","tag":"h1","content":{"text":"Kategorie: {{nazev}}"}},{"type":"text","content":{"html":"<p>{{pocet}} produktů</p>"}},{"type":"collection_list","content":{"collection":"produkty","category":"*","count":5,"pagination":true},"children":[{"type":"heading","tag":"h3","content":{"text":"{{nazev}}"}}]}]}]}}' > "$WORK/response"
expect "3.7 MCP: the category template is a draft target of its own" "$(mcp_value category_template)|$(mcp_value status)" "1|draft – shown on the site after publishing"
curl -s "$B/produkty/bezecke-pasy" | contains 'Kategorie: Běžecké' && { echo "  CHYBA  3.7 the draft category template is visible"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.7: the draft of the category template stays hidden"
mcp publish_build '{"collection":"produkty","category_template":true}' > /dev/null
check "3.7: the published category template with {{pocet}}" 200 /produkty/bezecke-pasy '<h1>Kategorie: Běžecké pásy</h1>'
grep -q '<p>14 produktů</p>' "$WORK/response" && grep -q 'aria-label="Stránky výpisu"' "$WORK/response" && grep -q 'href="/produkty/bezecke-pasy?page=2"' "$WORK/response" \
  && echo "  ok     3.7: the item count and the paging of the category's own list" || { echo "  CHYBA  3.7 the category template's list"; ERRORS=$((ERRORS+1)); }
expect "3.7: the category template's versions are kept apart from the item template's" "$(sq "SELECT COUNT(*) FROM ka_collection_category_templates WHERE idk = $PRODUKTY AND stavba LIKE '%Kategorie: {{nazev}}%'")" 1
check "3.7 admin: the category template in the builder" 200 "/admin.php?module=collections&action=builder&id=$PRODUKTY&sablona=kategorie" 'id="stavitel-data"'
# a hidden category has no page and is not in the sitemap; deleting a category with subcategories is refused
mcp save_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_EL,\"visible\":false}" > /dev/null
check "3.7: a hidden category has no page" 404 /produkty/elektro
curl -s "$B/sitemap.xml" | contains '/produkty/elektro<' && { echo "  CHYBA  3.7 a hidden category in the sitemap"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.7: a hidden category is not in the sitemap"
mcp delete_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_TOP}" | contains 'has subcategories' && echo "  ok     3.7 MCP: a category with subcategories is not deleted" || { echo "  CHYBA  3.7 delete with subcategories"; ERRORS=$((ERRORS+1)); }
mcp delete_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_EL}" > /dev/null
expect "3.7 MCP: a deleted category leaves its items in the collection" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_collection_categories WHERE id = $CAT_EL), '|', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link = 'stimulator'))")" "0|1"
# 3.7 security review N37-8: no item takes a category's address – save_collection_items numbers the slug and says so, the same
# row finds that item again; a restore from the trash or of a version keeps the item off the category's address
mcp save_collection_items '{"collection":"produkty","items":[{"name":"Příslušenství 37","slug":"prislusenstvi","values":{}}]}' > "$WORK/response"
N37_ITEM=$(mcp_value results 0 id)
expect "3.7 N37-8: save_collection_items gives an item with a category's address a number and says so" "$(mcp_value results 0 slug)|$(mcp_value results 0 note)" \
  "prislusenstvi-2|The address “prislusenstvi” belongs to a category of this collection, so the item has “prislusenstvi-2”."
mcp save_collection_items '{"collection":"produkty","items":[{"name":"Příslušenství 37","slug":"prislusenstvi","values":{}}]}' > "$WORK/response"
expect "3.7 N37-8: … the same row again finds that item instead of adding another" "$(mcp_value results 0 status)|$(mcp_value results 0 id)|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idk = $PRODUKTY AND seo_link LIKE 'prislusenstvi%'")" "unchanged|$N37_ITEM|1"
mcp save_collection_item '{"collection":"produkty","name":"Trash 37","slug":"trash-37","visible":true}' > /dev/null
T37=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'trash-37'")
mcp delete_collection_item "{\"collection\":\"produkty\",\"id\":$T37}" > /dev/null
sq "UPDATE ka_kolekce_polozky SET seo_link = 'bezecke-pasy' WHERE idp = $T37" > /dev/null # old data: an item in the trash with a category's address
mcp restore_from_trash "{\"type\":\"collection_item\",\"id\":$T37}" > "$WORK/response"
expect "3.7 N37-8: an item back from the trash never takes a category's address" "$(sq "SELECT seo_link FROM ka_kolekce_polozky WHERE idp = $T37")|$(mcp_value slug)" "bezecke-pasy-2|bezecke-pasy-2"
mcp save_collection_item '{"collection":"produkty","name":"Verze 37","slug":"verze-37"}' > /dev/null
VER37=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'verze-37'")
mcp save_collection_item "{\"collection\":\"produkty\",\"id\":$VER37,\"slug\":\"verze-37b\"}" > /dev/null
mcp save_collection_category '{"collection":"produkty","name":"Verze 37","slug":"verze-37","visible":true}' > /dev/null
mcp list_item_versions "{\"collection\":\"produkty\",\"id\":$VER37}" > "$WORK/response"
mcp restore_item_version "{\"collection\":\"produkty\",\"id\":$VER37,\"version\":$(mcp_value versions 0 id)}" > "$WORK/response"
expect "3.7 N37-8: a version whose address a category has now keeps the item's current one, and says so" "$(sq "SELECT seo_link FROM ka_kolekce_polozky WHERE idp = $VER37")|$(mcp_value note)" \
  "verze-37b|The address verze-37 of that version is taken by another item or a category now, so the item keeps verze-37b."
sq "DELETE FROM ka_kolekce_polozky WHERE idp IN ($N37_ITEM, $T37, $VER37); DELETE FROM ka_collection_categories WHERE id = (SELECT category_id FROM ka_collection_category_texts WHERE idk = $PRODUKTY AND slug = 'verze-37')" > /dev/null
# N37-9: without the Collections section a user sees only the categories on the site – a hidden one is neither listed on an
# item nor accepted as a filter (the same answer as an unknown one)
mcp save_collection_category '{"collection":"produkty","name":"Skrytá 37","slug":"skryta-37","visible":false}' > /dev/null
mcp save_collection_item '{"collection":"produkty","id":'"$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $PRODUKTY AND seo_link = 'stimulator'")"',"categories":["skryta-37"]}' > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n37-author', '', 'Author N37', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'n37-author'" > /dev/null
AUTHOR37="kaleta_$(printf '37%.0s' $(seq 1 24))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'author 37', '$(php -r 'echo hash("sha256", $argv[1]);' "$AUTHOR37")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n37-author'" > /dev/null
mcp37() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $AUTHOR37" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"list_collection_items\",\"arguments\":$1}}"; }
expect "3.7 N37-9: list_collection_items hides a hidden category from a user without Collections, also as a filter; the editor sees and filters by it" \
  "$(mcp37 '{"collection":"produkty"}' | grep -c 'skryta-37' || true)|$(mcp37 '{"collection":"produkty","category":"skryta-37"}' | grep -c 'The category is not in this collection' || true)|$(mcp list_collection_items '{"collection":"produkty","category":"skryta-37"}' > "$WORK/response"; mcp_value total)" "0|1|1"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'author 37'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'n37-author'); DELETE FROM ka_uzivatele WHERE user = 'n37-author'" > /dev/null
# N37-10: an address with a trailing newline is no second URL of a category page
check "3.7 N37-10: a category address with a trailing newline is a 404, not the category page" 404 "/produkty/bezecke-pasy/zdravotni%0A"
# N37-7: a form in the English item or category template is found when the English page posts it
mcp create_collection '{"name":"Formy 37","slug":"formy-37","item_pages":true,"fields":[{"label":"Popis","type":"text"}]}' > /dev/null
FORMY37=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'formy-37'")
form37() { php -r 'require $argv[1] . "/system/bootstrap.php"; [$b] = Kaleta\Builder\Build::sanitize(["v" => 1, "deti" => [["id" => "s" . $argv[2], "typ" => "sekce", "deti" => [["id" => $argv[2], "typ" => "formular",
  "obsah" => ["nazev" => "Enquiry 37", "bez_captcha" => true, "pole" => [["popisek" => "Name", "typ" => "text", "povinne" => true]]]]]]]], true); echo Kaleta\Builder\Build::toJson($b);' "$ROOT" "$1"; }
sq "INSERT INTO ka_kolekce_sablony (idk, jazyk, stavba, zmeneno) VALUES ($FORMY37, 'en', '$(form37 f37item)', '$(site_time)');
  INSERT INTO ka_collection_category_templates (idk, jazyk, stavba, zmeneno) VALUES ($FORMY37, 'en', '$(form37 f37cat)', '$(site_time)')" > /dev/null
SECRET37=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")
submit37() { local t; t=$(( $(date +%s) - 30 )); curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/en/form" -d "zdroj=$1" -d "prvek=$2" -d zpet=/en/formy-37/x -d "as_cas=$t" -d p0=Jana \
  -d "as_podpis=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$1|$2|$t" "$SECRET37")"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
expect "3.7 N37-7: a form of the English item template and of the English category template is found in its language" \
  "$(submit37 "kolekce:$FORMY37" f37item | grep -c 'form=f37item&result=ok' || true)|$(submit37 "kategorie:$FORMY37" f37cat | grep -c 'form=f37cat&result=ok' || true)|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE prvek IN ('f37item', 'f37cat')")" "1|1|2"
sq "DELETE FROM ka_poptavky WHERE prvek IN ('f37item', 'f37cat')" > /dev/null; mcp delete_collection '{"collection":"formy-37"}' > /dev/null
# previous / next item: in the collection order, within the item's category, a nav landmark with rel links
mcp save_build '{"collection":"produkty","publish":true,"build":{"v":1,"children":[{"type":"section","children":[{"type":"heading","tag":"h1","content":{"text":"{{nazev}}"}},{"type":"previous_next","content":{"previous_label":"Předchozí produkt","thumbnails":true}}]}]}}' > /dev/null
check "3.7: Previous / next item on an item page" 200 /produkty/pas-02 'aria-label="Předchozí a další položka"'
grep -q '<a class="ka-pd-predchozi" href="/produkty/pas-01" rel="prev">' "$WORK/response" && grep -q '<a class="ka-pd-dalsi" href="/produkty/pas-03" rel="next">' "$WORK/response" && grep -q 'Předchozí produkt' "$WORK/response" \
  && echo "  ok     3.7: the neighbours in the collection order, the own label" || { echo "  CHYBA  3.7 previous / next"; grep -o '<nav class="ka-predchozi-dalsi.\{0,400\}' "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "3.7: previous / next stays within the item's category (not the next item of the collection)" 200 /produkty/pas-13 '<a class="ka-pd-dalsi" href="/produkty/madla" rel="next">'
check "3.7: the first item of a category has no previous one" 200 /produkty/pas-01 'rel="next"'
grep -q 'rel="prev"' "$WORK/response" && { echo "  CHYBA  3.7 a previous item outside the category"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.7: no previous link before the first item of the category"
# the attachment limit of a form (3.7): its own limit, never above what the server accepts
FORM_MB=$(php -r '$b = fn ($v) => (int) $v * (["k" => 1024, "m" => 1048576, "g" => 1073741824][strtolower(substr(trim($v), -1))] ?? 1); $l = array_filter([$b(ini_get("upload_max_filesize")), $b(ini_get("post_max_size"))]); echo intdiv(min(25 * 1048576, $l ? min($l) : PHP_INT_MAX), 1048576);')
mcp create_page '{"title":"Výkresy","slug":"vykresy","visible":true}' > "$WORK/response"; DRAW_PAGE=$(mcp_value id)
draw_form() { mcp stavba_uloz "{\"id\":$DRAW_PAGE,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyk1\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Výkresy\",\"bez_captcha\":true,\"max_priloha\":$1,\"pole\":[{\"popisek\":\"Jméno\",\"typ\":\"text\",\"povinne\":true},{\"popisek\":\"Výkres\",\"typ\":\"soubor\",\"povinne\":false}]}}]}]}}" > /dev/null; }
draw_form 25
check "3.7: the form says the effective limit – its own, at most what the server accepts" 200 /vykresy "Nejvýš $FORM_MB MB: PDF"
head -c 1258291 /dev/zero > "$WORK/vykres.pdf"
draw_submit() { local t; t=$(( $(date +%s) - 30 )); curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=stranka:$DRAW_PAGE" -F prvek=vyk1 -F zpet=/vykresy -F "as_cas=$t" \
  -F "as_podpis=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|stranka:$DRAW_PAGE|vyk1|$t" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")" -F p0=Jana -F "p1=@$WORK/vykres.pdf;type=application/pdf"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null # earlier submissions from this address must not hit the limit
if [ "$FORM_MB" -ge 2 ]; then
  case "$(draw_submit)" in *result=ok*) echo "  ok     3.7: a 1.2 MB drawing passes a form with a higher limit";; *) echo "  CHYBA  3.7 attachment under the limit"; ERRORS=$((ERRORS+1));; esac
  draw_form 1
  check "3.7: the form's own lower limit is shown" 200 /vykresy "Nejvýš 1 MB: PDF"
  case "$(draw_submit)" in *result=pole*) echo "  ok     3.7: the same drawing is refused by a form with a 1 MB limit";; *) echo "  CHYBA  3.7 attachment over the form's limit"; ERRORS=$((ERRORS+1));; esac
fi
expect "3.7: a form without its own limit keeps 10 MB (the default)" "$(php -r 'require $argv[1] . "/system/bootstrap.php"; echo Kaleta\Builder\Elements\Form::attachmentLimit(["max_priloha" => 10]) === min(10 * 1048576, Kaleta\Core\Files::limit() ?: PHP_INT_MAX) ? "ok" : "no", "|", Kaleta\Builder\Build::fresh("formular")["obsah"]["max_priloha"];' "$ROOT")" "ok|10"

echo "== moving a site: import of a Kaleta export into a new installation (1.8)"
mcp write_notebook '{"topic":"history","title":"Historie redesignu","text":"Web přešel na Kaletu v říjnu 2026."}' > /dev/null # 2.15: the notebook moves with the site
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
sq "INSERT INTO ka_booking_services (name) VALUES ('Move test')" > /dev/null # 3.2: a booking set-up travels with the site
N6_PAYLOAD='<p class="n6" onclick="alert(1)">N6 check</p><script>alert(1)</script>' # 3.3.2: an archive from anywhere brings no script
sq "UPDATE ka_novinky SET text = CONCAT(text, '$N6_PAYLOAD') WHERE smazano IS NULL ORDER BY idc LIMIT 1" > /dev/null
# 3.3.3 (N55, N50): an archive with company_map and a social link "javascript:…" and a text fact "javascript:…" – the import drops
# them and keeps a valid link and an ordinary text fact; the settings of this site come back after the export
sq "CREATE TABLE ka_n55_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ('company_map', 'social_facebook', 'social_linkedin');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_map', 'javascript:alert(1)'), ('social_facebook', ' JavaScript:alert(2)'), ('social_linkedin', 'https://www.linkedin.com/company/n55');
  INSERT INTO ka_facts (fact_key, language, label, type, value, updated_at) VALUES ('n55promo', '', 'N55', 'text', 'javascript:alert(3)', '$(site_time)'), ('n55note', '', 'N55', 'text', 'Note: open daily', '$(site_time)')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('company_map', 'social_facebook', 'social_linkedin'); INSERT INTO ka_nastaveni SELECT * FROM ka_n55_backup; DROP TABLE ka_n55_backup;
  DELETE FROM ka_facts WHERE fact_key IN ('n55promo', 'n55note')" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; MOVE_EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.zip' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/presun.zip" "$B/admin.php?module=transfer&action=download&file=$MOVE_EXPORT"
sq "DELETE FROM ka_booking_services WHERE name = 'Move test'" > /dev/null
MEDIA_IN_ZIP=$(unzip -Z1 "$WORK/presun.zip" | grep -c '^media/.')
[ "$MEDIA_IN_ZIP" -gt 0 ] && echo "  ok     the export carries the media ($MEDIA_IN_ZIP files)" || { echo "  CHYBA  no media in the export"; ERRORS=$((ERRORS+1)); }
PORT2=$((PORT + 5)); B2="http://127.0.0.1:$PORT2"; DB2="${DB_NAME}_presun"; JAR_MOVE="$WORK/cookies-presun.txt"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`; CREATE DATABASE \`$DB2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web2" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web2")
mkdir -p "$WORK/web2/media" "$WORK/web2/storage/log" "$WORK/web2/storage/cache"
(cd "$WORK/web2" && exec php -S "127.0.0.1:$PORT2" system/dev-router.php > "$WORK/server2.log" 2>&1) & SERVER2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/install.php" && break; sleep 0.2; done
curl -s -o "$WORK/response" -X POST "$B2/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB2" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Nový web" -d web=export -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky'
grep -q "Pokračovat importem" "$WORK/response" && echo "  ok     installer: Start from an export leads to the import" || { echo "  CHYBA  installer with web=export"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -10; ERRORS=$((ERRORS+1)); }
expect "installer: Start from an export leaves the site empty" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_stranky), '/', (SELECT COUNT(*) FROM ka_novinky), '/', (SELECT COUNT(*) FROM ka_uzivatele))")" "0/0/1"
curl -s -c "$JAR_MOVE" -b "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php"; curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
grep -q 'id="soubor-kaleta"' "$WORK/response" && echo "  ok     an empty site offers Import from Kaleta" || { echo "  CHYBA  Import from Kaleta form"; ERRORS=$((ERRORS+1)); }
location=$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=upload" -F "_csrf=$(csrf)" -F "soubor=@$WORK/presun.zip;type=application/zip")
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$location"
grep -q "Export webu „Testovací firma“" "$WORK/response" && grep -q 'name="potvrzeni"' "$WORK/response" && echo "  ok     preview of the export with counts and a confirmation" || { echo "  CHYBA  preview of the export: $location"; ERRORS=$((ERRORS+1)); }
MOVE_FILE=$(printf '%s' "$location" | sed 's/.*file=//')
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE"
expect "without the confirmation nothing starts" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT COUNT(*) FROM ka_stranky")" "0"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE" -d potvrzeni=1
for i in $(seq 1 80); do
  curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" -X POST "$B2/admin.php?module=transfer&action=kaleta" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE"
  grep -q "Web je naimportovaný\|Import se zastavil" "$WORK/response" && break
done
grep -q "Web je naimportovaný" "$WORK/response" && grep -q 'data-auto-odeslat' "$WORK/response" && { echo "  CHYBA  the result still submits itself"; ERRORS=$((ERRORS+1)); } || true
grep -q "Web je naimportovaný" "$WORK/response" && echo "  ok     the import went through in batches" || { echo "  CHYBA  import: $(sed 's/<[^>]*>//g' "$WORK/response" | grep -i 'import\|chyb' | head -5)"; ERRORS=$((ERRORS+1)); }
move_counts() { "${MYSQL[@]}" "$1" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_stranky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_novinky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_kategorie),
  (SELECT COUNT(*) FROM ka_kolekce), (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_komponenty), (SELECT COUNT(*) FROM ka_tridy), (SELECT COUNT(*) FROM ka_menu),
  (SELECT COUNT(*) FROM ka_popupy), (SELECT COUNT(*) FROM ka_presmerovani), (SELECT COUNT(*) FROM ka_media), (SELECT COUNT(*) FROM ka_novinky_stitky ns JOIN ka_novinky n ON n.idc = ns.idc WHERE n.smazano IS NULL))"; }
expect "the new site has the same content (pages/news/categories/collections/items/components/classes/menus/pop-ups/redirects/media/tags)" "$(move_counts "$DB2")" "$(move_counts "$DB_NAME")"
category_counts() { "${MYSQL[@]}" "$1" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_collection_categories), (SELECT COUNT(*) FROM ka_collection_category_texts), (SELECT COUNT(*) FROM ka_collection_category_templates),
  (SELECT COUNT(*) FROM ka_collection_item_categories ic JOIN ka_kolekce_polozky p ON p.idp = ic.idp WHERE p.smazano IS NULL))"; }
expect "3.7: collection categories, their texts, templates and items moved with the site" "$(category_counts "$DB2")" "$(category_counts "$DB_NAME")"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B2/produkty/bezecke-pasy/zdravotni")" = 200 ] && curl -s "$B2/en/produkty/treadmills" | contains '<h1>Kategorie: Treadmills</h1>' \
  && echo "  ok     3.7: the category pages run on the moved site" || { echo "  CHYBA  3.7 category pages after the move"; ERRORS=$((ERRORS+1)); }
expect "same numbers: home page, site name and the design system came along" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))")" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))")"
expect "3.3.2: news HTML from the export is sanitized, its structure and classes kept" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT(SUM(text LIKE '%<p class=\"n6\">N6 check</p>%'), '/', SUM(text LIKE '%onclick%' OR text LIKE '%<script%')) FROM ka_novinky WHERE text LIKE '%N6 check%'")" "1/0"
sq "UPDATE ka_novinky SET text = REPLACE(text, '$N6_PAYLOAD', '')" > /dev/null
expect "3.3.3 (N55, N50): the import drops javascript: in company_map, a social link and a text fact; a valid link and ordinary text came along" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%javascript:%'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'social_linkedin'), (SELECT GROUP_CONCAT(fact_key ORDER BY fact_key) FROM ka_facts WHERE fact_key LIKE 'n55%'))")" "0|https://www.linkedin.com/company/n55|n55note"
expect "accounts and secrets stay on the new site (users, site address, tokens)" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_uzivatele), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'), (SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('webhook_secret', 'smtp_password') AND hodnota <> ''), (SELECT COUNT(DISTINCT autor) FROM ka_novinky))")" "1/$B2/0/1"
# 3.2: an export made before 3.2 knew Bookings as core – its booking set-up switches the feature on; from 3.2 the export's own choice counts
MOVE_BOOKINGS=$(cd "$ROOT" && php -r 'require "system/bootstrap.php"; echo version_compare(KALETA_VERSION, "3.2.0", "<") ? 1 : 0;')
expect "3.2: the booking set-up came along; Bookings follow the export's version (an export from before 3.2 switches them on)" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_booking_services WHERE name = 'Move test'), '|', (SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'))")" "1|$MOVE_BOOKINGS"
[ "$("${MYSQL[@]}" "$DB2" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")" != "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")" ] && echo "  ok     the new site keeps its own cron address" || { echo "  CHYBA  the cron address came from the old site"; ERRORS=$((ERRORS+1)); }
MOVED_MEDIA=$("${MYSQL[@]}" "$DB2" -N -e "SELECT obr_poloha FROM ka_media ORDER BY ido LIMIT 1")
[ -n "$MOVED_MEDIA" ] && [ -f "$WORK/web2/$MOVED_MEDIA" ] && echo "  ok     the media files are on the new site" || { echo "  CHYBA  media file $MOVED_MEDIA"; ERRORS=$((ERRORS+1)); }
[ -z "$(find "$WORK/web2/media" -name '*.php')" ] && echo "  ok     no PHP came into media/" || { echo "  CHYBA  PHP in media/"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/response" -w '%{http_code}' "$B2/"); [ "$code" = 200 ] && grep -q "Testovací firma" "$WORK/response" && echo "  ok     the moved site runs" || { echo "  CHYBA  moved site: $code"; ERRORS=$((ERRORS+1)); }
expect "2.15: the notebook moved with the site (the note, its topic and author)" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT(COUNT(*), '|', MAX(topic), '|', MAX(title), '|', MAX(author)) FROM ka_notebook")" "1|history|Historie redesignu|test"
SLUG_ITEM=$("${MYSQL[@]}" "$DB2" -N -e "SELECT seo_link FROM ka_stranky WHERE zobrazit = 1 AND smazano IS NULL AND ids <> (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page') ORDER BY ids LIMIT 1")
expect "a moved page looks the same" "$(curl -s "$B2/$SLUG_ITEM" | grep -o '<h1[^>]*>[^<]*' | head -1)" "$(curl -s "$B/$SLUG_ITEM" | grep -o '<h1[^>]*>[^<]*' | head -1)"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
grep -q 'id="soubor-kaleta"' "$WORK/response" && { echo "  CHYBA  a site with content still offers the import form"; ERRORS=$((ERRORS+1)); }
expect "a site with content refuses another import" "$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=kaleta_select" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE" | grep -c 'kaleta')|$(curl -s -b "$JAR_MOVE" -o - "$B2/admin.php?module=transfer&action=kaleta&file=$MOVE_FILE" | grep -c 'name="potvrzeni"')" "1|0"
# 3.7 security review N37-2, N37-10, N37-11: an export with a page on a system address (its subpage, and a later page with
# the first free slug), a category address with a newline, categories against the tree rules, one with an item's address,
# texts in an unknown language and an assignment across collections – imported by the rules of a save, the result says what changed
cp "$WORK/presun.zip" "$WORK/presun37.zip"
OTHER37=$(sq "SELECT COALESCE(MIN(idp), 0) FROM ka_kolekce_polozky WHERE idk <> $PRODUKTY")
php -r '$z = new ZipArchive(); $z->open($argv[1]); $d = json_decode((string) $z->getFromName("obsah.json"), true); [$idk, $sub, $other, $top] = array_map("intval", array_slice($argv, 2));
  $d["stranky"][] = ["ids" => 9701, "titulek" => "Form 37", "seo_link" => "form", "zobrazit" => 1, "text" => "<p>Form 37</p>"];
  $d["stranky"][] = ["ids" => 9702, "titulek" => "Detail 37", "seo_link" => "form/detail", "nadrazena" => 9701, "zobrazit" => 1, "text" => "<p>Detail 37</p>"];
  $d["stranky"][] = ["ids" => 9703, "titulek" => "Form two 37", "seo_link" => "form-2", "zobrazit" => 1, "text" => "<p>Form two 37</p>"];
  $d["collection_categories"][] = ["id" => 9711, "idk" => $idk, "parent_id" => 9711, "visible" => 1];
  $d["collection_categories"][] = ["id" => 9712, "idk" => $idk, "parent_id" => $sub, "visible" => 1];
  $d["collection_category_texts"][] = ["category_id" => 9711, "language" => "", "idk" => $idk, "name" => "Nova 37", "slug" => "imp\n"];
  $d["collection_category_texts"][] = ["category_id" => 9712, "language" => "", "idk" => $idk, "name" => "Kolize 37", "slug" => "stimulator"];
  $d["collection_category_texts"][] = ["category_id" => 9712, "language" => "xx", "idk" => $idk, "name" => "Unknown 37", "slug" => "unknown-37"];
  $d["collection_item_categories"][] = ["idp" => $other, "category_id" => $top];
  $z->addFromString("obsah.json", (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); $z->close();' "$WORK/presun37.zip" "$PRODUKTY" "$CAT_SUB" "$OTHER37" "$CAT_TOP"
kill "$SERVER2_PID" 2>/dev/null || true; wait "$SERVER2_PID" 2>/dev/null || true
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`; CREATE DATABASE \`$DB2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
rm -f "$WORK/web2/config.php"; cp "$ROOT/install.php" "$WORK/web2/install.php"
(cd "$WORK/web2" && exec php -S "127.0.0.1:$PORT2" system/dev-router.php >> "$WORK/server2.log" 2>&1) & SERVER2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/install.php" && break; sleep 0.2; done
curl -s -o /dev/null -X POST "$B2/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB2" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Nový web 37" -d web=export -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky'
rm -f "$JAR_MOVE"; curl -s -c "$JAR_MOVE" -b "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php"; curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
location=$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=upload" -F "_csrf=$(csrf)" -F "soubor=@$WORK/presun37.zip;type=application/zip")
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$location"; MOVE37=$(printf '%s' "$location" | sed 's/.*file=//')
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE37" -d potvrzeni=1
for i in $(seq 1 80); do
  curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" -X POST "$B2/admin.php?module=transfer&action=kaleta" -d "_csrf=$(csrf)" -d "soubor=$MOVE37"
  grep -q "Web je naimportovaný\|Import se zastavil" "$WORK/response" && break
done
expect "3.7 N37-2: an imported page on a system address gets a free slug (not the one a later page has), its subpage moves along, the result says so" \
  "$("${MYSQL[@]}" "$DB2" -N -e "SELECT GROUP_CONCAT(seo_link ORDER BY ids) FROM ka_stranky WHERE ids IN (9701, 9702, 9703)")|$(curl -s -o /dev/null -w '%{http_code}' "$B2/form-3/detail")|$(grep -c 'Adresu /form používá systém, proto stránka dostala /form-3.' "$WORK/response" || true)" \
  "form-3,form-3/detail,form-2|200|1"
expect "3.7 N37-10/N37-11: imported categories by the tree rules – two levels, no newline in an address, no item's address, known languages, one collection" \
  "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT GROUP_CONCAT(COALESCE(parent_id, 'top') ORDER BY id) FROM ka_collection_categories WHERE id IN (9711, 9712)),
    (SELECT GROUP_CONCAT(CONCAT(language, ':', slug) ORDER BY category_id, language) FROM ka_collection_category_texts WHERE category_id IN (9711, 9712)), (SELECT COUNT(*) FROM ka_collection_item_categories WHERE idp = $OTHER37 AND category_id = $CAT_TOP))")|$(grep -c 'adresu stimulator nelze použít' "$WORK/response" || true)" \
  "top,top|:nova-37,:stimulator-2|0|1"
expect "3.7 N37-11: the item keeps its page next to the imported category" "$(curl -s "$B2/produkty/stimulator" | grep -c '<h1>Stimulátor</h1>' || true)|$(curl -s -o /dev/null -w '%{http_code}' "$B2/produkty/stimulator-2")" "1|200"
[ -s "$WORK/web2/storage/log/chyby.log" ] && { echo "  CHYBA  errors on the new site:"; cat "$WORK/web2/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); }
kill "$SERVER2_PID" 2>/dev/null || true; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`"

echo "== záloha a obnova databáze"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$TOKEN"
BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ 2>/dev/null | grep -v predobnovou | head -1 || true)
[ -n "$BACKUP" ] && echo "  ok     záloha vytvořena" || { echo "  CHYBA  záloha nevznikla"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Po zaloze' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BACKUP"
expect "obnova vrátí stav ze zálohy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota <> 'Po zaloze' FROM ka_nastaveni WHERE promenna = 'site_name'")" "1"
BROKEN_BACKUP="kaleta-poskozena.sql"; [[ "$BACKUP" == *.gz ]] && BROKEN_BACKUP="kaleta-poskozena.sql.gz"
if [[ "$BACKUP" == *.gz ]]; then { gzip -dc "$WORK/web/storage/zalohy/$BACKUP" | head -c 4000 || true; } | gzip > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; else head -c 4000 "$WORK/web/storage/zalohy/$BACKUP" > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; fi
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Pred poskozenou' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BROKEN_BACKUP"
expect "poškozená záloha databázi nezmění" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" "Pred poskozenou"

echo "== off-site copies: the database and the media, incrementally (fake S3, 1.8)"
S3_PORT=$((PORT + 6)); mkdir -p "$WORK/s3"
cat > "$WORK/s3/router.php" <<'PHP'
<?php
$h = array_change_key_case(getallheaders());
file_put_contents(__DIR__ . '/puts.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . strlen(file_get_contents('php://input')) . ' ' . (str_starts_with($h['authorization'] ?? '', 'AWS4-HMAC-SHA256 ') ? 'signed' : 'unsigned') . "\n", FILE_APPEND);
http_response_code(200); return true;
PHP
(cd "$WORK/s3" && exec php -S "127.0.0.1:$S3_PORT" router.php > /dev/null 2>&1) & S3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$S3_PORT/ping" && break; sleep 0.2; done; : > "$WORK/s3/puts.log"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDTEST'), ('backup_password', 'tajne-s3'),
  ('backup_folder', 'kaleta-zalohy'), ('backup_region', 'eu-central-1'), ('backup_test_url', 'http://127.0.0.1:$S3_PORT'), ('backup_media', '1'), ('remote_media_status', '')"
rm -f "$WORK/web/storage/zalohy/media-kopie.json"
MEDIA_FILES=$(cd "$WORK/web" && find media -type f ! -name '.*' ! -name '*.php' | wc -l | tr -d ' ')
backup_now() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$(csrf)"; }
backup_now
expect "the backup and every media file uploaded, signed (sql/media/unsigned)" "$(grep -c '^PUT /kaleta-zalohy/kaleta-.*\.sql' "$WORK/s3/puts.log")/$(grep -c '^PUT /kaleta-zalohy/media/' "$WORK/s3/puts.log")/$(grep -c 'unsigned' "$WORK/s3/puts.log")" "1/$MEDIA_FILES/0"
expect "media status: complete" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT SUBSTRING_INDEX(hodnota, '|', -2) FROM ka_nastaveni WHERE promenna = 'remote_media_status'")" "ok|0"
check "Backups show the media copy" 200 "/admin.php?module=settings&tab=backups" "Média: kopie je kompletní"
: > "$WORK/s3/puts.log"; backup_now
expect "the next backup uploads no unchanged media" "$(grep -c '^PUT /kaleta-zalohy/media/' "$WORK/s3/puts.log")" "0"
mkdir -p "$WORK/web/media/2026/09" && echo "novy" > "$WORK/web/media/2026/09/novy-soubor.txt"; : > "$WORK/s3/puts.log"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'media_sync_check'"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "cron copies only the new file" "$(cat "$WORK/s3/puts.log")" "PUT /kaleta-zalohy/media/2026/09/novy-soubor.txt 5 signed"
# a daily backup when something changed, otherwise it waits for the week
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_backups', '1'), ('remote_backup', 'vypnuto'); INSERT INTO ka_protokol (cas, modul, akce) VALUES ('$(site_time)', 'test', 'change')"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*
BEFORE=$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-'); curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "a change since the last backup (older than a day) makes a new automatic one" "$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-')" "$((BEFORE + 1))"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_protokol SET cas = '$(site_time)' - INTERVAL 3 DAY WHERE cas > '$(site_time)' - INTERVAL 3 DAY; UPDATE ka_poptavky SET datum = '$(site_time)' - INTERVAL 3 DAY WHERE datum > '$(site_time)' - INTERVAL 3 DAY"
curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "without a change no new backup before the week is over" "$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-')" "$((BEFORE + 1))"
kill "$S3_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('backup_host', 'backup_test_url', 'backup_password', 'remote_media_status'); UPDATE ka_nastaveni SET hodnota = 'vypnuto' WHERE promenna = 'remote_backup'"

echo "== role přes MCP, obnova hesla, zámek účtu"
SUB_TOKEN2="kaleta_$(printf 'b%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$SUB_TOKEN2")', '$(site_time)' FROM ka_uzivatele WHERE user = 'obchodnik'"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $SUB_TOKEN2" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"vytvor_novinku","arguments":{"titulek":"Od obchodnika","kategorie":"aktuality"}}}' > "$WORK/response"
grep -q 'nemáš přístup' "$WORK/response" && expect "vlastní role bez Novinek nezaloží novinku ani přes MCP" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Od obchodnika'")" "0" || { echo "  CHYBA  MCP bez kontroly sekce Novinky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FAKE_REFRESH="$(printf 'c%.0s' $(seq 1 64))"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET obnova_otisk = '$(php -r 'echo hash("sha256", $argv[1]);' "$FAKE_REFRESH")', obnova_cas = '$(site_time)' + INTERVAL 1 DAY WHERE user = 'obchodnik'"
JAR3="$WORK/jar3"
TOKEN3=$(curl -s -c "$JAR3" "$B/admin.php?action=password&token=$FAKE_REFRESH" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
curl -s -b "$JAR3" -c "$JAR3" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$TOKEN3" -d "token=$FAKE_REFRESH" --data-urlencode "password=Nove-heslo-123" --data-urlencode "password2=Nove-heslo-123"
expect "obnova hesla zruší tokeny napojení" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny t JOIN ka_uzivatele u ON u.idu = t.idu WHERE u.user = 'obchodnik'")" "0"
JAR4="$WORK/jar4"
TOKEN4=$(curl -s -c "$JAR4" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
for i in $(seq 1 10); do curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik -d password=spatne-heslo-xyz; done
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # the per-address limit is reached too – here the account lock alone is tested
code=$(curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123")
expect "po 10 chybách je účet dočasně zamčený i pro správné heslo" "$code|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zamceno_do > '$(site_time)' FROM ka_uzivatele WHERE user = 'obchodnik'")" "401|1"
# 3.3.3 (N51): the locked account answers the right password exactly as any wrong password – no confirmation of the password
N51_LOCKED=$(grep -o 'Chybné jméno nebo heslo, nebo je účet po řadě chybných pokusů dočasně zamčený[^<]*' "$WORK/response" || true)
curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=admin -d password=wrong-for-admin-1; N51_WRONG=$(grep -o 'Chybné jméno nebo heslo[^<]*' "$WORK/response" || true)
curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=nikdo-takovy -d password=whatever-12345; N51_NOBODY=$(grep -o 'Chybné jméno nebo heslo[^<]*' "$WORK/response" || true)
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET zamceno_do = NULL, pocet_chyb = 0, blokovat = 1 WHERE user = 'obchodnik'"
code=$(curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123"); N51_BLOCKED=$(grep -o 'Chybné jméno nebo heslo[^<]*' "$WORK/response" || true)
expect "3.3.3: a locked account, a blocked one, a wrong password and an unknown name get the same answer, which offers the reset" \
  "$([ -n "$N51_LOCKED" ] && [ "$N51_LOCKED" = "$N51_WRONG" ] && [ "$N51_WRONG" = "$N51_NOBODY" ] && [ "$N51_NOBODY" = "$N51_BLOCKED" ] && echo same)|$code|$(printf '%s' "$N51_LOCKED" | grep -c 'obnovte')|$(grep -c 'zablokovaný\|blokován' "$WORK/response")" "same|401|1|0"
# 3.3.3 (N61): the password is read as typed – spaces around it are part of it, as everywhere a password is set
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET blokovat = 0, password = '$(php -r 'echo password_hash(" Mezera-heslo-123 ", PASSWORD_DEFAULT);')' WHERE user = 'obchodnik'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
expect "3.3.3: a password with spaces around it signs in as typed" "$(curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password= Mezera-heslo-123 ")" "302"
# 3.3.3 (N60): the sign-in ends after 8 idle hours or 24 hours in total – the admin keep-alive (action=token) does not extend it
session_shift() { # session_shift <jar> <key> <seconds back>: moves a time in the stored session of the jar
  local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$1" | tail -1); dir=$(php -r '$p = (string) ini_get("session.save_path"); $p = substr($p, (int) strrpos($p, ";") + (str_contains($p, ";") ? 1 : 0)); echo $p !== "" ? $p : sys_get_temp_dir();')
  php -r '$f = $argv[1]; $s = (string) file_get_contents($f); file_put_contents($f, preg_replace("/" . $argv[2] . "\\|i:\\d+;/", $argv[2] . "|i:" . (time() - (int) $argv[3]) . ";", $s));' "$dir/sess_$id" "$2" "$3"
}
expect "3.3.3: a fresh sign-in keeps the keep-alive working" "$(curl -s -b "$JAR4" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=token")|$(grep -c '"csrf"' "$WORK/response")" "200|1"
session_shift "$JAR4" last_seen $((8 * 3600 + 60))
expect "3.3.3: after 8 hours without a request the sign-in is over" "$(curl -s -b "$JAR4" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=token")|$(grep -c '"csrf"' "$WORK/response")|$(grep -c 'name="password"' "$WORK/response")" "200|0|1"
TOKEN4=$(csrf); curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password= Mezera-heslo-123 "
session_shift "$JAR4" login_at $((24 * 3600 + 60))
expect "3.3.3: 24 hours after signing in the keep-alive no longer works, however active the tab was" "$(curl -s -b "$JAR4" -o "$WORK/response" "$B/admin.php?action=token"; grep -c '"csrf"' "$WORK/response")|$(grep -c 'name="password"' "$WORK/response")" "0|1"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"

echo "== 2.8: security hygiene – unused accounts and Claude connections, automatic suspension"
# an administrator and an editor nobody has used for 100 days, an old personal token of the editor, an unused token of the admin created 70 days ago and a token used today
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('stary-spravce', '\$2y\$12\$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu', 'Stary Spravce', 2, '$(site_time)' - INTERVAL 100 DAY, '$(site_time)' - INTERVAL 100 DAY), ('stary-editor', '\$2y\$12\$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu', '', 1, '$(site_time)' - INTERVAL 100 DAY, '$(site_time)' - INTERVAL 100 DAY);
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, pouzit) SELECT idu, 'stary token', SHA2('hygiene-old', 256), '$(site_time)' - INTERVAL 100 DAY, '$(site_time)' - INTERVAL 100 DAY FROM ka_uzivatele WHERE user = 'stary-editor';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, expirace) SELECT idu, 'nepouzity token', SHA2('hygiene-unused', 256), '$(site_time)' - INTERVAL 70 DAY, '$(site_time)' + INTERVAL 1 YEAR FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, pouzit, expirace) SELECT idu, 'zivy token', SHA2('hygiene-live', 256), '$(site_time)' - INTERVAL 70 DAY, '$(site_time)', '$(site_time)' + INTERVAL 1 YEAR FROM ka_uzivatele WHERE user = 'admin'"
check "System status lists the unused accounts and connections with links" 200 "/admin.php?module=status" "Nepoužívané účty"
grep -q 'Stary Spravce (poslední aktivita' "$WORK/response" && grep -q 'stary-editor (poslední aktivita' "$WORK/response" && grep -q 'stary token (stary-editor)' "$WORK/response" && grep -q 'nepouzity token (Tester)' "$WORK/response" && ! grep -q 'zivy token (Tester)' "$WORK/response" \
  && grep -q 'vypnuto – nepoužívané účty a napojení se jen hlásí' "$WORK/response" && echo "  ok     System status: two unused accounts, two unused connections, the live token is fine, suspension off" || { echo "  CHYBA  System status hygiene findings"; grep -o 'Účty a přístup.*' "$WORK/response" | head -c 1500; ERRORS=$((ERRORS+1)); }
check "the settings form offers the automatic suspension" 200 "/admin.php?module=settings&tab=general" 'name="auto_suspend\[\]"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"
contains -qE '"handover": ?"unused_account"' "$WORK/text" && contains -qE '"handover": ?"unused_connection"' "$WORK/text" && contains -qE '"handover": ?"auto_suspend"' "$WORK/text" && echo "  ok     site audit: unused accounts and connections, suspension off, before handing over" || { echo "  CHYBA  hand-over audit hygiene"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the daily run (the 2.8 scheduler calls SecurityHygiene::run once a day): here straight from the command line against the test site
run_hygiene() { php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo json_encode(Kaleta\Core\SecurityHygiene::run($app));' "$WORK/web"; }
expect "run() does nothing while the automatic suspension is off" "$(run_hygiene)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_uzivatele WHERE blokovat = 1 AND user LIKE 'stary-%'")" '{"blocked":[],"revoked":[]}|0'
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_suspend', 'ucty,napojeni')"
expect "run() blocks the unused accounts and revokes the unused connections" "$(run_hygiene)" '{"blocked":["stary-editor","Stary Spravce"],"revoked":["stary token (stary-editor)","nepouzity token (Tester)"]}'
expect "the admin in use stays, the old accounts are blocked with the reason, the live token stays" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT CONCAT(blokovat, blokovano_automaticky IS NULL) FROM ka_uzivatele WHERE user = 'admin'), '|', (SELECT GROUP_CONCAT(CONCAT(user, ':', blokovat, ':', blokovano_automaticky IS NOT NULL) ORDER BY user) FROM ka_uzivatele WHERE user LIKE 'stary-%'), '|', (SELECT GROUP_CONCAT(nazev ORDER BY nazev) FROM ka_api_tokeny WHERE nazev LIKE '%token'))")" "01|stary-editor:1:1,stary-spravce:1:1|zivy token"
expect "every automatic action is in the change log" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users' AND akce = 'auto_block'), '/', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'auto_revoke'))")" "2/2"
expect "every automatic action is an event without names (alerts, list_events)" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'security.account_suspended'), '/', (SELECT COUNT(*) FROM ka_events WHERE type = 'security.connection_revoked'))")" "2/2"
expect "a blocked account cannot use its token" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer hygiene-old" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"
check "Users shows why the account is blocked" 200 "/admin.php?module=users" "Zablokován automaticky"
grep -q '(zablokován automaticky)' "$WORK/response" && grep -q 'action=reactivate' "$WORK/response" && echo "  ok     Users: the automatic block is labelled and can be reactivated" || { echo "  CHYBA  Users list without the automatic block"; ERRORS=$((ERRORS+1)); }
IDS_OLD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'stary-editor'")
check "the user form explains the automatic block" 200 "/admin.php?module=users&action=edit&id=$IDS_OLD" "Odškrtněte políčko a uložte"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=reactivate" -d "_csrf=$TOKEN" -d "idu=$IDS_OLD" -d user=stary-editor
expect "reactivation unblocks the account and confirms it" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(blokovat, blokovano_automaticky IS NULL, potvrzeno > '$(site_time)' - INTERVAL 1 MINUTE) FROM ka_uzivatele WHERE user = 'stary-editor'")" "011"
expect "a reactivated account is not blocked again by the next run" "$(run_hygiene)" '{"blocked":[],"revoked":[]}'
IDS_ADMIN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
IDT_LIVE=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_api_tokeny WHERE nazev = 'zivy token'")
check "the administrator sees the connections of an account" 200 "/admin.php?module=users&action=edit&id=$IDS_ADMIN" 'id="napojeni"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=revoke_connection" -d "_csrf=$TOKEN" -d "idu=$IDS_ADMIN" -d "idt=$IDT_LIVE" -d user=admin
expect "the administrator revokes a connection from the user form" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE nazev = 'zivy token'")" "0"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'auto_suspend'"

echo "== OAuth pro konektor Claude"
curl -s -o "$WORK/response" -D "$WORK/hlavicky" -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}'
grep -qi 'www-authenticate: Bearer resource_metadata="http://127.0.0.1:[0-9]*/.well-known/oauth-protected-resource"' "$WORK/hlavicky" && echo "  ok     MCP bez tokenu odkáže na metadata OAuth" || { echo "  CHYBA  WWW-Authenticate u MCP"; ERRORS=$((ERRORS+1)); }
check "metadata chráněného zdroje" 200 "/.well-known/oauth-protected-resource" '"authorization_servers"'
check "metadata autorizačního serveru" 200 "/.well-known/oauth-authorization-server" '"code_challenge_methods_supported":\["S256"\]'
REDIRECT_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude\",\"redirect_uris\":[\"$REDIRECT_URI\"],\"token_endpoint_auth_method\":\"none\"}"
CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$CLIENT" ] && echo "  ok     dynamická registrace klienta" || { echo "  CHYBA  registrace klienta"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "registrace odmítne http adresu návratu" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["http://zly.example/cb"]}')" 400
VERIFIER="$(printf 'v%.0s' $(seq 1 50))"; CHALLENGE=$(printf %s "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
expect "cizí adresa návratu se nepřesměruje" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=https://zly.example/&code_challenge=$CHALLENGE&code_challenge_method=S256")" 400
# 3.3.2 (N8): a request without the code flow or PKCE is answered here, never redirected to the registered address
expect "3.3.2 OAuth: an error before consent is a page, not a redirect" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=token&client_id=$CLIENT&redirect_uri=$REDIRECT_URI")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=x")" "400 |400 "
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=xyz&scope=mcp")
# 3.3.4 (N14): the consent page names the request it belongs to; the form sends that nonce back
oauth_nonce() { printf %s "$1" | grep -o 'request=[a-f0-9]*' | sed 's/request=//' || true; }
# oauth_allow <authorize query> [POST fields…]: a sign-in request, then the consent for exactly that request
oauth_allow() { local to; to=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$1"); shift
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d "request=$(oauth_nonce "$to")" "$@"; }
case "$code" in "302 "*"action=oauth&request="*) echo "  ok     přihlášení vede na souhlas v administraci";; *) echo "  CHYBA  authorize: $code"; ERRORS=$((ERRORS+1));; esac
OAUTH_NONCE=$(oauth_nonce "$code")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/hlavicky" "$B/admin.php?action=oauth"; grep -q 'Povolit přístup' "$WORK/response" && grep -q "name=\"request\" value=\"$OAUTH_NONCE\"" "$WORK/response" && echo "  ok     stránka souhlasu" || { echo "  CHYBA  stránka souhlasu"; ERRORS=$((ERRORS+1)); }
grep -qi "form-action 'self' https://claude.ai;" "$WORK/hlavicky" && ! grep -qi "x-kaleta-form-action" "$WORK/hlavicky" && echo "  ok     CSP souhlasu povolí návrat do aplikace (form-action)" || { echo "  CHYBA  CSP form-action na stránce souhlasu"; grep -i "content-security" "$WORK/hlavicky"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d "request=$OAUTH_NONCE" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
case "$REDIRECT" in "$REDIRECT_URI?code="*"state=xyz"*) echo "  ok     souhlas vrátí kód a state do aplikace";; *) echo "  CHYBA  návrat po souhlasu: $REDIRECT"; ERRORS=$((ERRORS+1));; esac
expect "špatný code_verifier (PKCE) neprojde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d code_verifier=spatny-overovac-spatny-overovac-spatny-overovac)" 400
REDIRECT=$(oauth_allow "response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=abc" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
ACCESS_TOKEN=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true); REFRESH_TOKEN=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$ACCESS_TOKEN" ] && [ -n "$REFRESH_TOKEN" ] && echo "  ok     výměna kódu za tokeny (PKCE)" || { echo "  CHYBA  token endpoint"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "kód jde použít jen jednou" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER")" 400
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACCESS_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q 'builder_schema' "$WORK/response" && echo "  ok     MCP s přístupovým tokenem z OAuth" || { echo "  CHYBA  MCP s tokenem OAuth"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "obnovovací token nejde použít k MCP" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $REFRESH_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" 401
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT"
grep -q '"access_token"' "$WORK/response" && echo "  ok     obnova tokenu" || { echo "  CHYBA  obnova tokenu"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.3.4 (N13): the refresh token is exchanged for a new one; a retry with the old one within REFRESH_GRACE gets the same
# new pair (it used to be refused at once – the reuse after the grace period is tested in the 3.3.4 section)
REFRESHED=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "obnovovací token se po použití vymění" "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = SHA2('$REFRESH_TOKEN', 256)")|$([ -n "$REFRESHED" ] && [ "$REFRESHED" != "$REFRESH_TOKEN" ] && echo new)" "0|new"
expect "3.3.4: a retry of the same refresh within the grace period returns the same new pair" "$(curl -s -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT" | grep -o '"refresh_token":"[a-z0-9_]*"' | sed 's/.*:"//;s/"//' || true)" "$REFRESHED"

echo "== 3.3.4: OAuth – consent bound to its request, a clearer consent, races on codes and refresh (N14, N65, N13, N66, N20)"
# register_client <name> <redirect_uri>: a public client (PKCE only) as Claude registers it; prints its client_id
register_client() { curl -s -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"$1\",\"redirect_uris\":[\"$2\"],\"token_endpoint_auth_method\":\"none\"}" | grep -o '"client_id":"[a-f0-9]*"' | sed 's/.*:"//;s/"//' || true; }
authorize_query() { printf 'response_type=code&client_id=%s&redirect_uri=%s&code_challenge=%s&code_challenge_method=S256&state=%s' "$1" "$2" "$CHALLENGE" "$3"; }
URI_A="https://claude.ai/api/mcp/auth_callback"; URI_B="https://claude.ai.evil.example/api/mcp/auth_callback"
CLIENT_A=$(register_client "Claude" "$URI_A"); CLIENT_B=$(register_client "Claude" "$URI_B")
# N14: the genuine consent page for A is open; a later top-level GET to /oauth/authorize for B in the same session (the
# session cookie is SameSite=Lax) must not change where "Allow" on A's page sends the code
NONCE_A=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "$URI_A" n14a)")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_A"; cp "$WORK/response" "$WORK/consent-a"; CSRF_A=$(csrf)
NONCE_B=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$CLIENT_B" "$URI_B" n14b)")")
[ -n "$NONCE_A" ] && [ -n "$NONCE_B" ] && [ "$NONCE_A" != "$NONCE_B" ] && echo "  ok     3.3.4 N14: each sign-in request gets its own nonce" || { echo "  CHYBA  N14 nonces: $NONCE_A / $NONCE_B"; ERRORS=$((ERRORS+1)); }
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d "request=$NONCE_A" -d povolit=1)
case "$REDIRECT" in "$URI_A?code="*"state=n14a"*) echo "  ok     3.3.4 N14: Allow on A's consent page sends the code to A's redirect_uri, even after a request for B";; *) echo "  CHYBA  N14: the consent went to $REDIRECT"; ERRORS=$((ERRORS+1));; esac
expect "3.3.4 N14: B got no code – it still needs its own consent" "$(sq "SELECT COUNT(*) FROM ka_oauth_kody WHERE client_id = '$CLIENT_B'")|$(sq "SELECT COUNT(*) FROM ka_oauth_kody WHERE client_id = '$CLIENT_A'")" "0|1"
expect "3.3.4 N14: a consent without its request is refused" "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d povolit=1)|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d "request=$NONCE_A" -d povolit=1)" "400|400"
# N65: B's consent (an unknown host) leads with the host, warns, marks the client as new and preselects drafts only;
# A's (claude.ai) has no warning and keeps full access as the default
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_B"
contains -q '<strong>claude.ai.evil.example</strong>' "$WORK/response" && contains -q 'claude.ai.evil.example není adresa vlastních aplikací Claude' "$WORK/response" && contains -q 'nová, neověřená' "$WORK/response" \
  && contains -q 'value="drafts" checked' "$WORK/response" && ! contains -q 'value="full" checked' "$WORK/response" && contains -q 'Co smí aplikace dělat?' "$WORK/response" && ! contains -q 'Claude staví' "$WORK/response" \
  && echo "  ok     3.3.4 N65: a consent for an unknown host shows the host, the warning, the new mark and drafts only, and does not speak of Claude" || { echo "  CHYBA  N65 consent for an unknown host"; sed 's/<[^>]*>/ /g' "$WORK/response" | grep -i 'evil\|claude' | head -5; ERRORS=$((ERRORS+1)); }
contains -q '<strong>claude.ai</strong>' "$WORK/consent-a" && ! contains -q 'není adresa vlastních aplikací Claude' "$WORK/consent-a" && contains -q 'value="full" checked' "$WORK/consent-a" && contains -q 'Co smí Claude dělat?' "$WORK/consent-a" \
  && echo "  ok     3.3.4 N65: a consent for claude.ai shows no warning and keeps full access as the default" || { echo "  CHYBA  N65 consent for claude.ai"; ERRORS=$((ERRORS+1)); }
expect "3.3.4 N65: a client allowed once is approved, the other is not" "$(sq "SELECT GROUP_CONCAT(approved IS NOT NULL ORDER BY client_id = '$CLIENT_A' DESC) FROM ka_oauth_klienti WHERE client_id IN ('$CLIENT_A', '$CLIENT_B')")" "1,0"
NONCE_A2=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "$URI_A" again)")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_A2"
contains -q 'Povolit přístup' "$WORK/response" && ! contains -q 'nová, neověřená' "$WORK/response" && echo "  ok     3.3.4 N65: a client this site approved before is not marked as new" || { echo "  CHYBA  N65 approved client marked as new"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d "request=$NONCE_A2" -d povolit=0
# N65: "Only allow Claude's own apps to connect" refuses registration and sign-in outside Claude's hosts
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('claude_apps_only', '1') ON DUPLICATE KEY UPDATE hodnota = '1'" > /dev/null
expect "3.3.4 N65: with claude_apps_only a foreign host cannot register or sign in, Claude and Claude Code can" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["https://evil.example/cb"]}')|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_B" "$URI_B" only)")|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["https://claude.ai/api/mcp/auth_callback","http://localhost:33418/callback"]}')|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "$URI_A" only)")" "400|403|201|302"
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'claude_apps_only'" > /dev/null
# 3.8 (Connectors Directory): Claude Code registers a loopback redirect and comes back on another port next time (RFC 8252
# §7.3 – any port for a loopback address); a different path or a non-loopback host with another port is still refused
CLIENT_CC=$(register_client "Claude Code" "http://127.0.0.1:33418/callback")
expect "3.8 OAuth: a loopback redirect_uri on another port goes to the consent, another path or host does not" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_CC" "http://127.0.0.1:51234/callback" cc)")|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_CC" "http://127.0.0.1:51234/other" cc)")|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "https://claude.ai:8443/api/mcp/auth_callback" cc)")" "302|400|400"
# N13: six parallel redemptions of one code give one token pair; a second server on the same files with six workers runs
# them at the same time, as PHP-FPM would
RACE_PORT=$((PORT + 17)); RACE="http://127.0.0.1:$RACE_PORT"
(cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=6 exec php -S "127.0.0.1:$RACE_PORT" system/dev-router.php > /dev/null 2>&1) & RACE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$RACE/" && break; sleep 0.2; done
CLIENT_R=$(register_client "Claude race" "$URI_A")
CODE_R=$(oauth_allow "$(authorize_query "$CLIENT_R" "$URI_A" race)" -d povolit=1 | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
RACERS=(); for i in 1 2 3 4 5 6; do curl -s -o "$WORK/race-code-$i" -X POST "$RACE/oauth/token" -d grant_type=authorization_code -d "code=$CODE_R" -d "redirect_uri=$URI_A" -d "client_id=$CLIENT_R" -d "code_verifier=$VERIFIER" & RACERS+=($!); done; wait "${RACERS[@]}" || true
expect "3.3.4 N13: six parallel redemptions of one code issue exactly one token pair" "$(cat "$WORK"/race-code-* | grep -o '"refresh_token"' | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")" "1|1"
REFRESH_R=$(cat "$WORK"/race-code-* | grep -o '"refresh_token":"[a-z0-9_]*"' | sed 's/.*:"//;s/"//' || true)
RACERS=(); for i in 1 2 3 4 5 6; do curl -s -o "$WORK/race-refresh-$i" -X POST "$RACE/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R" -d "client_id=$CLIENT_R" & RACERS+=($!); done; wait "${RACERS[@]}" || true
# the requests that lost the race fall within the grace period: they get the very pair the winner got, never a pair of their own
expect "3.3.4 N13: six parallel refreshes with one refresh token issue exactly one new pair, and one live refresh token remains" "$(cat "$WORK"/race-refresh-* | grep -o '"refresh_token":"[a-z0-9_]*"' | sort -u | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'pristup' AND expirace > '$(site_time)'")" "1|1|2"
expect "3.9: a refreshed Claude connection stays signed in for a year (like a personal token), the access token for an hour" "$(sq "SELECT DATEDIFF(MAX(expirace), '$(site_date now)') BETWEEN 364 AND 366 FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")|$(cat "$WORK"/race-refresh-* | grep -o '"expires_in":[0-9]*' | sort -u)" '1|"expires_in":3600'
pkill -P "$RACE_PID" 2>/dev/null || true; kill "$RACE_PID" 2>/dev/null || true # the workers first, they outlive their parent
REFRESH_R2=$(cat "$WORK"/race-refresh-* | grep -o '"refresh_token":"[a-z0-9_]*"' | head -1 | sed 's/.*:"//;s/"//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R2" -d "client_id=$CLIENT_R"
REFRESH_R3=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$REFRESH_R3" ] && [ "$REFRESH_R3" != "$REFRESH_R2" ] && echo "  ok     3.3.4 N13: the new refresh token refreshes normally" || { echo "  CHYBA  N13 refresh after the race"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
# reuse of a rotated refresh token after the grace period counts as theft: every token of the client for the user goes
sq "UPDATE ka_oauth_rotated SET rotated_at = '$(site_time)' - INTERVAL 1 MINUTE WHERE client_id = '$CLIENT_R'" > /dev/null
expect "3.3.4 N13: a rotated refresh token used after the grace period is refused and revokes the client's tokens, recorded as an event" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R" -d "client_id=$CLIENT_R")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'security.token_reuse' AND data LIKE '%$CLIENT_R%'")|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R3" -d "client_id=$CLIENT_R")" "400|0|1|400"
# N20: a code_verifier shorter than 43 characters is refused, even when it matches its challenge
SHORT_VERIFIER="short-verifier-of-thirty-one-ch"; SHORT_CHALLENGE=$(printf %s "$SHORT_VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
CODE_S=$(oauth_allow "response_type=code&client_id=$CLIENT_A&redirect_uri=$URI_A&code_challenge=$SHORT_CHALLENGE&code_challenge_method=S256&state=short" -d povolit=1 | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
expect "3.3.4 N20: a code_verifier of fewer than 43 characters is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$CODE_S" -d "redirect_uri=$URI_A" -d "client_id=$CLIENT_A" -d "code_verifier=$SHORT_VERIFIER")" 400
# N66: the daily job removes registrations nobody approved a day after registration; approved clients and clients with
# tokens or codes stay
CLIENT_OLD=$(register_client "Never approved" "https://evil.example/cb")
sq "UPDATE ka_oauth_klienti SET vytvoren = '$(site_time)' - INTERVAL 2 DAY WHERE client_id IN ('$CLIENT_OLD', '$CLIENT_A', '$CLIENT_B', '$CLIENT')" > /dev/null
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'security'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "3.3.4 N66: an unused registration older than a day is deleted, approved clients stay" "$(sq "SELECT COUNT(*) FROM ka_oauth_klienti WHERE client_id = '$CLIENT_OLD'")|$(sq "SELECT COUNT(*) FROM ka_oauth_klienti WHERE client_id IN ('$CLIENT_A', '$CLIENT')")|$(grep -c 'unused app registrations removed' "$WORK/tasks.txt")" "0|2|1"

echo "== 2.2: connection access, change log, instructions, prompts, settings over MCP"
expect "an OAuth connection approved without a choice (a consent page from before 2.2) has full access" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(DISTINCT access) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "full"
REDIRECT=$(oauth_allow "response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=drafts" -d povolit=1 -d access=drafts)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
REFRESH_DRAFTS=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_DRAFTS" -d "client_id=$CLIENT"
OAUTH_DRAFTS=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "drafts only chosen on the consent screen stays after a token refresh" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT access FROM ka_api_tokeny WHERE otisk = SHA2('$OAUTH_DRAFTS', 256)")" "drafts"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $OAUTH_DRAFTS" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
contains -q '"name":"save_build"' "$WORK/response" && ! contains -q '"name":"publish_build"' "$WORK/response" && ! contains -q '"name":"update_settings"' "$WORK/response" && echo "  ok     a drafts-only connection lists only reads and draft tools" || { echo "  CHYBA  tools/list for drafts"; ERRORS=$((ERRORS+1)); }
# personal tokens with limited access (My account)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; ACCOUNT_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude read" -d access=read
READ_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude drafts" -d access=drafts
DRAFT_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
expect "tokens from My account keep the chosen access" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(access ORDER BY nazev) FROM ka_api_tokeny WHERE nazev IN ('Claude read', 'Claude drafts')")" "drafts,read"
mcp_text() { php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"; } # the tool result itself
mcp_as() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
mcp_as "$READ_TOKEN" create_page '{"title":"From a read-only connection"}' > "$WORK/response"
contains -q 'can only read the site' "$WORK/response" && expect "a read-only connection changes nothing" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'From a read-only connection'")" 0 || { echo "  CHYBA  read-only connection wrote"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$READ_TOKEN" get_page '{"id":1}' > "$WORK/response"; contains -q '"isError":true' "$WORK/response" && { echo "  CHYBA  a read-only connection cannot read"; ERRORS=$((ERRORS+1)); } || echo "  ok     a read-only connection reads"
# N35-2: text from a drafts-only connection never goes live by itself – a later unrelated edit by a publisher keeps the page hidden
mcp create_page '{"title":"Waiting for drafts","slug":"waiting-drafts","visible":true}' > /dev/null
WAIT_DR=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'waiting-drafts'")
mcp_as "$DRAFT_TOKEN" update_page "{\"id\":${WAIT_DR:-0},\"text\":\"<p>Text from a drafts-only connection</p>\"}" > /dev/null
mcp update_page "{\"id\":${WAIT_DR:-0},\"description\":\"Only the description changes\"}" > /dev/null
expect "3.5 N35-2: drafts-only text in a waiting page stays hidden after a publisher's unrelated edit" \
  "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', text LIKE '%drafts-only%') FROM ka_stranky WHERE ids = ${WAIT_DR:-0}")" "0/0/1"
mcp_as "$DRAFT_TOKEN" create_page '{"title":"Drafted by Claude","visible":true}' > "$WORK/response"
mcp_text; DRAFT_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://' || true)
expect "a drafts-only connection creates a page, but hidden" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}'")" 0
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft\"}}]}]}}" > "$WORK/response"
contains -q 'Publishing needs' "$WORK/response" && expect "publishing over a drafts-only connection is refused before anything is saved" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COALESCE(stavba_koncept, 'none') FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}'")" "none" || { echo "  CHYBA  save_build publish over drafts"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft\"}}]}]}}" > "$WORK/response"
mcp_text; contains -q '"status":"draft' "$WORK/text" && echo "  ok     a drafts-only connection saves a draft build" || { echo "  CHYBA  drafts: save_build"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" update_settings '{"settings":{"site_name":"Hijacked"}}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     a drafts-only connection does not change settings" || { echo "  CHYBA  drafts: update_settings"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 2.5.1: an administrator's drafts-only connection must not reach the administrator's browser through a draft preview
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"custom_html\",\"content\":{\"code\":\"<p>drafted-code</p>\"}}]}]}}" > /dev/null
expect "a drafts-only connection cannot insert Custom HTML" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}' AND stavba_koncept LIKE '%drafted-code%'")" 0
# 3.7 N37-1: a prefixed attribute is one literal attribute (xml:onerror is not onerror) – on PHP 8.3 the compat parser bound the
# prefix, the sanitizer missed the attribute and the serializer wrote it back as a live onerror
mcp_as "$DRAFT_TOKEN" create_news '{"title":"N37-1 prefixed attributes","category":"'"$CATEGORY"'","text":"<p><img xml:onerror=alert(1) src=/media/x.png><a xml:href=javascript:alert(2) href=/ok>a</a> <a xlink:href=javascript:alert(3)>b</a> <span XML:ONCLICK=alert(4) x:onmouseover=alert(5) xmlns:x=y>c</span></p><svg><a xlink:href=javascript:alert(6)><text>d</text></a></svg><math><mi xml:onclick=alert(7)>e</mi></math>"}' > /dev/null
expect "N37-1: create_news over a drafts-only connection with xml:/xlink:/x: attributes stores nothing executable, the safe href stays" \
  "$(sq "SELECT CONCAT(text REGEXP '[[:space:]]on[a-z]+[[:space:]]*=', '/', text LIKE '%javascript%', '/', text LIKE '%<a href=\"/ok\">a</a>%', '/', text LIKE '%src=\"/media/x.png\"%') FROM ka_novinky WHERE titulek = 'N37-1 prefixed attributes'")" "0/0/1/1"
# 3.8 (N37-3 follow-up): markup over a limit of Core\HtmlLimits is refused before any parser sees it, on every PHP version – a
# Custom HTML element nested 3,000 deep is stored empty with the limit and the measured value among the errors, build_from_html,
# create_news, an SVG upload and the admin news form refuse it and store nothing
LIMIT_DEEP=$(printf '<div>%.0s' $(seq 1 3000))
mcp create_page '{"title":"Limit MCP","slug":"limit-mcp"}' > /dev/null
LIMIT_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'limit-mcp'")
mcp save_build "{\"id\":${LIMIT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"custom_html\",\"content\":{\"code\":\"$LIMIT_DEEP\"}},{\"type\":\"heading\",\"content\":{\"text\":\"Kept by the limit\"}}]}]}}" > "$WORK/response"
contains -q 'The markup is nested 3000 levels deep; the limit is 512 – the field was left empty.' "$WORK/response" \
  && expect "3.8: save_build with Custom HTML nested 3,000 deep returns the limit, the element stays empty, the rest is saved" \
    "$(sq "SELECT CONCAT(stavba_koncept LIKE '%<div><div>%', '/', stavba_koncept LIKE '%Kept by the limit%') FROM ka_stranky WHERE ids = ${LIMIT_PAGE:-0}")" "0/1" \
  || { echo "  CHYBA  3.8: save_build over the nesting limit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp build_from_html "{\"id\":${LIMIT_PAGE:-0},\"html\":\"<section>$LIMIT_DEEP</section>\"}" > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && contains -q 'The markup is nested 3,001 levels deep; the limit is 512.' "$WORK/response" \
  && expect "3.8: build_from_html over the nesting limit is refused with the limit, the draft stays as it was" "$(sq "SELECT stavba_koncept LIKE '%Kept by the limit%' FROM ka_stranky WHERE ids = ${LIMIT_PAGE:-0}")" 1 \
  || { echo "  CHYBA  3.8: build_from_html over the nesting limit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_news "{\"title\":\"Limit news\",\"category\":\"$CATEGORY\",\"text\":\"$LIMIT_DEEP\"}" > "$WORK/response"
mcp_as "$DRAFT_TOKEN" create_news "{\"title\":\"Limit news draft\",\"category\":\"$CATEGORY\",\"text\":\"<blockquote>$LIMIT_DEEP</blockquote>\"}" > "$WORK/response2"
contains -q 'text: The markup is nested 3,000 levels deep; the limit is 512.' "$WORK/response" && contains -q 'text: The markup is nested 3,001 levels deep' "$WORK/response2" \
  && expect "3.8: create_news over the limit is refused on a full and a drafts-only connection, nothing is stored" "$(sq "SELECT COUNT(*) FROM ka_novinky WHERE titulek LIKE 'Limit news%'")" 0 \
  || { echo "  CHYBA  3.8: create_news over the nesting limit"; head -c 300 "$WORK/response"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
LIMIT_SVG=$(php -r 'echo base64_encode("<svg xmlns=\"http://www.w3.org/2000/svg\">" . str_repeat("<g>", 300) . str_repeat("</g>", 300) . "</svg>");')
mcp upload_file "{\"filename\":\"deep.svg\",\"data\":\"$LIMIT_SVG\"}" > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && contains -q 'The markup is nested 301 levels deep; the limit is 256.' "$WORK/response" \
  && expect "3.8: an SVG nested 300 deep is refused with the limit, nothing in Media" "$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE '%/deep-%'")" 0 \
  || { echo "  CHYBA  3.8: SVG upload over the nesting limit"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$(csrf)" -d idc=0 -d "titulek=Limit form" -d "tema=$(sq "SELECT MIN(idt) FROM ka_kategorie")" --data-urlencode "text=$LIMIT_DEEP")
contains -q 'chyba-pole' "$WORK/response" && contains -q '3 000' "$WORK/response" \
  && expect "3.8: the admin news form shows the limit at the text, keeps the text in the form and saves nothing" "$code/$(sq "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Limit form'")/$(grep -c '&lt;div&gt;&lt;div&gt;' "$WORK/response")" "200/0/1" \
  || { echo "  CHYBA  3.8: admin news form over the limit: $code"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_newsletters (subject, intro, status, scheduled_at, created) VALUES ('Scheduled 251', 'Original intro', 'scheduled', '$(site_time)' + INTERVAL 1 DAY, '$(site_time)')"
NL_SCHEDULED=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT id FROM ka_newsletters WHERE subject = 'Scheduled 251'")
mcp_as "$DRAFT_TOKEN" draft_newsletter "{\"id\":$NL_SCHEDULED,\"intro\":\"Changed by a drafts connection\"}" > /dev/null
expect "a drafts-only connection cannot change a scheduled newsletter" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT intro FROM ka_newsletters WHERE id = $NL_SCHEDULED")" "Original intro"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_newsletters WHERE id = $NL_SCHEDULED"
# wrong tokens from one address do not lock out a valid one (a proxy in front of Docker, Claude's servers)
for i in $(seq 1 21); do mcp_as "kaleta_$(printf '0%.0s' $(seq 1 48))" get_page '{"id":1}' > /dev/null; done
mcp get_page '{"id":1}' > "$WORK/response"; contains -q '"isError":true\|"error"' "$WORK/response" && { echo "  CHYBA  wrong tokens locked out a valid token"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); } || echo "  ok     wrong tokens do not lock out a valid token"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'mcp'"
expect "the change log names the Claude connection" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_protokol WHERE via = 'Claude drafts' AND modul = 'claude'")" 1
mcp list_changes '{"by":"claude","limit":5}' > "$WORK/response"
mcp_text; contains -q '"claude_connection":"Claude drafts"' "$WORK/text" && echo "  ok     list_changes tells Claude's changes and their connection" || { echo "  CHYBA  list_changes"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "the change log in the admin filters Claude's changes" 200 "/admin.php?module=changelog&by=claude" 'Claude: Claude drafts'
# the site owner's instructions, resources, prompts and the protocol version
mcp update_settings '{"settings":{"claude_instructions":"Always say renovation, never reconstruction."}}' > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}' > "$WORK/response"
contains -q '"protocolVersion":"2025-03-26"' "$WORK/response" && contains -q 'Always say renovation' "$WORK/response" && contains -q '"prompts"' "$WORK/response" && echo "  ok     initialize: the client's protocol version, the owner's instructions, resources and prompts" || { echo "  CHYBA  initialize"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}' > "$WORK/response"
contains -q '"protocolVersion":"2025-06-18"' "$WORK/response" && contains -q 'CAN ONLY SAVE DRAFTS' "$WORK/response" && echo "  ok     initialize tells a drafts-only connection its limits" || { echo "  CHYBA  initialize drafts"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://instructions"}}' > "$WORK/response"
contains -q 'Always say renovation' "$WORK/response" && echo "  ok     the instructions as an MCP resource" || { echo "  CHYBA  resources/read"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"build_page","arguments":{"topic":"kitchens"}}}' > "$WORK/response"
contains -q 'Build a new page about kitchens' "$WORK/response" && echo "  ok     prompts/get fills in a ready-made task" || { echo "  CHYBA  prompts/get"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://nope"}}' > "$WORK/response"
contains -q '"code":-32602' "$WORK/response" && echo "  ok     an unknown resource is a JSON-RPC error" || { echo "  CHYBA  resources/read unknown"; ERRORS=$((ERRORS+1)); }
# 3.7: a drafts-only connection saves hidden collection categories only – a category page is public
mcp_as "$DRAFT_TOKEN" save_collection_category '{"collection":"produkty","name":"Návrh","slug":"navrh","visible":true}' | contains 'cannot make a category visible' \
  && mcp_as "$DRAFT_TOKEN" save_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_TOP,\"name\":\"Přepsáno\"}" | contains 'this category is on the site' \
  && mcp_as "$DRAFT_TOKEN" delete_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_SUB}" | contains 'isError' \
  && echo "  ok     3.7 drafts: no visible category, no change of a visible one, no delete" || { echo "  CHYBA  3.7 drafts-only categories"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_collection_category '{"collection":"produkty","name":"Návrh","slug":"navrh"}' > /dev/null
expect "3.7 drafts: a new category is saved hidden, the visible ones are untouched" "$(sq "SELECT CONCAT((SELECT c.visible FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id WHERE t.slug = 'navrh'), '|',
  (SELECT name FROM ka_collection_category_texts WHERE category_id = $CAT_TOP AND language = ''), '|', (SELECT COUNT(*) FROM ka_collection_categories WHERE id = $CAT_SUB))")" "0|Běžecké pásy|1"
check "3.7 drafts: the hidden category has no page" 404 /produkty/navrh
# settings that were admin-only before 2.2
mcp update_settings '{"settings":{"extensions":["novinky","poptavky"]}}' > "$WORK/response"
contains -q 'cannot switch itself off' "$WORK/response" && echo "  ok     Claude cannot switch its own connection off" || { echo "  CHYBA  extensions without claude"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
EXT_BEFORE=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$EXT_BEFORE" | sed 's/,/","/g')\",\"asistent\"],\"additional_languages\":[\"xx\"],\"llms_txt\":\"0\",\"indexing\":\"1\"}}" > "$WORK/response"
mcp_text; contains -q 'Unknown language codes: xx' "$WORK/text" && contains -q '"asistent"' "$WORK/text" && expect "extensions, SEO switches and languages over MCP, checked" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'llms_txt'")" 0 || { echo "  CHYBA  settings parity"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$EXT_BEFORE" | sed 's/,/","/g')\"],\"llms_txt\":\"1\"}}" > /dev/null
check "OAuth metadata for a site in a subfolder (openid-configuration)" 200 "/.well-known/openid-configuration" '"token_endpoint"'

echo "== 3.6: WordPress import over MCP (import_wordpress) – everything hidden, menus into the draft look, authors, redirects"
# Jan Novák has an account here with the e-mail of the WordPress author (in another case); a page here already uses the old address /kontakt-stavby
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin) VALUES ('jan-novak-36', '!', 'Jan Novák', 'jan.novak@stavby-novak.example', 1)" > /dev/null
WXR_JAN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'jan-novak-36'")
mcp create_page '{"title":"Kontakt (this site)","slug":"kontakt-stavby"}' > /dev/null
# the draft look starts empty for this block and comes back afterwards; the live menus must not change at all
sq "DROP TABLE IF EXISTS ka_test_look; CREATE TABLE ka_test_look AS SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'look_draft'" > /dev/null
WXR_LIVE_MENU=$(sq "SELECT COALESCE(SHA2(GROUP_CONCAT(umisteni, jazyk, polozky ORDER BY umisteni, jazyk), 256), '-') FROM ka_menu")
mcp import_wordpress '{"url":"http://10.0.0.1/export.xml"}' > "$WORK/response"
mcp_text; contains -q 'internal network' "$WORK/text" && echo "  ok     3.6 import_wordpress: an export address is fetched with the SSRF rules of the image downloader" || { echo "  CHYBA  3.6 import_wordpress url to an internal address"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp upload_file "{\"filename\":\"Stavby Novak.xml\",\"data\":\"$(base64 < "$ROOT/tools/fixtures/wordpress-migration.xml" | tr -d '\n')\"}" > "$WORK/response"
WXR_FILE=$(mcp_value import_file)
expect "3.6 upload_file keeps a WordPress export privately for import_wordpress, never in Media" \
  "$WXR_FILE|$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE '%stavby-novak%'")|$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/import/$WXR_FILE")" "stavby-novak.xml|0|403"
mcp_as "$DRAFT_TOKEN" import_wordpress "{\"file\":\"$WXR_FILE\"}" > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     3.6 import_wordpress is not for a drafts-only connection" || { echo "  CHYBA  3.6 import_wordpress from a drafts-only connection"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
wxr_run() { # a new import of the file, confirmed with the first call; then one call per batch until it is done
  mcp import_wordpress "{\"file\":\"$WXR_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"
  WXR_ID=$(mcp_value import)
  for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$WXR_ID\"}" > "$WORK/response"; done
}
wxr_run
expect "3.6 import_wordpress: done in batches, counts per type, everything that was public on WordPress arrives hidden" \
  "$(mcp_value phase)|$(mcp_value found menus 0 items)|$(mcp_value result news)|$(mcp_value result pages)|$(mcp_value result hidden_but_public_on_wordpress)|$(mcp_value everything_hidden)" "done|8|2|4|5|1"
mcp_text; contains -q '"what":"status","count":1' "$WORK/text" && contains -q '"what":"layout:Breakdance","count":1' "$WORK/text" && contains -q 'migration_report' "$WORK/text" \
  && echo "  ok     3.6 import_wordpress: what was skipped and why (a private page, a Breakdance layout) and the next steps" || { echo "  CHYBA  3.6 import_wordpress skipped/next"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "3.6 nothing is public: news drafts, pages hidden, the private page not imported" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link IN ('nova-zakazka-v-lhote', 'rozepsany-clanek-o-strechach') AND visible = 0), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('sluzby-stavby', 'rekonstrukce-bytu', 'kontakt-stavby-2', 'o-firme-novak') AND zobrazit = 0), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'interni-cenik'))")" "2/4/0"
expect "3.6 authors: Jan's news belongs to the user with his e-mail, Petra's to the importer, no account is created" \
  "$(sq "SELECT CONCAT((SELECT autor FROM ka_novinky WHERE seo_link = 'nova-zakazka-v-lhote') = $WXR_JAN, '/', (SELECT autor FROM ka_novinky WHERE seo_link = 'rozepsany-clanek-o-strechach') = (SELECT idu FROM ka_uzivatele WHERE user = 'admin'), '/', (SELECT COUNT(*) FROM ka_uzivatele WHERE email LIKE '%stavby-novak%'))")|$(mcp_value authors 0 why)" \
  "1/1/1|a user here has the same e-mail"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/sluzby-stavby/rekonstrukce-bytu/"); expect "3.6 an old WordPress address redirects to the new one" "$code" "301 $B/rekonstrukce-bytu"
expect "3.6 an old address that is a page here is not taken over by a redirect (links to it stay), and the answer says so" \
  "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'kontakt-stavby'")|$(mcp_value redirects not_created 0 from)|$(curl -s -o /dev/null -w '%{http_code}' "$B/kontakt-stavby")" "0|/kontakt-stavby|404"
expect "3.6 the menus: main and footer into the draft look, the third one handed back for save_menu" \
  "$(mcp_value menus 0 location)|$(mcp_value menus 0 status)|$(mcp_value menus 1 location)|$(mcp_value menus 2 status)|$(mcp_value menus 2 items_for_save_menu 0 url)" \
  "main|in_draft_look|footer|skipped|https://instagram.example/stavbynovak"
wxr_menu() { php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true);
  $f = function (array $items) use (&$f): string { return implode(",", array_map(fn (array $i): string => $i["text"] . "=" . ($i["url"] ?? $i["type"]) . (!empty($i["new_window"]) ? "*" : "") . (isset($i["children"]) ? "[" . $f($i["children"]) . "]" : ""), $items)); };
  echo ($v["look_draft"] ?? "") !== "" ? "draft:" : "live:", $f($v["items"] ?? []);' "$WORK/response"; }
mcp get_menu '{"location":"main"}' > "$WORK/response"
expect "3.6 the main menu draft: links to the new addresses, hierarchy kept, a third level moved up, a page that was not imported left out" "$(wxr_menu)" \
  "draft:Domů=/,=page[Byty=page,Postup=/rekonstrukce-bytu#postup],Aktuality stavby=/novinky/kategorie/aktuality-stavby[Zakázka=/novinky/nova-zakazka-v-lhote],Kontakt=page"
mcp get_menu '{"location":"footer"}' > "$WORK/response"
expect "3.6 the footer menu draft: custom links stay, a path of the old site stays a path" "$(wxr_menu)" "draft:Facebook=https://facebook.example/stavbynovak*,Ochrana údajů=/ochrana-udaju"
# the same file again: nothing is duplicated and the menus are not put into the draft a second time
wxr_run
expect "3.6 import_wordpress again: nothing duplicated, everything reported as already imported, the menus too" \
  "$(mcp_value phase)|$(mcp_value result news)|$(mcp_value result pages)|$(mcp_value result already_imported)|$(mcp_value menus 0 status)|$(sq "SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'nova-zakazka-v-lhote%'")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'sluzby-stavby%'")" \
  "done|0|0|6|skipped|1|1"
expect "3.6 the live menus did not change; the draft look holds the main and the footer menu" \
  "$(sq "SELECT COALESCE(SHA2(GROUP_CONCAT(umisteni, jazyk, polozky ORDER BY umisteni, jazyk), 256), '-') FROM ka_menu")|$(sq "SELECT JSON_LENGTH(hodnota, '\$.menus') FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$WXR_LIVE_MENU|2"
# 3.6 N36-2: the export an import works on cannot be swapped – no .xml from a drafts-only connection, an existing name is never
# replaced, and a file changed after the preview stops the import
WXR_B64=$(base64 < "$ROOT/tools/fixtures/wordpress-migration.xml" | tr -d '\n')
mcp_as "$DRAFT_TOKEN" upload_file "{\"filename\":\"Swap.xml\",\"data\":\"$WXR_B64\"}" > "$WORK/response"; R1=$(contains -q 'full access' "$WORK/response" && echo refused || echo saved)
mcp upload_file "{\"filename\":\"Stavby Novak.xml\",\"data\":\"$WXR_B64\"}" > "$WORK/response"; R2=$(contains -q 'already there' "$WORK/response" && echo refused || echo replaced)
mcp import_wordpress "{\"file\":\"$WXR_FILE\"}" > /dev/null
printf '\n<!-- changed after the preview -->\n' >> "$WORK/web/storage/import/$WXR_FILE"
mcp import_wordpress "{\"import\":\"$WXR_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"; R3=$(contains -q 'changed after the preview' "$WORK/response" && echo stopped || echo ran)
expect "3.6 N36-2: no export from a drafts-only connection, no replaced export, a changed export stops the import" "$R1|$R2|$R3|$(ls "$WORK/web/storage/import" | grep -c '^swap')" "refused|refused|stopped|0"
# 3.6 N36-1: an old WordPress address the site already routes (the news list, a news item, a page of a language version) never
# becomes a redirect – link healing would point live links at the new hidden record
N36_NEWS=$(sq "SELECT seo_link FROM ka_novinky WHERE visible = 1 AND smazano IS NULL ORDER BY idc LIMIT 1")
cat > "$WORK/n36.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>N36</title><link>https://n36.example</link><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>https://n36.example</wp:base_site_url>
<item><title>Novinky</title><link>https://n36.example/novinky/</link><dc:creator>admin</dc:creator><content:encoded><![CDATA[<p>Old news page</p>]]></content:encoded><wp:post_id>9001</wp:post_id><wp:post_name>novinky</wp:post_name><wp:status>publish</wp:status><wp:post_type>page</wp:post_type></item>
<item><title>Same news</title><link>https://n36.example/novinky/$N36_NEWS/</link><dc:creator>admin</dc:creator><content:encoded><![CDATA[<p>Old copy</p>]]></content:encoded><wp:post_id>9002</wp:post_id><wp:post_name>$N36_NEWS</wp:post_name><wp:status>publish</wp:status><wp:post_type>post</wp:post_type></item>
</channel></rss>
XML
mcp upload_file "{\"filename\":\"n36.xml\",\"data\":\"$(base64 < "$WORK/n36.xml" | tr -d '\n')\"}" > /dev/null
mcp import_wordpress '{"file":"n36.xml","confirm":true,"images":false,"menus":false}' > "$WORK/response"
for _ in 1 2 3; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress '{"import":"n36.xml"}' > "$WORK/response"; done
expect "3.6 N36-1: the news list and a live news item are not taken over by redirects from the import" \
  "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy IN ('novinky', 'novinky/$N36_NEWS')")|$(curl -s -o /dev/null -w '%{http_code}' "$B/novinky/$N36_NEWS")" "0|200"
# the admin reads the same file with the same code: its preview offers the menus
check "3.6 admin Import and export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-migration.xml"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=progress&file=wordpress-migration.xml" -d "_csrf=$TOKEN"
check "3.6 admin import preview: the WordPress menus and the option to bring them into the draft look" 200 "/admin.php?module=transfer&action=preview&file=wordpress-migration.xml" 'name="menu" value="1" checked'
check "3.6 admin import preview: a page builder layout is reported" 200 "/admin.php?module=transfer&action=preview&file=wordpress-migration.xml" 'Breakdance'
sq "UPDATE ka_nastaveni SET hodnota = COALESCE((SELECT hodnota FROM ka_test_look LIMIT 1), '') WHERE promenna = 'look_draft'; DROP TABLE ka_test_look" > /dev/null

echo "== 3.9: a multilingual WordPress export (Polylang, WPML) arrives as linked language versions"
# the language settings and the draft look come back after this block; the site loses its English version for a moment,
# so the import has to add it
sq "DROP TABLE IF EXISTS ka_test_ml; CREATE TABLE ka_test_ml AS SELECT * FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'extensions', 'look_draft'); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('look_draft', 'additional_languages')" > /dev/null
ml_run() { # $1 = fixture: upload, import confirmed with the first call, one call per batch until done
  mcp upload_file "{\"filename\":\"$1.xml\",\"data\":\"$(base64 < "$ROOT/tools/fixtures/$1.xml" | tr -d '\n')\"}" > "$WORK/response"
  ML_FILE=$(mcp_value import_file); [ -n "$ML_FILE" ] && [ "$ML_FILE" != null ] || ML_FILE="$1.xml" # an export already there is never replaced
  mcp import_wordpress "{\"file\":\"$ML_FILE\",\"confirm\":true,\"images\":false${2:-,\"add_languages\":true}}" > "$WORK/response"
  for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$ML_FILE\"}" > "$WORK/response"; done
}
ml_run wordpress-polylang
expect "3.9 Polylang: English added to the site, Arabic reported and left out, every translation linked to its original (2 pages, a post, a category)" \
  "$(mcp_value phase)|$(mcp_value languages added_to_site 0)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(mcp_value languages not_available 0 language)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'solutions-ictx-ar%'")|$(mcp_value languages translations_linked)" \
  "done|en|en|ar|0|4"
expect "3.9 Polylang: the English page is in the English version and points to its Czech original (which came after it in the file)" \
  "$(sq "SELECT CONCAT(c.jazyk, '/', e.jazyk, '/', e.preklad_z = c.ids) FROM ka_stranky e JOIN ka_stranky c ON c.seo_link = 'reseni-ictx' WHERE e.seo_link = 'solutions-ictx'")" "/en/1"
expect "3.9 Polylang: a slug both languages share gets a number in English while slugs are global, and the report shows both addresses" \
  "$(sq "SELECT CONCAT(e.seo_link, '/', e.jazyk, '/', e.preklad_z = c.ids) FROM ka_stranky e JOIN ka_stranky c ON c.seo_link = 'kontakt-ictx' AND c.jazyk = '' WHERE e.seo_link LIKE 'kontakt-ictx-%'")|$(mcp_value languages slug_clashes 0 address)|$(mcp_value languages slug_clashes 0 taken_by)" \
  "kontakt-ictx-2/en/1|/en/kontakt-ictx-2|/kontakt-ictx"
expect "3.9 Polylang: the English post in the English category of the shared slug, both linked to the Czech ones, with their own names" \
  "$(sq "SELECT CONCAT(e.jazyk, '/', e.preklad_z = c.idc, '/', ek.nazev, '/', ek.jazyk, '/', ek.preklad_z = ck.idt, '/', ck.nazev) FROM ka_novinky e JOIN ka_kategorie ek ON ek.idt = e.tema JOIN ka_novinky c ON c.seo_link = 'snidane-s-ai-ictx' JOIN ka_kategorie ck ON ck.idt = c.tema WHERE e.seo_link = 'breakfast-with-ai-ictx'")" \
  "en/1/ICTX Blog/en/1/Blog ICTX"
expect "3.9 Polylang: old English addresses and ?lang=en redirect to the new ones" \
  "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/en/kontakt-ictx/")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/en/kontakt-ictx")|$(curl -s -o /dev/null -w '%{http_code}' "$B/en/blog-ictx/breakfast-with-ai-ictx/")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/?lang=en")|$(curl -s -o /dev/null -w '%{http_code}' "$B/?lang=xx")" \
  "301 $B/en/kontakt-ictx-2|301 $B/en/kontakt-ictx-2|301|301 $B/en|200"
expect "3.9 Polylang: a menu per language in the draft look (Czech main, English main), the English links name their version, the language switcher left out" \
  "$(mcp_value menus 0 language)|$(mcp_value menus 0 location)|$(mcp_value menus 1 language)|$(mcp_value menus 1 location)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.menus.\"hlavni|en\"[1].url')) FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(mcp_value menus 0 warnings 0)" \
  "|main|en|main|/en/novinky/kategorie/blog-ictx-2|“Jazyky” (the Polylang language switcher) was left out: this site shows its own language switcher."
ml_run wordpress-polylang
expect "3.9 Polylang again: nothing duplicated, no link changed" \
  "$(mcp_value languages translations_linked)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'kontakt-ictx%'")|$(sq "SELECT COUNT(*) FROM ka_kategorie WHERE seo_link LIKE 'blog-ictx%'")" "0|2|2"
ml_run wordpress-wpml
expect "3.9 WPML: page, duplicate, post and category linked; a page with its language only in the address goes to its version" \
  "$(mcp_value languages plugin)|$(mcp_value languages translations_linked)|$(sq "SELECT GROUP_CONCAT(CONCAT(e.seo_link, ':', e.jazyk, ':', COALESCE(e.preklad_z = c.ids, '-')) ORDER BY e.seo_link) FROM ka_stranky e LEFT JOIN ka_stranky c ON c.ids = e.preklad_z WHERE e.seo_link IN ('services-wpx', 'cenik-wpx-en', 'about-wpx')")" \
  "wpml|4|about-wpx:en:-,cenik-wpx-en:en:1,services-wpx:en:1"
expect "3.9 WPML: the English news item and category point to the Czech ones; the English menu is the English main menu" \
  "$(sq "SELECT CONCAT(e.jazyk, '/', c.seo_link, '/', ek.seo_link, '/', ck.seo_link) FROM ka_novinky e JOIN ka_novinky c ON c.idc = e.preklad_z JOIN ka_kategorie ek ON ek.idt = e.tema JOIN ka_kategorie ck ON ck.idt = ek.preklad_z WHERE e.seo_link = 'new-service-van-wpx'")|$(mcp_value menus 0 language)|$(mcp_value menus 0 status)" \
  "en/novy-servisni-vuz-wpx/news-wpx/aktuality-wpx|en|skipped"
# the admin reads the same file with the same code: its preview names the plugin and the languages
check "3.9 admin Import and export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-polylang.xml"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=progress&soubor=wordpress-polylang.xml" -d "_csrf=$TOKEN"
check "3.9 admin import preview: a multilingual site with its languages, the unavailable one named" 200 "/admin.php?module=transfer&action=preview&soubor=wordpress-polylang.xml" 'Polylang'
contains -q 'ar (1)' "$WORK/response" && echo "  ok     3.9 admin import preview: Arabic named as a language this site cannot offer" || { echo "  CHYBA  3.9 admin preview of the languages"; ERRORS=$((ERRORS+1)); }
# N39-3: without add_languages a multilingual import never adds a language version (it would show in the switcher at once)
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'additional_languages'" > /dev/null
cp "$ROOT/tools/fixtures/wordpress-polylang.xml" "$WORK/wordpress-polylang-n39.xml"
mcp upload_file "{\"filename\":\"wordpress-polylang-n39.xml\",\"data\":\"$(base64 < "$WORK/wordpress-polylang-n39.xml" | tr -d '\n')\"}" > "$WORK/response"
N39_FILE=$(mcp_value import_file); [ -n "$N39_FILE" ] && [ "$N39_FILE" != null ] || N39_FILE=wordpress-polylang-n39.xml
mcp import_wordpress "{\"file\":\"$N39_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"
for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$N39_FILE\"}" > "$WORK/response"; done
expect "3.9 N39-3: without add_languages the import adds no language version" "$(mcp_value phase)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(mcp_value languages added_to_site 0)" "done||null"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'extensions', 'look_draft'); INSERT INTO ka_nastaveni SELECT * FROM ka_test_ml; DROP TABLE ka_test_ml" > /dev/null

echo "== 2.3: leads, statistics, forms, embeds, page head code, accessibility audit"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'"
curl -s -o /dev/null -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' "$B/?utm_source=facebook&utm_medium=paid&utm_campaign=autumn"
expect "a visit from a campaign on a phone counts in the statistics" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COALESCE((SELECT SUM(navstevy) FROM ka_stat_kampane WHERE kampan = 'facebook / paid / autumn'), 0), '|', COALESCE((SELECT SUM(navstevy) FROM ka_stat_zarizeni WHERE zarizeni = 'phone'), 0) > 0)")" "1|1"
# a form with ticked options and a hidden value, an embed and code in the head of one page
mcp vytvor_stranku '{"titulek":"Leads 23","zobrazit":true}' > "$WORK/response"; mcp_text; PAGE23=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":$PAGE23,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka 23\",\"pole\":[{\"popisek\":\"Sluzby\",\"typ\":\"zaskrtnuti\",\"povinne\":true,\"moznosti_zaskrtnuti\":\"Kuchyne\\nKoupelna\"},{\"popisek\":\"Produkt\",\"typ\":\"skryte\",\"hodnota\":\"Dubovy stul\"},{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true}]}},{\"typ\":\"vlozeni\",\"obsah\":{\"adresa\":\"https://calendly.com/acme/consultation\",\"titulek\":\"Book a consultation\"}},{\"typ\":\"vlozeni\",\"obsah\":{\"adresa\":\"https://evil.example/x\"}}]}]}}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET kod_hlavicky = '<meta name=\"kaleta-test\" content=\"23\">' WHERE ids = $PAGE23" # set in the administration, never through MCP (2.5.1)
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'type="checkbox" name="p0\[\]" value="Kuchyne"' "$WORK/formular.html" && ! grep -q 'Dubovy stul' "$WORK/formular.html" && echo "  ok     ticked options on the page, the hidden value not" || { echo "  CHYBA  checkboxes / hidden field"; ERRORS=$((ERRORS+1)); }
grep -q 'data-vlozit="https://calendly.com/acme/consultation?embed_type=Inline&amp;hide_gdpr_banner=1"' "$WORK/formular.html" && ! grep -q 'evil.example' "$WORK/formular.html" && echo "  ok     Embed: a known service after a click, anything else not at all" || { echo "  CHYBA  Embed"; ERRORS=$((ERRORS+1)); }
grep -q '<meta name="kaleta-test" content="23">' "$WORK/formular.html" && ! curl -s "$B/" | contains 'kaleta-test' && echo "  ok     code in the head of one page only" || { echo "  CHYBA  page head code"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$PAGE23,\"head_code\":\"<script>x()</script>\"}" > "$WORK/response"
contains -q 'only in the administration' "$WORK/response" && echo "  ok     MCP cannot set head code, not even with full access (2.5.1)" || { echo "  CHYBA  MCP: head code"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"head_code":"<script>x()</script>","marketing_code":"<script>y()</script>"}}' > "$WORK/response"
contains -q 'set only in the administration' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('head_code','marketing_code') AND hodnota LIKE '%<script>%'")" = 0 ] \
  && echo "  ok     MCP cannot set code for the whole site (2.5.1)" || { echo "  CHYBA  MCP: site code"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
curl -s -o /dev/null -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Koupelna' --data-urlencode p2=petr@example.cz \
  -d ka_vstup=/sluzby --data-urlencode "ka_kampan=utm_source=google&utm_medium=cpc&utm_campaign=kuchyne" -d ka_odkud=google.com
expect "an enquiry carries the first page, the campaign and the referring site of the visit" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(vstup, '|', odkud, '|', kampan) FROM ka_poptavky WHERE email = 'petr@example.cz'")" "/sluzby|google.com|utm_source=google&utm_medium=cpc&utm_campaign=kuchyne"
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"campaign":"google / cpc / kuchyne"' "$WORK/text" && contains -q '"device":"phone"' "$WORK/text" && contains -q '"path":"/sluzby"' "$WORK/text" && echo "  ok     get_stats: campaigns, devices and the first pages of leads" || { echo "  CHYBA  get_stats"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "Statistics: pages, campaigns and first pages that bring leads" 200 "/admin.php?module=stats&days=7" "google / cpc / kuchyne"
# 2.8: real-user speed – the beacon script only with the statistics on and only for visitors, one beacon per page view, the table in Statistics, get_stats and the audit
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/sluzby"
grep -q '<script src="/image/vitals.js?v=[^"]*" defer data-vitals="/vitals"></script>' "$WORK/response" && ! grep -q 'blocking="render" data-vitals' "$WORK/response" && echo "  ok     2.8: the speed beacon script loads deferred with the statistics on" || { echo "  CHYBA  vitals.js on the page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/sluzby"; ! grep -q 'vitals.js' "$WORK/response" && echo "  ok     2.8: no speed beacon for signed-in users" || { echo "  CHYBA  vitals.js for a signed-in user"; ERRORS=$((ERRORS+1)); }
expect "2.8: a beacon answers 204" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=1800 -d cls=0.05 -d inp=120)" 204
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/neexistuje-vitals -d lcp=1800   # a page the statistics never saw
curl -s -o /dev/null -X POST "$B/vitals" -d path=/sluzby -d lcp=1800                                  # curl's own user agent = a bot
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=999999 -d cls=abc # out of range, not numeric
expect "2.8: the beacon lands in histogram buckets per metric; made-up pages, bots and nonsense do not" "$(sq "SELECT GROUP_CONCAT(CONCAT(path, ':', metric, ':', bucket, ':', samples) ORDER BY metric) FROM ka_web_vitals")" "/sluzby:cls:2:1,/sluzby:inp:2:1,/sluzby:lcp:3:1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=stats&days=7"
grep -q 'href="/sluzby"' "$WORK/response" && grep -qE '2[.,]0 s <span class="stitek stitek-vydano">' "$WORK/response" && grep -qE '150 ms <span class="stitek stitek-vydano">' "$WORK/response" && echo "  ok     2.8: Statistics show p75 LCP, CLS and INP per page with the rating" || { echo "  CHYBA  Statistics: real-user speed"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"web_vitals":\[{"path":"/sluzby","samples":1,"lcp_p75":2000' "$WORK/text" && contains -q '"lcp_rating":"good"' "$WORK/text" && contains -q '"cls_p75":0.05' "$WORK/text" && contains -q '"inp_p75":150' "$WORK/text" && echo "  ok     2.8: get_stats carries web_vitals" || { echo "  CHYBA  get_stats web_vitals"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# 3.2: the old setting still works over MCP and switches the Statistics feature
mcp update_settings '{"settings":{"stats":"0"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.2: update_settings stats=0 switches the Statistics feature off" "$(sq "SELECT FIND_IN_SET('statistika', hodnota) FROM ka_nastaveni WHERE promenna = 'extensions'")" 0
curl -s -o "$WORK/response" "$B/sluzby"; ! grep -q 'vitals' "$WORK/response" && echo "  ok     2.8: statistics off – no beacon script on the page" || { echo "  CHYBA  vitals.js with the statistics off"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=1800
expect "2.8: statistics off – a beacon is not counted" "$(sq "SELECT SUM(samples) FROM ka_web_vitals")" 3
mcp update_settings '{"settings":{"stats":true}}' > "$WORK/response"; mcp_text; rm -f "$WORK"/web/storage/cache/stranky/*.html
contains -q '"stats":"1"' "$WORK/text" && expect "3.2: update_settings stats=true switches the Statistics feature on again, once" "$(sq "SELECT (LENGTH(hodnota) - LENGTH(REPLACE(hodnota, 'statistika', ''))) DIV LENGTH('statistika') FROM ka_nastaveni WHERE promenna = 'extensions'")" 1 \
  || { echo "  CHYBA  update_settings stats"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the audit: a page whose p75 LCP went from 2.0 s (30 measurements 35 days ago) to 3.0 s (30 measurements today) is flagged, /sluzby with one measurement is not
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_web_vitals (day, path, metric, bucket, samples) VALUES ('$(site_time today Y-m-d)' - INTERVAL 35 DAY, '/audit-pomalu', 'lcp', 3, 30), ('$(site_time today Y-m-d)', '/audit-pomalu', 'lcp', 5, 30)"
mcp site_audit '{"kind":"speed"}' > "$WORK/response"; mcp_text
contains -q '"path":"/audit-pomalu"' "$WORK/text" && contains -qE '3[.,]0 s' "$WORK/text" && ! contains -q '/sluzby' "$WORK/text" && echo "  ok     2.8: the site audit flags a page whose p75 LCP got worse by more than 25 %" || { echo "  CHYBA  speed audit"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_web_vitals WHERE path = '/audit-pomalu'"
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p2=tick@example.cz)" in *result=pole\&field=0*) echo "  ok     a required group needs at least one ticked option";; *) echo "  CHYBA  required checkbox group"; ERRORS=$((ERRORS+1));; esac
curl -s -o /dev/null -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Kuchyne' -d 'p0[]=Podvrh' -d p1=Hacked --data-urlencode p2=tick@example.cz
expect "ticked options (only offered ones) and the form's own hidden value are saved" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT data FROM ka_poptavky WHERE email = 'tick@example.cz'")" '[["Sluzby","Kuchyne"],["Produkt","Dubovy stul"],["Email","tick@example.cz"]]'
# accessibility in the site audit
mcp vytvor_stranku '{"titulek":"Access 23","zobrazit":true,"text":"<p>Prices: <a href=\"/sluzby\">click here</a>.</p><table><tr><td>1</td></tr></table>"}' > /dev/null
mcp site_audit '{"kind":"accessibility"}' > "$WORK/response"; mcp_text
contains -q 'click here' "$WORK/text" && contains -q 'header cells' "$WORK/text" && contains -q 'accessibility statement' "$WORK/text" && echo "  ok     site audit: link texts, tables and the accessibility statement" || { echo "  CHYBA  accessibility audit"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# 2.4 for agencies: a ready-made role, whom to ask for help, the check before handing the site over
check "2.4: ready-made Client role fills the form" 200 "/admin.php?module=roles&action=new&preset=client" 'name="nazev" value="Klient"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; mcp_text
contains -qE '"handover": ?"agency"' "$WORK/text" && contains -qE '"handover": ?"smtp"' "$WORK/text" && echo "  ok     hand-over check: agency contact and SMTP missing" || { echo "  CHYBA  hand-over audit"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"agency_name":"Studio Test","agency_email":"help@studio.example","agency_phone":"+420 777 123 456"}}' > /dev/null
curl -s -o "$WORK/response" "$B/admin.php"
grep -q 'Studio Test' "$WORK/response" && grep -q 'mailto:help@studio.example' "$WORK/response" && grep -q 'tel:+420777123456' "$WORK/response" && echo "  ok     sign-in screen shows whom to ask for help" || { echo "  CHYBA  agency contact on the sign-in screen"; ERRORS=$((ERRORS+1)); }
check "2.4: admin footer shows the agency" 200 "/admin.php?module=pages" 'class="agentura"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; mcp_text
contains -qE '"handover": ?"agency"' "$WORK/text" && { echo "  CHYBA  hand-over audit still misses the agency contact"; ERRORS=$((ERRORS+1)); } || echo "  ok     hand-over check: the agency contact is set"
# the cookie bar: remembering leads needs consent to marketing; Global Privacy Control counts as "only necessary"
mcp update_settings '{"settings":{"cookies_mode":"vestavena","lead_attribution":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q 'data-kategorie="marketing"' "$WORK/response" && grep -q 'ka-puvod' "$WORK/response" && grep -q 'globalPrivacyControl' "$WORK/response" && grep -q 'name="ka_vstup"' "$WORK/response" && echo "  ok     cookie bar: marketing consent for lead origins, Global Privacy Control" || { echo "  CHYBA  cookie bar with lead attribution"; ERRORS=$((ERRORS+1)); }
# 3.5 (UXP-07, UXP-02): the policy link says where it leads (Lighthouse link-text); the bar moves right after the skip
# link and, while it shows, gives the page scroll padding of its real height; the categories stay behind Settings
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_policy_url', '/leads-23') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q '<a href="/leads-23">Více o cookies a soukromí</a></p>' "$WORK/response" && grep -q 'preskocit.after(lista)' "$WORK/response" && grep -q 'scroll-padding-bottom: var(--ka-cookies-vyska' "$WORK/response" \
  && grep -q '\.cookies-volby:not(\[hidden\])' "$WORK/response" && echo "  ok     3.5: cookie bar – descriptive policy link, early in the tab order, scroll padding, categories behind Settings" || { echo "  CHYBA  3.5: cookie bar link, order or scroll padding"; ERRORS=$((ERRORS+1)); }
# 3.9 (UXM-11): the bar's text and policy link per language version, with the default language's as the fallback
mcp update_settings '{"settings":{"cookies_text":"Lišta UXM11 česky","cookies_text_en":"Bar UXM11 in English","cookies_policy_url_en":"/en/privacy-uxm11","cookies_policy_url_de":"javascript:alert(1)"}}' > "$WORK/response"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/uxm11-cs.html" "$B/leads-23"; curl -s -o "$WORK/uxm11-en.html" "$B/en/"
expect "3.9 UXM-11: the Czech page shows the Czech bar and policy link, the English version its own; an unsafe link is refused" \
  "$(grep -c 'Lišta UXM11 česky' "$WORK/uxm11-cs.html")|$(grep -c 'UXM11 česky</span> <a href="/leads-23">' "$WORK/uxm11-cs.html")|$(grep -c 'Bar UXM11 in English' "$WORK/uxm11-en.html")|$(grep -c 'in English</span> <a href="/en/privacy-uxm11">' "$WORK/uxm11-en.html")|$(grep -c 'UXM11 česky' "$WORK/uxm11-en.html")|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'cookies_policy_url_de'")" \
  "1|1|1|1|0|0"
mcp update_settings '{"settings":{"cookies_text_en":"","cookies_policy_url_en":""}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/uxm11-en.html" "$B/en/"
expect "3.9 UXM-11: a language version without its own text shows the default language's text and link" \
  "$(grep -c 'Lišta UXM11 česky' "$WORK/uxm11-en.html")|$(grep -c 'UXM11 česky</span> <a href="/leads-23">' "$WORK/uxm11-en.html")" "1|1"
check "3.9 UXM-11: Settings → Privacy and cookies has the bar for each language version" 200 "/admin.php?module=settings&tab=cookies" 'name="cookies_text_en"'
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna IN ('cookies_text', 'cookies_text_en', 'cookies_policy_url_en')"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna = 'cookies_policy_url'"
mcp update_settings '{"settings":{"lead_attribution":"0"}}' > /dev/null
# 2.6: an optional CAPTCHA on top of the built-in protection, checked with the provider on the server
mkdir -p "$WORK/captcha" && cat > "$WORK/captcha/router.php" <<'CAPTCHA'
<?php
$answer = $_POST['response'] ?? '';
header('Content-Type: application/json');
echo json_encode(($_POST['secret'] ?? '') !== 'test-secret' ? ['success' => false] : match ($answer) { 'pass' => ['success' => true, 'score' => 0.9], 'low' => ['success' => true, 'score' => 0.2], default => ['success' => false] });
CAPTCHA
(cd "$WORK/captcha" && exec php -S "127.0.0.1:$CAPTCHA_PORT" router.php > /dev/null 2>&1) & CAPTCHA_PID=$!
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_provider','turnstile'),('captcha_site_key','test-site'),('captcha_secret','test-secret') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota); DELETE FROM ka_kontrola_ip WHERE typ IN ('formular','odber')"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'class="ka-captcha cf-turnstile" data-sitekey="test-site"' "$WORK/formular.html" && [ "$(grep -o 'challenges.cloudflare.com/turnstile/v0/api.js' "$WORK/formular.html" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     CAPTCHA: the Turnstile widget in the form, its script once" || { echo "  CHYBA  CAPTCHA widget"; ERRORS=$((ERRORS+1)); }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
captcha_post() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Koupelna' --data-urlencode "p2=$1" "${@:2}"; }
case "$(captcha_post fail@example.cz -d cf-turnstile-response=wrong)" in *result=captcha*) echo "  ok     CAPTCHA: a failed check is refused";; *) echo "  CHYBA  CAPTCHA: failed check"; ERRORS=$((ERRORS+1));; esac
case "$(captcha_post none@example.cz)" in *result=captcha*) echo "  ok     CAPTCHA: a form without the answer is refused";; *) echo "  CHYBA  CAPTCHA: missing answer"; ERRORS=$((ERRORS+1));; esac
captcha_post pass@example.cz -d cf-turnstile-response=pass > /dev/null
expect "CAPTCHA: a passed check saves the enquiry, the failed ones not" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(email ORDER BY email) FROM ka_poptavky WHERE email IN ('fail@example.cz','none@example.cz','pass@example.cz')")" "pass@example.cz"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'recaptcha' WHERE promenna = 'captcha_provider'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'name="g-recaptcha-response" value="" data-recaptcha="test-site"' "$WORK/formular.html" && grep -q 'recaptcha/api.js?render=test-site' "$WORK/formular.html" || { echo "  CHYBA  reCAPTCHA v3 field and script"; ERRORS=$((ERRORS+1)); }
case "$(captcha_post low@example.cz -d g-recaptcha-response=low)" in *result=captcha*) echo "  ok     reCAPTCHA v3: a low score is refused";; *) echo "  CHYBA  reCAPTCHA score"; ERRORS=$((ERRORS+1));; esac
kill "$CAPTCHA_PID" 2>/dev/null; wait "$CAPTCHA_PID" 2>/dev/null || true; CAPTCHA_PID=
captcha_post down@example.cz -d g-recaptcha-response=pass > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_fail_open', '0') ON DUPLICATE KEY UPDATE hodnota = '0'"
case "$(captcha_post closed@example.cz -d g-recaptcha-response=pass)" in *result=captcha*) ;; *) echo "  CHYBA  CAPTCHA: fail closed"; ERRORS=$((ERRORS+1));; esac
expect "CAPTCHA: when the provider is down the owner's choice decides" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(email) FROM ka_poptavky WHERE email IN ('down@example.cz','closed@example.cz')")" "down@example.cz"
mcp update_settings '{"settings":{"captcha_secret":"stolen","captcha_provider":"hcaptcha"}}' > "$WORK/response"
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'captcha_secret'")" = "test-secret" ] && mcp update_settings '{}' > "$WORK/response" && ! contains -q 'test-secret' "$WORK/response" \
  && echo "  ok     CAPTCHA: Claude can neither set nor read the secret key" || { echo "  CHYBA  CAPTCHA secret over MCP"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna LIKE 'captcha_%'"
# 2.6: Google Tag Manager with consent mode – with the built-in bar it starts only after consent, without a bar right away
# 3.3.2 (N27): Claude can no longer set GTM or Matomo – they load script their owner chooses; the administrator sets them
mcp update_settings '{"settings":{"gtm_id":"GTM-EVIL1","matomo_url":"https://evil.example/","matomo_id":"1","ga4_id":"G-ABCD1234"}}' > "$WORK/response"
[ -z "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna IN ('gtm_id','matomo_url','matomo_id') AND hodnota <> ''")" ] \
  && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'ga4_id'")" = "G-ABCD1234" ] \
  && contains -q 'Google Tag Manager and Matomo load script' "$WORK/response" \
  && echo "  ok     MCP: gtm_id and matomo_* are refused with a reason, ga4_id is still accepted" || { echo "  CHYBA  MCP: GTM or Matomo settable over MCP"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"ga4_id":""}}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('gtm_id', 'GTM-TEST123') ON DUPLICATE KEY UPDATE hodnota = 'GTM-TEST123'"
mcp update_settings '{"settings":{"cookies_mode":"vestavena"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q '<script type="text/plain" data-gtm>(function(w,d,s,l,i)' "$WORK/response" && grep -q "gtag('consent','default',{ad_storage:'denied'" "$WORK/response" && grep -q "'dataLayer','GTM-TEST123'" "$WORK/response" \
  && grep -q 'data-kategorie="analytika"' "$WORK/response" && grep -q 'data-kategorie="marketing"' "$WORK/response" && echo "  ok     GTM: consent mode, the container waits for the cookie bar" || { echo "  CHYBA  GTM with the cookie bar"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"cookies_mode":"zadna"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q "<script>(function(w,d,s,l,i)" "$WORK/response" && ! grep -q "gtag('consent','default'" "$WORK/response" && echo "  ok     GTM: without a cookie bar the container loads right away" || { echo "  CHYBA  GTM without a bar"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'gtm_id'"
mcp update_settings '{"settings":{"cookies_mode":"vestavena"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
# 2.6: import from a website – a small "old site" with a sitemap, a header, a footer, an image and a blog post
OLD_PORT=$((PORT + 10)); OLD="http://127.0.0.1:$OLD_PORT"
mkdir -p "$WORK/oldsite/about-us" "$WORK/oldsite/blog/first-post" "$WORK/oldsite/img"
php -r '$i = imagecreatetruecolor(400, 300); imagefill($i, 0, 0, imagecolorallocate($i, 40, 120, 90)); imagepng($i, $argv[1]);' "$WORK/oldsite/img/team.png"
printf 'User-agent: *\nSitemap: %s/sitemap.xml\n' "$OLD" > "$WORK/oldsite/robots.txt"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/</loc></url><url><loc>%s/about-us/</loc></url><url><loc>%s/blog/first-post/</loc></url></urlset>' "$OLD" "$OLD" "$OLD" > "$WORK/oldsite/sitemap.xml"
oldpage() { printf '<!doctype html><html><head><title>%s | Old Oak</title><meta name="description" content="%s"></head><body><header><nav><a href="/">Old home</a> <a href="/about-us/">About</a></nav></header><main><h1>%s</h1>%s</main><footer>Old footer 1990</footer></body></html>' "$1" "$2" "$1" "$3"; }
oldpage "Welcome" "The old home page" "<p>Old Oak makes furniture by hand in our workshop near the river, since many years, for homes and offices alike.</p>" > "$WORK/oldsite/index.html"
oldpage "About us" "Who we are" '<p>We build oak furniture since 1990, for homes and offices across the region and beyond it, always by hand.</p><img src="/img/team.png" alt="Our team"><p><a href="/blog/first-post/">Read our story</a></p><div class="cookie-notice">We use cookies</div><p>Our tools id="</p><p title="><svg onload=alert(1)>">and</p><img src="/img/team.png" alt="q><svg onload=alert(2)>"><video src="/film.mp4" controls></video>' > "$WORK/oldsite/about-us/index.html"
printf '<!doctype html><html><head><title>Our first post | Old Oak</title><meta property="article:published_time" content="2024-05-06T09:00:00+02:00"></head><body><article><h1>Our first post</h1><p>Today we opened the new workshop for visitors, come and see how a table is made from a single oak.</p></article></body></html>' > "$WORK/oldsite/blog/first-post/index.html"
(cd "$WORK/oldsite" && exec php -S "127.0.0.1:$OLD_PORT" > /dev/null 2>&1) & OLDSITE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD/" && break; sleep 0.3; done
import_field() { php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "{}", true); echo is_array($r[$argv[2]] ?? null) ? json_encode($r[$argv[2]]) : ($r[$argv[2]] ?? "");' "$WORK/response" "$1"; }
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = preview ] && [ "$(import_field found)" = 3 ] && contains -q '/about-us' "$WORK/response" && echo "  ok     website import: three pages found in the sitemap, shown before importing" || { echo "  CHYBA  website import: finding pages"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.7 (N37-26): an import step counts the records it created against the hourly change limit – with one change left the
# step still runs (what it creates is known only afterwards), its change-log row says how many, and the next call is refused
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('claude_change_limit', '$(( $(claude_used test) + 1 ))')"
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
N26_ROWS=$(sq "SELECT popis FROM ka_protokol WHERE akce = 'importuj_web' ORDER BY idp DESC LIMIT 1")
mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response2"
expect "3.7 N37-26 website import: a step counts the records it created, the next step is refused over the limit" \
  "$([ "${N26_ROWS%% *}" -ge 2 ] 2>/dev/null && echo counted || echo "$N26_ROWS")|$(contains -q 'reached the limit' "$WORK/response2" && echo refused)" "counted|refused"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('claude_change_limit', '0')"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = done ] || { echo "  CHYBA  website import did not finish"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "website import: pages hidden, the post as a hidden news item" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT CONCAT(titulek, ':', zobrazit) FROM ka_stranky WHERE seo_link = 'about-us'), '|', (SELECT CONCAT(titulek, ':', visible, ':', DATE(datum)) FROM ka_novinky WHERE titulek = 'Our first post'))")" "About us:0|Our first post:0:2024-05-06"
ABOUT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(text, ' ', IFNULL(stavba, '')) FROM ka_stranky WHERE seo_link = 'about-us'")
echo "$ABOUT" | grep -q 'oak furniture' && echo "$ABOUT" | grep -q 'media/' && ! echo "$ABOUT" | grep -qE 'Old footer|Old home|We use cookies|127\.0\.0\.1' && echo "$ABOUT" | grep -q '"typ":"nadpis"' \
  && echo "  ok     website import: the content in the builder, the image in Media, no header, footer or cookie bar" || { echo "  CHYBA  website import: page content"; echo "$ABOUT" | head -c 600; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N23, N30): markup in attribute values of the old site stays text, and an imported page never gets Custom HTML
ABOUT_TEXT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky WHERE seo_link = 'about-us'")
! echo "$ABOUT_TEXT" | grep -q '<svg' && echo "$ABOUT_TEXT" | grep -q 'alt="q&gt;&lt;svg onload=alert(2)&gt;"' && ! echo "$ABOUT" | grep -q '"typ":"html"' \
  && echo "  ok     website import: attribute text never becomes markup, no Custom HTML from the old site" || { echo "  CHYBA  website import: markup from attributes or Custom HTML"; echo "$ABOUT" | head -c 900; ERRORS=$((ERRORS+1)); }
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'blog/first-post' AND na_adresu LIKE 'novinky/%'")" = 1 ] && echo "  ok     website import: the old address of the post redirects" || { echo "  CHYBA  website import: redirect"; ERRORS=$((ERRORS+1)); }
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "website import: running it again skips what is already there" "$(import_field result)" '{"new_pages":0,"new_news":0,"images":0,"redirects":0,"skipped":3,"failed":0}'
# 2.7: the migration report – a fourth old page with a form that nothing on the new site answers
# (one warning throughout: the old home page had a search engine description, the new starter home page has none)
mkdir -p "$WORK/oldsite/contact"
oldpage "Contact" "Write to us" '<p>Write to us about a table, a chair or a whole kitchen and we answer within two working days, promised.</p><form action="/send"><input name="email"><textarea name="message"></textarea></form>' > "$WORK/oldsite/contact/index.html"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/</loc></url><url><loc>%s/about-us/</loc></url><url><loc>%s/blog/first-post/</loc></url><url><loc>%s/contact/</loc></url></urlset>' "$OLD" "$OLD" "$OLD" "$OLD" > "$WORK/oldsite/sitemap.xml"
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "migration report: four old addresses – the imported ones not published yet, the contact page missing" "$(import_field summary)" '{"addresses":4,"checked":4,"ok":1,"redirected":0,"not_published":2,"missing":1,"errors":1,"warnings":3}'
contains -q '/contact' "$WORK/response" && contains -q 'form_missing\|missing' "$WORK/response" && contains -q 'site_checks' "$WORK/response" && echo "  ok     migration report: the missing page first, then the checks of the whole site" || { echo "  CHYBA  migration report rows"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_redirect '{"from":"/contact","to":"/about-us"}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'about-us'"
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "migration report: after a redirect and publishing, the contact address redirects (but the form is gone)" "$(import_field summary)" '{"addresses":4,"checked":4,"ok":2,"redirected":1,"not_published":1,"missing":0,"errors":1,"warnings":2}'
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 0 WHERE seo_link = 'about-us'; DELETE FROM ka_presmerovani WHERE z_adresy = 'contact'"
# 3.8: an old page nested 3,000 deep is never parsed – the website import skips it with the limit among its failures, the
# migration report checks only its address
mkdir -p "$WORK/oldsite/deep"
oldpage "Deep" "Deep page" "$(printf '<div>%.0s' $(seq 1 3000))" > "$WORK/oldsite/deep/index.html"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/deep/</loc></url></urlset>' "$OLD" > "$WORK/oldsite/sitemap.xml"
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "3.8: website import skips an old page over the nesting limit with the limit in its failures, nothing stored" \
  "$(import_field result | grep -o '"failed":[0-9]*')|$(import_field failures | grep -c 'deep.*3 001')|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Deep'")" '"failed":1|1|0'
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
contains -q 'too_large' "$WORK/response" && echo "  ok     3.8: the migration report reads an old page over the limit as too large, only its address is checked" \
  || { echo "  CHYBA  3.8: migration report of a page over the limit"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
kill "$OLDSITE_PID" 2>/dev/null; OLDSITE_PID=
echo "== 3.7: migration II – past 300 old addresses, items in batches and from CSV"
# a fake old shop (tools/fake-old-site.php): a sitemap index with 350 pages, a page its robots.txt disallows, images;
# every request it gets is in $BIGLOG
BIG_PORT=$((PORT + 8)); BIG="http://127.0.0.1:$BIG_PORT"; BIGLOG="$WORK/bigsite.log"; : > "$BIGLOG"
(cd "$ROOT/tools" && KALETA_FAKE_LOG="$BIGLOG" exec php -S "127.0.0.1:$BIG_PORT" fake-old-site.php > /dev/null 2>&1) & OLDSITE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$BIG/robots.txt" && break; sleep 0.3; done
mcp migration_report "{\"url\":\"$BIG\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 40); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "3.7 migration report: all 352 addresses of a sitemap index are checked (it stopped at 300)" "$(import_field summary)" '{"addresses":352,"checked":352,"ok":1,"redirected":0,"not_published":0,"missing":351,"errors":351,"warnings":0}'
mcp migration_report "{\"report_id\":\"$REPORT_ID\",\"offset\":300}" > "$WORK/response"
expect "3.7 migration report: the problems page by offset; the page robots.txt disallows is looked up here, never downloaded" \
  "$(php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo count($r["problems"]), "|", $r["more_problems"], "|", count(array_filter($r["problems"], fn (array $p): bool => in_array("robots", array_column($p["problems"], "code"), true)));' "$WORK/response")|$(grep -c '^/p/' "$BIGLOG" || true)|$(grep -c '^/private/' "$BIGLOG" || true)" \
  "51|0|1|350|0"
# 3.7 (N37-27): a huge number is a clear tool error naming the parameter – never an error page or another number
curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"migration_report\",\"arguments\":{\"report_id\":\"$REPORT_ID\",\"offset\":1e20}}}" > "$WORK/code"
mcp migration_report "{\"report_id\":\"$REPORT_ID\",\"offset\":5000}" > "$WORK/response2"
mcp save_collection_items '{"collection":"x","items":[{"id":100000000000000000000,"name":"A"}]}' > "$WORK/response3"
expect "3.7 N37-27 MCP: a huge or out-of-range number gives a tool error that names it (offset 1e20, offset 5000, an item id of 21 digits)" \
  "$(cat "$WORK/code")|$(contains -q '"isError":true' "$WORK/response" && contains -q 'The number in offset is too large' "$WORK/response" && echo 1)|$(contains -q 'offset must be a whole number from 0 to' "$WORK/response2" && echo 1)|$(contains -q 'The number in items.0.id is too large' "$WORK/response3" && echo 1)" \
  "200|1|1|1"
mcp import_website "{\"url\":\"$BIG\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 40); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "3.7 website import: the 352 pages of the sitemap index found (it stopped at 300)" "$(import_field phase)|$(import_field found)" "preview|352"
# save_collection_items: many items in one call with the rules of save_collection_item, media by address, dry_run first
mcp create_collection '{"name":"Produkty 37","slug":"produkty-37","item_pages":true,"fields":[{"key":"price","label":"Price","type":"number"},{"key":"photo","label":"Photo","type":"image"}]}' > /dev/null
ITEMS="[{\"name\":\"Oak table\",\"slug\":\"oak-table\",\"values\":{\"price\":\"1200\",\"nope\":\"x\"},\"media\":{\"photo\":\"$BIG/img/oak.png\"}},{\"name\":\"Chair\",\"values\":{\"price\":\"abc\"},\"visible\":true},\"nonsense\",{\"name\":\"Oak again\",\"slug\":\"oak-table\"},{\"name\":\"Lamp\",\"media\":{\"photo\":\"http://127.0.0.1:1/x.png\",\"price\":\"$BIG/img/x.png\"}}]"
mcp save_collection_items "{\"collection\":\"produkty-37\",\"dry_run\":true,\"items\":$ITEMS}" > "$WORK/response"
expect "3.7 save_collection_items dry_run: what would happen per item, nothing saved or downloaded" \
  "$(mcp_value added)|$(mcp_value refused)|$(mcp_value media to_download)|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k USING (idk) WHERE k.seo_link = 'produkty-37'")|$(grep -c '^/img/' "$BIGLOG" || true)" "3|2|2|0|0"
mcp save_collection_items "{\"collection\":\"produkty-37\",\"items\":$ITEMS}" > "$WORK/response"
expect "3.7 save_collection_items: valid items saved (hidden unless visible), invalid ones refused with the reason, the image downloaded into Media" \
  "$(mcp_value added)|$(mcp_value refused)|$(mcp_value results 3 reason)|$(mcp_value results 0 unknown_keys)|$(mcp_value results 1 invalid_fields)|$(mcp_value results 4 media_failed 0 reason)|$(mcp_value results 4 media_failed 1 reason)|$(sq "SELECT GROUP_CONCAT(CONCAT(p.seo_link, ':', p.zobrazit, ':', p.data LIKE '%\"photo\":\"media%') ORDER BY p.seo_link) FROM ka_kolekce_polozky p JOIN ka_kolekce k USING (idk) WHERE k.seo_link = 'produkty-37'")" \
  '3|2|The same item is in this batch twice – only the first one is saved.|["nope"]|["Price"]|The field does not exist or is not an image or file field.|The old site is not responding.|chair:1:0,lamp:0:0,oak-table:0:1'
mcp save_collection_items '{"collection":"produkty-37","items":[{"name":"Oak table","slug":"oak-table","values":{"price":"1200"}},{"slug":"chair","values":{"price":"89"}}]}' > "$WORK/response"
CHAIR=$(sq "SELECT p.idp FROM ka_kolekce_polozky p JOIN ka_kolekce k USING (idk) WHERE k.seo_link = 'produkty-37' AND p.seo_link = 'chair'")
expect "3.7 save_collection_items: a second call finds the items by slug – the same values stay unchanged, a change keeps the earlier version" \
  "$(mcp_value unchanged)|$(mcp_value changed)|$(sq "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'polozka:${CHAIR:-0}'")" "1|1|1"
mcp_as "$DRAFT_TOKEN" save_collection_items '{"collection":"produkty-37","items":[{"slug":"chair","values":{"price":"1"}},{"slug":"oak-table","visible":true},{"slug":"lamp","values":{"price":"5"}},{"name":"Draft item","visible":true}]}' > "$WORK/response"
expect "3.7 save_collection_items over a drafts-only connection: hidden items only – a visible item and visible: true refused, a new item stays hidden" \
  "$(mcp_value results 0 status)|$(mcp_value results 1 status)|$(mcp_value results 2 status)|$(mcp_value results 3 status):$(mcp_value results 3 visible)|$(sq "SELECT CONCAT(zobrazit, ':', data LIKE '%\"price\":\"89\"%') FROM ka_kolekce_polozky WHERE idp = ${CHAIR:-0}")|$(sq "SELECT GROUP_CONCAT(zobrazit) FROM ka_kolekce_polozky WHERE seo_link IN ('draft-item', 'oak-table')")" \
  "refused|refused|changed|added:|1:1|0,0"
# 3.7 (N37-21): a batch downloads its media first and saves after – an item a person published (and edited) while the
# download ran stays published with the person's edit, and a drafts-only batch never touches it then; (N37-28) item keys
# the tool does not know are reported
mcp create_collection '{"name":"Race 37","slug":"race-37","fields":[{"key":"price","label":"Price","type":"number"},{"key":"note","label":"Note","type":"text"},{"key":"photo","label":"Photo","type":"image"}]}' > /dev/null
mcp save_collection_items '{"collection":"race-37","items":[{"name":"Race A","slug":"race-a","values":{"price":"1","note":"old"}},{"name":"Race B","slug":"race-b","values":{"price":"1"}}]}' > /dev/null
RACE_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'race-37'")
mcp save_collection_items "{\"collection\":\"race-37\",\"items\":[{\"slug\":\"race-a\",\"publish_at\":\"2027-01-01\",\"values\":{\"price\":\"2\"},\"media\":{\"photo\":\"$BIG/slow/a.png\"}}]}" > "$WORK/response" & RACE_A=$!
sleep 1.5; sq "UPDATE ka_kolekce_polozky SET zobrazit = 1, data = JSON_SET(data, '$.note', 'new') WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-a'"; wait "$RACE_A" || true
N21_A="$(mcp_value results 0 status)|$(mcp_value results 0 unknown_item_keys)|$(sq "SELECT CONCAT(zobrazit, ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.price')), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.note')), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.photo')) LIKE 'media/%') FROM ka_kolekce_polozky WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-a'")"
mcp_as "$DRAFT_TOKEN" save_collection_items "{\"collection\":\"race-37\",\"items\":[{\"slug\":\"race-b\",\"values\":{\"price\":\"3\"},\"media\":{\"photo\":\"$BIG/slow/b.png\"}}]}" > "$WORK/response" & RACE_B=$!
sleep 1.5; sq "UPDATE ka_kolekce_polozky SET zobrazit = 1 WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-b'"; wait "$RACE_B" || true
expect "3.7 N37-21 save_collection_items: an item published and edited during the downloads stays published with the edit; a drafts-only batch is refused then (N37-28: unknown item keys reported)" \
  "$N21_A|$(mcp_value results 0 status)|$(sq "SELECT CONCAT(zobrazit, ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.price'))) FROM ka_kolekce_polozky WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-b'")" \
  'changed|["publish_at"]|1:2:new:1|refused|1:1'
# Collections → Import: a Windows-1250 CSV from Excel, the columns paired by themselves, the preview, saving in batches,
# the image downloaded afterwards; an empty cell leaves a value as it is
IDK37=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty-37'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=import&id=$IDK37"
printf 'Název;Price;Photo;Navíc\nŽlutý stůl;1 200;%s/img/stul.png;x\nOak table;999;;\n;5;;\nŽlutý stůl;1;;\n' "$BIG" | iconv -f UTF-8 -t WINDOWS-1250 > "$WORK/items.csv"
PREVIEW=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_upload" -F "_csrf=$(csrf)" -F "idk=$IDK37" -F "soubor=@$WORK/items.csv;type=text/csv")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$PREVIEW"
expect "3.7 CSV import of items: the preview pairs the columns, reads Windows-1250 and refuses a row without a name and a repeated one (nothing saved yet)" \
  "$(grep -o '<option value="[_a-z]*" selected>' "$WORK/response" | tr -d '\n')|$(contains -q '<td>Žlutý stůl</td>' "$WORK/response" && echo 1 || echo 0)|$(grep -c 'stitek stitek-chyba' "$WORK/response" || true)|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link = 'zluty-stul'")" \
  '<option value="_name" selected><option value="price" selected><option value="photo" selected>|1|2|0'
IMPORT37=$(grep -o 'name="import" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//' || true)
PROGRESS=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_map" -d "_csrf=$(csrf)" -d "idk=$IDK37" -d "import=$IMPORT37" \
  -d 'mapovani[0]=_name' -d 'mapovani[1]=price' -d 'mapovani[2]=photo' -d 'mapovani[3]=' -d ulozit=1)
for i in $(seq 1 10); do
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$PROGRESS" -d "_csrf=$(csrf)" -d "import=$IMPORT37"
  contains -q 'id="import-hotovo"' "$WORK/response" && break
done
expect "3.7 CSV import of items: saved hidden, the image from the address in Media, the existing item updated by its name, its photo kept" \
  "$(sq "SELECT CONCAT(nazev, ':', zobrazit, ':', data LIKE '%\"photo\":\"media%') FROM ka_kolekce_polozky WHERE seo_link = 'zluty-stul'")|$(sq "SELECT CONCAT(data LIKE '%\"price\":\"999\"%', ':', data LIKE '%\"photo\":\"media%') FROM ka_kolekce_polozky WHERE idk = ${IDK37:-0} AND seo_link = 'oak-table'")|$(grep -c '^/img/stul.png' "$BIGLOG" || true)" \
  "Žlutý stůl:0:1|1:1|1"
# 3.7 (N37-25): the uploaded rows are deleted once saved; a finished import is listed and can be removed; the daily
# clean-up removes import states and reports untouched for 14 days – an uploaded WordPress export stays
IMPORTDIR="$WORK/web/storage/import"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=import&id=$IDK37"
N25_LIST=$(grep -c "name=\"import\" value=\"$IMPORT37\"" "$WORK/response" || true)
N25_ROWS=$([ -e "$IMPORTDIR/polozky-$IMPORT37.rows.json" ] && echo rows-kept || echo rows-gone)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=import_delete" -d "_csrf=$(csrf)" -d "idk=$IDK37" -d "import=$IMPORT37"
printf '<?xml version="1.0"?><rss/>' > "$IMPORTDIR/old-export.xml"; printf '{}' > "$IMPORTDIR/web-0123456789abcdef.json"; printf '{}' > "$IMPORTDIR/parita-0123456789abcdef.json"; printf '{}' > "$IMPORTDIR/parita-fedcba9876543210.json"
php -r 'foreach (array_slice($argv, 1) as $f) { touch($f, time() - 20 * 86400); }' "$IMPORTDIR/old-export.xml" "$IMPORTDIR/web-0123456789abcdef.json" "$IMPORTDIR/parita-0123456789abcdef.json"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.7 N37-25 item import: rows deleted once saved, the finished import listed and removable; the daily clean-up expires old states, keeps the export and fresh files" \
  "$N25_ROWS|$N25_LIST|$([ -e "$IMPORTDIR/polozky-$IMPORT37.json" ] && echo state-kept || echo state-gone)|$(ls "$IMPORTDIR" | grep -E '^(old-export\.xml|web-0123456789abcdef\.json|parita-0123456789abcdef\.json|parita-fedcba9876543210\.json)$' | tr '\n' ' ')" \
  "rows-gone|1|state-gone|old-export.xml parita-fedcba9876543210.json "
# 3.7 security review N37-8: a row whose address (made from its name) is a category's gets a number, and the result lists it
mcp save_collection_category '{"collection":"produkty-37","name":"Stolky","slug":"stolky","visible":true}' > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=import&id=$IDK37"
printf 'Název;Price\nStolky;10\n' > "$WORK/items37.csv"
PREVIEW=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_upload" -F "_csrf=$(csrf)" -F "idk=$IDK37" -F "soubor=@$WORK/items37.csv;type=text/csv")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$PREVIEW"
contains -q 'Adresa „stolky“ patří kategorii této kolekce, proto má položka „stolky-2“.' "$WORK/response" && echo "  ok     3.7 N37-8: the import preview says the row gets another address" || { echo "  CHYBA  3.7 N37-8: import preview note"; ERRORS=$((ERRORS+1)); }
IMPORT37=$(grep -o 'name="import" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//' || true)
PROGRESS=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_map" -d "_csrf=$(csrf)" -d "idk=$IDK37" -d "import=$IMPORT37" -d 'mapovani[0]=_name' -d 'mapovani[1]=price' -d ulozit=1)
for i in $(seq 1 10); do
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$PROGRESS" -d "_csrf=$(csrf)" -d "import=$IMPORT37"
  contains -q 'id="import-hotovo"' "$WORK/response" && break
done
expect "3.7 N37-8: the CSV import never gives an item a category's address and lists the item that got another one" \
  "$(sq "SELECT GROUP_CONCAT(seo_link) FROM ka_kolekce_polozky WHERE idk = ${IDK37:-0} AND nazev = 'Stolky'")|$(grep -c 'id="import-prejmenovane"' "$WORK/response" || true)" "stolky-2|1"
kill "$OLDSITE_PID" 2>/dev/null; OLDSITE_PID=
# 3.7 (N37-23): a hostile old site – a 1 MB robots.txt of wildcard rules and a home page with 2,000 links: the first batch
# reads the robots.txt cut to 512 KB and 500 rules (and says so), checks all links against it and ends within its budget
HOSTILE_PORT=$((PORT + 19)); HOSTILE="http://127.0.0.1:$HOSTILE_PORT"; HOSTILELOG="$WORK/hostile.log"; : > "$HOSTILELOG"
(cd "$ROOT/tools" && KALETA_FAKE_LOG="$HOSTILELOG" KALETA_FAKE_MODE=robots exec php -S "127.0.0.1:$HOSTILE_PORT" fake-old-site.php > /dev/null 2>&1) & HOSTILE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$HOSTILE/" && break; sleep 0.3; done
N23_START=$(date +%s)
curl -s -m 30 -o "$WORK/response" -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"import_website\",\"arguments\":{\"url\":\"$HOSTILE\"}}}" || true
N23_TIME=$(( $(date +%s) - N23_START ))
expect "3.7 N37-23 website import: a 1 MB robots.txt and 2,000 links – one batch in under 30 s, every allowed link found, the cut robots.txt noted, the disallowed page never read" \
  "$([ "$N23_TIME" -lt 30 ] && echo fast || echo "slow:$N23_TIME")|$(import_field found)|$(contains -q 'only its first 512 KB and 500 rules' "$WORK/response" && echo noted)|$(grep -c '^/blocked/' "$HOSTILELOG" || true)" \
  "fast|2001|noted|0"
kill "$HOSTILE_PID" 2>/dev/null; HOSTILE_PID=
# 3.7 (N37-24): sitemaps on other hosts are never read (and the result says so), at most 100 sitemaps are queued, and no
# sitemap is read once 3,000 addresses are known
SITEMAPS_PORT=$((PORT + 20)); SITEMAPS="http://127.0.0.1:$SITEMAPS_PORT"; SITEMAPSLOG="$WORK/sitemaps.log"; : > "$SITEMAPSLOG"
(cd "$ROOT/tools" && KALETA_FAKE_LOG="$SITEMAPSLOG" KALETA_FAKE_MODE=sitemaps exec php -S "127.0.0.1:$SITEMAPS_PORT" fake-old-site.php > /dev/null 2>&1) & SITEMAPS_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$SITEMAPS/robots.txt" && break; sleep 0.3; done
mcp import_website "{\"url\":\"$SITEMAPS\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "3.7 N37-24 website import: 3,000 addresses from the first two sitemaps, the third never read, other hosts and the sitemaps over 100 skipped and said" \
  "$(import_field phase)|$(import_field found)|$(grep -c '^/sm-[12]\.xml' "$SITEMAPSLOG" || true)|$(grep -c '^/sm-3\.xml\|^/sm-x' "$SITEMAPSLOG" || true)|$(contains -q '3 sitemaps on other hosts were not read (localhost, cdn.invalid)' "$WORK/response" && echo foreign)|$(contains -q '56 more sitemaps were not read: at most 100 are read' "$WORK/response" && echo capped)" \
  "preview|3000|2|0|foreign|capped"
mcp migration_report "{\"url\":\"$SITEMAPS\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "3.7 N37-24 migration report: the skipped sitemaps are said in its notes too" "$(contains -q 'sitemaps on other hosts were not read' "$WORK/response" && echo noted)" "noted"
kill "$SITEMAPS_PID" 2>/dev/null; SITEMAPS_PID=
# 2.7: old form entries (e.g. Breakdance submissions) come over into Enquiries, once
ENTRIES='[{"date":"2025-03-14 09:30","form":"Contact","page":"/contact","fields":{"Name":"Jana Old","E-mail":"jana.old@example.cz","Message":"A table please"}},{"date":"2025-03-15 10:00","form":"Contact","fields":[{"label":"Phone","value":"777 000 111"}]}]'
mcp import_enquiries "{\"source\":\"breakdance\",\"entries\":$ENTRIES}" > "$WORK/response"
expect "import_enquiries: two old entries imported" "$(import_field imported)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(email), ':', MIN(stav)) FROM ka_poptavky WHERE zdroj = 'import:breakdance'")" "2|2:jana.old@example.cz:1"
mcp import_enquiries "{\"source\":\"breakdance\",\"entries\":$ENTRIES}" > "$WORK/response"
expect "import_enquiries: a second run skips them" "$(import_field imported):$(import_field already_imported)" "0:2"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # limit přihlášení z IP vyčerpal test zámku účtu
JAR5="$WORK/jar5"
curl -s -c "$JAR5" -b "$JAR5" -o /dev/null "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=nove"
TOKEN5=$(curl -s -b "$JAR5" -c "$JAR5" "$B/admin.php?action=oauth" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
code=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$TOKEN5" -d user=admin --data-urlencode "password=$PASSWORD")
case "$code" in *action=oauth) echo "  ok     nepřihlášený se po přihlášení vrátí na souhlas";; *) echo "  CHYBA  návrat na souhlas po přihlášení: $code"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; grep -q 'Připojené aplikace' "$WORK/response" && echo "  ok     připojená aplikace v Můj účet" || { echo "  CHYBA  připojené aplikace"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$OAUTH_CSRF" -d "odpojit_klient=$CLIENT"
expect "odpojení aplikace smaže její tokeny" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "0"

echo "== dvoufázové přihlášení (TOTP a záložní kódy)"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP', totp_zalozni = '[\"$(php -r 'echo hash("sha256", "abcde-12345");')\"]' WHERE user = 'autor'"
sign_in_2fa() { # prihlas2fa <jar> → vrátí kód odpovědi na zadání druhého kroku <kod>
  local jar="$1" t; t=$(curl -s -c "$jar" -b "$jar" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$t" -d user=autor --data-urlencode "password=$PASSWORD"
  grep -q 'name="kod"' "$WORK/response" || echo "bez-druheho-kroku"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod -d "kod=$2"
}
expect "špatný kód z aplikace neprojde" "$(sign_in_2fa "$WORK/jar6" 000000)" "401"
TOTP_CODE=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code("JBSWY3DPEHPK3PXP", intdiv(time(), 30));' "$ROOT")
expect "přihlášení s kódem z aplikace (TOTP)" "$(sign_in_2fa "$WORK/jar7" "$TOTP_CODE")" "302"
expect "záložní kód projde" "$(sign_in_2fa "$WORK/jar8" abcde-12345)" "302"
expect "záložní kód jde použít jen jednou" "$(sign_in_2fa "$WORK/jar9" abcde-12345)" "401"
# 3.3.2 (N7): a correct password does not reset the count of wrong codes – the per-account lock stays reachable
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'autor'"
sign_in_2fa "$WORK/jar10" 111111 > /dev/null; sign_in_2fa "$WORK/jar11" 222222 > /dev/null
expect "3.3.2: wrong codes add up across sign-ins with the right password" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pocet_chyb FROM ka_uzivatele WHERE user = 'autor'")" "2"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'autor'"
# 3.3.3 (N56): a stolen session alone adds no passkey and moves no e-mail – both need the current password; the old address hears of a change
account_post() { curl -s -b "$WORK/jar7" -c "$WORK/jar7" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=account" -d "_csrf=$N56_CSRF" "$@"; }
curl -s -b "$WORK/jar7" -c "$WORK/jar7" -o "$WORK/response" "$B/admin.php?action=account"; N56_CSRF=$(csrf)
grep -q 'id="klic-heslo"' "$WORK/response" && grep -q 'id="email-heslo"' "$WORK/response" && echo "  ok     3.3.3: My account asks for the password next to the e-mail and the passkey" || { echo "  CHYBA  My account: password fields for the e-mail and the passkey"; ERRORS=$((ERRORS+1)); }
expect "3.3.3: a passkey challenge only with the current password" "$(account_post -d co=klic_moznosti)|$(account_post -d co=klic_moznosti -d soucasne=wrong-password-1)|$(account_post -d co=klic_moznosti --data-urlencode "soucasne=$PASSWORD")|$(grep -c '"challenge"' "$WORK/response")" "403|403|200|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET email = 'autor-puvodni@example.cz', jazyk = '' WHERE user = 'autor'; DELETE FROM ka_posta WHERE komu = 'autor-puvodni@example.cz'"
account_post -d co=profil -d jmeno=Autor -d email=utocnik@example.cz > /dev/null
account_post -d co=profil -d jmeno=Autor -d email=utocnik@example.cz -d soucasne=wrong-password-1 > /dev/null
expect "3.3.3: without the current password the e-mail stays" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT email FROM ka_uzivatele WHERE user = 'autor'")" "autor-puvodni@example.cz"
account_post -d co=profil -d jmeno=Autor-jmeno -d email=autor-puvodni@example.cz > /dev/null
expect "3.3.3: other details save without the password while the e-mail stays the same" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(jmeno, '|', email) FROM ka_uzivatele WHERE user = 'autor'")" "Autor-jmeno|autor-puvodni@example.cz"
account_post -d co=profil -d jmeno=Autor -d email=autor-novy@example.cz --data-urlencode "soucasne=$PASSWORD" > /dev/null
expect "3.3.3: with the current password the e-mail changes and the old address gets a notice" \
  "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT email FROM ka_uzivatele WHERE user = 'autor'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'autor-puvodni@example.cz' AND predmet LIKE 'E-mail va%'))")" "autor-novy@example.cz|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET totp_tajemstvi = '', totp_zalozni = NULL WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'"

echo "== vypnutá rozšíření Novinky a Formuláře a poptávky"
EXTENSIONS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna='extensions'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='statistika,presmerovani,claude' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK"/web/storage/cache/*.txt 2>/dev/null || true
check "výpis novinek je pryč" 404 /novinky
check "novinka je pryč" 404 /novinky/vitejte-v-kalete
check "RSS je pryč" 404 /rss.xml
curl -s -o "$WORK/response" "$B/sitemap.xml"; ! grep -q "/novinky" "$WORK/response" && echo "  ok     mapa webu bez novinek" || { echo "  CHYBA  mapa webu s vypnutými novinkami"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'rss.xml' "$WORK/response" && echo "  ok     bez novinek ani odkaz na RSS" || { echo "  CHYBA  odkaz na RSS při vypnutých novinkách"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'href="[^"]*/novinky"' "$WORK/response" && echo "  ok     menu bez odkazu na novinky" || { echo "  CHYBA  menu odkazuje na vypnuté novinky"; ERRORS=$((ERRORS+1)); }
expect "odeslání formuláře nejde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/formular" -d x=1)" 404
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"; ! grep -q 'module=news"' "$WORK/response" && ! grep -q 'module=enquiries"' "$WORK/response" && echo "  ok     administrace bez novinek a poptávek" || { echo "  CHYBA  administrace ukazuje vypnutá rozšíření"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; ! grep -q '\\"formular\\":' "$WORK/response" && ! grep -q 'seznam_novinek' <(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') \
  && echo "  ok     builder a MCP nenabízejí prvky ani nástroje vypnutých rozšíření" || { echo "  CHYBA  schéma nebo MCP s vypnutými rozšířeními"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$EXTENSIONS' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== 2.0: one pop-up system – the old per-page Modal element becomes a site pop-up (migration 0034)"
# a page as 1.x saved it: a Modal opened after 5 s once a week, and a button that opened it by its anchor
mcp create_page '{"title":"Stará akce","slug":"stara-akce","visible":true}' > /dev/null; MODAL_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'stara-akce'")
mcp save_build "{\"id\":$MODAL_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Nabídka\",\"link\":\"#nabidka\"}}]}]}}" > /dev/null
# the Modal as 1.x stored it, next to the button (the 2.0 validator no longer accepts it, so straight into the database)
php -r '$b = json_decode($argv[1], true); $b["deti"][0]["deti"][] = ["id" => "ok1", "typ" => "okno", "kotva" => "nabidka", "popis" => "Jarní akce", "obsah" => ["samo" => "5", "znovu" => "tyden"], "styl" => [], "tridy" => [],
  "deti" => [["id" => "na1", "typ" => "nadpis", "znacka" => "h2", "obsah" => ["text" => "Sleva 20 %"], "styl" => [], "tridy" => []]]]; echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$(sq "SELECT stavba FROM ka_stranky WHERE ids = $MODAL_PAGE")" > "$WORK/modal.json"
php -r '$pdo = new PDO("mysql:host=" . $argv[1] . ";port=" . $argv[2] . ";dbname=" . $argv[3] . ";charset=utf8mb4", $argv[4], $argv[5]); $pdo->prepare("UPDATE ka_stranky SET stavba = ? WHERE ids = ?")->execute([file_get_contents($argv[6]), $argv[7]]);' \
  "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS" "$WORK/modal.json" "$MODAL_PAGE"
sq "UPDATE ka_nastaveni SET hodnota = '33' WHERE promenna = 'db_version'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'data_migrations'" > /dev/null # a site of 1.9
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/stara-akce"
expect "the migration made a site pop-up with the same trigger, frequency and content, only on that page" \
  "$(sq "SELECT CONCAT_WS('|', nazev, adresa, typ, spoustec, hodnota, cetnost, dni, aktivni, stavba LIKE '%Sleva 20 %%', JSON_EXTRACT(pravidla, '$.stranky[0]')) FROM ka_popupy WHERE nazev = 'Jarní akce'")" \
  "Jarní akce|nabidka|okno|cas|5|dni|7|1|1|$MODAL_PAGE"
expect "the page lost the element and its button opens the pop-up" "$(sq "SELECT CONCAT(stavba LIKE '%\"typ\":\"okno\"%', '|', stavba LIKE '%#popup-nabidka%') FROM ka_stranky WHERE ids = $MODAL_PAGE")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "0|1|$LAST_MIGRATION"
grep -q 'href="#popup-nabidka"' "$WORK/response" && grep -q 'id="popup-nabidka"' "$WORK/response" && grep -q 'Sleva 20 %' "$WORK/response" \
  && echo "  ok     on the site: the button and the pop-up with the old content" || { echo "  CHYBA  converted pop-up on the site"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'id="popup-nabidka"' "$WORK/response" && { echo "  CHYBA  the converted pop-up shows on other pages"; ERRORS=$((ERRORS+1)); } || echo "  ok     the converted pop-up stays on its page"
check "2.0: old admin URLs of 1.3 lead to the start screen, not a redirect" 200 "/admin.php?modul=stranky&akce=novy" "Přehled"

echo "== 2.8: background jobs, events, alerts"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "mail: sent" "$WORK/tasks.txt" && grep -q "cleanup: ok" "$WORK/tasks.txt" && echo "  ok     /ulohy runs the jobs of the scheduler" || { echo "  CHYBA  /ulohy jobs"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "every job that ran is recorded with its result" "$(sq "SELECT CONCAT(COUNT(*) > 5, ':', SUM(failures)) FROM ka_jobs")" "1:0"
[ "$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'enquiry.received'")" = 1 ] && [ "$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'build.published'")" = 1 ] \
  && ! sq "SELECT data FROM ka_events WHERE type = 'enquiry.received'" | contains '@' && echo "  ok     events: enquiries and publishing recorded, without the sender" || { echo "  CHYBA  events"; ERRORS=$((ERRORS+1)); }
check "System status lists the background jobs" 200 "/admin.php?module=status" "alerts_email"
# an error event goes out as one alert e-mail
sq "UPDATE ka_nastaveni SET hodnota = (SELECT COALESCE(MAX(id), 0) FROM ka_events) WHERE promenna = 'alerts_cursor'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'alerts_last_sent'" > /dev/null
sq "INSERT INTO ka_nastaveni (promenna, hodnota) SELECT 'alerts_cursor', (SELECT COALESCE(MAX(id), 0) FROM ka_events) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ka_nastaveni WHERE promenna = 'alerts_cursor')" > /dev/null
sq "INSERT INTO ka_events (created_at, type, severity, message) VALUES ('$(site_time)', 'backup.failed', 'error', 'Test: the automatic backup failed')" > /dev/null
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'alerts'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "alerts: one e-mail with the error, the next waits an hour" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'")" "1"
sq "INSERT INTO ka_events (created_at, type, severity, message) VALUES ('$(site_time)', 'mail.failed', 'error', 'Test: second')" > /dev/null; sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'alerts'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "alerts: at most one an hour" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'")" "1"
# the check after an update: only with the one-time code
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/ulohy?probe=abc"); expect "update check without the code is refused" "$code" "403"
sq "INSERT INTO ka_nastaveni VALUES ('update_probe','probe123') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
expect "update check with the code answers the running version" "$(curl -s "$B/ulohy?probe=probe123")" "KALETA-PROBE $(php -r 'require $argv[1]; echo KALETA_VERSION;' "$ROOT/system/bootstrap.php" 2>/dev/null)"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_probe'" > /dev/null
# Claude: get_health and list_events
mcp get_health '{}' > "$WORK/response"
contains -q 'kaleta_version' "$WORK/response" && contains -q 'alerts' "$WORK/response" && contains -q 'backup.failed' "$WORK/response" && echo "  ok     MCP get_health: status, jobs and the problems of the week" || { echo "  CHYBA  MCP get_health"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_events '{"types":["backup."],"min_severity":"error"}' > "$WORK/response"
contains -q 'Test: the automatic backup failed' "$WORK/response" && contains -q 'next_since_id' "$WORK/response" && ! contains -q 'type\\":\\"enquiry.received' "$WORK/response" && echo "  ok     MCP list_events: filtered by type and severity, with a cursor" || { echo "  CHYBA  MCP list_events"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }

# firewall (2.8): the test server runs with KALETA_FIREWALL_LOCAL=1, so 127.0.0.1 counts as a visitor's address
sq "INSERT INTO ka_nastaveni VALUES ('firewall_enabled','1'),('firewall_ips','127.0.0.1 # test'),('firewall_probes','1'),('firewall_rate','0') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
expect "firewall: a listed address is refused on the public site" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "403"
expect "firewall: the administration stays open" "$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=settings&tab=firewall")" "200"
grep -q 'name="firewall_ips"' "$WORK/response" && grep -q '127.0.0.1' "$WORK/response" && echo "  ok     firewall: the tab shows the settings and the refused request" || { echo "  CHYBA  firewall: záložka"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'firewall_ips'" > /dev/null
for i in 1 2 3 4; do curl -s -o /dev/null "$B/wp-login.php"; done
expect "firewall: probing for other systems is blocked at the fifth try" "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-login.php")" "403"
expect "firewall: the blocked address is refused everywhere for a while" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "403"
expect "firewall: the block is recorded as an event" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'firewall.blocked'")" "1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=firewall"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=firewall_unblock" -d "_csrf=$TOKEN&ip=127.0.0.1"
expect "firewall: an address can be unblocked" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"
sq "UPDATE ka_nastaveni SET hodnota = '3' WHERE promenna = 'firewall_rate'" > /dev/null
codes=""; for i in 1 2 3 4 5 6 7 8; do codes="$codes $(curl -s -o /dev/null -w '%{http_code}' "$B/")"; done
case "$codes" in *429*) echo "  ok     firewall: too many requests a minute get 429";; *) echo "  CHYBA  firewall: limit požadavků ($codes)"; ERRORS=$((ERRORS+1));; esac
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna IN ('firewall_enabled', 'firewall_rate')" > /dev/null
expect "firewall: off again, the site answers" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"

echo "== 2.9: fleet console (a second install is the console, this site pairs with it)"
PORT3=$((PORT + 13)); B3="http://127.0.0.1:$PORT3"; DB3="${DB_NAME}_konzole"; JAR_CON="$WORK/cookies-konzole.txt"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB3\`; CREATE DATABASE \`$DB3\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web3" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web3")
mkdir -p "$WORK/web3/media" "$WORK/web3/storage/log" "$WORK/web3/storage/cache"
(cd "$WORK/web3" && KALETA_FLEET_LOCAL=1 exec php -S "127.0.0.1:$PORT3" system/dev-router.php > "$WORK/server3.log" 2>&1) & SERVER3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B3/install.php" && break; sleep 0.2; done
curl -s -o "$WORK/response" -X POST "$B3/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB3" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Konzole agentury" -d web=firemni -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=fleet' -d 'rozsireni[]=claude'
sq3() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB3" -N -e "$1"; }
# the console's own update channel is not reachable here: it "knows" a newer version 9.9.9 from its cache
sq3 "REPLACE INTO ka_nastaveni VALUES ('update_url', 'http://127.0.0.1:1/aktualizace.json'), ('update_cache', '{\"url\":\"http://127.0.0.1:1/aktualizace.json\",\"overeno\":$(date +%s),\"manifest\":{\"verze\":\"9.9.9\",\"min_php\":\"8.3\",\"zmeny\":[]},\"chyba\":null}')" > /dev/null
curl -s -c "$JAR_CON" -b "$JAR_CON" -o "$WORK/response" "$B3/admin.php"; curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=pairing_key" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
PAIRING_KEY=$(grep -o 'kaleta-console:[A-Za-z0-9_-]*' "$WORK/response" | head -1)
[ -n "$PAIRING_KEY" ] && echo "  ok     console: a one-time pairing key" || { echo "  CHYBA  konzole nedala párovací klíč"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
! grep -q 'kaleta-console:' "$WORK/response" && echo "  ok     console: the pairing key is shown only once" || { echo "  CHYBA  párovací klíč se ukázal znovu"; ERRORS=$((ERRORS+1)); }
expect "a site without the fleet extension has no console addresses" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/fleet/heartbeat" -d '{}')" "404"
# this site pairs with the console from Settings → Fleet console
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; TOKEN=$(csrf)
grep -q 'name="pairing_key"' "$WORK/response" && echo "  ok     Settings → Fleet console offers pairing" || { echo "  CHYBA  záložka Konzole webů"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_pair" -d "_csrf=$TOKEN" --data-urlencode "pairing_key=$PAIRING_KEY" -d fleet_updates=1
expect "pairing: the site knows its console and its number there" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'), '|', (SELECT hodnota > 0 FROM ka_nastaveni WHERE promenna = 'fleet_site_id'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_updates'))")" "$B3|1|1"
expect "pairing: the console has the site with its first report" "$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(last_seen IS NOT NULL), '|', MAX(version <> ''), '|', MAX(manage_updates), '|', MAX(heartbeat LIKE '%enquiries_unanswered%')) FROM ka_fleet_sites")" "1|1|1|1|1"
expect "pairing: the code works only once" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_pairing WHERE used_at IS NOT NULL")" "1"
sq3 "SELECT heartbeat FROM ka_fleet_sites" | contains '@' && { echo "  CHYBA  the report carries an e-mail address"; ERRORS=$((ERRORS+1)); } || echo "  ok     the report carries no e-mail addresses"
FLEET_NAME=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&show=all"
grep -qF "$FLEET_NAME" "$WORK/response" && echo "  ok     console: the site is in the list" || { echo "  CHYBA  konzole: web není v seznamu"; ERRORS=$((ERRORS+1)); }
FLEET_ID=$(sq3 "SELECT id FROM ka_fleet_sites LIMIT 1")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
grep -q 'name="ring"' "$WORK/response" && echo "  ok     console: the detail of a site with its update ring" || { echo "  CHYBA  konzole: detail webu"; ERRORS=$((ERRORS+1)); }
# forged and repeated reports are refused
expect "console: a report with a wrong signature is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -H 'X-Kaleta-Signature: AAAA' -d "{\"site_id\":$FLEET_ID,\"ts\":$(date +%s)}")" "403"
expect "console: a report of an unknown site is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -d '{"site_id":99999}')" "404"
expect "console: pairing with an unknown code is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/pair" -d '{"action":"pair","code":"00000000000000000000000000000000","public_key":"'"$(sq3 "SELECT public_key FROM ka_fleet_sites LIMIT 1")"'","url":"http://x.test","ts":0}')" "403"
# the heartbeat job reports on its own
LAST_TS=$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID"); sleep 1
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'heartbeat'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
[ "$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID")" -gt "$LAST_TS" ] && echo "  ok     the background job sends the report" || { echo "  CHYBA  úloha heartbeat nic neposlala"; ERRORS=$((ERRORS+1)); }
# staged updates: a normal site waits, a test site (canary) gets the new version at once
expect "staged updates: a normal site waits for the test sites" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'")" ""
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=ring" -d "_csrf=$(csrf)" -d "id=$FLEET_ID" -d ring=canary
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "staged updates: a test site may install the new version" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'")" "9.9.9"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q '9.9.9' "$WORK/response" && echo "  ok     the site shows the allowed update" || { echo "  CHYBA  povolená aktualizace se neukazuje"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_updates" -d "_csrf=$(csrf)"
expect "the site takes the decision about updates back" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_updates'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'))")" "0|"
# uptime from the console, and a site that stops reporting
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
expect "uptime: the console sees the site up" "$(sq3 "SELECT up FROM ka_fleet_sites WHERE id = $FLEET_ID")" "1"
sq3 "UPDATE ka_fleet_sites SET url = 'http://127.0.0.1:1', last_seen = '$(site_time)' - INTERVAL 30 HOUR, silent_reported = 0 WHERE id = $FLEET_ID" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
expect "uptime: down twice in a row is an event, and so is a site that stopped reporting" "$(sq3 "SELECT CONCAT((SELECT up FROM ka_fleet_sites WHERE id = $FLEET_ID), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_down'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_silent'))")" "0|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
grep -q 'stitek-chyba' "$WORK/response" && echo "  ok     console: a down site is first in the list of what needs attention" || { echo "  CHYBA  konzole: nedostupný web se neukazuje"; ERRORS=$((ERRORS+1)); }
sq3 "UPDATE ka_fleet_sites SET url = '$B' WHERE id = $FLEET_ID" > /dev/null
# Claude on the console reads the fleet (read-only tools, only with the extension)
CON_TOKEN="kaleta_$(printf 'c%.0s' $(seq 1 48))"
sq3 "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$CON_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_sites","arguments":{}}}' > "$WORK/response"
contains -q 'console_decides_updates' "$WORK/response" && contains -q 'newest_version' "$WORK/response" && echo "  ok     MCP list_sites on the console" || { echo "  CHYBA  MCP list_sites"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"get_site\",\"arguments\":{\"id\":$FLEET_ID}}}" > "$WORK/response"
contains -q 'jobs_failing' "$WORK/response" && echo "  ok     MCP get_site: the last report" || { echo "  CHYBA  MCP get_site"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_sites '{}' > "$WORK/response"; contains -q 'newest_version' "$WORK/response" && { echo "  CHYBA  list_sites works on a site that is not a console"; ERRORS=$((ERRORS+1)); } || echo "  ok     list_sites exists only on a console"
echo "== 2.16: shared design kit – the console publishes it, a paired site receives it as drafts only (Fleet\Kit)"
# the console has a class, a token change, a component (with a custom-code element that must not travel) and a saved section
sq3 "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('kit-band', '{}', 'padding: 2rem;', '$(site_time)')" > /dev/null
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('design_system', '{\"barvy\":{\"primarni\":\"#aa0000\"}}')" > /dev/null
sq3 "INSERT INTO ka_komponenty (nazev, vlastnosti, stavba, zmeneno) VALUES ('Kit card', '[]', '{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Kit card v1\"}},{\"typ\":\"html\",\"obsah\":{\"kod\":\"<script>alert(1)</script>\"}}]}]}', '$(site_time)')" > /dev/null
sq3 "INSERT INTO ka_sekce (nazev, prvek, zmeneno) VALUES ('Kit banner', '{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Kit banner\"}}]}', '$(site_time)')" > /dev/null
KIT_COMPONENT=$(sq3 "SELECT idm FROM ka_komponenty WHERE nazev = 'Kit card'"); KIT_SECTION=$(sq3 "SELECT idx FROM ka_sekce WHERE nazev = 'Kit banner'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
grep -q 'name="design_system"' "$WORK/response" && grep -q 'value="kit-band"' "$WORK/response" && grep -q "value=\"$KIT_COMPONENT\"" "$WORK/response" && echo "  ok     console: the shared kit screen offers the design system, classes, components and sections" || { echo "  CHYBA  console: the shared kit screen"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d design_system=1 -d 'classes[]=kit-band' -d "components[]=$KIT_COMPONENT" -d "sections[]=$KIT_SECTION"
expect "console: kit version 1 is published – signed content without the custom-code element" "$(sq3 "SELECT CONCAT(version, '|', manifest LIKE '%#aa0000%', '|', manifest LIKE '%kit-band%', '|', manifest LIKE '%Kit card v1%', '|', manifest LIKE '%Kit banner%', '|', manifest LIKE '%<script%', '|', LENGTH(sha256), '|', summary) FROM ka_fleet_kits")" "1|1|1|1|1|0|64|design system, 1 class, 1 component, 1 section"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)"
expect "console: an empty kit is not published" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_kits")" "1"
# a site that did not opt in ignores the announcement
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q 'name="fleet_kit"' "$WORK/response" && echo "  ok     Settings → Fleet console offers receiving the kit (off by default)" || { echo "  CHYBA  the kit opt-in is missing"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "a site with the kit off ignores it" "$(sq "SELECT CONCAT(COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'), '0'), '|', (SELECT COUNT(*) FROM ka_komponenty WHERE kit_key IS NOT NULL), '|', COALESCE((SELECT hodnota LIKE '%kit-band%' FROM ka_nastaveni WHERE promenna = 'look_draft'), 0))")" "0|0|0"
# with the kit on, the next report fetches and applies it – as drafts only
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_kit" -d "_csrf=$(csrf)" -d fleet_kit=1
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "kit on: the look draft has the token and the class, the published look and the classes are unchanged" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'), '|', (SELECT hodnota LIKE '%#aa0000%' AND hodnota LIKE '%kit-band%' FROM ka_nastaveni WHERE promenna = 'look_draft'), '|', COALESCE((SELECT hodnota LIKE '%#aa0000%' FROM ka_nastaveni WHERE promenna = 'design_system'), 0), '|', (SELECT COUNT(*) FROM ka_tridy WHERE nazev = 'kit-band'))")" "1|1|0|0"
expect "kit on: the component is a draft without a published build and without the code element, the section is in the library, the event is recorded" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_komponenty WHERE kit_key = 'kit-card' AND stavba IS NULL AND stavba_koncept LIKE '%Kit card v1%' AND stavba_koncept NOT LIKE '%<script%'), '|', (SELECT COUNT(*) FROM ka_sekce WHERE kit_key = 'kit-banner'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.kit_received'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE akce = 'fleet_kit_received'))")" "1|1|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q 'module=components' "$WORK/response" && grep -q 'module=appearance' "$WORK/response" && echo "  ok     the site shows the received version with links to the waiting drafts" || { echo "  CHYBA  the received kit is not shown"; ERRORS=$((ERRORS+1)); }
# a second version updates the same component instead of duplicating it; the console learns which version the site applied
sq3 "UPDATE ka_komponenty SET stavba = REPLACE(stavba, 'Kit card v1', 'Kit card v2') WHERE idm = $KIT_COMPONENT" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d "components[]=$KIT_COMPONENT"
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "kit version 2 updates the component's draft (no duplicate); the report carried the version applied before" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_komponenty WHERE kit_key = 'kit-card'), '|', (SELECT stavba_koncept LIKE '%Kit card v2%' FROM ka_komponenty WHERE kit_key = 'kit-card'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'))")|$(sq3 "SELECT heartbeat LIKE '%\"kit_version\":1%' FROM ka_fleet_sites WHERE id = $FLEET_ID")" "1|1|2|1"
# a kit whose bytes do not match the announced hash is refused and nothing changes; an unsigned request to the console gets nothing
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d design_system=1
sq3 "UPDATE ka_fleet_kits SET manifest = REPLACE(manifest, '#aa0000', '#bb0000') WHERE version = 3" > /dev/null
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "a tampered kit is refused: the version stays, the draft does not change, the refusal is an event" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'), '|', (SELECT hodnota LIKE '%#bb0000%' FROM ka_nastaveni WHERE promenna = 'look_draft'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.kit_refused'), '|', (SELECT hodnota <> '' FROM ka_nastaveni WHERE promenna = 'fleet_kit_error'))")" "2|0|1|1"
expect "console: a kit request without a valid signature is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/kit" -H 'X-Kaleta-Signature: AAAA' -d "{\"action\":\"kit\",\"site_id\":$FLEET_ID,\"ts\":$(date +%s)}")" "403"
expect "console: a kit request of an unknown site is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/kit" -d '{"action":"kit","site_id":99999}')" "404"
# MCP: the site reports the kit it applied, the console shows the version of each site
mcp site_info '{}' > "$WORK/response"; contains -q 'fleet_kit' "$WORK/response" && contains -q 'applied_at' "$WORK/response" && echo "  ok     MCP site_info reports the kit version on a member site" || { echo "  CHYBA  MCP site_info: fleet_kit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_sites","arguments":{}}}' > "$WORK/response"
contains -q 'kit_version' "$WORK/response" && contains -q 'contents' "$WORK/response" && echo "  ok     MCP list_sites on the console shows the newest kit and each site's version" || { echo "  CHYBA  MCP list_sites: kit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# disconnecting tells the console; the used key does not pair again
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_unpair" -d "_csrf=$(csrf)"
expect "disconnecting removes the site from the console and the console from the site" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_sites")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'")" "0|"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_pair" -d "_csrf=$(csrf)" --data-urlencode "pairing_key=$PAIRING_KEY" -d fleet_updates=1
expect "a used pairing key does not pair again" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'")" ""
kill "$SERVER3_PID" 2>/dev/null || true; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB3\`"
echo "== 2.10: business facts"
mcp save_fact '{"key":"projects","label":"Projects","type":"number","value":"1500"}' > "$WORK/response"
contains -q 'fact.projects' "$WORK/response" && echo "  ok     facts: Claude creates a fact" || { echo "  CHYBA  save_fact"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"founded","label":"Founded","type":"year","value":"2004","schema_property":"foundingDate"}' > /dev/null
mcp save_fact '{"key":"founded","value":"long ago"}' > "$WORK/response"
contains -q 'does not fit the type' "$WORK/response" && echo "  ok     facts: a value that does not fit the type is refused" || { echo "  CHYBA  fakt nesprávného typu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Fakta test","slug":"fakta-test","visible":true,"text":"<p>Máme za sebou {{fact.projects}} zakázek od roku {{ fact.founded }}.</p><p>Loni jsme dokončili 1500 zakázek.</p><p>{{fact.neexistuje}}</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fakta-test"
grep -qE '1(.|..)500 zakázek od roku 2004' "$WORK/response" && ! grep -q '{{' "$WORK/response" && echo "  ok     facts: tokens filled in on the page (number with the thousands separator)" || { echo "  CHYBA  fakta se na stránce nedoplnila"; grep -o 'Máme za sebou[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q '"foundingDate":"2004"' "$WORK/response" && echo "  ok     facts: a fact with a schema property is in the structured data" || { echo "  CHYBA  foundingDate ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Fakta dokumentace","slug":"fakta-dokumentace","visible":true,"text":"<p>Napište <code>{{fact.projects}}</code> do textu.</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fakta-dokumentace"
grep -q '<code>{{fact.projects}}</code>' "$WORK/response" && echo "  ok     facts: a token inside <code> stays as written (documentation)" || { echo "  CHYBA  značka v <code> se doplnila"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N26): a text fact "javascript:…" filled into a link is checked like any other link
# 3.3.3 (N50): saving such a fact is refused; one stored before (here straight in the database) is still caught when filled
mcp save_fact '{"key":"promo_link","label":"Promo","type":"text","value":"javascript:alert(document.domain)"}' > "$WORK/response"
grep -q 'cannot begin with an address scheme' "$WORK/response" && echo "  ok     facts: save_fact refuses a text fact \"javascript:…\"" || { echo "  CHYBA  save_fact accepted javascript:"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_facts (fact_key, language, label, type, value, updated_at) VALUES ('promo_link', '', 'Promo', 'text', 'javascript:alert(document.domain)', '$(site_time)')" > /dev/null
mcp create_page '{"title":"Fact link","slug":"fact-link","visible":true,"text":"<p><a href=\"{{fact.promo_link}}\">Promo</a></p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fact-link"
! grep -qi 'href="javascript:' "$WORK/response" && grep -q 'href="#">Promo</a>' "$WORK/response" && echo "  ok     facts: a text fact \"javascript:…\" in a link becomes a link to #" || { echo "  CHYBA  fakt javascript: v odkazu"; grep -o '<a href="[^"]*">Promo' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp trash_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'fact-link'")}" > /dev/null; sq "DELETE FROM ka_facts WHERE fact_key = 'promo_link'" > /dev/null
curl -s -o "$WORK/response" "$B/llms.txt"
grep -qE '^- Projects: 1(.|..)500$' "$WORK/response" && echo "  ok     facts: llms.txt lists the facts" || { echo "  CHYBA  fakta v llms.txt"; grep -A3 -i 'fakt' "$WORK/response" | head -5; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"projects","value":"1600"}' > "$WORK/response"
contains -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     facts: a change lists the sentences that still state the old value" || { echo "  CHYBA  stará hodnota se nenašla"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/fakta-test"
grep -qE '1(.|..)600 zakázek' "$WORK/response" && echo "  ok     facts: the page says the new value at once (the cache is cleared)" || { echo "  CHYBA  nová hodnota faktu se neukázala"; ERRORS=$((ERRORS+1)); }
mcp find_claims '{}' > "$WORK/response"
contains -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     claims inventory: sentences with numbers written as plain text" || { echo "  CHYBA  find_claims"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'fact.neexistuje' "$WORK/response" && echo "  ok     site audit: a token of a fact that does not exist" || { echo "  CHYBA  audit neznámého faktu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_facts '{}' > "$WORK/response"
contains -q 'company_phone' "$WORK/response" && contains -q 'used_in' "$WORK/response" && echo "  ok     MCP list_facts: own and built-in facts with their use" || { echo "  CHYBA  list_facts"; ERRORS=$((ERRORS+1)); }
expect "facts: the admin list" "$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=facts")" "200"
grep -q 'fact.projects' "$WORK/response" && echo "  ok     facts: the list shows the token" || { echo "  CHYBA  seznam faktů"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=edit&key=projects"
grep -q 'Fakta test' "$WORK/response" && echo "  ok     facts: the fact shows where it is used" || { echo "  CHYBA  kde se fakt používá"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=facts&action=save" -d "_csrf=$TOKEN" -d key=projects -d label=Projects -d type=number -d value=1700
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=edit&key=projects"
grep -q 'starou hodnotu' "$WORK/response" && echo "  ok     facts: after a change in the admin it says whether the old value is still stated" || { echo "  CHYBA  admin: stará hodnota"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=claims"
grep -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     facts: the claims inventory in the admin" || { echo "  CHYBA  admin: věty s čísly"; ERRORS=$((ERRORS+1)); }
echo "== 2.10: computed facts and sourced proof numbers"
YEARS=$(php -r 'echo (int) date("Y") - 2004;'); TYM_COUNT=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = 'tym' AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ''")
mcp create_page '{"title":"Pocitane test","slug":"pocitane-test","visible":true,"text":"<p>Roky: {{years_since:2004}} / {{ years_since:fact.founded }}. Tým: {{count:tym}}. Novinky: {{count:news}}. Vadné: {{count:neexistuje}}|{{years_since:brzy}}.</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/pocitane-test"
grep -q "Roky: $YEARS / $YEARS\. Tým: $TYM_COUNT\." "$WORK/response" && grep -qE 'Novinky: [0-9]+\.' "$WORK/response" && grep -q 'Vadné: |\.' "$WORK/response" && ! grep -q '{{' "$WORK/response" \
  && echo "  ok     computed facts: years since a year and a fact, the count of a collection and of news; a bad token is empty" || { echo "  CHYBA  počítané fakty na stránce"; grep -o 'Roky:[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'count:neexistuje' "$WORK/response" && contains -q 'years_since:brzy' "$WORK/response" && contains -q 'cannot be computed' "$WORK/response" && echo "  ok     site audit: computed tokens that cannot be computed" || { echo "  CHYBA  audit vadné počítané značky"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp find_claims '{}' > "$WORK/response"
! contains -q 'Roky:' "$WORK/response" && echo "  ok     claims inventory: a sentence with a computed token is not a claim" || { echo "  CHYBA  find_claims s počítanou značkou"; ERRORS=$((ERRORS+1)); }
POCIT=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'pocitane-test'")
mcp save_build "{\"id\":$POCIT,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"id\":\"cnt1\",\"type\":\"counter\",\"content\":{\"number\":\"1500\",\"suffix\":\"+\",\"caption\":\"zakázek\"}},{\"id\":\"cnt2\",\"type\":\"counter\",\"content\":{\"number\":\"{{fact.projects}}\",\"suffix\":\"\",\"caption\":\"zakázek z faktu\"}},{\"id\":\"cnt3\",\"type\":\"counter\",\"content\":{\"number\":\"{{years_since:fact.founded}}\",\"suffix\":\" let\",\"caption\":\"na trhu\"}}]}]}}" > "$WORK/response"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/pocitane-test"
grep -qE 'data-pocitadlo="1700">1(.|..)700<' "$WORK/response" && grep -q "data-pocitadlo=\"$YEARS\">$YEARS<" "$WORK/response" && grep -q 'data-pocitadlo="1500"' "$WORK/response" && ! grep -q '{{' "$WORK/response" \
  && echo "  ok     counter: a fact and a computed token as the number, filled in for visitors with the count-up" || { echo "  CHYBA  počítadlo s faktem"; grep -o 'data-pocitadlo[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'The number 1500 is typed in' "$WORK/response" && contains -q 'cnt1' "$WORK/response" && ! contains -q 'cnt2' "$WORK/response" && ! contains -q 'cnt3' "$WORK/response" \
  && echo "  ok     site audit: a proof number typed in as digits is reported with its element, tokens are not" || { echo "  CHYBA  audit ručně napsaného čísla"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FIRST_COL=$(sq "SELECT seo_link FROM ka_kolekce ORDER BY idk LIMIT 1"); FIRST_COUNT=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = '$FIRST_COL' AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ''")
mcp list_facts '{}' > "$WORK/response"
contains -q 'years_since:fact.founded' "$WORK/response" && contains -q "count:$FIRST_COL" "$WORK/response" && echo "  ok     MCP list_facts: the computed tokens with their values" || { echo "  CHYBA  list_facts computed"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts"
grep -q 'years_since:fact.founded' "$WORK/response" && grep -q "count:$FIRST_COL}}</code></td><td>$FIRST_COUNT<" "$WORK/response" && echo "  ok     facts: the admin list explains the computed tokens with their current values" || { echo "  CHYBA  počítané značky v seznamu faktů"; ERRORS=$((ERRORS+1)); }
mcp trash_page "{\"id\":$POCIT}" > /dev/null
mcp delete_fact '{"key":"founded"}' > "$WORK/response"
contains -q 'Fakta test' "$WORK/response" && echo "  ok     deleting a fact lists where it was still used" || { echo "  CHYBA  delete_fact"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }

echo "== 2.10: opening hours with exceptions"
HOURS_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'"); TYPE_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_type'")
sq "REPLACE INTO ka_nastaveni VALUES ('company_hours', 'Po-Pá 8:00-17:00'), ('company_type', 'LocalBusiness')" > /dev/null
TOMORROW=$(site_time "+1 day" "Y-m-d")
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Inventura\",\"notice_days\":7}" > "$WORK/response"
contains -q 'Inventura' "$WORK/response" && echo "  ok     hours: Claude adds an exception (closed tomorrow)" || { echo "  CHYBA  save_hours_exception"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_hours_exception '{"from":"2026-13-01"}' > "$WORK/response"
contains -q 'YYYY-MM-DD' "$WORK/response" && echo "  ok     hours: a wrong date is refused" || { echo "  CHYBA  špatné datum výjimky"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
grep -q 'class="ka-oznameni-hodiny"' "$WORK/response" && grep -q 'Inventura' "$WORK/response" && echo "  ok     hours: the notice bar on the site" || { echo "  CHYBA  oznamovací lišta"; ERRORS=$((ERRORS+1)); }
grep -q '"specialOpeningHoursSpecification"' "$WORK/response" && echo "  ok     hours: the exception in the structured data" || { echo "  CHYBA  výjimka ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Hodiny test","slug":"hodiny-test","visible":true,"text":"<p>Dnes: {{hours.today}}. {{hours.status}}</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/hodiny-test"
! grep -q '{{hours' "$WORK/response" && grep -qE 'Dnes: ([0-9]|zavřeno)' "$WORK/response" && echo "  ok     hours: {{hours.today}} and {{hours.status}} filled in" || { echo "  CHYBA  značky hodin"; grep -o 'Dnes:[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_hours '{}' > "$WORK/response"
contains -q 'Monday' "$WORK/response" && contains -q 'Inventura' "$WORK/response" && echo "  ok     MCP list_hours: the week, the exceptions and now" || { echo "  CHYBA  list_hours"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
grep -q 'Inventura' "$WORK/response" && grep -q 'name="exception_from"' "$WORK/response" && echo "  ok     hours: the exceptions in Settings → Company" || { echo "  CHYBA  výjimky v nastavení"; ERRORS=$((ERRORS+1)); }
EXC=$(sq "SELECT id FROM ka_hours_exceptions LIMIT 1")
grep -q "action=hours_sign&amp;exception=$EXC" "$WORK/response" && echo "  ok     hours: every exception has a Door sign link" || { echo "  CHYBA  odkaz na ceduli"; ERRORS=$((ERRORS+1)); }
check "hours: the door sign is a printable page with the note" 200 "/admin.php?module=settings&action=hours_sign&exception=$EXC" "Inventura"
grep -q '<svg class="qr"' "$WORK/response" && grep -q "127.0.0.1:$PORT" "$WORK/response" && grep -q '@page { size: A4' "$WORK/response" && grep -q 'data-tisk' "$WORK/response" && ! grep -q 'admin.css' "$WORK/response" \
  && echo "  ok     hours: the sign carries the QR code with the site address, A4 print CSS and the Print button, outside the admin layout" || { echo "  CHYBA  cedule na dveře"; ERRORS=$((ERRORS+1)); }
check "hours: the A5 sign" 200 "/admin.php?module=settings&action=hours_sign&exception=$EXC&format=a5" "@page { size: A5"
check "hours: a sign for an unknown exception is a 404" 404 "/admin.php?module=settings&action=hours_sign&exception=999999"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=hours_delete" -d "_csrf=$(csrf)" -d "exception=$EXC"
expect "hours: an exception is deleted in the admin" "$(sq "SELECT COUNT(*) FROM ka_hours_exceptions")" "0"
curl -s -o "$WORK/response" "$B/"
! grep -q 'ka-oznameni-hodiny' "$WORK/response" && echo "  ok     hours: without an exception there is no notice bar" || { echo "  CHYBA  lišta zůstala"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '$HOURS_BEFORE' WHERE promenna = 'company_hours'; UPDATE ka_nastaveni SET hodnota = '$TYPE_BEFORE' WHERE promenna = 'company_type'" > /dev/null

echo "== 2.10: links between collections, people"
mcp create_collection '{"name":"Pobočky test","slug":"pobocky-test","item_pages":true,"fields":[{"label":"Město","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"pobocky-test","name":"Praha centrum","slug":"praha-centrum","values":{"mesto":"Praha"},"visible":true}' > /dev/null
mcp create_collection '{"name":"Lidé test","slug":"lide-test","item_pages":true,"redirect_hidden_to":"/pobocky-test","fields":[{"label":"Pobočka","type":"item","collection":"pobocky-test"}]}' > "$WORK/response"
contains -q 'redirect_hidden_to' "$WORK/response" && echo "  ok     collections: Claude links a field to another collection" || { echo "  CHYBA  create_collection s vazbou"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "collections: the link remembers the collection" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].kolekce')) FROM ka_kolekce WHERE seo_link = 'lide-test'")" "pobocky-test"
mcp save_collection_item '{"collection":"lide-test","name":"Jana Nová","slug":"jana-nova","values":{"pobocka":"praha-centrum"},"visible":true}' > /dev/null
curl -s -o "$WORK/response" "$B/lide-test/jana-nova"
grep -q 'Praha centrum' "$WORK/response" && echo "  ok     collections: the item page shows the linked item by its name" || { echo "  CHYBA  propojená položka se neukázala"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"lide-test\",\"id\":$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'"),\"visible\":false}" > /dev/null
expect "people: the page of a hidden person leads to the chosen page (301)" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/lide-test/jana-nova")" "301 $B/pobocky-test"
expect "people: an address that never existed is still not found" "$(curl -s -o /dev/null -w '%{http_code}' "$B/lide-test/nikdo-takovy")" "404"
mcp create_collection '{"name":"Tým","preset":"people"}' > "$WORK/response"
contains -q 'redirect_hidden_to' "$WORK/response" && contains -q 'image' "$WORK/response" && echo "  ok     people: the ready-made team collection" || { echo "  CHYBA  preset people"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 2.10: e-mail signature of a person – field keys by the preset's order (system/presets/people.php: photo, role, languages, phone, email, on_leave, about)
TEAM=$(sq "SELECT seo_link FROM ka_kolekce WHERE schema_org LIKE '%Person%' ORDER BY idk DESC LIMIT 1")
TEAM_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$TEAM'")
team_key() { sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[$1].klic')) FROM ka_kolekce WHERE seo_link = '$TEAM'"; }
mcp save_collection_item "{\"collection\":\"$TEAM\",\"name\":\"Petr Podpis\",\"slug\":\"petr-podpis\",\"values\":{\"$(team_key 1)\":\"Obchodní ředitel\",\"$(team_key 3)\":\"+420 777 123 456\",\"$(team_key 4)\":\"petr@example.cz\",\"$(team_key 5)\":\"Dovolená do pátku\"},\"visible\":true}" > "$WORK/response"
PERSON=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $TEAM_IDK AND seo_link = 'petr-podpis'")
[ -n "$PERSON" ] && echo "  ok     people: a person with a role, a phone and an e-mail" || { echo "  CHYBA  save_collection_item (people)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature "{\"collection\":\"$TEAM\",\"id\":${PERSON:-0}}" > "$WORK/response"
contains -q 'Petr Podpis' "$WORK/response" && contains -q '777 123 456' "$WORK/response" && contains -q 'tel:+420777123456' "$WORK/response" && contains -q 'max-width:600px' "$WORK/response" && ! contains -q 'Dovolen' "$WORK/response" \
  && echo "  ok     people: get_email_signature has the name and the phone in an inline-styled table, never the absence" || { echo "  CHYBA  get_email_signature"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature "{\"collection\":\"$TEAM\",\"slug\":\"petr-podpis\"}" > "$WORK/response"
contains -q 'mailto:petr@example.cz' "$WORK/response" && contains -q 'people_collection\\":true' "$WORK/response" && echo "  ok     people: the signature by the person's address" || { echo "  CHYBA  get_email_signature slug"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "people: the item form offers the e-mail signature" 200 "/admin.php?module=collections&action=item&id=$TEAM_IDK&item=${PERSON:-0}" 'action=signature'
check "people: the admin signature page shows the preview with the copy button" 200 "/admin.php?module=collections&action=signature&id=$TEAM_IDK&item=${PERSON:-0}" 'data-kopirovat-podpis'
grep -q 'Petr Podpis' "$WORK/response" && grep -q 'Obchodní ředitel' "$WORK/response" && grep -q 'href="tel:+420777123456"' "$WORK/response" && ! grep -q 'Dovolen' "$WORK/response" && echo "  ok     people: the preview has the name, the role and the phone, never the absence" || { echo "  CHYBA  signature preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=edit&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'lide-test'")"
grep -q 'name="hidden_redirect"' "$WORK/response" && grep -q 'name="pole\[0\]\[kolekce\]"' "$WORK/response" && echo "  ok     collections: the form offers links and the redirect" || { echo "  CHYBA  formulář kolekce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'lide-test'")&item=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'")"
grep -q '<option value="praha-centrum" selected>Praha centrum</option>' "$WORK/response" && echo "  ok     collections: the item form chooses the linked item" || { echo "  CHYBA  formulář položky s vazbou"; ERRORS=$((ERRORS+1)); }

echo "== 2.10: true until and review by"
YESTERDAY=$(site_time "-1 day" "Y-m-d"); TODAY=$(site_time now "Y-m-d")
# a visible page and a published news item that were true until yesterday and ask for a review today, a pop-up with a review due
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('validity', '$(site_time)' + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)" > /dev/null # not due until the test runs it itself (a day ahead: MySQL and PHP may be in different time zones)
mcp create_page "{\"title\":\"Expired offer\",\"text\":\"<p>Only until yesterday.</p>\",\"visible\":true,\"valid_until\":\"$YESTERDAY\",\"review_by\":\"$TODAY\"}" > "$WORK/response"
VALID_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Expired offer'")
grep -qF "valid_until\\\":\\\"$YESTERDAY" "$WORK/response" && grep -qF "review_by\\\":\\\"$TODAY" "$WORK/response" && echo "  ok     MCP: create_page takes valid_until and review_by and returns them" || { echo "  CHYBA  create_page valid_until/review_by"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_news "{\"title\":\"Expired news\",\"category\":\"$CATEGORY\",\"publish\":true,\"valid_until\":\"$YESTERDAY\",\"review_by\":\"$TODAY\"}" > "$WORK/response"
VALID_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Expired news'")
grep -qF "valid_until\\\":\\\"$YESTERDAY" "$WORK/response" && echo "  ok     MCP: create_news takes valid_until and review_by" || { echo "  CHYBA  create_news valid_until"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_popup "{\"name\":\"Review popup\",\"template\":\"blank\",\"review_by\":\"$YESTERDAY\"}" > "$WORK/response"
grep -qF "review_by\\\":\\\"$YESTERDAY" "$WORK/response" && echo "  ok     MCP: save_popup takes review_by" || { echo "  CHYBA  save_popup review_by"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$VALID_PAGE,\"valid_until\":\"nonsense\"}" > "$WORK/response"
grep -q 'must be a date' "$WORK/response" && echo "  ok     MCP: a value that is not a date is refused" || { echo "  CHYBA  update_page valid_until validation"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "before the job both are still visible" "$(sq "SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = $VALID_PAGE), '|', (SELECT visible FROM ka_novinky WHERE idc = $VALID_NEWS))")" "1|1"
# the hourly job hides what expired, records the events and asks for the reviews – once
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "the job hid the expired page and news item" "$(sq "SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = $VALID_PAGE), '|', (SELECT visible FROM ka_novinky WHERE idc = $VALID_NEWS))")" "0|0"
expect "content.expired events with the kind and id, content.review once per content" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired' AND severity = 'warning' AND data LIKE '%\"kind\":\"page\",\"id\":$VALID_PAGE%'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'))")" "1|2|3"
expect "the change log records what hid itself" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'expired' AND modul IN ('pages', 'news')")" "2"
grep -q 'validity: hidden 2, reviews 3' "$WORK/tasks.txt" && echo "  ok     the job reports what it did" || { echo "  CHYBA  validity job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a second run asks for no review twice and hides nothing again" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'))")" "3|2"
check "the hidden page is no longer on the site" 404 "/expired-offer"
# the site audit lists the content due for a review, with where to fix it
mcp site_audit '{"kind":"review"}' > "$WORK/response"
grep -q 'Expired offer' "$WORK/response" && grep -q 'Expired news' "$WORK/response" && grep -q 'Review popup' "$WORK/response" && grep -q '\\"page\\":' "$WORK/response" && grep -q '\\"news\\":' "$WORK/response" && grep -q '\\"popup\\":' "$WORK/response" \
  && echo "  ok     MCP: site_audit kind review lists the page, the news item and the pop-up with their targets" || { echo "  CHYBA  site_audit review"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "Administration → Site audit shows the review-by findings" 200 "/admin.php?module=audit" "Expired offer"
# the page form has the two fields; saving it with the dates changed is kept
check "the page form shows true until and review by" 200 "/admin.php?module=pages&action=edit&id=$VALID_PAGE" "name=\"valid_until\" value=\"$YESTERDAY\""
grep -q "name=\"review_by\" value=\"$TODAY\"" "$WORK/response" && echo "  ok     the page form shows the review-by date" || { echo "  CHYBA  page form review_by"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$(csrf)" -d "ids=$VALID_PAGE" -d "titulek=Expired offer" -d "seo_link=expired-offer" -d "text=<p>x</p>" -d "poradi=100" -d "valid_until=" -d "review_by=2030-01-01"
expect "saving the page form clears true until and keeps the new review-by date" "$(sq "SELECT CONCAT(IFNULL(valid_until, 'null'), '|', IFNULL(review_by, 'null')) FROM ka_stranky WHERE ids = $VALID_PAGE")" "null|2030-01-01"
check "the pages list shows the review-by badge" 200 "/admin.php?module=pages" "stitek stitek-koncept\" title=\"V tento den žádá o kontrolu.\""
check "the news form shows the two fields" 200 "/admin.php?module=news&action=edit&id=$VALID_NEWS" "name=\"review_by\" value=\"$TODAY\""
check "the pop-up form shows the two fields" 200 "/admin.php?module=popups&action=edit&id=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Review popup'")" "name=\"review_by\" value=\"$YESTERDAY\""
mcp update_page "{\"id\":$VALID_PAGE,\"review_by\":\"\"}" > "$WORK/response"
expect "MCP: an empty string clears review by" "$(sq "SELECT IFNULL(review_by, 'null') FROM ka_stranky WHERE ids = $VALID_PAGE")" "null"
echo "== 2.11: ready-made collections and the date-time, file and location fields"
mcp list_collection_presets '{}' > "$WORK/response"
contains -q 'preset\\":\\"people' "$WORK/response" && contains -q 'how_to_use' "$WORK/response" && contains -q 'existing_collection\\":\\"' "$WORK/response" && echo "  ok     MCP: list_collection_presets lists the presets with their fields and instructions" || { echo "  CHYBA  list_collection_presets"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_collection '{"name":"Náš tým","preset":"people"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "presets: the collection remembers its preset and gets English field keys" "$(sq "SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[3].klic')), '|', nazev) FROM ka_kolekce WHERE seo_link = 'nas-tym'")" "people|phone|Náš tým" \
  || { echo "  CHYBA  create_collection preset people (2.11)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "presets: a hidden page lists the new collection" "$(sq "SELECT CONCAT(zobrazit, '|', stavba LIKE '%\"kolekce\":\"nas-tym\"%', '|', stavba LIKE '%{{photo}}%') FROM ka_stranky WHERE seo_link = 'nas-tym'")" "0|1|1"
contains -q 'list_page' "$WORK/response" && echo "  ok     presets: Claude is told about the hidden list page" || { echo "  CHYBA  list_page"; ERRORS=$((ERRORS+1)); }
expect "presets: the team gets Person structured data mapped to its fields" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.telephone')) FROM ka_kolekce WHERE seo_link = 'nas-tym'")" "phone"
mcp create_collection '{"name":"Nesmysl","preset":"nothing-like-it"}' > "$WORK/response"
contains -q 'list_collection_presets' "$WORK/response" && echo "  ok     presets: an unknown preset names the known ones" || { echo "  CHYBA  unknown preset"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "presets: the collections list offers the ready-made collections" 200 "/admin.php?module=collections" 'name="preset" value="people"'
mcp create_collection '{"name":"Typy polí","slug":"typy-poli","item_pages":true,"fields":[{"label":"Začátek","type":"datetime"},{"label":"Ceník","type":"file"},{"label":"Místo","type":"location"}]}' > /dev/null
expect "fields: Claude's datetime, file and location types" "$(sq "SELECT GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT(pole, CONCAT('\$[', n.i, '].typ'))) ORDER BY n.i) FROM ka_kolekce, (SELECT 0 i UNION SELECT 1 UNION SELECT 2) n WHERE seo_link = 'typy-poli'")" "termin,soubor,poloha"
mcp save_collection_item '{"collection":"typy-poli","name":"Den otevřených dveří","slug":"den-otevrenych-dveri","values":{"zacatek":"2026-11-02T17:00","cenik":"/media/cenik-2026.pdf","misto":"49.1951;16.6068"},"visible":true}' > "$WORK/response"
expect "fields: the date and time, the file and the location are stored clean" "$(sq "SELECT data->>'\$.zacatek', data->>'\$.cenik', data->>'\$.misto' FROM ka_kolekce_polozky WHERE seo_link = 'den-otevrenych-dveri'" | tr '\t' '|')" "2026-11-02 17:00|/media/cenik-2026.pdf|49.1951, 16.6068"
curl -s -o "$WORK/response" "$B/typy-poli/den-otevrenych-dveri"
grep -q '2. 11. 2026 17:00' "$WORK/response" && grep -q 'href="[^"]*media/cenik-2026.pdf"' "$WORK/response" && grep -q 'cenik-2026.pdf)' "$WORK/response" \
  && echo "  ok     fields: the item page shows the day and time and a button to the file with its name" || { echo "  CHYBA  stránka položky s termínem a souborem"; ERRORS=$((ERRORS+1)); }
check "fields: the item form has a date-time input and a Media file picker" 200 "/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'typy-poli'")&item=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'den-otevrenych-dveri'")" 'type="datetime-local" id="pole-zacatek" name="data\[zacatek\]" value="2026-11-02T17:00"'
grep -q 'data-soubor' "$WORK/response" && echo "  ok     fields: the file field opens Media" || { echo "  CHYBA  data-soubor"; ERRORS=$((ERRORS+1)); }
# the period of a Collection list (2.11): upcoming, current and past by a start and an end field – the SQL condition run on real rows
mcp create_collection '{"name":"Období","slug":"obdobi-test","fields":[{"label":"Od","type":"datetime"},{"label":"Do","type":"datetime"}]}' > /dev/null
# dates on the site's clock (site_date, at the top) – the CI runner's shell is UTC, and near midnight they differ
YESTERDAY_D=$(site_date yesterday); TODAY_D=$(site_date today); TOMORROW_D=$(site_date tomorrow)
for row in "vcera|$YESTERDAY_D 10:00|" "dnes-cely-den|$TODAY_D|" "zitra|$TOMORROW_D 09:00|" "probiha|$YESTERDAY_D|$TOMORROW_D" "vyveseno|$YESTERDAY_D|" "bez-data||"; do
  IFS='|' read -r slug od do <<< "$row"
  mcp save_collection_item "{\"collection\":\"obdobi-test\",\"name\":\"$slug\",\"slug\":\"$slug\",\"values\":{\"od\":\"$od\",\"do\":\"$do\"},\"visible\":true}" > /dev/null
done
period() { php -r 'require $argv[1] . "/system/bootstrap.php"; [$sql, $p] = Kaleta\Builder\Collections::periodCondition($argv[2], "od", $argv[3], date("Y-m-d H:i")); echo str_replace("?", "'"'"'" . $p[0] . "'"'"'", $sql);' "$ROOT" "$1" "$2"; }
in_period() { sq "SELECT GROUP_CONCAT(seo_link ORDER BY seo_link) FROM ka_kolekce_polozky WHERE idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 'obdobi-test') AND $(period "$1" "$2")"; }
expect "period: upcoming – not ended (today's whole day counts, an event with an end that has not passed too)" "$(in_period nadchazejici do)" "dnes-cely-den,probiha,zitra"
expect "period: past – ended yesterday" "$(in_period minule do)" "vcera,vyveseno"
expect "period: current – started and not ended; without an end it stays up" "$(in_period probihajici do)" "dnes-cely-den,probiha,vcera,vyveseno"
expect "period: current without an end field – only the start's day" "$(in_period probihajici '')" "dnes-cely-den"
echo "== 2.11: events calendar"
mcp create_collection '{"name":"Akce test","preset":"events"}' > "$WORK/response"
EVENTS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'akce-test' AND preset = 'events'")
[ -n "$EVENTS_IDK" ] && contains -q 'list_page' "$WORK/response" && echo "  ok     events: the preset creates the calendar and its list page" || { echo "  CHYBA  events preset"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "events: the repetition is a choice of known options and the item template has the registration form" "$(sq "SELECT CONCAT(JSON_LENGTH(JSON_EXTRACT(pole, '\$[12].moznosti')), '|', stavba LIKE '%\"typ\":\"formular\"%', '|', stavba LIKE '%{{ical}}%') FROM ka_kolekce WHERE idk = $EVENTS_IDK")" "5|1|1"
TOMORROW_D=$(site_date tomorrow); EIGHT_AGO=$(site_date '-8 days')
SIX_AHEAD=$(site_date '+6 days'); YESTERDAY_D=$(site_date yesterday)
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Jóga, pro začátečníky\",\"slug\":\"joga\",\"values\":{\"start\":\"$TOMORROW_D 18:00\",\"end\":\"$TOMORROW_D 19:30\",\"venue\":\"Sál\",\"address\":\"Hlavní 1, Brno\",\"capacity\":\"1\",\"repeat\":\"weekly\",\"summary\":\"Přineste si podložku.\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Minulá přednáška\",\"slug\":\"minula\",\"values\":{\"start\":\"$YESTERDAY_D 10:00\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Seriál\",\"slug\":\"serial\",\"values\":{\"start\":\"$EIGHT_AGO 18:00\",\"end\":\"$EIGHT_AGO 19:30\",\"repeat\":\"weekly\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Nesmysl\",\"slug\":\"nesmysl\",\"values\":{\"repeat\":\"každé úterý\"}}" > "$WORK/response"
contains -q 'invalid_fields.*repeat' "$WORK/response" && expect "events: a repetition outside the options is refused" "$(sq "SELECT data->>'\$.repeat' FROM ka_kolekce_polozky WHERE idk = $EVENTS_IDK AND seo_link = 'nesmysl'")" "" \
  || { echo "  CHYBA  volba mimo možnosti"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('events', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "events: the job moves an ended weekly event to its next date, keeping the time" "$(sq "SELECT CONCAT(data->>'\$.start', '|', data->>'\$.end') FROM ka_kolekce_polozky WHERE idk = $EVENTS_IDK AND seo_link = 'serial'")" "$SIX_AHEAD 18:00|$SIX_AHEAD 19:30"
grep -q 'events: moved 1' "$WORK/tasks.txt" && echo "  ok     events: the job reports what it moved" || { echo "  CHYBA  events job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'akce-test'"),\"visible\":true}" > /dev/null
curl -s -o "$WORK/response" "$B/akce-test"
grep -q 'Jóga, pro začátečníky' "$WORK/response" && grep -q 'Seriál' "$WORK/response" && ! grep -q 'Minulá přednáška' "$WORK/response" && grep -q 'Sál, Hlavní 1, Brno' "$WORK/response" \
  && echo "  ok     events: the list page shows upcoming events with their date and place, not the past one" || { echo "  CHYBA  seznam akcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/formular.html" "$B/akce-test/joga"
grep -q '"@type":"Event"' "$WORK/formular.html" && grep -q 'OfflineEventAttendanceMode' "$WORK/formular.html" && grep -q 'href="[^"]*akce-test/joga.ics"' "$WORK/formular.html" && grep -q 'class="ka-formular"' "$WORK/formular.html" \
  && echo "  ok     events: the event page has Event data, an Add to calendar link and the registration form" || { echo "  CHYBA  stránka akce"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/response" "$B/akce-test.ics"
grep -qi 'content-type: text/calendar' "$WORK/headers" && grep -q 'SUMMARY:Jóga\\, pro začátečníky' "$WORK/response" && grep -q 'RRULE:FREQ=WEEKLY' "$WORK/response" && grep -q 'LOCATION:Sál\\, Hlavní 1\\, Brno' "$WORK/response" \
  && echo "  ok     events: /<collection>.ics is a calendar to subscribe to" || { echo "  CHYBA  iCal kolekce"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/response" "$B/akce-test/joga.ics"
grep -qi 'content-disposition: attachment; filename="joga.ics"' "$WORK/headers" && [ "$(grep -c 'BEGIN:VEVENT' "$WORK/response")" = 1 ] && echo "  ok     events: one event as a file to add" || { echo "  CHYBA  iCal akce"; ERRORS=$((ERRORS+1)); }
expect "events: a collection that is not a calendar has no .ics" "$(curl -s -o /dev/null -w '%{http_code}' "$B/typy-poli.ics")" "404"
# registration: capacity 1 – the first registration fills it, the form closes and the server refuses another one
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(field_value prvek || true); FORM_TIME=$(field_value as_cas || true); FORM_SIGNATURE=$(field_value as_podpis || true); sleep 4
register() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/akce-test/joga" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d p0=Eva --data-urlencode "p1=$1" -d p4=1; }
case "$(register eva@example.cz)" in *result=ok*) echo "  ok     events: a registration is accepted";; *) echo "  CHYBA  registrace na akci"; ERRORS=$((ERRORS+1));; esac
expect "events: the registration is an enquiry from the event's page" "$(sq "SELECT CONCAT(zdroj, '|', stranka) FROM ka_poptavky ORDER BY idp DESC LIMIT 1")" "kolekce:$EVENTS_IDK|/akce-test/joga"
case "$(register petr@example.cz)" in *result=plno*) echo "  ok     events: a full event refuses another registration on the server";; *) echo "  CHYBA  plná akce přijala registraci"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/akce-test/joga"
grep -q 'Akce je plně obsazená.' "$WORK/response" && ! grep -q 'class="ka-formular"' "$WORK/response" && echo "  ok     events: the page of a full event shows it is full instead of the form" || { echo "  CHYBA  plná akce stále ukazuje formulář"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items '{"collection":"akce-test"}' > "$WORK/response"
contains -q 'state\\":\\"full' "$WORK/response" && contains -q 'places_left\\":0' "$WORK/response" && echo "  ok     events: Claude sees the registrations and that it is full" || { echo "  CHYBA  registrace v list_collection_items"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/akce-test/minula"
grep -q 'Akce už skončila.' "$WORK/response" && ! grep -q 'class="ka-formular"' "$WORK/response" && echo "  ok     events: a past event says it has ended and takes no registrations" || { echo "  CHYBA  proběhlá akce"; ERRORS=$((ERRORS+1)); }
echo "== 2.11: product catalogue without a checkout"
mcp create_collection '{"name":"Produkty test","preset":"products"}' > /dev/null
PRODUCTS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty-test' AND preset = 'products'")
mcp save_collection_item '{"collection":"produkty-test","name":"Lehátko Basic","slug":"lehatko-basic","values":{"code":"LB-1","category":"Lehátka","price":"12900","parameters":"Šířka: 60 cm\nNosnost: 150 kg","variants":"Modrá | LB-1-M | 12 900 Kč\nŠedá | LB-1-S"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-test","name":"Lehátko Pro","slug":"lehatko-pro","values":{"code":"LP-2","category":"Lehátka","parameters":"Šířka: 70 cm\nMotor: 2 kW"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-test","name":"Špatné","slug":"spatne","values":{"parameters":"jen text bez hodnoty"}}' > "$WORK/response"
contains -q 'invalid_fields.*parameters' "$WORK/response" && echo "  ok     products: parameters without a value are refused" || { echo "  CHYBA  parametry bez hodnoty"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/produkty-test/lehatko-basic"
grep -q '<th scope="row">Nosnost</th><td>150 kg</td>' "$WORK/response" && grep -q 'class="ka-do-poptavky"' "$WORK/response" && grep -q '<option>Šedá</option>' "$WORK/response" && grep -q '"@type":"Product"' "$WORK/response" \
  && echo "  ok     products: the product page has the parameters, Add to enquiry with variants and Product data" || { echo "  CHYBA  stránka produktu"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web.js' "$WORK/response" && ! grep -q 'href="#"' "$WORK/response" && echo "  ok     products: the page keeps the basket script and has no button to a datasheet it does not have" || { echo "  CHYBA  web.js nebo prázdné tlačítko na stránce produktu"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/produkty-test/_porovnat?i=lehatko-basic,lehatko-pro,neni"
grep -q '<th scope="row">Šířka</th><td>60 cm</td><td>70 cm</td>' "$WORK/response" && grep -q '<th scope="row">Motor</th><td></td><td>2 kW</td>' "$WORK/response" && grep -q 'noindex' "$WORK/response" \
  && echo "  ok     products: the comparison puts the parameters side by side" || { echo "  CHYBA  porovnání produktů"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/compare-en.html" "$B/produkty-test/_compare?i=lehatko-basic,lehatko-pro,neni"
grep -q '<th scope="row">Šířka</th><td>60 cm</td><td>70 cm</td>' "$WORK/compare-en.html" && curl -s "$B/produkty-test/lehatko-basic" | contains -q 'data-porovnani="/produkty-test/_compare"' \
  && echo "  ok     3.7: the comparison at /_compare (the older /_porovnat above), linked from the product page" || { echo "  CHYBA  3.7: comparison at /_compare"; ERRORS=$((ERRORS+1)); }
expect "products: a comparison without known products is not found" "$(curl -s -o /dev/null -w '%{http_code}' "$B/produkty-test/_porovnat?i=neni")" "404"
expect "products: a collection that is not a catalogue has no comparison" "$(curl -s -o /dev/null -w '%{http_code}' "$B/typy-poli/_porovnat?i=den-otevrenych-dveri")" "404"
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'produkty-test'"),\"visible\":true}" > /dev/null
# without the script: Add to enquiry opens the list page with the product, the basket field has it
curl -s -o "$WORK/formular.html" "$B/produkty-test?product=produkty-test/lehatko-basic&variant=$(php -r 'echo rawurlencode("Šedá");')&quantity=2"
grep -q 'data-kosik-pole' "$WORK/formular.html" && grep -q '2 × Lehátko Basic – Šedá (LB-1-S)' "$WORK/formular.html" && echo "  ok     products: the enquiry form takes the product from the address" || { echo "  CHYBA  košík bez skriptu"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(grep -o 'name="prvek" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//'); FORM_TIME=$(grep -o 'name="as_cas" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//')
FORM_SIGNATURE=$(grep -o 'name="as_podpis" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//'); sleep 4
basket_send() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/produkty-test" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode "p0=$1" -d p1=Eva -d p2=eva@example.cz -d p5=1; }
case "$(basket_send '[{"c":"produkty-test","i":"lehatko-pro","v":"Zlatá","q":1}]')" in *result=pole*) echo "  ok     products: a variant the product does not have is refused";; *) echo "  CHYBA  neexistující varianta v košíku"; ERRORS=$((ERRORS+1));; esac
case "$(basket_send '[]')" in *result=pole*) echo "  ok     products: an empty basket is refused";; *) echo "  CHYBA  prázdný košík"; ERRORS=$((ERRORS+1));; esac
case "$(basket_send '[{"c":"produkty-test","i":"lehatko-basic","v":"Modrá","q":3},{"c":"produkty-test","i":"lehatko-pro","v":"","q":1,"n":"<script>"}]')" in *result=ok*) echo "  ok     products: the basket is sent";; *) echo "  CHYBA  odeslání košíku"; ERRORS=$((ERRORS+1));; esac
sq "SELECT data FROM ka_poptavky ORDER BY idp DESC LIMIT 1" > "$WORK/response"
grep -q '3 × Lehátko Basic – Modrá (LB-1-M)' "$WORK/response" && grep -q '1 × Lehátko Pro (LP-2)' "$WORK/response" && ! grep -q 'script' "$WORK/response" \
  && echo "  ok     products: the enquiry lists the products as the database has them, never the visitor's text" || { echo "  CHYBA  řádky košíku v poptávce"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
echo "== 2.11: industry blueprints"
cat > "$WORK/blueprint.json" <<'JSON'
{"kaleta_blueprint":1,"key":"dental_test","name":{"en":"Dental clinic","cs":"Zubní ordinace"},"description":"For dentists","presets":["people","faq_unknown_is_refused"],
 "facts":[{"key":"insurers","label":"Insurers","type":"text"}],"questions":[{"question":"Which insurers do you have contracts with?","fact":"insurers"}],
 "audit":[{"check":"fact","fact":"insurers","message":"Say which insurers you work with."},{"check":"preset_items","preset":"people","min":1,"message":"Add the doctors."}],
 "claude":"Patients look for insurers first; never give medical advice."}
JSON
mcp apply_blueprint "{\"manifest\":$(cat "$WORK/blueprint.json")}" > "$WORK/response"
contains -q 'faq_unknown_is_refused' "$WORK/response" && expect "blueprints: a manifest with an unknown preset is refused whole" "$(sq "SELECT COUNT(*) FROM ka_blueprints")" "0" || { echo "  CHYBA  neplatný plán"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sed -i.bak 's/,"faq_unknown_is_refused"//' "$WORK/blueprint.json"
sq "UPDATE ka_kolekce SET preset = '' WHERE preset = 'people'" > /dev/null # the earlier tests made teams; this site has none from the preset
mcp apply_blueprint "{\"manifest\":$(cat "$WORK/blueprint.json")}" > "$WORK/response"
contains -q 'created_facts.*insurers' "$WORK/response" && expect "blueprints: applying creates the team collection, the fact without a value and keeps the manifest" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'people'), '|', (SELECT CONCAT(label, '=', value) FROM ka_facts WHERE fact_key = 'insurers'), '|', (SELECT bkey FROM ka_blueprints WHERE nazev IN ('Zubní ordinace', 'Dental clinic')))")" "1|Insurers=|dental_test" \
  || { echo "  CHYBA  apply_blueprint"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_blueprint '{}' > "$WORK/response"
contains -q 'Which insurers do you have contracts with' "$WORK/response" && contains -q 'Say which insurers you work with' "$WORK/response" && contains -q 'Add the doctors' "$WORK/response" \
  && echo "  ok     blueprints: Claude sees the open question and the failing checks" || { echo "  CHYBA  get_blueprint"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | contains 'never give medical advice' \
  && echo "  ok     blueprints: the instructions of every Claude connection include the blueprint's" || { echo "  CHYBA  pokyny plánu v MCP"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"blueprint"}' > "$WORK/response"
contains -q 'Say which insurers you work with' "$WORK/response" && echo "  ok     blueprints: the site audit runs its checks" || { echo "  CHYBA  audit plánu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"insurers","value":"VZP, OZP"}' > /dev/null
mcp save_collection_item "{\"collection\":\"$(sq "SELECT seo_link FROM ka_kolekce WHERE preset = 'people' LIMIT 1")\",\"name\":\"MUDr. Test\",\"visible\":true}" > /dev/null
mcp site_audit '{"kind":"blueprint"}' > "$WORK/response"
! contains -q 'Say which insurers' "$WORK/response" && ! contains -q 'Add the doctors' "$WORK/response" && echo "  ok     blueprints: answered and filled in, the checks pass" || { echo "  CHYBA  kontroly plánu po doplnění"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "blueprints: the admin page shows the applied blueprint and its question with the answer" 200 "/admin.php?module=blueprints" 'value="VZP, OZP"'
mcp export_blueprint '{"key":"my_clinic","name":"My clinic"}' > "$WORK/response"
contains -q 'kaleta_blueprint' "$WORK/response" && contains -q 'insurers' "$WORK/response" && ! contains -q 'VZP' "$WORK/response" && contains -q 'people' "$WORK/response" \
  && echo "  ok     blueprints: the export has the presets and the facts, never their values" || { echo "  CHYBA  export_blueprint"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -D "$WORK/headers" -o "$WORK/response" "$B/admin.php?module=blueprints&action=export&key=my_clinic&name=Moje"
grep -qi 'filename="my_clinic.blueprint.json"' "$WORK/headers" && php -r 'exit(json_decode(file_get_contents($argv[1]), true)["kaleta_blueprint"] === 1 ? 0 : 1);' "$WORK/response" \
  && echo "  ok     blueprints: the admin downloads the site as a blueprint file" || { echo "  CHYBA  stažení plánu"; ERRORS=$((ERRORS+1)); }
mcp remove_blueprint '{"key":"dental_test"}' > /dev/null
expect "blueprints: removing keeps the collection and the fact" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'people'), '|', (SELECT value FROM ka_facts WHERE fact_key = 'insurers'))")" "0|1|VZP, OZP"
# 3.3: twenty blueprints in groups with a search; get_blueprint tells Claude what a manifest of its own may contain
check "3.3 blueprints: the screen groups the shipped blueprints, has a search and an Ask Claude box" 200 "/admin.php?module=blueprints" 'data-filtr-karet'
[ "$(grep -o 'data-filtr-skupina' "$WORK/response" | wc -l | tr -d ' ')" -ge 5 ] && grep -q 'name="quick" value="1"' "$WORK/response" && echo "  ok     3.3 blueprints: at least five groups and the request form for a blueprint of one's own" || { echo "  CHYBA  3.3 blueprint groups"; ERRORS=$((ERRORS+1)); }
mcp get_blueprint '{}' > "$WORK/response"
contains -q 'manifest_format' "$WORK/response" && contains -q 'audit_rules' "$WORK/response" && contains -q 'group.*software_saas\|software_saas.*group' "$WORK/response" \
  && echo "  ok     3.3 blueprints: get_blueprint lists the groups and the manifest format for draft_blueprint" || { echo "  CHYBA  get_blueprint 3.3"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
SAAS_IDK0=$(sq "SELECT IFNULL(MAX(idk), 0) FROM ka_kolekce")
mcp apply_blueprint '{"key":"software_saas"}' > "$WORK/response"
expect "3.3 blueprints: the software company blueprint creates the pricing plans collection" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'plans'")" "1"
# the site as before (later blocks make their own collections): the blueprint off, its collections and their hidden pages gone
mcp remove_blueprint '{"key":"software_saas"}' > /dev/null
for slug in $(sq "SELECT seo_link FROM ka_kolekce WHERE idk > $SAAS_IDK0"); do mcp delete_collection "{\"collection\":\"$slug\"}" > /dev/null; sq "DELETE FROM ka_stranky WHERE seo_link IN ('$slug', '$slug-archive') AND zobrazit = 0" > /dev/null; done
echo "== 2.11: job openings that close themselves"
mcp create_collection '{"name":"Volná místa","preset":"jobs"}' > "$WORK/response"
JOBS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE preset = 'jobs'")
expect "jobs: the preset brings JobPosting data, the contact linked to the team, the redirect of hidden jobs to the jobs page and an item template with a form and a CV field" \
  "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.employmentType')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[8].kolekce')) = (SELECT seo_link FROM ka_kolekce WHERE preset = 'people' ORDER BY idk LIMIT 1), '|', hidden_redirect, '|', stavba LIKE '%{{nazev}}%' AND stavba LIKE '%\"typ\":\"formular\"%' AND stavba LIKE '%\"typ\":\"soubor\"%') FROM ka_kolekce WHERE idk = $JOBS_IDK")" "JobPosting|employment_type|1|/volna-mista|1"
contains -q 'valid_until' "$WORK/response" && echo "  ok     jobs: Claude is told to always set the closing date (valid_until)" || { echo "  CHYBA  jobs how_to_use"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_kolekce SET schema_org = JSON_SET(schema_org, '\$.mena', 'CZK') WHERE idk = $JOBS_IDK" > /dev/null # the collection currency: salaries are published only with it
JOB_TOMORROW=$(site_time "+1 day" "Y-m-d"); JOB_YESTERDAY=$(site_time "-1 day" "Y-m-d")
mcp save_collection_item "{\"collection\":\"volna-mista\",\"name\":\"Truhlář\",\"slug\":\"truhlar\",\"values\":{\"location\":\"Brno\",\"employment_type\":\"plný úvazek\",\"salary_min\":\"35000\",\"salary_max\":\"45000\",\"salary_unit\":\"za měsíc\",\"description\":\"<p>Výroba nábytku na míru.</p>\"},\"visible\":true,\"valid_until\":\"$JOB_TOMORROW\"}" > "$WORK/response"
curl -s -o "$WORK/job.html" "$B/volna-mista/truhlar"
grep -qF '"@type":"JobPosting"' "$WORK/job.html" && grep -qF "\"validThrough\":\"$JOB_TOMORROW\"" "$WORK/job.html" && grep -qF '"employmentType":"FULL_TIME"' "$WORK/job.html" && grep -qF '"addressLocality":"Brno","addressCountry":"CZ"' "$WORK/job.html" \
  && grep -qF '"baseSalary":{"@type":"MonetaryAmount","currency":"CZK","value":{"@type":"QuantitativeValue","minValue":35000,"maxValue":45000,"unitText":"MONTH"}}' "$WORK/job.html" && grep -qF '"hiringOrganization":{"@type":"Organization","name":"' "$WORK/job.html" \
  && echo "  ok     jobs: the item page carries a JobPosting with validThrough, the company, the place and the salary" || { echo "  CHYBA  JobPosting on the item page"; grep -o '"@type":"JobPosting".*' "$WORK/job.html" | head -c 700; ERRORS=$((ERRORS+1)); }
grep -q 'name="p3" type="file"' "$WORK/job.html" && grep -qF 'type="hidden" name="p6" value="Truhlář"' "$WORK/job.html" && grep -q 'enctype="multipart/form-data"' "$WORK/job.html" \
  && echo "  ok     jobs: the application form has a CV field and carries the job name in a hidden field" || { echo "  CHYBA  application form on the item page"; ERRORS=$((ERRORS+1)); }
job_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/job.html" | head -1 | sed 's/.*value="//;s/"$//'; }
JOB_SOURCE=$(job_field zdroj); JOB_ELEMENT=$(job_field prvek); JOB_TIME=$(job_field as_cas); JOB_SIGNATURE=$(job_field as_podpis)
expect "jobs: the form is served from the collection's item template (the source of its enquiries)" "$JOB_SOURCE" "kolekce:$JOBS_IDK"
# a job whose closing date passed hides itself and its address leads to the jobs page; a job without a closing date is in the audit
mcp save_collection_item "{\"collection\":\"volna-mista\",\"name\":\"Svářeč\",\"slug\":\"svarec\",\"values\":{\"location\":\"Brno\"},\"visible\":true,\"valid_until\":\"$JOB_YESTERDAY\"}" > /dev/null
mcp save_collection_item '{"collection":"volna-mista","name":"Bez uzaverky","slug":"bez-uzaverky","values":{"location":"Praha"},"visible":true}' > /dev/null
check "jobs: before the validity job the job that closed yesterday still has its page" 200 "/volna-mista/svarec" "Svářeč"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "jobs: the validity job hid the job whose closing date passed, the open ones stay" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, '=', zobrazit) ORDER BY seo_link) FROM ka_kolekce_polozky WHERE idk = $JOBS_IDK")" "bez-uzaverky=1,svarec=0,truhlar=1"
case "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/volna-mista/svarec")" in "301 $B/volna-mista") echo "  ok     jobs: the closed job's address leads to the jobs page";; *) echo "  CHYBA  closed job redirect: $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/volna-mista/svarec")"; ERRORS=$((ERRORS+1));; esac
mcp site_audit '{"kind":"job"}' > "$WORK/response"
grep -q 'Bez uzaverky' "$WORK/response" && ! grep -q 'Truhl' "$WORK/response" && contains -q 'job\\":1' "$WORK/response" && contains -q 'collection\\":\\"volna-mista' "$WORK/response" \
  && echo "  ok     MCP: site_audit kind job lists only the visible job without a closing date, with its target" || { echo "  CHYBA  site_audit job"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "Administration → Site audit lists the job opening without a closing date" 200 "/admin.php?module=audit" "Bez uzaverky"
# the retention of applications next to the enquiries retention, with the usual practice of the company country as a hint
check "jobs: Enquiries offers the retention of job applications with the usual practice for the company country" 200 "/admin.php?module=enquiries" 'name="mesice_uchazeci" value="0"'
grep -q 'CZ: 6' "$WORK/response" && echo "  ok     jobs: the hint names the country and the months" || { echo "  CHYBA  retention hint"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=settings" -d "_csrf=$(csrf)" -d mesice=24 -d mesice_uchazeci=3
expect "jobs: the retention of applications is saved next to the enquiries retention" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'job_applications_months'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'))")" "3|24"
# an application with a CV: an enquiry from the job's page; the hidden job name comes back as plain text only
printf '%%PDF-1.4 test CV\n' > "$WORK/cv.pdf"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=$JOB_SOURCE" -F "prvek=$JOB_ELEMENT" -F zpet=/volna-mista/truhlar -F "as_cas=$JOB_TIME" -F "as_podpis=$JOB_SIGNATURE" \
  -F p0=Jan -F p1=jan@example.cz -F p2= -F "p3=@$WORK/cv.pdf" --form-string "p4=Hlásím se." -F p5=1 --form-string "p6=<b>Truhlář</b>") # --form-string: a value starting with < would be read as a file by -F
case "$location" in *"/volna-mista/truhlar?form=$JOB_ELEMENT&result=ok#"*) echo "  ok     jobs: an application with a CV was sent";; *) echo "  CHYBA  application: $location"; ERRORS=$((ERRORS+1));; esac
APP_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE zdroj = 'kolekce:$JOBS_IDK'")
expect "jobs: the application is an enquiry from the job's page with the job name as plain text and the CV outside the web root" \
  "$(sq "SELECT CONCAT(stranka, '|', email, '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[6][1]')), '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) REGEXP '^[0-9]{4}/[0-9]{2}/[a-f0-9]{24}[.]pdf$') FROM ka_poptavky WHERE idp = $APP_IDP")" "/volna-mista/truhlar|jan@example.cz|Truhlář|1"
CV_PATH=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) FROM ka_poptavky WHERE idp = $APP_IDP")
[ -n "$CV_PATH" ] && [ -f "$WORK/web/storage/prilohy/$CV_PATH" ] && echo "  ok     jobs: the CV is stored in storage/prilohy" || { echo "  CHYBA  CV file missing: $CV_PATH"; ERRORS=$((ERRORS+1)); }
# the daily clean-up deletes applications past their retention (3 months) with the CV and records it; an ordinary enquiry of the same age stays (24 months)
ENQ_IDP=$(sq "SELECT MIN(idp) FROM ka_poptavky WHERE zdroj LIKE 'stranka:%'")
sq "UPDATE ka_poptavky SET datum = '$(site_time)' - INTERVAL 4 MONTH WHERE idp IN ($APP_IDP, $ENQ_IDP)" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "jobs: the clean-up deleted the application after its retention and recorded it; the ordinary enquiry of the same age stays" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE idp = $APP_IDP), '|', (SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQ_IDP), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'applications.purged' AND data LIKE '%\"count\":1,\"months\":3%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'purge_applications'))")" "0|1|1|1"
[ ! -f "$WORK/web/storage/prilohy/$CV_PATH" ] && echo "  ok     jobs: the CV was deleted with the application" || { echo "  CHYBA  CV file still there after the clean-up"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'job_applications_months'" > /dev/null
echo "== 2.11: document library – versions, the stable latest address, download counts, gated downloads"
mcp create_collection '{"name":"Dokumenty","preset":"documents"}' > "$WORK/response"
DOCS=$(sq "SELECT seo_link FROM ka_kolekce WHERE preset = 'documents' ORDER BY idk DESC LIMIT 1"); DOCS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$DOCS'")
expect "documents: the preset brings the file, category, version, summary and issued fields and item pages" "$(sq "SELECT CONCAT(detail, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].klic')), ':', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[4].klic'))) FROM ka_kolekce WHERE idk = $DOCS_IDK")" "1|file:soubor|issued"
expect "documents: the item template downloads through {{latest}} and lists {{versions}}; the list page sorts by name and filters by category" "$(sq "SELECT CONCAT(stavba LIKE '%{{latest}}%', stavba LIKE '%{{versions}}%', (SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', stavba LIKE '%\"razeni\":\"nazev\"%') FROM ka_stranky WHERE seo_link = '$DOCS')) FROM ka_kolekce WHERE idk = $DOCS_IDK")" "1111"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"name\":\"Ceník\",\"slug\":\"cenik\",\"values\":{\"file\":\"/media/cenik-v1.pdf\",\"version\":\"1.0\",\"category\":\"Ceníky\",\"summary\":\"Platný ceník.\",\"issued\":\"2026-01-10\"},\"visible\":true}" > "$WORK/response"
DOC=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $DOCS_IDK AND seo_link = 'cenik'")
expect "documents: save_collection_item returns the stable address of the file" "$(mcp_value latest_url)" "$B/$DOCS/cenik/latest"
expect "documents: a new document has no previous version" "$(sq "SELECT COUNT(*) FROM ka_document_versions WHERE idp = $DOC")" "0"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"values\":{\"file\":\"/media/cenik-v2.pdf\",\"version\":\"2.0\"}}" > /dev/null
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"values\":{\"summary\":\"Platný ceník, nové ceny.\"}}" > /dev/null
expect "documents: a changed file keeps the previous file and its version for good, a save without a file change keeps nothing" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(file), '|', MAX(version), '|', MAX(replaced_by) LIKE 'Tester%') FROM ka_document_versions WHERE idp = $DOC")" "1|/media/cenik-v1.pdf|1.0|1"
curl -s -o "$WORK/response" "$B/$DOCS/cenik"
grep -q "href=\"/$DOCS/cenik/latest\"" "$WORK/response" && grep -q 'cenik-v2.pdf)' "$WORK/response" && grep -q 'href="/media/cenik-v1.pdf">cenik-v1.pdf · Verze 1.0</a>' "$WORK/response" && grep -q 'Předchozí verze' "$WORK/response" \
  && echo "  ok     documents: the item page downloads through the stable address and lists the previous version with its number" || { echo "  CHYBA  stránka dokumentu"; grep -o 'ka-dokument-verze.*</ul>' "$WORK/response" | head -c 400; ERRORS=$((ERRORS+1)); }
expect "documents: /…/latest answers 302 to the current file" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code} %{redirect_url}' "$B/$DOCS/cenik/latest")" "302 $B/media/cenik-v2.pdf"
curl -s -A 'Mozilla/5.0 test' -o /dev/null -D "$WORK/headers" "$B/$DOCS/cenik/latest"
grep -qi '^Cache-Control: no-store' "$WORK/headers" && echo "  ok     documents: the redirect to the file is never cached" || { echo "  CHYBA  latest Cache-Control"; cat "$WORK/headers"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/$DOCS/cenik/latest" # curl's own user agent counts as a bot
expect "documents: two downloads from one address within an hour count once, a bot never" "$(sq "SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = $DOC")" "1"
check "documents: the admin items list shows the downloads (30 days / total)" 200 "/admin.php?module=collections&action=items&id=$DOCS_IDK" '<td class="cislo stazeni">1 / 1</td>'
grep -q "href=\"/$DOCS/cenik/latest\"" "$WORK/response" && echo "  ok     documents: the admin items list links the stable address" || { echo "  CHYBA  admin latest link"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items "{\"collection\":\"$DOCS\"}" > "$WORK/response"
expect "MCP: list_collection_items carries the downloads and the stable address of a document" "$(mcp_value items 0 downloads total)|$(mcp_value items 0 downloads last_30_days)|$(mcp_value items 0 latest_url)" "1|1|$B/$DOCS/cenik/latest"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":false}" > /dev/null
expect "documents: a hidden document has no download address" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$B/$DOCS/cenik/latest")" "404"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":true,\"valid_until\":\"$YESTERDAY\"}" > /dev/null
expect "documents: an expired document has no download address even before the hourly job hides it" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$B/$DOCS/cenik/latest")" "404"
IN_TEN_DAYS=$(site_time "+10 days" "Y-m-d")
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":true,\"valid_until\":\"$IN_TEN_DAYS\"}" > /dev/null
mcp site_audit '{"kind":"document"}' > "$WORK/response"
expect "documents: the site audit warns 30 days before a document expires, with the item to fix" "$(mcp_value total)|$(mcp_value findings 0 target item)" "1|$DOC"
check "Administration → Site audit shows the expiring document" 200 "/admin.php?module=audit" "Dokument platí do"
# gated downloads: a form that e-mails a file after sending – the enquiry records it, the e-mail carries a signed link (fake SMTP)
SMTP2_PORT=$((PORT + 7)); mkdir -p "$WORK/smtp2"
command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$WORK/smtp2" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
mcp create_page '{"title":"Ceník e-mailem","slug":"cenik-emailem","visible":true}' > /dev/null
GATE_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'cenik-emailem'")
mcp stavba_uloz "{\"id\":$GATE_PAGE,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"gate123\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Ceník na e-mail\",\"poslat_soubor\":\"/media/cenik-v2.pdf\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}]}}" > "$WORK/response"
expect "gated: the published form keeps the file to send" "$(sq "SELECT stavba LIKE '%\"poslat_soubor\":\"/media/cenik-v2.pdf\"%' FROM ka_stranky WHERE ids = $GATE_PAGE")" "1"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/cenik-emailem"
GATE_SOURCE=$(field_value zdroj); GATE_ELEMENT=$(field_value prvek); GATE_TIME=$(field_value as_cas); GATE_SIGNATURE=$(field_value as_podpis)
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$GATE_SOURCE" -d "prvek=$GATE_ELEMENT" -d zpet=/cenik-emailem -d "as_cas=$GATE_TIME" -d "as_podpis=$GATE_SIGNATURE" --data-urlencode p0=gate@example.cz)
case "$location" in *result=ok*) echo "  ok     gated: the form was sent";; *) echo "  CHYBA  gated form: $location"; ERRORS=$((ERRORS+1));; esac
expect "gated: the enquiry records which file was sent" "$(sq "SELECT data LIKE '%Soubor poslan% e-mailem%cenik-v2.pdf%' FROM ka_poptavky WHERE email = 'gate@example.cz'")" "1"
# a plain-text e-mail: one base64 body (the eml helper above decodes the parts of a multipart newsletter)
gate_mail() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n", base64_decode($b);' "$1"; }
F=$(grep -l "^X-Rcpt-To: gate@example.cz" "$WORK"/smtp2/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
GATE_LINK=$(grep -o "http://127.0.0.1:$PORT/download/[A-Za-z0-9._-]*" "$WORK/eml.txt" | head -1 || true)
[ -n "$GATE_LINK" ] && grep -q '^Subject-Decoded: Váš soubor z webu' "$WORK/eml.txt" && grep -q 'cenik-v2.pdf' "$WORK/eml.txt" && echo "  ok     gated: the visitor got an e-mail with the file name and the download link" || { echo "  CHYBA  gated e-mail"; head -30 "$WORK/eml.txt"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'stazeni'" > /dev/null # an hour has passed for the counter
expect "gated: the link redirects to the file and counts the download of the document" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code} %{redirect_url}' "$GATE_LINK")|$(sq "SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = $DOC")" "302 $B/media/cenik-v2.pdf|2"
case "${GATE_LINK: -1}" in a) TAMPERED="${GATE_LINK%?}b";; *) TAMPERED="${GATE_LINK%?}a";; esac
expect "gated: a tampered token is not found" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$TAMPERED")|$(curl -s -o /dev/null -w '%{http_code}' "$B/download/nonsense.token.here")" "404|404"
kill "$SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
echo "== 2.11: branches (LocalBusiness) and the store locator"
mcp create_collection '{"name":"Pobočky","preset":"branches"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "branches: the collection remembers its preset, has a location field and LocalBusiness data from it" \
  "$(sq "SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.geo')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.openingHours'))) FROM ka_kolekce WHERE seo_link = 'pobocky'")" "branches|location|poloha|location|hours" \
  || { echo "  CHYBA  create_collection preset branches"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "branches: the item template brings the photo, the hours and a click-to-load map of the address" "$(sq "SELECT CONCAT(stavba LIKE '%{{photo}}%', stavba LIKE '%<p>{{hours}}</p>%', stavba LIKE '%\"adresa\":\"{{address}}\"%') FROM ka_kolekce WHERE seo_link = 'pobocky'")" "111"
mcp save_collection_item '{"collection":"pobocky","name":"Brno","slug":"brno","values":{"address":"Náměstí Svobody 1, 602 00 Brno","location":"49.1951, 16.6068","phone":"+420 123 456 789","email":"brno@example.com","hours":"Mo-Fr 9-17\nSa 9-12"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"pobocky","name":"Praha","slug":"praha","values":{"address":"Václavské náměstí 1, 110 00 Praha","location":"50.0813, 14.4275","phone":"+420 987 654 321","hours":"by appointment"},"visible":true}' > /dev/null
mcp create_page '{"title":"Kde nás najdete","slug":"kde-nas-najdete","visible":true}' > /dev/null
LOCATOR_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'kde-nas-najdete'")
mcp save_build "{\"id\":$LOCATOR_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"store_locator\"}]}]}}" > "$WORK/response"
contains -q 'published' "$WORK/response" && expect "store locator: Claude places the element by its English name, stored under its own type" "$(sq "SELECT stavba LIKE '%\"typ\":\"pobocky\"%' FROM ka_stranky WHERE ids = $LOCATOR_PAGE")" "1" \
  || { echo "  CHYBA  save_build store_locator"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp builder_schema '{"elements":["store_locator"]}' > "$WORK/response"
contains -q 'location_field' "$WORK/response" && contains -q 'show_map' "$WORK/response" && echo "  ok     store locator: builder_schema describes the element and its English options" || { echo "  CHYBA  builder_schema store_locator"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kde-nas-najdete"
grep -q 'data-pobocky' "$WORK/response" && grep -q 'href="[^"]*/pobocky/brno"' "$WORK/response" && grep -q 'href="[^"]*/pobocky/praha"' "$WORK/response" \
  && echo "  ok     store locator: the page lists both branches with links to their pages (no JavaScript needed)" || { echo "  CHYBA  store locator list"; ERRORS=$((ERRORS+1)); }
grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q 'href="tel:+420987654321"' "$WORK/response" && grep -q 'href="mailto:brno@example.com"' "$WORK/response" \
  && echo "  ok     store locator: phones as tel: links, the e-mail as mailto:" || { echo "  CHYBA  store locator tel/mailto"; ERRORS=$((ERRORS+1)); }
grep -q 'maps/search/?api=1&amp;query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody' "$WORK/response" && grep -q '>Trasa<' "$WORK/response" && echo "  ok     store locator: a Directions link to a maps search of the address" || { echo "  CHYBA  store locator directions"; ERRORS=$((ERRORS+1)); }
grep -q 'data-lat="49.1951" data-lng="16.6068"' "$WORK/response" && grep -q 'data-lat="50.0813" data-lng="14.4275"' "$WORK/response" && grep -q 'data-text="brno n' "$WORK/response" \
  && echo "  ok     store locator: data-lat/data-lng and the search text for the script" || { echo "  CHYBA  store locator data attributes"; ERRORS=$((ERRORS+1)); }
grep -q 'data-hledat' "$WORK/response" && grep -q 'data-nejblizsi>Nejblíže ke mně<' "$WORK/response" && grep -q 'data-mapa aria-controls="pobocky-mapa-' "$WORK/response" && grep -q 'class="ka-pobocky-mapa" id="pobocky-mapa-' "$WORK/response" \
  && grep -q 'data-leaflet="[^"]*/image/vendor/leaflet/"' "$WORK/response" && grep -q 'data-atribuce="© OpenStreetMap contributors"' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     store locator: search, nearest and map controls in Czech, the Leaflet path and attribution, web.js kept on the page" || { echo "  CHYBA  store locator controls"; ERRORS=$((ERRORS+1)); }
check "store locator: Leaflet 1.9.4 is served from the site itself" 200 "/image/vendor/leaflet/leaflet.js" "Leaflet 1.9.4"
check "store locator: the Leaflet stylesheet and marker are there" 200 "/image/vendor/leaflet/leaflet.css" "leaflet-marker-icon"
check "store locator: the Leaflet licence is shipped" 200 "/image/vendor/leaflet/LICENSE" "BSD 2-Clause"
# the structured data of a branch page: the LocalBusiness node of the graph (the company node is there too, so the whole page is not enough)
branch_node() { php -r 'preg_match("#<script type=\"application/ld\+json\">(.*?)</script>#s", (string) file_get_contents($argv[1]), $m); foreach (json_decode($m[1] ?? "{}", true)["@graph"] ?? [] as $n) { if (($n["@type"] ?? "") === "LocalBusiness") { echo json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); } }' "$WORK/response"; }
curl -s -o "$WORK/response" "$B/pobocky/brno"
BRNO_NODE=$(branch_node)
case "$BRNO_NODE" in *'"geo":{"@type":"GeoCoordinates","latitude":49.1951,"longitude":16.6068}'*'"openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday","Tuesday","Wednesday","Thursday","Friday"],"opens":"09:00","closes":"17:00"},{"@type":"OpeningHoursSpecification","dayOfWeek":["Saturday"],"opens":"09:00","closes":"12:00"}]'*'"parentOrganization":{"@id":'*)
  echo "  ok     branches: the branch page carries LocalBusiness data with the geo, the opening hours and the company as parent";;
  *) echo "  CHYBA  LocalBusiness JSON-LD (brno)"; echo "$BRNO_NODE" | head -c 600; ERRORS=$((ERRORS+1));; esac
case "$BRNO_NODE" in *'"telephone":"+420 123 456 789"'*'"email":"brno@example.com"'*) echo "  ok     branches: address, phone and e-mail in the structured data";; *) echo "  CHYBA  LocalBusiness contacts"; ERRORS=$((ERRORS+1));; esac
grep -q 'data-vlozit="https://maps.google.com/maps?q=N%C3%A1m%C4%9Bst%C3%AD%20Svobody' "$WORK/response" && grep -q '+420 123 456 789' "$WORK/response" \
  && echo "  ok     branches: the branch page shows the contacts and the map of its own address loading after a click" || { echo "  CHYBA  branch page map"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/pobocky/praha"
PRAHA_NODE=$(branch_node)
case "$PRAHA_NODE" in *openingHoursSpecification*) echo "  CHYBA  hours that do not parse must be left out"; ERRORS=$((ERRORS+1));; *'"latitude":50.0813'*) echo "  ok     branches: hours that do not parse are left out, the geo stays";; *) echo "  CHYBA  LocalBusiness JSON-LD (praha)"; echo "$PRAHA_NODE" | head -c 400; ERRORS=$((ERRORS+1));; esac
check "branches: the collection form offers the LocalBusiness type with its properties" 200 "/admin.php?module=collections&action=edit&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'pobocky'")" 'value="LocalBusiness"'
grep -q 'name="schema\[pole\]\[openingHours\]"' "$WORK/response" && grep -q 'name="schema\[pole\]\[geo\]"' "$WORK/response" && echo "  ok     branches: geo and opening hours can be mapped in the form" || { echo "  CHYBA  schema form LocalBusiness"; ERRORS=$((ERRORS+1)); }
# a team created after the branches links each person to a branch (system/presets/people.php: branch → preset branches)
mcp create_collection '{"name":"Tým poboček","preset":"people"}' > /dev/null
expect "branches: a team made afterwards gets the branch field linked to the branches" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'tym-pobocek' AND pole LIKE '%\"klic\":\"branch\"%\"typ\":\"polozka\",\"kolekce\":\"pobocky\"%'")" "1"
echo "== 2.11 F1: six more ready-made collections"
# preset => "preset|item pages|schema type|number of fields|hidden list page" (system/presets/<preset>.php); the services come first so that a reference can link to them
preset_row() { sq "SELECT CONCAT(k.preset, '|', k.detail, '|', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(k.schema_org, '\$.typ')), '-'), '|', JSON_LENGTH(k.pole), '|', (SELECT COUNT(*) FROM ka_stranky s WHERE s.seo_link = k.seo_link AND s.zobrazit = 0)) FROM ka_kolekce k WHERE k.seo_link = '$1'"; }
mcp create_collection '{"name":"Preset služby","preset":"services"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "presets: services – item pages, Service schema, five fields, a hidden list page" "$(preset_row preset-sluzby)" "services|1|Service|5|1" || { echo "  CHYBA  create_collection preset services"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "presets: the Service schema maps the price to price_from" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.price')) FROM ka_kolekce WHERE seo_link = 'preset-sluzby'")" "price_from"
mcp create_collection '{"name":"Preset reference","preset":"references"}' > /dev/null
expect "presets: references – item pages, no schema, seven fields (the service link included), a hidden list page" "$(preset_row preset-reference)" "references|1|-|7|1"
expect "presets: the service field of a reference links to the services collection" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].kolekce'))) FROM ka_kolekce WHERE seo_link = 'preset-reference'")" "service|polozka|preset-sluzby"
mcp create_collection '{"name":"Preset ceník","preset":"price_list"}' > /dev/null
expect "presets: price list – no item pages, four fields, a hidden list page" "$(preset_row preset-cenik)" "price_list|0|-|4|1"
expect "presets: the price list page filters by category with buttons, sorted by order" "$(sq "SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', '|', stavba LIKE '%\"filtry\":true%', '|', stavba LIKE '%<p>{{price}}</p>%') FROM ka_stranky WHERE seo_link = 'preset-cenik'")" "1|1|1"
mcp create_collection '{"name":"Preset FAQ","preset":"faq"}' > /dev/null
expect "presets: questions and answers – no item pages, FAQPage schema, two fields, a hidden list page" "$(preset_row preset-faq)" "faq|0|FAQPage|2|1"
mcp create_collection '{"name":"Preset stroje","preset":"machines"}' > /dev/null
expect "presets: machines – item pages, Product schema with the model as SKU, six fields" "$(preset_row preset-stroje)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.sku')) FROM ka_kolekce WHERE seo_link = 'preset-stroje'")" "machines|1|Product|6|1|model"
mcp create_collection '{"name":"Preset kurzy","preset":"courses"}' > "$WORK/response"
expect "presets: courses – item pages, Event schema, seven fields, a hidden list page" "$(preset_row preset-kurzy)" "courses|1|Event|7|1"
expect "presets: the courses page lists the upcoming ones by start and end, sorted by the start" "$(sq "SELECT CONCAT(stavba LIKE '%\"obdobi\":\"nadchazejici\"%', '|', stavba LIKE '%\"obdobi_od\":\"start\"%', '|', stavba LIKE '%\"razeni_pole\":\"start\"%', '|', stavba LIKE '%<p>{{start}}</p>%') FROM ka_stranky WHERE seo_link = 'preset-kurzy'")" "1|1|1|1"
expect "presets: the course item template comes from the preset (the dates, the place, the registration form)" "$(sq "SELECT CONCAT(stavba LIKE '%<strong>{{when}}</strong>%', '|', stavba LIKE '%{{capacity}}%', '|', stavba LIKE '%\"typ\":\"formular\"%') FROM ka_kolekce WHERE seo_link = 'preset-kurzy'")" "1|1|1"
FUTURE_DAY=$(site_time "+30 days" "Y-m-d"); PAST_DAY=$(site_time "-30 days" "Y-m-d")
mcp save_collection_item "{\"collection\":\"preset-kurzy\",\"name\":\"Kurz svařování\",\"slug\":\"kurz-svarovani\",\"values\":{\"start\":\"$FUTURE_DAY 09:00\",\"end\":\"$FUTURE_DAY 16:00\",\"place\":\"Brno\",\"price\":\"1900\"},\"visible\":true}" > "$WORK/response"
contains -q 'kurz-svarovani' "$WORK/response" && echo "  ok     presets: a course with a future start" || { echo "  CHYBA  save_collection_item (course)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"preset-kurzy\",\"name\":\"Kurz loňský\",\"slug\":\"kurz-lonsky\",\"values\":{\"start\":\"$PAST_DAY 09:00\",\"place\":\"Praha\"},\"visible\":true}" > /dev/null
check "presets: the course page shows the place and the formatted start" 200 /preset-kurzy/kurz-svarovani "Brno"
grep -q '"Event"' "$WORK/response" && grep -q '"startDate"' "$WORK/response" && echo "  ok     presets: the course page carries the Event structured data" || { echo "  CHYBA  course Event schema"; ERRORS=$((ERRORS+1)); }
# the hidden list page in the administrator's preview shows only the course still to come
curl -s -b "$JAR" -o "$WORK/response" "$B/preset-kurzy?build=koncept"
grep -q 'Kurz svařování' "$WORK/response" && ! grep -q 'Kurz loňský' "$WORK/response" && echo "  ok     presets: the courses list shows the future course and not the past one" || { echo "  CHYBA  courses list by date"; grep -o 'Kurz [a-zě]*' "$WORK/response" | sort -u; ERRORS=$((ERRORS+1)); }

echo "== 2.11 F1: screen mode for a reception"
expect "screen: off → 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$(printf 'a%.0s' $(seq 1 32))")" "404"
mcp update_settings '{"settings":{"screen_collections":["neexistuje"]}}' > "$WORK/response"
contains -q 'Unknown collections: neexistuje' "$WORK/response" && echo "  ok     MCP: the screen shows only collections that exist" || { echo "  CHYBA  screen_collections validation"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"screen_mode":1,"screen_seconds":"7","screen_collections":["preset-kurzy"],"screen_hours":1,"screen_clock":1}}' > "$WORK/response"
SCREEN_SECRET=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_secret'")
expect "screen: switching the mode on creates the secret part of the address" "${#SCREEN_SECRET}" "32"
contains -q 'screen\\":{\\"on\\":true,\\"seconds\\":7,\\"collections\\":\[\\"preset-kurzy\\"\]' "$WORK/response" && ! contains -q 'screen_secret' "$WORK/response" && ! contains -q "$SCREEN_SECRET" "$WORK/response" \
  && echo "  ok     MCP: update_settings switches the screen on and reports it without the secret" || { echo "  CHYBA  update_settings screen"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "screen: on with the right secret → 200 with noindex" 200 "/screen/$SCREEN_SECRET" '<meta name="robots" content="noindex, nofollow">'
grep -q 'obrazovka-slide obrazovka-news' "$WORK/response" && grep -q 'Kurz svařování' "$WORK/response" && ! grep -q 'Kurz loňský' "$WORK/response" && grep -q 'obrazovka-hours' "$WORK/response" && grep -q 'id="obrazovka-hodiny"' "$WORK/response" \
  && echo "  ok     screen: slides of the news, the upcoming course only, today's opening hours and the clock" || { echo "  CHYBA  screen slides"; grep -o 'obrazovka-[a-z]*' "$WORK/response" | sort | uniq -c; ERRORS=$((ERRORS+1)); }
grep -q '<noscript><meta http-equiv="refresh" content="7; url=/screen/'"$SCREEN_SECRET"'?s=1">' "$WORK/response" && grep -q 'setInterval(function(){i=(i+1)%n;show(i)},7000)' "$WORK/response" && grep -q -e '--ka-barva-primarni:' "$WORK/response" \
  && echo "  ok     screen: rotates every 7 seconds with the script and by a meta refresh without it, in the site's design tokens" || { echo "  CHYBA  screen rotation"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o /dev/null "$B/screen/$SCREEN_SECRET?s=1"
grep -qi '^X-Robots-Tag: noindex' "$WORK/headers" && grep -qi '^Cache-Control: no-store' "$WORK/headers" && ! grep -qi '^Set-Cookie' "$WORK/headers" && echo "  ok     screen: noindex and no-store headers, no cookies" || { echo "  CHYBA  screen headers"; cat "$WORK/headers"; ERRORS=$((ERRORS+1)); }
expect "screen: a wrong secret → 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$(echo "$SCREEN_SECRET" | tr '0-9a-f' '1-9a-f0')")" "404"
check "screen: the admin shows the full address with a copy button" 200 "/admin.php?module=settings&tab=general" "screen/$SCREEN_SECRET"
grep -q 'data-kopirovat="#screen-url"' "$WORK/response" && grep -q 'name="screen_collections\[\]" value="preset-kurzy" checked' "$WORK/response" && echo "  ok     screen: the copy button and the chosen collection" || { echo "  CHYBA  screen admin form"; ERRORS=$((ERRORS+1)); }
# the "new address" button: the form is posted as a browser does, the old address stops working
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post" -d novy_token_obrazovka=1
SCREEN_SECRET_2=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_secret'")
[ "${#SCREEN_SECRET_2}" = 32 ] && [ "$SCREEN_SECRET_2" != "$SCREEN_SECRET" ] && echo "  ok     screen: the button creates a new address" || { echo "  CHYBA  new screen address"; ERRORS=$((ERRORS+1)); }
expect "screen: the old address stops working, the new one works, the seconds stay" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET")|$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET_2")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_seconds'")" "404|200|7"
mcp update_settings '{"settings":{"screen_mode":0}}' > /dev/null
expect "screen: switched off → 404 even with the right secret" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET_2")" "404"

echo "== 2.11: official notice board – posting and takedown dates, permanent archive, audit trail"
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('notices', '$(site_time)' + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)" > /dev/null # the job runs only when the test asks (a day ahead: MySQL and PHP may be in different time zones)
N_YESTERDAY=$(site_time "-1 day" "Y-m-d"); N_TOMORROW=$(site_time "+1 day" "Y-m-d"); N_TEN_AGO=$(site_time "-10 day" "Y-m-d")
N_YESTERDAY_CZ=$(site_time "-1 day" "j. n. Y"); N_TOMORROW_CZ=$(site_time "+1 day" "j. n. Y")
mcp create_collection '{"name":"Úřední deska","preset":"notices"}' > "$WORK/response"
expect "notices: the collection with its board and its archive page, both hidden, each listing its period" "$(sq "SELECT CONCAT((SELECT preset FROM ka_kolekce WHERE seo_link = 'uredni-deska'), '|', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('uredni-deska', 'uredni-deska-archive') AND zobrazit = 0), '|', (SELECT stavba LIKE '%\"obdobi\":\"probihajici\"%' FROM ka_stranky WHERE seo_link = 'uredni-deska'), '|', (SELECT stavba LIKE '%\"obdobi\":\"minule\"%' AND stavba LIKE '%\"kolekce\":\"uredni-deska\"%' FROM ka_stranky WHERE seo_link = 'uredni-deska-archive'), '|', (SELECT titulek FROM ka_stranky WHERE seo_link = 'uredni-deska-archive'))")" "notices|2|1|1|Úřední deska – archive" # over MCP the texts are English (as the field labels of every preset); from the admin the name is translated
contains -q 'more_pages' "$WORK/response" && contains -q 'uredni-deska-archive' "$WORK/response" && echo "  ok     notices: Claude is told about the archive page" || { echo "  CHYBA  more_pages"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "notices: the item template comes from the preset with the status line" "$(sq "SELECT stavba LIKE '%{{notice_status}}%' FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" "1"
BOARD_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'uredni-deska'")
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Záměr pronájmu\",\"slug\":\"zamer-pronajmu\",\"values\":{\"posted\":\"$N_YESTERDAY\",\"taken_down\":\"$N_TOMORROW\",\"reference\":\"MU/2026/41\",\"issuer\":\"Městský úřad\",\"category\":\"Majetek\",\"summary\":\"Záměr pronajmout pozemek.\"},\"visible\":true}" > "$WORK/response"; NOTICE_A=$(mcp_value id)
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Rozpočet 2026\",\"slug\":\"rozpocet-2026\",\"values\":{\"posted\":\"$N_TEN_AGO\",\"taken_down\":\"$N_YESTERDAY\",\"reference\":\"MU/2026/12\",\"category\":\"Rozpočet\"},\"visible\":true}" > "$WORK/response"; NOTICE_B=$(mcp_value id)
mcp save_collection_item '{"collection":"uredni-deska","name":"Budoucí vyhláška","slug":"budouci","values":{"posted":"2099-01-01"}}' > "$WORK/response"
expect "notices: a notice still to be posted may stay hidden" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE seo_link = 'budouci'")" "0"
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Skrytá minulá\",\"values\":{\"posted\":\"$N_YESTERDAY\"}}" > "$WORK/response"
contains -q 'cannot be hidden' "$WORK/response" && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Skrytá minulá'")" = 0 ] && echo "  ok     MCP: a notice whose posting day has come cannot be created hidden" || { echo "  CHYBA  hidden notice created"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
for slug in uredni-deska uredni-deska-archive; do mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = '$slug'"),\"visible\":true}" > /dev/null; done
curl -s -o "$WORK/response" "$B/uredni-deska"
grep -q 'Záměr pronájmu' "$WORK/response" && grep -q 'MU/2026/41' "$WORK/response" && ! grep -q 'Rozpočet 2026' "$WORK/response" && grep -q 'Majetek' "$WORK/response" \
  && echo "  ok     notices: the board shows the current notice with its reference and the category filter, not the archived one" || { echo "  CHYBA  board page"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/uredni-deska-archive"
grep -q 'Rozpočet 2026' "$WORK/response" && ! grep -q 'Záměr pronájmu' "$WORK/response" && echo "  ok     notices: the archive shows the notice taken down yesterday, not the current one" || { echo "  CHYBA  archive page"; ERRORS=$((ERRORS+1)); }
check "notices: the item page says from when to when the notice is posted" 200 /uredni-deska/zamer-pronajmu "Vyvěšeno od $N_YESTERDAY_CZ do $N_TOMORROW_CZ"
grep -q 'Městský úřad' "$WORK/response" && echo "  ok     notices: the item page has the issuer" || { echo "  CHYBA  item page"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/uredni-deska/rozpocet-2026" # as a visitor: the page must not land in the page cache
grep -q "Sejmuto $N_YESTERDAY_CZ – archiv" "$WORK/response" && ! grep -qs 'Sejmuto' "$WORK"/web/storage/cache/stranky/*.html && echo "  ok     notices: an archived notice says when it was taken down, and the page is not cached" || { echo "  CHYBA  archived notice page"; ERRORS=$((ERRORS+1)); }
# the permanent archive: no trash, no hiding once posted, the collection stays while it has notices
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_A,\"visible\":false}" > "$WORK/response"
contains -q 'cannot be hidden' "$WORK/response" && [ "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $NOTICE_A")" = 1 ] && echo "  ok     MCP: a posted notice cannot be hidden" || { echo "  CHYBA  MCP hid a notice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp delete_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B}" > "$WORK/response"
contains -q 'stay in the archive' "$WORK/response" && [ "$(sq "SELECT smazano IS NULL FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" = 1 ] && echo "  ok     MCP: delete_collection_item refuses a notice with a clear message" || { echo "  CHYBA  MCP deleted a notice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$BOARD_IDK"; TOKEN=$(csrf)
! grep -q 'action=delete_item"' "$WORK/response" && grep -q 'archiv' "$WORK/response" && echo "  ok     admin: the notices list has no Delete button" || { echo "  CHYBA  delete button on a board"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B"
expect "admin: the delete action refuses a notice" "$(sq "SELECT smazano IS NULL FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$BOARD_IDK"
grep -q 'změňte místo toho datum sejmutí' "$WORK/response" && echo "  ok     admin: the refusal is explained" || { echo "  CHYBA  admin refusal message"; ERRORS=$((ERRORS+1)); }
mcp delete_collection '{"collection":"uredni-deska"}' > "$WORK/response"
contains -q 'cannot be deleted' "$WORK/response" && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" = 1 ] && echo "  ok     MCP: the board cannot be deleted while it has notices" || { echo "  CHYBA  delete_collection deleted a board"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK"
expect "admin: the collection delete refuses a board with notices" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" "1"
# the audit trail: created and changed rows, by whom, what changed; a save without a change writes nothing
expect "notices: a created row per notice, written by Claude, with the values" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(DISTINCT \`by\`), '|', (SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.reference[1]')) FROM ka_notice_log WHERE idp = $NOTICE_A AND action = 'created')) FROM ka_notice_log WHERE action = 'created'")" "3|Claude|MU/2026/41"
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B,\"values\":{\"summary\":\"Schválený rozpočet.\"}}" > /dev/null
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B,\"values\":{\"summary\":\"Schválený rozpočet.\"}}" > /dev/null
expect "notices: a change is logged once with the field, the old and the new value" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '$.summary[0]'))), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '$.summary[1]'))), '|', MAX(JSON_CONTAINS_PATH(fields, 'one', '$.reference'))) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'changed'")" "1||Schválený rozpočet.|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$BOARD_IDK&item=$NOTICE_B"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B" --data-urlencode "nazev=Rozpočet 2026" -d "seo_link=rozpocet-2026" -d "poradi=100" -d "zobrazit=1" \
  -d "data[posted]=$N_TEN_AGO" -d "data[taken_down]=$N_YESTERDAY" -d "data[reference]=MU/2026/12" --data-urlencode "data[issuer]=Rada města" --data-urlencode "data[category]=Rozpočet" -d "data[document]=" --data-urlencode "data[summary]=Schválený rozpočet."
expect "admin: saving the form logs the change under the user's name" "$(sq "SELECT CONCAT(\`by\`, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '$.issuer[1]'))) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'changed' ORDER BY id DESC LIMIT 1")" "Tester|Rada města"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B" --data-urlencode "nazev=Rozpočet 2026" -d "seo_link=rozpocet-2026" -d "poradi=100" \
  -d "data[posted]=$N_TEN_AGO" -d "data[taken_down]=$N_YESTERDAY" -d "data[reference]=MU/2026/12" --data-urlencode "data[issuer]=Rada města"
expect "admin: the form cannot hide a posted notice either" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" "1"
# the hourly job records posted and taken down once each and reports it
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'notices: posted 2, taken down 1' "$WORK/tasks.txt" && echo "  ok     notices: the job reports what it recorded" || { echo "  CHYBA  notices job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "notices: posted for both visible notices, taken_down for the archived one, by system" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_notice_log WHERE idp = $NOTICE_A AND action = 'posted'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'taken_down' AND JSON_UNQUOTE(JSON_EXTRACT(fields, '$.taken_down')) = '$N_YESTERDAY'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down') AND \`by\` = 'system'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE idp = (SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'budouci') AND action <> 'created'))")" "1|1|3|0"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'notices: posted 0, taken down 0' "$WORK/tasks.txt" && [ "$(sq "SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down')")" = 3 ] && echo "  ok     notices: a second run records nothing twice" || { echo "  CHYBA  notices job ran twice"; ERRORS=$((ERRORS+1)); }
# the log under the item form and the CSV for an administrator, not for a guest
check "notices: the item form shows the log with the CSV link" 200 "/admin.php?module=collections&action=item&id=$BOARD_IDK&item=$NOTICE_B" "action=notice_log"
grep -q 'Rada města' "$WORK/response" && grep -q "taken_down: $N_YESTERDAY" "$WORK/response" && echo "  ok     notices: the log shows the changes and the takedown" || { echo "  CHYBA  log under the form"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code} %{content_type}' "$B/admin.php?module=collections&action=notice_log&id=$BOARD_IDK")
expect "notices: the administrator downloads the log as CSV" "$code" "200 text/csv; charset=utf-8"
[ "$(grep -c ';taken_down;' "$WORK/response")|$(grep -c ';posted;' "$WORK/response")|$(grep -c ';created;' "$WORK/response")" = "1|2|3" ] && grep -q 'Rada města' "$WORK/response" && echo "  ok     notices: the CSV has every row of the board" || { echo "  CHYBA  notice log CSV"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/response" -w '%{content_type}' "$B/admin.php?module=collections&action=notice_log&id=$BOARD_IDK")
[[ "$code" == text/html* ]] && ! grep -q 'taken_down' "$WORK/response" && grep -q 'Heslo' "$WORK/response" && echo "  ok     notices: a guest gets the sign-in form instead of the CSV" || { echo "  CHYBA  notice log for a guest ($code)"; ERRORS=$((ERRORS+1)); }
mcp list_notice_log '{"collection":"uredni-deska"}' > "$WORK/response"
expect "MCP: list_notice_log lists the whole trail with who and what" "$(mcp_value count)|$(mcp_value entries 0 action)|$(mcp_value entries 0 by)|$(mcp_value entries 0 fields reference 1)" "8|created|Claude|MU/2026/41"
mcp list_notice_log "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B}" > "$WORK/response"
expect "MCP: list_notice_log of one notice" "$(mcp_value count)|$(mcp_value entries 4 action)|$(mcp_value entries 4 fields taken_down)" "5|taken_down|$N_YESTERDAY"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_notice_log"]["readOnlyHint"] === true && !isset($t["edit_notice_log"]) && !isset($t["delete_notice_log"]) ? 0 : 1);' "$WORK/response" \
  && echo "  ok     MCP: the notice log is read-only – no tool edits or deletes it" || { echo "  CHYBA  notice log tools"; ERRORS=$((ERRORS+1)); }
echo "== 2.11/3.3: the twenty shipped industry blueprints"
mcp get_blueprint '{}' > "$WORK/response"
BLUEPRINTS_LISTED=0; for key in accommodation agency auto_service beauty_wellness clinic craftsman driving_school farm fitness_studio it_services manufacturer municipality nonprofit photographer professional_services real_estate restaurant retail_shop school_courses software_saas; do contains -q "key\\\\\":\\\\\"$key" "$WORK/response" && BLUEPRINTS_LISTED=$((BLUEPRINTS_LISTED+1)); done
expect "shipped blueprints: get_blueprint lists the twenty as available" "$BLUEPRINTS_LISTED" "20"
BLUEPRINT_IDK0=$(sq "SELECT IFNULL(MAX(idk), 0) FROM ka_kolekce")
BLUEPRINT_MISSING=$(sq "SELECT 5 - COUNT(DISTINCT preset) FROM ka_kolekce WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')") # the earlier blocks made some of them – apply creates only what the site lacks
mcp apply_blueprint '{"key":"municipality"}' > "$WORK/response"
contains -q 'applied\\":\\"municipality' "$WORK/response" && expect "shipped blueprints: the municipality brings the collections the site lacks ($BLUEPRINT_MISSING of its five) and its facts without values" \
  "$(sq "SELECT CONCAT((SELECT COUNT(DISTINCT preset) FROM ka_kolekce WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0), '|', (SELECT COUNT(*) FROM ka_facts WHERE fact_key IN ('mayor_name', 'population', 'filing_office_email', 'council_meetings') AND value = ''), '|', (SELECT type FROM ka_facts WHERE fact_key = 'population'), '|', (SELECT bkey FROM ka_blueprints))")" "5|$BLUEPRINT_MISSING|4|number|municipality" \
  || { echo "  CHYBA  apply_blueprint municipality"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "shipped blueprints: the admin page asks the municipality's questions" 200 "/admin.php?module=blueprints" 'name="answer\[mayor_name\]"'
grep -q 'Kdo je starostou nebo starostkou obce?' "$WORK/response" && grep -q 'Uveďte starostu nebo starostku' "$WORK/response" && echo "  ok     shipped blueprints: the question and the failing check about the mayor are in the admin language" || { echo "  CHYBA  otázky plánu obce v administraci"; ERRORS=$((ERRORS+1)); }
# the site as before: the blueprint off, its empty collections and their hidden pages gone (the facts stay – removing never deletes them); later blocks make their own
mcp remove_blueprint '{"key":"municipality"}' > /dev/null
for slug in $(sq "SELECT seo_link FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0"); do mcp delete_collection "{\"collection\":\"$slug\"}" > /dev/null; sq "DELETE FROM ka_stranky WHERE seo_link IN ('$slug', '$slug-archive') AND zobrazit = 0" > /dev/null; done
expect "shipped blueprints: removed again, the site has no blueprint and no collection of the municipality" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0))")" "0|0"
echo "== 2.12: enquiry triage"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
TRIAGE_ID=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'eva@example.cz', '[[\"Zpráva\",\"Chceme nabídku na 40 oken do pátku\"]]', 0); SELECT LAST_INSERT_ID();")
SPAM_ID=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'seo@example.com', '[[\"Zpráva\",\"We can get you to the first page of Google\"]]', 0); SELECT LAST_INSERT_ID();")
mcp triage_enquiries '{}' > "$WORK/response"
contains -q "\"id\\\\\":$TRIAGE_ID" "$WORK/response" && contains -q '40 oken' "$WORK/response" && echo "  ok     triage: Claude gets the unsorted enquiries as text" || { echo "  CHYBA  triage_enquiries"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_enquiry "{\"id\":$TRIAGE_ID,\"category\":\"sales\",\"priority\":\"high\",\"draft_reply\":\"Dobrý den, děkujeme za poptávku.\"}" > /dev/null
mcp update_enquiry "{\"id\":$SPAM_ID,\"category\":\"spam\"}" > /dev/null
expect "triage: Claude's sorting is saved" "$(sq "SELECT CONCAT(kategorie, '|', priorita, '|', navrh_odpovedi, '|', triaged_by) FROM ka_poptavky WHERE idp = $TRIAGE_ID")" "sales|3|Dobrý den, děkujeme za poptávku.|claude"
mcp update_enquiry "{\"id\":$TRIAGE_ID,\"category\":\"nonsense\"}" > "$WORK/response"
contains -q 'category must be one of' "$WORK/response" && echo "  ok     triage: an unknown kind is refused" || { echo "  CHYBA  neznámá kategorie"; ERRORS=$((ERRORS+1)); }
mcp list_enquiries '{"limit":50}' > "$WORK/response"
contains -q 'draft_reply' "$WORK/response" && ! contains -q 'first page of Google' "$WORK/response" && echo "  ok     triage: list_enquiries carries the triage and leaves spam out" || { echo "  CHYBA  list_enquiries a spam"; ERRORS=$((ERRORS+1)); }
check "triage: the admin list leaves spam out" 200 "/admin.php?module=enquiries" "category=spam"
! grep -q 'first page of Google' "$WORK/response" && echo "  ok     triage: spam is not in the default list" || { echo "  CHYBA  spam v seznamu"; ERRORS=$((ERRORS+1)); }
check "triage: the detail has the kind, the priority and the draft in the e-mail reply" 200 "/admin.php?module=enquiries&action=detail&id=$TRIAGE_ID" 'body=Dobr%C3%BD%20den'
TRIAGE_CSRF=$(csrf)
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$TRIAGE_CSRF" -d "id=$TRIAGE_ID" -d kategorie=support -d priorita=1 --data-urlencode "navrh_odpovedi=Vlastní odpověď"
mcp update_enquiry "{\"id\":$TRIAGE_ID,\"category\":\"sales\"}" > "$WORK/response"
contains -q 'A person sorted this enquiry already' "$WORK/response" && expect "triage: a person's sorting wins over Claude" "$(sq "SELECT CONCAT(kategorie, '|', triaged_by <> 'claude') FROM ka_poptavky WHERE idp = $TRIAGE_ID")" "support|1" \
  || { echo "  CHYBA  člověk vs. Claude"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# the AI assistant sorts new enquiries in the background when switched on (a fake provider answers like the Claude API)
AI_PORT=$((PORT + 14)); mkdir -p "$WORK/ai"
cat > "$WORK/ai/index.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.log', file_get_contents('php://input') . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['content' => [['type' => 'text', 'text' => '{"category": "support", "priority": 2, "reply": "Dobrý den, podíváme se na to."}']]]);
PHP
(cd "$WORK/ai" && exec php -S "127.0.0.1:$AI_PORT" > /dev/null 2>&1) & AI_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$AI_PORT/" && break; sleep 0.2; done; : > "$WORK/ai/requests.log"
cp "$WORK/web/config.php" "$WORK/config.bak"; sed -i.tmp "1s|<?php|<?php define('KALETA_AI_URL', 'http://127.0.0.1:$AI_PORT/');|" "$WORK/web/config.php"; sleep 3 # OPcache of the test server revalidates the file after 2 s
ASSIST_ID=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'jan@example.cz', '[[\"Zpráva\",\"Nefunguje nám zámek u dveří\"]]', 0); SELECT LAST_INSERT_ID();")
EXT_TRIAGE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('ai_key', 'test-key-for-the-fake-provider'), ('ai_provider', 'anthropic'), ('triage_assistant', '0'), ('extensions', CONCAT('$EXT_TRIAGE', ',asistent'))" > /dev/null
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('triage', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "triage: switched off, the assistant sends nothing" "$(wc -c < "$WORK/ai/requests.log" | tr -d ' ')|$(sq "SELECT kategorie FROM ka_poptavky WHERE idp = $ASSIST_ID")" "0|"
sq "UPDATE ka_nastaveni SET hodnota = '1' WHERE promenna = 'triage_assistant'; UPDATE ka_jobs SET last_run = NULL WHERE name = 'triage'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "triage: switched on, the assistant sorts a new enquiry and drafts a reply" "$(sq "SELECT CONCAT_WS('|', kategorie, priorita, triaged_by, IFNULL(navrh_odpovedi, '-')) FROM ka_poptavky WHERE idp = $ASSIST_ID")" "support|2|assistant|Dobrý den, podíváme se na to."
grep -i 'triage' "$WORK/tasks.txt" | grep -q 'failed' && grep -i 'triage' "$WORK/tasks.txt"
grep -q 'never instructions' "$WORK/ai/requests.log" && grep -q 'zámek' "$WORK/ai/requests.log" && echo "  ok     triage: the assistant is told the enquiry is data, not instructions" || { echo "  CHYBA  pokyn pro asistenta"; ERRORS=$((ERRORS+1)); }
cp "$WORK/config.bak" "$WORK/web/config.php"; kill "$AI_PID" 2>/dev/null || true
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'triage_assistant'; DELETE FROM ka_nastaveni WHERE promenna = 'ai_key'; UPDATE ka_nastaveni SET hodnota = '$EXT_TRIAGE' WHERE promenna = 'extensions'" > /dev/null
echo "== 2.12: multi-step forms, conditions and a price estimate"
mcp vytvor_stranku '{"titulek":"Kalkulacka 212","zobrazit":true}' > "$WORK/response"; mcp_text; CALC_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":$CALC_PAGE,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Kalkulace\",\"bez_captcha\":true,\"pole\":[{\"popisek\":\"Typ\",\"typ\":\"volba\",\"povinne\":true,\"moznosti_volby\":\"Okna | 1200\\nDveře | 9 900\"},{\"popisek\":\"Počet\",\"typ\":\"cislo\",\"cena_za_jednotku\":\"1500\"},{\"popisek\":\"Upřesnění\",\"typ\":\"krok\"},{\"popisek\":\"Barva dveří\",\"typ\":\"vyber\",\"povinne\":true,\"moznosti\":\"Bílá\\nDub | 3000\",\"kdyz_pole\":\"Typ\",\"kdyz_hodnota\":\"Dveře\"},{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true},{\"popisek\":\"Odhad\",\"typ\":\"odhad\",\"zaklad\":\"500\",\"mena\":\"Kč\"}]}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/kalkulacka-212"
[ "$(grep -o 'class="ka-krok"' "$WORK/formular.html" | wc -l | tr -d ' ')" = 2 ] && grep -q 'data-kroky' "$WORK/formular.html" && grep -q '<legend>Upřesnění</legend>' "$WORK/formular.html" \
  && echo "  ok     multi-step: the form is split into its steps" || { echo "  CHYBA  kroky formuláře"; ERRORS=$((ERRORS+1)); }
grep -q 'data-kdyz="p0" data-kdyz-hodnota="Dveře"' "$WORK/formular.html" && grep -q 'value="Okna" data-cena="1200"' "$WORK/formular.html" && grep -q 'data-cena="9900"' "$WORK/formular.html" && grep -q 'data-cena-za="1500"' "$WORK/formular.html" && grep -q 'data-odhad data-zaklad="500" data-mena="Kč"' "$WORK/formular.html" && ! grep -q '| 1200' "$WORK/formular.html" \
  && echo "  ok     calculator: conditions and prices go to the script, the visitor never sees the price syntax" || { echo "  CHYBA  podmínky a ceny"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(field_value prvek || true); FORM_TIME=$(field_value as_cas || true); FORM_SIGNATURE=$(field_value as_podpis || true); sleep 4
calc() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/kalkulacka-212" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" "$@"; }
case "$(calc --data-urlencode 'p0=Dveře' -d p4=d@example.cz -d p5=1)" in *result=pole*field=3*) echo "  ok     conditions: a required field shown by the answer is checked on the server";; *) echo "  CHYBA  podmíněné povinné pole"; ERRORS=$((ERRORS+1));; esac
case "$(calc -d p0=Okna -d p1=4 -d p4=o@example.cz --data-urlencode 'p3=Dub' -d p5=1)" in *result=ok*) echo "  ok     conditions: a hidden required field does not block the form";; *) echo "  CHYBA  skryté povinné pole"; ERRORS=$((ERRORS+1));; esac
expect "calculator: the server computes the estimate and drops the hidden answer" "$(sq "SELECT data FROM ka_poptavky ORDER BY idp DESC LIMIT 1" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode("|", array_map(fn ($r) => $r[0] . "=" . str_replace("\u{a0}", " ", $r[1]), $d));')" "Typ=Okna|Počet=4|Email=o@example.cz|Odhad=7 700 Kč"
echo "== 2.12: testimonial requests with consent"
REF_ENQUIRY=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'zakaznik@example.cz', '[[\"Zpráva\",\"Děkujeme\"]]', 2); SELECT LAST_INSERT_ID();")
NO_MAIL_ENQUIRY=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', '', '[]', 2); SELECT LAST_INSERT_ID();")
mcp request_testimonial "{\"id\":$NO_MAIL_ENQUIRY}" > "$WORK/response"
contains -q 'no e-mail address' "$WORK/response" && echo "  ok     testimonials: an enquiry without an e-mail cannot be asked" || { echo "  CHYBA  žádost bez e-mailu"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp request_testimonial "{\"id\":$REF_ENQUIRY,\"send\":false}" > "$WORK/response"
REF_LINK=$(php -r '$t = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "null", true); echo ltrim((string) parse_url((string) ($t["link"] ?? ""), PHP_URL_PATH), "/");' "$WORK/response")
[ -n "$REF_LINK" ] && expect "testimonials: only a hash of the token is stored" "$(sq "SELECT COUNT(*) FROM ka_testimonial_requests WHERE idp = $REF_ENQUIRY AND token_hash = SHA2('${REF_LINK#_testimonial/}', 256)")" "1" || { echo "  CHYBA  request_testimonial"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/formular.html" "$B/$REF_LINK"
grep -q 'name="consent_words"' "$WORK/formular.html" && grep -q 'name="consent_photo"' "$WORK/formular.html" && grep -q 'noindex' "$WORK/formular.html" && echo "  ok     testimonials: the customer's page asks for the words and two separate consents" || { echo "  CHYBA  stránka pro referenci"; ERRORS=$((ERRORS+1)); }
REF_TIME=$(field_value as_cas || true); REF_SIGNATURE=$(field_value as_podpis || true); sleep 4
curl -s -o "$WORK/response" -X POST "$B/$REF_LINK" -d "as_cas=$REF_TIME" -d "as_podpis=$REF_SIGNATURE" --data-urlencode "text=Výborná spolupráce, vše včas." -d "name=Eva Nováková"
grep -q 'only with your consent\|jen s vaším souhlasem' "$WORK/response" && echo "  ok     testimonials: nothing is saved without the consent" || { echo "  CHYBA  souhlas"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" -X POST "$B/$REF_LINK" -d "as_cas=$REF_TIME" -d "as_podpis=$REF_SIGNATURE" --data-urlencode "text=Výborná spolupráce, vše <b>včas</b>." -d "name=Eva Nováková" --data-urlencode "role=ředitelka, ACME" -d consent_words=1
REF_ITEM=$(sq "SELECT item_id FROM ka_testimonial_requests WHERE idp = $REF_ENQUIRY AND used_at IS NOT NULL")
[ -n "$REF_ITEM" ] && expect "testimonials: the answer is a hidden draft reference with the words, the name and the role" "$(sq "SELECT CONCAT(p.zobrazit, '|', p.nazev, '|', p.data->>'\$.quote', '|', p.data->>'\$.client', '|', k.preset) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE p.idp = $REF_ITEM")" "0|Eva Nováková|Výborná spolupráce, vše včas.|Eva Nováková, ředitelka, ACME|references" \
  || { echo "  CHYBA  koncept reference"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "testimonials: the consent the customer saw is kept, the link works once" "$(sq "SELECT consent LIKE '%publish my words%' OR consent LIKE '%zveřejn%' FROM ka_testimonial_requests WHERE item_id = $REF_ITEM")|$(curl -s -o /dev/null -w '%{http_code}' "$B/$REF_LINK")" "1|404"
check "testimonials: the enquiry detail shows the request and links the draft" 200 "/admin.php?module=enquiries&action=detail&id=$REF_ENQUIRY" "item=$REF_ITEM"
echo "== 2.12: calls and e-mail clicks counted as conversions (Core\\Conversions)"
# a page with nothing but a phone number keeps image/web.js while the statistics are on – the click counter needs it and learns the endpoint from data-konverze; never for signed-in users
mcp vytvor_stranku '{"titulek":"Volejte 212","zobrazit":true,"text":"<p>Zavolejte: <a href=\"tel:+420777000212\">+420 777 000 212</a></p>"}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" -A 'Mozilla/5.0 test' "$B/volejte-212"
grep -q 'image/web.js?v=[^"]*" defer blocking="render"[^>]* data-konverze="/conversion"></script>' "$WORK/response" && echo "  ok     2.12: a page with only a tel: link keeps web.js with the click endpoint (3.7: /conversion) when the statistics are on" || { echo "  CHYBA  web.js on a page with a tel: link"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/volejte-212"; ! grep -q 'data-konverze' "$WORK/response" && echo "  ok     2.12: no click counter for signed-in users" || { echo "  CHYBA  data-konverze for a signed-in user"; ERRORS=$((ERRORS+1)); }
# the beacon: once per visitor, type and page a day – the visitor is the statistics' daily fingerprint (IP and browser), nothing of it is stored with the count
beacon() { curl -s -o /dev/null -w '%{http_code}' -X POST "$B/konverze" -A "${3:-Mozilla/5.0 test}" -d "type=$1" -d "path=$2"; }
expect "2.12: a click beacon answers 204" "$(beacon tel /volejte-212)" 204
beacon tel /volejte-212 > /dev/null                                   # the same visitor again – one call, not two
beacon tel '/volejte-212?utm_source=x#telefon' > /dev/null            # the same page with a query string and a fragment – still the same page and visitor
curl -s -o /dev/null -X POST "$B/conversion" -A 'Mozilla/5.0 test' -d type=mailto -d path=/volejte-212   # another type counts on its own (3.7: through /conversion)
beacon tel /volejte-212 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' > /dev/null  # another browser = another visitor
beacon fax /volejte-212 > /dev/null                                   # an unknown type
beacon tel /volejte-212 'curl/8.0' > /dev/null                        # a bot
beacon tel /neexistuje-212 > /dev/null                                # a page the statistics never saw
beacon tel 'volejte-212' > /dev/null                                  # not a path
curl -s -o /dev/null -b "$JAR" -X POST "$B/konverze" -A 'Mozilla/5.0 test' -d type=whatsapp -d path=/volejte-212   # signed in – never counted
expect "2.12: once per visitor, type and page a day; unknown types, bots, made-up pages and signed-in users are not counted" "$(sq "SELECT GROUP_CONCAT(CONCAT(cesta, ':', typ, ':', pocet) ORDER BY typ) FROM ka_stat_konverze")" "/volejte-212:mailto:1,/volejte-212:tel:2"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=stats&days=7"
grep -q 'href="/volejte-212"' "$WORK/response" && grep -q '<td class="cislo">2 / 1 / 0</td>' "$WORK/response" && grep -q 'Kontaktní kliknutí (hovory, e-maily, WhatsApp)' "$WORK/response" && echo "  ok     2.12: Statistics show calls, e-mails and WhatsApp per page and in total" || { echo "  CHYBA  Statistics: contact clicks"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"contact_clicks":{"calls":2,"emails":1,"whatsapp":0,"by_page":\[{"path":"/volejte-212","calls":2,"emails":1,"whatsapp":0}\]}' "$WORK/text" && contains -q '"path":"/volejte-212","views":[0-9]*,"enquiries":0,"signups":0,"calls":2,"emails":1,"whatsapp":0' "$WORK/text" \
  && echo "  ok     2.12: get_stats carries contact_clicks and the clicks of every page" || { echo "  CHYBA  get_stats contact_clicks"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the monthly report mentions calls and e-mails when the month had any
sq "INSERT INTO ka_stat_konverze (den, cesta, typ, pocet) VALUES ('$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m-d");')', '/volejte-212', 'tel', 4), ('$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m-d");')', '/volejte-212', 'mailto', 2)" > /dev/null
check "2.12: the monthly report mentions the calls and e-mails of the month" 200 "/admin.php?module=settings&action=report_preview" "Hovory – kliknutí na telefonní číslo"
grep -q 'E-maily – kliknutí na e-mailovou adresu' "$WORK/response" && ! grep -q 'WhatsApp – kliknutí' "$WORK/response" && echo "  ok     2.12: the report lists only the kinds of clicks there were" || { echo "  CHYBA  monthly report: contact clicks"; ERRORS=$((ERRORS+1)); }
# statistics off: no endpoint on the page and no counting
stats_feature 0
curl -s -o "$WORK/response" -A 'Mozilla/5.0 test' "$B/volejte-212"; ! grep -q 'data-konverze' "$WORK/response" && echo "  ok     2.12: statistics off – the page carries no click endpoint" || { echo "  CHYBA  data-konverze with the statistics off"; ERRORS=$((ERRORS+1)); }
beacon tel /volejte-212 'Mozilla/5.0 (X11; Linux x86_64) third' > /dev/null
expect "2.12: statistics off – a click is not counted" "$(sq "SELECT SUM(pocet) FROM ka_stat_konverze WHERE den = '$(site_date today)'")" 3
stats_feature 1

echo "== 2.12: share images drawn by the site"
if php -r 'exit(function_exists("imagecreatetruecolor") && function_exists("imagettftext") ? 0 : 1);'; then
OG_SHARE_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'share_image'")
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('share_image', 'share_image_auto')" > /dev/null # no site-wide sharing image, the generated ones on (the default)
mcp create_page '{"title":"Dřevěné schody na míru","slug":"drevene-schody","visible":true,"text":"<p>Schody.</p>"}' > /dev/null
OG_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'drevene-schody'")
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
OG_URL=$(grep -o 'property="og:image" content="[^"]*"' "$WORK/response" | head -1 | sed 's/.*content="//;s/"$//')
case "$OG_URL" in "$B"/og/[a-f0-9]*.png) echo "  ok     share images: a page without an image points og:image to /og/<hash>.png";; *) echo "  CHYBA  og:image of a page without an image: „$OG_URL“"; ERRORS=$((ERRORS+1));; esac
grep -q 'og:image:width" content="1200"' "$WORK/response" && grep -q 'twitter:card" content="summary_large_image"' "$WORK/response" && echo "  ok     share images: the size is announced and the Twitter card is the large one" || { echo "  CHYBA  og:image:width / twitter:card of the generated image"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/og.png" -w '%{http_code} %{content_type}' "$OG_URL"); expect "share images: the picture is served as PNG" "$code" "200 image/png"
expect "share images: the PNG is 1200×630" "$(php -r '$s = @getimagesize($argv[1]); echo $s ? $s[0] . "x" . $s[1] : "none";' "$WORK/og.png")" "1200x630"
curl -s -o /dev/null -D "$WORK/og.headers" "$OG_URL"; grep -qi '^Cache-Control: public, max-age=31536000' "$WORK/og.headers" && echo "  ok     share images: cached for a year (the address changes with the content)" || { echo "  CHYBA  Cache-Control of the picture"; ERRORS=$((ERRORS+1)); }
OG_HASH=${OG_URL##*/og/}; OG_HASH=${OG_HASH%.png}
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/og/${OG_HASH:1}0.png"); expect "share images: a tampered hash is 404 – nobody makes the site draw their own text" "$code" "404"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/og/$OG_HASH.jpg"); expect "share images: only the PNG address exists" "$code" "404"
mcp get_page "{\"id\":$OG_PAGE}" > "$WORK/response"; expect "MCP: get_page shows the generated address as share_image_generated" "$(mcp_value share_image_generated)" "$OG_URL"
sq "UPDATE ka_stranky SET titulek = 'Kamenné schody' WHERE ids = $OG_PAGE" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
OG_URL2=$(grep -o 'property="og:image" content="[^"]*"' "$WORK/response" | head -1 | sed 's/.*content="//;s/"$//')
[ "$OG_URL2" != "$OG_URL" ] && [[ "$OG_URL2" == "$B"/og/*.png ]] && echo "  ok     share images: a changed title is a new address (no stale copies at the social networks)" || { echo "  CHYBA  the address did not change with the title: $OG_URL2"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_stranky SET obrazek = 'media/2026/01/sdileni.jpg' WHERE ids = $OG_PAGE" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$WORK/response" && ! grep -q '/og/' "$WORK/response" && echo "  ok     share images: a page with its own image keeps it" || { echo "  CHYBA  a page's own share image was replaced"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_stranky SET obrazek = '' WHERE ids = $OG_PAGE; REPLACE INTO ka_nastaveni VALUES ('share_image_auto', '0')" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
! grep -q 'og:image' "$WORK/response" && grep -q 'twitter:card" content="summary"' "$WORK/response" && echo "  ok     share images: the setting off – no og:image, as before" || { echo "  CHYBA  og:image with the setting off"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o /dev/null -w '%{http_code}' "$OG_URL"); expect "share images: the setting off – the picture is not served either" "$code" "404"
mcp get_page "{\"id\":$OG_PAGE}" > "$WORK/response"; expect "MCP: get_page without share_image_generated when the setting is off" "$(mcp_value share_image_generated)" "null"
check "settings → SEO offers the switch" 200 "/admin.php?module=settings&tab=seo" 'name="share_image_auto"'
sq "DELETE FROM ka_nastaveni WHERE promenna = 'share_image_auto'" > /dev/null; [ -z "$OG_SHARE_BEFORE" ] || sq "REPLACE INTO ka_nastaveni VALUES ('share_image', '$OG_SHARE_BEFORE')" > /dev/null
mcp trash_page "{\"id\":$OG_PAGE}" > /dev/null
else echo "  skip   share images: the PHP used by the test has no GD – the image checks are skipped"; fi
echo "== 2.12: forms that know where they are, thank-you with next steps"
# the registration of the 2.11 events block was sent from a collection item page: the server recorded the collection and the item
expect "topic: a registration from an event's page records the calendar and the event" "$(sq "SELECT tema FROM ka_poptavky WHERE zdroj = 'kolekce:$EVENTS_IDK' ORDER BY idp LIMIT 1")" "Akce test – Jóga, pro začátečníky"
# a form on an ordinary page with the next steps: the page title is the topic, a posted topic is ignored
mcp create_page '{"title":"Koupelny F7","visible":true}' > /dev/null; PAGE_F7=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'koupelny-f7'")
mcp stavba_uloz "{\"id\":$PAGE_F7,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka F7\",\"pole\":[{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true}],\"dalsi_kroky\":\"Zavoláme vám\\nPřijedeme na zaměření\",\"odpovime_do\":4,\"odpovida\":\"Jana z kanceláře\"}}]}]}}" > "$WORK/response"
expect "next steps: the form keeps the steps, the working hours and who replies" "$(sq "SELECT CONCAT(stavba LIKE '%\"dalsi_kroky\":\"Zavol%', '|', stavba LIKE '%\"odpovime_do\":4%', '|', stavba LIKE '%\"odpovida\":\"Jana z kancel%') FROM ka_stranky WHERE ids = $PAGE_F7")" "1|1|1"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/koupelny-f7"
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/koupelny-f7 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p0=f7@example.cz -d tema=Podvrh -d about=Podvrh)
case "$location" in *result=ok*) echo "  ok     topic: the form on the page was sent";; *) echo "  CHYBA  form F7: $location"; ERRORS=$((ERRORS+1));; esac
F7_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE zdroj = 'stranka:$PAGE_F7'")
expect "topic: on a page the topic is the page title – what was posted for it is ignored" "$(sq "SELECT tema FROM ka_poptavky WHERE idp = $F7_IDP")" "Koupelny F7"
check "topic: the Enquiries list shows it with a link to the page" 200 "/admin.php?module=enquiries" 'Téma: <a href="/koupelny-f7"'
check "topic: the enquiry detail shows it" 200 "/admin.php?module=enquiries&action=detail&id=$F7_IDP" '<dt>Téma</dt><dd><a href="/koupelny-f7"'
mcp list_enquiries '{"limit":1}' > "$WORK/response"
expect "MCP: list_enquiries has about" "$(mcp_value 0 about)" "Koupelny F7"
# the thank-you in place of the form: the steps as a list, by when the reply comes (counted in working hours) and who replies
check "next steps: the thank-you lists the steps" 200 "/koupelny-f7?form=$FORM_ELEMENT&result=ok" '<ol class="ka-kroky"><li>Zavoláme vám</li><li>Přijedeme na zaměření</li></ol>'
grep -q 'class="ka-kroky-termin">Odpovíme .* do [0-9]*:[0-9][0-9]\.</p>' "$WORK/response" && grep -q '<p class="ka-kroky-kdo">Jana z kanceláře vám odpoví.</p>' "$WORK/response" \
  && echo "  ok     next steps: the thank-you says by when and who replies" || { echo "  CHYBA  thank-you deadline or who replies"; grep -o 'ka-formular-hotovo.\{0,400\}' "$WORK/response" | head -c 500; ERRORS=$((ERRORS+1)); }
echo "== 2.12: pricing table, before and after, hotspots, timeline"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema lists the four elements with their English names and fields" "$(mcp_value elements pricing_table | grep -c 'Pricing table.*plans:items\[name:text; price:text; period:text; description:text; features:lines; button_text:text; link:link; highlighted:boolean; badge:text\]')|$(mcp_value elements before_after | grep -c 'Before and after.*before_image:image; before_alt:text; before_label:text; after_image:image; after_alt:text; after_label:text; divider_position:number=50')|$(mcp_value elements hotspots | grep -c 'Hotspots.*points:items\[x:number; y:number; name:text; description:lines\]')|$(mcp_value elements timeline | grep -c 'Timeline.*milestones:items\[date:text; name:text; content:html; src:image; alt:text\]')" "1|1|1|1"
mcp create_page '{"title":"Prvky 2.12","slug":"prvky-2-12","visible":true}' > /dev/null; F9_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'prvky-2-12'")
mcp save_build "{\"id\":$F9_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Prvky\"}},
  {\"type\":\"pricing_table\",\"content\":{\"plans\":[{\"name\":\"Basic\",\"price\":\"9\",\"period\":\"/ month\",\"features\":\"One\\n- Two\",\"button_text\":\"Choose\",\"link\":\"/kontakt\"},{\"name\":\"Pro\",\"price\":\"29\",\"features\":\"One\\nTwo\",\"button_text\":\"Choose\",\"link\":\"javascript:alert(1)\",\"highlighted\":true,\"badge\":\"Most popular\"}]}},
  {\"type\":\"before_after\",\"content\":{\"before_image\":\"media/pred.jpg\",\"after_image\":\"media/po.jpg\",\"before_alt\":\"Before\",\"after_alt\":\"After\",\"divider_position\":40}},
  {\"type\":\"hotspots\",\"content\":{\"src\":\"media/plan.jpg\",\"alt\":\"Plan\",\"points\":[{\"x\":20,\"y\":30,\"name\":\"Entrance\",\"description\":\"Main door\"},{\"x\":80,\"y\":70,\"name\":\"Workshop\",\"description\":\"\"}]}},
  {\"type\":\"timeline\",\"content\":{\"milestones\":[{\"date\":\"2020\",\"name\":\"Founded\",\"content\":\"<p>Start</p>\"},{\"date\":\"2024\",\"name\":\"New hall\",\"content\":\"<p>Growth</p>\"}]}}]}]}}" > "$WORK/response"
contains -q 'obsah.plany.odkaz' "$WORK/response" && echo "  ok     MCP: save_build reports the rejected plan link (inside an item)" || { echo "  CHYBA  save_build: the rejected plan link was not reported"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "2.12: the English build is stored in the Czech keys, the item fields too" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[1].typ')), JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[1].obsah.plany[1].zvyraznit')), JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[1].obsah.plany[1].odkaz')), JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[3].obsah.body[0].x')), JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[4].obsah.udalosti[1].datum')) FROM ka_stranky WHERE ids = $F9_PAGE" | tr '\t' '|')" "cenik|true||20|2024"
check "2.12: the page renders the four elements" 200 "/prvky-2-12" 'ka-cenik-plan--zvyrazneny'
grep -q '<p class="ka-cenik-stitek">Most popular</p>' "$WORK/response" && grep -q '<li class="ka-cenik-ne"><span class="ka-cenik-sr">Není v ceně: </span>Two</li>' "$WORK/response" && grep -q '<a class="ka-tlacitko ka-tlacitko--primarni" href="#">Choose</a>' "$WORK/response" \
  && grep -q '<input type="range" class="ka-pred-po-ovladac" min="0" max="100" value="40" aria-label="Porovnat před a po">' "$WORK/response" && grep -q '<figcaption>Před</figcaption>' "$WORK/response" \
  && grep -q '<details class="ka-hotspoty-bod ka-hotspoty-bod--vlevo ka-hotspoty-bod--nahoru" name="hs-[a-z0-9]*" style="--x:80%;--y:70%"><summary><span aria-hidden="true">2</span><span class="ka-hotspoty-sr">Workshop</span></summary>' "$WORK/response" \
  && grep -q '<ol class="ka-hotspoty-seznam"><li><strong>Entrance</strong> – Main door</li>' "$WORK/response" && grep -q '<ol class="ka-casova-osa"><li class="ka-casova-osa-polozka"><div class="ka-casova-osa-karta"><span class="ka-casova-osa-datum">2020</span><h3>Founded</h3><p>Start</p>' "$WORK/response" \
  && echo "  ok     2.12: highlighted plan with its label, an excluded feature with a text for screen readers, the range control, hotspot popovers with the list, the timeline list" || { echo "  CHYBA  2.12 elements on the page"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web.js' "$WORK/response" && grep -q '\.ka-pred-po\[data-zapnuto\] \.ka-pred-po-po { clip-path' "$WORK/response" && grep -q '\.ka-tlacitko--primarni {' "$WORK/response" && grep -q 'prefers-reduced-motion: no-preference) {' "$WORK/response" \
  && echo "  ok     2.12: web.js stays on the page for the slider, the element CSS and the button CSS of the plans are there, the hotspot pulse respects reduced motion" || { echo "  CHYBA  2.12 page CSS and script"; ERRORS=$((ERRORS+1)); }
expect "2.12: the published text has the plans, points and milestones for search" "$(sq "SELECT CONCAT(text LIKE '%<h3>Pro</h3><p>29</p><ul><li>One</li><li>Two</li></ul>%', text LIKE '%<li>Two (není v ceně)</li>%', text LIKE '%<li>Entrance – Main door</li>%', text LIKE '%<h3>2024 – New hall</h3><p>Growth</p>%') FROM ka_stranky WHERE ids = $F9_PAGE")" "1111"
mcp get_build "{\"id\":$F9_PAGE}" > "$WORK/response"
expect "MCP: get_build answers with the English element and item names" "$(mcp_value build children 0 children 1 type)|$(mcp_value build children 0 children 1 content plans 1 badge)|$(mcp_value build children 0 children 3 content points 1 name)|$(mcp_value build children 0 children 4 content milestones 0 date)" "pricing_table|Most popular|Workshop|2020"
mcp trash_page "{\"id\":$F9_PAGE}" > /dev/null
echo "== 2.13: outbound connectors (OAuth with PKCE, encrypted credentials, the delivery log)"
FAKE_LOGS="$(php -r 'echo sys_get_temp_dir();')/kaleta-fake-$FAKE_PORT"
# connect_fake <service>: the site's OAuth app with test credentials, the sign-in through the fake service and back
connect_fake() {
  curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d "service=$1" -d client_id=test-client --data-urlencode secret=test-client-secret
  curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
  local location; location=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=connectors&action=connect" -d "_csrf=$(csrf)" -d "service=$1")
  echo "$location" > "$WORK/authorize-url"
  local state; state=$(printf %s "$location" | sed -n 's/.*[?&]state=\([a-f0-9]*\).*/\1/p')
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -L "$B/admin.php?module=connectors&action=callback&code=test-code&state=$state"
}
check "connectors: Administration → Connections lists Google with the redirect address" 200 "/admin.php?module=connectors" "module=connectors&amp;action=callback"
connect_fake google
grep -q "code_challenge_method=S256" "$WORK/authorize-url" && grep -q "access_type=offline" "$WORK/authorize-url" && grep -q "/o/oauth2/v2/auth?" "$WORK/authorize-url" \
  && echo "  ok     connectors: the sign-in asks with PKCE and a state for an offline token" || { echo "  CHYBA  authorize URL"; cat "$WORK/authorize-url"; ERRORS=$((ERRORS+1)); }
expect "connectors: Google is connected as the account from the sign-in, the tokens are encrypted" "$(sq "SELECT CONCAT(account, '|', connected_at IS NOT NULL, '|', access_token LIKE '%access-1%', '|', secret LIKE '%test-client-secret%') FROM ka_connectors WHERE service = 'google'")" "owner@example.com|1|0|0"
grep -q '"has_verifier":true' "$FAKE_LOGS-oauth.log" && echo "  ok     connectors: the code was exchanged with the PKCE verifier" || { echo "  CHYBA  PKCE verifier"; ERRORS=$((ERRORS+1)); }
check "connectors: the screen shows the connection and never the secret" 200 "/admin.php?module=connectors" "owner@example.com"
! grep -q 'test-client-secret\|access-1\|refresh-1' "$WORK/response" && echo "  ok     connectors: no credential on the page" || { echo "  CHYBA  credential on the page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o /dev/null "$B/admin.php?module=connectors&action=callback&code=test-code&state=0123456789abcdef0123456789abcdef"
expect "connectors: a callback with a state the session did not issue changes nothing" "$(sq "SELECT COUNT(*) FROM ka_connector_log WHERE action = 'oauth.token'")" "1"
connector_call() { php -r 'chdir($argv[1]); putenv("KALETA_CONNECTORS_FAKE=" . $argv[2]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $r = Kaleta\Core\Connectors::request($app, "google", "GET", "https://www.googleapis.com/echo"); echo $r["status"], "|", $r["json"]["authorization"] ?? "", "|", $r["error"];' "$WORK/web" "http://127.0.0.1:$FAKE_PORT"; }
expect "connectors: a call is authorised with the stored token" "$(connector_call)" "200|Bearer access-1|"
sq "UPDATE ka_connectors SET expires_at = '$(site_time)' - INTERVAL 1 DAY WHERE service = 'google'" > /dev/null
expect "connectors: an expired token is refreshed with the refresh token" "$(connector_call)" "200|Bearer access-2|"
expect "connectors: every call is logged, never its content" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(error LIKE '%access%')) FROM ka_connector_log WHERE service = 'google'")" "4|0"
mcp list_connectors '{}' > "$WORK/response"
contains -q 'owner@example.com' "$WORK/response" && ! contains -q 'access-2\|refresh-1\|test-client-secret' "$WORK/response" && echo "  ok     connectors: Claude sees the status, never a credential" || { echo "  CHYBA  list_connectors"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=google
expect "connectors: disconnecting revokes and forgets the tokens, the OAuth app stays" "$(sq "SELECT CONCAT(access_token IS NULL, '|', refresh_token IS NULL, '|', connected_at IS NULL, '|', secret IS NOT NULL) FROM ka_connectors WHERE service = 'google'")|$(grep -c revoked "$FAKE_LOGS-oauth.log")" "1|1|1|1|1"
echo "== 2.14: self-healing internal links"
# the target is live with content (3.5: an empty page would wait hidden, and a hidden page's rename heals nothing)
mcp vytvor_stranku '{"titulek":"Heal target","adresa":"lh-stare","text":"<p>Target</p>","zobrazit":true}' > "$WORK/response"; mcp_text; LH_TARGET=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp vytvor_stranku '{"titulek":"Heal source","zobrazit":true}' > "$WORK/response"; mcp_text; LH_SOURCE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_stranky SET stavba = '{\"v\":1,\"deti\":[{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Go\",\"odkaz\":\"/lh-stare#cast\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p><a href=\\\\\"/en/lh-stare\\\\\">x</a> <a href=\\\\\"/lh-stare-jina\\\\\">y</a></p>\"}}]}', text = '<p><a href=\"/lh-stare\">t</a></p>' WHERE ids = $LH_SOURCE" > /dev/null
sq "INSERT INTO ka_menu (umisteni, jazyk, polozky) VALUES ('lhtest', '', '[{\"typ\":\"odkaz\",\"url\":\"/lh-stare\",\"text\":\"M\"}]')" > /dev/null
mcp uprav_stranku "{\"id\":$LH_TARGET,\"adresa\":\"lh-nove\"}" > /dev/null
expect "link healing: a renamed page – button, text, menu and the language form point to the new address, a longer address is left alone" \
  "$(sq "SELECT CONCAT(stavba LIKE '%/lh-nove#cast%', stavba LIKE '%/en/lh-nove%', stavba LIKE '%/lh-stare-jina%', stavba NOT LIKE '%/lh-stare\"%', text LIKE '%/lh-nove%') FROM ka_stranky WHERE ids = $LH_SOURCE")|$(sq "SELECT polozky LIKE '%/lh-nove%' FROM ka_menu WHERE umisteni = 'lhtest'")" "11111|1"
expect "link healing: the change is an event with the count" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'links.healed' AND data LIKE '%lh-nove%'")" "1"
sq "DELETE FROM ka_menu WHERE umisteni = 'lhtest'" > /dev/null
echo "== 2.14: personal data requests"
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$(site_time)', 'PD', 'pd.person@example.com', '[[\"Name\",\"PD Person\"]]'), ('$(site_time)', 'PD', 'other@example.com', '[[\"Colleague\",\"PD.Person@example.com\"]]'), ('$(site_time)', 'PD', 'keep@example.com', '[[\"Name\",\"Keep\"]]')" > /dev/null
sq "INSERT INTO ka_odberatele (email, stav, token, datum) VALUES ('pd.person@example.com', 1, '0123456789abcdef0123456789abcdef', '$(site_time)')" > /dev/null
mcp find_personal_data '{"email":" PD.Person@Example.com "}' > "$WORK/response"; mcp_text
contains -q '"enquiries":2' "$WORK/text" && contains -q '"subscriber":1' "$WORK/text" && ! contains -q 'PD Person' "$WORK/text" && echo "  ok     personal data: Claude finds the enquiries (sender and any field) and the subscription, counts only" || { echo "  CHYBA  find_personal_data"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "personal data: the administrator's screen lists what the site keeps" 200 "/admin.php?module=enquiries&action=personal" "osobni-email"
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d email=pd.person@example.com -d provest=export
php -r '$j = json_decode(file_get_contents($argv[1]), true); exit(count($j["enquiries"] ?? []) === 2 && ($j["subscriber"]["email"] ?? "") === "pd.person@example.com" ? 0 : 1);' "$WORK/response" \
  && echo "  ok     personal data: the export is a JSON file for the person" || { echo "  CHYBA  personal data export"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp erase_personal_data '{"email":"pd.person@example.com"}' > "$WORK/response"
expect "personal data: erasing needs an explicit confirmation" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'PD'")" "3"
# 3.3.2 (N29): an enquiry a Claude session of an older release journaled, and a change of it now – which is no longer journaled
PD_IDP=$(sq "SELECT idp FROM ka_poptavky WHERE email = 'pd.person@example.com'")
sq "INSERT INTO ka_agent_sessions (connection, started_at, last_at, calls) VALUES ('pd-old', '$(site_time)' - INTERVAL 3 HOUR, '$(site_time)' - INTERVAL 3 HOUR, 1);
  INSERT INTO ka_agent_journal (session_id, call_no, tool, tbl, row_key, before_row, after_row, created_at) SELECT LAST_INSERT_ID(), 1, 'delete_enquiry', 'poptavky', CONCAT('{\"idp\":', idp, '}'),
  JSON_OBJECT('idp', idp, 'datum', datum, 'formular', formular, 'email', email, 'data', data), NULL, '$(site_time)' - INTERVAL 3 HOUR FROM ka_poptavky WHERE idp = $PD_IDP" > /dev/null
PD_OLD=$(sq "SELECT MAX(id) FROM ka_agent_sessions WHERE connection = 'pd-old'")
mcp update_enquiry "{\"id\":$PD_IDP,\"status\":\"read\"}" > /dev/null
mcp erase_personal_data '{"email":"pd.person@example.com","confirm":true}' > /dev/null
expect "personal data: erased – both enquiries and the subscriber; other people's enquiry stays; the log has no address" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'PD'), '|', (SELECT COUNT(*) FROM ka_odberatele WHERE email = 'pd.person@example.com'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'personal_data.erased' AND data NOT LIKE '%@%'))")" "1|0|1"
expect "3.3.2 personal data: the undo journal holds no copy of the erased address – enquiries are not journaled and an older entry is redacted" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_agent_journal WHERE LOWER(CONCAT_WS('|', before_row, after_row)) LIKE '%pd.person@example.com%'), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE tbl = 'poptavky' AND untracked IS NULL), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE session_id = $PD_OLD AND untracked = 'personal data erased on request'))")" "0|0|1"
mcp undo_agent_session "{\"id\":$PD_OLD,\"confirm\":true}" > "$WORK/response"; mcp_text
expect "3.3.2 personal data: undoing the older session does not bring the erased enquiry back and names the redacted write" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $PD_IDP")|$(grep -c 'personal data erased on request' "$WORK/text")" "0|1"
sq "DELETE FROM ka_poptavky WHERE formular = 'PD'" > /dev/null
echo "== 2.14: password-protected pages"
mcp vytvor_stranku '{"titulek":"Partner prices","adresa":"partner-ceny","text":"<p>Secret partner price 42</p>","zobrazit":true}' > "$WORK/response"; mcp_text; LOCK_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_stranky SET heslo_hash = '$(php -r 'echo password_hash("partner-2026", PASSWORD_DEFAULT);')' WHERE ids = $LOCK_PAGE" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
VJAR="$WORK/visitor-jar"; rm -f "$VJAR"
curl -s -c "$VJAR" -o "$WORK/response" "$B/partner-ceny"
contains -q 'ka-heslo-stranky' "$WORK/response" && ! contains -q 'Secret partner price' "$WORK/response" && contains -q 'noindex' "$WORK/response" \
  && echo "  ok     page lock: a visitor sees the password form, not the content, and the page is noindex" || { echo "  CHYBA  page lock form"; ERRORS=$((ERRORS+1)); }
expect "page lock: a wrong password is refused" "$(curl -s -b "$VJAR" -c "$VJAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=wrong)" "403"
expect "page lock: the right password opens the page for this visitor" "$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=partner-2026)|$(curl -s -b "$VJAR" "$B/partner-ceny" | grep -c 'Secret partner price')|$(curl -s "$B/partner-ceny" | grep -c 'Secret partner price')" "303|1|0"
# 3.3.3 (N58): past the page's cap of wrong passwords from all addresses only wrong ones are refused – the right one still opens
LOCK_FILES=$(php -r 'foreach ([0, 1] as $n) { echo $argv[1], "/storage/cache/firewall/page-lock-all-", intdiv(time(), 900) + $n, "-", substr(hash("sha256", "page-" . $argv[2]), 0, 24), "\n"; }' "$WORK/web" "$LOCK_PAGE")
mkdir -p "$WORK/web/storage/cache/firewall"; for f in $LOCK_FILES; do printf '%0.s.' $(seq 1 120) > "$f"; done
rm -f "$VJAR"; curl -s -c "$VJAR" -o /dev/null "$B/partner-ceny"
expect "3.3.3 page lock: past the page's cap a wrong password is refused as too many attempts, the right one opens the page" \
  "$(curl -s -b "$VJAR" -c "$VJAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=wrong-again)|$(grep -c 'Příliš mnoho pokusů' "$WORK/response")|$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=partner-2026)|$(curl -s -b "$VJAR" "$B/partner-ceny" | grep -c 'Secret partner price')" "403|1|303|1"
rm -f $LOCK_FILES
! ls "$WORK"/web/storage/cache/stranky/ 2>/dev/null | xargs -I{} grep -l 'Secret partner price' "$WORK/web/storage/cache/stranky/{}" 2>/dev/null | grep -q . && ! curl -s "$B/sitemap.xml" | contains 'partner-ceny' && ! curl -s "$B/hledani?q=partner" | contains 'Secret partner' \
  && echo "  ok     page lock: never in the page cache, the sitemap or the site search" || { echo "  CHYBA  page lock leaks"; ERRORS=$((ERRORS+1)); }
mcp nacti_stranku "{\"id\":$LOCK_PAGE}" > "$WORK/response"; mcp_text
contains -q '"password_protected":true' "$WORK/text" && ! contains -q 'heslo_hash\|\$2y\$' "$WORK/response" && echo "  ok     page lock: Claude sees that the page is protected, never the hash" || { echo "  CHYBA  get_page lock flag"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$LOCK_PAGE"
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$(csrf)" -d ids=$LOCK_PAGE -d titulek=Partner+prices -d seo_link=partner-ceny -d zobrazit=1 -d heslo_zrusit=1 --data-urlencode "text=<p>Secret partner price 42</p>"
expect "page lock: removing the password in the admin makes the page public" "$(sq "SELECT heslo_hash IS NULL FROM ka_stranky WHERE ids = $LOCK_PAGE")|$(curl -s "$B/partner-ceny" | grep -c 'Secret partner price')" "1|1"
echo "== 2.14: content hygiene – media clean-up, alt texts over MCP, content check, translation overview, bulk actions"
# an unused upload is listed in the clean-up and deleted from there after a confirmation (Media has no trash)
mcp upload_file "{\"filename\":\"nepouzity-f16.png\",\"data\":\"$PNG\"}" > /dev/null; F16_IDO=$(sq "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%nepouzity-f16%' ORDER BY ido DESC LIMIT 1")
check "clean-up: the unused upload is listed with a checkbox of the delete form" 200 "/admin.php?module=media&action=cleanup" "name=\"oznacene\[\]\" value=\"$F16_IDO\" form=\"smazani\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=bulk" -d "_csrf=$TOKEN" -d provest=smaz -d zpet=cleanup -d "oznacene[]=$F16_IDO"
expect "clean-up: the unused file is deleted, the change is logged" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $F16_IDO")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'media' AND akce = 'deleted'")" "0|1"
# an image without a description: listed over MCP, described with update_media; the same in bulk from the clean-up form
mcp upload_file "{\"filename\":\"bez-popisu-f16.png\",\"data\":\"$PNG\"}" > /dev/null; F16_ALT=$(sq "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%bez-popisu-f16%' ORDER BY ido DESC LIMIT 1")
sq "UPDATE ka_media SET nazev = '' WHERE ido = $F16_ALT" > /dev/null
mcp list_media_without_alt '{"limit":200}' > "$WORK/response"
mcp_value images | grep -q "\"id\":$F16_ALT,\"path\":\"media/" && echo "  ok     MCP: list_media_without_alt lists the image without a description" || { echo "  CHYBA  list_media_without_alt"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_media "{\"id\":$F16_ALT,\"alt\":\"Modrý čtverec\"}" > /dev/null
expect "MCP: update_media writes the description (alt)" "$(sq "SELECT nazev FROM ka_media WHERE ido = $F16_ALT")" "Modrý čtverec"
mcp list_media_without_alt '{"limit":200}' > "$WORK/response"
mcp_value images | grep -q "\"id\":$F16_ALT," && { echo "  CHYBA  a described image is still listed"; ERRORS=$((ERRORS+1)); } || echo "  ok     MCP: a described image leaves the list"
sq "UPDATE ka_media SET nazev = '' WHERE ido = $F16_ALT" > /dev/null
check "clean-up: the image without a description has an input" 200 "/admin.php?module=media&action=cleanup" "name=\"alt\[$F16_ALT\]\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=save_alts" -d "_csrf=$TOKEN" --data-urlencode "alt[$F16_ALT]=Ctverec z formulare"
expect "clean-up: descriptions saved in bulk" "$(sq "SELECT nazev FROM ka_media WHERE ido = $F16_ALT")" "Ctverec z formulare"
# content check: a text page with a short title, no description, a skipped heading level and an image without alt
mcp create_page '{"title":"Kontrola obsahu F16","content":"<h2>Co nabízíme</h2><p>Text o kuchyních.</p><h4>Skok</h4><img src=\"/media/x.jpg\">"}' > /dev/null; F16_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'kontrola-obsahu-f16'")
mcp get_page "{\"id\":$F16_PAGE}" > "$WORK/response"; mcp_value content_check > "$WORK/check.json"
expect "MCP: get_page content_check – one H1 (the title), a skipped level, an image without alt, a short title, no description" \
  "$(php -r '$c = array_column(json_decode(file_get_contents($argv[1]), true), "ok", "check"); echo (int) $c["single_h1"], (int) $c["heading_order"], (int) $c["images_alt"], (int) $c["title_length"], (int) $c["description_length"];' "$WORK/check.json")" "10000"
check "the page editor shows the content check of the saved version" 200 "/admin.php?module=pages&action=edit&id=$F16_PAGE" 'data-kontrola="heading_order"'
# translation overview: the page has no English version – missing in the admin matrix and over MCP; after translating and changing the original – outdated
check "translations: the overview offers to create the missing English version" 200 "/admin.php?module=pages&action=translations" "translation_of=$F16_PAGE"
grep -q 'data-stav="missing"' "$WORK/response" && echo "  ok     translations: the cell says missing" || { echo "  CHYBA  translation matrix cell"; ERRORS=$((ERRORS+1)); }
mcp translation_status '{"type":"page"}' > "$WORK/response"; mcp_value items > "$WORK/items.json"
f16_status() { php -r '$v = json_decode(file_get_contents($argv[1]), true) ?: []; foreach ($v as $i) { if ((int) $i["id"] === (int) $argv[2]) { echo $i["type"], "|", $i["translations"]["en"]["status"]; } }' "$WORK/items.json" "$F16_PAGE"; }
expect "MCP: translation_status reports the missing English version" "$(f16_status)" "page|missing"
mcp create_page "{\"title\":\"Content check F16\",\"language\":\"en\",\"translation_of\":$F16_PAGE}" > /dev/null
mcp translation_status '{"type":"page"}' > "$WORK/response"; mcp_value items > "$WORK/items.json"
expect "MCP: a translated page is not reported" "$(f16_status)" ""
sleep 1; mcp update_page "{\"id\":$F16_PAGE,\"description\":\"Originál se změnil po překladu.\"}" > /dev/null
mcp translation_status '{"status":"outdated"}' > "$WORK/response"; mcp_value items > "$WORK/items.json"
expect "MCP: the original changed after the translation – outdated" "$(f16_status)" "page|outdated"
check "translations: the matrix marks the older translation" 200 "/admin.php?module=pages&action=translations" 'data-stav="outdated"'
# bulk actions in the pages list: two pages hidden at once, one moved to a language version, one to the trash
mcp create_page '{"title":"Hromadně A","visible":true}' > /dev/null; mcp create_page '{"title":"Hromadně B","visible":true}' > /dev/null
F16_A=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'hromadne-a'"); F16_B=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'hromadne-b'")
check "pages list: row checkboxes belong to the bulk form" 200 "/admin.php?module=pages" "name=\"oznacene\[\]\" value=\"$F16_A\" form=\"hromadne\""
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$TOKEN" -d provest=skryt -d "oznacene[]=$F16_A" -d "oznacene[]=$F16_B"
expect "bulk: two pages hidden at once, a change log entry each" "$(sq "SELECT GROUP_CONCAT(zobrazit ORDER BY ids) FROM ka_stranky WHERE ids IN ($F16_A, $F16_B)")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pages' AND akce = 'bulk hidden'")" "0,0|2"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$TOKEN" -d provest=jazyk -d jazyk=en -d "oznacene[]=$F16_A"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$TOKEN" -d provest=kos -d "oznacene[]=$F16_B"
expect "bulk: a page moved to the English version, another to the trash" "$(sq "SELECT CONCAT((SELECT jazyk FROM ka_stranky WHERE ids = $F16_A), '|', (SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $F16_B))")" "en|1"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=bulk" -d provest=kos -d "oznacene[]=$F16_A"); expect "bulk: a POST without CSRF is refused" "$code" "400"
sq "UPDATE ka_stranky SET smazano = '$(site_time)' WHERE ids IN ($F16_A, $F16_PAGE) OR preklad_z = $F16_PAGE" > /dev/null
echo "== 2.14: EU duties as templates – cookie scanner, anonymise, record of processing, accessibility statement, toolbar"
mcp create_page '{"title":"Video 2.14","slug":"video-2-14","visible":true}' > /dev/null; F17_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'video-2-14'")
mcp save_build "{\"id\":$F17_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Video\"}},{\"type\":\"video\",\"content\":{\"url\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\",\"title\":\"Clip\"}},
  {\"type\":\"form\",\"content\":{\"name\":\"Servis 2.14\",\"fields\":[{\"label\":\"Jméno a příjmení\",\"type\":\"text\",\"required\":true},{\"label\":\"E-mail\",\"type\":\"email\",\"required\":true},{\"label\":\"Rozsah opravy\",\"type\":\"textarea\"}]}}]}]}}" > /dev/null
sq "INSERT INTO ka_nastaveni VALUES ('cookies_mode', 'vestavena') ON DUPLICATE KEY UPDATE hodnota = 'vestavena'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=cookies"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=cookie_scan" -d "_csrf=$(csrf)" -d tab=cookies
check "2.14: Settings → Privacy lists the consent storage and the YouTube cookies" 200 "/admin.php?module=settings&tab=cookies" '<code>kaleta_souhlas</code>'
grep -q '<code>VISITOR_INFO1_LIVE</code></td><td>YouTube</td>' "$WORK/response" && grep -q 'Skenovat web teď' "$WORK/response" && ! grep -q '<code>ka-pristupnost</code>' "$WORK/response" \
  && echo "  ok     2.14: the YouTube embed maps to its cookies, the scan button is there, the toolbar storage is listed only when the toolbar is on" || { echo "  CHYBA  2.14 cookie table"; grep -o 'Cookies a úložiště.\{0,600\}' "$WORK/response" | head -c 700; ERRORS=$((ERRORS+1)); }
grep -q 'Poslední sken vlastních stránek webu' "$WORK/response" && echo "  ok     2.14: the scan of the site's own pages ran and is dated" || { echo "  CHYBA  2.14 cookie scan did not record its run"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Cookies 2.14","slug":"cookies-2-14","content":"<p>Co používáme:</p><p>{{cookie_table}}</p>","visible":true}' > /dev/null
check "2.14: {{cookie_table}} on the cookie policy page becomes the table in the site language" 200 "/cookies-2-14" '<table class="ka-cookies-tabulka"><thead><tr><th>Název</th><th>Poskytovatel</th><th>Účel</th><th>Doba</th><th>Kategorie</th></tr></thead>'
grep -q '<td><code>kaleta_souhlas</code></td><td>Kaleta</td>' "$WORK/response" && grep -q '<td><code>YSC</code></td><td>YouTube</td><td>Přehrávač videa: zhlédnutí v rámci relace</td><td>relace</td><td>Marketing</td>' "$WORK/response" \
  && echo "  ok     2.14: the visitors' table has Kaleta's consent cookie and the YouTube rows translated" || { echo "  CHYBA  2.14 visitors' cookie table"; grep -o 'ka-cookies-tabulka.\{0,500\}' "$WORK/response" | head -c 600; ERRORS=$((ERRORS+1)); }
# anonymise instead of delete: the retention keeps the row with blanks (the choice first – opening Enquiries runs the retention, which would delete the old row under the default)
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=settings" -d "_csrf=$(csrf)" -d mesice=24 -d mesice_uchazeci=0 -d po_uplynuti=anonymise
F17_OLD=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav, kategorie) VALUES ('$(site_time)' - INTERVAL 30 MONTH, 'Servis 2.14', '/video-2-14', 'Video', 'stary@example.com', '[[\"Jméno\",\"Starý Zákazník\"],[\"E-mail\",\"stary@example.com\"],[\"Zpráva\",\"Opravte kotel\"]]', 2, 'sales'); SELECT LAST_INSERT_ID()")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"
expect "2.14: retention with anonymise keeps the row – date, form, page, topic and kind stay, the person is blank" "$(sq "SELECT CONCAT(formular, '|', stranka, '|', tema, '|', kategorie, '|', email, '|', data, '|', anonymizovano IS NOT NULL) FROM ka_poptavky WHERE idp = $F17_OLD")" 'Servis 2.14|/video-2-14|Video|sales||[["Jméno",""],["E-mail",""],["Zpráva",""]]|1'
grep -q 'name="po_uplynuti" value="anonymise" checked' "$WORK/response" && echo "  ok     2.14: the enquiry settings remember anonymise" || { echo "  CHYBA  2.14 enquiry settings"; ERRORS=$((ERRORS+1)); }
F17_NEW=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES ('$(site_time)', 'Servis 2.14', '/video-2-14', 'novy@example.com', '[[\"Jméno\",\"Nový Zákazník\"],[\"E-mail\",\"novy@example.com\"]]', 0); SELECT LAST_INSERT_ID()")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$F17_NEW"
grep -q 'action=anonymise' "$WORK/response" && echo "  ok     2.14: the enquiry detail offers Anonymise" || { echo "  CHYBA  2.14 no Anonymise action in the detail"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$(csrf)" -d "idp=$F17_NEW"
expect "2.14: a per-enquiry Anonymise blanks the person and keeps the row" "$(sq "SELECT CONCAT(email, '|', data, '|', anonymizovano IS NOT NULL) FROM ka_poptavky WHERE idp = $F17_NEW")" '|[["Jméno",""],["E-mail",""]]|1'
check "2.14: the detail of an anonymised enquiry says so and no longer offers the action" 200 "/admin.php?module=enquiries&action=detail&id=$F17_NEW" 'Anonymizováno'
grep -q 'action=anonymise' "$WORK/response" && { echo "  CHYBA  2.14 Anonymise offered twice"; ERRORS=$((ERRORS+1)); } || echo "  ok     2.14: an anonymised enquiry is not anonymised again"
# the record of processing from the configuration
mcp processing_record '{}' > "$WORK/response"
contains -q 'Servis 2.14' "$WORK/response" && contains -q 'Jméno a příjmení (text), E-mail (e-mail), Rozsah opravy' "$WORK/response" && contains -q '24 m' "$WORK/response" && contains -q "anonymi" "$WORK/response" && contains -q "VISITOR_INFO1_LIVE" "$WORK/response" && contains -q 'not legal advice' "$WORK/response" \
  && echo "  ok     MCP: processing_record names the form with its fields, the retention with anonymise, the cookies and the template notice" || { echo "  CHYBA  processing_record"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "2.14: Settings → Privacy → Record of processing is a page with the sections" 200 "/admin.php?module=settings&action=processing_record" 'Servis 2.14'
grep -q 'není právní radou' "$WORK/response" && echo "  ok     2.14: the record says it is a template, not legal advice" || { echo "  CHYBA  2.14 record without the template notice"; ERRORS=$((ERRORS+1)); }
# the accessibility statement from the audit
mcp accessibility_statement '{}' > "$WORK/response"
expect "MCP: accessibility_statement knows the standard and that no page exists yet" "$(mcp_value standard)|$(mcp_value page)" "EN 301 549 / WCAG 2.1 AA|null"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=cookies"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=accessibility_statement" -d "_csrf=$(csrf)" -d tab=cookies
F17_STATEMENT=$(sq "SELECT CONCAT(ids, '|', seo_link, '|', zobrazit, '|', text LIKE '%EN 301 549%', '|', text LIKE '%Stav souladu%') FROM ka_stranky WHERE titulek = 'Prohlášení o přístupnosti'")
expect "2.14: the statement is a hidden draft page in the site language with the standard and the status" "$(echo "$F17_STATEMENT" | cut -d'|' -f2-)" "prohlaseni-o-pristupnosti|0|1|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=cookies"
grep -q 'skrytý koncept' "$WORK/response" && grep -q 'Znovu vytvořit koncept z auditu' "$WORK/response" && echo "  ok     2.14: Settings → Privacy shows the draft and offers to regenerate it" || { echo "  CHYBA  2.14 statement in settings"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=accessibility_statement" -d "_csrf=$(csrf)" -d tab=cookies
expect "2.14: regenerating updates the same draft page" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Prohlášení o přístupnosti' AND smazano IS NULL")" "1"
mcp accessibility_statement '{}' > "$WORK/response"
expect "MCP: accessibility_statement sees the hidden page" "$(mcp_value page slug)|$(mcp_value page published)" "prohlaseni-o-pristupnosti|"
# the toolbar: only when on
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "2.14: without the setting the site has no accessibility toolbar" 200 "/video-2-14" 'Video'
grep -q 'data-pristupnost' "$WORK/response" && { echo "  CHYBA  2.14 toolbar shown while off"; ERRORS=$((ERRORS+1)); } || echo "  ok     2.14: the toolbar markup is absent while off"
sq "INSERT INTO ka_nastaveni VALUES ('accessibility_toolbar', '1') ON DUPLICATE KEY UPDATE hodnota = '1'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "2.14: with the setting on, the toolbar is on the page with translated options" 200 "/video-2-14" 'data-pristupnost-volba="kontrast" aria-pressed="false">Vysoký kontrast</button>'
# 3.5 (UXP-03): "Larger text" shows its size instead of an on/off state; the panel resets the popover's inset (it opened top-left)
grep -q 'data-pristupnost-volba="text"><span>Větší písmo</span> <span class="ka-pristupnost-uroven">' "$WORK/response" && grep -q 'ka-pristupnost-panel { position: fixed; inset: auto auto calc(' "$WORK/response" \
  && echo "  ok     3.5: toolbar – the text size in the label, the panel above its button" || { echo "  CHYBA  3.5: toolbar label or panel position"; ERRORS=$((ERRORS+1)); }
grep -q 'aria-label="Možnosti přístupnosti"' "$WORK/response" && grep -q "localStorage.getItem(KEY)" "$WORK/response" \
  && echo "  ok     2.14: the toolbar is labelled for screen readers and remembers the choice in localStorage" || { echo "  CHYBA  2.14 toolbar markup"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'accessibility_toolbar'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
mcp trash_page "{\"id\":$F17_PAGE}" > /dev/null
echo "== 2.14: links that look after themselves"
# 404s that fix themselves: a page moved without a redirect, visitors still ask for the old address (and a similar one)
mcp create_page '{"title":"Reference portfolio","slug":"reference-portfolio","visible":true,"in_menu":false,"text":"<p>Naše reference.</p>"}' > /dev/null
F15_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'reference-portfolio'")
sq "UPDATE ka_stranky SET seo_link = 'sluzby/reference-portfolio' WHERE ids = $F15_PAGE; DELETE FROM ka_presmerovani WHERE z_adresy LIKE '%reference-portfolio%'; DELETE FROM ka_nenalezeno; DELETE FROM ka_events WHERE type = 'redirect.auto'" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
for i in 1 2 3; do curl -s -o /dev/null "$B/reference-portfolio"; curl -s -o /dev/null "$B/reference-portfolio-2019"; curl -s -o /dev/null "$B/qzx-nahodna-adresa"; done
check "Redirects: the 404 list shows the page the visitor probably meant" 200 "/admin.php?module=redirects" "/sluzby/reference-portfolio"
grep -q 'name="redirect_auto"' "$WORK/response" && grep -q 'Vytvořit přesměrování' "$WORK/response" && grep -q 'žádná podobná stránka' "$WORK/response" && echo "  ok     Redirects: the setting for redirects by themselves, one-click create, no suggestion for a random address" || { echo "  CHYBA  redirects suggestions screen"; ERRORS=$((ERRORS+1)); }
mcp list_redirects '{}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '\\"suggestion\\":\\"/sluzby/reference-portfolio\\",\\"score\\":90' "$WORK/response" && grep -q '\\"suggestion\\":\\"/sluzby/reference-portfolio\\",\\"score\\":85' "$WORK/response" && grep -qE 'qzx-nahodna-adresa[^}]*\\"suggestion\\":null' "$WORK/response" \
  && echo "  ok     MCP: list_redirects carries the suggestion and its score" || { echo "  CHYBA  list_redirects suggestions"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('redirects', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "redirects: off" "$WORK/tasks.txt" && [ "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE auto_score IS NOT NULL")" = 0 ] && echo "  ok     redirects by themselves are off by default" || { echo "  CHYBA  redirects job default"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=redirects"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=settings" -d "_csrf=$(csrf)" -d redirect_auto=1 -d redirect_auto_threshold=90
expect "the setting is saved from the Redirects screen" "$(sq "SELECT GROUP_CONCAT(hodnota ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('redirect_auto', 'redirect_auto_threshold')")" "1,90"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'redirects'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "the daily job creates the redirect above the threshold, not below it" "$(sq "SELECT CONCAT((SELECT CONCAT(na_adresu, '|', typ, '|', auto_score) FROM ka_presmerovani WHERE z_adresy = 'reference-portfolio'), '|', (SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy IN ('reference-portfolio-2019', 'qzx-nahodna-adresa')))")" "sluzby/reference-portfolio|301|90|0"
expect "the old address redirects, the missing address leaves the 404 log" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/reference-portfolio")|$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta = 'reference-portfolio'")" "301 $B/sluzby/reference-portfolio|0"
expect "the automatic redirect is in the change log and the event redirect.auto" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'redirect.auto' AND data LIKE '%\"from\":\"/reference-portfolio\",\"to\":\"/sluzby/reference-portfolio\",\"score\":90%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'redirects' AND akce = 'auto' AND popis LIKE '%reference-portfolio%'))")" "1|1"
check "the Redirects list marks it automatic with the score (undo = delete)" 200 "/admin.php?module=redirects" "automaticky, skóre 90"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$(csrf)" -d z_adresy=/reference-portfolio-2019 -d na_adresu=/sluzby/reference-portfolio -d typ=301
expect "one click creates the suggested redirect by hand – not marked automatic" "$(sq "SELECT CONCAT(na_adresu, '|', auto_score IS NULL) FROM ka_presmerovani WHERE z_adresy = 'reference-portfolio-2019'")" "sluzby/reference-portfolio|1"
mcp update_settings '{"settings":{"redirect_auto":false}}' > /dev/null
expect "MCP: update_settings switches the automatic redirects off" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'redirect_auto'")" "0"
# internal link suggestions: a published page out of the navigation that nothing links to
mcp create_page '{"title":"Reference portfolio detail","slug":"portfolio-sirotek","visible":true,"in_menu":false,"text":"<p>Detail.</p>"}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "Site audit lists the orphan page" 200 "/admin.php?module=audit" "Stránky, na které nikdo neodkazuje"
grep -q 'Stránka „Reference portfolio detail“' "$WORK/response" && echo "  ok     the orphan is named with where to fix it" || { echo "  CHYBA  orphan in audit"; ERRORS=$((ERRORS+1)); }
mcp suggest_internal_links '{"limit":100}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '\\"page\\":\\"/portfolio-sirotek\\"' "$WORK/response" && grep -q '\\"page\\":\\"/sluzby/reference-portfolio\\",\\"shared_words\\":\[\\"reference\\",\\"portfolio\\"\]' "$WORK/response" \
  && echo "  ok     MCP: suggest_internal_links returns the orphan with a candidate page sharing title words" || { echo "  CHYBA  suggest_internal_links"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$F15_PAGE,\"text\":\"<p>Naše reference – <a href=\\\"/portfolio-sirotek\\\">detail</a>.</p>\"}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; mcp suggest_internal_links '{"limit":100}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '\\"page\\":\\"/portfolio-sirotek\\",\\"target\\"' "$WORK/response" && { echo "  CHYBA  a linked page is still an orphan"; ERRORS=$((ERRORS+1)); } || echo "  ok     a page linked from a text is no orphan any more"
# broken external links in a page build: a local port nothing listens on (the test server runs with KALETA_LINKS_LOCAL=1)
mcp create_page '{"title":"Odkazy test","slug":"odkazy-test","visible":true}' > /dev/null; F15_BUILD=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'odkazy-test'")
mcp save_build "{\"id\":$F15_BUILD,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Starý partner\",\"link\":\"http://127.0.0.1:1/partner\"}}]}]}}" > /dev/null
mcp publish_build "{\"id\":$F15_BUILD}" > /dev/null
F15_ELEMENT=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[0].id')) FROM ka_stranky WHERE ids = $F15_BUILD")
sq "UPDATE ka_novinky SET odkazy_cas = '$(site_time)'; UPDATE ka_stranky SET links_checked = '$(site_time)' WHERE ids <> $F15_BUILD; UPDATE ka_kolekce_polozky SET links_checked = '$(site_time)'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'link_check_time'; DELETE FROM ka_odkazy_vadne" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "the link check finds the dead link in the page build with its element" "$(sq "SELECT CONCAT(kind, '|', idc, '|', element, '|', stav) FROM ka_odkazy_vadne WHERE url = 'http://127.0.0.1:1/partner'")" "page|$F15_BUILD|$F15_ELEMENT|0"
mcp list_broken_links '{}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q "\\\\\"kind\\\\\":\\\\\"page\\\\\",\\\\\"id\\\\\":$F15_BUILD,\\\\\"title\\\\\":\\\\\"Odkazy test\\\\\"" "$WORK/response" && grep -q "\\\\\"element\\\\\":\\\\\"$F15_ELEMENT\\\\\"" "$WORK/response" && grep -q 'web.archive.org/web/2020/http://127.0.0.1:1/partner' "$WORK/response" \
  && echo "  ok     MCP: list_broken_links says where the link is, the element and the archive hint" || { echo "  CHYBA  list_broken_links"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "News → Broken links is a site-wide list" 200 "/admin.php?module=news&action=links" "Odkazy test"
grep -q "prvek $F15_ELEMENT" "$WORK/response" && echo "  ok     the list names the element" || { echo "  CHYBA  broken links element"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"link"}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '127.0.0.1:1/partner' "$WORK/response" && grep -q "\\\\\"element\\\\\":\\\\\"$F15_ELEMENT\\\\\"" "$WORK/response" && echo "  ok     site_audit reports the broken build link with the element" || { echo "  CHYBA  site_audit broken link"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=links"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=links" -d "_csrf=$(csrf)" -d kind=page -d id=$F15_BUILD
expect "Check again puts the page at the front of the queue" "$(sq "SELECT CONCAT((SELECT links_checked IS NULL FROM ka_stranky WHERE ids = $F15_BUILD), '|', (SELECT COUNT(*) FROM ka_odkazy_vadne WHERE kind = 'page' AND idc = $F15_BUILD))")" "1|0"

echo "== 2.14: whistleblowing channel"
WB_SMTP_PORT=$((PORT + 11)); mkdir -p "$WORK/smtp-wb"
command php "$ROOT/tools/fake-smtp.php" "$WB_SMTP_PORT" "$WORK/smtp-wb" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$WB_SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'); UPDATE ka_uzivatele SET email = 'wb-reader@example.cz' WHERE user = 'admin'" > /dev/null
WB_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
wb_csrf() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=whistleblowing"; csrf; }
check "whistleblowing: off by default – the public address is a 404" 404 "/_report"
# 3.2: Whistleblowing is a feature, off on a new installation – switched on in the administration under Features
check "3.2 whistleblowing: a feature that a new installation starts without – no admin module" 403 "/admin.php?module=whistleblowing"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=extensions"
grep -q 'value="whistleblowing"' "$WORK/response" && grep -q 'value="bookings"' "$WORK/response" && echo "  ok     3.2: Features offers Bookings and Whistleblowing" || { echo "  CHYBA  Features: the new features are not offered"; ERRORS=$((ERRORS+1)); }
WB_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
WB_FEATURES=(); for e in $(printf %s "$WB_EXT" | tr ',' ' ') whistleblowing; do WB_FEATURES+=(-d "rozsireni[]=$e"); done
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=extensions&action=save" -d "_csrf=$(csrf)" -d tab=extensions "${WB_FEATURES[@]}" -d "claude_destructive=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_destructive'), '1')")"
expect "3.2 whistleblowing: switched on under Features, the other features kept" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")" "$WB_EXT,whistleblowing"
check "whistleblowing: the module tells the administrator the channel is off and offers the setup" 200 "/admin.php?module=whistleblowing" 'name="readers'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=whistleblowing&action=settings" -d "_csrf=$(wb_csrf)" -d enabled=1 -d "readers[]=$WB_ADMIN" -d retention=24 --data-urlencode "intro=Oznámení řeší compliance officer."
expect "whistleblowing: the setup is saved – on, the reader, the retention" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_enabled'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_readers'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_retention_months'))")" "1|$WB_ADMIN|24"
# 3.3.3 (N53): a site with a CAPTCHA still loads none on the channel – the provider would learn who reports
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_provider','turnstile'),('captcha_site_key','test-site'),('captcha_secret','test-secret') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
check "whistleblowing: the public form with the introduction" 200 "/_report" 'name="text"'
grep -q 'compliance officer' "$WORK/response" && grep -q 'noindex' "$WORK/response" && ! grep -q 'googletagmanager\|data-souhlas=\|cookies-lista\|<script src="https://\|challenges.cloudflare.com\|cf-turnstile' "$WORK/response" \
  && echo "  ok     whistleblowing: the page is not indexed and carries no tracking code, consent bar, CAPTCHA or third-party script" || { echo "  CHYBA  whistleblowing page privacy"; ERRORS=$((ERRORS+1)); }
cp "$WORK/response" "$WORK/formular.html"; WB_TIME=$(field_value as_cas); WB_SIGNATURE=$(field_value as_podpis)
sleep 4
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Vedoucí skladu falšuje evidenci docházky." -F name= -F contact= -F "files[]=@$WORK/cv.pdf")
WB_NUMBER=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//'); WB_CODE=$(grep -o 'ka-oznameni-kod">[A-Z0-9-]*' "$WORK/response" | sed 's/.*>//')
expect "whistleblowing: an anonymous report with an attachment got the first case number of the year and a code (3.3.3: no CAPTCHA answer needed)" "$WB_HTTP|$WB_NUMBER|$(printf '%s' "$WB_CODE" | tr -d '-' | wc -c | tr -d ' ')" "200|$(date +%Y)-0001|20"
sq "DELETE FROM ka_nastaveni WHERE promenna LIKE 'captcha_%'" > /dev/null
expect "whistleblowing: only a hash of the code is stored; no plaintext of the report, no contact (anonymous), the attachment outside the web root" \
  "$(sq "SELECT CONCAT(LENGTH(code_hash), '|', code_hash LIKE '%$(printf '%s' "$WB_CODE" | tr -d '-')%', '|', text LIKE '%docházky%', '|', text LIKE '%Vedouc%', '|', contact IS NULL, '|', status, '|', attachments IS NOT NULL) FROM ka_whistleblowing_cases WHERE number = '$WB_NUMBER'")|$(ls "$WORK/web/storage/oznameni/$(date +%Y)/" | wc -l | tr -d ' ')" "64|0|0|0|1|received|1|1"
expect "whistleblowing: the event names the case only" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(message LIKE '%$WB_NUMBER%'), '|', MAX(message LIKE '%docházky%')) FROM ka_events WHERE type = 'whistleblowing.received'")" "1|1|0"
F=$(grep -l "^X-Rcpt-To: wb-reader@example.cz" "$WORK"/smtp-wb/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
grep -q "$WB_NUMBER" "$WORK/eml.txt" && ! grep -q 'docházky\|dochazky' "$WORK/eml.txt" && echo "  ok     whistleblowing: the reader's e-mail names the case number and carries no content" || { echo "  CHYBA  whistleblowing e-mail"; head -20 "$WORK/eml.txt"; ERRORS=$((ERRORS+1)); }
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report/follow" -d "number=$WB_NUMBER" --data-urlencode "code=$WB_CODE")
[ "$WB_HTTP" = 200 ] && grep -q 'data-stav="received"' "$WORK/response" && grep -q "$WB_NUMBER" "$WORK/response" && grep -q 'name="reply"' "$WORK/response" \
  && echo "  ok     whistleblowing: the follow-up with the code shows the status and a reply box" || { echo "  CHYBA  whistleblowing follow-up: HTTP $WB_HTTP"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d "code=$(printf '%s' "$WB_CODE" | tr 'A-Z' 'a-z' | tr -d '-')" --data-urlencode "reply=Doplňuji: děje se to každé pondělí."
grep -q 'ka-oznameni-zprava--reporter' "$WORK/response" && expect "whistleblowing: the reporter added information (the code typed in lower case without dashes); the message is encrypted" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(sender), '|', MAX(text LIKE '%pondělí%')) FROM ka_whistleblowing_messages")" "1|reporter|0" || { echo "  CHYBA  whistleblowing reply"; ERRORS=$((ERRORS+1)); }
expect "whistleblowing: a wrong code is refused" "$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d code=ABCDE-FGHJK-MNPQR-STUVW)|$(grep -c 'data-stav=' "$WORK/response")" "403|0"
for i in 1 2 3 4 5 6 7 8 9; do curl -s -o /dev/null -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d code=WRONG$i; done
expect "whistleblowing: after ten wrong codes the address waits an hour, even with the right code" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_report/follow" -d "number=$WB_NUMBER" --data-urlencode "code=$WB_CODE")|$(sq "SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE number = '$WB_NUMBER'")" "429|1"
# 3.3.2 (N35): a wrong code leaves only a short keyed hash of the address with a daily salt, never the old unkeyed sha256
expect "3.3.2 whistleblowing: a wrong code is recorded as a short keyed bucket, not as a hash of the address" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MIN(ip_adresa LIKE 'wb:%'), '|', MAX(LENGTH(ip_adresa)), '|', SUM(ip_adresa = LEFT(SHA2('kaleta|127.0.0.1', 256), 40))) FROM ka_kontrola_ip WHERE typ = 'oznameni'")" "10|1|8|0"
sq "UPDATE ka_kontrola_ip SET cas = '$(site_time)' - INTERVAL 25 HOUR WHERE typ = 'oznameni' LIMIT 3" > /dev/null
curl -s -o /dev/null -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d code=WRONG10
expect "3.3.2 whistleblowing: rows older than a day are forgotten on the next write" "$(sq "SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'oznameni' AND cas < '$(site_time)' - INTERVAL 1 DAY")" "0"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni'" > /dev/null # an hour has passed
# 3.3.2 (N28): five reports a day from one address bucket, then a kind "try again later" that keeps the text
for i in 2 3 4 5; do curl -s -o /dev/null -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Report number $i" -F name= -F contact=; done
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=The sixth report today" -F name= -F contact=)
expect "3.3.2 whistleblowing: the sixth report of the day from one address waits, kindly, with the text kept; the sent rows carry the day only" \
  "$WB_HTTP|$(grep -c 'Další oznámení teď nemůžeme přijmout' "$WORK/response")|$(grep -c 'The sixth report today' "$WORK/response")|$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_whistleblowing_cases), '|', (SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'oznameni-den' AND TIME(cas) = '00:00:00' AND ip_adresa LIKE 'wb:%'))")" "429|1|1|5|5"
# the site-wide hourly cap: twenty reports in the last hour from anywhere – stamped with the time the site itself wrote for the
# last report (the site's time zone), not MySQL's NOW(), which runs in UTC on CI
WB_LAST=$(sq "SELECT MAX(created_at) FROM ka_whistleblowing_cases")
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den';
  INSERT INTO ka_whistleblowing_cases (number, created_at, status, feedback_due, text, code_hash) SELECT CONCAT('1999-', LPAD(seq, 4, '0')), '$WB_LAST', 'received', '$WB_LAST' + INTERVAL 3 MONTH, 'x', REPEAT('b', 64)
  FROM (SELECT 1 seq UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15) s" > /dev/null
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Over the hourly cap" -F name= -F contact=)
WB_FLOOD=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
# 3.3.3 (N57): over the hourly cap of the channel a genuine reporter is no longer refused – the case is accepted and marked for the readers
expect "3.3.3 whistleblowing: over the hourly cap of the channel the report is accepted and marked as received during a flood" \
  "$WB_HTTP|$(grep -c 'ka-oznameni-kod' "$WORK/response")|$(sq "SELECT CONCAT(flood, '|', (SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE flood = 1)) FROM ka_whistleblowing_cases WHERE number = '$WB_FLOOD'")" "200|1|1|1"
check "3.3.3 whistleblowing: the list marks the case received during a flood" 200 "/admin.php?module=whistleblowing" "přijato během náporu"
sq "DELETE FROM ka_whistleblowing_cases WHERE number = '$WB_FLOOD'; DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'; UPDATE ka_whistleblowing_cases SET created_at = '$(site_time)' - INTERVAL 2 HOUR WHERE number LIKE '1999-%'" > /dev/null
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Under the hourly cap again" -F name= -F contact=)
WB_CALM=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
expect "3.3.3 whistleblowing: below the hourly cap a report carries no flood mark" "$WB_HTTP|$(sq "SELECT flood FROM ka_whistleblowing_cases WHERE number = '$WB_CALM'")" "200|0"
sq "DELETE FROM ka_whistleblowing_cases WHERE number = '$WB_CALM'; DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'" > /dev/null
# the storage cap for attachments: above it the report goes through only without new attachments
php -r '$f = fopen($argv[1], "w"); ftruncate($f, 1100 * 1048576); fclose($f);' "$WORK/web/storage/oznameni/$(date +%Y)/full.bin"
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=With a file over the storage cap" -F name= -F contact= -F "files[]=@$WORK/cv.pdf")
WB_HTTP2=$(curl -s -o "$WORK/response2" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Without a file over the storage cap" -F name= -F contact=)
WB_NOFILE=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
# 3.3.3 (N57): as the docblock promises – the text goes through without the attachment, and the reporter is told so
expect "3.3.3 whistleblowing: over the attachment storage cap the report is accepted without its attachment, and the reporter is told kindly" \
  "$WB_HTTP|$(grep -c 'Přílohy teď nemůžeme uložit' "$WORK/response")|$(grep -c 'ka-oznameni-kod' "$WORK/response")|$(sq "SELECT attachments IS NULL FROM ka_whistleblowing_cases WHERE number = '$WB_NOFILE'")|$WB_HTTP2|$(grep -c 'ka-oznameni-kod' "$WORK/response2")" "200|1|1|1|200|1"
rm -f "$WORK/web/storage/oznameni/$(date +%Y)/full.bin"
sq "DELETE FROM ka_whistleblowing_cases WHERE number <> '$WB_NUMBER'; DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'" > /dev/null
WB_ID=$(sq "SELECT id FROM ka_whistleblowing_cases WHERE number = '$WB_NUMBER'")
check "whistleblowing: the reader opens the case with the decrypted report and the reporter's message" 200 "/admin.php?module=whistleblowing&action=detail&id=$WB_ID" "falšuje evidenci docházky"
grep -q 'pondělí' "$WORK/response" && grep -q 'action=attachment' "$WORK/response" && echo "  ok     whistleblowing: the detail lists the message and the attachment" || { echo "  CHYBA  whistleblowing detail"; ERRORS=$((ERRORS+1)); }
expect "whistleblowing: the reader downloads the attachment" "$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=attachment&id=$WB_ID&index=0")|$(head -c 8 "$WORK/response")" "200|%PDF-1.4"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=whistleblowing&action=reply" -d "_csrf=$(wb_csrf)" -d "id=$WB_ID" --data-urlencode "text=Děkujeme, prošetřujeme."
expect "whistleblowing: the handler's first answer acknowledges the receipt" "$(sq "SELECT CONCAT(status, '|', acknowledged_at IS NOT NULL, '|', (SELECT COUNT(*) FROM ka_whistleblowing_messages WHERE sender = 'handler' AND text NOT LIKE '%prošetřujeme%')) FROM ka_whistleblowing_cases WHERE id = $WB_ID")" "acknowledged|1|1"
curl -s -o "$WORK/response" -X POST "$B/_report/follow" -d "number=$WB_NUMBER" --data-urlencode "code=$WB_CODE"
grep -q 'data-stav="acknowledged"' "$WORK/response" && grep -q 'prošetřujeme' "$WORK/response" && echo "  ok     whistleblowing: the reporter sees the new status and the handler's answer" || { echo "  CHYBA  whistleblowing reporter view"; ERRORS=$((ERRORS+1)); }
# another administrator is not a reader: the list with numbers and dates, no detail
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(wb_csrf)" -d idu=0 -d jmeno=Druhy -d user=druhy-spravce --data-urlencode "password=$PASSWORD" -d admin=2
JAR_WB="$WORK/cookies-wb.txt"; curl -s -c "$JAR_WB" -o "$WORK/response" "$B/admin.php"
curl -s -b "$JAR_WB" -c "$JAR_WB" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=druhy-spravce --data-urlencode "password=$PASSWORD"
WB_LIST=$(curl -s -b "$JAR_WB" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=whistleblowing"); WB_SEEN=$(grep -c "$WB_NUMBER" "$WORK/response" || true); WB_LINK=$(grep -c "action=detail&amp;id=$WB_ID" "$WORK/response" || true)
WB_DETAIL=$(curl -s -b "$JAR_WB" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=detail&id=$WB_ID")
expect "whistleblowing: another administrator sees the case number in the list without a link and cannot open the case" "$WB_LIST|$([ "$WB_SEEN" -ge 1 ] && echo 1 || echo 0)|$WB_LINK|$WB_DETAIL|$(grep -c 'docházky' "$WORK/response")" "200|1|0|403|0"
mcp_list | contains -q 'whistleblowing' && { echo "  CHYBA  MCP: a whistleblowing tool is listed"; ERRORS=$((ERRORS+1)); } || echo "  ok     MCP: tools/list has no whistleblowing tool"
mcp site_info '{}' > "$WORK/response"; expect "MCP: site_info says only that the channel is on" "$(mcp_value whistleblowing)" "1"
# the daily job: a reminder of an overdue acknowledgement, a closed case past the retention deleted
sq "UPDATE ka_whistleblowing_cases SET created_at = '$(site_time)' - INTERVAL 8 DAY, acknowledged_at = NULL, status = 'received' WHERE id = $WB_ID;
  INSERT INTO ka_whistleblowing_cases (number, created_at, status, acknowledged_at, feedback_due, closed_at, text, contact, attachments, code_hash) VALUES ('2023-0001', '$(site_time)' - INTERVAL 30 MONTH, 'closed', '$(site_time)' - INTERVAL 30 MONTH, '$(site_time)' - INTERVAL 27 MONTH, '$(site_time)' - INTERVAL 25 MONTH, 'x', NULL, NULL, REPEAT('a', 64));
  UPDATE ka_jobs SET last_run = NULL WHERE name = 'whistleblowing'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "whistleblowing: the daily job records the overdue acknowledgement and deletes the closed case past the retention" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'whistleblowing.due' AND data LIKE '%\"deadline\":\"acknowledgement\"%' AND message LIKE '%$WB_NUMBER%'), '|', (SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE number = '2023-0001'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'whistleblowing.purged'))")" "1|0|1"
check "whistleblowing: the list highlights the overdue acknowledgement" 200 "/admin.php?module=whistleblowing" "po lhůtě"
# 3.2: the feature switched off closes the channel even with its own switch on; the cases stay
sq "UPDATE ka_nastaveni SET hodnota = '$WB_EXT' WHERE promenna = 'extensions'" > /dev/null
check "3.2 whistleblowing off: the public address is a 404 although the channel is set up" 404 "/_report"
check "3.2 whistleblowing off: the admin module is gone" 403 "/admin.php?module=whistleblowing"
mcp site_info '{}' > "$WORK/response"; expect "3.2 whistleblowing off: MCP site_info no longer says the channel is on; the cases stay" "$([ "$(mcp_value whistleblowing)" = 1 ] && echo on || echo off)|$(sq "SELECT COUNT(*) > 0 FROM ka_whistleblowing_cases")" "off|1"
kill "$SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
echo "== 2.13: Search Console and Bing data in Statistics (Core\\SearchData)"
connect_fake google
check "search: a connected Google offers to load the Search Console properties; Bing is listed with its key field" 200 "/admin.php?module=connectors" "action=properties"
grep -q 'Bing Webmaster Tools' "$WORK/response" && grep -q 'name="config\[search_console_site\]"' "$WORK/response" && echo "  ok     search: the property setting and the Bing service are on the screen" || { echo "  CHYBA  connections screen: search settings"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=properties" -d "_csrf=$(csrf)"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
grep -q 'name="site" value="sc-domain:example.com"' "$WORK/response" && grep -q 'name="site" value="https://example.com/"' "$WORK/response" && echo "  ok     search: the account's properties are offered once" || { echo "  CHYBA  Load my properties"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=property" -d "_csrf=$(csrf)" -d "site=sc-domain:example.com"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=property" -d "_csrf=$(csrf)" -d "site=javascript:alert(1)"
expect "search: the chosen property is kept in the connection's settings, a made-up one is refused" "$(sq "SELECT config FROM ka_connectors WHERE service = 'google'")" '{"search_console_site":"sc-domain:example.com"}'
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
! grep -q 'name="site" value=' "$WORK/response" && echo "  ok     search: the loaded list is shown only once" || { echo "  CHYBA  properties shown again"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=bing -d client_id= -d account= --data-urlencode secret=bing-test-key --data-urlencode "config[site_url]=https://example.com/"
expect "search: Bing is connected with its key stored encrypted and its site in the settings" "$(sq "SELECT CONCAT(connected_at IS NOT NULL, '|', secret LIKE '%bing-test-key%', '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.site_url'))) FROM ka_connectors WHERE service = 'bing'")" '1|0|https://example.com/'
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'search_data'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'search_data: google 5, bing 2' "$WORK/tasks.txt" && echo "  ok     search: the daily job loaded 2 queries, 2 pages and a sitemap from Google and 1 query and 1 page from Bing" || { echo "  CHYBA  search_data job"; grep search_data "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "search: the snapshot of today – Google's CTR in per cent, Bing's days summed with the position weighted by impressions, the stale day dropped, the sitemap counts" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(engine, ':', kind, ':', \`key\`, ':', clicks, ':', impressions, ':', ctr, ':', position) ORDER BY engine, kind, clicks DESC SEPARATOR ' ') FROM ka_search_stats WHERE day = '$(site_date today)'")" \
  "bing:page:https://example.com/kontakt:4:50:8.00:5.0 bing:query:kaleta bing:6:120:5.00:5.0 google:page:https://example.com/sluzby:31:640:4.84:6.2 google:page:https://example.com/:12:200:6.00:2.1 google:query:kaleta cms:42:900:4.67:3.4 google:query:firemní web zdarma:7:310:2.26:11.8 google:sitemap:https://example.com/sitemap.xml:10:15:66.67:0.0"
grep -q '"site":"sc-domain:example.com","dimension":"query"' "$FAKE_LOGS-search.log" && grep -q '"limit":250' "$FAKE_LOGS-search.log" && grep -q '"bing":"GetPageStats","site":"https://example.com/","has_key":true' "$FAKE_LOGS-search.log" \
  && echo "  ok     search: Google was asked for the chosen property with 250 rows per dimension, Bing for the registered site with the key" || { echo "  CHYBA  what the fake services were asked"; cat "$FAKE_LOGS-search.log"; ERRORS=$((ERRORS+1)); }
expect "search: the calls are logged by their action, and the Bing key is in no log row" "$(sq "SELECT CONCAT(GROUP_CONCAT(DISTINCT action ORDER BY action), '|', SUM(action LIKE '%bing-test-key%' OR error LIKE '%bing-test-key%')) FROM ka_connector_log WHERE action LIKE 'search.%'")" "search.page,search.query,search.sitemaps,search.sites|0"
check "search: Statistics show the queries and pages of both engines with the sitemap coverage" 200 "/admin.php?module=stats&days=7" "kaleta cms"
grep -q '<td>kaleta bing</td><td class="cislo">6</td><td class="cislo">120</td><td class="cislo">5,0 %</td><td class="cislo">5,0</td>' "$WORK/response" && grep -q 'href="https://example.com/sluzby"' "$WORK/response" && grep -q '<td>https://example.com/sitemap.xml</td><td class="cislo">15</td><td class="cislo">10</td>' "$WORK/response" \
  && grep -q 'Nejčastější dotazy (Google)' "$WORK/response" && ! grep -q 'bing-test-key' "$WORK/response" && echo "  ok     search: the Statistics tables and never the Bing key" || { echo "  CHYBA  Statistics: search section"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"; ! grep -q 'bing-test-key' "$WORK/response" && echo "  ok     search: the Bing key is not on the Connections screen" || { echo "  CHYBA  Bing key on the page"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"search":{"google":{"day":"'"$(site_date today)"'","covers_days":28,"queries":\[{"query":"kaleta cms","clicks":42,"impressions":900,"ctr":4.67,"position":3.4}' "$WORK/text" && contains -q '"sitemaps":\[{"path":"https://example.com/sitemap.xml","submitted":15,"indexed":10}\]' "$WORK/text" \
  && contains -q '"bing":{"day":"'"$(site_date today)"'","covers_days":28,"queries":\[{"query":"kaleta bing","clicks":6' "$WORK/text" && ! contains -q 'bing-test-key' "$WORK/text" && echo "  ok     search: get_stats carries search.google and search.bing" || { echo "  CHYBA  get_stats search"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp list_connectors '{}' > "$WORK/response"; mcp_text
contains -q '"service":"bing","name":"Bing Webmaster Tools","auth":"token","connected":true' "$WORK/text" && ! contains -q 'bing-test-key' "$WORK/text" && echo "  ok     search: Claude sees Bing connected, never the key" || { echo "  CHYBA  list_connectors bing"; ERRORS=$((ERRORS+1)); }
# the monthly report names the top queries of the month's last snapshot
sq "INSERT INTO ka_search_stats (day, engine, kind, \`key\`, clicks, impressions, ctr, position) VALUES ('$(php -r 'echo (new DateTimeImmutable("last day of last month"))->format("Y-m-d");')', 'google', 'query', 'kaleta minulý měsíc', 15, 300, 5, 4.0)" > /dev/null
check "search: the monthly report mentions the top queries when the month has a snapshot" 200 "/admin.php?module=settings&action=report_preview" "kaleta minulý měsíc na Google"
grep -q 'Hledání, která přivedla návštěvníky' "$WORK/response" && ! grep -q 'kaleta bing' "$WORK/response" && echo "  ok     search: only the queries of that month" || { echo "  CHYBA  monthly report: searches"; ERRORS=$((ERRORS+1)); }
# a wrong key: the job reports it on the connection and does not throw while Google still works; the day's earlier Bing snapshot stays
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=bing -d client_id= -d account= --data-urlencode secret=wrong-key --data-urlencode "config[site_url]=https://example.com/"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'search_data'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "search: a refused Bing key is reported on the connection, Google's snapshot still arrives, the job does not fail" "$(grep -q 'search_data: google 5, bing: HTTP 401' "$WORK/tasks.txt" && echo job-ran)|$(sq "SELECT CONCAT(last_error LIKE '%401%', '|', (SELECT COUNT(*) FROM ka_search_stats WHERE engine = 'bing' AND day = '$(site_date today)'), '|', (SELECT failures FROM ka_jobs WHERE name = 'search_data')) FROM ka_connectors WHERE service = 'bing'")" "job-ran|1|2|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"; ! grep -q 'wrong-key' "$WORK/response" && grep -q 'HTTP 401' "$WORK/response" && echo "  ok     search: Connections shows the refused key as the connection's error, never the key" || { echo "  CHYBA  Connections: Bing error"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=bing
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=google
expect "search: both engines disconnected again, the stored key gone" "$(sq "SELECT GROUP_CONCAT(CONCAT(service, ':', connected_at IS NULL, ':', secret IS NULL) ORDER BY service) FROM ka_connectors")" "bing:1:1,google:1:0"
echo "== 2.13: social post drafts (Core\\SocialDrafts) – a person posts them, the site never does"
mcp create_news "{\"title\":\"Nová hala pro výrobu\",\"intro\":\"<p>Otevřeli jsme novou výrobní halu &amp; sklad.</p>\",\"category\":\"$CATEGORY\",\"tags\":\"nová hala F14, výroba F14, CNC stroje F14, čtvrtý F14\",\"image\":\"media/foto.jpg\",\"publish\":true}" > "$WORK/response"; SOC_NEWS=$(mcp_value id)
mcp get_social_drafts "{\"id\":$SOC_NEWS}" > "$WORK/response"
expect "social drafts: a news item published through Claude has a draft for Facebook and LinkedIn (the default), none for X" "$(mcp_value drafts 0 network)|$(mcp_value drafts 1 network)|$(mcp_value drafts 2)|$(mcp_value published)" "facebook|linkedin|null|1"
expect "social drafts: the tracked link (the statistics count utm campaigns) and the news image" "$(mcp_value drafts 0 link)|$(mcp_value drafts 1 link)|$(mcp_value drafts 0 image)" \
  "$B/novinky/nova-hala-pro-vyrobu?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu|$B/novinky/nova-hala-pro-vyrobu?utm_source=linkedin&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu|$B/media/foto.jpg"
mcp_value drafts 0 text > "$WORK/draft.txt"
grep -q '^Nová hala pro výrobu$' "$WORK/draft.txt" && grep -q '^Otevřeli jsme novou výrobní halu & sklad\.$' "$WORK/draft.txt" && grep -q '^#NovaHalaF14 #VyrobaF14 #CncStrojeF14$' "$WORK/draft.txt" && tail -1 "$WORK/draft.txt" | grep -q 'utm_source=facebook' \
  && echo "  ok     social drafts: the text is the title, the lead as plain text, three hashtags from the tags and the link" || { echo "  CHYBA  Facebook draft text"; cat "$WORK/draft.txt"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null -A 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Social' "$(mcp_value drafts 0 link)" # a user agent not seen today = a new visitor
expect "social drafts: a visit through the tracked link counts in the campaign statistics" "$(sq "SELECT COALESCE(SUM(navstevy), 0) > 0 FROM ka_stat_kampane WHERE kampan LIKE 'facebook / social / nova-hala-pro-vyrobu%'")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=edit&id=$SOC_NEWS"
grep -q 'id="social-posts"' "$WORK/response" && [ "$(grep -o 'data-kopirovat="#social-text-[0-9]*"' "$WORK/response" | wc -l | tr -d ' ')" = 2 ] && grep -q 'action=social_posted' "$WORK/response" && ! grep -q 'action=social_suggest' "$WORK/response" \
  && echo "  ok     social drafts: the editor shows the panel with a Copy button per draft and Mark as posted; no assistant button while the assistant is off" || { echo "  CHYBA  social posts panel"; ERRORS=$((ERRORS+1)); }
SOC_FB=$(sq "SELECT id FROM ka_social_drafts WHERE idc = $SOC_NEWS AND network = 'facebook'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=social_save" -d "_csrf=$(csrf)" -d "id=$SOC_FB" --data-urlencode "text=Upravený text <b>bez HTML</b> $B/novinky/nova-hala-pro-vyrobu?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala-pro-vyrobu"
expect "social drafts: a draft edited in the admin before copying (HTML stripped)" "$(sq "SELECT text LIKE 'Upravený text bez HTML http%' FROM ka_social_drafts WHERE id = $SOC_FB")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=social_posted" -d "_csrf=$(csrf)" -d "id=$SOC_FB" -d posted=1
expect "social drafts: marked as posted" "$(sq "SELECT copied_at IS NOT NULL FROM ka_social_drafts WHERE id = $SOC_FB")" "1"
check "social drafts: the news list links the drafts still waiting to be posted" 200 "/admin.php?module=news" "id=$SOC_NEWS#social-posts\"[^>]*>[^<]* (1)</a>"
SOC_LI=$(sq "SELECT id FROM ka_social_drafts WHERE idc = $SOC_NEWS AND network = 'linkedin'")
mcp update_social_draft "{\"id\":$SOC_LI,\"text\":\"Text od Clauda\"}" > "$WORK/response"
expect "social drafts: Claude polishes a draft with update_social_draft" "$(mcp_value draft text)|$(sq "SELECT text FROM ka_social_drafts WHERE id = $SOC_LI")" "Text od Clauda|Text od Clauda"
mcp create_news "{\"title\":\"Koncept bez příspěvků\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; SOC_DRAFT_NEWS=$(mcp_value id)
mcp get_social_drafts "{\"id\":$SOC_DRAFT_NEWS}" > "$WORK/response"
expect "social drafts: an unpublished news item has none" "$(mcp_value published)|$(mcp_value drafts)|$(sq "SELECT COUNT(*) FROM ka_social_drafts WHERE idc = $SOC_DRAFT_NEWS")" "|[]|0"
sq "INSERT INTO ka_nastaveni VALUES ('social_networks', 'facebook,linkedin,x,instagram') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$(csrf)" -d idc=0 --data-urlencode "titulek=Dlouhá novinka pro X" -d "tema=$(sq "SELECT idt FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1")" -d "autor=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")" -d stav=vydany \
  --data-urlencode "uvod=<p>$(printf 'Otevřeli jsme novou výrobní halu s moderními stroji. %.0s' $(seq 1 12))</p>" --data-urlencode "stitky=hala F14, stroje F14"
SOC_X_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Dlouhá novinka pro X'")
expect "social drafts: publishing in the admin prepares a draft for each of the four chosen networks" "$(sq "SELECT GROUP_CONCAT(network ORDER BY network) FROM ka_social_drafts WHERE idc = $SOC_X_NEWS")" "facebook,instagram,linkedin,x"
mcp get_social_drafts "{\"id\":$SOC_X_NEWS}" > "$WORK/response"
X_LEN=$(mcp_value drafts 2 text | php -r 'echo mb_strlen(preg_replace("#https?://\S+#", str_repeat("x", 23), trim(stream_get_contents(STDIN))));')
[ "$X_LEN" -le 280 ] && [ "$X_LEN" -gt 240 ] && mcp_value drafts 2 text | grep -q '#HalaF14 #StrojeF14' && mcp_value drafts 2 text | tail -1 | grep -q "utm_source=x&utm_medium=social&utm_campaign=dlouha-novinka-pro-x$" \
  && echo "  ok     social drafts: the X draft fits 280 characters with the link counted as 23 ($X_LEN), hashtags and link whole" || { echo "  CHYBA  X draft ($X_LEN)"; mcp_value drafts 2 text; ERRORS=$((ERRORS+1)); }
mcp_value drafts 3 text > "$WORK/draft.txt"
! grep -q 'http' "$WORK/draft.txt" && grep -q 'Odkaz v biu\|Link in bio' "$WORK/draft.txt" && [ "$(mcp_value drafts 3 link)" = "$B/novinky/dlouha-novinka-pro-x?utm_source=instagram&utm_medium=social&utm_campaign=dlouha-novinka-pro-x" ] \
  && echo "  ok     social drafts: Instagram has no link in the text (link in bio), the tracked link waits for the bio" || { echo "  CHYBA  Instagram draft"; cat "$WORK/draft.txt"; ERRORS=$((ERRORS+1)); }
case "$(mcp_value drafts 0 image)" in "$B"/og/[a-f0-9]*.png) echo "  ok     social drafts: a news item without an image gets the picture the site draws (2.12)";; *) echo "  CHYBA  social drafts image without a news image: „$(mcp_value drafts 0 image)“"; ERRORS=$((ERRORS+1));; esac
mcp update_social_draft "{\"id\":$(sq "SELECT id FROM ka_social_drafts WHERE idc = $SOC_X_NEWS AND network = 'x'"),\"text\":\"$(printf 'a%.0s' $(seq 1 281))\"}" | contains -q 'X allows 280' && echo "  ok     social drafts: Claude cannot make an X draft longer than 280" || { echo "  CHYBA  update_social_draft X limit"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | contains -q '"name":"update_social_draft"' && echo "  ok     social drafts: the tools are listed" || { echo "  CHYBA  social tools missing in tools/list"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_nastaveni WHERE promenna = 'social_networks'" > /dev/null
echo "== 2.13: Google Business Profile sync and customer reviews from Google"
GBP_LOG="$FAKE_LOGS-google-business.log"; rm -f "$GBP_LOG" "$FAKE_LOGS-google-fewer"
# gbp_sent <key>: the last logged request of a kind (patch | post) as JSON, for the checks of what went to Google
gbp_sent() { php -r 'foreach (array_reverse(file($argv[1], FILE_IGNORE_NEW_LINES)) as $l) { $e = json_decode($l, true); if (isset($e[$argv[2]])) { echo json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; } }' "$GBP_LOG" "$1" 2>/dev/null; }
GBP_HOURS_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'")
connect_fake google
check "GBP: the Connections screen has the Business Profile section with the location select and the news opt-in" 200 "/admin.php?module=connectors" 'name="config\[location\]"'
grep -q 'name="config\[post_news\]"' "$WORK/response" && grep -q 'action=gbp_locations' "$WORK/response" && ! grep -q 'action=gbp_sync' "$WORK/response" && echo "  ok     GBP: Load my locations is offered, Sync now only once a location is chosen" || { echo "  CHYBA  GBP section"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=gbp_locations" -d "_csrf=$(csrf)"
check "GBP: Load my locations lists the account's locations from Google" 200 "/admin.php?module=connectors" '<option value="accounts/100/locations/2001">Test Company – Prague</option>'
grep -q 'Test Company – Brno' "$WORK/response" && grep -q '"readMask":"name,title"' "$GBP_LOG" && echo "  ok     GBP: both locations, asked for with a read mask" || { echo "  CHYBA  locations"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=google -d client_id=test-client -d secret= --data-urlencode "config[location]=accounts/100/locations/2001" -d "config[post_news]=1"
expect "GBP: the chosen location and the opt-in are stored in the connection's config, the secret stays" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(config, '$.location')), '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.post_news')), '|', secret IS NOT NULL) FROM ka_connectors WHERE service = 'google'")" "accounts/100/locations/2001|1|1"
check "GBP: the chosen location is selected and Sync now is offered" 200 "/admin.php?module=connectors" '<option value="accounts/100/locations/2001" selected>'
# the hours: saving Settings → Company queues one gbp.hours delivery (however many saves), an exception too; the job delivers the PATCH
sq "DELETE FROM ka_connector_queue" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=company -d company_type=LocalBusiness --data-urlencode "company_hours=Po-Pá 8:00-17:00"
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Inventura GBP\",\"notice_days\":0}" > /dev/null
expect "GBP: saving the company hours and an exception queue one gbp.hours delivery" "$(sq "SELECT CONCAT(COUNT(*), '|', MIN(action)) FROM ka_connector_queue WHERE next_attempt IS NOT NULL")" "1|gbp.hours"
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('gbp', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null # the daily job ran (not connected) at the first /ulohy of this run – due again now
# the visit trigger of background jobs (at most once a minute) also runs after this cron call when the last one is older than
# a minute, and its connectors pass would deliver the hours the gbp job just queued a second time (2|2 on a slow runner):
# mark it as just run, so only the cron pass delivers
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('notification_check', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
rm -f "$GBP_LOG"; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
GBP_PATCH=$(gbp_sent patch)
GBP_TOMORROW=$(php -r '$d = new DateTimeImmutable($argv[1]); echo json_encode(["year" => (int) $d->format("Y"), "month" => (int) $d->format("n"), "day" => (int) $d->format("j")]);' "$TOMORROW")
printf %s "$GBP_PATCH" | grep -q '"updateMask":"regularHours,specialHours"' && [ "$(printf %s "$GBP_PATCH" | grep -o '"openDay":"[A-Z]*"' | sort | tr '\n' ' ')" = '"openDay":"FRIDAY" "openDay":"MONDAY" "openDay":"THURSDAY" "openDay":"TUESDAY" "openDay":"WEDNESDAY" ' ] \
  && printf %s "$GBP_PATCH" | grep -q '"openTime":{"hours":8,"minutes":0},"closeDay":"MONDAY","closeTime":{"hours":17,"minutes":0}' && printf %s "$GBP_PATCH" | grep -qF "{\"startDate\":$GBP_TOMORROW,\"endDate\":$GBP_TOMORROW,\"closed\":true}" \
  && echo "  ok     GBP: the job PATCHes the location – Mo–Fr 8–17 as regularHours, tomorrow closed as specialHours, with the update mask" || { echo "  CHYBA  GBP hours PATCH"; printf '%s\n' "$GBP_PATCH" | head -c 600; cat "$WORK/tasks.txt" | head -5; ERRORS=$((ERRORS+1)); }
expect "GBP: the delivery is done and logged as gbp.hours, never with its content" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_connector_queue WHERE action = 'gbp.hours' AND delivered_at IS NOT NULL), '|', (SELECT COUNT(*) FROM ka_connector_log WHERE action = 'gbp.hours' AND ok = 1))")" "1|1"
# the daily job ran in the same /ulohy: the reviews came in with the profile's rating, the reviewer's name without tags
expect "GBP: the daily job stores the fake reviews with the rating and the count of the whole profile" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_google_reviews), '|', (SELECT author FROM ka_google_reviews WHERE review_id = 'rev-b'), '|', (SELECT reply FROM ka_google_reviews WHERE review_id = 'rev-a'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_rating'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_reviews'))")" "3|Petr N.|Thank you, Alena!|4.3|27"
grep -q "gbp: hours queued, reviews 3" "$WORK/tasks.txt" && echo "  ok     GBP: the scheduler reports the job" || { echo "  CHYBA  gbp job result"; grep gbp "$WORK/tasks.txt" || true; ERRORS=$((ERRORS+1)); }
check "GBP: the Connections screen shows the fetched rating" 200 "/admin.php?module=connectors" 'Hodnocení na Google 4,3 z 5 z 27 recenzí'
# a published news item becomes a post with the LEARN_MORE button to its address
mcp create_news "{\"title\":\"Nová hala GBP\",\"category\":\"$CATEGORY\",\"publish\":true,\"intro\":\"<p>Otevřeli jsme <b>novou</b> halu.</p>\",\"image\":\"media/hala.jpg\"}" > /dev/null
GBP_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Nová hala GBP'")
curl -s -o /dev/null "$B/ulohy?token=testtoken123"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
GBP_POST=$(gbp_sent post)
printf %s "$GBP_POST" | grep -qF '"summary":"Nová hala GBP\n\nOtevřeli jsme novou halu."' && printf %s "$GBP_POST" | grep -q '"topicType":"STANDARD"' && printf %s "$GBP_POST" | grep -q "\"callToAction\":{\"actionType\":\"LEARN_MORE\",\"url\":\"$B/novinky/nova-hala-gbp\"}" \
  && printf %s "$GBP_POST" | grep -q "\"media\":\[{\"mediaFormat\":\"PHOTO\",\"sourceUrl\":\"$B/media/hala.jpg\"}\]" && echo "  ok     GBP: the published news item went out as a STANDARD post with the plain summary, the image and a Learn more button" || { echo "  CHYBA  GBP post"; printf '%s\n' "$GBP_POST" | head -c 600; ERRORS=$((ERRORS+1)); }
expect "GBP: the post was delivered through the queue once" "$(sq "SELECT COUNT(*) FROM ka_connector_queue WHERE action = 'gbp.post' AND delivered_at IS NOT NULL")" "1"
# the Google reviews element: the newest reviews with at least 4 stars, the summary, the link, AggregateRating
mcp builder_schema '{}' > "$WORK/response"
mcp_value elements google_reviews | grep -q 'Google reviews.*count:number=3; min_stars:number=4; summary:boolean' && echo "  ok     MCP: builder_schema lists google_reviews with its English fields" || { echo "  CHYBA  builder_schema google_reviews"; mcp_value elements google_reviews | head -c 300 || true; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Recenze GBP","slug":"recenze-gbp","visible":true}' > /dev/null; GBP_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'recenze-gbp'")
mcp save_build "{\"id\":$GBP_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Recenze\"}},{\"type\":\"google_reviews\",\"content\":{\"count\":5,\"min_stars\":4,\"summary\":true,\"link\":\"https://maps.google.com/?cid=1\"}},{\"type\":\"text\",\"content\":{\"html\":\"<p>Hodnocení {{fact.google_rating}} z {{fact.google_reviews}}</p>\"}}]}]}}" > "$WORK/response"
expect "GBP: the build is stored with the Czech element type" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[1].typ')) FROM ka_stranky WHERE ids = $GBP_PAGE")" "recenze_google"
rm -f "$WORK"/web/storage/cache/stranky/*.html; check "GBP: the page shows the reviews" 200 "/recenze-gbp" '<li class="ka-recenze"><header><strong>Alena K.</strong>'
grep -q '<strong>Petr N.</strong>' "$WORK/response" && ! grep -q 'Nobody answered' "$WORK/response" && grep -q '<p>Fast and friendly.<br />' "$WORK/response" && grep -q '<p class="ka-recenze-odpoved"><strong>Odpověď firmy:</strong> Thank you, Alena!</p>' "$WORK/response" \
  && grep -q 'aria-label="Hodnocení 4,3 z 5 · Recenzí na Google: 27"' "$WORK/response" && grep -q 'href="https://maps.google.com/?cid=1" target="_blank" rel="noopener">Všechny recenze na Google</a>' "$WORK/response" \
  && echo "  ok     GBP: two reviews with 4+ stars (the 2-star one left out), the reply, the summary with stars, the link to all reviews" || { echo "  CHYBA  GBP element"; grep -o 'ka-recenze-google.\{0,600\}' "$WORK/response" | head -c 700 || true; ERRORS=$((ERRORS+1)); }
grep -q '"aggregateRating":{"@type":"AggregateRating","ratingValue":4.3,"reviewCount":27,"bestRating":5,"worstRating":1}' "$WORK/response" && grep -q '"review":\[{"@type":"Review","author":{"@type":"Person","name":"Alena K."},"datePublished":"2026-09-20","reviewRating":{"@type":"Rating","ratingValue":5' "$WORK/response" \
  && grep -q '"@id":"'"$B"'/#firma"' "$WORK/response" && echo "  ok     GBP: AggregateRating and the shown reviews on the company node, only from Google's data" || { echo "  CHYBA  GBP structured data"; grep -o 'ld+json.\{0,400\}' "$WORK/response" | tail -1 || true; ERRORS=$((ERRORS+1)); }
grep -q '<p>Hodnocení 4.3 z 27</p>' "$WORK/response" && echo "  ok     GBP: {{fact.google_rating}} and {{fact.google_reviews}} are built-in facts" || { echo "  CHYBA  google facts"; grep -o 'Hodnocení [^<]*' "$WORK/response" | head -2 || true; ERRORS=$((ERRORS+1)); }
# a review deleted on Google disappears with the next fetch
touch "$FAKE_LOGS-google-fewer"; sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'gbp'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
GBP_LEFT=$(sq "SELECT GROUP_CONCAT(review_id ORDER BY review_id) FROM ka_google_reviews")
[ "$GBP_LEFT" = "rev-a,rev-c" ] && echo "  ok     GBP: a review gone from Google is gone from the site" || { echo "  CHYBA  GBP: a review gone from Google is gone from the site: $GBP_LEFT"; cat "$WORK/tasks.txt"; sq "SELECT name, last_run, last_error FROM ka_jobs WHERE name = 'gbp'"; sq "SELECT created_at, action, status, error FROM ka_connector_log ORDER BY id DESC LIMIT 4"; tail -3 "$GBP_LOG" || true; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/ulohy?token=testtoken123" # the daily job queued the hours again – delivered now
curl -s -o "$WORK/response" "$B/recenze-gbp"; ! grep -q 'Petr N.' "$WORK/response" && grep -q 'Alena K.' "$WORK/response" && echo "  ok     GBP: the page no longer shows it (the cache was cleared)" || { echo "  CHYBA  deleted review still shown"; ERRORS=$((ERRORS+1)); }
# disconnecting Google deletes the reviews and the rating; the element shows nothing to visitors
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=google
expect "GBP: disconnecting Google deletes the reviews, the rating and the loaded locations" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_google_reviews), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_rating'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_locations'))")" "0||"
curl -s -o "$WORK/response" "$B/recenze-gbp"; ! grep -q 'class="ka-recenze' "$WORK/response" && ! grep -q 'AggregateRating' "$WORK/response" && grep -q '<p>Hodnocení  z </p>' "$WORK/response" && echo "  ok     GBP: without the connection the element renders nothing and the facts are empty" || { echo "  CHYBA  element after disconnect"; grep -o 'ka-recenze.\{0,200\}' "$WORK/response" | head -c 300 || true; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=business"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=company --data-urlencode "company_hours=Po-Pá 9:00-16:00"
expect "GBP: without the connection a change of the hours queues nothing" "$(sq "SELECT COUNT(*) FROM ka_connector_queue WHERE next_attempt IS NOT NULL")" "0"
mcp trash_page "{\"id\":$GBP_PAGE}" > /dev/null; sq "DELETE FROM ka_hours_exceptions WHERE note = 'Inventura GBP'; UPDATE ka_nastaveni SET hodnota = '$GBP_HOURS_BEFORE' WHERE promenna = 'company_hours'" > /dev/null; rm -f "$FAKE_LOGS-google-fewer"
echo "== 2.13: enquiries to a Google sheet and the CRM (HubSpot, Pipedrive, Raynet)"
# a page with a full form (name, e-mail, phone, message, consent) – the contact form was re-pointed by the tests above
mcp create_page '{"title":"Poptavka CRM","visible":true}' > /dev/null; PAGE_CRM=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'poptavka-crm'")
mcp stavba_uloz "{\"id\":$PAGE_CRM,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka CRM\",\"pole\":[{\"popisek\":\"Jméno a příjmení\",\"typ\":\"text\",\"povinne\":true},{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true},{\"popisek\":\"Telefon\",\"typ\":\"tel\"},{\"popisek\":\"Zpráva\",\"typ\":\"textarea\"},{\"popisek\":\"Souhlas\",\"typ\":\"souhlas\",\"povinne\":true}]}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/poptavka-crm"
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
[ -n "$FORM_ELEMENT" ] && echo "  ok     enquiries: the test form with every field type the mapping uses is on its page" || { echo "  CHYBA  test form page"; ERRORS=$((ERRORS+1)); }
# crm_submit: that form; the per-IP limit of the form tests above is cleared first
crm_submit() { sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null; submit_form -d zpet=/poptavka-crm "$@"; }
connect_fake google
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
grep -q 'name="config\[enquiries\]" value="1"' "$WORK/response" && grep -q 'action=sheet' "$WORK/response" && grep -q 'name="config\[domain\]"' "$WORK/response" && grep -q 'name="config\[instance\]"' "$WORK/response" && grep -q 'name="config\[enquiry_jobs\]"' "$WORK/response" \
  && echo "  ok     enquiries: Connections offers the switch and the job-applications tick for every destination, Create the sheet for Google, the Pipedrive domain and the Raynet instance" || { echo "  CHYBA  Connections screen"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=google -d client_id=test-client -d "config[enquiries]=1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=hubspot --data-urlencode secret=hs-token -d "config[enquiries]=1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=pipedrive --data-urlencode secret=pd-token -d "config[domain]=acme" -d "config[enquiries]=1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=raynet --data-urlencode account=user@example.cz --data-urlencode secret=rn-key -d "config[instance]=acme-crm" -d "config[enquiries]=1"
expect "enquiries: the CRMs are connected by their keys, Google by the sign-in, the switch is on everywhere, the keys are encrypted" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(service, '=', connected_at IS NOT NULL, JSON_UNQUOTE(JSON_EXTRACT(config, '\$.enquiries')), secret LIKE '%-token%' OR secret LIKE '%rn-key%') ORDER BY service SEPARATOR ',') FROM ka_connectors")" "google=110,hubspot=110,pipedrive=110,raynet=110"
check "enquiries: the switch is on but the sheet is missing – the screen says so" 200 "/admin.php?module=connectors" "Nejdřív vytvořte tabulku"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=sheet" -d "_csrf=$(csrf)"
expect "enquiries: Create the sheet made the spreadsheet with the Google sign-in and kept its id with the settings" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(config, '\$.sheet_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(config, '\$.enquiries'))) FROM ka_connectors WHERE service = 'google'")" "sheet-test-1|1"
grep -q '"call":"create"' "$FAKE_LOGS-sheets.log" && grep -q '"title":"'"$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")"' – poptávky"' "$FAKE_LOGS-sheets.log" && grep -q '"stringValue":"Datum"' "$FAKE_LOGS-sheets.log" && grep -q '"stringValue":"E-mail"' "$FAKE_LOGS-sheets.log" && grep -q '"authorization":"Bearer access-1"' "$FAKE_LOGS-sheets.log" \
  && echo "  ok     enquiries: the sheet is named after the site and has a header row; the call carried the OAuth token" || { echo "  CHYBA  sheet create"; cat "$FAKE_LOGS-sheets.log"; ERRORS=$((ERRORS+1)); }
check "enquiries: Connections links the sheet" 200 "/admin.php?module=connectors" "https://docs.google.com/spreadsheets/d/sheet-test-1"
QID0=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_connector_queue")
sleep 4
# the visit trigger of background jobs runs at most once a minute – mark it as just run, so the form's redirect visit
# cannot deliver the queue and only the cron call below does
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('notification_check', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
location=$(crm_submit --data-urlencode "p0=Karel Novák" --data-urlencode p1=karel@example.cz --data-urlencode "p2=+420 777 123 456" --data-urlencode "p3=Chci novou kuchyň." -d p4=1)
case "$location" in *result=ok*) echo "  ok     enquiries: the form was sent";; *) echo "  CHYBA  form: $location"; ERRORS=$((ERRORS+1));; esac
CRM_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky"); FORM_NAME=$(sq "SELECT formular FROM ka_poptavky WHERE idp = $CRM_IDP")
expect "enquiries: one delivery per destination waits in the queue – nothing went out while the visitor waited" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(action ORDER BY action), '|', SUM(delivered_at IS NULL), '|', SUM(payload LIKE '%\"enquiry\":$CRM_IDP,%')) FROM ka_connector_queue WHERE id > $QID0")|$([ -f "$FAKE_LOGS-crm.log" ] && grep -c karel "$FAKE_LOGS-crm.log" || echo 0)" "4|crm.lead,crm.lead,crm.lead,sheets.append|4|4|0"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "connectors: delivered 4" "$WORK/tasks.txt" && echo "  ok     enquiries: the connectors job delivered the four" || { echo "  CHYBA  connectors job"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "enquiries: delivered rows lose their payload (personal data), no error stays" "$(sq "SELECT CONCAT(SUM(delivered_at IS NOT NULL), '|', SUM(payload IS NULL), '|', SUM(last_error = '')) FROM ka_connector_queue WHERE id > $QID0")" "4|4|4"
grep -q '"call":"append","sheet":"sheet-test-1","range":"A1","query":{"valueInputOption":"RAW","insertDataOption":"INSERT_ROWS"},"authorization":"Bearer access-1"' "$FAKE_LOGS-sheets.log" \
  && grep -q '"values":\[\["[0-9-]* [0-9:]*","'"$FORM_NAME"'","[^"]*","karel@example.cz","'"$B"'/poptavka-crm","Karel Novák","+420 777 123 456","[^"]*: Chci novou kuchyň."\]\]' "$FAKE_LOGS-sheets.log" \
  && echo "  ok     enquiries: the sheet got one row – date, form, topic, e-mail, page, name, phone, then the message" || { echo "  CHYBA  sheet row"; grep append "$FAKE_LOGS-sheets.log"; ERRORS=$((ERRORS+1)); }
grep -q '"crm":"hubspot","method":"POST","path":"/crm/v3/objects/contacts/search","authorization":"Bearer hs-token".*"value":"karel@example.cz"' "$FAKE_LOGS-crm.log" \
  && grep -q '"method":"POST","path":"/crm/v3/objects/contacts",.*"properties":{"email":"karel@example.cz","firstname":"Karel","lastname":"Novák","phone":"+420 777 123 456"}' "$FAKE_LOGS-crm.log" \
  && grep -q '"path":"/crm/v3/objects/notes",.*"hs_note_body":"[^"]*Chci novou kuchyň.*"to":{"id":"777"}.*"associationTypeId":202' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: HubSpot – the contact searched by e-mail, created with name and phone, the note with the message associated to it" || { echo "  CHYBA  HubSpot calls"; grep hubspot "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
grep -q '"crm":"pipedrive","method":"GET","path":"/api/v1/persons/search","api_token":"pd-token","query":{"term":"karel@example.cz","fields":"email","exact_match":"true","limit":"1"}' "$FAKE_LOGS-crm.log" \
  && grep -q '"path":"/api/v1/persons","api_token":"pd-token".*"name":"Karel Novák","email":\[{"value":"karel@example.cz","primary":true}\],"phone":\[{"value":"+420 777 123 456","primary":true}\]' "$FAKE_LOGS-crm.log" \
  && grep -q '"path":"/api/v1/leads".*"title":"'"$FORM_NAME"'[^"]*","person_id":42' "$FAKE_LOGS-crm.log" && grep -q '"path":"/api/v1/notes".*"lead_id":"lead-uuid-1","person_id":42' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: Pipedrive – the token as api_token, the person found or created, the lead titled after the form, the note" || { echo "  CHYBA  Pipedrive calls"; grep pipedrive "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
grep -q '"crm":"raynet","method":"PUT","path":"/api/v2/lead/","user":"user@example.cz","key_ok":true,"instance":"acme-crm".*"topic":"'"$FORM_NAME"'[^"]*","firstName":"Karel","lastName":"Novák","contactInfo":{"email":"karel@example.cz","tel1":"+420 777 123 456"},"notice":"[^"]*Chci novou kuchyň' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: Raynet – HTTP Basic with the instance header, the lead with the contact and the message" || { echo "  CHYBA  Raynet call"; grep raynet "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
expect "enquiries: every CRM call is in the log by its action, never with the content" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(ok), '|', SUM(action LIKE 'crm.%'), '|', SUM(error LIKE '%karel%' OR error LIKE '%token%')) FROM ka_connector_log WHERE service IN ('hubspot', 'pipedrive', 'raynet')")" "8|8|8|0"
# a sender the CRM already knows: HubSpot updates the contact, Pipedrive reuses the person
QID1=$(sq "SELECT MAX(id) FROM ka_connector_queue")
crm_submit --data-urlencode "p0=Known Person" --data-urlencode p1=known@example.cz -d p2= -d p3=again -d p4=1 > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
grep -q '"method":"PATCH","path":"/crm/v3/objects/contacts/501"' "$FAKE_LOGS-crm.log" && grep -q '"path":"/crm/v3/objects/notes".*"to":{"id":"501"}' "$FAKE_LOGS-crm.log" \
  && [ "$(grep -c '"path":"/api/v1/persons",' "$FAKE_LOGS-crm.log")" = 1 ] && grep -q '"path":"/api/v1/leads".*"person_id":31' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: a known sender – HubSpot updates the contact and notes it, Pipedrive adds the lead to the existing person" || { echo "  CHYBA  known contact"; grep 'known\|501\|person_id' "$FAKE_LOGS-crm.log" | tail -6; ERRORS=$((ERRORS+1)); }
expect "enquiries: the known sender's deliveries went through" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(delivered_at IS NOT NULL)) FROM ka_connector_queue WHERE id > $QID1")" "4|4"
# a job application (an enquiry from a jobs collection, 2.11) is not sent unless the administrator ticks it – and then without the CV
[ -n "${JOB_ELEMENT:-}" ] || { curl -s -o "$WORK/job.html" "$B/volna-mista/truhlar"; JOB_SOURCE=$(job_field zdroj); JOB_ELEMENT=$(job_field prvek); JOB_TIME=$(job_field as_cas); JOB_SIGNATURE=$(job_field as_podpis); sleep 4; }
printf '%%PDF-1.4 test CV\n' > "$WORK/cv.pdf"
submit_application() { sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null; curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=$JOB_SOURCE" -F "prvek=$JOB_ELEMENT" -F zpet=/volna-mista/truhlar -F "as_cas=$JOB_TIME" -F "as_podpis=$JOB_SIGNATURE" \
  -F p0=Petr -F "p1=$1" -F p2= -F "p3=@$WORK/cv.pdf" --form-string "p4=Hlásím se." -F p5=1 --form-string "p6=Truhlář"; }
QID2=$(sq "SELECT MAX(id) FROM ka_connector_queue")
case "$(submit_application f13-applicant@example.cz)" in *result=ok*) echo "  ok     enquiries: an application with a CV was sent";; *) echo "  CHYBA  application"; ERRORS=$((ERRORS+1));; esac
expect "enquiries: the application is stored but goes to no CRM and no sheet by default" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE email = 'f13-applicant@example.cz'), '|', (SELECT COUNT(*) FROM ka_connector_queue WHERE id > $QID2))")" "1|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=raynet --data-urlencode account=user@example.cz -d "config[instance]=acme-crm" -d "config[enquiries]=1" -d "config[enquiry_jobs]=1"
submit_application petra@example.cz > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "enquiries: with the tick only that destination gets the application; the key saved before stays" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(action), '|', SUM(delivered_at IS NOT NULL), '|', (SELECT connected_at IS NOT NULL FROM ka_connectors WHERE service = 'raynet')) FROM ka_connector_queue WHERE id > $QID2")" "1|crm.lead|1|1"
grep -q '"crm":"raynet".*petra@example.cz.*cv.pdf' "$FAKE_LOGS-crm.log" && ! grep -q 'storage/prilohy\|[0-9]\{4\}/[0-9]\{2\}/[a-f0-9]\{24\}\.pdf' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: the application reached Raynet with the CV's name only, never the file or its path" || { echo "  CHYBA  application in the CRM"; grep petra "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
# a CRM that fails: the deliveries wait for a retry with the error, the screen shows it, the retry clears it
touch "$FAKE_LOGS-crm.fail"; QID3=$(sq "SELECT MAX(id) FROM ka_connector_queue")
crm_submit -d p0=Failing --data-urlencode p1=fail@example.cz -d p2= -d p3=x -d p4=1 > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "enquiries: the CRMs answer 500 – their deliveries wait for a retry with the error, the sheet row went through" \
  "$(sq "SELECT CONCAT(SUM(action = 'crm.lead' AND attempts = 1 AND next_attempt IS NOT NULL AND delivered_at IS NULL AND last_error LIKE 'HTTP 500%'), '|', SUM(action = 'sheets.append' AND delivered_at IS NOT NULL)) FROM ka_connector_queue WHERE id > $QID3")" "3|1"
expect "enquiries: each CRM keeps its last error for the Connections screen" "$(sq "SELECT GROUP_CONCAT(CONCAT(service, ':', last_error LIKE 'HTTP 500%') ORDER BY service) FROM ka_connectors WHERE service IN ('google', 'hubspot', 'pipedrive', 'raynet')")" "google:0,hubspot:1,pipedrive:1,raynet:1"
check "enquiries: Connections shows the error" 200 "/admin.php?module=connectors" "HTTP 500: The fake CRM is broken."
rm -f "$FAKE_LOGS-crm.fail"; sq "UPDATE ka_connector_queue SET next_attempt = '$(site_time)' - INTERVAL 1 DAY WHERE id > $QID3 AND delivered_at IS NULL" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "enquiries: the retry delivers and clears the errors" "$(sq "SELECT CONCAT(SUM(delivered_at IS NOT NULL AND last_error = ''), '|', (SELECT SUM(last_error = '') FROM ka_connectors WHERE service IN ('google', 'hubspot', 'pipedrive', 'raynet'))) FROM ka_connector_queue WHERE id > $QID3")" "4|4"
# disconnecting stops the sending
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=hubspot
QID4=$(sq "SELECT MAX(id) FROM ka_connector_queue")
crm_submit -d p0=After --data-urlencode p1=after@example.cz -d p2= -d p3=x -d p4=1 > /dev/null
expect "enquiries: a disconnected CRM gets nothing more, the others still do; disconnecting forgot its key" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(payload LIKE '%\"service\":\"hubspot\"%'), '|', (SELECT secret IS NULL FROM ka_connectors WHERE service = 'hubspot')) FROM ka_connector_queue WHERE id > $QID4")" "3|0|1"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
mcp list_connectors '{}' > "$WORK/response"
contains -q 'raynet' "$WORK/response" && contains -q 'pipedrive' "$WORK/response" && ! contains -q 'hs-token\|pd-token\|rn-key\|sheet_id' "$WORK/response" && echo "  ok     enquiries: Claude sees the CRMs' status, never a key or the settings" || { echo "  CHYBA  list_connectors with CRMs"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
echo "== 2.15: the reason of a change and guardrails for Claude"
setting() { sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null; }
mcp vytvor_stranku '{"titulek":"Guarded page","zobrazit":false}' > "$WORK/response"; mcp_text; GUARD_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp vytvor_stranku '{"titulek":"Free page","zobrazit":false}' > "$WORK/response"; mcp_text; FREE_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp update_page "{\"id\":$FREE_PAGE,\"description\":\"New description\",\"reason\":\"Request 7: the client asked for a shorter description\"}" > /dev/null
expect "reason: a write tool's reason is in the change log" "$(sq "SELECT duvod FROM ka_protokol WHERE modul = 'claude' ORDER BY idp DESC LIMIT 1")" "Request 7: the client asked for a shorter description"
mcp list_changes '{"by":"claude","limit":5}' > "$WORK/response"; mcp_text
contains -q '"reason":"Request 7' "$WORK/text" && echo "  ok     reason: list_changes returns it" || { echo "  CHYBA  list_changes reason"; ERRORS=$((ERRORS+1)); }
setting claude_protected_pages "$GUARD_PAGE"
mcp update_page "{\"id\":$GUARD_PAGE,\"description\":\"x\"}" > "$WORK/response"
contains -q 'isError' "$WORK/response" && contains -q 'protected from changes' "$WORK/response" && echo "  ok     guardrails: a protected page refuses update_page" || { echo "  CHYBA  protected update_page"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_build "{\"id\":$GUARD_PAGE,\"build\":{\"v\":1,\"children\":[]}}" > "$WORK/response"
contains -q 'protected from changes' "$WORK/response" && echo "  ok     guardrails: and its build" || { echo "  CHYBA  protected save_build"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N31): an empty other target ("popup": 0, "part": "") does not take the protection off – the tools would edit the page
mcp save_build "{\"id\":$GUARD_PAGE,\"popup\":0,\"build\":{\"v\":1,\"children\":[]}}" > "$WORK/response"
mcp save_build "{\"id\":$GUARD_PAGE,\"part\":\"\",\"build\":{\"v\":1,\"children\":[]}}" > "$WORK/response2"
contains -q 'protected from changes' "$WORK/response" && contains -q 'protected from changes' "$WORK/response2" && echo "  ok     guardrails: an empty pop-up or part does not unprotect the page" || { echo "  CHYBA  protected page with an empty other target"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$FREE_PAGE,\"description\":\"Still free\"}" > "$WORK/response"
! contains -q 'isError' "$WORK/response" && echo "  ok     guardrails: other pages stay free" || { echo "  CHYBA  free page"; ERRORS=$((ERRORS+1)); }
setting claude_protected_pages ""
setting claude_destructive 0
mcp trash_page "{\"id\":$FREE_PAGE}" > "$WORK/response"
contains -q 'switched off deleting' "$WORK/response" && [ "$(sq "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $FREE_PAGE")" = 1 ] && echo "  ok     guardrails: deleting switched off – trash_page refused, the page stays" || { echo "  CHYBA  destructive off"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N32): deleting, overwriting and sending through write tools count as destructive too
mcp save_redirect '{"from":"/n32-old","to":"/n32-new"}' > "$WORK/response"
mcp save_redirect '{"from":"/n32-old","delete":true}' > "$WORK/response2"
mcp restore_item_version '{"collection":"x","id":1,"version":1}' > "$WORK/response3"
! contains -q 'isError' "$WORK/response" && contains -q 'switched off deleting' "$WORK/response2" && contains -q 'switched off deleting' "$WORK/response3" \
  && [ "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'n32-old'")" = 1 ] && echo "  ok     guardrails: deleting a redirect and restoring an item version are refused, adding a redirect is not" || { echo "  CHYBA  destructive parameters"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
setting claude_destructive 1
mcp save_redirect '{"from":"/n32-old","delete":true}' > /dev/null
setting claude_change_limit 1
mcp update_page "{\"id\":$FREE_PAGE,\"description\":\"Over the limit\"}" > "$WORK/response"
contains -q 'reached the limit of 1 changes an hour' "$WORK/response" && echo "  ok     guardrails: the hourly limit stops a connection" || { echo "  CHYBA  hourly limit"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_page "{\"id\":$FREE_PAGE}" > "$WORK/response"
! contains -q 'isError' "$WORK/response" && echo "  ok     guardrails: reading is never limited" || { echo "  CHYBA  read limited"; ERRORS=$((ERRORS+1)); }
setting claude_change_limit 0
# 3.7: every row of a batch tool counts against the hourly limit – a batch cannot carry 200 changes past a limit of 10
LIMIT_VIA=$(sq "SELECT via FROM ka_protokol WHERE modul = 'claude' ORDER BY idp DESC LIMIT 1")
LIMIT_USED=$(claude_used "$LIMIT_VIA")
setting claude_change_limit $((LIMIT_USED + 3))
mcp save_redirects '{"redirects":[{"from":"/b37-a","to":"/x"},{"from":"/b37-b","to":"/x"},{"from":"/b37-c","to":"/x"},{"from":"/b37-d","to":"/x"}]}' > "$WORK/response"; L1=$(contains -q 'would make 4 changes' "$WORK/response" && echo refused || echo saved)
mcp save_redirects '{"redirects":[{"from":"/b37-e","to":"/x"},{"from":"/b37-f","to":"/x"}]}' > "$WORK/response"; L2=$(contains -q 'isError' "$WORK/response" && echo refused || echo saved)
mcp save_redirects '{"redirects":[{"from":"/b37-g","to":"/x"},{"from":"/b37-h","to":"/x"}]}' > "$WORK/response"; L3=$(contains -q 'would make 2 changes, but the connection has 1 left' "$WORK/response" && echo refused || echo saved)
expect "3.7 guardrails: each row of a batch counts against the hourly limit" "$L1|$L2|$L3|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'b37-%'")" "refused|saved|refused|2"
# 3.7 (N37-20): ten parallel calls of one connection against a server with eight workers – each call is counted under a
# lock before its tool runs, so with 5 changes left only two batches of 2 rows get through (it was all ten: 20 changes)
RACE2_PORT=$((PORT + 18)); RACE2="http://127.0.0.1:$RACE2_PORT"
(cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=8 exec php -S "127.0.0.1:$RACE2_PORT" system/dev-router.php > /dev/null 2>&1) & RACE2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$RACE2/" && break; sleep 0.2; done
setting claude_change_limit $(( $(claude_used "$LIMIT_VIA") + 5 ))
RACE2_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
RACERS=(); for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -m 60 -o "$WORK/race-limit-$i" -X POST "$RACE2/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"save_redirects\",\"arguments\":{\"redirects\":[{\"from\":\"/r20-$i-a\",\"to\":\"/x\"},{\"from\":\"/r20-$i-b\",\"to\":\"/x\"}]}}}" & RACERS+=($!); done; wait "${RACERS[@]}" || true
pkill -P "$RACE2_PID" 2>/dev/null || true; kill "$RACE2_PID" 2>/dev/null || true; RACE2_PID= # the workers first, they outlive their parent
RACE2_OK=0; RACE2_REFUSED=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if contains -q '"result"' "$WORK/race-limit-$i" && ! contains -q 'isError' "$WORK/race-limit-$i"; then RACE2_OK=$((RACE2_OK+1)); elif contains -q 'changes an hour\|is being checked against the hourly limit' "$WORK/race-limit-$i"; then RACE2_REFUSED=$((RACE2_REFUSED+1)); fi # a call that waited too long for the lock is refused too (fail closed)
done
expect "3.7 N37-20 guardrails: parallel calls never get past the hourly limit – accepted calls, their redirects, one change-log row each, the rest refused" \
  "$RACE2_OK|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'r20-%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $RACE2_LOG AND akce = 'save_redirects'")|$RACE2_REFUSED" "2|4|2|8"
setting claude_change_limit 0
check "guardrails: the settings are in Claude settings" 200 "/admin.php?module=claude_settings" "claude_protected_pages"
echo "== 2.15: agent notebook"
mcp site_info '{}' > "$WORK/response"; NB_BEFORE=$(mcp_value notebook_count)
mcp write_notebook '{"topic":"style","title":"Nikdy slovo levný","text":"Píšeme „výhodný“ nebo „dostupný“, nikdy „levný“ – rozhodnutí klienta z 3. 10. 2026."}' > "$WORK/response"
NB_ID=$(mcp_value note id)
expect "notebook: Claude writes a note; the author is the name of its connection" "$([ -n "$NB_ID" ] && echo id)|$(mcp_value note author)|$(mcp_value note topic)|$(mcp_value note pinned)" "id|test|style|"
mcp write_notebook '{"topic":"credits","title":"Fotky z roku 2024","text":"Fotografie v sekci Reference nafotil interní tým, bez uvedení autora."}' > "$WORK/response"; NB_ID2=$(mcp_value note id)
mcp write_notebook "{\"id\":$NB_ID2,\"pinned\":true}" > "$WORK/response"
expect "notebook: a change by id keeps the other fields and pins the note" "$(mcp_value note title)|$(mcp_value note pinned)|$(mcp_value note topic)" "Fotky z roku 2024|1|credits"
mcp read_notebook '{}' > "$WORK/response"
expect "notebook: pinned first, then the most recently changed" "$(mcp_value notes 0 id)|$(mcp_value notes 1 id)|$(mcp_value count)" "$NB_ID2|$NB_ID|$((NB_BEFORE + 2))"
mcp read_notebook '{"search":"levný"}' > "$WORK/response"
expect "notebook: search in the title and text" "$(mcp_value count)|$(mcp_value notes 0 id)" "1|$NB_ID"
mcp read_notebook '{"topic":"credits"}' > "$WORK/response"
expect "notebook: filter by topic" "$(mcp_value count)|$(mcp_value notes 0 topic)" "1|credits"
mcp write_notebook '{"topic":"pricing","title":"x","text":"y"}' > "$WORK/response"
contains -q 'must be one of' "$WORK/response" && echo "  ok     notebook: an unknown topic is refused" || { echo "  CHYBA  notebook: unknown topic"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp write_notebook '{"title":"Bez textu"}' > "$WORK/response"
contains -q 'needs a text' "$WORK/response" && echo "  ok     notebook: a note without a text is refused" || { echo "  CHYBA  notebook: empty text"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"
expect "notebook: site_info counts the notes and names the pinned ones" "$(mcp_value notebook_count)|$(mcp_value notebook_pinned 0)" "$((NB_BEFORE + 2))|Fotky z roku 2024"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | contains 'read_notebook before larger changes' && echo "  ok     notebook: the server instructions tell Claude to read the notebook and write decisions down" || { echo "  CHYBA  notebook: server instructions"; ERRORS=$((ERRORS+1)); }
check "notebook: the admin list by topic with the pinned note first" 200 "/admin.php?module=notebook" "Fotky z roku 2024"
expect "notebook: the pinned note is above the newer one in the admin" "$(grep -o 'Fotky z roku 2024\|Nikdy slovo levný' "$WORK/response" | head -1)|$(grep -c 'Nikdy slovo levný' "$WORK/response")" "Fotky z roku 2024|1"
check "notebook: search in the admin" 200 "/admin.php?module=notebook&search=levn%C3%BD" "Nikdy slovo levný"
grep -q 'Fotky z roku 2024' "$WORK/response" && { echo "  CHYBA  notebook: the search still lists the other note"; ERRORS=$((ERRORS+1)); }
check "notebook: the admin filter by topic" 200 "/admin.php?module=notebook&topic=credits" "Fotky z roku 2024"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=save" -d "_csrf=$TOKEN" -d id=0 -d topic=decisions --data-urlencode "title=Klient je citlivý na stránku O nás" --data-urlencode "text=Texty na O nás schvaluje jednatel osobně."
NB_ID3=$(sq "SELECT id FROM ka_notebook WHERE title LIKE 'Klient je citliv%'")
expect "notebook: a note from the admin carries the user's name as its author" "$(sq "SELECT CONCAT(topic, '|', author, '|', pinned) FROM ka_notebook WHERE id = $NB_ID3")" "decisions|Tester|0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=pin" -d "_csrf=$TOKEN" -d "id=$NB_ID3"
expect "notebook: one click pins the note" "$(sq "SELECT pinned FROM ka_notebook WHERE id = $NB_ID3")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=save" -d "_csrf=$TOKEN" -d "id=$NB_ID3" -d topic=decisions --data-urlencode "title=Klient je citlivý na stránku O nás" --data-urlencode "text=Texty na O nás schvaluje jednatel osobně – i drobné změny." -d pinned=1
check "notebook: the edit form shows the changed text" 200 "/admin.php?module=notebook&action=edit&id=$NB_ID3" "i drobné změny"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; NB_EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/nb-export" "$B/admin.php?module=transfer&action=download&file=$NB_EXPORT"
if [ "${NB_EXPORT##*.}" = zip ]; then unzip -p "$WORK/nb-export" obsah.json > "$WORK/nb-obsah.json" 2>/dev/null || true; else cp "$WORK/nb-export" "$WORK/nb-obsah.json"; fi
grep -q '"notebook":\[' "$WORK/nb-obsah.json" && grep -q 'Klient je citlivý na stránku O nás' "$WORK/nb-obsah.json" && echo "  ok     notebook: the site export carries the notes (the import is checked in the 1.8 move)" || { echo "  CHYBA  notebook in the export"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=notebook"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=delete" -d "_csrf=$TOKEN" -d "id=$NB_ID3"
mcp delete_notebook_entry "{\"id\":$NB_ID}" > "$WORK/response"
expect "notebook: deleted in the admin and over MCP; the change log names both" "$(sq "SELECT COUNT(*) FROM ka_notebook WHERE id IN ($NB_ID, $NB_ID3)")|$(mcp_value count)|$(sq "SELECT GROUP_CONCAT(DISTINCT via ORDER BY via) FROM ka_protokol WHERE modul = 'notebook' AND akce = 'delete'")" "0|$((NB_BEFORE + 1))|,test"
mcp delete_notebook_entry '{"id":999999}' > "$WORK/response"
contains -q 'does not exist' "$WORK/response" && echo "  ok     notebook: deleting a note that does not exist is an error" || { echo "  CHYBA  notebook: delete of a missing note"; ERRORS=$((ERRORS+1)); }
echo "== 2.15: requests to Claude (Core\\Requests) – staff ask, Claude drafts, a person publishes"
# a staff user with the Requests section only; the administrator has an address for the notification
sq "UPDATE ka_uzivatele SET email = 'spravce-f19@example.cz' WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Recepce -d user=recepce --data-urlencode email=recepce@example.cz --data-urlencode "password=$PASSWORD" -d admin=0 -d rucne=1 -d 'moduly[]=requests'
expect "requests: the staff user has the Requests section only" "$(sq "SELECT GROUP_CONCAT(p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'recepce'")" "requests"
JAR_REQ="$WORK/jar-requests"
REQ_CSRF=$(curl -s -c "$JAR_REQ" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$REQ_CSRF" -d user=recepce --data-urlencode "password=$PASSWORD"
code=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=requests&action=new"); expect "requests: the staff user opens the form" "$code" 200
grep -q 'name="prilohy\[\]"' "$WORK/response" && grep -q 'value="page:1"' "$WORK/response" && echo "  ok     requests: the form offers attachments and the pages to choose from" || { echo "  CHYBA  request form"; ERRORS=$((ERRORS+1)); }
REQ_CSRF=$(csrf)
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$WORK/cenik-f19.pdf"
REQ_URL=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=requests&action=save" -F "_csrf=$REQ_CSRF" -F "title=Nový ceník na stránku Služby" -F "text=Prosím nahraďte starý ceník přiloženým PDF." -F "about=page:1" -F "about_url=" -F "prilohy[]=@$WORK/cenik-f19.pdf;type=application/pdf")
REQ_ID=$(printf %s "$REQ_URL" | grep -o 'id=[0-9]*' | sed 's/id=//' || true); REQ_ID="${REQ_ID:-0}"
expect "requests: saved as new, the PDF is a Media upload, the event is recorded, the administrator got the title by e-mail" \
  "$(sq "SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE 'media/%cenik-f19%' AND ido = JSON_EXTRACT(r.attachments, '\$[0]')), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'request.created' AND data LIKE '%\"id\":$REQ_ID,%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'spravce-f19@example.cz' AND predmet LIKE '%Nový ceník na stránku Služby%')) FROM ka_requests r WHERE r.id = $REQ_ID")" "new|1|1|1"
check "requests: the list opens with the new request first" 200 "/admin.php?module=requests" "Nový ceník na stránku Služby"
# Claude reads it with the attachment's address and answers – in progress, then done with links; only web addresses are kept
mcp list_requests '{}' > "$WORK/response"; mcp_text
contains -q "\"id\":$REQ_ID," "$WORK/text" && contains -q "\"url\":\"$B/media/" "$WORK/text" && contains -q '"author":"Recepce"' "$WORK/text" && contains -q '"written_by_staff"' "$WORK/text" && echo "  ok     requests: list_requests returns it with the Media url and the warning that staff wrote it" || { echo "  CHYBA  list_requests"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp update_request "{\"id\":$REQ_ID,\"status\":\"in_progress\",\"note\":\"Dívám se na to.\"}" > "$WORK/response"; mcp_text
expect "requests: update_request marks it in progress with a note" "$(sq "SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = r.id AND sender = 'claude' AND text = 'Dívám se na to.')) FROM ka_requests r WHERE r.id = $REQ_ID")" "in_progress|1"
mcp update_request "{\"id\":$REQ_ID,\"status\":\"declined\"}" > "$WORK/response"
contains -q 'needs a note' "$WORK/response" && echo "  ok     requests: declining without a reason is refused" || { echo "  CHYBA  update_request declined without note"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_request "{\"id\":$REQ_ID,\"status\":\"done\",\"note\":\"Ceník je v konceptu stránky Služby.\",\"links\":[{\"label\":\"Služby – koncept\",\"url\":\"$B/sluzby\"},{\"url\":\"javascript:alert(1)\"}]}" > "$WORK/response"; mcp_text
contains -q '"requester_notified":true' "$WORK/text" && echo "  ok     requests: done – the result says the requester was notified" || { echo "  CHYBA  update_request done"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "requests: done with the web link only, the requester got the note by e-mail" \
  "$(sq "SELECT CONCAT(r.status, '|', r.done_at IS NOT NULL, '|', (SELECT links LIKE '%$B/sluzby%' AND links NOT LIKE '%javascript%' FROM ka_request_messages WHERE request_id = r.id ORDER BY id DESC LIMIT 1), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'recepce@example.cz' AND predmet LIKE '%Nový ceník na stránku Služby%')) FROM ka_requests r WHERE r.id = $REQ_ID")" "done|1|1|1"
# the requester reads the note and the link in the detail and replies; Claude reads the reply with the request
code=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=requests&action=detail&id=$REQ_ID")
[ "$code" = 200 ] && grep -q 'Ceník je v konceptu stránky Služby.' "$WORK/response" && grep -q "href=\"$B/sluzby\"" "$WORK/response" && grep -q 'cenik-f19' "$WORK/response" && echo "  ok     requests: the requester's detail shows Claude's note, the draft link and the attachment" || { echo "  CHYBA  request detail: kód $code"; ERRORS=$((ERRORS+1)); }
REQ_CSRF=$(csrf)
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php?module=requests&action=reply" -d "_csrf=$REQ_CSRF" -d "id=$REQ_ID" --data-urlencode "text=Díky, zveřejním to."
mcp list_requests "{\"id\":$REQ_ID}" > "$WORK/response"; mcp_text
contains -q '"from":"person","name":"Recepce","text":"Díky, zveřejním to."' "$WORK/text" && echo "  ok     requests: the reply is in the conversation Claude reads" || { echo "  CHYBA  request reply"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp list_requests '{"status":"open"}' > "$WORK/response"; mcp_text
contains -q "\"id\":$REQ_ID," "$WORK/text" && { echo "  CHYBA  a done request is listed among the open ones"; ERRORS=$((ERRORS+1)); } || echo "  ok     requests: a done request is not among the open ones"
# a drafts-only connection answers requests (it only writes notes about drafts) but still cannot publish
mcp_as "$DRAFT_TOKEN" update_request "{\"id\":$REQ_ID,\"status\":\"in_progress\",\"note\":\"Reopened by a drafts connection\"}" > "$WORK/response"
expect "requests: a drafts-only connection reopens the request with a note" "$(sq "SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = $REQ_ID AND text = 'Reopened by a drafts connection')) FROM ka_requests WHERE id = $REQ_ID")" "in_progress|1"
mcp_as "$DRAFT_TOKEN" publish_build '{"id":1}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     requests: the same drafts-only connection cannot publish" || { echo "  CHYBA  drafts connection published"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# a person closes it from the detail; a request of a user without the section is refused over MCP
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" "$B/admin.php?module=requests&action=detail&id=$REQ_ID"; REQ_CSRF=$(csrf)
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php?module=requests&action=status" -d "_csrf=$REQ_CSRF" -d "id=$REQ_ID" -d status=done
expect "requests: the person marks it done in the detail" "$(sq "SELECT status FROM ka_requests WHERE id = $REQ_ID")" "done"
expect "requests: a user without the section gets a 403" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=requests")" 403
echo "== 3.1: Ask Claude on the dashboard"
expect "3.5: the first call of a Claude connection is remembered (claude_first_used)" "$(sq "SELECT hodnota REGEXP '^[0-9]{4}-' FROM ka_nastaveni WHERE promenna = 'claude_first_used'")" "1"
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" "$B/admin.php"
contains -q 'id="ask-claude-text"' "$WORK/response" && contains -q 'name="quick" value="1"' "$WORK/response" && ! contains -q 'data-ask-claude-example' "$WORK/response" && contains -q 'Nový ceník na stránku Služby' "$WORK/response" \
  && echo "  ok     ask: a staff user with only Requests gets the box and their requests, no examples for sections they cannot open" || { echo "  CHYBA  ask box for staff"; ERRORS=$((ERRORS+1)); }
REQ_CSRF=$(csrf)
ASK_URL=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=requests&action=save" -F "_csrf=$REQ_CSRF" -F quick=1 -F from=dashboard -F "text=Zavřeno od 24. do 26. prosince. Dejte to prosím na úvodní stránku i do patičky.")
expect "ask: the box saves a request titled by its first sentence and goes back to the dashboard" "$(sq "SELECT CONCAT(title, '|', status) FROM ka_requests ORDER BY id DESC LIMIT 1")|${ASK_URL##*/}" "Zavřeno od 24. do 26. prosince.|new|admin.php"
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php?module=requests&action=save" -d "_csrf=$REQ_CSRF" -d quick=1 -d from=dashboard -d "text=   "
expect "ask: an empty box saves nothing" "$(sq "SELECT COUNT(*) FROM ka_requests WHERE title = ''")" "0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
contains -q 'data-ask-claude-example="triage"' "$WORK/response" && contains -q 'data-ask-claude-copy' "$WORK/response" && contains -q 'ask-claude-kdy' "$WORK/response" \
  && echo "  ok     ask: the administrator gets examples, the copy for the Claude app and whether a scheduled run picks requests up" || { echo "  CHYBA  ask box for the administrator"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_uzivatele SET email = '' WHERE user = 'admin'" > /dev/null
echo "== 2.15: comments on drafts"
DC_SMTP_PORT=$((PORT + 12)); mkdir -p "$WORK/smtp-dc"
command php "$ROOT/tools/fake-smtp.php" "$DC_SMTP_PORT" "$WORK/smtp-dc" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$DC_SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'); UPDATE ka_uzivatele SET email = 'editor@example.cz', jazyk = '' WHERE user = 'admin'" > /dev/null
mcp vytvor_stranku '{"titulek":"Comment draft","adresa":"komentar-koncept","text":"<p>Draft paragraph to comment on</p>","zobrazit":false}' > "$WORK/response"; mcp_text; DC_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
# the builder turns the text page into a draft build and carries the (empty) comments panel data
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$DC_PAGE"
contains -q '"komentare":\[\]' "$WORK/response" && contains -q '"komentarVyrizen":' "$WORK/response" && echo "  ok     comments: the builder of a page carries the comments panel data (empty) and the resolve address" || { echo "  CHYBA  builder comments data"; ERRORS=$((ERRORS+1)); }
dc_share() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_share&id=$DC_PAGE" -d "_csrf=$(csrf)" -d dni=1 "$@"; }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
code=$(dc_share -d komentare=1); DC_LINK=$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' "$WORK/response"); DC_KEY="${DC_LINK##*preview_key=}"
[ "$code" = 200 ] && contains -q '"komentare":true' "$WORK/response" && [[ "$DC_KEY" == *k.* ]] && echo "  ok     comments: Share with “Allow comments” signs the flag into the key" || { echo "  CHYBA  build_share with comments: code $code, link $DC_LINK"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
dc_share > /dev/null; DC_PLAIN_KEY="$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' "$WORK/response")"; DC_PLAIN_KEY="${DC_PLAIN_KEY##*preview_key=}"
curl -s -o "$WORK/response" "$DC_LINK"; DC_ELEMENT=$(grep -o 'data-ka-id="[^"]*"' "$WORK/response" | head -1 | sed 's/data-ka-id="//;s/"//')
contains -q 'data-ka-komentare' "$WORK/response" && contains -q 'Draft paragraph to comment on' "$WORK/response" && contains -q 'noindex' "$WORK/response" && [ -n "$DC_ELEMENT" ] && ! contains -q 'data-ka-typ' "$WORK/response" \
  && echo "  ok     comments: a visitor with the link sees the draft, the comment widget and element ids – not the editor markers" || { echo "  CHYBA  comment mode preview"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/komentar-koncept?build=koncept&preview_key=$DC_PLAIN_KEY"
contains -q 'Draft paragraph to comment on' "$WORK/response" && ! contains -q 'data-ka-komentare' "$WORK/response" && ! contains -q 'data-ka-id' "$WORK/response" && echo "  ok     comments: a plain preview link shows the draft without the widget" || { echo "  CHYBA  plain preview shows the widget"; ERRORS=$((ERRORS+1)); }
dc_post() { curl -s -o "$WORK/response" -w '%{http_code} %{redirect_url}' -X POST "$B/_komentar" -d "cil=stranka:$DC_PAGE" "$@"; }
expect "3.7: a refused comment gets the same answer at /_comment as at /_komentar" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_comment" -d "cil=stranka:$DC_PAGE" -d klic=x)" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_komentar" -d "cil=stranka:$DC_PAGE" -d klic=x)"
DC_RESULT=$(dc_post -d "klic=$DC_KEY" -d "prvek=$DC_ELEMENT" --data-urlencode "zpet=/komentar-koncept?build=koncept&preview_key=$DC_KEY" --data-urlencode "citace=Draft paragraph" --data-urlencode "jmeno=Client <b>Novak</b>" --data-urlencode "text=Please <b>fix</b> this paragraph – it is  too long.")
[[ "$DC_RESULT" == "303 $B/komentar-koncept?build=koncept&preview_key=$DC_KEY&comment=ok#ka-komentar" ]] && echo "  ok     comments: an anonymous visitor with the key posts a comment and comes back to the preview" || { echo "  CHYBA  comment POST: $DC_RESULT"; ERRORS=$((ERRORS+1)); }
expect "comments: stored as plain text with the element, the quote and the name" "$(sq "SELECT CONCAT_WS('|', name, text, element, quote, resolved_at IS NULL) FROM ka_draft_comments WHERE target = 'stranka:$DC_PAGE'")" "Client Novak|Please fix this paragraph – it is too long.|$DC_ELEMENT|Draft paragraph|1"
expect "comments: an invalid key, a plain key and a wrong target are refused" "$(dc_post -d klic=1999999999k.$(printf 'a%.0s' $(seq 1 64)) -d jmeno=X -d text=Y | cut -c1-3)|$(dc_post -d "klic=$DC_PLAIN_KEY" -d jmeno=X -d text=Y | cut -c1-3)|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_komentar" -d cil=stranka:999999 -d "klic=$DC_KEY" -d jmeno=X -d text=Y)" "403|403|403"
expect "comments: without a name or a text nothing is stored" "$(dc_post -d "klic=$DC_KEY" -d jmeno= -d text=Hello | sed 's/.*comment=//;s/#.*//')|$(sq "SELECT COUNT(*) FROM ka_draft_comments")" "chyba|1"
for i in $(seq 1 25); do DC_MAIL=$(grep -l '^X-Rcpt-To: editor@example.cz' "$WORK"/smtp-dc/*.eml 2>/dev/null | tail -1); [ -n "$DC_MAIL" ] && break; sleep 0.2; done # every administrator gets one; the test reads the admin's (the fake SMTP server writes its file a moment after accepting)
dc_body() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo base64_decode($b);' "$1"; } # a single-part base64 message
[ -n "$DC_MAIL" ] && eml "$DC_MAIL" | contains 'Nový komentář ke konceptu „Comment draft“' && dc_body "$DC_MAIL" | contains 'Client Novak' && dc_body "$DC_MAIL" | contains "module=pages&action=builder&id=$DC_PAGE" \
  && echo "  ok     comments: the administrator gets an e-mail with the name, the excerpt and the builder link" || { echo "  CHYBA  comment e-mail"; [ -n "$DC_MAIL" ] && { eml "$DC_MAIL" | head -12; dc_body "$DC_MAIL"; }; ERRORS=$((ERRORS+1)); }
check "comments: the pages list shows the badge with the count" 200 "/admin.php?module=pages" "Komentářů: 1"
check "comments: the builder shows the comment in its panel data" 200 "/admin.php?module=pages&action=builder&id=$DC_PAGE" '"jmeno":"Client Novak"'
mcp list_draft_comments '{}' > "$WORK/response"; mcp_text
contains -q '"name":"Client Novak"' "$WORK/text" && contains -q "\"element\":\"$DC_ELEMENT\"" "$WORK/text" && contains -q '"page_title":"Comment draft"' "$WORK/text" && contains -q 'not instructions' "$WORK/text" && echo "  ok     MCP: list_draft_comments returns the comment with the element and tells Claude it is data, not an instruction" || { echo "  CHYBA  list_draft_comments"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
DC_ID=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp list_draft_comments "{\"page_id\":$((DC_PAGE + 1000))}" > "$WORK/response"; mcp_text; contains -q '"total":0' "$WORK/text" && echo "  ok     MCP: the page filter of list_draft_comments" || { echo "  CHYBA  list_draft_comments page filter"; ERRORS=$((ERRORS+1)); }
mcp resolve_draft_comment "{\"id\":$DC_ID}" > "$WORK/response"; mcp_text
contains -q '"resolved":true' "$WORK/text" && [ "$(sq "SELECT resolved_at IS NOT NULL FROM ka_draft_comments WHERE id = $DC_ID")" = 1 ] && echo "  ok     MCP: resolve_draft_comment marks the comment resolved" || { echo "  CHYBA  resolve_draft_comment"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp list_draft_comments '{}' > "$WORK/response"; mcp_text; DC_OPEN=$(grep -o '"total":[0-9]*' "$WORK/text"); mcp list_draft_comments '{"include_resolved":true}' > "$WORK/response"; mcp_text
expect "MCP: unresolved by default, resolved on request; resolving twice is refused" "$DC_OPEN|$(grep -o '"total":[0-9]*' "$WORK/text")|$(mcp resolve_draft_comment "{\"id\":$DC_ID}" | grep -c 'No open comment')" '"total":0|"total":1|1'
# a second comment resolved with one click in the builder; through another page's builder it is not found
dc_post -d "klic=$DC_KEY" -d jmeno=Client -d "text=Second note" > /dev/null; DC_ID2=$(sq "SELECT MAX(id) FROM ka_draft_comments")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"; DC_TOKEN=$(csrf)
DC_RESOLVE=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_comment_resolve&id=$DC_PAGE" -d "_csrf=$DC_TOKEN" -d "id=$DC_ID2")
expect "comments: the builder resolves a comment with one click, a comment of another page is not found" "$DC_RESOLVE|$(grep -c '"vyrizeno":true' "$WORK/response")|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_comment_resolve&id=$IDS" -d "_csrf=$DC_TOKEN" -d "id=$DC_ID2")" "200|1|404"
# rate limit: Core\Antispam counts comments per address (hashed) and draft
# the site's clock, not the database's NOW() (the CI database runs in UTC, the site in Europe/Prague)
DC_NOW=$(site_time)
sq "INSERT INTO ka_kontrola_ip (ip_adresa, typ, cil, cas) SELECT SUBSTRING(SHA2('kaleta|127.0.0.1', 256), 1, 40), 'komentar', $DC_PAGE, '$DC_NOW' FROM ka_nastaveni LIMIT 10" > /dev/null
expect "comments: the eleventh comment from one address in ten minutes is refused" "$(dc_post -d "klic=$DC_KEY" -d jmeno=Client -d text=Again | sed 's/.*comment=//;s/#.*//')|$(sq "SELECT COUNT(*) FROM ka_draft_comments WHERE target = 'stranka:$DC_PAGE'")" "limit|2"
mcp nahled_odkaz "{\"id\":$DC_PAGE,\"komentare\":true}" > "$WORK/response"; mcp_text
contains -q 'preview_key=[0-9]*k\.' "$WORK/text" && contains -q '"komentare":true' "$WORK/text" && echo "  ok     MCP: preview_link with comments: true gives a commenting link" || { echo "  CHYBA  preview_link comments"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_kontrola_ip WHERE typ = 'komentar'" > /dev/null
echo "== 2.17: undo a whole Claude session"
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp vytvor_stranku '{"titulek":"Undo original","zobrazit":false}' > "$WORK/response"; mcp_text; UNDO_OLD=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp update_page "{\"id\":$UNDO_OLD,\"title\":\"Changed by Claude\"}" > /dev/null
mcp save_build "{\"id\":$UNDO_OLD,\"build\":{\"v\":1,\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft by Claude\"}}]}}" > /dev/null
mcp vytvor_stranku '{"titulek":"Undo new page","zobrazit":false}' > "$WORK/response"; mcp_text; UNDO_NEW=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp vytvor_stranku '{"titulek":"Undo conflict","zobrazit":false}' > "$WORK/response"; mcp_text; UNDO_C=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_stranky SET titulek = 'Edited by a person' WHERE ids = $UNDO_C" > /dev/null
UNDO_S=$(sq "SELECT MAX(id) FROM ka_agent_sessions")
mcp list_agent_sessions '{"limit":3}' > "$WORK/response"; mcp_text
contains -q "\"id\":$UNDO_S," "$WORK/text" && contains -q 'save_build' "$WORK/text" && echo "  ok     undo: the session lists its changes and tools" || { echo "  CHYBA  list_agent_sessions"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp undo_agent_session "{\"id\":$UNDO_S}" > "$WORK/response"
contains -q 'confirm=true' "$WORK/response" && echo "  ok     undo: needs an explicit confirmation" || { echo "  CHYBA  undo confirm"; ERRORS=$((ERRORS+1)); }
mcp undo_agent_session "{\"id\":$UNDO_S,\"confirm\":true}" > "$WORK/response"; mcp_text
expect "undo: the original page has its title and no build again, the new page is gone, the page a person edited since stays and is reported" \
  "$(sq "SELECT CONCAT(titulek, '|', stavba_koncept IS NULL) FROM ka_stranky WHERE ids = $UNDO_OLD")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE ids = $UNDO_NEW")|$(sq "SELECT titulek FROM ka_stranky WHERE ids = $UNDO_C")|$(grep -c "\"conflicts\":\[{\"table\":\"stranky\"" "$WORK/text")" \
  "Undo original|1|0|Edited by a person|1"
expect "undo: the session is marked undone and cannot be undone twice" "$(sq "SELECT undone_at IS NOT NULL FROM ka_agent_sessions WHERE id = $UNDO_S")|$(mcp undo_agent_session "{\"id\":$UNDO_S,\"confirm\":true}" | grep -c 'already undone')" "1|1"
check "undo: the change log lists Claude sessions with the undo button" 200 "/admin.php?module=changelog&action=sessions" "action=undo"
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
echo "== 3.0: add-ons through the extension API"
mkdir -p "$WORK/web/extensions" && cp -R "$ROOT/docs/examples/extensions/hello" "$WORK/web/extensions/hello"
# the example asks for Kaleta 3.0; before the version is bumped for a release the tree may still say 2.x
sed -i.bak 's/">=3.0"/">=2.0"/' "$WORK/web/extensions/hello/extension.json" && rm -f "$WORK/web/extensions/hello/extension.json.bak"
mkdir -p "$WORK/web/extensions/broken" && printf '%s' '{"name":"Broken","class":"Broken\\Ext","requires":{"api":1}}' > "$WORK/web/extensions/broken/extension.json"
printf '%s\n' '<?php namespace Broken; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void { throw new \RuntimeException("deliberately broken"); } }' > "$WORK/web/extensions/broken/Extension.php"
mkdir -p "$WORK/web/extensions/old" && printf '%s' '{"name":"Old","class":"Old\\Ext","requires":{"api":0}}' > "$WORK/web/extensions/old/extension.json" && echo '<?php' > "$WORK/web/extensions/old/Extension.php"
check "add-ons: Add-ons lists what is in extensions/ and says why an old one cannot run" 200 "/admin.php?module=addons" "written for extension API 0"
# 3.3.2 (N36): the web serves only extensions/<slug>/public/ – never an add-on's code, manifest or SQL, and no PHP from public/
mkdir -p "$WORK/web/extensions/hello/public" && printf 'body{}' > "$WORK/web/extensions/hello/public/hello.css" && echo '<?php echo "ran";' > "$WORK/web/extensions/hello/public/run.php"
expect "3.3.2 add-ons: Extension.php, extension.json and public/*.php are refused, a file in public/ is served" \
  "$(for u in extensions/hello/Extension.php extensions/hello/extension.json extensions/hello/public/run.php extensions/README.md extensions/hello/public/hello.css; do printf '%s ' "$(curl -s -o /dev/null -w '%{http_code}' "$B/$u")"; done)" "403 403 403 403 200 "
rm -rf "$WORK/web/extensions/hello/public"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=1
expect "add-ons: switching on needs the trust tick" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'addons_enabled'" || true)" ""
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=1 -d trust=1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=broken -d on=1 -d trust=1
mcp vytvor_stranku '{"titulek":"Addon page","adresa":"addon-page","text":"<p>{{ext.hello.greeting name=\"Jana\"}}</p>","zobrazit":true}' > /dev/null
curl -s -o "$WORK/response" "$B/addon-page"
contains -q 'Hello, Jana!' "$WORK/response" && contains -q '<!-- hello add-on -->' "$WORK/response" && echo "  ok     add-ons: a token in a page and a footer filter" || { echo "  CHYBA  add-on token/filter"; grep -o '{{ext[^}]*}}' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N38): a token in what a visitor sent (the search query) is never run – with or without attributes
curl -s -G -o "$WORK/response" "$B/hledani" --data-urlencode 'q={{ext.hello.greeting name="Mallory"}}'
curl -s -G -o "$WORK/response2" "$B/hledani" --data-urlencode 'q={{ext.hello.greeting}}'
! contains -q 'hello-greeting' "$WORK/response" && ! contains -q 'hello-greeting' "$WORK/response2" && contains -q 'ext.hello.greeting name=&quot;Mallory&quot;' "$WORK/response" && echo "  ok     add-ons: a token in the search query is not run" || { echo "  CHYBA  add-on token ve vyhledávání"; grep -o '<input type="search"[^>]*>' "$WORK/response" "$WORK/response2" | head -2; ERRORS=$((ERRORS+1)); }
expect "add-ons: a broken add-on is switched off at once and its error kept" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'addons_enabled'")|$(sq "SELECT hodnota LIKE '%deliberately broken%' FROM ka_nastaveni WHERE promenna = 'addons_error.broken'")" "hello|1"
check "add-ons: the error shows in Add-ons" 200 "/admin.php?module=addons" "deliberately broken"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
mcp ext_hello_greet '{"name":"Petr"}' > "$WORK/response2"
contains -q '"name":"ext_hello_greet"' "$WORK/response" && contains -q 'Hello, Petr!' "$WORK/response2" && echo "  ok     add-ons: Claude lists and calls the add-on's tool" || { echo "  CHYBA  add-on MCP tool"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N39): add-on tools check the user's role like the built-in ones – a write tool needs an editor by default,
# a tool may require a section; a read tool stays open to every user
mkdir -p "$WORK/web/extensions/gate" && printf '%s' '{"name":"Gate","class":"Gate\\Ext","requires":{"api":1}}' > "$WORK/web/extensions/gate/extension.json"
cat > "$WORK/web/extensions/gate/Extension.php" <<'PHP'
<?php namespace Gate; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void {
    $api->mcpTool('write', 'A write tool without a role.', [], 'write', fn (array $a): array => ['written' => true]);
    $api->mcpTool('leads', 'A read tool for the Enquiries section.', [], 'read', fn (array $a): array => ['leads' => 1], 'enquiries');
    $api->mcpTool('look', 'A read tool without a role.', [], 'read', fn (array $a): array => ['looked' => true]);
} }
PHP
sq "UPDATE ka_nastaveni SET hodnota = 'hello,gate' WHERE promenna = 'addons_enabled';
  INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n39-author', '', 'Author N39', 0, '$(site_time)', '$(site_time)');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'n39-author') AND ident_modulu = 'enquiries'" > /dev/null
AUTHOR_TOKEN="kaleta_$(printf 'e%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'author', '$(php -r 'echo hash("sha256", $argv[1]);' "$AUTHOR_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n39-author'" > /dev/null
expect "3.3.2 add-ons: an author's connection cannot call a write tool or a tool of a section it lacks, may call a read tool; the admin may call all" \
  "$(mcp_as "$AUTHOR_TOKEN" ext_gate_write '{}' | grep -c 'needs an editor')|$(mcp_as "$AUTHOR_TOKEN" ext_gate_leads '{}' | grep -c 'section')|$(mcp_as "$AUTHOR_TOKEN" ext_gate_look '{}' | grep -c 'looked')|$(mcp ext_gate_write '{}' | grep -c 'written')|$(mcp ext_gate_leads '{}' | grep -c 'leads')" "1|1|1|1|1"
sq "UPDATE ka_nastaveni SET hodnota = 'hello' WHERE promenna = 'addons_enabled'; DELETE FROM ka_uzivatele WHERE user = 'n39-author'" > /dev/null
rm -rf "$WORK/web/extensions/gate"
# 3.3.2 (N12): without the News section an editor-level user reads over MCP only the news visitors see, as with pages
mcp create_news "{\"title\":\"N12 draft only for News\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; N12_DRAFT=$(mcp_value id)
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n12-editor', '', 'Editor N12', 1, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
EDITOR12_TOKEN="kaleta_$(printf 'd%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'editor', '$(php -r 'echo hash("sha256", $argv[1]);' "$EDITOR12_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
expect "3.3.2 MCP: list_news and get_news without the News section show no drafts; with it they do" \
  "$(mcp_as "$EDITOR12_TOKEN" list_news '{"limit":50}' | grep -c 'N12 draft only')|$(mcp_as "$EDITOR12_TOKEN" get_news "{\"id\":$N12_DRAFT}" | grep -c '"isError":true')|$(mcp list_news '{"limit":50}' | grep -c 'N12 draft only')" "0|1|1"
mcp trash_news "{\"id\":$N12_DRAFT}" > /dev/null
# 3.3.2 (N11): an author-level role with the Categories section cannot rename or delete a category in the admin (as over MCP)
sq "UPDATE ka_uzivatele SET admin = 0, password = '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")' WHERE user = 'n12-editor';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'categories' FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
JAR_N11="$WORK/cookies-n11.txt"; curl -s -c "$JAR_N11" -o "$WORK/response" "$B/admin.php"
curl -s -b "$JAR_N11" -c "$JAR_N11" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=n12-editor --data-urlencode "password=$PASSWORD"
N11_ID=$(sq "SELECT idt FROM ka_kategorie WHERE seo_link = '$CATEGORY' OR nazev = '$CATEGORY' LIMIT 1"); N11_NAME=$(sq "SELECT nazev FROM ka_kategorie WHERE idt = $N11_ID")
curl -s -b "$JAR_N11" -o "$WORK/response" "$B/admin.php?module=categories"
curl -s -b "$JAR_N11" -c "$JAR_N11" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$(csrf)" -d "idt=$N11_ID" -d nazev=Renamed-by-author -d seo_link=renamed-by-author
curl -s -b "$JAR_N11" -c "$JAR_N11" -o /dev/null -X POST "$B/admin.php?module=categories&action=delete" -d "_csrf=$(csrf)" -d "idt=$N11_ID"
expect "3.3.2 admin: an author-level role with the Categories section neither renames nor deletes a category" "$(sq "SELECT nazev FROM ka_kategorie WHERE idt = $N11_ID")" "$N11_NAME"
sq "DELETE FROM ka_uzivatele WHERE user = 'n12-editor'" > /dev/null
check "add-ons: the add-on's admin page" 200 "/admin.php?module=addons&action=page&p=hello.settings" "Greeting word"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=page&p=hello.settings" -d "_csrf=$TOKEN" -d word=Ahoj
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "add-ons: the admin page saved the add-on's own setting, the page uses it" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'ext.hello.word'")|$(curl -s "$B/addon-page" | grep -c 'Ahoj, Jana!')" "Ahoj|1"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "ext_hello_daily" "$WORK/tasks.txt" && echo "  ok     add-ons: the add-on's job runs with the others" || { echo "  CHYBA  add-on job"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=0
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "add-ons: switched off, the token is left as it was written and the tool is gone" "$(curl -s "$B/addon-page" | grep -c '{{ext.hello.greeting')|$(mcp ext_hello_greet '{}' | grep -c 'isError')" "1|1"
rm -rf "$WORK/web/extensions"
echo "== 2.17: scheduled Claude runs (Core\\AgentSchedules) – the site keeps the schedule, a routine in Claude does the runs as drafts"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=schedules"; TOKEN=$(csrf)
grep -q 'get_due_agent_runs' "$WORK/response" && grep -q "$B/mcp" "$WORK/response" && grep -q 'action=account#claude' "$WORK/response" && echo "  ok     schedules: the Set up in Claude panel has the routine prompt with the MCP address and the link to the tokens" || { echo "  CHYBA  schedules panel"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=schedules&action=save" -d "_csrf=$TOKEN" -d id=0 --data-urlencode "name=Weekly review" -d task=review --data-urlencode "text=Only the Services pages." -d cadence=weekly -d weekday=1 -d monthday=1 -d "time=07:00" -d active=1
SCHED=$(sq "SELECT id FROM ka_agent_schedules WHERE name = 'Weekly review'"); SCHED="${SCHED:-0}"
expect "schedules: saved, active, next due the coming Monday 07:00" "$(sq "SELECT CONCAT(active, '|', cadence, '|', day, '|', time, '|', next_due > '$(site_time)', '|', DAYOFWEEK(next_due), '|', TIME(next_due)) FROM ka_agent_schedules WHERE id = $SCHED")" "1|weekly|1|07:00|1|2|07:00:00"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=schedules&action=save" -d "_csrf=$TOKEN" -d id=0 --data-urlencode "name=Bad" -d task=custom -d text= -d cadence=monthly -d weekday=1 -d monthday=31 -d "time=07:00" -d active=1
expect "schedules: custom instructions without a text are refused" "$(sq "SELECT COUNT(*) FROM ka_agent_schedules WHERE name = 'Bad'")" "0"
# nothing due yet; a schedule due in the past is handed out once – with the task text, the administrator's extra and the rules
mcp_as "$DRAFT_TOKEN" get_due_agent_runs '{}' > "$WORK/response"
expect "schedules: nothing due – the drafts-only connection gets an empty list" "$(mcp_value count)" "0"
sq "UPDATE ka_agent_schedules SET next_due = '$(site_time)' - INTERVAL 1 HOUR WHERE id = $SCHED" > /dev/null
mcp_as "$DRAFT_TOKEN" get_due_agent_runs '{}' > "$WORK/response"; mcp_text
RUN=$(mcp_value runs 0 id); RUN="${RUN:-0}"
contains -q '"name":"Weekly review"' "$WORK/text" && contains -q 'Run site_audit' "$WORK/text" && contains -q 'Also: Only the Services pages.' "$WORK/text" && contains -q 'never publish' "$WORK/text" && contains -q '"connection":"drafts only' "$WORK/text" && echo "  ok     schedules: get_due_agent_runs hands the run out with the task text, the administrator's extra and the rules" || { echo "  CHYBA  get_due_agent_runs"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" get_due_agent_runs '{}' > "$WORK/response"
expect "schedules: a second call returns the same open run – one row, running, the connection remembered" "$(mcp_value runs 0 id)|$(sq "SELECT CONCAT(COUNT(*), '|', MAX(status), '|', MAX(connection)) FROM ka_agent_runs WHERE schedule_id = $SCHED")" "$RUN|1|running|Claude drafts"
mcp_as "$DRAFT_TOKEN" report_agent_run "{\"id\":$RUN,\"status\":\"ok\",\"summary\":\"Audit clean, two descriptions drafted.\",\"links\":[{\"label\":\"Services – draft\",\"url\":\"$B/sluzby\"},{\"url\":\"javascript:alert(1)\"}]}" > "$WORK/response"; mcp_text
contains -q '"status":"ok"' "$WORK/text" && contains -q '"next_due":"' "$WORK/text" && echo "  ok     schedules: report_agent_run finishes the run and tells the next due time" || { echo "  CHYBA  report_agent_run"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "schedules: the run is ok with the web link only, last_run_at set, next_due moved on to the next Monday 07:00" "$(sq "SELECT CONCAT(r.status, '|', r.finished_at IS NOT NULL, '|', r.links LIKE '%$B/sluzby%' AND r.links NOT LIKE '%javascript%', '|', s.last_run_at IS NOT NULL, '|', s.next_due > '$(site_time)' AND s.next_due <= '$(site_time)' + INTERVAL 7 DAY, '|', DAYOFWEEK(s.next_due), '|', TIME(s.next_due)) FROM ka_agent_runs r JOIN ka_agent_schedules s ON s.id = r.schedule_id WHERE r.id = $RUN")" "ok|1|1|1|1|2|07:00:00"
mcp_as "$DRAFT_TOKEN" report_agent_run "{\"id\":$RUN,\"status\":\"ok\",\"summary\":\"again\"}" > "$WORK/response"
contains -q 'already reported' "$WORK/response" && echo "  ok     schedules: a run is reported once" || { echo "  CHYBA  report twice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" publish_build '{"id":1}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     schedules: the same drafts-only connection cannot publish" || { echo "  CHYBA  drafts connection published"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# a schedule 7 hours overdue that nobody picked up: the hourly job marks it missed, moves it on and records the warning the alerts send
sq "UPDATE ka_agent_schedules SET next_due = '$(site_time)' - INTERVAL 7 HOUR WHERE id = $SCHED; UPDATE ka_jobs SET last_run = NULL WHERE name = 'agent_runs'" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "agent_runs: missed 1" "$WORK/tasks.txt" && echo "  ok     schedules: the job reports the missed run" || { echo "  CHYBA  agent_runs job"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "schedules: a missed run, next_due in the future, the event agent_run.missed as a warning with the schedule id" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_agent_runs WHERE schedule_id = $SCHED AND status = 'missed'), '|', (SELECT next_due > '$(site_time)' FROM ka_agent_schedules WHERE id = $SCHED), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'agent_run.missed' AND severity = 'warning' AND data LIKE '%\"schedule\":$SCHED,%'))")" "1|1|1"
check "schedules: the list shows the last run status" 200 "/admin.php?module=schedules" "Zmeškaný"
check "schedules: the history shows the summary of the reported run" 200 "/admin.php?module=schedules&action=history&id=$SCHED" "Audit clean, two descriptions drafted."
grep -q "href=\"$B/sluzby\"" "$WORK/response" && ! grep -q 'javascript:' "$WORK/response" && echo "  ok     schedules: the history links the draft, the bad link never got in" || { echo "  CHYBA  history link"; ERRORS=$((ERRORS+1)); }
expect "schedules: tools/list of a drafts-only connection offers report_agent_run (catalog: draft)" "$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -o '"name":"report_agent_run"' | wc -l | tr -d ' ')" "1"
echo "== 3.2: what a drafts-only connection may save, and Waiting for you (Core\\PendingReview)"
# collection items: a new one is always hidden, a hidden one may change, a visible one may not
mcp_as "$DRAFT_TOKEN" save_collection_item '{"collection":"tym","name":"Navrh Clena","values":{"funkce":"Stolar"},"visible":true}' > "$WORK/response"; mcp_text
DRAFT_ITEM=$(mcp_value id); DRAFT_ITEM="${DRAFT_ITEM:-0}"
expect "3.2: a drafts-only connection creates a collection item – hidden, whatever visible says, and says so" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM")|$(mcp_value visible)|$(grep -c 'Saved hidden' "$WORK/text")" "0||1"
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"tym\",\"id\":$DRAFT_ITEM,\"values\":{\"funkce\":\"Mistr stolar\"}}" > /dev/null
expect "3.2: a drafts-only connection changes a hidden item" "$(sq "SELECT CONCAT(zobrazit, '|', data LIKE '%Mistr stolar%') FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM")" "0|1"
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"tym\",\"id\":$DRAFT_ITEM,\"visible\":true}" > "$WORK/response"
contains -q 'cannot make an item visible' "$WORK/response" && expect "3.2: a drafts-only connection cannot make an item visible" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM")" "0" || { echo "  CHYBA  drafts: visible item"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_collection_item '{"collection":"tym","name":"Planovany Navrh","publish_at":"2099-01-01 08:00"}' > "$WORK/response"
contains -q 'cannot schedule an item' "$WORK/response" && expect "3.2: a drafts-only connection cannot schedule an item" "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Planovany Navrh'")" "0" || { echo "  CHYBA  drafts: scheduled item"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
LIVE_ITEM=$(sq "SELECT p.idp FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = 'tym' AND p.zobrazit = 1 AND p.smazano IS NULL ORDER BY p.idp LIMIT 1"); LIVE_ITEM="${LIVE_ITEM:-0}"
LIVE_BEFORE=$(sq "SELECT SHA2(CONCAT(nazev, data), 256) FROM ka_kolekce_polozky WHERE idp = $LIVE_ITEM")
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"tym\",\"id\":$LIVE_ITEM,\"name\":\"Prepsano Claudem\"}" > "$WORK/response"
contains -q 'propose' "$WORK/response" && expect "3.2: a drafts-only connection cannot change a visible item – it is told to propose the change" "$(sq "SELECT SHA2(CONCAT(nazev, data), 256) FROM ka_kolekce_polozky WHERE idp = $LIVE_ITEM")" "$LIVE_BEFORE" || { echo "  CHYBA  drafts: visible item changed"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# enquiries: only the triage, as a suggestion
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$(site_time)', 'Navrh trideni', 'navrh@example.com', '[]')" > /dev/null
DRAFT_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Navrh trideni'")
mcp_as "$DRAFT_TOKEN" update_enquiry "{\"id\":$DRAFT_ENQ,\"status\":\"resolved\",\"category\":\"sales\"}" > "$WORK/response"
contains -q 'triage' "$WORK/response" && expect "3.2: a drafts-only connection cannot set the status of an enquiry (nothing saved)" "$(sq "SELECT CONCAT(stav, '|', kategorie) FROM ka_poptavky WHERE idp = $DRAFT_ENQ")" "0|" || { echo "  CHYBA  drafts: enquiry status"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" update_enquiry "{\"id\":$DRAFT_ENQ,\"category\":\"sales\",\"priority\":\"high\",\"draft_reply\":\"Dobrý den, ozveme se.\"}" > /dev/null
expect "3.2: a drafts-only connection saves the triage of an enquiry" "$(sq "SELECT CONCAT(stav, '|', kategorie, '|', priorita, '|', triaged_by) FROM ka_poptavky WHERE idp = $DRAFT_ENQ")" "0|sales|3|claude"
sq "DELETE FROM ka_poptavky WHERE idp = $DRAFT_ENQ" > /dev/null
# the notebook
mcp_as "$DRAFT_TOKEN" write_notebook '{"topic":"history","title":"Zprava z behu","text":"Navstevy rostou."}' > /dev/null
expect "3.2: a drafts-only connection writes a notebook note" "$(sq "SELECT COUNT(*) FROM ka_notebook WHERE title = 'Zprava z behu'")" "1"
# opening hours: a proposal the site ignores until a person applies it
TOMORROW=$(site_time "+1 day" "Y-m-d")
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Platna vyjimka\",\"notice_days\":0}" > /dev/null
APPLIED_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'Platna vyjimka'"); APPLIED_EXC="${APPLIED_EXC:-0}"
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"id\":$APPLIED_EXC,\"from\":\"$TOMORROW\",\"note\":\"Zmeneno Claudem\"}" > "$WORK/response"
contains -q 'can only propose' "$WORK/response" && expect "3.2: a drafts-only connection cannot change an exception in use" "$(sq "SELECT CONCAT(note, '|', proposed) FROM ka_hours_exceptions WHERE id = $APPLIED_EXC")" "Platna vyjimka|0" || { echo "  CHYBA  drafts: applied exception"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_hours_exceptions WHERE id = $APPLIED_EXC" > /dev/null
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Navrh Clauda\",\"notice_days\":7}" > "$WORK/response"; mcp_text
PROPOSED_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'Navrh Clauda'"); PROPOSED_EXC="${PROPOSED_EXC:-0}"
expect "3.2: a drafts-only connection saves a PROPOSED exception and is told a person applies it" "$(sq "SELECT proposed FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")|$(grep -c 'PROPOSAL' "$WORK/text")" "1|1"
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"id\":$PROPOSED_EXC,\"from\":\"$TOMORROW\",\"note\":\"Navrh Clauda\",\"hours\":\"9-12\",\"notice_days\":7}" > /dev/null
expect "3.2: a drafts-only connection changes its own proposal, which stays a proposal" "$(sq "SELECT CONCAT(proposed, '|', closed, '|', hours) FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")" "1|0|9-12"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
! grep -q 'ka-oznameni-hodiny' "$WORK/response" && ! grep -q 'Navrh Clauda' "$WORK/response" && echo "  ok     3.2: the site ignores a proposed exception (no notice bar, no structured data)" || { echo "  CHYBA  a proposal shows on the site"; ERRORS=$((ERRORS+1)); }
mcp list_hours '{}' > "$WORK/response"
expect "3.2: list_hours keeps proposals apart from the exceptions in use" "$(mcp_value exceptions)|$(mcp_value proposed 0 note)" "[]|Navrh Clauda"
check "3.2: a proposal has no door sign" 404 "/admin.php?module=settings&action=hours_sign&exception=$PROPOSED_EXC"
# Waiting for you: the dashboard and list_pending_review list the hidden item and the proposal
mcp_as "$DRAFT_TOKEN" list_pending_review '{}' > "$WORK/response"; mcp_text
contains -q '"kind":"hidden_items"' "$WORK/text" && contains -q 'Navrh Clena (' "$WORK/text" && contains -q '"kind":"proposed_hours"' "$WORK/text" && contains -q '"admin_url":"http' "$WORK/text" \
  && echo "  ok     3.2: list_pending_review (also over a drafts-only connection) lists the hidden item and the proposal with admin links" || { echo "  CHYBA  list_pending_review"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "3.2: the dashboard shows Waiting for you above the counters" 200 "/admin.php" 'class="ceka-na-vas"'
grep -q 'data-kind="proposed_hours"' "$WORK/response" && grep -q 'data-kind="hidden_items"' "$WORK/response" && [ "$(grep -o 'class="ceka-na-vas"\|class="dlazdice"' "$WORK/response" | head -1)" = 'class="ceka-na-vas"' ] \
  && echo "  ok     3.2: Waiting for you has a row for the proposal and the hidden item" || { echo "  CHYBA  Waiting for you rows"; ERRORS=$((ERRORS+1)); }
# a person applies the proposal in the admin – then the site uses it; another one is discarded
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
grep -q 'id="proposed-hours"' "$WORK/response" && grep -q "action=hours_apply" "$WORK/response" && grep -q "action=hours_discard" "$WORK/response" \
  && echo "  ok     3.2: Business details show the proposal with Apply and Discard" || { echo "  CHYBA  proposal in the admin"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_apply" -d "_csrf=$(csrf)" -d "exception=$PROPOSED_EXC"
expect "3.2: a person applies the proposal" "$(sq "SELECT proposed FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")" "0"
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-oznameni-hodiny' "$WORK/response" && grep -q 'Navrh Clauda' "$WORK/response" && echo "  ok     3.2: the applied exception shows on the site" || { echo "  CHYBA  applied exception not on the site"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Druhy navrh\"}" > /dev/null
SECOND_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'Druhy navrh'"); SECOND_EXC="${SECOND_EXC:-0}"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_discard" -d "_csrf=$(csrf)" -d "exception=$PROPOSED_EXC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_discard" -d "_csrf=$(csrf)" -d "exception=$SECOND_EXC"
expect "3.2: Discard removes a proposal, never an exception in use" "$(sq "SELECT COUNT(*) FROM ka_hours_exceptions WHERE id = $SECOND_EXC")|$(sq "SELECT COUNT(*) FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")" "0|1"
sq "DELETE FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC; DELETE FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.2: tools/list of a drafts-only connection offers the four tools and list_pending_review, not save_fact" "$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -o '"name":"\(save_collection_item\|save_hours_exception\|update_enquiry\|write_notebook\|list_pending_review\|save_fact\)"' | sort | tr -d '\n')" \
  '"name":"list_pending_review""name":"save_collection_item""name":"save_hours_exception""name":"update_enquiry""name":"write_notebook"'
echo "== 3.0: structured importers – Ghost and Blogger (Import\\Batch)"
# a small "old site" that only serves the images the fixtures point at; the fixtures name it as 127.0.0.1:65000
SRC_PORT=$((PORT + 16)); SRC="http://127.0.0.1:$SRC_PORT"
mkdir -p "$WORK/sources/img" "$WORK/sources/s1600"
php -r '$i = imagecreatetruecolor(320, 200); imagefill($i, 0, 0, imagecolorallocate($i, 120, 60, 30)); imagepng($i, $argv[1]); copy($argv[1], $argv[2]);' "$WORK/sources/img/team.png" "$WORK/sources/s1600/team.png"
(cd "$WORK/sources" && exec php -S "127.0.0.1:$SRC_PORT" > /dev/null 2>&1) & SRC_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$SRC/img/team.png" && break; sleep 0.3; done
sed "s|http://127.0.0.1:65000|$SRC|g" "$ROOT/tools/fixtures/blogger-export.xml" > "$WORK/blogger-export.xml"
TOKEN=$(csrf)
check "import and export: the section for other systems lists Ghost and Blogger" 200 "/admin.php?module=transfer" 'option value="blogger">Blogger'
src_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=source_progress&file=$1" -d "_csrf=$TOKEN"; }
# Ghost: the export does not carry the site address – the admin enters it in the preview; the primary tag becomes the category
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_upload" -F "_csrf=$TOKEN" -F system=ghost -F "soubor=@$ROOT/tools/fixtures/ghost-export.json"
src_batch ghost-ghost-export.json
check "Ghost: the preview counts posts, pages and tags and says that routes.yaml is not read" 200 "/admin.php?module=transfer&action=source_preview&file=ghost-ghost-export.json" "routes.yaml"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=ghost-ghost-export.json"
contains -q 'name="site_url"' "$WORK/response" && contains -q 'Firing the first kiln' "$WORK/response" && contains -q 'bookmark 1' "$WORK/response" && echo "  ok     Ghost: the preview asks for the site address, shows the first titles and the unsupported card" || { echo "  CHYBA  Ghost preview"; ERRORS=$((ERRORS+1)); }
ghost_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d soubor=ghost-ghost-export.json -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0 -d "site_url=$SRC"; src_batch ghost-ghost-export.json; }
ghost_run
grep -q "The content import is finished\|Import obsahu je hotový" "$WORK/response" && echo "  ok     Ghost: the import finished in one batch" || { echo "  CHYBA  Ghost import did not finish"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "Ghost: posts as news items with status and date, the primary tag as the category, the other tag as a tag, SEO fields" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', k.nazev, ':', IFNULL((SELECT GROUP_CONCAT(s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.seo_titulek) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n JOIN ka_kategorie k ON k.idt = n.tema WHERE n.seo_link IN ('firing-the-first-kiln', 'glaze-recipes-we-keep', 'spring-market')")" \
  "firing-the-first-kiln:1:2024-03-10:Workshop:Glazes:Firing the first kiln – Clay Notes|glaze-recipes-we-keep:0:$(site_date today):Glazes:-:|spring-market:1:2099-05-01:Nezařazené:-:"
expect "Ghost: the page is a published build outside the menu with the excerpt as its description" "$(sq "SELECT CONCAT(zobrazit, ':', v_menu, ':', stavba IS NOT NULL, ':', popis) FROM ka_stranky WHERE seo_link = 'about-the-workshop'")" "1:0:1:Who we are and when we are open."
curl -s -o "$WORK/response" "$B/novinky/firing-the-first-kiln"; grep -q "podvrh" "$WORK/response" && { echo "  CHYBA  Ghost: the script from the html card got through"; ERRORS=$((ERRORS+1)); } || echo "  ok     Ghost: the html card's script is cleaned out"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/firing-the-first-kiln/"); expect "Ghost: the old address /slug/ redirects to the news item" "$code" "301 $B/novinky/firing-the-first-kiln"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-the-workshop"); expect "Ghost: the old page address is the new one (no redirect needed)" "$code" "200 "
# images: the featured image and the image in the text come from the entered site address
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d soubor=ghost-ghost-export.json
for i in $(seq 1 10); do src_batch ghost-ghost-export.json; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Ghost: the featured image is in Media and the text refers to the copy there (the link to another old post stays – the redirect catches it)" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%', ':', text LIKE '%<img src=\"http://127.0.0.1%', ':', text LIKE '%<a href=\"http://127.0.0.1%') FROM ka_novinky WHERE seo_link = 'firing-the-first-kiln'")" "1:1:0:1"
# a second run of the same file adds nothing
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d soubor=ghost-ghost-export.json
src_batch ghost-ghost-export.json; ghost_run
expect "Ghost: a second import skips everything" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'firing-the-first-kiln%' OR seo_link LIKE 'glaze-recipes%' OR seo_link LIKE 'spring-market%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'about-the-workshop%'), '/', (SELECT COUNT(*) FROM ka_kategorie WHERE nazev = 'Workshop'))")" "3/1/1"
grep -q 'dlazdice-polozka"><strong>4</strong><span>Skipped\|<strong>4</strong><span>Přeskočeno' "$WORK/response" && echo "  ok     Ghost: the result shows 4 skipped" || { echo "  CHYBA  Ghost: skipped count"; ERRORS=$((ERRORS+1)); }
# Blogger: the Atom export names the blog's address; labels → tags, the comment is skipped, the draft hidden
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_upload" -F "_csrf=$TOKEN" -F system=blogger -F "soubor=@$WORK/blogger-export.xml"
src_batch blogger-blogger-export.xml
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=blogger-blogger-export.xml"
contains -q 'Comments are skipped\|Komentáře se vynechávají' "$WORK/response" && ! contains -q 'name="site_url"' "$WORK/response" && contains -q 'Planting the first beds' "$WORK/response" && echo "  ok     Blogger: the preview says comments are skipped and knows the blog's address" || { echo "  CHYBA  Blogger preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d soubor=blogger-blogger-export.xml -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0
src_batch blogger-blogger-export.xml
expect "Blogger: the post with its labels as tags and date, the draft hidden, the comment not imported" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', IFNULL((SELECT GROUP_CONCAT(s.nazev ORDER BY s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-')) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n WHERE n.seo_link IN ('planting-first-beds', 'compost-notes') OR n.titulek LIKE 'Lovely%'")" \
  "planting-first-beds:1:2019-05-14:Spring,Vegetables|compost-notes:0:2024-06-01:Compost"
check "Blogger: the page" 200 /about-this-diary "slugs eat first"
curl -s -o "$WORK/response" "$B/novinky/planting-first-beds"; grep -q "podvrh" "$WORK/response" && { echo "  CHYBA  Blogger: the script got through"; ERRORS=$((ERRORS+1)); } || echo "  ok     Blogger: the script in the post is cleaned out, the paragraphs are made"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2019/05/planting-first-beds.html"); expect "Blogger: the old /2019/05/slug.html address redirects" "$code" "301 $B/novinky/planting-first-beds"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d soubor=blogger-blogger-export.xml
for i in $(seq 1 10); do src_batch blogger-blogger-export.xml; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Blogger: the image in the text and the thumbnail at full size are in Media (any public host)" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%') FROM ka_novinky WHERE seo_link = 'planting-first-beds'")" "1:1"
expect "the imports are recorded in ka_import_mapa under their own source labels" "$(sq "SELECT GROUP_CONCAT(DISTINCT zdroj ORDER BY zdroj) FROM ka_import_mapa WHERE zdroj LIKE 'ghost:%' OR zdroj LIKE 'blogger:%'")" "blogger:127.0.0.1,ghost:127.0.0.1"
check "the sources folder is not accessible from the web" 403 /storage/import/sources/ghost-ghost-export.json
kill "$SRC_PID" 2>/dev/null || true

echo "== url_slash: the preferred URL form (#14) – pages follow it, the Claude connection and system addresses never move"
url_slash() { mcp update_settings "{\"settings\":{\"url_slash\":\"$1\"}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-this-diary/"); expect "url_slash bez (default): /about-this-diary/ redirects to /about-this-diary" "$code" "301 $B/about-this-diary"
url_slash s
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-this-diary?x=1"); expect "url_slash s: /about-this-diary redirects to /about-this-diary/ with its query" "$code" "301 $B/about-this-diary/?x=1"
check "url_slash s: /about-this-diary/ is the page and its canonical URL" 200 /about-this-diary/ "rel=\"canonical\" href=\"$B/about-this-diary/\""
SITEMAP_CODE=$(curl -s -o "$WORK/sitemap.xml" -w '%{http_code}' "$B/sitemap.xml")
contains -q "<loc>$B/about-this-diary/</loc>" "$WORK/sitemap.xml" && echo "  ok     url_slash s: the sitemap uses /about-this-diary/" \
  || { echo "  CHYBA  url_slash s: sitemap (HTTP $SITEMAP_CODE; the page: $(sq "SELECT CONCAT_WS('|', ids, jazyk, zobrazit, noindex, smazano IS NULL) FROM ka_stranky WHERE seo_link = 'about-this-diary'"))"; head -c 1200 "$WORK/sitemap.xml"; echo; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2019/05/planting-first-beds.html"); expect "url_slash s: an old imported .html address redirects in one step" "$code" "301 $B/novinky/planting-first-beds/"
for mode in s html; do
  url_slash "$mode"
  codes=""; for p in /.well-known/oauth-protected-resource /.well-known/oauth-protected-resource/mcp /.well-known/oauth-authorization-server /.well-known/openid-configuration; do codes="$codes$(curl -s -o /dev/null -w '%{http_code}' "$B$p") "; done
  expect "url_slash $mode: the OAuth discovery of the Claude connection is answered, never redirected" "$codes" "200 200 200 200 "
  expect "url_slash $mode: /mcp, /ulohy and the subscription link are not redirected" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') $(curl -s -o /dev/null -w '%{redirect_url}' "$B/ulohy")$(curl -s -o /dev/null -w '%{redirect_url}' "$B/odber?confirm=x")$(curl -s -o /dev/null -w '%{redirect_url}' "$B/tasks")$(curl -s -o /dev/null -w '%{redirect_url}' "$B/subscription?confirm=x")" "200 "
done
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-this-diary/"); expect "url_slash html: /about-this-diary/ redirects to /about-this-diary.html" "$code" "301 $B/about-this-diary.html"
check "url_slash html: /about-this-diary.html is the page and its canonical URL" 200 /about-this-diary.html "rel=\"canonical\" href=\"$B/about-this-diary.html\""
# 3.4.2 N34-1: a path with two leading slashes or a backslash is never redirected off the site, in every URL form
for mode in bez s html; do
  url_slash "$mode"; off=""
  for p in "//a//evil.example/x/" "/%5Cevil.example/x/" "/\\evil.example/x/"; do
    loc=$(curl -s --path-as-is -o /dev/null -w '%{redirect_url}' "$B$p")
    [ -z "$loc" ] || [ "${loc#"$B"/}" != "$loc" ] || off="$off $p -> $loc"
  done
  [ -z "$off" ] && echo "  ok     3.4.2 N34-1: url_slash $mode keeps every redirect on the site" || { echo "  CHYBA  3.4.2 N34-1: url_slash $mode redirects off the site:$off"; ERRORS=$((ERRORS+1)); }
done
# 3.7 N37-6: the English system words are system addresses in every form – /tasks.html is no page URL, like /ulohy.html; a
# page that holds the word from before 3.7 keeps following the setting like any other page
url_slash bez
expect "3.7 N37-6: url_slash bez never redirects /tasks.html or /subscription.html (as /ulohy.html)" \
  "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/tasks.html?token=testtoken123")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/subscription.html")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/ulohy.html?token=testtoken123")" "404 |404 |404 "
url_slash html
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, v_menu, zmeneno) VALUES ('Form 37', 'form', '<p>Our form page 37</p>', 1, 0, '$(site_time)')" > /dev/null
expect "3.7 N37-6: a page that holds /form keeps the url_slash form of a page" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/form")|$(curl -s "$B/form.html" | grep -c 'Our form page 37' || true)" "301 $B/form.html|1"
sq "DELETE FROM ka_stranky WHERE titulek = 'Form 37' AND seo_link = 'form'" > /dev/null
url_slash bez
echo "== 3.0: online booking of appointments"
# mail must fail here, so every e-mail keeps its body in the queue (the cancel link is read from it); the token for cron is known
BK_MONTHS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '1'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('tasks_token', 'testtoken123'), ('enquiries_months', '24')" > /dev/null
# 3.2: Bookings are a feature, off on a new installation – nothing of it answers until it is switched on
check "3.2 bookings off: no admin module" 403 "/admin.php?module=bookings"
mcp save_booking_service '{"name":"Off test"}' > "$WORK/response"
contains -q 'switched off on this site' "$WORK/response" && expect "3.2 bookings off: the MCP tools say the feature is off and save nothing" "$(sq "SELECT COUNT(*) FROM ka_booking_services")" 0 || { echo "  CHYBA  a booking tool while the feature is off"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "3.2 bookings off: the public booking addresses are a 404" 404 "/_booking/days?service=1&staff=0&month=2026-01"
# switched on the way Claude is told to: update_settings with "bookings" added to extensions
BK_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$BK_EXT" | sed 's/,/","/g')\",\"bookings\"]}}" > /dev/null
expect "3.2 bookings: switched on over MCP" "$(sq "SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'")" 1
# 3.5 (UXA-08): one set-up order everywhere – a person, a service, a Book page – and no silent empty calendar
check "3.5 booking: the set-up card while nothing is set up" 200 "/admin.php?module=bookings" 'id="rezervace-nastaveni"'
grep -q 'action=staff_edit"><strong>Přidat člověka' "$WORK/response" && grep -q 'action=book_page' "$WORK/response" && grep -q '0 / 3' "$WORK/response" \
  && echo "  ok     3.5 booking: the card starts with the person and offers the Book page" || { echo "  CHYBA  3.5 booking: set-up card steps"; ERRORS=$((ERRORS+1)); }
check "3.5 booking: Services without anybody lead to adding a person first" 200 "/admin.php?module=bookings&action=services" 'Nejdřív přidejte člověka'
check "3.5 booking: People without anybody have the empty state with New person" 200 "/admin.php?module=bookings&action=staff" 'prazdny-stav'
check "3.5 booking: Features link to the set-up while it is incomplete" 200 "/admin.php?module=extensions" 'Nastavit rezervace →'
BK_HOURS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_hours', '')" > /dev/null
check "3.5 booking: a new person on a site without opening hours starts Mon–Fri 9–17" 200 "/admin.php?module=bookings&action=staff_edit" 'name="hours_5" maxlength="100" value="9:00–17:00"'
grep -q 'name="hours_6" maxlength="100" value=""' "$WORK/response" && echo "  ok     3.5 booking: the weekend stays empty" || { echo "  CHYBA  3.5 booking: weekend hours"; ERRORS=$((ERRORS+1)); }
# a person whose hours were cleared, offering a service: nothing to book – the screen and Claude are told why
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=staff_save" -d "_csrf=$(csrf)" -d id=0 --data-urlencode "name=Bez hodin" -d active=1
BK_EMPTY_STAFF=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Bez hodin'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=service_save" -d "_csrf=$(csrf)" -d id=0 --data-urlencode "name=Bez času" -d duration_min=30 -d active=1 -d "staff[]=$BK_EMPTY_STAFF"
check "3.5 booking: no free time in 14 days is a warning that names the person" 200 "/admin.php?module=bookings" 'Bez hodin nemá týdenní hodiny a web nemá otevírací dobu'
grep -q '2 / 3' "$WORK/response" && echo "  ok     3.5 booking: the card counts the person and the service as done" || { echo "  CHYBA  3.5 booking: card progress"; ERRORS=$((ERRORS+1)); }
mcp booking_availability '{}' > "$WORK/response"; mcp_text
contains -q '"warning":"No free time in the next 14 days: Bez hodin has no weekly hours' "$WORK/text" && echo "  ok     3.5 booking: booking_availability tells Claude why nothing can be booked" || { echo "  CHYBA  3.5 booking_availability warning"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=bookings"
BK_PAGE_URL=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=bookings&action=book_page" -d "_csrf=$(csrf)")
BK_BOOK_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE stavba_koncept LIKE '%\"typ\":\"rezervace\"%' ORDER BY ids DESC LIMIT 1")
expect "3.5 booking: Create a Book page makes a hidden page with the Booking element and opens the builder" \
  "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', titulek) FROM ka_stranky WHERE ids = ${BK_BOOK_PAGE:-0}")|${BK_PAGE_URL##*action=}" "0/1/Rezervace|builder&id=$BK_BOOK_PAGE"
check "3.5 booking: with the three steps done the card is gone" 200 "/admin.php?module=bookings" 'Rezervace'
grep -q 'id="rezervace-nastaveni"' "$WORK/response" && { echo "  CHYBA  3.5 booking: the card stayed"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.5 booking: the card goes once the set-up is complete"
sq "DELETE FROM ka_stranky WHERE ids = ${BK_BOOK_PAGE:-0}; DELETE FROM ka_booking_services WHERE name = 'Bez času'; DELETE FROM ka_booking_staff WHERE name = 'Bez hodin'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_hours', '$BK_HOURS')" > /dev/null
mcp save_booking_service '{"name":"Střih test","duration_min":30,"buffer_min":10,"price_text":"450 Kč","description":"Mytí, střih, foukaná"}' > "$WORK/response"; mcp_text
BK_SERVICE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp save_booking_staff "{\"name\":\"Jana Rezervace\",\"email\":\"jana-bk@example.cz\",\"services\":[${BK_SERVICE:-0}],\"hours\":{\"monday\":\"9:00-17:00\",\"tuesday\":\"9:00-17:00\",\"wednesday\":\"9:00-17:00\",\"thursday\":\"9:00-17:00\",\"friday\":\"9:00-17:00\",\"saturday\":\"9:00-17:00\",\"sunday\":\"9:00-17:00\"}}" > "$WORK/response"; mcp_text
BK_STAFF=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
[ -n "$BK_SERVICE" ] && [ -n "$BK_STAFF" ] && contains -q '"7":\[\["09:00","17:00"\]\]' "$WORK/text" && echo "  ok     booking: Claude sets up a service and a person with weekly hours" || { echo "  CHYBA  save_booking_service / save_booking_staff"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp save_booking_staff '{"name":"Nikdo","hours":{"monday":"17-9"}}' > "$WORK/response"
contains -q 'ranges' "$WORK/response" && echo "  ok     booking: wrong hours are refused" || { echo "  CHYBA  hours validation"; ERRORS=$((ERRORS+1)); }
# One person, hours per service – the second service only on Saturday afternoons, the first keeps the weekly hours
mcp save_booking_service '{"name":"Wochenende test","duration_min":60}' > "$WORK/response"; mcp_text
BK_SERVICE2=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp save_booking_staff "{\"id\":${BK_STAFF:-0},\"services\":[${BK_SERVICE:-0},${BK_SERVICE2:-0}],\"service_hours\":{\"${BK_SERVICE2:-0}\":{\"saturday\":\"13:00-15:00\"}}}" > "$WORK/response"; mcp_text
BK_SAT=$(php -r 'echo date("Y-m-d", strtotime("next saturday +7 days"));'); BK_MON=$(php -r 'echo date("Y-m-d", strtotime("next monday +7 days"));')
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE2&staff=0&day=$BK_SAT"
grep -q '"13:00"' "$WORK/response" && grep -q '"14:00"' "$WORK/response" && ! grep -q '"09:00"' "$WORK/response" && ! grep -q '"15:00"' "$WORK/response" && echo "  ok     booking: a service with its own hours is offered only then" || { echo "  CHYBA  hours per service"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE2&staff=0&day=$BK_MON"
! grep -q '"[0-9][0-9]:[0-9][0-9]"' "$WORK/response" && echo "  ok     booking: ... and not on the other days of the person's weekly hours" || { echo "  CHYBA  hours per service on a weekday"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_SAT"
grep -q '"09:00"' "$WORK/response" && echo "  ok     booking: the other service keeps the general weekly hours" || { echo "  CHYBA  general hours after hours per service"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_booking_services WHERE id = ${BK_SERVICE2:-0}" > /dev/null
expect "booking: removing a service removes its own hours" "$(sq "SELECT COUNT(*) FROM ka_booking_hours WHERE service_id IS NOT NULL")" "0"
mcp vytvor_stranku '{"titulek":"Rezervace test","adresa":"rezervace-test","zobrazit":true}' > "$WORK/response"; mcp_text; BK_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":${BK_PAGE:-0},\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h1\",\"obsah\":{\"text\":\"Objednejte se\"}},{\"id\":\"bk1\",\"typ\":\"rezervace\",\"obsah\":{}}]}]}}" > /dev/null
curl -s -o "$WORK/booking.html" "$B/rezervace-test"
grep -q 'class="ka-rezervace"' "$WORK/booking.html" && grep -q 'data-rezervace="bk1"' "$WORK/booking.html" && grep -q 'Střih test' "$WORK/booking.html" && grep -q 'name="as_podpis"' "$WORK/booking.html" && grep -q 'name="slot" required' "$WORK/booking.html" && grep -q 'image/web.js' "$WORK/booking.html" \
  && echo "  ok     booking: the element renders the service, the plain select of free times, the spam protection and keeps web.js" || { echo "  CHYBA  booking element"; ERRORS=$((ERRORS+1)); }
BK_DAY=$(site_time "+3 days" "Y-m-d")
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
grep -q '"09:00"' "$WORK/response" && grep -q '"16:30"' "$WORK/response" && ! grep -q '"17:00"' "$WORK/response" && echo "  ok     booking: /_booking/slots returns the free times of the day" || { echo "  CHYBA  /_booking/slots"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/_booking/days?service=$BK_SERVICE&staff=0&month=${BK_DAY:0:7}"
grep -q "\"$BK_DAY\"" "$WORK/response" && echo "  ok     booking: /_booking/days lists the day among the days with free times" || { echo "  CHYBA  /_booking/days"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
bk_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/booking.html" | head -1 | sed 's/.*value="//;s/"$//'; }
BK_SOURCE=$(bk_field zdroj); BK_TIME=$(( $(date +%s) - 10 )); BK_SIGNATURE=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "rezervace|$BK_SOURCE|bk1|$BK_TIME" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")
book() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/_booking" -d "zdroj=$BK_SOURCE" -d prvek=bk1 -d zpet=/rezervace-test -d "as_cas=$BK_TIME" -d "as_podpis=$BK_SIGNATURE" -d "service=$BK_SERVICE" -d staff=0 "$@"; }
case "$(book --data-urlencode "slot=$BK_DAY 10:00" --data-urlencode "jmeno=Petr Rezervující" -d email=petr-bk@example.cz -d telefon=+420777000111 -d poznamka=Test -d souhlas=1)" in *result=ok*) echo "  ok     booking: a visitor books a time";; *) echo "  CHYBA  booking POST"; ERRORS=$((ERRORS+1));; esac
expect "booking: saved as confirmed for the person, with the end time by the duration" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(staff_id), '|', MAX(TIME(ends_at))) FROM ka_bookings WHERE email = 'petr-bk@example.cz' AND status = 'confirmed'")" "1|$BK_STAFF|10:30:00"
expect "booking: the confirmation went to the customer and the notification to the person" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_posta WHERE komu = 'petr-bk@example.cz'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Nová rezervace%'))")" "1|1"
case "$(book --data-urlencode "slot=$BK_DAY 10:00" -d jmeno=Druhy -d email=druhy-bk@example.cz -d souhlas=1)" in *result=obsazeno*) echo "  ok     booking: the same time cannot be booked twice";; *) echo "  CHYBA  double booking"; ERRORS=$((ERRORS+1));; esac
# PR #16 renamed the booking form's fields to service / staff: a form opened or cached before the update posts sluzba / osoba
# and must still reach the same service (the taken time proves the service was read, nothing new is booked)
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/_booking" -d "zdroj=$BK_SOURCE" -d prvek=bk1 -d zpet=/rezervace-test -d "as_cas=$BK_TIME" -d "as_podpis=$BK_SIGNATURE" -d "sluzba=$BK_SERVICE" -d osoba=0 --data-urlencode "slot=$BK_DAY 10:00" -d jmeno=Stary -d email=stary-bk@example.cz -d souhlas=1)" in *result=obsazeno*) echo "  ok     booking: a form with the old field names (sluzba, osoba) still books the same service";; *) echo "  CHYBA  booking with the old field names"; ERRORS=$((ERRORS+1));; esac
expect "booking: the second attempt saved nothing" "$(sq "SELECT COUNT(*) FROM ka_bookings WHERE starts_at = '$BK_DAY 10:00:00'")" "1"
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
! grep -q '"10:00"' "$WORK/response" && ! grep -q '"09:30"' "$WORK/response" && ! grep -q '"10:30"' "$WORK/response" && grep -q '"11:00"' "$WORK/response" && echo "  ok     booking: the booked time and the buffer around it are gone from the free times" || { echo "  CHYBA  slots after booking"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
case "$(book --data-urlencode "slot=$BK_DAY 11:00" -d jmeno=Petr -d email=petr-bk@example.cz -d souhlas=)" in *result=souhlas*) echo "  ok     booking: without the consent nothing is saved";; *) echo "  CHYBA  consent check"; ERRORS=$((ERRORS+1));; esac
# the queued body is JSON (slashes escaped), and the mysql client escapes the backslashes once more on output
bk_token() { sq "SELECT telo FROM ka_posta WHERE komu = 'petr-bk@example.cz' ORDER BY idp $1 LIMIT 1" | php -r '$t = json_decode(str_replace("\\\\", "\\", file_get_contents("php://stdin")), true); preg_match("#_booking\\\\?/cancel\\\\?/([a-f0-9]{32})#", (string) ($t["text"] ?? ""), $m); echo $m[1] ?? "";'; }
BK_TOKEN=$(bk_token ASC)
[ -n "$BK_TOKEN" ] && echo "  ok     booking: the confirmation carries the cancel link" || { echo "  CHYBA  cancel link in the e-mail"; ERRORS=$((ERRORS+1)); }
check "booking: the .ics file for the customer's calendar" 200 "/_booking/ics/${BK_TOKEN:-0000000000000000000000000000000a}" "BEGIN:VEVENT"
check "booking: the cancel page asks before it cancels" 200 "/_booking/cancel/${BK_TOKEN:-0000000000000000000000000000000a}" "Zrušit termín?"
expect "booking: opening the link cancels nothing" "$(sq "SELECT status FROM ka_bookings WHERE email = 'petr-bk@example.cz'")" "confirmed"
bk_cancel() { curl -s -o "$WORK/response" -w '%{http_code}' -X POST -d zrusit=1 "$B/_booking/cancel/${1:-0000000000000000000000000000000a}"; }
code=$(bk_cancel "$BK_TOKEN"); [ "$code" = 200 ] && grep -q 'Váš termín je zrušen' "$WORK/response" && echo "  ok     booking: the customer cancels before the deadline" || { echo "  CHYBA  cancel by the customer: kód $code"; ERRORS=$((ERRORS+1)); }
expect "booking: cancelled by the customer, the person was told" "$(sq "SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Zrušená rezervace%')) FROM ka_bookings WHERE email = 'petr-bk@example.cz'")" "cancelled|customer|1"
case "$(book --data-urlencode "slot=$BK_DAY 11:00" --data-urlencode "jmeno=Petr Rezervující" -d email=petr-bk@example.cz -d souhlas=1)" in *result=ok*) echo "  ok     booking: a second appointment";; *) echo "  CHYBA  second booking"; ERRORS=$((ERRORS+1));; esac
BK_TOKEN2=$(bk_token DESC)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_cancel_hours', '200')" > /dev/null
code=$(bk_cancel "$BK_TOKEN2"); [ "$code" = 200 ] && grep -q 'už nelze zrušit online' "$WORK/response" && echo "  ok     booking: after the deadline the link refuses to cancel" || { echo "  CHYBA  cancel after the deadline: kód $code"; ERRORS=$((ERRORS+1)); }
expect "booking: the appointment stays confirmed" "$(sq "SELECT status FROM ka_bookings WHERE starts_at = '$BK_DAY 11:00:00'")" "confirmed"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_cancel_hours', '24'), ('booking_reminder_hours', '100'); UPDATE ka_bookings SET created_at = '$(site_time)' - INTERVAL 10 DAY WHERE starts_at = '$BK_DAY 11:00:00'; DELETE FROM ka_jobs WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "booking: the reminder job sends the reminder once and marks it" "$(sq "SELECT CONCAT((SELECT reminded_at IS NOT NULL FROM ka_bookings WHERE starts_at = '$BK_DAY 11:00:00'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'petr-bk@example.cz' AND predmet LIKE 'Připomínka%'))")" "1|1"
sq "UPDATE ka_jobs SET last_run = '$(site_time)' - INTERVAL 2 HOUR WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "booking: the next run sends no second reminder" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'petr-bk@example.cz' AND predmet LIKE 'Připomínka%'")" "1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_reminder_hours', '24')" > /dev/null
mcp list_bookings "{\"from\":\"$BK_DAY\",\"to\":\"$BK_DAY\",\"status\":\"all\"}" > "$WORK/response"; mcp_text
contains -q 'petr-bk@example.cz' "$WORK/text" && contains -q '"count":2' "$WORK/text" && echo "  ok     booking: Claude lists the bookings of the day" || { echo "  CHYBA  list_bookings"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "booking: every read of bookings by Claude is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'list_bookings' AND popis LIKE '2 %'")" "1"
mcp booking_availability "{\"service\":$BK_SERVICE,\"day\":\"$BK_DAY\"}" > "$WORK/response"; mcp_text
contains -q '"slots"' "$WORK/text" && contains -q '"09:00"' "$WORK/text" && ! contains -q 'petr' "$WORK/text" && echo "  ok     booking: booking_availability shows the free times and no personal data" || { echo "  CHYBA  booking_availability"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "booking: the admin list shows the booking by day" 200 "/admin.php?module=bookings" "Petr Rezervující"
BK_ID=$(sq "SELECT id FROM ka_bookings WHERE starts_at = '$BK_DAY 11:00:00'")
check "booking: the admin detail with the customer" 200 "/admin.php?module=bookings&action=detail&id=${BK_ID:-0}" "petr-bk@example.cz"
check "booking: the services screen" 200 "/admin.php?module=bookings&action=services&id=$BK_SERVICE" "Střih test"
check "booking: the person's form with the weekly hours" 200 "/admin.php?module=bookings&action=staff_edit&id=$BK_STAFF" 'name="hours_1"'
check "booking: the manual booking form" 200 "/admin.php?module=bookings&action=new" 'name="den"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=create" -d "_csrf=$(csrf)" -d "sluzba=$BK_SERVICE" -d osoba=0 -d "den=$BK_DAY" -d cas=14:00 --data-urlencode "jmeno=Telefon Zákazník" -d email= -d telefon=777000222
expect "booking: a booking taken by phone, without an e-mail" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(source)) FROM ka_bookings WHERE name = 'Telefon Zákazník' AND status = 'confirmed'")" "1|admin"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=create" -d "_csrf=$(csrf)" -d "sluzba=$BK_SERVICE" -d "osoba=$BK_STAFF" -d "den=$BK_DAY" -d cas=14:00 -d jmeno=Kolize -d email=
expect "booking: the admin cannot double-book either" "$(sq "SELECT COUNT(*) FROM ka_bookings WHERE name = 'Kolize'")" "0"
BK_PHONE=$(sq "SELECT id FROM ka_bookings WHERE name = 'Telefon Zákazník'")
mcp cancel_booking "{\"id\":${BK_PHONE:-0}}" > "$WORK/response"
contains -q 'confirm' "$WORK/response" && echo "  ok     booking: cancel_booking needs an explicit confirmation" || { echo "  CHYBA  cancel_booking without confirm"; ERRORS=$((ERRORS+1)); }
mcp cancel_booking "{\"id\":${BK_PHONE:-0},\"confirm\":true}" > /dev/null
expect "booking: Claude cancels a booking, the change is logged" "$(sq "SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'bookings' AND akce = 'cancel' AND popis = CONCAT('#', ${BK_PHONE:-0}))) FROM ka_bookings WHERE id = ${BK_PHONE:-0}")" "cancelled|claude|1"
mcp find_personal_data '{"email":"petr-bk@example.cz"}' > "$WORK/response"; mcp_text
contains -q '"bookings":2' "$WORK/text" && echo "  ok     booking: a personal data request finds the bookings" || { echo "  CHYBA  find_personal_data bookings"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "booking: the admin's personal data screen links the bookings" 200 "/admin.php?module=enquiries&action=personal" "osobni-email"
mcp erase_personal_data '{"email":"petr-bk@example.cz","confirm":true}' > /dev/null
expect "booking: erased on request, the other person's booking stays" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_bookings WHERE email = 'petr-bk@example.cz'), '|', (SELECT COUNT(*) FROM ka_bookings WHERE name = 'Telefon Zákazník'))")" "0|1"
sq "UPDATE ka_bookings SET ends_at = '$(site_time)' - INTERVAL 30 MONTH, starts_at = '$(site_time)' - INTERVAL 30 MONTH WHERE name = 'Telefon Zákazník'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'anonymise')" > /dev/null
curl -s -b "$JAR" -o /dev/null "$B/admin.php?module=bookings"
expect "booking: past the enquiry retention the booking is anonymised, the row stays" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(name = ''), '|', MAX(anonymised_at IS NOT NULL)) FROM ka_bookings WHERE id = ${BK_PHONE:-0}")" "1|1|1"
# 3.3: a service that needs the provider's confirmation – a request holds the time, the provider accepts, declines or proposes other times
mcp save_booking_service "{\"id\":${BK_SERVICE:-0},\"requires_confirmation\":true}" > /dev/null
expect "3.3 booking: the service needs confirmation" "$(sq "SELECT requires_confirmation FROM ka_booking_services WHERE id = ${BK_SERVICE:-0}")" "1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_pending_mail', 'Ahoj {name}, dostali jsme tvoji zprávu.')" > /dev/null
case "$(book --data-urlencode "slot=$BK_DAY 15:00" --data-urlencode "jmeno=Pavla Žádost" -d email=pavla-bk@example.cz -d souhlas=1)" in *result=pending*) echo "  ok     3.3 booking: a visitor's request comes back as pending";; *) echo "  CHYBA  3.3 pending request"; ERRORS=$((ERRORS+1));; esac
check "3.3 booking: the element thanks for a request, not for a booking" 200 "/rezervace-test?booking=bk1&result=pending" "vaši žádost jsme přijali"
expect "3.3 booking: saved as pending with a hold" "$(sq "SELECT CONCAT(status, '|', hold_until IS NOT NULL) FROM ka_bookings WHERE email = 'pavla-bk@example.cz'")" "pending|1"
expect "3.3 booking: the customer got the acknowledgement in the site's own words, the person the notification, nobody a confirmation" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_posta WHERE komu = 'pavla-bk@example.cz' AND predmet LIKE 'Přijali jsme vaši žádost%' AND telo LIKE '%Ahoj Pavla Žádost, dostali jsme tvoji zprávu.%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Žádost čeká na vaši odpověď%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'pavla-bk@example.cz'))")" "1|1|1"
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
! grep -q '"15:00"' "$WORK/response" && grep -q '"16:00"' "$WORK/response" && echo "  ok     3.3 booking: a pending request holds its time" || { echo "  CHYBA  3.3 hold"; ERRORS=$((ERRORS+1)); }
case "$(book --data-urlencode "slot=$BK_DAY 15:00" -d jmeno=Druha -d email=druha-bk@example.cz -d souhlas=1)" in *result=obsazeno*) echo "  ok     3.3 booking: nobody else can take the held time";; *) echo "  CHYBA  3.3 held time taken"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$B/admin.php?module=bookings&action=list"
check "3.3 booking: the list shows the request and what waits" 200 "/admin.php?module=bookings" "Pavla Žádost"
BK_P1=$(sq "SELECT id FROM ka_bookings WHERE email = 'pavla-bk@example.cz'")
check "3.3 booking: the detail offers accept, decline and other times" 200 "/admin.php?module=bookings&action=detail&id=${BK_P1:-0}" 'id="propose-slots"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=confirm" -d "_csrf=$(csrf)" -d "id=${BK_P1:-0}"
expect "3.3 booking: accepted – confirmed, hold released, the customer got the confirmation with a cancel link" "$(sq "SELECT CONCAT(status, '|', hold_until IS NULL, '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'pavla-bk@example.cz' AND telo LIKE '%_booking%cancel%'))  FROM ka_bookings WHERE id = ${BK_P1:-0}")" "confirmed|1|1"
# decline with a personal message: the time is free again
book --data-urlencode "slot=$BK_DAY 16:00" --data-urlencode "jmeno=Dana Odmítnutá" -d email=dana-bk@example.cz -d souhlas=1 > /dev/null
BK_P2=$(sq "SELECT id FROM ka_bookings WHERE email = 'dana-bk@example.cz'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=bookings&action=detail&id=${BK_P2:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=decline" -d "_csrf=$(csrf)" -d "id=${BK_P2:-0}" --data-urlencode "message=Ve čtvrtek bohužel nejsem na místě."
expect "3.3 booking: declined – the customer is told with the personal message" "$(sq "SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'dana-bk@example.cz' AND telo LIKE '%Ve čtvrtek bohužel nejsem na místě.%'))  FROM ka_bookings WHERE id = ${BK_P2:-0}")" "declined|admin|1"
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
grep -q '"16:00"' "$WORK/response" && echo "  ok     3.3 booking: a declined request frees the time" || { echo "  CHYBA  3.3 declined slot"; ERRORS=$((ERRORS+1)); }
# propose other times over MCP: the customer picks one with the link
book --data-urlencode "slot=$BK_DAY 16:00" --data-urlencode "jmeno=Eva Návrh" -d email=eva-bk@example.cz -d souhlas=1 > /dev/null
BK_P3=$(sq "SELECT id FROM ka_bookings WHERE email = 'eva-bk@example.cz'")
mcp propose_booking_times "{\"id\":${BK_P3:-0},\"times\":[\"$BK_DAY 12:30\"]}" > "$WORK/response"
contains -q 'confirm' "$WORK/response" && echo "  ok     3.3 booking: propose_booking_times needs an explicit confirmation" || { echo "  CHYBA  3.3 propose without confirm"; ERRORS=$((ERRORS+1)); }
mcp propose_booking_times "{\"id\":${BK_P3:-0},\"times\":[\"$BK_DAY 15:00\"],\"confirm\":true}" > "$WORK/response"
contains -q 'not free' "$WORK/response" && echo "  ok     3.3 booking: a time that is not free cannot be proposed" || { echo "  CHYBA  3.3 propose a busy time"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp propose_booking_times "{\"id\":${BK_P3:-0},\"times\":[\"$BK_DAY 12:30\",\"$BK_DAY 16:00\"],\"message\":\"Wie wäre es früher?\",\"confirm\":true}" > /dev/null
expect "3.3 booking: two times proposed – the own held time may be among them, the booking stays pending" "$(sq "SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = ${BK_P3:-0}))  FROM ka_bookings WHERE id = ${BK_P3:-0}")" "pending|2"
BK_CHOOSE=$(sq "SELECT telo FROM ka_posta WHERE komu = 'eva-bk@example.cz' AND telo LIKE '%_booking%choose%' ORDER BY idp DESC LIMIT 1" | php -r '$t = json_decode(str_replace("\\\\", "\\", file_get_contents("php://stdin")), true); preg_match("#_booking\\\\?/choose\\\\?/([a-f0-9]{32})#", (string) ($t["text"] ?? ""), $m); echo $m[1] ?? "";')
[ -n "$BK_CHOOSE" ] && echo "  ok     3.3 booking: the e-mail carries the link to pick a time" || { echo "  CHYBA  3.3 choose link"; ERRORS=$((ERRORS+1)); }
check "3.3 booking: the page lists the proposed times" 200 "/_booking/choose/${BK_CHOOSE:-0000000000000000000000000000000a}" 'name="proposal"'
expect "3.3 booking: opening the link chooses nothing" "$(sq "SELECT status FROM ka_bookings WHERE id = ${BK_P3:-0}")" "pending"
BK_PROPOSAL=$(sq "SELECT id FROM ka_booking_proposals WHERE booking_id = ${BK_P3:-0} AND starts_at LIKE '% 12:30:00'")
curl -s -o "$WORK/response" -X POST -d "proposal=${BK_PROPOSAL:-0}" "$B/_booking/choose/${BK_CHOOSE:-0000000000000000000000000000000a}"
expect "3.3 booking: the customer picks a time – the booking moves there and is confirmed, the person is told" "$(sq "SELECT CONCAT(status, '|', TIME(starts_at), '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = ${BK_P3:-0}), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Zákazník přijal navržený termín%'))  FROM ka_bookings WHERE id = ${BK_P3:-0}")" "confirmed|12:30:00|0|1"
# a request nobody answers: the hold runs out, the provider is reminded once, the customer hears nothing
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'rezervace'" > /dev/null # the limit of five bookings an hour from one address
book --data-urlencode "slot=$BK_DAY 16:00" --data-urlencode "jmeno=Hana Čekající" -d email=hana-bk@example.cz -d souhlas=1 > /dev/null
sq "UPDATE ka_bookings SET hold_until = '$(site_time)' - INTERVAL 1 HOUR WHERE email = 'hana-bk@example.cz'; UPDATE ka_jobs SET last_run = '$(site_time)' - INTERVAL 2 HOUR WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.3 booking: the hold ran out – the provider is reminded, the customer got only the acknowledgement" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Stále čeká na vaši odpověď%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'hana-bk@example.cz'), '|', (SELECT status FROM ka_bookings WHERE email = 'hana-bk@example.cz'))")" "1|1|pending"
sq "UPDATE ka_jobs SET last_run = '$(site_time)' - INTERVAL 2 HOUR WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.3 booking: the reminder goes out once" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Stále čeká na vaši odpověď%'")" "1"
case "$(book --data-urlencode "slot=$BK_DAY 16:00" -d jmeno=Iva -d email=iva-bk@example.cz -d souhlas=1)" in *result=pending*) echo "  ok     3.3 booking: after the hold the time can be requested by someone else";; *) echo "  CHYBA  3.3 expired hold"; ERRORS=$((ERRORS+1));; esac
mcp confirm_booking "{\"id\":$(sq "SELECT id FROM ka_bookings WHERE email = 'iva-bk@example.cz'"),\"confirm\":true}" > /dev/null
expect "3.3 booking: Claude accepts the request that holds the time now" "$(sq "SELECT status FROM ka_bookings WHERE email = 'iva-bk@example.cz'")" "confirmed"
mcp confirm_booking "{\"id\":$(sq "SELECT id FROM ka_bookings WHERE email = 'hana-bk@example.cz'"),\"confirm\":true}" > "$WORK/response"
contains -q 'taken' "$WORK/response" && expect "3.3 booking: a request whose held time ran out and was taken cannot be accepted" "$(sq "SELECT status FROM ka_bookings WHERE email = 'hana-bk@example.cz'")" "pending" || { echo "  CHYBA  3.3 accept a taken time"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_bookings "{\"from\":\"$BK_DAY\",\"to\":\"$BK_DAY\",\"status\":\"pending\"}" > "$WORK/response"; mcp_text
contains -q 'hana-bk@example.cz' "$WORK/text" && ! contains -q 'iva-bk@example.cz' "$WORK/text" && echo "  ok     3.3 booking: list_bookings filters the pending requests" || { echo "  CHYBA  3.3 list pending"; ERRORS=$((ERRORS+1)); }
# the booking settings over MCP: listed in update_settings, checked with the limits of the Bookings settings form
BK_HORIZON=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'booking_horizon_days'")
mcp update_settings '{"settings":{"booking_hold_hours":"5","booking_horizon_days":"9999","booking_pending_mail":"<b>Hallo</b> {name}"}}' > /dev/null
expect "3.3 booking: Claude sets the booking settings with the limits of the settings form" "$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('booking_hold_hours', 'booking_horizon_days', 'booking_pending_mail')")" "booking_hold_hours=5,booking_horizon_days=365,booking_pending_mail=Hallo {name}"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_hold_hours', '48'), ('booking_horizon_days', '${BK_HORIZON:-60}')" > /dev/null
sq "UPDATE ka_booking_services SET requires_confirmation = 0 WHERE id = ${BK_SERVICE:-0}; DELETE FROM ka_nastaveni WHERE promenna = 'booking_pending_mail'; UPDATE ka_bookings SET status = 'cancelled' WHERE email IN ('hana-bk@example.cz', 'iva-bk@example.cz')" > /dev/null
# 3.2: switched off again – the element, the public addresses, the admin module and the tools are gone; the data stays
sq "UPDATE ka_nastaveni SET hodnota = '$BK_EXT' WHERE promenna = 'extensions'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/rezervace-test"; ! grep -q 'class="ka-rezervace"' "$WORK/response" && echo "  ok     3.2 bookings off: the Booking element is not on the page" || { echo "  CHYBA  the Booking element with the feature off"; ERRORS=$((ERRORS+1)); }
# 3.2.3: an appointment booked before the switch-off – its cancel and .ics links keep working
BK_OFF_TOKEN=cafe0000cafe0000cafe0000cafe0003
sq "INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, name, email, token_hash, created_at) VALUES (${BK_SERVICE:-0}, ${BK_STAFF:-0}, '$(site_time)' + INTERVAL 10 DAY, '$(site_time)' + INTERVAL 10 DAY + INTERVAL 30 MINUTE, 'Off Customer', 'off-bk@example.cz', SHA2('$BK_OFF_TOKEN', 256), '$(site_time)')" > /dev/null
check "3.2.3 bookings off: the customer's .ics link still works" 200 "/_booking/ics/$BK_OFF_TOKEN" "BEGIN:VEVENT"
check "3.2.3 bookings off: the customer's cancel page still works" 200 "/_booking/cancel/$BK_OFF_TOKEN" "zrusit"
curl -s -o /dev/null -X POST -d zrusit=1 "$B/_booking/cancel/$BK_OFF_TOKEN"
expect "3.2.3 bookings off: the customer can still cancel" "$(sq "SELECT CONCAT(status, '|', cancelled_by) FROM ka_bookings WHERE email = 'off-bk@example.cz'")" "cancelled|customer"
check "3.2.3 bookings off: new bookings are not taken" 404 "/_booking/slots?service=${BK_SERVICE:-0}&staff=0&day=2026-01-05"
check "3.2 bookings off: no admin module" 403 "/admin.php?module=bookings"
mcp list_bookings '{}' > "$WORK/response"
contains -q 'switched off on this site' "$WORK/response" && expect "3.2 bookings off: list_bookings says so; the services and bookings stay" "$(sq "SELECT CONCAT((SELECT COUNT(*) > 0 FROM ka_booking_services), '|', (SELECT COUNT(*) > 0 FROM ka_bookings))")" "1|1" || { echo "  CHYBA  list_bookings with the feature off"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'delete'), ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
if [ -n "$BK_MONTHS" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '$BK_MONTHS')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'enquiries_months'" > /dev/null; fi

echo "== 3.0: importers on the common base – Joomla and Drupal through the fetch step (Import\\Fetch), Webflow CSV"
FAKE="http://127.0.0.1:$FAKE_PORT"; rm -f "$FAKE_LOGS-joomla.log" "$FAKE_LOGS-drupal.log"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
contains -q 'action=source_fetch' "$WORK/response" && contains -q 'name="system" value="joomla"' "$WORK/response" && contains -q 'name="system" value="drupal"' "$WORK/response" && contains -q 'option value="webflow">Webflow' "$WORK/response" && ! contains -q 'option value="joomla"' "$WORK/response" \
  && echo "  ok     import and export: Joomla and Drupal have a fetch form (address, token, steps), Webflow is a file upload" || { echo "  CHYBA  the From another system section"; ERRORS=$((ERRORS+1)); }
# an internal address is refused before anything is requested
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=joomla -d adresa=http://10.0.0.5 -d token=whatever
check "fetch: an internal address is refused" 200 "/admin.php?module=transfer" "not an internal address\|ne vnitřní adresu"
JOOMLA_FILE=joomla-127-0-0-1.json
# Joomla with a wrong token: the fake answers 401, the page shows it clearly, the token is forgotten
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=joomla -d "adresa=$FAKE" -d token=wrong-token-value -d 'kroky[]=categories' -d 'kroky[]=users' -d 'kroky[]=tags'
src_batch "$JOOMLA_FILE"
contains -q 'refused the request.*401\|odmítl.*401' "$WORK/response" && echo "  ok     Joomla: a wrong token is a clear error with the 401" || { echo "  CHYBA  Joomla wrong token"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -rq 'wrong-token-value' "$WORK/web/storage" && { echo "  CHYBA  Joomla: the wrong token was written under storage/"; ERRORS=$((ERRORS+1)); } || echo "  ok     Joomla: the refused token is nowhere under storage/"
# the right token: 6 pages (articles 3, categories, users, tags) in two requests, then the analysis, then the preview
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=joomla -d "adresa=$FAKE" -d token=jm-secret-token -d 'kroky[]=categories' -d 'kroky[]=users' -d 'kroky[]=tags'
src_batch "$JOOMLA_FILE"
contains -q 'Fetching from\|Stahuji z' "$WORK/response" && echo "  ok     Joomla: the fetch uses its page budget and continues in the next request" || { echo "  CHYBA  Joomla fetch progress"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
for i in 1 2 3 4; do src_batch "$JOOMLA_FILE"; done
# 8 calls: the refused attempt, articles paged 0/2/4, categories paged 0/2, users, tags – every one carried a token header
expect "Joomla: the fake saw a token header on every call and was paged through articles with offsets 0, 2, 4 (the first 0 is the refused attempt)" "$(grep -c '"has_token":true' "$FAKE_LOGS-joomla.log")|$(grep -c '"has_token":false' "$FAKE_LOGS-joomla.log")|$(grep 'content/articles' "$FAKE_LOGS-joomla.log" | grep -o '"offset":[0-9]*' | tr '\n' ' ' | sed 's/ $//')|$(grep -c 'content/categories' "$FAKE_LOGS-joomla.log")" "8|0|\"offset\":0 \"offset\":0 \"offset\":2 \"offset\":4|2"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=$JOOMLA_FILE"
contains -q 'SEF URL\|SEF adres' "$WORK/response" && contains -q 'Hello from the bakery' "$WORK/response" && contains -q 'Marta Editor' "$WORK/response" && ! contains -q 'name="site_url"' "$WORK/response" && ! contains -q 'jm-secret-token' "$WORK/response" \
  && echo "  ok     Joomla: the preview warns about SEF addresses, shows the first titles and the authors, knows the site address, never the token" || { echo "  CHYBA  Joomla preview"; ERRORS=$((ERRORS+1)); }
joomla_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d "soubor=$JOOMLA_FILE" -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0; src_batch "$JOOMLA_FILE"; }
joomla_run
expect "Joomla: published, unpublished (hidden), scheduled and archived articles as news items with their categories, tags and metadesc; the trashed one is not imported" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', k.nazev, ':', IFNULL((SELECT GROUP_CONCAT(s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.seo_popis) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n JOIN ka_kategorie k ON k.idt = n.tema WHERE n.seo_link IN ('hello-from-the-bakery', 'unpublished-recipe', 'trashed-note', 'summer-market', 'archived-thoughts')")" \
  "hello-from-the-bakery:1:2024-03-10:News:Sourdough:Opening day of the bakery.|unpublished-recipe:0:2024-04-02:News:-:|summer-market:1:2099-06-01:Blog:Events:|archived-thoughts:1:2023-01-05:Uncategorised:-:"
expect "Joomla: introtext is the intro, fulltext the text, the script is cleaned out" "$(sq "SELECT CONCAT(uvod LIKE '%opened the oven%', ':', text LIKE '%opened the oven%', ':', text LIKE '%first loaves%', ':', text LIKE '%podvrh%') FROM ka_novinky WHERE seo_link = 'hello-from-the-bakery'")" "1:0:1:0"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/blog/news/12-hello-from-the-bakery"); expect "Joomla: the best-guess old address /category-path/id-alias redirects to the news item" "$code" "301 $B/novinky/hello-from-the-bakery"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d "soubor=$JOOMLA_FILE"
for i in $(seq 1 10); do src_batch "$JOOMLA_FILE"; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Joomla: the featured image (images/… made absolute) and the image in the text are in Media" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%', ':', text LIKE '%<img src=\"http://127.0.0.1%') FROM ka_novinky WHERE seo_link = 'hello-from-the-bakery'")" "1:1:0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d "soubor=$JOOMLA_FILE"
src_batch "$JOOMLA_FILE"; joomla_run
expect "Joomla: a second import of the fetched file adds nothing" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'hello-from-the-bakery%' OR seo_link LIKE 'summer-market%' OR seo_link LIKE 'unpublished-recipe%' OR seo_link LIKE 'archived-thoughts%'), '/', (SELECT COUNT(*) FROM ka_kategorie WHERE nazev IN ('News', 'Blog')))")" "4/2"
grep -q 'dlazdice-polozka"><strong>4</strong><span>Skipped\|<strong>4</strong><span>Přeskočeno' "$WORK/response" && echo "  ok     Joomla: the result shows 4 skipped" || { echo "  CHYBA  Joomla: skipped count"; ERRORS=$((ERRORS+1)); }
# the token never lands anywhere: settings, the change log, files under storage/ (the fetched file and the state), the pages shown
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_progress&file=$JOOMLA_FILE"
expect "Joomla: the token is in neither ka_nastaveni nor ka_protokol" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%jm-secret-token%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%jm-secret-token%' OR akce LIKE '%jm-secret-token%' OR duvod LIKE '%jm-secret-token%'))")" "0|0"
grep -rq 'jm-secret-token' "$WORK/web/storage" "$WORK/response" && { echo "  CHYBA  Joomla: the token is in a file under storage/ or on a page"; grep -rl 'jm-secret-token' "$WORK/web/storage" "$WORK/response"; ERRORS=$((ERRORS+1)); } || echo "  ok     Joomla: the token is in no file under storage/ and on no page"
# Drupal: wrong credentials → 401; signed in, the unpublished node comes too; the tags endpoint is missing and skipped
DRUPAL_FILE=drupal-127-0-0-1.json
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=drupal -d "adresa=$FAKE/" -d token=drupal:wrong-pass -d 'kroky[]=pages' -d 'kroky[]=tags'
src_batch "$DRUPAL_FILE"
contains -q 'refused the request.*401\|odmítl.*401' "$WORK/response" && echo "  ok     Drupal: wrong credentials are a clear error with the 401" || { echo "  CHYBA  Drupal wrong credentials"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=drupal -d "adresa=$FAKE" -d token=drupal:dr-pass -d 'kroky[]=pages' -d 'kroky[]=tags'
for i in 1 2 3; do src_batch "$DRUPAL_FILE"; done
expect "Drupal: the fake saw the right Basic auth on the 4 calls after the refused one, articles paged with offsets 0 and 2, the tags endpoint asked once" "$(grep -c '"signed_in":true' "$FAKE_LOGS-drupal.log")|$(grep -c '"signed_in":false' "$FAKE_LOGS-drupal.log")|$(grep 'node/article' "$FAKE_LOGS-drupal.log" | grep -o '"offset":[0-9]*' | tr '\n' ' ' | sed 's/ $//')|$(grep -c 'taxonomy_term/tags' "$FAKE_LOGS-drupal.log")" "4|1|\"offset\":0 \"offset\":0 \"offset\":2|1"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=$DRUPAL_FILE"
contains -q 'does not offer tags\|nenabízí tags' "$WORK/response" && contains -q 'Hello from Drupal' "$WORK/response" && contains -q 'About the bakery' "$WORK/response" && ! contains -q 'dr-pass' "$WORK/response" \
  && echo "  ok     Drupal: the preview notes the skipped tags step, shows the article and the page titles, never the credentials" || { echo "  CHYBA  Drupal preview"; ERRORS=$((ERRORS+1)); }
drupal_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d "soubor=$DRUPAL_FILE" -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0; src_batch "$DRUPAL_FILE"; }
drupal_run
expect "Drupal: articles as news items with tags from the included terms, the metatag description, the unpublished one hidden" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', IFNULL((SELECT GROUP_CONCAT(s.nazev ORDER BY s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.seo_popis) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n WHERE n.seo_link IN ('hello-from-drupal', 'second-post', 'draft-post')")" \
  "hello-from-drupal:1:2024-03-10:Sourdough:Welcome text for the search engines.|second-post:1:2024-04-01:Events,Sourdough:|draft-post:0:2024-05-01:-:"
expect "Drupal: the basic page is a published build outside the menu; the script is cleaned out of the article" "$(sq "SELECT CONCAT((SELECT CONCAT(zobrazit, ':', v_menu, ':', stavba IS NOT NULL) FROM ka_stranky WHERE seo_link = 'about'), ':', (SELECT text LIKE '%podvrh%' FROM ka_novinky WHERE seo_link = 'hello-from-drupal'))")" "1:0:1:0"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/blog/hello-from-drupal"); expect "Drupal: the path alias redirects to the news item" "$code" "301 $B/novinky/hello-from-drupal"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about"); expect "Drupal: the page keeps its alias as the new address" "$code" "200 "
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d "soubor=$DRUPAL_FILE"
for i in $(seq 1 10); do src_batch "$DRUPAL_FILE"; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Drupal: the field_image file and the image in the body are in Media" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%') FROM ka_novinky WHERE seo_link = 'hello-from-drupal'")" "1:1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d "soubor=$DRUPAL_FILE"
src_batch "$DRUPAL_FILE"; drupal_run
expect "Drupal: a second import adds nothing" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'hello-from-drupal%' OR seo_link LIKE 'second-post%' OR seo_link LIKE 'draft-post%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('about', 'about-2')))")" "3/1"
grep -rq 'dr-pass' "$WORK/web/storage" && { echo "  CHYBA  Drupal: the credentials are in a file under storage/"; ERRORS=$((ERRORS+1)); } || echo "  ok     Drupal: the credentials are in no file under storage/"
# Webflow: the CSV of a collection; the admin enters the collection's address with its folder for the old addresses
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_upload" -F "_csrf=$TOKEN" -F system=webflow -F "soubor=@$ROOT/tools/fixtures/webflow-blog.csv"
src_batch webflow-webflow-blog.csv
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=webflow-webflow-blog.csv"
contains -q 'name="site_url"' "$WORK/response" && contains -q 'From a live website\|Z běžícího webu' "$WORK/response" && contains -q 'Spring sourdough' "$WORK/response" && echo "  ok     Webflow: the preview asks for the collection address, points static pages to the URL importer, shows the first titles" || { echo "  CHYBA  Webflow preview"; ERRORS=$((ERRORS+1)); }
webflow_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d soubor=webflow-webflow-blog.csv -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0 -d "site_url=$B/blog/"; src_batch webflow-webflow-blog.csv; }
webflow_run
expect "Webflow: the rows as news items – the summary as the intro, the category and the tags from the reference slugs, the draft and the archived one hidden" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', k.nazev, ':', IFNULL((SELECT GROUP_CONCAT(s.nazev ORDER BY s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.uvod LIKE '%spring recipe%', ':', n.text LIKE '%podvrh%') ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n JOIN ka_kategorie k ON k.idt = n.tema WHERE n.seo_link IN ('spring-sourdough', 'market-day', 'old-news')")" \
  "spring-sourdough:1:2024-03-05:Recipes:Sourdough,Spring:1:0|market-day:0:2024-04-01:Events:Events:0:0|old-news:0:2024-01-10:Recipes:-:0:0"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/blog/spring-sourdough"); expect "Webflow: the old address under the collection folder redirects to the news item" "$code" "301 $B/novinky/spring-sourdough"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d soubor=webflow-webflow-blog.csv
src_batch webflow-webflow-blog.csv; webflow_run
expect "Webflow: a second import adds nothing" "$(sq "SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'spring-sourdough%' OR seo_link LIKE 'market-day%' OR seo_link LIKE 'old-news%'")" "3"
expect "the three imports are recorded in ka_import_mapa under their own source labels" "$(sq "SELECT GROUP_CONCAT(DISTINCT zdroj ORDER BY zdroj) FROM ka_import_mapa WHERE zdroj LIKE 'joomla:%' OR zdroj LIKE 'drupal:%' OR zdroj LIKE 'webflow:%'")" "drupal:127.0.0.1,joomla:127.0.0.1,webflow:127.0.0.1"
check "the fetched file is not accessible from the web" 403 "/storage/import/sources/$JOOMLA_FILE"

echo "== 3.9: the same address in every language version (slugs_per_language, data model §3 step 1) – a bilingual WordPress site moves without renaming"
# a site with the English version, redirects and news on; the home page is the news list for this part (hreflang then
# needs no translated home page), restored at the end
EXT39=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
for e in jazyky presmerovani novinky; do case ",$EXT39," in *",$e,"*) ;; *) EXT39="$EXT39,$e";; esac; done
HOME39=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$EXT39'), ('additional_languages', 'en'), ('home_page', '0')" > /dev/null
keys39() { sq "SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ka_stranky', 'ka_novinky', 'ka_kategorie') AND INDEX_NAME LIKE 'uq_%seo' AND SEQ_IN_INDEX = 1"; }
get39() { rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" -w '%{http_code} %{redirect_url}' "$B$1"; }
general39() { # Settings → General saved as a browser does (every field as it is), with the switch on (1) or off (0)
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/general39.html" "$B/admin.php?module=settings&tab=general"
  php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
    foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $n === "slugs_per_language" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
      $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
    echo implode("&", $q), $argv[2] === "1" ? "&slugs_per_language=1" : "";' "$WORK/general39.html" "$1" > "$WORK/general39.post"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general39.post"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/general39.html" "$B/admin.php?module=settings&tab=general"
}
expect "3.9: a site from before keeps its global slug keys next to the per-language ones (migration 0083), the switch is off" \
  "$(keys39)|$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '0')")" \
  "uq_clanky_jazyk_seo,uq_clanky_seo,uq_stranky_jazyk_seo,uq_stranky_seo,uq_topic_jazyk_seo,uq_topic_seo|0"
mcp create_page '{"title":"Kontakt","slug":"kontakt-39","content":"<p>Česky 39</p>","visible":true}' > "$WORK/response"; CS39=$(mcp_value id)
mcp create_page "{\"title\":\"Contact\",\"slug\":\"kontakt-39\",\"language\":\"en\",\"translation_of\":$CS39,\"content\":\"<p>English 39</p>\",\"visible\":true}" > "$WORK/response"
contains -q 'isError' "$WORK/response" && contains -q 'kontakt-39' "$WORK/response" && expect "3.9 off: as before, an English page cannot take the slug of a Czech one" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'kontakt-39'")" "1" \
  || { echo "  CHYBA  3.9 off: the second kontakt-39 was saved: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
general39 1
grep -q 'name="slugs_per_language" value="1" checked' "$WORK/general39.html" && echo "  ok     3.9: Settings → General offers the switch (checked once on) with the language versions" || { echo "  CHYBA  3.9: no slugs_per_language field"; ERRORS=$((ERRORS+1)); }
expect "3.9: switching on drops the global keys only; the per-language keys stay" "$(keys39)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")" \
  "uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo|1"
# pages: the same slug in cs and en, refused twice in one language, system addresses refused in both
mcp create_page "{\"title\":\"Contact\",\"slug\":\"kontakt-39\",\"language\":\"en\",\"translation_of\":$CS39,\"content\":\"<p>English 39</p>\",\"visible\":true}" > "$WORK/response"; EN39=$(mcp_value id)
mcp create_page '{"title":"Kontakt znovu","slug":"kontakt-39","content":"<p>x</p>"}' > "$WORK/response"
contains -q 'isError' "$WORK/response" && echo "  ok     3.9 on: a second page with the slug in the same language is refused (MCP)" || { echo "  CHYBA  3.9: duplicate in one language over MCP: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 -d titulek=Duplicate -d seo_link=kontakt-39 -d jazyk=en
contains -q 'already exists\|už existuje' "$WORK/response" && echo "  ok     3.9 on: … and in the admin form, in the English version too" || { echo "  CHYBA  3.9: duplicate in one language in the admin"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Formular","slug":"form"}' > "$WORK/response"; R39A=$(contains -q 'isError' "$WORK/response" && echo refused)
mcp create_page '{"title":"Form","slug":"form","language":"en"}' > "$WORK/response"; R39B=$(contains -q 'isError' "$WORK/response" && echo refused)
mcp create_page '{"title":"En","slug":"en","language":"en"}' > "$WORK/response"; R39C=$(contains -q 'isError' "$WORK/response" && echo refused)
expect "3.9 on: a system address and a language code stay reserved in every language version" "$R39A|$R39B|$R39C|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('form', 'en')")" "refused|refused|refused|0"
expect "3.9 on: /kontakt-39 and /en/kontakt-39 are two pages" "$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', ids) ORDER BY jazyk) FROM ka_stranky WHERE seo_link = 'kontakt-39'")" ":$CS39,en:$EN39"
get39 /kontakt-39 > /dev/null; contains -q 'Česky 39' "$WORK/response" && contains -q "hreflang=\"en\" href=\"[^\"]*/en/kontakt-39\"" "$WORK/response" && P39CS=ok
get39 /en/kontakt-39 > /dev/null; contains -q 'English 39' "$WORK/response" && contains -q "hreflang=\"cs\" href=\"[^\"]*/kontakt-39\"" "$WORK/response" && P39EN=ok
expect "3.9 on: each address shows its own page with hreflang to the other" "${P39CS:-}|${P39EN:-}" "ok|ok"
# news and news categories: the same slugs in both versions
mcp create_category '{"name":"Zprávy 39"}' > /dev/null; sq "UPDATE ka_kategorie SET seo_link = 'zpravy-39' WHERE nazev = 'Zprávy 39'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=categories&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d idt=0 -d "nazev=News 39" -d seo_link=zpravy-39 -d jazyk=en -d popis= -d hodnost=100
expect "3.9 on: a news category with the same slug in both versions" "$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', seo_link) ORDER BY jazyk) FROM ka_kategorie WHERE nazev IN ('Zprávy 39', 'News 39')")" ":zpravy-39,en:zpravy-39"
mcp create_news '{"title":"Akce 39","category":"Zprávy 39","text":"<p>Novinka česky 39</p>","publish":true}' > /dev/null
mcp create_news '{"title":"Akce 39","category":"News 39","text":"<p>News in English 39</p>","publish":true}' > /dev/null
expect "3.9 on: a news item with the same slug in both versions" "$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', seo_link) ORDER BY jazyk) FROM ka_novinky WHERE titulek = 'Akce 39'")" ":akce-39,en:akce-39"
N39CS=$(get39 /novinky/akce-39); contains -q 'Novinka česky 39' "$WORK/response" || N39CS="$N39CS wrong"
N39EN=$(get39 /en/news/akce-39); contains -q 'News in English 39' "$WORK/response" || N39EN="$N39EN wrong"
C39=$(get39 /novinky/kategorie/zpravy-39)\|$(get39 /en/news/category/zpravy-39)
expect "3.9 on: each news item and category answers in its own version" "$N39CS|$N39EN|$C39" "200 |200 |200 |200 "
# a collection item and a collection category (both were per language before; the routing and hreflang stay)
mcp create_collection '{"name":"Sluzby 39","slug":"sluzby-39","item_pages":true,"fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"sluzby-39","name":"Servery","slug":"servery-39","values":{"popis":"Serverovna 39"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"sluzby-39","name":"Servers","slug":"servery-39","language":"en","values":{"popis":"Server room 39"},"visible":true}' > /dev/null
mcp save_collection_category '{"collection":"sluzby-39","name":"Cloud","slug":"cloud-39","visible":true}' > "$WORK/response"; CC39=$(mcp_value id)
mcp save_collection_category "{\"collection\":\"sluzby-39\",\"id\":$CC39,\"language\":\"en\",\"name\":\"Cloud EN\",\"slug\":\"cloud-39\"}" > /dev/null
I39=$(get39 /sluzby-39/servery-39); contains -q 'hreflang="en" href="[^"]*/en/sluzby-39/servery-39"' "$WORK/response" || I39="$I39 no-hreflang"
I39EN=$(get39 /en/sluzby-39/servery-39); contains -q 'hreflang="cs" href="[^"]*/sluzby-39/servery-39"' "$WORK/response" || I39EN="$I39EN no-hreflang"
K39=$(get39 /sluzby-39/cloud-39)\|$(get39 /en/sluzby-39/cloud-39); contains -q '<h1>Cloud EN</h1>' "$WORK/response" || K39="$K39 wrong"
expect "3.9 on: a collection item and a category with the same slug in cs and en, each with hreflang" "$I39|$I39EN|$K39" "200 |200 |200 |200 "
get39 /sitemap.xml > /dev/null
S39=""; for u in /kontakt-39 /en/kontakt-39 /novinky/akce-39 /en/news/akce-39 /sluzby-39/servery-39 /en/sluzby-39/servery-39 /sluzby-39/cloud-39 /en/sluzby-39/cloud-39; do contains -q "$u</loc>" "$WORK/response" && S39="$S39+" || S39="$S39 $u"; done
expect "3.9 on: the sitemap lists both versions of every address" "$S39" "++++++++"
# redirects: a version's own slug change redirects only that version (en/old), and heals only its links
mcp create_page '{"title":"O nas","slug":"o-nas-39","content":"<p>O nás 39 <a href=\"/o-nas-39\">cs</a> <a href=\"/en/o-nas-39\">en</a></p>","visible":true}' > "$WORK/response"; ON39=$(mcp_value id)
mcp create_page "{\"title\":\"About\",\"slug\":\"o-nas-39\",\"language\":\"en\",\"translation_of\":$ON39,\"content\":\"<p>About 39</p>\",\"visible\":true}" > "$WORK/response"; OE39=$(mcp_value id)
mcp update_page "{\"id\":$OE39,\"slug\":\"about-39\"}" > /dev/null
R39=$(get39 /en/o-nas-39)\|$(get39 /o-nas-39)
expect "3.9 on: the English slug change redirects /en/o-nas-39 only; /o-nas-39 stays the Czech page" "$R39|$(sq "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu)) FROM ka_presmerovani WHERE z_adresy LIKE '%o-nas-39'")" \
  "301 $B/en/about-39|200 |en/o-nas-39>en/about-39"
expect "3.9 on: link healing rewrote the English link only" "$(sq "SELECT CONCAT(text LIKE '%href=\"/o-nas-39\"%', text LIKE '%href=\"/en/about-39\"%') FROM ka_stranky WHERE ids = $ON39")" "11"
mcp save_redirect '{"from":"/en/stary-39","to":"/en/kontakt-39"}' > /dev/null
expect "3.9 on: a redirect stored with the prefix (a Polylang address) fires in its version" "$(get39 /en/stary-39)|$(get39 /stary-39)" "301 $B/en/kontakt-39|404 "
# switching off is refused while two versions share a slug; it works once they do not, and the global keys come back
general39 0
contains -q 'kontakt-39' "$WORK/general39.html" && expect "3.9: switching off is refused while two language versions share a slug, with the slugs named" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)" "1|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo" \
  || { echo "  CHYBA  3.9: switching off with duplicates: $(grep -o 'class="zprava[^"]*"[^<]*<[^<]*' "$WORK/general39.html" | head -3)"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE jazyk = 'en' AND seo_link = 'kontakt-39'; DELETE FROM ka_novinky WHERE jazyk = 'en' AND seo_link = 'akce-39'; DELETE FROM ka_kategorie WHERE jazyk = 'en' AND seo_link = 'zpravy-39'" > /dev/null
general39 0
expect "3.9: without shared slugs it switches off and the global keys are back" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)" \
  "0|uq_clanky_jazyk_seo,uq_clanky_seo,uq_stranky_jazyk_seo,uq_stranky_seo,uq_topic_jazyk_seo,uq_topic_seo"
sq "UPDATE ka_nastaveni SET hodnota = '$HOME39' WHERE promenna = 'home_page'" > /dev/null

echo "== 2.9: monthly report by e-mail"
REPORT_MAILS() { sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%Zpráva o webu%' OR predmet LIKE '%Website report%'"; }
LAST_MONTH=$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m");')
sq "INSERT INTO ka_nastaveni VALUES ('report_monthly','1'),('report_recipients','owner@example.cz'),('report_last_month','') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=mail"; TOKEN=$(csrf)
grep -q 'name="report_recipients"' "$WORK/response" && grep -q 'action=report_preview' "$WORK/response" && grep -q 'action=report_send' "$WORK/response" && echo "  ok     Settings → Mail has the monthly report with its preview and send buttons" || { echo "  CHYBA  Mail tab: monthly report"; ERRORS=$((ERRORS+1)); }
check "the preview is the e-mail of the last month with the site name" 200 "/admin.php?module=settings&action=report_preview" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")"
grep -q 'max-width:600px' "$WORK/response" && grep -q 'Studio Test' "$WORK/response" && ! grep -q 'spravce@example.cz' "$WORK/response" && echo "  ok     the preview is an inline-styled e-mail with the agency, without the site e-mail" || { echo "  CHYBA  report preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=report_send" -d "_csrf=$TOKEN"
expect "send now: the report is queued for the recipient" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'owner@example.cz' AND (predmet LIKE '%Zpráva o webu%' OR predmet LIKE '%Website report%')")" "1"
expect "send now remembers the month, so the job does not send it again" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_last_month'")" "$LAST_MONTH"
# the job: with the month forgotten it sends once, the second run finds it sent
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'report_last_month'; UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'" > /dev/null
BEFORE=$(REPORT_MAILS)
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'" > /dev/null
curl -s -o "$WORK/tasks2.txt" "$B/ulohy?token=testtoken123"
expect "the job sends the previous month once: two runs, one more report" "$(( $(REPORT_MAILS) - BEFORE ))" "1"
grep -q "monthly_report: sent to 1" "$WORK/tasks.txt" && grep -q "monthly_report: sent already" "$WORK/tasks2.txt" && echo "  ok     the job reports what it did" || { echo "  CHYBA  monthly_report job"; cat "$WORK/tasks.txt" "$WORK/tasks2.txt"; ERRORS=$((ERRORS+1)); }
expect "report.sent events carry the month and the count, never an address" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'report.sent' AND data LIKE '%\"month\":\"$LAST_MONTH\"%' AND data NOT LIKE '%@%'")" "2"
# recipients are validated on save: one bad address rejects the field, good ones are kept one per line
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=mail -d mail_mode=mail -d report_monthly=1 --data-urlencode "report_recipients=owner@example.cz, nonsense"
expect "recipients: an invalid address is not saved" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_recipients'")" "owner@example.cz"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=mail -d mail_mode=mail --data-urlencode "report_recipients=owner@example.cz, agentura@example.cz"
expect "recipients: valid addresses are saved one per line, the switch off when unchecked" "$(sq "SELECT CONCAT(REPLACE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_recipients'), '\n', '|'), ':', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_monthly'))")" "owner@example.cz|agentura@example.cz:0"

echo "== instalace aktualizace (testovací klíč a kanál)"
cat > "$WORK/vydani-test.php" <<'PHP'
<?php
[$site, $port] = [$argv[1], $argv[2]];
require $site . '/system/src/Core/Signature.php';
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
file_put_contents($site . '/system/aktualizace.pub', base64_encode(sodium_crypto_sign_publickey($pair)) . " test\n");
// seznam souborů „nainstalované verze“: podle otisku .htaccess aktualizace pozná, že ho správce upravil
file_put_contents($site . '/system/soubory.json', json_encode(['verze' => '1.0.0-dev', 'soubory' => ['.htaccess' => hash_file('sha256', $site . '/.htaccess')]]));
@mkdir(dirname($site) . '/kanal');
$zip = new ZipArchive();
$zip->open(dirname($site) . '/kanal/k.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('image/test-aktualizace.txt', "nova verze\n");
$zip->addFromString('.htaccess', "# htaccess nove verze\n");
// the core of the package says it is 9.9.9 – the check after the update (2.8) asks the site which version runs
$bootstrap = (string) preg_replace("/const KALETA_VERSION = '[^']*';/", "const KALETA_VERSION = '9.9.9';", (string) file_get_contents($site . '/system/bootstrap.php'));
$zip->addFromString('system/bootstrap.php', $bootstrap); // balíček musí nést jádro
$zip->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$zip->addFromString('system/aktualizace.pub', (string) file_get_contents($site . '/system/aktualizace.pub')); // the same test key: tools/check-channel.php checks the keys of the package
$zip->close();
$sha = hash_file('sha256', dirname($site) . '/kanal/k.zip');
// signed as tools/release.php signs: v1 ("podpis") and v2 over every field ("podpis2", 3.9)
$m = Kaleta\Core\Signature::signManifest(['verze' => '9.9.9', 'url' => "http://127.0.0.1:$port/k.zip", 'sha256' => $sha, 'min_php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'zmeny' => ['test']], $sk);
file_put_contents(dirname($site) . '/kanal/ok.json', json_encode($m));
// 3.3.2 (N40): the same release, genuinely signed as a security release (the source changes its answer between check and installation)
file_put_contents(dirname($site) . '/kanal/ok-bezpecnostni.json', json_encode(Kaleta\Core\Signature::signManifest(['bezpecnostni' => true] + $m, $sk)));
// 3.9: the security flag turned on after signing – signature v2 no longer holds, the check refuses the manifest at once
file_put_contents(dirname($site) . '/kanal/prepnuty.json', json_encode(['bezpecnostni' => true] + $m));
// 3.9: signature v2 removed from a 3.9+ manifest (to get around it) – refused too
file_put_contents(dirname($site) . '/kanal/bezv2.json', json_encode(array_diff_key($m, ['podpis2' => 1])));
// 3.9 (N37-4): a package whose own bootstrap needs a newer PHP than its (here signed) manifest says – refused before a file is written
$newPhp = new ZipArchive();
$newPhp->open(dirname($site) . '/kanal/p.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$newPhp->addFromString('image/test-novephp.txt', "nove php\n");
$newPhp->addFromString('system/bootstrap.php', (string) preg_replace("/const KALETA_MIN_PHP = '[^']*';/", "const KALETA_MIN_PHP = '99.0';", $bootstrap));
$newPhp->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$newPhp->close();
$shaP = hash_file('sha256', dirname($site) . '/kanal/p.zip');
file_put_contents(dirname($site) . '/kanal/balicekphp.json', json_encode(Kaleta\Core\Signature::signManifest(['url' => "http://127.0.0.1:$port/p.zip", 'sha256' => $shaP] + $m, $sk)));
// 2.8: a package that installs fine but breaks the home page – the update must undo itself
$broken = new ZipArchive();
$broken->open(dirname($site) . '/kanal/b.zip', ZipArchive::CREATE);
$broken->addFromString('image/test-rozbita.txt', "rozbita verze\n");
$broken->addFromString('system/bootstrap.php', $bootstrap);
$broken->addFromString('index.php', "<?php\nif (str_starts_with((string) parse_url((string) (\$_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/ulohy')) { require __DIR__ . '/system/bootstrap.php'; \$app = Kaleta\\Core\\App::boot(); (new Kaleta\\Front\\Kernel(\$app))->handle()->send(); exit; }\nhttp_response_code(500);\necho 'broken';\n");
$broken->close();
$shaB = hash_file('sha256', dirname($site) . '/kanal/b.zip');
file_put_contents(dirname($site) . '/kanal/rozbity.json', json_encode(Kaleta\Core\Signature::signManifest(['url' => "http://127.0.0.1:$port/b.zip", 'sha256' => $shaB] + $m, $sk)));
// a foreign v1 signature (v2 holds): the installation checks v1 as before and refuses
file_put_contents(dirname($site) . '/kanal/zly.json', json_encode(['podpis' => base64_encode(random_bytes(64))] + $m));
// 3.7: a correctly signed release for a PHP newer than the server runs
file_put_contents(dirname($site) . '/kanal/novephp.json', json_encode(Kaleta\Core\Signature::signManifest(['min_php' => '99.0'] + $m, $sk)));
// 3.8 (D3): release channels – folders with aktualizace.json (latest) and aktualizace-stable.json next to it, one key
$signed = fn (string $version, string $channel): array => Kaleta\Core\Signature::signManifest(['verze' => $version, 'kanal' => $channel] + $m, $sk);
$channels = [
    'kanaly' => [$signed('9.9.10', 'latest'), $signed('9.9.9', 'stable')],   // latest 9.9.10, stable 9.9.9
    'pozadu' => [$signed('9.9.10', 'latest'), $signed('1.0.0', 'stable')],   // the stable line is behind the site
    'spatne' => [$signed('9.9.10', 'latest'), $signed('9.9.10', 'latest')],  // a wrong redirect: the latest manifest at the stable address
    'bez' => [$signed('9.9.10', 'latest'), null],                            // no stable channel published yet
    // 3.9 (N38-3): a genuine latest release relabelled "stable" at the stable address – the channel is signed, v2 fails
    'podvrh' => [$signed('9.9.10', 'latest'), ['kanal' => 'stable'] + $signed('9.9.10', 'latest')],
    // the manifest published today (3.8.0, written before v2 existed): the daily check keeps passing on its v1 signature
    'stary' => [array_diff_key($signed('3.8.0', 'latest'), ['podpis2' => 1]), null],
];
foreach ($channels as $folder => [$latest, $stable]) {
    @mkdir(dirname($site) . '/kanal/' . $folder);
    file_put_contents(dirname($site) . '/kanal/' . $folder . '/aktualizace.json', json_encode($latest));
    if ($stable !== null) {
        file_put_contents(dirname($site) . '/kanal/' . $folder . '/aktualizace-stable.json', json_encode($stable));
    }
}
PHP
# the channel on its own server: the built-in PHP server handles only one request at a time, it could not download from itself
CHANNEL_PORT=$((PORT + 1))
php "$WORK/vydani-test.php" "$WORK/web" "$CHANNEL_PORT"
(cd "$WORK/kanal" && exec php -S "127.0.0.1:$CHANNEL_PORT" > /dev/null 2>&1) & CHANNEL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$CHANNEL_PORT/ok.json" && break; sleep 0.2; done
echo "# vlastni uprava spravce" >> "$WORK/web/.htaccess"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
update_from() { "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN"; }
update_from zly.json
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     balíček s cizím podpisem se nenainstaluje" || { echo "  CHYBA  nainstalován balíček s neplatným podpisem"; ERRORS=$((ERRORS+1)); }
# 3.7: a site on an older PHP than the release needs is not offered it (it says which PHP it needs) and cannot install it
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/novephp.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
contains -F 'PHP 99.0' "$WORK/response" && ! contains -F 'value="9.9.9"' "$WORK/response" && echo "  ok     3.7: a release for a newer PHP is not offered, the page names the PHP it needs" || { echo "  CHYBA  3.7: release for a newer PHP offered"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F '99.0' "$WORK/response" && echo "  ok     3.7: system health names the PHP the new version needs" || { echo "  CHYBA  3.7: health does not say which PHP the update needs"; ERRORS=$((ERRORS+1)); }
update_from novephp.json
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.7: a release for a newer PHP does not install" || { echo "  CHYBA  3.7: release for a newer PHP installed"; ERRORS=$((ERRORS+1)); }
# 3.9 (N38-3, N37-4): manifest signature v2 – a field changed after signing, or v2 removed from a 3.9+ manifest, is refused
# as soon as the site reads the manifest: nothing is offered, nothing installs
for f in prepnuty bezv2; do
  "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$f.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
  contains -F 'není podepsaný vydavatelem Kalety (podpis v2)' "$WORK/response" && ! contains -F 'value="9.9.9"' "$WORK/response" && echo "  ok     3.9 manifest v2 ($f): the manifest is refused, nothing is offered" || { echo "  CHYBA  3.9 manifest v2 ($f): offered"; ERRORS=$((ERRORS+1)); }
  update_from "$f.json"
  [ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.9 manifest v2 ($f): nothing installs" || { echo "  CHYBA  3.9 manifest v2 ($f): installed"; ERRORS=$((ERRORS+1)); }
done
# 3.9 (N37-4): the package's own KALETA_MIN_PHP decides before a file is written, whatever the manifest says
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/balicekphp.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN"
[ ! -f "$WORK/web/image/test-novephp.txt" ] && contains -F 'vyžaduje PHP 99.0' "$WORK/response" && echo "  ok     3.9: a package that needs a newer PHP is refused before a file is written" || { echo "  CHYBA  3.9: package PHP requirement"; ERRORS=$((ERRORS+1)); }
# 2.8: the check after an update asks the site itself – a second server on the same files, since this one is busy installing
PROBE_PORT=$((PORT + 11)); (cd "$WORK/web" && exec php -S "127.0.0.1:$PROBE_PORT" system/dev-router.php > /dev/null 2>&1) & PROBE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PROBE_PORT/" && break; sleep 0.2; done
SITE_URL_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")
sq "INSERT INTO ka_nastaveni VALUES ('site_url','http://127.0.0.1:$PROBE_PORT') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
update_from rozbity.json
[ ! -f "$WORK/web/image/test-rozbita.txt" ] && ! grep -q "echo 'broken'" "$WORK/web/index.php" && [ "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.rolled_back'")" = 1 ] \
  && sq "SELECT message FROM ka_events WHERE type = 'update.rolled_back'" | contains '500' && echo "  ok     2.8: an update that breaks the site undoes itself (event update.rolled_back)" || { echo "  CHYBA  rozbitá aktualizace se nevrátila"; sq "SELECT message, data FROM ka_events WHERE type LIKE 'update.%'"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_attempt'" > /dev/null
# 3.3.2 (N40): the version the administrator saw must be the one the source offers when installing
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/ok.json') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=9.9.8
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.3.2: an update offering another version than the one shown is not installed" || { echo "  CHYBA  installed a version the administrator did not choose"; ERRORS=$((ERRORS+1)); }
# the automatic installation decided on a manifest that called 9.9.9 a security release; when installing, the source answers
# with the genuine regular release (signed without the flag) – nothing may be installed
cat > "$WORK/kanal/flip.php" <<'PHP'
<?php
$n = (int) @file_get_contents(__DIR__ . '/flip.n');
file_put_contents(__DIR__ . '/flip.n', (string) ($n + 1));
// both answers are genuinely signed (3.9: a flag changed after signing is refused at the check already, see prepnuty.json)
header('Content-Type: application/json');
echo file_get_contents(__DIR__ . ($n === 0 ? '/ok-bezpecnostni.json' : '/ok.json'));
PHP
sq "INSERT INTO ka_nastaveni VALUES ('auto_updates', '1') ON DUPLICATE KEY UPDATE hodnota = '1';
  INSERT INTO ka_nastaveni VALUES ('update_url', 'http://127.0.0.1:$CHANNEL_PORT/flip.php') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota);
  UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_cache', 'update_attempt');
  UPDATE ka_jobs SET last_run = NULL WHERE name = 'updates'" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && grep -q 'updates: failed 9.9.9' "$WORK/tasks.txt" && echo "  ok     3.3.2: a security flag the signed package does not carry stops the automatic installation" || { echo "  CHYBA  automatic installation on an unverified security flag"; grep updates "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_attempt', 'update_cache'); UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'auto_updates'" > /dev/null
# 3.8 (D3): release channels – Latest (default) reads aktualizace.json, Stable the signed aktualizace-stable.json next to it
channel_source() { sq "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; }
backups_page() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; }
channel_source kanaly/aktualizace.json; backups_page
expect "3.8 channels: an existing site stays on Latest" "$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_channel'), 'latest')")|$(contains -F 'name="update_channel" value="latest" checked' "$WORK/response" && echo checked)" "latest|checked"
contains -F 'value="9.9.10"' "$WORK/response" && echo "  ok     3.8 channels: Latest offers the newest version (9.9.10)" || { echo "  CHYBA  3.8 channels: Latest does not offer 9.9.10"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf)
# the channel is chosen on the Backups and updates tab; the checkboxes of the tab are sent as they are
backups_save() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=backups -d "update_channel=$1" \
  $( [ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'auto_backups'")" = 0 ] || echo "-d auto_backups=1" ) $( [ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'backup_media'")" = 0 ] || echo "-d backup_media=1" ); }
backups_save beta; backups_page # the refused value is shown back once for correction
expect "3.8 channels: an unknown channel is not saved" "$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'update_channel' AND hodnota = 'beta'")" "0"
backups_save stable; sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null; backups_page
expect "3.8 channels: the site switched to Stable" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_channel'")" "stable"
contains -F 'value="9.9.9"' "$WORK/response" && ! contains -F 'value="9.9.10"' "$WORK/response" && contains -F 'name="update_channel" value="stable" checked' "$WORK/response" \
  && echo "  ok     3.8 channels: Stable offers the stable manifest (9.9.9), never the newer latest one" || { echo "  CHYBA  3.8 channels: Stable offers the wrong version"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
contains -F 'Kanál aktualizací' "$WORK/response" && contains -F 'Stabilní – jen bezpečnostní opravy' "$WORK/response" && echo "  ok     3.8 channels: System status names the channel" || { echo "  CHYBA  3.8 channels: System status does not name the channel"; ERRORS=$((ERRORS+1)); }
mcp get_health '{}' > "$WORK/response"; GH="$(mcp_value update channel)|$(mcp_value update available)"
mcp site_info '{}' > "$WORK/response"; expect "3.8 channels: MCP get_health and site_info say the channel and the offered version" "$GH|$(mcp_value update_channel)" "stable|9.9.9|stable"
# a site that switched to Stable while it runs a newer version than the stable line: no downgrade, it waits and says so
channel_source pozadu/aktualizace.json; backups_page
contains -F 'novější než stabilní kanál (1.0.0)' "$WORK/response" && ! contains -F 'value="1.0.0"' "$WORK/response" && echo "  ok     3.8 channels: a site ahead of the stable line is offered no downgrade and told why" || { echo "  CHYBA  3.8 channels: ahead of the stable line"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=1.0.0
mcp get_health '{}' > "$WORK/response"
expect "3.8 channels: no downgrade is installed; get_health says the site is ahead of the stable line" "$([ -f "$WORK/web/image/test-aktualizace.txt" ] && echo installed || echo kept)|$(mcp_value update ahead_of_stable)" "kept|1.0.0"
# the latest manifest at the stable address (a wrong redirect): nothing is offered nor installed
channel_source spatne/aktualizace.json; backups_page
contains -F 'nenabízí vydání stabilního kanálu' "$WORK/response" && ! contains -F 'value="9.9.10"' "$WORK/response" && echo "  ok     3.8 channels: a latest manifest at the stable address offers nothing" || { echo "  CHYBA  3.8 channels: wrong manifest on the stable channel"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=9.9.10
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.8 channels: a latest manifest at the stable address does not install" || { echo "  CHYBA  3.8 channels: installed a latest release on the stable channel"; ERRORS=$((ERRORS+1)); }
# 3.9 (N38-3): a genuine latest release relabelled "stable" at the stable address – the channel is signed (v2), so it is refused
channel_source podvrh/aktualizace.json; backups_page
contains -F 'podpis v2' "$WORK/response" && ! contains -F 'value="9.9.10"' "$WORK/response" && echo "  ok     3.9 channels: a latest release relabelled stable is refused (the channel is signed)" || { echo "  CHYBA  3.9 channels: relabelled stable manifest offered"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN" -d verze=9.9.10
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     3.9 channels: a latest release relabelled stable does not install" || { echo "  CHYBA  3.9 channels: installed a relabelled release on the stable channel"; ERRORS=$((ERRORS+1)); }
# a custom source with another file name has no stable twin: the site follows it as before, whatever the channel
channel_source ok.json; backups_page
contains -F 'nemá proto stabilní protějšek' "$WORK/response" && contains -F 'value="9.9.9"' "$WORK/response" && echo "  ok     3.8 channels: a custom source without a stable twin keeps working on Stable" || { echo "  CHYBA  3.8 channels: custom source on Stable"; ERRORS=$((ERRORS+1)); }
# tools/check-channel.php checks both manifests (signature, package, keys, the channel mark) and skips a stable one not yet published
for c in kanaly spatne bez podvrh stary; do
  if php "$WORK/web/tools/check-channel.php" "http://127.0.0.1:$CHANNEL_PORT/$c/aktualizace.json" > "$WORK/channel-$c.txt" 2>&1; then echo ok >> "$WORK/channel-$c.txt"; else echo failed >> "$WORK/channel-$c.txt"; fi
done
expect "3.8 check-channel: both manifests pass, a latest one at the stable address fails, a missing stable one is skipped" \
  "$(tail -1 "$WORK/channel-kanaly.txt")|$(contains -F 'aktualizace-stable.json – nabízená verze: 9.9.9' "$WORK/channel-kanaly.txt" && echo stable)|$(tail -1 "$WORK/channel-spatne.txt")|$(contains -F '"kanal": "stable"' "$WORK/channel-spatne.txt" && echo why)|$(tail -1 "$WORK/channel-bez.txt")|$(contains -F '404' "$WORK/channel-bez.txt" && echo skipped)" \
  "ok|stable|failed|why|ok|skipped"
[ "$(tail -1 "$WORK/channel-kanaly.txt")" = ok ] || cat "$WORK/channel-kanaly.txt"
expect "3.9 check-channel: a relabelled stable manifest fails on signature v2; a pre-3.9 manifest without v2 passes on v1" \
  "$(tail -1 "$WORK/channel-podvrh.txt")|$(contains -F 'podpis v2 (podpis2) NEPLATÍ' "$WORK/channel-podvrh.txt" && echo why)|$(tail -1 "$WORK/channel-stary.txt")|$(contains -F 'bez podpisu v2' "$WORK/channel-stary.txt" && echo noted)" \
  "failed|why|ok|noted"
sq "UPDATE ka_nastaveni SET hodnota = 'latest' WHERE promenna = 'update_channel'" > /dev/null
update_from ok.json
[ -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     podepsaná aktualizace se nainstaluje" || { echo "  CHYBA  aktualizace se nenainstalovala"; sq "SELECT message, data FROM ka_events WHERE type LIKE 'update.%'"; ERRORS=$((ERRORS+1)); }
grep -q "vlastni uprava spravce" "$WORK/web/.htaccess" && [ -f "$WORK/web/.htaccess.kaleta-nova" ] && echo "  ok     vlastní .htaccess zůstal, nová verze leží vedle" || { echo "  CHYBA  aktualizace přepsala vlastní .htaccess"; ERRORS=$((ERRORS+1)); }
expect "2.10.2: after an update the site knows it runs the newest version (no new check, no error)" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.manifest.verze')), '|', JSON_TYPE(JSON_EXTRACT(hodnota, '\$.chyba'))) FROM ka_nastaveni WHERE promenna = 'update_cache'")" "9.9.9|NULL"
expect "2.8: a working update is checked and recorded (update.applied)" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.applied'")" "1"
sq "UPDATE ka_nastaveni SET hodnota = '$SITE_URL_BEFORE' WHERE promenna = 'site_url'" > /dev/null; kill "$PROBE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_url', 'update_cache')"
kill "$CHANNEL_PID" 2>/dev/null || true

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
echo; [ "$ERRORS" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
