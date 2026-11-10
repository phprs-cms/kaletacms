echo "== 2.15: the reason of a change and guardrails for Claude"
setting() { sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null; }
mcp vytvor_stranku '{"titulek":"Guarded page","zobrazit":false}' > "$WORK/response"; mcp_text; GUARD_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp vytvor_stranku '{"titulek":"Free page","zobrazit":false}' > "$WORK/response"; mcp_text; FREE_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp update_page "{\"id\":$FREE_PAGE,\"description\":\"New description\",\"reason\":\"Request 7: the client asked for a shorter description\"}" > /dev/null
expect "reason: a write tool's reason is in the change log" "$(sq "SELECT duvod FROM ka_protokol WHERE modul = 'claude' ORDER BY idp DESC LIMIT 1")" "Request 7: the client asked for a shorter description"
mcp list_changes '{"by":"claude","limit":5}' > "$WORK/response"; mcp_text
contains -q '"reason":"Request 7' "$WORK/text" && echo "  ok     reason: list_changes returns it" || { echo "  CHYBA  list_changes reason"; ERRORS=$((ERRORS+1)); }
setting claude_protected_pages "$GUARD_PAGE"
mcp update_page "{\"id\":$GUARD_PAGE,\"description\":\"x\"}" > "$WORK/response"
contains -q 'isError' "$WORK/response" && contains -q 'protected from changes' "$WORK/response" && echo "  ok     guardrails: a protected page refuses update_page" || { echo "  CHYBA  protected update_page"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_build "{\"id\":$GUARD_PAGE,\"build\":{\"v\":1,\"children\":[]}}" > "$WORK/response"
contains -q 'protected from changes' "$WORK/response" && echo "  ok     guardrails: and its build" || { echo "  CHYBA  protected save_build"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N31): an empty other target ("popup": 0, "part": "") does not take the protection off – the tools would edit the page
mcp save_build "{\"id\":$GUARD_PAGE,\"popup\":0,\"build\":{\"v\":1,\"children\":[]}}" > "$WORK/response"
mcp save_build "{\"id\":$GUARD_PAGE,\"part\":\"\",\"build\":{\"v\":1,\"children\":[]}}" > "$WORK/response2"
contains -q 'protected from changes' "$WORK/response" && contains -q 'protected from changes' "$WORK/response2" && echo "  ok     guardrails: an empty pop-up or part does not unprotect the page" || { echo "  CHYBA  protected page with an empty other target"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$FREE_PAGE,\"description\":\"Still free\"}" > "$WORK/response"
! contains -q 'isError' "$WORK/response" && echo "  ok     guardrails: other pages stay free" || { echo "  CHYBA  free page"; ERRORS=$((ERRORS+1)); }
setting claude_protected_pages ""
setting claude_destructive 0
mcp trash_page "{\"id\":$FREE_PAGE}" > "$WORK/response"
contains -q 'switched off deleting' "$WORK/response" && [ "$(sq "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $FREE_PAGE")" = 1 ] && echo "  ok     guardrails: deleting switched off – trash_page refused, the page stays" || { echo "  CHYBA  destructive off"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N32): deleting, overwriting and sending through write tools count as destructive too
mcp save_redirect '{"from":"/n32-old","to":"/n32-new"}' > "$WORK/response"
mcp save_redirect '{"from":"/n32-old","delete":true}' > "$WORK/response2"
mcp restore_item_version '{"collection":"x","id":1,"version":1}' > "$WORK/response3"
! contains -q 'isError' "$WORK/response" && contains -q 'switched off deleting' "$WORK/response2" && contains -q 'switched off deleting' "$WORK/response3" \
  && [ "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'n32-old'")" = 1 ] && echo "  ok     guardrails: deleting a redirect and restoring an item version are refused, adding a redirect is not" || { echo "  CHYBA  destructive parameters"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
setting claude_destructive 1
mcp save_redirect '{"from":"/n32-old","delete":true}' > /dev/null
setting claude_change_limit 1
mcp update_page "{\"id\":$FREE_PAGE,\"description\":\"Over the limit\"}" > "$WORK/response"
contains -q 'reached the limit of 1 changes an hour' "$WORK/response" && echo "  ok     guardrails: the hourly limit stops a connection" || { echo "  CHYBA  hourly limit"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_page "{\"id\":$FREE_PAGE}" > "$WORK/response"
! contains -q 'isError' "$WORK/response" && echo "  ok     guardrails: reading is never limited" || { echo "  CHYBA  read limited"; ERRORS=$((ERRORS+1)); }
setting claude_change_limit 0
# 3.7: every row of a batch tool counts against the hourly limit – a batch cannot carry 200 changes past a limit of 10
LIMIT_VIA=$(sq "SELECT via FROM ka_protokol WHERE modul = 'claude' ORDER BY idp DESC LIMIT 1")
LIMIT_USED=$(claude_used "$LIMIT_VIA")
setting claude_change_limit $((LIMIT_USED + 3))
mcp save_redirects '{"redirects":[{"from":"/b37-a","to":"/x"},{"from":"/b37-b","to":"/x"},{"from":"/b37-c","to":"/x"},{"from":"/b37-d","to":"/x"}]}' > "$WORK/response"; L1=$(contains -q 'would make 4 changes' "$WORK/response" && echo refused || echo saved)
mcp save_redirects '{"redirects":[{"from":"/b37-e","to":"/x"},{"from":"/b37-f","to":"/x"}]}' > "$WORK/response"; L2=$(contains -q 'isError' "$WORK/response" && echo refused || echo saved)
mcp save_redirects '{"redirects":[{"from":"/b37-g","to":"/x"},{"from":"/b37-h","to":"/x"}]}' > "$WORK/response"; L3=$(contains -q 'would make 2 changes, but the connection has 1 left' "$WORK/response" && echo refused || echo saved)
expect "3.7 guardrails: each row of a batch counts against the hourly limit" "$L1|$L2|$L3|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'b37-%'")" "refused|saved|refused|2"
# 3.7 (N37-20): ten parallel calls of one connection against a server with eight workers – each call is counted under a
# lock before its tool runs, so with 5 changes left only two batches of 2 rows get through (it was all ten: 20 changes)
RACE2_PORT=$((PORT + 18)); RACE2="http://127.0.0.1:$RACE2_PORT"
(cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=8 exec php -S "127.0.0.1:$RACE2_PORT" system/dev-router.php > /dev/null 2>&1) & RACE2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$RACE2/" && break; sleep 0.2; done
setting claude_change_limit $(( $(claude_used "$LIMIT_VIA") + 5 ))
RACE2_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
RACERS=(); for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -m 60 -o "$WORK/race-limit-$i" -X POST "$RACE2/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"save_redirects\",\"arguments\":{\"redirects\":[{\"from\":\"/r20-$i-a\",\"to\":\"/x\"},{\"from\":\"/r20-$i-b\",\"to\":\"/x\"}]}}}" & RACERS+=($!); done; wait "${RACERS[@]}" || true
pkill -P "$RACE2_PID" 2>/dev/null || true; kill "$RACE2_PID" 2>/dev/null || true; RACE2_PID= # the workers first, they outlive their parent
RACE2_OK=0; RACE2_REFUSED=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if contains -q '"result"' "$WORK/race-limit-$i" && ! contains -q 'isError' "$WORK/race-limit-$i"; then RACE2_OK=$((RACE2_OK+1)); elif contains -q 'changes an hour\|is being checked against the hourly limit' "$WORK/race-limit-$i"; then RACE2_REFUSED=$((RACE2_REFUSED+1)); fi # a call that waited too long for the lock is refused too (fail closed)
done
expect "3.7 N37-20 guardrails: parallel calls never get past the hourly limit – accepted calls, their redirects, one change-log row each, the rest refused" \
  "$RACE2_OK|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'r20-%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $RACE2_LOG AND akce = 'save_redirects'")|$RACE2_REFUSED" "2|4|2|8"
setting claude_change_limit 0
check "guardrails: the settings are in Claude settings" 200 "/admin.php?module=claude_settings" "claude_protected_pages"
# 3.12 (UX310-19): protected pages are ticked in a list of the site's pages, the setting keeps its "12, 15" form
grep -q "name=\"claude_protected_pages\[\]\" value=\"$GUARD_PAGE\">" "$WORK/response" && echo "  ok     3.12: Claude settings list the pages to protect" || { echo "  CHYBA  3.12: no page picker for protected pages"; ERRORS=$((ERRORS+1)); }
KEEP_CLAUDE="-d claude_destructive=$(sq "SELECT COALESCE(MAX(hodnota), 1) FROM ka_nastaveni WHERE promenna = 'claude_destructive'") -d claude_apps_only=$(sq "SELECT COALESCE(MAX(hodnota), 0) FROM ka_nastaveni WHERE promenna = 'claude_apps_only'")" # the switches of the form keep their values
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=claude_settings&action=save" -d "_csrf=$(csrf)" -d tab=claude $KEEP_CLAUDE -d 'claude_protected_pages[]=' -d "claude_protected_pages[]=$GUARD_PAGE" -d "claude_protected_pages[]=$GUARD_PAGE"
expect "3.12: the ticked pages are saved as their numbers" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_protected_pages'")" "$GUARD_PAGE"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=claude_settings&action=save" -d "_csrf=$(csrf)" -d tab=claude $KEEP_CLAUDE -d 'claude_protected_pages[]='
expect "3.12: nothing ticked = no protected page" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_protected_pages'")" ""
