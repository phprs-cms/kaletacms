echo "== 3.40 (C): applications II – the counts and the statistics, Reject and send the rejection, the board, other applications, the talent pool renewal, the webhook, the deletion reminder"
c340_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $app->applyTimezone(); '"$1"); }
c340_post() { local jar="$1" action="$2"; shift 2; curl -s -b "$jar" -o "$WORK/response" "$B/admin.php?module=applications"; curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?module=applications&action=$action" -d "_csrf=$(csrf)" "$@"; }
C340_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$([ -z "$C340_EXT" ] && echo 'novinky,poptavky,statistika,presmerovani,claude' || echo "$C340_EXT"),applications');
  DROP TABLE IF EXISTS ka_c340_saved; CREATE TABLE ka_c340_saved AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'applications_months', 'applications_pool_months', 'applications_claude')" > /dev/null
# HR: only Applications
C340_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat, notify_email) VALUES ('c340-hr', '$C340_HASH', 'Hedda C340', 'hedda-c340@example.com', 'en', 1, '$(site_time)', '$(site_time)', 0, 'off');
  INSERT INTO ka_uzivatele_prava (fk_id_user, ident_modulu) SELECT idu, 'applications' FROM ka_uzivatele WHERE user = 'c340-hr'" > /dev/null
C340_HR=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'c340-hr'"); C340_ADMIN=$(sq "SELECT MIN(idu) FROM ka_uzivatele WHERE admin = 2 AND blokovat = 0")
C340_JH="$WORK/c340-hr.jar"; curl -s -c "$C340_JH" -b "$C340_JH" -o "$WORK/response" "$B/admin.php"; curl -s -b "$C340_JH" -c "$C340_JH" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(csrf)" -d user=c340-hr --data-urlencode "password=$PASSWORD"
c340_app() { sq "INSERT INTO ka_applications (received_at, job_item_id, job_title, form, name, email, email_norm, language, data, status, status_at, last_activity_at) VALUES ('$(site_time)', 9340, 'Welder C340', 'Job application', $1); SELECT LAST_INSERT_ID();"; }

# the counts: received and each status once per application and month, kept after the application is deleted
c340_php 'Kaleta\Core\ApplicationCounts::bump($app->db(), "received", 9340, "Welder C340"); Kaleta\Core\ApplicationCounts::bump($app->db(), "received", 9340, "Welder C340");' > /dev/null
C340_A=$(c340_app "'Anna Ray C340', 'anna-c340@example.com', 'anna-c340@example.com', '', '[[\"Full name\",\"Anna Ray C340\"]]', 'new', '$(site_time)', '$(site_time)'")
c340_post "$C340_JH" status -d "id=$C340_A" -d status=interview > /dev/null; c340_post "$C340_JH" status -d "id=$C340_A" -d status=reviewed > /dev/null; c340_post "$C340_JH" status -d "id=$C340_A" -d status=interview > /dev/null
expect "3.40 counts: received twice, an interview reached twice in one month counts once; the row names the position only" \
  "$(sq "SELECT CONCAT(received, '|', interview, '|', job_title) FROM ka_application_counts WHERE month = '$(site_time now Y-m)' AND job_item_id = 9340")" "2|1|Welder C340"
expect "3.40 counts: the applications of the job page (3.34) were counted as received" "$(sq "SELECT IFNULL(SUM(received), 0) > 0 FROM ka_application_counts WHERE job_item_id = ${T334_ITEM:-0}")" "1"
curl -s -b "$C340_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=stats"
expect "3.40 statistics: HR sees twelve months and the position with its counts – never a name or an address" \
  "$(grep -c 'data-applications-stats' "$WORK/response" | awk '{print ($1 > 0)}')|$(grep -c "data-stats-month=" "$WORK/response")|$(grep -c 'Welder C340' "$WORK/response" | awk '{print ($1 > 0)}')|$(grep -c 'Anna Ray C340\|anna-c340' "$WORK/response")" "1|12|1|0"

