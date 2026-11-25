echo "== 3.54 C: unsubscribed from this newsletter – the button page and the one-click unsubscribe count once; an old link, a forged id, a draft and a newsletter sent before the confirmation count nothing"
C5060_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter
c5060_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $db = $app->db(); '"$1" -- "${@:2}"); }
# c5060_subscriber <e-mail> <confirmed at>: a confirmed subscriber; prints the token
c5060_subscriber() { c5060_php '$t = bin2hex(random_bytes(16)); $db->insert("odberatele", ["email" => $argv[1], "token" => $t, "datum" => $argv[2], "stav" => 1, "potvrzeno" => $argv[2]]); echo $t;' "$1" "$2"; }
# c5060_newsletter <status> <started at|-> : a newsletter row; prints its id
c5060_newsletter() { c5060_php 'echo $db->insert("newsletters", ["subject" => "C5060", "intro" => "", "status" => $argv[1], "created" => date("Y-m-d H:i:s"), "started_at" => $argv[2] === "-" ? null : $argv[2]]);' "$1" "$2"; }
c5060_count() { sq "SELECT unsubscribed_count FROM ka_newsletters WHERE id = $1"; }
c5060_gone() { sq "SELECT COUNT(*) = 0 FROM ka_odberatele WHERE email = '$1'"; }

C5060_SENT=$(c5060_newsletter sent "$(site_time '-1 hour')")
C5060_DRAFT=$(c5060_newsletter draft -)
C5060_OLD=$(c5060_newsletter sent "$(site_time '-10 days')")
C5060_T1=$(c5060_subscriber c5060-button@example.com "$(site_time '-3 days')")
C5060_T2=$(c5060_subscriber c5060-oneclick@example.com "$(site_time '-3 days')")
C5060_T3=$(c5060_subscriber c5060-old@example.com "$(site_time '-3 days')")
C5060_T4=$(c5060_subscriber c5060-forged@example.com "$(site_time '-3 days')")
C5060_T5=$(c5060_subscriber c5060-draft@example.com "$(site_time '-3 days')")
C5060_T6=$(c5060_subscriber c5060-bare@example.com "$(site_time '-3 days')")
C5060_T7=$(c5060_subscriber c5060-junk@example.com "$(site_time '-3 days')")

# the button page: the GET keeps the newsletter in the form's action (and changes nothing), the POST counts
curl -s -o "$WORK/c5060.html" "$B/odber?unsubscribe=$C5060_T1&newsletter=$C5060_SENT"
C5060_ACTION=$(grep -o 'form method="post" action="[^"]*"' "$WORK/c5060.html" | sed 's/.*action="//;s/"$//;s/&amp;/\&/g')
expect "3.54 C: the unsubscribe page keeps the newsletter id in its button's address and changes nothing on opening" \
  "$(printf '%s' "$C5060_ACTION" | grep -c "?unsubscribe=$C5060_T1&newsletter=$C5060_SENT\$")|$(c5060_count "$C5060_SENT")|$(c5060_gone c5060-button@example.com)" "1|0|0"
curl -s -o /dev/null -X POST "$B$C5060_ACTION"
expect "3.54 C: the button unsubscribes and counts once for the newsletter" "$(c5060_gone c5060-button@example.com)|$(c5060_count "$C5060_SENT")" "1|1"
curl -s -o /dev/null -X POST "$B$C5060_ACTION"
expect "3.54 C: the used link counts nothing more (a token works once)" "$(c5060_count "$C5060_SENT")" "1"

# the one-click unsubscribe of the mail client (RFC 8058) posts the List-Unsubscribe address itself
curl -s -o /dev/null -X POST "$B/subscription?unsubscribe=$C5060_T2&newsletter=$C5060_SENT" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "3.54 C: a one-click unsubscribe counts once too" "$(c5060_gone c5060-oneclick@example.com)|$(c5060_count "$C5060_SENT")" "1|2"

