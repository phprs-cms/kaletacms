echo "== 3.34 review M1: the moved CVs are sealed with an id cursor – 500 rows whose files are gone do not hold the next one back"
M1_DIR="$WORK/web/storage/prilohy/2026/10"; mkdir -p "$M1_DIR"
M1_HEX=$(php -r 'echo bin2hex(random_bytes(12));'); printf 'CV of M1' > "$M1_DIR/$M1_HEX.pdf"
M1_GONE='[{"field":0,"name":"cv.pdf","size":1,"ext":"pdf","path":"2026/10/000000000000000000000000.pdf","sealed":false}]'
sq "UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id BETWEEN 930001 AND 930501;
  INSERT INTO ka_applications (received_at, form, data, attachments, status, status_at, last_activity_at, source_enquiry_id)
  SELECT '$(site_time)', 'M1 gone', '[]', '$M1_GONE', 'new', '$(site_time)', '$(site_time)', 930000 + t.x FROM (WITH RECURSIVE n (x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 500) SELECT x FROM n) t;
  INSERT INTO ka_applications (received_at, form, data, attachments, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$(site_time)', 'M1 real', '[[\"CV\",\"cv.pdf\"]]',
    '[{\"field\":0,\"name\":\"cv.pdf\",\"size\":8,\"ext\":\"pdf\",\"path\":\"2026/10/$M1_HEX.pdf\",\"sealed\":false}]', 'new', '$(site_time)', '$(site_time)', 930501);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_seal_after', '0')" > /dev/null
M1_ID=$(sq "SELECT id FROM ka_applications WHERE source_enquiry_id = 930501")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
expect "3.34 review M1: the settings tab says how many moved CVs still wait to be encrypted" "$(grep -c 'data-applications-unsealed' "$WORK/response")|$(t334_php 'echo Kaleta\Core\Applications::unsealedCount($app) >= 1 ? "waits" : "none";')" "1|waits"
t334_php 'Kaleta\Core\Applications::sealPending($app, 5);' > /dev/null
M1_A=$(t334_php '$a = Kaleta\Core\Applications::attachments($app->settings(), $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$M1_ID"'")); echo ($a[0]["sealed"] ?? false) ? "sealed" : "plain";')
expect "3.34 review M1: the CV after 500 rows with gone files is sealed in the first run, its plain copy is gone and it still opens" \
  "$M1_A|$([ -f "$M1_DIR/$M1_HEX.pdf" ] && echo kept || echo gone)|$(t334_php '$f = Kaleta\Core\Applications::attachment($app, '"$M1_ID"', 0); echo $f["content"] ?? "";')" "sealed|gone|CV of M1"
expect "3.34 review M1: the cursor started over at the end" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'applications_seal_after'")" "0"
sq "DELETE FROM ka_applications WHERE source_enquiry_id BETWEEN 930001 AND 930501" > /dev/null
t334_php 'Kaleta\Core\Applications::delete($app, ['"$M1_ID"'], false);' > /dev/null
