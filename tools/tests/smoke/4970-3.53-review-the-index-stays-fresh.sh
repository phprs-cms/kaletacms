echo "== 3.53 review: the index reads a changed component or field set, undo and the import empty what they change, Latin inside CJK, elision, news during a re-index, the job's progress"
r970_get() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1"; }
r970_found() { local n; n=$(r970_get "$1" | grep -c "href=\"$2\"" || true); echo "${n:-0}"; }
r970_page() { sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${1:-0}"; }
r970_item() { sq "SELECT COALESCE(hledani, 'NULL') FROM ka_kolekce_polozky WHERE idp = ${1:-0}"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
background_jobs hold

# a published component and two pages placing it; while the job indexes the first page the component is published again (a
# trigger plays the other request): the second page must get the new text – never the component read for the first page
mcp save_component '{"name":"Karta R970"}' > "$WORK/response"; R970_COMP=$(mcp_value id)
mcp save_build "{\"component\":${R970_COMP:-0},\"build\":{\"v\":1,\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Stará karta R970</p>\"}}]}}" > /dev/null
mcp publish_build "{\"component\":${R970_COMP:-0}}" > /dev/null
R970_PAGES=""
for R970_N in 1 2; do
  mcp create_page "{\"title\":\"Karty $R970_N R970\",\"slug\":\"karty-$R970_N-r970\",\"content\":\"<p>Úvod</p>\",\"visible\":true}" > "$WORK/response"; R970_ID=$(mcp_value id)
  mcp save_build "{\"id\":${R970_ID:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"component\",\"content\":{\"component\":\"${R970_COMP:-0}\"}}]}}" > /dev/null
  mcp publish_build "{\"id\":${R970_ID:-0}}" > /dev/null
  R970_PAGES="$R970_PAGES ${R970_ID:-0}"
done
read -r R970_P1 R970_P2 <<< "$R970_PAGES"
# a collection of one text field and two items holding a second field's value; while the job indexes the first item the
# second field becomes a searched text field
R970_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, detail, pole) VALUES ('Lodě R970', 'lode-r970', 1, '[{\"klic\":\"nazev2\",\"popisek\":\"Název\",\"typ\":\"text\"},{\"klic\":\"material\",\"popisek\":\"Materiál\",\"typ\":\"cislo\"}]'); SELECT LAST_INSERT_ID();")
R970_I1=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($R970_IDK, 'Loď 1 R970', 'lod-1-r970', '{\"nazev2\":\"Plachetnice\",\"material\":\"Dub R970\"}', '$(site_time)', 1); SELECT LAST_INSERT_ID();")
R970_I2=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit) VALUES ($R970_IDK, 'Loď 2 R970', 'lod-2-r970', '{\"nazev2\":\"Kánoe\",\"material\":\"Jasan R970\"}', '$(site_time)', 1); SELECT LAST_INSERT_ID();")
background_jobs settle
sq "CREATE TRIGGER r970_component AFTER UPDATE ON ka_stranky FOR EACH ROW UPDATE ka_komponenty SET stavba = '{\"v\":1,\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Nová karta R970</p>\"}}]}'
    WHERE idm = ${R970_COMP:-0} AND NEW.ids = ${R970_P1:-0} AND NEW.hledani IS NOT NULL;
  CREATE TRIGGER r970_fields AFTER UPDATE ON ka_kolekce_polozky FOR EACH ROW UPDATE ka_kolekce SET pole = '[{\"klic\":\"nazev2\",\"popisek\":\"Název\",\"typ\":\"text\"},{\"klic\":\"material\",\"popisek\":\"Materiál\",\"typ\":\"text\"}]'
    WHERE idk = $R970_IDK AND NEW.idp = $R970_I1 AND NEW.hledani IS NOT NULL;
  UPDATE ka_stranky SET hledani = NULL WHERE ids IN (${R970_P1:-0}, ${R970_P2:-0}); UPDATE ka_kolekce_polozky SET hledani = NULL WHERE idk = $R970_IDK" > /dev/null
run_job search_index > /dev/null
sq "DROP TRIGGER r970_component; DROP TRIGGER r970_fields" > /dev/null
expect "3.53 review: a component published while the job runs – the page indexed after it has the new text (the component is read after the row lock, not kept from the page before)" \
  "$(r970_page "$R970_P1")|$(r970_page "$R970_P2")" "karty 1 r970 stara karta r970|karty 2 r970 nova karta r970"
expect "3.53 review: a field turned into a searched text while the job runs – the item indexed after it has the field's value" \
  "$(r970_item "$R970_I1")|$(r970_item "$R970_I2")" "lod 1 r970 plachetnice|lod 2 r970 kanoe jasan r970"

