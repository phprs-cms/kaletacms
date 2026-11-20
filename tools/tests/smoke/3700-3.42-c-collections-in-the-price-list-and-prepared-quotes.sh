echo "== 3.42 C: any collection feeds the internal price list; enquiries about its items lead to a prepared quote"
C342_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
case ",$C342_EXT," in *,quotes,*) ;; *) features +quotes ;; esac
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_vat_rate', '21'), ('quote_vat_payer', '1')" > /dev/null
C342_CANARY='87654.32'
# a B2B site's own collection (not the products preset): an order code, versions written as variants, a description
mcp create_collection '{"name":"Produkty 342","slug":"produkty-342","item_pages":true,"fields":[{"label":"Kod","type":"text"},{"label":"Provedeni","type":"lines"},{"label":"Popis","type":"html"}]}' > /dev/null
C342_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty-342'"); C342_IDK="${C342_IDK:-0}"
mcp save_collection_item '{"collection":"produkty-342","name":"Rázová vlna X1","slug":"razova-vlna-x1","values":{"kod":"RV-X1","provedeni":"Mobilní | RV-X1-M\nStacionární | RV-X1-S"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-342","name":"Lehátko L2","slug":"lehatko-l2","values":{"kod":"LH-L2"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-342","name":"Gel G3","slug":"gel-g3","values":{},"visible":true}' > /dev/null
c342_item() { local v; v=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $C342_IDK AND seo_link = '$1'"); echo "${v:-0}"; }
C342_X1=$(c342_item razova-vlna-x1); C342_L2=$(c342_item lehatko-l2); C342_G3=$(c342_item gel-g3)
C342_LIST=$(sq "SELECT id FROM ka_price_lists WHERE is_default = 1 AND archived_at IS NULL ORDER BY id LIMIT 1"); C342_LIST="${C342_LIST:-0}"

# before the mapping: not a products collection – not in the grid, no box on the item form
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=prices&list=$C342_LIST&search=342"
c342_before_grid=$(grep -c "data-price-row=\"$C342_X1:" "$WORK/response" || true)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$C342_IDK&item=$C342_X1"
expect "3.42: an unmapped collection is not in the price list and its items have no Internal prices box" "$c342_before_grid|$(grep -c 'data-internal-prices' "$WORK/response")" "0|0"

# the mapping in Quotes → Price list set-up (3.45 U62, was Settings): any collection, its code field, its variants field (only suitable types are kept)
check "3.42: Quotes → Price list set-up offers every collection for the price list" 200 "/admin.php?module=quotes&action=setup" "data-price-collection=\"$C342_IDK\""
C342_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=collections_save" -d "_csrf=$C342_CSRF" -d "collection[]=$C342_IDK" -d "code_$C342_IDK=popis" -d "variants_$C342_IDK=kod"
expect "3.42: a code or variants field of an unsuitable type is not kept (html is no code, a short text no variants)" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quote_price_collections'")" "[{\"collection\":$C342_IDK,\"code\":\"\",\"variants\":\"\"}]"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/c342-headers" -X POST "$B/admin.php?module=quotes&action=collections_save" -d "_csrf=$C342_CSRF" -d back=prices -d "collection[]=$C342_IDK" -d "code_$C342_IDK=kod" -d "variants_$C342_IDK=provedeni"
expect "3.42: the mapping is saved as a setting and the form returns to where it was sent from" \
  "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'quote_price_collections'")|$(grep -ci '^location:.*action=prices#price-collections' "$WORK/c342-headers")" "[{\"collection\":$C342_IDK,\"code\":\"kod\",\"variants\":\"provedeni\"}]|1"

# the prefilled price list: every item with an empty price, variants as sub-rows, "missing price", the item box
check "3.42: Internal prices lists every item of the mapped collection with its variants" 200 "/admin.php?module=quotes&action=prices&list=$C342_LIST&collection=$C342_IDK" "data-price-row=\"$C342_X1:RV-X1-M\""
expect "3.42: the grid shows the items without a price (the code field, the address for an item without a code) and links to what is missing" \
  "$(grep -c "data-price-row=\"$C342_L2:\"" "$WORK/response")|$(grep -c "data-price-row=\"$C342_G3:\"" "$WORK/response")|$(grep -c '<bdi>gel-g3</bdi>' "$WORK/response")|$(grep -c 'data-prices-missing' "$WORK/response")" "1|1|1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=prices_save" -d "_csrf=$(csrf)" -d list=$C342_LIST -d collection=$C342_IDK \
  -d "item[]=$C342_X1" -d "variant[]=" -d "code[]=" -d "label[]=" --data-urlencode "price[]=$C342_CANARY" -d "unit[]=ks" -d "vat[]=" -d "note[]=" \
  -d "item[]=$C342_X1" -d "variant[]=RV-X1-S" -d "code[]=" -d "label[]=" -d "price[]=91000" -d "unit[]=ks" -d "vat[]=" -d "note[]="
expect "3.42: prices of a mapped item and its variant are saved under its code – in the price tables only, never in the item" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(variant_code, ':', code, ':', unit_price) ORDER BY variant_code SEPARATOR ' ') FROM ka_prices WHERE price_list_id = $C342_LIST AND item_id = $C342_X1")|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idk = $C342_IDK AND data LIKE '%87654%'")" \
  ":RV-X1:$C342_CANARY RV-X1-S:RV-X1-S:91000.00|0"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=quotes&action=prices&list=$C342_LIST&collection=$C342_IDK&missing=1"
