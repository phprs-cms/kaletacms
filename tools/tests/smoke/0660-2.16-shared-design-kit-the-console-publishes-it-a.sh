echo "== 2.16: shared design kit – the console publishes it, a paired site receives it as drafts only (Fleet\Kit)"
# the console has a class, a token change, a component (with a custom-code element that must not travel) and a saved section
sq3 "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('kit-band', '{}', 'padding: 2rem;', '$(site_time)')" > /dev/null
sq3 "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('design_system', '{\"barvy\":{\"primarni\":\"#aa0000\"}}')" > /dev/null
sq3 "INSERT INTO ka_komponenty (nazev, vlastnosti, stavba, zmeneno) VALUES ('Kit card', '[]', '{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Kit card v1\"}},{\"typ\":\"html\",\"obsah\":{\"kod\":\"<script>alert(1)</script>\"}}]}]}', '$(site_time)')" > /dev/null
sq3 "INSERT INTO ka_sekce (nazev, prvek, zmeneno) VALUES ('Kit banner', '{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Kit banner\"}}]}', '$(site_time)')" > /dev/null
KIT_COMPONENT=$(sq3 "SELECT idm FROM ka_komponenty WHERE nazev = 'Kit card'"); KIT_SECTION=$(sq3 "SELECT idx FROM ka_sekce WHERE nazev = 'Kit banner'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
grep -q 'name="design_system"' "$WORK/response" && grep -q 'value="kit-band"' "$WORK/response" && grep -q "value=\"$KIT_COMPONENT\"" "$WORK/response" && echo "  ok     console: the shared kit screen offers the design system, classes, components and sections" || { echo "  CHYBA  console: the shared kit screen"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d design_system=1 -d 'classes[]=kit-band' -d "components[]=$KIT_COMPONENT" -d "sections[]=$KIT_SECTION"
expect "console: kit version 1 is published – signed content without the custom-code element" "$(sq3 "SELECT CONCAT(version, '|', manifest LIKE '%#aa0000%', '|', manifest LIKE '%kit-band%', '|', manifest LIKE '%Kit card v1%', '|', manifest LIKE '%Kit banner%', '|', manifest LIKE '%<script%', '|', LENGTH(sha256), '|', summary) FROM ka_fleet_kits")" "1|1|1|1|1|0|64|design system, 1 class, 1 component, 1 section"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)"
expect "console: an empty kit is not published" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_kits")" "1"
# a site that did not opt in ignores the announcement
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q 'name="fleet_kit"' "$WORK/response" && echo "  ok     Settings → Fleet console offers receiving the kit (off by default)" || { echo "  CHYBA  the kit opt-in is missing"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "a site with the kit off ignores it" "$(sq "SELECT CONCAT(COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'), '0'), '|', (SELECT COUNT(*) FROM ka_komponenty WHERE kit_key IS NOT NULL), '|', COALESCE((SELECT hodnota LIKE '%kit-band%' FROM ka_nastaveni WHERE promenna = 'look_draft'), 0))")" "0|0|0"
# with the kit on, the next report fetches and applies it – as drafts only
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_kit" -d "_csrf=$(csrf)" -d fleet_kit=1
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "kit on: the look draft has the token and the class, the published look and the classes are unchanged" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'), '|', (SELECT hodnota LIKE '%#aa0000%' AND hodnota LIKE '%kit-band%' FROM ka_nastaveni WHERE promenna = 'look_draft'), '|', COALESCE((SELECT hodnota LIKE '%#aa0000%' FROM ka_nastaveni WHERE promenna = 'design_system'), 0), '|', (SELECT COUNT(*) FROM ka_tridy WHERE nazev = 'kit-band'))")" "1|1|0|0"
expect "kit on: the component is a draft without a published build and without the code element, the section is in the library, the event is recorded" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_komponenty WHERE kit_key = 'kit-card' AND stavba IS NULL AND stavba_koncept LIKE '%Kit card v1%' AND stavba_koncept NOT LIKE '%<script%'), '|', (SELECT COUNT(*) FROM ka_sekce WHERE kit_key = 'kit-banner'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.kit_received'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE akce = 'fleet_kit_received'))")" "1|1|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q 'module=components' "$WORK/response" && grep -q 'module=appearance' "$WORK/response" && echo "  ok     the site shows the received version with links to the waiting drafts" || { echo "  CHYBA  the received kit is not shown"; ERRORS=$((ERRORS+1)); }
# a second version updates the same component instead of duplicating it; the console learns which version the site applied
sq3 "UPDATE ka_komponenty SET stavba = REPLACE(stavba, 'Kit card v1', 'Kit card v2') WHERE idm = $KIT_COMPONENT" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d "components[]=$KIT_COMPONENT"
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "kit version 2 updates the component's draft (no duplicate); the report carried the version applied before" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_komponenty WHERE kit_key = 'kit-card'), '|', (SELECT stavba_koncept LIKE '%Kit card v2%' FROM ka_komponenty WHERE kit_key = 'kit-card'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'))")|$(sq3 "SELECT heartbeat LIKE '%\"kit_version\":1%' FROM ka_fleet_sites WHERE id = $FLEET_ID")" "1|1|2|1"
# a kit whose bytes do not match the announced hash is refused and nothing changes; an unsigned request to the console gets nothing
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d design_system=1
sq3 "UPDATE ka_fleet_kits SET manifest = REPLACE(manifest, '#aa0000', '#bb0000') WHERE version = 3" > /dev/null
sleep 1; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "a tampered kit is refused: the version stays, the draft does not change, the refusal is an event" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'), '|', (SELECT hodnota LIKE '%#bb0000%' FROM ka_nastaveni WHERE promenna = 'look_draft'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.kit_refused'), '|', (SELECT hodnota <> '' FROM ka_nastaveni WHERE promenna = 'fleet_kit_error'))")" "2|0|1|1"
expect "console: a kit request without a valid signature is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/kit" -H 'X-Kaleta-Signature: AAAA' -d "{\"action\":\"kit\",\"site_id\":$FLEET_ID,\"ts\":$(date +%s)}")" "403"
expect "console: a kit request of an unknown site is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/kit" -d '{"action":"kit","site_id":99999}')" "404"
# MCP: the site reports the kit it applied, the console shows the version of each site
mcp site_info '{}' > "$WORK/response"; contains -q 'fleet_kit' "$WORK/response" && contains -q 'applied_at' "$WORK/response" && echo "  ok     MCP site_info reports the kit version on a member site" || { echo "  CHYBA  MCP site_info: fleet_kit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_sites","arguments":{}}}' > "$WORK/response"
contains -q 'kit_version' "$WORK/response" && contains -q 'contents' "$WORK/response" && echo "  ok     MCP list_sites on the console shows the newest kit and each site's version" || { echo "  CHYBA  MCP list_sites: kit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
