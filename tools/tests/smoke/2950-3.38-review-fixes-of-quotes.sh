echo "== 3.38 review: the fixes of the security review of internal prices and quotes"
R338_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$R338_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$R338_EXT"),quotes'), ('quote_vat_rate', '21')" > /dev/null
R338_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
r338_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R338_NOW=$(site_time)
sq "INSERT INTO ka_contacts (email, email_norm, name, source, created_at, updated_at) VALUES ('osoba338r@example.cz', 'osoba338r@example.cz', 'Olga Osobní', 'manual', '$R338_NOW', '$R338_NOW')" > /dev/null
R338_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'osoba338r@example.cz'"); R338_CONTACT="${R338_CONTACT:-0}"

# M1: a draft with lines never changes its price list (lines kept by id would keep the old currency's numbers)
r338_php 'Kaleta\Core\Prices::defaultList($db, $app->settings()); $eur = $db->insert("price_lists", ["name" => "Export 338r", "currency" => "EUR", "is_default" => 0, "created_at" => date("Y-m-d H:i:s"), "updated_at" => date("Y-m-d H:i:s")]);
  $q = Kaleta\Core\Quotes::save($app, null, ["contact_id" => (int) $a[1], "lines" => [["name" => "Servis 338r", "quantity" => "1", "unit_price" => "1000"]]], (int) $a[0])["id"];
  $e = Kaleta\Core\Quotes::save($app, null, ["contact_id" => (int) $a[1]], (int) $a[0])["id"];
  echo implode(" ", [$eur, $q, $e]);' "$R338_ADMIN" "$R338_CONTACT" > "$WORK/r338-ids"
read -r R338_EUR R338_Q R338_EMPTY < "$WORK/r338-ids" || true; R338_EUR="${R338_EUR:-0}"; R338_Q="${R338_Q:-0}"; R338_EMPTY="${R338_EMPTY:-0}"
R338_LINE=$(sq "SELECT id FROM ka_quote_lines WHERE quote_id = $R338_Q")
mcp save_quote "{\"id\":$R338_Q,\"price_list\":\"EUR\",\"lines\":[{\"id\":${R338_LINE:-0},\"quantity\":2}]}" > "$WORK/response"; mcp_text
expect "3.38 review M1: save_quote cannot switch a draft with lines to a price list of another currency – the lines keep their prices" \
  "$(grep -c 'cannot change' "$WORK/text")|$(sq "SELECT CONCAT(q.currency, '|', l.unit_price, '|', l.quantity) FROM ka_quotes q JOIN ka_quote_lines l ON l.quote_id = q.id WHERE q.id = $R338_Q")" "1|CZK|1000.00|1.000"
expect "3.38 review M1: the same holds for every caller of Quotes::save; a draft without lines may still change its price list" \
  "$(r338_php 'try { Kaleta\Core\Quotes::save($app, (int) $a[0], ["price_list_id" => (int) $a[1]], 0); echo "saved"; } catch (DomainException $e) { echo "refused"; }
    echo "|", Kaleta\Core\Quotes::save($app, (int) $a[2], ["price_list_id" => (int) $a[1]], 0)["id"] === (int) $a[2] ? Kaleta\Core\Quotes::get($db, (int) $a[2])["currency"] : "";' "$R338_Q" "$R338_EUR" "$R338_EMPTY")" "refused|EUR"

# the state of a sent quote: status | the line's quantity | the frozen document's net | the stored net | the lines' net now
r338_state() { r338_php '$q = Kaleta\Core\Quotes::get($db, (int) $a[0]); echo $q === null ? "missing" : implode("|", [$q["status"], $q["lines"][0]["quantity"] ?? "-", $q["snapshot"]["totals"]["net"] ?? "-",
  $db->value("SELECT total_net FROM {quotes} WHERE id = ?", [$q["id"]]), $q["totals"]["net"]]);' "$1" 2>&1; }
