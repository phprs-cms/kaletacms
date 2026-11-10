echo "== 3.1: Ask Claude on the dashboard"
expect "3.5: the first call of a Claude connection is remembered (claude_first_used)" "$(sq "SELECT hodnota REGEXP '^[0-9]{4}-' FROM ka_nastaveni WHERE promenna = 'claude_first_used'")" "1"
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" "$B/admin.php"
contains -q 'id="ask-claude-text"' "$WORK/response" && contains -q 'name="quick" value="1"' "$WORK/response" && ! contains -q 'data-ask-claude-example' "$WORK/response" && contains -q 'Nový ceník na stránku Služby' "$WORK/response" \
  && echo "  ok     ask: a staff user with only Requests gets the box and their requests, no examples for sections they cannot open" || { echo "  CHYBA  ask box for staff"; ERRORS=$((ERRORS+1)); }
REQ_CSRF=$(csrf)
ASK_URL=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=requests&action=save" -F "_csrf=$REQ_CSRF" -F quick=1 -F from=dashboard -F "text=Zavřeno od 24. do 26. prosince. Dejte to prosím na úvodní stránku i do patičky.")
expect "ask: the box saves a request titled by its first sentence and goes back to the dashboard" "$(sq "SELECT CONCAT(title, '|', status) FROM ka_requests ORDER BY id DESC LIMIT 1")|${ASK_URL##*/}" "Zavřeno od 24. do 26. prosince.|new|admin.php"
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php?module=requests&action=save" -d "_csrf=$REQ_CSRF" -d quick=1 -d from=dashboard -d "text=   "
expect "ask: an empty box saves nothing" "$(sq "SELECT COUNT(*) FROM ka_requests WHERE title = ''")" "0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
contains -q 'data-ask-claude-example="triage"' "$WORK/response" && contains -q 'data-ask-claude-copy' "$WORK/response" && contains -q 'ask-claude-kdy' "$WORK/response" \
  && echo "  ok     ask: the administrator gets examples, the copy for the Claude app and whether a scheduled run picks requests up" || { echo "  CHYBA  ask box for the administrator"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_uzivatele SET email = '' WHERE user = 'admin'" > /dev/null
