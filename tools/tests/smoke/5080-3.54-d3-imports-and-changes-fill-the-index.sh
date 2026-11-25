echo "== 3.54 D3: an import is searchable when it reports done, a changed component or field set is filled at once – no job run"
d080_page() { sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${1:-0}"; }
d080_item() { sq "SELECT COALESCE(hledani, 'NULL') FROM ka_kolekce_polozky WHERE idp = ${1:-0}"; }
background_jobs hold
fill_search_index > /dev/null

# a WordPress export of its own (another old site, so nothing is "already imported"): 40 pages and a post, each with a body word
D080_ITEMS=$(for i in $(seq 1 40); do printf '<item><title><![CDATA[Stránka %s D080]]></title><link>https://www.d080.example/strana-d080-%s/</link><dc:creator><![CDATA[admin]]></dc:creator><content:encoded><![CDATA[<p>Obsah teleskopq080n%s a vyhledavaniq080.</p>]]></content:encoded><excerpt:encoded><![CDATA[]]></excerpt:encoded><wp:post_id>%s</wp:post_id><wp:post_date><![CDATA[2026-01-05 10:00:00]]></wp:post_date><wp:post_name><![CDATA[strana-d080-%s]]></wp:post_name><wp:status><![CDATA[publish]]></wp:status><wp:post_parent>0</wp:post_parent><wp:menu_order>%s</wp:menu_order><wp:post_type><![CDATA[page]]></wp:post_type><wp:post_password><![CDATA[]]></wp:post_password></item>' "$i" "$i" "$i" "$((100 + i))" "$i" "$i"; done)
cat > "$WORK/d080.xml" <<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel><title>D080</title><link>https://www.d080.example</link><description>D080</description><language>cs</language><wp:wxr_version>1.2</wp:wxr_version>
<wp:base_site_url>https://www.d080.example</wp:base_site_url><wp:base_blog_url>https://www.d080.example</wp:base_blog_url>
<wp:author><wp:author_id>1</wp:author_id><wp:author_login><![CDATA[admin]]></wp:author_login><wp:author_email><![CDATA[admin@d080.example]]></wp:author_email><wp:author_display_name><![CDATA[Admin]]></wp:author_display_name><wp:author_first_name><![CDATA[]]></wp:author_first_name><wp:author_last_name><![CDATA[]]></wp:author_last_name></wp:author>
$D080_ITEMS
<item><title><![CDATA[Zpráva D080]]></title><link>https://www.d080.example/2026/02/zprava-d080/</link><dc:creator><![CDATA[admin]]></dc:creator><content:encoded><![CDATA[<p>Novinka mikroskopq080.</p>]]></content:encoded><excerpt:encoded><![CDATA[]]></excerpt:encoded><wp:post_id>300</wp:post_id><wp:post_date><![CDATA[2026-02-01 10:00:00]]></wp:post_date><wp:post_name><![CDATA[zprava-d080]]></wp:post_name><wp:status><![CDATA[publish]]></wp:status><wp:post_parent>0</wp:post_parent><wp:menu_order>0</wp:menu_order><wp:post_type><![CDATA[post]]></wp:post_type><wp:post_password><![CDATA[]]></wp:post_password></item>
</channel></rss>
XML
mcp upload_file "{\"filename\":\"d080.xml\",\"data\":\"$(base64 < "$WORK/d080.xml" | tr -d '\n')\"}" > "$WORK/response"
D080_FILE=$(mcp_value import_file)
mcp import_wordpress "{\"file\":\"$D080_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"
D080_IMPORT=$(mcp_value import)
for _ in 1 2 3 4 5 6 7 8; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$D080_IMPORT\"}" > "$WORK/response"; done
expect "3.54 D3: the WordPress import is done and every page and the news item it wrote has its search text – no job ran (WpImport::import ends with SearchIndex::afterBatch)" \
  "$(mcp_value phase)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'strana-d080-%'")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'strana-d080-%' AND hledani IS NULL")|$(sq "SELECT COUNT(*) FROM ka_novinky WHERE seo_link = 'zprava-d080' AND hledani LIKE '%mikroskopq080%'")" \
  "done|40|0|1"
