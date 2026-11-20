#!/usr/bin/env bash
# Kaleta – output budget: installs each starter site and runs Lighthouse (mobile) on its pages. Fails when a page drops
# below the budget: performance MIN_PERF (default 90 – the PHP built-in server compresses nothing, a real host scores
# higher), accessibility, best practices and SEO MIN_OTHER (default 100).
# Needs PHP, MySQL, Node and Google Chrome (or CHROME=/path/to/chrome).
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test_lighthouse) DB_USER (root) DB_PASS () PORT (8099) SITES (all)
# The database DB_NAME is DROPPED and recreated for every starter site.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_lighthouse}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
MIN_PERF="${MIN_PERF:-90}"; MIN_OTHER="${MIN_OTHER:-100}"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
SITES="${SITES:-$(cd "$ROOT" && php -r 'require "system/bootstrap.php"; echo implode(" ", array_keys(Kaleta\Builder\Library::SITES));')}"
WORK="$(mktemp -d)"; ERRORS=0
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
stop_server() { [ -z "${SERVER_PID:-}" ] || { pkill -P "$SERVER_PID" 2>/dev/null || true; kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; }; }
cleanup() { stop_server; rm -rf "$WORK"; }
trap cleanup EXIT

npm i --silent --prefix "$WORK/lh" lighthouse@12 > /dev/null 2>&1
for SITE in $SITES; do
  echo "== starter site $SITE"
  stop_server
  SITE_PORT=$PORT; PORT=$((PORT + 1)); B="http://127.0.0.1:$SITE_PORT" # workers of the built-in server may linger on the previous port
  rm -rf "$WORK/web" && mkdir "$WORK/web"
  (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
  mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
  (cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$SITE_PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
  PASSWORD="Lighthouse-$(openssl rand -hex 8)"
  curl -s -o "$WORK/response" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ --data-urlencode "nazev_webu=Lighthouse Test Ltd" -d "web=$SITE" -d user=admin -d "jmeno=Tester" -d email= \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
    -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=customers' -d 'rozsireni[]=pipeline' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
  [ ! -f "$WORK/web/install.php" ] || { echo "  CHYBA  install of $SITE failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
  # 3.5: with the built-in cookie bar showing and its policy link, as on most real sites (lead attribution needs consent and
  # loads nothing from outside) – the bar's link text once cost Lighthouse SEO 92
  "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_mode', 'vestavena'), ('lead_attribution', '1'), ('cookies_policy_url', '/') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"
  # the home page and the pages of the starter site from the sitemap
  curl -s "$B/sitemap.xml" > "$WORK/sitemap.xml"
  PAGES=$(grep -o '<loc>[^<]*</loc>' "$WORK/sitemap.xml" | sed 's#<loc>[^/]*//[^/]*##;s#</loc>##' | head -5)
  for P in ${PAGES:-/}; do
    curl -s -o /dev/null "$B$P" # a warm page cache, as for a real visitor
    CHROME_PATH="$CHROME" "$WORK/lh/node_modules/.bin/lighthouse" "$B$P" --quiet --chrome-flags="--headless=new --no-sandbox" --output=json --output-path="$WORK/lh.json" \
      --only-categories=performance,accessibility,best-practices,seo > /dev/null 2>&1 || { echo "  CHYBA  Lighthouse failed on $P"; ERRORS=$((ERRORS+1)); continue; }
    RESULT=$(node -e '
      const r = require(process.argv[1]), c = r.categories, perf = +process.argv[2], other = +process.argv[3];
      const s = k => Math.round(c[k].score * 100), low = [];
      if (s("performance") < perf) low.push("performance");
      for (const k of ["accessibility", "best-practices", "seo"]) if (s(k) < other) low.push(k);
      const failed = [];
      for (const [id, a] of Object.entries(r.audits)) if (a.score !== null && a.score < 1 && ["binary", "numeric"].includes(a.scoreDisplayMode) && r.categories.accessibility.auditRefs.concat(r.categories["best-practices"].auditRefs, r.categories.seo.auditRefs).some(x => x.id === id && x.weight > 0)) failed.push(id);
      console.log((low.length ? "LOW " : "OK ") + Object.keys(c).map(k => k + " " + s(k)).join(", ") + " · LCP " + r.audits["largest-contentful-paint"].displayValue + ", CLS " + r.audits["cumulative-layout-shift"].displayValue + (failed.length ? " · failing: " + failed.join(", ") : ""));
    ' "$WORK/lh.json" "$MIN_PERF" "$MIN_OTHER")
    if [ "${RESULT%% *}" = OK ]; then echo "  ok     $P: ${RESULT#OK }"; else echo "  CHYBA  $P: ${RESULT#LOW }"; ERRORS=$((ERRORS+1)); fi
  done
  if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "  CHYBA  application error log:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
done

echo
[ "$ERRORS" -eq 0 ] && echo "OUTPUT BUDGET OK" || { echo "ERRORS: $ERRORS"; exit 1; }
