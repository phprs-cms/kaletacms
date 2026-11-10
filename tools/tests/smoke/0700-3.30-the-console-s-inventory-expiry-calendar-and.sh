echo "== 3.30: the console's inventory, expiry calendar and update rings (Fleet\Inventory, Fleet\Calendar, Fleet\Rollout), MCP fleet_inventory and fleet_calendar"
# the real site's heartbeat carries the inventory parts – system names, a version and a family, never a host
expect "3.30: the heartbeat carries the database, the web server family, the features, the add-ons and the languages" \
  "$(sq3 "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.db_server.family')) IN ('mysql', 'mariadb'), JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.db_server.version')) REGEXP '^[0-9]+\\\\.[0-9]+',
    JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.web_server')) IN ('apache', 'nginx', 'litespeed', 'iis', 'caddy', 'php', 'other', 'unknown'), JSON_CONTAINS(JSON_EXTRACT(heartbeat, '\$.features'), '\"novinky\"'),
    JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.addons')), JSON_CONTAINS(JSON_EXTRACT(heartbeat, '\$.languages'), '\"cs\"'), JSON_EXTRACT(heartbeat, '\$.db_server', '\$.web_server', '\$.features', '\$.addons', '\$.languages') REGEXP '127\\.0\\.0\\.1|localhost') FROM ka_fleet_sites WHERE id = $FLEET_ID")" \
  "1|1|1|1|ARRAY|1|0"
# three more sites stored in the console: one before 3.30, a pilot with a certificate ending in 5 days and a domain in 20, a careful one
ALLOWED330=$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_versions'"); CUST330=$(sq3 "SELECT COALESCE(customer_id, 'NULL') FROM ka_fleet_sites WHERE id = $FLEET_ID")
CERT330=$(site_time '+5 days' Y-m-d); DOMAIN330=$(site_time '+20 days' Y-m-d)
sq3 "INSERT INTO ka_fleet_sites (name, url, public_key, paired_at, last_seen, version, version_since, manage_updates, ring, status, up, heartbeat) VALUES
  ('=SUM(1) X330 Old', 'http://old330.test', 'old330key', '$(site_time)', '$(site_time)', '3.28.0', '$(site_time)', 0, 'normal', 'ok', 1, '{\"version\":\"3.28.0\",\"php\":\"8.3.11\",\"update_channel\":\"stable\"}'),
  ('X330 Pilot', 'http://pilot330.test', 'pilot330key', '$(site_time)', '$(site_time)', '3.99.9', '$(site_time '-50 hours')', 1, 'canary', 'ok', 1,
   '{\"version\":\"3.99.9\",\"php\":\"8.4.5\",\"update_channel\":\"latest\",\"db_server\":{\"family\":\"mariadb\",\"version\":\"10.6.5\",\"host\":\"db.evil330.test\"},\"web_server\":\"nginx\",\"features\":[\"novinky\",\"bookings\",\"whistleblowing\"],\"addons\":[\"seo-pack\"],\"languages\":[\"cs\",\"en\"],\"domain_watch\":{\"checked\":$(date +%s),\"local\":false,\"certificate\":{\"expires\":\"$CERT330\",\"days\":5,\"error\":false},\"domain\":{\"expires\":\"$DOMAIN330\",\"days\":20,\"error\":false}},\"offsite\":{\"status\":\"ok\",\"at\":\"$(site_time now Y-m-d)\",\"recovery_key\":\"confirmed\"},\"recovery_key_since\":\"2026-03-01\"}'),
  ('X330 Careful', 'http://careful330.test', 'careful330key', '$(site_time)', '$(site_time)', '3.29.0', '$(site_time)', 1, 'careful', 'ok', 1,
   '{\"version\":\"3.29.0\",\"php\":\"8.4.5\",\"offsite\":{\"status\":\"paused\",\"recovery_key\":\"unconfirmed\"}}')" > /dev/null
PILOT330=$(sq3 "SELECT id FROM ka_fleet_sites WHERE public_key = 'pilot330key'"); OLDX330=$(sq3 "SELECT id FROM ka_fleet_sites WHERE public_key = 'old330key'")
expect "3.30: migration 0116 – the test sites of before are the pilot ring, the new columns and the reminders table" \
  "$(sq3 "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'ka_fleet_sites' AND column_name = 'update_window') OR (table_name = 'ka_fleet_customers' AND column_name = 'update_window'))), '|', (SELECT COUNT(*) FROM ka_fleet_expiry_reminders))")" "2|0"
