echo "== 3.26 (A): comments on pages and items, @mentions, edit and delete, notifications"
# the real notifications (Core\Inbox) with the local SMTP sink: Jana chose instant, the administrator the daily summary
[ -z "${SMTP_PID:-}" ] || kill "$SMTP_PID" 2>/dev/null || true
A326_SMTP="$WORK/smtp-326a"; mkdir -p "$A326_SMTP"
for i in $(seq 1 20); do (exec 3<>"/dev/tcp/127.0.0.1/$SMTP2_PORT") 2>/dev/null || break; sleep 0.2; done
command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$A326_SMTP" > /dev/null 2>&1 & SMTP_PID=$!
sq "DROP TABLE IF EXISTS ka_a326_mail; CREATE TABLE ka_a326_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz');
  UPDATE ka_uzivatele SET notify_email = 'daily' WHERE user = 'admin'" > /dev/null
a326_mails() { grep -l "^X-Rcpt-To: $1" "$A326_SMTP"/*.eml 2>/dev/null | wc -l | tr -d ' '; }
a326_last() { local f; f=$(grep -l "^X-Rcpt-To: $1" "$A326_SMTP"/*.eml 2>/dev/null | head -1); if [ -n "$f" ]; then eml "$f"; fi; }
A326_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES
  ('a326-jana', '$A326_HASH', 'Jana A326', 'jana-a326@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0),
  ('a326-petr', '$A326_HASH', 'Petr A326', 'petr-a326@example.com', '', 1, '$(site_time)', '$(site_time)', 0),
  ('a326-old', '$A326_HASH', 'Old A326', 'old-a326@example.com', '', 2, '$(site_time)', '$(site_time)', 1);
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries') m WHERE user = 'a326-jana';
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'news' FROM ka_uzivatele WHERE user = 'a326-petr'" > /dev/null
A326_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a326-jana'"); A326_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A326_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, zmeneno) VALUES ('a326-stranka', 'Stránka A326', '<p>Text A326</p>', 0, '$(site_time)'); SELECT LAST_INSERT_ID();")
a326_comment() { # a326_comment <jar> <module> <screen query> <fields…>: opens the screen (for the form token), posts the comment action
  local jar="$1" module="$2" screen="$3"; shift 3
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" "$B/admin.php?$screen"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=$module&action=comment" -d "_csrf=$(csrf)" "$@"; }
a326_notes() { sq "SELECT IFNULL(GROUP_CONCAT(CONCAT(user_id, '/', kind) ORDER BY id SEPARATOR ' '), '') FROM ka_notifications WHERE target = '$1'"; }
check "3.26: the page settings have the team's thread with the @ list – people who may open Pages, not oneself, not blocked or without Pages" 200 "/admin.php?module=pages&action=edit&id=$A326_PAGE" 'data-comment-form'
expect "3.26: … the @ list" "$(php -r 'preg_match("#id=\"novy-komentar\"[^>]*data-mentions=\"([^\"]*)\"#", (string) file_get_contents($argv[1]), $m); $l = array_column(json_decode(html_entity_decode($m[1] ?? "", ENT_QUOTES), true) ?: [], "user"); echo implode(",", array_map(fn ($u) => $u . "=" . (in_array($u, $l, true) ? 1 : 0), ["a326-jana", "a326-petr", "a326-old", "admin"]));' -- "$WORK/response")" "a326-jana=1,a326-petr=0,a326-old=0,admin=0"
REDIRECT=$(a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" --data-urlencode $'text=@a326-jana please check the <b>prices</b>.\nThanks')
expect "3.26: a comment on a page – kind comment, the mention stored as the id, back to the thread; Jana is notified of the mention" \
  "$(sq "SELECT CONCAT_WS('|', kind, author_id, mentions, via, edited_at IS NULL, deleted_at IS NULL) FROM ka_comments WHERE target = 'page:$A326_PAGE'")|${REDIRECT##*#}|$(a326_notes "page:$A326_PAGE")" \
  "comment|$A326_ADMIN|[$A326_JANA]|admin|1|1|comments|$A326_JANA/mention"
expect "3.26: … the excerpt is plain text with the name" "$(sq "SELECT excerpt FROM ka_notifications WHERE target = 'page:$A326_PAGE'")" "@Jana A326 please check the <b>prices</b>. Thanks"
expect "3.26 (merge): … Jana chose instant – one e-mail at once, in English, with the quote and the link through the Notifications screen" \
  "$(a326_mails jana-a326@example.com)|$(a326_last jana-a326@example.com | grep -c 'Subject-Decoded: .* mentioned you: Page “Stránka A326”')|$(a326_last jana-a326@example.com | grep -c '“@Jana A326 please check the <b>prices</b>. Thanks”')|$(a326_last jana-a326@example.com | grep -c 'action=inbox&open=')|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'page:$A326_PAGE' AND emailed_at IS NOT NULL")" "1|1|1|1|1"
check "3.26: the thread shows the mention as the name, the text escaped" 200 "/admin.php?module=pages&action=edit&id=$A326_PAGE" "<span class=\"zminka\" data-mention=\"$A326_JANA\">@Jana A326</span> please check the &lt;b&gt;prices&lt;/b&gt;.<br"
# someone who cannot open Pages and a blocked account are refused – nothing saved, the text comes back
a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" --data-urlencode "text=@a326-petr look" > /dev/null
check "3.26: a mention of someone without Pages is refused and the text is offered again" 200 "/admin.php?module=pages&action=edit&id=$A326_PAGE" '@a326-petr tady nelze zmínit'
expect "3.26: … nothing saved, the composer has the text again" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'page:$A326_PAGE'")|$(grep -c '>@a326-petr look</textarea>' "$WORK/response")" "1|1"
a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" --data-urlencode "text=@a326-old hi" > /dev/null
expect "3.26: a blocked account cannot be mentioned; an unknown @word or an e-mail address is plain text" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'page:$A326_PAGE'")" "1"
# the responsible person gets "comment" for a new comment – once (a mention wins), never for their own
sq "UPDATE ka_stranky SET owner_id = $A326_JANA WHERE ids = $A326_PAGE" > /dev/null
a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" --data-urlencode "text=New photos are up, write to info@example.com or @nobody." > /dev/null
a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" --data-urlencode "text=@A326-Jana once more" > /dev/null
expect "3.26: the responsible person is told of a new comment; mentioned too, she gets the mention only" \
  "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'page:$A326_PAGE'")|$(a326_notes "page:$A326_PAGE")|$(sq "SELECT IFNULL(mentions, 'none') FROM ka_comments WHERE target = 'page:$A326_PAGE' AND text LIKE 'New photos%'")" \
  "3|$A326_JANA/mention $A326_JANA/comment $A326_JANA/mention|none"
# Jana signed in: she mentions the administrator, edits her own comment, may not edit or delete someone else's
JAR326A="$WORK/jar-a326"; rm -f "$JAR326A"
curl -s -c "$JAR326A" -b "$JAR326A" -o "$WORK/response" "$B/admin.php"; curl -s -b "$JAR326A" -c "$JAR326A" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=a326-jana --data-urlencode "password=$PASSWORD"
a326_comment "$JAR326A" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" --data-urlencode "text=Done, @admin." > /dev/null
A326_HERS=$(sq "SELECT MAX(id) FROM ka_comments WHERE target = 'page:$A326_PAGE' AND author_id = $A326_JANA"); A326_MINE=$(sq "SELECT MIN(id) FROM ka_comments WHERE target = 'page:$A326_PAGE'")
expect "3.26: Jana's comment mentions the administrator (notified); she is responsible but wrote it – nothing for her" \
  "$(sq "SELECT mentions FROM ka_comments WHERE id = $A326_HERS")|$(a326_notes "page:$A326_PAGE")" "[$A326_ADMIN]|$A326_JANA/mention $A326_JANA/comment $A326_JANA/mention $A326_ADMIN/mention"
expect "3.26 (merge): … the administrator chose the daily summary – no e-mail now, the row waits for the morning" \
  "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'page:$A326_PAGE' AND user_id = $A326_ADMIN AND emailed_at IS NULL AND excerpt LIKE 'Done, @%'")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu <> '' AND komu = (SELECT email FROM ka_uzivatele WHERE idu = $A326_ADMIN) AND predmet LIKE '%Stránka A326%'")" "1|0"
a326_comment "$JAR326A" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" -d op=edit -d "comment=$A326_MINE" --data-urlencode "text=Hijacked" > /dev/null
a326_comment "$JAR326A" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" -d op=delete -d "comment=$A326_MINE" > /dev/null
a326_comment "$JAR326A" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" -d op=edit -d "comment=$A326_HERS" --data-urlencode "text=Done, @admin – see the new prices." > /dev/null
expect "3.26: Jana cannot edit or delete the administrator's comment; her own edit is stamped, the mention kept and not notified again" \
  "$(sq "SELECT CONCAT_WS('|', text LIKE '@a326-jana please%', deleted_at IS NULL) FROM ka_comments WHERE id = $A326_MINE")|$(sq "SELECT CONCAT_WS('|', text, edited_at IS NOT NULL, mentions) FROM ka_comments WHERE id = $A326_HERS")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'page:$A326_PAGE'")" \
  "1|1|Done, @admin – see the new prices.|1|[$A326_ADMIN]|4"
expect "3.26 (merge): … the administrator's notification quotes the edited text" "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'page:$A326_PAGE' AND user_id = $A326_ADMIN AND excerpt LIKE '% – see the new prices.'")" "1"
check "3.26: the edited comment says so; Edit only under one's own, Delete too" 200 "/admin.php?module=pages&action=edit&id=$A326_PAGE" 'data-comment-edit'
expect "3.26: … the administrator gets Edit under his three, Delete under all four" "$(grep -c 'data-comment-edit' "$WORK/response")|$(grep -c 'data-comment-delete' "$WORK/response")|$(grep -c '· <span class="napoveda">upraveno</span>' "$WORK/response")" "3|4|1"
# delete: the author her own, the administrator anyone's; the thread keeps a placeholder, the text and mentions go
a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" -d op=delete -d "comment=$A326_HERS" > /dev/null
a326_comment "$JAR" pages "module=pages&action=edit&id=$A326_PAGE" -d "ids=$A326_PAGE" -d op=delete -d "comment=$A326_MINE" > /dev/null
expect "3.26: the administrator deletes Jana's comment and his own – soft: who and when stay, the text and the mentions go" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT_WS('/', author_name <> '', text = '', mentions IS NULL, deleted_at IS NOT NULL) ORDER BY id) FROM ka_comments WHERE id IN ($A326_MINE, $A326_HERS)")" "1/1/1/1,1/1/1/1"
expect "3.26 (merge): … the notifications quoting the deleted comments lose the quote; the others keep theirs" \
  "$(sq "SELECT GROUP_CONCAT(CONCAT(kind, ':', excerpt = '') ORDER BY id) FROM ka_notifications WHERE target = 'page:$A326_PAGE'")" "mention:1,comment:0,mention:0,mention:1"
check "3.26: a deleted comment stays as a line" 200 "/admin.php?module=pages&action=edit&id=$A326_PAGE" 'data-comment-deleted'
expect "3.26: … two placeholders, two comments left" "$(grep -c 'data-comment-deleted' "$WORK/response")|$(grep -c 'class="vlakno-komentar" data-comment=' "$WORK/response")" "2|2"
expect "3.26: the change log has a row for each add, edit and delete (no comment text in it)" \
  "$(sq "SELECT CONCAT_WS('|', SUM(akce = 'comment added'), SUM(akce = 'comment edited'), SUM(akce = 'comment deleted'), SUM(popis LIKE '%prices%')) FROM ka_protokol WHERE modul = 'pages' AND popis LIKE '#$A326_PAGE %'")" "4|1|2|0"
# the builder: the Team tab gets the thread, the people to mention and the address; the JSON answer is the fresh thread
check "3.26: the builder carries the Team tab's thread" 200 "/admin.php?module=pages&action=builder&id=$A326_PAGE" '"tym":{"komentare":\['
expect "3.26: … with the people to mention (Jana, not oneself)" "$(php -r 'preg_match("#<script type=\"application/json\" id=\"stavitel-data\">(.*?)</script>#s", (string) file_get_contents($argv[1]), $m); $d = json_decode($m[1] ?? "", true); $l = array_column($d["tym"]["lide"] ?? [], "user"); echo in_array("a326-jana", $l, true) ? 1 : 0, in_array("admin", $l, true) ? 1 : 0, in_array("a326-petr", $l, true) ? 1 : 0, "|", count($d["tym"]["komentare"] ?? []), "|", str_contains($d["tym"]["adresa"] ?? "", "module=pages&action=comment") ? 1 : 0;' -- "$WORK/response")" "100|4|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/json" -X POST "$B/admin.php?module=pages&action=comment" -d "_csrf=$(csrf)" -d format=json -d "ids=$A326_PAGE" --data-urlencode "text=From the builder @a326-jana"
expect "3.26: a comment from the builder answers with the thread (mention as the name)" "$(php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $last = end($j["komentare"]); echo ($j["ok"] ? 1 : 0), "|", count($j["komentare"]), "|", str_contains($last["html"], "data-mention") ? 1 : 0, "|", $last["muj"] ? 1 : 0;' -- "$WORK/json")" "1|5|1|1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/json" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=comment" -d "_csrf=$(csrf)" -d format=json -d "ids=$A326_PAGE" --data-urlencode "text=@a326-petr" > "$WORK/code"
expect "3.26: … a refused mention answers 400 with the reason" "$(cat "$WORK/code")|$(php -r 'echo json_decode((string) file_get_contents($argv[1]), true)["chyba"] ?? "";' -- "$WORK/json")" "400|@a326-petr tady nelze zmínit: nemá přístup do sekce Stránky."
# a collection item: its form has the thread; trash keeps it, the permanent delete takes it
A326_IDK=$(sq "INSERT INTO ka_kolekce (nazev, seo_link, pole) VALUES ('Kolekce A326', 'kolekce-a326', '[]'); SELECT LAST_INSERT_ID();")
A326_ITEM=$(sq "INSERT INTO ka_kolekce_polozky (idk, nazev, seo_link, data, zobrazit, datum, owner_id) VALUES ($A326_IDK, 'Položka A326', 'polozka-a326', '{}', 0, '$(site_time)', $A326_JANA); SELECT LAST_INSERT_ID();")
check "3.26: the item form has the team's thread" 200 "/admin.php?module=collections&action=item&id=$A326_IDK&item=$A326_ITEM" 'data-comments="item:'
REDIRECT=$(a326_comment "$JAR" collections "module=collections&action=item&id=$A326_IDK&item=$A326_ITEM" -d "idk=$A326_IDK" -d "idp=$A326_ITEM" --data-urlencode "text=Price checked")
expect "3.26: a comment on an item, its responsible person notified, back to the item" "$(sq "SELECT CONCAT(MAX(kind), '|', COUNT(*)) FROM ka_comments WHERE target = 'item:$A326_ITEM'")|$(a326_notes "item:$A326_ITEM")|$(echo "$REDIRECT" | grep -c "action=item&id=$A326_IDK&item=$A326_ITEM#comments")" "comment|1|$A326_JANA/comment|1"
a326_comment "$JAR" collections "module=collections&action=item&id=$A326_IDK&item=$A326_ITEM" -d "idk=999999" -d "idp=$A326_ITEM" --data-urlencode "text=Elsewhere" > /dev/null
expect "3.26: an item of another collection is no target" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'item:$A326_ITEM'")" "1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$A326_IDK"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete_item" -d "_csrf=$(csrf)" -d "idk=$A326_IDK" -d "idp=$A326_ITEM"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$(csrf)" -d "ids=$A326_PAGE"
expect "3.26: the trash keeps the threads of a page and an item" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('item:$A326_ITEM', 'page:$A326_PAGE')")" "6"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete_item_permanently" -d "_csrf=$(csrf)" -d "idk=$A326_IDK" -d "idp=$A326_ITEM"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete_permanently" -d "_csrf=$(csrf)" -d "ids=$A326_PAGE"
expect "3.26: deleting them for good deletes their threads" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('item:$A326_ITEM', 'page:$A326_PAGE')")" "0"
expect "3.26 (merge): … and the notifications about them" "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target IN ('item:$A326_ITEM', 'page:$A326_PAGE')")" "0"
# the emptied trash and any other path (T1: the daily trash job sweeps what is left of pages and items that are gone – no longer the Pages list)
A326_OLD=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit, zmeneno, smazano) VALUES ('a326-old', 'Old A326', '', 0, '$(site_time)', '$(site_time '-40 days')'); SELECT LAST_INSERT_ID();")
sq "INSERT INTO ka_comments (target, kind, text, created_at) VALUES ('page:$A326_OLD', 'comment', 'old', '$(site_time)'), ('page:987654321', 'comment', 'orphan', '$(site_time)'), ('item:987654321', 'comment', 'orphan', '$(site_time)')" > /dev/null
check "3.26: the Pages list" 200 "/admin.php?module=pages"
expect "T1 (Q5): the Pages list deletes nothing on a GET" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('page:$A326_OLD', 'page:987654321', 'item:987654321')")" "3"
run_job trash > /dev/null
expect "3.26: the emptied trash and the sweep leave no thread of a page or an item that is gone" "$(sq "SELECT COUNT(*) FROM ka_comments WHERE target IN ('page:$A326_OLD', 'page:987654321', 'item:987654321')")|$(sq "SELECT COUNT(*) FROM ka_stranky WHERE ids = $A326_OLD")" "0|0"
# the enquiry notes: mentions (only people with Enquiries), edit and delete one's own, anonymising still blanks them
A326_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt A326', 'a326@example.cz', '[]', 1); SELECT LAST_INSERT_ID();")
a326_note() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A326_ENQ"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$(csrf)" -d "idp=$A326_ENQ" "$@"; }
check "3.26: the note composer offers the people with Enquiries" 200 "/admin.php?module=enquiries&action=detail&id=$A326_ENQ" 'name="add_note"[^>]*data-mentions="[^"]*a326-jana'
a326_note --data-urlencode "add_note=@a326-jana call her back"
a326_note --data-urlencode "add_note=@a326-petr too"
A326_NOTE=$(sq "SELECT MAX(id) FROM ka_comments WHERE target = 'enquiry:$A326_ENQ'")
expect "3.26: a note with a mention – stored and notified; someone without Enquiries refused; a change-log row" \
  "$(sq "SELECT CONCAT_WS('|', COUNT(*), MAX(kind), MAX(mentions)) FROM ka_comments WHERE target = 'enquiry:$A326_ENQ'")|$(a326_notes "enquiry:$A326_ENQ")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'note added' AND popis LIKE '#$A326_ENQ %'")" \
  "1|note|[$A326_JANA]|$A326_JANA/mention|1"
check "3.26: the note shows the name, with Edit and Delete" 200 "/admin.php?module=enquiries&action=detail&id=$A326_ENQ" "data-mention=\"$A326_JANA\">@Jana A326</span> call her back"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=comment" -d "_csrf=$(csrf)" -d "idp=$A326_ENQ" -d op=edit -d "comment=$A326_NOTE" --data-urlencode "add_note=x" --data-urlencode "text=Called her – she wants oak."
expect "3.26: the author edits the note" "$(sq "SELECT CONCAT_WS('|', text, edited_at IS NOT NULL, IFNULL(mentions, 'none')) FROM ka_comments WHERE id = $A326_NOTE")" "Called her – she wants oak.|1|none"
check "3.26: …" 200 "/admin.php?module=enquiries&action=detail&id=$A326_ENQ" 'data-comment-edit'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=comment" -d "_csrf=$(csrf)" -d "idp=$A326_ENQ" -d op=delete -d "comment=$A326_NOTE"
check "3.26: a deleted note stays as a line in the conversation" 200 "/admin.php?module=enquiries&action=detail&id=$A326_ENQ" 'data-thread-item="note" data-comment="[0-9]*" data-comment-deleted'
a326_note --data-urlencode "add_note=@a326-jana keep this"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A326_ENQ"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=anonymise" -d "_csrf=$(csrf)" -d "idp=$A326_ENQ"
expect "3.26: anonymising still blanks the notes and their mentions" "$(sq "SELECT CONCAT_WS('/', COUNT(*), SUM(text = ''), SUM(mentions IS NULL)) FROM ka_comments WHERE target = 'enquiry:$A326_ENQ'")" "2/2/2"
check "3.26: … and the anonymised notes offer no Edit" 200 "/admin.php?module=enquiries&action=detail&id=$A326_ENQ" 'data-thread-item="note"'
expect "3.26: … (no Edit, no Delete)" "$(grep -c 'data-comment-actions' "$WORK/response")" "0"
sq "DELETE FROM ka_comments WHERE target = 'enquiry:$A326_ENQ'; DELETE FROM ka_poptavky WHERE idp = $A326_ENQ; DELETE FROM ka_notifications WHERE target IN ('page:$A326_PAGE', 'item:$A326_ITEM', 'enquiry:$A326_ENQ');
  DELETE FROM ka_kolekce WHERE idk = $A326_IDK; UPDATE ka_uzivatele SET notify_email = 'instant' WHERE user = 'admin';
  DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_a326_mail; DROP TABLE ka_a326_mail;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN (SELECT idu FROM ka_uzivatele WHERE user LIKE 'a326-%'); DELETE FROM ka_uzivatele WHERE user LIKE 'a326-%'" > /dev/null
kill "$SMTP_PID" 2>/dev/null || true

