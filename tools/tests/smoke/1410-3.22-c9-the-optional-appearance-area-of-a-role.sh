echo "== 3.22 (C9): the optional Appearance area of a role"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=roles&action=new"; TOKEN=$(csrf)
expect "3.22 (C9): the role form offers the Appearance area unticked and says what stays with the administrator" \
  "$(grep -c 'name="moduly\[\]" value="appearance_area">' "$WORK/response")|$(grep -c 'Vzhled: menu, části webu, pop-up okna a komponenty' "$WORK/response")|$(grep -c 'zálohy a nastavení Clauda zůstávají správci' "$WORK/response")" "1|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=roles&action=new&preset=client"
expect "3.23.1 (N-C1): the ready-made Client role ticks the Appearance area and Business details, and says so" \
  "$(grep -c 'value="appearance_area" checked>' "$WORK/response")|$(grep -c 'value="business" checked>' "$WORK/response")|$(grep -c 'položky kolekcí, menu, patičku a otevírací dobu' "$WORK/response")" "1|1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 --data-urlencode "nazev=C9 manager" -d uroven=1 -d 'moduly[]=pages' -d 'moduly[]=appearance_area'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 --data-urlencode "nazev=C9 writer" -d uroven=0 -d 'moduly[]=news' -d 'moduly[]=appearance_area'
IDR_C9M=$(sq "SELECT idr FROM ka_role WHERE nazev = 'C9 manager'"); IDR_C9W=$(sq "SELECT idr FROM ka_role WHERE nazev = 'C9 writer'")
for c9user in "c9-manager r$IDR_C9M" "c9-writer r$IDR_C9W" "c9-editor 1"; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d "user=${c9user% *}" --data-urlencode "password=$PASSWORD" -d "admin=${c9user#* }"
done
expect "3.22 (C9): the role gives its members the area, a plain editor does not get it by default" \
  "$(sq "SELECT GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'c9-manager'")|$(sq "SELECT COUNT(*) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'c9-editor' AND p.ident_modulu IN ('appearance_area', 'menu', 'parts', 'popups', 'components')")" "appearance_area,pages|0"
