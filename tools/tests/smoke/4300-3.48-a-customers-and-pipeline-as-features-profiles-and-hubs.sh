echo "== 3.48 A: Customers and the Pipeline as features (off: hidden, contacts still kept), the profiles of Features, the Sales and Addresses hubs"
a348_page() { curl -s -b "${2:-$JAR}" -c "${2:-$JAR}" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
a348_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
a348_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
A348_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
A348_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A348_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A348_ADMIN, 'a348 full', 'full', 'all', SHA2('$A348_TOKEN', 256), '$(site_time)')" > /dev/null
a348_tools() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $A348_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  | php -r 'echo implode(",", array_intersect(array_column(json_decode(stream_get_contents(STDIN), true)["result"]["tools"] ?? [], "name"), ["list_contacts", "get_contact", "save_contact", "log_activity", "merge_contacts", "list_deals", "save_deal", "move_deal", "pipeline_summary"]));'; }
a348_counts() { sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_contacts WHERE email_norm IS NULL OR email_norm <> 'erik-a348@example.com'), '|', (SELECT COUNT(*) FROM ka_deals))"; } # the records this section did not make itself

# a deal and a contact of their own, and the counts before anything is switched off
features +quotes
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('owner-a348@example.com', 'owner-a348@example.com', 'Olga A348', 'manual', '$(site_time)', '$(site_time)', '$(site_time)');
  INSERT INTO ka_deals (title, contact_id, owner_id, stage, created_at, updated_at) VALUES ('Deal A348', LAST_INSERT_ID(), NULL, 'new', '$(site_time)', '$(site_time)')" > /dev/null
A348_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'owner-a348@example.com'"); A348_DEAL=$(sq "SELECT id FROM ka_deals WHERE title = 'Deal A348'")
sq "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES ($A348_DEAL, NULL, 'new', '$(site_time)', $A348_ADMIN)" > /dev/null
A348_COUNTS=$(a348_counts)
expect "3.48: with both on – the nine tools are listed, the Pipeline item is named Prodej (Sales) while Quotes are on, Redirects are Adresy (Addresses)" \
  "$(a348_tools | tr ',' '\n' | grep -c .)|$(a348_page '' > /dev/null; grep -c 'module=pipeline"[^<]*<svg.*</svg>Prodej' "$WORK/response" | sed 's/^[1-9][0-9]*$/1/')|$(grep -c 'module=redirects"[^<]*<svg.*</svg>Adresy' "$WORK/response" | sed 's/^[1-9][0-9]*$/1/')|$(a348_has 'href="/admin.php?module=quotes"')" "9|1|1|0"
expect "3.48 A5: the Sales hub – Pipeline, Quotes, Internal prices as one row of tabs; every price screen highlights Internal prices; the set-up links on the right" \
  "$(a348_page 'module=pipeline')|$(a348_has 'nav class="zalozky zalozky-hub"')|$(a348_has 'data-pipeline-stages')|$(a348_page 'module=quotes&action=prices')|$(a348_has 'href="/admin.php?module=quotes&amp;action=prices" class="aktivni"')|$(a348_has 'data-quotes-tabs')|$(grep -c 'class="zalozky' "$WORK/response")" \
  "200|1|1|200|1|1|1"
expect "3.48 A5: Broken links opened from Site care show the Addresses tabs, Broken links current" \
  "$(a348_page 'module=broken_links')|$(a348_has 'href="/admin.php?module=broken_links" class="aktivni"')|$(a348_has 'href="/admin.php?module=redirects"')" "200|1|1"

