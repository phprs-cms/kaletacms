echo "== 3.31 (A): the lean pipeline – deals over MCP, from an enquiry, moves and history, retention, erasure, the monthly report"
t331_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
t331_json() { php -r '$v = json_decode((string) file_get_contents($argv[1]), true); foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? "true" : "false") : (string) $v);' "$WORK/text" "$1"; }
t331_token() { local t="kaleta_$(printf "$1%.0s" $(seq 1 48))"; sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($2, '$3', 'full', '$4', '$(php -r 'echo hash("sha256", $argv[1]);' "$t")', '$(site_time)')" > /dev/null; echo "$t"; }
T331_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
# Jana: Enquiries, Customers and the pipeline (work tools); Petr: Enquiries and Customers, no pipeline
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('t331-jana', '$T331_HASH', 'Jana T331', 'jana-t331@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('t331-petr', '$T331_HASH', 'Petr T331', 'petr-t331@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts' UNION SELECT 'pipeline') m WHERE user = 't331-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts') m WHERE user = 't331-petr'" > /dev/null
T331_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't331-jana'"); T331_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't331-petr'"); T331_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
T331_TJ=$(t331_token 3 "$T331_JANA" t331-jana work); T331_TP=$(t331_token 4 "$T331_PETR" t331-petr work); T331_TA=$(t331_token 5 "$T331_ADMIN" t331-admin all)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('contacts_backfill', '{\"enquiry\":2000000000,\"booking\":2000000000,\"subscriber\":2000000000}')" > /dev/null
curl -sL -o "$WORK/formular.html" "$B/kontakt"; FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
submit_form --data-urlencode "p0=Karel Dvořák" --data-urlencode p1=karel.t331@firma-t331.cz -d p2=777331331 --data-urlencode "p3=Secret message T331" -d p4=1 > /dev/null
T331_E1=$(sq "SELECT MAX(idp) FROM ka_poptavky"); T331_C1=$(sq "SELECT IFNULL(contact_id, 0) FROM ka_poptavky WHERE idp = ${T331_E1:-0}")
sq "UPDATE ka_poptavky SET tema = 'Website redesign T331', prirazeno = $T331_JANA WHERE idp = ${T331_E1:-0}" > /dev/null

# MCP: only for who may open the pipeline; the tools are in the work set
mcp_as "$T331_TP" list_deals '{}' > "$WORK/response"; mcp_text
expect "3.31 list_deals: refused without the pipeline (Petr has Customers only)" "$(grep -c 'Only a user who may open the pipeline' "$WORK/text")" "1"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $T331_TJ" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' > "$WORK/response"
expect "3.31: the work set lists list_deals, save_deal, move_deal and pipeline_summary" \
  "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $n = array_column($d["result"]["tools"] ?? [], "name"); echo count(array_intersect(["list_deals", "save_deal", "move_deal", "pipeline_summary"], $n));' "$WORK/response")" "4"
mkdir -p "$WORK/web/storage/cache/stranky"; echo cached > "$WORK/web/storage/cache/stranky/t331.html"
mcp_as "$T331_TA" save_deal "{\"enquiry\":${T331_E1:-0},\"value\":\"12 500,50\",\"currency\":\"czk\"}" > "$WORK/response"; mcp_text
T331_D1=$(t331_json id)
expect "3.31 save_deal from an enquiry: (3.32) who and what as the title, its customer, its handler responsible, the source, the first stage, the value with its currency, one history row; the page cache stays" \
  "$(t331_json created)|$(sq "SELECT CONCAT(title, '|', contact_id = ${T331_C1:-0}, '|', owner_id = $T331_JANA, '|', source_enquiry_id = ${T331_E1:-0}, '|', stage, '|', value, '|', currency, '|', closed_at IS NULL) FROM ka_deals WHERE id = ${T331_D1:-0}")|$(sq "SELECT CONCAT(COUNT(*), '|', IFNULL(MAX(from_stage), 'null'), '|', MAX(to_stage)) FROM ka_deal_stage_history WHERE deal_id = ${T331_D1:-0}")|$([ -f "$WORK/web/storage/cache/stranky/t331.html" ] && echo kept)" \
  "true|Karel Dvořák – Secret message T331|1|1|1|new|12500.50|CZK|1|1|null|new|kept"
expect "3.31: the responsible person is notified in the bell (assigned, deal:<id>); the change log has create; never journaled for undo" \
  "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $T331_JANA AND kind = 'assigned' AND target = 'deal:${T331_D1:-0}'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pipeline' AND akce = 'create' AND popis = '#${T331_D1:-0}'")|$(sq "SELECT COUNT(*) FROM ka_agent_journal WHERE tbl IN ('deals', 'deal_stage_history')")" "1|1|0"
