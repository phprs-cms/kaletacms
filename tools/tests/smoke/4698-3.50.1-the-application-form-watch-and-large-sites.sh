echo "== 3.50.1: the hourly application form watch rings the bell of Applications once and clears it; a pop-up on a site with more than 500 pages saves"
r698_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
r698_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
r698_watch() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Builder\ApplicationFormWatch::run($app);'); }
r698_says() { # r698_says <result> <part> <word>: the word when the run's result has the part, else the result
  if [[ "$1" == *"$2"* ]]; then echo "$3"; else echo "$1"; fi; }
r698_bell() { sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target = 'form:page:$R698_PAGE' AND user_id = $1"; }
R698_TEXT='A job application form can no longer be reached on the site'
R698_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
R698_STATE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'application_forms_watch'")
features +applications
background_jobs hold
R698_NOW=$(site_time); R698_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R698_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('r698-hr', '$R698_HASH', 'HR R698', 'hr-r698@example.com', 'en', 1, '$R698_NOW', '$R698_NOW', 0, 'off'),
  ('r698-editor', '$R698_HASH', 'Editor R698', 'editor-r698@example.com', 'en', 1, '$R698_NOW', '$R698_NOW', 0, 'off')" > /dev/null
R698_HR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r698-hr'"); R698_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r698-editor'")
R698_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R698_HR, 'applications'), ($R698_ED, 'pages'), ($R698_ED, 'appearance_area');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R698_ED, 'r698 editor', 'full', 'all', SHA2('$R698_ED_TOKEN', 256), '$R698_NOW')" > /dev/null
R698_FORM='{"v":1,"deti":[{"id":"sek698","typ":"sekce","deti":[{"id":"frm698","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
R698_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, stavba) VALUES ('r698-careers', 'R698 careers', '', 1, '$R698_FORM'); SELECT LAST_INSERT_ID();")

# the watch: the first run only remembers, a form going off the site alerts once, coming back clears the bell
sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
R698_W1=$(r698_watch)
sq "UPDATE ka_stranky SET zobrazit = 0 WHERE ids = $R698_PAGE" > /dev/null
R698_W2=$(r698_watch)
expect "3.50.1 watch: the first run remembers (no bell); the page with the form hidden rings the bell of the administrator and the user with Applications, not of the editor without it" \
  "$(r698_says "$R698_W1" 'started, ' started)|$(r698_says "$R698_W2" 'unreachable 1' alerted)|$(r698_bell "$R698_ADMIN")|$(r698_bell "$R698_HR")|$(r698_bell "$R698_ED")" \
  "started|alerted|1|1|0"
R698_HJAR="$WORK/r698-hr.jar"; r698_login r698-hr "$R698_HJAR"
curl -s -b "$R698_HJAR" -o "$WORK/r698-inbox.html" "$B/admin.php?action=inbox"
R698_EJAR="$WORK/r698-editor.jar"; r698_login r698-editor "$R698_EJAR"
curl -s -b "$R698_EJAR" -o "$WORK/r698-inbox-editor.html" "$B/admin.php?action=inbox"
expect "3.50.1 watch: the Notifications screen of the user with Applications says it and names the page; the editor's does not" \
  "$(grep -c "$R698_TEXT" "$WORK/r698-inbox.html")|$(grep -c 'R698 careers' "$WORK/r698-inbox.html")|$(grep -c "$R698_TEXT" "$WORK/r698-inbox-editor.html")" "1|1|0"
R698_NOTE=$(sq "SELECT id FROM ka_notifications WHERE kind = 'form_unreachable' AND user_id = $R698_HR")
curl -s -b "$R698_HJAR" -o /dev/null -D "$WORK/r698-open" "$B/admin.php?action=inbox&open=$R698_NOTE"
expect "3.50.1 watch: opening it leads to Applications" "$(grep -ci 'location:.*module=applications' "$WORK/r698-open")" "1"
R698_W3=$(r698_watch)
expect "3.50.1 watch: the next run while the form stays off rings no second time" "$(r698_says "$R698_W3" 'unreachable 0' quiet)|$(r698_bell "$R698_HR")" "quiet|1"
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $R698_PAGE" > /dev/null
R698_W4=$(r698_watch)
expect "3.50.1 watch: the page visible again clears the bell of everyone" \
  "$(r698_says "$R698_W4" 'reachable again 1' resolved)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target = 'form:page:$R698_PAGE'")" "resolved|0"
