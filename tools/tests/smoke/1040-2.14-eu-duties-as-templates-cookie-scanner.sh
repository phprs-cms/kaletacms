echo "== 2.14: EU duties as templates – cookie scanner, anonymise, record of processing, accessibility statement, toolbar"
mcp create_page '{"title":"Video 2.14","slug":"video-2-14","visible":true}' > /dev/null; F17_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'video-2-14'")
mcp save_build "{\"id\":$F17_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"tag\":\"h1\",\"content\":{\"text\":\"Video\"}},{\"type\":\"video\",\"content\":{\"url\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\",\"title\":\"Clip\"}},
  {\"type\":\"form\",\"content\":{\"name\":\"Servis 2.14\",\"fields\":[{\"label\":\"Jméno a příjmení\",\"type\":\"text\",\"required\":true},{\"label\":\"E-mail\",\"type\":\"email\",\"required\":true},{\"label\":\"Rozsah opravy\",\"type\":\"textarea\"}]}}]}]}}" > /dev/null
sq "INSERT INTO ka_nastaveni VALUES ('cookies_mode', 'vestavena') ON DUPLICATE KEY UPDATE hodnota = 'vestavena'" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=cookies"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=cookie_scan" -d "_csrf=$(csrf)" -d tab=cookies
check "2.14: Settings → Privacy lists the consent storage and the YouTube cookies" 200 "/admin.php?module=settings&tab=cookies" '<code>kaleta_souhlas</code>'
grep -q '<code>VISITOR_INFO1_LIVE</code></td><td>YouTube</td>' "$WORK/response" && grep -q 'Skenovat web teď' "$WORK/response" && ! grep -q '<code>ka-pristupnost</code>' "$WORK/response" \
  && echo "  ok     2.14: the YouTube embed maps to its cookies, the scan button is there, the toolbar storage is listed only when the toolbar is on" || { echo "  CHYBA  2.14 cookie table"; grep -o 'Cookies a úložiště.\{0,600\}' "$WORK/response" | head -c 700; ERRORS=$((ERRORS+1)); }
grep -q 'Poslední sken vlastních stránek webu' "$WORK/response" && echo "  ok     2.14: the scan of the site's own pages ran and is dated" || { echo "  CHYBA  2.14 cookie scan did not record its run"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Cookies 2.14","slug":"cookies-2-14","content":"<p>Co používáme:</p><p>{{cookie_table}}</p>","visible":true}' > /dev/null
check "2.14: {{cookie_table}} on the cookie policy page becomes the table in the site language" 200 "/cookies-2-14" '<table class="ka-cookies-tabulka"><thead><tr><th>Název</th><th>Poskytovatel</th><th>Účel</th><th>Doba</th><th>Kategorie</th></tr></thead>'
grep -q '<td><code>kaleta_souhlas</code></td><td>Kaleta</td>' "$WORK/response" && grep -q '<td><code>YSC</code></td><td>YouTube</td><td>Přehrávač videa: zhlédnutí v rámci relace</td><td>relace</td><td>Marketing</td>' "$WORK/response" \
  && echo "  ok     2.14: the visitors' table has Kaleta's consent cookie and the YouTube rows translated" || { echo "  CHYBA  2.14 visitors' cookie table"; grep -o 'ka-cookies-tabulka.\{0,500\}' "$WORK/response" | head -c 600; ERRORS=$((ERRORS+1)); }
# 3.13: the names and lifetimes as the code sets them – the consent 6 months, the session cookie "kaleta", the preview cookie;
# the consent id only when consents are recorded
grep -q '<td><code>kaleta_souhlas</code></td><td>Kaleta</td><td>[^<]*</td><td>6 měsíců</td>' "$WORK/response" && grep -q '<td><code>kaleta</code></td><td>Kaleta</td>' "$WORK/response" \
  && grep -q '<td><code>ka_nahled</code></td>' "$WORK/response" && ! grep -q 'PHPSESSID' "$WORK/response" && ! grep -q '<code>kaleta_souhlas_id</code>' "$WORK/response" \
  && echo "  ok     3.13: the cookie table names the session cookie kaleta, keeps the choice 6 months, lists the preview cookie and no consent id while consents are not recorded" || { echo "  CHYBA  3.13 own cookies in the table"; grep -o 'ka-cookies-tabulka.\{0,900\}' "$WORK/response" | head -c 1000; ERRORS=$((ERRORS+1)); }
# anonymise instead of delete: the retention keeps the row with blanks (the choice first – the clean-up job would delete the old row under the default)
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=settings" -d "_csrf=$(csrf)" -d mesice=24 -d mesice_uchazeci=0 -d po_uplynuti=anonymise
F17_OLD=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, tema, email, data, stav, kategorie) VALUES ('$(site_time)' - INTERVAL 30 MONTH, 'Servis 2.14', '/video-2-14', 'Video', 'stary@example.com', '[[\"Jméno\",\"Starý Zákazník\"],[\"E-mail\",\"stary@example.com\"],[\"Zpráva\",\"Opravte kotel\"]]', 2, 'sales'); SELECT LAST_INSERT_ID()")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"
expect "T1 (Q5): opening Enquiries runs no retention (the expired enquiry is untouched)" "$(sq "SELECT CONCAT(email, '|', anonymizovano IS NULL) FROM ka_poptavky WHERE idp = $F17_OLD")" "stary@example.com|1"
run_job cleanup > /dev/null
expect "2.14: retention with anonymise keeps the row – date, form, page, topic and kind stay, the person is blank" "$(sq "SELECT CONCAT(formular, '|', stranka, '|', tema, '|', kategorie, '|', email, '|', data, '|', anonymizovano IS NOT NULL) FROM ka_poptavky WHERE idp = $F17_OLD")" 'Servis 2.14|/video-2-14|Video|sales||[["Jméno",""],["E-mail",""],["Zpráva",""]]|1'
grep -q 'name="po_uplynuti" value="anonymise" checked' "$WORK/response" && echo "  ok     2.14: the enquiry settings remember anonymise" || { echo "  CHYBA  2.14 enquiry settings"; ERRORS=$((ERRORS+1)); }
F17_NEW=$(sq "INSERT INTO ka_poptavky (datum, formular, stranka, email, data, stav) VALUES ('$(site_time)', 'Servis 2.14', '/video-2-14', 'novy@example.com', '[[\"Jméno\",\"Nový Zákazník\"],[\"E-mail\",\"novy@example.com\"]]', 0); SELECT LAST_INSERT_ID()")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$F17_NEW"
grep -q 'action=anonymise' "$WORK/response" && echo "  ok     2.14: the enquiry detail offers Anonymise" || { echo "  CHYBA  2.14 no Anonymise action in the detail"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$(csrf)" -d "idp=$F17_NEW"
expect "2.14: a per-enquiry Anonymise blanks the person and keeps the row" "$(sq "SELECT CONCAT(email, '|', data, '|', anonymizovano IS NOT NULL) FROM ka_poptavky WHERE idp = $F17_NEW")" '|[["Jméno",""],["E-mail",""]]|1'
check "2.14: the detail of an anonymised enquiry says so and no longer offers the action" 200 "/admin.php?module=enquiries&action=detail&id=$F17_NEW" 'Anonymizováno'
grep -q 'action=anonymise' "$WORK/response" && { echo "  CHYBA  2.14 Anonymise offered twice"; ERRORS=$((ERRORS+1)); } || echo "  ok     2.14: an anonymised enquiry is not anonymised again"
# the record of processing from the configuration
mcp processing_record '{}' > "$WORK/response"
contains -q 'Servis 2.14' "$WORK/response" && contains -q 'Jméno a příjmení (text), E-mail (e-mail), Rozsah opravy' "$WORK/response" && contains -q '24 m' "$WORK/response" && contains -q "anonymi" "$WORK/response" && contains -q "VISITOR_INFO1_LIVE" "$WORK/response" && contains -q 'not legal advice' "$WORK/response" && contains -q 'notifications (who did what' "$WORK/response" \
  && echo "  ok     MCP: processing_record names the form with its fields, the retention with anonymise, the cookies, the team's notifications (3.26) and the template notice" || { echo "  CHYBA  processing_record"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "2.14: Settings → Privacy → Record of processing is a page with the sections" 200 "/admin.php?module=settings&action=processing_record" 'Servis 2.14'
grep -q 'není právní radou' "$WORK/response" && echo "  ok     2.14: the record says it is a template, not legal advice" || { echo "  CHYBA  2.14 record without the template notice"; ERRORS=$((ERRORS+1)); }
# the accessibility statement from the audit
mcp accessibility_statement '{}' > "$WORK/response"
expect "MCP: accessibility_statement knows the standard and that no page exists yet" "$(mcp_value standard)|$(mcp_value page)" "EN 301 549 / WCAG 2.1 AA|null"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=cookies"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=accessibility_statement" -d "_csrf=$(csrf)" -d tab=cookies
F17_STATEMENT=$(sq "SELECT CONCAT(ids, '|', seo_link, '|', zobrazit, '|', text LIKE '%EN 301 549%', '|', text LIKE '%Stav souladu%') FROM ka_stranky WHERE titulek = 'Prohlášení o přístupnosti'")
expect "2.14: the statement is a hidden draft page in the site language with the standard and the status" "$(echo "$F17_STATEMENT" | cut -d'|' -f2-)" "prohlaseni-o-pristupnosti|0|1|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=cookies"
grep -q 'skrytý koncept' "$WORK/response" && grep -q 'Znovu vytvořit koncept z auditu' "$WORK/response" && echo "  ok     2.14: Settings → Privacy shows the draft and offers to regenerate it" || { echo "  CHYBA  2.14 statement in settings"; ERRORS=$((ERRORS+1)); }
N32312_LOG=$(sq "SELECT COALESCE(MAX(idp), 0) FROM ka_protokol")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=accessibility_statement" -d "_csrf=$(csrf)" -d tab=cookies
expect "2.14: regenerating updates the same draft page" "$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Prohlášení o přístupnosti' AND smazano IS NULL")" "1"
expect "3.23.1 review (N3231-2): regenerating the statement is one change-log row (Pages, with the page number)" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(modul, ' ', akce, ' ', SUBSTRING_INDEX(popis, ' ', 1)) ORDER BY idp) FROM ka_protokol WHERE idp > $N32312_LOG")" "pages accessibility_statement updated"
mcp accessibility_statement '{}' > "$WORK/response"
expect "MCP: accessibility_statement sees the hidden page" "$(mcp_value page slug)|$(mcp_value page published)" "prohlaseni-o-pristupnosti|"
# the toolbar: only when on
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "2.14: without the setting the site has no accessibility toolbar" 200 "/video-2-14" 'Video'
grep -q 'data-pristupnost' "$WORK/response" && { echo "  CHYBA  2.14 toolbar shown while off"; ERRORS=$((ERRORS+1)); } || echo "  ok     2.14: the toolbar markup is absent while off"
sq "INSERT INTO ka_nastaveni VALUES ('accessibility_toolbar', '1') ON DUPLICATE KEY UPDATE hodnota = '1'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "2.14: with the setting on, the toolbar is on the page with translated options" 200 "/video-2-14" 'data-pristupnost-volba="kontrast" aria-pressed="false">Vysoký kontrast</button>'
# 3.5 (UXP-03): "Larger text" shows its size instead of an on/off state; the panel resets the popover's inset (it opened top-left)
grep -q 'data-pristupnost-volba="text"><span>Větší písmo</span> <span class="ka-pristupnost-uroven">' "$WORK/response" && grep -q 'ka-pristupnost-panel { position: fixed; inset-block: auto calc(' "$WORK/response" \
  && echo "  ok     3.5: toolbar – the text size in the label, the panel above its button" || { echo "  CHYBA  3.5: toolbar label or panel position"; ERRORS=$((ERRORS+1)); }
grep -q 'aria-label="Možnosti přístupnosti"' "$WORK/response" && grep -q "localStorage.getItem(KEY)" "$WORK/response" \
  && echo "  ok     2.14: the toolbar is labelled for screen readers and remembers the choice in localStorage" || { echo "  CHYBA  2.14 toolbar markup"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'accessibility_toolbar'" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
mcp trash_page "{\"id\":$F17_PAGE}" > /dev/null
