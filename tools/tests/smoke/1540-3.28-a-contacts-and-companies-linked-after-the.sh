echo "== 3.28 (A): contacts and companies – linked after the response, the backfill, merge, retention, erasure, MCP"
t328_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
t328_json() { php -r '$v = json_decode((string) file_get_contents($argv[1]), true); foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? "true" : "false") : (string) $v);' "$WORK/text" "$1"; }
t328_token() { local t="kaleta_$(printf "$1%.0s" $(seq 1 48))"; sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($2, '$3', 'full', '$4', '$(php -r 'echo hash("sha256", $argv[1]);' "$t")', '$(site_time)')" > /dev/null; echo "$t"; }
T328_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
# Jana: Enquiries and Customers (work tools); Petr: Enquiries only; Eva: Pages only
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('t328-jana', '$T328_HASH', 'Jana T328', 'jana-t328@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('t328-petr', '$T328_HASH', 'Petr T328', 'petr-t328@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('t328-eva', '$T328_HASH', 'Eva T328', 'eva-t328@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts') m WHERE user = 't328-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user = 't328-petr';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 't328-eva'" > /dev/null
T328_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't328-jana'"); T328_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't328-petr'")
T328_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't328-eva'"); T328_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
T328_TJ=$(t328_token 1 "$T328_JANA" t328-jana work); T328_TE=$(t328_token 2 "$T328_EVA" t328-eva all); T328_TA=$(t328_token 9 "$T328_ADMIN" t328-admin all)
T328_CO=$(sq "INSERT INTO ka_companies (name, domain, created_at, updated_at) VALUES ('Firma T328', 'firma-t328.cz', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
# the linking job is held far ahead: whatever is linked below, the after-response path linked it
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('contacts_backfill', '{\"enquiry\":2000000000,\"booking\":2000000000,\"subscriber\":2000000000}')" > /dev/null
curl -sL -o "$WORK/formular.html" "$B/kontakt"; FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
t328_submit() { sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null; submit_form "$@" > /dev/null; sq "SELECT MAX(idp) FROM ka_poptavky"; }
T328_E1=$(t328_submit --data-urlencode "p0=Marta T328" --data-urlencode p1=Marta.T328@Firma-T328.cz -d p2=777328328 --data-urlencode "p3=Tajná zpráva T328" -d p4=1)
expect "3.28: a submitted enquiry is linked after the response – one contact by the normalised address, the name and phone from the form, the company of its domain, source enquiry" \
  "$(sq "SELECT CONCAT(c.email_norm, '|', c.name, '|', c.phone, '|', c.company_id = $T328_CO, '|', c.source, '|', c.first_seen_at = p.datum, '|', c.last_activity_at = p.datum) FROM ka_poptavky p JOIN ka_contacts c ON c.id = p.contact_id WHERE p.idp = ${T328_E1:-0}")" \
  "marta.t328@firma-t328.cz|Marta T328|777328328|1|enquiry|1|1"
T328_C1=$(sq "SELECT contact_id FROM ka_poptavky WHERE idp = ${T328_E1:-0}")
T328_E2=$(t328_submit --data-urlencode "p0=Marta" --data-urlencode p1=MARTA.t328@firma-t328.CZ --data-urlencode "p3=Druhá T328" -d p4=1)
T328_E3=$(t328_submit --data-urlencode "p0=Marta Home" --data-urlencode p1=marta.t328@gmail.com --data-urlencode "p3=Z domova T328" -d p4=1)
expect "3.28: the same address in other letters joins the same contact; a different address (free-mail) never does – its own contact, no company" \
  "$(sq "SELECT contact_id = ${T328_C1:-0} FROM ka_poptavky WHERE idp = ${T328_E2:-0}")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE email_norm LIKE 'marta.t328@%'")|$(sq "SELECT CONCAT(contact_id <> ${T328_C1:-0}, '|', (SELECT company_id IS NULL FROM ka_contacts WHERE id = p.contact_id)) FROM ka_poptavky p WHERE idp = ${T328_E3:-0}")" \
  "1|2|1|1"
T328_C2=$(sq "SELECT contact_id FROM ka_poptavky WHERE idp = ${T328_E3:-0}")
# a subscription joins its customer once it is confirmed (the public confirmation, after the response)
sq "INSERT INTO ka_odberatele (email, token, datum, stav) VALUES ('marta.t328@firma-t328.cz', 'abcd0000abcd0000abcd0000abcd0328', '$(site_time)', 0)" > /dev/null
expect "3.28: an unconfirmed subscription is not linked" "$(sq "SELECT contact_id IS NULL FROM ka_odberatele WHERE email = 'marta.t328@firma-t328.cz'")" "1"
curl -s -o /dev/null -X POST "$B/odber?confirm=abcd0000abcd0000abcd0000abcd0328"
expect "3.28: … the confirmed one joins the contact after the response" "$(sq "SELECT CONCAT(stav, '|', contact_id = ${T328_C1:-0}) FROM ka_odberatele WHERE email = 'marta.t328@firma-t328.cz'")" "1|1"

# the backfill job: enquiries from before 3.28 (no contact yet) are linked a batch at a time, a row without an address stays
T328_OLD=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time '-20 months')', 'Starý formulář T328', 'Stary.T328@example.cz', '[[\"Jméno\",\"Starý Zákazník\"]]', 2),
  ('$(site_time '-19 months')', 'Starý formulář T328', 'stary.t328@example.cz', '[]', 2), ('$(site_time '-19 months')', 'Bez adresy T328', '', '[]', 2); SELECT LAST_INSERT_ID();")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('contacts_backfill', '{\"enquiry\":$(( T328_OLD - 1 )),\"booking\":2000000000,\"subscriber\":2000000000}')" > /dev/null
T328_B1=$(t328_php 'echo json_encode(Kaleta\Core\Contacts::backfill($app, 2));'); T328_B2=$(t328_php '$jobs = Kaleta\Core\Scheduler::jobs(); echo $jobs["contacts"][3]($app, "cron");')
expect "3.28 job contacts: two rows per run here, then the rest; both old enquiries on one contact (first seen = the older one), the row without an address stays unlinked" \
  "$T328_B1|$T328_B2|$(sq "SELECT CONCAT(COUNT(DISTINCT contact_id), '|', COUNT(*)) FROM ka_poptavky WHERE formular = 'Starý formulář T328'")|$(sq "SELECT contact_id IS NULL FROM ka_poptavky WHERE formular = 'Bez adresy T328'")|$(sq "SELECT CONCAT(name, '|', DATE(first_seen_at) = '$(site_date '-20 months')') FROM ka_contacts WHERE email_norm = 'stary.t328@example.cz'")" \
  '{"linked":2,"scanned":2,"done":false}|linked 0 of 1|1|2|1|Starý Zákazník|1'
T328_C3=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'stary.t328@example.cz'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('contacts_backfill', '')" > /dev/null

# MCP: Customers only for who may open it; the work set lists the tools
mcp_as "$T328_TE" list_contacts '{}' > "$WORK/response"; mcp_text
expect "3.28 list_contacts: refused without Customers" "$(grep -c 'Only a user who may open Customers' "$WORK/text")" "1"
mcp_as "$T328_TJ" list_contacts '{"search":"firma-t328"}' > "$WORK/response"; mcp_text
expect "3.28 list_contacts: by search – the contact with its company, two enquiries and the subscription" \
  "$(t328_json total)|$(t328_json contacts.0.id)|$(t328_json contacts.0.company.name)|$(t328_json contacts.0.enquiries)|$(t328_json contacts.0.subscribed)" "1|$T328_C1|Firma T328|2|true"
mcp_as "$T328_TJ" get_contact '{"email":"MARTA.T328@firma-t328.cz","timeline":5}' > "$WORK/response"; mcp_text
expect "3.28 get_contact: by address – the counts, a bounded timeline newest first (the subscription, the enquiries), never the enquiry's message" \
  "$(t328_json contact.id)|$(t328_json counts.enquiries)|$(t328_json timeline.0.kind)|$(grep -o '"kind":"enquiry"' "$WORK/text" | wc -l | tr -d ' ')|$(grep -c 'Tajná zpráva' "$WORK/text")" "$T328_C1|2|subscription|2|0"
mcp_as "$T328_TJ" save_contact '{"email":"marta.t328@firma-t328.cz","name":"Copy"}' > "$WORK/response"; mcp_text
expect "3.28 save_contact: an address that has a contact is refused – never a second record" "$(grep -c "already exists (#$T328_C1)" "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE name = 'Copy'")" "1|0"
mcp_as "$T328_TJ" save_contact '{"name":"Ruční T328","phone":"+420 600 328 328","company":"Nová firma T328","company_domain":"https://www.nova-t328.cz/","owner":"me"}' > "$WORK/response"; mcp_text
T328_C4=$(t328_json id)
expect "3.28 save_contact: a contact by hand with a new company (created from the name, its domain cleaned), Jana responsible; not undoable, so nothing in the journal" \
  "$(t328_json created)|$(sq "SELECT CONCAT(c.source, '|', c.owner_id = $T328_JANA, '|', co.name, '|', co.domain) FROM ka_contacts c JOIN ka_companies co ON co.id = c.company_id WHERE c.id = ${T328_C4:-0}")|$(sq "SELECT COUNT(*) FROM ka_agent_journal WHERE tbl IN ('contacts', 'companies', 'contact_activities')")" \
  "true|manual|1|Nová firma T328|nova-t328.cz|0"
mcp_as "$T328_TJ" save_contact "{\"id\":${T328_C4:-0},\"owner\":$T328_PETR}" > "$WORK/response"; mcp_text
expect "3.28 save_contact: a responsible person without Customers is refused" "$(grep -c 'cannot open Customers' "$WORK/text")|$(sq "SELECT owner_id = $T328_JANA FROM ka_contacts WHERE id = ${T328_C4:-0}")" "1|1"
mkdir -p "$WORK/web/storage/cache/stranky"; echo cached > "$WORK/web/storage/cache/stranky/t328.html"
mcp_as "$T328_TJ" log_activity "{\"contact\":${T328_C1:-0},\"kind\":\"call\",\"text\":\"Called about the offer T328\"}" > "$WORK/response"; mcp_text
expect "3.28 log_activity: a call on the contact by Jana, its last activity moves; the page cache stays" \
  "$(t328_json logged)|$(sq "SELECT CONCAT(kind, '|', author_id = $T328_JANA, '|', author_name) FROM ka_contact_activities WHERE contact_id = ${T328_C1:-0}")|$(sq "SELECT last_activity_at >= '$(site_time '-1 minute')' FROM ka_contacts WHERE id = ${T328_C1:-0}")|$([ -f "$WORK/web/storage/cache/stranky/t328.html" ] && echo kept)" \
  "true|call|1|Jana T328|1|kept"
mcp_as "$T328_TJ" add_comment "{\"target\":\"contact:${T328_C1:-0}\",\"text\":\"Prefers mornings T328\"}" > /dev/null
mcp_as "$T328_TJ" save_task "{\"title\":\"Send the offer T328\",\"target\":\"contact:${T328_C1:-0}\"}" > /dev/null
expect "3.28: comments and tasks about a customer (contact:<id>), never journaled" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'contact:${T328_C1:-0}'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'contact:${T328_C1:-0}'")|$(sq "SELECT COUNT(*) FROM ka_agent_journal WHERE tbl IN ('comments', 'tasks') AND (before_row LIKE '%T328%' OR after_row LIKE '%T328%')")" "1|1|0"
mcp_as "$T328_TE" add_comment "{\"target\":\"contact:${T328_C1:-0}\",\"text\":\"x\"}" > "$WORK/response"; mcp_text
expect "3.28 add_comment: on a customer only with Customers" "$(grep -c 'Only a user who may open Customers' "$WORK/text")" "1"
# merge: a person's decision – confirm needed; the free-mail contact goes into the company one, a note keeps the history
mcp_as "$T328_TA" merge_contacts "{\"into\":${T328_C1:-0},\"from\":${T328_C2:-0}}" > "$WORK/response"; mcp_text
expect "3.28 merge_contacts: without confirm nothing changes" "$(grep -c 'needs confirm=true' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = ${T328_C2:-0}")" "1|1"
mcp_as "$T328_TJ" merge_contacts "{\"into\":${T328_C1:-0},\"from\":${T328_C2:-0},\"confirm\":true}" > "$WORK/response"; mcp_text
expect "3.28 merge_contacts (N328-1): administrators only, as in the administration – Jana with Customers is refused, nothing changes" "$(grep -c 'Only an administrator can merge customers' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = ${T328_C2:-0}")" "1|1"
T328_MARK_BEFORE=$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'contacts_merged_at' AND hodnota <> ''")
mcp_as "$T328_TA" merge_contacts "{\"into\":${T328_C1:-0},\"from\":${T328_C2:-0},\"confirm\":true}" > "$WORK/response"; mcp_text
expect "3.28 (N328-3): linking looks for an address linked elsewhere only after the first merge (no scan of the enquiries before)" "$T328_MARK_BEFORE|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'contacts_merged_at' AND hodnota <> ''")" "0|1"
expect "3.28 merge_contacts: the enquiry moves, the other contact is gone, a note names the merged address, the change log has it" \
  "$(t328_json moved.enquiries)|$(sq "SELECT contact_id = ${T328_C1:-0} FROM ka_poptavky WHERE idp = ${T328_E3:-0}")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = ${T328_C2:-0}")|$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE contact_id = ${T328_C1:-0} AND kind = 'note' AND text LIKE '%marta.t328@gmail.com%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND akce = 'merge' AND popis = '#${T328_C2:-0} → #${T328_C1:-0}'")" \
  "1|1|0|1|1"
