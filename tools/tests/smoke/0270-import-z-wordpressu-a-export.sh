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
check "3.6 import z WordPressu – náhled nabízí vše skryté (3.11 UX310-16: preselected on a site with published pages)" 200 "/admin.php?module=transfer&action=preview&file=wordpress-sample.xml" 'name="skryte" value="1" checked>'
check "import z WordPressu – náhled hlásí SEO data pluginů" 200 "/admin.php?module=transfer&action=preview&file=wordpress-sample.xml" "Rank Math"
contains -q 'data-import-jazyk=' "$WORK/response" && { echo "  CHYBA  3.12 UX310-16: a Czech export into a Czech site warns about its language"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.12 UX310-16: a Czech export into a Czech site says nothing about its language"
wp_import
grep -q "Import obsahu je hotový" "$WORK/response" && echo "  ok     import z WordPressu doběhl" || { echo "  CHYBA  import z WordPressu nedoběhl"; ERRORS=$((ERRORS+1)); }
contains -q 'class="import-dalsi-kroky"' "$WORK/response" && echo "  ok     3.12 UX310-17: the import result ends in next steps" || { echo "  CHYBA  3.12 UX310-17: no next steps on the import result"; ERRORS=$((ERRORS+1)); }
check "importovaná novinka" 200 /novinky/lavka-pres-bystrinu "Lávka přes Bystřinu"
check "importovaná novinka – galerie a video" 200 /novinky/lavka-pres-bystrinu 'class="galerie"'
check "importovaná stránka" 200 /o-zpravodaji "Kontakt"
check "importovaná stránka je rovnou v builderu" 200 /o-zpravodaji '<main id="obsah" class="stavba" tabindex="-1">'
check "importovaná stránka má nadpis z WordPressu" 200 /o-zpravodaji '<h1>O zpravodaji</h1>'
# SEO plugin data (Core\WpSeo): SmartCrawl title with the site name filled in, Yoast default pattern skipped, Rank Math noindex from a serialized array
expect "SEO ze SmartCrawlu: titulek s názvem webu, popis, bez noindex" "$(sq "SELECT CONCAT(seo_titulek LIKE 'Lávka přes Bystřinu znovu otevřena – %', '|', seo_popis, '|', noindex) FROM ka_novinky WHERE seo_link = 'lavka-pres-bystrinu'")" "1|Po roce oprav se lávka v Horní Lhotě otevřela chodcům i cyklistům.|0"
expect "SEO z Yoastu: výchozí vzor titulku se neimportuje, popis a noindex ano" "$(sq "SELECT CONCAT(seo_titulek, '|', seo_popis, '|', noindex) FROM ka_novinky WHERE seo_link = 'slavnosti-syra'")" "|Rekordní slavnosti sýra: tři tisíce lidí a vítězná farma z Dolní Lhoty.|1"
expect "SEO z Rank Math: titulek s proměnnými, noindex ze serializovaného pole" "$(sq "SELECT CONCAT(seo_titulek LIKE 'Fotografie čtenářů: lávka přes Bystřinu – %', '|', noindex) FROM ka_novinky WHERE seo_link = 'lavka-pres-bystrinu-2'")" "1|1"
expect "3.22 (C13): the Rank Math canonical of a duplicate becomes a path on the new site (the old address redirects); the posts without one keep none" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, '=', canonical_url) ORDER BY seo_link) FROM ka_novinky WHERE seo_link IN ('lavka-pres-bystrinu', 'lavka-pres-bystrinu-2', 'slavnosti-syra')")" "lavka-pres-bystrinu=,lavka-pres-bystrinu-2=/2026/05/lavka-pres-bystrinu,slavnosti-syra="
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
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*-[0-9a-f]*\.[a-z]*' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/export" "$B/admin.php?module=transfer&action=download&file=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$WORK/export" obsah.json > "$WORK/obsah.json" 2>/dev/null || true; else cp "$WORK/export" "$WORK/obsah.json"; fi
grep -q '"format":"kaleta-export"' "$WORK/obsah.json" && grep -q '"novinky"' "$WORK/obsah.json" && ! grep -qE '"password"|smtp_heslo|tajny_klic|ai_klic' "$WORK/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export"; ERRORS=$((ERRORS+1)); }
grep -q '"kolekce_polozky":\[' "$WORK/obsah.json" && grep -q 'Jana Nováková' "$WORK/obsah.json" && grep -q '"tridy":\[' "$WORK/obsah.json" && grep -q '"casti":\[' "$WORK/obsah.json" && ! grep -q 'Chci kuchyň' "$WORK/obsah.json" \
  && grep -q '"adresa":"akce-okno"' "$WORK/obsah.json" && ! grep -q '"zobrazeni":' "$WORK/obsah.json" \
  && echo "  ok     export obsahuje builder, kolekce a pop-up okna, poptávky ani počitadla ne" || { echo "  CHYBA  export builderu a kolekcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/admin.php?module=transfer&action=download&file=$EXPORT"; grep -q "Heslo" "$WORK/response" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; ERRORS=$((ERRORS+1)); }

