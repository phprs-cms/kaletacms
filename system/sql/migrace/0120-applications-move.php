<?php
/**
 * Kaleta 3.34: job applications apart from enquiries (Core\Applications, migration 0119 made ka_applications).
 *
 * On a site with a Job openings collection (ka_kolekce.preset = 'jobs'):
 *  1. the feature "applications" is switched on (written down as a list, the 0073 pattern – a site that never saved its
 *     choice gets the defaults it had, plus this one);
 *  2. applications_months starts at job_applications_months when that was set, otherwise at enquiries_months (an update never
 *     shortens the retention; 0 = keep stays keep), and applications_retention_confirmed is cleared – the clean-up deletes
 *     nothing moved until an administrator saved Applications → Settings (3.34 review H1);
 *  3. every enquiry whose source is a jobs collection (kolekce:<idk> – the certain rule Core\Jobs used since 2.11) becomes an
 *     application, each in one transaction: the row is inserted with source_enquiry_id (unique – a second run skips what
 *     moved; nothing is moved twice), the fields without file paths, the CVs listed as still in storage/prilohy (sealed
 *     false – the daily clean-up seals them in batches, Core\Applications::sealPending), the position from the page it came
 *     from, the status (new → new, read → reviewed, resolved → reviewed with a note), the replies, the responsible person,
 *     the old single note as an internal note, and the latest of receipt, reply and note as its last activity; the
 *     comments, tasks and notifications of enquiry:<idp> move to application:<id>; a deal made from it loses the link
 *     (Deals::forgetEnquiries, unchanged since 3.32); the enquiry row goes;
 *  4. a customer record created only from moved applications (source enquiry, no other enquiry, booking, subscription,
 *     logged activity, deal, comment or task, no summary, no responsible person) is deleted; any other keeps its record.
 * The counts and the ids of the deals that lost their link go to the event applications.moved and the change log.
 *
 * Nobody but administrators gets the section Applications (owner decision B-2): the screen tells administrators to choose.
 * Retention is not prolonged: the receipt date and the activity stay, so rows past the period go with the first clean-up
 * after the administrator confirmed the retention.
 *
 * Self-contained on purpose: the update request of 3.33 runs this file with its own classes already loaded, so only Db,
 * Settings and classes unchanged since 3.32 (Core\Deals::forgetEnquiries, Core\Vault::key/seal) are used, each guarded.
 * Safe to run again.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    try {
        $collections = $db->all("SELECT idk, seo_link FROM {kolekce} WHERE preset = 'jobs'");
        $db->value('SELECT 1 FROM {applications} LIMIT 1');
    } catch (\PDOException) {
        return; // no collections or no applications table on this database
    }
    if ($collections === []) {
        return;
    }
    if ((int) ini_get('max_execution_time') > 0) {
        @set_time_limit(300);
    }
    $now = date('Y-m-d H:i:s');

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

    // --- 3. the enquiries of the jobs collections
    $slugs = [];
    foreach ($collections as $c) {
        $slugs[(int) $c['idk']] = (string) $c['seo_link'];
    }
    $sources = array_map(static fn (int $idk): string => 'kolekce:' . $idk, array_keys($slugs));
    $placeholders = implode(',', array_fill(0, count($sources), '?'));
    $seal = static function (array $list) use ($settings): ?string {
        if ($list === []) {
            return null;
        }
        $json = (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            return \Kaleta\Core\Vault::seal(\Kaleta\Core\Vault::key($settings, 'applications'), $json);
        } catch (\Throwable) {
            return $json; // read as plain JSON until the clean-up seals the files and the list (Core\Applications::attachments)
        }
    };
    $moved = 0;
    $deals = [];
    $contacts = [];
    $comments = 0;
    $rows = $db->all('SELECT * FROM {poptavky} p WHERE zdroj IN (' . $placeholders . ') AND NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp) ORDER BY idp', $sources);
    foreach ($rows as $e) {
        $idp = (int) $e['idp'];
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
        $status = match ((int) $e['stav']) {
            0 => 'new',
            default => 'reviewed',
        };
        $db->transaction(function () use ($db, $e, $idp, $data, $files, $seal, $item, $idk, $title, $email, $normal, $people, $activity, $status, $now, &$moved, &$deals, &$contacts, &$comments): void {
            $id = $db->insert('applications', [
                'received_at' => (string) $e['datum'], 'job_collection_id' => $item !== null ? $idk : null, 'job_item_id' => $item !== null ? (int) $item['idp'] : null,
                'job_title' => mb_substr($title, 0, 200), 'form' => mb_substr((string) $e['formular'], 0, 120), 'source' => (string) $e['zdroj'], 'element' => (string) $e['prvek'],
                'page' => (string) $e['stranka'], 'language' => '', 'name' => mb_substr($people['name'], 0, 150), 'email' => mb_substr($email, 0, 190),
                'email_norm' => filter_var($normal, FILTER_VALIDATE_EMAIL) !== false ? $normal : null, 'phone' => mb_substr($people['phone'], 0, 40),
                'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'attachments' => $seal($files), 'status' => $status, 'status_at' => (string) $e['datum'],
                'assignee_id' => $e['prirazeno'] !== null ? (int) $e['prirazeno'] : null, 'replies' => $e['replies'], 'late' => 0, 'last_activity_at' => $activity,
                'source_enquiry_id' => $idp,
            ]);
            $target = 'application:' . $id;
            foreach (['comments', 'tasks', 'notifications'] as $table) {
                $db->run('UPDATE {' . $table . '} SET target = ? WHERE target = ?', [$target, 'enquiry:' . $idp]);
            }
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
            if ($e['contact_id'] !== null) {
                $contacts[(int) $e['contact_id']] = true;
            }
            $moved++;
        });
    }

    // --- 4. customer records that only the moved applications made
    $removed = 0;
    foreach (array_keys($contacts) as $contactId) {
        $lone = $db->value("SELECT 1 FROM {contacts} c WHERE c.id = ? AND c.source = 'enquiry' AND (c.summary IS NULL OR c.summary = '') AND c.owner_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM {poptavky} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {bookings} WHERE contact_id = c.id)
            AND NOT EXISTS (SELECT 1 FROM {odberatele} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {contact_activities} WHERE contact_id = c.id)
            AND NOT EXISTS (SELECT 1 FROM {deals} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {comments} WHERE target = CONCAT('contact:', c.id))
            AND NOT EXISTS (SELECT 1 FROM {tasks} WHERE target = CONCAT('contact:', c.id))", [$contactId]);
        if ($lone !== null) {
            $db->run('DELETE FROM {notifications} WHERE target = ?', ['contact:' . $contactId]);
            $removed += $db->run('DELETE FROM {contacts} WHERE id = ?', [$contactId])->rowCount();
        }
    }

    if ($moved > 0) {
        $settings->set('applications_retention_confirmed', ''); // H1: the administrator confirms the retention before anything moved is purged
        $message = $moved . ' job application(s) moved from Enquiries to Applications' . ($deals !== [] ? '; deals that lost their link: #' . implode(', #', array_unique($deals)) : '')
            . ($removed > 0 ? '; customer records made only by applications deleted: ' . $removed : '');
        try {
            $db->insert('events', ['created_at' => $now, 'type' => 'applications.moved', 'severity' => 'info', 'message' => mb_substr($message, 0, 255),
                'data' => (string) json_encode(['moved' => $moved, 'deals' => array_values(array_unique($deals)), 'contacts_deleted' => $removed, 'notes' => $comments])]);
            $db->insert('protokol', ['cas' => $now, 'modul' => 'applications', 'akce' => 'move', 'popis' => mb_substr($message, 0, 255)]);
        } catch (\Throwable) {
            // a record never stops the migration
        }
    }
};
