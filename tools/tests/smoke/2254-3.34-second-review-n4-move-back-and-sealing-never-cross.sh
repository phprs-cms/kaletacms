echo "== 3.34 second review N4: a move back and the sealing of CVs never cross – a list changed meanwhile keeps the application, one lock for both"
r334_on
N4_DIR="$WORK/web/storage/prilohy/2026/08"; mkdir -p "$N4_DIR"
N4_HEX=$(php -r 'echo bin2hex(random_bytes(12));'); printf 'CV of N4' > "$N4_DIR/$N4_HEX.pdf"
sq "UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id = 925401" > /dev/null
N4_ID=$(sq "INSERT INTO ka_applications (received_at, form, source, data, attachments, attachment_paths, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$(site_time '-1 day')', 'N4 back', 'stranka:1', '[[\"CV\",\"cv.pdf\"]]',
  '[{\"field\":0,\"name\":\"cv.pdf\",\"size\":8,\"ext\":\"pdf\",\"path\":\"2026/08/$N4_HEX.pdf\",\"sealed\":false}]', '{\"bin\":[],\"old\":[\"2026/08/$N4_HEX.pdf\"]}', 'reviewed', '$(site_time)', '$(site_time)', 925401); SELECT LAST_INSERT_ID();")
# a move back that read the row before the sealing changed its list: the delete matches nothing, the transaction rolls back, the application stays whole
N4_RACE=$(t334_php '$db = $app->db(); $row = $db->one("SELECT * FROM {applications} WHERE id = '"$N4_ID"'");
  Kaleta\Core\Applications::sealPending($app);
  $back = (new ReflectionMethod(Kaleta\Core\ApplicationMove::class, "moveBack"))->invoke(null, $app, $app->settings(), $row, Kaleta\Core\Clock::now());
  echo $back ? "moved" : "stayed", "|", $db->value("SELECT COUNT(*) FROM {applications} WHERE id = '"$N4_ID"'"), "|", $db->value("SELECT COUNT(*) FROM {poptavky} WHERE formular = ?", ["N4 back"]), "|",
    Kaleta\Core\Applications::attachment($app, '"$N4_ID"', 0)["content"] ?? "";')
expect "3.34 second review N4: a move back with a list read before the sealing changed it – the application stays, no enquiry, its CV opens" "$N4_RACE" "stayed|1|0|CV of N4"
expect "3.34 second review N4: nothing it decrypted is left in storage/prilohy" "$(find "$N4_DIR" -type f | wc -l | tr -d ' ')" "0"
# one lock: while a move holds it, the clean-up does not seal (and a move waits for a running clean-up)
N4_HEX2=$(php -r 'echo bin2hex(random_bytes(12));'); printf 'CV of N4 two' > "$N4_DIR/$N4_HEX2.pdf"
sq "UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id = 925402" > /dev/null
N4_ID2=$(sq "INSERT INTO ka_applications (received_at, form, source, data, attachments, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$(site_time '-1 day')', 'N4 lock', 'stranka:1', '[]',
  '[{\"field\":0,\"name\":\"cv.pdf\",\"size\":12,\"ext\":\"pdf\",\"path\":\"2026/08/$N4_HEX2.pdf\",\"sealed\":false}]', 'reviewed', '$(site_time)', '$(site_time)', 925402); SELECT LAST_INSERT_ID();")
( "${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GET_LOCK('kaleta_cv_ka_', 0), SLEEP(6)" > /dev/null 2>&1 ) & N4_HOLD=$!
sleep 1
expect "3.34 second review N4: while the lock is held the clean-up seals nothing and returns at once" \
  "$(t334_php 'echo Kaleta\Core\Applications::sealPending($app);')|$([ -f "$N4_DIR/$N4_HEX2.pdf" ] && echo plain || echo sealed)" "0|plain"
wait "$N4_HOLD" 2>/dev/null || true
expect "3.34 second review N4: the lock released – the next clean-up seals it" "$(t334_php 'echo Kaleta\Core\Applications::sealPending($app) >= 1 ? "sealed" : "no";')|$([ -f "$N4_DIR/$N4_HEX2.pdf" ] && echo plain || echo gone)" "sealed|gone"
expect "3.34 second review N4: both ways of the move take the same lock" \
  "$(grep -c 'self::locked(\$db, fn (): array => self::moveRows(\$app, \$db->all(\|self::locked(\$app->db(), fn (): array => self::moveRows(\$app, // F6\|self::locked(\$app->db(), fn (): array => self::toEnquiriesLocked(' "$WORK/web/system/src/Core/ApplicationMove.php")" "3"
t334_php 'Kaleta\Core\Applications::delete($app, ['"$N4_ID,$N4_ID2"'], false);' > /dev/null
r334_off
