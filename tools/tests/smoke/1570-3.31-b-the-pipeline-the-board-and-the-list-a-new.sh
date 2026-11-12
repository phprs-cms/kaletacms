echo "== 3.31 (B): the Pipeline – the board and the list, a new deal, a deal from an enquiry and its badge, moves without JavaScript (lost with a reason), the detail, comments and tasks, the stages, permissions"
B331_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES
  ('b331-jana', '$B331_HASH', 'Jana B331', 'jana-b331@example.com', '', 0, '$(site_time)', '$(site_time)', 0),
  ('b331-petr', '$B331_HASH', 'Petr B331', 'petr-b331@example.com', '', 1, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts' UNION SELECT 'pipeline') m WHERE user = 'b331-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts') m WHERE user = 'b331-petr'" > /dev/null
B331_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b331-jana'"); B331_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b331-petr'"); B331_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
b331_login() { local jar="$WORK/jar-$1"; rm -f "$jar"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; echo "$jar"; }
b331_post() { # b331_post <jar> <screen query> <module&action query> <fields…>: opens the screen (for the form token), posts, prints the code and the redirect
  local jar="$1" screen="$2" target="$3"; shift 3
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?$screen"
  curl -s -b "$jar" -c "$jar" -o "$WORK/b331-post" -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?$target" -d "_csrf=$(csrf)" "$@"; }
b331_deal() { sq "SELECT CONCAT_WS('/', title, stage, IFNULL(value, '-'), IFNULL(currency, '-'), IFNULL(owner_id, '-'), IFNULL(contact_id, '-'), IFNULL(company_id, '-'), IFNULL(source_enquiry_id, '-'), IF(closed_at IS NULL, 'open', 'closed'), IFNULL(lost_reason, '-'), IFNULL(lost_note, '-')) FROM ka_deals WHERE id = ${1:-0}"; }
b331_has() { grep -c -- "$1" "$WORK/response" || true; }
B331_CACHE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'page_cache'"); setting page_cache 1
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null "$B/"
B331_CO=$(sq "INSERT INTO ka_companies (name, domain, created_at, updated_at) VALUES ('Severka B331', 'severka-b331.cz', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
B331_EVA=$(sq "INSERT INTO ka_contacts (email, email_norm, name, phone, company_id, language, source, first_seen_at, last_activity_at, created_at, updated_at) VALUES ('eva@severka-b331.cz', 'eva@severka-b331.cz', 'Eva B331', '', $B331_CO, '', 'manual', '$(site_time)', '$(site_time)', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
# the menu and the empty board; a new deal from the board – the customer's company comes along, the change log has one row
check "3.31: the Pipeline is in the menu after Customers, the empty board offers New deal" 200 "/admin.php?module=pipeline" 'data-new-deal'
expect "3.31: … the menu item, the tabs (Stages for the administrator), the palette searches deals and offers New deal" \
  "$(b331_has 'href="/admin.php?module=pipeline"' | head -1 | sed 's/[2-9]/1/')|$(b331_has 'data-pipeline-stages')|$(b331_has 'data-deals="/admin.php?module=pipeline&amp;action=search_json"')|$(b331_has 'module=pipeline\\u0026action=new')" "1|1|1|1"
check "3.31: New deal for a customer – the customer and their company are filled in" 200 "/admin.php?module=pipeline&action=new&contact=$B331_EVA" "<option value=\"$B331_CO\" selected>"
REDIRECT=$(b331_post "$JAR" "module=pipeline&action=new&contact=$B331_EVA" "module=pipeline&action=save" -d id=0 --data-urlencode "title=Nový web B331" -d "contact_id=$B331_EVA" -d "company_id=$B331_CO" -d "owner_id=$B331_ADMIN" --data-urlencode "value=12 500,50" -d currency=CZK -d "expected_close=$(site_date '-2 days')" -d stage=new)
B331_D1=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_deals WHERE title = 'Nový web B331'")
expect "3.31: … saved (value 12 500,50 CZK), back to its detail; one change-log row 'create' by number only; the cached page stays" \
  "$(b331_deal "$B331_D1")|$(echo "$REDIRECT" | grep -c "action=detail&id=$B331_D1")|$(sq "SELECT GROUP_CONCAT(akce) FROM ka_protokol WHERE modul = 'pipeline' AND popis LIKE '#$B331_D1%'")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "Nový web B331/new/12500.50/CZK/$B331_ADMIN/$B331_EVA/$B331_CO/-/open/-/-|1|create|1"
B331_N=$(sq "SELECT COUNT(*) FROM ka_deals")
b331_post "$JAR" "module=pipeline&action=new" "module=pipeline&action=save" -d id=0 -d "title=" -d value=abc > /dev/null
b331_post "$JAR" "module=pipeline&action=new" "module=pipeline&action=save" -d id=0 -d "title=Lost stage B331" -d stage=lost > /dev/null
expect "3.31: … a deal without a title, with a value that is no number or starting in a lost stage is refused" "$(sq "SELECT COUNT(*) FROM ka_deals")" "$B331_N"
# a deal from an enquiry: its topic, the contact linked now, the person handling it responsible (notified); one open deal per enquiry
B331_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, tema, email, data, stav, prirazeno) VALUES ('$(site_time)', 'Poptávka B331', 'Servis kotle B331', 'novak-b331@example.com', '[]', 1, $B331_JANA); SELECT LAST_INSERT_ID();")
check "3.31: an enquiry offers Create deal" 200 "/admin.php?module=enquiries&action=detail&id=$B331_ENQ" 'data-deal-create'
REDIRECT=$(b331_post "$JAR" "module=enquiries&action=detail&id=$B331_ENQ" "module=pipeline&action=from_enquiry" -d "idp=$B331_ENQ")
B331_D2=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_deals WHERE source_enquiry_id = $B331_ENQ")
B331_NOVAK=$(sq "SELECT IFNULL(contact_id, 0) FROM ka_poptavky WHERE idp = $B331_ENQ")
expect "3.31: Create deal – (3.32) who and what as the title, the contact linked, Jana responsible and notified; back to the deal" \
  "$(b331_deal "$B331_D2")|$(echo "$REDIRECT" | grep -c "action=detail&id=$B331_D2")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $B331_JANA AND target = 'deal:$B331_D2'")" \
  "novak-b331@example.com – Servis kotle B331/new/-/-/$B331_JANA/$B331_NOVAK/-/$B331_ENQ/open/-/-|1|1"
REDIRECT=$(b331_post "$JAR" "module=enquiries&action=detail&id=$B331_ENQ" "module=pipeline&action=from_enquiry" -d "idp=$B331_ENQ")
expect "3.31: … a second deal of the same enquiry is refused while the first is open (back to the enquiry)" "$(sq "SELECT COUNT(*) FROM ka_deals WHERE source_enquiry_id = $B331_ENQ")|$(echo "$REDIRECT" | grep -c "module=enquiries&action=detail&id=$B331_ENQ#deal")" "1|1"
check "3.31: … the enquiry shows its deal (Open the deal), no Create deal while it is open" 200 "/admin.php?module=enquiries&action=detail&id=$B331_ENQ" "data-deal-box=\"$B331_D2\""
expect "3.31: … no Create deal; the enquiry list shows the deal badge" "$(b331_has 'data-deal-create')|$(curl -s -b "$JAR" "$B/admin.php?module=enquiries" | grep -c "data-enquiry-deal=\"$B331_D2\"")" "0|1"
# the board: a column per stage, the cards with their move forms, counts and sums per currency; overdue marked
# 3.32 (U29): money reads "12 500,50 Kč" (non-breaking spaces) in Czech; the board carries the same rule for its live totals
B332_NB=$'\xc2\xa0'
check "3.31: the board – both deals in New with their move forms" 200 "/admin.php?module=pipeline" "id=\"deal-$B331_D1\""
expect "3.31: … five columns, two cards in New, the sum of New in CZK (and the card's value), the first card overdue, the live region; the search finds one" \
  "$(b331_has '<section class="obchody-sloupec')|$(sed -n '/data-stage="new"/,/<\/section>/p' "$WORK/response" | grep -c 'data-deal="')|$(b331_has "12${B332_NB}500,50${B332_NB}Kč")|$(b331_has 'obchod-karta obchod-po-terminu')|$(b331_has 'data-pipeline-announce')|$(curl -s -b "$JAR" "$B/admin.php?module=pipeline&search=kotle" | grep -c 'id="deal-')" \
  "5|2|2|1|1|1"
expect "3.32 (U29): … the board carries the money format of the admin language for its live totals" "$(b331_has 'data-money="{&quot;decimal&quot;:&quot;,&quot;')" "1"
# 3.32 (U29): the default currency – automatic from the site (Czech test site: CZK), a chosen one is what New deal starts with
check "3.32 (U29): Settings → General offers the currency, automatic by default" 200 "/admin.php?module=settings&tab=general" '<option value="">Automaticky (CZK)</option>'
setting default_currency GBP
check "3.32 (U29): … with GBP chosen, New deal starts in GBP" 200 "/admin.php?module=pipeline&action=new" '<option value="GBP" selected>GBP</option>'
setting default_currency ''
# moves without JavaScript: back to the board with its filter; a lost stage without a reason goes to the detail, with one it closes
REDIRECT=$(b331_post "$JAR" "module=pipeline" "module=pipeline&action=move" -d "id=$B331_D1" -d stage=qualified --data-urlencode "return=module=pipeline&owner=mine&evil=1")
expect "3.31: Move – the stage, a history row, one change-log row 'move'; back to the board with its filter and the card" \
  "$(b331_deal "$B331_D1" | cut -d/ -f2)|$(sq "SELECT COUNT(*) FROM ka_deal_stage_history WHERE deal_id = $B331_D1 AND from_stage = 'new' AND to_stage = 'qualified'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pipeline' AND popis LIKE '#$B331_D1 %' AND akce = 'move'")|$(echo "$REDIRECT" | sed 's/^302 //')" \
  "qualified|1|1|$B/admin.php?module=pipeline&owner=mine#deal-$B331_D1"
REDIRECT=$(b331_post "$JAR" "module=pipeline" "module=pipeline&action=move" -d "id=$B331_D1" -d stage=lost -d lost_reason= -d "return=module=pipeline")
expect "3.31: Move to Lost without a reason – nothing moves, the detail asks for it (Lost chosen)" "$(b331_deal "$B331_D1" | cut -d/ -f2)|$(echo "$REDIRECT" | grep -c "action=detail&id=$B331_D1&lose=lost#move")" "qualified|1"
check "3.31: … the detail has Lost chosen and the reason to fill in" 200 "/admin.php?module=pipeline&action=detail&id=$B331_D1&lose=lost" '<option value="lost" data-kind="lost" selected>'
b331_post "$JAR" "module=pipeline&action=detail&id=$B331_D1" "module=pipeline&action=move" -d "id=$B331_D1" -d stage=lost -d lost_reason=nonsense -d return=detail > /dev/null
b331_post "$JAR" "module=pipeline&action=detail&id=$B331_D1" "module=pipeline&action=move" -d "id=$B331_D1" -d stage=lost -d lost_reason=timing --data-urlencode "lost_note=Až na jaře" -d return=detail > /dev/null
expect "3.31: … an unknown reason is refused; with a reason and a note the deal is lost and closed" "$(b331_deal "$B331_D1")" "Nový web B331/lost/12500.50/CZK/$B331_ADMIN/$B331_EVA/$B331_CO/-/closed/timing/Až na jaře"
B331_JSON=$(b331_post "$JAR" "module=pipeline" "module=pipeline&action=move" -d "id=$B331_D1" -d stage=proposal -d format=json > /dev/null; php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo ($j["ok"] ? "ok" : "no") . "/" . $j["stage"] . "/" . ($j["closed"] ? "closed" : "open");' "$WORK/b331-post")
expect "3.31: drag and drop (format=json) reopens it in Proposal – the reason and the note are cleared" "$B331_JSON|$(b331_deal "$B331_D1" | cut -d/ -f9-11)" "ok/proposal/open|open/-/-"
# the detail: history, the thread and the tasks of the deal; the change log never carries the title
check "3.31: the detail – the history, the comments and tasks of deal:N, the move form" 200 "/admin.php?module=pipeline&action=detail&id=$B331_D1" "data-comments=\"deal:$B331_D1\""
expect "3.31: … tasks of deal:N, four moves in the history, Delete for the administrator" "$(b331_has "data-tasks=\"deal:$B331_D1\"")|$(b331_has 'data-history-to=')|$(b331_has 'data-deal-delete')" "1|4|1"
b331_post "$JAR" "module=pipeline&action=detail&id=$B331_D1" "module=pipeline&action=comment" -d "id=$B331_D1" -d op=add --data-urlencode "text=Poslat nabídku @b331-jana" > /dev/null
b331_post "$JAR" "module=pipeline&action=detail&id=$B331_D1" "module=pipeline&action=task" -d "id=$B331_D1" -d op=add --data-urlencode "title=Zavolat B331" -d due_quick=tomorrow > /dev/null
expect "3.31: a comment with a mention (Jana notified) and a task on the deal; the change log says \"deal\", never the title; the cached page stays" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'deal:$B331_D1'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $B331_JANA AND target = 'deal:$B331_D1' AND kind = 'mention'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'deal:$B331_D1'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pipeline' AND popis LIKE '%Nový web%'")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "1|1|1|0|1"
# the customer and the company: the Deals box with New deal, the deal on the timeline
check "3.31: the customer's page lists the deal with New deal" 200 "/admin.php?module=contacts&action=detail&id=$B331_EVA" 'data-deals-box'
expect "3.31: … the deal and its moves on the timeline with Open the deal; the company page lists it too" \
  "$(b331_has "data-deal=\"$B331_D1\"")|$(b331_has 'data-timeline-entry="deal"' | sed 's/[2-9]/1/')|$(b331_has 'module=pipeline&amp;action=new&amp;contact=')|$(curl -s -b "$JAR" "$B/admin.php?module=contacts&action=company&id=$B331_CO" | grep -c "data-deal=\"$B331_D1\"")" "1|1|2|1" # 3.37: New deal in the bar and in the box
# the list: the status and the filters, paging keeps them
check "3.31: the list – open deals by default" 200 "/admin.php?module=pipeline&view=list" 'data-deals-list'
expect "3.31: … both open; owner=mine only the administrator's; nobody's none; Jana's one; a company filter" \
  "$(grep -c 'data-deal="' "$WORK/response")|$(curl -s -b "$JAR" "$B/admin.php?module=pipeline&view=list&owner=mine" | grep -c 'data-deal="')|$(curl -s -b "$JAR" "$B/admin.php?module=pipeline&view=list&owner=none" | grep -c 'data-deal="')|$(curl -s -b "$JAR" "$B/admin.php?module=pipeline&view=list&owner=$B331_JANA" | grep -c "data-deal=\"$B331_D2\"")|$(curl -s -b "$JAR" "$B/admin.php?module=pipeline&view=list&company=$B331_CO" | grep -c 'data-deal="')" \
  "2|1|0|1|1"
expect "3.31: the palette search finds the deal by its customer's name, as JSON" \
  "$(curl -s -b "$JAR" "$B/admin.php?module=pipeline&action=search_json&q=Eva+B331" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo count(array_filter($d["deals"] ?? [], fn ($c) => $c["name"] === "Nový web B331" && str_contains($c["url"], "action=detail&id='"$B331_D1"'")));')" "1"
# the stages (administrators): rename, add, reorder; a stage with deals cannot be removed; too few stages are refused
check "3.31: Stages – the editor with the five default stages and two empty rows" 200 "/admin.php?module=pipeline&action=stages" 'data-stages-form'
expect "3.31: … seven rows, the deal counts, the stages with deals (New, Proposal) cannot be removed" "$(b331_has 'data-stage-row=')|$(b331_has 'name="remove\[\]"')" "7|3"
b331_post "$JAR" "module=pipeline&action=stages" "module=pipeline&action=stages_save" -d 'key[]=new' -d 'key[]=qualified' -d 'key[]=proposal' -d 'key[]=won' -d 'key[]=lost' -d 'key[]=' \
  -d 'label[]=New' -d 'label[]=Qualified' --data-urlencode 'label[]=Nabídka B331' -d 'label[]=Won' -d 'label[]=Lost' --data-urlencode 'label[]=Ukázka B331' \
  -d 'kind[]=open' -d 'kind[]=open' -d 'kind[]=open' -d 'kind[]=won' -d 'kind[]=lost' -d 'kind[]=open' -d 'order[]=1' -d 'order[]=2' -d 'order[]=4' -d 'order[]=5' -d 'order[]=6' -d 'order[]=3' > /dev/null
expect "3.31: … saved – a renamed stage keeps its key, the new one gets one from its label and its place; a default label stays translated (empty)" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'" | php -r 'echo implode(",", array_map(fn ($s) => $s["key"] . ":" . $s["label"] . ":" . $s["kind"], json_decode(stream_get_contents(STDIN), true)));')|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pipeline' AND akce = 'stages'")" \
  "new::open,qualified::open,ukazka_b331:Ukázka B331:open,proposal:Nabídka B331:open,won::won,lost::lost|1"
B331_STAGES=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'")
b331_post "$JAR" "module=pipeline&action=stages" "module=pipeline&action=stages_save" -d 'key[]=new' -d 'key[]=qualified' -d 'key[]=ukazka_b331' -d 'key[]=proposal' -d 'key[]=won' -d 'key[]=lost' \
  -d 'label[]=Nový' -d 'label[]=Ověřený' -d 'label[]=Ukázka' -d 'label[]=Nabídka' -d 'label[]=Vyhráno' -d 'label[]=Prohráno' -d 'kind[]=open' -d 'kind[]=open' -d 'kind[]=open' -d 'kind[]=open' -d 'kind[]=won' -d 'kind[]=lost' \
  -d 'order[]=1' -d 'order[]=2' -d 'order[]=3' -d 'order[]=4' -d 'order[]=5' -d 'order[]=6' -d 'remove[]=3' > /dev/null
check "3.31: … removing a stage that has deals is refused and the form keeps what was typed" 200 "/admin.php?module=pipeline&action=stages" 'class="hlaska hlaska-chyba"'
expect "3.31: … the setting unchanged, the refused labels offered again; too few stages refused too" \
  "$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'")" = "$B331_STAGES" ] && echo same)|$(b331_has 'value="Ukázka"')|$(b331_post "$JAR" "module=pipeline&action=stages" "module=pipeline&action=stages_save" -d 'key[]=won' -d 'key[]=lost' -d 'label[]=W' -d 'label[]=L' -d 'kind[]=won' -d 'kind[]=lost' -d 'order[]=1' -d 'order[]=2' > /dev/null; [ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'")" = "$B331_STAGES" ] && echo same)" \
  "same|1|same"
