echo "== 3.35 B1: approving full access on the OAuth consent needs a fresh proof"
# Owner decision 11 Nov 2026: a NEW Claude connection with full access ("Can also publish") is approved only with the
# current password, or within Auth::VERIFIED_WINDOW after a sign-in or a confirmed password (the session marker
# verified_at). Drafts and read stay one click. A refused consent keeps its request for another try.
S335A_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('s335a', '$S335A_HASH', 'Tester S335A', 's335a@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0);
  DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
S335A_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 's335a'")
S335A_JAR="$WORK/jar-s335a"; rm -f "$S335A_JAR"
curl -s -c "$S335A_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$S335A_JAR" -c "$S335A_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=s335a --data-urlencode "password=$PASSWORD"
S335A_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude 335\",\"redirect_uris\":[\"$S335A_URI\"],\"token_endpoint_auth_method\":\"none\"}"
S335A_CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
S335A_VERIFIER="$(printf 'w%.0s' $(seq 1 50))"; S335A_CHALLENGE=$(printf %s "$S335A_VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
# s335a_request <state>: a new authorize request of the signed-in s335a; prints its nonce and leaves the consent page in $WORK/response
s335a_request() { local to; to=$(curl -s -b "$S335A_JAR" -c "$S335A_JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$S335A_CLIENT&redirect_uri=$S335A_URI&code_challenge=$S335A_CHALLENGE&code_challenge_method=S256&state=$1")
  to=$(printf %s "$to" | grep -o 'request=[a-f0-9]*' | sed 's/request=//' || true); curl -s -b "$S335A_JAR" -c "$S335A_JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$to"; printf %s "$to"; }
# s335a_consent <nonce> [fields…]: posts the consent; prints the status and the address it redirects to
s335a_consent() { local n="$1"; shift; curl -s -b "$S335A_JAR" -c "$S335A_JAR" -o "$WORK/consent" -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$S335A_CSRF" -d "request=$n" -d povolit=1 "$@"; }
# s335a_age: the last proof of the session is long gone – verified_at in the PHP session file is set back to 1970
s335a_age() { local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$S335A_JAR" | tail -1); dir=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
  sed -i.bak 's/verified_at|i:[0-9]*;/verified_at|i:1;/' "$dir/sess_$id" && rm -f "$dir/sess_$id.bak"; grep -c 'verified_at|i:1;' "$dir/sess_$id"; }
s335a_errors() { sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $S335A_USER"; }
S335A_N1=$(s335a_request fresh); S335A_CSRF=$(csrf)
expect "3.35 B1: right after a sign-in the consent asks no password; full access is allowed in one click" \
  "$(grep -c 'id="oauth-heslo" name="soucasne"' "$WORK/response")|$(s335a_consent "$S335A_N1" -d access=full | grep -c '^302 https://claude.ai/api/mcp/auth_callback?code=')" "0|1"
expect "3.35 B1: the marker lives in the session as verified_at, and an old proof is pushed back" "$(s335a_age)" "1"
S335A_N2=$(s335a_request aged); S335A_CSRF=$(csrf)
expect "3.35 B1: without a fresh proof the consent shows the password field, with the hint" \
  "$(grep -c 'id="oauth-heslo" name="soucasne"' "$WORK/response")|$(grep -c 'Needed only for “Can also publish”' "$WORK/response")" "1|1"
expect "3.35 B1: full access without the password, or with a wrong one, issues no code and says why; the wrong one counts like a sign-in" \
  "$(s335a_consent "$S335A_N2" -d access=full)|$(grep -c 'enter your current password to allow it' "$WORK/consent")|$(grep -c 'value="full" checked' "$WORK/consent")|$(s335a_consent "$S335A_N2" -d access=full -d soucasne=wrong-password-1)|$(s335a_errors)" \
  "403 |1|1|403 |1"
expect "3.35 B1: the refused request waits for another try – the right password then allows full access" \
  "$(s335a_consent "$S335A_N2" -d access=full --data-urlencode "soucasne=$PASSWORD" | grep -c '^302 https://claude.ai/api/mcp/auth_callback?code=.*state=aged')|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $S335A_USER AND akce = 'app_connect' AND popis LIKE '%(full%'")" "1|2"
S335A_N3=$(s335a_request again)
expect "3.35 B1: a right password is a fresh proof too – the next full consent asks nothing" \
  "$(grep -c 'id="oauth-heslo"' "$WORK/response")|$(s335a_consent "$S335A_N3" -d access=full | grep -c '^302 .*code=')" "0|1"
s335a_age > /dev/null
S335A_N4=$(s335a_request drafts); S335A_CSRF=$(csrf)
S335A_N5=$(s335a_request read)
expect "3.35 B1: drafts and read stay one click without any password" \
  "$(s335a_consent "$S335A_N4" -d access=drafts | grep -c '^302 .*code=')|$(s335a_consent "$S335A_N5" -d access=read | grep -c '^302 .*code=')|$(s335a_consent "$(s335a_request plain)" | grep -c '^302 .*code=')" "1|1|1"
# the code of a full consent gives a full connection; refresh tokens of connections that exist are untouched by the rule
s335a_age > /dev/null; S335A_N6=$(s335a_request token)
S335A_CODE=$(s335a_consent "$S335A_N6" -d access=full --data-urlencode "soucasne=$PASSWORD" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$S335A_CODE" -d "redirect_uri=$S335A_URI" -d "client_id=$S335A_CLIENT" -d "code_verifier=$S335A_VERIFIER"
S335A_REFRESH=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "3.35 B1: the issued connection is full and its refresh token works" \
  "$(sq "SELECT GROUP_CONCAT(DISTINCT access) FROM ka_api_tokeny WHERE idu = $S335A_USER AND druh <> 'token' AND klient = '$S335A_CLIENT' AND access = 'full'")|$(curl -s -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$S335A_REFRESH" -d "client_id=$S335A_CLIENT" | grep -c '"access_token"')" "full|1"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $S335A_USER" > /dev/null
