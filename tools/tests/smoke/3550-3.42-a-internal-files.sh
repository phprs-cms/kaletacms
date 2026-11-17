echo "== 3.42 A: internal files – folders, versions, rights per folder, never public, MCP"
r342a_login() { # r342a_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
r342a_csrf() { curl -s -b "$1" -c "$1" "$B/admin.php?module=internal_files" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
TOKEN=$(r342a_csrf "$JAR")

# the section starts empty; an administrator creates a folder for everyone and one for a role
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r342a.html" "$B/admin.php?module=internal_files"
expect "3.42 A: Internal files opens in Workspace for an administrator with an empty state and New folder" \
  "$(grep -c 'module=internal_files&amp;action=folder"' "$WORK/r342a.html" | tr -d ' ' | sed 's/^[1-9][0-9]*$/yes/')|$(grep -c 'class="prazdny-stav"' "$WORK/r342a.html")" "yes|1"
R342A_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('R342a board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=R342a General" -d audience=all
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=R342a Board" -d audience=roles -d "roles[]=$R342A_ROLE"
R342A_GENERAL=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R342a General'")
R342A_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R342a Board'")
expect "3.42 A: two folders – everyone with the section, and one limited to a role" "$(sq "SELECT CONCAT(audience, ':', roles) FROM ka_internal_folders WHERE id IN ($R342A_GENERAL, $R342A_BOARD) ORDER BY id" | tr '\n' ' ')" "all: roles:$R342A_ROLE "
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r342a.html" -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=R342a Empty roles" -d audience=roles -L
expect "3.42 A: a folder limited to roles needs a role" "$(sq "SELECT COUNT(*) FROM ka_internal_folders WHERE name = 'R342a Empty roles'")" "0"

# upload, check the content, version
printf '# Onboarding R342a\n\nKeys, laptop, the first week.\n' > "$WORK/onboarding.md"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$TOKEN" -F "folder_id=$R342A_GENERAL" \
  -F "file=@$WORK/onboarding.md;type=text/markdown" -F "description=How we welcome someone new" -F "tags=HR, onboarding, hr"
R342A_FILE=$(sq "SELECT id FROM ka_internal_files WHERE name = 'onboarding.md' AND folder_id = $R342A_GENERAL")
R342A_PATH=$(sq "SELECT path FROM ka_internal_file_versions WHERE file_id = '$R342A_FILE' AND version = 1")
expect "3.42 A: an upload stores the file with its description and tags (duplicates aside) under a random name in storage/internal-files, never in media/" \
  "$(sq "SELECT CONCAT(tags, '|', status, '|', version, '|', extension, '|', size) FROM ka_internal_files WHERE id = '$R342A_FILE'")|$(test -f "$WORK/web/storage/internal-files/$R342A_PATH" && echo stored)|$(find "$WORK/web/media" -name 'onboarding*' | wc -l | tr -d ' ')" \
  "HR,onboarding|ready|1|md|$(wc -c < "$WORK/onboarding.md" | tr -d ' ')|stored|0"
printf '<?php echo "x";' > "$WORK/r342a.txt"
printf '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>' > "$WORK/r342a-page.pdf"
for f in r342a.txt r342a-page.pdf; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$TOKEN" -F "folder_id=$R342A_GENERAL" -F "file=@$WORK/$f"
done
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$TOKEN" -F "folder_id=$R342A_GENERAL" -F "file=@$WORK/onboarding.md;filename=run.php"
expect "3.42 A: PHP under .txt, HTML under .pdf and a .php name are refused" "$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE name IN ('r342a.txt', 'r342a-page.pdf', 'run.php', 'run.md')")" "0"
printf '# Onboarding R342a\n\nVersion two.\n' > "$WORK/onboarding2.md"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=replace" -F "_csrf=$TOKEN" -F "id=$R342A_FILE" -F "file=@$WORK/onboarding2.md"
expect "3.42 A: Replace adds version 2 and keeps version 1" "$(sq "SELECT version FROM ka_internal_files WHERE id = '$R342A_FILE'")|$(sq "SELECT GROUP_CONCAT(version ORDER BY version) FROM ka_internal_file_versions WHERE file_id = '$R342A_FILE'")" "2|1,2"
curl -s -b "$JAR" -c "$JAR" -D "$WORK/r342a.head" -o "$WORK/r342a.down" "$B/admin.php?module=internal_files&action=download&id=$R342A_FILE"
expect "3.42 A: the download is the current version as an attachment with its type, no-store and nosniff" \
  "$(cmp -s "$WORK/r342a.down" "$WORK/onboarding2.md" && echo same)|$(grep -ci '^content-type: text/markdown' "$WORK/r342a.head")|$(grep -ci '^content-disposition: attachment; filename="onboarding.md"' "$WORK/r342a.head")|$(grep -ci '^cache-control: no-store' "$WORK/r342a.head")|$(grep -ci '^x-content-type-options: nosniff' "$WORK/r342a.head")" \
  "same|1|1|1|1"
curl -s -b "$JAR" -c "$JAR" -D "$WORK/r342a.head" -o "$WORK/r342a.down" "$B/admin.php?module=internal_files&action=download&id=$R342A_FILE&version=1"
expect "3.42 A: an older version downloads under its own name" "$(cmp -s "$WORK/r342a.down" "$WORK/onboarding.md" && echo same)|$(grep -ci 'filename="onboarding (v1).md"' "$WORK/r342a.head")" "same|1"
expect "3.42 A: the change log notes the download by number, never the content" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'internal_files' AND akce = 'download' AND popis REGEXP '[^0-9]$R342A_FILE([^0-9]|\$)'")" "2"

# never public: not on the site, the stored file not served, signed out nothing
expect "3.42 A: the stored file is never served by the web server, and signed out the download asks for a sign-in" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$B/storage/internal-files/$R342A_PATH")|$(curl -s "$B/admin.php?module=internal_files&action=download&id=$R342A_FILE" | grep -c 'Onboarding R342a')" "403|0"
expect "3.42 A: the site's search, sitemap and llms.txt know nothing of it" \
  "$(curl -s "$B/hledani?q=Onboarding+R342a" | grep -c 'onboarding\.md\|internal_files')|$(curl -s "$B/sitemap.xml" | grep -c 'internal\|onboarding')|$(curl -s "$B/llms.txt" | grep -c 'nboarding')" "0|0|0"

# rights: no section = nothing; the section = folders for everyone; the role = its folder too
R342A_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r342a-eva', '$R342A_HASH', 'Eva R342a', 'eva-r342a@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off')" > /dev/null
R342A_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r342a-eva'")
JAR_R342A="$WORK/jar-r342a"; r342a_login "$JAR_R342A" r342a-eva
expect "3.42 A: without the section (an editor after the update) the list and the download are refused" \
  "$(curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files")|$(curl -s -b "$JAR_R342A" -c "$JAR_R342A" "$B/admin.php?module=internal_files&action=download&id=$R342A_FILE" | grep -c 'Version two')" "403|0"
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R342A_EVA, 'internal_files')" > /dev/null
printf 'board minutes R342a\n' > "$WORK/minutes.txt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$TOKEN" -F "folder_id=$R342A_BOARD" -F "file=@$WORK/minutes.txt"
R342A_MINUTES=$(sq "SELECT id FROM ka_internal_files WHERE folder_id = $R342A_BOARD AND name = 'minutes.txt'")
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o "$WORK/r342a.html" "$B/admin.php?module=internal_files"
expect "3.42 A: with the section a colleague sees the folder for everyone, not the role's folder, and no folder administration" \
  "$(grep -c 'R342a General' "$WORK/r342a.html" | sed 's/^[1-9][0-9]*$/yes/')|$(grep -c 'R342a Board\|minutes.txt' "$WORK/r342a.html")|$(grep -c 'action=folder' "$WORK/r342a.html")" "yes|0|0"
