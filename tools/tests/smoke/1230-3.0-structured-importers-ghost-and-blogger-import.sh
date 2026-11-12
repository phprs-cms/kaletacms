echo "== 3.0: structured importers – Ghost and Blogger (Import\\Batch)"
# a small "old site" that only serves the images the fixtures point at; the fixtures name it as 127.0.0.1:65000
SRC_PORT=$((PORT + 16)); SRC="http://127.0.0.1:$SRC_PORT"
mkdir -p "$WORK/sources/img" "$WORK/sources/s1600"
php -r '$i = imagecreatetruecolor(320, 200); imagefill($i, 0, 0, imagecolorallocate($i, 120, 60, 30)); imagepng($i, $argv[1]); copy($argv[1], $argv[2]);' -- "$WORK/sources/img/team.png" "$WORK/sources/s1600/team.png"
(cd "$WORK/sources" && exec php -S "127.0.0.1:$SRC_PORT" > /dev/null 2>&1) & SRC_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$SRC/img/team.png" && break; sleep 0.3; done
sed "s|http://127.0.0.1:65000|$SRC|g" "$ROOT/tools/fixtures/blogger-export.xml" > "$WORK/blogger-export.xml"
TOKEN=$(csrf)
check "import and export: the section for other systems lists Ghost and Blogger" 200 "/admin.php?module=transfer" 'option value="blogger">Blogger'
src_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=source_progress&file=$1" -d "_csrf=$TOKEN"; }
# Ghost: the export does not carry the site address – the admin enters it in the preview; the primary tag becomes the category
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_upload" -F "_csrf=$TOKEN" -F system=ghost -F "soubor=@$ROOT/tools/fixtures/ghost-export.json"
src_batch ghost-ghost-export.json
check "Ghost: the preview counts posts, pages and tags and says that routes.yaml is not read" 200 "/admin.php?module=transfer&action=source_preview&file=ghost-ghost-export.json" "routes.yaml"
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=ghost-ghost-export.json"
contains -q 'name="site_url"' "$WORK/response" && contains -q 'Firing the first kiln' "$WORK/response" && contains -q 'bookmark 1' "$WORK/response" && echo "  ok     Ghost: the preview asks for the site address, shows the first titles and the unsupported card" || { echo "  CHYBA  Ghost preview"; ERRORS=$((ERRORS+1)); }
ghost_run() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d soubor=ghost-ghost-export.json -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0 -d "site_url=$SRC"; src_batch ghost-ghost-export.json; }
ghost_run
grep -q "The content import is finished\|Import obsahu je hotový" "$WORK/response" && echo "  ok     Ghost: the import finished in one batch" || { echo "  CHYBA  Ghost import did not finish"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "Ghost: posts as news items with status and date, the primary tag as the category, the other tag as a tag, SEO fields" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', k.nazev, ':', IFNULL((SELECT GROUP_CONCAT(s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-'), ':', n.seo_titulek) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n JOIN ka_kategorie k ON k.idt = n.tema WHERE n.seo_link IN ('firing-the-first-kiln', 'glaze-recipes-we-keep', 'spring-market')")" \
  "firing-the-first-kiln:1:2024-03-10:Workshop:Glazes:Firing the first kiln – Clay Notes|glaze-recipes-we-keep:0:$(site_date today):Glazes:-:|spring-market:1:2099-05-01:Nezařazené:-:"
