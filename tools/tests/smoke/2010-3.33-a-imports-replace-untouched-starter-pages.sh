echo "== 3.33 part A: imports replace untouched starter pages, with the choice in the preview"
# the second site (2000) is a fresh starter site: Úvod, O nás, Služby, Kontakt untouched; Služby is edited now
s3mcp update_page "{\"id\":$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby'"),\"description\":\"Upraveno\"}" > /dev/null
# an old site on another platform: its home page and pages at the addresses of the starter pages
OLD333_PORT=$((PORT + 10)); OLD333="http://127.0.0.1:$OLD333_PORT"; rm -rf "$WORK/old333"; mkdir -p "$WORK/old333/kontakt" "$WORK/old333/o-nas" "$WORK/old333/sluzby"
old333page() { printf '<!doctype html><html><head><title>%s – Topení Dub</title><meta name="description" content="%s"></head><body><header>Menu</header><main><h1>%s</h1><p>%s</p>%s</main><footer>Patička</footer></body></html>' "$1" "$2" "$1" "Text stránky $1 je dost dlouhý na to, aby ho import vzal jako obsah stránky, a ne jako šum kolem obsahu." "${3:-}"; }
old333page "Vítejte u Dubu" "Kotle a topení" > "$WORK/old333/index.html"
old333page "Kontaktujte nás" "Napište nám" > "$WORK/old333/kontakt/index.html"
old333page "O firmě" "Kdo jsme" > "$WORK/old333/o-nas/index.html"
old333page "Naše služby" "Co děláme" > "$WORK/old333/sluzby/index.html"
old333_sitemap() { { printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'; for u in "$@"; do printf '<url><loc>%s%s</loc></url>' "$OLD333" "$u"; done; printf '</urlset>'; } > "$WORK/old333/sitemap.xml"; }
old333_sitemap / /kontakt/ /o-nas/ /sluzby/
printf 'User-agent: *\nSitemap: %s/sitemap.xml\n' "$OLD333" > "$WORK/old333/robots.txt"
(cd "$WORK/old333" && exec php -S "127.0.0.1:$OLD333_PORT" > /dev/null 2>&1) & OLD333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD333/" && break; sleep 0.3; done

s3mcp import_website "{\"url\":\"$OLD333\",\"images\":false}" > "$WORK/response"; S3_IMPORT=$(s3_field import_id)
for i in $(seq 1 20); do [ "$(s3_field phase)" = finding ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
expect "3.33 M2 import_website preview: the starter pages it replaces (Home for the old home page, Contact, About us) and the edited one it keeps" \
  "$(s3_field starter_pages | php -r 'echo implode(",", array_map(fn ($c) => $c["old"] . ">" . $c["title"] . ":" . ($c["replace"] ? "replace" : $c["why"]), json_decode(stream_get_contents(STDIN), true)));')" \
  "/>Úvod:replace,/kontakt>Kontakt:replace,/o-nas>O nás:replace,/sluzby>Služby:edited"
s3mcp import_website "{\"import_id\":\"$S3_IMPORT\",\"keep_starter_pages\":[\"/o-nas\"]}" > "$WORK/response"
expect "3.33 M2 keep_starter_pages declines one in the preview" "$(s3_field starter_pages 2 why)" "kept"
s3mcp import_website "{\"import_id\":\"$S3_IMPORT\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(s3_field phase)" = importing ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
expect "3.33 M2 import_website: Contact replaced at its own address, the starter in the trash as kontakt-sample; About us kept and the import got o-nas-2; the edited Služby kept (sluzby-2)" \
  "$(s3q "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', titulek, ':', smazano IS NOT NULL) ORDER BY seo_link) FROM ka_stranky WHERE seo_link REGEXP '^(kontakt|o-nas|sluzby)'")" \
  "kontakt:Kontaktujte nás:0,kontakt-sample:Kontakt:1,o-nas:O nás:0,o-nas-2:O firmě:0,sluzby:Služby:0,sluzby-2:Naše služby:0"
# 3.33 review M2: the old home page arrived hidden – the starter home page stays the home page, / never goes 404
expect "3.33 review M2 import_website: a hidden imported home page leaves the starter home page in place (home_page unchanged, nothing in the trash)" \
  "$(s3q "SELECT CONCAT(titulek, ':', zobrazit) FROM ka_stranky WHERE ids = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page')")|$(s3q "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'uvod-sample'")" "Úvod:1|0"
expect "3.33 M2 import_website result: what was replaced and what was kept, and why; the waiting home page with what to do" \
  "$(s3_field starter_pages_result | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo count($r["replaced"]), "/", implode(",", array_map(fn ($k) => $k["old"] . ":" . (str_contains($k["why"], "edited") ? "edited" : "kept"), $r["kept"])), "/", $r["home_waiting"]["title"] ?? "", ":", $r["home_waiting"]["imported_address"] ?? "", ":", str_contains($r["home_waiting"]["what_to_do"] ?? "", "update_settings") ? "how" : "";')" \
  "1//o-nas:kept,/sluzby:edited/Úvod:/home:how"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$S3/")" = 200 ] && echo "  ok     3.33 review M2: / still answers after the import" || { echo "  CHYBA  3.33 review M2: / after the import"; ERRORS=$((ERRORS+1)); }
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer&action=web_progress&id=$S3_IMPORT"
contains -q 'id="starter-pages-result"' "$WORK/response" && contains -q 'Kontakt ← /kontakt' "$WORK/response" \
  && contains -q 'id="starter-home-waiting"' "$WORK/response" \
  && echo "  ok     3.33 M2: the admin result of the website import names the replaced and kept sample pages and the waiting home page" || { echo "  CHYBA  3.33 M2: admin web import result"; ERRORS=$((ERRORS+1)); }

# the WordPress import: the front page and /kontakt replace the starter pages; in the admin preview a box unticked keeps one
s3_install firemni
s3mcp upload_file "{\"filename\":\"topeni-dub.xml\",\"data\":\"$(base64 < "$ROOT/tools/fixtures/wordpress-move-333.xml" | tr -d '\n')\"}" > "$WORK/response"
S3_WXR=$(s3_field import_file)
s3mcp import_wordpress "{\"file\":\"$S3_WXR\"}" > "$WORK/response"
for _ in 1 2 3; do [ "$(s3_field phase)" = preview ] && break; s3mcp import_wordpress "{\"import\":\"$S3_WXR\"}" > "$WORK/response"; done
expect "3.33 M2 import_wordpress preview: the front page lands on Home, /kontakt and /o-nas on the starter pages" \
  "$(s3_field starter_pages | php -r 'echo implode(",", array_map(fn ($c) => $c["old"] . ">" . $c["page_id"] . ":" . ($c["replace"] ? "replace" : $c["why"]), json_decode(stream_get_contents(STDIN), true)));')" \
  "/>$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'uvod'"):replace,/kontakt>$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakt'"):replace,/o-nas>$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'"):replace"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer&action=preview&file=$S3_WXR"
expect "3.33 M2: the admin preview of the WordPress import offers each sample page as a ticked box" "$(grep -c 'name="nahradit_vzory\[\]" value="[0-9]*" checked' "$WORK/response" || true)" "3"
# the person keeps Kontakt: only Úvod and O nás are ticked
TOKEN=$(csrf); S3_HOME=$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'uvod'"); S3_ABOUT=$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
curl -s -b "$S3_JAR" -c "$S3_JAR" -o /dev/null -X POST "$S3/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d "soubor=$S3_WXR" -d stranky=1 -d skryte=1 -d presmerovani=1 \
  -d "nahradit_vzory[]=$S3_HOME" -d "nahradit_vzory[]=$S3_ABOUT"
for _ in 1 2 3 4 5; do s3mcp import_wordpress "{\"import\":\"$S3_WXR\"}" > "$WORK/response"; [ "$(s3_field phase)" = done ] && break; done
expect "3.33 M2 import_wordpress: the hidden front page leaves Úvod as the home page (review M2), O nás replaced, Kontakt kept as chosen (the import got kontakt-2)" \
  "$(s3q "SELECT titulek FROM ka_stranky WHERE ids = (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page')")|$(s3q "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', titulek, ':', smazano IS NOT NULL) ORDER BY seo_link) FROM ka_stranky WHERE seo_link REGEXP '^(kontakt|o-nas)'")" \
  "Úvod|kontakt:Kontakt:0,kontakt-2:Kontaktujte nás:0,o-nas:O firmě:0,o-nas-sample:O nás:1"
expect "3.33 M2 import_wordpress result lists the replaced and the kept starter pages" \
  "$(s3_field starter_pages | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo count($r["replaced"] ?? []), "/", count($r["kept"] ?? []), "/", $r["home_waiting"]["title"] ?? "";')" "1/1/Úvod"
kill "$OLD333_PID" 2>/dev/null || true; OLD333_PID=