# the inventory: filters, the old site, CSV
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet&action=inventory&php=8.3" > "$WORK/code330.txt"
expect "3.30 Inventory: the PHP 8.3 filter shows the old site only, marked \"update the site to see\"; the tab is current" \
  "$(cat "$WORK/code330.txt")|$(grep -c 'X330 Old' "$WORK/response")|$(grep -c 'X330 Pilot' "$WORK/response")|$(grep -A3 'X330 Old' "$WORK/response" | grep -cE 'Update the site to see|Uvidíte po aktualizaci webu')|$(grep -c 'action=inventory" class="aktivni"' "$WORK/response")" "200|1|0|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=inventory&feature=bookings&server=nginx"
expect "3.30 Inventory: the feature and server filters; the database family and version; no host and never the whistleblowing channel" \
  "$(grep -c 'X330 Pilot' "$WORK/response")|$(grep -c 'X330 Old' "$WORK/response")|$(grep -c 'MariaDB 10.6.5' "$WORK/response")|$(grep -ciE 'evil330|whistleblow' "$WORK/response")" "1|0|1|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -D "$WORK/head330.txt" -o "$WORK/inventory330.csv" "$B3/admin.php?module=fleet&action=inventory_csv&q=x330"
expect "3.30 Inventory CSV: a download of what is filtered (the three X330 sites and the header), a site name starting with = cannot run as a formula" \
  "$(grep -ci '^content-type: text/csv' "$WORK/head330.txt")|$(grep -ci 'attachment; filename="fleet-inventory-' "$WORK/head330.txt")|$(grep -c 'X330' "$WORK/inventory330.csv")|$(grep -c "^\"'=SUM(1) X330 Old\";" "$WORK/inventory330.csv")|$(wc -l < "$WORK/inventory330.csv" | tr -d ' ')" "1|1|3|1|4"
# the calendar and its reminders: sent once per date and "days before", e-mail and the bell
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet&action=calendar" > "$WORK/code330.txt"
expect "3.30 Calendar: the month view and the list carry the pilot's certificate (red) and domain (amber), the careful site's recovery key waits" \
  "$(cat "$WORK/code330.txt")|$(grep -c 'class="flotila-stav flotila-stav-bad" href="/admin.php?module=fleet&amp;action=detail&amp;id='"$PILOT330"'"' "$WORK/response" | awk '{print ($1 >= 1)}')|$(grep -c 'class="vypis vypis-karty flotila-terminy"' "$WORK/response")|$(grep -A3 'X330 Careful' "$WORK/response" | grep -c 'flotila-stav-bad')" "200|1|1|1"
SMTP_PORT=$((PORT + 3)); mkdir -p "$WORK/smtp330"
# an earlier sink on the same port may still be closing: wait until the port is free, then until the new sink listens
for _ in $(seq 1 50); do command php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 1 : 0);' "$SMTP_PORT" && break; sleep 0.1; done
command php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp330" > /dev/null 2>&1 & SMTP_PID=$!
for _ in $(seq 1 50); do command php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$SMTP_PORT" && break; sleep 0.1; done
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'console@example.cz'), ('alerts_enabled', '1');
  DELETE FROM ka_jobs WHERE name = 'fleet_expiry'" > /dev/null
