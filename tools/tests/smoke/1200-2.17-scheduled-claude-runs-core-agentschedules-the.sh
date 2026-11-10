echo "== 2.17: scheduled Claude runs (Core\\AgentSchedules) – the site keeps the schedule, a routine in Claude does the runs as drafts"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=schedules"; TOKEN=$(csrf)
grep -q 'get_due_agent_runs' "$WORK/response" && grep -q "$B/mcp" "$WORK/response" && grep -q 'action=account#claude' "$WORK/response" && echo "  ok     schedules: the Set up in Claude panel has the routine prompt with the MCP address and the link to the tokens" || { echo "  CHYBA  schedules panel"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=schedules&action=save" -d "_csrf=$TOKEN" -d id=0 --data-urlencode "name=Weekly review" -d task=review --data-urlencode "text=Only the Services pages." -d cadence=weekly -d weekday=1 -d monthday=1 -d "time=07:00" -d active=1
SCHED=$(sq "SELECT id FROM ka_agent_schedules WHERE name = 'Weekly review'"); SCHED="${SCHED:-0}"
expect "schedules: saved, active, next due the coming Monday 07:00" "$(sq "SELECT CONCAT(active, '|', cadence, '|', day, '|', time, '|', next_due > '$(site_time)', '|', DAYOFWEEK(next_due), '|', TIME(next_due)) FROM ka_agent_schedules WHERE id = $SCHED")" "1|weekly|1|07:00|1|2|07:00:00"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=schedules&action=save" -d "_csrf=$TOKEN" -d id=0 --data-urlencode "name=Bad" -d task=custom -d text= -d cadence=monthly -d weekday=1 -d monthday=31 -d "time=07:00" -d active=1
expect "schedules: custom instructions without a text are refused" "$(sq "SELECT COUNT(*) FROM ka_agent_schedules WHERE name = 'Bad'")" "0"
# nothing due yet; a schedule due in the past is handed out once – with the task text, the administrator's extra and the rules
mcp_as "$DRAFT_TOKEN" get_due_agent_runs '{}' > "$WORK/response"
expect "schedules: nothing due – the drafts-only connection gets an empty list" "$(mcp_value count)" "0"
sq "UPDATE ka_agent_schedules SET next_due = '$(site_time)' - INTERVAL 1 HOUR WHERE id = $SCHED" > /dev/null
# 3.15 (N45): a read-only connection takes no run – it could never report it
expect "3.15 N45: a read-only connection is refused by get_due_agent_runs and the due run stays for the routine" \
  "$(mcp_as "$READ_TOKEN" get_due_agent_runs '{}' | grep -c 'read-only connection cannot take scheduled runs')|$(sq "SELECT COUNT(*) FROM ka_agent_runs WHERE schedule_id = $SCHED AND started_at IS NOT NULL")" "1|0"
mcp_as "$DRAFT_TOKEN" get_due_agent_runs '{}' > "$WORK/response"; mcp_text
RUN=$(mcp_value runs 0 id); RUN="${RUN:-0}"
contains -q '"name":"Weekly review"' "$WORK/text" && contains -q 'Run site_audit' "$WORK/text" && contains -q 'Also: Only the Services pages.' "$WORK/text" && contains -q 'never publish' "$WORK/text" && contains -q '"connection":"drafts only' "$WORK/text" && echo "  ok     schedules: get_due_agent_runs hands the run out with the task text, the administrator's extra and the rules" || { echo "  CHYBA  get_due_agent_runs"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" get_due_agent_runs '{}' > "$WORK/response"
expect "schedules: a second call returns the same open run – one row, running, the connection remembered" "$(mcp_value runs 0 id)|$(sq "SELECT CONCAT(COUNT(*), '|', MAX(status), '|', MAX(connection)) FROM ka_agent_runs WHERE schedule_id = $SCHED")" "$RUN|1|running|Claude drafts"
mcp_as "$DRAFT_TOKEN" report_agent_run "{\"id\":$RUN,\"status\":\"ok\",\"summary\":\"Audit clean, two descriptions drafted.\",\"links\":[{\"label\":\"Services – draft\",\"url\":\"$B/sluzby\"},{\"url\":\"javascript:alert(1)\"}]}" > "$WORK/response"; mcp_text
contains -q '"status":"ok"' "$WORK/text" && contains -q '"next_due":"' "$WORK/text" && echo "  ok     schedules: report_agent_run finishes the run and tells the next due time" || { echo "  CHYBA  report_agent_run"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "schedules: the run is ok with the web link only, last_run_at set, next_due moved on to the next Monday 07:00" "$(sq "SELECT CONCAT(r.status, '|', r.finished_at IS NOT NULL, '|', r.links LIKE '%$B/sluzby%' AND r.links NOT LIKE '%javascript%', '|', s.last_run_at IS NOT NULL, '|', s.next_due > '$(site_time)' AND s.next_due <= '$(site_time)' + INTERVAL 7 DAY, '|', DAYOFWEEK(s.next_due), '|', TIME(s.next_due)) FROM ka_agent_runs r JOIN ka_agent_schedules s ON s.id = r.schedule_id WHERE r.id = $RUN")" "ok|1|1|1|1|2|07:00:00"
mcp_as "$DRAFT_TOKEN" report_agent_run "{\"id\":$RUN,\"status\":\"ok\",\"summary\":\"again\"}" > "$WORK/response"
contains -q 'already reported' "$WORK/response" && echo "  ok     schedules: a run is reported once" || { echo "  CHYBA  report twice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" publish_build '{"id":1}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     schedules: the same drafts-only connection cannot publish" || { echo "  CHYBA  drafts connection published"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# a schedule 7 hours overdue that nobody picked up: the hourly job marks it missed, moves it on and records the warning the alerts send
sq "UPDATE ka_agent_schedules SET next_due = '$(site_time)' - INTERVAL 7 HOUR WHERE id = $SCHED; UPDATE ka_jobs SET last_run = NULL WHERE name = 'agent_runs'" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "agent_runs: missed 1" "$WORK/tasks.txt" && echo "  ok     schedules: the job reports the missed run" || { echo "  CHYBA  agent_runs job"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "schedules: a missed run, next_due in the future, the event agent_run.missed as a warning with the schedule id" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_agent_runs WHERE schedule_id = $SCHED AND status = 'missed'), '|', (SELECT next_due > '$(site_time)' FROM ka_agent_schedules WHERE id = $SCHED), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'agent_run.missed' AND severity = 'warning' AND data LIKE '%\"schedule\":$SCHED,%'))")" "1|1|1"
check "schedules: the list shows the last run status" 200 "/admin.php?module=schedules" "Zmeškaný"
check "schedules: the history shows the summary of the reported run" 200 "/admin.php?module=schedules&action=history&id=$SCHED" "Audit clean, two descriptions drafted."
grep -q "href=\"$B/sluzby\"" "$WORK/response" && ! grep -q 'javascript:' "$WORK/response" && echo "  ok     schedules: the history links the draft, the bad link never got in" || { echo "  CHYBA  history link"; ERRORS=$((ERRORS+1)); }
expect "schedules: tools/list of a drafts-only connection offers report_agent_run (catalog: draft)" "$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -o '"name":"report_agent_run"' | wc -l | tr -d ' ')" "1"
