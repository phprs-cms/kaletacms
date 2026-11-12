echo "== 3.28 (B): Customers – the list and its filters, a contact and a company, the log, comments and tasks, merge, the box on an enquiry and a booking, CSV export and import, permissions"
B328_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "UPDATE ka_nastaveni SET hodnota = CONCAT(hodnota, ',bookings') WHERE promenna = 'extensions' AND NOT FIND_IN_SET('bookings', hodnota);
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES
  ('b328-jana', '$B328_HASH', 'Jana B328', 'jana-b328@example.com', '', 1, '$(site_time)', '$(site_time)', 0),
  ('b328-petr', '$B328_HASH', 'Petr B328', 'petr-b328@example.com', '', 1, '$(site_time)', '$(site_time)', 0);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'contacts' UNION SELECT 'bookings') m WHERE user = 'b328-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'enquiries' AS ident UNION SELECT 'news') m WHERE user = 'b328-petr'" > /dev/null
B328_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b328-jana'"); B328_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b328-petr'"); B328_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
b328_login() { local jar="$WORK/jar-$1"; rm -f "$jar"; curl -s -c "$jar" -o "$WORK/response" "$B/admin.php"; curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d "user=$1" --data-urlencode "password=$PASSWORD"; echo "$jar"; }
b328_post() { # b328_post <jar> <screen query> <module&action query> <fields…>: opens the screen (for the form token), posts, prints the code and the redirect
  local jar="$1" screen="$2" target="$3"; shift 3
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?$screen"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?$target" -d "_csrf=$(csrf)" "$@"; }
b328_contact() { sq "SELECT CONCAT_WS('/', IFNULL(NULLIF(name, ''), '-'), IFNULL(NULLIF(email, ''), '-'), IFNULL(NULLIF(phone, ''), '-'), IFNULL(company_id, 'null'), IFNULL(owner_id, 'null'), source) FROM ka_contacts WHERE id = ${1:-0}"; }
B328_CACHE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'page_cache'"); setting page_cache 1
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o /dev/null "$B/"
# the menu, the list and a new contact by hand
check "3.28: Customers is in the menu after Enquiries, with the tabs and New contact" 200 "/admin.php?module=contacts" 'data-new-contact'
b328_has() { grep -q -- "$1" "$WORK/response" && echo 1 || echo 0; }
expect "3.28: … the menu item, the Companies tab, the export and import for the administrator; the palette searches customers" \
  "$(b328_has 'href="/admin.php?module=contacts"')|$(b328_has 'module=contacts&amp;action=companies')|$(b328_has 'data-contacts-export')|$(b328_has 'module=contacts&amp;action=import')|$(b328_has 'data-contacts="/admin.php?module=contacts&amp;action=search_json"')" "1|1|1|1|1"
REDIRECT=$(b328_post "$JAR" "module=contacts&action=new" "module=contacts&action=save" -d id=0 --data-urlencode "name=Eva B328" -d "email=Eva-B328@b328firma.cz" --data-urlencode "phone=+420 600 328 001" -d company_id=0 -d owner_id=0 -d language= -d summary=)
B328_EVA=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_contacts WHERE email_norm = 'eva-b328@b328firma.cz'")
expect "3.28: New contact – saved by hand (source manual), back to its detail; the change log names it by number only; the cached page stays" \
  "$(b328_contact "$B328_EVA")|$(echo "$REDIRECT" | grep -c "action=detail&id=$B328_EVA")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND akce = 'contact created' AND popis = '#$B328_EVA'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND (popis LIKE '%Eva%' OR popis LIKE '%b328firma%')")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "Eva B328/Eva-B328@b328firma.cz/+420 600 328 001/null/null/manual|1|1|0|1"
