echo "== 3.0: online booking of appointments"
# mail must fail here, so every e-mail keeps its body in the queue (the cancel link is read from it); the token for cron is known
BK_MONTHS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '1'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'), ('tasks_token', 'testtoken123'), ('enquiries_months', '24')" > /dev/null
# 3.2: Bookings are a feature, off on a new installation – nothing of it answers until it is switched on
check "3.2 bookings off: no admin module" 403 "/admin.php?module=bookings"
mcp save_booking_service '{"name":"Off test"}' > "$WORK/response"
contains -q 'switched off on this site' "$WORK/response" && expect "3.2 bookings off: the MCP tools say the feature is off and save nothing" "$(sq "SELECT COUNT(*) FROM ka_booking_services")" 0 || { echo "  CHYBA  a booking tool while the feature is off"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "3.2 bookings off: the public booking addresses are a 404" 404 "/_booking/days?service=1&staff=0&month=2026-01"
# switched on the way Claude is told to: update_settings with "bookings" added to extensions
BK_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$BK_EXT" | sed 's/,/","/g')\",\"bookings\"]}}" > /dev/null
expect "3.2 bookings: switched on over MCP" "$(sq "SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'")" 1
# 3.5 (UXA-08): one set-up order everywhere – a person, a service, a Book page – and no silent empty calendar
check "3.5 booking: the set-up card while nothing is set up" 200 "/admin.php?module=bookings" 'id="rezervace-nastaveni"'
grep -q 'action=staff_edit"><strong>Přidat člověka' "$WORK/response" && grep -q 'action=book_page' "$WORK/response" && grep -q '0 / 3' "$WORK/response" \
  && echo "  ok     3.5 booking: the card starts with the person and offers the Book page" || { echo "  CHYBA  3.5 booking: set-up card steps"; ERRORS=$((ERRORS+1)); }
check "3.5 booking: Services without anybody lead to adding a person first" 200 "/admin.php?module=bookings&action=services" 'Nejdřív přidejte člověka'
check "3.5 booking: People without anybody have the empty state with New person" 200 "/admin.php?module=bookings&action=staff" 'prazdny-stav'
check "3.5 booking: Features link to the set-up while it is incomplete" 200 "/admin.php?module=extensions" 'Nastavit rezervace →'
BK_HOURS=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_hours', '')" > /dev/null
check "3.5 booking: a new person on a site without opening hours starts Mon–Fri 9–17" 200 "/admin.php?module=bookings&action=staff_edit" 'name="hours_5" maxlength="100" value="9:00–17:00"'
grep -q 'name="hours_6" maxlength="100" value=""' "$WORK/response" && echo "  ok     3.5 booking: the weekend stays empty" || { echo "  CHYBA  3.5 booking: weekend hours"; ERRORS=$((ERRORS+1)); }
# a person whose hours were cleared, offering a service: nothing to book – the screen and Claude are told why
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=staff_save" -d "_csrf=$(csrf)" -d id=0 --data-urlencode "name=Bez hodin" -d active=1
BK_EMPTY_STAFF=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Bez hodin'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=service_save" -d "_csrf=$(csrf)" -d id=0 --data-urlencode "name=Bez času" -d duration_min=30 -d active=1 -d "staff[]=$BK_EMPTY_STAFF"
check "3.5 booking: no free time in 14 days is a warning that names the person" 200 "/admin.php?module=bookings" 'Bez hodin nemá týdenní hodiny a web nemá otevírací dobu'
grep -q '2 / 3' "$WORK/response" && echo "  ok     3.5 booking: the card counts the person and the service as done" || { echo "  CHYBA  3.5 booking: card progress"; ERRORS=$((ERRORS+1)); }
mcp booking_availability '{}' > "$WORK/response"; mcp_text
contains -q '"warning":"No free time in the next 14 days: Bez hodin has no weekly hours' "$WORK/text" && echo "  ok     3.5 booking: booking_availability tells Claude why nothing can be booked" || { echo "  CHYBA  3.5 booking_availability warning"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=bookings"
BK_PAGE_URL=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=bookings&action=book_page" -d "_csrf=$(csrf)")
BK_BOOK_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE stavba_koncept LIKE '%\"typ\":\"rezervace\"%' ORDER BY ids DESC LIMIT 1")
expect "3.5 booking: Create a Book page makes a hidden page with the Booking element and opens the builder" \
  "$(sq "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', titulek) FROM ka_stranky WHERE ids = ${BK_BOOK_PAGE:-0}")|${BK_PAGE_URL##*action=}" "0/1/Rezervace|builder&id=$BK_BOOK_PAGE"
check "3.5 booking: with the three steps done the card is gone" 200 "/admin.php?module=bookings" 'Rezervace'
grep -q 'id="rezervace-nastaveni"' "$WORK/response" && { echo "  CHYBA  3.5 booking: the card stayed"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.5 booking: the card goes once the set-up is complete"
sq "DELETE FROM ka_stranky WHERE ids = ${BK_BOOK_PAGE:-0}; DELETE FROM ka_booking_services WHERE name = 'Bez času'; DELETE FROM ka_booking_staff WHERE name = 'Bez hodin'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('company_hours', '$BK_HOURS')" > /dev/null
mcp save_booking_service '{"name":"Střih test","duration_min":30,"buffer_min":10,"price_text":"450 Kč","description":"Mytí, střih, foukaná"}' > "$WORK/response"; mcp_text
BK_SERVICE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp save_booking_staff "{\"name\":\"Jana Rezervace\",\"email\":\"jana-bk@example.cz\",\"services\":[${BK_SERVICE:-0}],\"hours\":{\"monday\":\"9:00-17:00\",\"tuesday\":\"9:00-17:00\",\"wednesday\":\"9:00-17:00\",\"thursday\":\"9:00-17:00\",\"friday\":\"9:00-17:00\",\"saturday\":\"9:00-17:00\",\"sunday\":\"9:00-17:00\"}}" > "$WORK/response"; mcp_text
BK_STAFF=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
[ -n "$BK_SERVICE" ] && [ -n "$BK_STAFF" ] && contains -q '"7":\[\["09:00","17:00"\]\]' "$WORK/text" && echo "  ok     booking: Claude sets up a service and a person with weekly hours" || { echo "  CHYBA  save_booking_service / save_booking_staff"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp save_booking_staff '{"name":"Nikdo","hours":{"monday":"17-9"}}' > "$WORK/response"
contains -q 'ranges' "$WORK/response" && echo "  ok     booking: wrong hours are refused" || { echo "  CHYBA  hours validation"; ERRORS=$((ERRORS+1)); }
# One person, hours per service – the second service only on Saturday afternoons, the first keeps the weekly hours
mcp save_booking_service '{"name":"Wochenende test","duration_min":60}' > "$WORK/response"; mcp_text
BK_SERVICE2=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp save_booking_staff "{\"id\":${BK_STAFF:-0},\"services\":[${BK_SERVICE:-0},${BK_SERVICE2:-0}],\"service_hours\":{\"${BK_SERVICE2:-0}\":{\"saturday\":\"13:00-15:00\"}}}" > "$WORK/response"; mcp_text
BK_SAT=$(php -r 'echo date("Y-m-d", strtotime("next saturday +7 days"));'); BK_MON=$(php -r 'echo date("Y-m-d", strtotime("next monday +7 days"));')
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE2&staff=0&day=$BK_SAT"
grep -q '"13:00"' "$WORK/response" && grep -q '"14:00"' "$WORK/response" && ! grep -q '"09:00"' "$WORK/response" && ! grep -q '"15:00"' "$WORK/response" && echo "  ok     booking: a service with its own hours is offered only then" || { echo "  CHYBA  hours per service"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE2&staff=0&day=$BK_MON"
! grep -q '"[0-9][0-9]:[0-9][0-9]"' "$WORK/response" && echo "  ok     booking: ... and not on the other days of the person's weekly hours" || { echo "  CHYBA  hours per service on a weekday"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_SAT"
grep -q '"09:00"' "$WORK/response" && echo "  ok     booking: the other service keeps the general weekly hours" || { echo "  CHYBA  general hours after hours per service"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_booking_services WHERE id = ${BK_SERVICE2:-0}" > /dev/null
expect "booking: removing a service removes its own hours" "$(sq "SELECT COUNT(*) FROM ka_booking_hours WHERE service_id IS NOT NULL")" "0"
mcp vytvor_stranku '{"titulek":"Rezervace test","adresa":"rezervace-test","zobrazit":true}' > "$WORK/response"; mcp_text; BK_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":${BK_PAGE:-0},\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h1\",\"obsah\":{\"text\":\"Objednejte se\"}},{\"id\":\"bk1\",\"typ\":\"rezervace\",\"obsah\":{}}]}]}}" > /dev/null
curl -s -o "$WORK/booking.html" "$B/rezervace-test"
grep -q 'class="ka-rezervace"' "$WORK/booking.html" && grep -q 'data-rezervace="bk1"' "$WORK/booking.html" && grep -q 'Střih test' "$WORK/booking.html" && grep -q 'name="as_podpis"' "$WORK/booking.html" && grep -q 'name="slot" required' "$WORK/booking.html" && grep -q 'image/web.js' "$WORK/booking.html" \
  && echo "  ok     booking: the element renders the service, the plain select of free times, the spam protection and keeps web.js" || { echo "  CHYBA  booking element"; ERRORS=$((ERRORS+1)); }
BK_DAY=$(site_time "+3 days" "Y-m-d")
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
grep -q '"09:00"' "$WORK/response" && grep -q '"16:30"' "$WORK/response" && ! grep -q '"17:00"' "$WORK/response" && echo "  ok     booking: /_booking/slots returns the free times of the day" || { echo "  CHYBA  /_booking/slots"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/_booking/days?service=$BK_SERVICE&staff=0&month=${BK_DAY:0:7}"
grep -q "\"$BK_DAY\"" "$WORK/response" && echo "  ok     booking: /_booking/days lists the day among the days with free times" || { echo "  CHYBA  /_booking/days"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
bk_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/booking.html" | head -1 | sed 's/.*value="//;s/"$//'; }
BK_SOURCE=$(bk_field zdroj); BK_TIME=$(( $(date +%s) - 10 )); BK_SIGNATURE=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "rezervace|$BK_SOURCE|bk1|$BK_TIME" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")
book() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/_booking" -d "zdroj=$BK_SOURCE" -d prvek=bk1 -d zpet=/rezervace-test -d "as_cas=$BK_TIME" -d "as_podpis=$BK_SIGNATURE" -d "service=$BK_SERVICE" -d staff=0 "$@"; }
case "$(book --data-urlencode "slot=$BK_DAY 10:00" --data-urlencode "jmeno=Petr Rezervující" -d email=petr-bk@example.cz -d telefon=+420777000111 -d poznamka=Test -d souhlas=1)" in *result=ok*) echo "  ok     booking: a visitor books a time";; *) echo "  CHYBA  booking POST"; ERRORS=$((ERRORS+1));; esac
expect "booking: saved as confirmed for the person, with the end time by the duration" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(staff_id), '|', MAX(TIME(ends_at))) FROM ka_bookings WHERE email = 'petr-bk@example.cz' AND status = 'confirmed'")" "1|$BK_STAFF|10:30:00"
# 3.28: the booking joins its customer after the response (Core\Contacts::later in Booking::book, linked in index.php)
expect "3.28 booking: linked to a contact after the response – the name and phone from the booking, source booking" \
  "$(sq "SELECT CONCAT(c.email_norm, '|', c.name, '|', c.phone = b.phone AND c.phone <> '', '|', c.source) FROM ka_bookings b JOIN ka_contacts c ON c.id = b.contact_id WHERE b.email = 'petr-bk@example.cz'")" "petr-bk@example.cz|Petr Rezervující|1|booking"
expect "booking: the confirmation went to the customer and the notification to the person" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_posta WHERE komu = 'petr-bk@example.cz'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Nová rezervace%'))")" "1|1"
case "$(book --data-urlencode "slot=$BK_DAY 10:00" -d jmeno=Druhy -d email=druhy-bk@example.cz -d souhlas=1)" in *result=obsazeno*) echo "  ok     booking: the same time cannot be booked twice";; *) echo "  CHYBA  double booking"; ERRORS=$((ERRORS+1));; esac
# PR #16 renamed the booking form's fields to service / staff: a form opened or cached before the update posts sluzba / osoba
# and must still reach the same service (the taken time proves the service was read, nothing new is booked)
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/_booking" -d "zdroj=$BK_SOURCE" -d prvek=bk1 -d zpet=/rezervace-test -d "as_cas=$BK_TIME" -d "as_podpis=$BK_SIGNATURE" -d "sluzba=$BK_SERVICE" -d osoba=0 --data-urlencode "slot=$BK_DAY 10:00" -d jmeno=Stary -d email=stary-bk@example.cz -d souhlas=1)" in *result=obsazeno*) echo "  ok     booking: a form with the old field names (sluzba, osoba) still books the same service";; *) echo "  CHYBA  booking with the old field names"; ERRORS=$((ERRORS+1));; esac
expect "booking: the second attempt saved nothing" "$(sq "SELECT COUNT(*) FROM ka_bookings WHERE starts_at = '$BK_DAY 10:00:00'")" "1"
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
! grep -q '"10:00"' "$WORK/response" && ! grep -q '"09:30"' "$WORK/response" && ! grep -q '"10:30"' "$WORK/response" && grep -q '"11:00"' "$WORK/response" && echo "  ok     booking: the booked time and the buffer around it are gone from the free times" || { echo "  CHYBA  slots after booking"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
case "$(book --data-urlencode "slot=$BK_DAY 11:00" -d jmeno=Petr -d email=petr-bk@example.cz -d souhlas=)" in *result=souhlas*) echo "  ok     booking: without the consent nothing is saved";; *) echo "  CHYBA  consent check"; ERRORS=$((ERRORS+1));; esac
# the queued body is JSON (slashes escaped), and the mysql client escapes the backslashes once more on output
bk_token() { sq "SELECT telo FROM ka_posta WHERE komu = 'petr-bk@example.cz' ORDER BY idp $1 LIMIT 1" | php -r '$t = json_decode(str_replace("\\\\", "\\", file_get_contents("php://stdin")), true); preg_match("#_booking\\\\?/cancel\\\\?/([a-f0-9]{32})#", (string) ($t["text"] ?? ""), $m); echo $m[1] ?? "";'; }
BK_TOKEN=$(bk_token ASC)
[ -n "$BK_TOKEN" ] && echo "  ok     booking: the confirmation carries the cancel link" || { echo "  CHYBA  cancel link in the e-mail"; ERRORS=$((ERRORS+1)); }
check "booking: the .ics file for the customer's calendar" 200 "/_booking/ics/${BK_TOKEN:-0000000000000000000000000000000a}" "BEGIN:VEVENT"
check "booking: the cancel page asks before it cancels" 200 "/_booking/cancel/${BK_TOKEN:-0000000000000000000000000000000a}" "Zrušit termín?"
expect "booking: opening the link cancels nothing" "$(sq "SELECT status FROM ka_bookings WHERE email = 'petr-bk@example.cz'")" "confirmed"
bk_cancel() { curl -s -o "$WORK/response" -w '%{http_code}' -X POST -d zrusit=1 "$B/_booking/cancel/${1:-0000000000000000000000000000000a}"; }
code=$(bk_cancel "$BK_TOKEN"); [ "$code" = 200 ] && grep -q 'Váš termín je zrušen' "$WORK/response" && echo "  ok     booking: the customer cancels before the deadline" || { echo "  CHYBA  cancel by the customer: kód $code"; ERRORS=$((ERRORS+1)); }
expect "booking: cancelled by the customer, the person was told" "$(sq "SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Zrušená rezervace%')) FROM ka_bookings WHERE email = 'petr-bk@example.cz'")" "cancelled|customer|1"
case "$(book --data-urlencode "slot=$BK_DAY 11:00" --data-urlencode "jmeno=Petr Rezervující" -d email=petr-bk@example.cz -d souhlas=1)" in *result=ok*) echo "  ok     booking: a second appointment";; *) echo "  CHYBA  second booking"; ERRORS=$((ERRORS+1));; esac
BK_TOKEN2=$(bk_token DESC)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_cancel_hours', '200')" > /dev/null
code=$(bk_cancel "$BK_TOKEN2"); [ "$code" = 200 ] && grep -q 'už nelze zrušit online' "$WORK/response" && echo "  ok     booking: after the deadline the link refuses to cancel" || { echo "  CHYBA  cancel after the deadline: kód $code"; ERRORS=$((ERRORS+1)); }
expect "booking: the appointment stays confirmed" "$(sq "SELECT status FROM ka_bookings WHERE starts_at = '$BK_DAY 11:00:00'")" "confirmed"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_cancel_hours', '24'), ('booking_reminder_hours', '100'); UPDATE ka_bookings SET created_at = '$(site_time)' - INTERVAL 10 DAY WHERE starts_at = '$BK_DAY 11:00:00'; DELETE FROM ka_jobs WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "booking: the reminder job sends the reminder once and marks it" "$(sq "SELECT CONCAT((SELECT reminded_at IS NOT NULL FROM ka_bookings WHERE starts_at = '$BK_DAY 11:00:00'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'petr-bk@example.cz' AND predmet LIKE 'Připomínka%'))")" "1|1"
sq "UPDATE ka_jobs SET last_run = '$(site_time)' - INTERVAL 2 HOUR WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "booking: the next run sends no second reminder" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'petr-bk@example.cz' AND predmet LIKE 'Připomínka%'")" "1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_reminder_hours', '24')" > /dev/null
mcp list_bookings "{\"from\":\"$BK_DAY\",\"to\":\"$BK_DAY\",\"status\":\"all\"}" > "$WORK/response"; mcp_text
contains -q 'petr-bk@example.cz' "$WORK/text" && contains -q '"count":2' "$WORK/text" && echo "  ok     booking: Claude lists the bookings of the day" || { echo "  CHYBA  list_bookings"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
expect "booking: every read of bookings by Claude is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'list_bookings' AND popis LIKE '2 %'")" "1"
mcp booking_availability "{\"service\":$BK_SERVICE,\"day\":\"$BK_DAY\"}" > "$WORK/response"; mcp_text
contains -q '"slots"' "$WORK/text" && contains -q '"09:00"' "$WORK/text" && ! contains -q 'petr' "$WORK/text" && echo "  ok     booking: booking_availability shows the free times and no personal data" || { echo "  CHYBA  booking_availability"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "booking: the admin list shows the booking by day" 200 "/admin.php?module=bookings" "Petr Rezervující"
BK_ID=$(sq "SELECT id FROM ka_bookings WHERE starts_at = '$BK_DAY 11:00:00'")
check "booking: the admin detail with the customer" 200 "/admin.php?module=bookings&action=detail&id=${BK_ID:-0}" "petr-bk@example.cz"
check "booking: the services screen" 200 "/admin.php?module=bookings&action=services&id=$BK_SERVICE" "Střih test"
check "booking: the person's form with the weekly hours" 200 "/admin.php?module=bookings&action=staff_edit&id=$BK_STAFF" 'name="hours_1"'
check "booking: the manual booking form" 200 "/admin.php?module=bookings&action=new" 'name="den"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=create" -d "_csrf=$(csrf)" -d "sluzba=$BK_SERVICE" -d osoba=0 -d "den=$BK_DAY" -d cas=14:00 --data-urlencode "jmeno=Telefon Zákazník" -d email= -d telefon=777000222
expect "booking: a booking taken by phone, without an e-mail" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(source)) FROM ka_bookings WHERE name = 'Telefon Zákazník' AND status = 'confirmed'")" "1|admin"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=create" -d "_csrf=$(csrf)" -d "sluzba=$BK_SERVICE" -d "osoba=$BK_STAFF" -d "den=$BK_DAY" -d cas=14:00 -d jmeno=Kolize -d email=
expect "booking: the admin cannot double-book either" "$(sq "SELECT COUNT(*) FROM ka_bookings WHERE name = 'Kolize'")" "0"
BK_PHONE=$(sq "SELECT id FROM ka_bookings WHERE name = 'Telefon Zákazník'")
mcp cancel_booking "{\"id\":${BK_PHONE:-0}}" > "$WORK/response"
contains -q 'confirm' "$WORK/response" && echo "  ok     booking: cancel_booking needs an explicit confirmation" || { echo "  CHYBA  cancel_booking without confirm"; ERRORS=$((ERRORS+1)); }
mcp cancel_booking "{\"id\":${BK_PHONE:-0},\"confirm\":true}" > /dev/null
expect "booking: Claude cancels a booking, the change is logged" "$(sq "SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'bookings' AND akce = 'cancel' AND popis = CONCAT('#', ${BK_PHONE:-0}))) FROM ka_bookings WHERE id = ${BK_PHONE:-0}")" "cancelled|claude|1"
mcp find_personal_data '{"email":"petr-bk@example.cz"}' > "$WORK/response"; mcp_text
contains -q '"bookings":2' "$WORK/text" && echo "  ok     booking: a personal data request finds the bookings" || { echo "  CHYBA  find_personal_data bookings"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "booking: the admin's personal data screen links the bookings" 200 "/admin.php?module=enquiries&action=personal" "osobni-email"
mcp erase_personal_data '{"email":"petr-bk@example.cz","confirm":true}' > /dev/null
expect "booking: erased on request, the other person's booking stays" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_bookings WHERE email = 'petr-bk@example.cz'), '|', (SELECT COUNT(*) FROM ka_bookings WHERE name = 'Telefon Zákazník'))")" "0|1"
sq "UPDATE ka_bookings SET ends_at = '$(site_time)' - INTERVAL 30 MONTH, starts_at = '$(site_time)' - INTERVAL 30 MONTH WHERE name = 'Telefon Zákazník'; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'anonymise')" > /dev/null
curl -s -b "$JAR" -o /dev/null "$B/admin.php?module=bookings"
expect "booking: past the enquiry retention the booking is anonymised, the row stays" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(name = ''), '|', MAX(anonymised_at IS NOT NULL)) FROM ka_bookings WHERE id = ${BK_PHONE:-0}")" "1|1|1"
# 3.3: a service that needs the provider's confirmation – a request holds the time, the provider accepts, declines or proposes other times
mcp save_booking_service "{\"id\":${BK_SERVICE:-0},\"requires_confirmation\":true}" > /dev/null
expect "3.3 booking: the service needs confirmation" "$(sq "SELECT requires_confirmation FROM ka_booking_services WHERE id = ${BK_SERVICE:-0}")" "1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_pending_mail', 'Ahoj {name}, dostali jsme tvoji zprávu.')" > /dev/null
case "$(book --data-urlencode "slot=$BK_DAY 15:00" --data-urlencode "jmeno=Pavla Žádost" -d email=pavla-bk@example.cz -d souhlas=1)" in *result=pending*) echo "  ok     3.3 booking: a visitor's request comes back as pending";; *) echo "  CHYBA  3.3 pending request"; ERRORS=$((ERRORS+1));; esac
check "3.3 booking: the element thanks for a request, not for a booking" 200 "/rezervace-test?booking=bk1&result=pending" "vaši žádost jsme přijali"
expect "3.3 booking: saved as pending with a hold" "$(sq "SELECT CONCAT(status, '|', hold_until IS NOT NULL) FROM ka_bookings WHERE email = 'pavla-bk@example.cz'")" "pending|1"
expect "3.3 booking: the customer got the acknowledgement in the site's own words, the person the notification, nobody a confirmation" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_posta WHERE komu = 'pavla-bk@example.cz' AND predmet LIKE 'Přijali jsme vaši žádost%' AND telo LIKE '%Ahoj Pavla Žádost, dostali jsme tvoji zprávu.%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Žádost čeká na vaši odpověď%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'pavla-bk@example.cz'))")" "1|1|1"
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
! grep -q '"15:00"' "$WORK/response" && grep -q '"16:00"' "$WORK/response" && echo "  ok     3.3 booking: a pending request holds its time" || { echo "  CHYBA  3.3 hold"; ERRORS=$((ERRORS+1)); }
case "$(book --data-urlencode "slot=$BK_DAY 15:00" -d jmeno=Druha -d email=druha-bk@example.cz -d souhlas=1)" in *result=obsazeno*) echo "  ok     3.3 booking: nobody else can take the held time";; *) echo "  CHYBA  3.3 held time taken"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$B/admin.php?module=bookings&action=list"
check "3.3 booking: the list shows the request and what waits" 200 "/admin.php?module=bookings" "Pavla Žádost"
BK_P1=$(sq "SELECT id FROM ka_bookings WHERE email = 'pavla-bk@example.cz'")
check "3.3 booking: the detail offers accept, decline and other times" 200 "/admin.php?module=bookings&action=detail&id=${BK_P1:-0}" 'id="propose-slots"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=confirm" -d "_csrf=$(csrf)" -d "id=${BK_P1:-0}"
expect "3.3 booking: accepted – confirmed, hold released, the customer got the confirmation with a cancel link" "$(sq "SELECT CONCAT(status, '|', hold_until IS NULL, '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'pavla-bk@example.cz' AND telo LIKE '%_booking%cancel%'))  FROM ka_bookings WHERE id = ${BK_P1:-0}")" "confirmed|1|1"
# decline with a personal message: the time is free again
book --data-urlencode "slot=$BK_DAY 16:00" --data-urlencode "jmeno=Dana Odmítnutá" -d email=dana-bk@example.cz -d souhlas=1 > /dev/null
BK_P2=$(sq "SELECT id FROM ka_bookings WHERE email = 'dana-bk@example.cz'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=bookings&action=detail&id=${BK_P2:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=bookings&action=decline" -d "_csrf=$(csrf)" -d "id=${BK_P2:-0}" --data-urlencode "message=Ve čtvrtek bohužel nejsem na místě."
expect "3.3 booking: declined – the customer is told with the personal message" "$(sq "SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'dana-bk@example.cz' AND telo LIKE '%Ve čtvrtek bohužel nejsem na místě.%'))  FROM ka_bookings WHERE id = ${BK_P2:-0}")" "declined|admin|1"
curl -s -o "$WORK/response" "$B/_booking/slots?service=$BK_SERVICE&staff=0&day=$BK_DAY"
grep -q '"16:00"' "$WORK/response" && echo "  ok     3.3 booking: a declined request frees the time" || { echo "  CHYBA  3.3 declined slot"; ERRORS=$((ERRORS+1)); }
# propose other times over MCP: the customer picks one with the link
book --data-urlencode "slot=$BK_DAY 16:00" --data-urlencode "jmeno=Eva Návrh" -d email=eva-bk@example.cz -d souhlas=1 > /dev/null
BK_P3=$(sq "SELECT id FROM ka_bookings WHERE email = 'eva-bk@example.cz'")
mcp propose_booking_times "{\"id\":${BK_P3:-0},\"times\":[\"$BK_DAY 12:30\"]}" > "$WORK/response"
contains -q 'confirm' "$WORK/response" && echo "  ok     3.3 booking: propose_booking_times needs an explicit confirmation" || { echo "  CHYBA  3.3 propose without confirm"; ERRORS=$((ERRORS+1)); }
mcp propose_booking_times "{\"id\":${BK_P3:-0},\"times\":[\"$BK_DAY 15:00\"],\"confirm\":true}" > "$WORK/response"
contains -q 'not free' "$WORK/response" && echo "  ok     3.3 booking: a time that is not free cannot be proposed" || { echo "  CHYBA  3.3 propose a busy time"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp propose_booking_times "{\"id\":${BK_P3:-0},\"times\":[\"$BK_DAY 12:30\",\"$BK_DAY 16:00\"],\"message\":\"Wie wäre es früher?\",\"confirm\":true}" > /dev/null
expect "3.3 booking: two times proposed – the own held time may be among them, the booking stays pending" "$(sq "SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = ${BK_P3:-0}))  FROM ka_bookings WHERE id = ${BK_P3:-0}")" "pending|2"
BK_CHOOSE=$(sq "SELECT telo FROM ka_posta WHERE komu = 'eva-bk@example.cz' AND telo LIKE '%_booking%choose%' ORDER BY idp DESC LIMIT 1" | php -r '$t = json_decode(str_replace("\\\\", "\\", file_get_contents("php://stdin")), true); preg_match("#_booking\\\\?/choose\\\\?/([a-f0-9]{32})#", (string) ($t["text"] ?? ""), $m); echo $m[1] ?? "";')
[ -n "$BK_CHOOSE" ] && echo "  ok     3.3 booking: the e-mail carries the link to pick a time" || { echo "  CHYBA  3.3 choose link"; ERRORS=$((ERRORS+1)); }
check "3.3 booking: the page lists the proposed times" 200 "/_booking/choose/${BK_CHOOSE:-0000000000000000000000000000000a}" 'name="proposal"'
expect "3.3 booking: opening the link chooses nothing" "$(sq "SELECT status FROM ka_bookings WHERE id = ${BK_P3:-0}")" "pending"
BK_PROPOSAL=$(sq "SELECT id FROM ka_booking_proposals WHERE booking_id = ${BK_P3:-0} AND starts_at LIKE '% 12:30:00'")
curl -s -o "$WORK/response" -X POST -d "proposal=${BK_PROPOSAL:-0}" "$B/_booking/choose/${BK_CHOOSE:-0000000000000000000000000000000a}"
expect "3.3 booking: the customer picks a time – the booking moves there and is confirmed, the person is told" "$(sq "SELECT CONCAT(status, '|', TIME(starts_at), '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = ${BK_P3:-0}), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Zákazník přijal navržený termín%'))  FROM ka_bookings WHERE id = ${BK_P3:-0}")" "confirmed|12:30:00|0|1"
# a request nobody answers: the hold runs out, the provider is reminded once, the customer hears nothing
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'rezervace'" > /dev/null # the limit of five bookings an hour from one address
book --data-urlencode "slot=$BK_DAY 16:00" --data-urlencode "jmeno=Hana Čekající" -d email=hana-bk@example.cz -d souhlas=1 > /dev/null
sq "UPDATE ka_bookings SET hold_until = '$(site_time)' - INTERVAL 1 HOUR WHERE email = 'hana-bk@example.cz'; UPDATE ka_jobs SET last_run = '$(site_time)' - INTERVAL 2 HOUR WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.3 booking: the hold ran out – the provider is reminded, the customer got only the acknowledgement" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Stále čeká na vaši odpověď%'), '|', (SELECT COUNT(*) FROM ka_posta WHERE komu = 'hana-bk@example.cz'), '|', (SELECT status FROM ka_bookings WHERE email = 'hana-bk@example.cz'))")" "1|1|pending"
sq "UPDATE ka_jobs SET last_run = '$(site_time)' - INTERVAL 2 HOUR WHERE name = 'booking_reminders'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.3 booking: the reminder goes out once" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana-bk@example.cz' AND predmet LIKE 'Stále čeká na vaši odpověď%'")" "1"
case "$(book --data-urlencode "slot=$BK_DAY 16:00" -d jmeno=Iva -d email=iva-bk@example.cz -d souhlas=1)" in *result=pending*) echo "  ok     3.3 booking: after the hold the time can be requested by someone else";; *) echo "  CHYBA  3.3 expired hold"; ERRORS=$((ERRORS+1));; esac
mcp confirm_booking "{\"id\":$(sq "SELECT id FROM ka_bookings WHERE email = 'iva-bk@example.cz'"),\"confirm\":true}" > /dev/null
expect "3.3 booking: Claude accepts the request that holds the time now" "$(sq "SELECT status FROM ka_bookings WHERE email = 'iva-bk@example.cz'")" "confirmed"
mcp confirm_booking "{\"id\":$(sq "SELECT id FROM ka_bookings WHERE email = 'hana-bk@example.cz'"),\"confirm\":true}" > "$WORK/response"
contains -q 'taken' "$WORK/response" && expect "3.3 booking: a request whose held time ran out and was taken cannot be accepted" "$(sq "SELECT status FROM ka_bookings WHERE email = 'hana-bk@example.cz'")" "pending" || { echo "  CHYBA  3.3 accept a taken time"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_bookings "{\"from\":\"$BK_DAY\",\"to\":\"$BK_DAY\",\"status\":\"pending\"}" > "$WORK/response"; mcp_text
contains -q 'hana-bk@example.cz' "$WORK/text" && ! contains -q 'iva-bk@example.cz' "$WORK/text" && echo "  ok     3.3 booking: list_bookings filters the pending requests" || { echo "  CHYBA  3.3 list pending"; ERRORS=$((ERRORS+1)); }
# the booking settings over MCP: listed in update_settings, checked with the limits of the Bookings settings form
BK_HORIZON=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'booking_horizon_days'")
mcp update_settings '{"settings":{"booking_hold_hours":"5","booking_horizon_days":"9999","booking_pending_mail":"<b>Hallo</b> {name}"}}' > /dev/null
expect "3.3 booking: Claude sets the booking settings with the limits of the settings form" "$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota) ORDER BY promenna) FROM ka_nastaveni WHERE promenna IN ('booking_hold_hours', 'booking_horizon_days', 'booking_pending_mail')")" "booking_hold_hours=5,booking_horizon_days=365,booking_pending_mail=Hallo {name}"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('booking_hold_hours', '48'), ('booking_horizon_days', '${BK_HORIZON:-60}')" > /dev/null
sq "UPDATE ka_booking_services SET requires_confirmation = 0 WHERE id = ${BK_SERVICE:-0}; DELETE FROM ka_nastaveni WHERE promenna = 'booking_pending_mail'; UPDATE ka_bookings SET status = 'cancelled' WHERE email IN ('hana-bk@example.cz', 'iva-bk@example.cz')" > /dev/null
# 3.2: switched off again – the element, the public addresses, the admin module and the tools are gone; the data stays
sq "UPDATE ka_nastaveni SET hodnota = '$BK_EXT' WHERE promenna = 'extensions'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/rezervace-test"; ! grep -q 'class="ka-rezervace"' "$WORK/response" && echo "  ok     3.2 bookings off: the Booking element is not on the page" || { echo "  CHYBA  the Booking element with the feature off"; ERRORS=$((ERRORS+1)); }
# 3.2.3: an appointment booked before the switch-off – its cancel and .ics links keep working
BK_OFF_TOKEN=cafe0000cafe0000cafe0000cafe0003
sq "INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, name, email, token_hash, created_at) VALUES (${BK_SERVICE:-0}, ${BK_STAFF:-0}, '$(site_time)' + INTERVAL 10 DAY, '$(site_time)' + INTERVAL 10 DAY + INTERVAL 30 MINUTE, 'Off Customer', 'off-bk@example.cz', SHA2('$BK_OFF_TOKEN', 256), '$(site_time)')" > /dev/null
check "3.2.3 bookings off: the customer's .ics link still works" 200 "/_booking/ics/$BK_OFF_TOKEN" "BEGIN:VEVENT"
check "3.2.3 bookings off: the customer's cancel page still works" 200 "/_booking/cancel/$BK_OFF_TOKEN" "zrusit"
curl -s -o /dev/null -X POST -d zrusit=1 "$B/_booking/cancel/$BK_OFF_TOKEN"
expect "3.2.3 bookings off: the customer can still cancel" "$(sq "SELECT CONCAT(status, '|', cancelled_by) FROM ka_bookings WHERE email = 'off-bk@example.cz'")" "cancelled|customer"
check "3.2.3 bookings off: new bookings are not taken" 404 "/_booking/slots?service=${BK_SERVICE:-0}&staff=0&day=2026-01-05"
check "3.2 bookings off: no admin module" 403 "/admin.php?module=bookings"
mcp list_bookings '{}' > "$WORK/response"
contains -q 'switched off on this site' "$WORK/response" && expect "3.2 bookings off: list_bookings says so; the services and bookings stay" "$(sq "SELECT CONCAT((SELECT COUNT(*) > 0 FROM ka_booking_services), '|', (SELECT COUNT(*) > 0 FROM ka_bookings))")" "1|1" || { echo "  CHYBA  list_bookings with the feature off"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_expiry', 'delete'), ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
if [ -n "$BK_MONTHS" ]; then sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('enquiries_months', '$BK_MONTHS')" > /dev/null; else sq "DELETE FROM ka_nastaveni WHERE promenna = 'enquiries_months'" > /dev/null; fi

