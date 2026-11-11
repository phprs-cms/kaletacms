echo "== 3.34 second review N7: erasing or deleting an application whose CV list the key cannot open leaves no CV behind – or says why it stays"
r334_on
N7_HEX=$(php -r 'echo bin2hex(random_bytes(12));'); mkdir -p "$WORK/web/storage/prilohy/2026/06" "$WORK/web/storage/applications/2026/06"
printf 'plain CV N7' > "$WORK/web/storage/prilohy/2026/06/$N7_HEX.pdf"
N7_BIN=$(php -r 'echo bin2hex(random_bytes(16));'); printf 'sealed CV N7' > "$WORK/web/storage/applications/2026/06/$N7_BIN.bin"
# a moved application half sealed: one CV still plain, one sealed – and its list sealed with a key this site does not have
N7_IDS=$(t334_php '$db = $app->db(); $now = Kaleta\Core\Clock::now(); $locked = Kaleta\Core\Vault::seal(Kaleta\Core\Vault::derive(bin2hex(random_bytes(32)), "applications"), "[]");
  $row = ["received_at" => $now, "form" => "N7", "email" => "erika-n7@example.com", "email_norm" => "erika-n7@example.com", "data" => "[]", "status" => "new", "status_at" => $now, "last_activity_at" => $now, "attachments" => $locked];
  echo $db->insert("applications", $row + ["attachment_paths" => json_encode(["bin" => ["2026/06/'"$N7_BIN"'.bin"], "old" => ["2026/06/'"$N7_HEX"'.pdf"]], JSON_UNESCAPED_SLASHES)]), ",",
    $db->insert("applications", ["email" => "frank-n7@example.com", "email_norm" => "frank-n7@example.com"] + $row);')
N7_A=${N7_IDS%%,*}; N7_B=${N7_IDS#*,}
expect "3.34 second review N7: both lists are locked (the encryption key)" "$(t334_php '$s = $app->settings(); foreach (['"$N7_IDS"'] as $id) { echo Kaleta\Core\Applications::get($app, $id)["attachments_locked"] ? "locked" : "open", ";"; }')" "locked;locked;"
N7_ERASE=$(t334_php 'echo json_encode(Kaleta\Core\PersonalData::erase($app, "erika-n7@example.com")["erased"]["applications"] ?? -1);')
expect "3.34 second review N7: erased – the row, the plain CV in storage/prilohy and the sealed copy all go (found from attachment_paths)" \
  "$N7_ERASE|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $N7_A")|$([ -f "$WORK/web/storage/prilohy/2026/06/$N7_HEX.pdf" ] && echo plain-kept || echo plain-gone)|$([ -f "$WORK/web/storage/applications/2026/06/$N7_BIN.bin" ] && echo bin-kept || echo bin-gone)" \
  "1|0|plain-gone|bin-gone"
N7_HELD=$(t334_php '$r = Kaleta\Core\PersonalData::erase($app, "frank-n7@example.com")["erased"]; echo ($r["applications"] ?? -1), "|", ($r["applications_held"] ?? 0);')
expect "3.34 second review N7: a locked list without attachment_paths – its files cannot be located: the application stays and the erasure says so (applications_held), the event names it by number" \
  "$N7_HELD|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $N7_B")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'applications.delete_held' AND message LIKE '%#$N7_B%' AND message NOT LIKE '%frank%'")" "0|1|1|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$N7_B"
expect "3.34 second review N7: the administrator's delete keeps it too and says why" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=applications&action=delete" -d "_csrf=$(csrf)" -d "id=$N7_B")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $N7_B")" "302|1"
sq "DELETE FROM ka_applications WHERE id = $N7_B; DELETE FROM ka_events WHERE type = 'applications.delete_held'" > /dev/null
r334_off
