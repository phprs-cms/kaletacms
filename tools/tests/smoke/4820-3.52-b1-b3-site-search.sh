echo "== 3.52 B1–B3: one ranked site search across pages, collection items and news – word forms, two letters, snippets, one paging; MCP search_site"
b820_get() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1" ${2:+-d "page=$2"}; }
# the hrefs of the results in their order, joined by |
b820_hrefs() { b820_get "$1" "${2:-}" | sed -n '/<ul class="vypis-stranky">/,/<\/ul>/p' | grep -o '<a href="[^"]*"' | sed 's/<a href="//; s/"$//' | tr '\n' '|'; }
B820_NOW=$(site_time)
B820_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B820_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
B820_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B820_ADMIN, 'b820 admin', 'full', 'all', SHA2('$B820_TOKEN', 256), '$B820_NOW')" > /dev/null

# a page with the word in its title, a page with it in the text, a hidden page, a collection item with a page, a news item
# found only by its text and a noindex news item – every one with the marker B820
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES
  ('koupelny-b820', 'Rekonstrukce koupelen B820', 'Koupelny na klíč a obklady.', 1),
  ('kontakt-b820', 'Kontakt B820', 'Napište nám o koupelně. Lorem sit amet, &lt;tajné&gt; &amp; víc.', 1),
  ('skryta-b820', 'Koupelna skrytá B820', 'koupelna', 0),
  ('it-b820', 'IT oddělení B820', 'Správa sítí a IT.', 1);
  INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES
  ('novinka-b820', 'Hotovo B820', '<p>Krátký perex.</p>', '<p>Text o nových koupelnách a obkladech.</p>', $B820_TEMA, '$B820_NOW', 1, 0, 'hotovo b820 kratky perex text o novych koupelnach a obkladech'),
  ('noindex-b820', 'Koupelna noindex B820', '', 'koupelna', $B820_TEMA, '$B820_NOW', 1, 1, 'koupelna noindex b820 koupelna')" > /dev/null
B820_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail) VALUES ('Reference B820', 'reference-b820', '[{\"klic\":\"popis\",\"typ\":\"text\"}]', 1); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($B820_IDK, 'Byt Brno B820', 'byt-b820', '{\"popis\":\"Kompletní rekonstrukce koupelny a kuchyně.\"}', '$B820_NOW', 1)" > /dev/null

# ranked: both words in the title (koupelen) first; the fresh news item (its text: koupelnách) gets a small bonus; the page
# (koupelně) and the item (koupelny) tie and keep their order – pages before items
expect "3.52 B1, B2: \"koupelna b820\" – one list across the kinds, other word forms (koupelen, koupelnách, koupelně, koupelny), never the hidden page or the noindex news" \
  "$(b820_hrefs 'koupelna b820')" "/koupelny-b820|/novinky/novinka-b820|/kontakt-b820|/reference-b820/byt-b820|"
B820_PAGE=$(b820_get 'koupelna b820')
expect "3.52 B1, B3: one count (Found: 4), the title \"Search: …\", the kinds of the results, matched words in <mark>, the snippet escaped, noindex and never cached" \
  "$(printf '%s' "$B820_PAGE" | grep -c 'Nalezeno: 4')|$(printf '%s' "$B820_PAGE" | grep -c '<title>Hledání: koupelna b820')|$(printf '%s' "$B820_PAGE" | grep -c 'class="vypis-stranky-typ">Reference B820<')|$(printf '%s' "$B820_PAGE" | grep -c 'o <mark>koupelně</mark>. Lorem sit amet, &lt;tajné&gt; &amp; víc.')|$(printf '%s' "$B820_PAGE" | grep -c '<meta name="robots" content="noindex')|$(ls "$WORK"/web/storage/cache/stranky/ 2>/dev/null | xargs -I{} grep -l 'Hledání: koupelna b820' "$WORK/web/storage/cache/stranky/{}" 2>/dev/null | wc -l | tr -d ' ')" \
  "1|1|1|1|1|0"
expect "3.52 B2: \"IT\" (two letters) finds the IT page and not \"sit amet\"; one letter is not searched" \
  "$(b820_hrefs 'IT B820')|$(b820_get 'x' | grep -c 'Zadejte alespoň 2 znaky.')" "/it-b820||1"
expect "3.52 B2: no result has every word – the ones with some of them, and the page says so" \
  "$(b820_get 'kuchyně zzqqb820' | grep -c 'Žádný výsledek neobsahuje všechna slova')|$(b820_hrefs 'kuchyně zzqqb820' | grep -c 'byt-b820')" "1|1"
expect "3.52 B3: nothing found – Found: 0, the advice and the main pages as on the 404 page" \
  "$(b820_get 'zzqqxxb820' | grep -c 'Nic jsme nenašli. Zkuste jiná nebo méně slov')" "1"

# one paging across the kinds: 3 a page; the second page holds the rest, a page past the end is the last one
B820_PER_PAGE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'news_per_page'), '9')")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('news_per_page', '3')" > /dev/null
expect "3.52 B1: one paging of the mixed list – page 1 has 3, page 2 the fourth, page 9 is the last page" \
  "$(b820_hrefs 'koupelna b820' | tr '|' '\n' | grep -c .)|$(b820_hrefs 'koupelna b820' 2)|$(b820_hrefs 'koupelna b820' 9)" "3|/reference-b820/byt-b820||/reference-b820/byt-b820|"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('news_per_page', '$B820_PER_PAGE')" > /dev/null

expect "3.52 B1: MCP search_site – the same ranked list with public addresses, plain snippets and the total; one letter refused" \
  "$(mcp_call "$B820_TOKEN" search_site '{"query":"koupelna b820","limit":2}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); $d = json_decode($r["result"]["content"][0]["text"] ?? "{}", true); echo implode(",", array_column($d["results"] ?? [], "type")), "|", $d["total"] ?? "-", "|", (int) str_ends_with($d["results"][0]["url"] ?? "", "/koupelny-b820"), "|", (int) !str_contains(json_encode($d), "<mark>"), "|", implode(",", $d["untrusted"] ?? []);')|$(mcp_call "$B820_TOKEN" search_site '{"query":"k"}' | grep -c 'two characters')" \
  "page,news|4|1|1|results|1"

# the limit of 30 searches a minute from one address still answers 429
B820_IP=$(sq "SELECT ip_adresa FROM ka_kontrola_ip WHERE typ = 'hledani' ORDER BY cas DESC LIMIT 1")
B820_ROWS=$(for i in $(seq 1 30); do printf "('%s', 'hledani', 0, '%s')," "$B820_IP" "$B820_NOW"; done)
sq "INSERT INTO ka_kontrola_ip (ip_adresa, typ, cil, cas) VALUES ${B820_ROWS%,}" > /dev/null
expect "3.52 B1: /hledani stays behind its limit (429 after 30 searches a minute)" "$(curl -s -o /dev/null -w '%{http_code}' "$B/hledani?q=koupelna")" "429"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

sq "DELETE FROM ka_stranky WHERE seo_link IN ('koupelny-b820', 'kontakt-b820', 'skryta-b820', 'it-b820'); DELETE FROM ka_novinky WHERE seo_link IN ('novinka-b820', 'noindex-b820');
  DELETE FROM ka_kolekce_polozky WHERE idk = $B820_IDK; DELETE FROM ka_kolekce WHERE idk = $B820_IDK; DELETE FROM ka_api_tokeny WHERE nazev = 'b820 admin'" > /dev/null
