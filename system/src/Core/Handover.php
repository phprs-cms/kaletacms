<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Who is responsible for what (3.24): the responsible person of a page or a collection item (owner_id, migration 0110) and
 * the person who handles an enquiry (ka_poptavky.prirazeno). Responsibility only – My work, the assignment e-mail and the
 * review reminder follow it; it never limits access and needs no approval: anyone who may open the section may change it.
 *
 * One place for the rules the admin and MCP share: who may take a kind of work (an active account that may open its
 * section – the pattern of Enquiries::listAssignees), how a user is named in a tool call (id, e-mail, "me", "none"),
 * one assignment (MCP assign and update_enquiry) and handing all of a leaver's work over (Users, before blocking or
 * deleting the account). A removed account leaves its id behind (no foreign key) and is shown as "(former user)".
 */
final class Handover
{
    /** kind => [the admin section that opens it (Module::IDENT), the extension it needs ('' = core), table, id column, column of the person] */
    public const array KINDS = [
        'page' => ['pages', '', 'stranky', 'ids', 'owner_id'],
        'item' => ['collections', '', 'kolekce_polozky', 'idp', 'owner_id'],
        'enquiry' => ['enquiries', 'poptavky', 'poptavky', 'idp', 'prirazeno'],
    ];

    /** Forgets the names read in this request (after an account was renamed, blocked or deleted – Assignment::forget). */
    public static function forget(): void
    {
        Assignment::forget();
    }

    /**
     * The person as a tool result shows them: {id, name}; null when nobody is set. A removed account keeps its id with
     * the name "(former user)" (a blocked one "Jana (former user)" – Assignment::label, in English at the MCP boundary),
     * so a list never hides that the work has no living owner.
     *
     * @return array{id: int, name: string}|null
     */
    public static function person(Db $db, mixed $id): ?array
    {
        $id = (int) $id;

        return $id <= 0 ? null : ['id' => $id, 'name' => Assignment::label($db, $id, false)];
    }

    /**
     * May this account open the section (Module::IDENT)? Active (not blocked), the feature on, and an administrator or a
     * row in ka_uzivatele_prava – what Auth::hasModule asks for the signed-in person, for anyone.
     */
    public static function mayOpen(Db $db, Settings $siteSettings, int $userId, string $section): bool
    {
        $kind = array_search($section, Assignment::KINDS, true);

        // the rule itself is Assignment::allowed – one implementation for the admin forms, MCP and the hand-over
        return is_string($kind) && self::sectionOn($siteSettings, $section) && Assignment::allowed($db, $kind, $userId);
    }

    /**
     * Who may take work of these sections: active accounts that may open every one of them (administrators always),
     * by name; $except (the leaver) is left out.
     *
     * @param list<string> $sections Module::IDENT values
     * @return array<int, string> idu => name
     */
    public static function eligible(Db $db, Settings $siteSettings, array $sections, int $except = 0): array
    {
        if ($sections === []) {
            return array_map('strval', $db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 AND idu <> ? ORDER BY " . $db->sortText("IF(jmeno = '', user, jmeno)"), [$except]));
        }
        $out = null;
        foreach ($sections as $section) {
            $kind = array_search($section, Assignment::KINDS, true);
            if (!is_string($kind) || !self::sectionOn($siteSettings, $section)) {
                return [];
            }
            // who may be chosen for each kind is Assignment::candidates – the recipient must be one for every kind
            $candidates = Assignment::candidates($db, $kind);
            $out = $out === null ? $candidates : array_intersect_key($out, $candidates);
        }
        unset($out[$except]);

        return $out;
    }

    /**
     * The person a tool call names: a user id, an e-mail address of an active account, "me" (the signed-in user) or
     * "none" / "" / 0 (nobody). Returns the id, null for nobody; an unknown person is an InvalidArgumentException.
     */
    public static function resolve(Db $db, mixed $value, int $self): ?int
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (in_array(strtolower($text), ['', '0', 'none', 'nobody'], true)) {
            return null;
        }
        if (strtolower($text) === 'me') {
            return $self;
        }
        $id = ctype_digit($text) ? (int) $db->value('SELECT idu FROM {uzivatele} WHERE idu = ?', [(int) $text])
            : (str_contains($text, '@') ? (int) $db->value('SELECT idu FROM {uzivatele} WHERE LOWER(email) = ? AND blokovat = 0 ORDER BY idu LIMIT 1', [mb_strtolower($text)]) : 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('No user has this id or e-mail. Give a user id, the e-mail of an active account, "me" or "none".');
        }

        return $id;
    }

