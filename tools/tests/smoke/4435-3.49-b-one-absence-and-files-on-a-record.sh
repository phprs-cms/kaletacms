echo "== 3.49 B: one absence closes booking times; files on a record"
b449_get() { curl -s -b "${2:-$JAR}" -c "${2:-$JAR}" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
b449_post() { local jar="$1" query="$2"; shift 2; curl -s -b "$jar" -c "$jar" -o "$WORK/response" -L "$B/admin.php?$query" "$@"; } # follows the redirect: the flash is in $WORK/response
b449_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
b449_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
b449_login() { # b449_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
b449_csrf() { b449_get "${2:-module=dashboard}" "$1" > /dev/null; csrf; }
B449_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +bookings +customers +pipeline +quotes
B449_NOW=$(site_time); B449_DAY=$(site_date '+4 days'); B449_BEFORE=$(site_date '+3 days'); B449_AFTER=$(site_date '+5 days')
B449_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B449_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
b449_user() { # b449_user <login> <e-mail> <level> <sections, comma-separated>
  sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('$1', '$B449_HASH', '$1', '$2', 'en', $3, '$B449_NOW', '$B449_NOW', 0)" > /dev/null
  local id; id=$(sq "SELECT idu FROM ka_uzivatele WHERE user = '$1'")
  for m in $(echo "$4" | tr ',' ' '); do sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($id, '$m')" > /dev/null; done; echo "$id"; }
B449_OLA=$(b449_user b449-ola ola-b449@example.com 1 'pipeline,internal_files,contacts')
B449_PETRA=$(b449_user b449-petra petra-b449@example.com 1 'bookings')
b449_user b449-dup1 dup-b449@example.com 1 '' > /dev/null; b449_user b449-dup2 dup-b449@example.com 1 '' > /dev/null
B449_NOPIPE=$(b449_user b449-nopipe nopipe-b449@example.com 1 'internal_files,contacts')

# the booking set-up: one service, Ola linked to her account, Petra and "Dvojka" not linked (Dvojka's e-mail belongs to two accounts)
sq "INSERT INTO ka_booking_services (name, duration_min, buffer_min, active) VALUES ('B449 service', 60, 0, 1);
  INSERT INTO ka_booking_staff (name, email, active, user_id) VALUES ('Ola B449', 'ola-b449@example.com', 1, $B449_OLA), ('Petra B449', 'petra-b449@example.com', 1, NULL), ('Dvojka B449', 'dup-b449@example.com', 1, NULL)" > /dev/null
B449_SERVICE=$(sq "SELECT id FROM ka_booking_services WHERE name = 'B449 service'")
B449_S_OLA=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Ola B449'"); B449_S_PETRA=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Petra B449'"); B449_S_DUP=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Dvojka B449'")
for s in $B449_S_OLA $B449_S_PETRA $B449_S_DUP; do
  sq "INSERT INTO ka_booking_staff_services (staff_id, service_id) VALUES ($s, $B449_SERVICE)" > /dev/null
  for d in 1 2 3 4 5 6 7; do sq "INSERT INTO ka_booking_hours (staff_id, weekday, time_from, time_to) VALUES ($s, $d, '09:00', '12:00')" > /dev/null; done
done
b449_slots() { curl -s "$B/_booking/slots?service=$B449_SERVICE&staff=$1&day=$2" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(",", $d["slots"] ?? []);'; }
expect "3.49 B: before the absence Ola and Petra are free on the day" "$(b449_slots "$B449_S_OLA" "$B449_DAY")|$(b449_slots "$B449_S_PETRA" "$B449_DAY")" "09:00,10:00,11:00|09:00,10:00,11:00"

# a pending booking of Ola (still held) with a proposed time, both on the day – they stay, the flash counts them
sq "INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, name, email, status, hold_until, token_hash, created_at, source) VALUES
  ($B449_SERVICE, $B449_S_OLA, '$B449_DAY 10:00:00', '$B449_DAY 11:00:00', 'Karel B449', 'karel-b449@example.com', 'pending', '$(site_time '+2 days')', SHA2('$(openssl rand -hex 16)', 256), '$B449_NOW', 'admin')" > /dev/null
B449_BOOKING=$(sq "SELECT id FROM ka_bookings WHERE email = 'karel-b449@example.com'")
sq "INSERT INTO ka_booking_proposals (booking_id, starts_at, ends_at) VALUES ($B449_BOOKING, '$B449_DAY 11:00:00', '$B449_DAY 12:00:00')" > /dev/null
B449_PROPOSAL=$(sq "SELECT id FROM ka_booking_proposals WHERE booking_id = $B449_BOOKING")
B449_UCSRF=$(b449_csrf "$JAR" "module=users&action=edit&id=$B449_OLA")
b449_post "$JAR" 'module=users&action=absence_add' -d "_csrf=$B449_UCSRF" -d "idu=$B449_OLA" -d "starts_on=$B449_DAY" -d "ends_on=$B449_DAY" -d reason=training
expect "3.49 B: an absence added in Users – the flash counts the pending booking and the proposed time, with the way to Bookings" \
  "$(sq "SELECT COUNT(*) FROM ka_absences WHERE user_id = $B449_OLA")|$(b449_has 'hlaska-ok.*rezervací: 1 a nabídnutých termínů: 1.*module=bookings">')" "1|1"
expect "3.49 B: … and nothing of them changed" "$(sq "SELECT CONCAT(status, '|', starts_at, '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = $B449_BOOKING)) FROM ka_bookings WHERE id = $B449_BOOKING")" "pending|$B449_DAY 10:00:00|1"
expect "3.49 B: the absence day has no free time for Ola; the day before and after unchanged; Petra (not linked) untouched; anyone is still Petra" \
  "$(b449_slots "$B449_S_OLA" "$B449_DAY")|$(b449_slots "$B449_S_OLA" "$B449_BEFORE")|$(b449_slots "$B449_S_OLA" "$B449_AFTER")|$(b449_slots "$B449_S_PETRA" "$B449_DAY")|$(b449_slots 0 "$B449_DAY")" \
  "|09:00,10:00,11:00|09:00,10:00,11:00|09:00,10:00,11:00|09:00,10:00,11:00"
B449_MONTH=${B449_DAY:0:7}
expect "3.49 B: /_booking/days leaves the day out for Ola and keeps it for Petra" \
  "$(curl -s "$B/_booking/days?service=$B449_SERVICE&staff=$B449_S_OLA&month=$B449_MONTH" | grep -c "\"$B449_DAY\"")|$(curl -s "$B/_booking/days?service=$B449_SERVICE&staff=$B449_S_PETRA&month=$B449_MONTH" | grep -c "\"$B449_DAY\"")" "0|1"
expect "3.49 B: a booking of that time for Ola is refused; accepting the proposed time is refused (it has just been taken)" \
  "$(b449_php '[$b, $e] = Kaleta\Core\Booking::book($app, ["service_id" => (int) $a[0], "staff_id" => (int) $a[1], "slot" => $a[2] . " 09:00", "name" => "Eva B449", "email" => "eva-b449@example.com", "by" => "admin"]); echo $b === null ? $e : "booked";' "$B449_SERVICE" "$B449_S_OLA" "$B449_DAY")|$(b449_php 'echo Kaleta\Core\Booking::acceptProposal($app, Kaleta\Core\Booking::find($db, (int) $a[0]), (int) $a[1]) ?? "accepted";' "$B449_BOOKING" "$B449_PROPOSAL")" \
  "taken|Sorry, this time has just been taken. Please choose another one."
expect "3.49 B: the booking detail labels the booking and the proposed time: the person is away" \
  "$(b449_get "module=bookings&action=detail&id=$B449_BOOKING")|$(grep -o 'data-booking-away' "$WORK/response" | wc -l | tr -d ' ')" "200|2"
expect "3.49 B: the person's form lists the absence from the account (days only) and says where it is set" \
  "$(b449_get "module=bookings&action=staff_edit&id=$B449_S_OLA")|$(b449_has 'data-staff-absences')|$(b449_has 'training')|$(b449_has 'Holiday')" "200|1|0|0"
sq "DELETE FROM ka_absences WHERE user_id = $B449_OLA" > /dev/null
expect "3.49 B: removing the absence brings the times back at once (nothing was copied; 10:00 stays held by the pending booking)" "$(b449_slots "$B449_S_OLA" "$B449_DAY")" "09:00,11:00"

# with Bookings off the flash says nothing about bookings
features -bookings
B449_UCSRF=$(b449_csrf "$JAR" "module=users&action=edit&id=$B449_OLA")
b449_post "$JAR" 'module=users&action=absence_add' -d "_csrf=$B449_UCSRF" -d "idu=$B449_OLA" -d "starts_on=$B449_DAY" -d "ends_on=$B449_DAY" -d reason=holiday
expect "3.49 B: Bookings off – the absence is saved, the flash names no bookings" "$(sq "SELECT COUNT(*) FROM ka_absences WHERE user_id = $B449_OLA")|$(b449_has 'nabídnutých termínů')" "1|0"
sq "DELETE FROM ka_absences WHERE user_id = $B449_OLA" > /dev/null
features +bookings

# "Link by e-mail": Petra's address is one account's, Dvojka's two accounts' – only Petra is linked
expect "3.49 B: the People list says how many people are not linked and offers Link by e-mail" \
  "$(b449_get 'module=bookings&action=staff')|$(b449_has 'data-staff-link')" "200|1"
B449_SCSRF=$(csrf)
b449_post "$JAR" 'module=bookings&action=staff_link' -d "_csrf=$B449_SCSRF"
expect "3.49 B: Link by e-mail links the person with a matching account and leaves an ambiguous address" \
  "$(sq "SELECT CONCAT(IFNULL((SELECT user_id FROM ka_booking_staff WHERE id = $B449_S_PETRA), '-'), '|', IFNULL((SELECT user_id FROM ka_booking_staff WHERE id = $B449_S_DUP), '-'))")|$(b449_has 'Propojeno s účty: 1')" "$B449_PETRA|-|1"
B449_PCSRF=$(b449_csrf "$JAR" "module=users&action=edit&id=$B449_PETRA")
b449_post "$JAR" 'module=users&action=absence_add' -d "_csrf=$B449_PCSRF" -d "idu=$B449_PETRA" -d "starts_on=$B449_DAY" -d "ends_on=$B449_DAY" -d reason=day_off
expect "3.49 B: once linked, Petra's absence closes her times too" "$(b449_slots "$B449_S_PETRA" "$B449_DAY")" ""
sq "DELETE FROM ka_absences WHERE user_id IN ($B449_OLA, $B449_PETRA); DELETE FROM ka_bookings WHERE id = $B449_BOOKING" > /dev/null

# ---------- files on a record ----------
B449_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('B449 board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_internal_folders (name, audience, roles, created_at, updated_at) VALUES ('B449 open', 'all', '', '$B449_NOW', '$B449_NOW'), ('B449 board', 'roles', '$B449_ROLE', '$B449_NOW', '$B449_NOW')" > /dev/null
B449_OPEN=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'B449 open'"); B449_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'B449 board'")
sq "INSERT INTO ka_internal_files (folder_id, name, status, extension, size, version, created_by, created_at, updated_at) VALUES ($B449_OPEN, 'b449-price.pdf', 'ready', 'pdf', 2048, 1, $B449_ADMIN, '$B449_NOW', '$B449_NOW'),
  ($B449_BOARD, 'b449-secret.pdf', 'ready', 'pdf', 1024, 1, $B449_ADMIN, '$B449_NOW', '$B449_NOW')" > /dev/null
B449_PRICE=$(sq "SELECT id FROM ka_internal_files WHERE name = 'b449-price.pdf'"); B449_SECRET=$(sq "SELECT id FROM ka_internal_files WHERE name = 'b449-secret.pdf'")
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('anna-b449@example.com', 'anna-b449@example.com', 'Anna B449', 'manual', '$B449_NOW', '$B449_NOW', '$B449_NOW'),
  ('anna2-b449@example.com', 'anna2-b449@example.com', 'Anna B449 dup', 'manual', '$B449_NOW', '$B449_NOW', '$B449_NOW'), ('cyril-b449@example.com', 'cyril-b449@example.com', 'Cyril B449', 'manual', '$B449_NOW', '$B449_NOW', '$B449_NOW');
  INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('B449 deal', 'new', '$B449_NOW', '$B449_NOW')" > /dev/null
B449_ANNA=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'anna-b449@example.com'"); B449_ANNA2=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'anna2-b449@example.com'"); B449_CYRIL=$(sq "SELECT id FROM ka_contacts WHERE email_norm = 'cyril-b449@example.com'")
B449_DEAL=$(sq "SELECT id FROM ka_deals WHERE title = 'B449 deal'")
B449_LIST=$(b449_php 'echo Kaleta\Core\Prices::defaultList($db, $app->settings())["id"];')
B449_QUOTE=$(sq "INSERT INTO ka_quotes (number, title, status, price_list_id, currency, language, vat_mode, customer_name, customer_email, created_by, created_at, updated_at) VALUES (NULL, 'Quote B449', 'draft', $B449_LIST, 'CZK', 'cs', 'standard', 'Cyril B449', 'cyril-b449@example.com', $B449_ADMIN, '$B449_NOW', '$B449_NOW'); SELECT LAST_INSERT_ID();")
b449_link() { # b449_link <jar> <file> <kind> <record> [unlink]
  local c; c=$(b449_csrf "$1" 'module=internal_files'); b449_post "$1" "module=internal_files&action=${5:-link}" -d "_csrf=$c" -d "file_id=$2" -d "kind=$3" -d "record_id=$4"; }
b449_links() { sq "SELECT IFNULL(GROUP_CONCAT(CONCAT(file_id, '@', kind, ':', record_id) ORDER BY file_id, kind, record_id SEPARATOR ' '), '-') FROM ka_internal_file_links WHERE $1"; }
expect "3.49 B: a deal page with no linked file shows only a closed Link a file – no empty list" \
  "$(b449_get "module=pipeline&action=detail&id=$B449_DEAL")|$(b449_has 'data-internal-files-box="deal"')|$(b449_has 'data-internal-file-row')|$(b449_has 'name="file_id" data-pick="files"')" "200|1|0|1"
b449_link "$JAR" "$B449_PRICE" deal "$B449_DEAL"; b449_link "$JAR" "$B449_SECRET" deal "$B449_DEAL"
expect "3.49 B: an administrator links two files to the deal; the deal page lists both; the change log names ids only" \
  "$(b449_links "kind = 'deal' AND record_id = $B449_DEAL")|$(b449_has 'b449-price.pdf')|$(b449_has 'b449-secret.pdf')|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'internal_files' AND akce = 'link' AND popis = '#$B449_PRICE → deal #$B449_DEAL'")" \
  "$B449_PRICE@deal:$B449_DEAL $B449_SECRET@deal:$B449_DEAL|1|1|1"
B449_OJAR="$WORK/jar-b449-ola"; b449_login "$B449_OJAR" b449-ola
expect "3.49 B: a colleague without the board folder sees the open file only – no row, no count of the other" \
  "$(b449_get "module=pipeline&action=detail&id=$B449_DEAL" "$B449_OJAR")|$(b449_has 'b449-price.pdf')|$(b449_has 'b449-secret')|$(grep -o 'Internal files <small>[0-9]*' "$WORK/response" | grep -o '[0-9]*$')" "200|1|0|1"
B449_NJAR="$WORK/jar-b449-nopipe"; b449_login "$B449_NJAR" b449-nopipe
b449_link "$B449_NJAR" "$B449_PRICE" deal "$B449_DEAL"; B449_REFUSED=$(grep -o '<p class="hlaska hlaska-chyba"[^<]*' "$WORK/response" | head -1 | sed 's/.*>//')
b449_link "$JAR" "$B449_PRICE" deal 9999999; B449_UNKNOWN=$(grep -o '<p class="hlaska hlaska-chyba"[^<]*' "$WORK/response" | head -1 | sed 's/.*>//')
expect "3.49 B: a colleague without the Pipeline cannot link to a deal; an unknown deal gets the same message (in the same language)" \
  "$(b449_links "record_id = 9999999 OR (created_by = $B449_NOPIPE)")|$B449_REFUSED|$(sq "SELECT COUNT(*) FROM ka_internal_file_links WHERE record_id = 9999999")" "-|The record does not exist, or you cannot open it.|0"
expect "3.49 B: … and the administrator's (Czech) refusal for the unknown deal is the same sentence" "$B449_UNKNOWN" "Záznam neexistuje, nebo ho nemůžete otevřít."
expect "3.49 B: the file page lists its records (read-only, Unlink per row)" \
  "$(b449_get "module=internal_files&action=file&id=$B449_PRICE")|$(b449_has 'data-internal-links')|$(b449_has "module=pipeline&amp;action=detail&amp;id=$B449_DEAL")" "200|1|1"

# merge two contacts: the links follow, a file linked to both stays once
b449_link "$JAR" "$B449_PRICE" contact "$B449_ANNA"; b449_link "$JAR" "$B449_PRICE" contact "$B449_ANNA2"; b449_link "$JAR" "$B449_SECRET" contact "$B449_ANNA2"
b449_php 'Kaleta\Core\Contacts::merge($app, (int) $a[0], (int) $a[1], (int) $a[2]);' "$B449_ANNA" "$B449_ANNA2" "$B449_ADMIN" > /dev/null
expect "3.49 B: a merge moves the links to the kept contact, without a duplicate" \
  "$(b449_links "kind = 'contact' AND record_id IN ($B449_ANNA, $B449_ANNA2)")" "$B449_PRICE@contact:$B449_ANNA $B449_SECRET@contact:$B449_ANNA"
# deleting a file removes its links
B449_DCSRF=$(b449_csrf "$JAR" "module=internal_files&action=file&id=$B449_SECRET")
b449_post "$JAR" 'module=internal_files&action=delete' -d "_csrf=$B449_DCSRF" -d "id=$B449_SECRET"
expect "3.49 B: deleting a file removes its links" "$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE id = $B449_SECRET")|$(b449_links "file_id = $B449_SECRET")" "0|-"
# MCP: get_contact counts what the user can open; a user without Internal files gets no key; a write connection links, drafts only is refused
B449_FULL="kaleta_$(openssl rand -hex 24)"; B449_DRAFTS="kaleta_$(openssl rand -hex 24)"; B449_NOFILES="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($B449_ADMIN, 'b449 full', 'full', 'all', SHA2('$B449_FULL', 256), '$B449_NOW'),
  ($B449_ADMIN, 'b449 drafts', 'drafts', 'all', SHA2('$B449_DRAFTS', 256), '$B449_NOW'), ($B449_PETRA, 'b449 read', 'read', 'all', SHA2('$B449_NOFILES', 256), '$B449_NOW')" > /dev/null
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($B449_PETRA, 'contacts'), ($B449_PETRA, 'quotes')" > /dev/null
b449_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
expect "3.49 B: MCP get_contact names internal_files for a user who can open them, and omits it for one without the section" \
  "$(b449_mcp "$B449_FULL" get_contact "{\"id\":$B449_ANNA}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["internal_files"] ?? "-";')|$(b449_mcp "$B449_NOFILES" get_contact "{\"id\":$B449_ANNA}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo isset($d["contact"]) ? ($d["internal_files"] ?? "-") : "?";')" "1|-"
B449_LINKED=$(b449_mcp "$B449_FULL" save_internal_file "{\"id\":$B449_PRICE,\"link\":\"quote:$B449_QUOTE\"}")
B449_DRAFTED=$(b449_mcp "$B449_DRAFTS" save_internal_file "{\"id\":$B449_PRICE,\"unlink\":\"quote:$B449_QUOTE\"}")
expect "3.49 B: save_internal_file link works for a write connection; a drafts-only one is refused for unlink (the link stays)" \
  "$(b449_links "kind = 'quote' AND record_id = $B449_QUOTE")|$(printf '%s' "$B449_LINKED" | grep -c '"linked"')|$(printf '%s' "$B449_DRAFTED" | grep -c 'live change – this connection writes drafts only')" "$B449_PRICE@quote:$B449_QUOTE|1|1"
expect "3.49 B: get_quote of a read connection without Internal files carries no linked file; get_internal_file names the records the user may open" \
  "$(b449_mcp "$B449_NOFILES" get_quote "{\"id\":$B449_QUOTE}" | grep -c 'internal_files\|b449-price')|$(b449_mcp "$B449_FULL" get_internal_file "{\"id\":$B449_PRICE}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_map(fn ($l) => $l["kind"] . ":" . $l["id"], $d["links"] ?? []));')" \
  "0|quote:$B449_QUOTE,contact:$B449_ANNA,deal:$B449_DEAL"
expect "3.49 B: list_internal_files linked_to gives the deal's file; an unknown record gets the same message as a forbidden one" \
  "$(b449_mcp "$B449_FULL" list_internal_files "{\"linked_to\":\"deal:$B449_DEAL\"}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_column($d["files"] ?? [], "name"));')|$(b449_mcp "$B449_FULL" list_internal_files '{"linked_to":"deal:9999999"}' | grep -c 'The record does not exist, or you cannot open it.')" "b449-price.pdf|1"

# the Pipeline off: the file page prints the deal as text; linked_to over MCP gives the feature message; on again, the link is back
features -pipeline
expect "3.49 B: Pipeline off – the file page names the deal without a link, marked switched off" \
  "$(b449_get "module=internal_files&action=file&id=$B449_PRICE")|$(b449_has "module=pipeline&amp;action=detail&amp;id=$B449_DEAL")|$(b449_has "Obchod #$B449_DEAL")" "200|0|1"
expect "3.49 B: Pipeline off – linked_to deal:… answers that the feature is switched off" \
  "$(b449_mcp "$B449_FULL" list_internal_files "{\"linked_to\":\"deal:$B449_DEAL\"}" | grep -c 'switched off on this site')" "1"
features +pipeline
expect "3.49 B: Pipeline on again – the link is back on the deal" "$(b449_get "module=pipeline&action=detail&id=$B449_DEAL")|$(b449_has 'b449-price.pdf')" "200|1"

# anonymising a contact and a quote forgets their links; erasing the person forgets the links, keeps the file and says so
b449_link "$JAR" "$B449_PRICE" contact "$B449_CYRIL"
b449_php 'Kaleta\Core\Contacts::anonymise($db, [(int) $a[0]]); Kaleta\Core\QuoteDelivery::anonymise($db, [(int) $a[1]]);' "$B449_CYRIL" "$B449_QUOTE" > /dev/null
expect "3.49 B: anonymising a contact and a quote removes their links; the file stays" \
  "$(b449_links "(kind = 'contact' AND record_id = $B449_CYRIL) OR (kind = 'quote' AND record_id = $B449_QUOTE)")|$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE id = $B449_PRICE")" "-|1"
expect "3.49 B: the personal data request counts the linked files for an administrator" \
  "$(b449_post "$JAR" 'module=enquiries&action=personal' -d "_csrf=$(b449_csrf "$JAR" 'module=enquiries&action=personal')" -d email=anna-b449@example.com; b449_has 'data-personal-files')" "1"
B449_ERASED=$(b449_php 'echo json_encode(Kaleta\Core\PersonalData::erase($app, "anna-b449@example.com")["kept"], JSON_UNESCAPED_UNICODE);')
expect "3.49 B: erasing the person – the links gone, the file there, kept says so" \
  "$(b449_links "kind = 'contact' AND record_id = $B449_ANNA")|$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE id = $B449_PRICE")|$B449_ERASED" \
  '-|1|[{"what":"internal files","until":"","reason":"company documents – check them by hand"}]'

# tidy up: the accounts, the booking set-up, the files, the records
sq "DELETE FROM ka_internal_file_links WHERE file_id = $B449_PRICE; DELETE FROM ka_internal_files WHERE id = $B449_PRICE; DELETE FROM ka_internal_folders WHERE id IN ($B449_OPEN, $B449_BOARD); DELETE FROM ka_role WHERE idr = $B449_ROLE;
  DELETE FROM ka_booking_staff WHERE id IN ($B449_S_OLA, $B449_S_PETRA, $B449_S_DUP); DELETE FROM ka_booking_services WHERE id = $B449_SERVICE; DELETE FROM ka_deals WHERE id = $B449_DEAL; DELETE FROM ka_quotes WHERE id = $B449_QUOTE;
  DELETE FROM ka_contacts WHERE id = $B449_CYRIL; DELETE FROM ka_api_tokeny WHERE nazev LIKE 'b449 %';
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user LIKE 'b449-%'); DELETE FROM ka_uzivatele WHERE user LIKE 'b449-%'" > /dev/null
features --exact "$B449_EXT"
