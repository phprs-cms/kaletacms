echo "== 3.46 C: private files sealed at rest – whistleblowing attachments (N162), internal files (N165), request attachments, migration 0134, the encrypted off-site copy"
c346_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
c346_csrf() { curl -s -b "$JAR" -c "$JAR" "$B/admin.php?${1:-module=internal_files}" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
C346_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
C346_YEAR=$(date +%Y)
# the settings this section changes go back as they were at its end
C346_KEYS="'extensions', 'whistleblowing_enabled', 'whistleblowing_readers', 'remote_backup', 'backup_host', 'backup_user', 'backup_password', 'backup_folder', 'backup_region', 'backup_test_url', 'backup_media', 'remote_private_status', 'private_sync_check', 'data_migrations', 'data_migrations_unfinished'"
sq "DROP TABLE IF EXISTS ka_c346_backup; CREATE TABLE ka_c346_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($C346_KEYS)" > /dev/null
C346_EXT=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')")
C346_ON=$(php -r '$e = array_filter(explode(",", $argv[1] !== "" && $argv[1] !== "-" ? $argv[1] : "novinky,poptavky,statistika,presmerovani,claude")); array_push($e, "whistleblowing", "internal_files"); echo implode(",", array_unique($e));' -- "$C346_EXT")
features "$C346_ON"; sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('whistleblowing_enabled', '1'), ('whistleblowing_readers', '$C346_ADMIN'); DELETE FROM ka_kontrola_ip WHERE typ IN ('oznameni', 'oznameni-den')" > /dev/null
printf '%%PDF-1.4\n%% C346 evidence – the warehouse records\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$WORK/c346-evidence.pdf"

# --- N162: a new report's attachment is sealed on the disk; the reader downloads the original
C346_TIME=$(( $(date +%s) - 10 )); C346_SIG=$(c346_php 'echo hash_hmac("sha256", "oznameni|" . $a[0], (new Kaleta\Core\Antispam($db, $s))->key());' "$C346_TIME")
{ ls "$WORK/web/storage/oznameni/$C346_YEAR" 2>/dev/null || true; } | sort > "$WORK/c346-before" # empty when no report came before (ONLY=4160)
C346_HTTP=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/_report" -F "as_cas=$C346_TIME" -F "as_podpis=$C346_SIG" --form-string "text=C346 report with evidence" -F name= -F contact= -F "files[]=@$WORK/c346-evidence.pdf")
C346_NUMBER=$(grep -o 'ka-oznameni-cislo">[0-9-]*' "$WORK/response" | sed 's/.*>//' || true)
C346_CASE=$(sq "SELECT COALESCE((SELECT id FROM ka_whistleblowing_cases WHERE number = '$C346_NUMBER'), 0)")
C346_NEW=$({ ls "$WORK/web/storage/oznameni/$C346_YEAR" 2>/dev/null || true; } | sort | comm -13 "$WORK/c346-before" - | head -1)
expect "3.46 N162: a report's attachment is stored as a random .bin, sealed (KCV1) – neither the PDF nor its text is on the disk" \
  "$C346_HTTP|$(printf '%s' "$C346_NEW" | grep -cE '^[a-f0-9]{32}\.bin$')|$(head -c 4 "$WORK/web/storage/oznameni/$C346_YEAR/$C346_NEW" 2>/dev/null)|$(grep -c 'C346 evidence' "$WORK/web/storage/oznameni/$C346_YEAR/$C346_NEW" 2>/dev/null || true)" "200|1|KCV1|0"
expect "3.46 N162: the reader downloads the original attachment, opened in memory" \
  "$(curl -s -b "$JAR" -o "$WORK/c346-down.pdf" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=attachment&id=$C346_CASE&index=0")|$(cmp -s "$WORK/c346-down.pdf" "$WORK/c346-evidence.pdf" && echo same)" "200|same"
expect "3.46 N162: the folder is never served by the web server" "$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/oznameni/$C346_YEAR/$C346_NEW")" "403"

