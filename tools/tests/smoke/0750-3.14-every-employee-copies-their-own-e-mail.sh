echo "== 3.14: every employee copies their own e-mail signature (My account)"
mcp save_collection_item "{\"collection\":\"$TEAM\",\"name\":\"Eva Skrytá\",\"slug\":\"eva-skryta\",\"values\":{\"$(team_key 1)\":\"Účetní\",\"$(team_key 4)\":\"eva@example.cz\"},\"visible\":false}" > /dev/null
EVA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $TEAM_IDK AND seo_link = 'eva-skryta'"); EVA="${EVA:-0}"
OTHER_ITEM=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'") # a record of a collection that holds no people
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=new"
grep -q 'name="team_item_id"' "$WORK/response" && grep -q "<option value=\"$EVA\">Eva Skrytá – eva@example.cz – " "$WORK/response" && ! grep -q "<option value=\"$OTHER_ITEM\"" "$WORK/response" \
  && echo "  ok     signature: the user form offers the people records (hidden too) and nothing else" || { echo "  CHYBA  signature: the Team record select"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d idu=0 -d jmeno=Petr -d user=podpis-autor --data-urlencode email=PETR@Example.cz --data-urlencode "password=$PASSWORD" -d admin=0 -d team_item_id=
SIG_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'podpis-autor'"); SIG_USER="${SIG_USER:-0}"
expect "signature: an author without Collections, linked automatically (no stored choice)" "$(sq "SELECT CONCAT(IFNULL(team_item_id, 'auto'), '|', (SELECT COUNT(*) FROM ka_uzivatele_prava WHERE fk_id_user = idu AND ident_modulu = 'collections')) FROM ka_uzivatele WHERE idu = $SIG_USER")" "auto|0"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
JAR_SIG="$WORK/jar-signature"; rm -f "$JAR_SIG"
SIG_CSRF=$(curl -s -c "$JAR_SIG" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_SIG" -c "$JAR_SIG" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$SIG_CSRF" -d user=podpis-autor --data-urlencode "password=$PASSWORD"
sig_page() { curl -s -b "$JAR_SIG" -c "$JAR_SIG" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=account$1"; }
expect "signature: My account has the My e-mail signature tab" "$(sig_page ''; grep -q 'action=account&amp;tab=signature' "$WORK/response" && echo ' ok')" "200 ok"
expect "signature: linked by the e-mail (case-insensitive) – the author sees their own signature with the Copy button" \
  "$(sig_page '&tab=signature'; grep -q 'data-kopirovat-podpis' "$WORK/response" && grep -q 'Petr Podpis' "$WORK/response" && grep -q 'href="tel:+420777123456"' "$WORK/response" && ! grep -q 'Eva Skrytá' "$WORK/response" && echo ' ok')" "200 ok"
expect "signature: parameters never select another person's record" \
  "$(sig_page "&tab=signature&id=$TEAM_IDK&item=$EVA&team_item_id=$EVA&user=$EVA"; grep -q 'Petr Podpis' "$WORK/response" && ! grep -q 'Eva Skrytá\|eva@example.cz' "$WORK/response" && echo ' ok')" "200 ok"
expect "signature: the author cannot open the Collections signature screen of someone else" "$(curl -s -b "$JAR_SIG" -o /dev/null -w '%{http_code}' "$B/admin.php?module=collections&action=signature&id=$TEAM_IDK&item=$EVA")" "403"
curl -s -b "$JAR_SIG" -c "$JAR_SIG" -o /dev/null -X POST "$B/admin.php?action=account&tab=signature" -d "_csrf=$SIG_CSRF" -d co=profil -d team_item_id=$EVA -d jmeno=Petr --data-urlencode email=PETR@Example.cz
expect "signature: the user cannot link themselves to a record (only an administrator)" "$(sq "SELECT IFNULL(team_item_id, 'auto') FROM ka_uzivatele WHERE idu = $SIG_USER")" "auto"
check "signature: the Users list shows who is linked and how" 200 "/admin.php?module=users" "Záznam v týmu: Petr Podpis (podle e-mailu)"
check "signature: the Team items list says staff copy their own signature" 200 "/admin.php?module=collections&action=items&id=$TEAM_IDK" "zkopírují sami v sekci Můj účet"
# an administrator's choice wins over the e-mail; a record of a collection without people is refused
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$SIG_USER"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d idu=$SIG_USER -d jmeno=Petr -d user=podpis-autor --data-urlencode email=PETR@Example.cz -d admin=0 -d team_item_id=$OTHER_ITEM
expect "signature: an administrator cannot link a record of a collection without people" "$(sq "SELECT IFNULL(team_item_id, 'auto') FROM ka_uzivatele WHERE idu = $SIG_USER")" "auto"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d idu=$SIG_USER -d jmeno=Petr -d user=podpis-autor --data-urlencode email=PETR@Example.cz -d admin=0 -d team_item_id=$EVA
expect "signature: an administrator links the account to a chosen record" "$(sq "SELECT team_item_id FROM ka_uzivatele WHERE idu = $SIG_USER")" "$EVA"
expect "signature: linked by the administrator's choice – the chosen (hidden) record, not the e-mail match" \
  "$(sig_page '&tab=signature'; grep -q 'Eva Skrytá' "$WORK/response" && grep -q 'Účetní' "$WORK/response" && ! grep -q 'Petr Podpis' "$WORK/response" && echo ' ok')" "200 ok"
check "signature: the Users list marks the chosen record" 200 "/admin.php?module=users" "Záznam v týmu: Eva Skrytá (vybraný)"
sq "UPDATE ka_kolekce_polozky SET smazano = '$(site_time)' WHERE idp = $EVA" > /dev/null
expect "signature: a chosen record in the trash falls back to the e-mail match" "$(sig_page '&tab=signature'; grep -q 'Petr Podpis' "$WORK/response" && ! grep -q 'Eva Skrytá' "$WORK/response" && echo ' ok')" "200 ok"
sq "UPDATE ka_kolekce_polozky SET smazano = NULL WHERE idp = $EVA; UPDATE ka_uzivatele SET team_item_id = NULL, email = 'nikdo@example.cz' WHERE idu = $SIG_USER" > /dev/null
expect "signature: not linked – a hint to ask an administrator, no signature" \
  "$(sig_page '&tab=signature'; grep -q 'Požádejte správce, aby vás propojil' "$WORK/response" && ! grep -q 'data-kopirovat-podpis\|Petr Podpis\|Eva Skrytá' "$WORK/response" && echo ' ok')" "200 ok"
check "signature: the Users list says who is not linked" 200 "/admin.php?module=users" "Záznam v týmu: nepropojeno"
# N314-1: an account e-mail is changed without confirming it, so a hidden record is never matched by e-mail – only chosen
sq "UPDATE ka_uzivatele SET email = 'EVA@example.cz' WHERE idu = $SIG_USER" > /dev/null
expect "signature: a hidden record is never linked by the e-mail, only by an administrator's choice (N314-1)" \
  "$(sig_page '&tab=signature'; grep -q 'Požádejte správce, aby vás propojil' "$WORK/response" && ! grep -q 'Eva Skrytá\|eva@example.cz' "$WORK/response" && echo ' ok')" "200 ok"
sq "DELETE FROM ka_uzivatele WHERE idu = $SIG_USER" > /dev/null

