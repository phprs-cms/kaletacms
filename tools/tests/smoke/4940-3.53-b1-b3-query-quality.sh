echo "== 3.53 B1–B3: punctuation words and other scripts never list every news item, a long Chinese word by its pairs, word forms in Polish and Russian"
b940_get() { curl -s -L -G -A "Mozilla/5.0 test" "$B/${2:-}hledani" --data-urlencode "q=$1"; }
b940_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; } # reads the whole input (no SIGPIPE under pipefail)
b940_mcp() { mcp_call "$B940_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
B940_NOW=$(site_time)
B940_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B940_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
B940_TOKEN="kaleta_$(openssl rand -hex 24)"
B940_LANGUAGES=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'), '')")
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B940_ADMIN, 'b940 admin', 'full', 'all', SHA2('$B940_TOKEN', 256), '$B940_NOW')" > /dev/null
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# news: R&B only in the text, a text with "sektor bank for business", a Cyrillic one (another case form than the query), a Czech one
sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex) VALUES
  ('vecer-b940', 'Hudební večer B940', '', '<p>Hrajeme R&amp;B a soul.</p>', $B940_TEMA, '$B940_NOW', 1, 0),
  ('banky-b940', 'Banky B940', '', '<p>Sektor bank for business</p>', $B940_TEMA, '$B940_NOW', 1, 0),
  ('moskva-b940', 'Pobočka B940', '', '<p>Наш офис в Москве открыт.</p>', $B940_TEMA, '$B940_NOW', 1, 0),
  ('bezna-b940', 'Běžná zpráva B940', '', '<p>Nic zvláštního.</p>', $B940_TEMA, '$B940_NOW', 1, 0)" > /dev/null
# pages: a Chinese one, a Polish one in a Polish language version (published: the home page has its Polish translation)
B940_HOME=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), '0')")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', '$(printf '%s' "${B940_LANGUAGES:+$B940_LANGUAGES,}pl")')" > /dev/null
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, jazyk, preklad_z) VALUES
  ('kancelar-b940', 'Kancelář B940', '<p>我们在北京有办公室</p>', 1, 0, '', NULL),
  ('uslugi-b940', 'Oferta B940', '<p>Nasze usługi i piękny ogród.</p>', 1, 0, 'pl', NULL),
  ('domov-b940', 'Strona główna B940', '<p>Witamy</p>', 1, 0, 'pl', NULLIF(${B940_HOME:-0}, 0))" > /dev/null
expect "3.53 B1–B3: the job indexes the new news and pages" "$(fill_search_index && echo filled)" "filled"

B940_RB_LIST=$(curl -s -G -b "$JAR" "$B/admin.php" -d module=news --data-urlencode 'search=R&B')
expect "3.53 B1: \"R&B\" in the news list, search_json and list_news finds the R&B news and not \"sektor bank for business\"" \
  "$(printf '%s' "$B940_RB_LIST" | b940_has 'Hudební večer B940')$(printf '%s' "$B940_RB_LIST" | b940_has 'Banky B940')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=news -d action=search_json --data-urlencode 'q=R&B' | b940_has 'Banky B940')|$(b940_mcp list_news '{"search":"R&B"}' | b940_has 'Hudební večer B940')$(b940_mcp list_news '{"search":"R&B"}' | b940_has 'Banky B940')" \
  "10|0|10"
B940_RU_LIST=$(curl -s -G -b "$JAR" "$B/admin.php" -d module=news --data-urlencode 'search=Москве')
expect "3.53 B1: a Cyrillic query in the news list, search_json and list_news lists only the matching news, not every news item" \
  "$(printf '%s' "$B940_RU_LIST" | b940_has 'Pobočka B940')$(printf '%s' "$B940_RU_LIST" | b940_has 'Běžná zpráva B940')|$(curl -s -G -b "$JAR" "$B/admin.php" -d module=news -d action=search_json --data-urlencode 'q=Москве' | b940_has 'Běžná zpráva B940')|$(b940_mcp list_news '{"search":"Москве"}' | b940_has 'Pobočka B940')$(b940_mcp list_news '{"search":"Москве"}' | b940_has 'Běžná zpráva B940')" \
  "10|0|10"
expect "3.53 B1: \"R&B\" on the site finds the R&B news only" \
  "$(b940_get 'R&B' | b940_has 'novinky/vecer-b940')$(b940_get 'R&B' | b940_has 'novinky/banky-b940')" "10"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

B940_ZH=$(b940_get '北京大学')
expect "3.53 B2: a long Chinese word without an exact hit is searched by its pairs – the page with 北京 is a partial result, on the site and over search_site" \
  "$(printf '%s' "$B940_ZH" | b940_has 'href="/kancelar-b940"')|$(printf '%s' "$B940_ZH" | b940_has 'Žádný výsledek neobsahuje všechna slova')|$(b940_mcp search_site '{"query":"北京大学"}' | b940_has 'kancelar-b940')" \
  "1|1|1"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

expect "3.53 B3: the Polish version finds \"usługi\" by \"usługami\" and \"piękny\" by \"pięknego\"; a Russian case form finds the news on the Czech site" \
  "$(b940_get 'usługami' 'pl/' | b940_has 'uslugi-b940')$(b940_get 'pięknego' 'pl/' | b940_has 'uslugi-b940')|$(b940_get 'Москва' | b940_has 'novinky/moskva-b940')" "11|1"

sq "DELETE FROM ka_novinky WHERE seo_link LIKE '%-b940'; DELETE FROM ka_stranky WHERE seo_link LIKE '%-b940'; DELETE FROM ka_api_tokeny WHERE nazev = 'b940 admin';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', '$B940_LANGUAGES'); DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
