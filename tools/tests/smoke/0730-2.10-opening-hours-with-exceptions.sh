echo "== 2.10: opening hours with exceptions"
HOURS_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'"); TYPE_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_type'")
sq "REPLACE INTO ka_nastaveni VALUES ('company_hours', 'Po-Pá 8:00-17:00'), ('company_type', 'LocalBusiness')" > /dev/null
TOMORROW=$(site_time "+1 day" "Y-m-d")
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Inventura\",\"notice_days\":7}" > "$WORK/response"
contains -q 'Inventura' "$WORK/response" && echo "  ok     hours: Claude adds an exception (closed tomorrow)" || { echo "  CHYBA  save_hours_exception"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_hours_exception '{"from":"2026-13-01"}' > "$WORK/response"
contains -q 'YYYY-MM-DD' "$WORK/response" && echo "  ok     hours: a wrong date is refused" || { echo "  CHYBA  špatné datum výjimky"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
grep -q 'class="ka-oznameni-hodiny"' "$WORK/response" && grep -q 'Inventura' "$WORK/response" && echo "  ok     hours: the notice bar on the site" || { echo "  CHYBA  oznamovací lišta"; ERRORS=$((ERRORS+1)); }
grep -q '"specialOpeningHoursSpecification"' "$WORK/response" && echo "  ok     hours: the exception in the structured data" || { echo "  CHYBA  výjimka ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Hodiny test","slug":"hodiny-test","visible":true,"text":"<p>Dnes: {{hours.today}}. {{hours.status}}</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/hodiny-test"
! grep -q '{{hours' "$WORK/response" && grep -qE 'Dnes: ([0-9]|zavřeno)' "$WORK/response" && echo "  ok     hours: {{hours.today}} and {{hours.status}} filled in" || { echo "  CHYBA  značky hodin"; grep -o 'Dnes:[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_hours '{}' > "$WORK/response"
contains -q 'Monday' "$WORK/response" && contains -q 'Inventura' "$WORK/response" && echo "  ok     MCP list_hours: the week, the exceptions and now" || { echo "  CHYBA  list_hours"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
grep -q 'Inventura' "$WORK/response" && grep -q 'name="exception_from"' "$WORK/response" && echo "  ok     hours: the exceptions in Settings → Company" || { echo "  CHYBA  výjimky v nastavení"; ERRORS=$((ERRORS+1)); }
EXC=$(sq "SELECT id FROM ka_hours_exceptions LIMIT 1")
grep -q "action=hours_sign&amp;exception=$EXC" "$WORK/response" && echo "  ok     hours: every exception has a Door sign link" || { echo "  CHYBA  odkaz na ceduli"; ERRORS=$((ERRORS+1)); }
check "hours: the door sign is a printable page with the note" 200 "/admin.php?module=settings&action=hours_sign&exception=$EXC" "Inventura"
grep -q '<svg class="qr"' "$WORK/response" && grep -q "127.0.0.1:$PORT" "$WORK/response" && grep -q '@page { size: A4' "$WORK/response" && grep -q 'data-tisk' "$WORK/response" && ! grep -q 'admin.css' "$WORK/response" \
  && echo "  ok     hours: the sign carries the QR code with the site address, A4 print CSS and the Print button, outside the admin layout" || { echo "  CHYBA  cedule na dveře"; ERRORS=$((ERRORS+1)); }
check "hours: the A5 sign" 200 "/admin.php?module=settings&action=hours_sign&exception=$EXC&format=a5" "@page { size: A5"
check "hours: a sign for an unknown exception is a 404" 404 "/admin.php?module=settings&action=hours_sign&exception=999999"
# N3231-2 (review of 3.23.1): an exception added and deleted in Business details is one change-log row each (Core\Hours logs it under Settings)
N32312_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_add" -d "_csrf=$(csrf)" -d exception_from=2031-12-24 -d exception_to=2031-12-26 -d exception_closed=1 --data-urlencode "exception_note=N3231 hours"
N32312_EXC=$(sq "SELECT id FROM ka_hours_exceptions WHERE note = 'N3231 hours'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=hours_delete" -d "_csrf=$(csrf)" -d "exception=${N32312_EXC:-0}"
expect "3.23.1 review (N3231-2): an exception added and deleted in Business details – one change-log row each" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(modul, ' ', akce) ORDER BY idp) FROM ka_protokol WHERE idp > $N32312_LOG")|$(sq "SELECT COUNT(*) FROM ka_hours_exceptions WHERE note = 'N3231 hours'")" \
  "settings hours_exception,settings hours_exception_delete|0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=business"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=hours_delete" -d "_csrf=$(csrf)" -d "exception=$EXC"
expect "hours: an exception is deleted in the admin" "$(sq "SELECT COUNT(*) FROM ka_hours_exceptions")" "0"
curl -s -o "$WORK/response" "$B/"
! grep -q 'ka-oznameni-hodiny' "$WORK/response" && echo "  ok     hours: without an exception there is no notice bar" || { echo "  CHYBA  lišta zůstala"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '$HOURS_BEFORE' WHERE promenna = 'company_hours'; UPDATE ka_nastaveni SET hodnota = '$TYPE_BEFORE' WHERE promenna = 'company_type'" > /dev/null

