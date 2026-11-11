echo "== 3.34 second review N8: the confirmation of the retention is the highest application id – a later move gates only what it moved"
r334_on
N8_BEFORE=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'")
N8_OLD=$(site_time '-30 months')
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6'); UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id IN (926001, 926002)" > /dev/null
N8_A=$(sq "INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at, source_enquiry_id, moved_at) VALUES ('$N8_OLD', 'N8 a', '[]', 'reviewed', '$N8_OLD', '$N8_OLD', 926001, '$(site_time '-40 days')'); SELECT LAST_INSERT_ID();")
expect "3.34 second review N8: a moved application waiting for the confirmation more than 30 days – System status says so" \
  "$(t334_php 'foreach (Kaleta\Core\Health::checks($app) as $c) { if (str_contains($c["info"], "to confirm the retention")) { echo $c["stav"]; } }')" "varovani"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=applications&action=settings_save" -d "_csrf=$(csrf)" -d applications_months=6 -d applications_pool_months=12
expect "3.34 second review N8: saving the settings confirms every application there now – the highest id; System status is quiet" \
  "$(sq "SELECT hodnota = (SELECT MAX(id) FROM ka_applications) FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'")|$(t334_php 'foreach (Kaleta\Core\Health::checks($app) as $c) { if (str_contains($c["info"], "to confirm the retention")) { echo $c["stav"]; } }')" "1|"
# a later move (one enquiry, by anyone with both sections) after the confirmation: the confirmation stays, only the new row waits
N8_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav) VALUES ('$N8_OLD', 'N8 later', 'stranka:1', 'x', '/kariera', 'n8@example.com', '[]', 1); SELECT LAST_INSERT_ID();")
N8_CONFIRMED=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'")
t334_php 'Kaleta\Core\ApplicationMove::enquiryToApplication($app, '"$N8_E"');' > /dev/null
N8_B=$(sq "SELECT id FROM ka_applications WHERE source_enquiry_id = $N8_E")
expect "3.34 second review N8: the later move leaves the confirmation as it was (the new row lies above it) and records when it moved" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'")|$(sq "SELECT moved_at IS NOT NULL AND id > $N8_CONFIRMED FROM ka_applications WHERE id = $N8_B")" "$N8_CONFIRMED|1"
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 second review N8: the clean-up still deletes the confirmed moved application past its retention, the newly moved one waits" \
  "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $N8_A")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $N8_B")" "0|1"
expect "3.34 second review N8: a confirmation that already reaches the moved ids (ids used again after a restore) is lowered below the first of them" \
  "$(t334_php '$s = $app->settings(); $s->set(Kaleta\Core\Applications::RETENTION_CONFIRMED, "500"); Kaleta\Core\Applications::adoptRetention($app->db(), $s, [420, 421]); echo Kaleta\Core\Applications::confirmedUpTo($s), "|"; Kaleta\Core\Applications::adoptRetention($app->db(), $s, [900]); echo Kaleta\Core\Applications::confirmedUpTo($s);')" "419|419"
sq "DELETE FROM ka_comments WHERE target = 'application:$N8_B'; DELETE FROM ka_applications WHERE id = $N8_B; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_retention_confirmed', '$N8_BEFORE')" > /dev/null
r334_off
