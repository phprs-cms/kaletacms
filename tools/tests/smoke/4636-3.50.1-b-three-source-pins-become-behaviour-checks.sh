echo "== 3.50.1 B5: three source pins of the moved code become behaviour checks – who edited a page, spam in My work, the normal form of every redirect"
B636_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) SELECT idu, 'b636 admin', 'full', 'all', SHA2('$B636_TOKEN', 256), '$(site_time)' FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
b636_mcp() { mcp_call "$B636_TOKEN" "$1" "$2"; } # its own connection: an earlier section may have removed the suite's shared token
B636_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")

# 1. (was a pin of 3.20 C11) every edit by a person stamps who along with when: MCP update_page, a publish of the builder
#    (Builder\Publisher), the listing page a preset creates (Builder\Presets) and its publish from the collection (Collections)
b636_mcp create_page '{"title":"Stamp B636","slug":"b636-stamp"}' > /dev/null
B636_PAGE=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'b636-stamp'")
b636_unstamp() { sq "UPDATE ka_stranky SET zmeneno = NULL, zmenil = NULL WHERE ids = $1" > /dev/null; }
b636_stamp() { sq "SELECT CONCAT(IFNULL(zmenil, 'none'), '|', zmeneno IS NOT NULL) FROM ka_stranky WHERE ids = $1"; }
b636_unstamp "$B636_PAGE"; b636_mcp update_page "{\"id\":$B636_PAGE,\"title\":\"Stamp B636 renamed\"}" > /dev/null
B636_UPDATE=$(b636_stamp "$B636_PAGE")
b636_unstamp "$B636_PAGE"
b636_mcp save_build "{\"id\":$B636_PAGE,\"publish\":true,\"build\":{\"v\":1,\"deti\":[{\"id\":\"sek636\",\"typ\":\"sekce\",\"deti\":[{\"id\":\"txt636\",\"typ\":\"text\",\"obsah\":{\"text\":\"Stamped\"}}]}]}}" > /dev/null
B636_PUBLISH=$(b636_stamp "$B636_PAGE")
b636_mcp create_collection '{"name":"Team B636","preset":"people"}' > /dev/null
B636_COLL=$(sq "SELECT IFNULL(MAX(idk), 0) FROM ka_kolekce WHERE seo_link = 'team-b636'")
B636_LIST=$(sq "SELECT IFNULL(MAX(ids), 0) FROM ka_stranky WHERE seo_link = 'team-b636'")
B636_PRESET=$(b636_stamp "$B636_LIST")
b636_unstamp "$B636_LIST"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$B636_COLL"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=publish_page" -d "_csrf=$(csrf)" -d "id=$B636_COLL"
B636_SHOWN=$(sq "SELECT CONCAT(zobrazit, '|', IFNULL(zmenil, 'none')) FROM ka_stranky WHERE ids = $B636_LIST")
expect "3.50.1 B5 (3.20 C11): update_page, a builder publish and a preset's listing page stamp the person and the time; publishing the listing page from the collection stamps the person" \
  "$B636_UPDATE $B636_PUBLISH $B636_PRESET $B636_SHOWN" "$B636_ADMIN|1 $B636_ADMIN|1 $B636_ADMIN|1 1|$B636_ADMIN"

# 2. (was a pin of N324-5) My work and its menu badge leave spam out, as the Enquiries list does
B636_NOW=$(site_time)
b636_assigned() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); echo Kaleta\Admin\Modules\Enquiries::countAssigned($app->db(), (int) $argv[1]);' -- "$B636_ADMIN"); }
B636_BEFORE=$(b636_assigned)
sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, kategorie, prirazeno) VALUES
  ('$B636_NOW', 'B636 real enquiry', 'b636-real@example.com', '[]', 0, 'sales', $B636_ADMIN), ('$B636_NOW', 'B636 spam enquiry', 'b636-spam@example.com', '[]', 0, 'spam', $B636_ADMIN)" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work"
B636_SCREEN="$(grep -c 'B636 real enquiry' "$WORK/response" || true)|$(grep -c 'B636 spam enquiry' "$WORK/response" || true)"
B636_BADGE=$(( $(b636_assigned) - B636_BEFORE ))
expect "3.50.1 B5 (N324-5): My work lists the assigned enquiry but not the assigned spam, and the badge counts one of the two" \
  "$([ "${B636_SCREEN%%|*}" -ge 1 ] && echo listed)|${B636_SCREEN#*|}|$B636_BADGE" "listed|0|1"

# 3. (was a pin of 3.19) every writer of a redirect fills its normal form: the admin form, save_redirect, save_redirects
#    (an exact rule, a pattern and a 410) and a changed page slug (Pages::move → RedirectStore::add)
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=redirects"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$(csrf)" -d z_adresy=/b636-form-old -d na_adresu=/b636-stamp -d typ=301
b636_mcp save_redirect '{"from":"/b636-one-old","to":"/b636-stamp"}' > /dev/null
b636_mcp save_redirects '{"redirects":[{"from":"/b636-many-old","to":"/b636-stamp"},{"from":"/b636-pattern/*","to":"/b636-stamp"},{"from":"/b636-gone","code":410}]}' > /dev/null
sq "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $B636_PAGE" > /dev/null # only a page on the site leaves a redirect behind
b636_mcp update_page "{\"id\":$B636_PAGE,\"slug\":\"b636-stamp-moved\"}" > /dev/null
expect "3.50.1 B5 (3.19): the redirects of the admin form, save_redirect, save_redirects (exact, pattern, 410) and a changed slug are all stored, none without its normal form" \
  "$(sq "SELECT CONCAT(COUNT(*), '|', SUM(source_norm IS NULL OR source_norm = '')) FROM ka_presmerovani WHERE z_adresy IN ('b636-form-old', 'b636-one-old', 'b636-many-old', 'b636-pattern/*', 'b636-gone', 'b636-stamp')")" "6|0"
expect "3.50.1 B5 (3.19): no redirect the suite has stored so far lacks its normal form" "$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE source_norm IS NULL")" "0"

# clean-up
sq "DELETE FROM ka_api_tokeny WHERE nazev = 'b636 admin'" > /dev/null
sq "DELETE FROM ka_presmerovani WHERE z_adresy LIKE 'b636-%' OR na_adresu LIKE 'b636-%'; DELETE FROM ka_poptavky WHERE formular LIKE 'B636 %';
  DELETE FROM ka_stavba_revize WHERE ids IN (${B636_PAGE:-0}, ${B636_LIST:-0}); DELETE FROM ka_stranky WHERE ids IN (${B636_PAGE:-0}, ${B636_LIST:-0});
  DELETE FROM ka_kolekce_polozky WHERE idk = ${B636_COLL:-0}; DELETE FROM ka_kolekce WHERE idk = ${B636_COLL:-0}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
