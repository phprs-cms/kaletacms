echo "== 3.9: the same address in every language version (slugs_per_language, data model §3 step 1) – a bilingual WordPress site moves without renaming"
# a site with the English version, redirects and news on; the home page is the news list for this part (hreflang then
# needs no translated home page), restored at the end
EXT39=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
for e in jazyky presmerovani novinky; do case ",$EXT39," in *",$e,"*) ;; *) EXT39="$EXT39,$e";; esac; done
HOME39=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$EXT39'), ('additional_languages', 'en'), ('home_page', '0')" > /dev/null
keys39() { sq "SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ka_stranky', 'ka_novinky', 'ka_kategorie') AND INDEX_NAME LIKE 'uq_%seo' AND SEQ_IN_INDEX = 1"; }
get39() { rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" -w '%{http_code} %{redirect_url}' "$B$1"; }
general39() { # Settings → Languages (General until 3.23) saved as a browser does (every field as it is), with the switch on (1) or off (0)
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/general39.html" "$B/admin.php?module=settings&tab=languages"
  php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
    foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $n === "slugs_per_language" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
      $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
    echo implode("&", $q), $argv[2] === "1" ? "&slugs_per_language=1" : "";' -- "$WORK/general39.html" "$1" > "$WORK/general39.post"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general39.post"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/general39.html" "$B/admin.php?module=settings&tab=languages"
}
# 3.19: a fresh install has the same address once in each language on and no global keys (schema.sql, the installer); this
# part plays a site the update to 3.19 could not switch: the global keys come back through Slug::switchPerLanguage(off),
# which no form offers any more (it stays for the documented manual schema rollback), so it behaves as 3.18 left it
slugoff39() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo json_encode(Kaleta\Core\Slug::switchPerLanguage($app->db(), $app->settings(), false), JSON_UNESCAPED_SLASHES);'); }
expect "3.19: a fresh install has the same address in every language on, with only the per-language keys (and the plain lookup keys)" \
  "$(keys39)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(sq "SELECT GROUP_CONCAT(INDEX_NAME ORDER BY INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ka_stranky', 'ka_novinky', 'ka_kategorie', 'ka_stitky') AND INDEX_NAME LIKE '%seo' AND SEQ_IN_INDEX = 1")" \
  "uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo|1|ix_clanky_seo,ix_stitky_seo,ix_stranky_seo,ix_topic_seo,uq_clanky_jazyk_seo,uq_stitky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo"
curl -s -b "$JAR" -o "$WORK/general39.html" "$B/admin.php?module=settings&tab=languages"
grep -q 'name="slugs_per_language"' "$WORK/general39.html" && { echo "  CHYBA  3.19: Settings → General still offers the switch on a site that has it on"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.19: … and Settings → General offers no switch there"
expect "3.19: the manual rollback path switches it off while nothing is shared" "$(slugoff39)" "null"
expect "3.9: a site from before keeps its global slug keys next to the per-language ones (migration 0083), the switch is off" \
  "$(keys39)|$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'), '0')")" \
  "uq_clanky_jazyk_seo,uq_clanky_seo,uq_stranky_jazyk_seo,uq_stranky_seo,uq_topic_jazyk_seo,uq_topic_seo|0"
mcp create_page '{"title":"Kontakt","slug":"kontakt-39","content":"<p>Česky 39</p>","visible":true}' > "$WORK/response"; CS39=$(mcp_value id)
mcp create_page "{\"title\":\"Contact\",\"slug\":\"kontakt-39\",\"language\":\"en\",\"translation_of\":$CS39,\"content\":\"<p>English 39</p>\",\"visible\":true}" > "$WORK/response"
contains -q 'isError' "$WORK/response" && contains -q 'kontakt-39' "$WORK/response" && expect "3.9 off: as before, an English page cannot take the slug of a Czech one" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'kontakt-39'")" "1" \
  || { echo "  CHYBA  3.9 off: the second kontakt-39 was saved: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
# 3.14: tags have a language version; switched off a slug is still one tag for every version, as before
tagkey314() { sq "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ka_stitky' AND INDEX_NAME = 'uq_stitky_seo'"; }
mcp create_category '{"name":"Tagy 314"}' > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=categories&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d idt=0 -d "nazev=Tags 314" -d seo_link=tags-314 -d jazyk=en -d popis= -d hodnost=100
mcp create_news '{"title":"Fair EN 314","category":"Tags 314","text":"<p>Fair in English 314</p>","tags":"Fair 314","publish":true}' > /dev/null
mcp create_news '{"title":"Fair CS 314","category":"Tagy 314","text":"<p>Jarmark česky 314</p>","tags":"Fair 314","publish":true}' > /dev/null
expect "3.14 off: a tag created from an English news item is English; the Czech item with the same tag reuses it, as before" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(s.jazyk, ':', s.seo_link, ':', (SELECT COUNT(*) FROM ka_novinky_stitky ns WHERE ns.ids = s.ids))) FROM ka_stitky s WHERE s.seo_link = 'fair-314'")|$(tagkey314)" "en:fair-314:2|1"
T314=$(get39 /novinky/stitek/fair-314); contains -q 'Fair CS 314' "$WORK/response" || T314="$T314 wrong"
T314EN=$(get39 /en/news/tag/fair-314); contains -q 'Fair EN 314' "$WORK/response" || T314EN="$T314EN wrong"
expect "3.14 off: the tag page answers in both versions, each with its own news" "$T314|$T314EN" "200 |200 "
curl -s -b "$JAR" -o "$WORK/general39.html" "$B/admin.php?module=settings&tab=languages"
grep -q 'name="slugs_per_language" value="1">' "$WORK/general39.html" && echo "  ok     3.19: a site that is off gets the switch (unticked) in Settings → General" || { echo "  CHYBA  3.19: no slugs_per_language switch on a site that is off"; ERRORS=$((ERRORS+1)); }
general39 1
grep -q 'name="slugs_per_language"' "$WORK/general39.html" && { echo "  CHYBA  3.19: the switch is still offered once on"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.19: once on, Settings → General no longer offers it (it cannot be switched off)"
expect "3.19: switching on (migration 0106 again) split the tag both versions used – each news item keeps a tag of its own language" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(s.jazyk, ':', n.titulek) ORDER BY s.jazyk) FROM ka_stitky s JOIN ka_novinky_stitky ns ON ns.ids = s.ids JOIN ka_novinky n ON n.idc = ns.idc WHERE s.seo_link = 'fair-314'")" \
  ":Fair CS 314,en:Fair EN 314"
expect "3.9: switching on drops the global keys only; the per-language keys stay" "$(keys39)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")" \
  "uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo|1"
# pages: the same slug in cs and en, refused twice in one language, system addresses refused in both
mcp create_page "{\"title\":\"Contact\",\"slug\":\"kontakt-39\",\"language\":\"en\",\"translation_of\":$CS39,\"content\":\"<p>English 39</p>\",\"visible\":true}" > "$WORK/response"; EN39=$(mcp_value id)
mcp create_page '{"title":"Kontakt znovu","slug":"kontakt-39","content":"<p>x</p>"}' > "$WORK/response"
contains -q 'isError' "$WORK/response" && echo "  ok     3.9 on: a second page with the slug in the same language is refused (MCP)" || { echo "  CHYBA  3.9: duplicate in one language over MCP: $(head -c 300 "$WORK/response")"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 -d titulek=Duplicate -d seo_link=kontakt-39 -d jazyk=en
contains -q 'already exists\|už existuje' "$WORK/response" && echo "  ok     3.9 on: … and in the admin form, in the English version too" || { echo "  CHYBA  3.9: duplicate in one language in the admin"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Formular","slug":"form"}' > "$WORK/response"; R39A=$(contains -q 'isError' "$WORK/response" && echo refused)
mcp create_page '{"title":"Form","slug":"form","language":"en"}' > "$WORK/response"; R39B=$(contains -q 'isError' "$WORK/response" && echo refused)
mcp create_page '{"title":"En","slug":"en","language":"en"}' > "$WORK/response"; R39C=$(contains -q 'isError' "$WORK/response" && echo refused)
expect "3.9 on: a system address and a language code stay reserved in every language version" "$R39A|$R39B|$R39C|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('form', 'en')")" "refused|refused|refused|0"
expect "3.9 on: /kontakt-39 and /en/kontakt-39 are two pages" "$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', ids) ORDER BY jazyk) FROM ka_stranky WHERE seo_link = 'kontakt-39'")" ":$CS39,en:$EN39"
get39 /kontakt-39 > /dev/null; contains -q 'Česky 39' "$WORK/response" && contains -q "hreflang=\"en\" href=\"[^\"]*/en/kontakt-39\"" "$WORK/response" && P39CS=ok
get39 /en/kontakt-39 > /dev/null; contains -q 'English 39' "$WORK/response" && contains -q "hreflang=\"cs\" href=\"[^\"]*/kontakt-39\"" "$WORK/response" && P39EN=ok
expect "3.9 on: each address shows its own page with hreflang to the other" "${P39CS:-}|${P39EN:-}" "ok|ok"
# news and news categories: the same slugs in both versions
mcp create_category '{"name":"Zprávy 39"}' > /dev/null; sq "UPDATE ka_kategorie SET seo_link = 'zpravy-39' WHERE nazev = 'Zprávy 39'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=categories&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d idt=0 -d "nazev=News 39" -d seo_link=zpravy-39 -d jazyk=en -d popis= -d hodnost=100
expect "3.9 on: a news category with the same slug in both versions" "$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', seo_link) ORDER BY jazyk) FROM ka_kategorie WHERE nazev IN ('Zprávy 39', 'News 39')")" ":zpravy-39,en:zpravy-39"
mcp create_news '{"title":"Akce 39","category":"Zprávy 39","text":"<p>Novinka česky 39</p>","publish":true}' > /dev/null
mcp create_news '{"title":"Akce 39","category":"News 39","text":"<p>News in English 39</p>","publish":true}' > /dev/null
expect "3.9 on: a news item with the same slug in both versions" "$(sq "SELECT GROUP_CONCAT(CONCAT(jazyk, ':', seo_link) ORDER BY jazyk) FROM ka_novinky WHERE titulek = 'Akce 39'")" ":akce-39,en:akce-39"
N39CS=$(get39 /novinky/akce-39); contains -q 'Novinka česky 39' "$WORK/response" || N39CS="$N39CS wrong"
N39EN=$(get39 /en/news/akce-39); contains -q 'News in English 39' "$WORK/response" || N39EN="$N39EN wrong"
C39=$(get39 /novinky/kategorie/zpravy-39)\|$(get39 /en/news/category/zpravy-39)
expect "3.9 on: each news item and category answers in its own version" "$N39CS|$N39EN|$C39" "200 |200 |200 |200 "
# 3.14: a tag with the same slug in both versions – the switch dropped the global tag key too
mcp create_news '{"title":"Trh 39","category":"Zprávy 39","text":"<p>Trh česky 39</p>","tags":"Trh 39","publish":true}' > /dev/null
mcp create_news '{"title":"Market 39","category":"News 39","text":"<p>Market in English 39</p>","tags":"Trh 39","publish":true}' > /dev/null
expect "3.14 on: a tag with the same slug in both versions, each news item with the tag of its own version" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(s.jazyk, ':', s.seo_link, ':', n.titulek) ORDER BY s.jazyk) FROM ka_stitky s JOIN ka_novinky_stitky ns ON ns.ids = s.ids JOIN ka_novinky n ON n.idc = ns.idc WHERE s.seo_link = 'trh-39'")|$(tagkey314)" \
  ":trh-39:Trh 39,en:trh-39:Market 39|0"
