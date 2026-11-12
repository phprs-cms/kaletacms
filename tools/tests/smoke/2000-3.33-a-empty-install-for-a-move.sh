echo "== 3.33 part A: an empty install for a move, the starter pages record"
# The sections 2000–2040 share a second site (web333, the same copy of the project) for the move: installed empty for a
# move, then installed again with the starter site, imported into, reported on. 2040 stops it and drops its database.
S3_PORT=$((PORT + 5)); S3="http://127.0.0.1:$S3_PORT"; S3_DB="${DB_NAME}_s333"; S3_JAR="$WORK/cookies-s333.txt"
S3_TOKEN="kaleta_$(printf 'c%.0s' $(seq 1 48))"
s3q() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$S3_DB" -N -e "$1"; }
s3mcp() { curl -s -X POST "$S3/mcp" -H "Authorization: Bearer $S3_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
s3_install() { # $1 = the starter choice (move | firemni)
  [ -z "${S3_PID:-}" ] || { kill "$S3_PID" 2>/dev/null || true; wait "$S3_PID" 2>/dev/null || true; }
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$S3_DB\`; CREATE DATABASE \`$S3_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
  rm -f "$WORK/web333/config.php" "$S3_JAR"; cp "$ROOT/install.php" "$WORK/web333/install.php"
  (cd "$WORK/web333" && KALETA_IMPORT_LOCAL=1 exec php -S "127.0.0.1:$S3_PORT" system/dev-router.php >> "$WORK/server333.log" 2>&1) & S3_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$S3/install.php" && break; sleep 0.2; done
  curl -s -o "$WORK/response" -X POST "$S3/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$S3_DB" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
    --data-urlencode "nazev_webu=Stěhování 333" -d "web=$1" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
    -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=presmerovani' -d 'rozsireni[]=claude'
  cp "$WORK/response" "$WORK/install333.html"; test_seams "$WORK/web333" # T4: KALETA_IMPORT_LOCAL counts only on a test copy
  s3q "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$S3_TOKEN")', NOW() FROM ka_uzivatele WHERE user = 'admin'"
  curl -s -c "$S3_JAR" -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php"
  curl -s -b "$S3_JAR" -c "$S3_JAR" -o /dev/null -X POST "$S3/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
}
s3_field() { php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "{}", true); foreach (array_slice($argv, 2) as $k) { $r = is_array($r) ? ($r[$k] ?? null) : null; } echo is_bool($r) ? ($r ? "true" : "false") : (is_scalar($r) ? $r : json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));' "$WORK/response" "$@"; }
s3_record() { s3q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'starter_pages'" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_array($r) ? implode(",", array_keys($r)) : "none";'; }
mkdir "$WORK/web333" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web333")
mkdir -p "$WORK/web333/media" "$WORK/web333/storage/log" "$WORK/web333/storage/cache"

# the installer offers the empty site for a move, and says what it holds
s3_install move
grep -q 'Web je připravený na stěhování' "$WORK/install333.html" && grep -q 'Pokračovat importem' "$WORK/install333.html" \
  && echo "  ok     3.33 M2: the installer's empty site for a move ends with the next step – the import" || { echo "  CHYBA  3.33 M2: install with web=move"; sed 's/<[^>]*>//g' "$WORK/install333.html" | grep -v '^\s*$' | head -10; ERRORS=$((ERRORS+1)); }
expect "3.33 M2: an empty install holds only the Home placeholder (shown, the home page) and the hidden privacy policy – no news, no category" \
  "$(s3q "SELECT CONCAT((SELECT GROUP_CONCAT(CONCAT(seo_link, ':', zobrazit) ORDER BY ids) FROM ka_stranky), '|', (SELECT COUNT(*) FROM ka_novinky), '|', (SELECT COUNT(*) FROM ka_kategorie), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page') = (SELECT MIN(ids) FROM ka_stranky))")" \
  "uvod:1,zasady-ochrany-osobnich-udaju:0|0|0|1"
expect "3.33 M2: the installer records the Home placeholder (not the privacy policy) as a starter page" "$(s3_record)" "$(s3q "SELECT MIN(ids) FROM ka_stranky")"
curl -s -o "$WORK/response" "$S3/"; contains -q 'Web se sem právě stěhuje' "$WORK/response" && echo "  ok     3.33 M2: the home page of a site being moved says so" || { echo "  CHYBA  3.33 M2: the placeholder home page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer"
! contains -q 'id="sample-pages"' "$WORK/response" && echo "  ok     3.33 M2: an empty install offers no sample pages to remove (the Home placeholder stays)" || { echo "  CHYBA  3.33 M2: sample pages panel on an empty install"; ERRORS=$((ERRORS+1)); }

# the starter site: four starter pages recorded; an existing fresh site removes them in Import and export
s3_install firemni
expect "3.33 M2: the starter site records its four starter pages" "$(s3_record)" "$(s3q "SELECT GROUP_CONCAT(ids ORDER BY ids) FROM ka_stranky WHERE seo_link IN ('uvod', 'o-nas', 'sluzby', 'kontakt')")"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer"
contains -q 'id="sample-pages"' "$WORK/response" && contains -q 'O nás (/o-nas)' "$WORK/response" && ! contains -q 'Úvod (/uvod)' "$WORK/response" \
  && echo "  ok     3.33 M2: Import and export lists the untouched sample pages (without Home)" || { echo "  CHYBA  3.33 M2: the sample pages panel"; ERRORS=$((ERRORS+1)); }
# an edit by a person (the starter's zmenil stamp) makes a page edited – it is never offered again
SERVICES333=$(s3q "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby'")
s3mcp update_page "{\"id\":$SERVICES333,\"description\":\"Naše služby\"}" > "$WORK/response"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=transfer"
! contains -q 'Služby (/sluzby)' "$WORK/response" && echo "  ok     3.33 M2: a sample page someone edited is no longer a sample page" || { echo "  CHYBA  3.33 M2: an edited starter page still listed"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf); curl -s -b "$S3_JAR" -c "$S3_JAR" -o /dev/null -X POST "$S3/admin.php?module=transfer&action=starter_remove" -d "_csrf=$TOKEN"
expect "3.33 M2: Move the sample pages to the trash – the untouched ones go to the trash under a free address, Home and the edited page stay (the edited one stays in the record, never replaced)" \
  "$(s3q "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', smazano IS NOT NULL) ORDER BY ids) FROM ka_stranky WHERE ids <= (SELECT MIN(ids) + 3 FROM ka_stranky)")|$(s3_record)" \
  "uvod:0,o-nas-sample:1,sluzby:0,kontakt-sample:1|$(s3q "SELECT GROUP_CONCAT(ids ORDER BY ids) FROM ka_stranky WHERE seo_link IN ('uvod', 'sluzby')")"
# a site installed before 3.33 has no record: the installer's titles and addresses decide (3.20–3.32 stamped zmeneno only)
s3q "DELETE FROM ka_nastaveni WHERE promenna = 'starter_pages'"
expect "3.33 M2: without the record (a site from 3.20–3.32) the untouched Home is still recognised, the edited Services is not" \
  "$(cd "$WORK/web333" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo implode(",", array_column(Kaleta\Core\StarterPages::untouched($app->db(), $app->settings()), "seo_link"));')" "uvod"
s3_install firemni # the import sections start from a fresh starter site
