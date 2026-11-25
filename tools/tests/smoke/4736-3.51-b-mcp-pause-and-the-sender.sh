echo "== 3.51 B MCP: send_newsletter pause without a code, one action per call; warnings; a scheduled newsletter without a sender goes back to draft"
# a full connection of its own: the shared token of the setup may be limited or gone after the sections before this one
B736_TOKEN="kaleta_$(openssl rand -hex 24)"
b351_db "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'b736 admin', 'full', 'all', SHA2('$B736_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'"
b736_raw() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $B736_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}" > "$WORK/response"; }
b736_value() { php -r '$v = json_decode((string) (json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "null"), true); foreach (array_slice($argv, 2) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' -- "$WORK/response" "$@"; }
b351_clear; b351_db "DELETE FROM ka_newsletter_queue"
b351_subscribers m1@example.cz m2@example.cz
B736_NL=$(b351_newsletter "MCP 736"); b351_alive
b736_raw send_newsletter "{\"id\":$B736_NL}"
B736_SEND=$(b736_value confirmation_required)
mcp_call "$B736_TOKEN" send_newsletter "{\"id\":$B736_NL}" > /dev/null
b736_raw send_newsletter "{\"id\":$B736_NL,\"pause\":true}"
expect "3.51 MCP: sending still needs the code; pause: true stops the send at once without one" "$B736_SEND|$(b736_value confirmation_required)|$(b736_value status)|$(b351_state "$B736_NL")" "1|null|paused|paused|2|0|0|0"
mcp_call "$B736_TOKEN" list_newsletters '{}' > "$WORK/response"
expect "3.51 MCP: list_newsletters – a paused one has paused_at, pause_reason, cancel_on and its counts; warnings is a list" \
  "$(b736_value newsletters 0 pause_reason)|$([ -n "$(b736_value newsletters 0 paused_at)" ] && [ -n "$(b736_value newsletters 0 cancel_on)" ] && echo dates)|$(b736_value newsletters 0 recipients)|$(b736_value warnings)" "manual|dates|2|[]"
expect "3.54 C: the paused one also has its results line – nobody unsubscribed through it, visits unknown (no campaign name for two recipients)" "$(b736_value newsletters 0 results)" '{"unsubscribed":0,"visits":null}'
mcp_call "$B736_TOKEN" send_newsletter "{\"id\":$B736_NL,\"pause\":true,\"at\":\"2099-01-01 08:00\"}" > "$WORK/response"
expect "3.51 MCP: pause together with at needs a code and is then refused – choose one" "$(grep -c 'Choose one' "$WORK/response")|$(b351_db "SELECT CONCAT(status, '|', scheduled_at IS NULL) FROM ka_newsletters WHERE id = $B736_NL")" "1|paused|1"
b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('newsletter_service', 'brevo')"; mcp_call "$B736_TOKEN" list_newsletters '{}' > "$WORK/response"; b351_db "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'newsletter_service'"
expect "3.51 B6: a connected mailing service is a warning, not a refusal" "$(b736_value warnings | grep -c 'mailing service')|$(b736_value sending_problem)" "1|null"
b351_admin cancel -d "id=$B736_NL"

# no company name: the newsletter cannot be sent, and a due scheduled one goes back to the drafts, its author told
B736_COMPANY=$(b351_db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_name'")
b351_db "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'company_name'"
mcp_call "$B736_TOKEN" list_newsletters '{}' > "$WORK/response"; B736_PROBLEM=$(b736_value sending_problem)
B736_DUE=$(b351_newsletter "Bez odesílatele 736"); b351_db "UPDATE ka_newsletters SET status = 'scheduled', scheduled_at = '$(site_time '-1 minute')' WHERE id = $B736_DUE"
b351_clear; b351_cron; b351_wait 1
expect "3.51 B5: without the company name – sending_problem says so; a due scheduled newsletter is a draft again, the change log says why, its author gets one e-mail, the subscribers nothing" \
  "$B736_PROBLEM|$(b351_db "SELECT CONCAT(status, '|', scheduled_at IS NULL, '|', recipients) FROM ka_newsletters WHERE id = $B736_DUE")|$(b351_db "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'newsletters' AND akce = 'unschedule' AND duvod LIKE 'not sent: the sender%'")|$(b351_mails admin@example.cz)|$(b351_mails m1@example.cz)" \
  "A newsletter must name its sender – fill in the company name and postal address in Business details.|draft|1|0|1|1|0"
check "3.51 B5: the newsletter screen tells what is missing" 200 "/admin.php?module=newsletters" "Newsletter musí uvádět odesílatele"
b351_db "UPDATE ka_nastaveni SET hodnota = '$B736_COMPANY' WHERE promenna = 'company_name'; DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters WHERE id IN ($B736_NL, $B736_DUE); DELETE FROM ka_odberatele; DELETE FROM ka_api_tokeny WHERE nazev = 'b736 admin'"
kill "$B351_PID" 2>/dev/null || true
b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES"
background_jobs resume
