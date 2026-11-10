echo "== vypnutá rozšíření Novinky a Formuláře a poptávky"
EXTENSIONS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna='extensions'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='statistika,presmerovani,claude' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK"/web/storage/cache/*.txt 2>/dev/null || true
check "výpis novinek je pryč" 404 /novinky
check "novinka je pryč" 404 /novinky/vitejte-v-kalete
check "RSS je pryč" 404 /rss.xml
curl -s -o "$WORK/response" "$B/sitemap.xml"; ! grep -q "/novinky" "$WORK/response" && echo "  ok     mapa webu bez novinek" || { echo "  CHYBA  mapa webu s vypnutými novinkami"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'rss.xml' "$WORK/response" && echo "  ok     bez novinek ani odkaz na RSS" || { echo "  CHYBA  odkaz na RSS při vypnutých novinkách"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'href="[^"]*/novinky"' "$WORK/response" && echo "  ok     menu bez odkazu na novinky" || { echo "  CHYBA  menu odkazuje na vypnuté novinky"; ERRORS=$((ERRORS+1)); }
expect "odeslání formuláře nejde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/formular" -d x=1)" 404
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"; ! grep -q 'module=news"' "$WORK/response" && ! grep -q 'module=enquiries"' "$WORK/response" && echo "  ok     administrace bez novinek a poptávek" || { echo "  CHYBA  administrace ukazuje vypnutá rozšíření"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; ! grep -q '\\"formular\\":' "$WORK/response" && ! grep -q 'seznam_novinek' <(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') \
  && echo "  ok     builder a MCP nenabízejí prvky ani nástroje vypnutých rozšíření" || { echo "  CHYBA  schéma nebo MCP s vypnutými rozšířeními"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$EXTENSIONS' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html

