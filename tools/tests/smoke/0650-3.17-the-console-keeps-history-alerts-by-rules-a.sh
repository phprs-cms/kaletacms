echo "== 3.17: the console keeps history, alerts by rules, a weekly digest and a monthly report per customer (Fleet\History, AlertRules, Digest, Report)"
expect "history: every uptime check is added to the site's day; the down period is open" \
  "$(sq3 "SELECT CONCAT(SUM(checks_ok) >= 1, '|', SUM(checks_failed) >= 2, '|', COUNT(*)) FROM ka_fleet_history WHERE site_id = $FLEET_ID")|$(sq3 "SELECT CONCAT(COUNT(*), '|', SUM(ended_at IS NULL)) FROM ka_fleet_incidents WHERE site_id = $FLEET_ID")" "1|1|1|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)" -d "id=$FLEET_ID"
expect "history: the site answers again – the down period ends, the response time of the answer is kept" \
  "$(sq3 "SELECT CONCAT(COUNT(*), '|', SUM(ended_at IS NULL), '|', SUM(ended_at >= started_at)) FROM ka_fleet_incidents WHERE site_id = $FLEET_ID")|$(sq3 "SELECT SUM(response_count) >= 1 FROM ka_fleet_history WHERE site_id = $FLEET_ID")" "1|0|1|1"
# the site's own archived month travels as headline counts (report_summary) with the next heartbeat
PREV317=$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m");')
sq "REPLACE INTO ka_monthly_reports (month, created_at, schema_version, data) VALUES ('$PREV317', '$(site_time)', 1, '{\"month\":\"$PREV317\",\"stats\":{\"visits\":4321},\"enquiries\":{\"total\":17,\"forms\":[{\"form\":\"Kontakt\",\"n\":17}]},\"requests\":{\"done_count\":3,\"done\":[{\"title\":\"Secret title 317\"}]},\"work\":{\"areas\":{\"pages\":{\"people\":4,\"claude\":9}}},\"updates\":[{\"type\":\"update.applied\"}],\"backups\":{\"created\":30}}')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1 # a heartbeat of the same second is refused as repeated
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
SUMMARY317=$(sq3 "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.report_summary.month')), '|', JSON_EXTRACT(heartbeat, '\$.report_summary.visits'), '|', JSON_EXTRACT(heartbeat, '\$.report_summary.changes_claude'), '|', heartbeat LIKE '%Secret title 317%', '|', heartbeat LIKE '%Kontakt%') FROM ka_fleet_sites WHERE id = $FLEET_ID")
expect "report_summary: the heartbeat carries the counts of the site's last archived month – no titles, no forms" "$SUMMARY317" "$PREV317|4321|9|0|0"
[ "$SUMMARY317" = "$PREV317|4321|9|0|0" ] || echo "         (the site's last error: $(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_last_error'"); its archive: $(sq "SELECT CONCAT(month, ' ', LEFT(data, 60)) FROM ka_monthly_reports ORDER BY month DESC LIMIT 1"))"
# 3.29: the parts behind the console's health matrix, Today and workload (Fleet\Checks) – states, days and counts only
REQ2FA329=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'require_2fa'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('require_2fa', 'spravci');
  INSERT INTO ka_tasks (title, due_at, created_at, updated_at) VALUES ('Call Jan329 at jan329@client.example', '$(site_time '-2 days')', '$(site_time '-3 days')', '$(site_time '-3 days')'), ('Open329', NULL, '$(site_time)', '$(site_time)')" > /dev/null
# sent by the site's own job code (the administration would first ask this admin to set up two-step sign-in)
sleep 1; KALETA_FLEET_LOCAL=1 php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Fleet\Link::send($app);' "$WORK/web" > "$WORK/send329.txt" 2>&1
expect "3.29: the heartbeat carries the new parts – off-site, mail, two-step, cookie scan, privacy, statement, hand-over, workload" \
  "$(sq3 "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.offsite.status')) IN ('off', 'never', 'ok', 'paused', 'failing'), JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.mail_setup.mode')), JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.twofa.required')),
    JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.cookie_scan')), JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.privacy_page')) IN ('published', 'hidden', 'none'), JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.accessibility_statement')) IN ('published', 'draft', 'none'),
    JSON_EXTRACT(heartbeat, '\$.handover.total') > 0, JSON_EXTRACT(heartbeat, '\$.handover.done') <= JSON_EXTRACT(heartbeat, '\$.handover.total'), JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.handover.failing')),
    JSON_EXTRACT(heartbeat, '\$.workload.tasks_open') >= 2, JSON_EXTRACT(heartbeat, '\$.workload.tasks_overdue') >= 1, JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.workload.enquiries_this_week'))) FROM ka_fleet_sites WHERE id = $FLEET_ID")" \
  "1|$(sq "SELECT IF(COUNT(*) = 2, 'smtp', 'php') FROM ka_nastaveni WHERE (promenna = 'mail_mode' AND hodnota = 'smtp') OR (promenna = 'smtp_host' AND hodnota <> '')")|admins|OBJECT|1|1|1|1|ARRAY|1|1|INTEGER"
