echo "== 3.30 (Y): console tasks per site or customer (Fleet\ConsoleTasks over Core\Tasks), the connector hint, MCP fleet_plan and the prompt fleet_fix"
y330_mcp() { curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "$2"; }
y330_call() { y330_mcp "$CON_TOKEN" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
Y330_ADMIN=$(sq3 "SELECT idu FROM ka_uzivatele WHERE user = 'admin'"); Y330_ACME=$(sq3 "SELECT name FROM ka_fleet_customers WHERE id = $ACME317")
# the site's screen: the connector of the site (its /mcp from the known address, no token) with Copy, and its task panel
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet&action=detail&id=$HOST329" > "$WORK/code330.txt"
expect "3.30: the site's screen shows its own Claude connector (plain address, Copy button) and the panel of its console tasks" \
  "$(cat "$WORK/code330.txt")|$(grep -c '<code id="fleet-connector">http://hostile329b.test/mcp</code> <button class="navigace" type="button" data-kopirovat="#fleet-connector">' "$WORK/response")|$(grep -c "data-tasks=\"fleet_site:$HOST329\"" "$WORK/response")|$(grep -c 'hostile329bkey\|evil329b' "$WORK/response")" "200|1|1|0"
# a task overdue for me about the site, and one about the customer due tomorrow (a quick button)
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=task" -d "_csrf=$(csrf)" -d "site=$HOST329" -d op=add --data-urlencode "title=Renew Lindner's domain 330" -d "due_date=$(site_date '-2 days')" -d due_time=10:00 -d assignee_id=0
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=customer_detail&id=$ACME317"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=task" -d "_csrf=$(csrf)" -d "customer=$ACME317" -d op=add --data-urlencode "title=Check prices at Acme before January 330" -d due_quick=tomorrow
Y330_T1=$(sq3 "SELECT id FROM ka_tasks WHERE title = 'Renew Lindner''s domain 330'"); Y330_T2=$(sq3 "SELECT id FROM ka_tasks WHERE title = 'Check prices at Acme before January 330'")
expect "3.30: a console task about a site and one about a customer – ordinary tasks for me, due when asked" \
  "$(sq3 "SELECT CONCAT(target, '|', assignee_id, '|', due_at) FROM ka_tasks WHERE id = ${Y330_T1:-0}")#$(sq3 "SELECT CONCAT(target, '|', assignee_id, '|', DATE(due_at)) FROM ka_tasks WHERE id = ${Y330_T2:-0}")" \
  "fleet_site:$HOST329|$Y330_ADMIN|$(site_date '-2 days') 10:00:00#fleet_customer:$ACME317|$Y330_ADMIN|$(site_date '+1 day')"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet&action=customer_detail&id=$ACME317" > "$WORK/code330.txt"
expect "3.30: the customer's screen – its sites and the panel of its tasks" \
  "$(cat "$WORK/code330.txt")|$(grep -c "data-tasks=\"fleet_customer:$ACME317\"" "$WORK/response")|$(grep -c 'Check prices at Acme before January 330' "$WORK/response")|$(grep -c 'data-customer-sites' "$WORK/response")|$(grep -c "action=detail&amp;id=$BETA317\"" "$WORK/response")" "200|1|1|1|1"
expect "3.30: an unknown customer's screen goes back to the customers" "$(curl -s -b "$JAR_CON" -o /dev/null -w '%{http_code}' "$B3/admin.php?module=fleet&action=customer_detail&id=999999")" "302"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=sites&show=all"
grep -qF "<td data-popisek=\"Úkoly\" class=\"stred\" data-fleet-tasks><a href=\"/admin.php?module=fleet&amp;action=detail&amp;id=$HOST329#tasks\">1</a></td>" "$WORK/response" \
  && echo "  ok     3.30: Sites count the open console tasks of each site" || { echo "  CHYBA  3.30: the Tasks column of Sites"; grep -o '<td[^>]*data-fleet-tasks>[^/]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=customers"
grep -qF "action=customer_detail&amp;id=$ACME317#tasks\">1</a>" "$WORK/response" && grep -qF "action=customer_detail&amp;id=$ACME317\"><strong>" "$WORK/response" \
  && echo "  ok     3.30: Customers count the open tasks of each customer and open the customer's screen" || { echo "  CHYBA  3.30: the Tasks column of Customers"; ERRORS=$((ERRORS+1)); }
# Today: the overdue task is an amber item of kind task, ranked with the checks, opening the task on the console; filters still work
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
expect "3.30 Today: the overdue console task is there (amber, its site, the link to the task on the console), the customer's task due tomorrow is not, the summary counts it" \
  "$(grep -A6 "data-fleet-task=\"${Y330_T1:-0}\"" "$WORK/response" | grep -c "class=\"flotila-warn\"\|href=\"/admin.php?module=fleet&amp;action=detail&amp;id=$HOST329#tasks\"")|$(grep -c "data-fleet-task=\"${Y330_T2:-0}\"" "$WORK/response")|$(grep -A1 'data-fleet-overdue-tasks' "$WORK/response" | grep -c '<strong>1</strong>')" "2|0|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&severity=bad"
