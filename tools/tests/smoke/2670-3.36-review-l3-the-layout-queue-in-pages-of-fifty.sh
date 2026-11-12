echo "== 3.36 review L3: the layout queue lists fifty pages at a time with the total, the old addresses in one query"
l3php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); '"$1" -- "${@:2}"); }
L3_BEFORE=$(l3php 'echo Kaleta\Core\ImportLayout::queue($app->db())["total"];')
L3_BUILD='{"v":1,"deti":[{"id":"a1","typ":"text","obsah":{"html":"<p>L3</p>"}}]}'
# 55 imported pages that wait, from web:old-l3.example.test
l3php '$db = $app->db(); for ($i = 1; $i <= 55; $i++) { $db->run("INSERT INTO {stranky} (titulek, seo_link, text, zmeneno, stavba) VALUES (?, ?, ?, NOW(), ?)", ["L3 $i", "l3-page-$i", "<p>L3</p>", $argv[1]]);
  $id = (int) $db->pdo()->lastInsertId(); Kaleta\Core\ImportLayout::remember($db, $id, $argv[1]); $db->run("INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?)", ["web:old-l3.example.test", "stranka", "l3-$i", $id]); }' "$L3_BUILD"
L3_PAGES=$(sq "SELECT GROUP_CONCAT(ids) FROM ka_stranky WHERE seo_link LIKE 'l3-page-%'")
# the old address of l3-page-1: a guess of the daily job and a WordPress ?p=… come first by id, the import's pretty address wins
sq "INSERT INTO ka_presmerovani (z_adresy, na_adresu, typ, auto_score, vytvoreno) VALUES ('l3-guess', 'l3-page-1', 301, 80, NOW()), ('?p=4242', 'l3-page-1', 301, NULL, NOW()), ('old-l3-pretty', '/l3-page-1', 301, NULL, NOW()), ('l3-other-*', 'l3-page-2', 301, NULL, NOW())" > /dev/null
expect "3.36 review L3: the first call lists 50 pages with the total and the next offset, the second the rest; together every waiting page once; the import's pretty old address wins" \
  "$(l3php '$db = $app->db(); $one = Kaleta\Core\ImportLayout::queue($db); $two = Kaleta\Core\ImportLayout::queue($db, 0, 50); $ids = array_merge(array_column($one["pages"], "id"), array_column($two["pages"], "id"));
    $mine = array_map("intval", explode(",", $argv[1])); $all = array_column(array_merge($one["pages"], $two["pages"]), "old_url", "slug");
    echo count($one["pages"]) . "|" . ($one["total"] - (int) $argv[2]) . "|" . (count($two["pages"]) === $one["total"] - 50 ? "rest" : "?") . "|" . (count(array_diff($mine, $ids)) === 0 && count($ids) === count(array_unique($ids)) ? "all once" : "?") . "|" . ($all["l3-page-1"] ?? "") . "|" . ($all["l3-page-2"] ?? "");' "$L3_PAGES" "$L3_BEFORE")" \
  "50|55|rest|all once|https://old-l3.example.test/old-l3-pretty|https://old-l3.example.test/l3-page-2"
expect "3.36 review L3: one call of the queue runs four statements, however many pages it lists (the redirects in one query)" \
  "$(l3php '$db = $app->db(); $n = $db->queryCount; Kaleta\Core\ImportLayout::queue($db); echo $db->queryCount - $n;')" "4"
mcp layout_queue '{}' > "$WORK/response"; mcp_text
L3_MCP=$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo count($r["pages"] ?? []) . "|" . ($r["next_offset"] ?? "-") . "|" . (($r["count"] ?? 0) >= 55 ? "total" : "?");' "$WORK/text")
mcp list_layout_queue '{"offset":50}' > "$WORK/response"; mcp_text
expect "3.36 review L3: layout_queue lists 50 with next_offset and count = every waiting page; list_layout_queue with offset gives the rest without next_offset" \
  "$L3_MCP|$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo (count($r["pages"] ?? []) === ($r["count"] ?? -1) - 50 ? "rest" : "?") . "|" . ($r["next_offset"] ?? "-");' "$WORK/text")" "50|50|total|rest|-"
sq "DELETE FROM ka_presmerovani WHERE z_adresy IN ('l3-guess', '?p=4242', 'old-l3-pretty', 'l3-other-*'); DELETE FROM ka_import_mapa WHERE nase_id IN ($L3_PAGES) AND (zdroj = 'layout' OR zdroj = 'web:old-l3.example.test');
  DELETE FROM ka_stranky WHERE ids IN ($L3_PAGES)" > /dev/null
