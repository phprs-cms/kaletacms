echo "== OAuth pro konektor Claude"
curl -s -o "$WORK/response" -D "$WORK/hlavicky" -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}'
grep -qi 'www-authenticate: Bearer resource_metadata="http://127.0.0.1:[0-9]*/.well-known/oauth-protected-resource"' "$WORK/hlavicky" && echo "  ok     MCP bez tokenu odkáže na metadata OAuth" || { echo "  CHYBA  WWW-Authenticate u MCP"; ERRORS=$((ERRORS+1)); }
check "metadata chráněného zdroje" 200 "/.well-known/oauth-protected-resource" '"authorization_servers"'
check "metadata autorizačního serveru" 200 "/.well-known/oauth-authorization-server" '"code_challenge_methods_supported":\["S256"\]'
REDIRECT_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude\",\"redirect_uris\":[\"$REDIRECT_URI\"],\"token_endpoint_auth_method\":\"none\"}"
CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$CLIENT" ] && echo "  ok     dynamická registrace klienta" || { echo "  CHYBA  registrace klienta"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "registrace odmítne http adresu návratu" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["http://zly.example/cb"]}')" 400
VERIFIER="$(printf 'v%.0s' $(seq 1 50))"; CHALLENGE=$(printf %s "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
expect "cizí adresa návratu se nepřesměruje" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=https://zly.example/&code_challenge=$CHALLENGE&code_challenge_method=S256")" 400
# 3.3.2 (N8): a request without the code flow or PKCE is answered here, never redirected to the registered address
expect "3.3.2 OAuth: an error before consent is a page, not a redirect" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=token&client_id=$CLIENT&redirect_uri=$REDIRECT_URI")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=x")" "400 |400 "
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=xyz&scope=mcp")
# 3.3.4 (N14): the consent page names the request it belongs to; the form sends that nonce back
oauth_nonce() { printf %s "$1" | grep -o 'request=[a-f0-9]*' | sed 's/request=//' || true; }
# oauth_allow <authorize query> [POST fields…]: a sign-in request, then the consent for exactly that request
oauth_allow() { local to; to=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$1"); shift
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d "request=$(oauth_nonce "$to")" "$@"; }
case "$code" in "302 "*"action=oauth&request="*) echo "  ok     přihlášení vede na souhlas v administraci";; *) echo "  CHYBA  authorize: $code"; ERRORS=$((ERRORS+1));; esac
OAUTH_NONCE=$(oauth_nonce "$code")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/hlavicky" "$B/admin.php?action=oauth"; grep -q 'Povolit přístup' "$WORK/response" && grep -q "name=\"request\" value=\"$OAUTH_NONCE\"" "$WORK/response" && echo "  ok     stránka souhlasu" || { echo "  CHYBA  stránka souhlasu"; ERRORS=$((ERRORS+1)); }
grep -qi "form-action 'self' https://claude.ai;" "$WORK/hlavicky" && ! grep -qi "x-kaleta-form-action" "$WORK/hlavicky" && echo "  ok     CSP souhlasu povolí návrat do aplikace (form-action)" || { echo "  CHYBA  CSP form-action na stránce souhlasu"; grep -i "content-security" "$WORK/hlavicky"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d "request=$OAUTH_NONCE" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
case "$REDIRECT" in "$REDIRECT_URI?code="*"state=xyz"*) echo "  ok     souhlas vrátí kód a state do aplikace";; *) echo "  CHYBA  návrat po souhlasu: $REDIRECT"; ERRORS=$((ERRORS+1));; esac
expect "špatný code_verifier (PKCE) neprojde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d code_verifier=spatny-overovac-spatny-overovac-spatny-overovac)" 400
REDIRECT=$(oauth_allow "response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=abc" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
ACCESS_TOKEN=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true); REFRESH_TOKEN=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$ACCESS_TOKEN" ] && [ -n "$REFRESH_TOKEN" ] && echo "  ok     výměna kódu za tokeny (PKCE)" || { echo "  CHYBA  token endpoint"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "kód jde použít jen jednou" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER")" 400
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACCESS_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q 'builder_schema' "$WORK/response" && echo "  ok     MCP s přístupovým tokenem z OAuth" || { echo "  CHYBA  MCP s tokenem OAuth"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "obnovovací token nejde použít k MCP" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $REFRESH_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" 401
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT"
grep -q '"access_token"' "$WORK/response" && echo "  ok     obnova tokenu" || { echo "  CHYBA  obnova tokenu"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.3.4 (N13): the refresh token is exchanged for a new one; a retry with the old one within REFRESH_GRACE gets the same
# new pair (it used to be refused at once – the reuse after the grace period is tested in the 3.3.4 section)
REFRESHED=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "obnovovací token se po použití vymění" "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = SHA2('$REFRESH_TOKEN', 256)")|$([ -n "$REFRESHED" ] && [ "$REFRESHED" != "$REFRESH_TOKEN" ] && echo new)" "0|new"
expect "3.3.4: a retry of the same refresh within the grace period returns the same new pair" "$(curl -s -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT" | grep -o '"refresh_token":"[a-z0-9_]*"' | sed 's/.*:"//;s/"//' || true)" "$REFRESHED"

