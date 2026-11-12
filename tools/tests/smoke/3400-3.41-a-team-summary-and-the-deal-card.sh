echo "== 3.41 A: the team summary (counts only, per person for administrators) and the deal card (days in the stage, the next step)"
r341a_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
# the count of one cell of the summary table: r341a_cell <file> <data-person> <count>
r341a_cell() { php -r '$d = new DOMDocument(); @$d->loadHTML((string) file_get_contents($argv[1])); $x = new DOMXPath($d);
  $n = $x->query("//table[@data-team-summary]//tr[@data-person=\"" . $argv[2] . "\"]/td[@data-count=\"" . $argv[3] . "\"]");
  echo $n->length === 1 ? trim($n->item(0)->textContent) : "-";' -- "$1" "$2" "$3"; }
R341A_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R341A_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r341a-eva', '$R341A_HASH', 'Eva R341a', 'eva-r341a@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m FROM ka_uzivatele, (SELECT 'pipeline' AS m UNION SELECT 'enquiries') x WHERE user = 'r341a-eva'" > /dev/null
R341A_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r341a-eva'")
# what happened this week: Eva completed two tasks and has one overdue, answered one enquiry twice (one count) and moved a deal;
# the administrator completed one task; a deal Eva is responsible for was won
R341A_STAGE=$(r341a_php 'echo Kaleta\Core\Deals::firstStage($app->settings());')
R341A_WON=$(r341a_php 'foreach (Kaleta\Core\Deals::stages($app->settings()) as $s) { if ($s["kind"] === "won") { echo $s["key"]; break; } }')
sq "INSERT INTO ka_tasks (title, assignee_id, created_by, due_at, done_at, done_by, created_at, updated_at) VALUES
  ('R341a done 1', $R341A_EVA, $R341A_EVA, NULL, '$(site_time '-1 day')', $R341A_EVA, '$(site_time '-2 days')', '$(site_time)'),
  ('R341a done 2', $R341A_EVA, $R341A_EVA, NULL, '$(site_time '-2 days')', $R341A_EVA, '$(site_time '-3 days')', '$(site_time)'),
  ('R341a overdue', $R341A_EVA, $R341A_ADMIN, '$(site_time '-3 days')', NULL, NULL, '$(site_time '-4 days')', '$(site_time)'),
  ('R341a admin done', $R341A_ADMIN, $R341A_ADMIN, NULL, '$(site_time '-1 day')', $R341A_ADMIN, '$(site_time '-2 days')', '$(site_time)'),
  ('R341a long ago', $R341A_EVA, $R341A_EVA, NULL, '$(site_time '-40 days')', $R341A_EVA, '$(site_time '-41 days')', '$(site_time)');
  INSERT INTO ka_protokol (cas, kdo, jmeno, modul, akce, popis) VALUES ('$(site_time '-1 day')', $R341A_EVA, 'Eva R341a', 'enquiries', 'reply', '#934101'),
  ('$(site_time '-1 day')', $R341A_EVA, 'Eva R341a', 'enquiries', 'reply', '#934101 (queued)')" > /dev/null
R341A_DEAL=$(sq "INSERT INTO ka_deals (title, owner_id, stage, created_by, created_at, updated_at) VALUES ('Deal R341a', $R341A_EVA, '$R341A_STAGE', $R341A_ADMIN, '$(site_time '-20 days')', '$(site_time)'); SELECT LAST_INSERT_ID();")
R341A_DONE=$(sq "INSERT INTO ka_deals (title, owner_id, stage, closed_at, created_by, created_at, updated_at) VALUES ('Won R341a', $R341A_EVA, '$R341A_WON', '$(site_time '-1 day')', $R341A_ADMIN, '$(site_time '-20 days')', '$(site_time)'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES ($R341A_DEAL, NULL, '$R341A_STAGE', '$(site_time '-20 days')', $R341A_ADMIN),
  ($R341A_DEAL, '$R341A_STAGE', '$R341A_STAGE', '$(site_time '-12 days')', $R341A_ADMIN), ($R341A_DONE, '$R341A_STAGE', '$R341A_WON', '$(site_time '-1 day')', $R341A_EVA)" > /dev/null

# the administrator: a row per person, the team's total (earlier sections completed tasks this week too – counted from the table)
R341A_FROM="$(site_date '-6 days') 00:00:00"
R341A_TEAM=$(sq "SELECT COUNT(*) FROM ka_tasks WHERE done_at >= '$R341A_FROM'")
R341A_MINE=$(sq "SELECT COUNT(*) FROM ka_tasks WHERE done_at >= '$R341A_FROM' AND done_by = $R341A_ADMIN")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r341a.html" "$B/admin.php?module=my_work&action=summary&period=7_days"
expect "3.41 A: the administrator sees each person's counts – tasks done, overdue, enquiries answered, deal moves, deals won (by the responsible person)" \
  "$(for c in tasks_done tasks_overdue enquiries_answered deals_moved deals_won; do printf '%s ' "$(r341a_cell "$WORK/r341a.html" "$R341A_EVA" $c)"; done)" "2 1 1 1 1 "
expect "3.41 A: the administrator's own row and the team's total; work older than the period is not counted" \
  "$(r341a_cell "$WORK/r341a.html" "$R341A_ADMIN" tasks_done) $(r341a_cell "$WORK/r341a.html" team tasks_done) $(grep -c 'data-person="you"' "$WORK/r341a.html")" "$R341A_MINE $R341A_TEAM 0"
expect "3.41 A: Summary is a tab of My work; the period is chosen with ?period= (an unknown one falls back to 30 days, which counts the work of 40 days ago out)" \
  "$(grep -q 'action=summary' "$WORK/r341a.html" && echo tab)|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=my_work&action=summary&period=bogus" > "$WORK/r341a2.html"; grep -c '<option value="30_days" selected' "$WORK/r341a2.html")|$(r341a_cell "$WORK/r341a2.html" "$R341A_EVA" tasks_done)" "tab|1|2"
expect "3.41 A: counts only – never a value of a deal or a customer on the summary" "$(grep -c 'Deal R341a\|Won R341a\|R341a done' "$WORK/r341a.html")" "0"

# a colleague: their own row and the team's total, nobody else's
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
JAR_R341A="$WORK/jar-r341a"; rm -f "$JAR_R341A"
R341A_CSRF=$(curl -s -c "$JAR_R341A" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_R341A" -c "$JAR_R341A" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$R341A_CSRF" -d user=r341a-eva --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_R341A" -c "$JAR_R341A" -o "$WORK/r341a.html" "$B/admin.php?module=my_work&action=summary&period=7_days"
expect "3.41 A: a colleague sees their own counts and the team's totals – no row of another person, no Team tab" \
  "$(r341a_cell "$WORK/r341a.html" you tasks_done) $(r341a_cell "$WORK/r341a.html" team tasks_done) $(grep -c "data-person=\"$R341A_ADMIN\"\|action=team" "$WORK/r341a.html")" "2 $R341A_TEAM 0"
expect "3.41 A: a colleague without Quotes or Applications sees no count of them" "$(grep -c 'data-count="quotes_\|data-count="applications_' "$WORK/r341a.html")" "0"

# MCP team_summary: per person for an administrator, never for anyone else
mcp team_summary '{"period":"7_days"}' > "$WORK/response"; mcp_text
expect "3.41 A: MCP team_summary for an administrator – the team's totals and each person by name, only counts above 0" \
  "$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $eva = array_values(array_filter($j["people"] ?? [], fn ($p) => ($p["name"] ?? "") === "Eva R341a"))[0] ?? [];
  echo ($j["period"] ?? ""), "|", ($j["team"]["tasks_done"] ?? "-"), "|", ($eva["tasks_done"] ?? "-"), "|", ($eva["user"] ?? "-"), "|", isset($eva["quotes_sent"]) ? "zero kept" : "compact";' -- "$WORK/text")" \
  "7_days|$R341A_TEAM|2|$R341A_EVA|compact"
R341A_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R341A_EVA, 'r341a colleague', 'full', 'all', SHA2('$R341A_TOKEN', 256), '$(site_time)')" > /dev/null
mcp_call "$R341A_TOKEN" team_summary '{"period":"7_days"}' > "$WORK/response"; mcp_text
expect "3.41 A: MCP team_summary for a colleague – their own counts and the team's totals, no people" \
  "$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo ($j["you"]["tasks_done"] ?? "-"), "|", ($j["team"]["tasks_done"] ?? "-"), "|", array_key_exists("people", $j) ? "people!" : "none";' -- "$WORK/text")" "2|$R341A_TEAM|none"

