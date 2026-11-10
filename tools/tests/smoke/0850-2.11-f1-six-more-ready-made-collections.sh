echo "== 2.11 F1: six more ready-made collections"
# preset => "preset|item pages|schema type|number of fields|hidden list page" (system/presets/<preset>.php); the services come first so that a reference can link to them
preset_row() { sq "SELECT CONCAT(k.preset, '|', k.detail, '|', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(k.schema_org, '\$.typ')), '-'), '|', JSON_LENGTH(k.pole), '|', (SELECT COUNT(*) FROM ka_stranky s WHERE s.seo_link = k.seo_link AND s.zobrazit = 0)) FROM ka_kolekce k WHERE k.seo_link = '$1'"; }
mcp create_collection '{"name":"Preset služby","preset":"services"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "presets: services – item pages, Service schema, five fields, a hidden list page" "$(preset_row preset-sluzby)" "services|1|Service|5|1" || { echo "  CHYBA  create_collection preset services"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "presets: the Service schema maps the price to price_from" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.price')) FROM ka_kolekce WHERE seo_link = 'preset-sluzby'")" "price_from"
mcp create_collection '{"name":"Preset reference","preset":"references"}' > /dev/null
expect "presets: references – item pages, no schema, seven fields (the service link included), a hidden list page" "$(preset_row preset-reference)" "references|1|-|7|1"
expect "presets: the service field of a reference links to the services collection" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].kolekce'))) FROM ka_kolekce WHERE seo_link = 'preset-reference'")" "service|polozka|preset-sluzby"
mcp create_collection '{"name":"Preset ceník","preset":"price_list"}' > /dev/null
expect "presets: price list – no item pages, four fields, a hidden list page" "$(preset_row preset-cenik)" "price_list|0|-|4|1"
expect "presets: the price list page filters by category with buttons, sorted by order" "$(sq "SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', '|', stavba LIKE '%\"filtry\":true%', '|', stavba LIKE '%<p>{{price}}</p>%') FROM ka_stranky WHERE seo_link = 'preset-cenik'")" "1|1|1"
mcp create_collection '{"name":"Preset FAQ","preset":"faq"}' > /dev/null
expect "presets: questions and answers – no item pages, FAQPage schema, two fields, a hidden list page" "$(preset_row preset-faq)" "faq|0|FAQPage|2|1"
mcp create_collection '{"name":"Preset stroje","preset":"machines"}' > /dev/null
expect "presets: machines – item pages, Product schema with the model as SKU, six fields" "$(preset_row preset-stroje)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.sku')) FROM ka_kolekce WHERE seo_link = 'preset-stroje'")" "machines|1|Product|6|1|model"
mcp create_collection '{"name":"Preset kurzy","preset":"courses"}' > "$WORK/response"
expect "presets: courses – item pages, Event schema, seven fields, a hidden list page" "$(preset_row preset-kurzy)" "courses|1|Event|7|1"
expect "presets: the courses page lists the upcoming ones by start and end, sorted by the start" "$(sq "SELECT CONCAT(stavba LIKE '%\"obdobi\":\"nadchazejici\"%', '|', stavba LIKE '%\"obdobi_od\":\"start\"%', '|', stavba LIKE '%\"razeni_pole\":\"start\"%', '|', stavba LIKE '%<p>{{start}}</p>%') FROM ka_stranky WHERE seo_link = 'preset-kurzy'")" "1|1|1|1"
expect "presets: the course item template comes from the preset (the dates, the place, the registration form)" "$(sq "SELECT CONCAT(stavba LIKE '%<strong>{{when}}</strong>%', '|', stavba LIKE '%{{capacity}}%', '|', stavba LIKE '%\"typ\":\"formular\"%') FROM ka_kolekce WHERE seo_link = 'preset-kurzy'")" "1|1|1"
FUTURE_DAY=$(site_time "+30 days" "Y-m-d"); PAST_DAY=$(site_time "-30 days" "Y-m-d")
mcp save_collection_item "{\"collection\":\"preset-kurzy\",\"name\":\"Kurz svařování\",\"slug\":\"kurz-svarovani\",\"values\":{\"start\":\"$FUTURE_DAY 09:00\",\"end\":\"$FUTURE_DAY 16:00\",\"place\":\"Brno\",\"price\":\"1900\"},\"visible\":true}" > "$WORK/response"
contains -q 'kurz-svarovani' "$WORK/response" && echo "  ok     presets: a course with a future start" || { echo "  CHYBA  save_collection_item (course)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"preset-kurzy\",\"name\":\"Kurz loňský\",\"slug\":\"kurz-lonsky\",\"values\":{\"start\":\"$PAST_DAY 09:00\",\"place\":\"Praha\"},\"visible\":true}" > /dev/null
check "presets: the course page shows the place and the formatted start" 200 /preset-kurzy/kurz-svarovani "Brno"
grep -q '"Event"' "$WORK/response" && grep -q '"startDate"' "$WORK/response" && echo "  ok     presets: the course page carries the Event structured data" || { echo "  CHYBA  course Event schema"; ERRORS=$((ERRORS+1)); }
# the hidden list page in the administrator's preview shows only the course still to come
curl -s -b "$JAR" -o "$WORK/response" "$B/preset-kurzy?build=koncept"
grep -q 'Kurz svařování' "$WORK/response" && ! grep -q 'Kurz loňský' "$WORK/response" && echo "  ok     presets: the courses list shows the future course and not the past one" || { echo "  CHYBA  courses list by date"; grep -o 'Kurz [a-zě]*' "$WORK/response" | sort -u; ERRORS=$((ERRORS+1)); }

