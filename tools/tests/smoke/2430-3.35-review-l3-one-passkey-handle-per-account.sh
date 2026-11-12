echo "== 3.35 review L3: two passkey option requests at the same moment end with one handle"
# Two sessions of pk430 (no handle yet) ask for the options of a new passkey at the same time; both answers carry the one
# handle the account keeps (UPDATE … WHERE passkey_handle = '' and a read back). Uses p335_page of 2300.
R430_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk430', '$R430_HASH', 'Tester 430', 'pk430@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
R430_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk430'")
for n in 1 2; do
  jar="$WORK/jar-r430-$n"; rm -f "$jar"; t=$(p335_page "$jar"); curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$t" -d user=pk430 --data-urlencode "password=$PASSWORD"
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?action=account"; eval "R430_T$n=\$(csrf)"
done
curl -s -b "$WORK/jar-r430-1" -o "$WORK/r430-1" -X POST "$B/admin.php?action=account" -d "_csrf=$R430_T1" -d co=klic_moznosti --data-urlencode "soucasne=$PASSWORD" & R430_P1=$!
curl -s -b "$WORK/jar-r430-2" -o "$WORK/r430-2" -X POST "$B/admin.php?action=account" -d "_csrf=$R430_T2" -d co=klic_moznosti --data-urlencode "soucasne=$PASSWORD" & R430_P2=$!
wait "$R430_P1" "$R430_P2" # only these two – the servers of the run are background jobs too
R430_HANDLE=$(sq "SELECT passkey_handle FROM ka_uzivatele WHERE idu = $R430_USER")
r430_id() { php -r '$o = json_decode((string) file_get_contents($argv[1]), true); echo $o["user"]["id"] ?? "none";' -- "$1"; }
expect "3.35 review L3: both answers carry the handle the account keeps" "$([ "$(r430_id "$WORK/r430-1")" = "$R430_HANDLE" ] && echo same)|$([ "$(r430_id "$WORK/r430-2")" = "$R430_HANDLE" ] && echo same)|${#R430_HANDLE}" "same|same|22"
sq "DELETE FROM ka_uzivatele WHERE idu = $R430_USER; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
