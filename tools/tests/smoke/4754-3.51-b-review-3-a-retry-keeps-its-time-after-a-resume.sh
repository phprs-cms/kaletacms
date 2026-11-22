echo "== 3.51 B review (third round): after a pause and a resume a due retry still goes with the next batch; only a row the brake put back waits for the untried ones"
command php "$ROOT/tools/fake-smtp.php" "$B351_SMTP" "$B351_DIR" > /dev/null 2>&1 & B754_PID=$!
background_jobs hold
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters; DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$B351_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('newsletter_hourly_limit', '10')"
b351_clear; b351_alive

# the first address answers 451 (greylisting), thirty healthy ones follow; ten e-mails an hour
B754_LIST=(docasne1@example.cz)
for i in $(seq 1 30); do B754_LIST+=("zdravy$i@example.cz"); done
b351_subscribers "${B754_LIST[@]}"
B754_NL=$(b351_newsletter "Šedý seznam 754")
b351_admin send -d "id=$B754_NL" -d when=now; b351_cron; b351_wait 9
# the author pauses the send by hand and resumes it: every row loses its tried_at (the brake counts again from here)
b351_admin pause -d "id=$B754_NL"; b351_alive; b351_admin resume -d "id=$B754_NL"
B754_RESUMED="$(b351_state "$B754_NL")|$(b351_db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $B754_NL AND tried_at IS NOT NULL")"
# zdravy10 looks like a retry left by 3.50 or an attempt cut off after claim() (attempts 1, no tried_at, no reply);
# zdravy11 is a row the brake put back (its reply is the mark)
b351_db "UPDATE ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id SET q.attempts = 1, q.reply = '' WHERE q.newsletter_id = $B754_NL AND o.email = 'zdravy10@example.cz';
  UPDATE ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id SET q.attempts = 1, q.reply = 'brake' WHERE q.newsletter_id = $B754_NL AND o.email = 'zdravy11@example.cz';
  UPDATE ka_newsletter_queue SET sent_at = '$(site_time '-2 hours')' WHERE newsletter_id = $B754_NL AND sent_at IS NOT NULL;
  UPDATE ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id SET q.next_attempt = '$(site_time '-1 minute')' WHERE q.newsletter_id = $B754_NL AND o.email = 'docasne1@example.cz'"
b351_cron
b754_row() { b351_db "SELECT CONCAT(q.attempts, '/', q.sent_at IS NOT NULL) FROM ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE q.newsletter_id = $B754_NL AND o.email = '$1'"; }
expect "3.51 B review (third round): after a manual pause and resume the greylisted address is retried in the next batch, the row like a 3.50 retry goes in its place, the put-back row waits for the untried addresses" \
  "$B754_RESUMED|$(b754_row docasne1@example.cz)|$(b754_row zdravy10@example.cz)|$(b754_row zdravy11@example.cz)|$(b351_db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $B754_NL AND attempts = 0")|$(b351_state "$B754_NL")" \
  "sending|31|9|0|0|0|2/0|2/1|1/0|11|sending|31|18|0|0"

b351_admin cancel -d "id=$B754_NL"
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters; DELETE FROM ka_odberatele"
kill "$B754_PID" 2>/dev/null || true
b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES"
background_jobs resume