# (the columns come one by one and are joined here: CONCAT/IFNULL/GROUP_CONCAT over ascii columns, literals and JSON results
# mix collations, which MySQL 8.4 refuses – the error made the value empty on CI)
# Reject and send the rejection: refused without SMTP; with it each one gets the template, no e-mail = rejected only, rejected already = left alone
C340_B=$(c340_app "'', '', NULL, '', '[]', 'reviewed', '$(site_time)', '$(site_time)'")
C340_C=$(c340_app "'Carl C340', 'carl-c340@example.com', 'carl-c340@example.com', '', '[]', 'rejected', '$(site_time)', '$(site_time)'")
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
c340_post "$C340_JH" bulk -d do=reject_reply -d "ids[]=$C340_A" -d "ids[]=$C340_B" -d reply_reason=position_filled > /dev/null
expect "3.40 bulk rejection: without SMTP nothing changes" "$(sq "SELECT GROUP_CONCAT(status ORDER BY id) FROM ka_applications WHERE id IN ($C340_A, $C340_B)")" "interview,reviewed"
[ -z "${SMTP_PID:-}" ] || kill "$SMTP_PID" 2>/dev/null || true
C340_SMTP="$WORK/smtp-340c"; mkdir -p "$C340_SMTP"; command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$C340_SMTP" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
# no background pass meanwhile: a due job (a reminder, a digest) would add its own e-mail to the count below – a run after the
# machine slept found two (3.41 review); the scheduler is held and a pass already running is waited for
background_jobs hold; background_jobs settle
sleep 1; C340_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
curl -s -b "$C340_JH" -o "$WORK/response" "$B/admin.php?module=applications&status=open"
expect "3.40 bulk rejection: the list offers it behind a confirmation" "$(grep -c 'value="reject_reply" data-potvrdit=' "$WORK/response")" "1"
c340_post "$C340_JH" bulk -d do=reject_reply -d "ids[]=$C340_A" -d "ids[]=$C340_B" -d "ids[]=$C340_C" -d reply_reason=position_filled > /dev/null
expect "3.40 bulk rejection: each rejected with the reason and the history; the one with an address got the reply on its own history; the rejected one is left alone" \
  "$(sq "SELECT status, reject_reason, JSON_LENGTH(replies), JSON_UNQUOTE(JSON_EXTRACT(history, CONCAT('\$[', JSON_LENGTH(history) - 1, '].to'))) FROM ka_applications WHERE id IN ($C340_A, $C340_B, $C340_C) ORDER BY id" \
    | awk -F'\t' '{ printf "%s%s:%s:%s:%s", (NR > 1 ? "," : ""), $1, ($2 == "NULL" ? "-" : $2), ($3 == "NULL" ? 0 : $3), ($4 == "NULL" ? "-" : $4) }')" \
  "rejected:position_filled:1:rejected,rejected:position_filled:0:rejected,rejected:-:0:-"
