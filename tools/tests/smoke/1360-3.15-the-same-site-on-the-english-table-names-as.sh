echo "== 3.15: the same site on the English table names (as 4.0 renames them) and back – the rollback target of 4.0"
# tools/rename-tables.php renames every table of names.php in one RENAME TABLE; the site is not told – it must notice by
# itself (Core\Db: a missing table, one look at information_schema, a marker in storage/cache), as after a rollback from 4.0
renamed_pass() { # <label> <settings table> <pages table>
  local l="$1" settings="ka_$2" pages="ka_$3" jar="$WORK/jar-names-$3" t code
  rm -f "$WORK"/web/storage/cache/stranky/*.html
  check "$l: home page" 200 "/"
  check "$l: a page" 200 "/kontakty"
  check "$l: news" 200 "/novinky"
  check "$l: sitemap" 200 "/sitemap.xml"
  rm -f "$jar"; curl -s -c "$jar" -b "$jar" -o "$WORK/response" "$B/admin.php"; t=$(csrf)
  code=$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d user=admin --data-urlencode "password=$PASSWORD")
  expect "$l: sign-in with a new session" "$code|$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$B/admin.php")" "302|200"
  check "$l: admin dashboard" 200 "/admin.php"
  for m in pages news collections menu redirects media users; do check "$l: admin $m" 200 "/admin.php?module=$m"; done
  check "$l: Settings → General" 200 "/admin.php?module=settings&tab=general"
  check "$l: System status with Ready for 4.0" 200 "/admin.php?module=status" 'ready-4'
  mcp list_collections '{}' > "$WORK/response"; contains -F '"result"' "$WORK/response" && ! contains -F '"isError":true' "$WORK/response" && echo "  ok     $l: MCP list_collections" || { echo "  CHYBA  $l: MCP list_collections"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
  mcp create_page "{\"title\":\"Names $3\"}" > "$WORK/response"
  expect "$l: MCP create_page writes into $pages" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM $pages WHERE titulek = 'Names $3'")" "1"
  mcp list_pages '{}' > "$WORK/response"; contains -F "Names $3" "$WORK/response" && echo "  ok     $l: MCP list_pages reads it back" || { echo "  CHYBA  $l: MCP list_pages"; ERRORS=$((ERRORS+1)); }
  # the last two migrations once more: their files name ka_stitky and ka_uzivatele – on renamed tables they must find ka_tags
  # and ka_users (and skip what is already there), not stop the admin with a missing table
  "${MYSQL[@]}" "$DB_NAME" -e "UPDATE $settings SET hodnota = '$((LAST_MIGRATION - 2))' WHERE promenna = 'db_version'"
  check "$l: the admin runs pending migrations" 200 "/admin.php"
  ! contains -F 'hlaska-chyba' "$WORK/response" && expect "$l: migrations ran on these tables" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM $settings WHERE promenna = 'db_version'")" "$LAST_MIGRATION" \
    || { echo "  CHYBA  $l: a migration failed"; ERRORS=$((ERRORS+1)); }
}
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
expect "3.15: rename to the English names (one RENAME TABLE)" "$(php "$ROOT/tools/rename-tables.php" "$WORK/web" english | sed 's/ tables.*//')|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('ka_novinky', 'ka_news', 'ka_nastaveni', 'ka_settings')")" \
  "$(php -r '$n = 0; foreach (require $argv[1] as $t => [$e]) { $n += $e !== $t ? 1 : 0; } echo $n;' -- "$ROOT/system/sql/names.php")|2"
renamed_pass "3.15 English table names" settings pages
mcp get_health '{}' > "$WORK/response"
contains -F 'table_names' "$WORK/response" && contains -F 'already have their English names' "$WORK/response" && echo "  ok     3.15: Ready for 4.0 (get_health) says the tables have their English names" || { echo "  CHYBA  3.15: table_names in get_health"; ERRORS=$((ERRORS+1)); }
expect "3.15: the site remembers the renamed tables (the marker in storage/cache)" "$(ls "$WORK"/web/storage/cache/tables-english-* 2>/dev/null | wc -l | tr -d ' ')" "1"
expect "3.15: rename back to today's names" "$(php "$ROOT/tools/rename-tables.php" "$WORK/web" today | sed 's/ tables.*//')" "$(php -r '$n = 0; foreach (require $argv[1] as $t => [$e]) { $n += $e !== $t ? 1 : 0; } echo $n;' -- "$ROOT/system/sql/names.php")"
renamed_pass "3.15 today's table names again" nastaveni stranky
expect "3.15: back on today's names the marker is gone and both pages are there" "$(ls "$WORK"/web/storage/cache/tables-english-* 2>/dev/null | wc -l | tr -d ' ')|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek IN ('Names pages', 'Names stranky')")" "0|2"

