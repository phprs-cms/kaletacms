echo "== 3.46 B: a recent proof for every POST of an administrator's section and for every new connection (N127, N136, N138, N158, I39)"
# Admin\FreshProof: a POST of an admin-only module needs a proof within Auth::VERIFIED_WINDOW, otherwise "Confirm it is you"
# posts the same form again with the current password; FreshProof::EXEMPT names what goes without. The suite's
# administrator ($JAR) is confirmed before every section (proof_fresh in test.sh) – here proof_age pushes the proof back.
T4130_CSRF() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=${1:-settings}"; csrf; }
t4130_errors() { sq "SELECT pocet_chyb FROM ka_uzivatele WHERE user = '${1:-admin}'"; }
sq "UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null

# N127: the mail server waits for the proof; the same fields go again with the password and are saved
proof_age "$JAR"
settings_form mail "$WORK/t4130-mail.post"
T4130_REPLY=$(sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'mail_reply_to'), '')")
printf '%s' "&mail_reply_to=t4130%40example.com" >> "$WORK/t4130-mail.post"
T4130_CODE=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/t4130-confirm.html" -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/t4130-mail.post")
expect "3.46 N127: Settings → Mail without a recent proof – “Confirm it is you” (403), the change waits, the typed values are in the form again" \
  "$T4130_CODE|$(grep -c 'id="confirm-proof"' "$WORK/t4130-confirm.html")|$(grep -c 'name="mail_reply_to" value="t4130@example.com"' "$WORK/t4130-confirm.html")|$(grep -c 'name="soucasne"' "$WORK/t4130-confirm.html")|$(sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'mail_reply_to'), '')")" \
  "403|1|1|1|$T4130_REPLY"
expect "3.46 N127: the form goes to the same address with the same smtp_host – nothing new to fill in" \
  "$(grep -c 'action="/admin.php?module=settings&amp;action=save"' "$WORK/t4130-confirm.html")|$(grep -c 'name="smtp_host" value="127.0.0.1"' "$WORK/t4130-confirm.html")" "1|1"
# t4130_repost <file> [fields]: posts the form of "Confirm it is you" as a browser would, with extra fields
t4130_repost() { local f="$1"; shift
  php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $q = [];
    foreach ($x->query("//form[@id=\"confirm-proof\"]//input[@type=\"hidden\"]") as $e) { $q[] = rawurlencode($e->getAttribute("name")) . "=" . rawurlencode($e->getAttribute("value")); } echo implode("&", $q);' -- "$f" > "$WORK/t4130-repost"
  printf '%s' "$*" >> "$WORK/t4130-repost"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/t4130-after.html" -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/t4130-repost"; }
expect "3.46 N127: a wrong password – nothing saved, counted like a wrong sign-in, the form offered again" \
  "$(t4130_repost "$WORK/t4130-confirm.html" "&soucasne=wrong-4130")|$(grep -c 'The password is not correct\|Heslo není správné' "$WORK/t4130-after.html")|$(t4130_errors)|$(sq "SELECT IFNULL((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'mail_reply_to'), '')")" \
  "403|1|1|$T4130_REPLY"
expect "3.46 N127: with the current password the same form saves the change (the wrong try stays counted until a sign-in)" \
  "$(t4130_repost "$WORK/t4130-confirm.html" "&soucasne=$(php -r 'echo rawurlencode($argv[1]);' -- "$PASSWORD")")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'mail_reply_to'")|$(t4130_errors)" "302|t4130@example.com|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_reply_to', '$T4130_REPLY')" > /dev/null
T4130_T=$(T4130_CSRF)
expect "3.46: a right password is a proof for the next minutes – the next save of an admin section asks nothing" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$T4130_T" -d idu=0 -d jmeno=T4130 -d user=t4130 --data-urlencode "password=$PASSWORD" -d admin=0 -d email=t4130@example.com -d jazyk=en)|$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE user = 't4130'")" "302|1"

sq "UPDATE ka_uzivatele SET jazyk = 'en' WHERE user = 't4130'" > /dev/null

# N136: a new account, blocking, deleting – Users waits for the proof too; JSON for a script
proof_age "$JAR"; T4130_T=$(T4130_CSRF users)
expect "3.46 N136: a new account without a recent proof is not created" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$T4130_T" -d idu=0 -d jmeno=T4130b -d user=t4130b --data-urlencode "password=$PASSWORD" -d admin=0)|$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE user = 't4130b'")" "403|0"
expect "3.46: a request of a script gets the refusal as JSON" \
  "$(curl -s -b "$JAR" -c "$JAR" -H 'Accept: application/json' -o "$WORK/t4130.json" -w '%{http_code}' -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$T4130_T" -d nazev=R4130)|$(grep -c '"proof":true' "$WORK/t4130.json")|$(sq "SELECT COUNT(*) FROM ka_role WHERE nazev = 'R4130'")" "403|1|0"