T328_E4=$(t328_submit --data-urlencode "p0=Marta" --data-urlencode p1=marta.t328@gmail.com --data-urlencode "p3=Zase z domova T328" -d p4=1)
expect "3.28: a new enquiry from the merged address joins the merged contact, no new one" \
  "$(sq "SELECT contact_id = ${T328_C1:-0} FROM ka_poptavky WHERE idp = ${T328_E4:-0}")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE email_norm = 'marta.t328@gmail.com'")" "1|0"
# the hand-over of a leaver counts and moves the customers they are responsible for
expect "3.28 hand-over: Jana's customer is counted and moves" \
  "$(t328_php "echo Kaleta\Core\Handover::owned(\$app->db(), $T328_JANA)['contact'], '|', Kaleta\Core\Handover::handOver(\$app->db(), $T328_JANA, $T328_ADMIN)['contact'];")|$(sq "SELECT owner_id = $T328_ADMIN FROM ka_contacts WHERE id = ${T328_C4:-0}")" "1|1|1"

# retention: the contacts' period sits next to the enquiries'; an expiring enquiry leaves "first contact" in the summary; a
# contact with no activity for the period is anonymised (its log keeps kind and time, its links go)
check "3.28: Enquiries → settings shows the customers' retention" 200 "/admin.php?module=enquiries" 'name="contacts_months"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=settings" -d "_csrf=$(csrf)" -d mesice=24 -d mesice_uchazeci=0 -d po_uplynuti=delete -d contacts_months=12
expect "3.28: … saved" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'contacts_months'")" "12"
t328_php "Kaleta\Core\Contacts::logActivity(\$app, ${T328_C3:-0}, 'note', 'Old note T328', ['idu' => $T328_ADMIN, 'jmeno' => 'Admin'], '$(site_time '-18 months' 'Y-m-d H:i')');" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '12')" > /dev/null
t328_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());'
expect "3.28: the expired enquiries are gone, the contact keeps the first contact (date and form, in the site's language), never the message" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'Starý formulář T328'")|$(sq "SELECT summary FROM ka_contacts WHERE id = ${T328_C3:-0}")" \
  "0|První kontakt $(php -r 'echo date("j. n. Y", strtotime($argv[1]));' "$(site_time '-20 months')") přes formulář „Starý formulář T328“."
