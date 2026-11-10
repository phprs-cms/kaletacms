echo "== 3.18: whether Claude is connected – the header, Ask Claude, requests, Claude settings, health, the fleet heartbeat (Core\\ClaudeConnection)"
# cc318: the state as the fleet heartbeat carries it and the console's claude_* reasons for attention: state|pickup|call|reasons
cc318() { php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
  $beat = Kaleta\Fleet\Heartbeat::build($app); $c = $beat["claude_connection"] ?? [];
  echo $c["state"] ?? "-", "|", $c["pickup"] ?? "-", "|", ($c["last_call"] ?? null) !== null ? "call" : "nocall", "|",
    implode(",", array_filter(Kaleta\Fleet\Console::attention(["up" => 1, "last_seen" => date("Y-m-d H:i:s"), "status" => "ok", "heartbeat" => json_encode($beat)], time())["reasons"], fn ($r) => str_starts_with($r, "claude_")));' "$WORK/web"; }
chip318() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"; grep -o 'class="claude-stav claude-stav-[a-z]*"[^>]*><span class="claude-stav-tecka" aria-hidden="true"></span><span class="claude-stav-text">[^<]*' "$WORK/response" | sed 's/^class="claude-stav claude-stav-\([a-z]*\)".*claude-stav-text">/\1|/' || true; }
# every connection is put aside for the section (back at its end): the site has none; one new request waits for three days
sq "DROP TABLE IF EXISTS ka_tokeny_318, ka_rotated_318; CREATE TABLE ka_tokeny_318 AS SELECT * FROM ka_api_tokeny; CREATE TABLE ka_rotated_318 AS SELECT * FROM ka_oauth_rotated; DELETE FROM ka_api_tokeny; DELETE FROM ka_oauth_rotated;
  INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, status) SELECT '$(site_time '-3 days')', '$(site_time '-3 days')', idu, 'Čeká 318', 'Starý požadavek.', 'new' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
