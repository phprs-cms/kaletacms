echo "== 3.46 review N1: the administrator who restores a backup older than their account keeps it, signs in and administers"
r194_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R194_DIR="$WORK/web/storage/zalohy"; R194_JAR="$WORK/jar-r194"; rm -f "$R194_JAR"
r194_login() { rm -f "$1"; curl -s -c "$1" -o "$WORK/response" "$B/admin.php"; curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=r194boss --data-urlencode "password=$PASSWORD"; }
r194_admin() { curl -s -b "$1" -o "$WORK/r194-page" -w '%{http_code}' "$B/admin.php?module=settings&tab=backups"; grep -q 'action=restore_backup' "$WORK/r194-page" && echo 1 || echo 0; }

# the backup: the site as it was handed over – the administrators of then
R194_FILE=$(r194_php 'echo Kaleta\Core\Backup::create($db, "r194", false);')
# since then: a new administrator (the new owner) and every administrator of then removed (the suite's own is kept aside)
R194_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('r194boss', '$R194_HASH', 'R194 Boss', 'r194boss@example.cz', 2, 0)" > /dev/null
R194_BOSS=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r194boss'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT $R194_BOSS, ident_modulu FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'admin')" > /dev/null
R194_ACTIVE=$(sq "SELECT COALESCE(GROUP_CONCAT(idu), '0') FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0 AND idu <> $R194_BOSS")
r194_login "$R194_JAR"; proof_fresh "$R194_JAR"
sq "DELETE FROM ka_uzivatele WHERE admin = 2 AND idu <> $R194_BOSS" > /dev/null
curl -s -b "$R194_JAR" -c "$R194_JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"
curl -s -b "$R194_JAR" -c "$R194_JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$(csrf)" -d "soubor=$R194_FILE"
expect "3.46 review N1: after the restore the new owner's account is there – active, administrator, today's password – and the backup's administrators are blocked" \
  "$(sq "SELECT CONCAT(admin, '/', blokovat, '/', password = '$R194_HASH') FROM ka_uzivatele WHERE idu = $R194_BOSS")|$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0 AND idu <> $R194_BOSS")" "2/0/1|0"
expect "3.46 review N1: the restoring session goes on, and a new sign-in of the new owner works and opens the administrator's Backups" \
  "$(r194_admin "$R194_JAR")|$(r194_login "$R194_JAR-2"; r194_admin "$R194_JAR-2")" "2001|2001"

# back as it was: the suite's administrators active again, the new owner gone
sq "UPDATE ka_uzivatele SET blokovat = 0 WHERE idu IN ($R194_ACTIVE); DELETE FROM ka_uzivatele WHERE idu = $R194_BOSS" > /dev/null
rm -f "$R194_DIR/$R194_FILE" "$R194_JAR" "$R194_JAR-2"
curl -s -c "$JAR" -b "$JAR" -o "$WORK/response" "$B/admin.php"
if ! grep -q 'action=logout' "$WORK/response"; then curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"; fi
