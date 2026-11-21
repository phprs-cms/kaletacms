<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Saved replies of the firm (3.49 A, ka_saved_replies, migration 0137): texts a person picks when replying to an enquiry,
 * per language version of the site ('' = the default language). Administrators and editors write them (Enquiries → Saved
 * replies, Auth::canPublish); everyone with the Enquiries section uses them; Claude reads them with get_email_signature
 * and never writes them. Placeholders {{name}} (who wrote the enquiry), {{topic}} (what it was about) and {{company}}
 * (Business details, else the site name) are filled by ReplyMail::fill – EnquiryReply::send fills them again for every
 * sender, so a placeholder never reaches the customer. There are no built-in texts. Not in the site export (D13); backups
 * carry the table. category is the seam of a later Support tab: always '' here.
 */
final class SavedReplies
{
    public const int MAX_PER_LANGUAGE = 30;
    public const int NAME_MAX = 80;
    public const int SUBJECT_MAX = 200;
    public const int TEXT_MAX = 5000;

    /**
     * Every saved reply by language ('' = the default one), each list by its order.
     *
     * @return array<string, list<array{id: int, language: string, name: string, subject: string, text: string}>>
     */
    public static function all(Db $db): array
    {
        try {
            $rows = $db->all("SELECT id, language, name, subject, text FROM {saved_replies} WHERE category = '' ORDER BY language, sort_order, id");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return []; // before migration 0137
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['language']][] = ['id' => (int) $r['id'], 'language' => (string) $r['language'], 'name' => (string) $r['name'], 'subject' => (string) $r['subject'], 'text' => (string) $r['text']];
        }

