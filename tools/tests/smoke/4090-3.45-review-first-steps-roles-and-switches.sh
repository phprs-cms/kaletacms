echo "== 3.45 review: the office's facts mean a reply and a follow-up (M2), a finished list is remembered (L7), Hide refuses an unknown list (L6), an old role called Team member saves (L4), level 0 is a team member (L5), yes/no over MCP (L8)"
r345_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
r345_setting() { sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = '$1'), '')"; }
R345_KEYS="'office_steps_hidden', 'first_steps_hidden', 'share_buttons'"
sq "DROP TABLE IF EXISTS ka_r345_backup; CREATE TABLE ka_r345_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($R345_KEYS)" > /dev/null

# M1: the guide has its own classes – nothing of it is styled as the first-steps box (.pruvodce)
check "3.45 review M1: the guide's index" 200 "/admin.php?action=guide" 'class="navod-kapitoly"'
check "3.45 review M1: a chapter of the guide" 200 "/admin.php?action=guide&chapter=quotes" '<article class="navod-kapitola">'
expect "3.45 review M1: … without the class of the first steps" "$(grep -c 'class="pruvodce' "$WORK/response" || true)" "0"

# M2: an enquiry that was only opened (status 1) and an answered spam row are no reply; status 2 is; a page's task is no follow-up
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'R345 read', 'r345-read@example.cz', '[]', 1)" > /dev/null
R345_READ=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'R345 read'")
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, kategorie) VALUES ('$(site_time)', 'R345 spam', 'r345-spam@example.cz', '[]', 2, 'spam')" > /dev/null
R345_SPAM=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'R345 spam'")
r345_replied() { r345_php 'echo implode(",", array_column($db->all("SELECT idp FROM {poptavky} WHERE idp IN (" . (int) $a[0] . ", " . (int) $a[1] . ") AND " . Kaleta\Admin\Modules\Enquiries::NOT_SPAM . " AND " . Kaleta\Admin\Modules\Enquiries::visible($db, $app->settings(), true) . " AND " . Kaleta\Admin\Kernel::OFFICE_REPLIED), "idp"));' "$R345_READ" "$R345_SPAM"; }
R345_BEFORE=$(r345_replied)
sq "UPDATE ka_poptavky SET stav = 2 WHERE idp = $R345_READ" > /dev/null
expect "3.45 review M2: an opened enquiry and an answered spam row are no reply; a handled enquiry is" "$R345_BEFORE|$(r345_replied)" "|$R345_READ"
R345_NOW=$(site_time)
sq "INSERT INTO ka_tasks (title, target, created_at, updated_at) VALUES ('R345 page', 'page:1', '$R345_NOW', '$R345_NOW'), ('R345 enquiry', 'enquiry:$R345_READ', '$R345_NOW', '$R345_NOW')" > /dev/null
expect "3.45 review M2: a follow-up is a task about an enquiry, a deal or a quote – not a page's" \
  "$(r345_php 'echo implode(",", array_column($db->all("SELECT title FROM {tasks} WHERE title LIKE ? AND " . Kaleta\Admin\Kernel::OFFICE_FOLLOW_UP, ["R345 %"]), "title"));')" "R345 enquiry"

# L7: once every office step is done, the dashboard stores office_steps_hidden itself and stops reading the facts
R345_ME=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R345_POSITION=$(sq "SELECT COALESCE(pozice, '') FROM ka_uzivatele WHERE idu = ${R345_ME:-0}")
sq "UPDATE ka_uzivatele SET pozice = 'R345 position' WHERE idu = ${R345_ME:-0}" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('office_steps_hidden', '0')" > /dev/null
R345_FACTS=$(r345_php '$f = Kaleta\Admin\Kernel::officeFacts($app, ["enquiries" => "x"], $db->one("SELECT * FROM {uzivatele} WHERE idu = ?", [(int) $a[0]]) ?? []); echo $f === null ? "none" : implode(",", array_map("intval", $f));' "$R345_ME")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.45 review L7: every office step done – no list, and office_steps_hidden is stored" \
  "$R345_FACTS|$(grep -c 'pruvodce-kancelar' "$WORK/response" || true)|$(r345_setting office_steps_hidden)" "1,1,1,1|0|1"
