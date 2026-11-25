echo "== 3.54 A1/A2: one recipient function – for every audience the count = the queue rows = recipients; a small audience gets no campaign name; unknown values are refused"
# s354_php '<code>' [args…]: PHP on the test site with $app, $db and the administrator signed in (the sections 5000–5040 share it)
s354_php() { (cd "$WORK/web" && php -r 'require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); $db = $app->db();
  $app->auth()->signInAs((array) $db->one("SELECT * FROM {uzivatele} WHERE user = ?", ["admin"])); use Kaleta\Core\Mailing; '"$1" -- "${@:2}"); }
# the settings a section changes are put back as they were (a key that did not exist is removed again)
S354_KEYS="'extensions','additional_languages','mail_mode','smtp_host','smtp_port','smtp_encryption','smtp_user','mail_from','tasks_last_run','company_name','company_street','company_postcode','company_city','contacts_backfill','pipeline_stages'"
s354_settings_save() { sq "SELECT CONCAT(promenna, '=', REPLACE(TO_BASE64(hodnota), '\\n', '')) FROM ka_nastaveni WHERE promenna IN ($S354_KEYS)" > "$WORK/s354-settings-$1"; }
s354_settings_restore() {
  local line; sq "DELETE FROM ka_nastaveni WHERE promenna IN ($S354_KEYS)" > /dev/null
  while IFS= read -r line; do [ -n "$line" ] && sq "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('${line%%=*}', FROM_BASE64('${line#*=}'))" > /dev/null; done < "$WORK/s354-settings-$1"
  rm -f "$WORK/s354-settings-$1"
}
# a site that may send: SMTP through the suite's mail sink, cron seen now, the sender named, two more languages
s354_sending_site() {
  sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES $MAIL_SINK_VALUES, ('mail_from', 'web@example.cz'), ('tasks_last_run', UNIX_TIMESTAMP()), ('company_name', 'Testovací firma s.r.o.'), ('company_street', 'Dlouhá 12'), ('company_postcode', '110 00'),
    ('company_city', 'Praha'), ('additional_languages', 'en,de'), ('pipeline_stages', '')" > /dev/null
}
# s354_fixture: no newsletters, and subscribers in three languages – cs (default, ''), en, de – with an unconfirmed one, one on the send
# register, two without a contact and three customers (won deals); prints nothing
s354_fixture() {
  s354_php '$db->run("DELETE FROM {newsletter_queue}"); $db->run("DELETE FROM {newsletters}"); $db->run("DELETE FROM {odberatele}"); $db->run("DELETE FROM {deals} WHERE title LIKE ?", ["S354 %"]);
    $now = Kaleta\Core\Clock::now(); $won = (string) Kaleta\Core\Deals::wonContactsSql($app->settings())[1][0];
    foreach ([["s354-cs1", 1, "", true, true], ["s354-cs2", 1, "", true, false], ["s354-cs3", 1, "", false, false], ["s354-cs4", 0, "", true, true], ["s354-bounced", 1, "", true, false],
        ["s354-en1", 1, "en", true, true], ["s354-en2", 1, "en", false, false], ["s354-de1", 1, "de", true, true]] as [$name, $confirmed, $language, $contact, $customer]) {
        $email = $name . "@example.com"; $contactId = null;
        if ($contact) {
            $contactId = (int) ($db->value("SELECT id FROM {contacts} WHERE email_norm = ?", [$email]) ?? $db->insert("contacts", ["email" => $email, "email_norm" => $email, "created_at" => $now, "updated_at" => $now]));
        }
        $db->insert("odberatele", ["email" => $email, "stav" => $confirmed, "token" => bin2hex(random_bytes(16)), "datum" => $now, "potvrzeno" => $confirmed ? $now : null, "language" => $language, "contact_id" => $contactId]);
        if ($customer) {
            $db->insert("deals", ["title" => "S354 " . $name, "contact_id" => $contactId, "stage" => $won, "closed_at" => $now, "created_at" => $now, "updated_at" => $now]);
        }
    }
    Kaleta\Core\SendRegister::record($db, "bounced", "s354-bounced@example.com");'
}
s354_settings_save 5000
S5000_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter +jazyky +customers +pipeline
s354_sending_site
s354_fixture

# one newsletter per audience, sent now with the number shown – each line: audience|count|recipients|queue rows|campaign name in the frozen e-mail
S5000_RUN=$(s354_php 'foreach ([["all", "", ""], ["language", "", ""], ["language", "", "en"], ["language", "", "de"], ["all", "customers", ""], ["language", "customers", "en"]] as [$audience, $filter, $language]) {
        $id = Mailing::save($app, ["subject" => "S354 " . $audience . "-" . $filter . "-" . $language, "news_mode" => "none", "button_label" => "Kontakt", "button_url" => "/kontakt",
            "audience" => $audience, "audience_filter" => $filter, "language" => $language]);
        $count = Mailing::recipientCount($app, (array) Mailing::byId($db, $id));
        Mailing::send($app, $id, null, $count);
        $n = (array) Mailing::byId($db, $id);
        echo $audience, "/", $filter, "/", $language, "|", $count, "|", $n["recipients"], "|", $db->value("SELECT COUNT(*) FROM {newsletter_queue} WHERE newsletter_id = ?", [$id]), "|",
            str_contains((string) $n["html"], "utm_campaign=") ? "tag" : "no tag", " ";
    }')
expect "3.54 A1: count = recipients = queue rows – everyone 7 (the register address is queued and skipped later), the default language 4, English 2, German 1, customers 3 (not the unconfirmed one), English customers 1; only an audience of five or more reachable people gets utm_campaign" \
  "$S5000_RUN" "all//|7|7|7|tag language//|4|4|4|no tag language//en|2|2|2|no tag language//de|1|1|1|no tag all/customers/|3|3|3|no tag language/customers/en|1|1|1|no tag "
expect "3.54 A1: the queue of the German newsletter holds the German subscriber only; the customers one no subscriber without a contact" \
  "$(sq "SELECT GROUP_CONCAT(o.email) FROM ka_newsletter_queue q JOIN ka_newsletters n ON n.id = q.newsletter_id JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE n.subject = 'S354 language--de'")|$(sq "SELECT COUNT(*) FROM ka_newsletter_queue q JOIN ka_newsletters n ON n.id = q.newsletter_id JOIN ka_odberatele o ON o.ido = q.subscriber_id WHERE n.subject = 'S354 all-customers-' AND o.contact_id IS NULL")" \
  "s354-de1@example.com|0"

# refused, never widened: a typo, a stored value nobody can choose, customers with the pipeline switched off
S5000_REFUSED=$(s354_php '$try = function (callable $f): string { try { $f(); return "sent"; } catch (\InvalidArgumentException | \DomainException $e) { return $e->getMessage(); } };
    echo $try(fn () => Mailing::save($app, ["subject" => "S354 typo", "audience" => "customer"])), "|";
    $id = Mailing::save($app, ["subject" => "S354 odd", "news_mode" => "none"]);
    $db->run("UPDATE {newsletters} SET audience = ? WHERE id = ?", ["everyone", $id]);
    echo $try(fn () => Mailing::send($app, $id)), "|", $db->value("SELECT CONCAT(status, \"/\", (SELECT COUNT(*) FROM {newsletter_queue} WHERE newsletter_id = ?)) FROM {newsletters} WHERE id = ?", [$id, $id]), "|";
    $id2 = Mailing::save($app, ["subject" => "S354 customers off", "news_mode" => "none", "customers_only" => "true"]);
    Kaleta\Core\Extensions::save($app->settings(), array_values(array_diff(Kaleta\Core\Extensions::enabled($app->settings()), ["pipeline"])));
    echo $try(fn () => Mailing::send($app, $id2, null, 99)), "|", Mailing::recipientCount($app, (array) Mailing::byId($db, $id2));')
expect "3.54 A1: a typo is refused at the save; an unknown stored audience and customers with the pipeline off refuse the send (a draft, no queue rows) and count nobody" \
  "$S5000_REFUSED" "audience must be all or language.|The newsletter has an unknown audience – choose who it goes to again.|draft/0|Only customers needs the sales pipeline or quotes switched on in Extensions – switch one on, or send to everyone in the audience.|0"

# the suite shares one database: the newsletters started here never go out (cancelled, queue gone), the settings come back
s354_php '$db->run("DELETE FROM {newsletter_queue}"); $db->run("DELETE FROM {newsletters} WHERE subject LIKE ?", ["S354 %"]);'
features --exact "$S5000_EXT"
s354_settings_restore 5000