mcp_as "$T331_TA" save_deal "{\"enquiry\":${T331_E1:-0}}" > "$WORK/response"; mcp_text
expect "3.31 save_deal: one open deal per enquiry – a second one is refused" "$(grep -c "already has an open deal (#${T331_D1:-0})" "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_deals WHERE source_enquiry_id = ${T331_E1:-0}")" "1|1"
# 3.31.1: save_deal with enquiry honours stage – an open stage is used; won or an unknown stage get the refusal of a deal
# without an enquiry, before the enquiry's contact is linked (the test deal goes again: the monthly report below counts the others)
T3311_E=$(sq "INSERT INTO ka_poptavky (datum, formular, tema, email, data, stav) VALUES ('$(site_time)', 'Poptávka T331', 'Stage T331', 'stage.t331@example.org', '[]', 1); SELECT LAST_INSERT_ID();")
mcp_as "$T331_TA" save_deal "{\"enquiry\":${T3311_E:-0},\"stage\":\"won\"}" > "$WORK/response"; mcp_text; T3311_R1=$(grep -c 'A new deal starts in an open stage' "$WORK/text")
mcp_as "$T331_TA" save_deal "{\"enquiry\":${T3311_E:-0},\"stage\":\"nope\"}" > "$WORK/response"; mcp_text; T3311_R2=$(grep -c 'A new deal starts in an open stage' "$WORK/text")
mcp_as "$T331_TA" save_deal '{"title":"Stage T331","stage":"nope"}' > "$WORK/response"; mcp_text; T3311_R3=$(grep -c 'A new deal starts in an open stage' "$WORK/text")
T3311_LINKED=$(sq "SELECT CONCAT(IFNULL(contact_id, 'null'), '|', (SELECT COUNT(*) FROM ka_deals WHERE source_enquiry_id = ${T3311_E:-0})) FROM ka_poptavky WHERE idp = ${T3311_E:-0}")
mcp_as "$T331_TA" save_deal "{\"enquiry\":${T3311_E:-0},\"stage\":\"qualified\"}" > "$WORK/response"; mcp_text; T3311_D=$(t331_json id)
expect "3.31.1 save_deal with enquiry: stage won and an unknown stage refused as without an enquiry, the contact not linked yet; an open stage is used, in its history" \
  "$T3311_R1|$T3311_R2|$T3311_R3|$T3311_LINKED|$(t331_json deal.stage)|$(sq "SELECT CONCAT(stage, '|', source_enquiry_id = ${T3311_E:-0}, '|', (SELECT GROUP_CONCAT(to_stage) FROM ka_deal_stage_history WHERE deal_id = ${T3311_D:-0})) FROM ka_deals WHERE id = ${T3311_D:-0}")" \
  "1|1|1|null|0|qualified|qualified|1|qualified"
sq "DELETE FROM ka_deal_stage_history WHERE deal_id = ${T3311_D:-0}; DELETE FROM ka_deals WHERE id = ${T3311_D:-0}; DELETE FROM ka_notifications WHERE target = 'deal:${T3311_D:-0}'" > /dev/null
# N331-1: as in the administration – Pia has the pipeline only: no deal from an enquiry (Enquiries), no contact or company (Customers)
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('t331-pia', '$T331_HASH', 'Pia T331', 'pia-t331@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pipeline' FROM ka_uzivatele WHERE user = 't331-pia'" > /dev/null
T331_PIA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't331-pia'"); T331_TPIA=$(t331_token 6 "$T331_PIA" t331-pia work); T331_NPIA=$(sq "SELECT COUNT(*) FROM ka_deals")
mcp_as "$T331_TPIA" save_deal "{\"enquiry\":${T331_E1:-0}}" > "$WORK/response"; mcp_text; T331_P1=$(grep -c 'Only a user who may open Enquiries' "$WORK/text")
mcp_as "$T331_TPIA" save_deal "{\"title\":\"Pia T331\",\"contact\":${T331_C1:-0}}" > "$WORK/response"; mcp_text; T331_P2=$(grep -c 'Only a user who may open Customers' "$WORK/text")
mcp_as "$T331_TPIA" save_deal "{\"id\":${T331_D1:-0},\"company\":\"none\"}" > "$WORK/response"; mcp_text; T331_P3=$(grep -c 'Only a user who may open Customers' "$WORK/text")
mcp_as "$T331_TPIA" list_deals '{}' > "$WORK/response"; mcp_text
expect "3.31 save_deal (N331-1): pipeline only – a deal from an enquiry and a contact or company are refused, nothing saved; list_deals works" \
  "$T331_P1|$T331_P2|$T331_P3|$(sq "SELECT COUNT(*) FROM ka_deals")|$(sq "SELECT company_id IS NOT NULL OR contact_id IS NOT NULL FROM ka_deals WHERE id = ${T331_D1:-0}")|$(t331_json total)" "1|1|1|$T331_NPIA|1|1"
mcp_as "$T331_TJ" save_deal "{\"id\":${T331_D1:-0},\"owner\":$T331_PETR}" > "$WORK/response"; mcp_text; T331_R1=$(grep -c 'cannot open the pipeline' "$WORK/text")
mcp_as "$T331_TJ" save_deal '{"title":"Bad T331","value":"-5"}' > "$WORK/response"; mcp_text; T331_R2=$(grep -c 'cannot be negative' "$WORK/text")
mcp_as "$T331_TJ" save_deal '{"title":"Bad T331","value":"100","currency":"XYZ"}' > "$WORK/response"; mcp_text; T331_R3=$(grep -c 'is not a currency' "$WORK/text")
mcp_as "$T331_TJ" save_deal '{"value":"100"}' > "$WORK/response"; mcp_text; T331_R4=$(grep -c 'Give the deal a title' "$WORK/text")
mcp_as "$T331_TJ" save_deal "{\"id\":${T331_D1:-0},\"stage\":\"won\"}" > "$WORK/response"; mcp_text; T331_R5=$(grep -c 'Move a deal to another stage' "$WORK/text")
expect "3.31 save_deal: refused – an owner without the pipeline, a negative value, an unknown currency, no title, a stage change outside move_deal; nothing saved" \
  "$T331_R1|$T331_R2|$T331_R3|$T331_R4|$T331_R5|$(sq "SELECT COUNT(*) FROM ka_deals WHERE title = 'Bad T331'")|$(sq "SELECT CONCAT(owner_id = $T331_JANA, stage) FROM ka_deals WHERE id = ${T331_D1:-0}")" "1|1|1|1|1|0|1new"
# moves: history, lost needs a reason, reopening clears it, won closes
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D1:-0},\"stage\":\"qualified\"}" > /dev/null
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D1:-0},\"stage\":\"lost\"}" > "$WORK/response"; mcp_text; T331_R6=$(grep -c 'Say why the deal was lost' "$WORK/text")
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D1:-0},\"stage\":\"lost\",\"lost_reason\":\"price\",\"lost_note\":\"Karel found it too expensive\"}" > "$WORK/response"; mcp_text
expect "3.31 move_deal: lost without a reason is refused; with one the deal closes with the reason and note" \
  "$T331_R6|$(t331_json deal.status)|$(sq "SELECT CONCAT(stage, '|', closed_at IS NOT NULL, '|', lost_reason, '|', lost_note) FROM ka_deals WHERE id = ${T331_D1:-0}")" "1|lost|lost|1|price|Karel found it too expensive"
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D1:-0},\"stage\":\"proposal\"}" > "$WORK/response"; mcp_text
expect "3.31 move_deal: back to an open stage reopens it – closed_at, the reason and the note are cleared" \
  "$(t331_json deal.status)|$(sq "SELECT CONCAT(stage, '|', closed_at IS NULL, '|', lost_reason IS NULL, '|', lost_note IS NULL) FROM ka_deals WHERE id = ${T331_D1:-0}")" "open|proposal|1|1|1"
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D1:-0},\"stage\":\"won\"}" > /dev/null
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D1:-0},\"stage\":\"won\"}" > "$WORK/response"; mcp_text
expect "3.31 move_deal: won closes it; the same stage again changes nothing; the history has every move; the change log names the stages" \
  "$(t331_json moved)|$(sq "SELECT GROUP_CONCAT(CONCAT(IFNULL(from_stage, '-'), '>', to_stage) ORDER BY id) FROM ka_deal_stage_history WHERE deal_id = ${T331_D1:-0}")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pipeline' AND akce = 'move' AND popis = '#${T331_D1:-0} proposal → won'")" \
  "false|->new,new>qualified,qualified>lost,lost>proposal,proposal>won|1"
