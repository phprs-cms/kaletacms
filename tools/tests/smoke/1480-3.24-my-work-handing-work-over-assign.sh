echo "== 3.24: My work, handing work over, assign"
# three colleagues: an editor with Pages, Collections, Enquiries and Ask Claude; a salesperson with Enquiries only; a writer
# with News only. A hidden page and an item they can be responsible for, three enquiries and a request Claude answered.
W324_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, posledni_login, potvrzeno) VALUES
  ('w324-editor', '$W324_HASH', 'Editor W324', 'w324-editor@example.com', 1, '$(site_time)', '$(site_time)'),
  ('w324-sales', '$W324_HASH', 'Sales W324', 'w324-sales@example.com', 0, '$(site_time)', '$(site_time)'),
  ('w324-writer', '$W324_HASH', 'Writer W324', '', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT u.idu, m.ident FROM ka_uzivatele u JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries' UNION SELECT 'requests') m WHERE u.user = 'w324-editor';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user = 'w324-sales';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'w324-writer'" > /dev/null
W324_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'w324-editor'"); W324_SALES=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'w324-sales'"); W324_WRITER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'w324-writer'")
W324_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, review_by, stavba_koncept, zmeneno) VALUES ('w324-page', 'W324 page', '', 0, 0, '$(site_date '+3 days')', '{\"v\":1,\"deti\":[]}', '$(site_time)'),
  ('w324-quiet', 'W324 quiet page', '', 0, 0, NULL, NULL, '$(site_time)')" > /dev/null
W324_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'w324-page'"); W324_QUIET=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'w324-quiet'")
sq "INSERT INTO ka_draft_comments (target, name, text, created_at) VALUES ('stranka:$W324_PAGE', 'Reviewer', 'Shorter, please.', '$(site_time)')" > /dev/null
mcp create_collection '{"name":"W324 collection","slug":"w324-collection"}' > /dev/null
W324_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'w324-collection'"); W324_COLLECTION=w324-collection
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, zobrazit, review_by, datum) VALUES ($W324_IDK, 'W324 item', 'w324-item', '{}', 1, '$(site_date '-2 days')', '$(site_time)')" > /dev/null
W324_ITEM=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'w324-item'")
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'W324 form A', 'a@visitor.example', '[]', 0), ('$(site_time)', 'W324 form B', 'b@visitor.example', '[]', 1), ('$(site_time)', 'W324 form C', 'c@visitor.example', '[]', 2), ('$(site_time)', 'W324 form D', 'd@visitor.example', '[]', 0)" > /dev/null
W324_EA=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'W324 form A'"); W324_EB=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'W324 form B'"); W324_EC=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'W324 form C'")
sq "INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, status) VALUES ('$(site_time)', '$(site_time)', $W324_ED, 'W324 request', 'Update the price list.', 'in_progress');
  INSERT INTO ka_request_messages (request_id, sender, sender_name, text, created_at) SELECT id, 'claude', 'Claude', 'A draft is ready – check it.', '$(site_time)' FROM ka_requests WHERE title = 'W324 request'" > /dev/null

