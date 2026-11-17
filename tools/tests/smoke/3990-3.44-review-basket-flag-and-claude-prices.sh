echo "== 3.44 review: the basket flag of a mapped collection (M1), Claude's prices (M2), the address warning (L2), the marker on a quote's texts (L1)"
r344_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
R344_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
R344_MAP=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quote_price_collections'")
R344_VAT=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quote_vat_payer'), '-')")
r344_ext() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$1')" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
R344_ON=$(php -r '$e = array_filter(explode(",", $argv[1] !== "" ? $argv[1] : "novinky,poptavky,statistika,presmerovani,claude")); $e[] = "quotes"; echo implode(",", array_unique($e));' -- "$R344_EXT")
R344_OFF=$(php -r 'echo implode(",", array_diff(explode(",", $argv[1]), ["quotes"]));' -- "$R344_ON")
r344_ext "$R344_ON"

# (M1) a mapped collection without public item pages is not offered in the basket – unless the administrator says so; never
# while Quotes is off; the visitor's list shows the name, not the mapped code
mcp create_collection '{"name":"Interni 344","slug":"interni-344","fields":[{"label":"Kod","type":"text"}]}' > /dev/null
R344_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'interni-344'"); R344_IDK="${R344_IDK:-0}"
mcp save_collection_item '{"collection":"interni-344","name":"Tajny dil 344","slug":"tajny-dil-344","values":{"kod":"SKU-INT-344"},"visible":true}' > /dev/null
r344_map() { # <basket: default|on|off>: the mapping as it was plus interni-344 (the stored setting, as Quotes → Settings writes it)
  local json; json=$(php -r '$m = json_decode($argv[1], true) ?: []; $e = ["collection" => (int) $argv[2], "code" => "kod", "variants" => ""] + ($argv[3] === "default" ? [] : ["basket" => $argv[3] === "on"]); $m[] = $e; echo json_encode($m);' -- "$R344_MAP" "$R344_IDK" "$1")
  sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_price_collections', '$json')" > /dev/null
  rm -f "$WORK"/web/storage/cache/stranky/*.html
}
r344_basket() { curl -s "$B/produkty-342/gel-g3?product=interni-344/tajny-dil-344&quantity=2&r=$RANDOM" | { grep -o '<li>2 × Tajny dil 344[^<]*</li>' || true; } | head -1; }
r344_map default; R344_DEFAULT=$(r344_basket)
r344_map on; R344_FLAG=$(r344_basket)
r344_ext "$R344_OFF"; R344_QUOTES_OFF=$(r344_basket); r344_ext "$R344_ON"
expect "3.44 review M1: without item pages a mapped collection stays out of the basket; offered, the visitor sees the name without the code; never while Quotes is off" \
  "${R344_DEFAULT:-none}|${R344_FLAG:-none}|${R344_QUOTES_OFF:-none}" "none|<li>2 × Tajny dil 344</li>|none"
check "3.44 review M1: Quotes → Settings has the basket column with the box ticked for a collection with item pages" 200 "/admin.php?module=quotes&action=settings" 'name="basket_form" value="1"'
expect "3.44 review M1: … and the stored flag of the collection without item pages" \
  "$(grep -c "name=\"basket_$R344_IDK\" value=\"1\" checked" "$WORK/response")|$(sq "SELECT hodnota LIKE '%\"basket\":true%' FROM ka_nastaveni WHERE promenna = 'quote_price_collections'")" "1|1"

# (M2) Claude's prices: a free line named as a product, reverse charge from a VAT payer, another price list
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_payer', '1')" > /dev/null
R344_CHEAP=$(r344_php 'echo Kaleta\Core\Prices::saveList($app, null, "Levny 344", "CZK");' 2>&1 || true); [ "$R344_CHEAP" -gt 0 ] 2>/dev/null || echo "  (saveList answered: ${R344_CHEAP:0:300})"
r344_php 'Kaleta\Core\Prices::save($app, Kaleta\Core\Prices::defaultList($db, $app->settings())["id"], ["code" => "R344", "label" => "Service R344", "unit_price" => "5000"], 0); echo "ok";' > /dev/null 2>&1 || true
R344_QUOTES=$(sq "SELECT COUNT(*) FROM ka_quotes")
mcp save_quote '{"customer":{"name":"Rita 344","email":"rita344@example.cz"},"lines":[{"name":"Gel G3","quantity":"1","unit_price":"1"}]}' > "$WORK/r344-a"
mcp save_quote '{"customer":{"name":"Rita 344","email":"rita344@example.cz"},"lines":[{"name":"Service R344","quantity":"1","unit_price":"1","vat_rate":"0"}]}' > "$WORK/r344-b"
mcp save_quote '{"customer":{"name":"Rita 344","email":"rita344@example.cz"},"vat_mode":"reverse_charge","lines":[{"code":"R344","quantity":"1"}]}' > "$WORK/r344-c"
mcp save_quote "{\"customer\":{\"name\":\"Rita 344\",\"email\":\"rita344@example.cz\"},\"price_list\":\"$R344_CHEAP\",\"lines\":[]}" > "$WORK/r344-d"
expect "3.44 review M2: Claude's free line named as a product or an own row, reverse charge from a VAT payer and a list of its choice are refused – no quote saved" \
  "$(grep -c 'is a product of the price list' "$WORK/r344-a")|$(grep -c 'is a product of the price list' "$WORK/r344-b")|$(grep -c 'ask a person to set it in the admin' "$WORK/r344-c")|$(grep -c 'the customer.s price list or the default one' "$WORK/r344-d")|$(sq "SELECT COUNT(*) FROM ka_quotes")" \
  "1|1|1|1|$R344_QUOTES"
mcp save_quote '{"customer":{"name":"Rita 344","email":"rita344@example.cz"},"intro":"Hello Rita","lines":[{"code":"R344","quantity":"2"},{"name":"Travel 344","quantity":"1","unit_price":"300"}]}' > "$WORK/r344-e"
R344_Q=$(sq "SELECT MAX(id) FROM ka_quotes WHERE customer_email = 'rita344@example.cz'"); R344_Q="${R344_Q:-0}"
mcp get_quote "{\"id\":$R344_Q}" > "$WORK/response"; mcp_text
expect "3.44 review M2 + L1: a product by its code and a free line of its own still work; get_quote marks the intro Claude wrote" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', unit_price) ORDER BY position) FROM ka_quote_lines WHERE quote_id = $R344_Q")|$(php -r '$j = json_decode(file_get_contents($argv[1]), true); $u = $j["quote"]["untrusted"] ?? $j["untrusted"] ?? []; echo (int) in_array("intro", $u, true);' -- "$WORK/text")" \
  "R344:5000.00,:300.00|1"

# (L2) an address that came through a contact Claude saved: the Send page warns although the quote's history has no entry
mcp save_contact '{"name":"Rudolf 344","email":"rudolf344@example.cz"}' > /dev/null
R344_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'rudolf344@example.cz'"); R344_CONTACT="${R344_CONTACT:-0}"
R344_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
R344_QC=$(r344_php 'echo Kaleta\Core\Quotes::save($app, null, ["contact_id" => (int) $a[0], "lines" => [["code" => "R344", "quantity" => "1"]]], (int) $a[1])["id"];' "$R344_CONTACT" "$R344_ADMIN" 2>&1 || true); [ "$R344_QC" -gt 0 ] 2>/dev/null || echo "  (Quotes::save answered: ${R344_QC:0:300})"
check "3.44 review L2: the Send page warns about an address a Claude connection wrote into the customer record" 200 "/admin.php?module=quotes&action=send&id=${R344_QC:-0}" 'data-quote-email-by-claude'

# clean up: the quotes, the contact, the price, the list, the collection, the mapping, the VAT setting and the extensions as they were
sq "DELETE FROM ka_quote_lines WHERE quote_id IN (SELECT id FROM ka_quotes WHERE customer_email IN ('rita344@example.cz', 'rudolf344@example.cz'));
  DELETE FROM ka_quotes WHERE customer_email IN ('rita344@example.cz', 'rudolf344@example.cz'); DELETE FROM ka_prices WHERE code = 'R344';
  DELETE FROM ka_price_lists WHERE id = ${R344_CHEAP:-0}; DELETE FROM ka_contacts WHERE id = $R344_CONTACT;
  DELETE FROM ka_kolekce_polozky WHERE idk = $R344_IDK; DELETE FROM ka_kolekce WHERE idk = $R344_IDK" > /dev/null 2>&1 || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_price_collections', '$R344_MAP')" > /dev/null
if [ "$R344_VAT" = '-' ]; then sq "DELETE FROM ka_nastaveni WHERE promenna = 'quote_vat_payer'" > /dev/null; else sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_payer', '$R344_VAT')" > /dev/null; fi
r344_ext "$R344_EXT"
