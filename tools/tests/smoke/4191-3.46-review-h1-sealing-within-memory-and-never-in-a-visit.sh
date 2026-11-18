echo "== 3.46 review H1: sealing and opening a large private file fit the memory budget; a file that killed a run is skipped; a visitor's request never seals"
# a command-line PHP with a fixed memory limit, the site's app ready: h1_php LIMIT 'code' args…
h1_php() { local limit="$1"; shift; (cd "$WORK/web" && php -d "memory_limit=$limit" -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
H1_KEYS="'private_files_sealing', 'private_files_skipped', 'private_files_unsealed', 'data_migrations', 'data_migrations_unfinished'"
sq "DROP TABLE IF EXISTS ka_h1_backup; CREATE TABLE ka_h1_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($H1_KEYS)" > /dev/null
sq "INSERT INTO ka_internal_folders (name, audience, created_at, updated_at) VALUES ('H1 Folder', 'all', '$(site_time)', '$(site_time)')" > /dev/null
H1_FOLDER=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'H1 Folder'")
# a version from before 3.46: a plain 26 MB file, the row without a key – 5× its size (the old peak) is above 128M, 3× is not
h1_plain() { # h1_plain NAME MB → the version id
  local rel; rel="$(date +%Y/%m)/$(php -r 'echo bin2hex(random_bytes(16));').bin"; mkdir -p "$(dirname "$WORK/web/storage/internal-files/$rel")"
  head -c "$(( $2 * 1048576 ))" /dev/urandom > "$WORK/web/storage/internal-files/$rel"
  local size sha; size=$(wc -c < "$WORK/web/storage/internal-files/$rel" | tr -d ' '); sha=$(php -r 'echo hash_file("sha256", $argv[1]);' -- "$WORK/web/storage/internal-files/$rel")
  sq "INSERT INTO ka_internal_files (folder_id, name, description, tags, status, extension, size, version, created_at, updated_at) VALUES ($H1_FOLDER, '$1', '', '', 'ready', 'bin', $size, 1, '$(site_time)', '$(site_time)');
    INSERT INTO ka_internal_file_versions (file_id, version, path, original_name, extension, size, sha256, file_key, uploaded_at) VALUES (LAST_INSERT_ID(), 1, '$rel', '$1', 'bin', $size, '$sha', NULL, '$(site_time)')" > /dev/null
  sq "SELECT v.id FROM ka_internal_file_versions v JOIN ka_internal_files f ON f.id = v.file_id WHERE f.name = '$1' AND f.folder_id = $H1_FOLDER"
}
h1_head() { head -c 4 "$WORK/web/storage/internal-files/$(sq "SELECT path FROM ka_internal_file_versions WHERE id = $1")"; }
H1_BIG=$(h1_plain h1-catalogue.bin 26)
H1_SHA=$(sq "SELECT sha256 FROM ka_internal_file_versions WHERE id = $H1_BIG")

# --- the daily sealing at the PHP default of 128M: no fatal error, the file sealed, the peak at most three copies (it was five)
H1_RUN=$(h1_php 128M '$before = memory_get_usage(); $r = Kaleta\Core\InternalFiles::sealPending($s, microtime(true) + 60.0); $peak = memory_get_peak_usage() - $before;
  echo json_encode($r), "|", $peak < 3 * (int) $a[0] ? "within" : "over:" . $peak;' "$(sq "SELECT size FROM ka_internal_file_versions WHERE id = $H1_BIG")" 2>&1)
expect "3.46 review H1: a 26 MB internal file is sealed at memory_limit 128M (five copies would need 130 MB) – the peak stays below three copies" \
  "$H1_RUN|$(h1_head "$H1_BIG")|$(sq "SELECT file_key IS NOT NULL FROM ka_internal_file_versions WHERE id = $H1_BIG")" '{"sealed":1,"failed":0,"left":false}|within|KCV1|1'
H1_OPEN=$(h1_php 128M '$before = memory_get_usage(); $v = $db->one("SELECT * FROM {internal_file_versions} WHERE id = ?", [(int) $a[0]]); $c = Kaleta\Core\InternalFiles::versionContent($s, $v ?? []);
  echo $c !== null && hash("sha256", $c) === $a[1] ? "same" : "different", "|", memory_get_peak_usage() - $before < 3 * strlen((string) $c) ? "within" : "over";' "$H1_BIG" "$H1_SHA" 2>&1)
expect "3.46 review H1: the download opens it at 128M as the original, within three copies (it was four)" "$H1_OPEN" "same|within"

# --- a run that died on a file (a memory or time limit no catch can stop) leaves its name; the next run skips it and says so
H1_DEAD=$(h1_plain h1-dead.bin 1)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('private_files_sealing', 'internal_files:$H1_DEAD')" > /dev/null
h1_php 128M 'Kaleta\Core\InternalFiles::sealPending($s, microtime(true) + 30.0);' > /dev/null 2>&1
expect "3.46 review H1: the file a run died on is skipped (still plain and readable), remembered with the memory limit, and an event says so" \
  "$([ "$(h1_head "$H1_DEAD")" != KCV1 ] && echo plain)|$(sq "SELECT file_key IS NULL FROM ka_internal_file_versions WHERE id = $H1_DEAD")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'private_files_skipped'")|$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'private_files_sealing'), '')")|$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'files.seal_failed'")|$(h1_php 128M '$v = $db->one("SELECT * FROM {internal_file_versions} WHERE id = ?", [(int) $a[0]]); echo Kaleta\Core\InternalFiles::versionContent($s, $v ?? []) !== null ? "readable" : "no";' "$H1_DEAD")" \
  "plain|1|internal_files:$H1_DEAD|134217728||1|readable"