expect "3.42 A: the role's file is not found for them – detail and download alike – and they cannot create a folder" \
  "$(curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=file&id=$R342A_MINUTES")|$(curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=download&id=$R342A_MINUTES")|$(curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=folder")" \
  "404|404|403"
sq "UPDATE ka_uzivatele SET role = $R342A_ROLE WHERE idu = $R342A_EVA" > /dev/null
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o "$WORK/r342a.down" "$B/admin.php?module=internal_files&action=download&id=$R342A_MINUTES"
expect "3.42 A: a member of the role downloads the role's file" "$(cmp -s "$WORK/r342a.down" "$WORK/minutes.txt" && echo same)" "same"
R342A_ECSRF=$(r342a_csrf "$JAR_R342A")
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=delete" -d "_csrf=$R342A_ECSRF" -d "id=$R342A_FILE"
expect "3.42 A: a colleague cannot delete a file somebody else uploaded" "$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE id = '$R342A_FILE'")" "1"
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=save" -d "_csrf=$R342A_ECSRF" -d "id=$R342A_FILE" \
  -d "name=Onboarding guide" -d "description=Updated" -d "tags=HR" -d "folder_id=$R342A_GENERAL" -d status=ready
expect "3.42 A: a colleague describes a file; the name keeps its extension" "$(sq "SELECT CONCAT(name, '|', description, '|', tags, '|', updated_by) FROM ka_internal_files WHERE id = '$R342A_FILE'")" "Onboarding guide.md|Updated|HR|$R342A_EVA"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r342a.html" "$B/admin.php?module=internal_files&search=welcome"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r342a2.html" "$B/admin.php?module=internal_files&tag=HR"
expect "3.42 A: search by a word of the description and by a tag (old description replaced: Updated)" \
  "$(grep -c 'Onboarding guide.md' "$WORK/r342a.html")|$(grep -c 'Onboarding guide.md' "$WORK/r342a2.html" | sed 's/^[1-9][0-9]*$/yes/')" "0|yes"

