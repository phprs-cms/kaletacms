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
    /**
     * The environment variables of the test servers (tools/test.sh) and what each relaxes – read only here (T4, Q10).
     *
     * @var array<string, string>
     */
    public const array SWITCHES = [
        'KALETA_IMPORT_LOCAL' => 'images and item files are downloaded from the test server on 127.0.0.1 (Core\ImageDownloader, Builder\ItemBatch)',
        'KALETA_LINKS_LOCAL' => 'the link check counts a local port nothing listens on as a broken link (Core\Links)',
        'KALETA_FIREWALL_LOCAL' => '127.0.0.1 counts as a visitor\'s address (Core\Firewall)',
        'KALETA_FLEET_LOCAL' => 'the fleet console and its sites talk over plain http, also to local addresses (Fleet\Http)',
        'KALETA_CONNECTORS_FAKE' => 'the base address of the fake outside services every connector calls (Core\Connectors)',
        'KALETA_CAPTCHA_VERIFY' => 'the address of the fake CAPTCHA provider (Core\Captcha)',
    ];

    /** The settings that send outbound calls to a fake server – http://127.0.0.1:<port> only, set only through the database. */
    public const array ADDRESSES = ['backup_test_url', 'newsletter_test_url', 'webhook_test_url'];

    /** Whether this is a test copy (KALETA_TEST_SEAMS === true in config.php). */
    public static function enabled(): bool
    {
        return defined('KALETA_TEST_SEAMS') && constant('KALETA_TEST_SEAMS') === true;
    }

    /** Whether a switch of SWITCHES is set to 1 on a test copy; always false on a real site. */
    public static function on(string $switch): bool
    {
        return self::value($switch) === '1';
    }

    /** The value of a switch of SWITCHES on a test copy; '' on a real site and for a name that is no switch. */
    public static function value(string $switch): string
    {
        if (!self::enabled() || !isset(self::SWITCHES[$switch])) {
            return '';
        }
        $value = getenv($switch);

        return is_string($value) ? $value : '';
    }

    /** A test address of ADDRESSES from the settings on a test copy; '' on a real site and for another key. */
    public static function address(Settings $s, string $key): string
    {
        return self::enabled() && in_array($key, self::ADDRESSES, true) ? $s->get($key) : '';
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