# --- N165: an internal file is sealed on the disk; size and sha256 describe the content; the download is the original
TOKEN=$(c346_csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=C346 Contracts" -d audience=all
C346_FOLDER=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'C346 Contracts'")
printf 'C346 contract – price 12 345 CZK, confidential\n' > "$WORK/c346-contract.txt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$TOKEN" -F "folder_id=$C346_FOLDER" -F "file=@$WORK/c346-contract.txt;type=text/plain"
C346_FILE=$(sq "SELECT COALESCE((SELECT id FROM ka_internal_files WHERE folder_id = '$C346_FOLDER' LIMIT 1), 0)")
C346_PATH=$(sq "SELECT path FROM ka_internal_file_versions WHERE file_id = $C346_FILE AND version = 1")
expect "3.46 N165: an internal file is sealed on the disk (KCV1, no text), its own key is stored sealed, size and sha256 are the content's" \
  "$(head -c 4 "$WORK/web/storage/internal-files/$C346_PATH" 2>/dev/null)|$(grep -c 'C346 contract' "$WORK/web/storage/internal-files/$C346_PATH" 2>/dev/null || true)|$(sq "SELECT CONCAT(file_key IS NOT NULL AND LENGTH(file_key) > 60, '|', size, '|', sha256) FROM ka_internal_file_versions WHERE file_id = $C346_FILE AND version = 1")" \
  "KCV1|0|1|$(wc -c < "$WORK/c346-contract.txt" | tr -d ' ')|$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$WORK/c346-contract.txt")"
expect "3.46 N165: the person with the folder downloads the original; the text opens for MCP (versionContent); the web server never serves it" \
  "$(curl -s -b "$JAR" -o "$WORK/c346-down.txt" -w '%{http_code}' "$B/admin.php?module=internal_files&action=download&id=$C346_FILE")|$(cmp -s "$WORK/c346-down.txt" "$WORK/c346-contract.txt" && echo same)|$(c346_php 'echo Kaleta\Core\InternalFiles::versionContent($s, $db->one("SELECT * FROM {internal_file_versions} WHERE file_id = ? AND version = 1", [(int) $a[0]]) ?? []) === file_get_contents($a[1]) ? "text" : "no";' "$C346_FILE" "$WORK/c346-contract.txt" 2>/dev/null)|$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/internal-files/$C346_PATH")" "200|same|text|403"

# --- migration 0134: files from before 3.46 (a plain whistleblowing attachment, a plain internal file version) are sealed in
# place – first a run killed after the files were sealed and before the rows were updated, then the next run, then one more
C346_OLD_WB="$C346_YEAR/$(php -r 'echo bin2hex(random_bytes(16));').pdf"; cp "$WORK/c346-evidence.pdf" "$WORK/web/storage/oznameni/$C346_OLD_WB"
C346_OLD_IF="$(date +%Y/%m)/$(php -r 'echo bin2hex(random_bytes(16));').bin"; mkdir -p "$(dirname "$WORK/web/storage/internal-files/$C346_OLD_IF")"; cp "$WORK/c346-contract.txt" "$WORK/web/storage/internal-files/$C346_OLD_IF"
C346_OLD_CASE=$(c346_php '$db->insert("whistleblowing_cases", ["number" => "1998-0346", "created_at" => Kaleta\Core\Clock::now(), "status" => "received", "feedback_due" => Kaleta\Core\Clock::now(), "text" => Kaleta\Core\Whistleblowing::encrypt($s, "Old report"),
  "attachments" => Kaleta\Core\Whistleblowing::encrypt($s, json_encode([["name" => "evidence.pdf", "path" => $a[0], "size" => filesize(Kaleta\Core\Whistleblowing::FOLDER . "/" . $a[0])]])), "code_hash" => str_repeat("c", 64)]); echo $db->value("SELECT id FROM {whistleblowing_cases} WHERE number = ?", ["1998-0346"]);' "$C346_OLD_WB")
