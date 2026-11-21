echo "== 3.50.1 A2: a shorter retention sent through a section's old field names waits on the confirmation screen of Settings (D1)"
a602_post() { # a602_post <screen query> <module&action query> <fields…>: opens the screen (the form token), posts, prints "<code> <redirect>"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?$1" > /dev/null; local target="$2"; shift 2
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?$target" -d "_csrf=$(csrf)" "$@"; }
a602_logged() { sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'retention' AND popis <> ''"; }
A602_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS a602_nastaveni; CREATE TABLE a602_nastaveni AS SELECT * FROM ka_nastaveni WHERE promenna <> 'extensions'" > /dev/null
features +applications +quotes +whistleblowing > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '24'), ('triage_assistant', '0'), ('applications_pool_months', '12'), ('quotes_months', '36'), ('whistleblowing_retention_months', '24')" > /dev/null

A602_LOG=$(a602_logged)
A602_TO=$(a602_post 'module=enquiries' 'module=enquiries&action=settings' -d mesice=1 -d triage_assistant=1)
expect "3.50.1 A2: Enquiries – a shorter period from the old field goes to the retention screen (302) and is not saved; the assistant switch is saved; nothing logged yet" \
  "$(printf '%s' "$A602_TO" | cut -d' ' -f1)|$(printf '%s' "$A602_TO" | grep -c 'module=settings&action=retention&enquiries_months=1$')|$(setting_plain enquiries_months)|$(setting_plain triage_assistant)|$(( $(a602_logged) - A602_LOG ))" "302|1|24|1|0"
curl -s -b "$JAR" -o "$WORK/a602-confirm.html" "$B/admin.php?module=settings&action=retention&enquiries_months=1"
expect "3.50.1 A2: the screen shows the proposal with Apply" "$(grep -c 'name="choice" value="apply"' "$WORK/a602-confirm.html" | sed 's/^[1-9][0-9]*$/1/')|$(grep -c 'name="enquiries_months" value="1"' "$WORK/a602-confirm.html" | sed 's/^[1-9][0-9]*$/1/')" "1|1"
retention_apply enquiries_months=1
expect "3.50.1 A2: confirmed (choice=apply) it is stored and the change log has one line" "$(setting_plain enquiries_months)|$(( $(a602_logged) - A602_LOG ))" "1|1"
A602_TO=$(a602_post 'module=enquiries' 'module=enquiries&action=settings' -d mesice=48 -d triage_assistant=1)
expect "3.50.1 A2: a longer period from the old field is saved at once (back to Enquiries, no confirmation, no log line)" \
  "$(printf '%s' "$A602_TO" | grep -c 'action=retention')|$(setting_plain enquiries_months)|$(( $(a602_logged) - A602_LOG ))" "0|48|1"

A602_LOG=$(a602_logged)
A602_TO=$(a602_post 'module=applications&action=settings' 'module=applications&action=settings_save' -d applications_pool_months=3 -d applications_claude=1)
A602_TO2=$(a602_post 'module=quotes&action=settings' 'module=quotes&action=settings_save' -d quote_number_format='{YYYY}-{NNNN}' -d quote_valid_days=30 -d quote_vat_rate=21 -d quote_vat_rates=21 -d quote_max_discount=20 -d quote_link_days=30 -d quote_vat_payer=1 -d quotes_months=12)
A602_TO3=$(a602_post 'module=whistleblowing' 'module=whistleblowing&action=settings' $([ "$(setting_plain whistleblowing_enabled)" = 1 ] && echo -d enabled=1) $(setting_plain whistleblowing_readers | tr ',' '\n' | sed '/^$/d;s/^/-d readers[]=/') --data-urlencode "intro=$(setting_plain whistleblowing_intro)" -d retention=6)
expect "3.50.1 A2: Applications, Quotes and Whistleblowing – each shorter period goes to the retention screen and waits; nothing stored or logged" \
  "$(printf '%s' "$A602_TO" | grep -c 'action=retention&applications_pool_months=3$')$(printf '%s' "$A602_TO2" | grep -c 'action=retention&quotes_months=12$')$(printf '%s' "$A602_TO3" | grep -c 'action=retention&whistleblowing_retention_months=6$')|$(setting_plain applications_pool_months)|$(setting_plain quotes_months)|$(setting_plain whistleblowing_retention_months)|$(setting_plain applications_claude)|$(( $(a602_logged) - A602_LOG ))" \
  "111|12|36|24|1|0"
retention_apply applications_pool_months=3
retention_apply quotes_months=12
retention_apply whistleblowing_retention_months=6
expect "3.50.1 A2: confirmed they are stored, one change-log line each" \
  "$(setting_plain applications_pool_months)|$(setting_plain quotes_months)|$(setting_plain whistleblowing_retention_months)|$(( $(a602_logged) - A602_LOG ))" "3|12|6|3"

sq "DELETE FROM ka_nastaveni WHERE promenna <> 'extensions'; INSERT INTO ka_nastaveni SELECT * FROM a602_nastaveni; DROP TABLE a602_nastaveni" > /dev/null
features --exact "$A602_EXT" > /dev/null
rm -f "$WORK/a602-confirm.html"
