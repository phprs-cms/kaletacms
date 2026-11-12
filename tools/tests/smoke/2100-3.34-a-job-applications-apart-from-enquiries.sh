echo "== 3.34 (A): job applications apart from enquiries – intake, sealed CVs, the section, statuses, notes and tasks, retention, personal data, the move"
t334_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
t334_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/t334-form.html" | head -1 | sed 's/.*value="//;s/"$//'; }
t334_login() { curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
t334_post() { local jar="$1" action="$2"; shift 2; curl -s -b "$jar" -o "$WORK/response" "$B/admin.php?module=applications"; curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=applications&action=$action" -d "_csrf=$(csrf)" "$@"; }
T334_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
# Hana: HR (Applications only); Olga: Enquiries, Customers and the pipeline – sales sees nothing of applications
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('t334-hana', '$T334_HASH', 'Hana T334', 'hana-t334@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('t334-olga', '$T334_HASH', 'Olga T334', 'olga-t334@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'applications' FROM ka_uzivatele WHERE user = 't334-hana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts' UNION SELECT 'pipeline') m WHERE user = 't334-olga'" > /dev/null
T334_HANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't334-hana'"); T334_OLGA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 't334-olga'")
T334_JH="$WORK/t334-hana.jar"; T334_JO="$WORK/t334-olga.jar"; t334_login t334-hana "$T334_JH"; t334_login t334-olga "$T334_JO"
# the feature off: the section is not there, even for Hana
expect "3.34: with the feature off the section answers as unknown, also for a person who has it" "$(curl -s -b "$T334_JH" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications")" "403"
T334_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$T334_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$T334_EXT"),applications')" > /dev/null
# a jobs collection with one open job (its item page carries the application form with a CV)
mcp create_collection '{"preset":"jobs","name":"Jobs T334"}' > "$WORK/response"; T334_IDK=$(sq "SELECT MAX(idk) FROM ka_kolekce WHERE preset = 'jobs'"); T334_SLUG=$(sq "SELECT seo_link FROM ka_kolekce WHERE idk = ${T334_IDK:-0}")
mcp save_collection_item "{\"collection\":\"$T334_SLUG\",\"name\":\"Technician T334\",\"slug\":\"technician-t334\",\"visible\":true}" > /dev/null
T334_ITEM=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = ${T334_IDK:-0} AND seo_link = 'technician-t334'")
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/t334-form.html" "$B/$T334_SLUG/technician-t334"
T334_SRC=$(t334_field zdroj); T334_EL=$(t334_field prvek); T334_TIME=$(t334_field as_cas); T334_SIG=$(t334_field as_podpis)
expect "3.34: the job's item page carries the application form (multipart, a file field)" "$T334_SRC|$(grep -c 'enctype="multipart/form-data"' "$WORK/t334-form.html")" "kolekce:${T334_IDK:-0}|1"
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n%% CV of Ivan T334 secret-cv-t334\n' > "$WORK/t334-cv.pdf"
printf '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>' > "$WORK/t334-fake.pdf"
t334_submit() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -F "zdroj=$T334_SRC" -F "prvek=$T334_EL" -F "zpet=/$T334_SLUG/technician-t334" -F "as_cas=$T334_TIME" -F "as_podpis=$T334_SIG" \
  -F "p0=Ivan Novak T334" -F "p1=ivan-t334@example.com" -F "p2=777334334" -F "p4=I would like to work for you" -F "p5=1" -F "p6=Technician T334" "$@"; }
sleep 4
sq "DROP TABLE IF EXISTS ka_t334_mail; CREATE TABLE ka_t334_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '1'), ('smtp_encryption', 'zadne'), ('smtp_user', ''); DELETE FROM ka_kontrola_ip" > /dev/null
T334_E0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky"); T334_C0=$(sq "SELECT COUNT(*) FROM ka_contacts"); T334_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
T334_BAD=$(t334_submit -F "p3=@$WORK/t334-fake.pdf;filename=cv.pdf")
expect "3.34: an HTML file named .pdf is refused at its field – nothing stored" "$(echo "$T334_BAD" | grep -o 'result=[a-z]*&field=3')|$(sq "SELECT COUNT(*) FROM ka_applications")" "result=pole&field=3|0"
T334_OK=$(t334_submit -F "p3=@$WORK/t334-cv.pdf;filename=CV Ivan.pdf")
T334_A=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications")
expect "3.34: an application from the job page lands in Applications – the position from the page, the name and phone, new; no enquiry, no customer record" \
  "$(echo "$T334_OK" | grep -o 'result=ok')|$(sq "SELECT CONCAT(job_item_id = ${T334_ITEM:-0}, '|', job_title, '|', name, '|', phone, '|', status, '|', email_norm, '|', source) FROM ka_applications WHERE id = $T334_A")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp > $T334_E0")|$(( $(sq "SELECT COUNT(*) FROM ka_contacts") - T334_C0 ))" \
  "result=ok|1|Technician T334|Ivan Novak T334|777334334|new|ivan-t334@example.com|kolekce:${T334_IDK:-0}|0|0"
