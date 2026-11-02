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

    /** The class that sends the assignment e-mail (see notify) – built in the parallel 3.24 branch, wired at merge. */
    private const string NOTIFIER = 'Kaleta\\Core\\Assignment';

    /** How a removed account is shown where its id is left (a page, an item or an enquiry). */
    public const string FORMER_USER = '(former user)';

    /** @var array<int, string>|null idu => the name the admin shows, for one request */
    private static ?array $names = null;

    /** The name of every account (also blocked ones): the name, or the sign-in name when it has none. @return array<int, string> */
    public static function names(Db $db): array
    {
        return self::$names ??= array_map('strval', $db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele}"));
    }

    /** Forgets the names read in this request (after an account was renamed or deleted, and in tests). */
    public static function forget(): void
    {
        self::$names = null;
    }

    /**
     * The person as a tool result shows them: {id, name}; null when nobody is set. A removed account keeps its id with
     * the name "(former user)", so a list never hides that the work has no living owner.
     *
     * @return array{id: int, name: string}|null
     */
    public static function person(Db $db, mixed $id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        return ['id' => $id, 'name' => self::names($db)[$id] ?? self::FORMER_USER];
    }

    /** The name the admin shows for a person ('' = nobody, translated "(former user)" for a removed account). */
    public static function label(Db $db, mixed $id): string
    {
        $person = self::person($db, $id);

        return $person === null ? '' : (isset(self::names($db)[$person['id']]) ? $person['name'] : t(self::FORMER_USER));
    }

    /**
     * May this account open the section (Module::IDENT)? Active (not blocked), the feature on, and an administrator or a
     * row in ka_uzivatele_prava – what Auth::hasModule asks for the signed-in person, for anyone.
     */
    public static function mayOpen(Db $db, Settings $siteSettings, int $userId, string $section): bool
    {
        if ($userId <= 0 || !self::sectionOn($siteSettings, $section)) {
            return false;
        }

        return $db->value('SELECT 1 FROM {uzivatele} u WHERE u.idu = ? AND u.blokovat = 0 AND (u.admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))',
            [$userId, Auth::ADMIN, $section]) !== null;
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
        foreach ($sections as $section) {
            if (!self::sectionOn($siteSettings, $section)) {
                return [];
            }
        }
        $conditions = array_map(fn (): string => 'EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?)', $sections);

        return array_map('strval', $db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} u WHERE u.blokovat = 0 AND u.idu <> ? AND (u.admin = ?"
            . ($conditions !== [] ? ' OR (' . implode(' AND ', $conditions) . ')' : ' OR 1 = 1') . ') ORDER BY ' . $db->sortText("IF(jmeno = '', user, jmeno)"), [$except, Auth::ADMIN, ...$sections]));
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
     * The e-mail to the person who got the work – only when it is someone else, never to oneself, in their admin language,
     * with a link and never the content (owner decision of 2 Nov 2026).
     *
     * MERGE (3.24): the e-mail itself is built by the parallel branch in a small core class, expected as
     * Core\Assignment::notify. This is the single call site of this branch: the lead wires the final signature here at
     * merge; until the class exists nothing is sent.
     */
    private static function notify(App $app, string $kind, int $id, ?int $previous, ?int $userId): void
    {
        if ($userId === null || $userId === $previous || $userId === $app->auth()->id()) {
            return;
        }
        $notify = [self::NOTIFIER, 'notify'];
        if (class_exists(self::NOTIFIER) && is_callable($notify)) {
            try {
                call_user_func($notify, $app, $kind, $id, $userId);
            } catch (\Throwable) {
                // an e-mail that cannot be prepared never undoes the assignment
            }
        }
    }

    /**
     * What a user is responsible for now: pages and collection items (not in the trash) and open enquiries (new or read).
     *
     * @return array{page: int, item: int, enquiry: int}
     */
    public static function owned(Db $db, int $userId): array
    {
        return [
            'page' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE owner_id = ? AND smazano IS NULL', [$userId]),
            'item' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE owner_id = ? AND smazano IS NULL', [$userId]),
            'enquiry' => (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE prirazeno = ? AND stav < 2', [$userId]),
        ];
    }

    /**
     * The same for every account at once (the Users list): idu => counts, only accounts that own something.
     *
     * @return array<int, array{page: int, item: int, enquiry: int}>
     */
    public static function ownedByAll(Db $db): array
    {
        $out = [];
        foreach ([
            'page' => 'SELECT owner_id AS idu, COUNT(*) AS n FROM {stranky} WHERE owner_id IS NOT NULL AND smazano IS NULL GROUP BY owner_id',
            'item' => 'SELECT owner_id AS idu, COUNT(*) AS n FROM {kolekce_polozky} WHERE owner_id IS NOT NULL AND smazano IS NULL GROUP BY owner_id',
            'enquiry' => 'SELECT prirazeno AS idu, COUNT(*) AS n FROM {poptavky} WHERE prirazeno IS NOT NULL AND stav < 2 GROUP BY prirazeno',
        ] as $kind => $sql) {
            foreach ($db->all($sql) as $row) {
                $out[(int) $row['idu']] ??= ['page' => 0, 'item' => 0, 'enquiry' => 0];
                $out[(int) $row['idu']][$kind] = (int) $row['n'];
            }
        }

        return $out;
    }

    /**
     * The sections a recipient of this work must be able to open (Module::IDENT), by what there is.
     *
     * @param array{page: int, item: int, enquiry: int} $owned
     * @return list<string>
     */
    public static function sectionsOf(array $owned): array
    {
        return array_map(fn (string $kind): string => self::KINDS[$kind][0], array_keys(array_filter($owned)));
    }

    /**
     * Hands all of a user's work to another person (null = nobody): every page and item they are responsible for (in the
     * trash too, so a restored one has a living owner) and their open enquiries; a resolved enquiry keeps who handled it.
     * No e-mail – the recipient finds it in My work. Returns how many of each moved.
     *
     * @return array{page: int, item: int, enquiry: int}
     */
    public static function handOver(Db $db, int $from, ?int $to): array
    {
        return [
            'page' => $db->run('UPDATE {stranky} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount(),
            'item' => $db->run('UPDATE {kolekce_polozky} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount(),
            'enquiry' => $db->run('UPDATE {poptavky} SET prirazeno = ? WHERE prirazeno = ? AND stav < 2', [$to, $from])->rowCount(),
        ];
    }

    /**
     * "pages: 2, collection items: 1, open enquiries: 3" – the parts that are not zero, translated (counted labels, so no
     * language needs plural forms).
     *
     * @param array{page: int, item: int, enquiry: int} $counts
     */
    public static function describe(array $counts): string
    {
        $parts = [];
        foreach (['page' => 'pages: %d', 'item' => 'collection items: %d', 'enquiry' => 'open enquiries: %d'] as $kind => $label) {
            if ($counts[$kind] > 0) {
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
