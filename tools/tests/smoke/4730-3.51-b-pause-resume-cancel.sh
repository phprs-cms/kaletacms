echo "== 3.51 B1: a newsletter can be paused, resumed and cancelled; recipients = sent + failed + skipped"
# a fake SMTP server of its own (tools/fake-smtp.php answers by address: odmitnout 5.1.1, blokovano 5.7.1, docasne 4.7.0, odesilatel 5.1.8)
B351_SMTP=$((PORT + 29)); B351_DIR="$WORK/smtp351"; mkdir -p "$B351_DIR"
command php "$ROOT/tools/fake-smtp.php" "$B351_SMTP" "$B351_DIR" > /dev/null 2>&1 & B351_PID=$!
b351_db() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
b351_clear() { find "${B351_DIR:?}" -name '*.eml' -delete; }
# b351_subscribers <address>…: exactly these confirmed subscribers, in this order
b351_subscribers() { local values="" a; for a in "$@"; do values="$values${values:+, }('$a', 1, '$(php -r 'echo bin2hex(random_bytes(16));')', '$(site_time)', '$(site_time)')"; done
  b351_db "DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES $values"; }
# b351_newsletter <subject>: a draft by the admin; prints its id
b351_newsletter() { b351_db "INSERT INTO ka_newsletters (subject, intro, news_mode, status, author, created, changed) VALUES ('$1', 'Dobrý den', 'none', 'draft', (SELECT idu FROM ka_uzivatele WHERE user = 'admin'), '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID()"; }
b351_admin() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
b351_alive() { b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_last_run', UNIX_TIMESTAMP())"; }
b351_cron() { b351_alive; run_job newsletter_mailing > /dev/null; }
b351_state() { b351_db "SELECT CONCAT_WS('|', status, recipients, sent_count, failed_count, skipped_count) FROM ka_newsletters WHERE id = $1"; }
b351_sum() { b351_db "SELECT recipients = sent_count + failed_count + skipped_count FROM ka_newsletters WHERE id = $1"; }
b351_count() { find "${B351_DIR:?}" -name '*.eml' | wc -l | tr -d ' '; }
b351_mails() { grep -l "^X-Rcpt-To: $1" "$B351_DIR"/*.eml 2>/dev/null | wc -l | tr -d ' '; }
# the fake server writes its file a moment after it accepted the message
b351_wait() { local i; for i in $(seq 1 25); do [ "$(b351_count)" -ge "$1" ] && return 0; sleep 0.2; done; }
b351_clear
background_jobs hold
b351_db "UPDATE ka_uzivatele SET email = 'admin@example.cz' WHERE user = 'admin'; DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$B351_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('newsletter_hourly_limit', '10');
  INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('company_name', 'Testovací firma s.r.o.'), ('company_street', 'Dlouhá 12'), ('company_postcode', '110 00'), ('company_city', 'Praha')
  ON DUPLICATE KEY UPDATE hodnota = IF(hodnota = '', VALUES(hodnota), hodnota)"
b351_alive
b351_subscribers a1@example.cz a2@example.cz a3@example.cz a4@example.cz a5@example.cz a6@example.cz

# pause before the first batch: nothing leaves, also with two cron processes at once; resume: the rest leaves once each
B730_NL=$(b351_newsletter "Pauza 730")
b351_admin send -d "id=$B730_NL" -d when=now
b351_admin pause -d "id=$B730_NL"
run_job newsletter_mailing > /dev/null & B730_P1=$!; run_job newsletter_mailing > /dev/null & B730_P2=$!; await_pids "$B730_P1" "$B730_P2"
expect "3.51 B1: paused – two cron processes send nothing, the reason is manual, the list says paused" \
  "$(b351_state "$B730_NL")|$(b351_db "SELECT pause_reason FROM ka_newsletters WHERE id = $B730_NL")|$(b351_count)|$(curl -s -b "$JAR" "$B/admin.php?module=newsletters" | grep -c 'Pozastaveno')" \
  "paused|6|0|0|0|manual|0|1"
check "3.51 B1: a paused newsletter offers Resume and Cancel" 200 "/admin.php?module=newsletters&action=edit&id=$B730_NL" "action=resume"
b351_admin delete -d "id=$B730_NL"
expect "3.51 B1: a paused newsletter cannot be deleted" "$(b351_db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $B730_NL")" "1"
b351_alive; b351_admin resume -d "id=$B730_NL"
b351_alive; run_job newsletter_mailing > /dev/null & B730_P1=$!; run_job newsletter_mailing > /dev/null & B730_P2=$!; await_pids "$B730_P1" "$B730_P2"; b351_wait 6
expect "3.51 B1: resumed – everyone gets exactly one e-mail, even with two cron processes; the newsletter ends with the count equality" \
  "$(b351_state "$B730_NL")|$(b351_sum "$B730_NL")|$(for a in a1 a2 a3 a4 a5 a6; do b351_mails "$a@example.cz"; done | sort -u | tr '\n' ' ')|$(b351_db "SELECT resumed_at IS NOT NULL FROM ka_newsletters WHERE id = $B730_NL")" \
  "sent|6|6|0|0|1|1 |1"

# cancel half-way (the hourly limit of 10 sends 10 of 14): the rest never goes, the rows stay a day for the hourly limit, then go
b351_db "DELETE FROM ka_newsletter_queue"; b351_clear
b351_subscribers c01@example.cz c02@example.cz c03@example.cz c04@example.cz c05@example.cz c06@example.cz c07@example.cz c08@example.cz c09@example.cz c10@example.cz c11@example.cz c12@example.cz c13@example.cz c14@example.cz
B730_C=$(b351_newsletter "Zrušit 730")
b351_admin send -d "id=$B730_C" -d when=now; b351_cron
B730_HALF=$(b351_state "$B730_C")
b351_admin cancel -d "id=$B730_C"; b351_db "UPDATE ka_newsletter_queue SET sent_at = '$(site_time '-2 hours')' WHERE newsletter_id = $B730_C AND sent_at IS NOT NULL"; b351_cron
expect "3.51 B1: cancelled after 10 of 14 – 4 skipped, the equality holds, nothing more goes out, the rows are kept (marked cancelled) for the hourly limit" \
  "$B730_HALF|$(b351_state "$B730_C")|$(b351_sum "$B730_C")|$(b351_db "SELECT CONCAT(COUNT(*), '/', SUM(error = 'cancelled'), '/', SUM(sent_at IS NOT NULL)) FROM ka_newsletter_queue WHERE newsletter_id = $B730_C")" \
  "sending|14|10|0|0|cancelled|14|10|0|4|1|14/4/10"
b351_db "UPDATE ka_newsletters SET finished_at = '$(site_time '-2 days')' WHERE id = $B730_C"; b351_cron
expect "3.51 B1: a day after the cancel the recipients are gone, the counts stay; a cancelled newsletter may be deleted" \
  "$(b351_db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $B730_C")|$(b351_state "$B730_C")|$(b351_admin delete -d "id=$B730_C"; b351_db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $B730_C")" \
  "0|cancelled|14|10|0|4|0"
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters WHERE id = $B730_NL; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('newsletter_hourly_limit', '300')"
