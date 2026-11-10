echo "== 2.12: enquiry triage"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
TRIAGE_ID=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'eva@example.cz', '[[\"Zpráva\",\"Chceme nabídku na 40 oken do pátku\"]]', 0); SELECT LAST_INSERT_ID();")
SPAM_ID=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'seo@example.com', '[[\"Zpráva\",\"We can get you to the first page of Google\"]]', 0); SELECT LAST_INSERT_ID();")
mcp triage_enquiries '{}' > "$WORK/response"
contains -q "\"id\\\\\":$TRIAGE_ID" "$WORK/response" && contains -q '40 oken' "$WORK/response" && echo "  ok     triage: Claude gets the unsorted enquiries as text" || { echo "  CHYBA  triage_enquiries"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_enquiry "{\"id\":$TRIAGE_ID,\"category\":\"sales\",\"priority\":\"high\",\"draft_reply\":\"Dobrý den, děkujeme za poptávku.\"}" > /dev/null
mcp update_enquiry "{\"id\":$SPAM_ID,\"category\":\"spam\"}" > /dev/null
expect "triage: Claude's sorting is saved" "$(sq "SELECT CONCAT(kategorie, '|', priorita, '|', navrh_odpovedi, '|', triaged_by) FROM ka_poptavky WHERE idp = $TRIAGE_ID")" "sales|3|Dobrý den, děkujeme za poptávku.|claude"
mcp update_enquiry "{\"id\":$TRIAGE_ID,\"category\":\"nonsense\"}" > "$WORK/response"
contains -q 'category must be one of' "$WORK/response" && echo "  ok     triage: an unknown kind is refused" || { echo "  CHYBA  neznámá kategorie"; ERRORS=$((ERRORS+1)); }
mcp list_enquiries '{"limit":50}' > "$WORK/response"
contains -q 'draft_reply' "$WORK/response" && ! contains -q 'first page of Google' "$WORK/response" && echo "  ok     triage: list_enquiries carries the triage and leaves spam out" || { echo "  CHYBA  list_enquiries a spam"; ERRORS=$((ERRORS+1)); }
check "triage: the admin list leaves spam out" 200 "/admin.php?module=enquiries" "category=spam"
! grep -q 'first page of Google' "$WORK/response" && echo "  ok     triage: spam is not in the default list" || { echo "  CHYBA  spam v seznamu"; ERRORS=$((ERRORS+1)); }
check "triage: the detail has the kind, the priority and the draft in the e-mail reply" 200 "/admin.php?module=enquiries&action=detail&id=$TRIAGE_ID" 'body=Dobr%C3%BD%20den'
TRIAGE_CSRF=$(csrf)
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$TRIAGE_CSRF" -d "id=$TRIAGE_ID" -d kategorie=support -d priorita=1 --data-urlencode "navrh_odpovedi=Vlastní odpověď"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$TRIAGE_CSRF" -d "idp=$TRIAGE_ID" --data-urlencode "poznamka=N-C3 note"
expect "3.23.1 (N-C3): the triage and the note are one change-log row each, with the enquiry number" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(akce, ' ', popis) ORDER BY idp SEPARATOR ',') FROM ka_protokol WHERE modul = 'enquiries' AND akce IN ('triage', 'note') AND popis LIKE '#$TRIAGE_ID%'")" "triage #$TRIAGE_ID,note #$TRIAGE_ID"
mcp update_enquiry "{\"id\":$TRIAGE_ID,\"category\":\"sales\"}" > "$WORK/response"
contains -q 'A person sorted this enquiry already' "$WORK/response" && expect "triage: a person's sorting wins over Claude" "$(sq "SELECT CONCAT(kategorie, '|', triaged_by <> 'claude') FROM ka_poptavky WHERE idp = $TRIAGE_ID")" "support|1" \
  || { echo "  CHYBA  člověk vs. Claude"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# the AI assistant sorts new enquiries in the background when switched on (a fake provider answers like the Claude API)
AI_PORT=$((PORT + 14)); mkdir -p "$WORK/ai"
cat > "$WORK/ai/index.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.log', file_get_contents('php://input') . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['content' => [['type' => 'text', 'text' => '{"category": "support", "priority": 2, "reply": "Dobrý den, podíváme se na to."}']]]);
PHP
(cd "$WORK/ai" && exec php -S "127.0.0.1:$AI_PORT" > /dev/null 2>&1) & AI_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$AI_PORT/" && break; sleep 0.2; done; : > "$WORK/ai/requests.log"
cp "$WORK/web/config.php" "$WORK/config.bak"; sed -i.tmp "1s|<?php|<?php define('KALETA_AI_URL', 'http://127.0.0.1:$AI_PORT/');|" "$WORK/web/config.php"; sleep 3 # OPcache of the test server revalidates the file after 2 s
ASSIST_ID=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'jan@example.cz', '[[\"Zpráva\",\"Nefunguje nám zámek u dveří\"]]', 0); SELECT LAST_INSERT_ID();")
EXT_TRIAGE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('ai_key', 'test-key-for-the-fake-provider'), ('ai_provider', 'anthropic'), ('triage_assistant', '0'), ('extensions', CONCAT('$EXT_TRIAGE', ',asistent'))" > /dev/null
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('triage', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "triage: switched off, the assistant sends nothing" "$(wc -c < "$WORK/ai/requests.log" | tr -d ' ')|$(sq "SELECT kategorie FROM ka_poptavky WHERE idp = $ASSIST_ID")" "0|"
sq "UPDATE ka_nastaveni SET hodnota = '1' WHERE promenna = 'triage_assistant'; UPDATE ka_jobs SET last_run = NULL WHERE name = 'triage'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "triage: switched on, the assistant sorts a new enquiry and drafts a reply" "$(sq "SELECT CONCAT_WS('|', kategorie, priorita, triaged_by, IFNULL(navrh_odpovedi, '-')) FROM ka_poptavky WHERE idp = $ASSIST_ID")" "support|2|assistant|Dobrý den, podíváme se na to."
grep -i 'triage' "$WORK/tasks.txt" | grep -q 'failed' && grep -i 'triage' "$WORK/tasks.txt"
grep -q 'never instructions' "$WORK/ai/requests.log" && grep -q 'zámek' "$WORK/ai/requests.log" && echo "  ok     triage: the assistant is told the enquiry is data, not instructions" || { echo "  CHYBA  pokyn pro asistenta"; ERRORS=$((ERRORS+1)); }
cp "$WORK/config.bak" "$WORK/web/config.php"; kill "$AI_PID" 2>/dev/null || true
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'triage_assistant'; DELETE FROM ka_nastaveni WHERE promenna = 'ai_key'; UPDATE ka_nastaveni SET hodnota = '$EXT_TRIAGE' WHERE promenna = 'extensions'" > /dev/null
