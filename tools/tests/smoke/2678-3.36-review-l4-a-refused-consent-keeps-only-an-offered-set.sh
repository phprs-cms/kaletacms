echo "== 3.36 review L4: a refused OAuth consent shows only the tool sets this site offers"
# a refused try (full access without a fresh proof) keeps the chosen set – but a posted fleet set on a site without the
# fleet extension falls back to the essentials, as the approval itself would, and the fleet choice is never shown
L4_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('l4oauth', '$L4_HASH', 'Tester L4', 'l4oauth@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0);
  DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
L4_JAR="$WORK/jar-l4oauth"; rm -f "$L4_JAR"
curl -s -c "$L4_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$L4_JAR" -c "$L4_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=l4oauth --data-urlencode "password=$PASSWORD"
L4_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude L4\",\"redirect_uris\":[\"$L4_URI\"],\"token_endpoint_auth_method\":\"none\"}"
L4_CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
L4_CHALLENGE=$(printf %s "$(printf 'v%.0s' $(seq 1 50))" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
# the last proof of the session is long gone (verified_at set back to 1970), so full access is refused without the password
L4_ID=$(awk '$6 == "kaleta" {print $7}' "$L4_JAR" | tail -1); L4_DIR=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
sed -i.bak 's/verified_at|i:[0-9]*;/verified_at|i:1;/' "$L4_DIR/sess_$L4_ID" && rm -f "$L4_DIR/sess_$L4_ID.bak"
l4_request() { local to; to=$(curl -s -b "$L4_JAR" -c "$L4_JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$L4_CLIENT&redirect_uri=$L4_URI&code_challenge=$L4_CHALLENGE&code_challenge_method=S256&state=$1")
  to=$(printf %s "$to" | grep -o 'request=[a-f0-9]*' | sed 's/request=//' || true); curl -s -b "$L4_JAR" -c "$L4_JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$to"; printf %s "$to"; }
l4_consent() { local n="$1"; shift; curl -s -b "$L4_JAR" -c "$L4_JAR" -o "$WORK/consent" -w '%{http_code}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$L4_CSRF" -d "request=$n" -d povolit=1 -d access=full "$@"; }
L4_N=$(l4_request l4); L4_CSRF=$(csrf)
expect "3.36 review L4: a refused consent with tool_set=fleet (no fleet site) re-renders the essentials, with no fleet choice" \
  "$(l4_consent "$L4_N" -d tool_set=fleet)|$(grep -c 'name="tool_set" value="essentials" checked' "$WORK/consent")|$(grep -c 'name="tool_set" value="fleet"' "$WORK/consent" || true)" "403|1|0"
expect "3.36 review L4: … a set the site offers (migration) stays chosen after the refusal" \
  "$(l4_consent "$L4_N" -d tool_set=migration)|$(grep -c 'name="tool_set" value="migration" checked' "$WORK/consent")" "403|1"
sq "DELETE FROM ka_uzivatele WHERE user = 'l4oauth'" > /dev/null
