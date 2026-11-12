echo "== 3.35 A: sign-in with a passkey alone (a software authenticator against the real endpoints)"
# A software authenticator (ES256): `register <state> <origin> <flags>` reads the options of navigator.credentials.create()
# and prints the response klice.js sends; `assert <state> <origin> <flags> <counter> [id|-] [handle|-]` reads the options
# of navigator.credentials.get() and prints an assertion (with the stored user handle unless another is given).
cat > "$WORK/pk335.php" <<'PHP'
<?php
$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$raw = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));
[, $mode, $stateFile, $origin, $flags] = $argv;
$options = json_decode((string) stream_get_contents(STDIN), true) ?: [];
if ($mode === 'register') {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $pem);
    $info = openssl_pkey_get_details($key);
    $id = random_bytes(16);
    $cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_pad($info['ec']['x'], 32, "\0", STR_PAD_LEFT) . "\x22\x58\x20" . str_pad($info['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $rp = (string) ($options['rp']['id'] ?? '');
    file_put_contents($stateFile, json_encode(['pem' => $pem, 'id' => $b64($id), 'handle' => (string) ($options['user']['id'] ?? ''), 'rp' => $rp]));
    $data = hash('sha256', $rp, true) . chr((int) $flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', 16) . $id . $cose;
    $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'] ?? '', 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    echo json_encode(['id' => $b64($id), 'clientDataJSON' => $b64($client), 'authenticatorData' => $b64($data), 'rk' => '1',
        'publicKey' => $b64(base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $info['key']))), 'publicKeyAlgorithm' => -7]);
    exit;
}
$state = json_decode((string) file_get_contents($stateFile), true);
$data = hash('sha256', (string) ($options['rpId'] ?? $state['rp']), true) . chr((int) $flags) . pack('N', (int) ($argv[5] ?? 0));
$client = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'] ?? '', 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
openssl_sign($data . hash('sha256', $client, true), $signature, openssl_pkey_get_private($state['pem']), OPENSSL_ALGO_SHA256);
$id = ($argv[6] ?? '-') !== '-' ? $argv[6] : $state['id'];
$handle = ($argv[7] ?? '-') !== '-' ? $argv[7] : $state['handle'];
echo json_encode(['id' => $id, 'clientDataJSON' => $b64($client), 'authenticatorData' => $b64($data), 'signature' => $b64($signature), 'userHandle' => $handle]);
PHP
P335_ORIGIN=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'"); P335_ORIGIN="${P335_ORIGIN%/}"
P335_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk335', '$P335_HASH', 'Passkey Tester', 'pk335@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
P335_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk335'")
p335_page() { curl -s -b "$1" -c "$1" -o "$WORK/response" "$B/admin.php${2:-}"; csrf; } # p335_page <jar> [query] → the form token
p335_password() { # <jar> [password] → HTTP code of the password step (the page stays in $WORK/response)
  local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk335 --data-urlencode "password=${2:-$PASSWORD}"; }
p335_code() { local t; t=$(csrf); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod -d "kod=$2"; }
p335_signed_in() { curl -s -b "$1" -o "$WORK/signed" "$B/admin.php?action=account"; grep -c 'name="co" value="profil"' "$WORK/signed" || true; }
p335_passkey() { # <jar> <state> <flags> <counter> [id] [handle] → HTTP code of the passwordless sign-in (JSON in $WORK/response)
  local t; t=$(p335_page "$1")
  curl -s -b "$1" -c "$1" -o "$WORK/p335-options" -X POST "$B/admin.php" -d "_csrf=$t" -d krok=passkey_options
  php "$WORK/pk335.php" assert "$2" "$P335_ORIGIN" "$3" "$4" "${5:--}" "${6:--}" < "$WORK/p335-options" > "$WORK/p335-assertion"
  curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=passkey --data-urlencode "odpoved@$WORK/p335-assertion"; }
