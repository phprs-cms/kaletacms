echo "== 2.14: whistleblowing channel"
WB_SMTP_PORT=$((PORT + 11)); mkdir -p "$WORK/smtp-wb"
command php "$ROOT/tools/fake-smtp.php" "$WB_SMTP_PORT" "$WORK/smtp-wb" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$WB_SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'); UPDATE ka_uzivatele SET email = 'wb-reader@example.cz' WHERE user = 'admin'" > /dev/null
WB_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
wb_csrf() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=whistleblowing"; csrf; }
check "whistleblowing: off by default – the public address is a 404" 404 "/_report"
# 3.2: Whistleblowing is a feature, off on a new installation – switched on in the administration under Features
check "3.2 whistleblowing: a feature that a new installation starts without – no admin module" 403 "/admin.php?module=whistleblowing"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=extensions"
grep -q 'value="whistleblowing"' "$WORK/response" && grep -q 'value="bookings"' "$WORK/response" && echo "  ok     3.2: Features offers Bookings and Whistleblowing" || { echo "  CHYBA  Features: the new features are not offered"; ERRORS=$((ERRORS+1)); }
WB_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
WB_FEATURES=(); for e in $(printf %s "$WB_EXT" | tr ',' ' ') whistleblowing; do WB_FEATURES+=(-d "rozsireni[]=$e"); done
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=extensions&action=save" -d "_csrf=$(csrf)" -d tab=extensions "${WB_FEATURES[@]}" -d "claude_destructive=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_destructive'), '1')")"
expect "3.2 whistleblowing: switched on under Features, the other features kept" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")" "$WB_EXT,whistleblowing"
check "whistleblowing: the module tells the administrator the channel is off and offers the setup" 200 "/admin.php?module=whistleblowing" 'name="readers'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=whistleblowing&action=settings" -d "_csrf=$(wb_csrf)" -d enabled=1 -d "readers[]=$WB_ADMIN" -d retention=24 --data-urlencode "intro=Oznámení řeší compliance officer."
expect "whistleblowing: the setup is saved – on, the reader, the retention" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_enabled'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_readers'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_retention_months'))")" "1|$WB_ADMIN|24"
# 3.3.3 (N53): a site with a CAPTCHA still loads none on the channel – the provider would learn who reports
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_provider','turnstile'),('captcha_site_key','test-site'),('captcha_secret','test-secret') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
check "whistleblowing: the public form with the introduction" 200 "/_report" 'name="text"'
# 3.15 (N46): the channel's page loads nothing from another host (an embed in the header or footer cannot see the reporter) and sends no Referer
expect "3.15 N46: the report page has a CSP that keeps it on this site and no Referer; an ordinary page has neither" \
  "$(curl -s -D - -o /dev/null "$B/_report" | tr -d '\r' | grep -ciE "^content-security-policy: default-src 'self';.*img-src 'self' data:;.*frame-src 'none'|^referrer-policy: no-referrer$" || true)|$(curl -s -D - -o /dev/null "$B/" | tr -d '\r' | grep -ciE '^content-security-policy:|^referrer-policy: no-referrer$' || true)" "2|0"
grep -q 'compliance officer' "$WORK/response" && grep -q 'noindex' "$WORK/response" && ! grep -q 'googletagmanager\|data-souhlas=\|cookies-lista\|<script src="https://\|challenges.cloudflare.com\|cf-turnstile' "$WORK/response" \
  && echo "  ok     whistleblowing: the page is not indexed and carries no tracking code, consent bar, CAPTCHA or third-party script" || { echo "  CHYBA  whistleblowing page privacy"; ERRORS=$((ERRORS+1)); }
cp "$WORK/response" "$WORK/formular.html"; WB_TIME=$(field_value as_cas); WB_SIGNATURE=$(field_value as_podpis)
sleep 4
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Vedoucí skladu falšuje evidenci docházky." -F name= -F contact= -F "files[]=@$WORK/cv.pdf")
WB_NUMBER=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//'); WB_CODE=$(grep -o 'ka-oznameni-kod">[A-Z0-9-]*' "$WORK/response" | sed 's/.*>//')
expect "whistleblowing: an anonymous report with an attachment got the first case number of the year and a code (3.3.3: no CAPTCHA answer needed)" "$WB_HTTP|$WB_NUMBER|$(printf '%s' "$WB_CODE" | tr -d '-' | wc -c | tr -d ' ')" "200|$(date +%Y)-0001|20"
sq "DELETE FROM ka_nastaveni WHERE promenna LIKE 'captcha_%'" > /dev/null
expect "whistleblowing: only a hash of the code is stored; no plaintext of the report, no contact (anonymous), the attachment outside the web root" \
  "$(sq "SELECT CONCAT(LENGTH(code_hash), '|', code_hash LIKE '%$(printf '%s' "$WB_CODE" | tr -d '-')%', '|', text LIKE '%docházky%', '|', text LIKE '%Vedouc%', '|', contact IS NULL, '|', status, '|', attachments IS NOT NULL) FROM ka_whistleblowing_cases WHERE number = '$WB_NUMBER'")|$(ls "$WORK/web/storage/oznameni/$(date +%Y)/" | wc -l | tr -d ' ')" "64|0|0|0|1|received|1|1"
