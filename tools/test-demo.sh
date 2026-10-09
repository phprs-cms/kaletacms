#!/usr/bin/env bash
# Kaleta – the public demo (2.6): a site installed from the command line, switched to demo mode, snapshotted, changed
# through the admin and reset. Checks the shared sign-in, what the demo refuses and that the reset brings everything
# back. Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME (kaleta_test_demo) DB_USER DB_PASS PORT (8097).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_demo}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8097}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; JAR="$WORK/jar"; FOUND=0
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT
ok() { echo "  ok     $1"; }
fail() { echo "  CHYBA  $1"; FOUND=$((FOUND+1)); }
sql() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
token() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }

echo "== Demo site"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
rm -f "$WORK/web/config.php"; mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
curl -s -o "$WORK/install.html" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ -d nazev_webu=Demo -d web=firemni -d user=demo -d email= -d password=Demo-kaleta-2026 -d password2=Demo-kaleta-2026
[ -f "$WORK/web/config.php" ] || { sed 's/<[^>]*>//g' "$WORK/install.html" | grep -v '^\s*$' | head -20; exit 1; }
# demo mode: the shared account in config.php
php -r '$f = $argv[1]; $c = require $f; $c["demo"] = ["user" => "demo", "password" => "Demo-kaleta-2026"]; file_put_contents($f, "<?php\nreturn " . var_export($c, true) . ";\n");' "$WORK/web/config.php"
# 3.2: features are chosen by whoever runs the demo before the snapshot (visitors cannot switch them) – here Bookings
sql "UPDATE ka_nastaveni SET hodnota = 'bookings' WHERE promenna = 'extensions'"
(cd "$WORK/web" && php system/demo.php snapshot > "$WORK/snapshot.txt" 2>&1) && ok "snapshot saved" || { fail "snapshot"; cat "$WORK/snapshot.txt"; }

echo "== What visitors see"
curl -s -o "$WORK/login.html" -c "$JAR" "$B/admin.php"
grep -q 'Sign in as demo with the password Demo-kaleta-2026' "$WORK/login.html" && grep -q 'value="Demo-kaleta-2026"' "$WORK/login.html" && ok "sign-in page shows and fills in the shared account" || fail "demo account on the sign-in page"
curl -s -D "$WORK/headers" -o "$WORK/home.html" "$B/"
grep -qi '^x-robots-tag: noindex' "$WORK/headers" && grep -q 'Kaleta demo – try the admin' "$WORK/home.html" && ok "public pages: noindex and the demo badge" || fail "noindex / badge"
curl -s "$B/robots.txt" | grep '^Disallow: /$' > /dev/null && ok "robots.txt disallows everything" || fail "robots.txt"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp")" = 403 ] && ok "the Claude connection is off" || fail "MCP in the demo"

echo "== What the demo refuses"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(token "$WORK/login.html")" -d user=demo -d password=Demo-kaleta-2026
curl -s -b "$JAR" -c "$JAR" -o "$WORK/page.html" "$B/admin.php?module=pages"; T=$(token "$WORK/page.html")
grep -q 'Public demo: everything you change here is reset in' "$WORK/page.html" && ok "admin shows the reset notice" || fail "admin demo notice"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$T" -d idu=0 -d user=intruder --data-urlencode "password=Intruder-2026-x" -d admin=2
[ "$(sql "SELECT COUNT(*) FROM ka_uzivatele WHERE user = 'intruder'")" = 0 ] && ok "no new users" || fail "a user was created in the demo"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$T" -d co=heslo --data-urlencode "stare=Demo-kaleta-2026" --data-urlencode "nove=Taken-over-2026" --data-urlencode "nove2=Taken-over-2026"
curl -s -c "$WORK/jar2" -o "$WORK/login2.html" "$B/admin.php"
code=$(curl -s -b "$WORK/jar2" -c "$WORK/jar2" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$(token "$WORK/login2.html")" -d user=demo -d password=Demo-kaleta-2026)
curl -s -b "$WORK/jar2" -o "$WORK/page.html" "$B/admin.php?module=pages"; grep -q 'module=pages' "$WORK/page.html" && ok "the shared password cannot be changed" || fail "demo password changed"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=analytics --data-urlencode 'head_code=<script>alert(1)</script>' -d stats=1
[ "$(sql "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'head_code' AND hodnota LIKE '%alert%'")" = 0 ] && ok "no code fields" || fail "head code saved in the demo"
[ "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=bookings")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=whistleblowing")" = "200|403" ] && ok "the features of the snapshot show (Bookings), the others not (Whistleblowing)" || fail "features in the demo"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=extensions&action=save" -d "_csrf=$T" -d tab=extensions -d 'rozsireni[]=bookings' -d 'rozsireni[]=whistleblowing' -d 'rozsireni[]=claude'
[ "$(sql "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")" = bookings ] && ok "visitors cannot switch features" || fail "features switched in the demo"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?module=settings&action=download_backup&file=x")
case "$code" in 302*) ok "backups cannot be downloaded";; *) fail "backup download: $code";; esac
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=mail -d mail_mode=smtp --data-urlencode smtp_host=evil.example
[ "$(sql "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'smtp_host' AND hodnota = 'evil.example'")" = 0 ] && ok "mail settings stay" || fail "mail settings changed"
# 3.3.2 (N24): the screens built on Settings (System status, Claude settings, Features, Business details) inherit its actions –
# the demo filters by the class, so none of them makes, downloads or restores a backup or pairs with a console
BACKUPS_BEFORE=$(ls "$WORK/web/storage/zalohy" 2>/dev/null | wc -l | tr -d ' ')
for m in status claude_settings extensions business settings; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=$m&action=backup" -d "_csrf=$T"
done
[ "$(ls "$WORK/web/storage/zalohy" 2>/dev/null | wc -l | tr -d ' ')" = "$BACKUPS_BEFORE" ] && ok "no Settings screen makes a backup" || fail "a backup was made in the demo"
DEMO_CODES=""
for m in status claude_settings extensions business; do
  DEMO_CODES="$DEMO_CODES$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=$m&action=download_backup&file=x") "
