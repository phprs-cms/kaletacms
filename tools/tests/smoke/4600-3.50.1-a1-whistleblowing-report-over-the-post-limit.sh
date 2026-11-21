echo "== 3.50.1 A1: a whistleblowing report over the server's post limit is answered with the real limits (UX-c1)"
# the channel on for this section (the earlier sections may have left it in any state), the address buckets cleared
A600_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
A600_ON=$(sq "SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_enabled'), '')")
features +whistleblowing > /dev/null
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('whistleblowing_enabled', '1'); DELETE FROM ka_kontrola_ip WHERE typ LIKE 'oznameni%'" > /dev/null
A600_CASES=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_whistleblowing_cases")
# a second server on the same site with a small post_max_size (as 4280)
A600_PORT=$((PORT + 27)); A600_B="http://127.0.0.1:$A600_PORT"
(cd "$WORK/web" && exec php -d post_max_size=1M -d upload_max_filesize=1M -d display_errors=0 -S "127.0.0.1:$A600_PORT" system/dev-router.php > "$WORK/server-a600.log" 2>&1) & A600_PID=$!
for i in $(seq 1 100); do curl -s -o /dev/null "$A600_B/" && break; sleep 0.2; done
curl -s -o "$WORK/a600-page.html" "$A600_B/_report" || true
expect "3.50.1 A1: the report page carries the limits for the browser (the body limit, 10 MB per file), keeps image/web.js and has no data-formular (its text is never kept in the browser)" \
  "$(grep -c 'data-max-post="1048576"' "$WORK/a600-page.html")|$(grep -c 'data-max-bytes="10485760"' "$WORK/a600-page.html")|$(grep -c '/image/web\.js' "$WORK/a600-page.html")|$(grep -c 'data-formular' "$WORK/a600-page.html")" "1|1|1|0"
grep -q 'Až 5 souborů, každý do 10 MB a dohromady do 1 MB\|Up to 5 files, each up to 10 MB and together up to 1 MB' "$WORK/a600-page.html" \
  && echo "  ok     3.50.1 A1: the hint says the real total the server accepts" || { echo "  CHYBA  3.50.1 A1: the hint does not name the total"; ERRORS=$((ERRORS+1)); }
A600_TIME=$(grep -o 'name="as_cas" value="[^"]*"' "$WORK/a600-page.html" | head -1 | sed 's/.*value="//;s/"$//')
A600_SIGNATURE=$(grep -o 'name="as_podpis" value="[^"]*"' "$WORK/a600-page.html" | head -1 | sed 's/.*value="//;s/"$//')
head -c 2200000 /dev/zero > "$WORK/a600-big.bin"
A600_BEFORE=$(sq "SELECT COUNT(*) FROM ka_kontrola_ip")
A600_HTTP=$(curl -s -o "$WORK/a600-big.html" -w '%{http_code}' -X POST "$A600_B/_report" -F "as_cas=$A600_TIME" -F "as_podpis=$A600_SIGNATURE" --form-string "text=A600 big report" -F name= -F contact= -F "files[]=@$WORK/a600-big.bin;filename=big.pdf")
expect "3.50.1 A1: a 2 MB body answers 413 with the size text and the limits, no case is stored and no antispam attempt is counted" \
  "$A600_HTTP|$(grep -c 'Soubory jsou příliš velké, proto se nic neodeslalo. Jeden soubor může mít nejvýše 10 MB, všechny soubory dohromady nejvýše 1 MB.\|The files are too large, so nothing was sent. One file can have up to 10 MB, all files together up to 1 MB.' "$WORK/a600-big.html")|$(sq "SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE id > $A600_CASES")|$(sq "SELECT COUNT(*) FROM ka_kontrola_ip")" \
  "413|1|0|$A600_BEFORE"
sleep 4
A600_HTTP=$(curl -s -o "$WORK/a600-ok.html" -w '%{http_code}' -X POST "$A600_B/_report" -F "as_cas=$A600_TIME" -F "as_podpis=$A600_SIGNATURE" --form-string "text=A600 normal report" -F name= -F contact=)
expect "3.50.1 A1: a normal report on the same server is still received with a case number" \
  "$A600_HTTP|$(grep -c 'ka-oznameni-cislo' "$WORK/a600-ok.html")|$(sq "SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE id > $A600_CASES")" "200|1|1"
kill "$A600_PID" 2> /dev/null || true; wait "$A600_PID" 2> /dev/null || true
sq "DELETE FROM ka_whistleblowing_cases WHERE id > $A600_CASES; DELETE FROM ka_kontrola_ip WHERE typ LIKE 'oznameni%';
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('whistleblowing_enabled', '$A600_ON')" > /dev/null
features --exact "$A600_EXT" > /dev/null
rm -f "$WORK/a600-page.html" "$WORK/a600-big.html" "$WORK/a600-ok.html" "$WORK/a600-big.bin"
