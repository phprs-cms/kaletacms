echo "== 3.6: pattern redirects, gone (410) and many redirects at once (INV-10)"
mcp save_redirects '{"redirects":[{"from":"/stary-blog/*","to":"/novinky/*"},{"from":"/stary-blog/zvlastni","to":"/z-html"},{"from":"/old/*","to":"/*"},{"from":"/ven/*","to":"https://example.org/*","code":302},{"from":"/spam/*","to":"","code":410},{"from":"/spam-jedna","code":410},{"from":"/kruh/*","to":"/kruh/x/*"},{"from":"/zly/*","to":"https://*.evil.example/"},{"from":"/stary-blog/*","to":"/jinam/*"}]}' > "$WORK/response"
expect "3.6 MCP save_redirects: per-row results (loop, a host from the visitor and a duplicate refused)" \
  "$(mcp_value added)|$(mcp_value refused)|$(mcp_value results 6 status)|$(mcp_value results 7 status)|$(mcp_value results 8 reason)" \
  "6|3|refused|refused|The same old address is in an earlier row – the first one counts."
rcode() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B$1"; }
expect "3.6: a pattern keeps the rest of the path, an exact redirect wins over it" "$(rcode /stary-blog/vitejte-v-kalete)|$(rcode /stary-blog/zvlastni)|$(rcode /old/kontakt)" \
  "301 $B/novinky/vitejte-v-kalete|301 $B/z-html|301 $B/kontakt"
expect "3.6: an absolute target the administrator saved keeps its host, the rest of the path follows" "$(rcode /ven/cenik/2026)" "302 https://example.org/cenik/2026"
# what a visitor types never decides the host: // and encoded slashes give empty segments, so no redirect at all (a backslash or
# a dot segment the server refuses before the site; the unit tests cover them), other characters are encoded into the path
rto() { curl -s -o /dev/null -w '%{redirect_url}' "$B$1"; }
expect "3.6: a pattern never forms an off-site redirect from visitor input" "$(rto '/old//evil.example')|$(rto '/old/%2F%2Fevil.example')|$(rto '/old/https:%2F%2Fevil.example')|$(rto '/ven/%2F%2Fevil.example')" "|||"
location=$(curl -s -o /dev/null -D - "$B/old/%40evil.example" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p')
[[ "$location" == /* && "$location" != //* && "$location" == *%40evil.example ]] && echo "  ok     3.6: visitor input stays an encoded path on this site ($location)" || { echo "  CHYBA  3.6 Location for visitor input: $location"; ERRORS=$((ERRORS+1)); }
expect "3.6: gone (410) for a pattern and an exact address, with the not-found page" "$(rcode /spam/produkty/levne)|$(rcode /spam-jedna)|$(curl -s -o "$WORK/gone.html" "$B/spam/x"; grep -q 'noindex' "$WORK/gone.html" && echo 1 || echo 0)" "410 |410 |1"
expect "3.6: refused rules were not saved" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy IN ('kruh/*', 'zly/*') OR na_adresu LIKE 'jinam%'")" 0
mcp save_redirects '{"redirects":[{"from":"/nanecisto/*","to":"/z-html"}],"dry_run":true}' > "$WORK/response"
expect "3.6 MCP save_redirects: dry_run says what would happen and saves nothing" "$(mcp_value dry_run)|$(mcp_value added)|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'nanecisto/*'")" "1|1|0"
# the admin form: a pattern, a refused loop and a 410 without a target
check "3.6: the redirects screen offers the CSV import and 410" 200 "/admin.php?module=redirects" 'name="soubor"'
TOKEN=$(csrf)
redirect_form() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" "$@"; }
redirect_form -d z_adresy=/katalog/* -d na_adresu=/z-html -d typ=301
redirect_form -d z_adresy=/novinky-old/* -d na_adresu=/stary-blog/* -d typ=301
redirect_form -d z_adresy=/smazano -d na_adresu= -d typ=410
expect "3.6 admin: a pattern and a 410 saved, a rule closing a loop refused" "$(rcode /katalog/stoly)|$(rcode /smazano)|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'novinky-old/*'")" "301 $B/z-html|410 |1"
redirect_form -d z_adresy=/novinky/x-stary -d na_adresu=/stary-blog/x-stary -d typ=301
expect "3.6 admin: an exact rule into a pattern that leads back is refused" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'novinky/x-stary'")|$(rcode /stary-blog/vitejte-v-kalete)" "0|301 $B/novinky/vitejte-v-kalete"
# CSV in the admin: the preview changes nothing, saving adds; the Redirection plugin export with a simple regular expression
printf 'source,target,regex,code,type,hits,title,status\n/csv-stara,/kontakt,0,301,url,0,,enabled\n"^/csv-blog/(.*)$",/novinky/$1,1,302,url,0,,enabled\n/csv-gone,,0,410,url,0,,enabled\n/csv-off,/kontakt,0,301,url,0,,disabled\n/csv-stara,/z-html,0,301,url,0,,enabled\n' > "$WORK/redirects.csv"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=redirects&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/redirects.csv;type=text/csv"
contains -q 'id="nahled-importu"' "$WORK/response" && contains -q 'Řádků: 5' "$WORK/response" && contains -q 'Pravidlo je v souboru vypnuté' "$WORK/response" \
  && [ "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'csv-%'")" = 0 ] && echo "  ok     3.6 CSV: the preview lists every row and saves nothing" || { echo "  CHYBA  3.6 CSV preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=import_save" -d "_csrf=$TOKEN" --data-urlencode "csv@$WORK/redirects.csv"
expect "3.6 CSV: saved – exact, the converted pattern (302) and a 410; the duplicate and the disabled row not" \
  "$(rcode /csv-stara)|$(rcode /csv-blog/vitejte-v-kalete)|$(rcode /csv-gone)|$(rcode /csv-off)" "301 $B/kontakt|302 $B/novinky/vitejte-v-kalete|410 |404 "
# sizes the moved sites need: 500 rows in one MCP call, 5,000 rows in one CSV, and the site still answers fast
php -r '$r = []; for ($i = 1; $i <= 500; $i++) { $r[] = ["from" => "/hromadne/stranka-$i", "to" => "/kontakt"]; } echo json_encode(["redirects" => $r]);' > "$WORK/batch.json"
mcp save_redirects "$(cat "$WORK/batch.json")" > "$WORK/response"
expect "3.6 MCP save_redirects: 500 rows in one call" "$(mcp_value added)|$(mcp_value refused)" "500|0"
php -r 'echo "from,to,code\n"; for ($i = 1; $i <= 5000; $i++) { echo "/velky-import/clanek-$i,/novinky/vitejte-v-kalete,301\n"; }' > "$WORK/big.csv"
started=$(php -r 'echo microtime(true);')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=import_save" -d "_csrf=$TOKEN" --data-urlencode "csv@$WORK/big.csv"
echo "  info   3.6 CSV: 5,000 rows saved in $(php -r 'printf("%.1f", microtime(true) - (float) $argv[1]);' "$started") s"
expect "3.6 CSV: 5,000 rows in one import" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'velky-import/%'")" 5000
expect "3.6: with thousands of rules the exact one and a pattern still answer" "$(rcode /velky-import/clanek-4321)|$(rcode /stary-blog/vitejte-v-kalete)" "301 $B/novinky/vitejte-v-kalete|301 $B/novinky/vitejte-v-kalete"
sq "DELETE FROM ka_presmerovani WHERE z_adresy LIKE 'velky-import/%' OR z_adresy LIKE 'hromadne/%' OR z_adresy IN ('old/*', 'katalog/*', 'ven/*')"

