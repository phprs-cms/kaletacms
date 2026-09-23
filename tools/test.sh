#!/usr/bin/env bash
# MiroCMS - kouřový test: čistá instalace do dočasné kopie a průchod hlavními stránkami.
# Spouští se lokálně i v GitHub Actions. Databázi bere z proměnných prostředí:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (mirocms_test) DB_USER (root) DB_PASS (prázdné) PORT (8099) STRIPE_PORT (8098, náhražka API Stripe)
# Databáze DB_NAME se při testu SMAŽE a vytvoří znovu.
set -euo pipefail

KOREN="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-mirocms_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
PRACE="$(mktemp -d)"; JAR="$PRACE/cookies.txt"; B="http://127.0.0.1:$PORT"; CHYB=0
uklid() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; [ -n "${STRIPE_PID:-}" ] && kill "$STRIPE_PID" 2>/dev/null || true; rm -rf "$PRACE"; }
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

echo "== instalace"
HESLO="Test-$(date +%s)-heslo"
curl -s -o "$PRACE/odpoved" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=rs_ \
  --data-urlencode "nazev_webu=Testovací magazín" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$HESLO" --data-urlencode "password2=$HESLO" -d layout=classic-newspaper
grep -q "Hotovo, magazín běží" "$PRACE/odpoved" || { echo "  CHYBA  instalace selhala"; sed 's/<[^>]*>//g' "$PRACE/odpoved" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     instalace"
# API Stripe se v testu nahrazuje místním serverem (blok „platby přes Stripe“); konstanta se zapisuje hned tady, protože opcache vestavěného serveru drží config.php ještě 2 vteřiny
STRIPE_PORT="${STRIPE_PORT:-8098}"
php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/^<\?php\n/", "<?php\ndefine(\"MIROCMS_STRIPE_URL\", \"http://127.0.0.1:" . $argv[2] . "\");\n", file_get_contents($f), 1));' "$PRACE/web/config.php" "$STRIPE_PORT"
grep -q "MIROCMS_STRIPE_URL" "$PRACE/web/config.php" || { echo "  CHYBA  do config.php se nepodařilo zapsat MIROCMS_STRIPE_URL"; exit 1; }
[ ! -f "$PRACE/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; CHYB=$((CHYB+1)); }

echo "== web"
over "hlavní stránka" 200 / "Testovací magazín"
over "článek" 200 /clanek/vitejte-v-mirocms "Vítejte"
over "rubrika" 200 /rubrika/aktuality
over "hledání" 200 "/hledani?q=MiroCMS"
for u in /rss.xml /feed.json /sitemap.xml /sitemap-news.xml /robots.txt /llms.txt /clanek/vitejte-v-mirocms.md; do over "$u" 200 "$u"; done
over "neexistující stránka" 404 /tohle-neexistuje
over "system/ není přístupný" 403 /system/sql/schema.sql
over "config.php není přístupný" 403 /config.php
# nastavená šablona, která ve složce layout/ není (zrušená vestavěná „default“ před migrací, smazaná vlastní): web se vykreslí výchozí šablonou
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_config SET hodnota='default' WHERE promenna='layout'"
over "chybějící šablona – web běží na výchozí" 200 / "layout/classic-newspaper/style.css"
# komerční sdělení a autor hlavní fotky se musí vypsat ve všech třech šablonách (článek i výpis na titulní straně)
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_clanky SET komercni = 1, komercni_partner = 'Partner', obrazek = 'media/test.jpg', obrazek_autor = 'Fotograf' WHERE seo_link = 'vitejte-v-mirocms'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html # změna přímo v databázi cache stránek nevymaže
for l in minimal classic-newspaper modern-magazine; do
  "${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_config SET hodnota='$l' WHERE promenna='layout'"; over "šablona $l" 200 / "layout/$l/style.css"; over "šablona $l – článek" 200 /clanek/vitejte-v-mirocms
  over "šablona $l – komerční sdělení ve výpisu" 200 / 'class="clanek-komercni"'
  over "šablona $l – komerční sdělení u článku" 200 /clanek/vitejte-v-mirocms 'Komerční sdělení · partner: Partner'
  over "šablona $l – autor hlavní fotky" 200 /clanek/vitejte-v-mirocms 'Foto: Fotograf'
done
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_clanky SET komercni = 0, komercni_partner = '', obrazek = '', obrazek_autor = '' WHERE seo_link = 'vitejte-v-mirocms'"
rm -f "$PRACE"/web/storage/cache/stranky/*.html

echo "== administrace"
over "zapomenuté heslo – formulář" 200 "/admin.php?akce=heslo" "Poslat odkaz"
over "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?akce=heslo&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
over "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//')
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); [ "$kod" = 401 ] && echo "  ok     špatné heslo odmítnuto" || { echo "  CHYBA  špatné heslo: $kod"; CHYB=$((CHYB+1)); }
kod=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$HESLO"); [ "$kod" = 400 ] && echo "  ok     POST bez CSRF odmítnut" || { echo "  CHYBA  CSRF: $kod"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$HESLO"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_config VALUES ('rozsireni','novinky,komentare,ankety,statistika,presmerovani,reklama,newsletter,ctenari,push,asistent,jazyky') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
over "přehled" 200 /admin.php "Přehled"
for m in clanky "clanky&akce=novy" "clanky&akce=kalendar" "clanky&akce=titulni" "clanky&akce=odkazy" intergal topic stitky stranky news comment ankety stat reklama newsletter ctenari vzhled "bloky&schema=1" users presmerovani protokol; do over "modul $m" 200 "/admin.php?modul=$m"; done
over "rozšíření (samostatná položka nabídky)" 200 "/admin.php?modul=rozsireni" "Rozšíření"
over "příjmy (rozcestník)" 200 "/admin.php?modul=prijmy" "Dobrovolná podpora"
over "uživatelé se shrnutím oprávnění" 200 "/admin.php?modul=users" "Smí všechno"
for z in zakladni vzhled seo mereni cookies posta zalohy stav; do over "nastavení/$z" 200 "/admin.php?modul=config&zalozka=$z"; done
over "účet čtenáře" 200 /ctenar "Jsem tu poprvé"
over "odkaz na účet čtenáře v hlavičce webu" 200 / 'class="mc-ucet"'
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_clanky SET pristup = 1; INSERT INTO rs_config VALUES ('zamek_odstavcu','0') ON DUPLICATE KEY UPDATE hodnota='0'"
curl -s -o "$PRACE/odpoved" "$B/clanek/vitejte-v-mirocms"; grep -q "mc-zamek" "$PRACE/odpoved" && echo "  ok     zamčený článek ukazuje výzvu" || { echo "  CHYBA  zamčený článek je vidět bez přihlášení"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/clanek/vitejte-v-mirocms.md"; grep -q "admin.php" "$PRACE/odpoved" && { echo "  CHYBA  zamčený text uniká přes .md"; CHYB=$((CHYB+1)); } || echo "  ok     zamčený text neuniká přes .md"
kod=$(curl -s -H "Cookie: mirocms_ctenar=1.9999999999.podvrh" "$B/clanek/vitejte-v-mirocms"); grep -q "mc-zamek" <<< "$kod" && echo "  ok     podvržená cookie čtenáře zámek neodemkne" || { echo "  CHYBA  podvržená cookie čtenáře odemkla článek"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_clanky SET pristup = 0"
for u in /sw.js /push.json /manifest.webmanifest; do over "$u" 200 "$u"; done
kod=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"endpoint":"https://utocnik.example/x"}' "$B/push/odber"); [ "$kod" = 400 ] && echo "  ok     Web Push odmítne cizí adresu odběru" || { echo "  CHYBA  Web Push přijal cizí adresu: $kod"; CHYB=$((CHYB+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_config VALUES ('jazyky_dalsi','en') ON DUPLICATE KEY UPDATE hodnota='en'"
over "anglická verze webu" 200 /en/ 'lang="en"'
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/clanek/vitejte-v-mirocms"); [ "$kod" = 301 ] && echo "  ok     článek jiné jazykové verze přesměruje" || { echo "  CHYBA  jazykové přesměrování: $kod"; CHYB=$((CHYB+1)); }
over "neznámý modul" 403 "/admin.php?modul=neexistuje"

# neúspěšná validace článku musí vrátit formulář s hláškou, ne chybu 500 (dřív padala na chybějícím klíči)
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" "$B/admin.php?modul=clanky&akce=novy"
TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//')
kod=$(curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -w '%{http_code}' -X POST "$B/admin.php?modul=clanky&akce=uloz" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$kod" = 200 ] && grep -q 'name="titulek"' "$PRACE/odpoved" && echo "  ok     chyba ve formuláři článku vrátí formulář" || { echo "  CHYBA  validace článku: kód $kod"; CHYB=$((CHYB+1)); }

# oprávnění podle rubriky: redaktor omezený na jinou rubriku článek z první rubriky nevidí ani neotevře
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_topic (nazev, seo_link, popis) VALUES ('Jen pro test', 'jen-pro-test', '')"
RUB=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM rs_topic WHERE seo_link = 'jen-pro-test'")
CLANEK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM rs_clanky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=users&akce=uloz" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Omezeny -d user=omezeny --data-urlencode "password=$HESLO" -d admin=1 -d "rubriky[]=$RUB"
JAR2="$PRACE/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=omezeny --data-urlencode "password=$HESLO"
kod=$(curl -s -b "$JAR2" -o "$PRACE/odpoved" -w '%{http_code}' "$B/admin.php?modul=clanky")
[ "$kod" = 200 ] && ! grep -q "akce=edit&amp;id=$CLANEK\"" "$PRACE/odpoved" && echo "  ok     omezený redaktor nevidí články cizí rubriky" || { echo "  CHYBA  oprávnění podle rubriky – výpis: kód $kod"; CHYB=$((CHYB+1)); }
kod=$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?modul=clanky&akce=edit&id=$CLANEK")
[ "$kod" = 404 ] && echo "  ok     omezený redaktor cizí článek neotevře" || { echo "  CHYBA  oprávnění podle rubriky – úprava: kód $kod"; CHYB=$((CHYB+1)); }
over "vizuální editor bloků" 200 "/?upravit=1" "mc-nastaveni"
# úprava článku přímo na webu: odkaz a formulář jen pro přihlášenou redakci
over "úprava na místě – odkaz pro redakci" 200 /clanek/vitejte-v-mirocms "mc-upravit-zde"
over "úprava na místě – formulář" 200 "/clanek/vitejte-v-mirocms?upravit=text" "mc-upravit-text"
curl -s -o "$PRACE/odpoved" "$B/clanek/vitejte-v-mirocms?upravit=text"; grep -q "mc-upravit" "$PRACE/odpoved" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; CHYB=$((CHYB+1)); } || echo "  ok     úprava na místě jen pro přihlášené"
kod=$(curl -s -o "$PRACE/odpoved" -w '%{http_code}' "$B/?upravit=1"); grep -q "mc-nastaveni" "$PRACE/odpoved" && { echo "  CHYBA  vizuální editor je vidět bez přihlášení"; CHYB=$((CHYB+1)); } || echo "  ok     vizuální editor jen pro přihlášené"

echo "== import z WordPressu a export"
over "import a export" 200 "/admin.php?modul=prenos" "WordPress"
TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//')
wp_davka() { curl -s -b "$JAR" -c "$JAR" -o "$PRACE/odpoved" -X POST "$B/admin.php?modul=prenos&akce=prubeh&soubor=wordpress-ukazka.xml" -d "_csrf=$TOKEN"; }
wp_import() { # náhled (čtení souboru) → volby → import; ukázkový soubor se vejde do jedné dávky
  wp_davka
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=spust" -d "_csrf=$TOKEN" -d soubor=wordpress-ukazka.xml -d koncepty=1 -d stranky=1 -d komentare=1 -d presmerovani=1 -d rubrika=0
  wp_davka
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=nahraj" -F "_csrf=$TOKEN" -F "soubor=@$KOREN/tools/fixtures/wordpress-ukazka.xml"
wp_davka
over "import z WordPressu – náhled upozorní na nepřevoditelný typ" 200 "/admin.php?modul=prenos&akce=nahled&soubor=wordpress-ukazka.xml" "nav_menu_item"
wp_import
grep -q "Import obsahu je hotový" "$PRACE/odpoved" && echo "  ok     import z WordPressu doběhl" || { echo "  CHYBA  import z WordPressu nedoběhl"; CHYB=$((CHYB+1)); }
over "importovaný článek" 200 /clanek/lavka-pres-bystrinu "Lávka přes Bystřinu"
over "importovaný článek – galerie a video" 200 /clanek/lavka-pres-bystrinu 'class="galerie"'
over "importovaná stránka" 200 /o-zpravodaji "Kontakt"
curl -s -o "$PRACE/odpoved" "$B/clanek/lavka-pres-bystrinu"; grep -qE "podvrh|onclick|kontaktni-formular|posta\.example" "$PRACE/odpoved" && { echo "  CHYBA  importovaný článek obsahuje skript, zkratku doplňku nebo e-mail komentujícího"; CHYB=$((CHYB+1)); } || echo "  ok     importovaný obsah je vyčištěný"
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/2026/05/lavka-pres-bystrinu/"); [ "$kod" = 301 ] && echo "  ok     stará adresa WordPressu přesměruje" || { echo "  CHYBA  přesměrování staré adresy: $kod"; CHYB=$((CHYB+1)); }
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/?p=102"); [ "$kod" = 301 ] && echo "  ok     stará adresa /?p=102 přesměruje" || { echo "  CHYBA  přesměrování /?p=102: $kod"; CHYB=$((CHYB+1)); }
# druhý import téhož souboru nesmí nic zdvojit
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=vyber" -d "_csrf=$TOKEN" -d soubor=wordpress-ukazka.xml
wp_import
POCTY=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM rs_clanky WHERE seo_link LIKE 'lavka-pres-bystrinu%' OR seo_link LIKE 'slavnosti-syra%' OR seo_link LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM rs_komentare k JOIN rs_clanky c ON c.idc = k.clanek WHERE c.seo_link = 'lavka-pres-bystrinu' AND k.od_mail = '' AND k.od_ip = ''), '/', (SELECT COUNT(*) FROM rs_stranky WHERE seo_link LIKE 'o-zpravodaji%'))")
[ "$POCTY" = "4/2/1" ] && echo "  ok     opakovaný import nic nezdvojil" || { echo "  CHYBA  opakovaný import: články/komentáře/stránky = $POCTY, čekal jsem 4/2/1"; CHYB=$((CHYB+1)); }
over "složka importu není přístupná z webu" 403 /storage/import/wordpress-ukazka.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=prenos&akce=export" -d "_csrf=$TOKEN"
over "export webu je v seznamu" 200 "/admin.php?modul=prenos" "akce=stahni"
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$PRACE/odpoved" | head -1)
curl -s -b "$JAR" -o "$PRACE/export" "$B/admin.php?modul=prenos&akce=stahni&soubor=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$PRACE/export" obsah.json > "$PRACE/obsah.json" 2>/dev/null || true; else cp "$PRACE/export" "$PRACE/obsah.json"; fi
grep -q '"format":"mirocms-export"' "$PRACE/obsah.json" && ! grep -qE '"password"|od_mail|od_ip|smtp_heslo|tajny_klic|push_klic_soukromy' "$PRACE/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export webu chybí nebo obsahuje tajné údaje"; CHYB=$((CHYB+1)); }
curl -s -o "$PRACE/odpoved" "$B/admin.php?modul=prenos&akce=stahni&soubor=$EXPORT"; grep -q "Heslo" "$PRACE/odpoved" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; CHYB=$((CHYB+1)); }

echo "== platby přes Stripe (bez sítě: webhook podepsaný tady, API nahrazuje tools/fixtures/stripe-server.php)"
WHSEC="whsec_testovaciTajemstvi$(date +%s)"; CENA="price_1TestMesicni0001"
php -S "127.0.0.1:$STRIPE_PORT" "$KOREN/tools/fixtures/stripe-server.php" > "$PRACE/stripe.log" 2>&1 & STRIPE_PID=$!
stripe_posli() { # stripe_posli <tělo> [tajemství] [čas] -> kód odpovědi; podpis je HMAC-SHA256 řetězce "čas.tělo"
  local cas="${3:-$(date +%s)}" podpis
  podpis=$(printf '%s' "$cas.$1" | openssl dgst -sha256 -hmac "${2:-$WHSEC}" | sed 's/^.*= *//')
  curl -s -o "$PRACE/odpoved" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "Stripe-Signature: t=$cas,v1=$podpis" --data-binary "$1" "$B/platba/stripe"
}
stripe_stav() { "${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM rs_platby), COALESCE(predplatne_do > CURDATE() + INTERVAL 20 DAY, 'NULL'), COALESCE(stripe_zakaznik, '-'), COALESCE(stripe_predplatne, '-'), predplatne_stav) FROM rs_ctenari WHERE email = 'platici@example.cz'"; }
ocekavej() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; CHYB=$((CHYB+1)); }; }
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_ctenari (email, jmeno, heslo, token, potvrzen, vytvoren) VALUES ('platici@example.cz', 'Platící', 'x-neplatny-otisk', REPEAT('a', 32), 1, NOW())"
IDCT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idct FROM rs_ctenari WHERE email = 'platici@example.cz'")
KONEC=$(( $(date +%s) + 30 * 86400 ))
SEZENI='{"id":"evt_test_sezeni1","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_test_1","object":"checkout.session","mode":"subscription","client_reference_id":"'$IDCT'","customer":"cus_TestZakaznik1","subscription":"sub_TestPredplatne1","payment_status":"paid","metadata":{"idct":"'$IDCT'"}}}}'
FAKTURA='{"id":"evt_test_faktura1","object":"event","type":"invoice.paid","data":{"object":{"id":"in_test_1","object":"invoice","customer":"cus_TestZakaznik1","amount_paid":9900,"currency":"czk","parent":{"type":"subscription_details","subscription_details":{"subscription":"sub_TestPredplatne1","metadata":{"idct":"'$IDCT'"}}},"lines":{"data":[{"id":"il_1","period":{"start":'$(date +%s)',"end":'$KONEC'}}]}}}}'
ocekavej "webhook bez nastaveného tajemství odmítá vše" "$(stripe_posli "$FAKTURA")/$(stripe_stav)" "400/0/NULL/-/-/"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_config VALUES ('stripe_webhook_tajemstvi','$WHSEC'), ('stripe_cena_mesic','$CENA'), ('stripe_cena_mesic_text','99 CZK za mesic') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
ocekavej "webhook: špatný podpis → 400 a nic se nezapíše" "$(stripe_posli "$FAKTURA" whsec_jineTajemstvi12345)/$(stripe_stav)" "400/0/NULL/-/-/"
ocekavej "webhook: starý čas → 400 a nic se nezapíše" "$(stripe_posli "$FAKTURA" "$WHSEC" $(( $(date +%s) - 900 )))/$(stripe_stav)" "400/0/NULL/-/-/"
kod=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data-binary "$FAKTURA" "$B/platba/stripe"); ocekavej "webhook: bez hlavičky s podpisem → 400" "$kod/$(stripe_stav)" "400/0/NULL/-/-/"
kod=$(curl -s -o /dev/null -w '%{http_code}' "$B/platba/stripe"); ocekavej "webhook: jen POST" "$kod" "405"
ocekavej "webhook: faktura dorazila dřív než dokončená platba – čtenář se najde podle metadat" "$(stripe_posli "$FAKTURA")/$(stripe_stav)" "200/1/1/cus_TestZakaznik1/-/"
ocekavej "webhook: checkout.session.completed" "$(stripe_posli "$SEZENI")/$(stripe_stav)" "200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/aktivni"
ocekavej "webhook: stejná událost podruhé nic nezdvojí" "$(stripe_posli "$FAKTURA")/$(stripe_posli "$SEZENI")/$(stripe_stav)" "200/200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/aktivni"
CIZI='{"id":"evt_test_cizi1","object":"event","type":"invoice.paid","data":{"object":{"id":"in_test_cizi","object":"invoice","customer":"cus_TestZakaznik1","amount_paid":50000,"currency":"czk","subscription":"sub_JinyProdej1","lines":{"data":[{"period":{"end":'$(( KONEC + 300 * 86400 ))'}}]}}}}'
ocekavej "webhook: faktura jiného prodeje téhož zákazníka (bez čísla čtenáře) přístup neprodlouží" "$(stripe_posli "$CIZI")/$(stripe_stav)/$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT predplatne_do > CURDATE() + INTERVAL 200 DAY FROM rs_ctenari WHERE idct = $IDCT")" "200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/aktivni/0"
ocekavej "webhook: neznámá událost → 200 a nic" "$(stripe_posli '{"id":"evt_test_jina1","type":"customer.created","data":{"object":{"id":"cus_X"}}}')/$(stripe_stav)" "200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/aktivni"
ocekavej "webhook: zrušení ke konci období → stav „konci“, datum zůstává" "$(stripe_posli '{"id":"evt_test_zmena1","type":"customer.subscription.updated","data":{"object":{"id":"sub_TestPredplatne1","customer":"cus_TestZakaznik1","status":"active","cancel_at_period_end":true}}}')/$(stripe_stav)" "200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/konci"
ocekavej "webhook: konec předplatného → „zruseno“, zaplacené období se nezkracuje" "$(stripe_posli '{"id":"evt_test_konec1","type":"customer.subscription.deleted","data":{"object":{"id":"sub_TestPredplatne1","customer":"cus_TestZakaznik1","status":"canceled"}}}')/$(stripe_stav)" "200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/zruseno"
ocekavej "webhook: opožděná starší událost zrušené předplatné neoživí" "$(stripe_posli '{"id":"evt_test_zmena0","type":"customer.subscription.updated","data":{"object":{"id":"sub_TestPredplatne1","customer":"cus_TestZakaznik1","status":"active"}}}')/$(stripe_stav)" "200/1/1/cus_TestZakaznik1/sub_TestPredplatne1/zruseno"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_config VALUES ('udrzba','1') ON DUPLICATE KEY UPDATE hodnota='1'"
ocekavej "webhook běží i při údržbě webu" "$(stripe_posli '{"id":"evt_test_jina2","type":"customer.created","data":{"object":{"id":"cus_X"}}}')" "200"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_config SET hodnota='0' WHERE promenna='udrzba'"
# odchod na platební stránku: přihlášený čtenář (cookie i podpis formuláře spočítané stejně jako ve Front\Ctenari)
TAJNY=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM rs_config WHERE promenna = 'tajny_klic'")
hmac() { printf '%s' "$1" | openssl dgst -sha256 -hmac "$TAJNY" | sed 's/^.*= *//'; }
PLATNOST=$(( $(date +%s) + 3600 )); COOKIE="mirocms_ctenar=$IDCT.$PLATNOST.$(hmac "ctenar|$IDCT.$PLATNOST.x-neplatny-otisk")"; PODPIS=$(hmac "ctenar|formular.$IDCT")
odchod() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H "Cookie: $COOKIE" -X POST "$B/ctenar/predplatne" -d "plan=$1" -d "podpis=${2:-$PODPIS}"; }
ocekavej "platba: bez tajného klíče se tlačítka nenabízejí" "$(curl -s -H "Cookie: $COOKIE" "$B/ctenar" | grep -c 'name="plan"')" "0"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO rs_config VALUES ('stripe_tajny_klic','sk_test_NahrazkaKlice000000000000') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
curl -s -H "Cookie: $COOKIE" -o "$PRACE/odpoved" "$B/ctenar"
grep -q 'name="plan" value="mesic"' "$PRACE/odpoved" && grep -q '<small>99 CZK za mesic</small>' "$PRACE/odpoved" && ! grep -q 'value="rok"' "$PRACE/odpoved" && echo "  ok     platba: účet nabízí jen nastavené období i s popisem ceny" || { echo "  CHYBA  platba: tlačítka předplatného v účtu čtenáře"; CHYB=$((CHYB+1)); }
VYSLEDEK=$(odchod mesic)
case "$VYSLEDEK" in "303 https://checkout.stripe.com/c/pay/cs_test_nahrazka?ctenar=$IDCT&cena=$CENA&zakaznik=cus_TestZakaznik1&jazyk=cs&navrat="*"stav%3Dzaplaceno") echo "  ok     platba: přesměrování na Checkout se správnou cenou, čtenářem a zákazníkem";; *) echo "  CHYBA  platba: odchod na Checkout: $VYSLEDEK"; CHYB=$((CHYB+1));; esac
ocekavej "platba: období bez nastavené ceny nikam nevede" "$(odchod rok)" "303 $B/ctenar"
ocekavej "platba: bez podpisu formuláře nic" "$(odchod mesic podvrh)" "303 $B/ctenar"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/ctenar/predplatne" -d plan=mesic -d "podpis=$PODPIS"); ocekavej "platba: nepřihlášený nic" "$kod" "303 $B/ctenar"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_ctenari SET predplatne_stav = 'aktivni' WHERE idct = $IDCT"
ocekavej "platba: kdo už platí, druhé předplatné nezaloží" "$(odchod mesic)" "303 $B/ctenar"
ocekavej "správa předplatného: přesměrování do portálu Stripe" "$(odchod sprava)" "303 https://billing.stripe.com/p/session/test_nahrazka?zakaznik=cus_TestZakaznik1"
curl -s -H "Cookie: $COOKIE" -o "$PRACE/odpoved" "$B/ctenar?stav=sprava"
ocekavej "správa předplatného: po návratu se stav načte ze Stripe" "$(stripe_stav)" "1/1/cus_TestZakaznik1/sub_TestPredplatne1/konci"
over "čtenáři: stav předplatného ze Stripe ve výpisu" 200 "/admin.php?modul=ctenari" "Stripe: neobnoví se"
over "příjmy: součet plateb za 30 dní" 200 "/admin.php?modul=prijmy" "99,00 CZK"
over "nastavení: záložka Čtenáři a platby s adresou webhooku" 200 "/admin.php?modul=config&zalozka=ctenari" "/platba/stripe"
grep -qE "sk_test_Nahrazka|$WHSEC" "$PRACE/odpoved" && { echo "  CHYBA  nastavení vypisuje tajný klíč nebo tajemství webhooku"; CHYB=$((CHYB+1)); } || echo "  ok     nastavení tajné hodnoty nevypisuje (jen konec)"
TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o "$PRACE/gdpr.json" -X POST "$B/admin.php?modul=config&akce=osobni_udaje" -d "_csrf=$TOKEN" -d gdpr_email=platici@example.cz -d gdpr_co=export
grep -q '"castka": 99' "$PRACE/gdpr.json" && grep -q '"mena": "CZK"' "$PRACE/gdpr.json" && echo "  ok     GDPR: výpis obsahuje platby" || { echo "  CHYBA  GDPR: ve výpisu chybí platby"; CHYB=$((CHYB+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=config&akce=osobni_udaje" -d "_csrf=$TOKEN" -d gdpr_email=platici@example.cz -d gdpr_co=smazat
ocekavej "GDPR: výmaz smaže čtenáře, platba zůstane anonymní" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM rs_ctenari WHERE email = 'platici@example.cz'), '/', (SELECT COUNT(*) FROM rs_platby WHERE idct IS NULL AND castka = 9900))")" "0/1"
kill "$STRIPE_PID" 2>/dev/null || true; STRIPE_PID=

echo "== koš článků"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM rs_clanky WHERE seo_link = 'vitejte-v-mirocms'")
over "výpis článků" 200 "/admin.php?modul=clanky" "Smazat označené"
TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=clanky&akce=smaz" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
over "článek v koši není na webu" 404 /clanek/vitejte-v-mirocms
over "článek v koši není ani v náhledu" 404 "/clanek/vitejte-v-mirocms?nahled=1"
over "záložka Koš" 200 "/admin.php?modul=clanky&stav=kos" "Vítejte"
over "článek v koši nejde upravit" 404 "/admin.php?modul=clanky&akce=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=clanky&akce=obnov" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
ocekavej "obnovený článek se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM rs_clanky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_clanky SET visible = 1, smazano = NOW() - INTERVAL 31 DAY WHERE idc = $IDC"
over "vstup do administrace vysype starý koš" 200 /admin.php "Přehled"
ocekavej "článek starší 30 dní v koši je smazaný natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM rs_clanky WHERE idc = $IDC")" "0"

