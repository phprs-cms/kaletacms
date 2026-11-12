echo "== 3.40 B: quotes II – a price list per customer, the customer's Accept / Decline, first opened on, the follow-up task, saved line sets"
B340_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$B340_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$B340_EXT"),quotes'), ('quote_vat_rate', '21')" > /dev/null
b340_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
b340_post() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/b340-headers" -X POST "$B/admin.php?module=quotes&action=$1" -d "_csrf=$B340_CSRF" "${@:2}"; { grep -i '^location:' "$WORK/b340-headers" || true; } | tr -d '\r' | sed 's/^[Ll]ocation: //'; }
B340_NOW=$(site_time)
B340_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B340_DEFAULT=$(b340_php 'echo Kaleta\Core\Prices::defaultList($db, $app->settings())["id"];')
sq "INSERT INTO ka_price_lists (name, currency, is_default, created_at, updated_at) VALUES ('Partneři 340', 'CZK', 0, '$B340_NOW', '$B340_NOW')" > /dev/null
B340_PARTNER=$(sq "SELECT id FROM ka_price_lists WHERE name = 'Partneři 340'"); B340_PARTNER="${B340_PARTNER:-0}"
sq "INSERT INTO ka_prices (price_list_id, item_id, variant_code, code, label, unit_price, unit, vat_rate, note, updated_at) VALUES
  ($B340_DEFAULT, NULL, '', 'S340', 'Servis 340', 100.00, 'h', 21.00, '', '$B340_NOW'), ($B340_PARTNER, NULL, '', 'S340', 'Servis 340', 80.00, 'h', 21.00, '', '$B340_NOW')" > /dev/null
sq "INSERT INTO ka_companies (name, created_at, updated_at) VALUES ('Firma 340', '$B340_NOW', '$B340_NOW')" > /dev/null
B340_COMPANY=$(sq "SELECT id FROM ka_companies WHERE name = 'Firma 340'"); B340_COMPANY="${B340_COMPANY:-0}"
sq "INSERT INTO ka_contacts (email, email_norm, name, company_id, source, created_at, updated_at) VALUES ('odberatel340@example.cz', 'odberatel340@example.cz', 'Olga Odběratelka', $B340_COMPANY, 'manual', '$B340_NOW', '$B340_NOW')" > /dev/null
B340_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'odberatel340@example.cz'"); B340_CONTACT="${B340_CONTACT:-0}"
B340_WHO=(--data-urlencode "customer_name=Olga Odběratelka" -d customer_company="Firma 340" -d customer_email=odberatel340@example.cz) # the editor posts the customer block

# a price list per company or contact: chosen in the box Quotes on the record, taken by a new quote for them
check "3.40 B: the company's box Quotes offers the price list for new quotes" 200 "/admin.php?module=contacts&action=company&id=$B340_COMPANY" 'data-quote-customer-list'
B340_CSRF=$(csrf)
B340_LOC=$(b340_post customer_list -d kind=company -d id=$B340_COMPANY -d price_list_id=$B340_PARTNER)
expect "3.40 B: the company gets the partner price list (back to its box)" "${B340_LOC##*\?}|$(sq "SELECT IFNULL(price_list_id, '-') FROM ka_companies WHERE id = $B340_COMPANY")" \
  "module=contacts&action=company&id=$B340_COMPANY#quotes|$B340_PARTNER"
