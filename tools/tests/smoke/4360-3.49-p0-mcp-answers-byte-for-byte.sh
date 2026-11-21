echo "== 3.49 P0: the MCP definitions next to their handlers – tools/list of every tool set and access, Customers off, Pipeline off and find_tools answer byte for byte as v3.48.0 did"
# tools/mcp-record.php records the test site as it recorded v3.48.0 into tools/contracts/mcp-record/ (every feature on, temporary
# personal tokens of the administrator, everything put back). A deliberate change of a definition: MCP_RECORD=update ONLY=4360
# tools/test.sh copies the new answers over the record – commit them with the tools/contracts.php --update diff.
P0_OUT="$WORK/mcp-record"; P0_DIFF=""; P0_FEATURES=$(sq "SELECT COALESCE(MAX(hodnota), '(none)') FROM ka_nastaveni WHERE promenna = 'extensions'")
if php "$ROOT/tools/mcp-record.php" --site="$WORK/web" --url="$B" --out="$P0_OUT" > "$WORK/mcp-record.log" 2>&1; then
  for P0_FILE in "$ROOT"/tools/contracts/mcp-record/*.json; do
    cmp -s "$P0_FILE" "$P0_OUT/${P0_FILE##*/}" || P0_DIFF="$P0_DIFF ${P0_FILE##*/}"
  done
  expect "3.49 P0: 19 answers recorded from the test site, every one equal to the stored answer (cmp)" "$(find "$P0_OUT" -name '*.json' | wc -l | tr -d ' ')|${P0_DIFF:- none}" "19| none"
  if [ -n "$P0_DIFF" ] && [ "${MCP_RECORD:-}" = update ]; then cp "$P0_OUT"/*.json "$ROOT/tools/contracts/mcp-record/"; echo "  --     MCP_RECORD=update: the new answers are in tools/contracts/mcp-record – read the diff before committing it"; fi
  expect "3.49 P0: the recording leaves no token and the features as they were" "$(sq "SELECT COUNT(*) FROM ka_api_tokeny WHERE nazev = 'mcp-record'")|$(sq "SELECT COALESCE(MAX(hodnota), '(none)') FROM ka_nastaveni WHERE promenna = 'extensions'")" "0|$P0_FEATURES"
else
  echo "  CHYBA  3.49 P0: the recorder failed: $(head -c 300 "$WORK/mcp-record.log")"; ERRORS=$((ERRORS+1))
fi
