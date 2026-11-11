echo "== 3.34 review H1: moved applications are not purged before an administrator confirms the retention"
# the review sections (2210–2249) switch the feature on for themselves and put the site's choice back at their end
r334_on() { R334_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'"); sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$R334_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$R334_EXT"),applications')" > /dev/null; }
r334_off() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$R334_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null; }
r334_on
# an application moved from an enquiry and an ordinary one, both quiet for two years; the retention 6 months, not confirmed yet
sq "UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id = 92101; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6'), ('applications_retention_confirmed', '')" > /dev/null
H1_MOVED=$(sq "INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$(site_time '-24 months')', 'H1 moved', '[]', 'reviewed', '$(site_time '-24 months')', '$(site_time '-24 months')', 92101); SELECT LAST_INSERT_ID();")
H1_OWN=$(sq "INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at) VALUES ('$(site_time '-24 months')', 'H1 own', '[]', 'new', '$(site_time '-24 months')', '$(site_time '-24 months')'); SELECT LAST_INSERT_ID();")
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 review H1: unconfirmed – the clean-up deletes the application that came in directly, the moved one stays" "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id IN ($H1_MOVED, $H1_OWN)")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $H1_MOVED")" "1|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
expect "3.34 review H1: the settings tab says how many moved applications wait (and from which date)" "$(grep -c 'data-applications-retention-pending' "$WORK/response")|$(grep -c "$(t334_php 'echo format_date((new DateTimeImmutable(Kaleta\Core\Clock::today()))->modify("-6 months")->format("Y-m-d"));')" "$WORK/response")" "1|1"
curl -s -b "$JAR" -o "$WORK/h1-list.html" "$B/admin.php?module=applications&status="
expect "3.34 review H1: the list says it too" "$(grep -c 'data-applications-retention-pending' "$WORK/h1-list.html")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=applications&action=settings_save" -d "_csrf=$(csrf)" -d applications_months=6 -d applications_pool_months=12 -d applications_claude=1
expect "3.34 review H1: saving the settings confirms the retention" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'")" "1"
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 review H1: confirmed – the moved application past the retention goes with the next clean-up" "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $H1_MOVED")" "0"
# the move by form takes enquiries_months when applications_months was never stored, and asks for the confirmation again
sq "DELETE FROM ka_nastaveni WHERE promenna = 'applications_months'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '0'), ('job_applications_months', '0')" > /dev/null
H1_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav) VALUES ('$(site_time '-30 months')', 'Kariera H1', 'stranka:1', 'x', '/kariera', 'h1@example.com', '[]', 1); SELECT LAST_INSERT_ID();")
t334_php 'Kaleta\Core\ApplicationMove::toApplications($app, "Kariera H1", "stranka:1");' > /dev/null
expect "3.34 review H1: a move by form on a site that keeps enquiries keeps the applications too (0), and the confirmation is asked again" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_months'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_retention_confirmed'")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = $H1_E")" "0||1"
sq "DELETE FROM ka_applications WHERE source_enquiry_id = $H1_E; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6'), ('enquiries_months', '24'), ('applications_retention_confirmed', '1')" > /dev/null
r334_off
