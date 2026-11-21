echo "== 3.49 C: choosing by search – the search endpoints (read-only, no-store, gated), values outside the caps, the special options, New customer…"
c449_get() { curl -s -b "${2:-$JAR}" -c "${2:-$JAR}" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
c449_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
c449_json() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo '"$1"';' -- "$WORK/response"; } # c449_json '<a PHP expression over $d>' – the section's own expressions
c449_login() { # c449_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
C449_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +quotes
C449_NOW=$(site_time)

# the records: a company, a customer of it, an anonymised one, 300 newer customers and one old customer outside the first 300
sq "INSERT INTO ka_companies (name, domain, created_at, updated_at) VALUES ('Firma C449 Zubr', 'zubr-c449.example', '$C449_NOW', '$C449_NOW')" > /dev/null
C449_COMPANY=$(sq "SELECT id FROM ka_companies WHERE name = 'Firma C449 Zubr'")
sq "INSERT INTO ka_contacts (email, email_norm, name, company_id, source, created_at, updated_at, last_activity_at) VALUES ('zora-c449@example.com', 'zora-c449@example.com', 'Zora C449', $C449_COMPANY, 'manual', '$C449_NOW', '$C449_NOW', '$C449_NOW');
  INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at, anonymised_at) VALUES ('', NULL, 'Zora C449 Anonym', 'manual', '$C449_NOW', '$C449_NOW', '$C449_NOW', '$C449_NOW');
  INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('old-c449@example.com', 'old-c449@example.com', 'Olaf C449', 'manual', '2001-01-01 10:00:00', '2001-01-01 10:00:00', '2001-01-01 10:00:00')" > /dev/null
C449_ZORA=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'zora-c449@example.com'"); C449_OLD=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'old-c449@example.com'")
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES $(seq 1 300 | sed "s/.*/('bulk&-c449@example.com', 'bulk&-c449@example.com', 'Bulk C449 &', 'manual', '$C449_NOW', '$C449_NOW', '$C449_NOW')/" | paste -sd, -)" > /dev/null

# the endpoints: rows with an id, Cache-Control no-store, nothing under two characters, never an anonymised customer
expect "3.49 C: the customer search (pick=1) answers 200 with id, name and note, no-store; under two characters no rows; an anonymised customer never appears" \
  "$(c449_get 'module=contacts&action=search_json&q=Zora+C449&pick=1')|$(c449_json 'implode(",", array_map(fn ($r) => $r["id"] . ":" . $r["name"] . ":" . $r["note"], $d["contacts"]))')|$(curl -s -b "$JAR" -D - -o /dev/null "$B/admin.php?module=contacts&action=search_json&q=Zora&pick=1" | tr -d '\r' | grep -i '^cache-control:' | cut -d' ' -f2-)|$(c449_get 'module=contacts&action=search_json&q=Z&pick=1' > /dev/null; c449_json 'count($d["contacts"])')" \
  "200|$C449_ZORA:Zora C449:Firma C449 Zubr · zora-c449@example.com|no-store, private|0"
expect "3.49 C: pick=1 gives at most 8 customers, the palette 6" \
  "$(c449_get 'module=contacts&action=search_json&q=Bulk+C449&pick=1' > /dev/null; c449_json 'count($d["contacts"])')|$(c449_get 'module=contacts&action=search_json&q=Bulk+C449' > /dev/null; c449_json 'count($d["contacts"])')" "8|6"
expect "3.49 C: the company search finds a company by its name and by its domain" \
  "$(c449_get 'module=contacts&action=company_search_json&q=Zubr')|$(c449_json 'implode(",", array_map(fn ($r) => $r["id"] . ":" . $r["name"] . ":" . $r["note"], $d["companies"]))')|$(c449_get 'module=contacts&action=company_search_json&q=zubr-c449.example' > /dev/null; c449_json 'count($d["companies"])')" \
  "200|$C449_COMPANY:Firma C449 Zubr:zubr-c449.example|1"

# deals: an open one and a won one with the same word – open first; the won one belongs to the old customer
sq "INSERT INTO ka_deals (title, contact_id, stage, closed_at, created_at, updated_at) VALUES ('Kanoe C449 won', $C449_OLD, 'won', '$C449_NOW', '$C449_NOW', '$C449_NOW');
  INSERT INTO ka_deals (title, contact_id, stage, created_at, updated_at) VALUES ('Kanoe C449 open', $C449_ZORA, 'new', '$C449_NOW', '$C449_NOW')" > /dev/null
