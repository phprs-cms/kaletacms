echo "== moving a site: import of a Kaleta export into a new installation (1.8)"
mcp write_notebook '{"topic":"history","title":"Historie redesignu","text":"Web přešel na Kaletu v říjnu 2026."}' > /dev/null # 2.15: the notebook moves with the site
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
sq "INSERT INTO ka_booking_services (name) VALUES ('Move test')" > /dev/null # 3.2: a booking set-up travels with the site
N6_PAYLOAD='<p class="n6" onclick="alert(1)">N6 check</p><script>alert(1)</script>' # 3.3.2: an archive from anywhere brings no script
sq "UPDATE ka_novinky SET text = CONCAT(text, '$N6_PAYLOAD') WHERE smazano IS NULL ORDER BY idc LIMIT 1" > /dev/null
# 3.3.3 (N55, N50): an archive with company_map and a social link "javascript:…" and a text fact "javascript:…" – the import drops
# them and keeps a valid link and an ordinary text fact; the settings of this site come back after the export
sq "CREATE TABLE ka_n55_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ('company_map', 'social_facebook', 'social_linkedin');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_map', 'javascript:alert(1)'), ('social_facebook', ' JavaScript:alert(2)'), ('social_linkedin', 'https://www.linkedin.com/company/n55');
  INSERT INTO ka_facts (fact_key, language, label, type, value, updated_at) VALUES ('n55promo', '', 'N55', 'text', 'javascript:alert(3)', '$(site_time)'), ('n55note', '', 'N55', 'text', 'Note: open daily', '$(site_time)')" > /dev/null
sq "INSERT INTO ka_stitky (nazev, seo_link, jazyk) VALUES ('Move 314', 'move-314', 'en')" > /dev/null # 3.14: a tag's language version moves with the site
sq "INSERT INTO ka_presmerovani (z_adresy, na_adresu, typ, vytvoreno) VALUES ('stara-uvodni-stranka', '', 301, '$(site_time)')" > /dev/null # 3.18.2: a redirect to the home page (stored with an empty target) moves too
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
# 3.14: the same content in export format 3 (English keys) – only on request, from the code; a second later, so the two
# archives never share the name of one second
sleep 1
MOVE3_EXPORT=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo Kaleta\Core\SiteExport::create($app->db(), $app->settings(), 3)["soubor"];')
mv "$WORK/web/storage/zalohy/$MOVE3_EXPORT" "$WORK/presun3.zip"
unzip -p "$WORK/presun3.zip" obsah.json > "$WORK/obsah3.json" 2>/dev/null || true
expect "3.14: export format 3 has the English header, table and column names, the default export stays format 2" \
  "$(php -r '$h = json_decode(rtrim(rtrim((string) fgets(fopen($argv[1], "rb"))), ",") . "}", true); $t = file_get_contents($argv[1]); echo $h["format_version"] ?? "-", "|", isset($h["settings"]["site_name"]) ? "settings" : "-", "|", (int) str_contains($t, "\n\"tags\":["), "|", (int) str_contains($t, "\n\"stitky\":["), "|", (int) str_contains($t, "\"slug\":\"move-314\",\"language\":\"en\"");' -- "$WORK/obsah3.json")|$(unzip -p "$WORK/presun3.zip" README.txt | grep -c 'Format 3 holds the same')" \
  "3|settings|1|0|1|1"
sq "DELETE FROM ka_stitky WHERE seo_link = 'move-314'" > /dev/null
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('company_map', 'social_facebook', 'social_linkedin'); INSERT INTO ka_nastaveni SELECT * FROM ka_n55_backup; DROP TABLE ka_n55_backup;
  DELETE FROM ka_facts WHERE fact_key IN ('n55promo', 'n55note')" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; MOVE_EXPORT=$(grep -o 'export-[0-9]*-[0-9]*-[0-9a-f]*\.zip' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/presun.zip" "$B/admin.php?module=transfer&action=download&file=$MOVE_EXPORT"
