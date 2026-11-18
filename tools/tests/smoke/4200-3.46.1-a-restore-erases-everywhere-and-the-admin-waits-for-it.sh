echo "== 3.46.1 re-audit M1, M2, M3, M5, L1, L2, L5: a restore erases a person found by a form field, a merge note or a queued body, deletes an application its applicant deleted, keeps today's access settings; the administration waits for a restore; a page's head code needs the proof"
r200_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R200_DIR="$WORK/web/storage/zalohy"; R200_NOW=$(site_time); R200_HEX() { php -r 'echo bin2hex(random_bytes((int) $argv[1]));' -- "${1:-32}"; }
R200_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R200_KEYS="'whistleblowing_readers', 'claude_destructive', 'tasks_token', 'webhook_url'"
sq "DROP TABLE IF EXISTS ka_r200_backup; CREATE TABLE ka_r200_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($R200_KEYS)" > /dev/null
r200_restore() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; curl -s -b "$JAR" -c "$JAR" -o "$WORK/r200-restored" -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$(csrf)" -d "soubor=$1"; }

# --- the backup: an enquiry whose e-mail is an ordinary text field (no sender), a customer record merged with an address,
# a webhook delivery kept for a retry that names a third address, an application in the talent pool, and the access settings
R200_TOKEN=$(R200_HEX)
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$R200_NOW', 'R200 form', '', '[[\"Phone\",\"123 456\"],[\"E-mail\",\" R200-Field@example.cz \"]]');
  INSERT INTO ka_contacts (name, email, created_at, updated_at) VALUES ('R200 colleague', 'r200-colleague@example.cz', '$R200_NOW', '$R200_NOW');
  INSERT INTO ka_webhook_deliveries (event, url, body, next_attempt, created) VALUES ('r200', 'https://example.invalid/r200', '{\"event\":\"r200\",\"person\":{\"email\":\"r200-queued@example.cz\"}}', '$(site_time '+1 hour')', '$R200_NOW');
  INSERT INTO ka_applications (received_at, name, email, data, status_at, last_activity_at, pool_consent_at, pool_until, pool_token, pool_token_until) VALUES ('$R200_NOW', 'R200 applicant', 'r200-applicant@example.cz', '[]', '$R200_NOW', '$R200_NOW', '$R200_NOW', '$(site_date '+300 days')', SHA2('$R200_TOKEN', 256), '$(site_time '+5 days')');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('whistleblowing_readers', '$R200_ADMIN,9999'), ('claude_destructive', '1'), ('tasks_token', 'r200-old-token'), ('webhook_url', 'https://example.invalid/r200-old')" > /dev/null
R200_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'R200 form'"); R200_CONTACT=$(sq "SELECT MAX(id) FROM ka_contacts WHERE name = 'R200 colleague'")
sq "INSERT INTO ka_contact_activities (contact_id, kind, text, happened_at, created_at, merged_email) VALUES ($R200_CONTACT, 'note', 'Merged with the contact #1 (r200-merged@example.cz).', '$R200_NOW', '$R200_NOW', 'r200-merged@example.cz')" > /dev/null
R200_DELIVERY=$(sq "SELECT MAX(id) FROM ka_webhook_deliveries WHERE event = 'r200'"); R200_APP=$(sq "SELECT MAX(id) FROM ka_applications WHERE name = 'R200 applicant'")
R200_MADE=$(r200_php 'echo Kaleta\Core\Backup::create($db, "r200", false);')
R200_FILE="kaleta-$(site_time '-2 minutes' Ymd-His)-r200-$(R200_HEX 4)-$(r200_php 'echo Kaleta\Core\Backup::siteMark($db);').sql.gz"
mv "$R200_DIR/$R200_MADE" "$R200_DIR/$R200_FILE"; touch -t "$(site_time '-2 minutes' YmdHi)" "$R200_DIR/$R200_FILE"

# --- after the backup: the three people are erased (each found only through a field, a merge note, a queued body), the applicant
# deletes the application through the talent-pool link, a reader is taken off the channel, Claude's deletions and the cron secret change
r200_php 'foreach (["r200-field@example.cz", "r200-merged@example.cz", "r200-queued@example.cz"] as $e) { Kaleta\Core\PersonalData::erase($app, $e); } echo Kaleta\Core\TalentPool::remove($app, $a[0]) ? "removed" : "kept";' "$R200_TOKEN" > "$WORK/r200-erase"
expect "3.46.1 re-audit M1: erased today – the field-only enquiry, the merged customer record and the queued body; the applicant's deletion is on record" \
  "$(cat "$WORK/r200-erase")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $R200_ENQ")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = $R200_CONTACT")|$(sq "SELECT body IS NULL FROM ka_webhook_deliveries WHERE id = $R200_DELIVERY")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $R200_APP")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'application_deleted' AND hash = '$(r200_php 'echo Kaleta\Core\RestoreLedger::applicationFingerprint($db, (int) $a[0], "r200-applicant@example.cz");' "$R200_APP")'")" \
  "removed|0|0|1|0|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('whistleblowing_readers', '9999'), ('claude_destructive', '0'), ('tasks_token', 'r200-new-token'), ('webhook_url', 'https://example.invalid/r200-new')" > /dev/null

