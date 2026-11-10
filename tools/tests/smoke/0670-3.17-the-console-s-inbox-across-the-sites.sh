echo "== 3.17: the console's inbox across the sites, customers (Fleet\Inbox, Fleet\Customers)"
# two more sites in the console: Beta reports like a 3.15 site (no 3.16 parts) and has been silent for 30 hours, Gamma has bookings to confirm
sq3 "INSERT INTO ka_fleet_sites (name, url, public_key, paired_at, last_seen, version, version_since, manage_updates, up, heartbeat) VALUES
  ('Fleet317 Beta', 'http://beta317.test', 'beta317key', '$(site_time)', '$(site_time '-30 hours')', '3.0.0', '$(site_time)', 1, 1, '{\"last_backup\":$(date +%s),\"enquiries_unanswered\":4}'),
  ('Fleet317 Gamma', 'http://gamma317.test', 'gamma317key', '$(site_time)', '$(site_time)', '3.0.0', '$(site_time)', 1, 1, '{\"last_backup\":$(date +%s),\"bookings_pending\":2}')" > /dev/null
BETA317=$(sq3 "SELECT id FROM ka_fleet_sites WHERE public_key = 'beta317key'"); GAMMA317=$(sq3 "SELECT id FROM ka_fleet_sites WHERE public_key = 'gamma317key'")
# this site: a request waiting for three days, its title with a phone number and an address
sq "INSERT INTO ka_requests (created_at, updated_at, author_id, title, text, about, status) SELECT '$(site_time '-3 days')', '$(site_time '-3 days')', idu, 'Fleet317 price list – call +420 777 317 317 or jana317@example.cz', 'Secret text 317', '', 'new' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
REQ317=$(sq "SELECT id FROM ka_requests WHERE title LIKE 'Fleet317 price%'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=inbox"
grep -qF "$B/admin.php?module=requests&amp;action=detail&amp;id=$REQ317\" target=\"_blank\" rel=\"noopener\"" "$WORK/response" && grep -qF 'Fleet317 price list – call [phone] or [e-mail]' "$WORK/response" \
  && echo "  ok     inbox: the request title, masked, with a plain link to the request in the site's administration" || { echo "  CHYBA  inbox: the request with its plain link"; ERRORS=$((ERRORS+1)); }
grep -qF 'Fleet317 Beta' "$WORK/response" && grep -q 'possibly out of date\|možná neaktuální' "$WORK/response" && grep -qF 'href="http://beta317.test/admin.php?module=enquiries"' "$WORK/response" && grep -qF 'href="http://gamma317.test/admin.php?module=bookings&amp;view=vse&amp;status=pending"' "$WORK/response" \
  && echo "  ok     inbox: a 3.15 report (enquiries) marked possibly out of date, bookings of another site, each with its link" || { echo "  CHYBA  inbox: the other sites"; ERRORS=$((ERRORS+1)); }
! contains -qE 'jana317|777 317|Secret text 317' "$WORK/response" && ! grep -q 'admin.php?[^"]*\(token\|password\|login\)' "$WORK/response" && echo "  ok     inbox: no e-mail address, phone number or request text, no sign-in in the links" || { echo "  CHYBA  inbox: personal data or a sign-in link"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&kind=bookings"
grep -qF 'Fleet317 Gamma' "$WORK/response" && ! grep -qF 'Fleet317 price list' "$WORK/response" && echo "  ok     inbox: the kind filter" || { echo "  CHYBA  inbox: the kind filter"; ERRORS=$((ERRORS+1)); }
# customers: two sites at once from the list, the third one from its detail with a note
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=sites&show=all"
grep -q 'name="sites\[\]"' "$WORK/response" && echo "  ok     the list of sites: tick sites to give them a customer" || { echo "  CHYBA  the list of sites: bulk customer"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer_bulk" -d "_csrf=$(csrf)" -d "sites[]=$FLEET_ID" -d "sites[]=$BETA317" --data-urlencode "customer=  Acme 317 "
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$GAMMA317"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer" -d "_csrf=$(csrf)" -d "id=$GAMMA317" -d "customer=Other 317" -d "note=Calls on Mondays"
ACME317=$(sq3 "SELECT id FROM ka_fleet_customers WHERE name = 'Acme 317'")
expect "customers: two sites of Acme from the list, Gamma of another customer with the owner's note" "$(sq3 "SELECT CONCAT((SELECT COUNT(*) FROM ka_fleet_sites WHERE customer_id = $ACME317), '|', (SELECT c.name FROM ka_fleet_sites s JOIN ka_fleet_customers c ON c.id = s.customer_id WHERE s.id = $GAMMA317), '|', (SELECT note FROM ka_fleet_sites WHERE id = $GAMMA317))")" "2|Other 317|Calls on Mondays"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=inbox&customer=$ACME317"
grep -qF 'Fleet317 price list' "$WORK/response" && grep -qF 'href="http://beta317.test/' "$WORK/response" && ! grep -qF 'href="http://gamma317.test/' "$WORK/response" && echo "  ok     inbox: the customer filter" || { echo "  CHYBA  inbox: the customer filter"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=sites&customer=$ACME317"
grep -qF 'Fleet317 Beta' "$WORK/response" && ! grep -qF 'Fleet317 Gamma' "$WORK/response" && grep -qF ">Acme 317</a>" "$WORK/response" && echo "  ok     the list of sites: the customer column and filter" || { echo "  CHYBA  the list of sites: customer"; ERRORS=$((ERRORS+1)); }
# 3.17 merge: the fleet report groups by the customer (its id, the name as it was) – rebuilt now that the sites have customers
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=report_build" -d "_csrf=$(csrf)" -d "month=$PREV317"
expect "fleet report by customer: Acme's two sites in one report, Gamma in its customer's" \
  "$(sq3 "SELECT CONCAT(customer, '|', JSON_LENGTH(data, '\$.sites'), '|', JSON_EXTRACT(data, '\$.customer_id')) FROM ka_fleet_reports WHERE month = '$PREV317' AND customer_id = $ACME317")|$(sq3 "SELECT customer FROM ka_fleet_reports WHERE month = '$PREV317' AND customer_id = $(sq3 "SELECT customer_id FROM ka_fleet_sites WHERE id = $GAMMA317")")" \
  "Acme 317|2|$ACME317|Other 317"
# renaming a customer once renames it for all its sites
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=customers"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=customer_rename" -d "_csrf=$(csrf)" -d "id=$ACME317" -d "name=Acme 317 Ltd"
expect "customers: renamed once for both sites" "$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(c.name)) FROM ka_fleet_sites s JOIN ka_fleet_customers c ON c.id = s.customer_id WHERE c.id = $ACME317")" "2|Acme 317 Ltd"
# the renamed customer keeps its archived report: the Customers tab links to it, the list shows the name now (and then), the digest links to the inbox filter
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=customers"
grep -qF "action=report_view&amp;month=$PREV317&amp;customer=$ACME317" "$WORK/response" && echo "  ok     Customers: a link to the customer's latest fleet report" || { echo "  CHYBA  Customers: the fleet report link"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=reports"
grep -qF "module=fleet&amp;action=inbox&amp;customer=$ACME317\">Acme 317 Ltd</a>" "$WORK/response" && grep -q 'dříve Acme 317\|then Acme 317' "$WORK/response" \
  && echo "  ok     Fleet reports: a renamed customer under its name now (the inbox filter), the name it had then" || { echo "  CHYBA  Fleet reports: the renamed customer"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=report_view&month=$PREV317&customer=$ACME317"
expect "fleet report of the renamed customer: printed with the name it was made with" "$(sq3 "SELECT customer FROM ka_fleet_reports WHERE month = '$PREV317' AND customer_id = $ACME317")|$(grep -c 'Acme 317 <span>' "$WORK/response")" "Acme 317|1"
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"fleet_report\",\"arguments\":{\"month\":\"$PREV317\",\"customer\":\"Acme 317 Ltd\"}}}" > "$WORK/response"
contains -qF 'customer_then' "$WORK/response" && contains -qF 'Acme 317 Ltd' "$WORK/response" && ! contains -qF 'Other 317' "$WORK/response" \
  && echo "  ok     MCP fleet_report: a renamed customer by its name now, with the name the report was made with" || { echo "  CHYBA  MCP fleet_report by customer"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=digest_preview"
grep -qF "== Acme 317 Ltd ==" "$WORK/response" && grep -qF "module=fleet&amp;action=inbox&amp;customer=$ACME317" "$WORK/response" && echo "  ok     digest: by customer, each with a link to the inbox filtered by it" || { echo "  CHYBA  digest: customers"; ERRORS=$((ERRORS+1)); }
# the new version for one customer's sites only (this site decides itself since the test above; Beta lets the console decide)
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=customers"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=allow" -d "_csrf=$(csrf)" -d "customer=$ACME317"
expect "allow the new version for one customer: Beta (Acme) yes, Gamma (another customer) no" "$(sq3 "SELECT CONCAT((SELECT update_allowed FROM ka_fleet_sites WHERE id = $BETA317), '|', (SELECT update_allowed FROM ka_fleet_sites WHERE id = $GAMMA317))")" "3.99.9|"
# MCP on the console: fleet_inbox (read-only, filters) and list_sites with the customer
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_inbox","arguments":{"customer":"Acme 317 Ltd"}}}' > "$WORK/response"
contains -qF 'Fleet317 price list' "$WORK/response" && contains -q 'written_on_the_site' "$WORK/response" && contains -q "module=requests&action=detail&id=$REQ317" "$WORK/response" && ! contains -qF 'Fleet317 Gamma' "$WORK/response" && ! contains -q 'jana317' "$WORK/response" \
  && echo "  ok     MCP fleet_inbox: one customer's waiting items with plain links, texts marked as written on the sites" || { echo "  CHYBA  MCP fleet_inbox"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fleet_inbox","arguments":{"kind":"bookings"}}}' > "$WORK/response"
contains -qF 'Fleet317 Gamma' "$WORK/response" && ! contains -qF 'Fleet317 Beta' "$WORK/response" && echo "  ok     MCP fleet_inbox: the kind filter" || { echo "  CHYBA  MCP fleet_inbox kind"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_sites","arguments":{"customer":"Other 317"}}}' > "$WORK/response"
contains -qF 'Fleet317 Gamma' "$WORK/response" && contains -qF 'Other 317' "$WORK/response" && ! contains -qF 'Fleet317 Beta' "$WORK/response" && echo "  ok     MCP list_sites: the customer of each site, filtered by customer" || { echo "  CHYBA  MCP list_sites customer"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# a kit version for another customer does not reach this site; one for its own customer does
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
grep -q 'name="customer"' "$WORK/response" && echo "  ok     the shared kit can be meant for one customer" || { echo "  CHYBA  kit: the customer choice"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d "components[]=$KIT_COMPONENT" -d "customer=$(sq3 "SELECT id FROM ka_fleet_customers WHERE name = 'Other 317'")"
KIT_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "a kit for another customer does not reach this site" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'")" "$KIT_BEFORE"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=kit"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=kit_publish" -d "_csrf=$(csrf)" -d "components[]=$KIT_COMPONENT" -d "customer=$ACME317"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "a kit for this site's customer reaches it" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_kit_version'")" "$(sq3 "SELECT MAX(version) FROM ka_fleet_kits")"
