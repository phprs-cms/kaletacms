echo "== 3.34 review low (e): a personal data request finds the applications sent from the address, not those that only name it"
r334_on
LE_OWN=$(sq "INSERT INTO ka_applications (received_at, form, email, email_norm, data, status, status_at, last_activity_at) VALUES ('$(site_time)', 'LE', 'Ref-LE@example.com', 'ref-le@example.com', '[]', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
LE_NAMES=$(sq "INSERT INTO ka_applications (received_at, form, email, email_norm, data, status, status_at, last_activity_at) VALUES ('$(site_time)', 'LE', 'cand-le@example.com', 'cand-le@example.com', '[[\"Referee\",\"ref-le@example.com\"]]', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
mcp erase_personal_data '{"email":"ref-le@example.com","confirm":true}' > /dev/null
expect "3.34 review low (e): erasure takes the application with that own address and leaves the one that names it as a referee" \
  "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $LE_OWN")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $LE_NAMES")" "0|1"
t334_php 'Kaleta\Core\Applications::delete($app, ['"$LE_NAMES"'], false);' > /dev/null
r334_off
