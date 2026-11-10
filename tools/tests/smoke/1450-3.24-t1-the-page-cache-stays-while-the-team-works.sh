echo "== 3.24 (T1): the page cache stays while the team works on enquiries, notes and requests"
T324_CACHE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'page_cache'"); setting page_cache 1
t324_cached() { find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' '; }
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('$(site_time)', 'T324', 't324@example.com', '[]')" > /dev/null
T324_ENQ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'T324'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$T324_ENQ"; T324_TOKEN=$(csrf)
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null "$B/"; T324_BEFORE=$(t324_cached)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$T324_TOKEN" -d "idp=$T324_ENQ" --data-urlencode "poznamka=Call back on Monday"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T324_TOKEN" -d tab=mail -d "mail_from=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'mail_from'")"
mcp write_notebook '{"topic":"decisions","title":"T324","text":"The page cache stays while the team works."}' > /dev/null
mcp update_enquiry "{\"id\":$T324_ENQ,\"status\":\"resolved\"}" > "$WORK/response"
expect "3.24 (T1): an enquiry note, a save of the Mail tab, a notebook entry and an enquiry update over MCP keep the cached page" \
  "$T324_BEFORE|$(t324_cached)|$(sq "SELECT poznamka FROM ka_poptavky WHERE idp = $T324_ENQ")|$(grep -c 'isError' "$WORK/response")" "1|1|Call back on Monday|0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=t324_unknown" -d "_csrf=$T324_TOKEN"
T324_ADMIN=$(t324_cached); curl -s -o /dev/null "$B/"
mcp save_redirect '{"from":"/t324-old","to":"/"}' > /dev/null
expect "3.24 (T1): a POST to a section that changes the site clears the cache (even an action it does not know), a site-changing MCP tool too" "$T324_ADMIN|$(t324_cached)" "0|0"
sq "DELETE FROM ka_poptavky WHERE idp = $T324_ENQ; DELETE FROM ka_notebook WHERE title = 'T324'; DELETE FROM ka_presmerovani WHERE z_adresy LIKE '%t324-old%'" > /dev/null
setting page_cache "$T324_CACHE"