expect "whistleblowing: the event names the case only" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(message LIKE '%$WB_NUMBER%'), '|', MAX(message LIKE '%docházky%')) FROM ka_events WHERE type = 'whistleblowing.received'")" "1|1|0"
F=$(grep -l "^X-Rcpt-To: wb-reader@example.cz" "$WORK"/smtp-wb/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
grep -q "$WB_NUMBER" "$WORK/eml.txt" && ! grep -q 'docházky\|dochazky' "$WORK/eml.txt" && echo "  ok     whistleblowing: the reader's e-mail names the case number and carries no content" || { echo "  CHYBA  whistleblowing e-mail"; head -20 "$WORK/eml.txt"; ERRORS=$((ERRORS+1)); }
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report/follow" -d "number=$WB_NUMBER" --data-urlencode "code=$WB_CODE")
[ "$WB_HTTP" = 200 ] && grep -q 'data-stav="received"' "$WORK/response" && grep -q "$WB_NUMBER" "$WORK/response" && grep -q 'name="reply"' "$WORK/response" \
  && echo "  ok     whistleblowing: the follow-up with the code shows the status and a reply box" || { echo "  CHYBA  whistleblowing follow-up: HTTP $WB_HTTP"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d "code=$(printf '%s' "$WB_CODE" | tr 'A-Z' 'a-z' | tr -d '-')" --data-urlencode "reply=Doplňuji: děje se to každé pondělí."
grep -q 'ka-oznameni-zprava--reporter' "$WORK/response" && expect "whistleblowing: the reporter added information (the code typed in lower case without dashes); the message is encrypted" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(sender), '|', MAX(text LIKE '%pondělí%')) FROM ka_whistleblowing_messages")" "1|reporter|0" || { echo "  CHYBA  whistleblowing reply"; ERRORS=$((ERRORS+1)); }
expect "whistleblowing: a wrong code is refused" "$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d code=ABCDE-FGHJK-MNPQR-STUVW)|$(grep -c 'data-stav=' "$WORK/response")" "403|0"
for i in 1 2 3 4 5 6 7 8 9; do curl -s -o /dev/null -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d code=WRONG$i; done
expect "whistleblowing: after ten wrong codes the address waits an hour, even with the right code" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_report/follow" -d "number=$WB_NUMBER" --data-urlencode "code=$WB_CODE")|$(sq "SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE number = '$WB_NUMBER'")" "429|1"
# 3.3.2 (N35): a wrong code leaves only a short keyed hash of the address with a daily salt, never the old unkeyed sha256
expect "3.3.2 whistleblowing: a wrong code is recorded as a short keyed bucket, not as a hash of the address" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MIN(ip_adresa LIKE 'wb:%'), '|', MAX(LENGTH(ip_adresa)), '|', SUM(ip_adresa = LEFT(SHA2('kaleta|127.0.0.1', 256), 40))) FROM ka_kontrola_ip WHERE typ = 'oznameni'")" "10|1|8|0"
# 26 hours: the code forgets rows 24 hours back, which is 23–25 hours of wall-clock time on the days the clocks change
sq "UPDATE ka_kontrola_ip SET cas = '$(site_time)' - INTERVAL 26 HOUR WHERE typ = 'oznameni' LIMIT 3" > /dev/null
curl -s -o /dev/null -X POST "$B/_report/follow" -d "number=$WB_NUMBER" -d code=WRONG10
expect "3.3.2 whistleblowing: rows older than a day are forgotten on the next write" "$(sq "SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'oznameni' AND cas < '$(site_time)' - INTERVAL 1 DAY")" "0"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni'" > /dev/null # an hour has passed
# 3.3.2 (N28): five reports a day from one address bucket, then a kind "try again later" that keeps the text
for i in 2 3 4 5; do curl -s -o /dev/null -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Report number $i" -F name= -F contact=; done
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=The sixth report today" -F name= -F contact=)
expect "3.3.2 whistleblowing: the sixth report of the day from one address waits, kindly, with the text kept; the sent rows carry the day only" \
  "$WB_HTTP|$(grep -c 'Další oznámení teď nemůžeme přijmout' "$WORK/response")|$(grep -c 'The sixth report today' "$WORK/response")|$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_whistleblowing_cases), '|', (SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'oznameni-den' AND TIME(cas) = '00:00:00' AND ip_adresa LIKE 'wb:%'))")" "429|1|1|5|5"