T334_FILE=$(find "$WORK/web/storage/applications" -name '*.bin' -newer "$WORK/t334-cv.pdf" | head -1)
expect "3.34: the CV is sealed in storage/applications (not readable as the original, no public address); the attachment list itself is sealed too" \
  "$([ -n "$T334_FILE" ] && echo stored)|$(grep -c 'secret-cv-t334' "$T334_FILE" 2>/dev/null || true)|$(sq "SELECT attachments LIKE '%CV Ivan%' OR attachments LIKE '[%' FROM ka_applications WHERE id = $T334_A")|$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/applications/")" \
  "stored|0|0|403"
expect "3.34: the event names the position and the form, never the sender; the notification e-mail names the position and the number only" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(message LIKE '%Ivan%')) FROM ka_events WHERE type = 'application.received'")|$(sq "SELECT CONCAT(SUM(predmet LIKE '%Technician T334%'), '|', SUM(telo LIKE '%Ivan%' OR telo LIKE '%ivan-t334%' OR predmet LIKE '%Ivan%'), '|', SUM(telo LIKE '%action=detail&id=$T334_A%')) FROM ka_posta WHERE idp > $T334_P0 AND komu <> 'ivan-t334@example.com'")" \
  "1|0|1|0|1"
# 3.34 B: the form sends no confirmation of its own, so the applicant gets the "received" template – logged without a name or the position
expect "3.34 B: the applicant gets the received confirmation; the mail log subject names nobody" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(predmet LIKE '%Ivan%' OR predmet LIKE '%Technician%'), '|', SUM(predmet LIKE 'Žádost přijata:%' OR predmet LIKE 'Application received:%')) FROM ka_posta WHERE idp > $T334_P0 AND komu = 'ivan-t334@example.com'")" "1|0|1"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user'); INSERT INTO ka_nastaveni SELECT * FROM ka_t334_mail; DROP TABLE ka_t334_mail; DELETE FROM ka_posta WHERE idp > $T334_P0" > /dev/null
# a general form with the Purpose "job application": a spontaneous application, not an enquiry
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -sL -o "$WORK/formular.html" "$B/kontakt"; T334_PAGE=$(field_value zdroj | sed 's/^stranka://')
t334_php '$b = Kaleta\Builder\Build::fromJson($app->db()->value("SELECT stavba FROM {stranky} WHERE ids = ?", ['"${T334_PAGE:-0}"'])); $f = function (?array &$nodes) use (&$f): void { if ($nodes === null) { return; } foreach ($nodes as &$n) { if (($n["typ"] ?? "") === "formular") { $n["obsah"]["ucel"] = "uchazec"; } if (isset($n["deti"])) { $f($n["deti"]); } } }; $f($b["deti"]); $app->db()->update("stranky", ["stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)], ["ids" => '"${T334_PAGE:-0}"']);' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -sL -o "$WORK/formular.html" "$B/kontakt"; FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
submit_form --data-urlencode "p0=Eva Spont T334" --data-urlencode p1=eva-t334@example.com -d p2=777334335 --data-urlencode "p3=Any opening for a designer?" -d p4=1 > /dev/null
expect "3.34: a form with the Purpose \"job application\" on an ordinary page – a spontaneous application (no position), not an enquiry" \
  "$(sq "SELECT CONCAT(IFNULL(job_item_id, 'none'), '|', job_title = '', '|', source) FROM ka_applications WHERE email = 'eva-t334@example.com'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email = 'eva-t334@example.com'")" "none|1|stranka:${T334_PAGE:-0}|0"