C340_F=$(grep -l "^X-Rcpt-To: anna-c340@example.com" "$C340_SMTP"/*.eml 2>/dev/null | tail -1 || true); if [ -n "$C340_F" ]; then eml "$C340_F" > "$WORK/c340.txt"; else : > "$WORK/c340.txt"; fi
expect "3.40 bulk rejection: one e-mail – the rejection template with the position and the sender's signature; the mail log names nobody" \
  "$(ls "$C340_SMTP"/*.eml 2>/dev/null | wc -l | tr -d ' ')|$(grep -c 'Welder C340' "$WORK/c340.txt" | awk '{print ($1 > 0)}')|$(grep -c -- '^-- ' "$WORK/c340.txt")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(predmet LIKE '%Anna%' OR predmet LIKE '%Welder%')) FROM ka_posta WHERE idp > $C340_P0")" \
  "1|1|1|1|0"
background_jobs resume

# the board of one position: a column per status, each card moves with its own form and comes back to the board
C340_D=$(c340_app "'Dora C340', 'dora-c340@example.com', 'dora-c340@example.com', '', '[]', 'new', '$(site_time '-10 days')', '$(site_time '-10 days')'")
sq "UPDATE ka_applications SET received_at = '$(site_time '-10 days')' WHERE id = $C340_D" > /dev/null
curl -s -b "$C340_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=board&job=9340"
expect "3.40 board: six columns, Dora waiting in New, Anna in Rejected (decided now); no deal words" \
  "$(grep -c 'data-board-status=' "$WORK/response")|$(tr -d '\n' < "$WORK/response" | grep -o 'data-board-status="new".*data-board-status="reviewed"' | grep -c "data-board-card=\"$C340_D\"")|$(tr -d '\n' < "$WORK/response" | grep -o 'data-board-status="rejected".*' | grep -c "data-board-card=\"$C340_A\"")|$(grep -c 'obchod-po-terminu' "$WORK/response" | awk '{print ($1 > 0)}')" \
  "6|1|1|1"
expect "3.40 board: a card's Move changes the status and returns to the board at the card" \
  "$(c340_post "$C340_JH" status -d "id=$C340_D" -d status=interview -d return=board -d job=9340 | grep -o 'action=board&job=9340#application-[0-9]*')|$(sq "SELECT status FROM ka_applications WHERE id = $C340_D")" \
  "action=board&job=9340#application-$C340_D|interview"

# other applications from this address: on the detail and in get_application, never another address
C340_E=$(c340_app "'Dora C340', 'dora-c340@example.com', 'dora-c340@example.com', '', '[]', 'new', '$(site_time)', '$(site_time)'")
curl -s -b "$C340_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=detail&id=$C340_E"
expect "3.40 other applications: the detail lists Dora's other one with its deletion day, not Anna's" \
  "$(tr -d '\n' < "$WORK/response" | grep -o 'data-application-others.*</ul>' | grep -c "id=$C340_D\"")|$(tr -d '\n' < "$WORK/response" | grep -o 'data-application-others.*</ul>' | grep -c "id=$C340_A\"")" "1|0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_claude', '1')" > /dev/null
mcp get_application "{\"id\":$C340_E}" > "$WORK/response"; mcp_text; grep -q other_applications "$WORK/text" || head -c 400 "$WORK/text"
expect "3.40 MCP get_application: other_applications names Dora's other application by number and status only" \
  "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo json_encode(array_map(fn ($o) => [$o["id"], $o["status"], isset($o["email"]) || isset($o["name"])], $d["other_applications"] ?? []));' -- "$WORK/text")" "[[$C340_D,\"interview\",false]]"

# the talent pool: the renewal e-mail a month before the consent lapses, in the applicant's language, one token for keep or delete
C340_P=$(c340_app "'Petra Pool C340', 'petra-c340@example.com', 'petra-c340@example.com', '', '[]', 'rejected', '$(site_time '-11 months')', '$(site_time '-11 months')'")
sq "UPDATE ka_applications SET pool_consent_at = '$(site_time '-11 months')', pool_consent_text = 'Keep it', pool_until = '$(site_date '+10 days')', decided_at = '$(site_time '-11 months')' WHERE id = $C340_P" > /dev/null
C340_Q=$(c340_app "'Quido C340', 'quido-c340@example.com', 'quido-c340@example.com', '', '[]', 'rejected', '$(site_time '-11 months')', '$(site_time '-11 months')'")
sq "UPDATE ka_applications SET pool_consent_at = '$(site_time '-11 months')', pool_until = '$(site_date '+20 days')', decided_at = '$(site_time '-11 months')' WHERE id = $C340_Q;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_pool_months', '12')" > /dev/null
curl -s -b "$C340_JH" -o "$WORK/response" "$B/admin.php?module=applications&action=pool"
expect "3.40 talent pool: HR sees both, the soonest to lapse first, the renewal not sent yet" \
  "$(grep -o "data-pool-application=\"\($C340_P\|$C340_Q\)\"" "$WORK/response" | tr -d '\n')" "data-pool-application=\"$C340_P\"data-pool-application=\"$C340_Q\""
C340_P0=$(sq "SELECT IFNULL(MAX(idp), 0) FROM ka_posta")
expect "3.40 talent pool: the daily run sends two renewal e-mails and only once per consent" \
  "$(c340_php 'echo Kaleta\Core\TalentPool::sendRenewals($app);')|$(c340_php 'echo Kaleta\Core\TalentPool::sendRenewals($app);')|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id IN ($C340_P, $C340_Q) AND pool_renewal_sent_at IS NOT NULL AND LENGTH(pool_token) = 64")" "2|0|2"
C340_F=$(grep -l "^X-Rcpt-To: petra-c340@example.com" "$C340_SMTP"/*.eml 2>/dev/null | tail -1 || true); if [ -n "$C340_F" ]; then eml "$C340_F" > "$WORK/c340.txt"; else : > "$WORK/c340.txt"; fi
C340_LINK=$(grep -o "https\?://[^ ]*/application-consent/[a-f0-9]\{64\}" "$WORK/c340.txt" | head -1); C340_LINK_PATH="/${C340_LINK#*://*/}"
expect "3.40 talent pool: the e-mail is in the site's language with the keep and the delete link (one token); the mail log names nobody; the token is stored hashed" \
  "$(grep -c 'Můžeme vaši žádost uchovat' "$WORK/c340.txt" | awk '{print ($1 > 0)}')|$(grep -c 'application-consent/[a-f0-9]\{64\}?choice=keep' "$WORK/c340.txt")|$(grep -c 'application-consent/[a-f0-9]\{64\}?choice=delete' "$WORK/c340.txt")|$(sq "SELECT SUM(predmet LIKE '%Petra%' OR predmet LIKE '%Welder%') FROM ka_posta WHERE idp > $C340_P0")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE pool_token = '${C340_LINK##*/}'")" \
  "1|1|1|0|0"
curl -s -o "$WORK/response" -D "$WORK/c340-headers" "$B$C340_LINK_PATH?choice=keep"
expect "3.40 talent pool: opening the link only shows the choice – nothing changes, nothing is cached, noindex" \
  "$(grep -c 'name="choice" value="keep"' "$WORK/response")|$(grep -c 'name="choice" value="delete"' "$WORK/response")|$(sq "SELECT pool_token IS NOT NULL FROM ka_applications WHERE id = $C340_P")|$(grep -ci '^x-cache' "$WORK/c340-headers")|$(grep -c 'noindex' "$WORK/response" | awk '{print ($1 > 0)}')" "1|1|1|0|1"
curl -s -o "$WORK/response" -X POST "$B$C340_LINK_PATH" -d choice=keep
expect "3.40 talent pool: Keep renews the consent for applications_pool_months and uses the token up" \
  "$(sq "SELECT CONCAT(pool_until = '$(site_date '+12 months')', '|', pool_token IS NULL, '|', DATE(pool_consent_at) = '$(site_date today)') FROM ka_applications WHERE id = $C340_P")|$(curl -s -X POST "$B$C340_LINK_PATH" -d choice=delete | grep -c 'name="choice"')|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $C340_P")" \
  "1|1|1|0|1"