T328_P=$(t328_php 'echo Kaleta\Core\Contacts::purge($app);')
expect "3.28 retention: the contact inactive for 12 months is anonymised – address, name, summary gone, its log without text, unlinked; the active ones stay" \
  "$([ "${T328_P:-0}" -ge 1 ] && echo 1)|$(sq "SELECT CONCAT(email, '|', email_norm IS NULL, '|', name, '|', summary IS NULL, '|', anonymised_at IS NOT NULL) FROM ka_contacts WHERE id = ${T328_C3:-0}")|$(sq "SELECT CONCAT(COUNT(*), '/', SUM(text = '')) FROM ka_contact_activities WHERE contact_id = ${T328_C3:-0}")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id IN (${T328_C1:-0}, ${T328_C4:-0}) AND anonymised_at IS NULL")" \
  "1||1||1|1|1/1|2"
# N328-7: the team's work counts as activity – a customer quiet for 30 months but with an open task due next week is kept
# (the task's title is not blanked) and its last activity moves, so it leaves the clean-up's batch
T328_BUSY=$(t328_php "echo Kaleta\\Core\\Contacts::create(\$app, ['email' => 'busy.t328@example.org', 'name' => 'Busy T328'], $T328_ADMIN);")
sq "UPDATE ka_contacts SET created_at = '$(site_time '-30 months')', first_seen_at = '$(site_time '-30 months')', last_activity_at = NULL WHERE id = ${T328_BUSY:-0}" > /dev/null
t328_php "Kaleta\\Core\\Tasks::create(\$app, ['title' => 'Call Busy T328 back', 'target' => 'contact:${T328_BUSY:-0}', 'assignee_id' => $T328_ADMIN, 'due_at' => '+7 days'], $T328_ADMIN);" > /dev/null
t328_php 'Kaleta\Core\Contacts::purge($app);' > /dev/null
expect "3.28 (N328-7): a customer with an open task is not anonymised by the retention, the task keeps its title, the last activity moves" \
  "$(sq "SELECT CONCAT(anonymised_at IS NULL, '|', name, '|', last_activity_at IS NOT NULL) FROM ka_contacts WHERE id = ${T328_BUSY:-0}")|$(sq "SELECT title FROM ka_tasks WHERE target = 'contact:${T328_BUSY:-0}'")" "1|Busy T328|1|Call Busy T328 back"
