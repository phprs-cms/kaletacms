echo "== 3.33 review H1: real pages are never taken for untouched sample pages"
# The second site of 2000–2040 (web333, its helpers s3_install, s3q, s3mcp, s3_field) once more: a fresh 3.33 install, a site
# from 3.20–3.32 (no record), a site a Kaleta export was imported into and a 3.32 site whose first pages were imported.
s3php() { (cd "$WORK/web333" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); '"$1" -- "${@:2}"); }
s3_untouched() { s3php 'echo implode(",", array_map(fn ($p) => $p["seo_link"] . ":" . $p["how"], Kaleta\Core\StarterPages::untouched($app->db(), $app->settings())));'; }

# a fresh 3.33 install: the recorded starter pages, as before
s3_install firemni
expect "3.33 review H1: a fresh 3.33 install – the four starter pages are recorded (replaced by default, as before)" "$(s3_untouched)" "uvod:recorded,o-nas:recorded,sluzby:recorded,kontakt:recorded"
# 3.33 review L3: a starter page with a translation counts as edited (never replaced – its translation would lose its original)
s3q "INSERT INTO ka_stranky (titulek, seo_link, text, jazyk, preklad_z, zmeneno) SELECT 'Services', 'services', '<p>x</p>', 'en', ids, NOW() FROM ka_stranky WHERE seo_link = 'sluzby' AND jazyk = ''"
expect "3.33 review L3: a translated starter page is no longer untouched, it is listed as edited" \
  "$(s3_untouched)|$(s3php 'echo implode(",", Kaleta\Core\StarterPages::edited($app->db(), $app->settings()));')" "uvod:recorded,o-nas:recorded,kontakt:recorded|$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby'")"
s3q "DELETE FROM ka_stranky WHERE seo_link = 'services' AND jazyk = 'en'"

# a site from 3.20–3.32: the same pages without the record – still recognised (legacy), but never replaced by default
s3q "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'starter_pages'"
expect "3.33 review H1: genuine untouched starters of a 3.32 install are still recognised – by the legacy rule" "$(s3_untouched)" "uvod:legacy,o-nas:legacy,sluzby:legacy,kontakt:legacy"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer"
contains -q 'id="sample-pages-how"' "$WORK/response" && contains -q 'poznaná jen podle titulku a adresy' "$WORK/response" \
  && echo "  ok     3.33 review H1: the sample pages panel says how each page was recognised" || { echo "  CHYBA  3.33 review H1: the panel does not say how"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf); curl -s -b "$S3_JAR" -c "$S3_JAR" -o /dev/null -X POST "$S3/admin.php?module=transfer&action=starter_remove" -d "_csrf=$TOKEN"
expect "3.33 review H1: Move the sample pages to the trash never trashes a visible page recognised only by the legacy rule" \
  "$(s3q "SELECT COUNT(*) FROM ka_stranky WHERE smazano IS NOT NULL")" "0"
