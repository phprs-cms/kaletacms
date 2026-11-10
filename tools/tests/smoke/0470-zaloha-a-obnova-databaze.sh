echo "== záloha a obnova databáze"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$TOKEN"
BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ 2>/dev/null | grep -v predobnovou | head -1 || true)
[ -n "$BACKUP" ] && echo "  ok     záloha vytvořena" || { echo "  CHYBA  záloha nevznikla"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Po zaloze' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BACKUP"
expect "obnova vrátí stav ze zálohy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota <> 'Po zaloze' FROM ka_nastaveni WHERE promenna = 'site_name'")" "1"
BROKEN_BACKUP="kaleta-poskozena.sql"; [[ "$BACKUP" == *.gz ]] && BROKEN_BACKUP="kaleta-poskozena.sql.gz"
if [[ "$BACKUP" == *.gz ]]; then { gzip -dc "$WORK/web/storage/zalohy/$BACKUP" | head -c 4000 || true; } | gzip > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; else head -c 4000 "$WORK/web/storage/zalohy/$BACKUP" > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; fi
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Pred poskozenou' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BROKEN_BACKUP"
expect "poškozená záloha databázi nezmění" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" "Pred poskozenou"

