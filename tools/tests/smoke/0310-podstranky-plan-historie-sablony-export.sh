echo "== podstránky, plán, historie, šablony, export"
save_page() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" "$@"; }
save_page -d ids=0 --data-urlencode "titulek=Služby firmy" -d seo_link=sluzby-firmy -d zobrazit=1 -d v_menu=0 -d "text=<p>S</p>" > /dev/null
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby-firmy'")
save_page -d ids=0 --data-urlencode "titulek=Kuchyně" -d "nadrazena=$IDR" -d zobrazit=1 -d v_menu=0 -d "text=<p>Kuchyně na míru</p>" > /dev/null
expect "podstránka má adresu pod nadřazenou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "sluzby-firmy/kuchyne"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "podstránka na webu" 200 /sluzby-firmy/kuchyne "Kuchyně na míru"
save_page -d "ids=$IDR" --data-urlencode "titulek=Služby firmy" -d seo_link=nase-sluzby -d zobrazit=1 -d v_menu=0 -d "text=<p>S2</p>" > /dev/null
expect "změna adresy nadřazené posune podstránku" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "nase-sluzby/kuchyne"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/sluzby-firmy/kuchyne"); expect "stará adresa podstránky přesměruje" "$code" "301 $B/nase-sluzby/kuchyne"
expect "změna textu uloží předchozí verzi" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky_revize WHERE ids = $IDR ORDER BY idr DESC LIMIT 1")" "<p>S</p>"
save_page -d ids=0 --data-urlencode "titulek=Akce" -d v_menu=0 -d "text=<p>A</p>" -d "zverejnit_od=$(date -v+1d '+%Y-%m-%dT%H:%M' 2>/dev/null || date -d '+1 day' '+%Y-%m-%dT%H:%M')" > /dev/null
expect "naplánovaná stránka čeká skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE seo_link = 'akce'")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zverejnit_od = '$(site_time)' - INTERVAL 1 MINUTE WHERE seo_link = 'akce'"; background_jobs due
curl -s -o /dev/null "$B/novinky?x=$RANDOM"
# the job runs after the page is sent; under load (parallel suites) it can take a few seconds, and a request that comes
# while another background job holds the lock does nothing – as on a real site, the next visit gives it another chance
# (a visit starts the jobs at most once a minute, so each retry clears that mark, as a minute passing would – 3.12)
for i in $(seq 1 16); do [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" = 1 ] && break; sleep 0.5
  background_jobs due; curl -s -o /dev/null "$B/novinky?x=$RANDOM"; done
expect "naplánovaná stránka se v čase sama zveřejní" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" "1"
location=$(save_page -d ids=0 --data-urlencode "titulek=Nabídka" -d sablona=landing -d zobrazit=0 -d v_menu=0 -d text=)
case "$location" in *action=builder*) echo "  ok     nová stránka ze šablony jde rovnou do builderu";; *) echo "  CHYBA  šablona stránky: $location"; ERRORS=$((ERRORS+1));; esac
expect "šablona složí koncept ze sekcí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%\"typ\":\"sekce\"%' FROM ka_stranky WHERE seo_link = 'nabidka'")" "1"
# 3.5 (UXA-02): "Publish page" and "Show in navigation" ticked on a template page – visitors never get it empty: hidden and out
# of the navigation until its build is published, then shown as chosen
save_page -d ids=0 --data-urlencode "titulek=Workshopy" -d sablona=landing -d zobrazit=1 -d v_menu=1 -d text= > /dev/null
IDW=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'workshopy'")
expect "3.5: a template page with Publish ticked waits hidden for its build, the wish is kept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', show_on_publish, '/', v_menu, '/', stavba IS NULL) FROM ka_stranky WHERE ids = $IDW")" "0/1/1/1"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
expect "3.5: before the publish visitors get a 404 and no navigation link" "$(curl -s -o /dev/null -w '%{http_code}' "$B/workshopy")|$(grep -c 'href="/workshopy"' "$WORK/response" || true)" "404|0"
check "3.5: the builder says the page is hidden until it is published" 200 "/admin.php?module=pages&action=builder&id=$IDW" '"poPublikovani":true'
check "3.5: the page form keeps Publish ticked and explains the wait" 200 "/admin.php?module=pages&action=edit&id=$IDW" 'Skrytá, dokud nemá obsah'
check "3.5: the pages list marks it hidden until published" 200 "/admin.php?module=pages" 'skrytá do publikování'
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_publish&id=$IDW" -d "_csrf=$TOKEN")
expect "3.5: the first publish of the build shows the page and says so to the builder" "$code|$(grep -c '"zobrazena":true' "$WORK/response" || true)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', show_on_publish) FROM ka_stranky WHERE ids = $IDW")" "200|1|1/0"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
expect "3.5: after the publish the page is live and in the navigation" "$(curl -s -o /dev/null -w '%{http_code}' "$B/workshopy")|$(grep -c 'href="/workshopy"' "$WORK/response" || true)" "200|1"
# a blank page opened in the builder before it has text waits too; with text it is shown at once, as before
save_page -d ids=0 --data-urlencode "titulek=Prázdná do builderu" -d seo_link=prazdna-builder -d zobrazit=1 -d v_menu=0 -d text= -d po_ulozeni=stavitel > /dev/null
save_page -d ids=0 --data-urlencode "titulek=S textem hned" -d seo_link=s-textem-hned -d zobrazit=1 -d v_menu=0 -d "text=<p>Obsah</p>" -d po_ulozeni=stavitel > /dev/null
expect "3.5: an empty page for the builder waits, a page with text is shown at once" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(CONCAT(zobrazit, '/', show_on_publish) ORDER BY seo_link) FROM ka_stranky WHERE seo_link IN ('prazdna-builder', 's-textem-hned')")" "0/1,1/0"
# adding text later in the form shows it as chosen; unticking Publish drops the wish
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'prazdna-builder'")
save_page -d "ids=$IDP" --data-urlencode "titulek=Prázdná do builderu" -d seo_link=prazdna-builder -d zobrazit=1 -d v_menu=0 -d "text=<p>Už s textem</p>" > /dev/null
expect "3.5: the page waiting for content is shown once it gets text" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', show_on_publish) FROM ka_stranky WHERE ids = $IDP")" "1/0"
IDN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nabidka'")
curl -s -b "$JAR" -o "$WORK/stranka.json" "$B/admin.php?module=pages&action=export&id=$IDN"
grep -q '"format": "kaleta-stranka"' "$WORK/stranka.json" && echo "  ok     export stránky do JSON" || { echo "  CHYBA  export stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/stranka.json;type=application/json"
expect "import stránky vytvoří skrytou kopii se stavbou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba_koncept IS NOT NULL) FROM ka_stranky WHERE seo_link = 'nabidka-2'")" "0/1"
# 1.8: the export carries the classes and components of the build (a component inside a component too)
php -r '$d = json_decode(file_get_contents($argv[1]), true); $d["titulek"] = "Balíček";
  $d["stavba"]["deti"][] = ["typ" => "sekce", "tridy" => ["balicek-karta", "balicek-vlastni"], "deti" => [["typ" => "komponenta", "obsah" => ["komponenta" => "901", "hodnoty" => []]]]];
  $d["tridy"] = [["nazev" => "balicek-karta", "styl" => ["zaklad" => ["odsazeni" => "l"]], "css" => "color: red; behavior: url(x)"], ["nazev" => "balicek-vlastni", "styl" => ["zaklad" => ["pozadi" => "primarni"]], "css" => ""]];
  $d["komponenty"] = [["id" => 901, "nazev" => "Balíček vnější", "vlastnosti" => [], "stavba" => ["v" => 1, "deti" => [["typ" => "sekce", "deti" => [["typ" => "komponenta", "obsah" => ["komponenta" => "902"]]]]]]],
    ["id" => 902, "nazev" => "Balíček vnitřní", "vlastnosti" => [["klic" => "nadpis", "popisek" => "Nadpis", "typ" => "text", "vychozi" => "Ahoj"]], "stavba" => ["v" => 1, "deti" => [["typ" => "nadpis", "obsah" => ["text" => "{{nadpis}}"]]]]]];
  file_put_contents($argv[2], json_encode($d, JSON_UNESCAPED_UNICODE));' -- "$WORK/stranka.json" "$WORK/balicek.json"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('balicek-vlastni', '{}', 'color: blue', '$(site_time)')"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/balicek.json;type=application/json"
