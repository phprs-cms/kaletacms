echo "== 3.26 (B): notifications – the bell, the Notifications screen, instant / daily / off e-mail"
[ -z "${SMTP_PID:-}" ] || kill "$SMTP_PID" 2>/dev/null || true
N326_SMTP="$WORK/smtp-326b"; mkdir -p "$N326_SMTP"
for i in $(seq 1 20); do (exec 3<>"/dev/tcp/127.0.0.1/$SMTP2_PORT") 2>/dev/null || break; sleep 0.2; done # the previous sink let the port go
command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$N326_SMTP" > /dev/null 2>&1 & SMTP_PID=$!
sq "DROP TABLE IF EXISTS ka_n326_mail; CREATE TABLE ka_n326_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
N326_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES
  ('n326-jana', '$N326_HASH', 'Jana N326', 'jana-n326@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'instant'),
  ('n326-petr', '$N326_HASH', 'Petr N326', 'petr-n326@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'daily'),
  ('n326-eva', '$N326_HASH', 'Eva N326', 'eva-n326@example.com', '', 1, '$(site_time)', '$(site_time)', 0, 'off'),
  ('n326-old', '$N326_HASH', 'Old N326', 'old-n326@example.com', '', 1, '$(site_time)', '$(site_time)', 1, 'instant');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, m.ident FROM ka_uzivatele JOIN (SELECT 'pages' AS ident UNION SELECT 'collections' UNION SELECT 'enquiries') m WHERE user LIKE 'n326-%'" > /dev/null
N326_JANA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n326-jana'"); N326_PETR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n326-petr'")
N326_EVA=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n326-eva'"); N326_OLD=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'n326-old'"); N326_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
N326_PAGE=$(sq "INSERT INTO ka_stranky (seo_link, titulek, text, zobrazit) VALUES ('n326-stranka', 'Stránka N326', '<p>N326</p>', 0); SELECT LAST_INSERT_ID();")
N326_ENQ=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Formulář N326', 'zakaznik-n326@example.cz', '[[\"Zpráva\",\"Tajná zpráva N326\"]]', 0); SELECT LAST_INSERT_ID();")
n326_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"' Kaleta\Core\Mail::processQueue($app->settings(), 50);'); }
n326_mails() { grep -l "^X-Rcpt-To: $1" "$N326_SMTP"/*.eml 2>/dev/null | wc -l | tr -d ' '; }
n326_last() { local f; f=$(grep -l "^X-Rcpt-To: $1" "$N326_SMTP"/*.eml 2>/dev/null | tail -1); if [ -n "$f" ]; then eml "$f"; fi; }
n326_rows() { sq "SELECT COUNT(*) FROM ka_notifications WHERE user_id = $1${2:+ AND $2}"; }
# instant: a mention by the administrator – the row (the actor's name copied, the excerpt as plain text) and one e-mail now
n326_php "Kaleta\Core\Inbox::notify(\$app, $N326_JANA, 'mention', 'page:$N326_PAGE', $N326_ADMIN, \"Please check\n the prices\");"
expect "3.26: instant – a mention is recorded with the actor's name and a plain-text excerpt, and e-mailed once in her admin language (English) with the quote and a link through the Notifications screen" \
  "$(n326_rows $N326_JANA "kind = 'mention' AND target = 'page:$N326_PAGE' AND actor_id = $N326_ADMIN AND actor_name <> '' AND excerpt = 'Please check the prices' AND emailed_at IS NOT NULL AND read_at IS NULL")|$(n326_mails jana-n326@example.com)|$(n326_last jana-n326@example.com | grep -c 'Subject-Decoded: .* mentioned you: Page “Stránka N326”')|$(n326_last jana-n326@example.com | grep -c '“Please check the prices”')|$(n326_last jana-n326@example.com | grep -c 'admin.php?action=inbox&open=')" \
  "1|1|1|1|1"
# nothing for the actor themself or a blocked account; an unknown kind is refused
n326_php "Kaleta\Core\Inbox::notify(\$app, $N326_JANA, 'comment', 'page:$N326_PAGE', $N326_JANA, 'mine'); Kaleta\Core\Inbox::notify(\$app, $N326_OLD, 'mention', 'page:$N326_PAGE', $N326_ADMIN); Kaleta\Core\Inbox::notify(\$app, $N326_JANA, 'like', 'page:$N326_PAGE', $N326_ADMIN);"
expect "3.26: never to the actor themself, never to a blocked account, never an unknown kind" "$(n326_rows $N326_JANA)|$(n326_rows $N326_OLD)|$(n326_mails jana-n326@example.com)" "1|0|1"
# daily and off: recorded, no e-mail now
n326_php "Kaleta\Core\Inbox::notify(\$app, $N326_PETR, 'mention', 'enquiry:$N326_ENQ', $N326_JANA, 'Petr, call the customer back'); Kaleta\Core\Inbox::notify(\$app, $N326_EVA, 'comment', 'page:$N326_PAGE', $N326_JANA, 'Eva, see this');"
expect "3.26: daily and off – recorded in the bell, no e-mail now" "$(n326_rows $N326_PETR 'emailed_at IS NULL')|$(n326_rows $N326_EVA 'emailed_at IS NULL')|$(n326_mails petr-n326@example.com)|$(n326_mails eva-n326@example.com)" "1|1|0|0"
# the assignment e-mail of 3.24 goes through it: instant keeps the e-mail (marked e-mailed), daily and off only the bell
n326_assign() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$N326_ENQ"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=note" -d "_csrf=$(csrf)" -d "idp=$N326_ENQ" -d poznamka=N326 -d "prirazeno=$1"; }
n326_assign "$N326_PETR"; n326_assign "$N326_EVA"; n326_assign "$N326_JANA"
sleep 1; n326_php ''
expect "3.26: Handled by – a notification each (no excerpt, never the enquiry's text); the e-mail only to the instant one, as in 3.24, marked e-mailed" \
  "$(n326_rows $N326_PETR "kind = 'assigned' AND target = 'enquiry:$N326_ENQ' AND excerpt = '' AND emailed_at IS NULL")|$(n326_rows $N326_EVA "kind = 'assigned' AND emailed_at IS NULL")|$(n326_rows $N326_JANA "kind = 'assigned' AND emailed_at IS NOT NULL")|$(n326_mails petr-n326@example.com)|$(n326_mails eva-n326@example.com)|$(n326_mails jana-n326@example.com)|$(n326_last jana-n326@example.com | grep -c 'Subject-Decoded: An enquiry from the form “Formulář N326” is assigned to you')|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE excerpt LIKE '%Tajná%'")" \
  "1|1|1|0|0|2|1|0"
# the review reminder: Petr (daily) gets it in the bell only, recorded with his choice
sq "UPDATE ka_stranky SET owner_id = $N326_PETR, review_by = '$(site_date now)' WHERE ids = $N326_PAGE" > /dev/null
N326_RUN=$(n326_php 'echo Kaleta\Core\Validity::run($app);')
expect "3.26: the review date of Petr's page – a review_due notification, no e-mail (daily), the reminder recorded with his choice" \
  "$(echo "$N326_RUN" | grep -c 'reminders 1')|$(n326_rows $N326_PETR "kind = 'review_due' AND target = 'page:$N326_PAGE' AND actor_id IS NULL")|$(n326_mails petr-n326@example.com)|$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'content.review_mailed' AND data LIKE '%\"user\":$N326_PETR,%' AND data LIKE '%\"email\":\"daily\"%'")" "1|1|0|1"
# the daily summary: one e-mail to Petr in the site's language (Czech) with his unread, not yet e-mailed notifications; none to Eva (off) or Jana (instant)
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'inbox_digest_day'; INSERT INTO ka_notifications (user_id, kind, target, actor_name, created_at) VALUES ($N326_PETR, 'comment', 'page:$N326_PAGE', 'Old', '$(site_time '-100 days')')" > /dev/null
N326_RUN=$(n326_php "echo Kaleta\Core\Inbox::run(\$app, new DateTimeImmutable('today 07:30'));")
expect "3.26: before 8:00 the daily run waits" "$N326_RUN|$(n326_mails petr-n326@example.com)" "not due|0"
N326_RUN=$(n326_php "echo Kaleta\Core\Inbox::run(\$app, new DateTimeImmutable('today 09:00'));")
expect "3.26: after 8:00 – one summary to Petr (Czech, his three notifications, each with its link), the 90-day clean-up, nothing to Eva or Jana; all of Petr's marked e-mailed" \
  "$(echo "$N326_RUN" | grep -c 'summaries 1')|$(n326_mails petr-n326@example.com)|$(n326_last petr-n326@example.com | grep -c 'Subject-Decoded: Nová upozornění (3) – ')|$(n326_last petr-n326@example.com | grep -c 'Jana N326 vás zmínil(a) – Poptávka č. ')|$(n326_last petr-n326@example.com | grep -c 'admin.php?action=inbox&open=')|$(n326_last petr-n326@example.com | grep -c 'Tajná')|$(n326_mails eva-n326@example.com)|$(n326_mails jana-n326@example.com)|$(n326_rows $N326_PETR 'emailed_at IS NULL')|$(n326_rows $N326_PETR "actor_name = 'Old'")" \
  "1|1|1|1|3|0|0|2|0|0"
N326_RUN=$(n326_php "echo Kaleta\Core\Inbox::run(\$app, new DateTimeImmutable('today 10:00'));")
expect "3.26: … once a day – a second run the same day sends nothing" "$N326_RUN|$(n326_mails petr-n326@example.com)" "not due|1"
# an enquiry's notes deleted or anonymised take the notifications quoting them along
n326_php "Kaleta\Core\Comments::anonymiseEnquiries(\$app->db(), [$N326_ENQ]);"
expect "3.26: an anonymised enquiry – the quotes of its notifications are blanked" "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'enquiry:$N326_ENQ' AND excerpt <> ''")|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target = 'enquiry:$N326_ENQ'")" "0|4"
# the bell and the screen, as the administrator: two notifications from Jana (the earlier sections left some – a clean start)
sq "DELETE FROM ka_notifications WHERE user_id = $N326_ADMIN" > /dev/null
n326_php "Kaleta\Core\Inbox::notify(\$app, $N326_ADMIN, 'mention', 'page:$N326_PAGE', $N326_JANA, 'Admin, a look please'); Kaleta\Core\Inbox::notify(\$app, $N326_ADMIN, 'comment', 'enquiry:999999', $N326_JANA, 'Gone');"
N326_UNREAD=$(n326_rows $N326_ADMIN 'read_at IS NULL')
check "3.26: the bell with the unread count is in the header bar" 200 "/admin.php" "class=\"zvonek zvonek-nove\" href=\"/admin.php?action=inbox\""
expect "3.26: … in the bar next to the account, not in the left menu; the count and its label" \
  "$(sed -n '/<section class="loginprouzek"/,/<\/section>/p' "$WORK/response" | grep -c 'class="zvonek')|$(sed -n '/<nav class="menu-obal"/,/<\/nav>/p' "$WORK/response" | grep -c 'zvonek\|action=inbox')|$(grep -c "data-bell=\"$N326_UNREAD\"><svg[^>]*>.*</svg><span class=\"zvonek-pocet\" aria-hidden=\"true\">$N326_UNREAD</span>" "$WORK/response")|$(grep -c "aria-label=\"Upozornění – nepřečtená: $N326_UNREAD\"" "$WORK/response")" "1|0|1|1"
check "3.26: the Notifications screen" 200 "/admin.php?action=inbox" 'data-inbox'
N326_NOTE=$(sq "SELECT id FROM ka_notifications WHERE user_id = $N326_ADMIN AND target = 'page:$N326_PAGE' ORDER BY id DESC LIMIT 1")
expect "3.26: … who, what and where with a link through open=, the quote, the unread mark; a place that is gone has no link" \
  "$(grep -c '<strong>Jana N326 vás zmínil(a)</strong>' "$WORK/response")|$(grep -c "href=\"/admin.php?action=inbox&amp;open=$N326_NOTE\">Stránka „Stránka N326“</a>" "$WORK/response")|$(grep -c 'Admin, a look please' "$WORK/response")|$(grep -c 'upozorneni-neprectene' "$WORK/response")|$(grep -c '(už není k dispozici)' "$WORK/response")|$(grep -c 'name="read_all"' "$WORK/response")" \
  "1|1|1|$N326_UNREAD|1|1"
N326_TO=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?action=inbox&open=$N326_NOTE")
expect "3.26: opening a notification marks it read and goes to its place" "$N326_TO|$(sq "SELECT read_at IS NOT NULL FROM ka_notifications WHERE id = $N326_NOTE")" "302 $B/admin.php?module=pages&action=edit&id=$N326_PAGE|1"
N326_OTHER=$(sq "SELECT id FROM ka_notifications WHERE user_id = $N326_EVA LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$B/admin.php?action=inbox&open=$N326_OTHER"
check "3.26: someone else's notification cannot be opened or marked" 200 "/admin.php?action=inbox" 'Toto upozornění už neexistuje'
expect "3.26: … it stays unread" "$(sq "SELECT read_at IS NULL FROM ka_notifications WHERE id = $N326_OTHER")" "1"
mkdir -p "$WORK/web/storage/cache/stranky"; echo cached > "$WORK/web/storage/cache/stranky/n326.html"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=inbox" -d "_csrf=$(csrf)" -d read_all=1
check "3.26: Mark all as read empties the bell" 200 "/admin.php" 'class="zvonek" href="/admin.php?action=inbox"'
expect "3.26: … every notification of mine is read, nobody else's; the page cache stays" "$(n326_rows $N326_ADMIN 'read_at IS NULL')|$(n326_rows $N326_EVA 'read_at IS NULL')|$(grep -c 'zvonek-pocet' "$WORK/response")|$([ -f "$WORK/web/storage/cache/stranky/n326.html" ] && echo kept)" "0|2|0|kept"
# My account: the e-mail choice with its help; an unknown value is the default
check "3.26: My account offers the e-mail choice" 200 "/admin.php?action=account" 'id="notifications"'
expect "3.26: … three choices, the current one checked" "$(grep -c 'name="notify_email" value="\(instant\|daily\|off\)"' "$WORK/response")|$(grep -c 'name="notify_email" value="instant" checked' "$WORK/response")" "3|1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$(csrf)" -d co=notifications -d notify_email=daily
N326_SAVED=$(sq "SELECT notify_email FROM ka_uzivatele WHERE idu = $N326_ADMIN")
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$(csrf)" -d co=notifications -d notify_email=weekly
expect "3.26: the choice is saved; an unknown value falls back to instant; the page cache stays" "$N326_SAVED|$(sq "SELECT notify_email FROM ka_uzivatele WHERE idu = $N326_ADMIN")|$([ -f "$WORK/web/storage/cache/stranky/n326.html" ] && echo kept)" "daily|instant|kept"
# the clean-up: notifications of a removed account and about a deleted enquiry go with the next day's run
sq "DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $N326_EVA; DELETE FROM ka_uzivatele WHERE idu = $N326_EVA; DELETE FROM ka_poptavky WHERE idp = $N326_ENQ; UPDATE ka_nastaveni SET hodnota = '$(site_date yesterday)' WHERE promenna = 'inbox_digest_day'" > /dev/null
n326_php "Kaleta\Core\Inbox::run(\$app, new DateTimeImmutable('today 09:00'));" > /dev/null
expect "3.26: the clean-up – a removed account's notifications and those about a deleted enquiry are gone" "$(n326_rows $N326_EVA)|$(sq "SELECT COUNT(*) FROM ka_notifications WHERE target IN ('enquiry:$N326_ENQ', 'enquiry:999999')")" "0|0"
rm -f "$WORK/web/storage/cache/stranky/n326.html"
kill "$SMTP_PID" 2>/dev/null || true
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_n326_mail; DROP TABLE ka_n326_mail;
  DELETE FROM ka_notifications WHERE user_id IN ($N326_JANA, $N326_PETR, $N326_EVA, $N326_OLD, $N326_ADMIN); DELETE FROM ka_stranky WHERE ids = $N326_PAGE;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user IN ($N326_JANA, $N326_PETR, $N326_OLD); DELETE FROM ka_uzivatele WHERE user LIKE 'n326-%'" > /dev/null
# 3.26 (agent A): the team's threads on pages and collection items, @mentions in every thread (the enquiry notes too), edit
# and delete, notifications through Core\Inbox (agent B's, merged: real rows and e-mails as each person chose), the threads
# follow their target, the builder's Team tab
