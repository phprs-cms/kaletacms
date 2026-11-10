echo "== right-to-left: an Arabic language version (data model §5) – dir, hreflang, Latin names inside Arabic, an Arabic WordPress import"
# a Czech site with an Arabic version, the home page = the news list (hreflang then needs no translated home page); restored at the end
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'en,ar'), ('home_page', '0')" > /dev/null
mcp create_page '{"title":"Kinesio RTL","slug":"kinesio-rtl","content":"<p>Česky RTL</p>","visible":true}' > "$WORK/response"; CSRTL=$(mcp_value id)
mcp create_page "{\"title\":\"شريط كينيزيو\",\"slug\":\"kinesio-rtl-ar\",\"language\":\"ar\",\"translation_of\":$CSRTL,\"content\":\"<p>شريط Kinesio Tape (5 cm) للرياضيين – السعر 120 EUR.</p>\",\"visible\":true}" > "$WORK/response"; ARRTL=$(mcp_value id)
ARP=$(get39 /ar/kinesio-rtl-ar)
contains -q '<html lang="ar" dir="rtl"' "$WORK/response" && contains -q 'شريط Kinesio Tape (5 cm) للرياضيين' "$WORK/response" && contains -q '<meta property="og:locale" content="ar_SA">' "$WORK/response" \
  && contains -q 'hreflang="cs" href="[^"]*/kinesio-rtl"' "$WORK/response" && contains -q 'hreflang="ar" href="[^"]*/ar/kinesio-rtl-ar"' "$WORK/response" \
  && contains -q "$(php -r 'echo (require $argv[1])["Skip to content"];' "$ROOT/system/jazyky/ar.php")" "$WORK/response" && ARP="$ARP ok"
