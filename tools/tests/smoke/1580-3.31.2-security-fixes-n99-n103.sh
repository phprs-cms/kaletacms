echo "== 3.31.2: security fixes (N99–N103)"
# N99: a drafts-only connection saves a draft whose card text carries the class of the collection list's page links – the
# class is left out with a note; a build stored with it (before 3.31.2) is rendered without it; ordinary classes stay
S3312_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0 ORDER BY idu LIMIT 1")
S3312_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($S3312_ADMIN, 's3312 drafts', 'drafts', 'all', SHA2('$S3312_TOKEN', 256), '$(site_time)')" > /dev/null
s3312_mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $S3312_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
sq "INSERT INTO ka_stranky (seo_link, titulek, text, v_menu, zobrazit) VALUES ('s3312-n99', 'N99', '', 0, 1)" > /dev/null
S3312_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 's3312-n99' AND jazyk = ''")
s3312_mcp save_build '{"id":'"$S3312_PAGE"',"build":{"v":1,"children":[{"type":"section","children":[{"id":"n99txt","type":"text","classes":["ka-kolekce-strany","card3312","ka-kolekce-filtry"],"content":{"html":"<p><a href=\"https://evil.example/p\">More</a></p>"}}]}]}}' > "$WORK/response"
expect "3.31.2 N99: save_build over a drafts connection leaves the \"ka-\" classes out and says so; the other class stays in the draft" \
  "$(grep -c 'reserved for Kaleta' "$WORK/response")|$(sq "SELECT stavba_koncept LIKE '%ka-kolekce%' FROM ka_stranky WHERE ids = $S3312_PAGE")|$(sq "SELECT stavba_koncept LIKE '%\"card3312\"%' FROM ka_stranky WHERE ids = $S3312_PAGE")" "1|0|1"
sq "UPDATE ka_stranky SET stavba_koncept = NULL, stavba = '{\"v\":1,\"deti\":[{\"id\":\"n99sec\",\"typ\":\"sekce\",\"znacka\":\"section\",\"obsah\":{},\"deti\":[{\"id\":\"n99txt\",\"typ\":\"text\",\"znacka\":\"div\",\"obsah\":{\"html\":\"<p><a href=\\\\\"https://evil.example/p\\\\\">More</a></p>\"},\"tridy\":[\"ka-kolekce-strany\",\"card3312\"]}]}]}' WHERE ids = $S3312_PAGE" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/s3312-n99"
expect "3.31.2 N99: a build stored with a \"ka-\" class renders without it – the element, its other class and its link stay" \
  "$(grep -c 'ka-kolekce-strany' "$WORK/response")|$(grep -c 'class="[^"]*card3312[^"]*"' "$WORK/response")|$(grep -c 'href="https://evil.example/p"' "$WORK/response")" "0|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"; TOKEN=$(csrf)
expect "3.31.2 N99: a shared class cannot take a \"ka-\" name in the builder; an ordinary name is still accepted" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_class" -d "_csrf=$TOKEN" -d name=ka-kolekce-strany -d pouziti=1)|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_class" -d "_csrf=$TOKEN" -d name=card3312 -d pouziti=1)" "400|200"
sq "DELETE FROM ka_stranky WHERE ids = $S3312_PAGE" > /dev/null
# N100: a signed-in session alone neither sets nor replaces the second factor – turning it on asks for the current password,
# and an account that has one keeps it until it is turned off (with the password); a backup code and the administrator's
# reset keep working. An administrator of its own (s3312) so the main account stays as the other checks left it.
S3312_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('s3312', '$S3312_HASH', 'Tester S3312', 's3312@example.com', '', 2, '$(site_time)', '$(site_time)', 0);
  DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null
