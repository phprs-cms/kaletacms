echo "== 3.35 review M3, L6: passkeys are counted on the host of the site address; a new host needs a confirmation and a fresh proof"
# A passkey row of the account pk418 bound to the current host makes its password need a second step (it has no app and no
# backup codes); after the site address moves to another host (confirmed, with the administrator's password) that passkey
# no longer counts – the account signs in with its password alone instead of a dead end – System status says so, My
# account marks the passkey. L6: an explicit default port, a trailing dot or slash of the site address are normalised.
R418_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
R418_URL=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")
R418_HOST=$(php -r 'echo strtolower(parse_url($argv[1], PHP_URL_HOST));' "$R418_URL")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk418', '$R418_HASH', 'Passkey Tester 418', 'pk418@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
R418_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk418'")
sq "INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno, discoverable, rp_id) VALUES ($R418_USER, 'Phone 418', SHA2('pk418-key', 256), 'cGs0MTg', 'x', -7, 0, '$(site_time)', 1, '$R418_HOST')" > /dev/null
r418_password() { local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk418 --data-urlencode "password=$PASSWORD"; }
r418_age() { local id dir; id=$(awk '$6 == "kaleta" {print $7}' "$1" | tail -1); dir=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
  sed -i.bak -e 's/verified_at|i:[0-9]*;/verified_at|i:1;/' -e 's/strong_at|i:[0-9]*;/strong_at|i:1;/' "$dir/sess_$id" && rm -f "$dir/sess_$id.bak"; }
r418_save() { settings_form general "$WORK/r418.post"; printf '%s' "$1" >> "$WORK/r418.post"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/r418.post"; }
r418_url() { sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'"; }
P1="$WORK/jar-r418-1"; rm -f "$P1"
expect "3.35 review M3: a passkey of this host – the password of pk418 waits for the second step" "$(r418_password "$P1")|$(p335_signed_in "$P1")" "200|0"

r418_age "$JAR"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=general"
expect "3.35 review M3: General warns that passkeys follow the host, with the confirmation and the password field" \
  "$(grep -c 'name="site_url_confirm"' "$WORK/response")|$(grep -c 'id="settings-soucasne-general"' "$WORK/response")" "1|1"
R418_NEW="http://localhost:${PORT}"
expect "3.35 review M3: another host without the confirmation – not saved, the reason says why" \
  "$(r418_save "&site_url=$R418_NEW")|$(r418_url)" "302|$R418_URL"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=general"
expect "3.35 review M3: … the message names the host change" "$(grep -c 'Tick the confirmation under the address\|Zaškrtněte potvrzení pod adresou' "$WORK/response")" "1"
expect "3.35 review M3: confirmed but without the administrator's password – not saved" \
  "$(r418_save "&site_url=$R418_NEW&site_url_confirm=1")|$(r418_url)" "302|$R418_URL"
expect "3.35 review L6: the same host with an explicit default port, a trailing dot and slash is no host change – saved normalised" \
  "$(r418_save "&site_url=$(php -r '$u = parse_url($argv[1]); echo $u["scheme"] . "://" . $u["host"] . ".:" . ($u["port"] ?? ($u["scheme"] === "https" ? 443 : 80)) . "/";' "$R418_URL")")|$(r418_url)" "302|$R418_URL"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
expect "3.35 review M3: confirmed and with the password – saved; the change log says so" \
  "$(r418_save "&site_url=$R418_NEW&site_url_confirm=1&soucasne=$PASSWORD")|$(r418_url)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'site_host_change'")" "302|$R418_NEW|1"
P2="$WORK/jar-r418-2"; rm -f "$P2"
expect "3.35 review M3: the passkey of the old host no longer counts – pk418 signs in with the password alone, never a dead end" "$(r418_password "$P2")|$(p335_signed_in "$P2")" "302|1"
curl -s -b "$P2" -o "$WORK/response" "$B/admin.php?action=account"
expect "3.35 review M3: My account marks the passkey of the other address" "$(grep -c "another address: $R418_HOST\|jiná adresa: $R418_HOST" "$WORK/response")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.35 review M3: System status reports passkeys of another host name" "$(grep -q 'Passkeys of another address\|Klíče jiné adresy' "$WORK/response" && echo shown)" "shown"
sq "UPDATE ka_nastaveni SET hodnota = '$R418_URL' WHERE promenna = 'site_url'; DELETE FROM ka_uzivatele_klice WHERE idu = $R418_USER; DELETE FROM ka_uzivatele WHERE idu = $R418_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
