echo "== 3.51 B review: dead addresses at the head of a list cannot stop a send for good; undoing a Claude session keeps its pause; the campaign name counts who can be reached; a deleted newsletter keeps the last hour for the limit"
# the fake SMTP server of the B sections again (4736 stopped it)
command php "$ROOT/tools/fake-smtp.php" "$B351_SMTP" "$B351_DIR" > /dev/null 2>&1 & B351_PID=$!
background_jobs hold
b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters; DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$B351_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('newsletter_hourly_limit', '300')"
b351_clear; b351_alive

# five addresses refused as unknown come first, ten healthy after them: the brake pauses (nothing delivered yet), the
# resume sends the untried healthy ones before the put-back rows, the dead five then fail without tripping it again
B750_LIST=(odmitnout1@example.cz odmitnout2@example.cz odmitnout3@example.cz odmitnout4@example.cz odmitnout5@example.cz)
for i in $(seq 1 10); do B750_LIST+=("zdravy$i@example.cz"); done
b351_subscribers "${B750_LIST[@]}"
B750_NL=$(b351_newsletter "Hlava seznamu 750")
b351_admin send -d "id=$B750_NL" -d when=now; b351_cron; b351_wait 1
B750_FIRST="$(b351_state "$B750_NL")|$(b351_db "SELECT pause_reason FROM ka_newsletters WHERE id = $B750_NL")"
b351_clear; b351_alive; b351_admin resume -d "id=$B750_NL"; b351_cron; b351_wait 10
expect "3.51 B review: five dead addresses first – one brake pause; after the resume the ten healthy ones are delivered, the five fail at once and are registered, no second pause" \
  "$B750_FIRST|$(b351_state "$B750_NL")|$(b351_sum "$B750_NL")|$(b351_count)|$(b351_db "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'bounced'")|$(b351_db "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'newsletters' AND akce = 'pause' AND popis = 'Hlava seznamu 750'")" \
  "paused|15|0|0|0|brake|sent|15|10|5|0|1|10|5|1"

# deleting it keeps the rows that went out in the last hour (the hourly limit counts them); a cron call an hour later removes them
b351_admin delete -d "id=$B750_NL"
B750_KEPT="$(b351_db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $B750_NL")|$(b351_db "SELECT CONCAT(COUNT(*), '/', SUM(sent_at IS NOT NULL)) FROM ka_newsletter_queue WHERE newsletter_id = $B750_NL")"
b351_db "UPDATE ka_newsletter_queue SET sent_at = '$(site_time '-2 hours')' WHERE newsletter_id = $B750_NL"; b351_cron
expect "3.51 B review: a deleted newsletter leaves its last hour's sent rows for the hourly limit, the rest goes; an hour later they go too" \
  "$B750_KEPT|$(b351_db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $B750_NL")" "0|10/10|0"

# a list the server keeps refusing (5.7.1): every resume tries again, but a row that used its last attempt is not put back –
# the third pause leaves the five given up and the next resume ends the newsletter
b351_db "DELETE FROM ka_restore_ledger WHERE kind = 'bounced'"; b351_clear
b351_subscribers blokovano1@example.cz blokovano2@example.cz blokovano3@example.cz blokovano4@example.cz blokovano5@example.cz
B750_STUCK=$(b351_newsletter "Stále odmítán 750")
b351_admin send -d "id=$B750_STUCK" -d when=now; b351_cron
for round in 2 3; do b351_alive; b351_admin resume -d "id=$B750_STUCK"; b351_cron; done
B750_THIRD="$(b351_state "$B750_STUCK")|$(b351_db "SELECT CONCAT(MIN(attempts), '/', SUM(next_attempt IS NULL)) FROM ka_newsletter_queue WHERE newsletter_id = $B750_STUCK")"
b351_alive; b351_admin resume -d "id=$B750_STUCK"; b351_cron
expect "3.51 B review: refused on every try – three pauses use the three attempts, then the rows stay given up and the next resume ends it (no endless pause and resume)" \
  "$B750_THIRD|$(b351_state "$B750_STUCK")|$(b351_sum "$B750_STUCK")|$(b351_db "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'bounced'")" \
  "paused|5|0|5|0|3/5|sent|5|0|5|0|1|0"

# Claude pauses a send; undoing that session later must not resume it (resume is a person's decision in the administration)
B750_TOKEN="kaleta_$(openssl rand -hex 24)"
b351_db "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'b750 admin', 'full', 'all', SHA2('$B750_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'"
b351_subscribers u1@example.cz u2@example.cz
B750_UNDO=$(b351_newsletter "Zpět 750")
b351_admin send -d "id=$B750_UNDO" -d when=now
b351_db "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'"
mcp_call "$B750_TOKEN" send_newsletter "{\"id\":$B750_UNDO,\"pause\":true}" > /dev/null
B750_SESSION=$(b351_db "SELECT MAX(id) FROM ka_agent_sessions")
B750_JOURNAL=$(b351_db "SELECT CONCAT(SUM(untracked IS NULL), '/', SUM(untracked LIKE 'a paused send%')) FROM ka_agent_journal WHERE session_id = $B750_SESSION AND tbl = 'newsletters'")
b351_db "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'"
mcp_call "$B750_TOKEN" undo_agent_session "{\"id\":$B750_SESSION,\"confirm\":true}" > "$WORK/response"
expect "3.51 B review: an MCP pause is no journal row but an untracked write; undoing the session leaves the newsletter paused" \
  "$B750_JOURNAL|$(b351_db "SELECT CONCAT(status, '|', pause_reason, '|', paused_at IS NOT NULL) FROM ka_newsletters WHERE id = $B750_UNDO")|$(b351_db "SELECT undone_at IS NOT NULL FROM ka_agent_sessions WHERE id = $B750_SESSION")" \
  "0/1|paused|manual|1|1"
b351_admin cancel -d "id=$B750_UNDO"

# five confirmed subscribers of whom two bounced reach three people: the links carry no campaign name
b351_subscribers t1@example.cz t2@example.cz t3@example.cz mrtvy1@example.cz mrtvy2@example.cz
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); foreach (["mrtvy1@example.cz", "mrtvy2@example.cz"] as $e) { Kaleta\Core\SendRegister::record($app->db(), "bounced", $e); }')
B750_TAG=$(b351_newsletter "Kampaň 750"); b351_db "UPDATE ka_newsletters SET button_label = 'Kontakt', button_url = '/kontakt' WHERE id = $B750_TAG"
b351_admin send -d "id=$B750_TAG" -d when=now
expect "3.51 B review: 5 confirmed, 2 of them on the register – the frozen e-mail links with utm_source but without utm_campaign" \
  "$(b351_db "SELECT CONCAT(html LIKE '%utm_source=newsletter%', '|', html LIKE '%utm_campaign%', '|', recipients) FROM ka_newsletters WHERE id = $B750_TAG")" "1|0|5"
b351_admin cancel -d "id=$B750_TAG"

b351_db "DELETE FROM ka_newsletter_queue; DELETE FROM ka_newsletters; DELETE FROM ka_odberatele; DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused'); DELETE FROM ka_api_tokeny WHERE nazev = 'b750 admin'"
kill "$B351_PID" 2>/dev/null || true
b351_db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES"
background_jobs resume
