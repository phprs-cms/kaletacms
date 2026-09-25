#!/usr/bin/env bash
# Kaleta - kouřový test: čistá instalace do dočasné kopie a průchod hlavními stránkami.
# Spouští se lokálně i v GitHub Actions. Databázi bere z proměnných prostředí:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (prázdné) PORT (8099) WEB (firemni | remeslo | poradenstvi)
# Databáze DB_NAME se při testu SMAŽE a vytvoří znovu.
set -euo pipefail

KOREN="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
PRACE="$(mktemp -d)"; JAR="$PRACE/cookies.txt"; B="http://127.0.0.1:$PORT"; CHYB=0
uklid() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$PRACE"; }
trap uklid EXIT

echo "== syntaxe PHP"
find "$KOREN" -name '*.php' -not -path '*/.git/*' -not -path '*/dist/*' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== čistá databáze a kopie projektu"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$PRACE/web" && (cd "$KOREN" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$PRACE/web") # soubory smazané a ještě nezapsané do gitu se nekopírují
mkdir -p "$PRACE/web/media" "$PRACE/web/storage/log" "$PRACE/web/storage/cache"
(cd "$PRACE/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$PRACE/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done

over() { # over <popis> <očekávaný kód> <adresa> [hledaný text]
  local kod; kod=$(curl -s -b "$JAR" -c "$JAR" -A "Mozilla/5.0 test" -o "$PRACE/odpoved" -w '%{http_code}' "$B$3")
  if [ "$kod" != "$2" ] || grep -qE 'Fatal error|Warning:|Deprecated:|Notice:' "$PRACE/odpoved" || { [ -n "${4:-}" ] && ! grep -q "$4" "$PRACE/odpoved"; }; then
    echo "  CHYBA  $1 ($3): kód $kod, čekal jsem $2${4:+, text „$4“}"; CHYB=$((CHYB+1))
  else echo "  ok     $1"; fi
}

POSLEDNI=$(ls "$KOREN"/system/sql/migrace/*.sql | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
grep -q "const KALETA_VERZE_DB = $POSLEDNI;" "$KOREN/system/bootstrap.php" && echo "  ok     KALETA_VERZE_DB odpovídá poslední migraci ($POSLEDNI)" || { echo "  CHYBA  KALETA_VERZE_DB v system/bootstrap.php neodpovídá poslední migraci ($POSLEDNI)"; CHYB=$((CHYB+1)); }

echo "== jednotkové testy"
php "$KOREN/tools/testy.php" || CHYB=$((CHYB+1))

csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//'; }
ocekavej() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; CHYB=$((CHYB+1)); }; }

echo "== instalace"
HESLO="Test-$(date +%s)-heslo"
curl -s -o "$PRACE/odpoved" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Testovací firma" -d "web=${WEB:-firemni}" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$HESLO" --data-urlencode "password2=$HESLO" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
grep -q "Hotovo, web běží" "$PRACE/odpoved" || { echo "  CHYBA  instalace selhala"; sed 's/<[^>]*>//g' "$PRACE/odpoved" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     instalace"
[ ! -f "$PRACE/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; CHYB=$((CHYB+1)); }

echo "== web"
over "úvodní stránka" 200 / "Testovací firma"
over "úvodní stránka má navigaci stránek a novinek" 200 / 'href="/o-nas"'
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/uvod"); ocekavej "úvodní stránka má jen jednu adresu" "$kod" "301 $B/"
over "stránka" 200 /sluzby "Služby"
over "úvodní stránka je ze sekcí builderu" 200 / 'class="stavba"'
over "služby mají otázky a odpovědi i pro vyhledávače" 200 /sluzby '"FAQPage"'
over "výpis novinek" 200 /novinky "Vítejte v Kaletě"
over "novinka" 200 /novinky/vitejte-v-kalete "Vítejte"
over "kategorie" 200 /novinky/kategorie/aktuality
over "hledání najde novinku i stránku" 200 "/hledani?q=Kontakt" 'href="/kontakt"'
for u in /rss.xml /feed.json /sitemap.xml /robots.txt /llms.txt /novinky/vitejte-v-kalete.md; do over "$u" 200 "$u"; done
over "mapa webu obsahuje novinku" 200 /sitemap.xml "/novinky/vitejte-v-kalete"
over "llms.txt vyjmenuje stránky" 200 /llms.txt "## Stránky"
over "strukturovaná data novinky" 200 /novinky/vitejte-v-kalete '"BlogPosting"'
over "zásady z instalace jsou skryté, dokud je správce nedoplní" 404 /zasady-ochrany-osobnich-udaju
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'zasady-ochrany-osobnich-udaju'"
over "zásady ochrany osobních údajů po zveřejnění" 200 /zasady-ochrany-osobnich-udaju "Jaké údaje zpracováváme"
over "patička odkazuje na zásady" 200 /o-nas 'zasady-ochrany-osobnich-udaju'
over "neexistující stránka" 404 /tohle-neexistuje
over "system/ není přístupný" 403 /system/sql/schema.sql
over "config.php není přístupný" 403 /config.php
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='neexistuje' WHERE promenna='layout'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "chybějící šablona – web běží na výchozí" 200 / "layout/zakladni/style.css"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='titulni_stranka'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "bez úvodní stránky je úvodem výpis novinek" 200 / "Vítejte v Kaletě"

echo "== administrace"
over "zapomenuté heslo – formulář" 200 "/admin.php?akce=heslo" "Poslat odkaz"
over "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?akce=heslo&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
over "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(csrf)
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); ocekavej "špatné heslo odmítnuto" "$kod" 401
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$HESLO"); ocekavej "POST bez CSRF odmítnut" "$kod" 400
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$HESLO"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('rozsireni','novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,api,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
over "přehled" 200 /admin.php "Přehled"
over "přehled: nadpis obrazovky je h1" 200 /admin.php "<h1>Přehled</h1>"
over "administrace: nadpis h1 a hlavní menu v <nav>" 200 "/admin.php?modul=stranky" '<nav class="menu-obal" aria-label="Hlavní menu">'
for m in stranky "stranky&akce=novy" poptavky casti komponenty "komponenty&akce=novy" kolekce "kolekce&akce=novy" novinky "novinky&akce=novy" "novinky&akce=odkazy" kategorie "kategorie&akce=novy" stitky intergal stat vzhled users "users&akce=novy" presmerovani protokol prenos rozsireni; do over "modul $m" 200 "/admin.php?modul=$m"; done
over "uživatelé se shrnutím oprávnění" 200 "/admin.php?modul=users" "Smí všechno"
for z in zakladni seo mereni cookies posta zalohy stav; do over "nastavení/$z" 200 "/admin.php?modul=config&zalozka=$z"; done
over "nastavení: volba úvodní stránky" 200 "/admin.php?modul=config&zalozka=zakladni" 'name="titulni_stranka"'
over "neznámý modul" 403 "/admin.php?modul=neexistuje"
over "API: novinky" 200 /api/novinky '"novinky"'
over "API: stránky" 200 /api/stranky '/kontakt"'
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('jazyky_dalsi','en') ON DUPLICATE KEY UPDATE hodnota='en'"
over "anglická verze webu" 200 /en/ 'lang="en"'
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/novinky/vitejte-v-kalete"); ocekavej "novinka jiné jazykové verze přesměruje" "$kod" 301

# neúspěšná validace novinky musí vrátit formulář s hláškou, ne chybu 500
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=novinky&akce=novy"
TOKEN=$(csrf)
kod=$(curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=novinky&akce=uloz" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$kod" = 200 ] && grep -q 'name="titulek"' "$PRACE/odpoved" && echo "  ok     chyba ve formuláři novinky vrátí formulář" || { echo "  CHYBA  validace novinky: kód $kod"; CHYB=$((CHYB+1)); }

# novinky bez kategorie (zapnuté až po instalaci): „Nová novinka“ není slepá ulička – vznikne výchozí kategorie v jazyce webu
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS kat_zaloha; CREATE TABLE kat_zaloha AS SELECT * FROM ka_kategorie; DELETE FROM ka_kategorie; UPDATE ka_nastaveni SET hodnota='en' WHERE promenna='jazyk_webu'"
over "nová novinka bez kategorie otevře editor" 200 "/admin.php?modul=novinky&akce=novy" 'name="titulek"'
ocekavej "výchozí kategorie založená v jazyce webu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(nazev)) FROM ka_kategorie")" "1:News"
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM ka_kategorie; INSERT INTO ka_kategorie SELECT * FROM kat_zaloha; DROP TABLE kat_zaloha; UPDATE ka_nastaveni SET hodnota='cs' WHERE promenna='jazyk_webu'"

# autor novinek: vidí jen své novinky a nevydává
NOVINKA=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=users&akce=uloz" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Autor -d user=autor --data-urlencode "password=$HESLO" -d admin=0
JAR2="$PRACE/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=autor --data-urlencode "password=$HESLO"
kod=$(curl -s -b "$JAR2" -o "$PRACE/odpoved" -w '%{http_code}' "$B/admin.php?modul=novinky")
[ "$kod" = 200 ] && ! grep -q "akce=edit&amp;id=$NOVINKA\"" "$PRACE/odpoved" && echo "  ok     autor nevidí cizí novinky" || { echo "  CHYBA  autor – výpis: kód $kod"; CHYB=$((CHYB+1)); }
ocekavej "autor cizí novinku neotevře" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=novinky&akce=edit&id=$NOVINKA")" 404
ocekavej "autor nemá přístup ke stránkám" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=stranky")" 403
TOKEN2=$(curl -s -b "$JAR2" "$B/admin.php?modul=novinky&akce=novy" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php?modul=novinky&akce=uloz" -d "_csrf=$TOKEN2" -d idc=0 -d titulek=XSS-test -d tema=1 \
  --data-urlencode 'uvod=<p onmouseover="alert(1)">Perex</p><script>alert(2)</script>' --data-urlencode 'text=<p><img src=x onerror=alert(3)><a href="javascript:alert(4)">odkaz</a></p>'
ocekavej "autor nevloží do novinky skript" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(uvod, text) REGEXP 'script|onerror|onmouseover|javascript' FROM ka_novinky WHERE titulek = 'XSS-test'")" "0"

echo "== firma"
over "nastavení/firma" 200 "/admin.php?modul=config&zalozka=firma" 'name="firma_hodiny"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=uloz" -d "_csrf=$TOKEN" -d zalozka=firma --data-urlencode "firma_nazev=Testovací firma s.r.o." -d firma_typ=HomeAndConstructionBusiness \
  -d firma_ico=12345678 -d firma_dic=CZ12345678 --data-urlencode "firma_ulice=Dlouhá 12" --data-urlencode "firma_mesto=Praha" --data-urlencode "firma_psc=110 00" -d firma_zeme=CZ \
  --data-urlencode "firma_telefon=+420 123 456 789" --data-urlencode "firma_hodiny=Po–Pá 8:00–17:00
So 9–12" --data-urlencode "firma_mapa=https://mapy.cz/s/abc" --data-urlencode "firma_gps=50.0875, 14.4213"
ocekavej "údaje firmy uloženy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'firma_ico'")" 12345678
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=uloz" -d "_csrf=$TOKEN" -d zalozka=firma -d firma_typ=LocalBusiness -d firma_zeme=CZ --data-urlencode "firma_hodiny=kdykoli"
ocekavej "nesrozumitelná otevírací doba odmítnuta" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%8:00%' AND hodnota NOT LIKE '%kdykoli%' FROM ka_nastaveni WHERE promenna = 'firma_hodiny'")" 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=uloz" -d "_csrf=$TOKEN" -d zalozka=firma --data-urlencode "firma_nazev=Testovací firma s.r.o." -d firma_typ=HomeAndConstructionBusiness \
  -d firma_ico=12345678 -d firma_dic=CZ12345678 --data-urlencode "firma_ulice=Dlouhá 12" --data-urlencode "firma_mesto=Praha" --data-urlencode "firma_psc=110 00" -d firma_zeme=CZ \
  --data-urlencode "firma_telefon=+420 123 456 789" --data-urlencode "firma_hodiny=Po–Pá 8:00–17:00" --data-urlencode "firma_mapa=https://mapy.cz/s/abc" --data-urlencode "firma_gps=50.0875, 14.4213"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/"
grep -q '"@type":"HomeAndConstructionBusiness"' "$PRACE/odpoved" && grep -q '"openingHoursSpecification"' "$PRACE/odpoved" && grep -q '"latitude":50.0875' "$PRACE/odpoved" && grep -q '"vatID":"CZ12345678"' "$PRACE/odpoved" \
  && echo "  ok     firma ve strukturovaných datech (LocalBusiness, otevírací doba, souřadnice)" || { echo "  CHYBA  firma ve strukturovaných datech"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/kontakt"
grep -q 'Dlouhá 12<br>110 00 Praha' "$PRACE/odpoved" && grep -q 'href="tel:+420123456789"' "$PRACE/odpoved" && grep -q '<li>Po–Pá 8:00–17:00</li>' "$PRACE/odpoved" && grep -q 'IČO 12345678, DIČ CZ12345678' "$PRACE/odpoved" \
  && echo "  ok     kontakt vypisuje údaje firmy z Nastavení" || { echo "  CHYBA  údaje firmy na kontaktu"; CHYB=$((CHYB+1)); }

echo "== vzhled webu (design systém)"
over "vzhled s předvolbami a náhledem" 200 "/admin.php?modul=vzhled" 'data-predvolba'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$PRACE/odpoved" -X POST "$B/admin.php?modul=vzhled&akce=nahled" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy][primarni]=#ff00aa' -d 'ds[zaklad_min]=18'
grep -q 'ka-barva-primarni: #ff00aa' "$PRACE/odpoved" && grep -q '"kontrasty"' "$PRACE/odpoved" && echo "  ok     živý náhled vrátí tokeny a kontrasty" || { echo "  CHYBA  náhled vzhledu"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=vzhled&akce=uloz" -d "_csrf=$TOKEN" -d layout=zakladni -d tmavy_rezim=vypnuto --data-urlencode 'ds[barvy][primarni]=#9a3412' --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280'
curl -s -o "$PRACE/odpoved" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$PRACE/odpoved" && grep -q 'ka-sirka: 80rem' "$PRACE/odpoved" && grep -q 'ka-pismo-titulky: Georgia' "$PRACE/odpoved" && echo "  ok     uložený vzhled je hned na webu" || { echo "  CHYBA  uložení vzhledu"; CHYB=$((CHYB+1)); }
grep -q 'body{' "$PRACE/odpoved" && { echo "  CHYBA  do CSS proniklo neplatné zadání barvy"; CHYB=$((CHYB+1)); } || echo "  ok     neplatná barva se nahradí výchozí"

echo "== builder stránek"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
over "builder se otevře a převede textovou stránku" 200 "/admin.php?modul=stranky&akce=stavitel&id=$IDS" 'id="stavitel-data"'
TOKEN=$(csrf)
st() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=stranky&akce=$1&id=$IDS" -d "_csrf=$TOKEN" "${@:2}"; }
STAVBA='{"v":1,"deti":[{"id":"sek1","typ":"sekce","deti":[{"id":"nad1","typ":"nadpis","znacka":"h1","obsah":{"text":"Builder test"},"styl":{"zaklad":{"barva":"primarni"},"mobil":{"velikost_pisma":"2"}},"tridy":["karta"]},{"id":"faq1","typ":"faq","obsah":{"polozky":[{"otazka":"Kolik to stojí?","odpoved":"<p>Záleží na rozsahu.</p>"}]}},{"id":"zly1","typ":"skript"}]}]}'
kod=$(st stavba_uloz --data-urlencode "stavba=$STAVBA")
[ "$kod" = 200 ] && grep -q '"ok":true' "$PRACE/odpoved" && grep -q 'Neznámý typ prvku' "$PRACE/odpoved" && echo "  ok     uložení konceptu vrátí vyčištěnou stavbu a chyby" || { echo "  CHYBA  stavba_uloz: kód $kod"; CHYB=$((CHYB+1)); }
ocekavej "neplatný JSON stavby odmítnut" "$(st stavba_uloz -d 'stavba={nesmysl')" 400
ocekavej "uložení z cizí verze odmítnuto (souběžná úprava)" "$(st stavba_uloz -d verze=0000000000000000 --data-urlencode "stavba=$STAVBA")" 409
grep -q '"konflikt":true' "$PRACE/odpoved" && grep -q 'Builder test' "$PRACE/odpoved" && echo "  ok     konflikt vrátí novější verzi ze serveru" || { echo "  CHYBA  odpověď konfliktu"; CHYB=$((CHYB+1)); }
ocekavej "publikování z cizí verze odmítnuto" "$(st stavba_publikuj -d verze=0000000000000000)" 409
ocekavej "přepsání cizí verze na přání" "$(st stavba_uloz -d verze=0000000000000000 -d prepsat=1 --data-urlencode "stavba=$STAVBA")" 200
ocekavej "builder bez CSRF odmítnut" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?modul=stranky&akce=stavba_uloz&id=$IDS" --data-urlencode "stavba=$STAVBA")" 400
ocekavej "knihovna sekcí jen přes POST" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=stranky&akce=stavba_sekce&id=$IDS&klic=faq")" 404
kod=$(st "stavba_sekce&klic=vyhody"); [ "$kod" = 200 ] && grep -q '"karta"' "$PRACE/odpoved" && echo "  ok     sekce z knihovny založí své třídy" || { echo "  CHYBA  stavba_sekce: kód $kod"; CHYB=$((CHYB+1)); }
kod=$(st stavba_trida -d nazev=karta --data-urlencode 'styl={"zaklad":{"pozadi":"plocha","odsazeni_y":"l"}}' --data-urlencode 'css=letter-spacing: 0.01em; background: url(x)')
[ "$kod" = 200 ] && grep -q 'Nepovolená deklarace' "$PRACE/odpoved" && echo "  ok     třída uložena, nebezpečné CSS zahozeno" || { echo "  CHYBA  stavba_trida: kód $kod"; CHYB=$((CHYB+1)); }
ocekavej "neplatný název třídy odmítnut" "$(st stavba_trida -d 'nazev=Karta Velka')" 400
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"; ! grep -q "Builder test" "$PRACE/odpoved" && echo "  ok     koncept není před publikováním na webu" || { echo "  CHYBA  koncept je na webu dřív, než se publikuje"; CHYB=$((CHYB+1)); }
over "náhled konceptu pro editor" 200 "/o-nas?stavba=koncept&editor=1" 'data-ka-id="nad1"'
over "náhled konceptu se neindexuje" 200 "/o-nas?stavba=koncept" 'noindex'
curl -s -o "$PRACE/odpoved" "$B/o-nas?stavba=koncept&editor=1"; ! grep -q "Builder test" "$PRACE/odpoved" && echo "  ok     náhled konceptu nevidí návštěvník" || { echo "  CHYBA  koncept vidí nepřihlášený"; CHYB=$((CHYB+1)); }
kod=$(st stavba_publikuj); ocekavej "publikování stavby" "$kod" 200
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"
grep -q '<h1 id="s-nad1" class="karta">Builder test</h1>' "$PRACE/odpoved" && echo "  ok     publikovaná stavba na webu, jedna značka na prvek" || { echo "  CHYBA  stavba na webu"; CHYB=$((CHYB+1)); }
grep -q 'data-ka-id' "$PRACE/odpoved" && { echo "  CHYBA  značky editoru na veřejném webu"; CHYB=$((CHYB+1)); } || echo "  ok     bez značek editoru na veřejném webu"
grep -q '@layer prvky' "$PRACE/odpoved" && grep -q '#s-nad1 { color: var(--ka-barva-primarni); }' "$PRACE/odpoved" && grep -q '.karta { background-color: var(--ka-barva-plocha)' "$PRACE/odpoved" && echo "  ok     CSS prvků a tříd ve vrstvách" || { echo "  CHYBA  CSS stavby"; CHYB=$((CHYB+1)); }
grep -q '"FAQPage"' "$PRACE/odpoved" && echo "  ok     otázky a odpovědi jako strukturovaná data" || { echo "  CHYBA  FAQPage chybí"; CHYB=$((CHYB+1)); }
over "hledání najde obsah stavby" 200 "/hledani?q=Builder+test" 'Nalezeno: 1'
st stavba_uloz --data-urlencode "stavba=${STAVBA/Builder test/Druhá verze}" > /dev/null; st stavba_publikuj > /dev/null
ocekavej "předchozí publikovaná verze je v historii" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")" 1
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")
st stavba_obnov -d "idr=$IDR" > /dev/null; grep -q 'Builder test' "$PRACE/odpoved" && echo "  ok     obnovení verze do konceptu" || { echo "  CHYBA  stavba_obnov"; CHYB=$((CHYB+1)); }
st stavba_zahod > /dev/null; grep -q 'Druhá verze' "$PRACE/odpoved" && echo "  ok     zahození změn vrátí publikovanou stavbu" || { echo "  CHYBA  stavba_zahod"; CHYB=$((CHYB+1)); }
ocekavej "autor novinek do builderu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=stranky&akce=stavitel&id=$IDS")" 403
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=stavba_text" -d "_csrf=$TOKEN" -d "ids=$IDS"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q "<h1>Druhá verze</h1>" "$PRACE/odpoved" && grep -q 'class="obal obsah"' "$PRACE/odpoved" && echo "  ok     návrat k textu zachová obsah stavby bez rozložení" || { echo "  CHYBA  stavba_text"; CHYB=$((CHYB+1)); }

echo "== Claude (MCP): builder"
TOK="kaleta_$(printf 'a%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$TOK")', NOW() FROM ka_uzivatele WHERE user = 'admin'"
mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
mcp stavba_schema '{}' > "$PRACE/odpoved"; grep -q 'knihovna' "$PRACE/odpoved" && grep -q 'ka-mezera' "$PRACE/odpoved" && echo "  ok     MCP: schéma builderu" || { echo "  CHYBA  MCP stavba_schema"; head -c 300 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
mcp stavba_z_html '{"titulek":"Z HTML","html":"<style>.uvod-x { padding-block: var(--ka-mezera-2xl); } .uvod-x h1 { color: red }</style><header class=\"uvod-x\"><div class=\"container\"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class=\"btn\" href=\"/kontakt\">Kontakt</a></div></header><form><input></form>"}' > "$PRACE/odpoved"
grep -q 'koncept' "$PRACE/odpoved" && grep -q 'Formul' "$PRACE/odpoved" && grep -q 'vynech.*btn' "$PRACE/odpoved" && echo "  ok     MCP: HTML převedeno na koncept stavby s hlášením (i formulář)" || { echo "  CHYBA  MCP stavba_z_html"; head -c 600 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
IDZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'z-html'")
ocekavej "MCP: nová stránka zůstává skrytá a bez publikované stavby" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba IS NULL, '/', stavba_koncept LIKE '%od Clauda%') FROM ka_stranky WHERE ids = $IDZ")" "0/1/1"
ocekavej "MCP: třída z <style> uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT css FROM ka_tridy WHERE nazev = 'uvod-x'")" "padding-block: var(--ka-mezera-2xl);"
mcp vloz_sekci "{\"id\":$IDZ,\"sekce\":\"faq\"}" > /dev/null
mcp publikuj_stavbu "{\"id\":$IDZ}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDZ"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"
grep -q '<h1>Stránka od Clauda</h1>' "$PRACE/odpoved" && grep -q 'class="uvod-x"' "$PRACE/odpoved" && ! grep -q 'container' "$PRACE/odpoved" && grep -q '"FAQPage"' "$PRACE/odpoved" && echo "  ok     MCP: publikovaná stránka od Clauda na webu" || { echo "  CHYBA  MCP publikování"; CHYB=$((CHYB+1)); }
mcp uprav_design_system '{"ds":{"barvy":{"primarni":"#0f766e"},"zaobleni":"l"}}' > "$PRACE/odpoved"; grep -q 'citelnost' "$PRACE/odpoved" && echo "  ok     MCP: úprava design systému" || { echo "  CHYBA  MCP uprav_design_system"; CHYB=$((CHYB+1)); }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "design systém z MCP je na webu" 200 / 'ka-barva-primarni: #0f766e'
over "design systém z MCP zachoval ostatní barvy" 200 / 'ka-barva-plocha: #f5f6f8'

echo "== Claude (MCP): stavba webu bez administrace"
mcp stavba_z_html '{"titulek":"Mrizka","html":"<style>.mriz-t { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .kar-t:hover { box-shadow: var(--ka-stin-m) } @media (max-width: 767px) { .mriz-t { grid-template-columns: 1fr } }</style><section><div class=\"mriz-t\"><div class=\"kar-t\"><h3>Jedna</h3></div><div class=\"kar-t\"><h3>Dva</h3></div></div></section>"}' > "$PRACE/odpoved"
IDM2=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'mrizka'")
ocekavej "MCP: @media a :hover z <style> jako stavy třídy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT styl FROM ka_tridy WHERE nazev = 'mriz-t'), (SELECT styl FROM ka_tridy WHERE nazev = 'kar-t'))")" '{"mobil":{"sloupce":"1"}}{"hover":{"stin":"m"}}'
mcp stavba_nacti "{\"id\":$IDM2}" > "$PRACE/odpoved"
grep -q 'mriz-t' "$PRACE/odpoved" && ! grep -q 'zobrazeni' "$PRACE/odpoved" && ! grep -q '\\"odkaz\\":\\"\\"' "$PRACE/odpoved" && echo "  ok     MCP: stavba_nacti bez výchozích hodnot, prvek s třídou bez výchozího stylu" || { echo "  CHYBA  MCP stavba_nacti kompaktní"; head -c 600 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
IDH3=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["stavba"]["deti"][0]["deti"][0]["deti"][0]["deti"][0]["id"];' "$PRACE/odpoved")
mcp stavba_uprav "{\"id\":$IDM2,\"operace\":[{\"op\":\"uprav\",\"id\":\"$IDH3\",\"obsah\":{\"text\":\"Opraveno\"}},{\"op\":\"smaz\",\"id\":\"neni\"}]}" > "$PRACE/odpoved"
grep -q 'chyby_operaci\\":{\\"op\[1\]' "$PRACE/odpoved" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%Opraveno%' FROM ka_stranky WHERE ids = $IDM2")" = 1 ] \
    && echo "  ok     MCP: dílčí úprava prvku podle id (chybná operace nahlášena)" || { echo "  CHYBA  MCP stavba_uprav"; head -c 600 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
NAHLED=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["nahled"];' "$PRACE/odpoved")
curl -s -o "$PRACE/odpoved" -w '%{http_code}' "$NAHLED" > "$PRACE/kod"; grep -q 'Opraveno' "$PRACE/odpoved" && grep -q 'noindex' "$PRACE/odpoved" && [ "$(cat "$PRACE/kod")" = 200 ] \
    && echo "  ok     podepsaný náhled konceptu skryté stránky bez přihlášení" || { echo "  CHYBA  podepsaný náhled ($(cat "$PRACE/kod"))"; CHYB=$((CHYB+1)); }
ocekavej "náhled s cizím nebo pozměněným klíčem nejde" "$(curl -s -o /dev/null -w '%{http_code}' "${NAHLED%?}x")" 404
ocekavej "klíč náhledu jedné stránky neotevře jinou" "$(curl -s -o /dev/null -w '%{http_code}' "$B/z-html?stavba=koncept&nahled_klic=${NAHLED##*nahled_klic=}" | tr -d '\n'; curl -s "$B/z-html?stavba=koncept&nahled_klic=${NAHLED##*nahled_klic=}" | grep -c 'Opraveno')" "2000"
mcp nahled_odkaz '{"cast":"paticka"}' > "$PRACE/odpoved"; grep -q 'cast=paticka&stavba=koncept&nahled_klic=' "$PRACE/odpoved" && echo "  ok     MCP: odkaz na náhled části webu" || { echo "  CHYBA  MCP nahled_odkaz"; CHYB=$((CHYB+1)); }
mcp uloz_tridy '{"css":".stitek-t { padding: var(--ka-mezera-2xs) var(--ka-mezera-s); border-radius: var(--ka-zaobleni) } @media (max-width: 1023px) { .stitek-t { font-size: var(--ka-krok--1) } }"}' > "$PRACE/odpoved"
mcp uloz_tridy '{"css":".stitek-t:hover { background-color: #ffe3dc }"}' > /dev/null
mcp seznam_trid '{"nazev":"stitek-t"}' > "$PRACE/odpoved"; grep -q 'velikost_pisma\\":\\"-1' "$PRACE/odpoved" && grep -q 'hover' "$PRACE/odpoved" && grep -q 'border-radius' "$PRACE/odpoved" && echo "  ok     MCP: sdílená třída z CSS i se stavem tablet" || { echo "  CHYBA  MCP uloz_tridy"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
PNG=$(php -r '$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 255, 79, 46)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
mcp nahraj_soubor "{\"nazev\":\"tym-foto.png\",\"data\":\"$PNG\",\"popis\":\"Tym v dilne\"}" > "$PRACE/odpoved"
MEDIUM=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["adresa"] ?? "";' "$PRACE/odpoved")
[ -n "$MEDIUM" ] && [ -f "$PRACE/web/$MEDIUM" ] && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE obr_poloha = '$MEDIUM'")" = "Tym v dilne" ] \
    && echo "  ok     MCP: obrázek nahraný v base64 je v Médiích" || { echo "  CHYBA  MCP nahraj_soubor (obrázek)"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
mcp nahraj_soubor "{\"nazev\":\"pismo.woff2\",\"data\":\"$(base64 < image/pisma/bricolage-grotesque-latin.woff2 | tr -d '\n')\"}" > "$PRACE/odpoved"
grep -q 'vlastni_pisma' "$PRACE/odpoved" && grep -q 'pismo-[a-f0-9]*\.woff2' "$PRACE/odpoved" && echo "  ok     MCP: písmo WOFF2 do Médií s návodem pro design system" || { echo "  CHYBA  MCP nahraj_soubor (písmo)"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
mcp nahraj_soubor '{"nazev":"skript.php","data":"PD9waHAgZWNobyAxOw=="}' > "$PRACE/odpoved"; grep -q 'isError' "$PRACE/odpoved" && ! ls "$PRACE"/web/media/*/*/skript* > /dev/null 2>&1 && echo "  ok     MCP: PHP ani jiný spustitelný soubor nahrát nejde" || { echo "  CHYBA  MCP nahraj_soubor pustil PHP"; CHYB=$((CHYB+1)); }
mcp uprav_nastaveni '{"nastaveni":{"text_paticky":"Paticka od Clauda","email_webu":"utocnik@example.com","firma_ico":"abc"}}' > "$PRACE/odpoved"
ocekavej "MCP: nastavení webu – povolené se uloží, e-mail a neplatné IČO ne" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'text_paticky'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'email_webu'), '') <> 'utocnik@example.com', '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'firma_ico'), '') <> 'abc')")" "Paticka od Clauda|1|1"
mcp uprav_nastaveni '{"nastaveni":{"logo_webu":"image/kaleta-logo.svg","favicon":"../config.php"}}' > "$PRACE/odpoved"
ocekavej "MCP: logo webu ze systémových souborů, cesta mimo media/ a image/ neprojde" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo_webu'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'favicon'), ''))")" "image/kaleta-logo.svg|"
mcp uloz_presmerovani '{"z":"/stary-web/sluzby","na":"/z-html"}' > /dev/null
ocekavej "MCP: přesměrování staré adresy" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/stary-web/sluzby")" "301 $B/z-html"
mcp smaz_stranku "{\"id\":$IDM2}" > /dev/null
ocekavej "MCP: stránka do koše" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $IDM2")" 1
mcp smaz_stranku "{\"id\":$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'titulni_stranka'")}" | grep -q 'isError' && echo "  ok     MCP: úvodní stránku smazat nejde" || { echo "  CHYBA  MCP smazal úvodní stránku"; CHYB=$((CHYB+1)); }
mcp vytvor_sablonu '{"nazev":"test-kopie"}' > /dev/null
mcp uloz_soubor_sablony '{"sablona":"test-kopie","soubor":"base.php","obsah":"<?php $url = \"system\"; echo $url(\"id\");"}' > "$PRACE/odpoved"
grep -q 'isError' "$PRACE/odpoved" && ! grep -q 'system' "$PRACE/web/layout/test-kopie/base.php" && echo "  ok     MCP: PHP soubor šablony uložit nejde" || { echo "  CHYBA  MCP uložil PHP šablonu"; CHYB=$((CHYB+1)); }
mcp uloz_soubor_sablony '{"sablona":"test-kopie","soubor":"style.css","obsah":"body { color: #111 }"}' > "$PRACE/odpoved"
grep -q 'ulozeno' "$PRACE/odpoved" && echo "  ok     MCP: style.css vlastní šablony uložit jde" || { echo "  CHYBA  MCP neuložil style.css"; head -c 300 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
rm -rf "$PRACE/web/layout/test-kopie"
mcp vytvor_kategorii '{"nazev":"Kategorie XSS","popis":"<p>Úvod</p><script>alert(1)</script><img src=x onerror=alert(2)>"}' > /dev/null
over "MCP: popis kategorie se vyčistí" 200 "/novinky/kategorie/kategorie-xss" "Úvod"
! grep -qE '<script>alert|onerror' "$PRACE/odpoved" && echo "  ok     MCP: v popisu kategorie nezůstal skript" || { echo "  CHYBA  popis kategorie pustil skript"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_kategorie SET popis = '<p>Stary popis</p><script>alert(3)</script>' WHERE seo_link = 'kategorie-xss'"
over "Uložený starý popis kategorie" 200 "/novinky/kategorie/kategorie-xss" "Stary popis"
! grep -q '<script>alert(3)' "$PRACE/odpoved" && echo "  ok     Výpis čistí i dřív uložený popis kategorie" || { echo "  CHYBA  výpis kategorie vypsal skript"; CHYB=$((CHYB+1)); }

echo "== části webu v builderu"
over "části webu" 200 "/admin.php?modul=casti" "Záhlaví"
over "záhlaví se otevře v builderu s koncept podle šablony" 200 "/admin.php?modul=casti&akce=stavitel&typ=hlavicka&jazyk=" 'id="stavitel-data"'
TOKEN=$(csrf)
cast() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=casti&akce=$1&typ=$2&jazyk=" -d "_csrf=$TOKEN" "${@:3}"; }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q 'header class="hlavicka"' "$PRACE/odpoved" && ! grep -q 'ka-nav' "$PRACE/odpoved" && echo "  ok     nepublikované záhlaví kreslí šablona" || { echo "  CHYBA  nepublikované záhlaví je na webu"; CHYB=$((CHYB+1)); }
over "náhled konceptu záhlaví pro editor" 200 "/o-nas?cast=hlavicka&stavba=koncept&editor=1" 'data-ka-typ="navigace"'
curl -s -o "$PRACE/odpoved" "$B/o-nas?cast=hlavicka&stavba=koncept&editor=1"; ! grep -q 'data-ka-typ' "$PRACE/odpoved" && echo "  ok     náhled části nevidí návštěvník" || { echo "  CHYBA  koncept části vidí nepřihlášený"; CHYB=$((CHYB+1)); }
ocekavej "publikování záhlaví" "$(cast stavba_publikuj hlavicka)" 200
curl -s -o "$PRACE/odpoved" "$B/o-nas"
grep -q 'class="ka-nav"' "$PRACE/odpoved" && ! grep -q 'header class="hlavicka"' "$PRACE/odpoved" && grep -q 'href="/o-nas" aria-current="page"' "$PRACE/odpoved" && echo "  ok     záhlaví z builderu na webu s aktivní položkou menu" || { echo "  CHYBA  záhlaví z builderu"; CHYB=$((CHYB+1)); }
[ "$(grep -o '<style>' "$PRACE/odpoved" | wc -l | tr -d ' ')" = 1 ] && [ "$(grep -o '@layer stavitel {' "$PRACE/odpoved" | wc -l | tr -d ' ')" = 1 ] && echo "  ok     stránka a části webu mají jedno CSS" || { echo "  CHYBA  CSS částí webu se opakuje"; CHYB=$((CHYB+1)); }
OBALKA='{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}'
over "obálka novinky v builderu" 200 "/admin.php?modul=casti&akce=stavitel&typ=novinka&jazyk=" 'id="stavitel-data"'
cast stavba_uloz novinka --data-urlencode "stavba=$OBALKA" > /dev/null; cast stavba_publikuj novinka > /dev/null
curl -s -o "$PRACE/odpoved" "$B/novinky/vitejte-v-kalete"; grep -q 'Pod článkem' "$PRACE/odpoved" && grep -q '<main id="obsah" class="stavba">' "$PRACE/odpoved" && grep -q 'class="obal obsah"' "$PRACE/odpoved" && grep -q 'Vítejte' "$PRACE/odpoved" && echo "  ok     obálka kolem novinky" || { echo "  CHYBA  obálka novinky"; CHYB=$((CHYB+1)); }
cast stavba_uloz hlavicka --data-urlencode 'stavba={"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}' > /dev/null; cast stavba_publikuj hlavicka > /dev/null
ocekavej "předchozí záhlaví je ve verzích" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'hlavicka:'")" 1
cast sablona hlavicka > /dev/null
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q 'header class="hlavicka"' "$PRACE/odpoved" && echo "  ok     vrácení záhlaví na šablonu" || { echo "  CHYBA  vrácení na šablonu"; CHYB=$((CHYB+1)); }
mcp stavba_uloz '{"cast":"paticka","stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]},"publikovat":true}' > "$PRACE/odpoved"
grep -q 'publikováno' "$PRACE/odpoved" && echo "  ok     MCP: patička ze stavby" || { echo "  CHYBA  MCP patička"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q "<p class=\"ka-udaj\">&copy; $(date +%Y) Testovací firma</p>" "$PRACE/odpoved" && ! grep -q 'footer class="paticka"' "$PRACE/odpoved" && echo "  ok     patička z MCP na webu" || { echo "  CHYBA  patička z MCP na webu"; CHYB=$((CHYB+1)); }
ocekavej "autor novinek k částem webu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=casti")" 403

echo "== formuláře a poptávky"
curl -s -o "$PRACE/formular.html" "$B/kontakt"
grep -q 'class="ka-formular"' "$PRACE/formular.html" && grep -q 'name="as_podpis"' "$PRACE/formular.html" && echo "  ok     kontakt má poptávkový formulář" || { echo "  CHYBA  formulář na kontaktu"; CHYB=$((CHYB+1)); }
hodnota() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$PRACE/formular.html" | head -1 | sed 's/.*value="//;s/"$//'; }
FZ=$(hodnota zdroj); FP=$(hodnota prvek); FC=$(hodnota as_cas); FS=$(hodnota as_podpis)
odesli() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FZ" -d "prvek=$FP" -d zpet=/kontakt -d "as_cas=$FC" -d "as_podpis=$FS" "$@"; }
sleep 4
kam=$(odesli -d p0=Jana --data-urlencode p1=jana@example.cz -d p2= --data-urlencode "p3=Chci kuchyň na míru." -d p4=1)
case "$kam" in *"/kontakt?formular=$FP&vysledek=ok#"*"$FP") echo "  ok     odeslání formuláře";; *) echo "  CHYBA  odeslání formuláře: $kam"; CHYB=$((CHYB+1));; esac
ocekavej "poptávka uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(stav)) FROM ka_poptavky")" "1/jana@example.cz/0"
case "$(odesli -d p0=Jana -d p1=neni-email -d p3=x -d p4=1)" in *vysledek=pole\&pole=1*) echo "  ok     neplatný e-mail odmítnut s číslem pole";; *) echo "  CHYBA  validace e-mailu"; CHYB=$((CHYB+1));; esac
curl -s -o "$PRACE/odpoved" "$B/kontakt?formular=$FP&vysledek=pole&pole=1"
grep -q 'aria-invalid="true" aria-describedby="f-'"$FP"'-1-chyba"' "$PRACE/odpoved" && grep -q 'data-obnovit' "$PRACE/odpoved" && echo "  ok     chybné pole je označené a vyplněné hodnoty se obnoví" || { echo "  CHYBA  označení chybného pole"; CHYB=$((CHYB+1)); }
case "$(odesli -d p0=Jana --data-urlencode p1=jana@example.cz -d p3=x)" in *vysledek=pole*) echo "  ok     chybějící souhlas odmítnut";; *) echo "  CHYBA  povinný souhlas"; CHYB=$((CHYB+1));; esac
odesli -d p0=Robot --data-urlencode p1=r@example.cz -d p3=spam -d p4=1 -d web_adresa=http://spam.example > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FZ" -d "prvek=$FP" -d zpet=/kontakt -d "as_cas=$FC" -d as_podpis=podvrh -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *vysledek=overeni*) echo "  ok     podvržený podpis odmítnut";; *) echo "  CHYBA  podpis formuláře"; CHYB=$((CHYB+1));; esac
case "$(odesli -d zdroj=stranka:999 -d p0=A)" in *formular=*) echo "  CHYBA  neexistující formulář přijat"; CHYB=$((CHYB+1));; *) echo "  ok     neexistující formulář nic neuloží";; esac
ocekavej "robot ani chyby poptávku nepřidaly" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_poptavky")" 1
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_poptavky")
over "poptávky v administraci" 200 "/admin.php?modul=poptavky" "jana@example.cz"
over "detail poptávky" 200 "/admin.php?modul=poptavky&akce=detail&id=$IDP" "Chci kuchyň na míru."
ocekavej "otevřená poptávka je přečtená" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_poptavky")" 1
curl -s -b "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=poptavky&akce=csv"; grep -q 'Chci kuchyň na míru.' "$PRACE/odpoved" && echo "  ok     export poptávek do CSV" || { echo "  CHYBA  CSV poptávek"; CHYB=$((CHYB+1)); }
over "poděkování po odeslání (na místě formuláře)" 200 "/kontakt?formular=$FP&vysledek=ok" 'class="ka-formular-hotovo"'

