echo "== 3.22 (C10): a reply sent from the enquiry – the site's mail, Reply-To the user, the signature, logged on the enquiry"
C10_USER=$(sq "SELECT CONCAT_WS('|', idu, email, IFNULL(team_item_id, '')) FROM ka_uzivatele WHERE user = 'admin'"); C10_IDU=${C10_USER%%|*}
C10_PERSON=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'petr-podpis' AND smazano IS NULL LIMIT 1")
sq "UPDATE ka_uzivatele SET email = 'alex@firma.example', team_item_id = ${C10_PERSON:-NULL} WHERE idu = $C10_IDU; REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''), ('mail_reply_to', 'info@firma.example')" > /dev/null
C10=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, stranka, email, data, stav, navrh_odpovedi) VALUES ('$(site_time)', 'Poptávka oken', 'stranka:1', '/kontakt', 'hana@example.cz', '[[\"Zpráva\",\"Kolik stojí okna?\"]]', 0, 'Dobrý den, pošleme nabídku.'); SELECT LAST_INSERT_ID();")
c10_reply() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$1"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=reply" -d "_csrf=$(csrf)" -d "id=$1" "${@:2}"; }
check "3.22 C10: without SMTP the detail says why and keeps the mailto: link with the draft" 200 "/admin.php?module=enquiries&action=detail&id=$C10" "data-reply-blocked"
grep -q 'href="mailto:hana@example.cz?subject=Re%3A%20Kolik%20stoj%C3%AD%20okna%3F&amp;body=Dobr' "$WORK/response" && ! grep -q 'data-reply-form' "$WORK/response" \
  && echo "  ok     3.22 C10: no Send button without SMTP, the mailto: fallback stays" || { echo "  CHYBA  3.22 C10: the detail without SMTP"; ERRORS=$((ERRORS+1)); }
c10_reply "$C10" --data-urlencode "subject=Re: Kolik stojí okna?" --data-urlencode "text=Dobrý den"
expect "3.22 C10: a reply posted without SMTP is refused and nothing is logged" "$(sq "SELECT CONCAT(IFNULL(replies, 'none'), '|', stav) FROM ka_poptavky WHERE idp = $C10")" "none|1"
mkdir -p "$WORK/smtp-c10"; command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$WORK/smtp-c10" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
check "3.22 C10: with SMTP the detail offers the reply form" 200 "/admin.php?module=enquiries&action=detail&id=$C10" "data-reply-form"
grep -q 'name="subject" maxlength="200" value="Re: Kolik stojí okna?"' "$WORK/response" && grep -q '>Dobrý den, pošleme nabídku.</textarea>' "$WORK/response" && grep -q 'alex@firma.example' "$WORK/response" && grep -q 'data-potvrdit="Poslat tuto odpověď hned na hana@example.cz?' "$WORK/response" \
  && echo "  ok     3.22 C10 (3.25 CPO #7): the subject Re: <first 60 characters of the message>, the draft, the user's address and a confirmation before sending" || { echo "  CHYBA  3.22 C10: the reply form"; ERRORS=$((ERRORS+1)); }