# a deal by hand, Jana's own ("me"), due yesterday – it waits for her in My work
mcp_as "$T331_TJ" save_deal "{\"title\":\"Support plan T331\",\"contact\":${T331_C1:-0},\"owner\":\"me\",\"value\":\"300\",\"currency\":\"EUR\",\"expected_close\":\"$(site_date '-1 day')\"}" > "$WORK/response"; mcp_text
T331_D2=$(t331_json id)
expect "3.31 save_deal by hand: the contact's company follows, Jana responsible (no notification to herself), the currency as given" \
  "$(sq "SELECT CONCAT(owner_id = $T331_JANA, '|', currency, '|', IFNULL(company_id, 'null') = IFNULL((SELECT company_id FROM ka_contacts WHERE id = ${T331_C1:-0}), 'null')) FROM ka_deals WHERE id = ${T331_D2:-0}")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'deal:${T331_D2:-0}'")" "1|EUR|1|0"
mcp_as "$T331_TJ" list_deals '{"owner":"me"}' > "$WORK/response"; mcp_text
expect "3.31 list_deals: mine and open by default – the hand-made deal (the won one is closed), with the stages" \
  "$(t331_json total)|$(t331_json deals.0.id)|$(t331_json deals.0.status)|$(t331_json stages.3.kind)" "1|${T331_D2:-0}|open|won"
