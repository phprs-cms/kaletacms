echo "== 3.40 A: the quote as a PDF – Download PDF in the admin and on the customer's page, the PDF attached to the e-mail"
A340_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$A340_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$A340_EXT"),quotes')" > /dev/null
A340_SMTP=$((PORT + 3)); mkdir -p "$WORK/smtp-a340"
A340_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$A340_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''),
  ('mail_from', 'web@example.cz'), ('quote_vat_rate', '21'), ('quote_terms', 'Dodání do 4 týdnů.'), ('quote_attach_pdf', '1')" > /dev/null # an earlier Settings save may have unticked it
a340_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
# a340_parts <file>: multipart/mixed?|PDF attachments|the file name|the file's first bytes|a whole file (%%EOF)|an HTML part
a340_parts() { php -r '$m = file_get_contents($argv[1]); [$h] = explode("\r\n\r\n", $m, 2); echo preg_match("~^Content-Type: multipart/mixed; boundary=\"~m", $h), "|";
  preg_match_all("~Content-Type: application/pdf; name=\"([^\"]+)\"\r\nContent-Disposition: attachment; filename=\"([^\"]+)\"\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+/=\r\n]+)~", $m, $p, PREG_SET_ORDER);
  $pdf = isset($p[0]) ? base64_decode($p[0][3]) : ""; echo count($p), "|", $p[0][2] ?? "", "|", substr($pdf, 0, 8), "|", (int) str_ends_with($pdf, "%%EOF\n"), "|", preg_match("~Content-Type: text/html~", $m);' -- "$1"; }

# a quote for a customer with a contact (MCP may send it) and one more; both Czech
a340_php '$c = $db->insert("contacts", ["email" => "zakaznik340@example.cz", "email_norm" => "zakaznik340@example.cz", "name" => "Žofie Řezníčková", "source" => "manual", "created_at" => date("Y-m-d H:i:s"), "updated_at" => date("Y-m-d H:i:s")]);
  $line = [["name" => "Laserová řezačka Šťastná", "code" => "LS340", "quantity" => "2", "unit" => "ks", "unit_price" => "12500.50", "vat_rate" => "21"], ["name" => "Montáž", "quantity" => "1", "unit" => "h", "unit_price" => "1500", "vat_rate" => "21"]];
  $q1 = Kaleta\Core\Quotes::save($app, null, ["contact_id" => $c, "title" => "Lasery pro dílnu", "lines" => $line, "valid_days" => 21], (int) $a[0])["id"];
  $q2 = Kaleta\Core\Quotes::save($app, null, ["contact_id" => $c, "title" => "Bez přílohy", "lines" => $line], (int) $a[0])["id"];
  $q3 = Kaleta\Core\Quotes::save($app, null, ["contact_id" => $c, "title" => "Od Clauda", "lines" => $line], (int) $a[0])["id"];
  echo implode(" ", [$c, $q1, $q2, $q3]);' "$A340_ADMIN" > "$WORK/a340-ids"
read -r A340_CONTACT A340_Q1 A340_Q2 A340_Q3 < "$WORK/a340-ids" || true
A340_Q1="${A340_Q1:-0}"; A340_Q2="${A340_Q2:-0}"; A340_Q3="${A340_Q3:-0}"; A340_CONTACT="${A340_CONTACT:-0}"

# the admin: Download PDF on the detail; a draft's PDF says it is a draft; the file is an attachment, never cached
check "3.40 A: the quote's detail offers Download PDF" 200 "/admin.php?module=quotes&action=detail&id=$A340_Q1" 'data-quote-pdf'
A340_HEAD=$(curl -s -b "$JAR" -c "$JAR" -D - -o "$WORK/a340-draft.pdf" "$B/admin.php?module=quotes&action=pdf&id=$A340_Q1" | tr -d '\r')
expect "3.40 A: the admin's PDF of a draft is a PDF attachment named after the quote, never cached" \
  "$(head -1 <<< "$A340_HEAD" | grep -c ' 200')|$(grep -ci '^content-type: application/pdf' <<< "$A340_HEAD")|$(grep -ci "^content-disposition: attachment; filename=\"quote-$A340_Q1.pdf\"" <<< "$A340_HEAD")|$(grep -ci '^cache-control: private, no-store' <<< "$A340_HEAD")|$(head -c 8 "$WORK/a340-draft.pdf")" \
  "1|1|1|1|%PDF-1.4"