B328_N=$(sq "SELECT COUNT(*) FROM ka_contacts")
b328_post "$JAR" "module=contacts&action=new" "module=contacts&action=save" -d id=0 -d "email=eva-b328@B328FIRMA.cz" -d "name=Eva again" > /dev/null
check "3.28: … the same address again is refused (one contact per address), the form keeps what was written" 200 "/admin.php?module=contacts&action=new" 'class="hlaska hlaska-chyba"'
expect "3.28: … nothing saved, the name offered again; a bad phone is refused too" \
  "$(sq "SELECT COUNT(*) FROM ka_contacts")|$(grep -c 'value="Eva again"' "$WORK/response")|$(b328_post "$JAR" "module=contacts&action=new" "module=contacts&action=save" -d id=0 -d "name=Bad phone B328" -d "phone=call me" > /dev/null; sq "SELECT COUNT(*) FROM ka_contacts")" \
  "$B328_N|1|$B328_N"
# a company, the suggestion from the e-mail domain, the responsible person
check "3.28: the contact's detail suggests the company of its e-mail domain" 200 "/admin.php?module=contacts&action=detail&id=$B328_EVA" 'data-company-suggestion'
expect "3.28: … the comments and tasks of the contact, the log form, Merge with… and Delete for the administrator" \
  "$(grep -c "data-comments=\"contact:$B328_EVA\"" "$WORK/response")|$(grep -c "data-tasks=\"contact:$B328_EVA\"" "$WORK/response")|$(grep -c 'data-activity-form' "$WORK/response")|$(grep -c 'data-contact-merge' "$WORK/response")|$(grep -c 'data-contact-delete' "$WORK/response")|$(grep -c "href=\"mailto:Eva-B328@b328firma.cz\"" "$WORK/response")|$(grep -c 'href="tel:+420600328001"' "$WORK/response")" "1|1|1|1|1|2|2" # 3.37: E-mail and Call in the bar too
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=save" -d "id=$B328_EVA" -d "company_new=B328firma" -d "company_domain=b328firma.cz" > /dev/null
B328_CO=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_companies WHERE domain = 'b328firma.cz'")
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=save" -d "id=$B328_EVA" -d "owner_id=$B328_JANA" > /dev/null
expect "3.28: the suggested company is created and the contact joins it; the header's responsible person changes only that; Jana is notified" \
  "$(b328_contact "$B328_EVA")|$(sq "SELECT name FROM ka_companies WHERE id = $B328_CO")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $B328_JANA AND target = 'contact:$B328_EVA'")" \
  "Eva B328/Eva-B328@b328firma.cz/+420 600 328 001/$B328_CO/$B328_JANA/manual|B328firma|1"
b328_post "$JAR" "module=contacts&action=company&id=$B328_CO" "module=contacts&action=company_save" -d "company=$B328_CO" --data-urlencode "name=B328 Firma s.r.o." -d "domain=https://www.B328firma.cz/kontakt" -d registration_no=12345678 -d vat_id=CZ12345678 -d owner_id=0 --data-urlencode "note=Velký klient" > /dev/null
check "3.28: the company's detail – its contact, the merged timeline, comments and tasks of the company" 200 "/admin.php?module=contacts&action=company&id=$B328_CO" "data-contact=\"$B328_EVA\""
expect "3.28: … the domain cleaned, the fields saved; its comments and tasks target company:N" \
  "$(sq "SELECT CONCAT_WS('/', name, domain, registration_no, vat_id, note) FROM ka_companies WHERE id = $B328_CO")|$(grep -c "data-comments=\"company:$B328_CO\"" "$WORK/response")|$(grep -c "data-tasks=\"company:$B328_CO\"" "$WORK/response")" \
  "B328 Firma s.r.o./b328firma.cz/12345678/CZ12345678/Velký klient|1|1"