S3312_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 's3312'")
S3312_JAR="$WORK/jar-s3312"; rm -f "$S3312_JAR"
curl -s -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=s3312 --data-urlencode "password=$PASSWORD"
s3312_account() { curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?action=account" -d "_csrf=$S3312_CSRF" "$@"; }
s3312_totp() { sq "SELECT CONCAT(totp_tajemstvi, '/', IFNULL(IFNULL(JSON_LENGTH(totp_zalozni, '\$.codes'), JSON_LENGTH(totp_zalozni)), 0)) FROM ka_uzivatele WHERE idu = $S3312_USER"; } # 3.35 review L4: {"codes": […]} or the older list
s3312_code() { php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code($argv[2], intdiv(time(), 30));' "$ROOT" "$1"; }
curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"; S3312_CSRF=$(csrf)
s3312_account -d co=totp_start > /dev/null
curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"
S3312_SECRET=$(grep -o '<code class="totp-klic">[A-Z2-7 ]*</code>' "$WORK/response" | head -1 | sed 's/<[^>]*>//g' | tr -d ' ')
expect "3.31.2 N100: turning two-factor sign-in on shows the key and asks for the current password" "$([ ${#S3312_SECRET} -ge 16 ] && echo key)|$(grep -c 'id="zap-heslo" name="soucasne"' "$WORK/response")" "key|1"
expect "3.31.2 N100: without the password, or with a wrong one, a right code turns nothing on" \
  "$(s3312_account -d co=totp_potvrd -d "kod=$(s3312_code "$S3312_SECRET")")|$(s3312_totp)|$(s3312_account -d co=totp_potvrd -d "kod=$(s3312_code "$S3312_SECRET")" -d soucasne=wrong-password-1)|$(s3312_totp)" "302|/0|302|/0"
expect "3.31.2 N100: with the password and the code it is on, with its backup codes" \
  "$(s3312_account -d co=totp_potvrd -d "kod=$(s3312_code "$S3312_SECRET")" --data-urlencode "soucasne=$PASSWORD")|$([ "$(s3312_totp)" = "$S3312_SECRET/8" ] && echo on)" "200|on"
# the attack of the audit: the session posts a new secret and confirms it – with or without the password, the owner's factor stays
s3312_account -d co=totp_start > /dev/null
curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"
expect "3.31.2 N100: with the second factor on, a new secret is not issued or confirmed – the app and the backup codes of the owner stay" \
  "$(grep -c 'totp_potvrd' "$WORK/response")|$(s3312_account -d co=totp_potvrd -d kod=123456)|$(s3312_account -d co=totp_potvrd -d kod=123456 --data-urlencode "soucasne=$PASSWORD")|$([ "$(s3312_totp)" = "$S3312_SECRET/8" ] && echo kept)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE kdo = $S3312_USER AND akce = 'two_factor_on'")" \
  "0|302|302|kept|1"
# recovery: turning it off with the password still works, and so does setting it up again afterwards
expect "3.31.2 N100: turning it off asks for the password as before; then it can be set up again" \
  "$(s3312_account -d co=totp_vypni -d soucasne=wrong-password-1 > /dev/null; [ "$(s3312_totp)" = "$S3312_SECRET/8" ] && echo kept)|$(s3312_account -d co=totp_vypni --data-urlencode "soucasne=$PASSWORD" > /dev/null; s3312_totp)|$(s3312_account -d co=totp_start > /dev/null; curl -s -b "$S3312_JAR" "$B/admin.php?action=account" | grep -c 'name="co" value="totp_potvrd"')" \
  "kept|/0|1"
# N101: a personal token needs the current password; a password change revokes the account's personal tokens unless the box is
# unticked (zrusit_tokeny=0) – a request without the field revokes too. Tokens of other accounts and OAuth clients of others stay.
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '', totp_zalozni = NULL WHERE idu = $S3312_USER" > /dev/null
curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"; S3312_CSRF=$(csrf)
s3312_tokens() { sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE idu = $S3312_USER AND druh = 'token'"; }
expect "3.31.2 N101: the token form asks for the current password; without it, or with a wrong one, no token is created (also full access without expiry)" \
  "$(grep -c 'id="token-heslo" name="soucasne"' "$WORK/response")|$(s3312_account -d co=token_novy -d nazev=s3312-a -d access=full -d platnost=0)|$(s3312_account -d co=token_novy -d nazev=s3312-a -d access=full -d platnost=0 -d soucasne=wrong-password-1)|$(grep -c 'kaleta_[a-f0-9]\{48\}' "$WORK/response")|$(s3312_tokens)" \
  "1|302|302|0|0"
expect "3.31.2 N101: with the current password the token is created and shown once" \
  "$(s3312_account -d co=token_novy -d nazev=s3312-a -d access=full -d platnost=0 --data-urlencode "soucasne=$PASSWORD")|$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | sort -u | wc -l | tr -d ' ')|$(sq "SELECT CONCAT(access, '/', IFNULL(expirace, 'none')) FROM ka_api_tokeny WHERE idu = $S3312_USER AND nazev = 's3312-a'")" "200|1|full/none"
S3312_PERSONAL=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1)
s3312_works() { curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'; }
S3312_OTHERS=$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE idu <> $S3312_USER")
S3312_NEW="Changed-$(openssl rand -hex 8)"
s3312_password() { curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"; S3312_CSRF=$(csrf); s3312_account -d co=heslo --data-urlencode "soucasne=$1" --data-urlencode "nove=$2" --data-urlencode "nove2=$2" "${@:3}" > /dev/null; }
expect "3.31.2 N101: the password form sends zrusit_tokeny=0 when the box is unticked, and the box is ticked" \
  "$(curl -s -b "$S3312_JAR" "$B/admin.php?action=account" | grep -c '<input type="hidden" name="zrusit_tokeny" value="0"><label><input type="checkbox" name="zrusit_tokeny" value="1" checked>')" "1"
s3312_password "$PASSWORD" "$S3312_NEW" -d zrusit_tokeny=0
expect "3.31.2 N101: the box unticked – the personal token survives the password change and still works" "$(s3312_tokens)|$(s3312_works "$S3312_PERSONAL")" "1|200"
s3312_password "$S3312_NEW" "$PASSWORD"
expect "3.31.2 N101: a password change without the field revokes the account's personal tokens – the token stops working; other accounts' tokens stay" \
  "$(s3312_tokens)|$(s3312_works "$S3312_PERSONAL")|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE idu <> $S3312_USER")|$(sq "SELECT popis FROM ka_protokol WHERE kdo = $S3312_USER AND akce = 'password_change' ORDER BY idp DESC LIMIT 1" | grep -c ': 1')" "0|401|$S3312_OTHERS|1"
# 3.31.2 (pre-release review): every "current password" in My account counts on the sign-in's own counter – ten wrong ones in a
# row (in any of its forms) lock the account for 15 minutes; an empty field is not counted; the session stays and says why;
# the right password changes nothing while the lock lasts, and a new sign-in is refused like any locked one
sq "UPDATE ka_uzivatele SET jazyk = 'en', pocet_chyb = 0, zamceno_do = NULL WHERE idu = $S3312_USER; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"; S3312_CSRF=$(csrf)
s3312_errors() { sq "SELECT CONCAT(pocet_chyb, '/', zamceno_do IS NOT NULL) FROM ka_uzivatele WHERE idu = $S3312_USER"; }
S3312_EMPTY="$(s3312_account -d co=token_novy -d nazev=s3312-c > /dev/null; s3312_errors)"
for i in 1 2 3 4 5 6 7 8; do s3312_account -d co=token_novy -d nazev=s3312-c -d "soucasne=wrong-password-$i" > /dev/null; done
s3312_account -d co=heslo -d soucasne=wrong-password-9 --data-urlencode "nove=$S3312_NEW" --data-urlencode "nove2=$S3312_NEW" > /dev/null
S3312_NINE=$(s3312_errors)
s3312_account -d co=profil -d jmeno=s3312 -d email=s3312-other@example.com -d soucasne=wrong-password-10 > /dev/null
expect "3.31.2 review: a wrong current password counts like a wrong sign-in (token, password, e-mail forms alike), an empty one does not; the tenth locks the account" \
  "$S3312_EMPTY|$S3312_NINE|$(s3312_errors)|$(sq "SELECT email FROM ka_uzivatele WHERE idu = $S3312_USER")" "0/0|9/0|0/1|s3312@example.com"
expect "3.31.2 review: the lock does not sign the person out – the page opens and says what happened" \
  "$(curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=account")|$(grep -c 'changes that need your password are locked for 15 minutes. You stay signed in' "$WORK/response")" "200|1"
S3312_CSRF=$(csrf); S3312_PW_BEFORE=$(sq "SELECT password FROM ka_uzivatele WHERE idu = $S3312_USER")
expect "3.31.2 review: while locked, the right password creates no token and changes no password; a new sign-in is refused" \
  "$(s3312_account -d co=token_novy -d nazev=s3312-c --data-urlencode "soucasne=$PASSWORD")|$(s3312_tokens)|$(s3312_account -d co=heslo --data-urlencode "soucasne=$PASSWORD" --data-urlencode "nove=$S3312_NEW" --data-urlencode "nove2=$S3312_NEW" > /dev/null; [ "$(sq "SELECT password FROM ka_uzivatele WHERE idu = $S3312_USER")" = "$S3312_PW_BEFORE" ] && echo same)|$(curl -s -c "$WORK/jar-s3312b" -o "$WORK/response" "$B/admin.php"; curl -s -b "$WORK/jar-s3312b" -c "$WORK/jar-s3312b" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=s3312 --data-urlencode "password=$PASSWORD")" \
  "302|0|same|401"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0, zamceno_do = NULL WHERE idu = $S3312_USER; DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
curl -s -b "$S3312_JAR" -c "$S3312_JAR" -o "$WORK/response" "$B/admin.php?action=account"; S3312_CSRF=$(csrf)
expect "3.31.2 review: after the lock the right password works again and resets nothing it should not" \
  "$(s3312_account -d co=token_novy -d nazev=s3312-c -d access=read --data-urlencode "soucasne=$PASSWORD")|$(s3312_tokens)|$(s3312_errors)" "200|1|0/0"
sq "DELETE FROM ka_api_tokeny WHERE idu = $S3312_USER AND nazev = 's3312-c'" > /dev/null
# the administrator's form in Users: the same rule (ticked or no field revokes, unticked keeps)
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($S3312_USER, 's3312-b', 'read', 'all', SHA2('s3312-b-$RANDOM', 256), '$(site_time)')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$S3312_USER"; TOKEN=$(csrf)
s3312_admin_sets() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$S3312_USER" -d "jmeno=Tester S3312" -d user=s3312 -d email=s3312@example.com -d admin=2 --data-urlencode "password=$PASSWORD" --data-urlencode "soucasne=$PASSWORD" "$@"; }
expect "3.31.2 N101: Users – the form sends 0 for the unticked box; unticked keeps the user's token, no field revokes it" \
  "$(grep -c '<input type="hidden" name="zrusit_tokeny" value="0">' "$WORK/response")|$(s3312_admin_sets -d zrusit_tokeny=0; s3312_tokens)|$(s3312_admin_sets; s3312_tokens)" "1|1|0"
# 3.31.2 (pre-release review): an administrator setting another user's password or turning off their second factor in Users
# confirms it with their own current password; without it (or with a wrong one) nothing of the save is written. Recovery
# still works with it: the factor and the passkeys go, the new password signs the user in.
S3312_SECRET2=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::newSecret();' "$ROOT")
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '$S3312_SECRET2', totp_zalozni = '[]', jmeno = 'Tester S3312' WHERE idu = $S3312_USER;
  INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno) VALUES ($S3312_USER, 's3312 key', SHA2('s3312-key-$RANDOM', 256), 'k3312', 'x', -7, 0, '$(site_time)')" > /dev/null