expect "3.40 A: without signing in there is no PDF" "$(curl -s -o /dev/null -w '%{content_type}' "$B/admin.php?module=quotes&action=pdf&id=$A340_Q1" | grep -c 'application/pdf')" "0"
expect "3.40 A: a quote that does not exist is not found" "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=quotes&action=pdf&id=999999")" "404"

# the send dialog: the box "Attach the quote as a PDF", ticked by the setting
check "3.40 A: the send dialog offers to attach the PDF, ticked by default" 200 "/admin.php?module=quotes&action=send&id=$A340_Q1" 'name="attach_pdf" value="1" checked data-quote-attach-pdf'

# sending with the box: the e-mail is multipart/mixed with the PDF named after the number; while the mail server is down it waits in the queue with it
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=send" -d "_csrf=$(csrf)" -d "id=$A340_Q1" --data-urlencode "subject=Nabídka {number}" \
  --data-urlencode $'message=Dobrý den,\nnabídka je v příloze.' -d attach_pdf=1
A340_NUMBER=$(sq "SELECT number FROM ka_quotes WHERE id = $A340_Q1")
expect "3.40 A: with the mail server down the quote is sent and its e-mail waits in the queue with the PDF" \
  "$(sq "SELECT status FROM ka_quotes WHERE id = $A340_Q1")|$(sq "SELECT (odeslano IS NULL) + (telo LIKE '%\"prilohy\":[{\"name\":\"$A340_NUMBER.pdf\",\"type\":\"application%') FROM ka_posta WHERE idp = (SELECT sent_mail FROM ka_quotes WHERE id = $A340_Q1)")" "sent|2"
command php "$ROOT/tools/fake-smtp.php" "$A340_SMTP" "$WORK/smtp-a340" > /dev/null 2>&1 & A340_SMTP_PID=$!
for i in $(seq 1 50); do php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' -- "$A340_SMTP" && break; sleep 0.2; done # listening before the retry
sq "UPDATE ka_posta SET dalsi_pokus = '$(site_time '-1 minute')' WHERE idp = (SELECT sent_mail FROM ka_quotes WHERE id = $A340_Q1)" > /dev/null
a340_php 'echo Kaleta\Core\Mail::processQueue($app->settings(), 500);' > /dev/null 2>&1 || true # earlier sections leave queued mail older than ours
F=$(grep -l "^X-Rcpt-To: zakaznik340@example.cz" "$WORK"/smtp-a340/*.eml 2>/dev/null | tail -1 || true)
expect "3.40 A: the retried e-mail carries the PDF – multipart/mixed, the attachment <number>.pdf, a whole PDF file, the HTML part kept" \
  "$( [ -n "$F" ] && a340_parts "$F")|$(sq "SELECT (odeslano IS NOT NULL) + (telo IS NULL) FROM ka_posta WHERE idp = (SELECT sent_mail FROM ka_quotes WHERE id = $A340_Q1)")" \
  "1|1|$A340_NUMBER.pdf|%PDF-1.4|1|1|2"

# the customer's page: Download PDF beside Print; <token>.pdf is the file with the same token rules; a wrong token is the 404
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=send&id=$A340_Q1"
A340_LINK=$(grep -o "$B/quote/[a-f0-9]\{64\}" "$WORK/response" | head -1); A340_TOKEN="${A340_LINK##*/}"
curl -s -o "$WORK/a340-page" "$A340_LINK"
expect "3.40 A: the customer's page links Stáhnout PDF to <token>.pdf" "$(grep -c "class=\"quote-pdf\" href=\"[^\"]*/quote/$A340_TOKEN.pdf\" download>Stáhnout PDF<" "$WORK/a340-page")" "1"
A340_HEAD=$(curl -s -D - -o "$WORK/a340-customer.pdf" "$A340_LINK.pdf" | tr -d '\r')
expect "3.40 A: <token>.pdf downloads the frozen quote – an attachment <number>.pdf, no-store, noindex, no referrer" \
  "$(head -1 <<< "$A340_HEAD" | grep -c ' 200')|$(grep -ci '^content-type: application/pdf' <<< "$A340_HEAD")|$(grep -ci "^content-disposition: attachment; filename=\"$A340_NUMBER.pdf\"" <<< "$A340_HEAD")|$(grep -ci '^cache-control: private, no-store' <<< "$A340_HEAD")|$(grep -ci '^x-robots-tag: noindex, nofollow' <<< "$A340_HEAD")|$(grep -ci '^referrer-policy: no-referrer' <<< "$A340_HEAD")|$(head -c 8 "$WORK/a340-customer.pdf")|$(grep -ci '^x-cache:' <<< "$A340_HEAD")" \
  "1|1|1|1|1|1|%PDF-1.4|0"