# the import brings everything hidden over MCP; one page shown – search_site (which never fills the index) finds it by its body
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'strana-d080-17'" > /dev/null
expect "3.54 D3: a page of the finished import is found by a word of its body over search_site, before any job run" \
  "$(mcp search_site '{"query":"teleskopq080n17"}' | grep -c 'strana-d080-17')" "1"

# a component published again: the pages placing it are emptied and filled at once from its new text (SearchIndex::soon)
mcp save_component '{"name":"Karta D080"}' > "$WORK/response"; D080_COMP=$(mcp_value id)
mcp save_build "{\"component\":${D080_COMP:-0},\"build\":{\"v\":1,\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Stará karta D080</p>\"}}]}}" > /dev/null
mcp publish_build "{\"component\":${D080_COMP:-0}}" > /dev/null
mcp create_page '{"title":"Karty D080","slug":"karty-d080","content":"<p>Úvod</p>","visible":true}' > "$WORK/response"; D080_PAGE=$(mcp_value id)
mcp save_build "{\"id\":${D080_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"component\",\"content\":{\"component\":\"${D080_COMP:-0}\"}}]}}" > /dev/null
mcp publish_build "{\"id\":${D080_PAGE:-0}}" > /dev/null
D080_BEFORE=$(d080_page "$D080_PAGE")
mcp save_build "{\"component\":${D080_COMP:-0},\"build\":{\"v\":1,\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Nová karta D080</p>\"}}]}}" > /dev/null
mcp publish_build "{\"component\":${D080_COMP:-0}}" > /dev/null
expect "3.54 D3: a component published again – the page placing it has the new text at once, not NULL until the next job run" \
  "$D080_BEFORE|$(d080_page "$D080_PAGE")" "karty d080 stara karta d080|karty d080 nova karta d080"

# a collection's fields: a number field turned into a searched text field – its items get the value at once
D080_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, detail, pole) VALUES ('Lodě D080', 'lode-d080', 1, '[{\"klic\":\"nazev2\",\"popisek\":\"Název\",\"typ\":\"text\"},{\"klic\":\"material\",\"popisek\":\"Materiál\",\"typ\":\"cislo\"}]'); SELECT LAST_INSERT_ID();")
D080_ITEM=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($D080_IDK, 'Loď D080', 'lod-d080', '{\"nazev2\":\"Plachetnice\",\"material\":\"Dub D080\"}', '$(site_time)', 1); SELECT LAST_INSERT_ID();")
fill_search_index > /dev/null
D080_ITEM_BEFORE=$(d080_item "$D080_ITEM")
mcp update_collection '{"collection":"lode-d080","pole":[{"klic":"nazev2","popisek":"Název","typ":"text"},{"klic":"material","popisek":"Materiál","typ":"text"}]}' > /dev/null
expect "3.54 D3: a field turned into a searched text – the item has its value in the search text at once (fieldsChanged fills what it emptied)" \
  "$D080_ITEM_BEFORE|$(d080_item "$D080_ITEM")" "lod d080 plachetnice|lod d080 plachetnice dub d080"

background_jobs resume
mcp delete_component "{\"id\":${D080_COMP:-0}}" > /dev/null
sq "DELETE FROM ka_stranky WHERE seo_link LIKE 'strana-d080-%' OR ids = ${D080_PAGE:-0}; DELETE FROM ka_novinky WHERE seo_link = 'zprava-d080';
  DELETE FROM ka_kolekce_polozky WHERE idk = $D080_IDK; DELETE FROM ka_kolekce WHERE idk = $D080_IDK" > /dev/null
rm -f "$WORK/d080.xml"
