echo "== 3.52 B4: search in the header – the Search element as an icon that opens the field (Popover API), a header template with it"
mcp builder_schema '{}' > "$WORK/response"
B830_TEMPLATE=$(mcp_value part_templates header s-hledanim | grep -c 'magnifier')
mcp builder_schema '{"elements":["search"]}' > "$WORK/response"
expect "3.52 B4: builder_schema lists the header template With search and the appearance (field | icon) of the Search element" \
  "$B830_TEMPLATE|$(grep -c '\\*"appearance\\*"' "$WORK/response")|$(grep -c '\\*"icon\\*"' "$WORK/response")" "1|1|1"
B830_HEADER=$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''")
mcp apply_part_template '{"part":"header","template":"s-hledanim"}' > "$WORK/response"
expect "3.52 B4: MCP apply_part_template – the search icon goes to the header draft, the published header stays" \
  "$(sq "SELECT stavba_koncept LIKE '%\"typ\":\"hledani\"%' AND stavba_koncept LIKE '%\"podoba\":\"ikona\"%' FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''")|$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = ''")" "1|$B830_HEADER"
curl -s -o "$WORK/b830.html" "$(mcp_value preview)"
expect "3.52 B4: the preview of the header – a magnifier button opens the popover with the field of a search form to /hledani, styled by the base CSS" \
  "$(grep -c 'class="ka-hledani-tl" popovertarget="hl-' "$WORK/b830.html")|$(grep -o '<form[^>]*ka-hledani--ikona[^>]*action="[^"]*/hledani"' "$WORK/b830.html" | wc -l | tr -d ' ')|$(grep -c '<div id="hl-[^"]*-pole" popover' "$WORK/b830.html")|$(grep -c '\.ka-hledani--ikona > \[popover\]:popover-open' "$WORK/b830.html")" \
  "1|1|1|1"
mcp discard_draft '{"part":"header"}' > /dev/null; sq "DELETE FROM ka_casti WHERE typ = 'hlavicka' AND jazyk = '' AND varianta = '' AND stavba IS NULL" > /dev/null
