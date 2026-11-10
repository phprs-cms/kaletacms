echo "== 2.13: Search Console and Bing data in Statistics (Core\\SearchData)"
connect_fake google
check "search: a connected Google offers to load the Search Console properties; Bing is listed with its key field" 200 "/admin.php?module=connectors" "action=properties"
grep -q 'Bing Webmaster Tools' "$WORK/response" && grep -q 'name="config\[search_console_site\]"' "$WORK/response" && echo "  ok     search: the property setting and the Bing service are on the screen" || { echo "  CHYBA  connections screen: search settings"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=properties" -d "_csrf=$(csrf)"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
grep -q 'name="site" value="sc-domain:example.com"' "$WORK/response" && grep -q 'name="site" value="https://example.com/"' "$WORK/response" && echo "  ok     search: the account's properties are offered once" || { echo "  CHYBA  Load my properties"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=property" -d "_csrf=$(csrf)" -d "site=sc-domain:example.com"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=property" -d "_csrf=$(csrf)" -d "site=javascript:alert(1)"
expect "search: the chosen property is kept in the connection's settings, a made-up one is refused" "$(sq "SELECT config FROM ka_connectors WHERE service = 'google'")" '{"search_console_site":"sc-domain:example.com"}'
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
! grep -q 'name="site" value=' "$WORK/response" && echo "  ok     search: the loaded list is shown only once" || { echo "  CHYBA  properties shown again"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=bing -d client_id= -d account= --data-urlencode secret=bing-test-key --data-urlencode "config[site_url]=https://example.com/"
expect "search: Bing is connected with its key stored encrypted and its site in the settings" "$(sq "SELECT CONCAT(connected_at IS NOT NULL, '|', secret LIKE '%bing-test-key%', '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.site_url'))) FROM ka_connectors WHERE service = 'bing'")" '1|0|https://example.com/'
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'search_data'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'search_data: google 5, bing 2' "$WORK/tasks.txt" && echo "  ok     search: the daily job loaded 2 queries, 2 pages and a sitemap from Google and 1 query and 1 page from Bing" || { echo "  CHYBA  search_data job"; grep search_data "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "search: the snapshot of today – Google's CTR in per cent, Bing's days summed with the position weighted by impressions, the stale day dropped, the sitemap counts" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(engine, ':', kind, ':', \`key\`, ':', clicks, ':', impressions, ':', ctr, ':', position) ORDER BY engine, kind, clicks DESC SEPARATOR ' ') FROM ka_search_stats WHERE day = '$(site_date today)'")" \
  "bing:page:https://example.com/kontakt:4:50:8.00:5.0 bing:query:kaleta bing:6:120:5.00:5.0 google:page:https://example.com/sluzby:31:640:4.84:6.2 google:page:https://example.com/:12:200:6.00:2.1 google:query:kaleta cms:42:900:4.67:3.4 google:query:firemní web zdarma:7:310:2.26:11.8 google:sitemap:https://example.com/sitemap.xml:10:15:66.67:0.0"
grep -q '"site":"sc-domain:example.com","dimension":"query"' "$FAKE_LOGS-search.log" && grep -q '"limit":250' "$FAKE_LOGS-search.log" && grep -q '"bing":"GetPageStats","site":"https://example.com/","has_key":true' "$FAKE_LOGS-search.log" \
  && echo "  ok     search: Google was asked for the chosen property with 250 rows per dimension, Bing for the registered site with the key" || { echo "  CHYBA  what the fake services were asked"; cat "$FAKE_LOGS-search.log"; ERRORS=$((ERRORS+1)); }
expect "search: the calls are logged by their action, and the Bing key is in no log row" "$(sq "SELECT CONCAT(GROUP_CONCAT(DISTINCT action ORDER BY action), '|', SUM(action LIKE '%bing-test-key%' OR error LIKE '%bing-test-key%')) FROM ka_connector_log WHERE action LIKE 'search.%'")" "search.page,search.query,search.sitemaps,search.sites|0"
check "search: Statistics show the queries and pages of both engines with the sitemap coverage" 200 "/admin.php?module=stats&days=7" "kaleta cms"
grep -q '<td>kaleta bing</td><td class="cislo">6</td><td class="cislo">120</td><td class="cislo">5,0 %</td><td class="cislo">5,0</td>' "$WORK/response" && grep -q 'href="https://example.com/sluzby"' "$WORK/response" && grep -q '<td>https://example.com/sitemap.xml</td><td class="cislo">15</td><td class="cislo">10</td>' "$WORK/response" \
  && grep -q 'Nejčastější dotazy (Google)' "$WORK/response" && ! grep -q 'bing-test-key' "$WORK/response" && echo "  ok     search: the Statistics tables and never the Bing key" || { echo "  CHYBA  Statistics: search section"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"; ! grep -q 'bing-test-key' "$WORK/response" && echo "  ok     search: the Bing key is not on the Connections screen" || { echo "  CHYBA  Bing key on the page"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"search":{"google":{"day":"'"$(site_date today)"'","covers_days":28,"queries":\[{"query":"kaleta cms","clicks":42,"impressions":900,"ctr":4.67,"position":3.4}' "$WORK/text" && contains -q '"sitemaps":\[{"path":"https://example.com/sitemap.xml","submitted":15,"indexed":10}\]' "$WORK/text" \
  && contains -q '"bing":{"day":"'"$(site_date today)"'","covers_days":28,"queries":\[{"query":"kaleta bing","clicks":6' "$WORK/text" && ! contains -q 'bing-test-key' "$WORK/text" && echo "  ok     search: get_stats carries search.google and search.bing" || { echo "  CHYBA  get_stats search"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp list_connectors '{}' > "$WORK/response"; mcp_text
contains -q '"service":"bing","name":"Bing Webmaster Tools","auth":"token","connected":true' "$WORK/text" && ! contains -q 'bing-test-key' "$WORK/text" && echo "  ok     search: Claude sees Bing connected, never the key" || { echo "  CHYBA  list_connectors bing"; ERRORS=$((ERRORS+1)); }
# the monthly report names the top queries of the month's last snapshot
sq "INSERT INTO ka_search_stats (day, engine, kind, \`key\`, clicks, impressions, ctr, position) VALUES ('$(php -r 'echo (new DateTimeImmutable("last day of last month"))->format("Y-m-d");')', 'google', 'query', 'kaleta minulý měsíc', 15, 300, 5, 4.0)" > /dev/null
sq "DELETE FROM ka_monthly_reports" > /dev/null # 3.16: built again with the snapshot above
check "search: the monthly report mentions the top queries when the month has a snapshot" 200 "/admin.php?module=settings&action=report_preview" "kaleta minulý měsíc na Google"
grep -q 'Hledání, která přivedla návštěvníky' "$WORK/response" && ! grep -q 'kaleta bing' "$WORK/response" && echo "  ok     search: only the queries of that month" || { echo "  CHYBA  monthly report: searches"; ERRORS=$((ERRORS+1)); }
# a wrong key: the job reports it on the connection and does not throw while Google still works; the day's earlier Bing snapshot stays
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=bing -d client_id= -d account= --data-urlencode secret=wrong-key --data-urlencode "config[site_url]=https://example.com/"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'search_data'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "search: a refused Bing key is reported on the connection, Google's snapshot still arrives, the job does not fail" "$(grep -q 'search_data: google 5, bing: HTTP 401' "$WORK/tasks.txt" && echo job-ran)|$(sq "SELECT CONCAT(last_error LIKE '%401%', '|', (SELECT COUNT(*) FROM ka_search_stats WHERE engine = 'bing' AND day = '$(site_date today)'), '|', (SELECT failures FROM ka_jobs WHERE name = 'search_data')) FROM ka_connectors WHERE service = 'bing'")" "job-ran|1|2|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"; ! grep -q 'wrong-key' "$WORK/response" && grep -q 'HTTP 401' "$WORK/response" && echo "  ok     search: Connections shows the refused key as the connection's error, never the key" || { echo "  CHYBA  Connections: Bing error"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=bing
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=google
expect "search: both engines disconnected again, the stored key gone" "$(sq "SELECT GROUP_CONCAT(CONCAT(service, ':', connected_at IS NULL, ':', secret IS NULL) ORDER BY service) FROM ka_connectors")" "bing:1:1,google:1:0"