check "3.40 B: the box shows the chosen list" 200 "/admin.php?module=contacts&action=company&id=$B340_COMPANY" "<option value=\"$B340_PARTNER\" selected>"
b340_post create -d contact=$B340_CONTACT > /dev/null
B340_Q1=$(sq "SELECT MAX(id) FROM ka_quotes WHERE contact_id = $B340_CONTACT"); B340_Q1="${B340_Q1:-0}"
b340_post customer_list -d kind=contact -d id=$B340_CONTACT -d price_list_id=$B340_DEFAULT > /dev/null
b340_post create -d company=$B340_COMPANY > /dev/null
B340_Q2=$(sq "SELECT MAX(id) FROM ka_quotes WHERE company_id = $B340_COMPANY AND id > $B340_Q1"); B340_Q2="${B340_Q2:-0}"
b340_post create -d contact=$B340_CONTACT > /dev/null
B340_Q3=$(sq "SELECT MAX(id) FROM ka_quotes WHERE contact_id = $B340_CONTACT AND id > $B340_Q2"); B340_Q3="${B340_Q3:-0}"
expect "3.40 B: a new quote takes the contact's own list, else its company's (a contact without one: the company's; with one: its own)" \
  "$(sq "SELECT GROUP_CONCAT(price_list_id ORDER BY id) FROM ka_quotes WHERE id IN ($B340_Q1, $B340_Q2, $B340_Q3)")" "$B340_PARTNER,$B340_PARTNER,$B340_DEFAULT"
expect "3.40 B: an archived or missing list is never taken; a stranger's list id is refused" \
  "$(b340_php 'echo json_encode([Kaleta\Core\Quotes::customerList($db, null, null), Kaleta\Core\Quotes::customerList($db, 999999, null)]);')|$(b340_post customer_list -d kind=company -d id=$B340_COMPANY -d price_list_id=999999 > /dev/null; sq "SELECT price_list_id FROM ka_companies WHERE id = $B340_COMPANY")" \
  "[null,null]|$B340_PARTNER"

