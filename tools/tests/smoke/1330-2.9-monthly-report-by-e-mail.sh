echo "== 2.9: monthly report by e-mail"
REPORT_MAILS() { sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%Zpráva o webu%' OR predmet LIKE '%Website report%'"; }
LAST_MONTH=$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m");')
sq "INSERT INTO ka_nastaveni VALUES ('report_monthly','1'),('report_recipients','owner@example.cz'),('report_last_month','') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=mail"; grep -q 'tab=reports' "$WORK/response" && ! grep -q 'name="report_recipients"' "$WORK/response" \
  && echo "  ok     3.16: Settings → Mail points to the Reports tab" || { echo "  CHYBA  3.16 Mail tab: the pointer to Reports"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=reports"; TOKEN=$(csrf)
grep -q 'name="report_recipients"' "$WORK/response" && grep -q 'action=report_preview' "$WORK/response" && grep -q 'action=report_send' "$WORK/response" && echo "  ok     Settings → Reports has the monthly e-mail with its preview and send buttons" || { echo "  CHYBA  Reports tab: monthly report"; ERRORS=$((ERRORS+1)); }
check "the preview is the e-mail of the last month with the site name" 200 "/admin.php?module=settings&action=report_preview" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")"
grep -q 'max-width:600px' "$WORK/response" && grep -q 'Studio Test' "$WORK/response" && ! grep -q 'spravce@example.cz' "$WORK/response" && echo "  ok     the preview is an inline-styled e-mail with the agency, without the site e-mail" || { echo "  CHYBA  report preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=report_send" -d "_csrf=$TOKEN"
expect "send now: the report is queued for the recipient" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'owner@example.cz' AND (predmet LIKE '%Zpráva o webu%' OR predmet LIKE '%Website report%')")" "1"
expect "send now remembers the month, so the job does not send it again" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_last_month'")" "$LAST_MONTH"
# the job: with the month forgotten it sends once, the second run finds it sent
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'report_last_month'; UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'" > /dev/null
BEFORE=$(REPORT_MAILS)
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'" > /dev/null
curl -s -o "$WORK/tasks2.txt" "$B/ulohy?token=testtoken123"
expect "the job sends the previous month once: two runs, one more report" "$(( $(REPORT_MAILS) - BEFORE ))" "1"
grep -q "monthly_report: sent to 1" "$WORK/tasks.txt" && grep -q "monthly_report: sent already" "$WORK/tasks2.txt" && echo "  ok     the job reports what it did" || { echo "  CHYBA  monthly_report job"; cat "$WORK/tasks.txt" "$WORK/tasks2.txt"; ERRORS=$((ERRORS+1)); }
expect "report.sent events carry the month and the count, never an address" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'report.sent' AND data LIKE '%\"month\":\"$LAST_MONTH\"%' AND data NOT LIKE '%@%'")" "2"
# recipients are validated on save: one bad address rejects the field, good ones are kept one per line
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=reports -d report_monthly=1 --data-urlencode "report_recipients=owner@example.cz, nonsense"
expect "recipients: an invalid address is not saved" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_recipients'")" "owner@example.cz"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=reports --data-urlencode "report_recipients=owner@example.cz, agentura@example.cz"
expect "recipients: valid addresses are saved one per line, the switch off when unchecked" "$(sq "SELECT CONCAT(REPLACE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_recipients'), '\n', '|'), ':', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_monthly'))")" "owner@example.cz|agentura@example.cz:0"

