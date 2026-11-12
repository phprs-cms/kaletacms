echo "== 3.27 (B): tasks on enquiries, pages and items – follow-ups, the review date, snooze, done, edit, delete, hand to Claude"
B327_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES
  ('b327-jana', '$B327_HASH', 'Jana B327', 'jana-b327@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0),
  ('b327-petr', '$B327_HASH', 'Petr B327', 'petr-b327@example.com', '', 1, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries') m WHERE user = 'b327-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'b327-petr'" > /dev/null
B327_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b327-jana'"); B327_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b327-petr'"); B327_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B327_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Poptávka B327', 'zakaznik-b327@example.cz', '[[\"Zpráva\",\"Tajná zpráva B327\"]]', 1); SELECT LAST_INSERT_ID();")
B327_REVIEW=$(site_date '+10 days')
B327_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, review_by, zmeneno) VALUES ('b327-stranka', 'Stránka B327', '<p>x</p>', 0, '$B327_REVIEW', '$(site_time)'); SELECT LAST_INSERT_ID();")
mcp create_collection '{"name":"B327 collection","slug":"b327-collection"}' > /dev/null
B327_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'b327-collection'")
B327_ITEM=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, zobrazit, datum, review_by) VALUES ($B327_IDK, 'Položka B327', 'b327-polozka', '{}', 1, '$(site_time)', '$B327_REVIEW'); SELECT LAST_INSERT_ID();")
b327_login() { local jar="$WORK/jar-$1"; rm -f "$jar"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; echo "$jar"; }
b327_task() { # b327_task <jar> <module> <screen query> <fields…>: opens the screen (for the form token), posts the task action, prints the redirect
  local jar="$1" module="$2" screen="$3"; shift 3
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?$screen"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=$module&action=task" -d "_csrf=$(csrf)" "$@"; }
b327_row() { sq "SELECT CONCAT_WS('/', title, assignee_id, created_by, IFNULL(due_at, '-'), IFNULL(remind_at, '-'), IF(done_at IS NULL, 'open', 'done'), IFNULL(request_id, '-')) FROM ka_tasks WHERE id = $1"; }
B327_ENQ_SCREEN="module=enquiries&action=detail&id=$B327_ENQ"; B327_PAGE_SCREEN="module=pages&action=edit&id=$B327_PAGE"; B327_ITEM_SCREEN="module=collections&action=item&id=$B327_IDK&item=$B327_ITEM"
check "3.27: the enquiry detail has the task panel with the follow-up buttons" 200 "/admin.php?$B327_ENQ_SCREEN" "data-tasks=\"enquiry:$B327_ENQ\""
expect "3.27: … Tomorrow, in 3 days, in a week; no open task yet; Add a task" \
  "$(grep -c 'name="days" value="\(tomorrow\|3days\|week\)"' "$WORK/response")|$(grep -c 'data-tasks-none' "$WORK/response")|$(grep -c 'data-task-add' "$WORK/response")|$(grep -c 'data-task-replied' "$WORK/response")" "3|1|1|0"
# the page cache stays: tasks never reach the site
B327_CACHE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'page_cache'"); setting page_cache 1
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null "$B/"
REDIRECT=$(b327_task "$JAR" enquiries "$B327_ENQ_SCREEN" -d "idp=$B327_ENQ" -d op=follow_up -d days=3days)
B327_T1=$(sq "SELECT MAX(id) FROM ka_tasks WHERE target = 'enquiry:$B327_ENQ'")
expect "3.27: Follow up in 3 days – my own task about the enquiry, titled after the form (never the message), due in 3 days at 9:00, back to #tasks" \
  "$(b327_row "${B327_T1:-0}")|$(echo "$REDIRECT" | grep -c "action=detail&id=$B327_ENQ#tasks")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title LIKE '%Tajná%'")" \
  "Ozvat se: Poptávka B327/$B327_ADMIN/$B327_ADMIN/$(site_date '+3 days') 09:00:00/-/open/-|1|0"
expect "3.27: … the change log names the enquiry and the task, not the customer; the cached page stays" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'task follow-up added' AND popis = '#$B327_ENQ Poptávka B327 (task #$B327_T1)'")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" "1|1"
check "3.27: the detail lists the task with its due day and actions" 200 "/admin.php?$B327_ENQ_SCREEN" "data-task=\"$B327_T1\""
expect "3.27: … done, snooze, edit, delete; the flash says it is in My work" \
  "$(grep -c 'data-task-actions' "$WORK/response")|$(grep -c 'data-task-snooze' "$WORK/response")|$(grep -c 'data-task-edit' "$WORK/response")|$(grep -c 'data-task-delete' "$WORK/response")|$(grep -c 'je v Mojí práci' "$WORK/response")" "1|1|1|1|1"
