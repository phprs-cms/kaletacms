echo "== 3.51 B3, B4: a register address is skipped; a send paused for 7 days is cancelled, also with SMTP switched off"
b351_clear; b351_db "DELETE FROM ka_newsletter_queue"
b351_subscribers r1@example.cz odmitl@example.cz r2@example.cz
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); Kaleta\Core\SendRegister::record($app->db(), "refused", "odmitl@example.cz");')
B734_NL=$(b351_newsletter "Registr 734")
b351_admin send -d "id=$B734_NL" -d when=now; b351_cron; b351_wait 2
expect "3.51 B3: an address that said no gets no e-mail and no attempt – it ends among the skipped" \
  "$(b351_state "$B734_NL")|$(b351_sum "$B734_NL")|$(b351_mails odmitl@example.cz)|$(b351_db "SELECT CONCAT(q.error, '|', q.tried_at IS NULL, '|', q.attempts) FROM ka_newsletter_queue q JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE o.email = 'odmitl@example.cz'")" \
  "sent|3|2|0|1|1|0|refused|1|1"
check "3.51 B1: a sent newsletter with a skipped address says why" 200 "/admin.php?module=newsletters&action=edit&id=$B734_NL" "Přeskočeno"

# paused for 8 days, SMTP switched off: the cron call still cancels it and queues the e-mail to its author
b351_subscribers p1@example.cz p2@example.cz
B734_OLD=$(b351_newsletter "Stará pauza 734")
b351_admin send -d "id=$B734_OLD" -d when=now; b351_admin pause -d "id=$B734_OLD"
b351_db "UPDATE ka_newsletters SET paused_at = '$(site_time '-8 days')' WHERE id = $B734_OLD; DELETE FROM ka_posta;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail')"
b351_cron
expect "3.51 B4: paused for 8 days with SMTP off – cancelled, 2 skipped, the author told (queued while SMTP is off), the change log says why" \
  "$(b351_state "$B734_OLD")|$(b351_sum "$B734_OLD")|$(b351_db "SELECT COUNT(*) FROM ka_posta WHERE komu = 'admin@example.cz'")|$(b351_db "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'newsletters' AND akce = 'cancel' AND duvod LIKE 'paused for 7 days%'")" \
  "cancelled|2|0|0|2|1|1|1"
b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'); DELETE FROM ka_posta; DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters WHERE id IN ($B734_NL, $B734_OLD); DELETE FROM ka_restore_ledger WHERE kind = 'refused'"