T39=$(get39 /novinky/stitek/trh-39); contains -q 'Trh 39' "$WORK/response" && ! contains -q 'Market 39' "$WORK/response" || T39="$T39 wrong"
T39EN=$(get39 /en/news/tag/trh-39); contains -q 'Market 39' "$WORK/response" && ! contains -q '>Trh 39<' "$WORK/response" || T39EN="$T39EN wrong"
expect "3.14 on: /novinky/stitek/trh-39 and /en/news/tag/trh-39 are two tags, each listing its own version" "$T39|$T39EN" "200 |200 "
# N314-4: a tag from before 3.14 ('' = shared) that already tags an English item stays its tag when the item is saved again
MARKET39=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Market 39'"); MARKET39="${MARKET39:-0}"
sq "INSERT INTO ka_stitky (nazev, seo_link, jazyk) VALUES ('Stary 314', 'stary-314', ''); INSERT INTO ka_novinky_stitky (idc, ids) SELECT $MARKET39, ids FROM ka_stitky WHERE seo_link = 'stary-314'" > /dev/null
mcp update_news "{\"id\":$MARKET39,\"tags\":\"Trh 39, Stary 314\"}" > /dev/null
expect "3.14 on: saving an English item again keeps its tag from before 3.14 – no second tag with the same slug (N314-4)" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', MIN(jazyk), '|', (SELECT COUNT(*) FROM ka_novinky_stitky ns JOIN ka_stitky s2 ON s2.ids = ns.ids WHERE ns.idc = $MARKET39 AND s2.seo_link = 'stary-314')) FROM ka_stitky WHERE seo_link = 'stary-314'")" "1||1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=tags"
contains -q '/en/news/tag/trh-39" target="_blank" rel="noopener">#Trh 39</a> <span class="stitek">EN</span>' "$WORK/response" \
  && echo "  ok     3.14: Tags and topics show the language of a tag and link to its own version" || { echo "  CHYBA  3.14: tag list without the language"; ERRORS=$((ERRORS+1)); }
