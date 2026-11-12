echo "== 3.14: normalised redirect sources (the background job, System status, get_health)"
# two redirects whose old addresses differ only in letter case and a slash – the unique key of today's collation keeps both,
# the exact comparison (3.19) does not; System status lists the group (Health), get_health has no Ready for 4.0 since 3.39
sq "INSERT INTO ka_presmerovani (z_adresy, na_adresu, vytvoreno) VALUES ('R314-Stara/', 'kontakt', '$(site_time)'), ('r314-stara', 'kontakt', '$(site_time)')" > /dev/null
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "3.14: the background job normalises the new redirect sources (lower case, no slashes, utf8mb4_bin)" \
  "$(grep -c 'redirect_sources: normalised' "$WORK/tasks.txt")|$(sq "SELECT GROUP_CONCAT(source_norm ORDER BY idp SEPARATOR ',') FROM ka_presmerovani WHERE z_adresy LIKE 'r314-stara%'")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE source_norm IS NULL")" \
  "1|r314-stara,r314-stara|0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=status"
expect "3.39: System status lists the colliding redirects and has no Ready for 4.0 section" \
  "$(contains -F '/R314-Stara/ = /r314-stara' "$WORK/response" && echo redirects)|$(grep -c 'id="ready-4"\|Připraveno na 4.0' "$WORK/response" || true)" "redirects|0"
mcp get_health '{}' > "$WORK/response"
expect "3.39: get_health keeps its keys, reports the redirect group as a problem and no longer has ready_for_4_0" \
  "$(mcp_value status | grep -cE '^(ok|warning|error)$')|$(grep -c 'Old addresses of redirects' "$WORK/response")|$(grep -c 'ready_for_4_0' "$WORK/response" || true)" "1|1|0"
# N314-2: editing the old address of a redirect normalises it again (lookups read source_norm since 3.19)
R314ID=$(sq "SELECT idp FROM ka_presmerovani WHERE z_adresy = 'r314-stara'"); R314ID="${R314ID:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d idp=$R314ID -d z_adresy=/r314-Nova -d na_adresu=/kontakt -d typ=301
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "3.14: an edited old address is normalised again (N314-2)" "$(sq "SELECT CONCAT(z_adresy, '|', IFNULL(source_norm, 'NULL')) FROM ka_presmerovani WHERE idp = $R314ID")" "r314-Nova|r314-nova"
sq "DELETE FROM ka_presmerovani WHERE z_adresy LIKE 'r314-stara%' OR idp = $R314ID" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=addons&action=toggle" -d "_csrf=$TOKEN" -d slug=hello -d on=0
expect "3.19.1 (N87): switching it off is in the change log and the events, with its slug" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'addons' AND akce = 'disabled' AND popis LIKE '%(hello%'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'addon.disabled' AND data LIKE '%\"addon\":\"hello\"%'")" "1|1"
rm -f "$WORK"/web/storage/cache/stranky/*.html
expect "add-ons: switched off, the token is left as it was written and the tool is gone" "$(curl -s "$B/addon-page" | grep -c '{{ext.hello.greeting')|$(mcp ext_hello_greet '{}' | grep -c 'isError')" "1|1"
rm -rf "$WORK/web/extensions"
