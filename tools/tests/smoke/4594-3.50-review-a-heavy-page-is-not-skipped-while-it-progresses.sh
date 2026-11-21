echo "== 3.50 review (CORRECTNESS-1): a heavy page whose batches the host kills is not skipped while each batch still downloads something – the import completes it"
# the fake old site of 4500 (mode a350): /heavy/ has eight images that answer after one second each; three batches are
# ended by force (kill -9, as a host's hard limit does) each after two new images – before the review the third batch
# skipped the page as "stopped the import twice"; now a batch that moved the page forward does not count as an attempt
# its own full connection (a later section may have removed the token of 4500)
A350_TOKEN="kaleta_$(openssl rand -hex 24)"
sq "INSERT INTO ka_api_tokeny (idu, nazev, access, tool_set, otisk, vytvoren) VALUES ((SELECT idu FROM ka_uzivatele WHERE user = 'admin'), 'r594 full', 'full', 'all', SHA2('$A350_TOKEN', 256), '$(site_time)')" > /dev/null
r594_progress() { php -r '$s = is_file($argv[1]) ? json_decode((string) file_get_contents($argv[1]), true) : null; $m = $s["stranka"] ?? []; echo is_array($s) && ($m["adresa"] ?? "") === $argv[2] && (int) ($m["postup_od"] ?? 0) >= (int) $argv[3] ? (int) ($m["postup"] ?? 0) - (int) ($m["postup_od"] ?? 0) : -1;' -- "$WORK/web/storage/import/web-$A350_ID.json" "$A350/heavy/" "$1"; }
r594_killed_batch() { # r594_killed_batch <import id> <downloads before> – a batch ended by force once its attempt (started after them) has two new downloads
  (cd "$WORK/web" && KALETA_IMPORT_LOCAL=1 exec php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone();
    $s = Kaleta\Core\WebImport::load($argv[1]); $admin = (int) $app->db()->value("SELECT idu FROM {uzivatele} WHERE user = ?", ["admin"]);
    (new Kaleta\Core\WebImport($app->db(), $app->settings(), $admin, new Kaleta\Core\ImageDownloader($s["web"], true)))->step($s, fn (array $x) => Kaleta\Core\WebImport::save($x));' -- "$1") > /dev/null 2>&1 &
  local pid=$! waited=0
  until [ "$(r594_progress "$2")" -ge 2 ] 2>/dev/null || [ "$waited" -ge 100 ]; do sleep 0.2; waited=$((waited+1)); done
  kill -9 "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true
}
a350_site /heavy/
a350_found true
a350_confirm "$A350_ID"
R594_AFTER=""
R594_SEEN=0
for _ in 1 2 3; do
  r594_killed_batch "$A350_ID" "$R594_SEEN"; R594_NOW=$(a350_state stranka postup 2>/dev/null || true); [[ "$R594_NOW" =~ ^[0-9]+$ ]] || R594_NOW=0
  R594_AFTER="$R594_AFTER$(a350_state stranka pokusy 2>/dev/null || true):$([ "$R594_NOW" -ge $((R594_SEEN + 2)) ] && echo more || true)|"; R594_SEEN=$R594_NOW
done
R594_LAST=$(a350_batch "$A350_ID")
R594_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Heavy' ORDER BY ids DESC LIMIT 1")
expect "3.50 review: each killed batch mapped two more images and left one attempt; the next batch finished the page with all eight images, without \"stopped the import twice\"" \
  "$R594_AFTER$R594_LAST|$(a350_state vysledek obrazky)|$(a350_state chyby | grep -c 'stopped the import twice')|$(sq "SELECT ROUND((LENGTH(text) - LENGTH(REPLACE(text, 'src=\"media/', ''))) / LENGTH('src=\"media/')) FROM ka_stranky WHERE ids = ${R594_PAGE:-0}")|$(a350_state stranka)" \
  "1:more|1:more|1:more|hotovo:2|8|0|8|"
sq "DELETE m FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.typ = 'stranka' AND s.ids = ${R594_PAGE:-0}; DELETE FROM ka_stranky WHERE ids = ${R594_PAGE:-0}; DELETE FROM ka_api_tokeny WHERE nazev = 'r594 full'" > /dev/null
kill "$A350_PID" 2>/dev/null || true; wait "$A350_PID" 2>/dev/null || true; A350_PID=
