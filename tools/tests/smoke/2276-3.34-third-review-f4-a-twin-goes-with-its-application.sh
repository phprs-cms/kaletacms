echo "== 3.34 third review F4: an enquiry twin a restored backup brought back goes with its application and in the clean-up – it never surfaces in Enquiries"
r334_on
f4_twin() { # $1 e-mail, $2 CV content → "enquiry id|application id|plain CV path"
  local hex at e a; hex=$(php -r 'echo bin2hex(random_bytes(12));'); mkdir -p "$WORK/web/storage/prilohy/2026/04"; printf '%s' "$2" > "$WORK/web/storage/prilohy/2026/04/$hex.pdf"
  at=$(site_time '-3 months')
  e=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$at', 'F4 twin', 'stranka:1', '/kariera', '$1', '[[\"Name\",\"F4\"],[\"CV\",\"cv.pdf (1 kB)\",\"2026/04/$hex.pdf\"]]', 0); SELECT LAST_INSERT_ID();")
  a=$(sq "INSERT INTO ka_applications (received_at, form, data, attachments, attachment_paths, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$at', 'F4 twin', '[]',
    '[{\"field\":1,\"name\":\"cv.pdf\",\"size\":9,\"ext\":\"pdf\",\"path\":\"2026/04/$hex.pdf\",\"sealed\":false}]', '{\"bin\":[],\"old\":[\"2026/04/$hex.pdf\"]}', 'new', '$at', '$(site_time)', $e); SELECT LAST_INSERT_ID();")
  sq "INSERT INTO ka_comments (target, author_name, text, via, created_at) VALUES ('enquiry:$e', 'F4', 'a note on the twin', 'admin', '$at')" > /dev/null
  echo "$e|$a|$WORK/web/storage/prilohy/2026/04/$hex.pdf"
}
f4_state() { # $1 enquiry id, $2 application id → enquiry rows|application rows|notes of the enquiry
  sq "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_poptavky WHERE idp = $1), (SELECT COUNT(*) FROM ka_applications WHERE id = $2), (SELECT COUNT(*) FROM ka_comments WHERE target = 'enquiry:$1'))"
}
# deleting the application deletes its twin with the note and the CV
F4_A=$(f4_twin 'f4-delete@example.com' 'CV of F4a'); F4_A_E=${F4_A%%|*}; F4_A_REST=${F4_A#*|}; F4_A_ID=${F4_A_REST%%|*}; F4_A_CV=${F4_A_REST#*|}
expect "3.34 third review F4: before – the twin is there and hidden" "$(f4_state "$F4_A_E" "$F4_A_ID")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=detail&id=$F4_A_E")" "1|1|1|404"
t334_php 'echo Kaleta\Core\Applications::delete($app, ['"$F4_A_ID"'], false);' > /dev/null
curl -s -b "$JAR" -o "$WORK/f4-list.html" "$B/admin.php?module=enquiries&search=f4-delete"
expect "3.34 third review F4: deleting the application deletes its twin, the twin's note and the CV – nothing surfaces in Enquiries" \
  "$(f4_state "$F4_A_E" "$F4_A_ID")|$([ -f "$F4_A_CV" ] && echo kept || echo gone)|$(grep -c 'f4-delete@example.com' "$WORK/f4-list.html")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=detail&id=$F4_A_E")" "0|0|0|gone|0|404"
# the daily clean-up removes a twin while its application stays, and keeps the CV the application lists
F4_B=$(f4_twin 'f4-cleanup@example.com' 'CV of F4b'); F4_B_E=${F4_B%%|*}; F4_B_REST=${F4_B#*|}; F4_B_ID=${F4_B_REST%%|*}; F4_B_CV=${F4_B_REST#*|}
t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
# (the clean-up may seal the CV meanwhile – the plain file then goes, the application's CV still opens)
expect "3.34 third review F4: the clean-up deletes the twin and its note, the application and its CV stay" \
  "$(f4_state "$F4_B_E" "$F4_B_ID")|$(t334_php 'echo Kaleta\Core\Applications::attachment($app, '"$F4_B_ID"', 0)["content"] ?? "lost";')" "0|1|0|CV of F4b"
t334_php 'Kaleta\Core\Applications::delete($app, ['"$F4_B_ID"'], false);' > /dev/null
expect "3.34 third review F4: an enquiry with the same number but another date is no twin – it stays" \
  "$(F4_C=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time '-1 day')', 'F4 other', 'stranka:1', '/kontakt', 'f4-other@example.com', '[]', 0); SELECT LAST_INSERT_ID();");
    sq "INSERT INTO ka_applications (received_at, form, data, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$(site_time '-2 days')', 'F4', '[]', 'new', '$(site_time)', '$(site_time)', $F4_C)" > /dev/null
    t334_php 'Kaleta\Core\Applications::purge($app);' > /dev/null; sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $F4_C"; sq "DELETE FROM ka_applications WHERE source_enquiry_id = $F4_C; DELETE FROM ka_poptavky WHERE idp = $F4_C" > /dev/null)" "1"
r334_off