# the monthly report: the team's totals without names
expect "3.41 A: the monthly report carries the team's totals – how many people, what they did – never a name or a count of the moment" \
  "$(r341a_php '$m = Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable(date("Y-m-01", strtotime("-1 day")))); $t = $m["team"] ?? []; $j = json_encode($t);
    echo ($t["people"] ?? 0) >= 2 ? "people" : "no people", "|", isset($t["tasks_done"]) ? "done" : "-", "|", isset($t["tasks_open"]) || isset($t["enquiries_open"]) ? "now!" : "period", "|", str_contains((string) $j, "Eva") || str_contains((string) $j, "name") ? "named!" : "anonymous";')" \
  "people|done|period|anonymous"

# the deal card: days in the stage and the next step
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r341a.html" "$B/admin.php?module=pipeline"
expect "3.41 A: an open card says how many days the deal is in its stage (since its latest move) and that it has no next step" \
  "$(php -r '$d = new DOMDocument(); @$d->loadHTML((string) file_get_contents($argv[1])); $x = new DOMXPath($d); $c = $x->query("//li[@data-deal=\"" . $argv[2] . "\"]//p[@class=\"obchod-krok\"]");
  echo $c->length === 1 ? preg_replace("/\s+/u", " ", trim($c->item(0)->textContent)) : "-";' -- "$WORK/r341a.html" "$R341A_DEAL")" "Dní v této etapě: 12 · Žádný další krok"