    /**
     * Makes $userId responsible for one page, item or enquiry (null = nobody). The person must be able to open the
     * section – otherwise nothing changes and a DomainException says why. The caller checks that the signed-in person
     * may open the section. Sends the assignment e-mail when someone else got the work (see notify).
     *
     * @return array{previous: ?int, changed: bool}
     */
    public static function assign(App $app, string $kind, int $id, ?int $userId): array
    {
        [$section, , $table, $idColumn, $column] = self::KINDS[$kind] ?? throw new \InvalidArgumentException('target must be page, item or enquiry.');
        $db = $app->db();
        $row = $db->one('SELECT ' . $column . ' AS person FROM {' . $table . '} WHERE ' . $idColumn . ' = ?' . ($kind === 'enquiry' ? '' : ' AND smazano IS NULL'), [$id])
            ?? throw new \InvalidArgumentException(['page' => 'The page does not exist. Use list_pages.', 'item' => 'The collection item does not exist. Use list_collection_items.', 'enquiry' => 'The enquiry does not exist. Use list_enquiries.'][$kind]);
        if ($userId !== null && !self::mayOpen($db, $app->settings(), $userId, $section)) {
            throw new \DomainException('This user cannot open ' . ['pages' => 'Pages', 'collections' => 'Collections', 'enquiries' => 'Enquiries'][$section]
                . ' (or the account is blocked) – give the work to someone who can, or ask an administrator to give them the section.');
        }
        $previous = $row['person'] !== null ? (int) $row['person'] : null;
        if ($previous === $userId) {
            return ['previous' => $previous, 'changed' => false];
        }
        $db->update($table, [$column => $userId], [$idColumn => $id]);
        self::notify($app, $kind, $id, $previous, $userId);

        return ['previous' => $previous, 'changed' => true];
    }

    /**
     * The e-mail to the person who got the work (Core\Assignment::notify – the same single e-mail the admin forms send):
     * only when it changed and went to someone else, never to oneself, in their admin language, with a link and never
     * the content (owner decision of 2 Nov 2026).
     */
    private static function notify(App $app, string $kind, int $id, ?int $previous, ?int $userId): void
    {
        if ($userId === null || $userId === $previous) {
            return;
        }
        try {
            Assignment::notify($app, $kind, $id, $userId, $app->auth()->id());
        } catch (\Throwable) {
            // an e-mail that cannot be prepared never undoes the assignment
        }
    }

