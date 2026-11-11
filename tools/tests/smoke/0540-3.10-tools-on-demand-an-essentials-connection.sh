echo "== 3.10: tools on demand – an essentials connection lists the core tools, finds the rest (find_tools) and runs it (run_read_tool, run_tool, run_destructive_tool)"
# mcp_list <token>: how many tools tools/list holds | the meta tools among them ("none") | whether it fits in 50 KB
mcp_list() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  | php -r '$t = json_decode(stream_get_contents(STDIN), true)["result"]["tools"] ?? []; $n = array_column($t, "name");
    $meta = array_values(array_intersect($n, ["find_tools", "run_read_tool", "run_tool", "run_destructive_tool"])); echo count($t), "|", $meta === [] ? "none" : implode(",", $meta), "|", strlen(json_encode(["tools" => $t], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 50000 ? "small" : "large";'; }
token_of() { grep -o "\"$1\":\"[a-z0-9_]*\"" "$WORK/response" | sed 's/.*:"//;s/"//' || true; }
ALL_BEFORE=$(mcp_list "$API_TOKEN")
case "$ALL_BEFORE" in *"|none|large") echo "  ok     3.10: a connection from before (all tools) lists every tool and no meta tool ($ALL_BEFORE)";; *) echo "  CHYBA  3.10: the all-tools list: $ALL_BEFORE"; ERRORS=$((ERRORS+1));; esac
expect "3.10: connections that existed before keep all tools" "$(sq "SELECT GROUP_CONCAT(DISTINCT tool_set) FROM ka_api_tokeny WHERE otisk IN (SHA2('$API_TOKEN', 256), SHA2('$READ_TOKEN', 256), SHA2('$DRAFT_TOKEN', 256))")" "all"
# the consent screen offers the tool set and preselects the essentials; the choice goes with the code into the tokens
NONCE_310=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=ts310")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_310"
contains -q 'Kolik toho má Claude načíst hned na začátku?' "$WORK/response" && contains -q 'name="tool_set" value="essentials" checked' "$WORK/response" && ! contains -q 'value="all" checked' "$WORK/response" \
  && echo "  ok     3.10: the consent screen offers the essentials (preselected) or all tools" || { echo "  CHYBA  3.10: the tool set on the consent screen"; ERRORS=$((ERRORS+1)); }
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$(csrf)" -d "request=$NONCE_310" -d povolit=1 -d access=full -d tool_set=essentials --data-urlencode "soucasne=$PASSWORD")
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
ESS_ACCESS=$(token_of access_token); ESS_REFRESH=$(token_of refresh_token)
expect "3.10: the essentials chosen at consent are on the access and the refresh token" "$(sq "SELECT GROUP_CONCAT(CONCAT(druh, ':', tool_set) ORDER BY druh) FROM ka_api_tokeny WHERE otisk IN (SHA2('$ESS_ACCESS', 256), SHA2('$ESS_REFRESH', 256))")" "obnova:essentials,pristup:essentials"
ESS_LIST=$(mcp_list "$ESS_ACCESS")
case "$ESS_LIST" in *"|find_tools,run_read_tool,run_tool,run_destructive_tool|small") [ "${ESS_LIST%%|*}" -lt "${ALL_BEFORE%%|*}" ] && echo "  ok     3.10: an essentials connection lists the core tools plus find_tools and the three runners, under 50 KB ($ESS_LIST)" || { echo "  CHYBA  3.10: the essentials list is not shorter: $ESS_LIST"; ERRORS=$((ERRORS+1)); };; *) echo "  CHYBA  3.10: the essentials list: $ESS_LIST"; ERRORS=$((ERRORS+1));; esac
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ESS_ACCESS" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' > "$WORK/response"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' > "$WORK/response2"
contains -q 'YOUR TOOL LIST HOLDS THE ESSENTIALS' "$WORK/response" && contains -q 'run_destructive_tool for deleting, sending or reaching outside the site – confirm that exact action with the user first' "$WORK/response" && ! contains -q 'YOUR TOOL LIST HOLDS THE ESSENTIALS' "$WORK/response2" \
  && echo "  ok     3.10: the server instructions of an essentials connection point to find_tools and the runners, an all-tools connection's do not" || { echo "  CHYBA  3.10: instructions"; ERRORS=$((ERRORS+1)); }
