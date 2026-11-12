echo "== 2.8: background jobs, events, alerts"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "mail: sent" "$WORK/tasks.txt" && grep -q "cleanup: ok" "$WORK/tasks.txt" && echo "  ok     /ulohy runs the jobs of the scheduler" || { echo "  CHYBA  /ulohy jobs"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "every job that ran is recorded with its result" "$(sq "SELECT CONCAT(COUNT(*) > 5, ':', SUM(failures)) FROM ka_jobs")" "1:0"
[ "$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'enquiry.received'")" = 1 ] && [ "$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'build.published'")" = 1 ] \
  && ! sq "SELECT data FROM ka_events WHERE type = 'enquiry.received'" | contains '@' && echo "  ok     events: enquiries and publishing recorded, without the sender" || { echo "  CHYBA  events"; ERRORS=$((ERRORS+1)); }
check "System status lists the background jobs" 200 "/admin.php?module=status" "alerts_email"
# an error event goes out as one alert e-mail
sq "UPDATE ka_nastaveni SET hodnota = (SELECT COALESCE(MAX(id), 0) FROM ka_events) WHERE promenna = 'alerts_cursor'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'alerts_last_sent'" > /dev/null
sq "INSERT INTO ka_nastaveni (promenna, hodnota) SELECT 'alerts_cursor', (SELECT COALESCE(MAX(id), 0) FROM ka_events) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ka_nastaveni WHERE promenna = 'alerts_cursor')" > /dev/null
sq "INSERT INTO ka_events (created_at, type, severity, message) VALUES ('$(site_time)', 'backup.failed', 'error', 'Test: the automatic backup failed')" > /dev/null
# earlier steps leave real warnings; on a slow run the hourly job may already have mailed them – count only what follows
ALERTS_BEFORE="$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'")"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'alerts'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "alerts: one e-mail with the error, the next waits an hour" "$(( $(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'") - ALERTS_BEFORE ))" "1"
sq "INSERT INTO ka_events (created_at, type, severity, message) VALUES ('$(site_time)', 'mail.failed', 'error', 'Test: second')" > /dev/null; sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'alerts'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "alerts: at most one an hour" "$(( $(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'") - ALERTS_BEFORE ))" "1"
# the check after an update: only with the one-time code
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/ulohy?probe=abc"); expect "update check without the code is refused" "$code" "403"
sq "INSERT INTO ka_nastaveni VALUES ('update_probe','probe123') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
expect "update check with the code answers the running version" "$(curl -s "$B/ulohy?probe=probe123")" "KALETA-PROBE $(php -r 'require $argv[1]; echo KALETA_VERSION;' -- "$ROOT/system/bootstrap.php" 2>/dev/null)"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_probe'" > /dev/null
# Claude: get_health and list_events
mcp get_health '{}' > "$WORK/response"
contains -q 'kaleta_version' "$WORK/response" && contains -q 'alerts' "$WORK/response" && contains -q 'backup.failed' "$WORK/response" && echo "  ok     MCP get_health: status, jobs and the problems of the week" || { echo "  CHYBA  MCP get_health"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_events '{"types":["backup."],"min_severity":"error"}' > "$WORK/response"
contains -q 'Test: the automatic backup failed' "$WORK/response" && contains -q 'next_since_id' "$WORK/response" && ! contains -q 'type\\":\\"enquiry.received' "$WORK/response" && echo "  ok     MCP list_events: filtered by type and severity, with a cursor" || { echo "  CHYBA  MCP list_events"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }

# firewall (2.8): the test server runs with KALETA_FIREWALL_LOCAL=1, so 127.0.0.1 counts as a visitor's address
sq "INSERT INTO ka_nastaveni VALUES ('firewall_enabled','1'),('firewall_ips','127.0.0.1 # test'),('firewall_probes','1'),('firewall_rate','0') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
expect "firewall: a listed address is refused on the public site" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "403"
# 3.15 (N46): a refused request for the whistleblowing channel is logged without the address
expect "3.15 N46: the firewall log keeps no address for a refused report page" "$(curl -s -o /dev/null -w '%{http_code}' "$B/_report")|$(sq "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(ip), '-')) FROM ka_firewall_log WHERE path = '/_report'")|$(sq "SELECT COUNT(*) > 0 FROM ka_firewall_log WHERE path = '/' AND ip = '127.0.0.1'")" "403|1:|1"
expect "firewall: the administration stays open" "$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=settings&tab=firewall")" "200"
grep -q 'name="firewall_ips"' "$WORK/response" && grep -q '127.0.0.1' "$WORK/response" && echo "  ok     firewall: the tab shows the settings and the refused request" || { echo "  CHYBA  firewall: záložka"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'firewall_ips'" > /dev/null
for i in 1 2 3 4; do curl -s -o /dev/null "$B/wp-login.php"; done
expect "firewall: probing for other systems is blocked at the fifth try" "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-login.php")" "403"
expect "firewall: the blocked address is refused everywhere for a while" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "403"
expect "firewall: the block is recorded as an event" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'firewall.blocked'")" "1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=firewall"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=firewall_unblock" -d "_csrf=$TOKEN&ip=127.0.0.1"
expect "firewall: an address can be unblocked" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"
sq "UPDATE ka_nastaveni SET hodnota = '3' WHERE promenna = 'firewall_rate'" > /dev/null
codes=""; for i in 1 2 3 4 5 6 7 8; do codes="$codes $(curl -s -o /dev/null -w '%{http_code}' "$B/")"; done
case "$codes" in *429*) echo "  ok     firewall: too many requests a minute get 429";; *) echo "  CHYBA  firewall: limit požadavků ($codes)"; ERRORS=$((ERRORS+1));; esac
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna IN ('firewall_enabled', 'firewall_rate')" > /dev/null
expect "firewall: off again, the site answers" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"

