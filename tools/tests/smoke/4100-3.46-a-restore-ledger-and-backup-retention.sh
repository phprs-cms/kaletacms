echo "== 3.46 A: restoring an older backup keeps today's sign-in security, erasures and unsubscribes and sends no queue again (N156); backups by age (N157); queued bodies blanked (N161)"
r346_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R346_NOW=$(site_time)
R346_HEX() { php -r 'echo bin2hex(random_bytes((int) $argv[1]));' -- "${1:-32}"; }
R346_STOPPED='Restored from a backup – not sent again.'

# --- the state the backup holds (day 3) ---
r346_php '(new Kaleta\Core\Antispam($db, $app->settings()))->key();' > /dev/null # the signing key that tells a backup of this site
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('r346keep', 'r346-old-hash', 'R346 Keep', 'r346keep@example.cz', 1, 0), ('r346gone', 'r346-gone-hash', 'R346 Gone', 'r346gone@example.cz', 1, 0)" > /dev/null
R346_KEEP=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r346keep'"); R346_GONE=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r346gone'")
R346_T1=$(R346_HEX); R346_T2=$(R346_HEX); R346_P1=$(R346_HEX)
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($R346_KEEP, 'R346 leaked', '$R346_T1', '$R346_NOW'), ($R346_GONE, 'R346 leaver', '$R346_T2', '$R346_NOW');
  INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, vytvoreno) VALUES ($R346_KEEP, 'R346 phone', '$R346_P1', 'x', 'x', -7, '$R346_NOW')" > /dev/null
