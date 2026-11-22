echo "== 3.51 D1 (SECURITY-3): assigned_by is stamped by every way the responsible person changes – and kept when a save carries the same person; a rule leaves NULL"
d777_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1" -- "${@:2}"); }
D777_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +quotes
D777_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
D777_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('d777eva', '$D777_HASH', 'Eva D777', 'd777eva@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0, 'off')" > /dev/null
D777_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'd777eva'")

# tasks: created for someone else = the creator; reassigned = who reassigned; the title changed alone = the old stamp
D777_TASK=$(d777_php 'echo Kaleta\Core\Tasks::create($app, ["title" => "D777 task", "assignee_id" => (int) $argv[1]], (int) $argv[2]);' "$D777_EVA" "$D777_ADMIN")
D777_T1=$(sq "SELECT assigned_by FROM ka_tasks WHERE id = ${D777_TASK:-0}")
d777_php 'Kaleta\Core\Tasks::update($app, (int) $argv[1], ["title" => "D777 task renamed", "assignee_id" => (int) $argv[2]], (int) $argv[3]);' "$D777_TASK" "$D777_EVA" "$D777_EVA" > /dev/null
D777_T2=$(sq "SELECT assigned_by FROM ka_tasks WHERE id = ${D777_TASK:-0}")
d777_php 'Kaleta\Core\Tasks::update($app, (int) $argv[1], ["assignee_id" => (int) $argv[2]], (int) $argv[3]);' "$D777_TASK" "$D777_ADMIN" "$D777_EVA" > /dev/null
expect "3.51 D1: a task – the creator stamps it, a save with the same person keeps the stamp, a new person is stamped with who gave it" \
  "$D777_T1|$D777_T2|$(sq "SELECT CONCAT(assignee_id, ':', assigned_by) FROM ka_tasks WHERE id = ${D777_TASK:-0}")" "$D777_ADMIN|$D777_ADMIN|$D777_ADMIN:$D777_EVA"

# deals: a new deal = its creator; a save carrying the same owner keeps the stamp; a new owner is stamped
D777_DEAL=$(d777_php 'echo Kaleta\Core\Deals::save($app, null, ["title" => "D777 deal", "owner_id" => (int) $argv[1]], (int) $argv[2]);' "$D777_EVA" "$D777_ADMIN")
d777_php 'Kaleta\Core\Deals::save($app, (int) $argv[1], ["title" => "D777 deal (2)", "owner_id" => (int) $argv[2]], (int) $argv[3]);' "$D777_DEAL" "$D777_EVA" "$D777_EVA" > /dev/null
D777_D2=$(sq "SELECT CONCAT(title, ':', assigned_by) FROM ka_deals WHERE id = ${D777_DEAL:-0}")
d777_php 'Kaleta\Core\Deals::save($app, (int) $argv[1], ["owner_id" => (int) $argv[2]], (int) $argv[3]);' "$D777_DEAL" "$D777_ADMIN" "$D777_EVA" > /dev/null
expect "3.51 D1: a deal – the creator stamps it, a save with the same owner keeps the stamp, a new owner is stamped with who changed it" \
  "$D777_D2|$(sq "SELECT CONCAT(owner_id, ':', assigned_by) FROM ka_deals WHERE id = ${D777_DEAL:-0}")" "D777 deal (2):$D777_ADMIN|$D777_ADMIN:$D777_EVA"

# quotes: the same three steps
D777_QUOTE=$(d777_php 'echo Kaleta\Core\Quotes::save($app, null, ["title" => "D777 quote", "owner_id" => (int) $argv[1]], (int) $argv[2])["id"];' "$D777_EVA" "$D777_ADMIN")
d777_php 'Kaleta\Core\Quotes::save($app, (int) $argv[1], ["title" => "D777 quote (2)", "owner_id" => (int) $argv[2]], (int) $argv[3]);' "$D777_QUOTE" "$D777_EVA" "$D777_EVA" > /dev/null
D777_Q2=$(sq "SELECT CONCAT(title, ':', assigned_by) FROM ka_quotes WHERE id = ${D777_QUOTE:-0}")
d777_php 'Kaleta\Core\Quotes::save($app, (int) $argv[1], ["owner_id" => (int) $argv[2]], (int) $argv[3]);' "$D777_QUOTE" "$D777_ADMIN" "$D777_EVA" > /dev/null
expect "3.51 D1: a quote – the creator stamps it, a save with the same owner keeps the stamp, a new owner is stamped with who changed it" \
  "$D777_Q2|$(sq "SELECT CONCAT(owner_id, ':', assigned_by) FROM ka_quotes WHERE id = ${D777_QUOTE:-0}")" "D777 quote (2):$D777_ADMIN|$D777_ADMIN:$D777_EVA"

# enquiries: "Take it" in My work stamps the taker; a department rule routes a new enquiry with no stamp
D777_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, assigned_by) VALUES ('$(site_time)', 'D777 form', 'd777@example.com', '[]', 0, 9999); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=my_work&action=take" -d "_csrf=$(csrf)" -d "idp=$D777_ENQ"
D777_TAKEN=$(sq "SELECT CONCAT(prirazeno, ':', assigned_by) FROM ka_poptavky WHERE idp = $D777_ENQ")
sq "INSERT INTO ka_departments (name, head_id, enquiry_forms, created_at, updated_at) VALUES ('D777 sales', $D777_EVA, 'D777 routed', '$(site_time)', '$(site_time)')" > /dev/null
D777_ROUTED=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, assigned_by) VALUES ('$(site_time)', 'D777 routed', 'd777r@example.com', '[]', 0, 9999); SELECT LAST_INSERT_ID();")
D777_TO=$(d777_php 'echo Kaleta\Core\Departments::assignEnquiry($app, (int) $argv[1]) ?? "-";' "$D777_ROUTED")
expect "3.51 D1: Take it stamps who took the enquiry; a department rule hands one over with assigned_by NULL (it counts for the team as before)" \
  "$D777_TAKEN|$D777_TO|$(sq "SELECT CONCAT(prirazeno, ':', IFNULL(assigned_by, 'rule')) FROM ka_poptavky WHERE idp = $D777_ROUTED")" "$D777_ADMIN:$D777_ADMIN|$D777_EVA|$D777_EVA:rule"