expect "Ghost: the page is a published build outside the menu with the excerpt as its description" "$(sq "SELECT CONCAT(zobrazit, ':', v_menu, ':', stavba IS NOT NULL, ':', popis) FROM ka_stranky WHERE seo_link = 'about-the-workshop'")" "1:0:1:Who we are and when we are open."
curl -s -o "$WORK/response" "$B/novinky/firing-the-first-kiln"; grep -q "podvrh" "$WORK/response" && { echo "  CHYBA  Ghost: the script from the html card got through"; ERRORS=$((ERRORS+1)); } || echo "  ok     Ghost: the html card's script is cleaned out"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/firing-the-first-kiln/"); expect "Ghost: the old address /slug/ redirects to the news item" "$code" "301 $B/novinky/firing-the-first-kiln"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/about-the-workshop"); expect "Ghost: the old page address is the new one (no redirect needed)" "$code" "200 "
# images: the featured image and the image in the text come from the entered site address
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d soubor=ghost-ghost-export.json
for i in $(seq 1 10); do src_batch ghost-ghost-export.json; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Ghost: the featured image is in Media and the text refers to the copy there (the link to another old post stays – the redirect catches it)" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%', ':', text LIKE '%<img src=\"http://127.0.0.1%', ':', text LIKE '%<a href=\"http://127.0.0.1%') FROM ka_novinky WHERE seo_link = 'firing-the-first-kiln'")" "1:1:0:1"
# a second run of the same file adds nothing
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_select" -d "_csrf=$TOKEN" -d soubor=ghost-ghost-export.json
src_batch ghost-ghost-export.json; ghost_run
expect "Ghost: a second import skips everything" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'firing-the-first-kiln%' OR seo_link LIKE 'glaze-recipes%' OR seo_link LIKE 'spring-market%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'about-the-workshop%'), '/', (SELECT COUNT(*) FROM ka_kategorie WHERE nazev = 'Workshop'))")" "3/1/1"
grep -q 'dlazdice-polozka"><strong>4</strong><span>Skipped\|<strong>4</strong><span>Přeskočeno' "$WORK/response" && echo "  ok     Ghost: the result shows 4 skipped" || { echo "  CHYBA  Ghost: skipped count"; ERRORS=$((ERRORS+1)); }
# Blogger: the Atom export names the blog's address; labels → tags, the comment is skipped, the draft hidden
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_upload" -F "_csrf=$TOKEN" -F system=blogger -F "soubor=@$WORK/blogger-export.xml"
src_batch blogger-blogger-export.xml
curl -s -o "$WORK/response" -b "$JAR" "$B/admin.php?module=transfer&action=source_preview&file=blogger-blogger-export.xml"
contains -q 'Comments are skipped\|Komentáře se vynechávají' "$WORK/response" && ! contains -q 'name="site_url"' "$WORK/response" && contains -q 'Planting the first beds' "$WORK/response" && echo "  ok     Blogger: the preview says comments are skipped and knows the blog's address" || { echo "  CHYBA  Blogger preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_run" -d "_csrf=$TOKEN" -d soubor=blogger-blogger-export.xml -d posts=news -d pages=page -d categories=category -d tags=tag -d drafts=1 -d builder=1 -d redirects=1 -d default_category=0
src_batch blogger-blogger-export.xml
expect "Blogger: the post with its labels as tags and date, the draft hidden, the comment not imported" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(n.seo_link, ':', n.visible, ':', DATE(n.datum), ':', IFNULL((SELECT GROUP_CONCAT(s.nazev ORDER BY s.nazev) FROM ka_novinky_stitky ns JOIN ka_stitky s ON s.ids = ns.ids WHERE ns.idc = n.idc), '-')) ORDER BY n.idc SEPARATOR '|') FROM ka_novinky n WHERE n.seo_link IN ('planting-first-beds', 'compost-notes') OR n.titulek LIKE 'Lovely%'")" \
  "planting-first-beds:1:2019-05-14:Spring,Vegetables|compost-notes:0:2024-06-01:Compost"
check "Blogger: the page" 200 /about-this-diary "slugs eat first"
curl -s -o "$WORK/response" "$B/novinky/planting-first-beds"; grep -q "podvrh" "$WORK/response" && { echo "  CHYBA  Blogger: the script got through"; ERRORS=$((ERRORS+1)); } || echo "  ok     Blogger: the script in the post is cleaned out, the paragraphs are made"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2019/05/planting-first-beds.html"); expect "Blogger: the old /2019/05/slug.html address redirects" "$code" "301 $B/novinky/planting-first-beds"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=source_images" -d "_csrf=$TOKEN" -d soubor=blogger-blogger-export.xml
for i in $(seq 1 10); do src_batch blogger-blogger-export.xml; grep -q "images downloaded\|Staženo .* obrázků" "$WORK/response" && break; done
expect "Blogger: the image in the text and the thumbnail at full size are in Media (any public host)" "$(sq "SELECT CONCAT(obrazek LIKE 'media/%', ':', text LIKE '%media/%') FROM ka_novinky WHERE seo_link = 'planting-first-beds'")" "1:1"
expect "the imports are recorded in ka_import_mapa under their own source labels" "$(sq "SELECT GROUP_CONCAT(DISTINCT zdroj ORDER BY zdroj) FROM ka_import_mapa WHERE zdroj LIKE 'ghost:%' OR zdroj LIKE 'blogger:%'")" "blogger:127.0.0.1,ghost:127.0.0.1"
check "the sources folder is not accessible from the web" 403 /storage/import/sources/ghost-ghost-export.json
kill "$SRC_PID" 2>/dev/null || true

