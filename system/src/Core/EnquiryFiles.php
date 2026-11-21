<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.50.1: the attachment files of enquiries that go (retention, a deleted enquiry, an erased person, an application's
 * clean-up). It lived in Admin\Modules\Enquiries::deleteAttachments, which forwards here; Core calls it here, so Core never
 * names the admin module for it (docs/design/code-layers.md).
 */
final class EnquiryFiles
{
    /**
     * The files of enquiries that go. 3.34 second review N9: a file an application still lists (attachment_paths – an
     * enquiry restored next to its application twin, a CV not sealed yet) is never removed.
     *
     * @param list<array<string, mixed>> $rows each with data (and idp)
     */
    public static function delete(array $rows, Db $db): void
    {
        $paths = [];
        foreach ($rows as $r) {
            foreach (json_decode((string) $r['data'], true) ?: [] as $item) {
                if (is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', (string) ($item[2] ?? ''))) {
                    $paths[] = (string) $item[2];
                }
            }
        }
        foreach (array_diff($paths, self::listedByApplications($db, $paths)) as $path) {
            @unlink(KALETA_ROOT . '/storage/prilohy/' . $path);
        }
    }

    /**
     * Which of these storage/prilohy paths an application still lists (attachment_paths, old); [] before migration 0119.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function listedByApplications(Db $db, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $listed = [];
        try {
            foreach (array_chunk(array_values(array_unique($paths)), 50) as $chunk) {
                $like = implode(' OR ', array_fill(0, count($chunk), 'attachment_paths LIKE ?'));
                foreach ($db->all('SELECT attachment_paths FROM {applications} WHERE ' . $like, array_map(fn (string $p): string => '%' . addcslashes($p, '%_\\') . '%', $chunk)) as $r) {
                    $old = json_decode((string) $r['attachment_paths'], true);
                    foreach (is_array($old) && is_array($old['old'] ?? null) ? $old['old'] : [] as $path) {
                        $listed[] = (string) $path;
                    }
                }
            }
        } catch (\PDOException $e) {
            // Before migration 0119 (no table or column) nothing is listed. Any other error must not read as
            // "nothing listed": then every path counts as listed, so no file an application may still need is deleted.
            return in_array((string) $e->getCode(), ['42S02', '42S22'], true) ? [] : array_values(array_unique($paths));
        }

        return array_values(array_intersect($paths, $listed));
    }
}
