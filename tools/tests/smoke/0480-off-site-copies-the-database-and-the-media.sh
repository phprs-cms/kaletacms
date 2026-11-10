echo "== off-site copies: the database and the media, incrementally (fake S3, 1.8)"
S3_PORT=$((PORT + 6)); mkdir -p "$WORK/s3"
cat > "$WORK/s3/router.php" <<'PHP'
<?php
$h = array_change_key_case(getallheaders());
$body = file_get_contents('php://input');
file_put_contents(__DIR__ . '/puts.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . strlen($body) . ' ' . (str_starts_with($h['authorization'] ?? '', 'AWS4-HMAC-SHA256 ') ? 'signed' : 'unsigned') . "\n", FILE_APPEND);
if (str_starts_with($_SERVER['REQUEST_URI'], '/kaleta-odmitnuto/')) { http_response_code(403); echo '<Error><Message>Access denied for AKIDTEST, secret tajne-s3</Message></Error>'; return true; } // 3.31.1: a refusing target
if (preg_match('#/(kaleta-[^/]+\.sql[^/]*)$#', $_SERVER['REQUEST_URI'], $m)) { file_put_contents(__DIR__ . '/' . $m[1], $body); } // 3.28: the database copies as they arrived
http_response_code(200); return true;
PHP
(cd "$WORK/s3" && exec php -S "127.0.0.1:$S3_PORT" router.php > /dev/null 2>&1) & S3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$S3_PORT/ping" && break; sleep 0.2; done; : > "$WORK/s3/puts.log"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDTEST'), ('backup_password', 'tajne-s3'),
  ('backup_folder', 'kaleta-zalohy'), ('backup_region', 'eu-central-1'), ('backup_test_url', 'http://127.0.0.1:$S3_PORT'), ('backup_media', '1'), ('remote_media_status', '')"
rm -f "$WORK/web/storage/zalohy/media-kopie.json"
MEDIA_FILES=$(cd "$WORK/web" && find media -type f ! -name '.*' ! -name '*.php' | wc -l | tr -d ' ')
backup_now() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$(csrf)"; }
backup_now
# 3.28 (D-W12): the database leaves the server only encrypted – until the recovery key is saved its copy waits (the media,
# the site's public files, go as they are)
expect "no recovery key yet: the database copy waits, every media file uploaded, signed (sql/media/unsigned)" "$(grep -c '^PUT /kaleta-zalohy/kaleta-.*\.sql' "$WORK/s3/puts.log")/$(grep -c '^PUT /kaleta-zalohy/media/' "$WORK/s3/puts.log")/$(grep -c 'unsigned' "$WORK/s3/puts.log")" "0/$MEDIA_FILES/0"
expect "media status: complete" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT SUBSTRING_INDEX(hodnota, '|', -2) FROM ka_nastaveni WHERE promenna = 'remote_media_status'")" "ok|0"
expect "3.28: the copy is paused, with one alert event, never the key" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(SUBSTRING_INDEX(hodnota, '|', -1), '/', (SELECT COUNT(*) FROM ka_events WHERE type = 'backup.copy_paused' AND severity = 'warning')) FROM ka_nastaveni WHERE promenna = 'remote_backup_status'")" "paused/1"
check "Backups show the media copy" 200 "/admin.php?module=settings&tab=backups" "Média: kopie je kompletní"
check "3.28: Backups say the copies wait for the recovery key" 200 "/admin.php?module=settings&tab=backups" "Zobrazit a uložit obnovovací klíč"
check "3.28: System status warns that the off-site copies are paused" 200 "/admin.php?module=status" "pozastavené – zálohy se mimo server kopírují jen zašifrované"
TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o "$WORK/recovery.html" -X POST "$B/admin.php?module=settings&action=backup_key" -d "_csrf=$TOKEN"
RECOVERY_KEY=$(grep -o -m1 'KBK1\(-[A-Z2-7]\{4\}\)\{13\}' "$WORK/recovery.html" | head -1)
expect "3.28: the recovery key is shown once, made outside the database (storage/backup-key.php, not readable by others)" "$([ -n "$RECOVERY_KEY" ] && echo shown)/$([ -f "$WORK/web/storage/backup-key.php" ] && echo file)/$(php -r 'echo decoct(fileperms($argv[1]) & 07);' "$WORK/web/storage/backup-key.php")/$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%$(sed -n 's/^key=//p' "$WORK/web/storage/backup-key.php" | cut -c1-20)%'")" "shown/file/0/0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/recovery.txt" -X POST "$B/admin.php?module=settings&action=backup_key_download" -d "_csrf=$TOKEN"
expect "3.28: the recovery key downloads as a text file while unconfirmed" "$(grep -c -- "$RECOVERY_KEY" "$WORK/recovery.txt")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup_key_confirm" -d "_csrf=$TOKEN"
expect "3.28: no confirmation without the tick" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'backup_key_confirmed'")" ""
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup_key_confirm" -d "_csrf=$TOKEN" -d "saved=1"
expect "3.28: confirmed – only the key's one-way id and the time are stored" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota REGEXP '^[0-9a-f]{16}[|][0-9]{4}-' FROM ka_nastaveni WHERE promenna = 'backup_key_confirmed'")" "1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/recovery2.html" -X POST "$B/admin.php?module=settings&action=backup_key" -d "_csrf=$TOKEN"
expect "3.28: after the confirmation the key is never shown again" "$(grep -c -- "$RECOVERY_KEY" "$WORK/recovery2.html")" "0"
expect "3.28: the key is in no log, event or change log" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT (SELECT COUNT(*) FROM ka_protokol WHERE CONCAT_WS(' ', akce, popis) LIKE '%KBK1%') + (SELECT COUNT(*) FROM ka_events WHERE CONCAT_WS(' ', message, data) LIKE '%KBK1%')")/$(grep -c 'KBK1' "$WORK/server.log" "$WORK"/web/storage/log/* 2>/dev/null | awk -F: '{s+=$NF} END {print s+0}')" "0/0"
: > "$WORK/s3/puts.log"; backup_now
UPLOADED=$(grep -o '^PUT /kaleta-zalohy/kaleta-[^ ]*' "$WORK/s3/puts.log" | sed 's#^PUT /kaleta-zalohy/##' | head -1)
expect "the next backup uploads the database encrypted (….sql.gz.enc) and no unchanged media" "$(grep -c '^PUT /kaleta-zalohy/kaleta-.*\.sql\(\.gz\)\?\.enc ' "$WORK/s3/puts.log")/$(grep -c '^PUT /kaleta-zalohy/media/' "$WORK/s3/puts.log")" "1/0"
expect "3.28: the uploaded bytes are encrypted – the format's header, no SQL, no temporary file left" "$(head -c 8 "$WORK/s3/$UPLOADED" | od -An -tx1 | tr -d ' \n')/$({ gzip -dc "$WORK/s3/$UPLOADED" 2>/dev/null || cat "$WORK/s3/$UPLOADED"; } | grep -a -c 'INSERT INTO')/$(ls -a "$WORK/web/storage/zalohy" | grep -c '^\.upload-')" "894b424b0d0a1a0a/0/0"
# restoring after losing the hosting: the off-site copy uploaded into storage/zalohy/, restored with the recovery key
cp "$WORK/s3/$UPLOADED" "$WORK/web/storage/zalohy/$UPLOADED"
mv "$WORK/web/storage/backup-key.php" "$WORK/backup-key.saved"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Pred sifrovanou' WHERE promenna = 'site_name'"
SAFETY_BEFORE=$(ls "$WORK/web/storage/zalohy" | grep -c predobnovou || true)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
expect "3.28: Backups offer the recovery key field for the .enc backup" "$(grep -c 'name="recovery_key"' "$WORK/response")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$UPLOADED"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
expect "3.28: without the key the encrypted backup is refused clearly and nothing changes" "$(grep -c 'Tahle záloha je zašifrovaná. Zadejte obnovovací klíč' "$WORK/response")/$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" "1/Pred sifrovanou"
WRONG_KEY=$(php -r 'require $argv[1] . "/system/bootstrap.php"; echo Kaleta\Core\RecoveryKey::format(random_bytes(32));' "$WORK/web")
TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$UPLOADED" --data-urlencode "recovery_key=$WRONG_KEY"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
expect "3.28: a wrong key is refused clearly, no safety backup made, nothing changes" "$(grep -c 'Obnovovací klíč k této záloze nepatří' "$WORK/response")/$(ls "$WORK/web/storage/zalohy" | grep -c predobnovou || true)/$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" "1/$SAFETY_BEFORE/Pred sifrovanou"
# N328-2: a damaged or cut-off copy (the right key) is refused while every chunk is checked – before a statement runs, so the
# database is not "reverted" by a whole restore of the safety backup, and that needless safety backup does not stay
for T328_CUT in tampered truncated; do
  T328_BAD="${UPLOADED/kaleta-/kaleta-$T328_CUT-}"
  php -r '$s = file_get_contents($argv[1]); if ($argv[3] === "tampered") { $s[200] = chr(ord($s[200]) ^ 1); } else { $s = substr($s, 0, -40); } file_put_contents($argv[2], $s);' "$WORK/web/storage/zalohy/$UPLOADED" "$WORK/web/storage/zalohy/$T328_BAD" "$T328_CUT"
  TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$T328_BAD" --data-urlencode "recovery_key=$RECOVERY_KEY"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
  expect "3.28 (N328-2): a $T328_CUT encrypted backup is refused, nothing reverted, no safety backup left, nothing changes" "$(grep -c 'Zašifrovaná záloha je poškozená nebo byla změněna\|Zašifrovaná záloha není celá' "$WORK/response")/$(grep -c 'Databáze je zpět ve stavu před obnovou' "$WORK/response")/$(ls "$WORK/web/storage/zalohy" | grep -c predobnovou || true)/$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")/$(ls -a "$WORK/web/storage/zalohy" | grep -c '^\.restore-')" "1/0/$SAFETY_BEFORE/Pred sifrovanou/0"
  rm -f "$WORK/web/storage/zalohy/$T328_BAD"