mcp_as "$T331_TJ" list_deals '{"status":"won"}' > "$WORK/response"; mcp_text
expect "3.31 list_deals: status won – the deal from the enquiry with its contact, value and source" "$(t331_json total)|$(t331_json deals.0.id)|$(t331_json deals.0.value)|$(t331_json deals.0.currency)|$(t331_json deals.0.enquiry)" \
  "1|${T331_D1:-0}|12500.50|CZK|${T331_E1:-0}"
mcp_as "$T331_TJ" pipeline_summary '{}' > "$WORK/response"; mcp_text
expect "3.31 pipeline_summary: one open, one won this month; values only as sums per currency" \
  "$(t331_json open)|$(t331_json won_this_month)|$(t331_json lost_this_month)|$(t331_json open_value)|$(t331_json stages.3.value)" '1|1|0|{"EUR":"300.00"}|{"CZK":"12500.50"}'
expect "3.31 My work (Deals::forOwner): Jana's open deal, its stage and overdue expected close" \
  "$(t331_php "echo json_encode(array_map(fn (\$d) => [\$d['id'], \$d['stage'], \$d['overdue']], Kaleta\\Core\\Deals::forOwner(\$app, $T331_JANA)));")" "[[${T331_D2:-0},\"new\",true]]"
mcp_as "$T331_TJ" get_contact "{\"id\":${T331_C1:-0},\"timeline\":20}" > "$WORK/response"; mcp_text
expect "3.31: the deals' moves are on the customer's timeline (created, moved, lost, reopened, won)" \
  "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo implode(",", array_unique(array_column(array_filter($d["timeline"] ?? [], fn ($e) => $e["kind"] === "deal"), "status")));' "$WORK/text")" "created,won,reopened,lost,moved"
# 3.31.1: Petr (Customers, no pipeline) sees the same moves only as "a deal" – no title, stage, status, who or link
mcp_as "$T331_TP" get_contact "{\"id\":${T331_C1:-0},\"timeline\":20}" > "$WORK/response"; mcp_text
expect "3.31.1 get_contact without the pipeline: the deal entries are there, without title, stage, status or who; no stage name in the answer (the enquiry keeps its topic)" \
  "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $e = array_filter($d["timeline"] ?? [], fn ($e) => $e["kind"] === "deal"); echo count($e) > 0 ? "some" : "none", "|", count(array_filter($e, fn ($x) => isset($x["title"]) || isset($x["text"]) || isset($x["status"]) || isset($x["by"])));' "$WORK/text")|$(grep -c '"Proposal"' "$WORK/text")" \
  "some|0|0"
