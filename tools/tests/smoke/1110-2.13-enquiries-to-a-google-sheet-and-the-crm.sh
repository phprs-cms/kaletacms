echo "== 2.13: enquiries to a Google sheet and the CRM (HubSpot, Pipedrive, Raynet)"
# a page with a full form (name, e-mail, phone, message, consent) – the contact form was re-pointed by the tests above
mcp create_page '{"title":"Poptavka CRM","visible":true}' > /dev/null; PAGE_CRM=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'poptavka-crm'")
mcp stavba_uloz "{\"id\":$PAGE_CRM,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka CRM\",\"pole\":[{\"popisek\":\"Jméno a příjmení\",\"typ\":\"text\",\"povinne\":true},{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true},{\"popisek\":\"Telefon\",\"typ\":\"tel\"},{\"popisek\":\"Zpráva\",\"typ\":\"textarea\"},{\"popisek\":\"Souhlas\",\"typ\":\"souhlas\",\"povinne\":true}]}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/poptavka-crm"
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
[ -n "$FORM_ELEMENT" ] && echo "  ok     enquiries: the test form with every field type the mapping uses is on its page" || { echo "  CHYBA  test form page"; ERRORS=$((ERRORS+1)); }
# crm_submit: that form; the per-IP limit of the form tests above is cleared first
crm_submit() { sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null; submit_form -d zpet=/poptavka-crm "$@"; }
connect_fake google
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
grep -q 'name="config\[enquiries\]" value="1"' "$WORK/response" && grep -q 'action=sheet' "$WORK/response" && grep -q 'name="config\[domain\]"' "$WORK/response" && grep -q 'name="config\[instance\]"' "$WORK/response" && grep -q 'name="config\[enquiry_jobs\]"' "$WORK/response" \
  && echo "  ok     enquiries: Connections offers the switch and the job-applications tick for every destination, Create the sheet for Google, the Pipedrive domain and the Raynet instance" || { echo "  CHYBA  Connections screen"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=google -d client_id=test-client -d "config[enquiries]=1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=hubspot --data-urlencode secret=hs-token -d "config[enquiries]=1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=pipedrive --data-urlencode secret=pd-token -d "config[domain]=acme" -d "config[enquiries]=1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=raynet --data-urlencode account=user@example.cz --data-urlencode secret=rn-key -d "config[instance]=acme-crm" -d "config[enquiries]=1"
expect "enquiries: the CRMs are connected by their keys, Google by the sign-in, the switch is on everywhere, the keys are encrypted" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(service, '=', connected_at IS NOT NULL, JSON_UNQUOTE(JSON_EXTRACT(config, '\$.enquiries')), secret LIKE '%-token%' OR secret LIKE '%rn-key%') ORDER BY service SEPARATOR ',') FROM ka_connectors")" "google=110,hubspot=110,pipedrive=110,raynet=110"
check "enquiries: the switch is on but the sheet is missing – the screen says so" 200 "/admin.php?module=connectors" "Nejdřív vytvořte tabulku"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=sheet" -d "_csrf=$(csrf)"
expect "enquiries: Create the sheet made the spreadsheet with the Google sign-in and kept its id with the settings" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(config, '\$.sheet_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(config, '\$.enquiries'))) FROM ka_connectors WHERE service = 'google'")" "sheet-test-1|1"
grep -q '"call":"create"' "$FAKE_LOGS-sheets.log" && grep -q '"title":"'"$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")"' – poptávky"' "$FAKE_LOGS-sheets.log" && grep -q '"stringValue":"Datum"' "$FAKE_LOGS-sheets.log" && grep -q '"stringValue":"E-mail"' "$FAKE_LOGS-sheets.log" && grep -q '"authorization":"Bearer access-1"' "$FAKE_LOGS-sheets.log" \
  && echo "  ok     enquiries: the sheet is named after the site and has a header row; the call carried the OAuth token" || { echo "  CHYBA  sheet create"; cat "$FAKE_LOGS-sheets.log"; ERRORS=$((ERRORS+1)); }