done
# 3.31.1: the copy being restored is the oldest file and ten local backups are there already – the safety backup made before
# the restore must not push it out (before 3.31.1 the rotation deleted it, and the restore found no backup); the real local
# backups wait aside meanwhile (the later steps count them), ten stand-ins take their place
local_backups() { ls "$WORK/web/storage/zalohy" | grep '^kaleta-' | grep -vc '\.enc$' || true; }
local_names() { ls "$WORK/web/storage/zalohy" | grep '^kaleta-' | grep -v '\.enc$' || true; }
N3311_SOURCE=$(ls -t "$WORK/web/storage/zalohy" | grep '^kaleta-' | grep -v '\.enc$' | head -1)
mkdir -p "$WORK/aside3311"; for f in $(local_names); do mv "$WORK/web/storage/zalohy/$f" "$WORK/aside3311/"; done
for i in 1 2 3 4 5 6 7 8 9 10; do cp "$WORK/aside3311/$N3311_SOURCE" "$WORK/web/storage/zalohy/kaleta-20200101-0000$(printf %02d "$i")-fake3311-$(printf %08d "$i").sql${N3311_SOURCE#*.sql}"; done
# each stand-in a minute apart (fake3311-1 the oldest): with one shared time, "the oldest" was a tie that ls and PHP broke
# differently on Linux (GitHub CI), so the hold checks below watched another file than the one the rotation deletes
for i in 1 2 3 4 5 6 7 8 9 10; do
  touch -t "$(date -v-2d -v+"${i}"M +%Y%m%d%H%M 2>/dev/null || date -d "-2 days +$i minutes" +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*-fake3311-"$(printf %08d "$i")".sql*
