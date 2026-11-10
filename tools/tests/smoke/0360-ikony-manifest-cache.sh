echo "== ikony, manifest, cache"
expect "favicon.ico bez ikony nevygeneruje stránku 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/favicon.ico")" 204
curl -s -o "$WORK/response" "$B/manifest.webmanifest"; grep -q '"start_url"' "$WORK/response" && echo "  ok     manifest webu" || { echo "  CHYBA  manifest"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/novinky"
expect "odkaz s utm parametry jde z cache" "$(curl -s -o /dev/null -D - "$B/novinky?utm_source=newsletter&fbclid=x" | grep -ci '^x-cache: kaleta')" 1
ETAG=$(curl -s -o /dev/null -D - "$B/novinky" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')
expect "stránka z cache odpoví 304 na shodný ETag" "$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$B/novinky")" 304

