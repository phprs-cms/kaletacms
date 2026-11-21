echo "== 3.49 review: anonymised records, a company's name with Customers off, the palette for authors, a deleted account, saved replies of a language switched off, save_internal_file in one piece, a record's files filtered, a searched file's size"
r449_get() { curl -s -b "${2:-$JAR}" -c "${2:-$JAR}" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
r449_post() { local jar="$1" query="$2"; shift 2; curl -s -b "$jar" -c "$jar" -o "$WORK/response" -L "$B/admin.php?$query" "$@"; }
r449_has() { grep -c -- "$1" "$WORK/response" | sed 's/^[1-9][0-9]*$/1/'; }
r449_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
r449_login() { # r449_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
r449_csrf() { r449_get "${2:-module=dashboard}" "$1" > /dev/null; csrf; }
r449_links() { sq "SELECT IFNULL(GROUP_CONCAT(CONCAT(file_id, '@', kind, ':', record_id) ORDER BY file_id, kind, record_id SEPARATOR ' '), '-') FROM ka_internal_file_links WHERE $1"; }
r449_mcp() { mcp_call "$1" "$2" "$3" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
R449_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'"); R449_LANGS=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'additional_languages'")
features +customers +pipeline +quotes +bookings +jazyky
R449_NOW=$(site_time); R449_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R449_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
r449_user() { # r449_user <login> <level> <sections, comma-separated> – prints the id
  sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('$1', '$R449_HASH', '$1', '$1@example.com', 'en', $2, '$R449_NOW', '$R449_NOW', 0, 'off')" > /dev/null
  local id; id=$(sq "SELECT idu FROM ka_uzivatele WHERE user = '$1'")
  for m in $(echo "$3" | tr ',' ' '); do sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($id, '$m')" > /dev/null; done; echo "$id"; }
R449_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R449_ADMIN, 'r449 full', 'full', 'all', SHA2('$R449_TOKEN', 256), '$R449_NOW')" > /dev/null

# the records and files of the section: a folder for everyone with a ready and a draft file, a contact, a company, a deal, a quote
sq "INSERT INTO ka_internal_folders (name, audience, roles, created_at, updated_at) VALUES ('R449 open', 'all', '', '$R449_NOW', '$R449_NOW')" > /dev/null
R449_FOLDER=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R449 open'")
sq "INSERT INTO ka_internal_files (folder_id, name, status, extension, size, version, created_by, created_at, updated_at) VALUES ($R449_FOLDER, 'r449-ready.pdf', 'ready', 'pdf', 4194304, 1, $R449_ADMIN, '$R449_NOW', '$R449_NOW'),
  ($R449_FOLDER, 'r449-draft.pdf', 'draft', 'pdf', 1024, 1, $R449_ADMIN, '$R449_NOW', '$R449_NOW')" > /dev/null
R449_READY=$(sq "SELECT id FROM ka_internal_files WHERE name = 'r449-ready.pdf'"); R449_DRAFT=$(sq "SELECT id FROM ka_internal_files WHERE name = 'r449-draft.pdf'")
R449_CONTACT=$(sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at, last_activity_at) VALUES ('jan-r449@example.com', 'jan-r449@example.com', 'Jan R449', 'manual', '$R449_NOW', '$R449_NOW', '$R449_NOW'); SELECT LAST_INSERT_ID();")
R449_COMPANY=$(sq "INSERT INTO ka_companies (name, created_at, updated_at) VALUES ('Acme R449 s.r.o.', '$R449_NOW', '$R449_NOW'); SELECT LAST_INSERT_ID();")
R449_DEAL=$(sq "INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('R449 deal', 'new', '$R449_NOW', '$R449_NOW'); SELECT LAST_INSERT_ID();")
R449_LIST=$(r449_php 'echo Kaleta\Core\Prices::defaultList($db, $app->settings())["id"];')
R449_QUOTE=$(sq "INSERT INTO ka_quotes (number, title, status, price_list_id, currency, language, vat_mode, customer_name, customer_email, created_by, created_at, updated_at) VALUES (NULL, 'Quote R449', 'draft', $R449_LIST, 'CZK', 'cs', 'standard', 'Jan R449', 'jan-r449@example.com', $R449_ADMIN, '$R449_NOW', '$R449_NOW'); SELECT LAST_INSERT_ID();")

# C1: an anonymised contact or quote takes no new link – neither in the administration nor over MCP; the anonymised quote's page offers no box
r449_php 'Kaleta\Core\Contacts::anonymise($db, [(int) $a[0]]); Kaleta\Core\QuoteDelivery::anonymise($db, [(int) $a[1]]);' "$R449_CONTACT" "$R449_QUOTE" > /dev/null
R449_C=$(r449_csrf "$JAR" 'module=internal_files')
r449_post "$JAR" 'module=internal_files&action=link' -d "_csrf=$R449_C" -d "file_id=$R449_READY" -d kind=contact -d "record_id=$R449_CONTACT"
expect "3.49 review C1: linking a file to an anonymised contact is refused in the administration (the message of an unknown record)" \
  "$(r449_links "kind = 'contact' AND record_id = $R449_CONTACT")|$(r449_has 'Záznam neexistuje, nebo ho nemůžete otevřít.')" "-|1"
expect "3.49 review C1: save_internal_file cannot link a file to an anonymised quote; its detail page offers no Link a file" \
  "$(r449_mcp "$R449_TOKEN" save_internal_file "{\"id\":$R449_READY,\"link\":\"quote:$R449_QUOTE\"}" | grep -c 'The record does not exist, or you cannot open it.')|$(r449_links "kind = 'quote' AND record_id = $R449_QUOTE")|$(r449_get "module=quotes&action=detail&id=$R449_QUOTE")|$(r449_has 'data-internal-files-box')" \
  "1|-|200|0"

# C2: a file linked to a company – with Customers off, a colleague without the Customers right reads no company name
R449_C=$(r449_csrf "$JAR" 'module=internal_files')
r449_post "$JAR" 'module=internal_files&action=link' -d "_csrf=$R449_C" -d "file_id=$R449_READY" -d kind=company -d "record_id=$R449_COMPANY"
R449_FILES=$(r449_user r449-files 1 'internal_files'); R449_FJAR="$WORK/jar-r449-files"; r449_login "$R449_FJAR" r449-files
features -customers
expect "3.49 review C2: Customers off – the colleague without the section sees 'a record you cannot open', never the company's name; the administrator still reads it" \
  "$(r449_get "module=internal_files&action=file&id=$R449_READY" "$R449_FJAR")|$(r449_has 'Acme R449')|$(r449_has 'a record you cannot open')|$(r449_get "module=internal_files&action=file&id=$R449_READY")|$(r449_has 'Acme R449')" \
  "200|0|1|200|1"
features +customers

# C3: the command palette offers Saved replies to an editor, not to an author (who would get a 403)
R449_AUTHOR=$(r449_user r449-author 0 'enquiries'); R449_EDITOR=$(r449_user r449-editor 1 'enquiries')
R449_AJAR="$WORK/jar-r449-author"; r449_login "$R449_AJAR" r449-author; R449_EJAR="$WORK/jar-r449-editor"; r449_login "$R449_EJAR" r449-editor
expect "3.49 review C3: the palette of an author has no Saved replies (403 there); an editor's has it" \
  "$(r449_get 'module=enquiries' "$R449_AJAR")|$(r449_has 'saved_replies')|$(r449_get 'module=enquiries&action=saved_replies' "$R449_AJAR")|$(r449_get 'module=enquiries' "$R449_EJAR")|$(r449_has 'saved_replies')" \
  "200|0|403|200|1"

# C4: deleting an account unlinks its booking person and removes its absences; a link to an account deleted before is no link
R449_LEAVER=$(r449_user r449-leaver 1 ''); R449_EVA=$(r449_user r449-eva 1 '')
sq "INSERT INTO ka_booking_staff (name, email, active, user_id) VALUES ('Leaver R449', 'leaver-r449@example.com', 1, $R449_LEAVER), ('Eva R449', 'r449-eva@example.com', 1, 99999990);
  INSERT INTO ka_absences (user_id, starts_on, ends_on, reason, created_at) VALUES ($R449_LEAVER, '$(site_date '+2 days')', '$(site_date '+9 days')', 'holiday', '$R449_NOW')" > /dev/null
R449_S_LEAVER=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Leaver R449'"); R449_S_EVA=$(sq "SELECT id FROM ka_booking_staff WHERE name = 'Eva R449'")
R449_C=$(r449_csrf "$JAR" 'module=users')
r449_post "$JAR" 'module=users&action=delete' -d "_csrf=$R449_C" -d "idu=$R449_LEAVER"
expect "3.49 review C4: the deleted account – its booking person is not linked any more, its absences are gone" \
  "$(sq "SELECT COUNT(*) FROM ka_uzivatele WHERE idu = $R449_LEAVER")|$(sq "SELECT IFNULL(user_id, '-') FROM ka_booking_staff WHERE id = $R449_S_LEAVER")|$(sq "SELECT COUNT(*) FROM ka_absences WHERE user_id = $R449_LEAVER")" "0|-|0"
expect "3.49 review C4: a person linked to a long-gone account counts as not linked; Link by e-mail links her to her account" \
  "$(r449_get 'module=bookings&action=staff')|$(r449_has 'data-staff-link')|$(r449_post "$JAR" 'module=bookings&action=staff_link' -d "_csrf=$(csrf)"; sq "SELECT IFNULL(user_id, '-') FROM ka_booking_staff WHERE id = $R449_S_EVA")" "200|1|$R449_EVA"

# C5: saved replies of a language switched off (pl) are offered nowhere, keep a tab in the admin and can be removed there
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', 'en');
  INSERT INTO ka_saved_replies (language, category, name, subject, text, sort_order, updated_at) VALUES ('', '', 'R449 default', '', 'Dobrý den', 0, '$R449_NOW'),
  ('en', '', 'R449 english', '', 'Hello', 0, '$R449_NOW'), ('pl', '', 'R449 polski', '', 'Dzień dobry', 0, '$R449_NOW')" > /dev/null
R449_PL=$(sq "SELECT id FROM ka_saved_replies WHERE name = 'R449 polski'")
R449_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav) VALUES ('$R449_NOW', 'Kontakt', '/kontakt', 'R449', 'r449@example.com', '[]', 1); SELECT LAST_INSERT_ID();")
expect "3.49 review C5: the reply form and get_email_signature leave out the replies of the language switched off" \
  "$(r449_get "module=enquiries&action=detail&id=$R449_ENQ")|$(r449_has 'R449 default')|$(r449_has 'R449 polski')|$(r449_mcp "$R449_TOKEN" get_email_signature '{}' | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_map(fn ($r) => $r["name"], array_filter($d["saved_replies"] ?? [], fn ($r) => str_starts_with($r["name"], "R449"))));')" \
  "200|1|0|R449 default,R449 english"
expect "3.49 review C5: the admin keeps a tab for it, marked switched off" \
  "$(r449_get 'module=enquiries&action=saved_replies&language=pl')|$(r449_has 'Polski (vypnuto)')|$(r449_has 'R449 polski')" "200|1|1"
R449_C=$(r449_csrf "$JAR" 'module=enquiries&action=saved_replies&language=pl')
r449_post "$JAR" 'module=enquiries&action=saved_replies_save' -d "_csrf=$R449_C" -d language=pl -d "replies[0][id]=$R449_PL" --data-urlencode 'replies[0][name]=R449 polski' -d 'replies[0][text]=x' -d 'replies[0][remove]=1'
R449_C=$(r449_csrf "$JAR" 'module=enquiries&action=saved_replies')
r449_post "$JAR" 'module=enquiries&action=saved_replies_save' -d "_csrf=$R449_C" -d language=fr -d 'replies[0][name]=R449 fr' -d 'replies[0][text]=Bonjour'
expect "3.49 review C5: removing them there works and the tab goes; a language never switched on is still refused" \
  "$(sq "SELECT COUNT(*) FROM ka_saved_replies WHERE language IN ('pl', 'fr')")|$(r449_get 'module=enquiries&action=saved_replies')|$(r449_has 'language=pl')" "0|200|0"

# C6: save_internal_file checks the whole call first – a refused unlink or an unknown folder leaves no new link behind
R449_C6A=$(r449_mcp "$R449_TOKEN" save_internal_file "{\"id\":$R449_READY,\"link\":\"deal:$R449_DEAL\",\"unlink\":\"company:$R449_COMPANY\",\"name\":\"\"}")
R449_C6B=$(r449_mcp "$R449_TOKEN" save_internal_file "{\"id\":$R449_READY,\"link\":\"deal:$R449_DEAL\",\"folder\":\"No such folder R449\"}")
R449_C6C=$(r449_mcp "$R449_TOKEN" save_internal_file "{\"id\":$R449_DRAFT,\"link\":\"deal:$R449_DEAL\",\"unlink\":\"deal:$R449_DEAL\"}")
expect "3.49 review C6: an invalid name, an unknown folder or an unlink of a link that is not there answer an error and write no link" \
  "$(printf '%s' "$R449_C6A" | grep -c 'Give the file a name')|$(printf '%s' "$R449_C6B" | grep -c 'No folder')|$(printf '%s' "$R449_C6C" | grep -c "The file is not linked to deal:$R449_DEAL")|$(r449_links "kind = 'deal' AND record_id = $R449_DEAL")|$(r449_links "kind = 'company' AND record_id = $R449_COMPANY")" \
  "1|1|1|-|$R449_READY@company:$R449_COMPANY"
R449_C6D=$(r449_mcp "$R449_TOKEN" save_internal_file "{\"id\":$R449_READY,\"link\":\"deal:$R449_DEAL\",\"unlink\":\"company:$R449_COMPANY\",\"description\":\"R449 price\"}")
expect "3.49 review C6: a valid call does all of it – the description, the new link and the unlink" \
  "$(printf '%s' "$R449_C6D" | grep -c '"linked"')|$(sq "SELECT description FROM ka_internal_files WHERE id = $R449_READY")|$(r449_links "kind IN ('deal', 'company') AND record_id IN ($R449_DEAL, $R449_COMPANY)")" \
  "1|R449 price|$R449_READY@deal:$R449_DEAL"

# C7: list_internal_files linked_to takes the status, the search and the folder too
sq "INSERT INTO ka_internal_file_links (file_id, kind, record_id, created_by, created_at) VALUES ($R449_DRAFT, 'deal', $R449_DEAL, $R449_ADMIN, '$R449_NOW')" > /dev/null
r449_names() { r449_mcp "$R449_TOKEN" list_internal_files "$1" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $n = array_column($d["files"] ?? [], "name"); sort($n); echo implode(",", $n), "#", $d["total"] ?? "?";'; }
expect "3.49 review C7: linked_to with status, search or another folder filters the record's files and counts what it gives" \
  "$(r449_names "{\"linked_to\":\"deal:$R449_DEAL\",\"status\":\"ready\"}")|$(r449_names "{\"linked_to\":\"deal:$R449_DEAL\",\"search\":\"draft\"}")|$(r449_names "{\"linked_to\":\"deal:$R449_DEAL\"}")" \
  "r449-ready.pdf#1|r449-draft.pdf#1|r449-draft.pdf,r449-ready.pdf#2"

# C10: a file found by search carries its size, so the attachments' check counts it before the upload
expect "3.49 review C10: search_json gives the size of each ready file in bytes" \
  "$(r449_get 'module=internal_files&action=search_json&q=r449&pick=1')|$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo implode(",", array_map(fn ($f) => $f["name"] . ":" . ($f["size"] ?? "-"), $d["files"] ?? []));' -- "$WORK/response")" \
  "200|r449-ready.pdf:4194304"

sq "DELETE FROM ka_internal_file_links WHERE file_id IN ($R449_READY, $R449_DRAFT); DELETE FROM ka_internal_files WHERE folder_id = $R449_FOLDER; DELETE FROM ka_internal_folders WHERE id = $R449_FOLDER;
  DELETE FROM ka_quotes WHERE id = $R449_QUOTE; DELETE FROM ka_deals WHERE id = $R449_DEAL; DELETE FROM ka_companies WHERE id = $R449_COMPANY; DELETE FROM ka_contacts WHERE id = $R449_CONTACT;
  DELETE FROM ka_booking_staff WHERE id IN ($R449_S_LEAVER, $R449_S_EVA); DELETE FROM ka_saved_replies WHERE name LIKE 'R449 %'; DELETE FROM ka_poptavky WHERE idp = $R449_ENQ;
  DELETE FROM ka_api_tokeny WHERE nazev = 'r449 full'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user LIKE 'r449-%'); DELETE FROM ka_uzivatele WHERE user LIKE 'r449-%';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', '$R449_LANGS')" > /dev/null
features --exact "$R449_EXT"
