echo "== 3.54 A3: a backup from before 0142 (no audience columns) – today's customers newsletter goes back to a draft, a paused one stays cancelled with its state, everyone stays scheduled"
# the restored structure of 3.53 in tables of their own (prefix r5040_): ka_newsletters without the three columns of 0142
S5040_OUT=$(s354_php '$c = $app->config["db"]; $c["prefix"] = "r5040_"; $old = Kaleta\Core\Db::fromConfig($c);
    $old->run("DROP TABLE IF EXISTS {newsletters}");
    $old->run("CREATE TABLE {newsletters} LIKE `ka_newsletters`");
    $old->run("ALTER TABLE {newsletters} DROP COLUMN audience, DROP COLUMN audience_filter, DROP COLUMN unsubscribed_count");
    $now = Kaleta\Core\Clock::now();
    foreach ([[1, "draft", null, ""], [2, "sending", null, ""], [3, "draft", null, ""], [4, "paused", $now, "brake"]] as [$id, $status, $paused, $reason]) {
        $old->insert("newsletters", ["id" => $id, "subject" => "S5040 " . $id, "intro" => "", "status" => $status, "created" => $now, "recipients" => 10, "sent_count" => 4, "paused_at" => $paused, "pause_reason" => $reason]);
    }
    // the capture on that structure falls back to the columns of 3.51 – the paused state survives
    $captured = Kaleta\Core\RestoreLedger::capture($old)["newsletters"];
    $row4 = array_values(array_filter($captured, fn (array $r): bool => (int) $r["id"] === 4))[0] ?? [];
    echo count($captured), "/", $row4["pause_reason"] ?? "lost", "/", array_key_exists("audience", $row4) ? "audience" : "no audience", "|";
    // today: 1 scheduled for customers, 2 paused by a person, 3 scheduled for everyone
    $today = [
        ["id" => 1, "status" => "scheduled", "scheduled_at" => "2099-01-01 08:00:00", "started_at" => null, "finished_at" => null, "recipients" => 0, "sent_count" => 0, "failed_count" => 0,
            "skipped_count" => 0, "paused_at" => null, "pause_reason" => "", "resumed_at" => null, "audience" => "all", "audience_filter" => "customers", "audience_language" => ""],
        ["id" => 2, "status" => "paused", "scheduled_at" => null, "started_at" => $now, "finished_at" => null, "recipients" => 10, "sent_count" => 6, "failed_count" => 1,
            "skipped_count" => 0, "paused_at" => $now, "pause_reason" => "manual", "resumed_at" => null, "audience" => "language", "audience_filter" => "", "audience_language" => "en"],
        ["id" => 3, "status" => "scheduled", "scheduled_at" => "2099-01-01 08:00:00", "started_at" => null, "finished_at" => null, "recipients" => 0, "sent_count" => 0, "failed_count" => 0,
            "skipped_count" => 0, "paused_at" => null, "pause_reason" => "", "resumed_at" => null, "audience" => "all", "audience_filter" => "", "audience_language" => ""],
    ];
    (new ReflectionMethod(Kaleta\Core\RestoreLedger::class, "newsletters"))->invoke(null, $old, $today);
    foreach ($old->all("SELECT id, status, COALESCE(scheduled_at, \"-\") AS at, skipped_count, pause_reason FROM {newsletters} ORDER BY id") as $r) {
        echo implode("/", array_map("strval", $r)), " ";
    }
    $old->run("DROP TABLE {newsletters}");')
expect "3.54 A3: the capture keeps the paused state on a 3.53 structure; after the restore the customers newsletter is a draft (the backup would mail everyone), the paused one is cancelled with its reason and remainder, everyone stays scheduled, the backup's send under way and its own paused one never go on" \
  "$S5040_OUT" "4/brake/no audience|1/draft/-/0/ 2/cancelled/-/3/manual 3/scheduled/2099-01-01 08:00:00/0/ 4/cancelled/-/6/brake "