# the exemptions: redirects (content) and revoking a person's connections go without; a non-admin section is not touched
expect "3.46: an exempt action (Redirects) and a section that is not the administrator's (Tags) need no proof" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$T4130_T" -d z_adresy=/t4130-old -d na_adresu=/kontakt -d typ=301)|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 't4130-old'")" "302|1"
sq "DELETE FROM ka_presmerovani WHERE z_adresy = 't4130-old'" > /dev/null

# I39: a backup leaves only on a POST with the form token and a recent proof
LAST_4130=$(ls -t "$WORK"/web/storage/zalohy/ | grep '^kaleta-' | head -1)
expect "3.46 I39: a GET of download_backup hands out nothing (back to Backups)" \
  "$(curl -s -b "$JAR" -o "$WORK/t4130-get" -w '%{http_code} %{content_type}' "$B/admin.php?module=settings&action=download_backup&file=$LAST_4130&soubor=$LAST_4130" | tr 'A-Z' 'a-z')|$(grep -c 'CREATE TABLE' "$WORK/t4130-get")" "302 text/html; charset=utf-8|0"
expect "3.46 I39: a POST without the form token is refused; with it but without a recent proof it asks to confirm" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=download_backup" -d "soubor=$LAST_4130")|$(curl -s -b "$JAR" -o "$WORK/t4130-post" -w '%{http_code}' -X POST "$B/admin.php?module=settings&action=download_backup" -d "_csrf=$T4130_T" -d "soubor=$LAST_4130")|$(grep -c 'id="confirm-proof"' "$WORK/t4130-post")" "400|403|1"
expect "3.46 I39: with the password the backup downloads whole, and the download is in the change log" \
  "$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/t4130-backup" -X POST "$B/admin.php?module=settings&action=download_backup" -d "_csrf=$T4130_T" -d "soubor=$LAST_4130" --data-urlencode "soucasne=$PASSWORD"; wc -c < "$WORK/t4130-backup" | tr -d ' ')|$(sq "SELECT COUNT(*) > 0 FROM ka_protokol WHERE modul = 'settings' AND akce = 'backup downloaded' AND popis = '$LAST_4130'")" \
  "$(wc -c < "$WORK/web/storage/zalohy/$LAST_4130" | tr -d ' ')|1"
expect "3.46 I39: the Backups tab offers the download as a button of the form, never as a link" \
  "$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; grep -c 'action=download_backup&amp;file=' "$WORK/response")|$(grep -c "formaction=\"/admin.php?module=settings&amp;action=download_backup\" name=\"soubor\" value=\"$LAST_4130\"" "$WORK/response")" "0|1"

# a form with a file: the file cannot be posted again – the proof alone goes to action=confirm, then back to the section
proof_age "$JAR"; T4130_T=$(T4130_CSRF transfer)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/t4130-file.html" -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$T4130_T" -F "soubor=@$ROOT/tools/fixtures/wordpress-sample.xml"
expect "3.46: an upload without a recent proof – the confirmation goes to action=confirm and says to choose the file again" \
  "$(grep -c 'action="/admin.php?action=confirm&amp;back=admin.php%3Fmodule%3Dtransfer"' "$WORK/t4130-file.html")|$(grep -c 'The file you chose was not sent\|Vybraný soubor se neodeslal' "$WORK/t4130-file.html")" "1|1"
expect "3.46: action=confirm with the password goes back to the section, and only to an address of the administration" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?action=confirm&back=admin.php%3Fmodule%3Dtransfer" -d "_csrf=$T4130_T" --data-urlencode "soucasne=$PASSWORD")|$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' "$B/admin.php?action=confirm&back=https://evil.example/x")" \
  "302 $B/admin.php?module=transfer|$B/admin.php"

# N138: setting up the authenticator app – not even the pending secret without a proof
T4130_JAR="$WORK/jar-t4130"; rm -f "$T4130_JAR"
curl -s -c "$T4130_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=t4130 --data-urlencode "password=$PASSWORD"
t4130_account() { curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o "$WORK/response" "$B/admin.php?action=account&tab=security"; csrf; } # 3.47: the security tab
proof_age "$T4130_JAR"; T4130_U=$(t4130_account)
expect "3.46 N138: without a recent proof the start form asks for the password" "$(grep -c 'id="start-heslo"' "$WORK/response")" "1"
curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$T4130_U" -d co=totp_start; t4130_account > /dev/null
expect "3.46 N138: a start without the proof stores no secret – no key on the page" "$(grep -c 'class="totp-klic"' "$WORK/response")|$(grep -c 'set up two-factor sign-in. Nothing was changed' "$WORK/response")" "0|1"
curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$T4130_U" -d co=totp_start --data-urlencode "soucasne=$PASSWORD"; t4130_account > /dev/null
expect "3.46 N138: with the password the key is shown (the confirmation still asks for the password)" "$(grep -c 'class="totp-klic"' "$WORK/response")|$(grep -c 'id="zap-heslo"' "$WORK/response")" "1|1"

