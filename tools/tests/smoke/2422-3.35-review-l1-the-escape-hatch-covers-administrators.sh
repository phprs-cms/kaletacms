echo "== 3.35 review L1: KALETA_PASSKEY_ONLY_OFF also lets an administrator with only a passkey sign in with the password"
# Three accounts with a passkey of this host and no app: pk422a (administrator, no backup codes), pk422b (editor, no backup
# codes) and pk422c (administrator with backup codes). Without the constant each password needs the second step; with it
# only pk422a signs in with the password alone, and every admin page warns until the constant is removed.
R422_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
R422_HOST=$(php -r 'echo strtolower(parse_url($argv[1], PHP_URL_HOST));' "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, totp_zalozni) VALUES
  ('pk422a', '$R422_HASH', 'Admin 422', 'pk422a@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0, NULL),
  ('pk422b', '$R422_HASH', 'Editor 422', 'pk422b@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, NULL),
  ('pk422c', '$R422_HASH', 'Admin 422 c', 'pk422c@example.com', 'en', 2, '$(site_time)', '$(site_time)', 0, JSON_ARRAY(SHA2('pk422-code', 256)))" > /dev/null
for u in a b c; do
  sq "INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno, discoverable, rp_id) SELECT idu, 'Key 422', SHA2(CONCAT('pk422-key-', idu), 256), 'cGs0MjI', 'x', -7, 0, '$(site_time)', 1, '$R422_HOST' FROM ka_uzivatele WHERE user = 'pk422$u'" > /dev/null
done
r422_password() { local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
r422_try() { local jar="$WORK/jar-r422-$1"; rm -f "$jar"; echo "$(r422_password "$jar" "pk422$1")/$(p335_signed_in "$jar")"; }
expect "3.35 review L1: without the constant every password with a passkey waits for the second step (nothing changes)" "$(r422_try a)|$(r422_try b)|$(r422_try c)" "200/0|200/0|200/0"
php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/^<\?php/", "<?php\ndefine(\x27KALETA_PASSKEY_ONLY_OFF\x27, true); // r422\n", (string) file_get_contents($f), 1));' "$WORK/web/config.php"; sleep 3 # the opcache of the test server revalidates files every 2 s
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
expect "3.35 review L1: with the constant – the administrator with only a passkey signs in with the password; the editor and the administrator with backup codes still need the second step" \
  "$(r422_try a)|$(r422_try b)|$(r422_try c)" "302/1|200/0|200/0"
curl -s -b "$WORK/jar-r422-a" -o "$WORK/response" "$B/admin.php"
expect "3.35 review L1: every admin page warns while the constant is in config.php" "$(grep -c 'Passkey sign-in rules are switched off\|Pravidla přihlášení klíčem jsou vypnutá' "$WORK/response")" "1"
curl -s -b "$WORK/jar-r422-a" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.35 review L1: System status says so too" "$(grep -q 'switched off by KALETA_PASSKEY_ONLY_OFF\|vypnutá řádkem KALETA_PASSKEY_ONLY_OFF' "$WORK/response" && echo shown)" "shown"
php -r '$f = $argv[1]; file_put_contents($f, str_replace("\ndefine(\x27KALETA_PASSKEY_ONLY_OFF\x27, true); // r422\n", "", (string) file_get_contents($f)));' "$WORK/web/config.php"; sleep 3
sq "DELETE FROM ka_uzivatele_klice WHERE idu IN (SELECT idu FROM ka_uzivatele WHERE user LIKE 'pk422_'); DELETE FROM ka_uzivatele WHERE user LIKE 'pk422_'; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