p335_account() { curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=account" -d "_csrf=$P335_CSRF" "${@:2}"; }
p335_register() { # <jar> <state> <flags> [extra post args…] → "<options code>|<save code>"
  local jar="$1" state="$2" flags="$3" c1 c2; shift 3
  c1=$(p335_account "$jar" -d co=klic_moznosti "$@"); cp "$WORK/response" "$WORK/p335-reg-options"
  php "$WORK/pk335.php" register "$state" "$P335_ORIGIN" "$flags" < "$WORK/p335-reg-options" > "$WORK/p335-reg"
  c2=$(p335_account "$jar" -d co=klic_uloz --data-urlencode "odpoved@$WORK/p335-reg" -d nazev="Laptop 335" "$@"); echo "$c1|$c2"; }
J1="$WORK/jar-p335-1"; rm -f "$J1"

curl -s -o "$WORK/response" "$B/admin.php"
expect "3.35 A: the sign-in page offers the passkey button and the user-name autofill (conditional UI) with klice.js" \
  "$(grep -c 'data-klic-bezhesla' "$WORK/response")|$(grep -c 'autocomplete="username webauthn"' "$WORK/response")|$(grep -c 'image/klice.js' "$WORK/response")" "1|1|1"
curl -s -H "Host: localhost:${PORT}" -o "$WORK/response" "$B/admin.php"
expect "3.35 A: another host name of the site gets no passkey sign-in, only a link to the canonical address" \
  "$(grep -c 'data-klic-bezhesla' "$WORK/response")|$(grep -c "href=\"$P335_ORIGIN/admin.php\"" "$WORK/response")|$(grep -c 'autocomplete="username webauthn"' "$WORK/response")" "0|1|0"

# an account without a passkey: the password alone, as before; the passkey can be added without two-factor sign-in
expect "3.35 A: no passkey, no app – the password alone signs in" "$(p335_password "$J1")|$(p335_signed_in "$J1")" "302|1"
P335_CSRF=$(p335_page "$J1" "?action=account")
expect "3.35 A: My account shows the passkey section without two-factor sign-in; a challenge only with the current password" \
  "$(grep -c 'data-klic-pridat' "$WORK/response")|$(p335_account "$J1" -d co=klic_moznosti)|$(p335_account "$J1" -d co=klic_moznosti -d soucasne=wrong-password-1)" "1|403|403"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $P335_USER" > /dev/null
expect "3.35 A: a registration whose device did not verify the user (no UV) is refused" "$(p335_register "$J1" "$WORK/pk335-nouv" 65 --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idu = $P335_USER")" "200|400|0"
expect "3.35 A: the options ask for a discoverable passkey with user verification and carry the account's opaque handle" \
  "$(grep -o '"residentKey":"required"' "$WORK/p335-reg-options")|$(grep -o '"userVerification":"required"' "$WORK/p335-reg-options")|$(sq "SELECT LENGTH(passkey_handle) = 22 AND passkey_handle <> CAST(idu AS CHAR) FROM ka_uzivatele WHERE idu = $P335_USER")|$(php -r '$o = json_decode(file_get_contents($argv[1]), true); echo $o["user"]["id"] === $argv[2] ? "same" : "other";' "$WORK/p335-reg-options" "$(sq "SELECT passkey_handle FROM ka_uzivatele WHERE idu = $P335_USER")")" \
  '"residentKey":"required"|"userVerification":"required"|1|same'
expect "3.35 A: a passkey with UV is registered as discoverable; the first one brings eight backup codes, shown once" \
  "$(p335_register "$J1" "$WORK/pk335-a" 69 --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT CONCAT(COUNT(*), '/', MAX(discoverable), '/', MAX(nazev)) FROM ka_uzivatele_klice WHERE idu = $P335_USER")|$(sq "SELECT JSON_LENGTH(totp_zalozni, '\$.codes') FROM ka_uzivatele WHERE idu = $P335_USER")|$(p335_page "$J1" "?action=account" > /dev/null; grep -o '[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}' "$WORK/response" | wc -l | tr -d ' ')|$(p335_page "$J1" "?action=account" > /dev/null; grep -c '[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}' "$WORK/response")" \
  "200|200|1/1/Laptop 335|8|8|0"
p335_page "$J1" "?action=account" > /dev/null; P335_CODES=($(grep -o '[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}' "$WORK/response" || true))
# the codes were shown once already – take them from a fresh registration on a throw-away account instead: set known ones
P335_BACKUP="b335a-$(openssl rand -hex 2)x"; P335_BACKUP2="b335b-$(openssl rand -hex 2)y"; P335_BACKUP3="b335c-$(openssl rand -hex 2)z"
sq "UPDATE ka_uzivatele SET totp_zalozni = JSON_ARRAY(SHA2('$P335_BACKUP', 256), SHA2('$P335_BACKUP2', 256), SHA2('$P335_BACKUP3', 256)) WHERE idu = $P335_USER" > /dev/null
curl -s -b "$J1" -c "$J1" -o /dev/null -X POST "$B/admin.php?action=logout" -d "_csrf=$P335_CSRF"