# --- the restore: everything the erasure found is erased again, the application is deleted again, the access settings are today's
r200_restore "$R200_FILE"
curl -s -b "$JAR" -o "$WORK/r200-backups" "$B/admin.php?module=settings&tab=backups"
expect "3.46.1 re-audit M1: after the restore the field-only enquiry, the merged customer record and the application are gone again, the delivery has no body" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $R200_ENQ")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = $R200_CONTACT")|$(sq "SELECT COALESCE((SELECT body IS NULL FROM ka_webhook_deliveries WHERE id = $R200_DELIVERY), 1)")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $R200_APP")" \
  "0|0|1|0"
expect "3.46.1 re-audit M5: the readers of the whistleblowing channel, Claude's rule and the cron secret are today's; the webhook address is the backup's, and the message says to check it" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_readers'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_destructive'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_url'")|$(grep -q -e 'Webhook addresses, the off-site backup target' -e 'Adresy webhooků, cíl záloh mimo server' "$WORK/r200-backups" && echo 1 || echo 0)" \
  "9999|0|r200-new-token|https://example.invalid/r200-old|1"

# --- M2: while another request restores, every administration request waits (503) and reads no account; afterwards it works
r200_php '$today = Kaleta\Core\RestoreLedger::capture($db); Kaleta\Core\RestoreLedger::lock($db); Kaleta\Core\RestoreLedger::begin($today, $a[0], $a[0], null); sleep(5); Kaleta\Core\RestoreLedger::end();' "$R200_FILE" > /dev/null & R200_PID=$!
for i in $(seq 1 30); do [ -f "$R200_DIR/.restore-state" ] && break; sleep 0.2; done
expect "3.46.1 re-audit M2: during a restore under way the administration answers 503 (a page and a script's request), the sign-in page too" \
  "$(curl -s -b "$JAR" -o "$WORK/r200-busy" -w '%{http_code}' "$B/admin.php")|$(grep -c -e 'A restore of the database is under way' -e 'Probíhá obnova databáze' "$WORK/r200-busy" || true)|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$B/admin.php?module=pages")|$(curl -s -o /dev/null -w '%{http_code}' "$B/admin.php")" \
  "503|1|503|503"
await_pids "$R200_PID" || true
expect "3.46.1 re-audit M2: the restore over, the administration answers again" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php")" "200"

# --- M2: a dead restore finished by an administration request – the account row read before is not the backup's
R200_PASS="R200-$(R200_HEX 6)"
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('r200admin', '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$R200_PASS")', 'R200 Admin', 'r200admin@example.cz', 2, 0)" > /dev/null
R200_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r200admin'"); R200_JAR="$WORK/r200-jar"; rm -f "$R200_JAR"
curl -s -b "$R200_JAR" -c "$R200_JAR" -o "$WORK/r200-login" "$B/admin.php"
curl -s -b "$R200_JAR" -c "$R200_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$WORK/r200-login" | head -1 | sed 's/.*value="//;s/"//')" -d "user=r200admin" --data-urlencode "password=$R200_PASS"
curl -s -b "$R200_JAR" -c "$R200_JAR" -o "$WORK/r200-in" "$B/admin.php"; R200_SIGNED=$(grep -q 'class="login-pole"' "$WORK/r200-in" && echo 1 || echo 0)
R200_DEAD=$(r200_php 'echo Kaleta\Core\Backup::create($db, "r200dead", false);') # holds the administrator unblocked
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE idu = $R200_USER" > /dev/null # blocked today
R200_SAFE=$(r200_php 'echo Kaleta\Core\Backup::create($db, "r200safe", false);')
r200_php '$file = $a[0]; $made = Kaleta\Core\RestoreLedger::backupTime($file); $today = Kaleta\Core\RestoreLedger::capture($db, $made, (int) $a[1]);
  Kaleta\Core\RestoreLedger::begin($today, $file, $a[2], $made); Kaleta\Core\Backup::restore($db, $file); Kaleta\Core\RestoreLedger::restored($made); /* the request dies here */' "$R200_DEAD" "$R200_ADMIN" "$R200_SAFE" > /dev/null
