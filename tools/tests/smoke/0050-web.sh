echo "== web"
check "úvodní stránka" 200 / "Testovací firma"
check "úvodní stránka má navigaci stránek a novinek" 200 / 'href="/o-nas"'
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/uvod"); expect "úvodní stránka má jen jednu adresu" "$code" "301 $B/"
check "stránka" 200 /sluzby "Služby"
check "úvodní stránka je ze sekcí builderu" 200 / 'class="stavba"'
check "služby mají otázky a odpovědi i pro vyhledávače" 200 /sluzby '"FAQPage"'
check "výpis novinek" 200 /novinky "Vítejte v Kaletě"
check "novinka" 200 /novinky/vitejte-v-kalete "Vítejte"
# 3.5 (UXP-06, UXP-16): a footer without links has no empty navigation landmark; the skip link's target takes focus
curl -s -o "$WORK/response" "$B/"; tr -d '\n\t' < "$WORK/response" > "$WORK/flat"
! grep -q '<ul></ul></nav>' "$WORK/flat" && ! grep -q 'aria-label="Odkazy v patičce"' "$WORK/response" && grep -q '<main id="obsah" class="stavba" tabindex="-1">' "$WORK/response" \
  && echo "  ok     3.5: no empty footer navigation, the main content is the skip link's focus target" || { echo "  CHYBA  3.5: footer navigation or main"; ERRORS=$((ERRORS+1)); }
check "kategorie" 200 /novinky/kategorie/aktuality
check "hledání najde novinku i stránku" 200 "/hledani?q=Kontakt" 'href="/kontakt"'
for u in /rss.xml /feed.json /sitemap.xml /robots.txt /llms.txt /novinky/vitejte-v-kalete.md; do check "$u" 200 "$u"; done
check "mapa webu obsahuje novinku" 200 /sitemap.xml "/novinky/vitejte-v-kalete"
check "llms.txt vyjmenuje stránky" 200 /llms.txt "## Stránky"
# a news intro with entities (&nbsp; from WordPress or a Kaleta site import, &amp;) is plain text wherever it is a summary:
# llms.txt, meta and og description, the BlogPosting description in JSON-LD – never the entity as text (&amp;nbsp;)
NBSP_BACKUP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(HEX(uvod), ':', HEX(seo_popis)) FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'")
"${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -e "UPDATE ka_novinky SET uvod = '<p>Jsme rádi, že jsme v&nbsp;prosinci 2022&nbsp;získali <b>územní</b> rozhodnutí.</p><p>R&amp;D</p>', seo_popis = '' WHERE seo_link = 'vitejte-v-kalete'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
NBSP_TEXT='Jsme rádi, že jsme v prosinci 2022 získali územní rozhodnutí. R'
curl -s -o "$WORK/response" "$B/llms.txt"
grep -q "$NBSP_TEXT&D" "$WORK/response" && ! grep -q '&nbsp;\|&amp;' "$WORK/response" \
  && echo "  ok     llms.txt: news intro without HTML entities" || { echo "  CHYBA  llms.txt: entity v úvodu novinky"; grep -m1 'vitejte-v-kalete' "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete"
grep -q "<meta name=\"description\" content=\"$NBSP_TEXT&amp;D\">" "$WORK/response" && grep -q "<meta property=\"og:description\" content=\"$NBSP_TEXT&amp;D\">" "$WORK/response" \
  && grep -q "\"description\":\"$NBSP_TEXT&D\"" "$WORK/response" && ! grep -q '&amp;nbsp;\|&amp;amp;' "$WORK/response" \
  && echo "  ok     news meta, og and JSON-LD description: plain text from the intro" || { echo "  CHYBA  popis novinky s entitami"; grep -o '<meta [^>]*description[^>]*>\|"description":"[^"]*"' "$WORK/response" | head -5; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_novinky SET uvod = UNHEX('${NBSP_BACKUP%%:*}'), seo_popis = UNHEX('${NBSP_BACKUP#*:}') WHERE seo_link = 'vitejte-v-kalete'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "strukturovaná data novinky" 200 /novinky/vitejte-v-kalete '"BlogPosting"'
check "zásady z instalace jsou skryté, dokud je správce nedoplní" 404 /zasady-ochrany-osobnich-udaju
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'zasady-ochrany-osobnich-udaju'"
check "zásady ochrany osobních údajů po zveřejnění" 200 /zasady-ochrany-osobnich-udaju "Jaké údaje zpracováváme"
grep -qF 'spojí s OpenStreetMap (OpenStreetMap Foundation) a pošle mu vaši IP adresu' "$WORK/response" && echo "  ok     3.19 (N3182-7): the privacy template of the installation says when the store locator sends the IP address to OpenStreetMap" || { echo "  CHYBA  3.19 (N3182-7): no OpenStreetMap paragraph in the installation's privacy policy"; ERRORS=$((ERRORS+1)); }
check "patička odkazuje na zásady" 200 /o-nas 'zasady-ochrany-osobnich-udaju'
check "neexistující stránka" 404 /tohle-neexistuje
check "system/ není přístupný" 403 /system/sql/schema.sql
check "config.php není přístupný" 403 /config.php
mkdir -p "$WORK/web/layout/vlastni" && echo '<?php echo "VLASTNI SABLONA";' > "$WORK/web/layout/vlastni/base.php"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni VALUES ('layout','vlastni')"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "themeless: the site uses the built-in frame, never a custom layout" 200 / "image/sablona.css"
grep -q "VLASTNI SABLONA" "$WORK/response" && { echo "  CHYBA  a custom layout was used"; ERRORS=$((ERRORS+1)); } || echo "  ok     a custom layout in layout/ is ignored"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "bez úvodní stránky je úvodem výpis novinek" 200 / "Vítejte v Kaletě"