check "enquiries: Connections links the sheet" 200 "/admin.php?module=connectors" "https://docs.google.com/spreadsheets/d/sheet-test-1"
QID0=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_connector_queue")
sleep 4
# the visit trigger of background jobs runs at most once a minute – mark it as just run, so the form's redirect visit
# cannot deliver the queue and only the cron call below does
background_jobs resume
location=$(crm_submit --data-urlencode "p0=Karel Novák" --data-urlencode p1=karel@example.cz --data-urlencode "p2=+420 777 123 456" --data-urlencode "p3=Chci novou kuchyň." -d p4=1)
case "$location" in *result=ok*) echo "  ok     enquiries: the form was sent";; *) echo "  CHYBA  form: $location"; ERRORS=$((ERRORS+1));; esac
CRM_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky"); FORM_NAME=$(sq "SELECT formular FROM ka_poptavky WHERE idp = $CRM_IDP")
expect "enquiries: one delivery per destination waits in the queue – nothing went out while the visitor waited" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(action ORDER BY action), '|', SUM(delivered_at IS NULL), '|', SUM(payload LIKE '%\"enquiry\":$CRM_IDP,%')) FROM ka_connector_queue WHERE id > $QID0")|$([ -f "$FAKE_LOGS-crm.log" ] && grep -c karel "$FAKE_LOGS-crm.log" || echo 0)" "4|crm.lead,crm.lead,crm.lead,sheets.append|4|4|0"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "connectors: delivered 4" "$WORK/tasks.txt" && echo "  ok     enquiries: the connectors job delivered the four" || { echo "  CHYBA  connectors job"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "enquiries: delivered rows lose their payload (personal data), no error stays" "$(sq "SELECT CONCAT(SUM(delivered_at IS NOT NULL), '|', SUM(payload IS NULL), '|', SUM(last_error = '')) FROM ka_connector_queue WHERE id > $QID0")" "4|4|4"
grep -q '"call":"append","sheet":"sheet-test-1","range":"A1","query":{"valueInputOption":"RAW","insertDataOption":"INSERT_ROWS"},"authorization":"Bearer access-1"' "$FAKE_LOGS-sheets.log" \
  && grep -q '"values":\[\["[0-9-]* [0-9:]*","'"$FORM_NAME"'","[^"]*","karel@example.cz","'"$B"'/poptavka-crm","Karel Novák","+420 777 123 456","[^"]*: Chci novou kuchyň."\]\]' "$FAKE_LOGS-sheets.log" \
  && echo "  ok     enquiries: the sheet got one row – date, form, topic, e-mail, page, name, phone, then the message" || { echo "  CHYBA  sheet row"; grep append "$FAKE_LOGS-sheets.log"; ERRORS=$((ERRORS+1)); }
grep -q '"crm":"hubspot","method":"POST","path":"/crm/v3/objects/contacts/search","authorization":"Bearer hs-token".*"value":"karel@example.cz"' "$FAKE_LOGS-crm.log" \
  && grep -q '"method":"POST","path":"/crm/v3/objects/contacts",.*"properties":{"email":"karel@example.cz","firstname":"Karel","lastname":"Novák","phone":"+420 777 123 456"}' "$FAKE_LOGS-crm.log" \
  && grep -q '"path":"/crm/v3/objects/notes",.*"hs_note_body":"[^"]*Chci novou kuchyň.*"to":{"id":"777"}.*"associationTypeId":202' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: HubSpot – the contact searched by e-mail, created with name and phone, the note with the message associated to it" || { echo "  CHYBA  HubSpot calls"; grep hubspot "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
grep -q '"crm":"pipedrive","method":"GET","path":"/api/v1/persons/search","api_token":"pd-token","query":{"term":"karel@example.cz","fields":"email","exact_match":"true","limit":"1"}' "$FAKE_LOGS-crm.log" \
  && grep -q '"path":"/api/v1/persons","api_token":"pd-token".*"name":"Karel Novák","email":\[{"value":"karel@example.cz","primary":true}\],"phone":\[{"value":"+420 777 123 456","primary":true}\]' "$FAKE_LOGS-crm.log" \
  && grep -q '"path":"/api/v1/leads".*"title":"'"$FORM_NAME"'[^"]*","person_id":42' "$FAKE_LOGS-crm.log" && grep -q '"path":"/api/v1/notes".*"lead_id":"lead-uuid-1","person_id":42' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: Pipedrive – the token as api_token, the person found or created, the lead titled after the form, the note" || { echo "  CHYBA  Pipedrive calls"; grep pipedrive "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
grep -q '"crm":"raynet","method":"PUT","path":"/api/v2/lead/","user":"user@example.cz","key_ok":true,"instance":"acme-crm".*"topic":"'"$FORM_NAME"'[^"]*","firstName":"Karel","lastName":"Novák","contactInfo":{"email":"karel@example.cz","tel1":"+420 777 123 456"},"notice":"[^"]*Chci novou kuchyň' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: Raynet – HTTP Basic with the instance header, the lead with the contact and the message" || { echo "  CHYBA  Raynet call"; grep raynet "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
expect "enquiries: every CRM call is in the log by its action, never with the content" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(ok), '|', SUM(action LIKE 'crm.%'), '|', SUM(error LIKE '%karel%' OR error LIKE '%token%')) FROM ka_connector_log WHERE service IN ('hubspot', 'pipedrive', 'raynet')")" "8|8|8|0"
# a sender the CRM already knows: HubSpot updates the contact, Pipedrive reuses the person
QID1=$(sq "SELECT MAX(id) FROM ka_connector_queue")
crm_submit --data-urlencode "p0=Known Person" --data-urlencode p1=known@example.cz -d p2= -d p3=again -d p4=1 > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
grep -q '"method":"PATCH","path":"/crm/v3/objects/contacts/501"' "$FAKE_LOGS-crm.log" && grep -q '"path":"/crm/v3/objects/notes".*"to":{"id":"501"}' "$FAKE_LOGS-crm.log" \
  && [ "$(grep -c '"path":"/api/v1/persons",' "$FAKE_LOGS-crm.log")" = 1 ] && grep -q '"path":"/api/v1/leads".*"person_id":31' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: a known sender – HubSpot updates the contact and notes it, Pipedrive adds the lead to the existing person" || { echo "  CHYBA  known contact"; grep 'known\|501\|person_id' "$FAKE_LOGS-crm.log" | tail -6; ERRORS=$((ERRORS+1)); }
