<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Modules\Enquiries;

/**
 * Job openings (2.11): what the ready-made Job openings collection (system/presets/jobs.php) needs beyond a collection.
 *
 * The jobs themselves close by their "true until" (Core\Validity) and are described for search engines as JobPosting
 * (Builder\CollectionSchema); the site audit lists a job without a closing date (Core\Audit). This class takes care of the
 * applications: they are enquiries sent from a job's item page, so their source (ka_poptavky.zdroj = kolekce:<idk>) names a
 * collection made from the jobs preset. That is more robust than the form name – the administrator may rename the form or
 * translate it, a copy of the form on an ordinary page is not an application, and nothing new is stored on the enquiry.
 *
 * Applications carry CVs and are usually kept only for a limited time after the selection, so they have their own retention
 * (the setting job_applications_months, Enquiries → settings; 0 = like other enquiries). The daily clean-up
 * (Core\Notifications::purgePersonalData) deletes them with their attachments and records the count in the change log and
 * as the event applications.purged. The selection is pure and unit-tested.
 */
final class Jobs
{
    public const string PRESET = 'jobs';

    /**
     * Country => months applications are usually kept after the selection – a hint next to the setting, not legal advice:
     * the decision stays with the owner (the default is 0) and the text says to check with a lawyer.
     */
    public const array RETENTION_PRACTICE = ['CZ' => 6, 'SK' => 6, 'DE' => 6, 'AT' => 6, 'PL' => 6, 'GB' => 6];

    /** Site language => country when Business details has no country. */
    private const array LANGUAGE_COUNTRY = ['cs' => 'CZ', 'sk' => 'SK', 'de' => 'DE', 'pl' => 'PL'];

    /**
     * The country whose usual practice the settings show, and the months: by the company country, otherwise by the site
     * language; null when neither is in the map.
     *
     * @return array{0: string, 1: int}|null [country code, months]
     */
    public static function suggestedRetention(string $country, string $language): ?array
    {
        $country = strtoupper(trim($country));
        if ($country === '') {
            $country = self::LANGUAGE_COUNTRY[strtolower(trim($language))] ?? '';
        }

        return isset(self::RETENTION_PRACTICE[$country]) ? [$country, self::RETENTION_PRACTICE[$country]] : null;
    }

    /** @return list<string> the enquiry sources (kolekce:<idk>) of every collection made from the jobs preset */
    public static function sources(Db $db): array
    {
        return array_map(fn (mixed $idk): string => 'kolekce:' . (int) $idk, array_column($db->all('SELECT idk FROM {kolekce} WHERE preset = ?', [self::PRESET]), 'idk'));
    }

    /**
     * Applications past their retention: enquiries whose source is a jobs collection and that are older than the months.
     * Zero months = no retention of their own (nothing is selected).
     *
     * @param list<array<string, mixed>> $rows enquiries with zdroj and datum (Y-m-d H:i:s)
     * @param list<string> $sources from sources()
     * @param string $now Y-m-d H:i:s
     * @return list<array<string, mixed>>
     */
    public static function expiredApplications(array $rows, array $sources, int $months, string $now): array
    {
        if ($months <= 0 || $sources === []) {
            return [];
        }
        $limit = (new \DateTimeImmutable($now))->modify('-' . $months . ' months')->format('Y-m-d H:i:s');

        return array_values(array_filter($rows, static fn (array $r): bool => in_array((string) ($r['zdroj'] ?? ''), $sources, true) && is_string($r['datum'] ?? null) && $r['datum'] < $limit));
    }

    /** Deletes the applications past their retention, including the CVs; returns how many. */
    public static function purgeApplications(App $app): int
    {
        $months = $app->settings()->int('job_applications_months');
        if ($months <= 0) {
            return 0;
        }
        $db = $app->db();
        $expired = self::expiredApplications($db->all('SELECT idp, zdroj, datum, data FROM {poptavky} WHERE datum < ?', [Clock::monthsAgo($months)]), self::sources($db), $months, date('Y-m-d H:i:s'));
        if ($expired === []) {
            return 0;
        }
        Enquiries::deleteAttachments($expired);
        $db->run('DELETE FROM {poptavky} WHERE idp IN (' . implode(',', array_map(fn (array $r): int => (int) $r['idp'], $expired)) . ')');
        ChangeLog::write($app, 'enquiries', 'purge_applications', sprintf('%d older than %d months', count($expired), $months));
        Events::record($db, 'applications.purged', 'info', t('%d job applications older than %d months were deleted, including the CVs.', count($expired), $months), ['count' => count($expired), 'months' => $months]);

        return count($expired);
    }
}