# M2: Send and Save on one draft at once – the second waits for the row lock and sees what the first did
R338_RACE=$(r338_php '$l = [["name" => "Kus 338r", "quantity" => "1", "unit_price" => "100"]];
  echo Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Závod 338r"], "lines" => $l], 0)["id"], " ", Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Závod 338r"], "lines" => $l], 0)["id"];')
read -r R338_RACE1 R338_RACE2 <<< "$R338_RACE" || true; R338_RACE1="${R338_RACE1:-0}"; R338_RACE2="${R338_RACE2:-0}"
r338_php '$db->transaction(function () use ($app, $a): void { Kaleta\Core\Quotes::finalise($app, (int) $a[0], 0); sleep(3); });' "$R338_RACE1" > /dev/null 2>&1 &
R338_BG=$!; sleep 1
R338_SAVE=$(r338_php 'try { Kaleta\Core\Quotes::save($app, (int) $a[0], ["lines" => [["name" => "Kus 338r", "quantity" => "1", "unit_price" => "999"]]], 0); echo "saved"; } catch (DomainException $e) { echo "refused"; }' "$R338_RACE1" 2>&1)
wait "$R338_BG" 2>/dev/null || true
expect "3.38 review M2: a save that overlaps Send waits for the lock and is refused – the numbered quote keeps the lines of its frozen document" \
  "$R338_SAVE|$(r338_state "$R338_RACE1")" \
  "refused|sent|1.000|100.00|100.00|100.00"
r338_php '$db->transaction(function () use ($app, $a): void { Kaleta\Core\Quotes::save($app, (int) $a[0], ["lines" => [["name" => "Kus 338r", "quantity" => "5", "unit_price" => "100"]]], 0); sleep(3); });' "$R338_RACE2" > /dev/null 2>&1 &
R338_BG=$!; sleep 1
r338_php 'Kaleta\Core\Quotes::finalise($app, (int) $a[0], 0);' "$R338_RACE2" > /dev/null 2>&1 || true
wait "$R338_BG" 2>/dev/null || true
expect "3.38 review M2: Send that overlaps a save freezes the document from the saved lines – the snapshot, the stored total and the lines agree" \
  "$(r338_state "$R338_RACE2")" "sent|5.000|500.00|500.00|500.00"

# M3 (A-5): Claude sends a quote only to an address it did not write itself – save_contact or an imported enquiry marks it; a person clears it
R338_SMTP=$((PORT + 4)); mkdir -p "$WORK/smtp-r338"; command php "$ROOT/tools/fake-smtp.php" "$R338_SMTP" "$WORK/smtp-r338" > /dev/null 2>&1 & R338_SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$R338_SMTP'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
R338_LINE_JSON='[{"name":"Servis 338r","quantity":1,"unit_price":"1000"}]'
mcp save_contact '{"email":"podvrh338r@example.net","name":"Petr Podvržený"}' > "$WORK/response"; mcp_text
R338_FAKE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | cut -d: -f2); R338_FAKE="${R338_FAKE:-0}"
mcp save_quote "{\"contact\":$R338_FAKE,\"lines\":$R338_LINE_JSON}" > "$WORK/response"; mcp_text
R338_QF=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | cut -d: -f2); R338_QF="${R338_QF:-0}"
mcp send_quote "{\"id\":$R338_QF,\"confirm\":false}" > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/r338-preview.json"
mcp send_quote "{\"id\":$R338_QF,\"confirm\":true}" > "$WORK/response"
expect "3.38 review M3: a customer record Claude created over MCP is not the customer's own address – send_quote refuses it (the preview says so), nothing is sent" \
  "$(sq "SELECT email_by_agent_at IS NOT NULL FROM ka_contacts WHERE id = $R338_FAKE")|$(grep -c 'written by a Claude connection' "$WORK/r338-preview.json")|$(grep -c 'written by a Claude connection' "$WORK/response")|$(sq "SELECT status FROM ka_quotes WHERE id = $R338_QF")|$(grep -l 'podvrh338r@example.net' "$WORK"/smtp-r338/*.eml 2>/dev/null | wc -l | tr -d ' ')" \
  "1|1|1|draft|0"