h1_php 256M 'Kaleta\Core\InternalFiles::sealPending($s, microtime(true) + 30.0);' > /dev/null 2>&1
expect "3.46 review H1: with a higher memory limit the skipped file is tried again and sealed" "$(h1_head "$H1_DEAD")|$(sq "SELECT file_key IS NOT NULL FROM ka_internal_file_versions WHERE id = $H1_DEAD")" "KCV1|1"

# --- migration 0134 unfinished: a visitor's request writes only the structure, the administrator's request seals
H1_VISIT=$(h1_plain h1-visit.bin 1)
sq "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM CONCAT(hodnota, ',0134-seal-private-files')) WHERE promenna = 'data_migrations_unfinished';
  REPLACE INTO ka_nastaveni (promenna, hodnota) SELECT 'data_migrations_unfinished', '0134-seal-private-files' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished')" > /dev/null
H1_PAGE=$(curl -s -o /dev/null -w '%{http_code}' "$B/")
h1_pending() { h1_php 128M 'echo Kaleta\Core\Migration::pending($s) ? "pending" : "done";'; }
expect "3.46 review H1/N2: a visitor's page request with 0134 unfinished answers and seals nothing – the migration is finished for good, the files left are named by the flag" \
  "$H1_PAGE|$(sq "SELECT file_key IS NULL FROM ka_internal_file_versions WHERE id = $H1_VISIT")|$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'data_migrations_unfinished'), '') LIKE '%0134%'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'private_files_unsealed'")|$(h1_pending)" "200|1|0|1|done"
H1_PAGE=$(curl -s -o /dev/null -w '%{http_code}' "$B/")
expect "3.46 review N2: the next visitor's request runs no migration – nothing pending, the file still waits for the administration" \
  "$H1_PAGE|$(h1_pending)|$(sq "SELECT file_key IS NULL FROM ka_internal_file_versions WHERE id = $H1_VISIT")" "200|done|1"
curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "3.46 review N2: the administrator's next request seals it and clears the flag (a full pass)" \
  "$(h1_head "$H1_VISIT")|$(sq "SELECT file_key IS NOT NULL FROM ka_internal_file_versions WHERE id = $H1_VISIT")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'private_files_unsealed'")" "KCV1|1|"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('private_files_unsealed', '1')" > /dev/null
h1_php 128M 'Kaleta\Core\Notifications::purgePersonalData($app, true);' > /dev/null 2>&1 || true
expect "3.46 review N2: the daily job clears the flag too once a pass got through" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'private_files_unsealed'")" ""

# clean up
for H1_ID in $H1_BIG $H1_DEAD $H1_VISIT; do rm -f "$WORK/web/storage/internal-files/$(sq "SELECT path FROM ka_internal_file_versions WHERE id = $H1_ID")"; done
sq "DELETE FROM ka_internal_file_versions WHERE file_id IN (SELECT id FROM ka_internal_files WHERE folder_id = $H1_FOLDER); DELETE FROM ka_internal_files WHERE folder_id = $H1_FOLDER; DELETE FROM ka_internal_folders WHERE id = $H1_FOLDER;
  DELETE FROM ka_events WHERE type = 'files.seal_failed'; DELETE FROM ka_nastaveni WHERE promenna IN ($H1_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_h1_backup; DROP TABLE ka_h1_backup" > /dev/null