curl -s -o /dev/null "$B3/tasks?token=contoken317"
for _ in $(seq 1 50); do ls "$WORK"/smtp330/*.eml > /dev/null 2>&1 && break; sleep 0.2; done; sleep 0.5 # the job may finish after the reply
F=$(grep -l "^X-Rcpt-To: owner317@agency.example" "$WORK"/smtp330/*.eml 2>/dev/null | tail -1 || true); : > "$WORK/eml330.txt"; [ -n "$F" ] && eml "$F" > "$WORK/eml330.txt"
expect "3.30 reminders: the certificate (5 days: 3.31.1 – 14 and 7 days before recorded, one reminder) and the domain (20 days: 30) – one e-mail to the alert recipients with plain links, a bell notification for the administrator" \
  "$(sq3 "SELECT GROUP_CONCAT(CONCAT(kind, ':', days_before) ORDER BY kind, days_before) FROM ka_fleet_expiry_reminders WHERE site_id = $PILOT330")|$(ls "$WORK"/smtp330/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(grep -c '^X-Rcpt-To: owner317@agency.example' "$WORK/eml330.txt")|$(grep -c "admin.php?module=fleet&action=detail&id=$PILOT330" "$WORK/eml330.txt")|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'fleet_expiry' AND target = 'fleet_site:$PILOT330' AND emailed_at IS NOT NULL")" \
  "certificate:7,certificate:14,domain:30|1|1|2|2"
sq3 "DELETE FROM ka_jobs WHERE name = 'fleet_expiry'" > /dev/null; curl -s -o /dev/null "$B3/tasks?token=contoken317"; sleep 1
expect "3.30 reminders: the next run sends nothing again" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_expiry_reminders")|$(ls "$WORK"/smtp330/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'fleet_expiry'")" "3|1|2"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?action=inbox"
expect "3.30: the bell lists the reminder with the site in the console" "$(grep -cE 'X330 Pilot' "$WORK/response" | awk '{print ($1 >= 1)}')" "1"
# 3.31: the mail server is down when a reminder is due – the bell arrives, the e-mail waits in the mail queue; once the server
# is back the job "mail" delivers it exactly once, and no later run repeats the e-mail or the bell
kill "$SMTP_PID" 2>/dev/null || true; wait "$SMTP_PID" 2>/dev/null || true
for _ in $(seq 1 50); do command php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 1 : 0);' "$SMTP_PORT" && break; sleep 0.1; done
R331_REMINDER="(predmet LIKE '%termíny vašich webů%' OR predmet LIKE '%dates of your sites%')"
# (no "eml | grep -q" here: grep quits early and pipefail would count the cut-off eml as a failure)
r331_emls() { local n=0 f; for f in "$WORK"/smtp330/*.eml; do { [ -f "$f" ] && grep -q '^X-Rcpt-To: owner317@agency.example' "$f"; } || continue
  case "$(eml "$f" | grep '^Subject-Decoded: ')" in *"termíny vašich webů"*|*"dates of your sites"*) n=$((n + 1)) ;; esac; done; echo "$n"; }
R331_P0=$(sq3 "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
sq3 "DELETE FROM ka_fleet_expiry_reminders WHERE site_id = $PILOT330 AND kind = 'domain'; DELETE FROM ka_jobs WHERE name IN ('fleet_expiry', 'mail')" > /dev/null
curl -s -o /dev/null "$B3/tasks?token=contoken317"; sleep 1
expect "3.31 reminders, mail server down: the reminder recorded, the bell once more, no e-mail delivered – it waits in the mail queue for owner317 only" \
  "$(sq3 "SELECT COUNT(*) FROM ka_fleet_expiry_reminders")|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'fleet_expiry'")|$(ls "$WORK"/smtp330/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(sq3 "SELECT CONCAT(COUNT(*), '|', SUM(odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus IS NOT NULL), '|', SUM(komu = 'owner317@agency.example')) FROM ka_posta WHERE idp > $R331_P0 AND $R331_REMINDER")" \
  "3|3|1|1|1|1"
command php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp330" > /dev/null 2>&1 & SMTP_PID=$!
for _ in $(seq 1 50); do command php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$SMTP_PORT" && break; sleep 0.1; done
sq3 "UPDATE ka_posta SET dalsi_pokus = '$(site_time '-1 day')' WHERE idp > $R331_P0 AND odeslano IS NULL AND telo IS NOT NULL; DELETE FROM ka_jobs WHERE name IN ('fleet_expiry', 'mail')" > /dev/null
curl -s -o /dev/null "$B3/tasks?token=contoken317"
for _ in $(seq 1 50); do [ "$(r331_emls)" -ge 2 ] && break; sleep 0.2; done; sleep 0.5
expect "3.31 reminders, mail server back: the job \"mail\" delivers the waiting reminder exactly once, to owner317; the bell is not repeated" \
  "$(r331_emls)|$(sq3 "SELECT CONCAT(COUNT(*), '|', SUM(odeslano IS NOT NULL)) FROM ka_posta WHERE idp > $R331_P0 AND $R331_REMINDER")|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'fleet_expiry'")" \
  "2|1|1|3"
sq3 "DELETE FROM ka_jobs WHERE name IN ('fleet_expiry', 'mail')" > /dev/null; curl -s -o /dev/null "$B3/tasks?token=contoken317"; sleep 1
expect "3.31 reminders: the next run sends no e-mail and no bell again" \
  "$(r331_emls)|$(sq3 "SELECT COUNT(*) FROM ka_posta WHERE idp > $R331_P0 AND $R331_REMINDER")|$(sq3 "SELECT COUNT(*) FROM ka_fleet_expiry_reminders")|$(sq3 "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'fleet_expiry'")" \
  "2|1|3|3"
kill "$SMTP_PID" 2>/dev/null || true; sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=calendar"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_save" -d "_csrf=$(csrf)" --data-urlencode "fleet_expiry_reminders=10, 45"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_save" -d "_csrf=$(csrf)" --data-urlencode "fleet_expiry_reminders=400"
expect "3.30 Calendar: the reminder days saved, the largest first; a wrong list keeps them" "$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_expiry_reminders'")" "45,10"
# 3.31.1: certificates have their own reminder days (14, 7 by default) next to the domains'; one wrong list saves neither
A3311_CAL=$(grep -c 'name="fleet_certificate_reminders" value="14, 7"' "$WORK/response")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_save" -d "_csrf=$(csrf)" --data-urlencode "fleet_certificate_reminders=3, 21" --data-urlencode "fleet_expiry_reminders=60, 10"
A3311_SAVED=$(sq3 "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('fleet_certificate_reminders', 'fleet_expiry_reminders')")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_save" -d "_csrf=$(csrf)" --data-urlencode "fleet_certificate_reminders=14, 7" --data-urlencode "fleet_expiry_reminders=x"
expect "3.31.1 Calendar: the certificates' reminder days next to the domains' (14, 7 by default), both saved; a wrong list saves neither" \
  "$A3311_CAL|$A3311_SAVED|$(sq3 "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('fleet_certificate_reminders', 'fleet_expiry_reminders')")" \
  "1|fleet_certificate_reminders=21,3,fleet_expiry_reminders=60,10|fleet_certificate_reminders=21,3,fleet_expiry_reminders=60,10"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_save" -d "_csrf=$(csrf)" --data-urlencode "fleet_certificate_reminders=14, 7" --data-urlencode "fleet_expiry_reminders=45, 10"
# the calendar link: shown once, the feed with the dates, revoked → gone
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_feed" -d "_csrf=$(csrf)" -d feed=new
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=calendar"
FEED330=$(grep -o 'value="[^"]*/fleet/calendar\.ics?token=[0-9]*-[0-9a-f]*"' "$WORK/response" | head -1 | sed 's/^value="//;s/"$//;s/&amp;/\&/g' || true); TOKEN330="${FEED330##*token=}"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response2" "$B3/admin.php?module=fleet&action=calendar"
curl -s -D "$WORK/head330.txt" -o "$WORK/feed330.ics" "$B3/fleet/calendar.ics?token=$TOKEN330"
expect "3.30 calendar link: shown once, only its hash stored; the feed is an iCalendar with the pilot's certificate, not cached" \
  "$([ -n "$TOKEN330" ] && echo link)|$(grep -c 'calendar.ics?token=' "$WORK/response2")|$(sq3 "SELECT hodnota LIKE '%${TOKEN330#*-}%' FROM ka_nastaveni WHERE promenna = 'fleet_calendar_feeds'")|$(grep -ci '^content-type: text/calendar' "$WORK/head330.txt")|$(grep -ci '^cache-control: no-store' "$WORK/head330.txt")|$(grep -c "DTSTART;VALUE=DATE:${CERT330//-/}" "$WORK/feed330.ics")|$(grep -c 'X330 Pilot' "$WORK/feed330.ics" | awk '{print ($1 >= 1)}')" \
  "link|0|0|1|1|1|1"
expect "3.30 calendar link: a wrong or made-up token gets 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B3/fleet/calendar.ics?token=${TOKEN330%%-*}-$(printf '0%.0s' $(seq 1 32))")|$(curl -s -o /dev/null -w '%{http_code}' "$B3/fleet/calendar.ics?token=x")|$(curl -s -o /dev/null -w '%{http_code}' "$B/fleet/calendar.ics?token=$TOKEN330")" "404|404|404"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=calendar_feed" -d "_csrf=$(csrf)" -d feed=revoke
expect "3.30 calendar link: revoked – the feed is gone" "$(curl -s -o /dev/null -w '%{http_code}' "$B3/fleet/calendar.ics?token=$TOKEN330")|$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_calendar_feeds'")" "404|"
# the update gate: what the console answers a site's own signed heartbeat, by ring, window and release
hb330() { sleep 1; php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $s = $app->settings();
  $body = json_encode(["action" => "heartbeat", "site_id" => $s->int("fleet_site_id"), "ts" => time(), "version" => "3.29.0", "manage_updates" => true]);
  file_put_contents($argv[2], $body); echo Kaleta\Fleet\Keys::sign($s, $body);' "$WORK/web" "$WORK/hb330.json" > "$WORK/hb330.sig"
  curl -s -X POST "$B3/fleet/heartbeat" -H 'Content-Type: application/json' -H "X-Kaleta-Signature: $(cat "$WORK/hb330.sig")" --data-binary @"$WORK/hb330.json" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) && array_key_exists("update_allowed", $d) ? ($d["update_allowed"] === "" ? "-" : $d["update_allowed"]) : "error";'; }