# 3.23.1 (N-C5): the hint promises the signature only to a user who has one; without it, it says so and links My e-mail signature
! grep -q 'data-reply-no-signature' "$WORK/response" && grep -q 'Pod text se přidá váš e-mailový podpis' "$WORK/response" \
  && echo "  ok     3.23.1 N-C5: with a signature the reply form says it is added" || { echo "  CHYBA  3.23.1 N-C5: the hint with a signature"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_uzivatele SET team_item_id = NULL, email = 'bez-podpisu@firma.example' WHERE idu = $C10_IDU" > /dev/null
# 3.37: without a Team record the user has the site's fallback signature (Builder\UserSignature) – the hint promises it
check "3.37: without a Team record the reply form still promises the (fallback) signature" 200 "/admin.php?module=enquiries&action=detail&id=$C10" 'Pod text se přidá váš e-mailový podpis'
! grep -q 'data-reply-no-signature' "$WORK/response" \
  && echo "  ok     3.37: … and never says the reply goes without one" || { echo "  CHYBA  3.37: the hint without a Team record"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_uzivatele SET email = 'alex@firma.example', team_item_id = ${C10_PERSON:-NULL} WHERE idu = $C10_IDU" > /dev/null
# a line break in the subject never becomes a header; the only recipient is the enquiry's address
c10_reply "$C10" --data-urlencode $'subject=Re: Poptávka oken\r\nBcc: zly@evil.example' --data-urlencode $'text=Dobrý den,\npošleme nabídku do pátku.'
F=$(grep -l "^X-Rcpt-To: hana@example.cz" "$WORK"/smtp-c10/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then eml "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
expect "3.22 C10: one recipient, From the site, Reply-To the user (not the site's reply address), no injected header" \
  "$(grep -c '^X-Rcpt-To:' "$F" 2>/dev/null)|$(grep -c '^From: .*<web@example.cz>' "$WORK/eml.txt")|$(grep -c '^Reply-To: alex@firma.example' "$WORK/eml.txt")|$(grep -ci '^Bcc:' "$WORK/eml.txt")|$(ls "$WORK"/smtp-c10/*.eml | wc -l | tr -d ' ')" "1|1|1|0|1"
expect "3.22 C10: the text as written, the signature from My account in the text and the HTML part" \
  "$(grep -c '^pošleme nabídku do pátku.$' "$WORK/eml.txt")|$(grep -c '^-- $' "$WORK/eml.txt")|$([ -n "$C10_PERSON" ] && grep -c 'Petr Podpis' "$WORK/eml.txt")|$([ -n "$C10_PERSON" ] && grep -c 'max-width:600px' "$WORK/eml.txt")" "1|1|2|1"
# 3.25 (CPO #7): the customer's message quoted below the signature – "> " lines in the text part, a blockquote in the HTML part
expect "3.25 CPO #7: the sent reply quotes the customer's message below the signature" \
  "$(grep -c '^> Kolik stojí okna?$' "$WORK/eml.txt")|$(grep -c 'hana@example.cz:$' "$WORK/eml.txt")|$(awk '/^-- $/{s=NR} /^> Kolik/{q=NR} END{print (s>0 && q>s)}' "$WORK/eml.txt")|$([ -n "$C10_PERSON" ] && grep -c '<blockquote style=.*>Kolik stojí okna?</blockquote>' "$WORK/eml.txt")" "1|1|1|1"
expect "3.22 C10: logged on the enquiry (sent, to, Reply-To, the text), resolved, handled by the sender" \
  "$(sq "SELECT CONCAT_WS('|', stav, prirazeno, JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].status')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].to')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].reply_to')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].via')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].text')) LIKE '%do pátku.') FROM ka_poptavky WHERE idp = $C10")" \
  "2|$C10_IDU|sent|hana@example.cz|alex@firma.example|admin|1"
expect "3.22 C10: the change log and the event name the enquiry, never the address or the text" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'reply' AND popis = '#$C10'")|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'enquiry.replied' AND data LIKE '%\"enquiry\":$C10%' AND CONCAT(message, data) NOT LIKE '%hana@%' AND CONCAT(message, data) NOT LIKE '%pátku%'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'reply'")" "1|1|1"
check "3.22 C10: the history on the detail – who, when, delivered, the text" 200 "/admin.php?module=enquiries&action=detail&id=$C10" 'data-reply-status="sent"'
grep -q 'Odpověděl(a) ' "$WORK/response" && grep -q 'pošleme nabídku do pátku.' "$WORK/response" && grep -q '<textarea class="textbox" id="odpoved-text" name="text" rows="8" maxlength="10000" required></textarea>' "$WORK/response" \
  && echo "  ok     3.22 C10: the history shows the reply and the next reply starts empty (no double send of the draft)" || { echo "  CHYBA  3.22 C10: the history"; ERRORS=$((ERRORS+1)); }
