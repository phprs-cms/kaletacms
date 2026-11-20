echo "== 3.47 A: My account in six tabs, Users as a hub with Roles and Departments, the bell about an absence (the days only)"
a347_page() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
a347_has() { grep -c "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
A347_ADM=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'"); A347_ADM_NAME=$(sq "SELECT IF(jmeno = '', user, jmeno) FROM ka_uzivatele WHERE idu = $A347_ADM")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, notify_email) VALUES ('deputy-a347', 'x', 'Dana Deputy', 'deputy-a347@example.com', 1, 'instant'),
    ('away-a347', 'x', 'Adam Away', 'away-a347@example.com', 1, 'instant')" > /dev/null
A347_DEP=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'deputy-a347'"); A347_AWAY=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'away-a347'")

# U68: each tab answers and holds only its own forms
expect "3.47 U68: my details – the profile and the notifications, no password or token forms" \
  "$(a347_page 'action=account')|$(a347_has 'name="pozice"')|$(a347_has 'id="notifications"')|$(a347_has 'id="two-factor"')|$(a347_has 'id="absences"')|$(a347_has 'name="co" value="token_novy"')|$(a347_has 'data-account-tabs=')" "200|1|1|0|0|0|1"
expect "3.47 U68: absence – the absences only, posted to their tab" \
  "$(a347_page 'action=account&tab=absence')|$(a347_has 'id="absences"')|$(a347_has 'name="pozice"')|$(a347_has 'action="/admin.php?action=account&amp;tab=absence"')|$(a347_has 'nobody – whom I report to\|nikdo – můj nadřízený')" "200|1|0|1|1"
expect "3.47 U68: sign-in and security – password, two-factor and passkeys, no profile" \
  "$(a347_page 'action=account&tab=security')|$(a347_has 'id="two-factor"')|$(a347_has 'id="passkeys"')|$(a347_has 'name="co" value="heslo"')|$(a347_has 'name="pozice"')|$(a347_has 'image/klice.js')" "200|1|1|1|0|1"
expect "3.47 U68: Claude – the connections and a new token only; the phone select names all six tabs" \
  "$(a347_page 'action=account&tab=claude')|$(a347_has 'id="claude"')|$(a347_has 'id="two-factor"')|$(sed -n '/id="ucet-zalozka"/,/<\/select>/p' "$WORK/response" | grep -c '<option')" "200|1|0|6"
expect "3.47 U68/U67: the org chart answers at its old address as the Colleagues tab by department; A–Z is the other view" \
  "$(a347_page 'action=account&tab=org_chart')|$(a347_has 'tab=colleagues&amp;view=departments" class="aktivni"')|$(a347_page 'action=account&tab=colleagues')|$(a347_has 'class="kolegove"')|$(a347_has 'tab=colleagues" class="aktivni"')" "200|1|200|1|1"
expect "3.47 U68: an unknown tab is my details" "$(a347_page 'action=account&tab=nonsense')|$(a347_has 'name="pozice"')" "200|1"

# U68: a POST returns to the tab of its step, whatever the address of the form said
A347_CSRF=$(a347_page 'action=account' > /dev/null; csrf)
A347_NOTIFY=$(sq "SELECT notify_email FROM ka_uzivatele WHERE idu = $A347_ADM")
expect "3.47 U68: saving the notifications returns to my details, an absence to its tab" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=account&tab=security" -d "_csrf=$A347_CSRF" -d co=notifications -d "notify_email=${A347_NOTIFY:-instant}" | sed 's#.*/admin.php#admin.php#')|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=account" -d "_csrf=$A347_CSRF" -d co=absence_add -d starts_on=x -d ends_on=y -d reason=holiday | sed 's#.*/admin.php#admin.php#')" \
  "admin.php?action=account|admin.php?action=account&tab=absence"

# N166: my own absence with a deputy – the deputy gets the bell with the days, I get none; never the reason
A347_MARK=$(find "$MAIL_SINK_DIR" -name '*.eml' 2>/dev/null | sort | tail -1 || true); A347_MARK=${A347_MARK:-$MAIL_SINK_DIR/0000.eml}
A347_FROM=$(site_date '+30 days'); A347_TO=$(site_date '+34 days')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account&tab=absence" -d "_csrf=$A347_CSRF" -d co=absence_add -d "starts_on=$A347_FROM" -d "ends_on=$A347_TO" -d reason=holiday -d "deputy_id=$A347_DEP"
expect "3.47 N166: the deputy gets one 'deputy_named' with my name and the days; I get nothing; no reason anywhere" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(kind, '|', excerpt, '|', target) SEPARATOR ',') FROM ka_notifications WHERE user_id = $A347_DEP")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $A347_ADM AND kind IN ('deputy_named', 'absence_added')")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind IN ('deputy_named', 'absence_added') AND (excerpt LIKE '%holiday%' OR excerpt LIKE '%Dovolen%')")" \
  "deputy_named|$A347_ADM_NAME: $A347_FROM – $A347_TO||0|0"
# an administrator records an absence for someone else in Users: the person learns it, the deputy too
A347_CSRF=$(a347_page "module=users&action=edit&id=$A347_AWAY" > /dev/null; csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=absence_add" -d "_csrf=$A347_CSRF" -d "idu=$A347_AWAY" -d "starts_on=$A347_FROM" -d "ends_on=$A347_TO" -d reason=day_off -d "deputy_id=$A347_DEP"
expect "3.47 N166: the person gets 'absence_added' from the administrator, the deputy a second 'deputy_named'" \
  "$(sq "SELECT CONCAT(kind, '|', actor_id = $A347_ADM, '|', excerpt) FROM ka_notifications WHERE user_id = $A347_AWAY")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $A347_DEP AND kind = 'deputy_named'")" \
  "absence_added|1|Adam Away: $A347_FROM – $A347_TO|2"
