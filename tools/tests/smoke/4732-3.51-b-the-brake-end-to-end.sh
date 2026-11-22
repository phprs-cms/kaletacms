echo "== 3.51 B2: the brake pauses a send the server refuses; a stale list finishes; only real bounces reach the register"
b351_bounced() { b351_db "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'bounced'"; }
b351_db "DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused'); DELETE FROM ka_newsletter_queue"; b351_clear

# 550 5.7.1 for everyone: the brake pauses after 5 attempts; nothing got through, so the rows go back to the queue and nothing is registered
b351_subscribers blokovano1@example.cz blokovano2@example.cz blokovano3@example.cz blokovano4@example.cz blokovano5@example.cz blokovano6@example.cz
B732_NL=$(b351_newsletter "Brzda 732")
b351_admin send -d "id=$B732_NL" -d when=now; b351_cron; b351_wait 1
expect "3.51 B2: refused at RCPT with 5.7.1 – paused by the brake, the register empty, every row back in the queue untried, failed 0, one e-mail to the author" \
  "$(b351_state "$B732_NL")|$(b351_db "SELECT pause_reason FROM ka_newsletters WHERE id = $B732_NL")|$(b351_bounced)|$(b351_db "SELECT CONCAT(SUM(next_attempt IS NOT NULL), '/', SUM(attempts = 0), '/', SUM(reply = '')) FROM ka_newsletter_queue WHERE newsletter_id = $B732_NL")|$(b351_mails admin@example.cz)" \
  "paused|6|0|0|0|brake|0|6/6/6|1"
check "3.51 B2: the newsletter says the brake paused it" 200 "/admin.php?module=newsletters&action=edit&id=$B732_NL" "Pozastaveno brzdou"
check "3.51 B6: System status lists the paused newsletter" 200 "/admin.php?module=status" "Pozastavený newsletter"
# the server accepts again (the fake server decides by address): after the resume everyone is delivered, nothing failed
b351_db "UPDATE ka_odberatele SET email = REPLACE(email, 'blokovano', 'zdravy')"
b351_alive; b351_admin resume -d "id=$B732_NL"; b351_cron; b351_wait 7
expect "3.51 B2: resumed against a healthy server – all 6 delivered, failed 0, no second e-mail to the author" \
  "$(b351_state "$B732_NL")|$(b351_mails admin@example.cz)" "sent|6|6|0|0|1"

# a stale list – 6 dead (550 5.1.1) among 20, deliveries in between: no pause, exactly the six registered
b351_clear
B732_LIST=(); for i in $(seq 1 14); do B732_LIST+=("ziva$i@example.cz"); [ "$i" -le 6 ] && B732_LIST+=("odmitnout$i@example.cz"); done
b351_subscribers "${B732_LIST[@]}"
B732_STALE=$(b351_newsletter "Starý seznam 732")
b351_admin send -d "id=$B732_STALE" -d when=now; b351_cron; b351_wait 14
expect "3.51 D5: a stale list finishes without a pause – 14 sent, 6 failed at once, exactly the six bounced addresses registered, no e-mail to the author" \
  "$(b351_state "$B732_STALE")|$(b351_bounced)|$(b351_mails admin@example.cz)" "sent|20|14|6|0|6|0"
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SendRegister::blocked($app->db(), "odmitnout3@example.cz"), "|", Kaleta\Core\SendRegister::blocked($app->db(), "ziva3@example.cz") ?? "none";') > "$WORK/b732.txt"
expect "3.51 D4: the register knows the dead address, not the living one" "$(cat "$WORK/b732.txt")" "bounced|none"

# a list of three, all refused with 5.1.1: nothing delivered, so nothing registered (a server that refuses everyone never fills it)
b351_db "DELETE FROM ka_restore_ledger WHERE kind = 'bounced'"
b351_subscribers odmitnout-a@example.cz odmitnout-b@example.cz odmitnout-c@example.cz
B732_THREE=$(b351_newsletter "Tři 732")
b351_admin send -d "id=$B732_THREE" -d when=now; b351_cron
expect "3.51 D4: three addresses all refused with 5.1.1 – sent with 3 failed, nothing registered" "$(b351_state "$B732_THREE")|$(b351_bounced)" "sent|3|0|3|0|0"

# 550 5.1.8 speaks about the sender: retried like any failure, never registered
b351_subscribers ok-1@example.cz ok-2@example.cz odesilatel@example.cz
B732_SENDER=$(b351_newsletter "Odesílatel 732")
b351_admin send -d "id=$B732_SENDER" -d when=now; b351_cron
b351_db "UPDATE ka_newsletter_queue SET next_attempt = '$(site_time)' WHERE next_attempt IS NOT NULL"; b351_cron
b351_db "UPDATE ka_newsletter_queue SET next_attempt = '$(site_time)' WHERE next_attempt IS NOT NULL"; b351_cron
expect "3.51 D4: 5.1.8 (the sender's address) is given up after the retries and never registered" \
  "$(b351_state "$B732_SENDER")|$(b351_db "SELECT CONCAT(attempts, '|', reply) FROM ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE o.email = 'odesilatel@example.cz'")|$(b351_bounced)" \
  "sent|3|2|1|0|3|rcpt:550:5.1.8|0"
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters WHERE id IN ($B732_NL, $B732_STALE, $B732_THREE, $B732_SENDER); DELETE FROM ka_restore_ledger WHERE kind = 'bounced'"