expect "3.42: the filter Without a price leaves out what has every price and keeps what still needs one" \
  "$(grep -c "data-price-row=\"$C342_L2:\"" "$WORK/response")|$(grep -c "data-price-row=\"$C342_X1:RV-X1-M\"" "$WORK/response")" "1|1"
check "3.42: the item form of a mapped collection has the Internal prices box with its variants" 200 "/admin.php?module=collections&action=item&id=$C342_IDK&item=$C342_X1" "data-item-price=\"$C342_LIST:RV-X1-S\""
# CSV: the unpriced items come out with an empty price to fill in, and go back in
curl -s -b "$JAR" -c "$JAR" -o "$WORK/c342.csv" "$B/admin.php?module=quotes&action=prices_export&list=$C342_LIST"
expect "3.42: the CSV export has the priced rows and every item still without a price (an empty price to fill in)" \
  "$(grep -c "^RV-X1;;$C342_CANARY;ks;" "$WORK/c342.csv")|$(grep -c '^LH-L2;;;;;;"Lehátko L2"' "$WORK/c342.csv")|$(grep -c '^RV-X1;RV-X1-M;;;;;"Rázová vlna X1 – Mobilní"' "$WORK/c342.csv")|$(grep -c '^gel-g3;;;;;;"Gel G3"' "$WORK/c342.csv")" "1|1|1|1"
printf 'code;variant;price;unit;vat;note;name\nLH-L2;;12 500;ks;;;Lehátko L2\ngel-g3;;450;tuba;12;;Gel G3\nRV-X1;RV-X1-M;;;;;Rázová vlna X1 – Mobilní\n' > "$WORK/c342-import.csv"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=prices_import" -F "_csrf=$(csrf)" -F list=$C342_LIST -F "csv=@$WORK/c342-import.csv;type=text/csv"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=prices_import_run" -d "_csrf=$(csrf)" -d list=$C342_LIST
expect "3.42: the filled-in CSV goes back in – by the code field and by the address; an empty price stays empty" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(code, '=', unit_price) ORDER BY code SEPARATOR ' ') FROM ka_prices WHERE price_list_id = $C342_LIST AND item_id IN ($C342_L2, $C342_G3)")|$(sq "SELECT COUNT(*) FROM ka_prices WHERE item_id = $C342_X1 AND variant_code = 'RV-X1-M'")" \
  "gel-g3=450.00 LH-L2=12500.00|0"
