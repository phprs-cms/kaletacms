echo "== 3.24 (N-U3): a new page starts in the builder"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
t324_where() { case "$1" in *"action=builder&id=$2") echo builder;; *action=builder*) echo "another builder: $1";; *) echo list;; esac; } # where a save went
expect "3.24 (N-U3): New page offers three starts – an empty builder page first and chosen, a ready-made layout, a text page; the text editor and Save belong to the text page" \
  "$(grep -c 'name="start" value="builder" id="start-builder" checked' "$WORK/response")|$(grep -c 'name="start" value="layout"' "$WORK/response")|$(grep -c 'name="start" value="text"' "$WORK/response")|$(grep -c 'class="radek pres-celou nova-jen-text"' "$WORK/response")|$(grep -c 'Vytvořit stránku a otevřít builder' "$WORK/response")|$(grep -c 'name="po_ulozeni"' "$WORK/response")" \
  "1|1|1|1|1|0"
location=$(save_page -d ids=0 --data-urlencode "titulek=Údržba zahrad" -d start=builder -d sablona=o-nas -d zobrazit=1 -d v_menu=0 --data-urlencode "text=<p>Typed, then the builder chosen</p>")
IDU3=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'udrzba-zahrad'")
expect "3.24 (N-U3): an empty builder page opens the builder with the page title band, no text, hidden until its first publish" \
  "$(t324_where "$location" "$IDU3")|$(sq "SELECT CONCAT(text = '', '/', zobrazit, '/', show_on_publish, '/', stavba_koncept LIKE '%\"znacka\":\"h1\"%', '/', stavba_koncept LIKE '%Údržba zahrad%', '/', stavba_koncept NOT LIKE '%pribeh%') FROM ka_stranky WHERE ids = ${IDU3:-0}")" \
  "builder|1/0/1/1/1/1"
location=$(save_page -d ids=0 --data-urlencode "titulek=Naše služby 324" -d start=layout -d sablona=sluzby -d zobrazit=1 -d v_menu=0 -d text=)
expect "3.24 (N-U3): a ready-made layout builds its sections and opens the builder" \
  "$(t324_where "$location" "$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'nase-sluzby-324'")")|$(sq "SELECT (LENGTH(stavba_koncept) - LENGTH(REPLACE(stavba_koncept, '\"typ\":\"sekce\"', ''))) DIV LENGTH('\"typ\":\"sekce\"') >= 4 FROM ka_stranky WHERE seo_link = 'nase-sluzby-324'")" "builder|1"
location=$(save_page -d ids=0 --data-urlencode "titulek=Textová 324" -d start=text -d sablona=o-nas -d zobrazit=1 -d v_menu=0 --data-urlencode "text=<p>Just text</p>")
expect "3.24 (N-U3): a simple text page saves its text, goes live and stays out of the builder (the hidden layout list is ignored)" \
  "$(t324_where "$location" 0)|$(sq "SELECT CONCAT(text, '/', zobrazit, '/', stavba_koncept IS NULL) FROM ka_stranky WHERE seo_link = 'textova-324'")" "list|<p>Just text</p>/1/1"
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d ids=0 -d titulek= -d start=layout -d sablona=kontakt)
expect "3.24 (N-U3): a refused new page keeps the chosen start and layout" "$code|$(grep -c 'value="layout" id="start-layout" checked' "$WORK/response")|$(grep -c 'value="kontakt" selected' "$WORK/response")" "200|1|1"
mcp create_page '{"title":"MCP 324","slug":"mcp-324","content":"<p>From Claude</p>"}' > "$WORK/response"
expect "3.24 (N-U3): MCP create_page is unchanged – a hidden text page without a build" "$(sq "SELECT CONCAT(text, '/', zobrazit, '/', stavba_koncept IS NULL) FROM ka_stranky WHERE seo_link = 'mcp-324'")" "<p>From Claude</p>/0/1"
sq "DELETE FROM ka_stranky WHERE seo_link IN ('udrzba-zahrad', 'nase-sluzby-324', 'textova-324', 'mcp-324')" > /dev/null

