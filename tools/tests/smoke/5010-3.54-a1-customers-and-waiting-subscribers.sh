echo "== 3.54 A1: the customers of a newsletter are the contacts of the testimonial rule; the contacts job links the confirmed subscribers it passed"
s354_settings_save 5010
S5010_EXT=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
features +newsletter +jazyky +customers +pipeline -quotes
s354_sending_site
s354_fixture

# the set (one query over the subscribers) and the rule of testimonial requests (hasWon per contact) give the same subscribers
expect "3.54 A1: the customers audience = the subscribers whose contact has a won deal (Deals::hasWon one by one), never an unconfirmed one or one without a contact" \
  "$(s354_php '[$where, $params] = Mailing::recipientsWhere($app, ["audience" => "all", "audience_filter" => "customers"]);
    $set = array_column($db->all("SELECT o.email FROM {odberatele} o WHERE " . $where . " ORDER BY o.email", $params), "email");
    $rule = array_column(array_filter($db->all("SELECT email, contact_id FROM {odberatele} WHERE stav = 1 ORDER BY email"), fn (array $r): bool => Kaleta\Core\Deals::hasWon($db, (int) $r["contact_id"], $app->settings())), "email");
    echo implode(",", $set), "|", $set === $rule ? "same" : implode(",", $rule);')" \
  "s354-cs1@example.com,s354-de1@example.com,s354-en1@example.com|same"

# a confirmed subscriber the backfill cursor already passed, with a won deal of its contact waiting – and an address that can never be linked before it
s354_php '$now = Kaleta\Core\Clock::now();
    $db->insert("odberatele", ["email" => "not an address", "stav" => 1, "token" => bin2hex(random_bytes(16)), "datum" => $now, "potvrzeno" => $now, "language" => ""]);
    $db->insert("odberatele", ["email" => "s354-late@example.com", "stav" => 1, "token" => bin2hex(random_bytes(16)), "datum" => $now, "potvrzeno" => $now, "language" => ""]);
    $contact = $db->insert("contacts", ["email" => "s354-late@example.com", "email_norm" => "s354-late@example.com", "created_at" => $now, "updated_at" => $now]);
    $db->insert("deals", ["title" => "S354 late", "contact_id" => $contact, "stage" => (string) Kaleta\Core\Deals::wonContactsSql($app->settings())[1][0], "closed_at" => $now, "created_at" => $now, "updated_at" => $now]);
    $max = (int) $db->value("SELECT MAX(ido) FROM {odberatele}");
    $app->settings()->set("contacts_backfill", (string) json_encode(["enquiry" => 1 << 30, "booking" => 1 << 30, "subscriber" => $max]));'
S5010_BEFORE=$(s354_php 'echo Mailing::recipientCount($app, ["audience_filter" => "customers"]), "/", Mailing::unlinkedCount($app, ["audience" => "all"]);')
S5010_JOB=$(run_job contacts)
S5010_AFTER=$(s354_php 'echo Mailing::recipientCount($app, ["audience_filter" => "customers"]), "/", Mailing::unlinkedCount($app, ["audience" => "all"]), "/",
    $db->value("SELECT contact_id IS NULL FROM {odberatele} WHERE email = ?", ["not an address"]), "/", json_decode($app->settings()->get("contacts_backfill"), true)["subscriber_repair"] ?? "none";')
expect "3.54 A1: the contacts job links the confirmed subscribers without a contact that its cursor passed (the late one becomes a customer); one that cannot be linked stays, blocks nobody, and the round starts again" \
  "$S5010_BEFORE|$S5010_AFTER|$(printf '%s' "$S5010_JOB" | grep -c 'waiting subscribers')" "3/4|4/1/1/0|1"
expect "3.54 A1: the backfill keeps the cursor of the waiting subscribers when it saves its own" \
  "$(run_job contacts > /dev/null; s354_php 'echo implode(",", array_keys((array) json_decode($app->settings()->get("contacts_backfill"), true)));')" "enquiry,booking,subscriber,subscriber_repair"

s354_php '$db->run("DELETE FROM {deals} WHERE title LIKE ?", ["S354 %"]); $db->run("DELETE FROM {odberatele} WHERE email IN (?, ?)", ["not an address", "s354-late@example.com"]);'
features --exact "$S5010_EXT"
s354_settings_restore 5010
