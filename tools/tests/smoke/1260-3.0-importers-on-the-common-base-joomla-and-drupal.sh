echo "== 3.0: importers on the common base – Joomla and Drupal through the fetch step (Import\\Fetch), Webflow CSV"
FAKE="http://127.0.0.1:$FAKE_PORT"; rm -f "$FAKE_LOGS-joomla.log" "$FAKE_LOGS-drupal.log"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
contains -q 'action=source_fetch' "$WORK/response" && contains -q 'name="system" value="joomla"' "$WORK/response" && contains -q 'name="system" value="drupal"' "$WORK/response" && contains -q 'option value="webflow">Webflow' "$WORK/response" && ! contains -q 'option value="joomla"' "$WORK/response" \
  && echo "  ok     import and export: Joomla and Drupal have a fetch form (address, token, steps), Webflow is a file upload" || { echo "  CHYBA  the From another system section"; ERRORS=$((ERRORS+1)); }
# an internal address is refused before anything is requested
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=joomla -d adresa=http://10.0.0.5 -d token=whatever
check "fetch: an internal address is refused" 200 "/admin.php?module=transfer" "not an internal address\|ne vnitřní adresu"
JOOMLA_FILE=joomla-127-0-0-1.json
# Joomla with a wrong token: the fake answers 401, the page shows it clearly, the token is forgotten
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=joomla -d "adresa=$FAKE" -d token=wrong-token-value -d 'kroky[]=categories' -d 'kroky[]=users' -d 'kroky[]=tags'
src_batch "$JOOMLA_FILE"
contains -q 'refused the request.*401\|odmítl.*401' "$WORK/response" && echo "  ok     Joomla: a wrong token is a clear error with the 401" || { echo "  CHYBA  Joomla wrong token"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -rq 'wrong-token-value' "$WORK/web/storage" && { echo "  CHYBA  Joomla: the wrong token was written under storage/"; ERRORS=$((ERRORS+1)); } || echo "  ok     Joomla: the refused token is nowhere under storage/"
# the right token: 6 pages (articles 3, categories, users, tags) in two requests, then the analysis, then the preview
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=joomla -d "adresa=$FAKE" -d token=jm-secret-token -d 'kroky[]=categories' -d 'kroky[]=users' -d 'kroky[]=tags'
src_batch "$JOOMLA_FILE"
contains -q 'Fetching from\|Stahuji z' "$WORK/response" && echo "  ok     Joomla: the fetch uses its page budget and continues in the next request" || { echo "  CHYBA  Joomla fetch progress"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
for i in 1 2 3 4; do src_batch "$JOOMLA_FILE"; done
# 8 calls: the refused attempt, articles paged 0/2/4, categories paged 0/2, users, tags – every one carried a token header
expect "Joomla: the fake saw a token header on every call and was paged through articles with offsets 0, 2, 4 (the first 0 is the refused attempt)" "$(grep -c '"has_token":true' "$FAKE_LOGS-joomla.log")|$(grep -c '"has_token":false' "$FAKE_LOGS-joomla.log")|$(grep 'content/articles' "$FAKE_LOGS-joomla.log" | grep -o '"offset":[0-9]*' | tr '\n' ' ' | sed 's/ $//')|$(grep -c 'content/categories' "$FAKE_LOGS-joomla.log")" "8|0|\"offset\":0 \"offset\":0 \"offset\":2 \"offset\":4|2"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=$JOOMLA_FILE"
contains -q 'SEF URL\|SEF adres' "$WORK/response" && contains -q 'Hello from the bakery' "$WORK/response" && contains -q 'Marta Editor' "$WORK/response" && ! contains -q 'name="site_url"' "$WORK/response" && ! contains -q 'jm-secret-token' "$WORK/response" \
  && echo "  ok     Joomla: the preview warns about SEF addresses, shows the first titles and the authors, knows the site address, never the token" || { echo "  CHYBA  Joomla preview"; ERRORS=$((ERRORS+1)); }
joomla_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d "soubor=$JOOMLA_FILE" -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0; src_batch "$JOOMLA_FILE"; }
joomla_run
expect "Joomla: published, unpublished (hidden), scheduled and archived articles as news items with their categories, tags and metadesc; the trashed one is not imported" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', k.nazev, ':', IFNULL((SELECT GROUP_CONCAT(s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.seo_popis) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n JOIN ka_kategorie k ON k.idt = n.tema WHERE n.seo_link IN ('hello-from-the-bakery', 'unpublished-recipe', 'trashed-note', 'summer-market', 'archived-thoughts')")" \
  "hello-from-the-bakery:1:2024-03-10:News:Sourdough:Opening day of the bakery.|unpublished-recipe:0:2024-04-02:News:-:|summer-market:1:2099-06-01:Blog:Events:|archived-thoughts:1:2023-01-05:Uncategorised:-:"
expect "Joomla: introtext is the intro, fulltext the text, the script is cleaned out" "$(sq "SELECT CONCAT(uvod LIKE '%opened the oven%', ':', text LIKE '%opened the oven%', ':', text LIKE '%first loaves%', ':', text LIKE '%podvrh%') FROM ka_novinky WHERE seo_link = 'hello-from-the-bakery'")" "1:0:1:0"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/blog/news/12-hello-from-the-bakery"); expect "Joomla: the best-guess old address /category-path/id-alias redirects to the news item" "$code" "301 $B/novinky/hello-from-the-bakery"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d "soubor=$JOOMLA_FILE"
for i in $(seq 1 10); do src_batch "$JOOMLA_FILE"; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Joomla: the featured image (images/… made absolute) and the image in the text are in Media" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%', ':', text LIKE '%<img src=\"http://127.0.0.1%') FROM ka_novinky WHERE seo_link = 'hello-from-the-bakery'")" "1:1:0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d "soubor=$JOOMLA_FILE"
src_batch "$JOOMLA_FILE"; joomla_run
expect "Joomla: a second import of the fetched file adds nothing" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'hello-from-the-bakery%' OR seo_link LIKE 'summer-market%' OR seo_link LIKE 'unpublished-recipe%' OR seo_link LIKE 'archived-thoughts%'), '/', (SELECT COUNT(*) FROM ka_kategorie WHERE nazev IN ('News', 'Blog')))")" "4/2"
grep -q 'dlazdice-polozka"><strong>4</strong><span>Skipped\|<strong>4</strong><span>Přeskočeno' "$WORK/response" && echo "  ok     Joomla: the result shows 4 skipped" || { echo "  CHYBA  Joomla: skipped count"; ERRORS=$((ERRORS+1)); }
# the token never lands anywhere: settings, the change log, files under storage/ (the fetched file and the state), the pages shown
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_progress&file=$JOOMLA_FILE"
expect "Joomla: the token is in neither ka_nastaveni nor ka_protokol" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%jm-secret-token%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE popis LIKE '%jm-secret-token%' OR akce LIKE '%jm-secret-token%' OR duvod LIKE '%jm-secret-token%'))")" "0|0"
grep -rq 'jm-secret-token' "$WORK/web/storage" "$WORK/response" && { echo "  CHYBA  Joomla: the token is in a file under storage/ or on a page"; grep -rl 'jm-secret-token' "$WORK/web/storage" "$WORK/response"; ERRORS=$((ERRORS+1)); } || echo "  ok     Joomla: the token is in no file under storage/ and on no page"
# Drupal: wrong credentials → 401; signed in, the unpublished node comes too; the tags endpoint is missing and skipped
DRUPAL_FILE=drupal-127-0-0-1.json
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=drupal -d "adresa=$FAKE/" -d token=drupal:wrong-pass -d 'kroky[]=pages' -d 'kroky[]=tags'
src_batch "$DRUPAL_FILE"
contains -q 'refused the request.*401\|odmítl.*401' "$WORK/response" && echo "  ok     Drupal: wrong credentials are a clear error with the 401" || { echo "  CHYBA  Drupal wrong credentials"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_fetch" -d "_csrf=$TOKEN" -d system=drupal -d "adresa=$FAKE" -d token=drupal:dr-pass -d 'kroky[]=pages' -d 'kroky[]=tags'
for i in 1 2 3; do src_batch "$DRUPAL_FILE"; done
expect "Drupal: the fake saw the right Basic auth on the 4 calls after the refused one, articles paged with offsets 0 and 2, the tags endpoint asked once" "$(grep -c '"signed_in":true' "$FAKE_LOGS-drupal.log")|$(grep -c '"signed_in":false' "$FAKE_LOGS-drupal.log")|$(grep 'node/article' "$FAKE_LOGS-drupal.log" | grep -o '"offset":[0-9]*' | tr '\n' ' ' | sed 's/ $//')|$(grep -c 'taxonomy_term/tags' "$FAKE_LOGS-drupal.log")" "4|1|\"offset\":0 \"offset\":0 \"offset\":2|1"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=$DRUPAL_FILE"
contains -q 'does not offer tags\|nenabízí tags' "$WORK/response" && contains -q 'Hello from Drupal' "$WORK/response" && contains -q 'About the bakery' "$WORK/response" && ! contains -q 'dr-pass' "$WORK/response" \
  && echo "  ok     Drupal: the preview notes the skipped tags step, shows the article and the page titles, never the credentials" || { echo "  CHYBA  Drupal preview"; ERRORS=$((ERRORS+1)); }
drupal_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d "soubor=$DRUPAL_FILE" -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0; src_batch "$DRUPAL_FILE"; }
drupal_run
expect "Drupal: articles as news items with tags from the included terms, the metatag description, the unpublished one hidden" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', IFNULL((SELECT GROUP_CONCAT(s.nazev ORDER BY s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.seo_popis) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n WHERE n.seo_link IN ('hello-from-drupal', 'second-post', 'draft-post')")" \
  "hello-from-drupal:1:2024-03-10:Sourdough:Welcome text for the search engines.|second-post:1:2024-04-01:Events,Sourdough:|draft-post:0:2024-05-01:-:"
