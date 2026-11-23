echo "== 3.52 review: the site search finds ß, ł, æ and Arabic words, Chinese and Cyrillic queries, word starts past 300 news; old searched terms go daily"
b850_hrefs() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1" | sed -n '/<ul class="vypis-stranky">/,/<\/ul>/p' | grep -o '<a href="[^"]*"' | sed 's/<a href="//; s/"$//' | tr '\n' '|'; }
B850_NOW=$(site_time)
B850_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# letters utf8mb4_unicode_ci does not fold the way Search::normalize does – in the text, the title and (as older rows have
# it, \u escaped) the JSON of a collection item; an Arabic page with taa marbuta; Cyrillic and Chinese titles
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES
  ('anfahrt-b850', 'Anfahrt B850', 'Sie finden uns in der Hauptstraße 5.', 1),
  ('uslugi-b850', 'Usługi B850', 'Nasze usługi.', 1),
  ('madrasa-b850', 'مدرسة B850', 'نص', 1),
  ('moskva-b850', 'Москва B850', 'Офис.', 1)" > /dev/null
B850_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail) VALUES ('Pobočky B850', 'pobocky-b850', '[{\"klic\":\"mesto\",\"typ\":\"text\"}]', 1); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($B850_IDK, 'Pobočka B850', 'pobocka-b850', '{\"mesto\":\"\\\\u0141\\\\u00f3d\\\\u017a, \\\\u00e6ble\"}', '$B850_NOW', 1)" > /dev/null
expect "3.52 review: \"hauptstrasse b850\" and \"Hauptstraße b850\" find the page with Hauptstraße in its text; \"uslugi b850\" finds Usługi; \"lodz b850\" and \"aeble b850\" the item with escaped Łódź and æble" \
  "$(b850_hrefs 'hauptstrasse b850')$(b850_hrefs 'Hauptstraße b850')$(b850_hrefs 'uslugi b850')$(b850_hrefs 'lodz b850')$(b850_hrefs 'aeble b850')" \
  "/anfahrt-b850|/anfahrt-b850|/uslugi-b850|/pobocky-b850/pobocka-b850|/pobocky-b850/pobocka-b850|"
expect "3.52 review: an Arabic query with taa marbuta written as ha finds the page; a Cyrillic query finds its page" \
  "$(b850_hrefs 'مدرسه b850')$(b850_hrefs 'москва b850')" "/madrasa-b850|/moskva-b850|"

# a Chinese news title (3.51 found news by title for such queries); 305 newer news hold "b850q" only inside a word, the
# oldest one as a word of its own – the 300 candidates count only words that start with it
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES
  ('beijing-b850', '北京办公室 B850', '', '', $B850_TEMA, '$(site_time '-2 days')', 1, 0, 'b850'),
  ('slovo-b850', 'Stará zpráva', '', 'b850q', $B850_TEMA, '$(site_time '-3 days')', 1, 0, 'stara zprava b850q')" > /dev/null
B850_ROWS=$(for i in $(seq 1 305); do printf "('sum-b850-%s', 'Zpráva %s', '', '', %s, '%s', 1, 0, 'zprava xb850q')," "$i" "$i" "$B850_TEMA" "$(site_time '-1 day')"; done)
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES ${B850_ROWS%,}" > /dev/null
expect "3.52 review: \"北京\" finds the news by its Chinese title; \"b850q\" finds the old news past 305 newer ones with xb850q" \
  "$(b850_hrefs '北京')$(b850_hrefs 'b850q')" "/novinky/beijing-b850|/novinky/slovo-b850|"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# searched terms older than 400 days go with the daily clean-up (cron), also while the statistics are off
sq "INSERT INTO ka_search_queries (day, language, query, searches, without_result) VALUES ('$(site_time '-401 days' 'Y-m-d')', '', 'stare b850', 1, 0), ('$(site_time '-399 days' 'Y-m-d')', '', 'novejsi b850', 1, 0)" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'testtoken123')" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.52 review: the daily clean-up deletes searched terms older than 400 days and keeps the rest" \
  "$(sq "SELECT GROUP_CONCAT(query ORDER BY query) FROM ka_search_queries WHERE query IN ('stare b850', 'novejsi b850')")" "novejsi b850"

sq "DELETE FROM ka_stranky WHERE seo_link LIKE '%-b850'; DELETE FROM ka_novinky WHERE seo_link LIKE '%-b850' OR seo_link LIKE 'sum-b850-%';
  DELETE FROM ka_kolekce_polozky WHERE idk = $B850_IDK; DELETE FROM ka_kolekce WHERE idk = $B850_IDK; DELETE FROM ka_search_queries WHERE query LIKE '%b850'" > /dev/null
