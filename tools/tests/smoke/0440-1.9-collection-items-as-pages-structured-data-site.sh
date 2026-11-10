echo "== 1.9: collection items as pages, structured data, site audit, privacy template, deprecations"
JANA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-novakova' AND jazyk = ''")
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"seo_title\":\"Jana Nováková, jednatelka\",\"description\":\"Vede dílnu dvacet let.\",\"share_image\":\"javascript:x\"}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q '<title>Jana Nováková, jednatelka – ' "$WORK/response" && grep -q '<meta name="description" content="Vede dílnu dvacet let.">' "$WORK/response" && ! grep -q 'javascript:x' "$WORK/response" \
  && echo "  ok     item page: its own SEO title and description, an unsafe image dropped" || { echo "  CHYBA  item SEO fields: $(grep -o '<title>[^<]*' "$WORK/response")"; ERRORS=$((ERRORS+1)); }
mcp list_item_versions "{\"collection\":\"tym\",\"id\":$JANA}" > "$WORK/response"; VER=$(mcp_value versions 0 id)
mcp restore_item_version "{\"collection\":\"tym\",\"id\":$JANA,\"version\":$VER}" > /dev/null
expect "item versions: the earlier version comes back, the newer one goes to the history" "$(sq "SELECT CONCAT(seo_titulek = '', '|', (SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'polozka:$JANA') >= 2) FROM ka_kolekce_polozky WHERE idp = $JANA")" "1|1"
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"noindex\":true}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova" # into a file: grep -q on a pipe would cut curl off (pipefail)
grep -q 'content="noindex' "$WORK/response" && ! curl -s "$B/sitemap.xml" | contains '/tym/jana-novakova' && ! curl -s "$B/llms.txt" | contains '/tym/jana-novakova' \
  && echo "  ok     a noindex item is out of search engines, the sitemap and llms.txt" || { echo "  CHYBA  noindex item"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"noindex\":false}" > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Planovany Clen","publish_at":"2099-01-01 08:00"}' > "$WORK/response"; PLAN=$(mcp_value id)
expect "a scheduled item waits hidden" "$(sq "SELECT CONCAT(zobrazit, '|', zverejnit_od IS NOT NULL) FROM ka_kolekce_polozky WHERE idp = $PLAN")" "0|1"
sq "UPDATE ka_kolekce_polozky SET zverejnit_od = '$(site_time)' - INTERVAL 1 MINUTE WHERE idp = $PLAN" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "the scheduled item publishes itself" "$(sq "SELECT CONCAT(zobrazit, '|', zverejnit_od IS NULL) FROM ka_kolekce_polozky WHERE idp = $PLAN")" "1|1"
IDK_TYM=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
check "the item form has SEO fields, scheduling and the history" 200 "/admin.php?module=collections&action=item&id=$IDK_TYM&item=$JANA" 'Historie položky'
grep -q 'name="seo_titulek"' "$WORK/response" && grep -q 'name="zverejnit_od"' "$WORK/response" && echo "  ok     item form fields" || { echo "  CHYBA  item form fields"; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","structured_data":{"type":"Person","fields":{"jobTitle":"funkce"}}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/tym/zuzana-zelena"
grep -q '"@type":"Person","name":"Zuzana Zelena"' "$WORK/response" && grep -q '"jobTitle":"Jednatelka"' "$WORK/response" \
  && echo "  ok     structured data of a collection: item pages are a Person" || { echo "  CHYBA  collection structured data"; grep -o '"@graph".\{0,600\}' "$WORK/response" | head -c 800; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","structured_data":{"type":"Recipe"}}' | contains 'Unknown structured data type' && echo "  ok     MCP: an unknown schema type is refused" || { echo "  CHYBA  MCP unknown schema type"; ERRORS=$((ERRORS+1)); }
check "the collection form offers structured data" 200 "/admin.php?module=collections&action=edit&id=$IDK_TYM" "Strukturovaná data pro vyhledávače"
mcp list_collections '{}' | contains 'jobTitle' && echo "  ok     MCP: list_collections shows the structured data" || { echo "  CHYBA  list_collections structured data"; ERRORS=$((ERRORS+1)); }
# site audit
mcp create_page '{"title":"Audit test","text":"<p><a href=\"/neexistuje-audit\">x</a> <a href=\"/tym/zuzana-zelena\">ok</a></p>","visible":true}' > /dev/null
check "Administration → Site audit finds a broken internal link" 200 "/admin.php?module=audit" "Odkaz /neexistuje-audit vede na stránku, která neexistuje"
grep -q '/tym/zuzana-zelena vede' "$WORK/response" && { echo "  CHYBA  the audit reports a working item link"; ERRORS=$((ERRORS+1)); } || echo "  ok     a link to an existing item is fine"
mcp site_audit '{"kind":"link"}' > "$WORK/response"
grep -q 'neexistuje-audit' "$WORK/response" && grep -q '\\"page\\":' "$WORK/response" && echo "  ok     MCP: site_audit with the target to fix" || { echo "  CHYBA  MCP site_audit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_list() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'; }
mcp_list | php -r '$t = array_column(json_decode(stream_get_contents(STDIN), true)["result"]["tools"], null, "name"); exit(($t["site_audit"]["annotations"]["readOnlyHint"] ?? false) === true && ($t["restore_item_version"]["annotations"]["readOnlyHint"] ?? true) === false ? 0 : 1);' \
  && echo "  ok     MCP: site_audit is read-only, restore_item_version writes" || { echo "  CHYBA  MCP annotations of 1.9 tools"; ERRORS=$((ERRORS+1)); }
# addresses not found: bots are not recorded, what works again drops out, the warning can be dismissed
sq "DELETE FROM ka_nenalezeno" > /dev/null
for i in 1 2 3; do curl -s -o /dev/null "$B/wp/v2/users"; curl -s -o /dev/null "$B/_next"; curl -s -o /dev/null "$B/stara-cenik-2019"; curl -s -o /dev/null "$B/stary-kontakt"; done
sq "INSERT INTO ka_nenalezeno (cesta, pocet, naposledy) VALUES ('o-nas', 9, '$(site_time)')" > /dev/null
expect "404 log: bot probes are not recorded" "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta IN ('wp/v2/users', '_next')")" "0"
check "the start screen explains the 404 warning and offers to review it" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 2."
grep -q 'module=redirects#nenalezeno' "$WORK/response" && grep -q 'action=ignore_all' "$WORK/response" && echo "  ok     the warning links to the list and can be dismissed" || { echo "  CHYBA  404 warning actions"; ERRORS=$((ERRORS+1)); }
expect "an address that works again drops out of the log" "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta = 'o-nas'")" "0"
check "the 404 list says what to do" 200 "/admin.php?module=redirects" "Ignorovat – nic ji nenahrazuje"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=ignore" -d "_csrf=$(csrf)" -d cesta=stary-kontakt
expect "Ignore hides one address for good" "$(sq "SELECT ignorovano IS NOT NULL FROM ka_nenalezeno WHERE cesta = 'stary-kontakt'")" "1"
curl -s -o /dev/null "$B/stary-kontakt"; check "an ignored address does not come back in the warning" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 1."
mcp ignore_not_found '{"all":true}' > "$WORK/response"
expect "MCP: ignore_not_found dismisses the rest" "$(mcp_value ignored)" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"; grep -q 'skončily „stránka nenalezena“' "$WORK/response" && { echo "  CHYBA  the 404 warning stays after Ignore all"; ERRORS=$((ERRORS+1)); } || echo "  ok     after ignoring, the start screen has no 404 warning"
for i in 1 2 3; do curl -s -o /dev/null "$B/uplne-nova-adresa"; done
check "a new address brings the warning back" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 1."
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=ignore_all" -d "_csrf=$(csrf)" -d zpet=prehled
# privacy policy from the enabled features
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
save_page -d ids=0 --data-urlencode "titulek=Zásady test" -d sablona=zasady -d zobrazit=0 -d v_menu=0 -d text= > /dev/null
expect "3.19 (N3182-7): a privacy page made later from the template leaves OpenStreetMap out while no store locator loads its map at once" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Zásady test' AND text LIKE '%OpenStreetMap%'")" "0"
expect "privacy template: a disclaimer and only the enabled features" "$(sq "SELECT CONCAT(text LIKE '%nikoli právní rada%', '|', text LIKE '%poptávkovém formuláři%' OR text LIKE '%formuláře%', '|', text LIKE '%[ADDRESS]%' OR text LIKE '%[ADRESA]%' OR text LIKE '%sídlem%') FROM ka_stranky WHERE titulek = 'Zásady test'")" "1|1|1"
# streamed backup download and the media ZIP only on POST
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
LAST_BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ | grep '^kaleta-' | head -1)
curl -s -b "$JAR" -o "$WORK/backup-download" "$B/admin.php?module=settings&action=download_backup&file=$LAST_BACKUP"
expect "a backup downloads whole (streamed)" "$(wc -c < "$WORK/backup-download" | tr -d ' ')" "$(wc -c < "$WORK/web/storage/zalohy/$LAST_BACKUP" | tr -d ' ')"
expect "3.15 N42: a backup download is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'settings' AND akce = 'backup downloaded' AND popis = '$LAST_BACKUP'")" "1"
expect "the media ZIP is not built by a GET" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$B/admin.php?module=settings&action=media_backup" | tr 'A-Z' 'a-z')" "text/html; charset=utf-8"
expect "the media ZIP by POST, with the originals" "$(curl -s -b "$JAR" -o "$WORK/media.zip" -w '%{content_type}' -X POST "$B/admin.php?module=settings&action=media_backup" -d "_csrf=$TOKEN")|$([ "$(unzip -Z1 "$WORK/media.zip" 2>/dev/null | grep -c '^media/')" -gt 0 ] && echo files)" "application/zip|files"

