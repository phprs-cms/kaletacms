echo "== 3.42 B: the team hierarchy – departments, reports to, absences with a deputy, the right contact, the org chart"
h342_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $f = require $argv[1]; echo $f($app, array_slice($argv, 2));' -- "$@"); }
h342_csrf() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/$1"; csrf; }
H342_ADM=$(sq "SELECT MIN(idu) FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0")
# three colleagues: a head of sales, whom the head reports to, a salesperson; the signed-in administrator will be the deputy
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, pozice) VALUES ('head-342', 'x', 'Hana Head', 'head342@example.cz', 2, 'Head of sales'),
    ('boss-342', 'x', 'Boris Boss', 'boss342@example.cz', 2, 'Director'), ('sales-342', 'x', 'Sid Sales', 'sales342@example.cz', 2, '')" > /dev/null
H342_HEAD=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'head-342'"); H342_BOSS=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'boss-342'"); H342_SALES=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'sales-342'")
H342_FORM=$(sq "SELECT formular FROM ka_poptavky WHERE zdroj = '$FORM_SOURCE' AND prvek = '$FORM_ELEMENT' ORDER BY idp DESC LIMIT 1")

# Users → Departments: a department with a head and the contact form, one under it; a circle is refused
check "3.42: Users → Departments opens with the empty state" 200 "/admin.php?module=users&action=departments" "Zatím žádná oddělení."
H342_CSRF=$(h342_csrf "admin.php?module=users&action=departments")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=department_save" -d "_csrf=$H342_CSRF" -d id=0 --data-urlencode "name=Sales 342" -d "head_id=$H342_HEAD" -d parent_id= --data-urlencode "enquiry_forms=$H342_FORM"
H342_DEP=$(sq "SELECT id FROM ka_departments WHERE name = 'Sales 342'"); H342_DEP="${H342_DEP:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=department_save" -d "_csrf=$H342_CSRF" -d id=0 --data-urlencode "name=Export 342" -d head_id= -d "parent_id=$H342_DEP" -d enquiry_forms=
H342_SUB=$(sq "SELECT id FROM ka_departments WHERE name = 'Export 342'"); H342_SUB="${H342_SUB:-0}"
expect "3.42: a department keeps its head, parent and forms" "$(sq "SELECT CONCAT(head_id = $H342_HEAD, '|', IFNULL(parent_id, '-'), '|', enquiry_forms) FROM ka_departments WHERE id = $H342_DEP")|$(sq "SELECT parent_id FROM ka_departments WHERE id = $H342_SUB")" "1|-|$H342_FORM|$H342_DEP"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=department_save" -d "_csrf=$H342_CSRF" -d "id=$H342_DEP" --data-urlencode "name=Sales 342" -d "head_id=$H342_HEAD" -d "parent_id=$H342_SUB" --data-urlencode "enquiry_forms=$H342_FORM"
expect "3.42: a department cannot go under a department below it (a circle)" "$(sq "SELECT IFNULL(parent_id, '-') FROM ka_departments WHERE id = $H342_DEP")" "-"
check "3.42: the tree lists the sub-department indented" 200 "/admin.php?module=users&action=departments" "– <a href="

