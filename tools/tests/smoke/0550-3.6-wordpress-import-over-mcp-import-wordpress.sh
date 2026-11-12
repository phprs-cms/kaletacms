echo "== 3.6: WordPress import over MCP (import_wordpress) – everything hidden, menus into the draft look, authors, redirects"
# Jan Novák has an account here with the e-mail of the WordPress author (in another case); a page here already uses the old address /kontakt-stavby
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin) VALUES ('jan-novak-36', '!', 'Jan Novák', 'jan.novak@stavby-novak.example', 1)" > /dev/null
WXR_JAN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'jan-novak-36'")
mcp create_page '{"title":"Kontakt (this site)","slug":"kontakt-stavby"}' > /dev/null
# the draft look starts empty for this block and comes back afterwards; the live menus must not change at all
sq "DROP TABLE IF EXISTS ka_test_look; CREATE TABLE ka_test_look AS SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'look_draft'" > /dev/null
WXR_LIVE_MENU=$(sq "SELECT COALESCE(SHA2(GROUP_CONCAT(umisteni, jazyk, polozky ORDER BY umisteni, jazyk), 256), '-') FROM ka_menu")
mcp import_wordpress '{"url":"http://10.0.0.1/export.xml"}' > "$WORK/response"
mcp_text; contains -q 'internal network' "$WORK/text" && echo "  ok     3.6 import_wordpress: an export address is fetched with the SSRF rules of the image downloader" || { echo "  CHYBA  3.6 import_wordpress url to an internal address"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp upload_file "{\"filename\":\"Stavby Novak.xml\",\"data\":\"$(base64 < "$ROOT/tools/fixtures/wordpress-migration.xml" | tr -d '\n')\"}" > "$WORK/response"
WXR_FILE=$(mcp_value import_file)
expect "3.6 upload_file keeps a WordPress export privately for import_wordpress, never in Media" \
  "$WXR_FILE|$(sq "SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE '%stavby-novak%'")|$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/import/$WXR_FILE")" "stavby-novak.xml|0|403"
mcp_as "$DRAFT_TOKEN" import_wordpress "{\"file\":\"$WXR_FILE\"}" > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     3.6 import_wordpress is not for a drafts-only connection" || { echo "  CHYBA  3.6 import_wordpress from a drafts-only connection"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
wxr_run() { # a new import of the file, confirmed with the first call; then one call per batch until it is done
  mcp import_wordpress "{\"file\":\"$WXR_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"
  WXR_ID=$(mcp_value import)
  for _ in 1 2 3 4 5; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress "{\"import\":\"$WXR_ID\"}" > "$WORK/response"; done
}
wxr_run
expect "3.6 import_wordpress: done in batches, counts per type, everything that was public on WordPress arrives hidden" \
  "$(mcp_value phase)|$(mcp_value found menus 0 items)|$(mcp_value result news)|$(mcp_value result pages)|$(mcp_value result hidden_but_public_on_wordpress)|$(mcp_value everything_hidden)" "done|8|2|4|5|1"
mcp_text; contains -q '"what":"status","count":1' "$WORK/text" && contains -q '"what":"layout:Breakdance","count":1' "$WORK/text" && contains -q 'migration_report' "$WORK/text" \
  && echo "  ok     3.6 import_wordpress: what was skipped and why (a private page, a Breakdance layout) and the next steps" || { echo "  CHYBA  3.6 import_wordpress skipped/next"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "3.6 nothing is public: news drafts, pages hidden, the private page not imported" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link IN ('nova-zakazka-v-lhote', 'rozepsany-clanek-o-strechach') AND visible = 0), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('sluzby-stavby', 'sluzby-stavby/rekonstrukce-bytu', 'kontakt-stavby-2', 'o-firme-novak') AND zobrazit = 0), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'interni-cenik'))")" "2/4/0"
expect "3.6 authors: Jan's news belongs to the user with his e-mail, Petra's to the importer, no account is created" \
  "$(sq "SELECT CONCAT((SELECT autor FROM ka_novinky WHERE seo_link = 'nova-zakazka-v-lhote') = $WXR_JAN, '/', (SELECT autor FROM ka_novinky WHERE seo_link = 'rozepsany-clanek-o-strechach') = (SELECT idu FROM ka_uzivatele WHERE user = 'admin'), '/', (SELECT COUNT(*) FROM ka_uzivatele WHERE email LIKE '%stavby-novak%'))")|$(mcp_value authors 0 why)" \
  "1/1/1|a user here has the same e-mail"
# 3.33 (M3): a child page keeps its old nested address – no redirect is needed; the old ?p= address still redirects to it
expect "3.6/3.33 a nested WordPress page keeps its old address (no redirect), its ?p= address redirects to it" \
  "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'sluzby-stavby/rekonstrukce-bytu'")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '?p=%' AND na_adresu = 'sluzby-stavby/rekonstrukce-bytu'")" "0|1"