echo "== kolekce"
over "kolekce" 200 "/admin.php?modul=kolekce" "Kolekce"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=kolekce&akce=uloz" -d "_csrf=$TOKEN" -d idk=0 --data-urlencode "nazev=Tým" -d detail=1 \
  --data-urlencode "pole[0][popisek]=Funkce" -d "pole[0][typ]=text" --data-urlencode "pole[1][popisek]=Foto" -d "pole[1][typ]=obrazek" --data-urlencode "pole[2][popisek]=Medailonek" -d "pole[2][typ]=html"
IDK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
ocekavej "kolekce založena s poli" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM ka_kolekce WHERE idk = $IDK")" 1
polozka() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=kolekce&akce=uloz_polozku" -d "_csrf=$TOKEN" -d "idk=$IDK" -d idp=0 "$@"; }
polozka --data-urlencode "nazev=Jana Nováková" --data-urlencode "data[funkce]=Jednatelka" --data-urlencode "data[medailonek]=<p>Dvacet let <b>v oboru</b>.</p><script>x</script>" -d poradi=1 -d zobrazit=1
polozka --data-urlencode "nazev=Skrytý Člen" --data-urlencode "data[funkce]=Tajný" -d poradi=2
over "položky kolekce" 200 "/admin.php?modul=kolekce&akce=polozky&id=$IDK" "Jana Nováková"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"smy1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\"},\"deti\":[{\"id\":\"kar1\",\"typ\":\"kontejner\",\"styl\":{\"zaklad\":{\"pozadi\":\"plocha\"}},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>{{funkce}}</p>{{medailonek}}\"}},{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Profil\",\"odkaz\":\"{{url}}\"}}]}]}]}]}}" > "$PRACE/odpoved"
grep -q 'publikováno' "$PRACE/odpoved" || { echo "  CHYBA  MCP stránka s výpisem kolekce"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"
grep -q '<h3>Jana Nováková</h3>' "$PRACE/odpoved" && grep -q '<p>Jednatelka</p>' "$PRACE/odpoved" && grep -q '^<p>Dvacet let <b>v oboru</b>.</p>' "$PRACE/odpoved" && grep -q 'href="/tym/jana-novakova"' "$PRACE/odpoved" && ! grep -q 'Skrytý' "$PRACE/odpoved" \
  && echo "  ok     výpis kolekce na stránce (jen zveřejněné položky, hodnoty dosazené)" || { echo "  CHYBA  výpis kolekce"; CHYB=$((CHYB+1)); }
grep -q 'class="s-kar1"' "$PRACE/odpoved" && ! grep -q 'id="s-kar1"' "$PRACE/odpoved" && grep -q '\.s-kar1 { background-color' "$PRACE/odpoved" && ! grep -q '<script>x' "$PRACE/odpoved" \
  && echo "  ok     opakované prvky mají styl přes třídu, ne duplicitní id" || { echo "  CHYBA  styl ve výpisu kolekce"; CHYB=$((CHYB+1)); }
over "detail položky kolekce" 200 /tym/jana-novakova "Jednatelka"
over "detail má nadpis položky" 200 /tym/jana-novakova "<h1>Jana Nováková</h1>"
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/tym/skryty-clen"); ocekavej "skrytá položka nemá detail" "$kod" 404
over "mapa webu obsahuje detail položky" 200 /sitemap.xml "/tym/jana-novakova"
over "šablona detailu v builderu" 200 "/admin.php?modul=kolekce&akce=stavitel&id=$IDK" 'id="stavitel-data"'
mcp seznam_kolekci '{}' > "$PRACE/odpoved"; grep -q 'kolekce\\":\\"tym' "$PRACE/odpoved" && grep -q 'medailonek' "$PRACE/odpoved" && echo "  ok     MCP: seznam kolekcí s poli" || { echo "  CHYBA  MCP seznam_kolekci"; CHYB=$((CHYB+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Petr Svoboda","data":{"funkce":"Mistr truhlář"},"zobrazit":true}' > /dev/null
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "MCP: nová položka je ve výpisu" 200 /z-html "Mistr truhlář"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyp1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\",\"pocet\":1,\"razeni\":\"nazev\",\"filtr_pole\":\"funkce\",\"filtry\":true,\"strankovani\":true},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}}]}]}]}}" > /dev/null
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"
[ "$(grep -o '<h3>[^<]*</h3>' "$PRACE/odpoved" | tr -d '\n')" = "<h3>Jana Nováková</h3>" ] && grep -q 'href="/z-html?s-vyp1=2"' "$PRACE/odpoved" && grep -q 'href="/z-html" aria-current="true">Vše' "$PRACE/odpoved" && grep -q 'f-vyp1=Mistr' "$PRACE/odpoved" \
  && echo "  ok     výpis kolekce: řazení, stránkování a tlačítka filtru" || { echo "  CHYBA  stránkování výpisu kolekce"; CHYB=$((CHYB+1)); }
over "výpis kolekce: druhá strana" 200 "/z-html?s-vyp1=2" "<h3>Petr Svoboda</h3>"
curl -s -o "$PRACE/odpoved" "$B/z-html?f-vyp1=Mistr+truhl%C3%A1%C5%99"; grep -q '<h3>Petr Svoboda</h3>' "$PRACE/odpoved" && ! grep -q '<h3>Jana' "$PRACE/odpoved" && grep -q 'aria-current="true">Mistr truhlář' "$PRACE/odpoved" \
  && echo "  ok     výpis kolekce: filtr návštěvníka" || { echo "  CHYBA  filtr výpisu kolekce"; CHYB=$((CHYB+1)); }

echo "== komponenty"
over "komponenty" 200 "/admin.php?modul=komponenty" "Komponenty"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=komponenty&akce=uloz" -d "_csrf=$TOKEN" -d idm=0 --data-urlencode "nazev=Karta služby" \
  --data-urlencode "vlastnosti[0][popisek]=Nadpis" -d "vlastnosti[0][typ]=text" --data-urlencode "vlastnosti[0][vychozi]=Výchozí nadpis" --data-urlencode "vlastnosti[1][popisek]=Odkaz" -d "vlastnosti[1][typ]=odkaz" -d "vlastnosti[1][vychozi]=/kontakt"
IDM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty ORDER BY idm DESC LIMIT 1")
komp() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=komponenty&akce=$1&id=$IDM" -d "_csrf=$TOKEN" "${@:2}"; }
over "komponenta v builderu" 200 "/admin.php?modul=komponenty&akce=stavitel&id=$IDM" 'id="stavitel-data"'
komp stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kna1","typ":"nadpis","znacka":"h3","obsah":{"text":"{{nadpis}}"},"styl":{"zaklad":{"barva":"primarni"}}},{"typ":"tlacitko","obsah":{"text":"Více","odkaz":"{{odkaz}}"}},{"typ":"komponenta","obsah":{"komponenta":"'"$IDM"'"}}]}]}' > /dev/null
ocekavej "publikování komponenty" "$(komp stavba_publikuj)" 200
over "náhled komponenty pro editor" 200 "/_komponenta/$IDM?stavba=koncept&editor=1" "Výchozí nadpis"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\",\"hodnoty\":{\"nadpis\":\"První <b>karta</b>\",\"odkaz\":\"javascript:alert(1)\"}}},{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\"}}]}}" > /dev/null
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" -w '' "$B/z-html"
grep -q '<h3 class="s-kna1">První karta</h3>' "$PRACE/odpoved" && grep -q '<h3 class="s-kna1">Výchozí nadpis</h3>' "$PRACE/odpoved" && [ "$(grep -o 'href="/kontakt"' "$PRACE/odpoved" | wc -l | tr -d ' ')" -ge 1 ] && ! grep -q 'javascript:' "$PRACE/odpoved" \
  && echo "  ok     komponenta na stránce: vlastní i výchozí hodnoty, bez značek, nebezpečný odkaz pryč" || { echo "  CHYBA  komponenta na stránce"; CHYB=$((CHYB+1)); }
! grep -q 'id="s-kna1"' "$PRACE/odpoved" && ! grep -q 'data-ka-id' "$PRACE/odpoved" && [ "$(grep -o '\.s-kna1 {' "$PRACE/odpoved" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     komponenta dvakrát na stránce: styl jednou, bez duplicitního id" || { echo "  CHYBA  styl komponenty"; CHYB=$((CHYB+1)); }
over "komponenty ukazují počet použití" 200 "/admin.php?modul=komponenty" "1×"
# formulář uvnitř komponenty: odeslání ho musí najít (dřív se hledal jen ve stavbě stránky)
komp stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kfo1","typ":"formular","obsah":{"nazev":"Poptávka z komponenty"}}]}]}' > /dev/null; komp stavba_publikuj > /dev/null
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/formular.html" "$B/z-html"
kam=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$(hodnota zdroj)" -d prvek=kfo1 -d zpet=/z-html -d "as_cas=$(hodnota as_cas)" -d "as_podpis=$(hodnota as_podpis)")
case "$kam" in *"formular=kfo1"*) echo "  ok     formulář v komponentě se odešle";; *) echo "  CHYBA  formulář v komponentě: $kam"; CHYB=$((CHYB+1));; esac
kod=$(curl -s -b "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=komponenty&akce=z_prvku" -d "_csrf=$TOKEN" --data-urlencode "nazev=Výzva" --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Zavolejte nám"}}]}')
[ "$kod" = 200 ] && grep -q '"ok":true' "$PRACE/odpoved" && echo "  ok     uložení prvku jako komponenty" || { echo "  CHYBA  z_prvku: $kod"; CHYB=$((CHYB+1)); }

over "náhled hotové sekce pro panel builderu" 200 /_sekce/cenik "Vyberte si balíček"
ocekavej "náhled sekce jen pro přihlášené" "$(curl -s -o /dev/null -w '%{http_code}' "$B/_sekce/cenik")" 404

echo "== varianty záhlaví"
over "formulář varianty" 200 "/admin.php?modul=casti&akce=varianta&typ=hlavicka&jazyk=" 'Název varianty'
TOKEN=$(csrf)
kam=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?modul=casti&akce=uloz_variantu&typ=hlavicka&jazyk=" -d "_csrf=$TOKEN" --data-urlencode "nazev=Landing page" -d "stranky[]=$IDZ")
case "$kam" in *"varianta=landing-page"*) echo "  ok     varianta založena a otevřena v builderu";; *) echo "  CHYBA  založení varianty: $kam"; CHYB=$((CHYB+1));; esac
var() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=casti&akce=$1&typ=hlavicka&jazyk=&varianta=landing-page" -d "_csrf=$TOKEN" "${@:2}"; }
var stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[]}' > /dev/null
ocekavej "publikování varianty" "$(var stavba_publikuj)" 200
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"; ! grep -q 'header class="hlavicka"' "$PRACE/odpoved" && ! grep -q 'ka-nav' "$PRACE/odpoved" && echo "  ok     stránka s prázdnou variantou je bez záhlaví" || { echo "  CHYBA  varianta záhlaví na stránce"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/kontakt"; grep -q 'header class="hlavicka"' "$PRACE/odpoved" && echo "  ok     ostatní stránky mají výchozí záhlaví" || { echo "  CHYBA  varianta se projevila i jinde"; CHYB=$((CHYB+1)); }
over "varianta v seznamu částí" 200 "/admin.php?modul=casti" "Landing page"

# úprava přímo na webu: odkaz a formulář jen pro přihlášené s právem
over "úprava na místě – odkaz" 200 /novinky/vitejte-v-kalete "ka-upravit-zde"
over "úprava na místě – formulář" 200 "/novinky/vitejte-v-kalete?upravit=text" "ka-upravit-text"
over "úprava stránky na místě" 200 "/o-nas?upravit=text" "ka-upravit-text"
curl -s -o "$PRACE/odpoved" "$B/novinky/vitejte-v-kalete?upravit=text"; grep -q "ka-upravit" "$PRACE/odpoved" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; CHYB=$((CHYB+1)); } || echo "  ok     úprava na místě bez přihlášení není"

