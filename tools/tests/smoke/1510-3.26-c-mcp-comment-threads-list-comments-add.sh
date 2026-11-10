echo "== 3.26 (C): MCP comment threads (list_comments, add_comment) and the work tool set of an employee's own Claude"
# an editor with Pages, Collections and Enquiries (own Claude: the work tool set, and a drafts-only connection), a salesperson
# with Enquiries only, a blocked administrator; a page the editor is responsible for, an enquiry and a collection item
C326_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat, posledni_login, potvrzeno) VALUES
  ('c326-editor', '$C326_HASH', 'Editor C326', 'c326-editor@example.com', 1, 0, '$(site_time)', '$(site_time)'),
  ('c326-sales', '$C326_HASH', 'Sales C326', 'c326-sales@example.com', 0, 0, '$(site_time)', '$(site_time)'),
  ('c326-gone', '$C326_HASH', 'Gone C326', 'c326-gone@example.com', 2, 1, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT u.idu, m.ident FROM ka_uzivatele u JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries') m WHERE u.user = 'c326-editor';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user = 'c326-sales'" > /dev/null
C326_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'c326-editor'"); C326_SALES=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'c326-sales'"); C326_GONE=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'c326-gone'")
C326_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, owner_id, zmeneno) VALUES ('c326-page', 'C326 page', '<p>x</p>', 1, 0, $C326_ED, '$(site_time)');
  INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'C326 form', 'c326@visitor.example', '[]', 0)" > /dev/null
C326_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'c326-page'"); C326_ENQ=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'C326 form'")
mcp create_collection '{"name":"C326 collection","slug":"c326-collection"}' > /dev/null
C326_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'c326-collection'")
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, zobrazit, datum) VALUES ($C326_IDK, 'C326 item', 'c326-item', '{}', 1, '$(site_time)')" > /dev/null
C326_ITEM=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'c326-item'")
c326_token() { local t="kaleta_$(printf "$1%.0s" $(seq 1 48))"; sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($2, '$3', '$4', '$5', '$(php -r 'echo hash("sha256", $argv[1]);' "$t")', '$(site_time)')" > /dev/null; echo "$t"; }
C326_WORK=$(c326_token d "$C326_ED" c326-work full work); C326_DRAFTS=$(c326_token e "$C326_ED" c326-drafts drafts all)
C326_SALES_T=$(c326_token f "$C326_SALES" c326-sales full all); C326_UNDO=$(c326_token 7 "$C326_ADMIN" c326-undo full all)
C326_CACHE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'page_cache'"); setting page_cache 1
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null "$B/"; C326_CACHED=$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')

# add_comment from the editor's own Claude: a mention of a colleague who may open the page is stored and named in the result
mcp_as "$C326_WORK" add_comment "{\"target\":\"page:$C326_PAGE\",\"text\":\"Prices are out of date – @admin please check.\\nThanks!\"}" > "$WORK/response"; mcp_text
C326_C1=$(php -r '$v = json_decode((string) file_get_contents($argv[1]), true); echo (int) ($v["id"] ?? 0);' "$WORK/text")
expect "3.26 add_comment: a page comment as the connected user (via mcp), the mention of an administrator stored, the admin link in the result" \
  "$(sq "SELECT CONCAT_WS('/', target, author_id, via, mentions, REPLACE(text, '\n', '~')) FROM ka_comments WHERE id = ${C326_C1:-0}")|$(grep -c "\"mentioned\":\[{\"id\":$C326_ADMIN," "$WORK/text")|$(grep -c "module=pages&action=edit&id=$C326_PAGE\"" "$WORK/text")" \
  "page:$C326_PAGE/$C326_ED/mcp/[$C326_ADMIN]/Prices are out of date – @admin please check.~Thanks!|1|1"
# notifications go through Core\Inbox once agent B's class is merged: a mention to the administrator, nothing to the author (the page's owner)
if [ -f "$ROOT/system/src/Core/Inbox.php" ]; then
  expect "3.26 add_comment: the mentioned administrator is notified, the author (the page's owner) is not" \
    "$(sq "SELECT CONCAT(SUM(user_id = $C326_ADMIN AND kind = 'mention'), '|', SUM(user_id = $C326_ED)) FROM ka_notifications WHERE target = 'page:$C326_PAGE'")" "1|0"
else
  expect "3.26 add_comment: without Core\\Inbox (before the merge) nobody is notified and nothing breaks" "$(grep -c '"notified"' "$WORK/text")" "0"
