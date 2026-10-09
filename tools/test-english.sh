#!/usr/bin/env bash
# Kaleta – English must not show Czech. Clean English installs of every starter site; the installer screens, the public site
# (every starter page, news, search, 404, privacy policy) and the admin screens (including messages after saving) are rendered
# and their visible text, titles, placeholders and labels checked by tools/find-czech.php: Czech letters, Czech dictionary keys that
# have an English translation (= a missing t() or lookup) and frequent Czech words without diacritics. Admin scripts: every Czech
# text in image/*.js needs an entry in image/jazyky/admin-en.js. A hit means a string without a translation (tools/add-translations.py)
# or a text printed without t(). Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME (kaleta_test_en) DB_USER DB_PASS PORT (8096).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_en}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8096}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; JAR="$WORK/jar"; FOUND=0; SCREENS=0
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { for pid in "${SERVER_PID:-}" "${ENV_PID:-}"; do [ -z "$pid" ] || kill "$pid" 2>/dev/null || true; done; rm -rf "$WORK"; }
trap cleanup EXIT

fail() { echo "  CHYBA  $1"; FOUND=$((FOUND+1)); }
sql() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
token() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }
# check <label> <file>: the visible text of a saved page has no Czech
check() { SCREENS=$((SCREENS+1)); local hits; hits=$(php "$ROOT/tools/find-czech.php" ${GERMAN:+--de} "$2") || { fail "$1"; echo "$hits"; }; }
# page <label> <path> [expected HTTP code] [cookie jar]: fetch a page and check it
page() {
  local code; code=$(curl -s ${4:+-b "$4"} -o "$WORK/page.html" -w '%{http_code}' "$B$2")
  [ "$code" = "${3:-200}" ] || fail "$1 ($2): HTTP $code, expected ${3:-200}"
  check "$1 ($2)" "$WORK/page.html"
}
# headings <label>: the page in page.html has exactly one h1 and its headings do not skip a level (the builder's pre-publish check)
headings() {
  local msg; msg=$(php -r 'require $argv[2] . "/system/bootstrap.php"; $d = Dom\HTMLDocument::createFromString(file_get_contents($argv[1]), LIBXML_NOERROR);
    $h = array_map(fn ($e) => (int) $e->tagName[1], iterator_to_array($d->querySelectorAll("main h1, main h2, main h3, main h4, main h5, main h6")));
    $single = count(array_keys($h, 1)); if ($single !== 1) { echo "$single h1"; exit; }
    foreach ($h as $i => $u) { if ($i > 0 && $u > $h[$i - 1] + 1) { echo "h", $h[$i - 1], " → h", $u; exit; } }' "$WORK/page.html" "$ROOT")
  [ -z "$msg" ] || fail "$1: headings ($msg)"
}
login() { # login <jar> <user> <password>
  curl -s -c "$1" -o "$WORK/login.html" "$B/admin.php"
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(token "$WORK/login.html")" -d "user=$2" --data-urlencode "password=$3"
}
PASSWORD="En-$(date +%s)-check"
ALL=(novinky poptavky newsletter bookings statistika presmerovani jazyky api asistent whistleblowing claude) # 3.2: Bookings and Whistleblowing are features, off unless ticked
install() { # install <starter> <extension…>: a clean English install (the installer removes itself, it is put back for the next one)
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
  rm -f "$WORK/web/config.php" "$JAR"; cp "$WORK/install.php" "$WORK/web/install.php"; rm -rf "$WORK/web/storage/cache"; mkdir -p "$WORK/web/storage/cache"
  local ext=(); for e in "${@:2}"; do ext+=(-d "rozsireni[]=$e"); done
  curl -s -o "$WORK/install.html" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ -d nazev_webu=Acme -d "web=$1" -d user=admin -d jmeno=Alex -d email=office@example.com \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" "${ext[@]}"
  [ ! -f "$WORK/web/install.php" ] || { echo "  CHYBA  English install of $1 failed"; sed 's/<[^>]*>//g' "$WORK/install.html" | grep -v '^\s*$' | head -20; exit 1; }
  check "installer: finished ($1)" "$WORK/install.html"
  if [[ " ${*:2} " == *" claude "* ]]; then grep -q "<code>$B/mcp</code>" "$WORK/install.html" || fail "installer: no Claude address after installing ($1)"; fi
}
public_site() { # public_site <starter>: every visible page, news, search, 404 and the privacy policy, as a visitor sees them
  local slug
  for slug in $(sql "SELECT IF(ids = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), '', seo_link) FROM ka_stranky WHERE zobrazit = 1 ORDER BY poradi"); do
    page "$1: page" "/$slug"; headings "$1: page /$slug"
  done
  [ "$(sql "SELECT COUNT(*) FROM ka_stranky WHERE stavba LIKE '%\"typ\":\"obrazek\"%' AND stavba NOT LIKE '%\"src\":\"media/%'")" = 0 ] || fail "$1: a starter page has an image slot without an image"
  if [ -n "$(sql "SELECT 1 FROM ka_nastaveni WHERE promenna = 'extensions' AND FIND_IN_SET('novinky', hodnota)")" ]; then
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
if hits=$(php "$ROOT/tools/find-czech.php" --js); then echo "  ok     image/*.js ↔ image/jazyky/admin-en.js"; else fail "texts in image/*.js without an entry in image/jazyky/admin-en.js"; echo "$hits" | sed 's/^/         /'; fi
# 3.5: a Czech text outside t() in the PHP sources reaches an English administration as it is (the Mail tab showed one)
if hits=$(php "$ROOT/tools/find-czech.php" --php); then echo "  ok     no untranslated Czech literals in the PHP sources"; else fail "Czech literals outside t() in the PHP sources"; echo "$hits" | sed 's/^/         /'; fi

mkdir "$WORK/web"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
cp "$WORK/web/install.php" "$WORK/install.php"
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done

echo "== Installer"
page "installer" "/install.php?language=en"
grep -q 'the /news listing' "$WORK/page.html" && ! grep -q '/novinky' "$WORK/page.html" || fail "installer: the News feature does not name the English news address"
GERMAN=1; page "German installer" "/install.php?language=de"; GERMAN=
grep -q 'Datenbank' "$WORK/page.html" || fail "German installer: not in German"
curl -s -o "$WORK/page.html" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d db_user=nosuchuser \
  -d db_password=wrong -d db_prefix=ka_ -d nazev_webu=Acme -d web=firemni -d user=admin -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD"
check "installer: wrong database user" "$WORK/page.html"
# (with the bootstrap: on PHP 8.3 Dom\HTMLDocument comes from system/compat)
php -r 'require $argv[2] . "/system/bootstrap.php"; $d = Dom\HTMLDocument::createFromString(file_get_contents($argv[1]), LIBXML_NOERROR); $c = $d->querySelector("#db_user")?->parentNode?->textContent ?? "";
  exit(str_contains($c, "user name or password") && !str_contains(file_get_contents($argv[1]), "SQLSTATE") ? 0 : 1);' "$WORK/page.html" "$ROOT" \
  || fail "installer: a wrong database user is not reported in plain words at the User field"
curl -s -o "$WORK/page.html" -X POST "$B/install.php" -d jazyk=en -d db_name= -d db_user= -d user=admin --data-urlencode "password=$PASSWORD" -d password2=other
check "installer: missing fields and passwords that do not match" "$WORK/page.html"

echo "== German installation through the web installer (2.5)"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
rm -f "$WORK/web/config.php"; cp "$WORK/install.php" "$WORK/web/install.php"
curl -s -o "$WORK/install.html" -X POST "$B/install.php" -d jazyk=de -d register=informal -d jazyk_webu=de --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ --data-urlencode "nazev_webu=Acme GmbH" -d web=firemni -d user=admin -d email=office@example.com \
  --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=claude'
GERMAN=1; check "German installer: finished" "$WORK/install.html"; GERMAN=
grep -q 'Mit Claude aufbauen' "$WORK/install.html" && grep -q "<code>$B/mcp</code>" "$WORK/install.html" || fail "German installer: no Claude address on the last screen"
[ ! -f "$WORK/web/install.php" ] || fail "German installer: install.php did not delete itself"
[ "$(sql "SELECT CONCAT(u.user, ':', u.jazyk, ':', n.hodnota) FROM ka_uzivatele u, ka_nastaveni n WHERE n.promenna = 'site_language'")" = "admin:de:de" ] || fail "German installer: the admin and the site are not German"
[ "$(sql "SELECT CONCAT(u.register, ':', n.hodnota) FROM ka_uzivatele u, ka_nastaveni n WHERE n.promenna = 'german_register'")" = "informal:informal" ] || fail "German installer: the form of address (du) is not saved for the admin and the site"
curl -s -o "$WORK/page.html" "$B/"; grep -q 'lang="de"' "$WORK/page.html" && grep -q 'Acme GmbH' "$WORK/page.html" || fail "German installer: the German home page is not there"
login "$JAR" admin "$PASSWORD"; GERMAN=1; page "German admin after the installation" "/admin.php" 200 "$JAR"; GERMAN=
grep -q 'Einstellungen' "$WORK/page.html" || fail "German installer: the first administrator does not see the German admin"
grep -q 'admin-de-du.js' "$WORK/page.html" || fail "German installer: the first administrator (du) does not get the script overlay"
echo "  ok     German installation: German site, admin and the Claude address"

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
PRIVACY=$(sql "SELECT CONCAT(titulek, '|', zobrazit, '|', text LIKE '%This policy explains%') FROM ka_stranky WHERE seo_link = 'privacy-policy'")
[ "$PRIVACY" = "Privacy policy|0|1" ] && echo "  ok     English install: privacy policy in English, hidden until completed" || fail "privacy policy page after English install: $PRIVACY"
login "$JAR" admin "$PASSWORD"
public_site firemni
# 3.5 (UXA-06): an English install uses English names and neutral examples – the home page slug, the news address, the
# examples in the forms; it claims no country nobody set
HOMESLUG=$(sql "SELECT seo_link FROM ka_stranky WHERE ids = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page')")
[ "$HOMESLUG" = home ] && [ "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/home")" = "301 $B/" ] || fail "English install: the home page slug is $HOMESLUG (expected home, and /home → /)"
curl -s -o "$WORK/page.html" "$B/"; grep -q '"addressCountry"' "$WORK/page.html" && fail "English install: the structured data claim a country nobody set"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=pages&action=new"; grep -q 'e.g. about-us' "$WORK/page.html" || fail "English admin: the URL example of a new page is not English"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=extensions"; grep -q 'the /news listing' "$WORK/page.html" && ! grep -q '/novinky' "$WORK/page.html" || fail "English admin: Features do not name the site's news address"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=business"; grep -qE 'CZ12345678|\+420|Mapy\.cz|CZ, SK' "$WORK/page.html" && fail "English admin: Czech-only examples in the company details"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=enquiries"; grep -q 'practice in CZ' "$WORK/page.html" && fail "English admin: the retention hint assumes the Czech Republic"
NEWS=$(sql "SELECT idc FROM ka_novinky LIMIT 1")
ADMIN_SCREENS=("" "module=pages" "module=pages&action=new" "module=pages&action=builder&id=1" "module=enquiries" "module=parts" "module=parts&action=builder&type=hlavicka&language=" \
  "module=components" "module=collections" "module=collections&action=new" "module=news" "module=news&action=new" "module=news&action=edit&id=$NEWS" "module=categories" "module=categories&action=new" \
  "module=tags" "module=media" "module=stats" "module=appearance" "module=menu" "module=users" "module=users&action=new" "module=roles" "module=roles&action=new" "module=redirects" \
  "module=changelog" "module=transfer" "module=extensions" "module=claude_settings" "module=addons" "module=subscribers" "module=newsletters" "module=newsletters&action=new" "module=parts&action=templates&type=hlavicka" "module=parts&action=templates&type=paticka" "action=account" "module=settings&tab=general" "module=business" "module=settings&tab=seo" \
  "module=settings&tab=analytics" "module=settings&tab=cookies" "module=settings&tab=mail" "module=settings&tab=backups" "module=status" \
  "module=popups" "module=popups&action=new" "module=notebook" "module=notebook&action=edit" "module=requests" "module=requests&action=new" "module=schedules" "module=schedules&action=edit" \
  "module=bookings" "module=bookings&action=new" "module=bookings&action=services&new=1" "module=bookings&action=staff" "module=bookings&action=staff_edit" "module=whistleblowing")
for u in "${ADMIN_SCREENS[@]}"; do
  page "admin.php?$u" "/admin.php?$u" 200 "$JAR"
done
# 2.4: the same screens in the German admin – no Czech either; the menu and the guide link are in German
sql "UPDATE ka_uzivatele SET jazyk = 'de' WHERE user = 'admin'"; GERMAN=1
for u in "${ADMIN_SCREENS[@]}"; do
  page "German admin.php?$u" "/admin.php?$u" 200 "$JAR"
done
grep -q 'Einstellungen' "$WORK/page.html" && grep -q 'So funktioniert es' "$WORK/page.html" || fail "German admin: the settings screen is not in German"
# issue #20: the informal German admin (du) shows the same screens, loads the overlay of the scripts and no longer addresses with Sie
sql "UPDATE ka_uzivatele SET register = 'informal' WHERE user = 'admin'"
for u in "${ADMIN_SCREENS[@]}"; do
  page "German informal admin.php?$u" "/admin.php?$u" 200 "$JAR"
done
grep -q 'admin-de-du.js' "$WORK/page.html" || fail "informal German admin: the script overlay admin-de-du.js is not loaded"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=settings&tab=mail"
grep -qE '(Geben|Wählen|Tragen|Speichern|Verwenden|Prüfen|Klicken) Sie ' "$WORK/page.html" && fail "informal German admin: a formal imperative (Sie) is still in the mail settings"
sql "UPDATE ka_uzivatele SET jazyk = '', register = '' WHERE user = 'admin'"; GERMAN=

# messages after saving: settings, menu, an upload over the server limit and a news item saved by its author
TOKEN=$(token "$WORK/page.html")
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Acme Ltd" -d company_country=GB
check "message after saving settings" "$WORK/page.html"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"novinky","text":""}]'
check "message after saving a menu" "$WORK/page.html"
head -c $((3 * 1024 * 1024)) /dev/zero > "$WORK/big.jpg"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?module=media&action=upload" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/big.jpg;type=image/jpeg"
check "message after an upload over the server limit" "$WORK/page.html"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Tom -d user=tom --data-urlencode "password=$PASSWORD" -d admin=0
login "$WORK/jar-tom" tom "$PASSWORD"
curl -s -b "$WORK/jar-tom" -o "$WORK/page.html" "$B/admin.php?module=news&action=new"
check "author: new news item" "$WORK/page.html"
curl -s -L -b "$WORK/jar-tom" -c "$WORK/jar-tom" -o "$WORK/page.html" -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$(token "$WORK/page.html")" -d idc=0 -d titulek=Draft -d tema=1 -d 'uvod=<p>Lead</p>'
check "author: message after saving a news item" "$WORK/page.html"