contains -qE 'Jan329|jan329@|smtp_host|backup_host|"cookies":\[' <<< "$(sq3 "SELECT JSON_EXTRACT(heartbeat, '\$.offsite', '\$.mail_setup', '\$.twofa', '\$.cookie_scan', '\$.handover', '\$.workload') FROM ka_fleet_sites WHERE id = $FLEET_ID")" \
  && { echo "  CHYBA  3.29: a new part carries a name, an address, a host or cookie names"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.29: the new parts carry no name, address, host or cookie name – counts, days and states only"
# the console maps them (Fleet\Checks): two-step required → ok, the link to fix it is built from the site's known address
php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
  $site = $app->db()->one("SELECT * FROM {fleet_sites} WHERE id = ?", [(int) $argv[2]]); $c = array_column(Kaleta\Core\Language::runWith("en", fn () => Kaleta\Fleet\Checks::forRow($site), "admin-"), null, "id");
  $today = array_column(array_filter(Kaleta\Fleet\Checks::today([$site]), fn ($i) => $i["check"] === "privacy_policy" || $i["check"] === "handover"), "link");
  echo $c["two_step"]["state"], "|", $c["uptime"]["state"], "|", $c["reporting"]["state"], "|", count(array_filter($c, fn ($x) => $x["state"] === "unknown" && $x["detail"] === "Update the site to see")), "|",
    count(array_filter($today, fn ($l) => !str_starts_with($l, rtrim((string) $site["url"], "/") . "/admin.php?module="))), "|", Kaleta\Fleet\Checks::workload([$site])["reporting"];' "$WORK/web3" "$FLEET_ID" > "$WORK/checks329.txt" 2>&1
expect "3.29: the console maps the stored parts – two-step required ok, up, reporting, no 'update the site' for a 3.29 site, links from its known address, workload known" "$(cat "$WORK/checks329.txt")" "ok|ok|ok|0|0|1"
# a hostile heartbeat signed by the site's own key: huge, negative, odd and unknown values are bounded or dropped, the console stays up
sleep 1
php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $s = $app->settings();
  $body = json_encode(["action" => "heartbeat", "site_id" => $s->int("fleet_site_id"), "ts" => time(), "version" => "3.29.0", "name" => "Hostile329",
    "offsite" => ["status" => "ok", "at" => "9999-99-99", "recovery_key" => "<script>", "host" => "ftp.evil329.test"], "mail_setup" => ["mode" => "smtp", "tested" => "yes", "tested_at" => "2026-02-30"],
    "twofa" => ["required" => "admins", "admins_without" => 9.9e300], "cookie_scan" => ["at" => "2026-11-03", "error" => "x", "cookies" => -40, "names" => ["evil329"]],
    "privacy_page" => "javascript:alert(1)", "accessibility_statement" => ["draft"], "handover" => ["done" => 999999, "total" => 16, "failing" => ["smtp", "https://evil329.test/", "smtp"]],
    "workload" => ["enquiries_this_week" => 1e300, "tasks_open" => 9223372036854775807, "tasks_overdue" => "7", "unanswered_over_24h" => -1, "jan329" => 5], "links" => ["two_step" => "https://evil329.test/"]]);
  file_put_contents($argv[2], $body); echo Kaleta\Fleet\Keys::sign($s, $body);' "$WORK/web" "$WORK/hostile329.json" > "$WORK/hostile329.sig"
expect "3.29: a hostile heartbeat signed by the site is accepted and bounded (no 500)" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -H 'Content-Type: application/json' -H "X-Kaleta-Signature: $(cat "$WORK/hostile329.sig")" --data-binary @"$WORK/hostile329.json")" "200"
expect "3.29: the hostile values – dates and enums dropped, counts clamped, unknown keys and failing rows left out" \
  "$(sq3 "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.offsite.status')), JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.offsite.at')), JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.offsite.recovery_key')),
    JSON_EXTRACT(heartbeat, '\$.mail_setup.tested'), JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.mail_setup.tested_at')), JSON_EXTRACT(heartbeat, '\$.twofa.admins_without'), JSON_EXTRACT(heartbeat, '\$.cookie_scan.cookies'),
    JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.privacy_page')), JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.accessibility_statement')), JSON_EXTRACT(heartbeat, '\$.handover.done'), JSON_EXTRACT(heartbeat, '\$.handover.failing'),
    JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.workload.enquiries_this_week')), JSON_EXTRACT(heartbeat, '\$.workload.tasks_open'), JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.workload.tasks_overdue')), JSON_EXTRACT(heartbeat, '\$.workload.unanswered_over_24h'),
    heartbeat LIKE '%evil329%', heartbeat LIKE '%jan329%') FROM ka_fleet_sites WHERE id = $FLEET_ID")" \
  "ok|NULL|NULL|false|NULL|0|0|NULL|NULL|16|[\"smtp\"]|NULL|1000000|NULL|0|0|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -w '%{http_code}' "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID" > "$WORK/code329.txt"
