<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Departments (3.42, table ka_departments, migration 0132): a name, an optional head (an account) and an optional parent
 * department – a tree, never a cycle. Each account belongs to at most one department (ka_uzivatele.department_id) and may
 * report to another account (ka_uzivatele.reports_to). Administrators edit both in Users → Departments; everyone sees them
 * in My account → Org chart (Admin\Account, Core\Colleagues::chart).
 *
 * A department may also name forms (enquiry_forms, one name per line): a new enquiry from such a form goes to the head of the
 * department – or to the head's stand-in while the head is away (Core\Absences::standIn) – as its "Handled by"
 * (Departments::assignEnquiry). Nothing is configured by default, so nothing changes until an administrator names a form.
 *
 * The stable API for other parts of Kaleta (folder visibility by department and the like):
 *  - all(): every department, by name;
 *  - ofUser(): the department of an account (null = none);
 *  - withChildren(): a department and every department below it;
 *  - userIn(): does an account belong to a department or to one below it.
 * Work data only – a department is never in a heartbeat, the fleet console or a report (counts at most).
 */
final class Departments
{
    /** The longest name of a department. */
    public const int NAME = 100;

    /** The most forms a department names, and the longest name of one. */
    public const int FORMS = 20;

    /** 3.42 review L1: the stored list of forms (one per line) is at most this long – ka_departments.enquiry_forms VARCHAR(1000). */
    public const int FORMS_TOTAL = 1000;

    /** @var array<int, array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>}>|null for one request */
    private static ?array $cache = null;

    /**
     * Every department, by name. An empty list before migration 0132.
     *
     * @return list<array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>}>
     */
    public static function all(Db $db): array
    {
        return array_values(self::load($db));
    }

    /** One department, or null. @return array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>}|null */
    public static function find(Db $db, int $id): ?array
    {
        return self::load($db)[$id] ?? null;
    }

    /** The department of an account (null = none, an unknown account, or a department that is gone). */
    public static function ofUser(Db $db, int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $id = $db->value('SELECT department_id FROM {uzivatele} WHERE idu = ?', [$userId]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null;
        }

        return $id !== null && isset(self::load($db)[(int) $id]) ? (int) $id : null;
    }

    /**
     * A department and every department below it (children, their children…), the department itself first. Empty for an
     * unknown department.
     *
     * @return list<int>
     */
    public static function withChildren(Db $db, int $id): array
    {
        $all = self::load($db);
        if (!isset($all[$id])) {
            return [];
        }
        $out = [$id];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($all as $d) {
                if ($d['parent_id'] === $out[$i] && !in_array($d['id'], $out, true)) {
                    $out[] = $d['id'];
                }
            }
        }

