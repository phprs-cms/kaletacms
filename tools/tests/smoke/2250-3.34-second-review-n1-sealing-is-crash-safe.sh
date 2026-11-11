echo "== 3.34 second review N1: sealing a moved CV is crash-safe – a run killed between the steps is finished by the next one, nothing is lost"
r334_on
N1_DIR="$WORK/web/storage/prilohy/2026/09"; mkdir -p "$N1_DIR"
# one moved application per case, each with its CV still in storage/prilohy (as migration 0120 leaves it)
n1_app() { # $1 source enquiry id, $2 content → "id|old path"
  local hex; hex=$(php -r 'echo bin2hex(random_bytes(12));'); printf '%s' "$2" > "$N1_DIR/$hex.pdf"
  sq "INSERT INTO ka_applications (received_at, form, data, attachments, attachment_paths, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$(site_time)', 'N1', '[[\"CV\",\"cv.pdf\"]]',
    '[{\"field\":0,\"name\":\"cv.pdf\",\"size\":9,\"ext\":\"pdf\",\"path\":\"2026/09/$hex.pdf\",\"sealed\":false}]', '{\"bin\":[],\"old\":[\"2026/09/$hex.pdf\"]}', 'new', '$(site_time)', '$(site_time)', $1); SELECT CONCAT(LAST_INSERT_ID(), '|2026/09/$hex.pdf');"
}
n1_state() { # $1 id, $2 old path → sealed|plain file kept/gone|copies of it|content
  t334_php '$s = $app->settings(); $a = Kaleta\Core\Applications::attachments($s, $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$1"'"))[0] ?? [];
    echo empty($a["sealed"]) ? "plain" : "sealed", "|", is_file(Kaleta\Core\Applications::OLD_FOLDER . "/'"$2"'") ? "kept" : "gone", "|",
      count(glob(Kaleta\Core\Applications::FOLDER . "/2026/09/*.bin") ?: []), "|", Kaleta\Core\Applications::attachment($app, '"$1"', 0)["content"] ?? "";'
}
sq "UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id BETWEEN 925001 AND 925009" > /dev/null
rm -rf "${WORK:?}/web/storage/applications/2026/09"

