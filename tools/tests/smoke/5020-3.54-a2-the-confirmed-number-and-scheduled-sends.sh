echo "== 3.54 A2: the number confirmed is the number queued – a change starts nothing; a scheduled send with a refused or empty audience goes back to the drafts; a scheduled audience is locked; a Czech newsletter reaches Czech subscribers only"
s354_settings_save 5020
S5020_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter +jazyky +customers +pipeline -quotes
s354_sending_site
s354_fixture
s5020_try() { s354_php '$try = function (callable $f): string { try { $f(); return "ok"; } catch (\InvalidArgumentException | \DomainException $e) {
    return ($e instanceof Kaleta\Core\RecipientCountChanged ? "changed " . $e->expected . "->" . $e->actual . ": " : "") . $e->getMessage(); } }; '"$1" "${@:2}"; }
s5020_state() { sq "SELECT CONCAT(status, '/', recipients, '/', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = ka_newsletters.id)) FROM ka_newsletters WHERE id = ${1:-0}"; }

# a subscriber confirms between the count shown and the click: nothing starts, the draft has no queue rows
S5020_ID=$(s354_php 'echo Mailing::save($app, ["subject" => "S354 race", "news_mode" => "none", "audience" => "language", "language" => ""]);')
S5020_SHOWN=$(s354_php 'echo Mailing::recipientCount($app, (array) Mailing::byId($db, (int) $argv[1]));' "$S5020_ID")
sq "UPDATE ka_odberatele SET stav = 1, potvrzeno = '$(site_time)' WHERE email = 's354-cs4@example.com'" > /dev/null
expect "3.54 A2: a subscriber confirmed after the count was shown – the send stops with both numbers (RecipientCountChanged) and leaves a draft without queue rows" \
  "$S5020_SHOWN|$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], null, (int) $argv[2]));' "$S5020_ID" "$S5020_SHOWN")|$(s5020_state "$S5020_ID")" \
  "4|changed 4->5: The number of recipients changed: was 4, now 5 – confirm again.|draft/0/0"
# the same between the check and the queue (a trigger plays the sign-up that lands in that moment): the transaction rolls everything back
sq "CREATE TRIGGER s5020_signup BEFORE UPDATE ON ka_newsletters FOR EACH ROW INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno, language)
    SELECT 's354-late@example.com', 1, SUBSTRING(SHA2(RAND(), 256), 1, 32), NOW(), NOW(), '' FROM DUAL WHERE NEW.status = 'sending' AND OLD.status = 'draft' AND NEW.id = $S5020_ID" > /dev/null
S5020_RACE=$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], null, 5));' "$S5020_ID")
sq "DROP TRIGGER s5020_signup" > /dev/null
expect "3.54 A2: a sign-up between the check and the queue – the queue got 6 rows for 5 confirmed, the flip, the rows and the sign-up itself are rolled back: still a draft, no queue rows, no change-log entry" \
  "$S5020_RACE|$(s5020_state "$S5020_ID")|$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email = 's354-late@example.com'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'newsletters' AND akce = 'send' AND popis = 'S354 race'")" \
  "changed 5->6: The number of recipients changed: was 5, now 6 – confirm again.|draft/0/0|0|0"
expect "3.54 A2: with the right number it starts – recipients = queue rows = the number confirmed; without a number (cron, an older caller) only the comparison is skipped" \
  "$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], null, 5));' "$S5020_ID")|$(s5020_state "$S5020_ID")" "ok|sending/5/5"
# an undone start (an agent session undone before the first batch, a restore of a backup taken mid-send) leaves a draft
# with its queue rows: unsent ones are dropped and counted again, so the confirmed number still matches (3.54 review)
sq "UPDATE ka_newsletters SET status = 'draft', started_at = NULL WHERE id = $S5020_ID" > /dev/null
expect "3.54 A2: a draft with its unsent queue rows left from an undone start – it starts again with recipients = expected, not \"was 5, now 0\"" \
  "$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], null, 5));' "$S5020_ID")|$(s5020_state "$S5020_ID")" "ok|sending/5/5"