sq "DELETE FROM ka_tasks WHERE target = 'contact:${T328_BUSY:-0}'; DELETE FROM ka_contacts WHERE id = ${T328_BUSY:-0}" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24'), ('contacts_months', '24')" > /dev/null
check "3.28: the record of processing names customer relations" 200 "/admin.php?module=settings&action=processing_record" "Péče o zákazníky"
# N328-5: an uploaded customers CSV nobody confirmed (personal data) goes with the daily clean-up after a day, not only when
# Import is opened again; a fresh one stays for its preview
mkdir -p "$WORK/web/storage/import"; echo '{"user":1,"name":"x.csv","rows":[]}' > "$WORK/web/storage/import/contacts-aaaaaaaaaaaaaaaaaaaaaaaa.json"
cp "$WORK/web/storage/import/contacts-aaaaaaaaaaaaaaaaaaaaaaaa.json" "$WORK/web/storage/import/contacts-bbbbbbbbbbbbbbbbbbbbbbbb.json"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK/web/storage/import/contacts-aaaaaaaaaaaaaaaaaaaaaaaa.json"
t328_php 'echo Kaleta\Admin\Modules\Contacts::sweepImports();' > /dev/null # what the daily clean-up calls (unit test)
expect "3.28 (N328-5): the daily clean-up removes an unconfirmed import older than a day, keeps a fresh one" \
  "$([ -f "$WORK/web/storage/import/contacts-aaaaaaaaaaaaaaaaaaaaaaaa.json" ] && echo kept || echo gone)/$([ -f "$WORK/web/storage/import/contacts-bbbbbbbbbbbbbbbbbbbbbbbb.json" ] && echo kept || echo gone)" "gone/kept"