R346_SUB_TOKEN=$(R346_HEX 16); R346_SUB2_TOKEN=$(R346_HEX 16)
sq "INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('r346-erase@example.cz', 1, '$R346_SUB_TOKEN', '$R346_NOW', '$R346_NOW'), ('r346-unsub@example.cz', 1, '$R346_SUB2_TOKEN', '$R346_NOW', '$R346_NOW');
  INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$R346_NOW', 'R346 form', 'r346-erase@example.cz', '[[\"Message\",\"R346 call me\"]]')" > /dev/null
R346_E1=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'R346 form'")
# queues waiting in the backup: M1, W1, C1 go out after it; M2 is still waiting today
sq "INSERT INTO ka_posta (komu, predmet, telo, vytvoreno, dalsi_pokus) VALUES ('r346-m1@example.cz', 'R346 M1', '{\"text\":\"x\"}', '$R346_NOW', '$(site_time '+1 day')'), ('r346-m2@example.cz', 'R346 M2', '{\"text\":\"x\"}', '$R346_NOW', '$(site_time '+1 day')');
  INSERT INTO ka_webhook_deliveries (event, url, body, created, next_attempt) VALUES ('r346', 'https://example.invalid/hook', '{\"id\":1}', '$R346_NOW', '$(site_time '+1 day')');
  INSERT INTO ka_connector_queue (action, payload, created_at, next_attempt) VALUES ('r346.test', '{\"x\":1}', '$R346_NOW', '$(site_time '+1 day')');
  INSERT INTO ka_connectors (service, account, access_token, connected_at) VALUES ('r346', 'R346 account', 'r346-token', '$R346_NOW')" > /dev/null
R346_M1=$(sq "SELECT idp FROM ka_posta WHERE predmet = 'R346 M1'"); R346_M2=$(sq "SELECT idp FROM ka_posta WHERE predmet = 'R346 M2'")
R346_W1=$(sq "SELECT MAX(id) FROM ka_webhook_deliveries WHERE event = 'r346'"); R346_C1=$(sq "SELECT MAX(id) FROM ka_connector_queue WHERE action = 'r346.test'")

# the backup, made "two days ago" (its name and its time) and – an older version – before migrations 0133 and 0134
R346_DBV=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'") # the version of this code (134 since 0134) – the restore brings the backup up to it
R346_MADE=$(r346_php 'echo Kaleta\Core\Backup::create($db, "r346", false);')
R346_FILE="kaleta-$(site_time '-2 days' Ymd-His)-r346-$(R346_HEX 4).sql.gz"; R346_DIR="$WORK/web/storage/zalohy"
gzip -dc "$R346_DIR/$R346_MADE" | php -r '$s = stream_get_contents(STDIN); $n = 0; echo str_replace("\x27db_version\x27,\x27" . $argv[1] . "\x27", "\x27db_version\x27,\x27132\x27", $s, $n); fwrite(STDERR, (string) $n);' -- "$R346_DBV" 2> "$WORK/r346-n" | gzip > "$R346_DIR/$R346_FILE"
rm -f "$R346_DIR/$R346_MADE"; touch -t "$(site_time '-2 days' YmdHi)" "$R346_DIR/$R346_FILE"
expect "3.46 N156: the backup never holds the restore ledger; the older backup says db_version 132" \
  "$(gzip -dc "$R346_DIR/$R346_FILE" | grep -c 'restore_ledger' || true)|$(cat "$WORK/r346-n")" "0|1"

# --- what happens after it (day 5) ---
R346_NEWHASH='r346-new-hash'
sq "DELETE FROM ka_api_tokeny WHERE otisk = '$R346_T1'; DELETE FROM ka_uzivatele_klice WHERE otisk_id = '$R346_P1';
  UPDATE ka_uzivatele SET password = '$R346_NEWHASH', blokovat = 1, sign_in_epoch = sign_in_epoch + 1 WHERE idu = $R346_KEEP; DELETE FROM ka_uzivatele WHERE idu = $R346_GONE;
  UPDATE ka_posta SET odeslano = '$R346_NOW', telo = NULL WHERE idp = $R346_M1; UPDATE ka_webhook_deliveries SET body = NULL, next_attempt = NULL, delivered = '$R346_NOW' WHERE id = $R346_W1;
  UPDATE ka_connector_queue SET payload = NULL, next_attempt = NULL, delivered_at = '$R346_NOW' WHERE id = $R346_C1;
  UPDATE ka_connectors SET access_token = NULL, connected_at = NULL WHERE service = 'r346'" > /dev/null
# N161: deliveries of the person still waiting when they are erased lose their body (by the address, by the enquiry id)
sq "INSERT INTO ka_webhook_deliveries (event, url, body, created, next_attempt) VALUES ('nova_poptavka', 'https://example.invalid/hook', '{\"udalost\":\"nova_poptavka\",\"id\":999999,\"email\":\"R346-Erase@example.cz\"}', '$R346_NOW', NULL);
  INSERT INTO ka_connector_queue (action, payload, created_at, next_attempt) VALUES ('crm.lead', '{\"service\":\"hubspot\",\"enquiry\":$R346_E1,\"email\":\"\"}', '$R346_NOW', '$(site_time '+1 hour')'),
    ('crm.lead', '{\"service\":\"hubspot\",\"enquiry\":999998,\"email\":\"someone-else@example.cz\"}', '$R346_NOW', '$(site_time '+1 hour')')" > /dev/null
R346_W3=$(sq "SELECT MAX(id) FROM ka_webhook_deliveries WHERE event = 'nova_poptavka' AND body LIKE '%R346-Erase%'"); R346_C3=$(sq "SELECT MAX(id) - 1 FROM ka_connector_queue WHERE action = 'crm.lead'")
R346_ERASE=$(r346_php '$r = Kaleta\Core\PersonalData::erase($app, "r346-erase@example.cz"); echo json_encode([$r["erased"]["deliveries"] ?? 0, $r["backup_horizon"]["local_days"], str_contains($r["backup_horizon"]["text"], "30")]);')
expect "3.46 N161/N157: the erasure blanks the person's queued deliveries (not someone else's) and states the backup horizon" \
  "$R346_ERASE|$(sq "SELECT CONCAT(body IS NULL, next_attempt IS NULL) FROM ka_webhook_deliveries WHERE id = $R346_W3")|$(sq "SELECT CONCAT(payload IS NULL, '/', (SELECT payload IS NULL FROM ka_connector_queue WHERE id = $R346_C3 + 1)) FROM ka_connector_queue WHERE id = $R346_C3")" "[2,30,true]|11|1/0"
R346_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/odber?unsubscribe=$R346_SUB2_TOKEN")
expect "3.46 N156: the unsubscribe link and the erasure go into the ledger as keyed fingerprints – never the address" \
  "$R346_CODE|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'r346-unsub@example.cz'")|$(r346_php 'echo (int) $db->value("SELECT COUNT(*) FROM {restore_ledger} WHERE (kind = ? AND hash = ?) OR (kind = ? AND hash = ?)", ["unsubscribed", Kaleta\Core\RestoreLedger::fingerprint($db, "r346-unsub@example.cz"), "erased", Kaleta\Core\RestoreLedger::fingerprint($db, "R346-erase@example.cz")]);')|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE hash LIKE '%r346%'")" "200|0|2|0"

# --- before the restore: the Backups tab says what it loses and what it keeps ---
# 3.46.1: the applications their applicants deleted count among the erasures
R346_MADE_AT=$(r346_php 'echo Kaleta\Core\RestoreLedger::madeAt($a[0]);' "$R346_FILE")
R346_ERASURES=$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind IN ('erased', 'application_deleted') AND created_at > '$R346_MADE_AT'"); R346_UNSUBS=$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'unsubscribed' AND created_at > '$R346_MADE_AT'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
expect "3.46 N156: the Restore button of the backup says what the restore loses since it and keeps from today" \
  "$(grep "value=\"$R346_FILE\" data-restore-undoes" "$WORK/response" | grep -c "Od této zálohy obnova ztratí .* $R346_ERASURES výmazů osobních údajů a $R346_UNSUBS odhlášení odběru od té doby" || true)" "1"