# 3.31.1: a stage with deals keeps its kind (Nabídka B331 holds the reopened deal) – refused, saying how many; a stage without deals may change it
a3311_stages() { b331_post "$JAR" "module=pipeline&action=stages" "module=pipeline&action=stages_save" -d 'key[]=new' -d 'key[]=qualified' -d 'key[]=ukazka_b331' -d 'key[]=proposal' -d 'key[]=won' -d 'key[]=lost' \
  -d 'label[]=New' -d 'label[]=Qualified' --data-urlencode 'label[]=Ukázka B331' --data-urlencode 'label[]=Nabídka B331' -d 'label[]=Won' -d 'label[]=Lost' \
  -d 'kind[]=open' -d 'kind[]=open' -d "kind[]=$1" -d "kind[]=$2" -d 'kind[]=won' -d 'kind[]=lost' -d 'order[]=1' -d 'order[]=2' -d 'order[]=3' -d 'order[]=4' -d 'order[]=5' -d 'order[]=6' > /dev/null; }
a3311_stages open lost
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pipeline&action=stages"
expect "3.31.1: changing the kind of a stage with deals is refused – the stage and the count of its deals named, nothing saved, the form keeps what was typed" \
  "$(b331_has 'class="hlaska hlaska-chyba"')|$(grep -cE 'Druh fáze „Nabídka B331“ nejde změnit, dokud jsou v ní obchody \(1\)|The kind of the stage “Nabídka B331” cannot change while deals are in it \(1\)' "$WORK/response")|$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'")" = "$B331_STAGES" ] && echo same)" "1|1|same"
