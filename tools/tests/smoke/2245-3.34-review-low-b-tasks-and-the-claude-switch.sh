echo "== 3.34 review low (b): with applications_claude off no task about an application reaches Claude, on any task path"
r334_on
LB_ME=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
LB_A=$(sq "INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at) VALUES ('$(site_time)', 'LB', '[]', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
LB_T=$(sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES ('Call the welder LB', 'application:$LB_A', $LB_ME, $LB_ME, '$(site_time '-1 day')', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_claude', '0')" > /dev/null
lb_has() { mcp list_tasks "$1" > "$WORK/response"; grep -c 'Call the welder LB' "$WORK/response"; }
expect "3.34 review low (b): switch off – list_tasks mine, overdue and assignee leave the task out" "$(lb_has '{}')|$(lb_has '{"filter":"overdue"}')|$(lb_has "{\"filter\":\"assignee:$LB_ME\"}")" "0|0|0"
mcp complete_task "{\"id\":$LB_T}" > "$WORK/response"
LB_DONE=$(grep -c 'Claude may not read job applications' "$WORK/response")
mcp save_task "{\"id\":$LB_T,\"title\":\"Changed LB\"}" > "$WORK/response"
expect "3.34 review low (b): switch off – complete_task and save_task with its id are refused, nothing changes" \
  "$LB_DONE|$(grep -c 'Claude may not read job applications' "$WORK/response")|$(sq "SELECT CONCAT(title, '|', done_at IS NULL) FROM ka_tasks WHERE id = $LB_T")" "1|1|Call the welder LB|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_claude', '1')" > /dev/null
expect "3.34 review low (b): switch on – the task is listed again" "$(lb_has '{}')" "1"
sq "DELETE FROM ka_tasks WHERE id = $LB_T" > /dev/null
t334_php 'Kaleta\Core\Applications::delete($app, ['"$LB_A"'], false);' > /dev/null
r334_off