# the head's account: department and reports to; a circle of reporting is refused
h342_save() { local who=$1 login=$2; shift 2; H342_CSRF=$(h342_csrf "admin.php?module=users&action=edit&id=$who")
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$H342_CSRF" -d "idu=$who" -d "user=$login" -d admin=2 -d team_item_id= "$@"; }
h342_save "$H342_HEAD" head-342 --data-urlencode "jmeno=Hana Head" --data-urlencode email=head342@example.cz -d "department_id=$H342_DEP" -d "reports_to=$H342_BOSS"
h342_save "$H342_SALES" sales-342 --data-urlencode "jmeno=Sid Sales" --data-urlencode email=sales342@example.cz -d "department_id=$H342_SUB" -d "reports_to=$H342_HEAD"
expect "3.42: Users saves the department and whom the user reports to" "$(sq "SELECT CONCAT(department_id = $H342_DEP, '|', reports_to = $H342_BOSS) FROM ka_uzivatele WHERE idu = $H342_HEAD")" "1|1"
h342_save "$H342_BOSS" boss-342 --data-urlencode "jmeno=Boris Boss" --data-urlencode email=boss342@example.cz -d department_id= -d "reports_to=$H342_SALES"
expect "3.42: reporting in a circle is refused with the reason" "$(grep -c 'Vznikl by kruh' "$WORK/response")|$(sq "SELECT IFNULL(reports_to, '-') FROM ka_uzivatele WHERE idu = $H342_BOSS")" "1|-"

# the head is away from today with the administrator as the deputy; a wrong absence is refused
H342_CSRF=$(h342_csrf "admin.php?module=users&action=edit&id=$H342_HEAD")
grep -q 'id="absences"' "$WORK/response" && grep -q 'name="reason"' "$WORK/response" && ! grep -q '<textarea[^>]*name="reason"' "$WORK/response" \
  && echo "  ok     3.42: the user form has the absences with a fixed list of reasons" || { echo "  CHYBA  3.42: absences in the user form"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=absence_add" -d "_csrf=$H342_CSRF" -d "idu=$H342_HEAD" -d "starts_on=$(site_date '+3 days')" -d "ends_on=$(site_date '+1 day')" -d reason=holiday -d "deputy_id=$H342_ADM"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=absence_add" -d "_csrf=$H342_CSRF" -d "idu=$H342_HEAD" -d "starts_on=$(site_date now)" -d "ends_on=$(site_date '+5 days')" -d reason=sick -d "deputy_id=$H342_ADM"
expect "3.42: an absence ending before it starts, or with a reason not on the list, is refused" "$(sq "SELECT COUNT(*) FROM ka_absences WHERE user_id = $H342_HEAD")" "0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=absence_add" -d "_csrf=$H342_CSRF" -d "idu=$H342_HEAD" -d "starts_on=$(site_date now)" -d "ends_on=$(site_date '+5 days')" -d reason=holiday -d "deputy_id=$H342_ADM"
expect "3.42: the absence is saved with its deputy" "$(sq "SELECT CONCAT(reason, '|', deputy_id = $H342_ADM, '|', ends_on) FROM ka_absences WHERE user_id = $H342_HEAD")" "holiday|1|$(site_date '+5 days')"

# the right contact: a new enquiry from the department's form goes to the deputy while the head is away, and says so
background_jobs hold
sleep 1
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null # earlier submissions from this address must not hit the limit
# a fresh signature (the one 0150 read may be older than the secret's last rotation): ten seconds ago, as a person would
H342_AT=$(( $(date +%s) - 10 )); H342_SIG=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$FORM_SOURCE|$FORM_ELEMENT|$H342_AT" "$(setting_plain secret_key)")
H342_SENT=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$H342_AT" -d "as_podpis=$H342_SIG" --data-urlencode "p0=Rita Route 342" --data-urlencode p1=rita342@example.com -d p2=777342342 --data-urlencode "p3=Routing test" -d p4=1)
case "$H342_SENT" in *result=ok*) echo "  ok     3.42: the enquiry from the department's form is sent";; *) echo "  CHYBA  3.42: sending the form: $H342_SENT"; ERRORS=$((ERRORS+1));; esac
H342_ENQ=$(sq "SELECT idp FROM ka_poptavky WHERE email = 'rita342@example.com' ORDER BY idp DESC LIMIT 1"); H342_ENQ="${H342_ENQ:-0}"
expect "3.42: the new enquiry is handled by the deputy, never by the away head" "$(sq "SELECT IFNULL(prirazeno, '-') FROM ka_poptavky WHERE idp = $H342_ENQ")" "$H342_ADM"
expect "3.42: the deputy's notification says whom they stand in for" "$(sq "SELECT CONCAT(kind, '|', standing_in_for) FROM ka_notifications WHERE user_id = $H342_ADM AND target = 'enquiry:$H342_ENQ'")" "assigned|Hana Head"
check "3.42: the bell shows the stand-in line" 200 "/admin.php?action=inbox" "Zastupujete Hana Head"

# every notification for the away head reaches them and a copy reaches the deputy – a mention stays theirs
cat > "$WORK/h342-notify.php" <<'PHP'
<?php return function (Kaleta\Core\App $app, array $a): string {
    Kaleta\Core\Inbox::notify($app, (int) $a[0], 'task_due', 'task:999342', null, 'Call the customer back');
    Kaleta\Core\Inbox::notify($app, (int) $a[0], 'mention', 'page:999342', null, 'hello');
    return 'ok';
};
PHP
h342_php "$WORK/h342-notify.php" "$H342_HEAD" > /dev/null
expect "3.42: a task reminder of the away head goes to them and, marked, to the deputy; a mention only to them" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(user_id = $H342_HEAD, ':', kind, ':', standing_in_for) ORDER BY id SEPARATOR ',') FROM ka_notifications WHERE target IN ('task:999342', 'page:999342')")" \
  "1:task_due:,0:task_due:Hana Head,1:mention:"

# escalation: a task of the salesperson three days overdue goes once to the head they report to – the head is away, so the deputy gets the copy
sq "INSERT INTO ka_tasks (title, assignee_id, created_by, due_at, created_at, updated_at) VALUES ('Send the offer 342', $H342_SALES, $H342_SALES, '$(site_time '-4 days')', '$(site_time '-5 days')', '$(site_time '-5 days')'),
    ('Ancient task 342', $H342_SALES, $H342_SALES, '$(site_time '-60 days')', '$(site_time '-61 days')', '$(site_time '-61 days')')" > /dev/null
cat > "$WORK/h342-escalate.php" <<'PHP'
<?php return fn (Kaleta\Core\App $app, array $a): string => (string) Kaleta\Core\Tasks::escalate($app, Kaleta\Core\Clock::now()) . '/' . Kaleta\Core\Tasks::escalate($app, Kaleta\Core\Clock::now());
PHP
expect "3.42: the overdue task is escalated once, the ancient one never" "$(h342_php "$WORK/h342-escalate.php")" "1/0"
expect "3.42: the head gets 'escalated' with the salesperson as the actor, the deputy a marked copy" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(user_id = $H342_HEAD, ':', actor_name, ':', standing_in_for) ORDER BY id SEPARATOR ',') FROM ka_notifications WHERE kind = 'escalated'")" "1:Sid Sales:,0:Sid Sales:Hana Head"
background_jobs resume

