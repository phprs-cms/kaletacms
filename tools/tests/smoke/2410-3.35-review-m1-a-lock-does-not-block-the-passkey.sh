echo "== 3.35 review M1: a lock after wrong passwords does not block a valid passkey"
# Uses the software authenticator of 2300 ($WORK/pk335.php, p335_page, p335_message). The account pk410 gets a passkey;
# then its lock is set as ten wrong passwords would set it. The password stays locked; a valid passkey signs in (without a
# password, as the second step, and as the confirmation of a signed-in session) and ends the lock; a refused passkey counts.
R410_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk410', '$R410_HASH', 'Passkey Tester 410', 'pk410@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
R410_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk410'")
r410_password() { # <jar> [password] → HTTP code of the password step
  local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk410 --data-urlencode "password=${2:-$PASSWORD}"; }
r410_lock() { sq "UPDATE ka_uzivatele SET pocet_chyb = 0, zamceno_do = '$(php -r 'echo date("Y-m-d H:i:s", time() + 900);')' WHERE idu = $R410_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null; }
r410_state() { sq "SELECT CONCAT(zamceno_do IS NOT NULL AND zamceno_do > NOW(), '/', pocet_chyb) FROM ka_uzivatele WHERE idu = $R410_USER"; }
R1="$WORK/jar-r410-1"; rm -f "$R1"
r410_password "$R1" > /dev/null
P335_CSRF=$(p335_page "$R1" "?action=account")
expect "3.35 review M1: pk410 registers its passkey" "$(p335_register "$R1" "$WORK/pk410-a" 69 --data-urlencode "soucasne=$PASSWORD")" "200|200"
rm -f "$R1"

r410_lock
R2="$WORK/jar-r410-2"; rm -f "$R2"
expect "3.35 review M1: a locked account – even the right password gets the one answer, the password step stays locked" \
  "$(r410_password "$R2")|$(grep -c 'temporarily locked\|dočasně zamčený' "$WORK/response")|$(p335_signed_in "$R2")" "401|1|0"
expect "3.35 review M1: a refused passkey of the locked account – the one answer, counted on the same counter" \
  "$(p335_passkey "$R2" "$WORK/pk410-a" 1 1)|$([ "$(p335_message)" = "$P335_FAILED" ] && echo same)|$(r410_state)" "401|same|1/1"
expect "3.35 review M1: a valid passkey with user verification signs in without a password and ends the lock" \
  "$(p335_passkey "$R2" "$WORK/pk410-a" 5 2)|$(p335_signed_in "$R2")|$(r410_state)" "200|1|0/0"

# the second step: locked after the password (wrong codes) – the passkey still completes it, a code does not
r410_unlock() { sq "UPDATE ka_uzivatele SET zamceno_do = NULL, pocet_chyb = 0 WHERE idu = $R410_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null; }
r410_unlock; R3="$WORK/jar-r410-3"; rm -f "$R3"
r410_password "$R3" > /dev/null; r410_lock
expect "3.35 review M1: locked while the second step waits – a backup code is refused as before" \
  "$(p335_code "$R3" "abcde-fghij")|$(grep -c 'temporarily locked\|dočasně zamčený' "$WORK/response")" "401|1"
r410_unlock; r410_password "$R3" > /dev/null; r410_lock
t=$(csrf); curl -s -b "$R3" -c "$R3" -o "$WORK/r410-options" -X POST "$B/admin.php" -d "_csrf=$t" -d krok=klic_moznosti
php "$WORK/pk335.php" assert "$WORK/pk410-a" "$P335_ORIGIN" 5 3 < "$WORK/r410-options" > "$WORK/r410-assertion"
expect "3.35 review M1: locked while the second step waits – the passkey completes the sign-in and ends the lock" \
  "$(curl -s -b "$R3" -c "$R3" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=klic --data-urlencode "odpoved@$WORK/r410-assertion")|$(p335_signed_in "$R3")|$(r410_state)" "200|1|0/0"

# the confirmation of a signed-in session (the OAuth consent's "Confirm with your passkey")
r410_lock
curl -s -b "$R3" -c "$R3" -o "$WORK/response" "$B/admin.php?action=account"; t=$(csrf)
curl -s -b "$R3" -c "$R3" -o "$WORK/r410-options" -X POST "$B/admin.php?action=oauth" -d "_csrf=$t" -d krok=passkey_confirm_options
php "$WORK/pk335.php" assert "$WORK/pk410-a" "$P335_ORIGIN" 5 4 < "$WORK/r410-options" > "$WORK/r410-assertion"
expect "3.35 review M1: a locked account confirms with its passkey – the lock ends" \
  "$(curl -s -b "$R3" -c "$R3" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$t" -d krok=passkey_confirm --data-urlencode "odpoved@$WORK/r410-assertion")|$(r410_state)" "200|0/0"

# a blocked account stays closed to its passkey
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE idu = $R410_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
R4="$WORK/jar-r410-4"; rm -f "$R4"
expect "3.35 review M1: a blocked account never signs in with its passkey" "$(p335_passkey "$R4" "$WORK/pk410-a" 5 5)|$([ "$(p335_message)" = "$P335_FAILED" ] && echo same)|$(p335_signed_in "$R4")" "401|same|0"
sq "DELETE FROM ka_uzivatele_klice WHERE idu = $R410_USER; DELETE FROM ka_uzivatele WHERE idu = $R410_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
