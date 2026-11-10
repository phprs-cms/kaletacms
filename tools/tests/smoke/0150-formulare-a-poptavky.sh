echo "== formuláře a poptávky"
# webhook receiver (1.8): logs every call with its signature headers; an address containing "chyba" answers 500
HOOK_PORT=$((PORT + 4)); mkdir -p "$WORK/hook"
cat > "$WORK/hook/router.php" <<'PHP'
<?php
$log = __DIR__ . '/calls.log';
$h = array_change_key_case(getallheaders());
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'event' => $h['x-kaleta-event'] ?? '', 'delivery' => $h['x-kaleta-delivery'] ?? '', 'ts' => $h['x-kaleta-timestamp'] ?? '',
    'sig' => $h['x-kaleta-signature'] ?? '', 'body' => file_get_contents('php://input')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
http_response_code(str_contains($_SERVER['REQUEST_URI'], 'chyba') ? 500 : 204); return true;
PHP
(cd "$WORK/hook" && exec php -S "127.0.0.1:$HOOK_PORT" router.php > /dev/null 2>&1) & HOOK_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$HOOK_PORT/ping" && break; sleep 0.2; done; : > "$WORK/hook/calls.log"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('webhook_enquiries', 'https://hooks.example.com/crm'), ('webhook_test_url', 'http://127.0.0.1:$HOOK_PORT')"
# hook_check <n>: is the n-th logged call signed with the site's secret? prints event|signature ok|uri
hook_check() { php -r '$c = json_decode(explode("\n", trim(file_get_contents($argv[1])))[$argv[2] - 1] ?? "null", true); if (!$c) { echo "none"; exit; }
  echo $c["event"], "|", hash_equals("sha256=" . hash_hmac("sha256", $c["ts"] . "." . $c["body"], $argv[3]), $c["sig"]) ? "signed" : "BAD", "|", $c["uri"];' "$WORK/hook/calls.log" "$1" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'")"; }