check "3.28: the Companies tab finds it by its registration number" 200 "/admin.php?module=contacts&action=companies&search=12345678" "data-company=\"$B328_CO\""
# the list filters: search, the responsible person, the company, the last activity
b328_ids() { curl -s -b "$JAR" "$B/admin.php?module=contacts&$1" | grep -o 'data-contact="[0-9]*"' | grep -c "data-contact=\"$B328_EVA\""; }
expect "3.28: the list filters – search by name, e-mail and company; Jana's (not mine, not nobody's); the company; no activity yet = quiet, not active" \
  "$(b328_ids 'search=Eva+B328')$(b328_ids 'search=eva-b328%40')$(b328_ids 'search=B328+Firma')$(b328_ids "owner=$B328_JANA")$(b328_ids 'owner=mine')$(b328_ids 'owner=nobody')$(b328_ids "company=$B328_CO")$(b328_ids 'activity=active30')$(b328_ids 'activity=quiet365&search=B328')" "111100101"
# the log: a call at a given time; a bad kind and a bad time are refused; the last activity moves
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=activity" -d "id=$B328_EVA" -d op=add -d kind=call --data-urlencode "text=Volala kvůli nabídce B328" -d "date=$(site_date '-1 day')" -d time=10:30 > /dev/null
B328_ACT=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_contact_activities WHERE contact_id = $B328_EVA")
B328_ACTS=$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE contact_id = $B328_EVA")
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=activity" -d "id=$B328_EVA" -d op=add -d kind=fax -d "text=X" > /dev/null
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=activity" -d "id=$B328_EVA" -d op=add -d kind=note -d "text=Y" -d time=25:99 > /dev/null
expect "3.28: Log a call – the kind, text, author and the time given; a bad kind or time saves nothing; the last activity follows; the change log has no text" \
  "$(sq "SELECT CONCAT_WS('/', kind, text, author_id, happened_at) FROM ka_contact_activities WHERE id = $B328_ACT")|$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE contact_id = $B328_EVA")|$(sq "SELECT last_activity_at FROM ka_contacts WHERE id = $B328_EVA")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND akce = 'activity logged' AND popis = '#$B328_EVA (call #$B328_ACT)'")" \
  "call/Volala kvůli nabídce B328/$B328_ADMIN/$(site_date '-1 day') 10:30:00|$B328_ACTS|$(site_date '-1 day') 10:30:00|1"
