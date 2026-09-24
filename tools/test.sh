#!/usr/bin/env bash
# MiroCMS - kouřový test: čistá instalace do dočasné kopie a průchod hlavními stránkami.
# Spouští se lokálně i v GitHub Actions. Databázi bere z proměnných prostředí:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (mirocms_test) DB_USER (root) DB_PASS (prázdné) PORT (8099)
# Databáze DB_NAME se při testu SMAŽE a vytvoří znovu.
set -euo pipefail

KOREN="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-mirocms_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
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
grep -q "const MIROCMS_VERZE_DB = $POSLEDNI;" "$KOREN/system/bootstrap.php" && echo "  ok     MIROCMS_VERZE_DB odpovídá poslední migraci ($POSLEDNI)" || { echo "  CHYBA  MIROCMS_VERZE_DB v system/bootstrap.php neodpovídá poslední migraci ($POSLEDNI)"; CHYB=$((CHYB+1)); }

echo "== jednotkové testy"
php "$KOREN/tools/testy.php" || CHYB=$((CHYB+1))

csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//'; }
ocekavej() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; CHYB=$((CHYB+1)); }; }

echo "== instalace"
HESLO="Test-$(date +%s)-heslo"
curl -s -o "$PRACE/odpoved" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=mc_ \
  --data-urlencode "nazev_webu=Testovací firma" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$HESLO" --data-urlencode "password2=$HESLO"
