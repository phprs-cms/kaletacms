echo "== 3.43 review: one hourly limit per user, only counted second steps logged, resetting pop-up counters needs the right to publish"
R343_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
r343_set() { sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null; }
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES
    ('c3890', '$R343_HASH', 'Editor 3890', 'c3890@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0),
    ('a3890', '$R343_HASH', 'Author 3890', 'a3890@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.x FROM ka_uzivatele JOIN (SELECT 'news' AS x UNION SELECT 'popups') m WHERE user IN ('c3890', 'a3890')" > /dev/null
R343_EDITOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'c3890'")
R343_AUTHOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a3890'")

# L1: one user with two token labels has ONE hourly limit; the undo session stays per label
R343_TA="kaleta_$(printf '39%.0s' $(seq 1 24))"; R343_TB="kaleta_$(printf '93%.0s' $(seq 1 24))"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) VALUES
    ($R343_EDITOR, 'Claude 3890 laptop', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$R343_TA")', '$(site_time)'),
    ($R343_EDITOR, 'Claude 3890 phone', '$(php -r 'echo hash("sha256", $argv[1]);' -- "$R343_TB")', '$(site_time)')" > /dev/null
r343_set claude_change_limit 1
R343_CAT=$(sq "SELECT nazev FROM ka_kategorie ORDER BY idt LIMIT 1")
R343_A=$(mcp_call "$R343_TA" create_news "{\"title\":\"Limit 3890 laptop\",\"category\":\"$R343_CAT\"}" | grep -c 'reached the limit' || true)
R343_B=$(mcp_call "$R343_TB" create_news "{\"title\":\"Limit 3890 phone\",\"category\":\"$R343_CAT\"}" | grep -c 'reached the limit' || true)
expect "3.43 review L1: the second token label of the same user does not get a second hourly limit" \
  "$R343_A|$R343_B|$(sq "SELECT COUNT(*) FROM ka_novinky WHERE titulek IN ('Limit 3890 laptop', 'Limit 3890 phone')")|$(sq "SELECT connection FROM ka_agent_sessions WHERE connection LIKE 'Claude 3890%'")" \
  "0|1|1|Claude 3890 laptop · c3890"
r343_set claude_change_limit 0
sq "DELETE FROM ka_api_tokeny WHERE nazev LIKE 'Claude 3890%'; DELETE FROM ka_novinky WHERE titulek LIKE 'Limit 3890%'; DELETE FROM ka_agent_sessions WHERE connection LIKE 'Claude 3890%'" > /dev/null

# L2: "sign-in expired" and "too many attempts" are not attempts – no change-log row, the account's counter stays
R343_LOGS() { sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'prihlaseni' AND akce = 'neuspech' AND popis LIKE 'second step%'"; }
R343_BEFORE=$(R343_LOGS)
rm -f "$WORK/jar-r343"; curl -s -c "$WORK/jar-r343" -b "$WORK/jar-r343" -o "$WORK/response" "$B/admin.php"
for i in 1 2 3; do curl -s -c "$WORK/jar-r343" -b "$WORK/jar-r343" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d krok=kod -d kod=123456; done
expect "3.43 review L2: a second step without a pending sign-in writes no change-log row" "$(R343_LOGS)" "$R343_BEFORE"
R343_HOST=$(php -r 'echo strtolower(parse_url($argv[1], PHP_URL_HOST));' -- "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")")
sq "INSERT INTO ka_uzivatele_klice (idu, nazev, otisk_id, id_klice, verejny, alg, pocitadlo, vytvoreno, discoverable, rp_id) VALUES ($R343_EDITOR, 'Key 3890', SHA2('c3890-key', 256), 'YzM4OTA', 'x', -7, 0, '$(site_time)', 1, '$R343_HOST')" > /dev/null
rm -f "$WORK/jar-r343"; curl -s -c "$WORK/jar-r343" -b "$WORK/jar-r343" -o "$WORK/response" "$B/admin.php"
curl -s -c "$WORK/jar-r343" -b "$WORK/jar-r343" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=c3890 --data-urlencode "password=$PASSWORD"
R343_IP=$(php -r 'echo substr(hash("sha256", "kaleta|127.0.0.1"), 0, 40);')
sq "INSERT INTO ka_kontrola_ip (ip_adresa, typ, cas) SELECT '$R343_IP', 'login', '$(site_time)' FROM (SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) n" > /dev/null
for i in 1 2 3; do curl -s -c "$WORK/jar-r343" -b "$WORK/jar-r343" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d krok=kod -d kod=0000-0000-0000-0000; done
expect "3.43 review L2: past the attempt limit the step answers \"too many attempts\" without a log row or a counted error" \
  "$(grep -c 'Too many attempts' "$WORK/response" || true)|$(R343_LOGS)|$(sq "SELECT pocet_chyb FROM ka_uzivatele WHERE idu = $R343_EDITOR")" "1|$R343_BEFORE|0"
sq "DELETE FROM ka_kontrola_ip WHERE typ IN ('login', 'totp'); DELETE FROM ka_uzivatele_klice WHERE idu = $R343_EDITOR" > /dev/null

# L4: resetting a pop-up's counters changes what the site reports at once – an author with the section is refused
sq "INSERT INTO ka_popupy (nazev, adresa, pravidla, zobrazeni, zavreni, konverze) VALUES ('Popup 3890', 'popup-3890', '{}', 40, 30, 20)" > /dev/null
R343_POPUP=$(sq "SELECT idpp FROM ka_popupy WHERE adresa = 'popup-3890'")
rm -f "$WORK/jar-r343a"; curl -s -c "$WORK/jar-r343a" -b "$WORK/jar-r343a" -o "$WORK/response" "$B/admin.php"
curl -s -c "$WORK/jar-r343a" -b "$WORK/jar-r343a" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=a3890 --data-urlencode "password=$PASSWORD"
curl -s -c "$WORK/jar-r343a" -b "$WORK/jar-r343a" -o "$WORK/response" "$B/admin.php?module=popups"
curl -s -L -c "$WORK/jar-r343a" -b "$WORK/jar-r343a" -o "$WORK/response" -X POST "$B/admin.php?module=popups&action=reset" -d "_csrf=$(csrf)" -d "idpp=$R343_POPUP"
R343_AUTHOR_RESET="$(grep -c 'Only an editor or an administrator can do this' "$WORK/response" || true) $(sq "SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $R343_POPUP")"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=popups"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=popups&action=reset" -d "_csrf=$(csrf)" -d "idpp=$R343_POPUP"
expect "3.43 review L4: an author cannot reset the counters; the administrator can" \
  "$R343_AUTHOR_RESET|$(sq "SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $R343_POPUP")" "1 40/30/20|0/0/0"
sq "DELETE FROM ka_popupy WHERE idpp = $R343_POPUP;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($R343_EDITOR, $R343_AUTHOR); DELETE FROM ka_uzivatele WHERE idu IN ($R343_EDITOR, $R343_AUTHOR)" > /dev/null