    /**
     * What a user is responsible for now: pages and collection items (not in the trash), open enquiries (new or read),
     * (3.27) open tasks, (3.28) customers and companies (Core\Contacts) and (3.31) open deals (Core\Deals).
     *
     * @return array{page: int, item: int, enquiry: int, task: int, contact: int, company: int, deal: int}
     */
    public static function owned(Db $db, int $userId): array
    {
        return [
            'page' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE owner_id = ? AND smazano IS NULL', [$userId]),
            'item' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE owner_id = ? AND smazano IS NULL', [$userId]),
            'enquiry' => (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE prirazeno = ? AND stav < 2', [$userId]),
            'task' => Tasks::openCount($db, $userId),
        ] + Contacts::owned($db, $userId) + ['deal' => Deals::owned($db, $userId)]; // 3.31: open deals
    }

    /**
     * The same for every account at once (the Users list): idu => counts, only accounts that own something.
     *
     * @return array<int, array{page: int, item: int, enquiry: int, task: int, contact: int, company: int, deal: int}>
     */
    public static function ownedByAll(Db $db): array
    {
        $none = ['page' => 0, 'item' => 0, 'enquiry' => 0, 'task' => 0, 'contact' => 0, 'company' => 0, 'deal' => 0];
        $out = [];
        foreach (Tasks::openByAll($db) as $idu => $n) {
            $out[$idu] = ['task' => $n] + $none; // 3.27: open tasks
        }
        foreach (Contacts::ownedByAll($db) as $idu => $counts) {
            $out[$idu] = $counts + ($out[$idu] ?? $none); // 3.28: customers and companies
        }
        foreach (Deals::ownedByAll($db) as $idu => $n) {
            $out[$idu] = ['deal' => $n] + ($out[$idu] ?? $none); // 3.31: open deals
        }
        foreach ([
            'page' => 'SELECT owner_id AS idu, COUNT(*) AS n FROM {stranky} WHERE owner_id IS NOT NULL AND smazano IS NULL GROUP BY owner_id',
            'item' => 'SELECT owner_id AS idu, COUNT(*) AS n FROM {kolekce_polozky} WHERE owner_id IS NOT NULL AND smazano IS NULL GROUP BY owner_id',
            'enquiry' => 'SELECT prirazeno AS idu, COUNT(*) AS n FROM {poptavky} WHERE prirazeno IS NOT NULL AND stav < 2 GROUP BY prirazeno',
        ] as $kind => $sql) {
            foreach ($db->all($sql) as $row) {
                $out[(int) $row['idu']] ??= $none;
                $out[(int) $row['idu']][$kind] = (int) $row['n'];
            }
        }

        return $out;
    }

    /**
     * The sections a recipient of this work must be able to open (Module::IDENT), by what there is. Tasks need no section of
     * their own (sectionsToTake adds those of their records); customers and companies need Customers (3.28).
     *
     * @param array{page: int, item: int, enquiry: int, task?: int, contact?: int, company?: int, deal?: int} $owned
     * @return list<string>
     */
    public static function sectionsOf(array $owned): array
    {
        return [...array_map(fn (string $kind): string => self::KINDS[$kind][0], array_keys(array_filter(array_intersect_key($owned, self::KINDS)))),
            ...(($owned['contact'] ?? 0) + ($owned['company'] ?? 0) > 0 ? [Contacts::SECTION] : []), ...(($owned['deal'] ?? 0) > 0 ? [Deals::SECTION] : [])];
    }

    /**
     * The sections a recipient of all of a user's work must be able to open – what handOver() moves, so the pages and
     * items in the trash count too (N324-4: a restored one must not come back to someone who cannot open it).
     *
     * @return list<string> Module::IDENT values
     */
    public static function sectionsToTake(Db $db, int $userId): array
    {
        return array_values(array_unique([...self::sectionsOf([
            'page' => (int) ($db->value('SELECT 1 FROM {stranky} WHERE owner_id = ? LIMIT 1', [$userId]) !== null),
            'item' => (int) ($db->value('SELECT 1 FROM {kolekce_polozky} WHERE owner_id = ? LIMIT 1', [$userId]) !== null),
            'enquiry' => (int) ($db->value('SELECT 1 FROM {poptavky} WHERE prirazeno = ? AND stav < 2 LIMIT 1', [$userId]) !== null),
        ] + Contacts::owned($db, $userId) + ['deal' => (int) Deals::ownsAny($db, $userId)]), ...Tasks::sectionsOf($db, $userId)])); // 3.27: the records of their open tasks too; 3.28 customers; 3.31 deals
    }

    /**
     * Hands all of a user's work to another person (null = nobody): every page and item they are responsible for (in the
     * trash too, so a restored one has a living owner) and their open enquiries; a resolved enquiry keeps who handled it.
     * No e-mail – the recipient finds it in My work. 3.27: their open tasks go too (to nobody = unassigned, listed under
     * "nobody" in the Team tab of My work); done tasks keep who did them. 3.28: their customers and companies go too (an
     * anonymised contact too, so no leaver's id is left behind). Returns how many of each moved.
     *
     * @return array{page: int, item: int, enquiry: int, task: int, contact: int, company: int, deal: int}
     */
    public static function handOver(Db $db, int $from, ?int $to): array
    {
        return [
            'page' => $db->run('UPDATE {stranky} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount(),
            'item' => $db->run('UPDATE {kolekce_polozky} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount(),
            'enquiry' => $db->run('UPDATE {poptavky} SET prirazeno = ? WHERE prirazeno = ? AND stav < 2', [$to, $from])->rowCount(),
            'task' => Tasks::handOver($db, $from, $to),
        ] + Contacts::handOver($db, $from, $to) + ['deal' => Deals::handOver($db, $from, $to)]; // 3.31: their deals, open and closed
    }

    /**
     * "pages: 2, collection items: 1, open enquiries: 3, open tasks: 4" – the parts that are not zero, translated (counted
     * labels, so no language needs plural forms).
     *
     * @param array{page: int, item: int, enquiry: int, task?: int, contact?: int, company?: int, deal?: int} $counts
     */
    public static function describe(array $counts): string
    {
        $parts = [];
        foreach (['page' => 'pages: %d', 'item' => 'collection items: %d', 'enquiry' => 'open enquiries: %d', 'task' => 'open tasks: %d', 'contact' => 'customers: %d', 'company' => 'companies: %d',
            'deal' => 'deals: %d'] as $kind => $label) {
            if (($counts[$kind] ?? 0) > 0) {
                $parts[] = t($label, $counts[$kind]);
            }
        }

        return implode(', ', $parts);
    }

    private static function sectionOn(Settings $siteSettings, string $section): bool
    {
        foreach (self::KINDS as [$ident, $extension]) {
            if ($ident === $section) {
                return Extensions::isEnabled($siteSettings, $extension);
            }
        }

        return true;
    }
}
