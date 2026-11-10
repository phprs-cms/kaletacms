echo "== 2.14: links that look after themselves"
# 404s that fix themselves: a page moved without a redirect, visitors still ask for the old address (and a similar one)
mcp create_page '{"title":"Reference portfolio","slug":"reference-portfolio","visible":true,"in_menu":false,"text":"<p>Naše reference.</p>"}' > /dev/null
F15_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'reference-portfolio'")
sq "UPDATE ka_stranky SET seo_link = 'sluzby/reference-portfolio' WHERE ids = $F15_PAGE; DELETE FROM ka_presmerovani WHERE z_adresy LIKE '%reference-portfolio%'; DELETE FROM ka_nenalezeno; DELETE FROM ka_events WHERE type = 'redirect.auto'" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
for i in 1 2 3; do curl -s -o /dev/null "$B/reference-portfolio"; curl -s -o /dev/null "$B/reference-portfolio-2019"; curl -s -o /dev/null "$B/qzx-nahodna-adresa"; done
check "Redirects: the 404 list shows the page the visitor probably meant" 200 "/admin.php?module=redirects" "/sluzby/reference-portfolio"
grep -q 'name="redirect_auto"' "$WORK/response" && grep -q 'Vytvořit přesměrování' "$WORK/response" && grep -q 'žádná podobná stránka' "$WORK/response" && echo "  ok     Redirects: the setting for redirects by themselves, one-click create, no suggestion for a random address" || { echo "  CHYBA  redirects suggestions screen"; ERRORS=$((ERRORS+1)); }
mcp list_redirects '{}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '\\"suggestion\\":\\"/sluzby/reference-portfolio\\",\\"score\\":90' "$WORK/response" && grep -q '\\"suggestion\\":\\"/sluzby/reference-portfolio\\",\\"score\\":85' "$WORK/response" && grep -qE 'qzx-nahodna-adresa[^}]*\\"suggestion\\":null' "$WORK/response" \
  && echo "  ok     MCP: list_redirects carries the suggestion and its score" || { echo "  CHYBA  list_redirects suggestions"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('redirects', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "redirects: off" "$WORK/tasks.txt" && [ "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE auto_score IS NOT NULL")" = 0 ] && echo "  ok     redirects by themselves are off by default" || { echo "  CHYBA  redirects job default"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=redirects"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=settings" -d "_csrf=$(csrf)" -d redirect_auto=1 -d redirect_auto_threshold=90
expect "the setting is saved from the Redirects screen" "$(sq "SELECT GROUP_CONCAT(hodnota ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('redirect_auto', 'redirect_auto_threshold')")" "1,90"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'redirects'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "the daily job creates the redirect above the threshold, not below it" "$(sq "SELECT CONCAT((SELECT CONCAT(na_adresu, '|', typ, '|', auto_score) FROM ka_presmerovani WHERE z_adresy = 'reference-portfolio'), '|', (SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy IN ('reference-portfolio-2019', 'qzx-nahodna-adresa')))")" "sluzby/reference-portfolio|301|90|0"
expect "the old address redirects, the missing address leaves the 404 log" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/reference-portfolio")|$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta = 'reference-portfolio'")" "301 $B/sluzby/reference-portfolio|0"
expect "the automatic redirect is in the change log and the event redirect.auto" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'redirect.auto' AND data LIKE '%\"from\":\"/reference-portfolio\",\"to\":\"/sluzby/reference-portfolio\",\"score\":90%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'redirects' AND akce = 'auto' AND popis LIKE '%reference-portfolio%'))")" "1|1"
check "the Redirects list marks it automatic with the score (undo = delete)" 200 "/admin.php?module=redirects" "automaticky, skóre 90"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$(csrf)" -d z_adresy=/reference-portfolio-2019 -d na_adresu=/sluzby/reference-portfolio -d typ=301
expect "one click creates the suggested redirect by hand – not marked automatic" "$(sq "SELECT CONCAT(na_adresu, '|', auto_score IS NULL) FROM ka_presmerovani WHERE z_adresy = 'reference-portfolio-2019'")" "sluzby/reference-portfolio|1"
mcp update_settings '{"settings":{"redirect_auto":false}}' > /dev/null
expect "MCP: update_settings switches the automatic redirects off" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'redirect_auto'")" "0"
# internal link suggestions: a published page out of the navigation that nothing links to
mcp create_page '{"title":"Reference portfolio detail","slug":"portfolio-sirotek","visible":true,"in_menu":false,"text":"<p>Detail.</p>"}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "Site audit lists the orphan page" 200 "/admin.php?module=audit" "Stránky, na které nikdo neodkazuje"
grep -q 'Stránka „Reference portfolio detail“' "$WORK/response" && echo "  ok     the orphan is named with where to fix it" || { echo "  CHYBA  orphan in audit"; ERRORS=$((ERRORS+1)); }
# the home page of a language version (/en/) is reached through the logo and the language switcher – never an orphan
mcp create_page '{"title":"Home language version","slug":"home-language-version","visible":true,"in_menu":false,"text":"<p>Home</p>"}' > /dev/null
sq "UPDATE ka_stranky SET jazyk = 'en', preklad_z = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page') WHERE seo_link = 'home-language-version'" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; mcp suggest_internal_links '{"limit":100}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q 'portfolio-sirotek' "$WORK/response" && ! grep -q 'home-language-version' "$WORK/response" \
  && echo "  ok     the home page of a language version is no orphan" || { echo "  CHYBA  the home page of a language version is an orphan"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE seo_link = 'home-language-version'" > /dev/null
