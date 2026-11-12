echo "== builder stránek"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
check "builder se otevře a převede textovou stránku" 200 "/admin.php?module=pages&action=builder&id=$IDS" 'id="stavitel-data"'
# (grep: contains() is defined further down; the header part's builder below shows the same string is there for a header)
grep -q 'id="stavitel-data"' "$WORK/response" && ! grep -q '"pri_rolovani":{"typ"' "$WORK/response" && echo "  ok     3.6 UXA-12: a page section does not offer the header-only options" || { echo "  CHYBA  3.6 UXA-12: a page section offers the header-only options"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf)
page_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDS" -d "_csrf=$TOKEN" "${@:2}"; }
BUILD='{"v":1,"deti":[{"id":"sek1","typ":"sekce","deti":[{"id":"nad1","typ":"nadpis","znacka":"h1","obsah":{"text":"Builder test"},"styl":{"zaklad":{"barva":"primarni"},"mobil":{"velikost_pisma":"2"}},"tridy":["karta"]},{"id":"faq1","typ":"faq","obsah":{"polozky":[{"otazka":"Kolik to stojí?","odpoved":"<p>Záleží na rozsahu.</p>"}]}},{"id":"txt1","typ":"text","obsah":{"html":"<h2>Jak to funguje</h2><p>Krok za krokem.</p><h2>Jak to funguje</h2><h3 id=\"vlastni\">Vlastní</h3>"}},{"id":"zly1","typ":"skript"}]}]}'
code=$(page_action build_save --data-urlencode "stavba=$BUILD")
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && grep -q 'Neznámý typ prvku' "$WORK/response" && echo "  ok     uložení konceptu vrátí vyčištěnou stavbu a chyby" || { echo "  CHYBA  stavba_uloz: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný JSON stavby odmítnut" "$(page_action build_save -d 'stavba={nesmysl')" 400
expect "uložení z cizí verze odmítnuto (souběžná úprava)" "$(page_action build_save -d verze=0000000000000000 --data-urlencode "stavba=$BUILD")" 409
grep -q '"konflikt":true' "$WORK/response" && grep -q 'Builder test' "$WORK/response" && echo "  ok     konflikt vrátí novější verzi ze serveru" || { echo "  CHYBA  odpověď konfliktu"; ERRORS=$((ERRORS+1)); }
expect "publikování z cizí verze odmítnuto" "$(page_action build_publish -d verze=0000000000000000)" 409
expect "přepsání cizí verze na přání" "$(page_action build_save -d verze=0000000000000000 -d prepsat=1 --data-urlencode "stavba=$BUILD")" 200
expect "builder bez CSRF odmítnut" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_save&id=$IDS" --data-urlencode "stavba=$BUILD")" 400
expect "knihovna sekcí jen přes POST" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_section&id=$IDS&key=faq")" 404
code=$(page_action "build_section&key=vyhody"); [ "$code" = 200 ] && grep -q '"karta"' "$WORK/response" && echo "  ok     sekce z knihovny založí své třídy" || { echo "  CHYBA  stavba_sekce: kód $code"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_class -d nazev=karta --data-urlencode 'styl={"zaklad":{"pozadi":"plocha","odsazeni_y":"l"}}' --data-urlencode 'css=letter-spacing: 0.01em; background: url(x)')
[ "$code" = 200 ] && grep -q 'Nepovolená deklarace' "$WORK/response" && echo "  ok     třída uložena, nebezpečné CSS zahozeno" || { echo "  CHYBA  stavba_trida: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný název třídy odmítnut" "$(page_action build_class -d 'nazev=Karta Velka')" 400
expect "a change of an existing class in the builder goes to the draft look" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%\"karta\"%' FROM ka_nastaveni WHERE promenna = 'look_draft'")" "1"
publish_look
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     koncept není před publikováním na webu" || { echo "  CHYBA  koncept je na webu dřív, než se publikuje"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu pro editor" 200 "/o-nas?build=koncept&editor=1" 'data-ka-id="nad1"'
check "náhled konceptu se neindexuje" 200 "/o-nas?build=koncept" 'noindex'
curl -s -o "$WORK/response" "$B/o-nas?build=koncept&editor=1"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     náhled konceptu nevidí návštěvník" || { echo "  CHYBA  koncept vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_share -d dni=3); SHARED_LINK=$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' -- "$WORK/response")
curl -s -o "$WORK/response" "$SHARED_LINK"
[ "$code" = 200 ] && [[ "$SHARED_LINK" == "$B/o-nas?build=koncept&preview_key="* ]] && grep -q "Builder test" "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && echo "  ok     sdílený odkaz ukáže koncept bez přihlášení a bez značek editoru" || { echo "  CHYBA  stavba_sdilet: kód $code, odkaz $SHARED_LINK"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas?stavba=koncept&nahled_klic=${SHARED_LINK##*preview_key=}"; grep -q "Builder test" "$WORK/response" && echo "  ok     an old shared preview link (stavba=, nahled_klic=) still opens the draft" || { echo "  CHYBA  old preview link"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_publish); expect "publikování stavby" "$code" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"
grep -q '<h1 id="s-nad1" class="karta">Builder test</h1>' "$WORK/response" && echo "  ok     publikovaná stavba na webu, jedna značka na prvek" || { echo "  CHYBA  stavba na webu"; ERRORS=$((ERRORS+1)); }
grep -q '<h2 id="jak-to-funguje">' "$WORK/response" && grep -q '<h2 id="jak-to-funguje-2">' "$WORK/response" && grep -q '<h3 id="vlastni">' "$WORK/response" && echo "  ok     mezititulky textu mají kotvy (jedinečné, vlastní id zůstane)" || { echo "  CHYBA  kotvy mezititulků v textu"; ERRORS=$((ERRORS+1)); }
grep -q 'data-ka-id' "$WORK/response" && { echo "  CHYBA  značky editoru na veřejném webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     bez značek editoru na veřejném webu"
grep -q '@layer prvky' "$WORK/response" && grep -q '#s-nad1 { color: var(--ka-barva-primarni); }' "$WORK/response" && grep -q '.karta { background-color: var(--ka-barva-plocha)' "$WORK/response" && echo "  ok     CSS prvků a tříd ve vrstvách" || { echo "  CHYBA  CSS stavby"; ERRORS=$((ERRORS+1)); }
grep -q '"FAQPage"' "$WORK/response" && echo "  ok     otázky a odpovědi jako strukturovaná data" || { echo "  CHYBA  FAQPage chybí"; ERRORS=$((ERRORS+1)); }
check "hledání najde obsah stavby" 200 "/hledani?q=Builder+test" 'Nalezeno: 1'
page_action build_save --data-urlencode "stavba=${BUILD/Builder test/Druhá verze}" > /dev/null; page_action build_publish > /dev/null
expect "předchozí publikovaná verze je v historii" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDS AND cast IS NULL AND stavba LIKE '%Builder test%'")" 1
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDS AND cast IS NULL AND stavba LIKE '%Builder test%'")
page_action build_restore -d "idr=$IDR" > /dev/null; grep -q 'Builder test' "$WORK/response" && echo "  ok     obnovení verze do konceptu" || { echo "  CHYBA  stavba_obnov"; ERRORS=$((ERRORS+1)); }
page_action build_discard > /dev/null; grep -q 'Druhá verze' "$WORK/response" && echo "  ok     zahození změn vrátí publikovanou stavbu" || { echo "  CHYBA  stavba_zahod"; ERRORS=$((ERRORS+1)); }
# 3.25 (U17, C21): draft history – one entry per burst of a person's saves, at most 10 per page, compare and restore
hist() { "${MYSQL[@]}" "$DB_NAME" -N -e "SELECT $1 FROM ka_stavba_revize WHERE ids = $IDS AND cast IN ('draft', 'draft~mcp') ${2:-}"; }
age_hist() { "${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stavba_revize SET datum = datum - INTERVAL 10 MINUTE WHERE ids = $IDS AND cast IN ('draft', 'draft~mcp')"; }
expect "3.25: the builder's saves, restore and discard left a draft history, published versions without it" "$(hist 'COUNT(*) > 0')|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDS AND cast IS NULL AND stavba LIKE '%Builder test%'")" "1|1"
age_hist; page_action build_save --data-urlencode "stavba=${BUILD/Builder test/History one}" > /dev/null; H1=$(hist 'COUNT(*)')
page_action build_save --data-urlencode "stavba=${BUILD/Builder test/History two}" > /dev/null
expect "3.25: a second save in the same burst updates the entry in place" "$(hist 'COUNT(*)')|$(hist "stavba LIKE '%History two%'" 'ORDER BY idr DESC LIMIT 1')" "$H1|1"
age_hist; page_action build_save --data-urlencode "stavba=${BUILD/Builder test/History three}" > /dev/null
expect "3.25: a save after the burst starts a new entry" "$(hist 'COUNT(*)')" "$((H1 + 1))"
for i in 1 2 3 4 5 6 7 8 9 10 11; do age_hist; page_action build_save --data-urlencode "stavba=${BUILD/Builder test/Prune $i}" > /dev/null; done
expect "3.25: the draft history keeps the last 10 entries of a page" "$(hist 'COUNT(*)')|$(hist "stavba LIKE '%Prune 11%'" 'ORDER BY idr DESC LIMIT 1')" "10|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=build_versions&id=$IDS"
php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $d = array_column($j["koncepty"] ?? [], "idr"); $p = array_column($j["revize"] ?? [], "idr");
  exit(count($d) === 10 && $p !== [] && array_intersect($d, $p) === [] && preg_match("/^(today|dnes) \\d\\d:\\d\\d$/", (string) $j["koncepty"][0]["kdy"]) === 1 ? 0 : 1);' -- "$WORK/response" \
  && echo "  ok     3.25: Versions lists the draft history (today/dnes HH:MM) apart from the published versions" || { echo "  CHYBA  3.25: build_versions koncepty"; ERRORS=$((ERRORS+1)); }
