echo "== 2.14: personal data requests"
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$(site_time)', 'PD', 'pd.person@example.com', '[[\"Name\",\"PD Person\"]]'), ('$(site_time)', 'PD', 'other@example.com', '[[\"Colleague\",\"PD.Person@example.com\"]]'), ('$(site_time)', 'PD', 'keep@example.com', '[[\"Name\",\"Keep\"]]')" > /dev/null
sq "INSERT INTO ka_odberatele (email, stav, token, datum) VALUES ('pd.person@example.com', 1, '0123456789abcdef0123456789abcdef', '$(site_time)')" > /dev/null
mcp find_personal_data '{"email":" PD.Person@Example.com "}' > "$WORK/response"; mcp_text
contains -q '"enquiries":2' "$WORK/text" && contains -q '"subscriber":1' "$WORK/text" && ! contains -q 'PD Person' "$WORK/text" && echo "  ok     personal data: Claude finds the enquiries (sender and any field) and the subscription, counts only" || { echo "  CHYBA  find_personal_data"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "personal data: the administrator's screen lists what the site keeps" 200 "/admin.php?module=enquiries&action=personal" "osobni-email"
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d email=pd.person@example.com -d provest=export
php -r '$j = json_decode(file_get_contents($argv[1]), true); exit(count($j["enquiries"] ?? []) === 2 && ($j["subscriber"]["email"] ?? "") === "pd.person@example.com" ? 0 : 1);' -- "$WORK/response" \
  && echo "  ok     personal data: the export is a JSON file for the person" || { echo "  CHYBA  personal data export"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp erase_personal_data '{"email":"pd.person@example.com"}' > "$WORK/response"
expect "personal data: erasing needs an explicit confirmation" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'PD'")" "3"
# 3.3.2 (N29): an enquiry a Claude session of an older release journaled, and a change of it now – which is no longer journaled
PD_IDP=$(sq "SELECT idp FROM ka_poptavky WHERE email = 'pd.person@example.com'")
sq "INSERT INTO ka_agent_sessions (connection, started_at, last_at, calls) VALUES ('pd-old', '$(site_time)' - INTERVAL 3 HOUR, '$(site_time)' - INTERVAL 3 HOUR, 1);
  INSERT INTO ka_agent_journal (session_id, call_no, tool, tbl, row_key, before_row, after_row, created_at) SELECT LAST_INSERT_ID(), 1, 'delete_enquiry', 'poptavky', CONCAT('{\"idp\":', idp, '}'),
  JSON_OBJECT('idp', idp, 'datum', datum, 'formular', formular, 'email', email, 'data', data), NULL, '$(site_time)' - INTERVAL 3 HOUR FROM ka_poptavky WHERE idp = $PD_IDP" > /dev/null
PD_OLD=$(sq "SELECT MAX(id) FROM ka_agent_sessions WHERE connection = 'pd-old'")
mcp update_enquiry "{\"id\":$PD_IDP,\"status\":\"read\"}" > /dev/null
mcp erase_personal_data '{"email":"pd.person@example.com","confirm":true}' > /dev/null
expect "personal data: erased – both enquiries and the subscriber; other people's enquiry stays; the log has no address" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'PD'), '|', (SELECT COUNT(*) FROM ka_odberatele WHERE email = 'pd.person@example.com'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'personal_data.erased' AND data NOT LIKE '%@%'))")" "1|0|1"
expect "3.3.2 personal data: the undo journal holds no copy of the erased address – enquiries are not journaled and an older entry is redacted" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_agent_journal WHERE LOWER(CONCAT_WS('|', before_row, after_row)) LIKE '%pd.person@example.com%'), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE tbl = 'poptavky' AND untracked IS NULL), '|', (SELECT COUNT(*) FROM ka_agent_journal WHERE session_id = $PD_OLD AND untracked = 'personal data erased on request'))")" "0|0|1"
mcp undo_agent_session "{\"id\":$PD_OLD,\"confirm\":true}" > "$WORK/response"; mcp_text
expect "3.3.2 personal data: undoing the older session does not bring the erased enquiry back and names the redacted write" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $PD_IDP")|$(grep -c 'personal data erased on request' "$WORK/text")" "0|1"
sq "DELETE FROM ka_poptavky WHERE formular = 'PD'" > /dev/null
