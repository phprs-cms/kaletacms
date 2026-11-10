echo "== koš novinek"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'")
check "výpis novinek" 200 "/admin.php?module=news" "Smazat označené"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=delete" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
check "novinka v koši není na webu" 404 /novinky/vitejte-v-kalete
check "novinka v koši není ani v náhledu" 404 "/novinky/vitejte-v-kalete?preview=1"
check "záložka Koš" 200 "/admin.php?module=news&status=kos" "Vítejte"
check "novinka v koši nejde upravit" 404 "/admin.php?module=news&action=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=restore" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
expect "obnovená novinka se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM ka_novinky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_novinky SET visible = 1, smazano = '$(site_time)' - INTERVAL 31 DAY WHERE idc = $IDC"
check "administrace" 200 /admin.php "Přehled"
expect "T1 (Q5): opening the administration no longer empties the trash (a GET deletes nothing)" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE idc = $IDC")" "1"
expect "T1 (Q5): the daily background job empties the trash" "$(run_job trash | cut -d, -f1-2)" "news 1, pages 0"
expect "novinka starší 30 dní v koši je smazaná natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE idc = $IDC")" "0"

