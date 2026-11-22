echo "== 3.50.1 review: an earlier true-until date, a page password, the click trigger, narrower rules, a hiding class or a placed component with a file form do not take an application form off the site without Applications"
r696_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
r696_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
r696_post() { # r696_post <jar> <screen query> <module&action query> <fields…>: opens the screen (the form token), posts as the browser does
  local jar="$1" screen="$2" target="$3"; shift 3
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?$screen"
  curl -s -b "$jar" -c "$jar" -o "$WORK/r696-after.html" -L -X POST "$B/admin.php?$target" -d "_csrf=$(csrf)" "$@"; }
r696_cli() { # r696_cli <php statements>: run with the test site's App as $app, as a person without Applications (Build::$purposeAllowed = false)
  { printf '%s\n' '<?php require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); Kaleta\Builder\Build::$purposeAllowed = false; try {'
    printf '%s\n' "$1" '} catch (\DomainException $e) { echo Kaleta\Mcp\Translator::message($e->getMessage()); }'; } > "$WORK/web/r696-cli.php"
  (cd "$WORK/web" && php r696-cli.php); rm -f "$WORK/web/r696-cli.php"; }
R696_OFF='take a job application form off the site – nothing was changed'
R696_SHADOW='hide a job application form or add another form with a file field'
R696_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications
R696_NOW=$(site_time); R696_PAST=$(site_date '-3 days'); R696_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R696_TOKEN="kaleta_$(openssl rand -hex 24)"; R696_ED_TOKEN="kaleta_$(openssl rand -hex 24)"
R696_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r696-editor', '$R696_HASH', 'Editor R696', 'editor-r696@example.com', 'en', 1, '$R696_NOW', '$R696_NOW', 0, 'off')" > /dev/null
R696_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r696-editor'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R696_ED, 'appearance_area'), ($R696_ED, 'pages');
  INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R696_ADMIN, 'r696 admin', 'full', 'all', SHA2('$R696_TOKEN', 256), '$R696_NOW'), ($R696_ED, 'r696 editor', 'full', 'all', SHA2('$R696_ED_TOKEN', 256), '$R696_NOW')" > /dev/null
R696_FORM='{"v":1,"deti":[{"id":"sek696","typ":"sekce","tridy":["wrap696"],"deti":[{"id":"frm696","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}}]}]}'
R696_EJAR="$WORK/r696-editor.jar"; r696_login r696-editor "$R696_EJAR"

# two live pages the pop-up shows on, and one id that no longer exists
r696_mcp "$R696_TOKEN" create_page '{"title":"R696 one","slug":"r696-one"}' > /dev/null
r696_mcp "$R696_TOKEN" create_page '{"title":"R696 two","slug":"r696-two"}' > /dev/null
R696_A=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'r696-one'"); R696_B=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'r696-two'")
R696_P=$(sq "INSERT INTO ka_popupy (nazev, adresa, spoustec, pravidla, stavba, aktivni) VALUES ('r696-pop', 'r696-pop', 'cas', '{\"kde\":\"vybrane\",\"stranky\":[$R696_A,$R696_B,987654]}', '$R696_FORM', 1); SELECT LAST_INSERT_ID();")
R696_POPUP_FORM() { r696_post "$R696_EJAR" "module=popups&action=edit&id=$R696_P" "module=popups&action=save" -d "idpp=$R696_P" -d adresa=r696-pop -d kde=vybrane "$@"; }
R696_POPUP_FORM -d nazev=r696-renamed -d spoustec=cas -d "stranky[]=$R696_B" -d "stranky[]=$R696_A"
expect "3.50.1 review: the editor renames the pop-up – the same pages in another order, without the deleted one, are no narrowing" "$(sq "SELECT nazev FROM ka_popupy WHERE idpp = $R696_P")" "r696-renamed"
R696_POPUP_FORM -d nazev=r696-renamed -d spoustec=cas -d "stranky[]=$R696_A"
R696_R1=$(grep -c "$R696_OFF" "$WORK/r696-after.html")
R696_POPUP_FORM -d nazev=r696-renamed -d spoustec=klik -d "stranky[]=$R696_A" -d "stranky[]=$R696_B"
R696_R2=$(grep -c "$R696_OFF" "$WORK/r696-after.html")
R696_POPUP_FORM -d nazev=r696-renamed -d spoustec=cas -d "stranky[]=$R696_A" -d "stranky[]=$R696_B" -d "valid_until=$R696_PAST"
R696_R3=$(grep -c "$R696_OFF" "$WORK/r696-after.html")
expect "3.50.1 review: but a page left out, the trigger only by clicking or a past true-until date is refused in the administration (said why), nothing changed" \
  "$R696_R1|$R696_R2|$R696_R3|$(sq "SELECT CONCAT(spoustec, '|', IFNULL(valid_until, '-'), '|', aktivni) FROM ka_popupy WHERE idpp = $R696_P")" "1|1|1|cas|-|1"
