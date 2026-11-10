echo "== 3.7: migration II – past 300 old addresses, items in batches and from CSV"
# a fake old shop (tools/fake-old-site.php): a sitemap index with 350 pages, a page its robots.txt disallows, images;
# every request it gets is in $BIGLOG
BIG_PORT=$((PORT + 8)); BIG="http://127.0.0.1:$BIG_PORT"; BIGLOG="$WORK/bigsite.log"; : > "$BIGLOG"
(cd "$ROOT/tools" && KALETA_FAKE_LOG="$BIGLOG" exec php -S "127.0.0.1:$BIG_PORT" fake-old-site.php > /dev/null 2>&1) & OLDSITE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$BIG/robots.txt" && break; sleep 0.3; done
mcp migration_report "{\"url\":\"$BIG\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 40); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "3.7 migration report: all 352 addresses of a sitemap index are checked (it stopped at 300)" "$(import_field summary)" '{"addresses":352,"checked":352,"ok":1,"redirected":0,"not_published":0,"missing":351,"errors":351,"warnings":0}'
mcp migration_report "{\"report_id\":\"$REPORT_ID\",\"offset\":300}" > "$WORK/response"
expect "3.7 migration report: the problems page by offset; the page robots.txt disallows is looked up here, never downloaded" \
  "$(php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo count($r["problems"]), "|", $r["more_problems"], "|", count(array_filter($r["problems"], fn (array $p): bool => in_array("robots", array_column($p["problems"], "code"), true)));' "$WORK/response")|$(grep -c '^/p/' "$BIGLOG" || true)|$(grep -c '^/private/' "$BIGLOG" || true)" \
  "51|0|1|350|0"
# 3.7 (N37-27): a huge number is a clear tool error naming the parameter – never an error page or another number
curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"migration_report\",\"arguments\":{\"report_id\":\"$REPORT_ID\",\"offset\":1e20}}}" > "$WORK/code"
mcp migration_report "{\"report_id\":\"$REPORT_ID\",\"offset\":5000}" > "$WORK/response2"
mcp save_collection_items '{"collection":"x","items":[{"id":100000000000000000000,"name":"A"}]}' > "$WORK/response3"
expect "3.7 N37-27 MCP: a huge or out-of-range number gives a tool error that names it (offset 1e20, offset 5000, an item id of 21 digits)" \
  "$(cat "$WORK/code")|$(contains -q '"isError":true' "$WORK/response" && contains -q 'The number in offset is too large' "$WORK/response" && echo 1)|$(contains -q 'offset must be a whole number from 0 to' "$WORK/response2" && echo 1)|$(contains -q 'The number in items.0.id is too large' "$WORK/response3" && echo 1)" \
  "200|1|1|1"
mcp import_website "{\"url\":\"$BIG\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 40); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "3.7 website import: the 352 pages of the sitemap index found (it stopped at 300)" "$(import_field phase)|$(import_field found)" "preview|352"
# save_collection_items: many items in one call with the rules of save_collection_item, media by address, dry_run first
mcp create_collection '{"name":"Produkty 37","slug":"produkty-37","item_pages":true,"fields":[{"key":"price","label":"Price","type":"number"},{"key":"photo","label":"Photo","type":"image"}]}' > /dev/null
ITEMS="[{\"name\":\"Oak table\",\"slug\":\"oak-table\",\"values\":{\"price\":\"1200\",\"nope\":\"x\"},\"media\":{\"photo\":\"$BIG/img/oak.png\"}},{\"name\":\"Chair\",\"values\":{\"price\":\"abc\"},\"visible\":true},\"nonsense\",{\"name\":\"Oak again\",\"slug\":\"oak-table\"},{\"name\":\"Lamp\",\"media\":{\"photo\":\"http://127.0.0.1:1/x.png\",\"price\":\"$BIG/img/x.png\"}}]"
mcp save_collection_items "{\"collection\":\"produkty-37\",\"dry_run\":true,\"items\":$ITEMS}" > "$WORK/response"
expect "3.7 save_collection_items dry_run: what would happen per item, nothing saved or downloaded" \
  "$(mcp_value added)|$(mcp_value refused)|$(mcp_value media to_download)|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k USING (idk) WHERE k.seo_link = 'produkty-37'")|$(grep -c '^/img/' "$BIGLOG" || true)" "3|2|2|0|0"
