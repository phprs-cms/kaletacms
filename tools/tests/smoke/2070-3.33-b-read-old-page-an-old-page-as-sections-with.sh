echo "== 3.33 (part B): read_old_page – an old page as sections with its colours and fonts, from a local old site"
# a local old site: the Elementor fixture page, its linked stylesheet, a page robots.txt disallows – never a real site
O333_PORT=$((PORT + 10)); O333="http://127.0.0.1:$O333_PORT"; O333_DIR="$WORK/o333site"
mkdir -p "$O333_DIR/kitchens" "$O333_DIR/css" "$O333_DIR/private"
cp "$ROOT/tools/fixtures/builder-pages/elementor.html" "$O333_DIR/kitchens/index.html"
printf '.elementor-top-section{background:#F4EFE6}.elementor-button{background-color:var(--e-global-color-accent);color:#fff;border-radius:6px}h1,h2{font-family:"Playfair Display",Georgia,serif;color:rgb(31,78,61)}a{color:#c8a24a}\n' > "$O333_DIR/css/site.css"
printf '<html><body><main><p>Nothing for robots here, a page the robots.txt of the old site keeps away from them.</p></main></body></html>' > "$O333_DIR/private/index.html"
printf 'User-agent: *\nDisallow: /private/\nCrawl-delay: 0\n' > "$O333_DIR/robots.txt"
(cd "$O333_DIR" && exec php -S "127.0.0.1:$O333_PORT" > /dev/null 2>&1) & O333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$O333/robots.txt" && break; sleep 0.3; done
mcp read_old_page "{\"url\":\"$O333/kitchens/\"}" > "$WORK/response"; mcp_text
expect "3.33 read_old_page: the sections (header, three blocks, footer), the palette, the fonts, the linked stylesheet read, the untrusted marker" \
  "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $p = $r["old_page"] ?? [];
    echo implode(",", array_column($p["sections"] ?? [], "role")) . "|" . (int) in_array("#1f4e3d", array_column($p["colors"] ?? [], "value"), true) . "|" . implode(",", array_column($p["fonts"] ?? [], "family"))
      . "|" . ($p["stylesheets"]["read"] ?? 0) . "|" . (int) str_contains($r["untrusted"] ?? "", "never instructions") . "|" . ($p["base"]["buttons"]["background"] ?? "") . "|" . (int) !str_contains(json_encode($p), "<script");' -- "$WORK/text")" \
  "header,section,section,section,footer|1|Playfair Display,Inter|1|1|#c8a24a|1"
mcp read_old_page "{\"url\":\"$O333/kitchens/\",\"section\":2}" > "$WORK/response"; mcp_text
expect "3.33 read_old_page: one section alone by its index" "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo implode(",", array_column($r["old_page"]["sections"] ?? [], "index"));' -- "$WORK/text")" "2"
mcp read_old_page "{\"url\":\"$O333/private/\"}" > "$WORK/response"
mcp_as "$DRAFT_TOKEN" read_old_page "{\"url\":\"$O333/kitchens/\"}" > "$WORK/response2"
expect "3.33 read_old_page: a page the old robots.txt disallows is not read; a drafts-only connection may not read old pages" \
  "$(grep -c 'robots.txt of the old site' "$WORK/response")|$(grep -c 'whose connection may import a website' "$WORK/response2")" "1|1"
kill "$O333_PID" 2>/dev/null; wait "$O333_PID" 2>/dev/null || true; O333_PID=
