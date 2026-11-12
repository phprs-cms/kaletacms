echo "== 3.38 B: the quote screens (list, editor, detail, statuses, revisions), the boxes of the records, the deal value and the MCP quote tools"
# the products, prices and the price list of 2800 stay: Laser 338 (L338, L338-M priced, L338-Z without a price), Nádrž 338 (T338), own row MONT338
Q350_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$Q350_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$Q350_EXT"),quotes'), ('quote_vat_rate', '21')" > /dev/null
Q350_YEAR=$(site_time now Y)
sq "DELETE FROM ka_quote_counters" > /dev/null
Q350_NOW=$(site_time)
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('kupec350@example.cz', 'kupec350@example.cz', 'Kamil Kupec', 'manual', '$Q350_NOW', '$Q350_NOW')" > /dev/null
Q350_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'kupec350@example.cz'"); Q350_CONTACT="${Q350_CONTACT:-0}"
sq "INSERT INTO ka_poptavky (datum, formular, tema, email, contact_id, stav, data) VALUES ('$Q350_NOW', 'Poptávka 350', 'Stroje 350', 'kupec350@example.cz', $Q350_CONTACT, 0, '[[\"Košík\",\"2 × Laser 338 – Modrý (L338-M)\\\\n1 × Nádrž 338 (T338)\\\\n1 × Záhadný stroj\"]]')" > /dev/null
Q350_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Poptávka 350'"); Q350_ENQ="${Q350_ENQ:-0}"
sq "INSERT INTO ka_deals (title, stage, contact_id, source_enquiry_id, created_at, updated_at) VALUES ('Obchod 350', 'new', $Q350_CONTACT, $Q350_ENQ, '$Q350_NOW', '$Q350_NOW')" > /dev/null
Q350_DEAL=$(sq "SELECT id FROM ka_deals WHERE title = 'Obchod 350'"); Q350_DEAL="${Q350_DEAL:-0}"
q350_location() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/q350-headers" -X POST "$B/admin.php?module=quotes&action=$1" -d "_csrf=$Q350_CSRF" "${@:2}"; { grep -i '^location:' "$WORK/q350-headers" || true; } | tr -d '\r' | sed 's/^[Ll]ocation: //'; }

# the boxes: the enquiry, the deal and the customer have "Quotes" with "New quote" for whoever may open Quotes
check "3.38 B: the enquiry has the Quotes box" 200 "/admin.php?module=enquiries&action=detail&id=$Q350_ENQ" 'data-quotes-box="enquiry"'
Q350_CSRF=$(csrf)
check "3.38 B: the deal has the Quotes box" 200 "/admin.php?module=pipeline&action=detail&id=$Q350_DEAL" 'data-quotes-box="deal"'
check "3.38 B: the customer has the Quotes box" 200 "/admin.php?module=contacts&action=detail&id=$Q350_CONTACT" 'data-quotes-box="contact"'
check "3.38 B: the empty list says how to start (and how to ask Claude)" 200 "/admin.php?module=quotes" 'data-quote-create'

# New quote from the enquiry: the customer, the open deal and the basket – a product by code with its list price, one without a price
Q350_LOC=$(q350_location create -d enquiry=$Q350_ENQ)
Q350_ID=$(sq "SELECT MAX(id) FROM ka_quotes WHERE enquiry_id = $Q350_ENQ"); Q350_ID="${Q350_ID:-0}"
expect "3.38 B: New quote on the enquiry makes a draft with its customer, deal and basket and opens the editor" \
  "${Q350_LOC##*\?}|$(sq "SELECT CONCAT(status, '|', contact_id, '|', deal_id, '|', customer_email) FROM ka_quotes WHERE id = $Q350_ID")|$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', IFNULL(unit_price, '-')) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $Q350_ID")" \
  "module=quotes&action=edit&id=$Q350_ID|draft|$Q350_CONTACT|$Q350_DEAL|kupec350@example.cz|L338-M:1.00 T338:4800.00 :-"
check "3.38 B: the editor shows the lines, a line without a price in red and the bar with Save and send" 200 "/admin.php?module=quotes&action=edit&id=$Q350_ID" 'data-quote-flag="unpriced"'
grep -q 'data-quote-send' "$WORK/response" && grep -q 'data-record-bar' "$WORK/response" && grep -q 'name="set_deal_value" value="1" checked' "$WORK/response" \
  && echo "  ok     3.38 B: the editor in the record template – one primary action, the deal-value box ticked by default" || { echo "  CHYBA  3.38 B: the editor bar or the deal-value box"; ERRORS=$((ERRORS+1)); }