grep -q "Hotovo, web běží" "$PRACE/odpoved" || { echo "  CHYBA  instalace selhala"; sed 's/<[^>]*>//g' "$PRACE/odpoved" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     instalace"
[ ! -f "$PRACE/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; CHYB=$((CHYB+1)); }

echo "== web"
over "úvodní stránka" 200 / "Testovací firma"
over "úvodní stránka má navigaci stránek a novinek" 200 / 'href="/o-nas"'
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/uvod"); ocekavej "úvodní stránka má jen jednu adresu" "$kod" "301 $B/"
over "stránka" 200 /sluzby "Služby"
over "úvodní stránka je ze sekcí stavitele" 200 / 'class="stavba"'
over "služby mají otázky a odpovědi i pro vyhledávače" 200 /sluzby '"FAQPage"'
over "výpis novinek" 200 /novinky "Vítejte v MiroCMS"
over "novinka" 200 /novinky/vitejte-v-mirocms "Vítejte"
over "kategorie" 200 /novinky/kategorie/aktuality
over "hledání najde novinku i stránku" 200 "/hledani?q=Kontakt" 'href="/kontakt"'
for u in /rss.xml /feed.json /sitemap.xml /robots.txt /llms.txt /novinky/vitejte-v-mirocms.md; do over "$u" 200 "$u"; done
over "mapa webu obsahuje novinku" 200 /sitemap.xml "/novinky/vitejte-v-mirocms"
over "llms.txt vyjmenuje stránky" 200 /llms.txt "## Stránky"
over "strukturovaná data novinky" 200 /novinky/vitejte-v-mirocms '"BlogPosting"'
over "neexistující stránka" 404 /tohle-neexistuje
over "system/ není přístupný" 403 /system/sql/schema.sql
over "config.php není přístupný" 403 /config.php
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE mc_nastaveni SET hodnota='neexistuje' WHERE promenna='layout'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "chybějící šablona – web běží na výchozí" 200 / "layout/zakladni/style.css"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE mc_nastaveni SET hodnota='0' WHERE promenna='titulni_stranka'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "bez úvodní stránky je úvodem výpis novinek" 200 / "Vítejte v MiroCMS"

echo "== administrace"
over "zapomenuté heslo – formulář" 200 "/admin.php?akce=heslo" "Poslat odkaz"
over "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?akce=heslo&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
over "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(csrf)
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); ocekavej "špatné heslo odmítnuto" "$kod" 401
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$HESLO"); ocekavej "POST bez CSRF odmítnut" "$kod" 400
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$HESLO"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO mc_nastaveni VALUES ('rozsireni','statistika,presmerovani,asistent,jazyky,api,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
over "přehled" 200 /admin.php "Přehled"
for m in stranky "stranky&akce=novy" poptavky casti kolekce "kolekce&akce=novy" novinky "novinky&akce=novy" "novinky&akce=odkazy" kategorie "kategorie&akce=novy" stitky intergal stat vzhled users "users&akce=novy" presmerovani protokol prenos rozsireni; do over "modul $m" 200 "/admin.php?modul=$m"; done
over "uživatelé se shrnutím oprávnění" 200 "/admin.php?modul=users" "Smí všechno"
for z in zakladni seo mereni cookies posta zalohy stav; do over "nastavení/$z" 200 "/admin.php?modul=config&zalozka=$z"; done
over "nastavení: volba úvodní stránky" 200 "/admin.php?modul=config&zalozka=zakladni" 'name="titulni_stranka"'
over "neznámý modul" 403 "/admin.php?modul=neexistuje"
over "API: novinky" 200 /api/novinky '"novinky"'
over "API: stránky" 200 /api/stranky '/kontakt"'
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO mc_nastaveni VALUES ('jazyky_dalsi','en') ON DUPLICATE KEY UPDATE hodnota='en'"
over "anglická verze webu" 200 /en/ 'lang="en"'
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/novinky/vitejte-v-mirocms"); ocekavej "novinka jiné jazykové verze přesměruje" "$kod" 301

# neúspěšná validace novinky musí vrátit formulář s hláškou, ne chybu 500
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=novinky&akce=novy"
TOKEN=$(csrf)
kod=$(curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=novinky&akce=uloz" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$kod" = 200 ] && grep -q 'name="titulek"' "$PRACE/odpoved" && echo "  ok     chyba ve formuláři novinky vrátí formulář" || { echo "  CHYBA  validace novinky: kód $kod"; CHYB=$((CHYB+1)); }

# autor novinek: vidí jen své novinky a nevydává
NOVINKA=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM mc_novinky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=users&akce=uloz" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Autor -d user=autor --data-urlencode "password=$HESLO" -d admin=0
JAR2="$PRACE/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=autor --data-urlencode "password=$HESLO"
kod=$(curl -s -b "$JAR2" -o "$PRACE/odpoved" -w '%{http_code}' "$B/admin.php?modul=novinky")
[ "$kod" = 200 ] && ! grep -q "akce=edit&amp;id=$NOVINKA\"" "$PRACE/odpoved" && echo "  ok     autor nevidí cizí novinky" || { echo "  CHYBA  autor – výpis: kód $kod"; CHYB=$((CHYB+1)); }
ocekavej "autor cizí novinku neotevře" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=novinky&akce=edit&id=$NOVINKA")" 404
ocekavej "autor nemá přístup ke stránkám" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=stranky")" 403

echo "== firma"
over "nastavení/firma" 200 "/admin.php?modul=config&zalozka=firma" 'name="firma_hodiny"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=uloz" -d "_csrf=$TOKEN" -d zalozka=firma --data-urlencode "firma_nazev=Testovací firma s.r.o." -d firma_typ=HomeAndConstructionBusiness \
  -d firma_ico=12345678 -d firma_dic=CZ12345678 --data-urlencode "firma_ulice=Dlouhá 12" --data-urlencode "firma_mesto=Praha" --data-urlencode "firma_psc=110 00" -d firma_zeme=CZ \
  --data-urlencode "firma_telefon=+420 123 456 789" --data-urlencode "firma_hodiny=Po–Pá 8:00–17:00
So 9–12" --data-urlencode "firma_mapa=https://mapy.cz/s/abc" --data-urlencode "firma_gps=50.0875, 14.4213"
ocekavej "údaje firmy uloženy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM mc_nastaveni WHERE promenna = 'firma_ico'")" 12345678
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=uloz" -d "_csrf=$TOKEN" -d zalozka=firma -d firma_typ=LocalBusiness -d firma_zeme=CZ --data-urlencode "firma_hodiny=kdykoli"
ocekavej "nesrozumitelná otevírací doba odmítnuta" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%8:00%' AND hodnota NOT LIKE '%kdykoli%' FROM mc_nastaveni WHERE promenna = 'firma_hodiny'")" 1
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
grep -q 'mc-barva-primarni: #ff00aa' "$PRACE/odpoved" && grep -q '"kontrasty"' "$PRACE/odpoved" && echo "  ok     živý náhled vrátí tokeny a kontrasty" || { echo "  CHYBA  náhled vzhledu"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=vzhled&akce=uloz" -d "_csrf=$TOKEN" -d layout=zakladni -d tmavy_rezim=vypnuto --data-urlencode 'ds[barvy][primarni]=#9a3412' --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280'
curl -s -o "$PRACE/odpoved" "$B/"
grep -q 'mc-barva-primarni: #9a3412' "$PRACE/odpoved" && grep -q 'mc-sirka: 80rem' "$PRACE/odpoved" && grep -q 'mc-pismo-titulky: Georgia' "$PRACE/odpoved" && echo "  ok     uložený vzhled je hned na webu" || { echo "  CHYBA  uložení vzhledu"; CHYB=$((CHYB+1)); }
grep -q 'body{' "$PRACE/odpoved" && { echo "  CHYBA  do CSS proniklo neplatné zadání barvy"; CHYB=$((CHYB+1)); } || echo "  ok     neplatná barva se nahradí výchozí"

echo "== stavitel stránek"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM mc_stranky WHERE seo_link = 'o-nas'")
over "stavitel se otevře a převede textovou stránku" 200 "/admin.php?modul=stranky&akce=stavitel&id=$IDS" 'id="stavitel-data"'
TOKEN=$(csrf)
st() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=stranky&akce=$1&id=$IDS" -d "_csrf=$TOKEN" "${@:2}"; }
STAVBA='{"v":1,"deti":[{"id":"sek1","typ":"sekce","deti":[{"id":"nad1","typ":"nadpis","znacka":"h1","obsah":{"text":"Stavitel test"},"styl":{"zaklad":{"barva":"primarni"},"mobil":{"velikost_pisma":"2"}},"tridy":["karta"]},{"id":"faq1","typ":"faq","obsah":{"polozky":[{"otazka":"Kolik to stojí?","odpoved":"<p>Záleží na rozsahu.</p>"}]}},{"id":"zly1","typ":"skript"}]}]}'
kod=$(st stavba_uloz --data-urlencode "stavba=$STAVBA")
[ "$kod" = 200 ] && grep -q '"ok":true' "$PRACE/odpoved" && grep -q 'Neznámý typ prvku' "$PRACE/odpoved" && echo "  ok     uložení konceptu vrátí vyčištěnou stavbu a chyby" || { echo "  CHYBA  stavba_uloz: kód $kod"; CHYB=$((CHYB+1)); }
ocekavej "neplatný JSON stavby odmítnut" "$(st stavba_uloz -d 'stavba={nesmysl')" 400
ocekavej "stavitel bez CSRF odmítnut" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?modul=stranky&akce=stavba_uloz&id=$IDS" --data-urlencode "stavba=$STAVBA")" 400
ocekavej "knihovna sekcí jen přes POST" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=stranky&akce=stavba_sekce&id=$IDS&klic=faq")" 404
kod=$(st "stavba_sekce&klic=vyhody"); [ "$kod" = 200 ] && grep -q '"karta"' "$PRACE/odpoved" && echo "  ok     sekce z knihovny založí své třídy" || { echo "  CHYBA  stavba_sekce: kód $kod"; CHYB=$((CHYB+1)); }
kod=$(st stavba_trida -d nazev=karta --data-urlencode 'styl={"zaklad":{"pozadi":"plocha","odsazeni_y":"l"}}' --data-urlencode 'css=letter-spacing: 0.01em; background: url(x)')
[ "$kod" = 200 ] && grep -q 'Nepovolená deklarace' "$PRACE/odpoved" && echo "  ok     třída uložena, nebezpečné CSS zahozeno" || { echo "  CHYBA  stavba_trida: kód $kod"; CHYB=$((CHYB+1)); }
ocekavej "neplatný název třídy odmítnut" "$(st stavba_trida -d 'nazev=Karta Velka')" 400
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"; ! grep -q "Stavitel test" "$PRACE/odpoved" && echo "  ok     koncept není před publikováním na webu" || { echo "  CHYBA  koncept je na webu dřív, než se publikuje"; CHYB=$((CHYB+1)); }
over "náhled konceptu pro editor" 200 "/o-nas?stavba=koncept&editor=1" 'data-mc-id="nad1"'
over "náhled konceptu se neindexuje" 200 "/o-nas?stavba=koncept" 'noindex'
curl -s -o "$PRACE/odpoved" "$B/o-nas?stavba=koncept&editor=1"; ! grep -q "Stavitel test" "$PRACE/odpoved" && echo "  ok     náhled konceptu nevidí návštěvník" || { echo "  CHYBA  koncept vidí nepřihlášený"; CHYB=$((CHYB+1)); }
kod=$(st stavba_publikuj); ocekavej "publikování stavby" "$kod" 200
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"
grep -q '<h1 id="s-nad1" class="karta">Stavitel test</h1>' "$PRACE/odpoved" && echo "  ok     publikovaná stavba na webu, jedna značka na prvek" || { echo "  CHYBA  stavba na webu"; CHYB=$((CHYB+1)); }
grep -q 'data-mc-id' "$PRACE/odpoved" && { echo "  CHYBA  značky editoru na veřejném webu"; CHYB=$((CHYB+1)); } || echo "  ok     bez značek editoru na veřejném webu"
grep -q '@layer prvky' "$PRACE/odpoved" && grep -q '#s-nad1 { color: var(--mc-barva-primarni); }' "$PRACE/odpoved" && grep -q '.karta { background-color: var(--mc-barva-plocha)' "$PRACE/odpoved" && echo "  ok     CSS prvků a tříd ve vrstvách" || { echo "  CHYBA  CSS stavby"; CHYB=$((CHYB+1)); }
grep -q '"FAQPage"' "$PRACE/odpoved" && echo "  ok     otázky a odpovědi jako strukturovaná data" || { echo "  CHYBA  FAQPage chybí"; CHYB=$((CHYB+1)); }
over "hledání najde obsah stavby" 200 "/hledani?q=Stavitel+test" 'Nalezeno: 1'
st stavba_uloz --data-urlencode "stavba=${STAVBA/Stavitel test/Druhá verze}" > /dev/null; st stavba_publikuj > /dev/null
ocekavej "předchozí publikovaná verze je v historii" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM mc_stavba_revize WHERE ids = $IDS")" 1
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM mc_stavba_revize WHERE ids = $IDS")
st stavba_obnov -d "idr=$IDR" > /dev/null; grep -q 'Stavitel test' "$PRACE/odpoved" && echo "  ok     obnovení verze do konceptu" || { echo "  CHYBA  stavba_obnov"; CHYB=$((CHYB+1)); }
st stavba_zahod > /dev/null; grep -q 'Druhá verze' "$PRACE/odpoved" && echo "  ok     zahození změn vrátí publikovanou stavbu" || { echo "  CHYBA  stavba_zahod"; CHYB=$((CHYB+1)); }
ocekavej "autor novinek do stavitele nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=stranky&akce=stavitel&id=$IDS")" 403
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=stavba_text" -d "_csrf=$TOKEN" -d "ids=$IDS"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q "<h1>Druhá verze</h1>" "$PRACE/odpoved" && grep -q 'class="obal obsah"' "$PRACE/odpoved" && echo "  ok     návrat k textu zachová obsah stavby bez rozložení" || { echo "  CHYBA  stavba_text"; CHYB=$((CHYB+1)); }