        return $out;
    }

    /** Does the account belong to the department ($withChildren: or to a department below it). */
    public static function userIn(Db $db, int $userId, int $departmentId, bool $withChildren = true): bool
    {
        $own = self::ofUser($db, $userId);

        return $own !== null && in_array($own, $withChildren ? self::withChildren($db, $departmentId) : [$departmentId], true);
    }

    /**
     * The departments as a tree for lists and selects: each with its depth (0 = top), parents before their children,
     * siblings by name. A department whose parent is gone counts as a top one.
     *
     * @return list<array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>, depth: int}>
     */
    public static function tree(Db $db): array
    {
        $all = self::load($db);
        $children = [];
        foreach ($all as $d) {
            $parent = $d['parent_id'] !== null && isset($all[$d['parent_id']]) ? $d['parent_id'] : 0;
            $children[$parent][] = $d['id'];
        }
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $children, $all): void {
            foreach ($children[$parent] ?? [] as $id) {
                if ($depth > 20 || isset($out[$id])) {
                    continue; // never loops, whatever the table holds
                }
                $out[$id] = $all[$id] + ['depth' => $depth];
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return array_values($out);
    }

    /**
     * Saves a department (id 0 = a new one). Returns its id, or the English error to show: an empty name, the same name
     * twice, a parent that is the department itself or one below it (a cycle), a head who is not an active account.
     *
     * @param list<string> $forms names of forms whose new enquiries go to the head
     */
    public static function save(Db $db, int $id, string $name, ?int $headId, ?int $parentId, array $forms): int|string
    {
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, self::NAME);
        if ($name === '') {
            return 'Enter the name of the department.';
        }
        $all = self::load($db);
        if ($id > 0 && !isset($all[$id])) {
            return 'The department does not exist.';
        }
        foreach ($all as $d) {
            if ($d['id'] !== $id && mb_strtolower($d['name']) === mb_strtolower($name)) {
                return 'Another department already has this name.';
            }
        }
        $parentId = $parentId !== null && $parentId > 0 ? $parentId : null;
        if ($parentId !== null && (!isset($all[$parentId]) || ($id > 0 && in_array($parentId, self::withChildren($db, $id), true)))) {
            return 'A department cannot be placed under itself or under a department below it.';
        }
        $headId = $headId !== null && $headId > 0 ? $headId : null;
        if ($headId !== null && $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$headId]) === null) {
            return 'The head must be an active account.';
        }
        $formList = implode("\n", self::forms($forms));
        if (mb_strlen($formList) > self::FORMS_TOTAL) {
            return 'The names of the forms are too long together – at most 1,000 characters.';
        }
        $row = ['name' => $name, 'head_id' => $headId, 'parent_id' => $parentId, 'enquiry_forms' => $formList, 'updated_at' => Clock::now()];
        if ($id > 0) {
            $db->update('departments', $row, ['id' => $id]);
        } else {
            $id = $db->insert('departments', $row + ['created_at' => Clock::now()]);
        }
        self::forget();

        return $id;
    }

    /**
     * Removes a department: its members belong to no department, the departments under it move to its parent. Returns
     * whether it existed.
     */
    public static function delete(Db $db, int $id): bool
    {
        $department = self::find($db, $id);
        if ($department === null) {
            return false;
        }
        $db->transaction(function () use ($db, $id, $department): void {
            $db->run('UPDATE {uzivatele} SET department_id = NULL WHERE department_id = ?', [$id]);
            $db->run('UPDATE {departments} SET parent_id = ? WHERE parent_id = ?', [$department['parent_id'], $id]);
            $db->delete('departments', ['id' => $id]);
        });
        self::forget();

        return true;
    }

    /**
     * The names of forms as stored: trimmed, without empty lines and repeats (case-insensitive), at most FORMS of them, each
     * as long as a form name can be (120 characters, ka_poptavky.formular). Pure.
     *
     * @param list<string> $forms
     * @return list<string>
     */
    public static function forms(array $forms): array
    {
        $out = [];
        foreach ($forms as $form) {
            $form = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $form)), 0, 120);
            if ($form !== '' && !isset($out[mb_strtolower($form)]) && count($out) < self::FORMS) {
                $out[mb_strtolower($form)] = $form;
            }
        }

        return array_values($out);
    }

    /** The department that names a form (the lowest id when two do), or null. @return array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>}|null */
    public static function forForm(Db $db, string $form): ?array
    {
        $form = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $form)));
        if ($form === '') {
            return null;
        }
        $all = self::load($db);
        ksort($all); // by id
        foreach ($all as $d) {
            if (in_array($form, array_map(mb_strtolower(...), $d['enquiry_forms']), true)) {
                return $d;
            }
        }

        return null;
    }

    /**
     * A new enquiry (Front\Forms, right after it is saved): when a department names its form and has a head, the head
     * handles it – or, while the head is away today, their stand-in (Core\Absences::standIn: the deputy, else whom the head
     * reports to), who is told they stand in. Only someone who may open Enquiries, only when nobody handles it yet; the
     * assignment notification (Core\Assignment::notify) follows. Returns who got it (null = nobody, nothing changed).
     */
    public static function assignEnquiry(App $app, int $idp): ?int
    {
        $db = $app->db();
        try {
            $enquiry = $db->one('SELECT idp, formular, prirazeno FROM {poptavky} WHERE idp = ?', [$idp]);
            $department = $enquiry !== null && $enquiry['prirazeno'] === null ? self::forForm($db, (string) $enquiry['formular']) : null;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null;
        }
        if ($department === null || $department['head_id'] === null) {
            return null;
        }
        $head = $department['head_id'];
        $standIn = Absences::standIn($db, $head, fn (int $uid): bool => Assignment::allowed($db, 'enquiry', $uid));
        $to = $standIn['id'] ?? (Absences::absentToday($db, $head) === null && Assignment::allowed($db, 'enquiry', $head) ? $head : null);
        if ($to === null) {
            return null;
        }
        // 3.51 (N148): routed by a rule of the site – nobody assigned it (assigned_by NULL counts for the team summary as before)
        if ($db->run('UPDATE {poptavky} SET prirazeno = ?, assigned_by = NULL WHERE idp = ? AND prirazeno IS NULL', [$to, $idp])->rowCount() === 0) {
            return null;
        }
        Assignment::notify($app, 'enquiry', $idp, $to, 0, $standIn !== null ? $standIn['for_name'] : '');

        return $to;
    }

    /**
     * Why an account cannot report to another one (English), or null when it can (null = to nobody): not to itself, only to
     * an active account, and never in a circle (A reports to B who reports to A).
     */
    public static function reportingRefusal(Db $db, int $userId, ?int $reportsTo): ?string
    {
        if ($reportsTo === null || $reportsTo <= 0) {
            return null;
        }
        if ($reportsTo === $userId) {
            return 'Nobody reports to themself.';
        }
        if ($db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$reportsTo]) === null) {
            return 'The person to report to must be an active account.';
        }
        $seen = [];
        for ($next = $reportsTo; $next !== null && $next > 0 && count($seen) < 100; ) {
            if ($next === $userId) {
                return 'This would make a circle – they already report to this person, directly or through others.';
            }
            if (isset($seen[$next])) {
                break; // a circle above, not through this account
            }
            $seen[$next] = true;
            $up = $db->value('SELECT reports_to FROM {uzivatele} WHERE idu = ?', [$next]);
            $next = $up !== null ? (int) $up : null;
        }

        return null;
    }

    /** Forgets the departments read in this request (after a change, and in tests). */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return array<int, array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>}> */
    private static function load(Db $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = $db->all('SELECT id, name, head_id, parent_id, enquiry_forms FROM {departments} ORDER BY id');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'head_id' => $r['head_id'] !== null ? (int) $r['head_id'] : null,
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'enquiry_forms' => array_values(array_filter(explode("\n", (string) $r['enquiry_forms']), fn (string $f): bool => $f !== ''))];
        }
        uasort($out, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
        self::$cache = $out;

        return $out;
    }
}