CLOSED330=$(php -r 'echo date("N", strtotime("+3 days"));')
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('fleet_versions', '{\"3.99.9\":[$(($(date +%s) - 3600)),false]}');
  UPDATE ka_fleet_sites SET ring = 'normal', update_window = NULL, customer_id = $ACME317 WHERE id = $FLEET_ID; UPDATE ka_fleet_customers SET update_window = '' WHERE id = $ACME317" > /dev/null
G1=$(hb330); sq3 "UPDATE ka_fleet_sites SET update_window = '$CLOSED330|00:00-00:00' WHERE id = $FLEET_ID" > /dev/null; G2=$(hb330)
sq3 "UPDATE ka_fleet_sites SET update_window = NULL WHERE id = $FLEET_ID; UPDATE ka_fleet_customers SET update_window = '$CLOSED330|00:00-00:00' WHERE id = $ACME317" > /dev/null; G3=$(hb330)
sq3 "UPDATE ka_fleet_sites SET update_window = '' WHERE id = $FLEET_ID" > /dev/null; G4=$(hb330)
sq3 "UPDATE ka_fleet_sites SET ring = 'careful' WHERE id = $FLEET_ID" > /dev/null; G5=$(hb330)
sq3 "UPDATE ka_fleet_sites SET update_window = '$CLOSED330|00:00-00:00' WHERE id = $FLEET_ID; UPDATE ka_nastaveni SET hodnota = '{\"3.99.9\":[$(($(date +%s) - 3600)),true]}' WHERE promenna = 'fleet_versions'" > /dev/null; G6=$(hb330)
sq3 "UPDATE ka_fleet_sites SET ring = 'pilot' WHERE id = $FLEET_ID; UPDATE ka_nastaveni SET hodnota = '{\"3.99.9\":[$(($(date +%s) - 3600)),false]}' WHERE promenna = 'fleet_versions'" > /dev/null; G7=$(hb330)
sq3 "UPDATE ka_fleet_sites SET update_window = '1234567|00:00-00:00' WHERE id = $FLEET_ID" > /dev/null; G8=$(hb330)
sq3 "UPDATE ka_fleet_sites SET ring = 'normal', update_window = NULL WHERE id = $FLEET_ID; UPDATE ka_fleet_customers SET update_window = '' WHERE id = $ACME317; UPDATE ka_fleet_sites SET status = 'error' WHERE id = $PILOT330" > /dev/null; G9=$(hb330)
expect "3.30 update gate: normal after the pilot ran it 48 h | its own window closed | its customer's window closed | its own 'any time' over the customer's | careful (7 days) | a security release at once, window or not | a pilot outside its window | inside it | the pilot fails → held back" \
  "$G1|$G2|$G3|$G4|$G5|$G6|$G7|$G8|$G9" "3.99.9|-|-|3.99.9|-|3.99.9|-|3.99.9|-"