R346_LEDGER_BEFORE=$(sq "SELECT COUNT(*) FROM ka_restore_ledger")

# --- the restore of the older backup ---
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$(csrf)" -d "soubor=$R346_FILE"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
expect "3.46: the older backup is restored, brought up to this version (0133 and 0134 again) and the message says what was kept; the administrator stays signed in" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")|$(grep -c 'Databáze byla obnovena ze zálohy' "$WORK/response" || true)|$(grep -c 'Ponecháno z dneška' "$WORK/response" || true)" "$R346_DBV|1|1"
expect "3.46 N156: today's sign-in security – the revoked token and passkey stay gone, the password and the block of today, the removed account comes back blocked without its token" \
  "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk IN ('$R346_T1', '$R346_T2')")|$(sq "SELECT COUNT(*) FROM ka_uzivatele_klice WHERE otisk_id = '$R346_P1'")|$(sq "SELECT CONCAT(password, '/', blokovat) FROM ka_uzivatele WHERE idu = $R346_KEEP")|$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $R346_GONE")" \
  "0|0|$R346_NEWHASH/1|1"
expect "3.46 N156: the erased person and the unsubscribed address do not come back with the backup" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $R346_E1")|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email IN ('r346-erase@example.cz', 'r346-unsub@example.cz')")" "0|0"
expect "3.46 N156: what the backup had waiting and went out since is not sent again; a mail still waiting today is not stopped; the disconnected service stays disconnected" \
  "$(sq "SELECT CONCAT(odeslano IS NULL, dalsi_pokus IS NULL, chyba = '$R346_STOPPED') FROM ka_posta WHERE idp = $R346_M1")|$(sq "SELECT chyba = '$R346_STOPPED' FROM ka_posta WHERE idp = $R346_M2")|$(sq "SELECT CONCAT(next_attempt IS NULL, error = '$R346_STOPPED') FROM ka_webhook_deliveries WHERE id = $R346_W1")|$(sq "SELECT CONCAT(next_attempt IS NULL, last_error = '$R346_STOPPED') FROM ka_connector_queue WHERE id = $R346_C1")|$(sq "SELECT CONCAT(access_token IS NULL, connected_at IS NULL) FROM ka_connectors WHERE service = 'r346'")" \
  "111|0|11|11|11"
expect "3.46 N156: the ledger outlived the restore (and remembers the removed token and passkey); the event says it was this site" \
  "$([ "$(sq "SELECT COUNT(*) FROM ka_restore_ledger")" -ge "$R346_LEDGER_BEFORE" ] && echo kept)|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE (kind = 'token' AND hash = '$R346_T2') OR (kind = 'passkey' AND hash = '$R346_P1')")|$(sq "SELECT JSON_EXTRACT(data, '$.same_site') FROM ka_events WHERE type = 'backup.restored' ORDER BY id DESC LIMIT 1")" \
  "kept|1|true"
check "3.46 N157: the processing record states the backup horizon" 200 "/admin.php?module=settings&action=processing_record" "Vymazané údaje mohou v zálohách zůstat nejvýš 30 dní"

