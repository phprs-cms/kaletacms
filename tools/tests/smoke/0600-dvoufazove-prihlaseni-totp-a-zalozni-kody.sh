echo "== dvoufázové přihlášení (TOTP a záložní kódy)"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP', totp_zalozni = '[\"$(php -r 'echo hash("sha256", "abcde-12345");')\"]' WHERE user = 'autor'"
sign_in_2fa() { # prihlas2fa <jar> → vrátí kód odpovědi na zadání druhého kroku <kod>
  local jar="$1" t; t=$(curl -s -c "$jar" -b "$jar" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$t" -d user=autor --data-urlencode "password=$PASSWORD"
  grep -q 'name="kod"' "$WORK/response" || echo "bez-druheho-kroku"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod -d "kod=$2"
}
expect "špatný kód z aplikace neprojde" "$(sign_in_2fa "$WORK/jar6" 000000)" "401"
TOTP_CODE=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code("JBSWY3DPEHPK3PXP", intdiv(time(), 30));' -- "$ROOT")
expect "přihlášení s kódem z aplikace (TOTP)" "$(sign_in_2fa "$WORK/jar7" "$TOTP_CODE")" "302"
# 3.15 (N18): the same code again – a code seen over a shoulder or by a phishing page – is refused while it is still in its window
expect "3.15 N18: a code from the app signs in only once" "$(sign_in_2fa "$WORK/jar12" "$TOTP_CODE")|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'totp' AND cil = (SELECT idu FROM ka_uzivatele WHERE user = 'autor')")" "401|1"
expect "záložní kód projde" "$(sign_in_2fa "$WORK/jar8" abcde-12345)" "302"
expect "záložní kód jde použít jen jednou" "$(sign_in_2fa "$WORK/jar9" abcde-12345)" "401"
# 3.3.2 (N7): a correct password does not reset the count of wrong codes – the per-account lock stays reachable
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'autor'"
sign_in_2fa "$WORK/jar10" 111111 > /dev/null; sign_in_2fa "$WORK/jar11" 222222 > /dev/null
expect "3.3.2: wrong codes add up across sign-ins with the right password" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pocet_chyb FROM ka_uzivatele WHERE user = 'autor'")" "2"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'autor'"
# 3.3.3 (N56): a stolen session alone adds no passkey and moves no e-mail – both need the current password; the old address hears of a change
account_post() { curl -s -b "$WORK/jar7" -c "$WORK/jar7" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=account" -d "_csrf=$N56_CSRF" "$@"; }
curl -s -b "$WORK/jar7" -c "$WORK/jar7" -o "$WORK/response" "$B/admin.php?action=account"; N56_CSRF=$(csrf)
grep -q 'id="klic-heslo"' "$WORK/response" && grep -q 'id="email-heslo"' "$WORK/response" && echo "  ok     3.3.3: My account asks for the password next to the e-mail and the passkey" || { echo "  CHYBA  My account: password fields for the e-mail and the passkey"; ERRORS=$((ERRORS+1)); }
expect "3.3.3: a passkey challenge only with the current password" "$(account_post -d co=klic_moznosti)|$(account_post -d co=klic_moznosti -d soucasne=wrong-password-1)|$(account_post -d co=klic_moznosti --data-urlencode "soucasne=$PASSWORD")|$(grep -c '"challenge"' "$WORK/response")" "403|403|200|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET email = 'autor-puvodni@example.cz', jazyk = '' WHERE user = 'autor'; DELETE FROM ka_posta WHERE komu = 'autor-puvodni@example.cz'"
account_post -d co=profil -d jmeno=Autor -d email=utocnik@example.cz > /dev/null
account_post -d co=profil -d jmeno=Autor -d email=utocnik@example.cz -d soucasne=wrong-password-1 > /dev/null
expect "3.3.3: without the current password the e-mail stays" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT email FROM ka_uzivatele WHERE user = 'autor'")" "autor-puvodni@example.cz"
account_post -d co=profil -d jmeno=Autor-jmeno -d email=autor-puvodni@example.cz > /dev/null
expect "3.3.3: other details save without the password while the e-mail stays the same" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(jmeno, '|', email) FROM ka_uzivatele WHERE user = 'autor'")" "Autor-jmeno|autor-puvodni@example.cz"
account_post -d co=profil -d jmeno=Autor -d email=autor-novy@example.cz --data-urlencode "soucasne=$PASSWORD" > /dev/null
expect "3.3.3: with the current password the e-mail changes and the old address gets a notice" \
  "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT email FROM ka_uzivatele WHERE user = 'autor'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'autor-puvodni@example.cz' AND predmet LIKE 'E-mail va%'))")" "autor-novy@example.cz|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET totp_tajemstvi = '', totp_zalozni = NULL WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')"

