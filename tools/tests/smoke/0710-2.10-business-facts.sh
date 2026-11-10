echo "== 2.10: business facts"
mcp save_fact '{"key":"projects","label":"Projects","type":"number","value":"1500"}' > "$WORK/response"
contains -q 'fact.projects' "$WORK/response" && echo "  ok     facts: Claude creates a fact" || { echo "  CHYBA  save_fact"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"founded","label":"Founded","type":"year","value":"2004","schema_property":"foundingDate"}' > /dev/null
mcp save_fact '{"key":"founded","value":"long ago"}' > "$WORK/response"
contains -q 'does not fit the type' "$WORK/response" && echo "  ok     facts: a value that does not fit the type is refused" || { echo "  CHYBA  fakt nesprávného typu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Fakta test","slug":"fakta-test","visible":true,"text":"<p>Máme za sebou {{fact.projects}} zakázek od roku {{ fact.founded }}.</p><p>Loni jsme dokončili 1500 zakázek.</p><p>{{fact.neexistuje}}</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fakta-test"
grep -qE '1(.|..)500 zakázek od roku 2004' "$WORK/response" && ! grep -q '{{' "$WORK/response" && echo "  ok     facts: tokens filled in on the page (number with the thousands separator)" || { echo "  CHYBA  fakta se na stránce nedoplnila"; grep -o 'Máme za sebou[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q '"foundingDate":"2004"' "$WORK/response" && echo "  ok     facts: a fact with a schema property is in the structured data" || { echo "  CHYBA  foundingDate ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Fakta dokumentace","slug":"fakta-dokumentace","visible":true,"text":"<p>Napište <code>{{fact.projects}}</code> do textu.</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fakta-dokumentace"
grep -q '<code>{{fact.projects}}</code>' "$WORK/response" && echo "  ok     facts: a token inside <code> stays as written (documentation)" || { echo "  CHYBA  značka v <code> se doplnila"; ERRORS=$((ERRORS+1)); }
# 3.3.2 (N26): a text fact "javascript:…" filled into a link is checked like any other link
# 3.3.3 (N50): saving such a fact is refused; one stored before (here straight in the database) is still caught when filled
mcp save_fact '{"key":"promo_link","label":"Promo","type":"text","value":"javascript:alert(document.domain)"}' > "$WORK/response"
grep -q 'cannot begin with an address scheme' "$WORK/response" && echo "  ok     facts: save_fact refuses a text fact \"javascript:…\"" || { echo "  CHYBA  save_fact accepted javascript:"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_facts (fact_key, language, label, type, value, updated_at) VALUES ('promo_link', '', 'Promo', 'text', 'javascript:alert(document.domain)', '$(site_time)')" > /dev/null
mcp create_page '{"title":"Fact link","slug":"fact-link","visible":true,"text":"<p><a href=\"{{fact.promo_link}}\">Promo</a></p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fact-link"
! grep -qi 'href="javascript:' "$WORK/response" && grep -q 'href="#">Promo</a>' "$WORK/response" && echo "  ok     facts: a text fact \"javascript:…\" in a link becomes a link to #" || { echo "  CHYBA  fakt javascript: v odkazu"; grep -o '<a href="[^"]*">Promo' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp trash_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'fact-link'")}" > /dev/null; sq "DELETE FROM ka_facts WHERE fact_key = 'promo_link'" > /dev/null
curl -s -o "$WORK/response" "$B/llms.txt"
grep -qE '^- Projects: 1(.|..)500$' "$WORK/response" && echo "  ok     facts: llms.txt lists the facts" || { echo "  CHYBA  fakta v llms.txt"; grep -A3 -i 'fakt' "$WORK/response" | head -5; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"projects","value":"1600"}' > "$WORK/response"
contains -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     facts: a change lists the sentences that still state the old value" || { echo "  CHYBA  stará hodnota se nenašla"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/fakta-test"
grep -qE '1(.|..)600 zakázek' "$WORK/response" && echo "  ok     facts: the page says the new value at once (the cache is cleared)" || { echo "  CHYBA  nová hodnota faktu se neukázala"; ERRORS=$((ERRORS+1)); }
mcp find_claims '{}' > "$WORK/response"
contains -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     claims inventory: sentences with numbers written as plain text" || { echo "  CHYBA  find_claims"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'fact.neexistuje' "$WORK/response" && echo "  ok     site audit: a token of a fact that does not exist" || { echo "  CHYBA  audit neznámého faktu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_facts '{}' > "$WORK/response"
contains -q 'company_phone' "$WORK/response" && contains -q 'used_in' "$WORK/response" && echo "  ok     MCP list_facts: own and built-in facts with their use" || { echo "  CHYBA  list_facts"; ERRORS=$((ERRORS+1)); }
expect "facts: the admin list" "$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=facts")" "200"
grep -q 'fact.projects' "$WORK/response" && echo "  ok     facts: the list shows the token" || { echo "  CHYBA  seznam faktů"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=edit&key=projects"
grep -q 'Fakta test' "$WORK/response" && echo "  ok     facts: the fact shows where it is used" || { echo "  CHYBA  kde se fakt používá"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=facts&action=save" -d "_csrf=$TOKEN" -d key=projects -d label=Projects -d type=number -d value=1700
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=edit&key=projects"
grep -q 'starou hodnotu' "$WORK/response" && echo "  ok     facts: after a change in the admin it says whether the old value is still stated" || { echo "  CHYBA  admin: stará hodnota"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=claims"
grep -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     facts: the claims inventory in the admin" || { echo "  CHYBA  admin: věty s čísly"; ERRORS=$((ERRORS+1)); }
