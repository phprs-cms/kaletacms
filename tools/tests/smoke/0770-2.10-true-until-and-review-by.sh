echo "== 2.10: true until and review by"
YESTERDAY=$(site_time "-1 day" "Y-m-d"); TODAY=$(site_time now "Y-m-d")
# a visible page and a published news item that were true until yesterday and ask for a review today, a pop-up with a review due
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('validity', '$(site_time)' + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)" > /dev/null # not due until the test runs it itself (a day ahead: MySQL and PHP may be in different time zones)
mcp create_page "{\"title\":\"Expired offer\",\"text\":\"<p>Only until yesterday.</p>\",\"visible\":true,\"valid_until\":\"$YESTERDAY\",\"review_by\":\"$TODAY\"}" > "$WORK/response"
VALID_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Expired offer'")
grep -qF "valid_until\\\":\\\"$YESTERDAY" "$WORK/response" && grep -qF "review_by\\\":\\\"$TODAY" "$WORK/response" && echo "  ok     MCP: create_page takes valid_until and review_by and returns them" || { echo "  CHYBA  create_page valid_until/review_by"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_news "{\"title\":\"Expired news\",\"category\":\"$CATEGORY\",\"publish\":true,\"valid_until\":\"$YESTERDAY\",\"review_by\":\"$TODAY\"}" > "$WORK/response"
VALID_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Expired news'")
grep -qF "valid_until\\\":\\\"$YESTERDAY" "$WORK/response" && echo "  ok     MCP: create_news takes valid_until and review_by" || { echo "  CHYBA  create_news valid_until"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_popup "{\"name\":\"Review popup\",\"template\":\"blank\",\"review_by\":\"$YESTERDAY\"}" > "$WORK/response"
grep -qF "review_by\\\":\\\"$YESTERDAY" "$WORK/response" && echo "  ok     MCP: save_popup takes review_by" || { echo "  CHYBA  save_popup review_by"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$VALID_PAGE,\"valid_until\":\"nonsense\"}" > "$WORK/response"
grep -q 'must be a date' "$WORK/response" && echo "  ok     MCP: a value that is not a date is refused" || { echo "  CHYBA  update_page valid_until validation"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "before the job both are still visible" "$(sq "SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = $VALID_PAGE), '|', (SELECT visible FROM ka_novinky WHERE idc = $VALID_NEWS))")" "1|1"
# the hourly job hides what expired, records the events and asks for the reviews – once
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "the job hid the expired page and news item" "$(sq "SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = $VALID_PAGE), '|', (SELECT visible FROM ka_novinky WHERE idc = $VALID_NEWS))")" "0|0"
expect "content.expired events with the kind and id, content.review once per content" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired' AND severity = 'warning' AND data LIKE '%\"kind\":\"page\",\"id\":$VALID_PAGE%'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'))")" "1|2|3"
expect "the change log records what hid itself" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'expired' AND modul IN ('pages', 'news')")" "2"
grep -q 'validity: hidden 2, reviews 3' "$WORK/tasks.txt" && echo "  ok     the job reports what it did" || { echo "  CHYBA  validity job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a second run asks for no review twice and hides nothing again" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'))")" "3|2"
check "the hidden page is no longer on the site" 404 "/expired-offer"
# the site audit lists the content due for a review, with where to fix it
mcp site_audit '{"kind":"review"}' > "$WORK/response"
grep -q 'Expired offer' "$WORK/response" && grep -q 'Expired news' "$WORK/response" && grep -q 'Review popup' "$WORK/response" && grep -q '\\"page\\":' "$WORK/response" && grep -q '\\"news\\":' "$WORK/response" && grep -q '\\"popup\\":' "$WORK/response" \
  && echo "  ok     MCP: site_audit kind review lists the page, the news item and the pop-up with their targets" || { echo "  CHYBA  site_audit review"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "Administration → Site audit shows the review-by findings" 200 "/admin.php?module=audit" "Expired offer"
# the page form has the two fields; saving it with the dates changed is kept
check "the page form shows true until and review by" 200 "/admin.php?module=pages&action=edit&id=$VALID_PAGE" "name=\"valid_until\" value=\"$YESTERDAY\""
grep -q "name=\"review_by\" value=\"$TODAY\"" "$WORK/response" && echo "  ok     the page form shows the review-by date" || { echo "  CHYBA  page form review_by"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$(csrf)" -d "ids=$VALID_PAGE" -d "titulek=Expired offer" -d "seo_link=expired-offer" -d "text=<p>x</p>" -d "poradi=100" -d "valid_until=" -d "review_by=2030-01-01"
expect "saving the page form clears true until and keeps the new review-by date" "$(sq "SELECT CONCAT(IFNULL(valid_until, 'null'), '|', IFNULL(review_by, 'null')) FROM ka_stranky WHERE ids = $VALID_PAGE")" "null|2030-01-01"
check "the pages list shows the review-by badge" 200 "/admin.php?module=pages" "stitek stitek-koncept\" title=\"V tento den žádá o kontrolu.\""
check "the news form shows the two fields" 200 "/admin.php?module=news&action=edit&id=$VALID_NEWS" "name=\"review_by\" value=\"$TODAY\""
check "the pop-up form shows the two fields" 200 "/admin.php?module=popups&action=edit&id=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Review popup'")" "name=\"review_by\" value=\"$YESTERDAY\""
mcp update_page "{\"id\":$VALID_PAGE,\"review_by\":\"\"}" > "$WORK/response"
expect "MCP: an empty string clears review by" "$(sq "SELECT IFNULL(review_by, 'null') FROM ka_stranky WHERE ids = $VALID_PAGE")" "null"
