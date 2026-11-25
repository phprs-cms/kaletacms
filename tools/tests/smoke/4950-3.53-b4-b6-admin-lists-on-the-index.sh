echo "== 3.53 B4, B6: the Pages list, the palette, list_pages and the lists of items search the index; the exact title first in the palette; tabs keep the search; a day's distinct queries stop at the cap"
b950_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; } # reads the whole input (no SIGPIPE under pipefail)
b950_mcp() { mcp_call "$B950_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
b950_palette() { curl -s -G -b "$JAR" "$B/admin.php" -d action=palette_search --data-urlencode "q=$1"; }
B950_NOW=$(site_time)
B950_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B950_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
B950_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B950_ADMIN, 'b950 admin', 'full', 'all', SHA2('$B950_TOKEN', 256), '$B950_NOW')" > /dev/null

# pages: "Straße" only in the text of one page; another page has "strasse" only inside a link address (no visible word);
# seven pages with zahradb950 in the text (the palette's "+N more" and the list count the same); one hidden page
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, jazyk) VALUES
  ('hotel-b950', 'Hotel B950', '<p>Am Markt, Straße der Einheit 5.</p>', 1, 0, ''),
  ('odkaz-b950', 'Odkaz B950', '<p><a href=\"/strasse-b950x\">mapa</a></p>', 1, 0, ''),
  ('z1-b950', 'Z1 B950', '<p>zahradb950</p>', 1, 0, ''), ('z2-b950', 'Z2 B950', '<p>zahradb950</p>', 1, 0, ''), ('z3-b950', 'Z3 B950', '<p>zahradb950</p>', 1, 0, ''),
  ('z4-b950', 'Z4 B950', '<p>zahradb950</p>', 1, 0, ''), ('z5-b950', 'Z5 B950', '<p>zahradb950</p>', 1, 0, ''), ('z6-b950', 'Z6 B950', '<p>zahradb950</p>', 1, 0, ''),
  ('z7-b950', 'Z7 B950', '<p>zahradb950</p>', 0, 0, '')" > /dev/null
# news: the exact title "Výroční zpráva B950" is older than 21 newer news that mention it in their text
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex) VALUES ('vyrocni-b950', 'Výroční zpráva B950', '', '<p>Celá zpráva.</p>', $B950_TEMA, '2020-01-01 10:00:00', 1, 0)" > /dev/null
for B950_I in $(seq 1 21); do
  sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex) VALUES ('zminka-$B950_I-b950', 'Zmínka $B950_I', '', '<p>Viz Výroční zpráva B950.</p>', $B950_TEMA, '$B950_NOW', 1, 0)" > /dev/null
done
expect "3.53 B4: the job indexes the new pages and news" "$(fill_search_index && echo filled)" "filled"

B950_LIST=$(curl -s -G -b "$JAR" "$B/admin.php" -d module=pages --data-urlencode 'search=strasse')
expect "3.53 B4: \"strasse\" finds the page with \"Straße\" in the Pages list, the palette and list_pages – and not the page with the word only in a link address" \
  "$(printf '%s' "$B950_LIST" | b950_has 'Hotel B950')$(printf '%s' "$B950_LIST" | b950_has 'Odkaz B950')|$(b950_palette strasse | b950_has 'Hotel B950')$(b950_palette strasse | b950_has 'Odkaz B950')|$(b950_mcp list_pages '{"search":"strasse"}' | b950_has 'Hotel B950')$(b950_mcp list_pages '{"search":"strasse"}' | b950_has 'Odkaz B950')" \
  "10|10|10"
expect "3.53 B4: the palette's count of pages equals the Pages list's \"Found\" (the text by its index in both)" \
  "$(b950_palette zahradb950 | php -r 'foreach (json_decode(stream_get_contents(STDIN), true)["groups"] ?? [] as $g) { if ($g["type"] === "page") { echo $g["total"]; } }')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=pages --data-urlencode 'search=zahradb950' | grep -o '<span data-found>[^<]*' | grep -o '[0-9][0-9]*' | head -1)" \
  "7|7"
expect "3.53 B4: a news item titled exactly as the query is the first news in the palette, though 21 newer news mention it" \
  "$(b950_palette 'Výroční zpráva B950' | php -r 'foreach (json_decode(stream_get_contents(STDIN), true)["groups"] ?? [] as $g) { if ($g["type"] === "news") { echo $g["items"][0]["title"], "|", $g["total"]; } }')" \
  "Výroční zpráva B950|22"
expect "3.53 B4: the status tabs of the News, Pages and Enquiries lists keep the search" \
  "$(curl -s -G -b "$JAR" "$B/admin.php" -d module=news --data-urlencode 'search=b950' | b950_has 'status=vydane&amp;search=b950')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=pages --data-urlencode 'search=b950' | b950_has 'status=hidden&amp;search=b950')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=enquiries --data-urlencode 'search=b950' | b950_has 'status=otevrene&amp;search=b950')" \
  "1|1|1"