sq "INSERT INTO ka_internal_files (folder_id, name, description, tags, status, extension, size, version, created_at, updated_at) VALUES ($C346_FOLDER, 'old-contract.txt', '', '', 'ready', 'txt', $(wc -c < "$WORK/c346-contract.txt" | tr -d ' '), 1, '$(site_time)', '$(site_time)');
  INSERT INTO ka_internal_file_versions (file_id, version, path, original_name, extension, size, sha256, file_key, uploaded_at) VALUES (LAST_INSERT_ID(), 1, '$C346_OLD_IF', 'old-contract.txt', 'txt', $(wc -c < "$WORK/c346-contract.txt" | tr -d ' '), '$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$WORK/c346-contract.txt")', NULL, '$(site_time)')" > /dev/null
C346_OLD_FILE=$(sq "SELECT id FROM ka_internal_files WHERE name = 'old-contract.txt' AND folder_id = $C346_FOLDER")
c346_migrate() { (cd "$WORK/web" && php -r "$1"' require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); Kaleta\Core\Migration::apply($app->db(), $app->settings());'); }
c346_state() { echo "$(head -c 4 "$WORK/web/storage/oznameni/$C346_OLD_WB")/$(head -c 4 "$WORK/web/storage/internal-files/$C346_OLD_IF")|$(c346_php 'echo json_encode(array_map(fn ($x) => !empty($x["sealed"]), Kaleta\Core\Whistleblowing::contents($s, $db->one("SELECT * FROM {whistleblowing_cases} WHERE id = ?", [(int) $a[0]]))["attachments"]));' "$C346_OLD_CASE")|$(sq "SELECT file_key IS NOT NULL FROM ka_internal_file_versions WHERE file_id = $C346_OLD_FILE")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'")"; }
c346_downloads() { echo "$(curl -s -b "$JAR" -o "$WORK/c346-d1" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=attachment&id=$C346_OLD_CASE&index=0")$(cmp -s "$WORK/c346-d1" "$WORK/c346-evidence.pdf" && echo =)/$(curl -s -b "$JAR" -o "$WORK/c346-d2" -w '%{http_code}' "$B/admin.php?module=internal_files&action=download&id=$C346_OLD_FILE")$(cmp -s "$WORK/c346-d2" "$WORK/c346-contract.txt" && echo =)"; }
expect "3.46 0134: before the migration the old files are plain and download as they are" "$(c346_state)|$(c346_downloads)" "%PDF/C346|[false]|0||200=/200="
sq "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0134-seal-private-files,', ',')) WHERE promenna = 'data_migrations'" > /dev/null
c346_migrate 'define("KALETA_MIGRATION_STOP_AFTER", 2);'
expect "3.46 0134 interrupted: killed after sealing, before the rows – the files are sealed, the rows still call them plain, the migration is unfinished, and the downloads still work (the header key)" \
  "$(c346_state)|$(c346_downloads)" "KCV1/KCV1|[false]|0|0134-seal-private-files|200=/200="
C346_SUM1=$(cat "$WORK/web/storage/oznameni/$C346_OLD_WB" "$WORK/web/storage/internal-files/$C346_OLD_IF" | php -r 'echo hash("sha256", stream_get_contents(STDIN));')
c346_migrate ''
C346_SUM2=$(cat "$WORK/web/storage/oznameni/$C346_OLD_WB" "$WORK/web/storage/internal-files/$C346_OLD_IF" | php -r 'echo hash("sha256", stream_get_contents(STDIN));')
expect "3.46 0134 resumed: the next request finishes – the rows take the keys from the headers, the files are not sealed a second time, the migration is done" \
  "$(c346_state)|$(c346_downloads)|$([ "$C346_SUM1" = "$C346_SUM2" ] && echo unchanged)" "KCV1/KCV1|[true]|1||200=/200=|unchanged"
