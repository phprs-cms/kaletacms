echo "== 3.42 review: the team hierarchy – what a stand-in and a manager are told, escalation, the forms of a department"
r342f_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $f = require $argv[1]; echo $f($app, array_slice($argv, 2));' -- "$@"); }
# an away colleague (no one above), their deputy – an editor without Enquiries –, a salesperson and the manager they report to (an
# editor without Enquiries), a second salesperson whose manager is blocked
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, notify_email) VALUES ('away-342f', 'x', 'Ava Away', 'away342f@example.cz', 1, 'off'),
    ('deputy-342f', 'x', 'Dan Deputy', 'deputy342f@example.cz', 1, 'off'), ('sales-342f', 'x', 'Sam Sales', 'sales342f@example.cz', 1, 'off'),
    ('manager-342f', 'x', 'Mia Manager', 'manager342f@example.cz', 1, 'off'), ('sales2-342f', 'x', 'Sue Sales', 'sales2342f@example.cz', 1, 'off'),
    ('gone-342f', 'x', 'Gil Gone', 'gone342f@example.cz', 1, 'off')" > /dev/null
r342f_id() { sq "SELECT idu FROM ka_uzivatele WHERE user = '$1'"; }
R342F_AWAY=$(r342f_id away-342f); R342F_DEPUTY=$(r342f_id deputy-342f); R342F_SALES=$(r342f_id sales-342f); R342F_MANAGER=$(r342f_id manager-342f)
R342F_SALES2=$(r342f_id sales2-342f); R342F_GONE=$(r342f_id gone-342f)
sq "UPDATE ka_uzivatele SET reports_to = $R342F_MANAGER WHERE idu = $R342F_SALES; UPDATE ka_uzivatele SET reports_to = $R342F_GONE WHERE idu = $R342F_SALES2;
    UPDATE ka_uzivatele SET blokovat = 1 WHERE idu = $R342F_GONE; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($R342F_DEPUTY, $R342F_MANAGER);
    INSERT INTO ka_absences (user_id, starts_on, ends_on, deputy_id, reason, created_at) VALUES ($R342F_AWAY, '$(site_date now)', '$(site_date '+3 days')', $R342F_DEPUTY, 'holiday', '$(site_time)')" > /dev/null
R342F_OLD="$(site_time '-4 days')"
sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES
    ('R342f about an enquiry', 'enquiry:999343', $R342F_AWAY, $R342F_AWAY, NULL, '$R342F_OLD', '$R342F_OLD'),
    ('R342f personal', NULL, $R342F_AWAY, $R342F_AWAY, NULL, '$R342F_OLD', '$R342F_OLD'),
    ('R342f made by the deputy', NULL, $R342F_AWAY, $R342F_DEPUTY, NULL, '$R342F_OLD', '$R342F_OLD'),
    ('R342f secret enquiry title', 'enquiry:999344', $R342F_SALES, $R342F_SALES, '$R342F_OLD', '$R342F_OLD', '$R342F_OLD'),
    ('R342f blocked manager', NULL, $R342F_SALES2, $R342F_SALES2, '$R342F_OLD', '$R342F_OLD', '$R342F_OLD')" > /dev/null
r342f_task() { sq "SELECT id FROM ka_tasks WHERE title = '$1'"; }
R342F_T1=$(r342f_task 'R342f about an enquiry'); R342F_T2=$(r342f_task 'R342f personal'); R342F_T3=$(r342f_task 'R342f made by the deputy')
R342F_T4=$(r342f_task 'R342f secret enquiry title'); R342F_T5=$(r342f_task 'R342f blocked manager')
cat > "$WORK/r342f-standin.php" <<'PHP'
<?php return function (Kaleta\Core\App $app, array $a): string {
    [$away, $deputy, $t1, $t2, $t3] = array_map('intval', $a);
    $db = $app->db();
    $copies = fn (int $task): int => (int) $db->value('SELECT COUNT(*) FROM {notifications} WHERE user_id = ? AND target = ?', [$deputy, 'task:' . $task]);
    Kaleta\Core\Inbox::notify($app, $away, 'assigned', 'task:' . $t1, null); // the deputy may not open Enquiries
    Kaleta\Core\Inbox::notify($app, $away, 'assigned', 'task:' . $t2, null); // a personal task of the away colleague
    Kaleta\Core\Inbox::notify($app, $away, 'assigned', 'task:999999999', null); // a task that is gone
    Kaleta\Core\Inbox::notify($app, $deputy, 'assigned', 'task:' . $t3, null); // the deputy is told of their own task first …
    Kaleta\Core\Inbox::notify($app, $away, 'assigned', 'task:' . $t3, null); // … and the same event as a stand-in gives no second row
    $out = [$copies($t1), $copies($t2), (int) $db->value("SELECT COUNT(*) FROM {notifications} WHERE user_id = ? AND target = 'task:999999999'", [$deputy]), $copies($t3)];
    $db->insert('uzivatele_prava', ['fk_id_user' => $deputy, 'ident_modulu' => 'enquiries']);
    Kaleta\Core\Inbox::notify($app, $away, 'task_due', 'task:' . $t1, null, 'Call back');
    $out[] = $copies($t1) . ':' . $db->value('SELECT standing_in_for FROM {notifications} WHERE user_id = ? AND target = ?', [$deputy, 'task:' . $t1]);

    return implode('|', $out);
};
PHP
expect "3.42 review M2 + L7: a stand-in's copy of a task only when they may open its record (or, without one, the task itself); none for a task that is gone; no second row of the same event" \
  "$(r342f_php "$WORK/r342f-standin.php" "$R342F_AWAY" "$R342F_DEPUTY" "$R342F_T1" "$R342F_T2" "$R342F_T3")" "0|0|0|1|1:Ava Away"
