echo "== 3.33 (part B): \"layout not rebuilt yet\" – the work queue of a move in list_pages and the admin list of pages"
# the builder pages of 2050 were imported: their builds are still the imported text
L333_EL=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'elementor' AND smazano IS NULL"); L333_DIVI=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'divi' AND smazano IS NULL")
l333_flags() { mcp list_pages '{}' > "$WORK/response"; mcp_text; php -r '$r = json_decode(file_get_contents($argv[1]), true); $f = array_column(array_filter($r, fn ($p) => !empty($p["layout_not_rebuilt"])), "id"); echo implode(",", array_map(fn ($i) => (int) in_array((int) $i, $f, true), array_slice($argv, 2)));' "$WORK/text" "$@"; }
L333_HOME=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")
expect "3.33 layout_not_rebuilt: list_pages flags the imported pages whose build is still the imported text, never another page" "$(l333_flags "$L333_EL" "$L333_DIVI" "$L333_HOME")" "1,1,0"
L333_HEADING=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -r -e "SELECT stavba FROM ka_stranky WHERE ids = $L333_EL" | php -r '$b = json_decode(stream_get_contents(STDIN), true); echo $b["deti"][0]["deti"][0]["id"] ?? "";')
mcp edit_build "{\"id\":$L333_EL,\"operations\":[{\"op\":\"update\",\"id\":\"$L333_HEADING\",\"content\":{\"text\":\"Bespoke kitchens by hand\"}}]}" > /dev/null
L333_AFTER_EDIT=$(l333_flags "$L333_EL")
mcp discard_draft "{\"id\":$L333_EL}" > /dev/null
L333_AFTER_DISCARD=$(l333_flags "$L333_EL")
mcp build_from_html "{\"id\":$L333_DIVI,\"html\":\"<section><h1>Garden rooms</h1><p>Rebuilt.</p></section>\"}" > /dev/null; mcp publish_build "{\"id\":$L333_DIVI}" > /dev/null
expect "3.33 layout_not_rebuilt: an edit of the build clears it, discarding that draft brings it back (nothing was rebuilt), a rebuilt and published page stays clear" \
  "$L333_AFTER_EDIT|$L333_AFTER_DISCARD|$(l333_flags "$L333_DIVI")" "0|1|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
expect "3.33 layout_not_rebuilt: the admin list of pages labels the waiting imported pages (in Czech here), not the rebuilt one" \
  "$(grep -c "data-layout-not-rebuilt=\"$L333_EL\"" "$WORK/response")|$(grep -c "data-layout-not-rebuilt=\"$L333_DIVI\"" "$WORK/response")|$(grep -c 'rozložení zatím nepřestavěné' "$WORK/response")" "1|0|$(sq "SELECT COUNT(*) FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.zdroj = 'layout' AND s.smazano IS NULL AND s.stavba_koncept IS NULL AND m.cizi_id = CONCAT(s.ids, ':', SHA2(s.stavba, 256))")"
