echo "== 3.51 A review (third round): a request the mail queue gives up on is no longer e-mailed; only the code in the e-mail refuses; a backup leaves the queued body out"
A724_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_a724_set; CREATE TABLE ka_a724_set AS SELECT * FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'site_email')" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_name', 'Firma A724 s.r.o.'), ('company_street', 'Krátká 4'), ('company_postcode', '602 00'), ('company_city', 'Brno'), ('company_country', 'CZ'), ('site_email', 'web-a724@example.com')" > /dev/null
features +pipeline
background_jobs hold # the mail queue must not retry while this section looks at it
A724_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'a724 admin', 'full', 'all', SHA2('$A724_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
a724_customer() { # <email>: a customer record with a won deal and an enquiry of it; prints the enquiry
  local contact; contact=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('$1', '$1', 'A724', 'manual', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
  sq "INSERT INTO ka_deals (title, contact_id, stage, closed_at, created_at, updated_at) VALUES ('Won A724', $contact, 'won', '$(site_time)', '$(site_time)', '$(site_time)')" > /dev/null
  sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, contact_id) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', '$1', '[]', 2, $contact); SELECT LAST_INSERT_ID();"
}
# the answer of request_testimonial as "sent|queued|reason|link"
a724_ask() { mcp_call "$A724_TOKEN" request_testimonial "{\"id\":$1,\"send\":true}" | php -r '$r = json_decode((string) file_get_contents("php://stdin"), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true);
  echo json_encode($t["sent"] ?? null), "|", json_encode($t["queued"] ?? null), "|", $t["reason"] ?? "-", "|", $t["link"] ?? "";'; }
a724_blocked() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SendRegister::blocked($app->db(), $argv[1]) ?? "none";' -- "$1"); }
a724_smtp_down() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('smtp_port', '1')" > /dev/null; } # nothing listens on port 1
a724_smtp_up() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES" > /dev/null; }

# the server is down and stays down: the request is queued; a backup row of it has no body; the queue's last retry fails
A724_GIVEN=$(a724_customer vzdana-a724@example.com)
a724_smtp_down
A724_FIRST=$(a724_ask "$A724_GIVEN")
A724_PATH=$(printf '%s' "${A724_FIRST##*|}" | grep -o '/_testimonial/[a-f0-9]\{32\}' || true)
A724_CODE=$(sq "SELECT telo FROM ka_posta WHERE komu = 'vzdana-a724@example.com'" | grep -o 'refuse=[a-f0-9]\{32\}' | head -1 | cut -d= -f2 || true)
A724_BACKUP=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $row = Kaleta\Core\Mail::forBackup((array) $app->db()->one("SELECT * FROM {posta} WHERE komu = ?", ["vzdana-a724@example.com"])); echo json_encode([$row["telo"], $row["dalsi_pokus"]]);')
sq "UPDATE ka_posta SET pokusu = 4, dalsi_pokus = '$(site_time '-1 minute')' WHERE komu = 'vzdana-a724@example.com'" > /dev/null
run_job mail > /dev/null
expect "3.51 A review (third round): queued with the server down (the e-mail's refuse code is not in the returned link, a backup leaves the body out); the queue's last retry fails – the request is no longer e-mailed and refuses nothing" \
  "${A724_FIRST%|*}|$(printf '%s' "${A724_FIRST##*|}" | grep -c 'refuse')|${#A724_CODE}|$A724_BACKUP|$(sq "SELECT CONCAT(odeslano IS NULL, '/', dalsi_pokus IS NULL, '/', telo IS NULL) FROM ka_posta WHERE komu = 'vzdana-a724@example.com'")|$(sq "SELECT CONCAT(COUNT(*), '/', SUM(sent_at IS NULL), '/', SUM(refuse_link = 0), '/', SUM(mail_id IS NULL)) FROM ka_testimonial_requests WHERE idp = $A724_GIVEN")" \
  "true|true|-|0|32|[null,null]|1/1/1|1/1/1/1"
if [ -n "$A724_PATH" ] && [ -n "$A724_CODE" ]; then
  curl -s -o "$WORK/a724.html" -X POST "$B$A724_PATH?refuse=$A724_CODE"
  expect "3.51 A review (third round): the refuse code of an e-mail that never went out refuses nothing" \
    "$(grep -c 'Tento odkaz už neplatí' "$WORK/a724.html")|$(a724_blocked vzdana-a724@example.com)" "1|none"
else echo "  CHYBA  3.51 A review (third round): no link or no refuse code in the queued e-mail"; ERRORS=$((ERRORS+1)); fi
# the server is back: the enquiry may be asked again, and this time it is delivered
a724_smtp_up
expect "3.51 A review (third round): after the queue gave up the enquiry is e-mailed once more when asked – delivered, nothing left in the queue" \
  "$(a724_ask "$A724_GIVEN" | cut -d'|' -f1-3)|$(sq "SELECT CONCAT(SUM(sent_at IS NOT NULL), '/', SUM(mail_id IS NOT NULL)) FROM ka_testimonial_requests WHERE idp = $A724_GIVEN")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'vzdana-a724@example.com' AND odeslano IS NOT NULL")" \
  "true|null|-|1/0|1"

# a delivered request: whoever holds the link it returned (staff, Claude) cannot refuse for the customer – not with ?refuse=1,
# not with a made-up code; only the code in the customer's e-mail works
A724_SENT=$(a724_customer dorucena-a724@example.com)
A724_ANSWER=$(a724_ask "$A724_SENT")
A724_SPATH=$(printf '%s' "${A724_ANSWER##*|}" | grep -o '/_testimonial/[a-f0-9]\{32\}' || true)
if [ -n "$A724_SPATH" ]; then
  curl -s -o "$WORK/a724-1.html" -X POST "$B$A724_SPATH?refuse=1"
  curl -s -o "$WORK/a724-2.html" -X POST "$B$A724_SPATH?refuse=$(openssl rand -hex 16)"
  A724_GOOD=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\Testimonials::refuseCode((new Kaleta\Core\Antispam($app->db(), $app->settings()))->key(), hash("sha256", $argv[1]));' -- "${A724_SPATH:14:32}")
  A724_BEFORE="$(grep -c 'Tento odkaz už neplatí' "$WORK/a724-1.html")|$(grep -c 'Tento odkaz už neplatí' "$WORK/a724-2.html")|$(a724_blocked dorucena-a724@example.com)"
  curl -s -o "$WORK/a724-3.html" -X POST "$B$A724_SPATH?refuse=$A724_GOOD"
  expect "3.51 A review (third round): an e-mailed request – the returned link with ?refuse=1 or a made-up code refuses nothing; the e-mail's code refuses" \
    "${A724_ANSWER%%|*}|$A724_BEFORE|$(grep -c 'Žádosti ani novinky vám už posílat nebudeme' "$WORK/a724-3.html")|$(a724_blocked dorucena-a724@example.com)" "true|1|1|none|1|refused"
else echo "  CHYBA  3.51 A review (third round): request_testimonial gave no link"; ERRORS=$((ERRORS+1)); fi

# the suite shares one database: what this section made goes, the settings come back
sq "DELETE FROM ka_posta WHERE komu LIKE '%-a724@example.com'; DELETE FROM ka_testimonial_requests WHERE idp IN ($A724_GIVEN, $A724_SENT); DELETE FROM ka_poptavky WHERE idp IN ($A724_GIVEN, $A724_SENT);
  DELETE FROM ka_deals WHERE title = 'Won A724'; DELETE FROM ka_contacts WHERE email LIKE '%-a724@example.com'; DELETE FROM ka_api_tokeny WHERE nazev = 'a724 admin'" > /dev/null
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); foreach (["vzdana-a724@example.com", "dorucena-a724@example.com"] as $e) { foreach (["refused", "bounced"] as $k) { Kaleta\Core\SendRegister::lift($app->db(), $k, $e); } }')
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'site_email'); INSERT INTO ka_nastaveni SELECT * FROM ka_a724_set; DROP TABLE ka_a724_set" > /dev/null
features --exact "$A724_EXT"
background_jobs resume
rm -f "$WORK"/a724*.html
