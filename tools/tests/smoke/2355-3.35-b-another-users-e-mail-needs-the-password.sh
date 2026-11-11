echo "== 3.35 B2: changing another user's e-mail in Users needs the administrator's own password"
# Owner decision 11 Nov 2026: the e-mail is where the password-reset and invitation links of the account go, so changing it
# in Users is confirmed like a new password or a turned-off second factor (3.31.2) – Auth::confirmPassword, nothing saved
# without it. The same address in other letter case is no change; every other field needs no password.
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('s335b', '$(php -r 'echo password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);')', 'Tester S335B', 's335b@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0)" > /dev/null
S335B_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 's335b'")
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$S335B_USER"; TOKEN=$(csrf)
s335b_save() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$S335B_USER" -d user=s335b -d admin=0 "$@"; }
s335b_row() { sq "SELECT CONCAT(email, '/', jmeno) FROM ka_uzivatele WHERE idu = $S335B_USER"; }
expect "3.35 B2: the form says the administrator's password is needed for another user's e-mail" \
  "$(grep -c 'změnu e-mailu tohoto uživatele\|change this user.s e-mail' "$WORK/response")" "1"
expect "3.35 B2: a changed e-mail without the password, or with a wrong one, saves nothing of the form and says why; the wrong one counts" \
  "$(s335b_save -d jmeno=Changed -d email=attacker@example.com)|$(grep -c 'Nic se neuložilo\|Nothing was saved' "$WORK/response")|$(s335b_row)|$(s335b_save -d jmeno=Changed -d email=attacker@example.com -d soucasne=wrong-password-1)|$(s335b_row)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE user = 'admin'")" \
  "200|1|s335b@example.com/Tester S335B|200|s335b@example.com/Tester S335B|1"
expect "3.35 B2: the same address in other letter case, and other changes, need no password" \
  "$(s335b_save -d jmeno=Renamed -d email=S335B@Example.com)|$(s335b_row)" "302|S335B@Example.com/Renamed"
expect "3.35 B2: with the administrator's password the e-mail changes, and the new-password link then goes to the new address" \
  "$(s335b_save -d jmeno=Renamed -d email=s335b-new@example.com --data-urlencode "soucasne=$PASSWORD")|$(s335b_row)|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=users&action=password_link" -d "_csrf=$TOKEN" -d "idu=$S335B_USER")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 's335b-new@example.com'")" \
  "302|s335b-new@example.com/Renamed|302|1"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'; DELETE FROM ka_posta WHERE komu = 's335b-new@example.com'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $S335B_USER; DELETE FROM ka_uzivatele WHERE idu = $S335B_USER" > /dev/null
