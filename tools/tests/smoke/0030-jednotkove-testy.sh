echo "== jednotkové testy"
php "$ROOT/tools/unit-tests.php" || ERRORS=$((ERRORS+1))

# sq <query>: one value from the test database (defined early: the collection menu checks of 3.18.3 use it too)
sq() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
csrf() { grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//'; }
# 3.19: slugs_global plays a site the update could not switch (the global keys back through Slug::switchPerLanguage, kept
# for the manual schema rollback); slugs_everywhere switches it on again through migration 0106 (Slug::enableEverywhere)
slugs_global() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo json_encode(Kaleta\Core\Slug::switchPerLanguage($app->db(), $app->settings(), false), JSON_UNESCAPED_SLASHES);'); }
slugs_everywhere() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo json_encode(Kaleta\Core\Slug::enableEverywhere($app->db(), $app->settings()), JSON_UNESCAPED_SLASHES);') > /dev/null; }
# publish_look: the administrator publishes the draft look (design system, classes, menus – Core\Look) from Site appearance
publish_look() { curl -s -b "$JAR" -o "$WORK/look.html" "$B/admin.php?module=appearance"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=publish_look" -d "_csrf=$(grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$WORK/look.html" | head -1 | sed 's/.*value="//;s/"//')"; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
expect() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; ERRORS=$((ERRORS+1)); }; }
# 3.29: a page view counts only when the visitor's browser confirms it – image/vitals.js sends POST /hit with the path the server
# rendered and its signature from the page's script tag. hit_from <saved page> [user agent] [more curl options…] sends that
# beacon like a browser and prints the status.
hit_attr() { { grep -o "data-hit-$2=\"[^\"]*\"" "$1" || true; } | head -1 | sed 's/^[^"]*"//;s/"$//'; }
hit_from() {
  local page="$1" ua="${2:-Mozilla/5.0 test}"; shift; if [ $# -gt 0 ]; then shift; fi
  local news; news=$(hit_attr "$page" news)
  curl -s -o /dev/null -w '%{http_code}' -X POST "$B/hit" -A "$ua" --data-urlencode "path=$(hit_attr "$page" page)" -d "key=$(hit_attr "$page" key)" ${news:+-d "news=$news"} "$@"
}

# leftovers <dictionary> <file>: visible texts of a page (and placeholders, titles, labels, button values) that are a source text
# with a different translation in system/jazyky/<dictionary>.php – shown untranslated (3.13, the Spanish admin and installer)
leftovers() { php -r 'require $argv[3] . "/system/bootstrap.php"; $dictionary = require $argv[3] . "/system/jazyky/" . $argv[1] . ".php";
  $czechKeys = str_starts_with($argv[1], "admin-") ? require $argv[3] . "/system/jazyky/admin-en.php" : []; // Czech keys may match Czech page content
  $d = Dom\HTMLDocument::createFromString((string) file_get_contents($argv[2]), LIBXML_NOERROR | Dom\HTML_NO_DEFAULT_NS); $found = [];
  foreach ((new Dom\XPath($d))->query("//body//text()[not(ancestor::script or ancestor::style or ancestor::textarea or ancestor::option)] | //@placeholder | //@title | //@aria-label | //input[@type=\"submit\"]/@value") as $n) {
    $t = trim((string) preg_replace("/\s+/u", " ", $n->textContent));
    if ($t !== "" && isset($dictionary[$t]) && $dictionary[$t] !== $t && !isset($czechKeys[$t])) { $found[$t] = true; }
  }
  echo implode(" | ", array_slice(array_keys($found), 0, 5));' -- "$1" "$2" "$ROOT"; }