C449_WON=$(sq "SELECT id FROM ka_deals WHERE title = 'Kanoe C449 won'")
expect "3.49 C: the deal search (pick=1) gives the open deal first, with ids" \
  "$(c449_get 'module=pipeline&action=search_json&q=Kanoe+C449&pick=1')|$(c449_json 'implode(",", array_column($d["deals"], "name"))')|$(c449_json 'implode(",", array_map(fn ($r) => (int) ($r["id"] > 0), $d["deals"]))')" \
  "200|Kanoe C449 open,Kanoe C449 won|1,1"

# products: the first product of the default price list is found by a word of its name, with its internal price as the note
C449_PRODUCT=$( (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $l = Kaleta\Core\Prices::defaultList($app->db(), $app->settings()); $r = Kaleta\Core\Prices::grid($app->db(), $l["id"], [], 1, 1)["rows"][0] ?? null; echo $l["id"], "|", $r === null ? "" : explode(" ", (string) $r["name"])[0];') )
C449_LIST=${C449_PRODUCT%%|*}; C449_WORD=${C449_PRODUCT#*|}
expect "3.49 C: the product search answers rows {value, name, note} of the price list, only inside Quotes" \
  "$(c449_get "module=quotes&action=product_search_json&list=$C449_LIST&q=$(php -r 'echo rawurlencode($argv[1]);' -- "$C449_WORD")")|$(c449_json '$d["products"] !== [] && array_filter($d["products"], fn ($r) => array_keys($r) !== ["value", "name", "note"] || $r["value"] === "") === [] ? "rows" : "none"')" \
  "200|$([ -n "$C449_WORD" ] && echo rows || echo none)"
expect "3.49 C: signed out, the product search answers no JSON and no price (the sign-in page)" \
  "$(curl -s -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=quotes&action=product_search_json&list=$C449_LIST&q=$(php -r 'echo rawurlencode($argv[1]);' -- "$C449_WORD")")|$(c449_has '"products"')|$(c449_has 'name="password"')" "200|0|1"

# internal files: ready files of the folders the person sees – never a draft, never a file of a folder for a role they lack
C449_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('C449 board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_internal_folders (name, audience, roles, created_at, updated_at) VALUES ('C449 open', 'all', '', '$C449_NOW', '$C449_NOW'), ('C449 board', 'roles', '$C449_ROLE', '$C449_NOW', '$C449_NOW')" > /dev/null
C449_OPEN=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'C449 open'"); C449_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'C449 board'")
sq "INSERT INTO ka_internal_files (folder_id, name, status, extension, size, created_at, updated_at) VALUES ($C449_OPEN, 'Cenik-c449-ready.pdf', 'ready', 'pdf', 2048, '$C449_NOW', '$C449_NOW'),
  ($C449_OPEN, 'Cenik-c449-draft.md', 'draft', 'md', 10, '$C449_NOW', '$C449_NOW'), ($C449_BOARD, 'Cenik-c449-board.pdf', 'ready', 'pdf', 10, '$C449_NOW', '$C449_NOW')" > /dev/null
C449_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('pick-c449', '$C449_HASH', 'Pia C449', 'pick-c449@example.com', 'en', 0, '$C449_NOW', '$C449_NOW', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m FROM ka_uzivatele, (SELECT 'pipeline' AS m UNION SELECT 'internal_files') x WHERE user = 'pick-c449'" > /dev/null
C449_JAR="$WORK/jar-c449"; c449_login "$C449_JAR" pick-c449
expect "3.49 C: the files search gives a ready file of a folder the person sees – no draft, nothing of a folder for another role; the note names the folder" \
  "$(c449_get 'module=internal_files&action=search_json&q=Cenik-c449' "$C449_JAR")|$(c449_json 'implode(",", array_column($d["files"], "name"))')|$(c449_json 'str_starts_with($d["files"][0]["note"] ?? "", "C449 open · PDF") ? "named" : "?"')|$(c449_get 'module=internal_files&action=search_json&q=Cenik-c449' > /dev/null; c449_json 'count($d["files"])')" \
  "200|Cenik-c449-ready.pdf|named|2"
expect "3.49 C: a person without Customers gets 403 from the customer and company searches; without Quotes from the product search" \
  "$(c449_get 'module=contacts&action=search_json&q=Zora&pick=1' "$C449_JAR")|$(c449_get 'module=contacts&action=company_search_json&q=Zubr' "$C449_JAR")|$(c449_get "module=quotes&action=product_search_json&list=$C449_LIST&q=ab" "$C449_JAR")" "403|403|403"

# a deal form without Customers: no picker, and "New customer…" fields are ignored – no contact is made
c449_get 'module=pipeline&action=new' "$C449_JAR" > /dev/null; C449_PCSRF=$(csrf)
expect "3.49 C: without Customers the deal form has no customer picker and no New customer…" "$(c449_has 'data-pick=')|$(c449_has 'data-pick-new')" "0|0"
curl -s -b "$C449_JAR" -c "$C449_JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_PCSRF" -d id=0 -d "title=Bez zakaznika C449" -d stage=new -d contact_new_name=Nikdo -d contact_new_email=nikdo-c449@example.com
expect "3.49 C: … and a posted New customer is refused without Customers (the deal is made, no contact)" \
  "$(sq "SELECT COUNT(*) FROM ka_deals WHERE title = 'Bez zakaznika C449' AND contact_id IS NULL")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE email_norm = 'nikdo-c449@example.com'")" "1|0"

# the deal form of an administrator: the pickers over the selects; the old customer is outside the first 300 options and can still be saved by its id
c449_get 'module=pipeline&action=new' > /dev/null; C449_CSRF=$(csrf)
expect "3.49 C: the deal form – customer and company pickers, New customer…, the old customer not among the options" \
  "$(c449_has 'name="contact_id" data-pick="contacts"')|$(c449_has 'name="company_id" data-pick="companies"')|$(c449_has '<details class="vyber-novy" data-pick-new>')|$(c449_has "<option value=\"$C449_OLD\"")" "1|1|1|0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d id=0 -d "title=Stary zakaznik C449" -d stage=new -d "contact_id=$C449_OLD" -d company_id=0
C449_DEAL=$(sq "SELECT id FROM ka_deals WHERE title = 'Stary zakaznik C449'")
c449_get "module=pipeline&action=detail&id=$C449_DEAL" > /dev/null
C449_SHOWN=$(sed -n '/name="contact_id"/,/<\/select>/p' "$WORK/response" | grep -o '<option value="[0-9a-z]*"[^>]* selected' | grep -o 'value="[0-9a-z]*"' | cut -d'"' -f2)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d "id=$C449_DEAL" -d "title=Stary zakaznik C449" -d "contact_id=$C449_SHOWN" -d company_id=0
expect "3.49 C: a customer outside the cap is saved by its id; the deal opened and saved untouched keeps it" \
  "$(sq "SELECT contact_id FROM ka_deals WHERE id = ${C449_DEAL:-0}")|$C449_SHOWN|$(sq "SELECT contact_id FROM ka_deals WHERE id = ${C449_DEAL:-0}")" "$C449_OLD|$C449_OLD|$C449_OLD"

# a quote of the won deal: the deal (not among the open ones offered) and its customer (outside the cap) stay chosen
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=create" -d "_csrf=$C449_CSRF" -d "deal=$C449_WON"
C449_QUOTE=$(sq "SELECT id FROM ka_quotes WHERE deal_id = ${C449_WON:-0} ORDER BY id DESC LIMIT 1")
expect "3.49 C: the quote editor – the deal and customer pickers keep a closed deal and a customer outside the caps chosen; the product field gets the search of its price list" \
  "$(c449_get "module=quotes&action=edit&id=${C449_QUOTE:-0}")|$(sed -n '/name="deal_id"/,/<\/select>/p' "$WORK/response" | grep -c "<option value=\"$C449_WON\" selected>")|$(sed -n '/name="contact_id"/,/<\/select>/p' "$WORK/response" | grep -c "<option value=\"$C449_OLD\" selected>")|$(c449_has 'data-pick="products"')|$(c449_has 'action=product_search_json&amp;list=')" \
  "200|1|1|1|1"