echo "== import z WordPressu a export"
over "import a export" 200 "/admin.php?modul=prenos" "WordPress"
TOKEN=$(csrf)
wp_davka() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -X POST "$B/admin.php?modul=prenos&akce=prubeh&soubor=wordpress-ukazka.xml" -d "_csrf=$TOKEN"; }
wp_import() { # náhled (čtení souboru) → volby → import; ukázkový soubor se vejde do jedné dávky
  wp_davka
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=spust" -d "_csrf=$TOKEN" -d soubor=wordpress-ukazka.xml -d koncepty=1 -d stranky=1 -d stavitel=1 -d presmerovani=1 -d rubrika=0
  wp_davka
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=nahraj" -F "_csrf=$TOKEN" -F "soubor=@$KOREN/tools/fixtures/wordpress-ukazka.xml"
wp_davka
over "import z WordPressu – náhled upozorní na nepřevoditelný typ" 200 "/admin.php?modul=prenos&akce=nahled&soubor=wordpress-ukazka.xml" "nav_menu_item"
wp_import
grep -q "Import obsahu je hotový" "$PRACE/odpoved" && echo "  ok     import z WordPressu doběhl" || { echo "  CHYBA  import z WordPressu nedoběhl"; CHYB=$((CHYB+1)); }
over "importovaná novinka" 200 /novinky/lavka-pres-bystrinu "Lávka přes Bystřinu"
over "importovaná novinka – galerie a video" 200 /novinky/lavka-pres-bystrinu 'class="galerie"'
over "importovaná stránka" 200 /o-zpravodaji "Kontakt"
over "importovaná stránka je rovnou v builderu" 200 /o-zpravodaji '<main id="obsah" class="stavba">'
over "importovaná stránka má nadpis z WordPressu" 200 /o-zpravodaji '<h1>O zpravodaji</h1>'
curl -s -o "$PRACE/odpoved" "$B/o-zpravodaji"; grep -q 'wp-block' "$PRACE/odpoved" && { echo "  CHYBA  třídy WordPressu ve stavbě"; CHYB=$((CHYB+1)); } || echo "  ok     třídy WordPressu bez stylu vynechány"
curl -s -o "$PRACE/odpoved" "$B/novinky/lavka-pres-bystrinu"; grep -qE "podvrh|onclick|kontaktni-formular|posta\.example" "$PRACE/odpoved" && { echo "  CHYBA  importovaná novinka obsahuje skript, zkratku doplňku nebo e-mail komentujícího"; CHYB=$((CHYB+1)); } || echo "  ok     importovaný obsah je vyčištěný"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2026/05/lavka-pres-bystrinu/"); ocekavej "stará adresa WordPressu přesměruje na novinku" "$kod" "301 $B/novinky/lavka-pres-bystrinu"
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/?p=102"); ocekavej "stará adresa /?p=102 přesměruje" "$kod" 301
# druhý import téhož souboru nesmí nic zdvojit
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=vyber" -d "_csrf=$TOKEN" -d soubor=wordpress-ukazka.xml
wp_import
POCTY=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'lavka-pres-bystrinu%' OR seo_link LIKE 'slavnosti-syra%' OR seo_link LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'o-zpravodaji%'))")
ocekavej "opakovaný import nic nezdvojil (novinky/stránky)" "$POCTY" "4/1"
over "složka importu není přístupná z webu" 403 /storage/import/wordpress-ukazka.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=export" -d "_csrf=$TOKEN"
over "export webu je v seznamu" 200 "/admin.php?modul=prenos" "akce=stahni"
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$PRACE/odpoved" | head -1)
curl -s -b "$JAR" -o "$PRACE/export" "$B/admin.php?modul=prenos&akce=stahni&soubor=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$PRACE/export" obsah.json > "$PRACE/obsah.json" 2>/dev/null || true; else cp "$PRACE/export" "$PRACE/obsah.json"; fi
grep -q '"format":"kaleta-export"' "$PRACE/obsah.json" && grep -q '"novinky"' "$PRACE/obsah.json" && ! grep -qE '"password"|smtp_heslo|tajny_klic|ai_klic' "$PRACE/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export"; CHYB=$((CHYB+1)); }
grep -q '"kolekce_polozky":\[' "$PRACE/obsah.json" && grep -q 'Jana Nováková' "$PRACE/obsah.json" && grep -q '"tridy":\[' "$PRACE/obsah.json" && grep -q '"casti":\[' "$PRACE/obsah.json" && ! grep -q 'Chci kuchyň' "$PRACE/obsah.json" \
  && echo "  ok     export obsahuje builder a kolekce, poptávky ne" || { echo "  CHYBA  export builderu a kolekcí"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/admin.php?modul=prenos&akce=stahni&soubor=$EXPORT"; grep -q "Heslo" "$PRACE/odpoved" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; CHYB=$((CHYB+1)); }

echo "== koš novinek"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'")
over "výpis novinek" 200 "/admin.php?modul=novinky" "Smazat označené"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=novinky&akce=smaz" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
over "novinka v koši není na webu" 404 /novinky/vitejte-v-kalete
over "novinka v koši není ani v náhledu" 404 "/novinky/vitejte-v-kalete?nahled=1"
over "záložka Koš" 200 "/admin.php?modul=novinky&stav=kos" "Vítejte"
over "novinka v koši nejde upravit" 404 "/admin.php?modul=novinky&akce=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=novinky&akce=obnov" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
ocekavej "obnovená novinka se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM ka_novinky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_novinky SET visible = 1, smazano = NOW() - INTERVAL 31 DAY WHERE idc = $IDC"
over "vstup do administrace vysype starý koš" 200 /admin.php "Přehled"
ocekavej "novinka starší 30 dní v koši je smazaná natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE idc = $IDC")" "0"