# the sign-in without a password
J2="$WORK/jar-p335-2"; rm -f "$J2"
expect "3.35 A: a passkey with UV signs in without a user name and password; the change log says so" \
  "$(p335_passkey "$J2" "$WORK/pk335-a" 5 1)|$(grep -c '"ok":true' "$WORK/response")|$(p335_signed_in "$J2")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $P335_USER AND akce = 'login' AND popis IN ('with a passkey, without a password', 'přihlašovacím klíčem, bez hesla')")|$(sq "SELECT pocitadlo FROM ka_uzivatele_klice WHERE idu = $P335_USER")" \
  "200|1|1|1|1"
J3="$WORK/jar-p335-3"; rm -f "$J3"
p335_token=$(p335_page "$J3")
p335_message() { php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j["chyba"] ?? "";' "$WORK/response"; }
P335_REPLAY=$(curl -s -b "$J3" -c "$J3" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$p335_token" -d krok=passkey --data-urlencode "odpoved@$WORK/p335-assertion")
P335_FAILED=$(p335_message) # the one answer of every failure, in the language of the sign-in page
expect "3.35 A: the same assertion again (a replay) is refused – the challenge was used once" "$P335_REPLAY|$(grep -c 'did not succeed\|se nepovedlo' "$WORK/response")" "401|1"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
expect "3.35 A: without the UV flag – refused with the one answer, counted on the account" \
  "$(p335_passkey "$J3" "$WORK/pk335-a" 1 2)|$([ "$(p335_message)" = "$P335_FAILED" ] && echo same)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $P335_USER")|$(p335_signed_in "$J3")" "401|same|1|0"
expect "3.35 A: an unknown passkey – the same answer (no user enumeration), counted on the address only" \
  "$(p335_passkey "$J3" "$WORK/pk335-a" 5 3 "$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(16)), "+/", "-_"), "=");')")|$([ "$(p335_message)" = "$P335_FAILED" ] && echo same)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $P335_USER")|$(sq "SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'login'")" "401|same|1|2"
expect "3.35 A: a user handle that is not the account's is refused" "$(p335_passkey "$J3" "$WORK/pk335-a" 5 4 - "$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(16)), "+/", "-_"), "=");')")|$([ "$(p335_message)" = "$P335_FAILED" ] && echo same)" "401|same"
expect "3.35 A: a counter that does not grow (a copied key) is refused" "$(p335_passkey "$J3" "$WORK/pk335-a" 5 1)|$(p335_signed_in "$J3")" "401|0"
# the lock and the limits of the password sign-in
sq "UPDATE ka_uzivatele SET pocet_chyb = 9 WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
p335_passkey "$J3" "$WORK/pk335-a" 1 5 > /dev/null
# 3.35 review M1: the lock is for the password – a valid passkey still signs in and ends it (smoke 2410 has the rest)
expect "3.35 A: the tenth failure in a row locks the account; a valid passkey still signs in and ends the lock" \
  "$(sq "SELECT zamceno_do IS NOT NULL FROM ka_uzivatele WHERE idu = $P335_USER")|$(p335_passkey "$J3" "$WORK/pk335-a" 5 6)|$(p335_signed_in "$J3")|$(sq "SELECT CONCAT(zamceno_do IS NULL, '/', pocet_chyb) FROM ka_uzivatele WHERE idu = $P335_USER")" "1|200|1|1/0"
rm -f "$J3"
sq "UPDATE ka_uzivatele SET zamceno_do = NULL, pocet_chyb = 0 WHERE idu = $P335_USER; UPDATE ka_uzivatele SET blokovat = 1 WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
expect "3.35 A: a blocked account never signs in with its passkey" "$(p335_passkey "$J3" "$WORK/pk335-a" 5 7)|$([ "$(p335_message)" = "$P335_FAILED" ] && echo same)" "401|same"
sq "UPDATE ka_uzivatele SET blokovat = 0 WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
for i in 1 2 3 4 5 6 7 8 9 10; do p335_passkey "$J3" "$WORK/pk335-a" 5 8 "x$i$(openssl rand -hex 8)" > /dev/null; done
expect "3.35 A: after 10 failures from one address in 15 minutes even a valid passkey waits" "$(p335_passkey "$J3" "$WORK/pk335-a" 5 8)|$(grep -c 'Too many sign-in attempts\|Příliš mnoho pokusů' "$WORK/response")" "401|1"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE idu = $P335_USER" > /dev/null

