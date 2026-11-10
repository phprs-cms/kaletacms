echo "== 2.11 F1: screen mode for a reception"
expect "screen: off → 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$(printf 'a%.0s' $(seq 1 32))")" "404"
mcp update_settings '{"settings":{"screen_collections":["neexistuje"]}}' > "$WORK/response"
contains -q 'Unknown collections: neexistuje' "$WORK/response" && echo "  ok     MCP: the screen shows only collections that exist" || { echo "  CHYBA  screen_collections validation"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"screen_mode":1,"screen_seconds":"7","screen_collections":["preset-kurzy"],"screen_hours":1,"screen_clock":1}}' > "$WORK/response"
SCREEN_SECRET=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_secret'")
expect "screen: switching the mode on creates the secret part of the address" "${#SCREEN_SECRET}" "32"
contains -q 'screen\\":{\\"on\\":true,\\"seconds\\":7,\\"collections\\":\[\\"preset-kurzy\\"\]' "$WORK/response" && ! contains -q 'screen_secret' "$WORK/response" && ! contains -q "$SCREEN_SECRET" "$WORK/response" \
  && echo "  ok     MCP: update_settings switches the screen on and reports it without the secret" || { echo "  CHYBA  update_settings screen"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "screen: on with the right secret → 200 with noindex" 200 "/screen/$SCREEN_SECRET" '<meta name="robots" content="noindex, nofollow">'
grep -q 'obrazovka-slide obrazovka-news' "$WORK/response" && grep -q 'Kurz svařování' "$WORK/response" && ! grep -q 'Kurz loňský' "$WORK/response" && grep -q 'obrazovka-hours' "$WORK/response" && grep -q 'id="obrazovka-hodiny"' "$WORK/response" \
  && echo "  ok     screen: slides of the news, the upcoming course only, today's opening hours and the clock" || { echo "  CHYBA  screen slides"; grep -o 'obrazovka-[a-z]*' "$WORK/response" | sort | uniq -c; ERRORS=$((ERRORS+1)); }
grep -q '<noscript><meta http-equiv="refresh" content="7; url=/screen/'"$SCREEN_SECRET"'?s=1">' "$WORK/response" && grep -q 'setInterval(function(){i=(i+1)%n;show(i)},7000)' "$WORK/response" && grep -q -e '--ka-barva-primarni:' "$WORK/response" \
  && echo "  ok     screen: rotates every 7 seconds with the script and by a meta refresh without it, in the site's design tokens" || { echo "  CHYBA  screen rotation"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o /dev/null "$B/screen/$SCREEN_SECRET?s=1"
grep -qi '^X-Robots-Tag: noindex' "$WORK/headers" && grep -qi '^Cache-Control: no-store' "$WORK/headers" && ! grep -qi '^Set-Cookie' "$WORK/headers" && echo "  ok     screen: noindex and no-store headers, no cookies" || { echo "  CHYBA  screen headers"; cat "$WORK/headers"; ERRORS=$((ERRORS+1)); }
expect "screen: a wrong secret → 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$(echo "$SCREEN_SECRET" | tr '0-9a-f' '1-9a-f0')")" "404"
check "screen: the admin shows the full address with a copy button" 200 "/admin.php?module=settings&tab=general" "screen/$SCREEN_SECRET"
grep -q 'data-kopirovat="#screen-url"' "$WORK/response" && grep -q 'name="screen_collections\[\]" value="preset-kurzy" checked' "$WORK/response" && echo "  ok     screen: the copy button and the chosen collection" || { echo "  CHYBA  screen admin form"; ERRORS=$((ERRORS+1)); }
# the "new address" button: the form is posted as a browser does, the old address stops working
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post" -d novy_token_obrazovka=1
SCREEN_SECRET_2=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_secret'")
[ "${#SCREEN_SECRET_2}" = 32 ] && [ "$SCREEN_SECRET_2" != "$SCREEN_SECRET" ] && echo "  ok     screen: the button creates a new address" || { echo "  CHYBA  new screen address"; ERRORS=$((ERRORS+1)); }
expect "screen: the old address stops working, the new one works, the seconds stay" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET")|$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET_2")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_seconds'")" "404|200|7"
mcp update_settings '{"settings":{"screen_mode":0}}' > /dev/null
expect "screen: switched off → 404 even with the right secret" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET_2")" "404"

