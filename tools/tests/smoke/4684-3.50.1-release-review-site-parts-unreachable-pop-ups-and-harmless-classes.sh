echo "== 3.50.1 release review: an application form in a site part is guarded and watched; a pop-up whose chosen pages are all hidden alerts; a harmless class on the form publishes"
r684_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
r684_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
r684_watch() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Builder\ApplicationFormWatch::run($app);'); }
r684_says() { if [[ "$1" == *"$2"* ]]; then echo "$3"; else echo "$1"; fi; }
R684_REMOVE='remove a job application form – nothing was deleted'
R684_SHADOW='hide a job application form or add another form with a file field'
R684_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
R684_STATE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'application_forms_watch'")
features +applications
background_jobs hold
R684_NOW=$(site_time); R684_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R684_TOKEN="kaleta_$(openssl rand -hex 24)"; R684_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
R684_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r684-editor', '$R684_HASH', 'Editor R684', 'editor-r684@example.com', 'en', 1, '$R684_NOW', '$R684_NOW', 0, 'off')" > /dev/null
R684_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r684-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R684_ED, 'appearance_area'), ($R684_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R684_ADMIN, 'r684 admin', 'full', 'all', SHA2('$R684_TOKEN', 256), '$R684_NOW'), ($R684_ED, 'r684 editor', 'full', 'all', SHA2('$R684_ED_TOKEN', 256), '$R684_NOW')" > /dev/null
R684_FORM='{"v":1,"deti":[{"id":"sek684","typ":"sekce","deti":[{"id":"frm684","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
R684_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('r684-careers', 'R684 careers', '', 1); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_casti (typ, jazyk, varianta, nazev, stranky, stavba, zmeneno) VALUES ('paticka', '', 'r684-careers', 'R684 careers footer', '[$R684_PAGE]', '$R684_FORM', '$R684_NOW')" > /dev/null
R684_POP=$(sq "INSERT INTO ka_popupy (nazev, adresa, spoustec, hodnota, pravidla, stavba, aktivni) VALUES ('r684-pop', 'r684-pop', 'cas', 5, '{\"kde\":\"vybrane\",\"stranky\":[$R684_PAGE]}', '$R684_FORM', 1); SELECT LAST_INSERT_ID();")
R684_KEY=$(php -r 'echo "form:part:" . substr(sha1("paticka::r684-careers"), 0, 16);')
R684_VARIANT="SELECT COUNT(*) FROM ka_casti WHERE typ = 'paticka' AND varianta = 'r684-careers'"

# the guard: deleting the footer variant holding the form is refused without Applications – in the administration and over Claude
R684_EJAR="$WORK/r684-editor.jar"; r684_login r684-editor "$R684_EJAR"
curl -s -b "$R684_EJAR" -c "$R684_EJAR" -o "$WORK/response" "$B/admin.php?module=parts"
curl -s -b "$R684_EJAR" -c "$R684_EJAR" -o "$WORK/r684-after.html" -L -X POST "$B/admin.php?module=parts&action=template&type=paticka&variant=r684-careers" -d "_csrf=$(csrf)"
expect "3.50.1 release review: the editor without Applications cannot delete a footer variant holding an application form (said why); nor over the editor's connection (in English)" \
  "$(grep -c "$R684_REMOVE" "$WORK/r684-after.html")|$(sq "$R684_VARIANT")|$(r684_mcp "$R684_ED_TOKEN" save_part_variant '{"part":"footer","variant":"r684-careers","delete":true}' | grep -c "$R684_REMOVE")|$(sq "$R684_VARIANT")" \
  "1|1|1|1"