# the password of an account with a passkey always needs a second step
J4="$WORK/jar-p335-4"; rm -f "$J4"
expect "3.35 A: passkey + password – the password alone does not sign in; the second step offers a backup code" \
  "$(p335_password "$J4")|$(grep -c 'name="kod"' "$WORK/response")|$(grep -c 'data-klic-prihlasit' "$WORK/response")|$(p335_signed_in "$J4")" "200|1|1|0"
expect "3.35 A: a backup code completes it; right after it a passkey can be added without the password (fresh confirmation)" \
  "$(p335_code "$J4" "$P335_BACKUP")|$(p335_signed_in "$J4")|$(P335_CSRF=$(p335_page "$J4" "?action=account"); p335_account "$J4" -d co=klic_moznosti)" "302|1|200"
J5="$WORK/jar-p335-5"; rm -f "$J5"
P335_SECRET=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::newSecret();' "$ROOT")
p335_totp() { php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code($argv[2], intdiv(time(), 30) + (int) $argv[3]);' "$ROOT" "$P335_SECRET" "${1:-0}"; }
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '$P335_SECRET' WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
expect "3.35 A: passkey + app – the code from the app still completes the password sign-in (a session that is not fresh)" "$(p335_password "$J5")|$(p335_code "$J5" "$(p335_totp)")|$(p335_signed_in "$J5")" "200|302|1"
P335_CSRF=$(p335_page "$J5" "?action=account")
P335_KEY=$(sq "SELECT idk FROM ka_uzivatele_klice WHERE idu = $P335_USER ORDER BY idk LIMIT 1")
expect "3.35 A: removing a passkey needs the password when the session is not fresh; renaming does not" \
  "$(p335_account "$J5" -d co=klic_smaz -d "idk=$P335_KEY")|$(sq "SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idk = $P335_KEY")|$(p335_account "$J5" -d co=klic_prejmenuj -d "idk=$P335_KEY" -d nazev=Phone-335)|$(sq "SELECT nazev FROM ka_uzivatele_klice WHERE idk = $P335_KEY")" \
  "302|1|302|Phone-335"
expect "3.35 A: turning off the app keeps the passkeys and the backup codes" \
  "$(p335_account "$J5" -d co=totp_vypni --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT CONCAT(totp_tajemstvi = '', '/', JSON_LENGTH(totp_zalozni), '/', (SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idu = $P335_USER)) FROM ka_uzivatele WHERE idu = $P335_USER")" "302|1/2/1"
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '$P335_SECRET' WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null

# passkey only – the account's choice
expect "3.35 A: passkey only needs a fresh confirmation; with the password it is on" \
  "$(p335_account "$J5" -d co=passkey_only -d passkey_only=1)|$(sq "SELECT passkey_only FROM ka_uzivatele WHERE idu = $P335_USER")|$(p335_account "$J5" -d co=passkey_only -d passkey_only=1 --data-urlencode "soucasne=$PASSWORD")|$(sq "SELECT passkey_only FROM ka_uzivatele WHERE idu = $P335_USER")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $P335_USER AND akce = 'passkey_only_on'")" \
  "302|0|302|1|1"
J6="$WORK/jar-p335-6"; rm -f "$J6"
expect "3.35 A: passkey only – after the password the code from the app is refused, the field asks for a backup code" \
  "$(p335_password "$J6")|$(grep -c 'Backup code:\|Záložní kód:' "$WORK/response")|$(p335_code "$J6" "$(p335_totp 1)")|$(p335_signed_in "$J6")" "200|1|401|0"
