echo "== 3.13: the admin in Spanish"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET jazyk = 'es' WHERE user = 'admin'"
for screen in "|Panel" "module=pages|Páginas" "module=settings|Ajustes" "action=account|Mi cuenta"; do
  check "3.13: Spanish admin ${screen%%|*}" 200 "/admin.php?${screen%%|*}" "${screen#*|}"
  grep -q '<html lang="es"' "$WORK/response" && grep -q 'image/jazyky/admin-es.js' "$WORK/response" || { echo "  CHYBA  3.13: /admin.php?${screen%%|*} is not Spanish or has no Spanish script texts"; ERRORS=$((ERRORS+1)); }
  expect "3.13: no English left on /admin.php?${screen%%|*}" "$(leftovers admin-es "$WORK/response")" ""
done
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET jazyk = '' WHERE user = 'admin'"

