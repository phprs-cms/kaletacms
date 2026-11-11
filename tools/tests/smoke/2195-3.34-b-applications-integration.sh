echo "== 3.34 B: applications in the rest of Kaleta – leavers, My work, Ctrl K, the jobs list, the builder, JobPosting, the report"
T3395=$(sq "INSERT INTO ka_applications (received_at, name, email, email_norm, job_collection_id, job_item_id, job_title, data, status, status_at, last_activity_at, assignee_id) VALUES ('$(site_time '-1 day')', 'Ida Integr T3395', 'ida-t3395@example.com', 'ida-t3395@example.com', ${T334_IDK:-0}, ${T334_ITEM:-0}, 'Technician T334', '[]', 'interview', '$(site_time '-1 day')', '$(site_time '-1 day')', $T334_HANA); SELECT LAST_INSERT_ID();")
expect "3.34 B: a leaver – Hana's undecided application counts, needs the section to take it (Olga cannot), and moves with handOver" \
  "$(t334_php 'echo Kaleta\Core\Handover::owned($app->db(), '"$T334_HANA"')["application"], "|", implode(",", Kaleta\Core\Handover::sectionsToTake($app->db(), '"$T334_HANA"')), "|", isset(Kaleta\Core\Handover::eligible($app->db(), $app->settings(), ["applications"], '"$T334_HANA"')['"$T334_OLGA"']) ? "olga" : "no-olga";')" \
  "1|applications|no-olga"
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=my_work"
expect "3.34 B: My work lists Hana's application by name, number and position" "$(grep -c "data-application=\"$T3395\"" "$WORK/response")|$(grep -c "Application #$T3395 · Technician T334" "$WORK/response" | awk '{print ($1 > 0)}')" "1|1"
expect "3.34 B: Ctrl K finds the application by number for Hana; Olga has no palette source and 403 on it" \
  "$(curl -s -b "$T334_JH" "$B/admin.php?module=applications&action=search_json&q=%23$T3395" | grep -c 'Ida Integr T3395')|$(curl -s -b "$T334_JO" "$B/admin.php?module=enquiries" | grep -c 'data-applications=')|$(curl -s -b "$T334_JO" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=search_json&q=Ida")" "1|0|403"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=${T334_IDK:-0}"
expect "3.34 B: the jobs collection's items list shows Applications (new / all) for the job" "$(grep -o "data-job-applications=\"${T334_ITEM:-0}\"><a [^>]*>0 / 1<" "$WORK/response" | wc -l | tr -d ' ')" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=build_choices&id=$(sq "SELECT MIN(ids) FROM ka_stranky WHERE smazano IS NULL")"
expect "3.34 B: the builder's choices carry the open jobs for a Position field" "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo in_array("Technician T334", $d["volby"]["pozice"] ?? [], true) ? "yes" : "no";' "$WORK/response")" "yes"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/$T334_SLUG/technician-t334"
expect "3.34 B JobPosting: the job page says directApply and carries the identifier" "$(grep -c '"directApply":true' "$WORK/response")|$(grep -c '"identifier":{"@type":"PropertyValue"' "$WORK/response")" "1|1"
expect "3.34 B report: the move of applications this month marks the enquiry comparison as \"counting changed\"" \
  "$(t334_php 'echo Kaleta\Core\Applications::countingChanged($app->db(), date("Y-m-01 00:00:00", strtotime("first day of last month")), date("Y-m-d H:i:s", strtotime("first day of next month 00:00"))) !== null ? "changed" : "same";')" "changed"
sq "DELETE FROM ka_applications WHERE id = $T3395 OR email LIKE '%-t33%@example.com' OR email LIKE '%-t3395@example.com'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$T335_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
