echo "== 3.53 A4: the search text cannot be forgotten – a save indexes at once, content writes empty it, the agent journal, undo and the export never carry it"
s930_id() { grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://'; }
s930_journal() { sq "SELECT CONCAT(COUNT(*) > 0, '|', COALESCE(SUM(COALESCE(before_row, '') LIKE '%\"hledani\":%' OR COALESCE(after_row, '') LIKE '%\"hledani\":%'), 0), '|', COALESCE(SUM(untracked IS NOT NULL AND tbl IN ('stranky', 'kolekce_polozky')), 0)) FROM ka_agent_journal WHERE session_id = $1"; }

# a page that was on the site before the session, with the text a job made; then a new Claude session
S930_OLD=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, hledani) VALUES ('altany-a930', 'Altány A930', '<p>Dřevěné altány</p>', 1, 'altany a930 drevene altany'); SELECT LAST_INSERT_ID();")
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp vytvor_stranku '{"titulek":"Pergoly A930","text":"<p>Hliníková pergola Łódź</p>","zobrazit":true}' > "$WORK/response"; mcp_text; S930_PAGE=$(s930_id)
S930_SESSION=$(sq "SELECT MAX(id) FROM ka_agent_sessions")
expect "3.53 A4: a page created over MCP is indexed at once – its normalized title and text, before any job runs" \
  "$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${S930_PAGE:-0}")" "pergoly a930 hlinikova pergola lodz"
mcp update_page "{\"id\":${S930_PAGE:-0},\"text\":\"<p>Zastřešení terasy</p>\"}" > /dev/null
mcp update_page "{\"id\":$S930_OLD,\"title\":\"Zahradní altány A930\"}" > /dev/null
expect "3.53 A4: an update over MCP indexes again at once (the old words go) – the new page and the page from before the session" \
  "$(sq "SELECT CONCAT(hledani LIKE '%zastreseni terasy%', hledani LIKE '%lodz%') FROM ka_stranky WHERE ids = ${S930_PAGE:-0}")|$(sq "SELECT hledani LIKE 'zahradni altany a930 %' FROM ka_stranky WHERE ids = $S930_OLD")" "10|1"
expect "3.53 A4: search_site finds the saved page in the same session" "$(mcp search_site '{"query":"zastreseni terasy"}' | grep -c 'pergoly-a930')" "1"
expect "3.53 A4: the agent journal of the session holds its pages without the search text – no copy of the index in before_row / after_row, no untracked page write" \
  "$(s930_journal "$S930_SESSION")" "1|0|0"

# the job fills the text again after the session (as it would after any change); undo is still no conflict
sq "UPDATE ka_stranky SET hledani = NULL WHERE ids = $S930_OLD" > /dev/null
run_job search_index > /dev/null
mcp undo_agent_session "{\"id\":$S930_SESSION,\"confirm\":true}" > "$WORK/response"; mcp_text
expect "3.53 A4: undo after the job – no conflict (the search text is no change by a person), the new page goes, the old one has its title back and an empty search text" \
  "$(grep -o '"conflicts":\[[^]]*\]' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE ids = ${S930_PAGE:-0}")|$(sq "SELECT CONCAT(titulek, '|', COALESCE(hledani, 'NULL')) FROM ka_stranky WHERE ids = $S930_OLD")" \
  '"conflicts":[]|0|Altány A930|NULL'
run_job search_index > /dev/null
expect "3.53 A4: … and the job makes it from the restored content" "$(sq "SELECT hledani FROM ka_stranky WHERE ids = $S930_OLD")" "altany a930 drevene altany"

# the admin: a duplicate has its own text at once (never the original's), a save and a publish index at once
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"; S930_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$S930_CSRF" -d "ids=$S930_OLD"
S930_COPY=$(sq "SELECT MAX(ids) FROM ka_stranky WHERE seo_link LIKE 'altany-a930-kopie%'")
expect "3.53 A4: a duplicated page is indexed at once with its own title" "$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${S930_COPY:-0}")" "altany a930 kopie drevene altany"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$S930_CSRF" -d "ids=${S930_COPY:-0}" --data-urlencode "titulek=Kopie A930" \
  -d "seo_link=altany-a930-kopie" -d zobrazit=0 --data-urlencode "text=<p>Nový text stránky</p>"