# MCP: an administrator lists, reads a text document and writes one; drafts-only writes drafts only; no section = refused
mcp list_internal_files '{"folder":"R342a General"}' > "$WORK/response"; mcp_text
expect "3.42 A: MCP list_internal_files – the folders and the files of one, no path on disk" \
  "$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $f = $j["files"][0] ?? []; echo count($j["folders"] ?? []) >= 2 ? "folders" : "-", "|", $f["name"] ?? "-", "|", implode(",", $f["tags"] ?? []), "|", $f["version"] ?? "-", "|", str_contains((string) file_get_contents($argv[1]), ".bin") ? "path!" : "no path";' -- "$WORK/text")" \
  "folders|Onboarding guide.md|HR|2|no path"
mcp get_internal_file "{\"id\":$R342A_FILE}" > "$WORK/response"; mcp_text
expect "3.42 A: MCP get_internal_file – the text of a small text document, marked as untrusted, with its versions" \
  "$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo str_contains($j["content"] ?? "", "Version two") ? "text" : "-", "|", implode(",", $j["untrusted"] ?? []), "|", count($j["versions"] ?? []);' -- "$WORK/text")" "text|name,description,tags,versions,content|2" # 3.44 (N164 a): the metadata too
mcp save_internal_file '{"folder":"R342a General","name":"Price rules R342a","content":"# Price rules\n\nNever below cost.","tags":["sales"]}' > "$WORK/response"; mcp_text
R342A_DOC=$(sq "SELECT id FROM ka_internal_files WHERE name = 'Price rules R342a.md'")
expect "3.42 A: MCP save_internal_file creates a Markdown document (ready) that a person downloads too" \
  "$(sq "SELECT CONCAT(status, '|', extension, '|', tags) FROM ka_internal_files WHERE id = '$R342A_DOC'")|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=internal_files&action=download&id=$R342A_DOC" | grep -c 'Never below cost')" "ready|md|sales|1"
mcp save_internal_file "{\"id\":$R342A_DOC,\"content\":\"# Price rules\\n\\nNever below cost. Ask Eva.\"}" > /dev/null
expect "3.42 A: new content of a text document is a new version" "$(sq "SELECT version FROM ka_internal_files WHERE id = '$R342A_DOC'")" "2"
mcp get_internal_file '{"id":999999}' > "$WORK/response"; mcp_text
expect "3.42 A: an unknown file is refused" "$(grep -c 'does not exist' "$WORK/text")" "1"
R342A_DRAFTS="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'r342a drafts', 'drafts', 'all', SHA2('$R342A_DRAFTS', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
mcp_call "$R342A_DRAFTS" save_internal_file '{"folder":"R342a General","name":"Draft R342a.md","content":"proposal"}' > /dev/null
mcp_call "$R342A_DRAFTS" save_internal_file "{\"id\":$R342A_DOC,\"tags\":\"x\"}" > "$WORK/response"; mcp_text
expect "3.42 A: a drafts-only connection creates a draft and cannot change a ready document" \
  "$(sq "SELECT status FROM ka_internal_files WHERE name = 'Draft R342a.md'")|$(grep -c 'changes only draft' "$WORK/text")|$(sq "SELECT tags FROM ka_internal_files WHERE id = '$R342A_DOC'")" "draft|1|sales"
