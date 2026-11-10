echo "== 3.7: collection categories, previous / next item, the attachment limit of a form"
mcp create_collection '{"name":"Produkty","slug":"produkty","item_pages":true,"fields":[{"label":"Foto","type":"image"},{"label":"Popis","type":"html"}]}' > /dev/null
mcp save_collection_category '{"collection":"produkty","name":"Běžecké pásy","slug":"bezecke-pasy","description":"<p>Pásy pro rehabilitaci chůze.</p><script>alert(1)</script>","seo_description":"Rehabilitační běžecké pásy.","visible":true,"order":10}' > "$WORK/response"
CAT_TOP=$(mcp_value id)
mcp save_collection_category '{"collection":"produkty","name":"Zdravotní pásy","slug":"zdravotni","parent":"bezecke-pasy","visible":true}' > "$WORK/response"; CAT_SUB=$(mcp_value id)
mcp save_collection_category '{"collection":"produkty","name":"Elektroléčba","slug":"elektro","visible":true,"order":20}' > "$WORK/response"; CAT_EL=$(mcp_value id)
expect "3.7 MCP: a category under a top-level one, the description through the allow-list, visible when asked" \
  "$(sq "SELECT CONCAT(c.parent_id = $CAT_TOP, '|', t.description NOT LIKE '%<script%', '|', (SELECT visible FROM ka_collection_categories WHERE id = $CAT_TOP)) FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id WHERE c.id = $CAT_SUB")" "1|1|1"
for i in $(seq -w 1 13); do mcp save_collection_item "{\"collection\":\"produkty\",\"name\":\"Pás $i\",\"slug\":\"pas-$i\",\"visible\":true,\"order\":$((10#$i)),\"categories\":[\"zdravotni\"]}" > /dev/null; done
mcp save_collection_item '{"collection":"produkty","name":"Stimulátor","slug":"stimulator","visible":true,"order":50,"categories":["elektro","neni-takova"]}' > "$WORK/response"
expect "3.7 MCP: an item's categories by slug, an unknown slug reported" "$(mcp_value categories)|$(mcp_value unknown_categories)" '["elektro"]|["neni-takova"]'
# 3.18.3: the admin list of items filters by category (a top-level one with its subcategories) and by each choice field
IDK_PROD=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty'")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$IDK_PROD&category=$CAT_TOP"
grep -q '>Pás 01</a>' "$WORK/response" && ! grep -q '>Stimulátor</a>' "$WORK/response" && grep -q "<option value=\"$CAT_TOP\" selected>" "$WORK/response" \
  && echo "  ok     3.18.3: admin items filtered by a category with its subcategories" || { echo "  CHYBA  3.18.3 admin category filter"; ERRORS=$((ERRORS+1)); }
check "3.18.3: admin items filtered by another category" 200 "/admin.php?module=collections&action=items&id=$IDK_PROD&category=$CAT_EL" '>Stimulátor</a>'
grep -q '>Pás 01</a>' "$WORK/response" && { echo "  CHYBA  3.18.3: the category filter let in an item of another category"; ERRORS=$((ERRORS+1)); }
mcp create_collection '{"name":"Volby test","slug":"volby-test","fields":[{"label":"Stav","type":"choice","options":["Nový","Starý"]}]}' > /dev/null
mcp save_collection_items '{"collection":"volby-test","items":[{"name":"Volba A","values":{"stav":"Nový"}},{"name":"Volba B","values":{"stav":"Starý"}}]}' > /dev/null
IDK_VOL=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'volby-test'")
check "3.18.3: admin items filtered by a choice field" 200 "/admin.php?module=collections&action=items&id=$IDK_VOL&field_stav=Nov%C3%BD" '<option value="Nový" selected>'
grep -q '>Volba A</a>' "$WORK/response" && ! grep -q '>Volba B</a>' "$WORK/response" \
  && echo "  ok     3.18.3: the choice filter keeps only the matching items" || { echo "  CHYBA  3.18.3 admin choice filter"; ERRORS=$((ERRORS+1)); }
