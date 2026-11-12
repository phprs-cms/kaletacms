echo "== 3.39 A: the fleet console's Today grouped by check, accepting a state on the console (Fleet\Acceptances), six sections instead of twelve tabs"
# the main site becomes a console for this section (the console of 0640 is gone by now): three sites stored straight in it,
# alert e-mails off so the job fleet_alerts only drops the stale acceptances
A339_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
A339_ALERTS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'alerts_enabled'")
sq "UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',fleet') WHERE promenna = 'extensions'" > /dev/null
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('alerts_enabled', '0') ON DUPLICATE KEY UPDATE hodnota = '0'" > /dev/null
# a339_beat <off-site status>: a 3.29+ report with every part – the off-site copy as given, the cookie scan never ran (amber)
a339_beat() { php -r 'echo json_encode(["version" => "3.39.0", "last_backup" => time() - 3600, "cron_last_run" => time() - 600, "auto_updates" => true,
  "offsite" => ["status" => $argv[1], "at" => null, "recovery_key" => "none"], "mail_setup" => ["mode" => "smtp", "tested" => true, "tested_at" => date("Y-m-d")],
  "twofa" => ["required" => "admins", "admins_without" => 0], "cookie_scan" => ["at" => null, "error" => false, "cookies" => 0], "privacy_page" => "published",
  "accessibility_statement" => "published", "handover" => ["done" => 16, "total" => 16, "failing" => []]]);' -- "$1"; }
sq "INSERT INTO ka_fleet_sites (name, url, public_key, paired_at, last_seen, version, version_since, manage_updates, up, up_status, up_changed, heartbeat) VALUES
  ('A339 Down', 'http://down339a.test', 'down339akey', '$(site_time)', '$(site_time '-10 minutes')', '3.39.0', '$(site_time)', 1, 0, 503, '$(site_time '-2 hours')', '$(a339_beat off)'),
  ('A339 Bakery', 'http://bakery339a.test', 'bakery339akey', '$(site_time)', '$(site_time '-10 minutes')', '3.39.0', '$(site_time)', 1, 1, 200, NULL, '$(a339_beat off)'),
  ('A339 Smith', 'http://smith339a.test', 'smith339akey', '$(site_time)', '$(site_time '-10 minutes')', '3.39.0', '$(site_time)', 1, 1, 200, NULL, '$(a339_beat never)')" > /dev/null
A339_DOWN=$(sq "SELECT id FROM ka_fleet_sites WHERE public_key = 'down339akey'"); A339_BAKERY=$(sq "SELECT id FROM ka_fleet_sites WHERE public_key = 'bakery339akey'")
A339_SMITH=$(sq "SELECT id FROM ka_fleet_sites WHERE public_key = 'smith339akey'")

# Today grouped by check: the outage on top and open, the off-site backup one group, the cookie scan an amber group
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=fleet" > "$WORK/code339a.txt"
expect "3.39 Today: grouped by check – the outage first and open, the off-site backup one red group of 3 sites, the cookie scan an amber group of 3 rows (open – amber folds over 5), each row with Open and Accept" \
  "$(cat "$WORK/code339a.txt")|$(grep -o 'data-fleet-group="[a-z_]*"' "$WORK/response" | head -1)|$(grep -c 'data-fleet-group="uptime" open' "$WORK/response")|$(grep -A3 'data-fleet-group="offsite_backup"' "$WORK/response" | grep -o 'data-fleet-group-sites>[^0-9]*3' | grep -c 3)|$(grep -c 'data-fleet-group="cookie_scan" open>' "$WORK/response")|$(grep -c 'data-fleet-accept>' "$WORK/response" | awk '{print ($1 >= 6)}')" \
  "200|data-fleet-group=\"uptime\"|1|1|1|1"
expect "3.39 tabs: six sections, Today current as a section and as a view, the inbox a view of Today" \
  "$(sed -n '/<nav class="zalozky" aria-label/,/<\/nav>/p' "$WORK/response" | grep -c '<a href=')|$(grep -c '<a href="/admin.php?module=fleet" class="aktivni" aria-current="true">' "$WORK/response")|$(grep -c '<a href="/admin.php?module=fleet" class="aktivni" aria-current="page">' "$WORK/response")|$(sed -n '/class="zalozky flotila-pohledy"/,/<\/nav>/p' "$WORK/response" | grep -c 'action=inbox')" \
  "6|1|1|1"
