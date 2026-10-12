<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;
use Kaleta\Builder\Elements\Form;

/**
 * Whistleblowing channel (2.14): a template that helps a company of 50+ employees meet the EU Whistleblower Directive
 * (2019/1937) and the national laws built on it – never legal advice. The company names the person who handles reports
 * and checks its national rules; the site gives them the channel.
 *
 *  - A public form at /_report (Front\Whistleblowing): the report text, an optional name and contact (anonymous is fine),
 *    optional attachments. No IP address, no statistics, no cookies, no third-party scripts on that page – the site's
 *    CAPTCHA is never shown there (3.3.3): its provider would learn the reporter's address and browser.
 *  - The reporter gets a case number (2026-0007) and a random access code shown once; only a hash of the code is stored.
 *    With both they follow the case at /_report/follow and add information. Wrong codes are rate-limited per address
 *    (ka_kontrola_ip, never in the case tables) – since 3.3.2 by addressBucket(): a keyed hash with a daily salt, so
 *    short that thousands of addresses share it, kept for one day at most.
 *  - Floods (3.3.2, 3.3.3): REPORTS_PER_DAY from one address bucket is the only refusal, a kind "try again later" that
 *    names no reason tied to the reporter. Beyond REPORTS_PER_HOUR for the whole channel a report is still accepted –
 *    a script must not shut genuine reporters out – but marked as received during a flood (column flood), so the
 *    readers see which cases arrived among the mass. Above MAX_STORAGE for all attachments together a report is
 *    accepted without its attachments, and the reporter is told so. The checks and the insert run under one lock
 *    (receive), so parallel requests cannot slip past them.
 *  - The text, the contact, the attachment list and every message are encrypted with sodium (secretbox) under a key
 *    derived from the site's secret – its own derivation, so the connectors' key never opens a report and vice versa.
 *  - Only the chosen readers (setting whistleblowing_readers) open a case; other administrators see case numbers, dates and
 *    statuses only. The e-mail about a new case names the case number, never the content.
 *  - Deadlines of the directive: acknowledgement within ACKNOWLEDGE_DAYS, feedback within FEEDBACK_MONTHS. The daily job
 *    records an event for every case whose deadline is due, and deletes closed cases after the retention period.
 *  - Deliberately NOT over MCP: no tool reads, lists or writes cases (Mcp\Tools). Claude only learns from site_info whether
 *    the channel is on. The tables are not in the site export (Core\SiteExport) – a report is not content of the site.
 *  - Attachments live in storage/oznameni/ (not reachable from the web), separate from the enquiry attachments.
 */
final class Whistleblowing
{
    /** Status => label (admin texts, translated with t()). */
    public const array STATUSES = ['received' => 'Received', 'acknowledged' => 'Acknowledged', 'in_progress' => 'In progress', 'closed' => 'Case closed'];

    public const int ACKNOWLEDGE_DAYS = 7;
    public const int FEEDBACK_MONTHS = 3;
    public const int DEFAULT_RETENTION_MONTHS = 24;
    public const int CODE_LENGTH = 20;
    public const int MAX_TEXT = 20000;
    public const int MAX_MESSAGE = 10000;
    public const int MAX_ATTACHMENTS = 5;
    /** Wrong codes from one address per hour before the follow-up form refuses to check more. */
    public const int WRONG_CODES_PER_HOUR = 10;
    public const string FOLDER = KALETA_ROOT . '/storage/oznameni';
    /**
     * Reports in one hour on the whole channel (3.3.2) after which new ones are marked as received during a flood (3.3.3):
     * still accepted – a script must not shut genuine reporters out – but easy for the readers to tell apart.
     */
    public const int REPORTS_PER_HOUR = 20;
    /** Reports from one address bucket (addressBucket) in one day. */
    public const int REPORTS_PER_DAY = 5;
    /** All stored attachments together, in bytes; above it new reports are accepted without attachments. */
    public const int MAX_STORAGE = 1024 * 1048576;
    /** Hex characters kept of the address hash: about a million buckets, so one bucket stands for thousands of IPv4 addresses. */
    private const int BUCKET_LENGTH = 5;
    /** ka_kontrola_ip.typ of a wrong follow-up code and of a sent report. */
    private const string WRONG_CODE = 'oznameni';
    private const string SENT = 'oznameni-den';

    /** Letters and digits that are not confused with each other (no 0/O, 1/I/L); codes are checked without case and separators. */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /* ---------- setup ---------- */