mcp save_collection_items "{\"collection\":\"produkty-37\",\"items\":$ITEMS}" > "$WORK/response"
expect "3.7 save_collection_items: valid items saved (hidden unless visible), invalid ones refused with the reason, the image downloaded into Media" \
  "$(mcp_value added)|$(mcp_value refused)|$(mcp_value results 3 reason)|$(mcp_value results 0 unknown_keys)|$(mcp_value results 1 invalid_fields)|$(mcp_value results 4 media_failed 0 reason)|$(mcp_value results 4 media_failed 1 reason)|$(sq "SELECT GROUP_CONCAT(CONCAT(p.seo_link, ':', p.zobrazit, ':', p.data LIKE '%\"photo\":\"media%') ORDER BY p.seo_link) FROM ka_kolekce_polozky p JOIN ka_kolekce k USING (idk) WHERE k.seo_link = 'produkty-37'")" \
  '3|2|The same item is in this batch twice – only the first one is saved.|["nope"]|["Price"]|The field does not exist or is not an image or file field.|The old site is not responding.|chair:1:0,lamp:0:0,oak-table:0:1'
mcp save_collection_items '{"collection":"produkty-37","items":[{"name":"Oak table","slug":"oak-table","values":{"price":"1200"}},{"slug":"chair","values":{"price":"89"}}]}' > "$WORK/response"
CHAIR=$(sq "SELECT p.idp FROM ka_kolekce_polozky p JOIN ka_kolekce k USING (idk) WHERE k.seo_link = 'produkty-37' AND p.seo_link = 'chair'")
expect "3.7 save_collection_items: a second call finds the items by slug – the same values stay unchanged, a change keeps the earlier version" \
  "$(mcp_value unchanged)|$(mcp_value changed)|$(sq "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'polozka:${CHAIR:-0}'")" "1|1|1"
mcp_as "$DRAFT_TOKEN" save_collection_items '{"collection":"produkty-37","items":[{"slug":"chair","values":{"price":"1"}},{"slug":"oak-table","visible":true},{"slug":"lamp","values":{"price":"5"}},{"name":"Draft item","visible":true}]}' > "$WORK/response"
expect "3.7 save_collection_items over a drafts-only connection: hidden items only – a visible item and visible: true refused, a new item stays hidden" \
  "$(mcp_value results 0 status)|$(mcp_value results 1 status)|$(mcp_value results 2 status)|$(mcp_value results 3 status):$(mcp_value results 3 visible)|$(sq "SELECT CONCAT(zobrazit, ':', data LIKE '%\"price\":\"89\"%') FROM ka_kolekce_polozky WHERE idp = ${CHAIR:-0}")|$(sq "SELECT GROUP_CONCAT(zobrazit) FROM ka_kolekce_polozky WHERE seo_link IN ('draft-item', 'oak-table')")" \
  "refused|refused|changed|added:|1:1|0,0"