mcp save_task "{\"title\":\"Call back R341a\",\"target\":\"deal:$R341A_DEAL\",\"due\":\"$(site_date '+2 days')\"}" > /dev/null
mcp save_task "{\"title\":\"Later R341a\",\"target\":\"deal:$R341A_DEAL\"}" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r341a.html" "$B/admin.php?module=pipeline"
expect "3.41 A: the card's next step is the open task due first, with its day" \
  "$(grep -c "Další krok: Call back R341a · " "$WORK/r341a.html")|$(grep -c 'Later R341a' "$WORK/r341a.html")" "1|0"
expect "3.41 A: a closed (won) card shows neither" "$(php -r '$d = new DOMDocument(); @$d->loadHTML((string) file_get_contents($argv[1])); echo (new DOMXPath($d))->query("//li[@data-deal=\"" . $argv[2] . "\"]//p[@class=\"obchod-krok\"]")->length;' -- "$WORK/r341a.html" "$R341A_DONE")" "0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r341a.html" "$B/admin.php?module=pipeline&action=detail&id=$R341A_DEAL"
expect "3.41 A: the deal detail says how long it is in the stage and its next step, linked to the tasks" \
  "$(grep -c 'data-deal-age>dní: 12' "$WORK/r341a.html")|$(grep -o 'data-deal-next><a href="#tasks">Call back R341a</a>' "$WORK/r341a.html" | wc -l | tr -d ' ')" "1|1"

sq "DELETE FROM ka_tasks WHERE title LIKE '%R341a%'; DELETE FROM ka_protokol WHERE popis LIKE '#934101%'; DELETE FROM ka_deal_stage_history WHERE deal_id IN ($R341A_DEAL, $R341A_DONE);
  DELETE FROM ka_deals WHERE id IN ($R341A_DEAL, $R341A_DONE); DELETE FROM ka_api_tokeny WHERE idu = $R341A_EVA; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $R341A_EVA; DELETE FROM ka_uzivatele WHERE idu = $R341A_EVA" > /dev/null
