echo "== 3.50 A2: an off-site private copy counts as deleted only when the target confirms it – a mistyped bucket (404 NoSuchBucket) keeps it listed, NoSuchKey lets it go (N169)"
a522_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
A522_KEYS="'remote_backup', 'backup_host', 'backup_user', 'backup_password', 'backup_folder', 'backup_region', 'backup_test_url', 'backup_media', 'backup_remote_days', 'remote_private_status', 'private_sync_check', 'backup_key_confirmed'"
sq "DROP TABLE IF EXISTS ka_a522_backup; CREATE TABLE ka_a522_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($A522_KEYS)" > /dev/null
A522_MANIFEST="$WORK/web/storage/zalohy/private-kopie.json"
# a fake S3: uploads are accepted; every DELETE answers 404 – NoSuchBucket, or NoSuchKey once the file "key" exists
A522_PORT=$((PORT + 24)); mkdir -p "$WORK/s3-a522" # the port of the fake target of 4201, stopped there
cat > "$WORK/s3-a522/router.php" <<'PHP'
<?php
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $path . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') { http_response_code(404); echo '<Error><Code>' . (is_file(__DIR__ . '/key') ? 'NoSuchKey' : 'NoSuchBucket') . '</Code><Message>gone</Message></Error>'; return true; }
http_response_code(200); return true;
PHP
(cd "$WORK/s3-a522" && exec php -S "127.0.0.1:$A522_PORT" router.php > /dev/null 2>&1) & A522_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$A522_PORT/ping" && break; sleep 0.2; done
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDA522'), ('backup_folder', 'a522'), ('backup_region', 'eu-central-1'),
  ('backup_test_url', 'http://127.0.0.1:$A522_PORT'), ('backup_media', '0'), ('backup_remote_days', '90'), ('remote_private_status', ''), ('private_sync_check', '0')" > /dev/null
mv "$WORK/web/storage/backup-key.php" "$WORK/a522-key.saved" 2>/dev/null || true
a522_php '$s->set("backup_password", "tajne-a522"); Kaleta\Core\RecoveryKey::confirm($s, Kaleta\Core\RecoveryKey::ensure());' > /dev/null
rm -f "$A522_MANIFEST"
A522_REL="$(date +%Y/%m)/$(php -r 'echo bin2hex(random_bytes(16));').bin"; mkdir -p "$(dirname "$WORK/web/storage/requests/$A522_REL")"; printf 'A522 private attachment\n' > "$WORK/web/storage/requests/$A522_REL"
A522_PATH="storage/requests/$A522_REL"
a522_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
rm -f "$WORK/web/storage/requests/$A522_REL"
a522_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
php -r '$m = json_decode((string) file_get_contents($argv[1]), true); $m["smazano"][$argv[2]] = time() - 91 * 86400; file_put_contents($argv[1], json_encode($m, JSON_UNESCAPED_SLASHES));' -- "$A522_MANIFEST" "$A522_PATH"
a522_manifest() { php -r '$m = json_decode((string) file_get_contents($argv[1]), true); $p = $argv[2]; echo (isset($m["soubory"][$p]) ? "listed" : "dropped") . "/" . (int) ($m["nesmazano"][$p] ?? 0);' -- "$A522_MANIFEST" "$A522_PATH"; }
: > "$WORK/s3-a522/requests.log"; a522_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
expect "3.50 N169: a DELETE answered 404 NoSuchBucket is no proof – the file stays listed and counts as not deleted" \
  "$(a522_manifest)|$(grep -c "^DELETE /a522/private/$A522_PATH.enc$" "$WORK/s3-a522/requests.log" || true)" "listed/1|1"
touch "$WORK/s3-a522/key"; a522_php 'Kaleta\Core\RemoteBackup::syncPrivate($s, 60);' > /dev/null
expect "3.50 N169: 404 NoSuchKey (the object is not there) counts as deleted – dropped from the list" "$(a522_manifest | cut -d/ -f1)|$(a522_php 'echo Kaleta\Core\RemoteBackup::undeletedPrivate();')" "dropped|0"
kill "$A522_PID" 2> /dev/null || true
rm -f "$A522_MANIFEST" "$WORK/web/storage/backup-key.php"; rm -rf "$WORK/s3-a522"; [ -f "$WORK/a522-key.saved" ] && mv "$WORK/a522-key.saved" "$WORK/web/storage/backup-key.php"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ($A522_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_a522_backup; DROP TABLE ka_a522_backup" > /dev/null