CSP=$(get39 /kinesio-rtl)
contains -q '<html lang="cs"' "$WORK/response" && ! contains -q 'dir="rtl"' "$WORK/response" && contains -q 'hreflang="ar" href="[^"]*/ar/kinesio-rtl-ar"' "$WORK/response" && CSP="$CSP ok"
expect "RTL: /ar/kinesio-rtl-ar is right to left with Arabic texts, its Latin product name and hreflang both ways; the Czech page has no dir" "$ARP|$CSP" "200  ok|200  ok"
get39 /sitemap.xml > /dev/null
expect "RTL: the sitemap lists the Arabic address; ar is a reserved slug" "$(contains -q '/ar/kinesio-rtl-ar</loc>' "$WORK/response" && echo listed)|$(mcp create_page '{"title":"Ar","slug":"ar"}' > "$WORK/response"; contains -q 'isError' "$WORK/response" && echo refused)" "listed|refused"
mcp site_info '{}' > "$WORK/response"; mcp_text
contains -q '"code":"ar","published":true,"direction":"rtl"' "$WORK/text" && contains -q '"direction":"ltr"' "$WORK/text" && echo "  ok     RTL: site_info reports the direction of every language version" || { echo "  CHYBA  RTL: site_info direction"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp translation_status '{}' > "$WORK/response"; mcp_text
contains -q '"directions":{"cs":"ltr","en":"ltr","ar":"rtl"}' "$WORK/text" && echo "  ok     RTL: translation_status reports the directions" || { echo "  CHYBA  RTL: translation_status directions"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the builder of the Arabic page: the canvas is the page itself (dir="rtl"), the editor tells its fields the direction; the admin stays left to right
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$ARRTL"
contains -q '"smer":"rtl"' "$WORK/response" && ! contains -q '<html[^>]*dir="rtl"' "$WORK/response" && echo "  ok     RTL: the builder of an Arabic page gets the direction for its fields, the admin page itself stays left to right" || { echo "  CHYBA  RTL: builder direction"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$ARRTL"
contains -q 'name="titulek" value="شريط كينيزيو" maxlength="200" required placeholder="[^"]*" dir="rtl"' "$WORK/response" && contains -q 'data-editor dir="rtl"' "$WORK/response" && echo "  ok     RTL: the page form writes Arabic fields right to left" || { echo "  CHYBA  RTL: page form direction"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$CSRTL"
! contains -q 'dir="rtl"' "$WORK/response" && echo "  ok     RTL: a Czech page form has no dir attribute" || { echo "  CHYBA  RTL: dir on a Czech form"; ERRORS=$((ERRORS+1)); }
# slugs: an Arabic title is transliterated, a title with nothing to transliterate takes the type and number (never n-a); the
# admin lists isolate typed values
mcp create_page '{"title":"صفحة الخدمات","language":"ar"}' > "$WORK/response"; SLUGAR=$(mcp_value id)
mcp create_page '{"title":"עמוד"}' > "$WORK/response"; SLUGHE=$(mcp_value id)
mcp create_category '{"name":"日本"}' > "$WORK/response"; SLUGCAT=$(mcp_value id)
expect "RTL slugs: an Arabic title transliterated, a Hebrew page and a Japanese category by type and number" \
  "$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $SLUGAR")|$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $SLUGHE")|$(sq "SELECT seo_link FROM ka_kategorie WHERE idt = $SLUGCAT")" \
  "sfha-alkhdmat|page-$SLUGHE|category-$SLUGCAT"
check "RTL: the pages list isolates titles (<bdi>)" 200 "/admin.php?module=pages" '<bdi>صفحة الخدمات</bdi>'
sq "DELETE FROM ka_stranky WHERE ids IN ($CSRTL, $ARRTL, $SLUGAR, $SLUGHE); DELETE FROM ka_kategorie WHERE idt = $SLUGCAT" > /dev/null
# fysiomed.eu's shape: an English site with an Arabic half from Polylang; for this part English is the default language
LANGRTL=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_language'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_language', 'en'), ('additional_languages', '')" > /dev/null
ml_run wordpress-arabic
expect "RTL import: Arabic (Polylang ar) added to the site, nothing left out, the page, post and category linked to their English originals" \
  "$(mcp_value phase)|$(mcp_value languages added_to_site 0)|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")|$(mcp_value languages not_available 0 language)|$(mcp_value languages translations_linked)" \
  "done|ar|ar|null|3"
expect "RTL import: the Arabic page and post are in the Arabic version and point to the English ones" \
  "$(sq "SELECT CONCAT(a.jazyk, '/', a.preklad_z = e.ids) FROM ka_stranky a JOIN ka_stranky e ON e.seo_link = 'kinesio-tape-fxm' WHERE a.seo_link = 'kinesio-tape-ar-fxm'")|$(sq "SELECT CONCAT(a.jazyk, '/', a.preklad_z = e.idc) FROM ka_novinky a JOIN ka_novinky e ON e.seo_link = 'taping-knee-fxm' WHERE a.seo_link = 'taping-knee-ar-fxm'")" \
  "ar/1|ar/1"
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link LIKE 'kinesio-tape-%fxm'" > /dev/null # over MCP everything arrives hidden
IMP=$(get39 /ar/kinesio-tape-ar-fxm)
contains -q '<html lang="ar" dir="rtl"' "$WORK/response" && contains -q 'Kinesio Tape (5 cm × 5 m)' "$WORK/response" && contains -q 'hreflang="en" href="[^"]*/kinesio-tape-fxm"' "$WORK/response" && IMP="$IMP ok"
IMPEN=$(get39 /kinesio-tape-fxm)
contains -q '<html lang="en">' "$WORK/response" && contains -q 'hreflang="ar" href="[^"]*/ar/kinesio-tape-ar-fxm"' "$WORK/response" && IMPEN="$IMPEN ok"
expect "RTL import: the imported Arabic page renders right to left with hreflang to English, and back" "$IMP|$IMPEN" "200  ok|200  ok"
sq "DELETE FROM ka_stranky WHERE seo_link LIKE '%-fxm'; DELETE FROM ka_novinky WHERE seo_link LIKE '%-fxm'; DELETE FROM ka_kategorie WHERE seo_link LIKE 'blog-fxm%'; DELETE FROM ka_presmerovani WHERE z_adresy LIKE '%fxm%'" > /dev/null
# 3.12: fysiomed.eu at a smaller scale – a generated WooCommerce shop (tools/fixtures/wordpress-scale.php, 60 products in
# English and Arabic with Polylang, product categories, featured images, a menu per language), its live side served by the
# fake old site; imported over MCP with the images, then the front, the old addresses and the migration report
SCALE_PORT=$((PORT + 8)); SCALE="http://127.0.0.1:$SCALE_PORT" # the big fake old site of 3.7 is stopped by now
php "$ROOT/tools/fixtures/wordpress-scale.php" --products=60 --site="$SCALE" --out="$WORK/web/storage/import/fysio-scale.xml" # put there as over FTP
(cd "$ROOT/tools" && KALETA_FAKE_MODE=wordpress KALETA_FAKE_WXR="$WORK/web/storage/import/fysio-scale.xml" exec php -S "127.0.0.1:$SCALE_PORT" fake-old-site.php > /dev/null 2>&1) & OLDSITE_PID=$!
sq "DROP TABLE IF EXISTS ka_test_scale; CREATE TABLE ka_test_scale AS SELECT * FROM ka_nastaveni WHERE promenna IN ('look_draft', 'design_system', 'home_page'); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('look_draft', 'additional_languages')" > /dev/null
for _ in $(seq 1 30); do curl -s -o /dev/null "$SCALE/robots.txt" && break; sleep 0.2; done
mcp import_wordpress '{"file":"fysio-scale.xml","confirm":true,"add_languages":true}' > "$WORK/response"; SCALE_CALLS=1
for _ in $(seq 1 25); do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress '{"import":"fysio-scale.xml"}' > "$WORK/response"; SCALE_CALLS=$((SCALE_CALLS + 1)); done
SCOL=$(mcp_value woocommerce collection)
expect "3.12 scale: 60 products ×2 in a few calls – Arabic added, products in a catalogue with 12 categories, every image downloaded, translations linked" \
  "$(mcp_value phase)|$(mcp_value languages added_to_site 0)|$(mcp_value result collection_items)|$(mcp_value result collection_categories)|$(mcp_value woocommerce products)|$(mcp_value images downloaded)|$(mcp_value images failed)|$(mcp_value languages translations_linked)|$([ "$SCALE_CALLS" -le 8 ] && echo few)" \
  "done|ar|119|12|119|60|0|28|few"
expect "3.12 scale: the catalogue keeps the Products preset; an Arabic product shares its English original's address, with its price, code, Arabic availability and picture" \
  "$(sq "SELECT CONCAT(k.preset, '|', a.seo_link = e.seo_link, '|', JSON_UNQUOTE(JSON_EXTRACT(a.data, '$.price')), '|', JSON_UNQUOTE(JSON_EXTRACT(a.data, '$.code')), '|', JSON_UNQUOTE(JSON_EXTRACT(a.data, '$.availability')), '|', JSON_UNQUOTE(JSON_EXTRACT(a.data, '$.image')) LIKE 'media/%', '|', a.obrazek LIKE 'media/%')
     FROM ka_kolekce_polozky e JOIN ka_kolekce_polozky a ON a.idk = e.idk AND a.jazyk = 'ar' AND a.nazev LIKE '%FS-001' JOIN ka_kolekce k ON k.idk = e.idk WHERE e.jazyk = '' AND e.nazev = 'Pro Knee Brace FS-001'")" \
  "products|1|11.90|FS-001|متوفر|1|1"
expect "3.12 scale: an Arabic-only product gets a transliterated Latin address; a category has an Arabic name and a Latin address; both menus have the shop's category" \
  "$(sq "SELECT seo_link FROM ka_kolekce_polozky WHERE jazyk = 'ar' AND nazev LIKE '%FS-040'")|$(sq "SELECT CONCAT(t.name, ':', t.slug) FROM ka_collection_category_texts t JOIN ka_collection_category_texts e ON e.category_id = t.category_id AND e.language = '' AND e.slug = 'taping-fs' WHERE t.language = 'ar'")|$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.menus.\"hlavni|\"[3].url')), ' ', JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.menus.\"hlavni|ar\"[3].url'))) FROM ka_nastaveni WHERE promenna = 'look_draft'")" \
  "kmada-barda-klasyky-fs-040|الأشرطة:alashrta|/$SCOL/taping-fs /ar/$SCOL/alashrta"
