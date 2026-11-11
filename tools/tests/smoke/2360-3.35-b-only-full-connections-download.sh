echo "== 3.35 B3 (N104): only a full connection makes the site download an address"
# A drafts-only or read-only connection never makes the site fetch an address it names: upload_file takes data but no url,
# save_collection_items downloads no media (a file downloaded before is still reused), migration_report and read_old_page
# are not listed. A full connection keeps today's behaviour and limits.
S335C_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
S335C_DRAFTS="kaleta_$(openssl rand -hex 24)"; S335C_READ="kaleta_$(openssl rand -hex 24)"; S335C_FULL="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($S335C_ADMIN, 's335c drafts', 'drafts', 'all', SHA2('$S335C_DRAFTS', 256), '$(site_time)'),
  ($S335C_ADMIN, 's335c read', 'read', 'all', SHA2('$S335C_READ', 256), '$(site_time)'), ($S335C_ADMIN, 's335c full', 'full', 'all', SHA2('$S335C_FULL', 256), '$(site_time)')" > /dev/null
s335c_mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
s335c_list() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -o '"name":"\(migration_report\|read_old_page\|upload_file\)"' | sort | tr -d '"' | sed 's/name://' | tr '\n' ',' ; }
expect "3.35 B3 N104: tools/list – a full connection lists the tools that read other sites, drafts and read do not" \
  "$(s335c_list "$S335C_FULL")|$(s335c_list "$S335C_DRAFTS")|$(s335c_list "$S335C_READ")" "migration_report,read_old_page,upload_file,|upload_file,|"
expect "3.35 B3 N104: migration_report over a drafts or a read connection is refused with the reason" \
  "$(s335c_mcp "$S335C_DRAFTS" migration_report '{"url":"https://old.example"}' | grep -c 'runs only over a connection with full access')|$(s335c_mcp "$S335C_READ" migration_report '{"url":"https://old.example"}' | grep -c '"isError":true')" "1|1"
S335C_CLOSED="https://127.0.0.1:$((PORT + 19))/n104"
s335c_mcp "$S335C_DRAFTS" upload_file "{\"filename\":\"n104.png\",\"url\":\"$S335C_CLOSED/a.png\"}" > "$WORK/response"
S335C_SVG=$(printf '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>' | openssl base64 -A)
expect "3.35 B3 N104: upload_file with a url over a drafts connection is refused before any request; data still uploads (staged)" \
  "$(grep -c 'This connection does not download addresses' "$WORK/response")|$(s335c_mcp "$S335C_DRAFTS" upload_file "{\"filename\":\"n104.svg\",\"data\":\"$S335C_SVG\"}" | tee "$WORK/s335c-svg" | grep -c '"staged":true')" "1|1"
grep -q '"staged":true' "$WORK/s335c-svg" || { echo "         the answer: $(head -c 600 "$WORK/s335c-svg")"; echo "         the url answer: $(head -c 300 "$WORK/response")"; }
expect "3.35 B3 N104: a full connection still downloads by url (a closed port fails as a download, not as the rule)" \
  "$(s335c_mcp "$S335C_FULL" upload_file "{\"filename\":\"n104.png\",\"url\":\"$S335C_CLOSED/a.png\"}" | grep -c 'could not be downloaded')|$(s335c_mcp "$S335C_FULL" upload_file "{\"filename\":\"n104.png\",\"url\":\"$S335C_CLOSED/a.png\"}" | grep -c 'does not download addresses')" "1|0"
sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail, zmeneno) VALUES ('N104', 's335c-n104', '[{\"klic\":\"foto\",\"popisek\":\"Photo\",\"typ\":\"obrazek\"}]', 0, '$(site_time)')" > /dev/null
s335c_mcp "$S335C_DRAFTS" save_collection_items "{\"collection\":\"s335c-n104\",\"items\":[{\"name\":\"Oak\",\"slug\":\"oak\",\"media\":{\"foto\":\"$S335C_CLOSED/oak.jpg\"}}]}" > "$WORK/response"
expect "3.35 B3 N104: save_collection_items over a drafts connection saves the item hidden and downloads no medium – it says why" \
  "$(grep -c 'only a connection with full access downloads addresses' "$WORK/response")|$(sq "SELECT CONCAT(COUNT(*), '/', IFNULL(MAX(zobrazit), '-')) FROM ka_kolekce_polozky WHERE seo_link = 'oak' AND idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 's335c-n104')")" "1|1/0"
s335c_mcp "$S335C_FULL" save_collection_items "{\"collection\":\"s335c-n104\",\"items\":[{\"slug\":\"oak\",\"media\":{\"foto\":\"$S335C_CLOSED/oak.jpg\"}}]}" > "$WORK/response"
expect "3.35 B3 N104: over a full connection the same medium is tried (and fails as a download)" \
  "$(grep -c 'media_failed' "$WORK/response")|$(grep -c 'only a connection with full access downloads' "$WORK/response")" "1|0"
sq "DELETE FROM ka_kolekce_polozky WHERE idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 's335c-n104'); DELETE FROM ka_kolekce WHERE seo_link = 's335c-n104'; DELETE FROM ka_api_tokeny WHERE nazev LIKE 's335c %'" > /dev/null
