echo "== 3.50 C (U33): the customer page lists the next open task of each of its enquiries and deals, deals only with the Pipeline"
C362_NOW=$(site_time)
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('olga-c362@example.com', 'olga-c362@example.com', 'Olga C362', 'manual', '$C362_NOW', '$C362_NOW', '$C362_NOW')" > /dev/null
C362_C=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'olga-c362@example.com'")
sq "INSERT INTO ka_poptavky (datum, formular, tema, email, data, contact_id) VALUES ('$C362_NOW', 'Contact C362', 'Kitchen C362', 'olga-c362@example.com', '[]', $C362_C);
  INSERT INTO ka_deals (title, contact_id, stage, created_at, updated_at) VALUES ('Website C362', $C362_C, 'new', '$C362_NOW', '$C362_NOW')" > /dev/null
C362_E=$(sq "SELECT idp FROM ka_poptavky WHERE tema = 'Kitchen C362'"); C362_D=$(sq "SELECT id FROM ka_deals WHERE title = 'Website C362'")
C362_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES
  ('Call back C362', 'enquiry:$C362_E', $C362_ADMIN, $C362_ADMIN, '$(site_time '+2 days')', '$C362_NOW', '$C362_NOW'),
  ('Send the offer C362', 'deal:$C362_D', $C362_ADMIN, $C362_ADMIN, '$(site_time '-1 day')', '$C362_NOW', '$C362_NOW'),
  ('Done already C362', 'deal:$C362_D', $C362_ADMIN, $C362_ADMIN, '$(site_time '-3 days')', '$C362_NOW', '$C362_NOW')" > /dev/null
sq "UPDATE ka_tasks SET done_at = '$C362_NOW', done_by = $C362_ADMIN WHERE title = 'Done already C362'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=contacts&action=detail&id=$C362_C" > "$WORK/code362.txt"
expect "3.50 C (U33): the Tasks panel – the overdue deal task first, then the enquiry task, each linking to its record; no done task; no \"No open tasks\" while they wait" \
  "$(cat "$WORK/code362.txt")|$(sed -n '/data-tasks-related/,/<\/ol>/p' "$WORK/response" | grep -o 'C362</strong>' | wc -l | tr -d ' ')|$(sed -n '/data-tasks-related/,/<\/ol>/p' "$WORK/response" | grep -o '<strong>[^<]*C362</strong>' | head -1)|$(grep -c "action=detail&amp;id=$C362_E#tasks\"" "$WORK/response")|$(grep -c "id=$C362_D#tasks\"" "$WORK/response")|$(grep -c 'Done already C362' "$WORK/response")|$(grep -c 'data-tasks-none' "$WORK/response")" \
  "200|2|<strong>Send the offer C362</strong>|1|1|0|0"
# without the Pipeline the deal's task is not shown (and its record not linked)
C362_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features -pipeline
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=contacts&action=detail&id=$C362_C"
expect "3.50 C (U33): with the Pipeline off only the enquiry's task is listed" \
  "$(sed -n '/data-tasks-related/,/<\/ol>/p' "$WORK/response" | grep -o 'C362</strong>' | wc -l | tr -d ' ')|$(grep -c 'Send the offer C362' "$WORK/response")" "1|0"
features --exact "$C362_EXT"
sq "DELETE FROM ka_tasks WHERE title LIKE '%C362'; DELETE FROM ka_deals WHERE id = $C362_D; DELETE FROM ka_poptavky WHERE idp = $C362_E; DELETE FROM ka_contacts WHERE id = $C362_C" > /dev/null
