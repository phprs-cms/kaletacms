echo "== 3.34 B: Move by form – the enquiries of a career form to applications and back, safe to run again"
T338_HEX=$(php -r 'echo bin2hex(random_bytes(12));'); T338_PATH="2026/11/$T338_HEX.pdf"; mkdir -p "$WORK/web/storage/prilohy/2026/11"; printf 'CV of Karel T338' > "$WORK/web/storage/prilohy/$T338_PATH"
T338=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, email, data, stav, poznamka) VALUES ('$(site_time '-3 days')', 'Kariera T338', 'stranka:1', 'x', '/kariera', 'karel-t338@example.com',
  '[[\"Name\",\"Karel T338\"],[\"CV\",\"cv.pdf (1 kB)\",\"$T338_PATH\"]]', 1, 'Called him'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_comments (target, kind, author_name, text, via, created_at) VALUES ('enquiry:$T338', 'note', 'Admin', 'Good one T338', 'admin', '$(site_time)')" > /dev/null
expect "3.34 B move: HR may not open the tool" "$(curl -s -b "$T334_JH" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=move")" "403"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=move"
expect "3.34 B move: the administrator sees the form among the enquiry forms" "$(grep -c 'value="stranka:1|Kariera T338"' "$WORK/response")" "1"
t338_run() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=move"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=applications&action=move_run" -d "_csrf=$(csrf)" --data-urlencode "form=stranka:1|Kariera T338" "$@"; }
t338_run -d direction=to > /dev/null
T338_A=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE source_enquiry_id = $T338")
expect "3.34 B move: the enquiry is an application now – reviewed, the name, the file listed unsealed, the note and the old note follow; the enquiry is gone" \
  "$(sq "SELECT CONCAT(status, '|', name, '|', form) FROM ka_applications WHERE id = $T338_A")|$(t334_php 'echo json_encode(array_column(Kaleta\Core\Applications::attachments($app->settings(), $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$T338_A"'")), "sealed"));')|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'application:$T338_A'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $T338")" \
  "reviewed|Karel T338|Kariera T338|[false]|2|0"
expect "3.34 B move: run again – nothing moves twice" "$(t338_run -d direction=to)|$(sq "SELECT COUNT(*) FROM ka_applications WHERE form = 'Kariera T338'")" "302|1"
# sealed by the clean-up, then back: the CV is decrypted to the enquiries' place again, the notes follow, the sealed copy goes
t334_php 'echo Kaleta\Core\Applications::sealPending($app);' > /dev/null
T338_SEALED=$(t334_php '$a = Kaleta\Core\Applications::attachments($app->settings(), $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$T338_A"'")); echo ($a[0]["sealed"] ?? false) ? Kaleta\Core\Applications::filePath($a[0]) : "";')
t338_run -d direction=back > /dev/null
T338_E=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky WHERE formular = 'Kariera T338'")
T338_NEW=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$[1][2]')) FROM ka_poptavky WHERE idp = $T338_E")
expect "3.34 B move back: an enquiry again (read, kind job), the CV readable in storage/prilohy, the notes and a note of the move follow, the application and its sealed file are gone" \
  "$(sq "SELECT CONCAT(stav, '|', kategorie, '|', email) FROM ka_poptavky WHERE idp = $T338_E")|$(cat "$WORK/web/storage/prilohy/$T338_NEW" 2>/dev/null)|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'enquiry:$T338_E'")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE form = 'Kariera T338'")|$([ -n "$T338_SEALED" ] && [ ! -f "$T338_SEALED" ] && echo gone || echo kept)" \
  "1|job|karel-t338@example.com|CV of Karel T338|3|0|gone"
expect "3.34 B move: both ways are in the change log and the events – counts only" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'move' AND popis LIKE '% moved to %' AND popis NOT LIKE '%karel%'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'applications.moved' AND message LIKE '% moved to %' AND message NOT LIKE '%Karel%'")" "2|2"
sq "DELETE FROM ka_comments WHERE target = 'enquiry:$T338_E'; DELETE FROM ka_poptavky WHERE idp = $T338_E" > /dev/null