expect "3.42 review M2: the away colleague still gets every notification of their own" \
  "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $R342F_AWAY AND target IN ('task:$R342F_T1', 'task:$R342F_T2', 'task:999999999', 'task:$R342F_T3')")" "5"

cat > "$WORK/r342f-escalate.php" <<'PHP'
<?php return fn (Kaleta\Core\App $app, array $a): string => (string) Kaleta\Core\Tasks::escalate($app, Kaleta\Core\Clock::now());
PHP
background_jobs hold
r342f_php "$WORK/r342f-escalate.php" > /dev/null
expect "3.42 review M2: a manager who cannot open the task's record is told of the task only – the generic line, never its title" \
  "$(sq "SELECT CONCAT(target, '|', excerpt, '|', actor_name) FROM ka_notifications WHERE user_id = $R342F_MANAGER AND kind = 'escalated'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'escalated' AND excerpt LIKE '%R342f secret%'")" \
  "task:$R342F_T4||Sam Sales|0"
expect "3.42 review L4: a blocked manager is skipped and the task stays unmarked" \
  "$(sq "SELECT IFNULL(escalated_at, '-') FROM ka_tasks WHERE id = $R342F_T5")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $R342F_GONE")" "-|0"
cat > "$WORK/r342f-reset.php" <<'PHP'
<?php return function (Kaleta\Core\App $app, array $a): string {
    [$task, $sales, $manager] = array_map('intval', $a);
    $db = $app->db();
    $marked = fn (): string => $db->value('SELECT escalated_at FROM {tasks} WHERE id = ?', [$task]) === null ? 'unmarked' : 'marked';
    $out = [$marked()];
    Kaleta\Core\Tasks::update($app, $task, ['title' => 'R342f secret enquiry title, renamed'], $sales);
    $out[] = $marked();
    Kaleta\Core\Tasks::update($app, $task, ['due_at' => Kaleta\Core\Clock::at('-5 days')], $sales);
    $out[] = $marked();
    $out[] = Kaleta\Core\Tasks::escalate($app, Kaleta\Core\Clock::now()) . ':' . $marked();
    Kaleta\Core\Tasks::handOver($db, $sales, $manager);
    $out[] = $marked();

    return implode('|', $out);
};
PHP
expect "3.42 review L4: an escalated task is escalated again only after its due date or assignee changes (a new title keeps the mark)" \
  "$(r342f_php "$WORK/r342f-reset.php" "$R342F_T4" "$R342F_SALES" "$R342F_MANAGER")" "marked|marked|unmarked|1:marked|unmarked"
background_jobs resume

# a department's forms fit their column: twenty long names are refused with the reason, not a database error
cat > "$WORK/r342f-forms.php" <<'PHP'
<?php return function (Kaleta\Core\App $app, array $a): string {
    $long = Kaleta\Core\Departments::save($app->db(), 0, 'R342f Long', null, null, array_map(fn (int $i): string => str_pad('Form ' . $i . ' ', 120, 'x'), range(1, 20)));
    $fine = Kaleta\Core\Departments::save($app->db(), 0, 'R342f Fine', null, null, array_map(fn (int $i): string => str_pad('Form ' . $i . ' ', 120, 'x'), range(1, 8)));

    return (is_string($long) ? $long : 'saved') . '|' . (is_int($fine) ? mb_strlen((string) $app->db()->value('SELECT enquiry_forms FROM {departments} WHERE id = ?', [$fine])) : $fine);
};
PHP
expect "3.42 review L1: the forms of a department too long together are refused with the reason; what fits is saved" \
  "$(r342f_php "$WORK/r342f-forms.php")|$(sq "SELECT COUNT(*) FROM ka_departments WHERE name = 'R342f Long'")" \
  "The names of the forms are too long together – at most 1,000 characters.|967|0"

sq "DELETE FROM ka_notifications WHERE user_id IN ($R342F_AWAY, $R342F_DEPUTY, $R342F_SALES, $R342F_MANAGER, $R342F_SALES2, $R342F_GONE);
    DELETE FROM ka_tasks WHERE title LIKE 'R342f %'; DELETE FROM ka_absences WHERE user_id = $R342F_AWAY; DELETE FROM ka_departments WHERE name LIKE 'R342f %';
    DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($R342F_DEPUTY, $R342F_MANAGER);
    DELETE FROM ka_uzivatele WHERE idu IN ($R342F_AWAY, $R342F_DEPUTY, $R342F_SALES, $R342F_MANAGER, $R342F_SALES2, $R342F_GONE)" > /dev/null
