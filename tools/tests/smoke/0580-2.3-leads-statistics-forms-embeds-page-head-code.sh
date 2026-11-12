echo "== 2.3: leads, statistics, forms, embeds, page head code, accessibility audit"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'"
curl -s -o "$WORK/response" -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' "$B/?utm_source=facebook&utm_medium=paid&utm_campaign=autumn"
hit_from "$WORK/response" 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' --data-urlencode 'query=?utm_source=facebook&utm_medium=paid&utm_campaign=autumn' > /dev/null
expect "a visit from a campaign on a phone counts in the statistics" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COALESCE((SELECT SUM(navstevy) FROM ka_stat_kampane WHERE kampan = 'facebook / paid / autumn'), 0), '|', COALESCE((SELECT SUM(navstevy) FROM ka_stat_zarizeni WHERE zarizeni = 'phone'), 0) > 0)")" "1|1"
# a form with ticked options and a hidden value, an embed and code in the head of one page
mcp vytvor_stranku '{"titulek":"Leads 23","zobrazit":true}' > "$WORK/response"; mcp_text; PAGE23=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":$PAGE23,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka 23\",\"pole\":[{\"popisek\":\"Sluzby\",\"typ\":\"zaskrtnuti\",\"povinne\":true,\"moznosti_zaskrtnuti\":\"Kuchyne\\nKoupelna\"},{\"popisek\":\"Produkt\",\"typ\":\"skryte\",\"hodnota\":\"Dubovy stul\"},{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true}]}},{\"typ\":\"vlozeni\",\"obsah\":{\"adresa\":\"https://calendly.com/acme/consultation\",\"titulek\":\"Book a consultation\"}},{\"typ\":\"vlozeni\",\"obsah\":{\"adresa\":\"https://evil.example/x\"}}]}]}}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET kod_hlavicky = '<meta name=\"kaleta-test\" content=\"23\">' WHERE ids = $PAGE23" # set in the administration, never through MCP (2.5.1)
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'type="checkbox" name="p0\[\]" value="Kuchyne"' "$WORK/formular.html" && ! grep -q 'Dubovy stul' "$WORK/formular.html" && echo "  ok     ticked options on the page, the hidden value not" || { echo "  CHYBA  checkboxes / hidden field"; ERRORS=$((ERRORS+1)); }
grep -q 'data-vlozit="https://calendly.com/acme/consultation?embed_type=Inline&amp;hide_gdpr_banner=1"' "$WORK/formular.html" && ! grep -q 'evil.example' "$WORK/formular.html" && echo "  ok     Embed: a known service after a click, anything else not at all" || { echo "  CHYBA  Embed"; ERRORS=$((ERRORS+1)); }
grep -q '<meta name="kaleta-test" content="23">' "$WORK/formular.html" && ! curl -s "$B/" | contains 'kaleta-test' && echo "  ok     code in the head of one page only" || { echo "  CHYBA  page head code"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$PAGE23,\"head_code\":\"<script>x()</script>\"}" > "$WORK/response"
contains -q 'only in the administration' "$WORK/response" && echo "  ok     MCP cannot set head code, not even with full access (2.5.1)" || { echo "  CHYBA  MCP: head code"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"head_code":"<script>x()</script>","marketing_code":"<script>y()</script>"}}' > "$WORK/response"
contains -q 'set only in the administration' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('head_code','marketing_code') AND hodnota LIKE '%<script>%'")" = 0 ] \
  && echo "  ok     MCP cannot set code for the whole site (2.5.1)" || { echo "  CHYBA  MCP: site code"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
curl -s -o /dev/null -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Koupelna' --data-urlencode p2=petr@example.cz \
  -d ka_vstup=/sluzby --data-urlencode "ka_kampan=utm_source=google&utm_medium=cpc&utm_campaign=kuchyne" -d ka_odkud=google.com
expect "an enquiry carries the first page, the campaign and the referring site of the visit" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(vstup, '|', odkud, '|', kampan) FROM ka_poptavky WHERE email = 'petr@example.cz'")" "/sluzby|google.com|utm_source=google&utm_medium=cpc&utm_campaign=kuchyne"
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"campaign":"google / cpc / kuchyne"' "$WORK/text" && contains -q '"device":"phone"' "$WORK/text" && contains -q '"path":"/sluzby"' "$WORK/text" && echo "  ok     get_stats: campaigns, devices and the first pages of leads" || { echo "  CHYBA  get_stats"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "Statistics: pages, campaigns and first pages that bring leads" 200 "/admin.php?module=stats&days=7" "google / cpc / kuchyne"
# 2.8: real-user speed – the beacon script only with the statistics on and only for visitors, one beacon per page view, the table in Statistics, get_stats and the audit
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/sluzby"
grep -q '<script src="/image/vitals.js?v=[^"]*" defer data-vitals="/vitals" data-hit="/hit" data-hit-page="/sluzby" data-hit-key="[0-9a-f]\{32\}"></script>' "$WORK/response" && ! grep -q 'blocking="render" data-vitals' "$WORK/response" && echo "  ok     2.8: the speed beacon script loads deferred with the statistics on (3.29: with the signed page for the page view)" || { echo "  CHYBA  vitals.js on the page"; grep -o '<script src="/image/vitals[^>]*>' "$WORK/response"; ERRORS=$((ERRORS+1)); }
hit_from "$WORK/response" > /dev/null # the browser confirms the page view first – the speed beacon is taken only for a page the statistics have seen
curl -s -b "$JAR" -o "$WORK/response" "$B/sluzby"; ! grep -q 'vitals.js' "$WORK/response" && echo "  ok     2.8: no speed beacon for signed-in users" || { echo "  CHYBA  vitals.js for a signed-in user"; ERRORS=$((ERRORS+1)); }
# N329-3: a draft shown through a shared preview link (?build=koncept&preview_key) is no visit – the client reviewing it reloads it often
code=$(curl -s -o "$WORK/response" -w '%{http_code}' "$SHARED_LINK"); [ "$code" = 200 ] && grep -q 'o-nas' "$WORK/response" && ! grep -q 'vitals.js' "$WORK/response" && echo "  ok     3.29: no page-view beacon on a shared preview of a draft" || { echo "  CHYBA  beacon on a shared draft preview ($code)"; grep -o '<script src="/image/vitals[^>]*>' "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "2.8: a beacon answers 204" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=1800 -d cls=0.05 -d inp=120)" 204
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/neexistuje-vitals -d lcp=1800   # a page the statistics never saw
curl -s -o /dev/null -X POST "$B/vitals" -d path=/sluzby -d lcp=1800                                  # curl's own user agent = a bot
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=999999 -d cls=abc # out of range, not numeric
expect "2.8: the beacon lands in histogram buckets per metric; made-up pages, bots and nonsense do not" "$(sq "SELECT GROUP_CONCAT(CONCAT(path, ':', metric, ':', bucket, ':', samples) ORDER BY metric) FROM ka_web_vitals")" "/sluzby:cls:2:1,/sluzby:inp:2:1,/sluzby:lcp:3:1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=stats&days=7"
grep -q 'href="/sluzby"' "$WORK/response" && grep -qE '2[.,]0 s <span class="stitek stitek-vydano">' "$WORK/response" && grep -qE '150 ms <span class="stitek stitek-vydano">' "$WORK/response" && echo "  ok     2.8: Statistics show p75 LCP, CLS and INP per page with the rating" || { echo "  CHYBA  Statistics: real-user speed"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"web_vitals":\[{"path":"/sluzby","samples":1,"lcp_p75":2000' "$WORK/text" && contains -q '"lcp_rating":"good"' "$WORK/text" && contains -q '"cls_p75":0.05' "$WORK/text" && contains -q '"inp_p75":150' "$WORK/text" && echo "  ok     2.8: get_stats carries web_vitals" || { echo "  CHYBA  get_stats web_vitals"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# 3.2: the old setting still works over MCP and switches the Statistics feature
mcp update_settings '{"settings":{"stats":"0"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.2: update_settings stats=0 switches the Statistics feature off" "$(sq "SELECT FIND_IN_SET('statistika', hodnota) FROM ka_nastaveni WHERE promenna = 'extensions'")" 0
curl -s -o "$WORK/response" "$B/sluzby"; ! grep -q 'vitals' "$WORK/response" && echo "  ok     2.8: statistics off – no beacon script on the page" || { echo "  CHYBA  vitals.js with the statistics off"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=1800
expect "2.8: statistics off – a beacon is not counted" "$(sq "SELECT SUM(samples) FROM ka_web_vitals")" 3
mcp update_settings '{"settings":{"stats":true}}' > "$WORK/response"; mcp_text; rm -f "$WORK"/web/storage/cache/stranky/*.html
contains -q '"stats":"1"' "$WORK/text" && expect "3.2: update_settings stats=true switches the Statistics feature on again, once" "$(sq "SELECT (LENGTH(hodnota) - LENGTH(REPLACE(hodnota, 'statistika', ''))) DIV LENGTH('statistika') FROM ka_nastaveni WHERE promenna = 'extensions'")" 1 \
  || { echo "  CHYBA  update_settings stats"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the audit: a page whose p75 LCP went from 2.0 s (30 measurements 35 days ago) to 3.0 s (30 measurements today) is flagged, /sluzby with one measurement is not
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_web_vitals (day, path, metric, bucket, samples) VALUES ('$(site_time today Y-m-d)' - INTERVAL 35 DAY, '/audit-pomalu', 'lcp', 3, 30), ('$(site_time today Y-m-d)', '/audit-pomalu', 'lcp', 5, 30)"
mcp site_audit '{"kind":"speed"}' > "$WORK/response"; mcp_text
contains -q '"path":"/audit-pomalu"' "$WORK/text" && contains -qE '3[.,]0 s' "$WORK/text" && ! contains -q '/sluzby' "$WORK/text" && echo "  ok     2.8: the site audit flags a page whose p75 LCP got worse by more than 25 %" || { echo "  CHYBA  speed audit"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_web_vitals WHERE path = '/audit-pomalu'"
# 3.29: visits confirmed by a browser – a page GET (rendered or from the page cache) counts nothing; the beacon POST /hit does,
# for a person only: never a bot, a prefetch, another site, a forged page or a signed-in user; a flood stops adding views
traffic() { sq "SELECT CONCAT(COALESCE(SUM(navstevy), 0), ' ', COALESCE(SUM(zobrazeni), 0)) FROM ka_stat_dny WHERE den = '$(site_date today)'"; }
delta() { local a b; read -r a b <<<"$1"; local c d; read -r c d <<<"$(traffic)"; echo "$((c - a))/$((d - b))"; }
rm -f "$WORK"/web/storage/cache/stranky/*.html
T0=$(traffic); curl -s -o "$WORK/hit329.html" -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' "$B/sluzby"
expect "3.29: a page GET alone no longer counts a visit or a page view" "$(delta "$T0")" "0/0"
UA329='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 k329-a'
T0=$(traffic); C1=$(hit_from "$WORK/hit329.html" "$UA329" -H "Origin: $B" -H 'Sec-Fetch-Site: same-origin'); C2=$(hit_from "$WORK/hit329.html" "$UA329")
expect "3.29: the browser's beacon answers 204 and counts; the same visitor twice is 1 visit and 2 page views" "$C1|$C2|$(delta "$T0")" "204|204|1/2"
expect "3.29: the first counted beacon records the day browser-confirmed counting started" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'stats_confirmed_since'")" "$(site_date today)"
T0=$(traffic)
hit_from "$WORK/hit329.html" 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' > /dev/null
hit_from "$WORK/hit329.html" 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' > /dev/null
hit_from "$WORK/hit329.html" 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0.0.0 Safari/537.36' > /dev/null
hit_from "$WORK/hit329.html" "$UA329 b" -H 'Sec-Purpose: prefetch;prerender' > /dev/null
hit_from "$WORK/hit329.html" "$UA329 c" -H 'Purpose: prefetch' > /dev/null
hit_from "$WORK/hit329.html" "$UA329 d" -H 'Origin: https://evil.example' > /dev/null
hit_from "$WORK/hit329.html" "$UA329 e" -H 'Sec-Fetch-Site: cross-site' > /dev/null
hit_from "$WORK/hit329.html" "$UA329 f" -b "$JAR" > /dev/null                                                                          # signed in – the team's own visit
curl -s -o /dev/null -X POST "$B/hit" -A "$UA329 g" --data-urlencode 'path=/neexistuje-329' -d "key=$(hit_attr "$WORK/hit329.html" key)"   # a made-up page with another page's key
curl -s -o /dev/null -X POST "$B/hit" -A "$UA329 h" --data-urlencode "path=$(hit_attr "$WORK/hit329.html" page)" -d key=0123456789abcdef0123456789abcdef
curl -s -o /dev/null -X POST "$B/hit" -A "$UA329 i" --data-urlencode "path=$(hit_attr "$WORK/hit329.html" page)" -d "key=$(hit_attr "$WORK/hit329.html" key)" -d news=1 # a news id the page did not sign
expect "3.29: bots and link previews, a prefetch, another site, a cross-site fetch, a signed-in user, a made-up page, a wrong key or a news id the page did not carry count nothing" "$(delta "$T0")" "0/0"
expect "3.29: a forged page leaves no row behind" "$(sq "SELECT COUNT(*) FROM ka_stat_stranky WHERE cesta = '/neexistuje-329'")" 0
# the full-page cache: the cached copy counts nothing by itself and carries the same beacon, which counts once
T0=$(traffic); curl -s -D "$WORK/hit329.head" -o "$WORK/hit329-cached.html" -A "$UA329 j" "$B/sluzby"
expect "3.29: a page from the full-page cache counts nothing by itself, its beacon counts once" "$(grep -ci '^x-cache: kaleta' "$WORK/hit329.head")|$(delta "$T0")|$(hit_from "$WORK/hit329-cached.html" "$UA329 j")|$(delta "$T0")" "1|0/0|204|1/1"
# a news item: its reads go with the confirmed page view, never with the render
# (a published, indexable news item of the default language that is there – the starter's welcome may have moved by now)
NEWS329=$(sq "SELECT idc FROM ka_novinky WHERE visible = 1 AND noindex = 0 AND smazano IS NULL AND jazyk = '' AND datum <= '$(site_time)' ORDER BY idc LIMIT 1")
NEWS329_SLUG=$(sq "SELECT seo_link FROM ka_novinky WHERE idc = ${NEWS329:-0}")
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -L -o "$WORK/hit329-news.html" -A "$UA329 k" "$B/novinky/$NEWS329_SLUG"
NEWS329_RENDER=$(sq "SELECT visit FROM ka_novinky WHERE idc = ${NEWS329:-0}"); hit_from "$WORK/hit329-news.html" "$UA329 k" > /dev/null
expect "3.29: a news item's page signs its id; its reads count with the beacon, not with the render" \
  "$(hit_attr "$WORK/hit329-news.html" news)|$NEWS329_RENDER|$(sq "SELECT visit FROM ka_novinky WHERE idc = ${NEWS329:-0}")|$(sq "SELECT COALESCE(SUM(pocet), 0) FROM ka_stat_novinky WHERE idc = ${NEWS329:-0}")" "$NEWS329|0|1|1"
# the visit's referring site, device and campaign come from the beacon (the page's own address as the browser shows it)
hit_from "$WORK/hit329.html" 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1 k329' \
  --data-urlencode 'referrer=https://www.seznam.cz/hledani?q=kaleta' --data-urlencode 'query=?utm_source=newsletter&utm_medium=email&utm_campaign=k329' > /dev/null
expect "3.29: the beacon carries the referring site and the campaign of the visit" "$(sq "SELECT CONCAT(COALESCE((SELECT SUM(pocet) FROM ka_stat_zdroje WHERE zdroj = 'seznam.cz' AND den = '$(site_date today)'), 0), '|', COALESCE((SELECT SUM(navstevy) FROM ka_stat_kampane WHERE kampan = 'newsletter / email / k329'), 0))")" "1|1"
# the flood guard: ten views within ten seconds are a machine – the tenth and everything after it that day add nothing
T0=$(traffic); for _ in 1 2 3 4 5 6 7 8 9 10 11 12; do hit_from "$WORK/hit329.html" "$UA329 flood" > /dev/null; done
expect "3.29: a visitor firing views within seconds stops adding them (1 visit, 9 views) and is held at the daily cap" "$(delta "$T0")|$(sq "SELECT COUNT(*) FROM ka_stat_navstevnici WHERE den = '$(site_date today)' AND views = 300")" "1/9|1"
# the honest history: with older numbers the Statistics screen says from when only browser-confirmed visits count, and get_stats too
sq "INSERT IGNORE INTO ka_stat_dny (den, navstevy, zobrazeni) VALUES ('$(site_date '-3 days')', 0, 0)" > /dev/null
check "3.29: Statistics say from when only visits confirmed by a browser count and mark the day" 200 "/admin.php?module=stats&days=7" 'data-stats-confirmed'
grep -q 'class="graf-sloupec graf-zacatek"' "$WORK/response" && echo "  ok     3.29: the first day of browser-confirmed counting is marked on the chart" || { echo "  CHYBA  3.29: the marked day on the chart"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"counting":"browser_confirmed"' "$WORK/text" && contains -q "\"confirmed_since\":\"$(site_date today)\"" "$WORK/text" && echo "  ok     3.29: get_stats says the numbers are browser-confirmed and since when (additive keys)" || { echo "  CHYBA  3.29: get_stats counting"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# 3.29.1: say why the visits dropped – the dashboard chart marks the day and says it; get_stats and get_monthly_report say a drop
# across the switch is fewer bots, not fewer people (additive keys); the monthly report shows no down arrow for it
contains -q '"counting_note":"Counting changed on' "$WORK/text" && echo "  ok     3.29.1: get_stats says a drop across the switch is fewer bots, not fewer people (counting_note)" || { echo "  CHYBA  3.29.1: get_stats counting_note"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "3.29.1: the dashboard chart says from when only visits confirmed by a browser count" 200 "/admin.php" 'data-stats-confirmed>'
expect "3.29.1: … and marks that day on the chart, with the day's label saying it" \
  "$(grep -o "class=\"prehled-zacatek\"[^>]*data-stats-confirmed-day=\"$(site_date today)\"" "$WORK/response" | wc -l | tr -d ' ')|$(grep -cE 'only visits confirmed by a browser count from this day|od tohoto dne se počítají jen návštěvy potvrzené prohlížečem' "$WORK/response")" "1|1"
# 3.29.1 (owner): after a sign-in only Content is open in the menu – the layout marks Content and names the sign-in on the menu script
expect "3.29.1: the menu marks Content as the group open after a sign-in and tells the script which sign-in this page belongs to" \
  "$(grep -o 'data-menu-skupina="[a-z-]*"[^>]*data-menu-vychozi' "$WORK/response" | sed 's/data-menu-skupina="\([a-z-]*\)".*/\1/' | tr '\n' ' ')|$(grep -cE 'admin-menu\.js\?v=[^"]*" data-menu-prihlaseni="[1-9][0-9]{9}"' "$WORK/response")" "content |1"
M3291=$(site_time '-2 months' Y-m)
sq "REPLACE INTO ka_monthly_reports (month, created_at, schema_version, data) VALUES ('$M3291', '$(site_time)', 1, '{\"month\":\"$M3291\",\"stats\":{\"visits\":600,\"views\":900,\"previous_visits\":1000,\"previous_views\":1500,\"confirmed_since\":\"$M3291-10\"}}')" > /dev/null
mcp get_monthly_report "{\"month\":\"$M3291\"}" > "$WORK/response"; mcp_text
contains -q "\"counting_changed\":{\"on\":\"$M3291-10\"" "$WORK/text" && echo "  ok     3.29.1: get_monthly_report says the month reaches across the switch (counting_changed, additive)" || { echo "  CHYBA  3.29.1: get_monthly_report counting_changed"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "3.29.1: the printed monthly report shows the drop across the switch without a down arrow" 200 "/admin.php?module=settings&action=report_view&month=$M3291"
expect "3.29.1: … the visits tile and rows name the switch, no ▼ and no minus" "$(grep -cE 'counting changed on|počítání se změnilo' "$WORK/response" | tr -d ' ' | sed 's/^[1-9][0-9]*$/yes/')|$(grep -c '▼' "$WORK/response")|$(grep -cE '\(−40 %\)' "$WORK/response")" "yes|0|0"
sq "DELETE FROM ka_monthly_reports WHERE month = '$M3291'" > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p2=tick@example.cz)" in *result=pole\&field=0*) echo "  ok     a required group needs at least one ticked option";; *) echo "  CHYBA  required checkbox group"; ERRORS=$((ERRORS+1));; esac
curl -s -o /dev/null -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Kuchyne' -d 'p0[]=Podvrh' -d p1=Hacked --data-urlencode p2=tick@example.cz
expect "ticked options (only offered ones) and the form's own hidden value are saved" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT data FROM ka_poptavky WHERE email = 'tick@example.cz'")" '[["Sluzby","Kuchyne"],["Produkt","Dubovy stul"],["Email","tick@example.cz"]]'
# accessibility in the site audit
mcp vytvor_stranku '{"titulek":"Access 23","zobrazit":true,"text":"<p>Prices: <a href=\"/sluzby\">click here</a>.</p><table><tr><td>1</td></tr></table>"}' > /dev/null
mcp site_audit '{"kind":"accessibility"}' > "$WORK/response"; mcp_text
contains -q 'click here' "$WORK/text" && contains -q 'header cells' "$WORK/text" && contains -q 'accessibility statement' "$WORK/text" && echo "  ok     site audit: link texts, tables and the accessibility statement" || { echo "  CHYBA  accessibility audit"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# 2.4 for agencies: a ready-made role, whom to ask for help, the check before handing the site over
check "2.4: ready-made Client role fills the form" 200 "/admin.php?module=roles&action=new&preset=client" 'name="nazev" value="Klient"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; mcp_text
contains -qE '"handover": ?"agency"' "$WORK/text" && contains -qE '"handover": ?"smtp"' "$WORK/text" && echo "  ok     hand-over check: agency contact and SMTP missing" || { echo "  CHYBA  hand-over audit"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"agency_name":"Studio Test","agency_email":"help@studio.example","agency_phone":"+420 777 123 456"}}' > /dev/null
curl -s -o "$WORK/response" "$B/admin.php"
grep -q 'Studio Test' "$WORK/response" && grep -q 'mailto:help@studio.example' "$WORK/response" && grep -q 'tel:+420777123456' "$WORK/response" && echo "  ok     sign-in screen shows whom to ask for help" || { echo "  CHYBA  agency contact on the sign-in screen"; ERRORS=$((ERRORS+1)); }
check "2.4: admin footer shows the agency" 200 "/admin.php?module=pages" 'class="agentura"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; mcp_text
contains -qE '"handover": ?"agency"' "$WORK/text" && { echo "  CHYBA  hand-over audit still misses the agency contact"; ERRORS=$((ERRORS+1)); } || echo "  ok     hand-over check: the agency contact is set"
# the cookie bar: remembering leads needs consent to marketing; Global Privacy Control counts as "only necessary"
mcp update_settings '{"settings":{"cookies_mode":"vestavena","lead_attribution":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q 'data-kategorie="marketing"' "$WORK/response" && grep -q 'ka-puvod' "$WORK/response" && grep -q 'globalPrivacyControl' "$WORK/response" && grep -q 'name="ka_vstup"' "$WORK/response" && echo "  ok     cookie bar: marketing consent for lead origins, Global Privacy Control" || { echo "  CHYBA  cookie bar with lead attribution"; ERRORS=$((ERRORS+1)); }
# 3.5 (UXP-07, UXP-02): the policy link says where it leads (Lighthouse link-text); the bar moves right after the skip
# link and, while it shows, gives the page scroll padding of its real height; the categories stay behind Settings
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_policy_url', '/leads-23') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q '<a href="/leads-23">Více o cookies a soukromí</a></p>' "$WORK/response" && grep -q 'preskocit.after(lista)' "$WORK/response" && grep -q 'scroll-padding-bottom: var(--ka-cookies-vyska' "$WORK/response" \
  && grep -q '\.cookies-volby:not(\[hidden\])' "$WORK/response" && echo "  ok     3.5: cookie bar – descriptive policy link, early in the tab order, scroll padding, categories behind Settings" || { echo "  CHYBA  3.5: cookie bar link, order or scroll padding"; ERRORS=$((ERRORS+1)); }
# 3.9 (UXM-11): the bar's text and policy link per language version, with the default language's as the fallback
mcp update_settings '{"settings":{"cookies_text":"Lišta UXM11 česky","cookies_text_en":"Bar UXM11 in English","cookies_policy_url_en":"/en/privacy-uxm11","cookies_policy_url_de":"javascript:alert(1)"}}' > "$WORK/response"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/uxm11-cs.html" "$B/leads-23"; curl -s -o "$WORK/uxm11-en.html" "$B/en/"
expect "3.9 UXM-11: the Czech page shows the Czech bar and policy link, the English version its own; an unsafe link is refused" \
  "$(grep -c 'Lišta UXM11 česky' "$WORK/uxm11-cs.html")|$(grep -c 'UXM11 česky</span> <a href="/leads-23">' "$WORK/uxm11-cs.html")|$(grep -c 'Bar UXM11 in English' "$WORK/uxm11-en.html")|$(grep -c 'in English</span> <a href="/en/privacy-uxm11">' "$WORK/uxm11-en.html")|$(grep -c 'UXM11 česky' "$WORK/uxm11-en.html")|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'cookies_policy_url_de'")" \
  "1|1|1|1|0|0"
mcp update_settings '{"settings":{"cookies_text_en":"","cookies_policy_url_en":""}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/uxm11-en.html" "$B/en/"
expect "3.9 UXM-11: a language version without its own text shows the default language's text and link" \
  "$(grep -c 'Lišta UXM11 česky' "$WORK/uxm11-en.html")|$(grep -c 'UXM11 česky</span> <a href="/leads-23">' "$WORK/uxm11-en.html")" "1|1"
check "3.9 UXM-11: Settings → Privacy and cookies has the bar for each language version" 200 "/admin.php?module=settings&tab=cookies" 'name="cookies_text_en"'
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna IN ('cookies_text', 'cookies_text_en', 'cookies_policy_url_en')"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna = 'cookies_policy_url'"
mcp update_settings '{"settings":{"lead_attribution":"0"}}' > /dev/null
# 2.6: an optional CAPTCHA on top of the built-in protection, checked with the provider on the server
mkdir -p "$WORK/captcha" && cat > "$WORK/captcha/router.php" <<'CAPTCHA'
<?php
$answer = $_POST['response'] ?? '';
header('Content-Type: application/json');
echo json_encode(($_POST['secret'] ?? '') !== 'test-secret' ? ['success' => false] : match ($answer) { 'pass' => ['success' => true, 'score' => 0.9], 'low' => ['success' => true, 'score' => 0.2], default => ['success' => false] });
CAPTCHA
(cd "$WORK/captcha" && exec php -S "127.0.0.1:$CAPTCHA_PORT" router.php > /dev/null 2>&1) & CAPTCHA_PID=$!
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_provider','turnstile'),('captcha_site_key','test-site'),('captcha_secret','test-secret') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota); DELETE FROM ka_kontrola_ip WHERE typ IN ('formular','odber')"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'class="ka-captcha cf-turnstile" data-sitekey="test-site"' "$WORK/formular.html" && [ "$(grep -o 'challenges.cloudflare.com/turnstile/v0/api.js' "$WORK/formular.html" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     CAPTCHA: the Turnstile widget in the form, its script once" || { echo "  CHYBA  CAPTCHA widget"; ERRORS=$((ERRORS+1)); }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
captcha_post() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Koupelna' --data-urlencode "p2=$1" "${@:2}"; }
case "$(captcha_post fail@example.cz -d cf-turnstile-response=wrong)" in *result=captcha*) echo "  ok     CAPTCHA: a failed check is refused";; *) echo "  CHYBA  CAPTCHA: failed check"; ERRORS=$((ERRORS+1));; esac
case "$(captcha_post none@example.cz)" in *result=captcha*) echo "  ok     CAPTCHA: a form without the answer is refused";; *) echo "  CHYBA  CAPTCHA: missing answer"; ERRORS=$((ERRORS+1));; esac
captcha_post pass@example.cz -d cf-turnstile-response=pass > /dev/null
expect "CAPTCHA: a passed check saves the enquiry, the failed ones not" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(email ORDER BY email) FROM ka_poptavky WHERE email IN ('fail@example.cz','none@example.cz','pass@example.cz')")" "pass@example.cz"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'recaptcha' WHERE promenna = 'captcha_provider'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'name="g-recaptcha-response" value="" data-recaptcha="test-site"' "$WORK/formular.html" && grep -q 'recaptcha/api.js?render=test-site' "$WORK/formular.html" || { echo "  CHYBA  reCAPTCHA v3 field and script"; ERRORS=$((ERRORS+1)); }
case "$(captcha_post low@example.cz -d g-recaptcha-response=low)" in *result=captcha*) echo "  ok     reCAPTCHA v3: a low score is refused";; *) echo "  CHYBA  reCAPTCHA score"; ERRORS=$((ERRORS+1));; esac
kill "$CAPTCHA_PID" 2>/dev/null; wait "$CAPTCHA_PID" 2>/dev/null || true; CAPTCHA_PID=
captcha_post down@example.cz -d g-recaptcha-response=pass > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_fail_open', '0') ON DUPLICATE KEY UPDATE hodnota = '0'"
case "$(captcha_post closed@example.cz -d g-recaptcha-response=pass)" in *result=captcha*) ;; *) echo "  CHYBA  CAPTCHA: fail closed"; ERRORS=$((ERRORS+1));; esac
expect "CAPTCHA: when the provider is down the owner's choice decides" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(email) FROM ka_poptavky WHERE email IN ('down@example.cz','closed@example.cz')")" "down@example.cz"
mcp update_settings '{"settings":{"captcha_secret":"stolen","captcha_provider":"hcaptcha"}}' > "$WORK/response"
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'captcha_secret'")" = "test-secret" ] && mcp update_settings '{}' > "$WORK/response" && ! contains -q 'test-secret' "$WORK/response" \
  && echo "  ok     CAPTCHA: Claude can neither set nor read the secret key" || { echo "  CHYBA  CAPTCHA secret over MCP"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna LIKE 'captcha_%'"
# 2.6: Google Tag Manager with consent mode – with the built-in bar it starts only after consent, without a bar right away
# 3.3.2 (N27): Claude can no longer set GTM or Matomo – they load script their owner chooses; the administrator sets them
mcp update_settings '{"settings":{"gtm_id":"GTM-EVIL1","matomo_url":"https://evil.example/","matomo_id":"1","ga4_id":"G-ABCD1234"}}' > "$WORK/response"
[ -z "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna IN ('gtm_id','matomo_url','matomo_id') AND hodnota <> ''")" ] \
  && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'ga4_id'")" = "G-ABCD1234" ] \
  && contains -q 'Google Tag Manager and Matomo load script' "$WORK/response" \
  && echo "  ok     MCP: gtm_id and matomo_* are refused with a reason, ga4_id is still accepted" || { echo "  CHYBA  MCP: GTM or Matomo settable over MCP"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"ga4_id":""}}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('gtm_id', 'GTM-TEST123') ON DUPLICATE KEY UPDATE hodnota = 'GTM-TEST123'"