expect "3.6 an old address that is a page here is not taken over by a redirect (links to it stay), and the answer says so" \
  "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'kontakt-stavby'")|$(mcp_value redirects not_created 0 from)|$(curl -s -o /dev/null -w '%{http_code}' "$B/kontakt-stavby")" "0|/kontakt-stavby|404"
expect "3.6 the menus: main and footer into the draft look, the third one handed back for save_menu" \
  "$(mcp_value menus 0 location)|$(mcp_value menus 0 status)|$(mcp_value menus 1 location)|$(mcp_value menus 2 status)|$(mcp_value menus 2 items_for_save_menu 0 url)" \
  "main|in_draft_look|footer|skipped|https://instagram.example/stavbynovak"
wxr_menu() { php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true);
  $f = function (array $items) use (&$f): string { return implode(",", array_map(fn (array $i): string => $i["text"] . "=" . ($i["url"] ?? $i["type"]) . (!empty($i["new_window"]) ? "*" : "") . (isset($i["children"]) ? "[" . $f($i["children"]) . "]" : ""), $items)); };
  echo ($v["look_draft"] ?? "") !== "" ? "draft:" : "live:", $f($v["items"] ?? []);' -- "$WORK/response"; }
mcp get_menu '{"location":"main"}' > "$WORK/response"
expect "3.6 the main menu draft: links to the new addresses, hierarchy kept, a third level moved up, a page that was not imported left out" "$(wxr_menu)" \
  "draft:Domů=/,=page[Byty=page,Postup=/sluzby-stavby/rekonstrukce-bytu#postup],Aktuality stavby=/novinky/kategorie/aktuality-stavby[Zakázka=/novinky/nova-zakazka-v-lhote],Kontakt=page"
mcp get_menu '{"location":"footer"}' > "$WORK/response"
expect "3.6 the footer menu draft: custom links stay, a path of the old site stays a path" "$(wxr_menu)" "draft:Facebook=https://facebook.example/stavbynovak*,Ochrana údajů=/ochrana-udaju"
# 3.12 (UX310-17): the look bar says what each draft menu changes for visitors and Publish asks first; the import result offers
# to preview and publish only the menus, with the same summary
check "3.12 UX310-17: the look bar lists the main menu before → after" 200 "/admin.php?module=appearance" "Hlavní menu – položky: [0-9]* → [0-9]*\."
contains -q 'Z menu zmizí:' "$WORK/response" && contains -q 'publish_look" data-potvrdit=' "$WORK/response" \
  && echo "  ok     3.12 UX310-17: the look bar names what leaves the menu and Publish asks first" || { echo "  CHYBA  3.12 UX310-17: no removed items or no confirmation in the look bar"; ERRORS=$((ERRORS+1)); }
check "3.12 UX310-17: the import result shows the menu changes and publishes only the menus" 200 "/admin.php?module=transfer&action=progress&file=$WXR_FILE" 'action=publish_menus" data-potvrdit='
contains -q 'class="hlaska hlaska-varovani import-menu-zmeny"' "$WORK/response" && contains -q 'name="adresa" value="https://www.stavby-novak.example' "$WORK/response" \
  && echo "  ok     3.12 UX310-17: the result lists what visitors will see and checks the move against the old site" || { echo "  CHYBA  3.12 UX310-17: menu changes or the move check missing on the result"; ERRORS=$((ERRORS+1)); }
# the same file again: nothing is duplicated and the menus are not put into the draft a second time
wxr_run
expect "3.6 import_wordpress again: nothing duplicated, everything reported as already imported, the menus too" \
  "$(mcp_value phase)|$(mcp_value result news)|$(mcp_value result pages)|$(mcp_value result already_imported)|$(mcp_value menus 0 status)|$(sq "SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'nova-zakazka-v-lhote%'")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'sluzby-stavby%' AND seo_link NOT LIKE '%/%'")" \
  "done|0|0|6|skipped|1|1"
expect "3.6 the live menus did not change; the draft look holds the main and the footer menu" \
  "$(sq "SELECT COALESCE(SHA2(GROUP_CONCAT(umisteni, jazyk, polozky ORDER BY umisteni, jazyk), 256), '-') FROM ka_menu")|$(sq "SELECT JSON_LENGTH(hodnota, '\$.menus') FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$WXR_LIVE_MENU|2"
