echo "== kolekce"
check "kolekce" 200 "/admin.php?module=collections" "Kolekce"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$TOKEN" -d idk=0 --data-urlencode "nazev=Tým" -d detail=1 -d admin_menu=1 \
  --data-urlencode "pole[0][popisek]=Funkce" -d "pole[0][typ]=text" --data-urlencode "pole[1][popisek]=Foto" -d "pole[1][typ]=obrazek" --data-urlencode "pole[2][popisek]=Medailonek" -d "pole[2][typ]=html"
IDK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
expect "kolekce založena s poli" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM ka_kolekce WHERE idk = $IDK")" 1
save_item() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$IDK" -d idp=0 "$@"; }
save_item --data-urlencode "nazev=Jana Nováková" --data-urlencode "data[funkce]=Jednatelka" --data-urlencode "data[medailonek]=<p>Dvacet let <b>v oboru</b>.</p><script>x</script>" -d poradi=1 -d zobrazit=1
save_item --data-urlencode "nazev=Skrytý Člen" --data-urlencode "data[funkce]=Tajný" -d poradi=2
check "položky kolekce" 200 "/admin.php?module=collections&action=items&id=$IDK" "Jana Nováková"
# 3.18.3: the loop over the detail templates of the other languages overwrote $language, so the language filter always showed the
# last additional language (English) although the list was not filtered by it – and choosing it did nothing
grep -q '<option value="en" selected>' "$WORK/response" && { echo "  CHYBA  položky kolekce: filtr jazyka ukazuje English, i když nefiltruje"; ERRORS=$((ERRORS+1)); } || echo "  ok     položky kolekce: filtr jazyka bez výběru ukazuje všechny jazyky"
check "položky kolekce: filtr jazyka ukazuje vybranou češtinu" 200 "/admin.php?module=collections&action=items&id=$IDK&language=cs" '<option value="cs" selected>'
check "stránky: výběr jazyka ve filtru se použije hned" 200 "/admin.php?module=pages" '<select name="language" data-odeslat-pri-zmene>'
# 3.18.3 (owner's request): a collection in the left menu – a direct link to its items, on by default, current on the
# collection's screens instead of Collections, only for people who may open Collections; MCP admin_menu
COLL_LINK="admin.php?module=collections&amp;action=items&amp;id=$IDK\""
COLL_PALETTE="{\"n\":\"Tým\",\"u\":\"/admin.php?module=collections\\u0026action=items\\u0026id=$IDK\""
check "3.18.3: the new collection form has Show in the admin menu ticked" 200 "/admin.php?module=collections&action=new" 'name="admin_menu" value="1" checked'
expect "3.18.3: a collection saved with the default form is in the admin menu" "$(sq "SELECT admin_menu FROM ka_kolekce WHERE idk = $IDK")" 1
check "3.18.3: the dashboard menu has the collection's own item" 200 "/admin.php" 'class="menu-kolekce"'
grep -qF "$COLL_LINK><svg" "$WORK/response" && grep -qF '<span>Tým</span>' "$WORK/response" && grep -qF "$COLL_PALETTE" "$WORK/response" \
  && echo "  ok     3.18.3: … it leads to the collection's items and the quick search lists it" || { echo "  CHYBA  3.18.3: the collection's menu item or palette entry"; ERRORS=$((ERRORS+1)); }
check "3.18.3: the menu link opens the items list" 200 "/admin.php?module=collections&action=items&id=$IDK" 'Jana Nováková'
grep -qF "<li class=\"menu-kolekce aktivni\"><a href=\"/$COLL_LINK aria-current=\"page\">" "$WORK/response" && ! grep -qF 'admin.php?module=collections" aria-current' "$WORK/response" \
  && echo "  ok     3.18.3: on the items list the collection's item is current, not Collections" || { echo "  CHYBA  3.18.3: aria-current on the items list"; grep -o '<li[^>]*><a href="[^"]*collections[^>]*>' "$WORK/response" | head -5; ERRORS=$((ERRORS+1)); }
