echo "== 3.34 second review N6: while 0120 moves, the job enquiries still to move stay hidden from people without Applications everywhere – export, download, actions by id, My work, the customer's timeline"
r334_on
N6_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('n6-sam', '$N6_HASH', 'Sam N6', 'sam-n6@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts' UNION SELECT 'my_work') m WHERE user = 'n6-sam'" > /dev/null
N6_SAM=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n6-sam'")
N6_JAR="$WORK/n6-sam.jar"; rm -f "$N6_JAR"
curl -s -c "$N6_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$N6_JAR" -c "$N6_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=n6-sam" --data-urlencode "password=$PASSWORD"
N6_HEX=$(php -r 'echo bin2hex(random_bytes(12));'); mkdir -p "$WORK/web/storage/prilohy/2026/07"; printf 'CV of Nina N6' > "$WORK/web/storage/prilohy/2026/07/$N6_HEX.pdf"
N6_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, preset) VALUES ('Jobs N6', 'jobs-n6', '[]', 'jobs'); SELECT LAST_INSERT_ID();")
N6_NOW=$(site_time) # one moment for both columns: two clock reads a second apart would make the record look edited (ApplicationMove::LONE_DELETE)
N6_CONTACT=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('nina-n6@example.com', 'nina-n6@example.com', 'Nina N6', 'enquiry', '$N6_NOW', '$N6_NOW'); SELECT LAST_INSERT_ID();")
N6_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, tema, email, data, stav, prirazeno, contact_id) VALUES ('$(site_time '-1 hour')', 'Job application N6', 'kolekce:$N6_IDK', '/jobs-n6/welder', '', 'nina-n6@example.com',
  '[[\"Name\",\"Nina N6\"],[\"CV\",\"cv.pdf (1 kB)\",\"2026/07/$N6_HEX.pdf\"]]', 0, $N6_SAM, $N6_CONTACT); SELECT LAST_INSERT_ID();")
N6_UNTIL=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'applications_move_until'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_move_until', '$N6_E')" > /dev/null
n6_post() { local action="$1"; shift; curl -s -b "$N6_JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"; curl -s -b "$N6_JAR" -c "$N6_JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=enquiries&action=$action" -d "_csrf=$(csrf)" "$@"; }
expect "3.34 second review N6: the CSV export of a person without Applications leaves it out; the administrator's has it" \
  "$(curl -s -b "$N6_JAR" "$B/admin.php?module=enquiries&action=csv" | grep -c 'nina-n6@example.com')|$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&action=csv" | grep -c 'nina-n6@example.com')" "0|1"
expect "3.34 second review N6: the CV download by id and the detail answer 404" \
  "$(curl -s -b "$N6_JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=attachment&id=$N6_E&field=1")|$(curl -s -b "$N6_JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=detail&id=$N6_E")" "404|404"
n6_post status -d "idp=$N6_E" -d stav=2 > /dev/null; n6_post bulk -d "oznacene[]=$N6_E" -d provest=vyridit > /dev/null; n6_post delete -d "idp=$N6_E" > /dev/null; n6_post bulk -d "oznacene[]=$N6_E" -d provest=smazat > /dev/null
expect "3.34 second review N6: status, bulk and delete by id do nothing to it – the row, its status and its CV are as they were" \
  "$(sq "SELECT stav FROM ka_poptavky WHERE idp = $N6_E")|$([ -f "$WORK/web/storage/prilohy/2026/07/$N6_HEX.pdf" ] && echo kept || echo gone)" "0|kept"
curl -s -b "$N6_JAR" -o "$WORK/n6-work.html" "$B/admin.php?module=my_work"
expect "3.34 second review N6: My work and the customer's timeline leave it out for a person without Applications; with Applications the timeline has it" \
  "$(grep -c 'Nina N6' "$WORK/n6-work.html")|$(t334_php 'echo count(array_filter(Kaleta\Core\Contacts::timeline($app->db(), '"$N6_CONTACT"', 100, false), fn (array $e): bool => $e["kind"] === "enquiry")), "|", count(array_filter(Kaleta\Core\Contacts::timeline($app->db(), '"$N6_CONTACT"', 100, true), fn (array $e): bool => $e["kind"] === "enquiry"));')" \
  "0|0|1"
expect "3.34 second review N6: the retention of enquiries and of job enquiries never takes it while it moves" \
  "$(t334_php '$s = $app->settings(); echo (int) $app->db()->value("SELECT COUNT(*) FROM {poptavky} WHERE idp = '"$N6_E"' AND " . Kaleta\Admin\Modules\Enquiries::visible($app->db(), $s, false));')" "0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_move_until', '$N6_UNTIL')" > /dev/null
expect "3.34 second review N6: once nothing moves it is an ordinary enquiry again for everyone" "$(curl -s -b "$N6_JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=detail&id=$N6_E")" "200"
sq "DELETE FROM ka_poptavky WHERE idp = $N6_E; DELETE FROM ka_contacts WHERE id = $N6_CONTACT; DELETE FROM ka_kolekce WHERE idk = $N6_IDK; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $N6_SAM; DELETE FROM ka_uzivatele WHERE idu = $N6_SAM" > /dev/null
rm -f "$WORK/web/storage/prilohy/2026/07/$N6_HEX.pdf"
r334_off
