echo "== 2.15: comments on drafts"
DC_SMTP_PORT=$((PORT + 12)); mkdir -p "$WORK/smtp-dc"
command php "$ROOT/tools/fake-smtp.php" "$DC_SMTP_PORT" "$WORK/smtp-dc" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$DC_SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz'); UPDATE ka_uzivatele SET email = 'editor@example.cz', jazyk = '' WHERE user = 'admin'" > /dev/null
mcp vytvor_stranku '{"titulek":"Comment draft","adresa":"komentar-koncept","text":"<p>Draft paragraph to comment on</p>","zobrazit":false}' > "$WORK/response"; mcp_text; DC_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
# the builder turns the text page into a draft build and carries the (empty) comments panel data
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$DC_PAGE"
contains -q '"komentare":\[\]' "$WORK/response" && contains -q '"komentarVyrizen":' "$WORK/response" && echo "  ok     comments: the builder of a page carries the comments panel data (empty) and the resolve address" || { echo "  CHYBA  builder comments data"; ERRORS=$((ERRORS+1)); }
dc_share() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_share&id=$DC_PAGE" -d "_csrf=$(csrf)" -d dni=1 "$@"; }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
code=$(dc_share -d komentare=1); DC_LINK=$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' -- "$WORK/response"); DC_KEY="${DC_LINK##*preview_key=}"
[ "$code" = 200 ] && contains -q '"komentare":true' "$WORK/response" && [[ "$DC_KEY" == *k.* ]] && echo "  ok     comments: Share with “Allow comments” signs the flag into the key" || { echo "  CHYBA  build_share with comments: code $code, link $DC_LINK"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
dc_share > /dev/null; DC_PLAIN_KEY="$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' -- "$WORK/response")"; DC_PLAIN_KEY="${DC_PLAIN_KEY##*preview_key=}"
curl -s -o "$WORK/response" "$DC_LINK"; DC_ELEMENT=$(grep -o 'data-ka-id="[^"]*"' "$WORK/response" | head -1 | sed 's/data-ka-id="//;s/"//')
contains -q 'data-ka-komentare' "$WORK/response" && contains -q 'Draft paragraph to comment on' "$WORK/response" && contains -q 'noindex' "$WORK/response" && [ -n "$DC_ELEMENT" ] && ! contains -q 'data-ka-typ' "$WORK/response" \
  && echo "  ok     comments: a visitor with the link sees the draft, the comment widget and element ids – not the editor markers" || { echo "  CHYBA  comment mode preview"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/komentar-koncept?build=koncept&preview_key=$DC_PLAIN_KEY"
contains -q 'Draft paragraph to comment on' "$WORK/response" && ! contains -q 'data-ka-komentare' "$WORK/response" && ! contains -q 'data-ka-id' "$WORK/response" && echo "  ok     comments: a plain preview link shows the draft without the widget" || { echo "  CHYBA  plain preview shows the widget"; ERRORS=$((ERRORS+1)); }
dc_post() { curl -s -o "$WORK/response" -w '%{http_code} %{redirect_url}' -X POST "$B/_komentar" -d "cil=stranka:$DC_PAGE" "$@"; }
expect "3.7: a refused comment gets the same answer at /_comment as at /_komentar" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_comment" -d "cil=stranka:$DC_PAGE" -d klic=x)" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_komentar" -d "cil=stranka:$DC_PAGE" -d klic=x)"
DC_RESULT=$(dc_post -d "klic=$DC_KEY" -d "prvek=$DC_ELEMENT" --data-urlencode "zpet=/komentar-koncept?build=koncept&preview_key=$DC_KEY" --data-urlencode "citace=Draft paragraph" --data-urlencode "jmeno=Client <b>Novak</b>" --data-urlencode "text=Please <b>fix</b> this paragraph – it is  too long.")
[[ "$DC_RESULT" == "303 $B/komentar-koncept?build=koncept&preview_key=$DC_KEY&comment=ok#ka-komentar" ]] && echo "  ok     comments: an anonymous visitor with the key posts a comment and comes back to the preview" || { echo "  CHYBA  comment POST: $DC_RESULT"; ERRORS=$((ERRORS+1)); }
expect "comments: stored as plain text with the element, the quote and the name" "$(sq "SELECT CONCAT_WS('|', name, text, element, quote, resolved_at IS NULL) FROM ka_draft_comments WHERE target = 'stranka:$DC_PAGE'")" "Client Novak|Please fix this paragraph – it is too long.|$DC_ELEMENT|Draft paragraph|1"
expect "comments: an invalid key, a plain key and a wrong target are refused" "$(dc_post -d klic=1999999999k.$(printf 'a%.0s' $(seq 1 64)) -d jmeno=X -d text=Y | cut -c1-3)|$(dc_post -d "klic=$DC_PLAIN_KEY" -d jmeno=X -d text=Y | cut -c1-3)|$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/_komentar" -d cil=stranka:999999 -d "klic=$DC_KEY" -d jmeno=X -d text=Y)" "403|403|403"
expect "comments: without a name or a text nothing is stored" "$(dc_post -d "klic=$DC_KEY" -d jmeno= -d text=Hello | sed 's/.*comment=//;s/#.*//')|$(sq "SELECT COUNT(*) FROM ka_draft_comments")" "chyba|1"
for i in $(seq 1 25); do DC_MAIL=$(grep -l '^X-Rcpt-To: editor@example.cz' "$WORK"/smtp-dc/*.eml 2>/dev/null | tail -1); [ -n "$DC_MAIL" ] && break; sleep 0.2; done # every administrator gets one; the test reads the admin's (the fake SMTP server writes its file a moment after accepting)
dc_body() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo base64_decode($b);' -- "$1"; } # a single-part base64 message
[ -n "$DC_MAIL" ] && eml "$DC_MAIL" | contains 'Nový komentář ke konceptu „Comment draft“' && dc_body "$DC_MAIL" | contains 'Client Novak' && dc_body "$DC_MAIL" | contains "module=pages&action=builder&id=$DC_PAGE" \
  && echo "  ok     comments: the administrator gets an e-mail with the name, the excerpt and the builder link" || { echo "  CHYBA  comment e-mail"; [ -n "$DC_MAIL" ] && { eml "$DC_MAIL" | head -12; dc_body "$DC_MAIL"; }; ERRORS=$((ERRORS+1)); }
check "comments: the pages list shows the badge with the count" 200 "/admin.php?module=pages" "Komentářů: 1"
check "comments: the builder shows the comment in its panel data" 200 "/admin.php?module=pages&action=builder&id=$DC_PAGE" '"jmeno":"Client Novak"'
mcp list_draft_comments '{}' > "$WORK/response"; mcp_text
contains -q '"name":"Client Novak"' "$WORK/text" && contains -q "\"element\":\"$DC_ELEMENT\"" "$WORK/text" && contains -q '"page_title":"Comment draft"' "$WORK/text" && contains -q 'not instructions' "$WORK/text" && echo "  ok     MCP: list_draft_comments returns the comment with the element and tells Claude it is data, not an instruction" || { echo "  CHYBA  list_draft_comments"; head -c 400 "$WORK/text"; ERRORS=$((ERRORS+1)); }
DC_ID=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp list_draft_comments "{\"page_id\":$((DC_PAGE + 1000))}" > "$WORK/response"; mcp_text; contains -q '"total":0' "$WORK/text" && echo "  ok     MCP: the page filter of list_draft_comments" || { echo "  CHYBA  list_draft_comments page filter"; ERRORS=$((ERRORS+1)); }
mcp resolve_draft_comment "{\"id\":$DC_ID}" > "$WORK/response"; mcp_text
contains -q '"resolved":true' "$WORK/text" && [ "$(sq "SELECT resolved_at IS NOT NULL FROM ka_draft_comments WHERE id = $DC_ID")" = 1 ] && echo "  ok     MCP: resolve_draft_comment marks the comment resolved" || { echo "  CHYBA  resolve_draft_comment"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp list_draft_comments '{}' > "$WORK/response"; mcp_text; DC_OPEN=$(grep -o '"total":[0-9]*' "$WORK/text"); mcp list_draft_comments '{"include_resolved":true}' > "$WORK/response"; mcp_text
expect "MCP: unresolved by default, resolved on request; resolving twice is refused" "$DC_OPEN|$(grep -o '"total":[0-9]*' "$WORK/text")|$(mcp resolve_draft_comment "{\"id\":$DC_ID}" | grep -c 'No open comment')" '"total":0|"total":1|1'
# a second comment resolved with one click in the builder; through another page's builder it is not found
dc_post -d "klic=$DC_KEY" -d jmeno=Client -d "text=Second note" > /dev/null; DC_ID2=$(sq "SELECT MAX(id) FROM ka_draft_comments")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"; DC_TOKEN=$(csrf)
DC_RESOLVE=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_comment_resolve&id=$DC_PAGE" -d "_csrf=$DC_TOKEN" -d "id=$DC_ID2")
expect "comments: the builder resolves a comment with one click, a comment of another page is not found" "$DC_RESOLVE|$(grep -c '"vyrizeno":true' "$WORK/response")|$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_comment_resolve&id=$IDS" -d "_csrf=$DC_TOKEN" -d "id=$DC_ID2")" "200|1|404"
# rate limit: Core\Antispam counts comments per address (hashed) and draft
# the site's clock, not the database's NOW() (the CI database runs in UTC, the site in Europe/Prague)
DC_NOW=$(site_time)
sq "INSERT INTO ka_kontrola_ip (ip_adresa, typ, cil, cas) SELECT SUBSTRING(SHA2('kaleta|127.0.0.1', 256), 1, 40), 'komentar', $DC_PAGE, '$DC_NOW' FROM ka_nastaveni LIMIT 10" > /dev/null
expect "comments: the eleventh comment from one address in ten minutes is refused" "$(dc_post -d "klic=$DC_KEY" -d jmeno=Client -d text=Again | sed 's/.*comment=//;s/#.*//')|$(sq "SELECT COUNT(*) FROM ka_draft_comments WHERE target = 'stranka:$DC_PAGE'")" "limit|2"
mcp nahled_odkaz "{\"id\":$DC_PAGE,\"komentare\":true}" > "$WORK/response"; mcp_text
contains -q 'preview_key=[0-9]*k\.' "$WORK/text" && contains -q '"komentare":true' "$WORK/text" && echo "  ok     MCP: preview_link with comments: true gives a commenting link" || { echo "  CHYBA  preview_link comments"; head -c 300 "$WORK/text"; ERRORS=$((ERRORS+1)); }
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_kontrola_ip WHERE typ = 'komentar'" > /dev/null