# 3.12: everything arrived hidden, the categories too; publishing an item shows its hidden category and that category's
# parent – over MCP, in the admin's bulk action and by a scheduled date – while categories without a published item stay hidden
SCIDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$SCOL'")
SC_CAT() { sq "SELECT GROUP_CONCAT(CONCAT(t.slug, '=', c.visible) ORDER BY t.slug) FROM ka_collection_categories c JOIN ka_collection_category_texts t ON t.category_id = c.id AND t.language = '' WHERE c.idk = $SCIDK AND t.slug IN ($1)"; }
SC_ITEM() { sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $SCIDK AND jazyk = '' AND nazev = '$1'"; }
SC_LOG=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'category_shown'")
mcp save_collection_item "{\"collection\":\"$SCOL\",\"id\":$(SC_ITEM 'Pro Knee Brace FS-001'),\"visible\":true}" > "$WORK/response"; SC_MCP=$(mcp_value categories_shown)
SC_A=$(SC_CAT "'supports-and-braces-fs', 'knee-supports-fs', 'back-supports-fs', 'taping-fs'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$SCIDK"; TOKEN=$(csrf)
curl -s -L -b "$JAR" -c "$JAR" -o "$WORK/bulk.html" -X POST "$B/admin.php?module=collections&action=bulk_items" -d "_csrf=$TOKEN" -d "idk=$SCIDK" -d provest=zobrazit -d "oznacene[]=$(SC_ITEM 'Pro Back Support Belt FS-003')"
SC_B=$(SC_CAT "'clinic-equipment-fs', 'tables-fs'")
sq "UPDATE ka_kolekce_polozky SET zverejnit_od = '$(site_time '-1 minute')' WHERE idp = $(SC_ITEM 'Sport Kinesio Tape FS-016'); UPDATE ka_jobs SET last_run = NULL WHERE name = 'notifications'" > /dev/null
curl -s -o /dev/null "$B/tasks?token=testtoken123"
expect "3.12: a published product shows its hidden category and the parent (MCP, admin bulk, scheduled), empty ones stay hidden, each logged" \
  "$SC_A|$SC_B|$(SC_CAT "'taping-fs', 'kinesiology-tape-fs', 'rigid-tape-fs'")|$(( $(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'category_shown'") - SC_LOG ))" \
  "back-supports-fs=0,knee-supports-fs=1,supports-and-braces-fs=1,taping-fs=0|clinic-equipment-fs=1,tables-fs=1|kinesiology-tape-fs=1,rigid-tape-fs=0,taping-fs=1|3"
