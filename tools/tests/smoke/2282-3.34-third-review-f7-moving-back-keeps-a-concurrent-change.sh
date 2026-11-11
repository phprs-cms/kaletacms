echo "== 3.34 third review F7: moving an application back takes the row as it is in the transaction – a status changed meanwhile is not lost"
r334_on
F7_ID=$(sq "INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at) VALUES ('$(site_time '-1 day')', 'F7 form', '[[\"Name\",\"F7\"]]', 'new', '$(site_time '-1 day')', '$(site_time '-1 day')'); SELECT LAST_INSERT_ID();")
# the move read the row as "new", then a colleague decided it (hired) before the move's transaction ran
F7_OUT=$(t334_php '$row = $app->db()->one("SELECT * FROM {applications} WHERE id = '"$F7_ID"'");
  $app->db()->run("UPDATE {applications} SET status = ?, decided_at = ? WHERE id = ?", ["hired", Kaleta\Core\Clock::now(), '"$F7_ID"']);
  echo (new ReflectionMethod(Kaleta\Core\ApplicationMove::class, "moveBack"))->invoke(null, $app, $app->settings(), $row, Kaleta\Core\Clock::now()) ? "moved" : "stayed";')
expect "3.34 third review F7: moved back with the status it has now (hired → resolved), not the one read before" \
  "$F7_OUT|$(sq "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_applications WHERE id = $F7_ID), (SELECT GROUP_CONCAT(stav) FROM ka_poptavky WHERE formular = 'F7 form'))")" "moved|0|2"
sq "DELETE FROM ka_poptavky WHERE formular = 'F7 form'" > /dev/null
r334_off
