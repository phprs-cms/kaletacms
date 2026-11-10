echo "== 2.9: fleet console (a second install is the console, this site pairs with it)"
PORT3=$((PORT + 13)); B3="http://127.0.0.1:$PORT3"; DB3="${DB_NAME}_konzole"; JAR_CON="$WORK/cookies-konzole.txt"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB3\`; CREATE DATABASE \`$DB3\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web3" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web3")
mkdir -p "$WORK/web3/media" "$WORK/web3/storage/log" "$WORK/web3/storage/cache"
(cd "$WORK/web3" && KALETA_FLEET_LOCAL=1 exec php -S "127.0.0.1:$PORT3" system/dev-router.php > "$WORK/server3.log" 2>&1) & SERVER3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B3/install.php" && break; sleep 0.2; done
curl -s -o "$WORK/response" -X POST "$B3/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB3" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Konzole agentury" -d web=firemni -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=fleet' -d 'rozsireni[]=claude'
sq3() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB3" -N -e "$1"; }
# the console's own update channel is not reachable here: it "knows" a newer version 3.99.9 from its cache
sq3 "REPLACE INTO ka_nastaveni VALUES ('update_url', 'http://127.0.0.1:1/aktualizace.json'), ('update_cache', '{\"url\":\"http://127.0.0.1:1/aktualizace.json\",\"overeno\":$(date +%s),\"manifest\":{\"verze\":\"3.99.9\",\"min_php\":\"8.3\",\"zmeny\":[]},\"chyba\":null}')" > /dev/null
curl -s -c "$JAR_CON" -b "$JAR_CON" -o "$WORK/response" "$B3/admin.php"; curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=pairing_key" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
PAIRING_KEY=$(grep -o 'kaleta-console:[A-Za-z0-9_-]*' "$WORK/response" | head -1)
[ -n "$PAIRING_KEY" ] && echo "  ok     console: a one-time pairing key" || { echo "  CHYBA  konzole nedala párovací klíč"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
! grep -q 'kaleta-console:' "$WORK/response" && echo "  ok     console: the pairing key is shown only once" || { echo "  CHYBA  párovací klíč se ukázal znovu"; ERRORS=$((ERRORS+1)); }
expect "a site without the fleet extension has no console addresses" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/fleet/heartbeat" -d '{}')" "404"
# this site pairs with the console from Settings → Reporting to a console (Fleet console until 3.23)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; TOKEN=$(csrf)
grep -q 'name="pairing_key"' "$WORK/response" && echo "  ok     Settings → Reporting to a console offers pairing" || { echo "  CHYBA  záložka Konzole webů"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_pair" -d "_csrf=$TOKEN" --data-urlencode "pairing_key=$PAIRING_KEY" -d fleet_updates=1
expect "pairing: the site knows its console and its number there" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'), '|', (SELECT hodnota > 0 FROM ka_nastaveni WHERE promenna = 'fleet_site_id'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_updates'))")" "$B3|1|1"
# 3.23 (C8): a paired site always shows its console tab, also with no agency named
AGENCY323=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'agency_name'")
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'agency_name'" > /dev/null
check "3.23: a paired site shows the console tab in the row without an agency named" 200 "/admin.php?module=settings&tab=general" '<a href="/admin.php?module=settings&amp;tab=console">Hlášení do konzole</a>'
sq "UPDATE ka_nastaveni SET hodnota = '$AGENCY323' WHERE promenna = 'agency_name'" > /dev/null
expect "pairing: the console has the site with its first report" "$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(last_seen IS NOT NULL), '|', MAX(version <> ''), '|', MAX(manage_updates), '|', MAX(heartbeat LIKE '%enquiries_unanswered%')) FROM ka_fleet_sites")" "1|1|1|1|1"
expect "pairing: the code works only once" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_pairing WHERE used_at IS NOT NULL")" "1"
sq3 "SELECT heartbeat FROM ka_fleet_sites" | contains '@' && { echo "  CHYBA  the report carries an e-mail address"; ERRORS=$((ERRORS+1)); } || echo "  ok     the report carries no e-mail addresses"
# 3.16: what waits for people – request titles (switchable on the site, on by default, e-mail addresses masked), security counts without addresses
sq "INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, about, status) SELECT '$(site_time '-3 days')', '$(site_time '-3 days')', idu, 'Fleet316 ceník – napište na jana.fleet@example.cz', 'Tajný text požadavku 316', '', 'new' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, about, status) SELECT '$(site_time '-1 hour')', '$(site_time '-1 hour')', idu, 'Fleet316 nová fotka týmu', 'Text 316', '', 'in_progress' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, about, status) SELECT '$(site_time '-2 hours')', '$(site_time '-2 hours')', idu, 'Fleet316 zavolat +420 777 316 316, novak (at) firma316 [dot] cz', 'Text 316', '', 'new' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_events (created_at, type, severity, message, data) VALUES ('$(site_time '-1 hour')', 'whistleblowing.due', 'warning', 'Case 316: the acknowledgement is due.', '{}');
  INSERT INTO ka_firewall_log (created_at, ip, reason, path) VALUES ('$(site_time '-1 hour')', '203.0.113.77', 'probe', '/wp-login.php');
  INSERT INTO ka_kontrola_ip (ip_adresa, typ, cil, cas) VALUES ('203.0.113.78', 'login', 0, '$(site_time '-1 hour')')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
grep -q 'name="fleet_request_titles" value="1" checked' "$WORK/response" && echo "  ok     Settings → Fleet console: request titles are shared by default, with the switch" || { echo "  CHYBA  the request titles switch is missing or off"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
FLEET_BEAT=$(sq3 "SELECT heartbeat FROM ka_fleet_sites")
contains -qF 'Fleet316 nová fotka týmu' <<< "$FLEET_BEAT" && contains -qF 'Fleet316 ceník – napište na [e-mail]' <<< "$FLEET_BEAT" && echo "  ok     the report carries the request titles, the e-mail address in a title masked" || { echo "  CHYBA  request titles in the report"; ERRORS=$((ERRORS+1)); }
contains -q '@' <<< "$FLEET_BEAT" && { echo "  CHYBA  the report with request titles carries an e-mail address"; ERRORS=$((ERRORS+1)); } || echo "  ok     the report with request titles carries no e-mail address"
contains -qF 'Fleet316 zavolat [phone], [e-mail]' <<< "$FLEET_BEAT" && ! contains -qE '316 316|firma316' <<< "$FLEET_BEAT" && echo "  ok     3.16 N316-2: a phone number and an address written out in a title are masked" || { echo "  CHYBA  N316-2 masking in request titles"; ERRORS=$((ERRORS+1)); }
contains -qi 'whistleblow' <<< "$FLEET_BEAT" && { echo "  CHYBA  N316-3: the report carries a whistleblowing event"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.16 N316-3: no whistleblowing event or job in the report (problems_7_days)"
contains -qE '203\.0\.113\.7|Tajný text|wp-login' <<< "$FLEET_BEAT" && { echo "  CHYBA  the report carries an address, a path or a request text"; ERRORS=$((ERRORS+1)); } || echo "  ok     the report carries no visitor address, path or request text"
expect "the report counts the requests, the oldest waiting, the failed sign-ins and the firewall by reason" "$(sq3 "SELECT CONCAT(JSON_EXTRACT(heartbeat, '\$.requests.new') >= 1, '|', JSON_EXTRACT(heartbeat, '\$.requests.in_progress') >= 1, '|', JSON_EXTRACT(heartbeat, '\$.requests.oldest_waiting_hours') >= 71, '|', JSON_EXTRACT(heartbeat, '\$.security_7_days.failed_sign_ins') >= 1, '|', JSON_EXTRACT(heartbeat, '\$.security_7_days.firewall.probe') >= 1, '|', JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.pending_review')), '|', JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.enquiries_new_not_spam'))) FROM ka_fleet_sites")" "1|1|1|1|1|OBJECT|INTEGER"
FLEET_REQ=$(sq "SELECT id FROM ka_requests WHERE title LIKE 'Fleet316 nová%'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$(sq3 "SELECT id FROM ka_fleet_sites LIMIT 1")"
grep -qF "$B/admin.php?module=requests&amp;action=detail&amp;id=$FLEET_REQ" "$WORK/response" && grep -qF 'Fleet316 nová fotka týmu' "$WORK/response" && grep -q 'requests wait over 48 hours\|požadavky čekají přes 48 hodin' "$WORK/response" \
  && echo "  ok     console: the detail lists what waits – request titles as plain links to the site's administration, the reason for attention" || { echo "  CHYBA  console: the Waiting block of the site detail"; ERRORS=$((ERRORS+1)); }
grep -q 'probing for other systems: [0-9]\|zkoušení jiných systémů: [0-9]' "$WORK/response" && ! grep -q 'probe [0-9]' "$WORK/response" && echo "  ok     3.16 N316-8: the console detail names the firewall reasons in its language" || { echo "  CHYBA  N316-8 firewall reasons on the console detail"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_titles" -d "_csrf=$(csrf)"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
FLEET_BEAT=$(sq3 "SELECT heartbeat FROM ka_fleet_sites")
expect "request titles off: the report has the counts but no titles" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_request_titles'")|$(contains -q 'Fleet316' <<< "$FLEET_BEAT" && echo titles || echo none)|$(sq3 "SELECT CONCAT(JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.requests.items')), '|', JSON_EXTRACT(heartbeat, '\$.requests.new') >= 1) FROM ka_fleet_sites")" "0|none|NULL|1"
contains -q '@' <<< "$FLEET_BEAT" && { echo "  CHYBA  the report carries an e-mail address"; ERRORS=$((ERRORS+1)); } || echo "  ok     the report without titles carries no e-mail address either"
sq "UPDATE ka_nastaveni SET hodnota = '1' WHERE promenna = 'fleet_request_titles'; DELETE FROM ka_requests WHERE title LIKE 'Fleet316%'; DELETE FROM ka_firewall_log WHERE ip = '203.0.113.77'; DELETE FROM ka_kontrola_ip WHERE ip_adresa = '203.0.113.78';
  DELETE FROM ka_events WHERE type = 'whistleblowing.due' AND message = 'Case 316: the acknowledgement is due.'" > /dev/null
FLEET_NAME=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=sites&show=all"
grep -qF "$FLEET_NAME" "$WORK/response" && echo "  ok     console: the site is in the list" || { echo "  CHYBA  konzole: web není v seznamu"; ERRORS=$((ERRORS+1)); }
FLEET_ID=$(sq3 "SELECT id FROM ka_fleet_sites LIMIT 1")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
grep -q 'name="ring"' "$WORK/response" && echo "  ok     console: the detail of a site with its update ring" || { echo "  CHYBA  konzole: detail webu"; ERRORS=$((ERRORS+1)); }
# forged and repeated reports are refused
expect "console: a report with a wrong signature is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -H 'X-Kaleta-Signature: AAAA' -d "{\"site_id\":$FLEET_ID,\"ts\":$(date +%s)}")" "403"
expect "console: a report of an unknown site is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -d '{"site_id":99999}')" "404"
expect "console: pairing with an unknown code is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/pair" -d '{"action":"pair","code":"00000000000000000000000000000000","public_key":"'"$(sq3 "SELECT public_key FROM ka_fleet_sites LIMIT 1")"'","url":"http://x.test","ts":0}')" "403"
# the heartbeat job reports on its own
LAST_TS=$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID"); sleep 1
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'heartbeat'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
[ "$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID")" -gt "$LAST_TS" ] && echo "  ok     the background job sends the report" || { echo "  CHYBA  úloha heartbeat nic neposlala"; ERRORS=$((ERRORS+1)); }
# staged updates: a normal site waits, a test site (canary) gets the new version at once
expect "staged updates: a normal site waits for the test sites" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'")" ""
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=ring" -d "_csrf=$(csrf)" -d "id=$FLEET_ID" -d ring=canary
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "staged updates: a test site may install the new version" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'")" "3.99.9"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q '3.99.9' "$WORK/response" && echo "  ok     the site shows the allowed update" || { echo "  CHYBA  povolená aktualizace se neukazuje"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_updates" -d "_csrf=$(csrf)"
expect "the site takes the decision about updates back" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_updates'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'))")" "0|"
# uptime from the console, and a site that stops reporting
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
expect "uptime: the console sees the site up" "$(sq3 "SELECT up FROM ka_fleet_sites WHERE id = $FLEET_ID")" "1"
sq3 "UPDATE ka_fleet_sites SET url = 'http://127.0.0.1:1', last_seen = '$(site_time)' - INTERVAL 30 HOUR, silent_reported = 0 WHERE id = $FLEET_ID" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
expect "uptime: down twice in a row is an event, and so is a site that stopped reporting" "$(sq3 "SELECT CONCAT((SELECT up FROM ka_fleet_sites WHERE id = $FLEET_ID), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_down'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_silent'))")" "0|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=sites"
grep -q 'stitek-chyba' "$WORK/response" && echo "  ok     console: a down site is first in the list of what needs attention" || { echo "  CHYBA  konzole: nedostupný web se neukazuje"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&urgent=1"
grep -qF 'href="http://127.0.0.1:1/" target="_blank" rel="noopener"' "$WORK/response" && grep -q 'stitek-chyba' "$WORK/response" && echo "  ok     3.17 inbox: a down site is an urgent item with a plain link to it" || { echo "  CHYBA  3.17 inbox: the down site"; ERRORS=$((ERRORS+1)); }
sq3 "UPDATE ka_fleet_sites SET url = '$B' WHERE id = $FLEET_ID" > /dev/null
# Claude on the console reads the fleet (read-only tools, only with the extension)
CON_TOKEN="kaleta_$(printf 'c%.0s' $(seq 1 48))"
sq3 "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$CON_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_sites","arguments":{}}}' > "$WORK/response"
contains -q 'console_decides_updates' "$WORK/response" && contains -q 'newest_version' "$WORK/response" && echo "  ok     MCP list_sites on the console" || { echo "  CHYBA  MCP list_sites"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"get_site\",\"arguments\":{\"id\":$FLEET_ID}}}" > "$WORK/response"
contains -q 'jobs_failing' "$WORK/response" && contains -q 'written_on_the_site' "$WORK/response" && echo "  ok     MCP get_site: the last report, its texts marked as written on that site (N316-6)" || { echo "  CHYBA  MCP get_site"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_sites '{}' > "$WORK/response"; contains -q 'newest_version' "$WORK/response" && { echo "  CHYBA  list_sites works on a site that is not a console"; ERRORS=$((ERRORS+1)); } || echo "  ok     list_sites exists only on a console"