# the editor: an own row priced by the partner list; Save as a set; the set inserted into a quote of the default list (priced again)
B340_LOC=$(b340_post save -d id=$B340_Q1 "${B340_WHO[@]}" -d add_own=S340 -d add_own_quantity=3 --data-urlencode "add_free_name=Doprava 340" -d add_free_price=250 -d add_free_quantity=1 -d next=add)
expect "3.40 B: the partner list prices the own row of the customer's quote" "$(sq "SELECT GROUP_CONCAT(CONCAT(name, ':', unit_price) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $B340_Q1")" "Servis 340:80.00 Doprava 340:250.00"
check "3.40 B: the editor offers Save as a set" 200 "/admin.php?module=quotes&action=edit&id=$B340_Q1" 'data-quote-save-set'
B340_UPD=$(grep -o 'name="updated_at" value="[^"]*"' "$WORK/response" | sed 's/.*value="//;s/"//')
B340_KEEP=(); for b340_l in $(sq "SELECT GROUP_CONCAT(id ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $B340_Q1"); do B340_KEEP+=(-d "line_id[]=$b340_l" -d "line_item[]=" -d "line_variant[]="
  -d "line_code[]=$(sq "SELECT code FROM ka_quote_lines WHERE id = $b340_l")" --data-urlencode "line_name[]=$(sq "SELECT name FROM ka_quote_lines WHERE id = $b340_l")" -d "line_description[]=" -d "line_quantity[]=$(sq "SELECT quantity FROM ka_quote_lines WHERE id = $b340_l")"
  -d "line_unit[]=$(sq "SELECT unit FROM ka_quote_lines WHERE id = $b340_l")" -d "line_price[]=$(sq "SELECT unit_price FROM ka_quote_lines WHERE id = $b340_l")" -d "line_discount[]=0" -d "line_vat[]=21.00"); done
B340_LOC=$(b340_post save -d id=$B340_Q1 "${B340_WHO[@]}" -d "updated_at=$B340_UPD" "${B340_KEEP[@]}" --data-urlencode "set_name=Servis s dopravou 340" -d next=save_set)
B340_SET=$(sq "SELECT id FROM ka_quote_line_sets WHERE name = 'Servis s dopravou 340'"); B340_SET="${B340_SET:-0}"
expect "3.40 B: Save as a set keeps the own row by its code (no price) and the free line with its price" \
  "${B340_LOC##*\?}|$(sq "SELECT content FROM ka_quote_line_sets WHERE id = $B340_SET" | php -r '$l = json_decode(stream_get_contents(STDIN), true); echo implode(" ", array_map(fn ($x) => $x["kind"] . ":" . ($x["code"] ?? "") . ":" . ($x["unit_price"] ?? "-"), $l));')" \
  "module=quotes&action=edit&id=$B340_Q1#line-sets|own:S340:- free::250.00"
B340_LOC=$(b340_post save -d id=$B340_Q3 "${B340_WHO[@]}" -d line_set=$B340_SET -d next=line_set)
expect "3.40 B: inserting the set into a quote of the default list prices the own row from that list now (100, not 80); the free line keeps its price" \
  "${B340_LOC##*\?}|$(sq "SELECT GROUP_CONCAT(CONCAT(name, ':', quantity, ':', unit_price) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $B340_Q3")" \
  "module=quotes&action=edit&id=$B340_Q3#lines|Servis 340:3.000:100.00 Doprava 340:1.000:250.00"
check "3.40 B: the Settings tab lists the saved line sets" 200 "/admin.php?module=quotes&action=settings" "data-line-set=\"$B340_SET\""
b340_post line_set_rename -d id=$B340_SET --data-urlencode "name=Servis a doprava 340" > /dev/null
expect "3.40 B: an administrator renames a set; the change log names it, never a price" \
  "$(sq "SELECT name FROM ka_quote_line_sets WHERE id = $B340_SET")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'quotes' AND akce = 'line_set' AND popis LIKE '%340%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'quotes' AND popis LIKE '%250%'")" \
  "Servis a doprava 340|2|0"

# MCP: list_quotes lists the sets, save_quote inserts one by name (priced from the quote's list), get_quote shows the customer's answer later
expect "3.40 B MCP: list_quotes with line_sets lists the saved sets" \
  "$(mcp_call "$API_TOKEN" list_quotes '{"line_sets":true,"limit":1}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $j = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); echo implode(",", array_map(fn ($s) => $s["name"] . ":" . $s["lines"], $j["line_sets"] ?? []));')" \
  "Servis a doprava 340:2"
B340_MCP=$(mcp_call "$API_TOKEN" save_quote "{\"contact\":$B340_CONTACT,\"title\":\"MCP 340\",\"line_set\":\"Servis a doprava 340\"}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $j = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); echo ($j["id"] ?? 0), "|", implode(" ", array_map(fn ($l) => $l["name"] . ":" . $l["unit_price"], $j["quote"]["lines"] ?? []));')
expect "3.40 B MCP: save_quote with line_set makes a draft with the set's lines, priced from the contact's list" "${B340_MCP#*|}" "Servis 340:100.00 Doprava 340:250.00"
expect "3.40 B MCP: an unknown line set is refused with the names of the sets and nothing is saved" \
  "$(mcp_call "$API_TOKEN" save_quote "{\"contact\":$B340_CONTACT,\"title\":\"MCP 340 bad\",\"line_set\":\"Nic\"}" | grep -c 'Servis a doprava 340')|$(sq "SELECT COUNT(*) FROM ka_quotes WHERE title = 'MCP 340 bad'")" "1|0"

# the customer's page: Accept / Decline only with the setting, first opened only with the other setting
B340_SENT=$(b340_php '$q = Kaleta\Core\Quotes::finalise($app, (int) $a[0], (int) $a[1]); echo $q["number"], " ", Kaleta\Core\QuoteDelivery::issueLink($app, $q["id"], 0);' "$B340_Q1" "$B340_ADMIN")
B340_NUMBER="${B340_SENT%% *}"; B340_LINK="${B340_SENT##* }"; B340_TOKEN="${B340_LINK##*/}"
B340_PAGE=$(curl -s "$B/quote/$B340_TOKEN")
expect "3.40 B: both are off by default – no buttons, nothing recorded" "$(grep -c 'data-quote-decision' <<< "$B340_PAGE")|$(sq "SELECT IFNULL(first_opened_at, '-') FROM ka_quotes WHERE id = $B340_Q1")" "0|-"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=settings"
grep -q 'data-quotes-decision' "$WORK/response" && grep -q 'data-quotes-opened' "$WORK/response" && ! grep -q 'data-quotes-opened checked\|name="quote_track_opened" value="1" checked' "$WORK/response" \
  && echo "  ok     3.40 B: Quotes → Settings offers both, unticked by default" || { echo "  CHYBA  3.40 B: the settings of the customer's page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=settings_save" -d "_csrf=$(csrf)" -d quote_number_format='{YYYY}-{NNNN}' -d quote_valid_days=30 -d quote_vat_payer=1 -d quote_vat_rate=21 \
  -d quote_vat_rates=21 -d quote_max_discount=20 -d quote_link_days=30 -d quotes_months=36 -d quote_set_deal_value=1 -d quote_customer_decision=1 -d quote_track_opened=1
curl -s -b "$JAR" -o /dev/null "$B/quote/$B340_TOKEN" # a signed-in person opening the link is not the customer
B340_ADMINVIEW=$(sq "SELECT IFNULL(first_opened_at, '-') FROM ka_quotes WHERE id = $B340_Q1")
curl -s -D "$WORK/b340-page-headers" -o "$WORK/b340-page" "$B/quote/$B340_TOKEN"
B340_OPENED=$(sq "SELECT IFNULL(first_opened_at, '-') FROM ka_quotes WHERE id = $B340_Q1")
expect "3.40 B: with the settings the page shows Accept / Decline (posting to itself, allowed by its CSP) and the first view stores the time only" \
  "$B340_ADMINVIEW|$(grep -c 'data-quote-decision="open"' "$WORK/b340-page")|$(grep -o "action=\"[^\"]*/quote/$B340_TOKEN\"" "$WORK/b340-page" | wc -l | tr -d ' ')|$(grep -ci "form-action 'self'" "$WORK/b340-page-headers")|$([ "$B340_OPENED" != '-' ] && echo time)" \
  "-|1|2|1|time"
curl -s -o /dev/null "$B/quote/$B340_TOKEN"
expect "3.40 B: only the first opening is kept" "$(sq "SELECT IFNULL(first_opened_at, '-') FROM ka_quotes WHERE id = $B340_Q1")" "$B340_OPENED"
check "3.40 B: the detail shows when it was first opened" 200 "/admin.php?module=quotes&action=detail&id=$B340_Q1" 'data-quote-opened'
background_jobs hold
B340_POST=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/quote/$B340_TOKEN" -d decision=decline --data-urlencode "reason=Příliš drahé <b>teď</b>")
B340_AGAIN=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/quote/$B340_TOKEN" -d decision=accept)
expect "3.40 B: the customer declines with a reason (303 back with a code); one decision – the second post changes nothing" \
  "${B340_POST%% *} ${B340_POST##*\?}|${B340_AGAIN##*\?}|$(sq "SELECT CONCAT_WS('|', status, decline_reason, IFNULL(decided_by, '-'), customer_reason) FROM ka_quotes WHERE id = $B340_Q1")" \
  "303 decision=done|decision=closed|declined|other|-|Příliš drahé <b>teď</b>"
expect "3.40 B: the history says by the customer, the responsible person has a bell naming the quote only, the deal is not touched" \
  "$(b340_php '$q = Kaleta\Core\Quotes::get($db, (int) $a[0]); $h = end($q["history"]); echo $h["via"] ?? "-", "|", $q["decided_by_customer"] ? "customer" : "person";' "$B340_Q1")|$(sq "SELECT CONCAT_WS('|', kind, target, excerpt) FROM ka_notifications WHERE user_id = $B340_ADMIN AND target = 'quote:$B340_Q1'")" \
  "customer|customer|quote_declined|quote:$B340_Q1|"
check "3.40 B: the page thanks after the answer and offers no more buttons" 200 "/quote/$B340_TOKEN?decision=done" 'data-quote-decision="done"'
grep -q 'data-quote-decision="open"' "$WORK/response" && { echo "  CHYBA  3.40 B: the buttons after a decision"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.40 B: no buttons once decided"
check "3.40 B: the detail names the customer as the one who declined, with their words escaped" 200 "/admin.php?module=quotes&action=detail&id=$B340_Q1" 'data-quote-customer-reason'
grep -q 'Příliš drahé &lt;b&gt;teď&lt;/b&gt;' "$WORK/response" && grep -q 'data-history-customer' "$WORK/response" \
  && echo "  ok     3.40 B: the reason is escaped, the history line marked as the customer's" || { echo "  CHYBA  3.40 B: the customer's reason or history line"; ERRORS=$((ERRORS+1)); }
# a person set it back to sent: the customer may not decide again (one decision); an expired validity shows no buttons either
b340_post status -d id=$B340_Q1 -d status=sent > /dev/null
expect "3.40 B: after a person set it back to sent the page offers no buttons and a post changes nothing" \
  "$(curl -s "$B/quote/$B340_TOKEN" | grep -c 'data-quote-decision="open"')|$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/quote/$B340_TOKEN" -d decision=accept | sed 's/.*?//')|$(sq "SELECT status FROM ka_quotes WHERE id = $B340_Q1")" \
  "0|decision=closed|sent"
B340_SENT2=$(b340_php '$q = Kaleta\Core\Quotes::finalise($app, (int) $a[0], (int) $a[1]); echo Kaleta\Core\QuoteDelivery::issueLink($app, $q["id"], 0);' "$B340_Q3" "$B340_ADMIN")
B340_TOKEN2="${B340_SENT2##*/}"
B340_ACCEPT=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/quote/$B340_TOKEN2" -d decision=accept | sed 's/.*?//')
expect "3.40 B: the customer accepts another quote; the bell for its responsible person" \
  "$B340_ACCEPT|$(sq "SELECT CONCAT_WS('|', status, IFNULL(customer_reason, '-')) FROM ka_quotes WHERE id = $B340_Q3")|$(sq "SELECT kind FROM ka_notifications WHERE user_id = $B340_ADMIN AND target = 'quote:$B340_Q3'")" \
  "decision=done|accepted|-|quote_accepted"
expect "3.40 B MCP: get_quote shows the customer's answer and the first opening" \
  "$(mcp_call "$API_TOKEN" get_quote "{\"id\":$B340_Q3}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $j = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); echo ($j["status"] ?? ""), "|", ($j["decided_by"] ?? "-");')" \
  "accepted|customer"
sq "UPDATE ka_quotes SET status = 'sent', decided_at = NULL, history = NULL, valid_until = '$(site_date '-2 days')' WHERE id = $B340_Q3" > /dev/null
expect "3.40 B: a quote past its validity offers no buttons" "$(curl -s "$B/quote/$B340_TOKEN2" | grep -c 'data-quote-decision="open"')" "0"
sq "UPDATE ka_quotes SET valid_until = NULL WHERE id = $B340_Q3" > /dev/null
B340_WRONG=$(php -r 'echo bin2hex(random_bytes(32));')
expect "3.40 B: a post with a wrong token is the plain 404" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/quote/$B340_WRONG" -d decision=accept)" "404"
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'quote_customer_decision'" > /dev/null
expect "3.40 B: with the setting off again a post decides nothing" \
  "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/quote/$B340_TOKEN2" -d decision=accept | sed 's/.*?//')|$(sq "SELECT status FROM ka_quotes WHERE id = $B340_Q3")" "decision=closed|sent"
background_jobs resume

# the follow-up task: "Remind me in N days" on the detail and in the send dialog – a task about the quote for its responsible person
B340_LOC=$(b340_post follow_up -d id=$B340_Q1 -d days=3)
expect "3.40 B: Remind me makes a task about the quote, due in 3 days, for the responsible person; the title names the number" \
  "${B340_LOC##*\?}|$(sq "SELECT CONCAT_WS('|', assignee_id, DATE(due_at), title LIKE '%$B340_NUMBER') FROM ka_tasks WHERE target = 'quote:$B340_Q1' ORDER BY id DESC LIMIT 1")" \
  "module=quotes&action=detail&id=$B340_Q1#tasks|$B340_ADMIN|$(site_date '+3 days')|1"
check "3.40 B: the detail lists the quote's tasks" 200 "/admin.php?module=quotes&action=detail&id=$B340_Q1" 'data-tasks="quote:'
b340_post save -d id=$B340_Q2 "${B340_WHO[@]}" -d add_own=S340 -d add_own_quantity=1 -d next=add > /dev/null
check "3.40 B: the send dialog offers Remind me" 200 "/admin.php?module=quotes&action=send&id=$B340_Q2" 'data-quote-send-follow-up'
B340_CSRF=$(csrf)
b340_post send -d id=$B340_Q2 -d mode=finalise -d follow_up=1 -d follow_up_days=5 > /dev/null
expect "3.40 B: finalising from the send dialog with the box ticked adds the follow-up" \
  "$(sq "SELECT status FROM ka_quotes WHERE id = $B340_Q2")|$(sq "SELECT DATE(due_at) FROM ka_tasks WHERE target = 'quote:$B340_Q2'")" "sent|$(site_date '+5 days')"
expect "3.40 B MCP: save_task takes a quote as its target; the target kind sweeps away with the quote" \
  "$(mcp_call "$API_TOKEN" save_task "{\"title\":\"Call about 340\",\"target\":\"quote:$B340_Q2\"}" | grep -c '"id"')|$(b340_php 'echo json_encode([Kaleta\Core\Tasks::cleanTarget("quote:7"), Kaleta\Core\Inbox::parse("quote:7"), Kaleta\Core\Assignment::KINDS["quote"] ?? null]);')" \
  '1|"quote:7",["quote",7],"quotes"]'

# switching "first opened on" off forgets the recorded times; anonymising blanks the customer's words
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=settings"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=settings_save" -d "_csrf=$(csrf)" -d quote_number_format='{YYYY}-{NNNN}' -d quote_valid_days=30 -d quote_vat_payer=1 -d quote_vat_rate=21 \
  -d quote_vat_rates=21 -d quote_max_discount=20 -d quote_link_days=30 -d quotes_months=36 -d quote_set_deal_value=1
expect "3.40 B: switching first opened off forgets every recorded time" "$(sq "SELECT COUNT(*) FROM ka_quotes WHERE first_opened_at IS NOT NULL")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quote_track_opened'")" "0|0"
sq "UPDATE ka_quotes SET customer_reason = 'Moje slova' WHERE id = $B340_Q1" > /dev/null
b340_php 'Kaleta\Core\QuoteDelivery::anonymise($db, [(int) $a[0]]);' "$B340_Q1" > /dev/null
expect "3.40 B: anonymising a quote blanks the customer's words" "$(sq "SELECT IFNULL(customer_reason, '-') FROM ka_quotes WHERE id = $B340_Q1")" "-"
b340_post line_set_delete -d id=$B340_SET > /dev/null
expect "3.40 B: an administrator deletes a set" "$(sq "SELECT COUNT(*) FROM ka_quote_line_sets WHERE id = $B340_SET")" "0"

# --- clean up
b340_php 'Kaleta\Core\QuoteDelivery::delete($db, array_map("intval", array_column($db->all("SELECT id FROM {quotes} WHERE contact_id = ? OR company_id = ?", [(int) $a[0], (int) $a[1]]), "id")));' "$B340_CONTACT" "$B340_COMPANY" > /dev/null 2>&1 || true
sq "DELETE FROM ka_tasks WHERE target LIKE 'quote:%'; DELETE FROM ka_notifications WHERE target LIKE 'quote:%'; DELETE FROM ka_prices WHERE code = 'S340'; DELETE FROM ka_price_lists WHERE id = $B340_PARTNER;
  DELETE FROM ka_contacts WHERE id = $B340_CONTACT; DELETE FROM ka_companies WHERE id = $B340_COMPANY; DELETE FROM ka_quote_counters; DELETE FROM ka_quote_line_sets;
  DELETE FROM ka_nastaveni WHERE promenna IN ('quote_customer_decision', 'quote_track_opened', 'quote_number_format', 'quote_valid_days', 'quote_vat_payer', 'quote_vat_rates', 'quote_max_discount', 'quote_link_days', 'quotes_months', 'quote_set_deal_value', 'quote_next_number');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$B340_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
