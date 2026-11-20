echo "== 3.47 B: the application on the record template with one Save, the board as JSON and on all positions, Internal files – several files at once and a preview"
b347_login() { # b347_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; curl -s -c "$1" -o "$WORK/b347-page" "$B/admin.php"; local c; c=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' "$WORK/b347-page" | sed -n 1p)
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
b347_csrf() { curl -s -b "$1" -c "$1" -o "$WORK/b347-page" "$B/admin.php?module=$2"; sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' "$WORK/b347-page" | sed -n 1p; } # a file, never a pipe that closes early (pipefail)
B347_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$B347_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$B347_EXT"),applications')" > /dev/null
B347_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
    ('b347-hr', '$B347_HASH', 'Hela B347', 'hela-b347@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off'),
    ('b347-ida', '$B347_HASH', 'Ida B347', 'ida-b347@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'applications' FROM ka_uzivatele WHERE user = 'b347-hr';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'internal_files' FROM ka_uzivatele WHERE user = 'b347-ida'" > /dev/null
B347_HR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b347-hr'")
B347_JH="$WORK/b347-hr.jar"; b347_login "$B347_JH" b347-hr
B347_JI="$WORK/b347-ida.jar"; b347_login "$B347_JI" b347-ida
b347_app() { sq "INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, language, data, status, status_at, last_activity_at) VALUES ('$(site_time)', $1, '$2', 'Job application', '$3', '$4', '$4', '', '[]', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();"; }
B347_A=$(b347_app 9347 'Welder B347' 'Edita Bártová B347' 'edita-b347@example.com')
B347_B=$(b347_app 9348 'Painter B347' 'Oto B347' 'oto-b347@example.com')
B347_C=$(b347_app 9347 'Welder B347' '' 'mail-only-b347@example.com')
b347_post() { local jar="$1" action="$2"; shift 2; local c; c=$(b347_csrf "$jar" applications); curl -s -b "$jar" -c "$jar" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=applications&action=$action" -d "_csrf=$c" "$@"; }

# U64: the record template, the person as the title, one Save
curl -s -b "$B347_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$B347_A"
expect "3.47 U64: the detail carries the applicant's name as its title and heading, the record bar and one Handling form; no Delete for HR" \
  "$(grep -c '<title>Edita Bártová B347 – ' "$WORK/response")|$(grep -c '<h1>Edita Bártová B347</h1>' "$WORK/response")|$(grep -c 'data-record-bar' "$WORK/response")|$(grep -c 'action=handling" data-application-move' "$WORK/response")|$(grep -c 'data-application-delete ' "$WORK/response")" "1|1|1|1|0"
curl -s -b "$B347_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$B347_C"
expect "3.47 U64: without a name the e-mail is the title" "$(grep -c '<h1>mail-only-b347@example.com</h1>' "$WORK/response")" "1"
expect "3.47 U64: POST handling changes the status (with its reason) and the responsible person in one request, each in the change log" \
  "$(b347_post "$B347_JH" handling -d "id=$B347_A" -d status=rejected -d reject_reason=position_filled -d "assignee_id=$B347_HR")|$(sq "SELECT CONCAT(status, ':', reject_reason, ':', assignee_id = $B347_HR) FROM ka_applications WHERE id = $B347_A")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce IN ('status', 'assign') AND popis LIKE '%$B347_A%'")" \
  "302|rejected:position_filled:1|2"
expect "3.47 U64: the same Save again changes nothing and writes nothing" \
  "$(b347_post "$B347_JH" handling -d "id=$B347_A" -d status=rejected -d reject_reason=position_filled -d "assignee_id=$B347_HR")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce IN ('status', 'assign') AND popis LIKE '%$B347_A%'")" "302|2"
curl -s -b "$B347_JH" -o "$WORK/response" "$B/admin.php?module=applications&status="
expect "3.47 U64: the list – the bulk form follows the table, Assign names its button, the status tabs are a select on a phone; the strip has no Move by form" \
  "$(tr -d '\n\t' < "$WORK/response" | grep -c '</table></div><form class="uchazeci-hromadne"')|$(grep -c 'value="assign">Assign</button>' "$WORK/response")|$(grep -c 'id="uchazeci-stav-vyber"' "$WORK/response")|$(grep -c 'action=move' "$WORK/response")" "1|1|1|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
expect "3.47 U64: Move by form is linked from the settings; it still answers an administrator and refuses HR" \
  "$(grep -c 'data-applications-move-link' "$WORK/response")|$(grep -c 'module=applications&amp;action=move"' "$WORK/response")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=move")|$(curl -s -b "$B347_JH" -o /dev/null -w '%{http_code}' "$B/admin.php?module=applications&action=move")" \
  "1|1|200|403"

# U65: the board answers the script as JSON; "All positions"
expect "3.47 U65: status with format=json answers JSON and moves the application; an unknown status answers ok:false with 400" \
  "$(b347_post "$B347_JH" status -d "id=$B347_B" -d status=interview -d format=json -d return=board -d job=9348)|$(grep -o '"ok":true,"status":"interview"' "$WORK/response")|$(sq "SELECT status FROM ka_applications WHERE id = $B347_B")|$(b347_post "$B347_JH" status -d "id=$B347_B" -d status=nonsense -d format=json)|$(grep -o '"ok":false' "$WORK/response")" \
  "200|\"ok\":true,\"status\":\"interview\"|interview|400|\"ok\":false"
curl -s -b "$B347_JH" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=applications&action=board&job=all" > "$WORK/b347-code"
expect "3.47 U65: job=all shows the cards of every position, each naming its position" \
  "$(cat "$WORK/b347-code")|$(grep -c '<option value="all" selected>' "$WORK/response")|$(grep -c "data-board-card=\"$B347_B\"" "$WORK/response")|$(grep -c "data-board-card=\"$B347_C\"" "$WORK/response")|$(grep -c 'data-board-position' "$WORK/response" | awk '{print ($1 >= 2)}')|$(grep -c 'data-board-status=' "$WORK/response")" \
  "200|1|1|1|1|6"

# U66: several files at once, from All folders, a refusal named, the preview
B347_TOKEN=$(b347_csrf "$JAR" internal_files)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$B347_TOKEN" -d "name=B347 Shared" -d audience=all
B347_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('B347 board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$B347_TOKEN" -d "name=B347 Board" -d audience=roles -d "roles[]=$B347_ROLE"
B347_SHARED=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'B347 Shared'"); B347_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'B347 Board'")
printf 'Price list B347\n' > "$WORK/b347-prices.txt"; printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=' | base64 -d > "$WORK/b347-logo.png"
printf '<?php echo 1;' > "$WORK/b347-run.txt"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=internal_files"
expect "3.47 U66: All folders offers the upload with a folder to choose and several files; the shared filter; no permanent banner" \
  "$(grep -c 'name="files\[\]" type="file" multiple' "$WORK/response")|$(grep -c 'select id="if-folder" name="folder_id"' "$WORK/response")|$(grep -c 'class="zakaznici-filtr" data-internal-filter' "$WORK/response")|$(grep -c '<p class="hlaska">Documents for the team\|<p class="hlaska">Dokumenty' "$WORK/response")" "1|1|1|0"
B347_UP=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'internal_files' AND akce = 'upload'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$B347_TOKEN" -F "folder_id=$B347_SHARED" \
  -F "files[]=@$WORK/b347-prices.txt" -F "files[]=@$WORK/b347-logo.png" -F "tags=B347" > "$WORK/b347-redirect"
expect "3.47 U66: two files in one POST from All folders are both stored in the chosen folder with the tags, one change-log line each, and the answer is the folder" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(name, ':', tags) ORDER BY name) FROM ka_internal_files WHERE folder_id = $B347_SHARED")|$(( $(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'internal_files' AND akce = 'upload'") - B347_UP ))|$(grep -c "folder=$B347_SHARED" "$WORK/b347-redirect")" \
  "b347-logo.png:B347,b347-prices.txt:B347|2|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$B347_TOKEN" -F "folder_id=$B347_BOARD" -F "files[]=@$WORK/b347-run.txt" -F "files[]=@$WORK/b347-prices.txt;filename=b347-minutes.txt"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=internal_files&folder=$B347_BOARD"
