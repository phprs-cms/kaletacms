echo "== média, přesměrování, poptávky, uživatelé, písma"
php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 200, 80, 40)); imagejpeg($i, "'"$WORK"'/foto.jpg");'
printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10" onload="alert(1)"><script>alert(2)</script><rect width="20" height="10" fill="red"/></svg>' > "$WORK/logo.svg"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=upload" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/foto.jpg;type=image/jpeg" -F "soubory[]=@$WORK/logo.svg;type=image/svg+xml"
SVG=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE obr_poloha LIKE '%.svg' ORDER BY ido DESC LIMIT 1")
[ -n "$SVG" ] && ! grep -q 'onload\|<script' "$WORK/web/$SVG" && grep -q '<rect' "$WORK/web/$SVG" && echo "  ok     SVG nahrané a vyčištěné" || { echo "  CHYBA  SVG v Médiích"; ERRORS=$((ERRORS+1)); }
# a file over upload_max_filesize (but under post_max_size): a clear message with the limit in MB, not the php.ini shorthand
UPLOAD_LIMIT=$(php -r '$b = fn ($v) => (int) $v * (["k" => 1024, "m" => 1048576, "g" => 1073741824][strtolower(substr(trim($v), -1))] ?? 1); echo $b(ini_get("upload_max_filesize")), " ", $b(ini_get("post_max_size"));')
if [ "${UPLOAD_LIMIT% *}" -gt 0 ] && [ $(( ${UPLOAD_LIMIT% *} + 4096 )) -lt "${UPLOAD_LIMIT#* }" ]; then
  head -c $(( ${UPLOAD_LIMIT% *} + 1024 )) /dev/zero > "$WORK/velky.zip"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=media&action=upload&format=json" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/velky.zip"
  grep -q 'nejvýš [0-9,]* MB' "$WORK/response" && echo "  ok     soubor nad limit serveru: hláška s limitem v MB" || { echo "  CHYBA  hláška o limitu nahrávání"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
fi
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
PHOTO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE ido = $IDO")
expect "nahraný obrázek nedostane popis (alt) ze jména souboru" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT('[', nazev, ']') FROM ka_media WHERE ido = $IDO")" "[]"
php -r '$i = imagecreatetruecolor(800, 800); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); imagejpeg($i, "'"$WORK"'/nova.jpg");'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=replace" -F "_csrf=$TOKEN" -F "ido=$IDO" -F "soubor=@$WORK/nova.jpg;type=image/jpeg"
expect "náhrada souboru zachová adresu a změní rozměry" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(obr_poloha, ' ', obr_width, 'x', obr_height) FROM ka_media WHERE ido = $IDO")" "$PHOTO 800x800"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=save" -d "_csrf=$TOKEN" -d "ido=$IDO" -d nazev=Foto -d ohnisko_x=20 -d ohnisko_y=80
expect "ohnisko ořezu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ohnisko FROM ka_media WHERE ido = $IDO")" "20% 80%"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d z_adresy=/akce-leto -d na_adresu=/kontakty -d typ=302
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/akce-leto"); expect "dočasné přesměrování 302" "$code" "302"
check "hledání v přesměrováních" 200 "/admin.php?module=redirects&search=akce-leto" "akce-leto"
check "protokol s filtrem" 200 "/admin.php?module=changelog&area=stranky" "Protokol"
IDU2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=pozvany --data-urlencode email=pozvany@example.cz -d admin=2 -d pozvat=1)
expect "pozvaný uživatel má odkaz na heslo s delší platností" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obnova_otisk <> '' AND obnova_cas > '$(site_time)' FROM ka_uzivatele WHERE user = 'pozvany'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 -d nazev=Obchodník -d uroven=0 -d 'moduly[]=enquiries' -d 'moduly[]=collections'
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT MAX(idr) FROM ka_role")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=obchodnik --data-urlencode "password=$PASSWORD" -d "admin=r$IDR"
expect "vlastní role dá uživateli své sekce" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' AND u.role = $IDR")" "collections,enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d "idr=$IDR" -d nazev=Obchodník -d uroven=1 -d 'moduly[]=enquiries'
expect "změna role se přenese na členy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.ident_modulu)) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' GROUP BY u.idu")" "1:enquiries"
check "přehled rolí" 200 "/admin.php?module=roles" "Obchodník"
check "uživatelé ukazují vlastní roli" 200 "/admin.php?module=users" "Obchodník"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('require_2fa', 'spravci') ON DUPLICATE KEY UPDATE hodnota = 'spravci'"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?module=pages"); case "$code" in "302 "*action=account*) echo "  ok     povinné dvoufázové přihlášení pustí jen do Můj účet";; *) echo "  CHYBA  vynucení 2FA: $code"; ERRORS=$((ERRORS+1));; esac
# 3.19 (N3183-2): at the gate My account is drawn without the module menu, the collection links and the quick-search palette
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?action=account")
expect "3.19 (N3183-2): at the two-factor gate My account shows no module menu, no collection link and no quick search" \
  "$code|$(grep -c 'id="paleta-data"' "$WORK/response")|$(grep -c 'data-paleta' "$WORK/response")|$(grep -c 'class="menu-kolekce' "$WORK/response")|$(grep -c 'module=pages' "$WORK/response")|$(grep -c 'totp_start' "$WORK/response")" "200|0|0|0|0|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'require_2fa'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$TOKEN" -d co=totp_start
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
grep -q '<svg class="qr"' "$WORK/response" && grep -q 'class="totp-klic"' "$WORK/response" && echo "  ok     zapnutí 2FA ukáže QR kód i klíč k ručnímu zadání" || { echo "  CHYBA  QR kód při zapínání 2FA"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = JSON_SET(IF(hodnota = '' OR hodnota IS NULL, '{}', hodnota), '$.vlastni_pisma', JSON_ARRAY(JSON_OBJECT('nazev', 'Znacka Sans', 'soubor', 'media/2026/01/znacka.woff2', 'tucny', '')), '$.pismo_titulky', 'vlastni-1') WHERE promenna = 'design_system'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '@font-face { font-family: "Znacka Sans"; src: url("/media/2026/01/znacka.woff2")' "$WORK/response" && grep -q -- '--ka-pismo-titulky: "Znacka Sans"' "$WORK/response" && echo "  ok     vlastní písmo z Médií" || { echo "  CHYBA  vlastní písmo"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/home.html" "$B/"; hit_from "$WORK/home.html" > /dev/null # 3.29: the visitor's browser confirms the page view (/kontakty is noindex now)
expect "statistika po stránkách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_stat_stranky")" "1"
# since 2.12 a phone number or an e-mail address anywhere on the page (the footer) keeps web.js for the click counter while the statistics are on – off, the page does without the script
stats_feature 0
curl -s -o "$WORK/response" "$B/kontakty"; grep -q 'image/web.js' "$WORK/response" && echo "  CHYBA  web.js i na stránce, která ho nepotřebuje" && ERRORS=$((ERRORS+1)) || echo "  ok     web.js jen tam, kde je potřeba"
check "3.2: with the Statistics feature off the Analytics tab says so and links to Features" 200 "/admin.php?module=settings&tab=analytics" 'Off – nothing is measured\|Vypnuto – nic se neměří'
stats_feature 1
check "3.2: the Analytics tab shows the statistics feature with a link to Features, no checkbox of its own" 200 "/admin.php?module=settings&tab=analytics" 'admin.php?module=extensions'
grep -q 'name="stats"' "$WORK/response" && { echo "  CHYBA  the Analytics tab still has its own statistics checkbox"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.2: one switch for the statistics – no second checkbox"