sq "UPDATE ka_stranky SET heslo_hash = 'x' WHERE ids = $R698_PAGE" > /dev/null
R698_W5=$(r698_watch)
expect "3.50.1 watch: a page password takes the form off the site too" "$(r698_says "$R698_W5" 'unreachable 1' alerted)|$(r698_bell "$R698_HR")" "alerted|1"

# a pop-up holding an application form on a site with more than 500 pages: the form lists 500, a chosen page beyond them stays
php -r 'for ($i = 0; $i < 510; $i++) { echo "(\x27r698-bulk-$i\x27, \x27R698 bulk $i\x27, \x27\x27, 1, 50000)" . ($i < 509 ? "," : ";") . "\n"; }' > "$WORK/r698-bulk.sql"
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, poradi) VALUES $(cat "$WORK/r698-bulk.sql")" > /dev/null
R698_LATE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, poradi) VALUES ('r698-late', 'R698 late', '', 1, 60000); SELECT LAST_INSERT_ID();")
R698_HOME=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL AND poradi < 50000 ORDER BY ids LIMIT 1")
R698_P=$(sq "INSERT INTO ka_popupy (nazev, adresa, spoustec, pravidla, stavba, aktivni) VALUES ('r698-pop', 'r698-pop', 'cas', '{\"kde\":\"vybrane\",\"stranky\":[$R698_HOME,$R698_LATE]}', '$R698_FORM', 1); SELECT LAST_INSERT_ID();")
curl -s -b "$R698_EJAR" -c "$R698_EJAR" -o "$WORK/response" "$B/admin.php?module=popups&action=edit&id=$R698_P"
R698_LISTED=$(grep -c "name=\"stranky\[\]\" value=\"$R698_LATE\"" "$WORK/response" || true)
curl -s -b "$R698_EJAR" -c "$R698_EJAR" -o "$WORK/r698-after.html" -L -X POST "$B/admin.php?module=popups&action=save" -d "_csrf=$(csrf)" \
  -d "idpp=$R698_P" -d nazev=r698-renamed -d adresa=r698-pop -d kde=vybrane -d spoustec=cas -d hodnota=5 -d "stranky[]=$R698_HOME"
expect "3.50.1 review: on a site with more than 500 pages the editor (no Applications) renames the pop-up – the page the form did not list stays chosen, nothing refused" \
  "$R698_LISTED|$(sq "SELECT nazev FROM ka_popupy WHERE idpp = $R698_P")|$(sq "SELECT JSON_CONTAINS(pravidla, '$R698_LATE', '\$.stranky') AND JSON_CONTAINS(pravidla, '$R698_HOME', '\$.stranky') FROM ka_popupy WHERE idpp = $R698_P")|$(grep -c 'take a job application form off the site' "$WORK/r698-after.html")" \
  "0|r698-renamed|1|0"
expect "3.50.1 review: over the editor's connection the same pages in another order save too; leaving out the page beyond 500 is still refused" \
  "$(r698_mcp "$R698_ED_TOKEN" save_popup "{\"id\":$R698_P,\"name\":\"r698-mcp\",\"rules\":{\"pages\":[$R698_LATE,$R698_HOME]}}" > /dev/null; sq "SELECT nazev FROM ka_popupy WHERE idpp = $R698_P")|$(r698_mcp "$R698_ED_TOKEN" save_popup "{\"id\":$R698_P,\"rules\":{\"pages\":[$R698_HOME]}}" | grep -c 'take a job application form off the site')" \
  "r698-mcp|1"

# clean-up
background_jobs resume
sq "DELETE FROM ka_notifications WHERE kind = 'form_unreachable' AND target = 'form:page:${R698_PAGE:-0}';
  DELETE FROM ka_popupy WHERE adresa = 'r698-pop'; DELETE FROM ka_stranky WHERE seo_link LIKE 'r698-%';
  DELETE FROM ka_api_tokeny WHERE nazev = 'r698 editor'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (${R698_HR:-0}, ${R698_ED:-0}); DELETE FROM ka_uzivatele WHERE user IN ('r698-hr', 'r698-editor')" > /dev/null
if [ -n "$R698_STATE" ]; then
  sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('application_forms_watch', '$(printf '%s' "$R698_STATE" | sed "s/'/''/g")') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
else
  sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
fi
features --exact "$R698_EXT"
rm -f "$R698_HJAR" "$R698_EJAR" "$WORK/r698-after.html" "$WORK/r698-inbox.html" "$WORK/r698-inbox-editor.html" "$WORK/r698-open" "$WORK/r698-bulk.sql"
