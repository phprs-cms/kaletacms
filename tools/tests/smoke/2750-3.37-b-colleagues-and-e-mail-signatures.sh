echo "== 3.37 B: colleagues, phone and position on the account, the fallback e-mail signature"
# an administrator gives a new account its position and work phone in Users
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=new"
grep -q 'name="pozice"' "$WORK/response" && grep -q 'name="phone"' "$WORK/response" \
  && echo "  ok     3.37: the user form has the position and the work phone" || { echo "  CHYBA  3.37: the user form"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d idu=0 -d jmeno=Karel\ Kolega -d user=kolega-337 \
  --data-urlencode email=kolega337@example.cz --data-urlencode "password=$PASSWORD" -d admin=0 -d team_item_id= --data-urlencode "pozice=Key Account Manager" --data-urlencode "phone=+420 777 111 222"
K337=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'kolega-337'"); K337="${K337:-0}"
expect "3.37: Users saves the position and the work phone" "$(sq "SELECT CONCAT(pozice, '|', phone) FROM ka_uzivatele WHERE idu = $K337")" "Key Account Manager|+420 777 111 222"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$K337"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d idu=$K337 -d jmeno=Karel\ Kolega -d user=kolega-337 \
  --data-urlencode email=kolega337@example.cz -d admin=0 -d team_item_id= --data-urlencode "phone=volejte mi"
grep -q 'Telefonní číslo může obsahovat jen číslice' "$WORK/response" \
  && echo "  ok     3.37: a phone that is not a number is refused with the reason" || { echo "  CHYBA  3.37: the phone error in Users"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=edit&id=$K337"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$(csrf)" -d idu=$K337 -d jmeno=Karel\ Kolega -d user=kolega-337 \
  --data-urlencode email=kolega337@example.cz -d admin=0 -d team_item_id=
expect "3.37: a refused phone saved nothing, a save without the fields keeps them" "$(sq "SELECT CONCAT(pozice, '|', phone) FROM ka_uzivatele WHERE idu = $K337")" "Key Account Manager|+420 777 111 222"
check "3.37: the Users list shows the position" 200 "/admin.php?module=users" "<span class=\"smltxt\">· <bdi>Key Account Manager</bdi></span>"
# a blocked account is no colleague
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, blokovat, pozice) VALUES ('blokovany-337', 'x', 'Bara Blokovana', 'bara337@example.cz', 1, 'Ex')" > /dev/null

# the colleague signs in: no section of Collections, still the colleagues and their own signature
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
JAR_K337="$WORK/jar-337"; rm -f "$JAR_K337"
K337_CSRF=$(curl -s -c "$JAR_K337" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_K337" -c "$JAR_K337" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$K337_CSRF" -d user=kolega-337 --data-urlencode "password=$PASSWORD"
k337_page() { curl -s -b "$JAR_K337" -c "$JAR_K337" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=account$1"; }
expect "3.37: My account has the Colleagues tab for an author" "$(k337_page ''; grep -q 'action=account&amp;tab=colleagues' "$WORK/response" && grep -q 'name="phone" value="+420 777 111 222"' "$WORK/response" && echo ' ok')" "200 ok"
expect "3.37: Colleagues lists the active accounts with position, phone and e-mail – never a blocked one" \
  "$(k337_page '&tab=colleagues'; grep -q 'class="kolegove"' "$WORK/response" && grep -q 'Karel Kolega' "$WORK/response" && grep -q 'Key Account Manager' "$WORK/response" \
     && grep -q 'href="tel:+420777111222"' "$WORK/response" && grep -q 'href="mailto:kolega337@example.cz"' "$WORK/response" && ! grep -q 'Bara Blokovana\|bara337' "$WORK/response" && echo ' ok')" "200 ok"
expect "3.37: without a Team record the signature tab shows the fallback signature from the account" \
  "$(k337_page '&tab=signature'; grep -q 'data-kopirovat-podpis' "$WORK/response" && grep -q 'Karel Kolega' "$WORK/response" && grep -q 'Key Account Manager' "$WORK/response" \
     && grep -q 'href="tel:+420777111222"' "$WORK/response" && grep -q 'mailto:kolega337@example.cz' "$WORK/response" && echo ' ok')" "200 ok"
# the colleague changes their own phone in My account; a phone that is not a number saves nothing
k337_page '' > /dev/null; K337_CSRF=$(csrf)
k337_profile() { curl -s -b "$JAR_K337" -c "$JAR_K337" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$K337_CSRF" -d co=profil -d jmeno=Karel\ Kolega \
  --data-urlencode email=kolega337@example.cz --data-urlencode "pozice=Key Account Manager" --data-urlencode "phone=$1"; }
k337_profile "zavolejte"
expect "3.37: My account refuses a phone that is not a number" "$(sq "SELECT phone FROM ka_uzivatele WHERE idu = $K337")" "+420 777 111 222"
k337_profile "+420 603 000 337"
expect "3.37: My account saves the own work phone" "$(sq "SELECT phone FROM ka_uzivatele WHERE idu = $K337")" "+420 603 000 337"
# the template in Settings: lines with placeholders, a line of empty placeholders left out, text escaped – always current
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('email_signature_template', '{name} | {position}\nMobil: {phone}\n<b>{company}</b>')" > /dev/null
expect "3.37: the fallback follows the template, escapes it and links the phone" \
  "$(k337_page '&tab=signature'; grep -q 'Karel Kolega | Key Account Manager' "$WORK/response" && grep -q 'Mobil: <a href="tel:+420603000337"' "$WORK/response" && grep -q '&lt;b&gt;' "$WORK/response" && echo ' ok')" "200 ok"
K337_PHONE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_phone'")
sq "UPDATE ka_uzivatele SET phone = '' WHERE idu = $K337; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_phone', '')" > /dev/null
expect "3.37: without any phone the Mobil line is left out" "$(k337_page '&tab=signature'; grep -q 'Karel Kolega | Key Account Manager' "$WORK/response" && ! grep -q 'Mobil:' "$WORK/response" && echo ' ok')" "200 ok"
sq "UPDATE ka_uzivatele SET phone = '+420 603 000 337' WHERE idu = $K337; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_phone', '$K337_PHONE')" > /dev/null
# the reply machinery (enquiries, job applications) takes the same signature
expect "3.37: a reply from Kaleta carries the fallback signature of a user without a Team record" \
  "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $u = $app->db()->one("SELECT * FROM {uzivatele} WHERE idu = ?", [(int) $argv[1]]); $s = Kaleta\Core\EnquiryReply::signature($app, (array) $u); echo $s !== null && str_contains($s["text"], "Mobil: +420 603 000 337") && str_contains($s["html"], "tel:+420603000337") ? "ok" : "no";' "$K337")" "ok"
sq "DELETE FROM ka_nastaveni WHERE promenna = 'email_signature_template'" > /dev/null
# MCP: list_colleagues (work set) and get_email_signature without a collection = the caller's own
mcp list_colleagues '{}' > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/k337.json"
grep -q '"name":"Karel Kolega"' "$WORK/k337.json" && grep -q '"phone":"+420 603 000 337"' "$WORK/k337.json" && grep -q '"position":"Key Account Manager"' "$WORK/k337.json" && ! grep -q 'Bara Blokovana' "$WORK/k337.json" && ! grep -q '"photo"\|"user":\|"bio"' "$WORK/k337.json" \
  && echo "  ok     3.37: MCP list_colleagues lists the active colleagues, compact, nothing else of the accounts" || { echo "  CHYBA  3.37: MCP list_colleagues: $(head -c 400 "$WORK/k337.json")"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature '{}' > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/k337.json"
grep -q '"source":"\(team\|fallback\)"' "$WORK/k337.json" && grep -q '"html":"<table' "$WORK/k337.json" && grep -q '"text":"' "$WORK/k337.json" \
  && echo "  ok     3.37: MCP get_email_signature without a collection returns the caller's own signature" || { echo "  CHYBA  3.37: MCP get_email_signature own: $(head -c 400 "$WORK/k337.json")"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_uzivatele WHERE user IN ('kolega-337', 'blokovany-337')" > /dev/null
