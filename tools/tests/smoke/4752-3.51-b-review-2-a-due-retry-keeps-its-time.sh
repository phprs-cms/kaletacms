echo "== 3.51 B review (second round): a greylisted address is retried when its retry is due, not after every untried address of the list"
command php "$ROOT/tools/fake-smtp.php" "$B351_SMTP" "$B351_DIR" > /dev/null 2>&1 & B752_PID=$!
background_jobs hold
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters; DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$B351_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('newsletter_hourly_limit', '10')"
b351_clear; b351_alive

# the first address answers 451 (greylisting), thirty healthy ones follow; ten e-mails an hour
B752_LIST=(docasne1@example.cz)
for i in $(seq 1 30); do B752_LIST+=("zdravy$i@example.cz"); done
b351_subscribers "${B752_LIST[@]}"
B752_NL=$(b351_newsletter "Šedý seznam 752")
b351_admin send -d "id=$B752_NL" -d when=now; b351_cron; b351_wait 9
B752_FIRST="$(b351_db "SELECT CONCAT(q.attempts, '/', q.next_attempt > '$(site_time)', '/', LEFT(q.reply, 7)) FROM ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE q.newsletter_id = $B752_NL AND o.email = 'docasne1@example.cz'")|$(b351_db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $B752_NL AND attempts = 0")"
# an hour later: the retry is due and the hourly limit is free again – twenty-one addresses were never tried
b351_db "UPDATE ka_newsletter_queue SET sent_at = '$(site_time '-2 hours')' WHERE newsletter_id = $B752_NL AND sent_at IS NOT NULL;
  UPDATE ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id SET q.next_attempt = '$(site_time '-1 minute')' WHERE q.newsletter_id = $B752_NL AND o.email = 'docasne1@example.cz'"
b351_cron
expect "3.51 B review (second round): the greylisted first address fails (451), waits its 15 minutes and is tried again in the next batch with the untried ones – not only after all 21 of them" \
  "$B752_FIRST|$(b351_db "SELECT q.attempts FROM ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE q.newsletter_id = $B752_NL AND o.email = 'docasne1@example.cz'")|$(b351_db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $B752_NL AND attempts = 0")|$(b351_state "$B752_NL")" \
  "1/1/rcpt:45|21|2|12|sending|31|18|0|0"

b351_admin cancel -d "id=$B752_NL"
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters; DELETE FROM ka_odberatele"
kill "$B752_PID" 2>/dev/null || true
b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES"
background_jobs resume
