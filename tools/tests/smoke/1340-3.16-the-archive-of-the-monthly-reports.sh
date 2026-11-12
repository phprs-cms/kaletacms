echo "== 3.16: the archive of the monthly reports"
# what happened last month: a request (its title names an address), an update, firewall blocks, failed sign-ins, changes by
# a person and by Claude – the job stores the month once, whether or not the e-mail is on; nothing personal gets in
# earlier checks age rows by a few days, so on the first days of a month they would land in last month: clear it first
MONTH_END=$(php -r 'echo (new DateTimeImmutable("first day of this month"))->format("Y-m-d");')
sq "DELETE FROM ka_protokol WHERE cas >= '$LAST_MONTH-01' AND cas < '$MONTH_END'; DELETE FROM ka_firewall_log WHERE created_at >= '$LAST_MONTH-01' AND created_at < '$MONTH_END';
  DELETE FROM ka_events WHERE created_at >= '$LAST_MONTH-01' AND created_at < '$MONTH_END' AND type <> 'report.sent'; DELETE FROM ka_requests WHERE created_at >= '$LAST_MONTH-01' AND created_at < '$MONTH_END';
  DELETE FROM ka_agent_runs WHERE due_at >= '$LAST_MONTH-01' AND due_at < '$MONTH_END'" > /dev/null
sq "DELETE FROM ka_monthly_reports; UPDATE ka_jobs SET last_run = NULL WHERE name = 'report_archive'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'report_monthly';
  INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, status, done_at) SELECT '$LAST_MONTH-03 10:00:00', '$LAST_MONTH-04 10:00:00', idu, 'Opening hours 316 – ask visitor316@example.org', 'x', 'done', '$LAST_MONTH-04 10:00:00' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_events (created_at, type, severity, message, data) VALUES ('$LAST_MONTH-10 04:00:00', 'update.applied', 'info', 'Version 3.16.0 was installed (from 3.15.4).', '{\"version\":\"3.16.0\",\"from\":\"3.15.4\"}');
  INSERT INTO ka_firewall_log (created_at, ip, reason, path) VALUES ('$LAST_MONTH-11 01:00:00', '203.0.113.9', 'probe', '/wp-login.php'), ('$LAST_MONTH-12 01:00:00', '203.0.113.9', 'probe', '/.env');
  INSERT INTO ka_protokol (cas, kdo, jmeno, via, modul, akce, popis) VALUES ('$LAST_MONTH-05 09:00:00', NULL, 'x', '', 'prihlaseni', 'neuspech', 'account: someone@example.org'),
    ('$LAST_MONTH-05 09:10:00', 1, 'Admin', '', 'pages', 'save', 'Home'), ('$LAST_MONTH-06 09:00:00', 1, 'Admin', 'Claude 316', 'claude', 'save_collection_item', 'x'),
    ('$LAST_MONTH-07 09:00:00', 1, 'Admin', '', 'whistleblowing', 'status', 'W316: closed');
  INSERT INTO ka_agent_schedules (name, task, text, cadence, day, time, active, next_due, created_at) VALUES ('Triage 316 for Jana Nováková', 'triage', '', 'weekly', 1, '07:00', 0, NULL, '$LAST_MONTH-01 08:00:00');
  INSERT INTO ka_agent_runs (schedule_id, due_at, finished_at, status, summary) SELECT id, '$LAST_MONTH-08 07:00:00', '$LAST_MONTH-08 07:20:00', 'ok', 'Triaged 4: Jana Nováková (+420 777 316 999) wants a roof in Brno' FROM ka_agent_schedules WHERE name = 'Triage 316 for Jana Nováková';
  INSERT INTO ka_monthly_reports (month, created_at, schema_version, data) VALUES ('2001-01', '2001-02-01 00:00:00', 1, '{}')" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'report_archive'" > /dev/null
curl -s -o "$WORK/tasks2.txt" "$B/ulohy?token=testtoken123"
grep -q "report_archive: stored $LAST_MONTH, removed 1" "$WORK/tasks.txt" && grep -q "report_archive: stored already" "$WORK/tasks2.txt" \
  && echo "  ok     the job stores the previous month once with the e-mail off, and removes months older than 24" || { echo "  CHYBA  3.16 report_archive job"; grep report_archive "$WORK/tasks.txt" "$WORK/tasks2.txt"; ERRORS=$((ERRORS+1)); }
