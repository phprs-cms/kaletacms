echo "== 3.45 A: first quote, first week – the first steps of quotes (CPO 3), Quotes → Settings split (U62), the office's first steps and the move (CPO 6)"
a345_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db(); $a = array_slice($argv, 1); '"$1" -- "${@:2}"); }
a345_setting() { sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = '$1'), '')"; }
a345_set() { sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('$1', '$2')" > /dev/null; }
# a block of the page between its opening marker and the next </details>
a345_block() { php -r '$h = (string) file_get_contents($argv[1]); $p = strpos($h, $argv[2]); echo $p === false ? "" : substr($h, $p, strpos($h, "</details>", $p) - $p);' -- "$WORK/response" "$1"; }
A345_EXT=$(a345_setting extensions)
# the settings this section changes go back as they were at its end (a copy of their rows, dropped there)
A345_KEYS="'company_name', 'company_street', 'quotes_steps_hidden', 'office_steps_hidden', 'first_steps_hidden', 'email_signature_template', 'extensions'"
sq "DROP TABLE IF EXISTS ka_a345_backup; CREATE TABLE ka_a345_backup AS SELECT * FROM ka_nastaveni WHERE promenna IN ($A345_KEYS)" > /dev/null
A345_ON=$(php -r '$e = array_filter(explode(",", $argv[1] !== "" ? $argv[1] : "novinky,poptavky,statistika,presmerovani,claude")); $e[] = "quotes"; echo implode(",", array_unique($e));' -- "$A345_EXT")
a345_set extensions "$A345_ON"
a345_set quotes_steps_hidden 0; a345_set office_steps_hidden 0; a345_set first_steps_hidden 0

# CPO 3: the first steps of quotes lead the list for the administrator, with the six steps in order and their links
a345_set company_name ''
check "3.45 A CPO 3: the quote list opens with the first steps of quotes" 200 "/admin.php?module=quotes" 'class="pruvodce pruvodce-nabidky"'
A345_STEPS=$(a345_block 'class="pruvodce pruvodce-nabidky"')
expect "3.45 A CPO 3: six steps – company, VAT and numbering, the collection on Price list set-up, prices, mail, a test quote – and Hide for this list" \
  "$(grep -o '<li' <<< "$A345_STEPS" | wc -l | tr -d ' ')|$(grep -c 'module=business' <<< "$A345_STEPS")|$(grep -c 'action=setup#price-collections' <<< "$A345_STEPS")|$(grep -c 'name="list" value="quotes"' <<< "$A345_STEPS")" "6|1|1|1"
expect "3.45 A CPO 3: the company step is done once the document has a name and an address" \
  "$(a345_php 'echo (int) Kaleta\Admin\Modules\Quotes::setupFacts($app)["company"];')|$(a345_set company_name 'Firma 345'; a345_set company_street 'Ulice 3'; a345_php 'echo (int) Kaleta\Admin\Modules\Quotes::setupFacts($app)["company"];')" "0|1"
# Hide: the list's own setting, back to Quotes, the website's first steps untouched
curl -s -b "$JAR" -c "$JAR" -o /dev/null -D "$WORK/a345-headers" -X POST "$B/admin.php?action=hide_first_steps" -d "_csrf=$(csrf)" -d list=quotes
check "3.45 A CPO 3: after Hide the quote list has no first steps" 200 "/admin.php?module=quotes" 'data-quote-create'
expect "3.45 A CPO 3: Hide sets only the quotes' setting and returns to Quotes" \
  "$(a345_setting quotes_steps_hidden)|$(a345_setting first_steps_hidden)|$(grep -ci '^location:.*module=quotes' "$WORK/a345-headers")|$(grep -c 'pruvodce-nabidky' "$WORK/response")" "1|0|1|0"

# U62: Settings is one form (and who may open Quotes); the collections, the default texts and the line sets are on Price list set-up
check "3.45 A U62: Quotes → Settings is the one form" 200 "/admin.php?module=quotes&action=settings" 'data-quotes-settings'
expect "3.45 A U62: … without the collections, the default texts and the line sets; with who may open Quotes and the new tab" \
  "$(grep -c 'data-price-collections\|data-quotes-texts\|data-quotes-line-sets' "$WORK/response")|$(grep -c 'data-quotes-people' "$WORK/response")|$(grep -c 'href="[^"]*action=setup"' "$WORK/response")" "0|1|1"
check "3.45 A U62: Price list set-up holds the collections in the price list" 200 "/admin.php?module=quotes&action=setup" 'id="price-collections"'
expect "3.45 A U62: … the default texts, the line sets, its link current (3.48: a set-up link on the right, the hub tabs above), the heading still Quotes" \
  "$(grep -c 'data-quotes-texts' "$WORK/response")|$(grep -c 'data-quotes-line-sets' "$WORK/response")|$(grep -c 'action=setup" aria-current="page"' "$WORK/response")|$(grep -o '<h1[^>]*>[^<]*' "$WORK/response" | head -1 | sed 's/.*>//')" \
  "1|1|1|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=quotes&action=settings" | grep -o '<h1[^>]*>[^<]*' | head -1 | sed 's/.*>//')"
A345_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -D "$WORK/a345-headers" -X POST "$B/admin.php?module=quotes&action=collections_save" -d "_csrf=$A345_CSRF" -d basket_form=1 \
  $(php -r '$m = json_decode($argv[1], true) ?: []; foreach ($m as $e) { echo " -d collection[]=", (int) $e["collection"], " -d code_", (int) $e["collection"], "=", rawurlencode((string) $e["code"]), " -d variants_", (int) $e["collection"], "=", rawurlencode((string) $e["variants"]), !empty($e["basket"]) ? " -d basket_" . (int) $e["collection"] . "=1" : ""; }' -- "$(a345_setting quote_price_collections)")
A345_TEXTS=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -D - -X POST "$B/admin.php?module=quotes&action=texts_save" -d "_csrf=$A345_CSRF" --data-urlencode "quote_intro=$(a345_setting quote_intro)" --data-urlencode "quote_terms=$(a345_setting quote_terms)" --data-urlencode "quote_footer=$(a345_setting quote_footer)" | grep -ci '^location:.*action=setup')
expect "3.45 A U62: saving the collections and the default texts returns to Price list set-up" "$(grep -ci '^location:.*action=setup#price-collections' "$WORK/a345-headers")|$A345_TEXTS" "1|1"
# Internal prices: no mapping form of its own; one empty state with the next step when no collection feeds the price list, otherwise a link to the set-up
check "3.45 A U62: Internal prices has no mapping form of its own" 200 "/admin.php?module=quotes&action=prices" 'data-prices-grid'
A345_PRODUCTS=$(a345_php 'echo count(Kaleta\Core\Prices::productCollections($db));')
expect "3.45 A U62: … one empty state with the next step without products, otherwise the link to Price list set-up; the heading is Quotes" \
  "$(grep -c 'id="price-collections"' "$WORK/response")|$(grep -c 'class="prazdny-stav"' "$WORK/response")|$(grep -c 'data-price-collections-link' "$WORK/response")|$(grep -o '<h1[^>]*>[^<]*' "$WORK/response" | head -1 | sed 's/.*>//')" \
  "0|$([ "$A345_PRODUCTS" = 0 ] && echo 1 || echo 0)|$([ "$A345_PRODUCTS" = 0 ] && echo 0 || echo 1)|$(curl -s -b "$JAR" -c "$JAR" "$B/admin.php?module=quotes" | grep -o '<h1[^>]*>[^<]*' | head -1 | sed 's/.*>//')"