# a collection item and a collection category (both were per language before; the routing and hreflang stay)
mcp create_collection '{"name":"Sluzby 39","slug":"sluzby-39","item_pages":true,"fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"sluzby-39","name":"Servery","slug":"servery-39","values":{"popis":"Serverovna 39"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"sluzby-39","name":"Servers","slug":"servery-39","language":"en","values":{"popis":"Server room 39"},"visible":true}' > /dev/null
mcp save_collection_category '{"collection":"sluzby-39","name":"Cloud","slug":"cloud-39","visible":true}' > "$WORK/response"; CC39=$(mcp_value id)
mcp save_collection_category "{\"collection\":\"sluzby-39\",\"id\":$CC39,\"language\":\"en\",\"name\":\"Cloud EN\",\"slug\":\"cloud-39\"}" > /dev/null
I39=$(get39 /sluzby-39/servery-39); contains -q 'hreflang="en" href="[^"]*/en/sluzby-39/servery-39"' "$WORK/response" || I39="$I39 no-hreflang"
I39EN=$(get39 /en/sluzby-39/servery-39); contains -q 'hreflang="cs" href="[^"]*/sluzby-39/servery-39"' "$WORK/response" || I39EN="$I39EN no-hreflang"
K39=$(get39 /sluzby-39/cloud-39)\|$(get39 /en/sluzby-39/cloud-39); contains -q '<h1>Cloud EN</h1>' "$WORK/response" || K39="$K39 wrong"
expect "3.9 on: a collection item and a category with the same slug in cs and en, each with hreflang" "$I39|$I39EN|$K39" "200 |200 |200 |200 "
get39 /sitemap.xml > /dev/null
S39=""; for u in /kontakt-39 /en/kontakt-39 /novinky/akce-39 /en/news/akce-39 /sluzby-39/servery-39 /en/sluzby-39/servery-39 /sluzby-39/cloud-39 /en/sluzby-39/cloud-39; do contains -q "$u</loc>" "$WORK/response" && S39="$S39+" || S39="$S39 $u"; done
expect "3.9 on: the sitemap lists both versions of every address" "$S39" "++++++++"
# redirects: a version's own slug change redirects only that version (en/old), and heals only its links
mcp create_page '{"title":"O nas","slug":"o-nas-39","content":"<p>O nás 39 <a href=\"/o-nas-39\">cs</a> <a href=\"/en/o-nas-39\">en</a></p>","visible":true}' > "$WORK/response"; ON39=$(mcp_value id)
mcp create_page "{\"title\":\"About\",\"slug\":\"o-nas-39\",\"language\":\"en\",\"translation_of\":$ON39,\"content\":\"<p>About 39</p>\",\"visible\":true}" > "$WORK/response"; OE39=$(mcp_value id)
mcp update_page "{\"id\":$OE39,\"slug\":\"about-39\"}" > /dev/null
R39=$(get39 /en/o-nas-39)\|$(get39 /o-nas-39)
expect "3.9 on: the English slug change redirects /en/o-nas-39 only; /o-nas-39 stays the Czech page" "$R39|$(sq "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu)) FROM ka_presmerovani WHERE z_adresy LIKE '%o-nas-39'")" \
  "301 $B/en/about-39|200 |en/o-nas-39>en/about-39"
