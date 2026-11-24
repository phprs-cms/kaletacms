echo "== 3.53 review 2: a re-index that rewrites nothing is progress, news above the cursor match at a word start, a mixed CJK word in the lists, the Ukrainian apostrophe"
r980_get() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1"; }
r980_found() { local n; n=$(r980_get "$1" | grep -c "href=\"$2\"" || true); echo "${n:-0}"; }
r980_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
background_jobs hold
R980_STATE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'), '')")
R980_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
fill_search_index > /dev/null

# a re-index from the start over news whose text stays the same (version 1 = version 2 for them): a short run moves the
# cursor without rewriting a row – it stores "waiting:examined", never "waiting:0", and the index is not reported stuck
R980_NEWS=$(sq "SELECT COUNT(*) FROM ka_novinky")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '1:0'), ('search_index_checked', '')" > /dev/null
R980_RUN=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $r = Kaleta\Core\SearchIndex::run($app->db(), $app->settings(), 0.000001);
  echo str_contains($r, "re-indexed") ? "rewrote" : "unchanged", "|", $app->settings()->get(Kaleta\Core\SearchIndex::WAITING), "|", (int) Kaleta\Core\SearchIndex::stuck($app->settings()), "|", Kaleta\Core\SearchIndex::state($app->settings())[1] > 0 ? "moved" : "kept";')
expect "3.53 review 2: a re-index run over unchanged news rewrites none, stores \"waiting:1\" (the one row it examined), moves the cursor and is no stuck index" \
  "$R980_RUN" "unchanged|$((R980_NEWS - 1)):1|0|moved"
fill_search_index > /dev/null
expect "3.53 review 2: … the job finishes the re-index – nothing waits, the version is current" \
  "$(search_index_pending)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'")" "0|$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; echo Kaleta\Core\Search::VERSION;'):0"

# during a re-index a news item above the cursor is matched in its title and intro at the start of a word, as the ranking
# reads it: "Smartr980" holds the letters of "artr980" inside a word and must not count – each row has one of the words,
# so they are the partial result (counted as having both, the news item kept the partial pass away and nothing was listed)
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('artr980', 'Artr980 galerie', '<p>Obrazy.</p>', 1, 0),
  ('decor980', 'Decor980 interiéry', '<p>Nábytek.</p>', 1, 0)" > /dev/null
R980_ID=$(sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES ('smart-r980', 'Smartr980 party', 'decor980ations', '', $R980_TEMA, '$(site_time '-1 hour')', 1, 0, 'smartr980 party decor980ations'); SELECT LAST_INSERT_ID();")
fill_search_index > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '1:$((R980_ID - 1))')" > /dev/null
expect "3.53 review 2: during a re-index \"artr980 decor980\" lists the two pages and the news item as a partial result – \"Smartr980\" does not count as \"artr980\", so no row seems to have both words" \
  "$(r980_found 'artr980 decor980' /artr980)$(r980_found 'artr980 decor980' /decor980)$(r980_found 'artr980 decor980' /novinky/smart-r980)" "111"
expect "3.53 review 2: … \"decor980\" still finds the news item above the cursor by the start of a word in its intro" "$(r980_found 'decor980' /novinky/smart-r980)" "1"
# … also a word after a tag (the intro is HTML) or an opening quote, which a version 1 index may lack entirely
# … also a word after a tag (the intro is HTML), after a line break or in quotes, which a version 1 index may lack entirely;
# a word that sits only in a link address of the intro is no match: the total stays 0 and the page "Hrefr980" is listed alone
R980_HTML=$(sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES ('html-r980', 'Nagroda „Quoter980”', CONCAT('<p>Tagr980 roku<br>', CHAR(10), 'Linebr980 <a href=\\"https://example.test/hrefr980\\">zde</a></p>'), '', $R980_TEMA, '$(site_time '-1 hour')', 1, 0, 'nagroda'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('hrefr980', 'Hrefr980 stránka', '<p>Obsah.</p>', 1, 0);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$R980_STATE')" > /dev/null
fill_search_index > /dev/null # indexes the page; the news item keeps its version 1 text
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '1:$((R980_ID - 1))')" > /dev/null
expect "3.53 review 3: during a re-index a news item above the cursor is found by the first word of an HTML intro paragraph, a word after a line break and a word in quotes" \
  "$(r980_found 'tagr980' /novinky/html-r980)$(r980_found 'linebr980' /novinky/html-r980)$(r980_found 'quoter980' /novinky/html-r980)" "111"
expect "3.53 review 3: … a word only in a link address of its intro does not find it, and \"hrefr980 tagr980\" lists the page and the news item as a partial result" \
  "$(r980_found 'hrefr980' /novinky/html-r980)$(r980_found 'hrefr980 tagr980' /hrefr980)$(r980_found 'hrefr980 tagr980' /novinky/html-r980)" "011"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$R980_STATE'); DELETE FROM ka_novinky WHERE idc IN ($R980_ID, $R980_HTML); DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# the admin and MCP lists: a word mixing Chinese and Latin letters whose Chinese part sits inside a run of the text
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('cjk-r980', '网站 R980', '<p>我们使用WordPress搭建网站，商品价格100元</p>', 1, 0),
  ('ukr-r980', 'Кава R980', '<p>Він п''є каву щоранку.</p>', 1, 0)" > /dev/null
fill_search_index > /dev/null
mcp list_pages '{"search":"使用WordPress"}' > "$WORK/response"; R980_A=$(r980_has 'cjk-r980' < "$WORK/response")
mcp list_pages '{"search":"价格100"}' > "$WORK/response"; R980_B=$(r980_has 'cjk-r980' < "$WORK/response")
mcp list_pages '{"search":"使用Joomla"}' > "$WORK/response"; R980_C=$(r980_has 'cjk-r980' < "$WORK/response")
expect "3.53 review 2: list_pages \"使用WordPress\" and \"价格100\" find the page whose text has them inside a Chinese run; \"使用Joomla\" does not" "$R980_A$R980_B$R980_C" "110"

# the Ukrainian apostrophe is inside the word: "п'є" is the phrase "п є" and finds the page (both sides are one letter)
expect "3.53 review 2: \"п'є\" finds the Ukrainian page" "$(r980_found "п'є" /ukr-r980)" "1"

background_jobs resume
sq "DELETE FROM ka_stranky WHERE seo_link IN ('artr980', 'decor980', 'cjk-r980', 'ukr-r980', 'hrefr980'); DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
