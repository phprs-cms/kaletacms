echo "== 3.52 A6, A8: collection items are searched and paged in the admin; lists say Found, mark the words and tell a filter without a match from an empty list"
a806_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; } # reads the whole input (no SIGPIPE under pipefail)
A806_NOW=$(site_time)
A806_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A806_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A806_ADMIN, 'a806 admin', 'full', 'all', SHA2('$A806_TOKEN', 256), '$A806_NOW')" > /dev/null
a806_mcp() { mcp_call "$A806_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }

# A6: a collection of 130 items – one with a word in its fields (stored with the letters themselves), 120 with a shared word
A806_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole) VALUES ('Pozice A806', 'pozice-a806', '[{\"klic\":\"popis\",\"popisek\":\"Popis\",\"typ\":\"text\"}]'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum) VALUES ($A806_IDK, 'Svářeč A806', 'svarec-a806', '{\"popis\":\"Práce v Žďáru nad Sázavou\"}', '$A806_NOW')" > /dev/null
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum) SELECT $A806_IDK, CONCAT('Skladník ', n), CONCAT('skladnik-', n), '{\"popis\":\"Sklad A806\"}', '$A806_NOW'
  FROM (SELECT a.N + b.N * 10 + c.N * 100 AS n FROM (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b, (SELECT 0 AS N UNION SELECT 1) c) t WHERE n < 129" > /dev/null
A806_ITEMS="$B/admin.php?module=collections&action=items&id=$A806_IDK"
A806_FOUND=$(curl -s -b "$JAR" "$A806_ITEMS&search=zdaru+SAZAVOU")
expect "3.52 A6: the items list finds a word of the fields without diacritics and in any order, marks the name of the address match, says Found and offers Clear filter" \
  "$(printf '%s' "$A806_FOUND" | a806_has 'Svářeč A806')|$(printf '%s' "$A806_FOUND" | a806_has 'Skladník 1<')|$(printf '%s' "$A806_FOUND" | grep -o 'data-found>[^<]*' | grep -o '[0-9]*$')|$(curl -s -b "$JAR" "$A806_ITEMS&search=svarec" | a806_has '<mark>Svářeč</mark> A806')" \
  "1|0|1|1"
A806_PAGE1=$(curl -s -b "$JAR" "$A806_ITEMS&search=sklad+a806")
expect "3.52 A6: 129 matches are paged by 100 – page 2 keeps the search, page=999 is the last page with the rest; without a search 130 items also page" \
  "$(printf '%s' "$A806_PAGE1" | grep -c 'name="oznacene\[\]"')|$(printf '%s' "$A806_PAGE1" | a806_has "action=items&amp;id=$A806_IDK&amp;search=sklad+a806&amp;page=2\"")|$(curl -s -b "$JAR" "$A806_ITEMS&search=sklad+a806&page=999" | grep -o 'aria-current="page">\[[0-9]*\]' | grep -o '[0-9]*')|$(curl -s -b "$JAR" "$A806_ITEMS&page=2" | grep -c 'name="oznacene\[\]"')" \
  "100|1|2|30"
expect "3.52 A6: a search without a match – the empty state of a filter with Clear filter, not 'The collection is empty.'; MCP list_collection_items finds by the address too" \
  "$(curl -s -b "$JAR" "$A806_ITEMS&search=nic+takoveho+a806" | a806_has 'prazdny-stav-nadpis">Filtru nic neodpovídá.')|$(curl -s -b "$JAR" "$A806_ITEMS&search=nic+takoveho+a806" | a806_has 'Kolekce je zatím prázdná.')|$(a806_mcp list_collection_items '{"collection":"pozice-a806","search":"svarec-a806"}' | grep -o '"total":[0-9]*')" \
  "1|0|\"total\":1"

# A8: a search without a match in Media, Redirects and Subscribers is a filter without a match, not an empty library
sq "INSERT INTO ka_presmerovani (z_adresy, na_adresu, typ, vytvoreno) VALUES ('stara-dvorakova-a806', 'kontakt', 301, '$A806_NOW')" > /dev/null
sq "INSERT INTO ka_odberatele (email, stav, token, datum) VALUES ('odber-a806@example.com', 1, '$(openssl rand -hex 16)', '$A806_NOW')" > /dev/null
expect "3.52 A8: Media, Redirects and Subscribers without a match say 'Nothing matches the filter.' with Clear filter – never the first-use advice" \
  "$(curl -s -b "$JAR" "$B/admin.php?module=media&search=nic+takoveho+a806" | a806_has 'Filtru nic neodpovídá.')|$(curl -s -b "$JAR" "$B/admin.php?module=media&search=nic+takoveho+a806" | a806_has 'Nahrajte první fotky')|$(curl -s -b "$JAR" "$B/admin.php?module=redirects&search=nic+takoveho+a806" | a806_has 'Filtru nic neodpovídá.')|$(curl -s -b "$JAR" "$B/admin.php?module=redirects&search=nic+takoveho+a806" | a806_has 'Zatím žádné přesměrování.')|$(curl -s -b "$JAR" "$B/admin.php?module=subscribers&search=nic+takoveho+a806" | a806_has 'Filtru nic neodpovídá.')|$(curl -s -b "$JAR" "$B/admin.php?module=subscribers&search=nic+takoveho+a806" | a806_has 'Zatím žádní odběratelé.')" \
  "1|0|1|0|1|0"
expect "3.52 A8: a found redirect and subscriber – Found: 1 and the searched word marked (diacritics in the search do not matter)" \
  "$(curl -s -G -b "$JAR" "$B/admin.php" -d module=redirects --data-urlencode 'search=dvořákova a806' | grep -o 'data-found>[^<]*' | grep -o '[0-9]*$')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=redirects --data-urlencode 'search=dvořákova a806' | a806_has 'stara-<mark>dvorakova</mark>-<mark>a806</mark>')|$(curl -s -b "$JAR" "$B/admin.php?module=subscribers&search=odber-a806" | a806_has '<mark>odber-a806</mark>@example.com')" \
  "1|1|1"

# A8: an enquiry whose match is far into the form text – the list shows the place around it, marked
A806_LONG=$(php -r 'echo str_repeat("Dobrý den, ", 30);')
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$A806_NOW', 'Kontakt A806', 'snippet-a806@example.com', '[[\"Zpráva\",\"$A806_LONG poptávám jeřáb A806\"]]', 0)" > /dev/null
A806_ENQ=$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&search=jerab+a806")
expect "3.52 A8: the enquiry list shows a snippet around the searched word (an ellipsis before it), marked, and Found: 1" \
  "$(printf '%s' "$A806_ENQ" | a806_has '<mark>jeřáb</mark> <mark>A806</mark>')|$(printf '%s' "$A806_ENQ" | a806_has '<bdi>…')|$(printf '%s' "$A806_ENQ" | grep -o 'data-found>[^<]*' | grep -o '[0-9]*$')" \
  "1|1|1"

sq "DELETE FROM ka_kolekce_polozky WHERE idk = $A806_IDK; DELETE FROM ka_kolekce WHERE idk = $A806_IDK; DELETE FROM ka_presmerovani WHERE z_adresy = 'stara-dvorakova-a806';
  DELETE FROM ka_odberatele WHERE email = 'odber-a806@example.com'; DELETE FROM ka_poptavky WHERE formular = 'Kontakt A806'; DELETE FROM ka_api_tokeny WHERE nazev = 'a806 admin'" > /dev/null
