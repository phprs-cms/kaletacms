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

# M2: Send and Save on one draft at once – the second waits for the row lock and sees what the first did
R338_RACE=$(r338_php '$l = [["name" => "Kus 338r", "quantity" => "1", "unit_price" => "100"]];
  echo Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Závod 338r"], "lines" => $l], 0)["id"], " ", Kaleta\Core\Quotes::save($app, null, ["customer" => ["name" => "Závod 338r"], "lines" => $l], 0)["id"];')
read -r R338_RACE1 R338_RACE2 <<< "$R338_RACE" || true; R338_RACE1="${R338_RACE1:-0}"; R338_RACE2="${R338_RACE2:-0}"
r338_php '$db->transaction(function () use ($app, $a): void { Kaleta\Core\Quotes::finalise($app, (int) $a[0], 0); sleep(3); });' "$R338_RACE1" > /dev/null 2>&1 &
R338_BG=$!; sleep 1
R338_SAVE=$(r338_php 'try { Kaleta\Core\Quotes::save($app, (int) $a[0], ["lines" => [["name" => "Kus 338r", "quantity" => "1", "unit_price" => "999"]]], 0); echo "saved"; } catch (DomainException $e) { echo "refused"; }' "$R338_RACE1" 2>&1)
wait "$R338_BG" 2>/dev/null || true
expect "3.38 review M2: a save that overlaps Send waits for the lock and is refused – the numbered quote keeps the lines of its frozen document" \
  "$R338_SAVE|$(sq "SELECT CONCAT(q.status, '|', l.unit_price, '|', JSON_UNQUOTE(JSON_EXTRACT(q.snapshot, '\$.totals.net')), '|', q.total_net) FROM ka_quotes q JOIN ka_quote_lines l ON l.quote_id = q.id WHERE q.id = $R338_RACE1")" \
  "refused|sent|100.00|100.00|100.00"
r338_php '$db->transaction(function () use ($app, $a): void { Kaleta\Core\Quotes::save($app, (int) $a[0], ["lines" => [["name" => "Kus 338r", "quantity" => "5", "unit_price" => "100"]]], 0); sleep(3); });' "$R338_RACE2" > /dev/null 2>&1 &
R338_BG=$!; sleep 1
r338_php 'Kaleta\Core\Quotes::finalise($app, (int) $a[0], 0);' "$R338_RACE2" > /dev/null 2>&1 || true
wait "$R338_BG" 2>/dev/null || true
expect "3.38 review M2: Send that overlaps a save freezes the document from the saved lines – the snapshot, the stored total and the lines agree" \
  "$(sq "SELECT CONCAT(q.status, '|', l.quantity, '|', JSON_UNQUOTE(JSON_EXTRACT(q.snapshot, '\$.totals.net')), '|', q.total_net, '|', JSON_UNQUOTE(JSON_EXTRACT(q.snapshot, '\$.lines[0].quantity'))) FROM ka_quotes q JOIN ka_quote_lines l ON l.quote_id = q.id WHERE q.id = $R338_RACE2")" \
  "sent|5.000|500.00|500.00|5.000"

# --- clean up
r338_php 'Kaleta\Core\QuoteDelivery::delete($db, array_map("intval", array_column($db->all("SELECT id FROM {quotes}"), "id")));' > /dev/null 2>&1 || true
sq "DELETE FROM ka_price_lists WHERE id = $R338_EUR; DELETE FROM ka_contacts WHERE id = $R338_CONTACT; DELETE FROM ka_quote_counters; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$R338_EXT');
  DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
