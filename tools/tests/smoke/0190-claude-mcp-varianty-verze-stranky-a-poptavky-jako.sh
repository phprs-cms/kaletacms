echo "== Claude (MCP): varianty, verze, stránky a poptávky jako v administraci"
# a value from an MCP response: mcpv key [key…] (arrays and objects as JSON)
mcp_value() { php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; } echo is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$WORK/response" "$@"; }
mcp seznam_casti '{}' > "$WORK/response"; [[ "$(mcp_value)" == *'"varianta":"landing-page"'* ]] && echo "  ok     MCP: seznam částí webu s variantami" || { echo "  CHYBA  MCP seznam_casti"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"nazev\":\"Kampaň\",\"stranky\":[$IDZ]}" > "$WORK/response"; VARIANT=$(mcp_value varianta)
expect "MCP: varianta patičky založena" "$VARIANT|$(mcp_value stranky)" "kampan|[$IDZ]"
mcp stavba_uloz "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"znacka\":\"footer\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"p\",\"obsah\":{\"text\":\"Paticka kampane\"}}]}]}}" > "$WORK/response"
VARIANT_PREVIEW=$(mcp_value nahled); curl -s -o "$WORK/response" "$VARIANT_PREVIEW"
[[ "$VARIANT_PREVIEW" == *"variant=$VARIANT"* ]] && grep -q 'Paticka kampane' "$WORK/response" && echo "  ok     MCP: podepsaný náhled konceptu varianty" || { echo "  CHYBA  náhled varianty: $VARIANT_PREVIEW"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\"}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; grep -q 'Paticka kampane' "$WORK/response" && ! curl -s "$B/kontakt" | contains 'Paticka kampane' && echo "  ok     MCP: publikovaná varianta patičky jen na vybrané stránce" || { echo "  CHYBA  varianta patičky z MCP"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"smazat\":true}" > /dev/null
expect "MCP: varianta smazána" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE varianta = '$VARIANT'")" 0
mcp stavba_z_html '{"titulek":"Verze test","html":"<section><h1>Verze A</h1></section>","publikovat":true}' > /dev/null
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'verze-test'")
mcp stavba_z_html "{\"id\":$IDV,\"html\":\"<section><h1>Verze B</h1></section>\",\"publikovat\":true}" > /dev/null
mcp stavba_verze "{\"id\":$IDV}" > "$WORK/response"; IDR=$(mcp_value verze 0 idr)
mcp obnov_verzi "{\"id\":$IDV,\"idr\":$IDR}" > /dev/null
expect "MCP: starší verze v konceptu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba_koncept LIKE '%Verze A%', stavba LIKE '%Verze B%') FROM ka_stranky WHERE ids = $IDV")" 11
# 3.25 (U17): the draft history in list_build_versions – published versions first (kind "published"), then Claude's autosaves (kind "draft")
mcp list_build_versions "{\"id\":$IDV}" > "$WORK/response"
php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true)["versions"] ?? [];
  $k = array_column($v, "kind"); $d = array_values(array_filter($v, fn ($x) => $x["kind"] === "draft"));
  exit($k[0] === "published" && count($d) === 2 && str_ends_with((string) $d[0]["who"], "Claude") && isset($v[0]["version_id"], $v[0]["when"]) ? 0 : 1);' "$WORK/response" \
  && echo "  ok     3.25 MCP: list_build_versions adds the draft history (kind draft, via Claude) after the published versions" || { echo "  CHYBA  3.25 MCP list_build_versions: $(mcp_value)"; ERRORS=$((ERRORS+1)); }
DRAFT_B=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDV AND cast = 'draft~mcp' AND stavba LIKE '%Verze B%' ORDER BY idr DESC LIMIT 1")
mcp restore_build_version "{\"id\":$IDV,\"version_id\":${DRAFT_B:-0}}" > /dev/null
expect "3.25 MCP: restore_build_version takes a draft history entry too; a person's and Claude's entries never mix" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba_koncept LIKE '%Verze B%', '|', (SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDV AND cast = 'draft')) FROM ka_stranky WHERE ids = $IDV")" "1|0"
mcp zahod_koncept "{\"id\":$IDV}" > /dev/null
expect "MCP: koncept zahozen" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept IS NULL FROM ka_stranky WHERE ids = $IDV")" 1
mcp vytvor_stranku "{\"titulek\":\"Podstranka MCP\",\"nadrazena\":$IDS,\"zverejnit_od\":\"2099-01-01 10:00\"}" > "$WORK/response"
expect "MCP: podstránka s plánovaným zveřejněním" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(seo_link, '|', zverejnit_od, '|', zobrazit) FROM ka_stranky WHERE titulek = 'Podstranka MCP'")" "o-nas/podstranka-mcp|2099-01-01 10:00:00|0"
mcp seznam_poptavek '{"stav":"vse"}' > "$WORK/response"
expect "MCP: poptávky s kampaní" "$(mcp_value 0 email)|$(mcp_value 0 kampan)" "jana@example.cz|newsletter / email / jaro"

