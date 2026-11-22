<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The addresses Kaleta does not write to unasked (3.51): "bounced" – a mail server refused the address itself as unknown
 * during a newsletter that delivered to others (Core\Mailing) – and "refused" – the person pressed "do not write to me"
 * behind the refuse link of a testimonial request. Newsletters and testimonial requests skip both; answers to a person's
 * own enquiries, bookings and quotes are not affected.
 *
 * The register is part of the restore ledger (ka_restore_ledger, kinds bounced and refused): the keyed fingerprint of the
 * address only, never the address, outside every backup – a restore neither brings an entry back nor removes one, and
 * the restore reapply (RestoreLedger::people, merge) ignores the two kinds. An erasure keeps the fingerprint, so an erased
 * person is not written to again either. Only lift() ends an entry: an administrator for a bounce, a new confirmed
 * sign-up for a refusal (Front\Subscription).
 */
final class SendRegister
{
    /** The kinds of the register. */
    public const array KINDS = ['bounced', 'refused'];

    /** Remembers an address (again: the time moves forward). An unknown kind or an empty address does nothing. */
    public static function record(Db $db, string $kind, string $email): void
    {
        if (in_array($kind, self::KINDS, true)) {
            RestoreLedger::record($db, $kind, $email);
        }
    }

    /** The kind an address is on the register with ('refused' before 'bounced'), or null. */
    public static function blocked(Db $db, string $email): ?string
    {
        $email = PersonalData::cleanAddress($email);
        if ($email === '') {
            return null;
        }
        try {
            $kinds = array_map('strval', array_column($db->all("SELECT kind FROM {restore_ledger} WHERE hash = ? AND kind IN ('refused', 'bounced')", [RestoreLedger::fingerprint($db, $email)]), 'kind'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0133

            return null;
        }
        // a refusal wins over a bounce: the person's own word is the stronger reason
        return in_array('refused', $kinds, true) ? 'refused' : (in_array('bounced', $kinds, true) ? 'bounced' : null);
    }

    /** Takes an address off the register for one kind. */
    public static function lift(Db $db, string $kind, string $email): void
    {
        $email = PersonalData::cleanAddress($email);
        if ($email === '' || !in_array($kind, self::KINDS, true)) {
            return;
        }
        try {
            $db->run('DELETE FROM {restore_ledger} WHERE kind = ? AND hash = ?', [$kind, RestoreLedger::fingerprint($db, $email)]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
        }
    }

    /**
     * How many of these addresses are not on the register (3.51 review) – one read of the register, no query per address.
     *
     * @param list<string> $emails
     */
    public static function reachable(Db $db, array $emails): int
    {
        try {
            $hashes = array_flip(array_map('strval', array_column($db->all("SELECT hash FROM {restore_ledger} WHERE kind IN ('refused', 'bounced')"), 'hash')));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0133

            return count($emails);
        }
        if ($hashes === []) {
            return count($emails);
        }

        return count(array_filter(RestoreLedger::fingerprints($db, $emails), fn (string $hash): bool => !isset($hashes[$hash])));
    }

    /** @return array{bounced: int, refused: int} how many addresses are on the register */
    public static function counts(Db $db): array
    {
        $counts = ['bounced' => 0, 'refused' => 0];
        try {
            foreach ($db->all("SELECT kind, COUNT(*) AS n FROM {restore_ledger} WHERE kind IN ('refused', 'bounced') GROUP BY kind") as $row) {
                $counts[(string) $row['kind']] = (int) $row['n'];
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
        }

        return $counts;
    }
}