done
touch -t "$(date -v-3d +%Y%m%d%H%M 2>/dev/null || date -d '-3 days' +%Y%m%d%H%M)" "$WORK/web/storage/zalohy/$UPLOADED"
expect "3.31.1: ten local backups and an older uploaded copy before the restore" "$(local_backups)/$(ls -tr "$WORK/web/storage/zalohy" | grep '^kaleta-' | head -1)" "10/$UPLOADED"
TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$UPLOADED" --data-urlencode "recovery_key=$(echo "$RECOVERY_KEY" | tr 'A-Z-' 'a-z ')"
expect "3.31.1: the uploaded copy outlives the safety backup, the rotation keeps ten local backups afterwards, no hold left" "$([ -f "$WORK/web/storage/zalohy/$UPLOADED" ] && echo kept)/$(local_backups)/$(ls -a "$WORK/web/storage/zalohy" | grep -c '^\.hold-' || true)" "kept/10/0"
expect "3.28: with the recovery key (any case, spaces) the encrypted backup restores, and the key becomes this site's again" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota <> 'Pred sifrovanou' FROM ka_nastaveni WHERE promenna = 'site_name'")/$(cmp -s "$WORK/web/storage/backup-key.php" "$WORK/backup-key.saved" && echo same || (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo Kaleta\Core\RecoveryKey::load() === Kaleta\Core\RecoveryKey::parse($argv[1]) ? "same" : "other";' -- "$RECOVERY_KEY"))/$(ls -a "$WORK/web/storage/zalohy" | grep -c '^\.restore-')" "1/same/0"
check "3.28: after the restore the copies go on with the same key" 200 "/admin.php?module=settings&tab=backups" "Obnovovací klíč uložen"
check "3.31.1: the Backups tab marks the uploaded copy" 200 "/admin.php?module=settings&tab=backups" "nahraná kopie"
# a backup held by a restore under way (another request) stays when a new backup rotates; a hold left by a dead request expires
N3311_OLDEST=$(ls -tr "$WORK/web/storage/zalohy" | grep '^kaleta-' | grep -v '\.enc$' | head -1)
expect "3.31.1: … the file watched is a stand-in and the one the rotation deletes next (the oldest in PHP's order too)" \
  "$(echo "$N3311_OLDEST" | grep -c -- '-fake3311-')/$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $l = Kaleta\Core\Backup::listLocal(); echo end($l)["soubor"] ?? "";')" "1/$N3311_OLDEST"
