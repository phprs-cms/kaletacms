echo "== 3.14: Ready for 4.0 (System status, get_health)"
# an add-on that writes the old placeholder {novinky} (in its file and while running), and two redirects whose old addresses
# differ only in letter case and a slash – the unique key of today's collation keeps both, an exact comparison would not
mkdir -p "$WORK/web/extensions/legacy314" && printf '%s' '{"name":"Legacy 314","class":"Legacy314\\Ext","requires":{"api":1}}' > "$WORK/web/extensions/legacy314/extension.json"
cat > "$WORK/web/extensions/legacy314/Extension.php" <<'PHP'
<?php namespace Legacy314; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void {
    $api->app()->db()->value('SELECT COUNT(*) FROM {novinky}');
} }
PHP
rm -f "$WORK/web/storage/log/legacy-table-names.json"
sq "UPDATE ka_nastaveni SET hodnota = 'hello,legacy314' WHERE promenna = 'addons_enabled';
  INSERT INTO ka_presmerovani (z_adresy, na_adresu, vytvoreno) VALUES ('R314-Stara/', 'kontakt', '$(site_time)'), ('r314-stara', 'kontakt', '$(site_time)')" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "3.14: the background job normalises the new redirect sources (lower case, no slashes, utf8mb4_bin)" \
  "$(grep -c 'redirect_sources: normalised' "$WORK/tasks.txt")|$(sq "SELECT GROUP_CONCAT(source_norm ORDER BY idp SEPARATOR ',') FROM ka_presmerovani WHERE z_adresy LIKE 'r314-stara%'")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE source_norm IS NULL")" \
  "1|r314-stara,r314-stara|0"
expect "3.14: the deprecation log names the add-on file, never Kaleta's own code" \
  "$(grep -c 'extensions/legacy314/Extension.php' "$WORK/web/storage/log/legacy-table-names.json" 2>/dev/null)|$(grep -c '"system/\|"tools/\|"index.php\|"admin.php' "$WORK/web/storage/log/legacy-table-names.json" 2>/dev/null || true)" "1|0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.14: System status has Ready for 4.0 with the add-on file (scan and while running) and the colliding redirects" \
  "$(contains -F 'Připraveno na 4.0' "$WORK/response" && echo section)|$(contains -F 'extensions/legacy314/Extension.php – 1× starý zástupný název ({novinky})' "$WORK/response" && echo scan)|$(contains -F 'doplněk legacy314: extensions/legacy314/Extension.php za běhu použil {novinky}' "$WORK/response" && echo log)|$(contains -F '/R314-Stara/ = /r314-stara' "$WORK/response" && echo redirects)|$(contains -F 'Databázový server' "$WORK/response" && echo server)" \
  "section|scan|log|redirects|server"
mcp get_health '{}' > "$WORK/response"
GH314="$(mcp_value ready_for_4_0 checks)"
expect "3.14: get_health keeps its keys and adds ready_for_4_0 with the add-on file and the redirect group" \
  "$(mcp_value status | grep -cE '^(ok|warning|error)$')|$(mcp_value ready_for_4_0 status)|$(printf '%s' "$GH314" | grep -c '"check":"legacy_table_names","name":"Add-ons and old table names","status":"warning"')|$(printf '%s' "$GH314" | grep -c 'extensions/legacy314/Extension.php')|$(printf '%s' "$GH314" | grep -c '/R314-Stara/ = /r314-stara')|$(printf '%s' "$GH314" | grep -c '"check":"database_version"')" \
  "1|warning|1|1|1|1"
# N314-2: editing the old address of a redirect normalises it again (in 4.0 the lookup reads source_norm)
R314ID=$(sq "SELECT idp FROM ka_presmerovani WHERE z_adresy = 'r314-stara'"); R314ID="${R314ID:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d idp=$R314ID -d z_adresy=/r314-Nova -d na_adresu=/kontakt -d typ=301
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.14: an edited old address is normalised again (N314-2)" "$(sq "SELECT CONCAT(z_adresy, '|', IFNULL(source_norm, 'NULL')) FROM ka_presmerovani WHERE idp = $R314ID")" "r314-Nova|r314-nova"
sq "DELETE FROM ka_presmerovani WHERE z_adresy LIKE 'r314-stara%' OR idp = $R314ID; UPDATE ka_nastaveni SET hodnota = 'hello' WHERE promenna = 'addons_enabled'" > /dev/null
rm -rf "$WORK/web/extensions/legacy314"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=0
expect "3.19.1 (N87): switching it off is in the change log and the events, with its slug" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'addons' AND akce = 'disabled' AND popis LIKE '%(hello%'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'addon.disabled' AND data LIKE '%\"addon\":\"hello\"%'")" "1|1"
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "add-ons: switched off, the token is left as it was written and the tool is gone" "$(curl -s "$B/addon-page" | grep -c '{{ext.hello.greeting')|$(mcp ext_hello_greet '{}' | grep -c 'isError')" "1|1"
rm -rf "$WORK/web/extensions"
