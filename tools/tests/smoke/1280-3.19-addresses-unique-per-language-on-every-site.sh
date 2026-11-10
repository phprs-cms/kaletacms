echo "== 3.19: addresses unique per language on every site, tags per language, redirects by the exact address (migration 0106)"
# an upgraded site as 3.18.3 left it: the setting off and the global keys in place, a tag shared by a Czech and an English
# news item, a tag from before 3.14 ('') used only by an English one, redirects the background job has not reached (two
# of them count as one under the Czech collation) – then the data migration runs by name, as on the first admin visit
mig319() { sq "UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0106-addresses-per-language,', ',')) WHERE promenna = 'data_migrations'" > /dev/null
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/mig319.html" "$B/admin.php"; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
slugoff39 > /dev/null
sq "INSERT INTO ka_kategorie (nazev, seo_link, popis, jazyk) VALUES ('News 319', 'news-319', '', 'en')" > /dev/null
mcp create_news '{"title":"Fair EN 319","category":"News 319","text":"<p>Fair in English 319</p>","tags":"Veletrh 319","publish":true}' > /dev/null
mcp create_news '{"title":"Veletrh CS 319","category":"Zprávy 39","text":"<p>Veletrh česky 319</p>","tags":"Veletrh 319","publish":true}' > /dev/null
EN319=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Fair EN 319'")
sq "INSERT INTO ka_stitky (nazev, seo_link, jazyk, popis) VALUES ('Stary 319', 'stary-319', '', '<p>Topic 319</p>'); INSERT INTO ka_novinky_stitky (idc, ids) SELECT $EN319, ids FROM ka_stitky WHERE seo_link = 'stary-319';
  INSERT INTO ka_presmerovani (z_adresy, source_norm, na_adresu, vytvoreno) VALUES ('cenik-319', NULL, 'kontakt-39', '$(site_time)'), ('cen%C3%ADk-319', NULL, 'o-nas-39', '$(site_time)'), ('Akce-319', NULL, 'kontakt-39', '$(site_time)')" > /dev/null
expect "3.19: the site before the update – off, global keys, one tag for both news items, three redirects not normalised" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(tagkey314)|$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', (SELECT COUNT(*) FROM ka_novinky_stitky ns WHERE ns.ids = s.ids))) FROM ka_stitky s WHERE seo_link = 'veletrh-319'")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE source_norm IS NULL")" \
  "0|1|en:2|3"
EVENTS319=$(sq "SELECT COALESCE(MAX(id), 0) FROM ka_events")
mig319
expect "3.19: after the migration the setting is on and only the per-language keys are left (plus the plain lookup keys)" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)|$(tagkey314)|$(sq "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('ix_stranky_seo', 'ix_clanky_seo', 'ix_topic_seo', 'ix_stitky_seo')")" \
  "1|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo|0|4"
expect "3.19 tag split: one tag per language, each news item linked to the tag of its own language" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(s.jazyk, ':', n.titulek) ORDER BY s.jazyk) FROM ka_stitky s JOIN ka_novinky_stitky ns ON ns.ids = s.ids JOIN ka_novinky n ON n.idc = ns.idc WHERE s.seo_link = 'veletrh-319'")|$(sq "SELECT COUNT(*) FROM ka_stitky WHERE seo_link = 'veletrh-319'")" \
  ":Veletrh CS 319,en:Fair EN 319|2"
