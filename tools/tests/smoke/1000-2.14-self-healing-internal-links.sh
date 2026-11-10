echo "== 2.14: self-healing internal links"
# the target is live with content (3.5: an empty page would wait hidden, and a hidden page's rename heals nothing)
mcp vytvor_stranku '{"titulek":"Heal target","adresa":"lh-stare","text":"<p>Target</p>","zobrazit":true}' > "$WORK/response"; mcp_text; LH_TARGET=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp vytvor_stranku '{"titulek":"Heal source","zobrazit":true}' > "$WORK/response"; mcp_text; LH_SOURCE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
sq "UPDATE ka_stranky SET stavba = '{\"v\":1,\"deti\":[{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Go\",\"odkaz\":\"/lh-stare#cast\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p><a href=\\\\\"/en/lh-stare\\\\\">x</a> <a href=\\\\\"/lh-stare-jina\\\\\">y</a></p>\"}}]}', text = '<p><a href=\"/lh-stare\">t</a></p>' WHERE ids = $LH_SOURCE" > /dev/null
sq "INSERT INTO ka_menu (umisteni, jazyk, polozky) VALUES ('lhtest', '', '[{\"typ\":\"odkaz\",\"url\":\"/lh-stare\",\"text\":\"M\"}]')" > /dev/null
mcp uprav_stranku "{\"id\":$LH_TARGET,\"adresa\":\"lh-nove\"}" > /dev/null
# 3.19: addresses are per language on every site, so /en/lh-stare may be another (English) page – the default version's rename
# heals only links without a prefix (LinkHealing, 3.9); /en/lh-stare still reaches the page through the redirect
expect "link healing: a renamed page – button, text and menu point to the new address, a longer address and the English address are left alone" \
  "$(sq "SELECT CONCAT(stavba LIKE '%/lh-nove#cast%', stavba LIKE '%/en/lh-stare%' AND stavba NOT LIKE '%/en/lh-nove%', stavba LIKE '%/lh-stare-jina%', text LIKE '%/lh-nove%') FROM ka_stranky WHERE ids = $LH_SOURCE")|$(sq "SELECT polozky LIKE '%/lh-nove%' FROM ka_menu WHERE umisteni = 'lhtest'")" "1111|1"
expect "link healing: the change is an event with the count" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'links.healed' AND data LIKE '%lh-nove%'")" "1"
sq "DELETE FROM ka_menu WHERE umisteni = 'lhtest'" > /dev/null
