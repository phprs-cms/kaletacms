echo "== 3.22 (C15): the Form's own confirmation e-mail text"
mcp create_page '{"title":"Potvrzení 322","slug":"potvrzeni-322","visible":true}' > /dev/null
C15_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'potvrzeni-322'")
mcp save_build "{\"id\":${C15_PAGE:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"id\":\"c15own\",\"type\":\"form\",\"content\":{\"name\":\"Poptávka 322\",\"confirmation\":true,\"confirmation_text\":\"Dobrý den,\\nozveme se do 24 hodin.\\nKatalog: https://example.cz/katalog\",\"thank_you\":\"Díky 322\",\"next_steps\":\"Zavoláme\",\"fields\":[{\"label\":\"E-mail\",\"type\":\"email\",\"required\":true}]}},{\"id\":\"c15def\",\"type\":\"form\",\"content\":{\"name\":\"Výchozí 322\",\"confirmation\":true,\"thank_you\":\"Díky 322\",\"next_steps\":\"Zavoláme\",\"fields\":[{\"label\":\"E-mail\",\"type\":\"email\",\"required\":true}]}}]}]}}" > "$WORK/response"
expect "3.22 C15: confirmation_text over MCP is stored as potvrzeni_text in the published build" "$(sq "SELECT stavba LIKE '%\"potvrzeni_text\":\"Dobrý den,%ozveme se do 24 hodin.%' FROM ka_stranky WHERE ids = ${C15_PAGE:-0}")" "1"
mcp get_build "{\"id\":${C15_PAGE:-0}}" > "$WORK/response"
contains -q 'confirmation_text' "$WORK/response" && ! contains -q 'potvrzeni_text' "$WORK/response" && echo "  ok     3.22 C15: get_build gives it back as confirmation_text" || { echo "  CHYBA  3.22 C15: get_build vocabulary"; ERRORS=$((ERRORS+1)); }
c15_send() { # c15_send <element id> <e-mail>
  rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/potvrzeni-322"
  php -r '$h = file_get_contents($argv[1]); preg_match("~<form[^>]*>(?:(?!</form>).)*name=\"prvek\" value=\"" . $argv[2] . "\"(?:(?!</form>).)*</form>~s", $h, $m); file_put_contents($argv[1], $m[0] ?? "");' "$WORK/formular.html" "$1"
  local source element time signature; source=$(field_value zdroj); element=$(field_value prvek); time=$(field_value as_cas); signature=$(field_value as_podpis); sleep 4
  curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$source" -d "prvek=$element" -d zpet=/potvrzeni-322 -d "as_cas=$time" -d "as_podpis=$signature" --data-urlencode "p0=$2"; }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
case "$(c15_send c15own vlastni322@example.cz)" in *result=ok*) echo "  ok     3.22 C15: the form with its own text was sent";; *) echo "  CHYBA  3.22 C15: sending the form"; ERRORS=$((ERRORS+1));; esac
F=$(grep -l "^X-Rcpt-To: vlastni322@example.cz" "$WORK"/smtp-c10/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
expect "3.22 C15: the confirmation carries the form's own text with its lines, not the default thank-you" \
  "$(grep -c '^Dobrý den,$' "$WORK/eml.txt")|$(grep -c '^ozveme se do 24 hodin.$' "$WORK/eml.txt")|$(grep -c '^Katalog: https://example.cz/katalog$' "$WORK/eml.txt")|$(grep -c 'Díky 322\|Zavoláme' "$WORK/eml.txt")" "1|1|1|0"
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
c15_send c15def vychozi322@example.cz > /dev/null
F=$(grep -l "^X-Rcpt-To: vychozi322@example.cz" "$WORK"/smtp-c10/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
expect "3.22 C15: without its own text the confirmation is the thank-you with the next steps, as before" "$(grep -c '^Díky 322$' "$WORK/eml.txt")|$(grep -c '^1. Zavoláme$' "$WORK/eml.txt")" "1|1"
mcp trash_page "{\"id\":${C15_PAGE:-0}}" > /dev/null
sq "DELETE FROM ka_poptavky WHERE email IN ('vlastni322@example.cz', 'vychozi322@example.cz')" > /dev/null
kill "$SMTP_PID" 2>/dev/null || true
C10_REST=${C10_USER#*|}; C10_TEAM=${C10_REST#*|}
sq "UPDATE ka_uzivatele SET email = '${C10_REST%%|*}', team_item_id = ${C10_TEAM:-NULL} WHERE idu = $C10_IDU; DELETE FROM ka_poptavky WHERE idp IN ($C10, $C10Q, $C10N); DELETE FROM ka_posta WHERE komu IN ('hana@example.cz', 'odmitnout@example.cz');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''), ('mail_reply_to', '')" > /dev/null
