echo "== 3.36 T3: query budgets of the busy lists"
# Q12: the test copy answers every admin request with X-Kaleta-Queries (Core\TestSeams, only with KALETA_TEST_SEAMS in
# config.php). Each busy list stays under its budget, and 30 more customers, deals and enquiries do not add one statement –
# the cheapest guard against a query per row (N+1) creeping into a list
t3q() { curl -s -b "$JAR" -D - -o /dev/null "$B/admin.php?$1" | tr -d '\r' | sed -n 's/^[Xx]-[Kk]aleta-[Qq]ueries: //p'; }
t3q_all() { printf '%s|%s|%s|%s|%s|%s|%s' "$(t3q 'module=contacts')" "$(t3q 'module=contacts&action=companies')" "$(t3q 'module=pipeline')" "$(t3q 'module=pipeline&view=list')" "$(t3q 'module=enquiries')" "$(t3q 'module=my_work')" "$(t3q '')"; }
t3q_within() { php -r '$n = explode("|", $argv[1]); $max = explode("|", $argv[2]); $over = [];
  foreach ($max as $i => $m) { if (($n[$i] ?? "") === "" || (int) $n[$i] > (int) $m) { $over[] = $i . ":" . ($n[$i] ?? "") . ">" . $m; } } echo $over === [] ? "within" : implode(" ", $over);' -- "$1" "$2"; }
# customers, companies, the board, the pipeline list, enquiries, my work, the dashboard
T3Q_BUDGET="30|25|30|30|28|32|75" # 3.36.0: 22|16|22|20|19|24|61 after all earlier sections
T3Q_BEFORE=$(t3q_all)
expect "3.36 T3 (Q12): the test copy reports the statements of a request (X-Kaleta-Queries), a real site never (Core\\TestSeams)" \
  "$(printf '%s' "$T3Q_BEFORE" | grep -cE '^[0-9]+(\|[0-9]+){6}$')" "1"
expect "3.36 T3 (Q12): Customers, companies, the board, the pipeline list, Enquiries, My work and the dashboard stay within their query budgets ($T3Q_BUDGET; now $T3Q_BEFORE)" \
  "$(t3q_within "$T3Q_BEFORE" "$T3Q_BUDGET")" "within"
T3Q_STAGE=$(sq "SELECT COALESCE((SELECT stage FROM ka_deals WHERE closed_at IS NULL ORDER BY id LIMIT 1), 'new')")
T3Q_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
T3Q_NOW=$(site_time)
t3q_seed() { # <from> <to>: customers with a company, an open deal and an assigned enquiry each
  local seq="(WITH RECURSIVE s (n) AS (SELECT $1 UNION ALL SELECT n + 1 FROM s WHERE n < $2) SELECT n FROM s) q"
  sq "INSERT INTO ka_companies (name, created_at, updated_at) SELECT CONCAT('t3q company ', n), '$T3Q_NOW', '$T3Q_NOW' FROM $seq;
    INSERT INTO ka_contacts (email, email_norm, name, owner_id, company_id, source, first_seen_at, last_activity_at, created_at, updated_at)
      SELECT CONCAT('t3q', n, '@example.cz'), CONCAT('t3q', n, '@example.cz'), CONCAT('T3q ', n), $T3Q_ADMIN, (SELECT id FROM ka_companies WHERE name = CONCAT('t3q company ', n)),
        'manual', '$T3Q_NOW', '$T3Q_NOW', '$T3Q_NOW', '$T3Q_NOW' FROM $seq;
    INSERT INTO ka_deals (title, contact_id, owner_id, stage, created_at, updated_at)
      SELECT CONCAT('t3q deal ', c.id), c.id, $T3Q_ADMIN, '$T3Q_STAGE', '$T3Q_NOW', '$T3Q_NOW' FROM ka_contacts c LEFT JOIN ka_deals d ON d.contact_id = c.id WHERE c.email LIKE 't3q%@example.cz' AND d.id IS NULL;
    INSERT INTO ka_poptavky (datum, formular, email, data, contact_id, prirazeno)
      SELECT '$T3Q_NOW', 't3q', c.email, '[]', c.id, $T3Q_ADMIN FROM ka_contacts c LEFT JOIN ka_poptavky p ON p.contact_id = c.id AND p.formular = 't3q' WHERE c.email LIKE 't3q%@example.cz' AND p.idp IS NULL" > /dev/null; }
# a list with rows asks a few more questions than an empty one (the counts beside its rows) – so 5 rows first, then 30 more
t3q_seed 1 5; T3Q_FIVE=$(t3q_all)
t3q_seed 6 35; T3Q_AFTER=$(t3q_all)
expect "3.36 T3 (Q12): 30 more customers, companies, deals and enquiries add no statement to any of the seven pages (no query per row)" "$T3Q_AFTER" "$T3Q_FIVE"
expect "3.36 T3 (Q12): … and with them every page is still within its budget" "$(t3q_within "$T3Q_AFTER" "$T3Q_BUDGET")" "within"
sq "DELETE FROM ka_poptavky WHERE formular = 't3q'; DELETE d FROM ka_deals d JOIN ka_contacts c ON c.id = d.contact_id WHERE c.email LIKE 't3q%@example.cz';
  DELETE FROM ka_contacts WHERE email LIKE 't3q%@example.cz'; DELETE FROM ka_companies WHERE name LIKE 't3q company %'" > /dev/null