# 3.31.1: the pilot fails and the release has been known for 6 days – Today and fleet_today have one amber item "rollout held
# back" with a plain link to the Rollout tab, which says how it goes on; known for an hour only – no item
FV3311=$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_versions'")
sq3 "UPDATE ka_nastaveni SET hodnota = '{\"3.99.9\":[$(($(date +%s) - 6 * 86400)),false]}' WHERE promenna = 'fleet_versions'" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
A3311_TODAY="$(grep -c 'data-fleet-rollout-held="3.99.9"' "$WORK/response")|$(grep -A6 'data-fleet-rollout-held=' "$WORK/response" | grep -c 'href="/admin.php?module=fleet&amp;action=rollout"' | awk '{print ($1 >= 1)}')|$(grep -cE '<strong>(Nasazování aktualizace se zdrželo|Update rollout held back)</strong>' "$WORK/response")"
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_today","arguments":{}}}' > "$WORK/response"
A3311_MCP=$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true); $i = array_values(array_filter($d["items"] ?? [], fn ($x) => $x["check"] === "rollout"));
  echo count($i), "|", $i[0]["state"] ?? "-", "|", (int) preg_match("~^http://127\.0\.0\.1:[0-9]+/admin\.php\?module=fleet&action=rollout$~", $i[0]["link"] ?? ""), "|", (int) isset($i[0]["site_id"]), "|", (int) str_starts_with($i[0]["detail"] ?? "", "Version 3.99.9");' "$WORK/response")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=rollout"; A3311_HINT=$(grep -c 'data-rollout-held' "$WORK/response")