Q350_UPDATED=$(grep -o 'name="updated_at" value="[^"]*"' "$WORK/response" | sed 's/.*value="//;s/"//')
read -r Q350_L1 Q350_L2 Q350_L3 <<< "$(sq "SELECT GROUP_CONCAT(id ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $Q350_ID")"
Q350_LINES=(-d "line_id[]=$Q350_L1" -d "line_item[]=$Q338_LASER" -d "line_variant[]=L338-M" -d "line_code[]=L338-M" --data-urlencode "line_name[]=Laser 338 – Modrý" -d "line_description[]=" -d "line_quantity[]=2" -d "line_unit[]=ks" -d "line_price[]=1000" -d "line_discount[]=0" -d "line_vat[]=21.00"
  -d "line_id[]=$Q350_L2" -d "line_item[]=$Q338_TANK" -d "line_variant[]=" -d "line_code[]=T338" --data-urlencode "line_name[]=Nádrž 338" -d "line_description[]=" -d "line_quantity[]=1" -d "line_unit[]=ks" -d "line_price[]=4800.00" -d "line_discount[]=10" -d "line_vat[]=12.00"
  -d "line_id[]=$Q350_L3" -d "line_item[]=" -d "line_variant[]=" -d "line_code[]=" --data-urlencode "line_name[]=Záhadný stroj" -d "line_description[]=" -d "line_quantity[]=1" -d "line_unit[]=" -d "line_price[]=" -d "line_discount[]=0" -d "line_vat[]=21.00" -d "line_remove[]=2")
Q350_FORM=(-d id=$Q350_ID -d "updated_at=$Q350_UPDATED" --data-urlencode "title=Nabídka 350" -d intro=Dobrý -d terms=Splatnost -d valid_until=2099-12-31 -d discount_percent=25 -d vat_mode=standard
  -d contact_id=$Q350_CONTACT -d company_id=0 -d deal_id=$Q350_DEAL -d owner_id=0 --data-urlencode "customer_name=Kamil Kupec" -d customer_company=Kupec350 -d customer_address= -d customer_reg_no= -d customer_vat_id= -d customer_email=kupec350@example.cz)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=quotes&action=totals" -d "_csrf=$Q350_CSRF" "${Q350_FORM[@]}" "${Q350_LINES[@]}"
expect "3.38 B: the editor's totals come from the server as typed, without saving" "$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo ($j["unpriced"] ?? "x"), "|", count($j["lines"] ?? []), "|", (int) $argv[2];' -- "$WORK/response" "$(sq "SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = $Q350_ID AND unit_price = 1000")")" "0|3|0"
# a person may give 25 % (above the 20 % Claude may save), a manual price, remove a line and add an own row
Q350_LOC=$(q350_location save "${Q350_FORM[@]}" "${Q350_LINES[@]}" -d add_own=MONT338 -d add_own_quantity=2 -d next=add)
expect "3.38 B: Save keeps the posted lines (a manual price marked), drops the removed one, adds the own row; a person may give 25 %" \
  "${Q350_LOC##*\?}|$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', unit_price, ':', IFNULL(list_price, '-'), ':', discount_percent) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $Q350_ID")|$(sq "SELECT CONCAT(discount_percent, '|', manual_prices, '|', customer_company, '|', total_net) FROM ka_quotes WHERE id = $Q350_ID")" \
  "module=quotes&action=edit&id=$Q350_ID#lines|L338-M:1000.00:1.00:0.00 T338:4800.00:4800.00:10.00 MONT338:1500.00:1500.00:0.00|25.00|1|Kupec350|6990.00"