# MCP: list_prices covers the mapped collection (additive keys: collections, without_price)
mcp list_prices '{"collection":"produkty-342"}' > "$WORK/response"; mcp_text; cp "$WORK/text" "$WORK/c342.json"
expect "3.42 MCP: list_prices lists the mapped collection's items with their codes and prices, names the collections and counts what is still without a price" \
  "$(php -r '$j = json_decode(file_get_contents($argv[1]), true); $p = array_column($j["products"] ?? [], null, "item"); $c = array_column($j["collections"] ?? [], null, "id");
    echo ($p[(int) $argv[2]]["code"] ?? "-"), "|", ($p[(int) $argv[2]]["unit_price"] ?? "-"), "|", ($p[(int) $argv[3]]["code"] ?? "-"), "|", ($c[(int) $argv[4]]["source"] ?? "-"), "|", ($j["without_price"] ?? "-");' -- "$WORK/c342.json" "$C342_X1" "$C342_G3" "$C342_IDK")" \
  "RV-X1|$C342_CANARY|gel-g3|mapped|1"

# enquiries → a prepared quote (Core\Quotes::linesFromEnquiry): (a) sent from the item's own page, (b) a field naming items
C342_NOW=$(site_time)
sq "INSERT INTO ka_contacts (email, email_norm, name, source, owner_id, created_at, updated_at) VALUES ('nakup342@example.cz', 'nakup342@example.cz', 'Nora Nákup', 'manual', NULL, '$C342_NOW', '$C342_NOW')" > /dev/null
C342_CONTACT=$(sq "SELECT id FROM ka_contacts WHERE email = 'nakup342@example.cz'"); C342_CONTACT="${C342_CONTACT:-0}"
sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, tema, email, contact_id, stav, data) VALUES ('$C342_NOW', 'Poptávka 342a', 'kolekce:$C342_IDK', '/produkty-342/razova-vlna-x1', 'Produkty 342 – Rázová vlna X1', 'nakup342@example.cz', $C342_CONTACT, 0, '[[\"Jméno\",\"Nora\"],[\"Zpráva\",\"Prosím o cenu a termín.\"]]')" > /dev/null
C342_ENQ_A=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Poptávka 342a'"); C342_ENQ_A="${C342_ENQ_A:-0}"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=quotes&action=create" -d "_csrf=$C342_CSRF" -d enquiry=$C342_ENQ_A
C342_QA=$(sq "SELECT MAX(id) FROM ka_quotes WHERE enquiry_id = $C342_ENQ_A"); C342_QA="${C342_QA:-0}"
expect "3.42: New quote on an enquiry sent from a mapped item's page arrives with that item, quantity 1, priced from the default list" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', quantity, ':', IFNULL(unit_price, '-')) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $C342_QA")" "RV-X1:1.000:$C342_CANARY"
# (b) a select filled from the collection, several ticked values; the customer's own price list (3.40) applies
C342_EUR=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
  $l = Kaleta\Core\Prices::saveList($app, null, "EUR 342", "EUR"); $me = (int) $argv[1];
  Kaleta\Core\Prices::save($app, $l, ["item_id" => (int) $argv[2], "unit_price" => "350"], $me); Kaleta\Core\Prices::save($app, $l, ["item_id" => (int) $argv[3], "variant_code" => "RV-X1-S", "unit_price" => "3600"], $me);
  Kaleta\Core\Quotes::setCustomerList($app, "contact", (int) $argv[4], $l, $me); echo $l;' -- "$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")" "$C342_L2" "$C342_X1" "$C342_CONTACT")
sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, contact_id, stav, data) VALUES ('$C342_NOW', 'Poptávka 342b', 'stranka:1', '/poptavka', 'nakup342@example.cz', $C342_CONTACT, 0, '[[\"Produkty\",\"lehátko l2, Rázová vlna X1 – Stacionární\"],[\"Kód\",\"neznámý-kód\"]]')" > /dev/null
C342_ENQ_B=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE formular = 'Poptávka 342b'"); C342_ENQ_B="${C342_ENQ_B:-0}"
mcp save_quote "{\"enquiry\":$C342_ENQ_B}" > "$WORK/response"; mcp_text
C342_QB=$(sq "SELECT MAX(id) FROM ka_quotes WHERE enquiry_id = $C342_ENQ_B"); C342_QB="${C342_QB:-0}"
expect "3.42 MCP: save_quote with an enquiry matches the items a field names (by name, case-insensitive, \"Name – Variant\") and prices them from the customer's list" \
  "$(sq "SELECT CONCAT(currency, '|', price_list_id) FROM ka_quotes WHERE id = $C342_QB")|$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', quantity, ':', IFNULL(unit_price, '-')) ORDER BY position SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $C342_QB")" \
  "EUR|$C342_EUR|LH-L2:1.000:350.00 RV-X1-S:1.000:3600.00"

# the automatic draft (off by default): a real form submission – prepared after the response, linked, one per enquiry, a bell without an amount
C342_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
expect "3.42: the automatic draft is off by default" "$(setting_plain quote_auto_draft)" "0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_auto_draft', '1')" > /dev/null
check "3.42: Quotes → Settings shows the automatic draft switched on" 200 "/admin.php?module=quotes&action=settings" 'name="quote_auto_draft" value="1" checked data-quotes-auto-draft'
sq "INSERT INTO ka_contacts (email, email_norm, name, source, owner_id, created_at, updated_at) VALUES ('fyzio342@example.cz', 'fyzio342@example.cz', 'Filip Fyzio', 'manual', $C342_ADMIN, '$C342_NOW', '$C342_NOW')" > /dev/null
# a page of its own with a plain enquiry form (a product field and an e-mail) – the visitor types the variant's code
mcp vytvor_stranku '{"titulek":"Poptavka 342","zobrazit":true}' > "$WORK/response"; mcp_text; C342_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":${C342_PAGE:-0},\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka 342\",\"pole\":[{\"popisek\":\"Produkt\",\"typ\":\"text\",\"povinne\":true},{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true}]}}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/$(sq "SELECT seo_link FROM ka_stranky WHERE ids = ${C342_PAGE:-0}")"
c342_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/formular.html" | head -1 | sed 's/.*value="//;s/"$//' || true; }
C342_SOURCE=$(c342_field zdroj); C342_ELEMENT=$(c342_field prvek); C342_TIME=$(( $(date +%s) - 60 ))
C342_SIG=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$C342_SOURCE|$C342_ELEMENT|$C342_TIME" "$(setting_plain secret_key)")
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
C342_SENT=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/form" -d "zdroj=$C342_SOURCE" -d "prvek=$C342_ELEMENT" -d zpet=/poptavka-342 -d "as_cas=$C342_TIME" -d "as_podpis=$C342_SIG" \
  -d p0=RV-X1-S -d p1=fyzio342@example.cz)
