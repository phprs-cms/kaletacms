echo "== Claude (MCP): trash, deleting and the rest of the admin (continued)"
# 3.5 (UXA-02) over MCP: create_page with visible true and nothing to show waits hidden; the first published build shows it –
# the same end state as before for create_page → save_build(publish), without an empty page on the site in between
mcp create_page '{"title":"Prázdná MCP","slug":"prazdna-mcp","visible":true,"in_menu":true}' > "$WORK/response"
php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"
MCP_EMPTY=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'prazdna-mcp'")
contains -q 'hidden until it has content' "$WORK/text" && expect "3.5 MCP: an empty visible page waits hidden, the wish is kept" "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', v_menu) FROM ka_stranky WHERE ids = ${MCP_EMPTY:-0}")" "0/1/1" \
  || { echo "  CHYBA  3.5 MCP create_page status"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "3.5 MCP: visitors get a 404 before the build is published" "$(curl -s -o /dev/null -w '%{http_code}' "$B/prazdna-mcp")" 404
mcp save_build "{\"id\":${MCP_EMPTY:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Prázdná MCP\"}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.5 MCP: save_build with publish shows the page as asked" "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish) FROM ka_stranky WHERE ids = ${MCP_EMPTY:-0}")|$(curl -s -o /dev/null -w '%{http_code}' "$B/prazdna-mcp")" "1/0|200"
mcp create_page '{"title":"Prázdná MCP text","slug":"prazdna-mcp-text","visible":true}' > /dev/null
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'prazdna-mcp-text'"),\"text\":\"<p>Teď s textem</p>\"}" > /dev/null
expect "3.5 MCP: a waiting page is shown once update_page gives it text; with text at once as before" "$(sq "SELECT GROUP_CONCAT(zobrazit ORDER BY ids) FROM ka_stranky WHERE seo_link IN ('prazdna-mcp-text')")" "1"
# N35-1: a publish date takes over from "show with the first content" – neither text nor a published build shows the page early
mcp create_page '{"title":"Embargo MCP","slug":"embargo-mcp","visible":true}' > /dev/null
MCP_EMB=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'embargo-mcp'")
mcp update_page "{\"id\":${MCP_EMB:-0},\"publish_at\":\"$(site_time '+2 days' 'Y-m-d H:i')\"}" > /dev/null
mcp update_page "{\"id\":${MCP_EMB:-0},\"text\":\"<p>Embargoed news</p>\"}" > /dev/null
EMB_TEXT="$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE ids = ${MCP_EMB:-0}")"
mcp save_build "{\"id\":${MCP_EMB:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Embargoed news\"}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.5 N35-1: a scheduled waiting page stays hidden with its schedule after text and after a published build" \
  "$EMB_TEXT|$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE ids = ${MCP_EMB:-0}")|$(curl -s -o /dev/null -w '%{http_code}' "$B/embargo-mcp")" "0/0/1|0/0/1|404"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_pages"]["readOnlyHint"] === true && $t["trash_page"]["destructiveHint"] === true && $t["create_page"]["readOnlyHint"] === false && $t["delete_collection"]["destructiveHint"] === true ? 0 : 1);' "$WORK/response" \
  && echo "  ok     MCP: tools carry annotations (read-only, destructive)" || { echo "  CHYBA  MCP annotations"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists extensions and languages" "$(mcp_value extensions | grep -c novinky)|$(mcp_value languages default)" "1|cs"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema in the English vocabulary" "$(mcp_value elements heading | grep -c 'content: text')|$(mcp_value style gap | grep -c gap)|$(mcp_value states 2)" "1|1|mobile"
