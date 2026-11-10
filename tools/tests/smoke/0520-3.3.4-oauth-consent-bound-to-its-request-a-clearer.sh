echo "== 3.3.4: OAuth – consent bound to its request, a clearer consent, races on codes and refresh (N14, N65, N13, N66, N20)"
# register_client <name> <redirect_uri>: a public client (PKCE only) as Claude registers it; prints its client_id
register_client() { curl -s -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"$1\",\"redirect_uris\":[\"$2\"],\"token_endpoint_auth_method\":\"none\"}" | grep -o '"client_id":"[a-f0-9]*"' | sed 's/.*:"//;s/"//' || true; }
authorize_query() { printf 'response_type=code&client_id=%s&redirect_uri=%s&code_challenge=%s&code_challenge_method=S256&state=%s' "$1" "$2" "$CHALLENGE" "$3"; }
URI_A="https://claude.ai/api/mcp/auth_callback"; URI_B="https://claude.ai.evil.example/api/mcp/auth_callback"
CLIENT_A=$(register_client "Claude" "$URI_A"); CLIENT_B=$(register_client "Claude" "$URI_B")
# N14: the genuine consent page for A is open; a later top-level GET to /oauth/authorize for B in the same session (the
# session cookie is SameSite=Lax) must not change where "Allow" on A's page sends the code
NONCE_A=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "$URI_A" n14a)")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_A"; cp "$WORK/response" "$WORK/consent-a"; CSRF_A=$(csrf)
NONCE_B=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$CLIENT_B" "$URI_B" n14b)")")
[ -n "$NONCE_A" ] && [ -n "$NONCE_B" ] && [ "$NONCE_A" != "$NONCE_B" ] && echo "  ok     3.3.4 N14: each sign-in request gets its own nonce" || { echo "  CHYBA  N14 nonces: $NONCE_A / $NONCE_B"; ERRORS=$((ERRORS+1)); }
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d "request=$NONCE_A" -d povolit=1)
case "$REDIRECT" in "$URI_A?code="*"state=n14a"*) echo "  ok     3.3.4 N14: Allow on A's consent page sends the code to A's redirect_uri, even after a request for B";; *) echo "  CHYBA  N14: the consent went to $REDIRECT"; ERRORS=$((ERRORS+1));; esac
expect "3.3.4 N14: B got no code – it still needs its own consent" "$(sq "SELECT COUNT(*) FROM ka_oauth_kody WHERE client_id = '$CLIENT_B'")|$(sq "SELECT COUNT(*) FROM ka_oauth_kody WHERE client_id = '$CLIENT_A'")" "0|1"
expect "3.3.4 N14: a consent without its request is refused" "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d povolit=1)|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d "request=$NONCE_A" -d povolit=1)" "400|400"
# N65: B's consent (an unknown host) leads with the host, warns, marks the client as new and preselects drafts only;
# A's (claude.ai) has no warning; since 3.11 (UX310-2) it starts at drafts only too
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_B"
contains -q '<strong>claude.ai.evil.example</strong>' "$WORK/response" && contains -q 'claude.ai.evil.example není adresa vlastních aplikací Claude' "$WORK/response" && contains -q 'nová, neověřená' "$WORK/response" \
  && contains -q 'value="drafts" checked' "$WORK/response" && ! contains -q 'value="full" checked' "$WORK/response" && contains -q 'Co smí aplikace dělat?' "$WORK/response" && ! contains -q 'Claude staví' "$WORK/response" \
  && echo "  ok     3.3.4 N65: a consent for an unknown host shows the host, the warning, the new mark and drafts only, and does not speak of Claude" || { echo "  CHYBA  N65 consent for an unknown host"; sed 's/<[^>]*>/ /g' "$WORK/response" | grep -i 'evil\|claude' | head -5; ERRORS=$((ERRORS+1)); }
contains -q '<strong>claude.ai</strong>' "$WORK/consent-a" && ! contains -q 'není adresa vlastních aplikací Claude' "$WORK/consent-a" && contains -q 'value="drafts" checked' "$WORK/consent-a" && ! contains -q 'value="full" checked' "$WORK/consent-a" && contains -q 'Co smí Claude dělat?' "$WORK/consent-a" \
  && echo "  ok     3.3.4 N65, 3.11 UX310-2: a consent for claude.ai shows no warning and preselects drafts only" || { echo "  CHYBA  N65 consent for claude.ai"; ERRORS=$((ERRORS+1)); }