expect "3.30 Today: the severity filter red leaves the amber task out; the customer filter too (the site has no customer)" \
  "$(grep -c 'data-fleet-task=' "$WORK/response")|$(curl -s -b "$JAR_CON" "$B3/admin.php?module=fleet&customer=$ACME317" | grep -c 'data-fleet-task=')|$(curl -s -b "$JAR_CON" "$B3/admin.php?module=fleet&severity=warn" | grep -c 'data-fleet-task=')" "0|0|1"
# My work lists it with what it is about; the reminder job notifies me once in the bell (task_due, the site as the target)
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=my_work"
expect "3.30 My work: the console tasks with the site and the customer they are about, linking to their panels" \
  "$(grep -c "Renew Lindner&#039;s domain 330</a><br><a class=\"smltxt\" href=\"/admin.php?module=fleet&amp;action=detail&amp;id=$HOST329#tasks\">Web „B329 &lt;script&gt;" "$WORK/response")|$(grep -c "href=\"/admin.php?module=fleet&amp;action=customer_detail&amp;id=$ACME317#tasks\">Zákazník konzole „$Y330_ACME“" "$WORK/response")" "1|1"
Y330_RUN=$(cd "$WORK/web3" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); echo Kaleta\Core\Tasks::run($app);')
expect "3.30 reminder: the job tasks notifies me of the overdue console task once (task_due, the site), not of tomorrow's" \
  "$(echo "$Y330_RUN" | grep -c 'notified 1')|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'task_due' AND user_id = $Y330_ADMIN AND target = 'fleet_site:$HOST329' AND excerpt = 'Renew Lindner''s domain 330'")|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE target = 'fleet_customer:$ACME317'")" "1|1|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?action=inbox"
grep -qF 'Web „B329 &lt;script&gt;alert(329)&lt;/script&gt; Hostile“' "$WORK/response" && echo "  ok     3.30: the bell names the site of the console task (escaped)" || { echo "  CHYBA  3.30: the bell"; ERRORS=$((ERRORS+1)); }
# the fleet plan for Claude: per site the items, the tasks, the site's own connector and the order; read-only, admins only, only on a console
y330_call fleet_plan '{}' > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true); $by = array_column($d["sites"] ?? [], null, "id"); $h = $by[(int) $argv[2]] ?? [];
  $t = array_column($h["tasks"] ?? [], null, "id")[(int) $argv[3]] ?? []; $c = $d["customer_tasks"][0] ?? [];
  echo implode("|", [$h["connector"] ?? "-", $h["url"] ?? "-", count($h["items"] ?? []) > 0 ? "items" : "-", ($t["overdue"] ?? false) ? "overdue" : "-", str_ends_with($t["admin_url"] ?? "", "action=detail&id=" . $argv[2] . "#tasks") ? "link" : "-",
    count(array_filter($h["order"] ?? [], fn ($o) => str_starts_with($o, "Task #" . $argv[3]))), ($c["id"] ?? 0) === (int) $argv[4] ? "customer" : "-", isset($d["how"], $d["note"], $d["written_on_the_site"]) ? "how" : "-",
    preg_match("/key|token|secret|heartbeat|password/i", implode(",", array_keys($h))) ? "leak" : "clean", str_contains(file_get_contents($argv[1]), "hostile329bkey") || str_contains(file_get_contents($argv[1]), "evil329b") ? "leak" : "clean"]);' -- \
  "$WORK/response" "$HOST329" "${Y330_T1:-0}" "${Y330_T2:-0}" > "$WORK/plan330.txt"
