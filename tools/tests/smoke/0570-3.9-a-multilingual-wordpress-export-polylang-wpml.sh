echo "== 3.9: a multilingual WordPress export (Polylang, WPML) arrives as linked language versions"
# the language settings and the draft look come back after this block; the site loses its English version for a moment,
# so the import has to add it
sq "DROP TABLE IF EXISTS ka_test_ml; CREATE TABLE ka_test_ml AS SELECT * FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'extensions', 'look_draft'); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('look_draft', 'additional_languages')" > /dev/null
# 3.19: this block shows a site the update could not switch (slugs unique across languages, as 3.18 left every site); the
# same import on a site with the same address in every language keeps a shared slug in both versions (WpImport::availableSlug)
expect "3.19: the multilingual import below runs on a site whose slugs are unique across languages" "$(slugs_global)" "null"
ml_run() { # $1 = fixture: upload, import confirmed with the first call, one call per batch until done
  mcp upload_file "{\"filename\":\"$1.xml\",\"data\":\"$(base64 < "$ROOT/tools/fixtures/$1.xml" | tr -d '\n')\"}" > "$WORK/response"
  ML_FILE=$(mcp_value import_file); [ -n "$ML_FILE" ] && [ "$ML_FILE" != null ] || ML_FILE="$1.xml" # an export already there is never replaced
  mcp import_wordpress "{\"file\":\"$ML_FILE\",\"confirm\":true,\"images\":false${2:-,\"add_languages\":true}}" > "$WORK/response"
  for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$ML_FILE\"}" > "$WORK/response"; done
}
ml_run wordpress-polylang
expect "3.9 Polylang: English added to the site, Hebrew reported and left out, every translation linked to its original (2 pages, a post, a category)" \
  "$(mcp_value phase)|$(mcp_value languages added_to_site 0)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(mcp_value languages not_available 0 language)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'solutions-ictx-he%'")|$(mcp_value languages translations_linked)" \
  "done|en|en|he|0|4"
expect "3.9 Polylang: the English page is in the English version and points to its Czech original (which came after it in the file)" \
  "$(sq "SELECT CONCAT(c.jazyk, '/', e.jazyk, '/', e.preklad_z = c.ids) FROM ka_stranky e JOIN ka_stranky c ON c.seo_link = 'reseni-ictx' WHERE e.seo_link = 'solutions-ictx'")" "/en/1"
expect "3.9 Polylang: a slug both languages share gets a number in English while slugs are global, and the report shows both addresses" \
  "$(sq "SELECT CONCAT(e.seo_link, '/', e.jazyk, '/', e.preklad_z = c.ids) FROM ka_stranky e JOIN ka_stranky c ON c.seo_link = 'kontakt-ictx' AND c.jazyk = '' WHERE e.seo_link LIKE 'kontakt-ictx-%'")|$(mcp_value languages slug_clashes 0 address)|$(mcp_value languages slug_clashes 0 taken_by)" \
  "kontakt-ictx-2/en/1|/en/kontakt-ictx-2|/kontakt-ictx"
expect "3.9 Polylang: the English post in the English category of the shared slug, both linked to the Czech ones, with their own names" \
  "$(sq "SELECT CONCAT(e.jazyk, '/', e.preklad_z = c.idc, '/', ek.nazev, '/', ek.jazyk, '/', ek.preklad_z = ck.idt, '/', ck.nazev) FROM ka_novinky e JOIN ka_kategorie ek ON ek.idt = e.tema JOIN ka_novinky c ON c.seo_link = 'snidane-s-ai-ictx' JOIN ka_kategorie ck ON ck.idt = c.tema WHERE e.seo_link = 'breakfast-with-ai-ictx'")" \
  "en/1/ICTX Blog/en/1/Blog ICTX"
expect "3.9 Polylang: old English addresses and ?lang=en redirect to the new ones" \
  "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/en/kontakt-ictx/")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/en/kontakt-ictx")|$(curl -s -o /dev/null -w '%{http_code}' "$B/en/blog-ictx/breakfast-with-ai-ictx/")|$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/?lang=en")|$(curl -s -o /dev/null -w '%{http_code}' "$B/?lang=xx")" \
  "301 $B/en/kontakt-ictx-2|301 $B/en/kontakt-ictx-2|301|301 $B/en|200"
