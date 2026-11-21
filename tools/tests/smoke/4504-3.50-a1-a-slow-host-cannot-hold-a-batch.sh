echo "== 3.50 A1 (N140): a slow old host pauses a page between batches – the downloads are reused and the import completes"
# /slow/ has six images that answer after one second each; batches of one second (and one more past the end) pause the
# page while it downloads them, keep its position and clear its marker, and the next batch reuses what came already
a350_site /slow/
a350_found true
a350_confirm "$A350_ID"
A350_RUNS=0
until [ "$(a350_state faze)" = hotovo ] || [ "$A350_RUNS" -ge 12 ]; do a350_batch "$A350_ID" 1 > /dev/null; A350_RUNS=$((A350_RUNS+1)); done
A350_SLOW=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Slow' ORDER BY ids DESC LIMIT 1")
expect "3.50 N140: the page took more than one batch, finished without an error (the one error is the fake site's home page, a 404), and its six images are in Media in its text" \
  "$([ "$A350_RUNS" -ge 2 ] && [ "$A350_RUNS" -lt 12 ] && echo batches)|$(a350_state faze)|$(a350_state vysledek chyb)|$(a350_state vysledek obrazky)|$(sq "SELECT ROUND((LENGTH(text) - LENGTH(REPLACE(text, 'src=\"media/', ''))) / LENGTH('src=\"media/')) FROM ka_stranky WHERE ids = ${A350_SLOW:-0}")|$(a350_state stranka)" \
  "batches|hotovo|1|6|6|"
expect "3.50 N140: every image was downloaded once – a paused page reuses the downloads mapped before" \
  "$(grep -c '^/slowimg/' "$A350_LOG" || true)|$(grep '^/slowimg/' "$A350_LOG" | sort -u | wc -l | tr -d ' ')" "6|6"
sq "DELETE m FROM ka_import_mapa m JOIN ka_stranky s ON s.ids = m.nase_id WHERE m.typ = 'stranka' AND s.ids = ${A350_SLOW:-0}; DELETE FROM ka_stranky WHERE ids = ${A350_SLOW:-0}" > /dev/null
kill "$A350_PID" 2>/dev/null || true; wait "$A350_PID" 2>/dev/null || true; A350_PID=
