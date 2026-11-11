echo "== 3.34 B: job applications – the list (waiting, with selected) and the detail"
# the feature on again (2100 switched it back off); Hana (HR, the section only) and the administrator from 2100
T335_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$T335_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$T335_EXT"),applications')" > /dev/null
sq "INSERT INTO ka_applications (received_at, name, email, email_norm, job_title, data, status, status_at, last_activity_at) VALUES
  ('$(site_time '-10 days')', 'Wanda Wait T335', 'wanda-t335@example.com', 'wanda-t335@example.com', 'Welder T335', '[[\"Message\",\"Hello\"]]', 'new', '$(site_time '-10 days')', '$(site_time '-10 days')'),
  ('$(site_time '-1 day')', 'Fresh Fred T335', 'fred-t335@example.com', 'fred-t335@example.com', 'Welder T335', '[]', 'new', '$(site_time '-1 day')', '$(site_time '-1 day')'),
  ('$(site_time '-2 days')', 'Wanda Again T335', 'wanda-t335@example.com', 'wanda-t335@example.com', 'Driver T335', '[]', 'reviewed', '$(site_time '-2 days')', '$(site_time '-2 days')')" > /dev/null
T335_W=$(sq "SELECT id FROM ka_applications WHERE name = 'Wanda Wait T335'"); T335_F=$(sq "SELECT id FROM ka_applications WHERE name = 'Fresh Fred T335'"); T335_A=$(sq "SELECT id FROM ka_applications WHERE name = 'Wanda Again T335'")
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=applications&status=waiting"
expect "3.34 B: the tab Waiting lists the new application without a reply older than a week only; with selected offers status and responsible person, not delete to HR" \
  "$(grep -c "data-application=\"$T335_W\"" "$WORK/response")|$(grep -c "data-application=\"$T335_F\"" "$WORK/response")|$(grep -c 'data-applications-bulk' "$WORK/response")|$(grep -c 'name="do" value="delete"' "$WORK/response")" "1|0|1|0"
expect "3.34 B: with selected – rejected with a reason for two applications at once; the history keeps both" \
  "$(t334_post "$T334_JH" bulk -d do=status -d "ids[]=$T335_W" -d "ids[]=$T335_F" -d status=rejected -d reject_reason=position_filled)|$(sq "SELECT GROUP_CONCAT(CONCAT(status, ':', reject_reason, ':', JSON_LENGTH(history)) ORDER BY id) FROM ka_applications WHERE id IN ($T335_W, $T335_F)")" \
  "302|rejected:position_filled:1,rejected:position_filled:1"
t334_post "$T334_JH" bulk -d do=assign -d "ids[]=$T335_A" -d "assignee_id=$T334_HANA" > /dev/null
expect "3.34 B: with selected – a responsible person; HR may not delete (403), not in bulk and not one" \
  "$(sq "SELECT assignee_id = $T334_HANA FROM ka_applications WHERE id = $T335_A")|$(t334_post "$T334_JH" bulk -d do=delete -d "ids[]=$T335_F")|$(t334_post "$T334_JH" delete -d "id=$T335_F")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $T335_F")" "1|403|403|1"
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$T335_A"
expect "3.34 B: the detail – two columns, the other application from the same address, no Delete for HR" \
  "$(grep -c 'class="uchazec-detail"' "$WORK/response")|$(grep -c 'class="uchazec-bok"' "$WORK/response")|$(grep -c "action=detail&amp;id=$T335_W\"" "$WORK/response")|$(grep -c 'data-application-delete data-potvrdit' "$WORK/response")" "1|1|1|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&status=rejected"
expect "3.34 B: an administrator gets Delete in the list; deleting two at once removes them" \
  "$(grep -c 'name="do" value="delete"' "$WORK/response")|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=applications&action=bulk" -d "_csrf=$(csrf)" -d do=delete -d "ids[]=$T335_W" -d "ids[]=$T335_F")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id IN ($T335_W, $T335_F)")" "1|302|0"
