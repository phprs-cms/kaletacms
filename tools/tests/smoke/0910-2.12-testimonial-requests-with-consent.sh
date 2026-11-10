echo "== 2.12: testimonial requests with consent"
REF_ENQUIRY=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', 'zakaznik@example.cz', '[[\"Zpráva\",\"Děkujeme\"]]', 2); SELECT LAST_INSERT_ID();")
NO_MAIL_ENQUIRY=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', '/kontakt', '', '[]', 2); SELECT LAST_INSERT_ID();")
mcp request_testimonial "{\"id\":$NO_MAIL_ENQUIRY}" > "$WORK/response"
contains -q 'no e-mail address' "$WORK/response" && echo "  ok     testimonials: an enquiry without an e-mail cannot be asked" || { echo "  CHYBA  žádost bez e-mailu"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp request_testimonial "{\"id\":$REF_ENQUIRY,\"send\":false}" > "$WORK/response"
REF_LINK=$(php -r '$t = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "null", true); echo ltrim((string) parse_url((string) ($t["link"] ?? ""), PHP_URL_PATH), "/");' "$WORK/response")
[ -n "$REF_LINK" ] && expect "testimonials: only a hash of the token is stored" "$(sq "SELECT COUNT(*) FROM ka_testimonial_requests WHERE idp = $REF_ENQUIRY AND token_hash = SHA2('${REF_LINK#_testimonial/}', 256)")" "1" || { echo "  CHYBA  request_testimonial"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/formular.html" "$B/$REF_LINK"
grep -q 'name="consent_words"' "$WORK/formular.html" && grep -q 'name="consent_photo"' "$WORK/formular.html" && grep -q 'noindex' "$WORK/formular.html" && echo "  ok     testimonials: the customer's page asks for the words and two separate consents" || { echo "  CHYBA  stránka pro referenci"; ERRORS=$((ERRORS+1)); }
REF_TIME=$(field_value as_cas || true); REF_SIGNATURE=$(field_value as_podpis || true); sleep 4
curl -s -o "$WORK/response" -X POST "$B/$REF_LINK" -d "as_cas=$REF_TIME" -d "as_podpis=$REF_SIGNATURE" --data-urlencode "text=Výborná spolupráce, vše včas." -d "name=Eva Nováková"
grep -q 'only with your consent\|jen s vaším souhlasem' "$WORK/response" && echo "  ok     testimonials: nothing is saved without the consent" || { echo "  CHYBA  souhlas"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" -X POST "$B/$REF_LINK" -d "as_cas=$REF_TIME" -d "as_podpis=$REF_SIGNATURE" --data-urlencode "text=Výborná spolupráce, vše <b>včas</b>." -d "name=Eva Nováková" --data-urlencode "role=ředitelka, ACME" -d consent_words=1
REF_ITEM=$(sq "SELECT item_id FROM ka_testimonial_requests WHERE idp = $REF_ENQUIRY AND used_at IS NOT NULL")
[ -n "$REF_ITEM" ] && expect "testimonials: the answer is a hidden draft reference with the words, the name and the role" "$(sq "SELECT CONCAT(p.zobrazit, '|', p.nazev, '|', p.data->>'\$.quote', '|', p.data->>'\$.client', '|', k.preset) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE p.idp = $REF_ITEM")" "0|Eva Nováková|Výborná spolupráce, vše včas.|Eva Nováková, ředitelka, ACME|references" \
  || { echo "  CHYBA  koncept reference"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "testimonials: the consent the customer saw is kept, the link works once" "$(sq "SELECT consent LIKE '%publish my words%' OR consent LIKE '%zveřejn%' FROM ka_testimonial_requests WHERE item_id = $REF_ITEM")|$(curl -s -o /dev/null -w '%{http_code}' "$B/$REF_LINK")" "1|404"
check "testimonials: the enquiry detail shows the request and links the draft" 200 "/admin.php?module=enquiries&action=detail&id=$REF_ENQUIRY" "item=$REF_ITEM"
# 3.23.1 (owner): the request is a folded step after the main actions – open while a request exists; not on a new enquiry, spam,
# a job application or a supplier; offered once the enquiry is resolved
grep -q '<details class="reference-zadost" data-testimonial open>' "$WORK/response" && grep -q 'Požádat zákazníka o referenci' "$WORK/response" \
  && [ "$(grep -o 'Smazat</button>\|data-testimonial' "$WORK/response" | tr '\n' ' ')" = "Smazat</button> data-testimonial " ] \
  && echo "  ok     3.23.1: with a request the testimonial block is open, after Mark as resolved / Anonymise / Delete" || { echo "  CHYBA  3.23.1: the testimonial block with a request"; ERRORS=$((ERRORS+1)); }
TM_NEW=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'novy@example.cz', '[]', 0); SELECT LAST_INSERT_ID();")
TM_SPAM=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, kategorie) VALUES ('$(site_time)', 'Kontakt', 'spam@example.cz', '[]', 2, 'spam'); SELECT LAST_INSERT_ID();")
TM_JOB=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, kategorie) VALUES ('$(site_time)', 'Kontakt', 'prace@example.cz', '[]', 2, 'job'); SELECT LAST_INSERT_ID();")
tm_block() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$1"; grep -c 'data-testimonial' "$WORK/response" || true; }
TM_BEFORE="$(tm_block "$TM_NEW")|$(tm_block "$TM_SPAM")|$(tm_block "$TM_JOB")"
tm_block "$TM_NEW" > /dev/null; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=status" -d "_csrf=$(csrf)" -d "idp=$TM_NEW" -d stav=2
expect "3.23.1: no testimonial block on a new enquiry, on spam or a job application; a resolved one offers it folded" \
  "$TM_BEFORE|$(tm_block "$TM_NEW")|$(grep -c '<details class="reference-zadost" data-testimonial>' "$WORK/response" || true)" "0|0|0|1|1"
sq "DELETE FROM ka_poptavky WHERE idp IN ($TM_NEW, $TM_SPAM, $TM_JOB)" > /dev/null