echo "== přesměrování po změně adresy kategorie a stránky"
over "formulář kategorie" 200 "/admin.php?modul=kategorie" "Kategorie"
TOKEN=$(csrf)
IDT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_kategorie WHERE seo_link = 'aktuality'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=kategorie&akce=uloz" -d "_csrf=$TOKEN" -d "idt=$IDT" -d nazev=Aktuality -d seo_link=aktuality-firmy -d hodnost=100
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/novinky/kategorie/aktuality"); ocekavej "stará adresa kategorie přesměruje na novou" "$kod" "301 $B/novinky/kategorie/aktuality-firmy"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakt'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=uloz" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/kontakt"); ocekavej "stará adresa stránky přesměruje na novou" "$kod" "301 $B/kontakty"

echo "== stránky: SEO, koš, duplikace"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakty'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=uloz" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>" \
  --data-urlencode "seo_titulek=Kontakt na truhlárnu" -d obrazek=media/2026/01/sdileni.jpg -d noindex=1
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/kontakty"
grep -q '<title>Kontakt na truhlárnu' "$PRACE/odpoved" && grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$PRACE/odpoved" && grep -q 'noindex, follow' "$PRACE/odpoved" \
  && echo "  ok     stránka: vlastní titulek, úplná adresa obrázku pro sdílení, noindex" || { echo "  CHYBA  SEO stránky"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=duplikuj" -d "_csrf=$TOKEN" -d "ids=$IDS"
