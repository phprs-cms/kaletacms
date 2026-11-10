echo "== 2.15: requests to Claude (Core\\Requests) – staff ask, Claude drafts, a person publishes"
# a staff user with the Requests section only; the administrator has an address for the notification
sq "UPDATE ka_uzivatele SET email = 'spravce-f19@example.cz' WHERE user = 'admin'" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=users&action=new"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Recepce -d user=recepce --data-urlencode email=recepce@example.cz --data-urlencode "password=$PASSWORD" -d admin=0 -d rucne=1 -d 'moduly[]=requests'
expect "requests: the staff user has the Requests section only" "$(sq "SELECT GROUP_CONCAT(p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'recepce'")" "requests"
JAR_REQ="$WORK/jar-requests"
REQ_CSRF=$(curl -s -c "$JAR_REQ" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$REQ_CSRF" -d user=recepce --data-urlencode "password=$PASSWORD"
code=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=requests&action=new"); expect "requests: the staff user opens the form" "$code" 200
grep -q 'name="prilohy\[\]"' "$WORK/response" && grep -q 'value="page:1"' "$WORK/response" && echo "  ok     requests: the form offers attachments and the pages to choose from" || { echo "  CHYBA  request form"; ERRORS=$((ERRORS+1)); }
REQ_CSRF=$(csrf)
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$WORK/cenik-f19.pdf"
REQ_URL=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=requests&action=save" -F "_csrf=$REQ_CSRF" -F "title=Nový ceník na stránku Služby" -F "text=Prosím nahraďte starý ceník přiloženým PDF." -F "about=page:1" -F "about_url=" -F "prilohy[]=@$WORK/cenik-f19.pdf;type=application/pdf")
REQ_ID=$(printf %s "$REQ_URL" | grep -o 'id=[0-9]*' | sed 's/id=//' || true); REQ_ID="${REQ_ID:-0}"
expect "requests: saved as new, the PDF is a Media upload, the event is recorded, the administrator got the title by e-mail" \
  "$(sq "SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_media WHERE obr_poloha LIKE 'media/%cenik-f19%' AND ido = JSON_EXTRACT(r.attachments, '\$[0]')), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'request.created' AND data LIKE '%\"id\":$REQ_ID,%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'spravce-f19@example.cz' AND predmet LIKE '%Nový ceník na stránku Služby%')) FROM ka_requests r WHERE r.id = $REQ_ID")" "new|1|1|1"
check "requests: the list opens with the new request first" 200 "/admin.php?module=requests" "Nový ceník na stránku Služby"
# Claude reads it with the attachment's address and answers – in progress, then done with links; only web addresses are kept
mcp list_requests '{}' > "$WORK/response"; mcp_text
contains -q "\"id\":$REQ_ID," "$WORK/text" && contains -q "\"url\":\"$B/media/" "$WORK/text" && contains -q '"author":"Recepce"' "$WORK/text" && contains -q '"written_by_staff"' "$WORK/text" && echo "  ok     requests: list_requests returns it with the Media url and the warning that staff wrote it" || { echo "  CHYBA  list_requests"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp update_request "{\"id\":$REQ_ID,\"status\":\"in_progress\",\"note\":\"Dívám se na to.\"}" > "$WORK/response"; mcp_text
expect "requests: update_request marks it in progress with a note" "$(sq "SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = r.id AND sender = 'claude' AND text = 'Dívám se na to.')) FROM ka_requests r WHERE r.id = $REQ_ID")" "in_progress|1"
mcp update_request "{\"id\":$REQ_ID,\"status\":\"declined\"}" > "$WORK/response"
contains -q 'needs a note' "$WORK/response" && echo "  ok     requests: declining without a reason is refused" || { echo "  CHYBA  update_request declined without note"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_request "{\"id\":$REQ_ID,\"status\":\"done\",\"note\":\"Ceník je v konceptu stránky Služby.\",\"links\":[{\"label\":\"Služby – koncept\",\"url\":\"$B/sluzby\"},{\"url\":\"javascript:alert(1)\"}]}" > "$WORK/response"; mcp_text
contains -q '"requester_notified":true' "$WORK/text" && echo "  ok     requests: done – the result says the requester was notified" || { echo "  CHYBA  update_request done"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "requests: done with the web link only, the requester got the note by e-mail" \
  "$(sq "SELECT CONCAT(r.status, '|', r.done_at IS NOT NULL, '|', (SELECT links LIKE '%$B/sluzby%' AND links NOT LIKE '%javascript%' FROM ka_request_messages WHERE request_id = r.id ORDER BY id DESC LIMIT 1), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'recepce@example.cz' AND predmet LIKE '%Nový ceník na stránku Služby%')) FROM ka_requests r WHERE r.id = $REQ_ID")" "done|1|1|1"
# the requester reads the note and the link in the detail and replies; Claude reads the reply with the request
code=$(curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=requests&action=detail&id=$REQ_ID")
[ "$code" = 200 ] && grep -q 'Ceník je v konceptu stránky Služby.' "$WORK/response" && grep -q "href=\"$B/sluzby\"" "$WORK/response" && grep -q 'cenik-f19' "$WORK/response" && echo "  ok     requests: the requester's detail shows Claude's note, the draft link and the attachment" || { echo "  CHYBA  request detail: kód $code"; ERRORS=$((ERRORS+1)); }
REQ_CSRF=$(csrf)
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php?module=requests&action=reply" -d "_csrf=$REQ_CSRF" -d "id=$REQ_ID" --data-urlencode "text=Díky, zveřejním to."
mcp list_requests "{\"id\":$REQ_ID}" > "$WORK/response"; mcp_text
contains -q '"from":"person","name":"Recepce","text":"Díky, zveřejním to."' "$WORK/text" && echo "  ok     requests: the reply is in the conversation Claude reads" || { echo "  CHYBA  request reply"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp list_requests '{"status":"open"}' > "$WORK/response"; mcp_text
contains -q "\"id\":$REQ_ID," "$WORK/text" && { echo "  CHYBA  a done request is listed among the open ones"; ERRORS=$((ERRORS+1)); } || echo "  ok     requests: a done request is not among the open ones"
# a drafts-only connection answers requests (it only writes notes about drafts) but still cannot publish
mcp_as "$DRAFT_TOKEN" update_request "{\"id\":$REQ_ID,\"status\":\"in_progress\",\"note\":\"Reopened by a drafts connection\"}" > "$WORK/response"
expect "requests: a drafts-only connection reopens the request with a note" "$(sq "SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = $REQ_ID AND text = 'Reopened by a drafts connection')) FROM ka_requests WHERE id = $REQ_ID")" "in_progress|1"
mcp_as "$DRAFT_TOKEN" publish_build '{"id":1}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     requests: the same drafts-only connection cannot publish" || { echo "  CHYBA  drafts connection published"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# a person closes it from the detail; a request of a user without the section is refused over MCP
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o "$WORK/response" "$B/admin.php?module=requests&action=detail&id=$REQ_ID"; REQ_CSRF=$(csrf)
curl -s -b "$JAR_REQ" -c "$JAR_REQ" -o /dev/null -X POST "$B/admin.php?module=requests&action=status" -d "_csrf=$REQ_CSRF" -d "id=$REQ_ID" -d status=done
expect "requests: the person marks it done in the detail" "$(sq "SELECT status FROM ka_requests WHERE id = $REQ_ID")" "done"
expect "requests: a user without the section gets a 403" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=requests")" 403
