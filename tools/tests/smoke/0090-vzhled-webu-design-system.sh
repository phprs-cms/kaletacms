echo "== vzhled webu (design systém)"
check "vzhled s předvolbami a náhledem" 200 "/admin.php?module=appearance" 'data-predvolba'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=appearance&action=preview" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy][primarni]=#ff00aa' -d 'ds[zaklad_min]=18'
grep -q 'ka-barva-primarni: #ff00aa' "$WORK/response" && grep -q '"kontrasty"' "$WORK/response" && echo "  ok     živý náhled vrátí tokeny a kontrasty" || { echo "  CHYBA  náhled vzhledu"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" -d layout=zakladni -d tmavy_rezim=vypnuto --data-urlencode 'ds[barvy][primarni]=#9a3412' --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280'
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && { echo "  CHYBA  the saved appearance is on the site before publishing"; ERRORS=$((ERRORS+1)); } || echo "  ok     the saved appearance waits in the draft look"
check "the admin shows the draft look with its changes" 200 "/admin.php?module=appearance" "Nepublikované změny vzhledu"
publish_look
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && grep -q 'ka-sirka: 80rem' "$WORK/response" && grep -q 'ka-pismo-titulky: Georgia' "$WORK/response" && echo "  ok     uložený vzhled je po publikování na webu" || { echo "  CHYBA  uložení vzhledu"; ERRORS=$((ERRORS+1)); }
grep -q 'body{' "$WORK/response" && { echo "  CHYBA  do CSS proniklo neplatné zadání barvy"; ERRORS=$((ERRORS+1)); } || echo "  ok     neplatná barva se nahradí výchozí"
# 3.12 (UX310-11): the logo and the site icon go to the draft look like the colours – the preview shows them, publishing puts them live
appearance_identity() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy][primarni]=#9a3412' \
  --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280' -d "logo=$1" -d "favicon=$2"; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
appearance_identity image/kaleta-logo.svg image/kaleta-logo.svg
expect "3.12 (UX310-11): a saved logo and icon wait in the draft look, the site keeps the published ones" \
  "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), ''), '|', (SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.identity.logo')), ',', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.identity.favicon')), ',', JSON_EXTRACT(hodnota, '$.design_system') IS NULL) FROM ka_nastaveni WHERE promenna = 'look_draft'))")" \
  "|image/kaleta-logo.svg,image/kaleta-logo.svg,1"
curl -s -o "$WORK/response" "$B/"; grep -q 'kaleta-logo.svg' "$WORK/response" && { echo "  CHYBA  3.12: the draft logo is on the site before publishing"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.12: visitors do not see the draft logo"
check "3.12: the appearance preview shows the draft logo and icon" 200 "/?preview=vzhled" 'rel="icon" href="/image/kaleta-logo.svg"'
grep -q 'class="logo"[^>]*><img src="/image/kaleta-logo.svg"' "$WORK/response" && echo "  ok     3.12: the preview has the draft logo in the header" || { echo "  CHYBA  3.12: no draft logo in the preview"; ERRORS=$((ERRORS+1)); }
check "3.12: Site appearance edits the draft logo and the bar names it" 200 "/admin.php?module=appearance" 'id="logo" name="logo" value="image/kaleta-logo.svg"'
grep -q 'Nepublikované změny:</strong> logo, ikona webu' "$WORK/response" && echo "  ok     3.12: the look bar lists logo and icon" || { echo "  CHYBA  3.12: the look bar does not list the logo"; ERRORS=$((ERRORS+1)); }
publish_look
curl -s -o "$WORK/response" "$B/"
grep -q 'class="logo"[^>]*><img src="/image/kaleta-logo.svg"' "$WORK/response" && grep -q 'rel="icon" href="/image/kaleta-logo.svg"' "$WORK/response" && echo "  ok     3.12: publishing the look puts the logo and the icon live" || { echo "  CHYBA  3.12: the published logo is not on the site"; ERRORS=$((ERRORS+1)); }
appearance_identity image/kaleta-logo.svg ''
expect "3.12: an unchanged live logo stays, only the removed icon waits in the draft" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), '|', (SELECT CONCAT(JSON_EXTRACT(hodnota, '$.identity.logo') IS NULL, ',', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.identity.favicon'))) FROM ka_nastaveni WHERE promenna = 'look_draft'))")" "image/kaleta-logo.svg|1,"
appearance_identity '' ''; publish_look
expect "3.12: logo and icon removed again after publishing" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'favicon'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'))")" "||"

