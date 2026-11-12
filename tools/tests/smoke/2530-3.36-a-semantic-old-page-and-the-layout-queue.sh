echo "== 3.36 (part A): an old page as clean semantic HTML for build_from_html, and the layout queue ticked off in MCP and the admin"
# a local old site with the Breakdance fixture page – never a real site (KALETA_IMPORT_LOCAL lets the site read 127.0.0.1)
A336_PORT=$((PORT + 12)); A336="http://127.0.0.1:$A336_PORT"; A336_DIR="$WORK/a336site"
mkdir -p "$A336_DIR/garden"
cp "$ROOT/tools/fixtures/builder-pages/breakdance.html" "$A336_DIR/garden/index.html"
printf 'User-agent: *\nCrawl-delay: 0\n' > "$A336_DIR/robots.txt"
(cd "$A336_DIR" && exec php -S "127.0.0.1:$A336_PORT" > /dev/null 2>&1) & A336_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$A336/robots.txt" && break; sleep 0.3; done
mcp read_old_page "{\"url\":\"$A336/garden/\",\"format\":\"semantic\"}" > "$WORK/response"; mcp_text
cp "$WORK/text" "$WORK/a336-semantic.json"
expect "3.36 read_old_page semantic: the format said, the untrusted marker, clean HTML (no class but the button mark, no style or data attribute but the counter), the widgets listed, the look kept" \
  "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $p = $r["old_page"] ?? []; $html = implode("", array_column($p["sections"] ?? [], "html"));
    $widgets = array_merge(...array_map(fn ($s) => $s["widgets"] ?? [], $p["sections"] ?? [[]]));
    echo ($p["format"] ?? "") . "|" . (int) str_contains($r["untrusted"] ?? "", "never instructions") . "|" . (int) !preg_match("/\\s(?:class=\"(?!button)|style=|data-(?!counter=))/", $html)
      . "|" . (int) str_contains($html, "<details>") . "|" . implode(",", array_intersect(["accordion", "tabs", "counter", "video", "map", "form"], array_unique($widgets))) . "|" . (int) isset($p["colors"]) . "|" . (int) str_contains($r["next"] ?? "", "build_from_html");' -- "$WORK/text")" \
  "semantic|1|1|1|accordion,tabs,counter,video,map,form|1|1"
