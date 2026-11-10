echo "== 2.8: security hygiene – unused accounts and Claude connections, automatic suspension"
# an administrator and an editor nobody has used for 100 days, an old personal token of the editor, an unused token of the admin created 70 days ago and a token used today
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('stary-spravce', '\$2y\$12\$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu', 'Stary Spravce', 2, '$(site_time)' - INTERVAL 100 DAY, '$(site_time)' - INTERVAL 100 DAY), ('stary-editor', '\$2y\$12\$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu', '', 1, '$(site_time)' - INTERVAL 100 DAY, '$(site_time)' - INTERVAL 100 DAY);
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, pouzit) SELECT idu, 'stary token', SHA2('hygiene-old', 256), '$(site_time)' - INTERVAL 100 DAY, '$(site_time)' - INTERVAL 100 DAY FROM ka_uzivatele WHERE user = 'stary-editor';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, expirace) SELECT idu, 'nepouzity token', SHA2('hygiene-unused', 256), '$(site_time)' - INTERVAL 70 DAY, '$(site_time)' + INTERVAL 1 YEAR FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, pouzit, expirace) SELECT idu, 'zivy token', SHA2('hygiene-live', 256), '$(site_time)' - INTERVAL 70 DAY, '$(site_time)', '$(site_time)' + INTERVAL 1 YEAR FROM ka_uzivatele WHERE user = 'admin'"
check "System status lists the unused accounts and connections with links" 200 "/admin.php?module=status" "Nepoužívané účty"
grep -q 'Stary Spravce (poslední aktivita' "$WORK/response" && grep -q 'stary-editor (poslední aktivita' "$WORK/response" && grep -q 'stary token (stary-editor)' "$WORK/response" && grep -q 'nepouzity token (Tester)' "$WORK/response" && ! grep -q 'zivy token (Tester)' "$WORK/response" \
  && grep -q 'vypnuto – nepoužívané účty a napojení se jen hlásí' "$WORK/response" && echo "  ok     System status: two unused accounts, two unused connections, the live token is fine, suspension off" || { echo "  CHYBA  System status hygiene findings"; grep -o 'Účty a přístup.*' "$WORK/response" | head -c 1500; ERRORS=$((ERRORS+1)); }
check "the settings form offers the automatic suspension (Security since 3.23)" 200 "/admin.php?module=settings&tab=security" 'name="auto_suspend\[\]"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"
contains -qE '"handover": ?"unused_account"' "$WORK/text" && contains -qE '"handover": ?"unused_connection"' "$WORK/text" && contains -qE '"handover": ?"auto_suspend"' "$WORK/text" && echo "  ok     site audit: unused accounts and connections, suspension off, before handing over" || { echo "  CHYBA  hand-over audit hygiene"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the daily run (the 2.8 scheduler calls SecurityHygiene::run once a day): here straight from the command line against the test site
run_hygiene() { php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo json_encode(Kaleta\Core\SecurityHygiene::run($app));' "$WORK/web"; }
expect "run() does nothing while the automatic suspension is off" "$(run_hygiene)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_uzivatele WHERE blokovat = 1 AND user LIKE 'stary-%'")" '{"blocked":[],"revoked":[]}|0'
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_suspend', 'ucty,napojeni')"
expect "run() blocks the unused accounts and revokes the unused connections" "$(run_hygiene)" '{"blocked":["stary-editor","Stary Spravce"],"revoked":["stary token (stary-editor)","nepouzity token (Tester)"]}'
expect "the admin in use stays, the old accounts are blocked with the reason, the live token stays" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT CONCAT(blokovat, blokovano_automaticky IS NULL) FROM ka_uzivatele WHERE user = 'admin'), '|', (SELECT GROUP_CONCAT(CONCAT(user, ':', blokovat, ':', blokovano_automaticky IS NOT NULL) ORDER BY user) FROM ka_uzivatele WHERE user LIKE 'stary-%'), '|', (SELECT GROUP_CONCAT(nazev ORDER BY nazev) FROM ka_api_tokeny WHERE nazev LIKE '%token'))")" "01|stary-editor:1:1,stary-spravce:1:1|zivy token"
expect "every automatic action is in the change log" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users' AND akce = 'auto_block'), '/', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'auto_revoke'))")" "2/2"
expect "every automatic action is an event without names (alerts, list_events)" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'security.account_suspended'), '/', (SELECT COUNT(*) FROM ka_events WHERE type = 'security.connection_revoked'))")" "2/2"
expect "a blocked account cannot use its token" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer hygiene-old" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"
check "Users shows why the account is blocked" 200 "/admin.php?module=users" "Zablokován automaticky"
grep -q '(zablokován automaticky)' "$WORK/response" && grep -q 'action=reactivate' "$WORK/response" && echo "  ok     Users: the automatic block is labelled and can be reactivated" || { echo "  CHYBA  Users list without the automatic block"; ERRORS=$((ERRORS+1)); }
IDS_OLD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'stary-editor'")
check "the user form explains the automatic block" 200 "/admin.php?module=users&action=edit&id=$IDS_OLD" "Odškrtněte políčko a uložte"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=reactivate" -d "_csrf=$TOKEN" -d "idu=$IDS_OLD" -d user=stary-editor
expect "reactivation unblocks the account and confirms it" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(blokovat, blokovano_automaticky IS NULL, potvrzeno > '$(site_time)' - INTERVAL 1 MINUTE) FROM ka_uzivatele WHERE user = 'stary-editor'")" "011"
expect "a reactivated account is not blocked again by the next run" "$(run_hygiene)" '{"blocked":[],"revoked":[]}'
IDS_ADMIN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
IDT_LIVE=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_api_tokeny WHERE nazev = 'zivy token'")
check "the administrator sees the connections of an account" 200 "/admin.php?module=users&action=edit&id=$IDS_ADMIN" 'id="napojeni"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=revoke_connection" -d "_csrf=$TOKEN" -d "idu=$IDS_ADMIN" -d "idt=$IDT_LIVE" -d user=admin
expect "the administrator revokes a connection from the user form" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE nazev = 'zivy token'")" "0"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'auto_suspend'"

