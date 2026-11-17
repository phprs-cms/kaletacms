echo "== 3.43 A: locks – live actions under any spelling, news preview, second-step fields and log, guardrails per user, OAuth codes"
S343_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
s343_login() { local jar="$WORK/jar-$1"; rm -f "$jar"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; echo "$jar"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('a3800', '$S343_HASH', 'Author 3800', 'a3800@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.x FROM ka_uzivatele JOIN (SELECT 'news' AS x UNION SELECT 'tags') m WHERE user = 'a3800'" > /dev/null
S343_AUTHOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a3800'")

# N155: an author with the Tags section posts delete under other spellings – none runs, the tag stays
sq "INSERT INTO ka_stitky (nazev, seo_link, jazyk) VALUES ('Tag 3800', 'tag-3800', '')" > /dev/null
S343_TAG=$(sq "SELECT ids FROM ka_stitky WHERE seo_link = 'tag-3800'")
S343_JAR=$(s343_login a3800)
curl -s -b "$S343_JAR" -c "$S343_JAR" -o "$WORK/response" "$B/admin.php?module=tags"; S343_T=$(csrf)
s343_post() { curl -s -b "$S343_JAR" -c "$S343_JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=tags&action=$1" -d "_csrf=$S343_T" -d "ids=$S343_TAG" -d "nazev=Renamed 3800"; }
S343_CODES="$(s343_post delete_)/$(s343_post delete__)/$(s343_post de_lete)/$(s343_post save_)/$(s343_post DELETE)"
s343_post delete > /dev/null
expect "3.43 N155: delete_, delete__, de_lete, save_ and DELETE are unknown actions (404); delete itself is refused – the tag stays unrenamed" \
  "$S343_CODES|$(sq "SELECT nazev FROM ka_stitky WHERE ids = $S343_TAG")" "404/404/404/404/404|Tag 3800"
sq "DELETE FROM ka_stitky WHERE ids = $S343_TAG" > /dev/null

# N159: a draft news item of the administrator is not previewed by an author, nor by anyone without the news section
mcp create_news "{\"title\":\"Draft 3800 embargo\",\"category\":\"$(sq "SELECT nazev FROM ka_kategorie ORDER BY idt LIMIT 1")\",\"text\":\"<p>Embargo 3800</p>\"}" > "$WORK/response"
S343_SLUG=$(sq "SELECT seo_link FROM ka_novinky WHERE titulek = 'Draft 3800 embargo' ORDER BY idc DESC LIMIT 1")
S343_AUTHOR_SEES=$(curl -s -b "$S343_JAR" -o "$WORK/response" -w '%{http_code}' "$B/novinky/$S343_SLUG?preview=1")
S343_ADMIN_SEES=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/novinky/$S343_SLUG?preview=1"; grep -c 'Embargo 3800' "$WORK/response" || true)
expect "3.43 N159: the author gets 404 for a colleague's draft; the administrator previews it" "$S343_AUTHOR_SEES|$S343_ADMIN_SEES" "404|2001"
sq "UPDATE ka_novinky SET autor = $S343_AUTHOR WHERE seo_link = '$S343_SLUG'" > /dev/null
expect "3.43 N159: the author previews their own draft" "$(curl -s -b "$S343_JAR" -o /dev/null -w '%{http_code}' "$B/novinky/$S343_SLUG?preview=1")" "200"
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $S343_AUTHOR AND ident_modulu = 'news'" > /dev/null
S343_JAR=$(s343_login a3800)
expect "3.43 N159: without the news section even their own draft is not previewed" "$(curl -s -b "$S343_JAR" -o /dev/null -w '%{http_code}' "$B/novinky/$S343_SLUG?preview=1")" "404"
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($S343_AUTHOR, 'news'); DELETE FROM ka_novinky WHERE seo_link = '$S343_SLUG'" > /dev/null

# N126 + N160: a second step – the field takes a backup code typed with spaces; a wrong code is in the change log; the tenth
# wrong step in a row locks the account and records the warning event
S343_HOST=$(php -r 'echo strtolower(parse_url($argv[1], PHP_URL_HOST));' -- "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pk3800', '$S343_HASH', 'Tester 3800', 'pk3800@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0)" > /dev/null
S343_PK=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'pk3800'")
sq "INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno, discoverable, rp_id) VALUES ($S343_PK, 'Key 3800', SHA2('pk3800-key', 256), 'cGszODAw', 'x', -7, 0, '$(site_time)', 1, '$S343_HOST')" > /dev/null
S343_GEN=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; [$c, $s] = Kaleta\Core\BackupCodes::generate(); echo $c[0], "\n", $s;')
S343_CODE=$(printf '%s' "$S343_GEN" | head -1)
sq "UPDATE ka_uzivatele SET totp_zalozni = '$(printf '%s' "$S343_GEN" | tail -1)' WHERE idu = $S343_PK" > /dev/null
s343_wait() { rm -f "$1"; curl -s -b "$1" -c "$1" -o "$WORK/response" "$B/admin.php"; curl -s -b "$1" -c "$1" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=pk3800 --data-urlencode "password=$PASSWORD"; }
s343_code() { local t; t=$(csrf); curl -s -b "$1" -c "$1" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod --data-urlencode "kod=$2"; }
s343_wait "$WORK/jar-s343"
S343_MAX=$(grep -o 'name="kod"[^>]*maxlength="[0-9]*"' "$WORK/response" | grep -o 'maxlength="[0-9]*"' | head -1)
S343_NUMERIC=$(grep -c 'name="kod"[^>]*inputmode="numeric"' "$WORK/response" || true)
S343_WRONG=$(s343_code "$WORK/jar-s343" "0000-0000-0000-0000")
S343_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'prihlaseni' AND akce = 'neuspech' AND popis = 'second step (code), account: pk3800' AND popis NOT LIKE '%0000%'")
S343_SPACED=$(printf '%s' "$S343_CODE" | tr '-' ' ')
expect "3.43 N126: the field takes 40 characters and letters; N160: the wrong code is logged without what was typed; the code typed with spaces signs in" \
  "$S343_MAX|$S343_NUMERIC|$S343_WRONG|$S343_LOG|${#S343_SPACED}|$(s343_code "$WORK/jar-s343" "$S343_SPACED")" 'maxlength="40"|0|401|1|19|302'
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp'); UPDATE ka_uzivatele SET pocet_chyb = 9 WHERE idu = $S343_PK" > /dev/null
s343_wait "$WORK/jar-s343b"; s343_code "$WORK/jar-s343b" "1111-1111-1111-1111" > /dev/null
expect "3.43 N160: the tenth wrong step locks the account and records security.account_locked with the user id only" \
  "$(sq "SELECT zamceno_do > NOW() - INTERVAL 1 DAY FROM ka_uzivatele WHERE idu = $S343_PK")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'security.account_locked' AND JSON_EXTRACT(data, '\$.user') = $S343_PK AND data NOT LIKE '%1111%'")" "1|1"
sq "DELETE FROM ka_uzivatele_klice WHERE idu = $S343_PK; DELETE FROM ka_uzivatele WHERE idu = $S343_PK; DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp')" > /dev/null

# N167: two users' connections with the same name have their own hourly limit and their own undo session
S343_TA="kaleta_$(printf '43%.0s' $(seq 1 24))"; S343_TB="kaleta_$(printf '38%.0s' $(seq 1 24))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'Claude 3800', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$S343_TA")', '$(site_time)' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES ($S343_AUTHOR, 'Claude 3800', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$S343_TB")', '$(site_time)')" > /dev/null
# the limit is per user (review L1): the administrator's earlier Claude changes count, so one change is left for them
S343_ADMIN_USED=$(sq "SELECT COALESCE(SUM(CASE WHEN akce IN ('save_redirects','save_collection_items','importuj_web','import_wordpress') AND popis REGEXP '^[0-9]+ rows' THEN CAST(SUBSTRING_INDEX(popis, ' ', 1) AS UNSIGNED) ELSE 1 END), 0) FROM ka_protokol WHERE modul = 'claude' AND kdo = (SELECT idu FROM ka_uzivatele WHERE user = 'admin') AND cas > '$(site_time '-1 hour')'")
setting claude_change_limit $((S343_ADMIN_USED + 1))
S343_CAT=$(sq "SELECT nazev FROM ka_kategorie ORDER BY idt LIMIT 1")
S343_A1=$(mcp_call "$S343_TA" create_news "{\"title\":\"Limit A 3800\",\"category\":\"$S343_CAT\"}" | grep -c 'reached the limit' || true)
S343_B1=$(mcp_call "$S343_TB" create_news "{\"title\":\"Limit B 3800\",\"category\":\"$S343_CAT\"}" | grep -c 'reached the limit' || true)
S343_A2=$(mcp_call "$S343_TA" create_news "{\"title\":\"Limit A2 3800\",\"category\":\"$S343_CAT\"}" | grep -c 'reached the limit' || true)
expect "3.43 N167: the limit counts per user – the colleague's first change passes, the second change of the first user is refused" "$S343_A1|$S343_B1|$S343_A2" "0|0|1"
expect "3.43 N167: each user's connection has its own undo session" \
  "$(sq "SELECT COUNT(DISTINCT id) FROM ka_agent_sessions WHERE connection IN ('Claude 3800 · admin', 'Claude 3800 · a3800') AND undone_at IS NULL")" "2"
setting claude_change_limit 0
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'Claude 3800';
  DELETE FROM ka_novinky WHERE titulek IN ('Limit A 3800', 'Limit B 3800', 'Limit A2 3800'); DELETE FROM ka_agent_sessions WHERE connection LIKE 'Claude 3800%'" > /dev/null

# N158: revoking a user's connections also deletes the authorization codes not yet redeemed
sq "INSERT INTO ka_oauth_kody (otisk, client_id, idu, presmerovani, vyzva, expirace) VALUES (SHA2('code-3800', 256), LPAD('c3800', 32, 'x'), $S343_AUTHOR, 'http://127.0.0.1/cb', 'v', '$(site_time '+10 minutes')')" > /dev/null
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; Kaleta\Front\OAuth::revokeConnections((new Kaleta\Core\App(require "config.php"))->db(), (int) $argv[1]);' -- "$S343_AUTHOR")
expect "3.43 N158: revokeConnections leaves no authorization code of the user" "$(sq "SELECT COUNT(*) FROM ka_oauth_kody WHERE idu = $S343_AUTHOR")" "0"
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $S343_AUTHOR; DELETE FROM ka_uzivatele WHERE idu = $S343_AUTHOR" > /dev/null
