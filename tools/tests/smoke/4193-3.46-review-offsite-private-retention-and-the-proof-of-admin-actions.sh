echo "== 3.46 review M5, L3, L4, L5: removed private files leave the off-site copy after the retention; the export downloads by a POST; admin-only actions of shared sections and repeated backups need the proof"
r193_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R193_KEYS="'remote_backup', 'backup_host', 'backup_user', 'backup_password', 'backup_folder', 'backup_region', 'backup_test_url', 'backup_media', 'backup_remote_days', 'remote_private_status', 'private_sync_check', 'backup_key_confirmed', 'whistleblowing_readers', 'extensions', 'whistleblowing_enabled'"
sq "DROP TABLE IF EXISTS ka_r193_backup; CREATE TABLE ka_r193_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($R193_KEYS)" > /dev/null

# --- M5: a fake S3 that keeps what is uploaded under private/ and deletes it on DELETE
R193_PORT=$((PORT + 23)); mkdir -p "$WORK/s3-r193/files"
cat > "$WORK/s3-r193/router.php" <<'PHP'
<?php
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $path . "\n", FILE_APPEND);
if (preg_match('#/private/(.+)$#', $path, $m)) {
    $f = __DIR__ . '/files/' . md5($m[1]);
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') { file_put_contents($f, file_get_contents('php://input')); }
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') { @unlink($f); http_response_code(204); return true; }
}
http_response_code(200); return true;
PHP
(cd "$WORK/s3-r193" && exec php -S "127.0.0.1:$R193_PORT" router.php > /dev/null 2>&1) & R193_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$R193_PORT/ping" && break; sleep 0.2; done
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDR193'), ('backup_folder', 'r193'), ('backup_region', 'eu-central-1'),
  ('backup_test_url', 'http://127.0.0.1:$R193_PORT'), ('backup_media', '0'), ('backup_remote_days', '90'), ('remote_private_status', ''), ('private_sync_check', '0')" > /dev/null
mv "$WORK/web/storage/backup-key.php" "$WORK/r193-key.saved" 2>/dev/null || true
r193_php '$s->set("backup_password", "tajne-r193"); Kaleta\Core\RecoveryKey::confirm($s, Kaleta\Core\RecoveryKey::ensure());' > /dev/null
rm -f "$WORK/web/storage/zalohy/private-kopie.json"
R193_REL="$(date +%Y/%m)/$(php -r 'echo bin2hex(random_bytes(16));').bin"; mkdir -p "$(dirname "$WORK/web/storage/requests/$R193_REL")"; printf 'R193 private attachment\n' > "$WORK/web/storage/requests/$R193_REL"
R193_REMOTE="storage/requests/$R193_REL.enc"; R193_MD5=$(php -r 'echo md5($argv[1]);' -- "$R193_REMOTE")
r193_php 'echo Kaleta\Core\RemoteBackup::syncPrivate($s, 60) ?? "";' > /dev/null
expect "3.46 review M5: the private file is in the off-site copy" "$([ -f "$WORK/s3-r193/files/$R193_MD5" ] && echo there)" "there"
rm -f "$WORK/web/storage/requests/$R193_REL" # deleted on the site (an erased person, a removed request)
: > "$WORK/s3-r193/requests.log"; r193_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
expect "3.46 review M5: removed on the site – kept off-site within backup_remote_days (a database copy of that time may still name it), its removal remembered" \
  "$([ -f "$WORK/s3-r193/files/$R193_MD5" ] && echo kept)|$(grep -c '^DELETE' "$WORK/s3-r193/requests.log" || true)|$(php -r '$m = json_decode((string) file_get_contents($argv[1]), true); echo isset($m["smazano"][$argv[2]]) ? "remembered" : "no";' -- "$WORK/web/storage/zalohy/private-kopie.json" "storage/requests/$R193_REL")" "kept|0|remembered"
