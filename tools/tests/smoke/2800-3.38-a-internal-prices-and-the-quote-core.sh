echo "== 3.38 A: internal prices (Quotes → Internal prices, the item box, CSV, MCP), never public, and the core of quotes"
Q338_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
Q338_YEAR=$(site_time now Y)
Q338_CANARY='98765.43'
# a products collection of its own: a laser with two variants, a tank, a translation of nothing
mcp create_collection '{"name":"Stroje 338","preset":"products"}' > /dev/null
Q338_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'stroje-338'")
mcp save_collection_item '{"collection":"stroje-338","name":"Laser 338","slug":"laser-338","values":{"code":"L338","price":"","variants":"Modrý | L338-M\nZelený | L338-Z"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"stroje-338","name":"Nádrž 338","slug":"nadrz-338","values":{"code":"T338"},"visible":true}' > /dev/null
Q338_LASER=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = ${Q338_IDK:-0} AND seo_link = 'laser-338'"); Q338_LASER="${Q338_LASER:-0}"
Q338_TANK=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = ${Q338_IDK:-0} AND seo_link = 'nadrz-338'"); Q338_TANK="${Q338_TANK:-0}"

# off by default: no section, no tool
expect "3.38: with the feature off the section answers 403 and tools/list has no price tool" \
  "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=quotes&action=prices")|$(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -c '"name":"list_prices"')" "403|0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$Q338_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$Q338_EXT"),quotes')" > /dev/null

# the grid: the products with their variants, the first default list created in the site's currency
check "3.38: Quotes → Internal prices lists the products with their variants" 200 "/admin.php?module=quotes&action=prices&collection=$Q338_IDK" "data-price-row=\"$Q338_LASER:L338-M\""
Q338_LIST=$(sq "SELECT id FROM ka_price_lists WHERE is_default = 1"); Q338_LIST="${Q338_LIST:-0}"
expect "3.38: the default price list exists once, in the site's default currency (CZK on the Czech test site)" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(currency)) FROM ka_price_lists WHERE is_default = 1")" "1|CZK"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=prices_save" -d "_csrf=$(csrf)" -d list=$Q338_LIST -d collection=$Q338_IDK \
  -d "item[]=$Q338_LASER" -d "variant[]=" -d "code[]=" -d "label[]=" --data-urlencode "price[]=$Q338_CANARY" -d "unit[]=ks" -d "vat[]=21" --data-urlencode "note[]=od 10 ks se zeptat" \
  -d "item[]=$Q338_LASER" -d "variant[]=L338-M" -d "code[]=" -d "label[]=" --data-urlencode "price[]=99 000,50" -d "unit[]=ks" -d "vat[]=" -d "note[]=" \
  -d "item[]=$Q338_LASER" -d "variant[]=L338-Z" -d "code[]=" -d "label[]=" -d "price[]=" -d "unit[]=" -d "vat[]=" -d "note[]=" \
  -d new_code=MONT338 --data-urlencode "new_label=Montáž na místě" -d new_price=1500 -d new_unit=h -d new_vat=21 -d new_note=
expect "3.38: saving the grid writes the changed rows – the product, a variant in Czech notation, an own row; an empty price writes nothing" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(IFNULL(item_id, 'own'), ':', variant_code, ':', code, ':', unit_price, ':', IFNULL(vat_rate, '-'), ':', unit) ORDER BY item_id IS NULL, variant_code SEPARATOR ' ') FROM ka_prices WHERE price_list_id = $Q338_LIST")" \
  "$Q338_LASER::L338:$Q338_CANARY:21.00:ks $Q338_LASER:L338-M:L338-M:99000.50:-:ks own::MONT338:1500.00:21.00:h"