# the org chart: departments nested, head first, reports to, away until with the deputy – never the reason
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account&tab=org_chart"
grep -q 'class="organigram"' "$WORK/response" && grep -q 'Sales 342' "$WORK/response" && grep -q 'Export 342' "$WORK/response" && grep -q 'Nadřízený: Boris Boss' "$WORK/response" \
  && grep -q 'Pryč do' "$WORK/response" && grep -q 'action=account&amp;tab=org_chart' "$WORK/response" && ! grep -q 'Dovolená' "$WORK/response" && ! grep -q '<script' <(sed -n '/<main/,/<\/main>/p' "$WORK/response") \
  && echo "  ok     3.42: the org chart shows the departments, who reports to whom and who is away – no reason, no script" || { echo "  CHYBA  3.42: the org chart"; ERRORS=$((ERRORS+1)); }
[ "$(grep -o '<ul class="organigram">' "$WORK/response" | wc -l | tr -d ' ')" -ge 2 ] && echo "  ok     3.42: a sub-department is a nested list" || { echo "  CHYBA  3.42: nesting of the org chart"; ERRORS=$((ERRORS+1)); }

# My account: one's own absence, added and removed
H342_CSRF=$(h342_csrf "admin.php?action=account")
grep -q 'id="absences"' "$WORK/response" && echo "  ok     3.42: My account has the own absences" || { echo "  CHYBA  3.42: absences in My account"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$H342_CSRF" -d co=absence_add -d "starts_on=$(site_date '+10 days')" -d "ends_on=$(site_date '+12 days')" -d reason=training -d "deputy_id=$H342_BOSS"
H342_OWN=$(sq "SELECT id FROM ka_absences WHERE user_id = $H342_ADM"); H342_OWN="${H342_OWN:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$H342_CSRF" -d co=absence_delete -d "absence=$(sq "SELECT id FROM ka_absences WHERE user_id = $H342_HEAD")"
expect "3.42: My account adds an own absence and removes only an own one" "$(sq "SELECT COUNT(*) FROM ka_absences WHERE id = $H342_OWN AND reason = 'training'")|$(sq "SELECT COUNT(*) FROM ka_absences WHERE user_id = $H342_HEAD")" "1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$H342_CSRF" -d co=absence_delete -d "absence=$H342_OWN"
expect "3.42: the own absence is removed" "$(sq "SELECT COUNT(*) FROM ka_absences WHERE user_id = $H342_ADM")" "0"

# MCP list_colleagues: department, reports_to, absent_until, deputy and the departments – never the reason
mcp list_colleagues '{}' > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/h342.json"
grep -q "\"department\":\"Sales 342\",\"reports_to\":$H342_BOSS,\"absent_until\":\"$(site_date '+5 days')\",\"deputy\":$H342_ADM" "$WORK/h342.json" \
  && grep -q "\"departments\":\[" "$WORK/h342.json" && grep -q "{\"id\":$H342_SUB,\"name\":\"Export 342\",\"parent\":$H342_DEP}" "$WORK/h342.json" && ! grep -q 'holiday' "$WORK/h342.json" \
  && echo "  ok     3.42: MCP list_colleagues carries the hierarchy and the absence – no reason" || { echo "  CHYBA  3.42: MCP list_colleagues: $(head -c 600 "$WORK/h342.json")"; ERRORS=$((ERRORS+1)); }

# removing a department: its people belong to none, the one under it moves up
H342_CSRF=$(h342_csrf "admin.php?module=users&action=departments")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=department_delete" -d "_csrf=$H342_CSRF" -d "id=$H342_DEP"
expect "3.42: a removed department leaves its people in none and lifts the one under it" \
  "$(sq "SELECT COUNT(*) FROM ka_departments WHERE id = $H342_DEP")|$(sq "SELECT IFNULL(department_id, '-') FROM ka_uzivatele WHERE idu = $H342_HEAD")|$(sq "SELECT IFNULL(parent_id, '-') FROM ka_departments WHERE id = $H342_SUB")" "0|-|-"

sq "DELETE FROM ka_notifications WHERE target IN ('task:999342', 'page:999342', 'enquiry:$H342_ENQ') OR kind = 'escalated'; DELETE FROM ka_tasks WHERE title IN ('Send the offer 342', 'Ancient task 342');
    DELETE FROM ka_absences WHERE user_id IN ($H342_HEAD, $H342_BOSS, $H342_SALES); DELETE FROM ka_departments; UPDATE ka_poptavky SET prirazeno = NULL WHERE idp = $H342_ENQ;
    DELETE FROM ka_uzivatele WHERE idu IN ($H342_HEAD, $H342_BOSS, $H342_SALES)" > /dev/null
