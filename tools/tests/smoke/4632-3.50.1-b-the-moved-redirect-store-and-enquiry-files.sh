echo "== 3.50.1 B2: the redirect store and the enquiry files moved to Core – the admin form, MCP and the daily matcher write the same row; an expired enquiry's file goes"
B632_NOW=$(site_time)
mcp create_page '{"title":"Price list B632","slug":"b632-cenik-sluzeb"}' > /dev/null
B632_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'b632-cenik-sluzeb'")
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $B632_PAGE; DELETE FROM ka_presmerovani WHERE na_adresu = 'b632-cenik-sluzeb'; DELETE FROM ka_nenalezeno WHERE cesta LIKE 'b632-%'" > /dev/null
B632_AUTO=$(sq "SELECT GROUP_CONCAT(CONCAT(promenna, '=', hodnota)) FROM ka_nastaveni WHERE promenna IN ('redirect_auto', 'redirect_auto_threshold')")

# the admin form (Redirects::actionSave → RedirectStore::twin), MCP save_redirect (Redirects::add → RedirectStore::add)
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=redirects"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$(csrf)" -d z_adresy=/b632-admin-old -d na_adresu=/b632-cenik-sluzeb -d typ=301
mcp save_redirect '{"from":"/b632-mcp-old","to":"/b632-cenik-sluzeb"}' > /dev/null
# the daily matcher (RedirectMatcher::run → RedirectStore::add) for an address visitors keep asking for
sq "INSERT INTO ka_nenalezeno (cesta, pocet, naposledy) VALUES ('b632-cenik-sluzby', 5, '$B632_NOW')" > /dev/null
sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('redirect_auto', '1'), ('redirect_auto_threshold', '80') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
run_job redirects > /dev/null
b632_row() { sq "SELECT IFNULL((SELECT CONCAT(na_adresu, '|', typ, '|', source_norm = '$1') FROM ka_presmerovani WHERE z_adresy = '$1'), 'none')"; }
expect "3.50.1 B2: the admin form, MCP and the daily matcher each store the redirect to the same target, permanent, with its normal form" \
  "$(b632_row b632-admin-old) $(b632_row b632-mcp-old) $(b632_row b632-cenik-sluzby)" \
  "b632-cenik-sluzeb|301|1 b632-cenik-sluzeb|301|1 b632-cenik-sluzeb|301|1"
expect "3.50.1 B2: only the matcher's row is marked automatic, and the old addresses redirect" \
  "$(sq "SELECT GROUP_CONCAT(z_adresy ORDER BY z_adresy) FROM ka_presmerovani WHERE na_adresu = 'b632-cenik-sluzeb' AND auto_score IS NOT NULL")|$(curl -s -o /dev/null -w '%{http_code}' "$B/b632-mcp-old")" \
  "b632-cenik-sluzby|301"

# retention (Enquiries::deleteExpired → EnquiryFiles::delete): the file of an expired enquiry is removed, a kept one stays
B632_OLD=$(openssl rand -hex 12); B632_NEW=$(openssl rand -hex 12)
mkdir -p "$WORK/web/storage/prilohy/2020/01"
printf 'old cv' > "$WORK/web/storage/prilohy/2020/01/$B632_OLD.pdf"; printf 'new cv' > "$WORK/web/storage/prilohy/2020/01/$B632_NEW.pdf"
sq "INSERT INTO ka_poptavky (datum, formular, email, data) VALUES ('2020-01-15 10:00:00', 'B632 old', 'b632-old@example.com', '[[\"CV\",\"cv.pdf\",\"2020/01/$B632_OLD.pdf\"]]'),
  ('$B632_NOW', 'B632 new', 'b632-new@example.com', '[[\"CV\",\"cv.pdf\",\"2020/01/$B632_NEW.pdf\"]]')" > /dev/null
B632_MONTHS=$(setting_plain enquiries_months)
(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $app->settings());')
expect "3.50.1 B2: the retention clean-up deletes the expired enquiry with its file and keeps the recent one with its file (retention $B632_MONTHS months)" \
  "$([ -f "$WORK/web/storage/prilohy/2020/01/$B632_OLD.pdf" ] && echo old-kept || echo old-gone) $([ -f "$WORK/web/storage/prilohy/2020/01/$B632_NEW.pdf" ] && echo new-kept || echo new-gone) $(sq "SELECT GROUP_CONCAT(formular ORDER BY formular) FROM ka_poptavky WHERE formular LIKE 'B632 %'")" \
  "old-gone new-kept B632 new"

# clean-up
sq "DELETE FROM ka_poptavky WHERE formular LIKE 'B632 %'; DELETE FROM ka_presmerovani WHERE na_adresu = 'b632-cenik-sluzeb'; DELETE FROM ka_nenalezeno WHERE cesta LIKE 'b632-%';
  DELETE FROM ka_events WHERE type = 'redirect.auto' AND data LIKE '%b632-%'; DELETE FROM ka_protokol WHERE modul = 'redirects' AND popis LIKE '%b632-%'; DELETE FROM ka_stranky WHERE ids = ${B632_PAGE:-0};
  DELETE FROM ka_nastaveni WHERE promenna IN ('redirect_auto', 'redirect_auto_threshold')" > /dev/null
for b632_pair in $(printf '%s' "$B632_AUTO" | tr ',' ' '); do
  sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('${b632_pair%%=*}', '${b632_pair#*=}')" > /dev/null
done
rm -f "$WORK/web/storage/prilohy/2020/01/$B632_NEW.pdf" "$WORK"/web/storage/cache/stranky/*.html