mcp_call "$R342A_DRAFTS" save_internal_file '{"folder":"R342a General","name":"Ready R342a.md","content":"x","status":"ready"}' > "$WORK/response"; mcp_text
expect "3.42 A: a drafts-only connection never marks a document ready" "$(grep -c 'drafts only' "$WORK/text")|$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE name = 'Ready R342a.md'")" "1|0"
R342A_READ="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'r342a read', 'read', 'all', SHA2('$R342A_READ', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
mcp_call "$R342A_READ" save_internal_file '{"folder":"R342a General","name":"Read R342a.md","content":"x"}' > /dev/null
expect "3.42 A: a read-only connection cannot write" "$(sq "SELECT COUNT(*) FROM ka_internal_files WHERE name = 'Read R342a.md'")" "0"
R342A_EVATOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R342A_EVA, 'r342a eva', 'full', 'all', SHA2('$R342A_EVATOKEN', 256), '$(site_time)');
  UPDATE ka_uzivatele SET role = NULL WHERE idu = $R342A_EVA" > /dev/null
mcp_call "$R342A_EVATOKEN" get_internal_file "{\"id\":$R342A_MINUTES}" > "$WORK/response"; mcp_text
expect "3.42 A: over MCP a colleague outside the role does not get the role's file" "$(grep -c 'board minutes' "$WORK/text")|$(grep -c 'does not exist' "$WORK/text")" "0|1"
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $R342A_EVA AND ident_modulu = 'internal_files'" > /dev/null
mcp_call "$R342A_EVATOKEN" list_internal_files '{}' > "$WORK/response"; mcp_text
expect "3.42 A: over MCP without the section – refused" "$(grep -c 'Only a user who may open Internal files' "$WORK/text")" "1"

# a folder with files is not deleted; deleting a file removes its versions on disk
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_delete" -d "_csrf=$TOKEN" -d "id=$R342A_GENERAL"
expect "3.42 A: a folder that holds files is not deleted" "$(sq "SELECT COUNT(*) FROM ka_internal_folders WHERE id = $R342A_GENERAL")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=delete" -d "_csrf=$TOKEN" -d "id=$R342A_FILE"
expect "3.42 A: an administrator deletes a file with every version, on disk too" \
  "$(sq "SELECT COUNT(*) FROM ka_internal_file_versions WHERE file_id = '$R342A_FILE'")|$(test -f "$WORK/web/storage/internal-files/$R342A_PATH" && echo left || echo gone)" "0|gone"

for R342A_ID in $(sq "SELECT id FROM ka_internal_files WHERE folder_id IN ($R342A_GENERAL, $R342A_BOARD)"); do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=delete" -d "_csrf=$TOKEN" -d "id=$R342A_ID"
done
for R342A_ID in $R342A_GENERAL $R342A_BOARD; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_delete" -d "_csrf=$TOKEN" -d "id=$R342A_ID"
done
expect "3.42 A: empty folders are deleted" "$(sq "SELECT COUNT(*) FROM ka_internal_folders WHERE name LIKE 'R342a%'")|$(sq "SELECT COUNT(*) FROM ka_internal_files")" "0|0"
# 3.42 wiring: a folder for chosen departments – members see it, members of a department below see it, others do not
R342A_DEPT=$(sq "INSERT INTO ka_departments (name, created_at, updated_at) VALUES ('R342a Sales', NOW(), NOW()); SELECT LAST_INSERT_ID();")
R342A_SUBDEPT=$(sq "INSERT INTO ka_departments (name, parent_id, created_at, updated_at) VALUES ('R342a Export', $R342A_DEPT, NOW(), NOW()); SELECT LAST_INSERT_ID();")
R342A_OTHERDEPT=$(sq "INSERT INTO ka_departments (name, created_at, updated_at) VALUES ('R342a Support', NOW(), NOW()); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=R342a Sales docs" -d audience=depts -d "departments[]=$R342A_DEPT" -d "roles[]=$R342A_ROLE"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$TOKEN" -d "name=R342a No departments" -d audience=depts -d "departments[]=999999"
R342A_SALES=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R342a Sales docs'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/r342a.html" "$B/admin.php?module=internal_files&action=folder&id=$R342A_SALES"
expect "3.42: a folder for departments stores only the chosen known departments, needs one, and the form shows them ticked" \
  "$(sq "SELECT CONCAT(audience, ':', roles) FROM ka_internal_folders WHERE id = '$R342A_SALES'")|$(sq "SELECT COUNT(*) FROM ka_internal_folders WHERE name = 'R342a No departments'")|$(grep -c "name=\"departments\[\]\" value=\"$R342A_DEPT\" checked" "$WORK/r342a.html")|$(grep -c "value=\"depts\" checked" "$WORK/r342a.html")" \
  "depts:d$R342A_DEPT|0|1|1"
