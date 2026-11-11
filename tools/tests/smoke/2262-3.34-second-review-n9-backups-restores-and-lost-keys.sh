echo "== 3.34 second review N9: a restored backup – the restore screen warns, a job enquiry with its application twin stays moved, a CV opens from its encrypted copy"
r334_on
# the restore screen: a backup older than the first encrypted CV is marked and its confirmation says what is lost
N9_SINCE=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'applications_files_since'")
t334_php 'Kaleta\Core\Backup::create($app->db(), "n9", false);' > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_files_since', '$(( $(date +%s) + 3600 ))')" > /dev/null
curl -s -b "$JAR" -o "$WORK/n9-backups.html" "$B/admin.php?module=settings&tab=backups"
expect "3.34 second review N9: an older backup carries the mark and the warning in its confirmation; the rule" \
  "$([ "$(grep -c 'data-backup-misses-cvs' "$WORK/n9-backups.html")" -ge 1 ] && echo marked)|$(t334_php 'echo (int) Kaleta\Core\Applications::backupMissesFiles(100, "200"), (int) Kaleta\Core\Applications::backupMissesFiles(300, "200"), (int) Kaleta\Core\Applications::backupMissesFiles(100, "");')" "marked|100"
if [ -n "$N9_SINCE" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_files_since', '$N9_SINCE')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'applications_files_since'" > /dev/null; fi
# a backup taken around the move brought a job enquiry back next to its application: nobody sees it, deleting it keeps the CV the application lists
N9_HEX=$(php -r 'echo bin2hex(random_bytes(12));'); mkdir -p "$WORK/web/storage/prilohy/2026/05"; printf 'CV of Ota N9' > "$WORK/web/storage/prilohy/2026/05/$N9_HEX.pdf"
N9_AT=$(site_time '-40 months')
N9_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$N9_AT', 'N9 twin', 'stranka:1', '/kariera', 'ota-n9@example.com', '[[\"Name\",\"Ota N9\"],[\"CV\",\"cv.pdf (1 kB)\",\"2026/05/$N9_HEX.pdf\"]]', 0); SELECT LAST_INSERT_ID();")
sq "UPDATE ka_applications SET source_enquiry_id = NULL WHERE source_enquiry_id = $N9_E;
  INSERT INTO ka_applications (received_at, form, data, attachments, attachment_paths, status, status_at, last_activity_at, source_enquiry_id) VALUES ('$N9_AT', 'N9 twin', '[]',
  '[{\"field\":1,\"name\":\"cv.pdf\",\"size\":12,\"ext\":\"pdf\",\"path\":\"2026/05/$N9_HEX.pdf\",\"sealed\":false}]', '{\"bin\":[],\"old\":[\"2026/05/$N9_HEX.pdf\"]}', 'new', '$N9_AT', '$N9_AT', $N9_E)" > /dev/null
curl -s -b "$JAR" -o "$WORK/n9-list.html" "$B/admin.php?module=enquiries&search=ota-n9"
expect "3.34 second review N9: the twin enquiry is not in the list, its detail and CV answer 404 – also for the administrator" \
  "$(grep -c 'ota-n9@example.com' "$WORK/n9-list.html")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=detail&id=$N9_E")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=attachment&id=$N9_E&field=1")" "0|404|404"
t334_php 'Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings()); Kaleta\Admin\Modules\Enquiries::deleteAttachments($app->db()->all("SELECT idp, data FROM {poptavky} WHERE idp = '"$N9_E"'"), $app->db());' > /dev/null
expect "3.34 second review N9: the retention of enquiries leaves the twin alone, and deleting its files never removes the CV the application lists" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $N9_E")|$([ -f "$WORK/web/storage/prilohy/2026/05/$N9_HEX.pdf" ] && echo kept || echo gone)" "1|kept"
# a backup older than the sealing restored as an enquiry: its plain CV is gone, the encrypted copy opens with the key of its header and the sweep keeps it
sq "DELETE FROM ka_applications WHERE source_enquiry_id = $N9_E" > /dev/null
N9_COPY=$(t334_php '$s = $app->settings(); $path = Kaleta\Core\Applications::sealedTarget("2026/05/'"$N9_HEX"'.pdf"); Kaleta\Core\Applications::writeSealed($s, "CV of Ota N9", $path); echo Kaleta\Core\Applications::FOLDER, "/", $path;')
rm -f "$WORK/web/storage/prilohy/2026/05/$N9_HEX.pdf"; touch -t "$(php -r 'echo date("YmdHi", strtotime("-30 days"));')" "$N9_COPY"
curl -s -b "$JAR" -o "$WORK/n9-cv" "$B/admin.php?module=enquiries&action=attachment&id=$N9_E&field=1"
t334_php 'Kaleta\Core\Applications::sweepOrphans($app);' > /dev/null
expect "3.34 second review N9: the restored enquiry's CV downloads from the encrypted copy, which the sweep keeps while the enquiry names it" \
  "$(cat "$WORK/n9-cv")|$([ -f "$N9_COPY" ] && echo kept || echo gone)" "CV of Ota N9|kept"
sq "DELETE FROM ka_poptavky WHERE idp = $N9_E" > /dev/null
t334_php 'Kaleta\Core\Applications::sweepOrphans($app);' > /dev/null
expect "3.34 second review N9: once nothing names it, the old copy goes (set aside first – 3.34 third review F1)" "$([ -f "$N9_COPY" ] && echo kept || echo gone)" "gone"
rm -f "$N9_COPY.orphan"
r334_off