expect "3.31: the hand-over counts Jana's open deal" "$(t331_php "echo Kaleta\\Core\\Handover::owned(\$app->db(), $T331_JANA)['deal'];")" "1"
expect "3.31: deleting a deal needs an administrator" "$(t331_php "try { Kaleta\\Core\\Deals::delete(\$app, ${T331_D2:-0}); echo 'deleted'; } catch (DomainException \$e) { echo 'refused'; }")|$(sq "SELECT COUNT(*) FROM ka_deals WHERE id = ${T331_D2:-0}")" "refused|1"

# the monthly report: counts only, never values
expect "3.31 monthly report: new, won and lost deals and the stages reached this month – never a value" \
  "$(t331_php '$d = Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable("first day of this month")); $p = $d["pipeline"] ?? []; echo $p["new"] ?? "x", "|", $p["won"] ?? "x", "|", $p["lost"] ?? "x", "|", json_encode(array_column($p["stages"] ?? [], "n", "key")), "|", (int) str_contains(json_encode($p), "12500"), "|", (int) str_contains(json_encode($p), "300");')" \
  '2|1|0|{"new":2,"qualified":1,"proposal":1,"won":1,"lost":1}|0|0'
# N331-3: the stage "won" turned into an open one (a new "done" wins now) – its closed deal is no longer won, and never counted as lost
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('pipeline_stages', '[{\"key\":\"new\",\"label\":\"\",\"kind\":\"open\"},{\"key\":\"qualified\",\"label\":\"\",\"kind\":\"open\"},{\"key\":\"proposal\",\"label\":\"\",\"kind\":\"open\"},{\"key\":\"won\",\"label\":\"\",\"kind\":\"open\"},{\"key\":\"done\",\"label\":\"Done\",\"kind\":\"won\"},{\"key\":\"lost\",\"label\":\"\",\"kind\":\"lost\"}]')" > /dev/null
expect "3.31 monthly report (N331-3): a closed deal in a stage that is open now is neither won nor lost" \
  "$(t331_php '$p = Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable("first day of this month"))["pipeline"] ?? []; echo $p["won"] ?? "x", "|", $p["lost"] ?? "x";')" "0|0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('pipeline_stages', '')" > /dev/null

# retention (D-W6): an enquiry of a deal stays while the deal is open and for the retention after it closed
sq "UPDATE ka_poptavky SET datum = '$(site_time '-30 months')' WHERE idp = ${T331_E1:-0}" > /dev/null
t331_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());'
expect "3.31 retention: the expired enquiry of a deal is kept while a deal of its customer is open" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = ${T331_E1:-0}")" "1"
mcp_as "$T331_TJ" move_deal "{\"id\":${T331_D2:-0},\"stage\":\"lost\",\"lost_reason\":\"no_response\"}" > /dev/null
t331_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());'
expect "3.31 retention: … and after both deals closed, for the enquiries' retention after it" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = ${T331_E1:-0}")" "1"
sq "UPDATE ka_deals SET closed_at = '$(site_time '-25 months')' WHERE id IN (${T331_D1:-0}, ${T331_D2:-0})" > /dev/null
t331_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());'
expect "3.31 retention: closed longer than the retention – the enquiry goes, the deal stays and forgets it" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = ${T331_E1:-0}")|$(sq "SELECT CONCAT(COUNT(*), IFNULL(MAX(source_enquiry_id), 'null')) FROM ka_deals WHERE id = ${T331_D1:-0}")" "0|1null"
# a customer with an open deal is never anonymised; once it closed, the customer goes and the deal stays without the person
T331_C5=$(t331_php "echo Kaleta\\Core\\Contacts::create(\$app, ['email' => 'olga.t331@example.org', 'name' => 'Olga Nováková'], $T331_ADMIN);")
T331_D3=$(t331_php "echo Kaleta\\Core\\Deals::save(\$app, null, ['title' => 'Olga Nováková – yearly contract', 'contact_id' => ${T331_C5:-0}], $T331_ADMIN);")
sq "UPDATE ka_contacts SET created_at = '$(site_time '-30 months')', last_activity_at = '$(site_time '-30 months')' WHERE id = ${T331_C5:-0}" > /dev/null
t331_php 'Kaleta\Core\Contacts::purge($app);' > /dev/null
expect "3.31 retention: a customer quiet for 30 months with an open deal is not anonymised" "$(sq "SELECT CONCAT(anonymised_at IS NULL, '|', name) FROM ka_contacts WHERE id = ${T331_C5:-0}")" "1|Olga Nováková"
t331_php "Kaleta\\Core\\Deals::move(\$app, ${T331_D3:-0}, 'lost', $T331_ADMIN, 'timing', 'Olga will call in spring');" > /dev/null
# the team's comments and tasks on the deal follow the rule of the comments on the customer (3.31): anonymised – who and when stay, the text goes
t331_php "Kaleta\\Core\\Comments::add(\$app->db(), 'deal:${T331_D3:-0}', 'Olga prefers calls T331', ['idu' => $T331_ADMIN, 'user' => 'admin']);
  Kaleta\\Core\\Tasks::create(\$app, ['title' => 'Call Olga Nováková T331', 'target' => 'deal:${T331_D3:-0}'], $T331_ADMIN);
  Kaleta\\Core\\Inbox::record(\$app->db(), $T331_JANA, 'comment', 'deal:${T331_D3:-0}', $T331_ADMIN, 'Olga prefers calls T331');" > /dev/null