expect "3.35 A: passkey only – a backup code after the password is the way back" "$(p335_password "$J6")|$(p335_code "$J6" "$P335_BACKUP2")|$(p335_signed_in "$J6")" "200|302|1"
expect "3.35 A: passkey only – the passkey alone still signs in" "$(rm -f "$WORK/jar-p335-7"; p335_passkey "$WORK/jar-p335-7" "$WORK/pk335-a" 5 20)|$(p335_signed_in "$WORK/jar-p335-7")" "200|1"
# a password-reset e-mail changes the password only – the account keeps its second step
P335_TOKEN=$(openssl rand -hex 32); P335_NEWPW="Reset-335-$(openssl rand -hex 6)"
sq "UPDATE ka_uzivatele SET obnova_otisk = SHA2('$P335_TOKEN', 256), obnova_cas = '$(site_time)' WHERE idu = $P335_USER" > /dev/null
J8="$WORK/jar-p335-8"; rm -f "$J8"
curl -s -b "$J8" -c "$J8" -o "$WORK/response" "$B/admin.php?action=password&token=$P335_TOKEN"
curl -s -b "$J8" -c "$J8" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$(csrf)" -d "token=$P335_TOKEN" --data-urlencode "password=$P335_NEWPW" --data-urlencode "password2=$P335_NEWPW"
expect "3.35 A: after a password reset a passkey-only account still needs its passkey or a backup code (the app's code is refused)" \
  "$(sq "SELECT obnova_otisk = '' AND passkey_only = 1 FROM ka_uzivatele WHERE idu = $P335_USER")|$(p335_password "$J8" "$P335_NEWPW")|$(p335_signed_in "$J8")|$(sq "UPDATE ka_kontrola_ip SET cil = NULL WHERE typ = 'totp'"; p335_code "$J8" "$(p335_totp -1)")" "1|200|0|401"
sq "UPDATE ka_uzivatele SET passkey_only = 0, password = '$P335_HASH' WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null

# passkey only – the site's setting, the safe rollout and the escape hatch
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=security"; TOKEN=$(csrf)
expect "3.35 A: the Security tab offers the setting with the config.php escape hatch" "$(grep -c 'name="signin_passkey_only"' "$WORK/response")|$(grep -c 'KALETA_PASSKEY_ONLY_OFF' "$WORK/response")" "1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=security -d signin_passkey_only=1
expect "3.35 A: an administrator without a passkey that has signed in cannot require passkeys of the site" "$(sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'signin_passkey_only'), '')")" ""
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('signin_passkey_only', '1') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
J9="$WORK/jar-p335-9"; rm -f "$J9"
expect "3.35 A: the site requires passkeys – an account with one is passkey-only (the app's code refused, a backup code works)" \
  "$(p335_password "$J9")|$(p335_code "$J9" "$(p335_totp)")|$(p335_password "$J9")|$(p335_code "$J9" "$P335_BACKUP3")|$(p335_signed_in "$J9")" "200|401|200|302|1"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
J10="$WORK/jar-p335-10"; rm -f "$J10"; t=$(p335_page "$J10")
expect "3.35 A: the site requires passkeys – an account without one signs in as before and hears it should add one" \
  "$(curl -s -b "$J10" -c "$J10" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=admin --data-urlencode "password=$PASSWORD")|$(curl -s -b "$J10" -o "$WORK/response" "$B/admin.php?action=account"; grep -c 'in My account – until\|v Mém účtu – do té doby' "$WORK/response")|$(grep -c 'below – until\|níže klíč pro toto' "$WORK/response")" "302|1|1"
php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/^<\?php/", "<?php\ndefine(\x27KALETA_PASSKEY_ONLY_OFF\x27, true); // p335\n", (string) file_get_contents($f), 1));' "$WORK/web/config.php"; sleep 3 # the opcache of the test server revalidates files every 2 s
J11="$WORK/jar-p335-11"; rm -f "$J11"
expect "3.35 A: KALETA_PASSKEY_ONLY_OFF in config.php switches passkey only off – the password and the app's code work again" \
  "$(p335_password "$J11")|$(sq "UPDATE ka_kontrola_ip SET cil = NULL WHERE typ = 'totp'"; p335_code "$J11" "$(p335_totp 1)")|$(p335_signed_in "$J11")" "200|302|1"
php -r '$f = $argv[1]; file_put_contents($f, str_replace("\ndefine(\x27KALETA_PASSKEY_ONLY_OFF\x27, true); // p335\n", "", (string) file_get_contents($f)));' "$WORK/web/config.php"; sleep 3
sq "DELETE FROM ka_nastaveni WHERE promenna = 'signin_passkey_only'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null

