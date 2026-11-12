echo "== 3.32 part A: CRM for daily use"
# names (U27, U41, R2): the enquiry list has who and what, My work and the page title too; the bell names a deal by its title
# for whoever has the Pipeline and by its number for anyone else; e-mails keep the number
A332_DATA='[["Jméno","Olivia Hart A332"],["E-mail","olivia@hartsteel-a332.example"],["Zpráva","We need 40 custom brackets for a new hall in March"]]'
A332_E=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, tema, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', 'Kontakt', 'olivia@hartsteel-a332.example', '$A332_DATA', 0); SELECT LAST_INSERT_ID();")
A332_ADMIN=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
A332_D=$(sq "INSERT INTO ka_deals (title, stage, created_at, updated_at) VALUES ('Brackets for the hall A332', 'new', '$(site_time)', '$(site_time)'); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries"
expect "3.32 names: the enquiry list has a Who column – the person and the first words of the message" \
  "$(grep -c '<th scope="col">Kdo</th>' "$WORK/response")|$(grep -A1 'data-enquiry-name' "$WORK/response" | grep -c 'Olivia Hart A332</bdi></a><br><small><bdi>We need 40 custom brackets for…')" "1|1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work"
expect "3.32 names: My work names the enquiry by who and what, the form and number under it" \
  "$(grep -A1 "data-enquiry=\"$A332_E\"" "$WORK/response" | grep -c "Olivia Hart A332 – We need 40 custom brackets for…</bdi></a><br><span class=\"smltxt\"><bdi>Kontakt #$A332_E")" "1"
expect "3.32 names: the enquiry's page title" "$(curl -s -b "$JAR" "$B/admin.php?module=enquiries&action=detail&id=$A332_E" | grep -c '<title>Olivia Hart A332 – We need 40 custom brackets for…')" "1"
# a writer without the Pipeline or Enquiries, signed in for the bell
sq "INSERT INTO ka_uzivatele (user, password, jmeno, email, jazyk, admin, posledni_login, potvrzeno, blokovat) VALUES ('a332-writer', '$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$PASSWORD")', 'Writer A332', '', 'cs', 0, '$(site_time)', '$(site_time)', 0)" > /dev/null
A332_WRITER=$(sq "SELECT idu FROM ka_uzivatele WHERE user = 'a332-writer'"); A332_WJAR="$WORK/jar-a332w"
A332_WTOKEN=$(curl -s -c "$A332_WJAR" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$A332_WJAR" -c "$A332_WJAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$A332_WTOKEN" -d user=a332-writer --data-urlencode "password=$PASSWORD"
sq "INSERT INTO ka_notifications (user_id, kind, target, actor_id, actor_name, excerpt, created_at) VALUES ($A332_ADMIN, 'assigned', 'deal:$A332_D', NULL, 'Wendy A332', '', '$(site_time)'),
  ($A332_ADMIN, 'assigned', 'enquiry:$A332_E', NULL, 'Wendy A332', '', '$(site_time)'), ($A332_WRITER, 'assigned', 'deal:$A332_D', NULL, 'Wendy A332', '', '$(site_time)')" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?action=inbox"
expect "3.32 names (U41): the administrator's bell names the deal and the enquiry" \
  "$(grep -c 'Obchod „Brackets for the hall A332“' "$WORK/response")|$(grep -c 'Poptávka: Olivia Hart A332 – We need' "$WORK/response")" "1|1"
expect "3.32 names: without the Pipeline the bell names the deal by its number only" \
  "$(curl -s -b "$A332_WJAR" "$B/admin.php?action=inbox" | grep -c "Obchod #$A332_D</a>")|$(curl -s -b "$A332_WJAR" "$B/admin.php?action=inbox" | grep -c 'Brackets for the hall A332')" "1|0"
expect "3.32 names: the e-mail of a notification still names the deal by its number" \
  "$(cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = Kaleta\Core\App::boot(); $place = Kaleta\Core\Inbox::places($app->db(), ["deal:" . $argv[1]])["deal:" . $argv[1]];
    [$subject, $text] = Kaleta\Core\Inbox::mail($app->settings(), ["jazyk" => "en"], ["kind" => "assigned", "actor_name" => "Wendy", "excerpt" => ""], $place, "u", "a"); echo (str_contains($subject . $text, "Brackets") ? "named" : "number") . "|" . $subject;' -- "$A332_D")" \
  "number|Wendy made you responsible for Deal #$A332_D"
# My work (R1): an opened but unanswered enquiry nobody handles stays in "nobody handles yet"; a reply or a resolved status takes it off
A332_R=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav, replies) VALUES ('$(site_time)', 'Kontakt', 'r-a332@example.com', '[]', 1, '[{\"at\":\"$(site_time)\",\"by\":\"Jana\",\"subject\":\"Re\",\"text\":\"Hi\",\"status\":\"sent\"}]'); SELECT LAST_INSERT_ID();")
A332_S=$(sq "INSERT INTO ka_poptavky (datum, formular, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 's-a332@example.com', '[]', 2); SELECT LAST_INSERT_ID();")
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=my_work"
expect "3.32 My work (R1): the opened enquiry (read, no reply, nobody) is still listed; the answered and the resolved one are not" \
  "$(sq "SELECT stav FROM ka_poptavky WHERE idp = $A332_E")|$(awk '/data-list="unassigned"/{u=1} /<\/section>/{u=0} u' "$WORK/response" | grep -c "data-enquiry=\"$A332_E\"")|$(grep -c "data-enquiry=\"$A332_R\"\|data-enquiry=\"$A332_S\"" "$WORK/response")|$(grep -c 'Nezodpovězené poptávky bez odpovědné osoby' "$WORK/response")" "1|1|0|1"
