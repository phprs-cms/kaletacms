echo "== 3.54 B: news consent at the form – unticked, never required, apart from the processing consent, double opt-in, no second e-mail; the proof of both sign-up paths in Subscribers, the CSV and the personal data export"
B5050_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter +poptavky
b5050_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $db = $app->db(); '"$1" -- "${@:2}"); }
b5050_clear() { rm -f "$WORK"/web/storage/cache/stranky/*.html; }
B5050_WORDING='News 5050: send me news and offers by e-mail.'
B5050_ELEMENT_TEXT='Element 5050: news by e-mail, unsubscribe any time.'
# one page: an enquiry form with the news consent (name, e-mail, the processing consent), a job application form with one,
# and the Newsletter element – published, as the server reads them from the published build only
B5050_PAGE=$(b5050_php '[$b] = Kaleta\Builder\Build::sanitize(["deti" => [
    ["typ" => "formular", "id" => "nf5050", "obsah" => ["nazev" => "News 5050", "souhlas_novinky" => $argv[1], "pole" => [
        ["popisek" => "Name", "typ" => "text", "povinne" => true], ["popisek" => "E-mail", "typ" => "email", "povinne" => true], ["popisek" => "I agree to the processing 5050", "typ" => "souhlas", "povinne" => true]]]],
    ["typ" => "formular", "id" => "ap5050", "obsah" => ["nazev" => "Job 5050", "ucel" => "uchazec", "souhlas_novinky" => $argv[1], "pole" => [
        ["popisek" => "Name", "typ" => "text", "povinne" => true], ["popisek" => "E-mail", "typ" => "email", "povinne" => true]]]],
    ["typ" => "newsletter", "id" => "nl5050", "obsah" => ["souhlas" => $argv[2]]]]]);
  echo $db->insert("stranky", ["seo_link" => "b5050-form", "titulek" => "Form 5050", "text" => "", "zobrazit" => 1, "v_menu" => 0, "zmeneno" => date("Y-m-d H:i:s"), "stavba" => json_encode($b, JSON_UNESCAPED_UNICODE)]);' "$B5050_WORDING" "$B5050_ELEMENT_TEXT")
B5050_SOURCE="stranka:$B5050_PAGE"
b5050_clear
b5050_sign() { local t; t=$(( $(date +%s) - 10 )); echo "$t $(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "$1|$t" "$(setting_plain secret_key)")"; }
# b5050_form <element> <e-mail> [curl args…]: the enquiry form as the browser sends it (name, e-mail, the processing consent ticked unless overridden)
b5050_form() {
  local sig; sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null; sig=$(b5050_sign "formular|$B5050_SOURCE|$1")
  curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -d "zdroj=$B5050_SOURCE" -d "prvek=$1" -d zpet=/b5050-form -d "as_cas=${sig% *}" -d "as_podpis=${sig#* }" -d "p0=Pat 5050" --data-urlencode "p1=$2" "${@:3}"
}
# b5050_element <e-mail> [curl args…]: the Newsletter element's sign-up
b5050_element() {
  local sig; sq "DELETE FROM ka_kontrola_ip WHERE typ = 'odber'" > /dev/null; sig=$(b5050_sign odber)
  curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/odber" --data-urlencode "email=$1" -d zpet=/b5050-form -d "as_cas=${sig% *}" -d "as_podpis=${sig#* }" -d web_adresa= "${@:2}"
}
b5050_mails() { sq "SELECT COUNT(*) FROM ka_posta WHERE komu = '$1' AND predmet LIKE 'Potvrďte odběr novinek%'"; } # confirmation e-mails only
# b5050_result <redirect> <code>: the code when the answer carries result=<code> (or subscription=<code>), else the whole answer
b5050_result() { if printf '%s' "$1" | grep -q "result=$2\|subscription=$2"; then echo "$2"; else echo "$1"; fi; }
b5050_row() { sq "SELECT CONCAT_WS('|', stav, consent_text, consent_source, consent_element, zdroj) FROM ka_odberatele WHERE email = '$1'"; }

curl -s -o "$WORK/b5050.html" "$B/b5050-form"
B5050_BOX=$(grep -o '<input type="checkbox" id="f-nf5050-news"[^>]*>' "$WORK/b5050.html")
expect "3.54 B2 rules 1–2: the form shows one news consent box – unticked, not required – with the author's wording, and the processing consent stays a field of its own" \
  "$(printf '%s' "$B5050_BOX" | grep -c 'name="ka_news" value="1">$')|$(printf '%s' "$B5050_BOX" | grep -c 'checked\|required')|$(grep -c "<span>$B5050_WORDING</span>" "$WORK/b5050.html")|$(grep -c 'name="p2" value="1" required' "$WORK/b5050.html")" "1|0|1|1"
expect "3.54 B1: the Newsletter element names its source and id for the proof" \
  "$(grep -c "name=\"zdroj\" value=\"$B5050_SOURCE\"><input type=\"hidden\" name=\"prvek\" value=\"nl5050\">" "$WORK/b5050.html")" "1"

# rule 2: without the tick nothing happens; rule 3: the tick does not stand in for the processing consent
B5050_A=b5050-a@example.com
B5050_NOTICK=$(b5050_form nf5050 "$B5050_A" -d p2=1)
B5050_NOCONSENT=$(b5050_form nf5050 "$B5050_A" -d ka_news=1)
expect "3.54 B rules 2–3: an enquiry without the tick subscribes nobody; the tick without the required processing consent is refused like any missing field, and nothing is subscribed" \
  "$(b5050_result "$B5050_NOTICK" ok)|$(b5050_result "$B5050_NOCONSENT" 'pole&field=2')|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = '$B5050_A'")|$(b5050_mails "$B5050_A")" "ok|pole&field=2|0|0"

# rule 4: the tick sends the confirmation e-mail – no subscription until the link is posted; the proof is the published wording
B5050_TICK=$(b5050_form nf5050 "$B5050_A" -d p2=1 -d ka_news=1)
expect "3.54 B rule 4: a tick stores an unconfirmed sign-up with its proof (wording, source, element, page), one confirmation e-mail, the enquiry keeps the consent as a row" \
  "$(b5050_result "$B5050_TICK" ok)|$(b5050_row "$B5050_A")|$(b5050_mails "$B5050_A")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email = '$B5050_A' AND data LIKE '%News 5050: send me news%'")" \
  "ok|0|$B5050_WORDING|$B5050_SOURCE|nf5050|/b5050-form|1|1"
B5050_TOKEN=$(sq "SELECT token FROM ka_odberatele WHERE email = '$B5050_A'")
curl -s -o /dev/null "$B/odber?confirm=$B5050_TOKEN"
B5050_AFTER_GET=$(sq "SELECT stav FROM ka_odberatele WHERE email = '$B5050_A'")
curl -s -o /dev/null -X POST "$B/odber?confirm=$B5050_TOKEN"
expect "3.54 B rule 4: opening the link changes nothing, posting it subscribes" "$B5050_AFTER_GET|$(sq "SELECT stav FROM ka_odberatele WHERE email = '$B5050_A'")" "0|1"
# rule 5: a confirmed address gets nothing more, from a form or from the element
B5050_M0=$(b5050_mails "$B5050_A")
b5050_form nf5050 "$B5050_A" -d p2=1 -d ka_news=1 > /dev/null
b5050_element "$B5050_A" > /dev/null
expect "3.54 B rule 5: no second confirmation e-mail to a confirmed address, the proof stays" "$(( $(b5050_mails "$B5050_A") - B5050_M0 ))|$(b5050_row "$B5050_A")" "0|1|$B5050_WORDING|$B5050_SOURCE|nf5050|/b5050-form"

# the form path: an unconfirmed address is mailed once a week at most, and the row is not touched when no e-mail goes out
B5050_B=b5050-b@example.com
b5050_form nf5050 "$B5050_B" -d p2=1 -d ka_news=1 > /dev/null
B5050_B_ROW0=$(sq "SELECT CONCAT(token, datum) FROM ka_odberatele WHERE email = '$B5050_B'")
b5050_form nf5050 "$B5050_B" -d p2=1 -d ka_news=1 > /dev/null
B5050_B_ROW1=$(sq "SELECT CONCAT(token, datum) FROM ka_odberatele WHERE email = '$B5050_B'")
B5050_B_MAILS1=$(b5050_mails "$B5050_B")
sq "UPDATE ka_odberatele SET datum = '$(site_time '-8 days')' WHERE email = '$B5050_B'" > /dev/null
b5050_form nf5050 "$B5050_B" -d p2=1 -d ka_news=1 > /dev/null
expect "3.54 B rule 5 (forms): a repeated enquiry within 7 days sends nothing and leaves the row as it was; after 7 days one more e-mail with a new link" \
  "$([ "$B5050_B_ROW0" = "$B5050_B_ROW1" ] && echo same || echo changed)|$B5050_B_MAILS1|$(b5050_mails "$B5050_B")|$([ "$(sq "SELECT token FROM ka_odberatele WHERE email = '$B5050_B'")" != "${B5050_B_ROW0:0:32}" ] && echo new || echo old)" "same|1|2|new"

# the form path: an address on the send register gets no e-mail and no row at all
B5050_R=b5050-reg@example.com
b5050_php 'Kaleta\Core\SendRegister::record($db, "refused", $argv[1]);' "$B5050_R"
b5050_form nf5050 "$B5050_R" -d p2=1 -d ka_news=1 > /dev/null
expect "3.54 B rule 5 (forms): an address that refused gets no confirmation e-mail and no subscriber row from a form; the enquiry itself is stored" \
  "$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = '$B5050_R'")|$(b5050_mails "$B5050_R")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email = '$B5050_R'")" "0|0|1"
# …while the Newsletter element stays the person's own way back (unit 4700: one e-mail a week)
b5050_element "$B5050_R" > /dev/null
expect "3.54 B1: the Newsletter element still sends the register address its confirmation e-mail (3.51 guard unchanged)" "$(b5050_mails "$B5050_R")" "1"

# the Newsletter element: its own text from the published build; missing or unknown inputs store an empty proof, never block
B5050_E=b5050-e@example.com
B5050_E_ANSWER=$(b5050_element "$B5050_E" -d "zdroj=$B5050_SOURCE" -d prvek=nl5050)
B5050_N=b5050-n@example.com
b5050_element "$B5050_N" > /dev/null
B5050_F=b5050-f@example.com
b5050_element "$B5050_F" -d "zdroj=$B5050_SOURCE" -d prvek=forged1 > /dev/null
expect "3.54 B1: the element stores the text under its field as the proof; a cached page without the inputs and a forged element store an empty proof and still sign up" \
  "$(b5050_result "$B5050_E_ANSWER" ok)|$(b5050_row "$B5050_E")|$(b5050_row "$B5050_N")|$(b5050_row "$B5050_F")|$(b5050_mails "$B5050_N")" \
  "ok|0|$B5050_ELEMENT_TEXT|$B5050_SOURCE|nl5050|/b5050-form|0||||/b5050-form|0||||/b5050-form|1"
# the proof is overwritten only together with a mailed link: a form tick within the week leaves the element's proof
b5050_form nf5050 "$B5050_E" -d p2=1 -d ka_news=1 > /dev/null
expect "3.54 B1: a later form tick without a new e-mail leaves the proof of the mailed link" "$(b5050_row "$B5050_E")|$(b5050_mails "$B5050_E")" "0|$B5050_ELEMENT_TEXT|$B5050_SOURCE|nl5050|/b5050-form|1"

# a job application form never subscribes, even with the box sent by hand
features +applications
b5050_clear
B5050_J=b5050-j@example.com
B5050_JOB=$(b5050_form ap5050 "$B5050_J" -d ka_news=1)
curl -s -o "$WORK/b5050.html" "$B/b5050-form"
expect "3.54 B2: a job application form – no box shown, a forged ka_news subscribes nobody and mails nothing" \
  "$(grep -c 'id="f-ap5050-news"' "$WORK/b5050.html")|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = '$B5050_J'")|$(b5050_mails "$B5050_J")|$(b5050_result "$B5050_JOB" ok)" "0|0|0|ok"
features -applications
# with the Newsletter feature off the box is neither shown nor processed
features -newsletter
b5050_clear
curl -s -o "$WORK/b5050.html" "$B/b5050-form"
B5050_OFF=b5050-off@example.com
b5050_form nf5050 "$B5050_OFF" -d p2=1 -d ka_news=1 > /dev/null
expect "3.54 B2: Newsletter off – no box on the form, a sent tick subscribes nobody; the enquiry is stored without a consent row" \
  "$(grep -c 'name="ka_news"' "$WORK/b5050.html")|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = '$B5050_OFF'")|$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE email = '$B5050_OFF' AND data NOT LIKE '%News 5050: send me%'")" "0|0|1"
features +newsletter

# B3: the proof where people look for it – Subscribers, its CSV and the personal data export
curl -s -b "$JAR" -c "$JAR" -o "$WORK/b5050-list.html" "$B/admin.php?module=subscribers&search=b5050"
expect "3.54 B3: Subscribers – a Consent detail per row with the wording and the form, a note for a sign-up without a stored wording" \
  "$(grep -c '<details data-consent>' "$WORK/b5050-list.html")|$(grep -c "“$B5050_WORDING”" "$WORK/b5050-list.html")|$(grep -c "Formulář: $B5050_SOURCE · nf5050" "$WORK/b5050-list.html")|$(grep -c 'Znění není uloženo' "$WORK/b5050-list.html")" "6|2|2|3"
curl -s -b "$JAR" -o "$WORK/b5050.csv" "$B/admin.php?module=subscribers&action=csv"
expect "3.54 B3: the CSV of confirmed subscribers ends with the wording, the page and the form" \
  "$(head -1 "$WORK/b5050.csv" | grep -c ';"Znění souhlasu";"Stránka souhlasu";"Formulář souhlasu"')|$(grep -c "^$B5050_A;.*;\"$B5050_WORDING\";/b5050-form;\"$B5050_SOURCE · nf5050\"\$" "$WORK/b5050.csv")" "1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=personal"
curl -s -b "$JAR" -o "$WORK/b5050.json" -X POST "$B/admin.php?module=enquiries&action=personal" -d "_csrf=$(csrf)" -d "email=$B5050_A" -d provest=export
expect "3.54 B3: the personal data export of the address carries the proof" \
  "$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo ($j["subscriber"]["consent_text"] ?? "none"), "|", ($j["subscriber"]["consent_source"] ?? ""), "|", ($j["subscriber"]["consent_element"] ?? "");' -- "$WORK/b5050.json")" \
  "$B5050_WORDING|$B5050_SOURCE|nf5050"
# the processing record names the proof (and the form's box among the form's fields)
# – listed by the rule the form shows it by (3.54 review): with job applications off the Job form offers the box too, with them on only the enquiry form
curl -s -b "$JAR" -o "$WORK/b5050-record.html" "$B/admin.php?module=settings&action=processing_record"
B5050_RECORD_OFF=$(grep -c "$B5050_WORDING (souhlas s novinkami e-mailem" "$WORK/b5050-record.html")
features +applications
curl -s -b "$JAR" -o "$WORK/b5050-record.html" "$B/admin.php?module=settings&action=processing_record"
features -applications
expect "3.54 B3: the processing record – the proof line, and the news consent listed with the form's fields (both forms with applications off, only the enquiry form with them on)" \
  "$(grep -c 'Doklad souhlasu: znění' "$WORK/b5050-record.html")|$B5050_RECORD_OFF|$(grep -c "$B5050_WORDING (souhlas s novinkami e-mailem" "$WORK/b5050-record.html")" "2|4|2" # the list and its Markdown copy

# a header or footer of a version with a long code (en-gb, pt-br, zh-hant) – its source cast:paticka:en-gb[:variant] finds
# the element, so the proof is stored and the form's box is processed (a two-letter pattern found neither, 3.54 review)
b5050_php '[$b] = Kaleta\Builder\Build::sanitize(["deti" => [["typ" => "newsletter", "id" => "pl5050", "obsah" => ["souhlas" => $argv[1]]],
    ["typ" => "formular", "id" => "pf5050", "obsah" => ["nazev" => "Part 5050", "souhlas_novinky" => $argv[2], "pole" => [["popisek" => "Name", "typ" => "text"], ["popisek" => "E-mail", "typ" => "email", "povinne" => true]]]]]]);
  foreach (["", "promo"] as $v) { $db->run("REPLACE INTO {casti} (typ, jazyk, varianta, stavba, zmeneno) VALUES (?, ?, ?, ?, NOW())", ["paticka", "en-gb", $v, json_encode($b, JSON_UNESCAPED_UNICODE)]); }' "$B5050_ELEMENT_TEXT" "$B5050_WORDING"
B5050_P=b5050-part@example.com; B5050_V=b5050-variant@example.com; B5050_Q=b5050-partform@example.com
b5050_element "$B5050_P" -d zdroj=cast:paticka:en-gb -d prvek=pl5050 > /dev/null
b5050_element "$B5050_V" -d zdroj=cast:paticka:en-gb:promo -d prvek=pl5050 > /dev/null
B5050_SOURCE_SAVED=$B5050_SOURCE; B5050_SOURCE=cast:paticka:en-gb
B5050_PARTFORM=$(b5050_form pf5050 "$B5050_Q" -d ka_news=1)
B5050_SOURCE=$B5050_SOURCE_SAVED
expect "3.54 review: the Newsletter element in an en-gb footer (and its variant) stores the proof; the form there is found and its news box processed" \
  "$(b5050_row "$B5050_P")|$(b5050_row "$B5050_V" | cut -d'|' -f3-4)|$(b5050_result "$B5050_PARTFORM" ok)|$(sq "SELECT CONCAT_WS('|', consent_text, consent_source, consent_element) FROM ka_odberatele WHERE email = '$B5050_Q'")" \
  "0|$B5050_ELEMENT_TEXT|cast:paticka:en-gb|pl5050|/b5050-form|cast:paticka:en-gb:promo|pl5050|ok|$B5050_WORDING|cast:paticka:en-gb|pf5050"
sq "DELETE FROM ka_casti WHERE typ = 'paticka' AND jazyk = 'en-gb'" > /dev/null
# the consent page is a path on this site, never any text the request carries (3.54 review)
B5050_W=b5050-elsewhere@example.com
b5050_element "$B5050_W" -d zdroj="$B5050_SOURCE" -d prvek=nl5050 --data-urlencode zpet=https://other.example/win-a-prize > /dev/null
expect "3.54 review: a sign-up whose page is another site's address keeps the wording and the element, the page stays empty" "$(b5050_row "$B5050_W")" "0|$B5050_ELEMENT_TEXT|$B5050_SOURCE|nl5050|"

# the suite shares one database: what this section made goes, the settings come back
for B5050_X in "$B5050_A" "$B5050_B" "$B5050_R" "$B5050_E" "$B5050_N" "$B5050_F" "$B5050_J" "$B5050_OFF" "$B5050_P" "$B5050_V" "$B5050_Q" "$B5050_W"; do
  b5050_php 'Kaleta\Core\SendRegister::lift($db, "refused", $argv[1]); Kaleta\Core\RestoreLedger::forget($db, "unsubscribed", $argv[1]);' "$B5050_X"
done
b5050_php 'foreach ($db->all("SELECT id FROM {contacts} WHERE email LIKE ?", ["b5050-%"]) as $c) { Kaleta\Core\Contacts::delete($app, (int) $c["id"]); }'
sq "DELETE FROM ka_odberatele WHERE email LIKE 'b5050-%'; DELETE FROM ka_odber_fronta WHERE email LIKE 'b5050-%'; DELETE FROM ka_poptavky WHERE email LIKE 'b5050-%'; DELETE FROM ka_applications WHERE email LIKE 'b5050-%'; DELETE FROM ka_posta WHERE komu LIKE 'b5050-%'; DELETE FROM ka_stranky WHERE ids = $B5050_PAGE" > /dev/null
features --exact "$B5050_EXT"
b5050_clear
rm -f "$WORK/b5050.html" "$WORK/b5050-list.html" "$WORK/b5050.csv" "$WORK/b5050.json" "$WORK/b5050-record.html"