expect "3.53 A4: a page saved in the admin is indexed at once" "$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${S930_COPY:-0}")" "kopie a930 novy text stranky"
mcp save_build "{\"id\":${S930_COPY:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Stavba A930\"}}]}}" > /dev/null
S930_DRAFT=$(sq "SELECT hledani FROM ka_stranky WHERE ids = ${S930_COPY:-0}")
mcp publish_build "{\"id\":${S930_COPY:-0}}" > /dev/null
expect "3.53 A4: a draft build leaves the search text; publishing indexes the published build at once" \
  "$S930_DRAFT|$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${S930_COPY:-0}")" "kopie a930 novy text stranky|kopie a930 stavba a930"

# collection items: a save over MCP indexes at once and is found; a new label keeps the text, a searched field turned into a number empties it
S930_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, detail, pole) VALUES ('Stany A930', 'stany-a930', 1, '[{\"klic\":\"podnadpis\",\"popisek\":\"Podnadpis\",\"typ\":\"text\"}]'); SELECT LAST_INSERT_ID();")
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp save_collection_item '{"collection":"stany-a930","name":"Stan Horizont A930","slug":"horizont-a930","values":{"podnadpis":"Lehký trekový stan"},"visible":true}' > "$WORK/response"; mcp_text; S930_ITEM=$(s930_id)
expect "3.53 A4: an item saved over MCP is indexed at once and search_site finds it in the same session" \
  "$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_kolekce_polozky WHERE idp = ${S930_ITEM:-0}")|$(mcp search_site '{"query":"trekovy stan"}' | grep -c 'horizont-a930')" "stan horizont a930 lehky trekovy stan|1"
mcp update_collection '{"collection":"stany-a930","pole":[{"klic":"podnadpis","popisek":"Krátký popis","typ":"text"}]}' > /dev/null
S930_LABEL=$(sq "SELECT hledani IS NOT NULL FROM ka_kolekce_polozky WHERE idp = ${S930_ITEM:-0}")
mcp update_collection '{"collection":"stany-a930","pole":[{"klic":"podnadpis","popisek":"Krátký popis","typ":"cislo"}]}' > /dev/null
S930_ITEM_SESSION=$(sq "SELECT MAX(id) FROM ka_agent_sessions")
expect "3.53 A4: update_collection – a new label keeps the items' search text, a text field turned into a number empties it; the session has no untracked item write" \
  "$S930_LABEL|$(sq "SELECT COALESCE(hledani, 'NULL') FROM ka_kolekce_polozky WHERE idp = ${S930_ITEM:-0}")|$(s930_journal "$S930_ITEM_SESSION" | cut -d'|' -f2-)" \
  "1|NULL|0|0"

# the column never leaves the site: neither the site export nor the page package carry it
S930_EXPORT=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
  $e = Kaleta\Core\SiteExport::create($app->db(), $app->settings()); $f = Kaleta\Core\Backup::FOLDER . "/" . basename($e["soubor"]);
  if (str_ends_with($f, ".zip")) { $z = new ZipArchive(); $z->open($f); $j = (string) $z->getFromName("obsah.json"); $z->close(); } else { $j = (string) file_get_contents($f); }
  @unlink($f); echo (int) str_contains($j, "\"altany-a930\""), "|", preg_match("/(^|[{,])\"hledani\":/m", $j);')
expect "3.53 A4: the site export has the pages, never their search text (nor that of news or items)" "$S930_EXPORT" "1|0"
expect "3.53 A4: the page package (export of one page) has no search text" \
  "$(curl -s -b "$JAR" "$B/admin.php?module=pages&action=export&id=${S930_COPY:-0}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? (int) array_key_exists("hledani", $d) . "|" . (int) str_contains(json_encode($d), "\"hledani\"") : "no package";')" "0|0"

sq "DELETE FROM ka_kolekce WHERE idk = $S930_IDK; DELETE FROM ka_stranky WHERE ids IN ($S930_OLD, ${S930_COPY:-0}, ${S930_PAGE:-0})" > /dev/null
