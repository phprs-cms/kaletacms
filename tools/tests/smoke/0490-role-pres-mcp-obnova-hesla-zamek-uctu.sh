echo "== role přes MCP, obnova hesla, zámek účtu"
SUB_TOKEN2="kaleta_$(printf 'b%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$SUB_TOKEN2")', '$(site_time)' FROM ka_uzivatele WHERE user = 'obchodnik'"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $SUB_TOKEN2" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"vytvor_novinku","arguments":{"titulek":"Od obchodnika","kategorie":"aktuality"}}}' > "$WORK/response"
grep -q 'nemáš přístup' "$WORK/response" && expect "vlastní role bez Novinek nezaloží novinku ani přes MCP" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Od obchodnika'")" "0" || { echo "  CHYBA  MCP bez kontroly sekce Novinky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FAKE_REFRESH="$(printf 'c%.0s' $(seq 1 64))"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET obnova_otisk = '$(php -r 'echo hash("sha256", $argv[1]);' "$FAKE_REFRESH")', obnova_cas = '$(site_time)' + INTERVAL 1 DAY WHERE user = 'obchodnik'"
JAR3="$WORK/jar3"
TOKEN3=$(curl -s -c "$JAR3" "$B/admin.php?action=password&token=$FAKE_REFRESH" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
curl -s -b "$JAR3" -c "$JAR3" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$TOKEN3" -d "token=$FAKE_REFRESH" --data-urlencode "password=Nove-heslo-123" --data-urlencode "password2=Nove-heslo-123"
expect "obnova hesla zruší tokeny napojení" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny t JOIN ka_uzivatele u ON u.idu = t.idu WHERE u.user = 'obchodnik'")" "0"
# the invitation link opened where another account is signed in still sets the password and signs that browser out;
# the invited user then signs in with the new password by user name and by the e-mail the invitation came to
csrf_of() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//' || true; }
INVITE_TOKEN="$(printf 'd%.0s' $(seq 1 64))"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET obnova_otisk = '$(php -r 'echo hash("sha256", $argv[1]);' "$INVITE_TOKEN")', obnova_cas = '$(site_time)' + INTERVAL 3 DAY WHERE user = 'pozvany'"
JAR5="$WORK/jar5"; curl -s -c "$JAR5" -o "$WORK/response" "$B/admin.php"
SIGNED_IN=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(csrf_of "$WORK/response")" -d user=obchodnik --data-urlencode "password=Nove-heslo-123")
curl -s -b "$JAR5" -c "$JAR5" -o "$WORK/response" "$B/admin.php?action=password&token=$INVITE_TOKEN"
INVITE_FORM="$(grep -c 'name="user" value="pozvany" autocomplete="username"' "$WORK/response" || true)|$(grep -c 'přihlášený jako obchodnik' "$WORK/response" || true)"
# the token as the form carries it, not as the test knows it: from 1.4.0 the form sent it empty and never set the password
FORM_TOKEN=$(grep -o 'name="token" value="[a-f0-9]*"' "$WORK/response" | sed 's/.*value="//;s/"//' || true)
INVITE_SET=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?action=password" -d "_csrf=$(csrf_of "$WORK/response")" -d "token=$FORM_TOKEN" --data-urlencode "password=Pozvany-heslo-123" --data-urlencode "password2=Pozvany-heslo-123")
curl -s -b "$JAR5" -c "$JAR5" -o "$WORK/response" "$B/admin.php"
expect "pozvánka otevřená u jiného přihlášení nastaví heslo a odhlásí" "$SIGNED_IN|$INVITE_FORM|${INVITE_SET%% *}|$(grep -q 'password_changed=1$' <<< "$INVITE_SET" && echo ok)|$(grep -c 'autocomplete="current-password"' "$WORK/response" || true)" "302|1|1|302|ok|1"
invite_login() { local jar="$WORK/jar-$2"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(csrf_of "$WORK/response")" --data-urlencode "user=$1" --data-urlencode "password=Pozvany-heslo-123"; }
expect "pozvaný se přihlásí jménem i e-mailem" "$(invite_login pozvany name)|$(invite_login pozvany@example.cz email)" "302|302"
# 3.29.1 review: an e-mail two accounts share (in another case too) signs neither in; wrong passwords typed with the e-mail
# count to that account's lock as with its user name, and the lock then holds for the right password
OWN_EMAIL3291=$(sq "SELECT email FROM ka_uzivatele WHERE user = 'obchodnik'")
sq "UPDATE ka_uzivatele SET email = 'POZVANY@example.cz' WHERE user = 'obchodnik'" > /dev/null
SHARED3291=$(invite_login pozvany@example.cz shared)
sq "UPDATE ka_uzivatele SET email = '$OWN_EMAIL3291' WHERE user = 'obchodnik'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
JAR6="$WORK/jar6"; curl -s -c "$JAR6" -o "$WORK/response" "$B/admin.php"; TOKEN6=$(csrf_of "$WORK/response")
for i in $(seq 1 10); do curl -s -b "$JAR6" -c "$JAR6" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN6" --data-urlencode user=pozvany@example.cz -d password=wrong-by-email-3291; done
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
LOCKED3291="$(sq "SELECT zamceno_do > '$(site_time)' FROM ka_uzivatele WHERE user = 'pozvany'")|$(invite_login pozvany locked)"
sq "UPDATE ka_uzivatele SET zamceno_do = NULL, pocet_chyb = 0 WHERE user = 'pozvany'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
expect "3.29.1 review: a shared e-mail signs nobody in; wrong passwords by e-mail lock the account as by name" "$SHARED3291|$LOCKED3291" "401|1|401"
JAR4="$WORK/jar4"
TOKEN4=$(curl -s -c "$JAR4" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
for i in $(seq 1 10); do curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik -d password=spatne-heslo-xyz; done
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # the per-address limit is reached too – here the account lock alone is tested
code=$(curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123")
expect "po 10 chybách je účet dočasně zamčený i pro správné heslo" "$code|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zamceno_do > '$(site_time)' FROM ka_uzivatele WHERE user = 'obchodnik'")" "401|1"
# 3.3.3 (N51): the locked account answers the right password exactly as any wrong password – no confirmation of the password
N51_LOCKED=$(grep -o 'Chybné jméno nebo heslo, nebo je účet po řadě chybných pokusů dočasně zamčený[^<]*' "$WORK/response" || true)
curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=admin -d password=wrong-for-admin-1; N51_WRONG=$(grep -o 'Chybné jméno nebo heslo[^<]*' "$WORK/response" || true)
curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=nikdo-takovy -d password=whatever-12345; N51_NOBODY=$(grep -o 'Chybné jméno nebo heslo[^<]*' "$WORK/response" || true)
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET zamceno_do = NULL, pocet_chyb = 0, blokovat = 1 WHERE user = 'obchodnik'"
code=$(curl -s -b "$JAR4" -c "$JAR4" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123"); N51_BLOCKED=$(grep -o 'Chybné jméno nebo heslo[^<]*' "$WORK/response" || true)
expect "3.3.3: a locked account, a blocked one, a wrong password and an unknown name get the same answer, which offers the reset" \
  "$([ -n "$N51_LOCKED" ] && [ "$N51_LOCKED" = "$N51_WRONG" ] && [ "$N51_WRONG" = "$N51_NOBODY" ] && [ "$N51_NOBODY" = "$N51_BLOCKED" ] && echo same)|$code|$(printf '%s' "$N51_LOCKED" | grep -c 'obnovte')|$(grep -c 'zablokovaný\|blokován' "$WORK/response")" "same|401|1|0"
# 3.3.3 (N61): the password is read as typed – spaces around it are part of it, as everywhere a password is set
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET blokovat = 0, password = '$(php -r 'echo password_hash(" Mezera-heslo-123 ", PASSWORD_DEFAULT);')' WHERE user = 'obchodnik'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
expect "3.3.3: a password with spaces around it signs in as typed" "$(curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password= Mezera-heslo-123 ")" "302"
# 3.3.3 (N60): the sign-in ends after 8 idle hours or 24 hours in total – the admin keep-alive (action=token) does not extend it
session_shift() { # session_shift <jar> <key> <seconds back>: moves a time in the stored session of the jar
  local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$1" | tail -1); dir=$(php -r '$p = (string) ini_get("session.save_path"); $p = substr($p, (int) strrpos($p, ";") + (str_contains($p, ";") ? 1 : 0)); echo $p !== "" ? $p : sys_get_temp_dir();')
  php -r '$f = $argv[1]; $s = (string) file_get_contents($f); file_put_contents($f, preg_replace("/" . $argv[2] . "\\|i:\\d+;/", $argv[2] . "|i:" . (time() - (int) $argv[3]) . ";", $s));' "$dir/sess_$id" "$2" "$3"
}
expect "3.3.3: a fresh sign-in keeps the keep-alive working" "$(curl -s -b "$JAR4" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=token")|$(grep -c '"csrf"' "$WORK/response")" "200|1"
session_shift "$JAR4" last_seen $((8 * 3600 + 60))
expect "3.3.3: after 8 hours without a request the sign-in is over" "$(curl -s -b "$JAR4" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=token")|$(grep -c '"csrf"' "$WORK/response")|$(grep -c 'name="password"' "$WORK/response")" "200|0|1"
TOKEN4=$(csrf); curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password= Mezera-heslo-123 "
session_shift "$JAR4" login_at $((24 * 3600 + 60))
expect "3.3.3: 24 hours after signing in the keep-alive no longer works, however active the tab was" "$(curl -s -b "$JAR4" -o "$WORK/response" "$B/admin.php?action=token"; grep -c '"csrf"' "$WORK/response")|$(grep -c 'name="password"' "$WORK/response")" "0|1"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"

