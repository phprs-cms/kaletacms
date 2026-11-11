echo "== 3.33 review M1: subpages of a retired sample page are linked to the imported page, never to the trash"
m1php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); '"$1" -- "${@:2}"); }
M1_PARENT=$(sq "INSERT INTO ka_stranky (titulek, seo_link, text, zmeneno) VALUES ('M1 rodič', 'm1-rodic', '<p>x</p>', NOW()); SELECT LAST_INSERT_ID()")
M1_CHILD=$(sq "INSERT INTO ka_stranky (titulek, seo_link, text, zmeneno, nadrazena) VALUES ('M1 dítě', 'm1-rodic/dite', '<p>x</p>', NOW(), $M1_PARENT); SELECT LAST_INSERT_ID()")
m1php 'Kaleta\Core\StarterPages::retire($app->db(), $app->settings(), (int) $argv[1]);' "$M1_PARENT"
expect "3.33 review M1: retiring a sample page unlinks its subpages (the address stays)" "$(sq "SELECT CONCAT(COALESCE(nadrazena, 'null'), ':', seo_link) FROM ka_stranky WHERE ids = $M1_CHILD")" "null:m1-rodic/dite"
M1_NEW=$(sq "INSERT INTO ka_stranky (titulek, seo_link, text, zmeneno) VALUES ('M1 importovaný rodič', 'm1-rodic', '<p>x</p>', NOW()); SELECT LAST_INSERT_ID()")
m1php 'Kaleta\Core\ImportPath::linkParents($app->db());'
expect "3.33 review M1: linkParents links the subpage to the imported page at the parent address" "$(sq "SELECT nadrazena FROM ka_stranky WHERE ids = $M1_CHILD")" "$M1_NEW"
# a subpage still pointing at a parent in the trash (a site from before this fix) is relinked too
sq "UPDATE ka_stranky SET nadrazena = $M1_PARENT WHERE ids = $M1_CHILD"
m1php 'Kaleta\Core\ImportPath::linkParents($app->db());'
expect "3.33 review M1: linkParents also relinks a page whose parent is in the trash" "$(sq "SELECT nadrazena FROM ka_stranky WHERE ids = $M1_CHILD")" "$M1_NEW"
# update_page never builds an address from a parent in the trash
sq "UPDATE ka_stranky SET nadrazena = $M1_PARENT WHERE ids = $M1_CHILD; DELETE FROM ka_stranky WHERE ids = $M1_NEW"
mcp update_page "{\"id\":$M1_CHILD,\"slug\":\"dite-2\"}" > "$WORK/response"
expect "3.33 review M1: update_page with a parent in the trash keeps the page's own directory, not the trashed parent's address" "$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $M1_CHILD")" "m1-rodic/dite-2"
expect "3.33 review M1: the page form's kept prefix ignores a parent in the trash" \
  "$(m1php 'echo Kaleta\Core\ImportPath::keptPrefix(["seo_link" => "m1-rodic/dite", "nadrazena" => (int) $argv[1]], $app->db()), "|", Kaleta\Core\ImportPath::keptPrefix(["seo_link" => "m1-rodic/dite", "nadrazena" => (int) $argv[1]]);' "$M1_PARENT")" "m1-rodic/|"
sq "DELETE FROM ka_stranky WHERE ids IN ($M1_CHILD, $M1_PARENT)"