# from an enquiry: 0 = the enquiry's customer, "none" = nobody
sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav, contact_id) VALUES ('$C449_NOW', 'Kontakt C449', '/kontakt', 'zora-c449@example.com', '[]', 0, $C449_ZORA), ('$C449_NOW', 'Kontakt C449 b', '/kontakt', 'zora-c449@example.com', '[]', 0, $C449_ZORA)" > /dev/null
C449_ENQ1=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'Kontakt C449'"); C449_ENQ2=$(sq "SELECT idp FROM ka_poptavky WHERE formular = 'Kontakt C449 b'")
c449_get "module=pipeline&action=new&enquiry=$C449_ENQ1" > /dev/null
expect "3.49 C: the deal form from an enquiry with a linked customer has it chosen and — (none) at the top of the picker" \
  "$(sed -n '/name="contact_id"/,/<\/select>/p' "$WORK/response" | grep -c "<option value=\"$C449_ZORA\" selected>")|$(c449_has '<option value="none" data-pick-special>—</option>')" "1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d id=0 -d "enquiry=$C449_ENQ1" -d "title=Z poptavky C449 a" -d stage=new -d contact_id=0 -d company_id=0
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d id=0 -d "enquiry=$C449_ENQ2" -d "title=Z poptavky C449 b" -d stage=new -d contact_id=none -d company_id=none
expect "3.49 C: from an enquiry 0 keeps the enquiry's customer, none is nobody" \
  "$(sq "SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE source_enquiry_id = $C449_ENQ1")|$(sq "SELECT IFNULL(contact_id, 'null') FROM ka_deals WHERE source_enquiry_id = $C449_ENQ2")" "$C449_ZORA|null"

