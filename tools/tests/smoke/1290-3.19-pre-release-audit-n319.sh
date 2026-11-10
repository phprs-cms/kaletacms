echo "== 3.19: pre-release audit (N319)"
# N319-2: "cafe" and "café" are one old address for the database's unique key (the Czech collation) and two for the exact
# lookup – a person saving the second one is refused with the saved one named, never a silent retarget of the first
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=redirects"; TOKEN=$(csrf)
redirect_form -d z_adresy=/cafe-n319 -d na_adresu=/kontakt-39 -d typ=301
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" --data-urlencode "z_adresy=/café-n319" -d na_adresu=/o-nas-39 -d typ=301
expect "3.19 (N319-2) admin: /café-n319 next to /cafe-n319 is refused with the saved address named; /cafe-n319 keeps its target" \
  "$(grep -c 'už je uložená: /cafe-n319' "$WORK/response")|$(sq "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu)) FROM ka_presmerovani WHERE z_adresy = 'cafe-n319'")" "1|cafe-n319>kontakt-39"
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d z_adresy=/CAFE-n319 -d na_adresu=/o-nas-39 -d typ=301
expect "3.19 (N319-2) admin: the same address in other letter case is the same redirect – its target changes, no refusal" \
  "$(grep -c 'už je uložená' "$WORK/response")|$(sq "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu)) FROM ka_presmerovani WHERE z_adresy = 'cafe-n319'")" "0|cafe-n319>o-nas-39"
mcp save_redirects '{"redirects":[{"from":"/café-n319","to":"/kontakt-39","code":301}]}' > "$WORK/response"
expect "3.19 (N319-2) MCP save_redirects: the accent twin is refused and the saved one named" "$(mcp_value refused)|$(mcp_value results 0 existing)|$(sq "SELECT na_adresu FROM ka_presmerovani WHERE z_adresy = 'cafe-n319'")" "1|/cafe-n319|o-nas-39"
# an automatic writer (a changed slug, an import) has nobody to ask: the new address wins, the row it took over is an event
EVENTS319=$(sq "SELECT COALESCE(MAX(id), 0) FROM ka_events")
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); Kaleta\Admin\Modules\Redirects::add($app->db(), "café-n319", "kontakt-39", false);')
expect "3.19 (N319-2) automatic writer: café-n319 takes the row over with its exact form, recorded with what it replaced" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu, '>', source_norm)) FROM ka_presmerovani WHERE z_adresy = 'cafe-n319'")|$(sq "SELECT CONCAT(severity, '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$.replaced')), '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$.replaced_to'))) FROM ka_events WHERE id > $EVENTS319 AND type = 'redirect.replaced'")" \
  "café-n319>kontakt-39>café-n319|warning|/cafe-n319|/o-nas-39"
expect "3.19 (N319-2): … and /café-n319 redirects now" "$(get39 /caf%C3%A9-n319)" "301 $B/kontakt-39"
# N319-5: a row without its normal form (written by 3.18 after a rollback, a restored backup) still answers and is filled
sq "UPDATE ka_presmerovani SET source_norm = NULL WHERE z_adresy = 'café-n319'; INSERT INTO ka_presmerovani (z_adresy, source_norm, na_adresu, vytvoreno) VALUES ('?p=99319', NULL, 'kontakt-39', '$(site_time)')" > /dev/null
expect "3.19 (N319-5): redirects whose source_norm is NULL still answer (the path and ?p=), the path's row is normalised on the way" \
  "$(get39 /caf%C3%A9-n319)|$(get39 '/?p=99319')|$(sq "SELECT source_norm FROM ka_presmerovani WHERE z_adresy = 'café-n319'")" "301 $B/kontakt-39|301 $B/kontakt-39|café-n319"
sq "DELETE FROM ka_presmerovani WHERE z_adresy IN ('café-n319', '?p=99319')" > /dev/null
# N319-7: what leaves the site (stav.json, the heartbeat, the monthly report) carries no database message and no old addresses
HEALTH319_BEFORE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'health_token'), '')"); HEALTH319=n319healthtoken0123456789abcdef
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('health_token', '$HEALTH319')" > /dev/null
sq "UPDATE ka_nastaveni SET hodnota = '{\"reason\":\"database\",\"table\":\"stitky\",\"error\":\"ALTER command denied to user w319x at 10.9.9.9\"}' WHERE promenna = 'slugs_switch_failed';
  INSERT INTO ka_presmerovani (z_adresy, source_norm, na_adresu, vytvoreno) VALUES ('kolize-n319', 'kolize-n319', 'kontakt-39', '$(site_time)'), ('kolize%2Dn319', 'kolize-n319', 'o-nas-39', '$(site_time)')" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s "$B/stav.json?token=$HEALTH319" | tee "$WORK/stav319.raw" | php -r 'echo json_encode(json_decode(stream_get_contents(STDIN), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' > "$WORK/stav319.json"