sq "UPDATE ka_contacts SET last_activity_at = '$(site_time '-30 months')' WHERE id = ${T331_C5:-0}; UPDATE ka_deals SET closed_at = '$(site_time '-30 months')' WHERE id = ${T331_D3:-0}" > /dev/null
t331_php 'Kaleta\Core\Contacts::purge($app);' > /dev/null
expect "3.31 retention: … closed, the customer is anonymised; the deal stays without the person – no contact, no note, a title naming them becomes its number" \
  "$(sq "SELECT anonymised_at IS NOT NULL FROM ka_contacts WHERE id = ${T331_C5:-0}")|$(sq "SELECT CONCAT(IFNULL(contact_id, 'null'), '|', title, '|', IFNULL(lost_note, 'null'), '|', lost_reason) FROM ka_deals WHERE id = ${T331_D3:-0}")" \
  "1|null|#${T331_D3:-0}|null|timing"
expect "3.31 retention: … the comments on the deal keep who and when without the text and mentions, its task without the title, its notification without the quote" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(text = ''), '|', SUM(mentions IS NULL), '|', SUM(author_id = $T331_ADMIN)) FROM ka_comments WHERE target = 'deal:${T331_D3:-0}'")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(title = '')) FROM ka_tasks WHERE target = 'deal:${T331_D3:-0}'")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(excerpt = '')) FROM ka_notifications WHERE target = 'deal:${T331_D3:-0}' AND kind = 'comment'")" \
  "1|1|1|1|1|1|1|1"
# erasure on request: the file lists the deals; erasing keeps them without the person
t331_php "Kaleta\\Core\\Comments::add(\$app->db(), 'deal:${T331_D1:-0}', 'Karel asked for a discount T331', ['idu' => $T331_ADMIN, 'user' => 'admin']);
  Kaleta\\Core\\Tasks::create(\$app, ['title' => 'Send Karel Dvořák the offer T331', 'target' => 'deal:${T331_D1:-0}'], $T331_ADMIN);
  Kaleta\\Core\\Inbox::record(\$app->db(), $T331_JANA, 'comment', 'deal:${T331_D1:-0}', $T331_ADMIN, 'Karel asked for a discount T331');" > /dev/null
expect "3.31 personal data: the file of the customer lists their deals, with the team's comments and tasks on each" \
  "$(t331_php '$f = json_decode(Kaleta\Core\PersonalData::export($app, "karel.t331@firma-t331.cz"), true); $d = array_column($f["contacts"][0]["deals"] ?? [], null, "id"); echo count($d), "|", $d['"${T331_D1:-0}"']["notes"][0]["text"] ?? "", "|", $d['"${T331_D1:-0}"']["tasks"][0]["title"] ?? "", "|", count($d['"${T331_D2:-0}"']["notes"] ?? [1]);')" \
  "2|Karel asked for a discount T331|Send Karel Dvořák the offer T331|0"
mcp_as "$T331_TA" erase_personal_data '{"email":"karel.t331@firma-t331.cz","confirm":true}' > /dev/null
expect "3.31 erase_personal_data: the contact goes, its deals stay without it (no contact, no lost note)" \
  "$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = ${T331_C1:-0}")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(contact_id IS NULL), '|', SUM(lost_note IS NULL)) FROM ka_deals WHERE id IN (${T331_D1:-0}, ${T331_D2:-0})")" "0|2|2|2"
