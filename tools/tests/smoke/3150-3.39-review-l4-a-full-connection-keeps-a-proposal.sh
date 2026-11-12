echo "== 3.39 review L4: a full Claude connection saving over a proposal leaves it a proposal"
L4_DRAFTS="kaleta_$(php -r 'echo bin2hex(random_bytes(24));')"
sq "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, access) SELECT idu, 'Drafts L4', SHA2('$L4_DRAFTS', 256), '$(site_time)', 'drafts' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
mcp_as "$L4_DRAFTS" write_notebook '{"topic":"decisions","title":"L4 proposal","text":"Send quotes without asking."}' > "$WORK/response"; L4_ID=$(mcp_value note id)
mcp write_notebook "{\"id\":${L4_ID:-0},\"text\":\"Send quotes without asking – confirmed.\",\"pinned\":true}" > "$WORK/response"
expect "3.39 review L4: the full connection's save keeps the note a proposal – not pinned, still marked as from a drafts-only connection" \
  "$(mcp_value note proposal)|$(sq "SELECT CONCAT(pinned, '/', access, '/', text) FROM ka_notebook WHERE id = ${L4_ID:-0}")" "1|0/drafts/Send quotes without asking – confirmed."
sq "DELETE FROM ka_notebook WHERE id = ${L4_ID:-0}; DELETE FROM ka_api_tokeny WHERE nazev = 'Drafts L4'" > /dev/null