C340_F=$(grep -l "^X-Rcpt-To: quido-c340@example.com" "$C340_SMTP"/*.eml 2>/dev/null | tail -1 || true); if [ -n "$C340_F" ]; then eml "$C340_F" > "$WORK/c340.txt"; else : > "$WORK/c340.txt"; fi
C340_LINK=$(grep -o "https\?://[^ ]*/application-consent/[a-f0-9]\{64\}" "$WORK/c340.txt" | head -1); C340_LINK_PATH="/${C340_LINK#*://*/}"
sq "INSERT INTO ka_comments (target, kind, author_name, text, via, created_at) VALUES ('application:$C340_Q', 'note', 'Hedda', 'Call later', 'admin', '$(site_time)')" > /dev/null
curl -s -o /dev/null -X POST "$B$C340_LINK_PATH" -d choice=delete
expect "3.40 talent pool: Delete removes the application with its notes; the event and the change log name only the number" \
  "$(sq "SELECT COUNT(*) FROM ka_applications WHERE id = $C340_Q")|$(sq "SELECT COUNT(*) FROM ka_comments WHERE target = 'application:$C340_Q'")|$(sq "SELECT CONCAT(COUNT(*), '|', SUM(message LIKE '%Quido%')) FROM ka_events WHERE type = 'application.deleted_by_applicant'")|$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'applications' AND akce = 'deleted_by_applicant' AND popis = '#$C340_Q'")" \
  "0|0|1|0|1"
sq "UPDATE ka_applications SET pool_token = SHA2('expired-c340', 256), pool_token_until = '$(site_time '-1 hour')' WHERE id = $C340_P" > /dev/null
expect "3.40 talent pool: an expired or unknown token gets the same answer, no choice" \
  "$(curl -s "$B/application-consent/$(php -r 'echo hash("sha256", "expired-c340");')" | grep -c 'name="choice"')|$(curl -s "$B/application-consent/$(php -r 'echo str_repeat("ab", 32);')" | grep -c 'name="choice"')" "0|0"
expect "3.40 talent pool: the e-mail of a German application is German" \
  "$(c340_php '$m = Kaleta\Core\TalentPool::compose($app, ["name" => "Jörg", "job_title" => "Schweißer", "language" => "de", "pool_until" => "2027-01-31"], str_repeat("c", 64)); echo str_contains($m["subject"], "Dürfen wir Ihre Bewerbung aufbewahren") && str_contains($m["text"], "Hallo Jörg,") ? "de" : $m["subject"];')" "de"

# the webhook application.received: the number and the position only; an address that is not https is refused
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=applications&action=settings"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=applications&action=settings_save" -d "_csrf=$(csrf)" -d applications_months=6 -d applications_pool_months=12 -d webhook_applications=http://hooks.invalid/c340
expect "3.40 webhook: an http:// address is refused" "$(sq "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'webhook_applications' AND hodnota <> ''")" "0"
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('webhook_applications', 'https://hooks.invalid/c340')" > /dev/null
C340_W0=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_webhook_deliveries")
c340_php 'Kaleta\Core\TalentPool::webhook($app, '"$C340_E"', ["item" => 9340, "title" => "Welder C340", "collection" => null, "late" => false]);' > /dev/null
expect "3.40 webhook: application.received carries the event, the number and the position – nothing of the applicant" \
  "$(sq "SELECT CONCAT(event, '|', JSON_UNQUOTE(JSON_EXTRACT(body, '$.event')), '|', JSON_EXTRACT(body, '$.application'), '|', JSON_UNQUOTE(JSON_EXTRACT(body, '$.position')), '|', body LIKE '%dora%' OR body LIKE '%Dora%') FROM ka_webhook_deliveries WHERE id > $C340_W0")" \
  "application.received|application.received|$C340_E|Welder C340|0"
sq "DELETE FROM ka_webhook_deliveries WHERE id > $C340_W0; DELETE FROM ka_nastaveni WHERE promenna = 'webhook_applications'" > /dev/null

# the deletion reminder: once per deletion day (a dismissed bell entry does not bring it back), again when the day moved; hired ones too
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('applications_months', '6')" > /dev/null
C340_W=$(c340_app "'Walt C340', 'walt-c340@example.com', 'walt-c340@example.com', '', '[]', 'interview', '$(site_time)', '$(site_time '-6 months +3 days')'")
C340_H=$(c340_app "'Hank C340', 'hank-c340@example.com', 'hank-c340@example.com', '', '[]', 'hired', '$(site_time '-6 months +3 days')', '$(site_time '-6 months +3 days')'")
C340_R=$(c340_app "'Rex C340', 'rex-c340@example.com', 'rex-c340@example.com', '', '[]', 'rejected', '$(site_time '-6 months +3 days')', '$(site_time '-6 months +3 days')'")
sq "UPDATE ka_applications SET assignee_id = $C340_ADMIN WHERE id IN ($C340_W, $C340_H, $C340_R); UPDATE ka_applications SET decided_at = status_at WHERE id IN ($C340_H, $C340_R)" > /dev/null
c340_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.40 reminder: the undecided and the hired one are announced (the day remembered), the rejected one is not" \
  "$(sq "SELECT GROUP_CONCAT(target ORDER BY target) FROM ka_notifications WHERE kind = 'deletion_due' AND target IN ('application:$C340_W', 'application:$C340_H', 'application:$C340_R')")|$(sq "SELECT COUNT(*) FROM ka_applications WHERE id IN ($C340_W, $C340_H) AND deletion_warned_on IS NOT NULL")" \
  "$(printf '%s\n' "application:$C340_H" "application:$C340_W" | sort | paste -sd, -)|2"
sq "DELETE FROM ka_notifications WHERE target IN ('application:$C340_W', 'application:$C340_H')" > /dev/null
c340_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.40 reminder: a dismissed bell entry does not come back for the same day" "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'deletion_due' AND target IN ('application:$C340_W', 'application:$C340_H')")" "0"
sq "UPDATE ka_applications SET last_activity_at = '$(site_time '-6 months +5 days')' WHERE id = $C340_W" > /dev/null
c340_php 'Kaleta\Core\Applications::purge($app);' > /dev/null
expect "3.40 reminder: the deletion moved and came close again – announced again for the new day" \
  "$(sq "SELECT COUNT(*) FROM ka_notifications WHERE kind = 'deletion_due' AND target = 'application:$C340_W'")|$(sq "SELECT deletion_warned_on = '$(site_date '+5 days')' FROM ka_applications WHERE id = $C340_W")" "1|1"

# the monthly report keeps the counts after the applications are gone
c340_php 'Kaleta\Core\Applications::delete($app, ['"$C340_A, $C340_B, $C340_C, $C340_D, $C340_E, $C340_P, $C340_W, $C340_H, $C340_R"'], false);' > /dev/null
expect "3.40 monthly report: recruitment counts come from the counts – the interview stays after the deletion, no person" \
  "$(c340_php '$c = Kaleta\Core\Applications::monthCounts($app->db(), date("Y-m-01 00:00:00"), date("Y-m-d H:i:s", strtotime("first day of next month 00:00"))); echo $c === null ? "null" : (($c["interview"] >= 1 ? "kept" : "lost") . "|" . (str_contains(json_encode($c), "@") || str_contains(json_encode($c), "Anna") ? "leak" : "ok"));')" "kept|ok"
kill "$SMTP_PID" 2>/dev/null || true
sq "DELETE FROM ka_application_counts WHERE job_item_id = 9340;
  DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from', 'applications_months', 'applications_pool_months', 'applications_claude'); INSERT INTO ka_nastaveni SELECT * FROM ka_c340_saved; DROP TABLE ka_c340_saved;
  DELETE FROM ka_uzivatele_prava WHERE fk_id_user = $C340_HR; DELETE FROM ka_uzivatele WHERE idu = $C340_HR;
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('extensions', '$C340_EXT'); DELETE FROM ka_nastaveni WHERE promenna = 'extensions' AND hodnota = ''" > /dev/null
