echo "== draft look and whole-site preview (1.7)"
mcp discard_look '{}' > /dev/null
OLDPRIMARY=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")
OLDFONT=$(sq "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.pismo_titulky')), 'moderni') FROM ka_nastaveni WHERE promenna = 'design_system'")
OLDRADIUS=$(sq "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.zaobleni')), 'm') FROM ka_nastaveni WHERE promenna = 'design_system'")
NEWFONT=$([ "$OLDFONT" = zaoblene ] && echo strojove || echo zaoblene); NEWRADIUS=$([ "$OLDRADIUS" = l ] && echo s || echo l)
mcp update_design_system "{\"ds\":{\"barvy\":{\"primarni\":\"#123456\"},\"pismo_titulky\":\"$NEWFONT\",\"zaobleni\":\"$NEWRADIUS\"}}" > "$WORK/response"
SITEPREVIEW=$(mcp_value preview)
expect "MCP: the design system goes to the draft look, the site keeps the published one" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.design_system.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$OLDPRIMARY|#123456"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'ka-barva-primarni: #123456' "$WORK/response" && { echo "  CHYBA  the draft look is on the public site"; ERRORS=$((ERRORS+1)); } || echo "  ok     visitors do not see the draft look"
# 3.5 (UXA-03): the preview in Site appearance shows the saved draft look – to the administrator only, never to visitors
curl -s -o "$WORK/response" "$B/?preview=vzhled"; grep -q 'ka-barva-primarni: #123456' "$WORK/response" && { echo "  CHYBA  ?preview=vzhled shows the draft look to a visitor"; ERRORS=$((ERRORS+1)); } || echo "  ok     ?preview=vzhled keeps the published look for visitors"
curl -s -b "$JAR" -o "$WORK/response" "$B/?preview=vzhled"; expect "the Site appearance preview shows the saved draft look to the administrator" "$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")" "1"
check "Site appearance labels the preview as the draft look" 200 "/admin.php?module=appearance" "Náhled: koncept vzhledu"
# 3.5 (UXA-04): the look bar names the options (not the stored keys zaoblene, l) and shows colour swatches
expect "the look bar names the options and shows colour swatches" "$(grep -c "Písmo nadpisů [^<]* → $([ "$NEWFONT" = zaoblene ] && echo Zaoblené || echo 'Psací stroj')" "$WORK/response")|$(grep -c "→ $([ "$NEWRADIUS" = l ] && echo velké || echo jemné)" "$WORK/response")|$(grep -c 'class="vzhled-vzorek" style="background:#123456"' "$WORK/response")|$(grep -c -e "→ $NEWFONT" -e "→ $NEWRADIUS[,<]" "$WORK/response" || true)" "1|1|1|0"
mcp save_classes '{"css":".look-test { padding: 1rem }"}' > /dev/null
mcp save_classes '{"css":".look-test { padding: 2rem }"}' > "$WORK/response"
expect "MCP: a new class is live at once, a change of it waits in the draft" "$(sq "SELECT styl LIKE '%\"odsazeni_y\"%' OR css LIKE '%1rem%' FROM ka_tridy WHERE nazev = 'look-test'")|$(mcp_value look_draft 0)" "1|look-test"
mcp list_classes '{"name":"look-test"}' > "$WORK/response"
expect "MCP: list_classes shows the draft" "$(mcp_value 0 draft)" "1"
sq "DROP TABLE IF EXISTS menu_before; CREATE TABLE menu_before AS SELECT * FROM ka_menu"
mcp save_menu '{"location":"main","items":[{"type":"link","text":"Draft link","url":"/draft-link"}]}' > /dev/null
expect "MCP: save_menu goes to the draft look" "$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni' AND polozky LIKE '%draft-link%'")" "0"
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists the draft look" "$(mcp_value look_draft | grep -c 'look-test')" "1"
expect "MCP: the draft look summary names the options in English" "$(mcp_value look_draft | grep -c "Heading font [A-Za-z -]* → $([ "$NEWFONT" = zaoblene ] && echo Rounded || echo Typewriter)")|$(mcp_value look_draft | grep -c "→ $NEWFONT" || true)" "1|0"
curl -s -c "$WORK/preview.jar" -o "$WORK/response" "$SITEPREVIEW"
expect "the whole-site preview shows the draft look, the draft menu and the bar, and is not indexed" "$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")|$(grep -c 'draft-link' "$WORK/response")|$(grep -c 'ka-nahled-lista' "$WORK/response")|$(grep -c 'noindex' "$WORK/response")" "1|1|1|1"
DRAFTPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL AND zobrazit = 1 AND stavba IS NOT NULL ORDER BY ids LIMIT 1")
DRAFTSLUG=$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $DRAFTPAGE")
mcp edit_build "{\"id\":$DRAFTPAGE,\"operations\":[{\"op\":\"insert\",\"elements\":[{\"type\":\"heading\",\"content\":{\"text\":\"Only in the draft\"}}],\"into\":null,\"position\":0}]}" > /dev/null
curl -s -b "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG"
expect "browsing on in the preview (cookie) shows page drafts too" "$(grep -c 'Only in the draft' "$WORK/response")|$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")" "1|1"
curl -s -o "$WORK/response" "$B/$DRAFTSLUG"; ! grep -q 'Only in the draft' "$WORK/response" && echo "  ok     without the preview the page draft stays hidden" || { echo "  CHYBA  page draft visible without the preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$WORK/preview.jar" -c "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG?preview_end=1"
curl -s -b "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG"; ! grep -q 'Only in the draft' "$WORK/response" && echo "  ok     ending the preview shows the published site again" || { echo "  CHYBA  the preview did not end"; ERRORS=$((ERRORS+1)); }
mcp discard_draft "{\"id\":$DRAFTPAGE}" > /dev/null
check "the admin shows the look bar on the Appearance screens" 200 "/admin.php?module=menu" 'id="vzhled-koncept"' # 3.6: one line, its button says Publish
# 3.32 (A2): only on the dashboard and the Appearance screens – not on Pages, Users or Settings
expect "3.32 (A2): the look bar is on the dashboard, not on Pages, Users or Settings" "$(curl -s -b "$JAR" "$B/admin.php" | grep -c 'id="vzhled-koncept"')|$(curl -s -b "$JAR" "$B/admin.php?module=pages" | grep -c 'id="vzhled-koncept"')|$(curl -s -b "$JAR" "$B/admin.php?module=users" | grep -c 'id="vzhled-koncept"')|$(curl -s -b "$JAR" "$B/admin.php?module=settings" | grep -c 'id="vzhled-koncept"')" "1|0|0|0"
mcp publish_look '{}' > "$WORK/response"
expect "MCP: publish_look publishes everything and keeps the previous look" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")|$(sq "SELECT css LIKE '%2rem%' OR styl LIKE '%2rem%' OR styl LIKE '%\"xl\"%' FROM ka_tridy WHERE nazev = 'look-test'")|$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni' AND polozky LIKE '%draft-link%'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COUNT(*) > 0 FROM ka_look_versions")" "#123456|1|1||1"
expect "publishing the look is in the change log with what changed" "$(sq "SELECT popis LIKE '%#123456%' FROM ka_protokol WHERE akce = 'publish look' ORDER BY idp DESC LIMIT 1")" "1"
# #12 / PR #13: on the Czech site Claude gets the summary in English; the stored version keeps the site's language as before
expect "MCP: publish_look answers in English, the stored version stays in the site language" "$(mcp_value published | grep -c 'Design system: Primary')|$(mcp_value published | grep -c 'Main menu')|$(mcp_value published | grep -c 'Hlavní')|$(sq "SELECT summary LIKE '%Hlavní%' FROM ka_look_versions ORDER BY id DESC LIMIT 1")" "1|1|0|1"
check "3.5: the change log names the publication of the look in words, not by its action key" 200 "/admin.php?module=changelog" "vzhled publikován"
mcp list_look_versions '{}' > "$WORK/response"; VERSION=$(mcp_value versions 0 id)
mcp restore_look_version "{\"id\":$VERSION}" > /dev/null
expect "MCP: an earlier look comes back into the draft" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.design_system.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$OLDPRIMARY"
check "earlier looks in Site appearance" 200 "/admin.php?module=appearance" "Vrátit tento vzhled"
mcp discard_look '{}' > /dev/null
expect "MCP: discard_look" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "|#123456"
mcp update_design_system "{\"ds\":{\"barvy\":{\"primarni\":\"$OLDPRIMARY\"},\"pismo_titulky\":\"$OLDFONT\",\"zaobleni\":\"$OLDRADIUS\"}}" > /dev/null; mcp publish_look '{}' > /dev/null
sq "DELETE FROM ka_menu; INSERT INTO ka_menu SELECT * FROM menu_before; DROP TABLE menu_before"; rm -f "$WORK"/web/storage/cache/stranky/*.html