echo "== Claude (MCP): stavitel"
TOK="mirocms_$(printf 'a%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO mc_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$TOK")', NOW() FROM mc_uzivatele WHERE user = 'admin'"
mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
mcp stavba_schema '{}' > "$PRACE/odpoved"; grep -q 'knihovna' "$PRACE/odpoved" && grep -q 'mc-mezera' "$PRACE/odpoved" && echo "  ok     MCP: schéma stavitele" || { echo "  CHYBA  MCP stavba_schema"; head -c 300 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
mcp stavba_z_html '{"titulek":"Z HTML","html":"<style>.uvod-x { padding-block: var(--mc-mezera-2xl); } .uvod-x h1 { color: red }</style><header class=\"uvod-x\"><div class=\"container\"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class=\"btn\" href=\"/kontakt\">Kontakt</a></div></header><form><input></form>"}' > "$PRACE/odpoved"
grep -q 'koncept' "$PRACE/odpoved" && grep -q 'Formul' "$PRACE/odpoved" && grep -q 'vynech.*btn' "$PRACE/odpoved" && echo "  ok     MCP: HTML převedeno na koncept stavby s hlášením (i formulář)" || { echo "  CHYBA  MCP stavba_z_html"; head -c 600 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
IDZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM mc_stranky WHERE seo_link = 'z-html'")
ocekavej "MCP: nová stránka zůstává skrytá a bez publikované stavby" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba IS NULL, '/', stavba_koncept LIKE '%od Clauda%') FROM mc_stranky WHERE ids = $IDZ")" "0/1/1"
ocekavej "MCP: třída z <style> uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT css FROM mc_tridy WHERE nazev = 'uvod-x'")" "padding-block: var(--mc-mezera-2xl);"
mcp vloz_sekci "{\"id\":$IDZ,\"sekce\":\"faq\"}" > /dev/null
mcp publikuj_stavbu "{\"id\":$IDZ}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE mc_stranky SET zobrazit = 1 WHERE ids = $IDZ"
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/z-html"
grep -q '<h1>Stránka od Clauda</h1>' "$PRACE/odpoved" && grep -q 'class="uvod-x"' "$PRACE/odpoved" && ! grep -q 'container' "$PRACE/odpoved" && grep -q '"FAQPage"' "$PRACE/odpoved" && echo "  ok     MCP: publikovaná stránka od Clauda na webu" || { echo "  CHYBA  MCP publikování"; CHYB=$((CHYB+1)); }
mcp uprav_design_system '{"ds":{"barvy":{"primarni":"#0f766e"},"zaobleni":"l"}}' > "$PRACE/odpoved"; grep -q 'citelnost' "$PRACE/odpoved" && echo "  ok     MCP: úprava design systému" || { echo "  CHYBA  MCP uprav_design_system"; CHYB=$((CHYB+1)); }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "design systém z MCP je na webu" 200 / 'mc-barva-primarni: #0f766e'
over "design systém z MCP zachoval ostatní barvy" 200 / 'mc-barva-plocha: #f5f6f8'

