echo "== Claude (MCP): builder"
API_TOKEN="kaleta_$(printf 'a%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$API_TOKEN")', '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'"
# a grep that reads the whole input: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
contains() { grep "$@" > /dev/null; }
mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q '"name":"create_page"' "$WORK/response" && ! grep -q '"name":"vytvor_stranku"' "$WORK/response" && grep -q '"title":{' "$WORK/response" && echo "  ok     MCP: nástroje s anglickými názvy a parametry" || { echo "  CHYBA  MCP tools/list anglicky"; ERRORS=$((ERRORS+1)); }
# 3.8 (Connectors Directory): every tool has a title (top level = annotations.title), the hints are by the English name
php -r '$t = json_decode(file_get_contents($argv[1]), true)["result"]["tools"]; $bad = array_filter($t, fn ($x) => !is_string($x["title"] ?? null) || $x["title"] === "" || $x["title"] !== ($x["annotations"]["title"] ?? null) || array_slice(array_keys($x), 0, 2) !== ["name", "title"]);
  $a = array_column($t, "annotations", "name"); exit($bad === [] && $a["upload_file"]["openWorldHint"] === true && $a["import_website"]["openWorldHint"] === true && $a["list_pages"]["openWorldHint"] === false
  && ($a["save_build"]["idempotentHint"] ?? null) === true && !isset($a["publish_build"]["idempotentHint"]) && $a["list_pages"]["title"] === "List pages" ? 0 : 1);' "$WORK/response" \
  && echo "  ok     3.8 MCP: every tool has a title, open-world and idempotent hints by the English name" || { echo "  CHYBA  3.8 MCP titles and hints"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' > "$WORK/initialize.json"
php -r '$i = json_decode(file_get_contents($argv[1]), true)["result"]; $s = $i["serverInfo"]; exit($i["protocolVersion"] === "2025-06-18" && str_starts_with($s["name"], "Kaleta – ") && $s["title"] === "Kaleta" && $s["websiteUrl"] === "https://kaletacms.com"
  && $s["icons"][0]["src"] === $argv[2] . "/image/kaleta-znacka.svg" && $s["icons"][1]["sizes"] === ["180x180"] ? 0 : 1);' "$WORK/initialize.json" "$B" \
  && [ "$(curl -s -o /dev/null -w '%{http_code}' "$B/image/kaleta-znacka-180.png")" = 200 ] \
  && echo "  ok     3.8 MCP: initialize names the server (title Kaleta, websiteUrl, icons the site serves)" || { echo "  CHYBA  3.8 MCP serverInfo"; head -c 400 "$WORK/initialize.json"; ERRORS=$((ERRORS+1)); }