# the refresh keeps the tool set
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$ESS_REFRESH" -d "client_id=$CLIENT"
ESS_ACCESS=$(token_of access_token); ESS_REFRESH=$(token_of refresh_token)
expect "3.10: a token refresh keeps the essentials" "$(sq "SELECT GROUP_CONCAT(CONCAT(druh, ':', tool_set) ORDER BY druh) FROM ka_api_tokeny WHERE otisk IN (SHA2('$ESS_ACCESS', 256), SHA2('$ESS_REFRESH', 256))")|$(mcp_list "$ESS_ACCESS" | cut -d'|' -f2)" "obnova:essentials,pristup:essentials|find_tools,run_read_tool,run_tool,run_destructive_tool"
# find_tools: the index of the rest, full definitions by name or by query
mcp_as "$ESS_ACCESS" find_tools '{}' > "$WORK/response"; mcp_text
contains -q '"name":"list_news","access":"read","runner":"run_read_tool"' "$WORK/text" && contains -q '"name":"save_redirect","access":"write","runner":"run_tool"' "$WORK/text" \
  && contains -q '"name":"delete_media","access":"destructive","runner":"run_destructive_tool"' "$WORK/text" && contains -q '"name":"migration_report","access":"read","runner":"run_destructive_tool"' "$WORK/text" && ! contains -q '"name":"create_page"' "$WORK/text" && ! contains -q '"inputSchema"' "$WORK/text" \
  && echo "  ok     3.10: find_tools without arguments indexes the tools outside the list, one line each with the runner to use" || { echo "  CHYBA  3.10: find_tools index"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp_as "$ESS_ACCESS" find_tools '{"names":["create_news","nonexistent_tool"]}' > "$WORK/response"; mcp_text
contains -q '"name":"create_news","title":"Create a news item"' "$WORK/text" && contains -q '"reason":{' "$WORK/text" && contains -q '"runner":"run_tool"' "$WORK/text" && contains -q '"not_available":\["nonexistent_tool"\]' "$WORK/text" \
  && echo "  ok     3.10: find_tools with names returns the full definition (with reason) and names what is not available" || { echo "  CHYBA  3.10: find_tools names"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp_as "$ESS_ACCESS" find_tools '{"query":"redirect"}' > "$WORK/response"; mcp_text
contains -q '"name":"list_redirects"' "$WORK/text" && contains -q '"name":"save_redirect"' "$WORK/text" && echo "  ok     3.10: find_tools with a query finds the matching tools" || { echo "  CHYBA  3.10: find_tools query"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# three runners, each for its own class of tools only (3.10 audit N310-2, N310-4): a read inside the site, a draft or a
# change, and anything destructive or reaching outside the site – each refuses the others and names the right one
mcp_as "$ESS_ACCESS" run_read_tool '{"name":"list_redirects","arguments":{}}' > "$WORK/response"; mcp_as "$READ_TOKEN" run_read_tool '{"name":"list_news","arguments":{}}' > "$WORK/response2"
contains -q '"isError":true' "$WORK/response" || contains -q '"isError":true' "$WORK/response2" && { echo "  CHYBA  3.10: run_read_tool"; head -c 300 "$WORK/response"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.10: run_read_tool runs a read tool, also on a read-only connection"
mcp_as "$ESS_ACCESS" run_read_tool "{\"name\":\"create_news\",\"arguments\":{\"title\":\"Via run_read_tool\",\"category\":\"$CATEGORY\"}}" > "$WORK/response"
mcp_as "$ESS_ACCESS" run_read_tool '{"name":"migration_report","arguments":{"url":"https://example.com"}}' > "$WORK/response2"
mcp_as "$ESS_ACCESS" run_tool '{"name":"list_redirects","arguments":{}}' > "$WORK/response3"
mcp_as "$ESS_ACCESS" run_tool '{"name":"delete_media","arguments":{"id":999999}}' > "$WORK/response4"
mcp_as "$ESS_ACCESS" run_destructive_tool "{\"name\":\"create_news\",\"arguments\":{\"title\":\"Via run_destructive_tool\",\"category\":\"$CATEGORY\"}}" > "$WORK/response5"
contains -q 'run_read_tool runs read-only tools that stay inside the site – create_news saves drafts or changes the site. Use run_tool for it.' "$WORK/response" \
  && contains -q 'migration_report reaches outside the site. Use run_destructive_tool for it, after the user confirmed this exact action.' "$WORK/response2" \
  && contains -q 'run_tool runs tools that save drafts or change the site without removing, sending or reaching outside it – list_redirects only reads. Use run_read_tool for it.' "$WORK/response3" \
  && contains -q 'delete_media removes, overwrites or sends something. Use run_destructive_tool' "$WORK/response4" && contains -q 'create_news saves drafts or changes the site. Use run_tool for it.' "$WORK/response5" \
  && expect "3.10: each runner refuses the tools of another class, names the right runner and saves nothing" "$(sq "SELECT COUNT(*) FROM ka_novinky WHERE titulek IN ('Via run_read_tool', 'Via run_destructive_tool')")|$(cat "$WORK"/response "$WORK"/response[2-5] | grep -o '"isError":true' | wc -l | tr -d ' ')" "0|5" \
  || { echo "  CHYBA  3.10: runner classes"; for f in "$WORK"/response "$WORK"/response[2-5]; do head -c 250 "$f"; echo; done; ERRORS=$((ERRORS+1)); }
JOURNAL_BEFORE=$(sq "SELECT COUNT(*) FROM ka_agent_journal WHERE tool = 'create_news'")
mcp_as "$ESS_ACCESS" run_tool "{\"name\":\"create_news\",\"arguments\":{\"title\":\"Written via run_tool\",\"category\":\"$CATEGORY\",\"text\":\"<p>Hello</p>\",\"typo_param\":1},\"reason\":\"The user asked for a news item (3.10 test)\"}" > "$WORK/response"; mcp_text
expect "3.10: run_tool runs a write tool – a draft, the change log row of create_news with the connection and the reason, the undo journal, unknown parameters reported" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Written via run_tool' AND visible = 0), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'vytvor_novinku' AND via <> '' AND duvod = 'The user asked for a news item (3.10 test)'), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE tool = 'create_news') > $JOURNAL_BEFORE)")|$(grep -c 'typo_param' "$WORK/text")" "1|1|1|1"
RUN_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Written via run_tool'")
mcp_as "$ESS_ACCESS" run_destructive_tool "{\"name\":\"trash_news\",\"arguments\":{\"id\":${RUN_NEWS:-0}},\"reason\":\"The user asked to remove it (3.10 test)\"}" > "$WORK/response"
expect "3.10: run_destructive_tool runs a destructive tool – the news item in the trash, the change log row with the reason" \
  "$(sq "SELECT CONCAT((SELECT smazano IS NOT NULL FROM ka_novinky WHERE idc = ${RUN_NEWS:-0}), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'trash_news' AND duvod = 'The user asked to remove it (3.10 test)'))")" "1|1"
mcp_as "$ESS_ACCESS" list_news '{"status":"all"}' > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && { echo "  CHYBA  3.10: a direct call of a tool outside the list"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.10: a tool outside the list can still be called directly by name"
# nesting, unknown tools and the connection's access
mcp_as "$ESS_ACCESS" run_tool '{"name":"run_tool","arguments":{"name":"list_news"}}' > "$WORK/response"; mcp_as "$ESS_ACCESS" run_tool '{"name":"find_tools"}' > "$WORK/response2"
mcp_as "$ESS_ACCESS" run_read_tool '{"name":"run_read_tool","arguments":{"name":"list_news"}}' > "$WORK/response3"; mcp_as "$ESS_ACCESS" run_tool '{"name":"run_read_tool","arguments":{"name":"list_news"}}' > "$WORK/response4"
mcp_as "$ESS_ACCESS" run_destructive_tool '{"name":"run_tool","arguments":{"name":"delete_media"}}' > "$WORK/response5"
contains -q 'not run_tool' "$WORK/response" && contains -q '"isError":true' "$WORK/response" && contains -q 'call find_tools directly' "$WORK/response2" && contains -q 'run_read_tool runs the site.s tools, not run_read_tool' "$WORK/response3" && contains -q 'not run_read_tool' "$WORK/response4" && contains -q 'run_destructive_tool runs the site.s tools, not run_tool' "$WORK/response5" \
  && echo "  ok     3.10: no runner runs a meta tool" || { echo "  CHYBA  3.10: nested run_tool"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$ESS_ACCESS" run_tool '{"name":"no_such_tool","arguments":{}}' | contains -q 'Unknown tool: no_such_tool. Call find_tools' && echo "  ok     3.10: run_tool with an unknown tool points to find_tools" || { echo "  CHYBA  3.10: run_tool unknown"; ERRORS=$((ERRORS+1)); }
mcp_as "$READ_TOKEN" run_tool "{\"name\":\"create_news\",\"arguments\":{\"title\":\"Read-only via run_tool\",\"category\":\"$CATEGORY\"}}" > "$WORK/response"
mcp_as "$DRAFT_TOKEN" run_tool '{"name":"save_redirect","arguments":{"from":"/old-310","to":"/"}}' > "$WORK/response2"
contains -q 'can only read the site' "$WORK/response" && contains -q 'can only save drafts' "$WORK/response2" \
  && expect "3.10: run_tool keeps a read-only and a drafts-only connection to their access" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Read-only via run_tool'), '|', (SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%old-310%'))")" "0|0" \
  || { echo "  CHYBA  3.10: run_tool and the connection's access"; head -c 300 "$WORK/response"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
mcp_as "$READ_TOKEN" find_tools '{"names":["create_news","list_news"]}' > "$WORK/response"; mcp_text
contains -q '"not_available":\["create_news"\]' "$WORK/text" && contains -q '"name":"list_news"' "$WORK/text" && echo "  ok     3.10: find_tools on a read-only connection hands out reads only" || { echo "  CHYBA  3.10: find_tools read-only"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp_as "$ESS_ACCESS" find_tools "{\"query\":\"$(printf 'redirect %.0s' $(seq 1 30))\"}" > "$WORK/response"; mcp_as "$ESS_ACCESS" find_tools "{\"names\":[$(printf '"tool_%s",' $(seq 1 31) | sed 's/,$//')]}" > "$WORK/response2"
contains -q 'The find_tools query is too long – at most 200 characters and 20 words' "$WORK/response" && contains -q '"isError":true' "$WORK/response" && contains -q 'find_tools takes at most 30 names at a time (31 given)' "$WORK/response2" \
  && echo "  ok     3.10 N310-8: find_tools refuses a query over 200 characters or 20 words and more than 30 names, with the limit" || { echo "  CHYBA  3.10: find_tools limits"; head -c 300 "$WORK/response"; head -c 300 "$WORK/response2"; ERRORS=$((ERRORS+1)); }
# personal tokens: all tools by default, the essentials when chosen; My account shows and switches the tool set
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; ACCOUNT_CSRF=$(csrf)
contains -q 'name="tool_set" value="all" checked' "$WORK/response" && echo "  ok     3.10: a new personal token preselects all tools" || { echo "  CHYBA  3.10: personal token default"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude essentials" -d access=full -d tool_set=essentials --data-urlencode "soucasne=$PASSWORD"
ESS_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
expect "3.10: a personal token with the essentials lists them" "$(sq "SELECT tool_set FROM ka_api_tokeny WHERE nazev = 'Claude essentials'")|$(mcp_list "$ESS_TOKEN" | cut -d'|' -f2-)" "essentials|find_tools,run_read_tool,run_tool,run_destructive_tool|small"
# N310-6: a read-only essentials connection lists find_tools and run_read_tool only; a drafts-only one no runner for tools it can never run
for a in read drafts; do curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude essentials $a" -d access=$a -d tool_set=essentials --data-urlencode "soucasne=$PASSWORD"
  printf -v "ESS_$a" '%s' "$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)"; done
expect "3.10 N310-6: the runners of read-only and drafts-only essentials connections" "$(mcp_list "$ESS_read" | cut -d'|' -f2)|$(mcp_list "$ESS_drafts" | cut -d'|' -f2)" "find_tools,run_read_tool|find_tools,run_read_tool,run_tool,run_destructive_tool"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
# N310-7: the form of a new token has "Create token" as its only button – the buttons of the listed connections belong to their own form
expect "3.10 N310-7: Enter in the token name can only create a token" "$(php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d);
  $own = []; foreach ($x->query("//form[.//input[@id=\"token-nazev\"]]//button[not(@form) and not(@type=\"button\")]") as $b) { $own[] = $b->getAttribute("value"); }
  echo implode(",", $own), "|", $x->query("//form[.//input[@id=\"token-nazev\"]]//button[@form=\"claude-connections\"]")->length > 0 ? "listed" : "none", "|", $x->query("//form[@id=\"claude-connections\"]//input[@name=\"_csrf\"]")->length;' "$WORK/response")" "token_novy|listed|1"
ESS_IDT=$(sq "SELECT idt FROM ka_api_tokeny WHERE nazev = 'Claude essentials'")
contains -q "value=\"all:token:$ESS_IDT\"" "$WORK/response" && contains -q "value=\"all:app:$CLIENT\"" "$WORK/response" && contains -q 'základní nástroje' "$WORK/response" \
  && echo "  ok     3.10: My account shows the tool set of tokens and connected applications with a switch" || { echo "  CHYBA  3.10: My account tool set"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d "tool_set_switch=all:token:$ESS_IDT"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response2" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d "tool_set_switch=all:app:$CLIENT"
expect "3.10: My account switches a personal token and every token of a connected application to all tools, in the change log" \
  "$(sq "SELECT CONCAT((SELECT tool_set FROM ka_api_tokeny WHERE idt = ${ESS_IDT:-0}), '|', (SELECT GROUP_CONCAT(DISTINCT tool_set) FROM ka_api_tokeny WHERE klient = '$CLIENT'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE akce = 'claude_tool_set'))")|$(mcp_list "$ESS_TOKEN" | cut -d'|' -f2)" "all|all|2|none"
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$ESS_REFRESH" -d "client_id=$CLIENT"
expect "3.10: the next refresh of the switched application keeps all tools" "$(sq "SELECT tool_set FROM ka_api_tokeny WHERE otisk = SHA2('$(token_of refresh_token)', 256)")|$(mcp_list "$(token_of access_token)" | cut -d'|' -f2)" "all|none"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'other 310', SHA2('other-310', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'obchodnik'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d "tool_set_switch=essentials:token:$(sq "SELECT idt FROM ka_api_tokeny WHERE nazev = 'other 310'")"
expect "3.10: a switch never reaches another user's token" "$(sq "SELECT tool_set FROM ka_api_tokeny WHERE nazev = 'other 310'")" "all"
sq "DELETE FROM ka_api_tokeny WHERE nazev IN ('Claude essentials', 'other 310', 'Claude essentials read', 'Claude essentials drafts')" > /dev/null
# N310-9: before migration 0085 has run (the column missing) every connection keeps working with all tools, and a new
# connection is allowed and its tokens refreshed without an SQL error
sq "ALTER TABLE ka_api_tokeny DROP COLUMN tool_set; ALTER TABLE ka_oauth_kody DROP COLUMN tool_set" > /dev/null
NONCE_310=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=n3109")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_310"
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$(csrf)" -d "request=$NONCE_310" -d povolit=1 -d access=full -d tool_set=essentials --data-urlencode "soucasne=$PASSWORD")
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$(token_of refresh_token)" -d "client_id=$CLIENT"
expect "3.10 N310-9: without the tool_set column MCP and OAuth keep working with all tools" "$(mcp_list "$API_TOKEN")|$(mcp_list "$(token_of access_token)" | cut -d'|' -f2)" "$ALL_BEFORE|none"
sq "ALTER TABLE ka_api_tokeny ADD COLUMN tool_set VARCHAR(16) NOT NULL DEFAULT 'all' AFTER access; ALTER TABLE ka_oauth_kody ADD COLUMN tool_set VARCHAR(16) NOT NULL DEFAULT 'all' AFTER access" > /dev/null