# every former tab keeps its address and is the current view of its section
A339_OLD=""; for a339_action in inbox health workload sites customers reports alerts kit inventory calendar rollout; do
  a339_code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=fleet&action=$a339_action")
  [ "$a339_code" = 200 ] && [ "$(awk '/<nav class="zalozky( flotila-pohledy)?" aria-label/{n++; on=(n<=2)} on; /<\/nav>/{on=0}' "$WORK/response" | grep -c "action=$a339_action\" class=\"aktivni\" aria-current=\"page\"")" = 1 ] || A339_OLD="$A339_OLD $a339_action:$a339_code"
done
expect "3.39 tabs: the twelve former tab addresses all open, each the current view (or section) of its own" "$A339_OLD" ""

# accepting: the state is the console's own evaluation, a note is required, the last day defaults to 30 days and is bounded
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=fleet"; A339_CSRF=$(csrf)
a339_accept() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=fleet&action=accept" -d "_csrf=$A339_CSRF" "$@"; }
a339_accept -d "id=$A339_BAKERY" -d check=uptime --data-urlencode "note=Fine" > /dev/null
a339_accept -d "id=$A339_BAKERY" -d check=offsite_backup -d "note=" > /dev/null
a339_accept -d "id=$A339_BAKERY" -d check=offsite_backup -d "note=Later" -d "until=$(site_date '+400 days')" > /dev/null
a339_accept -d "id=$A339_BAKERY" -d check=no_such_check -d "note=Later" > /dev/null
expect "3.39 accept: an OK check, an empty note, a day past a year and an unknown check are refused – nothing stored" "$(sq "SELECT COUNT(*) FROM ka_fleet_acceptances")" "0"
a339_accept -d "id=$A339_BAKERY" -d check=offsite_backup -d state=warn --data-urlencode $'note=The customer declines\nan off-site backup <b>' > /dev/null
expect "3.39 accept: stored with the console's state (bad, not the posted warn), the note on one line, 30 days ahead, by the signed-in user" \
  "$(sq "SELECT CONCAT(state, '|', note, '|', ends_on = '$(site_date '+30 days')', '|', accepted_by IS NOT NULL) FROM ka_fleet_acceptances WHERE site_id = $A339_BAKERY AND check_id = 'offsite_backup'")" \
  "bad|The customer declines an off-site backup <b>|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=fleet"
expect "3.39 Today: the accepted check leaves its group (now 2 sites) for the folded Accepted group with its note escaped and who accepted; the summary counts it" \
  "$(grep -A3 'data-fleet-group="offsite_backup"' "$WORK/response" | grep -o 'data-fleet-group-sites>[^0-9]*2' | grep -c 2)|$(grep -c 'data-fleet-accepted>' "$WORK/response")|$(grep -c 'data-fleet-accepted-item="offsite_backup"' "$WORK/response")|$(grep -c 'an off-site backup &lt;b&gt;' "$WORK/response")|$(grep -c 'an off-site backup <b>' "$WORK/response")|$(grep -c 'data-fleet-accepted-count><strong>1<' "$WORK/response")" \
  "1|1|1|1|0|1"

# a worse state re-opens it at once; the job fleet_alerts then drops the acceptance whose check changed
a339_accept -d "id=$A339_SMITH" -d check=offsite_backup --data-urlencode "note=Waiting for the key" -d "until=$(site_date '+10 days')" > /dev/null
expect "3.39 accept: an amber check is accepted as warn until the day given" "$(sq "SELECT CONCAT(state, '|', ends_on) FROM ka_fleet_acceptances WHERE site_id = $A339_SMITH")" "warn|$(site_date '+10 days')"
sq "UPDATE ka_fleet_sites SET heartbeat = '$(a339_beat off)' WHERE id = $A339_SMITH" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=fleet"
expect "3.39 Today: the off-site backup of the accepted amber site turned red – open again in its group, not under Accepted" \
  "$(grep -A3 'data-fleet-group="offsite_backup"' "$WORK/response" | grep -o 'data-fleet-group-sites>[^0-9]*2' | grep -c 2)|$(grep -c 'data-fleet-accepted-item=' "$WORK/response")" "1|1"
expect "3.39 job fleet_alerts (alerts off): drops the acceptance whose check changed, keeps the one that holds" \
  "$(run_job fleet_alerts)|$(sq "SELECT GROUP_CONCAT(site_id) FROM ka_fleet_acceptances")" "off, acceptances ended: 1|$A339_BAKERY"