# 3.12 (UX310-17): "Publish the menus" on the import result – the menus go live, the rest of the draft keeps waiting (the live menus come back afterwards)
sq "DROP TABLE IF EXISTS ka_test_menu; CREATE TABLE ka_test_menu AS SELECT * FROM ka_menu; UPDATE ka_nastaveni SET hodnota = JSON_SET(hodnota, '\$.classes', JSON_OBJECT('ux17-waits', JSON_OBJECT('styl', JSON_OBJECT(), 'css', ''))) WHERE promenna = 'look_draft'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer&action=progress&file=$WXR_FILE"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=publish_menus" -d "_csrf=$TOKEN" -d "soubor=$WXR_FILE"
expect "3.12 UX310-17: Publish the menus puts both menus live and leaves the waiting class in the draft" \
  "$(sq "SELECT COUNT(*) FROM ka_menu WHERE jazyk = '' AND ((umisteni = 'hlavni' AND polozky LIKE '%Aktuality stavby%') OR (umisteni = 'paticka' AND polozky LIKE '%facebook.example%'))")|$(sq "SELECT CONCAT(JSON_CONTAINS_PATH(hodnota, 'one', '\$.menus'), JSON_CONTAINS_PATH(hodnota, 'one', '\$.classes.\"ux17-waits\"')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "2|01"
sq "DELETE FROM ka_menu; INSERT INTO ka_menu SELECT * FROM ka_test_menu; DROP TABLE ka_test_menu" > /dev/null
# 3.6 N36-2: the export an import works on cannot be swapped – no .xml from a drafts-only connection, an existing name is never
# replaced, and a file changed after the preview stops the import
WXR_B64=$(base64 < "$ROOT/tools/fixtures/wordpress-migration.xml" | tr -d '\n')
mcp_as "$DRAFT_TOKEN" upload_file "{\"filename\":\"Swap.xml\",\"data\":\"$WXR_B64\"}" > "$WORK/response"; R1=$(contains -q 'full access' "$WORK/response" && echo refused || echo saved)
mcp upload_file "{\"filename\":\"Stavby Novak.xml\",\"data\":\"$WXR_B64\"}" > "$WORK/response"; R2=$(contains -q 'already there' "$WORK/response" && echo refused || echo replaced)
mcp import_wordpress "{\"file\":\"$WXR_FILE\"}" > /dev/null
printf '\n<!-- changed after the preview -->\n' >> "$WORK/web/storage/import/$WXR_FILE"
mcp import_wordpress "{\"import\":\"$WXR_FILE\",\"confirm\":true,\"images\":false}" > "$WORK/response"; R3=$(contains -q 'changed after the preview' "$WORK/response" && echo stopped || echo ran)
expect "3.6 N36-2: no export from a drafts-only connection, no replaced export, a changed export stops the import" "$R1|$R2|$R3|$(ls "$WORK/web/storage/import" | grep -c '^swap')" "refused|refused|stopped|0"
# 3.6 N36-1: an old WordPress address the site already routes (the news list, a news item, a page of a language version) never
# becomes a redirect – link healing would point live links at the new hidden record
N36_NEWS=$(sq "SELECT seo_link FROM ka_novinky WHERE visible = 1 AND smazano IS NULL ORDER BY idc LIMIT 1")
cat > "$WORK/n36.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>N36</title><link>https://n36.example</link><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>https://n36.example</wp:base_site_url>
<item><title>Novinky</title><link>https://n36.example/novinky/</link><dc:creator>admin</dc:creator><content:encoded><![CDATA[<p>Old news page</p>]]></content:encoded><wp:post_id>9001</wp:post_id><wp:post_name>novinky</wp:post_name><wp:status>publish</wp:status><wp:post_type>page</wp:post_type></item>
<item><title>Same news</title><link>https://n36.example/novinky/$N36_NEWS/</link><dc:creator>admin</dc:creator><content:encoded><![CDATA[<p>Old copy</p>]]></content:encoded><wp:post_id>9002</wp:post_id><wp:post_name>$N36_NEWS</wp:post_name><wp:status>publish</wp:status><wp:post_type>post</wp:post_type></item>
</channel></rss>
XML
mcp upload_file "{\"filename\":\"n36.xml\",\"data\":\"$(base64 < "$WORK/n36.xml" | tr -d '\n')\"}" > /dev/null
mcp import_wordpress '{"file":"n36.xml","confirm":true,"images":false,"menus":false}' > "$WORK/response"
for _ in 1 2 3; do [ "$(mcp_value phase)" = done ] && break; mcp import_wordpress '{"import":"n36.xml"}' > "$WORK/response"; done
expect "3.6 N36-1: the news list and a live news item are not taken over by redirects from the import" \
  "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy IN ('novinky', 'novinky/$N36_NEWS')")|$(curl -s -o /dev/null -w '%{http_code}' "$B/novinky/$N36_NEWS")" "0|200"
# the admin reads the same file with the same code: its preview offers the menus
check "3.6 admin Import and export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-migration.xml"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=progress&file=wordpress-migration.xml" -d "_csrf=$TOKEN"
check "3.6 admin import preview: the WordPress menus and the option to bring them into the draft look" 200 "/admin.php?module=transfer&action=preview&file=wordpress-migration.xml" 'name="menu" value="1" checked'
check "3.6 admin import preview: a page builder layout is reported" 200 "/admin.php?module=transfer&action=preview&file=wordpress-migration.xml" 'Breakdance'
sq "UPDATE ka_nastaveni SET hodnota = COALESCE((SELECT hodnota FROM ka_test_look LIMIT 1), '') WHERE promenna = 'look_draft'; DROP TABLE ka_test_look" > /dev/null

