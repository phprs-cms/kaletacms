echo "== 3.32 pre-release review"
r332_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
R332_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
# r332_enq <name> <company> <e-mail> <message> [zdroj] [tema] – an enquiry as a form stores it
r332_enq() { local data; data=$(php -r 'echo json_encode([["Jméno", $argv[1]], ["Firma", $argv[2]], ["E-mail", $argv[3]], ["Zpráva", $argv[4]]], JSON_UNESCAPED_UNICODE);' "$1" "$2" "$3" "$4")
  sq "INSERT INTO ka_poptavky (datum, formular, zdroj, tema, email, data, stav) VALUES ('$(site_time)', 'Kontakt', '${5:-stranka:1}', '${6:-Kontakt}', '$3', '$data', 1); SELECT LAST_INSERT_ID();"; }
# r332_deal <idp> [title] – a deal from the enquiry: the generated title (createFromEnquiry) or the one given
r332_deal() { if [ -z "${2:-}" ]; then r332_php "echo Kaleta\\Core\\Deals::createFromEnquiry(\$app, $1, $R332_ADMIN);"; else
  sq "INSERT INTO ka_deals (title, stage, source_enquiry_id, created_at, updated_at) VALUES ('$2', 'new', $1, '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();"; fi; }
r332_title() { sq "SELECT CONCAT(title, '|', IFNULL(source_enquiry_id, 'null')) FROM ka_deals WHERE id = ${1:-0}"; }
# item 1: a deal from an enquiry loses the person's words on every path that deletes or anonymises the enquiry
R332_E1=$(r332_enq "Olivia Hart R332" "Hartsteel R332" "olivia-r332@example.com" "We need forty custom brackets for a new hall in March"); R332_D1=$(r332_deal "$R332_E1")
R332_E2=$(r332_enq "Ben Stone R332" "Stoneworks R332" "ben-r332@example.com" "Roof repair before the winter please"); R332_D2=$(r332_deal "$R332_E2" "Stoneworks R332 – roof")
R332_E3=$(r332_enq "Cleo Park R332" "" "cleo-r332@example.com" "Three gates for the yard and a fence" "kolekce:1" "Gate G-3 R332"); R332_D3=$(r332_deal "$R332_E3" "Quote: three gates for the yard and")
R332_E8=$(r332_enq "Hugo Lane R332" "" "hugo-r332@example.com" "Window cleaning every month"); R332_D8=$(r332_deal "$R332_E8" "Windows yearly R332")
expect "3.32 deal from an enquiry: the generated title names the person and the message" "$(r332_title "$R332_D1")" "Olivia Hart R332 – We need forty custom brackets for…|$R332_E1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$R332_E1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=delete" -d "_csrf=$(csrf)" -d "idp=$R332_E1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=bulk" -d "_csrf=$(csrf)" -d provest=smazat -d "oznacene[]=$R332_E2" -d "oznacene[]=$R332_E8"
mcp delete_enquiry "{\"id\":$R332_E3}" > /dev/null
expect "3.32 deal titles: Delete (generated title), bulk delete (company), MCP delete_enquiry (message words; a product topic stays) – #<id> with the topic; a neutral title stays" \
  "$(r332_title "$R332_D1")/$(r332_title "$R332_D2")/$(r332_title "$R332_D3")/$(r332_title "$R332_D8")" \
  "#$R332_D1 – Kontakt|null/#$R332_D2 – Kontakt|null/#$R332_D3 – Gate G-3 R332|null/Windows yearly R332|null"
# the retention: deleted, and anonymised (the link stays); the admin's Anonymise; erasure on request (with the contact)
R332_E4=$(r332_enq "Dana Wolf R332" "" "dana-r332@example.com" "Garden design for a small yard"); R332_D4=$(r332_deal "$R332_E4")
R332_E5=$(r332_enq "Eli Moss R332" "" "eli-r332@example.com" "Painting the office in August"); R332_D5=$(r332_deal "$R332_E5" "Call eli-r332@example.com")
sq "UPDATE ka_poptavky SET datum = '$(site_time '-40 months')' WHERE idp = $R332_E5; UPDATE ka_deals SET closed_at = '$(site_time '-40 months')', stage = 'lost', lost_reason = 'timing' WHERE id IN ($R332_D4, $R332_D5);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24'), ('enquiries_expiry', 'anonymise')" > /dev/null
r332_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());'
R332_A5="$(sq "SELECT anonymizovano IS NOT NULL FROM ka_poptavky WHERE idp = $R332_E5")|$(r332_title "$R332_D5")|$(r332_title "$R332_D4")"
sq "UPDATE ka_poptavky SET datum = '$(site_time '-40 months')' WHERE idp = $R332_E4; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'delete')" > /dev/null
r332_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());'
expect "3.32 deal titles: the retention – anonymised (the link stays; a younger enquiry's deal untouched) and deleted – leaves no name, address or message words" \
  "$R332_A5/$(r332_title "$R332_D4")" "1|#$R332_D5 – Kontakt|$R332_E5|Dana Wolf R332 – Garden design for a small yard|$R332_E4/#$R332_D4 – Kontakt|null"