sq "DELETE FROM ka_booking_services WHERE name = 'Move test'" > /dev/null
MEDIA_IN_ZIP=$(unzip -Z1 "$WORK/presun.zip" | grep -c '^media/.')
[ "$MEDIA_IN_ZIP" -gt 0 ] && echo "  ok     the export carries the media ($MEDIA_IN_ZIP files)" || { echo "  CHYBA  no media in the export"; ERRORS=$((ERRORS+1)); }
PORT2=$((PORT + 5)); B2="http://127.0.0.1:$PORT2"; DB2="${DB_NAME}_presun"; JAR_MOVE="$WORK/cookies-presun.txt"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`; CREATE DATABASE \`$DB2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web2" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web2")
mkdir -p "$WORK/web2/media" "$WORK/web2/storage/log" "$WORK/web2/storage/cache"
(cd "$WORK/web2" && exec php -S "127.0.0.1:$PORT2" system/dev-router.php > "$WORK/server2.log" 2>&1) & SERVER2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/install.php" && break; sleep 0.2; done
curl -s -o "$WORK/response" -X POST "$B2/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB2" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Nový web" -d web=export -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky'
grep -q "Pokračovat importem" "$WORK/response" && echo "  ok     installer: Start from an export leads to the import" || { echo "  CHYBA  installer with web=export"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -10; ERRORS=$((ERRORS+1)); }
expect "installer: Start from an export leaves the site empty" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_stranky), '/', (SELECT COUNT(*) FROM ka_novinky), '/', (SELECT COUNT(*) FROM ka_uzivatele))")" "0/0/1"
curl -s -c "$JAR_MOVE" -b "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php"; curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
grep -q 'id="soubor-kaleta"' "$WORK/response" && echo "  ok     an empty site offers Import from Kaleta" || { echo "  CHYBA  Import from Kaleta form"; ERRORS=$((ERRORS+1)); }
location=$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=upload" -F "_csrf=$(csrf)" -F "soubor=@$WORK/presun.zip;type=application/zip")
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$location"
grep -q "Export webu „Testovací firma“" "$WORK/response" && grep -q 'name="potvrzeni"' "$WORK/response" && echo "  ok     preview of the export with counts and a confirmation" || { echo "  CHYBA  preview of the export: $location"; ERRORS=$((ERRORS+1)); }
MOVE_FILE=$(printf '%s' "$location" | sed 's/.*file=//')
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE"
expect "without the confirmation nothing starts" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT COUNT(*) FROM ka_stranky")" "0"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE" -d potvrzeni=1
for i in $(seq 1 80); do
  curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" -X POST "$B2/admin.php?module=transfer&action=kaleta" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE"
  grep -q "Web je naimportovaný\|Import se zastavil" "$WORK/response" && break
done
grep -q "Web je naimportovaný" "$WORK/response" && grep -q 'data-auto-odeslat' "$WORK/response" && { echo "  CHYBA  the result still submits itself"; ERRORS=$((ERRORS+1)); } || true
grep -q "Web je naimportovaný" "$WORK/response" && ! grep -q "přeskočen" "$WORK/response" && echo "  ok     3.18.2: nothing reported as skipped (the newline after the last row is no record)" || { echo "  CHYBA  3.18.2 the import reports skipped records: $(sed 's/<[^>]*>//g' "$WORK/response" | grep 'přeskočen' | head -3)"; ERRORS=$((ERRORS+1)); }
grep -q "Web je naimportovaný" "$WORK/response" && echo "  ok     the import went through in batches" || { echo "  CHYBA  import: $(sed 's/<[^>]*>//g' "$WORK/response" | grep -i 'import\|chyb' | head -5)"; ERRORS=$((ERRORS+1)); }
move_counts() { "${MYSQL[@]}" "$1" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_stranky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_novinky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_kategorie),
  (SELECT COUNT(*) FROM ka_kolekce), (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_komponenty), (SELECT COUNT(*) FROM ka_tridy), (SELECT COUNT(*) FROM ka_menu),
  (SELECT COUNT(*) FROM ka_popupy), (SELECT COUNT(*) FROM ka_presmerovani), (SELECT COUNT(*) FROM ka_media), (SELECT COUNT(*) FROM ka_novinky_stitky ns JOIN ka_novinky n ON n.idc = ns.idc WHERE n.smazano IS NULL))"; }
expect "the new site has the same content (pages/news/categories/collections/items/components/classes/menus/pop-ups/redirects/media/tags)" "$(move_counts "$DB2")" "$(move_counts "$DB_NAME")"
category_counts() { "${MYSQL[@]}" "$1" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_collection_categories), (SELECT COUNT(*) FROM ka_collection_category_texts), (SELECT COUNT(*) FROM ka_collection_category_templates),
  (SELECT COUNT(*) FROM ka_collection_item_categories ic JOIN ka_kolekce_polozky p ON p.idp = ic.idp WHERE p.smazano IS NULL))"; }
expect "3.7: collection categories, their texts, templates and items moved with the site" "$(category_counts "$DB2")" "$(category_counts "$DB_NAME")"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B2/produkty/bezecke-pasy/zdravotni")" = 200 ] && curl -s "$B2/en/produkty/treadmills" | contains '<h1>Kategorie: Treadmills</h1>' \
  && echo "  ok     3.7: the category pages run on the moved site" || { echo "  CHYBA  3.7 category pages after the move"; ERRORS=$((ERRORS+1)); }
expect "same numbers: home page, site name and the design system came along" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))")" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))")"
expect "3.3.2: news HTML from the export is sanitized, its structure and classes kept" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT(SUM(text LIKE '%<p class=\"n6\">N6 check</p>%'), '/', SUM(text LIKE '%onclick%' OR text LIKE '%<script%')) FROM ka_novinky WHERE text LIKE '%N6 check%'")" "1/0"
sq "UPDATE ka_novinky SET text = REPLACE(text, '$N6_PAYLOAD', '')" > /dev/null
expect "3.3.3 (N55, N50): the import drops javascript: in company_map, a social link and a text fact; a valid link and ordinary text came along" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%javascript:%'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'social_linkedin'), (SELECT GROUP_CONCAT(fact_key ORDER BY fact_key) FROM ka_facts WHERE fact_key LIKE 'n55%'))")" "0|https://www.linkedin.com/company/n55|n55note"
expect "accounts and secrets stay on the new site (users, site address, tokens)" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_uzivatele), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'), (SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('webhook_secret', 'smtp_password') AND hodnota <> ''), (SELECT COUNT(DISTINCT autor) FROM ka_novinky))")" "1/$B2/0/1"
# 3.2: an export made before 3.2 knew Bookings as core – its booking set-up switches the feature on; from 3.2 the export's own choice counts
MOVE_BOOKINGS=$(cd "$ROOT" && php -r 'require "system/bootstrap.php"; echo version_compare(KALETA_VERSION, "3.2.0", "<") ? 1 : 0;')
expect "3.2: the booking set-up came along; Bookings follow the export's version (an export from before 3.2 switches them on)" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_booking_services WHERE name = 'Move test'), '|', (SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'))")" "1|$MOVE_BOOKINGS"
[ "$("${MYSQL[@]}" "$DB2" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")" != "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")" ] && echo "  ok     the new site keeps its own cron address" || { echo "  CHYBA  the cron address came from the old site"; ERRORS=$((ERRORS+1)); }
MOVED_MEDIA=$("${MYSQL[@]}" "$DB2" -N -e "SELECT obr_poloha FROM ka_media ORDER BY ido LIMIT 1")
[ -n "$MOVED_MEDIA" ] && [ -f "$WORK/web2/$MOVED_MEDIA" ] && echo "  ok     the media files are on the new site" || { echo "  CHYBA  media file $MOVED_MEDIA"; ERRORS=$((ERRORS+1)); }
[ -z "$(find "$WORK/web2/media" -name '*.php')" ] && echo "  ok     no PHP came into media/" || { echo "  CHYBA  PHP in media/"; ERRORS=$((ERRORS+1)); }
expect "3.18.2: a redirect to the home page works on the moved site" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B2/stara-uvodni-stranka")" "301 $B2/"
sq "DELETE FROM ka_presmerovani WHERE z_adresy = 'stara-uvodni-stranka'" > /dev/null
code=$(curl -s -o "$WORK/response" -w '%{http_code}' "$B2/"); [ "$code" = 200 ] && grep -q "Testovací firma" "$WORK/response" && echo "  ok     the moved site runs" || { echo "  CHYBA  moved site: $code"; ERRORS=$((ERRORS+1)); }
expect "2.15: the notebook moved with the site (the note, its topic and author)" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT(COUNT(*), '|', MAX(topic), '|', MAX(title), '|', MAX(author)) FROM ka_notebook")" "1|history|Historie redesignu|test"
SLUG_ITEM=$("${MYSQL[@]}" "$DB2" -N -e "SELECT seo_link FROM ka_stranky WHERE zobrazit = 1 AND smazano IS NULL AND ids <> (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page') ORDER BY ids LIMIT 1")
expect "a moved page looks the same" "$(curl -s "$B2/$SLUG_ITEM" | grep -o '<h1[^>]*>[^<]*' | head -1)" "$(curl -s "$B/$SLUG_ITEM" | grep -o '<h1[^>]*>[^<]*' | head -1)"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
grep -q 'id="soubor-kaleta"' "$WORK/response" && { echo "  CHYBA  a site with content still offers the import form"; ERRORS=$((ERRORS+1)); }
expect "a site with content refuses another import" "$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=kaleta_select" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE" | grep -c 'kaleta')|$(curl -s -b "$JAR_MOVE" -o - "$B2/admin.php?module=transfer&action=kaleta&file=$MOVE_FILE" | grep -c 'name="potvrzeni"')" "1|0"
expect "3.14: a tag keeps its language version when the site moves" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT(jazyk, ':', seo_link) FROM ka_stitky WHERE nazev = 'Move 314'")" "en:move-314"
# 3.14: export format 3 into a clean install gives the same site as format 2 – counts and the main rows of every main table
# (dates in the site's wall time, as written), the settings that travel and the tag links
roundtrip314() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$1" -N -e "SET SESSION group_concat_max_len = 16000000; SELECT CONCAT_WS('|',
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', ids, seo_link, titulek, jazyk, IFNULL(preklad_z, '-'), zobrazit, SHA2(IFNULL(text, ''), 256), SHA2(IFNULL(stavba, ''), 256)) ORDER BY ids), 256) FROM ka_stranky WHERE smazano IS NULL),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', idc, seo_link, titulek, jazyk, tema, datum, visible, SHA2(text, 256)) ORDER BY idc), 256) FROM ka_novinky WHERE smazano IS NULL),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', idt, nazev, seo_link, jazyk) ORDER BY idt), 256) FROM ka_kategorie),
  (SELECT CONCAT(COUNT(*), ':', SHA2(GROUP_CONCAT(CONCAT_WS(':', ids, nazev, seo_link, jazyk, SHA2(IFNULL(popis, ''), 256)) ORDER BY ids), 256)) FROM ka_stitky),
  (SELECT SHA2(GROUP_CONCAT(CONCAT(idc, '-', ids) ORDER BY idc, ids), 256) FROM ka_novinky_stitky),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', z_adresy, na_adresu, typ) ORDER BY idp), 256) FROM ka_presmerovani),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', ido, obr_poloha, nazev, IFNULL(sekce, '-')) ORDER BY ido), 256) FROM ka_media),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', idp, idk, seo_link, jazyk, SHA2(data, 256)) ORDER BY idp), 256) FROM ka_kolekce_polozky WHERE smazano IS NULL),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', umisteni, jazyk, SHA2(polozky, 256)) ORDER BY umisteni, jazyk), 256) FROM ka_menu),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', typ, jazyk, varianta, SHA2(IFNULL(stavba, ''), 256)) ORDER BY typ, jazyk, varianta), 256) FROM ka_casti),
  (SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', promenna, SHA2(hodnota, 256)) ORDER BY promenna), 256) FROM ka_nastaveni WHERE promenna IN ('site_name', 'home_page', 'design_system', 'additional_languages', 'company_name')))"; }
ROUNDTRIP2=$(move_counts "$DB2")/$(category_counts "$DB2")/$(roundtrip314 "$DB2")
kill "$SERVER2_PID" 2>/dev/null || true; wait "$SERVER2_PID" 2>/dev/null || true
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`; CREATE DATABASE \`$DB2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
rm -f "$WORK/web2/config.php"; cp "$ROOT/install.php" "$WORK/web2/install.php"; rm -rf "$WORK/web2/media"; mkdir -p "$WORK/web2/media"
(cd "$WORK/web2" && exec php -S "127.0.0.1:$PORT2" system/dev-router.php >> "$WORK/server2.log" 2>&1) & SERVER2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/install.php" && break; sleep 0.2; done
curl -s -o /dev/null -X POST "$B2/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB2" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Nový web 314" -d web=export -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky'
rm -f "$JAR_MOVE"; curl -s -c "$JAR_MOVE" -b "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php"; curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
location=$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=upload" -F "_csrf=$(csrf)" -F "soubor=@$WORK/presun3.zip;type=application/zip")
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$location"; MOVE314=$(printf '%s' "$location" | sed 's/.*file=//')
grep -q "Export webu „Testovací firma“" "$WORK/response" && grep -q 'name="potvrzeni"' "$WORK/response" && echo "  ok     3.14: the preview reads a format-3 export (site name from its English header)" || { echo "  CHYBA  3.14: preview of a format-3 export: $location"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE314" -d potvrzeni=1
for i in $(seq 1 80); do
  curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" -X POST "$B2/admin.php?module=transfer&action=kaleta" -d "_csrf=$(csrf)" -d "soubor=$MOVE314"
  grep -q "Web je naimportovaný\|Import se zastavil" "$WORK/response" && break
done
grep -q "Web je naimportovaný" "$WORK/response" && echo "  ok     3.14: a format-3 export imports in batches" || { echo "  CHYBA  3.14 format-3 import: $(sed 's/<[^>]*>//g' "$WORK/response" | grep -i 'import\|chyb' | head -5)"; ERRORS=$((ERRORS+1)); }
expect "3.14: export format 3 → clean install = export format 2 → clean install (counts, main rows, settings, tag links)" "$(move_counts "$DB2")/$(category_counts "$DB2")/$(roundtrip314 "$DB2")" "$ROUNDTRIP2"
expect "3.14: … and its media files came along" "$(cd "$WORK/web2" && find media -type f ! -name '.*' | wc -l | tr -d ' ')" "$MEDIA_IN_ZIP"
# 3.7 security review N37-2, N37-10, N37-11: an export with a page on a system address (its subpage, and a later page with
# the first free slug), a category address with a newline, categories against the tree rules, one with an item's address,
# texts in an unknown language and an assignment across collections – imported by the rules of a save, the result says what changed
cp "$WORK/presun.zip" "$WORK/presun37.zip"
OTHER37=$(sq "SELECT COALESCE(MIN(idp), 0) FROM ka_kolekce_polozky WHERE idk <> $PRODUKTY")
php -r '$z = new ZipArchive(); $z->open($argv[1]); $d = json_decode((string) $z->getFromName("obsah.json"), true); [$idk, $sub, $other, $top] = array_map("intval", array_slice($argv, 2));
  $d["stranky"][] = ["ids" => 9701, "titulek" => "Form 37", "seo_link" => "form", "zobrazit" => 1, "text" => "<p>Form 37</p>"];
  $d["stranky"][] = ["ids" => 9702, "titulek" => "Detail 37", "seo_link" => "form/detail", "nadrazena" => 9701, "zobrazit" => 1, "text" => "<p>Detail 37</p>"];
  $d["stranky"][] = ["ids" => 9703, "titulek" => "Form two 37", "seo_link" => "form-2", "zobrazit" => 1, "text" => "<p>Form two 37</p>"];
  $d["collection_categories"][] = ["id" => 9711, "idk" => $idk, "parent_id" => 9711, "visible" => 1];
  $d["collection_categories"][] = ["id" => 9712, "idk" => $idk, "parent_id" => $sub, "visible" => 1];
  $d["collection_category_texts"][] = ["category_id" => 9711, "language" => "", "idk" => $idk, "name" => "Nova 37", "slug" => "imp\n"];
  $d["collection_category_texts"][] = ["category_id" => 9712, "language" => "", "idk" => $idk, "name" => "Kolize 37", "slug" => "stimulator"];
  $d["collection_category_texts"][] = ["category_id" => 9712, "language" => "xx", "idk" => $idk, "name" => "Unknown 37", "slug" => "unknown-37"];
  $d["collection_item_categories"][] = ["idp" => $other, "category_id" => $top];
  $d["stranky"][] = ["ids" => 9704, "titulek" => "Media 315", "seo_link" => "media-315", "zobrazit" => 1, "text" => "<p><img src=\"/media/2026/10/Photo.JPG\" alt=\"p\"> <a href=\"/media/2026/10/route.GPX\">route</a></p>"];
  $d["media"][] = ["ido" => 9790, "obr_poloha" => "media/2026/10/Photo.JPG", "nazev" => "Photo 315", "obr_width" => 4, "obr_height" => 4];
  ob_start(); imagejpeg(imagecreatetruecolor(4, 4)); $z->addFromString("media/2026/10/Photo.JPG", (string) ob_get_clean());
  $z->addFromString("media/2026/10/route.GPX", "<?xml version=\"1.0\"?><gpx version=\"1.1\" creator=\"x\"><trk><name>r</name></trk></gpx>");
  $z->addFromString("media/2026/10/fake-315.pdf", "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>");
  $z->addFromString("media/2026/10/fake-315.png", "<html><body><script>alert(2)</script></body></html>");
  $z->addFromString("obsah.json", (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); $z->close();' -- "$WORK/presun37.zip" "$PRODUKTY" "$CAT_SUB" "$OTHER37" "$CAT_TOP"
kill "$SERVER2_PID" 2>/dev/null || true; wait "$SERVER2_PID" 2>/dev/null || true
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`; CREATE DATABASE \`$DB2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
rm -f "$WORK/web2/config.php"; cp "$ROOT/install.php" "$WORK/web2/install.php"
(cd "$WORK/web2" && exec php -S "127.0.0.1:$PORT2" system/dev-router.php >> "$WORK/server2.log" 2>&1) & SERVER2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/install.php" && break; sleep 0.2; done
curl -s -o /dev/null -X POST "$B2/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB2" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Nový web 37" -d web=export -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky'
rm -f "$JAR_MOVE"; curl -s -c "$JAR_MOVE" -b "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php"; curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
location=$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=upload" -F "_csrf=$(csrf)" -F "soubor=@$WORK/presun37.zip;type=application/zip")
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$location"; MOVE37=$(printf '%s' "$location" | sed 's/.*file=//')
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE37" -d potvrzeni=1
for i in $(seq 1 80); do
  curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" -X POST "$B2/admin.php?module=transfer&action=kaleta" -d "_csrf=$(csrf)" -d "soubor=$MOVE37"
  grep -q "Web je naimportovaný\|Import se zastavil" "$WORK/response" && break
done
expect "3.7 N37-2: an imported page on a system address gets a free slug (not the one a later page has), its subpage moves along, the result says so" \
  "$("${MYSQL[@]}" "$DB2" -N -e "SELECT GROUP_CONCAT(seo_link ORDER BY ids) FROM ka_stranky WHERE ids IN (9701, 9702, 9703)")|$(curl -s -o /dev/null -w '%{http_code}' "$B2/form-3/detail")|$(grep -c 'Adresu /form používá systém, proto stránka dostala /form-3.' "$WORK/response" || true)" \
  "form-3,form-3/detail,form-2|200|1"
expect "3.7 N37-10/N37-11: imported categories by the tree rules – two levels, no newline in an address, no item's address, known languages, one collection" \
  "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT GROUP_CONCAT(COALESCE(parent_id, 'top') ORDER BY id) FROM ka_collection_categories WHERE id IN (9711, 9712)),
    (SELECT GROUP_CONCAT(CONCAT(language, ':', slug) ORDER BY category_id, language) FROM ka_collection_category_texts WHERE category_id IN (9711, 9712)), (SELECT COUNT(*) FROM ka_collection_item_categories WHERE idp = $OTHER37 AND category_id = $CAT_TOP))")|$(grep -c 'adresu stimulator nelze použít' "$WORK/response" || true)" \
  "top,top|:nova-37,:stimulator-2|0|1"
# 3.15 N72: media from the archive get lower-case extensions (Photo.JPG → Photo.jpg, route.GPX → route.gpx) and the content
# follows; a file whose content is not what its extension says (HTML as .pdf or .png) never reaches media/
expect "3.15 N72: imported media get lower-case extensions, pages and media rows point at them, HTML posing as a PDF or PNG stays out" \
  "$(ls "$WORK/web2/media/2026/10/" 2>/dev/null | grep -cE '^(Photo\.jpg|route\.gpx)$' || true)|$(ls "$WORK/web2/media/2026/10/" 2>/dev/null | grep -cE '^(Photo\.JPG|route\.GPX|fake-315\..*)$' || true)|$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT((SELECT LOCATE(BINARY '/media/2026/10/Photo.jpg', text) > 0 AND LOCATE(BINARY '/media/2026/10/route.gpx', text) > 0 FROM ka_stranky WHERE ids = 9704), '|', (SELECT obr_poloha FROM ka_media WHERE ido = 9790))")|$(grep -c 'fake-315.pdf' "$WORK/response" || true)" \
  "2|0|1|media/2026/10/Photo.jpg|1"
expect "3.7 N37-11: the item keeps its page next to the imported category" "$(curl -s "$B2/produkty/stimulator" | grep -c '<h1>Stimulátor</h1>' || true)|$(curl -s -o /dev/null -w '%{http_code}' "$B2/produkty/stimulator-2")" "1|200"
[ -s "$WORK/web2/storage/log/chyby.log" ] && { echo "  CHYBA  errors on the new site:"; cat "$WORK/web2/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); }
kill "$SERVER2_PID" 2>/dev/null || true; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`"

