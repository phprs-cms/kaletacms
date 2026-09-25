#!/usr/bin/env bash
# Kaleta – English must not show Czech. Clean English installs of every starter site; the installer screens, the public site
# (every starter page, news, search, 404, privacy policy) and the admin screens (including messages after saving) are rendered
# and their visible text, titles, placeholders and labels checked by tools/cestina.php: Czech letters, Czech dictionary keys that
# have an English translation (= a missing t() or lookup) and frequent Czech words without diacritics. Admin scripts: every Czech
# text in image/*.js needs an entry in image/jazyky/admin-en.js. A hit means a string without a translation (tools/slovnik.py)
# or a text printed without t(). Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME (kaleta_test_en) DB_USER DB_PASS PORT (8096).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_en}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8096}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; JAR="$WORK/jar"; FOUND=0; SCREENS=0
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT

fail() { echo "  CHYBA  $1"; FOUND=$((FOUND+1)); }
sql() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
token() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }
# check <label> <file>: the visible text of a saved page has no Czech
check() { SCREENS=$((SCREENS+1)); local hits; hits=$(php "$ROOT/tools/cestina.php" "$2") || { fail "$1"; echo "$hits"; }; }
# page <label> <path> [expected HTTP code] [cookie jar]: fetch a page and check it
page() {
  local code; code=$(curl -s ${4:+-b "$4"} -o "$WORK/page.html" -w '%{http_code}' "$B$2")
  [ "$code" = "${3:-200}" ] || fail "$1 ($2): HTTP $code, expected ${3:-200}"
  check "$1 ($2)" "$WORK/page.html"
}
# headings <label>: the page in page.html has exactly one h1 and its headings do not skip a level (the builder's pre-publish check)
headings() {
  local msg; msg=$(php -r '$d = Dom\HTMLDocument::createFromString(file_get_contents($argv[1]), LIBXML_NOERROR);
    $h = array_map(fn ($e) => (int) $e->tagName[1], iterator_to_array($d->querySelectorAll("main h1, main h2, main h3, main h4, main h5, main h6")));
    $jedna = count(array_keys($h, 1)); if ($jedna !== 1) { echo "$jedna h1"; exit; }
    foreach ($h as $i => $u) { if ($i > 0 && $u > $h[$i - 1] + 1) { echo "h", $h[$i - 1], " → h", $u; exit; } }' "$WORK/page.html")
  [ -z "$msg" ] || fail "$1: headings ($msg)"
}
login() { # login <jar> <user> <password>
  curl -s -c "$1" -o "$WORK/login.html" "$B/admin.php"
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(token "$WORK/login.html")" -d "user=$2" --data-urlencode "password=$3"
}
PASSWORD="En-$(date +%s)-check"
ALL=(novinky poptavky newsletter statistika presmerovani jazyky api asistent claude)
install() { # install <starter> <extension…>: a clean English install (the installer removes itself, it is put back for the next one)
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
  rm -f "$WORK/web/config.php" "$JAR"; cp "$WORK/install.php" "$WORK/web/install.php"; rm -rf "$WORK/web/storage/cache"; mkdir -p "$WORK/web/storage/cache"
  local ext=(); for e in "${@:2}"; do ext+=(-d "rozsireni[]=$e"); done
  curl -s -o "$WORK/install.html" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ -d nazev_webu=Acme -d "web=$1" -d user=admin -d jmeno=Alex -d email=office@example.com \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" "${ext[@]}"
  [ ! -f "$WORK/web/install.php" ] || { echo "  CHYBA  English install of $1 failed"; sed 's/<[^>]*>//g' "$WORK/install.html" | grep -v '^\s*$' | head -20; exit 1; }
  check "installer: finished ($1)" "$WORK/install.html"
}
public_site() { # public_site <starter>: every visible page, news, search, 404 and the privacy policy, as a visitor sees them
  local slug
  for slug in $(sql "SELECT IF(ids = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'titulni_stranka'), '', seo_link) FROM ka_stranky WHERE zobrazit = 1 ORDER BY poradi"); do
    page "$1: page" "/$slug"; headings "$1: page /$slug"
  done
  [ "$(sql "SELECT COUNT(*) FROM ka_stranky WHERE stavba LIKE '%\"typ\":\"obrazek\"%' AND stavba NOT LIKE '%\"src\":\"media/%'")" = 0 ] || fail "$1: a starter page has an image slot without an image"
  if [ -n "$(sql "SELECT 1 FROM ka_nastaveni WHERE promenna = 'rozsireni' AND FIND_IN_SET('novinky', hodnota)")" ]; then
    page "$1: news" /news
    code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/novinky/kategorie/x")
    [ "$code" = "301 $B/news/category/x" ] || fail "$1: the Czech address /novinky/kategorie/x does not redirect to /news/category/x ($code)"
    page "$1: news item" "/news/$(sql "SELECT seo_link FROM ka_novinky LIMIT 1")"
    page "$1: news item, signed in" "/news/$(sql "SELECT seo_link FROM ka_novinky LIMIT 1")" 200 "$JAR"
    page "$1: news category" "/news/category/$(sql "SELECT seo_link FROM ka_kategorie LIMIT 1")"
  fi
  page "$1: search with results" "/search?q=contact"
  grep -q 'href="/contact"' "$WORK/page.html" || fail "$1: search does not find the Contact page"
  page "$1: search without results" "/search?q=zzqqxx"
  page "$1: not found" /this-page-does-not-exist 404
  sql "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'privacy-policy'"; rm -rf "$WORK/web/storage/cache/stranky"
  page "$1: privacy policy (published)" /privacy-policy
}

echo "== Admin scripts: every Czech text has an English entry"
if hits=$(php "$ROOT/tools/cestina.php" --js); then echo "  ok     image/*.js ↔ image/jazyky/admin-en.js"; else fail "texts in image/*.js without an entry in image/jazyky/admin-en.js"; echo "$hits" | sed 's/^/         /'; fi

mkdir "$WORK/web"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
cp "$WORK/web/install.php" "$WORK/install.php"
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done

echo "== Installer"
page "installer" "/install.php?jazyk=en"
curl -s -o "$WORK/page.html" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d db_user=nosuchuser \
  -d db_password=wrong -d db_prefix=ka_ -d nazev_webu=Acme -d web=firemni -d user=admin -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD"
check "installer: wrong database user" "$WORK/page.html"
php -r '$d = Dom\HTMLDocument::createFromString(file_get_contents($argv[1]), LIBXML_NOERROR); $c = $d->querySelector("#db_user")?->parentNode?->textContent ?? "";
  exit(str_contains($c, "user name or password") && !str_contains(file_get_contents($argv[1]), "SQLSTATE") ? 0 : 1);' "$WORK/page.html" \
  || fail "installer: a wrong database user is not reported in plain words at the User field"
curl -s -o "$WORK/page.html" -X POST "$B/install.php" -d jazyk=en -d db_name= -d db_user= -d user=admin --data-urlencode "password=$PASSWORD" -d password2=other
check "installer: missing fields and passwords that do not match" "$WORK/page.html"

echo "== Crafts starter without the Forms extension"
install remeslo novinky statistika presmerovani
login "$JAR" admin "$PASSWORD"
public_site remeslo
curl -s -o "$WORK/page.html" "$B/contact"; grep -q 'Contact details' "$WORK/page.html" || fail "remeslo: the Contact page without Forms has no contact details"

echo "== Consulting starter"
install poradenstvi "${ALL[@]}"
login "$JAR" admin "$PASSWORD"
public_site poradenstvi

echo "== Business starter and the admin"
install firemni "${ALL[@]}"
ZASADY=$(sql "SELECT CONCAT(titulek, '|', zobrazit, '|', text LIKE '%This policy explains%') FROM ka_stranky WHERE seo_link = 'privacy-policy'")
[ "$ZASADY" = "Privacy policy|0|1" ] && echo "  ok     English install: privacy policy in English, hidden until completed" || fail "privacy policy page after English install: $ZASADY"
login "$JAR" admin "$PASSWORD"
public_site firemni
NEWS=$(sql "SELECT idc FROM ka_novinky LIMIT 1")
for u in "" "modul=stranky" "modul=stranky&akce=novy" "modul=stranky&akce=stavitel&id=1" "modul=poptavky" "modul=casti" "modul=casti&akce=stavitel&typ=hlavicka&jazyk=" \
  "modul=komponenty" "modul=kolekce" "modul=kolekce&akce=novy" "modul=novinky" "modul=novinky&akce=novy" "modul=novinky&akce=edit&id=$NEWS" "modul=kategorie" "modul=kategorie&akce=novy" \
  "modul=stitky" "modul=intergal" "modul=stat" "modul=vzhled" "modul=menu" "modul=users" "modul=users&akce=novy" "modul=role" "modul=role&akce=novy" "modul=presmerovani" \
  "modul=protokol" "modul=prenos" "modul=rozsireni" "modul=odberatele" "akce=ucet" "modul=config&zalozka=zakladni" "modul=config&zalozka=firma" "modul=config&zalozka=seo" \
  "modul=config&zalozka=mereni" "modul=config&zalozka=cookies" "modul=config&zalozka=posta" "modul=config&zalozka=zalohy" "modul=config&zalozka=stav"; do
  page "admin.php?$u" "/admin.php?$u" 200 "$JAR"
done

# messages after saving: settings, menu, an upload over the server limit and a news item saved by its author
TOKEN=$(token "$WORK/page.html")
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?modul=config&akce=uloz" -d "_csrf=$TOKEN" -d zalozka=firma --data-urlencode "firma_nazev=Acme Ltd" -d firma_zeme=GB
check "message after saving settings" "$WORK/page.html"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?modul=menu&akce=uloz&umisteni=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"novinky","text":""}]'
check "message after saving a menu" "$WORK/page.html"
head -c $((3 * 1024 * 1024)) /dev/zero > "$WORK/big.jpg"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?modul=intergal&akce=nahraj" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/big.jpg;type=image/jpeg"
check "message after an upload over the server limit" "$WORK/page.html"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=users&akce=uloz" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Tom -d user=tom --data-urlencode "password=$PASSWORD" -d admin=0
login "$WORK/jar-tom" tom "$PASSWORD"
curl -s -b "$WORK/jar-tom" -o "$WORK/page.html" "$B/admin.php?modul=novinky&akce=novy"
check "author: new news item" "$WORK/page.html"
curl -s -L -b "$WORK/jar-tom" -c "$WORK/jar-tom" -o "$WORK/page.html" -X POST "$B/admin.php?modul=novinky&akce=uloz" -d "_csrf=$(token "$WORK/page.html")" -d idc=0 -d titulek=Draft -d tema=1 -d 'uvod=<p>Lead</p>'
check "author: message after saving a news item" "$WORK/page.html"

# a site without news: the empty list
sql "UPDATE ka_novinky SET visible = 0"; rm -rf "$WORK/web/storage/cache/stranky"
page "firemni: news without news items" /news

[ "$FOUND" = 0 ] && echo "  ok     English installer, public site and admin without Czech ($SCREENS screens)" || { echo "NALEZENO CHYB: $FOUND"; exit 1; }