# the site-wide hourly cap: twenty reports in the last hour from anywhere – stamped with the time the site itself wrote for the
# last report (the site's time zone), not MySQL's NOW(), which runs in UTC on CI
WB_LAST=$(sq "SELECT MAX(created_at) FROM ka_whistleblowing_cases")
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den';
  INSERT INTO ka_whistleblowing_cases (number, created_at, status, feedback_due, text, code_hash) SELECT CONCAT('1999-', LPAD(seq, 4, '0')), '$WB_LAST', 'received', '$WB_LAST' + INTERVAL 3 MONTH, 'x', REPEAT('b', 64)
  FROM (SELECT 1 seq UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15) s" > /dev/null
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Over the hourly cap" -F name= -F contact=)
WB_FLOOD=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
# 3.3.3 (N57): over the hourly cap of the channel a genuine reporter is no longer refused – the case is accepted and marked for the readers
expect "3.3.3 whistleblowing: over the hourly cap of the channel the report is accepted and marked as received during a flood" \
  "$WB_HTTP|$(grep -c 'ka-oznameni-kod' "$WORK/response")|$(sq "SELECT CONCAT(flood, '|', (SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE flood = 1)) FROM ka_whistleblowing_cases WHERE number = '$WB_FLOOD'")" "200|1|1|1"