# a newsletter that started before the person confirmed, an unknown id, a draft, a link without the id, a junk id: unsubscribed, nothing counted
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$C5060_T3&newsletter=$C5060_OLD"
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$C5060_T4&newsletter=$((C5060_SENT + 9000))"
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$C5060_T5&newsletter=$C5060_DRAFT"
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$C5060_T6"
curl -s -o /dev/null -X POST "$B/odber?unsubscribe=$C5060_T7&newsletter=$C5060_SENT%27x"
expect "3.54 C: every one of them is unsubscribed; nothing is counted for a newsletter sent before the confirmation, a draft, an unknown or a junk id, or an old link" \
  "$(sq "SELECT COUNT(*) FROM ka_odberatele WHERE email LIKE 'c5060-%'")|$(c5060_count "$C5060_OLD")|$(c5060_count "$C5060_DRAFT")|$(c5060_count "$C5060_SENT")|$(sq "SELECT SUM(unsubscribed_count) FROM ka_newsletters WHERE subject = 'C5060'")" "0|0|0|2|2"

# the link a newsletter mails carries its id: the queue sends one e-mail through the suite's mail sink
C5060_Q=$(c5060_subscriber c5060-queue@example.com "$(site_time '-3 days')")
C5060_SENDING=$(c5060_php 'echo $db->insert("newsletters", ["subject" => "C5060 queue", "intro" => "", "status" => "sending", "created" => date("Y-m-d H:i:s"), "started_at" => date("Y-m-d H:i:s"),
  "html" => "<p><a href=\"" . Kaleta\Core\Mailing::UNSUBSCRIBE . "\">x</a></p>", "text" => "Unsubscribe: " . Kaleta\Core\Mailing::UNSUBSCRIBE, "recipients" => 1]);')
sq "INSERT INTO ka_newsletter_queue (newsletter_id, subscriber_id, next_attempt) SELECT $C5060_SENDING, ido, '$(site_time '-1 minute')' FROM ka_odberatele WHERE email = 'c5060-queue@example.com'" > /dev/null
C5060_SINK0=$(ls "$MAIL_SINK_DIR" | wc -l | tr -d ' ')
C5060_SITE_EMAIL="$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'site_email'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'")"
[ -n "${C5060_SITE_EMAIL#*|}" ] || sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_email', 'site5060@example.com')" > /dev/null # a partial run has no sender yet
c5060_php '$app->applyTimezone(); Kaleta\Core\Mailing::processQueue($app);' > /dev/null
C5060_MAIL=$(grep -l "unsubscribe=$C5060_Q" "$MAIL_SINK_DIR"/* 2>/dev/null | head -1 || true)
expect "3.54 C: the newsletter's e-mail – List-Unsubscribe and the body link name the newsletter" \
  "$([ -n "$C5060_MAIL" ] && grep -c "^List-Unsubscribe: <.*unsubscribe=$C5060_Q&newsletter=$C5060_SENDING>" "$C5060_MAIL")|$(( $(ls "$MAIL_SINK_DIR" | wc -l | tr -d ' ') - C5060_SINK0 ))" "1|1"

# the suite shares one database: what this section made goes
for C5060_E in button oneclick old forged draft bare junk queue; do c5060_php 'Kaleta\Core\RestoreLedger::forget($db, "unsubscribed", $argv[1]);' "c5060-$C5060_E@example.com"; done
sq "DELETE FROM ka_newsletter_queue WHERE newsletter_id IN (SELECT id FROM ka_newsletters WHERE subject LIKE 'C5060%'); DELETE FROM ka_newsletters WHERE subject LIKE 'C5060%'; DELETE FROM ka_odberatele WHERE email LIKE 'c5060-%'; DELETE FROM ka_odber_fronta WHERE email LIKE 'c5060-%'" > /dev/null
if [ "${C5060_SITE_EMAIL%%|*}" = 0 ]; then sq "DELETE FROM ka_nastaveni WHERE promenna = 'site_email'" > /dev/null; else sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('site_email', '${C5060_SITE_EMAIL#*|}')" > /dev/null; fi
features --exact "$C5060_EXT"
rm -f "$WORK/c5060.html"