# a page: one click for the review date, then a task for a colleague with a quick day and a reminder, and a refusal for someone without Pages
check "3.27: the page settings offer a task for the review date" 200 "/admin.php?$B327_PAGE_SCREEN" 'data-task-review'
REDIRECT=$(b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=add -d review=1)
B327_T2=$(sq "SELECT MAX(id) FROM ka_tasks WHERE target = 'page:$B327_PAGE'")
expect "3.27: the review task – \"Zkontrolovat: <title>\" for me on the review date; the offer is gone once it exists; the cached page stays" \
  "$(b327_row "${B327_T2:-0}")|$(echo "$REDIRECT" | grep -c "action=edit&id=$B327_PAGE#tasks")|$(curl -s -b "$JAR" "$B/admin.php?$B327_PAGE_SCREEN" | grep -c 'data-task-review')|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "Zkontrolovat: Stránka B327/$B327_ADMIN/$B327_ADMIN/$B327_REVIEW 09:00:00/-/open/-|1|0|1"
setting page_cache "$B327_CACHE"
check "3.27: the person list offers Jana (Pages), not Petr (news only)" 200 "/admin.php?$B327_PAGE_SCREEN" 'data-task-add'
sed -n '/data-task-add>/,/<\/form>/p' "$WORK/response" > "$WORK/b327-form"
expect "3.27: … me chosen by default" "$(grep -c "<option value=\"$B327_JANA\">Jana B327</option>" "$WORK/b327-form")|$(grep -c "<option value=\"$B327_PETR\"" "$WORK/b327-form")|$(grep -c "<option value=\"$B327_ADMIN\" selected>" "$WORK/b327-form")" "1|0|1"
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=add --data-urlencode "title=Ceník pro Janu" -d "assignee_id=$B327_JANA" -d due_quick=tomorrow -d due_time=14:30 -d remind=1h > /dev/null
B327_T3=$(sq "SELECT MAX(id) FROM ka_tasks WHERE target = 'page:$B327_PAGE'")
expect "3.27: a task for Jana tomorrow at 14:30 with a reminder an hour before – she is notified" \
  "$(b327_row "${B327_T3:-0}")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $B327_JANA AND kind = 'assigned' AND target = 'task:$B327_T3'")" \
  "Ceník pro Janu/$B327_JANA/$B327_ADMIN/$(site_date '+1 day') 14:30:00/$(site_date '+1 day') 13:30:00/open/-|1"
B327_N=$(sq "SELECT COUNT(*) FROM ka_tasks")
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=add --data-urlencode "title=Pro Petra" -d "assignee_id=$B327_PETR" > /dev/null
check "3.27: a task for someone without Pages is refused, the title offered again" 200 "/admin.php?$B327_PAGE_SCREEN" 'class="hlaska hlaska-chyba"'
expect "3.27: … with the reason (Core\\Tasks)" "$(grep -cE 'cannot open what the task is about|nemůže otevřít, čeho se úkol týká' "$WORK/response")" "1"
expect "3.27: … nothing saved; the form is open with the title; a bad time and an empty title are refused too" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks")|$(grep -c '<details class="ukol-pridat" open>' "$WORK/response")|$(grep -c 'name="title" maxlength="200" value="Pro Petra"' "$WORK/response")|$(b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=add -d title=X -d due_time=25:61 > /dev/null; b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=add -d title= > /dev/null; sq "SELECT COUNT(*) FROM ka_tasks")" \
  "$B327_N|1|1|$B327_N"
# an item: the form's date and time, its review date
b327_task "$JAR" collections "$B327_ITEM_SCREEN" -d "idk=$B327_IDK" -d "idp=$B327_ITEM" -d op=add --data-urlencode "title=Nová fotka" -d "due_date=$(site_date '+5 days')" -d due_time=10:00 -d remind=1d > /dev/null
B327_T4=$(sq "SELECT MAX(id) FROM ka_tasks WHERE target = 'item:$B327_ITEM'")
expect "3.27: a task on an item – the date and time of the form, the reminder a day before; the review offer stays (no task on that day yet)" \
  "$(b327_row "${B327_T4:-0}")|$(curl -s -b "$JAR" "$B/admin.php?$B327_ITEM_SCREEN" | grep -c 'data-task-review')" \
  "Nová fotka/$B327_ADMIN/$B327_ADMIN/$(site_date '+5 days') 10:00:00/$(site_date '+4 days') 10:00:00/open/-|1"
