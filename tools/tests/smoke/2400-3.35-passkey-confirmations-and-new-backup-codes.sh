echo "== 3.35: new backup codes in My account, and the passkey confirmation of a full Claude connection"
# Owner decisions 11 Nov 2026: an account with a passkey and without the authenticator app creates new backup codes in My
# account (a fresh confirmation – a passkey or backup-code step a few minutes ago, or the current password; the codes are
# shown once and the old ones stop working). The OAuth consent of a new full connection accepts Auth::recentlyVerified(): a
# passkey-only account approves right after its passkey sign-in, and later confirms with its passkey (Auth::confirmWithPasskey,
# markVerified) where others type the current password. Uses the software authenticator of 2300 ($WORK/pk335.php, p335_*).
Q340_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk340', '$Q340_HASH', 'Passkey Tester 340', 'pk340@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0)" > /dev/null
Q340_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk340'")
q340_password() { # <jar> → HTTP code of the password step
  local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk340 --data-urlencode "password=$PASSWORD"; }
q340_codes() { sq "SELECT SHA2(IFNULL(totp_zalozni, ''), 256) FROM ka_uzivatele WHERE idu = $Q340_USER"; }
# q340_age <jar>: every proof of the session is long gone – verified_at and strong_at in the PHP session file are set back to 1970
q340_age() { local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$1" | tail -1); dir=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
  sed -i.bak -e 's/verified_at|i:[0-9]*;/verified_at|i:1;/' -e 's/strong_at|i:[0-9]*;/strong_at|i:1;/' "$dir/sess_$id" && rm -f "$dir/sess_$id.bak"; grep -c 'verified_at|i:1;' "$dir/sess_$id"; }

# a password sign-in (no second step yet) and the first passkey
Q1="$WORK/jar-q340-1"; rm -f "$Q1"
q340_password "$Q1" > /dev/null
P335_CSRF=$(p335_page "$Q1" "?action=account")
expect "3.35: no passkey yet – My account offers no new backup codes" "$(grep -c 'name="co" value="backup_codes_new"' "$WORK/response")" "0"
expect "3.35: the first passkey of pk340 is registered with the password" "$(p335_register "$Q1" "$WORK/pk340-a" 69 --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idu = $Q340_USER AND discoverable = 1")" "200|200|1"
p335_page "$Q1" "?action=account" > /dev/null # the codes of the first passkey are shown here once
Q340_OLD="q340o-$(openssl rand -hex 2)k"
sq "UPDATE ka_uzivatele SET totp_zalozni = JSON_ARRAY(SHA2('$Q340_OLD', 256)) WHERE idu = $Q340_USER" > /dev/null
P335_CSRF=$(p335_page "$Q1" "?action=account")
expect "3.35: a passkey and no app – My account offers “New backup codes”, asking for the password (a password sign-in is not fresh)" \
  "$(grep -c 'name="co" value="backup_codes_new"' "$WORK/response")|$(grep -c 'id="kody-heslo"' "$WORK/response")|$(grep -c 'Create new backup codes? The old ones will stop working.' "$WORK/response")" "1|1|1"
Q340_BEFORE=$(q340_codes)
expect "3.35: without the password nothing changes; a wrong one counts like a sign-in" \
  "$(p335_account "$Q1" -d co=backup_codes_new)|$([ "$(q340_codes)" = "$Q340_BEFORE" ] && echo same)|$(p335_account "$Q1" -d co=backup_codes_new -d soucasne=wrong-password-1)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $Q340_USER")|$([ "$(q340_codes)" = "$Q340_BEFORE" ] && echo same)" \
  "302|same|302|1|same"
expect "3.35: with the password – eight new codes shown once, the old code gone, the change log says so" \
  "$(p335_account "$Q1" -d co=backup_codes_new --data-urlencode "soucasne=$PASSWORD")|$(grep -c 'Your new backup codes' "$WORK/response")|$(grep -o '[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}' "$WORK/response" | wc -l | tr -d ' ')|$(sq "SELECT JSON_LENGTH(totp_zalozni, '\$.codes') FROM ka_uzivatele WHERE idu = $Q340_USER")|$(sq "SELECT LOCATE(SHA2('$Q340_OLD', 256), totp_zalozni) FROM ka_uzivatele WHERE idu = $Q340_USER")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $Q340_USER AND akce = 'backup_codes_new'")|$(p335_page "$Q1" "?action=account" > /dev/null; grep -c '[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}' "$WORK/response")" \
  "200|1|8|8|0|1|0"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $Q340_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
Q2="$WORK/jar-q340-2"; rm -f "$Q2"
expect "3.35: the old backup code no longer completes the sign-in" "$(q340_password "$Q2")|$(p335_code "$Q2" "$Q340_OLD")|$(p335_signed_in "$Q2")" "200|401|0"
# fresh after a passkey sign-in: no password needed for a few minutes
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $Q340_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
Q3="$WORK/jar-q340-3"; rm -f "$Q3"
p335_passkey "$Q3" "$WORK/pk340-a" 5 1 > /dev/null
P335_CSRF=$(p335_page "$Q3" "?action=account")
expect "3.35: right after the passkey sign-in new codes need no password (fresh confirmation)" \
  "$(grep -c 'id="kody-heslo"' "$WORK/response")|$(p335_account "$Q3" -d co=backup_codes_new)|$(grep -c 'Your new backup codes' "$WORK/response")" "0|200|1"