# --- a backup of another installation (its signing key unknown to the ledger – the hosting was lost): its accounts stay as they are in it ---
R346_KEY=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'"); R346_PREV=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'secret_key_previous'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('secret_key_previous', 'r346-previous-key|1')" > /dev/null # a site whose key was rotated once (the full run has one)
R346_FOREIGN=$(r346_php 'echo Kaleta\Core\Backup::create($db, "r346f", false);')
gzip -dc "$R346_DIR/$R346_FOREIGN" | php -r '$s = stream_get_contents(STDIN); $s = str_replace("'"'"'" . $argv[1] . "'"'"'", "'"'"'" . bin2hex(random_bytes(32)) . "'"'"'", $s); echo preg_replace("/(\x27secret_key_previous\x27,)\x27[^\x27]*\x27/", "\\1\x27\x27", $s);' -- "$R346_KEY" | gzip > "$R346_DIR/$R346_FOREIGN.tmp" && mv "$R346_DIR/$R346_FOREIGN.tmp" "$R346_DIR/$R346_FOREIGN"
sq "UPDATE ka_uzivatele SET password = 'r346-third-hash' WHERE idu = $R346_KEEP" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$(csrf)" -d "soubor=$R346_FOREIGN"
expect "3.46 N156: a backup of another installation keeps its own accounts (no sign-in state carried over); the event says so" \
  "$(sq "SELECT password FROM ka_uzivatele WHERE idu = $R346_KEEP")|$(sq "SELECT JSON_EXTRACT(data, '$.same_site') FROM ka_events WHERE type = 'backup.restored' ORDER BY id DESC LIMIT 1")" "$R346_NEWHASH|false"
sq "UPDATE ka_nastaveni SET hodnota = '$R346_KEY' WHERE promenna = 'secret_key'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('secret_key_previous', '$R346_PREV')" > /dev/null; rm -f "$R346_DIR/$R346_FOREIGN"

# --- N161: the daily sweep – a given-up delivery keeps its body for the Retry button for 7 days, then loses it ---
sq "INSERT INTO ka_webhook_deliveries (event, url, body, created, next_attempt) VALUES ('r346-old', 'https://example.invalid/hook', '{\"x\":1}', '$(site_time '-8 days')', NULL), ('r346-new', 'https://example.invalid/hook', '{\"x\":1}', '$R346_NOW', NULL);
  INSERT INTO ka_connector_queue (action, payload, created_at, next_attempt) VALUES ('r346.old', '{\"x\":1}', '$(site_time '-8 days')', NULL)" > /dev/null
r346_php 'Kaleta\Core\PersonalData::sweepQueues($db);' > /dev/null
expect "3.46 N161: the sweep blanks given-up bodies older than 7 days and keeps a fresh one for Retry" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(event, ':', body IS NULL) ORDER BY event) FROM ka_webhook_deliveries WHERE event IN ('r346-old', 'r346-new')")|$(sq "SELECT payload IS NULL FROM ka_connector_queue WHERE action = 'r346.old'")" "r346-new:0,r346-old:1|1"

