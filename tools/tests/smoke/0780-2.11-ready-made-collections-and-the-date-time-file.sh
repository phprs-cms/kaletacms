echo "== 2.11: ready-made collections and the date-time, file and location fields"
mcp list_collection_presets '{}' > "$WORK/response"
contains -q 'preset\\":\\"people' "$WORK/response" && contains -q 'how_to_use' "$WORK/response" && contains -q 'existing_collection\\":\\"' "$WORK/response" && echo "  ok     MCP: list_collection_presets lists the presets with their fields and instructions" || { echo "  CHYBA  list_collection_presets"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_collection '{"name":"Náš tým","preset":"people"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "presets: the collection remembers its preset and gets English field keys" "$(sq "SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[3].klic')), '|', nazev) FROM ka_kolekce WHERE seo_link = 'nas-tym'")" "people|phone|Náš tým" \
  || { echo "  CHYBA  create_collection preset people (2.11)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "presets: a hidden page lists the new collection" "$(sq "SELECT CONCAT(zobrazit, '|', stavba LIKE '%\"kolekce\":\"nas-tym\"%', '|', stavba LIKE '%{{photo}}%') FROM ka_stranky WHERE seo_link = 'nas-tym'")" "0|1|1"
contains -q 'list_page' "$WORK/response" && echo "  ok     presets: Claude is told about the hidden list page" || { echo "  CHYBA  list_page"; ERRORS=$((ERRORS+1)); }
expect "presets: the team gets Person structured data mapped to its fields" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.telephone')) FROM ka_kolekce WHERE seo_link = 'nas-tym'")" "phone"
mcp create_collection '{"name":"Nesmysl","preset":"nothing-like-it"}' > "$WORK/response"
contains -q 'list_collection_presets' "$WORK/response" && echo "  ok     presets: an unknown preset names the known ones" || { echo "  CHYBA  unknown preset"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "presets: the collections list offers the ready-made collections" 200 "/admin.php?module=collections" 'name="preset" value="people"'
mcp create_collection '{"name":"Typy polí","slug":"typy-poli","item_pages":true,"fields":[{"label":"Začátek","type":"datetime"},{"label":"Ceník","type":"file"},{"label":"Místo","type":"location"}]}' > /dev/null
expect "fields: Claude's datetime, file and location types" "$(sq "SELECT GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT(pole, CONCAT('\$[', n.i, '].typ'))) ORDER BY n.i) FROM ka_kolekce, (SELECT 0 i UNION SELECT 1 UNION SELECT 2) n WHERE seo_link = 'typy-poli'")" "termin,soubor,poloha"
mcp save_collection_item '{"collection":"typy-poli","name":"Den otevřených dveří","slug":"den-otevrenych-dveri","values":{"zacatek":"2026-11-02T17:00","cenik":"/media/cenik-2026.pdf","misto":"49.1951;16.6068"},"visible":true}' > "$WORK/response"
expect "fields: the date and time, the file and the location are stored clean" "$(sq "SELECT data->>'\$.zacatek', data->>'\$.cenik', data->>'\$.misto' FROM ka_kolekce_polozky WHERE seo_link = 'den-otevrenych-dveri'" | tr '\t' '|')" "2026-11-02 17:00|/media/cenik-2026.pdf|49.1951, 16.6068"
curl -s -o "$WORK/response" "$B/typy-poli/den-otevrenych-dveri"
grep -q '2. 11. 2026 17:00' "$WORK/response" && grep -q 'href="[^"]*media/cenik-2026.pdf"' "$WORK/response" && grep -q 'cenik-2026.pdf)' "$WORK/response" \
  && echo "  ok     fields: the item page shows the day and time and a button to the file with its name" || { echo "  CHYBA  stránka položky s termínem a souborem"; ERRORS=$((ERRORS+1)); }
check "fields: the item form has a date-time input and a Media file picker" 200 "/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'typy-poli'")&item=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'den-otevrenych-dveri'")" 'type="datetime-local" id="pole-zacatek" name="data\[zacatek\]" value="2026-11-02T17:00"'
grep -q 'data-soubor' "$WORK/response" && echo "  ok     fields: the file field opens Media" || { echo "  CHYBA  data-soubor"; ERRORS=$((ERRORS+1)); }
# the period of a Collection list (2.11): upcoming, current and past by a start and an end field – the SQL condition run on real rows
mcp create_collection '{"name":"Období","slug":"obdobi-test","fields":[{"label":"Od","type":"datetime"},{"label":"Do","type":"datetime"}]}' > /dev/null
# dates on the site's clock (site_date, at the top) – the CI runner's shell is UTC, and near midnight they differ
YESTERDAY_D=$(site_date yesterday); TODAY_D=$(site_date today); TOMORROW_D=$(site_date tomorrow)
for row in "vcera|$YESTERDAY_D 10:00|" "dnes-cely-den|$TODAY_D|" "zitra|$TOMORROW_D 09:00|" "probiha|$YESTERDAY_D|$TOMORROW_D" "vyveseno|$YESTERDAY_D|" "bez-data||"; do
  IFS='|' read -r slug od do <<< "$row"
  mcp save_collection_item "{\"collection\":\"obdobi-test\",\"name\":\"$slug\",\"slug\":\"$slug\",\"values\":{\"od\":\"$od\",\"do\":\"$do\"},\"visible\":true}" > /dev/null
done
period() { php -r 'require $argv[1] . "/system/bootstrap.php"; [$sql, $p] = Kaleta\Builder\Collections::periodCondition($argv[2], "od", $argv[3], date("Y-m-d H:i")); echo str_replace("?", "'"'"'" . $p[0] . "'"'"'", $sql);' "$ROOT" "$1" "$2"; }
in_period() { sq "SELECT GROUP_CONCAT(seo_link ORDER BY seo_link) FROM ka_kolekce_polozky WHERE idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 'obdobi-test') AND $(period "$1" "$2")"; }
expect "period: upcoming – not ended (today's whole day counts, an event with an end that has not passed too)" "$(in_period nadchazejici do)" "dnes-cely-den,probiha,zitra"
expect "period: past – ended yesterday" "$(in_period minule do)" "vcera,vyveseno"
expect "period: current – started and not ended; without an end it stays up" "$(in_period probihajici do)" "dnes-cely-den,probiha,vcera,vyveseno"
expect "period: current without an end field – only the start's day" "$(in_period probihajici '')" "dnes-cely-den"