# an older passkey (registered as the second step before 3.35): the second step only, and "register again" replaces it
P335_OLDCSRF=$(p335_page "$J5" "?action=account")
php "$WORK/pk335.php" register "$WORK/pk335-old" "$P335_ORIGIN" 65 < "$WORK/p335-reg-options" > /dev/null
P335_OLD_PUB=$(php -r '$s = json_decode(file_get_contents($argv[1]), true); echo openssl_pkey_get_details(openssl_pkey_get_private($s["pem"]))["key"];' "$WORK/pk335-old")
P335_OLD_ID=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["id"];' "$WORK/pk335-old")
P335_OLD_HASH=$(php -r '$b = base64_decode(strtr($argv[1], "-_", "+/")); echo hash("sha256", $b);' "$P335_OLD_ID")
sq "INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno, discoverable) VALUES ($P335_USER, 'Old key', '$P335_OLD_HASH', '$P335_OLD_ID', '$P335_OLD_PUB', -7, 0, '$(site_time)', 0)" > /dev/null
J12="$WORK/jar-p335-12"; rm -f "$J12"
expect "3.35 A: an older second-step passkey does not sign in without a password – its owner hears to register it again" \
  "$(p335_passkey "$J12" "$WORK/pk335-old" 5 1)|$(grep -c 'second step only\|jen jako druhý krok' "$WORK/response")|$(p335_signed_in "$J12")" "401|1|0"
p335_password "$J12" > /dev/null; t=$(csrf)
curl -s -b "$J12" -c "$J12" -o "$WORK/p335-options" -X POST "$B/admin.php" -d "_csrf=$t" -d krok=klic_moznosti
php "$WORK/pk335.php" assert "$WORK/pk335-old" "$P335_ORIGIN" 1 2 < "$WORK/p335-options" > "$WORK/p335-assertion"
expect "3.35 A: the older passkey keeps working as the second step after the password (without UV, as before)" \
  "$(grep -cF -e "$P335_OLD_ID" "$WORK/p335-options")|$(curl -s -b "$J12" -c "$J12" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=klic --data-urlencode "odpoved@$WORK/p335-assertion")|$(p335_signed_in "$J12")" "1|200|1"
P335_OLD_IDK=$(sq "SELECT idk FROM ka_uzivatele_klice WHERE otisk_id = '$P335_OLD_HASH'")
P335_CSRF=$(p335_page "$J12" "?action=account")
expect "3.35 A: My account marks it \"second step only\" with a \"Register again\" button; registering again (fresh after the passkey step) replaces it" \
  "$(grep -c "data-nahradit=\"$P335_OLD_IDK\"" "$WORK/response")|$(p335_register "$J12" "$WORK/pk335-b" 69 -d "nahradit=$P335_OLD_IDK" -d nazev=)|$(sq "SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idk = $P335_OLD_IDK")|$(sq "SELECT CONCAT(COUNT(*), '/', SUM(discoverable), '/', SUM(nazev = 'Old key')) FROM ka_uzivatele_klice WHERE idu = $P335_USER")" \
  "1|200|200|0|2/2/1"

# recovery by an administrator: the reset takes the passkeys, the app, the codes and "passkey only"; the password signs in again
sq "UPDATE ka_uzivatele SET passkey_only = 1, pocet_chyb = 0 WHERE idu = $P335_USER; UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$P335_USER"; TOKEN=$(csrf)
expect "3.35 A: Users offers the reset for an account with passkeys" "$(grep -c 'name="totp_reset"' "$WORK/response")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$P335_USER" -d user=pk335 -d jmeno="Passkey Tester" -d email=pk335@example.com -d admin=1 -d totp_reset=1 --data-urlencode "soucasne=$PASSWORD"
J13="$WORK/jar-p335-13"; rm -f "$J13"
expect "3.35 A: after the administrator's reset the account has no passkeys, app, codes or passkey only – the password signs in" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_uzivatele_klice WHERE idu = $P335_USER), '/', totp_tajemstvi, '/', IFNULL(totp_zalozni, 'null'), '/', passkey_only) FROM ka_uzivatele WHERE idu = $P335_USER")|$(p335_password "$J13")|$(p335_signed_in "$J13")" "0//null/0|302|1"
sq "DELETE FROM ka_uzivatele WHERE idu = $P335_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