# Create deal (U25, R4): the enquiry links to the short form prefilled – the title from who and what (never just "Kontakt"),
# value and currency empty but offered, the first open stage; saving makes the deal from the enquiry; a second form goes back
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A332_E"
expect "3.32 Create deal: the enquiry links to the prefilled form" "$(grep -c "href=\"/admin.php?module=pipeline&amp;action=new&amp;enquiry=$A332_E\" data-deal-create" "$WORK/response")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=pipeline&action=new&enquiry=$A332_E"
expect "3.32 Create deal: the form – the title, an empty value and currency, the first stage, the enquiry named" \
  "$(grep -c 'name="title" maxlength="200" required value="Olivia Hart A332 – We need 40 custom brackets for…"' "$WORK/response")|$(grep -c 'name="value" maxlength="20" value=""' "$WORK/response")|$(grep -c '<option value="" selected>—</option>' "$WORK/response")|$(grep -c '<option value="new" selected>' "$WORK/response")|$(grep -c "name=\"enquiry\" value=\"$A332_E\"" "$WORK/response")|$(grep -c "data-deal-from-enquiry=\"$A332_E\"" "$WORK/response")" \
  "1|1|1|1|1|1"
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=pipeline&action=save" -d "_csrf=$(csrf)" -d id=0 -d "enquiry=$A332_E" \
  --data-urlencode "title=Olivia Hart A332 – We need 40 custom brackets for…" -d value= -d currency= -d expected_close= -d stage=new -d owner_id=0)
A332_DE=$(sq "SELECT IFNULL(MAX(id), 0) FROM ka_deals WHERE source_enquiry_id = $A332_E")
expect "3.32 Create deal: saved from the enquiry – no value or currency, back to the deal" \
  "$(sq "SELECT CONCAT_WS('/', title, stage, IFNULL(value, '-'), IFNULL(currency, '-'), source_enquiry_id) FROM ka_deals WHERE id = $A332_DE")|$(echo "$REDIRECT" | grep -c "action=detail&id=$A332_DE")" \
  "Olivia Hart A332 – We need 40 custom brackets for…/new/-/-/$A332_E|1"