mcp save_quote "{\"contact\":$R338_CONTACT,\"lines\":$R338_LINE_JSON}" > "$WORK/response"; mcp_text
R338_QP=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | cut -d: -f2); R338_QP="${R338_QP:-0}"
mcp save_contact "{\"id\":$R338_CONTACT,\"email\":\"prevzato338r@example.net\"}" > "$WORK/response"
mcp save_quote "{\"id\":$R338_QP,\"customer\":{\"email\":\"prevzato338r@example.net\"}}" > "$WORK/response"
mcp send_quote "{\"id\":$R338_QP,\"confirm\":true}" > "$WORK/response"
expect "3.38 review M3: an address Claude changed in a person's customer record is refused the same way" \
  "$(sq "SELECT CONCAT(email, '|', email_by_agent_at IS NOT NULL) FROM ka_contacts WHERE id = $R338_CONTACT")|$(grep -c 'written by a Claude connection' "$WORK/response")|$(sq "SELECT status FROM ka_quotes WHERE id = $R338_QP")" \
  "prevzato338r@example.net|1|1|draft"
r338_php 'Kaleta\Core\Contacts::update($app, (int) $a[0], ["email" => "osoba338r@example.cz"], (int) $a[1]); Kaleta\Core\Quotes::save($app, (int) $a[2], ["customer" => ["email" => "osoba338r@example.cz"]], (int) $a[1]);' "$R338_CONTACT" "$R338_ADMIN" "$R338_QP" > /dev/null 2>&1 || true
mcp send_quote "{\"id\":$R338_QP,\"confirm\":true}" > "$WORK/response"; mcp_text
expect "3.38 review M3: once a person sets the address in the administration, the mark is gone and send_quote sends to it" \
  "$(sq "SELECT email_by_agent_at IS NULL FROM ka_contacts WHERE id = $R338_CONTACT")|$(grep -c '"sent":true' "$WORK/text")|$(sq "SELECT status FROM ka_quotes WHERE id = $R338_QP")|$(grep -l '^X-Rcpt-To: osoba338r@example.cz' "$WORK"/smtp-r338/*.eml 2>/dev/null | wc -l | tr -d ' ')" \
  "1|1|sent|1"
sq "INSERT INTO ka_poptavky (datum, formular, zdroj, tema, email, stav, data) VALUES ('$R338_NOW', 'Import 338r', 'import:stary-web', 'Stará poptávka', 'import338r@example.net', 0, '[]')" > /dev/null
R338_IMP=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Import 338r'"); R338_IMP="${R338_IMP:-0}"
R338_QI=$(r338_php 'echo Kaleta\Core\Quotes::save($app, null, ["enquiry_id" => (int) $a[0], "customer" => ["name" => "Ivan Importovaný", "email" => "import338r@example.net"], "lines" => [["name" => "Servis 338r", "quantity" => "1", "unit_price" => "1000"]]], (int) $a[1])["id"];' "$R338_IMP" "$R338_ADMIN")
mcp send_quote "{\"id\":${R338_QI:-0},\"confirm\":true}" > "$WORK/response"
expect "3.38 review M3: the address of an enquiry import_enquiries brought in is refused too; a person can still send from the admin" \
  "$(grep -c 'written by a Claude connection' "$WORK/response")|$(sq "SELECT status FROM ka_quotes WHERE id = ${R338_QI:-0}")|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=quotes&action=send&id=${R338_QI:-0}")" \
  "1|draft|200"
kill "$R338_SMTP_PID" 2>/dev/null || true; wait "$R338_SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_poptavky WHERE idp = $R338_IMP; DELETE FROM ka_contacts WHERE id = $R338_FAKE" > /dev/null