# CPO 6: the office's first steps on the dashboard once an enquiry exists – the administrator without a position has the signature step open
A345_ME=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A345_POSITION=$(sq "SELECT COALESCE(pozice, '') FROM ka_uzivatele WHERE idu = ${A345_ME:-0}")
[ -n "$(sq "SELECT idp FROM ka_poptavky WHERE kategorie <> 'spam' LIMIT 1")" ] || sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES (NOW(), 'Office 345', 'office345@example.cz', '[]', 0)" > /dev/null
sq "UPDATE ka_uzivatele SET pozice = '' WHERE idu = ${A345_ME:-0}" > /dev/null; a345_set email_signature_template ''
A345_FACTS=$(a345_php '$f = Kaleta\Admin\Kernel::officeFacts($app, ["enquiries" => "x"], $db->one("SELECT * FROM {uzivatele} WHERE idu = ?", [(int) $a[0]]) ?? []); echo $f === null ? "none" : implode(",", array_map("intval", $f));' "$A345_ME")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
A345_OFFICE=$(a345_block 'class="pruvodce pruvodce-kancelar"')
expect "3.45 A CPO 6: the office's first steps – four, the signature step open while the account has no position (or a Team record), Hide for this list" \
  "$([ "${A345_FACTS#*,}" != "${A345_FACTS}" ] && [ "$(cut -d, -f2 <<< "$A345_FACTS")" = 0 ] && echo shown || echo "facts $A345_FACTS")|$(grep -o '<li' <<< "$A345_OFFICE" | wc -l | tr -d ' ')|$(grep -c 'tab=signature' <<< "$A345_OFFICE")|$(grep -c 'name="list" value="office"' <<< "$A345_OFFICE")" \
  "shown|4|1|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -D "$WORK/a345-headers" -X POST "$B/admin.php?action=hide_first_steps" -d "_csrf=$(csrf)" -d list=office
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.45 A CPO 6: Hide puts away only the office's list" "$(a345_setting office_steps_hidden)|$(a345_setting first_steps_hidden)|$(grep -c 'pruvodce-kancelar' "$WORK/response")" "1|0|0"
sq "UPDATE ka_uzivatele SET pozice = '$(sed "s/'/''/g" <<< "$A345_POSITION")' WHERE idu = ${A345_ME:-0}" > /dev/null

# CPO 6 (O3): a site moved here gets the move in its first steps instead of "Prepare your pages"
A345_MOVE=$(a345_php '$m = Kaleta\Admin\Kernel::moveStep($app); echo $m === null ? "none" : $m[2] . "|" . (int) $m[3];')
A345_SITE=$(a345_block '<details class="pruvodce"')
expect "3.45 A CPO 6: the website's first steps carry the move step instead of Prepare your pages (or none are left)" \
  "$([ -z "$A345_SITE" ] && echo none || echo "$(grep -c "href=\"[^\"]*${A345_MOVE%%|*}\"" <<< "$A345_SITE" || true)|$(grep -c 'href="[^"]*module=pages"' <<< "$A345_SITE" || true)")" \
  "$([ -z "$A345_SITE" ] && echo none || echo '1|0')"
expect "3.45 A CPO 6: an import ran on this test site, so the move step leads to Site audit" "${A345_MOVE%%|*}" "admin.php?module=audit"

# CPO 6: the role preset Office / sales with Bookings and Quotes; the base level is Team member
check "3.45 A CPO 6: the preset Office / sales offers Quotes and Bookings when the features are on" 200 "/admin.php?module=roles&action=new&preset=office" 'name="moduly\[\]" value="quotes" checked'
check "3.45 A CPO 6: the base level of a user is no longer called News author" 200 "/admin.php?module=users&action=new" 'name="admin" value="0"'
expect "3.45 A CPO 6: … but the neutral name" "$(grep -cE 'Team member|Člen týmu|Teammitglied|Miembro del equipo' "$WORK/response")|$(grep -cE '>(News author|Autor novinek|News-Autor|Autor de noticias)<' "$WORK/response" || true)" "1|0"

# back as it was
sq "DELETE FROM ka_nastaveni WHERE promenna IN ($A345_KEYS); INSERT INTO ka_nastaveni SELECT * FROM ka_a345_backup; DROP TABLE ka_a345_backup" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
sq "DELETE FROM ka_poptavky WHERE formular = 'Office 345'" > /dev/null