S3312_HASH_NOW=$(sq "SELECT password FROM ka_uzivatele WHERE idu = $S3312_USER")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$S3312_USER"; TOKEN=$(csrf)
s3312_users_save() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$S3312_USER" -d user=s3312 -d email=s3312@example.com -d admin=2 "$@"; }
s3312_state() { sq "SELECT CONCAT(password = '$S3312_HASH_NOW', '/', totp_tajemstvi <> '', '/', (SELECT COUNT(*) FROM ka_uzivatele_klice k WHERE k.idu = $S3312_USER), '/', jmeno) FROM ka_uzivatele WHERE idu = $S3312_USER"; }
S3312_NEW2="Reset-$(openssl rand -hex 8)"
expect "3.31.2 review: Users – another account's form asks for the administrator's current password" "$(grep -c 'id="soucasne" name="soucasne"' "$WORK/response")" "1"
expect "3.31.2 review: Users – a new password or a turned-off second factor without the administrator's password (or with a wrong one) saves nothing and says why" \
  "$(s3312_users_save -d jmeno=Changed --data-urlencode "password=$S3312_NEW2")|$(grep -c 'Nic se neuložilo\|Nothing was saved' "$WORK/response")|$(s3312_state)|$(s3312_users_save -d jmeno=Changed -d totp_reset=1 -d soucasne=wrong-password-1)|$(s3312_state)" \
  "200|1|1/1/1/Tester S3312|200|1/1/1/Tester S3312"
