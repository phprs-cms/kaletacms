echo "== 2.12: forms that know where they are, thank-you with next steps"
# the registration of the 2.11 events block was sent from a collection item page: the server recorded the collection and the item
expect "topic: a registration from an event's page records the calendar and the event" "$(sq "SELECT tema FROM ka_poptavky WHERE zdroj = 'kolekce:$EVENTS_IDK' ORDER BY idp LIMIT 1")" "Akce test – Jóga, pro začátečníky"
# a form on an ordinary page with the next steps: the page title is the topic, a posted topic is ignored
mcp create_page '{"title":"Koupelny F7","visible":true}' > /dev/null; PAGE_F7=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'koupelny-f7'")
mcp stavba_uloz "{\"id\":$PAGE_F7,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka F7\",\"pole\":[{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true}],\"dalsi_kroky\":\"Zavoláme vám\\nPřijedeme na zaměření\",\"odpovime_do\":4,\"odpovida\":\"Jana z kanceláře\"}}]}]}}" > "$WORK/response"
expect "next steps: the form keeps the steps, the working hours and who replies" "$(sq "SELECT CONCAT(stavba LIKE '%\"dalsi_kroky\":\"Zavol%', '|', stavba LIKE '%\"odpovime_do\":4%', '|', stavba LIKE '%\"odpovida\":\"Jana z kancel%') FROM ka_stranky WHERE ids = $PAGE_F7")" "1|1|1"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/koupelny-f7"
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/koupelny-f7 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p0=f7@example.cz -d tema=Podvrh -d about=Podvrh)
case "$location" in *result=ok*) echo "  ok     topic: the form on the page was sent";; *) echo "  CHYBA  form F7: $location"; ERRORS=$((ERRORS+1));; esac
F7_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE zdroj = 'stranka:$PAGE_F7'")
expect "topic: on a page the topic is the page title – what was posted for it is ignored" "$(sq "SELECT tema FROM ka_poptavky WHERE idp = $F7_IDP")" "Koupelny F7"
check "topic: the Enquiries list shows it with a link to the page" 200 "/admin.php?module=enquiries" 'Téma: <a href="/koupelny-f7"'
check "topic: the enquiry detail shows it" 200 "/admin.php?module=enquiries&action=detail&id=$F7_IDP" '<dt>Téma</dt><dd><a href="/koupelny-f7"'
mcp list_enquiries '{"limit":1}' > "$WORK/response"
expect "MCP: list_enquiries has about" "$(mcp_value 0 about)" "Koupelny F7"
# the thank-you in place of the form: the steps as a list, by when the reply comes (counted in working hours) and who replies
check "next steps: the thank-you lists the steps" 200 "/koupelny-f7?form=$FORM_ELEMENT&result=ok" '<ol class="ka-kroky"><li>Zavoláme vám</li><li>Přijedeme na zaměření</li></ol>'
grep -q 'class="ka-kroky-termin">Odpovíme .* do [0-9]*:[0-9][0-9]\.</p>' "$WORK/response" && grep -q '<p class="ka-kroky-kdo">Jana z kanceláře vám odpoví.</p>' "$WORK/response" \
  && echo "  ok     next steps: the thank-you says by when and who replies" || { echo "  CHYBA  thank-you deadline or who replies"; grep -o 'ka-formular-hotovo.\{0,400\}' "$WORK/response" | head -c 500; ERRORS=$((ERRORS+1)); }