expect "the archive: one row for last month, none for 2001" "$(sq "SELECT CONCAT(SUM(month = '$LAST_MONTH'), '|', SUM(month = '2001-01'), '|', MAX(schema_version)) FROM ka_monthly_reports")" "1|0|1"
expect "the stored JSON: the request, the update, the firewall and the sign-in counts, the areas – and no \"@\" anywhere" \
  "$(sq "SELECT CONCAT(data LIKE '%Opening hours 316%', data LIKE '%\"from\":\"3.15.4\"%', data LIKE '%\"probe\":2%', data LIKE '%\"failed_sign_ins\":1%', data LIKE '%\"collections\":{\"people\":0,\"claude\":1}%', LOCATE('@', data) = 0) FROM ka_monthly_reports WHERE month = '$LAST_MONTH'")" "111111"
expect "3.16 N316-1/-3/-9: scheduled runs by task and result only (no name, no summary), no whistleblowing change counted, moments as ISO 8601 with the offset" \
  "$(sq "SELECT CONCAT(data LIKE '%\"run_tasks\":{\"triage\":{\"ok\":1}}%', LOCATE('Nováková', data) = 0, LOCATE('316 999', data) = 0, LOCATE('Brno', data) = 0, LOCATE('\"other\"', data) = 0,
    data REGEXP '\"generated_at\":\"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\"') FROM ka_monthly_reports WHERE month = '$LAST_MONTH'")" "111111"