expect "3.28: … now active in the last 30 days" "$(b328_ids 'activity=active30')$(b328_ids 'activity=quiet90&search=B328')" "10"
check "3.28: the timeline shows the call with its delete button" 200 "/admin.php?module=contacts&action=detail&id=$B328_EVA" 'data-timeline-entry="call"'
expect "3.28: … the administrator may delete it" "$(grep -c 'data-activity-delete' "$WORK/response")" "1"
# comments and tasks of a contact (Admin\CommentActions, Admin\TaskActions): their change-log rows never carry the name
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=comment" -d "id=$B328_EVA" -d op=add --data-urlencode "text=VIP klientka B328" > /dev/null
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=task" -d "id=$B328_EVA" -d op=add --data-urlencode "title=Poslat nabídku B328" -d due_quick=tomorrow > /dev/null
b328_post "$JAR" "module=contacts&action=company&id=$B328_CO" "module=contacts&action=comment" -d "company=$B328_CO" -d op=add --data-urlencode "text=Firma B328 platí včas" > /dev/null
expect "3.28: a comment and a task on the contact, a comment on the company; the change log says \"contact\", never the name; the cached page stays" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'contact:$B328_EVA' AND text = 'VIP klientka B328'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'contact:$B328_EVA' AND title = 'Poslat nabídku B328' AND DATE(due_at) = '$(site_date '+1 day')'")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'company:$B328_CO'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND popis LIKE '#$B328_EVA contact (%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND popis LIKE '%Eva%'")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "1|1|1|2|0|1"
check "3.28: … all on the timeline" 200 "/admin.php?module=contacts&action=detail&id=$B328_EVA" 'data-timeline-entry="comment"'
expect "3.28: … the task too" "$(grep -c 'data-timeline-entry="task"' "$WORK/response")" "1"
# the box on an enquiry and a booking: an old row gets "Link to a contact"; the free-mail one never gets a company
B328_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Poptávka B328', 'novy-b328@b328firma.cz', '[[\"Zpráva\",\"Tajná zpráva B328\"]]', 1); SELECT LAST_INSERT_ID();")
B328_FREE=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Poptávka B328', 'b328.free@gmail.com', '[]', 1); SELECT LAST_INSERT_ID();")
B328_NOMAIL=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Poptávka B328', '', '[]', 1); SELECT LAST_INSERT_ID();")
check "3.28: an enquiry from before 3.28 offers Link to a contact" 200 "/admin.php?module=enquiries&action=detail&id=$B328_ENQ" 'data-contact-link'
REDIRECT=$(b328_post "$JAR" "module=enquiries&action=detail&id=$B328_ENQ" "module=contacts&action=link" -d kind=enquiry -d "id=$B328_ENQ")
b328_post "$JAR" "module=enquiries&action=detail&id=$B328_FREE" "module=contacts&action=link" -d kind=enquiry -d "id=$B328_FREE" > /dev/null
B328_NEW=$(sq "SELECT IFNULL(contact_id, 0) FROM ka_poptavky WHERE idp = $B328_ENQ"); B328_FREEC=$(sq "SELECT IFNULL(contact_id, 0) FROM ka_poptavky WHERE idp = $B328_FREE")
expect "3.28: Link to a contact – a contact of the address (source enquiry) in the company of its domain; the free-mail one without a company; back to the enquiry" \
  "$(b328_contact "$B328_NEW")|$(b328_contact "$B328_FREEC")|$(echo "$REDIRECT" | grep -c "module=enquiries&action=detail&id=$B328_ENQ#contact")" \
  "-/novy-b328@b328firma.cz/-/$B328_CO/null/enquiry|-/b328.free@gmail.com/-/null/null/enquiry|1"
check "3.28: … the enquiry's box shows the customer with Open customer" 200 "/admin.php?module=enquiries&action=detail&id=$B328_ENQ" "data-contact-box=\"$B328_NEW\""
expect "3.28: … its company, the counts and the link to the customer; an enquiry without an address has nothing to link" \
  "$(grep -c 'B328 Firma s.r.o.' "$WORK/response")|$(grep -c "data-contact-open" "$WORK/response")|$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&action=detail&id=$B328_NOMAIL" | grep -c 'data-contact-none')" "1|1|1"
B328_BOOK=$(sq "INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, name, email, phone, token_hash, created_at) VALUES (0, 0, '$(site_time '+5 days')', '$(site_time '+5 days')', 'Eva B328', 'EVA-B328@b328firma.cz', '', SHA2('b328-book', 256), '$(site_time)'); SELECT LAST_INSERT_ID();")
check "3.28: a booking from before 3.28 offers Link to a contact too" 200 "/admin.php?module=bookings&action=detail&id=$B328_BOOK" 'data-contact-link'
b328_post "$JAR" "module=bookings&action=detail&id=$B328_BOOK" "module=contacts&action=link" -d kind=booking -d "id=$B328_BOOK" > /dev/null
expect "3.28: … linked to Eva by the normalised address (the case of the address does not matter); its box shows her" \
  "$(sq "SELECT IFNULL(contact_id, 0) FROM ka_bookings WHERE id = $B328_BOOK")|$(curl -s -b "$JAR" "$B/admin.php?module=bookings&action=detail&id=$B328_BOOK" | grep -c "data-contact-box=\"$B328_EVA\"")" "$B328_EVA|1"
