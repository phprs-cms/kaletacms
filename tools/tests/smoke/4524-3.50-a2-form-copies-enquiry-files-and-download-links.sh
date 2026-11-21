echo "== 3.50 A2: copies of a notification are queued after a failed delivery and expire with their links; enquiry files get a content check; a download link from another site asks for one click (L2, L8, L10, L11)"
a524_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
a524_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/a524-form.html" | head -1 | sed 's/.*value="//;s/"$//' || true; }
A524_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "DROP TABLE IF EXISTS ka_a524_set; CREATE TABLE ka_a524_set AS SELECT * FROM ka_nastaveni WHERE promenna IN ('site_email')" > /dev/null
features +poptavky -applications
[ -n "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")" ] || sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_email', 'web-a524@example.com')" > /dev/null
A524_IDK=$(a524_php '[$id] = Kaleta\Builder\Presets::createWithPage($app, "jobs", "Jobs A524", false) ?? [0]; $app->db()->insert("kolekce_polozky", ["idk" => $id, "nazev" => "Joiner A524", "seo_link" => "joiner-a524", "data" => "{}", "zobrazit" => 1, "jazyk" => "", "datum" => date("Y-m-d H:i:s"), "poradi" => 0]); echo $id;')
A524_SLUG=$(sq "SELECT seo_link FROM ka_kolekce WHERE idk = ${A524_IDK:-0}")
a524_php '$db = $app->db(); $b = Kaleta\Builder\Build::fromJson($db->value("SELECT stavba FROM {kolekce} WHERE idk = ?", ['"${A524_IDK:-0}"'])); $f = function (?array &$nodes) use (&$f): void { if ($nodes === null) { return; } foreach ($nodes as &$n) { if (($n["typ"] ?? "") === "formular") { $n["obsah"]["prijemce"] = "hr-a524@example.com"; $n["obsah"]["skryta_kopie"] = "bcc1-a524@example.com, bcc2-a524@example.com"; $n["obsah"]["potvrzeni"] = ""; } if (isset($n["deti"])) { $f($n["deti"]); } } }; $f($b["deti"]); $db->update("kolekce", ["stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)], ["idk" => '"${A524_IDK:-0}"']);' > /dev/null
a524_submit() { # a524_submit <file> <file name>
  rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/a524-form.html" "$B/$A524_SLUG/joiner-a524"
  local t s; t=$(a524_field as_cas); s=$(a524_field as_podpis); sleep 4; sq "DELETE FROM ka_kontrola_ip" > /dev/null
  curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -F "zdroj=$(a524_field zdroj)" -F "prvek=$(a524_field prvek)" -F "zpet=/$A524_SLUG/joiner-a524" -F "as_cas=$t" -F "as_podpis=$s" \
    -F "p0=Karel A524" -F "p1=karel-a524@example.com" -F "p2=777524000" -F "p3=@$1;filename=$2" -F "p4=Hello A524" -F "p5=1" -F "p6=Joiner A524"; }

# --- L10: an enquiry's file with markup inside a .txt is refused – nothing is stored
printf '<html><body><script>alert(1)</script></body></html>\n' > "$WORK/a524-bad.txt"
A524_E0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky")
expect "3.50 L10: an enquiry's .txt with markup is refused by its content (\"check the field\"); no enquiry is stored" \
  "$(a524_submit "$WORK/a524-bad.txt" notes.txt | grep -o 'result=[a-z]*')|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp > $A524_E0")" "result=pole|0"

# --- L2, L8: the mail server is down – the visible copy is tried once, the two hidden copies are queued without a try; all expire with the links
printf 'Plain notes of Karel A524\n' > "$WORK/a524-notes.txt"
A524_M0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('smtp_port', '$((PORT + 24))')" > /dev/null
A524_R=$(a524_submit "$WORK/a524-notes.txt" notes.txt)
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES" > /dev/null
A524_E=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_poptavky WHERE idp > $A524_E0")
expect "3.50 L2, L8: with the mail server down the enquiry is stored and its three notification copies wait in the queue, each with the expiry of its links (30 days)" \
  "$(echo "$A524_R" | grep -o 'result=ok')|$(sq "SELECT COUNT(*) FROM ka_posta WHERE idp > $A524_M0 AND komu LIKE '%-a524@example.com' AND odeslano IS NULL AND telo LIKE '%\"expires\":%'")|$(sq "SELECT MIN(CAST(JSON_EXTRACT(telo, '$.expires') AS SIGNED)) - UNIX_TIMESTAMP() > 29 * 86400 FROM ka_posta WHERE idp > $A524_M0 AND komu LIKE '%-a524@example.com'")" \
  "result=ok|3|1"
