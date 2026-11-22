echo "== 3.51 A: a testimonial request is e-mailed only to a customer, once, with the sender and a refuse link; the refuse link stops requests and the newsletter"
A704_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_a704_set; CREATE TABLE ka_a704_set AS SELECT * FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'company_email', 'site_email')" > /dev/null
features +newsletter
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'company_email', 'site_email')" > /dev/null
A704_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'a704 admin', 'full', 'all', SHA2('$A704_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
a704_enquiry() { sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, contact_id) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', '$1', '[]', 2, $2); SELECT LAST_INSERT_ID();"; }
a704_mails() { sq "SELECT COUNT(*) FROM ka_posta WHERE komu = '$1'"; }
a704_ask() { # <enquiry> <send 0|1>: the detail's form, then the result message of the detail
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$1"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=testimonial" -d "_csrf=$(csrf)" -d "id=$1" -d "poslat=$2"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$1"
}
a704_reason() { php -r '$r = json_decode((string) file_get_contents("php://stdin"), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); echo ($t["reason"] ?? "-") . "|" . (($t["sent"] ?? null) === true ? "sent" : "not sent");'; }
# the newest message of the sink for an address: headers and every base64 part decoded
a704_mail() { local f; f=$(grep -l "^X-Rcpt-To: $1" "$MAIL_SINK_DIR"/*.eml 2>/dev/null | sort | tail -1 || true); [ -n "$f" ] || return 0
  php -r '[$h, $b] = explode("\r\n\r\n", (string) file_get_contents($argv[1]), 2) + [1 => ""]; echo $h, "\n"; preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p);
    foreach ($p[1] as $x) { echo base64_decode($x), "\n"; } if ($p[1] === [] && preg_match("/^Content-Transfer-Encoding: base64/mi", $h)) { echo base64_decode((string) preg_replace("/\s+/", "", $b)), "\n"; } elseif ($p[1] === []) { echo $b; }' -- "$f"; }

# customers: contact A won a deal; contact B only has an open one
A704_A=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('zakaznik-a704@example.com', 'zakaznik-a704@example.com', 'A704', 'manual', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
A704_B=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('novy-a704@example.com', 'novy-a704@example.com', 'B704', 'manual', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
A704_DEALS=$(sq "INSERT INTO ka_deals (title, contact_id, stage, closed_at, created_at, updated_at) VALUES ('Won A704', $A704_A, 'won', '$(site_time)', '$(site_time)', '$(site_time)'), ('Open A704', $A704_B, 'proposal', NULL, '$(site_time)', '$(site_time)'); SELECT GROUP_CONCAT(id) FROM ka_deals WHERE title LIKE '% A704'")
A704_CUSTOMER=$(a704_enquiry zakaznik-a704@example.com "$A704_A")
A704_STRANGER=$(a704_enquiry novy-a704@example.com "$A704_B")

# without the company name and address: nothing is e-mailed, not even to a customer (text 8)
A704_RAW=$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $A704_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"request_testimonial\",\"arguments\":{\"id\":$A704_CUSTOMER,\"send\":true}}}")
A704_NOSENDER=$(mcp_call "$A704_TOKEN" request_testimonial "{\"id\":$A704_CUSTOMER,\"send\":true}" | a704_reason)
expect "3.51 A4: MCP send=true still asks for the confirmation code; without the company address the request is not e-mailed (reason sender_missing), the link is returned" \
  "$(printf '%s' "$A704_RAW" | grep -c 'confirmation_required')|$A704_NOSENDER|$(a704_mails zakaznik-a704@example.com)|$(sq "SELECT COUNT(*) FROM ka_testimonial_requests WHERE idp = $A704_CUSTOMER AND sent_at IS NULL")" \
  "1|sender_missing|not sent|0|1"
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('company_name', 'Firma A704 s.r.o.'), ('company_street', 'Dlouhá 7'), ('company_postcode', '602 00'), ('company_city', 'Brno'), ('company_country', 'CZ'), ('company_email', 'info-a704@example.com'), ('site_email', 'web-a704@example.com')" > /dev/null

# a person who is no customer: only the link, with the reason on the screen
a704_ask "$A704_STRANGER" 1
expect "3.51 A4: no won deal and no accepted quote – the admin gets the reason and the link to pass on; no e-mail" \
  "$(grep -c 'Žádost nebyla odeslána e-mailem: e-mailem ji dostane jen zákazník' "$WORK/response")|$(grep -c '/_testimonial/[a-f0-9]\{32\}' "$WORK/response" | tr -d ' ')|$(a704_mails novy-a704@example.com)" "1|1|0"

# a customer: one e-mail with the sender and the refuse line; a second request is not e-mailed again
a704_ask "$A704_CUSTOMER" 1
A704_MAIL=$(a704_mail zakaznik-a704@example.com)
A704_LINK=$(printf '%s' "$A704_MAIL" | grep -o '/_testimonial/[a-f0-9]\{32\}?refuse=[a-f0-9]\{32\}' | head -1 || true)
expect "3.51 A4: a customer gets one e-mail – with the sender's line (company and address) and the refuse line with its link; the request remembers it was sent" \
  "$(a704_mails zakaznik-a704@example.com)|$(printf '%s' "$A704_MAIL" | grep -c 'Odesílatel: Firma A704 s.r.o., Dlouhá 7, 602 00 Brno.')|$(printf '%s' "$A704_MAIL" | grep -c 'Pokud si podobné e-maily od nás nepřejete, dejte nám vědět zde: .*/_testimonial/[a-f0-9]\{32\}?refuse=[a-f0-9]\{32\}')|$(sq "SELECT COUNT(*) FROM ka_testimonial_requests WHERE idp = $A704_CUSTOMER AND sent_at IS NOT NULL")" \
  "1|1|1|1"
A704_SECOND=$(mcp_call "$A704_TOKEN" request_testimonial "{\"id\":$A704_CUSTOMER,\"send\":true}" | a704_reason)
expect "3.51 A4: once per enquiry – the second request (MCP, with the code) says already_sent and sends nothing" "$A704_SECOND|$(a704_mails zakaznik-a704@example.com)" "already_sent|not sent|1"

# A5: the refuse link – a GET changes nothing; the button refuses, ends the subscription and stops further requests
sq "INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('zakaznik-a704@example.com', 1, SUBSTRING(SHA2(RAND(), 256), 1, 32), '$(site_time)', '$(site_time)')" > /dev/null
a704_blocked() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SendRegister::blocked($app->db(), $argv[1]) ?? "none";' -- "$1"); }
if [ -n "$A704_LINK" ]; then
  # the request expired meanwhile – the refuse link still works
  sq "UPDATE ka_testimonial_requests SET expires_at = '$(site_time '-1 day')' WHERE idp = $A704_CUSTOMER" > /dev/null
  curl -s -o "$WORK/a704-refuse.html" "$B$A704_LINK"
  A704_GET="$(grep -c 'Nepište mi' "$WORK/a704-refuse.html")|$(grep -c 'zakaznik-a704' "$WORK/a704-refuse.html")|$(grep -c 'noindex' "$WORK/a704-refuse.html")|$(a704_blocked zakaznik-a704@example.com)"
  curl -s -o "$WORK/a704-refuse.html" -X POST "$B$A704_LINK"
  expect "3.51 A5: the refuse page asks without showing the address (noindex), the GET changes nothing; the POST of an expired request refuses, uses the request and ends the subscription" \
    "$A704_GET|$(grep -c 'Žádosti ani novinky vám už posílat nebudeme' "$WORK/a704-refuse.html")|$(a704_blocked zakaznik-a704@example.com)|$(sq "SELECT COUNT(*) FROM ka_testimonial_requests WHERE token_hash = SHA2('${A704_LINK:14:32}', 256) AND used_at IS NOT NULL")|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'zakaznik-a704@example.com'")" \
    "1|0|1|none|1|refused|1|0"
else echo "  CHYBA  3.51 A5: the e-mail had no refuse link"; ERRORS=$((ERRORS+1)); fi
A704_AGAIN=$(a704_enquiry zakaznik-a704@example.com "$A704_A")
expect "3.51 A5: after the refusal a new enquiry of the same customer is not e-mailed (reason refused)" \
  "$(mcp_call "$A704_TOKEN" request_testimonial "{\"id\":$A704_AGAIN,\"send\":true}" | a704_reason)|$(a704_mails zakaznik-a704@example.com)" "refused|not sent|1"
curl -s -o "$WORK/a704-refuse.html" -X POST "$B/_testimonial/$(openssl rand -hex 16)?refuse=1"
expect "3.51 A5: an unknown token refuses nothing and says to write to the site's public address" \
  "$(grep -c 'Tento odkaz už neplatí – napište nám na info-a704@example.com.' "$WORK/a704-refuse.html")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'refused' AND created_at >= '$(site_time '-1 hour')'")" "1|1"

# A6: the personal data request names the register; the erasure keeps the fingerprint and says so
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=personal"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/a704-personal.html" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d "email=zakaznik-a704@example.com"
A704_FIND=$(mcp_call "$A704_TOKEN" find_personal_data '{"email":"zakaznik-a704@example.com"}' | php -r '$r = json_decode((string) file_get_contents("php://stdin"), true); echo json_encode(json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true)["register"] ?? null);')
A704_ERASE=$(mcp_call "$A704_TOKEN" erase_personal_data '{"email":"zakaznik-a704@example.com","confirm":true}' | php -r '$r = json_decode((string) file_get_contents("php://stdin"), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true); echo count(array_filter($t["kept"] ?? [], fn ($k) => str_starts_with((string) ($k["what"] ?? ""), "send register")));')
expect "3.51 A6: the personal data screen and find_personal_data name the register; erase_personal_data keeps the fingerprint and lists it as kept" \
  "$(grep -c 'data-personal-register' "$WORK/a704-personal.html")|$A704_FIND|$A704_ERASE|$(a704_blocked zakaznik-a704@example.com)" '1|["refused"]|1|refused'

# the suite shares one database: what this section made goes, the settings come back
sq "DELETE FROM ka_testimonial_requests WHERE idp IN ($A704_CUSTOMER, $A704_STRANGER, $A704_AGAIN); DELETE FROM ka_poptavky WHERE idp IN ($A704_CUSTOMER, $A704_STRANGER, $A704_AGAIN);
  DELETE FROM ka_deals WHERE id IN (${A704_DEALS:-0}); DELETE FROM ka_contacts WHERE id IN ($A704_A, $A704_B); DELETE FROM ka_odberatele WHERE email LIKE '%-a704@example.com'; DELETE FROM ka_api_tokeny WHERE nazev = 'a704 admin'" > /dev/null
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); foreach (["zakaznik-a704@example.com", "novy-a704@example.com"] as $e) { foreach (["refused", "bounced"] as $k) { Kaleta\Core\SendRegister::lift($app->db(), $k, $e); } foreach (["unsubscribed", "erased"] as $k) { Kaleta\Core\RestoreLedger::forget($app->db(), $k, $e); } }')
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('company_name', 'company_street', 'company_postcode', 'company_city', 'company_country', 'company_email', 'site_email'); INSERT INTO ka_nastaveni SELECT * FROM ka_a704_set; DROP TABLE ka_a704_set" > /dev/null
features --exact "$A704_EXT"
rm -f "$WORK/a704-refuse.html" "$WORK/a704-personal.html"
