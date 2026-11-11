<?php
/**
 * Kaleta 3.34: job applications apart from enquiries (Core\Applications, migration 0119 made ka_applications).
 *
 * On a site with a Job openings collection (ka_kolekce.preset = 'jobs'):
 *  1. the feature "applications" is switched on (written down as a list, the 0073 pattern – a site that never saved its
 *     choice gets the defaults it had, plus this one);
 *  2. applications_months starts at job_applications_months when that was set, otherwise at enquiries_months (an update never
 *     shortens the retention; 0 = keep stays keep); the clean-up deletes nothing moved until an administrator saved
 *     Applications → Settings after it moved (3.34 review H1; second review N8: applications_retention_confirmed holds the
 *     highest application id confirmed, the moved rows lie above it);
 *  3. every enquiry whose source is a jobs collection (kolekce:<idk> – the certain rule Core\Jobs used since 2.11) becomes an
 *     application, each in one transaction: the row is inserted with source_enquiry_id (unique – a second run skips what
 *     moved; nothing is moved twice), the fields without file paths, the CVs listed as still in storage/prilohy (sealed
 *     false – the daily clean-up seals them in batches, Core\Applications::sealPending), the position from the page it came
 *     from, the status (new → new, read → reviewed, resolved → reviewed with a note), the replies, the responsible person,
 *     the old single note as an internal note, and the latest of receipt, reply and note as its last activity; the
 *     comments, tasks and notifications of enquiry:<idp> move to application:<id> – a responsible person and the bell entries
 *     of anyone who may not open Applications (after the update: anyone but an administrator) do not follow (review low a); a deal made from it loses the link
 *     (Deals::forgetEnquiries, unchanged since 3.32); the enquiry row goes;
 *  4. a customer record created only from moved applications is deleted – derived from the data, not from memory: an
 *     application keeps the customer record its enquiry had (source_contact_id), and a record whose only source rows are
 *     such applications (source enquiry, never edited – updated_at = created_at –, no other enquiry, booking, subscription,
 *     logged activity – a merge logs one –, deal, comment or task, no summary, no responsible person) goes; any other keeps
 *     its record, so a real customer who also applied is never deleted. Each record looked at loses the link.
 * The counts and the ids of the deals that lost their link go to the event applications.moved and the change log.
 *
 * Resumable (3.34 review M3): each enquiry moves in its own small transaction inside a try/catch – a row that fails stays an
 * enquiry, is counted and named by its number only in the event applications.move_failed, and the move goes on. A run stops
 * after MOVE_SECONDS (a web request's time limit); the enquiries still to move (job sources, idp <= applications_move_until)
 * stay hidden in Enquiries from people without Applications (Core\Applications::movingCondition), and the migration puts its
 * name into data_migrations_unfinished – Core\Migration runs it again on the next request (a visit or the administration)
 * until nothing is left. The update request of 3.33 marks it done after its first run; the name in
 * data_migrations_unfinished brings it back with the new code all the same. 3.34 second review N5: run by the code of an
 * older release (the update request), it only writes the feature, the retention and what is to move – nothing moves and no
 * customer record goes until the 3.34 code serves a request, and not while an update checks itself (update_probe); that
 * first run takes the job enquiries that arrived in between too.
 *
 * Nobody but administrators gets the section Applications (owner decision B-2): the screen tells administrators to choose.
 * Retention is not prolonged: the receipt date and the activity stay, so rows past the period go with the first clean-up
 * after the administrator confirmed the retention.
 *
 * Self-contained on purpose: the update request of 3.33 runs this file with its own classes already loaded (it stops after
 * the state, N5), so only Db, Settings and classes unchanged since 3.32 (Core\Deals::forgetEnquiries, Core\Vault::key/seal)
 * are used, each guarded.
 * Safe to run again.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $name = '0120-applications-move';
    $unfinished = array_values(array_filter(explode(',', $settings->get('data_migrations_unfinished')), static fn (string $n): bool => $n !== '' && $n !== $name));
    $resumed = count($unfinished) !== count(array_filter(explode(',', $settings->get('data_migrations_unfinished')), static fn (string $n): bool => $n !== ''));
    $finish = static function (bool $left) use ($settings, $name, $unfinished): void {
        $settings->set('data_migrations_unfinished', implode(',', $left ? [...$unfinished, $name] : $unfinished));
        if (!$left) {
            $settings->set('applications_move_until', '');
        }
    };
    try {
        $collections = $db->all("SELECT idk, seo_link FROM {kolekce} WHERE preset = 'jobs'");
        $db->value('SELECT 1 FROM {applications} LIMIT 1');
    } catch (\PDOException) {
        return; // no collections or no applications table on this database
    }
    if ($collections === []) {
        $finish(false);

        return;
    }
    if ((int) ini_get('max_execution_time') > 0) {
        @set_time_limit(300);
    }
    // one run moves for at most this long (a web request – the next request goes on); the tests make it one row
    $seconds = defined('KALETA_MIGRATION_SECONDS') ? max(0.0, (float) constant('KALETA_MIGRATION_SECONDS')) : 15.0;
    $rowsAtMost = defined('KALETA_MIGRATION_ROWS') ? max(1, (int) constant('KALETA_MIGRATION_ROWS')) : PHP_INT_MAX;
    $contactBatch = defined('KALETA_MIGRATION_CONTACTS') ? max(1, (int) constant('KALETA_MIGRATION_CONTACTS')) : 500; // the tests make the batches small
    $started = microtime(true);
    $now = date('Y-m-d H:i:s');
    $event = static function (string $type, string $severity, string $message, array $data) use ($db, $now): void {
        try {
            $db->insert('events', ['created_at' => $now, 'type' => $type, 'severity' => $severity, 'message' => mb_substr($message, 0, 255), 'data' => (string) json_encode($data)]);
        } catch (\Throwable) {
            // a record never stops the migration
        }
    };
    $slugs = [];
    foreach ($collections as $c) {
        $slugs[(int) $c['idk']] = (string) $c['seo_link'];
    }
    $sources = array_map(static fn (int $idk): string => 'kolekce:' . $idk, array_keys($slugs));
    $placeholders = implode(',', array_fill(0, count($sources), '?'));

    if (!$resumed) {
        // --- 1. the feature
        $stored = $settings->get('extensions');
        $features = match ($stored) {
            '' => ['novinky', 'poptavky', 'statistika', 'presmerovani', 'claude'], // the defaults of 3.33
            '-' => [],
            default => array_values(array_filter(array_map('trim', explode(',', $stored)))),
        };
        if (!in_array('applications', $features, true)) {
            $features[] = 'applications';
            $settings->set('extensions', implode(',', $features));
        }

        // --- 2. the retention (3.34 review H1: an update never shortens it) – job_applications_months when it was set, otherwise the
        // site's enquiries_months (0 = keep stays keep); the default 6 months is only for a site that switches the feature on by hand
        // with nothing to move. Nothing moved is purged until an administrator saved Applications → Settings.
        if ($db->value("SELECT 1 FROM {nastaveni} WHERE promenna = 'applications_months'") === null) {
            $jobMonths = (int) $settings->get('job_applications_months');
            $settings->set('applications_months', (string) max(0, min(120, $jobMonths > 0 ? $jobMonths : (int) $settings->get('enquiries_months'))));
        }

        // --- the enquiries this move takes: what is there now (a job enquiry that arrives later is an application already)
        $settings->set('applications_move_until', (string) (int) $db->value('SELECT MAX(idp) FROM {poptavky} WHERE zdroj IN (' . $placeholders . ')', $sources));
    }
    // 3.34 second review N5: under the code of an older release (the update request of 3.33 or older runs this file with its
    // own classes) only the state above is written – the feature, the retention, what is to move. Nothing moves and no
    // customer record goes inside the update request, so an update that is rolled back strands nothing; the move starts
    // on the first request the 3.34 code serves (the name in data_migrations_unfinished brings it back)
    if (defined('KALETA_MIGRATION_AS_OLD_CODE') || !method_exists(\Kaleta\Core\Migration::class, 'onlyUnfinished')) {
        $settings->set('applications_move_deferred', '1');
        $finish(true);

        return;
    }
    if ($settings->get('applications_move_deferred') === '1') {
        // the first run of the 3.34 code after an older release's update request: job enquiries that arrived in between move too
        $settings->set('applications_move_until', (string) max((int) $settings->get('applications_move_until'), (int) $db->value('SELECT MAX(idp) FROM {poptavky} WHERE zdroj IN (' . $placeholders . ')', $sources)));
        $settings->set('applications_move_deferred', '');
    }
    if ((string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'update_probe'") !== '') {
        $finish(true); // an update is checking that the new version runs (and may still roll back) – the move waits for the next request

        return;
    }
    $until = (int) $settings->get('applications_move_until');

    // --- 3. the enquiries of the jobs collections, in batches; each row in its own transaction
    $seal = static function (array $list) use ($settings): ?string {
        if ($list === []) {
            return null;
        }
        $json = (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            return \Kaleta\Core\Vault::state($settings) === 'missing' ? $json : \Kaleta\Core\Vault::seal(\Kaleta\Core\Vault::key($settings, 'applications'), $json);
        } catch (\Throwable) {
            return $json; // read as plain JSON until the clean-up seals the files and the list (Core\Applications::attachments)
        }
    };
    $moved = 0;
    $firstMoved = null;
    $failed = [];
    $deals = [];
    $comments = 0;
    $after = 0;
    $left = false;
    while (true) {
        $rows = $db->all('SELECT * FROM {poptavky} p WHERE zdroj IN (' . $placeholders . ') AND idp > ? AND idp <= ? AND NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp) ORDER BY idp LIMIT 100',
            [...$sources, $after, $until]);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $e) {
            if ($moved + count($failed) >= $rowsAtMost || microtime(true) - $started > $seconds) {
                $left = true;
                break 2;
            }
            $idp = (int) $e['idp'];
            $after = $idp;
            try {
                $stored = json_decode((string) $e['data'], true);
                $data = [];
                $files = [];
                foreach (is_array($stored) ? $stored : [] as $field) {
                    if (!is_array($field) || !is_scalar($field[0] ?? null) || !is_scalar($field[1] ?? null)) {
                        continue;
                    }
                    $data[] = [(string) $field[0], (string) $field[1]];
                    $path = is_string($field[2] ?? null) ? $field[2] : '';
                    if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.([a-z0-9]{2,5})$#D', $path, $m) === 1) {
                        $file = KALETA_ROOT . '/storage/prilohy/' . $path;
                        $files[] = ['field' => count($data) - 1, 'name' => (string) (preg_replace('/ \([^)]*\)$/', '', (string) $field[1]) ?: basename($path)), 'size' => is_file($file) ? (int) filesize($file) : 0,
                            'ext' => $m[1], 'path' => $path, 'sealed' => false];
                    }
                }
                // the position: the item of the page it came back to (as Front\EnquiryTopic), otherwise the item part of the topic
                $idk = (int) substr((string) $e['zdroj'], 8);
                $item = null;
                if (($slugs[$idk] ?? '') !== '' && preg_match('#/' . preg_quote($slugs[$idk], '#') . '/([a-z0-9-]{1,160})/?(?:\?.*)?$#', (string) $e['stranka'], $m) === 1) {
                    $item = $db->one('SELECT idp, nazev, valid_until FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? ORDER BY jazyk = \'\' DESC LIMIT 1', [$idk, $m[1]]);
                }
                $topic = (string) $e['tema'];
                $title = $item !== null ? (string) $item['nazev'] : (str_contains($topic, ' – ') ? substr($topic, strrpos($topic, ' – ') + strlen(' – ')) : '');
                $email = trim((string) $e['email']);
                $normal = mb_strtolower($email);
                $people = method_exists(\Kaleta\Core\Contacts::class, 'fromFields') ? \Kaleta\Core\Contacts::fromFields($data, $email) : ['name' => '', 'phone' => ''];
                $replies = json_decode((string) ($e['replies'] ?? ''), true);
                $activity = (string) $e['datum'];
                foreach (is_array($replies) ? $replies : [] as $reply) {
                    if (is_array($reply) && is_string($reply['at'] ?? null) && $reply['at'] > $activity) {
                        $activity = $reply['at'];
                    }
                }
                $lastComment = $db->value("SELECT MAX(created_at) FROM {comments} WHERE target = ?", ['enquiry:' . $idp]);
                $activity = is_string($lastComment) && $lastComment > $activity ? $lastComment : $activity;
                $status = (int) $e['stav'] === 0 ? 'new' : 'reviewed';
                $db->transaction(function () use ($db, $e, $idp, $data, $files, $seal, $item, $idk, $title, $email, $normal, $people, $activity, $status, $now, &$deals, &$comments, &$firstMoved): void {
                    $id = $db->insert('applications', [
                        'received_at' => (string) $e['datum'], 'job_collection_id' => $item !== null ? $idk : null, 'job_item_id' => $item !== null ? (int) $item['idp'] : null,
                        'job_title' => mb_substr($title, 0, 200), 'form' => mb_substr((string) $e['formular'], 0, 120), 'source' => (string) $e['zdroj'], 'element' => (string) $e['prvek'],
                        'page' => (string) $e['stranka'], 'language' => '', 'name' => mb_substr($people['name'], 0, 150), 'email' => mb_substr($email, 0, 190),
                        'email_norm' => filter_var($normal, FILTER_VALIDATE_EMAIL) !== false ? $normal : null, 'phone' => mb_substr($people['phone'], 0, 40),
                        'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'attachments' => $seal($files),
                        'attachment_paths' => $files === [] ? null : (string) json_encode(['bin' => [], 'old' => array_column($files, 'path')], JSON_UNESCAPED_SLASHES), 'status' => $status, 'status_at' => (string) $e['datum'],
                        'assignee_id' => $e['prirazeno'] !== null ? (int) $e['prirazeno'] : null, 'replies' => $e['replies'], 'late' => 0, 'last_activity_at' => $activity,
                        'source_enquiry_id' => $idp, 'source_contact_id' => $e['contact_id'] !== null ? (int) $e['contact_id'] : null, 'moved_at' => $now,
                    ]);
                    $firstMoved = $firstMoved === null ? $id : min($firstMoved, $id);
                    $target = 'application:' . $id;
                    foreach (['comments', 'tasks', 'notifications'] as $table) {
                        $db->run('UPDATE {' . $table . '} SET target = ? WHERE target = ?', [$target, 'enquiry:' . $idp]);
                    }
                    // review low (a): only administrators have Applications after the update – the responsible person and the bell
                    // entries (their excerpts) of anyone else do not follow
                    $db->run('UPDATE {applications} a SET assignee_id = NULL WHERE a.id = ? AND a.assignee_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {uzivatele} u WHERE u.idu = a.assignee_id AND u.blokovat = 0
                        AND (u.admin = 2 OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = \'applications\')))', [$id]);
                    $db->run('DELETE n FROM {notifications} n WHERE n.target = ? AND NOT EXISTS (SELECT 1 FROM {uzivatele} u WHERE u.idu = n.user_id AND u.blokovat = 0
                        AND (u.admin = 2 OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = \'applications\')))', [$target]);
                    $db->run("UPDATE {comments} SET kind = 'note' WHERE target = ?", [$target]);
                    $note = trim((string) ($e['poznamka'] ?? ''));
                    foreach (array_filter([$note, (int) $e['stav'] === 2 ? 'Resolved as an enquiry before the move to Applications.' : '']) as $text) {
                        $db->insert('comments', ['target' => $target, 'kind' => 'note', 'author_id' => null, 'author_name' => 'Kaleta', 'text' => mb_substr($text, 0, 5000), 'via' => 'admin', 'created_at' => $now]);
                        $comments++;
                    }
                    foreach ($db->all('SELECT id FROM {deals} WHERE source_enquiry_id = ?', [$idp]) as $deal) {
                        $deals[] = (int) $deal['id'];
                    }
                    if (method_exists(\Kaleta\Core\Deals::class, 'forgetEnquiries')) {
                        \Kaleta\Core\Deals::forgetEnquiries($db, [$idp]); // the deal stays, without the link and the person's words
                    } else {
                        $db->run('UPDATE {deals} SET source_enquiry_id = NULL WHERE source_enquiry_id = ?', [$idp]);
                    }
                    $db->delete('poptavky', ['idp' => $idp]);
                });
                $moved++;
            } catch (\Throwable) {
                $failed[] = $idp; // stays an enquiry; its number only – the message may carry the person's data
            }
        }
    }

    // --- 4. customer records that only moved applications made (from the data: a run cut short finishes it next time). 3.34
    // second review N2: in batches by record id, within the same time as the move; the migration finishes only once no
    // application names a record to look at any more
    $removed = 0;
    try {
        $lastContact = 0;
        while (true) {
            $batch = $db->all('SELECT DISTINCT source_contact_id AS id FROM {applications} WHERE source_contact_id > ? ORDER BY source_contact_id LIMIT ' . $contactBatch, [$lastContact]);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $r) {
                if (microtime(true) - $started > $seconds) {
                    $left = true;
                    break 2;
                }
                $contactId = (int) $r['id'];
                $lastContact = $contactId;
                $lone = $db->value("SELECT 1 FROM {contacts} c WHERE c.id = ? AND c.source = 'enquiry' AND (c.summary IS NULL OR c.summary = '') AND c.owner_id IS NULL AND c.updated_at <= c.created_at
                    AND NOT EXISTS (SELECT 1 FROM {poptavky} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {bookings} WHERE contact_id = c.id)
                    AND NOT EXISTS (SELECT 1 FROM {odberatele} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {contact_activities} WHERE contact_id = c.id)
                    AND NOT EXISTS (SELECT 1 FROM {deals} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {comments} WHERE target = CONCAT('contact:', c.id))
                    AND NOT EXISTS (SELECT 1 FROM {tasks} WHERE target = CONCAT('contact:', c.id))", [$contactId]);
                $db->transaction(function () use ($db, $contactId, $lone, &$removed): void {
                    if ($lone !== null) {
                        $db->run('DELETE FROM {notifications} WHERE target = ?', ['contact:' . $contactId]);
                        $removed += $db->run('DELETE FROM {contacts} WHERE id = ?', [$contactId])->rowCount();
                    }
                    $db->run('UPDATE {applications} SET source_contact_id = NULL WHERE source_contact_id = ?', [$contactId]);
                });
            }
        }
        if (!$left && $db->value('SELECT 1 FROM {applications} WHERE source_contact_id IS NOT NULL LIMIT 1') !== null) {
            $left = true; // named meanwhile – looked at on the next run
        }
    } catch (\Throwable) {
        $left = true; // looked at again on the next run
    }

    if ($moved > 0) {
        if ($settings->get('applications_files_since') === '') {
            $settings->set('applications_files_since', (string) time()); // N9: a backup older than this misses the move (the restore screen says so)
        }
        // H1: the administrator confirms the retention before anything moved is purged – N8: the confirmation is the highest id
        // confirmed, and the rows moved now lie above it (lowered below the first of them when ids were used again)
        $confirmed = $settings->get('applications_retention_confirmed');
        if ($firstMoved !== null && ctype_digit($confirmed) && (int) $confirmed >= $firstMoved) {
            $settings->set('applications_retention_confirmed', (string) max(0, $firstMoved - 1));
        }
        $message = $moved . ' job application(s) moved from Enquiries to Applications' . ($deals !== [] ? '; deals that lost their link: #' . implode(', #', array_unique($deals)) : '')
            . ($removed > 0 ? '; customer records made only by applications deleted: ' . $removed : '') . ($left ? '; the rest follows on the next requests' : '');
        $event('applications.moved', 'info', $message, ['moved' => $moved, 'deals' => array_values(array_unique($deals)), 'contacts_deleted' => $removed, 'notes' => $comments, 'left' => $left]);
        try {
            $db->insert('protokol', ['cas' => $now, 'modul' => 'applications', 'akce' => 'move', 'popis' => mb_substr($message, 0, 255)]);
        } catch (\Throwable) {
            // a record never stops the migration
        }
    }
    if ($failed !== []) {
        $event('applications.move_failed', 'warning', count($failed) . ' job enquiry(-ies) could not be moved to Applications and stay in Enquiries: #' . implode(', #', array_slice($failed, 0, 30))
            . ' – try Move by form in Applications', ['enquiries' => array_slice($failed, 0, 200), 'count' => count($failed)]);
    }
    $finish($left);
};
