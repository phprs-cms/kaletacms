echo "== 3.46.1 re-audit M4, review N2: a private file still to be deleted off-site survives a new recovery key; a target that refuses deletion keeps the file listed and Settings → Backups says so"
r201_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R201_KEYS="'remote_backup', 'backup_host', 'backup_user', 'backup_password', 'backup_folder', 'backup_region', 'backup_test_url', 'backup_media', 'backup_remote_days', 'remote_private_status', 'private_sync_check', 'backup_key_confirmed'"
sq "DROP TABLE IF EXISTS ka_r201_backup; CREATE TABLE ka_r201_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($R201_KEYS)" > /dev/null
R201_MANIFEST="$WORK/web/storage/zalohy/private-kopie.json"

# --- a fake S3 that stores what is uploaded under private/ and refuses every DELETE (credentials that may write only)
R201_PORT=$((PORT + 24)); mkdir -p "$WORK/s3-r201/files"
cat > "$WORK/s3-r201/router.php" <<'PHP'
<?php
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $path . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && is_file(__DIR__ . '/missing')) { http_response_code(404); echo '<Error><Code>NoSuchKey</Code></Error>'; return true; }
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') { http_response_code(403); echo '<Error><Code>AccessDenied</Code></Error>'; return true; }
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#/private/(.+)$#', $path, $m)) { file_put_contents(__DIR__ . '/files/' . md5($m[1]), file_get_contents('php://input')); }
http_response_code(200); return true;
PHP
(cd "$WORK/s3-r201" && exec php -S "127.0.0.1:$R201_PORT" router.php > /dev/null 2>&1) & R201_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$R201_PORT/ping" && break; sleep 0.2; done
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDR201'), ('backup_folder', 'r201'), ('backup_region', 'eu-central-1'),
  ('backup_test_url', 'http://127.0.0.1:$R201_PORT'), ('backup_media', '0'), ('backup_remote_days', '90'), ('remote_private_status', ''), ('private_sync_check', '0')" > /dev/null
mv "$WORK/web/storage/backup-key.php" "$WORK/r201-key.saved" 2>/dev/null || true
r201_php '$s->set("backup_password", "tajne-r201"); Kaleta\Core\RecoveryKey::confirm($s, Kaleta\Core\RecoveryKey::ensure());' > /dev/null
rm -f "$R201_MANIFEST"
R201_REL="$(date +%Y/%m)/$(php -r 'echo bin2hex(random_bytes(16));').bin"; mkdir -p "$(dirname "$WORK/web/storage/requests/$R201_REL")"; printf 'R201 private attachment\n' > "$WORK/web/storage/requests/$R201_REL"
R201_PATH="storage/requests/$R201_REL"
r201_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
rm -f "$WORK/web/storage/requests/$R201_REL" # an erased person's attachment
r201_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
r201_manifest() { php -r '$m = json_decode((string) file_get_contents($argv[1]), true); $p = $argv[2]; echo (isset($m["soubory"][$p]) ? "listed" : "dropped") . "/" . (isset($m["smazano"][$p]) ? "gone" : "-") . "/" . (int) ($m["nesmazano"][$p] ?? 0);' -- "$R201_MANIFEST" "$R201_PATH"; }
expect "3.46.1 re-audit M4: copied, then removed on the site – listed as gone, nothing deleted yet" "$(r201_manifest)" "listed/gone/0"

# --- a new recovery key: everything is copied again, and the file still to be deleted stays on the list with the day it went
php -r '$m = json_decode((string) file_get_contents($argv[1]), true); $m["smazano"][$argv[2]] = time() - 91 * 86400; file_put_contents($argv[1], json_encode($m, JSON_UNESCAPED_SLASHES));' -- "$R201_MANIFEST" "$R201_PATH"
rm -f "$WORK/web/storage/backup-key.php"; r201_php 'Kaleta\Core\RecoveryKey::confirm($s, Kaleta\Core\RecoveryKey::ensure());' > /dev/null
: > "$WORK/s3-r201/requests.log"; r201_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
expect "3.46.1 re-audit M4: after a new recovery key the pending deletion is kept – the target is asked to delete it" \
  "$(r201_manifest)|$(grep -c "^DELETE /r201/private/$R201_PATH.enc$" "$WORK/s3-r201/requests.log" || true)" "listed/gone/1|1"

# --- the target refuses deletion three times: the file stays listed as not deleted, the next run still tries, Settings → Backups says so
r201_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60); Kaleta\Core\RemoteBackup::syncPrivate($s, 60); Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r201-backups" "$B/admin.php?module=settings&tab=backups"
expect "3.46.1 re-audit M4: after three refused deletions the file is still listed (tried on every run – an administration request may run the copy too), counted as not deleted and shown in Settings → Backups" \
  "$(r201_manifest | cut -d/ -f1,2)|$(r201_php 'echo Kaleta\Core\RemoteBackup::undeletedPrivate();')|$([ "$(grep -c "^DELETE /r201/private/$R201_PATH.enc$" "$WORK/s3-r201/requests.log" || true)" -ge 4 ] && echo tried || echo not)|$(grep -q -e 'could not be deleted from the off-site copy' -e 'nešlo smazat z kopie mimo server' "$WORK/r201-backups" && echo shown || echo no)" \
  "listed/gone|1|tried|shown"
# --- review N2: the file is not there any more (deleted by hand) – "no such object" counts as deleted and the message clears
touch "$WORK/s3-r201/missing"; r201_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
expect "3.46.1 review N2: a deletion answered with \"no such object\" counts as done – dropped from the list, nothing left as not deleted" \
  "$(r201_manifest | cut -d/ -f1)|$(r201_php 'echo Kaleta\Core\RemoteBackup::undeletedPrivate();')" "dropped|0"
kill "$R201_PID" 2> /dev/null || true

# clean up
rm -f "$R201_MANIFEST" "$WORK/web/storage/backup-key.php"; rm -rf "$WORK/s3-r201"; [ -f "$WORK/r201-key.saved" ] && mv "$WORK/r201-key.saved" "$WORK/web/storage/backup-key.php"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ($R201_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_r201_backup; DROP TABLE ka_r201_backup" > /dev/null