# 3.11 (UX310-8): the consent stands on its own – no admin menu, no search, no draft-look bar with a one-click Publish
contains -q '<body class="login">' "$WORK/consent-a" && ! contains -q 'class="menu"' "$WORK/consent-a" && ! contains -q 'data-paleta' "$WORK/consent-a" && ! contains -q 'action=publish_look' "$WORK/consent-a" \
  && contains -q 'Jen koncepty – publikujete vy <span class="stitek stitek-vydano">doporučeno' "$WORK/consent-a" \
  && echo "  ok     3.11 UX310-8: the consent screen has no admin menu and no Publish bar; Drafts only is marked recommended" || { echo "  CHYBA  3.11: the consent screen frame"; ERRORS=$((ERRORS+1)); }
expect "3.3.4 N65: a client allowed once is approved, the other is not" "$(sq "SELECT GROUP_CONCAT(approved IS NOT NULL ORDER BY client_id = '$CLIENT_A' DESC) FROM ka_oauth_klienti WHERE client_id IN ('$CLIENT_A', '$CLIENT_B')")" "1,0"
NONCE_A2=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "$URI_A" again)")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$NONCE_A2"
contains -q 'Povolit přístup' "$WORK/response" && ! contains -q 'nová, neověřená' "$WORK/response" && echo "  ok     3.3.4 N65: a client this site approved before is not marked as new" || { echo "  CHYBA  N65 approved client marked as new"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=oauth" -d "_csrf=$CSRF_A" -d "request=$NONCE_A2" -d povolit=0
# N65: "Only allow Claude's own apps to connect" refuses registration and sign-in outside Claude's hosts
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('claude_apps_only', '1') ON DUPLICATE KEY UPDATE hodnota = '1'" > /dev/null
expect "3.3.4 N65: with claude_apps_only a foreign host cannot register or sign in, Claude and Claude Code can" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["https://evil.example/cb"]}')|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_B" "$URI_B" only)")|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["https://claude.ai/api/mcp/auth_callback","http://localhost:33418/callback"]}')|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "$URI_A" only)")" "400|403|201|302"
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'claude_apps_only'" > /dev/null
# 3.8 (Connectors Directory): Claude Code registers a loopback redirect and comes back on another port next time (RFC 8252
# §7.3 – any port for a loopback address); a different path or a non-loopback host with another port is still refused
CLIENT_CC=$(register_client "Claude Code" "http://127.0.0.1:33418/callback")
expect "3.8 OAuth: a loopback redirect_uri on another port goes to the consent, another path or host does not" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_CC" "http://127.0.0.1:51234/callback" cc)")|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_CC" "http://127.0.0.1:51234/other" cc)")|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?$(authorize_query "$CLIENT_A" "https://claude.ai:8443/api/mcp/auth_callback" cc)")" "302|400|400"
# N13: six parallel redemptions of one code give one token pair; a second server on the same files with six workers runs
# them at the same time, as PHP-FPM would
RACE_PORT=$((PORT + 17)); RACE="http://127.0.0.1:$RACE_PORT"
(cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=6 exec php -S "127.0.0.1:$RACE_PORT" system/dev-router.php > /dev/null 2>&1) & RACE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$RACE/" && break; sleep 0.2; done
CLIENT_R=$(register_client "Claude race" "$URI_A")
CODE_R=$(oauth_allow "$(authorize_query "$CLIENT_R" "$URI_A" race)" -d povolit=1 | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
RACERS=(); for i in 1 2 3 4 5 6; do curl -s -o "$WORK/race-code-$i" -X POST "$RACE/oauth/token" -d grant_type=authorization_code -d "code=$CODE_R" -d "redirect_uri=$URI_A" -d "client_id=$CLIENT_R" -d "code_verifier=$VERIFIER" & RACERS+=($!); done; wait "${RACERS[@]}" || true
expect "3.3.4 N13: six parallel redemptions of one code issue exactly one token pair" "$(cat "$WORK"/race-code-* | grep -o '"refresh_token"' | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")" "1|1"
REFRESH_R=$(cat "$WORK"/race-code-* | grep -o '"refresh_token":"[a-z0-9_]*"' | sed 's/.*:"//;s/"//' || true)
RACERS=(); for i in 1 2 3 4 5 6; do curl -s -o "$WORK/race-refresh-$i" -X POST "$RACE/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R" -d "client_id=$CLIENT_R" & RACERS+=($!); done; wait "${RACERS[@]}" || true
# the requests that lost the race fall within the grace period: they get the very pair the winner got, never a pair of their own
expect "3.3.4 N13: six parallel refreshes with one refresh token issue exactly one new pair, and one live refresh token remains" "$(cat "$WORK"/race-refresh-* | grep -o '"refresh_token":"[a-z0-9_]*"' | sort -u | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'pristup' AND expirace > '$(site_time)'")" "1|1|2"
expect "3.9: a refreshed Claude connection stays signed in for a year (like a personal token), the access token for an hour" "$(sq "SELECT DATEDIFF(MAX(expirace), '$(site_date now)') BETWEEN 364 AND 366 FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")|$(cat "$WORK"/race-refresh-* | grep -o '"expires_in":[0-9]*' | sort -u)" '1|"expires_in":3600'
pkill -P "$RACE_PID" 2>/dev/null || true; kill "$RACE_PID" 2>/dev/null || true # the workers first, they outlive their parent
REFRESH_R2=$(cat "$WORK"/race-refresh-* | grep -o '"refresh_token":"[a-z0-9_]*"' | head -1 | sed 's/.*:"//;s/"//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R2" -d "client_id=$CLIENT_R"
REFRESH_R3=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$REFRESH_R3" ] && [ "$REFRESH_R3" != "$REFRESH_R2" ] && echo "  ok     3.3.4 N13: the new refresh token refreshes normally" || { echo "  CHYBA  N13 refresh after the race"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.10.1 (N39-5): a rotation is remembered for 30 days, not for the old token's year; past that window (a record from
# before 3.10.1 kept for a year included) the old token is plainly invalid, the live pair keeps working and the record goes
expect "3.10.1 N39-5: two refreshes leave two rotation records, each kept at most 30 days" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(expires_at) <= '$(site_time)' + INTERVAL 30 DAY + INTERVAL 1 MINUTE) FROM ka_oauth_rotated WHERE client_id = '$CLIENT_R'")" "2|1"
sq "UPDATE ka_oauth_rotated SET rotated_at = '$(site_time)' - INTERVAL 31 DAY, expires_at = '$(site_time)' + INTERVAL 300 DAY WHERE hash = SHA2('$REFRESH_R2', 256)" > /dev/null
expect "3.10.1 N39-5: a refresh token rotated more than 30 days ago is refused without revoking the live pair or recording theft" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R2" -d "client_id=$CLIENT_R")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R' AND druh = 'obnova'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'security.token_reuse' AND data LIKE '%$CLIENT_R%'")" "400|1|0"
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R3" -d "client_id=$CLIENT_R"
REFRESH_R4=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "3.10.1 N39-5: the live refresh token still works, and the cleanup deletes the record past its window, the recent ones stay" \
  "$([ -n "$REFRESH_R4" ] && [ "$REFRESH_R4" != "$REFRESH_R3" ] && echo new)|$(sq "SELECT COUNT(*) FROM ka_oauth_rotated WHERE hash = SHA2('$REFRESH_R2', 256)")|$(sq "SELECT COUNT(*) FROM ka_oauth_rotated WHERE client_id = '$CLIENT_R'")" "new|0|2"
