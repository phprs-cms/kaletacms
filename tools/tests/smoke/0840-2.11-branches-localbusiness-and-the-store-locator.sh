echo "== 2.11: branches (LocalBusiness) and the store locator"
mcp create_collection '{"name":"Pobočky","preset":"branches"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "branches: the collection remembers its preset, has a location field and LocalBusiness data from it" \
  "$(sq "SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.geo')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.openingHours'))) FROM ka_kolekce WHERE seo_link = 'pobocky'")" "branches|location|poloha|location|hours" \
  || { echo "  CHYBA  create_collection preset branches"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "branches: the item template brings the photo, the hours and a click-to-load map of the address" "$(sq "SELECT CONCAT(stavba LIKE '%{{photo}}%', stavba LIKE '%<p>{{hours}}</p>%', stavba LIKE '%\"adresa\":\"{{address}}\"%') FROM ka_kolekce WHERE seo_link = 'pobocky'")" "111"
mcp save_collection_item '{"collection":"pobocky","name":"Brno","slug":"brno","values":{"address":"Náměstí Svobody 1, 602 00 Brno","location":"49.1951, 16.6068","phone":"+420 123 456 789","email":"brno@example.com","hours":"Mo-Fr 9-17\nSa 9-12"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"pobocky","name":"Praha","slug":"praha","values":{"address":"Václavské náměstí 1, 110 00 Praha","location":"50.0813, 14.4275","phone":"+420 987 654 321","hours":"by appointment"},"visible":true}' > /dev/null
mcp create_page '{"title":"Kde nás najdete","slug":"kde-nas-najdete","visible":true}' > /dev/null
LOCATOR_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'kde-nas-najdete'")
mcp save_build "{\"id\":$LOCATOR_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"store_locator\"}]}]}}" > "$WORK/response"
contains -q 'published' "$WORK/response" && expect "store locator: Claude places the element by its English name, stored under its own type" "$(sq "SELECT stavba LIKE '%\"typ\":\"pobocky\"%' FROM ka_stranky WHERE ids = $LOCATOR_PAGE")" "1" \
  || { echo "  CHYBA  save_build store_locator"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp builder_schema '{"elements":["store_locator"]}' > "$WORK/response"
contains -q 'location_field' "$WORK/response" && contains -q 'show_map' "$WORK/response" && echo "  ok     store locator: builder_schema describes the element and its English options" || { echo "  CHYBA  builder_schema store_locator"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kde-nas-najdete"
grep -q 'data-pobocky' "$WORK/response" && grep -q 'href="[^"]*/pobocky/brno"' "$WORK/response" && grep -q 'href="[^"]*/pobocky/praha"' "$WORK/response" \
  && echo "  ok     store locator: the page lists both branches with links to their pages (no JavaScript needed)" || { echo "  CHYBA  store locator list"; ERRORS=$((ERRORS+1)); }
grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q 'href="tel:+420987654321"' "$WORK/response" && grep -q 'href="mailto:brno@example.com"' "$WORK/response" \
  && echo "  ok     store locator: phones as tel: links, the e-mail as mailto:" || { echo "  CHYBA  store locator tel/mailto"; ERRORS=$((ERRORS+1)); }
grep -q 'maps/search/?api=1&amp;query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody' "$WORK/response" && grep -q '>Trasa<' "$WORK/response" && echo "  ok     store locator: a Directions link to a maps search of the address" || { echo "  CHYBA  store locator directions"; ERRORS=$((ERRORS+1)); }
grep -q 'data-lat="49.1951" data-lng="16.6068"' "$WORK/response" && grep -q 'data-lat="50.0813" data-lng="14.4275"' "$WORK/response" && grep -q 'data-text="brno n' "$WORK/response" \
  && echo "  ok     store locator: data-lat/data-lng and the search text for the script" || { echo "  CHYBA  store locator data attributes"; ERRORS=$((ERRORS+1)); }
grep -q 'data-hledat' "$WORK/response" && grep -q 'data-nejblizsi>Nejblíže ke mně<' "$WORK/response" && grep -q 'data-mapa aria-controls="pobocky-mapa-' "$WORK/response" && grep -q 'class="ka-pobocky-mapa" id="pobocky-mapa-' "$WORK/response" \
  && grep -q 'data-leaflet="[^"]*/image/vendor/leaflet/"' "$WORK/response" && grep -q 'data-atribuce="© OpenStreetMap contributors"' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     store locator: search, nearest and map controls in Czech, the Leaflet path and attribution, web.js kept on the page" || { echo "  CHYBA  store locator controls"; ERRORS=$((ERRORS+1)); }
check "store locator: Leaflet 1.9.4 is served from the site itself" 200 "/image/vendor/leaflet/leaflet.js" "Leaflet 1.9.4"
check "store locator: the Leaflet stylesheet and marker are there" 200 "/image/vendor/leaflet/leaflet.css" "leaflet-marker-icon"
check "store locator: the Leaflet licence is shipped" 200 "/image/vendor/leaflet/LICENSE" "BSD 2-Clause"
# the structured data of a branch page: the LocalBusiness node of the graph (the company node is there too, so the whole page is not enough)
branch_node() { php -r 'preg_match("#<script type=\"application/ld\+json\">(.*?)</script>#s", (string) file_get_contents($argv[1]), $m); foreach (json_decode($m[1] ?? "{}", true)["@graph"] ?? [] as $n) { if (($n["@type"] ?? "") === "LocalBusiness") { echo json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); } }' -- "$WORK/response"; }
curl -s -o "$WORK/response" "$B/pobocky/brno"
BRNO_NODE=$(branch_node)
case "$BRNO_NODE" in *'"geo":{"@type":"GeoCoordinates","latitude":49.1951,"longitude":16.6068}'*'"openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday","Tuesday","Wednesday","Thursday","Friday"],"opens":"09:00","closes":"17:00"},{"@type":"OpeningHoursSpecification","dayOfWeek":["Saturday"],"opens":"09:00","closes":"12:00"}]'*'"parentOrganization":{"@id":'*)
  echo "  ok     branches: the branch page carries LocalBusiness data with the geo, the opening hours and the company as parent";;
  *) echo "  CHYBA  LocalBusiness JSON-LD (brno)"; echo "$BRNO_NODE" | head -c 600; ERRORS=$((ERRORS+1));; esac
