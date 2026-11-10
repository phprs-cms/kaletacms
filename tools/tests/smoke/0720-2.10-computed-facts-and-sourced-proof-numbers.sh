echo "== 2.10: computed facts and sourced proof numbers"
YEARS=$(php -r 'echo (int) date("Y") - 2004;'); TYM_COUNT=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = 'tym' AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ''")
mcp create_page '{"title":"Pocitane test","slug":"pocitane-test","visible":true,"text":"<p>Roky: {{years_since:2004}} / {{ years_since:fact.founded }}. Tým: {{count:tym}}. Novinky: {{count:news}}. Vadné: {{count:neexistuje}}|{{years_since:brzy}}.</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/pocitane-test"
grep -q "Roky: $YEARS / $YEARS\. Tým: $TYM_COUNT\." "$WORK/response" && grep -qE 'Novinky: [0-9]+\.' "$WORK/response" && grep -q 'Vadné: |\.' "$WORK/response" && ! grep -q '{{' "$WORK/response" \
  && echo "  ok     computed facts: years since a year and a fact, the count of a collection and of news; a bad token is empty" || { echo "  CHYBA  počítané fakty na stránce"; grep -o 'Roky:[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'count:neexistuje' "$WORK/response" && contains -q 'years_since:brzy' "$WORK/response" && contains -q 'cannot be computed' "$WORK/response" && echo "  ok     site audit: computed tokens that cannot be computed" || { echo "  CHYBA  audit vadné počítané značky"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp find_claims '{}' > "$WORK/response"
! contains -q 'Roky:' "$WORK/response" && echo "  ok     claims inventory: a sentence with a computed token is not a claim" || { echo "  CHYBA  find_claims s počítanou značkou"; ERRORS=$((ERRORS+1)); }
POCIT=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'pocitane-test'")
mcp save_build "{\"id\":$POCIT,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"id\":\"cnt1\",\"type\":\"counter\",\"content\":{\"number\":\"1500\",\"suffix\":\"+\",\"caption\":\"zakázek\"}},{\"id\":\"cnt2\",\"type\":\"counter\",\"content\":{\"number\":\"{{fact.projects}}\",\"suffix\":\"\",\"caption\":\"zakázek z faktu\"}},{\"id\":\"cnt3\",\"type\":\"counter\",\"content\":{\"number\":\"{{years_since:fact.founded}}\",\"suffix\":\" let\",\"caption\":\"na trhu\"}}]}]}}" > "$WORK/response"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/pocitane-test"
grep -qE 'data-pocitadlo="1700">1(.|..)700<' "$WORK/response" && grep -q "data-pocitadlo=\"$YEARS\">$YEARS<" "$WORK/response" && grep -q 'data-pocitadlo="1500"' "$WORK/response" && ! grep -q '{{' "$WORK/response" \
  && echo "  ok     counter: a fact and a computed token as the number, filled in for visitors with the count-up" || { echo "  CHYBA  počítadlo s faktem"; grep -o 'data-pocitadlo[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'The number 1500 is typed in' "$WORK/response" && contains -q 'cnt1' "$WORK/response" && ! contains -q 'cnt2' "$WORK/response" && ! contains -q 'cnt3' "$WORK/response" \
  && echo "  ok     site audit: a proof number typed in as digits is reported with its element, tokens are not" || { echo "  CHYBA  audit ručně napsaného čísla"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FIRST_COL=$(sq "SELECT seo_link FROM ka_kolekce ORDER BY idk LIMIT 1"); FIRST_COUNT=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = '$FIRST_COL' AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ''")
mcp list_facts '{}' > "$WORK/response"
contains -q 'years_since:fact.founded' "$WORK/response" && contains -q "count:$FIRST_COL" "$WORK/response" && echo "  ok     MCP list_facts: the computed tokens with their values" || { echo "  CHYBA  list_facts computed"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts"
grep -q 'years_since:fact.founded' "$WORK/response" && grep -q "count:$FIRST_COL}}</code></td><td>$FIRST_COUNT<" "$WORK/response" && echo "  ok     facts: the admin list explains the computed tokens with their current values" || { echo "  CHYBA  počítané značky v seznamu faktů"; ERRORS=$((ERRORS+1)); }
mcp trash_page "{\"id\":$POCIT}" > /dev/null
mcp delete_fact '{"key":"founded"}' > "$WORK/response"
contains -q 'Fakta test' "$WORK/response" && echo "  ok     deleting a fact lists where it was still used" || { echo "  CHYBA  delete_fact"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }

