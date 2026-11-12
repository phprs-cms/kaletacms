echo "== 3.24 (T11): the admin runs the light background jobs after its answer"
# a scheduled page whose time has come is published by the admin alone – no visit to the site in between
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, v_menu, poradi, zverejnit_od) VALUES ('T324 scheduled', 't324-scheduled', '<p>Due</p>', 0, 0, 100, '$(site_time '-5 minutes')')" > /dev/null
for i in $(seq 1 16); do background_jobs due; curl -s -b "$JAR" -o /dev/null "$B/admin.php"; [ "$(sq "SELECT zobrazit FROM ka_stranky WHERE seo_link = 't324-scheduled'")" = 1 ] && break; sleep 0.5; done
expect "3.24 (T11): the scheduled page went out after an admin request, and the once-a-minute mark is set" \
  "$(sq "SELECT CONCAT(zobrazit, '/', zverejnit_od IS NULL) FROM ka_stranky WHERE seo_link = 't324-scheduled'")|$(( $(date +%s) - $(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'notification_check'") < 120 ))" "1/1|1"
# the pace holds: within the minute a second admin request runs nothing
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, v_menu, poradi, zverejnit_od) VALUES ('T324 later', 't324-later', '<p>Due</p>', 0, 0, 100, '$(site_time '-5 minutes')')" > /dev/null
background_jobs resume; curl -s -b "$JAR" -o /dev/null "$B/admin.php"; sleep 1
expect "3.24 (T11): within the minute the admin leaves the jobs alone" "$(sq "SELECT zobrazit FROM ka_stranky WHERE seo_link = 't324-later'")" "0"
sq "DELETE FROM ka_stranky WHERE seo_link IN ('t324-scheduled', 't324-later')" > /dev/null