    /** The channel is open: the Whistleblowing feature is on (3.2, Core\Extensions) and the administrator opened the channel in its setup. */
    public static function isOn(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, 'whistleblowing') && $settings->bool('whistleblowing_enabled');
    }

    /** @return list<int> ids of the users who may read reports */
    public static function readerIds(Settings $settings): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $settings->get('whistleblowing_readers'))), fn (int $id): bool => $id > 0));
    }

    /** The current user may open reports. An administrator who is not on the list may not – that is the point of the list. */
    public static function isReader(App $app): bool
    {
        return $app->auth()->id() > 0 && in_array($app->auth()->id(), self::readerIds($app->settings()), true);
    }

    /** @return list<array{idu: int, name: string, email: string}> the active readers */
    public static function readers(Db $db, Settings $settings): array
    {
        $ids = self::readerIds($settings);
        if ($ids === []) {
            return [];
        }

        return array_map(fn (array $r): array => ['idu' => (int) $r['idu'], 'name' => (string) ($r['jmeno'] !== '' ? $r['jmeno'] : $r['user']), 'email' => (string) $r['email']],
            $db->all('SELECT idu, user, jmeno, email FROM {uzivatele} WHERE blokovat = 0 AND idu IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ' . $db->sortText('jmeno') . ', user', $ids));
    }

    /**
     * Saves the setup (administrators only – the module checks). Readers that are not administrators get the module
     * permission, so they can open it; readers taken off the list lose it again.
     *
     * @param list<int> $readers
     */
    public static function saveSetup(App $app, bool $on, array $readers, string $intro, int $retentionMonths): void
    {
        $db = $app->db();
        $s = $app->settings();
        $readers = array_values(array_unique(array_filter(array_map('intval', $readers), fn (int $id): bool => $id > 0)));
        $valid = $readers === [] ? [] : array_map('intval', array_column($db->all('SELECT idu FROM {uzivatele} WHERE blokovat = 0 AND idu IN (' . implode(',', array_fill(0, count($readers), '?')) . ')', $readers), 'idu'));
        $before = self::readerIds($s);
        $s->set('whistleblowing_enabled', $on ? '1' : '0');
        $s->set('whistleblowing_readers', implode(',', $valid));
        $s->set('whistleblowing_intro', mb_substr(trim($intro), 0, 5000));
        $s->set('whistleblowing_retention_months', (string) max(1, min(120, $retentionMonths)));
        foreach (array_diff($before, $valid) as $gone) {
            $db->delete('uzivatele_prava', ['fk_id_user' => $gone, 'ident_modulu' => 'whistleblowing']);
        }
        foreach ($valid as $id) {
            $db->run('INSERT IGNORE INTO {uzivatele_prava} (fk_id_user, ident_modulu) VALUES (?, ?)', [$id, 'whistleblowing']);
        }
        ChangeLog::write($app, 'whistleblowing', 'setup', ($on ? 'on' : 'off') . ', readers: ' . implode(', ', $valid));
    }

    /* ---------- encryption (the same scheme as Core\Connectors, its own key) ---------- */

    private static function vaultKey(Settings $settings): string
    {
        return sodium_crypto_generichash('kaleta-whistleblowing|' . (new Antispam($settings->db(), $settings))->key(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(Settings $settings, string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::vaultKey($settings)));
    }

    /** null when it cannot be read (another site's key, damaged). */
    public static function decrypt(Settings $settings, ?string $sealed): ?string
    {
        $raw = $sealed !== null ? base64_decode($sealed, true) : false;
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::vaultKey($settings));

        return $plain === false ? null : $plain;
    }

    /* ---------- numbers, codes, deadlines (pure, unit-tested) ---------- */

    /** The case number: the year and a sequence within it, "2026-0007". */
    public static function number(int $year, int $sequence): string
    {
        return sprintf('%04d-%04d', $year, $sequence);
    }

    public static function isNumber(string $number): bool
    {
        return preg_match('/^\d{4}-\d{4,6}$/D', $number) === 1;
    }

    private static function nextNumber(Db $db): string
    {
        $year = (int) date('Y');
        $sequence = (int) $db->value('SELECT COUNT(*) FROM {whistleblowing_cases} WHERE number LIKE ?', [$year . '-%']) + 1;
        while ($db->value('SELECT 1 FROM {whistleblowing_cases} WHERE number = ?', [self::number($year, $sequence)]) !== null) {
            $sequence++; // a deleted case left a gap that the count does not see
        }

        return self::number($year, $sequence);
    }

    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($code, 5)); // groups of five are easier to write down
    }

    /** Typed codes are accepted in any case and with or without separators. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $code));
    }

    /** Only this hash is stored; it is bound to the case number, so a code never opens another case. */
    public static function codeHash(string $number, string $code): string
    {
        return hash('sha256', 'kaleta-whistleblowing|' . $number . '|' . self::normalizeCode($code));
    }

    /** @return array{acknowledge_by: string, feedback_due: string} the deadlines of the directive from the moment of receipt */
    public static function deadlines(string $createdAt): array
    {
        $created = new \DateTimeImmutable($createdAt);

        return ['acknowledge_by' => $created->modify('+' . self::ACKNOWLEDGE_DAYS . ' days')->format('Y-m-d H:i:s'), 'feedback_due' => $created->modify('+' . self::FEEDBACK_MONTHS . ' months')->format('Y-m-d H:i:s')];
    }

    /**
     * Which deadline of a case has passed: the acknowledgement (no acknowledgement yet and ACKNOWLEDGE_DAYS gone) and
     * the feedback (not closed and feedback_due gone).
     *
     * @param array<string, mixed> $case row of ka_whistleblowing_cases
     * @return array{acknowledgement: bool, feedback: bool}
     */
    public static function overdue(array $case, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');

        return [
            'acknowledgement' => $case['acknowledged_at'] === null && $case['status'] !== 'closed' && self::deadlines((string) $case['created_at'])['acknowledge_by'] < $now,
            'feedback' => $case['status'] !== 'closed' && (string) $case['feedback_due'] < $now,
        ];
    }

    /* ---------- the reporter's side ---------- */

    /**
     * A report from the public form (3.3.3): the daily limit of the address bucket, the flood mark and the storage cap are
     * checked and the case is stored under one database lock, so parallel requests cannot all pass the checks first.
     * Returns null when the address bucket has sent its REPORTS_PER_DAY today (the form asks to try again later).
     *
     * @param array<string, mixed>|null $files
     * @return array{number: string, code: string, flood: bool, without_attachments: bool}|string|null
     */
    public static function receive(App $app, string $text, string $name, string $contact, ?array $files): array|string|null
    {
        $db = $app->db();
        // per database and table prefix, so two sites on one MySQL server never wait for each other; when the lock cannot be
        // had in time the report goes through anyway – a genuine reporter is never refused for it
        $lock = substr('kaleta-wb-' . hash('sha256', (string) $db->value('SELECT DATABASE()') . '|' . $db->sql('{whistleblowing_cases}')), 0, 64);
        $locked = (int) $db->value('SELECT GET_LOCK(?, 10)', [$lock]) === 1;
        try {
            return self::acceptsReport($app) ? self::submit($app, $text, $name, $contact, $files) : null;
        } finally {
            if ($locked) {
                $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    /**
     * Stores a report and returns the case number and the access code (shown once). Attachments are the $_FILES entry of
     * a multiple file input (name[], tmp_name[]…); a file outside the enquiry rules (type, size) refuses the whole report.
     * Above MAX_STORAGE the report is stored without its attachments (without_attachments), and beyond REPORTS_PER_HOUR
     * on the channel it is marked as received during a flood (3.3.3). The public form calls receive(), which holds the lock.
     *
     * @param array<string, mixed>|null $files
     * @return array{number: string, code: string, flood: bool, without_attachments: bool}|string the case, or the reason it was refused (already translated)
     */
    public static function submit(App $app, string $text, string $name, string $contact, ?array $files): array|string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return t('Please describe what happened.');
        }
        $uploads = [];
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $extension = strtolower(pathinfo((string) $files['name'][$i], PATHINFO_EXTENSION));
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $files['tmp_name'][$i]) || !in_array($extension, Form::ATTACHMENT_EXTENSIONS, true)
                || (int) $files['size'][$i] > Form::MAX_ATTACHMENT || count($uploads) >= self::MAX_ATTACHMENTS) {
                return t('A file could not be accepted: up to %d files, each up to %d MB – PDF, image, document or ZIP.', self::MAX_ATTACHMENTS, (int) (Form::MAX_ATTACHMENT / 1048576));
            }
            $uploads[] = [(string) $files['tmp_name'][$i], $extension, mb_substr(basename((string) $files['name'][$i]), 0, 120), (int) $files['size'][$i]];
        }
        // over the storage cap the report still goes through, only without the attachments – the reporter is told so (3.3.3, N57)
        $withoutAttachments = $uploads !== [] && self::storedBytes() + array_sum(array_column($uploads, 3)) > self::MAX_STORAGE;
        if ($withoutAttachments) {
            $uploads = [];
        }
        $db = $app->db();
        $s = $app->settings();
        $flood = self::isFlood($db);
        $attachments = [];
        foreach ($uploads as [$tmp, $extension, $original, $size]) {
            $path = date('Y') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
            $target = self::FOLDER . '/' . $path;
            if ((is_dir(dirname($target)) || mkdir(dirname($target), 0775, true)) && move_uploaded_file($tmp, $target)) {
                $attachments[] = ['name' => $original, 'path' => $path, 'size' => $size];
            }
        }
        $code = self::newCode();
        $now = date('Y-m-d H:i:s');
        $contactText = trim($name) !== '' || trim($contact) !== '' ? trim(mb_substr(trim($name), 0, 200) . "\n" . mb_substr(trim($contact), 0, 500)) : '';
        $number = $db->transaction(function () use ($db, $s, $text, $contactText, $attachments, $code, $now, $flood): string {
            $number = self::nextNumber($db);
            $db->insert('whistleblowing_cases', [
                'number' => $number, 'created_at' => $now, 'status' => 'received', 'acknowledged_at' => null, 'closed_at' => null, 'flood' => $flood ? 1 : 0,
                'feedback_due' => self::deadlines($now)['feedback_due'],
                'text' => self::encrypt($s, mb_substr($text, 0, self::MAX_TEXT)),
                'contact' => $contactText === '' ? null : self::encrypt($s, $contactText),
                'attachments' => $attachments === [] ? null : self::encrypt($s, (string) json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'code_hash' => self::codeHash($number, $code),
            ]);

            return $number;
        });
        self::remember($app, self::SENT, date('Y-m-d 00:00:00')); // the day only – the row cannot be matched to the case by time
        // the event and the e-mail name the case, never what it says
        Events::record($db, 'whistleblowing.received', 'info', t('A new report arrived in the whistleblowing channel, case %s.', $number), ['number' => $number] + ($flood ? ['flood' => true] : []));
        // during a flood the readers get one e-mail, for the first marked case of the hour – not one for every scripted report
        if (!$flood || (int) $db->value('SELECT COUNT(*) FROM {whistleblowing_cases} WHERE flood = 1 AND created_at > ?', [date('Y-m-d H:i:s', time() - 3600)]) === 1) {
            self::notifyReaders($app, $number, $flood);
        }

        return ['number' => $number, 'code' => $code, 'flood' => $flood, 'without_attachments' => $withoutAttachments];
    }

    /** REPORTS_PER_HOUR or more reports arrived on the whole channel in the last hour: a new one is marked (3.3.3, N57). */
    public static function isFlood(Db $db): bool
    {
        return (int) $db->value('SELECT COUNT(*) FROM {whistleblowing_cases} WHERE created_at > ?', [date('Y-m-d H:i:s', time() - 3600)]) >= self::REPORTS_PER_HOUR;
    }

    /** "A new report arrived, case 2026-0007" to every reader with an e-mail address – and nothing else. */
    private static function notifyReaders(App $app, string $number, bool $flood = false): void
    {
        $s = $app->settings();
        $url = rtrim($s->get('site_url') ?: $app->request->origin(), '/') . $app->url('admin.php?module=whistleblowing');
        foreach (self::readers($app->db(), $s) as $reader) {
            if ($reader['email'] === '') {
                continue;
            }
            Mail::send($s, $reader['email'], t('New report in the whistleblowing channel – case %s', $number),
                t('A new report arrived in the whistleblowing channel, case %s.', $number) . "\n\n"
                . ($flood ? t('Many reports are arriving at once. This case and the ones after it this hour are marked as received during a flood – no further e-mail is sent for them.') . "\n\n" : '')
                . t('Acknowledge it within %d days and give feedback within %d months.', self::ACKNOWLEDGE_DAYS, self::FEEDBACK_MONTHS) . "\n" . $url . "\n");
        }
    }

    /**
     * The address bucket may send another report today (3.3.2): fewer than REPORTS_PER_DAY. The hourly limit of the whole
     * channel no longer refuses anyone – it marks the report instead (isFlood, 3.3.3).
     */
    public static function acceptsReport(App $app): bool
    {
        $db = $app->db();

        return (int) $db->value('SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = ? AND ip_adresa = ? AND cas >= ?', [self::SENT, self::addressBucket($app), date('Y-m-d 00:00:00')]) < self::REPORTS_PER_DAY;
    }

    /** The follow-up form from one address bucket checked too many wrong codes in the last hour. */
    public static function tooManyAttempts(App $app): bool
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = ? AND ip_adresa = ? AND cas > ?',
            [self::WRONG_CODE, self::addressBucket($app), date('Y-m-d H:i:s', time() - 3600)]) >= self::WRONG_CODES_PER_HOUR;
    }

    /**
     * What the channel keeps of a reporter's address (3.3.2, N35): an HMAC under the site's secret with the day as salt,
     * cut to BUCKET_LENGTH hex characters – enough to count tries, too short to name an address even for someone with
     * the database and the key (thousands of IPv4 addresses, or IPv6 networks, share each value), and a new value every
     * day. IPv6 counts by its /64, and behind Cloudflare the visitor's own address counts, not the proxy's (Firewall::visitorKey,
     * 3.3.3) – otherwise every reporter coming through one edge would share a bucket.
     */
    public static function addressBucket(App $app, ?string $day = null): string
    {
        $key = (new Antispam($app->db(), $app->settings()))->key();

        return 'wb:' . substr(hash_hmac('sha256', 'whistleblowing|' . ($day ?? date('Y-m-d')) . '|' . Firewall::visitorKey($app->request, $app->settings()), $key), 0, self::BUCKET_LENGTH);
    }

    /** One row for the address bucket, and every row of the channel older than a day is forgotten. */
    private static function remember(App $app, string $type, string $time): void
    {
        $app->db()->insert('kontrola_ip', ['ip_adresa' => self::addressBucket($app), 'typ' => $type, 'cil' => 0, 'cas' => $time]);
        self::forgetAddresses($app->db());
    }

    /** The channel keeps its address rows for one day at most (also run by the daily job). */
    public static function forgetAddresses(Db $db): void
    {
        $db->run('DELETE FROM {kontrola_ip} WHERE typ IN (?, ?) AND cas < ?', [self::WRONG_CODE, self::SENT, date('Y-m-d H:i:s', time() - 86400)]);
    }

    /** The size of every stored attachment together, in bytes. */
    private static function storedBytes(): int
    {
        if (!is_dir(self::FOLDER)) {
            return 0;
        }
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::FOLDER, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $total += $file instanceof \SplFileInfo && $file->isFile() ? (int) $file->getSize() : 0;
        }

        return $total;
    }

    /**
     * The case for a number and a code, or null. A wrong pair counts against the address bucket (addressBucket); the
     * case tables never see an address.
     *
     * @return array<string, mixed>|null
     */
    public static function open(App $app, string $number, string $code): ?array
    {
        $case = self::isNumber($number) && self::normalizeCode($code) !== '' ? $app->db()->one('SELECT * FROM {whistleblowing_cases} WHERE number = ?', [$number]) : null;
        if ($case === null || !hash_equals((string) $case['code_hash'], self::codeHash($number, $code))) {
            self::remember($app, self::WRONG_CODE, date('Y-m-d H:i:s'));

            return null;
        }

        return $case;
    }

    /* ---------- reading a case (readers and the reporter with the code) ---------- */

    /**
     * @param array<string, mixed> $case
     * @return array{text: string, contact: string, attachments: list<array{name: string, path: string, size: int}>}
     */
    public static function contents(Settings $settings, array $case): array
    {
        $attachments = json_decode(self::decrypt($settings, $case['attachments'] ?? null) ?? '[]', true);

        return ['text' => self::decrypt($settings, (string) $case['text']) ?? '', 'contact' => self::decrypt($settings, $case['contact'] ?? null) ?? '',
            'attachments' => is_array($attachments) ? array_values(array_filter($attachments, fn (mixed $a): bool => is_array($a) && is_string($a['path'] ?? null) && is_string($a['name'] ?? null))) : []];
    }

    /** @return list<array{from: string, text: string, created_at: string}> oldest first */
    public static function messages(App $app, int $caseId): array
    {
        return array_map(fn (array $m): array => ['from' => (string) $m['sender'], 'text' => self::decrypt($app->settings(), (string) $m['text']) ?? '', 'created_at' => (string) $m['created_at']],
            $app->db()->all('SELECT sender, text, created_at FROM {whistleblowing_messages} WHERE case_id = ? ORDER BY id', [$caseId]));
    }

    /** @param 'reporter'|'handler' $from */
    public static function addMessage(App $app, int $caseId, string $from, string $text): bool
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '' || !in_array($from, ['reporter', 'handler'], true)) {
            return false;
        }
        $app->db()->insert('whistleblowing_messages', ['case_id' => $caseId, 'sender' => $from, 'text' => self::encrypt($app->settings(), mb_substr($text, 0, self::MAX_MESSAGE)), 'created_at' => date('Y-m-d H:i:s')]);
        if ($from === 'handler') {
            // the first answer of the handler is the acknowledgement of receipt
            $app->db()->run("UPDATE {whistleblowing_cases} SET acknowledged_at = COALESCE(acknowledged_at, ?), status = IF(status = 'received', 'acknowledged', status) WHERE id = ?", [Clock::now(), $caseId]);
        }

        return true;
    }

    public static function setStatus(App $app, int $caseId, string $status): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }
        $data = ['status' => $status, 'closed_at' => $status === 'closed' ? date('Y-m-d H:i:s') : null];
        $app->db()->update('whistleblowing_cases', $data, ['id' => $caseId]);
        if ($status !== 'received') {
            $app->db()->run('UPDATE {whistleblowing_cases} SET acknowledged_at = COALESCE(acknowledged_at, ?) WHERE id = ?', [Clock::now(), $caseId]);
        }
        $number = (string) $app->db()->value('SELECT number FROM {whistleblowing_cases} WHERE id = ?', [$caseId]);
        ChangeLog::write($app, 'whistleblowing', 'status', $number . ': ' . $status); // the number and the status only

        return true;
    }

    /** The file of an attachment by its index, or null (the stored path is validated – nothing outside the folder). */
    public static function attachmentPath(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');

        return preg_match('#^\d{4}/[a-f0-9]{32}\.[a-z0-9]{2,5}$#D', $path) && is_file(self::FOLDER . '/' . $path) ? self::FOLDER . '/' . $path : null;
    }

    /* ---------- the daily job: reminders and retention ---------- */

    /** Scheduler job 'whistleblowing': an event for every due deadline, and closed cases past the retention period deleted. */
    public static function run(App $app): string
    {
        $db = $app->db();
        $s = $app->settings();
        $reminded = 0;
        $tomorrow = date('Y-m-d H:i:s', time() + 86400);
        foreach ($db->all("SELECT * FROM {whistleblowing_cases} WHERE status <> 'closed'") as $case) {
            $deadlines = self::deadlines((string) $case['created_at']);
            if ($case['acknowledged_at'] === null && $deadlines['acknowledge_by'] <= $tomorrow) {
                Events::record($db, 'whistleblowing.due', 'warning', t('Case %s: the acknowledgement of receipt is due by %s.', (string) $case['number'], format_date($deadlines['acknowledge_by'])), ['number' => (string) $case['number'], 'deadline' => 'acknowledgement']);
                $reminded++;
            } elseif ((string) $case['feedback_due'] <= $tomorrow) {
                Events::record($db, 'whistleblowing.due', 'warning', t('Case %s: the feedback to the reporter is due by %s.', (string) $case['number'], format_date((string) $case['feedback_due'])), ['number' => (string) $case['number'], 'deadline' => 'feedback']);
                $reminded++;
            }
        }
        self::forgetAddresses($db);
        $months = $s->int('whistleblowing_retention_months') ?: self::DEFAULT_RETENTION_MONTHS;
        $purged = 0;
        foreach ($db->all("SELECT * FROM {whistleblowing_cases} WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < ?", [Clock::monthsAgo($months)]) as $case) {
            self::delete($app, $case);
            $purged++;
        }
        if ($purged > 0) {
            Events::record($db, 'whistleblowing.purged', 'info', t('%d closed whistleblowing cases past the retention period of %d months were deleted.', $purged, $months), ['count' => $purged, 'months' => $months]);
        }

        return 'reminded ' . $reminded . ', purged ' . $purged;
    }

    /** @param array<string, mixed> $case */
    public static function delete(App $app, array $case): void
    {
        foreach (self::contents($app->settings(), $case)['attachments'] as $attachment) {
            $path = self::attachmentPath($attachment);
            if ($path !== null) {
                @unlink($path);
            }
        }
        $app->db()->delete('whistleblowing_messages', ['case_id' => (int) $case['id']]);
        $app->db()->delete('whistleblowing_cases', ['id' => (int) $case['id']]);
    }
}