# collection items: "Łódź" in a text field (key mesto); a number as the search of list_collection_items; a field key finds nothing
b950_mcp create_collection '{"name":"Pobočky B950","slug":"pobocky-b950","fields":[{"label":"Město","type":"text"},{"label":"Foto","type":"image"}]}' > /dev/null
b950_mcp save_collection_item '{"collection":"pobocky-b950","name":"Pobočka 950","slug":"pobocka-950","visible":true,"values":{"mesto":"Łódź","foto":"/media/lodz-b950.jpg"}}' > /dev/null
b950_mcp save_collection_item '{"collection":"pobocky-b950","name":"Pobočka sever","slug":"pobocka-sever","visible":true,"values":{"mesto":"Praha"}}' > /dev/null
B950_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'pobocky-b950'")
B950_ITEMS=$(curl -s -G -b "$JAR" "$B/admin.php" -d module=collections -d action=items -d id="$B950_IDK" --data-urlencode 'search=lodz')
expect "3.53 B4: \"lodz\" finds the item with \"Łódź\" in the list of items, the palette and list_collection_items; a number is a search of list_collection_items" \
  "$(printf '%s' "$B950_ITEMS" | b950_has 'Pobočka 950')$(printf '%s' "$B950_ITEMS" | b950_has 'Pobočka sever')|$(b950_palette lodz | b950_has 'Pobočka 950')|$(b950_mcp list_collection_items '{"collection":"pobocky-b950","search":"lodz"}' | b950_has 'Pobočka 950')|$(b950_mcp list_collection_items '{"collection":"pobocky-b950","search":950}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["total"] ?? "?";')" \
  "10|1|1|1"
expect "3.53 B4: a field key or an image path of the fields is no match once the item is indexed – in the list and over MCP" \
  "$(curl -s -G -b "$JAR" "$B/admin.php" -d module=collections -d action=items -d id="$B950_IDK" --data-urlencode 'search=mesto' | b950_has 'Pobočka 950')|$(b950_mcp list_collection_items '{"collection":"pobocky-b950","search":"lodz-b950"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["total"] ?? "?";')" \
  "0|0"
sq "UPDATE ka_kolekce_polozky SET hledani = NULL WHERE seo_link = 'pobocka-950'" > /dev/null
expect "3.54 D2: an item not indexed yet is found by its name, not by its fields (no 3.52 JSON search); found by the fields again once the job fills it" \
  "$(b950_mcp list_collection_items '{"collection":"pobocky-b950","search":"Łódź"}' | b950_has 'Pobočka 950')|$(b950_mcp list_collection_items '{"collection":"pobocky-b950","search":"Pobočka 950"}' | b950_has 'Pobočka 950')|$(fill_search_index && b950_mcp list_collection_items '{"collection":"pobocky-b950","search":"Łódź"}' | b950_has 'Pobočka 950')" "0|1|1"

# B6: a day keeps at most 2000 distinct queries of a language version – the next new one is not stored, a known one still counts
B950_STATS=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'stats'), '')")
b950_mcp update_settings '{"settings":{"stats":true}}' > /dev/null
sq "DELETE FROM ka_search_queries; DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
B950_TODAY=$(sq "SELECT DATE('$B950_NOW')")
B950_DIGITS="(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)"
sq "INSERT INTO ka_search_queries (day, language, query, searches, without_result)
  SELECT '$B950_TODAY', '', CONCAT('dotaz ', n), 1, 0 FROM (SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d AS n FROM $B950_DIGITS a, $B950_DIGITS b, $B950_DIGITS c, $B950_DIGITS e) x WHERE n BETWEEN 1 AND 1999" > /dev/null
for B950_Q in 'poslední b950' 'navíc b950' 'dotaz 7'; do curl -s -o /dev/null -G -A 'Mozilla/5.0 test' "$B/hledani" --data-urlencode "q=$B950_Q"; done
expect "3.53 B6: the 2000th distinct query of the day is stored, the next new one is not, a known one is still counted" \
  "$(sq "SELECT COUNT(*) FROM ka_search_queries WHERE day = '$B950_TODAY' AND language = ''")|$(sq "SELECT COUNT(*) FROM ka_search_queries WHERE query IN ('poslední b950', 'navíc b950')")|$(sq "SELECT searches FROM ka_search_queries WHERE query = 'dotaz 7'")" \
  "2000|1|2"

[ "$B950_STATS" = 1 ] || b950_mcp update_settings '{"settings":{"stats":"0"}}' > /dev/null
sq "DELETE FROM ka_search_queries; DELETE FROM ka_kontrola_ip WHERE typ = 'hledani';
  DELETE FROM ka_stranky WHERE seo_link LIKE '%-b950'; DELETE FROM ka_novinky WHERE seo_link LIKE '%-b950';
  DELETE FROM ka_kolekce_polozky WHERE idk = ${B950_IDK:-0}; DELETE FROM ka_kolekce WHERE idk = ${B950_IDK:-0}; DELETE FROM ka_api_tokeny WHERE nazev = 'b950 admin'" > /dev/null
