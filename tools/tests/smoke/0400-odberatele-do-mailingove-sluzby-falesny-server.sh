echo "== odběratelé do mailingové služby (falešný server)"
SERVICE_PORT=$((PORT + 2)); mkdir -p "$WORK/sluzba"
cat > "$WORK/sluzba/router.php" <<'PHP'
<?php
$log = __DIR__ . '/pozadavky.log';
if ($_SERVER['REQUEST_URI'] === '/_log') { header('Content-Type: text/plain'); @readfile($log); return true; }
$h = array_change_key_case(getallheaders());
file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . ($h['api-key'] ?? $h['authorization'] ?? $h['key'] ?? '-') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
if (str_contains($_SERVER['REQUEST_URI'], 'chyba')) { http_response_code(500); echo '{"message":"Invalid list"}'; return true; }
http_response_code(201); header('Content-Type: application/json'); echo '{}'; return true;
PHP
(cd "$WORK/sluzba" && exec php -S "127.0.0.1:$SERVICE_PORT" router.php > /dev/null 2>&1) & SERVICE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$SERVICE_PORT/_log" && break; sleep 0.2; done
set_service() { "${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('newsletter_service','$1'),('newsletter_key','$2'),('newsletter_list','$3'),('newsletter_webhook','$4'),('newsletter_test_url','http://127.0.0.1:$SERVICE_PORT'); DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('sluzba@example.cz', 1, '$(php -r 'echo bin2hex(random_bytes(16));')', '$(site_time)', '$(site_time)')"; : > "$WORK/sluzba/pozadavky.log"; }
subscriber_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=subscribers&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
last_request() { tail -1 "$WORK/sluzba/pozadavky.log"; }
set_service brevo brevo-klic 7 ''; subscriber_action sync
case "$(last_request)" in 'POST /brevo/v3/contacts brevo-klic {"email":"sluzba@example.cz","listIds":[7],"updateEnabled":true}') echo "  ok     Brevo: přidání do seznamu";; *) echo "  CHYBA  Brevo: $(last_request)"; ERRORS=$((ERRORS+1));; esac
expect "odběratel ve službě" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(sync, '/', (SELECT COUNT(*) FROM ka_odber_fronta)) FROM ka_odberatele")" "ok/0"
check "stav služby u odběratelů" 200 "/admin.php?module=subscribers" "odesláno"
IDOD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_odberatele"); subscriber_action delete -d "ido=$IDOD"; subscriber_action retry
case "$(last_request)" in 'POST /brevo/v3/contacts/lists/7/contacts/remove brevo-klic {"emails":["sluzba@example.cz"]}') echo "  ok     Brevo: smazaný odběratel odebrán ze seznamu";; *) echo "  CHYBA  Brevo odebrání: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailchimp 'abc123-us21' 'aud1' ''; subscriber_action sync
case "$(last_request)" in "PUT /mailchimp/3.0/lists/aud1/members/$(php -r 'echo md5("sluzba@example.cz");') Basic $(printf 'kaleta:abc123-us21' | base64) "*'"status":"subscribed"'*) echo "  ok     Mailchimp: člen audience";; *) echo "  CHYBA  Mailchimp: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailerlite ml-klic 99 ''; subscriber_action sync
case "$(last_request)" in 'POST /mailerlite/api/subscribers Bearer ml-klic {"email":"sluzba@example.cz","groups":["99"],"status":"active"}') echo "  ok     MailerLite: odběratel ve skupině";; *) echo "  CHYBA  MailerLite: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service smartemailing 'jmeno:klic' 5 ''; subscriber_action sync
case "$(last_request)" in "POST /smartemailing/api/v3/import Basic $(printf 'jmeno:klic' | base64) "*'"contactlists":[{"id":5,"status":"confirmed"}]'*) echo "  ok     SmartEmailing: import do seznamu";; *) echo "  CHYBA  SmartEmailing: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service webhook '' '' 'https://hook.example.com/odber'; subscriber_action sync
case "$(last_request)" in 'POST /webhook/odber - {"udalost":"novy_odberatel",'*'"email":"sluzba@example.cz"'*) echo "  ok     webhook: nový odběratel";; *) echo "  CHYBA  webhook: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service ecomail eco-klic chyba ''; subscriber_action sync
expect "nepovedený přenos čeká na další pokus s chybou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(pokusy, '|', chyba LIKE 'HTTP 500%', '|', dalsi > '$(site_time)') FROM ka_odber_fronta")" "1|1|1"
case "$(last_request)" in 'POST /ecomail/lists/chyba/subscribe eco-klic '*'"skip_confirmation":true'*) echo "  ok     Ecomail: přihlášení do seznamu";; *) echo "  CHYBA  Ecomail: $(last_request)"; ERRORS=$((ERRORS+1));; esac
mcp uprav_nastaveni '{}' | contains 'newsletter_klic\|eco-klic' && { echo "  CHYBA  MCP ukazuje klíč mailingové služby"; ERRORS=$((ERRORS+1)); } || echo "  ok     klíč mailingové služby MCP neukazuje"
kill "$SERVICE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna LIKE 'newsletter\_%'; DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele"

