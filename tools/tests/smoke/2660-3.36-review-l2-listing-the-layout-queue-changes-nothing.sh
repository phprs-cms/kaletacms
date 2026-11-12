echo "== 3.36 review L2: listing the layout queue writes no change-log row and opens no session; a read-only connection reads it"
L2_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol"); L2_SESSIONS=$(sq "SELECT COUNT(*) FROM ka_agent_sessions")
mcp layout_queue '{}' > "$WORK/response"; mcp_text
L2_FIRST=$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo (int) isset($r["count"]);' -- "$WORK/text")
mcp layout_queue '{"done":[]}' > "$WORK/response"; mcp_text
L2_EMPTY=$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo (int) isset($r["count"]);' -- "$WORK/text")
expect "3.36 review L2: layout_queue without done (or with an empty list) lists the queue – no change-log row, no agent session" \
  "$L2_FIRST|$L2_EMPTY|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $L2_LOG")|$(sq "SELECT COUNT(*) FROM ka_agent_sessions")" "1|1|0|$L2_SESSIONS"
mcp_as "$READ_TOKEN" list_layout_queue '{}' > "$WORK/response"; mcp_text
L2_READ=$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo (int) isset($r["count"], $r["pages"]);' -- "$WORK/text")
mcp_as "$READ_TOKEN" layout_queue '{"done":[999999]}' > "$WORK/response"
expect "3.36 review L2: a read-only connection lists the queue with list_layout_queue, nothing logged; layout_queue with done stays refused there" \
  "$L2_READ|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $L2_LOG")|$(grep -c 'can only read the site' "$WORK/response")" "1|0|1"
L2_ONE=$(sq "SELECT s.ids FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.zdroj = 'layout' AND s.smazano IS NULL LIMIT 1")
if [ -n "$L2_ONE" ]; then
  mcp layout_queue "{\"done\":[$L2_ONE]}" > "$WORK/response"; mcp_text
  expect "3.36 review L2: … and layout_queue with done still ticks a page off and logs it" \
    "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo json_encode($r["marked"] ?? []);' -- "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $L2_LOG AND akce = 'layout marked done'")" "[{\"id\":$L2_ONE,\"done\":true}]|1"
fi