Q350_LOC=$(q350_location save -d id=$Q350_ID -d discount_percent=0 -d "line_id[]=" -d "line_item[]=" -d "line_variant[]=" -d "line_code[]=" -d "line_name[]=Bad" -d "line_description[]=" -d "line_quantity[]=1" -d "line_unit[]=" -d "line_price[]=abc" -d "line_discount[]=0" -d "line_vat[]=21.00")
expect "3.38 B: a refused save shows the editor again with what was typed and changes nothing" "$Q350_LOC|$(grep -c 'value="abc"' "$WORK/response")|$(sq "SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = $Q350_ID")" "|1|3"
# Finalise without sending (with the deal-value box): the number, frozen, the deal takes the NET total (A-10)
read -r Q350_L1 Q350_L2 Q350_L3 <<< "$(sq "SELECT GROUP_CONCAT(id ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $Q350_ID")"
Q350_KEEP=(); for q350_l in $Q350_L1 $Q350_L2 $Q350_L3; do Q350_KEEP+=(-d "line_id[]=$q350_l" -d "line_item[]=$(sq "SELECT IFNULL(item_id, '') FROM ka_quote_lines WHERE id = $q350_l")" -d "line_variant[]=$(sq "SELECT variant_code FROM ka_quote_lines WHERE id = $q350_l")"
  -d "line_code[]=$(sq "SELECT code FROM ka_quote_lines WHERE id = $q350_l")" --data-urlencode "line_name[]=$(sq "SELECT name FROM ka_quote_lines WHERE id = $q350_l")" -d "line_description[]=" -d "line_quantity[]=$(sq "SELECT quantity FROM ka_quote_lines WHERE id = $q350_l")"
  -d "line_unit[]=" -d "line_price[]=$(sq "SELECT unit_price FROM ka_quote_lines WHERE id = $q350_l")" -d "line_discount[]=$(sq "SELECT discount_percent FROM ka_quote_lines WHERE id = $q350_l")" -d "line_vat[]=$(sq "SELECT vat_rate FROM ka_quote_lines WHERE id = $q350_l")"); done
Q350_LOC=$(q350_location save "${Q350_FORM[@]}" -d updated_at= "${Q350_KEEP[@]}" -d next=finalise -d set_deal_value=1)
expect "3.38 B: Finalise without sending gives the first number, freezes the quote and sets the deal's value to the net total" \
  "${Q350_LOC##*\?}|$(sq "SELECT CONCAT(number, '|', status, '|', total_net) FROM ka_quotes WHERE id = $Q350_ID")|$(sq "SELECT CONCAT(value, ' ', currency) FROM ka_deals WHERE id = $Q350_DEAL")" \
  "module=quotes&action=detail&id=$Q350_ID|$Q350_YEAR-0001|sent|6990.00|6990.00 CZK"
check "3.38 B: the detail shows the frozen document, the history and Mark accepted" 200 "/admin.php?module=quotes&action=detail&id=$Q350_ID" 'data-quote-accept'
grep -q 'data-quote-document' "$WORK/response" && grep -q 'data-history-action="finalised"' "$WORK/response" && grep -q 'data-quote-deal' "$WORK/response" \
  && echo "  ok     3.38 B: the detail in the record template – the document, the history, the deal in the side column" || { echo "  CHYBA  3.38 B: the detail"; ERRORS=$((ERRORS+1)); }
check "3.38 B: the list shows the quote with its number and total" 200 "/admin.php?module=quotes&status=sent&search=Kupec350" "$Q350_YEAR-0001"
check "3.38 B: the deal's box lists the quote" 200 "/admin.php?module=pipeline&action=detail&id=$Q350_DEAL" "data-quote=\"$Q350_ID\""
expect "3.38 B: an edit link of a sent quote opens its detail; a sent quote cannot be saved" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/admin.php?module=quotes&action=edit&id=$Q350_ID" | sed 's/.*?//')|$(q350_location save -d id=$Q350_ID -d title=X | sed 's/.*?//')" \
  "module=quotes&action=detail&id=$Q350_ID|module=quotes&action=detail&id=$Q350_ID"
# statuses: declined needs a reason; accepted ↔ sent; revise makes a draft /2 later; the draft is deleted
q350_location status -d id=$Q350_ID -d status=declined > /dev/null
Q350_A=$(sq "SELECT status FROM ka_quotes WHERE id = $Q350_ID")
q350_location status -d id=$Q350_ID -d status=declined -d reason=price > /dev/null
Q350_B=$(sq "SELECT CONCAT(status, ':', decline_reason) FROM ka_quotes WHERE id = $Q350_ID")
check "3.38 B: a declined quote offers to mark the deal lost with the same reason" 200 "/admin.php?module=quotes&action=detail&id=$Q350_ID" 'name="lost_reason" value="price"'
q350_location status -d id=$Q350_ID -d status=sent > /dev/null; q350_location status -d id=$Q350_ID -d status=accepted > /dev/null
expect "3.38 B: Mark declined needs a reason; back to sent and accepted; the deal never moves by itself" \
  "$Q350_A|$Q350_B|$(sq "SELECT status FROM ka_quotes WHERE id = $Q350_ID")|$(sq "SELECT stage FROM ka_deals WHERE id = $Q350_DEAL")" "sent|declined:price|accepted|new"
check "3.38 B: an accepted quote offers to move the deal to won" 200 "/admin.php?module=quotes&action=detail&id=$Q350_ID" 'data-quote-deal-won'
Q350_LOC=$(q350_location revise -d id=$Q350_ID)
Q350_REV=$(sq "SELECT MAX(id) FROM ka_quotes WHERE revision_of = $Q350_ID"); Q350_REV="${Q350_REV:-0}"
expect "3.38 B: Revise makes a new draft with the same lines and opens it" "${Q350_LOC##*\?}|$(sq "SELECT CONCAT(status, '|', IFNULL(number, '-'), '|', (SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = $Q350_REV))  FROM ka_quotes WHERE id = $Q350_REV")" \
  "module=quotes&action=edit&id=$Q350_REV|draft|-|3"
q350_location delete -d id=$Q350_REV > /dev/null
expect "3.38 B: the draft is deleted; the change log names quotes by number, never an amount" \
  "$(sq "SELECT COUNT(*) FROM ka_quotes WHERE id = $Q350_REV")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'quotes' AND (popis LIKE '%6990%' OR popis LIKE '%6 990%' OR popis LIKE '%4800%')")" "0|0"