expect "3.27: an item of another collection is no target" "$(b327_task "$JAR" collections "$B327_ITEM_SCREEN" -d "idk=$((B327_IDK + 1000))" -d "idp=$B327_ITEM" -d op=add -d title=Y > /dev/null; sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'item:$B327_ITEM'")" "1"
# snooze, done, open again, edit
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=snooze -d "task=$B327_T2" -d snooze=tomorrow > /dev/null
expect "3.27: Snooze tomorrow – the due day moves, the time stays" "$(b327_row "$B327_T2")" "Zkontrolovat: Stránka B327/$B327_ADMIN/$B327_ADMIN/$(site_date '+1 day') 09:00:00/-/open/-"
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=done -d "task=$B327_T2" > /dev/null; B327_DONE=$(b327_row "$B327_T2")
check "3.27: a done task is folded under Done" 200 "/admin.php?$B327_PAGE_SCREEN" 'data-tasks-done'
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=reopen -d "task=$B327_T2" > /dev/null
expect "3.27: Done, then Reopen" "$B327_DONE|$(b327_row "$B327_T2")" "Zkontrolovat: Stránka B327/$B327_ADMIN/$B327_ADMIN/$(site_date '+1 day') 09:00:00/-/done/-|Zkontrolovat: Stránka B327/$B327_ADMIN/$B327_ADMIN/$(site_date '+1 day') 09:00:00/-/open/-"
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=edit -d "task=$B327_T3" --data-urlencode "title=Ceník – nové ceny" -d "assignee_id=$B327_JANA" -d "due_date=$(site_date '+2 days')" -d due_time= -d remind=keep > /dev/null
expect "3.27: Edit in place – the title and the day change, the time is the default, the reminder set before is kept" \
  "$(b327_row "$B327_T3")" "Ceník – nové ceny/$B327_JANA/$B327_ADMIN/$(site_date '+2 days') 09:00:00/$(site_date '+1 day') 13:30:00/open/-"