expect "3.47 U66: a file refused by its content is named with the reason while the other is stored" \
  "$(grep -c 'Stored: 1. b347-run.txt – \|Uloženo: 1. b347-run.txt – ' "$WORK/response")|$(sq "SELECT GROUP_CONCAT(name) FROM ka_internal_files WHERE folder_id = $B347_BOARD")" "1|b347-minutes.txt"
B347_PNG=$(sq "SELECT id FROM ka_internal_files WHERE name = 'b347-logo.png'"); B347_MIN=$(sq "SELECT id FROM ka_internal_files WHERE name = 'b347-minutes.txt'")
curl -s -b "$JAR" -D "$WORK/b347-headers" -o "$WORK/b347-preview" "$B/admin.php?module=internal_files&action=preview&id=$B347_PNG"
expect "3.47 U66: the preview of a PNG answers inline with its type, nosniff and no-store, the same bytes, and writes a change-log line" \
  "$(grep -ci '^content-disposition: inline' "$WORK/b347-headers")|$(grep -ci '^content-type: image/png' "$WORK/b347-headers")|$(grep -ci '^x-content-type-options: nosniff' "$WORK/b347-headers")|$(grep -ci '^cache-control: no-store' "$WORK/b347-headers")|$(cmp -s "$WORK/b347-preview" "$WORK/b347-logo.png" && echo same)|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'internal_files' AND akce = 'preview' AND popis LIKE '%$B347_PNG%'")" \
  "1|1|1|1|same|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=internal_files&action=file&id=$B347_PNG"
