<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Components – reusable blocks of the builder (table ka_komponenty). Inside a component there are {{properties}} – the same
 * tags as in collections (Collections::fill) – and each use on a page (the „komponenta“ element) gives them its own values.
 */
final class Components
{
    /** Property types (a subset of collection fields). */
    public const array TYPES = ['text' => 'short text', 'radky' => 'longer text', 'html' => 'formatted text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz'];

    /** Maximum nesting of components (a component in a component…). */
    public const int MAX_NESTING = 4;

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {komponenty} ORDER BY ' . $db->sortText('nazev')));
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idm): ?array
    {
        $r = $db->one('SELECT * FROM {komponenty} WHERE idm = ?', [$idm]);

        return $r === null ? null : self::extract($r);
    }

    private static function extract(array $r): array
    {
        $r['vlastnosti'] = json_decode((string) $r['vlastnosti'], true) ?: [];

        return $r;
    }

    /**
     * Property definitions from the form or from AI: key, label, type and default value (checked by type).
     *
     * @return list<array{klic: string, popisek: string, typ: string, vychozi: string}>
     */
    public static function sanitizeProperties(mixed $input): array
    {
        // only rows with a label; the default value goes hand in hand with the field, the order must not diverge
        $rows = array_values(array_filter(is_array($input) ? $input : [], fn (mixed $v): bool => is_array($v) && trim(strip_tags((string) ($v['popisek'] ?? ''))) !== ''));
        $rows = array_slice($rows, 0, 30);
        $field = Collections::sanitizeFields(array_map(fn (array $v): array => ['typ' => isset(self::TYPES[$v['typ'] ?? '']) ? $v['typ'] : 'text'] + $v, $rows));
        $defaults = Collections::sanitizeData($field, array_combine(array_column($field, 'klic'), array_map(fn (array $v): string => is_scalar($v['vychozi'] ?? null) ? (string) $v['vychozi'] : '', $rows)));

        return array_map(fn (array $p): array => $p + ['vychozi' => $defaults[$p['klic']] ?? ''], $field);
    }

    /**
     * Values for {{tags}}: those given at the use, otherwise the defaults. They are checked by property type (image, link, HTML).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $component, array $given): array
    {
        $clean = Collections::sanitizeData($component['vlastnosti'], $given);
        $h = [];
        foreach ($component['vlastnosti'] as $v) {
            $h[$v['klic']] = [($clean[$v['klic']] ?? '') !== '' ? $clean[$v['klic']] : (string) $v['vychozi'], $v['typ']];
        }

        return $h;
    }
}