N3311_HOLD="$WORK/web/storage/zalohy/.hold-$N3311_OLDEST.$(printf '%012x' 3311)" # one marker per request: .hold-<name>.<token>
touch "$N3311_HOLD"; backup_now
expect "3.31.1: a held backup is not rotated away (the rotation keeps one more meanwhile)" "$([ -f "$WORK/web/storage/zalohy/$N3311_OLDEST" ] && echo kept)/$(local_backups)" "kept/11"
touch -t "$(date -v-2H +%Y%m%d%H%M 2>/dev/null || date -d '-2 hours' +%Y%m%d%H%M)" "$N3311_HOLD"; backup_now
expect "3.31.1: a hold older than an hour no longer protects, and the rotation deletes the expired marker" "$([ -f "$WORK/web/storage/zalohy/$N3311_OLDEST" ] && echo kept || echo gone)/$(local_backups)/$([ -f "$N3311_HOLD" ] && echo marker || echo none)" "gone/10/none"
# review of 3.31.1: two restores of the same file – each holds it with its own marker; the first to finish releases only its own,
# so the file stays held for the second; a name or token from outside the pattern removes nothing
N3311_NEXT=$(ls -tr "$WORK/web/storage/zalohy" | grep '^kaleta-' | grep -v '\.enc$' | head -1)
expect "3.31.1 (review): two restores of one file – the first release leaves the second hold, the second release ends it; a foreign name removes nothing" \
  "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $f = $argv[1]; $a = Kaleta\Core\Backup::hold($f); $b = Kaleta\Core\Backup::hold($f); Kaleta\Core\Backup::release($f, $a);
    $mid = Kaleta\Core\Backup::held(); Kaleta\Core\Backup::release("../" . $f, $b); Kaleta\Core\Backup::release($f, "../x"); $still = count(glob(Kaleta\Core\Backup::FOLDER . "/.hold-*"));
    Kaleta\Core\Backup::release($f, $b); echo ($a !== $b ? "two" : "one"), "/", implode(",", $mid) === $f ? "held" : "free", "/", $still, "/", count(Kaleta\Core\Backup::held()), "/", count(glob(Kaleta\Core\Backup::FOLDER . "/.hold-*"));' -- "$N3311_NEXT")" \
  "two/held/1/0/0"