sq "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0134-seal-private-files,', ',')) WHERE promenna = 'data_migrations'" > /dev/null
c346_migrate ''
expect "3.46 0134 run again: nothing changes, nothing is lost, nothing is left beside the files" \
  "$(c346_state)|$(c346_downloads)|$(cat "$WORK/web/storage/oznameni/$C346_OLD_WB" "$WORK/web/storage/internal-files/$C346_OLD_IF" | php -r 'echo hash("sha256", stream_get_contents(STDIN));' | grep -c "$C346_SUM1")|$(find "$WORK/web/storage" -name '*.sealing' | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'files.sealed'")" \
  "KCV1/KCV1|[true]|1||200=/200=|1|0|1"

# --- owner decision 5: a request's attachment is private and sealed; a colleague with the section downloads it; the web never serves it
TOKEN=$(c346_csrf "module=requests&action=new")
C346_REQ_URL=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=requests&action=save" -F "_csrf=$TOKEN" -F "title=C346 new team photo" -F "text=Please add the attached contract summary." -F "about=" -F "about_url=" -F "prilohy[]=@$WORK/c346-contract.txt;type=text/plain")
C346_REQ=$(printf %s "$C346_REQ_URL" | grep -o 'id=[0-9]*' | sed 's/id=//' || true); C346_REQ="${C346_REQ:-0}"
C346_REQ_PATH=$(c346_php 'echo Kaleta\Core\Requests::get($app, (int) $a[0])["files"][0]["path"] ?? "";' "$C346_REQ")
expect "3.46 decision 5: the request's attachment is in storage/requests, sealed – never in Media; the detail lists it; the download is the original; the web server refuses the file" \
  "$(head -c 4 "$WORK/web/storage/requests/$C346_REQ_PATH" 2>/dev/null)|$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE '%c346-contract%'")|$(curl -s -b "$JAR" "$B/admin.php?module=requests&action=detail&id=$C346_REQ" | grep -c 'action=file&amp;id='"$C346_REQ")|$(curl -s -b "$JAR" -o "$WORK/c346-d3" -w '%{http_code}' "$B/admin.php?module=requests&action=file&id=$C346_REQ&index=0")$(cmp -s "$WORK/c346-d3" "$WORK/c346-contract.txt" && echo =)|$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/requests/$C346_REQ_PATH")" \
  "KCV1|0|1|200=|403"

# --- the off-site copy: the private folders go to private/, every file encrypted under the recovery key (fake S3)
C346_S3_PORT=$((PORT + 22)); mkdir -p "$WORK/s3-c346"
cat > "$WORK/s3-c346/router.php" <<'PHP'
<?php
$body = file_get_contents('php://input');
file_put_contents(__DIR__ . '/puts.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
if (preg_match('#/private/(.+)$#', $_SERVER['REQUEST_URI'], $m)) { @mkdir(__DIR__ . '/files', 0777, true); file_put_contents(__DIR__ . '/files/' . md5($m[1]), $body); }
http_response_code(200); return true;
PHP
(cd "$WORK/s3-c346" && exec php -S "127.0.0.1:$C346_S3_PORT" router.php > /dev/null 2>&1) & C346_S3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$C346_S3_PORT/ping" && break; sleep 0.2; done; : > "$WORK/s3-c346/puts.log"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDTEST'), ('backup_folder', 'c346-zalohy'), ('backup_region', 'eu-central-1'),
  ('backup_test_url', 'http://127.0.0.1:$C346_S3_PORT'), ('backup_media', '0'), ('remote_private_status', ''), ('private_sync_check', '0')" > /dev/null
c346_php '$s->set("backup_password", "tajne-s3");' > /dev/null
rm -f "$WORK/web/storage/zalohy/private-kopie.json"
mv "$WORK/web/storage/backup-key.php" "$WORK/c346-key.saved" 2>/dev/null || true
C346_KEY_CONFIRMED=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'backup_key_confirmed'), '')")
c346_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 20);' > /dev/null
expect "3.46 N165: without the recovery key nothing private leaves the server – the copy waits (paused)" \
  "$(grep -c '/private/' "$WORK/s3-c346/puts.log")|$(sq "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(hodnota, '|', 2), '|', -1) FROM ka_nastaveni WHERE promenna = 'remote_private_status'")" "0|paused"
