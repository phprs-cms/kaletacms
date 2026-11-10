echo "== 3.6: header and footer variants by kind of content (INV-9)"
# a variant for news items and pages under "O nás", sorted first by key; a variant listing a page always wins over it
mcp vytvor_stranku "{\"titulek\":\"Pod onas\",\"nadrazena\":$IDS,\"zobrazit\":true,\"text\":\"<p>Podstranka</p>\"}" > /dev/null
CHILD=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas/pod-onas'")
mcp save_part_variant '{"part":"footer","name":"A pravidla","news_items":true}' > "$WORK/response"
expect "3.6 MCP: a footer variant for news items (English names)" "$(mcp_value variant)|$(mcp_value news_items)|$(mcp_value news_list)|$(mcp_value under_pages)" "a-pravidla|1||[]"
mcp save_part_variant "{\"part\":\"footer\",\"variant\":\"a-pravidla\",\"name\":\"A pravidla\",\"under_pages\":[$IDS]}" > "$WORK/response"
expect "3.6 MCP: rules not sent stay (news_items), under_pages is added" "$(mcp_value news_items)|$(mcp_value under_pages)" "1|[$IDS]"
mcp uloz_variantu "{\"cast\":\"paticka\",\"nazev\":\"Z stranka\",\"stranky\":[$CHILD]}" > /dev/null
for v in a-pravidla:Paticka-pravidel z-stranka:Paticka-stranky; do
  mcp stavba_uloz "{\"cast\":\"paticka\",\"varianta\":\"${v%%:*}\",\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"znacka\":\"footer\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"p\",\"obsah\":{\"text\":\"${v##*:}\"}}]}]}}" > /dev/null
  mcp publikuj_stavbu "{\"cast\":\"paticka\",\"varianta\":\"${v%%:*}\"}" > /dev/null
done
rm -f "$WORK"/web/storage/cache/stranky/*.html
footer_on() { curl -s "$B$1" | grep -o 'Paticka-[a-z]*' | head -1 || true; }
expect "3.6: news item → rule variant, page under the parent → its own page variant wins, the parent and other pages → default" \
  "$(footer_on /novinky/vitejte-v-kalete)|$(footer_on /o-nas/pod-onas)|$(footer_on /o-nas)|$(footer_on /kontakt)|$(footer_on /novinky)" "Paticka-pravidel|Paticka-stranky|||"
mcp uloz_variantu "{\"cast\":\"paticka\",\"varianta\":\"z-stranka\",\"smazat\":true}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.6: without the page variant the page under the parent takes the rule variant" "$(footer_on /o-nas/pod-onas)" "Paticka-pravidel"
mcp seznam_casti '{}' > "$WORK/response"
[[ "$(mcp_value)" == *'"varianta":"a-pravidla"'*'"novinky":true'*"\"nadrazene\":[$IDS]"* ]] && echo "  ok     3.6 MCP: list_site_parts (Czech alias) shows the rules" || { echo "  CHYBA  3.6 seznam_casti rules: $(mcp_value)"; ERRORS=$((ERRORS+1)); }
check "3.6: the variant form offers kinds of content" 200 "/admin.php?module=parts&action=variant&type=paticka&language=&variant=a-pravidla" 'name="nadrazene\[\]"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=parts&action=save_variant&type=paticka&language=" -d "_csrf=$TOKEN" -d varianta=a-pravidla --data-urlencode "nazev=A pravidla" -d vypis=1 -d "kolekce[]=reference" -d "kolekce[]=Spatna adresa!"
expect "3.6 admin: rules saved from the form (news list, a collection; a bad slug dropped; unticked news items off)" "$(sq "SELECT pravidla FROM ka_casti WHERE varianta = 'a-pravidla'")" '{"novinky":false,"vypis":true,"kolekce":["reference"],"nadrazene":[]}'
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.6: the news list takes the variant now, a news item no longer" "$(footer_on /novinky)|$(footer_on /novinky/vitejte-v-kalete)" "Paticka-pravidel|"
mcp uloz_variantu '{"cast":"paticka","varianta":"a-pravidla","smazat":true}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

