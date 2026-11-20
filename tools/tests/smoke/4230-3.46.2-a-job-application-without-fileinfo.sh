echo "== 3.46.2: a job application with a CV on a server without fileinfo – stored, never a 500; a check that throws answers \"not stored\"; System status names sodium and fileinfo"
u4230_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
u4230_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/u4230-form.html" | head -1 | sed 's/.*value="//;s/"$//' || true; }
U4230_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_u4230_ext; CREATE TABLE ka_u4230_ext AS SELECT * FROM ka_nastaveni WHERE promenna = 'extensions'" > /dev/null
case ",$U4230_EXT," in *,applications,*) ;; *) sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$U4230_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$U4230_EXT"),applications')" > /dev/null ;; esac
# the jobs collection and its open job straight through PHP (after the restores of 4190–4202 the suite's MCP token is the backup's)
U4230_IDK=$(u4230_php '[$id] = Kaleta\Builder\Presets::createWithPage($app, "jobs", "Jobs U4230", false) ?? [0]; $app->db()->insert("kolekce_polozky", ["idk" => $id, "nazev" => "Welder U4230", "seo_link" => "welder-u4230", "data" => "{}", "zobrazit" => 1, "jazyk" => "", "datum" => date("Y-m-d H:i:s"), "poradi" => 0]); echo $id;')
U4230_SLUG=$(sq "SELECT seo_link FROM ka_kolekce WHERE idk = ${U4230_IDK:-0}"); U4230_ITEM=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = ${U4230_IDK:-0} AND seo_link = 'welder-u4230'")

# a second server on the same site that works as a host without the fileinfo extension (the test switch of Core\Files)
U4230_PORT=$((PORT + 24)); U4230_B="http://127.0.0.1:$U4230_PORT"
for i in $(seq 1 100); do curl -s -o /dev/null "$U4230_B/" || break; sleep 0.2; done # the port is free again (4201's fake S3 used it)
(cd "$WORK/web" && KALETA_NO_FILEINFO=1 exec php -S "127.0.0.1:$U4230_PORT" system/dev-router.php > "$WORK/server-u4230.log" 2>&1) & U4230_PID=$!
for i in $(seq 1 100); do curl -s -o /dev/null "$U4230_B/" && break; sleep 0.2; done
expect "3.46.2: the test server really works without fileinfo (the switch answers on a test copy only)" \
  "$(cd "$WORK/web" && KALETA_NO_FILEINFO=1 php -r 'require "system/bootstrap.php"; require "config.php"; echo Kaleta\Core\Files::hasFileinfo() ? "finfo" : "sniffer";')|$(cd "$WORK/web" && KALETA_NO_FILEINFO=1 php -r 'require "system/bootstrap.php"; echo Kaleta\Core\Files::hasFileinfo() ? "finfo" : "sniffer";')" \
  "sniffer|finfo"

rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/u4230-form.html" "$U4230_B/$U4230_SLUG/welder-u4230" || true
U4230_SRC=$(u4230_field zdroj); U4230_EL=$(u4230_field prvek); U4230_TIME=$(u4230_field as_cas); U4230_SIG=$(u4230_field as_podpis)
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n%% CV of Jana U4230\n' > "$WORK/u4230-cv.pdf"
printf 'MZ\220\000\003\000\000\000This program cannot be run in DOS mode' > "$WORK/u4230-setup.pdf"
u4230_submit() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$U4230_B/form" -F "zdroj=$U4230_SRC" -F "prvek=$U4230_EL" -F "zpet=/$U4230_SLUG/welder-u4230" -F "as_cas=$U4230_TIME" -F "as_podpis=$U4230_SIG" \
  -F "p0=Jana Kovar U4230" -F "p1=jana-u4230@example.com" -F "p2=777423000" -F "p4=I weld" -F "p5=1" -F "p6=Welder U4230" "$@"; }