expect "3.50.1 review: nor over the editor's Claude connection (trigger click, valid_until – in English)" \
  "$(r696_mcp "$R696_ED_TOKEN" save_popup "{\"id\":$R696_P,\"trigger\":\"click\"}" | grep -c "$R696_OFF")|$(r696_mcp "$R696_ED_TOKEN" save_popup "{\"id\":$R696_P,\"valid_until\":\"$R696_PAST\"}" | grep -c "$R696_OFF")|$(sq "SELECT CONCAT(spoustec, '|', IFNULL(valid_until, '-')) FROM ka_popupy WHERE idpp = $R696_P")" "1|1|cas|-"
r696_mcp "$R696_ED_TOKEN" save_popup "{\"id\":$R696_P,\"rules\":{\"pages\":[$R696_B,$R696_A,987654]}}" > /dev/null
r696_mcp "$R696_ED_TOKEN" save_popup "{\"id\":$R696_P,\"name\":\"r696-mcp\",\"rules\":{\"pages\":[$R696_B,$R696_A]}}" > /dev/null
expect "3.50.1 review: the same rules reordered or without the deleted page save over the editor's connection" "$(sq "SELECT nazev FROM ka_popupy WHERE idpp = $R696_P")" "r696-mcp"

# the page with the published application form
r696_mcp "$R696_TOKEN" save_build "{\"id\":$R696_A,\"publish\":true,\"build\":$R696_FORM}" > /dev/null
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $R696_A" > /dev/null
R696_PAGE_FORM() { r696_post "$R696_EJAR" "module=pages&action=edit&id=$R696_A" "module=pages&action=save" -d "ids=$R696_A" -d "titulek=R696 one" -d seo_link=r696-one -d zobrazit=1 "$@"; }
R696_PAGE_FORM -d "valid_until=$R696_PAST"
R696_G1=$(grep -c "$R696_OFF" "$WORK/r696-after.html")
R696_PAGE_FORM --data-urlencode "heslo_stranky=R696-$(openssl rand -hex 4)-pw"
R696_G2=$(grep -c "$R696_OFF" "$WORK/r696-after.html")
expect "3.50.1 review: the editor cannot give the page a past true-until date nor a password in the administration (said why)" \
  "$R696_G1|$R696_G2|$(sq "SELECT CONCAT(IFNULL(valid_until, '-'), '|', heslo_hash IS NULL, '|', zobrazit) FROM ka_stranky WHERE ids = $R696_A")" "1|1|-|1|1"
expect "3.50.1 review: nor over the editor's connection (update_page valid_until – in English)" \
  "$(r696_mcp "$R696_ED_TOKEN" update_page "{\"id\":$R696_A,\"valid_until\":\"$R696_PAST\"}" | grep -c "$R696_OFF")|$(sq "SELECT IFNULL(valid_until, '-') FROM ka_stranky WHERE ids = $R696_A")" "1|-"
R696_PAGE_FORM -d "titulek=R696 one renamed"
expect "3.50.1 review: saving the page without those changes works" "$(sq "SELECT titulek FROM ka_stranky WHERE ids = $R696_A")" "R696 one renamed"
r696_mcp "$R696_TOKEN" update_page "{\"id\":$R696_A,\"valid_until\":\"$R696_PAST\"}" > /dev/null
expect "3.50.1 review: the administrator (with Applications) sets the date" "$(sq "SELECT valid_until FROM ka_stranky WHERE ids = $R696_A")" "$R696_PAST"
sq "UPDATE ka_stranky SET valid_until = NULL WHERE ids = $R696_A" > /dev/null