mcp update_settings '{"settings":{"cookies_mode":"vestavena"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q '<script type="text/plain" data-gtm>(function(w,d,s,l,i)' "$WORK/response" && grep -q "gtag('consent','default',{ad_storage:'denied'" "$WORK/response" && grep -q "'dataLayer','GTM-TEST123'" "$WORK/response" \
  && grep -q 'data-kategorie="analytika"' "$WORK/response" && grep -q 'data-kategorie="marketing"' "$WORK/response" && echo "  ok     GTM: consent mode, the container waits for the cookie bar" || { echo "  CHYBA  GTM with the cookie bar"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"cookies_mode":"zadna"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q "<script>(function(w,d,s,l,i)" "$WORK/response" && ! grep -q "gtag('consent','default'" "$WORK/response" && echo "  ok     GTM: without a cookie bar the container loads right away" || { echo "  CHYBA  GTM without a bar"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'gtm_id'"
mcp update_settings '{"settings":{"cookies_mode":"vestavena"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
# 2.6: import from a website – a small "old site" with a sitemap, a header, a footer, an image and a blog post
OLD_PORT=$((PORT + 10)); OLD="http://127.0.0.1:$OLD_PORT"
mkdir -p "$WORK/oldsite/about-us" "$WORK/oldsite/blog/first-post" "$WORK/oldsite/img"
php -r '$i = imagecreatetruecolor(400, 300); imagefill($i, 0, 0, imagecolorallocate($i, 40, 120, 90)); imagepng($i, $argv[1]);' -- "$WORK/oldsite/img/team.png"
printf 'User-agent: *\nSitemap: %s/sitemap.xml\n' "$OLD" > "$WORK/oldsite/robots.txt"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/</loc></url><url><loc>%s/about-us/</loc></url><url><loc>%s/blog/first-post/</loc></url></urlset>' "$OLD" "$OLD" "$OLD" > "$WORK/oldsite/sitemap.xml"
oldpage() { printf '<!doctype html><html><head><title>%s | Old Oak</title><meta name="description" content="%s"></head><body><header><nav><a href="/">Old home</a> <a href="/about-us/">About</a></nav></header><main><h1>%s</h1>%s</main><footer>Old footer 1990</footer></body></html>' "$1" "$2" "$1" "$3"; }
oldpage "Welcome" "The old home page" "<p>Old Oak makes furniture by hand in our workshop near the river, since many years, for homes and offices alike.</p>" > "$WORK/oldsite/index.html"
oldpage "About us" "Who we are" '<p>We build oak furniture since 1990, for homes and offices across the region and beyond it, always by hand.</p><img src="/img/team.png" alt="Our team"><p><a href="/blog/first-post/">Read our story</a></p><div class="cookie-notice">We use cookies</div><p>Our tools id="</p><p title="><svg onload=alert(1)>">and</p><img src="/img/team.png" alt="q><svg onload=alert(2)>"><video src="/film.mp4" controls></video>' > "$WORK/oldsite/about-us/index.html"
printf '<!doctype html><html><head><title>Our first post | Old Oak</title><meta property="article:published_time" content="2024-05-06T09:00:00+02:00"></head><body><article><h1>Our first post</h1><p>Today we opened the new workshop for visitors, come and see how a table is made from a single oak.</p></article></body></html>' > "$WORK/oldsite/blog/first-post/index.html"
(cd "$WORK/oldsite" && exec php -S "127.0.0.1:$OLD_PORT" > /dev/null 2>&1) & OLDSITE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD/" && break; sleep 0.3; done
import_field() { php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "{}", true); echo is_array($r[$argv[2]] ?? null) ? json_encode($r[$argv[2]]) : ($r[$argv[2]] ?? "");' -- "$WORK/response" "$1"; }
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = preview ] && [ "$(import_field found)" = 3 ] && contains -q '/about-us' "$WORK/response" && echo "  ok     website import: three pages found in the sitemap, shown before importing" || { echo "  CHYBA  website import: finding pages"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 3.7 (N37-26): an import step counts the records it created against the hourly change limit – with one change left the
# step still runs (what it creates is known only afterwards), its change-log row says how many, and the next call is refused
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('claude_change_limit', '$(( $(claude_used test) + 1 ))')"
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
N26_ROWS=$(sq "SELECT popis FROM ka_protokol WHERE akce = 'importuj_web' ORDER BY idp DESC LIMIT 1")
mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response2"
expect "3.7 N37-26 website import: a step counts the records it created, the next step is refused over the limit" \
  "$([ "${N26_ROWS%% *}" -ge 2 ] 2>/dev/null && echo counted || echo "$N26_ROWS")|$(contains -q 'reached the limit' "$WORK/response2" && echo refused)" "counted|refused"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('claude_change_limit', '0')"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = done ] || { echo "  CHYBA  website import did not finish"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "website import: pages hidden, the post as a hidden news item" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT CONCAT(titulek, ':', zobrazit) FROM ka_stranky WHERE seo_link = 'about-us'), '|', (SELECT CONCAT(titulek, ':', visible, ':', DATE(datum)) FROM ka_novinky WHERE titulek = 'Our first post'))")" "About us:0|Our first post:0:2024-05-06"
