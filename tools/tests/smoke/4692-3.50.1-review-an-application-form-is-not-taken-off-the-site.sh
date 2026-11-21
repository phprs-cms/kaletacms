echo "== 3.50.1 review: a pop-up or page with an application form is not switched off, re-ruled, trashed or hidden, and no component with a file form is added beside it, by someone without Applications"
r692_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
r692_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
r692_post() { # r692_post <jar> <screen query> <module&action query> <fields…>: opens the screen (the form token), posts as the browser does, prints the page it lands on
  local jar="$1" screen="$2" target="$3"; shift 3
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?$screen"
  curl -s -b "$jar" -c "$jar" -o "$WORK/r692-after.html" -L -X POST "$B/admin.php?$target" -d "_csrf=$(csrf)" "$@"; }
R692_OFF='take a job application form off the site – nothing was changed'
R692_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications +poptavky +quotes
R692_NOW=$(site_time); R692_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R692_TOKEN="kaleta_$(openssl rand -hex 24)"; R692_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
R692_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r692-editor', '$R692_HASH', 'Editor R692', 'editor-r692@example.com', 'en', 1, '$R692_NOW', '$R692_NOW', 0, 'off')" > /dev/null
R692_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r692-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R692_ED, 'appearance_area'), ($R692_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R692_ADMIN, 'r692 admin', 'full', 'all', SHA2('$R692_TOKEN', 256), '$R692_NOW'), ($R692_ED, 'r692 editor', 'full', 'all', SHA2('$R692_ED_TOKEN', 256), '$R692_NOW')" > /dev/null
R692_FORM='{"v":1,"deti":[{"id":"sek692","typ":"sekce","deti":[{"id":"frm692","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
R692_FILE='{"v":1,"deti":[{"id":"sek693","typ":"sekce","deti":[{"id":"frm693","typ":"formular","obsah":{"nazev":"Send a CV","pole":[{"typ":"soubor","popisek":"CV"}]}}]}]}'
R692_PLAIN='{"v":1,"deti":[{"id":"sek694","typ":"sekce","deti":[{"id":"txt694","typ":"text","obsah":{"text":"Hello"}}]}]}'
R692_P1=$(sq "INSERT INTO ka_popupy (nazev, adresa, pravidla, stavba, aktivni) VALUES ('r692-one', 'r692-one', '{}', '$R692_FORM', 1); SELECT LAST_INSERT_ID();")
R692_C_FILE=$(sq "INSERT INTO ka_komponenty (nazev, vlastnosti, stavba) VALUES ('r692-file', '[]', '$R692_FILE'); SELECT LAST_INSERT_ID();")
R692_C_PLAIN=$(sq "INSERT INTO ka_komponenty (nazev, vlastnosti, stavba) VALUES ('r692-plain', '[]', '$R692_PLAIN'); SELECT LAST_INSERT_ID();")
R692_EJAR="$WORK/r692-editor.jar"; r692_login r692-editor "$R692_EJAR"
R692_AJAR="$WORK/r692-admin.jar"; r692_login admin "$R692_AJAR"

# the pop-up: switching off and new rules in the administration and over MCP are refused for the editor; a new name is saved
r692_post "$R692_EJAR" "module=popups" "module=popups&action=toggle" -d "idpp=$R692_P1"
R692_TOGGLE=$(grep -c "$R692_OFF" "$WORK/r692-after.html")
r692_post "$R692_EJAR" "module=popups&action=edit&id=$R692_P1" "module=popups&action=save" -d "idpp=$R692_P1" -d nazev=r692-one -d adresa=r692-one -d kde=vse -d do=2020-01-01
R692_RULES=$(grep -c "$R692_OFF" "$WORK/r692-after.html")
expect "3.50.1 review: the editor without Applications cannot switch off the pop-up with an application form nor give it an end date in the administration (said why)" \
  "$R692_TOGGLE|$R692_RULES|$(sq "SELECT aktivni FROM ka_popupy WHERE idpp = $R692_P1")|$(sq "SELECT pravidla FROM ka_popupy WHERE idpp = $R692_P1")" "1|1|1|{}"
expect "3.50.1 review: nor over the editor's Claude connection (save_popup active false, or new rules – in English)" \
  "$(r692_mcp "$R692_ED_TOKEN" save_popup "{\"id\":$R692_P1,\"active\":false}" | grep -c "$R692_OFF")|$(r692_mcp "$R692_ED_TOKEN" save_popup "{\"id\":$R692_P1,\"rules\":{\"to\":\"2020-01-01\"}}" | grep -c "$R692_OFF")|$(sq "SELECT aktivni FROM ka_popupy WHERE idpp = $R692_P1")|$(sq "SELECT pravidla FROM ka_popupy WHERE idpp = $R692_P1")" "1|1|1|{}"
r692_post "$R692_EJAR" "module=popups&action=edit&id=$R692_P1" "module=popups&action=save" -d "idpp=$R692_P1" -d nazev=r692-renamed -d adresa=r692-one -d kde=vse
expect "3.50.1 review: the same rules with another name are saved by the editor" "$(sq "SELECT nazev FROM ka_popupy WHERE idpp = $R692_P1")|$(sq "SELECT aktivni FROM ka_popupy WHERE idpp = $R692_P1")" "r692-renamed|1"
r692_post "$R692_AJAR" "module=popups" "module=popups&action=toggle" -d "idpp=$R692_P1"
expect "3.50.1 review: the administrator (who has Applications) switches it off" "$(sq "SELECT aktivni FROM ka_popupy WHERE idpp = $R692_P1")" "0"