expect "3.9 Polylang: a menu per language in the draft look (Czech main, English main), the English links name their version, the language switcher left out" \
  "$(mcp_value menus 0 language)|$(mcp_value menus 0 location)|$(mcp_value menus 1 language)|$(mcp_value menus 1 location)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.menus.\"hlavni|en\"[1].url')) FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(mcp_value menus 0 warnings 0)" \
  "|main|en|main|/en/novinky/kategorie/blog-ictx-2|“Jazyky” (the Polylang language switcher) was left out: this site shows its own language switcher."
ml_run wordpress-polylang
expect "3.9 Polylang again: nothing duplicated, no link changed" \
  "$(mcp_value languages translations_linked)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'kontakt-ictx%'")|$(sq "SELECT COUNT(*) FROM ka_kategorie WHERE seo_link LIKE 'blog-ictx%'")" "0|2|2"
ml_run wordpress-wpml
expect "3.9 WPML: page, duplicate, post and category linked; a page with its language only in the address goes to its version" \
  "$(mcp_value languages plugin)|$(mcp_value languages translations_linked)|$(sq "SELECT GROUP_CONCAT(CONCAT(e.seo_link, ':', e.jazyk, ':', COALESCE(e.preklad_z = c.ids, '-')) ORDER BY e.seo_link) FROM ka_stranky e LEFT JOIN ka_stranky c ON c.ids = e.preklad_z WHERE e.seo_link IN ('services-wpx', 'cenik-wpx-en', 'about-wpx')")" \
  "wpml|4|about-wpx:en:-,cenik-wpx-en:en:1,services-wpx:en:1"
expect "3.9 WPML: the English news item and category point to the Czech ones; the English menu is the English main menu" \
  "$(sq "SELECT CONCAT(e.jazyk, '/', c.seo_link, '/', ek.seo_link, '/', ck.seo_link) FROM ka_novinky e JOIN ka_novinky c ON c.idc = e.preklad_z JOIN ka_kategorie ek ON ek.idt = e.tema JOIN ka_kategorie ck ON ck.idt = ek.preklad_z WHERE e.seo_link = 'new-service-van-wpx'")|$(mcp_value menus 0 language)|$(mcp_value menus 0 status)" \
  "en/novy-servisni-vuz-wpx/news-wpx/aktuality-wpx|en|skipped"
# the admin reads the same file with the same code: its preview names the plugin and the languages
check "3.9 admin Import and export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-polylang.xml"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=progress&soubor=wordpress-polylang.xml" -d "_csrf=$TOKEN"
check "3.9 admin import preview: a multilingual site with its languages, the unavailable one named" 200 "/admin.php?module=transfer&action=preview&soubor=wordpress-polylang.xml" 'Polylang'
contains -q 'he (1)' "$WORK/response" && echo "  ok     3.9 admin import preview: Hebrew named as a language this site cannot offer" || { echo "  CHYBA  3.9 admin preview of the languages"; ERRORS=$((ERRORS+1)); }
# N39-3: without add_languages a multilingual import never adds a language version (it would show in the switcher at once)
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'additional_languages'" > /dev/null
cp "$ROOT/tools/fixtures/wordpress-polylang.xml" "$WORK/wordpress-polylang-n39.xml"
mcp upload_file "{\"filename\":\"wordpress-polylang-n39.xml\",\"data\":\"$(base64 < "$WORK/wordpress-polylang-n39.xml" | tr -d '\n')\"}" > "$WORK/response"
N39_FILE=$(mcp_value import_file); [ -n "$N39_FILE" ] && [ "$N39_FILE" != null ] || N39_FILE=wordpress-polylang-n39.xml
mcp import_wordpress "{\"file\":\"$N39_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"
for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$N39_FILE\"}" > "$WORK/response"; done
expect "3.9 N39-3: without add_languages the import adds no language version" "$(mcp_value phase)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(mcp_value languages added_to_site 0)" "done||null"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('additional_languages', 'extensions', 'look_draft'); INSERT INTO ka_nastaveni SELECT * FROM ka_test_ml; DROP TABLE ka_test_ml" > /dev/null
slugs_everywhere # 3.19: back to the same address once in each language (migration 0106 run again, as Settings → General does)

