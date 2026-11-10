echo "== ready-made templates of site parts (1.7)"
check "templates of the header" 200 "/admin.php?module=parts&action=templates&type=hlavicka" "Logo uprostřed"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema lists the part templates" "$(mcp_value part_templates header na-stred | grep -c 'Centred logo')|$(mcp_value part_templates footer tiraz | grep -c 'imprint')" "1|1"
PUBLISHEDFOOTER=$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")
mcp apply_part_template '{"part":"footer","template":"kompaktni"}' > "$WORK/response"
expect "MCP: a template goes to the draft, the published footer stays" "$(sq "SELECT stavba_koncept LIKE '%\"udaj\":\"copyright\"%' AND stavba_koncept NOT LIKE '%\"mrizka\"%' FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")|$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")" "1|$PUBLISHEDFOOTER"
curl -s -o "$WORK/footer.html" "$(mcp_value preview)"; grep -q '<footer' "$WORK/footer.html" && echo "  ok     MCP: the part preview shows the template" || { echo "  CHYBA  part template preview"; ERRORS=$((ERRORS+1)); }
mcp apply_part_template '{"part":"footer","template":"nothing"}' | contains 'Unknown template' && echo "  ok     MCP: an unknown template is refused" || { echo "  CHYBA  unknown template accepted"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=parts&action=templates&type=nenalezeno"
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?module=parts&action=apply_template&type=nenalezeno" -d "_csrf=$(csrf)" -d sablona=s-hledanim)
case "$code" in "302 "*"module=parts&action=builder&type=nenalezeno"*) echo "  ok     admin: a template opens in the builder";; *) echo "  CHYBA  admin apply template: $code"; ERRORS=$((ERRORS+1));; esac
expect "admin: the 404 wrapper got the search template as a draft" "$(sq "SELECT stavba_koncept LIKE '%\"typ\":\"hledani\"%' FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = ''")" "1"
mcp discard_draft '{"part":"footer"}' > /dev/null; sq "DELETE FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = '' AND stavba IS NULL"

