echo "== 3.35 review L4: backup codes – 64 bits under a salt of the list, used up by compare-and-swap"
# pk434 has a passkey row (so its password needs the second step) and backup codes. Two sign-ins at the same moment with
# two different codes both pass and leave neither code behind; the same code twice at the same moment signs in once.
# New codes from My account (64 bits, xxxx-xxxx-xxxx-xxxx) complete the sign-in typed without dashes in upper case.
R434_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
R434_HOST=$(php -r 'echo strtolower(parse_url($argv[1], PHP_URL_HOST));' -- "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk434', '$R434_HASH', 'Tester 434', 'pk434@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
R434_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk434'")
sq "INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno, discoverable, rp_id) VALUES ($R434_USER, 'Key 434', SHA2('pk434-key', 256), 'cGs0MzQ', 'x', -7, 0, '$(site_time)', 1, '$R434_HOST')" > /dev/null
r434_wait() { # <jar>: the password step, then the form token of the second step in R434_T
  rm -f "$1"; local t; t=$(p335_page "$1"); curl -s -b "$1" -c "$1" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk434 --data-urlencode "password=$PASSWORD"; R434_T=$(csrf); }
r434_codes() { sq "SELECT IFNULL(IFNULL(JSON_LENGTH(totp_zalozni, '\$.codes'), JSON_LENGTH(totp_zalozni)), 0) FROM ka_uzivatele WHERE idu = $R434_USER"; }
R434_A="a434-$(openssl rand -hex 3)"; R434_B="b434-$(openssl rand -hex 3)"; R434_C="c434-$(openssl rand -hex 3)"
sq "UPDATE ka_uzivatele SET totp_zalozni = JSON_ARRAY(SHA2('$R434_A', 256), SHA2('$R434_B', 256), SHA2('$R434_C', 256)) WHERE idu = $R434_USER" > /dev/null
r434_wait "$WORK/jar-r434-1"; R434_T1=$R434_T; r434_wait "$WORK/jar-r434-2"; R434_T2=$R434_T
curl -s -b "$WORK/jar-r434-1" -c "$WORK/jar-r434-1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$R434_T1" -d krok=kod -d "kod=$R434_A" & R434_P1=$!
curl -s -b "$WORK/jar-r434-2" -c "$WORK/jar-r434-2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$R434_T2" -d krok=kod -d "kod=$R434_B" & R434_P2=$!
wait "$R434_P1" "$R434_P2"
expect "3.35 review L4: two codes at the same moment – both sign in, neither comes back (an older list keeps its format)" \
  "$(p335_signed_in "$WORK/jar-r434-1")|$(p335_signed_in "$WORK/jar-r434-2")|$(r434_codes)|$(sq "SELECT LOCATE(SHA2('$R434_A', 256), totp_zalozni) + LOCATE(SHA2('$R434_B', 256), totp_zalozni) FROM ka_uzivatele WHERE idu = $R434_USER")" "1|1|1|0"
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
r434_wait "$WORK/jar-r434-3"; R434_T3=$R434_T; r434_wait "$WORK/jar-r434-4"; R434_T4=$R434_T
curl -s -b "$WORK/jar-r434-3" -c "$WORK/jar-r434-3" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$R434_T3" -d krok=kod -d "kod=$R434_C" & R434_P1=$!
curl -s -b "$WORK/jar-r434-4" -c "$WORK/jar-r434-4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$R434_T4" -d krok=kod -d "kod=$R434_C" & R434_P2=$!
wait "$R434_P1" "$R434_P2"
expect "3.35 review L4: the same code twice at the same moment signs in once" "$(( $(p335_signed_in "$WORK/jar-r434-3") + $(p335_signed_in "$WORK/jar-r434-4") ))|$(r434_codes)" "1|0"
# new codes from My account – right after a backup-code sign-in no password is needed
sq "UPDATE ka_uzivatele SET totp_zalozni = JSON_ARRAY(SHA2('$R434_A', 256)), pocet_chyb = 0 WHERE idu = $R434_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
r434_wait "$WORK/jar-r434-5"; p335_code "$WORK/jar-r434-5" "$R434_A" > /dev/null
P335_CSRF=$(p335_page "$WORK/jar-r434-5" "?action=account")
p335_account "$WORK/jar-r434-5" -d co=backup_codes_new > /dev/null
R434_NEW=($(grep -o '[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}-[0-9a-f]\{4\}' "$WORK/response" || true))
expect "3.35 review L4: eight new codes of 64 bits, stored under a salt of the list – no code in the database" \
  "${#R434_NEW[@]}|$(r434_codes)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(totp_zalozni, '\$.v')), LENGTH(JSON_UNQUOTE(JSON_EXTRACT(totp_zalozni, '\$.salt'))) FROM ka_uzivatele WHERE idu = $R434_USER" | tr '\t' '/')|$(sq "SELECT LOCATE('${R434_NEW[0]:-x}', totp_zalozni) + LOCATE(SHA2('${R434_NEW[0]:-x}', 256), totp_zalozni) FROM ka_uzivatele WHERE idu = $R434_USER")" "8|8|2/32|0"
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
r434_wait "$WORK/jar-r434-6"
expect "3.35 review L4: a new code completes the sign-in typed without dashes in upper case, once" \
  "$(p335_code "$WORK/jar-r434-6" "$(printf %s "${R434_NEW[2]:-x}" | tr -d '-' | tr 'a-f' 'A-F')")|$(p335_signed_in "$WORK/jar-r434-6")|$(r434_codes)" "302|1|7"
sq "DELETE FROM ka_uzivatele_klice WHERE idu = $R434_USER; DELETE FROM ka_uzivatele WHERE idu = $R434_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