C342_ENQ_C=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE email = 'fyzio342@example.cz'"); C342_ENQ_C="${C342_ENQ_C:-0}"
C342_QC=$(sq "SELECT MAX(id) FROM ka_quotes WHERE enquiry_id = $C342_ENQ_C"); C342_QC="${C342_QC:-0}"
expect "3.42: a new enquiry naming a mapped product gets a DRAFT quote after the response – linked to its customer, the responsible person the contact's, the line priced, never sent" \
  "$(c342_r=${C342_SENT##*result=}; echo "${c342_r%%#*}")|$(sq "SELECT CONCAT(status, '|', IFNULL(number, '-'), '|', IFNULL(sent_at, '-'), '|', contact_id = (SELECT contact_id FROM ka_poptavky WHERE idp = $C342_ENQ_C), '|', owner_id) FROM ka_quotes WHERE id = $C342_QC")|$(sq "SELECT GROUP_CONCAT(CONCAT(code, ':', IFNULL(unit_price, '-')) SEPARATOR ' ') FROM ka_quote_lines WHERE quote_id = $C342_QC")" \
  "ok|draft|-|-|1|$C342_ADMIN|RV-X1-S:91000.00"
expect "3.42: the responsible person gets a bell that names the quote only – no amount, no excerpt" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', IFNULL(MAX(excerpt), '')) FROM ka_notifications WHERE user_id = $C342_ADMIN AND kind = 'quote_prepared' AND target = 'quote:$C342_QC'")" "1|"
check "3.42: the prepared draft is in the enquiry's Quotes box" 200 "/admin.php?module=enquiries&action=detail&id=$C342_ENQ_C" "data-quote=\"$C342_QC\""
cat > "$WORK/c342.php" <<'PHP'
<?php
require 'system/bootstrap.php';
$app = new Kaleta\Core\App(require 'config.php');
$app->applyTimezone();
use Kaleta\Core\Quotes;
$db = $app->db();
[$withDraft, $idk] = [(int) $argv[1], (int) $argv[2]];
$now = date('Y-m-d H:i:s');
$enquiry = fn (array $row): int => $db->insert('poptavky', $row + ['datum' => $now, 'formular' => 'Poptávka 342d', 'email' => 'd342@example.cz', 'stav' => 0]);
$out = [Quotes::prepareDraft($app, $withDraft) === null ? 'again:none' : 'again:twice', (int) $db->value('SELECT COUNT(*) FROM {quotes} WHERE enquiry_id = ?', [$withDraft])];
$spam = $enquiry(['kategorie' => 'spam', 'data' => json_encode([['Kód', 'LH-L2']])]);
$nothing = $enquiry(['data' => json_encode([['Zpráva', 'Dobrý den, máte katalog?']])]);
$page = $enquiry(['zdroj' => 'kolekce:' . $idk, 'stranka' => '/en/produkty-342/gel-g3?utm_source=x', 'data' => '[]']);
$out[] = Quotes::prepareDraft($app, $spam) === null ? 'spam:none' : 'spam:draft';
$out[] = Quotes::prepareDraft($app, $nothing) === null ? 'unmatched:none' : 'unmatched:draft';
$app->settings()->set('quote_auto_draft', '0');
$out[] = Quotes::prepareDraft($app, $page) === null ? 'off:none' : 'off:draft';
$app->settings()->set('quote_auto_draft', '1');
$id = Quotes::prepareDraft($app, $page);
$out[] = $id === null ? 'page:none' : 'page:' . implode(',', array_map(fn (array $l): string => $l['code'] . '=' . ($l['unit_price'] ?? '-'), Quotes::get($db, $id)['lines'])) . ':' . (Quotes::get($db, $id)['owner_id'] ?? 'nobody');
echo implode('|', $out);
PHP
expect "3.42: one draft per enquiry; spam, an enquiry naming no product and a switched-off setting get none; a page address with a language and a query still finds the item" \
  "$(cd "$WORK/web" && php "$WORK/c342.php" "$C342_ENQ_C" "$C342_IDK" 2>&1)" "again:none|1|spam:none|unmatched:none|off:none|page:gel-g3=450.00:nobody"

# never public (design A4): the mapped items' prices in no page, list, sitemap, search or feed of the site
C342_LEAKS=""
for c342_path in "/produkty-342/razova-vlna-x1" "/produkty-342" "/sitemap.xml" "/llms.txt" "/hledani?q=vlna" "/feed.json" "/produkty-342/razova-vlna-x1.md"; do
  curl -s -o "$WORK/c342-page" "$B$c342_path"; grep -q "87654\|91000\|12500" "$WORK/c342-page" && C342_LEAKS="$C342_LEAKS $c342_path"
done
mcp list_collection_items '{"collection":"produkty-342"}' > "$WORK/c342-page"; grep -q "87654\|91000" "$WORK/c342-page" && C342_LEAKS="$C342_LEAKS mcp:list_collection_items"
expect "3.42: the prices of a mapped collection reach no page of the site and no item tool" "${C342_LEAKS:-none}" "none"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('quote_auto_draft', '0')" > /dev/null
