<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Colleagues (3.37): the people with an account on the site, as every signed-in user sees them in My account → Colleagues
 * and MCP list_colleagues – the name, the position, the work phone, the e-mail and the photo each of them entered for work
 * (My account; administrators in Users). Nothing else of the account (sign-in name, role, bio, sign-ins) and no blocked
 * account: a blocked person is not a colleague to reach. Never in a heartbeat, the fleet console or a report.
 *
 * 3.42: also the department (Core\Departments), whom the person reports to, and – while they are away today
 * (Core\Absences) – the last day of the absence and the deputy. Never the reason of an absence outside the administration.
 */
final class Colleagues
{
    /** A work phone: what the company phone takes (digits, spaces, + ( ) / . -). */
    public const string PHONE_PATTERN = '/^[+()\d\s\/.-]{0,30}$/D';

    /**
     * The colleagues, by name.
     *
     * @return list<array{id: int, name: string, position: string, phone: string, email: string, photo: string, department_id: int|null, department: string, reports_to: int|null, absent_until: string, deputy: int|null}>
     */
    public static function all(Db $db): array
    {
        try {
            $rows = $db->all('SELECT idu, jmeno, pozice, phone, email, foto, department_id, reports_to FROM {uzivatele} WHERE blokovat = 0');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0132: without the department
            $rows = $db->all('SELECT idu, jmeno, pozice, phone, email, foto, NULL AS department_id, NULL AS reports_to FROM {uzivatele} WHERE blokovat = 0');
        }
        $active = array_flip(array_map(fn (array $r): int => (int) $r['idu'], $rows));
        $departments = array_column(Departments::all($db), 'name', 'id');
        $away = Absences::on($db);
        $out = array_map(fn (array $r): array => [
            'id' => (int) $r['idu'],
            // without a name: the part of the work e-mail before @, never the sign-in name
            'name' => trim((string) $r['jmeno']) !== '' ? trim((string) $r['jmeno'])
                : (filter_var(trim((string) $r['email']), FILTER_VALIDATE_EMAIL) !== false ? (string) strstr(trim((string) $r['email']), '@', true) : '—'),
            'position' => trim((string) $r['pozice']),
            'phone' => trim((string) $r['phone']),
            'email' => filter_var(trim((string) $r['email']), FILTER_VALIDATE_EMAIL) !== false ? trim((string) $r['email']) : '',
            'photo' => self::photo((string) $r['foto']),
            'department_id' => $r['department_id'] !== null && isset($departments[(int) $r['department_id']]) ? (int) $r['department_id'] : null,
            'department' => $r['department_id'] !== null ? (string) ($departments[(int) $r['department_id']] ?? '') : '',
            // only an active colleague: a blocked or removed manager or deputy is nobody to reach
            'reports_to' => $r['reports_to'] !== null && isset($active[(int) $r['reports_to']]) && (int) $r['reports_to'] !== (int) $r['idu'] ? (int) $r['reports_to'] : null,
            'absent_until' => (string) ($away[(int) $r['idu']]['ends_on'] ?? ''),
            'deputy' => isset($away[(int) $r['idu']]['deputy_id'], $active[(int) $away[(int) $r['idu']]['deputy_id']]) ? (int) $away[(int) $r['idu']]['deputy_id'] : null,
        ], $rows);
        usort($out, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return $out;
    }

    /**
     * The colleagues for MCP list_colleagues: without the photo and without empty values (compact output). 3.42: the
     * department's name, reports_to and – while away today – absent_until and deputy (ids of colleagues).
     *
     * @param list<array<string, mixed>> $colleagues rows of all()
     * @return list<array<string, int|string>>
     */
    public static function compact(array $colleagues): array
    {
        return array_map(fn (array $c): array => array_filter(['id' => $c['id'], 'name' => $c['name'], 'position' => $c['position'], 'phone' => $c['phone'], 'email' => $c['email'],
            'department' => $c['department'] ?? '', 'reports_to' => $c['reports_to'] ?? null, 'absent_until' => $c['absent_until'] ?? '', 'deputy' => $c['deputy'] ?? null],
            fn (mixed $v): bool => $v !== '' && $v !== null), $colleagues);
    }

    /**
     * The org chart (3.42, My account → Org chart; MCP list_colleagues 'departments'): the departments as a tree,
     * each with its head and members (colleagues of all(), the head first, then by name), and the people in no department.
     *
     * @param list<array<string, mixed>> $colleagues rows of all()
     * @return array{departments: list<array{id: int, name: string, depth: int, parent_id: int|null, head: int|null, members: list<array<string, mixed>>}>, none: list<array<string, mixed>>}
     */
    public static function chart(Db $db, array $colleagues): array
    {
        $byDepartment = [];
        foreach ($colleagues as $c) {
            $byDepartment[(int) ($c['department_id'] ?? 0)][] = $c;
        }
        $active = array_flip(array_map(fn (array $c): int => (int) $c['id'], $colleagues));
        $departments = [];
        foreach (Departments::tree($db) as $d) {
            $head = $d['head_id'] !== null && isset($active[$d['head_id']]) ? $d['head_id'] : null;
            $members = $byDepartment[$d['id']] ?? [];
            usort($members, fn (array $a, array $b): int => [(int) ($a['id'] !== $head), mb_strtolower((string) $a['name'])] <=> [(int) ($b['id'] !== $head), mb_strtolower((string) $b['name'])]);
            $departments[] = ['id' => $d['id'], 'name' => $d['name'], 'depth' => $d['depth'], 'parent_id' => $d['parent_id'], 'head' => $head, 'members' => $members];
        }

        return ['departments' => $departments, 'none' => $byDepartment[0] ?? []];
    }

    /**
     * The departments of chart() for MCP list_colleagues (3.42): id, name, parent and head (ids), without empty values.
     *
     * @param array{departments: list<array{id: int, name: string, depth: int, parent_id: int|null, head: int|null, members: list<array<string, mixed>>}>, none: list<array<string, mixed>>} $chart
     * @return list<array<string, int|string>>
     */
    public static function compactDepartments(array $chart): array
    {
        return array_map(fn (array $d): array => array_filter(['id' => $d['id'], 'name' => $d['name'], 'parent' => $d['parent_id'], 'head' => $d['head']], fn (mixed $v): bool => $v !== null),
            $chart['departments']);
    }

    /** A work phone as entered (trimmed, inner blanks kept), or null when it is not a phone number. '' = none. */
    public static function phone(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return preg_match(self::PHONE_PATTERN, $value) ? $value : null;
    }

    /** The photo of an account only when it is an image of the site's media (a path, never a URL elsewhere); '' otherwise. */
    public static function photo(string $path): string
    {
        $path = trim($path);

        return preg_match('#^/?(media|image)/[A-Za-z0-9/_.-]{1,200}\.(png|jpe?g|gif|webp|avif|svg)$#iD', $path) && !str_contains($path, '..') ? $path : '';
    }
}
