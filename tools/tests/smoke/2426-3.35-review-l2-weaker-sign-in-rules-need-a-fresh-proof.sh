echo "== 3.35 review L2: turning “passkeys only” off and changing the two-factor rule need a fresh proof"
# The administrator's session (JAR) has its proofs pushed back; Settings → Security then saves neither change without the
# current password, and saves them with it. An administrator who signs in with a passkey only (pk426) is not confirmed by
# the password at all – "Confirm with your passkey" (through My account) is its proof. Uses 2300's authenticator and p335_*.
r426_age() { local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$1" | tail -1); dir=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
  sed -i.bak -e 's/verified_at|i:[0-9]*;/verified_at|i:1;/' -e 's/strong_at|i:[0-9]*;/strong_at|i:1;/' "$dir/sess_$id" && rm -f "$dir/sess_$id.bak"; }
r426_set() { sq "SELECT CONCAT(IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'require_2fa'), ''), '/', IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'signin_passkey_only'), ''))"; }
r426_save() { local jar="$1"; shift; curl -s -b "$jar" -c "$jar" -o /dev/null "$B/admin.php?module=settings&tab=security" # shows (and forgets) a refused value
  settings_form_as "$jar" security "$WORK/r426.post"; printf '%s' "$*" >> "$WORK/r426.post"; curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/r426.post"; }
settings_form_as() { # <jar> <tab> <file>: settings_form of 0060 for another session
  curl -s -b "$1" -c "$1" -o "$WORK/settings-form.html" "$B/admin.php?module=settings&tab=$2"
  php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
    foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
      $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
    echo implode("&", $q);' "$WORK/settings-form.html" > "$3"; }
R426_BEFORE=$(r426_set)
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('signin_passkey_only', '1'), ('require_2fa', '')" > /dev/null
r426_age "$JAR"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=security"
expect "3.35 review L2: Security asks for the current password under its fields" "$(grep -c 'id="settings-soucasne-security"' "$WORK/response")" "1"
expect "3.35 review L2: without the password – neither the two-factor rule nor turning passkeys only off is saved" \
  "$(r426_save "$JAR" "&require_2fa=vsichni&signin_passkey_only=0")|$(r426_set)" "302|/1"
expect "3.35 review L2: a wrong password – not saved, counted like a wrong sign-in" \
  "$(r426_save "$JAR" "&signin_passkey_only=0&soucasne=wrong-password-426")|$(r426_set)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE user = 'admin'")" "302|/1|1"
expect "3.35 review L2: an unchanged save needs no password" "$(r426_save "$JAR" "")|$(r426_set)" "302|/1"
expect "3.35 review L2: with the password – passkeys only goes off" "$(r426_save "$JAR" "&signin_passkey_only=0&soucasne=$PASSWORD")|$(r426_set)" "302|/0"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null

# an administrator who signs in with a passkey only
R426_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk426', '$R426_HASH', 'Admin 426', 'pk426@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0)" > /dev/null
R426_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk426'")
L1J="$WORK/jar-r426-1"; rm -f "$L1J"
t=$(p335_page "$L1J"); curl -s -b "$L1J" -c "$L1J" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk426 --data-urlencode "password=$PASSWORD"
P335_CSRF=$(p335_page "$L1J" "?action=account")
p335_register "$L1J" "$WORK/pk426-a" 69 --data-urlencode "soucasne=$PASSWORD" > /dev/null
sq "UPDATE ka_uzivatele SET passkey_only = 1 WHERE idu = $R426_USER; DELETE FROM ka_nastaveni WHERE promenna = 'require_2fa'" > /dev/null
rm -f "$L1J"; p335_passkey "$L1J" "$WORK/pk426-a" 5 1 > /dev/null; r426_age "$L1J"
curl -s -b "$L1J" -c "$L1J" -o "$WORK/response" "$B/admin.php?module=settings&tab=security"
expect "3.35 review L2: a passkey-only administrator gets “Confirm with your passkey” (the form posts it to My account), no password field" \
  "$(grep -c 'id="settings-soucasne-security"' "$WORK/response")|$(grep -c 'data-klic-potvrdit' "$WORK/response")|$(grep -c 'data-klice="/admin.php?action=account"' "$WORK/response")|$(grep -c 'image/klice.js' "$WORK/response")" "0|1|1|1"
expect "3.35 review L2: its right password does not confirm the change of the two-factor rule" "$(r426_save "$L1J" "&require_2fa=spravci&soucasne=$PASSWORD")|$(r426_set)" "302|/0"
P335_CSRF=$(csrf)
curl -s -b "$L1J" -c "$L1J" -o "$WORK/r426-options" -X POST "$B/admin.php?action=account" -d "_csrf=$P335_CSRF" -d krok=passkey_confirm_options
php "$WORK/pk335.php" assert "$WORK/pk426-a" "$P335_ORIGIN" 5 2 < "$WORK/r426-options" > "$WORK/r426-assertion"
expect "3.35 review L2: after “Confirm with your passkey” the change is saved" \
  "$(curl -s -b "$L1J" -c "$L1J" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=account" -d "_csrf=$P335_CSRF" -d krok=passkey_confirm --data-urlencode "odpoved@$WORK/r426-assertion")|$(r426_save "$L1J" "&require_2fa=spravci")|$(r426_set)" "200|302|spravci/0"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('require_2fa', 'signin_passkey_only'); DELETE FROM ka_uzivatele_klice WHERE idu = $R426_USER; DELETE FROM ka_uzivatele WHERE idu = $R426_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
[ "$R426_BEFORE" = "/" ] || echo "  note   the settings before were $R426_BEFORE (put back to defaults)"