OLD333_PORT=$((PORT + 10)); OLD333="http://127.0.0.1:$OLD333_PORT"
(cd "$WORK/old333" && exec php -S "127.0.0.1:$OLD333_PORT" > /dev/null 2>&1) & OLD333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD333/" && break; sleep 0.3; done
s3mcp import_website "{\"url\":\"$OLD333\",\"images\":false}" > "$WORK/response"; S3_IMPORT=$(s3_field import_id)
for i in $(seq 1 20); do [ "$(s3_field phase)" = finding ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
expect "3.33 review H1 import_website preview: the legacy sample pages are kept by default (why legacy, how legacy)" \
  "$(s3_field starter_pages | php -r 'echo implode(",", array_map(fn ($c) => $c["old"] . ":" . ($c["replace"] ? "replace" : $c["why"]) . ":" . $c["how"], json_decode(stream_get_contents(STDIN), true)));')" \
  "/:legacy:legacy,/kontakt:legacy:legacy,/o-nas:legacy:legacy,/sluzby:legacy:legacy"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer&action=web_progress&id=$S3_IMPORT"
expect "3.33 review H1: the admin preview offers the legacy sample pages unticked, with the reason" \
  "$(grep -c 'name="nahradit_vzory\[\]" value="[0-9]*" checked' "$WORK/response" || true)|$(grep -c 'data-starter-legacy' "$WORK/response" || true)" "0|4"
s3mcp import_website "{\"import_id\":\"$S3_IMPORT\",\"replace_starter_pages\":[\"/kontakt\"]}" > "$WORK/response"
expect "3.33 review H1 replace_starter_pages names one legacy page – only that one is replaced" \
  "$(s3_field starter_pages | php -r 'echo implode(",", array_map(fn ($c) => $c["old"] . ":" . ($c["replace"] ? "replace" : $c["why"]), json_decode(stream_get_contents(STDIN), true)));')" "/:legacy,/kontakt:replace,/o-nas:legacy,/sluzby:legacy"
S3_HOME_BEFORE=$(s3q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")
s3mcp import_website "{\"import_id\":\"$S3_IMPORT\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(s3_field phase)" = importing ] || break; s3mcp import_website "{\"import_id\":\"$S3_IMPORT\"}" > "$WORK/response"; done
expect "3.33 review H1: only the named legacy page went to the trash; the others stayed and the imports got a number; the home page stays" \
  "$(s3q "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', smazano IS NOT NULL) ORDER BY seo_link) FROM ka_stranky WHERE seo_link REGEXP '^(kontakt|o-nas|sluzby)'")|$(s3q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")" \
  "kontakt:0,kontakt-sample:1,o-nas:0,o-nas-2:0,sluzby:0,sluzby-2:0|$S3_HOME_BEFORE"
kill "$OLD333_PID" 2>/dev/null || true; OLD333_PID=

# a site a Kaleta export was imported into: the export comes from a site installed a day earlier with untouched starters
s3_install firemni
s3q "UPDATE ka_stranky SET zmeneno = zmeneno - INTERVAL 1 DAY; UPDATE ka_uzivatele SET potvrzeno = potvrzeno - INTERVAL 1 DAY"
S3_EXPORT=$(s3php 'echo Kaleta\Core\SiteExport::create($app->db(), $app->settings())["soubor"];')
s3_install export
expect "3.33 review H1: Start from an export writes an empty record" "$(s3q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'starter_pages'")" "{}"
mkdir -p "$WORK/web333/storage/import"; cp "$WORK/web333/storage/zalohy/$S3_EXPORT" "$WORK/web333/storage/import/$S3_EXPORT"
expect "3.33 review H1: the Kaleta export is imported" "$(s3php '$s = Kaleta\Core\SiteImport::newState($argv[1]); Kaleta\Core\SiteImport::prepare($s); $s["faze"] = "data"; $i = new Kaleta\Core\SiteImport($app->db(), $app->settings(), 1);
  for ($n = 0; $n < 50 && $s["faze"] === "data"; $n++) { $i->importData($s); } for ($n = 0; $n < 50 && $s["faze"] === "media"; $n++) { $i->importMedia($s); } echo $s["faze"];' "$S3_EXPORT")" "hotovo"
expect "3.33 review H1: after the import the original starter titles are there, the record is empty and no page is a sample" \
  "$(s3q "SELECT GROUP_CONCAT(seo_link ORDER BY ids) FROM ka_stranky WHERE ids <= 4")|$(s3q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'starter_pages'")|$(s3_untouched)" "uvod,o-nas,sluzby,kontakt|{}|"
s3q "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'starter_pages'"
expect "3.33 review H1: a site imported from an export on 3.32 (no record) – the copied stamp is older than this site's first account, so no legacy samples" "$(s3_untouched)" ""

# a 3.32 site whose first pages were imported (an empty install, no record): starter titles at the lowest numbers
s3_install export
s3q "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'starter_pages';
  INSERT INTO ka_stranky (titulek, seo_link, text, zmeneno) SELECT t, s, '<p>x</p>', (SELECT potvrzeno + INTERVAL 2 HOUR FROM ka_uzivatele ORDER BY idu LIMIT 1)
  FROM (SELECT 'Úvod' t, 'uvod' s UNION ALL SELECT 'O nás', 'o-nas' UNION ALL SELECT 'Služby', 'sluzby' UNION ALL SELECT 'Kontakt', 'kontakt') x"
expect "3.33 review H1: pages written later than the installation are no samples" "$(s3_untouched)" ""
s3q "UPDATE ka_stranky SET zmeneno = (SELECT potvrzeno FROM ka_uzivatele ORDER BY idu LIMIT 1); INSERT INTO ka_import_mapa (zdroj, typ, cizi_id, nase_id) SELECT 'web:old.example', 'stranka', seo_link, ids FROM ka_stranky"
expect "3.33 review H1: pages an import created (a row in ka_import_mapa) are no samples, even with the installer's stamp" "$(s3_untouched)" ""
[ -s "$WORK/web333/storage/log/chyby.log" ] && { echo "  CHYBA  errors on the second site:"; cat "$WORK/web333/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); }
kill "$S3_PID" 2>/dev/null || true; wait "$S3_PID" 2>/dev/null || true; S3_PID=; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$S3_DB\`"