sq3 "UPDATE ka_nastaveni SET hodnota = '{\"3.99.9\":[$(($(date +%s) - 3600)),false]}' WHERE promenna = 'fleet_versions'" > /dev/null
expect "3.31.1 Today: a rollout held back by the pilots for 6 days is one amber item with a plain link to Rollout (screen and fleet_today), Rollout says how it goes on; after an hour – none" \
  "$A3311_TODAY|$A3311_MCP|$A3311_HINT|$(curl -s -b "$JAR_CON" -c "$JAR_CON" "$B3/admin.php?module=fleet" | grep -c 'data-fleet-rollout-held=')" "1|1|1|1|warn|1|0|1|1|0"
sq3 "UPDATE ka_nastaveni SET hodnota = '$FV3311' WHERE promenna = 'fleet_versions'" > /dev/null
sq3 "UPDATE ka_fleet_sites SET status = 'ok' WHERE id = $PILOT330" > /dev/null
# N330-5: the console installed a security release on itself before any site asked – it still goes to every site at once
V330=$(sed -nE "s/^const KALETA_VERSION = '([^']+)';/\\1/p" "$WORK/web3/system/bootstrap.php")
CACHE330=$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'update_cache'"); FV330=$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_versions'")
sq3 "UPDATE ka_nastaveni SET hodnota = '{\"url\":\"http://127.0.0.1:1/aktualizace.json\",\"overeno\":$(date +%s),\"manifest\":{\"verze\":\"$V330\",\"bezpecnostni\":true,\"min_php\":\"8.3\",\"zmeny\":[]},\"chyba\":null}' WHERE promenna = 'update_cache';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('fleet_versions', '{\"$V330\":[$(date +%s),false]}');
  UPDATE ka_fleet_sites SET ring = 'careful', update_window = '$CLOSED330|00:00-00:00' WHERE id = $FLEET_ID" > /dev/null
