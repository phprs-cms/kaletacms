echo "== 3.33 part A: imports keep nested addresses"
# the WordPress export of 2010 (already imported on the second site): /sluzby-topeni/topeni/kotle comes before its parent
expect "3.33 M3 import_wordpress: nested pages keep their old addresses and their parents – a child listed before its parent too" \
  "$(s3q "SELECT GROUP_CONCAT(CONCAT(c.seo_link, '<', IFNULL(p.seo_link, '-')) ORDER BY c.seo_link) FROM ka_stranky c LEFT JOIN ka_stranky p ON p.ids = c.nadrazena WHERE c.seo_link LIKE 'sluzby-topeni%' AND c.seo_link NOT LIKE '%velmi%'")" \
  "sluzby-topeni<-,sluzby-topeni/topeni<sluzby-topeni,sluzby-topeni/topeni/kotle<sluzby-topeni/topeni"
expect "3.33 M3 import_wordpress: a kept address needs no redirect" "$(s3q "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'sluzby-topeni%' AND z_adresy NOT LIKE '%velmi%'")" "0"
S3_LONG=$(s3q "SELECT seo_link FROM ka_stranky WHERE seo_link LIKE 'sluzby-topeni/velmi-dlouhy%'")
expect "3.33 M3 import_wordpress: an address over 120 characters has its last part shortened, the old address redirects to it, the result says so" \
  "$([ "${#S3_LONG}" -le 120 ] && [ "${#S3_LONG}" -gt 100 ] && echo fits)|$(s3q "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE 'sluzby-topeni/velmi-dlouhy%' AND na_adresu = '$S3_LONG'")|$(s3_field addresses_changed 0 why | grep -c 'shortened' || true)" "fits|1|1"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer&action=progress&file=$S3_WXR"
contains -q 'id="addresses-changed"' "$WORK/response" && echo "  ok     3.33 M3: the admin result of the WordPress import lists the shortened address" || { echo "  CHYBA  3.33 M3: admin WordPress result without the changed addresses"; ERRORS=$((ERRORS+1)); }
expect "3.33 M3: the nested page answers at its old address once published" \
  "$(s3q "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link IN ('sluzby-topeni', 'sluzby-topeni/topeni', 'sluzby-topeni/topeni/kotle')"; curl -s -o /dev/null -w '%{http_code}' "$S3/sluzby-topeni/topeni/kotle")" "200"

# the website import: a child before its parent, a page whose parent never existed, a first part the system uses
rm -rf "$WORK/old333"; mkdir -p "$WORK/old333/kotle-servis/plyn" "$WORK/old333/reference/projekt-a" "$WORK/old333/form/poptavka"
old333page "Plynové kotle" "Servis plynu" > "$WORK/old333/kotle-servis/plyn/index.html"
old333page "Servis kotlů" "Servis" > "$WORK/old333/kotle-servis/index.html"
old333page "Projekt A" "Reference" > "$WORK/old333/reference/projekt-a/index.html"
old333page "Poptávka" "Napište" > "$WORK/old333/form/poptavka/index.html"
old333_sitemap /kotle-servis/plyn/ /kotle-servis/ /reference/projekt-a/ /form/poptavka/
printf 'User-agent: *\nSitemap: %s/sitemap.xml\n' "$OLD333" > "$WORK/old333/robots.txt"
(cd "$WORK/old333" && exec php -S "127.0.0.1:$OLD333_PORT" > /dev/null 2>&1) & OLD333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD333/kotle-servis/" && break; sleep 0.3; done
s3mcp import_website "{\"url\":\"$OLD333\",\"images\":false}" > "$WORK/response"; S3_IMPORT=$(s3_field import_id)
for i in $(seq 1 20); do [ "$(s3_field phase)" = finding ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
s3mcp import_website "{\"import_id\":\"$S3_IMPORT\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(s3_field phase)" = importing ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
expect "3.33 M3 import_website: nested addresses kept (the child linked to its later parent, the orphan without a parent), a system word first lands at the last part" \
  "$(s3q "SELECT GROUP_CONCAT(CONCAT(c.seo_link, '<', IFNULL(p.seo_link, '-')) ORDER BY c.seo_link) FROM ka_stranky c LEFT JOIN ka_stranky p ON p.ids = c.nadrazena WHERE c.titulek IN ('Plynové kotle', 'Servis kotlů', 'Projekt A', 'Poptávka')")" \
  "kotle-servis<-,kotle-servis/plyn<kotle-servis,poptavka<-,reference/projekt-a<-"
expect "3.33 M3 import_website: only the address that changed redirects, and the result lists it" \
  "$(s3q "SELECT GROUP_CONCAT(CONCAT(z_adresy, '>', na_adresu)) FROM ka_presmerovani WHERE z_adresy IN ('kotle-servis', 'kotle-servis/plyn', 'reference/projekt-a', 'form/poptavka')")|$(s3_field addresses_changed 0 old)" \
  "form/poptavka>poptavka|/form/poptavka"
# the orphan keeps its directory when Claude changes its address (update_page) – it is not flattened to /projekt-b
S3_ORPHAN=$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'reference/projekt-a'")
s3mcp update_page "{\"id\":$S3_ORPHAN,\"slug\":\"projekt-b\"}" > "$WORK/response"
expect "3.33 M3: update_page on a nested page without a parent keeps its directory" "$(s3q "SELECT seo_link FROM ka_stranky WHERE ids = $S3_ORPHAN")" "reference/projekt-b"
kill "$OLD333_PID" 2>/dev/null || true; OLD333_PID=
