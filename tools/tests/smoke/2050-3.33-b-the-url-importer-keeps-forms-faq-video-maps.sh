echo "== 3.33 (part B, M4): the URL importer keeps forms, accordions, video, maps, files and the SEO fields – a local site of eight builders"
# a local old site from tools/fixtures/builder-pages: one page per builder (Elementor, Divi, WPBakery, Gutenberg, Breakdance and
# the Squarespace, Webflow and Wix exports), the share image, the linked PDF files and a video file – never a real site
B333_PORT=$((PORT + 10)); B333="http://127.0.0.1:$B333_PORT"; B333_DIR="$WORK/b333site"
mkdir -p "$B333_DIR/img" "$B333_DIR/files" "$B333_DIR/_files/ugd"
B333_PAGES="elementor divi wpbakery gutenberg breakdance squarespace webflow wix"
for b333_page in $B333_PAGES; do mkdir -p "$B333_DIR/$b333_page"; cp "$ROOT/tools/fixtures/builder-pages/$b333_page.html" "$B333_DIR/$b333_page/index.html"; done
php -r '$i = imagecreatetruecolor(1200, 630); imagefill($i, 0, 0, imagecolorallocate($i, 31, 78, 61)); imagepng($i, $argv[1]);' -- "$B333_DIR/img/share.png"
for b333_pdf in files/price-list.pdf files/aftercare.pdf files/brochure.pdf files/process.pdf _files/ugd/timetable.pdf; do
  printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$B333_DIR/$b333_pdf"; done
# an MP4 header (ftyp isom) and zeros: random bytes looked like a Windows executable to finfo about once in a hundred runs,
# and the importer rightly refused the file as content that does not match its extension
{ printf '\000\000\000\030ftypisom\000\000\002\000isomiso2mp41'; head -c 4072 /dev/zero; } > "$B333_DIR/files/clinic-tour.mp4"
printf 'User-agent: *\nCrawl-delay: 0\nSitemap: %s/sitemap.xml\n' "$B333" > "$B333_DIR/robots.txt"
{ printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'; for b333_page in $B333_PAGES; do printf '<url><loc>%s/%s/</loc></url>' "$B333" "$b333_page"; done; printf '</urlset>'; } > "$B333_DIR/sitemap.xml"
(cd "$B333_DIR" && exec php -S "127.0.0.1:$B333_PORT" > /dev/null 2>&1) & B333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B333/" && break; sleep 0.3; done
mcp import_website "{\"url\":\"$B333\"}" > "$WORK/response"; B333_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$B333_ID\"}" > "$WORK/response"; done
mcp import_website "{\"import_id\":\"$B333_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 30); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$B333_ID\"}" > "$WORK/response"; done
cp "$WORK/response" "$WORK/b333-import.json"
expect "3.33 M4: all eight builder pages imported, hidden" "$(import_field phase)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('elementor','divi','wpbakery','gutenberg','breakdance','squarespace','webflow','wix') AND zobrazit = 0 AND smazano IS NULL")" "done|8"
# the build of each page holds the widgets of the old page as elements
b333_types() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -r -e "SELECT stavba FROM ka_stranky WHERE seo_link = '$1' AND smazano IS NULL" | php -r '$b = json_decode(stream_get_contents(STDIN), true); $t = []; $w = function ($n) use (&$w, &$t) { foreach ($n as $p) { if (in_array($p["typ"], ["faq", "zalozky", "video", "mapa", "pocitadlo", "formular", "vlozeni"], true)) { $t[] = $p["typ"]; } $w($p["deti"] ?? []); } }; $w($b["deti"] ?? []); echo implode(",", $t);'; }
expect "3.33 M4: the builds keep the forms, accordions, tabs, counters, videos and maps of every builder" \
  "$(for b333_page in $B333_PAGES; do printf '%s=%s;' "$b333_page" "$(b333_types "$b333_page")"; done)" \
  "elementor=pocitadlo,faq,video,mapa,formular;divi=zalozky,faq,pocitadlo,video,mapa,formular;wpbakery=faq,zalozky,video,mapa,formular;gutenberg=faq,faq,video,video,mapa;breakdance=faq,zalozky,pocitadlo,video,mapa,formular;squarespace=faq,video,mapa;webflow=faq,zalozky,pocitadlo,pocitadlo,video,formular;wix=faq,video,mapa,formular;"
B333_EL=$(sq "SELECT CONCAT(seo_titulek, '|', obrazek LIKE 'media/%', '|', text LIKE '%media/%price-list%.pdf%', '|', stavba LIKE '%media/%price-list%.pdf%', '|', stavba LIKE '%/files/price-list.pdf%', '|', stavba LIKE '%any brand you choose%', '|', text LIKE '%How long does a kitchen take?%') FROM ka_stranky WHERE seo_link = 'elementor' AND smazano IS NULL")
expect "3.33 M4: the old <title> is the SEO title, og:image the share image in Media, the price list PDF in Media in the text and the build, the hidden answer kept" \
  "$B333_EL" "Bespoke kitchens – Northwind Joinery|1|1|1|0|1|1"
expect "3.33 M4: the video file of the Gutenberg page plays from Media; the linked PDFs of the other builders are in Media too" \
  "$(sq "SELECT stavba LIKE '%\"url\":\"media/%clinic-tour%.mp4\"%' FROM ka_stranky WHERE seo_link = 'gutenberg' AND smazano IS NULL")|$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha REGEXP '(price-list|aftercare|brochure|process|timetable)-[0-9a-f]{6}[.]pdf$'")|$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE '%clinic-tour%.mp4'")" "1|5|1"
B333_ANSWER=$(php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "{}", true); $k = $r["kept"] ?? []; $a = implode("\n", $r["attention"] ?? []);
  echo ($k["forms"] ?? 0) . "|" . ($k["faq"] ?? 0) . "|" . ($k["maps"] ?? 0) . "|" . ($k["files"] ?? 0) . "|" . ($k["pages_with_seo_fields"] ?? 0) . "|" . (int) str_contains($a, "/gutenberg – a form") . "|" . (int) str_contains($a, "/squarespace – a form") . "|" . (int) str_contains($r["next"] ?? "", "attention");' -- "$WORK/b333-import.json")
expect "3.33 M4: MCP – kept counts the forms, accordions, maps, files and SEO fields; attention names the two forms a script draws (placeholders)" "$B333_ANSWER" "6|9|7|6|8|1|1|1"
expect "3.33 M4: a form drawn by a script is a visible placeholder in the build, never an invented form" \
  "$(sq "SELECT stavba LIKE '%Formulář ze starého webu%' OR stavba LIKE '%Form from the old site%' FROM ka_stranky WHERE seo_link = 'squarespace' AND smazano IS NULL")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer&action=web_progress&id=$B333_ID"
expect "3.33 M4: the import's result page in the admin says what was kept and what needs attention – an import started over MCP reads in the admin's language" \
  "$(grep -c 'data-import-kept' "$WORK/response")|$(grep -c 'Ze starých stránek zůstalo' "$WORK/response")|$(grep -c '/squarespace – formulář, jehož pole kreslí skript' "$WORK/response")|$(grep -c '/squarespace – a form' "$WORK/response" || true)" "1|1|1|0"
kill "$B333_PID" 2>/dev/null; wait "$B333_PID" 2>/dev/null || true; B333_PID=