# N158: every new connection – drafts too – needs the proof; an e-mail tells the account; existing connections keep working
T4130_U=$(t4130_account)
curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$T4130_U" -d co=token_novy -d nazev=T4130 -d access=drafts --data-urlencode "soucasne=$PASSWORD"
T4130_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
T4130_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude 4130\",\"redirect_uris\":[\"$T4130_URI\"],\"token_endpoint_auth_method\":\"none\"}"
T4130_CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
T4130_VERIFIER="$(printf 'q%.0s' $(seq 1 50))"; T4130_CHALLENGE=$(printf %s "$T4130_VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
t4130_request() { local to; to=$(curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$T4130_CLIENT&redirect_uri=$T4130_URI&code_challenge=$T4130_CHALLENGE&code_challenge_method=S256&state=$1")
  to=$(printf %s "$to" | grep -o 'request=[a-f0-9]*' | sed 's/request=//' || true); curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o "$WORK/response" "$B/admin.php?action=oauth&request=$to"; printf %s "$to"; }
t4130_consent() { local n="$1"; shift; curl -s -b "$T4130_JAR" -c "$T4130_JAR" -o "$WORK/consent" -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$T4130_C" -d "request=$n" -d povolit=1 "$@"; }
proof_age "$T4130_JAR"
T4130_N1=$(t4130_request drafts); T4130_C=$(csrf)
expect "3.46 N158: without a recent proof the consent asks for the password for drafts too, and drafts without it issue no code" \
  "$(grep -c 'id="oauth-heslo" name="soucasne"' "$WORK/response")|$(t4130_consent "$T4130_N1" -d access=drafts | cut -c1-3)|$(grep -c 'Connecting an application needs a recent confirmation' "$WORK/consent")|$(grep -c 'value="drafts" checked' "$WORK/consent")" \
  "1|403|1|1"
expect "3.46 N158: read only without the proof issues no code either" "$(t4130_consent "$T4130_N1" -d access=read | cut -c1-3)" "403"
T4130_MAILS=$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 't4130@example.com' AND predmet LIKE 'A new application was connected to your account%'")
expect "3.46 N158: with the password drafts are allowed, and the account gets one e-mail about the new application" \
  "$(t4130_consent "$T4130_N1" -d access=drafts --data-urlencode "soucasne=$PASSWORD" | grep -c "^302 $T4130_URI?code=.*state=drafts")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 't4130@example.com' AND predmet LIKE 'A new application was connected to your account%'")" \
  "1|$((T4130_MAILS + 1))"
T4130_N2=$(t4130_request again)
expect "3.46 N158: right after the password the next consent of the same application asks nothing and sends no second e-mail" \
  "$(grep -c 'id="oauth-heslo"' "$WORK/response")|$(t4130_consent "$T4130_N2" -d access=drafts | grep -c '^302 .*code=')|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 't4130@example.com' AND predmet LIKE 'A new application was connected to your account%'")" \
  "0|1|$((T4130_MAILS + 1))"
# an existing connection keeps working with the proof long gone: the personal token and the OAuth refresh
proof_age "$T4130_JAR"; proof_age "$JAR"
expect "3.46: an existing token keeps working after the proof is gone – nothing to confirm over MCP" \
  "$(mcp_call "$T4130_TOKEN" site_info '{}' | grep -c '"error"')|$(mcp_call "$T4130_TOKEN" site_info '{}' | grep -c '"result"')" "0|1"
T4130_N3=$(t4130_request refresh); T4130_C=$(csrf)
T4130_REDIRECT=$(t4130_consent "$T4130_N3" -d access=drafts --data-urlencode "soucasne=$PASSWORD")
T4130_CODE=$(printf %s "$T4130_REDIRECT" | grep -o 'code=[^&]*' | sed 's/code=//' || true)
curl -s -o "$WORK/t4130-token" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$T4130_CODE" -d "redirect_uri=$T4130_URI" -d "client_id=$T4130_CLIENT" -d "code_verifier=$T4130_VERIFIER"
T4130_REFRESH=$(grep -o '"refresh_token":"[^"]*"' "$WORK/t4130-token" | sed 's/.*:"//;s/"//' || true)
proof_age "$T4130_JAR"
expect "3.46: an existing OAuth connection refreshes its token without anyone signed in or confirming" \
  "$(curl -s -o "$WORK/t4130-refresh" -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$T4130_REFRESH" -d "client_id=$T4130_CLIENT")|$(grep -c '"access_token"' "$WORK/t4130-refresh")" "200|1"

sq "DELETE FROM ka_api_tokeny WHERE idu = (SELECT idu FROM ka_uzivatele WHERE user = 't4130'); DELETE FROM ka_uzivatele WHERE user IN ('t4130', 't4130b'); UPDATE ka_uzivatele SET pocet_chyb = 0 WHERE user = 'admin'" > /dev/null
proof_fresh "$JAR"