expect "3.27: right after the reply the detail offers the follow-up first (once)" "$(grep -c 'data-task-follow-up data-task-replied' "$WORK/response")|$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&action=detail&id=$C10" | grep -c 'data-task-replied')" "1|0"
# the mail server refuses: the reply waits in the queue, the person is told so, the enquiry stays open when asked
C10Q=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'odmitnout@example.cz', '[]', 0); SELECT LAST_INSERT_ID();")
c10_reply "$C10Q" -d keep_open=1 --data-urlencode "subject=Re: Kontakt" --data-urlencode "text=Dobrý den, ozveme se."
expect "3.22 C10: a refused delivery is queued for another attempt, the enquiry kept open" \
  "$(sq "SELECT CONCAT_WS('|', p.stav, JSON_UNQUOTE(JSON_EXTRACT(p.replies, '\$[0].status')), m.telo IS NOT NULL, m.odeslano IS NULL) FROM ka_poptavky p JOIN ka_posta m ON m.idp = JSON_EXTRACT(p.replies, '\$[0].mail') WHERE p.idp = $C10Q")" "1|queued|1|1"
check "3.22 C10: the detail says the reply waits in the queue" 200 "/admin.php?module=enquiries&action=detail&id=$C10Q" 'data-reply-status="queued"'
# no e-mail: nothing to send to; an anonymised enquiry neither
C10N=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt', '', '[]', 1); SELECT LAST_INSERT_ID();")
check "3.22 C10: an enquiry without an e-mail has no reply form" 200 "/admin.php?module=enquiries&action=detail&id=$C10N" "data-reply-none"
c10_reply "$C10N" --data-urlencode "text=Dobrý den"
expect "3.22 C10: and a posted reply to it is refused" "$(sq "SELECT IFNULL(replies, 'none') FROM ka_poptavky WHERE idp = $C10N")" "none"
# MCP: a real e-mail only with confirm=true; the history says it came through Claude
mcp reply_to_enquiry "{\"id\":$C10Q,\"text\":\"Hello\"}" > "$WORK/response"
contains -q 'confirm=true' "$WORK/response" && echo "  ok     3.22 C10: reply_to_enquiry refuses without confirm=true" || { echo "  CHYBA  3.22 C10: reply_to_enquiry without confirm"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp reply_to_enquiry "{\"id\":$C10,\"text\":\"Dobrý den, posíláme ještě ceník.\",\"keep_open\":true,\"confirm\":true}" > "$WORK/response"
expect "3.22 C10: reply_to_enquiry sends with confirm=true, the history has a second reply through Claude" \
  "$(mcp_value status)|$(sq "SELECT CONCAT_WS('|', JSON_LENGTH(replies), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[1].via')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[1].subject'))) FROM ka_poptavky WHERE idp = $C10")" "sent|2|mcp|Re: Kolik stojí okna?"
# anonymising keeps who replied and when, drops the address, the subject and the text; the personal data file carries the replies
mcp find_personal_data '{"email":"hana@example.cz"}' > /dev/null
C10_EXPORT=$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $f = Kaleta\Core\PersonalData::find($app->db(), "hana@example.cz"); foreach ($f["enquiries"] as $e) { if ((int) $e["idp"] === (int) $argv[1]) { echo count($e["replies"]), "|", $e["replies"][0]["text"]; } }' "$C10")
expect "3.22 C10: the personal data file carries the replies sent to the person" "$C10_EXPORT" "2|Dobrý den,
pošleme nabídku do pátku."
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$C10"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$(csrf)" -d "idp=$C10"
expect "3.22 C10: an anonymised enquiry keeps who replied and when, without the address, the subject and the text" \
  "$(sq "SELECT CONCAT_WS('|', JSON_LENGTH(replies), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].by')) <> '', JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].to')), JSON_UNQUOTE(JSON_EXTRACT(replies, '\$[0].text')), replies LIKE '%hana%' OR replies LIKE '%pátku%') FROM ka_poptavky WHERE idp = $C10")" "2|1|||0"
check "3.22 C10: and has no reply form" 200 "/admin.php?module=enquiries&action=detail&id=$C10" "data-reply-none"