expect "3.30 MCP fleet_plan: the hostile site with its items, its overdue task in the order with the console link, its connector from the known address; the customer's task apart; no key" \
  "$(cat "$WORK/plan330.txt")" "http://hostile329b.test/mcp|http://hostile329b.test|items|overdue|link|1|customer|how|clean|clean"
y330_call fleet_plan "{\"customer\":\"$Y330_ACME\",\"severity\":\"amber\",\"tasks\":false}" > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true);
  echo implode("|", [str_contains(file_get_contents($argv[1]), "hostile329b") ? "other" : "only", count(array_filter($d["sites"] ?? [], fn ($s) => array_filter($s["items"], fn ($i) => $i["state"] !== "warn") !== [])), count($d["customer_tasks"] ?? [1]), count(array_merge(...array_map(fn ($s) => $s["tasks"], $d["sites"] ?? [])))]);' -- "$WORK/response" > "$WORK/plan330.txt"
expect "3.30 MCP fleet_plan: the customer filter, amber = warn only, without tasks" "$(cat "$WORK/plan330.txt")" "only|0|0|0"
y330_call fleet_plan "{\"site\":$HOST329,\"checks\":[\"uptime\"]}" > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true);
  echo implode("|", [count($d["sites"] ?? []), implode(",", array_unique(array_column($d["sites"][0]["items"] ?? [], "check"))), count($d["customer_tasks"] ?? [1])]);' -- "$WORK/response" > "$WORK/plan330.txt"
expect "3.30 MCP fleet_plan: one site, one check, no customer tasks" "$(cat "$WORK/plan330.txt")" "1|uptime|0"
expect "3.30 MCP fleet_plan: an unknown severity, check or site is refused" \
  "$(y330_call fleet_plan '{"severity":"purple"}' | grep -c 'severity must be one of')|$(y330_call fleet_plan '{"checks":["nope"]}' | grep -c 'Unknown check: nope')|$(y330_call fleet_plan '{"site":999999}' | grep -c 'not in the console')" "1|1|1"
# only administrators of the console; only on a console; a console task only where the console is on
sq3 "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('editor330', '!', 'Editor 330', 1, '$(site_time)', '$(site_time)')" > /dev/null
Y330_ETOKEN="kaleta_$(printf 'e%.0s' $(seq 1 48))"
sq3 "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$Y330_ETOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'editor330'" > /dev/null
y330_mcp "$Y330_ETOKEN" '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_plan","arguments":{}}}' > "$WORK/response"
contains -q '"isError":true' "$WORK/response" && ! contains -q 'connector' "$WORK/response" && echo "  ok     3.30 MCP fleet_plan: refused to someone who is not an administrator" || { echo "  CHYBA  3.30 fleet_plan for an editor"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp fleet_plan '{}' > "$WORK/response"; contains -q '"connector"' "$WORK/response" && { echo "  CHYBA  fleet_plan works on a site that is not a console"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.30: fleet_plan exists only on a console"
mcp save_task '{"title":"Console task off the console","target":"fleet_site:1"}' > "$WORK/response"
expect "3.30: no console task where the console is off" "$(grep -c 'does not exist' "$WORK/response")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Console task off the console'")" "1|0"
# N330-4: the console is for administrators only – a forged or stale section right never makes an editor a candidate for a console task
sq3 "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'fleet' FROM ka_uzivatele WHERE user = 'editor330'" > /dev/null
y330_call save_task "{\"title\":\"Forged right 330\",\"target\":\"fleet_site:$HOST329\",\"assignee\":\"editor330\"}" > "$WORK/response"
expect "3.30 review N330-4: an editor with a forged fleet right gets no console task and cannot list the console tasks" \
  "$(sq3 "SELECT COUNT(*) FROM ka_tasks WHERE title = 'Forged right 330'")|$(y330_mcp "$Y330_ETOKEN" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"list_tasks\",\"arguments\":{\"filter\":\"target:fleet_site:$HOST329\"}}}" | grep -c 'the fleet console can see the tasks')" "0|1"
