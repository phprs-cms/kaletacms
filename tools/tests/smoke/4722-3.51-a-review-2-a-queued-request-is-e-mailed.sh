echo "== 3.51 A review (second round): a request the mail queue delivers later counts as e-mailed and its refuse link works; one that no e-mail exists for leaves nothing behind"
A722_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_a722_set; CREATE TABLE ka_a722_set AS SELECT * FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'site_email')" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_name', 'Firma A722 s.r.o.'), ('company_street', 'Krátká 2'), ('company_postcode', '602 00'), ('company_city', 'Brno'), ('company_country', 'CZ'), ('site_email', 'web-a722@example.com')" > /dev/null
features +pipeline
background_jobs hold # the mail queue must not retry while this section looks at it
A722_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'a722 admin', 'full', 'all', SHA2('$A722_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
a722_customer() { # <email>: a customer record with a won deal and an enquiry of it; prints the enquiry
  local contact; contact=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('$1', '$1', 'A722', 'manual', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
  sq "INSERT INTO ka_deals (title, contact_id, stage, closed_at, created_at, updated_at) VALUES ('Won A722', $contact, 'won', '$(site_time)', '$(site_time)', '$(site_time)')" > /dev/null
  sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, contact_id) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', '$1', '[]', 2, $contact); SELECT LAST_INSERT_ID();"
}
# the answer of request_testimonial as "sent|queued|reason|link"
a722_ask() { mcp_call "$A722_TOKEN" request_testimonial "{\"id\":$1,\"send\":true}" | php -r '$r = json_decode((string) file_get_contents("php://stdin"), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true);
  echo json_encode($t["sent"] ?? null), "|", json_encode($t["queued"] ?? null), "|", $t["reason"] ?? "-", "|", $t["link"] ?? "";'; }
a722_smtp_down() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('smtp_port', '1')" > /dev/null; } # nothing listens on port 1
a722_smtp_up() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES" > /dev/null; }

# the mail server does not answer: Mail::send() fails, the message waits in ka_posta – it counts as e-mailed
A722_QUEUED=$(a722_customer fronta-a722@example.com)
a722_smtp_down
A722_FIRST=$(a722_ask "$A722_QUEUED")
A722_PATH=$(printf '%s' "${A722_FIRST##*|}" | grep -o '/_testimonial/[a-f0-9]\{32\}' || true)
expect "3.51 A review (second round): with the mail server down the request answers sent + queued, records sent_at, the refuse line and its queue row, and its message waits in the mail queue with an expiry (a backup leaves its body out – third round)" \
  "${A722_FIRST%|*}|$(sq "SELECT CONCAT(COUNT(*), '/', SUM(sent_at IS NOT NULL), '/', SUM(refuse_hash IS NOT NULL)) FROM ka_testimonial_requests WHERE idp = $A722_QUEUED")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'fronta-a722@example.com' AND odeslano IS NULL AND telo LIKE '%refuse=%' AND telo LIKE '%\"expires\":%' AND dalsi_pokus IS NOT NULL AND chyba NOT LIKE '%sender address%'")|$(sq "SELECT mail_id = (SELECT MAX(idp) FROM ka_posta WHERE komu = 'fronta-a722@example.com') FROM ka_testimonial_requests WHERE idp = $A722_QUEUED")" \
  "true|true|-|1/1/1|1|1"
a722_smtp_up
expect "3.51 A review (second round): the queued request is the one e-mail of its enquiry – a second request says already_sent and queues nothing" \
  "$(a722_ask "$A722_QUEUED" | cut -d'|' -f1-3)|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'fronta-a722@example.com'")" "false|null|already_sent|1"
# the refuse code is only in the queued e-mail (JSON in ka_posta.telo), never in the link MCP returned
A722_CODE=$(sq "SELECT telo FROM ka_posta WHERE komu = 'fronta-a722@example.com'" | grep -o 'refuse=[a-f0-9]\{32\}' | head -1 | cut -d= -f2 || true)
if [ -n "$A722_PATH" ] && [ -n "$A722_CODE" ]; then
  curl -s -o "$WORK/a722.html" -X POST "$B$A722_PATH?refuse=$A722_CODE"
  A722_BLOCKED=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SendRegister::blocked($app->db(), "fronta-a722@example.com") ?? "none";')
  expect "3.51 A review (second round): the refuse link of the queued e-mail works – the customer clicking it is refused, not told the link is no longer valid" \
    "$(grep -c 'Žádosti ani novinky vám už posílat nebudeme' "$WORK/a722.html")|$(grep -c 'Tento odkaz už neplatí' "$WORK/a722.html")|$A722_BLOCKED" "1|0|refused"
else echo "  CHYBA  3.51 A review (second round): request_testimonial gave no link"; ERRORS=$((ERRORS+1)); fi

# not even the queue row can be written (the mail log is unavailable) and the server is down: no e-mail exists – nothing is
# recorded, and the next attempt may e-mail the enquiry
A722_LOST=$(a722_customer ztracena-a722@example.com)
a722_smtp_down
sq "RENAME TABLE ka_posta TO ka_posta_a722" > /dev/null
A722_FAIL=$(a722_ask "$A722_LOST")
sq "RENAME TABLE ka_posta_a722 TO ka_posta" > /dev/null
a722_smtp_up
A722_FAIL_STATE="$(sq "SELECT CONCAT(COUNT(*), '/', SUM(sent_at IS NULL), '/', SUM(refuse_hash IS NULL)) FROM ka_testimonial_requests WHERE idp = $A722_LOST")"
A722_RETRY=$(a722_ask "$A722_LOST")
expect "3.51 A review (second round): no queue row and no delivery – sent false, no reason, the request stays unsent and refuses nothing; the next request e-mails the enquiry once" \
  "$(printf '%s' "$A722_FAIL" | cut -d'|' -f1-3)|$A722_FAIL_STATE|$(printf '%s' "$A722_RETRY" | cut -d'|' -f1-3)|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'ztracena-a722@example.com' AND odeslano IS NOT NULL")|$(sq "SELECT SUM(sent_at IS NOT NULL) FROM ka_testimonial_requests WHERE idp = $A722_LOST")" \
  "false|null|-|1/1/1|true|null|-|1|1"

# the suite shares one database: what this section made goes, the settings come back
sq "DELETE FROM ka_posta WHERE komu LIKE '%-a722@example.com'; DELETE FROM ka_testimonial_requests WHERE idp IN ($A722_QUEUED, $A722_LOST); DELETE FROM ka_poptavky WHERE idp IN ($A722_QUEUED, $A722_LOST);
  DELETE FROM ka_deals WHERE title = 'Won A722'; DELETE FROM ka_contacts WHERE email LIKE '%-a722@example.com'; DELETE FROM ka_api_tokeny WHERE nazev = 'a722 admin'" > /dev/null
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); foreach (["fronta-a722@example.com", "ztracena-a722@example.com"] as $e) { foreach (["refused", "bounced"] as $k) { Kaleta\Core\SendRegister::lift($app->db(), $k, $e); } }')
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'site_email'); INSERT INTO ka_nastaveni SELECT * FROM ka_a722_set; DROP TABLE ka_a722_set" > /dev/null
features --exact "$A722_EXT"
background_jobs resume
rm -f "$WORK/a722.html"