# merge: the other contact found, both side by side, a confirmation; never without it
check "3.28: Merge with… finds the other contact" 200 "/admin.php?module=contacts&action=merge&id=$B328_EVA&search=novy-b328" "action=merge&amp;id=$B328_EVA&amp;with=$B328_NEW"
check "3.28: … both side by side with what moves and the swap" 200 "/admin.php?module=contacts&action=merge&id=$B328_EVA&with=$B328_NEW" 'data-merge-compare'
expect "3.28: … the kept and the merged one, the confirmation" "$(grep -c 'data-merge-side="keep"' "$WORK/response")|$(grep -c 'data-merge-side="other"' "$WORK/response")|$(grep -c 'data-merge-swap' "$WORK/response")|$(grep -c 'name="confirmed" value="1" required' "$WORK/response")" "1|1|1|1"
b328_post "$JAR" "module=contacts&action=merge&id=$B328_EVA&with=$B328_NEW" "module=contacts&action=merge" -d "id=$B328_EVA" -d "with=$B328_NEW" > /dev/null
expect "3.28: … without the tick nothing is merged" "$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id IN ($B328_EVA, $B328_NEW)")" "2"
REDIRECT=$(b328_post "$JAR" "module=contacts&action=merge&id=$B328_EVA&with=$B328_NEW" "module=contacts&action=merge" -d "id=$B328_EVA" -d "with=$B328_NEW" -d confirmed=1)
expect "3.28: Merge – the other one is gone, its enquiry moved, Eva keeps her address, a note keeps the history; logged; back to Eva" \
  "$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = $B328_NEW")|$(sq "SELECT contact_id FROM ka_poptavky WHERE idp = $B328_ENQ")|$(sq "SELECT email FROM ka_contacts WHERE id = $B328_EVA")|$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE contact_id = $B328_EVA AND kind = 'note' AND text LIKE '%#$B328_NEW%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND akce = 'merge' AND popis = '#$B328_NEW → #$B328_EVA'")|$(echo "$REDIRECT" | grep -c "action=detail&id=$B328_EVA")" \
  "0|$B328_EVA|Eva-B328@b328firma.cz|1|1|1"
# CSV export (administrators): UTF-8 with a BOM, the company and the responsible person; logged without data
curl -s -b "$JAR" -D "$WORK/headers" -o "$WORK/b328.csv" "$B/admin.php?module=contacts&action=export"
expect "3.28: CSV export – text/csv, a BOM, semicolons, Eva with her company and Jana; a cell starting with + is never a formula; logged without data" \
  "$(grep -ci 'content-type: text/csv; charset=utf-8' "$WORK/headers")|$(head -c 3 "$WORK/b328.csv" | od -An -tx1 | tr -d ' ')|$(grep -c "^$B328_EVA;\"Eva B328\";Eva-B328@b328firma.cz;\"'+420 600 328 001\";\"B328 Firma s.r.o.\";\"Jana B328\";" "$WORK/b328.csv")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'contacts' AND akce = 'export CSV' AND popis = ''")" \
  "1|efbbbf|1|1"
# CSV import (administrators): the preview counts new / update / skipped – a duplicate address, a row without one; a free-mail
# row never gets a company; matched by the address only; the change log keeps the counts
printf 'E-mail;Jméno;Telefon;Firma\neva-b328@b328firma.cz;;+420 600 328 999;\nimport-b328@gmail.com;Ivan Import;;\nimport-b328@gmail.com;Ivan Twice;;\nnení adresa;Nobody;;\nhana-b328@stavby-b328.cz;Hana B328;;Stavby B328 s.r.o.\nb328.free@gmail.com;;;\n' > "$WORK/b328-import.csv"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=contacts&action=import"
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=contacts&action=import_upload" -F "_csrf=$(csrf)" -F "file=@$WORK/b328-import.csv;type=text/csv")
B328_FILE=$(echo "$REDIRECT" | sed -n 's/.*file=\([a-f0-9]*\).*/\1/p')
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$REDIRECT"
expect "3.28: CSV import preview – 2 new, 1 update (Eva's phone), 3 skipped (the same address twice, no address, nothing new); nothing saved yet" \
  "$(grep -o 'data-import-count="[a-z]*"><strong>[0-9]*' "$WORK/response" | sed 's/.*>//' | tr '\n' '/')|$(grep -o 'data-import-row="[a-z]*"' "$WORK/response" | sed 's/.*="//;s/"//' | tr '\n' ' ')|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE email_norm IN ('import-b328@gmail.com', 'hana-b328@stavby-b328.cz')")" \
  "2/1/3/|update new skip skip new skip |0"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=contacts&action=import_run" -d "_csrf=$(csrf)" -d "file=$B328_FILE" -d op=run