G10=$(hb330)
expect "3.30 review N330-5: a security release the console already runs goes to a careful site outside its window at once" "$G10|$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_versions'" | grep -c "\"$V330\":\[[0-9]*,true\]")" "$V330|1"
sq3 "UPDATE ka_nastaveni SET hodnota = '$CACHE330' WHERE promenna = 'update_cache'; UPDATE ka_nastaveni SET hodnota = '$FV330' WHERE promenna = 'fleet_versions'; UPDATE ka_fleet_sites SET ring = 'normal', update_window = NULL WHERE id = $FLEET_ID" > /dev/null
# the rollout screen, the ring and window forms, the delays
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet&action=rollout" > "$WORK/code330.txt"
expect "3.30 Rollout: the rings with their counts, the pilot updated, the old site deciding itself, a window form per customer" \
  "$(cat "$WORK/code330.txt")|$(grep -c 'class="vypis vypis-karty flotila-okruhy"' "$WORK/response")|$(grep -A4 'X330 Pilot' "$WORK/response" | grep -cE '>(Pilot|Pilotní)<')|$(grep -A6 'X330 Old' "$WORK/response" | grep -cE 'Decides itself|Rozhoduje sám')|$(grep -c 'name="window_days\[\]"' "$WORK/response" | awk '{print ($1 >= 7)}')" "200|1|1|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=window" -d "_csrf=$(csrf)" -d "customer=$ACME317" -d 'window_days[]=5' -d 'window_days[]=1' -d 'window_days[]=9' -d window_from=22:00 -d window_to=5:00
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=rollout_save" -d "_csrf=$(csrf)" -d fleet_ring_pilot_hours=2 -d fleet_ring_normal_hours=24 -d fleet_ring_careful_hours=336
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=rollout_save" -d "_csrf=$(csrf)" -d fleet_ring_pilot_hours=1 -d fleet_ring_normal_hours=x -d fleet_ring_careful_hours=99999
expect "3.30 Rollout: a customer's window saved (days sorted, an unknown day left out), the delays saved, a wrong delay saves nothing" \
  "$(sq3 "SELECT update_window FROM ka_fleet_customers WHERE id = $ACME317")|$(sq3 "SELECT GROUP_CONCAT(hodnota ORDER BY promenna) FROM ka_nastaveni WHERE promenna LIKE 'fleet\\_ring\\_%\\_hours'")" "15|22:00-05:00|336,24,2"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