check "3.18.3: an option the field does not have is no filter" 200 "/admin.php?module=collections&action=items&id=$IDK_VOL&field_stav=Jin%C3%BD" '>Volba B</a>'
# 3.19 (N3183-1): a choice field whose stored key is no field key (written past sanitizeFields) is never spliced into the JSON path
POLE_VOL=$(sq "SELECT pole FROM ka_kolekce WHERE idk = $IDK_VOL")
sq "UPDATE ka_kolekce SET pole = JSON_ARRAY(JSON_OBJECT('klic', 'x'') OR 1=1 -- ', 'popisek', 'Stav', 'typ', 'volba', 'moznosti', JSON_ARRAY('Nový', 'Starý'))) WHERE idk = $IDK_VOL" > /dev/null
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=collections&action=items&id=$IDK_VOL&field_x%27%29+OR+1%3D1+--+=Nov%C3%BD")
expect "3.19 (N3183-1): a hostile stored field key is refused – no filter, no SQL error, both items listed" \
  "$code|$(grep -c '>Volba A</a>' "$WORK/response")|$(grep -c '>Volba B</a>' "$WORK/response")|$(grep -c 'name="field_x' "$WORK/response")" "200|1|1|0"
sq "UPDATE ka_kolekce SET pole = '$(printf '%s' "$POLE_VOL" | sed "s/'/''/g")' WHERE idk = $IDK_VOL" > /dev/null
check "3.7: a category page lists the items of its subcategories" 200 /produkty/bezecke-pasy '<h1>Běžecké pásy</h1>'
# the site audit and the migration report know category pages (3.9.3): a link to one is no broken link
mcp create_page '{"title":"Audit kategorií","text":"<p><a href=\"/produkty/bezecke-pasy\">a</a> <a href=\"/produkty/bezecke-pasy/zdravotni\">b</a> <a href=\"/produkty/neni-kategorie\">c</a></p>","visible":true}' > /dev/null
mcp site_audit '{"kind":"link"}' > "$WORK/audit.json" # not into response: the checks below still read the category page fetched above
grep -q 'neni-kategorie' "$WORK/audit.json" && ! grep -q 'bezecke-pasy' "$WORK/audit.json" \
  && echo "  ok     3.9.3: the audit accepts links to category pages of a collection" || { echo "  CHYBA  audit of category links"; head -c 600 "$WORK/audit.json"; ERRORS=$((ERRORS+1)); }
grep -q 'href="/produkty/bezecke-pasy/zdravotni"' "$WORK/response" && grep -q 'href="/produkty/pas-01"' "$WORK/response" && ! grep -q 'href="/produkty/stimulator"' "$WORK/response" \
  && grep -q '"@type":"CollectionPage"' "$WORK/response" && grep -q '"@type":"BreadcrumbList"' "$WORK/response" && grep -q '<link rel="canonical" href="[^"]*/produkty/bezecke-pasy">' "$WORK/response" \
  && grep -q '<meta name="description" content="Rehabilitační běžecké pásy.">' "$WORK/response" && grep -q 'aria-label="Drobečková navigace"\|class="ka-drobecky"' "$WORK/response" \
  && echo "  ok     3.7: subcategory cards, items, CollectionPage, BreadcrumbList, canonical and description" || { echo "  CHYBA  3.7 category page"; ERRORS=$((ERRORS+1)); }
check "3.7: the second page of a category" 200 "/produkty/bezecke-pasy?page=2" 'href="/produkty/pas-13"'
grep -q '<link rel="canonical" href="[^"]*/produkty/bezecke-pasy?page=2">' "$WORK/response" && ! grep -q 'href="/produkty/pas-01"' "$WORK/response" \
  && echo "  ok     3.7: paging with ?page= and its own canonical address" || { echo "  CHYBA  3.7 category paging"; ERRORS=$((ERRORS+1)); }