fi
# a mention by e-mail; an unknown handle stays plain text; someone who cannot open the record or a blocked account is refused
mcp_as "$C326_WORK" add_comment "{\"target\":\"enquiry:$C326_ENQ\",\"text\":\"@c326-sales call back, see @nobody-c326\"}" > "$WORK/response"; mcp_text
expect "3.26 add_comment: an enquiry note mentions a colleague with Enquiries; @nobody-c326 is no account and stays plain text (reported)" \
  "$(sq "SELECT CONCAT_WS('/', mentions, via) FROM ka_comments WHERE target = 'enquiry:$C326_ENQ'")|$(grep -c '"not_mentioned":\["@nobody-c326"\]' "$WORK/text")" "[$C326_SALES]/mcp|1"
C326_N=$(sq "SELECT COUNT(*) FROM ka_comments WHERE target LIKE 'page:$C326_PAGE'")
mcp_as "$C326_WORK" add_comment "{\"target\":\"page:$C326_PAGE\",\"text\":\"@c326-sales look\"}" > "$WORK/response"; mcp_text
contains -q '@c326-sales cannot be mentioned here' "$WORK/text" && contains -q 'Nothing was saved' "$WORK/text" && echo "  ok     3.26 add_comment: a colleague who cannot open Pages is not mentioned – the call is refused" || { echo "  CHYBA  3.26 mention without the section: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp_as "$C326_WORK" add_comment "{\"target\":\"page:$C326_PAGE\",\"text\":\"@c326-gone look\"}" > "$WORK/response"; mcp_text
contains -q '@c326-gone cannot be mentioned: the account is blocked' "$WORK/text" && expect "3.26 add_comment: a blocked account is refused too – nothing saved" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'page:$C326_PAGE'")" "$C326_N" \
  || { echo "  CHYBA  3.26 mention of a blocked account: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
# a drafts-only connection may comment, but not mention (a mention e-mails a colleague)
mcp_as "$C326_DRAFTS" add_comment "{\"target\":\"item:$C326_ITEM\",\"text\":\"@admin the photo is missing\"}" > "$WORK/response"; mcp_text
contains -q 'drafts-only connection cannot mention' "$WORK/text" && echo "  ok     3.26 add_comment: a drafts-only connection cannot mention" || { echo "  CHYBA  3.26 drafts-only mention: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp_as "$C326_DRAFTS" add_comment "{\"target\":\"item:$C326_ITEM\",\"text\":\"The photo is missing.\"}" > "$WORK/response"; mcp_text
expect "3.26 add_comment: a drafts-only connection writes a plain comment on an item; the admin link opens the item in its collection" \
  "$(sq "SELECT CONCAT_WS('/', author_id, IFNULL(mentions, '-')) FROM ka_comments WHERE target = 'item:$C326_ITEM'")|$(grep -c "module=collections&action=item&id=$C326_IDK&item=$C326_ITEM\"" "$WORK/text")" "$C326_ED/-|1"
# who may open the record: the salesperson reads the enquiry's thread, not the page's; a missing record, a bad target, an empty text
mcp_as "$C326_SALES_T" list_comments "{\"target\":\"enquiry:$C326_ENQ\"}" > "$WORK/response"; mcp_text
expect "3.26 list_comments: the thread of an enquiry for someone with Enquiries – who, the text, the mention by id, name and sign-in name (N326-2)" \
  "$(php -r '$v = json_decode((string) file_get_contents($argv[1]), true); $c = $v["comments"][0] ?? []; echo ($v["total"] ?? 0), "|", $c["by"]["name"] ?? "", "|", $c["mentions"][0]["name"] ?? "", "|", $c["mentions"][0]["user"] ?? "", "|", $c["via"] ?? "";' "$WORK/text")" "1|Editor C326|Sales C326|c326-sales|claude"
mcp_as "$C326_SALES_T" list_comments "{\"target\":\"page:$C326_PAGE\"}" > "$WORK/response"; mcp_text
contains -q 'Only a user who may open Pages' "$WORK/text" && echo "  ok     3.26 list_comments: a user without Pages cannot read a page's thread" || { echo "  CHYBA  3.26 list_comments without the section: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
expect "3.26 list_comments / add_comment: a missing page, a malformed target and an empty text are refused" \
  "$(mcp list_comments '{"target":"page:999999"}' | grep -c 'The page does not exist')|$(mcp list_comments '{"target":"news:1"}' | grep -c 'target must be')|$(mcp add_comment "{\"target\":\"page:$C326_PAGE\",\"text\":\"  \"}" | grep -c 'text is required')" "1|1|1"
# comments never touch the public page cache; a page comment Claude wrote is part of its session, so undo takes it back
expect "3.26: add_comment and list_comments leave the cached page" "$C326_CACHED|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" "1|1"
setting page_cache "$C326_CACHE"
mcp_as "$C326_UNDO" add_comment "{\"target\":\"page:$C326_PAGE\",\"text\":\"Undo me\"}" > /dev/null
mcp_as "$C326_UNDO" add_comment "{\"target\":\"enquiry:$C326_ENQ\",\"text\":\"Personal – never journaled\"}" > /dev/null
C326_S=$(sq "SELECT id FROM ka_agent_sessions WHERE connection = 'c326-undo' ORDER BY id DESC LIMIT 1")
expect "3.26 undo journal: the page comment is journaled, the enquiry note is not" "$(sq "SELECT GROUP_CONCAT(tbl) FROM ka_agent_journal WHERE session_id = ${C326_S:-0} AND untracked IS NULL")" "comments"
mcp_as "$C326_UNDO" undo_agent_session "{\"id\":${C326_S:-0},\"confirm\":true}" > /dev/null
expect "3.26 undo_agent_session: the page comment is gone, the enquiry note stays" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE text = 'Undo me'")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE text = 'Personal – never journaled'")" "0|1"
# the work tool set: the work tools, find_tools and the runners – under 49,000 bytes; the builder is one find_tools away
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $C326_WORK" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
expect "3.26 work tool set: tools/list holds my_work, the enquiry and comment tools, assign and find_tools, not the builder; under 49,000 bytes" \
  "$(php -r '$t = json_decode((string) file_get_contents($argv[1]), true)["result"]["tools"] ?? []; $n = array_column($t, "name");
    echo implode(",", array_values(array_intersect(["my_work", "list_enquiries", "list_comments", "add_comment", "assign", "get_email_signature", "find_tools", "save_build", "publish_look"], $n))), "|", strlen(json_encode(["tools" => $t], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 49000 ? "small" : "big";' "$WORK/response")" \
  "my_work,list_enquiries,list_comments,add_comment,assign,get_email_signature,find_tools|small"
mcp_as "$C326_WORK" find_tools '{"names":["save_build"]}' > "$WORK/response"; mcp_text
contains -q '"runner":"run_tool"' "$WORK/text" && echo "  ok     3.26 work tool set: find_tools reaches the builder (save_build through run_tool)" || { echo "  CHYBA  3.26 work find_tools: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $C326_WORK" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}' > "$WORK/response"
contains -q 'YOUR TOOL LIST HOLDS THE WORK TOOLS' "$WORK/response" && echo "  ok     3.26 work tool set: the server instructions say what the list holds" || { echo "  CHYBA  3.26 work instructions"; ERRORS=$((ERRORS+1)); }
# My account: a new token may take the work tool set (Advanced), the list labels it, the switch goes to all tools
check "3.26 My account: the work tool set is offered for a new token, a work connection is labelled" 200 "/admin.php?action=account" 'name="tool_set" value="work"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$TOKEN" -d co=token_novy -d nazev=c326-new -d access=drafts -d tool_set=work -d platnost=30 --data-urlencode "soucasne=$PASSWORD"
expect "3.26 My account: the new token keeps the work tool set" "$(sq "SELECT CONCAT(access, '/', tool_set) FROM ka_api_tokeny WHERE nazev = 'c326-new'")" "drafts/work"
C326_NEW=$(sq "SELECT idt FROM ka_api_tokeny WHERE nazev = 'c326-new'")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
expect "3.26 My account: the work token is labelled and its switch goes to all tools" "$(grep -c '>pracovní nástroje<' "$WORK/response")|$(grep -c "value=\"all:token:$C326_NEW\"" "$WORK/response")" "1|1"
sq "DELETE FROM ka_api_tokeny WHERE nazev LIKE 'c326-%'; DELETE FROM ka_comments WHERE target IN ('page:$C326_PAGE', 'enquiry:$C326_ENQ', 'item:$C326_ITEM'); DELETE FROM ka_stranky WHERE seo_link = 'c326-page';
  DELETE FROM ka_kolekce_polozky WHERE seo_link = 'c326-item'; DELETE FROM ka_kolekce WHERE seo_link = 'c326-collection'; DELETE FROM ka_poptavky WHERE formular = 'C326 form';
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($C326_ED, $C326_SALES, $C326_GONE); DELETE FROM ka_uzivatele WHERE user LIKE 'c326-%'" > /dev/null
if [ -f "$ROOT/system/src/Core/Inbox.php" ]; then sq "DELETE FROM ka_notifications WHERE target IN ('page:$C326_PAGE', 'enquiry:$C326_ENQ', 'item:$C326_ITEM')" > /dev/null; fi
# 3.27 (agent A): tasks and follow-ups – Core\Tasks (ka_tasks, migration 0113), the reminder job "tasks" (Core\Inbox kind
# task_due, the e-mail as each person chose), My work (the tasks first, Done and snooze, the Team tab), MCP list_tasks,
# save_task and complete_task (permissions, journaled for undo), the hand-over of a leaver and the clean-up with the record