# with the app, codes come from turning the app off and on – the button is not offered and the action is refused
Q340_SECRET=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::newSecret();' -- "$ROOT")
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '$Q340_SECRET' WHERE idu = $Q340_USER" > /dev/null
P335_CSRF=$(p335_page "$Q3" "?action=account"); Q340_BEFORE=$(q340_codes)
expect "3.35: an account with the authenticator app gets no “New backup codes” – refused even when posted" \
  "$(grep -c 'name="co" value="backup_codes_new"' "$WORK/response")|$(p335_account "$Q3" -d co=backup_codes_new --data-urlencode "soucasne=$PASSWORD")|$([ "$(q340_codes)" = "$Q340_BEFORE" ] && echo same)" "0|302|same"
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '', passkey_only = 1 WHERE idu = $Q340_USER" > /dev/null

# the OAuth consent of a passkey-only account
q340_request() { # <jar> <state>: a new authorize request; prints its nonce, the consent page stays in $WORK/response
  local to; to=$(curl -s -b "$1" -c "$1" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$S335A_CLIENT&redirect_uri=$S335A_URI&code_challenge=$S335A_CHALLENGE&code_challenge_method=S256&state=$2")
  to=$(printf %s "$to" | grep -o 'request=[a-f0-9]*' | sed 's/request=//' || true); curl -s -b "$1" -c "$1" -o "$WORK/response" "$B/admin.php?action=oauth&request=$to"; printf %s "$to"; }
q340_consent() { local jar="$1" n="$2"; shift 2; curl -s -b "$jar" -c "$jar" -o "$WORK/consent" -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$Q340_CSRF" -d "request=$n" -d povolit=1 "$@"; }
Q4="$WORK/jar-q340-4"; rm -f "$Q4"
p335_passkey "$Q4" "$WORK/pk340-a" 5 2 > /dev/null
Q340_N1=$(q340_request "$Q4" fresh340); Q340_CSRF=$(csrf)
expect "3.35: a passkey-only account right after its passkey sign-in – no password field, full access in one click" \
  "$(grep -c 'id="oauth-heslo"' "$WORK/response")|$(grep -c 'data-klic-potvrdit' "$WORK/response")|$(q340_consent "$Q4" "$Q340_N1" -d access=full | grep -c '^302 https://claude.ai/api/mcp/auth_callback?code=')" "0|0|1"
expect "3.35: the proofs of the passkey sign-in are pushed back" "$(q340_age "$Q4")" "1"
Q340_N2=$(q340_request "$Q4" aged340); Q340_CSRF=$(csrf)
expect "3.35: later the consent offers “Confirm with your passkey” instead of a password field (with klice.js)" \
  "$(grep -c 'id="oauth-heslo"' "$WORK/response")|$(grep -c 'data-klic-potvrdit' "$WORK/response")|$(grep -c "data-klice=\"[^\"]*request=$Q340_N2\"" "$WORK/response")|$(grep -c 'image/klice.js' "$WORK/response")" "0|1|1|1"
expect "3.35: full access without the confirmation – refused on the page, the choice kept, asked for the passkey" \
  "$(q340_consent "$Q4" "$Q340_N2" -d access=full)|$(grep -c 'confirm with your passkey to allow it' "$WORK/consent")|$(grep -c 'value="full" checked' "$WORK/consent")" "403 |1|1"
q340_confirm() { # <jar> <nonce> <flags> <counter> → HTTP code of the passkey confirmation (JSON in $WORK/response)
  curl -s -b "$1" -c "$1" -o "$WORK/q340-options" -X POST "$B/admin.php?action=oauth&request=$2" -d "_csrf=$Q340_CSRF" -d krok=passkey_confirm_options
  php "$WORK/pk335.php" assert "$WORK/pk340-a" "$P335_ORIGIN" "$3" "$4" < "$WORK/q340-options" > "$WORK/q340-assertion"
  curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=oauth&request=$2" -d "_csrf=$Q340_CSRF" -d krok=passkey_confirm --data-urlencode "odpoved@$WORK/q340-assertion"; }
expect "3.35: a confirmation without user verification is refused and counted like a wrong password" \
  "$(q340_confirm "$Q4" "$Q340_N2" 1 3)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $Q340_USER")|$(q340_consent "$Q4" "$Q340_N2" -d access=full | cut -c1-3)" "401|1|403"
expect "3.35: the challenge names the account's own passkey; the confirmation with it returns to this consent" \
  "$(q340_confirm "$Q4" "$Q340_N2" 5 4)|$(grep -cF -e "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["id"];' -- "$WORK/pk340-a")" "$WORK/q340-options")|$(grep -c "\"kam\":\"[^\"]*request=$Q340_N2\"" "$WORK/response")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $Q340_USER AND akce = 'passkey_confirm'")" "200|1|1|1"
expect "3.35: after the passkey confirmation full access is allowed; the request waited for it" \
  "$(q340_consent "$Q4" "$Q340_N2" -d access=full | grep -c '^302 https://claude.ai/api/mcp/auth_callback?code=.*state=aged340')" "1"
expect "3.35: the same assertion again is refused – the challenge was used once" \
  "$(curl -s -b "$Q4" -c "$Q4" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$Q340_CSRF" -d krok=passkey_confirm --data-urlencode "odpoved@$WORK/q340-assertion")" "401"
# an account with a passkey that may still use its password gets both
sq "UPDATE ka_uzivatele SET passkey_only = 0, pocet_chyb = 0 WHERE idu = $Q340_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
q340_age "$Q4" > /dev/null
Q340_N3=$(q340_request "$Q4" both340)
expect "3.35: an account with a passkey and its password usable – the password field and the passkey button" \
  "$(grep -c 'id="oauth-heslo"' "$WORK/response")|$(grep -c 'data-klic-potvrdit' "$WORK/response")" "1|1"
sq "DELETE FROM ka_uzivatele_klice WHERE idu = $Q340_USER; DELETE FROM ka_api_tokeny WHERE idu = $Q340_USER; DELETE FROM ka_uzivatele WHERE idu = $Q340_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
