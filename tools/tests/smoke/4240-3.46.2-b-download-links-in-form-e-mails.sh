echo "== 3.46.2 (B): form notification e-mails carry a signed download link per file (never the file) – sign-in and the section still decide, links expire, downloads are logged"
u4240_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
u4240_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/u4240-form.html" | head -1 | sed 's/.*value="//;s/"$//' || true; }
u4240_login() { curl -s -c "$2" -b "$2" -o "$WORK/response" "$B/admin.php"; curl -s -b "$2" -c "$2" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; }
# the newest message of the sink for an address since the mark: the headers and every base64 part decoded
u4240_eml() { php -r '[$h, $b] = explode("\r\n\r\n", (string) file_get_contents($argv[1]), 2) + [1 => ""]; echo $h, "\n"; preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p);
  foreach ($p[1] as $x) { echo base64_decode($x), "\n"; } if ($p[1] === [] && preg_match("/^Content-Transfer-Encoding: base64/mi", $h)) { echo base64_decode((string) preg_replace("/\s+/", "", $b)), "\n"; }' -- "$1"; }
u4240_mail() { local f; f=$(grep -l "^X-Rcpt-To: $1" "$MAIL_SINK_DIR"/*.eml 2>/dev/null | sort | tail -1 || true); if [ -n "$f" ] && [ "$f" \> "$U4240_MARK" ]; then u4240_eml "$f"; fi; }
u4240_mark() { U4240_MARK=$(find "$MAIL_SINK_DIR" -name '*.eml' 2>/dev/null | sort | tail -1 || true); U4240_MARK=${U4240_MARK:-$MAIL_SINK_DIR/0000.eml}; }
u4240_link() { u4240_mail "$1" | grep -o "https\?://[^ ]*action=attachment[^ ]*" | head -1 || true; }
U4240_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_u4240_set; CREATE TABLE ka_u4240_set AS SELECT * FROM ka_nastaveni WHERE promenna IN ('applications_notify_full', 'site_email')" > /dev/null
U4240_ON="$([ -z "$U4240_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$U4240_EXT" | sed 's/,applications//g'),applications"
features "$U4240_ON"; sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_notify_full', '0')" > /dev/null
[ -n "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")" ] || sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_email', 'web-u4240@example.com')" > /dev/null # a sender (a partial run starts without one)
# the jobs collection and its open job straight through PHP (after the restores of 4190–4202 the suite's MCP token is the backup's)
U4240_IDK=$(u4240_php '[$id] = Kaleta\Builder\Presets::createWithPage($app, "jobs", "Jobs U4240", false) ?? [0]; $app->db()->insert("kolekce_polozky", ["idk" => $id, "nazev" => "Fitter U4240", "seo_link" => "fitter-u4240", "data" => "{}", "zobrazit" => 1, "jazyk" => "", "datum" => date("Y-m-d H:i:s"), "poradi" => 0]); echo $id;')
U4240_SLUG=$(sq "SELECT seo_link FROM ka_kolekce WHERE idk = ${U4240_IDK:-0}"); U4240_ITEM=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = ${U4240_IDK:-0} AND seo_link = 'fitter-u4240'")
# the form of the job page: a visible recipient and a hidden copy
u4240_php '$db = $app->db(); $b = Kaleta\Builder\Build::fromJson($db->value("SELECT stavba FROM {kolekce} WHERE idk = ?", ['"${U4240_IDK:-0}"'])); $f = function (?array &$nodes) use (&$f): void { if ($nodes === null) { return; } foreach ($nodes as &$n) { if (($n["typ"] ?? "") === "formular") { $n["obsah"]["prijemce"] = "hr-u4240@example.com"; $n["obsah"]["skryta_kopie"] = "bcc-u4240@example.com"; $n["obsah"]["potvrzeni"] = ""; } if (isset($n["deti"])) { $f($n["deti"]); } } }; $f($b["deti"]); $db->update("kolekce", ["stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)], ["idk" => '"${U4240_IDK:-0}"']);' > /dev/null
# Hana may open Applications only, Olga Enquiries only
U4240_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('u4240-hana', '$U4240_HASH', 'Hana U4240', 'hana-u4240@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('u4240-olga', '$U4240_HASH', 'Olga U4240', 'olga-u4240@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'applications' FROM ka_uzivatele WHERE user = 'u4240-hana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'enquiries' FROM ka_uzivatele WHERE user = 'u4240-olga'" > /dev/null
U4240_JH="$WORK/u4240-hana.jar"; U4240_JO="$WORK/u4240-olga.jar"; rm -f "$U4240_JH" "$U4240_JO"; u4240_login u4240-hana "$U4240_JH" > /dev/null; u4240_login u4240-olga "$U4240_JO" > /dev/null

printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n%% the CV of Petra U4240 bytes-u4240\n' > "$WORK/u4240-cv.pdf"
u4240_page() { rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/u4240-form.html" "$B/$U4240_SLUG/fitter-u4240"
  U4240_SRC=$(u4240_field zdroj); U4240_EL=$(u4240_field prvek); U4240_TIME=$(u4240_field as_cas); U4240_SIG=$(u4240_field as_podpis); sleep 4; sq "DELETE FROM ka_kontrola_ip" > /dev/null; }
u4240_submit() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -F "zdroj=$U4240_SRC" -F "prvek=$U4240_EL" -F "zpet=/$U4240_SLUG/fitter-u4240" -F "as_cas=$U4240_TIME" -F "as_podpis=$U4240_SIG" \
  -F "p0=Petra Dvorak U4240" -F "p1=petra-u4240@example.com" -F "p2=777424000" -F "p3=@$WORK/u4240-cv.pdf;filename=CV Petra.pdf" -F "p4=I fit pipes U4240" -F "p5=1" -F "p6=Fitter U4240"; }

# --- a job application, the full details off
u4240_mark
u4240_page; U4240_R=$(u4240_submit); U4240_A=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_applications WHERE email = 'petra-u4240@example.com'")
U4240_LINK=$(u4240_link hr-u4240@example.com)
expect "3.46.2: the application's notification has one signed download link (applications, its number, the file, an expiry) and no attachment; full details off: nothing of the person, not even the CV's name" \
  "$(echo "$U4240_R" | grep -o 'result=ok')|$(echo "$U4240_LINK" | grep -c "module=applications&action=attachment&id=$U4240_A&index=0&expires=[0-9]*&sig=[a-f0-9]\{64\}")|$(u4240_mail hr-u4240@example.com | grep -ci 'Content-Disposition: attachment')|$(u4240_mail hr-u4240@example.com | grep -c 'Petra\|777424000\|I fit pipes')|$(u4240_mail hr-u4240@example.com | grep -c 'Fitter U4240')|$(u4240_mail hr-u4240@example.com | grep -c "application-$U4240_A-1.pdf")" \
  "result=ok|1|0|0|1|1"
expect "3.46.2: the hidden copy goes alone, without the links; the applicant's confirmation has no link either" \
  "$(u4240_mail bcc-u4240@example.com | grep -c 'Fitter U4240' | sed 's/^[1-9][0-9]*$/yes/')|$(u4240_mail bcc-u4240@example.com | grep -c 'action=attachment')|$(u4240_mail bcc-u4240@example.com | grep '^X-Rcpt-To:' | tr -d '\r')|$(u4240_mail petra-u4240@example.com | grep -c 'Fitter\|U4240' | sed 's/^[1-9][0-9]*$/yes/')|$(u4240_mail petra-u4240@example.com | grep -c 'action=attachment')" \
  "yes|0|X-Rcpt-To: bcc-u4240@example.com|yes|0"

# opening the link: signed out → sign-in and back to the link; without the section → 403; with it → the file
U4240_PATH="/${U4240_LINK#*://*/}"
U4240_JN="$WORK/u4240-new.jar"; rm -f "$U4240_JN"
U4240_OUT=$(curl -s -c "$U4240_JN" -b "$U4240_JN" -o "$WORK/response" -w '%{http_code}' "$B$U4240_PATH")
U4240_BACK=$(curl -s -b "$U4240_JN" -c "$U4240_JN" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=u4240-hana" --data-urlencode "password=$PASSWORD")
U4240_LOG0=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
U4240_GET=$(curl -s -b "$U4240_JN" -D "$WORK/u4240-headers" -o "$WORK/u4240-got.pdf" -w '%{http_code}' "${U4240_BACK:-$B/admin.php}")
expect "3.46.2: signed out, the link shows the sign-in; after it the sign-in goes back to the link, which serves the CV as a neutral download (nosniff, no caching)" \
  "$U4240_OUT|$(grep -c 'name="password"' "$WORK/response" | sed 's/^[1-9][0-9]*$/yes/')|$(echo "$U4240_BACK" | grep -c "action=attachment&id=$U4240_A&index=0&expires=")|$U4240_GET|$(grep -c 'bytes-u4240' "$WORK/u4240-got.pdf")|$(grep -ci "filename\*=UTF-8''application-$U4240_A-1.pdf" "$WORK/u4240-headers")|$(grep -ci '^X-Content-Type-Options: nosniff' "$WORK/u4240-headers")|$(grep -ci '^Cache-Control: no-store' "$WORK/u4240-headers")" \
  "200|yes|1|200|1|1|1|1"
expect "3.46.2: the download is in the change log – who, which application, which file, from the e-mail link" \
  "$(sq "SELECT CONCAT(jmeno, '|', modul, '|', akce, '|', popis LIKE '%#$U4240_A%', '|', popis LIKE '%1%') FROM ka_protokol WHERE idp > $U4240_LOG0 ORDER BY idp DESC LIMIT 1")" "Hana U4240|applications|attachment|1|1"
expect "3.46.2: the link never serves without the section (Olga: Enquiries only) – 403; an administrator gets the file" \
  "$(curl -s -b "$U4240_JO" -o "$WORK/response" -w '%{http_code}' "$B$U4240_PATH")|$(grep -c 'bytes-u4240' "$WORK/response")|$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B$U4240_PATH")|$(grep -c 'bytes-u4240' "$WORK/response")" "403|0|200|1"
U4240_EXPIRED=$(u4240_php 'echo Kaleta\Core\FileLink::path(Kaleta\Core\FileLink::key($app), "applications", '"${U4240_A:-0}"', 0, time() - 60);')
expect "3.46.2: a changed signature or record is refused (403), an expired link says so and links the record (410) – never the file" \
  "$(curl -s -b "$U4240_JH" -o "$WORK/response" -w '%{http_code}' "$B${U4240_PATH%?}x")|$(grep -c 'bytes-u4240' "$WORK/response")|$(curl -s -b "$U4240_JH" -o "$WORK/response" -w '%{http_code}' "$B$(echo "$U4240_PATH" | sed "s/id=$U4240_A&/id=$((U4240_A + 1000))\&/")")|$(curl -s -b "$U4240_JH" -o "$WORK/response" -w '%{http_code}' "$B/$U4240_EXPIRED")|$(grep -c 'bytes-u4240' "$WORK/response")|$(grep -c "action=detail&amp;id=$U4240_A" "$WORK/response")" \
  "403|0|403|410|0|1"

# --- the full details on: the e-mail lists the fields, then the link
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_notify_full', '1')" > /dev/null
u4240_mark
u4240_page; u4240_submit > /dev/null
expect "3.46.2: with the full details on, the notification lists the fields and still carries the link; the hidden copy has the fields without the link" \
  "$(u4240_mail hr-u4240@example.com | grep -c 'Petra Dvorak U4240\|777424000\|I fit pipes U4240')|$(u4240_link hr-u4240@example.com | grep -c 'module=applications&action=attachment')|$(u4240_mail bcc-u4240@example.com | grep -c 'Petra Dvorak U4240' | sed 's/^[1-9][0-9]*$/yes/')|$(u4240_mail bcc-u4240@example.com | grep -c 'action=attachment')" \
  "3|1|yes|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
expect "3.46.2: the Applications settings warn that copies stay in the recipients' mailboxes" "$(grep -c 'nedosáhne\|does not reach them' "$WORK/response" | sed 's/^[1-9][0-9]*$/yes/')" "yes"

# --- the same form as an enquiry form (Applications off): every field, then the link to the enquiry's file
features "$U4240_ON" -applications
u4240_mark; U4240_E0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky")
u4240_page; U4240_R=$(u4240_submit); U4240_E=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky WHERE idp > $U4240_E0")
U4240_ELINK=$(u4240_link hr-u4240@example.com); U4240_EPATH="/${U4240_ELINK#*://*/}"
expect "3.46.2: an enquiry's notification lists every field and a signed link to its file, no attachment; the hidden copy and the visitor get no link" \
  "$(echo "$U4240_R" | grep -o 'result=ok')|$(u4240_mail hr-u4240@example.com | grep -c 'Petra Dvorak U4240\|777424000\|I fit pipes U4240')|$(echo "$U4240_ELINK" | grep -c "module=enquiries&action=attachment&id=$U4240_E&field=[0-9]*&expires=[0-9]*&sig=[a-f0-9]\{64\}")|$(u4240_mail hr-u4240@example.com | grep -ci 'Content-Disposition: attachment')|$(u4240_mail bcc-u4240@example.com | grep -c 'action=attachment')|$(u4240_mail petra-u4240@example.com | grep -c 'action=attachment')" \
  "result=ok|3|1|0|0|0"
U4240_LOG0=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
expect "3.46.2: the enquiry link – Hana (Applications only) 403, Olga (Enquiries) the file, logged; a changed signature 403" \
  "$(curl -s -b "$U4240_JH" -o "$WORK/response" -w '%{http_code}' "$B$U4240_EPATH")|$(curl -s -b "$U4240_JO" -o "$WORK/u4240-got.pdf" -w '%{http_code}' "$B$U4240_EPATH")|$(grep -c 'bytes-u4240' "$WORK/u4240-got.pdf")|$(sq "SELECT CONCAT(jmeno, '|', modul, '|', akce, '|', popis LIKE '%#$U4240_E%') FROM ka_protokol WHERE idp > $U4240_LOG0 ORDER BY idp DESC LIMIT 1")|$(curl -s -b "$U4240_JO" -o /dev/null -w '%{http_code}' "$B${U4240_EPATH%?}x")" \
  "403|200|1|Olga U4240|enquiries|attachment|1|403"
expect "3.46.2: find_personal_data (Forms::notificationCopies) names where notification e-mails with the details went" \
  "$(u4240_php 'echo implode(" ", Kaleta\Front\Forms::notificationCopies($app, Kaleta\Core\PersonalData::find($app->db(), "petra-u4240@example.com", $app)));' | grep -c 'hr-u4240@example.com' | sed 's/^[1-9][0-9]*$/yes/')" "yes"

# clean-up: the records, the files, the users, the collection and the settings as they were
[ "${U4240_A:-0}" -gt 0 ] && u4240_php '$ids = array_map("intval", array_column($app->db()->all("SELECT id FROM {applications} WHERE email = ?", ["petra-u4240@example.com"]), "id")); Kaleta\Core\Applications::delete($app, $ids, false);' > /dev/null 2>&1 || true
for U4240_F in $(sq "SELECT data FROM ka_poptavky WHERE idp > $U4240_E0" | grep -o '[0-9]\{4\}/[0-9]\{2\}/[a-f0-9]\{24\}\.pdf'); do rm -f "$WORK/web/storage/prilohy/$U4240_F"; done
sq "DELETE FROM ka_applications WHERE email = 'petra-u4240@example.com'; DELETE FROM ka_poptavky WHERE idp > $U4240_E0; DELETE FROM ka_kolekce_polozky WHERE idk = ${U4240_IDK:-0}; DELETE FROM ka_kolekce WHERE idk = ${U4240_IDK:-0};
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user IN ('u4240-hana', 'u4240-olga')); DELETE FROM ka_uzivatele WHERE user IN ('u4240-hana', 'u4240-olga');
  DELETE FROM ka_nastaveni WHERE promenna IN ('applications_notify_full', 'site_email'); INSERT INTO ka_nastaveni SELECT * FROM ka_u4240_set; DROP TABLE ka_u4240_set; DELETE FROM ka_kontrola_ip" > /dev/null; features --exact "$U4240_EXT"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/u4240-cv.pdf" "$WORK/u4240-got.pdf" "$WORK/u4240-form.html" "$WORK/u4240-headers" "$U4240_JH" "$U4240_JO" "$U4240_JN"