ocekavej "duplikát stránky je skrytý a má volnou adresu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', seo_link) FROM ka_stranky ORDER BY ids DESC LIMIT 1")" "0/kontakty-kopie"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=smaz" -d "_csrf=$TOKEN" -d "ids=$IDS"
over "stránka v koši není na webu" 404 /kontakty
over "záložka Koš u stránek" 200 "/admin.php?modul=stranky&stav=kos" "Kontakt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=obnov" -d "_csrf=$TOKEN" -d "ids=$IDS"
ocekavej "obnovená stránka je skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', smazano IS NULL) FROM ka_stranky WHERE ids = $IDS")" "0/1"
IDU=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$IDU' WHERE promenna='titulni_stranka'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=smaz" -d "_csrf=$TOKEN" -d "ids=$IDU"
ocekavej "úvodní stránku nejde smazat" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $IDU")" "1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='titulni_stranka'"

echo "== podstránky, plán, historie, šablony, export"
ulozs() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?modul=stranky&akce=uloz" -d "_csrf=$TOKEN" "$@"; }
ulozs -d ids=0 --data-urlencode "titulek=Služby firmy" -d seo_link=sluzby-firmy -d zobrazit=1 -d v_menu=0 -d "text=<p>S</p>" > /dev/null
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby-firmy'")
ulozs -d ids=0 --data-urlencode "titulek=Kuchyně" -d "nadrazena=$IDR" -d zobrazit=1 -d v_menu=0 -d "text=<p>Kuchyně na míru</p>" > /dev/null
ocekavej "podstránka má adresu pod nadřazenou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "sluzby-firmy/kuchyne"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "podstránka na webu" 200 /sluzby-firmy/kuchyne "Kuchyně na míru"
ulozs -d "ids=$IDR" --data-urlencode "titulek=Služby firmy" -d seo_link=nase-sluzby -d zobrazit=1 -d v_menu=0 -d "text=<p>S2</p>" > /dev/null
ocekavej "změna adresy nadřazené posune podstránku" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "nase-sluzby/kuchyne"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/sluzby-firmy/kuchyne"); ocekavej "stará adresa podstránky přesměruje" "$kod" "301 $B/nase-sluzby/kuchyne"
ocekavej "změna textu uloží předchozí verzi" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky_revize WHERE ids = $IDR ORDER BY idr DESC LIMIT 1")" "<p>S</p>"
ulozs -d ids=0 --data-urlencode "titulek=Akce" -d v_menu=0 -d "text=<p>A</p>" -d "zverejnit_od=$(date -v+1d '+%Y-%m-%dT%H:%M' 2>/dev/null || date -d '+1 day' '+%Y-%m-%dT%H:%M')" > /dev/null
ocekavej "naplánovaná stránka čeká skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE seo_link = 'akce'")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zverejnit_od = NOW() - INTERVAL 1 MINUTE WHERE seo_link = 'akce'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'oznameni_kontrola'"
curl -s -o /dev/null "$B/novinky?x=$RANDOM"; sleep 1
ocekavej "naplánovaná stránka se v čase sama zveřejní" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" "1"
kam=$(ulozs -d ids=0 --data-urlencode "titulek=Nabídka" -d sablona=landing -d zobrazit=0 -d v_menu=0 -d text=)
case "$kam" in *akce=stavitel*) echo "  ok     nová stránka ze šablony jde rovnou do builderu";; *) echo "  CHYBA  šablona stránky: $kam"; CHYB=$((CHYB+1));; esac
ocekavej "šablona složí koncept ze sekcí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%\"typ\":\"sekce\"%' FROM ka_stranky WHERE seo_link = 'nabidka'")" "1"
IDN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nabidka'")
curl -s -b "$JAR" -o "$PRACE/stranka.json" "$B/admin.php?modul=stranky&akce=export&id=$IDN"
grep -q '"format": "kaleta-stranka"' "$PRACE/stranka.json" && echo "  ok     export stránky do JSON" || { echo "  CHYBA  export stránky"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=import" -F "_csrf=$TOKEN" -F "soubor=@$PRACE/stranka.json;type=application/json"
ocekavej "import stránky vytvoří skrytou kopii se stavbou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba_koncept IS NOT NULL) FROM ka_stranky WHERE seo_link = 'nabidka-2'")" "0/1"

