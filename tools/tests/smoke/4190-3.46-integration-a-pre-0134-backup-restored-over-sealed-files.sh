echo "== 3.46 integration: a backup from before 0134 restored after the files were sealed – the rows say plain, the files are sealed, everything opens"
i346_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
i346_csrf() { curl -s -b "$JAR" -c "$JAR" "$B/admin.php?${1:-module=internal_files}" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
I346_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
I346_KEYS="'extensions', 'whistleblowing_enabled', 'whistleblowing_readers'"
sq "DROP TABLE IF EXISTS ka_i346_backup; CREATE TABLE ka_i346_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($I346_KEYS)" > /dev/null
I346_EXT=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')")
I346_ON=$(php -r '$e = array_filter(explode(",", $argv[1] !== "" && $argv[1] !== "-" ? $argv[1] : "novinky,poptavky,statistika,presmerovani,claude")); array_push($e, "whistleblowing", "internal_files"); echo implode(",", array_unique($e));' -- "$I346_EXT")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$I346_ON'), ('whistleblowing_enabled', '1'), ('whistleblowing_readers', '$I346_ADMIN')" > /dev/null
printf '%%PDF-1.4\n%% I346 evidence\ntrailer << >>\n%%%%EOF\n' > "$WORK/i346-evidence.pdf"
printf 'I346 contract – kept private across a restore\n' > "$WORK/i346-contract.txt"

# --- the state of a 3.45 site: a plain whistleblowing attachment and a plain internal file, rows without keys
TOKEN=$(i346_csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=I346 Folder" -d audience=all
I346_FOLDER=$(sq "SELECT COALESCE((SELECT id FROM ka_internal_folders WHERE name = 'I346 Folder'), 0)")
I346_WB="$(date +%Y)/$(php -r 'echo bin2hex(random_bytes(16));').pdf"; mkdir -p "$(dirname "$WORK/web/storage/oznameni/$I346_WB")"; cp "$WORK/i346-evidence.pdf" "$WORK/web/storage/oznameni/$I346_WB"
I346_IF="$(date +%Y/%m)/$(php -r 'echo bin2hex(random_bytes(16));').bin"; mkdir -p "$(dirname "$WORK/web/storage/internal-files/$I346_IF")"; cp "$WORK/i346-contract.txt" "$WORK/web/storage/internal-files/$I346_IF"
I346_PLAIN_LIST=$(php -r 'echo json_encode([["name" => "evidence.pdf", "path" => $argv[1], "size" => filesize($argv[2])]]);' -- "$I346_WB" "$WORK/i346-evidence.pdf")
I346_CASE=$(i346_php '$db->insert("whistleblowing_cases", ["number" => "1997-0346", "created_at" => Kaleta\Core\Clock::now(), "status" => "received", "feedback_due" => Kaleta\Core\Clock::now(), "text" => Kaleta\Core\Whistleblowing::encrypt($s, "I346 report"),
  "attachments" => Kaleta\Core\Whistleblowing::encrypt($s, $a[0]), "code_hash" => str_repeat("d", 64)]); echo $db->value("SELECT id FROM {whistleblowing_cases} WHERE number = ?", ["1997-0346"]);' "$I346_PLAIN_LIST")
I346_SIZE=$(wc -c < "$WORK/i346-contract.txt" | tr -d ' '); I346_SHA=$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$WORK/i346-contract.txt")
sq "INSERT INTO ka_internal_files (folder_id, name, description, tags, status, extension, size, version, created_at, updated_at) VALUES ($I346_FOLDER, 'i346-contract.txt', '', '', 'ready', 'txt', $I346_SIZE, 1, '$(site_time)', '$(site_time)');
  INSERT INTO ka_internal_file_versions (file_id, version, path, original_name, extension, size, sha256, file_key, uploaded_at) VALUES (LAST_INSERT_ID(), 1, '$I346_IF', 'i346-contract.txt', 'txt', $I346_SIZE, '$I346_SHA', NULL, '$(site_time)')" > /dev/null
I346_FILE=$(sq "SELECT id FROM ka_internal_files WHERE name = 'i346-contract.txt' AND folder_id = $I346_FOLDER")
i346_heads() { echo "$(head -c 4 "$WORK/web/storage/oznameni/$I346_WB")/$(head -c 4 "$WORK/web/storage/internal-files/$I346_IF")"; }
i346_sum() { cat "$WORK/web/storage/oznameni/$I346_WB" "$WORK/web/storage/internal-files/$I346_IF" | php -r 'echo hash("sha256", stream_get_contents(STDIN));'; }
i346_rows() { echo "$(i346_php 'echo json_encode(array_map(fn ($x) => !empty($x["sealed"]), Kaleta\Core\Whistleblowing::contents($s, $db->one("SELECT * FROM {whistleblowing_cases} WHERE id = ?", [(int) $a[0]]))["attachments"]));' "$I346_CASE")/$(sq "SELECT file_key IS NOT NULL FROM ka_internal_file_versions WHERE file_id = $I346_FILE")"; }
i346_downloads() { echo "$(curl -s -b "$JAR" -o "$WORK/i346-d1" -w '%{http_code}' "$B/admin.php?module=whistleblowing&action=attachment&id=$I346_CASE&index=0")$(cmp -s "$WORK/i346-d1" "$WORK/i346-evidence.pdf" && echo =)/$(curl -s -b "$JAR" -o "$WORK/i346-d2" -w '%{http_code}' "$B/admin.php?module=internal_files&action=download&id=$I346_FILE")$(cmp -s "$WORK/i346-d2" "$WORK/i346-contract.txt" && echo =)"; }

# --- the backup of that site: the schema of before 0134 (no file_key, no ka_requests.files), db_version 133, 0134 not run;
# made from the command line, so no request of the site runs on the older schema meanwhile
I346_DBV=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")
sq "ALTER TABLE ka_internal_file_versions DROP COLUMN file_key; ALTER TABLE ka_requests DROP COLUMN files; UPDATE ka_nastaveni SET hodnota = '133' WHERE promenna = 'db_version';
  UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0134-seal-private-files,', ',')) WHERE promenna = 'data_migrations'" > /dev/null
I346_OLD=$(i346_php 'echo Kaleta\Core\Backup::create($db, "i346", false);')
# --- the update to 3.46: 0134 adds the columns and seals both files in place
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); Kaleta\Core\Migration::apply($app->db(), $app->settings());')
expect "3.46 integration: the backup has the schema of before 0134 and no ledger; after 0134 the files are sealed and the rows hold their keys" \
  "$(gzip -dc "$WORK/web/storage/zalohy/$I346_OLD" | grep -c 'file_key' || true)|$(gzip -dc "$WORK/web/storage/zalohy/$I346_OLD" | grep -c 'restore_ledger' || true)|$(gzip -dc "$WORK/web/storage/zalohy/$I346_OLD" | grep -c "'db_version','133'" || true)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")|$(i346_heads)|$(i346_rows)|$(i346_downloads)" \
  "0|0|1|$I346_DBV|KCV1/KCV1|[true]/1|200=/200="
