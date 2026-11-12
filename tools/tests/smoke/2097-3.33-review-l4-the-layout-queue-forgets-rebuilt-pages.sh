echo "== 3.33 review L4: the layout queue forgets rebuilt pages (no perpetual hashing), the Move block reads one report"
l4php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); '"$1" -- "${@:2}"); }
L4_BUILD='{"v":1,"deti":[{"id":"a1","typ":"text","obsah":{"html":"<p>L4</p>"}}]}'
L4_PAGES=""
for l4 in rebuilt trashed draft waiting; do
  L4_ID=$(sq "INSERT INTO ka_stranky (titulek, seo_link, text, zmeneno, stavba) VALUES ('L4 $l4', 'l4-$l4', '<p>L4</p>', NOW(), '$L4_BUILD'); SELECT LAST_INSERT_ID()")
  l4php 'Kaleta\Core\ImportLayout::remember($app->db(), (int) $argv[1], (string) $app->db()->value("SELECT stavba FROM {stranky} WHERE ids = ?", [(int) $argv[1]]));' "$L4_ID"
  L4_PAGES="$L4_PAGES $L4_ID"; eval "L4_$l4=$L4_ID"
done
sq "UPDATE ka_stranky SET stavba = REPLACE(stavba, '<p>L4</p>', '<p>L4 rebuilt</p>') WHERE ids IN ($L4_rebuilt, $L4_trashed, $L4_draft);
  UPDATE ka_stranky SET smazano = NOW() WHERE ids = $L4_trashed; UPDATE ka_stranky SET stavba_koncept = stavba WHERE ids = $L4_draft"
expect "3.33 review L4: the cleanup job's prune removes only the fingerprint of the rebuilt page (a trashed page and one with a draft keep theirs, the waiting one too)" \
  "$(l4php 'echo Kaleta\Core\ImportLayout::prune($app->db()) >= 1 ? "pruned" : "none";')|$(sq "SELECT GROUP_CONCAT(nase_id ORDER BY nase_id) FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id IN ($(echo $L4_PAGES | tr ' ' ','))")" \
  "pruned|$L4_trashed,$L4_draft,$L4_waiting"
expect "3.33 review L4: the waiting page still waits" "$(l4php 'echo isset(Kaleta\Core\ImportLayout::pending($app->db())[(int) $argv[1]]) ? "yes" : "no";' "$L4_waiting")" "yes"
grep -q "ImportLayout::prune" "$ROOT/system/src/Core/Scheduler.php" && echo "  ok     3.33 review L4: the prune runs in the cleanup job, never on a GET" || { echo "  CHYBA  3.33 review L4: prune not in the cleanup job"; ERRORS=$((ERRORS+1)); }
# the Move block's report: the newest finished file, even with an unfinished newer one beside it
L4_DIR="$WORK/web/storage/import"; mkdir -p "$L4_DIR"
printf '{"id":"aaaaaaaaaaaaaaaa","faze":"hotovo","zalozeno":"2026-11-01 10:00:00","dokonceno":"2026-11-01 10:05:00","radky":[{"stav":"sample","problemy":["sample_page"]}]}' > "$L4_DIR/parita-aaaaaaaaaaaaaaaa.json"
printf '{"id":"bbbbbbbbbbbbbbbb","faze":"kontrola","zalozeno":"2026-11-02 10:00:00","radky":[]}' > "$L4_DIR/parita-bbbbbbbbbbbbbbbb.json"
php -r 'touch($argv[1], time() + 30); touch($argv[2], time() + 60);' -- "$L4_DIR/parita-aaaaaaaaaaaaaaaa.json" "$L4_DIR/parita-bbbbbbbbbbbbbbbb.json" # newer than any report of the earlier sections
expect "3.33 review L4: latestReport finds the newest finished report without decoding them all" \
  "$(l4php '$r = Kaleta\Core\MoveStatus::latestReport(); echo $r === null ? "none" : $r["id"] . ":" . $r["on_sample_pages"];')" "aaaaaaaaaaaaaaaa:1"
rm -f "$L4_DIR/parita-aaaaaaaaaaaaaaaa.json" "$L4_DIR/parita-bbbbbbbbbbbbbbbb.json"
sq "DELETE FROM ka_import_mapa WHERE zdroj = 'layout' AND nase_id IN ($(echo $L4_PAGES | tr ' ' ',')); DELETE FROM ka_stranky WHERE ids IN ($(echo $L4_PAGES | tr ' ' ','))"
