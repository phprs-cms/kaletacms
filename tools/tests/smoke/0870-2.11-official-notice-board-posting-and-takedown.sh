echo "== 2.11: official notice board – posting and takedown dates, permanent archive, audit trail"
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('notices', '$(site_time)' + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)" > /dev/null # the job runs only when the test asks (a day ahead: MySQL and PHP may be in different time zones)
N_YESTERDAY=$(site_time "-1 day" "Y-m-d"); N_TOMORROW=$(site_time "+1 day" "Y-m-d"); N_TEN_AGO=$(site_time "-10 day" "Y-m-d")
N_YESTERDAY_CZ=$(site_time "-1 day" "j. n. Y"); N_TOMORROW_CZ=$(site_time "+1 day" "j. n. Y")
mcp create_collection '{"name":"Úřední deska","preset":"notices"}' > "$WORK/response"
expect "notices: the collection with its board and its archive page, both hidden, each listing its period" "$(sq "SELECT CONCAT((SELECT preset FROM ka_kolekce WHERE seo_link = 'uredni-deska'), '|', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('uredni-deska', 'uredni-deska-archive') AND zobrazit = 0), '|', (SELECT stavba LIKE '%\"obdobi\":\"probihajici\"%' FROM ka_stranky WHERE seo_link = 'uredni-deska'), '|', (SELECT stavba LIKE '%\"obdobi\":\"minule\"%' AND stavba LIKE '%\"kolekce\":\"uredni-deska\"%' FROM ka_stranky WHERE seo_link = 'uredni-deska-archive'), '|', (SELECT titulek FROM ka_stranky WHERE seo_link = 'uredni-deska-archive'))")" "notices|2|1|1|Úřední deska – archiv" # 3.9.2: what a preset writes is in the site language, over MCP too (Presets::createWithPage)
contains -q 'more_pages' "$WORK/response" && contains -q 'uredni-deska-archive' "$WORK/response" && echo "  ok     notices: Claude is told about the archive page" || { echo "  CHYBA  more_pages"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "notices: the item template comes from the preset with the status line" "$(sq "SELECT stavba LIKE '%{{notice_status}}%' FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" "1"
BOARD_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'uredni-deska'")
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Záměr pronájmu\",\"slug\":\"zamer-pronajmu\",\"values\":{\"posted\":\"$N_YESTERDAY\",\"taken_down\":\"$N_TOMORROW\",\"reference\":\"MU/2026/41\",\"issuer\":\"Městský úřad\",\"category\":\"Majetek\",\"summary\":\"Záměr pronajmout pozemek.\"},\"visible\":true}" > "$WORK/response"; NOTICE_A=$(mcp_value id)
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Rozpočet 2026\",\"slug\":\"rozpocet-2026\",\"values\":{\"posted\":\"$N_TEN_AGO\",\"taken_down\":\"$N_YESTERDAY\",\"reference\":\"MU/2026/12\",\"category\":\"Rozpočet\"},\"visible\":true}" > "$WORK/response"; NOTICE_B=$(mcp_value id)
mcp save_collection_item '{"collection":"uredni-deska","name":"Budoucí vyhláška","slug":"budouci","values":{"posted":"2099-01-01"}}' > "$WORK/response"
expect "notices: a notice still to be posted may stay hidden" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE seo_link = 'budouci'")" "0"
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Skrytá minulá\",\"values\":{\"posted\":\"$N_YESTERDAY\"}}" > "$WORK/response"
contains -q 'cannot be hidden' "$WORK/response" && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Skrytá minulá'")" = 0 ] && echo "  ok     MCP: a notice whose posting day has come cannot be created hidden" || { echo "  CHYBA  hidden notice created"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
for slug in uredni-deska uredni-deska-archive; do mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = '$slug'"),\"visible\":true}" > /dev/null; done
curl -s -o "$WORK/response" "$B/uredni-deska"
grep -q 'Záměr pronájmu' "$WORK/response" && grep -q 'MU/2026/41' "$WORK/response" && ! grep -q 'Rozpočet 2026' "$WORK/response" && grep -q 'Majetek' "$WORK/response" \
  && echo "  ok     notices: the board shows the current notice with its reference and the category filter, not the archived one" || { echo "  CHYBA  board page"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/uredni-deska-archive"
grep -q 'Rozpočet 2026' "$WORK/response" && ! grep -q 'Záměr pronájmu' "$WORK/response" && echo "  ok     notices: the archive shows the notice taken down yesterday, not the current one" || { echo "  CHYBA  archive page"; ERRORS=$((ERRORS+1)); }
check "notices: the item page says from when to when the notice is posted" 200 /uredni-deska/zamer-pronajmu "Vyvěšeno od $N_YESTERDAY_CZ do $N_TOMORROW_CZ"
grep -q 'Městský úřad' "$WORK/response" && echo "  ok     notices: the item page has the issuer" || { echo "  CHYBA  item page"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/uredni-deska/rozpocet-2026" # as a visitor: the page must not land in the page cache
grep -q "Sejmuto $N_YESTERDAY_CZ – archiv" "$WORK/response" && ! grep -qs 'Sejmuto' "$WORK"/web/storage/cache/stranky/*.html && echo "  ok     notices: an archived notice says when it was taken down, and the page is not cached" || { echo "  CHYBA  archived notice page"; ERRORS=$((ERRORS+1)); }
# the permanent archive: no trash, no hiding once posted, the collection stays while it has notices
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_A,\"visible\":false}" > "$WORK/response"
contains -q 'cannot be hidden' "$WORK/response" && [ "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $NOTICE_A")" = 1 ] && echo "  ok     MCP: a posted notice cannot be hidden" || { echo "  CHYBA  MCP hid a notice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp delete_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B}" > "$WORK/response"
contains -q 'stay in the archive' "$WORK/response" && [ "$(sq "SELECT smazano IS NULL FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" = 1 ] && echo "  ok     MCP: delete_collection_item refuses a notice with a clear message" || { echo "  CHYBA  MCP deleted a notice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$BOARD_IDK"; TOKEN=$(csrf)
! grep -q 'action=delete_item"' "$WORK/response" && grep -q 'archiv' "$WORK/response" && echo "  ok     admin: the notices list has no Delete button" || { echo "  CHYBA  delete button on a board"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B"
expect "admin: the delete action refuses a notice" "$(sq "SELECT smazano IS NULL FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$BOARD_IDK"
grep -q 'změňte místo toho datum sejmutí' "$WORK/response" && echo "  ok     admin: the refusal is explained" || { echo "  CHYBA  admin refusal message"; ERRORS=$((ERRORS+1)); }
mcp delete_collection '{"collection":"uredni-deska"}' > "$WORK/response"
contains -q 'cannot be deleted' "$WORK/response" && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" = 1 ] && echo "  ok     MCP: the board cannot be deleted while it has notices" || { echo "  CHYBA  delete_collection deleted a board"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK"
expect "admin: the collection delete refuses a board with notices" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" "1"
# the audit trail: created and changed rows, by whom, what changed; a save without a change writes nothing
expect "notices: a created row per notice, written by Claude, with the values" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(DISTINCT \`by\`), '|', (SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.reference[1]')) FROM ka_notice_log WHERE idp = $NOTICE_A AND action = 'created')) FROM ka_notice_log WHERE action = 'created'")" "3|Claude|MU/2026/41"
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B,\"values\":{\"summary\":\"Schválený rozpočet.\"}}" > /dev/null
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B,\"values\":{\"summary\":\"Schválený rozpočet.\"}}" > /dev/null
expect "notices: a change is logged once with the field, the old and the new value" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '$.summary[0]'))), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '$.summary[1]'))), '|', MAX(JSON_CONTAINS_PATH(fields, 'one', '$.reference'))) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'changed'")" "1||Schválený rozpočet.|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$BOARD_IDK&item=$NOTICE_B"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B" --data-urlencode "nazev=Rozpočet 2026" -d "seo_link=rozpocet-2026" -d "poradi=100" -d "zobrazit=1" \
  -d "data[posted]=$N_TEN_AGO" -d "data[taken_down]=$N_YESTERDAY" -d "data[reference]=MU/2026/12" --data-urlencode "data[issuer]=Rada města" --data-urlencode "data[category]=Rozpočet" -d "data[document]=" --data-urlencode "data[summary]=Schválený rozpočet."
expect "admin: saving the form logs the change under the user's name" "$(sq "SELECT CONCAT(\`by\`, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '$.issuer[1]'))) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'changed' ORDER BY id DESC LIMIT 1")" "Tester|Rada města"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B" --data-urlencode "nazev=Rozpočet 2026" -d "seo_link=rozpocet-2026" -d "poradi=100" \
  -d "data[posted]=$N_TEN_AGO" -d "data[taken_down]=$N_YESTERDAY" -d "data[reference]=MU/2026/12" --data-urlencode "data[issuer]=Rada města"
expect "admin: the form cannot hide a posted notice either" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" "1"
# the hourly job records posted and taken down once each and reports it
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'notices: posted 2, taken down 1' "$WORK/tasks.txt" && echo "  ok     notices: the job reports what it recorded" || { echo "  CHYBA  notices job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "notices: posted for both visible notices, taken_down for the archived one, by system" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_notice_log WHERE idp = $NOTICE_A AND action = 'posted'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'taken_down' AND JSON_UNQUOTE(JSON_EXTRACT(fields, '$.taken_down')) = '$N_YESTERDAY'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down') AND \`by\` = 'system'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE idp = (SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'budouci') AND action <> 'created'))")" "1|1|3|0"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'notices: posted 0, taken down 0' "$WORK/tasks.txt" && [ "$(sq "SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down')")" = 3 ] && echo "  ok     notices: a second run records nothing twice" || { echo "  CHYBA  notices job ran twice"; ERRORS=$((ERRORS+1)); }
# the log under the item form and the CSV for an administrator, not for a guest
check "notices: the item form shows the log with the CSV link" 200 "/admin.php?module=collections&action=item&id=$BOARD_IDK&item=$NOTICE_B" "action=notice_log"
grep -q 'Rada města' "$WORK/response" && grep -q "taken_down: $N_YESTERDAY" "$WORK/response" && echo "  ok     notices: the log shows the changes and the takedown" || { echo "  CHYBA  log under the form"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code} %{content_type}' "$B/admin.php?module=collections&action=notice_log&id=$BOARD_IDK")
expect "notices: the administrator downloads the log as CSV" "$code" "200 text/csv; charset=utf-8"
[ "$(grep -c ';taken_down;' "$WORK/response")|$(grep -c ';posted;' "$WORK/response")|$(grep -c ';created;' "$WORK/response")" = "1|2|3" ] && grep -q 'Rada města' "$WORK/response" && echo "  ok     notices: the CSV has every row of the board" || { echo "  CHYBA  notice log CSV"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/response" -w '%{content_type}' "$B/admin.php?module=collections&action=notice_log&id=$BOARD_IDK")
[[ "$code" == text/html* ]] && ! grep -q 'taken_down' "$WORK/response" && grep -q 'Heslo' "$WORK/response" && echo "  ok     notices: a guest gets the sign-in form instead of the CSV" || { echo "  CHYBA  notice log for a guest ($code)"; ERRORS=$((ERRORS+1)); }
mcp list_notice_log '{"collection":"uredni-deska"}' > "$WORK/response"
expect "MCP: list_notice_log lists the whole trail with who and what" "$(mcp_value count)|$(mcp_value entries 0 action)|$(mcp_value entries 0 by)|$(mcp_value entries 0 fields reference 1)" "8|created|Claude|MU/2026/41"
mcp list_notice_log "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B}" > "$WORK/response"
expect "MCP: list_notice_log of one notice" "$(mcp_value count)|$(mcp_value entries 4 action)|$(mcp_value entries 4 fields taken_down)" "5|taken_down|$N_YESTERDAY"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_notice_log"]["readOnlyHint"] === true && !isset($t["edit_notice_log"]) && !isset($t["delete_notice_log"]) ? 0 : 1);' -- "$WORK/response" \
  && echo "  ok     MCP: the notice log is read-only – no tool edits or deletes it" || { echo "  CHYBA  notice log tools"; ERRORS=$((ERRORS+1)); }
