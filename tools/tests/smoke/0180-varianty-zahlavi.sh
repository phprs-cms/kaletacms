echo "== varianty záhlaví"
check "formulář varianty" 200 "/admin.php?module=parts&action=variant&type=hlavicka&language=" 'Název varianty'
TOKEN=$(csrf)
location=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=parts&action=save_variant&type=hlavicka&language=" -d "_csrf=$TOKEN" --data-urlencode "nazev=Landing page" -d "stranky[]=$IDZ")
case "$location" in *"variant=landing-page"*) echo "  ok     varianta založena a otevřena v builderu";; *) echo "  CHYBA  založení varianty: $location"; ERRORS=$((ERRORS+1));; esac
variant_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&type=hlavicka&language=&variant=landing-page" -d "_csrf=$TOKEN" "${@:2}"; }
variant_action build_save --data-urlencode 'stavba={"v":1,"deti":[]}' > /dev/null
expect "publikování varianty" "$(variant_action build_publish)" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; ! grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     stránka s prázdnou variantou je bez záhlaví" || { echo "  CHYBA  varianta záhlaví na stránce"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     ostatní stránky mají výchozí záhlaví" || { echo "  CHYBA  varianta se projevila i jinde"; ERRORS=$((ERRORS+1)); }
check "varianta v seznamu částí" 200 "/admin.php?module=parts" "Landing page"