I346_SEALED=$(i346_sum)
sq "INSERT IGNORE INTO ka_restore_ledger (kind, hash, created_at) VALUES ('erased', '$(php -r 'echo hash("sha256", "i346-ledger");')', '$(site_time)')" > /dev/null
I346_LEDGER=$(sq "SELECT COUNT(*) FROM ka_restore_ledger")

# --- the restore of the backup from before 0134 through Backups (B's proof: the suite confirmed before this section)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$(csrf)" -d "soubor=$I346_OLD"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
expect "3.46 integration: the restore runs 0133 and 0134 again – db_version is this code's, both columns are back, the ledger is untouched" \
  "$(grep -c 'Databáze byla obnovena ze zálohy' "$WORK/response" || true)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")|$(sq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'ka_internal_file_versions' AND COLUMN_NAME = 'file_key') OR (TABLE_NAME = 'ka_requests' AND COLUMN_NAME = 'files'))")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger")" \
  "1|$I346_DBV|2|$I346_LEDGER"
expect "3.46 integration: the files on the disk stay sealed and are never sealed a second time; both download as the originals" \
  "$(i346_heads)|$([ "$(i346_sum)" = "$I346_SEALED" ] && echo unchanged)|$(i346_downloads)|$(find "$WORK/web/storage" -name '*.sealing' | wc -l | tr -d ' ')" "KCV1/KCV1|unchanged|200=/200=|0"
# the rows of the backup say plain while the files are sealed (as right after the restore, before 0134 takes the keys): the header key opens them
i346_php '$db->update("whistleblowing_cases", ["attachments" => Kaleta\Core\Whistleblowing::encrypt($s, $a[1])], ["id" => (int) $a[0]]);' "$I346_CASE" "$I346_PLAIN_LIST"
sq "UPDATE ka_internal_file_versions SET file_key = NULL WHERE file_id = $I346_FILE" > /dev/null
expect "3.46 integration: a sealed file whose row says plain opens through its header key – the original, never the ciphertext" \
  "$(i346_rows)|$(i346_downloads)|$(i346_heads)" "[false]/0|200=/200=|KCV1/KCV1"
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); Kaleta\Core\InternalFiles::sealPending($app->settings(), microtime(true) + 10.0); Kaleta\Core\Whistleblowing::sealPending($app->settings(), microtime(true) + 10.0);')
expect "3.46 integration: the daily jobs take the keys back from the headers, the files unchanged" \
  "$(i346_rows)|$([ "$(i346_sum)" = "$I346_SEALED" ] && echo unchanged)|$(i346_downloads)" "[true]/1|unchanged|200=/200="

# clean up – the restore put back the settings of the start of this section with the rows above; they go as they came
rm -f "$WORK/web/storage/oznameni/$I346_WB" "$WORK/web/storage/internal-files/$I346_IF" "$WORK/web/storage/zalohy/$I346_OLD"
sq "DELETE FROM ka_whistleblowing_cases WHERE id = $I346_CASE; DELETE FROM ka_internal_file_versions WHERE file_id = $I346_FILE; DELETE FROM ka_internal_files WHERE folder_id = $I346_FOLDER;
  DELETE FROM ka_internal_folders WHERE id = $I346_FOLDER; DELETE FROM ka_restore_ledger WHERE hash = '$(php -r 'echo hash("sha256", "i346-ledger");')';
  DELETE FROM ka_nastaveni WHERE promenna IN ($I346_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_i346_backup; DROP TABLE IF EXISTS ka_i346_backup" > /dev/null
