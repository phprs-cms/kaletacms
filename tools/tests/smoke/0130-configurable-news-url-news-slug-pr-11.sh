echo "== configurable news URL (news_slug, PR #11)"
slug_q() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
slug_code() { rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B$1"; }
mcp create_page '{"title":"Blog"}' > /dev/null
BLOGPAGE=$(slug_q "SELECT ids FROM ka_stranky WHERE seo_link = 'blog'")
mcp trash_page "{\"id\":$BLOGPAGE}" > /dev/null
mcp update_settings '{"settings":{"news_slug":"blog"}}' > /dev/null
expect "news_slug: set over MCP (a page in the trash does not block it)" "$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug'")" "blog"
expect "news_slug: /blog lists the news, the item and its .md work" "$(slug_code /blog)|$(slug_code /blog/vitejte-v-kalete)|$(slug_code /blog/vitejte-v-kalete.md)" "200 |200 |200 "
expect "news_slug: /novinky and its items redirect permanently to /blog" "$(slug_code /novinky)|$(slug_code /novinky/vitejte-v-kalete)" "301 $B/blog|301 $B/blog/vitejte-v-kalete"
expect "news_slug: the listing and the RSS feed link to /blog" "$(curl -s "$B/blog" | grep -c '/blog/vitejte-v-kalete"')|$(curl -s "$B/rss.xml" | grep -c '/blog/vitejte-v-kalete</link>')" "1|1"
# 3.18.2: news_title gives the list its heading (Blog to go with /blog); empty = Novinky again
mcp update_settings '{"settings":{"news_title":"Blog firmy"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "news_title: the heading and the title of the news list" "$(curl -s "$B/blog" | grep -c '<h1>Blog firmy</h1>')|$(curl -s "$B/blog" | grep -c '<title>Blog firmy')" "1|1"
mcp update_settings '{"settings":{"news_title":""}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "news_title: empty = Novinky" "$(curl -s "$B/blog" | grep -c '<h1>Novinky</h1>')" "1"
mcp uloz_presmerovani '{"z":"/blog/old-wp-post","na":"/z-html"}' > /dev/null
expect "news_slug: a stored redirect under /blog fires (as a WordPress import writes it)" "$(slug_code /blog/old-wp-post)" "301 $B/z-html"
mcp restore_from_trash "{\"type\":\"page\",\"id\":$BLOGPAGE}" > "$WORK/response"
expect "news_slug: a page restored from the trash onto the news URL gets a free one" "$(slug_q "SELECT CONCAT(seo_link, '|', smazano IS NULL) FROM ka_stranky WHERE ids = $BLOGPAGE")|$(grep -c '/blog-2' "$WORK/response")" "blog-2|1|1"
slug_q "DELETE FROM ka_stranky WHERE ids = $BLOGPAGE"
mcp update_settings '{"settings":{"news_slug":"oauth"}}' > "$WORK/response"
mcp update_settings '{"settings":{"news_slug":"Blog Post"}}' >> "$WORK/response"
expect "news_slug: oauth and a badly formed slug are refused over MCP, with the reason in English" "$(grep -c 'This URL is used by the system' "$WORK/response")|$(grep -c 'Use only lowercase letters' "$WORK/response")|$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug'")" "1|1|blog"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' -- "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post" -d news_slug=oauth
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=general"
expect "news_slug: oauth is refused in the admin with the reason" "$(grep -c 'Adresa novinek.*Tuto adresu používá systém' "$WORK/response")|$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug'")" "1|blog"
# a value written straight to the database (an old import, a direct edit) is ignored: OAuth and MCP keep working
slug_q "UPDATE ka_nastaveni SET hodnota = 'oauth' WHERE promenna = 'news_slug'"
expect "news_slug: a stored oauth is ignored – OAuth, MCP and /novinky keep working" "$(curl -s -X POST "$B/oauth/token" -d grant_type=refresh_token -d refresh_token=x -d client_id=x | grep -c '"error":"invalid_client"')|$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?client_id=x")|$(mcp list_pages '{}' | grep -c '"result"')|$(slug_code /novinky)" "1|400|1|200 "
slug_q "UPDATE ka_nastaveni SET hodnota = 'blog' WHERE promenna = 'news_slug'"
mcp update_settings '{"settings":{"news_slug":"magazin"}}' > /dev/null
expect "news_slug: after blog → magazin the old URLs redirect, the stored redirect still fires" "$(slug_code /blog/vitejte-v-kalete)|$(slug_code /blog)|$(slug_code /magazin/vitejte-v-kalete)|$(slug_code /blog/old-wp-post)" "301 $B/magazin/vitejte-v-kalete|301 $B/magazin|200 |301 $B/z-html"
mcp update_settings '{"settings":{"news_slug":""}}' > /dev/null
expect "news_slug: back to empty, both earlier slugs redirect to /novinky" "$(slug_code /blog/vitejte-v-kalete)|$(slug_code /magazin)|$(slug_code /novinky/vitejte-v-kalete)|$(slug_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_slug_previous'")" "301 $B/novinky/vitejte-v-kalete|301 $B/novinky|200 |magazin,blog"
slug_q "DELETE FROM ka_presmerovani WHERE z_adresy LIKE '%old-wp-post'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('news_slug', 'news_slug_previous')"; rm -f "$WORK"/web/storage/cache/stranky/*.html