# the hand-over of a leaver (Users → block with a recipient) stamps the administrator who handed the work over
sq "UPDATE ka_deals SET owner_id = $D777_EVA, assigned_by = $D777_EVA WHERE id = ${D777_DEAL:-0}" > /dev/null
D777_OPEN=$(sq "INSERT INTO ka_tasks (title, assignee_id, created_by, assigned_by, created_at, updated_at) VALUES ('D777 leaver', $D777_EVA, $D777_EVA, $D777_EVA, '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
d777_php 'Kaleta\Core\Handover::handOver($app->db(), (int) $argv[1], (int) $argv[2], (int) $argv[2]);' "$D777_EVA" "$D777_ADMIN" > /dev/null
expect "3.51 D1: a leaver's open task, open enquiry and deal move to the recipient, stamped with the administrator" \
  "$(sq "SELECT CONCAT(assignee_id, ':', assigned_by) FROM ka_tasks WHERE id = $D777_OPEN")|$(sq "SELECT CONCAT(prirazeno, ':', assigned_by) FROM ka_poptavky WHERE idp = $D777_ROUTED")|$(sq "SELECT CONCAT(owner_id, ':', assigned_by) FROM ka_deals WHERE id = ${D777_DEAL:-0}")" \
  "$D777_ADMIN:$D777_ADMIN|$D777_ADMIN:$D777_ADMIN|$D777_ADMIN:$D777_ADMIN"

sq "DELETE FROM ka_tasks WHERE id IN (${D777_TASK:-0}, $D777_OPEN); DELETE FROM ka_deal_stage_history WHERE deal_id = ${D777_DEAL:-0}; DELETE FROM ka_deals WHERE id = ${D777_DEAL:-0};
  DELETE FROM ka_quote_lines WHERE quote_id = ${D777_QUOTE:-0}; DELETE FROM ka_quotes WHERE id = ${D777_QUOTE:-0}; DELETE FROM ka_poptavky WHERE idp IN ($D777_ENQ, $D777_ROUTED);
  DELETE FROM ka_departments WHERE name = 'D777 sales'; DELETE FROM ka_notifications WHERE user_id IN ($D777_EVA, $D777_ADMIN) AND target IN ('task:${D777_TASK:-0}', 'deal:${D777_DEAL:-0}', 'quote:${D777_QUOTE:-0}', 'enquiry:$D777_ENQ', 'enquiry:$D777_ROUTED');
  DELETE FROM ka_uzivatele WHERE idu = $D777_EVA" > /dev/null
features --exact "$D777_EXT"