# the watch: the variant and the pop-up both reach the form through the careers page – hiding the page alerts both, showing it clears them
sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
R684_W1=$(r684_watch)
sq "UPDATE ka_stranky SET zobrazit = 0 WHERE ids = $R684_PAGE" > /dev/null
R684_W2=$(r684_watch)
expect "3.50.1 release review: hiding the only page of the footer variant and of the pop-up rings the bell for both (the part under its own key)" \
  "$(r684_says "$R684_W1" 'started, ' started)|$(r684_says "$R684_W2" 'unreachable 2' alerted)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target IN ('$R684_KEY', 'form:popup:$R684_POP') AND user_id = $R684_ADMIN")" \
  "started|alerted|2"
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $R684_PAGE" > /dev/null
R684_W3=$(r684_watch)
expect "3.50.1 release review: the page visible again clears both" \
  "$(r684_says "$R684_W3" 'reachable again 2' resolved)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target IN ('$R684_KEY', 'form:popup:$R684_POP')")" "resolved|0"
r684_mcp "$R684_TOKEN" save_part_variant '{"part":"footer","variant":"r684-careers","delete":true}' > /dev/null
R684_W4=$(r684_watch)
expect "3.50.1 release review: the administrator (with Applications) deletes the variant; the next run reports the form gone from the site" \
  "$(sq "$R684_VARIANT")|$(r684_says "$R684_W4" 'unreachable 1' alerted)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target = '$R684_KEY' AND user_id = $R684_ADMIN")" "0|alerted|1"

# a class that does not hide, added to the form's section, publishes without Applications; a hiding one does not
sq "DELETE FROM ka_tridy WHERE nazev IN ('free684', 'gone684'); INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('free684', '{}', 'color: red;', '$R684_NOW'), ('gone684', '{}', 'display: none;', '$R684_NOW')" > /dev/null
r684_mcp "$R684_TOKEN" save_build "{\"id\":$R684_PAGE,\"publish\":true,\"build\":$R684_FORM}" > /dev/null
R684_FREE=$(r684_mcp "$R684_ED_TOKEN" save_build "{\"id\":$R684_PAGE,\"publish\":true,\"build\":${R684_FORM/\"typ\":\"sekce\",/\"typ\":\"sekce\",\"tridy\":[\"free684\"],}}")
R684_GONE=$(r684_mcp "$R684_ED_TOKEN" save_build "{\"id\":$R684_PAGE,\"publish\":true,\"build\":${R684_FORM/\"typ\":\"sekce\",/\"typ\":\"sekce\",\"tridy\":[\"free684\",\"gone684\"],}}")
expect "3.50.1 release review: the editor publishes the form's section with a spacing class; the same section with a class that hides is refused (in English), the site keeps the first" \
  "$(printf '%s' "$R684_FREE" | grep -c "$R684_SHADOW")|$(printf '%s' "$R684_GONE" | grep -c "$R684_SHADOW")|$(sq "SELECT CONCAT(stavba LIKE '%free684%', stavba LIKE '%gone684%') FROM ka_stranky WHERE ids = $R684_PAGE")" \
  "0|1|10"

# clean-up
background_jobs resume
sq "DELETE FROM ka_notifications WHERE kind = 'form_unreachable' AND target IN ('$R684_KEY', 'form:popup:${R684_POP:-0}', 'form:page:${R684_PAGE:-0}');
  DELETE FROM ka_casti WHERE typ = 'paticka' AND varianta = 'r684-careers'; DELETE FROM ka_stavba_revize WHERE ids = ${R684_PAGE:-0} OR cast LIKE 'paticka:%r684-careers%';
  DELETE FROM ka_popupy WHERE adresa = 'r684-pop'; DELETE FROM ka_stranky WHERE seo_link = 'r684-careers'; DELETE FROM ka_tridy WHERE nazev IN ('free684', 'gone684');
  DELETE FROM ka_api_tokeny WHERE nazev IN ('r684 admin', 'r684 editor'); DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${R684_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'r684-editor'" > /dev/null
if [ -n "$R684_STATE" ]; then
  sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('application_forms_watch', '$(printf '%s' "$R684_STATE" | sed "s/'/''/g")') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
else
  sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
fi
features --exact "$R684_EXT"
rm -f "$R684_EJAR" "$WORK/r684-after.html"
