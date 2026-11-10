echo "== builder: vlastní CSS, atributy, animace, moje sekce, přejmenování třídy"
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nase-sluzby'")
version_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDV" -d "_csrf=$TOKEN" "${@:2}"; }
version_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"sv1","typ":"sekce","tridy":["karta"],"css":"backdrop-filter: blur(4px); background: url(x)","atributy":{"data-sledovat":"cta","onclick":"x"},"styl":{"zaklad":{"animace":"ka-vyjet","prechod":"linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))","okraj_vlevo":"auto"},"aktivni":{"pruhlednost":"0.8"}},"deti":[{"typ":"nadpis","obsah":{"text":"Test"}}]}]}' > /dev/null
grep -q 'Nepovolená deklarace' "$WORK/response" && grep -q 'Atribut může být jen' "$WORK/response" && echo "  ok     vlastní CSS a atributy prvku se čistí" || { echo "  CHYBA  čištění CSS a atributů"; ERRORS=$((ERRORS+1)); }
version_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/nase-sluzby"
grep -q 'data-sledovat="cta"' "$WORK/response" && ! grep -q 'onclick="x"' "$WORK/response" && grep -q 'backdrop-filter: blur(4px)' "$WORK/response" && grep -q 'animation-timeline: view()' "$WORK/response" \
  && grep -q '@keyframes ka-vyjet' "$WORK/response" && grep -q ':active {' "$WORK/response" && grep -q 'margin-inline-start: auto' "$WORK/response" \
  && echo "  ok     vlastní CSS, atributy, animace, stisknutí a okraj na webu" || { echo "  CHYBA  nové vlastnosti stylu na webu"; ERRORS=$((ERRORS+1)); }
expect "uložení do mých sekcí" "$(version_action build_save_section --data-urlencode 'nazev=Moje karta' --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Z knihovny"}}]}')" 200
grep -q '"nazev":"Moje karta"' "$WORK/response" && echo "  ok     moje sekce v seznamu" || { echo "  CHYBA  moje sekce"; ERRORS=$((ERRORS+1)); }
version_action build_class -d nazev=karta -d pouziti=1 > /dev/null; grep -q 'Služby firmy' "$WORK/response" && echo "  ok     přehled použití třídy" || { echo "  CHYBA  použití třídy"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "přejmenování třídy" "$(version_action build_class -d nazev=karta -d novy_nazev=karta-sluzby)" 200
expect "přejmenovaná třída ve stavbách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba LIKE '%\"karta-sluzby\"%' AND stavba NOT LIKE '%\"karta\"%' FROM ka_stranky WHERE ids = $IDV")" "1"