check "3.3.3 whistleblowing: the list marks the case received during a flood" 200 "/admin.php?module=whistleblowing" "přijato během náporu"
sq "DELETE FROM ka_whistleblowing_cases WHERE number = '$WB_FLOOD'; DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'; UPDATE ka_whistleblowing_cases SET created_at = '$(site_time)' - INTERVAL 2 HOUR WHERE number LIKE '1999-%'" > /dev/null
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Under the hourly cap again" -F name= -F contact=)
WB_CALM=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
expect "3.3.3 whistleblowing: below the hourly cap a report carries no flood mark" "$WB_HTTP|$(sq "SELECT flood FROM ka_whistleblowing_cases WHERE number = '$WB_CALM'")" "200|0"
sq "DELETE FROM ka_whistleblowing_cases WHERE number = '$WB_CALM'; DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'" > /dev/null
# the storage cap for attachments: above it the report goes through only without new attachments
php -r '$f = fopen($argv[1], "w"); ftruncate($f, 1100 * 1048576); fclose($f);' "$WORK/web/storage/oznameni/$(date +%Y)/full.bin"
WB_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=With a file over the storage cap" -F name= -F contact= -F "files[]=@$WORK/cv.pdf")
WB_HTTP2=$(curl -s -o "$WORK/response2" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$WB_TIME" -F "as_podpis=$WB_SIGNATURE" --form-string "text=Without a file over the storage cap" -F name= -F contact=)
WB_NOFILE=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
# 3.3.3 (N57): as the docblock promises – the text goes through without the attachment, and the reporter is told so
expect "3.3.3 whistleblowing: over the attachment storage cap the report is accepted without its attachment, and the reporter is told kindly" \
  "$WB_HTTP|$(grep -c 'Přílohy teď nemůžeme uložit' "$WORK/response")|$(grep -c 'ka-oznameni-kod' "$WORK/response")|$(sq "SELECT attachments IS NULL FROM ka_whistleblowing_cases WHERE number = '$WB_NOFILE'")|$WB_HTTP2|$(grep -c 'ka-oznameni-kod' "$WORK/response2")" "200|1|1|1|200|1"
rm -f "$WORK/web/storage/oznameni/$(date +%Y)/full.bin"
sq "DELETE FROM ka_whistleblowing_cases WHERE number <> '$WB_NUMBER'; DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'" > /dev/null
WB_ID=$(sq "SELECT id FROM ka_whistleblowing_cases WHERE number = '$WB_NUMBER'")
check "whistleblowing: the reader opens the case with the decrypted report and the reporter's message" 200 "/admin.php?module=whistleblowing&action=detail&id=$WB_ID" "falšuje evidenci docházky"
grep -q 'pondělí' "$WORK/response" && grep -q 'action=attachment' "$WORK/response" && echo "  ok     whistleblowing: the detail lists the message and the attachment" || { echo "  CHYBA  whistleblowing detail"; ERRORS=$((ERRORS+1)); }
expect "whistleblowing: the reader downloads the attachment" "$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=attachment&id=$WB_ID&index=0")|$(head -c 8 "$WORK/response")" "200|%PDF-1.4"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=whistleblowing&action=reply" -d "_csrf=$(wb_csrf)" -d "id=$WB_ID" --data-urlencode "text=Děkujeme, prošetřujeme."
expect "whistleblowing: the handler's first answer acknowledges the receipt" "$(sq "SELECT CONCAT(status, '|', acknowledged_at IS NOT NULL, '|', (SELECT COUNT(*) FROM ka_whistleblowing_messages WHERE sender = 'handler' AND text NOT LIKE '%prošetřujeme%')) FROM ka_whistleblowing_cases WHERE id = $WB_ID")" "acknowledged|1|1"
curl -s -o "$WORK/response" -X POST "$B/_report/follow" -d "number=$WB_NUMBER" --data-urlencode "code=$WB_CODE"
grep -q 'data-stav="acknowledged"' "$WORK/response" && grep -q 'prošetřujeme' "$WORK/response" && echo "  ok     whistleblowing: the reporter sees the new status and the handler's answer" || { echo "  CHYBA  whistleblowing reporter view"; ERRORS=$((ERRORS+1)); }
# another administrator is not a reader: the list with numbers and dates, no detail
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(wb_csrf)" -d idu=0 -d jmeno=Druhy -d user=druhy-spravce --data-urlencode "password=$PASSWORD" -d admin=2
JAR_WB="$WORK/cookies-wb.txt"; curl -s -c "$JAR_WB" -o "$WORK/response" "$B/admin.php"
curl -s -b "$JAR_WB" -c "$JAR_WB" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=druhy-spravce --data-urlencode "password=$PASSWORD"
WB_LIST=$(curl -s -b "$JAR_WB" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=whistleblowing"); WB_SEEN=$(grep -c "$WB_NUMBER" "$WORK/response" || true); WB_LINK=$(grep -c "action=detail&amp;id=$WB_ID" "$WORK/response" || true)
WB_DETAIL=$(curl -s -b "$JAR_WB" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=detail&id=$WB_ID")
expect "whistleblowing: another administrator sees the case number in the list without a link and cannot open the case" "$WB_LIST|$([ "$WB_SEEN" -ge 1 ] && echo 1 || echo 0)|$WB_LINK|$WB_DETAIL|$(grep -c 'docházky' "$WORK/response")" "200|1|0|403|0"
# (the raw tools/list: mcp_list of 3.10 takes a token and prints counts)
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/tools-list"
contains -q '"name":"list_pages"' "$WORK/tools-list" && ! contains -q 'whistleblowing' "$WORK/tools-list" && echo "  ok     MCP: tools/list has no whistleblowing tool" || { echo "  CHYBA  MCP: a whistleblowing tool is listed, or the list is empty"; head -c 300 "$WORK/tools-list"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"; expect "MCP: site_info says only that the channel is on" "$(mcp_value whistleblowing)" "1"
# the daily job: a reminder of an overdue acknowledgement, a closed case past the retention deleted
sq "UPDATE ka_whistleblowing_cases SET created_at = '$(site_time)' - INTERVAL 8 DAY, acknowledged_at = NULL, status = 'received' WHERE id = $WB_ID;
  INSERT INTO ka_whistleblowing_cases (number, created_at, status, acknowledged_at, feedback_due, closed_at, text, contact, attachments, code_hash) VALUES ('2023-0001', '$(site_time)' - INTERVAL 30 MONTH, 'closed', '$(site_time)' - INTERVAL 30 MONTH, '$(site_time)' - INTERVAL 27 MONTH, '$(site_time)' - INTERVAL 25 MONTH, 'x', NULL, NULL, REPEAT('a', 64));
  UPDATE ka_jobs SET last_run = NULL WHERE name = 'whistleblowing'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "whistleblowing: the daily job records the overdue acknowledgement and deletes the closed case past the retention" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'whistleblowing.due' AND data LIKE '%\"deadline\":\"acknowledgement\"%' AND message LIKE '%$WB_NUMBER%'), '|', (SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE number = '2023-0001'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'whistleblowing.purged'))")" "1|0|1"
check "whistleblowing: the list highlights the overdue acknowledgement" 200 "/admin.php?module=whistleblowing" "po lhůtě"