ABOUT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(text, ' ', IFNULL(stavba, '')) FROM ka_stranky WHERE seo_link = 'about-us'")
echo "$ABOUT" | grep -q 'oak furniture' && echo "$ABOUT" | grep -q 'media/' && ! echo "$ABOUT" | grep -qE 'Old footer|Old home|We use cookies|127\.0\.0\.1' && echo "$ABOUT" | grep -q '"typ":"nadpis"' \
  && echo "  ok     website import: the content in the builder, the image in Media, no header, footer or cookie bar" || { echo "  CHYBA  website import: page content"; echo "$ABOUT" | head -c 600; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N23, N30): markup in attribute values of the old site stays text, and an imported page never gets Custom HTML
ABOUT_TEXT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky WHERE seo_link = 'about-us'")
! echo "$ABOUT_TEXT" | grep -q '<svg' && echo "$ABOUT_TEXT" | grep -q 'alt="q&gt;&lt;svg onload=alert(2)&gt;"' && ! echo "$ABOUT" | grep -q '"typ":"html"' \
  && echo "  ok     website import: attribute text never becomes markup, no Custom HTML from the old site" || { echo "  CHYBA  website import: markup from attributes or Custom HTML"; echo "$ABOUT" | head -c 900; ERRORS=$((ERRORS+1)); }
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'blog/first-post' AND na_adresu LIKE 'novinky/%'")" = 1 ] && echo "  ok     website import: the old address of the post redirects" || { echo "  CHYBA  website import: redirect"; ERRORS=$((ERRORS+1)); }
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "website import: running it again skips what is already there" "$(import_field result)" '{"new_pages":0,"new_news":0,"images":0,"redirects":0,"skipped":3,"failed":0}'
# 2.7: the migration report – a fourth old page with a form that nothing on the new site answers
# (one warning throughout: the old home page had a search engine description, the new starter home page has none)
mkdir -p "$WORK/oldsite/contact"
oldpage "Contact" "Write to us" '<p>Write to us about a table, a chair or a whole kitchen and we answer within two working days, promised.</p><form action="/send"><input name="email"><textarea name="message"></textarea></form>' > "$WORK/oldsite/contact/index.html"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/</loc></url><url><loc>%s/about-us/</loc></url><url><loc>%s/blog/first-post/</loc></url><url><loc>%s/contact/</loc></url></urlset>' "$OLD" "$OLD" "$OLD" "$OLD" > "$WORK/oldsite/sitemap.xml"
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "migration report: four old addresses – the imported ones not published yet, the contact page missing" "$(import_field summary)" '{"addresses":4,"checked":4,"ok":1,"redirected":0,"not_published":2,"missing":1,"errors":1,"warnings":3}'
contains -q '/contact' "$WORK/response" && contains -q 'form_missing\|missing' "$WORK/response" && contains -q 'site_checks' "$WORK/response" && echo "  ok     migration report: the missing page first, then the checks of the whole site" || { echo "  CHYBA  migration report rows"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_redirect '{"from":"/contact","to":"/about-us"}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'about-us'"
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "migration report: after a redirect and publishing, the contact address redirects (but the form is gone)" "$(import_field summary)" '{"addresses":4,"checked":4,"ok":2,"redirected":1,"not_published":1,"missing":0,"errors":1,"warnings":2}'
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 0 WHERE seo_link = 'about-us'; DELETE FROM ka_presmerovani WHERE z_adresy = 'contact'"
# 3.18.2: a form shared by pages as a component is still a form – the new contact page holds it in a component
mcp save_component '{"name":"Formulář 3182"}' > "$WORK/response"; COMP3182=$(mcp_value id)
mcp save_build "{\"component\":$COMP3182,\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\"}]}]}}" > /dev/null
mcp publish_build "{\"component\":$COMP3182}" > /dev/null
mcp create_page '{"title":"Contact","slug":"contact","visible":true}' > /dev/null; CONTACT3182=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'contact' AND smazano IS NULL")
mcp save_build "{\"id\":$CONTACT3182,\"publish\":true,\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$COMP3182\"}}]}]}}" > /dev/null
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
contains -q '/contact' "$WORK/response" && ! contains -q 'form_missing' "$WORK/response" \
  && echo "  ok     3.18.2: the migration report counts a form inside a component" || { echo "  CHYBA  3.18.2 form in a component: $(import_field summary)"; ERRORS=$((ERRORS+1)); }
# 3.18.2 N3182-4: a component holding itself 60 times and a chain A → B → A – the report follows each component once
# (before: 61⁴ lookups for the one page, the batch never ended) and still finds the form
mcp save_component '{"name":"Cyklus 3182"}' > "$WORK/response"; CYCLE3182=$(mcp_value id)
CYCLE_BUILD=$(php -r '$d = [["id" => "f3182", "typ" => "formular"]]; for ($i = 0; $i < 60; $i++) { $d[] = ["id" => "s3182x" . $i, "typ" => "komponenta", "obsah" => ["komponenta" => $argv[1]]]; } $d[] = ["id" => "c3182", "typ" => "komponenta", "obsah" => ["komponenta" => $argv[2]]]; echo addslashes(json_encode(["v" => 1, "deti" => [["id" => "k3182", "typ" => "sekce", "deti" => $d]]]));' -- "$COMP3182" "$CYCLE3182")
sq "UPDATE ka_komponenty SET stavba = '$CYCLE_BUILD' WHERE idm = $COMP3182" > /dev/null
sq "UPDATE ka_komponenty SET stavba = '{\"v\":1,\"deti\":[{\"id\":\"b3182\",\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$COMP3182\"}}]}' WHERE idm = $CYCLE3182" > /dev/null
START3182=$(date +%s)
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = done ] && contains -q '/contact' "$WORK/response" && ! contains -q 'form_missing' "$WORK/response" && [ $(( $(date +%s) - START3182 )) -lt 60 ] \
  && echo "  ok     3.18.2 N3182-4: a component inside itself and a chain A → B → A – the report finishes and counts the form" || { echo "  CHYBA  3.18.2 N3182-4 component cycle: $(import_field phase) $(import_field summary) $(( $(date +%s) - START3182 )) s"; ERRORS=$((ERRORS+1)); }
mcp delete_component "{\"id\":$CYCLE3182}" > /dev/null
sq "DELETE FROM ka_stranky WHERE ids = $CONTACT3182" > /dev/null; mcp delete_component "{\"id\":$COMP3182}" > /dev/null
# 3.8: an old page nested 3,000 deep is never parsed – the website import skips it with the limit among its failures, the
# migration report checks only its address
mkdir -p "$WORK/oldsite/deep"
oldpage "Deep" "Deep page" "$(printf '<div>%.0s' $(seq 1 3000))" > "$WORK/oldsite/deep/index.html"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/deep/</loc></url></urlset>' "$OLD" > "$WORK/oldsite/sitemap.xml"
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "3.8: website import skips an old page over the nesting limit with the limit in its failures, nothing stored" \
  "$(import_field result | grep -o '"failed":[0-9]*')|$(import_field failures | grep -c 'deep.*3 001')|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Deep'")" '"failed":1|1|0'
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
contains -q 'too_large' "$WORK/response" && echo "  ok     3.8: the migration report reads an old page over the limit as too large, only its address is checked" \
  || { echo "  CHYBA  3.8: migration report of a page over the limit"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
kill "$OLDSITE_PID" 2>/dev/null; OLDSITE_PID=
