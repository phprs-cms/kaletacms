echo "== 3.50.1 C: confirmations before removals (UX-l1, l2), empty states and scrolling tables (UX-l3, l4), the guide's chapters for tabs (CPO-l2, l9)"
c3501_get() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
c3501_post() { local token; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?$1"; token=$(csrf)
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?$2" -d "_csrf=$token" "${@:3}"; }
c3501_site() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); '"$1"); }

# UX-l1: Back to this look asks first only while a draft look exists
C3501_DRAFT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")
C3501_NOW=$(site_time)
sq "INSERT INTO ka_look_versions (data, summary, author, created) VALUES ('{}', 'C3501 version', NULL, '$C3501_NOW')" > /dev/null
C3501_VERSION=$(sq "SELECT MAX(id) FROM ka_look_versions WHERE summary = 'C3501 version'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('look_draft', '{\"design_system\":{\"zaobleni\":\"l\"}}')" > /dev/null
C3501_WITH=$(c3501_get 'module=appearance' > /dev/null; grep -c 'action="[^"]*restore_look"[^>]*data-potvrdit="' "$WORK/response" || true)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('look_draft', '')" > /dev/null
C3501_WITHOUT=$(c3501_get 'module=appearance' > /dev/null; grep -c 'action="[^"]*restore_look"[^>]*data-potvrdit="' "$WORK/response" || true)
expect "3.50.1 C (UX-l1): Back to this look asks before replacing a draft look, and only while there is one" \
  "$([ "$C3501_WITH" -ge 1 ] && echo yes)|$C3501_WITHOUT" "yes|0"
sq "DELETE FROM ka_look_versions WHERE id = $C3501_VERSION" > /dev/null
if [ -n "$C3501_DRAFT" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('look_draft', '$(printf '%s' "$C3501_DRAFT" | sed "s/'/''/g")')" > /dev/null; fi

# UX-l2: the two Transfer removals ask first and answer with a flash; the record goes, nothing else
C3501_IDS=$(c3501_site '$r = Kaleta\Core\MigrationReport::newState("https://c3501-old.example"); Kaleta\Core\MigrationReport::save($r); $w = Kaleta\Core\WebImport::newState("https://c3501-web.example"); Kaleta\Core\WebImport::save($w); echo $r["id"], " ", $w["id"];')
C3501_REPORT=${C3501_IDS% *}; C3501_WEB=${C3501_IDS#* }
expect "3.50.1 C (UX-l2): Import and export – every report and web import on the list asks before it is removed" \
  "$(c3501_get 'module=transfer')|$(grep -c 'action="[^"]*report_delete"' "$WORK/response")|$(grep -c 'action="[^"]*report_delete"[^>]*data-potvrdit="' "$WORK/response")|$(grep -c 'action="[^"]*web_delete"' "$WORK/response")|$(grep -c 'action="[^"]*web_delete"[^>]*data-potvrdit="' "$WORK/response")" \
  "200|$(ls "$WORK"/web/storage/import/parita-*.json | wc -l | tr -d ' ')|$(ls "$WORK"/web/storage/import/parita-*.json | wc -l | tr -d ' ')|$(ls "$WORK"/web/storage/import/web-*.json | wc -l | tr -d ' ')|$(ls "$WORK"/web/storage/import/web-*.json | wc -l | tr -d ' ')"
C3501_R1=$(c3501_post 'module=transfer' 'module=transfer&action=report_delete' -d "id=$C3501_REPORT")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"
C3501_F1=$(grep -cE 'The report was removed\.|Zpráva byla odebrána\.' "$WORK/response" || true)
C3501_R2=$(c3501_post 'module=transfer' 'module=transfer&action=web_delete' -d "id=$C3501_WEB")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"
C3501_F2=$(grep -cE 'The import was removed from the list\.|Import byl odebrán ze seznamu\.' "$WORK/response" || true)
expect "3.50.1 C (UX-l2): both removals redirect, say what happened and remove the state file" \
  "$C3501_R1|$C3501_F1|$C3501_R2|$C3501_F2|$(ls "$WORK"/web/storage/import/parita-"$C3501_REPORT".json "$WORK"/web/storage/import/web-"$C3501_WEB".json 2>/dev/null | wc -l | tr -d ' ')" "302|1|302|1|0"

# UX-l2: a person's or the business's time off asks before it goes (the slots become bookable again)
C3501_FEATURES=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +bookings > /dev/null
sq "INSERT INTO ka_booking_off (staff_id, off_from, off_to, note) VALUES (NULL, '$(site_time '+20 days' 'Y-m-d 00:00:00')', '$(site_time '+21 days' 'Y-m-d 23:59:59')', 'C3501 off')" > /dev/null
expect "3.50.1 C (UX-l2): Bookings → People – removing a time off asks first" \
  "$(c3501_get 'module=bookings&action=staff')|$(grep -c 'action="[^"]*off_delete"[^>]*data-potvrdit="' "$WORK/response" | awk '{print ($1 >= 1)}')" "200|1"
sq "DELETE FROM ka_booking_off WHERE note = 'C3501 off'" > /dev/null
features --exact "$C3501_FEATURES" > /dev/null

# UX-l3, l4: a filtered change log without a match shows the empty state with the way back; the sessions table scrolls on a phone
expect "3.50.1 C (UX-l3): a change log filter with no match shows the empty state and a link to the whole log, no empty table" \
  "$(c3501_get 'module=changelog&search=c3501-nothing-matches-this')|$(grep -c 'class="prazdny-stav"' "$WORK/response")|$(grep -cE 'Show the whole log|Ukázat celý protokol' "$WORK/response")|$(grep -c '<table class="vypis">' "$WORK/response")" \
  "200|1|1|0"
C3501_SESSIONS=$(sq "SELECT COUNT(*) FROM ka_agent_sessions" 2>/dev/null || echo 0)
expect "3.50.1 C (UX-l3, l4): Claude sessions – the table inside tab-obal, or the empty state when there is none" \
  "$(c3501_get 'module=changelog&action=sessions')|$([ "${C3501_SESSIONS:-0}" -gt 0 ] && grep -c '<div class="tab-obal">' "$WORK/response" || grep -c 'class="prazdny-stav"' "$WORK/response")" "200|1"
expect "3.50.1 C (UX-l3, l4): Add-ons show the empty state, or their table inside tab-obal" \
  "$(c3501_get 'module=addons')|$(grep -c 'class="prazdny-stav"\|<div class="tab-obal">' "$WORK/response" | awk '{print ($1 >= 1)}')" "200|1"

# CPO-l2, l9: the guide in the administration – My account and Settings tabs open their chapters, Privacy is in the index
expect "3.50.1 C (CPO-l9): How to use this on My account → Absence and on Settings → Security opens a chapter of the guide; the signature keeps its article" \
  "$(c3501_get 'action=account&tab=absence')|$(grep -c 'action=guide&amp;chapter=account' "$WORK/response" | awk '{print ($1 >= 1)}')|$(c3501_get 'module=settings&tab=security' > /dev/null; grep -c 'action=guide&amp;chapter=site-settings' "$WORK/response" | awk '{print ($1 >= 1)}')|$(c3501_get 'action=account&tab=signature' > /dev/null; grep -c 'guide/email#your-e-mail-signature' "$WORK/response" | awk '{print ($1 >= 1)}')" \
  "200|1|1|1"
expect "3.50.1 C (CPO-l2, l9): the guide's index lists Privacy and My account; the new chapters open with a link to their screen" \
  "$(c3501_get 'action=guide')|$(grep -c 'chapter=privacy"' "$WORK/response" | awk '{print ($1 >= 1)}')|$(grep -c 'chapter=account"' "$WORK/response" | awk '{print ($1 >= 1)}')|$(c3501_get 'action=guide&chapter=site-settings')|$(grep -c 'href="[^"]*module=settings&amp;tab=sections"' "$WORK/response")|$(c3501_get 'action=guide&chapter=claude-notes')|$(c3501_get 'action=guide&chapter=changelog')" \
  "200|1|1|200|1|200|200"
