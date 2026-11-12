echo "== 2.13: outbound connectors (OAuth with PKCE, encrypted credentials, the delivery log)"
FAKE_LOGS="$WORK/fake/kaleta-fake-$FAKE_PORT"
# connect_fake <service>: the site's OAuth app with test credentials, the sign-in through the fake service and back
connect_fake() {
  curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d "service=$1" -d client_id=test-client --data-urlencode secret=test-client-secret
  curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
  local location; location=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=connectors&action=connect" -d "_csrf=$(csrf)" -d "service=$1")
  echo "$location" > "$WORK/authorize-url"
  local state; state=$(printf %s "$location" | sed -n 's/.*[?&]state=\([a-f0-9]*\).*/\1/p')
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -L "$B/admin.php?module=connectors&action=callback&code=test-code&state=$state"
}
check "connectors: Administration → Connections lists Google with the redirect address" 200 "/admin.php?module=connectors" "module=connectors&amp;action=callback"
connect_fake google
grep -q "code_challenge_method=S256" "$WORK/authorize-url" && grep -q "access_type=offline" "$WORK/authorize-url" && grep -q "/o/oauth2/v2/auth?" "$WORK/authorize-url" \
  && echo "  ok     connectors: the sign-in asks with PKCE and a state for an offline token" || { echo "  CHYBA  authorize URL"; cat "$WORK/authorize-url"; ERRORS=$((ERRORS+1)); }
expect "connectors: Google is connected as the account from the sign-in, the tokens are encrypted" "$(sq "SELECT CONCAT(account, '|', connected_at IS NOT NULL, '|', access_token LIKE '%access-1%', '|', secret LIKE '%test-client-secret%') FROM ka_connectors WHERE service = 'google'")" "owner@example.com|1|0|0"
grep -q '"has_verifier":true' "$FAKE_LOGS-oauth.log" && echo "  ok     connectors: the code was exchanged with the PKCE verifier" || { echo "  CHYBA  PKCE verifier"; ERRORS=$((ERRORS+1)); }
check "connectors: the screen shows the connection and never the secret" 200 "/admin.php?module=connectors" "owner@example.com"
! grep -q 'test-client-secret\|access-1\|refresh-1' "$WORK/response" && echo "  ok     connectors: no credential on the page" || { echo "  CHYBA  credential on the page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o /dev/null "$B/admin.php?module=connectors&action=callback&code=test-code&state=0123456789abcdef0123456789abcdef"
# 3.15 (N49): the error of a cancelled sign-in is shown only as one of the OAuth error codes, never as text from the address
curl -s -b "$JAR" -c "$JAR" -L -o "$WORK/response" "$B/admin.php?module=connectors&action=callback&error=Call+our+support+at+evil.example"
grep -q 'unknown_error' "$WORK/response" && ! grep -q 'evil.example' "$WORK/response" && echo "  ok     3.15 N49: a cancelled sign-in shows an OAuth error code, not text from the address" || { echo "  CHYBA  N49 OAuth callback error"; ERRORS=$((ERRORS+1)); }
expect "connectors: a callback with a state the session did not issue changes nothing" "$(sq "SELECT COUNT(*) FROM ka_connector_log WHERE action = 'oauth.token'")" "1"
connector_call() { php -r 'chdir($argv[1]); putenv("KALETA_CONNECTORS_FAKE=" . $argv[2]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $r = Kaleta\Core\Connectors::request($app, "google", "GET", "https://www.googleapis.com/echo"); echo $r["status"], "|", $r["json"]["authorization"] ?? "", "|", $r["error"];' -- "$WORK/web" "http://127.0.0.1:$FAKE_PORT"; }
expect "connectors: a call is authorised with the stored token" "$(connector_call)" "200|Bearer access-1|"
sq "UPDATE ka_connectors SET expires_at = '$(site_time)' - INTERVAL 1 DAY WHERE service = 'google'" > /dev/null
expect "connectors: an expired token is refreshed with the refresh token" "$(connector_call)" "200|Bearer access-2|"
expect "connectors: every call is logged, never its content" "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(error LIKE '%access%')) FROM ka_connector_log WHERE service = 'google'")" "4|0"
mcp list_connectors '{}' > "$WORK/response"
contains -q 'owner@example.com' "$WORK/response" && ! contains -q 'access-2\|refresh-1\|test-client-secret' "$WORK/response" && echo "  ok     connectors: Claude sees the status, never a credential" || { echo "  CHYBA  list_connectors"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=google
expect "connectors: disconnecting revokes and forgets the tokens, the OAuth app stays" "$(sq "SELECT CONCAT(access_token IS NULL, '|', refresh_token IS NULL, '|', connected_at IS NULL, '|', secret IS NOT NULL) FROM ka_connectors WHERE service = 'google'")|$(grep -c revoked "$FAKE_LOGS-oauth.log")" "1|1|1|1|1"
