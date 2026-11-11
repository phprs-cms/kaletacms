echo "== 3.34 second review N10: the customer clean-up decides inside its delete; a new signing key never strands CV lists held under the key in the database"
r334_on
N10_AT=$(site_time '-2 days')
sq "UPDATE ka_applications SET source_contact_id = NULL WHERE source_contact_id IN (926401, 926402, 926403)" > /dev/null
sq "INSERT INTO ka_contacts (id, email, email_norm, name, source, created_at, updated_at) VALUES
  (926401, 'linked-n10@example.com', 'linked-n10@example.com', 'Linked N10', 'enquiry', '$N10_AT', '$N10_AT'),
  (926402, 'expired-n10@example.com', 'expired-n10@example.com', 'Expired N10', 'enquiry', '$N10_AT', '$N10_AT');
  INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at, source_contact_id) VALUES
  ('$N10_AT', 'N10', '[]', 'new', '$N10_AT', '$N10_AT', 926401), ('$N10_AT', 'N10', '[]', 'new', '$N10_AT', '$N10_AT', 926402);
  INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, contact_id) VALUES ('$N10_AT', 'N10 other', 'stranka:1', '/kontakt', 'linked-n10@example.com', '[]', 0, 926401)" > /dev/null
t334_php 'Kaleta\Core\ApplicationMove::forgetLoneContacts($app->db());' > /dev/null
expect "3.34 second review N10: a record an enquiry links (meanwhile, too) stays; one whose other enquiry already expired goes; both lose the link" \
  "$(sq "SELECT IFNULL(GROUP_CONCAT(id ORDER BY id), '-') FROM ka_contacts WHERE id IN (926401, 926402)")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE source_contact_id IN (926401, 926402)")" "926401|0"
expect "3.34 second review N10: the check is the delete itself, inside the transaction that unlinks the record – in the move by form and in 0120" \
  "$(grep -c 'self::LONE_DELETE' "$WORK/web/system/src/Core/ApplicationMove.php")|$(grep -c 'if ($db->run("DELETE FROM {contacts} WHERE id = ? AND source' "$WORK/web/system/sql/migrace/0120-applications-move.php")" "1|1"
sq "DELETE FROM ka_poptavky WHERE contact_id = 926401; DELETE FROM ka_contacts WHERE id = 926401; DELETE FROM ka_applications WHERE form = 'N10'" > /dev/null
# a CV list held under the key in the database (the key of config.php was missing): a new signing key reseals it first
N10_CHECK=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'vault_check'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('vault_check', '$(php -r 'echo bin2hex(random_bytes(16));')')" > /dev/null
N10_HELD=$(t334_php '$s = $app->settings(); $l = Kaleta\Core\Applications::encodeAttachments($s, [["field" => 0, "name" => "cv-n10.pdf", "size" => 1, "ext" => "pdf", "path" => "2026/11/x.bin", "key" => "k", "sealed" => true]]);
  $now = Kaleta\Core\Clock::now(); echo $app->db()->insert("applications", ["received_at" => $now, "form" => "N10 held", "data" => "[]", "attachments" => $l, "status" => "new", "status_at" => $now, "last_activity_at" => $now]);')
if [ -n "$N10_CHECK" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('vault_check', '$N10_CHECK')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'vault_check'" > /dev/null; fi
expect "3.34 second review N10: a new signing key – the held list is sealed again first, then the key changes, and the list still opens" \
  "$(sq "SELECT LEFT(attachments, 3) FROM ka_applications WHERE id = $N10_HELD")|$(t334_php 'echo Kaleta\Core\SigningKey::rotate($app)["status"];')|$(sq "SELECT LEFT(attachments, 3) <> 'db:' FROM ka_applications WHERE id = $N10_HELD")|$(t334_php 'echo Kaleta\Core\Applications::attachments($app->settings(), $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$N10_HELD"'"))[0]["name"] ?? "";')" \
  "db:|rotated|1|cv-n10.pdf"
# a held list nothing can open any more: the rotation is refused and nothing changes
sq "UPDATE ka_applications SET attachments = 'db:bm90LWEtc2VhbA==' WHERE id = $N10_HELD" > /dev/null
N10_KEY=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")
expect "3.34 second review N10: a held list that cannot be sealed again refuses the new signing key – the key stays" \
  "$(t334_php 'echo Kaleta\Core\SigningKey::rotate($app)["status"];')|$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")" = "$N10_KEY" ] && echo same || echo changed)" "held|same"
sq "DELETE FROM ka_applications WHERE id = $N10_HELD" > /dev/null
r334_off