# the look: a draft class on the form's ancestor restyled to display none is not published without Applications; another class is
sq "DELETE FROM ka_tridy WHERE nazev IN ('wrap696', 'free696'); INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('wrap696', '{}', 'color: red;', '$R696_NOW')" > /dev/null
R696_DRAFT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")
r696_cli 'Kaleta\Core\Look::discard($app->settings()); Kaleta\Core\Look::setClass($app->settings(), "wrap696", ["styl" => ["mobil" => ["zobrazeni" => "none"]], "css" => ""]); Kaleta\Core\Look::publish($app);' > "$WORK/r696-look"
expect "3.50.1 review: Look::publish refuses a class of the form's ancestor hidden on phones (in English) – the class on the site stays, the draft stays" \
  "$(grep -c "$R696_SHADOW" "$WORK/r696-look")|$(sq "SELECT styl FROM ka_tridy WHERE nazev = 'wrap696'")|$(sq "SELECT hodnota LIKE '%wrap696%' FROM ka_nastaveni WHERE promenna = 'look_draft'")" "1|{}|1"
r696_cli 'Kaleta\Core\Look::discard($app->settings()); Kaleta\Core\Look::setClass($app->settings(), "wrap696", ["styl" => [], "css" => "color: blue;"]); Kaleta\Core\Look::publish($app); echo "published";' > "$WORK/r696-look"
expect "3.50.1 review: a change of the same class that does not hide is published" "$(cat "$WORK/r696-look")|$(sq "SELECT css FROM ka_tridy WHERE nazev = 'wrap696'")" "published|color: blue;"

# a placed component that gains a form with a file field: refused while it sits beside the application form
R696_C=$(sq "INSERT INTO ka_komponenty (nazev, vlastnosti, stavba) VALUES ('r696-comp', '[]', '{\"v\":1,\"deti\":[{\"id\":\"t696\",\"typ\":\"text\",\"obsah\":{\"text\":\"Hi\"}}]}'); SELECT LAST_INSERT_ID();")
r696_mcp "$R696_TOKEN" save_build "{\"id\":$R696_A,\"publish\":true,\"build\":$(printf '{"v":1,"deti":[{"id":"sek696","typ":"sekce","deti":[{"id":"frm696","typ":"formular","obsah":{"nazev":"Apply","ucel":"uchazec","pole":[{"typ":"text","popisek":"Name"}]}},{"id":"kmp696","typ":"komponenta","obsah":{"komponenta":"%s"}}]}]}' "$R696_C")}" > /dev/null
R696_FILEBUILD='{"v":1,"deti":[{"id":"f697","typ":"formular","obsah":{"nazev":"CV","pole":[{"typ":"soubor","popisek":"CV"}]}}]}'
sq "UPDATE ka_komponenty SET stavba_koncept = '$R696_FILEBUILD' WHERE idm = $R696_C" > /dev/null
r696_cli "Kaleta\Builder\Publisher::component(\$app, \$app->db()->one('SELECT * FROM {komponenty} WHERE idm = $R696_C')); echo 'published';" > "$WORK/r696-comp"
expect "3.50.1 review: Publisher::component refuses a file form in a component placed beside the application form (in English) – the published component stays" \
  "$(grep -c "$R696_SHADOW" "$WORK/r696-comp")|$(sq "SELECT stavba LIKE '%soubor%' FROM ka_komponenty WHERE idm = $R696_C")" "1|0"
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); Kaleta\Builder\Build::$purposeAllowed = true; Kaleta\Builder\Publisher::component($app, $app->db()->one("SELECT * FROM {komponenty} WHERE idm = ?", [(int) $argv[1]]));' -- "$R696_C")
expect "3.50.1 review: someone with Applications publishes it" "$(sq "SELECT stavba LIKE '%soubor%' FROM ka_komponenty WHERE idm = $R696_C")" "1"

# clean-up
sq "UPDATE ka_nastaveni SET hodnota = '$(printf '%s' "$R696_DRAFT" | sed "s/'/''/g")' WHERE promenna = 'look_draft'" > /dev/null
sq "DELETE FROM ka_tridy WHERE nazev IN ('wrap696', 'free696'); DELETE FROM ka_stavba_revize WHERE ids IN (${R696_A:-0}, ${R696_B:-0}) OR cast = 'komponenta:${R696_C:-0}';
  DELETE FROM ka_stranky WHERE ids IN (${R696_A:-0}, ${R696_B:-0}); DELETE FROM ka_popupy WHERE adresa = 'r696-pop'; DELETE FROM ka_komponenty WHERE nazev = 'r696-comp';
  DELETE FROM ka_api_tokeny WHERE nazev IN ('r696 admin', 'r696 editor'); DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${R696_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'r696-editor'" > /dev/null
features --exact "$R696_EXT"
rm -f "$R696_EJAR" "$WORK/r696-after.html" "$WORK/r696-look" "$WORK/r696-comp"
