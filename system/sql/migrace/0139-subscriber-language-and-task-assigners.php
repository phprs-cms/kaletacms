<?php
/**
 * Kaleta 3.51: fills three columns of 0138 on rows that existed before the update.
 *  1. ka_odberatele.language where it is '': the language version of the page the person signed up from (the first path
 *     segment of zdroj after the site's own base path, when it is one of the site's additional languages); otherwise the
 *     language of the subscriber's customer record (ka_contacts.language) when that is one of them; otherwise it stays ''
 *     (the default language). A page whose address only looks like a code ("/de" on a site without German) changes nothing.
 *  2. ka_tasks.assigned_by = created_by for open and done tasks with an assignee and no assigner – the rule of 3.50 (a task
 *     a person created for someone else is theirs to answer for) stays true for tasks that exist.
 *  3. ka_testimonial_requests.sent_at = created_at for every request made before the update (3.51 review): which of them
 *     went out by e-mail is known only from the change log's text, so each counts as e-mailed – the enquiry is not e-mailed a
 *     second time ("once per enquiry", reason already_sent); the link can still be passed on by hand.
 *
 * Safe to run again: every step touches only rows that are still empty and gives the same result (a second run would also
 * count a request made since as e-mailed – the careful side: it only stops a second e-mail). Self-contained on purpose:
 * the update request of the release before runs this file with its own classes already loaded, so the languages are read
 * from the stored settings here (site_language, additional_languages, site_url), not through Core\Language.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $stored = [];
    foreach ($db->all("SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN ('site_language', 'additional_languages', 'site_url')") as $row) {
        $stored[(string) $row['promenna']] = (string) $row['hodnota'];
    }
    $default = ($stored['site_language'] ?? '') !== '' ? $stored['site_language'] : 'cs';
    $languages = array_values(array_diff(array_filter(array_map('trim', explode(',', $stored['additional_languages'] ?? ''))), [$default]));
    $base = rtrim((string) parse_url($stored['site_url'] ?? '', PHP_URL_PATH), '/');

    // the language of an address "/<base>/<code>/…" (or a full URL with it); '' when the first segment is not an additional language
    $fromPath = static function (string $address) use ($languages, $base): string {
        $path = (string) (parse_url($address, PHP_URL_PATH) ?? '');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        $segment = strtolower(explode('/', ltrim($path, '/'), 2)[0]);

        return in_array($segment, $languages, true) ? $segment : '';
    };

    if ($languages !== []) {
        try {
            $rows = $db->all("SELECT o.ido, o.zdroj, c.language AS contact_language FROM {odberatele} o LEFT JOIN {contacts} c ON c.id = o.contact_id WHERE o.language = ''");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            $rows = $db->all("SELECT ido, zdroj, '' AS contact_language FROM {odberatele} WHERE language = ''"); // a site without customer records
        }
        foreach ($rows as $row) {
            $language = $fromPath((string) $row['zdroj']);
            if ($language === '' && in_array((string) $row['contact_language'], $languages, true)) {
                $language = (string) $row['contact_language'];
            }
            if ($language !== '') {
                $db->run("UPDATE {odberatele} SET language = ? WHERE ido = ? AND language = ''", [$language, (int) $row['ido']]);
            }
        }
    }

    try {
        $db->run('UPDATE {tasks} SET assigned_by = created_by WHERE assigned_by IS NULL AND assignee_id IS NOT NULL AND created_by IS NOT NULL');
    } catch (\PDOException $e) {
        Db::rethrowUnlessMissing($e); // a site without tasks
    }

    try {
        $db->run('UPDATE {testimonial_requests} SET sent_at = created_at WHERE sent_at IS NULL');
    } catch (\PDOException $e) {
        Db::rethrowUnlessMissing($e); // a site without testimonial requests
    }
};
