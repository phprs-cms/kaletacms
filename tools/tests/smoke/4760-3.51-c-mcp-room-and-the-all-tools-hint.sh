echo "== 3.51 C: the work set has room again; a connection that lists every tool hears what it costs (site_info, My account)"
# c760_bytes <token>: the bytes of the connection's live tools/list, measured as unit 2550 measures (unescaped JSON)
c760_bytes() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  | php -r 'echo strlen(json_encode(["tools" => json_decode(stream_get_contents(STDIN), true)["result"]["tools"] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));'; }
# c760_connection <token>: site_info's connection as tool_set|hint
c760_connection() { mcp_call "$1" site_info '{}' > "$WORK/response"; mcp_text
  php -r '$c = json_decode(file_get_contents($argv[1]), true)["connection"] ?? []; echo ($c["tool_set"] ?? "-"), "|", ($c["hint"] ?? "");' -- "$WORK/text"; }
C760_WORK="kaleta_$(openssl rand -hex 24)"
C760_ALL="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'c760 work', 'full', 'work', SHA2('$C760_WORK', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
C760_BYTES=$(c760_bytes "$C760_WORK")
expect "3.51 C1: a work connection's live tools/list stays under the 3.51 target of 53,200 bytes" "$([ "${C760_BYTES:-0}" -gt 1000 ] && [ "$C760_BYTES" -le 53200 ] && echo under || echo "over ${C760_BYTES:-?}")" "under"
expect "3.51 C3: site_info of a work connection names its tool set and gives no hint" "$(c760_connection "$C760_WORK")" "work|"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/c760-account.html" "$B/admin.php?action=account&tab=claude"
# no all-tools token before is a count of 0 – grep finding nothing must not stop the suite (set -e, pipefail)
C760_BEFORE=$({ grep -o 'Toto napojení načítá všechny nástroje – v každé konverzaci asi [0-9]* kB' "$WORK/c760-account.html" || true; } | wc -l | tr -d ' ')
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'c760 all', 'full', 'all', SHA2('$C760_ALL', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
C760_HINT=$(c760_connection "$C760_ALL")
expect "3.51 C3: site_info of an all-tools connection names the set and hints at the size and at the set to choose instead" \
  "$(printf '%s' "$C760_HINT" | cut -d'|' -f1)|$(printf '%s' "$C760_HINT" | grep -c 'every tool, about [0-9]* kB in each conversation; the Work, Essentials or Migration set is a fraction')|$(printf '%s' "$C760_HINT" | grep -c 'Change the tool set')" "all|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/c760-account.html" "$B/admin.php?action=account&tab=claude"
expect "3.51 C3: My account shows the hint in the row of the all-tools token only (one more than before), and the new-token choice says what everything costs" \
  "$(( $({ grep -o 'Toto napojení načítá všechny nástroje – v každé konverzaci asi [0-9]* kB' "$WORK/c760-account.html" || true; } | wc -l | tr -d ' ') - C760_BEFORE ))|$(grep -c 'V každé konverzaci asi [0-9]* kB; ostatní sady načtou jen zlomek.' "$WORK/c760-account.html")" "1|1"
sq "DELETE FROM ka_api_tokeny WHERE nazev IN ('c760 work', 'c760 all')" > /dev/null