expect "3.28: … imported: the new ones with source import, the free-mail one without a company, the named company created with its domain; Eva updated, nothing else of hers changes; the file is gone; the change log keeps the counts only" \
  "$(b328_contact "$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_contacts WHERE email_norm = 'import-b328@gmail.com'")")|$(sq "SELECT CONCAT(c.name, '/', co.name, '/', co.domain) FROM ka_contacts c JOIN ka_companies co ON co.id = c.company_id WHERE c.email_norm = 'hana-b328@stavby-b328.cz'")|$(b328_contact "$B328_EVA")|$(ls "$WORK/web/storage/import/" | grep -c "contacts-$B328_FILE")|$(sq "SELECT popis FROM ka_protokol WHERE modul = 'contacts' AND akce = 'import' LIMIT 1")" \
  "Ivan Import/import-b328@gmail.com/-/null/null/import|Hana B328/Stavby B328 s.r.o./stavby-b328.cz|Eva B328/Eva-B328@b328firma.cz/+420 600 328 999/$B328_CO/$B328_JANA/manual|0|nové: 2, upravené: 1, přeskočené: 3"
# permissions: Jana (Enquiries and Customers, no administrator) works with customers, but merge, delete, export and import are
# the administrators'; Petr (Enquiries, no Customers) gets 403 and no box on the enquiry
B328_JJAR=$(b328_login b328-jana)
expect "3.28: Jana opens the list and the detail; merge, export and import are 403; no Merge or Delete buttons for her" \
  "$(curl -s -b "$B328_JJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts")|$(curl -s -b "$B328_JJAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=contacts&action=detail&id=$B328_EVA")|$(grep -c 'data-contact-merge\|data-contact-delete\|data-contacts-export' "$WORK/response")|$(curl -s -b "$B328_JJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts&action=merge&id=$B328_EVA")|$(curl -s -b "$B328_JJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts&action=export")|$(curl -s -b "$B328_JJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts&action=import")" \
  "200|200|0|403|403|403"
expect "3.28: … she may not delete the contact nor the administrator's log entry; her own entry she deletes" \
  "$(b328_post "$B328_JJAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=delete" -d "id=$B328_EVA" | cut -c1-3)|$(b328_post "$B328_JJAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=activity" -d "id=$B328_EVA" -d op=delete -d "activity=$B328_ACT" > /dev/null; sq "SELECT COUNT(*) FROM ka_contact_activities WHERE id = $B328_ACT")|$(b328_post "$B328_JJAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=activity" -d "id=$B328_EVA" -d op=add -d kind=meeting -d "text=Schůzka B328" > /dev/null; B328_JACT=$(sq "SELECT MAX(id) FROM ka_contact_activities WHERE contact_id = $B328_EVA AND author_id = $B328_JANA"); b328_post "$B328_JJAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=activity" -d "id=$B328_EVA" -d op=delete -d "activity=$B328_JACT" > /dev/null; sq "SELECT COUNT(*) FROM ka_contact_activities WHERE author_id = $B328_JANA")" \
  "403|1|0"
