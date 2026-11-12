echo "== webhooks: signature, delivery log and retries (1.8)"
check "Settings → Webhooks shows the secret and the log" 200 "/admin.php?module=settings&tab=webhooks" "$(setting_plain webhook_secret)"
grep -q "<code>nova_poptavka</code>" "$WORK/response" && echo "  ok     the log lists the enquiry call" || { echo "  CHYBA  the delivery log"; ERRORS=$((ERRORS+1)); }
webhook_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=webhooks"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=$1" -d "_csrf=$(csrf)" -d tab=webhooks "${@:2}"; }
: > "$WORK/hook/calls.log"; webhook_action test_webhook
expect "test call signed and logged" "$(hook_check 1)" "test|signed|/crm"
db_q() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
db_q "UPDATE ka_nastaveni SET hodnota = 'https://hooks.example.com/chyba' WHERE promenna = 'webhook_enquiries'"
: > "$WORK/hook/calls.log"; webhook_action test_webhook
FAILED=$(db_q "SELECT MAX(id) FROM ka_webhook_deliveries")
expect "a failed call waits for the next attempt with the reason" "$(db_q "SELECT CONCAT(attempts, '|', status, '|', error, '|', next_attempt > '$(site_time)', '|', body IS NOT NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")" "1|500|HTTP 500|1|1"
for i in 2 3 4 5 6; do db_q "UPDATE ka_webhook_deliveries SET next_attempt = '$(site_time)' WHERE id = $FAILED"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"; done
expect "after six attempts the call is given up but kept for sending again" "$(db_q "SELECT CONCAT(attempts, '|', next_attempt IS NULL, '|', delivered IS NULL, '|', body IS NOT NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")|$(wc -l < "$WORK/hook/calls.log" | tr -d ' ')" "6|1|1|1|6"
check "the given-up call has Send again" 200 "/admin.php?module=settings&tab=webhooks" "name=\"id\" value=\"$FAILED\""
db_q "UPDATE ka_webhook_deliveries SET url = 'https://hooks.example.com/crm' WHERE id = $FAILED"
webhook_action retry_webhook -d "id=$FAILED"
expect "Send again delivers it" "$(db_q "SELECT CONCAT(attempts, '|', status, '|', delivered IS NOT NULL, '|', body IS NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")" "7|204|1|1"
OLD_SECRET=$(setting_plain webhook_secret); webhook_action new_webhook_secret
NEW_SECRET=$(setting_plain webhook_secret)
expect "a new secret replaces the old one" "$([ "$NEW_SECRET" != "$OLD_SECRET" ] && [ "${NEW_SECRET#whsec_}" != "$NEW_SECRET" ] && echo 1)" "1"
mcp site_info '{}' | contains "whsec_" && { echo "  CHYBA  MCP shows the webhook secret"; ERRORS=$((ERRORS+1)); } || echo "  ok     the webhook secret stays out of MCP"
db_q "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('webhook_enquiries', 'webhook_test_url')"
kill "$HOOK_PID" 2>/dev/null || true

