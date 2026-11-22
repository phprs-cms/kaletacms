echo "== 3.51 D2: the application form watch covers a jobs collection's item template – item pages off ring the bell of Applications once, on again clear it; the last position closing rings nothing"
d781_watch() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo Kaleta\Builder\ApplicationFormWatch::run($app);'); }
d781_says() { if [[ "$1" == *"$2"* ]]; then echo "$3"; else echo "$1"; fi; }
D781_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
D781_STATE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'application_forms_watch'")
features +applications
background_jobs hold
D781_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
D781_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('d781-hr', '$D781_HASH', 'HR D781', 'hr-d781@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0, 'off'),
  ('d781-editor', '$D781_HASH', 'Editor D781', 'editor-d781@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0, 'off')" > /dev/null
D781_HR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'd781-hr'"); D781_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'd781-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($D781_HR, 'applications'), ($D781_ED, 'collections')" > /dev/null
# a jobs collection whose item template holds a plain Form (no Purpose) – Front\Forms takes it as the application form
D781_TPL='{"v":1,"deti":[{"id":"sek781","typ":"sekce","deti":[{"id":"frm781","typ":"formular","obsah":{"nazev":"Apply","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
D781_COLL=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, preset, stavba) VALUES ('Positions D781', 'positions-d781', '[]', 1, 'jobs', '$D781_TPL'); SELECT LAST_INSERT_ID();")
D781_ITEM=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($D781_COLL, 'Welder D781', 'welder-d781', '{}', '$(site_time)', 1); SELECT LAST_INSERT_ID();")
D781_KEY=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo Kaleta\Builder\ApplicationFormWatch::collectionKey((int) $argv[1], "");' -- "$D781_COLL")
d781_bell() { sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target = '$D781_KEY' AND user_id = $1"; }

sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
D781_W1=$(d781_watch)
expect "3.51 D2: the first run takes the item template as it is – remembered as reachable, no bell" \
  "$(d781_says "$D781_W1" 'started, ' started)|$(sq "SELECT JSON_CONTAINS(hodnota, '\"${D781_KEY#form:coll:}\"', '\$.collections') FROM ka_nastaveni WHERE promenna = 'application_forms_watch'")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = '$D781_KEY'")" \
  "started|1|0"

# the last position hidden (no open job): item pages are still on – nothing rings
sq "UPDATE ka_kolekce_polozky SET zobrazit = 0 WHERE idp = $D781_ITEM" > /dev/null
D781_W2=$(d781_watch)
expect "3.51 D2: the last open position closing is normal – no bell" "$(d781_says "$D781_W2" 'unreachable 0' quiet)|$(d781_bell "$D781_HR")" "quiet|0"

# item pages switched off: one notification for the people with Applications (and administrators), none for the editor
sq "UPDATE ka_kolekce SET detail = 0 WHERE idk = $D781_COLL" > /dev/null
D781_W3=$(d781_watch)
expect "3.51 D2: item pages switched off ring the bell of the administrator and the user with Applications, not of the editor; it names the collection" \
  "$(d781_says "$D781_W3" 'unreachable 1' alerted)|$(d781_bell "$D781_ADMIN")|$(d781_bell "$D781_HR")|$(d781_bell "$D781_ED")|$(sq "SELECT excerpt FROM ka_notifications WHERE target = '$D781_KEY' AND user_id = $D781_HR" | sed 's/ – stránka položky$/ – item page/')" \
  "alerted|1|1|0|Positions D781 – item page"
D781_W4=$(d781_watch)
expect "3.51 D2: the next run while item pages stay off rings no second time" "$(d781_says "$D781_W4" 'unreachable 0' quiet)|$(d781_bell "$D781_HR")" "quiet|1"
sq "UPDATE ka_kolekce SET detail = 1 WHERE idk = $D781_COLL" > /dev/null
D781_W5=$(d781_watch)
expect "3.51 D2: item pages on again clear the bell of everyone" \
  "$(d781_says "$D781_W5" 'reachable again 1' resolved)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'form_unreachable' AND target = '$D781_KEY'")" "resolved|0"

# clean-up
background_jobs resume
sq "DELETE FROM ka_notifications WHERE kind = 'form_unreachable' AND target = '$D781_KEY'; DELETE FROM ka_kolekce_polozky WHERE idk = $D781_COLL; DELETE FROM ka_kolekce WHERE idk = $D781_COLL;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($D781_HR, $D781_ED); DELETE FROM ka_uzivatele WHERE idu IN ($D781_HR, $D781_ED)" > /dev/null
if [ -n "$D781_STATE" ]; then
  sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('application_forms_watch', '$(printf '%s' "$D781_STATE" | sed "s/'/''/g")') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
else
  sq "DELETE FROM ka_nastaveni WHERE promenna = 'application_forms_watch'" > /dev/null
fi
features --exact "$D781_EXT"
