echo "== 3.40 review: the fixes of the security review of quote PDFs, quotes II and applications II"
r340_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }

# M1: a mangled talent-pool link (a trailing dot or bracket) is a 404 that is never kept among the addresses not found
R340_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
for r340_suffix in '.' ')' '/'; do curl -s -o /dev/null "$B/application-consent/$R340_TOKEN$r340_suffix"; done
curl -s -o /dev/null "$B/en/Application-Consent/$R340_TOKEN."
expect "3.40 review M1: no mangled /application-consent/ address is kept among the addresses not found" \
  "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta LIKE '%$R340_TOKEN%'")" "0"

# M2: a bulk rejection leaves a hired applicant alone – no rejection, no e-mail, counted as skipped
sq "DROP TABLE IF EXISTS ka_r340_saved; CREATE TABLE ka_r340_saved AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('mail_from', 'web@example.cz')" > /dev/null
R340_HIRED=$(sq "INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, language, data, status, status_at, last_activity_at, decided_at) VALUES ('$(site_time)', 9341, 'Welder R340', 'Job application', 'Hana R340', 'hana-r340@example.com', 'hana-r340@example.com', '', '[]', 'hired', '$(site_time)', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
R340_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
expect "3.40 review M2: Reject and send the rejection skips a hired applicant – still hired, no reply, nothing in the mail queue" \
  "$(r340_php 'echo json_encode(Kaleta\Core\Applications::rejectWithTemplate($app, ['"$R340_HIRED"'], "position_filled"));')|$(sq "SELECT CONCAT(status, ':', IFNULL(JSON_LENGTH(replies), 0)) FROM ka_applications WHERE id = $R340_HIRED")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE idp > $R340_P0")" \
  '{"sent":0,"queued":0,"no_email":0,"skipped":1}|hired:0|0'
r340_php 'Kaleta\Core\Applications::delete($app, ['"$R340_HIRED"'], false);' > /dev/null
sq "DELETE FROM ka_application_counts WHERE job_item_id = 9341; DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_r340_saved; DROP TABLE ka_r340_saved" > /dev/null