expect "3.19 tag split: a tag used only by English news moves to English and keeps its row and topic text" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MIN(jazyk), '|', MIN(popis)) FROM ka_stitky WHERE seo_link = 'stary-319'")" "1|en|<p>Topic 319</p>"
T319=$(get39 /novinky/stitek/veletrh-319); contains -q 'Veletrh CS 319' "$WORK/response" && ! contains -q 'Fair EN 319' "$WORK/response" || T319="$T319 wrong"
T319EN=$(get39 /en/news/tag/veletrh-319); contains -q 'Fair EN 319' "$WORK/response" && ! contains -q 'Veletrh CS 319' "$WORK/response" || T319EN="$T319EN wrong"
expect "3.19: each tag page lists its own version; the moved tag answers in English only" "$T319|$T319EN|$(get39 /en/news/tag/stary-319)|$(get39 /novinky/stitek/stary-319)" "200 |200 |200 |404 "
expect "3.19: every redirect has its exact normal form now" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE source_norm IS NULL")|$(sq "SELECT GROUP_CONCAT(source_norm ORDER BY idp) FROM ka_presmerovani WHERE z_adresy IN ('cenik-319', 'cen%C3%ADk-319', 'Akce-319')")" \
  "0|cenik-319,ceník-319,akce-319"
expect "3.19: redirects by the exact address – /cenik-319 and /ceník-319 are two addresses now, letter case still does not matter" \
  "$(get39 /cenik-319)|$(get39 /cen%C3%ADk-319)|$(get39 /AKCE-319)" "301 $B/kontakt-39|301 $B/o-nas-39|301 $B/kontakt-39"
expect "3.19: the migration recorded its events (switched on, tags split, colliding redirects)" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(type, ':', severity) ORDER BY id) FROM ka_events WHERE id > $EVENTS319 AND type IN ('slugs.per_language', 'tags.split', 'redirect.collisions', 'slugs.per_language_failed')")|$(sq "SELECT JSON_EXTRACT(data, '\$.groups') FROM ka_events WHERE id > $EVENTS319 AND type = 'redirect.collisions'")" \
  "redirect.collisions:warning,slugs.per_language:info,tags.split:info|1"
check "3.19: System status – addresses in every language are on" 200 "/admin.php?module=status" 'každá jazyková verze smí použít stejnou adresu jednou'
grep -qF '/cenik-319 = /cen%C3%ADk-319' "$WORK/response" && grep -qF 'skupin(a) přesměrování má staré adresy, které se před Kaletou 3.19 počítaly jako jedna adresa' "$WORK/response" \
  && echo "  ok     3.19: … and lists the redirects whose old addresses collide once compared exactly (nothing deleted)" || { echo "  CHYBA  3.19: the collision report on System status"; ERRORS=$((ERRORS+1)); }
mcp get_health '{}' > "$WORK/response"
contains -q 'cen%C3%ADk-319' "$WORK/response" && echo "  ok     3.19 MCP: get_health lists the colliding redirects too" || { echo "  CHYBA  3.19 MCP get_health collision"; ERRORS=$((ERRORS+1)); }
# on the upgraded site: kontakt, a news item, a category and a tag with the same address in Czech and in English
mcp create_page '{"title":"Kontakt 319","slug":"kontakt-319","content":"<p>Kontakt česky 319</p>","visible":true}' > "$WORK/response"; K319=$(mcp_value id)
mcp create_page "{\"title\":\"Contact 319\",\"slug\":\"kontakt-319\",\"language\":\"en\",\"translation_of\":$K319,\"content\":\"<p>Contact in English 319</p>\",\"visible\":true}" > "$WORK/response"
mcp create_category '{"name":"Rubrika 319"}' > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=categories&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d idt=0 -d "nazev=Rubrika 319" -d seo_link=rubrika-319 -d jazyk=en -d popis= -d hodnost=100
mcp create_news '{"title":"Zprava 319","category":"Zprávy 39","text":"<p>Zpráva česky 319</p>","tags":"Stitek 319","publish":true}' > /dev/null
mcp create_news '{"title":"Zprava 319","category":"News 319","text":"<p>Report in English 319</p>","tags":"Stitek 319","publish":true}' > /dev/null
expect "3.19 upgraded site: a page, a category, a news item and a tag with the same address in cs and en" \
  "$(sq "SELECT GROUP_CONCAT(jazyk ORDER BY jazyk) FROM ka_stranky WHERE seo_link = 'kontakt-319'")|$(sq "SELECT GROUP_CONCAT(jazyk ORDER BY jazyk) FROM ka_kategorie WHERE seo_link = 'rubrika-319'")|$(sq "SELECT GROUP_CONCAT(jazyk ORDER BY jazyk) FROM ka_novinky WHERE seo_link = 'zprava-319'")|$(sq "SELECT GROUP_CONCAT(jazyk ORDER BY jazyk) FROM ka_stitky WHERE seo_link = 'stitek-319'")" \
  ",en|,en|,en|,en"
