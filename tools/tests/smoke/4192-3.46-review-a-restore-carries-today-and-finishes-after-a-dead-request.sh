echo "== 3.46 review M1–M4, L1, L2: a restore carries today's roles, e-mails, rules, signing key and sent newsletters; a dead restore is finished by the next request; undoing a restore takes nothing for good"
r192_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $s = $app->settings(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R192_DIR="$WORK/web/storage/zalohy"; R192_NOW=$(site_time); R192_HEX() { php -r 'echo bin2hex(random_bytes((int) $argv[1]));' -- "${1:-32}"; }
R192_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R192_KEYS="'signin_passkey_only'"
sq "DROP TABLE IF EXISTS ka_r192_backup; CREATE TABLE ka_r192_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($R192_KEYS)" > /dev/null
r192_restore() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$(csrf)" -d "soubor=$1"; }

# --- the state the backup holds: an administrator about to be demoted, an account's old e-mail, a scheduled newsletter, a news
# item not announced yet, a person erased once (ten days ago) whose new enquiry came after that
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('r192demote', 'x', 'R192 Demote', 'r192demote@example.cz', 2, 0), ('r192mail', 'x', 'R192 Mail', 'r192-old@example.cz', 1, 0)" > /dev/null
R192_DEMOTE=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r192demote'"); R192_MAIL=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r192mail'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R192_DEMOTE, 'settings'), ($R192_DEMOTE, 'pages')" > /dev/null
sq "INSERT INTO ka_newsletters (subject, intro, status, scheduled_at, created) VALUES ('R192 newsletter', 'x', 'scheduled', '$(site_time '-1 hour')', '$R192_NOW');
  INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$R192_NOW', 'R192 form', 'r192-twice@example.cz', '[[\"Message\",\"R192 again\"]]')" > /dev/null
R192_NEWS=$(sq "SELECT MAX(id) FROM ka_newsletters WHERE subject = 'R192 newsletter'"); R192_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'R192 form'")
R192_ARTICLE=$(sq "SELECT COALESCE(MAX(idc), 0) FROM ka_novinky"); sq "UPDATE ka_novinky SET oznameno = NULL WHERE idc = $R192_ARTICLE" > /dev/null
r192_php 'Kaleta\Core\RestoreLedger::record($db, "erased", "r192-twice@example.cz");' > /dev/null
sq "UPDATE ka_restore_ledger SET created_at = '$(site_time '-10 days')' WHERE kind = 'erased' AND hash = '$(r192_php 'echo Kaleta\Core\RestoreLedger::fingerprint($db, "r192-twice@example.cz");')'" > /dev/null
R192_MADE=$(r192_php 'echo Kaleta\Core\Backup::create($db, "r192", false);')
R192_FILE="kaleta-$(site_time '-2 minutes' Ymd-His)-r192-$(R192_HEX 4)-$(r192_php 'echo Kaleta\Core\Backup::siteMark($db);').sql.gz"
mv "$R192_DIR/$R192_MADE" "$R192_DIR/$R192_FILE"; touch -t "$(site_time '-2 minutes' YmdHi)" "$R192_DIR/$R192_FILE"
expect "3.46 review L8/L6: the backup's first line names when and under which key; its name carries the site's mark" \
  "$(gzip -dc "$R192_DIR/$R192_FILE" | head -1 | grep -cE '^-- Kaleta [^ ]+ - database backup [0-9T:+-]+ key [a-f0-9]{16}$')|$(printf %s "$R192_FILE" | grep -cE -- '-s[a-f0-9]{8}\.sql\.gz$')" "1|1"

# --- after the backup: erased again, demoted, a new address, the passkey-only rule switched on, a newsletter sent, the news announced, a new
# signing key, and an account (with a token) made since
r192_php 'Kaleta\Core\PersonalData::erase($app, "r192-twice@example.cz");' > /dev/null
R192_FP=$(r192_php 'echo Kaleta\Core\RestoreLedger::fingerprint($db, "r192-twice@example.cz");')
expect "3.46 review M1: the second erasure moves the ledger's record forward" "$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'erased' AND hash = '$R192_FP' AND created_at > '$(site_time '-2 minutes')'")" "1"
sq "UPDATE ka_uzivatele SET admin = 0 WHERE idu = $R192_DEMOTE; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $R192_DEMOTE; INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R192_DEMOTE, 'news');
  UPDATE ka_uzivatele SET email = 'r192-new@example.cz' WHERE idu = $R192_MAIL; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('signin_passkey_only', '1');
  UPDATE ka_newsletters SET status = 'sent', started_at = '$R192_NOW', finished_at = '$R192_NOW', recipients = 3, sent_count = 3 WHERE id = $R192_NEWS; UPDATE ka_novinky SET oznameno = '$R192_NOW' WHERE idc = $R192_ARTICLE;
  INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('r192new', 'x', 'R192 New', 'r192new@example.cz', 1, 0)" > /dev/null
R192_NEW=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r192new'"); R192_NEWTOKEN=$(R192_HEX)
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($R192_NEW, 'R192 new', '$R192_NEWTOKEN', '$R192_NOW')" > /dev/null
R192_OLDKEY=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups" # the administrator's button keeps this session signed in
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=new_signing_key" -d "_csrf=$(csrf)"
sq "SELECT IF(COUNT(*) > 0, '\"rotated\"', 'none') FROM ka_events WHERE type = 'security.signing_key_rotated' AND created_at >= '$(site_time '-1 minute')'" > "$WORK/r192-rotate"
R192_KEY=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")
expect "3.46 review L1: a new signing key – both keys are in the restore ledger" \
  "$(cat "$WORK/r192-rotate")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'site' AND hash IN (SHA2('$R192_OLDKEY', 256), SHA2('$R192_KEY', 256))")" '"rotated"|2'