sleep 4
sq "DROP TABLE IF EXISTS ka_u4230_mail; CREATE TABLE ka_u4230_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '1'), ('smtp_encryption', 'zadne'), ('smtp_user', ''); DELETE FROM ka_kontrola_ip" > /dev/null
U4230_A0=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications"); U4230_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
U4230_BAD=$(u4230_submit -F "p3=@$WORK/u4230-setup.pdf;filename=cv.pdf")
expect "3.46.2: without fileinfo a program named .pdf is refused at its field – 303, nothing stored" \
  "$(echo "$U4230_BAD" | cut -d' ' -f1)|$(echo "$U4230_BAD" | grep -o 'result=[a-z]*&field=3')|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id > $U4230_A0")" "303|result=pole&field=3|0"
U4230_OK=$(u4230_submit -F "p3=@$WORK/u4230-cv.pdf;filename=CV Jana.pdf")
U4230_A=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE id > $U4230_A0")
expect "3.46.2: without fileinfo the application with a PDF CV is stored with its sealed CV – 303 to the thanks, no 500 in the server log" \
  "$(echo "$U4230_OK" | cut -d' ' -f1)|$(echo "$U4230_OK" | grep -o 'result=ok')|$(sq "SELECT CONCAT(job_item_id = ${U4230_ITEM:-0}, '|', name, '|', attachment_paths LIKE '%.bin%') FROM ka_applications WHERE id = ${U4230_A:-0}")|$(grep -c 'finfo\|PHP Fatal\|\[500\]' "$WORK/server-u4230.log" || true)" \
  "303|result=ok|1|Jana Kovar U4230|1|0"
kill "$U4230_PID" 2> /dev/null || true; wait "$U4230_PID" 2> /dev/null || true

# a check that throws (whatever the reason): one error event and one log line naming the form, never the sender; the visitor
# gets the result "ulozeni" (Front\Forms::process catches it around Applications::checkUpload)
U4230_LOG=$(grep -c 'The file check of form u4230form failed' "$WORK/web/storage/log/chyby.log" 2> /dev/null || true)
u4230_php 'Kaleta\Front\Forms::fileCheckFailed($app, new Error("Class \"finfo\" not found"), "u4230form");' > /dev/null
expect "3.46.2: a file check that throws is recorded – an error event with the form and the error class, one error log line" \
  "$(sq "SELECT CONCAT(severity, '|', JSON_UNQUOTE(JSON_EXTRACT(data, '$.form')), '|', JSON_UNQUOTE(JSON_EXTRACT(data, '$.error')), '|', message LIKE '%Jana%') FROM ka_events WHERE type = 'form.file_check_failed' ORDER BY id DESC LIMIT 1")|$(( $(grep -c 'The file check of form u4230form failed' "$WORK/web/storage/log/chyby.log") - ${U4230_LOG:-0} ))" \
  "error|u4230form|Error|0|1"
sed -i.bak '/The file check of form u4230form failed/d' "$WORK/web/storage/log/chyby.log"; rm -f "$WORK/web/storage/log/chyby.log.bak"

# System status names both extensions (sodium required, fileinfo recommended)
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.46.2: System status has a row for the sodium and the fileinfo extension" "$(grep -c 'sodium' "$WORK/response" | sed 's/^[1-9][0-9]*$/yes/')|$(grep -c 'fileinfo' "$WORK/response" | sed 's/^[1-9][0-9]*$/yes/')" "yes|yes"

# clean-up: the application, its sealed CV, the job collection, the mail settings and the feature as they were
[ "${U4230_A:-0}" -gt 0 ] && u4230_php 'Kaleta\Core\Applications::delete($app, ['"$U4230_A"'], false);' > /dev/null 2>&1 || true
sq "DELETE FROM ka_applications WHERE id > $U4230_A0; DELETE FROM ka_kolekce_polozky WHERE idk = ${U4230_IDK:-0}; DELETE FROM ka_kolekce WHERE idk = ${U4230_IDK:-0};
  DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user'); INSERT INTO ka_nastaveni SELECT * FROM ka_u4230_mail; DROP TABLE ka_u4230_mail;
  DELETE FROM ka_posta WHERE idp > $U4230_P0; DELETE FROM ka_kontrola_ip; DELETE FROM ka_nastaveni WHERE promenna = 'extensions'; INSERT INTO ka_nastaveni SELECT * FROM ka_u4230_ext; DROP TABLE ka_u4230_ext" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/u4230-cv.pdf" "$WORK/u4230-setup.pdf" "$WORK/u4230-form.html"