grep -q 'name="ring"' "$WORK/response" && grep -q 'name="window_mode" value="customer" checked' "$WORK/response" && grep -qE 'Mon, Fri 22:00–05:00|Po, Pá 22:00–05:00' "$WORK/response" \
  && echo "  ok     3.30: a site's page offers its ring and the customer's window in words" || { echo "  CHYBA  3.30: the ring and window form on the site's page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=ring" -d "_csrf=$(csrf)" -d "id=$FLEET_ID" -d ring=careful -d window_mode=own -d 'window_days[]=6' -d 'window_days[]=7' -d window_from=10:00 -d window_to=10:00
R1=$(sq3 "SELECT CONCAT(ring, '|', update_window) FROM ka_fleet_sites WHERE id = $FLEET_ID")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=ring" -d "_csrf=$(csrf)" -d "id=$FLEET_ID" -d ring=canary -d window_mode=customer
expect "3.30: the ring and the site's own window saved; the old value canary means pilot; back to the customer's window" "$R1|$(sq3 "SELECT CONCAT(ring, '|', COALESCE(update_window, 'NULL')) FROM ka_fleet_sites WHERE id = $FLEET_ID")" "careful|67|00:00-00:00|pilot|NULL"
# N330-3: a window shorter than two hours could fall between two hourly heartbeats every day – refused, nothing saved
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=window" -d "_csrf=$(csrf)" -d "customer=$ACME317" -d 'window_days[]=3' -d window_from=03:00 -d window_to=03:30
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=ring" -d "_csrf=$(csrf)" -d "id=$FLEET_ID" -d ring=careful -d window_mode=own -d 'window_days[]=6' -d window_from=23:30 -d window_to=00:30
expect "3.30 review N330-3: a maintenance window under two hours is refused for a customer and a site (nothing saved)" \
  "$(sq3 "SELECT update_window FROM ka_fleet_customers WHERE id = $ACME317")|$(sq3 "SELECT CONCAT(ring, '|', COALESCE(update_window, 'NULL')) FROM ka_fleet_sites WHERE id = $FLEET_ID")" "15|22:00-05:00|pilot|NULL"
# MCP on the console
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_inventory","arguments":{"php":"8.3"}}}' > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true);
  echo implode("|", [count($d["sites"] ?? []), $d["sites"][0]["name"] ?? "-", json_encode($d["sites"][0]["update_needed"] ?? null), $d["sites"][0]["update"]["state"] ?? "-", $d["rollout"]["version"] ?? "-", $d["rollout"]["delays_hours"]["normal"] ?? "-", isset($d["filters"]["php"]["8.3"]) ? "options" : "-"]);' "$WORK/response" > "$WORK/mcp330.txt"
expect "3.30 MCP fleet_inventory: filtered like the screen, the old site marked, its update state, the rollout and the filter values" "$(cat "$WORK/mcp330.txt")" "1|=SUM(1) X330 Old|true|self|3.99.9|24|options"
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_calendar","arguments":{"days":30}}}' > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true); $by = [];
  foreach ($d["dates"] ?? [] as $e) { $by[$e["site"] . ":" . $e["kind"]] = $e["state"] . ":" . $e["days_left"]; }
  $keys = array_column($d["recovery_keys"] ?? [], "state", "site");
  echo implode("|", [$by["X330 Pilot:certificate"] ?? "-", $by["X330 Pilot:domain"] ?? "-", $keys["X330 Careful"] ?? "-", $keys["X330 Pilot"] ?? "-", implode(",", $d["reminder_days_before"] ?? []), str_contains(file_get_contents($argv[1]), "evil330") ? "leak" : "clean", implode(",", $d["certificate_reminder_days_before"] ?? [])]);' "$WORK/response" > "$WORK/mcp330.txt"
expect "3.30 MCP fleet_calendar: the dates with their state and days left, the recovery keys, the reminder days (3.31.1: the certificates' apart)" "$(cat "$WORK/mcp330.txt")" "bad:4|warn:19|bad|ok|45,10|clean|14,7"
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_calendar","arguments":{"days":9999}}}' > "$WORK/response"
contains -q 'days must be between' "$WORK/response" && echo "  ok     3.30 MCP fleet_calendar: days out of range refused" || { echo "  CHYBA  3.30 MCP fleet_calendar days"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp fleet_inventory '{}' > "$WORK/response"; contains -q 'delays_hours' "$WORK/response" && { echo "  CHYBA  fleet_inventory works on a site that is not a console"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.30: fleet_inventory and fleet_calendar exist only on a console"
sq3 "UPDATE ka_fleet_sites SET ring = 'normal', update_window = NULL, customer_id = $CUST330 WHERE id = $FLEET_ID; UPDATE ka_fleet_customers SET update_window = '' WHERE id = $ACME317;
  DELETE FROM ka_fleet_sites WHERE public_key IN ('old330key', 'pilot330key', 'careful330key'); DELETE FROM ka_fleet_expiry_reminders; DELETE FROM ka_notifications WHERE kind = 'fleet_expiry';
  DELETE FROM ka_nastaveni WHERE promenna IN ('fleet_ring_pilot_hours', 'fleet_ring_normal_hours', 'fleet_ring_careful_hours', 'fleet_expiry_reminders', 'fleet_certificate_reminders')" > /dev/null
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('fleet_versions', '$ALLOWED330')" > /dev/null
# N317-9: the archived reports of a forgotten customer (id 900 here) never pass to a new customer
sq3 "INSERT INTO ka_fleet_reports (month, customer_id, customer, created_at, data) VALUES ('2020-01', 900, 'Forgotten 317', '$(site_time)', '{}')" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$GAMMA317"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer" -d "_csrf=$(csrf)" -d "id=$GAMMA317" -d "customer=New 317" -d "note=Calls on Mondays"
expect "N317-9: a new customer gets an id above every archived report's customer" "$(sq3 "SELECT id > 900 FROM ka_fleet_customers WHERE name = 'New 317'")" "1"
sq3 "DELETE FROM ka_fleet_reports WHERE customer_id = 900" > /dev/null
sq "DELETE FROM ka_requests WHERE title LIKE 'Fleet317%'" > /dev/null; sq3 "DELETE FROM ka_fleet_sites WHERE public_key IN ('beta317key', 'gamma317key')" > /dev/null
# disconnecting tells the console; the used key does not pair again
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_unpair" -d "_csrf=$(csrf)"
expect "disconnecting removes the site from the console and the console from the site" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_sites")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'")" "0|"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_pair" -d "_csrf=$(csrf)" --data-urlencode "pairing_key=$PAIRING_KEY" -d fleet_updates=1
expect "a used pairing key does not pair again" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'")" ""
kill "$SERVER3_PID" 2>/dev/null || true; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB3\`"
