echo "== 3.52 A4, A5: the command palette finds every kind of record on the server in one answer – only what the person may open; MCP search the same"
a810_login() { local jar="$WORK/jar-$1"; rm -f "$jar"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; echo "$jar"; }
# the kinds of a palette answer, in their order, and every title found (type:title)
a810_types() { curl -s -G -b "$1" "$B/admin.php" -d action=palette_search --data-urlencode "q=$2" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? implode(",", array_column($d["groups"] ?? [], "type")) : "no-json";'; }
a810_titles() { curl -s -G -b "$1" "$B/admin.php" -d action=palette_search --data-urlencode "q=$2" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $o = []; foreach ($d["groups"] ?? [] as $g) { foreach ($g["items"] as $i) { $o[] = $g["type"] . ":" . $i["title"]; } } echo implode("|", $o);'; }
a810_mcp() { mcp_call "$A810_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
A810_NOW=$(site_time)
A810_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A810_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('a810-author', '$A810_HASH', 'Author A810', 'author-a810@example.com', 'en', 0, '$A810_NOW', '$A810_NOW', 0, 'off'),
  ('a810-desk', '$A810_HASH', 'Desk A810', 'desk-a810@example.com', 'en', 1, '$A810_NOW', '$A810_NOW', 0, 'off')" > /dev/null
A810_AUTHOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a810-author'")
A810_DESK=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a810-desk'")
# the author (role 0) has News only; the desk (an editor) has Enquiries only – no Pages, no Customers, no Media list of its own besides the shared one
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($A810_AUTHOR, 'news'), ($A810_DESK, 'enquiries')" > /dev/null
A810_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A810_ADMIN, 'a810 admin', 'full', 'all', SHA2('$A810_TOKEN', 256), '$A810_NOW')" > /dev/null

# one word "Šrámek A810" in every kind of record: a page, the administrator's draft, the author's own draft, a collection item, a file, an enquiry, a customer
A810_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('sramek-a810', 'Šrámek A810', '', 0);
  INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, autor, hledani) VALUES
  ('admin-sramek-a810', 'Koncept správce Šrámek A810', '', '', $A810_TEMA, '$A810_NOW', 0, $A810_ADMIN, 'koncept spravce sramek a810'),
  ('autor-sramek-a810', 'Můj koncept Šrámek A810', '', '', $A810_TEMA, '$A810_NOW', 0, $A810_AUTHOR, 'muj koncept sramek a810');
  INSERT INTO ka_media (obr_poloha, nazev, datum) VALUES ('media/a810/sramek-a810.pdf', 'Ceník Šrámek A810', '$A810_NOW');
  INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$A810_NOW', 'Kontakt A810', 'sramek-a810@example.com', '[[\"Jméno\",\"Jiří Šrámek A810\"]]', 0);
  INSERT INTO ka_contacts (name, email, created_at, updated_at) VALUES ('Jiří Šrámek A810', 'jiri-a810@example.com', '$A810_NOW', '$A810_NOW')" > /dev/null
A810_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole) VALUES ('Lidé A810', 'lide-a810', '[]'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($A810_IDK, 'Šrámek A810 – vedoucí', 'sramek-a810-vedouci', '{}', '$A810_NOW', 1)" > /dev/null

expect "3.52 A4: the administrator's palette finds the page, both drafts, the item, the file, the enquiry and the customer – without diacritics and in any word order" \
  "$(a810_types "$JAR" 'a810 sramek' | tr ',' '\n' | sort | tr '\n' ',')" \
  "collection_item,contact,enquiry,media,news,page,"
A810_HEAD=$(curl -s -D - -o /dev/null -b "$JAR" "$B/admin.php?action=palette_search&q=sramek+a810")
expect "3.52 A5: the exact name first (the page Šrámek A810 opens the answer); the answer is never stored; under two characters nothing" \
  "$(a810_titles "$JAR" 'Sramek A810' | cut -d'|' -f1)|$(printf '%s' "$A810_HEAD" | grep -ci '^cache-control: no-store')|$(a810_types "$JAR" 's')" \
  "page:Šrámek A810|1|"

# A4 (risk D): each kind only for whoever may open its section, with the conditions of its list
A810_AJAR=$(a810_login a810-author)
A810_DJAR=$(a810_login a810-desk)
expect "3.52 A4: the author (role 0, News only) gets their own draft and the shared Media, never the administrator's draft, a page, an enquiry or a customer" \
  "$(a810_titles "$A810_AJAR" 'sramek a810' | tr '|' '\n' | grep -v '^media:' | tr '\n' '|')|$(a810_types "$A810_AJAR" 'sramek a810' | tr ',' '\n' | sort | tr '\n' ',')" \
  "news:Můj koncept Šrámek A810||media,news,"
expect "3.52 A4: a user without Customers, Pages, News and Collections gets the enquiry (their section) and nothing of the others" \
  "$(a810_types "$A810_DJAR" 'sramek a810' | tr ',' '\n' | sort | tr '\n' ',')" \
  "enquiry,media,"
# only the palette's own JSON (one line, its </script> on the same line) – the dashboard below may name the page
expect "3.52 A4: the dashboard no longer carries the pages for the palette (they are searched on the server)" \
  "$(curl -s -b "$JAR" "$B/admin.php" | php -r 'preg_match("~id=\"paleta-data\">(.*?)</script>~s", (string) stream_get_contents(STDIN), $m); echo isset($m[1]) ? substr_count($m[1], "Šrámek A810") : "no palette";')" "0"

expect "3.52 A4: MCP search finds the same with admin addresses and totals; the Czech alias hledej answers too; a one-letter query is refused" \
  "$(a810_mcp search '{"query":"a810 sramek"}' | php -r '$d = json_decode(stream_get_contents(STDIN), true); $t = array_column($d["results"] ?? [], "type"); sort($t); echo implode(",", array_unique($t)), "|", ($d["totals"]["news"] ?? 0), "|", (int) str_contains(json_encode($d["results"] ?? []), "admin.php?module=pages&action=edit"), "|", implode(",", $d["untrusted"] ?? []);')|$(a810_mcp hledej '{"dotaz":"sramek a810"}' | grep -c 'Šrámek A810' | awk '{print ($1 > 0)}')|$(a810_mcp search '{"query":"s"}' | grep -c 'two characters')" \
  "collection_item,contact,enquiry,media,news,page|2|1|results|1|1"

sq "DELETE FROM ka_stranky WHERE seo_link = 'sramek-a810'; DELETE FROM ka_novinky WHERE seo_link IN ('admin-sramek-a810', 'autor-sramek-a810');
  DELETE FROM ka_media WHERE obr_poloha = 'media/a810/sramek-a810.pdf'; DELETE FROM ka_poptavky WHERE formular = 'Kontakt A810'; DELETE FROM ka_contacts WHERE email = 'jiri-a810@example.com';
  DELETE FROM ka_kolekce_polozky WHERE idk = $A810_IDK; DELETE FROM ka_kolekce WHERE idk = $A810_IDK; DELETE FROM ka_api_tokeny WHERE nazev = 'a810 admin';
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($A810_AUTHOR, $A810_DESK); DELETE FROM ka_uzivatele WHERE idu IN ($A810_AUTHOR, $A810_DESK)" > /dev/null