# 3.7 (N37-21): a batch downloads its media first and saves after – an item a person published (and edited) while the
# download ran stays published with the person's edit, and a drafts-only batch never touches it then; (N37-28) item keys
# the tool does not know are reported
mcp create_collection '{"name":"Race 37","slug":"race-37","fields":[{"key":"price","label":"Price","type":"number"},{"key":"note","label":"Note","type":"text"},{"key":"photo","label":"Photo","type":"image"}]}' > /dev/null
mcp save_collection_items '{"collection":"race-37","items":[{"name":"Race A","slug":"race-a","values":{"price":"1","note":"old"}},{"name":"Race B","slug":"race-b","values":{"price":"1"}}]}' > /dev/null
RACE_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'race-37'")
mcp save_collection_items "{\"collection\":\"race-37\",\"items\":[{\"slug\":\"race-a\",\"publish_at\":\"2027-01-01\",\"values\":{\"price\":\"2\"},\"media\":{\"photo\":\"$BIG/slow/a.png\"}}]}" > "$WORK/response" & RACE_A=$!
sleep 1.5; sq "UPDATE ka_kolekce_polozky SET zobrazit = 1, data = JSON_SET(data, '$.note', 'new') WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-a'"; wait "$RACE_A" || true
N21_A="$(mcp_value results 0 status)|$(mcp_value results 0 unknown_item_keys)|$(sq "SELECT CONCAT(zobrazit, ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.price')), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.note')), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.photo')) LIKE 'media/%') FROM ka_kolekce_polozky WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-a'")"
mcp_as "$DRAFT_TOKEN" save_collection_items "{\"collection\":\"race-37\",\"items\":[{\"slug\":\"race-b\",\"values\":{\"price\":\"3\"},\"media\":{\"photo\":\"$BIG/slow/b.png\"}}]}" > "$WORK/response" & RACE_B=$!
sleep 1.5; sq "UPDATE ka_kolekce_polozky SET zobrazit = 1 WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-b'"; wait "$RACE_B" || true
expect "3.7 N37-21 save_collection_items: an item published and edited during the downloads stays published with the edit; a drafts-only batch is refused then (N37-28: unknown item keys reported)" \
  "$N21_A|$(mcp_value results 0 status)|$(sq "SELECT CONCAT(zobrazit, ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.price'))) FROM ka_kolekce_polozky WHERE idk = ${RACE_IDK:-0} AND seo_link = 'race-b'")" \
  'changed|["publish_at"]|1:2:new:1|refused|1:1'
# Collections → Import: a Windows-1250 CSV from Excel, the columns paired by themselves, the preview, saving in batches,
# the image downloaded afterwards; an empty cell leaves a value as it is
IDK37=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty-37'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=import&id=$IDK37"
printf 'Název;Price;Photo;Navíc\nŽlutý stůl;1 200;%s/img/stul.png;x\nOak table;999;;\n;5;;\nŽlutý stůl;1;;\n' "$BIG" | iconv -f UTF-8 -t WINDOWS-1250 > "$WORK/items.csv"
PREVIEW=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_upload" -F "_csrf=$(csrf)" -F "idk=$IDK37" -F "soubor=@$WORK/items.csv;type=text/csv")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$PREVIEW"
expect "3.7 CSV import of items: the preview pairs the columns, reads Windows-1250 and refuses a row without a name and a repeated one (nothing saved yet)" \
  "$(grep -o '<option value="[_a-z]*" selected>' "$WORK/response" | tr -d '\n')|$(contains -q '<td>Žlutý stůl</td>' "$WORK/response" && echo 1 || echo 0)|$(grep -c 'stitek stitek-chyba' "$WORK/response" || true)|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link = 'zluty-stul'")" \
  '<option value="_name" selected><option value="price" selected><option value="photo" selected>|1|2|0'
IMPORT37=$(grep -o 'name="import" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//' || true)
PROGRESS=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_map" -d "_csrf=$(csrf)" -d "idk=$IDK37" -d "import=$IMPORT37" \
  -d 'mapovani[0]=_name' -d 'mapovani[1]=price' -d 'mapovani[2]=photo' -d 'mapovani[3]=' -d ulozit=1)
for i in $(seq 1 10); do
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$PROGRESS" -d "_csrf=$(csrf)" -d "import=$IMPORT37"
  contains -q 'id="import-hotovo"' "$WORK/response" && break
done
expect "3.7 CSV import of items: saved hidden, the image from the address in Media, the existing item updated by its name, its photo kept" \
  "$(sq "SELECT CONCAT(nazev, ':', zobrazit, ':', data LIKE '%\"photo\":\"media%') FROM ka_kolekce_polozky WHERE seo_link = 'zluty-stul'")|$(sq "SELECT CONCAT(data LIKE '%\"price\":\"999\"%', ':', data LIKE '%\"photo\":\"media%') FROM ka_kolekce_polozky WHERE idk = ${IDK37:-0} AND seo_link = 'oak-table'")|$(grep -c '^/img/stul.png' "$BIGLOG" || true)" \
  "Žlutý stůl:0:1|1:1|1"
