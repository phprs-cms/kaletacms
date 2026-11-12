echo "== 3.39 review L2: run_destructive_tool forwards a confirmation given next to the tool's arguments"
l2_call() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"run_destructive_tool\",\"arguments\":$1}}" > "$WORK/response"; mcp_text; }
l2_key() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $v = is_array($d) ? ($d[$argv[2]] ?? "") : ""; echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? (string) $v : "array");' -- "$WORK/text" "$1"; }
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES ('$(site_time)', 'l2-runner@example.cz', '[]')" > /dev/null
l2_call '{"name":"erase_personal_data","arguments":{"email":"l2-runner@example.cz","confirm":true}}'; L2_CODE=$(l2_key confirmation)
l2_call "{\"name\":\"erase_personal_data\",\"arguments\":{\"email\":\"l2-runner@example.cz\",\"confirm\":true},\"confirmation\":\"$L2_CODE\"}"
expect "3.39 review L2: the code given to the runner itself (like reason) reaches the tool – it runs once, the enquiry is erased" \
  "$([ -n "$L2_CODE" ] && echo code)|$(grep -c 'confirmation_required' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email = 'l2-runner@example.cz'")" "code|0|0"