expect "3.32 Create deal: with an open deal the form goes back to the enquiry" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' "$B/admin.php?module=pipeline&action=new&enquiry=$A332_E" | grep -c "module=enquiries&action=detail&id=$A332_E#deal")" "1"
# MCP save_deal with enquiry: the same default title; an explicit title still wins
A332_DATA2='[["Jméno","Hannah Fischer A332"],["Zpráva","Kitchen for a new family house in the spring"]]'
A332_M=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, tema, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', 'Kontakt', 'hannah-a332@example.com', '$A332_DATA2', 1); SELECT LAST_INSERT_ID();")
A332_M2=$(sq "INSERT INTO ka_poptavky (datum, formular, zdroj, tema, email, data, stav) VALUES ('$(site_time)', 'Kontakt', 'stranka:1', 'Kontakt', 'ida-a332@example.com', '$A332_DATA2', 1); SELECT LAST_INSERT_ID();")
mcp save_deal "{\"enquiry\":$A332_M}" > "$WORK/response"; mcp save_deal "{\"enquiry\":$A332_M2,\"title\":\"Own title A332\"}" > /dev/null
expect "3.32 save_deal with enquiry: who and what as the default title; an explicit title wins" \
  "$(sq "SELECT title FROM ka_deals WHERE source_enquiry_id = $A332_M")|$(sq "SELECT title FROM ka_deals WHERE source_enquiry_id = $A332_M2")" \
  "Hannah Fischer A332 – Kitchen for a new family house…|Own title A332"
# defaults (R4): a deal created without a responsible person gets its creator ("none" still means nobody); the reply form
# offers the follow-up – chosen, the signed-in person's own task "Follow up: <form>" is added with the reply
expect "3.32 defaults: a deal from save_deal without owner is the creator's; owner none leaves it to nobody" \
  "$(sq "SELECT IFNULL(owner_id, '-') FROM ka_deals WHERE source_enquiry_id = $A332_M")|$(mcp save_deal '{"title":"Nobody A332","owner":"none"}' > /dev/null; sq "SELECT IFNULL(owner_id, '-') FROM ka_deals WHERE title = 'Nobody A332'")" "$A332_ADMIN|-"
[ -z "${SMTP_PID:-}" ] || kill "$SMTP_PID" 2>/dev/null || true
A332_SMTP="$WORK/smtp-a332"; mkdir -p "$A332_SMTP"
for i in $(seq 1 20); do (exec 3<>"/dev/tcp/127.0.0.1/$SMTP2_PORT") 2>/dev/null || break; sleep 0.2; done
command php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$A332_SMTP" > /dev/null 2>&1 & SMTP_PID=$!
sq "DROP TABLE IF EXISTS ka_a332_mail; CREATE TABLE ka_a332_mail AS SELECT * FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from');
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=detail&id=$A332_M"
expect "3.32 defaults: the reply form offers the follow-up" "$(grep -c 'name="follow_up" data-reply-follow-up' "$WORK/response")|$(grep -c "<option value=\"3days\">" "$WORK/response")" "1|1"
c10_reply "$A332_M" --data-urlencode "subject=Re: kitchen" --data-urlencode "text=Thank you, we will send a proposal." -d follow_up=3days
expect "3.32 defaults: the reply with a follow-up – the task is mine, about the enquiry, due in 3 days, titled by the form (its reminder is e-mailed)" \
  "$(sq "SELECT CONCAT(title, '|', assignee_id, '|', DATE(due_at)) FROM ka_tasks WHERE target = 'enquiry:$A332_M'")" "Ozvat se: Kontakt|$A332_ADMIN|$(site_date '+3 days')"
c10_reply "$A332_M2" --data-urlencode "subject=Re" --data-urlencode "text=Thank you." -d follow_up=
expect "3.32 defaults: … without a choice no task is added" "$(sq "SELECT COUNT(*) FROM ka_tasks WHERE target = 'enquiry:$A332_M2'")" "0"
sq "DELETE FROM ka_nastaveni WHERE promenna IN ('mail_mode', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'mail_from'); INSERT INTO ka_nastaveni SELECT * FROM ka_a332_mail; DROP TABLE ka_a332_mail" > /dev/null