a3311_stages lost open
A3311_KINDS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'" | php -r 'echo implode(",", array_map(fn ($s) => $s["key"] . ":" . $s["kind"], json_decode(stream_get_contents(STDIN), true)));')
a3311_stages open open
expect "3.31.1: … a stage without deals changes its kind, and back" \
  "$A3311_KINDS|$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'")" = "$B331_STAGES" ] && echo same)" \
  "new:open,qualified:open,ukazka_b331:lost,proposal:open,won:won,lost:lost|same"
# review of 3.31.1: data left inconsistent by 3.31.0 can be repaired – a lost deal in an open stage: the stage may become lost
# (every deal in it fits), but not open again while the deal is closed (refused with the hint); reopened, the stage turns back
a3311_kinds() { sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'" | php -r 'echo implode(",", array_map(fn ($s) => $s["key"] . ":" . $s["kind"], json_decode(stream_get_contents(STDIN), true)));'; }
sq "UPDATE ka_deals SET closed_at = '$(site_time)', lost_reason = 'price' WHERE id = ${B331_D1:-0}" > /dev/null
a3311_stages open lost; A3311_REPAIRED=$(a3311_kinds)
a3311_stages open open
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pipeline&action=stages"
expect "3.31.1 (review): a stage whose deals all fit the new kind changes it (the repair of 3.31.0 data); back to open is refused while its deal is closed, with the hint" \
  "$A3311_REPAIRED|$(a3311_kinds)|$(grep -cE 'Změnit jde, jen když všechny její obchody novému druhu už odpovídají|It can change only when all its deals already fit the new kind' "$WORK/response")" \
  "new:open,qualified:open,ukazka_b331:open,proposal:lost,won:won,lost:lost|new:open,qualified:open,ukazka_b331:open,proposal:lost,won:won,lost:lost|1"
sq "UPDATE ka_deals SET closed_at = NULL, lost_reason = NULL WHERE id = ${B331_D1:-0}" > /dev/null
a3311_stages open open
expect "3.31.1 (review): … once its deal is open again, the stage turns back to open" "$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'pipeline_stages'")" = "$B331_STAGES" ] && echo same)" "same"
# permissions: Jana (Enquiries, Customers, Pipeline; no administrator) moves and sees My work, but stages and delete are the
# administrators'; "Mine" is a filter; Petr (no Pipeline) gets 403 and no deal box, badge or palette search
B331_JJAR=$(b331_login b331-jana)
expect "3.31: Jana opens the board and her deal; Stages and Delete are 403 for her; no Stages tab, no Delete button" \
  "$(curl -s -b "$B331_JJAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=pipeline")|$(b331_has 'data-pipeline-stages')|$(curl -s -b "$B331_JJAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=pipeline&action=detail&id=$B331_D2")|$(b331_has 'data-deal-delete')|$(curl -s -b "$B331_JJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pipeline&action=stages")|$(b331_post "$B331_JJAR" "module=pipeline&action=detail&id=$B331_D2" "module=pipeline&action=delete" -d "id=$B331_D2" | cut -c1-3)" \
  "200|0|200|0|403|403"