# MCP: fleet_today marks the accepted item and counts it, groups the open ones; fleet_plan leaves it out; fleet_health marks it
mcp fleet_today '{}' > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true); $b = (int) $argv[2];
  $acc = array_values(array_filter($d["items"] ?? [], fn ($i) => ($i["site_id"] ?? 0) === $b && $i["check"] === "offsite_backup"));
  echo implode("|", [$acc[0]["accepted"]["note"] ?? "-", isset($acc[0]["accepted"]["until"], $acc[0]["accepted"]["at"]) ? "dated" : "-", $d["summary"]["accepted"] ?? "-",
    ($d["summary"]["open"] ?? 0) + ($d["summary"]["accepted"] ?? 0) === ($d["total"] ?? -1) ? "sum" : "-", $d["groups"][0]["check"] ?? "-", $d["groups"][0]["state"] ?? "-",
    str_contains($d["note"] ?? "", "accepted:") ? "noted" : "-"]);' -- "$WORK/response" "$A339_BAKERY" > "$WORK/mcp339a.txt"
expect "3.39 MCP fleet_today: the accepted item carries its note and dates, the summary counts open and accepted, the open groups the outage first" \
  "$(cat "$WORK/mcp339a.txt")" "The customer declines an off-site backup <b>|dated|1|sum|uptime|bad|noted"
mcp fleet_plan "{\"site\":$A339_BAKERY}" > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true);
  echo implode("|", [$d["accepted_left_out"] ?? "-", in_array("offsite_backup", array_column($d["sites"][0]["items"] ?? [], "check"), true) ? "planned" : "left-out", in_array("cookie_scan", array_column($d["sites"][0]["items"] ?? [], "check"), true) ? "cookie" : "-"]);' -- "$WORK/response" > "$WORK/mcp339a.txt"
expect "3.39 MCP fleet_plan: the accepted state is not work to do – left out and counted; the open amber check stays" "$(cat "$WORK/mcp339a.txt")" "1|left-out|cookie"
mcp fleet_health '{}' > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true); $by = array_column($d["sites"] ?? [], null, "id");
  $p = array_column($by[(int) $argv[2]]["problems"] ?? [], null, "check"); echo ($p["offsite_backup"]["accepted"]["until"] ?? "-") . "|" . (isset($p["cookie_scan"]["accepted"]) ? "wrong" : "open");' -- "$WORK/response" "$A339_BAKERY" > "$WORK/mcp339a.txt"
expect "3.39 MCP fleet_health: the accepted problem says until when; the others are open" "$(cat "$WORK/mcp339a.txt")" "$(site_date '+30 days')|open"

# the weekly digest lists it as accepted, not as needing attention
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=fleet&action=digest_preview"
grep -q 'The customer declines an off-site backup &lt;b&gt;' "$WORK/response" && echo "  ok     3.39 digest: the accepted state with its note" || { echo "  CHYBA  3.39 digest: the accepted state"; ERRORS=$((ERRORS+1)); }

# the site's screen shows the acceptance and withdraws it; withdrawing returns there and the check is open again
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=fleet&action=detail&id=$A339_BAKERY"
A339_BACK=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=fleet&action=unaccept" -d "_csrf=$(csrf)" -d "id=$A339_BAKERY" -d check=offsite_backup -d from=detail)
expect "3.39 site screen: the accepted state is listed with Withdraw; withdrawing returns to the site and removes it" \
  "$(grep -c 'data-fleet-accepted-site' "$WORK/response")|$(grep -c "action=detail&id=$A339_BAKERY" <<< "$A339_BACK")|$(sq "SELECT COUNT(*) FROM ka_fleet_acceptances")" "1|1|0"
# a removed site takes its acceptances along
a339_accept -d "id=$A339_DOWN" -d check=uptime --data-urlencode "note=Moving hosts" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=fleet&action=remove" -d "_csrf=$A339_CSRF" -d "id=$A339_DOWN"
expect "3.39: an accepted outage is stored; removing the site removes it" "$(sq "SELECT COUNT(*) FROM ka_fleet_sites WHERE id = $A339_DOWN")|$(sq "SELECT COUNT(*) FROM ka_fleet_acceptances")" "0|0"

sq "DELETE FROM ka_fleet_acceptances; DELETE FROM ka_fleet_sites WHERE public_key IN ('down339akey', 'bakery339akey', 'smith339akey');" > /dev/null
sq "UPDATE ka_nastaveni SET hodnota = '$A339_EXT' WHERE promenna = 'extensions'" > /dev/null
if [ -n "$A339_ALERTS" ]; then sq "UPDATE ka_nastaveni SET hodnota = '$A339_ALERTS' WHERE promenna = 'alerts_enabled'" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'alerts_enabled'" > /dev/null; fi
