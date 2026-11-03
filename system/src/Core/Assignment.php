<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Who handles what (3.24): "Handled by" of an enquiry (ka_poptavky.prirazeno) and the responsible person of a page or a
 * collection item (owner_id, migration 0110). Responsibility only – it feeds Mine, the review reminders and one e-mail on
 * assignment; it never limits who may open or change the thing, and there is no approval step.
 *
 *  - candidates(): who may be chosen – active administrators and people with the section (the rule Enquiries used since 2.x).
 *  - notify(): the notification (3.26, Core\Inbox, kind "assigned") and the one e-mail to the person something was assigned
 *    to – the admin, MCP update_enquiry and assign call it. Never to oneself, never with the content of an enquiry: the form
 *    name or the title and a link to the administration. The e-mail follows the person's choice (notify_email).
 *  - label(): a name for the lists, "(former user)" for an account that was removed or blocked.
 */
final class Assignment
{
    /**
     * kind => the admin section (Module::IDENT) a person needs to be chosen for it; 3.28: a customer and a company need Customers
     * (Core\Contacts::SECTION); 3.30: a site and a customer of a fleet console need the console (only tasks are about them –
     * Fleet\ConsoleTasks; nothing on the console has a responsible person)
     */
    public const array KINDS = ['enquiry' => 'enquiries', 'page' => 'pages', 'item' => 'collections', 'contact' => 'contacts', 'company' => 'contacts',
        'fleet_site' => 'fleet', 'fleet_customer' => 'fleet'];

    /**
     * Sections only administrators open (Module::ADMIN_ONLY – the fleet console, N330-4): a stale or forged section right
     * of another account never makes it a candidate there.
     */
    public const array ADMIN_SECTIONS = ['fleet'];

    /** How a removed account is shown where its id is left (untranslated at the MCP boundary, t() in the admin). */
    public const string FORMER_USER = '(former user)';

    /** @var array<int, array{0: string, 1: bool}>|null idu => [the name the admin shows, blocked] of every account, for one request */
    private static ?array $accounts = null;

    /**
     * Who can be chosen for a kind: active administrators and users with the section. $keep (the current one) stays in
     * the list even when they lost the section or were blocked meanwhile – saving a form does not silently drop them; a
     * blocked one is named "(former user)".
     *
     * @return array<int, string> user id => name, in the order of the names
     */
    public static function candidates(Db $db, string $kind, int $keep = 0): array
    {
        $section = self::KINDS[$kind] ?? throw new \InvalidArgumentException('Unknown kind of assignment.');
        $name = "IF(jmeno = '', user, jmeno)";
        $rows = $db->all(
            'SELECT idu, ' . $name . ' AS name, blokovat FROM {uzivatele} u WHERE (blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))) OR idu = ? ORDER BY ' . $db->sortText($name),
            [Auth::ADMIN, in_array($section, self::ADMIN_SECTIONS, true) ? '' : $section, $keep],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['idu']] = (int) $r['blokovat'] !== 0 ? self::formerName((string) $r['name']) : (string) $r['name'];
        }
        if ($keep > 0 && !isset($out[$keep])) {
            $out[$keep] = t(self::FORMER_USER); // the account was deleted: the id stays until someone chooses another person
        }