curl -s -o "$WORK/formular.html" "$B/kontakt"
grep -q 'class="ka-formular"' "$WORK/formular.html" && grep -q 'name="as_podpis"' "$WORK/formular.html" && echo "  ok     kontakt má poptávkový formulář" || { echo "  CHYBA  formulář na kontaktu"; ERRORS=$((ERRORS+1)); }
field_value() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/formular.html" | head -1 | sed 's/.*value="//;s/"$//'; }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
grep -q 'method="post" action="/form"' "$WORK/formular.html" && ! grep -q 'action="/formular"' "$WORK/formular.html" && echo "  ok     3.7: a form posts to the English /form" || { echo "  CHYBA  3.7: form action: $(grep -o 'action="[^"]*"' "$WORK/formular.html" | sort -u | tr '\n' ' ')"; ERRORS=$((ERRORS+1)); }
# 3.7: submit_form posts to the English /form, the checks right below to the Czech /formular (forms on pages cached before 3.7)
submit_form() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" "$@"; }
# too fast a submit (autofill): its own code and the message „počkejte chvilku“ (wait a moment), not „nepodařilo se ověřit“ (could not verify)
NOW=$(date +%s); FAST_SIGNATURE=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$FORM_SOURCE|$FORM_ELEMENT|$NOW" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$NOW" -d "as_podpis=$FAST_SIGNATURE" -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *result=rychle*) echo "  ok     příliš rychlé odeslání má vlastní výsledek";; *) echo "  CHYBA  příliš rychlé odeslání formuláře"; ERRORS=$((ERRORS+1));; esac
check "hlášení po příliš rychlém odeslání radí počkat" 200 "/kontakt?form=$FORM_ELEMENT&result=rychle" "Počkejte prosím chvilku a odešlete ho znovu"
grep -q 'type="text" autocomplete="name"' "$WORK/formular.html" && grep -q 'type="tel" autocomplete="tel" maxlength="30" pattern="' "$WORK/formular.html" && echo "  ok     jméno s automatickým vyplněním, telefon s kontrolou v prohlížeči" || { echo "  CHYBA  autocomplete jména nebo vzor telefonu"; ERRORS=$((ERRORS+1)); }
grep -q 'name="as_cas" value="[0-9]*" data-cekat="4"' "$WORK/formular.html" && echo "  ok     formulář nese minimální dobu pro odložené odeslání" || { echo "  CHYBA  data-cekat u formuláře"; ERRORS=$((ERRORS+1)); }
sleep 4
location=$(submit_form -H "Referer: $B/kontakt?utm_source=newsletter&utm_medium=email&utm_campaign=jaro" -d p0=Jana --data-urlencode p1=jana@example.cz -d p2= --data-urlencode "p3=Chci kuchyň na míru." -d p4=1)
case "$location" in *"/kontakt?form=$FORM_ELEMENT&result=ok#"*"$FORM_ELEMENT") echo "  ok     odeslání formuláře";; *) echo "  CHYBA  odeslání formuláře: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(stav)) FROM ka_poptavky")" "1/jana@example.cz/0"
expect "webhook: new enquiry delivered after the response, signed" "$(hook_check 1)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(event, '/', status, '/', delivered IS NOT NULL, '/', body IS NULL) FROM ka_webhook_deliveries")" "nova_poptavka|signed|/crm|nova_poptavka/204/1/1"
grep -q 'email.":."jana@example.cz' "$WORK/hook/calls.log" && echo "  ok     webhook: the enquiry data are in the body" || { echo "  CHYBA  webhook body: $(cat "$WORK/hook/calls.log")"; ERRORS=$((ERRORS+1)); }
expect "poptávka nese kampaň z utm_* stránky s formulářem" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT kampan FROM ka_poptavky")" "utm_source=newsletter&utm_medium=email&utm_campaign=jaro"
case "$(submit_form -d p0=Jana -d p1=neni-email -d p3=x -d p4=1)" in *result=pole\&field=1*) echo "  ok     neplatný e-mail odmítnut s číslem pole";; *) echo "  CHYBA  validace e-mailu"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/kontakt?form=$FORM_ELEMENT&result=pole&field=1"
grep -q 'aria-invalid="true" aria-describedby="f-'"$FORM_ELEMENT"'-1-chyba"' "$WORK/response" && grep -q 'data-obnovit' "$WORK/response" && echo "  ok     chybné pole je označené a vyplněné hodnoty se obnoví" || { echo "  CHYBA  označení chybného pole"; ERRORS=$((ERRORS+1)); }
case "$(submit_form -d p0=Jana --data-urlencode p1=jana@example.cz -d p3=x)" in *result=pole*) echo "  ok     chybějící souhlas odmítnut";; *) echo "  CHYBA  povinný souhlas"; ERRORS=$((ERRORS+1));; esac
submit_form -d p0=Robot --data-urlencode p1=r@example.cz -d p3=spam -d p4=1 -d web_adresa=http://spam.example > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d as_podpis=podvrh -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *result=overeni*) echo "  ok     podvržený podpis odmítnut";; *) echo "  CHYBA  podpis formuláře"; ERRORS=$((ERRORS+1));; esac
case "$(submit_form -d zdroj=stranka:999 -d p0=A)" in *form=*) echo "  CHYBA  neexistující formulář přijat"; ERRORS=$((ERRORS+1));; *) echo "  ok     neexistující formulář nic neuloží";; esac
expect "robot ani chyby poptávku nepřidaly" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_poptavky")" 1
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_poptavky")
check "poptávky v administraci" 200 "/admin.php?module=enquiries" "jana@example.cz"
check "detail poptávky" 200 "/admin.php?module=enquiries&action=detail&id=$IDP" "Chci kuchyň na míru."
grep -q '>Tester</option>' "$WORK/response" && ! grep -q '>Autor</option>' "$WORK/response" && echo "  ok     poptávku vyřizuje jen ten, kdo má přístup k Poptávkám" || { echo "  CHYBA  výběr Vyřizuje nabízí uživatele bez přístupu k Poptávkám"; ERRORS=$((ERRORS+1)); }
expect "otevřená poptávka je přečtená" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_poptavky")" 1
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=csv"; grep -q 'Chci kuchyň na míru.' "$WORK/response" && echo "  ok     export poptávek do CSV" || { echo "  CHYBA  CSV poptávek"; ERRORS=$((ERRORS+1)); }
check "poděkování po odeslání (na místě formuláře)" 200 "/kontakt?form=$FORM_ELEMENT&result=ok" 'class="ka-formular-hotovo"'