mcp suggest_internal_links '{"limit":100}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '\\"page\\":\\"/portfolio-sirotek\\"' "$WORK/response" && grep -q '\\"page\\":\\"/sluzby/reference-portfolio\\",\\"shared_words\\":\[\\"reference\\",\\"portfolio\\"\]' "$WORK/response" \
  && echo "  ok     MCP: suggest_internal_links returns the orphan with a candidate page sharing title words" || { echo "  CHYBA  suggest_internal_links"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$F15_PAGE,\"text\":\"<p>Naše reference – <a href=\\\"/portfolio-sirotek\\\">detail</a>.</p>\"}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; mcp suggest_internal_links '{"limit":100}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '\\"page\\":\\"/portfolio-sirotek\\",\\"target\\"' "$WORK/response" && { echo "  CHYBA  a linked page is still an orphan"; ERRORS=$((ERRORS+1)); } || echo "  ok     a page linked from a text is no orphan any more"
# broken external links in a page build: a local port nothing listens on (the test server runs with KALETA_LINKS_LOCAL=1)
mcp create_page '{"title":"Odkazy test","slug":"odkazy-test","visible":true}' > /dev/null; F15_BUILD=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'odkazy-test'")
mcp save_build "{\"id\":$F15_BUILD,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Starý partner\",\"link\":\"http://127.0.0.1:1/partner\"}}]}]}}" > /dev/null
mcp publish_build "{\"id\":$F15_BUILD}" > /dev/null
F15_ELEMENT=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba, '$.deti[0].deti[0].id')) FROM ka_stranky WHERE ids = $F15_BUILD")
sq "UPDATE ka_novinky SET odkazy_cas = '$(site_time)'; UPDATE ka_stranky SET links_checked = '$(site_time)' WHERE ids <> $F15_BUILD; UPDATE ka_kolekce_polozky SET links_checked = '$(site_time)'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'link_check_time'; DELETE FROM ka_odkazy_vadne" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "the link check finds the dead link in the page build with its element" "$(sq "SELECT CONCAT(kind, '|', idc, '|', element, '|', stav) FROM ka_odkazy_vadne WHERE url = 'http://127.0.0.1:1/partner'")" "page|$F15_BUILD|$F15_ELEMENT|0"
mcp list_broken_links '{}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q "\\\\\"kind\\\\\":\\\\\"page\\\\\",\\\\\"id\\\\\":$F15_BUILD,\\\\\"title\\\\\":\\\\\"Odkazy test\\\\\"" "$WORK/response" && grep -q "\\\\\"element\\\\\":\\\\\"$F15_ELEMENT\\\\\"" "$WORK/response" && grep -q 'web.archive.org/web/2020/http://127.0.0.1:1/partner' "$WORK/response" \
  && echo "  ok     MCP: list_broken_links says where the link is, the element and the archive hint" || { echo "  CHYBA  list_broken_links"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "News → Broken links is a site-wide list" 200 "/admin.php?module=news&action=links" "Odkazy test"
grep -q "prvek $F15_ELEMENT" "$WORK/response" && echo "  ok     the list names the element" || { echo "  CHYBA  broken links element"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"link"}' | sed 's#\\/#/#g' > "$WORK/response"
grep -q '127.0.0.1:1/partner' "$WORK/response" && grep -q "\\\\\"element\\\\\":\\\\\"$F15_ELEMENT\\\\\"" "$WORK/response" && echo "  ok     site_audit reports the broken build link with the element" || { echo "  CHYBA  site_audit broken link"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=links"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=links" -d "_csrf=$(csrf)" -d kind=page -d id=$F15_BUILD
expect "Check again puts the page at the front of the queue" "$(sq "SELECT CONCAT((SELECT links_checked IS NULL FROM ka_stranky WHERE ids = $F15_BUILD), '|', (SELECT COUNT(*) FROM ka_odkazy_vadne WHERE kind = 'page' AND idc = $F15_BUILD))")" "1|0"