R332_E6=$(r332_enq "Finn Rae R332" "" "finn-r332@example.com" "New kitchen cabinets in oak"); R332_D6=$(r332_deal "$R332_E6")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$R332_E6"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$(csrf)" -d "idp=$R332_E6"
R332_E7=$(r332_enq "Gina Hale R332" "Hale Bakery R332" "gina-r332@example.com" "Ovens for the bakery next spring"); R332_D7=$(r332_deal "$R332_E7")
mcp erase_personal_data '{"email":"gina-r332@example.com","confirm":true}' > /dev/null
expect "3.32 deal titles: the admin's Anonymise (the link stays) and erase_personal_data (with its customer) retitle the deal" \
  "$(r332_title "$R332_D6")/$(r332_title "$R332_D7")" "#$R332_D6 – Kontakt|$R332_E6/#$R332_D7 – Kontakt|null"
expect "3.32 deal titles: nothing of the old titles was written anywhere else (change log, history)" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%R332%'")" "0"
# item 2: a form notification names the visitor (name or company, never the address) only in the e-mail – the mail log, its
# retries and the mail.failed event keep "<form>: <site>"; a queued message keeps the full subject in its body until sent
sq "DROP TABLE IF EXISTS ka_r332_mail; CREATE TABLE ka_r332_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '1'), ('smtp_encryption', 'zadne'), ('smtp_user', ''); DELETE FROM ka_kontrola_ip" > /dev/null
R332_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
submit_form --data-urlencode "p0=Quinn Ray R332" --data-urlencode p1=quinn-r332@example.com -d p2=777332332 --data-urlencode "p3=Please call me about the offer" -d p4=1 > /dev/null
expect "3.32 form notification: the mail log keeps the subject without the person; the queued body holds the full one (name, never the address)" \
  "$(sq "SELECT CONCAT(SUM(predmet LIKE '%Quinn%' OR predmet LIKE '%quinn-r332%'), '|', SUM(telo LIKE '%\"predmet\":\"%: % – Quinn Ray R332\"%'), '|', SUM(telo LIKE '%\"predmet\":\"%quinn-r332@%')) FROM ka_posta WHERE idp > $R332_P0")" "0|1|0"
sq "UPDATE ka_posta SET pokusu = 4, dalsi_pokus = '$(site_time '-1 minute')' WHERE idp > $R332_P0 AND odeslano IS NULL AND telo IS NOT NULL" > /dev/null
R332_EV0=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_events")
r332_php 'Kaleta\Core\Mail::processQueue($app->settings(), 50);' > /dev/null
expect "3.32 mail.failed: the event names the logged subject, never the visitor" \
  "$(sq "SELECT CONCAT(COUNT(*) > 0, '|', SUM(message LIKE '%Quinn%')) FROM ka_events WHERE type = 'mail.failed' AND id > $R332_EV0")" "1|0"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user'); INSERT INTO ka_nastaveni SELECT * FROM ka_r332_mail; DROP TABLE ka_r332_mail;
  DELETE FROM ka_posta WHERE idp > $R332_P0" > /dev/null
# item 3: the automatic currency keeps what a site uses – the company country, else the currency most of its deals carry,
# else the site language (the time zone is no evidence: the installer sets it from its language)
sq "DROP TABLE IF EXISTS ka_r332_cur; CREATE TABLE ka_r332_cur AS SELECT * FROM ka_nastaveni WHERE promenna IN ('company_country', 'default_currency', 'time_zone');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_country', ''), ('default_currency', ''), ('time_zone', 'Europe/London')" > /dev/null
R332_N=$(sq "SELECT COUNT(*) FROM ka_deals WHERE currency IS NOT NULL")
for i in $(seq 0 "$R332_N"); do sq "INSERT INTO ka_deals (title, stage, value, currency, created_at, updated_at) VALUES ('Currency R332', 'new', 100, 'GBP', '$(site_time)', '$(site_time)')" > /dev/null; done
R332_C1=$(r332_php 'echo Kaleta\Core\Deals::defaultCurrency($app->settings());')
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_country', 'SE')" > /dev/null
R332_C2=$(r332_php 'echo Kaleta\Core\Deals::defaultCurrency($app->settings());')
sq "DELETE FROM ka_deals WHERE title = 'Currency R332'; DELETE FROM ka_nastaveni WHERE promenna IN ('company_country', 'default_currency', 'time_zone'); INSERT INTO ka_nastaveni SELECT * FROM ka_r332_cur; DROP TABLE ka_r332_cur" > /dev/null
expect "3.32 currency: automatic = the currency most deals use (not the London time zone), the company country before it" "$R332_C1|$R332_C2" "GBP|SEK"
# item 4: My work lists an unassigned, unanswered enquiry while it is new or from the last 30 days – MCP my_work alike
R332_W1=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time '-40 days')', 'Kontakt', 'w1-r332@example.com', '[]', 1); SELECT LAST_INSERT_ID();")
R332_W2=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time '-40 days')', 'Kontakt', 'w2-r332@example.com', '[]', 0); SELECT LAST_INSERT_ID();")
R332_W3=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time '-5 days')', 'Kontakt', 'w3-r332@example.com', '[]', 1); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work"
R332_UN=$(awk '/data-list="unassigned"/{u=1} /<\/section>/{u=0} u' "$WORK/response")
mcp my_work '{}' > "$WORK/response"; mcp_text
expect "3.32 My work: an opened enquiry from 40 days ago is not listed; a new one that old and an opened recent one are – in the admin and in MCP my_work" \
  "$(echo "$R332_UN" | grep -c "data-enquiry=\"$R332_W1\"")|$(echo "$R332_UN" | grep -c "data-enquiry=\"$R332_W2\"")|$(echo "$R332_UN" | grep -c "data-enquiry=\"$R332_W3\"")|$(php -r '$ids = array_column(json_decode(file_get_contents($argv[1]), true)["enquiries_unassigned"] ?? [], "id"); echo implode(",", array_map(fn ($i) => (int) in_array((int) $i, $ids, true), array_slice($argv, 2)));' "$WORK/text" "$R332_W1" "$R332_W2" "$R332_W3")" \
  "0|1|1|0,1,1"
