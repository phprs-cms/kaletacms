echo "== 3.35 review L7: a new administrator needs the acting administrator's fresh proof"
# Users: a new account at the administrator level, raising an editor to it and giving every section by hand are saved only
# with the acting administrator's current password; a new editor or author needs none.
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=new"; R442_T=$(csrf)
expect "3.35 review L7: the form of a new user asks for the acting administrator's password" "$(grep -c 'name="soucasne"' "$WORK/response")" "1"
r442_save() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$R442_T" -d jmeno=L7 --data-urlencode "password=Another-password-442" "$@"; }
r442_level() { sq "SELECT IFNULL((SELECT admin FROM ka_uzivatele WHERE user = '$1'), 'none')"; }
expect "3.35 review L7: a new administrator without the password, or with a wrong one – not created" \
  "$(r442_save -d idu=0 -d user=l7admin -d admin=2)|$(r442_save -d idu=0 -d user=l7admin -d admin=2 -d soucasne=wrong-password-442)|$(r442_level l7admin)" "200|200|none"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
expect "3.35 review L7: a new editor needs no password; a new administrator with it is created" \
  "$(r442_save -d idu=0 -d user=l7editor -d admin=1)|$(r442_level l7editor)|$(r442_save -d idu=0 -d user=l7admin -d admin=2 --data-urlencode "soucasne=$PASSWORD")|$(r442_level l7admin)" "302|1|302|2"
R442_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'l7editor'")
r442_edit() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$R442_T" -d jmeno=L7 -d "idu=$R442_ED" -d user=l7editor "$@"; }
R442_ALL=$(php -r 'require $argv[1] . "/system/bootstrap.php"; foreach (array_keys(Kaleta\Admin\Modules\Roles::configurable()) as $k) echo "moduly[]=$k&";' "$ROOT")
expect "3.35 review L7: raising the editor to administrator and giving every section by hand need the password" \
  "$(r442_edit -d admin=2)|$(r442_level l7editor)|$(r442_edit -d admin=1 -d rucne=1 --data "$R442_ALL")|$(sq "SELECT COUNT(*) FROM ka_uzivatele_prava WHERE fk_id_user = $R442_ED AND ident_modulu = 'appearance_area'")|$(r442_edit -d admin=2 --data-urlencode "soucasne=$PASSWORD")|$(r442_level l7editor)" \
  "200|1|200|0|302|2"
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user IN ('l7admin', 'l7editor')); DELETE FROM ka_uzivatele WHERE user IN ('l7admin', 'l7editor'); UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