expect "3.31 erase_personal_data: … the comments on its deals go, the deal's task stays without its title, the notification without the quote" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('deal:${T331_D1:-0}', 'deal:${T331_D2:-0}')")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(title = '')) FROM ka_tasks WHERE target = 'deal:${T331_D1:-0}'")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(excerpt = '')) FROM ka_notifications WHERE target = 'deal:${T331_D1:-0}' AND kind = 'comment'")" \
  "0|1|1|1|1"
T331_M1=$(t331_php "echo Kaleta\\Core\\Contacts::create(\$app, ['email' => 'm1.t331@example.org'], $T331_ADMIN);"); T331_M2=$(t331_php "echo Kaleta\\Core\\Contacts::create(\$app, ['email' => 'm2.t331@example.org'], $T331_ADMIN);")
T331_D4=$(t331_php "echo Kaleta\\Core\\Deals::save(\$app, null, ['title' => 'Merge T331', 'contact_id' => ${T331_M2:-0}], $T331_ADMIN);")
expect "3.31: merging two contacts moves the deals of the merged one" "$(t331_php "echo Kaleta\\Core\\Contacts::merge(\$app, ${T331_M1:-0}, ${T331_M2:-0}, $T331_ADMIN)['moved']['deals'];")|$(sq "SELECT contact_id = ${T331_M1:-0} FROM ka_deals WHERE id = ${T331_D4:-0}")" "1|1"
# N331-2: a deal forgets an enquiry deleted by hand (MCP delete_enquiry; also the admin's Delete, bulk delete, the job applications' purge) and a deleted company
T331_E9=$(sq "INSERT INTO ka_poptavky (datum, formular, tema, email, data, stav) VALUES ('$(site_time)', 'Poptávka T331', 'Gone T331', '', '[]', 1); SELECT LAST_INSERT_ID();")
T331_CO9=$(t331_php "echo Kaleta\\Core\\Contacts::saveCompany(\$app, null, ['name' => 'Gone Co T331'], $T331_ADMIN);")
T331_D9=$(t331_php "echo Kaleta\\Core\\Deals::save(\$app, null, ['title' => 'Forget T331', 'source_enquiry_id' => ${T331_E9:-0}, 'company_id' => ${T331_CO9:-0}], $T331_ADMIN);")
mcp_as "$T331_TA" delete_enquiry "{\"id\":${T331_E9:-0}}" > /dev/null
t331_php "Kaleta\\Core\\Contacts::deleteCompany(\$app, ${T331_CO9:-0});" > /dev/null
expect "3.31 (N331-2): a deleted enquiry and a deleted company leave the deal, which stays" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = ${T331_E9:-0}")|$(sq "SELECT CONCAT(title, '|', source_enquiry_id IS NULL, '|', company_id IS NULL) FROM ka_deals WHERE id = ${T331_D9:-0}")" "0|Forget T331|1|1"
check "3.31: the record of processing names the deals" 200 "/admin.php?module=settings&action=processing_record" "Obchody se zákazníky"
sq "DELETE FROM ka_deal_stage_history WHERE deal_id IN (SELECT id FROM ka_deals WHERE title LIKE '%T331%' OR id IN (${T331_D1:-0}, ${T331_D2:-0}, ${T331_D3:-0})); DELETE FROM ka_deals WHERE title LIKE '%T331%' OR id IN (${T331_D1:-0}, ${T331_D2:-0}, ${T331_D3:-0}); /* 3.32: an erased customer's name leaves the title as #<id> */
  DELETE FROM ka_notifications WHERE target LIKE 'deal:%'; DELETE FROM ka_comments WHERE target LIKE 'deal:%'; DELETE FROM ka_tasks WHERE target LIKE 'deal:%'; DELETE FROM ka_contact_activities WHERE contact_id IN (SELECT id FROM ka_contacts WHERE email LIKE '%t331%');
  DELETE FROM ka_contacts WHERE email LIKE '%t331%' OR id = ${T331_C5:-0}; DELETE FROM ka_companies WHERE domain = 'firma-t331.cz';
  DELETE FROM ka_poptavky WHERE email LIKE '%t331%'; DELETE FROM ka_api_tokeny WHERE nazev LIKE 't331-%'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($T331_JANA, $T331_PETR, ${T331_PIA:-0}); DELETE FROM ka_uzivatele WHERE user LIKE 't331-%';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('contacts_backfill', '')" > /dev/null

