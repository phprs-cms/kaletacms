echo "== 3.34 review low (a): responsible people and bell entries of applications stay with those who may open Applications"
r334_on
LA_OLGA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't334-olga'"); LA_ADMIN=$(sq "SELECT MIN(idu) FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0")
LA_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav, prirazeno) VALUES ('$(site_time '-2 days')', 'Kariera LA', 'stranka:1', 'x', '/kariera', 'la@example.com', '[]', 1, $LA_OLGA); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_notifications (user_id, kind, target, actor_name, excerpt, created_at) VALUES ($LA_OLGA, 'comment', 'enquiry:$LA_E', 'Admin', 'excerpt for Olga', '$(site_time)'), ($LA_ADMIN, 'comment', 'enquiry:$LA_E', 'Olga', 'excerpt for the admin', '$(site_time)')" > /dev/null
t334_php 'Kaleta\Core\ApplicationMove::toApplications($app, "Kariera LA", "stranka:1");' > /dev/null
LA_A=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE source_enquiry_id = $LA_E")
expect "3.34 review low (a): the move by form clears a responsible person without Applications and drops their bell entry; the administrator's stays" \
  "$(sq "SELECT IFNULL(assignee_id, 'nobody') FROM ka_applications WHERE id = $LA_A")|$(sq "SELECT GROUP_CONCAT(user_id = $LA_ADMIN) FROM ka_notifications WHERE target = 'application:$LA_A'")" "nobody|1"
# deletion_due goes only to someone who may open Applications
sq "UPDATE ka_applications SET assignee_id = $LA_OLGA, source_enquiry_id = NULL, last_activity_at = '$(site_time '-6 months +3 days')' WHERE id = $LA_A; DELETE FROM ka_notifications WHERE target = 'application:$LA_A'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6')" > /dev/null
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 review low (a): no deletion_due for a responsible person without Applications" "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'application:$LA_A' AND kind = 'deletion_due'")" "0"
sq "UPDATE ka_applications SET assignee_id = $LA_ADMIN WHERE id = $LA_A" > /dev/null
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 review low (a): an administrator gets it" "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'application:$LA_A' AND kind = 'deletion_due' AND user_id = $LA_ADMIN")" "1"
t334_php 'Kaleta\Core\Applications::delete($app, ['"$LA_A"'], false);' > /dev/null
r334_off