expect "3.12 N312-3: the publisher is told which categories went visible – MCP categories_shown, the admin's bulk message" \
  "$SC_MCP|$(grep -c 'Clinic equipment, Tables' "$WORK/bulk.html")" '["Supports and braces","Knee supports"]|1'
# N312-3: a category a drafts-only connection wrote stays hidden when a person publishes its first item; the person is told
mcp_as "$DRAFT_TOKEN" save_collection_category "{\"collection\":\"$SCOL\",\"name\":\"Secret promo\",\"slug\":\"secret-promo-fs\",\"description\":\"<p>Unreviewed text</p>\"}" > "$WORK/response"; SC_PROMISE=$(mcp_value next)
mcp_as "$DRAFT_TOKEN" save_collection_item "{\"collection\":\"$SCOL\",\"name\":\"Draft gadget FS\",\"categories\":[\"secret-promo-fs\"]}" > "$WORK/response"; SC_DRAFT=$(mcp_value id)
mcp save_collection_item "{\"collection\":\"$SCOL\",\"id\":$SC_DRAFT,\"visible\":true}" > "$WORK/response"
expect "3.12 N312-3: a drafts-only connection's category stays hidden when its first item is published, the publisher is told it waits for review, and the promise says so" \
  "$(SC_CAT "'secret-promo-fs'")|$(mcp_value categories_waiting_review)|$(get39 "/$SCOL/secret-promo-fs" | cut -c1-3)|$(printf '%s' "$SC_PROMISE" | grep -q 'also when one of its items is published' && echo promised)" \
  'secret-promo-fs=0|["Secret promo"]|404|promised'
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=categories&id=$SCIDK"; TOKEN=$(csrf)
SC_PROMO=$(sq "SELECT category_id FROM ka_collection_category_texts WHERE slug = 'secret-promo-fs'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_category" -d "_csrf=$TOKEN" -d "idk=$SCIDK" -d "id=$SC_PROMO" -d name='Secret promo' -d slug=secret-promo-fs -d description='<p>Reviewed</p>' -d sort_order=100
expect "3.12 N312-3: once a person has saved the category, it is no longer waiting for review" "$(sq "SELECT FIND_IN_SET('$SC_PROMO', hodnota) FROM ka_nastaveni WHERE promenna = 'categories_pending_review'")" "0"
# the rest is shown by hand here, the imported home page as the home page (a language version is public once its
# translation of the home page is)
sq "UPDATE ka_kolekce_polozky SET zobrazit = 1 WHERE seo_link LIKE '%-fs-%'; UPDATE ka_collection_categories c JOIN ka_kolekce k ON k.idk = c.idk SET c.visible = 1 WHERE k.seo_link = '$SCOL';
    UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link LIKE '%-fs' OR jazyk = 'ar'; UPDATE ka_nastaveni SET hodnota = (SELECT ids FROM ka_stranky WHERE seo_link = 'home-fs') WHERE promenna = 'home_page'" > /dev/null
SC1=$(get39 "/$SCOL/pro-knee-brace-fs-001"); contains -q "hreflang=\"ar\" href=\"[^\"]*/ar/$SCOL/pro-knee-brace-fs-001\"" "$WORK/response" && SC1="$SC1 ok"
SC2=$(get39 "/ar/$SCOL/pro-knee-brace-fs-001"); contains -q '<html lang="ar" dir="rtl"' "$WORK/response" && contains -q "hreflang=\"en\" href=\"[^\"]*/$SCOL/pro-knee-brace-fs-001\"" "$WORK/response" && contains -q 'متوفر' "$WORK/response" && SC2="$SC2 ok"
expect "3.12 scale: the English and the Arabic product page, linked by hreflang both ways" "$SC1|$SC2" "200  ok|200  ok"
SC_AR=$(php -r 'echo implode("-", array_map(fn ($w) => strtolower(rawurlencode($w)), ["دعامة", "الركبة", "احترافي", "fs-001"]));')
expect "3.12 scale: old addresses redirect – an Arabic product, ?lang=ar, a product category and an Arabic subcategory under WooCommerce's base" \
  "$(get39 "/ar/product/$SC_AR/")|$(get39 '/?lang=ar')|$(get39 /product-category/taping-fs/)|$(get39 "/ar/product-category/$(php -r 'echo strtolower(rawurlencode("الأشرطة")) . "/" . strtolower(rawurlencode("شريط-الحركة"));')/")" \
  "301 $B/ar/$SCOL/pro-knee-brace-fs-001|301 $B/ar|301 $B/$SCOL/taping-fs|301 $B/ar/$SCOL/alashrta/shryt-alhrka"
SC3=$(get39 "/ar/$SCOL/alashrta"); contains -q 'dir="rtl"' "$WORK/response" && contains -q "href=\"/ar/$SCOL/pro-[a-z-]*fs-0" "$WORK/response" && SC3="$SC3 ok"
curl -s -o "$WORK/response" -G "$B/ar/search" --data-urlencode "q=دعامه الركبه"; SC4=$(grep -o "href=\"/ar/$SCOL/[a-z0-9-]*\"" "$WORK/response" | sort -u | wc -l | tr -d ' ')
# 3.12: the news index of an Arabic post made before (ة kept) is emptied by migration 0089 and filled again before a search
SC_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE jazyk = 'ar' AND titulek = 'نصيحة التمرين رقم 1'")
sq "UPDATE ka_novinky SET visible = 1, datum = '$(site_time '-1 day')', hledani = 'نصيحة التمرين رقم 1' WHERE idc = $SC_NEWS; UPDATE ka_nastaveni SET hodnota = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', hodnota, ','), ',0089-arabic-search-index,', ',')) WHERE promenna = 'data_migrations'" > /dev/null
curl -s -b "$JAR" -o /dev/null "$B/admin.php"
SC_INDEX=$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_novinky WHERE idc = $SC_NEWS")
curl -s -o "$WORK/response" -G "$B/ar/search" --data-urlencode "q=نصيحه"
expect "3.12: migration 0089 empties an old Arabic news index, the next search fills it with ة as ه and finds the post" \
  "$SC_INDEX|$(sq "SELECT hledani LIKE '%نصيحه التمرين%' FROM ka_novinky WHERE idc = $SC_NEWS")|$(grep -c 'نصيحة التمرين رقم 1' "$WORK/response")" "NULL|1|1"
get39 /sitemap.xml > /dev/null
expect "3.12 scale: the Arabic category page lists its products, Arabic search finds the products, the sitemap has both languages' products and categories" \
  "$SC3|$([ "$SC4" -ge 3 ] && echo found)|$(grep -c "<loc>[^<]*/ar/$SCOL/pro-knee-brace-fs-001</loc>" "$WORK/response")|$(grep -c "<loc>[^<]*/$SCOL/taping-fs/kinesiology-tape-fs</loc>" "$WORK/response")|$(grep -c "<loc>[^<]*/ar/$SCOL/alashrta</loc>" "$WORK/response")" \
  "200  ok|found|1|1|1"
mcp migration_report "{\"url\":\"$SCALE\"}" > "$WORK/response"; SC_REPORT=$(mcp_value report_id)
for _ in 1 2 3 4 5 6; do [ "$(mcp_value phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$SC_REPORT\"}" > "$WORK/response"; done
expect "3.12 scale: the migration report finds every old address of the shop (products, pages, posts, categories in both languages) here" \
  "$(mcp_value phase)|$(mcp_value summary addresses)|$(mcp_value summary missing)|$(mcp_value summary errors)" "done|194|0|0"
# the fonts: an Arabic version with a heading font without Arabic letters is reported in Site appearance and the site audit
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('design_system', '{\"pismo_titulky\":\"elegantni\",\"pismo_text\":\"moderni\"}'), ('look_draft', '')" > /dev/null
check "3.12: Site appearance warns that the heading font has no Arabic letters and names fonts that have them" 200 "/admin.php?module=appearance" 'Arabic sans-serif\|Arabské bezpatkové'
contains -q 'Noto Sans Arabic, IBM Plex Sans Arabic, Cairo, Tajawal' "$WORK/response" && echo "  ok     3.12: the warning suggests Arabic fonts to upload" || { echo "  CHYBA  3.12: the font warning"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"accessibility"}' > "$WORK/response"; mcp_text; FA1=$(contains -q '"look":"font_headings","script":"arabic"' "$WORK/text" && echo reported)
sq "UPDATE ka_nastaveni SET hodnota = '{\"pismo_titulky\":\"arabske\",\"pismo_text\":\"moderni\"}' WHERE promenna = 'design_system'" > /dev/null
mcp site_audit '{"kind":"accessibility"}' > "$WORK/response"; mcp_text; FA2=$(contains -q '"look":"font_' "$WORK/text" && echo still || echo gone)
expect "3.12: site_audit reports the heading font without Arabic letters, and not once the Arabic font is chosen" "$FA1|$FA2" "reported|gone"
# the WPML variant of the same shop over the admin's preview
php "$ROOT/tools/fixtures/wordpress-scale.php" --products=60 --plugin=wpml --site="$SCALE" --out="$WORK/web/storage/import/fysio-scale-wpml.xml"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=fysio-scale-wpml.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=progress" -d "_csrf=$TOKEN" -d soubor=fysio-scale-wpml.xml
check "3.12: the admin preview of a WPML WooCommerce shop names the catalogue and its categories" 200 "/admin.php?module=transfer&action=preview&file=fysio-scale-wpml.xml" 'WooCommerce'
contains -q '119' "$WORK/response" && contains -q 'WPML' "$WORK/response" && echo "  ok     3.12: 119 products and WPML in the preview" || { echo "  CHYBA  3.12: WPML preview"; ERRORS=$((ERRORS+1)); }
kill "$OLDSITE_PID" 2>/dev/null; OLDSITE_PID=
# deleting the imported catalogue: one foreign key cascade over it (categories, subcategories, item links) is more than
# MySQL allows (error 6575) – Collections::delete goes step by step, and nothing of the collection is left
SCIDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$SCOL'")
mcp delete_collection "{\"collection\":\"$SCOL\"}" > "$WORK/response"
expect "3.12: delete_collection removes the imported catalogue with its 119 products and 12 categories (no cascade error)" \
  "$(mcp_value deleted)|$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce WHERE idk = $SCIDK), (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idk = $SCIDK), (SELECT COUNT(*) FROM ka_collection_categories WHERE idk = $SCIDK), (SELECT COUNT(*) FROM ka_collection_category_texts WHERE idk = $SCIDK))")" "$SCOL|0000"
# N312-4, N312-5, N312-6: an export with a product category slug of invalid UTF-8 (%ff%fe), a "*" slug and a link that
# would make "*" the category base, and a parent cycle (cyc-a under cyc-b under cyc-a) – the import keeps its state,
# writes no pattern rule and creates each category once, the cycle named in the report
cat > "$WORK/web/storage/import/hostile-312.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel><title>Hostile</title><link>https://www.hostile312.example</link><wp:base_site_url>https://www.hostile312.example</wp:base_site_url>
<wp:term><wp:term_id>11</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>%ff%fe</wp:term_slug><wp:term_parent></wp:term_parent><wp:term_name><![CDATA[Odd]]></wp:term_name></wp:term>
<wp:term><wp:term_id>12</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>%2a</wp:term_slug><wp:term_parent></wp:term_parent><wp:term_name><![CDATA[Star]]></wp:term_name></wp:term>
<wp:term><wp:term_id>13</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>cyc-a</wp:term_slug><wp:term_parent>cyc-b</wp:term_parent><wp:term_name><![CDATA[Cycle A]]></wp:term_name></wp:term>
<wp:term><wp:term_id>14</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>cyc-b</wp:term_slug><wp:term_parent>cyc-a</wp:term_parent><wp:term_name><![CDATA[Cycle B]]></wp:term_name></wp:term>
<item><title>Hostile one</title><link>https://www.hostile312.example/product/hostile-one/</link><content:encoded><![CDATA[<p>See <a href="https://www.hostile312.example/*/cyc-a/">x</a> and <a href="https://www.hostile312.example/*/cyc-b/">y</a></p>]]></content:encoded><wp:post_id>501</wp:post_id><wp:post_name>hostile-one</wp:post_name><wp:status>publish</wp:status><wp:post_type>product</wp:post_type><category domain="product_cat" nicename="%ff%fe">Odd</category><category domain="product_cat" nicename="%2a">Star</category><category domain="product_type" nicename="simple">simple</category><wp:postmeta><wp:meta_key>_sku</wp:meta_key><wp:meta_value>H1</wp:meta_value></wp:postmeta></item>
<item><title>Hostile two</title><link>https://www.hostile312.example/product/hostile-two/</link><content:encoded><![CDATA[<p>Two</p>]]></content:encoded><wp:post_id>502</wp:post_id><wp:post_name>hostile-two</wp:post_name><wp:status>publish</wp:status><wp:post_type>product</wp:post_type><category domain="product_cat" nicename="cyc-a">Cycle A</category><category domain="product_cat" nicename="%ff%fe">Odd</category><category domain="product_type" nicename="simple">simple</category><wp:postmeta><wp:meta_key>_sku</wp:meta_key><wp:meta_value>H2</wp:meta_value></wp:postmeta></item>
</channel>
</rss>
XML
HOSTILE_STARS=$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%*%'")
mcp import_wordpress '{"file":"hostile-312.xml","confirm":true,"images":false}' > "$WORK/response"
for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress '{"import":"hostile-312.xml"}' > "$WORK/response"; done
HCOL=$(mcp_value woocommerce collection); HIDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$HCOL'")
expect "3.12 N312-4/5/6: an odd slug keeps the import state, no \"*\" rule is written, the cycle gives each category once and is reported" \
  "$(mcp_value phase)|$(mcp_value result collection_items)|$([ -s "$WORK"/web/storage/import/stav-"$(php -r 'echo substr(sha1("hostile-312.xml"), 0, 16);')".json ] && echo kept)|$(( $(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%*%'") - HOSTILE_STARS ))|$(sq "SELECT COUNT(*) FROM ka_collection_category_texts WHERE idk = $HIDK AND name LIKE 'Cycle %'")|$(mcp_value woocommerce category_cycles_broken 0)" \
  "done|2|kept|0|2|cyc-b → cyc-a"