# New customer…: one contact per e-mail address – the second post reuses it; a bad address is refused with the form kept
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d id=0 -d "title=Novy zakaznik C449 a" -d stage=new -d contact_id=0 -d company_id=0 -d "contact_new_name=Nela C449" -d contact_new_email=nela-c449@example.com
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d id=0 -d "title=Novy zakaznik C449 b" -d stage=new -d contact_id=0 -d company_id=0 -d "contact_new_name=Nela" -d contact_new_email=NELA-c449@example.com
C449_NELA=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'nela-c449@example.com'")
expect "3.49 C (U32): New customer… makes one contact (with its change-log line) and the second deal with the same address reuses it" \
  "$(sq "SELECT COUNT(*) FROM ka_contacts WHERE email_norm = 'nela-c449@example.com'")|$(sq "SELECT name FROM ka_contacts WHERE id = ${C449_NELA:-0}")|$(sq "SELECT GROUP_CONCAT(contact_id ORDER BY id) FROM ka_deals WHERE title LIKE 'Novy zakaznik C449 %'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND akce = 'contact created' AND popis = '#${C449_NELA:-0}'")" \
  "1|Nela C449|$C449_NELA,$C449_NELA|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$C449_CSRF" -d id=0 -d "title=Spatna adresa C449" -d stage=new -d contact_id=0 -d company_id=0 -d "contact_new_name=Vera C449" -d contact_new_email=not-an-address
c449_get 'module=pipeline&action=new' > /dev/null
expect "3.49 C: a refused New customer… makes no deal and no contact; the form comes back open with what was typed" \
  "$(sq "SELECT COUNT(*) FROM ka_deals WHERE title = 'Spatna adresa C449'")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE name = 'Vera C449'")|$(c449_has 'data-pick-new open>')|$(c449_has 'value="not-an-address"')" "0|0|1|1"

# the Pipeline off: its search answers 403 (the feature's page); Customers off too: the customer and company searches as well
features -pipeline
expect "3.49 C: Pipeline off – the deal search answers 403; the customer search still answers" \
  "$(c449_get 'module=pipeline&action=search_json&q=Kanoe&pick=1')|$(c449_get 'module=contacts&action=search_json&q=Zora&pick=1')" "403|200"
features -customers -pipeline
expect "3.49 C: Customers off – the customer and company searches answer 403" \
  "$(c449_get 'module=contacts&action=search_json&q=Zora&pick=1')|$(c449_get 'module=contacts&action=company_search_json&q=Zubr')" "403|403"
features --exact "$C449_EXT"
expect "3.49 C: Quotes off – the product search answers 403" "$(features -quotes; c449_get "module=quotes&action=product_search_json&list=$C449_LIST&q=ab")" "403"
features --exact "$C449_EXT"

# tidy up what would weigh on later sections: the bulk customers and the test user
sq "DELETE FROM ka_contacts WHERE email_norm LIKE 'bulk%-c449@example.com'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = (SELECT idu FROM ka_uzivatele WHERE user = 'pick-c449'); DELETE FROM ka_uzivatele WHERE user = 'pick-c449'" > /dev/null