# an editor with News only keeps Broken links as an item of its own
A348_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('news-a348', '$A348_HASH', 'Nina A348', 'news-a348@example.com', '', 0, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'news-a348'" > /dev/null
A348_NEWS=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'news-a348'")
A348_JAR="$WORK/jar-a348"; rm -f "$A348_JAR"; curl -s -c "$A348_JAR" -o "$WORK/response" "$B/admin.php"; curl -s -b "$A348_JAR" -c "$A348_JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=news-a348 --data-urlencode "password=$PASSWORD"
expect "3.48 A5: an editor with News only – Broken links is its own item, no Addresses, no Redirects" \
  "$(a348_page '' "$A348_JAR")|$(a348_has 'href="/admin.php?module=broken_links"')|$(a348_has 'href="/admin.php?module=redirects"')|$(grep -c '</svg>Adresy' "$WORK/response")" "200|1|0|0"

# the Pipeline off (Customers stay): the four deal tools leave tools/list, a call says why, the report has no pipeline block
features -pipeline
expect "3.48 A2: Pipeline off – the deal tools leave tools/list, the contact tools stay; a call of a hidden tool names the switched-off feature" \
  "$(a348_tools)|$(mcp_call "$A348_TOKEN" list_deals '{}' | grep -c 'switched off on this site')" "list_contacts,get_contact,save_contact,log_activity,merge_contacts|1"
expect "3.48 A2: Pipeline off – the monthly report of this month has no pipeline block (with it on, it has one: the deal is this month's)" \
  "$(a348_php 'echo json_encode(Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable("first day of this month 00:00"))["pipeline"] ?? null);')|$(features +pipeline; a348_php 'echo (Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable("first day of this month 00:00"))["pipeline"]["new"] ?? 0) > 0 ? "counted" : "none";'; features -pipeline)" \
  "null|counted"
expect "3.48 A2: Pipeline off – Quotes alone are their own item named Quotes; the Pipeline answers switched off" \
  "$(a348_page '' > /dev/null; grep -c 'module=quotes"[^<]*<svg.*</svg>Nabídky' "$WORK/response" | sed 's/^[1-9][0-9]*$/1/')|$(a348_page 'module=pipeline')|$(a348_has '„Obchody“ je vypnutá')" "1|403|1"
# a task about the deal: My work names it without a link to the switched-off Pipeline
sq "INSERT INTO ka_tasks (title, target, assignee_id, created_by, due_at, created_at, updated_at) VALUES ('Call back A348', 'deal:$A348_DEAL', $A348_ADMIN, $A348_ADMIN, '$(site_time '+1 day')', '$(site_time)', '$(site_time)')" > /dev/null
A348_TASK=$(sq "SELECT id FROM ka_tasks WHERE title = 'Call back A348'")
expect "3.48 A2: a task on a deal with the Pipeline off – My work names the deal with \"(section switched off)\" and links nowhere into the Pipeline" \
  "$(a348_page 'module=my_work')|$(sed -n "/id=\"task-$A348_TASK\"/,/<\/tr>/p" "$WORK/response" | grep -c '(sekce je vypnutá)' | sed 's/^[1-9][0-9]*$/1/')|$(sed -n "/id=\"task-$A348_TASK\"/,/<\/tr>/p" "$WORK/response" | grep -c 'module=pipeline')" "200|1|0"
# a leaver who owns the deal: with the Pipeline off a recipient can still be chosen (the deal moves with the rest)
sq "UPDATE ka_deals SET owner_id = $A348_NEWS WHERE id = $A348_DEAL" > /dev/null
expect "3.48 A2: a leaver who owns a deal, Pipeline off – the hand-over offers recipients" \
  "$(a348_page "module=users&action=handover&id=$A348_NEWS")|$(sed -n '/<select/,/<\/select>/p' "$WORK/response" | grep -c "<option value=\"$A348_ADMIN\"")" "200|1"

# Customers off too: no menu item, the switched-off page, no customer box – and the contacts are still made and erased
features --exact "$A348_EXT" -customers -pipeline +quotes
expect "3.48 A2: Customers off – no menu item, the switched-off page with the way to Features, every CRM tool gone from tools/list" \
  "$(a348_page '' > /dev/null; a348_has 'href="/admin.php?module=contacts"')|$(a348_page 'module=contacts')|$(a348_has '„Zákazníci“ je vypnutá')|$(a348_tools)" "0|403|1|"
# a new enquiry goes the way Front\Forms stores one – the row, then Contacts::later() and afterResponse() (the shared site's
# contact form changes over the suite, so the section does not depend on it)
sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, tema, email, data, stav) VALUES ('$(site_time)', 'Contact A348', 'stranka:1', '/kontakt', 'A question A348', 'erik-a348@example.com', '[]', 0)" > /dev/null
a348_php '$id = (int) $app->db()->value("SELECT MAX(idp) FROM {poptavky} WHERE email = ?", ["erik-a348@example.com"]); Kaleta\Core\Contacts::later("enquiry", $id); Kaleta\Core\Contacts::afterResponse($app);' > /dev/null
A348_ENQ=$(sq "SELECT idp FROM ka_poptavky WHERE email = 'erik-a348@example.com' ORDER BY idp DESC LIMIT 1")
expect "3.48 A2: Customers off – a new enquiry still links its contact (Contacts::link unchanged); the enquiry shows no customer box" \
  "$(sq "SELECT c.email_norm FROM ka_poptavky p JOIN ka_contacts c ON c.id = p.contact_id WHERE p.idp = ${A348_ENQ:-0}")|$(a348_page "module=enquiries&action=detail&id=${A348_ENQ:-0}")|$(a348_has 'data-contact-open')" \
  "erik-a348@example.com|200|0"