# 3.7 (N37-25): the uploaded rows are deleted once saved; a finished import is listed and can be removed; the daily
# clean-up removes import states and reports untouched for 14 days – an uploaded WordPress export stays
IMPORTDIR="$WORK/web/storage/import"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=import&id=$IDK37"
N25_LIST=$(grep -c "name=\"import\" value=\"$IMPORT37\"" "$WORK/response" || true)
N25_ROWS=$([ -e "$IMPORTDIR/polozky-$IMPORT37.rows.json" ] && echo rows-kept || echo rows-gone)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=import_delete" -d "_csrf=$(csrf)" -d "idk=$IDK37" -d "import=$IMPORT37"
printf '<?xml version="1.0"?><rss/>' > "$IMPORTDIR/old-export.xml"; printf '{}' > "$IMPORTDIR/web-0123456789abcdef.json"; printf '{}' > "$IMPORTDIR/parita-0123456789abcdef.json"; printf '{}' > "$IMPORTDIR/parita-fedcba9876543210.json"
php -r 'foreach (array_slice($argv, 1) as $f) { touch($f, time() - 20 * 86400); }' "$IMPORTDIR/old-export.xml" "$IMPORTDIR/web-0123456789abcdef.json" "$IMPORTDIR/parita-0123456789abcdef.json"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.7 N37-25 item import: rows deleted once saved, the finished import listed and removable; the daily clean-up expires old states, keeps the export and fresh files" \
  "$N25_ROWS|$N25_LIST|$([ -e "$IMPORTDIR/polozky-$IMPORT37.json" ] && echo state-kept || echo state-gone)|$(ls "$IMPORTDIR" | grep -E '^(old-export\.xml|web-0123456789abcdef\.json|parita-0123456789abcdef\.json|parita-fedcba9876543210\.json)$' | tr '\n' ' ')" \
  "rows-gone|1|state-gone|old-export.xml parita-fedcba9876543210.json "
# 3.7 security review N37-8: a row whose address (made from its name) is a category's gets a number, and the result lists it
mcp save_collection_category '{"collection":"produkty-37","name":"Stolky","slug":"stolky","visible":true}' > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=import&id=$IDK37"
printf 'Název;Price\nStolky;10\n' > "$WORK/items37.csv"
PREVIEW=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_upload" -F "_csrf=$(csrf)" -F "idk=$IDK37" -F "soubor=@$WORK/items37.csv;type=text/csv")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$PREVIEW"
contains -q 'Adresa „stolky“ patří kategorii této kolekce, proto má položka „stolky-2“.' "$WORK/response" && echo "  ok     3.7 N37-8: the import preview says the row gets another address" || { echo "  CHYBA  3.7 N37-8: import preview note"; ERRORS=$((ERRORS+1)); }
IMPORT37=$(grep -o 'name="import" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//' || true)
PROGRESS=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=collections&action=import_map" -d "_csrf=$(csrf)" -d "idk=$IDK37" -d "import=$IMPORT37" -d 'mapovani[0]=_name' -d 'mapovani[1]=price' -d ulozit=1)
for i in $(seq 1 10); do
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$PROGRESS" -d "_csrf=$(csrf)" -d "import=$IMPORT37"
  contains -q 'id="import-hotovo"' "$WORK/response" && break
done
expect "3.7 N37-8: the CSV import never gives an item a category's address and lists the item that got another one" \
  "$(sq "SELECT GROUP_CONCAT(seo_link) FROM ka_kolekce_polozky WHERE idk = ${IDK37:-0} AND nazev = 'Stolky'")|$(grep -c 'id="import-prejmenovane"' "$WORK/response" || true)" "stolky-2|1"
kill "$OLDSITE_PID" 2>/dev/null; OLDSITE_PID=
# 3.7 (N37-23): a hostile old site – a 1 MB robots.txt of wildcard rules and a home page with 2,000 links: the first batch
# reads the robots.txt cut to 512 KB and 500 rules (and says so), checks all links against it and ends within its budget
HOSTILE_PORT=$((PORT + 19)); HOSTILE="http://127.0.0.1:$HOSTILE_PORT"; HOSTILELOG="$WORK/hostile.log"; : > "$HOSTILELOG"
(cd "$ROOT/tools" && KALETA_FAKE_LOG="$HOSTILELOG" KALETA_FAKE_MODE=robots exec php -S "127.0.0.1:$HOSTILE_PORT" fake-old-site.php > /dev/null 2>&1) & HOSTILE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$HOSTILE/" && break; sleep 0.3; done
N23_START=$(date +%s)
curl -s -m 30 -o "$WORK/response" -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"import_website\",\"arguments\":{\"url\":\"$HOSTILE\"}}}" || true
N23_TIME=$(( $(date +%s) - N23_START ))
expect "3.7 N37-23 website import: a 1 MB robots.txt and 2,000 links – one batch in under 30 s, every allowed link found, the cut robots.txt noted, the disallowed page never read" \
  "$([ "$N23_TIME" -lt 30 ] && echo fast || echo "slow:$N23_TIME")|$(import_field found)|$(contains -q 'only its first 512 KB and 500 rules' "$WORK/response" && echo noted)|$(grep -c '^/blocked/' "$HOSTILELOG" || true)" \
  "fast|2001|noted|0"
