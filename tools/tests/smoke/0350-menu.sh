echo "== menu"
check "editor menu" 200 "/admin.php?module=menu" 'data-menu-seznam'
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
# 2.7: an icon (lide = people) and a description on an item, a group inside the submenu with its own items (a column), an unknown icon drops out
MENU='[{"typ":"stranka","ids":'$IDO',"text":"O firmě","ikona":"lide","popis":"Kdo jsme","deti":[{"typ":"odkaz","text":"Kariéra","url":"https://example.cz/kariera","nove_okno":true},{"typ":"odkaz","text":"Ikona z Médií","url":"/kontakt","ikona":"'$SVG'"},{"typ":"odkaz","text":"Cizí SVG","url":"/kontakt","ikona":"media/neni/tady.svg"},{"typ":"skupina","text":"Tým","ikona":"neexistuje","deti":[{"typ":"odkaz","text":"Vedení","url":"/vedeni"}]}]},{"typ":"novinky"},{"typ":"odkaz","text":"Zlý","url":"javascript:alert(1)"}]'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=hlavni" -d "_csrf=$TOKEN" --data-urlencode "polozky=$MENU"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Zásady ochrany soukromí","url":"/zasady"}]'
expect "the menu waits in the draft look" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'paticka' AND polozky LIKE '%/zasady%'")" "0"
publish_look
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/novinky"
grep -q '<li class="podmenu"><a href="[^"]*/o-nas"><svg class="menu-ikona"' "$WORK/response" && grep -q '</svg>O firmě</a><button type="button" class="menu-rozbalit" aria-controls="ka-podmenu-[0-9]*" aria-label="Podmenu: O firmě"></button><ul id="ka-podmenu-[0-9]*"><li><a href="https://example.cz/kariera" target="_blank" rel="noopener">Kariéra</a>' "$WORK/response" && grep -q 'aria-current="page">Novinky' "$WORK/response" && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     menu s podmenu na webu, ikona před textem, nebezpečný odkaz vypadl" || { echo "  CHYBA  menu na webu"; ERRORS=$((ERRORS+1)); }
grep -q '</li><li class="menu-sloupec"><span class="menu-nadpis">Tým</span><ul><li><a href="[^"]*/vedeni">Vedení</a></li></ul></li>' "$WORK/response" && ! grep -q 'menu-popis\|neexistuje' "$WORK/response" \
  && echo "  ok     skupina v podmenu jako sloupec s nadpisem; popis jen v mega menu, neznámá ikona vypadla" || { echo "  CHYBA  sloupec skupiny v menu"; ERRORS=$((ERRORS+1)); }
grep -q '<a href="[^"]*/kontakt"><img class="menu-ikona" src="[^"]*/'"$SVG"'" alt="" width="20" height="20">Ikona z Médií</a>' "$WORK/response" && grep -q '<a href="[^"]*/kontakt">Cizí SVG</a>' "$WORK/response" \
  && echo "  ok     3.10: an SVG from Media as a menu icon is an <img>, a path to a missing file drops out" || { echo "  CHYBA  SVG z Médií jako ikona menu"; grep -o '<li>[^<]*<a[^>]*kontakt[^<]*<[^>]*>' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
grep -q 'image/web\.js' "$WORK/response" && echo "  ok     stránka s podmenu načte web.js (Esc podmenu zavře)" || { echo "  CHYBA  stránka s podmenu bez web.js"; ERRORS=$((ERRORS+1)); }
mcp get_menu '{"location":"main"}' > "$WORK/response"; grep -q 'icon\\":\\"people' "$WORK/response" && grep -q 'description\\":\\"Kdo jsme' "$WORK/response" && echo "  ok     MCP: get_menu vrací ikonu anglicky a popis položky" || { echo "  CHYBA  MCP get_menu ikona"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nacti_menu '{"umisteni":"paticka"}' > "$WORK/response"; grep -q 'Zásady ochrany soukromí' "$WORK/response" && echo "  ok     menu v patičce (MCP)" || { echo "  CHYBA  menu v patičce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
expect "zaškrtnutá stránka se přidá na konec sestaveného menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT polozky LIKE '%\"ids\":$IDS%' FROM ka_menu WHERE umisteni = 'hlavni'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=automatic&location=hlavni" -d "_csrf=$TOKEN"
publish_look
expect "návrat k automatickému menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni'")" "0"
# 3.6 (UXA-09): "Save and publish menu" publishes that menu at once and keeps a version; the rest of the draft look waits
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=hlavni" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Draft only","url":"/draft-only"}]'
LOOK_VERSION=$(sq "SELECT COALESCE(MAX(id), 0) FROM ka_look_versions")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$TOKEN" -d publikovat=1 --data-urlencode 'polozky=[{"typ":"odkaz","text":"Published at once","url":"/published-at-once"}]'
expect "3.6 UXA-09: Save and publish menu publishes that menu with a version, another menu waits in the draft" \
  "$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'paticka' AND polozky LIKE '%published-at-once%'")|$(sq "SELECT COUNT(*) FROM ka_menu WHERE polozky LIKE '%draft-only%'")|$(sq "SELECT hodnota LIKE '%draft-only%' AND hodnota NOT LIKE '%published-at-once%' FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COALESCE(MAX(id), 0) > $LOOK_VERSION FROM ka_look_versions")" "1|0|1|1"
check "3.6 UXA-09: the draft look bar is one line naming what waits, the details open below" 200 "/admin.php?module=menu" '<summary><strong>Nepublikované změny:</strong> menu</summary>'
publish_look