touch "$R200_DIR/.restore-$(R200_HEX 6).sql" # the decrypted copy a dead restore of an encrypted backup leaves (L2)
expect "3.46.1 re-audit M2: the blocked administrator was signed in; the dead restore put the backup's unblocked row back" \
  "$R200_SIGNED|$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $R200_USER")" "0|0"
curl -s -b "$R200_JAR" -c "$R200_JAR" -o "$WORK/r200-after" "$B/admin.php"
expect "3.46.1 re-audit M2, L2: the administration request finishes the restore and then reads today's row – the blocked account sees the sign-in page; the decrypted copy is gone" \
  "$(grep -q 'class="login-pole"' "$WORK/r200-after" && echo 1 || echo 0)|$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $R200_USER")|$([ -f "$R200_DIR/.restore-state" ] && echo left || echo gone)|$(ls -a "$R200_DIR" | grep -c '^\.restore-[a-f0-9]*\.sql' || true)" \
  "1|1|gone|0"

# --- L5: "Retry failed transfers" restarts only what failed, not what a restore stopped
sq "INSERT INTO ka_odber_fronta (email, akce, dalsi, chyba, vytvoreno) VALUES ('r200-stopped@example.cz', 'pridat', NULL, '$(r200_php 'echo Kaleta\Core\RestoreLedger::STOPPED;')', '$R200_NOW'), ('r200-failed@example.cz', 'pridat', NULL, 'HTTP 500', '$R200_NOW')" > /dev/null
r200_php 'Kaleta\Core\Newsletter::retry($app);' > /dev/null
expect "3.46.1 re-audit L5: a retry restarts the failed transfer and leaves the one a restore stopped" \
  "$(sq "SELECT GROUP_CONCAT(IF(dalsi IS NULL, 'stopped', 'retried') ORDER BY email) FROM ka_odber_fronta WHERE email LIKE 'r200-%'")" "retried,stopped"

# --- M3: a change of a page's own head code needs the recent proof; saving the page with the code as it is does not ask
R200_PAGE=$(sq "SELECT MIN(ids) FROM ka_stranky WHERE smazano IS NULL AND stavba IS NULL")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$R200_PAGE"
proof_age "$JAR"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$R200_PAGE"; R200_T=$(csrf)
R200_TITLE=$(sq "SELECT titulek FROM ka_stranky WHERE ids = $R200_PAGE"); R200_TEXT=$(sq "SELECT text FROM ka_stranky WHERE ids = $R200_PAGE"); R200_CODE=$(sq "SELECT COALESCE(kod_hlavicky, '') FROM ka_stranky WHERE ids = $R200_PAGE")
r200_save() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/r200-save" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$R200_T" -d "ids=$R200_PAGE" --data-urlencode "titulek=$R200_TITLE" --data-urlencode "text=$R200_TEXT" -d "zobrazit=1" --data-urlencode "kod_hlavicky=$1"; }
R200_SAME=$(r200_save "$R200_CODE")
R200_CHANGED=$(r200_save '<script src="https://example.invalid/r200.js"></script>')$(grep -c 'id="confirm-proof"' "$WORK/r200-save" || true)
expect "3.46.1 re-audit M3: the same head code saves without asking; a new one asks \"Confirm it is you\" and is not stored" \
  "$R200_SAME|$R200_CHANGED|$(sq "SELECT COALESCE(kod_hlavicky, '') LIKE '%r200.js%' FROM ka_stranky WHERE ids = $R200_PAGE")" "302|4031|0"

# clean up (the proof is renewed by the suite before the next section)
rm -f "$R200_DIR/$R200_FILE" "$R200_DIR/$R200_DEAD" "$R200_DIR/$R200_SAFE" "$R200_DIR/.restore-state" "$R200_JAR"
sq "DELETE FROM ka_uzivatele WHERE user = 'r200admin'; DELETE FROM ka_odber_fronta WHERE email LIKE 'r200-%'; DELETE FROM ka_webhook_deliveries WHERE event = 'r200'; DELETE FROM ka_contacts WHERE name = 'R200 colleague';
  DELETE FROM ka_nastaveni WHERE promenna IN ($R200_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_r200_backup; DROP TABLE ka_r200_backup" > /dev/null