kill "$HOSTILE_PID" 2>/dev/null; HOSTILE_PID=
# 3.7 (N37-24): sitemaps on other hosts are never read (and the result says so), at most 100 sitemaps are queued, and no
# sitemap is read once 3,000 addresses are known
SITEMAPS_PORT=$((PORT + 20)); SITEMAPS="http://127.0.0.1:$SITEMAPS_PORT"; SITEMAPSLOG="$WORK/sitemaps.log"; : > "$SITEMAPSLOG"
(cd "$ROOT/tools" && KALETA_FAKE_LOG="$SITEMAPSLOG" KALETA_FAKE_MODE=sitemaps exec php -S "127.0.0.1:$SITEMAPS_PORT" fake-old-site.php > /dev/null 2>&1) & SITEMAPS_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$SITEMAPS/robots.txt" && break; sleep 0.3; done
mcp import_website "{\"url\":\"$SITEMAPS\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "3.7 N37-24 website import: 3,000 addresses from the first two sitemaps, the third never read, other hosts and the sitemaps over 100 skipped and said" \
  "$(import_field phase)|$(import_field found)|$(grep -c '^/sm-[12]\.xml' "$SITEMAPSLOG" || true)|$(grep -c '^/sm-3\.xml\|^/sm-x' "$SITEMAPSLOG" || true)|$(contains -q '3 sitemaps on other hosts were not read (localhost, cdn.invalid)' "$WORK/response" && echo foreign)|$(contains -q '56 more sitemaps were not read: at most 100 are read' "$WORK/response" && echo capped)" \
  "preview|3000|2|0|foreign|capped"
mcp migration_report "{\"url\":\"$SITEMAPS\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "3.7 N37-24 migration report: the skipped sitemaps are said in its notes too" "$(contains -q 'sitemaps on other hosts were not read' "$WORK/response" && echo noted)" "noted"
kill "$SITEMAPS_PID" 2>/dev/null; SITEMAPS_PID=
# 2.7: old form entries (e.g. Breakdance submissions) come over into Enquiries, once
ENTRIES='[{"date":"2025-03-14 09:30","form":"Contact","page":"/contact","fields":{"Name":"Jana Old","E-mail":"jana.old@example.cz","Message":"A table please"}},{"date":"2025-03-15 10:00","form":"Contact","fields":[{"label":"Phone","value":"777 000 111"}]}]'
mcp import_enquiries "{\"source\":\"breakdance\",\"entries\":$ENTRIES}" > "$WORK/response"
expect "import_enquiries: two old entries imported" "$(import_field imported)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(email), ':', MIN(stav)) FROM ka_poptavky WHERE zdroj = 'import:breakdance'")" "2|2:jana.old@example.cz:1"
mcp import_enquiries "{\"source\":\"breakdance\",\"entries\":$ENTRIES}" > "$WORK/response"
expect "import_enquiries: a second run skips them" "$(import_field imported):$(import_field already_imported)" "0:2"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # limit přihlášení z IP vyčerpal test zámku účtu
JAR5="$WORK/jar5"
curl -s -c "$JAR5" -b "$JAR5" -o /dev/null "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=nove"
TOKEN5=$(curl -s -b "$JAR5" -c "$JAR5" "$B/admin.php?action=oauth" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
code=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$TOKEN5" -d user=admin --data-urlencode "password=$PASSWORD")
case "$code" in *action=oauth) echo "  ok     nepřihlášený se po přihlášení vrátí na souhlas";; *) echo "  CHYBA  návrat na souhlas po přihlášení: $code"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; grep -q 'Připojené aplikace' "$WORK/response" && echo "  ok     připojená aplikace v Můj účet" || { echo "  CHYBA  připojené aplikace"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$OAUTH_CSRF" -d "odpojit_klient=$CLIENT"
expect "odpojení aplikace smaže její tokeny" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "0"