expect "3.29: the console's pages still open after the hostile heartbeat" "$(cat "$WORK/code329.txt")" "200"
# back to the real report
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('require_2fa', '$REQ2FA329'); DELETE FROM ka_tasks WHERE title IN ('Call Jan329 at jan329@client.example', 'Open329')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
# 3.29.1: the site sends the day browser-confirmed counting started (stats_confirmed_since); the console bounds it, marks the site's
# visits for the 14 days from it and asks no attention and sends no alert for the drop (every alert rule on)
SINCE3291=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'stats_confirmed_since'")
D3291=$(site_date '-3 days')
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('stats_confirmed_since', '$D3291'); INSERT IGNORE INTO ka_stat_dny (den, navstevy, zobrazeni) VALUES ('$(site_date '-10 days')', 0, 0)" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "3.29.1: the heartbeat carries the day browser-confirmed counting started, next to the visits of 7 days" \
  "$(sq3 "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(heartbeat, '\$.stats_confirmed_since')), '|', JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.visits_7_days'))) FROM ka_fleet_sites WHERE id = $FLEET_ID")" "$D3291|INTEGER"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
expect "3.29.1: the console's site page marks the visits – counting changed on that day, fewer bots, not fewer people" \
  "$(grep -c 'data-counting-changed' "$WORK/response")|$(grep -cE 'Counting of visits changed on|Počítání návštěv se změnilo' "$WORK/response")" "1|1"
php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
  $site = $app->db()->one("SELECT * FROM {fleet_sites} WHERE id = ?", [(int) $argv[2]]);
  $all = array_map(fn (array $k): array => ["on" => true, "threshold" => $k[1]], Kaleta\Fleet\AlertRules::KINDS);
  $words = implode(",", [...Kaleta\Fleet\Console::attention($site, time())["reasons"], ...array_column(Kaleta\Fleet\AlertRules::matches($site, $all, time()), "kind")]);
  echo preg_match("/visit|traffic|drop/", $words) === 1 ? "alarm: " . $words : "none", "|", Kaleta\Fleet\Console::countingChanged(Kaleta\Fleet\Console::storedBeat($site), time()) ?? "-";' "$WORK/web3" "$FLEET_ID" > "$WORK/alerts3291.txt" 2>&1
expect "3.29.1: the drop across the switch raises no attention and no alert on the console (every rule on); the mark holds" "$(cat "$WORK/alerts3291.txt")" "none|$D3291"
# a hostile day signed by the site is dropped on its own, the rest of the heartbeat kept
sleep 1
php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $s = $app->settings();
  $body = json_encode(["action" => "heartbeat", "site_id" => $s->int("fleet_site_id"), "ts" => time(), "version" => "3.29.1", "name" => "Hostile3291", "visits_7_days" => 12, "stats_confirmed_since" => "9999-12-31<script>"]);
  file_put_contents($argv[2], $body); echo Kaleta\Fleet\Keys::sign($s, $body);' "$WORK/web" "$WORK/hostile3291.json" > "$WORK/hostile3291.sig"