IDP_JANA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $IDK AND nazev = 'Jana Nováková'")
check "3.18.3: an item of the collection keeps its menu item current" 200 "/admin.php?module=collections&action=item&id=$IDK&item=$IDP_JANA" "class=\"menu-kolekce aktivni\""
check "3.18.3: the list of collections keeps Collections current" 200 "/admin.php?module=collections" 'admin.php?module=collections" aria-current="page"'
grep -qF 'menu-kolekce aktivni' "$WORK/response" && { echo "  CHYBA  3.18.3: a collection's item current on the list of collections"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.18.3: … and no collection's item"
code=$(curl -s -b "$JAR2" -o "$WORK/response" -w '%{http_code}' "$B/admin.php")
[ "$code" = 200 ] && grep -q 'menu-logout' "$WORK/response" && ! grep -q 'menu-kolekce' "$WORK/response" && ! grep -qF "$COLL_PALETTE" "$WORK/response" \
  && echo "  ok     3.18.3: an author without Collections sees no collection in the menu or the quick search" || { echo "  CHYBA  3.18.3: author menu ($code)"; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","admin_menu":false}' > "$WORK/response"
grep -q 'admin_menu\\":false' "$WORK/response" && expect "3.18.3 MCP: update_collection admin_menu false switches it off" "$(sq "SELECT admin_menu FROM ka_kolekce WHERE idk = $IDK")" 0 || { echo "  CHYBA  3.18.3 MCP update_collection admin_menu"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "3.18.3: the dashboard after switching it off" 200 "/admin.php"
grep -qF "$COLL_PALETTE" "$WORK/response" && ! grep -q 'menu-kolekce' "$WORK/response" \
  && echo "  ok     3.18.3: … the menu item is gone, the quick search still finds the collection" || { echo "  CHYBA  3.18.3: a collection out of the menu"; ERRORS=$((ERRORS+1)); }
check "3.18.3: the collection's settings show it unticked" 200 "/admin.php?module=collections&action=edit&id=$IDK" 'name="admin_menu" value="1">'
mcp list_collections '{}' > "$WORK/response"
grep -q 'admin_menu\\":false' "$WORK/response" && echo "  ok     3.18.3 MCP: list_collections returns admin_menu" || { echo "  CHYBA  3.18.3 MCP list_collections admin_menu"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","admin_menu":true}' > /dev/null
mcp create_collection '{"name":"Mimo menu","slug":"mimo-menu","admin_menu":false}' > "$WORK/response"
mcp create_collection '{"name":"V menu","slug":"v-menu"}' > /dev/null
expect "3.18.3 MCP: round trip on, create_collection with admin_menu false, and without it on" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', admin_menu) ORDER BY seo_link) FROM ka_kolekce WHERE seo_link IN ('tym', 'mimo-menu', 'v-menu')")|$(grep -c 'admin_menu\\":false' "$WORK/response")" "mimo-menu:0,tym:1,v-menu:1|1"
# the form: an unticked box saves the collection out of the menu
IDK_VMENU=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'v-menu'")
check "3.18.3: a collection created through MCP without admin_menu shows it ticked" 200 "/admin.php?module=collections&action=edit&id=$IDK_VMENU" 'name="admin_menu" value="1" checked'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$TOKEN" -d "idk=$IDK_VMENU" --data-urlencode "nazev=V menu" -d seo_link=v-menu
expect "3.18.3: the settings form saves an unticked box as out of the menu" "$(sq "SELECT admin_menu FROM ka_kolekce WHERE seo_link = 'v-menu'")" 0
# at most 5 collections in the menu (by name), then "More…" (3.25); the rest stay under Collections
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_kolekce (nazev, seo_link, pole) VALUES ('Cap 1', 'cap-1', '[]'), ('Cap 2', 'cap-2', '[]'), ('Cap 3', 'cap-3', '[]'), ('Cap 4', 'cap-4', '[]'), ('Cap 5', 'cap-5', '[]'), ('Cap 6', 'cap-6', '[]'), ('Cap 7', 'cap-7', '[]'), ('Cap 8', 'cap-8', '[]'), ('Cap 9', 'cap-9', '[]')"
check "3.18.3, 3.25: with more than 5 collections the menu shows 5" 200 "/admin.php" 'class="menu-kolekce"'
expect "3.18.3, 3.25: … exactly 5, by name, then More… to the list of collections" "$(grep -c 'class="menu-kolekce"' "$WORK/response")|$(grep -c '<span>Cap 9</span>' "$WORK/response")|$(grep -c '<li class="menu-kolekce menu-kolekce-dalsi"><a href="/admin.php?module=collections">.*<span>Další…</span>' "$WORK/response")" "5|0|1"
# 3.25 (C): the menu groups – collapsible, Czech labels, Statistics under Site care, no Company group; an author with a few
# items gets a flat list without groups (and without the group script)
check "3.25: the administrator's menu has collapsible groups" 200 "/admin.php" '<details class="menu-skupiny" data-menu-skupina="content" data-menu-vychozi open><summary class="menu-skupina"><span>Obsah</span>'
expect "3.25: … the group script, Statistics in Site care, no Company group, the Business details in Content" "$(grep -c 'src="/image/admin-menu.js?v=' "$WORK/response")|$(perl -0ne 'print m{data-menu-skupina="site-care".*?module=stats.*?</details>}s ? 1 : 0' "$WORK/response")|$(grep -c '<span>Firma</span>' "$WORK/response")|$(perl -0ne 'print m{data-menu-skupina="content"[^>]*>.*?module=business".*?</details>}s ? 1 : 0' "$WORK/response")" "1|1|0|1"
check "3.25: the current screen's group is marked" 200 "/admin.php?module=stats" 'data-menu-skupina="site-care" data-aktualni open>'
# 3.32 (U50): the chart is a group of bars, each an image with its text, described by a summary
expect "3.32 (U50): Statistics – the chart is a group described by its summary; every bar is an image" \
  "$(grep -c 'class="graf" role="group" aria-label="[^"]*" aria-describedby="graf-souhrn"' "$WORK/response")|$(grep -c 'id="graf-souhrn"' "$WORK/response")|$([ "$(grep -c 'class="graf-sloupec' "$WORK/response")" = "$(grep -c 'class="graf-sloupec[^"]*" data-tip="[^"]*" role="img"' "$WORK/response")" ] && echo same)" "1|1|same"
code=$(curl -s -b "$JAR2" -o "$WORK/response" -w '%{http_code}' "$B/admin.php")
expect "3.25: an author's short menu is a flat list – no group, no group script" "$code|$(grep -c 'menu-skupiny' "$WORK/response")|$(grep -c 'admin-menu.js' "$WORK/response")|$(grep -c '<li><a href="/admin.php?module=news">' "$WORK/response")" "200|0|0|1"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kolekce WHERE seo_link LIKE 'cap-_' OR seo_link IN ('mimo-menu', 'v-menu')"
# 3.19 (owner, 22 October 2026): an icon per collection – chosen in its settings from the built-in set, shown by its menu item
# and in the list of collections; MCP icon by the English name, validated, "" = the list icon again
HOUSE='<path d="M4 11 12 4l8 7"/>'; CAR='<circle cx="7" cy="17.5" r="1.7"/>'; LISTICON='<rect x="3.5" y="4.5" width="4" height="4" rx="1"/>'
check "3.19: the collection settings offer the icon picker, the list icon chosen" 200 "/admin.php?module=collections&action=edit&id=$IDK" 'class="ikony-volba" role="radiogroup"'
grep -qF 'name="icon" value="" checked' "$WORK/response" && grep -qF 'name="icon" value="dum">' "$WORK/response" && grep -qF "$HOUSE" "$WORK/response" \
  && echo "  ok     3.19: … every icon of the set with its picture, the list icon checked" || { echo "  CHYBA  3.19: the icon picker"; ERRORS=$((ERRORS+1)); }
# the owner's follow-up: twelve tiles first (the list icon and Collections::ICON_SHORTLIST), the rest under "More icons", closed
PICK319=$(sed -n '/class="ikony-volba"/,/<details/p' "$WORK/response" | grep -c 'name="icon"'); MORE319=$(sed -n '/<details class="ikony-dalsi"/,/<\/details>/p' "$WORK/response" | grep -c 'name="icon"')
expect "3.19: the picker shows a short selection of 12, the rest under a closed “More icons” (Další ikony)" "$PICK319|$(grep -c '<details class="ikony-dalsi">' "$WORK/response")|$(grep -c '<summary>Další ikony</summary>' "$WORK/response")|$((PICK319 + MORE319 > 40))" "12|1|1|1"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$TOKEN" -d "idk=$IDK" --data-urlencode "nazev=Tým" -d seo_link=tym -d admin_menu=1 -d detail=1 -d icon=dum \
  $(sq "SELECT pole FROM ka_kolekce WHERE idk = $IDK" | php -r '$i = 0; foreach (json_decode(stream_get_contents(STDIN), true) as $f) { foreach (["klic", "popisek", "typ"] as $k) { echo " --data-urlencode pole[$i][$k]=" . escapeshellarg((string) $f[$k]); } $i++; }' | xargs -n2 printf '%s %s ' 2>/dev/null)
expect "3.19: the settings form saves the chosen icon (and keeps the fields)" "$(sq "SELECT CONCAT(COALESCE(icon, '-'), '|', JSON_LENGTH(pole) > 0) FROM ka_kolekce WHERE idk = $IDK")" "dum|1"
check "3.19: the menu item of the collection shows its icon" 200 "/admin.php" 'class="menu-kolekce"'
grep -F "$COLL_LINK" "$WORK/response" | grep -qF "$HOUSE" && echo "  ok     3.19: … the house, not the list icon" || { echo "  CHYBA  3.19: the collection's icon in the menu"; grep -F "$COLL_LINK" "$WORK/response" | head -2; ERRORS=$((ERRORS+1)); }
check "3.19: the list of collections shows the icon" 200 "/admin.php?module=collections" 'class="kolekce-nazev"'
grep -A0 -F "action=items&amp;id=$IDK\"><svg" "$WORK/response" | grep -qF "$HOUSE" && echo "  ok     3.19: … next to the name of the collection" || { echo "  CHYBA  3.19: the icon in the list of collections"; ERRORS=$((ERRORS+1)); }
check "3.19: the settings show the saved icon checked" 200 "/admin.php?module=collections&action=edit&id=$IDK" 'name="icon" value="dum" checked'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$(csrf)" -d "idk=$IDK" --data-urlencode "nazev=Tým" -d seo_link=tym -d admin_menu=1 -d detail=1 -d 'icon=<svg>' \
  $(sq "SELECT pole FROM ka_kolekce WHERE idk = $IDK" | php -r '$i = 0; foreach (json_decode(stream_get_contents(STDIN), true) as $f) { foreach (["klic", "popisek", "typ"] as $k) { echo " --data-urlencode pole[$i][$k]=" . escapeshellarg((string) $f[$k]); } $i++; }' | xargs -n2 printf '%s %s ' 2>/dev/null)
expect "3.19: an icon outside the set is stored as none (the list icon)" "$(sq "SELECT COALESCE(icon, '-') FROM ka_kolekce WHERE idk = $IDK")" "-"
mcp update_collection '{"collection":"tym","icon":"car"}' > "$WORK/response"
expect "3.19 MCP: update_collection icon by its English name, stored as the key, answered in English" "$(sq "SELECT icon FROM ka_kolekce WHERE idk = $IDK")|$(grep -c 'icon\\":\\"car' "$WORK/response")" "auto|1"
check "3.19: … and the menu shows the car" 200 "/admin.php" "$CAR"
check "3.19: a chosen icon outside the short selection opens “More icons” by itself" 200 "/admin.php?module=collections&action=edit&id=$IDK" '<details class="ikony-dalsi" open>'
expect "3.19: … and the chosen car is among the first tiles, once" "$(sed -n '/class="ikony-volba"/,/<details/p' "$WORK/response" | grep -c 'name="icon" value="auto" checked')|$(grep -c 'name="icon" value="auto"' "$WORK/response")" "1|1"
mcp list_collections '{}' > "$WORK/response"
grep -q 'icon\\":\\"car' "$WORK/response" && echo "  ok     3.19 MCP: list_collections returns the icon" || { echo "  CHYBA  3.19 MCP list_collections icon"; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","icon":"castle"}' > "$WORK/response"
expect "3.19 MCP: an unknown icon is refused with the list, nothing changes" "$(grep -c 'Unknown icon' "$WORK/response")|$(sq "SELECT icon FROM ka_kolekce WHERE idk = $IDK")" "1|auto"
mcp create_collection '{"name":"Ikona MCP","slug":"ikona-mcp","icon":"people"}' > "$WORK/response"
mcp create_collection '{"name":"Ikona spatna","slug":"ikona-spatna","icon":"<b>"}' > /dev/null
expect "3.19 MCP: create_collection with an icon; with a bad one nothing is created" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', COALESCE(icon, '-'))) FROM ka_kolekce WHERE seo_link IN ('ikona-mcp', 'ikona-spatna')")|$(grep -c 'icon\\":\\"people' "$WORK/response")" "ikona-mcp:lide|1"
mcp update_collection '{"collection":"tym","icon":""}' > /dev/null
check "3.19 MCP: icon \"\" puts the list icon back" 200 "/admin.php" 'class="menu-kolekce"'
grep -F "$COLL_LINK" "$WORK/response" | grep -qF "$LISTICON" && expect "3.19: … in the menu and in the database" "$(sq "SELECT COALESCE(icon, '-') FROM ka_kolekce WHERE idk = $IDK")" "-" || { echo "  CHYBA  3.19: the list icon back"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kolekce WHERE seo_link = 'ikona-mcp'" > /dev/null
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"smy1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\"},\"deti\":[{\"id\":\"kar1\",\"typ\":\"kontejner\",\"styl\":{\"zaklad\":{\"pozadi\":\"plocha\"}},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>{{funkce}}</p>{{medailonek}}\"}},{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Profil\",\"odkaz\":\"{{url}}\"}}]}]}]}]}}" > "$WORK/response"
grep -q 'publikováno' "$WORK/response" || { echo "  CHYBA  MCP stránka s výpisem kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h3>Jana Nováková</h3>' "$WORK/response" && grep -q '<p>Jednatelka</p>' "$WORK/response" && grep -q '^<p>Dvacet let <b>v oboru</b>.</p>' "$WORK/response" && grep -q 'href="/tym/jana-novakova"' "$WORK/response" && ! grep -q 'Skrytý' "$WORK/response" \
  && echo "  ok     výpis kolekce na stránce (jen zveřejněné položky, hodnoty dosazené)" || { echo "  CHYBA  výpis kolekce"; ERRORS=$((ERRORS+1)); }
grep -q 'class="s-kar1"' "$WORK/response" && ! grep -q 'id="s-kar1"' "$WORK/response" && grep -q '\.s-kar1 { background-color' "$WORK/response" && ! grep -q '<script>x' "$WORK/response" \
  && echo "  ok     opakované prvky mají styl přes třídu, ne duplicitní id" || { echo "  CHYBA  styl ve výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "detail položky kolekce" 200 /tym/jana-novakova "Jednatelka"
check "detail má nadpis položky" 200 /tym/jana-novakova "<h1>Jana Nováková</h1>"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/tym/skryty-clen"); expect "skrytá položka nemá detail" "$code" 404
check "mapa webu obsahuje detail položky" 200 /sitemap.xml "/tym/jana-novakova"
check "šablona detailu v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK" 'id="stavitel-data"'
mcp seznam_kolekci '{}' > "$WORK/response"; grep -q 'kolekce\\":\\"tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: seznam kolekcí s poli" || { echo "  CHYBA  MCP seznam_kolekci"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Petr Svoboda","data":{"funkce":"Mistr truhlář"},"zobrazit":true}' > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Text JSON","values":"{\"funkce\":\"Z textu\"}"}' > "$WORK/response"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Z textu"}' | contains 'Text JSON' && echo "  ok     MCP: data poslaná jako text JSON se uloží" || { echo "  CHYBA  MCP data jako text JSON"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_menu '{"umisteni":"hlavni","polozky":"nejde precist"}' | contains 'musí být seznam' && echo "  ok     MCP: nečitelné položky menu jsou chyba, menu se nevrátí na automatické" || { echo "  CHYBA  MCP nečitelné položky menu"; ERRORS=$((ERRORS+1)); }
mcp save_classes '{"classes":[{"name":"x"}]}' | contains 'unknown_parameters' && echo "  ok     MCP: neznámý parametr je ve výsledku, ne tiše vynechaný" || { echo "  CHYBA  MCP neznámé parametry"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Spatna data","data":"funkce=x"}' | contains 'musí být objekt' && echo "  ok     MCP: nečitelná data položky jsou chyba, ne tiché vynechání" || { echo "  CHYBA  MCP nečitelná data položky"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman","adresa":"zdenek","zobrazit":true}' > "$WORK/response"
grep -q 'tym\\/zdenek' "$WORK/response" && echo "  ok     MCP: vlastní adresa položky" || { echo "  CHYBA  MCP adresa položky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}}]}]}}' > "$WORK/response"
PREVIEW=$(php -r '$o = json_decode(file_get_contents($argv[1]), true); echo json_decode($o["result"]["content"][0]["text"] ?? "{}", true)["nahled"] ?? "";' "$WORK/response")
[ -n "$PREVIEW" ] && curl -s "$PREVIEW" | contains 'Profil: ' && echo "  ok     MCP: šablona detailu kolekce jako koncept s podepsaným náhledem" || { echo "  CHYBA  MCP šablona detailu kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s "$B/tym/zdenek" | contains 'Profil: ' && { echo "  CHYBA  koncept šablony kolekce je vidět bez publikování"; ERRORS=$((ERRORS+1)); } || echo "  ok     koncept šablony kolekce návštěvník nevidí"
mcp publikuj_stavbu '{"kolekce":"tym"}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: publikovaná šablona detailu kolekce" 200 /tym/zdenek "Profil: Zdenek Zeman"
check "llms.txt vyjmenuje položky kolekcí s detailem" 200 /llms.txt "/tym/zdenek"
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zuzana Zelena","data":{"funkce":"Jednatelka"},"zobrazit":true}' > /dev/null
mcp stavba_uloz '{"kolekce":"tym","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}},{"typ":"kolekce","obsah":{"kolekce":"tym","filtr_pole":"funkce","filtr_hodnota":"{{funkce}}","bez_aktualni":true},"deti":[{"typ":"nadpis","znacka":"h3","obsah":{"text":"Kolega: {{nazev}}"}}]}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q 'Kolega: Zuzana Zelena' "$WORK/response" && ! grep -q 'Kolega: Jana' "$WORK/response" && ! grep -q 'Kolega: Petr' "$WORK/response" && ! curl -s "$B/tym/petr-svoboda" | contains 'Kolega: Zuzana' \
    && echo "  ok     související položky: filtr podle pole zobrazené položky, bez ní samotné" || { echo "  CHYBA  související položky kolekce"; ERRORS=$((ERRORS+1)); }
# a collection in several languages: an item's translation has the same slug, another language its own item template, breadcrumbs lead to the translation of the hub page
mcp vytvor_stranku '{"titulek":"Náš tým","adresa":"tym","text":"<p>Tým</p>","zobrazit":true}' > /dev/null; IDTYM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'tym'")
mcp vytvor_stranku "{\"titulek\":\"Our team\",\"adresa\":\"team\",\"jazyk\":\"en\",\"preklad_z\":$IDTYM,\"text\":\"<p>Team</p>\",\"zobrazit\":true}" > /dev/null
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman EN","adresa":"zdenek","jazyk":"en","data":{"funkce":"Workshop lead"},"zobrazit":true}' > "$WORK/response"
grep -q 'en\\/tym\\/zdenek\\"' "$WORK/response" && mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Druhy Zdenek","adresa":"zdenek","jazyk":"en"}' | contains 'tym\\/zdenek-2' \
  && echo "  ok     adresa položky je jedinečná v jazyce (překlad smí mít stejnou)" || { echo "  CHYBA  adresa položky v jiném jazyce"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/en/tym/zdenek" | contains 'Profil: Zdenek Zeman EN' && echo "  ok     jazyk bez vlastní šablony použije šablonu výchozího jazyka, koncept je skrytý" || { echo "  CHYBA  šablona detailu jazyka bez publikování"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu '{"kolekce":"tym","jazyk":"en"}' > /dev/null; mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/en/tym/zdenek"
grep -q '<h1>Profile: Zdenek Zeman EN</h1>' "$WORK/response" && grep -q 'href="/en/team">Our team</a>' "$WORK/response" && grep -q 'hreflang="cs" href="[^"]*/tym/zdenek"' "$WORK/response" \
  && curl -s "$B/tym/zdenek" | contains 'Profil: Zdenek Zeman<' && curl -s "$B/tym/zdenek" | contains 'hreflang="en" href="[^"]*/en/tym/zdenek"' \
  && echo "  ok     šablona detailu v jazyce, drobečky přes překlad rozcestníku, hreflang mezi překlady položky" || { echo "  CHYBA  kolekce ve více jazycích"; grep -o '<nav class="ka-drobecky.\{0,300\}' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q 'class="logo"[^>]*><img src="/image/kaleta-logo.svg"' "$WORK/response" && ! grep -q 'src="/en/image/' "$WORK/response" && echo "  ok     logo a obrázky šablony na jazykové verzi bez předpony jazyka" || { echo "  CHYBA  adresa loga s předponou jazyka"; ERRORS=$((ERRORS+1)); }
expect "verze šablony jazyka zvlášť" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'kolekce:$IDK:en'")" 1
mcp seznam_stranek '{}' | contains 'en\\/team' && echo "  ok     MCP: seznam stránek ukazuje adresu s předponou jazyka" || { echo "  CHYBA  MCP adresa stránky jazykové verze"; ERRORS=$((ERRORS+1)); }
check "šablona detailu jazyka v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK&language=en" 'en\/tym\/zdenek'
# translation via MCP: the page as a copy of the original's build, texts by id, the language's header and footer start as a copy of the default one
mcp vytvor_stranku '{"titulek":"Bez originalu","adresa":"bez-originalu","kopie_stavby":true}' | contains 'potřebuje preklad_z' \
  && expect "kopie stavby bez originálu stránku nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'bez-originalu'")" 0 || { echo "  CHYBA  kopie stavby bez preklad_z"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"From HTML\",\"adresa\":\"from-html\",\"jazyk\":\"en\",\"preklad_z\":$IDZ,\"kopie_stavby\":true}" > /dev/null
IDZEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'from-html'")
expect "překlad stránky začíná kopií stavby originálu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT n.stavba_koncept = COALESCE(o.stavba_koncept, o.stavba) FROM ka_stranky n JOIN ka_stranky o ON o.ids = n.preklad_z WHERE n.ids = $IDZEN")" 1
mcp get_build "{\"id\":$IDZEN,\"texts_only\":true}" > "$WORK/response"
grep -q 'texts' "$WORK/response" && grep -q '{{nazev}}' "$WORK/response" && ! grep -q '\\"build\\"' "$WORK/response" && ! grep -q 'kolekce\\":\\"tym' "$WORK/response" \
  && echo "  ok     MCP: jen texty stavby pro překlad (bez struktury a technických polí)" || { echo "  CHYBA  MCP texty stavby"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Klic navic","data":{"funkce":"x","nazev":"Jinak"}}' | contains 'nezname_klice.*nazev' \
  && echo "  ok     MCP: klíč, který kolekce nemá, je ve výsledku" || { echo "  CHYBA  MCP neznámý klíč položky"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku '{"titulek":"Skryta textem","adresa":"skryta-textem","zobrazit":"false"}' > /dev/null
expect "MCP: zobrazit poslané jako text „false“ nechá stránku skrytou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'skryta-textem'")" 0
mcp stavba_nacti '{"cast":"paticka","jazyk":"en"}' > /dev/null
expect "MCP: čtení části, která ještě není, nic nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE typ = 'paticka' AND jazyk = 'en'")" 0
mcp stavba_uprav '{"cast":"paticka","jazyk":"en","operace":[]}' > /dev/null
expect "patička nového jazyka začíná kopií patičky výchozího jazyka" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT e.stavba_koncept = COALESCE(c.stavba_koncept, c.stavba) FROM ka_casti e JOIN ka_casti c ON c.typ = e.typ AND c.jazyk = '' AND c.varianta = '' WHERE e.typ = 'paticka' AND e.jazyk = 'en' AND e.varianta = ''")" 1
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'testtoken123'); INSERT INTO ka_souhlasy (id_souhlasu, cas, kategorie) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '$(site_time)' - INTERVAL 40 MONTH, 'nic'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '$(site_time)', 'nic')"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "úklid maže staré záznamy o souhlasech s cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(LEFT(id_souhlasu, 1) ORDER BY id_souhlasu) FROM ka_souhlasy WHERE id_souhlasu IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')")" "b"
echo 'ALTER TABLE ka_neexistuje ADD COLUMN x INT;' > "$WORK/web/system/sql/migrace/9999-rozbita.sql"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '19' WHERE promenna = 'db_version'"; rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/web/storage/cache/migrace-chyba"
check "nepovedená migrace neshodí web" 200 /
grep -q 'The database migration failed' "$WORK/web/storage/log/chyby.log" && echo "  ok     nepovedená migrace je v protokolu chyb" || { echo "  CHYBA  nepovedená migrace chybí v protokolu"; ERRORS=$((ERRORS+1)); }
check "nepovedená migrace nezamkne administraci" 200 "/admin.php" "Aktualizace databáze se nepovedla"
rm -f "$WORK/web/system/sql/migrace/9999-rozbita.sql"; sed -i.bak "/The database migration failed/d" "$WORK/web/storage/log/chyby.log"; rm -f "$WORK/web/storage/log/chyby.log.bak"
expect "migrace, které prošly, zůstanou provedené" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "$LAST_MIGRATION"
mcp uprav_kolekci '{"kolekce":"tym","nazev":"Nas tym"}' > "$WORK/response"
grep -q 'Nas tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: úprava kolekce ponechá pole" || { echo "  CHYBA  MCP uprav_kolekci"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: nová položka je ve výpisu" 200 /z-html "Mistr truhlář"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyp1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\",\"pocet\":1,\"razeni\":\"nazev\",\"filtr_pole\":\"funkce\",\"filtry\":true,\"strankovani\":true},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}}]}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
[ "$(grep -o '<h3>[^<]*</h3>' "$WORK/response" | tr -d '\n')" = "<h3>Jana Nováková</h3>" ] && grep -q 'href="/z-html?s-vyp1=2"' "$WORK/response" && grep -q 'href="/z-html" aria-current="true">Vše' "$WORK/response" && grep -q 'f-vyp1=Mistr' "$WORK/response" \
  && echo "  ok     výpis kolekce: řazení, stránkování a tlačítka filtru" || { echo "  CHYBA  stránkování výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "výpis kolekce: druhá strana" 200 "/z-html?s-vyp1=2" "<h3>Petr Svoboda</h3>"
curl -s -o "$WORK/response" "$B/z-html?f-vyp1=Mistr+truhl%C3%A1%C5%99"; grep -q '<h3>Petr Svoboda</h3>' "$WORK/response" && ! grep -q '<h3>Jana' "$WORK/response" && grep -q 'aria-current="true">Mistr truhlář' "$WORK/response" \
  && echo "  ok     výpis kolekce: filtr návštěvníka" || { echo "  CHYBA  filtr výpisu kolekce"; ERRORS=$((ERRORS+1)); }

