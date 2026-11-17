echo "== 3.44 B: the quote editor – Up/Down and Remove without JavaScript, the landing line, the guards and the send page's summary"
B344_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
case ",$B344_EXT," in *,quotes,*) ;; *) sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '${B344_EXT:+$B344_EXT,}quotes')" > /dev/null ;; esac
B344_CSRF=$(csrf)
b344_post() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/b344-headers" -X POST "$B/admin.php?module=quotes&action=$1" -d "_csrf=$B344_CSRF" "${@:2}"; { grep -i '^location:' "$WORK/b344-headers" || true; } | tr -d '\r' | sed 's/^[Ll]ocation: //'; }
b344_post create > /dev/null
B344_Q=$(sq "SELECT MAX(id) FROM ka_quotes WHERE status = 'draft'"); B344_Q="${B344_Q:-0}"
check "3.44 B: the editor of a new draft opens" 200 "/admin.php?module=quotes&action=edit&id=$B344_Q" 'data-quote-editor'
expect "3.44 B: a draft is titled Draft quote, not #id (the site's language)" "$(grep -c "<title>#$B344_Q" "$WORK/response")|$(php -r '$d = require $argv[1]; echo preg_match("~<title>(Draft quote|" . preg_quote($d["Draft quote"], "~") . ")~u", file_get_contents($argv[2]));' -- "$ROOT/system/jazyky/admin-cs.php" "$WORK/response")" "0|1"
grep -q 'data-quote-editor data-unsaved' "$WORK/response" && grep -q 'data-quote-send-check' "$WORK/response" \
  && echo "  ok     3.44 B: the editor carries data-unsaved and the check before Save and send" || { echo "  CHYBA  3.44 B: the editor guard or the send check"; ERRORS=$((ERRORS+1)); }
# the editor's lines as posted (parallel lists), in their stored order
b344_lines() {
  B344_LINES=(); for b344_id in $(sq "SELECT id FROM ka_quote_lines WHERE quote_id = $B344_Q ORDER BY position"); do
    B344_LINES+=(-d "line_id[]=$b344_id" -d "line_item[]=" -d "line_variant[]=" -d "line_code[]=" --data-urlencode "line_name[]=$(sq "SELECT name FROM ka_quote_lines WHERE id = $b344_id")" -d "line_description[]="
      -d "line_quantity[]=1" -d "line_unit[]=" -d "line_price[]=10" -d "line_discount[]=0" -d "line_vat[]=21.00" -d "line_removed[]=${1:-0}"); done
}
for b344_name in Alfa Beta Gama; do b344_lines; b344_post save -d id=$B344_Q ${B344_LINES[@]+"${B344_LINES[@]}"} --data-urlencode "add_free_name=$b344_name 344" -d add_free_price=10 -d add_free_quantity=1 -d next=add > "$WORK/b344-loc"; done
expect "3.44 B: Add a line lands on the new line, not under the sticky bar" "$(sed 's/.*#/#/' "$WORK/b344-loc")" "#line-3"
b344_order() { sq "SELECT GROUP_CONCAT(name ORDER BY position SEPARATOR ',') FROM ka_quote_lines WHERE quote_id = $B344_Q"; }
b344_lines; B344_LOC=$(b344_post save -d id=$B344_Q ${B344_LINES[@]+"${B344_LINES[@]}"} -d line_move=0:down)
expect "3.44 B: Down without JavaScript saves the line one place lower and lands on it" "${B344_LOC##*#}|$(b344_order)" "line-2|Beta 344,Alfa 344,Gama 344"
b344_lines; B344_LOC=$(b344_post save -d id=$B344_Q ${B344_LINES[@]+"${B344_LINES[@]}"} -d line_move=0:up)
expect "3.44 B: Up past the first line changes nothing" "$(b344_order)" "Beta 344,Alfa 344,Gama 344"
b344_lines; B344_LOC=$(b344_post save -d id=$B344_Q ${B344_LINES[@]+"${B344_LINES[@]}"} -d line_drop=2)
expect "3.44 B: Remove without JavaScript saves the quote without that line" "${B344_LOC##*#}|$(b344_order)" "lines|Beta 344,Alfa 344"
# with JavaScript the rows are reordered in the page and Remove sets the row's line_removed[] flag
B344_IDS=($(sq "SELECT id FROM ka_quote_lines WHERE quote_id = $B344_Q ORDER BY position"))
b344_post save -d id=$B344_Q -d "line_id[]=${B344_IDS[1]:-0}" -d "line_item[]=" -d "line_variant[]=" -d "line_code[]=" -d "line_name[]=Alfa 344" -d "line_description[]=" -d "line_quantity[]=1" -d "line_unit[]=" -d "line_price[]=10" -d "line_discount[]=0" -d "line_vat[]=21.00" -d "line_removed[]=0" \
  -d "line_id[]=${B344_IDS[0]:-0}" -d "line_item[]=" -d "line_variant[]=" -d "line_code[]=" -d "line_name[]=Beta 344" -d "line_description[]=" -d "line_quantity[]=1" -d "line_unit[]=" -d "line_price[]=10" -d "line_discount[]=0" -d "line_vat[]=21.00" -d "line_removed[]=0" > /dev/null
expect "3.44 B: the order the page posts is the order saved" "$(b344_order)" "Alfa 344,Beta 344"
b344_lines 1; b344_post save -d id=$B344_Q ${B344_LINES[@]+"${B344_LINES[@]}"} > /dev/null
expect "3.44 B: a line flagged as removed is dropped by Save" "$(sq "SELECT COUNT(*) FROM ka_quote_lines WHERE quote_id = $B344_Q")" "0"
# the send page: what leaves – the customer, the total and the validity – and (with SMTP) the folded preview of the e-mail
b344_post save -d id=$B344_Q --data-urlencode "customer_name=Greta 344" -d customer_email=greta344@example.com -d "add_free_name=Delta 344" -d add_free_price=100 -d next=add > /dev/null
check "3.44 B: the send page names the draft and sums up the customer, the total and the validity" 200 "/admin.php?module=quotes&action=send&id=$B344_Q" 'data-quote-summary'
grep -q 'Greta 344' "$WORK/response" && { ! grep -qE 'data-quote-send[ >]' "$WORK/response" || grep -q 'data-quote-mail-preview' "$WORK/response"; } \
  && echo "  ok     3.44 B: the summary names the customer; a send form has the e-mail preview" || { echo "  CHYBA  3.44 B: the send page summary or preview"; ERRORS=$((ERRORS+1)); }
check "3.44 B: the price grid warns before unsaved prices are lost and keeps Save prices in view" 200 "/admin.php?module=quotes&action=prices" 'data-prices-save'
grep -q 'data-prices-grid data-unsaved' "$WORK/response" && echo "  ok     3.44 B: the price grid carries data-unsaved" || { echo "  CHYBA  3.44 B: the price grid guard"; ERRORS=$((ERRORS+1)); }
check "3.44 B: the pipeline stages warn before unsaved changes are lost" 200 "/admin.php?module=pipeline&action=stages" 'data-stages-form data-unsaved'
sq "DELETE FROM ka_quote_lines WHERE quote_id = $B344_Q; DELETE FROM ka_quotes WHERE id = $B344_Q; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$B344_EXT')" > /dev/null