expect "3.29.1: a hostile stats_confirmed_since is dropped, the visits kept, the site page still opens without the mark" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -H 'Content-Type: application/json' -H "X-Kaleta-Signature: $(cat "$WORK/hostile3291.sig")" --data-binary @"$WORK/hostile3291.json")|$(sq3 "SELECT CONCAT(JSON_TYPE(JSON_EXTRACT(heartbeat, '\$.stats_confirmed_since')), '|', JSON_EXTRACT(heartbeat, '\$.visits_7_days')) FROM ka_fleet_sites WHERE id = $FLEET_ID")|$(curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID")|$(grep -c 'data-counting-changed' "$WORK/response")" \
  "200|NULL|12|200|0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('stats_confirmed_since', '$SINCE3291')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
# 3.26.1: the site's own uptime – it asks its console (POST /fleet/uptime, signed both ways; Fleet\Uptime), keeps the days and its monthly
# report (and MCP get_monthly_report, also for a month archived before) gains "uptime"; an older console without the address changes nothing
sq3 "INSERT INTO ka_fleet_history (site_id, day, checks_ok, checks_failed, response_ms_total, response_count) VALUES ($FLEET_ID, '$PREV317-05', 286, 2, 0, 286)" > /dev/null
sq "DELETE FROM ka_nastaveni WHERE promenna = 'fleet_uptime_days'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=reports"
expect "3.26.1: without uptime days the Reports tab (admin only) says where they come from" "$(grep -cE "the fleet console has sent this site|konzole webů pošle dny tohoto webu" "$WORK/response")" "1"
sleep 1; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
TODAY3261=$(sq3 "SELECT checks_ok + checks_failed FROM ka_fleet_history WHERE site_id = $FLEET_ID AND day = '$(site_time now Y-m-d)'")
expect "3.26.1: the site received its days from the console – the day of last month and today's checks – and keeps them with the time" \
  "$(sq "SELECT CONCAT(JSON_EXTRACT(hodnota, '\$.received') > 0, '|', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.days[0].date')), '|', JSON_EXTRACT(hodnota, '\$.days[0].checks'), '|', JSON_EXTRACT(hodnota, '\$.days[0].failed'), '|', JSON_EXTRACT(hodnota, '\$.days[last].checks')) FROM ka_nastaveni WHERE promenna = 'fleet_uptime_days'")" \
  "1|$PREV317-05|288|2|$TODAY3261"
mcp get_monthly_report "{\"month\":\"$PREV317\"}" > "$WORK/response"
php -r '$r = json_decode(file_get_contents($argv[1]), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "", true)["report"]["uptime"] ?? null;
  echo is_array($d) ? implode("|", [$d["percent"], $d["checks"], $d["failed"], count($d["days"]), $d["days"][4]["state"], $d["days"][4]["percent"], $d["days"][0]["state"]]) : "none";' "$WORK/response" > "$WORK/uptime.txt"
expect "3.26.1: MCP get_monthly_report has uptime for a month archived before 3.26.1 – percent, checks, failed and every day with its state" \
  "$(cat "$WORK/uptime.txt")" "99.3|288|2|$(php -r 'echo (new DateTimeImmutable($argv[1] . "-01"))->format("t");' "$PREV317")|partial|99.3|none"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=reports"
expect "3.26.1: with uptime days the Reports tab no longer shows the hint" "$(grep -cE "the fleet console has sent this site|Connect a fleet console to see uptime|konzole webů pošle dny|Připojte konzoli webů" "$WORK/response")" "0"
expect "3.26.1: the console answers the uptime question only to a request signed by the site's key" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/uptime" -H 'X-Kaleta-Signature: AAAA' -d "{\"action\":\"uptime\",\"site_id\":$FLEET_ID,\"ts\":$(date +%s)}")|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/uptime" -d '{"action":"uptime","site_id":99999}')" "403|404"
# an older console: the heartbeat goes through, /fleet/uptime is not there (404) – the site keeps its days and asks again the next day
OLDCON_PORT=$((PORT + 5))
cat > "$WORK/old-console.php" <<'PHP'
<?php
// a console before 3.26.1: it forwards the heartbeat to the real console and knows no /fleet/uptime
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/fleet/heartbeat') { http_response_code(404); echo 'Not found'; return; }
$ch = curl_init(getenv('CONSOLE') . '/fleet/heartbeat');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents('php://input'), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Kaleta-Signature: ' . ($_SERVER['HTTP_X_KALETA_SIGNATURE'] ?? '')]]);
$raw = (string) curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); http_response_code(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
foreach (explode("\r\n", substr($raw, 0, $size)) as $h) { if (stripos($h, 'X-Kaleta-Signature:') === 0 || stripos($h, 'Content-Type:') === 0) { header($h); } }
echo substr($raw, $size);
PHP
(CONSOLE="$B3" exec php -S "127.0.0.1:$OLDCON_PORT" "$WORK/old-console.php" > /dev/null 2>&1) & OLDCON_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$OLDCON_PORT/" && break; sleep 0.2; done
sq "UPDATE ka_nastaveni SET hodnota = 'http://127.0.0.1:$OLDCON_PORT' WHERE promenna = 'fleet_console_url'" > /dev/null
DAYS3261=$(sq "SELECT JSON_EXTRACT(hodnota, '\$.days') FROM ka_nastaveni WHERE promenna = 'fleet_uptime_days'"); LAST_TS3261=$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID")
sleep 1; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "3.26.1: an older console – the heartbeat is confirmed, the site keeps its days and only notes when it asked" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_last_error'")|$([ "$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID")" -gt "$LAST_TS3261" ] && echo sent)|$([ "$(sq "SELECT JSON_EXTRACT(hodnota, '\$.days') FROM ka_nastaveni WHERE promenna = 'fleet_uptime_days'")" = "$DAYS3261" ] && echo kept)" "|sent|kept"
sq "DELETE FROM ka_nastaveni WHERE promenna = 'fleet_uptime_days'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=reports"
expect "3.26.1: paired, no days – the Reports tab hint" "$(grep -cE "the fleet console has sent this site|konzole webů pošle dny tohoto webu" "$WORK/response")" "1"
sleep 1; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "3.26.1: an older console and no days yet – no uptime in the report (null), the heartbeat still confirmed" \
  "$(sq "SELECT CONCAT(JSON_TYPE(JSON_EXTRACT(hodnota, '\$.received')), '|', JSON_LENGTH(hodnota, '\$.days')) FROM ka_nastaveni WHERE promenna = 'fleet_uptime_days'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_last_error'")|$(mcp get_monthly_report "{\"month\":\"$PREV317\"}" | grep -o '\\"uptime\\":null' | head -1)" 'NULL|0||\"uptime\":null'
kill "$OLDCON_PID" 2>/dev/null
sq "UPDATE ka_nastaveni SET hodnota = '$B3' WHERE promenna = 'fleet_console_url'" > /dev/null
sq3 "DELETE FROM ka_fleet_history WHERE site_id = $FLEET_ID AND day = '$PREV317-05'" > /dev/null
# N317-5: a site's administrator changes its address – the console follows it and says so on the site's page
SITE_URL317=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")
sq "UPDATE ka_nastaveni SET hodnota = 'http://localhost:$PORT' WHERE promenna = 'site_url'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
expect "N317-5: a new address from the site – the console follows it and records it on the site's page (from, to)" \
  "$(sq3 "SELECT url FROM ka_fleet_sites WHERE id = $FLEET_ID")|$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(severity), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(data, '\$.from'))), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(data, '\$.to')))) FROM ka_events WHERE type = 'fleet.site_url_changed' AND JSON_EXTRACT(data, '\$.site') = $FLEET_ID")|$(grep -cE "address changed from $SITE_URL317 to http://localhost:$PORT|Adresa webu se změnila z $SITE_URL317 na http://localhost:$PORT" "$WORK/response")" \
  "http://localhost:$PORT|1|warning|$SITE_URL317|http://localhost:$PORT|1"
sq "UPDATE ka_nastaveni SET hodnota = '$SITE_URL317' WHERE promenna = 'site_url'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "N317-5: the address back – one more event, the same address does not record one" "$(sq3 "SELECT url FROM ka_fleet_sites WHERE id = $FLEET_ID")|$(sq3 "SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_url_changed'")" "$SITE_URL317|2"
# the console's jobs run from its cron: the day's state from the stored heartbeat, the alerts by the rules
SMTP_PORT=$((PORT + 3)); mkdir -p "$WORK/smtp317"
command php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp317" > /dev/null 2>&1 & SMTP_PID=$!
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'console@example.cz'), ('tasks_token', 'contoken317')" > /dev/null
# the oldest request of the site waits 30 hours: under the default threshold (48), over the owner's (24)
sq3 "UPDATE ka_fleet_sites SET heartbeat = JSON_SET(heartbeat, '\$.requests', JSON_OBJECT('new', 1, 'in_progress', 0, 'done_to_review', 0, 'oldest_waiting_hours', 30, 'items', JSON_ARRAY(JSON_OBJECT('id', 5, 'title', 'Fleet317 secret request', 'status', 'new')))) WHERE id = $FLEET_ID" > /dev/null
sq3 "DELETE FROM ka_jobs WHERE name LIKE 'fleet\\_%'" > /dev/null
curl -s -o /dev/null "$B3/tasks?token=contoken317"
expect "console cron: the day's row has the state from the heartbeat (version, attention, the site's report counts); with the default rules nothing is e-mailed" \
  "$(sq3 "SELECT CONCAT(version <> '', '|', attention IS NOT NULL, '|', report_summary LIKE '%4321%') FROM ka_fleet_history WHERE site_id = $FLEET_ID AND day = '$(site_time now Y-m-d)'")|$(ls "$WORK"/smtp317/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(sq3 "SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.alert_sent'")" "1|1|1|0|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=alerts"
