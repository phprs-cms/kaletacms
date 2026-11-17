echo "== 3.45 B: the guide to the office in the administration, the palette's quick actions (New quote posts), English values in update_settings"
B345_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
B345_ON=$(php -r '$l = array_filter(explode(",", $argv[1])); $l = $l === [] ? ["novinky", "poptavky", "statistika", "presmerovani", "claude"] : $l; echo implode(",", array_unique([...$l, "quotes", "applications"]));' -- "$B345_EXT")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$B345_ON')" > /dev/null
# the heading link of an office section opens its chapter in the same tab; the online articles keep the new tab
check "3.45 B: Quotes links to its chapter of the guide" 200 "/admin.php?module=quotes" 'action=guide&amp;chapter=quotes"'
expect "3.45 B: the chapter link stays in the tab (no target, no “opens in a new tab”)" "$(grep -o 'class="navod-odkaz" href="[^"]*chapter=quotes"[^>]*>' "$WORK/response" | grep -c 'target=')" "0"
check "3.45 B: Statistics keeps the online article" 200 "/admin.php?module=stats" 'kaletacms.com/'
check "3.45 B: the guide's index lists the chapters of the sections the administrator opens" 200 "/admin.php?action=guide" 'class="navod-kapitoly"'
expect "3.45 B: the index has Quotes, Applications, Customers, Pipeline and My work, and no guide link of its own" \
  "$(for c in quotes applications customers pipeline my-work; do grep -c "chapter=$c\"><strong>" "$WORK/response"; done | tr '\n' ' ')$(grep -c 'class="navod-odkaz"' "$WORK/response")" "1 1 1 1 1 0"
check "3.45 B: a chapter has its steps and opens its section" 200 "/admin.php?action=guide&chapter=quotes" 'class="navod-dalsi"'
grep -q 'admin.php?module=quotes"' "$WORK/response" && grep -q '<ol>' "$WORK/response" \
  && echo "  ok     3.45 B: the chapter lists the steps and links to Quotes" || { echo "  CHYBA  3.45 B: the chapter's steps or its section link"; ERRORS=$((ERRORS+1)); }
check "3.45 B: a chapter with an online article links to it" 200 "/admin.php?action=guide&chapter=customers" '/guide/customers" target="_blank"'
check "3.45 B: an unknown chapter is not found" 404 "/admin.php?action=guide&chapter=nothing"
# the palette's data on the dashboard: the quick actions of 3.34–3.42 and the chapters; New quote is a POST with the form token
check "3.45 B: the dashboard carries the palette with the form token" 200 "/admin.php" 'id="paleta-data"'
B345_PALETTE=$(php -r '$h = file_get_contents($argv[1]); preg_match("~<script type=\"application/json\" id=\"paleta-data\">(.*?)</script>~s", $h, $m); preg_match("~data-csrf=\"([a-f0-9]+)\"~", $h, $c);
  $d = json_decode($m[1] ?? "[]", true) ?: []; $u = fn (array $x): string => preg_replace("~^.*admin\.php\??~", "", $x["u"]);
  $post = array_values(array_filter($d, fn (array $x): bool => !empty($x["p"])));
  echo implode(" ", array_map($u, $post)), "|", count(array_filter($d, fn (array $x): bool => in_array($u($x), ["module=quotes&action=prices", "module=applications&action=board", "module=users&action=departments", "action=account&tab=org_chart", "action=account#absences", "action=guide&chapter=quotes"], true))), "|", $c[1] ?? "";' -- "$WORK/response")
expect "3.45 B: only New quote posts; Internal prices, the board, Departments, the org chart, absences and the chapter are there" "${B345_PALETTE%|*}" "module=quotes&action=create|6"
B345_DRAFTS=$(sq "SELECT COUNT(*) FROM ka_quotes WHERE status = 'draft'")
B345_CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?module=quotes&action=create" -d "_csrf=${B345_PALETTE##*|}")
expect "3.45 B: New quote from the palette (its URL and the token of data-csrf) makes a draft and opens its editor" \
  "$(echo "$B345_CODE" | grep -c '^302 .*action=edit')|$(( $(sq "SELECT COUNT(*) FROM ka_quotes WHERE status = 'draft'") - B345_DRAFTS ))" "1|1"
# update_settings over MCP: English values land as the stored Czech ones (also through the Czech tool name); the result is unchanged
B345_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B345_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B345_ADMIN, 'b345', 'full', 'all', SHA2('$B345_TOKEN', 256), '$(site_time)')" > /dev/null
B345_KEYS="dark_mode ai_crawlers url_slash cookies_mode share_buttons"
B345_KEEP=""; for b345_key in $B345_KEYS; do B345_KEEP="$B345_KEEP $b345_key=$(sq "SELECT COALESCE(MAX(hodnota), '-') FROM ka_nastaveni WHERE promenna = '$b345_key'")"; done
b345_settings() { mcp_call "$B345_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true) ?: []; echo json_encode($t["errors"] ?? $t["chyby"] ?? [], JSON_UNESCAPED_UNICODE);'; }
B345_ERR=$(b345_settings update_settings '{"settings":{"dark_mode":"dark","ai_crawlers":"block","url_slash":"with","cookies_mode":"built_in","share_buttons":"yes"}}')
expect "3.45 B: update_settings stores dark, block, with, built_in and yes as tmavy, zakazat, s, vestavena and 1" \
  "$B345_ERR|$(sq "SELECT GROUP_CONCAT(hodnota ORDER BY promenna SEPARATOR ',') FROM ka_nastaveni WHERE promenna IN ('dark_mode', 'ai_crawlers', 'url_slash', 'cookies_mode', 'share_buttons')")" "[]|zakazat,vestavena,tmavy,1,s"
B345_ERR=$(b345_settings uprav_nastaveni '{"nastaveni":{"dark_mode":"off","url_slash":"bez","ai_crawlers":"povolit"}}')
expect "3.45 B: the Czech tool name takes an English value too; Czech values keep working" \
  "$B345_ERR|$(sq "SELECT GROUP_CONCAT(hodnota ORDER BY promenna SEPARATOR ',') FROM ka_nastaveni WHERE promenna IN ('dark_mode', 'ai_crawlers', 'url_slash')")" "[]|povolit,vypnuto,bez"
B345_ERR=$(b345_settings update_settings '{"settings":{"dark_mode":"midnight"}}')
expect "3.45 B: an unknown value is still refused" "$(echo "$B345_ERR" | grep -c dark_mode)" "1"
for b345_pair in $B345_KEEP; do # back as it was: a setting that was not stored is removed again
  if [ "${b345_pair#*=}" = "-" ]; then sq "DELETE FROM ka_nastaveni WHERE promenna = '${b345_pair%%=*}'" > /dev/null
  else sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('${b345_pair%%=*}', '${b345_pair#*=}')" > /dev/null; fi
done
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'b345'" > /dev/null
if [ -n "$B345_EXT" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$B345_EXT')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'extensions'" > /dev/null; fi
rm -f "$WORK"/web/storage/cache/stranky/*.html
