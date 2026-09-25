#!/usr/bin/env bash
# Kaleta – the English admin must not show Czech. A clean English install, then every admin screen is rendered and its visible
# text searched for Czech letters. A hit means a string without a translation (system/jazyky/admin-en.php, tools/slovnik.py)
# or a text printed without t(). Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME (kaleta_test_en) DB_USER DB_PASS PORT (8096).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_en}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8096}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; JAR="$WORK/jar"; FOUND=0
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT

echo "== English admin: clean install"
mkdir "$WORK/web"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
PASSWORD="En-$(date +%s)-check"
EXT=""; for e in novinky poptavky newsletter statistika presmerovani jazyky api asistent claude; do EXT="$EXT -d rozsireni[]=$e"; done
# shellcheck disable=SC2086
curl -s -o /dev/null -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ -d nazev_webu=Acme -d web=firemni -d user=admin -d jmeno=Alex -d email= \
  --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" $EXT
[ ! -f "$WORK/web/install.php" ] || { echo "  CHYBA  English install failed"; exit 1; }
TOKEN=$(curl -s -c "$JAR" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$PASSWORD"

echo "== English admin: no Czech on screen"
for u in "" "modul=stranky" "modul=stranky&akce=novy" "modul=stranky&akce=stavitel&id=1" "modul=poptavky" "modul=casti" "modul=casti&akce=stavitel&typ=hlavicka&jazyk=" \
  "modul=komponenty" "modul=kolekce" "modul=kolekce&akce=novy" "modul=novinky" "modul=novinky&akce=novy" "modul=kategorie" "modul=stitky" "modul=intergal" "modul=stat" \
  "modul=vzhled" "modul=menu" "modul=users" "modul=users&akce=novy" "modul=role" "modul=presmerovani" "modul=protokol" "modul=prenos" "modul=rozsireni" "modul=odberatele" \
  "akce=ucet" "modul=config&zalozka=zakladni" "modul=config&zalozka=firma" "modul=config&zalozka=seo" "modul=config&zalozka=mereni" "modul=config&zalozka=cookies" \
  "modul=config&zalozka=posta" "modul=config&zalozka=zalohy" "modul=config&zalozka=stav"; do
  curl -s -b "$JAR" "$B/admin.php?$u" > "$WORK/page.html"
  # visible text, titles, placeholders and labels; the language names (Čeština…) and English words with accents are fine
  hits=$(php -r '$h = preg_replace("#<(script|style)\b.*?</\\1>#s", "", file_get_contents($argv[1]));
    preg_match_all("#(?:>|title=\"|placeholder=\"|aria-label=\"|value=\")([^<\"]*[ěščřžůťďňáéíóúýĚŠČŘŽŮŤĎŇÁÉÍÓÚÝ][^<\"]*)#u", $h, $m);
    foreach (array_unique(array_map("trim", $m[1])) as $t) { if (!preg_match("/^(Čeština|restaurant, café)$/u", $t)) echo "         ", html_entity_decode($t), "\n"; }' "$WORK/page.html")
  if [ -n "$hits" ]; then echo "  CHYBA  admin.php?$u"; echo "$hits"; FOUND=$((FOUND+1)); fi
done
[ "$FOUND" = 0 ] && echo "  ok     English admin without Czech (35 screens)" || { echo "NALEZENO CHYB: $FOUND"; exit 1; }