check "3.7: a page past the last one is a 404" 404 "/produkty/bezecke-pasy?page=9"
check "3.7: a subcategory page has the parent in its breadcrumbs" 200 /produkty/bezecke-pasy/zdravotni '<a href="/produkty/bezecke-pasy">Běžecké pásy</a>'
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/produkty/zdravotni"); expect "3.7: a subcategory at the first level redirects to its own address" "$code" "301 $B/produkty/bezecke-pasy/zdravotni"
check "3.7: a subcategory under another parent is a 404" 404 /produkty/elektro/zdravotni
check "3.7: the address latest is never a category" 404 /produkty/bezecke-pasy/latest
check "3.7: an item page still works next to the categories" 200 /produkty/stimulator '<h1>Stimulátor</h1>'
# 3.18.2: filter buttons without a field are the categories – the top-level ones, or the subcategories of the list's category; only those with items
mcp create_page '{"title":"Filtr kategorií 3182","slug":"filtr-kategorii-3182","visible":true}' > /dev/null
FILTER3182=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'filtr-kategorii-3182'")
mcp save_build '{"id":'"$FILTER3182"',"publish":true,"build":{"v":1,"children":[{"type":"section","children":[{"id":"kf3182","type":"collection_list","content":{"collection":"produkty","count":30,"filters":true},"children":[{"type":"heading","tag":"h3","content":{"text":"{{nazev}}"}}]},{"id":"kp3182","type":"collection_list","content":{"collection":"produkty","category":"bezecke-pasy","count":30,"filters":true},"children":[{"type":"heading","tag":"h4","content":{"text":"{{nazev}}"}}]}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/filtr-kategorii-3182"
grep -q 'f-kf3182=bezecke-pasy">Běžecké pásy<' "$WORK/response" && grep -q 'f-kf3182=elektro">Elektroléčba<' "$WORK/response" && ! grep -q 'f-kf3182=zdravotni' "$WORK/response" \
  && grep -q 'f-kp3182=zdravotni">Zdravotní pásy<' "$WORK/response" && ! grep -q 'f-kp3182=elektro' "$WORK/response" \
  && echo "  ok     3.18.2: category filter buttons – top-level ones, the subcategories under a list's category" || { echo "  CHYBA  3.18.2 category filter buttons: $(grep -o '<ul class="ka-kolekce-filtry".*</ul>' "$WORK/response" | head -c 600)"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/filtr-kategorii-3182?f-kf3182=elektro"
grep -q '<h3>Stimulátor</h3>' "$WORK/response" && ! grep -q '<h3>Pás 01</h3>' "$WORK/response" && grep -q 'aria-current="true">Elektroléčba<' "$WORK/response" \
  && echo "  ok     3.18.2: a category button filters the list" || { echo "  CHYBA  3.18.2 filtering by a category button"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE ids = $FILTER3182" > /dev/null
check "3.7: the sitemap lists category pages" 200 /sitemap.xml '/produkty/bezecke-pasy/zdravotni</loc>'
# collisions: an address is never both a category and an item of the collection
mcp save_collection_category '{"collection":"produkty","name":"Pás","slug":"pas-01"}' | contains 'already used by an item of this collection' \
  && mcp save_collection_item '{"collection":"produkty","name":"Kolize","slug":"elektro"}' | contains 'belongs to a category of this collection' \
  && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Kolize'")" = 0 ] \
  && echo "  ok     3.7 MCP: an address shared by a category and an item is refused both ways" || { echo "  CHYBA  3.7 slug collisions over MCP"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item '{"collection":"produkty","name":"Elektro"}' > "$WORK/response"
expect "3.7: an item address made from the name skips a category address" "$(mcp_value url | sed 's#.*/##')" "elektro-2"
PRODUKTY=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=categories&id=$PRODUKTY"
grep -q 'Zdravotní pásy' "$WORK/response" && grep -q '/produkty/bezecke-pasy/zdravotni' "$WORK/response" && echo "  ok     3.7 admin: the category tree with addresses" || { echo "  CHYBA  3.7 admin categories"; ERRORS=$((ERRORS+1)); }
check "3.7 admin: the category form" 200 "/admin.php?module=collections&action=category&id=$PRODUKTY&category=$CAT_SUB" 'name="parent_id"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_category" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d id=0 --data-urlencode "name=Kolizní" -d slug=pas-02 -d visible=1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_category" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d id=0 --data-urlencode "name=Příslušenství" -d slug=prislusenstvi -d visible=1 -d sort_order=30
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d idp=0 --data-urlencode "nazev=Admin kolize" -d seo_link=prislusenstvi -d zobrazit=1
CAT_ACC=$(sq "SELECT category_id FROM ka_collection_category_texts WHERE idk = $PRODUKTY AND slug = 'prislusenstvi'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$PRODUKTY" -d idp=0 --data-urlencode "nazev=Madla" -d seo_link=madla -d poradi=100 -d zobrazit=1 -d kategorie_formular=1 -d "kategorie[]=$CAT_ACC" -d "kategorie[]=$CAT_SUB"
expect "3.7 admin: a clash refused both ways, the ticked categories saved" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_collection_category_texts WHERE slug = 'pas-02'), '|', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Admin kolize'), '|',
  (SELECT GROUP_CONCAT(category_id ORDER BY category_id) FROM ka_collection_item_categories ic JOIN ka_kolekce_polozky p ON p.idp = ic.idp WHERE p.seo_link = 'madla'))")" "0|0|$(printf '%s\n' "$CAT_SUB" "$CAT_ACC" | sort -n | paste -sd, -)"
MADLA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'madla'")
check "3.7 admin: the item form ticks the item's categories" 200 "/admin.php?module=collections&action=item&id=$PRODUKTY&item=$MADLA" "name=\"kategorie\[\]\" value=\"$CAT_ACC\" checked"
# MCP reading: the categories with counts, items filtered by a category (its subcategories included)
mcp list_collection_categories '{"collection":"produkty"}' > "$WORK/response"
expect "3.7 MCP: list_collection_categories – the tree with item counts" "$(mcp_value categories 0 slug)|$(mcp_value categories 0 items)|$(mcp_value categories 1 parent)" "bezecke-pasy|14|bezecke-pasy"
mcp list_collection_items '{"collection":"produkty","category":"bezecke-pasy"}' > "$WORK/response"
expect "3.7 MCP: list_collection_items by a category with its subcategories" "$(mcp_value total)|$(mcp_value items 0 categories)" '14|["zdravotni"]'
# languages: the English texts of a category – its own address, hreflang both ways
mcp save_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_TOP,\"language\":\"en\",\"name\":\"Treadmills\",\"slug\":\"treadmills\"}" > /dev/null
mcp save_collection_item '{"collection":"produkty","name":"Belt EN","slug":"pas-01","language":"en","visible":true,"categories":["treadmills"]}' > /dev/null
check "3.7: the English category page" 200 /en/produkty/treadmills '<h1>Treadmills</h1>'
grep -q 'href="/en/produkty/pas-01"' "$WORK/response" && grep -q 'hreflang="cs" href="[^"]*/produkty/bezecke-pasy"' "$WORK/response" && echo "  ok     3.7: the English items and hreflang to the Czech page" || { echo "  CHYBA  3.7 category in English"; ERRORS=$((ERRORS+1)); }
check "3.7: the Czech page points to its English counterpart" 200 /produkty/bezecke-pasy 'hreflang="en" href="[^"]*/en/produkty/treadmills"'
check "3.7: the sitemap lists the English category" 200 /sitemap.xml '/en/produkty/treadmills</loc>'
# the category template: a draft from MCP is not on the site until it is published
mcp save_build '{"collection":"produkty","category_template":true,"build":{"v":1,"children":[{"type":"section","children":[{"type":"breadcrumbs"},{"type":"heading","tag":"h1","content":{"text":"Kategorie: {{nazev}}"}},{"type":"text","content":{"html":"<p>{{pocet}} produktů</p>"}},{"type":"collection_list","content":{"collection":"produkty","category":"*","count":5,"pagination":true},"children":[{"type":"heading","tag":"h3","content":{"text":"{{nazev}}"}}]}]}]}}' > "$WORK/response"
expect "3.7 MCP: the category template is a draft target of its own" "$(mcp_value category_template)|$(mcp_value status)" "1|draft – shown on the site after publishing"
curl -s "$B/produkty/bezecke-pasy" | contains 'Kategorie: Běžecké' && { echo "  CHYBA  3.7 the draft category template is visible"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.7: the draft of the category template stays hidden"
mcp publish_build '{"collection":"produkty","category_template":true}' > /dev/null
check "3.7: the published category template with {{pocet}}" 200 /produkty/bezecke-pasy '<h1>Kategorie: Běžecké pásy</h1>'
grep -q '<p>14 produktů</p>' "$WORK/response" && grep -q 'aria-label="Stránky výpisu"' "$WORK/response" && grep -q 'href="/produkty/bezecke-pasy?page=2"' "$WORK/response" \
  && echo "  ok     3.7: the item count and the paging of the category's own list" || { echo "  CHYBA  3.7 the category template's list"; ERRORS=$((ERRORS+1)); }
expect "3.7: the category template's versions are kept apart from the item template's" "$(sq "SELECT COUNT(*) FROM ka_collection_category_templates WHERE idk = $PRODUKTY AND stavba LIKE '%Kategorie: {{nazev}}%'")" 1
check "3.7 admin: the category template in the builder" 200 "/admin.php?module=collections&action=builder&id=$PRODUKTY&sablona=kategorie" 'id="stavitel-data"'
# a hidden category has no page and is not in the sitemap; deleting a category with subcategories is refused
mcp save_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_EL,\"visible\":false}" > /dev/null
check "3.7: a hidden category has no page" 404 /produkty/elektro
curl -s "$B/sitemap.xml" | contains '/produkty/elektro<' && { echo "  CHYBA  3.7 a hidden category in the sitemap"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.7: a hidden category is not in the sitemap"
mcp delete_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_TOP}" | contains 'has subcategories' && echo "  ok     3.7 MCP: a category with subcategories is not deleted" || { echo "  CHYBA  3.7 delete with subcategories"; ERRORS=$((ERRORS+1)); }
mcp delete_collection_category "{\"collection\":\"produkty\",\"id\":$CAT_EL}" > /dev/null
expect "3.7 MCP: a deleted category leaves its items in the collection" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_collection_categories WHERE id = $CAT_EL), '|', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link = 'stimulator'))")" "0|1"
# 3.7 security review N37-8: no item takes a category's address – save_collection_items numbers the slug and says so, the same
# row finds that item again; a restore from the trash or of a version keeps the item off the category's address
mcp save_collection_items '{"collection":"produkty","items":[{"name":"Příslušenství 37","slug":"prislusenstvi","values":{}}]}' > "$WORK/response"
N37_ITEM=$(mcp_value results 0 id)
expect "3.7 N37-8: save_collection_items gives an item with a category's address a number and says so" "$(mcp_value results 0 slug)|$(mcp_value results 0 note)" \
  "prislusenstvi-2|The address “prislusenstvi” belongs to a category of this collection, so the item has “prislusenstvi-2”."