REFRESH_R3="$REFRESH_R4"
# reuse of a rotated refresh token after the grace period counts as theft: every token of the client for the user goes
sq "UPDATE ka_oauth_rotated SET rotated_at = '$(site_time)' - INTERVAL 1 MINUTE WHERE client_id = '$CLIENT_R'" > /dev/null
expect "3.3.4 N13: a rotated refresh token used after the grace period is refused and revokes the client's tokens, recorded as an event" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R" -d "client_id=$CLIENT_R")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT_R'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'security.token_reuse' AND data LIKE '%$CLIENT_R%'")|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_R3" -d "client_id=$CLIENT_R")" "400|0|1|400"
# N20: a code_verifier shorter than 43 characters is refused, even when it matches its challenge
SHORT_VERIFIER="short-verifier-of-thirty-one-ch"; SHORT_CHALLENGE=$(printf %s "$SHORT_VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
CODE_S=$(oauth_allow "response_type=code&client_id=$CLIENT_A&redirect_uri=$URI_A&code_challenge=$SHORT_CHALLENGE&code_challenge_method=S256&state=short" -d povolit=1 | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
expect "3.3.4 N20: a code_verifier of fewer than 43 characters is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$CODE_S" -d "redirect_uri=$URI_A" -d "client_id=$CLIENT_A" -d "code_verifier=$SHORT_VERIFIER")" 400
# N66: the daily job removes registrations nobody approved a day after registration; approved clients and clients with
# tokens or codes stay
CLIENT_OLD=$(register_client "Never approved" "https://evil.example/cb")
sq "UPDATE ka_oauth_klienti SET vytvoren = '$(site_time)' - INTERVAL 2 DAY WHERE client_id IN ('$CLIENT_OLD', '$CLIENT_A', '$CLIENT_B', '$CLIENT')" > /dev/null
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'security'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "3.3.4 N66: an unused registration older than a day is deleted, approved clients stay" "$(sq "SELECT COUNT(*) FROM ka_oauth_klienti WHERE client_id = '$CLIENT_OLD'")|$(sq "SELECT COUNT(*) FROM ka_oauth_klienti WHERE client_id IN ('$CLIENT_A', '$CLIENT')")|$(grep -c 'unused app registrations removed' "$WORK/tasks.txt")" "0|2|1"

