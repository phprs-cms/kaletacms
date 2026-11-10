echo "== pop-up okna"
check "pop-up okna v administraci" 200 "/admin.php?module=popups" "Zatím žádná pop-up okna"
check "nové okno ze vzoru" 200 "/admin.php?module=popups&action=new" 'name="vzor" value="newsletter"'
mcp uloz_popup '{"vzor":"prazdny","nazev":"Akce okno"}' > "$WORK/response"; IDPP=$(mcp_value id)
expect "MCP: okno založené vypnuté a nepublikované" "$(mcp_value adresa)|$(mcp_value aktivni)|$(mcp_value publikovano)|$(mcp_value spoustec)" "akce-okno|||klik"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":true}" | contains 'nejdřív publikuj' && echo "  ok     MCP: nepublikované okno nejde zapnout" || { echo "  CHYBA  zapnutí nepublikovaného okna"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz "{\"popup\":$IDPP,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h2\",\"obsah\":{\"text\":\"Okno akce\"}},{\"id\":\"ab12cd3\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Z okna\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}}" > "$WORK/response"
mcp save_popup "{\"id\":$IDPP,\"type\":\"slide_in\",\"trigger\":\"time\",\"value\":3,\"frequency\":\"until_closed\",\"rules\":{\"device\":\"phone\"},\"active\":true}" > "$WORK/response"
expect "MCP anglicky: typ, spouštěč, četnost a pravidla" "$(mcp_value type)|$(mcp_value trigger)|$(mcp_value frequency)|$(mcp_value rules device)|$(mcp_value active)" "slide_in|time|until_closed|phone|1"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q "data-popup=\"$IDPP\"" "$WORK/response" && grep -q 'class="ka-popup ka-popup--panel" popover="manual" role="region"' "$WORK/response" && grep -q 'data-spoustec="cas" data-hodnota="3" data-cetnost="zavreni"' "$WORK/response" \
  && grep -q 'data-zarizeni="telefon"' "$WORK/response" && grep -q 'Okno akce' "$WORK/response" && grep -q 'image/web\.js' "$WORK/response" \
  && echo "  ok     zapnuté okno na webu se spouštěčem, pravidly prohlížeče a skriptem" || { echo "  CHYBA  pop-up okno na webu"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"kde\":\"vybrane\",\"stranky\":[$IDS]}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/" | contains "data-popup=\"$IDPP\"" && curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno jen na vybrané stránce" || { echo "  CHYBA  pravidlo vybraných stránek"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"2099-01-01\"}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno mimo období se do stránky nevloží" || { echo "  CHYBA  období okna"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"\",\"kde\":\"vse\"}}" > "$WORK/response"; POPUP_PREVIEW=$(mcp_value nahled); rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=zobrazeni; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=konverze; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=nic
expect "počitadla okna bez cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $IDPP")" "1/0/1"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/_popup/$IDPP?build=koncept"); expect "koncept okna bez přihlášení není" "$code" 404
curl -s -o "$WORK/response" "$POPUP_PREVIEW"; grep -q 'data-otevrit="1"' "$WORK/response" && grep -q 'noindex' "$WORK/response" && echo "  ok     podepsaný náhled okno rovnou otevře" || { echo "  CHYBA  náhled okna: $POPUP_PREVIEW"; ERRORS=$((ERRORS+1)); }
check "okno v builderu" 200 "/admin.php?module=popups&action=builder&id=$IDPP" 'id="stavitel-data"'
check "plátno okna v builderu" 200 "/_popup/$IDPP?build=koncept&editor=1" 'ka-popup--editor'
curl -s -b "$JAR" "$B/o-nas" | contains "data-popup=\"$IDPP\"" && ! curl -s -b "$JAR" "$B/o-nas?build=koncept&editor=1" | contains "data-popup=" \
  && echo "  ok     plátno builderu stránky je bez pop-up oken webu" || { echo "  CHYBA  pop-up okno v plátně builderu"; ERRORS=$((ERRORS+1)); }
curl -s "$B/" | sed -n '/data-popup=/,$p' > "$WORK/formular.html" # jen okno – stránka může mít vlastní formulář
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
expect "formulář v okně má zdroj okna" "$FORM_SOURCE|$FORM_ELEMENT" "popup:$IDPP|ab12cd3"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/ -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p0=okno@example.cz)
case "$location" in *"result=ok"*) echo "  ok     formulář v okně odeslán";; *) echo "  CHYBA  formulář v okně: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka z okna uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(formular, '|', zdroj) FROM ka_poptavky WHERE email = 'okno@example.cz'")" "Z okna|popup:$IDPP"
mcp seznam_popupu '{}' > "$WORK/response"; expect "MCP: seznam oken s počitadly" "$(mcp_value 0 nazev)|$(mcp_value 0 zobrazeni)|$(mcp_value 0 konverze)" "Akce okno|1|1"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":false}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html # další testy počítají se stránkami bez okna

# editing right on the site: the link and the form only for signed-in users with the permission
check "úprava na místě – odkaz" 200 /novinky/vitejte-v-kalete "ka-upravit-zde"
check "úprava na místě – formulář" 200 "/novinky/vitejte-v-kalete?edit=text" "ka-upravit-text"
check "úprava stránky na místě" 200 "/o-nas?edit=text" "ka-upravit-text"
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete?edit=text"; grep -q "ka-upravit" "$WORK/response" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; ERRORS=$((ERRORS+1)); } || echo "  ok     úprava na místě bez přihlášení není"