OUTER=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Balíček vnější'"); INNER=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Balíček vnitřní'")
expect "page import creates the missing class (cleaned) and keeps the site's own" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT(css LIKE '%color: red%', '/', css LIKE '%behavior%') FROM ka_tridy WHERE nazev = 'balicek-karta'")|$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', styl, ':', css) FROM ka_tridy WHERE nazev = 'balicek-vlastni'")" "1/0|1:{}:color: blue"
expect "page import creates both components and points the uses at them" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT((SELECT stavba LIKE '%\"komponenta\":\"$INNER\"%' FROM ka_komponenty WHERE idm = '$OUTER'), '/', stavba_koncept LIKE '%\"komponenta\":\"$OUTER\"%') FROM ka_stranky WHERE titulek = 'Balíček'")" "1/1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/balicek.json;type=application/json"
expect "a second import of the same page reuses the components" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_komponenty WHERE nazev LIKE 'Balíček%'")" "2"
IDB=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT MIN(ids) FROM ka_stranky WHERE titulek = 'Balíček'")
curl -s -b "$JAR" -o "$WORK/balicek-export.json" "$B/admin.php?module=pages&action=export&id=$IDB"
expect "the export lists the used classes and both components" "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["verze"], "|", implode(",", preg_grep("/^(balicek|karta$)/", array_column($d["tridy"], "nazev"))), "|", implode(",", array_column($d["komponenty"], "nazev"));' -- "$WORK/balicek-export.json")" "2|balicek-karta,balicek-vlastni,karta|Balíček vnější,Balíček vnitřní"

