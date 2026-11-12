<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The one door for what exists only for the automated tests (3.36, T3/T4). Everything here answers "off" unless the test
 * copy's config.php defines KALETA_TEST_SEAMS as true (tools/test.sh and the other suites write it there; a real site never
 * has it), so a stray environment variable or setting on a real site changes nothing.
 */
final class TestSeams
{
    /** Whether this is a test copy (KALETA_TEST_SEAMS === true in config.php). */
    public static function enabled(): bool
    {
        return defined('KALETA_TEST_SEAMS') && constant('KALETA_TEST_SEAMS') === true;
    }

    /**
     * The response headers only the tests read: X-Kaleta-Queries = the number of database statements the request ran until
     * its answer was ready (the query budgets of the smoke test, Q12). Empty on a real site.
     *
     * @return array<string, string>
     */
    public static function headers(Db $db): array
    {
        return self::enabled() ? ['X-Kaleta-Queries' => (string) $db->queryCount] : [];
    }
}