# N327-2: a task handed to nobody (a leaver's) – its edit form offers "nobody", chosen, and saving keeps it unassigned
B327_NOBODY=$(sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES ('N3272 orphan', 'page:$B327_PAGE', NULL, $B327_ADMIN, '$(site_date '+4 days') 09:00:00', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
check "N327-2: the edit form of a task for nobody" 200 "/admin.php?$B327_PAGE_SCREEN" "data-task=\"$B327_NOBODY\""
sed -n "/data-task=\"$B327_NOBODY\"/,/<\/li>/p" "$WORK/response" > "$WORK/n3272"
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=edit -d "task=$B327_NOBODY" --data-urlencode "title=N3272 orphan renamed" -d assignee_id=0 -d "due_date=$(site_date '+4 days')" -d due_time=09:00 -d remind= > /dev/null
expect "N327-2: … offers nobody, chosen; saving the edit keeps it unassigned and notifies nobody" \
  "$(grep -c '<option value="0" selected>nikdo</option>' "$WORK/n3272")|$(sq "SELECT CONCAT(title, '/', IFNULL(assignee_id, 'null')) FROM ka_tasks WHERE id = $B327_NOBODY")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'task:$B327_NOBODY'")" \
  "1|N3272 orphan renamed/null|0"
sq "DELETE FROM ka_tasks WHERE id = $B327_NOBODY" > /dev/null
# N327-4: a task already notified, its due moment stored with seconds (as "+2 hours" saved before) – saving the edit form with the
# same day and time (HH:MM) is no new moment: it does not notify again
B327_SEC=$(sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, notified_at, created_at, updated_at) VALUES ('N3274 seconds', 'page:$B327_PAGE', $B327_ADMIN, $B327_ADMIN, '$(site_date '+4 days') 11:15:42', '$(site_time)', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
b327_task "$JAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=edit -d "task=$B327_SEC" --data-urlencode "title=N3274 seconds renamed" -d "assignee_id=$B327_ADMIN" -d "due_date=$(site_date '+4 days')" -d due_time=11:15 -d remind= > /dev/null
expect "N327-4: the same due moment saved again from the edit form (to the minute) keeps the task notified" \
  "$(sq "SELECT CONCAT(title, '/', notified_at IS NOT NULL) FROM ka_tasks WHERE id = $B327_SEC")" "N3274 seconds renamed/1"
sq "DELETE FROM ka_tasks WHERE id = $B327_SEC" > /dev/null
B327_BEFORE=$(b327_row "$B327_T3")
b327_task "$JAR" enquiries "$B327_ENQ_SCREEN" -d "idp=$B327_ENQ" -d op=done -d "task=$B327_T3" > /dev/null
check "3.27: a task id of another record is not found through this one" 200 "/admin.php?$B327_ENQ_SCREEN" 'Úkol neexistuje.'
expect "3.27: … unchanged" "$(b327_row "$B327_T3")" "$B327_BEFORE"
# Jana (Pages, Enquiries, no Requests): she sees the tasks, works on her own, may not change the administrator's, cannot hand one to Claude
B327_JJAR=$(b327_login b327-jana)
curl -s -b "$B327_JJAR" -o "$WORK/response" "$B/admin.php?$B327_PAGE_SCREEN"
expect "3.27: Jana sees the page's tasks – actions on her own one, none on the administrator's, no Hand to Claude" "$(grep -o 'data-task="[0-9]*"' "$WORK/response" | wc -l | tr -d ' ')|$(grep -o 'data-task-actions' "$WORK/response" | wc -l | tr -d ' ')|$(grep -c 'data-task-claude' "$WORK/response")" "2|1|0"
b327_task "$B327_JJAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=delete -d "task=$B327_T2" > /dev/null
b327_task "$B327_JJAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=claude -d "task=$B327_T3" > /dev/null
expect "3.27: Jana cannot delete the administrator's task nor hand hers to Claude (no Requests) – both stay, no request" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id IN ($B327_T2, $B327_T3) AND done_at IS NULL")|$(sq "SELECT COUNT(*) FROM ka_requests WHERE title LIKE 'Ceník%'")" "2|0"
b327_task "$B327_JJAR" pages "$B327_PAGE_SCREEN" -d "ids=$B327_PAGE" -d op=done -d "task=$B327_T3" > /dev/null
expect "3.27: … she completes her own" "$(sq "SELECT CONCAT(done_by, '/', done_at IS NOT NULL) FROM ka_tasks WHERE id = $B327_T3")" "$B327_JANA/1"
# Petr (news only) never reaches the task action of a section he cannot open
B327_PJAR=$(b327_login b327-petr)
curl -s -b "$B327_PJAR" -o "$WORK/response" "$B/admin.php?module=news"; B327_PTOKEN=$(csrf)
expect "3.27: Petr without Enquiries, Pages and Collections gets 403 for their task action; nothing changes" \
  "$(curl -s -b "$B327_PJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=enquiries&action=task" -d "_csrf=$B327_PTOKEN" -d "idp=$B327_ENQ" -d op=delete -d "task=$B327_T1")|$(curl -s -b "$B327_PJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=task" -d "_csrf=$B327_PTOKEN" -d "ids=$B327_PAGE" -d op=add -d title=Z)|$(curl -s -b "$B327_PJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=collections&action=task" -d "_csrf=$B327_PTOKEN" -d "idk=$B327_IDK" -d "idp=$B327_ITEM" -d op=add -d title=Z)|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id = $B327_T1")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Z'")" \
  "403|403|403|1|0"
expect "3.27: without the form token nothing happens" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=enquiries&action=task" -d "idp=$B327_ENQ" -d op=delete -d "task=$B327_T1")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id = $B327_T1")" "400|1"
# hand to Claude: a request with the title and the link to the record; the task done, keeping the request's id; a link on the task
check "3.27: the administrator may hand a task to Claude (with a confirmation)" 200 "/admin.php?$B327_ENQ_SCREEN" 'data-task-claude data-potvrdit='
b327_task "$JAR" enquiries "$B327_ENQ_SCREEN" -d "idp=$B327_ENQ" -d op=claude -d "task=$B327_T1" > /dev/null
B327_REQ=$(sq "SELECT IFNULL(request_id, 0) FROM ka_tasks WHERE id = $B327_T1")
expect "3.27: Hand to Claude – a request titled like the task, linked to the enquiry, never its message; the task is done with the request's id" \
  "$(sq "SELECT CONCAT_WS('/', title, author_id, text LIKE '%action=detail&id=$B327_ENQ%', text LIKE '%Tajná%') FROM ka_requests WHERE id = $B327_REQ")|$(b327_row "$B327_T1" | cut -d/ -f6-)" \
  "Ozvat se: Poptávka B327/$B327_ADMIN/1/0|done/$B327_REQ"