php -r '$m = json_decode((string) file_get_contents($argv[1]), true); $m["smazano"][$argv[2]] = time() - 91 * 86400; file_put_contents($argv[1], json_encode($m, JSON_UNESCAPED_SLASHES));' -- "$WORK/web/storage/zalohy/private-kopie.json" "storage/requests/$R193_REL"
r193_php 'echo Kaleta\Core\RemoteBackup::syncPrivate($s, 60) ?? "";' > "$WORK/r193-sync"
expect "3.46 review M5: gone for longer than backup_remote_days – deleted from the copy and from the manifest" \
  "$(cat "$WORK/r193-sync")|$([ -f "$WORK/s3-r193/files/$R193_MD5" ] && echo kept || echo deleted)|$(grep -c "^DELETE /r193/private/$R193_REMOTE$" "$WORK/s3-r193/requests.log" || true)|$(php -r '$m = json_decode((string) file_get_contents($argv[1]), true); echo isset($m["soubory"][$argv[2]]) || isset($m["smazano"][$argv[2]]) ? "listed" : "dropped";' -- "$WORK/web/storage/zalohy/private-kopie.json" "storage/requests/$R193_REL")" \
  "|deleted|1|dropped"
kill "$R193_PID" 2> /dev/null || true

# --- L5: the site export downloads only by a POST with the form token
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$(csrf)"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; R193_EXPORT=$(grep -o 'export-[0-9]*-[0-9]*-[0-9a-f]*\.[a-z]*' "$WORK/response" | head -1)
expect "3.46 review L5: a GET of the export hands out nothing; the list offers a form button; a POST with the token downloads it" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=transfer&action=download&file=$R193_EXPORT")|$(grep -c 'action=download&amp;file=' "$WORK/response" || true)|$(curl -s -b "$JAR" -o "$WORK/r193-export" -w '%{http_code}' -X POST "$B/admin.php?module=transfer&action=download" -d "_csrf=$(csrf)" -d "soubor=$R193_EXPORT")|$([ -s "$WORK/r193-export" ] && head -c 2 "$WORK/r193-export" | grep -c 'PK\|{"' || echo 0)" \
  "302|0|200|1"

# --- L4: "Create backup now" again within five minutes needs the proof; L3: admin-only actions of shared sections need it
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$(csrf)"
proof_age "$JAR"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; R193_T=$(csrf)
R193_BEFORE=$(ls "$WORK/web/storage/zalohy" | grep -c -- '-rucni-' || true)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r193-again" -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$R193_T"
expect "3.46 review L4: a second manual backup within five minutes without a recent proof asks to confirm and makes none" \
  "$(grep -c 'id="confirm-proof"' "$WORK/r193-again")|$(ls "$WORK/web/storage/zalohy" | grep -c -- '-rucni-' || true)" "1|$R193_BEFORE"
R193_EXT=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'), '')")
R193_ON=$(php -r '$e = array_filter(explode(",", $argv[1] !== "" ? $argv[1] : "novinky,poptavky,statistika,presmerovani,claude")); $e[] = "whistleblowing"; echo implode(",", array_unique($e));' -- "$R193_EXT")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$R193_ON'), ('whistleblowing_enabled', '1')" > /dev/null
R193_W=$(curl -s -b "$JAR" -o "$WORK/r193-wb" -w '%{http_code}' -X POST "$B/admin.php?module=whistleblowing&action=settings" -d "_csrf=$R193_T" -d "readers[]=1")$(grep -c 'id="confirm-proof"' "$WORK/r193-wb")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r193-erase" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$R193_T" -d "email=nobody-r193@example.cz" -d "erase=1"
expect "3.46 review L3: the readers of the whistleblowing channel and the erasure of personal data need the proof (\"Confirm it is you\")" \
  "$R193_W|$(grep -c 'id="confirm-proof"' "$WORK/r193-erase")" "4031|1"

# clean up (the proof is renewed by the suite before the next section)
rm -f "$WORK/web/storage/zalohy/private-kopie.json" "$WORK/web/storage/backup-key.php"; rm -rf "$WORK/s3-r193"; [ -f "$WORK/r193-key.saved" ] && mv "$WORK/r193-key.saved" "$WORK/web/storage/backup-key.php"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ($R193_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_r193_backup; DROP TABLE ka_r193_backup" > /dev/null