# the instant e-mails: who and when, never the reason
for i in $(seq 1 50); do [ "$(grep -l '^X-Rcpt-To: away-a347@example.com' "$MAIL_SINK_DIR"/*.eml 2>/dev/null | wc -l | tr -d ' ')" -ge 1 ] && break; sleep 0.2; done
A347_MAILS=$(for f in $(grep -l '^X-Rcpt-To: \(deputy\|away\)-a347@example.com' "$MAIL_SINK_DIR"/*.eml 2>/dev/null | sort); do [ "$f" \> "$A347_MARK" ] && php -r '[$h, $b] = explode("\r\n\r\n", (string) file_get_contents($argv[1]), 2) + [1 => ""]; echo $h, "\n", preg_match("/^Content-Transfer-Encoding: base64/mi", $h) ? base64_decode((string) preg_replace("/\s+/", "", $b)) : $b, "\n";' -- "$f"; done)
expect "3.47 N166: three instant e-mails carry the name and the days, none the reason" \
  "$(printf %s "$A347_MAILS" | grep -c "^X-Rcpt-To:")|$(printf %s "$A347_MAILS" | grep -c "$A347_FROM – $A347_TO" | sed 's/^[1-9][0-9]*$/yes/')|$(printf %s "$A347_MAILS" | grep -ci 'holiday\|dovolen\|day off\|volno')" "3|yes|0"
# the bell: the deputy's note opens My account → Colleagues
A347_NOTE=$(sq "SELECT MIN(id) FROM ka_notifications WHERE user_id = $A347_DEP")
expect "3.47 N166: the note has a place (My account) and opens the Colleagues tab" \
  "$(php -r 'require $argv[1] . "/system/bootstrap.php"; $app = new Kaleta\Core\App(require $argv[1] . "/config.php"); echo Kaleta\Core\Inbox::one($app->db(), (int) $argv[2], (int) $argv[3])["place"]["path"] ?? "none";' -- "$WORK/web" "$A347_DEP" "${A347_NOTE:-0}")" \
  "admin.php?action=account&tab=colleagues"

# U67: Users, Roles and Departments as one hub
expect "3.47 U67: Roles and Departments show the hub's tabs; the Users list no longer has the two buttons" \
  "$(a347_page 'module=roles')|$(a347_has 'nav class="zalozky zalozky-hub"')|$(a347_page 'module=users&action=departments')|$(a347_has 'nav class="zalozky zalozky-hub"')|$(a347_page 'module=users')|$(a347_has 'href="/admin.php?module=roles" class="navigace"')" "200|1|200|1|200|0"
# departments: two ticked forms are stored; Edit leads to the form; a head of two departments has one full card
A347_CSRF=$(a347_page 'module=users&action=departments' > /dev/null; csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=department_save" -d "_csrf=$A347_CSRF" -d id=0 --data-urlencode "name=North A347" -d "head_id=$A347_DEP" -d parent_id= \
  --data-urlencode "enquiry_forms[]=Contact A347" --data-urlencode "enquiry_forms[]=Quote A347"
A347_D1=$(sq "SELECT id FROM ka_departments WHERE name = 'North A347'"); A347_D1="${A347_D1:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=department_save" -d "_csrf=$A347_CSRF" -d id=0 --data-urlencode "name=South A347" -d "head_id=$A347_DEP" -d parent_id=
sq "UPDATE ka_uzivatele SET department_id = $A347_D1 WHERE idu = $A347_DEP" > /dev/null
expect "3.47 U67: both ticked forms are stored" "$(sq "SELECT REPLACE(enquiry_forms, CHAR(10), '|') FROM ka_departments WHERE id = $A347_D1")" "Contact A347|Quote A347"
expect "3.47 U67: the list has Edit before Remove; the form shows the saved forms ticked, marked without enquiries" \
  "$(a347_page 'module=users&action=departments')|$(a347_has "action=departments&amp;department=$A347_D1\">Upravit</a>\|action=departments&amp;department=$A347_D1\">Edit</a>")|$(a347_page "module=users&action=departments&department=$A347_D1")|$(grep -c 'name="enquiry_forms\[\]" value="Contact A347" checked' "$WORK/response")|$(a347_has '(zatím bez poptávek)\|(no enquiries yet)')" "200|1|200|1|1"
a347_page 'action=account&tab=colleagues&view=departments' > /dev/null
expect "3.47 U67: a head of two departments has one full card, the second time a short line" \
  "$(php -r '$d = new DOMDocument(); @$d->loadHTML((string) file_get_contents($argv[1])); $x = new DOMXPath($d); $full = 0; $short = 0;
    foreach ($x->query("//li[contains(concat(\" \", @class, \" \"), \" kolega \")]") as $li) { if (str_contains($li->textContent, "Dana Deputy")) { str_contains(" " . $li->getAttribute("class") . " ", " kolega-znovu ") ? $short++ : $full++; } }
    echo $full, "|", $short, "|", $x->query("//h2[contains(., \"North A347\")]")->length;' -- "$WORK/response")" "1|1|1"

sq "DELETE FROM ka_notifications WHERE user_id IN ($A347_DEP, $A347_AWAY, $A347_ADM) AND kind IN ('deputy_named', 'absence_added'); DELETE FROM ka_absences WHERE user_id IN ($A347_ADM, $A347_AWAY);
    UPDATE ka_uzivatele SET department_id = NULL WHERE idu = $A347_DEP; DELETE FROM ka_departments WHERE name IN ('North A347', 'South A347'); DELETE FROM ka_uzivatele WHERE idu IN ($A347_DEP, $A347_AWAY)" > /dev/null
