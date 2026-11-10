echo "== 2.15: agent notebook"
mcp site_info '{}' > "$WORK/response"; NB_BEFORE=$(mcp_value notebook_count)
mcp write_notebook '{"topic":"style","title":"Nikdy slovo levný","text":"Píšeme „výhodný“ nebo „dostupný“, nikdy „levný“ – rozhodnutí klienta z 3. 10. 2026."}' > "$WORK/response"
NB_ID=$(mcp_value note id)
expect "notebook: Claude writes a note; the author is the name of its connection" "$([ -n "$NB_ID" ] && echo id)|$(mcp_value note author)|$(mcp_value note topic)|$(mcp_value note pinned)" "id|test|style|"
mcp write_notebook '{"topic":"credits","title":"Fotky z roku 2024","text":"Fotografie v sekci Reference nafotil interní tým, bez uvedení autora."}' > "$WORK/response"; NB_ID2=$(mcp_value note id)
mcp write_notebook "{\"id\":$NB_ID2,\"pinned\":true}" > "$WORK/response"
expect "notebook: a change by id keeps the other fields and pins the note" "$(mcp_value note title)|$(mcp_value note pinned)|$(mcp_value note topic)" "Fotky z roku 2024|1|credits"
mcp read_notebook '{}' > "$WORK/response"
expect "notebook: pinned first, then the most recently changed" "$(mcp_value notes 0 id)|$(mcp_value notes 1 id)|$(mcp_value count)" "$NB_ID2|$NB_ID|$((NB_BEFORE + 2))"
mcp read_notebook '{"search":"levný"}' > "$WORK/response"
expect "notebook: search in the title and text" "$(mcp_value count)|$(mcp_value notes 0 id)" "1|$NB_ID"
mcp read_notebook '{"topic":"credits"}' > "$WORK/response"
expect "notebook: filter by topic" "$(mcp_value count)|$(mcp_value notes 0 topic)" "1|credits"
mcp write_notebook '{"topic":"pricing","title":"x","text":"y"}' > "$WORK/response"
contains -q 'must be one of' "$WORK/response" && echo "  ok     notebook: an unknown topic is refused" || { echo "  CHYBA  notebook: unknown topic"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp write_notebook '{"title":"Bez textu"}' > "$WORK/response"
contains -q 'needs a text' "$WORK/response" && echo "  ok     notebook: a note without a text is refused" || { echo "  CHYBA  notebook: empty text"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"
expect "notebook: site_info counts the notes and names the pinned ones" "$(mcp_value notebook_count)|$(mcp_value notebook_pinned 0)" "$((NB_BEFORE + 2))|Fotky z roku 2024"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | contains 'read_notebook before larger changes' && echo "  ok     notebook: the server instructions tell Claude to read the notebook and write decisions down" || { echo "  CHYBA  notebook: server instructions"; ERRORS=$((ERRORS+1)); }
check "notebook: the admin list by topic with the pinned note first" 200 "/admin.php?module=notebook" "Fotky z roku 2024"
expect "notebook: the pinned note is above the newer one in the admin" "$(grep -o 'Fotky z roku 2024\|Nikdy slovo levný' "$WORK/response" | head -1)|$(grep -c 'Nikdy slovo levný' "$WORK/response")" "Fotky z roku 2024|1"
check "notebook: search in the admin" 200 "/admin.php?module=notebook&search=levn%C3%BD" "Nikdy slovo levný"
grep -q 'Fotky z roku 2024' "$WORK/response" && { echo "  CHYBA  notebook: the search still lists the other note"; ERRORS=$((ERRORS+1)); }
check "notebook: the admin filter by topic" 200 "/admin.php?module=notebook&topic=credits" "Fotky z roku 2024"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=save" -d "_csrf=$TOKEN" -d id=0 -d topic=decisions --data-urlencode "title=Klient je citlivý na stránku O nás" --data-urlencode "text=Texty na O nás schvaluje jednatel osobně."
NB_ID3=$(sq "SELECT id FROM ka_notebook WHERE title LIKE 'Klient je citliv%'")
expect "notebook: a note from the admin carries the user's name as its author" "$(sq "SELECT CONCAT(topic, '|', author, '|', pinned) FROM ka_notebook WHERE id = $NB_ID3")" "decisions|Tester|0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=pin" -d "_csrf=$TOKEN" -d "id=$NB_ID3"
expect "notebook: one click pins the note" "$(sq "SELECT pinned FROM ka_notebook WHERE id = $NB_ID3")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=save" -d "_csrf=$TOKEN" -d "id=$NB_ID3" -d topic=decisions --data-urlencode "title=Klient je citlivý na stránku O nás" --data-urlencode "text=Texty na O nás schvaluje jednatel osobně – i drobné změny." -d pinned=1
check "notebook: the edit form shows the changed text" 200 "/admin.php?module=notebook&action=edit&id=$NB_ID3" "i drobné změny"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; NB_EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/nb-export" "$B/admin.php?module=transfer&action=download&file=$NB_EXPORT"
if [ "${NB_EXPORT##*.}" = zip ]; then unzip -p "$WORK/nb-export" obsah.json > "$WORK/nb-obsah.json" 2>/dev/null || true; else cp "$WORK/nb-export" "$WORK/nb-obsah.json"; fi
grep -q '"notebook":\[' "$WORK/nb-obsah.json" && grep -q 'Klient je citlivý na stránku O nás' "$WORK/nb-obsah.json" && echo "  ok     notebook: the site export carries the notes (the import is checked in the 1.8 move)" || { echo "  CHYBA  notebook in the export"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=notebook"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=notebook&action=delete" -d "_csrf=$TOKEN" -d "id=$NB_ID3"
mcp delete_notebook_entry "{\"id\":$NB_ID}" > "$WORK/response"
expect "notebook: deleted in the admin and over MCP; the change log names both" "$(sq "SELECT COUNT(*) FROM ka_notebook WHERE id IN ($NB_ID, $NB_ID3)")|$(mcp_value count)|$(sq "SELECT GROUP_CONCAT(DISTINCT via ORDER BY via) FROM ka_protokol WHERE modul = 'notebook' AND akce = 'delete'")" "0|$((NB_BEFORE + 1))|,test"
mcp delete_notebook_entry '{"id":999999}' > "$WORK/response"
contains -q 'does not exist' "$WORK/response" && echo "  ok     notebook: deleting a note that does not exist is an error" || { echo "  CHYBA  notebook: delete of a missing note"; ERRORS=$((ERRORS+1)); }
