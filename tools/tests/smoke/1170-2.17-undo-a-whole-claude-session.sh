echo "== 2.17: undo a whole Claude session"
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp vytvor_stranku '{"titulek":"Undo original","zobrazit":false}' > "$WORK/response"; mcp_text; UNDO_OLD=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
mcp update_page "{\"id\":$UNDO_OLD,\"title\":\"Changed by Claude\"}" > /dev/null
mcp save_build "{\"id\":$UNDO_OLD,\"build\":{\"v\":1,\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft by Claude\"}}]}}" > /dev/null
mcp vytvor_stranku '{"titulek":"Undo new page","zobrazit":false}' > "$WORK/response"; mcp_text; UNDO_NEW=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp vytvor_stranku '{"titulek":"Undo conflict","zobrazit":false}' > "$WORK/response"; mcp_text; UNDO_C=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_stranky SET titulek = 'Edited by a person' WHERE ids = $UNDO_C" > /dev/null
UNDO_S=$(sq "SELECT MAX(id) FROM ka_agent_sessions")
mcp list_agent_sessions '{"limit":3}' > "$WORK/response"; mcp_text
contains -q "\"id\":$UNDO_S," "$WORK/text" && contains -q 'save_build' "$WORK/text" && echo "  ok     undo: the session lists its changes and tools" || { echo "  CHYBA  list_agent_sessions"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp undo_agent_session "{\"id\":$UNDO_S}" > "$WORK/response"
contains -q 'confirm=true' "$WORK/response" && echo "  ok     undo: needs an explicit confirmation" || { echo "  CHYBA  undo confirm"; ERRORS=$((ERRORS+1)); }
mcp undo_agent_session "{\"id\":$UNDO_S,\"confirm\":true}" > "$WORK/response"; mcp_text
expect "undo: the original page has its title and no build again, the new page is gone, the page a person edited since stays and is reported" \
  "$(sq "SELECT CONCAT(titulek, '|', stavba_koncept IS NULL) FROM ka_stranky WHERE ids = $UNDO_OLD")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE ids = $UNDO_NEW")|$(sq "SELECT titulek FROM ka_stranky WHERE ids = $UNDO_C")|$(grep -c "\"conflicts\":\[{\"table\":\"stranky\"" "$WORK/text")" \
  "Undo original|1|0|Edited by a person|1"
expect "undo: the session is marked undone and cannot be undone twice" "$(sq "SELECT undone_at IS NOT NULL FROM ka_agent_sessions WHERE id = $UNDO_S")|$(mcp undo_agent_session "{\"id\":$UNDO_S,\"confirm\":true}" | grep -c 'already undone')" "1|1"
check "undo: the change log lists Claude sessions with the undo button" 200 "/admin.php?module=changelog&action=sessions" "action=undo"
# 3.10 (N310-5): writes through the English table names (an add-on following docs/EXTENSIONS.md) are journaled under
# today's name and undone with the session – an insert (redirects), an update (pages) and a delete (tags)
sq "INSERT INTO ka_stitky (nazev, seo_link) VALUES ('N310-5 tag', 'n310-5-tag')" > /dev/null
TAG3105=$(sq "SELECT ids FROM ka_stitky WHERE seo_link = 'n310-5-tag'")
S3105=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $db = Kaleta\Core\App::boot()->db();
  $db->journal = Kaleta\Core\AgentJournal::start($db, "n310-5", "ext_test_write");
  $db->insert("redirects", ["z_adresy" => "n310-5-old", "na_adresu" => "n310-5-new", "vytvoreno" => Kaleta\Core\Clock::now()]);
  $db->update("pages", ["titulek" => "N310-5 changed"], ["ids" => (int) $argv[1]]);
  $db->delete("tags", ["ids" => (int) $argv[2]]);
  echo $db->journal?->session ?? 0;' -- "$UNDO_OLD" "$TAG3105")
expect "3.10 N310-5: an insert, an update and a delete through English table names are journaled under today's names" \
  "$(sq "SELECT GROUP_CONCAT(tbl ORDER BY id) FROM ka_agent_journal WHERE session_id = ${S3105:-0} AND untracked IS NULL")" "presmerovani,stranky,stitky"
mcp undo_agent_session "{\"id\":${S3105:-0},\"confirm\":true}" > /dev/null
expect "3.10 N310-5: … and undoing the session takes all three back" \
  "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'n310-5-old'")|$(sq "SELECT titulek FROM ka_stranky WHERE ids = $UNDO_OLD")|$(sq "SELECT nazev FROM ka_stitky WHERE ids = $TAG3105")" "0|Undo original|N310-5 tag"
sq "DELETE FROM ka_stitky WHERE ids = $TAG3105" > /dev/null
sq "UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'" > /dev/null
