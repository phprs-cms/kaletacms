#!/usr/bin/env bash
# Kaleta – screenshots for the project website, README and docs (docs/screenshots/*.png).
# A clean English install of each starter site with sample content, then tools/screenshots.mjs drives Chrome (Playwright).
# Needs PHP, MySQL, Node and Google Chrome (or CHROME=/path/to/chrome). The database DB_NAME is DROPPED and recreated.
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_screens) DB_USER (root) DB_PASS () PORT (8097)
#   KEEP=1 leaves the last install running for a look around (Ctrl+C to stop).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_screens}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8097}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; OUT="$ROOT/docs/screenshots"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT
mkdir -p "$OUT"

echo "== Playwright"
npm i --silent --prefix "$WORK/pw" playwright-core@1 > /dev/null

# starter site => site name for the sample company
for SITE in firemni remeslo poradenstvi; do
  case "$SITE" in
    firemni) NAME="Northfield Engineering" ;;
    remeslo) NAME="Oak & Iron Joinery" ;;
    poradenstvi) NAME="Clearpath Advisory" ;;
  esac
  echo "== $SITE: clean install"
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null || true
  rm -rf "$WORK/web"; mkdir "$WORK/web"
  (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
  mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
  (cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
  PASSWORD="Screens-$(openssl rand -hex 8)"
  curl -s -o "$WORK/response" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ --data-urlencode "nazev_webu=$NAME" -d "web=$SITE" -d user=admin -d "jmeno=Alex Morgan" -d email=alex@example.com \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
    -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=newsletter' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani' -d 'rozsireni[]=claude'
  [ ! -f "$WORK/web/install.php" ] || { echo "install failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
  # sample data the empty install lacks: enquiries in the inbox and a few visits for the dashboard
  "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -e "
    INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES
      (NOW() - INTERVAL 3 DAY, 'Enquiry', '/contact', 'priya@example.com', '[[\"Name\",\"Priya Shah\"],[\"Email\",\"priya@example.com\"],[\"How can we help you?\",\"Thanks for the call yesterday. Sending the floor plan as promised.\"]]', 2),
      (NOW() - INTERVAL 1 DAY, 'Enquiry', '/services', 'daniel@example.com', '[[\"Name\",\"Daniel Novak\"],[\"Email\",\"daniel@example.com\"],[\"How can we help you?\",\"Do you also maintain existing installations, or only new ones?\"]]', 1),
      (NOW() - INTERVAL 2 HOUR, 'Enquiry', '/contact', 'hannah@example.com', '[[\"Name\",\"Hannah Clarke\"],[\"Email\",\"hannah@example.com\"],[\"Phone\",\"+44 20 7946 0958\"],[\"How can we help you?\",\"Could you quote for a new staircase and two oak doors? We are in Leeds and flexible on dates.\"]]', 0);"
  echo "== $SITE: screenshots"
  NODE_PATH="$WORK/pw/node_modules" BASE="$B" SITE="$SITE" PASSWORD="$PASSWORD" OUT="$OUT" CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}" \
    node "$ROOT/tools/screenshots.mjs"
done

ls -1 "$OUT"
if [ -n "${KEEP:-}" ]; then echo "Running at $B/ (admin / password in \$PASSWORD above) – Ctrl+C to stop"; wait "$SERVER_PID"; fi
