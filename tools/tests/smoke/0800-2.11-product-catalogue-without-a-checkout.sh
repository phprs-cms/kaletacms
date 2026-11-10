echo "== 2.11: product catalogue without a checkout"
mcp create_collection '{"name":"Produkty test","preset":"products"}' > /dev/null
PRODUCTS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty-test' AND preset = 'products'")
mcp save_collection_item '{"collection":"produkty-test","name":"Lehátko Basic","slug":"lehatko-basic","values":{"code":"LB-1","category":"Lehátka","price":"12900","parameters":"Šířka: 60 cm\nNosnost: 150 kg","variants":"Modrá | LB-1-M | 12 900 Kč\nŠedá | LB-1-S"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-test","name":"Lehátko Pro","slug":"lehatko-pro","values":{"code":"LP-2","category":"Lehátka","parameters":"Šířka: 70 cm\nMotor: 2 kW"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-test","name":"Špatné","slug":"spatne","values":{"parameters":"jen text bez hodnoty"}}' > "$WORK/response"
contains -q 'invalid_fields.*parameters' "$WORK/response" && echo "  ok     products: parameters without a value are refused" || { echo "  CHYBA  parametry bez hodnoty"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/produkty-test/lehatko-basic"
grep -q '<th scope="row">Nosnost</th><td>150 kg</td>' "$WORK/response" && grep -q 'class="ka-do-poptavky"' "$WORK/response" && grep -q '<option>Šedá</option>' "$WORK/response" && grep -q '"@type":"Product"' "$WORK/response" \
  && echo "  ok     products: the product page has the parameters, Add to enquiry with variants and Product data" || { echo "  CHYBA  stránka produktu"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web.js' "$WORK/response" && ! grep -q 'href="#"' "$WORK/response" && echo "  ok     products: the page keeps the basket script and has no button to a datasheet it does not have" || { echo "  CHYBA  web.js nebo prázdné tlačítko na stránce produktu"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/produkty-test/_porovnat?i=lehatko-basic,lehatko-pro,neni"
grep -q '<th scope="row">Šířka</th><td>60 cm</td><td>70 cm</td>' "$WORK/response" && grep -q '<th scope="row">Motor</th><td></td><td>2 kW</td>' "$WORK/response" && grep -q 'noindex' "$WORK/response" \
  && echo "  ok     products: the comparison puts the parameters side by side" || { echo "  CHYBA  porovnání produktů"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/compare-en.html" "$B/produkty-test/_compare?i=lehatko-basic,lehatko-pro,neni"
grep -q '<th scope="row">Šířka</th><td>60 cm</td><td>70 cm</td>' "$WORK/compare-en.html" && curl -s "$B/produkty-test/lehatko-basic" | contains -q 'data-porovnani="/produkty-test/_compare"' \
  && echo "  ok     3.7: the comparison at /_compare (the older /_porovnat above), linked from the product page" || { echo "  CHYBA  3.7: comparison at /_compare"; ERRORS=$((ERRORS+1)); }
expect "products: a comparison without known products is not found" "$(curl -s -o /dev/null -w '%{http_code}' "$B/produkty-test/_porovnat?i=neni")" "404"
expect "products: a collection that is not a catalogue has no comparison" "$(curl -s -o /dev/null -w '%{http_code}' "$B/typy-poli/_porovnat?i=den-otevrenych-dveri")" "404"
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'produkty-test'"),\"visible\":true}" > /dev/null
# without the script: Add to enquiry opens the list page with the product, the basket field has it
curl -s -o "$WORK/formular.html" "$B/produkty-test?product=produkty-test/lehatko-basic&variant=$(php -r 'echo rawurlencode("Šedá");')&quantity=2"
grep -q 'data-kosik-pole' "$WORK/formular.html" && grep -q '2 × Lehátko Basic – Šedá (LB-1-S)' "$WORK/formular.html" && echo "  ok     products: the enquiry form takes the product from the address" || { echo "  CHYBA  košík bez skriptu"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(grep -o 'name="prvek" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//'); FORM_TIME=$(grep -o 'name="as_cas" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//')
FORM_SIGNATURE=$(grep -o 'name="as_podpis" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//'); sleep 4
basket_send() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/produkty-test" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode "p0=$1" -d p1=Eva -d p2=eva@example.cz -d p5=1; }
case "$(basket_send '[{"c":"produkty-test","i":"lehatko-pro","v":"Zlatá","q":1}]')" in *result=pole*) echo "  ok     products: a variant the product does not have is refused";; *) echo "  CHYBA  neexistující varianta v košíku"; ERRORS=$((ERRORS+1));; esac
case "$(basket_send '[]')" in *result=pole*) echo "  ok     products: an empty basket is refused";; *) echo "  CHYBA  prázdný košík"; ERRORS=$((ERRORS+1));; esac
case "$(basket_send '[{"c":"produkty-test","i":"lehatko-basic","v":"Modrá","q":3},{"c":"produkty-test","i":"lehatko-pro","v":"","q":1,"n":"<script>"}]')" in *result=ok*) echo "  ok     products: the basket is sent";; *) echo "  CHYBA  odeslání košíku"; ERRORS=$((ERRORS+1));; esac
sq "SELECT data FROM ka_poptavky ORDER BY idp DESC LIMIT 1" > "$WORK/response"
grep -q '3 × Lehátko Basic – Modrá (LB-1-M)' "$WORK/response" && grep -q '1 × Lehátko Pro (LP-2)' "$WORK/response" && ! grep -q 'script' "$WORK/response" \
  && echo "  ok     products: the enquiry lists the products as the database has them, never the visitor's text" || { echo "  CHYBA  řádky košíku v poptávce"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