mcp save_collection_items '{"collection":"produkty","items":[{"name":"Příslušenství 37","slug":"prislusenstvi","values":{}}]}' > "$WORK/response"
expect "3.7 N37-8: … the same row again finds that item instead of adding another" "$(mcp_value results 0 status)|$(mcp_value results 0 id)|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idk = $PRODUKTY AND seo_link LIKE 'prislusenstvi%'")" "unchanged|$N37_ITEM|1"
mcp save_collection_item '{"collection":"produkty","name":"Trash 37","slug":"trash-37","visible":true}' > /dev/null
T37=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'trash-37'")
mcp delete_collection_item "{\"collection\":\"produkty\",\"id\":$T37}" > /dev/null
sq "UPDATE ka_kolekce_polozky SET seo_link = 'bezecke-pasy' WHERE idp = $T37" > /dev/null # old data: an item in the trash with a category's address
mcp restore_from_trash "{\"type\":\"collection_item\",\"id\":$T37}" > "$WORK/response"
expect "3.7 N37-8: an item back from the trash never takes a category's address" "$(sq "SELECT seo_link FROM ka_kolekce_polozky WHERE idp = $T37")|$(mcp_value slug)" "bezecke-pasy-2|bezecke-pasy-2"
mcp save_collection_item '{"collection":"produkty","name":"Verze 37","slug":"verze-37"}' > /dev/null
VER37=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'verze-37'")
mcp save_collection_item "{\"collection\":\"produkty\",\"id\":$VER37,\"slug\":\"verze-37b\"}" > /dev/null
mcp save_collection_category '{"collection":"produkty","name":"Verze 37","slug":"verze-37","visible":true}' > /dev/null
mcp list_item_versions "{\"collection\":\"produkty\",\"id\":$VER37}" > "$WORK/response"
mcp restore_item_version "{\"collection\":\"produkty\",\"id\":$VER37,\"version\":$(mcp_value versions 0 id)}" > "$WORK/response"
expect "3.7 N37-8: a version whose address a category has now keeps the item's current one, and says so" "$(sq "SELECT seo_link FROM ka_kolekce_polozky WHERE idp = $VER37")|$(mcp_value note)" \
  "verze-37b|The address verze-37 of that version is taken by another item or a category now, so the item keeps verze-37b."
