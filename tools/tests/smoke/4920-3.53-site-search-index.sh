echo "== 3.53 A5: the site search reads the index – past every 3.52 cap, only visible words, any script, an exact total; 3.54: rows not indexed yet are filled by the search page"
a920_get() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1" ${2:+-d "page=$2"}; }
a920_hrefs() { a920_get "$1" "${2:-}" | sed -n '/<ul class="vypis-stranky">/,/<\/ul>/p' | grep -o '<a href="[^"]*"' | sed 's/<a href="//; s/"$//' | tr '\n' '|'; }
A920_NOW=$(site_time)
A920_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A920_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
A920_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A920_ADMIN, 'a920 admin', 'full', 'all', SHA2('$A920_TOKEN', 256), '$A920_NOW')" > /dev/null
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# 700 pages (3.52 read at most 500, by their order), every one with "zahrada" once and a link to /mediaq920/…; the page at
# order 650 has "zahrada" 24 times; the page with "mediaq920" as a visible word, an Arabic, a Polish page
A920_PAGES=$(for i in $(seq 1 700); do printf "('strana-a920-%s', 'Strana %s A920', '<p>Obsah zahrada <a href=\"/mediaq920/%s\">odkaz</a></p>', 1, 0, %s)," "$i" "$i" "$i" "$i"; done)
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, poradi) VALUES ${A920_PAGES%,}" > /dev/null
sq "UPDATE ka_stranky SET text = '<p>$(printf 'zahrada %.0s' $(seq 1 24))</p>' WHERE seo_link = 'strana-a920-650';
  UPDATE ka_stranky SET text = '<p>Naše mediaq920 sada</p>' WHERE seo_link = 'strana-a920-660';
  UPDATE ka_stranky SET titulek = 'مدرسة النور A920', text = '<p>نص</p>' WHERE seo_link = 'strana-a920-690';
  UPDATE ka_stranky SET titulek = 'Książka usług A920', text = '<p>Tekst</p>' WHERE seo_link = 'strana-a920-695'" > /dev/null
# 400 items of a collection whose image field holds a /mediaq920/ path and whose text field key is klicq920; one item has
# "mediaq920" as a visible word
A920_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail) VALUES ('Katalog A920', 'katalog-a920', '[{\"klic\":\"klicq920\",\"typ\":\"text\"},{\"klic\":\"foto\",\"typ\":\"obrazek\"}]', 1); SELECT LAST_INSERT_ID();")
A920_ITEMS=$(for i in $(seq 1 400); do printf "(%s, 'Položka %s A920', 'polozka-a920-%s', '{\"klicq920\":\"Popis výrobku\",\"foto\":\"/mediaq920/%s.jpg\"}', '%s', 1, %s)," "$A920_IDK" "$i" "$i" "$i" "$A920_NOW" "$i"; done)
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit, poradi) VALUES ${A920_ITEMS%,}" > /dev/null
sq "UPDATE ka_kolekce_polozky SET data = '{\"klicq920\":\"Náš mediaq920 kit\",\"foto\":\"\"}' WHERE idk = $A920_IDK AND seo_link = 'polozka-a920-7'" > /dev/null
# 400 news; one has a Cyrillic word only in its text
A920_NEWS=$(for i in $(seq 1 400); do printf "('zprava-a920-%s', 'Zpráva %s A920', '', '<p>Text</p>', %s, '%s', 1, 0)," "$i" "$i" "$A920_TEMA" "$(site_time "-$i hours")"; done)
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex) VALUES ${A920_NEWS%,}" > /dev/null
sq "UPDATE ka_novinky SET text = '<p>Последние новостей компании</p>' WHERE seo_link = 'zprava-a920-300'" > /dev/null
expect "3.53 A5: the job fills the index of the 1500 new rows (pages, items, news)" "$(fill_search_index && echo filled)" "filled"

expect "3.53 A5: the page past the first 500 with 24 occurrences is the first result of \"zahrada a920\"" \
  "$(a920_hrefs 'zahrada a920' | cut -d'|' -f1)" "/strana-a920-650"
expect "3.53 A5: \"mediaq920\" finds exactly the page and the item that show the word – not the 700 links or the 400 image paths; a JSON key finds nothing" \
  "$(a920_hrefs 'mediaq920')|$(a920_get 'klicq920' | grep -c 'Nalezeno: 0')" "/strana-a920-660|/katalog-a920/polozka-a920-7||1"
expect "3.53 A5: an Arabic query (taa marbuta as ha) finds the page at order 690; \"ksiazka\" the Polish page; a Cyrillic word only in a news text finds it" \
  "$(a920_hrefs 'مدرسه a920')$(a920_hrefs 'ksiazka a920')$(a920_hrefs 'новостей a920')" "/strana-a920-690|/strana-a920-695|/novinky/zprava-a920-300|"
