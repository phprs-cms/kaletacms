echo "== 3.39 review M4: a newsletter's confirmation binds what will be sent and shows a summary"
m4_call() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}" > "$WORK/response"; mcp_text; }
m4_key() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) ? ($v[$k] ?? "") : ""; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? (string) $v : "array");' -- "$WORK/text" "$1"; }
sq "INSERT INTO ka_newsletters (subject, intro, news_mode, created) VALUES ('M4 spring offer', 'Hello', 'none', '$(site_time)')" > /dev/null
M4_NL=$(sq "SELECT MAX(id) FROM ka_newsletters")
m4_call send_newsletter "{\"id\":$M4_NL}"; M4_CODE=$(m4_key confirmation)
expect "3.39 review M4: the first send_newsletter answer carries a server-rendered summary – the subject and how many subscribers get it – and sends nothing" \
  "$(m4_key confirmation_required)|$(m4_key summary.subject)|$([ -n "$(m4_key summary.recipients)" ] && echo counted)|$(m4_key summary.when)|$(sq "SELECT status FROM ka_newsletters WHERE id = $M4_NL")" \
  "true|M4 spring offer|counted|now|draft"
# the newsletter is edited after the user's yes: the code no longer fits, a new one comes with the new subject
sq "UPDATE ka_newsletters SET subject = 'M4 changed after the yes' WHERE id = $M4_NL" > /dev/null
m4_call send_newsletter "{\"id\":$M4_NL,\"confirmation\":\"$M4_CODE\"}"
expect "3.39 review M4: a newsletter changed after its code was given is refused – a new code and the new subject come back, nothing is sent" \
  "$(m4_key confirmation_required)|$(grep -c 'changed arguments or content' "$WORK/text")|$(m4_key summary.subject)|$(sq "SELECT status FROM ka_newsletters WHERE id = $M4_NL")" \
  "true|1|M4 changed after the yes|draft"
sq "DELETE FROM ka_newsletters WHERE id = $M4_NL" > /dev/null