sq "DELETE FROM ka_kolekce_polozky WHERE idp IN ($N37_ITEM, $T37, $VER37); DELETE FROM ka_collection_categories WHERE id = (SELECT category_id FROM ka_collection_category_texts WHERE idk = $PRODUKTY AND slug = 'verze-37')" > /dev/null
# N37-9: without the Collections section a user sees only the categories on the site – a hidden one is neither listed on an
# item nor accepted as a filter (the same answer as an unknown one)
mcp save_collection_category '{"collection":"produkty","name":"Skrytá 37","slug":"skryta-37","visible":false}' > /dev/null
mcp save_collection_item '{"collection":"produkty","id":'"$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $PRODUKTY AND seo_link = 'stimulator'")"',"categories":["skryta-37"]}' > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n37-author', '', 'Author N37', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'n37-author'" > /dev/null
AUTHOR37="kaleta_$(printf '37%.0s' $(seq 1 24))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'author 37', '$(php -r 'echo hash("sha256", $argv[1]);' "$AUTHOR37")', '$(site_time)' FROM ka_uzivatele WHERE user = 'n37-author'" > /dev/null
mcp37() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $AUTHOR37" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"list_collection_items\",\"arguments\":$1}}"; }
expect "3.7 N37-9: list_collection_items hides a hidden category from a user without Collections, also as a filter; the editor sees and filters by it" \
  "$(mcp37 '{"collection":"produkty"}' | grep -c 'skryta-37' || true)|$(mcp37 '{"collection":"produkty","category":"skryta-37"}' | grep -c 'The category is not in this collection' || true)|$(mcp list_collection_items '{"collection":"produkty","category":"skryta-37"}' > "$WORK/response"; mcp_value total)" "0|1|1"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'author 37'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'n37-author'); DELETE FROM ka_uzivatele WHERE user = 'n37-author'" > /dev/null
