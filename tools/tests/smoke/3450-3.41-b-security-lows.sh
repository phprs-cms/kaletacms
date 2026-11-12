echo "== 3.41 b: the remaining low findings of the deep security audit (N113–N123)"
s341_csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//' || true; }
s341_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1" -- "${@:2}"); }

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

# N119: a redirect of the update source is followed hop by hop and every hop checked (here a test copy: http to 127.0.0.1 only)
S341_UPD_PORT=$((PORT + 19)); mkdir -p "$WORK/s341-upd"
cat > "$WORK/s341-upd/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/inside.json') { header('Location: http://10.0.0.5/aktualizace.json', true, 302); exit; }
if ($path === '/hop.json') { header('Location: /landed.json', true, 302); exit; }
if ($path === '/landed.json') { echo '{"not":"a manifest"}'; exit; }
http_response_code(404);
PHP
(cd "$WORK/s341-upd" && exec php -S "127.0.0.1:$S341_UPD_PORT" router.php > /dev/null 2>&1) & S341_UPD_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$S341_UPD_PORT/" && break; sleep 0.2; done
S341_OLD_UPDATE_URL=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_url'")
s341_update_error() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_url', 'http://127.0.0.1:$S341_UPD_PORT/$1')" > /dev/null; s341_php 'Kaleta\Core\Language::set("en"); echo (new Kaleta\Core\Updater($app->settings()))->state(true)["chyba"] ?? "none";'; }
expect "3.41 N119: a redirect into the server's network is refused; a redirect to an allowed address is followed to its answer" \
  "$(s341_update_error inside.json)|$(s341_update_error hop.json)" \
  "The update source leads to an address that is not allowed (10.0.0.5).|The update information file is not in a valid format."
kill "$S341_UPD_PID" 2>/dev/null; S341_UPD_PID=
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('update_url', '$S341_OLD_UPDATE_URL'); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'" > /dev/null

# N120: the daily cron job asks the running site for the canary and schema.sql – the test server refuses both; System status says so
# (the job asks the site_url; this test copy has none, so the check is pointed at the test server itself)
expect "3.41 N120: the server check finds storage/ and system/ closed on the test server and wrote the canary" \
  "$(s341_php '$c = Kaleta\Core\ServerRules::run($app->settings(), $argv[1] . "/"); echo $c["error"] !== "" ? $c["error"] : ($c["open"] === [] ? "closed" : implode(",", $c["open"]));' "$B")|$([ -f "$WORK/web/storage/cache/kaleta-canary.txt" ] && echo canary)" "closed|canary"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.41 N120: System status shows the folders storage/ and system/ as refused" "$(grep -c 'webový server je návštěvníkům odmítá' "$WORK/response")" "1"
sq "UPDATE ka_nastaveni SET hodnota = '{\"at\":$(date +%s),\"open\":[\"storage/cache/kaleta-canary.txt\"],\"error\":\"\"}' WHERE promenna = 'server_rules'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.41 N120: a served canary is an error on System status naming the address" "$(grep -c 'Webový server vydává návštěvníkům soubory ze storage/ nebo system/ (/storage/cache/kaleta-canary.txt)' "$WORK/response")" "1"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'server_rules'" > /dev/null

# N113: an anonymous request does not replace a pending invitation; a new e-mail (Users) and a completed sign-in end a link
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, potvrzeno, blokovat, obnova_otisk, obnova_cas) VALUES ('s341inv', '$(php -r 'echo password_hash("S341-invited-pw-1", PASSWORD_DEFAULT);')', 'Invited S341', 's341inv@example.com', 'en', 0, '$(site_time)', 0, SHA2('s341-invitation', 256), '$(site_time '+71 hours')')" > /dev/null
S341_INV=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 's341inv'"); S341_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
S341_RJAR="$WORK/jar-s341r"; curl -s -c "$S341_RJAR" -o "$WORK/response" "$B/admin.php?action=password"
curl -s -b "$S341_RJAR" -c "$S341_RJAR" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$(s341_csrf "$WORK/response")" -d kdo=s341inv@example.com
expect "3.41 N113: a request from the sign-in page keeps a pending invitation – same token, nothing queued, the request logged as ignored" \
  "$(sq "SELECT obnova_otisk = SHA2('s341-invitation', 256) FROM ka_uzivatele WHERE idu = $S341_INV")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE idp > $S341_P0")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'obnova-hesla' AND popis LIKE '%s341inv'")" "1|0|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$S341_INV"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d "idu=$S341_INV" -d user=s341inv -d admin=0 -d jmeno="Invited S341" -d email=s341inv-new@example.com --data-urlencode "soucasne=$PASSWORD"
expect "3.41 N113: changing the account's e-mail in Users ends its invitation link" "$(sq "SELECT CONCAT(email, '|', obnova_otisk = '', '|', obnova_cas IS NULL) FROM ka_uzivatele WHERE idu = $S341_INV")" "s341inv-new@example.com|1|1"
sq "UPDATE ka_uzivatele SET obnova_otisk = SHA2('s341-request', 256), obnova_cas = '$(site_time '-20 minutes')' WHERE idu = $S341_INV" > /dev/null
S341_SJAR="$WORK/jar-s341s"; curl -s -c "$S341_SJAR" -o "$WORK/response" "$B/admin.php"
expect "3.41 N113: a completed sign-in ends a reset link of the account" \
  "$(curl -s -b "$S341_SJAR" -c "$S341_SJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(s341_csrf "$WORK/response")" -d user=s341inv --data-urlencode "password=S341-invited-pw-1")|$(sq "SELECT obnova_otisk = '' FROM ka_uzivatele WHERE idu = $S341_INV")" "302|1"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'; DELETE FROM ka_kontrola_ip WHERE typ IN ('obnova', 'login'); DELETE FROM ka_posta WHERE idp > $S341_P0" > /dev/null