mcp builder_schema '{"elements":["form"]}' > "$WORK/response"
expect "MCP: full definition of an element by its English type" "$(mcp_value elements 0 type)|$(mcp_value elements 0 fields fields item_fields type options 5)" "form|radio"
VPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL ORDER BY ids LIMIT 1")
mcp save_build "{\"id\":$VPAGE,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Go\",\"variant\":\"outline\",\"icon\":\"arrow\"},\"style\":{\"mobile\":{\"gap\":\"s\",\"background\":\"primary-soft\"}}}]}]}}" > /dev/null
expect "MCP: an English build is stored in the Czech keys" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].typ')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.varianta')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.mobil.pozadi')) FROM ka_stranky WHERE ids = $VPAGE" | tr '\t' '|')" "tlacitko|obrys|primarni-jemna"
mcp get_build "{\"id\":$VPAGE}" > "$WORK/response"
expect "MCP: get_build answers in English" "$(mcp_value build children 0 children 0 type)|$(mcp_value build children 0 children 0 content variant)|$(mcp_value build children 0 children 0 style mobile background)" "button|outline|primary-soft"
BUTTON=$(mcp_value build children 0 children 0 id)
mcp edit_build "{\"id\":$VPAGE,\"operations\":[{\"op\":\"update\",\"id\":\"$BUTTON\",\"content\":{\"new_window\":true},\"style\":{\"base\":{\"radius\":\"full\"}}}]}" > /dev/null
expect "MCP: edit_build takes English content and style" "$(sq "SELECT CONCAT(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.nove_okno'), '|', JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.zaklad.zaobleni'))) FROM ka_stranky WHERE ids = $VPAGE")" "true|plne"
mcp discard_draft "{\"id\":$VPAGE}" > /dev/null
mcp create_collection '{"name":"Kos test","fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"kos-test","name":"Polozka","visible":true}' > "$WORK/response"; ITEM=$(mcp_value id)
mcp delete_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM}" > /dev/null
expect "MCP: a collection item goes to the trash, hidden" "$(sq "SELECT CONCAT(smazano IS NOT NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
check "collection trash in the admin" 200 "/admin.php?module=collections&action=items&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'kos-test'")&status=kos" "Polozka"
mcp list_trash '{}' > "$WORK/response"
expect "MCP: list_trash shows the item" "$(mcp_value collection_items 0 name)" "Polozka"
mcp save_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM,\"visible\":true}" | contains 'is in the trash' && [ "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $ITEM")" = 0 ] \
  && echo "  ok     MCP: an item in the trash cannot be published by saving it (1.9)" || { echo "  CHYBA  MCP saved an item from the trash"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items '{"collection":"kos-test"}' | contains 'Polozka' && { echo "  CHYBA  list_collection_items lists the trash"; ERRORS=$((ERRORS+1)); } || echo "  ok     list_collection_items leaves the trash out"