sq "UPDATE ka_uzivatele SET pozice = '$(sed "s/'/''/g" <<< "$R345_POSITION")' WHERE idu = ${R345_ME:-0}" > /dev/null

# L6: Hide with an unknown list is refused and hides nothing
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('first_steps_hidden', '0')" > /dev/null
R345_CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?action=hide_first_steps" -d "_csrf=$(csrf)" -d list=nonsense)
expect "3.45 review L6: hide_first_steps with an unknown list answers 400 and leaves the website's first steps alone" "$R345_CODE|$(r345_setting first_steps_hidden)" "400|0"

# L4: a role that already carries a now reserved name (Team member, in either language) can still be saved
sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('Team member', '', 0, 'enquiries'), ('Člen týmu', '', 0, 'enquiries')" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=roles"
R345_TOKEN=$(csrf)
R345_SAVED=""
for r345_name in 'Team member' 'Člen týmu'; do
  r345_id=$(sq "SELECT idr FROM ka_role WHERE nazev = '$r345_name' COLLATE utf8mb4_bin")
  R345_SAVED="$R345_SAVED$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$R345_TOKEN" -d "idr=$r345_id" --data-urlencode "nazev=$r345_name" -d uroven=0 -d 'moduly[]=enquiries') "
done
R345_NEW=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$R345_TOKEN" -d idr=0 --data-urlencode "nazev=Editor" -d uroven=0 -d 'moduly[]=enquiries')
expect "3.45 review L4: both old roles save under their names; a new role still cannot take a level's name" "$R345_SAVED|$R345_NEW|$(sq "SELECT COUNT(*) FROM ka_role WHERE nazev = 'Editor'")" "302 302 |200|0"
sq "DELETE FROM ka_role WHERE nazev IN ('Team member', 'Člen týmu')" > /dev/null

# L5: a user of level 0 without a role is a team member in the Users list, never an author
check "3.45 review L5: the Users list" 200 "/admin.php?module=users"
expect "3.45 review L5: … names level 0 a team member" "$([ "$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE admin = 0 AND (role IS NULL OR role = 0)")" = 0 ] && echo none || grep -cE '<td>(team member|člen týmu|Teammitglied|miembro del equipo)</td>' "$WORK/response" | sed 's/^[1-9][0-9]*$/yes/')|$(grep -cE '<td>(autor|author)</td>' "$WORK/response" || true)" \
  "$([ "$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE admin = 0 AND (role IS NULL OR role = 0)")" = 0 ] && echo none || echo yes)|0"

# L8: update_settings refuses a word a yes/no setting does not know, and still takes yes/no and 1/0
R345_TOKEN_MCP="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES (${R345_ME:-0}, 'r345', 'full', 'all', SHA2('$R345_TOKEN_MCP', 256), '$(site_time)')" > /dev/null
r345_settings() { mcp_call "$R345_TOKEN_MCP" update_settings "$1" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? "{}"), true) ?: []; echo isset($t["errors"]["share_buttons"]) ? "refused" : "stored";'; }
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('share_buttons', '1')" > /dev/null
R345_ENABLED=$(r345_settings '{"settings":{"share_buttons":"enabled"}}'); R345_AFTER=$(r345_setting share_buttons)
R345_NO=$(r345_settings '{"settings":{"share_buttons":"no"}}'); R345_AFTER_NO=$(r345_setting share_buttons)
R345_ON=$(r345_settings '{"settings":{"share_buttons":"On"}}'); R345_AFTER_ON=$(r345_setting share_buttons)
expect "3.45 review L8: \"enabled\" is refused and changes nothing; no stores 0, On stores 1" "$R345_ENABLED $R345_AFTER|$R345_NO $R345_AFTER_NO|$R345_ON $R345_AFTER_ON" "refused 1|stored 0|stored 1"
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'r345'" > /dev/null

# back as it was
sq "DELETE FROM ka_tasks WHERE title IN ('R345 page', 'R345 enquiry'); DELETE FROM ka_poptavky WHERE formular IN ('R345 read', 'R345 spam')" > /dev/null
sq "DELETE FROM ka_nastaveni WHERE promenna IN ($R345_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_r345_backup; DROP TABLE ka_r345_backup" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