grep -q 'name="on_site_down" value="1" checked' "$WORK/response" && grep -q 'name="on_site_silent" value="1" checked' "$WORK/response" && ! grep -q 'name="on_requests_waiting" value="1" checked' "$WORK/response" \
  && grep -q 'name="threshold_requests_waiting" value="48"' "$WORK/response" && grep -q 'name="fleet_digest" value="1">' "$WORK/response" \
  && echo "  ok     Alerts and digest: down and silent on by default (as before), the other rules off, the digest off" || { echo "  CHYBA  Alerts and digest defaults"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=alerts_save" -d "_csrf=$(csrf)" -d on_site_down=1 -d on_site_silent=1 -d on_requests_waiting=1 -d threshold_requests_waiting=24 \
  -d threshold_site_silent=26 -d fleet_alert_recipients=owner317@agency.example -d fleet_alert_quiet_hours=24
sq3 "DELETE FROM ka_jobs WHERE name = 'fleet_alerts'" > /dev/null
curl -s -o /dev/null "$B3/tasks?token=contoken317"
F=$(grep -l "^X-Rcpt-To: owner317@agency.example" "$WORK"/smtp317/*.eml 2>/dev/null | tail -1); : > "$WORK/eml.txt"; [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "alert rules: the owner's threshold (24 hours) sends one e-mail with a plain link to the site in the console, the event is recorded, no request title in it" \
  "$(ls "$WORK"/smtp317/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(grep -c "admin.php?module=fleet&action=detail&id=$FLEET_ID" "$WORK/eml.txt")|$(grep -c 'Fleet317 secret request' "$WORK/eml.txt")|$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(data, '\$.kind')))) FROM ka_events WHERE type = 'fleet.alert_sent'")" "1|1|0|1|requests_waiting"
sq3 "DELETE FROM ka_jobs WHERE name = 'fleet_alerts'" > /dev/null
curl -s -o /dev/null "$B3/tasks?token=contoken317"
expect "alert rules: within the quiet hours the same alert for the same site is not sent again" "$(ls "$WORK"/smtp317/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(sq3 "SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.alert_sent'")" "1|1"
# N317-1/-2: a heartbeat stored before 3.17's bounds (any value a modified site sent) – first by name – and a site that is down after it:
# the day's rows, the alerts of the other site, the inbox, the list, the site's page and the digest all go on; its name stays on one line in the e-mail
HOSTILE317='{"audit":{"x":9223372036854775807,"y":[1],"z":-7},"update_problem":["x"],"enquiries_unanswered":-1,"visits_7_days":9223372036854775807,"last_backup":1e300,"requests":{"new":1e300,"items":[{"id":1,"status":["x"]}]},"security_7_days":"x","domain_watch":{"certificate":"x"},"problems":"x","jobs_failing":[["x"]]}'
sq3 "INSERT INTO ka_fleet_sites (name, url, public_key, paired_at, last_seen, version, version_since, manage_updates, up, heartbeat) VALUES
  ('0 Hostile317\\nSIGN IN at http://evil317.test', 'http://hostile317.test', 'hostile317key', '$(site_time)', '$(site_time)', '3.17.0', '$(site_time)', 0, 1, '$HOSTILE317'),
  ('Z Down317', 'http://down317.test', 'down317key', '$(site_time)', '$(site_time)', '3.17.0', '$(site_time)', 0, 0, '{\"last_backup\":$(date +%s),\"cron_last_run\":$(date +%s)}')" > /dev/null
HOSTILE_ID317=$(sq3 "SELECT id FROM ka_fleet_sites WHERE public_key = 'hostile317key'"); DOWN_ID317=$(sq3 "SELECT id FROM ka_fleet_sites WHERE public_key = 'down317key'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=alerts"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=alerts_save" -d "_csrf=$(csrf)" -d on_site_down=1 -d on_site_silent=1 -d on_update_failed=1 -d on_requests_waiting=1 -d threshold_requests_waiting=24 \
  -d threshold_site_silent=26 -d fleet_alert_recipients=owner317@agency.example -d fleet_alert_quiet_hours=24
sq3 "DELETE FROM ka_jobs WHERE name IN ('fleet_history', 'fleet_alerts')" > /dev/null
TASKS317=$(curl -s "$B3/tasks?token=contoken317")
F=$(ls -t "$WORK"/smtp317/*.eml 2>/dev/null | head -1); : > "$WORK/eml.txt"; [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "N317-1: a hostile stored heartbeat stops neither the day's rows (bounded) nor the other site's alert; the jobs report no failure" \
  "$(sq3 "SELECT CONCAT(COUNT(*), '|', COALESCE(MAX(IF(site_id = $HOSTILE_ID317, CONCAT(audit_total, '/', enquiries_new, '/', COALESCE(requests_open, '-')), NULL)), '-')) FROM ka_fleet_history WHERE site_id IN ($HOSTILE_ID317, $DOWN_ID317) AND day = '$(site_time now Y-m-d)'")|$(ls "$WORK"/smtp317/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(grep -c "^Z Down317 – http://down317.test" "$WORK/eml.txt")|$(sq3 "SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.alert_sent' AND JSON_EXTRACT(data, '\$.site') IN ($HOSTILE_ID317, $DOWN_ID317)")|$(grep -oE 'fleet_history: recorded [0-9]+(, failed [0-9]+)?' <<< "$TASKS317")" \
  "2|1000000/0/0|2|1|2|fleet_history: recorded 3"
expect "N317-2: the site's name with a line break is one line in the alert e-mail – no line of its own" "$(grep -c '^SIGN IN' "$WORK/eml.txt")|$(grep -c '^0 Hostile317 SIGN IN at http://evil317.test – http://hostile317.test' "$WORK/eml.txt")" "0|1"
CODES317=""
for page317 in "" "&action=sites&show=all" "&action=detail&id=$HOSTILE_ID317" "&action=digest_preview"; do CODES317="$CODES317$(curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" -w '%{http_code}' "$B3/admin.php?module=fleet$page317")|"; done
expect "N317-1: the inbox, the list of sites, the hostile site's page and the digest preview open" "$CODES317$(grep -c '0 Hostile317' "$WORK/response")" "200|200|200|200|1"
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_inbox","arguments":{}}}' > "$WORK/response"
contains -qF '0 Hostile317 SIGN IN' "$WORK/response" && ! contains -q '"isError":true' "$WORK/response" && echo "  ok     N317-1: MCP fleet_inbox answers with the hostile site in it" || { echo "  CHYBA  N317-1: MCP fleet_inbox"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq3 "DELETE FROM ka_fleet_history WHERE site_id IN ($HOSTILE_ID317, $DOWN_ID317); DELETE FROM ka_fleet_incidents WHERE site_id IN ($HOSTILE_ID317, $DOWN_ID317); DELETE FROM ka_fleet_sites WHERE id IN ($HOSTILE_ID317, $DOWN_ID317)" > /dev/null
# the weekly digest: a preview with the saved settings; request titles stay out unless turned on
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=digest_preview"
grep -qF "$B/admin.php" "$WORK/response" && grep -qF "module=fleet&amp;action=detail&amp;id=$FLEET_ID" "$WORK/response" && ! grep -q 'Fleet317 secret request' "$WORK/response" \
  && echo "  ok     digest preview: the sites with plain links, request titles left out by default" || { echo "  CHYBA  digest preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=alerts"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=alerts_save" -d "_csrf=$(csrf)" -d on_site_down=1 -d fleet_digest=1 -d fleet_digest_titles=1 -d fleet_alert_recipients=owner317@agency.example
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=digest_preview"
expect "digest with titles turned on names the request; the settings are saved" "$(grep -c 'Fleet317 secret request' "$WORK/response")|$(sq3 "SELECT GROUP_CONCAT(hodnota ORDER BY promenna SEPARATOR ',') FROM ka_nastaveni WHERE promenna IN ('fleet_digest', 'fleet_digest_titles')")" "1|1,1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=alerts"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=alerts_save" -d "_csrf=$(csrf)" -d on_site_down=1 -d on_site_silent=1 -d fleet_alert_recipients='not-an-address'
expect "a wrong recipient address saves nothing" "$(sq3 "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_alert_recipients'")" "owner317@agency.example"
# the trends on the site's page: uptime this and last month, 90 days of bars with a summary and a table, the outage
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
# 3.26.1: the bars are Core\UptimeStrip – the e-mail-safe table the site's monthly report uses too
grep -q '<figure class="trend">' "$WORK/response" && grep -q '<table role="img" aria-label="' "$WORK/response" && grep -q 'class="trend-tabulka"' "$WORK/response" \
  && [ "$(sed -n '/<figure class="trend">/,/<\/figure>/p' "$WORK/response" | grep -o '<td title="' | wc -l | tr -d ' ')" = "90" ] && grep -q 'Výpadky za posledních 90 dní\|Outages in the last 90 days' "$WORK/response" \
  && ! grep -q '<svg viewBox="0 0 360 40"' "$WORK/response" \
  && echo "  ok     trends: 90 bars (the shared uptime strip) with a summary for screen readers and the days as a table" || { echo "  CHYBA  trends on the site's page"; ERRORS=$((ERRORS+1)); }
# the monthly fleet report per customer: from the console's own history of the previous month
sq3 "INSERT INTO ka_fleet_history (site_id, day, checks_ok, checks_failed, response_ms_total, response_count, status, version, attention, reasons) VALUES ($FLEET_ID, '$PREV317-10', 286, 2, 57200, 286, 'ok', '3.16.0', 40, 'jobs_failing'), ($FLEET_ID, '$PREV317-11', 288, 0, 57600, 288, 'ok', '3.17.0', 0, '');
  INSERT INTO ka_fleet_incidents (site_id, started_at, ended_at, status) VALUES ($FLEET_ID, '$PREV317-10 10:00:00', '$PREV317-10 10:25:00', 503);
  INSERT INTO ka_events (created_at, type, severity, message, data) VALUES ('$PREV317-11 03:00:00', 'fleet.site_updated', 'info', 'updated', '{\"site\":$FLEET_ID,\"version\":\"3.17.0\"}')" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=report_build" -d "_csrf=$(csrf)"
expect "fleet report: one per customer (here the sites without one) with the uptime, the outage, the update, the attention and the site's own counts; nothing with @" \
  "$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(customer), '|', MAX(JSON_EXTRACT(data, '\$.sites[0].uptime.percent')), '|', MAX(JSON_EXTRACT(data, '\$.sites[0].downtime_minutes')), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(data, '\$.sites[0].updates[0].version'))), '|', MAX(JSON_EXTRACT(data, '\$.sites[0].attention.reasons.jobs_failing')), '|', MAX(JSON_EXTRACT(data, '\$.sites[0].summary.visits')), '|', MAX(LOCATE('@', data))) FROM ka_fleet_reports WHERE month = '$PREV317'")" \
  "1||99.65|25|3.17.0|1|4321|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
grep -qF "action=report_view&amp;month=$PREV317&amp;customer=" "$WORK/response" && echo "  ok     Fleet reports lists the month with view, download and rebuild" || { echo "  CHYBA  Fleet reports list"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -D "$WORK/headers" -o "$WORK/response" "$B3/admin.php?module=fleet&action=report_view&month=$PREV317&customer="
expect "fleet report: the print page – A4, the Print button, the customer, the outage, its own CSP" "$(grep -c 'size: A4' "$WORK/response")|$(grep -c 'data-tisk' "$WORK/response")|$(grep -q 'Weby bez zákazníka\|Sites without a customer' "$WORK/response" && echo 1 || echo 0)|$(grep -ci "^content-security-policy: default-src 'none'" "$WORK/headers")|$(grep -q '4321' "$WORK/response" && echo 1 || echo 0)" "1|1|1|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -D "$WORK/headers" -o "$WORK/response" "$B3/admin.php?module=fleet&action=report_download&month=$PREV317&customer="
expect "fleet report: the download – an attachment, no script" "$(grep -ci "^content-disposition: attachment; filename=\"fleet-report-no-customer-$PREV317.html\"" "$WORK/headers")|$(grep -c '<script' "$WORK/response")" "1|0"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=report_build" -d "_csrf=$(csrf)" -d "month=$PREV317"
expect "fleet report: rebuilt from the history still kept on the administrator's request" "$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(rebuilt_at IS NOT NULL)) FROM ka_fleet_reports WHERE month = '$PREV317'")" "1|1"
sq3 "DELETE FROM ka_jobs WHERE name = 'fleet_reports'" > /dev/null; TASKS317=$(curl -s "$B3/tasks?token=contoken317")
expect "fleet report: the job does not make a stored month again" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_reports")|$(grep -o 'fleet_reports: stored already' <<< "$TASKS317")" "1|fleet_reports: stored already"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
for month317 in 2099-12 "$(site_time now Y-m)" 2020-01; do curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=report_build" -d "_csrf=$(csrf)" -d "month=$month317"; done
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
expect "N317-6: no report of a future month, the current one or one without history – only the month that ended stays" "$(sq3 "SELECT GROUP_CONCAT(DISTINCT month) FROM ka_fleet_reports")|$(grep -q 'that has ended\|který skončil' "$WORK/response" && echo refused || echo silent)" "$PREV317|refused"
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_report","arguments":{}}}' > "$WORK/response"
contains -q "$PREV317" "$WORK/response" && contains -q 'kept_months' "$WORK/response" && echo "  ok     MCP fleet_report lists the stored months and customers" || { echo "  CHYBA  MCP fleet_report list"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"fleet_report\",\"arguments\":{\"month\":\"$PREV317\",\"customer\":\"\"}}}" > "$WORK/response"
contains -q 'print_url' "$WORK/response" && contains -q 'downtime_minutes' "$WORK/response" && contains -q '4321' "$WORK/response" && echo "  ok     MCP fleet_report gives one customer's month with its print address" || { echo "  CHYBA  MCP fleet_report month"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"get_site\",\"arguments\":{\"id\":$FLEET_ID}}}" > "$WORK/response"
contains -q 'uptime_this_month' "$WORK/response" && contains -q 'outages_90_days' "$WORK/response" && echo "  ok     MCP get_site carries the console's trends of the site" || { echo "  CHYBA  MCP get_site trends"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp fleet_report '{}' > "$WORK/response"; contains -q 'kept_months' "$WORK/response" && { echo "  CHYBA  fleet_report works on a site that is not a console"; ERRORS=$((ERRORS+1)); } || echo "  ok     fleet_report exists only on a console"
kill "$SMTP_PID" 2>/dev/null || true
sq "DELETE FROM ka_monthly_reports WHERE month = '$PREV317' AND data LIKE '%Secret title 317%'" > /dev/null
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