mcp delete_collection "{\"collection\":\"$HCOL\"}" > /dev/null
sq "DELETE FROM ka_presmerovani WHERE na_adresu LIKE '$HCOL/%'; DELETE FROM ka_import_mapa WHERE zdroj = 'wp:hostile312.example'" > /dev/null
sq "DELETE FROM ka_presmerovani WHERE na_adresu LIKE '%-fs%' OR na_adresu LIKE 'ar%' OR na_adresu LIKE '$SCOL/%' OR z_adresy = '?lang=ar';
    DELETE FROM ka_stranky WHERE ids IN (SELECT nase_id FROM ka_import_mapa WHERE zdroj = 'wp:127.0.0.1' AND typ = 'stranka'); DELETE FROM ka_novinky WHERE idc IN (SELECT nase_id FROM ka_import_mapa WHERE zdroj = 'wp:127.0.0.1' AND typ = 'clanek');
    DELETE FROM ka_kategorie WHERE idt IN (SELECT nase_id FROM ka_import_mapa WHERE zdroj = 'wp:127.0.0.1' AND typ = 'rubrika'); DELETE FROM ka_import_mapa WHERE zdroj = 'wp:127.0.0.1'; DELETE FROM ka_nastaveni WHERE promenna IN ('look_draft', 'design_system', 'home_page'); INSERT INTO ka_nastaveni SELECT * FROM ka_test_scale; DROP TABLE ka_test_scale" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_language', '$LANGRTL'), ('additional_languages', 'en'), ('home_page', '$HOME39')" > /dev/null

