echo "== url_slash: the preferred URL form (#14) – pages follow it, the Claude connection and system addresses never move"
url_slash() { mcp update_settings "{\"settings\":{\"url_slash\":\"$1\"}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-this-diary/"); expect "url_slash bez (default): /about-this-diary/ redirects to /about-this-diary" "$code" "301 $B/about-this-diary"
url_slash s
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-this-diary?x=1"); expect "url_slash s: /about-this-diary redirects to /about-this-diary/ with its query" "$code" "301 $B/about-this-diary/?x=1"
check "url_slash s: /about-this-diary/ is the page and its canonical URL" 200 /about-this-diary/ "rel=\"canonical\" href=\"$B/about-this-diary/\""
SITEMAP_CODE=$(curl -s -o "$WORK/sitemap.xml" -w '%{http_code}' "$B/sitemap.xml")
contains -q "<loc>$B/about-this-diary/</loc>" "$WORK/sitemap.xml" && echo "  ok     url_slash s: the sitemap uses /about-this-diary/" \
  || { echo "  CHYBA  url_slash s: sitemap (HTTP $SITEMAP_CODE; the page: $(sq "SELECT CONCAT_WS('|', ids, jazyk, zobrazit, noindex, smazano IS NULL) FROM ka_stranky WHERE seo_link = 'about-this-diary'"))"; head -c 1200 "$WORK/sitemap.xml"; echo; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2019/05/planting-first-beds.html"); expect "url_slash s: an old imported .html address redirects in one step" "$code" "301 $B/novinky/planting-first-beds/"
for mode in s html; do
  url_slash "$mode"
  codes=""; for p in /.well-known/oauth-protected-resource /.well-known/oauth-protected-resource/mcp /.well-known/oauth-authorization-server /.well-known/openid-configuration; do codes="$codes$(curl -s -o /dev/null -w '%{http_code}' "$B$p") "; done
  expect "url_slash $mode: the OAuth discovery of the Claude connection is answered, never redirected" "$codes" "200 200 200 200 "
  expect "url_slash $mode: /mcp, /ulohy and the subscription link are not redirected" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') $(curl -s -o /dev/null -w '%{redirect_url}' "$B/ulohy")$(curl -s -o /dev/null -w '%{redirect_url}' "$B/odber?confirm=x")$(curl -s -o /dev/null -w '%{redirect_url}' "$B/tasks")$(curl -s -o /dev/null -w '%{redirect_url}' "$B/subscription?confirm=x")" "200 "
done
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-this-diary/"); expect "url_slash html: /about-this-diary/ redirects to /about-this-diary.html" "$code" "301 $B/about-this-diary.html"
check "url_slash html: /about-this-diary.html is the page and its canonical URL" 200 /about-this-diary.html "rel=\"canonical\" href=\"$B/about-this-diary.html\""
# 3.4.2 N34-1: a path with two leading slashes or a backslash is never redirected off the site, in every URL form
for mode in bez s html; do
  url_slash "$mode"; off=""
  for p in "//a//evil.example/x/" "/%5Cevil.example/x/" "/\\evil.example/x/"; do
    loc=$(curl -s --path-as-is -o /dev/null -w '%{redirect_url}' "$B$p")
    [ -z "$loc" ] || [ "${loc#"$B"/}" != "$loc" ] || off="$off $p -> $loc"
  done
  [ -z "$off" ] && echo "  ok     3.4.2 N34-1: url_slash $mode keeps every redirect on the site" || { echo "  CHYBA  3.4.2 N34-1: url_slash $mode redirects off the site:$off"; ERRORS=$((ERRORS+1)); }
done
# 3.7 N37-6: the English system words are system addresses in every form – /tasks.html is no page URL, like /ulohy.html; a
# page that holds the word from before 3.7 keeps following the setting like any other page
url_slash bez
expect "3.7 N37-6: url_slash bez never redirects /tasks.html or /subscription.html (as /ulohy.html)" \
  "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/tasks.html?token=testtoken123")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/subscription.html")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/ulohy.html?token=testtoken123")" "404 |404 |404 "
url_slash html
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, v_menu, zmeneno) VALUES ('Form 37', 'form', '<p>Our form page 37</p>', 1, 0, '$(site_time)')" > /dev/null
expect "3.7 N37-6: a page that holds /form keeps the url_slash form of a page" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/form")|$(curl -s "$B/form.html" | grep -c 'Our form page 37' || true)" "301 $B/form.html|1"
sq "DELETE FROM ka_stranky WHERE titulek = 'Form 37' AND seo_link = 'form'" > /dev/null
url_slash bez
