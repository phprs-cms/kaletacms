echo "== 2.0: one pop-up system – the old per-page Modal element becomes a site pop-up (migration 0034)"
# a page as 1.x saved it: a Modal opened after 5 s once a week, and a button that opened it by its anchor
mcp create_page '{"title":"Stará akce","slug":"stara-akce","visible":true}' > /dev/null; MODAL_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'stara-akce'")
mcp save_build "{\"id\":$MODAL_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Nabídka\",\"link\":\"#nabidka\"}}]}]}}" > /dev/null
# the Modal as 1.x stored it, next to the button (the 2.0 validator no longer accepts it, so straight into the database)
php -r '$b = json_decode($argv[1], true); $b["deti"][0]["deti"][] = ["id" => "ok1", "typ" => "okno", "kotva" => "nabidka", "popis" => "Jarní akce", "obsah" => ["samo" => "5", "znovu" => "tyden"], "styl" => [], "tridy" => [],
  "deti" => [["id" => "na1", "typ" => "nadpis", "znacka" => "h2", "obsah" => ["text" => "Sleva 20 %"], "styl" => [], "tridy" => []]]]; echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' -- "$(sq "SELECT stavba FROM ka_stranky WHERE ids = $MODAL_PAGE")" > "$WORK/modal.json"
php -r '$pdo = new PDO("mysql:host=" . $argv[1] . ";port=" . $argv[2] . ";dbname=" . $argv[3] . ";charset=utf8mb4", $argv[4], $argv[5]); $pdo->prepare("UPDATE ka_stranky SET stavba = ? WHERE ids = ?")->execute([file_get_contents($argv[6]), $argv[7]]);' -- \
  "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS" "$WORK/modal.json" "$MODAL_PAGE"
sq "UPDATE ka_nastaveni SET hodnota = '33' WHERE promenna = 'db_version'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'data_migrations'" > /dev/null # a site of 1.9
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/stara-akce"
expect "the migration made a site pop-up with the same trigger, frequency and content, only on that page" \
  "$(sq "SELECT CONCAT_WS('|', nazev, adresa, typ, spoustec, hodnota, cetnost, dni, aktivni, stavba LIKE '%Sleva 20 %%', JSON_EXTRACT(pravidla, '$.stranky[0]')) FROM ka_popupy WHERE nazev = 'Jarní akce'")" \
  "Jarní akce|nabidka|okno|cas|5|dni|7|1|1|$MODAL_PAGE"
expect "the page lost the element and its button opens the pop-up" "$(sq "SELECT CONCAT(stavba LIKE '%\"typ\":\"okno\"%', '|', stavba LIKE '%#popup-nabidka%') FROM ka_stranky WHERE ids = $MODAL_PAGE")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "0|1|$LAST_MIGRATION"
grep -q 'href="#popup-nabidka"' "$WORK/response" && grep -q 'id="popup-nabidka"' "$WORK/response" && grep -q 'Sleva 20 %' "$WORK/response" \
  && echo "  ok     on the site: the button and the pop-up with the old content" || { echo "  CHYBA  converted pop-up on the site"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'id="popup-nabidka"' "$WORK/response" && { echo "  CHYBA  the converted pop-up shows on other pages"; ERRORS=$((ERRORS+1)); } || echo "  ok     the converted pop-up stays on its page"
check "2.0: old admin URLs of 1.3 lead to the start screen, not a redirect" 200 "/admin.php?modul=stranky&akce=novy" "Přehled"