# --- the restore of the backup: today's roles, rights, e-mail, rule and key; the newsletter and the news stay sent; the second erasure applies
r192_restore "$R192_FILE"
expect "3.46 review M3: the demoted administrator stays an author with today's sections, the account keeps today's e-mail, the passkey-only rule stays on" \
  "$(sq "SELECT admin FROM ka_uzivatele WHERE idu = $R192_DEMOTE")|$(sq "SELECT GROUP_CONCAT(ident_modulu ORDER BY ident_modulu) FROM ka_uzivatele_prava WHERE fk_id_user = $R192_DEMOTE")|$(sq "SELECT email FROM ka_uzivatele WHERE idu = $R192_MAIL")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'signin_passkey_only'")" \
  "0|news|r192-new@example.cz|1"
expect "3.46 review M3: the signing key is today's, not the backup's" "$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")" = "$R192_KEY" ] && echo today)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.signing_key')) FROM ka_events WHERE type = 'backup.restored' ORDER BY id DESC LIMIT 1")" "today|today"
expect "3.46 review M4: the newsletter the backup had scheduled stays sent; the news announced since is not announced again" \
  "$(sq "SELECT CONCAT(status, '/', sent_count) FROM ka_newsletters WHERE id = $R192_NEWS")|$(sq "SELECT oznameno IS NOT NULL FROM ka_novinky WHERE idc = $R192_ARTICLE")" "sent/3|1"
expect "3.46 review M1: the person erased a second time after the backup is erased again – the enquiry of before the backup is gone" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $R192_ENQ")" "0"

# --- L2: undoing the restore with its safety backup brings back what the restore took away – the account made since, unblocked, with its token
R192_SAFETY=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.safety_backup')) FROM ka_events WHERE type = 'backup.restored' ORDER BY id DESC LIMIT 1")
expect "3.46 review L2: after the restore the account made since is gone with its token (taken away by the restore, not removed)" \
  "$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE idu = $R192_NEW")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'token' AND hash = '$R192_NEWTOKEN'")" "0|0"