case "$BRNO_NODE" in *'"telephone":"+420 123 456 789"'*'"email":"brno@example.com"'*) echo "  ok     branches: address, phone and e-mail in the structured data";; *) echo "  CHYBA  LocalBusiness contacts"; ERRORS=$((ERRORS+1));; esac
grep -q 'data-vlozit="https://maps.google.com/maps?q=N%C3%A1m%C4%9Bst%C3%AD%20Svobody' "$WORK/response" && grep -q '+420 123 456 789' "$WORK/response" \
  && echo "  ok     branches: the branch page shows the contacts and the map of its own address loading after a click" || { echo "  CHYBA  branch page map"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/pobocky/praha"
PRAHA_NODE=$(branch_node)
case "$PRAHA_NODE" in *openingHoursSpecification*) echo "  CHYBA  hours that do not parse must be left out"; ERRORS=$((ERRORS+1));; *'"latitude":50.0813'*) echo "  ok     branches: hours that do not parse are left out, the geo stays";; *) echo "  CHYBA  LocalBusiness JSON-LD (praha)"; echo "$PRAHA_NODE" | head -c 400; ERRORS=$((ERRORS+1));; esac
check "branches: the collection form offers the LocalBusiness type with its properties" 200 "/admin.php?module=collections&action=edit&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'pobocky'")" 'value="LocalBusiness"'
grep -q 'name="schema\[pole\]\[openingHours\]"' "$WORK/response" && grep -q 'name="schema\[pole\]\[geo\]"' "$WORK/response" && echo "  ok     branches: geo and opening hours can be mapped in the form" || { echo "  CHYBA  schema form LocalBusiness"; ERRORS=$((ERRORS+1)); }
# a team created after the branches links each person to a branch (system/presets/people.php: branch → preset branches)
mcp create_collection '{"name":"Tým poboček","preset":"people"}' > /dev/null
expect "branches: a team made afterwards gets the branch field linked to the branches" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'tym-pobocek' AND pole LIKE '%\"klic\":\"branch\"%\"typ\":\"polozka\",\"kolekce\":\"pobocky\"%'")" "1"