# MCP: list_quotes, get_quote, save_quote (Claude: prices from the list only, the discount cap), mark_quote (the deal only on request)
mcp list_quotes "{\"enquiry\":$Q350_ENQ}" > "$WORK/response"; mcp_text
expect "3.38 B MCP: list_quotes filters by the enquiry and gives the number, status and totals" "$(php -r '$j = json_decode(file_get_contents($argv[1]), true); $q = $j["quotes"][0] ?? []; echo ($j["total"] ?? "x"), "|", $q["number"] ?? "", "|", $q["status"] ?? "", "|", $q["total_net"] ?? "";' -- "$WORK/text")" "1|$Q350_YEAR-0001|accepted|6990.00"
mcp get_quote "{\"id\":$Q350_ID}" > "$WORK/response"; mcp_text
expect "3.38 B MCP: get_quote gives the lines, totals per rate and history" "$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo count($j["lines"] ?? []), "|", count($j["totals"]["rates"] ?? []), "|", implode(",", array_column($j["history"] ?? [], "action")), "|", isset($j["lines"][0]["manual"]) ? "manual" : "";' -- "$WORK/text")" "3|2|created,finalised,status,status,status|manual"
mcp save_quote "{\"enquiry\":$Q350_ENQ,\"discount_percent\":\"25\"}" > "$WORK/response"; mcp_text
expect "3.38 B MCP: Claude cannot save a discount above the setting – nothing is saved" "$(grep -c 'ask a person to set it in the admin' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_quotes WHERE enquiry_id = $Q350_ENQ")" "1|1"
mcp save_collection_item '{"collection":"stroje-338","name":"Bez ceny 350","slug":"bez-ceny-350","values":{"code":"N350"},"visible":true}' > /dev/null # a product with no internal price at all
mcp save_quote "{\"contact\":$Q350_CONTACT,\"lines\":[{\"code\":\"L338-M\",\"quantity\":2,\"unit_price\":\"900\"},{\"code\":\"N350\",\"unit_price\":\"5\"},{\"code\":\"MONT338\"}],\"discount_percent\":\"10\"}" > "$WORK/response"; mcp_text
Q350_MCP=$(php -r 'echo (int) (json_decode(file_get_contents($argv[1]), true)["id"] ?? 0);' -- "$WORK/text")
expect "3.38 B MCP: save_quote makes a draft – a different price kept with a warning, a product without a list price gets no price (never guessed)" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', IFNULL(unit_price, '-')) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $Q350_MCP")|$(sq "SELECT CONCAT(status, ':', discount_percent) FROM ka_quotes WHERE id = $Q350_MCP")|$(grep -c 'manual price' "$WORK/text")|$(grep -c 'a person fills it in the admin' "$WORK/text")" \
  "L338-M:900.00 N350:- MONT338:1500.00|draft:10.00|1|1"
