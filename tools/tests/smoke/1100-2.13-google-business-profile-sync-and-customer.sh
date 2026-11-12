echo "== 2.13: Google Business Profile sync and customer reviews from Google"
GBP_LOG="$FAKE_LOGS-google-business.log"; rm -f "$GBP_LOG" "$FAKE_LOGS-google-fewer"
# gbp_sent <key>: the last logged request of a kind (patch | post) as JSON, for the checks of what went to Google
gbp_sent() { php -r 'foreach (array_reverse(file($argv[1], FILE_IGNORE_NEW_LINES)) as $l) { $e = json_decode($l, true); if (isset($e[$argv[2]])) { echo json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; } }' -- "$GBP_LOG" "$1" 2>/dev/null; }
GBP_HOURS_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'")
connect_fake google
check "GBP: the Connections screen has the Business Profile section with the location select and the news opt-in" 200 "/admin.php?module=connectors" 'name="config\[location\]"'
grep -q 'name="config\[post_news\]"' "$WORK/response" && grep -q 'action=gbp_locations' "$WORK/response" && ! grep -q 'action=gbp_sync' "$WORK/response" && echo "  ok     GBP: Load my locations is offered, Sync now only once a location is chosen" || { echo "  CHYBA  GBP section"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=gbp_locations" -d "_csrf=$(csrf)"
check "GBP: Load my locations lists the account's locations from Google" 200 "/admin.php?module=connectors" '<option value="accounts/100/locations/2001">Test Company – Prague</option>'
grep -q 'Test Company – Brno' "$WORK/response" && grep -q '"readMask":"name,title"' "$GBP_LOG" && echo "  ok     GBP: both locations, asked for with a read mask" || { echo "  CHYBA  locations"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=save" -d "_csrf=$(csrf)" -d service=google -d client_id=test-client -d secret= --data-urlencode "config[location]=accounts/100/locations/2001" -d "config[post_news]=1"
expect "GBP: the chosen location and the opt-in are stored in the connection's config, the secret stays" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(config, '$.location')), '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.post_news')), '|', secret IS NOT NULL) FROM ka_connectors WHERE service = 'google'")" "accounts/100/locations/2001|1|1"
check "GBP: the chosen location is selected and Sync now is offered" 200 "/admin.php?module=connectors" '<option value="accounts/100/locations/2001" selected>'
# the hours: saving Settings → Company queues one gbp.hours delivery (however many saves), an exception too; the job delivers the PATCH
sq "DELETE FROM ka_connector_queue" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=company -d company_type=LocalBusiness --data-urlencode "company_hours=Po-Pá 8:00-17:00"
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Inventura GBP\",\"notice_days\":0}" > /dev/null
expect "GBP: saving the company hours and an exception queue one gbp.hours delivery" "$(sq "SELECT CONCAT(COUNT(*), '|', MIN(action)) FROM ka_connector_queue WHERE next_attempt IS NOT NULL")" "1|gbp.hours"
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('gbp', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null # the daily job ran (not connected) at the first /ulohy of this run – due again now
# the visit trigger of background jobs (at most once a minute) also runs after this cron call when the last one is older than
# a minute, and its connectors pass would deliver the hours the gbp job just queued a second time (2|2 on a slow runner):
# mark it as just run, so only the cron pass delivers
background_jobs resume
rm -f "$GBP_LOG"; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
GBP_PATCH=$(gbp_sent patch)
GBP_TOMORROW=$(php -r '$d = new DateTimeImmutable($argv[1]); echo json_encode(["year" => (int) $d->format("Y"), "month" => (int) $d->format("n"), "day" => (int) $d->format("j")]);' -- "$TOMORROW")
printf %s "$GBP_PATCH" | grep -q '"updateMask":"regularHours,specialHours"' && [ "$(printf %s "$GBP_PATCH" | grep -o '"openDay":"[A-Z]*"' | sort | tr '\n' ' ')" = '"openDay":"FRIDAY" "openDay":"MONDAY" "openDay":"THURSDAY" "openDay":"TUESDAY" "openDay":"WEDNESDAY" ' ] \
  && printf %s "$GBP_PATCH" | grep -q '"openTime":{"hours":8,"minutes":0},"closeDay":"MONDAY","closeTime":{"hours":17,"minutes":0}' && printf %s "$GBP_PATCH" | grep -qF "{\"startDate\":$GBP_TOMORROW,\"endDate\":$GBP_TOMORROW,\"closed\":true}" \
  && echo "  ok     GBP: the job PATCHes the location – Mo–Fr 8–17 as regularHours, tomorrow closed as specialHours, with the update mask" || { echo "  CHYBA  GBP hours PATCH"; printf '%s\n' "$GBP_PATCH" | head -c 600; cat "$WORK/tasks.txt" | head -5; ERRORS=$((ERRORS+1)); }
expect "GBP: the delivery is done and logged as gbp.hours, never with its content" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_connector_queue WHERE action = 'gbp.hours' AND delivered_at IS NOT NULL), '|', (SELECT COUNT(*) FROM ka_connector_log WHERE action = 'gbp.hours' AND ok = 1))")" "1|1"
# the daily job ran in the same /ulohy: the reviews came in with the profile's rating, the reviewer's name without tags
expect "GBP: the daily job stores the fake reviews with the rating and the count of the whole profile" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_google_reviews), '|', (SELECT author FROM ka_google_reviews WHERE review_id = 'rev-b'), '|', (SELECT reply FROM ka_google_reviews WHERE review_id = 'rev-a'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_rating'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_reviews'))")" "3|Petr N.|Thank you, Alena!|4.3|27"
grep -q "gbp: hours queued, reviews 3" "$WORK/tasks.txt" && echo "  ok     GBP: the scheduler reports the job" || { echo "  CHYBA  gbp job result"; grep gbp "$WORK/tasks.txt" || true; ERRORS=$((ERRORS+1)); }
check "GBP: the Connections screen shows the fetched rating" 200 "/admin.php?module=connectors" 'Hodnocení na Google 4,3 z 5 z 27 recenzí'
# a published news item becomes a post with the LEARN_MORE button to its address
mcp create_news "{\"title\":\"Nová hala GBP\",\"category\":\"$CATEGORY\",\"publish\":true,\"intro\":\"<p>Otevřeli jsme <b>novou</b> halu.</p>\",\"image\":\"media/hala.jpg\"}" > /dev/null
GBP_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Nová hala GBP'")
curl -s -o /dev/null "$B/ulohy?token=testtoken123"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
GBP_POST=$(gbp_sent post)
printf %s "$GBP_POST" | grep -qF '"summary":"Nová hala GBP\n\nOtevřeli jsme novou halu."' && printf %s "$GBP_POST" | grep -q '"topicType":"STANDARD"' && printf %s "$GBP_POST" | grep -q "\"callToAction\":{\"actionType\":\"LEARN_MORE\",\"url\":\"$B/novinky/nova-hala-gbp\"}" \
  && printf %s "$GBP_POST" | grep -q "\"media\":\[{\"mediaFormat\":\"PHOTO\",\"sourceUrl\":\"$B/media/hala.jpg\"}\]" && echo "  ok     GBP: the published news item went out as a STANDARD post with the plain summary, the image and a Learn more button" || { echo "  CHYBA  GBP post"; printf '%s\n' "$GBP_POST" | head -c 600; ERRORS=$((ERRORS+1)); }