A920_PARTIAL=$(a920_get 'ksiazka zzqqa920')
expect "3.53 A5: two words without a full match – the partial result says so" \
  "$(printf '%s' "$A920_PARTIAL" | grep -c 'Žádný výsledek neobsahuje všechna slova')|$(printf '%s' "$A920_PARTIAL" | grep -c 'href="/strana-a920-695"')" "1|1"
A920_ALL=$(a920_get 'a920')
expect "3.53 A5: \"Found\" is the exact count of 1500 rows; the ranked set is the best 300 of each kind and the page says so" \
  "$(printf '%s' "$A920_ALL" | grep -c 'Nalezeno: 1500')|$(printf '%s' "$A920_ALL" | grep -c 'Zobrazeno nejlepších 900 z 1500.')" "1|1"
expect "3.53 A5: MCP search_site gives the same exact total" \
  "$(mcp_call "$A920_TOKEN" search_site '{"query":"a920","limit":3}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true); echo count($d["results"] ?? []), "|", $d["total"] ?? "-";')" "3|1500"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# one Chinese character is a whole word: the site, MCP and the admin search take it (Search::longEnough), one letter not
sq "UPDATE ka_stranky SET titulek = '茶室 A920', text = '<p>绿茶</p>', hledani = NULL WHERE seo_link = 'strana-a920-680'" > /dev/null
fill_search_index > /dev/null
expect "3.53 A5: one Chinese character searches on the site and over search_site and search; one Latin letter does not" \
  "$(a920_hrefs '茶')|$(mcp_call "$A920_TOKEN" search_site '{"query":"茶"}' | grep -c 'strana-a920-680')|$(mcp_call "$A920_TOKEN" search '{"query":"茶"}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true); echo (int) in_array("茶室 A920", array_column($d["results"] ?? [], "title"), true);')|$(a920_get 'x' | grep -c 'Zadejte alespoň 2 znaky.')" \
  "/strana-a920-680||1|1|1"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# while the news are re-indexed for a new Search::VERSION, a news item above the cursor has its old (version 1) index – 3.54
# (D1): no title alternative any more; MCP (which never fills) misses its Cyrillic title word and says indexing, the search
# page fills a slice first (SearchIndex::heal – reindexNews with the time complete() left) and then finds it
A920_STATE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'), '')")
A920_OLD=$(sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES ('stara-a920', 'Летопись A920', '', '', $A920_TEMA, '$A920_NOW', 1, 0, 'a920'); SELECT LAST_INSERT_ID();")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '1:$((A920_OLD - 1))')" > /dev/null
expect "3.54 D1: during a news re-index search_site misses a Cyrillic title word of a news item still of version 1 and says indexing; /hledani rewrites it and finds it" \
  "$(mcp_call "$A920_TOKEN" search_site '{"query":"летопись"}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true); echo count($d["results"] ?? []), "|", var_export($d["indexing"] ?? null, true);')|$(a920_hrefs 'летопись' | grep -c 'stara-a920')" "0|true|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$A920_STATE'); DELETE FROM ka_novinky WHERE idc = $A920_OLD" > /dev/null
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# 3.54 (D1): a row the job has not indexed yet is no longer searched the 3.52 way – MCP (never fills) does not find it by its
# body and says indexing; the search page fills it first (heal) and finds it, and with nothing waiting shows no notice
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('fallback-a920', 'Starší stránka', '<p>Obsah fallbackq920 zde</p>', 1, 0)" > /dev/null
A920_NULL=$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'fallback-a920' AND hledani IS NULL")
A920_MCP=$(mcp_call "$A920_TOKEN" search_site '{"query":"fallbackq920"}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true); echo count($d["results"] ?? []), "|", var_export($d["indexing"] ?? null, true);')
A920_PAGE=$(a920_get 'fallbackq920')
expect "3.54 D1: a page with no search text yet (NULL) – search_site misses it with indexing: true; /hledani fills it, finds it by its body, no notice" \
  "$A920_NULL|$A920_MCP|$(printf '%s' "$A920_PAGE" | grep -c 'href="/fallback-a920"')|$(printf '%s' "$A920_PAGE" | grep -c 'se ještě indexují')|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'fallback-a920' AND hledani IS NULL")" \
  "1|0|true|1|0|0"

sq "DELETE FROM ka_stranky WHERE seo_link LIKE 'strana-a920-%' OR seo_link = 'fallback-a920'; DELETE FROM ka_novinky WHERE seo_link LIKE 'zprava-a920-%';
  DELETE FROM ka_kolekce_polozky WHERE idk = $A920_IDK; DELETE FROM ka_kolekce WHERE idk = $A920_IDK; DELETE FROM ka_api_tokeny WHERE nazev = 'a920 admin';
  DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