expect "3.47 U66: the file page – a record bar with Preview and Download, Delete in More (out of the Save form), two columns" \
  "$(grep -c 'data-record-bar' "$WORK/response")|$(grep -c 'data-internal-preview' "$WORK/response")|$(grep -c 'class="tl" href="[^"]*action=download' "$WORK/response")|$(grep -c 'data-record-more' "$WORK/response")|$(grep -c 'formaction=' "$WORK/response")|$(grep -c 'class="zaznam-bok"' "$WORK/response")" "1|1|1|1|0|1"
expect "3.47 U66: a text document previews as plain text; someone who does not see the folder gets 404, as for a PDF" \
  "$(curl -s -b "$JAR" -D - -o /dev/null "$B/admin.php?module=internal_files&action=preview&id=$B347_MIN" | grep -ci '^content-type: text/plain')|$(curl -s -b "$B347_JI" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=preview&id=$B347_MIN")|$(curl -s -b "$B347_JI" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=preview&id=$B347_PNG")" \
  "1|404|200"
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$WORK/b347.pdf"
B347_ONE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$B347_TOKEN" -F "folder_id=$B347_SHARED" -F "files[]=@$WORK/b347.pdf")
B347_PDF=$(sq "SELECT id FROM ka_internal_files WHERE name = 'b347.pdf'")
expect "3.47 U66: one file stored opens its page; a PDF has no preview (404) and no Preview link" \
  "$(echo "$B347_ONE" | grep -c "action=file&id=$B347_PDF")|$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=preview&id=$B347_PDF")|$(curl -s -b "$JAR" "$B/admin.php?module=internal_files&action=file&id=$B347_PDF" | grep -c 'data-internal-preview')" "1|404|0"

# clean-up: the files through the module (bytes and rows), then the folders, the people and the applications
B347_TOKEN=$(b347_csrf "$JAR" internal_files)
for b347_id in $(sq "SELECT id FROM ka_internal_files WHERE folder_id IN ($B347_SHARED, $B347_BOARD)"); do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=delete" -d "_csrf=$B347_TOKEN" -d "id=$b347_id"
done
sq "DELETE FROM ka_internal_folders WHERE id IN ($B347_SHARED, $B347_BOARD); DELETE FROM ka_role WHERE idr = $B347_ROLE;
  DELETE FROM ka_applications WHERE id IN ($B347_A, $B347_B, $B347_C); DELETE FROM ka_application_counts WHERE job_item_id IN (9347, 9348);
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user IN ('b347-hr', 'b347-ida')); DELETE FROM ka_uzivatele WHERE user IN ('b347-hr', 'b347-ida');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$B347_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