sq "UPDATE ka_newsletters SET status = 'draft', started_at = NULL WHERE id = $S5020_ID; UPDATE ka_newsletter_queue SET sent_at = '$(site_time)', next_attempt = NULL WHERE newsletter_id = $S5020_ID ORDER BY id LIMIT 1" > /dev/null
expect "3.54 A2: … but one whose earlier start already reached someone is refused in its own words, scheduling too – nothing is queued twice" \
  "$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], null, 5)), "|", $try(fn () => Mailing::send($app, (int) $argv[1], "2099-01-01 08:00"));' "$S5020_ID")|$(s5020_state "$S5020_ID")" \
  "This newsletter already went out to some subscribers before it became a draft again – write a new newsletter instead of sending it once more.|This newsletter already went out to some subscribers before it became a draft again – write a new newsletter instead of sending it once more.|draft/5/5"
s354_php '$db->run("DELETE FROM {newsletter_queue}"); $db->run("UPDATE {newsletters} SET status = ? WHERE subject = ?", ["cancelled", "S354 race"]);'

# scheduling: the audience must be allowed and not empty now; a scheduled audience cannot change
S5020_EMPTY=$(s354_php 'echo Mailing::save($app, ["subject" => "S354 nobody", "news_mode" => "none", "audience" => "language", "language" => "de"]);')
sq "UPDATE ka_odberatele SET stav = 0 WHERE email = 's354-de1@example.com'" > /dev/null
expect "3.54 A2: scheduling an audience that is empty now is refused in its own words" \
  "$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], "2099-01-01 08:00"));' "$S5020_EMPTY")|$(s5020_state "$S5020_EMPTY")" \
  "There are no confirmed subscribers of this newsletter's language yet.|draft/0/0"
sq "UPDATE ka_odberatele SET stav = 1 WHERE email = 's354-de1@example.com'" > /dev/null
S5020_LOCK=$(s5020_try 'echo $try(fn () => Mailing::send($app, (int) $argv[1], "2099-01-01 08:00")), "|", $try(fn () => Mailing::save($app, ["subject" => "S354 nobody", "audience" => "all"], (int) $argv[1])), "|",
    $try(fn () => Mailing::save($app, ["subject" => "S354 nobody", "customers_only" => true], (int) $argv[1])), "|", $try(fn () => Mailing::save($app, ["subject" => "S354 nobody", "language" => "en"], (int) $argv[1])), "|",
    $try(fn () => Mailing::save($app, ["subject" => "S354 nobody – corrected", "news_mode" => "none"], (int) $argv[1]));' "$S5020_EMPTY")
expect "3.54 A2: a scheduled newsletter – the audience, the filter and the language of the audience \"language\" are locked (unschedule it first), the subject is not" \
  "$S5020_LOCK|$(sq "SELECT CONCAT(status, '/', audience, '/', audience_filter, '/', language, '/', subject) FROM ka_newsletters WHERE id = $S5020_EMPTY")" \
  "ok|The audience of a scheduled newsletter cannot change – unschedule it first.|The audience of a scheduled newsletter cannot change – unschedule it first.|The audience of a scheduled newsletter cannot change – unschedule it first.|ok|scheduled/language//de/S354 nobody – corrected"
# the same through the administration's form (the language select of a scheduled newsletter for its language)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters&action=edit&id=$S5020_EMPTY"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=save" -d "_csrf=$(csrf)" -d "id=$S5020_EMPTY" --data-urlencode "subject=S354 nobody – corrected" -d news_mode=none -d language=en
expect "3.54 A2: the administration's save of the scheduled one in another language is refused – it stays German and scheduled" \
  "$(sq "SELECT CONCAT(status, '/', language) FROM ka_newsletters WHERE id = $S5020_EMPTY")" "scheduled/de"

# at the start (cron): nobody left in the audience, or an audience the site no longer allows → back to the drafts with the reason, never "sent"
S5020_CUST=$(s354_php '$id = Mailing::save($app, ["subject" => "S354 customers later", "news_mode" => "none", "customers_only" => true]); Mailing::send($app, $id, "2099-01-01 08:00"); echo $id;')
sq "UPDATE ka_odberatele SET stav = 0 WHERE email = 's354-de1@example.com'; UPDATE ka_newsletters SET scheduled_at = '$(site_time '-1 minute')' WHERE id IN ($S5020_EMPTY, $S5020_CUST)" > /dev/null
features -pipeline -quotes
S5020_ADMIN_MAIL=$(sq "SELECT email FROM ka_uzivatele WHERE user = 'admin'")
sq "UPDATE ka_uzivatele SET email = 'admin@example.cz' WHERE user = 'admin'" > /dev/null # the author is told (a partial run has no address yet)
s354_php 'Mailing::processQueue($app);' > /dev/null
sq "UPDATE ka_uzivatele SET email = '$S5020_ADMIN_MAIL' WHERE user = 'admin'" > /dev/null
expect "3.54 A2: at the cron start both go back to the drafts, never sent – the change log says why (nobody in the audience, the audience refused) and the author gets one e-mail each" \
  "$(s5020_state "$S5020_EMPTY")|$(s5020_state "$S5020_CUST")|$(sq "SELECT GROUP_CONCAT(duvod ORDER BY duvod SEPARATOR ';') FROM ka_protokol WHERE modul = 'newsletters' AND akce = 'unschedule' AND popis IN ('S354 nobody – corrected', 'S354 customers later')")|$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%S354 nobody – corrected' OR predmet LIKE '%S354 customers later'")" \
  "draft/0/0|draft/0/0|not sent: its audience cannot be sent to;not sent: nobody is in its audience|2"