sq3 "DELETE p FROM ka_uzivatele_prava p JOIN ka_uzivatele u ON u.idu = p.fk_id_user WHERE u.user = 'editor330' AND p.ident_modulu = 'fleet'" > /dev/null
expect "3.30: the prompt fleet_fix is listed on the console (and runs fleet_plan), not on a site that is not a console" \
  "$(y330_mcp "$CON_TOKEN" '{"jsonrpc":"2.0","id":1,"method":"prompts/list"}' | grep -c '"name":"fleet_fix"')|$(y330_mcp "$CON_TOKEN" '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"fleet_fix","arguments":{"severity":"amber"}}}' | grep -c 'only the amber items. You are connected to a Kaleta fleet console')|$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/list"}' | grep -c 'fleet_fix')" "1|1|0"
# Claude reports back on the console: complete_task with the id from the plan; a task id of another record never reaches it through a panel
y330_call complete_task "{\"id\":${Y330_T1:-0}}" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$HOST329"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=task" -d "_csrf=$(csrf)" -d "site=$HOST329" -d op=done -d "task=${Y330_T2:-0}"
expect "3.30: complete_task finishes the console task; the site's panel cannot finish the customer's task" \
  "$(sq3 "SELECT done_at IS NOT NULL FROM ka_tasks WHERE id = ${Y330_T1:-0}")|$(sq3 "SELECT done_at IS NULL FROM ka_tasks WHERE id = ${Y330_T2:-0}")|$(curl -s -b "$JAR_CON" "$B3/admin.php?module=fleet" | grep -c 'data-fleet-task=')" "1|1|0"
# a task follows its record: a customer with an open task is kept without sites, its done tasks go with it; a removed site takes its tasks
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$HOST329"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer" -d "_csrf=$(csrf)" -d "id=$HOST329" -d "customer=Keep 330" -d note=
Y330_KEEP=$(sq3 "SELECT id FROM ka_fleet_customers WHERE name = 'Keep 330'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=task" -d "_csrf=$(csrf)" -d "customer=$Y330_KEEP" -d op=add --data-urlencode "title=Keep 330 task" -d due_quick=week
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer" -d "_csrf=$(csrf)" -d "id=$HOST329" -d customer= -d note=
Y330_KEPT=$(sq3 "SELECT COUNT(*) FROM ka_fleet_customers WHERE id = ${Y330_KEEP:-0}")
sq3 "UPDATE ka_tasks SET done_at = '$(site_time)' WHERE target = 'fleet_customer:${Y330_KEEP:-0}'" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer" -d "_csrf=$(csrf)" -d "id=$HOST329" -d customer= -d note=
expect "3.30: a customer without sites stays while it has an open task; once done, it is forgotten with its tasks" \
  "$Y330_KEPT|$(sq3 "SELECT COUNT(*) FROM ka_fleet_customers WHERE id = ${Y330_KEEP:-0}")|$(sq3 "SELECT COUNT(*) FROM ka_tasks WHERE target = 'fleet_customer:${Y330_KEEP:-0}'")" "1|0|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=task" -d "_csrf=$(csrf)" -d "site=$OLD329" -d op=add --data-urlencode "title=Old 330 task" -d due_quick=today
Y330_OLD_TASK=$(sq3 "SELECT COUNT(*) FROM ka_tasks WHERE target = 'fleet_site:$OLD329'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=remove" -d "_csrf=$(csrf)" -d "id=$OLD329"
expect "3.30: removing a site from the console removes its console tasks" "$Y330_OLD_TASK|$(sq3 "SELECT COUNT(*) FROM ka_tasks WHERE target = 'fleet_site:$OLD329'")|$(sq3 "SELECT COUNT(*) FROM ka_fleet_sites WHERE id = $OLD329")" "1|0|0"
sq3 "DELETE FROM ka_tasks WHERE target LIKE 'fleet%'; DELETE FROM ka_notifications WHERE target LIKE 'fleet%'; DELETE FROM ka_api_tokeny WHERE idu = (SELECT idu FROM ka_uzivatele WHERE user = 'editor330'); DELETE FROM ka_uzivatele WHERE user = 'editor330'" > /dev/null
sq3 "DELETE FROM ka_fleet_sites WHERE public_key IN ('old329bkey', 'hostile329bkey', 'new329bkey')" > /dev/null