echo "== builder: vlastní CSS, atributy, animace, moje sekce, přejmenování třídy"
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nase-sluzby'")
sv() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=stranky&akce=$1&id=$IDV" -d "_csrf=$TOKEN" "${@:2}"; }
sv stavba_uloz --data-urlencode 'stavba={"v":1,"deti":[{"id":"sv1","typ":"sekce","tridy":["karta"],"css":"backdrop-filter: blur(4px); background: url(x)","atributy":{"data-sledovat":"cta","onclick":"x"},"styl":{"zaklad":{"animace":"ka-vyjet","prechod":"linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))","okraj_vlevo":"auto"},"aktivni":{"pruhlednost":"0.8"}},"deti":[{"typ":"nadpis","obsah":{"text":"Test"}}]}]}' > /dev/null
grep -q 'Nepovolená deklarace' "$PRACE/odpoved" && grep -q 'Atribut může být jen' "$PRACE/odpoved" && echo "  ok     vlastní CSS a atributy prvku se čistí" || { echo "  CHYBA  čištění CSS a atributů"; CHYB=$((CHYB+1)); }
sv stavba_publikuj > /dev/null
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/nase-sluzby"
grep -q 'data-sledovat="cta"' "$PRACE/odpoved" && ! grep -q 'onclick="x"' "$PRACE/odpoved" && grep -q 'backdrop-filter: blur(4px)' "$PRACE/odpoved" && grep -q 'animation-timeline: view()' "$PRACE/odpoved" \
  && grep -q '@keyframes ka-vyjet' "$PRACE/odpoved" && grep -q ':active {' "$PRACE/odpoved" && grep -q 'margin-inline-start: auto' "$PRACE/odpoved" \
  && echo "  ok     vlastní CSS, atributy, animace, stisknutí a okraj na webu" || { echo "  CHYBA  nové vlastnosti stylu na webu"; CHYB=$((CHYB+1)); }
ocekavej "uložení do mých sekcí" "$(sv stavba_uloz_sekci --data-urlencode 'nazev=Moje karta' --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Z knihovny"}}]}')" 200
grep -q '"nazev":"Moje karta"' "$PRACE/odpoved" && echo "  ok     moje sekce v seznamu" || { echo "  CHYBA  moje sekce"; CHYB=$((CHYB+1)); }
sv stavba_trida -d nazev=karta -d pouziti=1 > /dev/null; grep -q 'Služby firmy' "$PRACE/odpoved" && echo "  ok     přehled použití třídy" || { echo "  CHYBA  použití třídy"; head -c 300 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
ocekavej "přejmenování třídy" "$(sv stavba_trida -d nazev=karta -d novy_nazev=karta-sluzby)" 200
ocekavej "přejmenovaná třída ve stavbách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba LIKE '%\"karta-sluzby\"%' AND stavba NOT LIKE '%\"karta\"%' FROM ka_stranky WHERE ids = $IDV")" "1"

echo "== média, přesměrování, poptávky, uživatelé, písma"
php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 200, 80, 40)); imagejpeg($i, "'"$PRACE"'/foto.jpg");'
printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10" onload="alert(1)"><script>alert(2)</script><rect width="20" height="10" fill="red"/></svg>' > "$PRACE/logo.svg"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=intergal&akce=nahraj" -F "_csrf=$TOKEN" -F "soubory[]=@$PRACE/foto.jpg;type=image/jpeg" -F "soubory[]=@$PRACE/logo.svg;type=image/svg+xml"
SVG=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE obr_poloha LIKE '%.svg' ORDER BY ido DESC LIMIT 1")
[ -n "$SVG" ] && ! grep -q 'onload\|<script' "$PRACE/web/$SVG" && grep -q '<rect' "$PRACE/web/$SVG" && echo "  ok     SVG nahrané a vyčištěné" || { echo "  CHYBA  SVG v Médiích"; CHYB=$((CHYB+1)); }
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
FOTO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE ido = $IDO")
php -r '$i = imagecreatetruecolor(800, 800); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); imagejpeg($i, "'"$PRACE"'/nova.jpg");'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=intergal&akce=nahradit" -F "_csrf=$TOKEN" -F "ido=$IDO" -F "soubor=@$PRACE/nova.jpg;type=image/jpeg"
ocekavej "náhrada souboru zachová adresu a změní rozměry" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(obr_poloha, ' ', obr_width, 'x', obr_height) FROM ka_media WHERE ido = $IDO")" "$FOTO 800x800"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=intergal&akce=uloz" -d "_csrf=$TOKEN" -d "ido=$IDO" -d nazev=Foto -d ohnisko_x=20 -d ohnisko_y=80
ocekavej "ohnisko ořezu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ohnisko FROM ka_media WHERE ido = $IDO")" "20% 80%"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=presmerovani&akce=uloz" -d "_csrf=$TOKEN" -d z_adresy=/akce-leto -d na_adresu=/kontakty -d typ=302
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/akce-leto"); ocekavej "dočasné přesměrování 302" "$kod" "302"
over "hledání v přesměrováních" 200 "/admin.php?modul=presmerovani&hledat=akce-leto" "akce-leto"
over "protokol s filtrem" 200 "/admin.php?modul=protokol&kde=stranky" "Protokol"
IDU2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?modul=users&akce=uloz" -d "_csrf=$TOKEN" -d idu=0 -d user=pozvany --data-urlencode email=pozvany@example.cz -d admin=2 -d pozvat=1)
ocekavej "pozvaný uživatel má odkaz na heslo s delší platností" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obnova_otisk <> '' AND obnova_cas > NOW() FROM ka_uzivatele WHERE user = 'pozvany'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=role&akce=uloz" -d "_csrf=$TOKEN" -d idr=0 -d nazev=Obchodník -d uroven=0 -d 'moduly[]=poptavky' -d 'moduly[]=kolekce'
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT MAX(idr) FROM ka_role")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=users&akce=uloz" -d "_csrf=$TOKEN" -d idu=0 -d user=obchodnik --data-urlencode "password=$HESLO" -d "admin=r$IDR"
ocekavej "vlastní role dá uživateli své sekce" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' AND u.role = $IDR")" "kolekce,poptavky"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=role&akce=uloz" -d "_csrf=$TOKEN" -d "idr=$IDR" -d nazev=Obchodník -d uroven=1 -d 'moduly[]=poptavky'
ocekavej "změna role se přenese na členy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.ident_modulu)) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' GROUP BY u.idu")" "1:poptavky"
over "přehled rolí" 200 "/admin.php?modul=role" "Obchodník"
over "uživatelé ukazují vlastní roli" 200 "/admin.php?modul=users" "Obchodník"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('vynutit_2fa', 'spravci') ON DUPLICATE KEY UPDATE hodnota = 'spravci'"
kod=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?modul=stranky"); case "$kod" in "302 "*akce=ucet*) echo "  ok     povinné dvoufázové přihlášení pustí jen do Můj účet";; *) echo "  CHYBA  vynucení 2FA: $kod"; CHYB=$((CHYB+1));; esac
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'vynutit_2fa'"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = JSON_SET(IF(hodnota = '' OR hodnota IS NULL, '{}', hodnota), '$.vlastni_pisma', JSON_ARRAY(JSON_OBJECT('nazev', 'Znacka Sans', 'soubor', 'media/2026/01/znacka.woff2', 'tucny', '')), '$.pismo_titulky', 'vlastni-1') WHERE promenna = 'design_system'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/kontakty"
grep -q '@font-face { font-family: "Znacka Sans"; src: url("/media/2026/01/znacka.woff2")' "$PRACE/odpoved" && grep -q -- '--ka-pismo-titulky: "Znacka Sans"' "$PRACE/odpoved" && echo "  ok     vlastní písmo z Médií" || { echo "  CHYBA  vlastní písmo"; CHYB=$((CHYB+1)); }
ocekavej "statistika po stránkách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_stat_stranky")" "1"
curl -s -o "$PRACE/odpoved" "$B/kontakty"; grep -q 'image/web.js' "$PRACE/odpoved" && echo "  CHYBA  web.js i na stránce, která ho nepotřebuje" && CHYB=$((CHYB+1)) || echo "  ok     web.js jen tam, kde je potřeba"

echo "== menu"
over "editor menu" 200 "/admin.php?modul=menu" 'data-menu-seznam'
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
MENU='[{"typ":"stranka","ids":'$IDO',"text":"O firmě","deti":[{"typ":"odkaz","text":"Kariéra","url":"https://example.cz/kariera","nove_okno":true}]},{"typ":"novinky"},{"typ":"odkaz","text":"Zlý","url":"javascript:alert(1)"}]'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=menu&akce=uloz&umisteni=hlavni" -d "_csrf=$TOKEN" --data-urlencode "polozky=$MENU"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=menu&akce=uloz&umisteni=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Zásady ochrany soukromí","url":"/zasady"}]'
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/novinky"
grep -q '<li class="podmenu"><a href="[^"]*/o-nas">O firmě</a><ul><li><a href="https://example.cz/kariera" target="_blank" rel="noopener">Kariéra</a>' "$PRACE/odpoved" && grep -q 'aria-current="page">Novinky' "$PRACE/odpoved" && ! grep -q 'javascript:' "$PRACE/odpoved" \
  && echo "  ok     menu s podmenu na webu, nebezpečný odkaz vypadl" || { echo "  CHYBA  menu na webu"; CHYB=$((CHYB+1)); }
mcp nacti_menu '{"umisteni":"paticka"}' > "$PRACE/odpoved"; grep -q 'Zásady ochrany soukromí' "$PRACE/odpoved" && echo "  ok     menu v patičce (MCP)" || { echo "  CHYBA  menu v patičce"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=uloz" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
ocekavej "zaškrtnutá stránka se přidá na konec sestaveného menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT polozky LIKE '%\"ids\":$IDS%' FROM ka_menu WHERE umisteni = 'hlavni'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=menu&akce=automaticky&umisteni=hlavni" -d "_csrf=$TOKEN"
ocekavej "návrat k automatickému menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni'")" "0"

echo "== ikony, manifest, cache"
ocekavej "favicon.ico bez ikony nevygeneruje stránku 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/favicon.ico")" 204
curl -s -o "$PRACE/odpoved" "$B/manifest.webmanifest"; grep -q '"start_url"' "$PRACE/odpoved" && echo "  ok     manifest webu" || { echo "  CHYBA  manifest"; CHYB=$((CHYB+1)); }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/novinky"
ocekavej "odkaz s utm parametry jde z cache" "$(curl -s -o /dev/null -D - "$B/novinky?utm_source=newsletter&fbclid=x" | grep -ci '^x-cache: kaleta')" 1
ETAG=$(curl -s -o /dev/null -D - "$B/novinky" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')
ocekavej "stránka z cache odpoví 304 na shodný ETag" "$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$B/novinky")" 304

