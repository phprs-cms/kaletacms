echo "== 2.14: password-protected pages"
mcp vytvor_stranku '{"titulek":"Partner prices","adresa":"partner-ceny","text":"<p>Secret partner price 42</p>","zobrazit":true}' > "$WORK/response"; mcp_text; LOCK_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_stranky SET heslo_hash = '$(php -r 'echo password_hash("partner-2026", PASSWORD_DEFAULT);')' WHERE ids = $LOCK_PAGE" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
VJAR="$WORK/visitor-jar"; rm -f "$VJAR"
curl -s -c "$VJAR" -o "$WORK/response" "$B/partner-ceny"
contains -q 'ka-heslo-stranky' "$WORK/response" && ! contains -q 'Secret partner price' "$WORK/response" && contains -q 'noindex' "$WORK/response" \
  && echo "  ok     page lock: a visitor sees the password form, not the content, and the page is noindex" || { echo "  CHYBA  page lock form"; ERRORS=$((ERRORS+1)); }
expect "page lock: a wrong password is refused" "$(curl -s -b "$VJAR" -c "$VJAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=wrong)" "403"
expect "page lock: the right password opens the page for this visitor" "$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=partner-2026)|$(curl -s -b "$VJAR" "$B/partner-ceny" | grep -c 'Secret partner price')|$(curl -s "$B/partner-ceny" | grep -c 'Secret partner price')" "303|1|0"
# 3.3.3 (N58): past the page's cap of wrong passwords from all addresses only wrong ones are refused – the right one still opens
LOCK_FILES=$(php -r 'foreach ([0, 1] as $n) { echo $argv[1], "/storage/cache/firewall/page-lock-all-", intdiv(time(), 900) + $n, "-", substr(hash("sha256", "page-" . $argv[2]), 0, 24), "\n"; }' -- "$WORK/web" "$LOCK_PAGE")
mkdir -p "$WORK/web/storage/cache/firewall"; for f in $LOCK_FILES; do printf '%0.s.' $(seq 1 120) > "$f"; done
rm -f "$VJAR"; curl -s -c "$VJAR" -o /dev/null "$B/partner-ceny"
expect "3.3.3 page lock: past the page's cap a wrong password is refused as too many attempts, the right one opens the page" \
  "$(curl -s -b "$VJAR" -c "$VJAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=wrong-again)|$(grep -c 'Příliš mnoho pokusů' "$WORK/response")|$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' -X POST "$B/partner-ceny" --data-urlencode ka_heslo_stranky=partner-2026)|$(curl -s -b "$VJAR" "$B/partner-ceny" | grep -c 'Secret partner price')" "403|1|303|1"
rm -f $LOCK_FILES
! ls "$WORK"/web/storage/cache/stranky/ 2>/dev/null | xargs -I{} grep -l 'Secret partner price' "$WORK/web/storage/cache/stranky/{}" 2>/dev/null | grep -q . && ! curl -s "$B/sitemap.xml" | contains 'partner-ceny' && ! curl -s "$B/hledani?q=partner" | contains 'Secret partner' \
  && echo "  ok     page lock: never in the page cache, the sitemap or the site search" || { echo "  CHYBA  page lock leaks"; ERRORS=$((ERRORS+1)); }
mcp nacti_stranku "{\"id\":$LOCK_PAGE}" > "$WORK/response"; mcp_text
contains -q '"password_protected":true' "$WORK/text" && ! contains -q 'heslo_hash\|\$2y\$' "$WORK/response" && echo "  ok     page lock: Claude sees that the page is protected, never the hash" || { echo "  CHYBA  get_page lock flag"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$LOCK_PAGE"
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$(csrf)" -d ids=$LOCK_PAGE -d titulek=Partner+prices -d seo_link=partner-ceny -d zobrazit=1 -d heslo_zrusit=1 --data-urlencode "text=<p>Secret partner price 42</p>"
expect "page lock: removing the password in the admin makes the page public" "$(sq "SELECT heslo_hash IS NULL FROM ka_stranky WHERE ids = $LOCK_PAGE")|$(curl -s "$B/partner-ceny" | grep -c 'Secret partner price')" "1|1"
