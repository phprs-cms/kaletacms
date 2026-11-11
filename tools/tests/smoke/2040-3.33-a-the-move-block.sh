echo "== 3.33 part A: the Move block of Site audit and site_audit – where a move stands"
# the second site after 2030: five pages imported (hidden), one of them reworked, the sample pages replaced; a new report
(cd "$WORK/old333" && exec php -S "127.0.0.1:$OLD333_PORT" > /dev/null 2>&1) & OLD333_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD333/" && break; sleep 0.3; done
s3_report "$OLD333"
s3mcp site_audit '{"kind":"handover"}' > "$WORK/response"
expect "3.33 M7 site_audit move: pages imported and published, not reworked, sample pages left, the newest report – counts only" \
  "$(s3_field move pages_imported)|$(s3_field move pages_published)|$(s3_field move pages_not_reworked)|$(s3_field move sample_pages)|$(s3_field move report forms_missing)|$(s3_field move report site_wide_forms)|$(s3_field move report clean)" \
  "5|0|4|0|1|1|false" # the imported contact page lost its form (2040: the old contact form), the newsletter is the site-wide one
expect "3.33 M7 site_audit move rows: done and not done, each with its link" \
  "$(s3_field move rows | php -r 'echo implode(",", array_map(fn ($r) => $r["key"] . ":" . ($r["done"] ? 1 : 0) . (str_contains($r["edit"], "admin.php?module=") ? "" : ":nolink"), json_decode(stream_get_contents(STDIN), true)));')" \
  "move_imported:1,move_layouts:0,move_samples:1,move_redirects:1,move_forms:0,move_report:0"
curl -s -b "$S3_JAR" -o "$WORK/response" "$S3/admin.php?module=audit"
contains -q 'id="move"' "$WORK/response" && contains -q 'data-move-radek="move_layouts"' "$WORK/response" && contains -q 'Importované stránky: 5 (zveřejněné: 0), novinky: 0' "$WORK/response" \
  && echo "  ok     3.33 M7: Site audit shows the Move block above the hand-over checklist" || { echo "  CHYBA  3.33 M7: the Move block in Site audit"; ERRORS=$((ERRORS+1)); }
# a fresh site has no move: no block, no key
s3_install move
rm -f "$WORK"/web333/storage/import/parita-*.json
s3mcp site_audit '{"kind":"handover"}' > "$WORK/response"
expect "3.33 M7: a site without imported pages or a migration report has no Move block" "$(s3_field move)" "null"
kill "$OLD333_PID" 2>/dev/null || true; OLD333_PID=
# the end of the second site of 2000–2040
[ -s "$WORK/web333/storage/log/chyby.log" ] && { echo "  CHYBA  errors on the second site:"; cat "$WORK/web333/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); }
kill "$S3_PID" 2>/dev/null || true; wait "$S3_PID" 2>/dev/null || true; S3_PID=; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$S3_DB\`"