r192_restore "$R192_SAFETY"
expect "3.46 review L2: undoing it – the account is back unblocked with its token, the administrator who restores can sign in, nothing was remembered as revoked" \
  "$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $R192_NEW")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = '$R192_NEWTOKEN'")|$(sq "SELECT blokovat FROM ka_uzivatele WHERE idu = $R192_ADMIN")|$(sq "SELECT COUNT(*) FROM ka_restore_ledger WHERE kind IN ('token', 'account_lost', 'token_lost') AND hash IN ('$R192_NEWTOKEN', SHA2('account:$R192_NEW', 256))")" \
  "0|1|0|0"

# --- M2: a restore whose request died after the tables were replaced – the next request finishes what must not come back
R192_DEAD=$(r192_php 'echo Kaleta\Core\Backup::create($db, "r192dead", false);')
R192_DEADTOKEN=$(R192_HEX); sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($R192_ADMIN, 'R192 dead', '$R192_DEADTOKEN', '$R192_NOW')" > /dev/null
R192_DEAD2=$(r192_php 'echo Kaleta\Core\Backup::create($db, "r192dead", false);') # a backup that holds the token
sq "DELETE FROM ka_api_tokeny WHERE otisk = '$R192_DEADTOKEN'" > /dev/null # revoked today
r192_php '$file = $a[0]; $made = Kaleta\Core\RestoreLedger::backupTime($file); $today = Kaleta\Core\RestoreLedger::capture($db, $made, (int) $a[1]);
  Kaleta\Core\RestoreLedger::begin($today, $file, $a[2], $made); Kaleta\Core\Backup::restore($db, $file); Kaleta\Core\RestoreLedger::restored($made); /* the request dies here */' "$R192_DEAD2" "$R192_ADMIN" "$R192_DEAD" > /dev/null
expect "3.46 review M2: the request died – the revoked token is back in the tables and the state of today waits in storage/zalohy (0600)" \
  "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = '$R192_DEADTOKEN'")|$(php -r 'echo is_file($argv[1]) ? decoct(fileperms($argv[1]) & 0777) : "none";' -- "$R192_DIR/.restore-state")" "1|600"
R192_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$B/")
expect "3.46 review M2: the next request (a visitor's) finishes the reapply first – the token is gone, the state file too, the event says it was finished later" \
  "$R192_CODE|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = '$R192_DEADTOKEN'")|$([ -f "$R192_DIR/.restore-state" ] && echo left || echo gone)|$(sq "SELECT JSON_EXTRACT(data, '$.finished_later') FROM ka_events WHERE type = 'backup.restored' ORDER BY id DESC LIMIT 1")" \
  "200|0|gone|true"
# while another request holds the restore (its lock and state), no token works and cron sends nothing
r192_php '$today = Kaleta\Core\RestoreLedger::capture($db); Kaleta\Core\RestoreLedger::lock($db); Kaleta\Core\RestoreLedger::begin($today, $a[0], $a[0], null); sleep(6); Kaleta\Core\RestoreLedger::end();' "$R192_DEAD" > /dev/null & R192_PID=$!
for i in $(seq 1 30); do [ -f "$R192_DIR/.restore-state" ] && break; sleep 0.2; done
expect "3.46 review M2: during a restore under way, /mcp with a token and the cron address answer 503; a visitor's page answers" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H 'Authorization: Bearer x')|$(curl -s -o /dev/null -w '%{http_code}' "$B/ulohy")|$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "503|503|200"
await_pids "$R192_PID" || true

# clean up
rm -f "$R192_DIR/$R192_FILE" "$R192_DIR/$R192_DEAD" "$R192_DIR/$R192_DEAD2" "$R192_DIR/.restore-state"
sq "DELETE FROM ka_uzivatele WHERE user IN ('r192demote', 'r192mail', 'r192new'); DELETE FROM ka_newsletters WHERE subject = 'R192 newsletter'; DELETE FROM ka_poptavky WHERE formular = 'R192 form';
  DELETE FROM ka_api_tokeny WHERE nazev IN ('R192 new', 'R192 dead');
  DELETE FROM ka_nastaveni WHERE promenna IN ($R192_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_r192_backup; DROP TABLE ka_r192_backup" > /dev/null
