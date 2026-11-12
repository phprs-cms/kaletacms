<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Colleagues (3.37): the people with an account on the site, as every signed-in user sees them in My account → Colleagues
 * and MCP list_colleagues – the name, the position, the work phone, the e-mail and the photo each of them entered for work
 * (My account; administrators in Users). Nothing else of the account (sign-in name, role, bio, sign-ins) and no blocked
 * account: a blocked person is not a colleague to reach. Never in a heartbeat, the fleet console or a report.
 */
final class Colleagues
{
    /** A work phone: what the company phone takes (digits, spaces, + ( ) / . -). */
    public const string PHONE_PATTERN = '/^[+()\d\s\/.-]{0,30}$/D';

    /**
     * The colleagues, by name.
     *
     * @return list<array{id: int, name: string, position: string, phone: string, email: string, photo: string}>
     */
    public static function all(Db $db): array
    {
        $rows = $db->all('SELECT idu, user, jmeno, pozice, phone, email, foto FROM {uzivatele} WHERE blokovat = 0');
        $out = array_map(fn (array $r): array => [
            'id' => (int) $r['idu'],
            'name' => trim((string) $r['jmeno']) !== '' ? trim((string) $r['jmeno']) : (string) $r['user'],
            'position' => trim((string) $r['pozice']),
            'phone' => trim((string) $r['phone']),
            'email' => filter_var(trim((string) $r['email']), FILTER_VALIDATE_EMAIL) !== false ? trim((string) $r['email']) : '',
            'photo' => self::photo((string) $r['foto']),
        ], $rows);
        usort($out, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return $out;
    }

    /**
     * The colleagues for MCP list_colleagues: without the photo and without empty values (compact output).
     *
     * @param list<array{id: int, name: string, position: string, phone: string, email: string, photo: string}> $colleagues
     * @return list<array<string, int|string>>
     */
    public static function compact(array $colleagues): array
    {
        return array_map(fn (array $c): array => array_filter(['id' => $c['id'], 'name' => $c['name'], 'position' => $c['position'], 'phone' => $c['phone'], 'email' => $c['email']],
            fn (int|string $v): bool => $v !== ''), $colleagues);
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
