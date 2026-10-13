<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * The link between a user account and the person's record in a people collection (3.14), so that every employee copies
 * their own e-mail signature in My account (Builder\EmailSignature) without access to Collections.
 *
 * The link is automatic: the record whose e-mail field holds the account's e-mail (case-insensitive, hidden records too,
 * never one in the trash). An administrator may choose the record instead in Users → edit user
 * (ka_uzivatele.team_item_id). A chosen record that is no longer a person – deleted, in the trash, or its collection
 * changed – falls back to the e-mail match, so a link never points at someone else's data by accident.
 *
 * With several people collections the ready-made Team (preset "people") comes first, then the others in the order they
 * were created; within a collection the default language before translations, then the oldest record.
 */
final class TeamRecord
{
    /**
     * People collections (EmailSignature::isPeople), the Team preset first.
     *
     * @return list<array<string, mixed>> collections with decoded "pole"
     */
    public static function collections(Db $db): array
    {
        $people = array_values(array_filter(Collections::all($db), EmailSignature::isPeople(...)));
        usort($people, fn (array $a, array $b): int => [(string) $a['preset'] !== 'people', (int) $a['idk']] <=> [(string) $b['preset'] !== 'people', (int) $b['idk']]);

        return $people;
    }

    /**
     * Every record an administrator can link an account to, by collection: the items not in the trash, hidden ones too.
     *
     * @return list<array{collection: string, items: list<array{id: int, name: string, language: string, email: string, visible: bool}>}>
     */
    public static function choices(Db $db): array
    {
        $out = [];
        foreach (self::collections($db) as $k) {
            $items = [];
            foreach (self::items($db, $k) as $p) {
                $items[] = ['id' => (int) $p['idp'], 'name' => (string) $p['nazev'], 'language' => (string) $p['jazyk'], 'email' => self::emailOf($k, $p), 'visible' => (bool) $p['zobrazit']];
            }
            if ($items !== []) {
                $out[] = ['collection' => (string) $k['nazev'], 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * The person record of a user account, or null when it has none.
     *
     * @param array<string, mixed> $user a row of ka_uzivatele (email, team_item_id)
     * @return array{collection: array<string, mixed>, item: array<string, mixed>, chosen: bool}|null the item with decoded "data";
     *   chosen = an administrator picked it (otherwise it matched by the e-mail)
     */
    public static function forUser(Db $db, array $user): ?array
    {
        $collections = self::collections($db);
        $chosen = (int) ($user['team_item_id'] ?? 0);
        if ($chosen > 0) {
            $p = $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND smazano IS NULL', [$chosen]);
            foreach ($p === null ? [] : $collections as $k) {
                if ((int) $k['idk'] === (int) $p['idk']) {
                    $p['data'] = json_decode((string) $p['data'], true) ?: [];

                    return ['collection' => $k, 'item' => $p, 'chosen' => true];
                }
            }
        }
        $email = self::normalize((string) ($user['email'] ?? ''));
        if ($email === '') {
            return null;
        }
        foreach ($collections as $k) {
            foreach (self::items($db, $k) as $p) {
                if (self::normalize(self::emailOf($k, $p)) === $email) {
                    $p['data'] = json_decode((string) $p['data'], true) ?: [];

                    return ['collection' => $k, 'item' => $p, 'chosen' => false];
                }
            }
        }

        return null;
    }

    /**
     * Which accounts are linked, for the Users list: idu => [record name, chosen]. One pass over the records, not one per user.
     *
     * @param list<array<string, mixed>> $users rows of ka_uzivatele (idu, email, team_item_id)
     * @return array<int, array{name: string, chosen: bool}>
     */
    public static function linked(Db $db, array $users): array
    {
        $byId = [];
        $byEmail = [];
        foreach (self::collections($db) as $k) {
            foreach (self::items($db, $k) as $p) {
                $byId[(int) $p['idp']] = (string) $p['nazev'];
                $email = self::normalize(self::emailOf($k, $p));
                if ($email !== '' && !isset($byEmail[$email])) {
                    $byEmail[$email] = (string) $p['nazev']; // the first one wins, as in forUser()
                }
            }
        }
        $out = [];
        foreach ($users as $u) {
            $chosen = (int) ($u['team_item_id'] ?? 0);
            $email = self::normalize((string) ($u['email'] ?? ''));
            if ($chosen > 0 && isset($byId[$chosen])) {
                $out[(int) $u['idu']] = ['name' => $byId[$chosen], 'chosen' => true];
            } elseif ($email !== '' && isset($byEmail[$email])) {
                $out[(int) $u['idu']] = ['name' => $byEmail[$email], 'chosen' => false];
            }
        }

        return $out;
    }

    /** Is the id a record an administrator may link an account to (a person not in the trash)? */
    public static function isChoice(Db $db, int $idp): bool
    {
        $idk = $idp > 0 ? $db->value('SELECT idk FROM {kolekce_polozky} WHERE idp = ? AND smazano IS NULL', [$idp]) : null;

        return $idk !== null && array_filter(self::collections($db), fn (array $k): bool => (int) $k['idk'] === (int) $idk) !== [];
    }

    /**
     * The e-mail of a record: the collection's e-mail field (EmailSignature::fields), trimmed; '' when it has none.
     *
     * @param array<string, mixed> $collection with decoded "pole"
     * @param array<string, mixed> $item with "data" decoded or as JSON
     */
    public static function emailOf(array $collection, array $item): string
    {
        $key = EmailSignature::fields($collection)['email'];
        $data = is_array($item['data'] ?? null) ? $item['data'] : (json_decode((string) ($item['data'] ?? ''), true) ?: []);

        return $key === null ? '' : trim(strip_tags((string) ($data[$key] ?? '')));
    }

    /** An address compared without case and surrounding blanks; anything that is not an e-mail address compares as ''. */
    public static function normalize(string $email): string
    {
        $email = mb_strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /**
     * Records of one collection that may be a person's: not in the trash, the default language first, then the oldest.
     *
     * @param array<string, mixed> $collection
     * @return list<array<string, mixed>>
     */
    private static function items(Db $db, array $collection): array
    {
        return $db->all("SELECT idp, idk, nazev, data, jazyk, zobrazit FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL ORDER BY jazyk <> '', idp", [$collection['idk']]);
    }
}