expect "3.19 upgraded site: each address answers with its own version" "$(get39 /kontakt-319)|$(contains -q 'Kontakt česky 319' "$WORK/response" && echo cs)|$(get39 /en/kontakt-319)|$(contains -q 'Contact in English 319' "$WORK/response" && echo en)|$(get39 /novinky/zprava-319)|$(get39 /en/news/zprava-319)|$(contains -q 'Report in English 319' "$WORK/response" && echo en)" \
  "200 |cs|200 |en|200 |200 |en"
mcp create_page '{"title":"Kontakt znovu 319","slug":"kontakt-319","content":"<p>x</p>"}' > "$WORK/response"
expect "3.19: a second kontakt-319 in the same language is still refused" "$(contains -q 'isError' "$WORK/response" && echo refused)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'kontakt-319'")" "refused|2"
# a site that cannot switch: two categories of one language share a slug and neither key holds (removed by hand, a partial
# restore) – the migration leaves everything as it is, records why, System status and Settings → General say what to do
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'slugs_per_language'; ALTER TABLE ka_kategorie DROP INDEX uq_topic_jazyk_seo;
  INSERT INTO ka_kategorie (nazev, seo_link, popis, jazyk) VALUES ('Dvojice A', 'dvojice-319', '', ''), ('Dvojice B', 'dvojice-319', '', '')" > /dev/null
EVENTS319=$(sq "SELECT COALESCE(MAX(id), 0) FROM ka_events")
mig319
expect "3.19 blocked: the site stays as it was – off, keys untouched, the reason stored with the shared address" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)|$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.reason')), ':', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.slugs[0]'))) FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'")" \
  "0|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo|shared:/novinky/kategorie/dvojice-319"
expect "3.19 blocked: the event says it could not switch" "$(sq "SELECT CONCAT(type, ':', severity) FROM ka_events WHERE id > $EVENTS319 AND type LIKE 'slugs.%'")" "slugs.per_language_failed:warning"
check "3.19 blocked: System status warns and says what to do" 200 "/admin.php?module=status" 'Dvě položky jedné jazykové verze mají stejnou adresu (/novinky/kategorie/dvojice-319)'
check "3.19 blocked: Settings → Languages shows the reason and offers the switch" 200 "/admin.php?module=settings&tab=languages" 'name="slugs_per_language" value="1">'
grep -qF 'Dvě položky jedné jazykové verze mají stejnou adresu (/novinky/kategorie/dvojice-319)' "$WORK/response" && echo "  ok     3.19 blocked: … with the reason" || { echo "  CHYBA  3.19 blocked: no reason in Settings → General"; ERRORS=$((ERRORS+1)); }
general39 1
expect "3.19 blocked: switching on again is refused while the cause remains" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")" "0"
sq "UPDATE ka_kategorie SET seo_link = 'dvojice-319-b' WHERE nazev = 'Dvojice B'" > /dev/null
general39 1
expect "3.19 blocked: once fixed, Settings → General switches it on – the key back, the reason gone" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_switch_failed'")" \
  "1|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo|"
check "3.19: System status is green for the addresses again" 200 "/admin.php?module=status" 'každá jazyková verze smí použít stejnou adresu jednou'
sq "DELETE FROM ka_kategorie WHERE nazev IN ('Dvojice A', 'Dvojice B'); DELETE FROM ka_presmerovani WHERE z_adresy IN ('cenik-319', 'cen%C3%ADk-319', 'Akce-319')" > /dev/null
sq "UPDATE ka_nastaveni SET hodnota = '$HOME39' WHERE promenna = 'home_page'" > /dev/null