# L1: a random token per link – a link made twice in one second differs, a withdrawn one never comes back; the plain link only in the session that made it
r338_php '$q = Kaleta\Core\Quotes::finalise($app, Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Odkaz 338r"], "lines" => [["name" => "Kus 338r", "quantity" => "1", "unit_price" => "100"]]], 0)["id"], 0)["id"];
  $one = Kaleta\Core\QuoteDelivery::issueLink($app, $q, 0); $h1 = $db->value("SELECT token_hash FROM {quotes} WHERE id = ?", [$q]); $e1 = $db->value("SELECT token_expires_at FROM {quotes} WHERE id = ?", [$q]);
  Kaleta\Core\QuoteDelivery::withdrawLink($app, $q, 0); $two = Kaleta\Core\QuoteDelivery::issueLink($app, $q, 0);
  echo $q, " ", $one, " ", $two, " ", (int) ($h1 !== $db->value("SELECT token_hash FROM {quotes} WHERE id = ?", [$q])), " ", (int) ($e1 === $db->value("SELECT token_expires_at FROM {quotes} WHERE id = ?", [$q]));' > "$WORK/r338-links"
read -r R338_QL R338_L1 R338_L2 R338_LDIFF R338_LSAME < "$WORK/r338-links" || true; R338_QL="${R338_QL:-0}"
expect "3.38 review L1: a new link in the same second as the withdrawn one has another token – the withdrawn one stays the 404, the new one opens" \
  "${R338_LDIFF:-}|${R338_LSAME:-}|$(curl -s -o /dev/null -w '%{http_code}' "${R338_L1:-$B/quote/x}")|$(curl -s -o /dev/null -w '%{http_code}' "${R338_L2:-$B/quote/x}")" "1|1|404|200"
check "3.38 review L1: a link made elsewhere is not shown again – the detail says it is kept as a fingerprint and offers New link" 200 "/admin.php?module=quotes&action=detail&id=$R338_QL" 'data-quote-link-hidden'
expect "3.38 review L1: neither the detail nor the send dialog prints a working link it did not make" \
  "$(grep -c '/quote/[a-f0-9]\{64\}' "$WORK/response")|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=quotes&action=send&id=$R338_QL" | grep -c 'data-quote-link-hidden')" "0|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=link" -d "_csrf=$(csrf)" -d "id=$R338_QL" -d back=detail
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=detail&id=$R338_QL"
R338_L3=$(grep -o "$B/quote/[a-f0-9]\{64\}" "$WORK/response" | head -1)
expect "3.38 review L1: New link shows the new link to copy in this session; it opens, the one before it is dead" \
  "$([ -n "$R338_L3" ] && echo shown)|$(sq "SELECT token_hash = SHA2('${R338_L3##*/}', 256) FROM ka_quotes WHERE id = $R338_QL")|$(curl -s -o /dev/null -w '%{http_code}' "${R338_L3:-$B/quote/x}")|$(curl -s -o /dev/null -w '%{http_code}' "${R338_L2:-$B/quote/x}")" "shown|1|200|404"

# L2: a mangled customer's link is the plain 404 and never lands among the addresses not found (Redirects shows them)
R338_T=$(php -r 'echo bin2hex(random_bytes(32));')
for r338_p in "/quote/$R338_T/" "/quote/${R338_T}x" "/quote/$R338_T/print" "/en/quote/$R338_T" "/QUOTE/$R338_T"; do curl -s -o /dev/null "$B$r338_p"; done
expect "3.38 review L2: mangled links under /quote/ are 404s that store no token among the addresses not found" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$B/quote/${R338_T}x")|$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE LOWER(cesta) LIKE '%$R338_T%'")" "404|0"

# --- clean up
r338_php 'Kaleta\Core\QuoteDelivery::delete($db, array_map("intval", array_column($db->all("SELECT id FROM {quotes}"), "id")));' > /dev/null 2>&1 || true
sq "DELETE FROM ka_price_lists WHERE id = $R338_EUR; DELETE FROM ka_contacts WHERE id = $R338_CONTACT; DELETE FROM ka_quote_counters; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$R338_EXT');
  DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