# 3.9.2: a hidden category has no page of its own, but a list set to it still lists its items (the listing pages of a moved site)
mcp create_page '{"title":"Výpis skryté 37","slug":"vypis-skryte-37","visible":true}' > /dev/null
HIDDEN_LIST=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'vypis-skryte-37'")
mcp save_build '{"id":'"$HIDDEN_LIST"',"publish":true,"build":{"v":1,"children":[{"type":"section","children":[{"type":"heading","tag":"h1","content":{"text":"Skrytá kategorie"}},{"type":"collection_list","content":{"collection":"produkty","category":"skryta-37","empty_text":"Nic 37"},"children":[{"type":"heading","tag":"h3","content":{"text":"seo-{{seo}}"}}]}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "3.9.2: a list set to a hidden category lists its items" 200 /vypis-skryte-37 'seo-stimulator'
check "3.9.2: the hidden category itself still has no page" 404 /produkty/skryta-37
# N37-10: an address with a trailing newline is no second URL of a category page
check "3.7 N37-10: a category address with a trailing newline is a 404, not the category page" 404 "/produkty/bezecke-pasy/zdravotni%0A"
# N37-7: a form in the English item or category template is found when the English page posts it
mcp create_collection '{"name":"Formy 37","slug":"formy-37","item_pages":true,"fields":[{"label":"Popis","type":"text"}]}' > /dev/null
FORMY37=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'formy-37'")
form37() { php -r 'require $argv[1] . "/system/bootstrap.php"; [$b] = Kaleta\Builder\Build::sanitize(["v" => 1, "deti" => [["id" => "s" . $argv[2], "typ" => "sekce", "deti" => [["id" => $argv[2], "typ" => "formular",
  "obsah" => ["nazev" => "Enquiry 37", "bez_captcha" => true, "pole" => [["popisek" => "Name", "typ" => "text", "povinne" => true]]]]]]]], true); echo Kaleta\Builder\Build::toJson($b);' "$ROOT" "$1"; }