expect "enquiries: the known sender's deliveries went through" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(delivered_at IS NOT NULL)) FROM ka_connector_queue WHERE id > $QID1")" "4|4"
# a job application (an enquiry from a jobs collection, 2.11) is not sent unless the administrator ticks it – and then without the CV
[ -n "${JOB_ELEMENT:-}" ] || { curl -s -o "$WORK/job.html" "$B/volna-mista/truhlar"; JOB_SOURCE=$(job_field zdroj); JOB_ELEMENT=$(job_field prvek); JOB_TIME=$(job_field as_cas); JOB_SIGNATURE=$(job_field as_podpis); sleep 4; }
printf '%%PDF-1.4 test CV\n' > "$WORK/cv.pdf"
submit_application() { sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null; curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=$JOB_SOURCE" -F "prvek=$JOB_ELEMENT" -F zpet=/volna-mista/truhlar -F "as_cas=$JOB_TIME" -F "as_podpis=$JOB_SIGNATURE" \
  -F p0=Petr -F "p1=$1" -F p2= -F "p3=@$WORK/cv.pdf" --form-string "p4=Hlásím se." -F p5=1 --form-string "p6=Truhlář"; }
QID2=$(sq "SELECT MAX(id) FROM ka_connector_queue")
case "$(submit_application f13-applicant@example.cz)" in *result=ok*) echo "  ok     enquiries: an application with a CV was sent";; *) echo "  CHYBA  application"; ERRORS=$((ERRORS+1));; esac
expect "enquiries: the application is stored but goes to no CRM and no sheet by default" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE email = 'f13-applicant@example.cz'), '|', (SELECT COUNT(*) FROM ka_connector_queue WHERE id > $QID2))")" "1|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=raynet --data-urlencode account=user@example.cz -d "config[instance]=acme-crm" -d "config[enquiries]=1" -d "config[enquiry_jobs]=1"
submit_application petra@example.cz > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "enquiries: with the tick only that destination gets the application; the key saved before stays" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(action), '|', SUM(delivered_at IS NOT NULL), '|', (SELECT connected_at IS NOT NULL FROM ka_connectors WHERE service = 'raynet')) FROM ka_connector_queue WHERE id > $QID2")" "1|crm.lead|1|1"
grep -q '"crm":"raynet".*petra@example.cz.*cv.pdf' "$FAKE_LOGS-crm.log" && ! grep -q 'storage/prilohy\|[0-9]\{4\}/[0-9]\{2\}/[a-f0-9]\{24\}\.pdf' "$FAKE_LOGS-crm.log" \
  && echo "  ok     enquiries: the application reached Raynet with the CV's name only, never the file or its path" || { echo "  CHYBA  application in the CRM"; grep petra "$FAKE_LOGS-crm.log"; ERRORS=$((ERRORS+1)); }