A340_WRONG=$(php -r 'echo bin2hex(random_bytes(32));')
expect "3.40 A: a wrong, an upper-case or a withdrawn token's PDF is the site's 404, and nothing else under /quote/ answers" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/$A340_WRONG.pdf")|$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/$(tr 'a-f' 'A-F' <<< "$A340_TOKEN").pdf")|$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/$A340_TOKEN.PDF")|$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/$A340_TOKEN.pdf/x")" \
  "404|404|404|404"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=link_withdraw" -d "_csrf=$(csrf)" -d "id=$A340_Q1"
expect "3.40 A: after the link is withdrawn its PDF is gone too" "$(curl -s -o /dev/null -w '%{http_code}' "$A340_LINK.pdf")" "404"
expect "3.40 A: the admin's PDF of the sent quote is its number" "$(curl -s -b "$JAR" -c "$JAR" -D - -o /dev/null "$B/admin.php?module=quotes&action=pdf&id=$A340_Q1" | tr -d '\r' | grep -ci "^content-disposition: attachment; filename=\"$A340_NUMBER.pdf\"")" "1"

# without the box the e-mail has no attachment
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=send" -d "_csrf=$(csrf)" -d "id=$A340_Q2" --data-urlencode "subject=Nabídka {number}" --data-urlencode "message=Dobrý den."
F=$(grep -l "Subject: =?UTF-8?B?$(printf 'Nabídka %s' "$(sq "SELECT number FROM ka_quotes WHERE id = $A340_Q2")" | base64)?=" "$WORK"/smtp-a340/*.eml 2>/dev/null | tail -1 || true)
expect "3.40 A: sent without the box, the e-mail is the plain message with the link" "$( [ -n "$F" ] && a340_parts "$F")" "0|0|||0|1"

# MCP send_quote: the PDF by the site's setting (additive key pdf), attach_pdf=false leaves it out
A340_OUT=$(mcp_call "$API_TOKEN" send_quote "{\"id\":$A340_Q3,\"confirm\":true,\"message\":\"Dobrý den, nabídka od Clauda.\"}")
F=$(ls -t "$WORK"/smtp-a340/*.eml 2>/dev/null | head -1)
expect "3.40 A MCP: send_quote attaches the PDF by the site's setting and says so" "$(grep -c '\\"pdf\\":true' <<< "$A340_OUT")|$( [ -n "$F" ] && a340_parts "$F" | cut -d'|' -f1-3)" "1|1|1|$(sq "SELECT number FROM ka_quotes WHERE id = $A340_Q3").pdf"

# clean up
kill "$A340_SMTP_PID" 2>/dev/null || true; wait "$A340_SMTP_PID" 2>/dev/null || true
a340_php 'Kaleta\Core\QuoteDelivery::delete($db, array_map("intval", array_column($db->all("SELECT id FROM {quotes}"), "id")));' > /dev/null 2>&1 || true
sq "DELETE FROM ka_contacts WHERE id = $A340_CONTACT; DELETE FROM ka_quote_counters; DELETE FROM ka_nastaveni WHERE promenna LIKE 'quote%';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''), ('extensions', '$A340_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
