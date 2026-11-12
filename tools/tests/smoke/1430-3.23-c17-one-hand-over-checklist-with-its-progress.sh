echo "== 3.23 (C17): one hand-over checklist with its progress"
c17_audit() { mcp site_audit "{\"kind\":\"${1:-handover}\"}" > "$WORK/c17.json"; }
c17_progress() { php -r '$r = json_decode((string) file_get_contents($argv[1]), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? ""), true); $c = $t["handover_checklist"] ?? null;
  echo $c === null ? "none" : ($c["done"] === count(array_filter($c["items"], fn ($i) => $i["done"])) && $c["total"] === count($c["items"]) ? $c["done"] . "/" . $c["total"] : "bad");' -- "$WORK/c17.json"; }
# the client rows start open: no other active account (blocked for the check), the report off, the guardrails never saved, an empty Notebook
c17_finding() { php -r '$r = json_decode((string) file_get_contents($argv[1]), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? ""), true);
  echo count(array_filter($t["findings"] ?? [], fn ($f) => ($f["target"]["handover"] ?? "") === $argv[2]));' -- "$WORK/c17.json" "$1"; }
C17_ACTIVE=$(sq "SELECT GROUP_CONCAT(idu) FROM ka_uzivatele WHERE admin < 2 AND blokovat = 0")
[ -z "$C17_ACTIVE" ] || sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE idu IN ($C17_ACTIVE)" > /dev/null
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('claude_destructive', 'report_monthly', 'report_recipients'); DROP TABLE IF EXISTS ka_c17_notebook; CREATE TABLE ka_c17_notebook AS SELECT * FROM ka_notebook; DELETE FROM ka_notebook" > /dev/null
c17_audit
expect "3.23 (C17): MCP site_audit returns the checklist – the client account, the monthly report, the guardrails and the Notebook start open; done counts the rows" \
  "$(c17_row client_account)|$(c17_row monthly_report)|$(c17_row guardrails)|$(c17_row notebook)|$(c17_row agency)|$(c17_progress | grep -c '^[0-9]*/[0-9]*$')|$(c17_finding monthly_report)" "open|open|open|open|done|1|1"
C17_START=$(c17_progress)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=audit"
expect "3.23 (C17): Site audit shows the same progress and each row links to where it is set (Claude settings, Reports, Users, the Notebook)" \
  "$(grep -c "Předání klientovi <span class=\"stitek\">hotovo ${C17_START%/*} z ${C17_START#*/}</span>" "$WORK/response")|$(grep -c '<progress class="predani-postup"' "$WORK/response")|$(grep -A1 'data-predani-radek="guardrails"' "$WORK/response" | grep -c 'module=claude_settings')|$(grep -A1 'data-predani-radek="monthly_report"' "$WORK/response" | grep -c 'tab=reports')|$(grep -A1 'data-predani-radek="client_account"' "$WORK/response" | grep -c 'module=users')|$(grep -A1 'data-predani-radek="notebook"' "$WORK/response" | grep -c 'module=notebook')|$(grep -c 'pro klienta</span>' "$WORK/response")" \
  "1|1|1|1|1|1|0"
c17_audit accessibility
expect "3.23 (C17): another kind does not carry the checklist" "$(c17_progress)" "none"
# the ready-made Client role (by its name in any admin language) or the Appearance area makes the client's account; since
# 3.23.1 (N-C1) the row is done only when it can change the menu, the footer and the opening hours, otherwise it says what is
# missing and links the role; with no such account the link is a new user with the Client role (N-C9)
c17_fix() { php -r '$r = json_decode((string) file_get_contents($argv[1]), true); $t = json_decode((string) ($r["result"]["content"][0]["text"] ?? ""), true);
  echo implode(" ; ", array_map(fn ($f) => preg_replace("#^.*admin\.php#", "admin.php", $f["edit"]) . " " . $f["message"], array_values(array_filter($t["findings"] ?? [], fn ($f) => ($f["target"]["handover"] ?? "") === $argv[2]))));' -- "$WORK/c17.json" "$1"; }
c17_audit; C17_NONE=$(c17_fix client_account)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=roles"; TOKEN=$(csrf)
C17_KLIENT_NEW=0; [ -n "$(sq "SELECT idr FROM ka_role WHERE nazev = 'Klient'")" ] || { C17_KLIENT_NEW=1; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 --data-urlencode "nazev=Klient" -d uroven=1 -d 'moduly[]=pages'; }
C17_KLIENT_IDR=$(sq "SELECT idr FROM ka_role WHERE nazev = 'Klient' ORDER BY idr LIMIT 1"); C17_KLIENT_MODULES=$(sq "SELECT moduly FROM ka_role WHERE idr = $C17_KLIENT_IDR")
sq "UPDATE ka_role SET moduly = 'pages' WHERE idr = $C17_KLIENT_IDR" > /dev/null # a Client role saved before 3.23.1
c17_audit; C17_NONE_ROLE=$(c17_fix client_account)
expect "3.23.1 (N-C9): no client account – the fix creates one with the Client role (the preset while there is none, then a new user with it)" \
  "$(echo "$C17_NONE" | grep -cE '^admin\.php\?module=(roles&action=new&preset=client|users&action=new&role=[0-9]+) The client has no account of their own yet – create one with the Client role')|$(echo "$C17_NONE_ROLE" | grep -c "^admin.php?module=users&action=new&role=$C17_KLIENT_IDR The client has no account")" "1|1"
check "3.23.1 (N-C9): New user with ?role= has the role picked" 200 "/admin.php?module=users&action=new&role=$C17_KLIENT_IDR" "name=\"admin\" value=\"r$C17_KLIENT_IDR\" checked"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 --data-urlencode "nazev=C17 area" -d uroven=0 -d 'moduly[]=news' -d 'moduly[]=appearance_area'
for c17user in "c17-klient r$C17_KLIENT_IDR" "c17-area r$(sq "SELECT idr FROM ka_role WHERE nazev = 'C17 area'")"; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d "user=${c17user% *}" --data-urlencode "password=$PASSWORD" -d "admin=${c17user#* }"
done
expect "3.23.1 (N-C2): a user created by an administrator stores the administrator's admin language" "$(sq "SELECT GROUP_CONCAT(jazyk) FROM ka_uzivatele WHERE user IN ('c17-klient', 'c17-area')")" "cs,cs"
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE user = 'c17-area'" > /dev/null; c17_audit; C17_KLIENT="$(c17_row client_account) $(c17_fix client_account)"
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE user = 'c17-klient'; UPDATE ka_uzivatele SET blokovat = 0 WHERE user = 'c17-area'" > /dev/null; c17_audit; C17_AREA="$(c17_row client_account) $(c17_fix client_account)"
sq "UPDATE ka_uzivatele SET blokovat = 1 WHERE user = 'c17-area'" > /dev/null; c17_audit
expect "3.23.1 (N-C1): a Client-role account without the Appearance area or Business details is not done – the row says what it lacks and links the role; a blocked one does not count" \
  "$C17_KLIENT|$C17_AREA|$(c17_row client_account)" \
  "open admin.php?module=roles&action=edit&id=$C17_KLIENT_IDR The client account c17-klient cannot change the menu, the footer or the opening hours – add the Appearance area and Business details to its access.|open admin.php?module=roles&action=edit&id=$(sq "SELECT idr FROM ka_role WHERE nazev = 'C17 area'") The client account c17-area cannot change the opening hours – add Business details to its access.|open"
# the role as the 3.23.1 preset makes it: done; its member opens Menu and Business details
sq "UPDATE ka_role SET moduly = 'pages,news,business,appearance_area' WHERE idr = $C17_KLIENT_IDR; UPDATE ka_uzivatele SET blokovat = 0 WHERE user = 'c17-klient'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=roles&action=edit&id=$C17_KLIENT_IDR"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$(csrf)" -d idr="$C17_KLIENT_IDR" --data-urlencode "nazev=Klient" -d uroven=1 -d 'moduly[]=pages' -d 'moduly[]=news' -d 'moduly[]=business' -d 'moduly[]=appearance_area'
c17_audit; c9_sign_in c17-klient
expect "3.23.1 (N-C1): a Client-role account with the Appearance area and Business details is done and opens Menu, Site parts and Business details (200), not Site appearance or Settings" \
  "$(c17_row client_account)|$(c9_code c17-klient '/admin.php?module=menu')|$(c9_code c17-klient '/admin.php?module=parts')|$(c9_code c17-klient '/admin.php?module=business')|$(c9_code c17-klient '/admin.php?module=appearance')|$(c9_code c17-klient '/admin.php?module=settings')" "done|200|200|200|403|403"
sq "UPDATE ka_role SET moduly = '$C17_KLIENT_MODULES' WHERE idr = $C17_KLIENT_IDR; UPDATE ka_uzivatele SET blokovat = 1 WHERE user = 'c17-klient'" > /dev/null
# 3.23.1 (N-C9): one's own two-step sign-in leads to My account (where it is switched on), the privacy policy step to that page
C17_ME=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'") # the MCP token of the test is the admin's
C17_2FA=$(sq "SELECT CONCAT(totp_tajemstvi, '|', (SELECT COUNT(*) FROM ka_uzivatele_klice k WHERE k.idu = u.idu)) FROM ka_uzivatele u WHERE idu = ${C17_ME:-0}")
expect "3.23.1 (N-C9): the administrator's own two-step finding links My account, the anchor exists there" \
  "$( [ "$C17_2FA" = '|0' ] && { c17_fix two_step | grep -q 'admin.php?action=account#two-factor The administrator' && echo own || echo "$(c17_fix two_step)"; } || echo own)|$(curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; grep -c '<fieldset id="two-factor">' "$WORK/response")" "own|1"
C17_STEPS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'first_steps_hidden'"); setting first_steps_hidden 0
C17_PRIVACY=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL AND jazyk = '' AND (seo_link = 'zasady-ochrany-osobnich-udaju' OR seo_link LIKE '%privacy%') ORDER BY seo_link = 'zasady-ochrany-osobnich-udaju' DESC, ids LIMIT 1")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.23.1 (N-C9): the first step about the privacy policy opens that page's settings, not the list of pages" \
  "$(grep -o 'href="[^"]*"><strong>Doplňte zásady ochrany osobních údajů' "$WORK/response" | grep -c "module=pages&amp;action=edit&amp;id=${C17_PRIVACY:-none}\"")" "$(grep -c '<strong>Doplňte zásady ochrany osobních údajů' "$WORK/response")"
setting first_steps_hidden "${C17_STEPS:-0}"
# the monthly report: on but only to the site e-mail is not done, with the client's address it is (report_last_month = last month: nothing is sent)
setting report_last_month "$(site_time 'first day of last month' Y-m)"; setting report_monthly 1; c17_audit; C17_REPORT=$(c17_row monthly_report)
setting report_recipients 'klient@example.cz'; c17_audit
expect "3.23 (C17): the monthly report row is done only with a recipient" "$C17_REPORT|$(c17_row monthly_report)" "open|done"
setting report_monthly 0
# the guardrails: saving Claude settings is reviewing them; the Notebook: one note
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=claude_settings"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=claude_settings&action=save" -d "_csrf=$(csrf)" -d tab=claude -d claude_destructive=1 --data-urlencode "claude_instructions=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_instructions'")"
sq "INSERT INTO ka_notebook (topic, title, text, author, created_at, updated_at) VALUES ('decisions', 'C17', 'Why the site is built this way.', 'Tester', '$(site_time)', '$(site_time)')" > /dev/null
c17_audit
expect "3.23 (C17): the guardrails row is done after Claude settings were saved, the Notebook row after the first note; the progress grows by them" \
  "$(c17_row guardrails)|$(c17_row notebook)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_destructive'")|$(( ${C17_START%/*} + 2 ))/${C17_START#*/}" "done|done|1|$(c17_progress)"
sq "DELETE FROM ka_notebook; INSERT INTO ka_notebook SELECT * FROM ka_c17_notebook; DROP TABLE ka_c17_notebook; DELETE FROM ka_nastaveni WHERE promenna IN ('report_recipients', 'report_monthly'); DELETE FROM ka_uzivatele WHERE user LIKE 'c17-%'; DELETE FROM ka_role WHERE nazev = 'C17 area'" > /dev/null
[ "$C17_KLIENT_NEW" = 0 ] || sq "DELETE FROM ka_role WHERE nazev = 'Klient'" > /dev/null
[ -z "$C17_ACTIVE" ] || sq "UPDATE ka_uzivatele SET blokovat = 0 WHERE idu IN ($C17_ACTIVE)" > /dev/null
# 3.23 (U10): the builder's choices of dynamic elements – collections and their categories, news categories, the services and
# people of Bookings – only what the person may see in the administration: an editor without Bookings gets no services or
# people (null: the editor keeps a free field), without Collections no hidden category; never an e-mail of a person
U10_BOOKINGS=$(sq "SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'")
[ "$U10_BOOKINGS" = 1 ] || sq "UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',bookings') WHERE promenna = 'extensions'" > /dev/null
sq "INSERT INTO ka_booking_services (name, active) VALUES ('U10 service', 0); INSERT INTO ka_booking_staff (name, email) VALUES ('U10 person', 'u10-person@example.com')" > /dev/null
mcp save_collection_category '{"collection":"produkty","name":"Skryta U10","slug":"skryta-u10","visible":false}' > /dev/null
mcp save_collection_category '{"collection":"produkty","name":"Viditelna U10","slug":"viditelna-u10","visible":true}' > /dev/null
U10_PAGE=$(sq "SELECT MIN(ids) FROM ka_stranky WHERE smazano IS NULL")
check "3.23 (U10): the builder carries the choices of dynamic elements" 200 "/admin.php?module=pages&action=builder&id=$U10_PAGE" '"volby":{"kategorie":{'
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=build_choices&id=$U10_PAGE"
grep -q '"ok":true' "$WORK/response" && grep -q '"nazev":"U10 service (' "$WORK/response" && grep -q '"nazev":"U10 person"' "$WORK/response" && grep -q '"adresa":"skryta-u10"' "$WORK/response" \
  && grep -q '"adresa":"viditelna-u10"' "$WORK/response" && grep -q '"kategorieNovinek":\[{' "$WORK/response" && ! grep -q 'u10-person@example.com' "$WORK/response" \
  && echo "  ok     3.23 (U10): the administrator's choices – an inactive service marked, people by name only, hidden and visible categories, news categories" \
  || { echo "  CHYBA  3.23 (U10): build_choices for the administrator: $(head -c 400 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
# N323-2: a page of the default language asks for the choices of the default language (the column '', not the code cs) –
# its news categories carry no "(default language)" note
grep -q '"kategorieNovinek":\[{' "$WORK/response" && ! grep -q '(výchozí jazyk)\|(v\\u00fdchoz\|(default language)' "$WORK/response" \
  && echo "  ok     3.23 review (N323-2): the news categories of a default-language page are not marked as another language" \
  || { echo "  CHYBA  3.23 review (N323-2): build_choices marks the default language: $(grep -o '"kategorieNovinek":[^]]*' "$WORK/response" | head -c 300)"; ERRORS=$((ERRORS+1)); }
expect "3.23 (U10): build_choices of a page that does not exist" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_choices&id=999999")" 404
sq "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('u10-editor', '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")', 'Editor U10', 1, '$(site_time)', '$(site_time)');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 'u10-editor'" > /dev/null
JAR3="$WORK/jar-u10"
TOKEN3=$(curl -s -c "$JAR3" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR3" -c "$JAR3" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN3" -d user=u10-editor --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR3" -o "$WORK/response" "$B/admin.php?module=pages&action=build_choices&id=$U10_PAGE"
grep -q '"ok":true' "$WORK/response" && grep -q '"sluzby":null' "$WORK/response" && grep -q '"osoby":null' "$WORK/response" && grep -q '"kategorieNovinek":null' "$WORK/response" \
  && grep -q '"adresa":"viditelna-u10"' "$WORK/response" && ! grep -q 'skryta-u10\|U10 person\|U10 service' "$WORK/response" \
  && echo "  ok     3.23 (U10): an editor with Pages only – no services, people or news categories, only the categories on the site" \
  || { echo "  CHYBA  3.23 (U10): build_choices for an editor with Pages only: $(head -c 400 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_uzivatele_prava SET ident_modulu = 'news' WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'u10-editor')" > /dev/null
expect "3.23 (U10): without Pages there are no choices" "$(curl -s -b "$JAR3" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_choices&id=$U10_PAGE")" 403
mcp builder_schema '{"elements":["booking"]}' > "$WORK/response"
grep -q 'a service id from booking_availability' "$WORK/response" && echo "  ok     3.23 (U10): builder_schema says where the service id comes from" || { echo "  CHYBA  3.23 (U10): builder_schema booking source"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'u10-editor'); DELETE FROM ka_uzivatele WHERE user = 'u10-editor';
  DELETE FROM ka_booking_services WHERE name = 'U10 service'; DELETE FROM ka_booking_staff WHERE name = 'U10 person';
  DELETE FROM ka_collection_categories WHERE id IN (SELECT category_id FROM ka_collection_category_texts WHERE slug IN ('skryta-u10', 'viditelna-u10'))" > /dev/null
[ "$U10_BOOKINGS" = 1 ] || sq "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',bookings,', ',')) WHERE promenna = 'extensions'" > /dev/null
# 3.24 (agent B): the responsible person of pages and items, Mine, the assignment e-mail (the admin language, never the enquiry
# content, never to oneself, never when unchanged), the review reminder to the responsible person and the draft attribution (N-U6)