expect "3.38: the change log names the list and a count, never an amount" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'quotes' AND (popis LIKE '%98765%' OR popis LIKE '%99000%' OR popis LIKE '%1500%')")|$(sq "SELECT COUNT(*) > 0 FROM ka_protokol WHERE modul = 'quotes'")" "0|1"
# the item box: with the section, its own query; editable with the area (administrators have both)
check "3.38: the item form has the box Internal prices with the saved price for an administrator" 200 "/admin.php?module=collections&action=item&id=$Q338_IDK&item=$Q338_LASER" "value=\"$Q338_CANARY\""
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=item_prices_save" -d "_csrf=$(csrf)" -d idk=$Q338_IDK -d idp=$Q338_TANK \
  -d "list[]=$Q338_LIST" -d "item[]=$Q338_TANK" -d "variant[]=" -d "price[]=4500" -d "unit[]=ks" -d "vat[]=12" -d "note[]="
expect "3.38: the item box saves the tank's price" "$(sq "SELECT CONCAT(unit_price, '|', vat_rate) FROM ka_prices WHERE item_id = $Q338_TANK AND price_list_id = $Q338_LIST")" "4500.00|12.00"
# CSV: export, then an import checked into a preview before it writes
curl -s -b "$JAR" -c "$JAR" -o "$WORK/q338.csv" "$B/admin.php?module=quotes&action=prices_export&list=$Q338_LIST"
expect "3.38: the CSV export has the header and the rows (code, variant, price …, name)" "$(head -1 "$WORK/q338.csv" | tr -d '\357\273\277\r')|$(grep -c "^L338;L338-M;99000.50;ks;;;" "$WORK/q338.csv")|$(grep -c '^MONT338;;1500.00;h;21;;' "$WORK/q338.csv")" \
  "code;variant;price;unit;vat;note;name|1|1"
printf 'code;variant;price;vat;name\nT338;;4 700,00;12;\nL338;L338-Z;88000;;\nNEZNAMY-338;;10;;\nDOPRAVA338;;350;21;Doprava\nT338;;abc;;\n' > "$WORK/q338-import.csv"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=prices_import" -F "_csrf=$(csrf)" -F list=$Q338_LIST -F "csv=@$WORK/q338-import.csv;type=text/csv"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=prices&list=$Q338_LIST&import=1"
grep -q 'data-prices-import-preview' "$WORK/response" && grep -q 'NEZNAMY-338' "$WORK/response" && [ "$(sq "SELECT unit_price FROM ka_prices WHERE item_id = $Q338_TANK AND price_list_id = $Q338_LIST")" = "4500.00" ] \
  && echo "  ok     3.38: a CSV import is a preview first – unknown codes named, nothing written yet" || { echo "  CHYBA  3.38: the import preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=prices_import_run" -d "_csrf=$(csrf)" -d list=$Q338_LIST
expect "3.38: the confirmed import changes the tank, prices a variant, adds an own row with a name; the unknown code and the bad price are skipped" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(code, '=', unit_price) ORDER BY code SEPARATOR ' ') FROM ka_prices WHERE price_list_id = $Q338_LIST AND code IN ('T338', 'L338-Z', 'DOPRAVA338', 'NEZNAMY-338')")" \
  "DOPRAVA338=350.00 L338-Z=88000.00 T338=4700.00"

# MCP: list_prices reads, save_price writes a batch and returns the previous values; a drafts-only connection cannot
mcp list_prices "{\"collection\":\"stroje-338\"}" > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/q338.json"
grep -q "\"item\":$Q338_LASER" "$WORK/q338.json" && grep -q "\"unit_price\":\"$Q338_CANARY\"" "$WORK/q338.json" && grep -q '"currency":"CZK"' "$WORK/q338.json" && grep -q '"code":"MONT338"' "$WORK/q338.json" \
  && echo "  ok     3.38 MCP: list_prices returns the products with their prices, the own rows and the list" || { echo "  CHYBA  3.38 MCP list_prices: $(head -c 400 "$WORK/q338.json")"; ERRORS=$((ERRORS+1)); }
mcp save_price '{"prices":[{"code":"T338","unit_price":"4800"},{"item":'"$Q338_LASER"',"variant":"L338-Z","unit_price":null}]}' > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/q338.json"
grep -q '"action":"changed"' "$WORK/q338.json" && grep -q '"before":{"unit_price":"4700.00"' "$WORK/q338.json" && grep -q '"action":"removed"' "$WORK/q338.json" \
  && [ "$(sq "SELECT GROUP_CONCAT(unit_price) FROM ka_prices WHERE price_list_id = $Q338_LIST AND code IN ('T338', 'L338-Z')")" = "4800.00" ] \
  && echo "  ok     3.38 MCP: save_price saves a batch by code and item, removes with null and returns the previous values" || { echo "  CHYBA  3.38 MCP save_price: $(head -c 400 "$WORK/q338.json")"; ERRORS=$((ERRORS+1)); }