LEAVING319=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo json_encode(Kaleta\Core\Language::runWith("en", fn (): array => Kaleta\Core\Health::checks($app, true), "admin-"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);')
expect "3.19 (N319-7): stav.json and the checks that leave the site say what is wrong (the address keys, the redirect groups) without the database message or the old addresses" \
  "$(grep -c 'w319x\|10.9.9.9\|kolize' "$WORK/stav319.json")|$(grep -c 'Databáze odmítla změnit klíče adres\|The database refused to change the keys' "$WORK/stav319.json")|$(grep -c 'skupin(a) přesměrování\|group(s) of redirects' "$WORK/stav319.json")|$(printf '%s' "$LEAVING319" | grep -c 'w319x\|kolize')|$(printf '%s' "$LEAVING319" | grep -c 'older key')" "0|1|1|0|1"
grep -q '"kontroly"' "$WORK/stav319.raw" || echo "         stav.json answered: $(head -c 300 "$WORK/stav319.raw")"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('health_token', '$HEALTH319_BEFORE')" > /dev/null
# N319-1: a site that is on stays on when the migration failed there – System status says what is missing and what to do
check "3.19 (N319-7, N319-1): System status shows the administrators the database message and the old addresses, and warns on a site that is on" 200 "/admin.php?module=status" 'Databáze odpověděla: ALTER command denied to user w319x'
grep -qF '/kolize-n319 = /kolize%2Dn319' "$WORK/response" && grep -qF 'tu dál je starší klíč' "$WORK/response" && echo "  ok     3.19 (N319-7, N319-1): … the colliding addresses listed, the on-site wording" || { echo "  CHYBA  3.19 N319-7 System status details"; ERRORS=$((ERRORS+1)); }
check "3.19 (N319-1): Settings → Languages offers the ticked switch on an on site with a recorded failure" 200 "/admin.php?module=settings&tab=languages" 'name="slugs_per_language" value="1" checked'
sq "DELETE FROM ka_presmerovani WHERE z_adresy IN ('kolize-n319', 'kolize%2Dn319')" > /dev/null
# N319-3: Settings → General runs migration 0106 under the migration lock – two at once wait for each other, both end well
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo json_encode(Kaleta\Core\Slug::enableEverywhere($app->db(), $app->settings()));' > "$WORK/enable319a.txt") & E319A=$!
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo json_encode(Kaleta\Core\Slug::enableEverywhere($app->db(), $app->settings()));' > "$WORK/enable319b.txt") & E319B=$!
wait $E319A $E319B
expect "3.19 (N319-3): two runs of the switch at once – both done, the recorded failure cleared, the site on" \
  "$(cat "$WORK/enable319a.txt")|$(cat "$WORK/enable319b.txt")|$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '|', hodnota) FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'")" "null|null|1|"
# N319-8: create_collection with a preset checks the icon before anything is created
COLLS319=$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce), '|', (SELECT COUNT(*) FROM ka_stranky))")
mcp create_collection '{"name":"Ikona preset 319","preset":"people","icon":"unicorn"}' > "$WORK/response"
expect "3.19 (N319-8): a preset with an unknown icon is refused and creates no collection and no hidden page" "$(grep -c 'Unknown icon' "$WORK/response")|$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce), '|', (SELECT COUNT(*) FROM ka_stranky))")" "1|$COLLS319"
# N319-9: a store locator that loads its map at once in a collection's template of another language or a category template
IDK319=$(sq "SELECT MIN(idk) FROM ka_kolekce")
maponce319() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo Kaleta\Core\Privacy::mapAtOnce(Kaleta\Core\App::boot()->db()) ? 1 : 0;'); }
MAP319=$(maponce319)
sq "INSERT INTO ka_kolekce_sablony (idk, jazyk, stavba) VALUES ($IDK319, 'xx-n319', '{\"v\":1,\"deti\":[{\"id\":\"p1\",\"typ\":\"pobocky\",\"obsah\":{\"mapa\":true,\"mapa_hned\":true}}]}')" > /dev/null
MAP319="$MAP319|$(maponce319)"
sq "DELETE FROM ka_kolekce_sablony WHERE jazyk = 'xx-n319'; INSERT INTO ka_collection_category_templates (idk, jazyk, stavba) VALUES ($IDK319, 'xx-n319', '{\"v\":1,\"deti\":[{\"id\":\"p1\",\"typ\":\"sekce\",\"deti\":[{\"id\":\"p2\",\"typ\":\"pobocky\",\"obsah\":{\"mapa_hned\":true}}]}]}')" > /dev/null
MAP319="$MAP319|$(maponce319)"
sq "UPDATE ka_collection_category_templates SET stavba = REPLACE(stavba, '{\"mapa_hned\":true}', '{\"mapa\":false,\"mapa_hned\":true}') WHERE jazyk = 'xx-n319'" > /dev/null
MAP319="$MAP319|$(maponce319)"
sq "DELETE FROM ka_collection_category_templates WHERE jazyk = 'xx-n319'" > /dev/null
expect "3.19 (N319-9): the privacy check finds the map loaded at once in an item template of another language and in a category template, not with the map off" "$MAP319" "0|1|1|0"

