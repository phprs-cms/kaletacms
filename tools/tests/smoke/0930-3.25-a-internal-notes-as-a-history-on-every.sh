echo "== 3.25 (A): internal notes as a history on every enquiry – appended with who and when, the conversation, MCP add_note, GDPR"
N325=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, poznamka) VALUES ('$(site_time)', 'Kontakt 325', 'notes325@example.cz', '[[\"Zpráva\",\"Potřebuji nabídku na dveře.\"]]', 1, 'Staré shrnutí 325'); SELECT LAST_INSERT_ID();")
n325_post() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$N325"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$(csrf)" -d "idp=$N325" "$@"; }
n325_post --data-urlencode $'add_note=První poznámka 325\nna dva řádky'
n325_post --data-urlencode "add_note=   "
n325_post --data-urlencode "add_note=Druhá poznámka 325"
expect "3.25: two notes appended in order with the author and the time, an empty one refused, the summary (poznamka) untouched" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT_WS('/', kind, author_name <> '', author_id IS NOT NULL, via, created_at IS NOT NULL, REPLACE(text, '\n', '~')) ORDER BY id SEPARATOR '|') FROM ka_comments WHERE target = 'enquiry:$N325'")|$(sq "SELECT poznamka FROM ka_poptavky WHERE idp = $N325")" \
  "note/1/1/admin/1/První poznámka 325~na dva řádky|note/1/1/admin/1/Druhá poznámka 325|Staré shrnutí 325"
check "3.25: the detail shows one conversation – the summary first, the customer's message, the notes marked internal" 200 "/admin.php?module=enquiries&action=detail&id=$N325" 'data-thread-item="note"'
expect "3.25: the conversation order and the internal marking" \
  "$(grep -o 'data-thread-item="[a-z]*"' "$WORK/response" | tr '\n' ' ')|$(grep -c 'Interní – zákazník ji nevidí' "$WORK/response")|$(grep -c 'První poznámka 325<br' "$WORK/response")|$(grep -c 'name="add_note"' "$WORK/response")" \
  'data-thread-item="summary" data-thread-item="customer" data-thread-item="note" data-thread-item="note" |2|1|1'
n325_post -d poznamka=Nové%20shrnutí -d prirazeno=0
expect "3.25: the Handled by form still rewrites the summary and adds no note" "$(sq "SELECT poznamka FROM ka_poptavky WHERE idp = $N325")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'enquiry:$N325'")" "Nové shrnutí|2"
mcp update_enquiry "{\"id\":$N325,\"add_note\":\"Poznámka od Clauda 325\"}" > "$WORK/response"
expect "3.25: MCP update_enquiry add_note appends a note as the connected user (via mcp), the earlier ones stay" \
  "$(mcp_value changed)|$(sq "SELECT CONCAT_WS('/', COUNT(*), SUM(via = 'mcp'), MAX(IF(via = 'mcp', author_name <> '', 0))) FROM ka_comments WHERE target = 'enquiry:$N325'")" '["note_added"]|3/1/1'
mcp update_enquiry "{\"id\":$N325,\"add_note\":\" \"}" > "$WORK/response"
contains -q 'add_note must be the text of the note' "$WORK/response" && echo "  ok     3.25: an empty add_note is refused" || { echo "  CHYBA  3.25: empty add_note"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_enquiries '{"search":"notes325@example.cz"}' > "$WORK/response"
expect "3.25: list_enquiries rows carry notes_count (old keys kept)" "$(php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); $r = $v[0] ?? $v["enquiries"][0] ?? []; echo ($r["notes_count"] ?? "none"), "|", isset($r["email"], $r["status"], $r["fields"]) ? 1 : json_encode(array_keys($r));' -- "$WORK/response")" "3|1"
N325_EXPORT=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); foreach (Kaleta\Core\PersonalData::find($app->db(), "notes325@example.cz")["enquiries"] as $e) { echo count($e["notes"]), "|", $e["notes"][0]["text"]; }')
expect "3.25: the personal data file carries the notes on the person's enquiry" "$N325_EXPORT" "3|První poznámka 325
na dva řádky"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$N325"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$(csrf)" -d "idp=$N325"
expect "3.25: anonymising keeps who wrote each note and when, blanks the text" \
  "$(sq "SELECT CONCAT_WS('/', COUNT(*), SUM(text = ''), SUM(author_name <> ''), SUM(created_at IS NOT NULL)) FROM ka_comments WHERE target = 'enquiry:$N325'")" "3/3/3/3"
check "3.25: the anonymised detail says the note text was removed" 200 "/admin.php?module=enquiries&action=detail&id=$N325" 'data-thread-item="note"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=delete" -d "_csrf=$(csrf)" -d "idp=$N325"
expect "3.25: deleting the enquiry deletes its notes" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'enquiry:$N325'")" "0"
# erasure on request and MCP delete_enquiry take the notes too; a note left by another path is swept by the retention cleanup
N325E=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt 325', 'erase325@example.cz', '[]', 1); SELECT LAST_INSERT_ID();")
N325D=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt 325', 'delete325@example.cz', '[]', 1); SELECT LAST_INSERT_ID();")
mcp update_enquiry "{\"id\":$N325E,\"add_note\":\"Erase me\"}" > /dev/null; mcp update_enquiry "{\"id\":$N325D,\"add_note\":\"Delete me\"}" > /dev/null
mcp erase_personal_data '{"email":"erase325@example.cz","confirm":true}' > /dev/null; mcp delete_enquiry "{\"id\":$N325D}" > /dev/null
# N325-1: the sweep looks enquiries up by id and survives a malformed target (no cast in SQL under a strict sql_mode); a note of a live enquiry stays
N325K=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt 325', 'keep325@example.cz', '[]', 1); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_comments (target, kind, text, created_at) VALUES ('enquiry:987654321', 'note', 'orphan', '$(site_time)'), ('enquiry:x325', 'note', 'malformed', '$(site_time)'), ('enquiry:$N325K', 'note', 'keep', '$(site_time)')" > /dev/null
curl -s -b "$JAR" -o /dev/null "$B/admin.php?module=enquiries"
expect "T1 (Q5): the Enquiries list no longer sweeps notes on a GET" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('enquiry:987654321', 'enquiry:x325')")" "2"
run_job cleanup > /dev/null
expect "3.25: erase_personal_data, delete_enquiry and the cleanup leave no note behind (a malformed one too), the note of a live enquiry stays" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('enquiry:$N325E', 'enquiry:$N325D', 'enquiry:987654321', 'enquiry:x325')")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'enquiry:$N325K'")" "0|1"
sq "DELETE FROM ka_comments WHERE target = 'enquiry:$N325K'; DELETE FROM ka_poptavky WHERE idp = $N325K" > /dev/null
sq "DELETE FROM ka_events WHERE type = 'personal_data.erased'" > /dev/null # the personal data part below counts its own erasure
