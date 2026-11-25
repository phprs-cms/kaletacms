echo "== 3.54 MCP: draft_newsletter sets the audience (a drafts connection too, which cannot send); send_newsletter's code binds the audience and the number, its summary shows a problem, the confirmed number reaches the send"
s354_settings_save 5030
S5030_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter +jazyky +customers +pipeline -quotes
s354_sending_site
s354_fixture
# a full and a drafts-only connection of their own (the shared token may be limited or gone after earlier sections)
S5030_FULL="kaleta_$(openssl rand -hex 24)"; S5030_DRAFTS="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 's5030 full', 'full', 'all', SHA2('$S5030_FULL', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'
  UNION ALL SELECT idu, 's5030 drafts', 'drafts', 'all', SHA2('$S5030_DRAFTS', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
s5030_raw() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}" > "$WORK/response"; }
# s5030_value <key>…: a value of the tool result (the text of the answer as JSON); an error text is printed as it is
s5030_value() { php -r '$t = (string) (json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? ""); $v = json_decode($t, true); if (!is_array($v)) { echo $t; exit; }
  foreach (array_slice($argv, 2) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));' -- "$WORK/response" "$@"; }
s5030_state() { sq "SELECT CONCAT(status, '/', recipients, '/', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = ka_newsletters.id)) FROM ka_newsletters WHERE id = ${1:-0}"; }

# a drafts-only connection writes the audience and gets the number now – it cannot send
s5030_raw "$S5030_DRAFTS" draft_newsletter '{"subject":"S5030 customers in Czech","news_mode":"none","intro":"Dobrý den","audience":"language","customers_only":true}'
S5030_ID=$(s5030_value id)
expect "3.54 MCP: draft_newsletter on a drafts connection stores the audience and answers audience, customers_only and recipients_now (one Czech customer)" \
  "$(s5030_value audience)|$(s5030_value customers_only)|$(s5030_value recipients_now)|$(sq "SELECT CONCAT(audience, '/', audience_filter) FROM ka_newsletters WHERE id = ${S5030_ID:-0}")" "language|true|1|language/customers"
s5030_raw "$S5030_DRAFTS" send_newsletter "{\"id\":$S5030_ID}"
expect "3.54 MCP: the drafts connection cannot send it" "$(grep -c 'can only save drafts' "$WORK/response")|$(s5030_state "$S5030_ID")" "1|draft/0/0"
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"audience\":\"customer\"}"; S5030_TYPO=$(s5030_value)
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"customers_only\":\"maybe\"}"; S5030_MAYBE=$(s5030_value)
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"customers_only\":\"false\",\"audience\":\"all\"}"; S5030_FALSE="$(s5030_value audience)/$(s5030_value customers_only)/$(s5030_value recipients_now)"
expect "3.54 MCP: a typo in the audience and a customers_only that is no boolean are refused – never read as everyone; the string \"false\" is false" \
  "$S5030_TYPO|$S5030_MAYBE|$S5030_FALSE" "audience must be all or language.|customers_only must be true or false.|all/false/7"

# the code binds the audience: an audience changed after the yes needs a new code, and the new summary shows the new number
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"audience\":\"language\",\"customers_only\":true}"
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID}"; S5030_CODE=$(s5030_value confirmation)
S5030_FIRST="$(s5030_value summary recipients)/$(s5030_value summary audience)/$(s5030_value summary customers_only)/$(s5030_value summary problem)"
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"customers_only\":false}"
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID,\"confirmation\":\"$S5030_CODE\"}"
expect "3.54 MCP: the summary names who and how many (1 Czech customer); after the audience changed the code is refused, the new summary says 4, nothing is sent" \
  "$S5030_FIRST|$(s5030_value confirmation_required)|$(grep -c 'changed arguments or content' "$WORK/response")|$(s5030_value summary recipients)/$(s5030_value summary customers_only)|$(s5030_state "$S5030_ID")" \
  "1/language/true/null|true|1|4/false|draft/0/0"
# … and the number: a subscriber who confirms after the yes changes what the code was given for
S5030_CODE=$(s5030_value confirmation)
sq "UPDATE ka_odberatele SET stav = 1, potvrzeno = '$(site_time)' WHERE email = 's354-cs4@example.com'" > /dev/null
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID,\"confirmation\":\"$S5030_CODE\"}"
expect "3.54 MCP: one more confirmed subscriber after the yes – the code is refused and the new summary says 5" \
  "$(s5030_value confirmation_required)|$(grep -c 'changed arguments or content' "$WORK/response")|$(s5030_value summary recipients)|$(s5030_state "$S5030_ID")" "true|1|5|draft/0/0"

# a refused audience is said in the summary, before anyone is asked to confirm a send that cannot happen
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"customers_only\":true}"
features -pipeline
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID}"; S5030_OFF=$(s5030_value summary problem)
features +pipeline
sq "UPDATE ka_odberatele SET stav = 0 WHERE email IN ('s354-cs1@example.com', 's354-cs4@example.com')" > /dev/null
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID}"; S5030_NOBODY="$(s5030_value summary recipients)/$(s5030_value summary problem)"
sq "UPDATE ka_odberatele SET stav = 1 WHERE email IN ('s354-cs1@example.com', 's354-cs4@example.com')" > /dev/null
expect "3.54 MCP: the summary carries problem – customers with the pipeline and quotes off, and an audience with nobody in it (one Czech subscriber without a customer record yet)" \
  "$S5030_OFF|$S5030_NOBODY" "Only customers needs the sales pipeline or quotes switched on in Extensions – switch one on, or send to everyone in the audience.|0/None of the confirmed subscribers is a customer yet – some of them are not linked to a customer record yet; that happens within a few minutes."

