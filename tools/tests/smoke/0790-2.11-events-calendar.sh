echo "== 2.11: events calendar"
mcp create_collection '{"name":"Akce test","preset":"events"}' > "$WORK/response"
EVENTS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'akce-test' AND preset = 'events'")
[ -n "$EVENTS_IDK" ] && contains -q 'list_page' "$WORK/response" && echo "  ok     events: the preset creates the calendar and its list page" || { echo "  CHYBA  events preset"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "events: the repetition is a choice of known options and the item template has the registration form" "$(sq "SELECT CONCAT(JSON_LENGTH(JSON_EXTRACT(pole, '\$[12].moznosti')), '|', stavba LIKE '%\"typ\":\"formular\"%', '|', stavba LIKE '%{{ical}}%') FROM ka_kolekce WHERE idk = $EVENTS_IDK")" "5|1|1"
TOMORROW_D=$(site_date tomorrow); EIGHT_AGO=$(site_date '-8 days')
SIX_AHEAD=$(site_date '+6 days'); YESTERDAY_D=$(site_date yesterday)
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Jóga, pro začátečníky\",\"slug\":\"joga\",\"values\":{\"start\":\"$TOMORROW_D 18:00\",\"end\":\"$TOMORROW_D 19:30\",\"venue\":\"Sál\",\"address\":\"Hlavní 1, Brno\",\"capacity\":\"1\",\"repeat\":\"weekly\",\"summary\":\"Přineste si podložku.\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Minulá přednáška\",\"slug\":\"minula\",\"values\":{\"start\":\"$YESTERDAY_D 10:00\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Seriál\",\"slug\":\"serial\",\"values\":{\"start\":\"$EIGHT_AGO 18:00\",\"end\":\"$EIGHT_AGO 19:30\",\"repeat\":\"weekly\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Nesmysl\",\"slug\":\"nesmysl\",\"values\":{\"repeat\":\"každé úterý\"}}" > "$WORK/response"
contains -q 'invalid_fields.*repeat' "$WORK/response" && expect "events: a repetition outside the options is refused" "$(sq "SELECT data->>'\$.repeat' FROM ka_kolekce_polozky WHERE idk = $EVENTS_IDK AND seo_link = 'nesmysl'")" "" \
  || { echo "  CHYBA  volba mimo možnosti"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('events', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "events: the job moves an ended weekly event to its next date, keeping the time" "$(sq "SELECT CONCAT(data->>'\$.start', '|', data->>'\$.end') FROM ka_kolekce_polozky WHERE idk = $EVENTS_IDK AND seo_link = 'serial'")" "$SIX_AHEAD 18:00|$SIX_AHEAD 19:30"
grep -q 'events: moved 1' "$WORK/tasks.txt" && echo "  ok     events: the job reports what it moved" || { echo "  CHYBA  events job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'akce-test'"),\"visible\":true}" > /dev/null
curl -s -o "$WORK/response" "$B/akce-test"
grep -q 'Jóga, pro začátečníky' "$WORK/response" && grep -q 'Seriál' "$WORK/response" && ! grep -q 'Minulá přednáška' "$WORK/response" && grep -q 'Sál, Hlavní 1, Brno' "$WORK/response" \
  && echo "  ok     events: the list page shows upcoming events with their date and place, not the past one" || { echo "  CHYBA  seznam akcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/formular.html" "$B/akce-test/joga"
grep -q '"@type":"Event"' "$WORK/formular.html" && grep -q 'OfflineEventAttendanceMode' "$WORK/formular.html" && grep -q 'href="[^"]*akce-test/joga.ics"' "$WORK/formular.html" && grep -q 'class="ka-formular"' "$WORK/formular.html" \
  && echo "  ok     events: the event page has Event data, an Add to calendar link and the registration form" || { echo "  CHYBA  stránka akce"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/response" "$B/akce-test.ics"
grep -qi 'content-type: text/calendar' "$WORK/headers" && grep -q 'SUMMARY:Jóga\\, pro začátečníky' "$WORK/response" && grep -q 'RRULE:FREQ=WEEKLY' "$WORK/response" && grep -q 'LOCATION:Sál\\, Hlavní 1\\, Brno' "$WORK/response" \
  && echo "  ok     events: /<collection>.ics is a calendar to subscribe to" || { echo "  CHYBA  iCal kolekce"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/response" "$B/akce-test/joga.ics"
grep -qi 'content-disposition: attachment; filename="joga.ics"' "$WORK/headers" && [ "$(grep -c 'BEGIN:VEVENT' "$WORK/response")" = 1 ] && echo "  ok     events: one event as a file to add" || { echo "  CHYBA  iCal akce"; ERRORS=$((ERRORS+1)); }
expect "events: a collection that is not a calendar has no .ics" "$(curl -s -o /dev/null -w '%{http_code}' "$B/typy-poli.ics")" "404"
# registration: capacity 1 – the first registration fills it, the form closes and the server refuses another one
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(field_value prvek || true); FORM_TIME=$(field_value as_cas || true); FORM_SIGNATURE=$(field_value as_podpis || true); sleep 4
register() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/akce-test/joga" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d p0=Eva --data-urlencode "p1=$1" -d p4=1; }
case "$(register eva@example.cz)" in *result=ok*) echo "  ok     events: a registration is accepted";; *) echo "  CHYBA  registrace na akci"; ERRORS=$((ERRORS+1));; esac
expect "events: the registration is an enquiry from the event's page" "$(sq "SELECT CONCAT(zdroj, '|', stranka) FROM ka_poptavky ORDER BY idp DESC LIMIT 1")" "kolekce:$EVENTS_IDK|/akce-test/joga"
case "$(register petr@example.cz)" in *result=plno*) echo "  ok     events: a full event refuses another registration on the server";; *) echo "  CHYBA  plná akce přijala registraci"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/akce-test/joga"
grep -q 'Akce je plně obsazená.' "$WORK/response" && ! grep -q 'class="ka-formular"' "$WORK/response" && echo "  ok     events: the page of a full event shows it is full instead of the form" || { echo "  CHYBA  plná akce stále ukazuje formulář"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items '{"collection":"akce-test"}' > "$WORK/response"
contains -q 'state\\":\\"full' "$WORK/response" && contains -q 'places_left\\":0' "$WORK/response" && echo "  ok     events: Claude sees the registrations and that it is full" || { echo "  CHYBA  registrace v list_collection_items"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/akce-test/minula"
grep -q 'Akce už skončila.' "$WORK/response" && ! grep -q 'class="ka-formular"' "$WORK/response" && echo "  ok     events: a past event says it has ended and takes no registrations" || { echo "  CHYBA  proběhlá akce"; ERRORS=$((ERRORS+1)); }