printf 'sales targets R342a\n' > "$WORK/targets.txt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$TOKEN" -F "folder_id=$R342A_SALES" -F "file=@$WORK/targets.txt"
R342A_TARGETS=$(sq "SELECT id FROM ka_internal_files WHERE folder_id = '$R342A_SALES' AND name = 'targets.txt'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R342A_EVA, 'internal_files'); UPDATE ka_uzivatele SET role = $R342A_ROLE, department_id = $R342A_OTHERDEPT WHERE idu = $R342A_EVA" > /dev/null
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o "$WORK/r342a.html" "$B/admin.php?module=internal_files"
expect "3.42: a colleague of another department (even with a role the old audience knew) does not see the folder or its file" \
  "$(grep -c 'R342a Sales docs' "$WORK/r342a.html")|$(curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o /dev/null -w '%{http_code}' "$B/admin.php?module=internal_files&action=download&id=$R342A_TARGETS")" "0|404"
sq "UPDATE ka_uzivatele SET department_id = $R342A_DEPT WHERE idu = $R342A_EVA" > /dev/null
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o "$WORK/r342a.down" "$B/admin.php?module=internal_files&action=download&id=$R342A_TARGETS"
expect "3.42: a member of the chosen department downloads the file" "$(cmp -s "$WORK/r342a.down" "$WORK/targets.txt" && echo same)" "same"
sq "UPDATE ka_uzivatele SET role = NULL, department_id = $R342A_SUBDEPT WHERE idu = $R342A_EVA" > /dev/null
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o "$WORK/r342a.html" "$B/admin.php?module=internal_files"
curl -s -b "$JAR_R342A" -c "$JAR_R342A" -o "$WORK/r342a.down" "$B/admin.php?module=internal_files&action=download&id=$R342A_TARGETS"
mcp_call "$R342A_EVATOKEN" list_internal_files '{"folder":"R342a Sales docs"}' > "$WORK/response"; mcp_text
expect "3.42: a member of a department below the chosen one sees the folder (marked departments only), downloads the file, and MCP lists it" \
  "$(grep -c 'R342a Sales docs' "$WORK/r342a.html" | sed 's/^[1-9][0-9]*$/yes/')|$(grep -c 'departments only' "$WORK/r342a.html" | sed 's/^[1-9][0-9]*$/yes/')|$(cmp -s "$WORK/r342a.down" "$WORK/targets.txt" && echo same)|$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $f = array_column($j["folders"] ?? [], "audience", "name"); echo $f["R342a Sales docs"] ?? "-", "|", $j["files"][0]["name"] ?? "-";' -- "$WORK/text")" \
  "yes|yes|same|depts|targets.txt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=delete" -d "_csrf=$TOKEN" -d "id=$R342A_TARGETS"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_delete" -d "_csrf=$TOKEN" -d "id=$R342A_SALES"
sq "UPDATE ka_uzivatele SET department_id = NULL WHERE idu = $R342A_EVA; DELETE FROM ka_departments WHERE id IN ($R342A_DEPT, $R342A_SUBDEPT, $R342A_OTHERDEPT)" > /dev/null
expect "3.42: the department folder is deleted" "$(sq "SELECT COUNT(*) FROM ka_internal_folders WHERE name LIKE 'R342a%'")" "0"
sq "DELETE FROM ka_api_tokeny WHERE nazev LIKE 'r342a %'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $R342A_EVA; DELETE FROM ka_uzivatele WHERE idu = $R342A_EVA; DELETE FROM ka_role WHERE idr = $R342A_ROLE" > /dev/null