echo "== přesměrování po změně adresy rubriky a stránky"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE rs_config SET hodnota = CONCAT(hodnota, ',presmerovani') WHERE promenna = 'rozsireni' AND FIND_IN_SET('presmerovani', hodnota) = 0"
over "formulář rubriky" 200 "/admin.php?modul=topic" "Rubriky"
TOKEN=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PRACE/odpoved" | head -1 | sed 's/.*value="//;s/"//')
IDT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM rs_topic WHERE seo_link = 'aktuality'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=topic&akce=uloz" -d "_csrf=$TOKEN" -d "idt=$IDT" -d nazev=Aktuality -d seo_link=zpravy-dne -d zobrazit=1 -d hodnost=100
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/rubrika/aktuality"); ocekavej "stará adresa rubriky přesměruje na novou" "$kod" "301 $B/rubrika/zpravy-dne"
over "nová adresa rubriky" 200 /rubrika/zpravy-dne
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "INSERT INTO rs_stranky (seo_link, titulek, text) VALUES ('o-nas', 'O nás', '<p>Redakce.</p>'); SELECT LAST_INSERT_ID()")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?modul=stranky&akce=uloz" -d "_csrf=$TOKEN" -d "ids=$IDS" --data-urlencode "titulek=O nás" -d seo_link=redakce -d zobrazit=1 -d "text=<p>Redakce.</p>"
kod=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/o-nas"); ocekavej "stará adresa stránky přesměruje na novou" "$kod" "301 $B/redakce"

if [ -s "$PRACE/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$PRACE/web/storage/log/chyby.log"; CHYB=$((CHYB+1)); fi
echo; [ "$CHYB" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $CHYB"; exit 1; }