# MCP assign (as the administrator): by e-mail, refused for someone without the section, "none" clears
mcp assign "{\"target\":\"page\",\"id\":$W324_PAGE,\"user\":\"w324-editor@example.com\"}" > "$WORK/response"; mcp_text
contains -q "\"owner\":{\"id\":$W324_ED,\"name\":\"Editor W324\"}" "$WORK/text" && contains -q '"changed":true' "$WORK/text" \
  && expect "3.24 assign: a page gets its responsible person by e-mail" "$(sq "SELECT owner_id FROM ka_stranky WHERE ids = $W324_PAGE")" "$W324_ED" \
  || { echo "  CHYBA  3.24 assign page: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp assign "{\"target\":\"page\",\"id\":$W324_PAGE,\"user\":$W324_SALES}" > "$WORK/response"; mcp_text
contains -q 'cannot open Pages' "$WORK/text" && expect "3.24 assign: someone who cannot open Pages is refused and nothing changes" "$(sq "SELECT owner_id FROM ka_stranky WHERE ids = $W324_PAGE")" "$W324_ED" \
  || { echo "  CHYBA  3.24 assign to a user without Pages: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp assign "{\"target\":\"item\",\"id\":$W324_ITEM,\"user\":\"$W324_ED\"}" > /dev/null
mcp assign "{\"target\":\"page\",\"id\":$W324_QUIET,\"user\":\"me\"}" > /dev/null
mcp assign "{\"target\":\"page\",\"id\":$W324_QUIET,\"user\":\"none\"}" > "$WORK/response"; mcp_text
expect "3.24 assign: \"me\" and then \"none\" – the page has nobody again, the result names who had it" "$(sq "SELECT IFNULL(owner_id, 'none') FROM ka_stranky WHERE ids = $W324_QUIET")|$(grep -c "\"previous\":{\"id\":$W324_ADMIN," "$WORK/text")" "none|1"
mcp assign '{"target":"news","id":1,"user":"me"}' > "$WORK/response"; mcp_text
contains -q 'target must be page, item or enquiry' "$WORK/text" && echo "  ok     3.24 assign: an unknown target is refused" || { echo "  CHYBA  3.24 assign target: $(head -c 200 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp assign "{\"target\":\"enquiry\",\"id\":$W324_EA,\"user\":\"nobody-w324@example.com\"}" > "$WORK/response"; mcp_text
contains -q 'No user has this id or e-mail' "$WORK/text" && echo "  ok     3.24 assign: an unknown e-mail is refused" || { echo "  CHYBA  3.24 assign unknown e-mail: $(head -c 200 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
# update_enquiry gains assignee (validated the same way; a refusal changes nothing, not even the status sent with it)
mcp update_enquiry "{\"id\":$W324_EA,\"assignee\":\"$W324_ED\"}" > "$WORK/response"; mcp_text
contains -q '"changed":\["assignee"\]' "$WORK/text" && expect "3.24 update_enquiry: assignee sets who handles it" "$(sq "SELECT prirazeno FROM ka_poptavky WHERE idp = $W324_EA")" "$W324_ED" \
  || { echo "  CHYBA  3.24 update_enquiry assignee: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp update_enquiry "{\"id\":$W324_EB,\"status\":\"resolved\",\"assignee\":\"$W324_WRITER\"}" > "$WORK/response"; mcp_text
contains -q 'cannot open Enquiries' "$WORK/text" && expect "3.24 update_enquiry: a writer without Enquiries is refused – the status stays too" "$(sq "SELECT CONCAT(stav, '|', IFNULL(prirazeno, 'none')) FROM ka_poptavky WHERE idp = $W324_EB")" "1|none" \
  || { echo "  CHYBA  3.24 update_enquiry assignee without the section: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp update_enquiry "{\"id\":$W324_EB,\"assignee\":\"$W324_ED\"}" > /dev/null; sq "UPDATE ka_poptavky SET prirazeno = $W324_ED WHERE idp = $W324_EC" > /dev/null
# the merge of 3.24: MCP assign and update_enquiry send the one assignment e-mail of Core\Assignment (the person's admin language
# – the site's Czech here), once per change, never to oneself and never with the enquiry's content
mcp assign "{\"target\":\"page\",\"id\":$W324_PAGE,\"user\":\"$W324_ED\"}" > /dev/null # unchanged: no second e-mail
expect "3.24 merge: MCP assign and update_enquiry e-mail the new person once per change (page, item, two enquiries), the administrator choosing himself nothing" \
  "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'w324-editor@example.com' AND predmet = 'Odpovídáte za stránku „W324 page“'")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'w324-editor@example.com' AND predmet = 'Odpovídáte za „W324 item“ v kolekci W324 collection'")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'w324-editor@example.com' AND predmet LIKE 'Byla vám přidělena poptávka z formuláře „W324 form %'")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%W324 quiet page%'")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'w324-editor@example.com' AND telo LIKE '%visitor.example%'")" \
  "1|1|2|0|0"
# the lists gain owner / assignee (output only)
mcp list_pages '{}' > "$WORK/response"; mcp_text
contains -q "\"owner\":{\"id\":$W324_ED,\"name\":\"Editor W324\"}" "$WORK/text" && echo "  ok     3.24 list_pages: owner" || { echo "  CHYBA  3.24 list_pages owner"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items "{\"collection\":\"$W324_COLLECTION\",\"search\":\"W324\"}" > "$WORK/response"; mcp_text
contains -q "\"owner\":{\"id\":$W324_ED,\"name\":\"Editor W324\"}" "$WORK/text" && echo "  ok     3.24 list_collection_items: owner" || { echo "  CHYBA  3.24 list_collection_items owner: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
mcp list_enquiries '{"search":"a@visitor.example"}' > "$WORK/response"; mcp_text
contains -q "\"assignee\":{\"id\":$W324_ED,\"name\":\"Editor W324\"}" "$WORK/text" && echo "  ok     3.24 list_enquiries: assignee" || { echo "  CHYBA  3.24 list_enquiries assignee: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
contains -q '"name":"my_work"' "$WORK/response" && contains -q '"name":"assign"' "$WORK/response" && echo "  ok     3.24: my_work and assign are listed (all tools)" || { echo "  CHYBA  3.24 tools/list"; ERRORS=$((ERRORS+1)); }

# my_work for the editor's own connection: the same lists as the screen, only for the sections they may open
W324_TOKEN="kaleta_$(printf 'b%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($W324_ED, 'w324', '$(php -r 'echo hash("sha256", $argv[1]);' "$W324_TOKEN")', '$(site_time)')" > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $W324_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"my_work","arguments":{}}}' > "$WORK/response"; mcp_text
php -r '$w = json_decode((string) file_get_contents($argv[1]), true) ?: []; $ids = fn (string $k): string => implode(",", array_column($w[$k] ?? [], "id"));
  $page = array_values(array_filter($w["pages"] ?? [], fn ($p) => $p["title"] === "W324 page"))[0] ?? [];
  echo implode("|", [$ids("enquiries_assigned"), in_array((int) $argv[2], array_column($w["enquiries_unassigned"] ?? [], "id"), true) ? "unassigned" : "-", implode("+", $page["reasons"] ?? []), $page["unresolved_comments"] ?? 0,
    implode("+", array_values(array_filter($w["items"] ?? [], fn ($p) => $p["title"] === "W324 item"))[0]["reasons"] ?? []), implode(",", array_column($w["requests"] ?? [], "title")), $w["user"]["name"] ?? ""]);' "$WORK/text" "$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'W324 form D'")" > "$WORK/w324"
expect "3.24 my_work: the open assigned enquiries (newest first, not the resolved one), new unassigned ones, the page (review soon, draft, a comment), the overdue item, the request Claude answered" \
  "$(cat "$WORK/w324")" "$W324_EB,$W324_EA|unassigned|review+draft+comments|1|overdue|W324 request|Editor W324"
W324_TOKEN2="kaleta_$(printf 'c%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($W324_WRITER, 'w324', '$(php -r 'echo hash("sha256", $argv[1]);' "$W324_TOKEN2")', '$(site_time)')" > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $W324_TOKEN2" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"my_work","arguments":{}}}' > "$WORK/response"; mcp_text
contains -q '"total":0' "$WORK/text" && ! contains -q 'enquiries_assigned\|"pages"' "$WORK/text" && echo "  ok     3.24 my_work: a writer without those sections gets no enquiry or page lists" || { echo "  CHYBA  3.24 my_work for a writer: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $W324_TOKEN2" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"assign\",\"arguments\":{\"target\":\"page\",\"id\":$W324_QUIET,\"user\":\"me\"}}}" > "$WORK/response"; mcp_text
contains -q 'Only a user who may open Pages' "$WORK/text" && echo "  ok     3.24 assign: a connection whose user cannot open Pages changes nothing there" || { echo "  CHYBA  3.24 assign by a writer: $(head -c 300 "$WORK/text")"; ERRORS=$((ERRORS+1)); }

# the screen: My work first in the editor's menu (before the Dashboard), the same rows, each linking to its place
JAR324="$WORK/jar-w324"
TOKEN324=$(curl -s -c "$JAR324" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR324" -c "$JAR324" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN324" -d user=w324-editor --data-urlencode "password=$PASSWORD"
expect "3.24 My work: the editor's screen" "$(curl -s -b "$JAR324" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=my_work")|$(grep -c 'data-list="pages"' "$WORK/response")" "200|1"
grep -q "module=pages&amp;action=edit&amp;id=$W324_PAGE\"" "$WORK/response" && grep -q "module=collections&amp;action=item&amp;id=$W324_IDK&amp;item=$W324_ITEM\"" "$WORK/response" \
  && grep -q "module=enquiries&amp;action=detail&amp;id=$W324_EA\"" "$WORK/response" && grep -q 'data-list="requests"' "$WORK/response" && grep -q 'data-list="unassigned"' "$WORK/response" \
  && echo "  ok     3.24 My work: every row links to its place" || { echo "  CHYBA  3.24 My work rows"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR324" -o "$WORK/response" "$B/admin.php"
expect "3.24 My work: the first menu item for a non-administrator, with the count of enquiries assigned to them" \
  "$(php -r '$h = (string) file_get_contents($argv[1]); $w = strpos($h, "class=\"menu-moje"); $d = strpos($h, "class=\"menu-prehled"); echo $w !== false && $d !== false && $w < $d ? "first" : "no"; echo preg_match("#menu-moje.*?menu-pocet[^>]*>2<#s", $h) ? "|2" : "|-";' "$WORK/response")" "first|2"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.24 My work: an administrator keeps the Dashboard first, My work right after it" \
  "$(php -r '$h = (string) file_get_contents($argv[1]); $w = strpos($h, "class=\"menu-moje"); $d = strpos($h, "class=\"menu-prehled"); echo $w !== false && $d !== false && $d < $w ? "after" : "no";' "$WORK/response")" "after"
# 3.32 (U35): "Take it" on a new enquiry nobody handles – the editor takes it; a second take (someone has it) changes nothing
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'W332 take', 'take@visitor.example', '[]', 0)" > /dev/null
W332_TAKE=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'W332 take'")
curl -s -b "$JAR324" -o "$WORK/response" "$B/admin.php?module=my_work"
W332_CSRF=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//')
expect "3.32 (U35) My work: an unassigned enquiry has Take it" "$(grep -c "data-enquiry-take><input type=\"hidden\" name=\"_csrf\"[^>]*><input type=\"hidden\" name=\"idp\" value=\"$W332_TAKE\"" "$WORK/response")" "1"
curl -s -b "$JAR324" -c "$JAR324" -o /dev/null -X POST "$B/admin.php?module=my_work&action=take" -d "_csrf=$W332_CSRF" -d "idp=$W332_TAKE"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=take" -d "_csrf=$(csrf)" -d "idp=$W332_TAKE"
expect "3.32 (U35) My work: … Take it makes it the editor's (once, logged); taking it again changes nothing" \
  "$(sq "SELECT prirazeno FROM ka_poptavky WHERE idp = $W332_TAKE")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'enquiry_taken' AND popis = '#$W332_TAKE'")" "$W324_ED|1"
sq "DELETE FROM ka_poptavky WHERE idp = $W332_TAKE" > /dev/null
# 3.32 (U36, A2): the landing rule – the salesperson (Enquiries only, no content section) lands on My work after sign-in,
# the editor (Pages, Collections) on the Dashboard
w332_land() { local jar="$WORK/jar-w332-$1" token; token=$(curl -s -c "$jar" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$token" -d "user=$1" --data-urlencode "password=$PASSWORD" | sed 's#^http://[^/]*##'; }
expect "3.32 (U36): after sign-in the salesperson lands on My work, the editor on the Dashboard" "$(w332_land w324-sales)|$(w332_land w324-editor)" "/admin.php?module=my_work|/admin.php"
JAR324W="$WORK/jar-w324w"
TOKEN324W=$(curl -s -c "$JAR324W" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR324W" -c "$JAR324W" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN324W" -d user=w324-writer --data-urlencode "password=$PASSWORD"
expect "3.24 My work: a writer has it too (for every signed-in user) – the empty state" "$(curl -s -b "$JAR324W" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=my_work")|$(grep -c 'prazdny-stav-nadpis' "$WORK/response")" "200|1"

# Users: who owns what, and the hand-over offers only people who may open those sections
check "3.24 Users: the list says what the editor is responsible for" 200 "/admin.php?module=users" "action=handover&amp;id=$W324_ED"
check "3.24 Users: the hand-over screen" 200 "/admin.php?module=users&action=handover&id=$W324_ED&then=block" 'name="hand_over_to"'
expect "3.24 Users: the recipients may open Pages, Collections and Enquiries – the administrator yes, the salesperson and the writer not" \
  "$(grep -c "<option value=\"$W324_ADMIN\">" "$WORK/response")|$(grep -c "<option value=\"$W324_SALES\">\|<option value=\"$W324_WRITER\">" "$WORK/response")" "1|0"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=handover" -d "_csrf=$TOKEN" -d "idu=$W324_ED" -d user=w324-editor -d "hand_over_to=$W324_SALES" -d then=block
expect "3.24 Users: a recipient without the sections is refused – nothing moves, the account stays" "$(sq "SELECT CONCAT(owner_id, '|', (SELECT blokovat FROM ka_uzivatele WHERE idu = $W324_ED)) FROM ka_stranky WHERE ids = $W324_PAGE")" "$W324_ED|0"
W324_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=handover" -d "_csrf=$TOKEN" -d "idu=$W324_ED" -d user=w324-editor -d "hand_over_to=$W324_ADMIN" -d then=block
expect "3.24 Users: hand over and block in one step – the page, the item and the open enquiries go, the resolved one keeps who handled it; one change-log row" \
  "$(sq "SELECT CONCAT((SELECT owner_id FROM ka_stranky WHERE ids = $W324_PAGE), '|', (SELECT owner_id FROM ka_kolekce_polozky WHERE idp = $W324_ITEM), '|', (SELECT GROUP_CONCAT(prirazeno ORDER BY idp) FROM ka_poptavky WHERE idp IN ($W324_EA, $W324_EB, $W324_EC)), '|', (SELECT blokovat FROM ka_uzivatele WHERE idu = $W324_ED), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users') - $W324_LOG, '|', (SELECT akce FROM ka_protokol WHERE modul = 'users' ORDER BY idp DESC LIMIT 1))")" \
  "$W324_ADMIN|$W324_ADMIN|$W324_ADMIN,$W324_ADMIN,$W324_ED|1|1|block"
# the edit form: blocking the salesperson hands their enquiry to nobody in the same save
sq "UPDATE ka_poptavky SET prirazeno = $W324_SALES WHERE idp = $W324_EA" > /dev/null
# N324-4: a page of theirs in the trash moves too, so the recipient must be able to open Pages – a colleague with Enquiries only is not offered
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, posledni_login, potvrzeno) VALUES ('w324-helper', '$W324_HASH', 'Helper W324', '', 0, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user = 'w324-helper';
  INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, owner_id, smazano, zmeneno) VALUES ('w324-trashed', 'W324 trashed page', '', 0, 0, $W324_SALES, '$(site_time)', '$(site_time)')" > /dev/null
W324_HELPER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'w324-helper'")
check "3.24 Users: the edit form offers the hand-over next to Block account" 200 "/admin.php?module=users&action=edit&id=$W324_SALES" 'name="hand_over_to"'
expect "N324-4 Users: with a page in the trash the recipient must open Pages too – the administrator is offered, the colleague with Enquiries only not" \
  "$(php -r 'preg_match("#<select id=\"hand_over_to\".*?</select>#s", (string) file_get_contents($argv[1]), $m); $s = $m[0] ?? ""; echo substr_count($s, "<option value=\"" . $argv[2] . "\""), "|", substr_count($s, "<option value=\"" . $argv[3] . "\"");' "$WORK/response" "$W324_ADMIN" "$W324_HELPER")" "1|0"
TOKEN=$(csrf); W324_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$W324_SALES" -d user=w324-sales -d jmeno='Sales W324' -d email=w324-sales@example.com -d admin=0 -d blokovat=1 -d hand_over_to=0
expect "3.24 Users: block with the hand-over in the edit form – the enquiry has nobody, the account is blocked, one change-log row" \
  "$(sq "SELECT CONCAT(IFNULL((SELECT prirazeno FROM ka_poptavky WHERE idp = $W324_EA), 'none'), '|', (SELECT blokovat FROM ka_uzivatele WHERE idu = $W324_SALES), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users') - $W324_LOG, '|', IFNULL((SELECT owner_id FROM ka_stranky WHERE seo_link = 'w324-trashed'), 'none'))")" "none|1|1|none"
# a removed account: its id stays and the lists say "(former user)"
sq "UPDATE ka_stranky SET owner_id = 999999 WHERE ids = $W324_QUIET" > /dev/null
mcp list_pages '{}' > "$WORK/response"; mcp_text
contains -q '"owner":{"id":999999,"name":"(former user)"}' "$WORK/text" && echo "  ok     3.24 list_pages: a removed account is \"(former user)\"" || { echo "  CHYBA  3.24 former user"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'w324'; DELETE FROM ka_draft_comments WHERE target = 'stranka:$W324_PAGE'; DELETE FROM ka_stranky WHERE seo_link IN ('w324-page', 'w324-quiet', 'w324-trashed');
  DELETE FROM ka_kolekce_polozky WHERE seo_link = 'w324-item'; DELETE FROM ka_kolekce WHERE seo_link = 'w324-collection'; DELETE FROM ka_poptavky WHERE formular LIKE 'W324 form %'; DELETE FROM ka_requests WHERE title = 'W324 request';
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($W324_ED, $W324_SALES, $W324_WRITER, $W324_HELPER); DELETE FROM ka_uzivatele WHERE user LIKE 'w324-%'" > /dev/null
# 3.26 (agent B): notifications – Core\Inbox records who did what where, the bell in the header bar counts the unread, the
# Notifications screen opens and marks them, and the e-mail follows each person's choice: instant (one e-mail now, as before),
# daily (one summary in the morning) or off (the bell only). The assignment e-mail and the review reminder go through it.