# --- N157: local backups by age, off-site copies past backup_remote_days (fake S3 with a listing and DELETE) ---
R346_OLD="kaleta-$(site_time '-40 days' Ymd-His)-auto-$(R346_HEX 4).sql.gz"
printf -- '-- Kaleta old\n' | gzip > "$R346_DIR/$R346_OLD"; touch -t "$(site_time '-40 days' YmdHi)" "$R346_DIR/$R346_OLD"
R346_FRESH=$(r346_php 'echo Kaleta\Core\Backup::create($db, "r346n", false);') # the newest – neither the count nor the age takes it
r346_php 'Kaleta\Core\Backup::prune();' > /dev/null
expect "3.46 N157: a local backup older than 30 days goes, a fresh one stays" "$([ -f "$R346_DIR/$R346_OLD" ] && echo kept || echo gone)|$([ -f "$R346_DIR/$R346_FRESH" ] && echo kept || echo gone)" "gone|kept"
rm -f "$R346_DIR/$R346_FRESH"
S346_PORT=$((PORT + 46)); mkdir -p "$WORK/s346/sub"
cat > "$WORK/s346/router.php" <<'PHP'
<?php
$h = array_change_key_case(getallheaders());
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . (str_starts_with($h['authorization'] ?? '', 'AWS4-HMAC-SHA256 ') ? 'signed' : 'unsigned') . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/r346-bucket' && ($_GET['list-type'] ?? '') === '2') {
    $keys = '';
    foreach (glob(__DIR__ . '/sub/kaleta-*') ?: [] as $f) { $keys .= '<Contents><Key>sub/' . basename($f) . '</Key></Contents>'; }
    header('Content-Type: application/xml'); echo '<?xml version="1.0"?><ListBucketResult>' . $keys . '</ListBucketResult>'; return true;
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('#^/r346-bucket/sub/(kaleta-[^/]+)$#', $path, $m)) { @unlink(__DIR__ . '/sub/' . $m[1]); http_response_code(204); return true; }
http_response_code(200); return true;
PHP
(cd "$WORK/s346" && exec php -S "127.0.0.1:$S346_PORT" router.php > /dev/null 2>&1) & S346_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$S346_PORT/ping" && break; sleep 0.2; done
R346_MARK=$(r346_php 'echo Kaleta\Core\Backup::siteMark($db);') # review L6: only this site's names are pruned
R346_R_OLD="kaleta-$(site_time '-100 days' Ymd-His)-auto-aaaa1111-$R346_MARK.sql.gz.enc"; R346_R_NEW="kaleta-$(site_time '-10 days' Ymd-His)-auto-bbbb2222-$R346_MARK.sql.gz.enc"; R346_R_PLAIN="kaleta-$(site_time '-100 days' Ymd-His)-auto-cccc3333-$R346_MARK.sql.gz"
touch "$WORK/s346/sub/$R346_R_OLD" "$WORK/s346/sub/$R346_R_NEW" "$WORK/s346/sub/$R346_R_PLAIN"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKID346'), ('backup_password', 'secret-346'), ('backup_folder', 'r346-bucket/sub'),
  ('backup_region', 'eu-central-1'), ('backup_test_url', 'http://127.0.0.1:$S346_PORT'), ('backup_remote_days', '90')" > /dev/null
R346_PRUNED=$(r346_php '$s = $app->settings(); [$put, $close, $list, $delete] = (new ReflectionMethod(Kaleta\Core\RemoteBackup::class, "open"))->invoke(null, $s);
  $seen = $list(); sort($seen); echo count($seen), "/", (new ReflectionMethod(Kaleta\Core\RemoteBackup::class, "pruneRemote"))->invoke(null, $s, $list, $delete, "kaleta-x.sql.gz.enc"); $close();')
expect "3.46 N157: the off-site listing sees the three backup names; only the encrypted copy past 90 days is deleted, every request signed" \
  "$R346_PRUNED|$(ls "$WORK/s346/sub" | sort | tr '\n' ' ')|$(grep -v " /ping " "$WORK/s346/requests.log" | grep -c "unsigned" || true)|$(grep -c '^DELETE /r346-bucket/sub/kaleta-' "$WORK/s346/requests.log" || true)" \
  "3/1|$(printf '%s\n' "$R346_R_NEW" "$R346_R_PLAIN" | sort | tr '\n' ' ')|0|1"
kill "$S346_PID" 2> /dev/null || true
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('backup_host', 'backup_user', 'backup_password', 'backup_folder', 'backup_test_url'); UPDATE ka_nastaveni SET hodnota = 'vypnuto' WHERE promenna = 'remote_backup'; DELETE FROM ka_nastaveni WHERE promenna = 'backup_remote_days'" > /dev/null

# clean up
sq "DELETE FROM ka_uzivatele WHERE user IN ('r346keep', 'r346gone'); DELETE FROM ka_connectors WHERE service = 'r346';
  DELETE FROM ka_posta WHERE predmet IN ('R346 M1', 'R346 M2'); DELETE FROM ka_webhook_deliveries WHERE event IN ('r346', 'r346-old', 'r346-new') OR id = ${R346_W3:-0};
  DELETE FROM ka_connector_queue WHERE action IN ('r346.test', 'r346.old') OR id IN (${R346_C3:-0}, ${R346_C3:-0} + 1)" > /dev/null
rm -f "$R346_DIR/$R346_FILE"
