echo "== 3.42 review: internal files – a move to another audience, two replacements at once"
r342g_login() { # r342g_login <jar> <user>
  "${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'"
  rm -f "$1"; local c; c=$(curl -s -c "$1" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$c" -d "user=$2" --data-urlencode "password=$PASSWORD"; }
r342g_csrf() { curl -s -b "$1" -c "$1" "$B/admin.php?module=internal_files" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
R342G_TOKEN=$(r342g_csrf "$JAR")
R342G_ROLE=$(sq "INSERT INTO ka_role (nazev, popis, uroven, moduly) VALUES ('R342g board', '', 1, 'internal_files'); SELECT LAST_INSERT_ID();")
for r342g_folder in "R342g Board|roles" "R342g Board two|roles" "R342g General|all"; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_save" -d "_csrf=$R342G_TOKEN" --data-urlencode "name=${r342g_folder%%|*}" \
    -d "audience=${r342g_folder##*|}" -d "roles[]=$R342G_ROLE"
done
R342G_BOARD=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R342g Board'"); R342G_BOARD2=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R342g Board two'")
R342G_GENERAL=$(sq "SELECT id FROM ka_internal_folders WHERE name = 'R342g General'")
R342G_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, role, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('r342g-eva', '$R342G_HASH', 'Eva R342g', 'eva-r342g@example.com', 'en', 1, $R342G_ROLE, '$(site_time)', '$(site_time)', 0, 'off')" > /dev/null
R342G_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'r342g-eva'")
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($R342G_EVA, 'internal_files')" > /dev/null
JAR_R342G="$WORK/jar-r342g"; r342g_login "$JAR_R342G" r342g-eva; R342G_ECSRF=$(r342g_csrf "$JAR_R342G")
printf 'board minutes R342g\n' > "$WORK/r342g-minutes.txt"
curl -s -b "$JAR_R342G" -c "$JAR_R342G" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=upload" -F "_csrf=$R342G_ECSRF" -F "folder_id=$R342G_BOARD" -F "file=@$WORK/r342g-minutes.txt"
R342G_FILE=$(sq "SELECT id FROM ka_internal_files WHERE folder_id = $R342G_BOARD AND name = 'r342g-minutes.txt'"); R342G_FILE="${R342G_FILE:-0}"
r342g_move() { curl -s -b "$1" -c "$1" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=save" -d "_csrf=$2" -d "id=$R342G_FILE" -d "name=r342g-minutes.txt" -d description= -d tags= -d "folder_id=$3" -d status=ready; }
r342g_move "$JAR_R342G" "$R342G_ECSRF" "$R342G_GENERAL"
expect "3.42 review M3: a colleague who sees both folders cannot move a role's file into the folder for everyone" "$(sq "SELECT folder_id FROM ka_internal_files WHERE id = $R342G_FILE")" "$R342G_BOARD"
R342G_EVATOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R342G_EVA, 'r342g eva', 'full', 'all', SHA2('$R342G_EVATOKEN', 256), '$(site_time)')" > /dev/null
mcp_call "$R342G_EVATOKEN" save_internal_file "{\"id\":$R342G_FILE,\"folder\":\"R342g General\"}" > "$WORK/response"; mcp_text
expect "3.42 review M3: over MCP the same move is refused in English" \
  "$(grep -c 'Only an administrator can move a file to a folder with other people in its audience.' "$WORK/text")|$(sq "SELECT folder_id FROM ka_internal_files WHERE id = $R342G_FILE")" "1|$R342G_BOARD"
r342g_move "$JAR_R342G" "$R342G_ECSRF" "$R342G_BOARD2"
expect "3.42 review M3: a move to a folder with the same audience stays open to the colleague" "$(sq "SELECT folder_id FROM ka_internal_files WHERE id = $R342G_FILE")" "$R342G_BOARD2"
r342g_move "$JAR" "$R342G_TOKEN" "$R342G_GENERAL"
expect "3.42 review M3: an administrator moves it to the folder for everyone" "$(sq "SELECT folder_id FROM ka_internal_files WHERE id = $R342G_FILE")" "$R342G_GENERAL"

# two people replace the same file at once: every replacement gets its own version, and no stored bytes without a version
cat > "$WORK/r342g-replace.php" <<'PHP'
<?php
require 'system/bootstrap.php';
$app = new Kaleta\Core\App(require 'config.php');
$app->applyTimezone();
$app->auth()->signInAs($app->db()->one('SELECT * FROM {uzivatele} WHERE idu = ?', [(int) $argv[2]]));
$failed = 0;
for ($i = 1; $i <= 5; $i++) {
    $temp = (string) tempnam(sys_get_temp_dir(), 'r342g');
    file_put_contents($temp, "replacement $argv[3] $i\n");
    try {
        $saved = Kaleta\Core\InternalFiles::replace($app, (int) $argv[1], $temp, 'r342g-minutes.txt');
        $failed += is_array($saved) ? 0 : 1;
    } catch (Throwable) {
        $failed++;
    }
    @unlink($temp);
}
echo $failed;
PHP
R342G_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R342G_PIDS=""
for r342g_n in 1 2 3 4; do (cd "$WORK/web" && php "$WORK/r342g-replace.php" "$R342G_FILE" "$R342G_ADMIN" "$r342g_n" > "$WORK/r342g-replace-$r342g_n.out" 2>&1) & R342G_PIDS="$R342G_PIDS $!"; done
for r342g_pid in $R342G_PIDS; do wait "$r342g_pid"; done # only these four – a bare wait would wait for the site's servers too
R342G_STORED=0
for r342g_path in $(sq "SELECT path FROM ka_internal_file_versions WHERE file_id = $R342G_FILE"); do [ -f "$WORK/web/storage/internal-files/$r342g_path" ] && R342G_STORED=$((R342G_STORED+1)); done
expect "3.42 review L5: four people replacing a file five times each at once – no failure, versions 1 to 21 without a gap, each with its bytes, no stray file on disk" \
  "$(cat "$WORK"/r342g-replace-*.out | tr -d '\n')|$(sq "SELECT CONCAT(COUNT(*), ':', COUNT(DISTINCT version), ':', MAX(version)) FROM ka_internal_file_versions WHERE file_id = $R342G_FILE")|$(sq "SELECT version FROM ka_internal_files WHERE id = $R342G_FILE")|$R342G_STORED|$(( $(find "$WORK/web/storage/internal-files" -type f -name '*.bin' | wc -l | tr -d ' ') - $(sq "SELECT COUNT(*) FROM ka_internal_file_versions") ))" \
  "0000|21:21:21|21|21|0"

curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=delete" -d "_csrf=$R342G_TOKEN" -d "id=$R342G_FILE"
for R342G_ID in $R342G_BOARD $R342G_BOARD2 $R342G_GENERAL; do
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=internal_files&action=folder_delete" -d "_csrf=$R342G_TOKEN" -d "id=$R342G_ID"
done
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'r342g eva'; DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $R342G_EVA; DELETE FROM ka_uzivatele WHERE idu = $R342G_EVA; DELETE FROM ka_role WHERE idr = $R342G_ROLE" > /dev/null
expect "3.42 review: the folders of this section are gone" "$(sq "SELECT COUNT(*) FROM ka_internal_folders WHERE name LIKE 'R342g%'")" "0"
