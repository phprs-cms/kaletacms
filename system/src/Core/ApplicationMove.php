<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * Moving enquiries of one form to job applications and back (3.34 B, the admin tool "Move by form" in Applications): for
 * a site whose career form is not on a job's item page, so migration 0120 could not tell its enquiries were applications.
 * A form is the pair (form name, source) – the same form on two pages is two lines. Both ways run in batches (BATCH) and
 * are safe to run again: an enquiry moves once (ka_applications.source_enquiry_id is unique), an application that went
 * back is no longer an application.
 *
 * To applications – each enquiry in one transaction, the steps of migration 0120: the row with source_enquiry_id, the
 * fields without file paths, the CVs listed as still in storage/prilohy (sealed false – the daily clean-up seals them,
 * Applications::sealPending), the position looked up on the server (Applications::position), the status (new → new,
 * read → reviewed, resolved → reviewed with a note), the replies, the responsible person, the old single note as an internal
 * note, the latest of receipt, reply and note as its last activity; comments, tasks and notifications follow to
 * application:<id>; a deal made from it loses the link (Deals::forgetEnquiries); the enquiry goes; a customer record made
 * only by the moved enquiries is deleted (forgetLoneContacts – from the data, source_contact_id).
 *
 * Back to enquiries: the row with the fields, each CV decrypted back into storage/prilohy (the enquiries' place), the
 * status (new → new, hired or rejected → resolved, the rest → read), the replies, the responsible person, an internal note
 * with the status it had; comments, tasks and notifications follow to enquiry:<idp>; the application and its sealed files
 * go. The customers job links the enquiry to a customer record as for any other.
 */
final class ApplicationMove
{
    /** Rows moved per request (the admin form says how many are left and runs again). */
    public const int BATCH = 200;

    /** A form name that looks like a job application (the tool lists these first). */
    public const string LOOKS = '/(application|apply|job|career|cv\b|resume|résumé|žádost o (místo|práci|zaměstnání)|uchaze|kariér|životopis|práce u nás|bewerb|stellen|karriere|lebenslauf|empleo|candidat|trabaja|curr[ií]cul|praca|aplikacj|zamestnan|pracovn)/iu';

    /**
     * The forms of the enquiries: form, source, how many, how many were sorted as kind job, and whether it looks like an
     * application form – those first, then by count. At most 100.
     *
     * @return list<array{form: string, source: string, count: int, jobs: int, looks: bool}>
     */
    public static function enquiryForms(Db $db): array
    {
        $rows = array_map(fn (array $r): array => ['form' => (string) $r['formular'], 'source' => (string) $r['zdroj'], 'count' => (int) $r['n'], 'jobs' => (int) $r['jobs'],
            'looks' => (int) $r['jobs'] > 0 || preg_match(self::LOOKS, (string) $r['formular']) === 1],
            $db->all("SELECT formular, zdroj, COUNT(*) AS n, SUM(kategorie = 'job') AS jobs FROM {poptavky} GROUP BY formular, zdroj ORDER BY n DESC LIMIT 100"));
        usort($rows, fn (array $a, array $b): int => [$b['looks'], $b['count']] <=> [$a['looks'], $a['count']]);

        return $rows;
    }

    /**
     * The forms of the applications: form, source, how many – the way back.
     *
     * @return list<array{form: string, source: string, count: int}>
     */
    public static function applicationForms(Db $db): array
    {
        return array_map(fn (array $r): array => ['form' => (string) $r['form'], 'source' => (string) $r['source'], 'count' => (int) $r['n']],
            $db->all('SELECT form, source, COUNT(*) AS n FROM {applications} GROUP BY form, source ORDER BY n DESC LIMIT 100'));
    }

    /** A form as one value of the tool's choice ("<source>|<form name>") and back. Pure. */
    public static function key(string $form, string $source): string
    {
        return $source . '|' . $form;
    }

    /**
     * @return array{form: string, source: string}|null
     */
    public static function parseKey(string $key): ?array
    {
        $at = strpos($key, '|');

        return $at === false ? null : ['form' => substr($key, $at + 1), 'source' => substr($key, 0, $at)];
    }

    /** The old status of an enquiry as an application status (migration 0120): new → new, read or resolved → reviewed. Pure. */
    public static function statusFromEnquiry(int $stav): string
    {
        return $stav === 0 ? 'new' : 'reviewed';
    }

    /** An application status as the status of an enquiry: new → 0, hired or rejected → 2 (resolved), the rest → 1 (read). Pure. */
    public static function statusToEnquiry(string $status): int
    {
        return match (true) {
            $status === 'new' => 0,
            in_array($status, Applications::DECIDED, true) => 2,
            default => 1,
        };
    }

    /**
     * Moves up to BATCH enquiries of one form to applications.
     *
     * @return array{moved: int, left: int, deals: list<int>, contacts_deleted: int}
     */
    public static function toApplications(App $app, string $form, string $source): array
    {
        $db = $app->db();
        $rows = $db->all('SELECT * FROM {poptavky} p WHERE formular = ? AND zdroj = ? AND NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp) ORDER BY idp LIMIT ' . self::BATCH, [$form, $source]);
        $result = self::moveRows($app, $rows);
        $left = (int) $db->value('SELECT COUNT(*) FROM {poptavky} p WHERE formular = ? AND zdroj = ? AND NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp)', [$form, $source]);

        return ['moved' => $result['moved'], 'left' => $left, 'deals' => $result['deals'], 'contacts_deleted' => $result['contacts_deleted']];
    }

    /**
     * Moves one enquiry to applications (3.34, "Move to Applications" on the detail of an enquiry, for a person with both
     * sections) – the same steps as the move by form. Null when the enquiry does not exist (or moved already).
     *
     * @return array{id: int, deals: list<int>, contacts_deleted: int}|null
     */
    public static function enquiryToApplication(App $app, int $idp): ?array
    {
        $rows = $app->db()->all('SELECT * FROM {poptavky} p WHERE idp = ? AND NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp)', [$idp]);
        $result = self::moveRows($app, $rows);

        return $result['ids'] === [] ? null : ['id' => $result['ids'][0], 'deals' => $result['deals'], 'contacts_deleted' => $result['contacts_deleted']];
    }

    /**
     * The move of enquiry rows to applications, each in one transaction (the steps described above the class).
     *
     * @param list<array<string, mixed>> $rows whole rows of ka_poptavky
     * @return array{moved: int, ids: list<int>, deals: list<int>, contacts_deleted: int}
     */
    private static function moveRows(App $app, array $rows): array
    {
        $db = $app->db();
        $now = Clock::now();
        $ids = [];
        $moved = 0;
        $deals = [];
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
                    $file = Applications::OLD_FOLDER . '/' . $path;
                    $files[] = ['field' => count($data) - 1, 'name' => (string) (preg_replace('/ \([^)]*\)$/', '', (string) $field[1]) ?: basename($path)), 'size' => is_file($file) ? (int) filesize($file) : 0,
                        'ext' => $m[1], 'path' => $path, 'sealed' => false];
                }
            }
            $position = Applications::position($db, (string) $e['zdroj'], (string) $e['stranka'], $data);
            $email = trim((string) $e['email']);
            $people = Contacts::fromFields($data, $email);
            $activity = (string) $e['datum'];
            foreach (EnquiryReply::decode($e['replies'] ?? null) as $reply) {
                $activity = $reply['at'] > $activity ? $reply['at'] : $activity;
            }
            $lastComment = $db->value('SELECT MAX(created_at) FROM {comments} WHERE target = ?', ['enquiry:' . $idp]);
            $activity = is_string($lastComment) && $lastComment > $activity ? $lastComment : $activity;
            $db->transaction(function () use ($app, $db, $e, $idp, $data, $files, $position, $email, $people, $activity, $now, &$moved, &$ids, &$deals): void {
                $id = $db->insert('applications', [
                    'received_at' => (string) $e['datum'], 'job_collection_id' => $position['collection'], 'job_item_id' => $position['item'], 'job_title' => $position['title'],
                    'form' => mb_substr((string) $e['formular'], 0, 120), 'source' => (string) $e['zdroj'], 'element' => (string) $e['prvek'], 'page' => (string) $e['stranka'], 'language' => '',
                    'name' => mb_substr($people['name'], 0, 150), 'email' => mb_substr($email, 0, 190), 'email_norm' => PersonalData::normalise($email), 'phone' => mb_substr($people['phone'], 0, 40),
                    'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'attachments' => Applications::encodeAttachments($app->settings(), $files), 'attachment_paths' => Applications::paths($files),
                    'status' => self::statusFromEnquiry((int) $e['stav']), 'status_at' => (string) $e['datum'], 'assignee_id' => $e['prirazeno'] !== null ? (int) $e['prirazeno'] : null,
                    'replies' => $e['replies'], 'late' => $position['late'] ? 1 : 0, 'last_activity_at' => $activity, 'source_enquiry_id' => $idp,
                    'source_contact_id' => $e['contact_id'] !== null ? (int) $e['contact_id'] : null,
                ]);
                $ids[] = $id;
                $target = Applications::target($id);
                foreach (['comments', 'tasks', 'notifications'] as $table) {
                    $db->run('UPDATE {' . $table . '} SET target = ? WHERE target = ?', [$target, 'enquiry:' . $idp]);
                }
                $note = trim((string) ($e['poznamka'] ?? ''));
                foreach (array_filter([$note, (int) $e['stav'] === 2 ? 'Resolved as an enquiry before the move to Applications.' : '']) as $text) {
                    $db->insert('comments', ['target' => $target, 'kind' => 'note', 'author_id' => null, 'author_name' => 'Kaleta', 'text' => mb_substr($text, 0, 5000), 'via' => 'admin', 'created_at' => $now]);
                }
                foreach ($db->all('SELECT id FROM {deals} WHERE source_enquiry_id = ?', [$idp]) as $deal) {
                    $deals[] = (int) $deal['id'];
                }
                Deals::forgetEnquiries($db, [$idp]); // the deal stays, without the link and the person's words
                $db->delete('poptavky', ['idp' => $idp]);
                $moved++;
            });
        }
        Applications::keepToSection($db, $ids); // review low (a): no responsible person or bell entry outside the section
        $removed = self::forgetLoneContacts($db);
        if ($moved > 0) {
            Applications::adoptRetention($db, $app->settings()); // 3.34 review H1: never the default 6 months, and nothing moved is purged before an administrator confirms
            self::record($app, 'to applications', $moved, $deals, $removed);
        }

        return ['moved' => $moved, 'ids' => $ids, 'deals' => array_values(array_unique($deals)), 'contacts_deleted' => $removed];
    }

    /**
     * Moves up to BATCH applications of one form back to enquiries. 3.34 review M2: nothing of an application is lost on the
     * way – each sealed CV is decrypted into storage/prilohy and read back before anything goes; an application whose
     * attachment list cannot be opened (the Vault key) or one of whose files cannot be decrypted, written or verified is
     * skipped and counted (skipped) and stays an application; a transaction that rolls back removes the plain copies it
     * wrote; the application row and its sealed files go only once the enquiry with everything of it is stored.
     *
     * @return array{moved: int, left: int, skipped: int}
     */
    public static function toEnquiries(App $app, string $form, string $source): array
    {
        $db = $app->db();
        $s = $app->settings();
        $now = Clock::now();
        $moved = 0;
        $skipped = 0;
        $after = 0;
        while ($moved < self::BATCH) {
            $rows = $db->all('SELECT * FROM {applications} WHERE form = ? AND source = ? AND id > ? ORDER BY id LIMIT ' . self::BATCH, [$form, $source, $after]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                if ($moved >= self::BATCH) {
                    break 2;
                }
                if (self::moveBack($app, $s, $row, $now)) {
                    $moved++;
                } else {
                    $skipped++;
                }
            }
        }
        $left = (int) $db->value('SELECT COUNT(*) FROM {applications} WHERE form = ? AND source = ?', [$form, $source]);
        if ($moved > 0) {
            self::record($app, 'to enquiries', $moved, [], 0);
        }
        if ($skipped > 0) {
            Events::record($db, 'applications.move_failed', 'warning', mb_substr(sprintf('%d application(s) could not be moved back to Enquiries – their CVs cannot be opened or written; they stay in Applications', $skipped), 0, 255),
                ['skipped' => $skipped, 'direction' => 'to enquiries']);
        }

        return ['moved' => $moved, 'left' => $left, 'skipped' => $skipped];
    }

    /**
     * One application back to an enquiry (toEnquiries); false when it stays an application.
     *
     * @param array<string, mixed> $row a whole row of ka_applications
     */
    private static function moveBack(App $app, Settings $s, array $row, string $now): bool
    {
        $db = $app->db();
        $a = Applications::decode($s, $row);
        $id = $a['id'];
        if (Applications::attachmentsLocked($s, $row['attachments'] ?? null)) {
            return false; // the list of CVs cannot be opened (the encryption key) – moving would lose them
        }
        $data = array_values(array_filter($a['data'], fn (mixed $f): bool => is_array($f) && is_scalar($f[0] ?? null) && is_scalar($f[1] ?? null)));
        $data = array_map(fn (array $f): array => [(string) $f[0], (string) $f[1]], $data);
        $written = [];
        $sealedFiles = [];
        $forget = static function (array $files): void {
            foreach ($files as $file) {
                @unlink($file); // the plain copies of an application that stays
            }
        };
        foreach (Applications::attachments($s, $row['attachments'] ?? null) as $i => $raw) {
            $field = (int) ($raw['field'] ?? -1);
            if (!isset($data[$field])) {
                $data[] = [(string) ($raw['name'] ?? 'CV'), (string) ($raw['name'] ?? '')]; // a file without its field keeps a field of its own
                $field = count($data) - 1;
            }
            if (empty($raw['sealed'])) {
                $data[$field][2] = (string) ($raw['path'] ?? ''); // still in storage/prilohy (moved from an enquiry, not sealed yet)
                continue;
            }
            $file = Applications::attachment($app, $id, (int) $i);
            $extension = preg_match('/^[a-z0-9]{2,5}$/D', (string) ($raw['ext'] ?? '')) === 1 ? (string) $raw['ext'] : 'bin';
            $path = date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
            $target = Applications::OLD_FOLDER . '/' . $path;
            if ($file === null || (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0770, true)) || @file_put_contents($target, $file['content'], LOCK_EX) === false) {
                $forget($written);

                return false;
            }
            $written[] = $target;
            $check = @file_get_contents($target);
            if (!is_string($check) || !hash_equals(hash('sha256', $file['content']), hash('sha256', $check))) {
                $forget($written);

                return false;
            }
            $data[$field][2] = $path;
            $sealedFiles[] = Applications::filePath($raw);
        }
        try {
            $db->transaction(function () use ($db, $a, $id, $data, $now): void {
                $idp = $db->insert('poptavky', [
                    'datum' => (string) $a['received_at'], 'formular' => (string) $a['form'], 'zdroj' => (string) $a['source'], 'prvek' => (string) $a['element'], 'stranka' => (string) $a['page'],
                    'tema' => mb_substr((string) $a['job_title'], 0, 255), 'email' => (string) $a['email'], 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
                    'stav' => self::statusToEnquiry((string) $a['status']), 'kategorie' => 'job', 'triaged_by' => 'rule', 'prirazeno' => $a['assignee_id'],
                    'replies' => $a['replies'] !== [] ? (string) json_encode($a['replies'], JSON_UNESCAPED_UNICODE) : null,
                ]);
                foreach (['comments', 'tasks', 'notifications'] as $table) {
                    $db->run('UPDATE {' . $table . '} SET target = ? WHERE target = ?', [Comments::enquiry($idp), Applications::target($id)]);
                }
                $db->insert('comments', ['target' => Comments::enquiry($idp), 'kind' => 'note', 'author_id' => null, 'author_name' => 'Kaleta', 'via' => 'admin', 'created_at' => $now,
                    'text' => 'Moved back from Applications (application #' . $id . ', status ' . $a['status'] . ($a['job_title'] !== '' ? ', position ' . mb_substr((string) $a['job_title'], 0, 200) : '') . ').']);
                if ($db->run('DELETE FROM {applications} WHERE id = ?', [$id])->rowCount() !== 1) {
                    throw new \RuntimeException('The application changed meanwhile.');
                }
            });
        } catch (\Throwable) {
            $forget($written);

            return false;
        }
        foreach ($sealedFiles as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path); // the sealed copy – the enquiry has its own now
            }
        }

        return true;
    }

    /**
     * Deletes the customer records only moved enquiries made – from the data (3.34 review M3), the rule of migration 0120: an
     * application keeps the record its enquiry had (source_contact_id); a record whose only source rows are such
     * applications – source enquiry, never edited (updated_at = created_at), no other enquiry, booking, subscription, logged
     * activity (a merge logs one), deal, comment or task, no summary, no responsible person – goes. Every record looked at
     * loses the link, so a real customer who also applied is looked at once and kept. Returns how many went.
     */
    public static function forgetLoneContacts(Db $db): int
    {
        $removed = 0;
        foreach ($db->all('SELECT DISTINCT source_contact_id AS id FROM {applications} WHERE source_contact_id IS NOT NULL LIMIT 1000') as $r) {
            $contactId = (int) $r['id'];
            $lone = $db->value("SELECT 1 FROM {contacts} c WHERE c.id = ? AND c.source = 'enquiry' AND (c.summary IS NULL OR c.summary = '') AND c.owner_id IS NULL AND c.updated_at <= c.created_at
                AND NOT EXISTS (SELECT 1 FROM {poptavky} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {bookings} WHERE contact_id = c.id)
                AND NOT EXISTS (SELECT 1 FROM {odberatele} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {contact_activities} WHERE contact_id = c.id)
                AND NOT EXISTS (SELECT 1 FROM {deals} WHERE contact_id = c.id) AND NOT EXISTS (SELECT 1 FROM {comments} WHERE target = CONCAT('contact:', c.id))
                AND NOT EXISTS (SELECT 1 FROM {tasks} WHERE target = CONCAT('contact:', c.id))", [$contactId]);
            $db->transaction(function (Db $db) use ($contactId, $lone, &$removed): void {
                if ($lone !== null) {
                    $db->run('DELETE FROM {notifications} WHERE target = ?', ['contact:' . $contactId]);
                    $removed += $db->run('DELETE FROM {contacts} WHERE id = ?', [$contactId])->rowCount();
                }
                $db->run('UPDATE {applications} SET source_contact_id = NULL WHERE source_contact_id = ?', [$contactId]);
            });
        }

        return $removed;
    }

    /**
     * The change log and the event applications.moved – counts and deal numbers, never a person.
     *
     * @param list<int> $deals
     */
    private static function record(App $app, string $direction, int $moved, array $deals, int $contacts): void
    {
        $text = sprintf('%d moved %s', $moved, $direction) . ($deals !== [] ? '; deals that lost their link: #' . implode(', #', array_unique($deals)) : '')
            . ($contacts > 0 ? '; customer records made only by them deleted: ' . $contacts : '');
        ChangeLog::write($app, Applications::SECTION, 'move', mb_substr($text, 0, 255));
        Events::record($app->db(), 'applications.moved', 'info', mb_substr($text, 0, 255), ['moved' => $moved, 'direction' => $direction, 'deals' => array_values(array_unique($deals)), 'contacts_deleted' => $contacts]);
    }
}
