echo "== 2.11: document library – versions, the stable latest address, download counts, gated downloads"
mcp create_collection '{"name":"Dokumenty","preset":"documents"}' > "$WORK/response"
DOCS=$(sq "SELECT seo_link FROM ka_kolekce WHERE preset = 'documents' ORDER BY idk DESC LIMIT 1"); DOCS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$DOCS'")
expect "documents: the preset brings the file, category, version, summary and issued fields and item pages" "$(sq "SELECT CONCAT(detail, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].klic')), ':', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[4].klic'))) FROM ka_kolekce WHERE idk = $DOCS_IDK")" "1|file:soubor|issued"
expect "documents: the item template downloads through {{latest}} and lists {{versions}}; the list page sorts by name and filters by category" "$(sq "SELECT CONCAT(stavba LIKE '%{{latest}}%', stavba LIKE '%{{versions}}%', (SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', stavba LIKE '%\"razeni\":\"nazev\"%') FROM ka_stranky WHERE seo_link = '$DOCS')) FROM ka_kolekce WHERE idk = $DOCS_IDK")" "1111"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"name\":\"Ceník\",\"slug\":\"cenik\",\"values\":{\"file\":\"/media/cenik-v1.pdf\",\"version\":\"1.0\",\"category\":\"Ceníky\",\"summary\":\"Platný ceník.\",\"issued\":\"2026-01-10\"},\"visible\":true}" > "$WORK/response"
DOC=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $DOCS_IDK AND seo_link = 'cenik'")
expect "documents: save_collection_item returns the stable address of the file" "$(mcp_value latest_url)" "$B/$DOCS/cenik/latest"
expect "documents: a new document has no previous version" "$(sq "SELECT COUNT(*) FROM ka_document_versions WHERE idp = $DOC")" "0"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"values\":{\"file\":\"/media/cenik-v2.pdf\",\"version\":\"2.0\"}}" > /dev/null
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"values\":{\"summary\":\"Platný ceník, nové ceny.\"}}" > /dev/null
expect "documents: a changed file keeps the previous file and its version for good, a save without a file change keeps nothing" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(file), '|', MAX(version), '|', MAX(replaced_by) LIKE 'Tester%') FROM ka_document_versions WHERE idp = $DOC")" "1|/media/cenik-v1.pdf|1.0|1"
curl -s -o "$WORK/response" "$B/$DOCS/cenik"
grep -q "href=\"/$DOCS/cenik/latest\"" "$WORK/response" && grep -q 'cenik-v2.pdf)' "$WORK/response" && grep -q 'href="/media/cenik-v1.pdf">cenik-v1.pdf · Verze 1.0</a>' "$WORK/response" && grep -q 'Předchozí verze' "$WORK/response" \
  && echo "  ok     documents: the item page downloads through the stable address and lists the previous version with its number" || { echo "  CHYBA  stránka dokumentu"; grep -o 'ka-dokument-verze.*</ul>' "$WORK/response" | head -c 400; ERRORS=$((ERRORS+1)); }