Q338_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'"); Q338_DRAFTS="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ($Q338_ADMIN, 'q338 drafts', 'drafts', 'all', SHA2('$Q338_DRAFTS', 256), '$(site_time)')" > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $Q338_DRAFTS" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"save_price","arguments":{"code":"T338","unit_price":"1"}}}' > "$WORK/response"
expect "3.38 MCP: a drafts-only connection cannot save a price" "$(grep -c '"isError":true\|"error"' "$WORK/response")|$(sq "SELECT unit_price FROM ka_prices WHERE price_list_id = $Q338_LIST AND code = 'T338'")" "1|4800.00"

# never public (design A4): the canary price in no page, list, comparison, sitemap, search, feed, llms.txt, export, blueprint,
# report, heartbeat, change log or MCP tool other than the two price tools
Q338_LEAKS=""
for q338_path in "/stroje-338/laser-338" "/stroje-338" "/stroje-338/_compare?i=laser-338,nadrz-338" "/sitemap.xml" "/llms.txt" "/hledani?q=Laser" "/feed.json" "/robots.txt"; do
  curl -s -o "$WORK/q338-page" "$B$q338_path"; grep -q "98765\|99000" "$WORK/q338-page" && Q338_LEAKS="$Q338_LEAKS $q338_path"
done
for q338_tool in 'list_collection_items {"collection":"stroje-338"}' 'list_item_versions {"item":'"$Q338_LASER"'}' 'list_changes {}' 'get_stats {}' 'site_info {}' 'list_collections {}'; do
  mcp ${q338_tool%% *} "${q338_tool#* }" > "$WORK/q338-page"; grep -q "98765\|99000" "$WORK/q338-page" && Q338_LEAKS="$Q338_LEAKS mcp:${q338_tool%% *}"
done
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
  $e = Kaleta\Core\SiteExport::create($app->db(), $app->settings()); $f = Kaleta\Core\Backup::FOLDER . "/" . basename($e["soubor"]);
  if (str_ends_with($f, ".zip")) { $z = new ZipArchive(); $z->open($f); $j = (string) $z->getFromName("obsah.json"); $z->close(); } else { $j = (string) file_get_contents($f); }
  @unlink($f); echo str_contains($j, "98765") || str_contains($j, "99000") ? "export " : "";
  $b = json_encode(Kaleta\Core\Blueprint::export($app, "q338", "Q338")); echo str_contains((string) $b, "98765") ? "blueprint " : "";
  $r = json_encode(Kaleta\Core\MonthlyReport::build($app, new DateTimeImmutable("first day of this month"))); echo str_contains((string) $r, "98765") || str_contains((string) $r, "99000") ? "report " : "";
  $h = json_encode(Kaleta\Fleet\Heartbeat::build($app)); echo str_contains((string) $h, "98765") ? "heartbeat " : "";
  echo str_contains((string) $h, "\"quotes\"") ? "" : "heartbeat-without-the-feature ";' > "$WORK/q338-page" 2>&1) || true
Q338_LEAKS="$Q338_LEAKS$(tr -d '\n' < "$WORK/q338-page")"
expect "3.38 A4: the internal price is in no public response, export, blueprint, report, heartbeat (it names only the feature) or other MCP tool" "${Q338_LEAKS// /}" ""