echo "== části webu ve staviteli"
over "části webu" 200 "/admin.php?modul=casti" "Záhlaví"
over "záhlaví se otevře ve staviteli s koncept podle šablony" 200 "/admin.php?modul=casti&akce=stavitel&typ=hlavicka&jazyk=" 'id="stavitel-data"'
TOKEN=$(csrf)
cast() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=casti&akce=$1&typ=$2&jazyk=" -d "_csrf=$TOKEN" "${@:3}"; }
rm -f "$PRACE"/web/storage/cache/stranky/*.html
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q 'header class="hlavicka"' "$PRACE/odpoved" && ! grep -q 'mc-nav' "$PRACE/odpoved" && echo "  ok     nepublikované záhlaví kreslí šablona" || { echo "  CHYBA  nepublikované záhlaví je na webu"; CHYB=$((CHYB+1)); }
over "náhled konceptu záhlaví pro editor" 200 "/o-nas?cast=hlavicka&stavba=koncept&editor=1" 'data-mc-typ="navigace"'
curl -s -o "$PRACE/odpoved" "$B/o-nas?cast=hlavicka&stavba=koncept&editor=1"; ! grep -q 'data-mc-typ' "$PRACE/odpoved" && echo "  ok     náhled části nevidí návštěvník" || { echo "  CHYBA  koncept části vidí nepřihlášený"; CHYB=$((CHYB+1)); }
ocekavej "publikování záhlaví" "$(cast stavba_publikuj hlavicka)" 200
curl -s -o "$PRACE/odpoved" "$B/o-nas"
grep -q 'class="mc-nav"' "$PRACE/odpoved" && ! grep -q 'header class="hlavicka"' "$PRACE/odpoved" && grep -q 'href="/o-nas" aria-current="page"' "$PRACE/odpoved" && echo "  ok     záhlaví ze stavitele na webu s aktivní položkou menu" || { echo "  CHYBA  záhlaví ze stavitele"; CHYB=$((CHYB+1)); }
[ "$(grep -o '<style>' "$PRACE/odpoved" | wc -l | tr -d ' ')" = 1 ] && [ "$(grep -o '@layer stavitel {' "$PRACE/odpoved" | wc -l | tr -d ' ')" = 1 ] && echo "  ok     stránka a části webu mají jedno CSS" || { echo "  CHYBA  CSS částí webu se opakuje"; CHYB=$((CHYB+1)); }
OBALKA='{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}'
over "obálka novinky ve staviteli" 200 "/admin.php?modul=casti&akce=stavitel&typ=novinka&jazyk=" 'id="stavitel-data"'
cast stavba_uloz novinka --data-urlencode "stavba=$OBALKA" > /dev/null; cast stavba_publikuj novinka > /dev/null
curl -s -o "$PRACE/odpoved" "$B/novinky/vitejte-v-mirocms"; grep -q 'Pod článkem' "$PRACE/odpoved" && grep -q '<main id="obsah" class="stavba">' "$PRACE/odpoved" && grep -q 'class="obal obsah"' "$PRACE/odpoved" && grep -q 'Vítejte' "$PRACE/odpoved" && echo "  ok     obálka kolem novinky" || { echo "  CHYBA  obálka novinky"; CHYB=$((CHYB+1)); }
cast stavba_uloz hlavicka --data-urlencode 'stavba={"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}' > /dev/null; cast stavba_publikuj hlavicka > /dev/null
ocekavej "předchozí záhlaví je ve verzích" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM mc_stavba_revize WHERE cast = 'hlavicka:'")" 1
cast sablona hlavicka > /dev/null
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q 'header class="hlavicka"' "$PRACE/odpoved" && echo "  ok     vrácení záhlaví na šablonu" || { echo "  CHYBA  vrácení na šablonu"; CHYB=$((CHYB+1)); }
mcp stavba_uloz '{"cast":"paticka","stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]},"publikovat":true}' > "$PRACE/odpoved"
grep -q 'publikováno' "$PRACE/odpoved" && echo "  ok     MCP: patička ze stavby" || { echo "  CHYBA  MCP patička"; head -c 400 "$PRACE/odpoved"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/o-nas"; grep -q "<p class=\"mc-udaj\">&copy; $(date +%Y) Testovací firma</p>" "$PRACE/odpoved" && ! grep -q 'footer class="paticka"' "$PRACE/odpoved" && echo "  ok     patička z MCP na webu" || { echo "  CHYBA  patička z MCP na webu"; CHYB=$((CHYB+1)); }
ocekavej "autor novinek k částem webu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=casti")" 403

echo "== formuláře a poptávky"
curl -s -o "$PRACE/formular.html" "$B/kontakt"
grep -q 'class="mc-formular"' "$PRACE/formular.html" && grep -q 'name="as_podpis"' "$PRACE/formular.html" && echo "  ok     kontakt má poptávkový formulář" || { echo "  CHYBA  formulář na kontaktu"; CHYB=$((CHYB+1)); }
hodnota() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$PRACE/formular.html" | head -1 | sed 's/.*value="//;s/"$//'; }
FZ=$(hodnota zdroj); FP=$(hodnota prvek); FC=$(hodnota as_cas); FS=$(hodnota as_podpis)
odesli() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FZ" -d "prvek=$FP" -d zpet=/kontakt -d "as_cas=$FC" -d "as_podpis=$FS" "$@"; }
sleep 4
kam=$(odesli -d p0=Jana --data-urlencode p1=jana@example.cz -d p2= --data-urlencode "p3=Chci kuchyň na míru." -d p4=1)
case "$kam" in *"/kontakt?formular=$FP&vysledek=ok#s-$FP") echo "  ok     odeslání formuláře";; *) echo "  CHYBA  odeslání formuláře: $kam"; CHYB=$((CHYB+1));; esac
ocekavej "poptávka uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(stav)) FROM mc_poptavky")" "1/jana@example.cz/0"
case "$(odesli -d p0=Jana -d p1=neni-email -d p3=x -d p4=1)" in *vysledek=pole*) echo "  ok     neplatný e-mail odmítnut";; *) echo "  CHYBA  validace e-mailu"; CHYB=$((CHYB+1));; esac
case "$(odesli -d p0=Jana --data-urlencode p1=jana@example.cz -d p3=x)" in *vysledek=pole*) echo "  ok     chybějící souhlas odmítnut";; *) echo "  CHYBA  povinný souhlas"; CHYB=$((CHYB+1));; esac
odesli -d p0=Robot --data-urlencode p1=r@example.cz -d p3=spam -d p4=1 -d web_adresa=http://spam.example > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FZ" -d "prvek=$FP" -d zpet=/kontakt -d "as_cas=$FC" -d as_podpis=podvrh -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *vysledek=overeni*) echo "  ok     podvržený podpis odmítnut";; *) echo "  CHYBA  podpis formuláře"; CHYB=$((CHYB+1));; esac
case "$(odesli -d zdroj=stranka:999 -d p0=A)" in *formular=*) echo "  CHYBA  neexistující formulář přijat"; CHYB=$((CHYB+1));; *) echo "  ok     neexistující formulář nic neuloží";; esac
ocekavej "robot ani chyby poptávku nepřidaly" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM mc_poptavky")" 1
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM mc_poptavky")
over "poptávky v administraci" 200 "/admin.php?modul=poptavky" "jana@example.cz"
over "detail poptávky" 200 "/admin.php?modul=poptavky&akce=detail&id=$IDP" "Chci kuchyň na míru."
ocekavej "otevřená poptávka je přečtená" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM mc_poptavky")" 1
curl -s -b "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=poptavky&akce=csv"; grep -q 'Chci kuchyň na míru.' "$PRACE/odpoved" && echo "  ok     export poptávek do CSV" || { echo "  CHYBA  CSV poptávek"; CHYB=$((CHYB+1)); }
over "poděkování po odeslání (na místě formuláře)" 200 "/kontakt?formular=$FP&vysledek=ok" "id=\"s-$FP\" class=\"mc-formular-hotovo\" role=\"status\""

echo "== kolekce"
over "kolekce" 200 "/admin.php?modul=kolekce" "Kolekce"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=kolekce&akce=uloz" -d "_csrf=$TOKEN" -d idk=0 --data-urlencode "nazev=Tým" -d detail=1 \
  --data-urlencode "pole[0][popisek]=Funkce" -d "pole[0][typ]=text" --data-urlencode "pole[1][popisek]=Foto" -d "pole[1][typ]=obrazek" --data-urlencode "pole[2][popisek]=Medailonek" -d "pole[2][typ]=html"
IDK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idk FROM mc_kolekce WHERE seo_link = 'tym'")
ocekavej "kolekce založena s poli" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM mc_kolekce WHERE idk = $IDK")" 1
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
over "šablona detailu ve staviteli" 200 "/admin.php?modul=kolekce&akce=stavitel&id=$IDK" 'id="stavitel-data"'
mcp seznam_kolekci '{}' > "$PRACE/odpoved"; grep -q 'kolekce\\": \\"tym' "$PRACE/odpoved" && grep -q 'medailonek' "$PRACE/odpoved" && echo "  ok     MCP: seznam kolekcí s poli" || { echo "  CHYBA  MCP seznam_kolekci"; CHYB=$((CHYB+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Petr Svoboda","data":{"funkce":"Mistr truhlář"},"zobrazit":true}' > /dev/null
rm -f "$PRACE"/web/storage/cache/stranky/*.html
over "MCP: nová položka je ve výpisu" 200 /z-html "Mistr truhlář"

# úprava přímo na webu: odkaz a formulář jen pro přihlášené s právem
over "úprava na místě – odkaz" 200 /novinky/vitejte-v-mirocms "mc-upravit-zde"
over "úprava na místě – formulář" 200 "/novinky/vitejte-v-mirocms?upravit=text" "mc-upravit-text"
over "úprava stránky na místě" 200 "/o-nas?upravit=text" "mc-upravit-text"
curl -s -o "$PRACE/odpoved" "$B/novinky/vitejte-v-mirocms?upravit=text"; grep -q "mc-upravit" "$PRACE/odpoved" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; CHYB=$((CHYB+1)); } || echo "  ok     úprava na místě bez přihlášení není"

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
over "importovaná stránka je rovnou ve stavitelu" 200 /o-zpravodaji '<main id="obsah" class="stavba">'
over "importovaná stránka má nadpis z WordPressu" 200 /o-zpravodaji '<h1>O zpravodaji</h1>'
curl -s -o "$PRACE/odpoved" "$B/o-zpravodaji"; grep -q 'wp-block' "$PRACE/odpoved" && { echo "  CHYBA  třídy WordPressu ve stavbě"; CHYB=$((CHYB+1)); } || echo "  ok     třídy WordPressu bez stylu vynechány"
curl -s -o "$PRACE/odpoved" "$B/novinky/lavka-pres-bystrinu"; grep -qE "podvrh|onclick|kontaktni-formular|posta\.example" "$PRACE/odpoved" && { echo "  CHYBA  importovaná novinka obsahuje skript, zkratku doplňku nebo e-mail komentujícího"; CHYB=$((CHYB+1)); } || echo "  ok     importovaný obsah je vyčištěný"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2026/05/lavka-pres-bystrinu/"); ocekavej "stará adresa WordPressu přesměruje na novinku" "$kod" "301 $B/novinky/lavka-pres-bystrinu"
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/?p=102"); ocekavej "stará adresa /?p=102 přesměruje" "$kod" 301
# druhý import téhož souboru nesmí nic zdvojit
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=vyber" -d "_csrf=$TOKEN" -d soubor=wordpress-ukazka.xml
wp_import
POCTY=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM mc_novinky WHERE seo_link LIKE 'lavka-pres-bystrinu%' OR seo_link LIKE 'slavnosti-syra%' OR seo_link LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM mc_stranky WHERE seo_link LIKE 'o-zpravodaji%'))")
ocekavej "opakovaný import nic nezdvojil (novinky/stránky)" "$POCTY" "4/1"
over "složka importu není přístupná z webu" 403 /storage/import/wordpress-ukazka.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=export" -d "_csrf=$TOKEN"
over "export webu je v seznamu" 200 "/admin.php?modul=prenos" "akce=stahni"
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$PRACE/odpoved" | head -1)
curl -s -b "$JAR" -o "$PRACE/export" "$B/admin.php?modul=prenos&akce=stahni&soubor=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$PRACE/export" obsah.json > "$PRACE/obsah.json" 2>/dev/null || true; else cp "$PRACE/export" "$PRACE/obsah.json"; fi
grep -q '"format":"mirocms-export"' "$PRACE/obsah.json" && grep -q '"novinky"' "$PRACE/obsah.json" && ! grep -qE '"password"|smtp_heslo|tajny_klic|ai_klic' "$PRACE/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export"; CHYB=$((CHYB+1)); }
grep -q '"kolekce_polozky":\[' "$PRACE/obsah.json" && grep -q 'Jana Nováková' "$PRACE/obsah.json" && grep -q '"tridy":\[' "$PRACE/obsah.json" && grep -q '"casti":\[' "$PRACE/obsah.json" && ! grep -q 'Chci kuchyň' "$PRACE/obsah.json" \
  && echo "  ok     export obsahuje stavitel a kolekce, poptávky ne" || { echo "  CHYBA  export stavitele a kolekcí"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/admin.php?modul=prenos&akce=stahni&soubor=$EXPORT"; grep -q "Heslo" "$PRACE/odpoved" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; CHYB=$((CHYB+1)); }

echo "== koš novinek"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM mc_novinky WHERE seo_link = 'vitejte-v-mirocms'")
over "výpis novinek" 200 "/admin.php?modul=novinky" "Smazat označené"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=novinky&akce=smaz" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
over "novinka v koši není na webu" 404 /novinky/vitejte-v-mirocms
over "novinka v koši není ani v náhledu" 404 "/novinky/vitejte-v-mirocms?nahled=1"
over "záložka Koš" 200 "/admin.php?modul=novinky&stav=kos" "Vítejte"
over "novinka v koši nejde upravit" 404 "/admin.php?modul=novinky&akce=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=novinky&akce=obnov" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
ocekavej "obnovená novinka se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM mc_novinky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE mc_novinky SET visible = 1, smazano = NOW() - INTERVAL 31 DAY WHERE idc = $IDC"
over "vstup do administrace vysype starý koš" 200 /admin.php "Přehled"
ocekavej "novinka starší 30 dní v koši je smazaná natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM mc_novinky WHERE idc = $IDC")" "0"

echo "== přesměrování po změně adresy kategorie a stránky"
over "formulář kategorie" 200 "/admin.php?modul=kategorie" "Kategorie"
TOKEN=$(csrf)
IDT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM mc_kategorie WHERE seo_link = 'aktuality'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=kategorie&akce=uloz" -d "_csrf=$TOKEN" -d "idt=$IDT" -d nazev=Aktuality -d seo_link=aktuality-firmy -d hodnost=100
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/novinky/kategorie/aktuality"); ocekavej "stará adresa kategorie přesměruje na novou" "$kod" "301 $B/novinky/kategorie/aktuality-firmy"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM mc_stranky WHERE seo_link = 'kontakt'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=uloz" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/kontakt"); ocekavej "stará adresa stránky přesměruje na novou" "$kod" "301 $B/kontakty"

if [ -s "$PRACE/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$PRACE/web/storage/log/chyby.log"; CHYB=$((CHYB+1)); fi
echo; [ "$CHYB" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $CHYB"; exit 1; }
