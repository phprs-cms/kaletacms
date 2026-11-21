echo "== 3.49 integration (A meets B): the Attachments block of a reply and of a quote e-mail offers the internal files linked to the customer or the quote"
i449_get() { curl -s -b "${2:-$JAR}" -c "${2:-$JAR}" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
i449_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
i449_rows() { sed -n '/data-attach-list>/,/<\/ul>/p' "$WORK/response" | grep -o 'name="internal_files\[\]" value="[0-9]*" data-bytes="[0-9]*"[^>]*>' | sed 's/name="internal_files\[\]" value="\([0-9]*\)" data-bytes="[0-9]*"/\1/; s/ checked>/+/; s/>//' | paste -sd, -; } # 12,13+ (+ = ticked)
i449_login() { # i449_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
I449_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +customers +quotes
I449_FROM=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'mail_from'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES, ('mail_from', 'web@example.cz')" > /dev/null # the reply form needs a way to send
I449_NOW=$(site_time); I449_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
I449_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
i449_user() { # i449_user <login> <sections> – an editor with these sections; prints the id
  sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('$1', '$I449_HASH', '$1', '$1@example.com', 'en', 1, '$I449_NOW', '$I449_NOW', 0, 'off')" > /dev/null
  local id; id=$(sq "SELECT idu FROM ka_uzivatele WHERE user = '$1'")
  for m in $(echo "$2" | tr ',' ' '); do sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($id, '$m')" > /dev/null; done; echo "$id"; }

# a folder for everyone (a ready PDF, a draft, an SVG, a second PDF) and a folder of one role (a ready PDF)
I449_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('I449 board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_internal_folders (name, audience, roles, created_at, updated_at) VALUES ('I449 open', 'all', '', '$I449_NOW', '$I449_NOW'), ('I449 board', 'roles', '$I449_ROLE', '$I449_NOW', '$I449_NOW')" > /dev/null
I449_OPEN=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'I449 open'"); I449_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'I449 board'")
sq "INSERT INTO ka_internal_files (folder_id, name, status, extension, size, version, created_by, created_at, updated_at) VALUES
  ($I449_OPEN, 'i449-price.pdf', 'ready', 'pdf', 2048, 1, $I449_ADMIN, '$I449_NOW', '$I449_NOW'), ($I449_OPEN, 'i449-draft.pdf', 'draft', 'pdf', 1024, 1, $I449_ADMIN, '$I449_NOW', '$I449_NOW'),
  ($I449_OPEN, 'i449-logo.svg', 'ready', 'svg', 512, 1, $I449_ADMIN, '$I449_NOW', '$I449_NOW'), ($I449_OPEN, 'i449-terms.pdf', 'ready', 'pdf', 3072, 1, $I449_ADMIN, '$I449_NOW', '$I449_NOW'),
  ($I449_BOARD, 'i449-board.pdf', 'ready', 'pdf', 4096, 1, $I449_ADMIN, '$I449_NOW', '$I449_NOW')" > /dev/null
i449_file() { sq "SELECT id FROM ka_internal_files WHERE name = '$1'"; }
I449_PRICE=$(i449_file i449-price.pdf); I449_DRAFT=$(i449_file i449-draft.pdf); I449_SVG=$(i449_file i449-logo.svg); I449_TERMS=$(i449_file i449-terms.pdf); I449_SECRET=$(i449_file i449-board.pdf)
# a customer with an enquiry, a second enquiry without a customer, a draft quote
I449_CONTACT=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('iva-i449@example.com', 'iva-i449@example.com', 'Iva I449', 'manual', '$I449_NOW', '$I449_NOW', '$I449_NOW'); SELECT LAST_INSERT_ID();")
I449_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav, contact_id) VALUES ('$I449_NOW', 'Kontakt', '/kontakt', 'Okna I449', 'iva-i449@example.com', '[]', 1, $I449_CONTACT); SELECT LAST_INSERT_ID();")
I449_ENQ2=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav) VALUES ('$I449_NOW', 'Kontakt', '/kontakt', 'Bez I449', 'bez-i449@example.com', '[]', 1); SELECT LAST_INSERT_ID();")
I449_QUOTE=$( (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone();
  echo Kaleta\Core\Quotes::save($app, null, ["contact_id" => (int) $argv[2], "title" => "Quote I449", "lines" => [["name" => "Okno I449", "quantity" => "1", "unit" => "ks", "unit_price" => "1000", "vat_rate" => "21"]]], (int) $argv[1])["id"];' -- "$I449_ADMIN" "$I449_CONTACT") )
# links: every file to the customer; the terms and the board file to the quote
sq "INSERT INTO ka_internal_file_links (file_id, kind, record_id, created_by, created_at) VALUES ($I449_PRICE, 'contact', $I449_CONTACT, $I449_ADMIN, '$I449_NOW'), ($I449_DRAFT, 'contact', $I449_CONTACT, $I449_ADMIN, '$I449_NOW'),
  ($I449_SVG, 'contact', $I449_CONTACT, $I449_ADMIN, '$I449_NOW'), ($I449_SECRET, 'contact', $I449_CONTACT, $I449_ADMIN, '$I449_NOW'),
  ($I449_TERMS, 'quote', $I449_QUOTE, $I449_ADMIN, '$I449_NOW'), ($I449_SECRET, 'quote', $I449_QUOTE, $I449_ADMIN, '$I449_NOW')" > /dev/null

expect "3.49 integration: the reply to an enquiry offers the customer's ready files unticked (no draft, no SVG), labelled in the admin's language; an enquiry without a customer offers none" \
  "$(i449_get "module=enquiries&action=detail&id=$I449_ENQ")|$(i449_rows)|$(i449_has 'Připojeno k zákazníkovi')|$(i449_has 'i449-draft')|$(i449_has 'i449-logo')|$(i449_get "module=enquiries&action=detail&id=$I449_ENQ2")|$(i449_has 'Připojeno k zákazníkovi')" \
  "200|$I449_SECRET,$I449_PRICE|1|0|0|200|0"
expect "3.49 integration: the quote e-mail offers the quote's files unticked; one chosen without the script (attach_add) is ticked once, not offered again" \
  "$(i449_get "module=quotes&action=send&id=$I449_QUOTE")|$(i449_rows)|$(i449_has 'Připojeno k nabídce')|$(i449_get "module=quotes&action=send&id=$I449_QUOTE&attach_add=$I449_TERMS")|$(i449_rows)" \
  "200|$I449_SECRET,$I449_TERMS|1|200|$I449_TERMS+,$I449_SECRET"

# who sees what: an editor without the board folder gets the open file only; one without Customers gets nothing for the
# customer (the link would name the customer's documents) but still the quote's files; one without Internal files nothing
I449_ED=$(i449_user i449-ed enquiries,internal_files,contacts,quotes); I449_NOC=$(i449_user i449-noc enquiries,internal_files,quotes); I449_NOF=$(i449_user i449-nof enquiries,contacts,quotes)
I449_EJAR="$WORK/jar-i449-ed"; i449_login "$I449_EJAR" i449-ed; I449_CJAR="$WORK/jar-i449-noc"; i449_login "$I449_CJAR" i449-noc; I449_FJAR="$WORK/jar-i449-nof"; i449_login "$I449_FJAR" i449-nof
expect "3.49 integration: an editor without the board folder is offered the open file only (no row, no name of the other), in English" \
  "$(i449_get "module=enquiries&action=detail&id=$I449_ENQ" "$I449_EJAR")|$(i449_rows)|$(i449_has 'i449-board')|$(i449_has 'Linked to the customer')|$(i449_get "module=quotes&action=send&id=$I449_QUOTE" "$I449_EJAR")|$(i449_rows)|$(i449_has 'i449-board')" \
  "200|$I449_PRICE|0|1|200|$I449_TERMS|0"
expect "3.49 integration: without Customers no customer's file is offered (the quote's still are); without Internal files nothing is" \
  "$(i449_get "module=enquiries&action=detail&id=$I449_ENQ" "$I449_CJAR")|$(i449_rows)|$(i449_get "module=quotes&action=send&id=$I449_QUOTE" "$I449_CJAR")|$(i449_rows)|$(i449_get "module=enquiries&action=detail&id=$I449_ENQ" "$I449_FJAR")|$(i449_rows)|$(i449_has 'i449-price')" \
  "200||200|$I449_TERMS|200||0"
features -customers -pipeline
expect "3.49 integration: with Customers switched off the reply offers no customer's file" "$(i449_get "module=enquiries&action=detail&id=$I449_ENQ")|$(i449_rows)" "200|"
features +customers

sq "DELETE FROM ka_internal_file_links WHERE file_id IN ($I449_PRICE, $I449_DRAFT, $I449_SVG, $I449_TERMS, $I449_SECRET); DELETE FROM ka_internal_files WHERE folder_id IN ($I449_OPEN, $I449_BOARD);
  DELETE FROM ka_internal_folders WHERE id IN ($I449_OPEN, $I449_BOARD); DELETE FROM ka_role WHERE idr = $I449_ROLE; DELETE FROM ka_quote_lines WHERE quote_id = $I449_QUOTE; DELETE FROM ka_quotes WHERE id = $I449_QUOTE;
  DELETE FROM ka_poptavky WHERE idp IN ($I449_ENQ, $I449_ENQ2); DELETE FROM ka_contacts WHERE id = $I449_CONTACT;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($I449_ED, $I449_NOC, $I449_NOF); DELETE FROM ka_uzivatele WHERE idu IN ($I449_ED, $I449_NOC, $I449_NOF)" > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_from', '$I449_FROM')" > /dev/null
features --exact "$I449_EXT"
