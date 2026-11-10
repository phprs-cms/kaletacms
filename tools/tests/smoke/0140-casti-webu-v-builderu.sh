echo "== části webu v builderu"
check "části webu" 200 "/admin.php?module=parts" "Záhlaví"
check "záhlaví se otevře v builderu s koncept podle šablony" 200 "/admin.php?module=parts&action=builder&type=hlavicka&language=" 'id="stavitel-data"'
contains -q '"pri_rolovani":{"typ"' "$WORK/response" && echo "  ok     3.6 UXA-12: the header part offers the header options" || { echo "  CHYBA  3.6 UXA-12: the header part lost its header options"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf)
part_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&type=$2&language=" -d "_csrf=$TOKEN" "${@:3}"; }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     nepublikované záhlaví kreslí šablona" || { echo "  CHYBA  nepublikované záhlaví je na webu"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu záhlaví pro editor" 200 "/o-nas?part=hlavicka&build=koncept&editor=1" 'data-ka-typ="navigace"'
curl -s -o "$WORK/response" "$B/o-nas?part=hlavicka&build=koncept&editor=1"; ! grep -q 'data-ka-typ' "$WORK/response" && echo "  ok     náhled části nevidí návštěvník" || { echo "  CHYBA  koncept části vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
expect "publikování záhlaví" "$(part_action build_publish hlavicka)" 200
curl -s -o "$WORK/response" "$B/o-nas"
grep -q 'class="ka-nav"' "$WORK/response" && ! grep -q 'header class="hlavicka"' "$WORK/response" && grep -q 'href="/o-nas" aria-current="page"' "$WORK/response" && echo "  ok     záhlaví z builderu na webu s aktivní položkou menu" || { echo "  CHYBA  záhlaví z builderu"; ERRORS=$((ERRORS+1)); }
[ "$(grep -o '<style>' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && [ "$(grep -o '@layer stavitel {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && echo "  ok     stránka a části webu mají jedno CSS" || { echo "  CHYBA  CSS částí webu se opakuje"; ERRORS=$((ERRORS+1)); }
WRAPPER='{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}'
check "obálka novinky v builderu" 200 "/admin.php?module=parts&action=builder&type=novinka&language=" 'id="stavitel-data"'
part_action build_save novinka --data-urlencode "stavba=$WRAPPER" > /dev/null; part_action build_publish novinka > /dev/null
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete"; grep -q 'Pod článkem' "$WORK/response" && grep -q '<main id="obsah" class="stavba" tabindex="-1">' "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && grep -q 'Vítejte' "$WORK/response" && echo "  ok     obálka kolem novinky" || { echo "  CHYBA  obálka novinky"; ERRORS=$((ERRORS+1)); }
part_action build_save hlavicka --data-urlencode 'stavba={"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}' > /dev/null; part_action build_publish hlavicka > /dev/null
expect "předchozí záhlaví je ve verzích" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'hlavicka:'")" 1
part_action template hlavicka > /dev/null
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     vrácení záhlaví na šablonu" || { echo "  CHYBA  vrácení na šablonu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]},"publikovat":true}' > "$WORK/response"
grep -q 'publikováno' "$WORK/response" && echo "  ok     MCP: patička ze stavby" || { echo "  CHYBA  MCP patička"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<p class=\"ka-udaj\">&copy; $(date +%Y) Testovací firma</p>" "$WORK/response" && ! grep -q 'footer class="paticka"' "$WORK/response" && echo "  ok     patička z MCP na webu" || { echo "  CHYBA  patička z MCP na webu"; ERRORS=$((ERRORS+1)); }
expect "autor novinek k částem webu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=parts")" 403