done
[ "$DEMO_CODES" = "302 302 302 302 " ] && ok "no Settings screen hands out a backup" || fail "backup download through a Settings screen: $DEMO_CODES"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/pair.html" -L -X POST "$B/admin.php?module=status&action=fleet_pair" -d "_csrf=$T" -d "kod=x"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=claude_settings&action=save" -d "_csrf=$T" --data-urlencode "claude_instructions=Injected"
[ "$(sql "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'claude_instructions' AND hodnota = 'Injected'")" = 0 ] && grep -q 'switched off in the public demo' "$WORK/pair.html" \
  && ok "System status cannot pair with a console and Claude settings cannot be saved" || fail "status or claude_settings actions in the demo"
# the settings the demo saves are an allow-list: a script host, a javascript: policy link and maintenance mode stay as they are
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=analytics --data-urlencode "matomo_url=https://evil.example/" -d matomo_id=1 -d gtm_id=GTM-EVIL1 -d ga4_id=G-DEMO1234
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=general -d maintenance=1 -d "site_name=Demo"
[ "$(sql "SELECT COUNT(*) FROM ka_nastaveni WHERE (promenna IN ('matomo_url', 'gtm_id') AND hodnota <> '') OR (promenna = 'maintenance' AND hodnota = '1')")" = 0 ] \
  && [ "$(sql "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'ga4_id'")" = "G-DEMO1234" ] && ok "the demo saves only allowed settings (no Matomo, GTM or maintenance; GA4 yes)" || fail "settings allow-list in the demo"

echo "== Changes and the reset"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=general --data-urlencode "site_name=Changed by a visitor"
sql "UPDATE ka_stranky SET titulek = 'Defaced' WHERE ids = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page')"
echo "visitor upload" > "$WORK/web/media/visitor.txt"
[ "$(sql "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" = "Changed by a visitor" ] && ok "visitors can change the site" || fail "the general settings did not save"
(cd "$WORK/web" && php system/demo.php reset > "$WORK/reset.txt" 2>&1) || { fail "reset"; cat "$WORK/reset.txt"; }
[ "$(sql "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" != "Changed by a visitor" ] && [ "$(sql "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Defaced'")" = 0 ] && [ ! -e "$WORK/web/media/visitor.txt" ] \
  && ok "the reset brings back the database and media" || fail "after the reset"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=pages"; grep -q 'module=pages' "$WORK/page.html" && ok "the admin works after the reset" || fail "admin after the reset"
php "$WORK/web/system/demo.php" reset > /dev/null 2>&1 < /dev/null; [ "$(curl -s -o /dev/null -w '%{http_code}' "$B/system/demo.php")" != 200 ] && ok "demo.php does not run from the web" || fail "demo.php reachable from the web"

[ "$FOUND" = 0 ] && echo "  ok     the public demo" || { echo "NALEZENO CHYB: $FOUND"; exit 1; }
