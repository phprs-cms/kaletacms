echo "== 2.12: share images drawn by the site"
if php -r 'exit(function_exists("imagecreatetruecolor") && function_exists("imagettftext") ? 0 : 1);'; then
OG_SHARE_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'share_image'")
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('share_image', 'share_image_auto')" > /dev/null # no site-wide sharing image, the generated ones on (the default)
mcp create_page '{"title":"Dřevěné schody na míru","slug":"drevene-schody","visible":true,"text":"<p>Schody.</p>"}' > /dev/null
OG_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'drevene-schody'")
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
OG_URL=$(grep -o 'property="og:image" content="[^"]*"' "$WORK/response" | head -1 | sed 's/.*content="//;s/"$//')
case "$OG_URL" in "$B"/og/[a-f0-9]*.png) echo "  ok     share images: a page without an image points og:image to /og/<hash>.png";; *) echo "  CHYBA  og:image of a page without an image: „$OG_URL“"; ERRORS=$((ERRORS+1));; esac
grep -q 'og:image:width" content="1200"' "$WORK/response" && grep -q 'twitter:card" content="summary_large_image"' "$WORK/response" && echo "  ok     share images: the size is announced and the Twitter card is the large one" || { echo "  CHYBA  og:image:width / twitter:card of the generated image"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/og.png" -w '%{http_code} %{content_type}' "$OG_URL"); expect "share images: the picture is served as PNG" "$code" "200 image/png"
expect "share images: the PNG is 1200×630" "$(php -r '$s = @getimagesize($argv[1]); echo $s ? $s[0] . "x" . $s[1] : "none";' "$WORK/og.png")" "1200x630"
curl -s -o /dev/null -D "$WORK/og.headers" "$OG_URL"; grep -qi '^Cache-Control: public, max-age=31536000' "$WORK/og.headers" && echo "  ok     share images: cached for a year (the address changes with the content)" || { echo "  CHYBA  Cache-Control of the picture"; ERRORS=$((ERRORS+1)); }
OG_HASH=${OG_URL##*/og/}; OG_HASH=${OG_HASH%.png}
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/og/${OG_HASH:1}0.png"); expect "share images: a tampered hash is 404 – nobody makes the site draw their own text" "$code" "404"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/og/$OG_HASH.jpg"); expect "share images: only the PNG address exists" "$code" "404"
mcp get_page "{\"id\":$OG_PAGE}" > "$WORK/response"; expect "MCP: get_page shows the generated address as share_image_generated" "$(mcp_value share_image_generated)" "$OG_URL"
sq "UPDATE ka_stranky SET titulek = 'Kamenné schody' WHERE ids = $OG_PAGE" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
OG_URL2=$(grep -o 'property="og:image" content="[^"]*"' "$WORK/response" | head -1 | sed 's/.*content="//;s/"$//')
[ "$OG_URL2" != "$OG_URL" ] && [[ "$OG_URL2" == "$B"/og/*.png ]] && echo "  ok     share images: a changed title is a new address (no stale copies at the social networks)" || { echo "  CHYBA  the address did not change with the title: $OG_URL2"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_stranky SET obrazek = 'media/2026/01/sdileni.jpg' WHERE ids = $OG_PAGE" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$WORK/response" && ! grep -q '/og/' "$WORK/response" && echo "  ok     share images: a page with its own image keeps it" || { echo "  CHYBA  a page's own share image was replaced"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_stranky SET obrazek = '' WHERE ids = $OG_PAGE; REPLACE INTO ka_nastaveni VALUES ('share_image_auto', '0')" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/drevene-schody"
! grep -q 'og:image' "$WORK/response" && grep -q 'twitter:card" content="summary"' "$WORK/response" && echo "  ok     share images: the setting off – no og:image, as before" || { echo "  CHYBA  og:image with the setting off"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o /dev/null -w '%{http_code}' "$OG_URL"); expect "share images: the setting off – the picture is not served either" "$code" "404"
mcp get_page "{\"id\":$OG_PAGE}" > "$WORK/response"; expect "MCP: get_page without share_image_generated when the setting is off" "$(mcp_value share_image_generated)" "null"
check "settings → SEO offers the switch" 200 "/admin.php?module=settings&tab=seo" 'name="share_image_auto"'
sq "DELETE FROM ka_nastaveni WHERE promenna = 'share_image_auto'" > /dev/null; [ -z "$OG_SHARE_BEFORE" ] || sq "REPLACE INTO ka_nastaveni VALUES ('share_image', '$OG_SHARE_BEFORE')" > /dev/null
mcp trash_page "{\"id\":$OG_PAGE}" > /dev/null
else echo "  skip   share images: the PHP used by the test has no GD – the image checks are skipped"; fi