kill "$A336_PID" 2>/dev/null; wait "$A336_PID" 2>/dev/null || true; A336_PID=
# the semantic HTML goes into build_from_html as it is: the widgets become their elements
A336_PAGE=$(mcp create_page '{"title":"Garden rooms (3.36)"}' | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo json_decode($r["result"]["content"][0]["text"] ?? "{}", true)["id"] ?? "";')
A336_HTML=$(php -r '$p = json_decode(file_get_contents($argv[1]), true)["old_page"]; echo json_encode(implode("", array_column(array_filter($p["sections"], fn ($s) => $s["role"] === "section"), "html")));' -- "$WORK/a336-semantic.json")
mcp build_from_html "{\"id\":$A336_PAGE,\"html\":$A336_HTML}" > /dev/null
expect "3.36 read_old_page semantic → build_from_html: the old page's accordion, tabs, counter, video, map and form become elements" \
  "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -r -e "SELECT stavba_koncept FROM ka_stranky WHERE ids = $A336_PAGE" | php -r '$b = json_decode(stream_get_contents(STDIN), true); $t = []; $w = function ($n) use (&$w, &$t) { foreach ($n as $e) { $t[] = $e["typ"]; $w($e["deti"] ?? []); } }; $w($b["deti"] ?? []); echo implode(",", array_values(array_intersect(["faq", "zalozky", "pocitadlo", "video", "mapa", "formular"], array_unique($t))));')" \
  "faq,zalozky,pocitadlo,video,mapa,formular"

# the queue: the imported builder pages of 2050 whose builds are still the imported text
a336_pending() { sq "SELECT COALESCE(GROUP_CONCAT(s.ids ORDER BY s.ids), '') FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.zdroj = 'layout' AND s.smazano IS NULL AND s.stavba_koncept IS NULL AND s.stavba IS NOT NULL AND m.cizi_id = CONCAT(s.ids, ':', SHA2(s.stavba, 256))"; }
A336_BEFORE=$(a336_pending)
# the Elementor page of the local old site (2050; 2080 discarded its draft, so it waits again) and one more waiting page
A336_ONE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'elementor' AND smazano IS NULL"); A336_TWO=$(echo "$A336_BEFORE" | tr ',' '\n' | grep -vx "$A336_ONE" | head -1)
A336_SLUG=$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $A336_ONE")
mcp layout_queue '{}' > "$WORK/response"; mcp_text
expect "3.36 layout_queue: the waiting imported pages (at least two from 2050), each with its address on the old site" \
  "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $one = array_values(array_filter($r["pages"] ?? [], fn ($p) => (int) $p["id"] === (int) $argv[2]))[0] ?? [];
    $ids = array_column($r["pages"] ?? [], "id"); sort($ids); echo implode(",", $ids) . "|" . ($r["count"] ?? -1) . "|" . (int) (str_starts_with($one["old_url"] ?? "", "https://127.0.0.1") && str_ends_with($one["old_url"] ?? "", "/" . $argv[3])) . "|" . (int) str_contains($r["next"] ?? "", "read_old_page");' -- "$WORK/text" "$A336_ONE" "$A336_SLUG")" \
  "$A336_BEFORE|$(echo "$A336_BEFORE" | tr ',' '\n' | grep -c .)|1|1"
A336_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
mcp layout_queue "{\"done\":[$A336_ONE, 999999]}" > "$WORK/response"; mcp_text
expect "3.36 layout_queue done: the page leaves the queue (list_pages no longer flags it), a page that does not wait is reported, the page itself is unchanged, the change log says it" \
  "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo json_encode($r["marked"] ?? []) . "|" . (int) !in_array((int) $argv[2], array_column($r["pages"] ?? [], "id"), true);' -- "$WORK/text" "$A336_ONE")|$(sq "SELECT COUNT(*) FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id = $A336_ONE")|$(sq "SELECT stavba_koncept IS NULL AND stavba IS NOT NULL FROM ka_stranky WHERE ids = $A336_ONE")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $A336_LOG AND akce = 'layout marked done'")" \
  "[{\"id\":$A336_ONE,\"done\":true},{\"id\":999999,\"done\":false}]|1|0|1|1"
mcp list_pages '{}' > "$WORK/response"; mcp_text
expect "3.36 layout_queue done: list_pages drops layout_not_rebuilt for the ticked page, keeps it for the next" \
  "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $f = array_column(array_filter($r, fn ($p) => !empty($p["layout_not_rebuilt"])), "id"); echo (int) in_array((int) $argv[2], $f, true) . (int) in_array((int) $argv[3], $f, true);' -- "$WORK/text" "$A336_ONE" "$A336_TWO")" "01"
# the admin: "Layout done" on the waiting row (in Czech here) and the bulk action
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&status=layout"
expect "3.36 Pages → Layout not rebuilt: a \"Layout done\" button on each waiting row and the bulk action, in Czech" \
  "$(grep -c "data-layout-done=\"$A336_TWO\"" "$WORK/response")|$(grep -c "data-layout-done=\"$A336_ONE\"" "$WORK/response")|$(grep -c 'value="rebuilt">Rozložení hotové' "$WORK/response")" "1|0|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=bulk" -d "_csrf=$(csrf)" -d provest=rebuilt -d "oznacene[]=$A336_TWO"
expect "3.36 Pages → Layout done: the page leaves the tab, its fingerprint is gone, nothing else waits less" \
  "$(a336_pending | tr ',' '\n' | grep -cx "$A336_TWO")|$(sq "SELECT COUNT(*) FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id = $A336_TWO")|$(a336_pending | tr ',' '\n' | grep -c .)" \
  "0|0|$(( $(echo "$A336_BEFORE" | tr ',' '\n' | grep -c .) - 2 ))"
mcp_as "$DRAFT_TOKEN" layout_queue '{}' > "$WORK/response"; mcp_text
expect "3.36 layout_queue: a drafts-only connection may list the queue (the tick off is a draft-class change of no content)" \
  "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo (int) isset($r["count"]);' -- "$WORK/text")" "1"
