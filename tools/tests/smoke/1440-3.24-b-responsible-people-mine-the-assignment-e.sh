echo "== 3.24 (B): responsible people, Mine, the assignment e-mail, review reminders, draft attribution"
[ -z "${SMTP_PID:-}" ] || kill "$SMTP_PID" 2>/dev/null || true # the sink still running (comments, 2.15) – the exit trap knows only the last one
B324_SMTP="$WORK/smtp-324b"; mkdir -p "$B324_SMTP"; command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$B324_SMTP" > /dev/null 2>&1 & SMTP_PID=$!
sq "DROP TABLE IF EXISTS ka_b324_mail; CREATE TABLE ka_b324_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
B324_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES
  ('b324-jana', '$B324_HASH', 'Jana B324', 'jana-b324@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0),
  ('b324-petr', '$B324_HASH', 'Petr B324', 'petr-b324@example.com', '', 1, '$(site_time)', '$(site_time)', 0),
  ('b324-old', '$B324_HASH', 'Old B324', 'old-b324@example.com', '', 1, '$(site_time)', '$(site_time)', 1);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries') m WHERE user = 'b324-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'b324-petr';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'pages' FROM ka_uzivatele WHERE user = 'b324-old'" > /dev/null
B324_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b324-jana'"); B324_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b324-petr'"); B324_OLD=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'b324-old'")
B324_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
B324_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, zmeneno) VALUES ('b324-stranka', 'Stránka B324', '<p>Text B324</p>', 0, '$(site_time '-1 hour')'); SELECT LAST_INSERT_ID();")
b324_mails() { grep -l "^X-Rcpt-To: $1" "$B324_SMTP"/*.eml 2>/dev/null | wc -l | tr -d ' '; }
b324_last() { local f; f=$(grep -l "^X-Rcpt-To: $1" "$B324_SMTP"/*.eml 2>/dev/null | tail -1); if [ -n "$f" ]; then eml "$f"; fi; }
b324_save() { # b324_save <owner id> – the page form posted as it is, with the responsible person
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$B324_PAGE"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$(csrf)" -d "ids=$B324_PAGE" --data-urlencode "titulek=Stránka B324" -d seo_link=b324-stranka --data-urlencode "text=<p>Text B324</p>" -d "owner_id=$1"; }
check "3.24 (B): the page settings offer the responsible person" 200 "/admin.php?module=pages&action=edit&id=$B324_PAGE" 'data-owner-field'
expect "3.24 (B): … only people who can open Pages (an administrator, the editor with Pages – not the one without, not the blocked one), and “—”" \
  "$(sed -n '/name="owner_id">/,/<\/select>/p' "$WORK/response" > "$WORK/owners.html"; grep -c "<option value=\"$B324_JANA\">Jana B324</option>" "$WORK/owners.html")|$(grep -c "<option value=\"$B324_ADMIN\"" "$WORK/owners.html")|$(grep -c "value=\"$B324_PETR\"" "$WORK/owners.html")|$(grep -c "value=\"$B324_OLD\"" "$WORK/owners.html")|$(grep -c 'name="owner_id"><option value="0">—</option>' "$WORK/owners.html")" "1|1|0|0|1"
b324_save "$B324_JANA"
expect "3.24 (B): saved as the responsible person; one e-mail to her, in her admin language (English), with the link" \
  "$(sq "SELECT owner_id FROM ka_stranky WHERE ids = $B324_PAGE")|$(b324_mails jana-b324@example.com)|$(b324_last jana-b324@example.com | grep -c 'Subject-Decoded: You are responsible for the page “Stránka B324”')|$(b324_last jana-b324@example.com | grep -c "module=pages&action=edit&id=$B324_PAGE")" "$B324_JANA|1|1|1"
b324_save "$B324_JANA"; b324_save "$B324_PETR"
expect "3.24 (B): saved again unchanged – no e-mail; someone who cannot open Pages is refused and the person stays" "$(b324_mails jana-b324@example.com)|$(b324_mails petr-b324@example.com)|$(sq "SELECT owner_id FROM ka_stranky WHERE ids = $B324_PAGE")" "1|0|$B324_JANA"
B324_ADMIN_MAILS=$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%Stránka B324%'")
b324_save "$B324_ADMIN"
expect "3.24 (B): choosing oneself sends nothing" "$(sq "SELECT owner_id FROM ka_stranky WHERE ids = $B324_PAGE")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%Stránka B324%'")" "$B324_ADMIN|$B324_ADMIN_MAILS"
B324_MINE=$(sq "SELECT COUNT(*) FROM ka_stranky WHERE owner_id = $B324_ADMIN AND smazano IS NULL")
check "3.24 (B): the Mine tab of Pages with its count" 200 "/admin.php?module=pages&status=mine" 'Stránka B324'
expect "3.24 (B): … the tab is active, counts my pages, and lists none of anyone else's" "$(grep -c "status=mine\" class=\"aktivni\" aria-current=\"true\">Moje ($B324_MINE)" "$WORK/response")|$(grep -c '<tr' "$WORK/response")" "1|$(( B324_MINE + 1 ))"
# a blocked or removed account stays chosen and shows as a former user – saving the form does not drop it silently
sq "UPDATE ka_stranky SET owner_id = $B324_OLD WHERE ids = $B324_PAGE" > /dev/null
check "3.24 (B): a blocked responsible person shows as a former user" 200 "/admin.php?module=pages&action=edit&id=$B324_PAGE" "<option value=\"$B324_OLD\" selected>Old B324 (bývalý uživatel)</option>"
sq "UPDATE ka_stranky SET owner_id = 999999 WHERE ids = $B324_PAGE" > /dev/null
check "3.24 (B): a removed one as “(former user)”" 200 "/admin.php?module=pages&action=edit&id=$B324_PAGE" '<option value="999999" selected>(bývalý uživatel)</option>'
# the copy: the copier's when the original had somebody, nobody's otherwise
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$(csrf)" -d "ids=$B324_PAGE"
B324_NONE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('b324-bez', 'Bez B324', '', 0); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$(csrf)" -d "ids=$B324_NONE"
expect "3.24 (B): a copy of a page with a responsible person is the copier's, a copy of one without has nobody" \
  "$(sq "SELECT IFNULL(owner_id, 'null') FROM ka_stranky WHERE seo_link = 'b324-stranka-kopie'")|$(sq "SELECT IFNULL(owner_id, 'null') FROM ka_stranky WHERE seo_link = 'b324-bez-kopie'")" "$B324_ADMIN|null"
grep -q '"owner' <(curl -s -b "$JAR" "$B/admin.php?module=pages&action=export&id=$B324_PAGE") && { echo "  CHYBA  3.24 (B): the page export carries the responsible person"; ERRORS=$((ERRORS+1)); } || echo "  ok     3.24 (B): the page export does not carry the responsible person"
# an Author-level person with Pages changes the responsible person of a published page, though they may not save its settings
sq "UPDATE ka_stranky SET zobrazit = 1, owner_id = NULL WHERE ids = $B324_PAGE; UPDATE ka_uzivatele SET admin = 0 WHERE idu = $B324_JANA" > /dev/null
JAR324="$WORK/jar-b324"
TOKEN324=$(curl -s -c "$JAR324" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR324" -c "$JAR324" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN324" -d user=b324-jana --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR324" -c "$JAR324" -o "$WORK/response" "$B/admin.php?module=pages&action=edit&id=$B324_PAGE"
grep -q 'action=owner"' "$WORK/response" && grep -q 'Save the responsible person' "$WORK/response" \
  && echo "  ok     3.24 (B): an author-level user gets the responsible person of a published page as its own small form (English admin)" || { echo "  CHYBA  3.24 (B): the separate owner form"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR324" -c "$JAR324" -o /dev/null -X POST "$B/admin.php?module=pages&action=owner" -d "_csrf=$(csrf)" -d "ids=$B324_PAGE" -d "owner_id=$B324_ADMIN"
expect "3.24 (B): … it saves the person only, logs it, and e-mails the administrator; the page is not stamped as edited" \
  "$(sq "SELECT owner_id FROM ka_stranky WHERE ids = $B324_PAGE")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pages' AND akce = 'responsible person' AND popis LIKE '#$B324_PAGE %'")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = (SELECT email FROM ka_uzivatele WHERE idu = $B324_ADMIN) AND predmet = 'Odpovídáte za stránku „Stránka B324“'")|$(sq "SELECT IFNULL(zmenil, 0) <> $B324_JANA FROM ka_stranky WHERE ids = $B324_PAGE")" "$B324_ADMIN|1|1|1"
sq "UPDATE ka_uzivatele SET admin = 1 WHERE idu = $B324_JANA; UPDATE ka_stranky SET zobrazit = 0 WHERE ids = $B324_PAGE" > /dev/null
# Handled by of an enquiry: one e-mail to the new person, the form name and the link – never what the customer wrote
B324_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Formulář B324', 'zakaznik-b324@example.cz', '[[\"Zpráva\",\"Tajná zpráva B324\"]]', 0); SELECT LAST_INSERT_ID();")
b324_note() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$B324_ENQ"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$(csrf)" -d "idp=$B324_ENQ" -d poznamka=B324 -d "prirazeno=$1"; }
B324_BEFORE=$(b324_mails jana-b324@example.com)
b324_note "$B324_JANA"
expect "3.24 (B): Handled by e-mails the new person once – the form and the link, not the customer's text or address" \
  "$(sq "SELECT prirazeno FROM ka_poptavky WHERE idp = $B324_ENQ")|$(( $(b324_mails jana-b324@example.com) - B324_BEFORE ))|$(b324_last jana-b324@example.com | grep -c 'Subject-Decoded: An enquiry from the form “Formulář B324” is assigned to you')|$(b324_last jana-b324@example.com | grep -c "action=detail&id=$B324_ENQ")|$(b324_last jana-b324@example.com | grep -c 'Tajná\|zakaznik-b324')" \
  "$B324_JANA|1|1|1|0"
b324_note "$B324_JANA"; b324_note "$B324_ADMIN"
expect "3.24 (B): unchanged – nothing; taking it oneself – nothing" "$(( $(b324_mails jana-b324@example.com) - B324_BEFORE ))|$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%Formulář B324%'")" "1|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"
expect "3.24 (B): the dashboard tile Assigned to me counts my open enquiries (spam left out) and leads to Mine" \
  "$(grep -o "<a class=\"dlazdice-polozka\" href=\"/admin.php?module=enquiries&amp;status=moje\"><strong>[0-9]*</strong><span>Přiděleno mně</span>" "$WORK/response" | grep -o '<strong>[0-9]*' | sed 's/<strong>//')" \
  "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE prirazeno = $B324_ADMIN AND stav < 2 AND kategorie <> 'spam'")"
# a collection item: the responsible person in the item form, its e-mail, the Mine tab of the items
B324_IDK=$(sq "SELECT idk FROM ka_kolekce ORDER BY idk LIMIT 1"); B324_KNAME=$(sq "SELECT nazev FROM ka_kolekce WHERE idk = $B324_IDK")
B324_ITEM=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, zobrazit, datum) VALUES ($B324_IDK, 'Položka B324', 'polozka-b324', '{}', 0, '$(site_time)'); SELECT LAST_INSERT_ID();")
check "3.24 (B): the item form offers the responsible person" 200 "/admin.php?module=collections&action=item&id=$B324_IDK&item=$B324_ITEM" "<option value=\"$B324_JANA\">Jana B324</option>"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$(csrf)" -d "idk=$B324_IDK" -d "idp=$B324_ITEM" --data-urlencode "nazev=Položka B324" -d seo_link=polozka-b324 -d "owner_id=$B324_JANA"
expect "3.24 (B): the item saved with its responsible person, one e-mail naming the item and the collection" \
  "$(sq "SELECT owner_id FROM ka_kolekce_polozky WHERE idp = $B324_ITEM")|$(b324_last jana-b324@example.com | grep -c "Subject-Decoded: You are responsible for “Položka B324” in $B324_KNAME")|$(b324_last jana-b324@example.com | grep -c "action=item&id=$B324_IDK&item=$B324_ITEM")" "$B324_JANA|1|1"
sq "UPDATE ka_kolekce_polozky SET owner_id = $B324_ADMIN WHERE idp = $B324_ITEM" > /dev/null
B324_MINE=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idk = $B324_IDK AND owner_id = $B324_ADMIN AND smazano IS NULL")
check "3.24 (B): the Mine tab of the collection items" 200 "/admin.php?module=collections&action=items&id=$B324_IDK&status=mine" "Moje ($B324_MINE)"
expect "3.24 (B): … listing only my items" "$(grep -c '>Položka B324</a>' "$WORK/response")|$(grep -c 'name="oznacene\[\]"' "$WORK/response")" "1|$B324_MINE"
# the review reminder: the hourly job e-mails the responsible person what of theirs is due – one e-mail a day at most
sq "UPDATE ka_stranky SET owner_id = $B324_JANA, review_by = '$(site_date now)' WHERE ids = $B324_PAGE; UPDATE ka_kolekce_polozky SET owner_id = $B324_JANA WHERE idp = $B324_ITEM" > /dev/null
b324_validity() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); echo Kaleta\Core\Validity::run($app); Kaleta\Core\Mail::processQueue($app->settings(), 50);'); }
B324_BEFORE=$(b324_mails jana-b324@example.com)
B324_RUN=$(b324_validity)
expect "3.24 (B): the review-by day of a page sends one reminder to its responsible person, recorded without the address" \
  "$(echo "$B324_RUN" | grep -c 'reminders 1')|$(( $(b324_mails jana-b324@example.com) - B324_BEFORE ))|$(b324_last jana-b324@example.com | grep -c 'Subject-Decoded: Review by today: “Stránka B324”')|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'content.review_mailed' AND data LIKE '%\"user\":$B324_JANA,%' AND data NOT LIKE '%@%'")" "1|1|1|1"
sq "UPDATE ka_kolekce_polozky SET review_by = '$(site_date now)' WHERE idp = $B324_ITEM" > /dev/null
B324_RUN=$(b324_validity)
expect "3.24 (B): … a second item due the same day waits for tomorrow's e-mail" "$(echo "$B324_RUN" | grep -c 'reminders')|$(( $(b324_mails jana-b324@example.com) - B324_BEFORE ))" "0|1"
sq "UPDATE ka_events SET data = REPLACE(data, '\"day\":\"$(site_date now)\"', '\"day\":\"$(site_date yesterday)\"') WHERE type = 'content.review_mailed'" > /dev/null
B324_RUN=$(b324_validity)
expect "3.24 (B): … the next day it comes, with only what was not in a reminder yet" "$(( $(b324_mails jana-b324@example.com) - B324_BEFORE ))|$(b324_last jana-b324@example.com | grep -c 'Subject-Decoded: Review by today: “Položka B324”')|$(b324_last jana-b324@example.com | grep -c 'Stránka B324')" "2|1|0"
# draft attribution (N-U6): a builder autosave by Jana – the Pages list, the builder's top bar, Waiting for you and MCP name her
sq "UPDATE ka_stranky SET review_by = NULL, zmeneno = '$(site_time '-1 minute')' WHERE ids = $B324_PAGE" > /dev/null # the newest draft: first in Waiting for you
curl -s -b "$JAR324" -c "$JAR324" -o "$WORK/response" "$B/admin.php?module=pages&action=builder&id=$B324_PAGE"; TOKEN324=$(csrf)
for i in 1 2; do curl -s -b "$JAR324" -c "$JAR324" -o "$WORK/response" -X POST "$B/admin.php?module=pages&action=build_save&id=$B324_PAGE" -d "_csrf=$TOKEN324" \
  --data-urlencode "stavba={\"v\":1,\"deti\":[{\"id\":\"b324t\",\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Koncept B324 $i</p>\"}}]}"; done
expect "3.24 (B, N-U6): two autosaves by one person in one hour are one change-log line; the save answers with her name" \
  "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'pages' AND akce = 'draft edited' AND kdo = $B324_JANA AND popis = '#$B324_PAGE Stránka B324'")|$(grep -c '"koncept":"Draft by Jana B324, [0-9][0-9]:[0-9][0-9]"' "$WORK/response")" "1|1"
check "3.24 (B, N-U6): the Pages list says who made the draft" 200 "/admin.php?module=pages" "<span class=\"smltxt\" data-draft-by>Koncept: Jana B324, $(sq "SELECT DATE_FORMAT(cas, '%H:%i') FROM ka_protokol WHERE modul = 'pages' AND akce = 'draft edited' AND kdo = $B324_JANA ORDER BY idp DESC LIMIT 1")</span>"
check "3.24 (B, N-U6): … the builder's top bar too" 200 "/admin.php?module=pages&action=builder&id=$B324_PAGE" '"koncept":"Koncept: Jana B324, '
mcp list_pending_review '{}' > "$WORK/response"; mcp_text
grep -q '"kind":"page_drafts","label":"[^"]*","count":[0-9]*,"admin_url":"[^"]*","examples":\[[^]]*\],"drafted_by":\[{"title":"' "$WORK/text" && grep -q '"title":"Stránka B324","by":"Jana B324","at":"' "$WORK/text" \
  && echo "  ok     3.24 (B, N-U6): list_pending_review gains drafted_by, the shape stays" || { echo "  CHYBA  3.24 (B, N-U6): list_pending_review drafted_by"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp save_build "{\"id\":$B324_PAGE,\"build\":{\"v\":1,\"children\":[{\"type\":\"text\",\"content\":{\"html\":\"<p>MCP B324</p>\"}}]}}" > /dev/null
check "3.24 (B, N-U6): a draft saved over MCP names the person and the connection" 200 "/admin.php?module=pages" 'data-draft-by>Koncept: [^<]* přes '
sq "UPDATE ka_stranky SET zmeneno = '$(site_time '+1 minute')' WHERE ids = $B324_PAGE" > /dev/null
check "3.24 (B, N-U6): after the page was edited (or published) the old line names nobody" 200 "/admin.php?module=pages" 'Stránka B324'
! grep '>Stránka B324</bdi></a>' "$WORK/response" | grep -q 'data-draft-by' && echo "  ok     3.24 (B, N-U6): … no stale name" || { echo "  CHYBA  3.24 (B, N-U6): a stale draft name"; ERRORS=$((ERRORS+1)); }
kill "$SMTP_PID" 2>/dev/null || true
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_b324_mail; DROP TABLE ka_b324_mail;
  DELETE FROM ka_stranky WHERE seo_link IN ('b324-stranka', 'b324-stranka-kopie', 'b324-bez', 'b324-bez-kopie'); DELETE FROM ka_kolekce_polozky WHERE idp = $B324_ITEM; DELETE FROM ka_poptavky WHERE idp = $B324_ENQ;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($B324_JANA, $B324_PETR, $B324_OLD); DELETE FROM ka_uzivatele WHERE idu IN ($B324_JANA, $B324_PETR, $B324_OLD)" > /dev/null
