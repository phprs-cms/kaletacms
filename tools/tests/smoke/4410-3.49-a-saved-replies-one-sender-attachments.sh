echo "== 3.49 A: replies – saved replies per language, one sender, attachments from Internal files and uploads, never queued, a quote numbered once"
a449_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
a449_login() { # a449_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
a449_token() { curl -s -b "${2:-$JAR}" -c "${2:-$JAR}" -o "$WORK/response" "$B/admin.php?$1"; csrf; } # the form token of a page (the page stays in $WORK/response)
a449_mail() { grep -l "^X-Rcpt-To: $1" "$MAIL_SINK_DIR"/*.eml 2>/dev/null | tail -1; }
a449_text() { php -r '$b = (string) file_get_contents($argv[1]); preg_match_all("/Content-Type: text\/(?:plain|html)[^\r\n]*\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }' -- "$1"; } # the text parts of a message, decoded
A449_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'"); A449_LANGS=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'additional_languages'")
A449_COMPANY=$(sq "SELECT IFNULL(MAX(hodnota), '') FROM ka_nastaveni WHERE promenna = 'company_name'")
features +quotes +jazyky +claude
A449_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'"); A449_ADMIN_EMAIL=$(sq "SELECT email FROM ka_uzivatele WHERE idu = $A449_ADMIN")
sq "UPDATE ka_uzivatele SET email = 'alex449@firma.example' WHERE idu = $A449_ADMIN; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES, ('mail_from', 'web@example.cz'), ('additional_languages', 'en'), ('company_name', 'Okna A449 s.r.o.')" > /dev/null
# a second server on the same site with larger upload limits (the suite's PHP takes 2 MB per file, 8 MB per request)
A449_PORT=$((PORT + 27)); A449_B="http://127.0.0.1:$A449_PORT"
(cd "$WORK/web" && exec php -d post_max_size=24M -d upload_max_filesize=12M -d display_errors=0 -S "127.0.0.1:$A449_PORT" system/dev-router.php > "$WORK/server-a449.log" 2>&1) & A449_PID=$!
for i in $(seq 1 100); do curl -s -o /dev/null "$A449_B/" && break; sleep 0.2; done

# saved replies: one in the default language, one in English
A449_T=$(a449_token 'module=enquiries&action=saved_replies')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=saved_replies_save" -d "_csrf=$A449_T" -d language= -d 'replies[0][id]=0' --data-urlencode 'replies[0][name]=Ceník A449' \
  --data-urlencode 'replies[0][subject]=Ceník – {{topic}}' --data-urlencode $'replies[0][text]=Dobrý den {{name}},\nposíláme ceník. {{company}} {{other}}'
A449_T=$(a449_token 'module=enquiries&action=saved_replies&language=en')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=saved_replies_save" -d "_csrf=$A449_T" -d language=en -d 'replies[0][id]=0' --data-urlencode 'replies[0][name]=Prices A449' \
  --data-urlencode 'replies[0][text]=Hello {{name}}, our prices.'
A449_CS=$(sq "SELECT id FROM ka_saved_replies WHERE name = 'Ceník A449'"); A449_EN=$(sq "SELECT id FROM ka_saved_replies WHERE name = 'Prices A449'"); A449_CS="${A449_CS:-0}"; A449_EN="${A449_EN:-0}"
expect "3.49 A: saved replies are stored per language (default '' and en) with who and when" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(language, ':', name, ':', updated_by = $A449_ADMIN) ORDER BY language SEPARATOR ',') FROM ka_saved_replies")" ":Ceník A449:1,en:Prices A449:1"
check "3.49 A: the saved replies screen shows the English tab with its reply in a details row" 200 "/admin.php?module=enquiries&action=saved_replies&language=en" 'data-saved-reply-row'
expect "3.49 A: … the tabs, the English reply and an empty Add a reply" "$(grep -c 'data-saved-replies-tabs' "$WORK/response")|$(grep -c 'value="Prices A449"' "$WORK/response")|$(grep -c '<details class="ulozena-odpoved" open data-saved-reply-row>' "$WORK/response")" "1|1|1"

# an enquiry from the English version offers the English reply first; ?saved_reply= prefills; the JSON is filled
A449_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav) VALUES ('$(site_time)', 'Contact', '/en/contact', 'Okna A449', 'jana449@example.com', '[[\"Jméno\",\"Jana Nováková\"],[\"Zpráva\",\"Kolik stojí okna?\"]]', 0); SELECT LAST_INSERT_ID();")
check "3.49 A: the reply form has the saved reply choice and the attachments, multipart" 200 "/admin.php?module=enquiries&action=detail&id=$A449_ENQ" 'data-saved-reply='
expect "3.49 A: the enquiry's language first (the English reply before the Czech one), Insert, Manage saved replies, multipart, the Attachments block" \
  "$(sed -n '/id="odpoved-ulozena"/,/<\/select>/p' "$WORK/response" | grep -o '<option value="[0-9]\+">' | sed 's/[^0-9]//g' | paste -sd, -)|$(grep -c 'data-saved-reply-insert' "$WORK/response")|$(grep -c 'action=saved_replies' "$WORK/response" | sed 's/^[1-9][0-9]*$/1/')|$(grep -c 'enctype="multipart/form-data" data-reply-form' "$WORK/response")|$(grep -c 'data-attachments' "$WORK/response")" \
  "$A449_EN,$A449_CS|1|1|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A449_ENQ&saved_reply=$A449_CS"
expect "3.49 A: ?saved_reply= prefills the subject and the text, the known placeholders filled and an unknown one kept" \
  "$(grep -c 'name="subject" maxlength="200" value="Ceník – Okna A449"' "$WORK/response")|$(grep -c 'Dobrý den Jana Nováková,' "$WORK/response")|$(grep -c 'posíláme ceník. Okna A449 s.r.o. {{other}}</textarea>' "$WORK/response")" "1|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/a449-head" "$B/admin.php?module=enquiries&action=saved_reply_json&enquiry=$A449_ENQ&id=$A449_EN"
expect "3.49 A: saved_reply_json answers the filled reply, no-store; an unknown one is 404" \
  "$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo $d["text"] ?? "-", "|", $d["subject"] ?? "-";' -- "$WORK/response")|$(tr -d '\r' < "$WORK/a449-head" | grep -ci '^cache-control: no-store')|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=saved_reply_json&enquiry=$A449_ENQ&id=999999")" \
  "Hello Jana Nováková, our prices.|Re: Okna A449|1|404"

# internal files: a folder for everyone with a ready file and a draft, a folder of one role with a file the editor does not see
A449_T=$(a449_token 'module=internal_files')
A449_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('A449 board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
for a449_folder in "A449 All|all" "A449 Board|roles"; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$A449_T" --data-urlencode "name=${a449_folder%%|*}" -d "audience=${a449_folder##*|}" -d "roles[]=$A449_ROLE"
done
A449_ALL=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'A449 All'"); A449_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'A449 Board'")
printf '%%PDF-1.4\n%% Cenik A449\n1 0 obj << >> endobj\ntrailer << >>\n%%%%EOF\n' > "$WORK/a449.pdf"
for a449_up in "$A449_ALL|Ceník A449.pdf" "$A449_ALL|Draft A449.pdf" "$A449_BOARD|Board A449.pdf"; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$A449_T" -F "folder_id=${a449_up%%|*}" -F "file=@$WORK/a449.pdf;filename=${a449_up##*|}"
done
A449_FILE=$(sq "SELECT id FROM ka_internal_files WHERE name = 'Ceník A449.pdf'"); A449_DRAFT=$(sq "SELECT id FROM ka_internal_files WHERE name = 'Draft A449.pdf'"); A449_SECRET=$(sq "SELECT id FROM ka_internal_files WHERE name = 'Board A449.pdf'")
A449_FILE="${A449_FILE:-0}"; A449_DRAFT="${A449_DRAFT:-0}"; A449_SECRET="${A449_SECRET:-0}"
sq "UPDATE ka_internal_files SET status = 'draft' WHERE id = $A449_DRAFT" > /dev/null
a449_reply() { # a449_reply <jar> <base url> <enquiry> [curl -F …]
  local t; t=$(a449_token "module=enquiries&action=detail&id=$3" "$1"); curl -s -b "$1" -c "$1" -o "$WORK/a449-post" -w '%{http_code}' -X POST "$2/admin.php?module=enquiries&action=reply" -F "_csrf=$t" -F "id=$3" "${@:4}"; }
a449_replies() { sq "SELECT IFNULL(JSON_LENGTH(replies), 0) FROM ka_poptavky WHERE idp = $1"; }

# a reply with one upload and one internal file arrives as multipart with both names; the history lists them; the file's section logs it
printf '%%PDF-1.4\n%% upload A449\n%%%%EOF\n' > "$WORK/a449-upload.pdf"
a449_reply "$JAR" "$B" "$A449_ENQ" -F 'subject=Ceník – {{topic}}' -F $'text=Dobrý den {{name}},\nposíláme ceník.' -F "internal_files[]=$A449_FILE" -F "files[]=@$WORK/a449-upload.pdf;filename=Ceník služeb.pdf" > /dev/null
A449_EML=$(a449_mail jana449@example.com)
expect "3.49 A: the reply with an upload and an internal file arrives as multipart/mixed with both names (ASCII, .pdf kept); the placeholders are filled; From the site, Reply-To the person" \
  "$(grep -c '^Content-Type: multipart/mixed' "$A449_EML" 2>/dev/null)|$(grep -o 'filename="[^"]*"' "$A449_EML" 2>/dev/null | sort | paste -sd, -)|$(a449_text "$A449_EML" 2>/dev/null | grep -c 'Dobrý den Jana Nováková,')|$(a449_text "$A449_EML" 2>/dev/null | grep -c '{{')|$(grep -c '^Reply-To: alex449@firma.example' "$A449_EML" 2>/dev/null)" \
  "1|filename=\"Cenik-A449.pdf\",filename=\"Cenik-sluzeb.pdf\"|2|0|1"
expect "3.49 A: the history keeps the names as sent, the sizes and the internal file's id; one change-log line in Internal files, never the address" \
  "$(sq "SELECT CONCAT_WS('|', JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].status')), JSON_LENGTH(JSON_EXTRACT(replies, '\$[0].files')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].files[0].name')), JSON_EXTRACT(replies, '\$[0].files[1].file'), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].subject'))) FROM ka_poptavky WHERE idp = $A449_ENQ")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'internal_files' AND akce = 'sent' AND popis LIKE '% $A449_FILE %' AND popis LIKE '% $A449_ENQ' AND popis NOT LIKE '%jana449%'")" \
  "sent|2|Cenik-sluzeb.pdf|$A449_FILE|Ceník – Okna A449|1"
check "3.49 A: the conversation names the files of the reply, the internal one as a link" 200 "/admin.php?module=enquiries&action=detail&id=$A449_ENQ" 'data-reply-files'
expect "3.49 A: … with a link to the internal file" "$(grep -c "module=internal_files&amp;action=file&amp;id=$A449_FILE\">Cenik-A449.pdf</a>" "$WORK/response")" "1"

# refusals: a draft (the administrator), a file of a folder that is not the editor's, too many; nothing is sent or logged
A449_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('a449-editor', '$A449_HASH', 'Eda A449', 'eda449@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('a449-author', '$A449_HASH', 'Ota A449', 'ota449@example.com', 'en', 0, '$(site_time)', '$(site_time)', 0, 'off')" > /dev/null
A449_EDITOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a449-editor'"); A449_AUTHOR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a449-author'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($A449_EDITOR, 'enquiries'), ($A449_EDITOR, 'internal_files'), ($A449_AUTHOR, 'enquiries')" > /dev/null
JAR_A449E="$WORK/jar-a449e"; a449_login "$JAR_A449E" a449-editor; JAR_A449A="$WORK/jar-a449a"; a449_login "$JAR_A449A" a449-author
A449_MAILS=$(sq "SELECT COUNT(*) FROM ka_posta")
a449_reply "$JAR" "$B" "$A449_ENQ" -F subject=S -F text=Draft -F "internal_files[]=$A449_DRAFT" > /dev/null
A449_R1=$(a449_replies "$A449_ENQ")
a449_reply "$JAR_A449E" "$B" "$A449_ENQ" -F subject=S -F text=Secret -F "internal_files[]=$A449_SECRET" > /dev/null
curl -s -b "$JAR_A449E" -c "$JAR_A449E" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A449_ENQ"
expect "3.49 A: a draft file is refused, a file of a folder not for the person is refused with the message of a missing one; the text comes back, nothing is sent" \
  "$A449_R1|$(a449_replies "$A449_ENQ")|$(sq "SELECT COUNT(*) FROM ka_posta")|$(grep -c 'The file does not exist, or its folder is not for you.' "$WORK/response")|$(grep -c '>Secret</textarea>' "$WORK/response")|$(grep -c "name=\"internal_files\[\]\" value=\"$A449_SECRET\"" "$WORK/response")" \
  "1|1|$A449_MAILS|1|1|0"

# sizes: 5 MB + 1 byte is refused before a read, nothing logged; a 9 MB body on the suite's server gets the size message, not "the form has expired"
{ printf '%%PDF-1.4\n'; head -c 4999992 /dev/zero; } > "$WORK/a449-big.pdf"
a449_reply "$JAR" "$A449_B" "$A449_ENQ" -F subject=S -F text=Big -F "files[]=@$WORK/a449-big.pdf;filename=big.pdf" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A449_ENQ"
expect "3.49 A: 5,000,001 bytes of attachments are refused with the sizes named, nothing sent, the person is told to choose the uploads again" \
  "$(stat -f %z "$WORK/a449-big.pdf" 2>/dev/null || stat -c %s "$WORK/a449-big.pdf")|$(a449_replies "$A449_ENQ")|$(sq "SELECT COUNT(*) FROM ka_posta")|$(grep -c 'big.pdf (' "$WORK/response")|$(grep -c 'Nahrávané soubory vyberte znovu.' "$WORK/response")" \
  "5000001|1|$A449_MAILS|1|1"
head -c 9437184 /dev/zero > "$WORK/a449-9mb.bin"
A449_9=$(a449_token "module=enquiries&action=detail&id=$A449_ENQ"; curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -H "Referer: $B/admin.php?module=enquiries&action=detail&id=$A449_ENQ" -X POST "$B/admin.php?module=enquiries&action=reply" -F "_csrf=x" -F "id=$A449_ENQ" -F "files[]=@$WORK/a449-9mb.bin;filename=9mb.pdf")
expect "3.49 A: a body over post_max_size answers 413 with the size and a way back to the form, never the expired-form message" \
  "$(echo -n "$A449_9" | tail -c 3)|$(grep -c 'Nahrávaná data byla větší, než tento server přijme (8 MB)' "$WORK/response")|$(grep -c "href=\"admin.php?module=enquiries&amp;action=detail&amp;id=$A449_ENQ\"" "$WORK/response")|$(grep -c 'vypršel' "$WORK/response")" "413|1|1|0"

# the mail server is down: a reply with files is not sent and not queued, the text and the ticked internal file come back
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('smtp_port', '$((PORT + 28))')" > /dev/null
a449_reply "$JAR" "$B" "$A449_ENQ" -F subject=Down -F text=Pozdeji -F "internal_files[]=$A449_FILE" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A449_ENQ"
expect "3.49 A: SMTP down – a reply with files is neither recorded nor queued (no body kept), the text and the ticked internal file come back" \
  "$(a449_replies "$A449_ENQ")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'jana449@example.com' AND telo IS NOT NULL")|$(grep -c '>Pozdeji</textarea>' "$WORK/response")|$(grep -c "name=\"internal_files\[\]\" value=\"$A449_FILE\" data-bytes=\"[0-9]*\" checked" "$WORK/response")" "1|0|1|1"

# quotes: SMTP down – numbered once and queued with the PDF only, the warning names the files
a449_quote() { a449_php '$c = (int) ($db->value("SELECT id FROM {contacts} WHERE email_norm = ?", ["zak449@example.cz"]) ?? 0) ?: $db->insert("contacts", ["email" => "zak449@example.cz", "email_norm" => "zak449@example.cz", "name" => "Zdena A449", "source" => "manual", "created_at" => date("Y-m-d H:i:s"), "updated_at" => date("Y-m-d H:i:s")]);
  echo Kaleta\Core\Quotes::save($app, null, ["contact_id" => $c, "title" => $a[1], "lines" => [["name" => "Okno A449", "quantity" => "1", "unit" => "ks", "unit_price" => "1000", "vat_rate" => "21"]]], (int) $a[0])["id"];' "$A449_ADMIN" "$1"; }
a449_send() { # a449_send <base url> <quote> [curl -F …]
  local t; t=$(a449_token "module=quotes&action=send&id=$2"); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$1/admin.php?module=quotes&action=send" -F "_csrf=$t" -F "id=$2" -F 'subject=Nabídka {number}' -F 'message=Dobrý den' "${@:3}"; }
A449_Q1=$(a449_quote 'Down A449'); A449_Q1="${A449_Q1:-0}"
check "3.49 A: the quote send dialog has the Attachments block and the reload form, multipart" 200 "/admin.php?module=quotes&action=send&id=$A449_Q1" 'enctype="multipart/form-data" data-quote-send'
expect "3.49 A: … the Attachments block and the preview line for the files" "$(grep -c 'data-attachments' "$WORK/response")|$(grep -c 'data-quote-mail-files hidden' "$WORK/response")|$(grep -c 'id="nabidka-nacist"' "$WORK/response")" "1|1|1"
a449_send "$B" "$A449_Q1" -F attach_pdf=1 -F "internal_files[]=$A449_FILE"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=send&id=$A449_Q1"
expect "3.49 A: SMTP down – the quote is numbered once and queued with the PDF only; the warning names the files; its history keeps no files" \
  "$(sq "SELECT CONCAT_WS('|', status, number IS NOT NULL, history NOT LIKE '%files%') FROM ka_quotes WHERE id = $A449_Q1")|$(sq "SELECT CONCAT_WS('|', telo LIKE '%prilohy%', telo LIKE '%.pdf%', telo NOT LIKE '%Cenik-A449%') FROM ka_posta WHERE idp = (SELECT sent_mail FROM ka_quotes WHERE id = $A449_Q1)")|$(grep -c 'E-mail čeká ve frontě bez přiložených souborů: Cenik-A449.pdf.' "$WORK/response")" \
  "sent|1|1|1|1|1|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES" > /dev/null

# a quote sent with two files: numbered once, the files and the PDF arrive, the history names the files; over the limit no number is taken
A449_Q2=$(a449_quote 'Two A449'); A449_Q2="${A449_Q2:-0}"
a449_send "$B" "$A449_Q2" -F attach_pdf=1 -F "internal_files[]=$A449_FILE" -F "files[]=@$WORK/a449-upload.pdf;filename=Plán.pdf"
A449_QEML=$(grep -l "^X-Rcpt-To: zak449@example.cz" "$MAIL_SINK_DIR"/*.eml 2>/dev/null | tail -1)
expect "3.49 A: a quote with two files – sent, numbered, the PDF and both files arrive, the sent entry of its history names the files" \
  "$(sq "SELECT CONCAT_WS('|', status, number IS NOT NULL, JSON_LENGTH(JSON_EXTRACT(history, '\$[1].files'))) FROM ka_quotes WHERE id = $A449_Q2")|$(grep -o 'filename="[^"]*"' "$A449_QEML" 2>/dev/null | wc -l | tr -d ' ')|$(grep -c 'filename="Plan.pdf"' "$A449_QEML" 2>/dev/null)|$(grep -c 'filename="Cenik-A449.pdf"' "$A449_QEML" 2>/dev/null)" \
  "sent|1|2|3|1|1"
check "3.49 A: the quote's history names the files that went" 200 "/admin.php?module=quotes&action=detail&id=$A449_Q2" 'data-quote-history-files'
A449_Q3=$(a449_quote 'Big A449'); A449_Q3="${A449_Q3:-0}"
a449_send "$A449_B" "$A449_Q3" -F "files[]=@$WORK/a449-big.pdf;filename=big.pdf"
expect "3.49 A: files over the limit – the quote stays a draft without a number" "$(sq "SELECT CONCAT_WS('|', status, number IS NULL) FROM ka_quotes WHERE id = $A449_Q3")" "draft|1"
{ printf '%%PDF-1.4\n'; head -c 4998990 /dev/zero; } > "$WORK/a449-4.pdf"
a449_send "$A449_B" "$A449_Q3" -F attach_pdf=1 -F "files[]=@$WORK/a449-4.pdf;filename=almost.pdf"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=send&id=$A449_Q3"
expect "3.49 A: files that fit alone but not with the PDF – the files go, the PDF stays out with its warning, numbered once" \
  "$(sq "SELECT CONCAT_WS('|', status, number IS NOT NULL, JSON_LENGTH(JSON_EXTRACT(history, '\$[1].files'))) FROM ka_quotes WHERE id = $A449_Q3")|$(grep -c 'bez PDF' "$WORK/response" | sed 's/^[1-9][0-9]*$/1/')" "sent|1|1|1"

# who changes saved replies: an editor may, an author with the section may not (the screen and the save)
A449_ET=$(a449_token 'module=enquiries&action=saved_replies' "$JAR_A449E")
curl -s -b "$JAR_A449E" -c "$JAR_A449E" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=saved_replies_save" -d "_csrf=$A449_ET" -d language= -d "replies[0][id]=$A449_CS" --data-urlencode 'replies[0][name]=Ceník A449' \
  --data-urlencode 'replies[0][text]=Upraveno' -d 'replies[1][id]=0' -d 'replies[1][name]=Editor A449' -d 'replies[1][text]=Text'
A449_AT=$(a449_token "module=enquiries&action=detail&id=$A449_ENQ" "$JAR_A449A")
expect "3.49 A: an editor saves saved replies (the id stays), an author with the section gets 403 on the screen and the save" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(id = $A449_CS, ':', name, ':', text) ORDER BY sort_order SEPARATOR ',') FROM ka_saved_replies WHERE language = ''")|$(curl -s -b "$JAR_A449A" -o /dev/null -w '%{http_code}' "$B/admin.php?module=enquiries&action=saved_replies")|$(curl -s -b "$JAR_A449A" -c "$JAR_A449A" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=enquiries&action=saved_replies_save" -d "_csrf=$A449_AT" -d language= -d 'replies[0][name]=X' -d 'replies[0][text]=Y')|$(sq "SELECT COUNT(*) FROM ka_saved_replies")" \
  "1:Ceník A449:Upraveno,0:Editor A449:Text|403|403|3"

# MCP: get_email_signature brings the saved replies with their placeholders; list_enquiries counts them once, on the first unanswered enquiry
A449_TOKEN="kaleta_$(openssl rand -hex 24)" # a connection of its own (full access, every tool) – earlier sections leave the suite's token as they need it
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($A449_ADMIN, 'a449 full', 'full', 'all', SHA2('$A449_TOKEN', 256), '$(site_time)')" > /dev/null
mcp_call "$A449_TOKEN" get_email_signature '{}' > "$WORK/response"
expect "3.49 A: get_email_signature carries saved_replies – full rows with the placeholders, languages as codes" \
  "$(mcp_value saved_replies | php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; echo implode(",", array_map(fn ($r) => $r["language"] . ":" . $r["name"] . ":" . (int) str_contains($r["text"] ?? "", "{{") . ":" . (int) isset($r["more"]), $d));')" \
  "cs:Ceník A449:0:0,cs:Editor A449:0:0,en:Prices A449:1:0"
grep -q 'saved_replies' "$WORK/response" || echo "  --     get_email_signature answered: $(head -c 300 "$WORK/response")"
A449_NEW=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES ('$(site_time)', 'Contact', '/kontakt', 'new449@example.com', '[]', 0); SELECT LAST_INSERT_ID();")
mcp_call "$A449_TOKEN" list_enquiries '{"limit":5}' > "$WORK/response"
expect "3.49 A: list_enquiries – saved_replies: 3 only on the first enquiry still waiting for an answer" \
  "$(mcp_value | php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; echo implode(",", array_map(fn ($r) => $r["id"] . ":" . ($r["saved_replies"] ?? "-"), array_filter($d, fn ($r) => isset($r["saved_replies"]))));')" "$A449_NEW:3"

# anonymising the enquiry and the quote empties the file names
A449_T=$(a449_token "module=enquiries&action=detail&id=$A449_ENQ"); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$A449_T" -d "idp=$A449_ENQ"
a449_php 'Kaleta\Core\QuoteDelivery::anonymise($db, [(int) $a[0]]);' "$A449_Q2"
expect "3.49 A: anonymising the enquiry and the quote empties the file names (who and when stay)" \
  "$(sq "SELECT CONCAT_WS('|', JSON_LENGTH(replies), replies LIKE '%files%', replies LIKE '%Cenik%') FROM ka_poptavky WHERE idp = $A449_ENQ")|$(sq "SELECT CONCAT_WS('|', history LIKE '%files%', history LIKE '%Plan.pdf%', JSON_UNQUOTE(JSON_EXTRACT(history, '\$[1].action'))) FROM ka_quotes WHERE id = $A449_Q2")" "1|0|0|0|0|sent"

kill "$A449_PID" 2> /dev/null || true; wait "$A449_PID" 2> /dev/null || true
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'a449 full'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($A449_EDITOR, $A449_AUTHOR); DELETE FROM ka_uzivatele WHERE idu IN ($A449_EDITOR, $A449_AUTHOR); DELETE FROM ka_saved_replies;
  UPDATE ka_uzivatele SET email = '$A449_ADMIN_EMAIL' WHERE idu = $A449_ADMIN; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('additional_languages', '$A449_LANGS'), ('company_name', '$A449_COMPANY')" > /dev/null
features --exact "$A449_EXT"