# a colleague without the section sees no price; with the section but without the area reads and cannot save
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" > /dev/null
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, admin, blokovat) VALUES ('cenik-338', '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")', 'Cena Kolegyně', 'cenik338@example.cz', 1, 0)" > /dev/null
Q338_USER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'cenik-338'"); Q338_USER="${Q338_USER:-0}"
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($Q338_USER, 'collections')" > /dev/null
JAR_Q338="$WORK/jar-q338"; rm -f "$JAR_Q338"
Q338_CSRF=$(curl -s -c "$JAR_Q338" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR_Q338" -c "$JAR_Q338" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$Q338_CSRF" -d user=cenik-338 --data-urlencode "password=$PASSWORD"
q338_get() { curl -s -b "$JAR_Q338" -c "$JAR_Q338" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?$1"; }
expect "3.38: without the section – 403 on Quotes and no price box or price in the item form" \
  "$(q338_get "module=quotes&action=prices")|$(q338_get "module=collections&action=item&id=$Q338_IDK&item=$Q338_LASER"; printf ' '; grep -c 'data-internal-prices\|98765' "$WORK/response")" "403|200 0"
sq "INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) VALUES ($Q338_USER, 'quotes')" > /dev/null
expect "3.38: with the section, without the area – the box and the grid are read-only" \
  "$(q338_get "module=collections&action=item&id=$Q338_IDK&item=$Q338_LASER"; printf ' '; grep -c "value=\"$Q338_CANARY\" aria-label=\"[^\"]*\" readonly" "$WORK/response")|$(q338_get "module=quotes&action=prices"; printf ' '; grep -c 'data-prices-readonly' "$WORK/response")" "200 1|200 1"
Q338_CSRF=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//')
expect "3.38: without the area saving a price is refused" "$(curl -s -b "$JAR_Q338" -c "$JAR_Q338" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=quotes&action=prices_save" -d "_csrf=$Q338_CSRF" -d list=$Q338_LIST \
  -d "item[]=$Q338_TANK" -d "variant[]=" -d "code[]=" -d "label[]=" -d "price[]=1" -d "unit[]=" -d "vat[]=" -d "note[]=")|$(sq "SELECT unit_price FROM ka_prices WHERE price_list_id = $Q338_LIST AND code = 'T338'")" "403|4800.00"

