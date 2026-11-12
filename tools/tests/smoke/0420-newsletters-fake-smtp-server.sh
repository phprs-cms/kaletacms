echo "== newsletters (fake SMTP server)"
SMTP_PORT=$((PORT + 3)); mkdir -p "$WORK/smtp"
command php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp" > /dev/null 2>&1 & SMTP_PID=$!
db() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
tok() { php -r 'echo bin2hex(random_bytes(16));'; }
# eml <file>: headers, the decoded subject and the decoded text and HTML parts of a captured message
eml() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo $h, "\n"; preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n";
  preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }
  if ($p[1] === [] && preg_match("/^Content-Transfer-Encoding: base64/mi", $h)) { echo base64_decode(preg_replace("/\s+/", "", $b)), "\n"; }' -- "$1"; }
mail_to() { grep -l "^X-Rcpt-To: $1" "$WORK"/smtp/*.eml 2>/dev/null | tail -1; }
newsletter_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
ANNA=$(tok); PETR=$(tok)
db "UPDATE ka_uzivatele SET email = 'admin@example.cz' WHERE user = 'admin'; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES
  ('anna@example.cz', 1, '$ANNA', '$(site_time)', '$(site_time)'), ('petr@example.cz', 1, '$PETR', '$(site_time)', '$(site_time)'), ('odmitnout@example.cz', 1, '$(tok)', '$(site_time)', '$(site_time)'), ('ceka@example.cz', 0, '$(tok)', '$(site_time)', NULL);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('tasks_last_run', '0')"
check "newsletters: empty list" 200 "/admin.php?module=newsletters" "Napsat newsletter"
check "newsletters: new draft form" 200 "/admin.php?module=newsletters&action=new" 'name="subject"'
newsletter_action save -d id=0 --data-urlencode "subject=Jarní novinky" --data-urlencode "preheader=Co je nového" --data-urlencode $'intro=Dobrý den,\n\nposíláme novinky. Více na https://example.cz/akce' \
  -d news_mode=latest -d news_count=2 --data-urlencode "button_label=Všechny novinky" -d button_url=/novinky
NL=$(db "SELECT id FROM ka_newsletters ORDER BY id DESC LIMIT 1")
expect "newsletter draft saved" "$(db "SELECT CONCAT(status, '|', subject, '|', news_count) FROM ka_newsletters WHERE id = $NL")" "draft|Jarní novinky|2"
check "newsletter: e-mail preview" 200 "/admin.php?module=newsletters&action=preview&id=$NL" "utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=jarni-novinky"
expect "preview: 2 news items, linked address, button and unsubscribe" "$(grep -c 'Číst dál' "$WORK/response")|$(grep -c 'href="https://example.cz/akce"' "$WORK/response")|$(grep -c 'Všechny novinky' "$WORK/response")|$(grep -c 'Odhlásit odběr' "$WORK/response")" "2|1|1|1"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending without an SMTP server" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')"
newsletter_action send -d "id=$NL" -d when=now
# 3.15.2: several visible recipients see each other in To, a hidden copy (Bcc) is only in the envelope
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); Kaleta\Core\Mail::send($app->settings(), "prvni@example.cz, druhy@example.cz", "Více příjemců", "text", "", ["Reply-To" => "navstevnik@example.cz", "Bcc" => "skryty@example.cz"]);')
F=$(mail_to "prvni@example.cz, druhy@example.cz, skryty@example.cz")
expect "3.15.2: To lists the visible recipients, the hidden one only in the envelope" "$([ -n "$F" ] && grep -c '^To: prvni@example.cz, druhy@example.cz' "$F")|$([ -n "$F" ] && grep -ci 'skryty@example.cz' "$F")" "1|1"
expect "no sending while cron does not run" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
check "newsletter form tells why it cannot send" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Cron za posledních 30 minut"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
newsletter_action test -d "id=$NL"
F=$(mail_to admin@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "test e-mail to the signed-in user" "$(grep -c '^Subject-Decoded: \[Zkouška\] Jarní novinky$' "$WORK/eml.txt" 2>/dev/null)" "1"
newsletter_action send -d "id=$NL" -d when=now
expect "sending started for confirmed subscribers only" "$(db "SELECT CONCAT(status, '|', recipients, '|', html LIKE '%{{unsubscribe}}%') FROM ka_newsletters WHERE id = $NL")" "sending|3|1"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "cron sends a batch: 2 delivered, the refused one waits for a retry" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL AND next_attempt > '$(site_time)')) FROM ka_newsletters WHERE id = $NL")" "sending|2|0|1"
F=$(mail_to anna@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "subscriber e-mail: one-click unsubscribe with the own link, no one else's" "$(grep -c "^List-Unsubscribe: <http://127.0.0.1:$PORT/subscription?unsubscribe=$ANNA>" "$WORK/eml.txt")|$(grep -c '^List-Unsubscribe-Post: List-Unsubscribe=One-Click' "$WORK/eml.txt")|$(grep -c "unsubscribe=$ANNA" "$WORK/eml.txt")|$(grep -c "$PETR" "$WORK/eml.txt")" "1|1|3|0"
expect "subscriber e-mail: subject, text part and HTML part" "$(grep -c '^Subject-Decoded: Jarní novinky$' "$WORK/eml.txt")|$(grep -c '^Všechny novinky: http' "$WORK/eml.txt")|$(grep -c '<h1 ' "$WORK/eml.txt")" "1|1|1"
expect "newsletter recipients are not in the mail log" "$(db "SELECT COUNT(*) FROM ka_posta WHERE komu IN ('anna@example.cz', 'petr@example.cz')")" "0"
db "UPDATE ka_newsletter_queue SET next_attempt = '$(site_time)' WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
db "UPDATE ka_newsletter_queue SET next_attempt = '$(site_time)' WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a refused address is given up after three attempts, the newsletter is sent" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', finished_at IS NOT NULL) FROM ka_newsletters WHERE id = $NL")" "sent|2|1|1"
check "newsletters: list with counts" 200 "/admin.php?module=newsletters" "Odesláno"
check "a sent newsletter is read-only" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Příjemci"
contains -c 'name="subject"' "$WORK/response" && { echo "  CHYBA  a sent newsletter can still be edited"; ERRORS=$((ERRORS+1)); } || echo "  ok     a sent newsletter has no form"
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$ANNA" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "one-click unsubscribe from the mail client (RFC 8058) – the older /odber link of an e-mail sent before 3.7" "$(db "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'anna@example.cz'")" "0"

# 3.7: a sign-up through /subscription gets a confirmation e-mail with the English link; confirming and one-click unsubscribing work there too
rm -f "$WORK"/smtp/*.eml
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/subscription" -d "email=en37@example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$NL_SIGNATURE" -d "as_cas=$NL_TIME" -d web_adresa=)
case "$code" in "303 "*"/z-html?subscription=ok#x") echo "  ok     3.7: sign-up through POST /subscription";; *) echo "  CHYBA  3.7: sign-up through /subscription: $code"; ERRORS=$((ERRORS+1));; esac
: > "$WORK/eml.txt"; for i in $(seq 1 25); do F=$(mail_to en37@example.cz || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; grep -q 'potvrdit=' "$WORK/eml.txt" && break; fi; sleep 0.2; done
EN37=$(db "SELECT token FROM ka_odberatele WHERE email = 'en37@example.cz'")
expect "3.7: the confirmation e-mail links to /subscription, not /odber" "$(grep -c "http://127.0.0.1:$PORT/subscription?confirm=$EN37" "$WORK/eml.txt")|$(grep -c '/odber?' "$WORK/eml.txt")" "1|0"
check "3.7: the confirmation link opens at /subscription" 200 "/subscription?confirm=$EN37" "Potvrdit odběr"
contains -q "action=\"/subscription?confirm=$EN37\"" "$WORK/response" && echo "  ok     3.7: … and its button posts back to /subscription" || { echo "  CHYBA  3.7: confirm button action"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null -X POST "$B/subscription?confirm=$EN37"
curl -s -o /dev/null -X POST "$B/subscription?unsubscribe=$EN37" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "3.7: confirmed, then one-click unsubscribed at /subscription" "$(db "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'en37@example.cz'")" "0"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | contains '"name":"draft_newsletter"' && echo "  ok     MCP: newsletter tools listed" || { echo "  CHYBA  MCP: newsletter tools missing"; ERRORS=$((ERRORS+1)); }
db "DELETE FROM ka_odberatele WHERE email LIKE 'odmitnout%'"
mcp draft_newsletter '{"subject":"Novinky přes Clauda","intro":"Ahoj,\n\nkrátká zpráva.","news_mode":"none","button_label":"Kontakt","button_url":"/kontakt"}' > "$WORK/response"
NL2=$(mcp_value id)
expect "MCP: draft_newsletter returns the text version" "$(mcp_value status)|$(mcp_value text | grep -c '^Kontakt: http://127.0.0.1')" "draft|1"
mcp send_test_newsletter "{\"id\":$NL2}" > "$WORK/response"
expect "MCP: test goes to the connected user" "$(mcp_value sent_to)" "admin@example.cz"
mcp send_newsletter "{\"id\":$NL2,\"at\":\"2099-01-01 08:00\"}" > "$WORK/response"
expect "MCP: send_newsletter schedules" "$(mcp_value status)|$(mcp_value scheduled_at)" "scheduled|2099-01-01 08:00"
db "UPDATE ka_newsletters SET scheduled_at = '$(site_time)' - INTERVAL 1 MINUTE WHERE id = $NL2"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a due scheduled newsletter goes out on the next cron call" "$(db "SELECT CONCAT(status, '|', recipients, '|', sent_count) FROM ka_newsletters WHERE id = $NL2")" "sent|1|1"
mcp list_newsletters '{}' > "$WORK/response"
expect "MCP: list_newsletters with subscribers and no sending problem" "$(mcp_value confirmed_subscribers)|$(mcp_value sending_problem)|$(mcp_value newsletters 0 status)" "1|null|sent"
mcp delete_newsletter "{\"id\":$NL2}" > /dev/null
expect "MCP: delete_newsletter" "$(db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $NL2")" "0"
db "UPDATE ka_newsletters SET finished_at = '$(site_time)' - INTERVAL 2 DAY WHERE id = $NL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "recipients are kept only a day after sending" "$(db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL")|$(db "SELECT sent_count FROM ka_newsletters WHERE id = $NL")" "0|2"
check "health: cron check" 200 "/admin.php?module=status" "Cron"
# 3.22 (C14): the small limit of the test server (2M) says how to raise it here – php -S reads no .user.ini, so no button to write one
grep -q 'Aby šly nahrát větší soubory, nastavte upload_max_filesize = 16M, post_max_size = 20M v souboru php.ini serveru' "$WORK/response" && ! grep -q 'action=user_ini' "$WORK/response" && [ ! -e "$WORK/web/.user.ini" ] \
  && echo "  ok     3.22 C14: health – the upload limit advice for this server, no .user.ini where PHP does not read one" || { echo "  CHYBA  3.22 C14: upload limit advice missing or a .user.ini offered: $(grep -o 'upload_max_filesize = [^<]*' "$WORK/response" | head -1)"; ERRORS=$((ERRORS+1)); }
# 2.8: the domain and mail watch shows its group and the Check now button; a site on 127.0.0.1 makes no DNS or network request
grep -q "Doména a pošta" "$WORK/response" && grep -q "action=domain_check" "$WORK/response" && grep -q "běží na místní adrese" "$WORK/response" && echo "  ok     health: domain and mail watch – group, Check now, nothing checked on a local address" || { echo "  CHYBA  health: domain and mail watch group missing"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=domain_check" -d "_csrf=$TOKEN"
check "health: Check now stores the result and reports the local address" 200 "/admin.php?module=status" "Naposledy zkontrolováno"
expect "health: the check result is cached in the domain_watch setting" "$(db "SELECT JSON_EXTRACT(hodnota, '$.local') FROM ka_nastaveni WHERE promenna = 'domain_watch'")" "true"
grep -q "vlastni ve složce layout/" "$WORK/response" && echo "  ok     health: a leftover custom layout is reported" || { echo "  CHYBA  health: leftover custom layout not reported"; ERRORS=$((ERRORS+1)); }
# 3.3.3 (N59): the reset link is queued and sent right after the response – it still arrives at once, and an unknown name leaves no trace
JAR_RESET="$WORK/jar-reset"; rm -f "$JAR_RESET"
reset_request() { curl -s -c "$JAR_RESET" -b "$JAR_RESET" -o "$WORK/response" "$B/admin.php?action=password"; curl -s -b "$JAR_RESET" -c "$JAR_RESET" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=password" -d "_csrf=$(csrf)" -d "kdo=$1"; }
db "DELETE FROM ka_posta; DELETE FROM ka_kontrola_ip WHERE typ = 'obnova'"; rm -f "$WORK"/smtp/*.eml
RESET_KNOWN=$(reset_request admin); sed 's/<[^>]*>//g' "$WORK/response" > "$WORK/reset-known.txt"
# the fake SMTP server writes its file a moment after it accepted the message – wait for it (the queue row already says sent)
: > "$WORK/eml.txt"; for i in $(seq 1 25); do F=$(mail_to admin@example.cz || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; grep -q 'action=password&token=' "$WORK/eml.txt" && break; fi; sleep 0.2; done
expect "3.3.3: the reset link went through the queue and was delivered by the time the answer was complete" \
  "$RESET_KNOWN|$(db "SELECT CONCAT(COUNT(*), '|', SUM(odeslano IS NOT NULL), '|', SUM(telo IS NULL), '|', MIN(pokusu)) FROM ka_posta WHERE komu = 'admin@example.cz'")|$(grep -c 'action=password&token=[a-f0-9]\{64\}' "$WORK/eml.txt")" "200|1|1|1|1|1"
RESET_UNKNOWN=$(reset_request nikdo-takovy); sed 's/<[^>]*>//g' "$WORK/response" > "$WORK/reset-unknown.txt"
expect "3.3.3: an unknown name gets the same page and queues nothing" "$RESET_UNKNOWN|$(cmp -s "$WORK/reset-known.txt" "$WORK/reset-unknown.txt" && echo same)|$(db "SELECT COUNT(*) FROM ka_posta")" "200|same|1"
# 3.9 (owner's idea): Settings → Mail → "Send through" a mail service. A configuration from before 3.9 (no smtp_provider) is shown as
# it is and saving the form unchanged keeps every value; a service fills its server, port and encryption only when the server is not
# already its own; the user name and the password are never touched; the test e-mail still reaches the fake SMTP server
mail_form() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=mail"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=mail -d mail_mode=smtp -d newsletter_hourly_limit=300 -d smtp_password= -d smtp_ses_region=eu-central-1 "$@"; }
mail_smtp() { printf '%s' "$(db "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_host'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_port'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_encryption'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_user'), '')")"; [ "$(setting_plain smtp_password)" = "$1" ] && echo 1 || echo 0; } # 3.39 (N106): the password is stored sealed
OLD_SMTP_PW=$(tok)
db "DELETE FROM ka_nastaveni WHERE promenna = 'smtp_provider'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', 'smtp.firma.example'), ('smtp_port', '465'), ('smtp_encryption', 'ssl'), ('smtp_user', 'web@firma.example'), ('smtp_password', '$OLD_SMTP_PW')"
check "3.9: the Mail tab shows a configuration from before 3.9 as it is" 200 "/admin.php?module=settings&tab=mail" 'value="smtp.firma.example"'
contains -q '<option value="other" selected>' "$WORK/response" && contains -q 'data-smtp-sluzba' "$WORK/response" && contains -q 'data-host="smtp-relay.brevo.com" data-port="587" data-sifrovani="tls"' "$WORK/response" && ! contains -q "$OLD_SMTP_PW" "$WORK/response" \
  && echo "  ok     3.9: … as “Other server”, with every service's server in the choice and the password never in the page" || { echo "  CHYBA  3.9: the mail service choice"; ERRORS=$((ERRORS+1)); }
mail_form -d smtp_provider=other -d smtp_host=smtp.firma.example -d smtp_port=465 -d smtp_encryption=ssl -d smtp_user=web@firma.example
expect "3.9: saving it unchanged keeps the custom server exactly – server, port, encryption, user name and password" "$(mail_smtp "$OLD_SMTP_PW")|$(db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_provider'")" "smtp.firma.example|465|ssl|web@firma.example|1|other"
# a Brevo server on port 2525 (set up before 3.9): shown as Brevo with what Brevo wants as the password, saved with its own port
db "DELETE FROM ka_nastaveni WHERE promenna = 'smtp_provider'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('smtp_host', 'smtp-relay.brevo.com'), ('smtp_port', '2525'), ('smtp_encryption', 'tls'), ('newsletter_service', 'brevo')"
check "3.9: a Brevo server is shown as Brevo, with its SMTP login and key explained" 200 "/admin.php?module=settings&tab=mail" 'SMTP login, který Brevo ukazuje'
contains -q '<option value="brevo" data-host="smtp-relay.brevo.com" data-port="587" data-sifrovani="tls" selected>' "$WORK/response" && contains -q 'Napojení newsletteru používá také Brevo. Jeho API klíč se sem nekopíruje' "$WORK/response" && contains -q 'href="/admin.php?module=extensions#newsletter"' "$WORK/response" && contains -q 'DNS záznamy pro Brevo' "$WORK/response" \
  && echo "  ok     3.9: … the newsletter integration on the same account is named and linked (no key copied), and the DNS records for Brevo follow" || { echo "  CHYBA  3.9: Brevo hint, newsletter link or DNS records"; ERRORS=$((ERRORS+1)); }
check "3.9: Features links the newsletter integration back to Settings → Mail" 200 "/admin.php?module=extensions" 'Vlastní pošta webu jde také přes Brevo'
contains -q 'href="/admin.php?module=settings&amp;tab=mail"' "$WORK/response" && echo "  ok     3.9: … with the link to the Mail tab" || { echo "  CHYBA  3.9: Features → Mail link"; ERRORS=$((ERRORS+1)); }
mail_form -d smtp_provider=brevo -d smtp_host=smtp-relay.brevo.com -d smtp_port=2525 -d smtp_encryption=tls -d smtp_user=web@firma.example
expect "3.9: saving Brevo keeps its own server on port 2525 and the password" "$(mail_smtp "$OLD_SMTP_PW")" "smtp-relay.brevo.com|2525|tls|web@firma.example|1"
# choosing a service without JavaScript: its server, port and encryption are filled; the user name and password stay
mail_form -d smtp_provider=postmark -d smtp_host= -d smtp_port=25 -d smtp_encryption=zadne -d smtp_user=web@firma.example
expect "3.9: choosing Postmark fills its server, port and STARTTLS; the user name and password stay" "$(mail_smtp "$OLD_SMTP_PW")" "smtp.postmarkapp.com|587|tls|web@firma.example|1"
mail_form -d smtp_provider=ses -d smtp_ses_region=eu-west-1 -d smtp_host=smtp.postmarkapp.com -d smtp_port=587 -d smtp_encryption=tls -d smtp_user=web@firma.example
expect "3.9: switching to Amazon SES gives the endpoint of the chosen region" "$(mail_smtp "$OLD_SMTP_PW")" "email-smtp.eu-west-1.amazonaws.com|587|tls|web@firma.example|1"
check "3.9: System status names the mail service" 200 "/admin.php?module=status" 'přes Amazon SES (SMTP server email-smtp.eu-west-1.amazonaws.com)'
mcp site_info '{}' > "$WORK/response"; SITE_MAIL=$(mcp_value mail); contains -q "$OLD_SMTP_PW" "$WORK/response" && SITE_MAIL="leaked"
mcp get_health '{}' > "$WORK/response"; HEALTH_MAIL=$(mcp_value mail_service); contains -q "$OLD_SMTP_PW" "$WORK/response" && HEALTH_MAIL="leaked"
mcp update_settings '{"settings":{"smtp_provider":"brevo","smtp_host":"smtp.evil.example","smtp_password":"x"}}' > /dev/null
expect "3.9: MCP names the service (site_info, get_health), never the password, and cannot change the mail server" "$SITE_MAIL|$HEALTH_MAIL|$(mail_smtp "$OLD_SMTP_PW")|$(db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'smtp_provider'")" \
  '{"sending":"smtp","service":"Amazon SES"}|Amazon SES|email-smtp.eu-west-1.amazonaws.com|587|tls|web@firma.example|1|ses'
# back to "Other server" – the fake SMTP server – through the form: the test e-mail arrives there
mail_form -d smtp_provider=other -d smtp_host=127.0.0.1 -d "smtp_port=$SMTP_PORT" -d smtp_encryption=zadne -d smtp_user=
rm -f "$WORK"/smtp/*.eml; SITE_MAILBOX=$(db "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")
# 3.23 (C17): c17_row <key> – done | open | missing: one row of the hand-over checklist in the last site_audit answer ($WORK/c17.json)
c17_row() { php -r '$r = json_decode((string) file_get_contents($argv[1]), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? ""), true);
  foreach ($t["handover_checklist"]["items"] ?? [] as $i) { if ($i["key"] === $argv[2]) { echo $i["done"] ? "done" : "open"; exit; } } echo "missing";' -- "$WORK/c17.json" "$1"; }
db "DELETE FROM ka_nastaveni WHERE promenna = 'smtp_last_ok'"; mcp site_audit '{"kind":"handover"}' > "$WORK/c17.json"
C17_SMTP_BEFORE="$(c17_row smtp)|$(php -r '$t = json_decode((string) (json_decode((string) file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? ""), true); echo count(array_filter($t["findings"] ?? [], fn ($f) => ($f["target"]["handover"] ?? "") === "smtp_tested"));' -- "$WORK/c17.json")"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=test_mail" -d "_csrf=$(csrf)" -d tab=mail
: > "$WORK/eml.txt"; for i in $(seq 1 25); do F=$(mail_to "$SITE_MAILBOX" || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; break; fi; sleep 0.2; done
expect "3.9: “Other server” saved through the form – the test e-mail reaches the fake SMTP server" "$(mail_smtp "$OLD_SMTP_PW")|$(grep -c 'can send e-mail\|umí odesílat e-maily\|E-Mails senden kann' "$WORK/eml.txt")" "127.0.0.1|$SMTP_PORT|zadne||1|1"
mcp site_audit '{"kind":"handover"}' > "$WORK/c17.json"
expect "3.23 (C17): SMTP is tested once the server set accepted a message (the test e-mail) – not before, and not for another server" \
  "$C17_SMTP_BEFORE|$(db "SELECT hodnota LIKE '%|127.0.0.1' FROM ka_nastaveni WHERE promenna = 'smtp_last_ok'")|$(c17_row smtp)|$(db "UPDATE ka_nastaveni SET hodnota = 'smtp.other.example' WHERE promenna = 'smtp_host'"; mcp site_audit '{"kind":"handover"}' > "$WORK/c17.json"; c17_row smtp)" \
  "open|1|1|done|open"
db "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('newsletter_service', 'smtp_password', 'smtp_provider')"
kill "$SMTP_PID" 2>/dev/null || true
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_odberatele; DELETE FROM ka_newsletters; DELETE FROM ka_newsletter_queue"

