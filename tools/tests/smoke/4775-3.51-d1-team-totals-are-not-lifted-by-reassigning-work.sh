echo "== 3.51 D1 (SECURITY-3): a colleague who puts a task or an enquiry on someone else does not lift the team totals – screen and MCP; a third person or an administrator does"
d775_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1" -- "${@:2}"); }
d775_cell() { php -r '$d = new DOMDocument(); @$d->loadHTML((string) file_get_contents($argv[1])); $x = new DOMXPath($d);
  $n = $x->query("//table[@data-team-summary]//tr[@data-person=\"team\"]/td[@data-count=\"" . $argv[2] . "\"]");
  echo $n->length === 1 ? trim($n->item(0)->textContent) : "hidden";' -- "$1" "$2"; }
# the open tasks and open enquiries of earlier sections are set aside, so the people who count are known
D775_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "DROP TABLE IF EXISTS d775_tasks; CREATE TABLE d775_tasks LIKE ka_tasks; INSERT INTO d775_tasks SELECT * FROM ka_tasks; DELETE FROM ka_tasks;
  DROP TABLE IF EXISTS d775_open; CREATE TABLE d775_open AS SELECT idp, stav FROM ka_poptavky WHERE stav < 2; UPDATE ka_poptavky SET stav = 2 WHERE stav < 2;
  DELETE FROM ka_kontrola_ip WHERE typ = 'login';
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
    ('d775view', '$D775_HASH', 'Viewer D775', 'd775view@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0, 'off'),
    ('d775col', '$D775_HASH', 'Colleague D775', 'd775col@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0, 'off'),
    ('d775b', '$D775_HASH', 'Third D775', 'd775b@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user IN ('d775view', 'd775col', 'd775b')" > /dev/null
D775_V=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'd775view'"); D775_C=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'd775col'"); D775_B=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'd775b'")
D775_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
D775_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($D775_V, 'd775 viewer', 'full', 'all', SHA2('$D775_TOKEN', 256), '$(site_time)');
  INSERT INTO ka_tasks (title, assignee_id, created_by, assigned_by, created_at, updated_at) VALUES ('D775 mine', $D775_V, $D775_V, $D775_V, '$(site_time)', '$(site_time)'), ('D775 theirs', $D775_C, $D775_C, $D775_C, '$(site_time)', '$(site_time)');
  INSERT INTO ka_poptavky (datum, formular, email, data, stav, prirazeno) VALUES ('$(site_time)', 'D775', 'd775-1@example.com', '[]', 1, $D775_V), ('$(site_time)', 'D775', 'd775-2@example.com', '[]', 1, $D775_C), ('$(site_time)', 'D775', 'd775-3@example.com', '[]', 0, NULL)" > /dev/null
D775_ENQ=$(sq "SELECT idp FROM ka_poptavky WHERE email = 'd775-3@example.com'")
D775_JAR="$WORK/jar-d775"; rm -f "$D775_JAR"; curl -s -c "$D775_JAR" -o "$WORK/response" "$B/admin.php"
curl -s -b "$D775_JAR" -c "$D775_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=d775view --data-urlencode "password=$PASSWORD"
d775_team() { # → "<screen: open tasks>|<screen: open enquiries>|<MCP: tasks_open>|<MCP: enquiries_open>" as the viewer sees them
  curl -s -b "$D775_JAR" -c "$D775_JAR" -o "$WORK/d775.html" "$B/admin.php?module=my_work&action=summary&period=7_days"
  mcp_call "$D775_TOKEN" team_summary '{"period":"7_days"}' > "$WORK/response"; mcp_text
  echo "$(d775_cell "$WORK/d775.html" tasks_open)|$(d775_cell "$WORK/d775.html" enquiries_open)|$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $t = is_array($j["team"] ?? null) ? $j["team"] : []; echo ($t["tasks_open"] ?? "hidden") . "|" . ($t["enquiries_open"] ?? "hidden");' -- "$WORK/text")"; }
expect "3.51 D1: the viewer and one colleague have open work – the totals stay hidden from the viewer (screen and MCP)" "$(d775_team)" "hidden|hidden|hidden|hidden"

# the viewer gives a new task to the third person and the unassigned enquiry to them (MCP) – a planted count, still hidden
mcp_call "$D775_TOKEN" save_task "{\"title\":\"D775 planted\",\"assignee\":\"$D775_B\"}" > /dev/null
mcp_call "$D775_TOKEN" update_enquiry "{\"id\":$D775_ENQ,\"assignee\":\"$D775_B\"}" > /dev/null
expect "3.51 D1: the task and the enquiry are on the third person, stamped with the viewer" \
  "$(sq "SELECT CONCAT(assignee_id = $D775_B, assigned_by = $D775_V) FROM ka_tasks WHERE title = 'D775 planted'")|$(sq "SELECT CONCAT(prirazeno = $D775_B, assigned_by = $D775_V) FROM ka_poptavky WHERE idp = $D775_ENQ")" "11|11"
expect "3.51 D1: what the viewer planted on a third account does not lift the threshold – both totals stay hidden from the viewer" "$(d775_team)" "hidden|hidden|hidden|hidden"
expect "3.51 D1: an administrator sees every total and person all the same" \
  "$(d775_php '$s = Kaleta\Core\TeamSummary::build($app, ["tasks", "enquiries"], "7_days", (int) $argv[1], true); echo $s["team"]["tasks_open"], "|", $s["team"]["enquiries_open"], "|", count(array_filter($s["people"], fn ($p) => ($p["counts"]["tasks_open"] ?? 0) + ($p["counts"]["enquiries_open"] ?? 0) > 0));' "$D775_ADMIN")" "3|3|3"

# the administrator saves the enquiry's Handling form with the same person and a new summary – the viewer's stamp stays
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$D775_ENQ"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$(csrf)" -d "id=$D775_ENQ" -d "prirazeno=$D775_B" --data-urlencode "poznamka=Summary D775"
expect "3.51 D1: a summary saved with the same person keeps who assigned it – the enquiry stays hidden from the viewer" \
  "$(sq "SELECT CONCAT_WS('|', poznamka, assigned_by = $D775_V) FROM ka_poptavky WHERE idp = $D775_ENQ")|$(d775_team | cut -d'|' -f2)" "Summary D775|1|hidden"

# a third person (the administrator) puts work on the third account: now enough other people really have it – shown
d775_php 'Kaleta\Core\Tasks::create($app, ["title" => "D775 from the admin", "assignee_id" => (int) $argv[1]], (int) $argv[2]);' "$D775_B" "$D775_ADMIN" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$D775_ENQ"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$(csrf)" -d "id=$D775_ENQ" -d "prirazeno=0" --data-urlencode "poznamka=Summary D775"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$D775_ENQ"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=triage" -d "_csrf=$(csrf)" -d "id=$D775_ENQ" -d "prirazeno=$D775_B" --data-urlencode "poznamka=Summary D775"
expect "3.51 D1: work the administrator put on the third person counts – the viewer sees both totals (tasks 4, enquiries 3)" \
  "$(sq "SELECT assigned_by = $D775_ADMIN FROM ka_poptavky WHERE idp = $D775_ENQ")|$(d775_team)" "1|4|3|4|3"

sq "DELETE FROM ka_tasks; INSERT INTO ka_tasks SELECT * FROM d775_tasks; DROP TABLE d775_tasks;
  DELETE FROM ka_notifications WHERE user_id IN ($D775_V, $D775_C, $D775_B); DELETE FROM ka_poptavky WHERE formular = 'D775';
  UPDATE ka_poptavky p JOIN d775_open o ON o.idp = p.idp SET p.stav = o.stav; DROP TABLE d775_open;
  DELETE FROM ka_api_tokeny WHERE idu = $D775_V; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($D775_V, $D775_C, $D775_B);
  DELETE FROM ka_uzivatele WHERE idu IN ($D775_V, $D775_C, $D775_B); DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