b331_post "$B331_JJAR" "module=pipeline" "module=pipeline&action=move" -d "id=$B331_D2" -d stage=won -d return=module=pipeline > /dev/null
expect "3.31: … she wins her deal; her Mine shows only hers; My work lists her open deals (none now)" \
  "$(b331_deal "$B331_D2" | cut -d/ -f2,9)|$(curl -s -b "$B331_JJAR" "$B/admin.php?module=pipeline&view=list&status=all&owner=mine" | grep -o 'data-deal="[0-9]*"' | tr '\n' ' ')|$(b331_post "$B331_JJAR" "module=pipeline" "module=pipeline&action=move" -d "id=$B331_D2" -d stage=new -d return=detail > /dev/null; curl -s -b "$B331_JJAR" "$B/admin.php?module=my_work" | grep -c "data-list=\"deals\"")" \
  "won/closed|data-deal=\"$B331_D2\" |1"
B331_PJAR=$(b331_login b331-petr)
curl -s -b "$B331_PJAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$B331_ENQ"; B331_PTOKEN=$(csrf)
expect "3.31: Petr without the Pipeline – the board, a deal, a move, Create deal and the palette search are 403; his enquiry has no deal box, his list no badge, his palette no deals" \
  "$(curl -s -b "$B331_PJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pipeline")|$(curl -s -b "$B331_PJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pipeline&action=detail&id=$B331_D1")|$(curl -s -b "$B331_PJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pipeline&action=move" -d "_csrf=$B331_PTOKEN" -d "id=$B331_D1" -d stage=won)|$(curl -s -b "$B331_PJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pipeline&action=from_enquiry" -d "_csrf=$B331_PTOKEN" -d "idp=$B331_ENQ")|$(curl -s -b "$B331_PJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pipeline&action=search_json&q=B331")|$(b331_has 'data-deal-box')|$(b331_has 'data-deals=')|$(curl -s -b "$B331_PJAR" "$B/admin.php?module=enquiries" | grep -c 'data-enquiry-deal')|$(b331_deal "$B331_D1" | cut -d/ -f2)" \
  "403|403|403|403|403|0|0|0|proposal"
