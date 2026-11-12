echo "== 3.39 B (N105): the tools that e-mail outside the team or erase for good run only with a confirmation code"
# raw calls here (no automatic second step as in mcp_call): what the model sees
n105_call() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer ${3:-$API_TOKEN}" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}" > "$WORK/response"; mcp_text; }
n105_key() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $v = is_array($d) ? ($d[$argv[2]] ?? "") : ""; echo is_bool($v) ? ($v ? "true" : "false") : (string) $v;' -- "$WORK/text" "$1"; }
N105_OTHER="kaleta_$(php -r 'echo bin2hex(random_bytes(24));')"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'Second N105', SHA2('$N105_OTHER', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES ('$(site_time)', 'n105@example.cz', '[]')" > /dev/null; N105_E=$(sq "SELECT MAX(idp) FROM ka_poptavky")
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
expect "3.39 N105: tools/list – delete_enquiry, reply_to_enquiry and send_newsletter take confirmation, list_pages does not" \
  "$(php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "inputSchema", "name"); foreach (["delete_enquiry", "reply_to_enquiry", "send_newsletter", "list_pages"] as $n) { echo isset($t[$n]["properties"]["confirmation"]) ? 1 : 0; }' -- "$WORK/response")" "1110"
N105_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol")
n105_call delete_enquiry "{\"id\":$N105_E}"; N105_CODE=$(n105_key confirmation)
expect "3.39 N105: the first call answers with a code and does nothing – the enquiry is there, no change-log row" \
  "$(n105_key confirmation_required)|$(n105_key done)|$([ -n "$N105_CODE" ] && echo code)|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $N105_E")|$(( $(sq "SELECT COUNT(*) FROM ka_protokol") - N105_LOG ))|$(grep -c '"isError":true' "$WORK/response")" "true|false|code|1|0|0"
n105_call delete_enquiry "{\"id\":$((N105_E + 100000)),\"confirmation\":\"$N105_CODE\"}"
expect "3.39 N105: the code with changed arguments is refused (a new code comes back), nothing is deleted" "$(n105_key confirmation_required)|$(grep -c 'does not fit this call' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $N105_E")" "true|1|1"
n105_call delete_enquiry "{\"id\":$N105_E,\"confirmation\":\"$N105_CODE\"}" "$N105_OTHER"
expect "3.39 N105: the code on another connection of the same user is refused" "$(n105_key confirmation_required)|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $N105_E")" "true|1"
n105_call delete_enquiry "{\"id\":$N105_E,\"confirmation\":\"$N105_CODE\",\"reason\":\"the user asked to delete it\"}"
expect "3.39 N105: the same call with its code runs (a reason may differ) – the enquiry is gone" "$(n105_key deleted)|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $N105_E")" "$N105_E|0"
# a code works once
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES ('$(site_time)', 'n105b@example.cz', '[]')" > /dev/null
n105_call erase_personal_data '{"email":"n105b@example.cz","confirm":true}'; N105_CODE=$(n105_key confirmation)
n105_call erase_personal_data "{\"email\":\"n105b@example.cz\",\"confirm\":true,\"confirmation\":\"$N105_CODE\"}"
N105_FIRST=$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email = 'n105b@example.cz'")
n105_call erase_personal_data "{\"email\":\"n105b@example.cz\",\"confirm\":true,\"confirmation\":\"$N105_CODE\"}"
expect "3.39 N105: erase_personal_data runs with its code, and the same code again is refused (used)" "$N105_FIRST|$(n105_key confirmation_required)|$(grep -c 'already used' "$WORK/text")" "0|true|1"
# without confirm=true the tool answers as before (refusal) – no code is handed out
n105_call erase_personal_data '{"email":"n105b@example.cz"}'
expect "3.39 N105: without confirm=true the tool refuses as before – no code" "$(grep -c 'needs confirm=true' "$WORK/text")|$(grep -c 'confirmation_required' "$WORK/text")" "1|0"
# the server instructions say what the code is for
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' > "$WORK/response"
contains -q 'TWO-STEP CONFIRMATION' "$WORK/response" && echo "  ok     3.39 N105: the server instructions explain the two-step confirmation" || { echo "  CHYBA  3.39 N105: instructions"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'Second N105'" > /dev/null