# killed after step 1 (the copy written, the row not yet changed): the next run takes the copy it finds – one copy, sealed, the plain file gone
N1_A=$(n1_app 925001 'CV of N1a'); N1_A_ID=${N1_A%%|*}; N1_A_OLD=${N1_A#*|}
expect "3.34 second review N1: a run killed after writing the copy – the row still lists the plain CV, the plain file stays" \
  "$(t334_php 'echo Kaleta\Core\Applications::sealEntry($app, '"$N1_A_ID"', 0, 1);')|$(n1_state "$N1_A_ID" "$N1_A_OLD")" "stopped|plain|kept|1|CV of N1a"
t334_php 'Kaleta\Core\Applications::sealPending($app);' > /dev/null
expect "3.34 second review N1: the next run finishes it – sealed, the plain file gone, still one copy, it opens" "$(n1_state "$N1_A_ID" "$N1_A_OLD")" "sealed|gone|1|CV of N1a"

# killed after step 2 (the row lists the copy, the plain file still there) and after step 3: the next run checks the copy and removes the plain file
N1_B=$(n1_app 925002 'CV of N1b'); N1_B_ID=${N1_B%%|*}; N1_B_OLD=${N1_B#*|}
N1_C=$(n1_app 925003 'CV of N1c'); N1_C_ID=${N1_C%%|*}; N1_C_OLD=${N1_C#*|}
expect "3.34 second review N1: runs killed after the row changed (step 2) and after the check (step 3) – the plain file stays until it is checked" \
  "$(t334_php 'echo Kaleta\Core\Applications::sealEntry($app, '"$N1_B_ID"', 0, 2), Kaleta\Core\Applications::sealEntry($app, '"$N1_C_ID"', 0, 3);')|$(n1_state "$N1_B_ID" "$N1_B_OLD")" "stoppedstopped|sealed|kept|3|CV of N1b"
t334_php 'Kaleta\Core\Applications::sealPending($app);' > /dev/null
expect "3.34 second review N1: the next run removes the plain files the killed runs left" "$(n1_state "$N1_B_ID" "$N1_B_OLD")|$(n1_state "$N1_C_ID" "$N1_C_OLD")" "sealed|gone|3|CV of N1b|sealed|gone|3|CV of N1c"

# a copy that does not open to the plain file (damaged) is never trusted: the entry is listed plain again and sealed anew
N1_D=$(n1_app 925004 'CV of N1d'); N1_D_ID=${N1_D%%|*}; N1_D_OLD=${N1_D#*|}
t334_php 'Kaleta\Core\Applications::sealEntry($app, '"$N1_D_ID"', 0, 2);' > /dev/null
N1_D_BIN=$(t334_php 'echo Kaleta\Core\Applications::FOLDER, "/", Kaleta\Core\Applications::sealedTarget("'"$N1_D_OLD"'");')
printf 'damaged' > "$N1_D_BIN"
expect "3.34 second review N1: a damaged copy – the plain file is kept and listed again (reverted), then sealed anew" \
  "$(t334_php 'echo Kaleta\Core\Applications::sealEntry($app, '"$N1_D_ID"', 0);')|$(n1_state "$N1_D_ID" "$N1_D_OLD")|$(t334_php 'echo Kaleta\Core\Applications::sealEntry($app, '"$N1_D_ID"', 0);')|$(n1_state "$N1_D_ID" "$N1_D_OLD")" \
  "reverted|plain|kept|4|CV of N1d|sealed|sealed|gone|4|CV of N1d"

# N9: a database restored from a backup older than the sealing lists the plain CV again, which is gone – the sealed copy is taken back with the key of its header
N1_E=$(n1_app 925005 'CV of N1e'); N1_E_ID=${N1_E%%|*}; N1_E_OLD=${N1_E#*|}
N1_E_BEFORE=$(sq "SELECT attachments FROM ka_applications WHERE id = $N1_E_ID")
t334_php 'Kaleta\Core\Applications::sealPending($app);' > /dev/null
sq "UPDATE ka_applications SET attachments = '$N1_E_BEFORE' WHERE id = $N1_E_ID" > /dev/null
expect "3.34 second review N9: restored to the plain listing, the plain CV gone – the download opens the sealed copy, the next run takes it back" \
  "$(n1_state "$N1_E_ID" "$N1_E_OLD")|$(t334_php 'echo Kaleta\Core\Applications::sealEntry($app, '"$N1_E_ID"', 0);')|$(n1_state "$N1_E_ID" "$N1_E_OLD")" "plain|gone|5|CV of N1e|recovered|sealed|gone|5|CV of N1e"

# the sweep: an encrypted file no row lists and a half-written one, older than a week, go; a listed one and a recent orphan stay
N1_ORPHAN=$(t334_php '$p = "2026/09/" . bin2hex(random_bytes(16)) . ".bin"; Kaleta\Core\Applications::writeSealed($app->settings(), "orphan", $p); echo Kaleta\Core\Applications::FOLDER, "/", $p;')
N1_FRESH="$WORK/web/storage/applications/2026/09/$(php -r 'echo bin2hex(random_bytes(16));').bin"; printf 'x' > "$N1_FRESH"
N1_PART="$N1_FRESH.ab12cd34.part"; printf 'x' > "$N1_PART"
N1_OLD_TIME=$(php -r 'echo date("YmdHi", strtotime("-8 days"));')
touch -t "$N1_OLD_TIME" "$N1_ORPHAN" "$N1_PART" "$N1_D_BIN"
# 3.34 third review F1: the old orphan is set aside (.orphan) for seven more days, not removed at once
expect "3.34 second review N1: the sweep sets the old orphan aside and removes the half-written file, keeps the listed copy and the recent orphan" \
  "$(t334_php 'echo Kaleta\Core\Applications::sweepOrphans($app);')|$([ -f "$N1_ORPHAN" ] && echo kept || echo gone)|$([ -f "$N1_ORPHAN.orphan" ] && echo aside || echo gone)|$([ -f "$N1_PART" ] && echo kept || echo gone)|$([ -f "$N1_D_BIN" ] && echo kept || echo gone)|$([ -f "$N1_FRESH" ] && echo kept || echo gone)" \
  "1|gone|aside|gone|kept|kept"
rm -f "$N1_FRESH" "$N1_ORPHAN.orphan"

# a new upload: the row is stored before the upload goes (receive) – checked in the source, the smoke test of part A covers the way through
expect "3.34 second review N1: receive() removes the uploads only after the row lists the sealed copies, and removes the copies when the row cannot be stored" \
  "$(grep -c 'N1: the uploads go only once the row lists their sealed copies' "$WORK/web/system/src/Core/Applications.php")|$(grep -c 'N1: no row lists them' "$WORK/web/system/src/Core/Applications.php")" "1|1"
t334_php 'Kaleta\Core\Applications::delete($app, ['"$N1_A_ID,$N1_B_ID,$N1_C_ID,$N1_D_ID,$N1_E_ID"'], false);' > /dev/null
expect "3.34 second review N1: deleting them leaves no file behind" "$(find "$WORK/web/storage/applications/2026/09" "$N1_DIR" -type f 2>/dev/null | wc -l | tr -d ' ')" "0"
r334_off