# 3.31.1: Petr opens Eva's customer page – her deal's moves are on the timeline only as "a deal": no title, stage or link
curl -s -b "$B331_PJAR" -o "$WORK/response" "$B/admin.php?module=contacts&action=detail&id=$B331_EVA"
expect "3.31.1: without the Pipeline the customer's timeline shows \"Obchod\" entries without the deal's title, stage or link; the company page too" \
  "$(b331_has 'data-timeline-entry="deal"' | sed 's/[2-9]/1/')|$(b331_has 'Nový web B331')|$(b331_has 'Nabídka B331')|$(b331_has 'module=pipeline&amp;action=detail')|$(curl -s -b "$B331_PJAR" "$B/admin.php?module=contacts&action=company&id=$B331_CO" | grep -c 'Nový web B331')" \
  "1|0|0|0|0"
# delete (administrators): the deal, its history, comments and tasks go; the customer and the enquiry stay
b331_post "$JAR" "module=pipeline&action=detail&id=$B331_D1" "module=pipeline&action=delete" -d "id=$B331_D1" > /dev/null
expect "3.31: Delete – the deal, its history, comments, tasks and notifications go; the customer stays; one change-log row" \
  "$(sq "SELECT COUNT(*) FROM ka_deals WHERE id = $B331_D1")|$(sq "SELECT COUNT(*) FROM ka_deal_stage_history WHERE deal_id = $B331_D1")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'deal:$B331_D1'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'deal:$B331_D1'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'deal:$B331_D1'")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = $B331_EVA")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pipeline' AND akce = 'delete' AND popis = '#$B331_D1'")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "0|0|0|0|0|1|1|1"
setting page_cache "$B331_CACHE"
sq "DELETE FROM ka_notifications WHERE user_id IN ($B331_JANA, $B331_PETR) OR target LIKE 'deal:%'; DELETE FROM ka_comments WHERE target LIKE 'deal:%'; DELETE FROM ka_tasks WHERE target LIKE 'deal:%';
  DELETE FROM ka_deal_stage_history; DELETE FROM ka_deals; DELETE FROM ka_nastaveni WHERE promenna = 'pipeline_stages';
  UPDATE ka_poptavky SET contact_id = NULL WHERE idp = $B331_ENQ; DELETE FROM ka_poptavky WHERE idp = $B331_ENQ;
  DELETE FROM ka_contact_activities WHERE contact_id IN (SELECT id FROM ka_contacts WHERE email_norm LIKE '%b331%'); DELETE FROM ka_contacts WHERE email_norm LIKE '%b331%'; DELETE FROM ka_companies WHERE name LIKE '%B331%';
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($B331_JANA, $B331_PETR); DELETE FROM ka_uzivatele WHERE user LIKE 'b331-%'" > /dev/null