OLDEST=$(hist 'idr' 'ORDER BY idr ASC LIMIT 1')
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=build_compare&id=$IDS&version=$OLDEST"
php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $s = $j["sekce"][0] ?? [];
  exit(($j["ok"] ?? false) && $s["stav"] === "changed" && str_contains($s["html"], "<del>") && str_contains($s["html"], "<ins>11</ins>") && $j["pridano"] > 0 ? 0 : 1);' -- "$WORK/response" \
  && echo "  ok     3.25 (C21): a draft history entry compared with the current draft per section" || { echo "  CHYBA  3.25: build_compare $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
PUBLISHED_IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDS AND cast IS NULL ORDER BY idr DESC LIMIT 1")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=build_compare&id=$IDS&version=$PUBLISHED_IDR"; grep -q '"ok":true' "$WORK/response" && echo "  ok     3.25: a published version compares too" || { echo "  CHYBA  3.25: compare of a published version"; ERRORS=$((ERRORS+1)); }
expect "3.25: compare of another page's version is refused" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_compare&id=$IDS&version=999999")" 404
CHOSEN=$(hist 'idr' 'ORDER BY idr ASC LIMIT 1 OFFSET 2'); CHOSEN_TEXT=$(hist "REGEXP_SUBSTR(stavba, 'Prune [0-9]+')" "AND idr = $CHOSEN")
page_action build_restore -d "idr=$CHOSEN" > /dev/null
expect "3.25: restoring a draft entry makes it the draft and keeps the replaced draft in the history" "$(grep -c "$CHOSEN_TEXT\"" "$WORK/response")|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%$CHOSEN_TEXT\"%' FROM ka_stranky WHERE ids = $IDS")|$(hist "stavba LIKE '%Prune 11\"%'" 'ORDER BY idr DESC LIMIT 1 OFFSET 1')" "1|1|1"
expect "autor novinek do builderu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=builder&id=$IDS")" 403
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=build_text" -d "_csrf=$TOKEN" -d "ids=$IDS"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<h1>Druhá verze</h1>" "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && echo "  ok     návrat k textu zachová obsah stavby bez rozložení" || { echo "  CHYBA  stavba_text"; ERRORS=$((ERRORS+1)); }