# Quotes on with Customers off: a quote from the enquiry takes the address, the price list opens
A348_CSRF=$(a348_page "module=enquiries&action=detail&id=${A348_ENQ:-0}" > /dev/null; csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=create" -d "_csrf=$A348_CSRF" -d "enquiry=${A348_ENQ:-0}"
expect "3.48 A2: Quotes with Customers off – a quote from an enquiry is made with the customer's e-mail; the internal prices open; nothing links to Customers" \
  "$(sq "SELECT customer_email FROM ka_quotes WHERE enquiry_id = ${A348_ENQ:-0}")|$(a348_page 'module=quotes&action=prices')|$(a348_page "module=quotes&action=edit&id=$(sq "SELECT id FROM ka_quotes WHERE enquiry_id = ${A348_ENQ:-0}")")|$(a348_has 'module=contacts')" \
  "erik-a348@example.com|200|200|0"
expect "3.48 A2: Customers off – the privacy record still describes the customer records it keeps; an erasure still reaches the contact" \
  "$(a348_php 'echo str_contains(json_encode(Kaleta\Core\Privacy::processingRecord($app), JSON_UNESCAPED_UNICODE), "Customer") || str_contains(json_encode(Kaleta\Core\Privacy::processingRecord($app), JSON_UNESCAPED_UNICODE), "Zákazn") ? "described" : "missing";' 2>&1 | tail -c 40)|$(a348_php 'echo count(Kaleta\Core\PersonalData::find($app->db(), "erik-a348@example.com", $app)["contacts"]);')|$(a348_php 'Kaleta\Core\PersonalData::erase($app, "erik-a348@example.com");'; sq "SELECT COUNT(*) FROM ka_contacts WHERE email_norm = 'erik-a348@example.com'")" \
  "described|1|0"

# Features: a profile is a preselection that names what it would switch off; the Pipeline keeps Customers on
features --exact "$A348_EXT"
sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, tema, email, data, stav) VALUES ('$(site_time)', 'Contact A348', 'stranka:1', '/kontakt', 'Kept A348', 'kept-a348@example.com', '[]', 0)" > /dev/null
expect "3.48 A3: ?profile=website on a site with enquiries – the notice names Formuláře a poptávky with its records, nothing is saved by the link" \
  "$(a348_page 'module=extensions&profile=website')|$(sed -n '/data-profile-notice/p' "$WORK/response" | grep -c 'Formuláře a poptávky (záznamů: [1-9]' | sed 's/^[1-9][0-9]*$/1/')|$(a348_has 'name="rozsireni\[\]" value="poptavky">')|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")" \
  "200|1|1|$A348_EXT"
expect "3.48 A3: the cards of the CRM say who sees them and lead to their screens; Customers says it is needed by the Pipeline" \
  "$(a348_page 'module=extensions')|$(a348_has 'class="rozsireni-vidi')|$(a348_has 'Potřebuje ji: Obchody')|$(a348_has 'module=pipeline&amp;action=stages')" "200|1|1|1"
A348_CSRF=$(csrf)
A348_POST=(); for e in $(printf %s "$A348_EXT" | tr ',' ' '); do [ "$e" = customers ] || A348_POST+=(-d "rozsireni[]=$e"); done
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=extensions&action=save" -d "_csrf=$A348_CSRF" -d tab=extensions "${A348_POST[@]}"
expect "3.48 A3: unticking Customers with the Pipeline on saves both on and says so" \
  "$(sq "SELECT FIND_IN_SET('customers', hodnota) > 0 AND FIND_IN_SET('pipeline', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'")|$(a348_page 'module=extensions' > /dev/null; a348_has '„Zákazníci“ zůstává zapnutá')" "1|1"
expect "3.48 A3: update_settings extensions returns the list closed under REQUIRES" \
  "$(mcp_call "$A348_TOKEN" update_settings "{\"settings\":{\"extensions\":[\"novinky\",\"poptavky\",\"pipeline\",\"claude\"]}}" | php -r 'echo implode(",", json_decode(json_decode(stream_get_contents(STDIN), true)["result"]["content"][0]["text"] ?? "{}", true)["saved"]["extensions"] ?? []);')" "novinky,poptavky,customers,pipeline,claude"
features --exact "$A348_EXT"
expect "3.48 A3: switched off and on again – the contacts and deals counted before are all there" "$(a348_counts)" "$A348_COUNTS"

features --exact "$A348_EXT"
sq "DELETE FROM ka_poptavky WHERE email = 'kept-a348@example.com'; DELETE FROM ka_contacts WHERE email_norm = 'kept-a348@example.com'; DELETE FROM ka_tasks WHERE id = ${A348_TASK:-0}; DELETE FROM ka_quotes WHERE enquiry_id = ${A348_ENQ:-0}; DELETE FROM ka_deals WHERE id = ${A348_DEAL:-0}; DELETE FROM ka_contacts WHERE id = ${A348_CONTACT:-0};
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = ${A348_NEWS:-0}; DELETE FROM ka_uzivatele WHERE idu = ${A348_NEWS:-0}; DELETE FROM ka_api_tokeny WHERE nazev = 'a348 full'" > /dev/null
rm -f "$A348_JAR" "$WORK"/web/storage/cache/stranky/*.html
