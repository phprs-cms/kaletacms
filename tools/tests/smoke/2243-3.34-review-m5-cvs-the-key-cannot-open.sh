echo "== 3.34 review M5: CVs whose list the encryption key cannot open are said so, kept, and never sealed under a lost key"
r334_on
M5_IDS=$(t334_php '$db = $app->db(); $now = Kaleta\Core\Clock::now(); $old = Kaleta\Core\Clock::at("-30 months");
  $row = ["received_at" => $old, "form" => "M5", "data" => json_encode([["Name", "Mia M5"]]), "status" => "rejected", "status_at" => $old, "decided_at" => $old, "last_activity_at" => $old];
  echo $db->insert("applications", $row + ["attachments" => Kaleta\Core\Vault::seal(Kaleta\Core\Vault::derive(str_repeat("cd", 32), "applications"), "[]")]);')
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6'), ('applications_retention_confirmed', '1')" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$M5_IDS"
curl -s -b "$JAR" -o "$WORK/m5-list.html" "$B/admin.php?module=applications&status=&search=Mia+M5"
expect "3.34 review M5: the detail and the list say the CVs cannot be opened (encryption key), never “no attachments”" \
  "$(grep -c 'data-application-files-locked' "$WORK/response")|$(grep -c 'data-application-locked' "$WORK/m5-list.html")" "1|1"
expect "3.34 review M5: System status has the row, MCP get_application says attachments_locked" \
  "$(t334_php 'foreach (Kaleta\Core\Health::checks($app) as $c) { if (str_contains($c["info"], "encryption key: they were sealed")) { echo $c["stav"]; } }')|$(t334_php '$a = Kaleta\Core\Applications::get($app, '"$M5_IDS"'); echo $a["attachments_locked"] ? "locked" : "open";')" "chyba|locked"
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.34 review M5: the clean-up does not delete it while its CVs cannot be opened (it would orphan them)" "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $M5_IDS")" "1"
# the key of config.php is missing (the data belongs to another key): a new list is sealed with the database key, marked, readable – and resealed once the key is back
M5_CHECK=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'vault_check'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('vault_check', '$(php -r 'echo bin2hex(random_bytes(16));')')" > /dev/null
M5_HELD=$(t334_php '$s = $app->settings(); echo Kaleta\Core\Vault::state($s), "|"; $l = Kaleta\Core\Applications::encodeAttachments($s, [["field" => 0, "name" => "cv.pdf", "size" => 1, "ext" => "pdf", "path" => "2026/11/x.bin", "key" => "k", "sealed" => true]]);
  echo substr($l, 0, 3), "|", Kaleta\Core\Applications::attachments($s, $l)[0]["name"] ?? "", "|"; $db = $app->db(); $now = Kaleta\Core\Clock::now();
  echo $db->insert("applications", ["received_at" => $now, "form" => "M5 held", "data" => "[]", "attachments" => $l, "status" => "new", "status_at" => $now, "last_activity_at" => $now]);')
expect "3.34 review M5: key missing – the list is sealed with the database key (db:) and still opens" "${M5_HELD%|*}" "missing|db:|cv.pdf"
M5_HELD_ID="${M5_HELD##*|}"
if [ -n "$M5_CHECK" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('vault_check', '$M5_CHECK')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'vault_check'" > /dev/null; fi
t334_php 'Kaleta\Core\Applications::resealHeld($app);' > /dev/null
expect "3.34 review M5: the key back – the held list is sealed again with the proper key and opens" \
  "$(sq "SELECT LEFT(attachments, 3) <> 'db:' FROM ka_applications WHERE id = $M5_HELD_ID")|$(t334_php 'echo Kaleta\Core\Applications::attachments($app->settings(), $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$M5_HELD_ID"'"))[0]["name"] ?? "";')" "1|cv.pdf"
expect "3.34 review M5: the state texts and the config.php line name the CVs" "$(t334_php 'echo (int) str_contains(Kaleta\Core\Vault::describe("missing"), "CVs"), (int) str_contains(Kaleta\Core\Vault::configLine(str_repeat("e", 64)), "CVs");')" "11"
sq "DELETE FROM ka_applications WHERE id IN ($M5_IDS, $M5_HELD_ID)" > /dev/null
r334_off
