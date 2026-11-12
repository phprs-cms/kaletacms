echo "== Claude (MCP): stavba webu bez administrace"
mcp stavba_z_html '{"titulek":"Mrizka","html":"<style>.mriz-t { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .kar-t:hover { box-shadow: var(--ka-stin-m) } @media (max-width: 767px) { .mriz-t { grid-template-columns: 1fr } }</style><section><div class=\"mriz-t\"><div class=\"kar-t\"><h3>Jedna</h3></div><div class=\"kar-t\"><h3>Dva</h3></div></div></section>"}' > "$WORK/response"
IDM2=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'mrizka'")
expect "MCP: @media a :hover z <style> jako stavy třídy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT styl FROM ka_tridy WHERE nazev = 'mriz-t'), (SELECT styl FROM ka_tridy WHERE nazev = 'kar-t'))")" '{"mobil":{"sloupce":"1"}}{"hover":{"stin":"m"}}'
mcp stavba_nacti "{\"id\":$IDM2}" > "$WORK/response"
grep -q 'mriz-t' "$WORK/response" && ! grep -q 'zobrazeni' "$WORK/response" && ! grep -q '\\"odkaz\\":\\"\\"' "$WORK/response" && echo "  ok     MCP: stavba_nacti bez výchozích hodnot, prvek s třídou bez výchozího stylu" || { echo "  CHYBA  MCP stavba_nacti kompaktní"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDH3=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["stavba"]["deti"][0]["deti"][0]["deti"][0]["deti"][0]["id"];' -- "$WORK/response")
mcp stavba_uprav "{\"id\":$IDM2,\"operace\":[{\"op\":\"uprav\",\"id\":\"$IDH3\",\"obsah\":{\"text\":\"Opraveno\"}},{\"op\":\"smaz\",\"id\":\"neni\"}]}" > "$WORK/response"
grep -q 'chyby_operaci\\":{\\"op\[1\]' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%Opraveno%' FROM ka_stranky WHERE ids = $IDM2")" = 1 ] \
    && echo "  ok     MCP: dílčí úprava prvku podle id (chybná operace nahlášena)" || { echo "  CHYBA  MCP stavba_uprav"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
CHECK_RESULT=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo implode("|", array_column($j["kontrola"] ?? [], "zprava"));' -- "$WORK/response")
[[ "$CHECK_RESULT" == *"(h1)"* ]] && echo "  ok     MCP: zápis stavby vrátí kontrolu před publikováním (stránka bez h1)" || { echo "  CHYBA  MCP kontrola: $CHECK_RESULT"; ERRORS=$((ERRORS+1)); }
PREVIEW=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["nahled"];' -- "$WORK/response")
curl -s -o "$WORK/response" -w '%{http_code}' "$PREVIEW" > "$WORK/kod"; grep -q 'Opraveno' "$WORK/response" && grep -q 'noindex' "$WORK/response" && [ "$(cat "$WORK/kod")" = 200 ] \
    && echo "  ok     podepsaný náhled konceptu skryté stránky bez přihlášení" || { echo "  CHYBA  podepsaný náhled ($(cat "$WORK/kod"))"; ERRORS=$((ERRORS+1)); }
expect "náhled s cizím nebo pozměněným klíčem nejde" "$(curl -s -o /dev/null -w '%{http_code}' "${PREVIEW%?}x")" 404
expect "klíč náhledu jedné stránky neotevře jinou" "$(curl -s -o /dev/null -w '%{http_code}' "$B/z-html?build=koncept&preview_key=${PREVIEW##*preview_key=}" | tr -d '\n'; curl -s "$B/z-html?build=koncept&preview_key=${PREVIEW##*preview_key=}" | grep -c 'Opraveno')" "2000"
mcp nahled_odkaz '{"cast":"paticka"}' > "$WORK/response"; grep -q 'part=paticka&build=koncept&preview_key=' "$WORK/response" && echo "  ok     MCP: odkaz na náhled části webu" || { echo "  CHYBA  MCP nahled_odkaz"; ERRORS=$((ERRORS+1)); }
mcp uloz_tridy '{"css":".stitek-t { padding: var(--ka-mezera-2xs) var(--ka-mezera-s); border-radius: var(--ka-zaobleni) } @media (max-width: 1023px) { .stitek-t { font-size: var(--ka-krok--1) } }"}' > "$WORK/response"
mcp uloz_tridy '{"css":".stitek-t:hover { background-color: #ffe3dc }"}' > /dev/null
mcp seznam_trid '{"nazev":"stitek-t"}' > "$WORK/response"; grep -q 'velikost_pisma\\":\\"-1' "$WORK/response" && grep -q 'hover' "$WORK/response" && grep -q 'border-radius' "$WORK/response" && echo "  ok     MCP: sdílená třída z CSS i se stavem tablet" || { echo "  CHYBA  MCP uloz_tridy"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
PNG=$(php -r '$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 255, 79, 46)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
mcp nahraj_soubor "{\"nazev\":\"tym-foto.png\",\"data\":\"$PNG\",\"popis\":\"Tym v dilne\"}" > "$WORK/response"
MEDIUM=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["adresa"] ?? "";' -- "$WORK/response")
[ -n "$MEDIUM" ] && [ -f "$WORK/web/$MEDIUM" ] && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE obr_poloha = '$MEDIUM'")" = "Tym v dilne" ] \
    && echo "  ok     MCP: obrázek nahraný v base64 je v Médiích" || { echo "  CHYBA  MCP nahraj_soubor (obrázek)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor "{\"nazev\":\"pismo.woff2\",\"data\":\"$(base64 < image/pisma/bricolage-grotesque-latin.woff2 | tr -d '\n')\"}" > "$WORK/response"
grep -q 'vlastni_pisma' "$WORK/response" && grep -q 'pismo-[a-f0-9]*\.woff2' "$WORK/response" && echo "  ok     MCP: písmo WOFF2 do Médií s návodem pro design system" || { echo "  CHYBA  MCP nahraj_soubor (písmo)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor '{"nazev":"skript.php","data":"PD9waHAgZWNobyAxOw=="}' > "$WORK/response"; grep -q 'isError' "$WORK/response" && ! ls "$WORK"/web/media/*/*/skript* > /dev/null 2>&1 && echo "  ok     MCP: PHP ani jiný spustitelný soubor nahrát nejde" || { echo "  CHYBA  MCP nahraj_soubor pustil PHP"; ERRORS=$((ERRORS+1)); }
mcp uprav_nastaveni '{"nastaveni":{"text_paticky":"Paticka od Clauda","email_webu":"utocnik@example.com","firma_ico":"abc"}}' > "$WORK/response"
expect "MCP: nastavení webu – povolené se uloží, e-mail a neplatné IČO ne" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'footer_text'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '') <> 'utocnik@example.com', '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'), '') <> 'abc')")" "Paticka od Clauda|1|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'spravce@example.cz' WHERE promenna = 'site_email'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/" | contains 'spravce@example.cz' && { echo "  CHYBA  e-mail webu je vidět na webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     e-mail webu (poptávky, upozornění) se na webu neukazuje"
mcp uprav_nastaveni '{"nastaveni":{"firma_email":"info@example.cz"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "veřejný e-mail firmy v patičce" 200 / "info@example.cz"
check "security.txt: without a contact there is none" 404 /.well-known/security.txt
mcp update_settings '{"settings":{"security_contact":"security@example.com"}}' > /dev/null
check "security.txt from the security contact (RFC 9116)" 200 /.well-known/security.txt "Contact: mailto:security@example.com"
mcp uprav_nastaveni '{"nastaveni":{"logo_webu":"image/kaleta-logo.svg","favicon":"../config.php"}}' > "$WORK/response"
# 3.12 (UX310-11): over MCP too, the logo goes to the draft look and publish_look puts it live
expect "3.12: MCP update_settings puts the logo into the draft look and says so" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), ''), '|', (SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.identity.logo')) FROM ka_nastaveni WHERE promenna = 'look_draft'))")|$(grep -c 'logo in the draft look' "$WORK/response")" "|image/kaleta-logo.svg|1"
mcp publish_look '{}' > /dev/null
expect "MCP: logo webu ze systémových souborů, cesta mimo media/ a image/ neprojde" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'favicon'), ''))")" "image/kaleta-logo.svg|"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "3.5 (UXP-15): an SVG logo gets its width and height from the file" 200 / 'kaleta-logo.svg" alt="[^"]*" width="281" height="71"'
mcp uloz_presmerovani '{"z":"/stary-web/sluzby","na":"/z-html"}' > /dev/null
expect "MCP: přesměrování staré adresy" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/stary-web/sluzby")" "301 $B/z-html"
mcp smaz_stranku "{\"id\":$IDM2}" > /dev/null
expect "MCP: stránka do koše" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $IDM2")" 1
mcp smaz_stranku "{\"id\":$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")}" | contains 'isError' && echo "  ok     MCP: úvodní stránku smazat nejde" || { echo "  CHYBA  MCP smazal úvodní stránku"; ERRORS=$((ERRORS+1)); }
mcp vytvor_sablonu '{"nazev":"test-kopie"}' > "$WORK/response"; mcp copy_theme '{"name":"test-kopie2"}' >> "$WORK/response"
[ "$(grep -o 'isError' "$WORK/response" | wc -l | tr -d ' ')" = 2 ] && [ ! -d "$WORK/web/layout/test-kopie" ] && [ ! -d "$WORK/web/layout/test-kopie2" ] && echo "  ok     MCP: vlastní šablony už přes napojení nevznikají" || { echo "  CHYBA  MCP šablony"; ERRORS=$((ERRORS+1)); }
mcp vytvor_kategorii '{"nazev":"Kategorie XSS","popis":"<p>Úvod</p><script>alert(1)</script><img src=x onerror=alert(2)>"}' > /dev/null
check "MCP: popis kategorie se vyčistí" 200 "/novinky/kategorie/kategorie-xss" "Úvod"
! grep -qE '<script>alert|onerror' "$WORK/response" && echo "  ok     MCP: v popisu kategorie nezůstal skript" || { echo "  CHYBA  popis kategorie pustil skript"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_kategorie SET popis = '<p>Stary popis</p><script>alert(3)</script>' WHERE seo_link = 'kategorie-xss'"
check "Uložený starý popis kategorie" 200 "/novinky/kategorie/kategorie-xss" "Stary popis"
! grep -q '<script>alert(3)' "$WORK/response" && echo "  ok     Výpis čistí i dřív uložený popis kategorie" || { echo "  CHYBA  výpis kategorie vypsal skript"; ERRORS=$((ERRORS+1)); }