expect "3.9 on: link healing rewrote the English link only" "$(sq "SELECT CONCAT(text LIKE '%href=\"/o-nas-39\"%', text LIKE '%href=\"/en/about-39\"%') FROM ka_stranky WHERE ids = $ON39")" "11"
mcp save_redirect '{"from":"/en/stary-39","to":"/en/kontakt-39"}' > /dev/null
expect "3.9 on: a redirect stored with the prefix (a Polylang address) fires in its version" "$(get39 /en/stary-39)|$(get39 /stary-39)" "301 $B/en/kontakt-39|404 "
# 3.19: Settings → General cannot switch it off; the manual rollback path (Slug::switchPerLanguage) refuses while two versions
# share a slug, works once they do not and the global keys come back; the form then switches it on again (migration 0106)
general39 0
expect "3.19: a save of Settings → General never switches it off" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)" "1|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo"
contains -q 'kontakt-39' <<<"$(slugoff39)" && expect "3.9: switching off is refused while two language versions share a slug, with the slugs named" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)" "1|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo" \
  || { echo "  CHYBA  3.9: switching off with duplicates"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stranky WHERE jazyk = 'en' AND seo_link = 'kontakt-39'; DELETE FROM ka_novinky WHERE jazyk = 'en' AND seo_link IN ('akce-39', 'market-39'); DELETE FROM ka_kategorie WHERE jazyk = 'en' AND seo_link = 'zpravy-39'" > /dev/null
contains -q 'trh-39' <<<"$(slugoff39)" && expect "3.14: … a tag shared by two versions refuses it too" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(tagkey314)" "1|0" \
  || { echo "  CHYBA  3.14: switching off with a shared tag slug"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_stitky WHERE jazyk = 'en' AND seo_link = 'trh-39'; DELETE FROM ka_stitky WHERE jazyk = '' AND seo_link = 'fair-314'" > /dev/null
slugoff39 > /dev/null
expect "3.9: without shared slugs it switches off and the global keys are back" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)|$(tagkey314)" \
  "0|uq_clanky_jazyk_seo,uq_clanky_seo,uq_stranky_jazyk_seo,uq_stranky_seo,uq_topic_jazyk_seo,uq_topic_seo|1"
general39 1
expect "3.19: … and Settings → General switches it on again – the global keys go once more" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'slugs_per_language'")|$(keys39)|$(tagkey314)" \
  "1|uq_clanky_jazyk_seo,uq_stranky_jazyk_seo,uq_topic_jazyk_seo|0"

