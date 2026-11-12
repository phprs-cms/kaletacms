echo "== 3.34 third review F1: an encrypted CV no row lists (a restored backup) is set aside for seven days, comes back when listed or opened, and only then goes"
r334_on
F1_DIR="$WORK/web/storage/applications/2026/08"
rm -rf "${F1_DIR:?}"
f1_bin() { # $1 content → the full path of a sealed file written two months ago
  t334_php '$p = "2026/08/" . bin2hex(random_bytes(16)) . ".bin"; Kaleta\Core\Applications::writeSealed($app->settings(), "'"$1"'", $p); echo Kaleta\Core\Applications::FOLDER, "/", $p;'
}
f1_where() { [ -f "$1" ] && echo here || { [ -f "$1.orphan" ] && echo aside || echo gone; }; }
F1_OLD=$(php -r 'echo date("YmdHi", strtotime("-60 days"));')
# a CV received after the backup: its row is gone with the restore, its file is two months old
F1_A=$(f1_bin 'CV of F1a'); F1_B=$(f1_bin 'CV of F1b'); F1_C=$(f1_bin 'CV of F1c')
touch -t "$F1_OLD" "$F1_A" "$F1_B" "$F1_C"
expect "3.34 third review F1: the first sweep after the restore removes nothing – the unlisted old files are set aside, their time is now" \
  "$(t334_php 'echo Kaleta\Core\Applications::sweepOrphans($app);')|$(f1_where "$F1_A")|$(f1_where "$F1_B")|$(f1_where "$F1_C")|$(php -r 'echo time() - filemtime($argv[1]) < 600 ? "fresh" : "old";' -- "$F1_A.orphan")" \
  "0|aside|aside|aside|fresh"
expect "3.34 third review F1: a second sweep the same week still removes nothing" "$(t334_php 'echo Kaleta\Core\Applications::sweepOrphans($app);')|$(f1_where "$F1_A")" "0|aside"
# B: a newer backup restored – a row lists it again; the sweep puts it back
F1_B_REL="2026/08/$(basename "$F1_B")"
F1_B_ID=$(sq "INSERT INTO ka_applications (received_at, form, data, attachment_paths, status, status_at, last_activity_at) VALUES ('$(site_time)', 'F1', '[]', '{\"bin\":[\"$F1_B_REL\"],\"old\":[]}', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
t334_php 'Kaleta\Core\Applications::sweepOrphans($app);' > /dev/null
expect "3.34 third review F1: listed again, the set-aside file is put back under its name" "$(f1_where "$F1_B")" "here"
# C: something opens it before the sweep ran (the download of a restored row) – it comes back and opens
expect "3.34 third review F1: opening a set-aside file puts it back" \
  "$(t334_php 'echo Kaleta\Core\Applications::openSealed($app->settings(), "'"$F1_C"'", null);')|$(f1_where "$F1_C")" "CV of F1c|here"
# A: unlisted seven days after it was set aside – only now it goes
touch -t "$(php -r 'echo date("YmdHi", strtotime("-8 days"));')" "$F1_A.orphan"
expect "3.34 third review F1: unlisted for seven days since it was set aside, the file goes" "$(t334_php 'echo Kaleta\Core\Applications::sweepOrphans($app);')|$(f1_where "$F1_A")" "1|gone"
# deleting an application removes a set-aside copy too
mv "$F1_B" "$F1_B.orphan"
t334_php 'Kaleta\Core\Applications::delete($app, ['"$F1_B_ID"'], false);' > /dev/null
expect "3.34 third review F1: deleting the application leaves no set-aside copy behind" "$(f1_where "$F1_B")" "gone"
rm -rf "${F1_DIR:?}"
r334_off