expect "documents: /…/latest answers 302 to the current file" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code} %{redirect_url}' "$B/$DOCS/cenik/latest")" "302 $B/media/cenik-v2.pdf"
curl -s -A 'Mozilla/5.0 test' -o /dev/null -D "$WORK/headers" "$B/$DOCS/cenik/latest"
grep -qi '^Cache-Control: no-store' "$WORK/headers" && echo "  ok     documents: the redirect to the file is never cached" || { echo "  CHYBA  latest Cache-Control"; cat "$WORK/headers"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/$DOCS/cenik/latest" # curl's own user agent counts as a bot
expect "documents: two downloads from one address within an hour count once, a bot never" "$(sq "SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = $DOC")" "1"
check "documents: the admin items list shows the downloads (30 days / total)" 200 "/admin.php?module=collections&action=items&id=$DOCS_IDK" '<td class="cislo stazeni">1 / 1</td>'
grep -q "href=\"/$DOCS/cenik/latest\"" "$WORK/response" && echo "  ok     documents: the admin items list links the stable address" || { echo "  CHYBA  admin latest link"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items "{\"collection\":\"$DOCS\"}" > "$WORK/response"
expect "MCP: list_collection_items carries the downloads and the stable address of a document" "$(mcp_value items 0 downloads total)|$(mcp_value items 0 downloads last_30_days)|$(mcp_value items 0 latest_url)" "1|1|$B/$DOCS/cenik/latest"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":false}" > /dev/null
expect "documents: a hidden document has no download address" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$B/$DOCS/cenik/latest")" "404"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":true,\"valid_until\":\"$YESTERDAY\"}" > /dev/null
expect "documents: an expired document has no download address even before the hourly job hides it" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$B/$DOCS/cenik/latest")" "404"
IN_TEN_DAYS=$(site_time "+10 days" "Y-m-d")
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":true,\"valid_until\":\"$IN_TEN_DAYS\"}" > /dev/null
mcp site_audit '{"kind":"document"}' > "$WORK/response"
expect "documents: the site audit warns 30 days before a document expires, with the item to fix" "$(mcp_value total)|$(mcp_value findings 0 target item)" "1|$DOC"
check "Administration → Site audit shows the expiring document" 200 "/admin.php?module=audit" "Dokument platí do"
# gated downloads: a form that e-mails a file after sending – the enquiry records it, the e-mail carries a signed link (fake SMTP)
SMTP2_PORT=$((PORT + 7)); mkdir -p "$WORK/smtp2"
command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$WORK/smtp2" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
mcp create_page '{"title":"Ceník e-mailem","slug":"cenik-emailem","visible":true}' > /dev/null
GATE_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'cenik-emailem'")
mcp stavba_uloz "{\"id\":$GATE_PAGE,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"gate123\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Ceník na e-mail\",\"poslat_soubor\":\"/media/cenik-v2.pdf\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}]}}" > "$WORK/response"
expect "gated: the published form keeps the file to send" "$(sq "SELECT stavba LIKE '%\"poslat_soubor\":\"/media/cenik-v2.pdf\"%' FROM ka_stranky WHERE ids = $GATE_PAGE")" "1"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/cenik-emailem"
GATE_SOURCE=$(field_value zdroj); GATE_ELEMENT=$(field_value prvek); GATE_TIME=$(field_value as_cas); GATE_SIGNATURE=$(field_value as_podpis)
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$GATE_SOURCE" -d "prvek=$GATE_ELEMENT" -d zpet=/cenik-emailem -d "as_cas=$GATE_TIME" -d "as_podpis=$GATE_SIGNATURE" --data-urlencode p0=gate@example.cz)
case "$location" in *result=ok*) echo "  ok     gated: the form was sent";; *) echo "  CHYBA  gated form: $location"; ERRORS=$((ERRORS+1));; esac
expect "gated: the enquiry records which file was sent" "$(sq "SELECT data LIKE '%Soubor poslan% e-mailem%cenik-v2.pdf%' FROM ka_poptavky WHERE email = 'gate@example.cz'")" "1"
# a plain-text e-mail: one base64 body (the eml helper above decodes the parts of a multipart newsletter)
gate_mail() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n", base64_decode($b);' -- "$1"; }
F=$(grep -l "^X-Rcpt-To: gate@example.cz" "$WORK"/smtp2/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
GATE_LINK=$(grep -o "http://127.0.0.1:$PORT/download/[A-Za-z0-9._-]*" "$WORK/eml.txt" | head -1 || true)
[ -n "$GATE_LINK" ] && grep -q '^Subject-Decoded: Váš soubor z webu' "$WORK/eml.txt" && grep -q 'cenik-v2.pdf' "$WORK/eml.txt" && echo "  ok     gated: the visitor got an e-mail with the file name and the download link" || { echo "  CHYBA  gated e-mail"; head -30 "$WORK/eml.txt"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'stazeni'" > /dev/null # an hour has passed for the counter
expect "gated: the link redirects to the file and counts the download of the document" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code} %{redirect_url}' "$GATE_LINK")|$(sq "SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = $DOC")" "302 $B/media/cenik-v2.pdf|2"
case "${GATE_LINK: -1}" in a) TAMPERED="${GATE_LINK%?}b";; *) TAMPERED="${GATE_LINK%?}a";; esac
expect "gated: a tampered token is not found" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$TAMPERED")|$(curl -s -o /dev/null -w '%{http_code}' "$B/download/nonsense.token.here")" "404|404"
kill "$SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
