echo "== 3.19.1: the open findings of the daily security audits"
# N83: the installer left config.php readable only by the site; System status warns when it is not and fixes it with one click
# (the mode right after the installation is checked at the installer; later tests rewrite config.php with sed)
chmod 0644 "$WORK/web/config.php"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"; TOKEN=$(csrf)
grep -q 'config.php může číst každý uživatel serveru (práva 0644)' "$WORK/response" && grep -q 'action=config_permissions' "$WORK/response" \
  && echo "  ok     3.19.1 (N83): System status warns about a world-readable config.php and offers to fix it" || { echo "  CHYBA  3.19.1 (N83): no warning about config.php"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=config_permissions" -d "_csrf=$TOKEN"
expect "3.19.1 (N83): one click makes config.php 600, recorded in the change log" "$(config_mode)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'config.php permissions'")" "600|1"
# N75: a sign-in starts a new form token – the token of the session before the sign-in is refused afterwards
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('n75-admin', '$(php -r 'echo password_hash("N75-heslo-dlouhe", PASSWORD_DEFAULT);')', 'Admin N75', 2, '$(site_time)', '$(site_time)')" > /dev/null
JAR75="$WORK/cookies-n75.txt"
curl -s -c "$JAR75" -o "$WORK/response" "$B/admin.php"; TOKEN75_BEFORE=$(csrf)
curl -s -b "$JAR75" -c "$JAR75" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN75_BEFORE" -d user=n75-admin -d password=N75-heslo-dlouhe
curl -s -b "$JAR75" -c "$JAR75" -o "$WORK/response" "$B/admin.php"; TOKEN75_AFTER=$(csrf)
N75_OLD=$(curl -s -b "$JAR75" -c "$JAR75" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=hide_first_steps" -d "_csrf=$TOKEN75_BEFORE")
N75_NEW=$(curl -s -b "$JAR75" -c "$JAR75" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=hide_first_steps" -d "_csrf=$TOKEN75_AFTER")
expect "3.19.1 (N75): the form token changes at sign-in; the one from before is refused, the new one works" \
  "$([ -n "$TOKEN75_BEFORE" ] && [ -n "$TOKEN75_AFTER" ] && [ "$TOKEN75_BEFORE" != "$TOKEN75_AFTER" ] && echo rotated)|$N75_OLD|$N75_NEW" "rotated|400|302"
sq "DELETE FROM ka_uzivatele WHERE user = 'n75-admin'" > /dev/null
# N81: NO_BACKSLASH_ESCAPES in the session – Kaleta's own sql_mode makes the backup whole again, an empty backup is refused
N81=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db(); $pdo = $db->pdo();
  $nbe = fn () => $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, \",NO_BACKSLASH_ESCAPES\")");
  $nbe(); $pdo->exec("SET sql_mode = " . Kaleta\Core\Db::SQL_MODE);
  $mode = str_contains((string) $db->value("SELECT @@SESSION.sql_mode"), "NO_BACKSLASH_ESCAPES") ? "nbe" : "clean";
  $file = Kaleta\Core\Backup::create($db, "n81"); $path = (string) Kaleta\Core\Backup::path($file);
  $tables = substr_count((string) (str_ends_with($path, ".gz") ? gzdecode((string) file_get_contents($path)) : file_get_contents($path)), "\nCREATE TABLE ");
  @unlink($path);
  $nbe(); try { @unlink(Kaleta\Core\Backup::FOLDER . "/" . Kaleta\Core\Backup::create($db, "n81")); $empty = "made"; } catch (RuntimeException) { $empty = "refused"; }
  $pdo->exec("SET sql_mode = " . Kaleta\Core\Db::SQL_MODE);
  $emptyFile = "kaleta-20260101-000000-n81empty-0000abcd.sql";
  file_put_contents(Kaleta\Core\Backup::FOLDER . "/" . $emptyFile, "-- Kaleta 3.19.0 - database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\nSET FOREIGN_KEY_CHECKS = 1;\n");
  try { Kaleta\Core\Backup::restore($db, $emptyFile); $restore = "restored"; } catch (RuntimeException) { $restore = "refused"; }
  @unlink(Kaleta\Core\Backup::FOLDER . "/" . $emptyFile);
  echo $mode, "|", $tables > 50 ? "tables" : $tables, "|", $empty, "|", $restore;')
expect "3.19.1 (N81): without NO_BACKSLASH_ESCAPES the backup has every table; under it the backup is refused, and an empty backup does not restore" "$N81" "clean|tables|refused|refused"
# N82: restoring and deleting a backup are recorded – the restore's record is written after it, into the restored database
backups_page; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$TOKEN"
N82_FILE=$(ls -t "$WORK/web/storage/zalohy" | grep -m1 'rucni' || true)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$N82_FILE"
N82_SAFETY=$(ls -t "$WORK/web/storage/zalohy" | grep -m1 'predobnovou' || true)
expect "3.19.1 (N82): the restore is in the change log and the events of the restored database, with the file and the safety backup" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'backup restored' AND popis = '$N82_FILE (safety backup: $N82_SAFETY)'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.restored' AND data LIKE '%$N82_SAFETY%'")" "1|1"
backups_page; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=delete_backup" -d "_csrf=$TOKEN" -d "soubor=$N82_SAFETY"
expect "3.19.1 (N82): deleting a backup is in the change log and the events" \
  "$([ ! -f "$WORK/web/storage/zalohy/$N82_SAFETY" ] && echo gone)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'backup deleted' AND popis = '$N82_SAFETY'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'backup.deleted' AND data LIKE '%$N82_SAFETY%'")" "gone|1|1"