check "3.22 (C9): the users list says the member has the Appearance area" 200 "/admin.php?module=users" 'Vzhled: menu, části webu, pop-up okna a komponenty'
c9_sign_in() { rm -f "$WORK/jar-$1"; sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null; curl -s -c "$WORK/jar-$1" -o "$WORK/response" "$B/admin.php"
  curl -s -b "$WORK/jar-$1" -c "$WORK/jar-$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
c9_code() { curl -s -b "$WORK/jar-$1" -c "$WORK/jar-$1" -o "$WORK/response" -w '%{http_code}' "$B$2"; }
c9_sign_in c9-manager; c9_sign_in c9-writer; c9_sign_in c9-editor
expect "3.22 (C9): with the area Menu, Site parts, Pop-ups and Components open; Site appearance, Settings, Users and Roles do not" \
  "$(c9_code c9-manager '/admin.php?module=menu')|$(c9_code c9-manager '/admin.php?module=parts')|$(c9_code c9-manager '/admin.php?module=popups')|$(c9_code c9-manager '/admin.php?module=components')|$(c9_code c9-manager '/admin.php?module=appearance')|$(c9_code c9-manager '/admin.php?module=settings&tab=backups')|$(c9_code c9-manager '/admin.php?module=claude_settings')|$(c9_code c9-manager '/admin.php?module=users')|$(c9_code c9-manager '/admin.php?module=roles')" \
  "200|200|200|200|403|403|403|403|403"
c9_code c9-manager '/admin.php' > /dev/null
c9_has() { grep -q "$1" "$WORK/response" && echo yes || echo no; }
expect "3.22 (C9): the admin menu shows the four screens under Appearance, not Site appearance" \
  "$(c9_has 'href="[^"]*module=parts"')|$(c9_has 'href="[^"]*module=popups"')|$(c9_has 'href="[^"]*module=menu"')|$(c9_has 'href="[^"]*module=components"')|$(c9_has 'href="[^"]*module=appearance"')" "yes|yes|yes|yes|no"
expect "3.22 (C9): an editor without the area keeps what they had – no Menu, no Site parts" "$(c9_code c9-editor '/admin.php?module=menu')|$(c9_code c9-editor '/admin.php?module=parts')" "403|403"
expect "3.22 (C9): the footer opens in the builder for the member, its canvas draws the draft for editing" \
  "$(c9_code c9-manager '/admin.php?module=parts&action=builder&type=paticka&language=')|$(grep -c 'id="stavitel-data"' "$WORK/response")|$(c9_code c9-manager '/?part=paticka&build=koncept&editor=1')|$([ "$(grep -c 'data-ka-id=' "$WORK/response")" -gt 0 ] && echo canvas)" "200|1|200|canvas"
c9_code c9-manager '/admin.php?module=parts&action=builder&type=paticka&language=' > /dev/null
C9_BUILD=$(php -r 'preg_match("/id=\"stavitel-data\">(.*?)<\/script>/s", (string) file_get_contents($argv[1]), $m); $b = (json_decode($m[1] ?? "", true) ?: [])["stavba"] ?? ["v" => 1, "deti" => []];
  $b["deti"][] = ["typ" => "sekce", "deti" => [["typ" => "nadpis", "obsah" => ["text" => "C9 footer line"]]]]; echo json_encode($b, JSON_UNESCAPED_UNICODE);' -- "$WORK/response")
c9_code c9-manager '/admin.php?module=parts' > /dev/null; C9_TOKEN=$(csrf)
expect "3.22 (C9): the member saves the footer draft from the builder (server-side check passes)" \
  "$(curl -s -b "$WORK/jar-c9-manager" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=build_save&type=paticka&language=" -d "_csrf=$C9_TOKEN" --data-urlencode "stavba=$C9_BUILD")|$(grep -c '"ok":true' "$WORK/response")|$(sq "SELECT stavba_koncept LIKE '%C9 footer line%' FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")" "200|1|1"
c9_code c9-manager '/admin.php?module=menu&location=paticka' > /dev/null; C9_TOKEN=$(csrf)
curl -s -b "$WORK/jar-c9-manager" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$C9_TOKEN" -d publikovat=1 --data-urlencode 'polozky=[{"typ":"odkaz","text":"C9 published","url":"/c9-published"}]'
expect "3.22 (C9): an editor-level member publishes the footer menu" "$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'paticka' AND polozky LIKE '%c9-published%'")" "1"
c9_code c9-writer '/admin.php?module=menu&location=paticka' > /dev/null; C9_TOKEN=$(csrf)
expect "3.22 (C9): a member who only writes drafts gets no publish button" "$(grep -c 'name="publikovat"' "$WORK/response")|$(grep -c 'Menu zveřejní editor nebo správce' "$WORK/response")" "0|1"
curl -s -b "$WORK/jar-c9-writer" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&location=paticka" -d "_csrf=$C9_TOKEN" -d publikovat=1 --data-urlencode 'polozky=[{"typ":"odkaz","text":"C9 writer","url":"/c9-writer"}]'
c9_code c9-writer '/admin.php?module=components' > /dev/null; C9_TOKEN=$(csrf)
curl -s -b "$WORK/jar-c9-writer" -o /dev/null -X POST "$B/admin.php?module=components&action=save" -d "_csrf=$C9_TOKEN" -d idm=0 --data-urlencode "nazev=C9 writer component"
C9_FROM=$(curl -s -b "$WORK/jar-c9-writer" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=from_element" -d "_csrf=$C9_TOKEN" --data-urlencode "name=C9 from element" --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"C9"}}]}')
expect "3.22 (C9): the drafts-only member's menu waits in the draft look, a component is not created (form or builder)" \
  "$(sq "SELECT COUNT(*) FROM ka_menu WHERE polozky LIKE '%c9-writer%'")|$(sq "SELECT hodnota LIKE '%c9-writer%' FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE nazev LIKE 'C9 %'")|$C9_FROM" "0|1|0|403"
# 3.23 (owner): whoever has the Appearance area previews the draft look (the menu draft above and a draft colour) read only;
# visitors and a plain editor never see it (c9_draft <user or ''> <path>: yes when the page has the draft colour)
mcp update_design_system '{"ds":{"barvy":{"primarni":"#c9c923"}}}' > /dev/null
c9_draft() { if [ -n "$1" ]; then curl -s -b "$WORK/jar-$1" -c "$WORK/jar-$1" -o "$WORK/response" "$B$2"; else curl -s -o "$WORK/response" "$B$2"; fi; grep -q 'ka-barva-primarni: #c9c923' "$WORK/response" && echo yes || echo no; }
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "3.23: the draft look – the members with the area see it in the preview and the builder; a plain editor and visitors never" \
  "$(curl -s -b "$JAR" -o "$WORK/response" "$B/?preview=vzhled"; grep -q 'ka-barva-primarni: #c9c923' "$WORK/response" && echo yes || echo no)|$(c9_draft c9-manager '/?preview=vzhled')|$(c9_draft c9-manager '/?build=koncept')|$(c9_draft c9-writer '/?preview=vzhled')|$(c9_draft c9-editor '/?preview=vzhled')|$(c9_draft c9-editor '/?build=koncept')|$(c9_draft '' '/?preview=vzhled')|$(c9_draft '' '/?build=koncept')|$(c9_draft '' '/')" \
  "yes|yes|yes|yes|no|no|no|no|no"
c9_code c9-manager '/admin.php?module=menu&location=paticka' > /dev/null
expect "3.23: on the Menu screen the member gets the read-only look bar – Review opens the draft look, no Publish, no Discard" \
  "$(grep -c 'id="vzhled-koncept"' "$WORK/response")|$(grep -c 'href="/?preview=vzhled"' "$WORK/response")|$(grep -c 'action=publish_look\|action=discard_look\|action=preview_site' "$WORK/response")|$(grep -c 'Vzhled publikuje správce' "$WORK/response")" "1|1|0|1"
expect "3.23: no look bar on a screen outside the area, nor for a plain editor; the member still cannot publish the look" \
  "$(c9_code c9-manager '/admin.php?module=pages' > /dev/null; grep -c 'id="vzhled-koncept"' "$WORK/response")|$(c9_code c9-editor '/admin.php?module=pages' > /dev/null; grep -c 'id="vzhled-koncept"' "$WORK/response")|$(c9_code c9-manager '/admin.php?module=menu' > /dev/null; curl -s -b "$WORK/jar-c9-manager" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=appearance&action=publish_look" -d "_csrf=$(csrf)")|$(sq "SELECT hodnota LIKE '%c9-writer%' FROM ka_nastaveni WHERE promenna = 'look_draft'")" "0|0|403|1"
sq "UPDATE ka_nastaveni SET hodnota = JSON_REMOVE(hodnota, '$.design_system') WHERE promenna = 'look_draft'" > /dev/null # the draft colour was only for this check
publish_look
# MCP (additive): a member's token reaches the site parts and the menu; a plain editor's does not, as before
C9_MCP="kaleta_$(printf '7%.0s' $(seq 1 48))"; C9_MCP_ED="kaleta_$(printf '8%.0s' $(seq 1 48))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'c9', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$C9_MCP")', '$(site_time)' FROM ka_uzivatele WHERE user = 'c9-manager'; INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'c9', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$C9_MCP_ED")', '$(site_time)' FROM ka_uzivatele WHERE user = 'c9-editor'" > /dev/null
mcp_as "$C9_MCP" list_site_parts '{}' > "$WORK/c9-parts"; mcp_as "$C9_MCP_ED" list_site_parts '{}' > "$WORK/c9-parts-ed"
mcp_as "$C9_MCP" site_info '{}' > "$WORK/c9-info"
expect "3.22 (C9): MCP – the member lists the site parts and site_info says can_edit_appearance; a plain editor is refused" \
  "$(grep -c '"isError":true' "$WORK/c9-parts")|$(grep -c 'paticka\|footer' "$WORK/c9-parts")|$(grep -c 'can_edit_appearance\\":true' "$WORK/c9-info")|$(grep -c '"isError":true' "$WORK/c9-parts-ed")" "0|1|1|1"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'c9'; DELETE FROM ka_uzivatele WHERE user LIKE 'c9-%'; DELETE FROM ka_role WHERE nazev LIKE 'C9 %'" > /dev/null