rm -f "$WORK/web/storage/import/contacts-bbbbbbbbbbbbbbbbbbbbbbbb.json"

# erasure on request: the contact with its log, comments and tasks goes with the enquiries
mcp_as "$T328_TA" find_personal_data '{"email":"marta.t328@firma-t328.cz"}' > "$WORK/response"; mcp_text
expect "3.28 find_personal_data: the customer record is found (counts only)" "$(t328_json found.contacts)|$(grep -c 'Prefers mornings' "$WORK/text")" "1|0"
check "3.28: the personal data request screen" 200 "/admin.php?module=enquiries&action=personal" 'name="email"'
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d email=marta.t328@firma-t328.cz
expect "3.28: … lists the customer record with its link" "$(grep -c "module=contacts&amp;action=detail&amp;id=${T328_C1:-0}\"" "$WORK/response")" "1"
mcp_as "$T328_TA" erase_personal_data '{"email":"marta.t328@firma-t328.cz","confirm":true}' > /dev/null
expect "3.28 erase_personal_data: the contact, its log, comments and tasks are gone; the company stays" \
  "$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = ${T328_C1:-0}")|$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE contact_id = ${T328_C1:-0}")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'contact:${T328_C1:-0}'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'contact:${T328_C1:-0}'")|$(sq "SELECT COUNT(*) FROM ka_companies WHERE id = $T328_CO")" \
  "0|0|0|0|1"