A524_SINK0=$(find "$MAIL_SINK_DIR" -name '*.eml' | wc -l | tr -d ' ')
A524_LATER=$(a524_php '$ok = Kaleta\Core\Mail::send($app->settings(), "later-a524@example.com", "A524 copy", "text", "", [], true, "", [], 0, false); $r = $app->db()->one("SELECT pokusu, chyba, odeslano IS NULL AS waiting, dalsi_pokus <= NOW() AS due FROM {posta} WHERE komu = ? ORDER BY idp DESC LIMIT 1", ["later-a524@example.com"]); echo json_encode([$ok, (int) $r["pokusu"], $r["chyba"], (int) $r["waiting"], (int) $r["due"]]);')
expect "3.50 L2: Mail::send without a try only queues – nothing reaches the (working) mail server in the request, the message is due at once for the queue" \
  "$A524_LATER|$(( $(find "$MAIL_SINK_DIR" -name '*.eml' | wc -l | tr -d ' ') - A524_SINK0 ))" '[false,0,"",1,1]|0'
sq "DELETE FROM ka_posta WHERE idp > $A524_M0 AND (komu LIKE '%-a524@example.com' OR komu LIKE '%a524@example.com%')" > /dev/null

# --- L11: the download link of the enquiry opened from another site shows a Download button; the file and the log line come with the click
A524_PATH=$(a524_php '$data = json_decode((string) $app->db()->value("SELECT data FROM {poptavky} WHERE idp = ?", ['"${A524_E:-0}"']), true) ?: []; $file = 0; foreach ($data as $i => $d) { if (($d[2] ?? "") !== "") { $file = (int) $i; } } echo Kaleta\Core\FileLink::path(Kaleta\Core\FileLink::key($app), "enquiries", '"${A524_E:-0}"', $file, time() + 3600);')
A524_LOG0=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
A524_CROSS=$(curl -s -b "$JAR" -c "$JAR" -H 'Sec-Fetch-Site: cross-site' -o "$WORK/response" -w '%{http_code}' "$B/$A524_PATH")
A524_FORM="$(grep -c '<form method="post" action="[^"]*action=attachment' "$WORK/response")|$(grep -c 'Plain notes of Karel' "$WORK/response")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $A524_LOG0 AND modul = 'enquiries' AND akce = 'attachment'")"
A524_POST=$(curl -s -b "$JAR" -c "$JAR" -H 'Sec-Fetch-Site: same-origin' -o "$WORK/a524-got.txt" -w '%{http_code}' -X POST "$B/$A524_PATH" -d "_csrf=$(csrf)")
expect "3.50 L11: from another site the link answers a page with one Download button – no file, no log line; the click (POST with the session's token) gives the file and logs it" \
  "$A524_CROSS|$A524_FORM|$A524_POST|$(grep -c 'Plain notes of Karel A524' "$WORK/a524-got.txt")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE idp > $A524_LOG0 AND modul = 'enquiries' AND akce = 'attachment'")" \
  "200|1|0|0|200|1|1"
expect "3.50 L11: a link opened from the administration itself (same-origin) or by a browser without the header downloads at once" \
  "$(curl -s -b "$JAR" -H 'Sec-Fetch-Site: same-origin' -o "$WORK/a524-got.txt" -w '%{http_code}' "$B/$A524_PATH")|$(grep -c 'Plain notes of Karel A524' "$WORK/a524-got.txt")|$(curl -s -b "$JAR" -o "$WORK/a524-got.txt" -w '%{http_code}' "$B/$A524_PATH")|$(grep -c 'Plain notes of Karel A524' "$WORK/a524-got.txt")" \
  "200|1|200|1"

# clean-up
for A524_F in $(sq "SELECT data FROM ka_poptavky WHERE idp > $A524_E0" | grep -o '[0-9]\{4\}/[0-9]\{2\}/[a-f0-9]\{24\}\.txt'); do rm -f "$WORK/web/storage/prilohy/$A524_F"; done
sq "DELETE FROM ka_poptavky WHERE idp > $A524_E0; DELETE FROM ka_kolekce_polozky WHERE idk = ${A524_IDK:-0}; DELETE FROM ka_kolekce WHERE idk = ${A524_IDK:-0};
  DELETE FROM ka_nastaveni WHERE promenna IN ('site_email'); INSERT INTO ka_nastaveni SELECT * FROM ka_a524_set; DROP TABLE ka_a524_set; DELETE FROM ka_kontrola_ip" > /dev/null
features --exact "$A524_EXT"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/a524-bad.txt" "$WORK/a524-notes.txt" "$WORK/a524-got.txt" "$WORK/a524-form.html"
