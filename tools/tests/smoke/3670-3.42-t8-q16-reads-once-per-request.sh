echo "== 3.42 T8 (Q16): the admin layout and the public page read the same rows once per request"
q16_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db();
  $selects = fn (): int => (int) ($db->one("SHOW SESSION STATUS LIKE \"Com_select\"")["Value"] ?? 0); $delta = function (callable $f) use ($selects): int { $a = $selects(); $f(); return $selects() - $a; }; '"$1" -- "${@:2}"); }
q16q() { curl -s -b "$JAR" -D - -o /dev/null "$B/admin.php?$1" | tr -d '\r' | sed -n 's/^[Xx]-[Kk]aleta-[Qq]ueries: //p'; }

# the Pages list asked for the main menu once per row (whether the page is in it); now once
Q16_BEFORE=$(q16q 'module=pages')
Q16_NOW=$(site_time)
sq "INSERT INTO ka_stranky (titulek, seo_link, text, zobrazit, jazyk, zmeneno) SELECT CONCAT('Q16 page ', n), CONCAT('q16-page-', n), '', 1, '', '$Q16_NOW'
  FROM (WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 8) SELECT n FROM s) q" > /dev/null
expect "3.42 Q16: eight more pages add no statement to the Pages list (the main menu is read once, not per row)" "$(q16q 'module=pages')" "$Q16_BEFORE"
sq "DELETE FROM ka_stranky WHERE seo_link LIKE 'q16-page-%'" > /dev/null

expect "3.42 Q16: the opening-hours exceptions are read once per request, a second menu reads only its own row (not the pages again); a saved exception is seen at once" \
  "$(q16_php '$hours = [$delta(fn () => Kaleta\Core\Hours::exceptions($db)), $delta(fn () => Kaleta\Core\Hours::exceptions($db))];
    Kaleta\Core\Menu::items($app, "hlavni", "", 0); $menus = [$delta(fn () => Kaleta\Core\Menu::items($app, "hlavni", "", 0)), $delta(fn () => Kaleta\Core\Menu::items($app, "paticka", "", 0))];
    $count = count(Kaleta\Core\Hours::exceptions($db)); $error = Kaleta\Core\Hours::save($app, ["from" => date("Y-m-d", strtotime("+400 days")), "closed" => 1, "note" => "Q16 exception"]);
    echo json_encode([$hours, $menus, $error, count(Kaleta\Core\Hours::exceptions($db)) - $count]);')" \
  '[[1,0],[0,1],null,1]'
sq "DELETE FROM ka_hours_exceptions WHERE note = 'Q16 exception'" > /dev/null