expect "GBP: the post was delivered through the queue once" "$(sq "SELECT COUNT(*) FROM ka_connector_queue WHERE action = 'gbp.post' AND delivered_at IS NOT NULL")" "1"
# the Google reviews element: the newest reviews with at least 4 stars, the summary, the link, AggregateRating
mcp builder_schema '{}' > "$WORK/response"
mcp_value elements google_reviews | grep -q 'Google reviews.*count:number=3; min_stars:number=4; summary:boolean' && echo "  ok     MCP: builder_schema lists google_reviews with its English fields" || { echo "  CHYBA  builder_schema google_reviews"; mcp_value elements google_reviews | head -c 300 || true; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Recenze GBP","slug":"recenze-gbp","visible":true}' > /dev/null; GBP_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'recenze-gbp'")
mcp save_build "{\"id\":$GBP_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Recenze\"}},{\"type\":\"google_reviews\",\"content\":{\"count\":5,\"min_stars\":4,\"summary\":true,\"link\":\"https://maps.google.com/?cid=1\"}},{\"type\":\"text\",\"content\":{\"html\":\"<p>Hodnocení {{fact.google_rating}} z {{fact.google_reviews}}</p>\"}}]}]}}" > "$WORK/response"
expect "GBP: the build is stored with the Czech element type" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[1].typ')) FROM ka_stranky WHERE ids = $GBP_PAGE")" "recenze_google"
rm -f "$WORK"/web/storage/cache/stranky/*.html; check "GBP: the page shows the reviews" 200 "/recenze-gbp" '<li class="ka-recenze"><header><strong>Alena K.</strong>'
grep -q '<strong>Petr N.</strong>' "$WORK/response" && ! grep -q 'Nobody answered' "$WORK/response" && grep -q '<p>Fast and friendly.<br />' "$WORK/response" && grep -q '<p class="ka-recenze-odpoved"><strong>Odpověď firmy:</strong> Thank you, Alena!</p>' "$WORK/response" \
  && grep -q 'aria-label="Hodnocení 4,3 z 5 · Recenzí na Google: 27"' "$WORK/response" && grep -q 'href="https://maps.google.com/?cid=1" target="_blank" rel="noopener">Všechny recenze na Google</a>' "$WORK/response" \
  && echo "  ok     GBP: two reviews with 4+ stars (the 2-star one left out), the reply, the summary with stars, the link to all reviews" || { echo "  CHYBA  GBP element"; grep -o 'ka-recenze-google.\{0,600\}' "$WORK/response" | head -c 700 || true; ERRORS=$((ERRORS+1)); }
grep -q '"aggregateRating":{"@type":"AggregateRating","ratingValue":4.3,"reviewCount":27,"bestRating":5,"worstRating":1}' "$WORK/response" && grep -q '"review":\[{"@type":"Review","author":{"@type":"Person","name":"Alena K."},"datePublished":"2026-09-20","reviewRating":{"@type":"Rating","ratingValue":5' "$WORK/response" \
  && grep -q '"@id":"'"$B"'/#firma"' "$WORK/response" && echo "  ok     GBP: AggregateRating and the shown reviews on the company node, only from Google's data" || { echo "  CHYBA  GBP structured data"; grep -o 'ld+json.\{0,400\}' "$WORK/response" | tail -1 || true; ERRORS=$((ERRORS+1)); }
grep -q '<p>Hodnocení 4.3 z 27</p>' "$WORK/response" && echo "  ok     GBP: {{fact.google_rating}} and {{fact.google_reviews}} are built-in facts" || { echo "  CHYBA  google facts"; grep -o 'Hodnocení [^<]*' "$WORK/response" | head -2 || true; ERRORS=$((ERRORS+1)); }
# a review deleted on Google disappears with the next fetch
touch "$FAKE_LOGS-google-fewer"; sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'gbp'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
GBP_LEFT=$(sq "SELECT GROUP_CONCAT(review_id ORDER BY review_id) FROM ka_google_reviews")
[ "$GBP_LEFT" = "rev-a,rev-c" ] && echo "  ok     GBP: a review gone from Google is gone from the site" || { echo "  CHYBA  GBP: a review gone from Google is gone from the site: $GBP_LEFT"; cat "$WORK/tasks.txt"; sq "SELECT name, last_run, last_error FROM ka_jobs WHERE name = 'gbp'"; sq "SELECT created_at, action, status, error FROM ka_connector_log ORDER BY id DESC LIMIT 4"; tail -3 "$GBP_LOG" || true; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/ulohy?token=testtoken123" # the daily job queued the hours again – delivered now
curl -s -o "$WORK/response" "$B/recenze-gbp"; ! grep -q 'Petr N.' "$WORK/response" && grep -q 'Alena K.' "$WORK/response" && echo "  ok     GBP: the page no longer shows it (the cache was cleared)" || { echo "  CHYBA  deleted review still shown"; ERRORS=$((ERRORS+1)); }
# disconnecting Google deletes the reviews and the rating; the element shows nothing to visitors
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=connectors"
curl -s -b "$JAR" -o /dev/null -X POST "$B/admin.php?module=connectors&action=disconnect" -d "_csrf=$(csrf)" -d service=google
expect "GBP: disconnecting Google deletes the reviews, the rating and the loaded locations" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_google_reviews), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_rating'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'google_locations'))")" "0||"
curl -s -o "$WORK/response" "$B/recenze-gbp"; ! grep -q 'class="ka-recenze' "$WORK/response" && ! grep -q 'AggregateRating' "$WORK/response" && grep -q '<p>Hodnocení  z </p>' "$WORK/response" && echo "  ok     GBP: without the connection the element renders nothing and the facts are empty" || { echo "  CHYBA  element after disconnect"; grep -o 'ka-recenze.\{0,200\}' "$WORK/response" | head -c 300 || true; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=business"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$(csrf)" -d tab=company --data-urlencode "company_hours=Po-Pá 9:00-16:00"
expect "GBP: without the connection a change of the hours queues nothing" "$(sq "SELECT COUNT(*) FROM ka_connector_queue WHERE next_attempt IS NOT NULL")" "0"
mcp trash_page "{\"id\":$GBP_PAGE}" > /dev/null; sq "DELETE FROM ka_hours_exceptions WHERE note = 'Inventura GBP'; UPDATE ka_nastaveni SET hodnota = '$GBP_HOURS_BEFORE' WHERE promenna = 'company_hours'" > /dev/null; rm -f "$FAKE_LOGS-google-fewer"