check "3.27: … the task links its request" 200 "/admin.php?$B327_ENQ_SCREEN" "module=requests&amp;action=detail&amp;id=$B327_REQ"
expect "3.27: … handing it again is refused" "$(b327_task "$JAR" enquiries "$B327_ENQ_SCREEN" -d "idp=$B327_ENQ" -d op=claude -d "task=$B327_T1" > /dev/null; sq "SELECT COUNT(*) FROM ka_requests WHERE title = 'Ozvat se: Poptávka B327'")" "1"
# delete (with a confirmation), and a GET of the action changes nothing
expect "3.27: Delete asks first" "$(curl -s -b "$JAR" "$B/admin.php?$B327_ITEM_SCREEN" | grep -c 'data-task-delete data-potvrdit=')" "1"
b327_task "$JAR" collections "$B327_ITEM_SCREEN" -d "idk=$B327_IDK" -d "idp=$B327_ITEM" -d op=delete -d "task=$B327_T4" > /dev/null
expect "3.27: the item's task is deleted, logged; a GET of the action redirects and changes nothing" \
  "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id = $B327_T4")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'collections' AND akce = 'task deleted' AND popis = '#$B327_ITEM Položka B327 (task #$B327_T4)'")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=task&ids=$B327_PAGE&op=delete&task=$B327_T2")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE id = $B327_T2")" \
  "0|1|302|1"
# My work: "New task" – the same form, a task about nothing, for me by default or for any active colleague (even without Pages)
check "3.27: My work has New task with the shared form" 200 "/admin.php?module=my_work" 'data-new-task'
sed -n '/data-task-add>/,/<\/form>/p' "$WORK/response" > "$WORK/b327-form"
expect "3.27: … no record fields, me by default, Petr offered too (a task about nothing may go to any active colleague)" \
  "$(grep -c 'name="\(idp\|ids\|idk\)"' "$WORK/b327-form")|$(grep -c "<option value=\"$B327_ADMIN\" selected>" "$WORK/b327-form")|$(grep -c "<option value=\"$B327_PETR\">" "$WORK/b327-form")" "0|1|1"
REDIRECT=$(b327_task "$JAR" my_work "module=my_work" -d op=add --data-urlencode "title=Objednat papír B327" -d due_quick=week)
B327_T5=$(sq "SELECT MAX(id) FROM ka_tasks WHERE title = 'Objednat papír B327'")
b327_task "$JAR" my_work "module=my_work" -d op=add --data-urlencode "title=Pro Petra B327" -d "assignee_id=$B327_PETR" > /dev/null
expect "3.27: New task – mine about nothing in a week, back to My work; one for Petr, who is notified; a change-log row" \
  "$(b327_row "${B327_T5:-0}")|$(sq "SELECT IFNULL(target, 'none') FROM ka_tasks WHERE id = ${B327_T5:-0}")|$(echo "$REDIRECT" | grep -c 'module=my_work$')|$(sq "SELECT CONCAT(assignee_id, '/', IFNULL(target, 'none')) FROM ka_tasks WHERE title = 'Pro Petra B327'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $B327_PETR AND kind = 'assigned'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'my_work' AND akce = 'task_added' AND popis = '#$B327_T5 Objednat papír B327'")" \
  "Objednat papír B327/$B327_ADMIN/$B327_ADMIN/$(site_date '+7 days') 09:00:00/-/open/-|none|1|$B327_PETR/none|1|1"
b327_task "$JAR" my_work "module=my_work" -d op=add --data-urlencode "title=Špatný čas B327" -d due_time=99:99 > /dev/null
check "3.27: … a bad time is refused, the form opens again with the title" 200 "/admin.php?module=my_work" '<details class="ukol-pridat moje-novy-ukol" data-new-task open>'
expect "3.27: … nothing saved, the title kept" "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title LIKE 'Špatný čas%'")|$(grep -c 'value="Špatný čas B327"' "$WORK/response")" "0|1"
sq "DELETE FROM ka_tasks WHERE title IN ('Objednat papír B327', 'Pro Petra B327')" > /dev/null
sq "DELETE FROM ka_notifications WHERE target LIKE 'task:%' OR user_id IN ($B327_JANA, $B327_PETR); DELETE FROM ka_tasks WHERE target IN ('enquiry:$B327_ENQ', 'page:$B327_PAGE', 'item:$B327_ITEM');
  DELETE FROM ka_request_messages WHERE request_id = ${B327_REQ:-0}; DELETE FROM ka_requests WHERE id = ${B327_REQ:-0};
  DELETE FROM ka_poptavky WHERE idp = $B327_ENQ; DELETE FROM ka_stranky WHERE ids = $B327_PAGE; DELETE FROM ka_kolekce_polozky WHERE idk = $B327_IDK; DELETE FROM ka_kolekce WHERE idk = $B327_IDK;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($B327_JANA, $B327_PETR); DELETE FROM ka_uzivatele WHERE user LIKE 'b327-%'" > /dev/null
