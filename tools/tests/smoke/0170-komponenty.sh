echo "== komponenty"
check "komponenty" 200 "/admin.php?module=components" "Komponenty"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=components&action=save" -d "_csrf=$TOKEN" -d idm=0 --data-urlencode "nazev=Karta služby" \
  --data-urlencode "vlastnosti[0][popisek]=Nadpis" -d "vlastnosti[0][typ]=text" --data-urlencode "vlastnosti[0][vychozi]=Výchozí nadpis" --data-urlencode "vlastnosti[1][popisek]=Odkaz" -d "vlastnosti[1][typ]=odkaz" -d "vlastnosti[1][vychozi]=/kontakt"
IDM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty ORDER BY idm DESC LIMIT 1")
component_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=$1&id=$IDM" -d "_csrf=$TOKEN" "${@:2}"; }
check "komponenta v builderu" 200 "/admin.php?module=components&action=builder&id=$IDM" 'id="stavitel-data"'
check "2.4: builder links to its guide article" 200 "/admin.php?module=components&action=builder&id=$IDM" '"navod":"https:[^"]*guide[^"]*components"'
check "2.4: settings tab links to its guide article" 200 "/admin.php?module=settings&tab=backups" 'class="navod-odkaz" href="https://kaletacms.com/[a-z/]*guide/backups-updates"'
# 3.32 (U49): a yes/no box is named by its row label, not by "Yes"
expect "3.32 (U49): the automatic updates box is labelled by its row" "$(grep -c '<span class="popisek" id="auto_updates-popisek">' "$WORK/response")|$(grep -c 'aria-labelledby="auto_updates-popisek" name="auto_updates" value="1"' "$WORK/response")" "1|1"
# 3.32 (U47): the cookie table of Settings → Privacy and cookies is a listing in its wrapper (cards on a phone, admin.js)
check "3.32 (U47): Privacy and cookies – the cookie table is a listing in a wrapper" 200 "/admin.php?module=settings&tab=cookies" '<table class="vypis cookies-tabulka">'
expect "3.32 (U47): … right after its wrapper" "$(grep -B1 '<table class="vypis cookies-tabulka">' "$WORK/response" | head -1)" '<div class="tab-obal">'
check "2.4: dashboard links to the guide" 200 "/admin.php" 'guide/first-steps#the-dashboard'
component_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kna1","typ":"nadpis","znacka":"h3","obsah":{"text":"{{nadpis}}"},"styl":{"zaklad":{"barva":"primarni"}}},{"typ":"tlacitko","obsah":{"text":"Více","odkaz":"{{odkaz}}"}},{"typ":"komponenta","obsah":{"komponenta":"'"$IDM"'"}}]}]}' > /dev/null
expect "publikování komponenty" "$(component_action build_publish)" 200
check "náhled komponenty pro editor" 200 "/_komponenta/$IDM?build=koncept&editor=1" "Výchozí nadpis"
check "3.7: the component preview at /_component" 200 "/_component/$IDM?build=koncept&editor=1" "Výchozí nadpis"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\",\"hodnoty\":{\"nadpis\":\"První <b>karta</b>\",\"odkaz\":\"javascript:alert(1)\"}}},{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\"}}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" -w '' "$B/z-html"
grep -q '<h3 class="s-kna1">První karta</h3>' "$WORK/response" && grep -q '<h3 class="s-kna1">Výchozí nadpis</h3>' "$WORK/response" && [ "$(grep -o 'href="/kontakt"' "$WORK/response" | wc -l | tr -d ' ')" -ge 1 ] && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     komponenta na stránce: vlastní i výchozí hodnoty, bez značek, nebezpečný odkaz pryč" || { echo "  CHYBA  komponenta na stránce"; ERRORS=$((ERRORS+1)); }
! grep -q 'id="s-kna1"' "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && [ "$(grep -o '\.s-kna1 {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     komponenta dvakrát na stránce: styl jednou, bez duplicitního id" || { echo "  CHYBA  styl komponenty"; ERRORS=$((ERRORS+1)); }
check "komponenty ukazují počet použití" 200 "/admin.php?module=components" "1×"
grep -q 'data-potvrdit="Komponentu „Karta služby“ používá: stránka „' "$WORK/response" && echo "  ok     potvrzení smazání komponenty vyjmenuje, kde je použitá" || { echo "  CHYBA  potvrzení smazání komponenty"; ERRORS=$((ERRORS+1)); }
# a form inside a component: the submit must find it (it used to be searched only in the page build)
component_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kfo1","typ":"formular","obsah":{"nazev":"Poptávka z komponenty"}}]}]}' > /dev/null; component_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/z-html"
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$(field_value zdroj)" -d prvek=kfo1 -d zpet=/z-html -d "as_cas=$(field_value as_cas)" -d "as_podpis=$(field_value as_podpis)")
case "$location" in *"form=kfo1"*) echo "  ok     formulář v komponentě se odešle";; *) echo "  CHYBA  formulář v komponentě: $location"; ERRORS=$((ERRORS+1));; esac
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=from_element" -d "_csrf=$TOKEN" --data-urlencode "nazev=Výzva" --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Zavolejte nám"}}]}')
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && echo "  ok     uložení prvku jako komponenty" || { echo "  CHYBA  z_prvku: $code"; ERRORS=$((ERRORS+1)); }

check "náhled hotové sekce pro panel builderu" 200 /_sekce/cenik "Vyberte si balíček"
check "3.7: the section preview at /_section" 200 /_section/cenik "Vyberte si balíček"
expect "náhled sekce jen pro přihlášené" "$(curl -s -o /dev/null -w '%{http_code}' "$B/_sekce/cenik")" 404

