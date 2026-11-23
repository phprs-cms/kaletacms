echo "== 3.52 A1–A3, A7, A9: lists and MCP find words in any order without diacritics; enquiries keep the search on page 2; an author's palette sees no other drafts"
a800_login() { local jar="$WORK/jar-$1"; rm -f "$jar"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; echo "$jar"; }
a800_mcp() { mcp_call "$A800_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
a800_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; } # reads the whole input (no SIGPIPE under pipefail)
a800_count() { php -r '$d = json_decode(stream_get_contents(STDIN), true); echo count(array_filter(is_array($d) ? ($d["items"] ?? $d) : [], fn ($r) => is_array($r) && str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), $argv[1])));' -- "$1"; }
A800_NOW=$(site_time)
A800_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A800_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('a800-author', '$A800_HASH', 'Author A800', 'author-a800@example.com', 'en', 0, '$A800_NOW', '$A800_NOW', 0, 'off')" > /dev/null
A800_AUTHOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a800-author'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($A800_AUTHOR, 'news')" > /dev/null
A800_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A800_ADMIN, 'a800 admin', 'full', 'all', SHA2('$A800_TOKEN', 256), '$A800_NOW')" > /dev/null

# A7: a news item found by a word of its text (the index column), A3: the administrator's draft is not the author's to find
A800_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, autor, hledani) VALUES
  ('koncept-a800', 'Koncept Dvořák A800', '', '<p>Železobeton a lešení</p>', $A800_TEMA, '$A800_NOW', 0, $A800_ADMIN, 'koncept dvorak a800 zelezobeton a leseni')" > /dev/null
A800_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE seo_link = 'koncept-a800'")
A800_AJAR=$(a800_login a800-author)
expect "3.52 A3: search_json answers the author without the administrator's draft – with edit=1 and without it; the administrator gets it with the link to its editor" \
  "$(curl -s -b "$A800_AJAR" "$B/admin.php?module=news&action=search_json&q=dvorak+a800" | a800_has 'A800')|$(curl -s -b "$A800_AJAR" "$B/admin.php?module=news&action=search_json&edit=1&q=dvorak+a800" | a800_has 'A800')|$(curl -s -b "$JAR" "$B/admin.php?module=news&action=search_json&q=a800+DVORAK" | a800_has "action=edit&id=$A800_NEWS\"")" \
  "0|0|1"
expect "3.52 A7: the news list finds a word of the text without diacritics (zelezobeton), and the title words in another order; list_news the same" \
  "$(curl -s -b "$JAR" "$B/admin.php?module=news&search=zelezobeton" | a800_has 'Koncept Dvořák A800')|$(curl -s -b "$JAR" "$B/admin.php?module=news&search=a800+koncept" | a800_has 'Koncept Dvořák A800')|$(a800_mcp list_news '{"search":"lešení A800"}' | a800_count 'Koncept Dvořák A800')" \
  "1|1|1"

# A2: an enquiry by a name in the form data, stored with the letters themselves (Front\Forms) – and the old \uXXXX rows
sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav) VALUES
  ('$A800_NOW', 'Kontakt A800', '/kontakt', 'Sklad A800', 'rehor@example.com', '[[\"Jméno\",\"Řehoř Dvořák\"],[\"Zpráva\",\"Ahoj A800\"]]', 0),
  ('$A800_NOW', 'Kontakt A800', '/kontakt', '', 'old@example.com', '[[\"Jm\\\\u00e9no\",\"Old \\\\u0160koda A800\"]]', 0)" > /dev/null
A800_LIST=$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&search=dvorak+rehor")
expect "3.52 A2: enquiries find a name of the form data without diacritics in any order, an old \\\\u row by the word with diacritics, and the topic of the form" \
  "$(printf '%s' "$A800_LIST" | a800_has 'rehor@example.com')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=enquiries --data-urlencode 'search=Škoda A800' | a800_has 'old@example.com')|$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&search=sklad+a800" | a800_has 'rehor@example.com')|$(a800_mcp list_enquiries '{"search":"DVORAK řehoř"}' | a800_count 'rehor@example.com')" \
  "1|1|1|1"
# 51 more of the same word: page 2 keeps the search and the kind, a page past the end is the last one
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, kategorie) SELECT '$A800_NOW', 'Strana A800', CONCAT('p', n, '@example.com'), '[[\"Zpráva\",\"Stránkování A800\"]]', 0, 'sales'
  FROM (SELECT a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) b) t WHERE n < 51" > /dev/null
A800_PAGED=$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&category=sales&search=strankovani+a800")
expect "3.52 A2: page 2 of a search keeps search and category, the form keeps the kind, page=999 shows the last page" \
  "$(printf '%s' "$A800_PAGED" | a800_has 'category=sales&amp;search=strankovani+a800&amp;page=2')|$(printf '%s' "$A800_PAGED" | a800_has 'name="category" value="sales"')|$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&category=sales&search=strankovani+a800&page=999" | a800_has '<strong>[2]</strong>')" \
  "1|1|1"

# A9: a customer by the digits of the phone, pages by the SEO title through MCP list_pages
sq "INSERT INTO ka_contacts (name, email, phone, created_at, updated_at) VALUES ('Telefon A800', 'tel-a800@example.com', '+420 777 123 456', '$A800_NOW', '$A800_NOW')" > /dev/null
sq "INSERT INTO ka_stranky (seo_link, titulek, seo_titulek, text, zobrazit) VALUES ('stranka-a800', 'Stránka A800', 'Žluťoučký kůň A800', '', 1)" > /dev/null
expect "3.52 A9: a customer by 777-123 (digits only), list_pages by a word of the SEO title without diacritics, and list_pages without search lists it too" \
  "$(curl -s -b "$JAR" "$B/admin.php?module=contacts&search=777-123" | a800_has 'Telefon A800')|$(a800_mcp list_pages '{"search":"zlutoucky a800"}' | a800_count 'Stránka A800')|$(a800_mcp list_pages '{}' | a800_count 'Stránka A800')|$(a800_mcp list_pages '{"search":"a800 nic-takoveho"}' | a800_count 'Stránka A800')" \
  "1|1|1|0"

sq "DELETE FROM ka_novinky WHERE idc = $A800_NEWS; DELETE FROM ka_poptavky WHERE formular IN ('Kontakt A800', 'Strana A800'); DELETE FROM ka_contacts WHERE email = 'tel-a800@example.com';
  DELETE FROM ka_stranky WHERE seo_link = 'stranka-a800'; DELETE FROM ka_api_tokeny WHERE nazev = 'a800 admin';
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $A800_AUTHOR; DELETE FROM ka_uzivatele WHERE idu = $A800_AUTHOR" > /dev/null
