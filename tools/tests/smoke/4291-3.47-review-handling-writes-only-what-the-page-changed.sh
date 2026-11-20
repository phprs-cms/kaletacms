echo "== 3.47 review F3: the one Save of Handling writes only the part the page changed – a colleague's move or new responsible person meanwhile stays"
R347_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications
R347_ME=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R347_ID=$(sq "INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, language, data, status, status_at, last_activity_at) VALUES ('$(site_time)', 9391, 'Fitter R347', 'Job application', 'Rita R347', 'rita-r347@example.com', 'rita-r347@example.com', '', '[]', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
r347_handling() { local c; curl -s -b "$JAR" -c "$JAR" -o "$WORK/r347-csrf" "$B/admin.php?module=applications"; c=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' "$WORK/r347-csrf" | sed -n 1p) # a file, never a pipe that closes early
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=applications&action=handling" -d "_csrf=$c" -d "id=$R347_ID" "$@"; }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r347-page" "$B/admin.php?module=applications&action=detail&id=$R347_ID"
expect "3.47 review F3: the Handling form carries what it shows – the status, the reason and the responsible person" \
  "$(grep -c 'name="was_status" value="new"' "$WORK/r347-page")|$(grep -c 'name="was_reject_reason" value=""' "$WORK/r347-page")|$(grep -c 'name="was_assignee_id" value="0"' "$WORK/r347-page")" "1|1|1"

# a colleague moves the application to Hired on the board while the page is open; the page only picks a responsible person
sq "UPDATE ka_applications SET status = 'hired', status_at = '$(site_time)', decided_at = '$(site_time)' WHERE id = $R347_ID" > /dev/null
expect "3.47 review F3: picking only the person on a stale page keeps the colleague's Hired (and its decision date) and writes no status change" \
  "$(r347_handling -d was_status=new -d was_reject_reason= -d was_assignee_id=0 -d status=new -d reject_reason=position_filled -d "assignee_id=$R347_ME")|$(sq "SELECT CONCAT(status, ':', decided_at IS NOT NULL, ':', assignee_id = $R347_ME) FROM ka_applications WHERE id = $R347_ID")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'status' AND popis LIKE '#$R347_ID %'")" \
  "302|hired:1:1|0"

# the mirrored case: the page showed nobody responsible and only the status changes – the person set meanwhile stays
expect "3.47 review F3: changing only the status on a page that showed nobody responsible keeps the person set meanwhile" \
  "$(r347_handling -d was_status=hired -d was_reject_reason= -d was_assignee_id=0 -d status=interview -d reject_reason=position_filled -d assignee_id=0)|$(sq "SELECT CONCAT(status, ':', decided_at IS NULL, ':', assignee_id = $R347_ME) FROM ka_applications WHERE id = $R347_ID")" \
  "302|interview:1:1"
expect "3.47 review F3: a new reason of a rejection alone is a change of the status part" \
  "$(r347_handling -d was_status=interview -d was_reject_reason= -d "was_assignee_id=$R347_ME" -d status=rejected -d reject_reason=position_filled -d "assignee_id=$R347_ME")|$(r347_handling -d was_status=rejected -d was_reject_reason=position_filled -d "was_assignee_id=$R347_ME" -d status=rejected -d reject_reason=other -d "assignee_id=$R347_ME")|$(sq "SELECT CONCAT(status, ':', reject_reason, ':', assignee_id = $R347_ME) FROM ka_applications WHERE id = $R347_ID")" \
  "302|302|rejected:other:1"

sq "DELETE FROM ka_applications WHERE id = $R347_ID; DELETE FROM ka_application_counts WHERE job_item_id = 9391;" > /dev/null; features --exact "$R347_EXT"