t334_php '$b = Kaleta\Builder\Build::fromJson($app->db()->value("SELECT stavba FROM {stranky} WHERE ids = ?", ['"${T334_PAGE:-0}"'])); $f = function (?array &$nodes) use (&$f): void { if ($nodes === null) { return; } foreach ($nodes as &$n) { unset($n["obsah"]["ucel"]); if (isset($n["deti"])) { $f($n["deti"]); } } }; $f($b["deti"]); $app->db()->update("stranky", ["stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)], ["ids" => '"${T334_PAGE:-0}"']);' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
T334_SPONT=$(sq "SELECT id FROM ka_applications WHERE email = 'eva-t334@example.com'")

# the section: sales sees nothing; HR opens the list, the detail and the CV
expect "3.34: Olga (Enquiries, Customers, Pipeline) gets 403 on the list, the detail and the CV, and no menu item" \
  "$(curl -s -b "$T334_JO" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications")|$(curl -s -b "$T334_JO" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=detail&id=$T334_A")|$(curl -s -b "$T334_JO" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=attachment&id=$T334_A&index=0")|$(curl -s -b "$T334_JO" "$B/admin.php?module=enquiries" | grep -c 'module=applications')" \
  "403|403|403|0"
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=applications"
expect "3.34: Hana sees the list – the open tab, both applications, the position; no notice to administrators" \
  "$(grep -c "data-application=\"$T334_A\"" "$WORK/response")|$(grep -c "data-application=\"${T334_SPONT:-0}\"" "$WORK/response")|$(grep -c 'Technician T334' "$WORK/response" | awk '{print ($1 > 0)}')|$(grep -c 'data-applications-nobody' "$WORK/response")" "1|1|1|0"
curl -s -b "$T334_JH" -o "$WORK/t334-download" -D "$WORK/t334-headers" "$B/admin.php?module=applications&action=attachment&id=$T334_A&index=0"
expect "3.34: the CV downloads intact, as an attachment with nosniff; the download is in the change log" \
  "$(cmp -s "$WORK/t334-download" "$WORK/t334-cv.pdf" && echo same)|$(grep -ci '^content-type: application/octet-stream' "$WORK/t334-headers")|$(grep -ci '^x-content-type-options: nosniff' "$WORK/t334-headers")|$(grep -ci 'content-disposition: attachment' "$WORK/t334-headers")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'attachment' AND popis LIKE '%#$T334_A'")" \
  "same|1|1|1|1"
# statuses, the responsible person, notes and tasks
expect "3.34: rejected with a reason – decided, in the history; reopened – undecided again" \
  "$(t334_post "$T334_JH" status -d "id=$T334_A" -d status=rejected -d reject_reason=withdrew)|$(sq "SELECT CONCAT(status, '|', reject_reason, '|', decided_at IS NOT NULL, '|', JSON_LENGTH(history)) FROM ka_applications WHERE id = $T334_A")|$(t334_post "$T334_JH" status -d "id=$T334_A" -d status=interview > /dev/null; sq "SELECT CONCAT(status, '|', reject_reason IS NULL, '|', decided_at IS NULL, '|', JSON_LENGTH(history)) FROM ka_applications WHERE id = $T334_A")" \
  "302|rejected|withdrew|1|1|interview|1|1|2"
t334_post "$T334_JH" assign -d "id=$T334_A" -d "assignee_id=$T334_OLGA" > /dev/null
T334_OLGA_SET=$(sq "SELECT IFNULL(assignee_id, 'none') FROM ka_applications WHERE id = $T334_A")
T334_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
t334_post "$T334_JH" assign -d "id=$T334_A" -d "assignee_id=$T334_ADMIN" > /dev/null
expect "3.34: a person without the section cannot be responsible; an administrator can and is notified (number only in the target)" \
  "$T334_OLGA_SET|$(sq "SELECT assignee_id = $T334_ADMIN FROM ka_applications WHERE id = $T334_A")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $T334_ADMIN AND kind = 'assigned' AND target = 'application:$T334_A'")" "none|1|1"