# N114: a queued message whose link expired is dropped, not sent; a backup leaves out a body with a live link
S341_M0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
sq "INSERT INTO ka_posta (komu, predmet, vytvoreno, pokusu, odeslano, chyba, dalsi_pokus, telo) VALUES
  ('s341-old@example.com', 'Reset S341', '$(site_time '-2 hours')', 1, NULL, 'smtp down', '$(site_time '-1 minute')', '{\"text\":\"old-link-s341\",\"html\":\"\",\"hlavicky\":[],\"expires\":$(( $(date +%s) - 60 ))}'),
  ('s341-live@example.com', 'Invite S341', '$(site_time)', 1, NULL, 'smtp down', '$(site_time '+1 hour')', '{\"text\":\"live-link-s341\",\"html\":\"\",\"hlavicky\":[],\"expires\":$(( $(date +%s) + 3600 ))}'),
  ('s341-plain@example.com', 'Plain S341', '$(site_time)', 1, NULL, 'smtp down', '$(site_time '+1 hour')', '{\"text\":\"plain-body-s341\",\"html\":\"\",\"hlavicky\":[]}')" > /dev/null
s341_php 'Kaleta\Core\Mail::processQueue($app->settings());' > /dev/null
S341_BACKUP=$(s341_php 'echo Kaleta\Core\Backup::create($app->db(), "s341", false);')
S341_DUMP=$( (gzip -dc "$WORK/web/storage/zalohy/$S341_BACKUP" 2>/dev/null || cat "$WORK/web/storage/zalohy/$S341_BACKUP") | grep -c 'live-link-s341\|plain-body-s341\|old-link-s341' || true)
S341_PLAIN=$( (gzip -dc "$WORK/web/storage/zalohy/$S341_BACKUP" 2>/dev/null || cat "$WORK/web/storage/zalohy/$S341_BACKUP") | grep -o 'plain-body-s341' | head -1)
expect "3.41 N114: the expired link is dropped with its reason; the backup has the plain queued body but not the live link" \
  "$(sq "SELECT CONCAT(telo IS NULL, '|', odeslano IS NULL, '|', chyba) FROM ka_posta WHERE komu = 's341-old@example.com'")|$S341_DUMP|$S341_PLAIN|$(sq "SELECT telo IS NOT NULL FROM ka_posta WHERE komu = 's341-live@example.com'")" \
  "1|1|The link in the message expired before the message could be sent.|1|plain-body-s341|1"
rm -f "$WORK/web/storage/zalohy/$S341_BACKUP"; sq "DELETE FROM ka_posta WHERE idp > $S341_M0" > /dev/null

# N116: a change of the second factor ends the other sign-ins of the account; the one that made it stays signed in
sq "UPDATE ka_uzivatele SET passkey_only = 1 WHERE idu = $S341_INV" > /dev/null
s341_signin() { curl -s -c "$1" -o "$WORK/response" "$B/admin.php"; curl -s -b "$1" -c "$1" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(s341_csrf "$WORK/response")" -d user=s341inv --data-urlencode "password=S341-invited-pw-1"; }
s341_signed_in() { curl -s -b "$1" -c "$1" -o "$WORK/response" "$B/admin.php?action=account"; grep -c 'name="co" value="profil"' "$WORK/response" || true; }
S341_A="$WORK/jar-s341a"; S341_B="$WORK/jar-s341b"; rm -f "$S341_A" "$S341_B"
S341_IN="$(s341_signin "$S341_A")|$(s341_signin "$S341_B")|$(s341_signed_in "$S341_A")|$(s341_signed_in "$S341_B")"
curl -s -b "$S341_A" -c "$S341_A" -o "$WORK/response" "$B/admin.php?action=account"
curl -s -b "$S341_A" -c "$S341_A" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$(s341_csrf "$WORK/response")" -d co=passkey_only --data-urlencode "soucasne=S341-invited-pw-1"
expect "3.41 N116: switching \"passkey only\" off in one sign-in ends the other one; the first stays signed in" \
  "$S341_IN|$(sq "SELECT passkey_only FROM ka_uzivatele WHERE idu = $S341_INV")|$(s341_signed_in "$S341_A")|$(s341_signed_in "$S341_B")" "302|302|1|1|0|1|0"
sq "UPDATE ka_uzivatele SET sign_in_epoch = sign_in_epoch + 1 WHERE idu = $S341_INV" > /dev/null
expect "3.41 N116: a second-factor change made elsewhere (an administrator's reset raises the account's epoch) ends that sign-in as well" "$(s341_signed_in "$S341_A")" "0"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $S341_INV; DELETE FROM ka_uzivatele WHERE idu = $S341_INV" > /dev/null
