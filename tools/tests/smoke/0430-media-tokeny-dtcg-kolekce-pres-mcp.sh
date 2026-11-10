echo "== média, tokeny DTCG, kolekce přes MCP"
IDOM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
check "média: hledání a řazení" 200 "/admin.php?module=media&search=jpg&sort=velikost" 'data-popis-media='
reply=$(curl -s -b "$JAR" -c "$JAR" -X POST "$B/admin.php?module=media&action=save_caption" -d "_csrf=$TOKEN" -d "ido=$IDOM" --data-urlencode "popis=Dilna zevnitr")
expect "popis obrázku bez znovunačtení" "$reply|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE ido = $IDOM")" '{"ok":true}|Dilna zevnitr'
curl -s -b "$JAR" -o "$WORK/tokeny.json" "$B/admin.php?module=appearance&action=tokens"
grep -q '"\$type": "color"' "$WORK/tokeny.json" && grep -q '"cz.kaleta"' "$WORK/tokeny.json" && echo "  ok     export tokenů DTCG" || { echo "  CHYBA  export tokenů"; ERRORS=$((ERRORS+1)); }
printf '{"color":{"primary":{"$type":"color","$value":"#aa3300"}}}' > "$WORK/cizi.tokens.json"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/cizi.tokens.json"
publish_look
expect "import barev z cizích tokenů" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "#aa3300"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/tokeny.json"
publish_look
expect "import vlastního exportu vrátí vzhled" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) <> '#aa3300' FROM ka_nastaveni WHERE promenna = 'design_system'")" "1"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Mistr truhlář"}' > "$WORK/response"
grep -q 'Petr Svoboda' "$WORK/response" && grep -q 'celkem\\":1' "$WORK/response" && echo "  ok     kolekce přes MCP: filtr podle pole" || { echo "  CHYBA  kolekce přes MCP s filtrem"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDPS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_kolekce_polozky WHERE nazev = 'Petr Svoboda'")
mcp uloz_polozku_kolekce "{\"kolekce\":\"tym\",\"id\":$IDPS,\"data\":{\"funkce\":\"Vedouci dilny\"}}" > /dev/null
expect "kolekce přes MCP: úprava položky bez názvu název zachová" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(nazev, '|', data LIKE '%Vedouci dilny%') FROM ka_kolekce_polozky WHERE idp = $IDPS")" "Petr Svoboda|1"