# the number the summary showed is the one the send compares: a sign-up between the yes and the start (a trigger plays it the
# moment the code is used up) starts nothing – the answer names the new number and a new code is needed
s5030_raw "$S5030_FULL" draft_newsletter "{\"id\":$S5030_ID,\"subject\":\"S5030 customers in Czech\",\"customers_only\":false}"
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID}"; S5030_CODE=$(s5030_value confirmation); S5030_SHOWN=$(s5030_value summary recipients)
sq "CREATE TRIGGER s5030_signup AFTER INSERT ON ka_kontrola_ip FOR EACH ROW INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno, language)
    SELECT 's5030-late@example.com', 1, SUBSTRING(SHA2(RAND(), 256), 1, 32), NOW(), NOW(), '' FROM DUAL WHERE NEW.typ = 'mcp_confirm'" > /dev/null
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID,\"confirmation\":\"$S5030_CODE\"}"
sq "DROP TRIGGER s5030_signup" > /dev/null
expect "3.54 MCP: a sign-up after the code was used up – the send stops with the confirmed and the new number, the newsletter stays a draft without queue rows" \
  "$S5030_SHOWN|$(grep -c '"isError":true' "$WORK/response")|$(s5030_value)|$(s5030_state "$S5030_ID")" \
  "5|1|The number of recipients changed: was 5, now 6 – confirm again.|draft/0/0"
mcp_call "$S5030_FULL" send_newsletter "{\"id\":$S5030_ID}" > "$WORK/response"
expect "3.54 MCP: confirmed again, it starts – status, recipients and queue rows are the 6 of the new summary" "$(s5030_value status)|$(s5030_state "$S5030_ID")" "sending|sending/6/6"

# list_newsletters: what the site offers, the number now of a draft, the results of one that started (no site link = no campaign name = no visits)
s5030_raw "$S5030_FULL" draft_newsletter '{"subject":"S5030 for everyone","news_mode":"none","intro":"Hello"}'; S5030_ALL=$(s5030_value id)
mcp_call "$S5030_FULL" list_newsletters '{}' > "$WORK/response"
S5030_ROWS=$(php -r '$v = json_decode((string) json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); foreach ($v["newsletters"] as $n) { if (in_array($n["id"], [(int) $argv[2], (int) $argv[3]], true)) {
  echo $n["id"] === (int) $argv[2] ? "started:" . json_encode($n["results"] ?? null) . ":" . (array_key_exists("recipients_now", $n) ? "now" : "-") : "draft:" . $n["audience"] . ":" . ($n["recipients_now"] ?? "-") . ":" . (array_key_exists("results", $n) ? "results" : "-"), " "; } }' -- "$WORK/response" "$S5030_ID" "$S5030_ALL")
expect "3.54 MCP: list_newsletters – audiences offered (language, customers), confirmed_subscribers stays global; the started one has results (unsubscribed 0, visits null), the draft for everyone its 9 now" \
  "$(s5030_value audiences)|$(s5030_value confirmed_subscribers)|$S5030_ROWS" '["language","customers"]|9|draft:all:9:- started:{"unsubscribed":0,"visits":null}:- '

# a scheduled send binds no number – it is counted again at its start: the code stays good when someone leaves meanwhile
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ALL,\"at\":\"2099-01-01 08:00\"}"; S5030_CODE=$(s5030_value confirmation); S5030_LATER=$(s5030_value summary when)
sq "UPDATE ka_odberatele SET stav = 0 WHERE email = 's5030-late@example.com'" > /dev/null
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_ALL,\"at\":\"2099-01-01 08:00\",\"confirmation\":\"$S5030_CODE\"}"
expect "3.54 MCP: scheduling – the code given for 9 still schedules it after one left (8 now); it is counted again when it starts" \
  "$S5030_LATER|$(s5030_value status)|$(s5030_value recipients_now)" "2099-01-01 08:00|scheduled|8"

# a blank `at` is "now" (the handler trims it): the summary says now and the code binds the number – one more confirmed
# subscriber after the yes refuses the code instead of sending to a number nobody saw (3.54 review)
s5030_raw "$S5030_FULL" draft_newsletter '{"subject":"S5030 blank at","news_mode":"none","intro":"Hello"}'; S5030_BLANK=$(s5030_value id)
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_BLANK,\"at\":\" \"}"; S5030_CODE=$(s5030_value confirmation); S5030_WHEN="$(s5030_value summary when)/$(s5030_value summary recipients)"
sq "UPDATE ka_odberatele SET stav = 1 WHERE email = 's5030-late@example.com'" > /dev/null
s5030_raw "$S5030_FULL" send_newsletter "{\"id\":$S5030_BLANK,\"at\":\" \",\"confirmation\":\"$S5030_CODE\"}"
expect "3.54 MCP: a blank at is now – the summary says now/8, one more confirmed subscriber refuses the code (new summary 9), nothing is sent" \
  "$S5030_WHEN|$(s5030_value confirmation_required)|$(s5030_value summary recipients)|$(s5030_state "$S5030_BLANK")" "now/8|true|9|draft/0/0"

sq "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters WHERE subject LIKE 'S5030 %'; DELETE FROM ka_odberatele WHERE email = 's5030-late@example.com'; DELETE FROM ka_api_tokeny WHERE nazev LIKE 's5030 %'" > /dev/null
features --exact "$S5030_EXT"
s354_settings_restore 5030