mcp list_pages '{}' > "$WORK/response"; grep -q 'title\\":' "$WORK/response" && grep -q 'in_menu\\":' "$WORK/response" && echo "  ok     MCP: anglický nástroj vrací anglické klíče" || { echo "  CHYBA  MCP list_pages"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp seznam_stranek '{}' > "$WORK/response"; grep -q 'titulek\\":' "$WORK/response" && echo "  ok     MCP: český název funguje dál jako skrytý alias" || { echo "  CHYBA  MCP český alias"; ERRORS=$((ERRORS+1)); }
mcp get_page '{"id":99999}' > "$WORK/response"; grep -q 'The page does not exist. Use list_pages.' "$WORK/response" && echo "  ok     MCP: chyba anglického nástroje anglicky" || { echo "  CHYBA  MCP anglická chyba"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# #12 / PR #13: the server's own errors are English; the 401 keeps the WWW-Authenticate header Claude discovers OAuth by
code=$(curl -s -D "$WORK/headers" -o "$WORK/response" -w '%{http_code}' -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
expect "MCP without a token: 401 in English with the OAuth discovery header" "$code|$(grep -c '"error":"The token is invalid or missing."' "$WORK/response")|$(grep -ci '^www-authenticate: Bearer resource_metadata="http.*/.well-known/oauth-protected-resource"' "$WORK/headers")" "401|1|1"
expect "MCP: invalid JSON and an unknown method are answered in English" "$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d 'nope' | grep -c '"Invalid JSON."')|$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"foo/bar"}' | grep -c 'Unknown method: foo')" "1|1"
mcp update_settings '{"settings":{"home_page":999999,"social_facebook":"javascript:alert(1)","site_email":"x@example.com"}}' > "$WORK/response"
expect "MCP: update_settings errors in English (#12)" "$(grep -c 'Only a visible page can be the home page.' "$WORK/response")|$(grep -c 'Invalid value.' "$WORK/response")|$(grep -c 'cannot be changed through the Claude connection' "$WORK/response")|$(grep -cE 'Neplatn|Tohle nastaven|zveřejněná' "$WORK/response")" "1|1|1|0"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | contains 'build_from_html' && echo "  ok     MCP: pokyny serveru s anglickými názvy" || { echo "  CHYBA  MCP pokyny"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; grep -q 'knihovna' "$WORK/response" && grep -q 'ka-mezera' "$WORK/response" && echo "  ok     MCP: schéma builderu" || { echo "  CHYBA  MCP stavba_schema"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_z_html '{"titulek":"Z HTML","html":"<style>.uvod-x { padding-block: var(--ka-mezera-2xl); } .uvod-x h1 { color: red }</style><header class=\"uvod-x\"><div class=\"container\"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class=\"btn\" href=\"/kontakt\">Kontakt</a></div></header><form><input></form>"}' > "$WORK/response"
grep -q 'koncept' "$WORK/response" && grep -q 'Formul' "$WORK/response" && grep -q 'vynech.*btn' "$WORK/response" && echo "  ok     MCP: HTML převedeno na koncept stavby s hlášením (i formulář)" || { echo "  CHYBA  MCP stavba_z_html"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'z-html'")
expect "MCP: nová stránka zůstává skrytá a bez publikované stavby" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba IS NULL, '/', stavba_koncept LIKE '%od Clauda%') FROM ka_stranky WHERE ids = $IDZ")" "0/1/1"
expect "MCP: třída z <style> uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT css FROM ka_tridy WHERE nazev = 'uvod-x'")" "padding-block: var(--ka-mezera-2xl);"
mcp vloz_sekci "{\"id\":$IDZ,\"sekce\":\"faq\"}" > /dev/null
mcp publikuj_stavbu "{\"id\":$IDZ}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDZ"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h1>Stránka od Clauda</h1>' "$WORK/response" && grep -q 'class="uvod-x"' "$WORK/response" && ! grep -q 'container' "$WORK/response" && grep -q '"FAQPage"' "$WORK/response" && echo "  ok     MCP: publikovaná stránka od Clauda na webu" || { echo "  CHYBA  MCP publikování"; ERRORS=$((ERRORS+1)); }
mcp uprav_design_system '{"ds":{"barvy":{"primarni":"#0f766e"},"zaobleni":"l"}}' > "$WORK/response"; mcp publish_look '{}' > /dev/null; grep -q 'citelnost' "$WORK/response" && echo "  ok     MCP: úprava design systému" || { echo "  CHYBA  MCP uprav_design_system"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "design systém z MCP je na webu" 200 / 'ka-barva-primarni: #0f766e'
check "design systém z MCP zachoval ostatní barvy" 200 / 'ka-barva-plocha: #f5f6f8'
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"tmavy","tmavy_prepinac":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'data-tmavy data-tema="tmavy"' "$WORK/response" && grep -q 'data-tema-volba="svetly"' "$WORK/response" && grep -q 'localStorage.getItem(.ka-tema.)' "$WORK/response" && grep -q 'data-tema=\\"tmavy\\"\]\|data-tema="tmavy"\] {' "$WORK/response" \
    && echo "  ok     tmavý vzhled vždy a přepínač vzhledu pro návštěvníky (i přes MCP)" || { echo "  CHYBA  tmavý režim a přepínač vzhledu"; ERRORS=$((ERRORS+1)); }
grep -q 'class="ka-jazyky-vyber ka-tema" role="group"' "$WORK/response" && ! grep -q '<nav class="ka-jazyky-vyber ka-tema"' "$WORK/response" \
  && echo "  ok     3.5 (UXP-06): the appearance switcher is a labelled group, not a navigation inside the navigation" || { echo "  CHYBA  3.5: appearance switcher markup"; ERRORS=$((ERRORS+1)); }
# 3.6 (UXP-01): the dark block carries a primary, secondary and text on primary of its own – derived from the light ones
# (until 3.5 only text, background and surface, so the light primary #0f766e stayed on the dark page); a chosen one wins
darkblock() { awk '/^:root\[data-tmavy\]\[data-tema="tmavy"\] \{/{on=1} on{print} on&&/^\}/{exit}' "$WORK/response"; }
darkblock > "$WORK/dark.css"
contains -q -- '--ka-barva-primarni: #' "$WORK/dark.css" && contains -q -- '--ka-barva-sekundarni: #' "$WORK/dark.css" && contains -q -- '--ka-barva-na-primarni: #' "$WORK/dark.css" && ! contains -q -- '--ka-barva-primarni: #0f766e' "$WORK/dark.css" \
  && echo "  ok     3.6 (UXP-01): dark mode has its own readable primary, secondary and button text" || { echo "  CHYBA  3.6: the dark block has no primary of its own"; cat "$WORK/dark.css"; ERRORS=$((ERRORS+1)); }
mcp update_design_system '{"design":{"barvy_tmave":{"primarni":"#ffd7a8"}}}' > "$WORK/response"; mcp publish_look '{}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
contains -q 'readability_dark\\":' "$WORK/response" && contains -q 'dark_palette\\":' "$WORK/response" && contains -q 'primarni\\":\\"#ffd7a8' "$WORK/response" \
  && echo "  ok     3.6: update_design_system sets a dark primary and answers with the dark palette and its readability" || { echo "  CHYBA  3.6: update_design_system dark colours"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/"; darkblock > "$WORK/dark.css"
contains -q -- '--ka-barva-primarni: #ffd7a8' "$WORK/dark.css" && echo "  ok     3.6: the chosen dark primary is on the site" || { echo "  CHYBA  3.6: the chosen dark primary is not on the site"; ERRORS=$((ERRORS+1)); }
mcp update_design_system '{"design":{"barvy_tmave":{"primarni":""}}}' > /dev/null; mcp publish_look '{}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; darkblock > "$WORK/dark.css"
contains -q -- '--ka-barva-primarni: #' "$WORK/dark.css" && ! contains -q -- '--ka-barva-primarni: #ffd7a8' "$WORK/dark.css" && echo "  ok     3.6: an empty dark primary is automatic again" || { echo "  CHYBA  3.6: the dark primary does not return to automatic"; ERRORS=$((ERRORS+1)); }
# Site appearance: "automatic" ticked drops a picked dark primary; a hard-to-read dark pair warns on saving
check "3.6: Site appearance lists the dark mode readability" 200 "/admin.php?module=appearance" 'data-kontrasty-tmave'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=appearance&action=preview" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy_tmave][primarni]=#000000' -d 'ds[tmave_auto][]=primarni'
contains -q '"kontrasty_tmave"' "$WORK/response" && ! contains -q '"primarni":"#000000"' "$WORK/response" && echo "  ok     3.6: the appearance preview derives a dark primary left on automatic" || { echo "  CHYBA  3.6: appearance preview of dark colours"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" -d dark_mode=tmavy -d theme_switcher=1 --data-urlencode 'ds[barvy_tmave][primarni]=#16181d'
check "3.6: saving a hard-to-read dark colour warns" 200 "/admin.php?module=appearance" 'Tmavý režim: některé dvojice barev jsou špatně čitelné'
mcp discard_look '{}' > /dev/null
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"vypnuto","tmavy_prepinac":"0"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

