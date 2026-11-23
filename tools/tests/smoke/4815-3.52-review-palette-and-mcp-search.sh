echo "== 3.52 review: MCP search – applications only with applications_claude, never user accounts, notes in English; the palette keeps users, its \"+ N more\" of applications lists every status, Recently opened per sign-in; list_enquiries and list_news filter by a number"
r815_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; } # reads the whole input (no SIGPIPE under pipefail)
r815_mcp() { mcp_call "$R815_TOKEN" "$1" "$2" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"]["content"][0]["text"] ?? json_encode($r["error"] ?? $r);'; }
# the kinds of an MCP search answer (sorted, unique), the kinds in totals, whether the note of the page is in English
r815_kinds() { r815_mcp search "{\"query\":\"$1\"}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $t = array_unique(array_column($d["results"] ?? [], "type")); sort($t); $k = array_keys((array) ($d["totals"] ?? [])); sort($k); $n = ""; foreach ($d["results"] ?? [] as $r) { if ($r["type"] === "page") { $n = $r["note"] ?? ""; } } echo implode(",", $t), "|", implode(",", $k), "|", (int) str_contains($n, "hidden · /kovar-r815");'; }
R815_NOW=$(site_time)
R815_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R815_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +applications
R815_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($R815_ADMIN, 'r815 admin', 'full', 'all', SHA2('$R815_TOKEN', 256), '$R815_NOW')" > /dev/null

# one word "Kovář R815" in a hidden page, a rejected job application, the administrator's task about it and a blocked account
R815_APP=$(sq "INSERT INTO ka_applications (received_at, name, email, email_norm, job_title, data, status, status_at, last_activity_at) VALUES
  ('$R815_NOW', 'Rút Kovář R815', 'rut-r815@example.com', 'rut-r815@example.com', 'Welder R815', '[]', 'rejected', '$R815_NOW', '$R815_NOW'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('kovar-r815', 'Kovář R815 stránka', '', 0);
  INSERT INTO ka_tasks (title, target, assignee_id, created_by, created_at, updated_at) VALUES ('Pohovor Kovář R815', 'application:$R815_APP', $R815_ADMIN, $R815_ADMIN, '$R815_NOW', '$R815_NOW');
  INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('kovar-r815-login', '', 'Kovář R815 účet', 'ucet-r815@example.com', 'en', 0, '$R815_NOW', '$R815_NOW', 1, 'off')" > /dev/null

R815_PALETTE=$(curl -s -G -b "$JAR" "$B/admin.php" -d action=palette_search --data-urlencode 'q=kovar r815')
expect "3.52 review: the palette keeps the user accounts and the task about the application; its \"+ N more\" of applications opens the list of every status" \
  "$(printf '%s' "$R815_PALETTE" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $t = array_column($d["groups"] ?? [], "type"); sort($t); $m = ""; foreach ($d["groups"] ?? [] as $g) { if ($g["type"] === "application") { $m = $g["more"]; } } echo implode(",", $t), "|", (int) str_contains($m, "module=applications&status=&search=");')" \
  "application,page,task,user|1"
expect "3.52 review: MCP search with applications_claude on – the application and its task, never the account; the note of the hidden page in English" \
  "$(r815_kinds 'kovar r815')" "application,page,task|application,page,task|1"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_claude', '0')" > /dev/null
expect "3.52 review (high): MCP search with applications_claude off – no application, no task about one, nothing of them in totals" \
  "$(r815_kinds 'kovar r815')" "page|page|1"
sq "DELETE FROM ka_nastaveni WHERE promenna = 'applications_claude'" > /dev/null

# Recently opened of the palette belongs to the account and its sign-in
expect "3.52 review: the palette carries the account and the sign-in its Recently opened belongs to" \
  "$(curl -s -b "$JAR" "$B/admin.php" | r815_has "data-paleta-prihlaseni=\"$R815_ADMIN:")|$(grep -c 'stored.who !== popupSignIn' "$WORK/web/image/admin.js")" "1|1"

# list_enquiries and list_news: a number given as a JSON number still filters (as before 3.52), never the whole list
R815_TEMA=$(sq "SELECT tema FROM ka_novinky ORDER BY idc LIMIT 1")
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES
  ('$R815_NOW', 'Kontakt R815', 'phone-r815@example.com', '[[\"Telefon\",\"608123815\"]]', 0),
  ('$(site_time '+1 minute')', 'Kontakt R815', 'other-r815@example.com', '[[\"Zpráva\",\"Bez čísla\"]]', 0);
  INSERT INTO ka_novinky (seo_link, titulek, uvod, text, tema, datum, visible, autor, hledani) VALUES
  ('vyroci-r815', 'Výročí 1815 R815', '', '', $R815_TEMA, '$R815_NOW', 0, $R815_ADMIN, 'vyroci 1815 r815'),
  ('jina-r815', 'Jiná R815', '', '', $R815_TEMA, '$(site_time '+1 minute')', 0, $R815_ADMIN, 'jina r815')" > /dev/null
R815_ENQ=$(r815_mcp list_enquiries '{"search":608123815}')
R815_NEWS=$(r815_mcp list_news '{"search":1815,"status":"all"}')
expect "3.52 review: list_enquiries {search: 608123815} and list_news {search: 1815} filter as before 3.52" \
  "$(printf '%s' "$R815_ENQ" | r815_has 'phone-r815@example.com')|$(printf '%s' "$R815_ENQ" | r815_has 'other-r815@example.com')|$(printf '%s' "$R815_NEWS" | r815_has 'Výročí 1815 R815')|$(printf '%s' "$R815_NEWS" | r815_has 'Jiná R815')" \
  "1|0|1|0"

features --exact "$R815_EXT"
sq "DELETE FROM ka_applications WHERE id = $R815_APP; DELETE FROM ka_stranky WHERE seo_link = 'kovar-r815'; DELETE FROM ka_tasks WHERE title = 'Pohovor Kovář R815';
  DELETE FROM ka_uzivatele WHERE user = 'kovar-r815-login'; DELETE FROM ka_poptavky WHERE formular = 'Kontakt R815';
  DELETE FROM ka_novinky WHERE seo_link IN ('vyroci-r815', 'jina-r815'); DELETE FROM ka_api_tokeny WHERE nazev = 'r815 admin'" > /dev/null
