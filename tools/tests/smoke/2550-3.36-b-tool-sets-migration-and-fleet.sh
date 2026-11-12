echo "== 3.36 B: tool sets Migration and Fleet next to Essentials and Work, chosen for a token and switched in My account; short server instructions by task"
# b336_names <token>: the names tools/list holds, comma-separated
b336_names() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  | php -r 'echo implode(",", array_column(json_decode(stream_get_contents(STDIN), true)["result"]["tools"] ?? [], "name"));'; }
# b336_init <token>: the server instructions of the connection
b336_init() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)["result"]["instructions"] ?? "";'; }
# b336_first <text> <heading>…: the headings in the order the text has them
# b336_has <text> <needle>…: "yes" when the text holds every needle in this order
b336_has() { php -r '$t = $argv[1]; $at = 0; foreach (array_slice($argv, 2) as $n) { $p = strpos($t, $n, $at); if ($p === false) { echo "no"; exit; } $at = $p; } echo "yes";' -- "$@"; }
b336_first() { php -r '$t = $argv[1]; $at = []; foreach (array_slice($argv, 2) as $h) { if (($p = strpos($t, $h)) !== false) { $at[$h] = $p; } } asort($at); echo implode(">", array_keys($at));' -- "$@"; }
B336_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; B336_CSRF=$(csrf)
contains -q 'name="tool_set" value="migration"' "$WORK/response" && ! contains -q 'name="tool_set" value="fleet"' "$WORK/response" && contains -q 'Stěhování – pro přesun webu' "$WORK/response" \
  && echo "  ok     3.36 B: a new token offers the migration set; the fleet set only on a fleet site" || { echo "  CHYBA  3.36 B: the sets of a new token"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$B336_CSRF" -d co=token_novy -d "nazev=b336 migration" -d access=full -d tool_set=migration --data-urlencode "soucasne=$PASSWORD"
B336_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
B336_IDT=$(sq "SELECT idt FROM ka_api_tokeny WHERE nazev = 'b336 migration'")
B336_LIST=$(b336_names "$B336_TOKEN")
expect "3.36 B: a migration token lists the move – imports, old pages, the builder, redirects, the report – then find_tools and the runners; no CRM tools" \
  "$(sq "SELECT tool_set FROM ka_api_tokeny WHERE idt = ${B336_IDT:-0}")|$(php -r '$n = explode(",", $argv[1]); echo implode(",", array_values(array_diff(["import_website", "import_wordpress", "read_old_page", "migration_report", "build_from_html", "save_redirects", "upload_file", "find_tools", "run_destructive_tool"], $n))), "|", implode(",", array_intersect(["list_deals", "my_work", "list_sites", "send_newsletter"], $n));' -- "$B336_LIST")" \
  "migration||"
B336_TEXT=$(b336_init "$B336_TOKEN")
expect "3.36 B: the instructions of a migration connection say what its list holds and put moving a site first" \
  "$(b336_has "$B336_TEXT" 'YOUR TOOL LIST HOLDS THE MIGRATION TOOLS' 'confirm that exact action with the user first')|$(b336_first "$B336_TEXT" 'MOVING A SITE' 'BUILDING A SITE' 'DAILY WORK')|$([ "${#B336_TEXT}" -lt 9000 ] && echo short || echo "long ${#B336_TEXT}")" \
  "yes|MOVING A SITE>BUILDING A SITE>DAILY WORK|short"
mcp_as "$B336_TOKEN" site_info '{}' > "$WORK/response"; mcp_text
expect "3.36 B: site_info shows the tool set of the connection" "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["connection"]["tool_set"] ?? "";' -- "$WORK/text")" "migration"
# My account: one folded choice of the other sets; a switch to work, a refused switch to fleet (not a fleet site)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
contains -q 'nástroje pro stěhování' "$WORK/response" && contains -q "value=\"work:token:$B336_IDT\"" "$WORK/response" && contains -q "value=\"essentials:token:$B336_IDT\"" "$WORK/response" \
  && contains -q "value=\"all:token:$B336_IDT\"" "$WORK/response" && ! contains -q "value=\"fleet:token:$B336_IDT\"" "$WORK/response" && ! contains -q "value=\"migration:token:$B336_IDT\"" "$WORK/response" \
  && echo "  ok     3.36 B: My account labels the migration token and offers every other set except fleet" || { echo "  CHYBA  3.36 B: My account switch"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$B336_CSRF" -d "tool_set_switch=work:token:$B336_IDT"
curl -sL -b "$JAR" -c "$JAR" -o "$WORK/response2" -X POST "$B/admin.php?action=account" -d "_csrf=$B336_CSRF" -d "tool_set_switch=fleet:token:$B336_IDT"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$B336_CSRF" -d co=token_novy -d "nazev=b336 fleet refused" -d access=read -d tool_set=fleet --data-urlencode "soucasne=$PASSWORD"
expect "3.36 B: a switch to work is saved and logged; fleet is refused on a site without the fleet extension, a new token falls back to all tools" \
  "$(sq "SELECT CONCAT((SELECT tool_set FROM ka_api_tokeny WHERE idt = ${B336_IDT:-0}), '|', (SELECT tool_set FROM ka_api_tokeny WHERE nazev = 'b336 fleet refused'), '|', (SELECT popis FROM ka_protokol WHERE akce = 'claude_tool_set' ORDER BY idp DESC LIMIT 1))")|$(grep -c 'Tato sada nástrojů se na tomto webu nenabízí' "$WORK/response2" || true)" \
  "work|all|work|1"
B336_TEXT=$(b336_init "$B336_TOKEN")
expect "3.36 B: a work connection's instructions start the tasks with the daily work (customers, deals, tasks)" \
  "$(b336_first "$B336_TEXT" 'DAILY WORK' 'BUILDING A SITE' 'MOVING A SITE')|$(b336_has "$B336_TEXT" save_contact list_deals complete_task)" "DAILY WORK>BUILDING A SITE>MOVING A SITE|yes"
# a fleet site: the set is offered, switched to and lists the console; the instructions put the console first
sq "UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',fleet') WHERE promenna = 'extensions'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
contains -q "value=\"fleet:token:$B336_IDT\"" "$WORK/response" && contains -q 'name="tool_set" value="fleet"' "$WORK/response" \
  && echo "  ok     3.36 B: on a fleet site My account and a new token offer the fleet set" || { echo "  CHYBA  3.36 B: fleet set offered"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$B336_CSRF" -d "tool_set_switch=fleet:token:$B336_IDT"
B336_TEXT=$(b336_init "$B336_TOKEN")
expect "3.36 B: a fleet connection lists the console tools and hears about the console first" \
  "$(sq "SELECT tool_set FROM ka_api_tokeny WHERE idt = ${B336_IDT:-0}")|$(php -r '$n = explode(",", $argv[1]); echo implode(",", array_values(array_diff(["list_sites", "get_site", "fleet_today", "fleet_plan", "fleet_calendar", "find_tools"], $n))), "|", implode(",", array_intersect(["build_from_html", "import_wordpress"], $n));' -- "$(b336_names "$B336_TOKEN")")|$(b336_first "$B336_TEXT" 'THE FLEET CONSOLE' 'BUILDING A SITE')" \
  "fleet|||THE FLEET CONSOLE>BUILDING A SITE"
sq "UPDATE ka_nastaveni SET hodnota = '$B336_EXT' WHERE promenna = 'extensions'" > /dev/null
# without the extension a stored fleet set keeps working – it only lists less – and My account still shows it
expect "3.36 B: after the fleet extension is off, the fleet connection still works and keeps its set" \
  "$(b336_has "$(b336_names "$B336_TOKEN")" site_info find_tools)|$(sq "SELECT tool_set FROM ka_api_tokeny WHERE idt = ${B336_IDT:-0}")" "yes|fleet"
sq "DELETE FROM ka_api_tokeny WHERE nazev IN ('b336 migration', 'b336 fleet refused')" > /dev/null
