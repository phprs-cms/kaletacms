echo "== 3.27 (A): tasks and follow-ups – the reminder job, My work, MCP, the hand-over and the clean-up"
[ -z "${SMTP_PID:-}" ] || kill "$SMTP_PID" 2>/dev/null || true
T327_SMTP="$WORK/smtp-327"; mkdir -p "$T327_SMTP"
for i in $(seq 1 20); do (exec 3<>"/dev/tcp/127.0.0.1/$SMTP2_PORT") 2>/dev/null || break; sleep 0.2; done # the previous sink let the port go
command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$T327_SMTP" > /dev/null 2>&1 & SMTP_PID=$!
sq "DROP TABLE IF EXISTS ka_t327_mail; CREATE TABLE ka_t327_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
T327_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
# Jana: an editor with Enquiries and Pages, e-mail at once (English); Petr: Enquiries only, the daily summary; Eva: Pages, no e-mail
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('t327-jana', '$T327_HASH', 'Jana T327', 'jana-t327@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'instant'),
  ('t327-petr', '$T327_HASH', 'Petr T327', 'petr-t327@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'daily'),
  ('t327-eva', '$T327_HASH', 'Eva T327', 'eva-t327@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'enquiries') m WHERE user = 't327-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user = 't327-petr';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 't327-eva'" > /dev/null
T327_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't327-jana'"); T327_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't327-petr'")
T327_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't327-eva'"); T327_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
T327_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, zmeneno) VALUES ('t327-stranka', 'Stránka T327', '<p>T327</p>', 0, '$(site_time)'); SELECT LAST_INSERT_ID();")
T327_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Formulář T327', 'zakaznik-t327@example.cz', '[[\"Zpráva\",\"Tajná zpráva T327\"]]', 0); SELECT LAST_INSERT_ID();")
t327_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"' Kaleta\Core\Mail::processQueue($app->settings(), 50);'); }
t327_mails() { grep -l "^X-Rcpt-To: $1" "$T327_SMTP"/*.eml 2>/dev/null | wc -l | tr -d ' '; }
t327_last() { local f; f=$(grep -l "^X-Rcpt-To: $1" "$T327_SMTP"/*.eml 2>/dev/null | tail -1); if [ -n "$f" ]; then eml "$f"; fi; }
t327_json() { php -r '$v = json_decode((string) file_get_contents($argv[1]), true); foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? "true" : "false") : (string) $v);' "$WORK/text" "$1"; }
t327_token() { local t="kaleta_$(printf "$1%.0s" $(seq 1 48))"; sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($2, '$3', 'full', '$4', '$(php -r 'echo hash("sha256", $argv[1]);' "$t")', '$(site_time)')" > /dev/null; echo "$t"; }
T327_TJ=$(t327_token 3 "$T327_JANA" t327-jana work); T327_TP=$(t327_token 4 "$T327_PETR" t327-petr all); T327_TE=$(t327_token 5 "$T327_EVA" t327-eva all); T327_TA=$(t327_token 6 "$T327_ADMIN" t327-admin all)

# save_task: a task for oneself about an enquiry, due in 3 days (9:00 on the site's clock)
mcp_as "$T327_TJ" save_task "{\"title\":\"Call back about the windows\",\"target\":\"enquiry:$T327_ENQ\",\"due\":\"+3 days\"}" > "$WORK/response"; mcp_text
T327_T1=$(t327_json id)
expect "3.27 save_task: a task for oneself about an enquiry – created, due in 3 days at 9:00, the result names the form (never the enquiry's text)" \
  "$(t327_json created)|$(sq "SELECT CONCAT(assignee_id = $T327_JANA, created_by = $T327_JANA, target = 'enquiry:$T327_ENQ', due_at = '$(site_date '+3 days') 09:00:00') FROM ka_tasks WHERE id = ${T327_T1:-0}")|$(t327_json task.about.title)|$(t327_json task.group)|$(grep -c 'Tajná' "$WORK/text")" \
  "true|1111|Formulář T327|upcoming|0"
# for someone else: both must be able to open the record – Petr has no Pages, so a page task for him is refused and nothing is saved
mcp_as "$T327_TJ" save_task "{\"title\":\"Check the page\",\"target\":\"page:$T327_PAGE\",\"assignee\":\"petr-t327@example.com\"}" > "$WORK/response"; mcp_text
expect "3.27 save_task: a task about a page for someone without Pages is refused – nothing saved" \
  "$(grep -c 'This person cannot open what the task is about' "$WORK/text")|$(grep -c 'Nothing was saved' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Check the page'")" "1|1|0"
mcp_as "$T327_TP" save_task "{\"title\":\"Fix the page\",\"target\":\"page:$T327_PAGE\"}" > "$WORK/response"; mcp_text
expect "3.27 save_task: … and nobody makes a task about what they cannot open, not even for themselves" "$(grep -c 'You cannot open what the task is about' "$WORK/text")" "1"
mcp_as "$T327_TJ" save_task "{\"title\":\"Send the offer\",\"target\":\"enquiry:$T327_ENQ\",\"assignee\":$T327_PETR,\"due\":\"$(site_date '+20 days')\"}" > "$WORK/response"; mcp_text
T327_T2=$(t327_json id)
mcp_as "$T327_TJ" save_task "{\"title\":\"Order business cards\",\"assignee\":\"t327-eva\",\"due\":\"$(site_date '+10 days') 14:00\",\"remind\":\"$(site_time '-1 hour' 'Y-m-d H:i')\"}" > "$WORK/response"; mcp_text
T327_T3=$(t327_json id)
expect "3.27 save_task: for Petr about the enquiry (he has Enquiries) and for Eva by her sign-in name about nothing – each told in the bell (assigned, task:<id>), no e-mail for daily and off" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id IN (${T327_T2:-0}, ${T327_T3:-0}) AND created_by = $T327_JANA")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'assigned' AND ((user_id = $T327_PETR AND target = 'task:${T327_T2:-0}') OR (user_id = $T327_EVA AND target = 'task:${T327_T3:-0}'))")|$(t327_mails petr-t327@example.com)|$(t327_mails eva-t327@example.com)" \
  "2|2|0|0"
mcp_as "$T327_TJ" save_task '{"title":"Whenever","due":"next friday"}' > "$WORK/response"; mcp_text
expect "3.27 save_task: a date it does not understand is refused with the forms it takes" "$(grep -c 'is not understood – use YYYY-MM-DD' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Whenever'")" "1|0"
# list_tasks: mine, someone else's (administrators only), a record's (who may open it)
mcp_as "$T327_TP" list_tasks '{}' > "$WORK/response"; mcp_text
expect "3.27 list_tasks: mine – Petr's task with its group, due day, the form and its admin link, who created it" \
  "$(t327_json total)|$(t327_json tasks.0.title)|$(t327_json tasks.0.group)|$(t327_json tasks.0.about.title)|$(t327_json tasks.0.created_by.name)|$(t327_json tasks.0.about.admin_url | grep -c "module=enquiries&action=detail&id=$T327_ENQ")" \
  "1|Send the offer|later|Formulář T327|Jana T327|1"
mcp_as "$T327_TP" list_tasks "{\"filter\":\"assignee:$T327_JANA\"}" > "$WORK/response"; mcp_text
expect "3.27 list_tasks: only an administrator lists someone else's" "$(grep -c 'Only an administrator may list the tasks of someone else' "$WORK/text")" "1"
# N327-1: an unknown e-mail or sign-in name gets the same answer – the filter is no way to find out who has an account
T327_P1=$(mcp_as "$T327_TP" list_tasks '{"filter":"assignee:nobody-n3271@example.com"}' | grep -c 'Only an administrator may list the tasks of someone else')
T327_P2=$(mcp_as "$T327_TP" list_tasks '{"filter":"assignee:nobody-n3271"}' | grep -c 'Only an administrator may list the tasks of someone else')
expect "N327-1 list_tasks: someone else's unknown e-mail or sign-in name – the same refusal as an existing colleague" "$T327_P1|$T327_P2" "1|1"
mcp_as "$T327_TA" list_tasks '{"filter":"assignee:t327-jana"}' > "$WORK/response"; mcp_text; T327_A=$(t327_json total)
mcp_as "$T327_TA" list_tasks "{\"filter\":\"target:enquiry:$T327_ENQ\"}" > "$WORK/response"; mcp_text
expect "3.27 list_tasks: the administrator lists Jana's, and every task about the enquiry" "$T327_A|$(t327_json total)" "1|2"
mcp_as "$T327_TP" list_tasks "{\"filter\":\"target:page:$T327_PAGE\"}" > "$WORK/response"; mcp_text
expect "3.27 list_tasks: not about a record one cannot open" "$(grep -c 'Only a user who may open Pages' "$WORK/text")" "1"
# complete_task: Eva may not touch Jana's task; Jana completes it, again (nothing more), and opens it again
mcp_as "$T327_TE" complete_task "{\"id\":${T327_T1:-0}}" > "$WORK/response"; mcp_text; T327_E=$(grep -c 'Only the person the task is for' "$WORK/text")
mcp_as "$T327_TJ" complete_task "{\"id\":${T327_T1:-0}}" > "$WORK/response"; mcp_text; T327_D1=$(sq "SELECT done_at FROM ka_tasks WHERE id = ${T327_T1:-0}")
sleep 1; mcp_as "$T327_TJ" complete_task "{\"id\":${T327_T1:-0},\"done\":true}" > /dev/null
expect "3.27 complete_task: only the assignee, the creator or an administrator; done once, a repeat changes nothing" \
  "$T327_E|$(t327_json done)|$(sq "SELECT CONCAT(done_by = $T327_JANA, done_at = '$T327_D1') FROM ka_tasks WHERE id = ${T327_T1:-0}")" "1|true|11"
mcp_as "$T327_TJ" complete_task "{\"id\":${T327_T1:-0},\"done\":false}" > "$WORK/response"; mcp_text
expect "3.27 complete_task: done false opens it again" "$(t327_json done)|$(sq "SELECT done_at IS NULL AND done_by IS NULL FROM ka_tasks WHERE id = ${T327_T1:-0}")" "false|1"

# the reminder job: Jana's task is due now (instant – one e-mail in English), Eva's reminder has passed (off – the bell only),
# Petr's is weeks away; each task notifies once, a reminder of one's own task reaches its creator
sq "UPDATE ka_tasks SET due_at = '$(site_time '-10 minutes')' WHERE id = ${T327_T1:-0}" > /dev/null
T327_RUN=$(t327_php 'echo Kaleta\Core\Tasks::run($app);')
expect "3.27 job tasks: due and reminded tasks notify their assignee once (task_due, the record or task:<id>, the title as the excerpt); Petr's waits" \
  "$(echo "$T327_RUN" | grep -c 'notified 2')|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'task_due' AND user_id = $T327_JANA AND target = 'enquiry:$T327_ENQ' AND actor_id = $T327_JANA AND excerpt = 'Call back about the windows'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'task_due' AND user_id = $T327_EVA AND target = 'task:${T327_T3:-0}' AND emailed_at IS NULL")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE notified_at IS NOT NULL AND id IN (${T327_T1:-0}, ${T327_T2:-0}, ${T327_T3:-0})")" \
  "1|1|1|2"
expect "3.27 job tasks: Jana chose instant – one e-mail in English with the title and the link through the Notifications screen; nothing to Eva (off) or Petr" \
  "$(t327_mails jana-t327@example.com)|$(t327_last jana-t327@example.com | grep -c 'Subject-Decoded: Task due: Call back about the windows')|$(t327_last jana-t327@example.com | grep -c 'admin.php?action=inbox&open=')|$(t327_last jana-t327@example.com | grep -c 'Tajná')|$(t327_mails eva-t327@example.com)|$(t327_mails petr-t327@example.com)" \
  "1|1|1|0|0|0"
T327_RUN=$(t327_php 'echo Kaleta\Core\Tasks::run($app);')
expect "3.27 job tasks: a second run notifies nobody again" "$(echo "$T327_RUN" | grep -c 'notified 0')|$(t327_mails jana-t327@example.com)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'task_due' AND user_id IN ($T327_JANA, $T327_EVA)")" "1|1|2"
mcp_as "$T327_TJ" save_task "{\"id\":${T327_T1:-0},\"due\":\"+1 day\"}" > "$WORK/response"; mcp_text
expect "3.27 save_task: a new due moment notifies again when it comes" "$(t327_json updated)|$(sq "SELECT notified_at IS NULL FROM ka_tasks WHERE id = ${T327_T1:-0}")" "true|1"

# My work as the administrator: the tasks first, by group, with Done and the snooze buttons; the tile and badge count overdue + today
T327_A1=$(t327_php "echo Kaleta\Core\Tasks::create(\$app, ['title' => 'Overdue T327', 'due_at' => '$(site_date '-2 days')'], $T327_ADMIN);")
T327_A2=$(t327_php "echo Kaleta\Core\Tasks::create(\$app, ['title' => 'Today T327', 'due_at' => '$(site_date now) 23:30', 'target' => 'page:$T327_PAGE'], $T327_ADMIN);")
T327_A3=$(t327_php "echo Kaleta\Core\Tasks::create(\$app, ['title' => 'Later T327'], $T327_ADMIN);")
check "3.27 My work: the tasks come first" 200 "/admin.php?module=my_work" 'data-list="tasks"'
expect "3.27 My work: … grouped overdue, today, later, each row with Done, Tomorrow and In 3 days; the page task links to the page; the administrator has the Team tab" \
  "$(grep -o 'data-group="[a-z]*"' "$WORK/response" | tr '\n' ' ')|$(grep -c "id=\"task-${T327_A1:-0}\"" "$WORK/response")|$(grep -c 'action=snooze&amp;by=3days' "$WORK/response")|$(grep -c "module=pages&amp;action=edit&amp;id=$T327_PAGE\"" "$WORK/response")|$(grep -c 'module=my_work&amp;action=team' "$WORK/response")|$(awk '/data-list="tasks"/{t=NR} /data-list="(assigned|unassigned|pages|items|requests)"/{if(!o)o=NR} END{print (o==0||t<o)?"first":"not first"}' "$WORK/response")" \
  'data-group="overdue" data-group="today" data-group="later" |1|3|1|1|first'
expect "3.27 My work: the dashboard tile and the menu badge count the overdue and today's tasks" \
  "$(t327_php "\$c = Kaleta\Core\Tasks::counts(\$app->db(), $T327_ADMIN); echo \$c['overdue'], '+', \$c['today'];")" "1+1"
mkdir -p "$WORK/web/storage/cache/stranky"; echo cached > "$WORK/web/storage/cache/stranky/t327.html"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=complete" -d "_csrf=$(csrf)" -d "id=${T327_A1:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=snooze&by=tomorrow" -d "_csrf=$(csrf)" -d "id=${T327_A2:-0}"
expect "3.27 My work: Done marks it done, Tomorrow moves the due day keeping its time; both logged, the page cache stays" \
  "$(sq "SELECT done_by = $T327_ADMIN FROM ka_tasks WHERE id = ${T327_A1:-0}")|$(sq "SELECT CONCAT(due_at, '|', notified_at IS NULL) FROM ka_tasks WHERE id = ${T327_A2:-0}")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce IN ('task_done', 'task_snoozed')")|$([ -f "$WORK/web/storage/cache/stranky/t327.html" ] && echo kept)" \
  "1|$(site_date '+1 day') 23:30:00|1|2|kept"
# 3.32 (U35): the task title is a link, Edit sits in the row (a form after the table, form=…) and saves title and due day
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work"
expect "3.32 (U35) My work: the title links to the record's tasks (or Edit), each open task has Edit with its own form" \
  "$(grep -c "class=\"moje-ukol-nazev\" href=\"[^\"]*#task-${T327_A3:-0}-edit\"" "$WORK/response")|$(grep -c "id=\"ukol-uprava-${T327_A3:-0}\"" "$WORK/response")|$(grep -c "form=\"ukol-uprava-${T327_A3:-0}\"" "$WORK/response")" "1|1|5"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=task_edit" -d "_csrf=$(csrf)" -d "id=${T327_A3:-0}" -d "title=Later T332" -d "due_date=$(site_date '+5 days')" -d due_time= -d remind= -d assignee_id=0
expect "3.32 (U35) My work: … Edit saves the title and the due day, keeps the person; logged" \
  "$(sq "SELECT CONCAT(title, '|', LEFT(due_at, 10), '|', assignee_id = $T327_ADMIN) FROM ka_tasks WHERE id = ${T327_A3:-0}")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'task_edited'")" "Later T332|$(site_date '+5 days')|1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=complete" -d "_csrf=$(csrf)" -d "id=987654321"
expect "3.27 My work: a task that does not exist changes nothing" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'task_done'")" "1"
# N327-3: a task about an enquiry done in My work – the change log names the task and the enquiry, never the title (it may name
# the customer, and the change log outlives the enquiry)
T327_N3=$(t327_php "echo Kaleta\Core\Tasks::create(\$app, ['title' => 'Call Mrs N3273 back', 'target' => 'enquiry:$T327_ENQ'], $T327_ADMIN);")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=complete" -d "_csrf=$(csrf)" -d "id=${T327_N3:-0}"
expect "N327-3 My work: the change log of a task about an enquiry names the enquiry, not the title" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'task_done' AND popis = '#${T327_N3:-0} (enquiry:$T327_ENQ)'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%N3273%'")" "1|0"
sq "DELETE FROM ka_tasks WHERE id = ${T327_N3:-0}; DELETE FROM ka_protokol WHERE modul = 'my_work' AND popis = '#${T327_N3:-0} (enquiry:$T327_ENQ)'" > /dev/null
check "3.27 My work: the Team tab – open tasks per person" 200 "/admin.php?module=my_work&action=team" 'data-list="team"'
expect "3.27 My work: … Jana, Petr and Eva with their counts" "$(grep -c '>Jana T327<\|>Petr T327<\|>Eva T327<' "$WORK/response")" "3"
# hand a task to Claude: Jana has no Ask Claude; the administrator's becomes a request and the task is done with its id
T327_REQ=$(t327_php "try { Kaleta\Core\Tasks::toRequest(\$app, ${T327_T1:-0}, $T327_JANA); echo 'handed'; } catch (DomainException \$e) { echo 'refused'; } echo '|', Kaleta\Core\Tasks::toRequest(\$app, ${T327_A2:-0}, $T327_ADMIN);")
expect "3.27 toRequest: only with Ask Claude; the request carries the title and the page, the task is done with the request's id" \
  "${T327_REQ%%|*}|$(sq "SELECT CONCAT(title = 'Today T327', about = 'page:$T327_PAGE', text LIKE '%Stránka T327%') FROM ka_requests WHERE id = ${T327_REQ##*|}")|$(sq "SELECT CONCAT(request_id = ${T327_REQ##*|}, done_at IS NOT NULL) FROM ka_tasks WHERE id = ${T327_A2:-0}")" \
  "refused|111|11"

# undo: a task Claude saves about a page is part of its session, one about an enquiry never enters the journal
mcp_as "$T327_TA" save_task "{\"title\":\"Undo me T327\",\"target\":\"page:$T327_PAGE\"}" > /dev/null
mcp_as "$T327_TA" save_task "{\"title\":\"Keep me T327\",\"target\":\"enquiry:$T327_ENQ\"}" > /dev/null
T327_S=$(sq "SELECT id FROM ka_agent_sessions WHERE connection = 't327-admin' ORDER BY id DESC LIMIT 1")
expect "3.27 undo journal: the page task is journaled, the enquiry task is not" "$(sq "SELECT GROUP_CONCAT(tbl) FROM ka_agent_journal WHERE session_id = ${T327_S:-0} AND untracked IS NULL")" "tasks"
mcp_as "$T327_TA" undo_agent_session "{\"id\":${T327_S:-0},\"confirm\":true}" > /dev/null
expect "3.27 undo_agent_session: the page task is gone, the enquiry task stays" "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Undo me T327'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Keep me T327'")" "0|1"
# my_work over MCP carries the tasks (an additive key)
mcp_as "$T327_TP" my_work '{}' > "$WORK/response"; mcp_text
expect "3.27 my_work: the open tasks first, as list_tasks shows them" "$(t327_json tasks.0.title)|$(t327_json tasks.0.group)" "Send the offer|later"

# the hand-over of a leaver: Eva's open task goes with her pages to the one who takes over
check "3.27 hand-over: Eva's open task is counted" 200 "/admin.php?module=users&action=handover&id=$T327_EVA" 'otevřené úkoly: 1'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=handover" -d "_csrf=$(csrf)" -d "idu=$T327_EVA" -d user=t327-eva -d "hand_over_to=$T327_JANA" -d then=
expect "3.27 hand-over: … and moves to Jana, to be notified again" "$(sq "SELECT CONCAT(assignee_id = $T327_JANA, notified_at IS NULL) FROM ka_tasks WHERE id = ${T327_T3:-0}")" "11"
# 3.27: tasks nobody has are never lost – a leaver's work handed to nobody (and a task left on a removed account) is listed
# under "Not assigned" in My work → Team, whose nobody row links there; Reassign to… gives each to a colleague who may take it
# (notified, logged without the title of a task about an enquiry – N327-3); others are refused; MCP list_tasks assignee:none
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('t327-leo', '$T327_HASH', 'Leo T327', 'leo-t327@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'enquiries') m WHERE user = 't327-leo'" > /dev/null
T327_LEO=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't327-leo'")
T327_R1=$(t327_php "echo Kaleta\Core\Tasks::create(\$app, ['title' => 'Call Mrs R327 back', 'target' => 'enquiry:$T327_ENQ', 'assignee_id' => $T327_LEO, 'due_at' => '+2 days'], $T327_ADMIN);")
T327_R2=$(t327_php "echo Kaleta\Core\Tasks::create(\$app, ['title' => 'Order paper R327', 'assignee_id' => $T327_LEO, 'due_at' => '$(site_date '-1 day')'], $T327_ADMIN);")
T327_R3=$(sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, created_at, updated_at) VALUES ('Former R327', NULL, 987654321, $T327_ADMIN, '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=handover&id=$T327_LEO"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=handover" -d "_csrf=$(csrf)" -d "idu=$T327_LEO" -d user=t327-leo -d hand_over_to=0 -d then=block
expect "3.27 Not assigned: Leo's work handed to nobody, his account blocked" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id IN (${T327_R1:-0}, ${T327_R2:-0}) AND assignee_id IS NULL")|$(sq "SELECT blokovat <> 0 FROM ka_uzivatele WHERE idu = $T327_LEO")" "2|1"
check "3.27 Not assigned: My work → Team lists the tasks nobody active has" 200 "/admin.php?module=my_work&action=team" 'id="unassigned"'
sed -n "/data-task=\"${T327_R1:-0}\"/,/<\/tr>/p" "$WORK/response" > "$WORK/t327r1"; sed -n "/data-task=\"${T327_R2:-0}\"/,/<\/tr>/p" "$WORK/response" > "$WORK/t327r2"
expect "3.27 Not assigned: … the three tasks; the rows nobody and (former user) of the summary link there; the enquiry task links its enquiry, was for nobody" \
  "$(grep -c "data-task=\"${T327_R1:-0}\"\|data-task=\"${T327_R2:-0}\"\|data-task=\"${T327_R3:-0}\"" "$WORK/response")|$(grep -c '<a href="#unassigned">nikdo</a>\|<a href="#unassigned">(bývalý uživatel)</a>' "$WORK/response")|$(grep -c "module=enquiries&amp;action=detail&amp;id=$T327_ENQ" "$WORK/t327r1")|$(grep -c '<td>nikdo</td>' "$WORK/t327r1")" "3|2|1|1"
expect "3.27 Not assigned: … Reassign to… offers those who may take it – Jana and Petr (Enquiries) for the enquiry task, Eva (Pages only) and the blocked Leo not; anyone active for a task about nothing" \
  "$(grep -c "<option value=\"$T327_JANA\">\|<option value=\"$T327_PETR\">" "$WORK/t327r1")|$(grep -c "<option value=\"$T327_EVA\">\|<option value=\"$T327_LEO\">" "$WORK/t327r1")|$(grep -c "<option value=\"$T327_EVA\">" "$WORK/t327r2")|$(grep -c 'data-task-reassign' "$WORK/t327r2")" "2|0|1|1"
# Jana (an editor) neither sees the list nor reassigns
T327_JJAR="$WORK/jar-t327-jana"; rm -f "$T327_JJAR"; curl -s -c "$T327_JJAR" -o "$WORK/response" "$B/admin.php"
curl -s -b "$T327_JJAR" -c "$T327_JJAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=t327-jana --data-urlencode "password=$PASSWORD"
curl -s -b "$T327_JJAR" -c "$T327_JJAR" -o "$WORK/response" "$B/admin.php?module=my_work"
expect "3.27 Not assigned: a non-administrator gets 403 for the list and for Reassign; nothing changes" \
  "$(curl -s -b "$T327_JJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=my_work&action=team")|$(curl -s -b "$T327_JJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=my_work&action=reassign" -d "_csrf=$(csrf)" -d "id=${T327_R1:-0}" -d "assignee_id=$T327_JANA")|$(sq "SELECT IFNULL(assignee_id, 'null') FROM ka_tasks WHERE id = ${T327_R1:-0}")" "403|403|null"
t327_reassign() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work&action=team"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=my_work&action=reassign" -d "_csrf=$(csrf)" -d "id=$1" -d "assignee_id=$2"; }
expect "3.27 Not assigned: without the form token nothing happens" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=my_work&action=reassign" -d "id=${T327_R1:-0}" -d "assignee_id=$T327_PETR")|$(sq "SELECT IFNULL(assignee_id, 'null') FROM ka_tasks WHERE id = ${T327_R1:-0}")" "400|null"
T327_RR=$(t327_reassign "${T327_R1:-0}" "$T327_PETR")
expect "3.27 Not assigned: Reassign gives the enquiry task to Petr – notified (assigned, task:<id>), back to the Team tab; the change log names the task and the enquiry, never the title" \
  "$(sq "SELECT assignee_id = $T327_PETR FROM ka_tasks WHERE id = ${T327_R1:-0}")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $T327_PETR AND kind = 'assigned' AND target = 'task:${T327_R1:-0}'")|$(echo "$T327_RR" | grep -c 'module=my_work&action=team$')|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'task_reassigned' AND popis = '#${T327_R1:-0} (enquiry:$T327_ENQ)'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%Mrs R327%'")" \
  "1|1|1|1|0"
t327_reassign "${T327_R1:-0}" "$T327_JANA" > /dev/null; t327_reassign "${T327_R3:-0}" 0 > /dev/null; t327_reassign "${T327_R3:-0}" "$T327_LEO" > /dev/null
expect "3.27 Not assigned: a task someone has meanwhile stays theirs; no person chosen or a blocked account changes nothing" \
  "$(sq "SELECT assignee_id = $T327_PETR FROM ka_tasks WHERE id = ${T327_R1:-0}")|$(sq "SELECT assignee_id FROM ka_tasks WHERE id = ${T327_R3:-0}")" "1|987654321"
t327_reassign "${T327_R2:-0}" "$T327_EVA" > /dev/null
expect "3.27 Not assigned: a task about nothing may go to any active colleague (Eva), logged with its title" \
  "$(sq "SELECT assignee_id = $T327_EVA FROM ka_tasks WHERE id = ${T327_R2:-0}")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'task_reassigned' AND popis = '#${T327_R2:-0} Order paper R327'")" "1|1"
# MCP: list_tasks assignee:none for administrators (the refusal of every other assignee filter for anyone else), save_task gives it away
mcp_as "$T327_TA" list_tasks '{"filter":"assignee:none"}' > "$WORK/response"; mcp_text
expect "3.27 list_tasks assignee:none: the administrator gets the task left on a removed account, who had it, not the reassigned ones" \
  "$(grep -c '"title":"Former R327","assignee":{"id":987654321,"name":"(former user)"}' "$WORK/text")|$(grep -c 'Order paper R327\|Mrs R327' "$WORK/text")|$(t327_json filter)" "1|0|assignee:none"
mcp_as "$T327_TJ" list_tasks '{"filter":"assignee:none"}' > "$WORK/response"; mcp_text
expect "3.27 list_tasks assignee:none: a non-administrator gets the refusal of the other assignee filters" "$(grep -c 'Only an administrator may list the tasks of someone else' "$WORK/text")|$(grep -c 'Former R327' "$WORK/text")" "1|0"
mcp_as "$T327_TA" save_task "{\"id\":${T327_R3:-0},\"assignee\":\"t327-jana\"}" > "$WORK/response"; mcp_text
mcp_as "$T327_TA" list_tasks '{"filter":"assignee:none"}' > "$WORK/response"
expect "3.27 save_task: the administrator gives a task nobody has to Jana – notified; assignee:none no longer lists it" \
  "$(sq "SELECT assignee_id = $T327_JANA FROM ka_tasks WHERE id = ${T327_R3:-0}")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $T327_JANA AND kind = 'assigned' AND target = 'task:${T327_R3:-0}'")|$(grep -c 'Former R327' "$WORK/response")" "1|1|0"
sq "DELETE FROM ka_notifications WHERE target IN ('task:${T327_R1:-0}', 'task:${T327_R2:-0}', 'task:${T327_R3:-0}'); DELETE FROM ka_tasks WHERE id IN (${T327_R1:-0}, ${T327_R2:-0}, ${T327_R3:-0});
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $T327_LEO; DELETE FROM ka_uzivatele WHERE idu = $T327_LEO" > /dev/null
# GDPR: the processing record names the tasks; the personal data file of the customer carries them; anonymising blanks the titles
# (and the quotes of their notifications); deleting the enquiry or the page for good takes the tasks along
expect "3.27 GDPR: the processing record and the customer's data file name the tasks about an enquiry" \
  "$(t327_php "echo (int) str_contains(json_encode(Kaleta\Core\Language::runWith('cs', fn () => Kaleta\Core\Privacy::processingRecord(\$app), 'admin-'), JSON_UNESCAPED_UNICODE), 'Úkoly týmu k poptávce'), '|', count(Kaleta\Core\PersonalData::find(\$app->db(), 'zakaznik-t327@example.cz')['enquiries'][0]['tasks'] ?? []);")" "1|3"
t327_php "Kaleta\Core\Comments::anonymiseEnquiries(\$app->db(), [$T327_ENQ]);"
expect "3.27 GDPR: an anonymised enquiry – its tasks keep who and when, the titles go, so do the quotes of their notifications" \
  "$(sq "SELECT CONCAT(COUNT(*), '/', SUM(title = '')) FROM ka_tasks WHERE target = 'enquiry:$T327_ENQ'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'enquiry:$T327_ENQ' AND excerpt <> ''")" "3/3|0"
t327_php "Kaleta\Core\Comments::forgetEnquiries(\$app->db(), [$T327_ENQ]);"
expect "3.27 GDPR: a deleted enquiry takes its tasks and their notifications along" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'enquiry:$T327_ENQ'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target IN ('task:${T327_T2:-0}', 'enquiry:$T327_ENQ')")" "0|0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$(csrf)" -d "ids=$T327_PAGE"
expect "3.27: a page in the trash keeps its tasks" "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'page:$T327_PAGE'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete_permanently" -d "_csrf=$(csrf)" -d "ids=$T327_PAGE"
sq "INSERT INTO ka_tasks (title, target, assignee_id, created_at, updated_at) VALUES ('Orphan T327', 'item:987654321', $T327_ADMIN, '$(site_time)', '$(site_time)')" > /dev/null
T327_RUN=$(t327_php 'echo Kaleta\Core\Tasks::run($app);')
expect "3.27: a page deleted for good takes its tasks; the job sweeps tasks of records some other path deleted" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'page:$T327_PAGE'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Orphan T327'")|$(echo "$T327_RUN" | grep -c 'swept 1')" "0|0|1"
sq "DELETE FROM ka_tasks WHERE created_by IN ($T327_JANA, $T327_PETR, $T327_EVA, $T327_ADMIN) OR assignee_id IN ($T327_JANA, $T327_PETR, $T327_EVA) OR title LIKE '%T327%';
  DELETE FROM ka_requests WHERE title = 'Today T327'; DELETE FROM ka_api_tokeny WHERE nazev LIKE 't327-%'; DELETE FROM ka_poptavky WHERE idp = $T327_ENQ; DELETE FROM ka_stranky WHERE ids = $T327_PAGE;
  DELETE FROM ka_notifications WHERE user_id IN ($T327_JANA, $T327_PETR, $T327_EVA) OR target LIKE 'task:%';
  DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_t327_mail; DROP TABLE ka_t327_mail;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($T327_JANA, $T327_PETR, $T327_EVA); DELETE FROM ka_uzivatele WHERE user LIKE 't327-%'" > /dev/null
rm -f "$WORK/web/storage/cache/stranky/t327.html"
kill "$SMTP_PID" 2>/dev/null || true
# 3.27 (agent B): the tasks of a record in the admin (Admin\TaskActions, views/admin/tasks_panel.php) – the follow-up buttons of
# an enquiry (titled after the form, never after what the customer wrote), "Add a task" on pages and items with one click for
# the review date, snooze, done, edit, delete, hand to Claude; refusals for people without the section or without Requests