# a CRM that fails: the deliveries wait for a retry with the error, the screen shows it, the retry clears it
touch "$FAKE_LOGS-crm.fail"; QID3=$(sq "SELECT MAX(id) FROM ka_connector_queue")
crm_submit -d p0=Failing --data-urlencode p1=fail@example.cz -d p2= -d p3=x -d p4=1 > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "enquiries: the CRMs answer 500 – their deliveries wait for a retry with the error, the sheet row went through" \
  "$(sq "SELECT CONCAT(SUM(action = 'crm.lead' AND attempts = 1 AND next_attempt IS NOT NULL AND delivered_at IS NULL AND last_error LIKE 'HTTP 500%'), '|', SUM(action = 'sheets.append' AND delivered_at IS NOT NULL)) FROM ka_connector_queue WHERE id > $QID3")" "3|1"
expect "enquiries: each CRM keeps its last error for the Connections screen" "$(sq "SELECT GROUP_CONCAT(CONCAT(service, ':', last_error LIKE 'HTTP 500%') ORDER BY service) FROM ka_connectors WHERE service IN ('google', 'hubspot', 'pipedrive', 'raynet')")" "google:0,hubspot:1,pipedrive:1,raynet:1"
check "enquiries: Connections shows the error" 200 "/admin.php?module=connectors" "HTTP 500: The fake CRM is broken."
rm -f "$FAKE_LOGS-crm.fail"; sq "UPDATE ka_connector_queue SET next_attempt = '$(site_time)' - INTERVAL 1 DAY WHERE id > $QID3 AND delivered_at IS NULL" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "enquiries: the retry delivers and clears the errors" "$(sq "SELECT CONCAT(SUM(delivered_at IS NOT NULL AND last_error = ''), '|', (SELECT SUM(last_error = '') FROM ka_connectors WHERE service IN ('google', 'hubspot', 'pipedrive', 'raynet'))) FROM ka_connector_queue WHERE id > $QID3")" "4|4"
# disconnecting stops the sending
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=hubspot
QID4=$(sq "SELECT MAX(id) FROM ka_connector_queue")
crm_submit -d p0=After --data-urlencode p1=after@example.cz -d p2= -d p3=x -d p4=1 > /dev/null
expect "enquiries: a disconnected CRM gets nothing more, the others still do; disconnecting forgot its key" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(payload LIKE '%\"service\":\"hubspot\"%'), '|', (SELECT secret IS NULL FROM ka_connectors WHERE service = 'hubspot')) FROM ka_connector_queue WHERE id > $QID4")" "3|0|1"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
mcp list_connectors '{}' > "$WORK/response"
contains -q 'raynet' "$WORK/response" && contains -q 'pipedrive' "$WORK/response" && ! contains -q 'hs-token\|pd-token\|rn-key\|sheet_id' "$WORK/response" && echo "  ok     enquiries: Claude sees the CRMs' status, never a key or the settings" || { echo "  CHYBA  list_connectors with CRMs"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