# undo of a Claude session that changed the fields: the restored field set empties the items, the job indexes them by it
sq "UPDATE ka_kolekce_polozky SET hledani = NULL WHERE idk = $R970_IDK" > /dev/null
run_job search_index > /dev/null
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp update_collection '{"collection":"lode-r970","pole":[{"klic":"nazev2","popisek":"Název","typ":"text"},{"klic":"material","popisek":"Materiál","typ":"cislo"}]}' > /dev/null
R970_SESSION=$(sq "SELECT MAX(id) FROM ka_agent_sessions")
run_job search_index > /dev/null
R970_CHANGED=$(r970_item "$R970_I1")
mcp undo_agent_session "{\"id\":$R970_SESSION,\"confirm\":true}" > /dev/null
R970_UNDONE=$(r970_item "$R970_I1")
run_job search_index > /dev/null
expect "3.53 review: undo of update_collection – the items' text loses the field with the session and has it again after the undo (3.54: the undo fills the emptied items at once, SearchIndex::soon)" \
  "$R970_CHANGED|$R970_UNDONE|$(r970_item "$R970_I1")|$(sq "SELECT pole LIKE '%\"material\",\"popisek\":\"Materiál\",\"typ\":\"text\"%' FROM ka_kolekce WHERE idk = $R970_IDK")" \
  "lod 1 r970 plachetnice|lod 1 r970 plachetnice dub r970|lod 1 r970 plachetnice dub r970|1"

# the import of a Kaleta export: a page indexed by the job before its component was imported is indexed again at the end
sq "UPDATE ka_stranky SET hledani = 'karty 1 r970' WHERE ids = ${R970_P1:-0}" > /dev/null
R970_EMPTIED=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Core\SearchIndex::componentPagesChanged($app->db()) > 0 ? "emptied" : "kept";')
expect "3.53 review: after an import the pages placing a component are emptied (SiteImport::finish) and the job gives them the component's text; a page without one keeps its text" \
  "$R970_EMPTIED|$(r970_page "$R970_P1")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE hledani IS NULL AND (stavba IS NULL OR stavba NOT LIKE '%\"komponenta\":%')")" "emptied|NULL|0"
run_job search_index > /dev/null
expect "3.53 review: … the job fills it from the component" "$(r970_page "$R970_P1")" "karty 1 r970 nova karta r970"

# Latin words and numbers written into Chinese and Japanese text, French elision – found on the site
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu) VALUES ('cjk-r970', '网站 R970', '<p>使用WordPress搭建网站，最新のiPhone15を発売。价格100元</p>', 1, 0),
  ('hotel-r970', 'Hôtel du Parc R970', '<p>Notre hôtel au centre.</p>', 1, 0)" > /dev/null
run_job search_index > /dev/null
expect "3.53 review: \"wordpress r970\", \"iphone r970\" and \"100 r970\" find the Chinese page; \"l'hôtel r970\" and \"d’hôtel r970\" the hotel" \
  "$(r970_found 'wordpress r970' /cjk-r970)$(r970_found 'iphone r970' /cjk-r970)$(r970_found '100 r970' /cjk-r970)|$(r970_found "l'hôtel r970" /hotel-r970)$(r970_found 'd’hôtel r970' /hotel-r970)" "111|11"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# a news re-index running: a news item above the cursor has its version 1 index ("książka" split as "ksi zka") – 3.54: no
# title alternative; the search page rewrites it first (SearchIndex::heal runs reindexNews) and finds it by its new index
R970_STATE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'), '')")
R970_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
R970_NEWS=$(sq "INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, noindex, hledani) VALUES ('ksiazka-r970', 'Nowa książka R970', '', '', $R970_TEMA, '$(site_time '-1 hour')', 1, 0, 'nowa ksi zka r970'); SELECT LAST_INSERT_ID();")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '1:$((R970_NEWS - 1))')" > /dev/null
expect "3.54 D3: during a news re-index \"ksiazka r970\" finds the news item whose version 1 index split the word – /hledani rewrote it first" \
  "$(r970_found 'ksiazka r970' /novinky/ksiazka-r970)|$(sq "SELECT hledani FROM ka_novinky WHERE idc = $R970_NEWS")" "1|nowa ksiazka r970"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$R970_STATE'); DELETE FROM ka_novinky WHERE idc = $R970_NEWS; DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null

# the job remembers the rows waiting and what its run did – a run that filled rows is progress, never "stuck"
sq "UPDATE ka_stranky SET hledani = NULL WHERE seo_link IN ('cjk-r970', 'hotel-r970', 'karty-1-r970')" > /dev/null
R970_PENDING=$(search_index_pending)
R970_RUN=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); Kaleta\Core\SearchIndex::run($app->db(), $app->settings(), 0.000001);
  echo $app->settings()->get(Kaleta\Core\SearchIndex::WAITING), "|", (int) Kaleta\Core\SearchIndex::stuck($app->settings());')
expect "3.53 review: a run with almost no time fills one row and stores \"waiting:done\" – rows still wait, but it is no warning" "$R970_RUN" "$((R970_PENDING - 1)):1|0"
fill_search_index > /dev/null

background_jobs resume
sq "DELETE FROM ka_stranky WHERE seo_link IN ('karty-1-r970', 'karty-2-r970', 'cjk-r970', 'hotel-r970'); DELETE FROM ka_kolekce_polozky WHERE idk = $R970_IDK; DELETE FROM ka_kolekce WHERE idk = $R970_IDK" > /dev/null
mcp delete_component "{\"id\":${R970_COMP:-0}}" > /dev/null
