echo "== 3.35 re-review N2: a custom role with every section needs the acting administrator's fresh proof"
# Roles: a new role with every section is saved only with the current password; one section fewer needs none. Users:
# assigning a role with every section to an editor who lacked one needs the password too. Uses R442_ALL of 2442.
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=roles&action=new"; R450_T=$(csrf)
expect "3.35 re-review N2: the role form asks for the acting administrator's password" "$(grep -c 'name="soucasne"' "$WORK/response")" "1"
r450_role() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$R450_T" -d uroven=1 "$@"; }
r450_id() { sq "SELECT IFNULL((SELECT idr FROM ka_role WHERE nazev = '$1'), 'none')"; }
R450_ALMOST=$(echo "$R442_ALL" | sed 's/moduly\[\]=appearance_area&//')
expect "3.35 re-review N2: every section without the password, or with a wrong one – not saved; one fewer – saved" \
  "$(r450_role -d idr=0 -d nazev=N2all --data "$R442_ALL")|$(r450_role -d idr=0 -d nazev=N2all --data "$R442_ALL" -d soucasne=wrong-password-450)|$(r450_id N2all)|$(r450_role -d idr=0 -d nazev=N2almost --data "$R450_ALMOST")|$([ "$(r450_id N2almost)" != none ] && echo saved)" \
  "200|200|none|302|saved"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
R450_ALMOST_ID=$(r450_id N2almost)
expect "3.35 re-review N2: giving the existing role its last section needs the password too; with it the role is saved" \
  "$(r450_role -d "idr=$R450_ALMOST_ID" -d nazev=N2almost --data "$R442_ALL")|$(sq "SELECT FIND_IN_SET('appearance_area', moduly) > 0 FROM ka_role WHERE idr = $R450_ALMOST_ID")|$(r450_role -d idr=0 -d nazev=N2all --data "$R442_ALL" --data-urlencode "soucasne=$PASSWORD")|$([ "$(r450_id N2all)" != none ] && echo saved)" \
  "200|0|302|saved"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=new"; R450_U=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$R450_U" -d idu=0 -d user=n2editor -d jmeno=N2 -d admin=1 --data-urlencode "password=Another-password-450"
R450_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n2editor'")
r450_user() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$R450_U" -d "idu=$R450_ED" -d user=n2editor -d jmeno=N2 "$@"; }
expect "3.35 re-review N2: assigning the role with every section needs the password; a role with fewer needs none" \
  "$(r450_user -d "admin=r$(r450_id N2all)")|$(sq "SELECT IFNULL(role, 'none') FROM ka_uzivatele WHERE idu = $R450_ED")|$(r450_user -d "admin=r$R450_ALMOST_ID")|$(r450_user -d "admin=r$(r450_id N2all)" --data-urlencode "soucasne=$PASSWORD")|$([ "$(sq "SELECT role FROM ka_uzivatele WHERE idu = $R450_ED")" = "$(r450_id N2all)" ] && echo assigned)" \
  "200|none|302|302|assigned"
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $R450_ED; DELETE FROM ka_uzivatele WHERE idu = $R450_ED; DELETE FROM ka_role WHERE nazev IN ('N2all', 'N2almost'); UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