# pop-ups: a new one from a template (content in the site language), its settings, the list and the message after turning it on
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=popups&action=new"; TOKEN=$(token "$WORK/page.html")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=popups&action=create" -d "_csrf=$TOKEN" -d vzor=magnet -d nazev=
PP=$(sql "SELECT idpp FROM ka_popupy ORDER BY idpp DESC LIMIT 1")
page "pop-up settings" "/admin.php?module=popups&action=edit&id=$PP" 200 "$JAR"
page "pop-up in the builder" "/admin.php?module=popups&action=builder&id=$PP" 200 "$JAR"
page "pop-up template on the builder canvas" "/_popup/$PP?build=koncept&editor=1" 200 "$JAR"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/page.html" -X POST "$B/admin.php?module=popups&action=toggle" -d "_csrf=$TOKEN" -d "idpp=$PP"
check "message: an unpublished pop-up cannot be turned on" "$WORK/page.html"
page "pop-up list" "/admin.php?module=popups" 200 "$JAR"

# a site without news: the empty list
sql "UPDATE ka_novinky SET visible = 0"; rm -rf "$WORK/web/storage/cache/stranky"
page "firemni: news without news items" /news

[ "$FOUND" = 0 ] && echo "  ok     English installer, public site and admin without Czech ($SCREENS screens)" || { echo "NALEZENO CHYB: $FOUND"; exit 1; }
