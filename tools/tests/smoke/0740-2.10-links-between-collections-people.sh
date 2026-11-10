echo "== 2.10: links between collections, people"
mcp create_collection '{"name":"Pobočky test","slug":"pobocky-test","item_pages":true,"fields":[{"label":"Město","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"pobocky-test","name":"Praha centrum","slug":"praha-centrum","values":{"mesto":"Praha"},"visible":true}' > /dev/null
mcp create_collection '{"name":"Lidé test","slug":"lide-test","item_pages":true,"redirect_hidden_to":"/pobocky-test","fields":[{"label":"Pobočka","type":"item","collection":"pobocky-test"}]}' > "$WORK/response"
contains -q 'redirect_hidden_to' "$WORK/response" && echo "  ok     collections: Claude links a field to another collection" || { echo "  CHYBA  create_collection s vazbou"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "collections: the link remembers the collection" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].kolekce')) FROM ka_kolekce WHERE seo_link = 'lide-test'")" "pobocky-test"
mcp save_collection_item '{"collection":"lide-test","name":"Jana Nová","slug":"jana-nova","values":{"pobocka":"praha-centrum"},"visible":true}' > /dev/null
curl -s -o "$WORK/response" "$B/lide-test/jana-nova"
grep -q 'Praha centrum' "$WORK/response" && echo "  ok     collections: the item page shows the linked item by its name" || { echo "  CHYBA  propojená položka se neukázala"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"lide-test\",\"id\":$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'"),\"visible\":false}" > /dev/null
expect "people: the page of a hidden person leads to the chosen page (301)" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/lide-test/jana-nova")" "301 $B/pobocky-test"
expect "people: an address that never existed is still not found" "$(curl -s -o /dev/null -w '%{http_code}' "$B/lide-test/nikdo-takovy")" "404"
mcp create_collection '{"name":"Tým","preset":"people"}' > "$WORK/response"
contains -q 'redirect_hidden_to' "$WORK/response" && contains -q 'image' "$WORK/response" && echo "  ok     people: the ready-made team collection" || { echo "  CHYBA  preset people"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 2.10: e-mail signature of a person – field keys by the preset's order (system/presets/people.php: photo, role, languages, phone, email, on_leave, about)
TEAM=$(sq "SELECT seo_link FROM ka_kolekce WHERE schema_org LIKE '%Person%' ORDER BY idk DESC LIMIT 1")
TEAM_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$TEAM'")
team_key() { sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[$1].klic')) FROM ka_kolekce WHERE seo_link = '$TEAM'"; }
mcp save_collection_item "{\"collection\":\"$TEAM\",\"name\":\"Petr Podpis\",\"slug\":\"petr-podpis\",\"values\":{\"$(team_key 1)\":\"Obchodní ředitel\",\"$(team_key 3)\":\"+420 777 123 456\",\"$(team_key 4)\":\"petr@example.cz\",\"$(team_key 5)\":\"Dovolená do pátku\"},\"visible\":true}" > "$WORK/response"
PERSON=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $TEAM_IDK AND seo_link = 'petr-podpis'")
[ -n "$PERSON" ] && echo "  ok     people: a person with a role, a phone and an e-mail" || { echo "  CHYBA  save_collection_item (people)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature "{\"collection\":\"$TEAM\",\"id\":${PERSON:-0}}" > "$WORK/response"
contains -q 'Petr Podpis' "$WORK/response" && contains -q '777 123 456' "$WORK/response" && contains -q 'tel:+420777123456' "$WORK/response" && contains -q 'max-width:600px' "$WORK/response" && ! contains -q 'Dovolen' "$WORK/response" \
  && echo "  ok     people: get_email_signature has the name and the phone in an inline-styled table, never the absence" || { echo "  CHYBA  get_email_signature"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature "{\"collection\":\"$TEAM\",\"slug\":\"petr-podpis\"}" > "$WORK/response"
contains -q 'mailto:petr@example.cz' "$WORK/response" && contains -q 'people_collection\\":true' "$WORK/response" && echo "  ok     people: the signature by the person's address" || { echo "  CHYBA  get_email_signature slug"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "people: the item form offers the e-mail signature" 200 "/admin.php?module=collections&action=item&id=$TEAM_IDK&item=${PERSON:-0}" 'action=signature'
check "people: the admin signature page shows the preview with the copy button" 200 "/admin.php?module=collections&action=signature&id=$TEAM_IDK&item=${PERSON:-0}" 'data-kopirovat-podpis'
grep -q 'Petr Podpis' "$WORK/response" && grep -q 'Obchodní ředitel' "$WORK/response" && grep -q 'href="tel:+420777123456"' "$WORK/response" && ! grep -q 'Dovolen' "$WORK/response" && echo "  ok     people: the preview has the name, the role and the phone, never the absence" || { echo "  CHYBA  signature preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=edit&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'lide-test'")"
grep -q 'name="hidden_redirect"' "$WORK/response" && grep -q 'name="pole\[0\]\[kolekce\]"' "$WORK/response" && echo "  ok     collections: the form offers links and the redirect" || { echo "  CHYBA  formulář kolekce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'lide-test'")&item=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'")"
grep -q '<option value="praha-centrum" selected>Praha centrum</option>' "$WORK/response" && echo "  ok     collections: the item form chooses the linked item" || { echo "  CHYBA  formulář položky s vazbou"; ERRORS=$((ERRORS+1)); }