sq "DELETE FROM ka_poptavky WHERE idp IN ($R332_W1, $R332_W2, $R332_W3)" > /dev/null
# item 5: Create deal from an enquiry not linked to a customer yet – the form's "the enquiry's customer" (0) keeps the customer
# linked when the deal is made; "—" (none) leaves the deal without one
R332_F1=$(r332_enq "Ivy North R332" "" "ivy-r332@example.com" "A new fence for the garden"); R332_F2=$(r332_enq "Jon West R332" "" "jon-r332@example.com" "Paving the driveway")
sq "UPDATE ka_poptavky SET contact_id = NULL WHERE idp IN ($R332_F1, $R332_F2)" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pipeline&action=new&enquiry=$R332_F1"
expect "3.32 Create deal: the form offers the enquiry's customer (0, chosen) apart from — (none)" \
  "$(grep -c '<option value="0" selected>Zákazník z poptávky</option><option value="none">—</option>' "$WORK/response")|$(grep -c '<option value="0" selected>Firma zákazníka</option><option value="none">—</option>' "$WORK/response")" "1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$(csrf)" -d id=0 -d "enquiry=$R332_F1" --data-urlencode "title=Fence R332" -d contact_id=0 -d company_id=0 -d value= -d currency= -d expected_close= -d stage=new -d owner_id=0
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pipeline&action=new&enquiry=$R332_F2"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$(csrf)" -d id=0 -d "enquiry=$R332_F2" --data-urlencode "title=Paving R332" -d contact_id=none -d company_id=none -d value= -d currency= -d expected_close= -d stage=new -d owner_id=0
expect "3.32 Create deal: 0 keeps the customer the enquiry is linked to now; none leaves the deal without one" \
  "$(sq "SELECT CONCAT(d.contact_id IS NOT NULL, '|', d.contact_id <=> p.contact_id) FROM ka_deals d JOIN ka_poptavky p ON p.idp = d.source_enquiry_id WHERE d.source_enquiry_id = $R332_F1")|$(sq "SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE source_enquiry_id = $R332_F2")" "1|1|null"
# item 7: System status warns when the daily clean-up has not succeeded for more than 2 days; the dashboard leaves spam out of
# its latest enquiries, as the enquiry list does
sq "INSERT INTO ka_jobs (name, last_run, last_ok, last_error, failures, runs, duration_ms) VALUES ('cleanup', '$(site_time)', '$(site_time '-3 days')', '', 0, 1, 0) ON DUPLICATE KEY UPDATE last_ok = VALUES(last_ok)" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"; R332_S1=$(grep -q 'Úklid osobních údajů' "$WORK/response" && echo 1 || echo 0) # named in the problems list and in its row
sq "UPDATE ka_jobs SET last_ok = '$(site_time '-1 hour')' WHERE name = 'cleanup'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"; R332_S2=$(grep -q 'Úklid osobních údajů' "$WORK/response" && echo 1 || echo 0) # named in the problems list and in its row
R332_SP=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, kategorie) VALUES ('$(site_time)', 'Kontakt', 'spam-r332@example.com', '[[\"Jméno\",\"Spammy Sender R332\"]]', 0, 'spam'); SELECT LAST_INSERT_ID();")
expect "3.32 System status: the clean-up 3 days without success warns, a recent one does not; the dashboard leaves spam out" \
  "$R332_S1|$R332_S2|$(curl -s -b "$JAR" "$B/admin.php" | grep -c 'Spammy Sender R332')" "1|0|0"
sq "DELETE FROM ka_poptavky WHERE idp = $R332_SP" > /dev/null