# N84: a new signing key withdraws a shared preview link; the stored secrets stay readable whatever the state of the encryption key
n84() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $db = $app->db(); $s = $app->settings();
  echo $argv[1] === "key" ? Kaleta\Core\Preview::key($db, $s, "web", 60) : (Kaleta\Core\Preview::verify($db, $s, "web", $argv[2]) ? "valid" : "withdrawn") . "|" . Kaleta\Core\Vault::state($s);' "$@"); }
KEY84=$(n84 key); STATE84=$(n84 check "$KEY84"); SECRET84=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")
backups_page; TOKEN=$(csrf)
grep -q 'action=new_signing_key' "$WORK/response" && grep -q 'Vytvořit nový podpisový klíč? Okamžitě přestane fungovat: každý sdílený odkaz na náhled konceptu' "$WORK/response" \
  && echo "  ok     3.19.1 (N84): the Backups tab offers a new signing key and says what stops working" || { echo "  CHYBA  3.19.1 (N84): no signing key action"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -L -X POST "$B/admin.php?module=settings&action=new_signing_key" -d "_csrf=$TOKEN"
expect "3.19.1 (N84): the preview link was valid and is withdrawn by the new key, which is logged" \
  "${STATE84%%|*}|$(n84 check "$KEY84" | cut -d'|' -f1)|$([ "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")" != "$SECRET84" ] && echo new)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'new signing key'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'security.signing_key_rotated'")" \
  "valid|withdrawn|new|1|1"
# N88: a rollback whose copy back fails keeps the originals, records the files and keeps the maintenance page, however old the lock
mkdir -p "$WORK/web/storage/cache/aktualizace-n88-puvodni/system"
printf 'old' > "$WORK/web/storage/cache/aktualizace-n88-puvodni/system/n88.txt"; printf 'new' > "$WORK/web/system/n88.txt"; chmod 0444 "$WORK/web/system/n88.txt"
N88=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $u = new Kaleta\Core\Updater($app->settings());
  echo Kaleta\Core\Language::runWith("en", fn () => (new ReflectionMethod($u, "rollBack"))->invoke($u, ["system/n88.txt"], KALETA_ROOT . "/storage/cache/aktualizace-n88-puvodni", $app->db(), "9.9.9") ?? "null", "admin-");')
touch -t 202601010000 "$WORK/web/storage/udrzba.lock"
expect "3.19.1 (N88): the message names the file and the folder, the originals stay, the event lists them" \
  "$([[ "$N88" == "1 file(s) could not be put back (system/n88.txt)"*"storage/cache/aktualizace-n88-puvodni"* ]] && echo said)|$(cat "$WORK/web/storage/cache/aktualizace-n88-puvodni/system/n88.txt")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.restore_failed' AND data LIKE '%system/n88.txt%' AND data LIKE '%aktualizace-n88-puvodni%'")" \
  "said|old|1"
expect "3.19.1 (N88): visitors keep the maintenance page (a lock older than 10 minutes too), the administration opens and System status says what to do" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$B/")|$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=status")|$(grep -c '<strong>Vrácení aktualizace' "$WORK/response")" "503|200|1"
rm -f "$WORK/web/storage/udrzba.lock" "$WORK/web/system/n88.txt"; rm -rf "$WORK/web/storage/cache/aktualizace-n88-puvodni"
expect "3.19.1 (N88): without the lock the site answers again" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"