c346_php 'Kaleta\Core\RecoveryKey::confirm($s, Kaleta\Core\RecoveryKey::ensure());' > /dev/null
c346_php 'echo Kaleta\Core\RemoteBackup::syncPrivate($s, 60) ?? "";' > "$WORK/c346-sync"
C346_PRIVATE=$(c346_php 'echo count(Kaleta\Core\RemoteBackup::privateFiles());')
expect "3.46 N165: with the recovery key every private file goes to private/<path>.enc – the encrypted format, none of the content in the clear; the status says complete" \
  "$(cat "$WORK/c346-sync")|$(grep -c '^PUT /c346-zalohy/private/storage/.*\.enc$' "$WORK/s3-c346/puts.log")|$(grep -c "/private/storage/requests/$C346_REQ_PATH.enc" "$WORK/s3-c346/puts.log")|$(grep -c "/private/storage/oznameni/$C346_OLD_WB.enc" "$WORK/s3-c346/puts.log")|$(head -c 8 "$WORK/s3-c346/files/$(php -r 'echo md5($argv[1]);' -- "storage/internal-files/$C346_OLD_IF.enc")" | od -An -tx1 | tr -d ' \n')|$(grep -rl 'C346' "$WORK/s3-c346/files" | wc -l | tr -d ' ')|$(sq "SELECT SUBSTRING_INDEX(hodnota, '|', -2) FROM ka_nastaveni WHERE promenna = 'remote_private_status'")|$(ls -a "$WORK/web/storage/zalohy" | grep -c '^\.upload-')" \
  "|$C346_PRIVATE|1|1|894b424b0d0a1a0a|0|ok|0|0"
: > "$WORK/s3-c346/puts.log"; c346_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
expect "3.46 N165: the next run uploads nothing unchanged" "$(grep -c '/private/' "$WORK/s3-c346/puts.log")" "0"
check "3.46 N165: Backups say the private files go encrypted and the copy is complete" 200 "/admin.php?module=settings&tab=backups" "Soukromé soubory: zašifrovaná kopie je úplná"
kill "$C346_S3_PID" 2>/dev/null || true

# --- back as it was: the recovery key, the settings, the rows and the files of this section
rm -f "$WORK/web/storage/backup-key.php" "$WORK/web/storage/zalohy/private-kopie.json"; [ -f "$WORK/c346-key.saved" ] && mv "$WORK/c346-key.saved" "$WORK/web/storage/backup-key.php"
sq "UPDATE ka_nastaveni SET hodnota = '$C346_KEY_CONFIRMED' WHERE promenna = 'backup_key_confirmed'" > /dev/null
rm -f "$WORK/web/storage/oznameni/$C346_OLD_WB" "$WORK/web/storage/internal-files/$C346_OLD_IF" "$WORK/web/storage/oznameni/$C346_YEAR/$C346_NEW" "$WORK/web/storage/internal-files/$C346_PATH" "$WORK/web/storage/requests/$C346_REQ_PATH"
sq "DELETE FROM ka_whistleblowing_cases WHERE id IN ($C346_CASE, $C346_OLD_CASE); DELETE FROM ka_internal_file_versions WHERE file_id IN (SELECT id FROM ka_internal_files WHERE folder_id = $C346_FOLDER);
  DELETE FROM ka_internal_files WHERE folder_id = $C346_FOLDER; DELETE FROM ka_internal_folders WHERE id = $C346_FOLDER; DELETE FROM ka_requests WHERE id = $C346_REQ; DELETE FROM ka_kontrola_ip WHERE typ IN ('oznameni', 'oznameni-den');
  DELETE FROM ka_nastaveni WHERE promenna IN ($C346_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_c346_backup; DROP TABLE ka_c346_backup" > /dev/null