expect "Drupal: the basic page is a published build outside the menu; the script is cleaned out of the article" "$(sq "SELECT CONCAT((SELECT CONCAT(zobrazit, ':', v_menu, ':', stavba IS NOT NULL) FROM ka_stranky WHERE seo_link = 'about'), ':', (SELECT text LIKE '%podvrh%' FROM ka_novinky WHERE seo_link = 'hello-from-drupal'))")" "1:0:1:0"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/blog/hello-from-drupal"); expect "Drupal: the path alias redirects to the news item" "$code" "301 $B/novinky/hello-from-drupal"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about"); expect "Drupal: the page keeps its alias as the new address" "$code" "200 "
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d "soubor=$DRUPAL_FILE"
for i in $(seq 1 10); do src_batch "$DRUPAL_FILE"; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Drupal: the field_image file and the image in the body are in Media" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%') FROM ka_novinky WHERE seo_link = 'hello-from-drupal'")" "1:1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d "soubor=$DRUPAL_FILE"
src_batch "$DRUPAL_FILE"; drupal_run
expect "Drupal: a second import adds nothing" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'hello-from-drupal%' OR seo_link LIKE 'second-post%' OR seo_link LIKE 'draft-post%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('about', 'about-2')))")" "3/1"
grep -rq 'dr-pass' "$WORK/web/storage" && { echo "  CHYBA  Drupal: the credentials are in a file under storage/"; ERRORS=$((ERRORS+1)); } || echo "  ok     Drupal: the credentials are in no file under storage/"
# Webflow: the CSV of a collection; the admin enters the collection's address with its folder for the old addresses
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_upload" -F "_csrf=$TOKEN" -F system=webflow -F "soubor=@$ROOT/tools/fixtures/webflow-blog.csv"
src_batch webflow-webflow-blog.csv
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=webflow-webflow-blog.csv"
contains -q 'name="site_url"' "$WORK/response" && contains -q 'From a live website\|Z běžícího webu' "$WORK/response" && contains -q 'Spring sourdough' "$WORK/response" && echo "  ok     Webflow: the preview asks for the collection address, points static pages to the URL importer, shows the first titles" || { echo "  CHYBA  Webflow preview"; ERRORS=$((ERRORS+1)); }
webflow_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d soubor=webflow-webflow-blog.csv -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0 -d "site_url=$B/blog/"; src_batch webflow-webflow-blog.csv; }
webflow_run
expect "Webflow: the rows as news items – the summary as the intro, the category and the tags from the reference slugs, the draft and the archived one hidden" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', k.nazev, ':', IFNULL((SELECT GROUP_CONCAT(s.nazev ORDER BY s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.uvod LIKE '%spring recipe%', ':', n.text LIKE '%podvrh%') ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n JOIN ka_kategorie k ON k.idt = n.tema WHERE n.seo_link IN ('spring-sourdough', 'market-day', 'old-news')")" \
  "spring-sourdough:1:2024-03-05:Recipes:Sourdough,Spring:1:0|market-day:0:2024-04-01:Events:Events:0:0|old-news:0:2024-01-10:Recipes:-:0:0"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/blog/spring-sourdough"); expect "Webflow: the old address under the collection folder redirects to the news item" "$code" "301 $B/novinky/spring-sourdough"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d soubor=webflow-webflow-blog.csv
src_batch webflow-webflow-blog.csv; webflow_run
expect "Webflow: a second import adds nothing" "$(sq "SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'spring-sourdough%' OR seo_link LIKE 'market-day%' OR seo_link LIKE 'old-news%'")" "3"
expect "the three imports are recorded in ka_import_mapa under their own source labels" "$(sq "SELECT GROUP_CONCAT(DISTINCT zdroj ORDER BY zdroj) FROM ka_import_mapa WHERE zdroj LIKE 'joomla:%' OR zdroj LIKE 'drupal:%' OR zdroj LIKE 'webflow:%'")" "drupal:127.0.0.1,joomla:127.0.0.1,webflow:127.0.0.1"
check "the fetched file is not accessible from the web" 403 "/storage/import/sources/$JOOMLA_FILE"

