echo "== 3.53 B5: the search index sees text inside components, custom HTML and gallery descriptions; a changed or deleted component re-indexes the pages using it"
s960_page() { sq "SELECT COALESCE(hledani, 'NULL') FROM ka_stranky WHERE ids = ${S960_PAGE:-0}"; }
s960_found() { mcp search_site "{\"query\":\"$1\"}" | grep -c 'sluzby-b960'; }

# a component with a property, published; a page that uses it (with its own value) next to a gallery and custom HTML
mcp save_component '{"name":"Karta B960","properties":[{"klic":"nadpis","popisek":"Nadpis","typ":"text","vychozi":"Výchozí B960"}]}' > "$WORK/response"; S960_COMP=$(mcp_value id)
mcp save_build "{\"component\":${S960_COMP:-0},\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"{{nadpis}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Kamenné zídky Q960</p>\"}}]}]}}" > /dev/null
mcp publish_build "{\"component\":${S960_COMP:-0}}" > /dev/null
mcp create_page '{"title":"Služby B960","slug":"sluzby-b960","content":"<p>Úvod</p>","visible":true}' > "$WORK/response"; S960_PAGE=$(mcp_value id)
mcp save_build "{\"id\":${S960_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"component\",\"content\":{\"component\":\"${S960_COMP:-0}\",\"values\":{\"nadpis\":\"Zahradní architektura Z960\"}}},
  {\"type\":\"gallery\",\"content\":{\"photos\":[{\"src\":\"/media/neni-b960.jpg\",\"alt\":\"Jezírko G960\"}]}},{\"type\":\"custom_html\",\"content\":{\"code\":\"<table><tr><td>Montáž</td><td>Servis H960</td></tr></table>\"}}]}]}}" > /dev/null
mcp publish_build "{\"id\":${S960_PAGE:-0}}" > /dev/null
expect "3.53 B5: publishing the page indexes at once the text inside the component with the value of this use, the gallery description and the custom HTML (cells apart); the page content (text) stays without them" \
  "$(s960_page)|$(sq "SELECT CONCAT(text LIKE '%Kamenné zídky Q960%', text LIKE '%G960%', text LIKE '%H960%') FROM ka_stranky WHERE ids = ${S960_PAGE:-0}")" \
  "sluzby b960 zahradni architektura z960 kamenne zidky q960 jezirko g960 montaz servis h960|000"
expect "3.53 B5: search_site finds the page by a word inside the component, the value of the use, the gallery description and the custom HTML" \
  "$(s960_found 'kamenne zidky q960')$(s960_found 'architektura z960')$(s960_found 'jezirko g960')$(s960_found 'servis h960')" "1111"

# the component changes and is published: the page is emptied and (3.54, SearchIndex::soon) filled again at once from the new text
mcp save_build "{\"component\":${S960_COMP:-0},\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"{{nadpis}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Gabionové ploty P960</p>\"}}]}]}}" > /dev/null
S960_DRAFT=$(s960_page)
mcp publish_build "{\"component\":${S960_COMP:-0}}" > /dev/null
expect "3.53 B5: a component draft leaves the page index; publishing the component re-indexes it at once (3.54: not NULL until the job)" "$S960_DRAFT|$(s960_page)" "sluzby b960 zahradni architektura z960 kamenne zidky q960 jezirko g960 montaz servis h960|sluzby b960 zahradni architektura z960 gabionove ploty p960 jezirko g960 montaz servis h960"
run_job search_index > /dev/null
expect "3.53 B5: after the job the page has the component's new text – search_site finds the new words, not the old ones" \
  "$(s960_page)|$(s960_found 'gabionove ploty p960')$(s960_found 'kamenne zidky q960')" "sluzby b960 zahradni architektura z960 gabionove ploty p960 jezirko g960 montaz servis h960|10"

# a new default value of a property (the page gives its own: no change in words, but the page is re-indexed), then the component is deleted
mcp save_component "{\"id\":${S960_COMP:-0},\"properties\":[{\"klic\":\"nadpis\",\"popisek\":\"Nadpis\",\"typ\":\"text\",\"vychozi\":\"Jiný B960\"}]}" > /dev/null
S960_PROPS=$(s960_page); run_job search_index > /dev/null
mcp delete_component "{\"id\":${S960_COMP:-0}}" > /dev/null
S960_DELETED=$(s960_page); run_job search_index > /dev/null
expect "3.53 B5: new properties and deleting the component re-index the page (3.54: at once); the deleted component's text is gone" \
  "$S960_PROPS|$S960_DELETED|$(s960_page)|$(s960_found 'gabionove ploty p960')" "sluzby b960 zahradni architektura z960 gabionove ploty p960 jezirko g960 montaz servis h960|sluzby b960 jezirko g960 montaz servis h960|sluzby b960 jezirko g960 montaz servis h960|0"

sq "DELETE FROM ka_stranky WHERE ids = ${S960_PAGE:-0}" > /dev/null