features +pipeline
sq "UPDATE ka_odberatele SET stav = 1 WHERE email = 's354-de1@example.com'" > /dev/null

# a Czech newsletter on a site with three languages: the cron sends it to the Czech subscribers only (the bounced one is skipped)
S5020_CS=$(s354_php '$id = Mailing::save($app, ["subject" => "S354 česky", "news_mode" => "none", "audience" => "language", "language" => ""]); Mailing::send($app, $id, null, Mailing::recipientCount($app, (array) Mailing::byId($db, $id))); echo $id;')
s354_php 'Mailing::processQueue($app);' > /dev/null
for _ in $(seq 1 25); do [ "$(grep -l '^X-Rcpt-To: s354-' "$MAIL_SINK_DIR"/*.eml 2>/dev/null | wc -l | tr -d ' ')" -ge 4 ] && break; sleep 0.2; done # the sink writes a file a moment after it accepted it
S5020_TO=$(for f in "$MAIL_SINK_DIR"/*.eml; do [ -f "$f" ] && grep -h '^X-Rcpt-To: s354-' "$f"; done | tr -d '\r' | sed 's/^X-Rcpt-To: //' | sort | tr '\n' ',')
expect "3.54 A1: the Czech newsletter went to the Czech subscribers only – five queued (the one who confirmed above too), four delivered, the bounced one skipped; no English or German address got it" \
  "$S5020_TO|$(sq "SELECT CONCAT(status, '/', recipients, '/', sent_count, '/', skipped_count) FROM ka_newsletters WHERE id = ${S5020_CS:-0}")" \
  "s354-cs1@example.com,s354-cs2@example.com,s354-cs3@example.com,s354-cs4@example.com,|sent/5/4/1"

s354_php '$db->run("DELETE FROM {newsletter_queue}"); $db->run("DELETE FROM {newsletters} WHERE subject LIKE ?", ["S354 %"]); $db->run("DELETE FROM {deals} WHERE title LIKE ?", ["S354 %"]);'
features --exact "$S5020_EXT"
s354_settings_restore 5020