expect "3.31.2 review: Users – other changes need no password" "$(s3312_users_save -d jmeno=Renamed)|$(s3312_state)" "302|1/1/1/Renamed"
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
expect "3.31.2 review: Users – with it, recovery works: the second factor and passkeys go, the new password signs the user in" \
  "$(s3312_users_save -d jmeno=Renamed -d totp_reset=1 --data-urlencode "password=$S3312_NEW2" --data-urlencode "soucasne=$PASSWORD")|$(s3312_state)|$(curl -s -c "$WORK/jar-s3312c" -o "$WORK/response" "$B/admin.php"; curl -s -b "$WORK/jar-s3312c" -c "$WORK/jar-s3312c" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=s3312 --data-urlencode "password=$S3312_NEW2")" \
  "302|0/0/0/Renamed|302"
# one's own account in Users: the e-mail, a password and a two-factor reset are ignored (My account asks for the password)
S3312_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
S3312_ADMIN_ROW=$(sq "SELECT CONCAT(password, '/', email, '/', totp_tajemstvi) FROM ka_uzivatele WHERE idu = $S3312_ADMIN")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$S3312_ADMIN"; TOKEN=$(csrf)
expect "3.31.2 review: Users – one's own form has no password, current-password or two-factor fields and points to My account" \
  "$(grep -c 'name="password"\|name="soucasne"\|name="totp_reset"\|name="email"' "$WORK/response")|$(grep -c 'Můj účet – tam se ptá na současné heslo\|My account – it asks for your current password' "$WORK/response")" "0|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d "idu=$S3312_ADMIN" -d user=admin -d email=taken-over@example.com --data-urlencode "password=Taken-over-$RANDOM-x" -d totp_reset=1 -d admin=2
