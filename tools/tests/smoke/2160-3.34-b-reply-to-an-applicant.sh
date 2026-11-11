echo "== 3.34 B: a reply to an applicant – templates, the signature machinery, the status with it, no person in the mail log"
T336_SMTP=$((PORT + 3)); mkdir -p "$WORK/smtp-t336"; command php "$ROOT/tools/fake-smtp.php" "$T336_SMTP" "$WORK/smtp-t336" > /dev/null 2>&1 & T336_SMTP_PID=$!
sq "DROP TABLE IF EXISTS ka_t336_mail; CREATE TABLE ka_t336_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$T336_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
T336=$(sq "INSERT INTO ka_applications (received_at, name, email, email_norm, job_title, language, data, status, status_at, last_activity_at) VALUES ('$(site_time '-1 day')', 'Rita Reply T336', 'rita-t336@example.com', 'rita-t336@example.com', 'Painter T336', 'en', '[]', 'new', '$(site_time '-1 day')', '$(site_time '-1 day')'); SELECT LAST_INSERT_ID();")
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$T336&template=rejection"
expect "3.34 B: the template Rejection fills the form in the application's language – name, position, company; rejected chosen to follow" \
  "$(grep -c 'value="Your application – Painter T336"' "$WORK/response")|$(grep -c '>Hello Rita Reply T336,' "$WORK/response")|$(grep -c 'for Painter T336' "$WORK/response" | awk '{print ($1 > 0)}')|$(grep -c '<option value="rejected" selected>' "$WORK/response")" "1|1|1|1"
T336_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
t334_post "$T334_JH" reply -d "id=$T336" --data-urlencode "subject=Your application – Painter T336" --data-urlencode $'text=Hello Rita,\nthank you – not this time.' -d status=rejected -d reject_reason=not_suitable > /dev/null
T336_F=$(grep -l "^X-Rcpt-To: rita-t336@example.com" "$WORK"/smtp-t336/*.eml 2>/dev/null | tail -1 || true)
expect "3.34 B: the reply went to the applicant only, Reply-To the sender; kept on the application, rejected with the reason, Hana responsible now" \
  "$(grep -c '^X-Rcpt-To:' "$T336_F" 2>/dev/null)|$(grep -c '^Reply-To: hana-t334@example.com' "$T336_F" 2>/dev/null)|$(sq "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].status')), CONVERT(status USING utf8mb4), CONVERT(reject_reason USING utf8mb4), assignee_id = $T334_HANA) FROM ka_applications WHERE id = $T336")" \
  "1|1|sent|rejected|not_suitable|1"
expect "3.34 B: the mail log keeps \"Reply to application #N\" – no name, no position; the event and the change log name the number only" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(predmet LIKE '%Rita%' OR predmet LIKE '%Painter%'), '|', SUM(predmet LIKE 'Reply to application #$T336:%')) FROM ka_posta WHERE idp > $T336_P0")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'application.replied' AND message NOT LIKE '%Rita%' AND data LIKE '%\"application\":$T336%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'reply' AND popis = '#$T336'")" \
  "1|0|1|1|1"
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$T336"
expect "3.34 B: the detail shows the reply with its delivery state" "$(grep -c 'data-reply-status="sent"' "$WORK/response")" "1"
# the "received" confirmation when the form sends none of its own; an own template text per language wins
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('application_templates', '{\"cs\":{\"received\":{\"subject\":\"Máme to T336\",\"text\":\"Díky {{name}} za zájem o místo {{position}}.\"}}}')" > /dev/null
T336_R=$(t334_php 'echo json_encode(Kaleta\Core\Applications::compose($app->settings(), ["name" => "", "job_title" => "", "language" => "cs"], "received"), JSON_UNESCAPED_UNICODE);')
expect "3.34 B: an own template in Czech wins; an empty name takes its comma, no position reads \"a position with us\"" "$T336_R" '{"subject":"Máme to T336","text":"Díky za zájem o místo u nás."}'
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'application_templates'); INSERT INTO ka_nastaveni SELECT * FROM ka_t336_mail; DROP TABLE ka_t336_mail" > /dev/null
kill "$T336_SMTP_PID" 2>/dev/null || true; wait "$T336_SMTP_PID" 2>/dev/null || true
