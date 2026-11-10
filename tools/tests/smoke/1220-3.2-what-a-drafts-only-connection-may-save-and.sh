echo "== 3.2: what a drafts-only connection may save, and Waiting for you (Core\\PendingReview)"
# collection items: a new one is always hidden, a hidden one may change, a visible one may not
mcp_as "$DRAFT_TOKEN" save_collection_item '{"collection":"tym","name":"Navrh Clena","values":{"funkce":"Stolar"},"visible":true}' > "$WORK/response"; mcp_text
DRAFT_ITEM=$(mcp_value id); DRAFT_ITEM="${DRAFT_ITEM:-0}"
expect "3.2: a drafts-only connection creates a collection item – hidden, whatever visible says, and says so" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM")|$(mcp_value visible)|$(grep -c 'Saved hidden' "$WORK/text")" "0||1"
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"tym\",\"id\":$DRAFT_ITEM,\"values\":{\"funkce\":\"Mistr stolar\"}}" > /dev/null
expect "3.2: a drafts-only connection changes a hidden item" "$(sq "SELECT CONCAT(zobrazit, '|', data LIKE '%Mistr stolar%') FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM")" "0|1"
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"tym\",\"id\":$DRAFT_ITEM,\"visible\":true}" > "$WORK/response"
contains -q 'cannot make an item visible' "$WORK/response" && expect "3.2: a drafts-only connection cannot make an item visible" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM")" "0" || { echo "  CHYBA  drafts: visible item"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_collection_item '{"collection":"tym","name":"Planovany Navrh","publish_at":"2099-01-01 08:00"}' > "$WORK/response"
contains -q 'cannot schedule an item' "$WORK/response" && expect "3.2: a drafts-only connection cannot schedule an item" "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Planovany Navrh'")" "0" || { echo "  CHYBA  drafts: scheduled item"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
LIVE_ITEM=$(sq "SELECT p.idp FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = 'tym' AND p.zobrazit = 1 AND p.smazano IS NULL ORDER BY p.idp LIMIT 1"); LIVE_ITEM="${LIVE_ITEM:-0}"
LIVE_BEFORE=$(sq "SELECT SHA2(CONCAT(nazev, data), 256) FROM ka_kolekce_polozky WHERE idp = $LIVE_ITEM")
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"tym\",\"id\":$LIVE_ITEM,\"name\":\"Prepsano Claudem\"}" > "$WORK/response"
contains -q 'propose' "$WORK/response" && expect "3.2: a drafts-only connection cannot change a visible item – it is told to propose the change" "$(sq "SELECT SHA2(CONCAT(nazev, data), 256) FROM ka_kolekce_polozky WHERE idp = $LIVE_ITEM")" "$LIVE_BEFORE" || { echo "  CHYBA  drafts: visible item changed"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# enquiries: only the triage, as a suggestion
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$(site_time)', 'Navrh trideni', 'navrh@example.com', '[]')" > /dev/null
DRAFT_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Navrh trideni'")
mcp_as "$DRAFT_TOKEN" update_enquiry "{\"id\":$DRAFT_ENQ,\"status\":\"resolved\",\"category\":\"sales\"}" > "$WORK/response"
contains -q 'triage' "$WORK/response" && expect "3.2: a drafts-only connection cannot set the status of an enquiry (nothing saved)" "$(sq "SELECT CONCAT(stav, '|', kategorie) FROM ka_poptavky WHERE idp = $DRAFT_ENQ")" "0|" || { echo "  CHYBA  drafts: enquiry status"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" update_enquiry "{\"id\":$DRAFT_ENQ,\"category\":\"sales\",\"priority\":\"high\",\"draft_reply\":\"Dobrý den, ozveme se.\"}" > /dev/null
expect "3.2: a drafts-only connection saves the triage of an enquiry" "$(sq "SELECT CONCAT(stav, '|', kategorie, '|', priorita, '|', triaged_by) FROM ka_poptavky WHERE idp = $DRAFT_ENQ")" "0|sales|3|claude"
sq "DELETE FROM ka_poptavky WHERE idp = $DRAFT_ENQ" > /dev/null
# the notebook
mcp_as "$DRAFT_TOKEN" write_notebook '{"topic":"history","title":"Zprava z behu","text":"Navstevy rostou."}' > /dev/null
expect "3.2: a drafts-only connection writes a notebook note" "$(sq "SELECT COUNT(*) FROM ka_notebook WHERE title = 'Zprava z behu'")" "1"
# opening hours: a proposal the site ignores until a person applies it
TOMORROW=$(site_time "+1 day" "Y-m-d")
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Platna vyjimka\",\"notice_days\":0}" > /dev/null
APPLIED_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'Platna vyjimka'"); APPLIED_EXC="${APPLIED_EXC:-0}"
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"id\":$APPLIED_EXC,\"from\":\"$TOMORROW\",\"note\":\"Zmeneno Claudem\"}" > "$WORK/response"
contains -q 'can only propose' "$WORK/response" && expect "3.2: a drafts-only connection cannot change an exception in use" "$(sq "SELECT CONCAT(note, '|', proposed) FROM ka_hours_exceptions WHERE id = $APPLIED_EXC")" "Platna vyjimka|0" || { echo "  CHYBA  drafts: applied exception"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_hours_exceptions WHERE id = $APPLIED_EXC" > /dev/null
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Navrh Clauda\",\"notice_days\":7}" > "$WORK/response"; mcp_text
PROPOSED_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'Navrh Clauda'"); PROPOSED_EXC="${PROPOSED_EXC:-0}"
expect "3.2: a drafts-only connection saves a PROPOSED exception and is told a person applies it" "$(sq "SELECT proposed FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")|$(grep -c 'PROPOSAL' "$WORK/text")" "1|1"
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"id\":$PROPOSED_EXC,\"from\":\"$TOMORROW\",\"note\":\"Navrh Clauda\",\"hours\":\"9-12\",\"notice_days\":7}" > /dev/null
expect "3.2: a drafts-only connection changes its own proposal, which stays a proposal" "$(sq "SELECT CONCAT(proposed, '|', closed, '|', hours) FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")" "1|0|9-12"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
! grep -q 'ka-oznameni-hodiny' "$WORK/response" && ! grep -q 'Navrh Clauda' "$WORK/response" && echo "  ok     3.2: the site ignores a proposed exception (no notice bar, no structured data)" || { echo "  CHYBA  a proposal shows on the site"; ERRORS=$((ERRORS+1)); }
mcp list_hours '{}' > "$WORK/response"
expect "3.2: list_hours keeps proposals apart from the exceptions in use" "$(mcp_value exceptions)|$(mcp_value proposed 0 note)" "[]|Navrh Clauda"
check "3.2: a proposal has no door sign" 404 "/admin.php?module=settings&action=hours_sign&exception=$PROPOSED_EXC"
# Waiting for you: the dashboard and list_pending_review list the hidden item and the proposal
mcp_as "$DRAFT_TOKEN" list_pending_review '{}' > "$WORK/response"; mcp_text
contains -q '"kind":"hidden_items"' "$WORK/text" && contains -q 'Navrh Clena (' "$WORK/text" && contains -q '"kind":"proposed_hours"' "$WORK/text" && contains -q '"admin_url":"http' "$WORK/text" \
  && echo "  ok     3.2: list_pending_review (also over a drafts-only connection) lists the hidden item and the proposal with admin links" || { echo "  CHYBA  list_pending_review"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "3.2: the dashboard shows Waiting for you above the counters" 200 "/admin.php" 'class="ceka-na-vas"'
grep -q 'data-kind="proposed_hours"' "$WORK/response" && grep -q 'data-kind="hidden_items"' "$WORK/response" && [ "$(grep -o 'class="ceka-na-vas"\|class="dlazdice"' "$WORK/response" | head -1)" = 'class="ceka-na-vas"' ] \
  && echo "  ok     3.2: Waiting for you has a row for the proposal and the hidden item" || { echo "  CHYBA  Waiting for you rows"; ERRORS=$((ERRORS+1)); }
# a person applies the proposal in the admin – then the site uses it; another one is discarded
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
grep -q 'id="proposed-hours"' "$WORK/response" && grep -q "action=hours_apply" "$WORK/response" && grep -q "action=hours_discard" "$WORK/response" \
  && echo "  ok     3.2: Business details show the proposal with Apply and Discard" || { echo "  CHYBA  proposal in the admin"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_apply" -d "_csrf=$(csrf)" -d "exception=$PROPOSED_EXC"
expect "3.2: a person applies the proposal" "$(sq "SELECT proposed FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")" "0"
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-oznameni-hodiny' "$WORK/response" && grep -q 'Navrh Clauda' "$WORK/response" && echo "  ok     3.2: the applied exception shows on the site" || { echo "  CHYBA  applied exception not on the site"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Druhy navrh\"}" > /dev/null
SECOND_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'Druhy navrh'"); SECOND_EXC="${SECOND_EXC:-0}"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_discard" -d "_csrf=$(csrf)" -d "exception=$PROPOSED_EXC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_discard" -d "_csrf=$(csrf)" -d "exception=$SECOND_EXC"
expect "3.2: Discard removes a proposal, never an exception in use" "$(sq "SELECT COUNT(*) FROM ka_hours_exceptions WHERE id = $SECOND_EXC")|$(sq "SELECT COUNT(*) FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC")" "0|1"
sq "DELETE FROM ka_hours_exceptions WHERE id = $PROPOSED_EXC; DELETE FROM ka_kolekce_polozky WHERE idp = $DRAFT_ITEM" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.2: tools/list of a drafts-only connection offers the four tools and list_pending_review, not save_fact" "$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -o '"name":"\(save_collection_item\|save_hours_exception\|update_enquiry\|write_notebook\|list_pending_review\|save_fact\)"' | sort | tr -d '\n')" \
  '"name":"list_pending_review""name":"save_collection_item""name":"save_hours_exception""name":"update_enquiry""name":"write_notebook"'