mcp restore_from_trash "{\"type\":\"collection_item\",\"id\":$ITEM}" > /dev/null
expect "MCP: restored from the trash as hidden" "$(sq "SELECT CONCAT(smazano IS NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
mcp delete_collection '{"collection":"kos-test"}' > /dev/null
expect "MCP: delete_collection removes it with its items" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'kos-test'")|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "0|0"
CATEGORY=$(sq "SELECT nazev FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1")
mcp create_news "{\"title\":\"Do kose\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Do kose'")
mcp trash_news "{\"id\":$NEWS}" > /dev/null
expect "MCP: trash_news" "$(sq "SELECT smazano IS NOT NULL FROM ka_novinky WHERE idc = $NEWS")" "1"
mcp restore_from_trash "{\"type\":\"news\",\"id\":$NEWS}" > /dev/null
expect "MCP: a news item back from the trash as a draft" "$(sq "SELECT CONCAT(smazano IS NULL, visible) FROM ka_novinky WHERE idc = $NEWS")" "10"
mcp create_category '{"name":"Docasna"}' > /dev/null; CAT=$(sq "SELECT idt FROM ka_kategorie WHERE nazev = 'Docasna'")
mcp update_category "{\"id\":$CAT,\"name\":\"Docasna 2\",\"slug\":\"docasna-2\"}" > /dev/null
expect "MCP: update_category renames and redirects the old address" "$(sq "SELECT CONCAT(nazev, '|', seo_link) FROM ka_kategorie WHERE idt = $CAT")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%kategorie/docasna'")" "Docasna 2|docasna-2|1"
mcp delete_category "{\"id\":$(sq "SELECT tema FROM ka_novinky WHERE idc = $NEWS")}" | contains 'still has news items' && echo "  ok     MCP: a category with news items is not deleted" || { echo "  CHYBA  MCP: delete_category of a used category"; ERRORS=$((ERRORS+1)); }
mcp delete_category "{\"id\":$CAT}" > /dev/null
expect "MCP: delete_category" "$(sq "SELECT COUNT(*) FROM ka_kategorie WHERE idt = $CAT")" "0"
mcp save_component '{"name":"Karta","properties":[{"klic":"titulek","popisek":"Titulek","typ":"text","vychozi":"Ahoj"}]}' > "$WORK/response"; COMP=$(mcp_value id)
mcp save_build "{\"component\":$COMP,\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"{{titulek}}\"}}]}]}}" > /dev/null
mcp publish_build "{\"component\":$COMP}" > /dev/null
expect "MCP: a component built and published through the component target" "$(sq "SELECT stavba LIKE '%{{titulek}}%' AND stavba_koncept IS NULL FROM ka_komponenty WHERE idm = $COMP")" "1"
mcp list_components '{}' > "$WORK/response"
expect "MCP: list_components" "$(mcp_value 0 name)|$(mcp_value 0 published)" "Karta|1"
mcp delete_component "{\"id\":$COMP}" > /dev/null
expect "MCP: delete_component" "$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $COMP")" "0"
PAGE=$(sq "SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND smazano IS NULL ORDER BY ids LIMIT 1")
ELEMENT=$(sq "SELECT COALESCE(stavba_koncept, stavba) FROM ka_stranky WHERE ids = $PAGE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["deti"][0]["id"];')
mcp save_section "{\"id\":$PAGE,\"element\":\"$ELEMENT\",\"name\":\"Moje sekce z MCP\"}" > "$WORK/response"; SECTION=$(mcp_value id)
mcp builder_schema '{}' | contains 'Moje sekce z MCP' && echo "  ok     MCP: saved sections in builder_schema" || { echo "  CHYBA  MCP: saved sections missing in builder_schema"; ERRORS=$((ERRORS+1)); }
BEFORE=$(sq "SELECT JSON_LENGTH(COALESCE(stavba_koncept, stavba), '$.deti') FROM ka_stranky WHERE ids = $PAGE")
mcp insert_section "{\"id\":$PAGE,\"saved_section\":$SECTION}" > /dev/null
expect "MCP: insert_section with a saved section adds it with new ids" "$(sq "SELECT JSON_LENGTH(stavba_koncept, '$.deti') FROM ka_stranky WHERE ids = $PAGE")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, CONCAT('$.deti[', JSON_LENGTH(stavba_koncept, '$.deti') - 1, '].id'))) <> '$ELEMENT' FROM ka_stranky WHERE ids = $PAGE")" "$((BEFORE + 1))|1"
mcp discard_draft "{\"id\":$PAGE}" > /dev/null; mcp delete_section "{\"id\":$SECTION}" > /dev/null
expect "MCP: delete_section" "$(sq "SELECT COUNT(*) FROM ka_sekce WHERE idx = $SECTION")" "0"
mcp save_popup '{"template":"announcement_bar","name":"Na smazani"}' > "$WORK/response"; POPUP=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Na smazani'")
mcp delete_popup "{\"id\":$POPUP}" > /dev/null
expect "MCP: delete_popup" "$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $POPUP")" "0"
PNG=$(php -r 'ob_start(); imagepng(imagecreatetruecolor(8, 8)); echo base64_encode(ob_get_clean());')
mcp upload_file "{\"filename\":\"mcp-smazat.png\",\"data\":\"$PNG\"}" > /dev/null; MEDIA=$(sq "SELECT ido FROM ka_media ORDER BY ido DESC LIMIT 1")
mcp update_media "{\"id\":$MEDIA,\"alt\":\"Cerny ctverec\",\"caption\":\"Popisek\"}" > /dev/null
expect "MCP: update_media" "$(sq "SELECT CONCAT(nazev, '|', popis) FROM ka_media WHERE ido = $MEDIA")" "Cerny ctverec|Popisek"
FILE=$(sq "SELECT obr_poloha FROM ka_media WHERE ido = $MEDIA")
mcp delete_media "{\"id\":$MEDIA}" > /dev/null
expect "MCP: delete_media removes the record and the file" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $MEDIA")|$([ -e "$WORK/web/$FILE" ] && echo file || echo gone)" "0|gone"
USED=$(sq "SELECT ido FROM ka_media m WHERE EXISTS (SELECT 1 FROM ka_stranky s WHERE CONCAT_WS(' ', s.stavba, s.stavba_koncept, s.text) LIKE CONCAT('%', REPLACE(m.obr_poloha, '/', '%'), '%')) LIMIT 1")
[ -z "$USED" ] || { mcp delete_media "{\"id\":$USED}" | contains 'still used on the site' && echo "  ok     MCP: a file in use is not deleted" || { echo "  CHYBA  MCP: delete_media deleted a file in use"; ERRORS=$((ERRORS+1)); }; }
READS=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'"); mcp list_enquiries '{}' > /dev/null
expect "MCP: every enquiry read is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'")" "$((READS + 1))"
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES ('$(site_time)', 'mcp@example.cz', '[]')"; ENQUIRY=$(sq "SELECT MAX(idp) FROM ka_poptavky")
mcp update_enquiry "{\"id\":$ENQUIRY,\"status\":\"resolved\",\"note\":\"Vyrizeno pres Clauda\"}" > /dev/null
expect "MCP: update_enquiry" "$(sq "SELECT CONCAT(stav, '|', poznamka) FROM ka_poptavky WHERE idp = $ENQUIRY")" "2|Vyrizeno pres Clauda"
mcp delete_enquiry "{\"id\":$ENQUIRY}" > /dev/null
expect "MCP: delete_enquiry" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQUIRY")" "0"