        return $out;
    }

    /** May this user be chosen for the kind now: an active account that can open the section. */
    public static function allowed(Db $db, string $kind, int $userId): bool
    {
        $section = self::KINDS[$kind] ?? null;

        return $section !== null && $userId > 0 && $db->value('SELECT 1 FROM {uzivatele} u WHERE idu = ? AND blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))',
            [$userId, Auth::ADMIN, in_array($section, self::ADMIN_SECTIONS, true) ? '' : $section]) !== null;
    }

    /**
     * The value a form posted for the responsible person: null = nobody ("—"), the id of a candidate, or the stored value
     * when the post names someone who may not be chosen (a stale form, a forged id).
     */
    public static function fromForm(Db $db, string $kind, int $posted, ?int $stored): ?int
    {
        if ($posted <= 0) {
            return null;
        }

        return isset(self::candidates($db, $kind, (int) $stored)[$posted]) ? $posted : $stored;
    }

    /**
     * The name of a user wherever the admin or MCP shows who is responsible: the name (the sign-in name when there is
     * none); a blocked account "Jana (former user)", a removed one "(former user)". $translate = false keeps the English
     * words for the MCP boundary. The one implementation of the label – Core\Handover::person uses it too.
     */
    public static function label(Db $db, int $id, bool $translate = true): string
    {
        $account = (self::$accounts ??= array_map(fn (array $r): array => [(string) $r['name'], (int) $r['blokovat'] !== 0],
            array_column($db->all("SELECT idu, IF(jmeno = '', user, jmeno) AS name, blokovat FROM {uzivatele}"), null, 'idu')))[$id] ?? null;

        return $account === null ? ($translate ? t(self::FORMER_USER) : self::FORMER_USER) : ($account[1] ? self::formerName($account[0], $translate) : $account[0]);
    }

    /** Forgets the accounts read in this request (after one was renamed, blocked or deleted, and in tests). */
    public static function forget(): void
    {
        self::$accounts = null;
    }

    /**
     * Names of users for a list, by id (label()).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function labels(Db $db, array $ids): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map(intval(...), $ids), fn (int $id): bool => $id > 0)) as $id) {
            $out[$id] = self::label($db, $id);
        }

        return $out;
    }

    /**
     * Tells the person an enquiry, a page or an item was just assigned to (3.24; 3.26: a notification in their bell too).
     * Nothing – and false – when the recipient is the one who assigns (self-assignment), nobody, not an active account, or
     * cannot open the section. The caller calls it only when the assignee changed. The notification (kind "assigned",
     * target "<kind>:<id>", no excerpt) is recorded for every such person; the e-mail goes as before 3.26 only when they
     * chose "instant" (the default) and have an e-mail address – "daily" leaves it to the morning summary, "off" to the
     * bell. In the recipient's admin language; the form name or the title and a link – never what the enquiry says. Queued
     * and sent after the response (Mail::later), so the save does not wait for the mail server. Returns whether it e-mailed.
     *
     * @param string $kind enquiry | page | item (Assignment::KINDS)
     * @param int $byUserId who assigned it (0 = Kaleta itself)
     */
    public static function notify(App $app, string $kind, int $id, int $toUserId, int $byUserId): bool
    {
        if ($toUserId <= 0 || $toUserId === $byUserId || !isset(self::KINDS[$kind])) {
            return false;
        }
        $db = $app->db();
        $s = $app->settings();
        $to = $db->one('SELECT idu, email, jazyk, register FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$toUserId]);
        if ($to === null || !self::allowed($db, $kind, $toUserId)) {
            return false;
        }
        $target = self::target($db, $kind, $id);
        if ($target === null) {
            return false;
        }
        // 3.26: the bell first; its e-mail is this one – sent only for "instant" (before migration 0112 there is no row and
        // no choice: the e-mail goes, as in 3.24)
        $note = Inbox::record($db, $toUserId, 'assigned', $kind . ':' . $id, $byUserId > 0 ? $byUserId : null);
        if (($note['email'] ?? 'instant') !== 'instant' || count(Mail::addresses((string) $to['email'])) !== 1) {
            return false;
        }
        $by = $byUserId > 0 ? (string) $db->value("SELECT IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE idu = ?", [$byUserId]) : '';
        $url = rtrim($s->get('site_url') ?: $app->request->origin(), '/') . $app->url($target['path']);
        [$subject, $body] = self::mail($s, $to, $kind, $target, $by !== '' ? $by : 'Kaleta', $url);
        Mail::later($s, (string) $to['email'], $subject, $body);
        Inbox::markEmailed($db, $note !== null ? [$note['id']] : []);

        return true;
    }

    /**
     * Subject and text of the assignment e-mail, in the language of the recipient's administration (the site's language
     * when they have not chosen one).
     *
     * @param array<string, mixed> $recipient a row of ka_uzivatele with jazyk and register
     * @param array{id: int, title: string, collection?: string, path: string} $target
     * @return array{0: string, 1: string}
     */
    public static function mail(Settings $s, array $recipient, string $kind, array $target, string $by, string $url): array
    {
        return Language::runWith((string) ($recipient['jazyk'] ?? '') ?: Language::defaults($s), function () use ($kind, $target, $by, $url): array {
            $responsible = t('Being responsible means its review reminders come to you and it is listed under Mine. It does not change who may edit it.');
            [$subject, $text] = match ($kind) {
                'enquiry' => [t('An enquiry from the form “%s” is assigned to you', $target['title']),
                    t('%s assigned you the enquiry #%s from the form “%s”.', $by, (string) $target['id'], $target['title'])],
                'page' => [t('You are responsible for the page “%s”', $target['title']),
                    t('%s made you the responsible person for the page “%s”.', $by, $target['title']) . ' ' . $responsible],
                // 3.28: a customer is named by its number only – the e-mail never carries a customer's personal data
                'contact' => [t('You are responsible for the customer #%s', (string) $target['id']),
                    t('%s made you the responsible person for the customer #%s.', $by, (string) $target['id']) . ' ' . t('It is listed under Mine in Customers. It does not change who may open it.')],
                'company' => [t('You are responsible for the company “%s”', $target['title']),
                    t('%s made you the responsible person for the company “%s”.', $by, $target['title']) . ' ' . t('It is listed under Mine in Customers. It does not change who may open it.')],
                default => [t('You are responsible for “%s” in %s', $target['title'], (string) ($target['collection'] ?? '')),
                    t('%s made you the responsible person for “%s” in the collection %s.', $by, $target['title'], (string) ($target['collection'] ?? '')) . ' ' . $responsible],
            };

            return [$subject, $text . "\n\n" . t('Open it in the administration:') . "\n" . $url . "\n"];
        }, 'admin-', Language::normalizeRegister((string) ($recipient['register'] ?? '')));
    }

    /**
     * What the e-mail names and where it leads: the form name of an enquiry (never its fields), the title of a page, the
     * name and the collection of an item, the number of a customer (3.28 – never their name), the name of a company. Null
     * when it does not exist (or is in the trash, or anonymised).
     *
     * @return array{id: int, title: string, collection?: string, path: string}|null
     */
    private static function target(Db $db, string $kind, int $id): ?array
    {
        if ($kind === 'contact') {
            $exists = $db->value('SELECT 1 FROM {contacts} WHERE id = ? AND anonymised_at IS NULL', [$id]);

            return $exists === null ? null : ['id' => $id, 'title' => '#' . $id, 'path' => Contacts::adminPath($id)];
        }
        if ($kind === 'company') {
            $name = $db->value('SELECT name FROM {companies} WHERE id = ?', [$id]);

            return $name === null ? null : ['id' => $id, 'title' => (string) $name, 'path' => Contacts::companyAdminPath($id)];
        }
        if ($kind === 'enquiry') {
            $form = $db->value('SELECT formular FROM {poptavky} WHERE idp = ?', [$id]);

            return $form === null ? null : ['id' => $id, 'title' => (string) $form, 'path' => 'admin.php?module=enquiries&action=detail&id=' . $id];
        }
        if ($kind === 'page') {
            $title = $db->value('SELECT titulek FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);

            return $title === null ? null : ['id' => $id, 'title' => (string) $title, 'path' => 'admin.php?module=pages&action=edit&id=' . $id];
        }
        $item = $db->one('SELECT p.idk, p.nazev, k.nazev AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp = ? AND p.smazano IS NULL', [$id]);

        return $item === null ? null : ['id' => $id, 'title' => (string) $item['nazev'], 'collection' => (string) $item['kolekce'],
            'path' => 'admin.php?module=collections&action=item&id=' . (int) $item['idk'] . '&item=' . $id];
    }

    private static function formerName(string $name, bool $translate = true): string
    {
        $former = $translate ? t(self::FORMER_USER) : self::FORMER_USER;

        return $name !== '' ? $name . ' ' . $former : $former;
    }
}