# the page: a visible page holding the published application form
r692_mcp "$R692_TOKEN" create_page '{"title":"Careers R692","slug":"careers-r692"}' > /dev/null
R692_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'careers-r692'")
r692_mcp "$R692_TOKEN" save_build "{\"id\":$R692_PAGE,\"publish\":true,\"build\":$R692_FORM}" > /dev/null
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $R692_PAGE" > /dev/null
r692_post "$R692_EJAR" "module=pages" "module=pages&action=delete" -d "ids=$R692_PAGE"
R692_DELETE=$(grep -c 'can remove a job application form – nothing was deleted' "$WORK/r692-after.html")
r692_post "$R692_EJAR" "module=pages" "module=pages&action=bulk" -d provest=skryt -d "oznacene[]=$R692_PAGE"
R692_BULK=$(grep -c 'Skipped: 1 (a job application form' "$WORK/r692-after.html")
r692_post "$R692_EJAR" "module=pages" "module=pages&action=bulk" -d provest=kos -d "oznacene[]=$R692_PAGE"
R692_BULK="$R692_BULK$(grep -c 'Skipped: 1 (a job application form' "$WORK/r692-after.html")"
expect "3.50.1 review: the editor cannot move the page with an application form to the trash, nor hide it or trash it in bulk (said why)" \
  "$R692_DELETE|$R692_BULK|$(sq "SELECT CONCAT(zobrazit, IFNULL(smazano, '-')) FROM ka_stranky WHERE ids = $R692_PAGE")" "1|11|1-"
expect "3.50.1 review: nor over the editor's Claude connection (trash_page, update_page visible false – in English)" \
  "$(r692_mcp "$R692_ED_TOKEN" trash_page "{\"id\":$R692_PAGE}" | grep -c 'can remove a job application form – nothing was deleted')|$(r692_mcp "$R692_ED_TOKEN" update_page "{\"id\":$R692_PAGE,\"visible\":false}" | grep -c "$R692_OFF")|$(sq "SELECT CONCAT(zobrazit, IFNULL(smazano, '-')) FROM ka_stranky WHERE ids = $R692_PAGE")" "1|1|1-"

# a component with a form that takes files, newly placed beside the application form, is refused; a plain one publishes
R692_WITH() { printf '{"v":1,"deti":[{"id":"sek692","typ":"sekce","deti":[{"id":"frm692","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}},{"id":"kmp692","typ":"komponenta","obsah":{"komponenta":"%s"}}]}]}' "$1"; }
expect "3.50.1 review: the editor's connection cannot publish a component with a file form beside the application form (in English) – the published build stays" \
  "$(r692_mcp "$R692_ED_TOKEN" save_build "{\"id\":$R692_PAGE,\"publish\":true,\"build\":$(R692_WITH "$R692_C_FILE")}" | grep -c 'can hide a job application form or add another form with a file field')|$(sq "SELECT stavba LIKE '%komponenta%' FROM ka_stranky WHERE ids = $R692_PAGE")" "1|0"
r692_mcp "$R692_ED_TOKEN" save_build "{\"id\":$R692_PAGE,\"publish\":true,\"build\":$(R692_WITH "$R692_C_PLAIN")}" > /dev/null
expect "3.50.1 review: a component without a file form beside it publishes" "$(sq "SELECT stavba LIKE '%komponenta%' FROM ka_stranky WHERE ids = $R692_PAGE")" "1"
r692_mcp "$R692_TOKEN" trash_page "{\"id\":$R692_PAGE}" > /dev/null
expect "3.50.1 review: the administrator moves the page to the trash" "$(sq "SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $R692_PAGE")" "1"

# Quotes settings: a held shorter retention period still says that the lower next number was not used
sq "DROP TABLE IF EXISTS r692_nastaveni; CREATE TABLE r692_nastaveni AS SELECT * FROM ka_nastaveni WHERE promenna LIKE 'quote%'" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quotes_months', '36')" > /dev/null
r692_post "$JAR" "module=quotes&action=settings" "module=quotes&action=settings_save" -d quote_number_format='{YYYY}-{NNNN}' -d quote_valid_days=30 -d quote_vat_rate=21 -d quote_vat_rates=21 -d quote_max_discount=20 -d quote_link_days=30 -d quotes_months=12 -d quote_next_number=1
expect "3.50.1 review: Quotes settings with a shorter period held for confirmation still say that the next number stays (the period waits)" \
  "$(grep -c 'Další číslo zůstává' "$WORK/r692-after.html")|$(setting_plain quotes_months)" "1|36"

# clean-up
sq "DELETE FROM ka_nastaveni WHERE promenna LIKE 'quote%'; INSERT INTO ka_nastaveni SELECT * FROM r692_nastaveni; DROP TABLE r692_nastaveni" > /dev/null
sq "DELETE FROM ka_stavba_revize WHERE ids = ${R692_PAGE:-0}; DELETE FROM ka_stranky WHERE ids = ${R692_PAGE:-0}" > /dev/null
sq "DELETE FROM ka_popupy WHERE adresa LIKE 'r692-%'; DELETE FROM ka_komponenty WHERE nazev LIKE 'r692-%'; DELETE FROM ka_api_tokeny WHERE nazev IN ('r692 admin', 'r692 editor');
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${R692_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'r692-editor'" > /dev/null
features --exact "$R692_EXT"
rm -f "$R692_EJAR" "$R692_AJAR" "$WORK/r692-after.html"
