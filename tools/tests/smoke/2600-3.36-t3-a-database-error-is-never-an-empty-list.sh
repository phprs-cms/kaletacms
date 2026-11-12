echo "== 3.36 T3: a database error is never an empty list"
# Q2: only a missing table or column means "before the migration" (an empty Customers list, as before); any other database
# error – here a view whose table is gone (MySQL 1356, SQLSTATE HY000) in the place of ka_contacts – is logged and shown as
# an error page, in the admin and through MCP, never as "no customers"
background_jobs hold # the jobs after a response would fail on the broken table too
t3_code() { curl -s -b "$JAR" -o "$WORK/t3.html" -w '%{http_code}' "$B/$1"; }
t3_mcp() { curl -s -o "$WORK/t3.json" -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
  --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_contacts","arguments":{}}}'; }
t3_logged() { echo $(( $(grep -c 'PDOException: SQLSTATE\[HY000\]: General error: 1356' "$WORK/web/storage/log/chyby.log" || true) - T3_LOGGED )); }
T3_LOGGED=0; T3_LOGGED=$(t3_logged)
expect "3.36 T3: Customers, the board, the dashboard and list_contacts answer while the tables are there" \
  "$(t3_code 'admin.php?module=contacts')|$(t3_code 'admin.php?module=pipeline')|$(t3_code 'admin.php')|$(t3_mcp)|$(grep -c '"total' "$WORK/t3.json")" "200|200|200|200|1"
sq "RENAME TABLE ka_contacts TO ka_t3_contacts" > /dev/null
expect "3.36 T3: before the migration (no table ka_contacts) Customers, the board, the dashboard and list_contacts still answer, with nothing listed" \
  "$(t3_code 'admin.php?module=contacts')|$(t3_code 'admin.php?module=pipeline')|$(t3_code 'admin.php')|$(t3_mcp)|$(grep -c '\\"total\\":0' "$WORK/t3.json")|$(t3_logged)" "200|200|200|200|1|0"
sq "CREATE TABLE ka_t3_gone (id INT); CREATE VIEW ka_contacts AS SELECT id FROM ka_t3_gone; DROP TABLE ka_t3_gone" > /dev/null
expect "3.36 T3: a real database error is an error page on Customers, the board and the dashboard and an HTTP 500 for list_contacts – never \"no customers\" – and each is logged" \
  "$(t3_code 'admin.php?module=contacts')|$(grep -c 'Sorry, something went wrong\|došlo k chybě' "$WORK/t3.html")|$(t3_code 'admin.php?module=pipeline')|$(t3_code 'admin.php')|$(t3_mcp)|$(grep -c 'total' "$WORK/t3.json" || true)|$(t3_logged)" \
  "500|1|500|500|500|0|4"
sq "DROP VIEW ka_contacts; RENAME TABLE ka_t3_contacts TO ka_contacts" > /dev/null
php -r '$f = $argv[1]; file_put_contents($f, implode("", array_filter(file($f) ?: [], fn ($r) => !str_contains($r, "General error: 1356"))));' "$WORK/web/storage/log/chyby.log"
expect "3.36 T3: … and with the table back Customers lists again" "$(t3_code 'admin.php?module=contacts')" "200"
background_jobs resume