expect "3.31.2 review: Users – one's own e-mail, password and second factor stay as they were; the session stays signed in" \
  "$([ "$(sq "SELECT CONCAT(password, '/', email, '/', totp_tajemstvi) FROM ka_uzivatele WHERE idu = $S3312_ADMIN")" = "$S3312_ADMIN_ROW" ] && echo same)|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=users")" "same|200"
sq "DELETE FROM ka_api_tokeny WHERE idu = $S3312_USER OR nazev = 's3312 drafts'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $S3312_USER; DELETE FROM ka_uzivatele WHERE idu = $S3312_USER" > /dev/null
# N102: in maintenance mode visitors get 503, but the check after an update (/ulohy?probe=) and cron (/ulohy?token=) answer as
# usual – with their code only; the updater's own request for the home page gets the same page with 200 while the check runs
# (the updater of 3.31.1 and older reads a 503 as a broken release)
S3312_TASKS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'"); [ -n "$S3312_TASKS" ] || { S3312_TASKS="s3312tasks$RANDOM"; setting tasks_token "$S3312_TASKS"; }
S3312_MAINTENANCE=$(sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'maintenance'), '0')")
s3312_code() { curl -s -o "$WORK/response" -w '%{http_code}' "${@:2}" "$B$1"; }
setting maintenance 1; setting update_probe ''
expect "3.31.2 N102: maintenance mode – visitors get 503; the update check and cron are refused only for a wrong code (403), never by the maintenance page" \
  "$(s3312_code /)|$(s3312_code /kontakty)|$(s3312_code '/ulohy?probe=abc')|$(s3312_code '/ulohy?token=wrong')|$(s3312_code /ulohy)" "503|503|403|403|403"
setting update_probe s3312probe
expect "3.31.2 N102: … with the one-time code the check answers the running version" "$(s3312_code '/ulohy?probe=s3312probe')|$(cat "$WORK/response")" \
  "200|KALETA-PROBE $(sed -n "s/^const KALETA_VERSION = '\(.*\)';/\1/p" "$WORK/web/system/bootstrap.php")"
expect "3.31.2 N102: … while the check runs the updater's own request for the home page gets the maintenance page with 200; a visitor still 503" \
  "$(s3312_code / -A 'Kaleta-update-check/3.31.1')|$(grep -c '<h1' "$WORK/response")|$(s3312_code / -A 'Mozilla/5.0 test')" "200|1|503"
setting update_probe ''
expect "3.31.2 N102: … and never outside the check" "$(s3312_code / -A 'Kaleta-update-check/3.31.1')" "503"
sq "UPDATE ka_jobs SET last_run = '$(site_time)'" > /dev/null # nothing is due: the call itself is what is checked
expect "3.31.2 N102: cron runs in maintenance mode (tasks_last_run moves)" \
  "$(setting tasks_last_run 0; s3312_code "/ulohy?token=$S3312_TASKS")|$(head -c 3 "$WORK/response")|$(sq "SELECT hodnota > 0 FROM ka_nastaveni WHERE promenna = 'tasks_last_run'")" "200|OK |1"
setting maintenance "$S3312_MAINTENANCE"