check "Settings → Reports lists the stored month" 200 "/admin.php?module=settings&tab=reports" "action=report_view&amp;month=$LAST_MONTH"
grep -q 'action=report_download&amp;month=' "$WORK/response" && grep -q 'action=report_rebuild' "$WORK/response" && grep -q 'data-potvrdit=' "$WORK/response" && TOKEN=$(csrf) \
  && echo "  ok     each month: view, download, send and rebuild (with a warning)" || { echo "  CHYBA  3.16 Reports tab actions"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -D "$WORK/headers" -o "$WORK/response" "$B/admin.php?module=settings&action=report_view&month=$LAST_MONTH"
grep -q 'size: A4' "$WORK/response" && grep -q 'counter(pages)' "$WORK/response" && grep -q 'data-tisk' "$WORK/response" && grep -q 'Opening hours 316' "$WORK/response" && ! grep -q 'example.org' "$WORK/response" \
  && grep -qi "content-security-policy: default-src 'none'" "$WORK/headers" && echo "  ok     the printable page: A4, page numbers, the Print button, its own CSP, nothing personal" || { echo "  CHYBA  3.16 report_view"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -D "$WORK/headers" -o "$WORK/response" "$B/admin.php?module=settings&action=report_download&month=$LAST_MONTH"
grep -qi "content-disposition: attachment; filename=\"website-report-[a-z0-9.-]*-$LAST_MONTH.html\"" "$WORK/headers" && grep -q '<h1>' "$WORK/response" && ! grep -q '<script' "$WORK/response" \
  && echo "  ok     download: the same page as an HTML file, without the script" || { echo "  CHYBA  3.16 report_download"; grep -i disposition "$WORK/headers"; ERRORS=$((ERRORS+1)); }
check "a month that is not archived: 404" 404 "/admin.php?module=settings&action=report_view&month=1999-01"
# N3261-3: the counters of the daily checks move on after the month – a rebuild keeps the runs counted when it was archived
RUNS3261=$(sq "SELECT JSON_EXTRACT(data, '\$.care.job_runs') FROM ka_monthly_reports WHERE month = '$LAST_MONTH'")
JOB3261=$(sq "SELECT COUNT(*) FROM ka_jobs WHERE name = 'domain_watch'")
sq "INSERT INTO ka_jobs (name, runs) VALUES ('domain_watch', 100) ON DUPLICATE KEY UPDATE runs = runs + 100" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=report_rebuild" -d "_csrf=$TOKEN" -d "month=$LAST_MONTH"
expect "rebuild from the data still available: the row is replaced and marked" "$(sq "SELECT rebuilt_at IS NOT NULL FROM ka_monthly_reports WHERE month = '$LAST_MONTH'")" "1"
expect "3.26.1 (N3261-3): the rebuilt month keeps the runs of the daily checks counted when it was archived, not today's counters" \
  "$(sq "SELECT JSON_EXTRACT(data, '\$.care.job_runs') FROM ka_monthly_reports WHERE month = '$LAST_MONTH'")" "$RUNS3261"
if [ "$JOB3261" = 0 ]; then sq "DELETE FROM ka_jobs WHERE name = 'domain_watch'" > /dev/null; else sq "UPDATE ka_jobs SET runs = runs - 100 WHERE name = 'domain_watch'" > /dev/null; fi
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&action=report_view&month=$LAST_MONTH"
grep -q 'when the report was rebuilt – not at the end of the month\|kdy byla zpráva znovu sestavena – ne ke konci měsíce' "$WORK/response" && ! grep -q 'Co čekalo na konci měsíce\|Waiting at the end of the month' "$WORK/response" \
  && echo "  ok     3.16 N316-7: a rebuilt report says that what waits, the problems and the audit are from the day of the rebuild" || { echo "  CHYBA  N316-7 rebuilt report labels"; ERRORS=$((ERRORS+1)); }
# sending an older archived month never makes the job send the last one twice
OLDER=$(php -r 'echo (new DateTimeImmutable("first day of -3 months"))->format("Y-m");')
sq "INSERT INTO ka_monthly_reports (month, created_at, schema_version, data) VALUES ('$OLDER', '$(site_time)', 1, '{\"month\":\"$OLDER\"}'); UPDATE ka_nastaveni SET hodnota = '$LAST_MONTH' WHERE promenna = 'report_last_month'" > /dev/null
BEFORE=$(REPORT_MAILS)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=report_send" -d "_csrf=$TOKEN" -d "month=$OLDER"
expect "send by e-mail an archived month: queued, the last sent month kept" "$(( $(REPORT_MAILS) - BEFORE ))|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_last_month'")" "2|$LAST_MONTH"
expect "a news author may not view or download reports" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=settings&action=report_view&month=$LAST_MONTH")|$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=settings&action=report_download&month=$LAST_MONTH")" "403|403"
mcp list_monthly_reports '{}' > "$WORK/response"
expect "MCP list_monthly_reports: the months, newest first" "$(mcp_value reports 0 month)|$(mcp_value kept_months)" "$LAST_MONTH|24"
mcp get_monthly_report "{\"month\":\"$LAST_MONTH\"}" > "$WORK/response"
expect "MCP get_monthly_report: the data and the printable page" "$(mcp_value report month)|$(mcp_value report requests done_count)|$(mcp_value print_url | grep -c "action=report_view&month=$LAST_MONTH")" "$LAST_MONTH|1|1"
contains -q 'example.org' "$WORK/response" && { echo "  CHYBA  3.16 MCP report carries an address"; ERRORS=$((ERRORS+1)); } || echo "  ok     MCP: nothing personal in the report"
AUTHOR316="kaleta_$(printf 'c%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'author 316', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$AUTHOR316")', '$(site_time)' FROM ka_uzivatele WHERE user = 'autor'" > /dev/null
mcp_as "$AUTHOR316" get_monthly_report "{\"month\":\"$LAST_MONTH\"}" > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && contains -q 'administrator' "$WORK/response" && echo "  ok     MCP: a non-administrator is refused the reports" || { echo "  CHYBA  3.16 MCP refuses non-admins"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_requests WHERE title LIKE 'Opening hours 316%'; DELETE FROM ka_api_tokeny WHERE nazev = 'author 316'; DELETE FROM ka_events WHERE type = 'update.applied' AND message = 'Version 3.16.0 was installed (from 3.15.4).';
  DELETE FROM ka_firewall_log WHERE ip = '203.0.113.9' AND created_at LIKE '$LAST_MONTH-%'; DELETE FROM ka_protokol WHERE via = 'Claude 316' OR (modul = 'prihlaseni' AND popis = 'account: someone@example.org') OR popis = 'W316: closed';
  DELETE FROM ka_agent_schedules WHERE name = 'Triage 316 for Jana Nováková'" > /dev/null

