echo "== 3.50 A1 (N124): a page that ends two batches is skipped and named – the import goes on"
# a fake old site (tools/fake-old-site.php, mode a350) with the pages in its sitemap (and its home page /, which answers 404);
# /kill/ answers only after 3 s, and the batch is ended before that (kill -9, as a host's time limit does) – twice. Batches run in the CLI with the site's config,
# like a request of the admin would (Core\WebImport::step with the state saved between its heavy parts).
A350_PORT=$((PORT + 8)); A350="http://127.0.0.1:$A350_PORT"; A350_LOG="$WORK/a350.log"
a350_site() { # a350_site <comma-separated paths> – (re)starts the fake old site with these pages in its sitemap
  [ -z "${A350_PID:-}" ] || { kill "$A350_PID" 2>/dev/null || true; wait "$A350_PID" 2>/dev/null || true; }
  : > "$A350_LOG"
  (cd "$ROOT/tools" && KALETA_FAKE_SLEEP=3 KALETA_FAKE_LOG="$A350_LOG" KALETA_FAKE_MODE=a350 KALETA_FAKE_PAGES="$1" exec php -S "127.0.0.1:$A350_PORT" fake-old-site.php > /dev/null 2>&1) & A350_PID=$!
  for _ in $(seq 1 30); do curl -s -o /dev/null "$A350/robots.txt" && break; sleep 0.3; done
}
a350_found() { # a new website import of the fake site over MCP, until its preview; A350_ID is its id
  mcp import_website "{\"url\":\"$A350\",\"images\":${1:-true}}" > "$WORK/response"; A350_ID=$(import_field import_id)
  for _ in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$A350_ID\"}" > "$WORK/response"; done
}
a350_confirm() { (cd "$WORK/web" && exec php -r 'require "system/bootstrap.php"; $s = Kaleta\Core\WebImport::load($argv[1]); $s["faze"] = "import"; $s["pozice"] = 0; Kaleta\Core\WebImport::save($s);' -- "$1"); }
a350_batch() { # a350_batch <import id> [seconds] – one batch of the import in the CLI; prints phase:position
  (cd "$WORK/web" && KALETA_IMPORT_LOCAL=1 exec php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
    $s = Kaleta\Core\WebImport::load($argv[1]); $admin = (int) $app->db()->value("SELECT idu FROM {uzivatele} WHERE user = ?", ["admin"]); $t = (float) ($argv[2] ?? 15);
    (new Kaleta\Core\WebImport($app->db(), $app->settings(), $admin, new Kaleta\Core\ImageDownloader($s["web"], true)))->step($s, fn (array $x) => Kaleta\Core\WebImport::save($x), $t, $t);
    Kaleta\Core\WebImport::save($s); echo $s["faze"], ":", $s["pozice"];' -- "$@")
}
a350_state() { php -r '$s = json_decode((string) file_get_contents($argv[1]), true); $v = $s; foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; } echo $v === null ? "" : (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));' -- "$WORK/web/storage/import/web-$A350_ID.json" "$@"; }
a350_killed_batch() { # a batch ended by force while it waits for /kill/ – after the state names /kill/ as the page in progress
  (cd "$WORK/web" && KALETA_IMPORT_LOCAL=1 exec php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
    $s = Kaleta\Core\WebImport::load($argv[1]); $admin = (int) $app->db()->value("SELECT idu FROM {uzivatele} WHERE user = ?", ["admin"]);
    (new Kaleta\Core\WebImport($app->db(), $app->settings(), $admin, new Kaleta\Core\ImageDownloader($s["web"], true)))->step($s, fn (array $x) => Kaleta\Core\WebImport::save($x));' -- "$1") > /dev/null 2>&1 &
  local pid=$! waited=0
  until [ "$(a350_state stranka adresa)" = "$A350/kill/" ] || [ "$waited" -ge 60 ]; do sleep 0.2; waited=$((waited+1)); done
  sleep 0.5; kill -9 "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true
}

a350_site /a350-first/,/kill/,/a350-last/
a350_found false
expect "3.50 N124: the fake old site's three pages (and its home page) are found" "$(import_field phase)|$(import_field found)" "preview|4"
a350_confirm "$A350_ID"
a350_killed_batch "$A350_ID"
A350_AFTER1="$(a350_state pozice)|$(a350_state stranka pokusy)"
a350_killed_batch "$A350_ID"
A350_AFTER2="$(a350_state pozice)|$(a350_state stranka pokusy)"
A350_LAST=$(a350_batch "$A350_ID")
expect "3.50 N124: each killed batch leaves the page in progress with its attempts; the third batch skips it, names it and imports the rest" \
  "$A350_AFTER1|$A350_AFTER2|$A350_LAST|$(a350_state vysledek stranky)|$(a350_state vysledek chyb)|$(a350_state stranka)" \
  "1|1|1|2|hotovo:4|2|2|"
expect "3.50 N124: the error says the page stopped the import twice; /kill/ was read twice and never imported, the pages around it were" \
  "$(a350_state chyby | grep -c 'kill – This page stopped the import twice')|$(grep -c '^/kill/' "$A350_LOG" || true)|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Kill'")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE titulek IN ('A350-first', 'A350-last') AND zobrazit = 0")" \
  "1|2|0|2"
mcp import_website "{\"import_id\":\"$A350_ID\"}" > "$WORK/response"
expect "3.50 N124: MCP shows the skipped page among the failures in English" "$(import_field phase)|$(import_field failures | grep -c 'stopped the import twice')" "done|1"
sq "DELETE m FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.typ = 'stranka' AND s.titulek IN ('A350-first', 'A350-last'); DELETE FROM ka_stranky WHERE titulek IN ('A350-first', 'A350-last')" > /dev/null
