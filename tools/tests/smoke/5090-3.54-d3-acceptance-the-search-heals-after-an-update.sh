echo "== 3.54 D3 acceptance (G2): right after an update from 3.52 – 3000 pages and items without a search text, no job run, no cron – the search says so and heals"
d090_get() { curl -s -G -A "Mozilla/5.0 test" "$B/hledani" --data-urlencode "q=$1"; }
d090_has() { local n; n=$(grep -cF -- "$1" || true); [ "${n:-0}" -gt 0 ] && echo 1 || echo 0; }
d090_waiting() { sq "SELECT (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'akcept-d090-%' AND hledani IS NULL) + (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link LIKE 'akcept-d090-%' AND hledani IS NULL)"; }
# the state an update from 3.52 leaves: every new row NULL, the news index of version 1 (search_index_at '' – a re-index to
# run), nothing remembered by the job (search_index_checked ''), the background jobs not due
d090_after_update() {
  sq "UPDATE ka_stranky SET hledani = NULL WHERE seo_link LIKE 'akcept-d090-%'; UPDATE ka_kolekce_polozky SET hledani = NULL WHERE seo_link LIKE 'akcept-d090-%';
    REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', ''), ('search_index_checked', ''); DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
}
background_jobs hold
fill_search_index > /dev/null
D090_NOW=$(site_time)
D090_PAGES=$(for i in $(seq 1 1500); do printf "('akcept-d090-%s', 'Akceptace %s D090', '<p>Obsah stránky s textem ver%sq090 a dalšími slovy pro hledání na webu.</p>', 1, 0, %s)," "$i" "$i" "$i" "$((5000 + i))"; done)
sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, v_menu, poradi) VALUES ${D090_PAGES%,}" > /dev/null
D090_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole, detail) VALUES ('Akceptace D090', 'akceptace-d090', '[{\"klic\":\"popis\",\"typ\":\"text\"}]', 1); SELECT LAST_INSERT_ID();")
D090_ITEMS=$(for i in $(seq 1 1500); do printf "(%s, 'Položka %s D090', 'akcept-d090-%s', '{\"popis\":\"Popis položky pol%sq090\"}', '%s', 1, %s)," "$D090_IDK" "$i" "$i" "$i" "$D090_NOW" "$i"; done)
sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, datum, zobrazit, poradi) VALUES ${D090_ITEMS%,}" > /dev/null
D090_STATE=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'search_index_at'), '')")

# 1) visitors only, nobody searches until the end: the first search shows the notice (its own slice of 0.3 s cannot fill 3000
# rows); the visit-driven slices of the background jobs (once a minute, here made due each visit) fill the rest
d090_after_update
D090_FIRST=$(d090_get 'ver480q090')
D090_FIRST_LEFT=$(d090_waiting)
expect "3.54 D3: the first search right after the update shows \"Některé stránky se ještě indexují…\" (rows still wait after its own slice)" \
  "$(printf '%s' "$D090_FIRST" | d090_has 'se ještě indexují')|$([ "$D090_FIRST_LEFT" -gt 0 ] && echo waiting)" "1|waiting"
D090_START=$(date +%s)
D090_VISITS=0
while [ "$(search_index_pending)" -gt 0 ] && [ "$D090_VISITS" -lt 300 ]; do
  background_jobs due
  curl -s -o /dev/null -A "Mozilla/5.0 test" "$B/"
  background_jobs settle
  D090_VISITS=$((D090_VISITS + 1))
done
D090_SECONDS=$(( $(date +%s) - D090_START ))
background_jobs hold
D090_FOUND=$(d090_get 'ver480q090')
echo "  info   3.54 D3 (G2): 3000 rows (and the news re-index) filled by $D090_VISITS visit-driven slices in ${D090_SECONDS} s; the first search's own slice left $D090_FIRST_LEFT of 3000"
expect "3.54 D3: after the visit-driven slices a page is found by a word of its body and an item by a field value; the notice is gone" \
  "$([ "$D090_VISITS" -lt 300 ] && echo filled)|$(printf '%s' "$D090_FOUND" | d090_has 'href="/akcept-d090-480"')|$(printf '%s' "$D090_FOUND" | d090_has 'se ještě indexují')|$(d090_get 'pol480q090' | d090_has 'href="/akceptace-d090/akcept-d090-480"')" \
  "filled|1|0|1"

# 2) no visits at all, only people searching: each search fills a slice first (SearchIndex::heal, 0.3 s) – a site without cron
# and with little traffic heals at the moment someone searches
d090_after_update
D090_SEARCHES=0
while [ "$D090_SEARCHES" -lt 60 ]; do
  sq "DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
  D090_PAGE=$(d090_get 'ver490q090')
  D090_SEARCHES=$((D090_SEARCHES + 1))
  [ "$(printf '%s' "$D090_PAGE" | d090_has 'se ještě indexují')" = 0 ] && break
done
echo "  info   3.54 D3 (G2): with searches only, $D090_SEARCHES searches until the notice was gone"
expect "3.54 D3: searches alone heal the index – the notice goes, the page is found by its body, nothing of the 3000 rows waits" \
  "$([ "$D090_SEARCHES" -lt 60 ] && echo healed)|$(printf '%s' "$D090_PAGE" | d090_has 'href="/akcept-d090-490"')|$(d090_waiting)" "healed|1|0"

sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('search_index_at', '$D090_STATE');
  DELETE FROM ka_stranky WHERE seo_link LIKE 'akcept-d090-%'; DELETE FROM ka_kolekce_polozky WHERE idk = $D090_IDK; DELETE FROM ka_kolekce WHERE idk = $D090_IDK;
  DELETE FROM ka_kontrola_ip WHERE typ = 'hledani'" > /dev/null
fill_search_index > /dev/null
background_jobs resume