echo "== nové prvky builderu"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[
{\"typ\":\"drobecky\"},
{\"typ\":\"ikona\",\"obsah\":{\"ikona\":\"telefon\",\"tvar\":\"kruh\"}},
{\"typ\":\"galerie\",\"obsah\":{\"fotky\":[{\"src\":\"media/2026/01/a.jpg\",\"alt\":\"Dílna\"},{\"src\":\"media/2026/01/b.jpg\",\"alt\":\"\"}]}},
{\"typ\":\"zalozky\",\"obsah\":{\"karty\":[{\"nazev\":\"Základ\",\"obsah\":\"<p>A</p>\"},{\"nazev\":\"Plus\",\"obsah\":\"<p>B</p>\"}]}},
{\"typ\":\"karusel\",\"obsah\":{\"naraz\":\"2\"},\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Snímek</p>\"}}]},
{\"typ\":\"mapa\",\"obsah\":{\"adresa\":\"Brno, Náměstí Svobody\"}},
{\"typ\":\"okno\",\"kotva\":\"nabidka\",\"obsah\":{\"samo\":\"5\"},\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Akce\"}}]},
{\"typ\":\"faq\",\"obsah\":{\"jedna\":true,\"faq\":false,\"polozky\":[{\"otazka\":\"Co?\",\"odpoved\":\"<p>To.</p>\"}]}}
]}]}}" > "$PRACE/odpoved"
grep -q 'chyby\\":\[\]' "$PRACE/odpoved" && echo "  ok     nové prvky projdou validátorem" || { echo "  CHYBA  validace nových prvků"; head -c 600 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"
for vzor in 'class="ka-drobecky"' 'aria-current="page">Z HTML' 'class="ka-ikona ka-ikona--kruh" aria-hidden="true"><svg' 'class="ka-galerie"' 'alt="Dílna"' 'role="tablist"' 'aria-controls="zp-' 'data-karusel' '--ka-naraz:2' 'data-vlozit="https://maps.google.com/maps?q=Brno' 'id="nabidka"' 'popover role="dialog" aria-label="Vyskakovací okno" data-samo="5"' 'name="faq-'; do
  grep -qF -- "$vzor" "$PRACE/odpoved" || { echo "  CHYBA  nový prvek na webu: chybí $vzor"; CHYB=$((CHYB+1)); }
done
grep -q '"BreadcrumbList"' "$PRACE/odpoved" && ! grep -q '"FAQPage"' "$PRACE/odpoved" && echo "  ok     nové prvky na webu, drobečky i pro vyhledávače, akordeon bez FAQPage" || { echo "  CHYBA  strukturovaná data stránky"; CHYB=$((CHYB+1)); }

echo "== další prvky: počítadlo, průběh, hodnocení, odpočet, sítě, hledání, nahoru, odběr, podmínky"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('soc_instagram','https://instagram.com/firma') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"obsah\":{\"video\":\"media/2026/01/pozadi.mp4\"},\"deti\":[
{\"typ\":\"pocitadlo\",\"obsah\":{\"cislo\":1200,\"za\":\"+\"}},
{\"typ\":\"prubeh\",\"obsah\":{\"polozky\":[{\"nazev\":\"Termíny\",\"hodnota\":96}]}},
{\"typ\":\"hodnoceni\",\"obsah\":{\"hodnota\":\"4,5\"}},
{\"typ\":\"odpocet\",\"obsah\":{\"cil\":\"2099-01-01 09:00\"}},
{\"typ\":\"socialni\"},{\"typ\":\"hledani\"},{\"typ\":\"nahoru\"},{\"typ\":\"newsletter\"},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Jen pro redakci\"},\"podminky\":{\"prihlaseni\":\"ano\"}},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Stará akce\"},\"podminky\":{\"do\":\"2000-01-01\"}},
{\"typ\":\"video\",\"obsah\":{\"url\":\"media/2026/01/film.mp4\",\"plakat\":\"media/2026/01/plakat.jpg\"}}
]}]}}" > "$PRACE/odpoved"
grep -q 'chyby\\":\[\]' "$PRACE/odpoved" && echo "  ok     další prvky projdou validátorem" || { echo "  CHYBA  validace dalších prvků"; head -c 600 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"
for vzor in 'data-pocitadlo="1200">1' '<meter min="0" max="100"' 'aria-label="Hodnocení 4,5 z 5' 'data-odpocet="2099-01-01T09:00' 'class="ka-socialni"' 'aria-label="Instagram"' 'role="search"' 'class="ka-nahoru"' 'class="ka-newsletter"' 'name="as_podpis"' 'class="ka-video-pozadi"' 'poster="/media/2026/01/plakat.jpg"' 'image/web.js'; do
  grep -qF -- "$vzor" "$PRACE/odpoved" || { echo "  CHYBA  další prvek na webu: chybí $vzor"; CHYB=$((CHYB+1)); }
done
! grep -q 'Jen pro redakci\|Stará akce' "$PRACE/odpoved" && echo "  ok     podmínky zobrazení skryjí prvek nepřihlášenému i po datu" || { echo "  CHYBA  podmínky zobrazení"; CHYB=$((CHYB+1)); }
curl -s -o /dev/null "$B/z-html"; ls "$PRACE"/web/storage/cache/stranky/*.html >/dev/null 2>&1 && { echo "  CHYBA  stránka s podmínkou zobrazení šla do cache"; CHYB=$((CHYB+1)); } || echo "  ok     stránka s podmínkou zobrazení se necachuje"
curl -s -b "$JAR" -o "$PRACE/odpoved" "$B/z-html"; grep -q 'Jen pro redakci' "$PRACE/odpoved" && echo "  ok     přihlášený vidí prvek jen pro redakci" || { echo "  CHYBA  prvek pro přihlášené"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/z-html"
NL=$(tr '\n' ' ' < "$PRACE/odpoved" | grep -o 'class="ka-newsletter".*' | sed 's#</form>.*##')
PODPIS=$(echo "$NL" | grep -o 'name="as_podpis" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'); CAS=$(echo "$NL" | grep -o 'name="as_cas" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
sleep 5
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/odber" -d "email=Odber@Example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$PODPIS" -d "as_cas=$CAS" -d web_adresa=)
case "$kod" in "303 "*"/z-html?odber=ok#x") echo "  ok     přihlášení k odběru";; *) echo "  CHYBA  přihlášení k odběru: $kod"; CHYB=$((CHYB+1));; esac
TOKO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT token FROM ka_odberatele WHERE email = 'odber@example.cz' AND stav = 0")
over "odkaz z e-mailu jen nabídne potvrzení" 200 "/odber?potvrdit=$TOKO" "Potvrdit odběr"
ocekavej "otevření odkazu (skener pošty) odběr nepotvrdí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "0"
curl -s -o "$PRACE/odpoved" -X POST "$B/odber?potvrdit=$TOKO"; grep -q "Odběr je potvrzený" "$PRACE/odpoved" && echo "  ok     potvrzení odběru tlačítkem" || { echo "  CHYBA  potvrzení odběru"; CHYB=$((CHYB+1)); }
ocekavej "odběratel je potvrzený" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "1"
over "odběratelé v administraci" 200 "/admin.php?modul=odberatele" "odber@example.cz"
curl -s -b "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=odberatele&akce=csv"; grep -q "odber@example.cz;.*odber?odhlasit=$TOKO" "$PRACE/odpoved" && echo "  ok     export odběratelů s odkazem na odhlášení" || { echo "  CHYBA  export odběratelů"; head -3 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'newsletter,', '') WHERE promenna = 'rozsireni'"
over "odhlášení jde i s vypnutým Newsletterem" 200 "/odber?odhlasit=$TOKO" "Odhlásit odběr"
curl -s -o "$PRACE/odpoved" -X POST "$B/odber?odhlasit=$TOKO"; grep -q "Odhlášeno" "$PRACE/odpoved" && echo "  ok     odhlášení tlačítkem" || { echo "  CHYBA  odhlášení"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'poptavky,', 'poptavky,newsletter,') WHERE promenna = 'rozsireni'"
ocekavej "odhlášený je smazaný" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_odberatele")" "0"

echo "== média, tokeny DTCG, kolekce přes MCP"
IDOM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
over "média: hledání a řazení" 200 "/admin.php?modul=intergal&hledat=jpg&razeni=velikost" 'data-popis-media='
odp=$(curl -s -b "$JAR" -c "$JAR" -X POST "$B/admin.php?modul=intergal&akce=uloz_popis" -d "_csrf=$TOKEN" -d "ido=$IDOM" --data-urlencode "popis=Dilna zevnitr")
ocekavej "popis obrázku bez znovunačtení" "$odp|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE ido = $IDOM")" '{"ok":true}|Dilna zevnitr'
curl -s -b "$JAR" -o "$PRACE/tokeny.json" "$B/admin.php?modul=vzhled&akce=tokeny"
grep -q '"\$type": "color"' "$PRACE/tokeny.json" && grep -q '"cz.kaleta"' "$PRACE/tokeny.json" && echo "  ok     export tokenů DTCG" || { echo "  CHYBA  export tokenů"; CHYB=$((CHYB+1)); }
printf '{"color":{"primary":{"$type":"color","$value":"#aa3300"}}}' > "$PRACE/cizi.tokens.json"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=vzhled&akce=tokeny_import" -F "_csrf=$TOKEN" -F "tokeny=@$PRACE/cizi.tokens.json"
ocekavej "import barev z cizích tokenů" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "#aa3300"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=vzhled&akce=tokeny_import" -F "_csrf=$TOKEN" -F "tokeny=@$PRACE/tokeny.json"
ocekavej "import vlastního exportu vrátí vzhled" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) <> '#aa3300' FROM ka_nastaveni WHERE promenna = 'design_system'")" "1"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Mistr truhlář"}' > "$PRACE/odpoved"
grep -q 'Petr Svoboda' "$PRACE/odpoved" && grep -q 'celkem\\":1' "$PRACE/odpoved" && echo "  ok     kolekce přes MCP: filtr podle pole" || { echo "  CHYBA  kolekce přes MCP s filtrem"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }

echo "== záloha a obnova databáze"
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=config&zalozka=zalohy"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=zalohuj" -d "_csrf=$TOKEN"
ZALOHA=$(ls -t "$PRACE"/web/storage/zalohy/ 2>/dev/null | grep -v predobnovou | head -1 || true)
[ -n "$ZALOHA" ] && echo "  ok     záloha vytvořena" || { echo "  CHYBA  záloha nevznikla"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Po zaloze' WHERE promenna = 'nazev_webu'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=obnov_zalohu" -d "_csrf=$TOKEN" -d "soubor=$ZALOHA"
ocekavej "obnova vrátí stav ze zálohy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota <> 'Po zaloze' FROM ka_nastaveni WHERE promenna = 'nazev_webu'")" "1"
POSK="kaleta-poskozena.sql"; [[ "$ZALOHA" == *.gz ]] && POSK="kaleta-poskozena.sql.gz"
if [[ "$ZALOHA" == *.gz ]]; then { gzip -dc "$PRACE/web/storage/zalohy/$ZALOHA" | head -c 4000 || true; } | gzip > "$PRACE/web/storage/zalohy/$POSK"; else head -c 4000 "$PRACE/web/storage/zalohy/$ZALOHA" > "$PRACE/web/storage/zalohy/$POSK"; fi
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Pred poskozenou' WHERE promenna = 'nazev_webu'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=obnov_zalohu" -d "_csrf=$TOKEN" -d "soubor=$POSK"
ocekavej "poškozená záloha databázi nezmění" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'nazev_webu'")" "Pred poskozenou"

echo "== role přes MCP, obnova hesla, zámek účtu"
TOKO2="kaleta_$(printf 'b%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$TOKO2")', NOW() FROM ka_uzivatele WHERE user = 'obchodnik'"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $TOKO2" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"vytvor_novinku","arguments":{"titulek":"Od obchodnika","kategorie":"aktuality"}}}' > "$PRACE/odpoved"
grep -q 'nemáš přístup' "$PRACE/odpoved" && ocekavej "vlastní role bez Novinek nezaloží novinku ani přes MCP" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Od obchodnika'")" "0" || { echo "  CHYBA  MCP bez kontroly sekce Novinky"; head -c 300 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
OBN="$(printf 'c%.0s' $(seq 1 64))"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET obnova_otisk = '$(php -r 'echo hash("sha256", $argv[1]);' "$OBN")', obnova_cas = NOW() + INTERVAL 1 DAY WHERE user = 'obchodnik'"
JAR3="$PRACE/jar3"
TOKEN3=$(curl -s -c "$JAR3" "$B/admin.php?akce=heslo&token=$OBN" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
curl -s -b "$JAR3" -c "$JAR3" -o /dev/null -X POST "$B/admin.php?akce=heslo" -d "_csrf=$TOKEN3" -d "token=$OBN" --data-urlencode "password=Nove-heslo-123" --data-urlencode "password2=Nove-heslo-123"
ocekavej "obnova hesla zruší tokeny napojení" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny t JOIN ka_uzivatele u ON u.idu = t.idu WHERE u.user = 'obchodnik'")" "0"
JAR4="$PRACE/jar4"
TOKEN4=$(curl -s -c "$JAR4" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
for i in $(seq 1 10); do curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik -d password=spatne-heslo-xyz; done
kod=$(curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123")
ocekavej "po 10 chybách je účet dočasně zamčený i pro správné heslo" "$kod|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zamceno_do > NOW() FROM ka_uzivatele WHERE user = 'obchodnik'")" "401|1"

echo "== OAuth pro konektor Claude"
curl -s -o "$PRACE/odpoved" -D "$PRACE/hlavicky" -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}'
grep -qi 'www-authenticate: Bearer resource_metadata="http://127.0.0.1:[0-9]*/.well-known/oauth-protected-resource"' "$PRACE/hlavicky" && echo "  ok     MCP bez tokenu odkáže na metadata OAuth" || { echo "  CHYBA  WWW-Authenticate u MCP"; CHYB=$((CHYB+1)); }
over "metadata chráněného zdroje" 200 "/.well-known/oauth-protected-resource" '"authorization_servers"'
over "metadata autorizačního serveru" 200 "/.well-known/oauth-authorization-server" '"code_challenge_methods_supported":\["S256"\]'
NAVRAT="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$PRACE/odpoved" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude\",\"redirect_uris\":[\"$NAVRAT\"],\"token_endpoint_auth_method\":\"none\"}"
KLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$PRACE/odpoved" | sed 's/.*:"//;s/"//' || true)
[ -n "$KLIENT" ] && echo "  ok     dynamická registrace klienta" || { echo "  CHYBA  registrace klienta"; cat "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
ocekavej "registrace odmítne http adresu návratu" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["http://zly.example/cb"]}')" 400
VER="$(printf 'v%.0s' $(seq 1 50))"; CH=$(printf %s "$VER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
ocekavej "cizí adresa návratu se nepřesměruje" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?response_type=code&client_id=$KLIENT&redirect_uri=https://zly.example/&code_challenge=$CH&code_challenge_method=S256")" 400
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$KLIENT&redirect_uri=$NAVRAT&code_challenge=$CH&code_challenge_method=S256&state=xyz&scope=mcp")
case "$kod" in "302 "*akce=oauth) echo "  ok     přihlášení vede na souhlas v administraci";; *) echo "  CHYBA  authorize: $kod"; CHYB=$((CHYB+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -D "$PRACE/hlavicky" "$B/admin.php?akce=oauth"; grep -q 'Povolit přístup' "$PRACE/odpoved" && echo "  ok     stránka souhlasu" || { echo "  CHYBA  stránka souhlasu"; CHYB=$((CHYB+1)); }
grep -qi "form-action 'self' https://claude.ai;" "$PRACE/hlavicky" && ! grep -qi "x-kaleta-form-action" "$PRACE/hlavicky" && echo "  ok     CSP souhlasu povolí návrat do aplikace (form-action)" || { echo "  CHYBA  CSP form-action na stránce souhlasu"; grep -i "content-security" "$PRACE/hlavicky"; CHYB=$((CHYB+1)); }
TOKENO=$(csrf)
ZPET=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?akce=oauth" -d "_csrf=$TOKENO" -d povolit=1)
KOD=$(printf %s "$ZPET" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
case "$ZPET" in "$NAVRAT?code="*"state=xyz"*) echo "  ok     souhlas vrátí kód a state do aplikace";; *) echo "  CHYBA  návrat po souhlasu: $ZPET"; CHYB=$((CHYB+1));; esac
ocekavej "špatný code_verifier (PKCE) neprojde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$KOD" -d "redirect_uri=$NAVRAT" -d "client_id=$KLIENT" -d code_verifier=spatny-overovac-spatny-overovac-spatny-overovac)" 400
ZPET=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$KLIENT&redirect_uri=$NAVRAT&code_challenge=$CH&code_challenge_method=S256&state=abc" && curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?akce=oauth" -d "_csrf=$TOKENO" -d povolit=1)
KOD=$(printf %s "$ZPET" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$PRACE/odpoved" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$KOD" -d "redirect_uri=$NAVRAT" -d "client_id=$KLIENT" -d "code_verifier=$VER"
PRISTUP=$(grep -o '"access_token":"[a-z0-9_]*"' "$PRACE/odpoved" | sed 's/.*:"//;s/"//' || true); OBNOVA=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$PRACE/odpoved" | sed 's/.*:"//;s/"//' || true)
[ -n "$PRISTUP" ] && [ -n "$OBNOVA" ] && echo "  ok     výměna kódu za tokeny (PKCE)" || { echo "  CHYBA  token endpoint"; cat "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
ocekavej "kód jde použít jen jednou" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$KOD" -d "redirect_uri=$NAVRAT" -d "client_id=$KLIENT" -d "code_verifier=$VER")" 400
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $PRISTUP" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$PRACE/odpoved"
grep -q 'stavba_schema' "$PRACE/odpoved" && echo "  ok     MCP s přístupovým tokenem z OAuth" || { echo "  CHYBA  MCP s tokenem OAuth"; head -c 300 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
ocekavej "obnovovací token nejde použít k MCP" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $OBNOVA" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" 401
curl -s -o "$PRACE/odpoved" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$OBNOVA" -d "client_id=$KLIENT"
grep -q '"access_token"' "$PRACE/odpoved" && echo "  ok     obnova tokenu" || { echo "  CHYBA  obnova tokenu"; cat "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
ocekavej "obnovovací token se po použití vymění" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$OBNOVA" -d "client_id=$KLIENT")" 400
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # limit přihlášení z IP vyčerpal test zámku účtu
JAR5="$PRACE/jar5"
curl -s -c "$JAR5" -b "$JAR5" -o /dev/null "$B/oauth/authorize?response_type=code&client_id=$KLIENT&redirect_uri=$NAVRAT&code_challenge=$CH&code_challenge_method=S256&state=nove"
TOKEN5=$(curl -s -b "$JAR5" -c "$JAR5" "$B/admin.php?akce=oauth" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
kod=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$TOKEN5" -d user=admin --data-urlencode "password=$HESLO")
case "$kod" in *akce=oauth) echo "  ok     nepřihlášený se po přihlášení vrátí na souhlas";; *) echo "  CHYBA  návrat na souhlas po přihlášení: $kod"; CHYB=$((CHYB+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?akce=ucet"; grep -q 'Připojené aplikace' "$PRACE/odpoved" && echo "  ok     připojená aplikace v Můj účet" || { echo "  CHYBA  připojené aplikace"; CHYB=$((CHYB+1)); }
TOKENO=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?akce=ucet" -d "_csrf=$TOKENO" -d "odpojit_klient=$KLIENT"
ocekavej "odpojení aplikace smaže její tokeny" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$KLIENT'")" "0"

echo "== dvoufázové přihlášení (TOTP a záložní kódy)"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP', totp_zalozni = '[\"$(php -r 'echo hash("sha256", "abcde-12345");')\"]' WHERE user = 'autor'"
prihlas2fa() { # prihlas2fa <jar> → vrátí kód odpovědi na zadání druhého kroku <kod>
  local jar="$1" t; t=$(curl -s -c "$jar" -b "$jar" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
  curl -s -b "$jar" -c "$jar" -o "$PRACE/odpoved" -X POST "$B/admin.php" -d "_csrf=$t" -d user=autor --data-urlencode "password=$HESLO"
  grep -q 'name="kod"' "$PRACE/odpoved" || echo "bez-druheho-kroku"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod -d "kod=$2"
}
ocekavej "špatný kód z aplikace neprojde" "$(prihlas2fa "$PRACE/jar6" 000000)" "401"
KODT=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::kod("JBSWY3DPEHPK3PXP", intdiv(time(), 30));' "$KOREN")
ocekavej "přihlášení s kódem z aplikace (TOTP)" "$(prihlas2fa "$PRACE/jar7" "$KODT")" "302"
ocekavej "záložní kód projde" "$(prihlas2fa "$PRACE/jar8" abcde-12345)" "302"
ocekavej "záložní kód jde použít jen jednou" "$(prihlas2fa "$PRACE/jar9" abcde-12345)" "401"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET totp_tajemstvi = '', totp_zalozni = NULL WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'"

echo "== vypnutá rozšíření Novinky a Formuláře a poptávky"
ROZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna='rozsireni'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='statistika,presmerovani,claude' WHERE promenna='rozsireni'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html "$PRACE"/web/storage/cache/*.txt 2>/dev/null || true
over "výpis novinek je pryč" 404 /novinky
over "novinka je pryč" 404 /novinky/vitejte-v-kalete
over "RSS je pryč" 404 /rss.xml
curl -s -o "$PRACE/odpoved" "$B/sitemap.xml"; ! grep -q "/novinky" "$PRACE/odpoved" && echo "  ok     mapa webu bez novinek" || { echo "  CHYBA  mapa webu s vypnutými novinkami"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/o-nas"; ! grep -q 'rss.xml' "$PRACE/odpoved" && echo "  ok     bez novinek ani odkaz na RSS" || { echo "  CHYBA  odkaz na RSS při vypnutých novinkách"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/o-nas"; ! grep -q 'href="[^"]*/novinky"' "$PRACE/odpoved" && echo "  ok     menu bez odkazu na novinky" || { echo "  CHYBA  menu odkazuje na vypnuté novinky"; CHYB=$((CHYB+1)); }
ocekavej "odeslání formuláře nejde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/formular" -d x=1)" 404
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php"; ! grep -q 'modul=novinky"' "$PRACE/odpoved" && ! grep -q 'modul=poptavky"' "$PRACE/odpoved" && echo "  ok     administrace bez novinek a poptávek" || { echo "  CHYBA  administrace ukazuje vypnutá rozšíření"; CHYB=$((CHYB+1)); }
mcp stavba_schema '{}' > "$PRACE/odpoved"; ! grep -q '\\"formular\\":' "$PRACE/odpoved" && ! grep -q 'seznam_novinek' <(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') \
  && echo "  ok     builder a MCP nenabízejí prvky ani nástroje vypnutých rozšíření" || { echo "  CHYBA  schéma nebo MCP s vypnutými rozšířeními"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$ROZ' WHERE promenna='rozsireni'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html

echo "== instalace aktualizace (testovací klíč a kanál)"
cat > "$PRACE/vydani-test.php" <<'PHP'
<?php
[$web, $port] = [$argv[1], $argv[2]];
require $web . '/system/src/Core/Podpis.php';
$par = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($par);
file_put_contents($web . '/system/aktualizace.pub', base64_encode(sodium_crypto_sign_publickey($par)) . " test\n");
// seznam souborů „nainstalované verze“: podle otisku .htaccess aktualizace pozná, že ho správce upravil
file_put_contents($web . '/system/soubory.json', json_encode(['verze' => '1.0.0-dev', 'soubory' => ['.htaccess' => hash_file('sha256', $web . '/.htaccess')]]));
@mkdir(dirname($web) . '/kanal');
$zip = new ZipArchive();
$zip->open(dirname($web) . '/kanal/k.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('layout/zakladni/test-aktualizace.txt', "nova verze\n");
$zip->addFromString('.htaccess', "# htaccess nove verze\n");
$zip->addFromString('system/bootstrap.php', (string) file_get_contents($web . '/system/bootstrap.php')); // balíček musí nést jádro
$zip->addFromString('index.php', (string) file_get_contents($web . '/index.php'));
$zip->close();
$sha = hash_file('sha256', dirname($web) . '/kanal/k.zip');
$m = ['verze' => '9.9.9', 'url' => "http://127.0.0.1:$port/k.zip", 'sha256' => $sha, 'min_php' => '8.4', 'zmeny' => ['test'],
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Podpis::zpravaBalicku('9.9.9', $sha, false), $sk))];
file_put_contents(dirname($web) . '/kanal/ok.json', json_encode($m));
file_put_contents(dirname($web) . '/kanal/zly.json', json_encode(['podpis' => base64_encode(random_bytes(64))] + $m));
PHP
# kanál na vlastním serveru: vestavěný server PHP obsluhuje jen jeden požadavek, sám od sebe by stahovat nemohl
KPORT=$((PORT + 1))
php "$PRACE/vydani-test.php" "$PRACE/web" "$KPORT"
(cd "$PRACE/kanal" && exec php -S "127.0.0.1:$KPORT" > /dev/null 2>&1) & KANAL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$KPORT/ok.json" && break; sleep 0.2; done
echo "# vlastni uprava spravce" >> "$PRACE/web/.htaccess"
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=config&zalozka=zalohy"; TOKEN=$(csrf)
aktualizuj() { "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('aktualizace_url','http://127.0.0.1:$KPORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'aktualizace_cache'"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=aktualizuj" -d "_csrf=$TOKEN"; }
aktualizuj zly.json
[ ! -f "$PRACE/web/layout/zakladni/test-aktualizace.txt" ] && echo "  ok     balíček s cizím podpisem se nenainstaluje" || { echo "  CHYBA  nainstalován balíček s neplatným podpisem"; CHYB=$((CHYB+1)); }
aktualizuj ok.json
[ -f "$PRACE/web/layout/zakladni/test-aktualizace.txt" ] && echo "  ok     podepsaná aktualizace se nainstaluje" || { echo "  CHYBA  aktualizace se nenainstalovala"; CHYB=$((CHYB+1)); }
grep -q "vlastni uprava spravce" "$PRACE/web/.htaccess" && [ -f "$PRACE/web/.htaccess.kaleta-nova" ] && echo "  ok     vlastní .htaccess zůstal, nová verze leží vedle" || { echo "  CHYBA  aktualizace přepsala vlastní .htaccess"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('aktualizace_url', 'aktualizace_cache')"
kill "$KANAL_PID" 2>/dev/null || true

if [ -s "$PRACE/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$PRACE/web/storage/log/chyby.log"; CHYB=$((CHYB+1)); fi
echo; [ "$CHYB" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $CHYB"; exit 1; }
