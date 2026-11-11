echo "== 3.34 review M2: moving applications back to Enquiries loses nothing – an unreadable list or CV keeps the application"
# one application whose list was sealed with another key, one whose sealed CV is gone from the disk, one that moves
M2_IDS=$(t334_php '$s = $app->settings(); $db = $app->db(); $now = Kaleta\Core\Clock::now(); $ids = [];
  $row = ["received_at" => $now, "form" => "M2 back", "source" => "stranka:1", "data" => json_encode([["CV", "cv.pdf"]]), "status" => "reviewed", "status_at" => $now, "last_activity_at" => $now];
  $ids[] = $db->insert("applications", $row + ["attachments" => Kaleta\Core\Vault::seal(Kaleta\Core\Vault::derive(str_repeat("ab", 32), "applications"), "[]")]);
  $tmp = tempnam(sys_get_temp_dir(), "m2"); file_put_contents($tmp, "CV gone M2"); $gone = Kaleta\Core\Applications::sealFile($tmp);
  $ids[] = $db->insert("applications", $row + ["attachments" => Kaleta\Core\Applications::encodeAttachments($s, [["field" => 0, "name" => "cv.pdf", "size" => 10, "ext" => "pdf", "sealed" => true] + $gone])]);
  unlink(Kaleta\Core\Applications::FOLDER . "/" . $gone["path"]);
  $tmp = tempnam(sys_get_temp_dir(), "m2"); file_put_contents($tmp, "CV fine M2"); $fine = Kaleta\Core\Applications::sealFile($tmp);
  $ids[] = $db->insert("applications", $row + ["attachments" => Kaleta\Core\Applications::encodeAttachments($s, [["field" => 0, "name" => "cv.pdf", "size" => 10, "ext" => "pdf", "sealed" => true] + $fine])]);
  echo implode(",", $ids);')
M2_PLAIN_BEFORE=$(find "$WORK/web/storage/prilohy" -type f 2>/dev/null | wc -l | tr -d ' ')
M2_RESULT=$(t334_php 'echo json_encode(Kaleta\Core\ApplicationMove::toEnquiries($app, "M2 back", "stranka:1"));')
M2_E=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky WHERE formular = 'M2 back'")
M2_NEW=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$[0][2]')) FROM ka_poptavky WHERE idp = $M2_E")
expect "3.34 review M2: the two that cannot be read whole stay applications, counted as skipped; the third is an enquiry with its CV readable" \
  "$M2_RESULT|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id IN ($M2_IDS)")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE formular = 'M2 back'")|$(cat "$WORK/web/storage/prilohy/$M2_NEW" 2>/dev/null)" \
  '{"moved":1,"left":2,"skipped":2}|2|1|CV fine M2'
expect "3.34 review M2: no plain copy is left behind for the ones that stayed (only the moved CV is new in storage/prilohy)" "$(( $(find "$WORK/web/storage/prilohy" -type f | wc -l | tr -d ' ') - M2_PLAIN_BEFORE ))" "1"
expect "3.34 review M2: the event names the count only" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'applications.move_failed' AND message LIKE '2 application(s) could not be moved back%'")" "1"
sq "DELETE FROM ka_comments WHERE target = 'enquiry:$M2_E'; DELETE FROM ka_poptavky WHERE idp = $M2_E" > /dev/null
t334_php 'Kaleta\Core\Applications::delete($app, ['"$M2_IDS"'], false);' > /dev/null
