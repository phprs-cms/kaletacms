echo "== 3.35 review M2: a passkey-only account is never confirmed by its password in My account"
# Uses the software authenticator of 2300 ($WORK/pk335.php, p335_*). pk414 signs in with a passkey only; once the proofs of its
# passkey sign-in are old, its right current password confirms none of the sensitive changes – only "Confirm with your
# passkey" (Auth::confirmWithPasskey through My account) or a new passkey/backup-code step does (Auth::freshProof).
R414_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk414', '$R414_HASH', 'Passkey Tester 414', 'pk414@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
R414_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk414'")
r414_password() { local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk414 --data-urlencode "password=$PASSWORD"; }
# r414_age <jar>: the proofs of the session are set back to 1970 (verified_at and strong_at in the PHP session file)
r414_age() { local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$1" | tail -1); dir=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
  sed -i.bak -e 's/verified_at|i:[0-9]*;/verified_at|i:1;/' -e 's/strong_at|i:[0-9]*;/strong_at|i:1;/' "$dir/sess_$id" && rm -f "$dir/sess_$id.bak"; grep -c 'verified_at|i:1;' "$dir/sess_$id"; }
r414_row() { sq "SELECT CONCAT(email, '/', passkey_only, '/', pocet_chyb, '/', (SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idu = $R414_USER), '/', (SELECT COUNT(*) FROM ka_api_tokeny WHERE idu = $R414_USER), '/', SHA2(password, 256) = SHA2('$R414_HASH', 256)) FROM ka_uzivatele WHERE idu = $R414_USER"; }
r414_flash() { curl -s -b "$1" -o "$WORK/r414-page" "$B/admin.php?action=account"; grep -c 'your password does not confirm this\|heslo tohle nepotvrdí' "$WORK/r414-page"; }
M1J="$WORK/jar-r414-1"; rm -f "$M1J"
r414_password "$M1J" > /dev/null
P335_CSRF=$(p335_page "$M1J" "?action=account")
expect "3.35 review M2: pk414 registers its passkey and turns passkey only on (with the password – not passkey-only yet)" \
  "$(p335_register "$M1J" "$WORK/pk414-a" 69 --data-urlencode "soucasne=$PASSWORD")|$(p335_account "$M1J" -d co=passkey_only -d passkey_only=1 --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT passkey_only FROM ka_uzivatele WHERE idu = $R414_USER")" "200|200|302|1"
rm -f "$M1J"
M2J="$WORK/jar-r414-2"; rm -f "$M2J"
p335_passkey "$M2J" "$WORK/pk414-a" 5 1 > /dev/null
P335_CSRF=$(p335_page "$M2J" "?action=account")
expect "3.35 review M2: right after the passkey sign-in – no password fields, no confirmation block, the change needs nothing more" \
  "$(grep -c 'id="email-heslo"\|id="token-heslo"\|id="soucasne"' "$WORK/response")|$(grep -c 'id="confirm-passkey"' "$WORK/response")|$(p335_account "$M2J" -d co=profil -d jmeno=Tester -d email=pk414b@example.com)|$(sq "SELECT email FROM ka_uzivatele WHERE idu = $R414_USER")" \
  "0|0|302|pk414b@example.com"
R414_BEFORE=$(r414_row)
expect "3.35 review M2: the proofs of the passkey sign-in are pushed back" "$(r414_age "$M2J")" "1"
P335_CSRF=$(p335_page "$M2J" "?action=account")
expect "3.35 review M2: later My account offers “Confirm with your passkey” at the top and still no password field" \
  "$(grep -c 'id="confirm-passkey"' "$WORK/response")|$(grep -c 'data-klic-potvrdit' "$WORK/response")|$(grep -c 'id="email-heslo"\|id="token-heslo"\|id="soucasne"\|id="klic-heslo"\|id="passkey-only-heslo"\|id="kody-heslo"' "$WORK/response")" "1|1|0"
expect "3.35 review M2: the right password changes nothing – e-mail, password, token, passkey only, backup codes, the app; nothing is counted" \
  "$(p335_account "$M2J" -d co=profil -d jmeno=Tester -d email=evil@example.com --data-urlencode "soucasne=$PASSWORD")|$(r414_flash "$M2J")|$(p335_account "$M2J" -d co=heslo --data-urlencode "soucasne=$PASSWORD" -d nove=another-password-414 -d nove2=another-password-414)|$(p335_account "$M2J" -d co=token_novy -d nazev=x -d access=full --data-urlencode "soucasne=$PASSWORD")|$(p335_account "$M2J" -d co=passkey_only -d passkey_only=0 --data-urlencode "soucasne=$PASSWORD")|$(p335_account "$M2J" -d co=backup_codes_new --data-urlencode "soucasne=$PASSWORD")|$(p335_account "$M2J" -d co=klic_moznosti --data-urlencode "soucasne=$PASSWORD")|$([ "$(r414_row)" = "$R414_BEFORE" ] && echo same)" \
  "302|1|302|302|302|302|403|same"
r414_confirm() { # <jar> <flags> <counter> → HTTP code of "Confirm with your passkey" in My account
  curl -s -b "$1" -c "$1" -o "$WORK/r414-options" -X POST "$B/admin.php?action=account" -d "_csrf=$P335_CSRF" -d krok=passkey_confirm_options
  php "$WORK/pk335.php" assert "$WORK/pk414-a" "$P335_ORIGIN" "$2" "$3" < "$WORK/r414-options" > "$WORK/r414-assertion"
  curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=account" -d "_csrf=$P335_CSRF" -d krok=passkey_confirm --data-urlencode "odpoved@$WORK/r414-assertion"; }
expect "3.35 review M2: a confirmation without user verification is refused and counted" "$(r414_confirm "$M2J" 1 2)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $R414_USER")" "401|1"
expect "3.35 review M2: “Confirm with your passkey” in My account – then the change goes through without a password" \
  "$(r414_confirm "$M2J" 5 3)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $R414_USER AND akce = 'passkey_confirm'")|$(p335_account "$M2J" -d co=profil -d jmeno=Tester -d email=pk414c@example.com)|$(sq "SELECT email FROM ka_uzivatele WHERE idu = $R414_USER")" \
  "200|1|302|pk414c@example.com"
# the same account without passkey only: the password confirms as before
sq "UPDATE ka_uzivatele SET passkey_only = 0, pocet_chyb = 0 WHERE idu = $R414_USER" > /dev/null; r414_age "$M2J" > /dev/null
P335_CSRF=$(p335_page "$M2J" "?action=account")
expect "3.35 review M2: without passkey only the password fields are back and the password confirms the e-mail change" \
  "$(grep -c 'id="email-heslo"' "$WORK/response")|$(p335_account "$M2J" -d co=profil -d jmeno=Tester -d email=pk414d@example.com --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT email FROM ka_uzivatele WHERE idu = $R414_USER")" "1|302|pk414d@example.com"
sq "DELETE FROM ka_uzivatele_klice WHERE idu = $R414_USER; DELETE FROM ka_api_tokeny WHERE idu = $R414_USER; DELETE FROM ka_uzivatele WHERE idu = $R414_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