mcp mark_quote "{\"id\":$Q350_ID,\"status\":\"sent\"}" > /dev/null
mcp mark_quote "{\"id\":$Q350_ID,\"status\":\"declined\",\"decline_reason\":\"competitor\",\"update_deal\":true}" > "$WORK/response"; mcp_text
expect "3.38 B MCP: mark_quote declines with a reason and, asked to, marks the deal lost with it" "$(sq "SELECT CONCAT(status, ':', decline_reason) FROM ka_quotes WHERE id = $Q350_ID")|$(sq "SELECT CONCAT(stage, ':', IFNULL(lost_reason, '')) FROM ka_deals WHERE id = $Q350_DEAL")" "declined:competitor|lost:competitor"
Q350_DRAFTS="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($Q338_ADMIN, 'q350 drafts', 'drafts', 'all', SHA2('$Q350_DRAFTS', 256), '$(site_time)')" > /dev/null
q350_as() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
q350_as "$Q350_DRAFTS" save_quote "{\"contact\":$Q350_CONTACT,\"title\":\"Z konceptu 350\"}" > "$WORK/response"
q350_as "$Q350_DRAFTS" mark_quote "{\"id\":$Q350_ID,\"status\":\"sent\"}" > "$WORK/q350-mark"
expect "3.38 B MCP: a drafts-only connection saves a draft quote and cannot change a status" "$(sq "SELECT COUNT(*) FROM ka_quotes WHERE title = 'Z konceptu 350' AND status = 'draft'")|$(grep -c '"isError":true\|"error"' "$WORK/q350-mark")|$(sq "SELECT status FROM ka_quotes WHERE id = $Q350_ID")" "1|1|declined"

# a colleague without the section: no box, 403, no tool; the feature off: no box, no tool
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('nabidky-350', '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")', 'Bez Nabídek', 'nabidky350@example.cz', 1, 0)" > /dev/null
Q350_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'nabidky-350'"); Q350_USER="${Q350_USER:-0}"
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($Q350_USER, 'enquiries'), ($Q350_USER, 'pipeline'), ($Q350_USER, 'contacts')" > /dev/null
JAR_Q350="$WORK/jar-q350"; rm -f "$JAR_Q350"
Q350_HER_CSRF=$(curl -s -c "$JAR_Q350" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_Q350" -c "$JAR_Q350" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$Q350_HER_CSRF" -d user=nabidky-350 --data-urlencode "password=$PASSWORD"
q350_her() { curl -s -b "$JAR_Q350" -c "$JAR_Q350" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
# (the amount only when formatted: a random CSP nonce in the page may hold the digits 6990)
expect "3.38 B: without the section – 403 on the quotes, and the enquiry and the deal show no Quotes box and no amount" \
  "$(q350_her "module=quotes&action=detail&id=$Q350_ID")|$(q350_her "module=enquiries&action=detail&id=$Q350_ENQ"; printf ' '; grep -cE 'data-quotes-box|6990[.,]00|6[  ]990' "$WORK/response")|$(q350_her "module=pipeline&action=detail&id=$Q350_DEAL"; printf ' '; grep -c 'data-quotes-box' "$WORK/response")" \
  "403|200 0|200 0"
Q350_HER="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($Q350_USER, 'q350 colleague', 'full', 'all', SHA2('$Q350_HER', 256), '$(site_time)')" > /dev/null
expect "3.38 B MCP: a colleague without the section cannot read quotes" "$(q350_as "$Q350_HER" list_quotes '{}' | grep -c 'Only a user who may open Quotes')" "1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$Q350_EXT')" > /dev/null
expect "3.38 B: with the feature off the boxes and the tools are gone" \
  "$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=enquiries&action=detail&id=$Q350_ENQ" | grep -c 'data-quotes-box')|$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -c '"name":"list_quotes"')" "0|0"

# clean up
sq "DELETE FROM ka_quote_lines WHERE quote_id IN (SELECT id FROM ka_quotes WHERE contact_id = $Q350_CONTACT OR enquiry_id = $Q350_ENQ); DELETE FROM ka_quotes WHERE contact_id = $Q350_CONTACT OR enquiry_id = $Q350_ENQ;
  DELETE FROM ka_quote_counters; DELETE FROM ka_deal_stage_history WHERE deal_id = $Q350_DEAL; DELETE FROM ka_deals WHERE id = $Q350_DEAL; DELETE FROM ka_poptavky WHERE idp = $Q350_ENQ;
  DELETE FROM ka_contacts WHERE id = $Q350_CONTACT; DELETE FROM ka_kolekce_polozky WHERE seo_link = 'bez-ceny-350'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $Q350_USER; DELETE FROM ka_uzivatele WHERE idu = $Q350_USER;
  DELETE FROM ka_api_tokeny WHERE nazev IN ('q350 drafts', 'q350 colleague'); DELETE FROM ka_nastaveni WHERE promenna = 'quote_vat_rate'; DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