sq "UPDATE ka_applications SET last_activity_at = '$(site_time '-3 days')' WHERE id = $T334_A" > /dev/null
t334_post "$T334_JH" comment -d "id=$T334_A" --data-urlencode "text=Strong profile, call him @admin" > /dev/null
t334_post "$T334_JH" task -d "id=$T334_A" -d op=add --data-urlencode "title=Call Ivan on Thursday" -d "assignee_id=$T334_HANA" > /dev/null
expect "3.34: a note (with a mention) and a task on the application – target application:N, the mention notifies, both count as activity; never journalled" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(kind)) FROM ka_comments WHERE target = 'application:$T334_A'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'application:$T334_A'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'mention' AND target = 'application:$T334_A'")|$(sq "SELECT DATE(last_activity_at) = '$(site_date today)' FROM ka_applications WHERE id = $T334_A")|$(sq "SELECT COUNT(*) FROM ka_agent_journal WHERE tbl = 'applications'")" \
  "1|note|1|1|1|0"
curl -s -b "$T334_JH" -o "$WORK/response" "$B/admin.php?module=my_work"
expect "3.34: My work names the task's application by its number and position" "$(grep -c "Application #$T334_A · Technician T334" "$WORK/response" | awk '{print ($1 > 0)}')" "1"
# MCP: the comment tools follow the section (Olga has none) and the switch applications_claude
T334_TO="kaleta_$(printf '9%.0s' $(seq 1 48))"; sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($T334_OLGA, 't334-olga', 'full', 'work', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$T334_TO")', '$(site_time)')" > /dev/null
mcp_as "$T334_TO" list_comments "{\"target\":\"application:$T334_A\"}" > "$WORK/response"; mcp_text
expect "3.34 MCP list_comments: refused without the section Applications" "$(grep -c 'Only a user who may open Applications' "$WORK/text")" "1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_claude', '0')" > /dev/null
mcp list_comments "{\"target\":\"application:$T334_A\"}" > "$WORK/response"; mcp_text
expect "3.34 MCP list_comments: with applications_claude off even an administrator's connection is refused" "$(grep -c 'Claude may not read job applications' "$WORK/text")" "1"
sq "DELETE FROM ka_nastaveni WHERE promenna = 'applications_claude'" > /dev/null

# personal data: found, in the file, erased with the sealed CV
mcp find_personal_data '{"email":"ivan-t334@example.com"}' > "$WORK/response"; mcp_text
expect "3.34 find_personal_data: the applications are counted" "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["found"]["applications"] ?? "missing";' -- "$WORK/text")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=personal"
curl -s -b "$JAR" -o "$WORK/t334-export.json" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d email=ivan-t334@example.com -d provest=export
expect "3.34: the personal data file holds the application with its note and the CV by name – never the file" \
  "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $a = $d["applications"][0] ?? []; echo ($a["position"] ?? ""), "|", count($a["notes"] ?? []), "|", ($a["attachments"][0]["name"] ?? ""), "|", isset($a["attachments"][0]["key"]) || isset($a["attachments"][0]["path"]) ? "leak" : "ok";' -- "$WORK/t334-export.json")" \
  "Technician T334|1|CV Ivan.pdf|ok"
mcp erase_personal_data '{"email":"ivan-t334@example.com","confirm":true}' > /dev/null
expect "3.34 erase_personal_data: the application, its sealed CV, notes, tasks and notifications are gone" \
  "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $T334_A")|$([ -f "$T334_FILE" ] && echo file || echo none)|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'application:$T334_A'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'application:$T334_A'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'application:$T334_A'")" \
  "0|none|0|0|0"

# retention: decided past the months, idle past the months, kept by the consent; the event and the change log
sq "UPDATE ka_applications SET status = 'rejected', decided_at = '$(site_time '-8 months')', last_activity_at = '$(site_time '-8 months')' WHERE id = ${T334_SPONT:-0};
  INSERT INTO ka_applications (received_at, data, status, status_at, last_activity_at, email) VALUES ('$(site_time '-9 months')', '[]', 'new', '$(site_time '-9 months')', '$(site_time '-7 months')', 'idle-t334@example.com'),
    ('$(site_time '-9 months')', '[]', 'hired', '$(site_time '-9 months')', '$(site_time '-9 months')', 'pool-t334@example.com'), ('$(site_time '-1 month')', '[]', 'interview', '$(site_time)', '$(site_time '-1 month')', 'busy-t334@example.com');
  UPDATE ka_applications SET decided_at = '$(site_time '-9 months')', pool_consent_at = '$(site_time '-9 months')', pool_until = '$(site_date '+3 months')' WHERE email = 'pool-t334@example.com';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6')" > /dev/null