        return $out;
    }

    /** How many saved replies there are (0 before migration 0137). */
    public static function count(Db $db): int
    {
        try {
            return (int) $db->value("SELECT COUNT(*) FROM {saved_replies} WHERE category = ''");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return 0;
        }
    }

    /**
     * The language of an enquiry as saved replies store it (finding 4 – an enquiry has no language column): the language
     * prefix of the page it was sent from when it is an additional language of the site, else '' (the default language).
     *
     * @param array<string, mixed> $enquiry a row of ka_poptavky (stranka)
     */
    public static function languageOf(Settings $s, array $enquiry): string
    {
        $code = Language::splitPrefix((string) ($enquiry['stranka'] ?? ''))[0] ?? '';

        return $code !== '' && $code !== Language::defaults($s) && in_array($code, Language::additional($s), true) ? $code : '';
    }

    /**
     * The choices for one enquiry: the replies in its language first, then the others – ids and names only.
     *
     * @param array<string, mixed> $enquiry
     * @return list<array{id: int, name: string, language: string}>
     */
    public static function forEnquiry(Db $db, Settings $s, array $enquiry): array
    {
        $all = self::all($db);
        $first = self::languageOf($s, $enquiry);
        $out = [];
        foreach ([$first => $all[$first] ?? []] + $all as $rows) {
            foreach ($rows as $r) {
                $out[] = ['id' => $r['id'], 'name' => $r['name'], 'language' => $r['language']];
            }
        }

        return $out;
    }

    /**
     * The values of the placeholders for one enquiry: name – who wrote it (from the enquiry itself, so it works with
     * Customers off; the linked customer's name only when the enquiry names nobody and Customers is on), topic – what it was
     * about, company – Business details, else the site name.
     *
     * @param array<string, mixed> $enquiry a row of ka_poptavky
     * @return array{name: string, topic: string, company: string}
     */
    public static function vars(App $app, array $enquiry): array
    {
        $s = $app->settings();
        $name = ($enquiry['anonymizovano'] ?? null) === null ? RecordTitle::enquiryPeople($enquiry)['name'] : '';
        if ($name === '' && (int) ($enquiry['contact_id'] ?? 0) > 0 && ($enquiry['anonymizovano'] ?? null) === null && Extensions::isEnabled($s, 'customers')) {
            $name = trim((string) ($app->db()->value('SELECT name FROM {contacts} WHERE id = ? AND anonymised_at IS NULL', [(int) $enquiry['contact_id']]) ?? ''));
        }
        $topic = trim((string) preg_replace('/\s+/u', ' ', (string) ($enquiry['tema'] ?? '')));

        return ['name' => mb_substr($name, 0, 80), 'topic' => mb_substr($topic, 0, 100), 'company' => $s->get('company_name') !== '' ? $s->get('company_name') : $s->get('site_name')];
    }

    /**
     * One saved reply filled for an enquiry: the subject ('' = the reply's default subject) and the text; null when it does
     * not exist.
     *
     * @param array<string, mixed> $enquiry
     * @return array{subject: string, text: string}|null
     */
    public static function compose(App $app, array $enquiry, int $id): ?array
    {
        try {
            $row = $id > 0 ? $app->db()->one("SELECT subject, text FROM {saved_replies} WHERE id = ? AND category = ''", [$id]) : null;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            $row = null;
        }
        if ($row === null) {
            return null;
        }
        $vars = self::vars($app, $enquiry);
        $subject = ReplyMail::fill((string) $row['subject'], $vars);

        return ['subject' => $subject !== '' ? $subject : EnquiryReply::subject($enquiry), 'text' => ReplyMail::fill((string) $row['text'], $vars)];
    }

    /**
     * The rows of a form cleaned (pure): name, subject and text trimmed to their limits, a subject on one line; a row with
     * remove ticked, or with neither a name nor a text, is left out. Returns the rows or the English refusal.
     *
     * @param list<array<string, mixed>> $rows {id?, name, subject, text, remove?}
     * @return list<array{id: int, name: string, subject: string, text: string}>|string
     */
    public static function clean(array $rows): array|string
    {
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['remove'])) {
                continue;
            }
            $name = trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($r['name'] ?? '')), 0, self::NAME_MAX));
            $subject = trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($r['subject'] ?? '')), 0, self::SUBJECT_MAX));
            $text = trim(mb_substr(str_replace("\r\n", "\n", (string) ($r['text'] ?? '')), 0, self::TEXT_MAX));
            if ($name === '' && $text === '') {
                continue;
            }
            if ($name === '' || $text === '') {
                return 'Every saved reply needs a name and a text.';
            }
            $out[] = ['id' => max(0, (int) ($r['id'] ?? 0)), 'name' => $name, 'subject' => $subject, 'text' => $text];
        }
        if (count($out) > self::MAX_PER_LANGUAGE) {
            return t('At most %d saved replies per language.', self::MAX_PER_LANGUAGE);
        }

        return $out;
    }

    /**
     * Replaces the saved replies of one language with the rows of the form (clean()): a row with the id of one of them
     * changes it (the id stays the handle), a new row is added, the ones left out are deleted. Returns the saved rows or the
     * English refusal (translated by the caller).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, subject: string, text: string}>|string
     */
    public static function save(App $app, string $language, array $rows): array|string
    {
        $s = $app->settings();
        if ($language !== '' && !in_array($language, Language::additional($s), true)) {
            return 'This language version is not switched on.';
        }
        $clean = self::clean($rows);
        if (is_string($clean)) {
            return $clean;
        }
        $me = $app->auth()->id();
        $now = Clock::now();

        return $app->db()->transaction(function (Db $db) use ($clean, $language, $me, $now): array {
            $own = array_map('intval', array_column($db->all("SELECT id FROM {saved_replies} WHERE language = ? AND category = '' FOR UPDATE", [$language]), 'id'));
            $kept = [];
            foreach ($clean as $i => $r) {
                $values = ['name' => $r['name'], 'subject' => $r['subject'], 'text' => $r['text'], 'sort_order' => $i, 'updated_by' => $me > 0 ? $me : null, 'updated_at' => $now];
                if ($r['id'] > 0 && in_array($r['id'], $own, true)) {
                    $db->update('saved_replies', $values, ['id' => $r['id']]);
                } else {
                    $r['id'] = (int) $db->insert('saved_replies', $values + ['language' => $language, 'category' => '']);
                }
                $kept[] = $r['id'];
                $clean[$i] = $r;
            }
            $gone = array_diff($own, $kept);
            if ($gone !== []) {
                $db->run('DELETE FROM {saved_replies} WHERE id IN (' . implode(',', array_map('intval', $gone)) . ')');
            }

            return $clean;
        });
    }

    /**
     * The saved replies for Claude (get_email_signature, 3.49 A): full rows {name, language, subject, text} while their JSON
     * stays under $budget bytes, the rest as {name, language, more: true}. Languages as codes (the default one too).
     *
     * @return list<array<string, mixed>>
     */
    public static function forClaude(Db $db, Settings $s, int $budget = 4000): array
    {
        return self::claudeRows(self::all($db), Language::defaults($s), $budget);
    }

    /**
     * forClaude() of given rows (pure): full rows while their JSON stays under $budget bytes, then names with more: true.
     *
     * @param array<string, list<array<string, mixed>>> $all as all() gives them
     * @return list<array<string, mixed>>
     */
    public static function claudeRows(array $all, string $defaultLanguage, int $budget = 4000): array
    {
        $out = [];
        $bytes = 2;
        $full = true;
        foreach ($all as $language => $rows) {
            foreach ($rows as $r) {
                $row = ['name' => (string) $r['name'], 'language' => $language !== '' ? (string) $language : $defaultLanguage, 'subject' => (string) $r['subject'], 'text' => (string) $r['text']];
                $size = strlen((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 1;
                if ($full && $bytes + $size <= $budget) {
                    $out[] = $row;
                    $bytes += $size;
                    continue;
                }
                $full = false;
                $out[] = ['name' => $row['name'], 'language' => $row['language'], 'more' => true];
            }
        }

        return $out;
    }
}