rm -f "$WORK"/web/storage/zalohy/.hold-*; for f in $(local_names); do rm -f "$WORK/web/storage/zalohy/$f"; done
mv "$WORK"/aside3311/* "$WORK/web/storage/zalohy/"; rmdir "$WORK/aside3311"
rm -f "$WORK/web/storage/zalohy/$UPLOADED" "$WORK/backup-key.saved"
mkdir -p "$WORK/web/media/2026/09" && echo "novy" > "$WORK/web/media/2026/09/novy-soubor.txt"; : > "$WORK/s3/puts.log"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'media_sync_check'"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "cron copies only the new file" "$(cat "$WORK/s3/puts.log")" "PUT /kaleta-zalohy/media/2026/09/novy-soubor.txt 5 signed"
# 3.31.1: a failed off-site copy is the event backup.failed – once per failed upload, with a short text that never carries the
# target's user or password (the server repeats both here); the status says it, System status shows it; paused stays separate
N3311_FAILED=$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.failed'"); N3311_PAUSED=$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.copy_paused'")
sq "UPDATE ka_nastaveni SET hodnota = 'kaleta-odmitnuto' WHERE promenna = 'backup_folder'" > /dev/null
backup_now; backup_now
expect "3.31.1: two failed copies = two backup.failed events (offsite, the file), no secret in them or the status, no paused event" \
  "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.failed' AND severity = 'error' AND data LIKE '%\"offsite\":true%' AND data LIKE '%\"file\":\"kaleta-%'")/$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.failed' AND (message LIKE '%tajne-s3%' OR message LIKE '%AKIDTEST%')")/$(sq "SELECT (hodnota LIKE '%403%') + 2 * (hodnota LIKE '%tajne-s3%' OR hodnota LIKE '%AKIDTEST%') FROM ka_nastaveni WHERE promenna = 'remote_backup_status'")/$(( $(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.failed'") - N3311_FAILED ))/$(( $(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.copy_paused'") - N3311_PAUSED ))" \
  "2/0/1/2/0"
check "3.31.1: System status shows the failed off-site copy" 200 "/admin.php?module=status" "Úložiště nahrání odmítlo (kód 403)"
# review of 3.31.1: a status saved before 3.31.1 holds the whole error (secrets, "|") – System status and get_health (and so the
# heartbeat's problems) mask it when they read it
sq "UPDATE ka_nastaveni SET hodnota = '2026-11-08 03:00|Login failed for AKIDTEST with tajne-s3 | write to ops@client.example' WHERE promenna = 'remote_backup_status'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
R3311_STATUS="$(grep -c 'Login failed for … with … write to …' "$WORK/response" || true)/$(grep -c 'tajne-s3\|AKIDTEST\|ops@client' "$WORK/response" || true)"
mcp get_health '{}' > "$WORK/response"
expect "3.31.1 (review): an error text saved before 3.31.1 is masked when System status and get_health read it" \
  "$R3311_STATUS/$(grep -c 'Login failed for' "$WORK/response")/$(grep -c 'tajne-s3\|AKIDTEST\|ops@client' "$WORK/response")" "1/0/1/0"
echo "novy2" > "$WORK/web/media/2026/09/novy-soubor-2.txt"
for i in 1 2; do sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'media_sync_check'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"; done
expect "3.31.1: the failing media copy is one event when it starts failing, not one per run" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.failed' AND data LIKE '%\"media\":true%'")/$(sq "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(hodnota, '|', 2), '|', -1) LIKE '%403%' FROM ka_nastaveni WHERE promenna = 'remote_media_status'")" "1/1"
sq "UPDATE ka_nastaveni SET hodnota = 'kaleta-zalohy' WHERE promenna = 'backup_folder'" > /dev/null
# a daily backup when something changed, otherwise it waits for the week
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_backups', '1'), ('remote_backup', 'vypnuto'); INSERT INTO ka_protokol (cas, modul, akce) VALUES ('$(site_time)', 'test', 'change')"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*
BEFORE=$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-'); curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "a change since the last backup (older than a day) makes a new automatic one" "$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-')" "$((BEFORE + 1))"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_protokol SET cas = '$(site_time)' - INTERVAL 3 DAY WHERE cas > '$(site_time)' - INTERVAL 3 DAY; UPDATE ka_poptavky SET datum = '$(site_time)' - INTERVAL 3 DAY WHERE datum > '$(site_time)' - INTERVAL 3 DAY"
curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "without a change no new backup before the week is over" "$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-')" "$((BEFORE + 1))"
kill "$S3_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('backup_host', 'backup_test_url', 'backup_password', 'remote_media_status'); UPDATE ka_nastaveni SET hodnota = 'vypnuto' WHERE promenna = 'remote_backup'"

