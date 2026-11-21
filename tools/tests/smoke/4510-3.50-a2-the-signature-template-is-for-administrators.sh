echo "== 3.50 A2: the signature template of people without a Team record is for administrators – an editor of Business details neither sees nor saves it (N135)"
a510_login() { rm -f "$2"; curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
sq "DROP TABLE IF EXISTS ka_a510_set; CREATE TABLE ka_a510_set AS SELECT * FROM ka_nastaveni" > /dev/null # the editor's save posts one field
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('email_signature_template', '{name}\nA510 administrators line')" > /dev/null
A510_NOW=$(site_time); A510_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('a510-editor', '$A510_HASH', 'Editor A510', 'editor-a510@example.com', 'en', 1, '$A510_NOW', '$A510_NOW', 0, 'off')" > /dev/null
A510_ED=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a510-editor'"); sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($A510_ED, 'business')" > /dev/null
A510_JAR="$WORK/a510-editor.jar"; a510_login a510-editor "$A510_JAR"
A510_CODE=$(curl -s -b "$A510_JAR" -c "$A510_JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=business")
A510_FIELD=$(grep -c 'name="email_signature_template"' "$WORK/response" || true)
curl -s -b "$A510_JAR" -c "$A510_JAR" -o /dev/null -X POST "$B/admin.php?module=business&action=save" -d "_csrf=$(csrf)" --data-urlencode "email_signature_template=Pay to IBAN XX00 A510"
expect "3.50 N135: an editor's Business details show no signature template and a posted one is ignored" \
  "$A510_CODE|$A510_FIELD|$(sq "SELECT hodnota LIKE '%administrators line%' FROM ka_nastaveni WHERE promenna = 'email_signature_template'")|$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%IBAN XX00 A510%'")" "200|0|1|0"
expect "3.50 N135: the administrator still sees the template in Business details" \
  "$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=business")|$(grep -c 'name="email_signature_template"' "$WORK/response")" "200|1"
# clean-up
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${A510_ED:-0}; DELETE FROM ka_uzivatele WHERE user = 'a510-editor';
  DELETE FROM ka_nastaveni; INSERT INTO ka_nastaveni SELECT * FROM ka_a510_set; DROP TABLE ka_a510_set" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
rm -f "$A510_JAR"