sq "INSERT INTO ka_kolekce_sablony (idk, jazyk, stavba, zmeneno) VALUES ($FORMY37, 'en', '$(form37 f37item)', '$(site_time)');
  INSERT INTO ka_collection_category_templates (idk, jazyk, stavba, zmeneno) VALUES ($FORMY37, 'en', '$(form37 f37cat)', '$(site_time)')" > /dev/null
SECRET37=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")
submit37() { local t; t=$(( $(date +%s) - 30 )); curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/en/form" -d "zdroj=$1" -d "prvek=$2" -d zpet=/en/formy-37/x -d "as_cas=$t" -d p0=Jana \
  -d "as_podpis=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$1|$2|$t" "$SECRET37")"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
expect "3.7 N37-7: a form of the English item template and of the English category template is found in its language" \
  "$(submit37 "kolekce:$FORMY37" f37item | grep -c 'form=f37item&result=ok' || true)|$(submit37 "kategorie:$FORMY37" f37cat | grep -c 'form=f37cat&result=ok' || true)|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE prvek IN ('f37item', 'f37cat')")" "1|1|2"
sq "DELETE FROM ka_poptavky WHERE prvek IN ('f37item', 'f37cat')" > /dev/null; mcp delete_collection '{"collection":"formy-37"}' > /dev/null
# previous / next item: in the collection order, within the item's category, a nav landmark with rel links
mcp save_build '{"collection":"produkty","publish":true,"build":{"v":1,"children":[{"type":"section","children":[{"type":"heading","tag":"h1","content":{"text":"{{nazev}}"}},{"type":"previous_next","content":{"previous_label":"Předchozí produkt","thumbnails":true}}]}]}}' > /dev/null
check "3.7: Previous / next item on an item page" 200 /produkty/pas-02 'aria-label="Předchozí a další položka"'
grep -q '<a class="ka-pd-predchozi" href="/produkty/pas-01" rel="prev">' "$WORK/response" && grep -q '<a class="ka-pd-dalsi" href="/produkty/pas-03" rel="next">' "$WORK/response" && grep -q 'Předchozí produkt' "$WORK/response" \
  && echo "  ok     3.7: the neighbours in the collection order, the own label" || { echo "  CHYBA  3.7 previous / next"; grep -o '<nav class="ka-predchozi-dalsi.\{0,400\}' "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "3.7: previous / next stays within the item's category (not the next item of the collection)" 200 /produkty/pas-13 '<a class="ka-pd-dalsi" href="/produkty/madla" rel="next">'
check "3.7: the first item of a category has no previous one" 200 /produkty/pas-01 'rel="next"'
grep -q 'rel="prev"' "$WORK/response" && { echo "  CHYBA  3.7 a previous item outside the category"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.7: no previous link before the first item of the category"
# the attachment limit of a form (3.7): its own limit, never above what the server accepts
FORM_MB=$(php -r '$b = fn ($v) => (int) $v * (["k" => 1024, "m" => 1048576, "g" => 1073741824][strtolower(substr(trim($v), -1))] ?? 1); $l = array_filter([$b(ini_get("upload_max_filesize")), $b(ini_get("post_max_size"))]); echo intdiv(min(25 * 1048576, $l ? min($l) : PHP_INT_MAX), 1048576);')
mcp create_page '{"title":"Výkresy","slug":"vykresy","visible":true}' > "$WORK/response"; DRAW_PAGE=$(mcp_value id)
draw_form() { mcp stavba_uloz "{\"id\":$DRAW_PAGE,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyk1\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Výkresy\",\"bez_captcha\":true,\"max_priloha\":$1,\"pole\":[{\"popisek\":\"Jméno\",\"typ\":\"text\",\"povinne\":true},{\"popisek\":\"Výkres\",\"typ\":\"soubor\",\"povinne\":false}]}}]}]}}" > /dev/null; }
draw_form 25
check "3.7: the form says the effective limit – its own, at most what the server accepts" 200 /vykresy "Nejvýš $FORM_MB MB: PDF"
head -c 1258291 /dev/zero > "$WORK/vykres.pdf"
draw_submit() { local t; t=$(( $(date +%s) - 30 )); curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=stranka:$DRAW_PAGE" -F prvek=vyk1 -F zpet=/vykresy -F "as_cas=$t" \
  -F "as_podpis=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|stranka:$DRAW_PAGE|vyk1|$t" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")" -F p0=Jana -F "p1=@$WORK/vykres.pdf;type=application/pdf"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null # earlier submissions from this address must not hit the limit
if [ "$FORM_MB" -ge 2 ]; then
  case "$(draw_submit)" in *result=ok*) echo "  ok     3.7: a 1.2 MB drawing passes a form with a higher limit";; *) echo "  CHYBA  3.7 attachment under the limit"; ERRORS=$((ERRORS+1));; esac
  draw_form 1
  check "3.7: the form's own lower limit is shown" 200 /vykresy "Nejvýš 1 MB: PDF"
  case "$(draw_submit)" in *result=pole*) echo "  ok     3.7: the same drawing is refused by a form with a 1 MB limit";; *) echo "  CHYBA  3.7 attachment over the form's limit"; ERRORS=$((ERRORS+1));; esac
fi
expect "3.7: a form without its own limit keeps 10 MB (the default)" "$(php -r 'require $argv[1] . "/system/bootstrap.php"; echo Kaleta\Builder\Elements\Form::attachmentLimit(["max_priloha" => 10]) === min(10 * 1048576, Kaleta\Core\Files::limit() ?: PHP_INT_MAX) ? "ok" : "no", "|", Kaleta\Builder\Build::fresh("formular")["obsah"]["max_priloha"];' "$ROOT")" "ok|10"

