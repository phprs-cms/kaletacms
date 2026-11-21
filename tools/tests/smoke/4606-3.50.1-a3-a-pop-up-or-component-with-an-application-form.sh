echo "== 3.50.1 A3c: a pop-up or component that holds an application form is deleted only by someone with Applications – in the administration and over MCP"
a606_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
a606_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
a606_delete() { # a606_delete <jar> <module> <id field> <id>: the delete form of the list as the browser posts it; prints the page it lands on
  curl -s -b "$1" -c "$1" -o "$WORK/response" "$B/admin.php?module=$2"
  curl -s -b "$1" -c "$1" -o "$WORK/a606-after.html" -L -X POST "$B/admin.php?module=$2&action=delete" -d "_csrf=$(csrf)" -d "$3=$4"; }
A606_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications +poptavky
A606_NOW=$(site_time); A606_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A606_TOKEN="kaleta_$(openssl rand -hex 24)"; A606_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
A606_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('a606-editor', '$A606_HASH', 'Editor A606', 'editor-a606@example.com', 'en', 1, '$A606_NOW', '$A606_NOW', 0, 'off')" > /dev/null
A606_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a606-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($A606_ED, 'appearance_area'), ($A606_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A606_ADMIN, 'a606 admin', 'full', 'all', SHA2('$A606_TOKEN', 256), '$A606_NOW'), ($A606_ED, 'a606 editor', 'full', 'all', SHA2('$A606_ED_TOKEN', 256), '$A606_NOW')" > /dev/null
A606_FORM='{"v":1,"deti":[{"id":"sek606","typ":"sekce","deti":[{"id":"frm606","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
A606_PLAIN='{"v":1,"deti":[{"id":"sek607","typ":"sekce","deti":[]}]}'
a606_popup() { sq "INSERT INTO ka_popupy (nazev, adresa, pravidla, stavba) VALUES ('$1', '$1', '{}', '$2'); SELECT LAST_INSERT_ID();"; }
a606_component() { sq "INSERT INTO ka_komponenty (nazev, vlastnosti, stavba) VALUES ('$1', '[]', '$2'); SELECT LAST_INSERT_ID();"; }
A606_P1=$(a606_popup a606-one "$A606_FORM"); A606_P2=$(a606_popup a606-two "$A606_FORM"); A606_P3=$(a606_popup a606-plain "$A606_PLAIN")
A606_C1=$(a606_component a606-one "$A606_FORM"); A606_C2=$(a606_component a606-two "$A606_FORM")
A606_EJAR="$WORK/a606-editor.jar"; a606_login a606-editor "$A606_EJAR"
A606_AJAR="$WORK/a606-admin.jar"; a606_login admin "$A606_AJAR"

a606_delete "$A606_EJAR" popups idpp "$A606_P1"
A606_FLASH=$(grep -c 'can remove a job application form – nothing was deleted' "$WORK/a606-after.html" || true)
a606_delete "$A606_EJAR" components idm "$A606_C1"
expect "3.50.1 A3c: the editor without Applications cannot delete a pop-up or a component with an application form in the administration (said why)" \
  "$A606_FLASH|$(grep -c 'can remove a job application form – nothing was deleted' "$WORK/a606-after.html")|$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $A606_P1")|$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $A606_C1")" "1|1|1|1"
expect "3.50.1 A3c: nor over the editor's own Claude connection (delete_popup, delete_component – in English)" \
  "$(a606_mcp "$A606_ED_TOKEN" delete_popup "{\"id\":$A606_P2}" | grep -c 'can remove a job application form – nothing was deleted')|$(a606_mcp "$A606_ED_TOKEN" delete_component "{\"id\":$A606_C2}" | grep -c 'can remove a job application form – nothing was deleted')|$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $A606_P2")|$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $A606_C2")" "1|1|1|1"
a606_delete "$A606_EJAR" popups idpp "$A606_P3"
expect "3.50.1 A3c: a pop-up without an application form is deleted by the editor as before" "$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $A606_P3")" "0"
a606_delete "$A606_AJAR" popups idpp "$A606_P1"
a606_delete "$A606_AJAR" components idm "$A606_C1"
expect "3.50.1 A3c: the administrator (who has Applications) deletes them in the administration and over MCP" \
  "$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $A606_P1")|$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $A606_C1")|$(a606_mcp "$A606_TOKEN" delete_popup "{\"id\":$A606_P2}" | grep -c "$A606_P2")|$(a606_mcp "$A606_TOKEN" delete_component "{\"id\":$A606_C2}" | grep -c "$A606_C2")|$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $A606_P2")$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $A606_C2")" \
  "0|0|1|1|00"

# A3a/A3d over MCP: the editor cannot publish the application form hidden; a label change publishes and leaves a line in the change log of Applications
a606_mcp "$A606_TOKEN" create_page '{"title":"Careers A606","slug":"careers-a606"}' > /dev/null
A606_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'careers-a606'")
a606_mcp "$A606_TOKEN" save_build "{\"id\":$A606_PAGE,\"publish\":true,\"build\":$A606_FORM}" > /dev/null
A606_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'build published'")
A606_HIDDEN='{"v":1,"deti":[{"id":"sek606","typ":"sekce","styl":{"mobil":{"zobrazeni":"none"}},"deti":[{"id":"frm606","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
A606_LABEL='{"v":1,"deti":[{"id":"sek606","typ":"sekce","deti":[{"id":"frm606","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Full name"}]}}]}]}'
expect "3.50.1 A3a: the editor's connection cannot publish the application form hidden on phones (in English) – the published build stays" \
  "$(a606_mcp "$A606_ED_TOKEN" save_build "{\"id\":$A606_PAGE,\"publish\":true,\"build\":$A606_HIDDEN}" | grep -c 'can hide a job application form or add another form with a file field')|$(sq "SELECT stavba LIKE '%zobrazeni%' FROM ka_stranky WHERE ids = $A606_PAGE")" "1|0"
a606_mcp "$A606_ED_TOKEN" save_build "{\"id\":$A606_PAGE,\"publish\":true,\"build\":$A606_LABEL}" > /dev/null
expect "3.50.1 A3d: a label change by the editor publishes and the change log of Applications names it" \
  "$(sq "SELECT stavba LIKE '%Full name%' FROM ka_stranky WHERE ids = $A606_PAGE")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'build published' AND kdo = $A606_ED")|$(( $(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'build published'") - A606_LOG ))" "1|1|1"

# clean-up
sq "DELETE FROM ka_stavba_revize WHERE ids = ${A606_PAGE:-0}; DELETE FROM ka_stranky WHERE ids = ${A606_PAGE:-0}" > /dev/null
sq "DELETE FROM ka_popupy WHERE adresa LIKE 'a606-%'; DELETE FROM ka_komponenty WHERE nazev LIKE 'a606-%'; DELETE FROM ka_api_tokeny WHERE nazev IN ('a606 admin', 'a606 editor');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${A606_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'a606-editor'" > /dev/null
features --exact "$A606_EXT"
rm -f "$A606_EJAR" "$A606_AJAR" "$WORK/a606-after.html"
