echo "== 3.22 (C13): the canonical address of a page, a news item and a collection item"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
expect "3.22 (C13): the page form has the field under Search engines and sharing" "$(grep -c 'name="canonical_url"' "$WORK/response")|$(grep -c 'Kanonická adresa' "$WORK/response")" "1|1"
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 --data-urlencode "titulek=C13 copy" -d seo_link=c13-copy -d zobrazit=1 --data-urlencode "text=<p>A campaign copy.</p>" --data-urlencode 'canonical_url=javascript:alert(1)')
expect "3.22 (C13): javascript: is refused with the reason, nothing is saved" "$code|$(grep -c 'Kanonická adresa musí začínat https://' "$WORK/response")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'c13-copy'")" "200|1|0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 --data-urlencode "titulek=C13 copy" -d seo_link=c13-copy -d zobrazit=1 --data-urlencode "text=<p>A campaign copy.</p>" --data-urlencode 'canonical_url= /o-nas '
IDS_C13=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'c13-copy'")
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/c13-copy"
expect "3.22 (C13): a site path is stored trimmed and printed as the one absolute canonical link (og:url too)" \
  "$(sq "SELECT canonical_url FROM ka_stranky WHERE ids = $IDS_C13")|$(grep -c 'rel="canonical"' "$WORK/response")|$(grep -c "<link rel=\"canonical\" href=\"$B/o-nas\">" "$WORK/response")|$(grep -c "og:url\" content=\"$B/o-nas\"" "$WORK/response")" "/o-nas|1|1|1"
# N322-2: the menu marks the page itself as current, never the page its canonical address names
C13_CURRENT=$(grep -c 'href="/o-nas"[^>]*aria-current' "$WORK/response" || true)
curl -s -o "$WORK/response" "$B/o-nas"
expect "3.22 (N322-2): a page whose canonical is /o-nas does not mark About us as the current menu item (/o-nas itself does)" "$C13_CURRENT|$(grep -q 'href="/o-nas"[^>]*aria-current' "$WORK/response" && echo 1 || echo 0)" "0|1"
# N322-3: the sitemap lists canonical addresses only – the copy that points at /o-nas is left out, /o-nas stays
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/sitemap.xml"
expect "3.22 (N322-3): a page whose canonical names another page is not in the sitemap, the original is" "$(grep -c "<loc>$B/c13-copy</loc>" "$WORK/response")|$(grep -c "<loc>$B/o-nas</loc>" "$WORK/response")" "0|1"
curl -s -o "$WORK/response" "$B/kontakty"
expect "3.22 (C13): a page without it keeps its own canonical address" "$(grep -c "<link rel=\"canonical\" href=\"$B/kontakty\">" "$WORK/response")" "1"
mcp update_page "{\"id\":$IDS_C13,\"canonical_url\":\"data:text/html,x\"}" > "$WORK/response"
C13_BAD=$(grep -c '"isError":true' "$WORK/response")
mcp update_page "{\"id\":$IDS_C13,\"canonical_url\":\"https://example.com/original\"}" > /dev/null
mcp get_page "{\"id\":$IDS_C13}" > "$WORK/response"
mcp_text
expect "3.22 (C13): MCP – update_page refuses data:, takes an absolute address, get_page returns it" "$C13_BAD|$(grep -c '"canonical_url":"https://example.com/original"' "$WORK/text")" "1|1"
C13_NEWS=$(sq "SELECT seo_link FROM ka_novinky WHERE visible = 1 AND noindex = 0 AND smazano IS NULL AND jazyk = '' AND datum <= '$(site_time)' ORDER BY idc LIMIT 1")
C13_NEWS_ID=$(sq "SELECT idc FROM ka_novinky WHERE seo_link = '$C13_NEWS' AND jazyk = ''")
mcp update_news "{\"id\":$C13_NEWS_ID,\"canonical_url\":\"https://example.com/first-published-here\"}" > /dev/null
mcp get_news "{\"id\":$C13_NEWS_ID}" > "$WORK/response"; mcp_text; C13_NEWS_MCP=$(grep -c '"canonical_url":"https://example.com/first-published-here"' "$WORK/text")
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/novinky/$C13_NEWS"
expect "3.22 (C13): a news item with an article first published elsewhere points there (MCP update_news, get_news)" \
  "$C13_NEWS_MCP|$(grep -c 'rel="canonical"' "$WORK/response")|$(grep -c '<link rel="canonical" href="https://example.com/first-published-here">' "$WORK/response")" "1|1|1"
check "3.22 (C13): the news form shows the stored address" 200 "/admin.php?module=news&action=edit&id=$C13_NEWS_ID" 'name="canonical_url" value="https://example.com/first-published-here"'
mcp update_news "{\"id\":$C13_NEWS_ID,\"canonical_url\":\"\"}" > /dev/null
C13_ITEM=$(sq "SELECT CONCAT(k.seo_link, '|', p.seo_link, '|', p.idp) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL AND p.jazyk = '' ORDER BY p.idp LIMIT 1")
IFS='|' read -r C13_COLLECTION C13_ITEM_SLUG C13_ITEM_ID <<< "$C13_ITEM"
mcp save_collection_item "{\"collection\":\"$C13_COLLECTION\",\"id\":$C13_ITEM_ID,\"canonical_url\":\"/kontakty\"}" > "$WORK/response"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/$C13_COLLECTION/$C13_ITEM_SLUG"
expect "3.22 (C13): a collection item page takes it too (MCP save_collection_item)" "$(sq "SELECT canonical_url FROM ka_kolekce_polozky WHERE idp = $C13_ITEM_ID")|$(grep -c "<link rel=\"canonical\" href=\"$B/kontakty\">" "$WORK/response")" "/kontakty|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce_polozky WHERE idp = $C13_ITEM_ID")&item=$C13_ITEM_ID"
expect "3.22 (C13): the item form shows it" "$(grep -c 'name="canonical_url" value="/kontakty"' "$WORK/response")" "1"
sq "UPDATE ka_kolekce_polozky SET canonical_url = '' WHERE idp = $C13_ITEM_ID; DELETE FROM ka_stranky WHERE ids = $IDS_C13" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
