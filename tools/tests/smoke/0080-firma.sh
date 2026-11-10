echo "== firma"
check "nastavení/firma" 200 "/admin.php?module=business" 'name="company_hours"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00
So 9–12" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
expect "údaje firmy uloženy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'")" 12345678
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company -d company_type=LocalBusiness -d company_country=CZ --data-urlencode "company_hours=kdykoli"
expect "nesrozumitelná otevírací doba odmítnuta" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%8:00%' AND hodnota NOT LIKE '%kdykoli%' FROM ka_nastaveni WHERE promenna = 'company_hours'")" 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '"@type":"HomeAndConstructionBusiness"' "$WORK/response" && grep -q '"openingHoursSpecification"' "$WORK/response" && grep -q '"latitude":50.0875' "$WORK/response" && grep -q '"vatID":"CZ12345678"' "$WORK/response" \
  && echo "  ok     firma ve strukturovaných datech (LocalBusiness, otevírací doba, souřadnice)" || { echo "  CHYBA  firma ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"
grep -q 'Dlouhá 12<br>110 00 Praha' "$WORK/response" && grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q '<li>Po–Pá 8:00–17:00</li>' "$WORK/response" && grep -q 'IČO 12345678, DIČ CZ12345678' "$WORK/response" \
  && echo "  ok     kontakt vypisuje údaje firmy z Nastavení" || { echo "  CHYBA  údaje firmy na kontaktu"; ERRORS=$((ERRORS+1)); }

