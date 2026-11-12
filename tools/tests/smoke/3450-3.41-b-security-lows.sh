echo "== 3.41 b: the remaining low findings of the deep security audit (N113–N123)"
s341_csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//' || true; }
s341_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }

# N117: a failed sign-in logs the name of an existing account only – never what was typed into the name field
S341_JAR="$WORK/jar-s341"; curl -s -c "$S341_JAR" -o "$WORK/response" "$B/admin.php"; S341_TOKEN=$(s341_csrf "$WORK/response")
S341_LOG0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_protokol")
curl -s -b "$S341_JAR" -c "$S341_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$S341_TOKEN" --data-urlencode "user=Typed-Secret-S341" -d password=whatever-s341
curl -s -b "$S341_JAR" -c "$S341_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$S341_TOKEN" -d user=admin -d password=wrong-password-s341
expect "3.41 N117: a failed sign-in logs 'unknown account' for a name no account has and the account's name for an existing one" \
  "$(sq "SELECT GROUP_CONCAT(popis ORDER BY idp SEPARATOR '|') FROM ka_protokol WHERE idp > $S341_LOG0 AND modul = 'prihlaseni' AND akce = 'neuspech'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%Typed-Secret-S341%'")" \
  "unknown account|account: admin|0"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET pocet_chyb = 0, zamceno_do = NULL WHERE user = 'admin'" > /dev/null

# N123: an escaped host is refused at registration; a new app on a Claude address gets the "only if you started it" warning
expect "3.41 N123: registering https://evil.example%2f.claude.ai/cb is refused" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["https://evil.example%2f.claude.ai/cb"],"token_endpoint_auth_method":"none"}')" 400
S341_URI="https://claude.ai/api/mcp/auth_callback?s341=1"; S341_CLIENT=$(register_client "Claude" "$S341_URI")
S341_NONCE=$(oauth_nonce "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?$(authorize_query "$S341_CLIENT" "$(php -r 'echo rawurlencode($argv[1]);' -- "$S341_URI")" s341)")")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$S341_NONCE"
expect "3.41 N123: the consent of a new app on claude.ai warns to allow only a connection you started, with its registration time; no foreign-host warning" \
  "$(grep -c 'Přístup povolte, jen pokud jste připojení této aplikace právě teď spustili sami (k tomuto webu se zaregistrovala' "$WORK/response")|$(grep -c 'není adresa vlastních aplikací Claude' "$WORK/response")" "1|0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=oauth" -d "_csrf=$(csrf)" -d "request=$S341_NONCE" -d povolit=0