run_job cleanup > /dev/null
expect "3.34 retention: the decided and the idle one are deleted, the consented and the busy one stay; the event and the change log count them" \
  "$(sq "SELECT GROUP_CONCAT(email ORDER BY email) FROM ka_applications WHERE email LIKE '%-t334@example.com'")|$(sq "SELECT JSON_EXTRACT(data, '$.count') FROM ka_events WHERE type = 'applications.purged' ORDER BY id DESC LIMIT 1")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'purge'")" \
  "busy-t334@example.com,pool-t334@example.com|2|1"

# the move of the applications that were enquiries (migration 0120, run again by itself): certain ones only, once each
# one moment for both columns: two clock reads a second apart would make the record look edited (updated_at > created_at)
T334_NOW=$(site_time)
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('lone-t334@example.com', 'lone-t334@example.com', 'Lone', 'enquiry', '$T334_NOW', '$T334_NOW');
  INSERT INTO ka_poptavky (datum, formular, zdroj, prvek, stranka, tema, email, data, stav, poznamka, contact_id) VALUES
    ('$(site_time '-2 days')', 'Job application', 'kolekce:${T334_IDK:-0}', 'x', '/$T334_SLUG/technician-t334', 'Jobs T334 – Technician T334', 'lone-t334@example.com', '[[\"Full name\",\"Lone Applicant\"],[\"CV\",\"cv.pdf (1 kB)\",\"2026/11/aaaaaaaaaaaaaaaaaaaaaaaa.pdf\"]]', 2, 'Called once', LAST_INSERT_ID()),
    ('$(site_time '-2 days')', 'Kontakt', 'stranka:${T334_PAGE:-0}', 'x', '/kontakt', '', 'stay-t334@example.com', '[]', 0, NULL, NULL)" > /dev/null
T334_EQ=$(sq "SELECT idp FROM ka_poptavky WHERE email = 'lone-t334@example.com'")
sq "INSERT INTO ka_comments (target, kind, author_name, text, via, created_at) VALUES ('enquiry:$T334_EQ', 'note', 'Hana', 'Moved note', 'admin', '$(site_time)');
  INSERT INTO ka_tasks (title, target, assignee_id, created_at, updated_at) VALUES ('Moved task', 'enquiry:$T334_EQ', $T334_HANA, '$(site_time)', '$(site_time)')" > /dev/null
t334_move() { t334_php '$f = require "system/sql/migrace/0120-applications-move.php"; $f($app->db(), $app->settings());' > /dev/null; }
t334_move
T334_M=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE source_enquiry_id = ${T334_EQ:-0}")
expect "3.34 move: the job enquiry becomes an application (position, status, file listed unsealed, old note and the 'resolved' note), its note and task follow; the other enquiry stays; the lone customer goes" \
  "$(sq "SELECT CONCAT(job_item_id = ${T334_ITEM:-0}, '|', job_title, '|', status, '|', name) FROM ka_applications WHERE id = $T334_M")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'application:$T334_M'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'application:$T334_M'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email IN ('lone-t334@example.com', 'stay-t334@example.com')")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE email = 'lone-t334@example.com'")|$(t334_php 'echo json_encode(array_column(Kaleta\Core\Applications::attachments($app->settings(), $app->db()->value("SELECT attachments FROM {applications} WHERE id = '"$T334_M"'")), "sealed"));')" \
  "1|Technician T334|reviewed|Lone Applicant|3|1|1|0|[false]"
t334_move
expect "3.34 move: run again, nothing moves twice" "$(sq "SELECT COUNT(*) FROM ka_applications WHERE source_enquiry_id = ${T334_EQ:-0}")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'applications.moved'")" "1|1"
# the monthly report counts (counts and positions only); 3.40: from ka_application_counts – the interview of the erased application still counts
expect "3.34 monthly report: recruitment counts – no person" \
  "$(t334_php '$c = Kaleta\Core\Applications::monthCounts($app->db(), date("Y-m-01 00:00:00"), date("Y-m-d H:i:s", strtotime("first day of next month 00:00"))); echo $c === null ? "null" : ($c["interview"] . "|" . (str_contains(json_encode($c), "@") ? "leak" : "ok"));')" "1|ok"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$T334_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''; DELETE FROM ka_applications WHERE email LIKE '%-t334@example.com' OR source_enquiry_id = ${T334_EQ:-0}" > /dev/null