# N328-6: an address merged into another contact (a contact added by hand, no records of its own) is still found – by the
# merge's note – and erasing it takes the contact it went into
T328_SOLO=$(t328_php "echo Kaleta\\Core\\Contacts::create(\$app, ['email' => 'solo.t328@example.org', 'name' => 'Solo T328'], $T328_ADMIN);")
T328_INTO=$(t328_php "echo Kaleta\\Core\\Contacts::create(\$app, ['email' => 'into.t328@example.org'], $T328_ADMIN);")
t328_php "Kaleta\\Core\\Contacts::merge(\$app, ${T328_INTO:-0}, ${T328_SOLO:-0}, $T328_ADMIN);" > /dev/null
mcp_as "$T328_TA" find_personal_data '{"email":"solo.t328@example.org"}' > "$WORK/response"; mcp_text
T328_SOLO_FOUND=$(t328_json found.contacts)
mcp_as "$T328_TA" erase_personal_data '{"email":"solo.t328@example.org","confirm":true}' > /dev/null
expect "3.28 (N328-6): an address merged into another contact is found by the merge's note, and erased with it" "$T328_SOLO_FOUND|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id IN (${T328_SOLO:-0}, ${T328_INTO:-0})")|$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE text LIKE '%solo.t328@%'")" "1|0|0"
sq "DELETE FROM ka_contact_activities WHERE contact_id IN (SELECT id FROM ka_contacts WHERE name LIKE '%T328%' OR email LIKE '%t328%'); DELETE FROM ka_contacts WHERE name LIKE '%T328%' OR email LIKE '%t328%' OR id = ${T328_C3:-0};
  DELETE FROM ka_companies WHERE name LIKE '%T328%'; DELETE FROM ka_poptavky WHERE formular = 'Bez adresy T328' OR email LIKE '%t328%'; DELETE FROM ka_odberatele WHERE email LIKE '%t328%';
  DELETE FROM ka_api_tokeny WHERE nazev LIKE 't328-%'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($T328_JANA, $T328_PETR, $T328_EVA); DELETE FROM ka_uzivatele WHERE user LIKE 't328-%'" > /dev/null


