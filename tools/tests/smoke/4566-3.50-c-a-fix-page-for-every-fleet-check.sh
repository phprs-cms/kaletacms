echo "== 3.50 C: Fleet – a fix page for every check (CPO 11), \"no report\" is accepted for a week (N147)"
# the main site is a console for this section (as 3000): three sites stored straight in it
C366_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +fleet
c366_beat() { php -r 'echo json_encode(["version" => "3.49.0", "last_backup" => time() - (int) $argv[1], "cron_last_run" => time() - 600, "auto_updates" => true]);' -- "$1"; }
sq "INSERT INTO ka_fleet_sites (name, url, public_key, paired_at, last_seen, version, version_since, manage_updates, up, up_status, up_changed, heartbeat) VALUES
  ('C366 Fresh', 'http://fresh366c.test', 'fresh366ckey', '$(site_time)', '$(site_time '-10 minutes')', '3.49.0', '$(site_time)', 1, 1, 200, NULL, '$(c366_beat 3600)'),
  ('C366 Old', 'http://old366c.test', 'old366ckey', '$(site_time)', '$(site_time '-10 minutes')', '3.49.0', '$(site_time)', 1, 1, 200, NULL, '$(c366_beat 2000000)'),
  ('C366 Silent', 'http://silent366c.test', 'silent366ckey', '$(site_time)', '$(site_time '-3 days')', '3.49.0', '$(site_time)', 1, 1, 200, NULL, '$(c366_beat 3600)')" > /dev/null
C366_FRESH=$(sq "SELECT id FROM ka_fleet_sites WHERE public_key = 'fresh366ckey'"); C366_OLD=$(sq "SELECT id FROM ka_fleet_sites WHERE public_key = 'old366ckey'")
C366_SILENT=$(sq "SELECT id FROM ka_fleet_sites WHERE public_key = 'silent366ckey'")

curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=fleet&action=fix&check=local_backup" > "$WORK/code366.txt"
expect "3.50 C (CPO 11): the fix page of Local backup – what it checks and how to fix it, the old site to fix with a plain link to its own screen, the fresh one ticked, Sites the current tab" \
  "$(cat "$WORK/code366.txt")|$(grep -c 'data-fleet-fix="local_backup"' "$WORK/response")|$(grep -c "data-fleet-fix-site=\"$C366_OLD\"" "$WORK/response")|$(grep -A6 "data-fleet-fix-site=\"$C366_OLD\"" "$WORK/response" | grep -c 'href="http://old366c.test/admin.php?module=settings&amp;tab=backups" target="_blank" rel="noopener"')|$(grep -c "data-fleet-fix-site=\"$C366_FRESH\" data-fleet-fix-done" "$WORK/response")|$(grep -A6 "data-fleet-fix-site=\"$C366_FRESH\"" "$WORK/response" | grep -c 'http://fresh366c.test/admin')|$(grep -c 'module=fleet&amp;action=sites" class="aktivni" aria-current="true"' "$WORK/response")" \
  "200|1|1|1|1|0|1"
expect "3.50 C (CPO 11): an unknown check goes back to the health matrix; the matrix headings and Today's groups link to the fix pages" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}|%{redirect_url}' "$B/admin.php?module=fleet&action=fix&check=nonsense" | sed 's#http://[^/]*##')|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=fleet&action=health" | grep -c 'data-fleet-fix-link=')|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=fleet" | grep -c 'data-fleet-fix-link="local_backup"')" \
  "302|/admin.php?module=fleet&action=health|18|1"

# N147: "no report" accepted with a last day a month away holds a week only; a security release ends an accepted failed update
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=fleet&action=accept" -d "_csrf=$(csrf)" -d "id=$C366_SILENT" -d check=reporting --data-urlencode "note=Moving C366" -d "until=$(site_date '+30 days')"
expect "3.50 C (N147): an accepted \"no report\" ends a week after it was accepted, whatever day the form gave" \
  "$(sq "SELECT CONCAT(check_id, '|', ends_on = '$(site_date '+7 days')') FROM ka_fleet_acceptances WHERE site_id = $C366_SILENT")" "reporting|1"
sq "DELETE FROM ka_fleet_acceptances WHERE site_id IN ($C366_FRESH, $C366_OLD, $C366_SILENT); DELETE FROM ka_fleet_sites WHERE id IN ($C366_FRESH, $C366_OLD, $C366_SILENT)" > /dev/null
features --exact "$C366_EXT"