# the core of quotes (Core\Quotes, no screens yet): from an enquiry's basket, totals, numbering, statuses, revisions, the deal value
cat > "$WORK/q338.php" <<'PHP'
<?php
require 'system/bootstrap.php';
$app = new Kaleta\Core\App(require 'config.php');
$app->applyTimezone();
use Kaleta\Core\Quotes;
$db = $app->db();
[$admin, $laser, $tank, $year, $list] = [(int) $argv[1], (int) $argv[2], (int) $argv[3], $argv[4], (int) $argv[5]];
$bad = 0;
$check = function (string $label, mixed $got, mixed $want) use (&$bad): void {
    if ($got === $want) { echo "  ok     3.38 Quotes: $label\n"; return; }
    $bad++; echo "  CHYBA  3.38 Quotes: $label: got " . json_encode($got, JSON_UNESCAPED_UNICODE) . ', expected ' . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
};
$try = function (callable $f): string { try { $f(); return 'ok'; } catch (\DomainException $e) { return 'refused'; } };
$app->settings()->set('quote_vat_rate', '21');
$contact = $db->insert('contacts', ['email' => 'kupec338@example.cz', 'email_norm' => 'kupec338@example.cz', 'name' => 'Karel Kupec', 'source' => 'manual', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
$enquiry = $db->insert('poptavky', ['datum' => date('Y-m-d H:i:s'), 'formular' => 'Poptávka 338', 'tema' => 'Stroje 338 – Laser 338', 'email' => 'kupec338@example.cz', 'contact_id' => $contact, 'stav' => 0,
    'data' => json_encode([['Košík', "3 × Laser 338 – Modrý (L338-M)\n1 × Nádrž 338 (T338)\n2 × Neznámý stroj"], ['Jméno', 'Karel']], JSON_UNESCAPED_UNICODE)]);
$eur = Kaleta\Core\Prices::saveList($app, null, 'EUR 338', 'EUR');
$deal = Kaleta\Core\Deals::save($app, null, ['title' => 'Laser pro Karla', 'source_enquiry_id' => $enquiry, 'contact_id' => $contact], $admin);
$r = Quotes::save($app, null, ['enquiry_id' => $enquiry], $admin);
$q = Quotes::get($db, $r['id']);
$check('a quote of an enquiry takes its customer, its open deal and its basket – matched by code, the unknown line without a price',
    [$q['status'], $q['number'], $q['contact_id'], $q['deal_id'], $q['customer']['name'], $q['customer']['email'], $q['currency'], $q['title'],
     array_map(fn (array $l): string => $l['code'] . ':' . $l['quantity'] . ':' . ($l['unit_price'] ?? 'none') . ':' . $l['vat_rate'], $q['lines']), $q['totals']['unpriced']],
    ['draft', null, $contact, $deal, 'Karel Kupec', 'kupec338@example.cz', 'CZK', 'Stroje 338 – Laser 338', ['L338-M:3.000:99000.50:21.00', 'T338:1.000:4800.00:12.00', ':2.000:none:21.00'], 1]);
$check('the server computes the totals and refuses to send while a line has no price', [$q['totals']['net'], $q['totals']['vat'], $q['totals']['gross'], $try(fn () => Quotes::finalise($app, $q['id'], $admin))],
    ['301801.50', '62946.32', '364747.82', 'refused']);
$lines = $q['lines'];
$lines[2]['unit_price'] = '250';
$lines[0]['discount_percent'] = '5';
$r = Quotes::save($app, $q['id'], ['lines' => $lines, 'discount_percent' => '10', 'expected_updated_at' => '2000-01-01 00:00:00'], $admin);
$q = Quotes::get($db, $q['id']);
$check('a person fills the price, a line discount and a whole-quote discount; a stale form is warned about; manual prices marked',
    [$q['totals']['net'], $q['totals']['unpriced'], $q['manual_prices'], $q['lines'][2]['manual'], $q['lines'][0]['manual'], count($r['warnings']) >= 1], ['258706.29', 0, true, true, false, true]);
$check('Claude cannot save a discount above quote_max_discount, nor a price for a product the list has none for', [
    $try(fn () => Quotes::save($app, $q['id'], ['discount_percent' => '25'], $admin, true)),
    Quotes::get($db, Quotes::save($app, null, ['price_list_id' => $eur, 'lines' => [['item_id' => $laser, 'unit_price' => '1']], 'customer' => ['name' => 'X']], $admin, true)['id'])['lines'][0]['unit_price'],
    Quotes::get($db, Quotes::save($app, null, ['lines' => [['item_id' => $laser, 'variant_code' => 'L338-Z', 'unit_price' => '1']], 'customer' => ['name' => 'X']], $admin, true)['id'])['lines'][0]['manual']],
    ['refused', null, true]);
$sent = Quotes::finalise($app, $q['id'], $admin);
$check('Finalise assigns the first number of the year, freezes the snapshot and the history', [$sent['number'], $sent['status'], $sent['snapshot']['number'] ?? null,
    $sent['snapshot']['lines'][0]['unit_price'] ?? null, array_column($sent['history'], 'action')], [$year . '-0001', 'sent', $year . '-0001', '99000.50', ['created', 'finalised']]);
$check('a sent quote is frozen', $try(fn () => Quotes::save($app, $q['id'], ['title' => 'x'], $admin)), 'refused');
Kaleta\Core\Prices::save($app, $list, ['item_id' => $laser, 'variant_code' => 'L338-M', 'unit_price' => '1'], $admin);
$check('a later price change never alters a sent quote', [Quotes::get($db, $q['id'])['lines'][0]['unit_price'], Quotes::get($db, $q['id'])['snapshot']['lines'][0]['unit_price']], ['99000.50', '99000.50']);
$check('the deal takes the NET total of the quote (A-10), once', [Quotes::setDealValue($app, $q['id'], $admin), Kaleta\Core\Deals::get($db, $deal)['value'], Quotes::setDealValue($app, $q['id'], $admin)],
    [true, '258706.29', false]);
$check('statuses: declined needs a reason, accepted and back to sent, history kept', [$try(fn () => Quotes::setStatus($app, $q['id'], 'declined', $admin)),
    Quotes::setStatus($app, $q['id'], 'accepted', $admin), Quotes::setStatus($app, $q['id'], 'accepted', $admin), Quotes::setStatus($app, $q['id'], 'sent', $admin),
    Quotes::setStatus($app, $q['id'], 'declined', $admin, 'price'), Quotes::get($db, $q['id'])['decline_reason'], count(Quotes::get($db, $q['id'])['history'])], ['refused', true, false, true, true, 'price', 5]);
Quotes::setStatus($app, $q['id'], 'sent', $admin);
$second = Quotes::save($app, null, ['contact_id' => $contact, 'lines' => [['code' => 'MONT338', 'quantity' => '2']]], $admin)['id'];
$check('an own row by its code; the next number follows', [Quotes::get($db, $second)['lines'][0]['name'], Quotes::get($db, $second)['lines'][0]['unit_price'], Quotes::finalise($app, $second, $admin)['number']],
    ['Montáž na místě', '1500.00', $year . '-0002']);
$check('a series continues: raiseCounter only goes up', [Quotes::raiseCounter($db, 50), Quotes::raiseCounter($db, 10), Quotes::nextNumber($db, $app->settings())], [true, false, $year . '-0050']);
$revision = Quotes::revise($app, $q['id'], $admin);
$rev = Quotes::markSent($app, $revision, $admin, null);
$check('a revision gets the number /2 when sent and the original expires as replaced', [$rev['number'], $rev['revision_of'], Quotes::get($db, $q['id'])['status'], end(Quotes::get($db, $q['id'])['history'])['action'] ?? null],
    [$year . '-0001/2', $q['id'], 'expired', 'replaced']);
$check('the boxes of the records: the quotes of the enquiry, the deal and the contact', [count(Quotes::of($db, 'enquiry', $enquiry)), count(Quotes::of($db, 'deal', $deal)), count(Quotes::of($db, 'contact', $contact)) >= 3], [2, 2, true]);
$check('deleting: a numbered quote only by an administrator', [$try(fn () => Quotes::delete($app, $second, $admin + 100000, false)), $try(fn () => Quotes::delete($app, $second, $admin, true)), Quotes::get($db, $second)], ['refused', 'ok', null]);
$check('the change log names quotes by their number, never an amount', (int) $db->value("SELECT COUNT(*) FROM {protokol} WHERE modul = 'quotes' AND (popis LIKE '%258%' OR popis LIKE '%99000%')"), 0);
foreach ([$q['id'], $revision] as $id) { Quotes::delete($app, $id, $admin, true); }
foreach ($db->all('SELECT id FROM {quotes} WHERE customer_name = ?', ['X']) as $row) { Quotes::delete($app, (int) $row['id'], $admin, true); }
$db->run('DELETE FROM {price_lists} WHERE id = ?', [$eur]);
$db->run('DELETE FROM {deals} WHERE id = ?', [$deal]);
$db->run('DELETE FROM {deal_stage_history} WHERE deal_id = ?', [$deal]);
$db->run('DELETE FROM {poptavky} WHERE idp = ?', [$enquiry]);
$db->run('DELETE FROM {contacts} WHERE id = ?', [$contact]);
$app->settings()->set('quote_vat_rate', '');
echo "Q338_BAD=$bad\n";
PHP
(cd "$WORK/web" && php "$WORK/q338.php" "$Q338_ADMIN" "$Q338_LASER" "$Q338_TANK" "$Q338_YEAR" "$Q338_LIST") > "$WORK/q338.out" 2>&1 || true
grep -v '^Q338_BAD=' "$WORK/q338.out"
Q338_BAD=$(grep -o '^Q338_BAD=[0-9]*' "$WORK/q338.out" | cut -d= -f2); [ -n "$Q338_BAD" ] || { echo "  CHYBA  3.38 Quotes: the script did not finish"; Q338_BAD=1; }
ERRORS=$((ERRORS+Q338_BAD))

# clean up: the feature back as it was, the colleague and the token gone (prices and collection stay for later sections)
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $Q338_USER; DELETE FROM ka_uzivatele WHERE idu = $Q338_USER; DELETE FROM ka_api_tokeny WHERE nazev = 'q338 drafts';
  DELETE FROM ka_quote_counters; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$Q338_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