B328_PJAR=$(b328_login b328-petr)
curl -s -b "$B328_PJAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$B328_ENQ"; B328_PTOKEN=$(csrf)
expect "3.28: Petr without Customers – the list, the detail, a save and the palette search are 403; his enquiry detail has no box; his palette does not search customers" \
  "$(curl -s -b "$B328_PJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts")|$(curl -s -b "$B328_PJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts&action=detail&id=$B328_EVA")|$(curl -s -b "$B328_PJAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=contacts&action=save" -d "_csrf=$B328_PTOKEN" -d "id=$B328_EVA" -d name=Hacked)|$(curl -s -b "$B328_PJAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=contacts&action=search_json&q=Eva")|$(grep -c 'data-contact-box' "$WORK/response")|$(grep -c 'data-contacts=' "$WORK/response")|$(sq "SELECT name FROM ka_contacts WHERE id = $B328_EVA")" \
  "403|403|403|403|0|0|Eva B328"
expect "3.28: the palette search finds Eva by her company for the administrator, as JSON" \
  "$(curl -s -b "$JAR" "$B/admin.php?module=contacts&action=search_json&q=B328+Firma" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo count(array_filter($d["contacts"] ?? [], fn ($c) => $c["name"] === "Eva B328" && str_contains($c["url"], "action=detail&id='"$B328_EVA"'")));')" "1"
# delete (administrators): the contact goes, its enquiries and bookings stay unlinked; a company's contacts stay without it
b328_post "$JAR" "module=contacts&action=company&id=$B328_CO" "module=contacts&action=company_delete" -d "company=$B328_CO" > /dev/null
b328_post "$JAR" "module=contacts&action=detail&id=$B328_EVA" "module=contacts&action=delete" -d "id=$B328_EVA" > /dev/null
expect "3.28: Delete company – its contacts stay without it; Delete contact – its log, comments and tasks go, its enquiry and booking stay unlinked" \
  "$(sq "SELECT COUNT(*) FROM ka_companies WHERE id = $B328_CO")|$(sq "SELECT COUNT(*) FROM ka_contacts WHERE id = $B328_EVA")|$(sq "SELECT COUNT(*) FROM ka_contact_activities WHERE contact_id = $B328_EVA")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'contact:$B328_EVA'")|$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'contact:$B328_EVA'")|$(sq "SELECT CONCAT(COUNT(*), '/', IFNULL(MAX(contact_id), 'null')) FROM ka_poptavky WHERE idp = $B328_ENQ")|$(sq "SELECT IFNULL(contact_id, 'null') FROM ka_bookings WHERE id = $B328_BOOK")|$(find "$WORK/web/storage/cache/stranky" -name '*.html' 2>/dev/null | wc -l | tr -d ' ')" \
  "0|0|0|0|0|1/null|null|1"
setting page_cache "$B328_CACHE"
sq "DELETE FROM ka_notifications WHERE user_id IN ($B328_JANA, $B328_PETR) OR target LIKE 'contact:%' OR target LIKE 'company:%'; DELETE FROM ka_comments WHERE target LIKE 'company:%' OR target LIKE 'contact:%';
  DELETE FROM ka_tasks WHERE target LIKE 'contact:%' OR target LIKE 'company:%'; DELETE FROM ka_contact_activities WHERE contact_id IN (SELECT id FROM ka_contacts WHERE email_norm LIKE '%b328%');
  UPDATE ka_poptavky SET contact_id = NULL WHERE contact_id IN (SELECT id FROM (SELECT id FROM ka_contacts WHERE email_norm LIKE '%b328%') x);
  DELETE FROM ka_contacts WHERE email_norm LIKE '%b328%'; DELETE FROM ka_companies WHERE name LIKE '%B328%';
  DELETE FROM ka_poptavky WHERE idp IN ($B328_ENQ, $B328_FREE, $B328_NOMAIL); DELETE FROM ka_bookings WHERE id = $B328_BOOK;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($B328_JANA, $B328_PETR); DELETE FROM ka_uzivatele WHERE user LIKE 'b328-%'" > /dev/null