expect "3.18: no connection – the header says Claude is not connected" "$(chip318)" "none|Claude nepřipojený"
grep -q 'Claude není k tomuto webu připojený. Požadavek uložený teď počká, dokud Clauda někdo nepřipojí.' "$WORK/response" && grep -q '<button class="navigace" type="submit">Uložit na později</button>' "$WORK/response" \
  && ! grep -q '>Poslat Claudovi<' "$WORK/response" && grep -q 'id="pripojit-claude"' "$WORK/response" \
  && echo "  ok     3.18: Ask Claude warns, saves for later (no Send to Claude), the Connect Claude card leads" || { echo "  CHYBA  3.18: Ask Claude without a connection"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=requests&action=save" -d "_csrf=$(csrf)" -d quick=1 -d from=dashboard -d "text=Zkouška 318 bez připojení."
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
grep -q 'class="hlaska hlaska-varovani" role="status">Uloženo jako požadavek č. [0-9]* – počká, dokud někdo Clauda k webu nepřipojí.' "$WORK/response" \
  && echo "  ok     3.18: saving a request flashes that it waits" || { echo "  CHYBA  3.18: flash without a connection"; grep -o 'hlaska-[a-z]*" role="status">[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
check "3.18: the Requests list has the status line" 200 "/admin.php?module=requests" 'class="hlaska hlaska-varovani claude-upozorneni requests-stav"'
tr -d '\n\t' < "$WORK/response" | grep -q '<tr class="ceka-dlouho"><td><a href="[^"]*"><strong>Čeká 318</strong></a> <span class="stitek stitek-chyba">čeká déle než 48 hodin</span>' \
  && echo "  ok     3.18: a new request waiting over 48 hours is highlighted while Claude is not connected" || { echo "  CHYBA  3.18: the overdue request is not highlighted"; ERRORS=$((ERRORS+1)); }
check "3.18: a new request saves for later" 200 "/admin.php?module=requests&action=new" '<button class="navigace" type="submit">Uložit na později</button>'
check "3.18: Claude settings – the status panel says not connected and what to do" 200 "/admin.php?module=claude_settings" 'Připojte Clauda: v aplikaci Claude přidejte adresu níže'
check "3.18: System status warns that requests wait while Claude is not connected" 200 "/admin.php?module=status" 'Požadavky čekají ([0-9]*), ale Claude není k webu připojený'
expect "3.18: the heartbeat says none and the console asks for attention" "$(cc318)" "none|-|nocall|claude_not_connected"
# an OAuth connection (as the Claude app makes it): the access token for an hour, the refresh token for a year
CLIENT318="318a$(printf '0%.0s' $(seq 1 28))"; ACC318="kaleta_oa_$(printf 'c%.0s' $(seq 1 48))"; REF318="kaleta_or_$(printf 'd%.0s' $(seq 1 48))"
sq "INSERT INTO ka_oauth_klienti (client_id, nazev, presmerovani, vytvoren, approved) VALUES ('$CLIENT318', 'Claude', '[\"https://claude.ai/api/mcp/auth_callback\"]', '$(site_time)', '$(site_time)');
  INSERT INTO ka_api_tokeny (idu, nazev, klient, druh, access, expirace, otisk, vytvoren) SELECT idu, 'Claude', '$CLIENT318', 'pristup', 'full', '$(site_time '+1 hour')', SHA2('$ACC318', 256), '$(site_time '-100 days')' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_api_tokeny (idu, nazev, klient, druh, access, expirace, otisk, vytvoren) SELECT idu, 'Claude', '$CLIENT318', 'obnova', 'full', '$(site_time '+300 days')', SHA2('$REF318', 256), '$(site_time '-100 days')' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
expect "3.18: a connection nobody has called with yet is idle" "$(chip318)" "idle|Claude nečinný"
# 3.18 audit N318-1: the site requires two-factor sign-in of administrators and the connected administrator has none – every
# call is refused, so none counts: nothing is stamped, Claude is not connected, staff are not told a request was sent
TOTP318=$(sq "SELECT totp_tajemstvi FROM ka_uzivatele WHERE user = 'admin'"); FIRST318=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_first_used'")
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '' WHERE user = 'admin'" > /dev/null; setting require_2fa spravci; setting claude_first_used ''
code=$(curl -s -o "$WORK/response" -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $ACC318" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
expect "3.18 N318-1: a call refused for the missing two-factor sign-in stamps neither row nor the first call" \
  "$code|$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT318' AND pouzit IS NOT NULL")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_first_used'")" "403|0|"
expect "3.18 N318-1: the refused connection is no connection – the heartbeat says none" "$(cc318)" "none|-|nocall|claude_not_connected"
code=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=requests&action=new")
expect "3.18 N318-1: staff see Claude not connected and Save for later, not Send to Claude" \
  "$code|$(grep -c 'title="Claude nepřipojený"' "$WORK/response")|$(grep -c '<button class="navigace" type="submit">Uložit na později</button>' "$WORK/response")|$(grep -c 'Poslat Claudovi' "$WORK/response")" "200|1|1|0"
sq "UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP' WHERE user = 'admin'" > /dev/null
expect "3.18 N318-1: with two-factor sign-in on, the same connection counts again" "$(cc318 | cut -d'|' -f1)" "idle"
sq "UPDATE ka_uzivatele SET totp_tajemstvi = '$TOTP318' WHERE user = 'admin'" > /dev/null; setting require_2fa ''; setting claude_first_used "$FIRST318"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACC318" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > /dev/null
expect "3.18: a call stamps the access and the refresh row" "$(sq "SELECT CONCAT(druh, ':', pouzit IS NOT NULL) FROM ka_api_tokeny WHERE klient = '$CLIENT318' ORDER BY druh")" "$(printf 'obnova:1\npristup:1')"
expect "3.18: after the call the header says connected" "$(chip318)" "active|Claude připojený"
grep -q '<span class="claude-stav-kdy"> – poslední volání ' "$WORK/response" && grep -q '<button class="tl" type="submit">Poslat Claudovi</button>' "$WORK/response" && grep -q 'Claude si požadavky vezme, až se ho někdo zeptá v aplikaci Claude (poslední volání ' "$WORK/response" \
  && echo "  ok     3.18: the last call is shown, Ask Claude sends again and says requests are read when someone asks Claude" || { echo "  CHYBA  3.18: connected dashboard"; ERRORS=$((ERRORS+1)); }
# the stamp is written at most once a minute
sq "UPDATE ka_api_tokeny SET pouzit = '$(site_time '-30 seconds')' WHERE klient = '$CLIENT318' AND druh = 'obnova'" > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACC318" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > /dev/null
R318=$(sq "SELECT pouzit > '$(site_time '-20 seconds')' FROM ka_api_tokeny WHERE klient = '$CLIENT318' AND druh = 'obnova'")
sq "UPDATE ka_api_tokeny SET pouzit = '$(site_time '-2 minutes')' WHERE klient = '$CLIENT318' AND druh = 'obnova'" > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACC318" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > /dev/null
expect "3.18: the refresh row is stamped at most once a minute" "$R318|$(sq "SELECT pouzit > '$(site_time '-20 seconds')' FROM ka_api_tokeny WHERE klient = '$CLIENT318' AND druh = 'obnova'")" "0|1"
# the regression of 3.17: an hour later the access row has expired and is cleaned up – the refresh keeps the last call
CALL318="$(site_time '-2 hours')"
sq "UPDATE ka_api_tokeny SET pouzit = '$CALL318' WHERE klient = '$CLIENT318'; DELETE FROM ka_api_tokeny WHERE klient = '$CLIENT318' AND druh = 'pristup'" > /dev/null
curl -s -o "$WORK/token318.json" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REF318" -d "client_id=$CLIENT318"
ACC318=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/token318.json" | sed 's/.*:"//;s/"//' || true)
expect "3.18: an OAuth refresh carries the last call over to the new access and refresh token" "$([ -n "$ACC318" ] && echo new)|$(sq "SELECT GROUP_CONCAT(CONCAT(druh, ':', pouzit = '$CALL318') ORDER BY druh) FROM ka_api_tokeny WHERE klient = '$CLIENT318'")" "new|obnova:1,pristup:1"
expect "3.18: after the refresh the header still says connected" "$(chip318)" "active|Claude připojený"
expect "3.18: the hygiene check does not count a live OAuth connection as unused (created 100 days ago, called 2 hours ago)" \
  "$(php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo count(array_filter(Kaleta\Core\SecurityHygiene::findings($app)["unused_connections"], fn ($c) => $c["id"] === $argv[2]));' "$WORK/web" "$CLIENT318")" "0"
# 3.18 audit N318-2: refreshes of one connection are limited – a refresh every hour for a day is fine, a loop gets
# 429 slow_down after 30 within an hour, its refresh token stays valid and works once the hour has moved on
REF318=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/token318.json" | sed 's/.*:"//;s/"//' || true)
sq "INSERT INTO ka_oauth_rotated (hash, client_id, idu, salt, rotated_at, expires_at) SELECT SHA2(CONCAT('hourly318-', h.n), 256), '$CLIENT318', u.idu, REPEAT('0', 64), '$(site_time)' - INTERVAL h.n HOUR, '$(site_time '+20 days')'
  FROM ka_uzivatele u, (SELECT 2 AS n UNION SELECT 3 UNION SELECT 5 UNION SELECT 8 UNION SELECT 12 UNION SELECT 16 UNION SELECT 20 UNION SELECT 24) h WHERE u.user = 'admin'" > /dev/null
N318=0; LIMIT318=""
for i318 in $(seq 1 40); do
  code=$(curl -s -o "$WORK/token318.json" -D "$WORK/headers318" -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REF318" -d "client_id=$CLIENT318")
  if [ "$code" != 200 ]; then LIMIT318="$code|$(grep -o '"error":"[a-z_]*"' "$WORK/token318.json")|$(grep -ci '^retry-after: [0-9]' "$WORK/headers318")"; break; fi
  N318=$((N318+1)); REF318=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/token318.json" | sed 's/.*:"//;s/"//' || true)
done
expect "3.18 N318-2: after a day of hourly refreshes a loop gets 29 more (30 within the hour), then 429 slow_down with Retry-After" "$N318|$LIMIT318" '29|429|"error":"slow_down"|1'
expect "3.18 N318-2: the limited refresh token is not rotated – it stays valid" "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE otisk = SHA2('$REF318', 256) AND druh = 'obnova'")" "1"
sq "UPDATE ka_oauth_rotated SET rotated_at = rotated_at - INTERVAL 1 HOUR WHERE client_id = '$CLIENT318'" > /dev/null
curl -s -o "$WORK/token318.json" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REF318" -d "client_id=$CLIENT318"
ACC318=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/token318.json" | sed 's/.*:"//;s/"//' || true)
expect "3.18 N318-2: once the hour has moved on the same refresh token works again" "$([ -n "$ACC318" ] && echo new)" "new"
# no call for 8 days: idle (the refresh above counts as a call too – Claude refreshes only to call the site)
sq "UPDATE ka_api_tokeny SET pouzit = '$(site_time '-8 days')' WHERE klient = '$CLIENT318'; UPDATE ka_oauth_rotated SET rotated_at = '$(site_time '-8 days')' WHERE client_id = '$CLIENT318'" > /dev/null
expect "3.18: no call for 8 days – idle" "$(chip318)" "idle|Claude nečinný"
check "3.18: Claude settings – connected but idle, requests only when someone asks" 200 "/admin.php?module=claude_settings" 'Claude je připojený, ale nečinný'
grep -q 'class="hlaska claude-panel hlaska-varovani"' "$WORK/response" && grep -q 'vyřídí je Claude, až se ho někdo zeptá v aplikaci Claude' "$WORK/response" && grep -q 'Claude web nevolal už 7 dní' "$WORK/response" \
  && echo "  ok     3.18: the panel shows the pickup and what to do" || { echo "  CHYBA  3.18: idle panel"; ERRORS=$((ERRORS+1)); }
# a scheduled run of the task "requests": picked up automatically
sq "UPDATE ka_api_tokeny SET pouzit = '$(site_time '-5 minutes')' WHERE klient = '$CLIENT318';
  INSERT INTO ka_agent_schedules (name, task, text, cadence, day, time, active, next_due, created_at) VALUES ('Requests 318', 'requests', '', 'daily', 1, '07:00', 1, '$(site_time '+1 day')', '$(site_time)')" > /dev/null
SCHED318=$(sq "SELECT id FROM ka_agent_schedules WHERE name = 'Requests 318'")
mcp_as "$ACC318" get_health '{}' > "$WORK/response"
expect "3.18: get_health – claude_connection active, picked up automatically" "$(mcp_value claude_connection state)|$(mcp_value claude_connection pickup)|$([ -n "$(mcp_value claude_connection last_call)" ] && echo call)" "active|automatic|call"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
grep -q 'class="smltxt ask-claude-kdy">Plánovaný běh vyřizuje požadavky ' "$WORK/response" && ! grep -q 'claude-upozorneni' "$WORK/response" \
  && echo "  ok     3.18: with the scheduled run Ask Claude says when requests are picked up, no warning" || { echo "  CHYBA  3.18: automatic pickup on the dashboard"; ERRORS=$((ERRORS+1)); }
expect "3.18: the heartbeat – active, automatic, the last call, no attention" "$(cc318)" "active|automatic|call|"
# the scheduled run missed: stalled – requests wait
sq "INSERT INTO ka_agent_runs (schedule_id, due_at, finished_at, status) VALUES ($SCHED318, '$(site_time '-2 hours')', '$(site_time '-1 hour')', 'missed')" > /dev/null
expect "3.18: the run missed – the header says requests wait" "$(chip318)" "stalled|Claude: požadavky čekají"
grep -q 'Naplánovaný běh, který vyřizuje požadavky, neběží (naposledy zmeškaný nebo neúspěšný ' "$WORK/response" && echo "  ok     3.18: Ask Claude warns that the scheduled run is not running" || { echo "  CHYBA  3.18: stalled warning"; ERRORS=$((ERRORS+1)); }
check "3.18: System status warns that requests wait for a scheduled run that does not run" 200 "/admin.php?module=status" 'ale naplánovaný běh, který je vyřizuje, neběží'
expect "3.18: the heartbeat – stalled, the console asks for attention" "$(cc318)" "active|stalled|call|claude_run_stalled"
mcp_as "$ACC318" get_health '{}' > "$WORK/response"
expect "3.18: get_health – pickup stalled" "$(mcp_value claude_connection pickup)" "stalled"
# every connection revoked: none again – 3.18 audit N318-3: the connection refreshed 5 minutes ago is disconnected in My
# account, its rotation records go with it and nothing says "last call 5 minutes ago"
sq "UPDATE ka_oauth_rotated SET rotated_at = '$(site_time '-5 minutes')' WHERE client_id = '$CLIENT318'; UPDATE ka_agent_schedules SET active = 0 WHERE id = $SCHED318" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$(csrf)" -d "odpojit_klient=$CLIENT318"
expect "3.18 N318-3: disconnecting the application deletes its tokens and its rotation records" "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT318'")|$(sq "SELECT COUNT(*) FROM ka_oauth_rotated WHERE client_id = '$CLIENT318'")" "0|0"
# a record left behind (a revocation before 3.18, a blocked account) does not count once its connection has no live token:
# with a personal token never called with the site is idle, without a last call
sq "INSERT INTO ka_oauth_rotated (hash, client_id, idu, salt, rotated_at, expires_at) SELECT SHA2('left318', 256), '$CLIENT318', idu, REPEAT('0', 64), '$(site_time '-5 minutes')', '$(site_time '+20 days')' FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_api_tokeny (idu, nazev, druh, access, expirace, otisk, vytvoren) SELECT idu, 'Token 318', 'token', 'drafts', NULL, SHA2('token318', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
expect "3.18 N318-3: a revoked connection's record is not a last call" "$(chip318)|$(grep -c 'claude-stav-kdy"> – zatím žádné volání' "$WORK/response")|$(grep -c 'poslední volání' "$WORK/response")|$(cc318)" "idle|Claude nečinný|1|0|idle|manual|nocall|"
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE user = 'recepce'; INSERT INTO ka_api_tokeny (idu, nazev, druh, access, expirace, otisk, vytvoren, pouzit) SELECT idu, 'Blocked 318', 'token', 'drafts', NULL, SHA2('blocked318', 256), '$(site_time)', '$(site_time '-1 minute')' FROM ka_uzivatele WHERE user = 'recepce'" > /dev/null
expect "3.18 N318-3: a blocked account's token is neither a connection nor a last call" "$(cc318)" "idle|manual|nocall|"
sq "DELETE FROM ka_api_tokeny WHERE otisk IN (SHA2('token318', 256), SHA2('blocked318', 256)); DELETE FROM ka_oauth_rotated WHERE hash = SHA2('left318', 256); UPDATE ka_uzivatele SET blokovat = 0 WHERE user = 'recepce'" > /dev/null
expect "3.18: every connection revoked – not connected again" "$(chip318)" "none|Claude nepřipojený"
grep -q 'id="pripojit-claude"' "$WORK/response" && echo "  ok     3.18: the Connect Claude card is back" || { echo "  CHYBA  3.18: no card after revoking"; ERRORS=$((ERRORS+1)); }
# a user with only Requests sees the state too and is sent to an administrator
code=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=requests&action=new")
expect "3.18: a staff user sees the chip (it leads to Requests) and is asked to turn to an administrator" "$code|$(grep -c 'href="/admin.php?module=requests" title="Claude nepřipojený"' "$WORK/response")|$(grep -c 'Požádejte správce, aby Clauda připojil.' "$WORK/response")" "200|1|1"
sq "DELETE FROM ka_agent_schedules WHERE id = $SCHED318; DELETE FROM ka_oauth_klienti WHERE client_id = '$CLIENT318'; DELETE FROM ka_requests WHERE title IN ('Čeká 318', 'Zkouška 318 bez připojení.');
  DELETE FROM ka_oauth_rotated; INSERT INTO ka_api_tokeny SELECT * FROM ka_tokeny_318; INSERT INTO ka_oauth_rotated SELECT * FROM ka_rotated_318; DROP TABLE ka_tokeny_318, ka_rotated_318" > /dev/null
